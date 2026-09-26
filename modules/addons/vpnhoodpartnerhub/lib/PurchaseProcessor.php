<?php

namespace WHMCS\Module\Addon\VpnHoodPartnerHub;

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\VpnHoodStore\ApiService;
use WHMCS\Module\Server\VpnHoodStore\Helper;

require_once __DIR__ . '/RefundPolicy.php';

/**
 * Runs a purchase one confirmed step at a time — created → ordered (AddOrder) → paid (credit)
 * → provisioned (AcceptOrder) → delivered (key read) — saving each step's result before the
 * next step starts.
 *
 * A keyed request that finds its purchase midway resumes it, but never on the saved state
 * alone: the request that saved it may have died after the next step succeeded. So each step's
 * footprint in the system that performed it decides (resume()): a step is redone only when its
 * footprint is provably absent, anything ambiguous goes to a person (needs_reconciliation).
 * Provisioning is never redone: its token request can outlive a dying request and still create
 * a token, so a second AcceptOrder could mint a second one.
 *
 * Money rules, for every order: credit is applied in full or not at all, under the client's
 * credit lock; an order is rolled back only while no credit is applied to it, and only a
 * verified rollback releases its key; an order is never deleted once paid.
 */
class PurchaseProcessor
{
    /** How long a repeat waits for the original; below the web server's 30 s timeout. */
    public const KEY_LOCK_SECONDS = 20;
    public const CREDIT_LOCK_SECONDS = 15;
    private const LIVE_STATUSES = ['Active', 'Suspended'];

    /** A service in one of these has ended: its key was terminated (or refunded) and stays that way. */
    public const ENDED_STATUSES = ['Terminated', 'Cancelled', 'Fraud'];

    private PartnerRepository $repo;
    private PurchaseRepository $purchases;
    private array $partner;

    /** Whether this call placed a new WHMCS order; a call that only finished or returned an earlier one is a replay. */
    private bool $placedOrder = false;

    public function __construct(PartnerRepository $repo, PurchaseRepository $purchases, array $partner)
    {
        $this->repo = $repo;
        $this->purchases = $purchases;
        $this->partner = $partner;
    }

    // -- API entry points -------------------------------------------------------

    /**
     * A keyless order: every call buys (the contract of every connector before idempotency-v1),
     * with the money rules above.
     *
     * @throws ApiException
     */
    public function placeUnkeyed(int $productId, string $billingCycle, string $reference): array
    {
        $this->placedOrder = false;
        return $this->complete($this->purchases->create($this->partner, null, $reference, $productId, $billingCycle));
    }

    /**
     * A keyed order: the key buys exactly one purchase, ever. Repeats return it.
     *
     * @return array{replayed: bool, key: array}
     * @throws ApiException
     */
    public function orderKeyed(int $productId, string $billingCycle, string $reference, string $key, bool $confirmNewPurchase): array
    {
        $this->placedOrder = false;
        return $this->withKeyLock($key, function () use ($productId, $billingCycle, $reference, $key, $confirmNewPurchase): array {
            $purchase = $this->purchases->findByKey($this->partnerId(), $key);
            if ($purchase === null) {
                if ($reference !== '' && !$confirmNewPurchase) {
                    $this->guardLegacy($reference);
                }
                $entry = $this->complete($this->purchases->create($this->partner, $key, $reference, $productId, $billingCycle));
            } else {
                $this->assertSameRequest($purchase, $productId, $billingCycle, $reference);
                $entry = $this->continueKeyed($purchase);
            }
            return ['replayed' => !$this->placedOrder, 'key' => $entry];
        });
    }

    /**
     * Bind a keyless order (placed by an older connector, or before keys existed) to a key, so
     * the key's repeats return it. Never places an order.
     *
     * @throws ApiException
     */
    public function link(int $productId, string $billingCycle, string $reference, string $key, int $orderId): array
    {
        $this->placedOrder = false;
        return $this->withKeyLock($key, function () use ($productId, $billingCycle, $reference, $key, $orderId): array {
            $partnerId = $this->partnerId();
            $bound = $this->purchases->findByKey($partnerId, $key);
            if ($bound !== null) {
                if ((int) $bound['order_id'] !== $orderId) {
                    throw new ApiException(
                        "idempotencyKey '{$key}' is already bound to " . $this->describe($bound) . ", not to order #{$orderId}.",
                        409,
                        'key_mismatch',
                        ['upstreamOrderId' => (int) $bound['order_id'] ?: null]
                    );
                }
                // The same link succeeded before and its response was lost: answer it again.
                $this->assertSameRequest($bound, $productId, $billingCycle, $reference);
                return $this->continueKeyed($bound);
            }

            $purchase = $this->purchases->findByOrder($partnerId, $orderId);
            if ($purchase === null) {
                throw new ApiException(
                    "Order #{$orderId} is not an order this partner placed through the Hub.",
                    404,
                    'link_rejected'
                );
            }
            if ($purchase['idempotency_key'] !== null) {
                throw new ApiException("Order #{$orderId} is already linked to another idempotencyKey.", 409, 'already_claimed');
            }
            if ($purchase['state'] !== PurchaseRepository::DELIVERED) {
                throw new ApiException(
                    "Order #{$orderId} never completed (" . str_replace('_', ' ', $purchase['state']) . '). VpnHood support must finish'
                    . " it before it can be linked; quote #{$orderId}.",
                    409,
                    'link_rejected'
                );
            }
            $this->assertLinkMatches($purchase, $productId, $billingCycle, $reference);
            $status = (string) Capsule::table('tblhosting')->where('id', (int) $purchase['service_id'])
                ->where('userid', (int) $this->partner['client_id'])->value('domainstatus');
            if (!in_array($status, self::LIVE_STATUSES, true)) {
                throw new ApiException(
                    "Order #{$orderId} is " . ($status ?: 'deleted') . '; only an Active or Suspended order can be linked.',
                    409,
                    'link_rejected'
                );
            }
            if (!$this->purchases->claim((int) $purchase['id'], $partnerId, $key)) {
                throw new ApiException("Order #{$orderId} was just linked to another idempotencyKey.", 409, 'already_claimed');
            }
            return $this->deliver($this->purchases->find((int) $purchase['id']));
        });
    }

    /**
     * Pay an existing invoice (a renewal) in full from the partner's credit, or not at all, once
     * the lines of ended keys are off it. The caller holds the client's credit lock
     * (PartnerApiController runs every service action under it).
     *
     * @throws ApiException
     */
    public function settleInvoiceLocked(int $invoiceId): void
    {
        $clientId = (int) $this->partner['client_id'];
        $this->dropEndedKeyLines($invoiceId, $clientId);
        // Read under the lock: an order or another renewal may have spent the credit meanwhile.
        $balance = $this->repo->invoiceBalance($invoiceId);
        $credit = $this->repo->getClientCredit($clientId);
        if ($balance > 0) {
            if ($credit + 0.005 < $balance) {
                throw new ApiException(
                    "Insufficient credit to renew. Invoice balance: {$balance}, available: {$credit}.",
                    402,
                    'insufficient_credit'
                );
            }
            $this->applyCredit($invoiceId, $clientId, $balance);
        }
        if (Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status') !== 'Paid') {
            throw new ApiException('Renewal invoice could not be settled from credit.', 402);
        }
    }

    /**
     * WHMCS bills a client's same-day renewals on one invoice, so paying it for one key would also
     * pay for keys the partner has already ended. Their lines come off first. Once anything is paid
     * on the invoice, taking lines off could leave it overpaid, so the renewal is refused instead
     * and the invoice is fixed by hand.
     *
     * @throws ApiException
     */
    private function dropEndedKeyLines(int $invoiceId, int $clientId): void
    {
        $lines = Capsule::table('tblinvoiceitems as i')
            ->join('tblhosting as h', 'h.id', '=', 'i.relid')
            ->where('i.invoiceid', $invoiceId)
            ->whereIn('i.type', RefundPolicy::SERVICE_LINE_TYPES)
            ->whereIn('h.domainstatus', self::ENDED_STATUSES)
            ->get(['i.id', 'h.orderid'])
            ->all();
        if ($lines === []) {
            return;
        }

        $orders = implode(', ', array_unique(array_map(fn ($line) => '#' . $line->orderid, $lines)));
        $total = (float) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('total');
        if ($this->repo->invoiceBalance($invoiceId) + 0.005 < $total) {
            throw new ApiException(
                "Renewal invoice #{$invoiceId} also bills ended order(s) {$orders}, and a payment is already on it, so those"
                . ' lines cannot be taken off here. Nothing was paid. Contact VpnHood support and quote the invoice.',
                409,
                'renewal_blocked',
                ['invoiceId' => $invoiceId]
            );
        }

        LocalApi::call('UpdateInvoice', [
            'invoiceid'     => $invoiceId,
            'deletelineids' => array_map(fn ($line) => (int) $line->id, $lines),
        ]);
        logActivity("Partner Hub: took ended order(s) {$orders} off renewal invoice #{$invoiceId} before paying it from the"
            . " credit of client #{$clientId}.", $clientId);
    }

    /**
     * Refund an order the Hub sold (docs/ARCHITECTURE.md, "Service actions and refunds"): end its
     * key, then return the invoice total to the partner's credit. The caller holds the client's credit lock, so
     * the check for an earlier refund and the credit it guards cannot interleave with another.
     *
     * @throws ApiException
     */
    public function refundLocked(int $orderId, int $serviceId, int $days): array
    {
        $clientId = (int) $this->partner['client_id'];
        $purchase = $this->purchases->findByOrder($this->partnerId(), $orderId);
        $invoiceId = (int) ($purchase['invoice_id'] ?? 0);
        $description = RefundPolicy::creditDescription($orderId, $invoiceId);

        // A repeat (a lost response, a second click) answers from the credit already returned.
        $returned = $invoiceId > 0 ? $this->returnedCredit($clientId, $description) : null;
        if ($returned !== null) {
            return ['upstreamOrderId' => $orderId, 'status' => 'refunded', 'amount' => $returned];
        }

        $facts = $this->refundFacts($orderId, $serviceId, $purchase);
        $refusal = RefundPolicy::refusal($facts, $days, time());
        if ($refusal !== null) {
            throw new ApiException($refusal['message'], $refusal['status'], $refusal['code'], ['upstreamOrderId' => $orderId]);
        }

        // The key ends first, every time: a Terminated status alone does not prove the key is
        // off (it can be set without the module), and a failure here must return nothing.
        try {
            LocalApi::call('ModuleTerminate', ['serviceid' => $serviceId]);
        } catch (ApiException $e) {
            throw new ApiException(
                "Order #{$orderId} was not refunded: ending its key failed ({$e->getMessage()}). Nothing was returned.",
                422,
                'not_refundable',
                ['upstreamOrderId' => $orderId]
            );
        }

        $amount = round($facts['invoice']['total'], 2);
        try {
            LocalApi::call('AddCredit', ['clientid' => $clientId, 'description' => $description, 'amount' => $amount]);
        } catch (ApiException $e) {
            logActivity("Partner Hub: order #{$orderId} was terminated for a refund, but returning {$amount} to the credit of"
                . " client #{$clientId} failed ({$e->getMessage()}). Add the credit by hand with the description \"{$description}\";"
                . ' that row is what marks the refund done.', $clientId);
            throw new ApiException(
                "The key of order #{$orderId} is ended, but returning {$amount} to your credit failed. Contact VpnHood support"
                . " and quote invoice #{$invoiceId}.",
                409,
                'refund_incomplete',
                ['upstreamOrderId' => $orderId]
            );
        }
        logActivity("Partner Hub: order #{$orderId} refunded: its key is ended and {$amount} returned to the credit of client"
            . " #{$clientId} (invoice #{$invoiceId}).", $clientId);
        return ['upstreamOrderId' => $orderId, 'status' => 'refunded', 'amount' => $amount];
    }

    /**
     * The delivery of a provisioned service: its current code (normal delivery) or its batch
     * (CSV delivery). Reading has no side effect, so any caller may repeat it.
     *
     * @throws ApiException
     */
    public function readDelivery(int $serviceId, int $orderId): array
    {
        $service = Capsule::table('tblhosting')->where('id', $serviceId)
            ->where('userid', (int) $this->partner['client_id'])->first();
        if ($service === null) {
            throw new ApiException('Service not found for this partner.', 404);
        }

        $api = new ApiService();
        if ($this->isCsvDelivery($service)) {
            $csv = (string) $api->getAccessCodeCsvFile((string) $service->userid, (string) $orderId);
            if (trim($csv) === '') {
                throw new ApiException('The access server returned an empty batch for this order.', 502);
            }
            return ['deliveryType' => 'csv', 'csv' => $csv];
        }

        // The token id comes from the partner's own service, never from the request, so a
        // partner cannot read another partner's code.
        $accessTokenId = $this->serviceProperty($serviceId, 'accessTokenId');
        if ($accessTokenId === '') {
            throw new ApiException('No access token is available for this order.', 404);
        }
        $json = json_decode($api->getAccessCode($accessTokenId));
        $accessCode = $json->accessToken->accessCode ?? null;
        if ($accessCode === null || $accessCode === '') {
            throw new ApiException('The access server did not return an access code.', 502);
        }
        return [
            'deliveryType'  => 'normal',
            // The connector keeps accessTokenId as the handle that is unambiguous across both
            // installs; accessCode is the value at the time of this read.
            'accessTokenId' => $accessTokenId,
            'accessCode'    => $accessCode,
        ];
    }

    // -- Admin actions (addon page) ---------------------------------------------

    /**
     * "Retry": finish a purchase a person has repaired (section "Purchases needing attention"
     * on the partner page). Never orders, pays or provisions — it only re-checks the footprints
     * and reads the key.
     */
    public function retry(int $purchaseId): string
    {
        return $this->withPurchaseLock($purchaseId, function (array $purchase): string {
            $id = (int) $purchase['id'];
            if ($purchase['state'] === PurchaseRepository::DELIVERED || $purchase['state'] === PurchaseRepository::ROLLED_BACK) {
                return "Purchase #{$id} is " . str_replace('_', ' ', $purchase['state']) . '; there is nothing to finish.';
            }
            if ((int) $purchase['order_id'] === 0) {
                $marked = $this->purchases->markedService($id, (int) $purchase['client_id']);
                if ($marked === null) {
                    return "No order exists for purchase #{$id}. If nothing was charged, release it.";
                }
                $purchase = $this->purchases->setState($id, $purchase['state'], [
                    'order_id'   => (int) $marked['order_id'],
                    'invoice_id' => (int) $marked['invoice_id'] ?: null,
                    'service_id' => (int) $marked['service_id'],
                ]);
            }
            $invoiceId = (int) $purchase['invoice_id'];
            $invoiceStatus = $invoiceId > 0 ? (string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status') : 'Paid';
            if ($invoiceStatus !== 'Paid') {
                return "Invoice #{$invoiceId} is " . ($invoiceStatus ?: 'deleted') . ': purchase #' . $id . ' was never paid.'
                    . ' Cancel the order and release the purchase, or pay the invoice and retry.';
            }
            $footprint = $this->provisioningFootprint((int) $purchase['service_id']);
            if (!$footprint['present']) {
                return "Purchase #{$id} is not finished: {$footprint['summary']}. Follow the procedure below, then retry.";
            }
            $this->acceptWithoutSetup((int) $purchase['order_id']);
            $purchase = $this->purchases->setState($id, PurchaseRepository::PROVISIONED, ['last_error' => null]);
            try {
                $this->deliver($purchase);
            } catch (ApiException $e) {
                return "Purchase #{$id} is provisioned, but reading its key failed: " . $e->getMessage();
            }
            return "Purchase #{$id} is finished: order #{$purchase['order_id']} is delivered. "
                . ($purchase['idempotency_key'] !== null
                    ? "The partner's next request with the same key returns it."
                    : "The partner can fetch its key with getAccessCode for order #{$purchase['order_id']}.");
        });
    }

    /**
     * "Release": close an unfinished purchase as rolled back, which frees its key. Refused
     * while an order or a payment it made still exists, unless the person confirms the refund.
     */
    public function release(int $purchaseId, bool $refundConfirmed): string
    {
        return $this->withPurchaseLock($purchaseId, function (array $purchase) use ($refundConfirmed): string {
            $id = (int) $purchase['id'];
            if ($purchase['state'] === PurchaseRepository::DELIVERED || $purchase['state'] === PurchaseRepository::ROLLED_BACK) {
                return "Purchase #{$id} is " . str_replace('_', ' ', $purchase['state']) . '; only an unfinished purchase can be released.';
            }
            $held = [];
            $orderId = (int) $purchase['order_id'];
            $orderStatus = $orderId > 0 ? (string) Capsule::table('tblorders')->where('id', $orderId)->value('status') : '';
            if ($orderStatus !== '' && !in_array($orderStatus, ['Cancelled', 'Fraud'], true)) {
                $held[] = "order #{$orderId} is {$orderStatus}";
            }
            $invoiceId = (int) $purchase['invoice_id'];
            if ($invoiceId > 0) {
                $invoiceStatus = (string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status');
                $payments = $this->invoicePayments($invoiceId, $invoiceStatus === 'Cancelled');
                if ($invoiceStatus === 'Paid') {
                    $held[] = "invoice #{$invoiceId} is Paid";
                } elseif ($payments > 0.004) {
                    $held[] = "invoice #{$invoiceId} holds {$payments} of payments or applied credit";
                }
            }
            if ($held !== [] && !$refundConfirmed) {
                return "Purchase #{$id} was not released: " . implode('; ', $held)
                    . '. Cancel and refund first, then tick "Refund done" and release again.';
            }
            $this->purchases->setState($id, PurchaseRepository::ROLLED_BACK, [
                'order_id'   => null,
                'invoice_id' => null,
                'service_id' => null,
                'last_error' => mb_substr('Released by an administrator on ' . date('Y-m-d H:i')
                    . ($held !== [] ? ' after confirming the refund (' . implode('; ', $held) . ')' : '')
                    . ($purchase['last_error'] ? '. Before: ' . $purchase['last_error'] : ''), 0, 2000),
            ]);
            return "Purchase #{$id} is released."
                . ($purchase['idempotency_key'] !== null ? ' The next request with its key buys a new order.' : '');
        });
    }

    // -- Steps ------------------------------------------------------------------

    private function continueKeyed(array $purchase): array
    {
        switch ($purchase['state']) {
            case PurchaseRepository::DELIVERED:
                return $this->replay($purchase);
            case PurchaseRepository::ROLLED_BACK:
                return $this->complete($this->purchases->restart((int) $purchase['id']));
            case PurchaseRepository::NEEDS_RECONCILIATION:
                throw $this->needsReconciliation($purchase);
            default:
                return $this->resume($purchase);
        }
    }

    /** Run the remaining steps. Each returns the row in its next state or throws. */
    private function complete(array $purchase): array
    {
        if ($purchase['state'] === PurchaseRepository::CREATED) {
            $purchase = $this->addOrder($purchase);
        }
        if ($purchase['state'] === PurchaseRepository::ORDERED) {
            $purchase = $this->pay($purchase);
        }
        if ($purchase['state'] === PurchaseRepository::PAID) {
            $purchase = $this->provision($purchase);
        }
        return $this->deliver($purchase);
    }

    /**
     * Continue a purchase whose request died midway. The saved state is the last CONFIRMED
     * step; the step after it may or may not have happened, so its footprint decides.
     */
    private function resume(array $purchase): array
    {
        switch ($purchase['state']) {
            case PurchaseRepository::CREATED:
                $purchase = $this->verifyOrder($purchase);
                break;
            case PurchaseRepository::ORDERED:
                $purchase = $this->verifyPayment($purchase);
                break;
            case PurchaseRepository::PAID:
                $purchase = $this->verifyProvisioning($purchase);
                break;
        }
        return $this->complete($purchase);
    }

    private function addOrder(array $purchase): array
    {
        $id = (int) $purchase['id'];
        $params = [
            'clientid'       => (int) $purchase['client_id'],
            'pid'            => (int) $purchase['product_id'],
            'billingcycle'   => (string) $purchase['billing_cycle'],
            'noemail'        => true,
            'noinvoiceemail' => true,
            // The footprint: WHMCS writes the purchase id onto the service it creates, so an
            // order stays findable even if this request dies before saving its ids below.
            'customfields'   => base64_encode(serialize([
                $this->purchases->markerFieldId((int) $purchase['product_id']) => (string) $id,
            ])),
        ];
        $gateway = $this->repo->settings()['orderGateway'];
        if ($gateway !== '') {
            $params['paymentmethod'] = $gateway;
        }

        try {
            $add = LocalApi::call('AddOrder', $params);
        } catch (ApiException $e) {
            // WHMCS refuses before it writes anything; the marker proves nothing was ordered.
            if ($this->purchases->markedService($id, (int) $purchase['client_id']) !== null) {
                throw $this->toReconciliation($purchase, 'AddOrder failed after creating an order: ' . $e->getMessage());
            }
            $this->purchases->advance($id, PurchaseRepository::CREATED, PurchaseRepository::ROLLED_BACK, ['last_error' => $e->getMessage()]);
            throw $e;
        }
        $this->placedOrder = true;

        $orderId = (int) ($add['orderid'] ?? 0);
        $serviceId = (int) explode(',', (string) ($add['productids'] ?? ($add['serviceids'] ?? '')))[0];
        if ($orderId <= 0 || $serviceId <= 0) {
            throw $this->toReconciliation($purchase, 'AddOrder succeeded without an order or service id: ' . json_encode($add));
        }
        return $this->purchases->advance($id, PurchaseRepository::CREATED, PurchaseRepository::ORDERED, [
            'order_id'   => $orderId,
            'invoice_id' => (int) ($add['invoiceid'] ?? 0) ?: null,
            'service_id' => $serviceId,
        ]);
    }

    private function pay(array $purchase): array
    {
        $lock = PurchaseRepository::creditLock((int) $purchase['client_id']);
        if (!$this->purchases->lock($lock, self::CREDIT_LOCK_SECONDS)) {
            // Nothing is applied yet: give the order back rather than leave it unpaid.
            $this->rollBack($purchase, null, 'Another payment from this credit balance is still running; retry shortly', 409, 'in_progress');
        }
        try {
            return $this->payLocked($purchase);
        } finally {
            $this->purchases->unlock($lock);
        }
    }

    private function payLocked(array $purchase): array
    {
        $id = (int) $purchase['id'];
        $clientId = (int) $purchase['client_id'];
        $invoiceId = (int) $purchase['invoice_id'];
        if ($invoiceId === 0) {
            return $this->purchases->advance($id, PurchaseRepository::ORDERED, PurchaseRepository::PAID); // free product
        }

        // Read inside the lock: every Hub payment from this client's credit (orders, renewals)
        // waits here. Credit operations outside the Hub are not serialized — the check after
        // applying catches a collision with one.
        $creditBefore = $this->repo->getClientCredit($clientId);
        $balance = $this->repo->invoiceBalance($invoiceId);
        if ($balance > 0) {
            if ($creditBefore + 0.005 < $balance) {
                $this->rollBack(
                    $purchase,
                    $creditBefore,
                    'Insufficient credit to cover the order. Available balance: ' . number_format($creditBefore, 2, '.', '') . '.',
                    402,
                    'insufficient_credit'
                );
            }
            try {
                $this->applyCredit($invoiceId, $clientId, $balance);
            } catch (\Throwable $e) {
                throw $this->toReconciliation($purchase, "Applying credit to invoice #{$invoiceId} failed midway: " . $e->getMessage());
            }
        }

        $status = (string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status');
        $creditAfter = $this->repo->getClientCredit($clientId);
        if ($status !== 'Paid' || $creditAfter < -0.004) {
            throw $this->toReconciliation($purchase, "Credit was applied to invoice #{$invoiceId}, but it is {$status} and the balance is {$creditAfter}");
        }
        return $this->purchases->advance($id, PurchaseRepository::ORDERED, PurchaseRepository::PAID);
    }

    /**
     * Give back an order no credit was applied to. Only a verified rollback releases the key;
     * anything it leaves behind goes to a person. Always throws.
     *
     * @throws ApiException
     */
    private function rollBack(array $purchase, ?float $creditBefore, string $message, int $status, string $code): void
    {
        $orderId = (int) $purchase['order_id'];
        $invoiceId = (int) $purchase['invoice_id'];
        // DeleteOrder refuses an order that is not Cancelled or Fraud, hence cancel first.
        $cancel = LocalApi::tryCall('CancelOrder', ['orderid' => $orderId, 'cancelsub' => false]);
        $delete = LocalApi::tryCall('DeleteOrder', ['orderid' => $orderId]);
        $this->repo->log(
            (int) $this->partner['id'],
            'rollback',
            null,
            0,
            ['orderid' => $orderId, 'purchase' => (int) $purchase['id']],
            ['cancel' => $cancel, 'delete' => $delete]
        );

        // WHMCS leaves the invoice Cancelled; any credit applied must be back on the balance.
        $problems = [];
        $orderStatus = (string) Capsule::table('tblorders')->where('id', $orderId)->value('status');
        if (!in_array($orderStatus, ['', 'Cancelled'], true)) {
            $problems[] = "order #{$orderId} is {$orderStatus}";
        }
        if ($invoiceId > 0) {
            $invoiceStatus = (string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status');
            if (!in_array($invoiceStatus, ['', 'Cancelled'], true)) {
                $problems[] = "invoice #{$invoiceId} is {$invoiceStatus}";
            }
            if ($this->invoicePayments($invoiceId, true) > 0.004) {
                $problems[] = "invoice #{$invoiceId} holds payments";
            }
        }
        if ($creditBefore !== null && abs($this->repo->getClientCredit((int) $purchase['client_id']) - $creditBefore) > 0.004) {
            $problems[] = 'the credit balance changed';
        }
        if ($problems !== []) {
            throw $this->toReconciliation($purchase, "{$message}; rolling back order #{$orderId} left " . implode(', ', $problems));
        }

        $this->purchases->advance((int) $purchase['id'], PurchaseRepository::ORDERED, PurchaseRepository::ROLLED_BACK, [
            'order_id'   => null,
            'invoice_id' => null,
            'service_id' => null,
            'last_error' => "{$message} (order #{$orderId}, invoice #{$invoiceId} rolled back)",
        ]);
        throw new ApiException($message, $status, $code);
    }

    private function provision(array $purchase): array
    {
        // Provisioned already: by WHMCS while the invoice was paid (a product set to provision
        // on payment), or by hand meanwhile. Never add a second token to it.
        $serviceId = (int) $purchase['service_id'];
        if ($this->provisioningFootprint($serviceId)['present']) {
            $this->acceptWithoutSetup((int) $purchase['order_id']);
            return $this->purchases->advance((int) $purchase['id'], PurchaseRepository::PAID, PurchaseRepository::PROVISIONED);
        }

        // A product that provisions on payment (or on order) had its one attempt already, when
        // WHMCS ran the module as the invoice was paid. Running it again through AcceptOrder
        // would be exactly the automatic second attempt this class never makes.
        $setup = (string) Capsule::table('tblproducts')->where('id', (int) $purchase['product_id'])->value('autosetup');
        if ($setup === 'payment' || $setup === 'order') {
            $reason = $this->moduleQueueError($serviceId);
            throw $this->toReconciliation(
                $purchase,
                'Paid, not provisioned: WHMCS ran the module when the invoice was paid'
                . ($reason !== '' ? " and it failed ({$reason})" : '') . '; '
                . $this->provisioningFootprint($serviceId)['summary'],
                'not_provisioned'
            );
        }

        $acceptError = '';
        try {
            // Runs vpnhoodstore_CreateAccount. Whatever it answers, the service decides below.
            LocalApi::call('AcceptOrder', [
                'orderid'   => (int) $purchase['order_id'],
                'autosetup' => true,
                'sendemail' => false,
            ]);
        } catch (\Throwable $e) {
            $acceptError = $e->getMessage();
        }

        $footprint = $this->provisioningFootprint((int) $purchase['service_id']);
        if ($footprint['present']) {
            return $this->purchases->advance((int) $purchase['id'], PurchaseRepository::PAID, PurchaseRepository::PROVISIONED);
        }
        // Never repeated automatically (see the class comment): a person finds out what the
        // token request did before anything creates a token again.
        throw $this->toReconciliation(
            $purchase,
            'Paid, not provisioned: ' . ($acceptError !== '' ? $acceptError . '; ' : '') . $footprint['summary'],
            'not_provisioned'
        );
    }

    private function deliver(array $purchase): array
    {
        $orderId = (int) $purchase['order_id'];
        try {
            $delivery = $this->readDelivery((int) $purchase['service_id'], $orderId);
        } catch (\Throwable $e) {
            if ($purchase['state'] === PurchaseRepository::PROVISIONED) {
                $this->purchases->noteError((int) $purchase['id'], 'Reading the key failed: ' . $e->getMessage());
            }
            $how = $purchase['idempotency_key'] !== null
                ? 'repeat the request with the same idempotencyKey, or fetch it with getAccessCode'
                : 'fetch it with getAccessCode';
            throw new ApiException(
                "Order #{$orderId} is provisioned, but its key could not be read ({$e->getMessage()}). Do not order again: {$how}.",
                409,
                'not_delivered',
                ['upstreamOrderId' => $orderId]
            );
        }
        if ($purchase['state'] === PurchaseRepository::PROVISIONED) {
            $purchase = $this->purchases->advance((int) $purchase['id'], PurchaseRepository::PROVISIONED, PurchaseRepository::DELIVERED, ['last_error' => null]);
        }
        return array_merge([
            'upstreamOrderId'   => $orderId,
            'customerReference' => (string) $purchase['customer_reference'],
        ], $delivery);
    }

    private function replay(array $purchase): array
    {
        $status = (string) Capsule::table('tblhosting')->where('id', (int) $purchase['service_id'])
            ->where('userid', (int) $purchase['client_id'])->value('domainstatus');
        if (!in_array($status, self::LIVE_STATUSES, true)) {
            throw new ApiException(
                "idempotencyKey '{$purchase['idempotency_key']}' belongs to order #{$purchase['order_id']}, which is "
                . ($status ?: 'deleted') . '. A replacement purchase needs a new key.',
                409,
                'key_spent',
                ['upstreamOrderId' => (int) $purchase['order_id'], 'status' => $status ?: 'Deleted']
            );
        }
        return $this->deliver($purchase);
    }

    // -- Footprints (resume) -------------------------------------------------------

    /** From `created`: did AddOrder run? The marker on its service says so. */
    private function verifyOrder(array $purchase): array
    {
        $id = (int) $purchase['id'];
        $clientId = (int) $purchase['client_id'];
        $marked = $this->purchases->markedService($id, $clientId);
        if ($marked !== null) {
            return $this->purchases->advance($id, PurchaseRepository::CREATED, PurchaseRepository::ORDERED, [
                'order_id'   => (int) $marked['order_id'],
                'invoice_id' => (int) $marked['invoice_id'] ?: null,
                'service_id' => (int) $marked['service_id'],
            ]);
        }
        // AddOrder dying between the order and its custom fields would leave an order that no
        // purchase accounts for. Any such order since this purchase began makes "not ordered" unprovable.
        $since = date('Y-m-d H:i:s', strtotime((string) $purchase['created_at']) - 60);
        $unexplained = $this->purchases->unexplainedOrderIds($clientId, $since);
        if ($unexplained !== []) {
            throw $this->toReconciliation(
                $purchase,
                'An earlier attempt may have ordered: order #' . implode(', #', $unexplained) . ' has no purchase record'
            );
        }
        return $purchase; // provably not ordered: order now
    }

    /** From `ordered`: was it paid? The invoice and its payments say so. AcceptOrder never ran. */
    private function verifyPayment(array $purchase): array
    {
        $orderId = (int) $purchase['order_id'];
        $orderStatus = (string) Capsule::table('tblorders')->where('id', $orderId)->value('status');
        if (!in_array($orderStatus, ['Pending', 'Active'], true)) {
            throw $this->toReconciliation($purchase, "Order #{$orderId} is " . ($orderStatus ?: 'deleted') . ' but the Hub never finished paying it');
        }
        $invoiceId = (int) $purchase['invoice_id'];
        if ($invoiceId === 0) {
            return $purchase;
        }
        $status = (string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status');
        if ($status === 'Paid') {
            return $this->purchases->advance((int) $purchase['id'], PurchaseRepository::ORDERED, PurchaseRepository::PAID);
        }
        $payments = $this->invoicePayments($invoiceId);
        if ($status === 'Unpaid' && $payments < 0.005) {
            return $purchase; // provably unpaid: pay now
        }
        throw $this->toReconciliation($purchase, "Invoice #{$invoiceId} is " . ($status ?: 'deleted') . " with {$payments} applied: a payment was interrupted");
    }

    /** From `paid`: AcceptOrder may have run. Only a recorded token (or batch) on a live service proves it did. */
    private function verifyProvisioning(array $purchase): array
    {
        $footprint = $this->provisioningFootprint((int) $purchase['service_id']);
        if ($footprint['present']) {
            return $this->purchases->advance((int) $purchase['id'], PurchaseRepository::PAID, PurchaseRepository::PROVISIONED);
        }
        throw $this->toReconciliation($purchase, 'Paid, but ' . $footprint['summary'] . '; an interrupted provisioning is never repeated automatically');
    }

    /**
     * Whether a service carries the record of its provisioning: a token id (normal delivery)
     * or the batch mark (CSV delivery — a product configured for CSV delivers a batch even at
     * quantity 1, and stores no single token), on an Active or Suspended service.
     *
     * @return array{present: bool, summary: string}
     */
    private function provisioningFootprint(int $serviceId): array
    {
        $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        if ($service === null) {
            return ['present' => false, 'summary' => "service #{$serviceId} no longer exists"];
        }
        $csv = $this->isCsvDelivery($service);
        $recorded = $csv
            ? $this->serviceProperty($serviceId, 'bulkDelivery') === 'yes'
            : $this->serviceProperty($serviceId, 'accessTokenId') !== '';
        $what = $csv ? 'batch' : 'token';
        return [
            'present' => $recorded && in_array($service->domainstatus, self::LIVE_STATUSES, true),
            'summary' => "service #{$serviceId} is {$service->domainstatus} with " . ($recorded ? "its {$what}" : "no {$what}") . ' recorded',
        ];
    }

    // -- Helpers -------------------------------------------------------------------

    private function guardLegacy(string $reference): void
    {
        $candidates = $this->purchases->legacyCandidates($this->partnerId(), (int) $this->partner['client_id'], $reference);
        if ($candidates === []) {
            return;
        }
        $ids = implode(', ', array_map(fn ($c) => '#' . $c['upstreamOrderId'], $candidates));
        throw new ApiException(
            "customerReference '{$reference}' already has order {$ids}, placed without an idempotencyKey — possibly this"
            . ' very purchase, if an earlier response was lost. Link the right order with linkOrder, or repeat this order'
            . ' with confirmNewPurchase: true to buy a new key anyway.',
            409,
            'reconcile',
            ['candidates' => $candidates]
        );
    }

    private function assertSameRequest(array $purchase, int $productId, string $billingCycle, string $reference): void
    {
        $differences = $this->differences($purchase, $productId, $billingCycle, $reference);
        if ($differences !== []) {
            throw new ApiException(
                "idempotencyKey '{$purchase['idempotency_key']}' already belongs to " . $this->describe($purchase)
                . ' with a different ' . implode(', ', $differences) . '. Use a new key for a new purchase.',
                409,
                'key_mismatch',
                ['upstreamOrderId' => (int) $purchase['order_id'] ?: null]
            );
        }
    }

    private function assertLinkMatches(array $purchase, int $productId, string $billingCycle, string $reference): void
    {
        $differences = $this->differences($purchase, $productId, $billingCycle, $reference);
        if ($differences !== []) {
            throw new ApiException(
                "Order #{$purchase['order_id']} was placed with a different " . implode(', ', $differences)
                . ' than this request, so it is not this purchase.',
                409,
                'link_rejected'
            );
        }
    }

    private function differences(array $purchase, int $productId, string $billingCycle, string $reference): array
    {
        $differences = [];
        if ((int) $purchase['product_id'] !== $productId) {
            $differences[] = 'product';
        }
        if ((string) $purchase['billing_cycle'] !== $billingCycle) {
            $differences[] = "billing cycle ({$purchase['billing_cycle']})";
        }
        if ((string) $purchase['customer_reference'] !== $reference) {
            $differences[] = "customerReference ('{$purchase['customer_reference']}')";
        }
        return $differences;
    }

    private function describe(array $purchase): string
    {
        return (int) $purchase['order_id'] > 0 ? "order #{$purchase['order_id']}" : "purchase #{$purchase['id']}";
    }

    /** Record why a purchase needs a person, and the error the caller gets for it. */
    private function toReconciliation(array $purchase, string $reason, string $code = 'needs_reconciliation'): ApiException
    {
        $purchase = $this->purchases->setState((int) $purchase['id'], PurchaseRepository::NEEDS_RECONCILIATION, [
            'last_error' => mb_substr($reason, 0, 2000),
        ]);
        return $this->needsReconciliation($purchase, $code);
    }

    private function needsReconciliation(array $purchase, string $code = 'needs_reconciliation'): ApiException
    {
        $orderId = (int) $purchase['order_id'];
        $subject = $orderId > 0 ? "Order #{$orderId}" : 'This purchase';
        $stop = $purchase['idempotency_key'] !== null
            ? 'Do not order again: repeats with this idempotencyKey are refused until VpnHood support resolves it'
            : 'Do not order again: a new order is charged again. VpnHood support will resolve it';
        return new ApiException(
            "{$subject} needs VpnHood support before it can finish — " . ($purchase['last_error'] ?: 'its outcome is uncertain')
            . ". {$stop}" . ($orderId > 0 ? "; quote #{$orderId}." : '.'),
            409,
            $code,
            ['upstreamOrderId' => $orderId ?: null]
        );
    }

    private function withKeyLock(string $key, callable $work): array
    {
        $lock = PurchaseRepository::keyLock($this->partnerId(), $key);
        if (!$this->purchases->lock($lock, self::KEY_LOCK_SECONDS)) {
            throw new ApiException(
                "A request with idempotencyKey '{$key}' is still being processed. Retry shortly: the retry returns"
                . ' its result without charging again.',
                409,
                'in_progress'
            );
        }
        try {
            return $work();
        } finally {
            $this->purchases->unlock($lock);
        }
    }

    /** Admin actions take the lock an API request on the same purchase would take. */
    private function withPurchaseLock(int $purchaseId, callable $work): string
    {
        $purchase = $this->purchases->find($purchaseId);
        if ($purchase === null || (int) $purchase['partner_id'] !== $this->partnerId()) {
            return "Purchase #{$purchaseId} does not belong to this partner.";
        }
        $lock = $purchase['idempotency_key'] !== null
            ? PurchaseRepository::keyLock($this->partnerId(), (string) $purchase['idempotency_key'])
            : "purchase\0{$purchaseId}";
        if (!$this->purchases->lock($lock, 5)) {
            return "Purchase #{$purchaseId} is being processed by a request right now; try again in a moment.";
        }
        try {
            return $work($this->purchases->find($purchaseId));
        } catch (ApiException $e) {
            return $e->getMessage();
        } finally {
            $this->purchases->unlock($lock);
        }
    }

    /** Why WHMCS's own run of the module failed, as its Module Queue recorded it ('' if it did not). */
    private function moduleQueueError(int $serviceId): string
    {
        return mb_substr((string) Capsule::table('tblmodulequeue')
            ->where('service_type', 'service')->where('service_id', $serviceId)
            ->where('module_action', 'CreateAccount')
            ->orderBy('id', 'desc')->value('last_attempt_error'), 0, 500);
    }

    /**
     * Accept a Pending order WITHOUT running the module: its service was provisioned by hand,
     * and a Pending order invites an admin to accept it with setup — a second token.
     */
    private function acceptWithoutSetup(int $orderId): void
    {
        if ((string) Capsule::table('tblorders')->where('id', $orderId)->value('status') === 'Pending') {
            LocalApi::tryCall('AcceptOrder', ['orderid' => $orderId, 'autosetup' => false, 'sendemail' => false]);
        }
    }

    private function applyCredit(int $invoiceId, int $clientId, float $amount): void
    {
        require_once ROOTDIR . '/includes/invoicefunctions.php';
        if (!function_exists('applyCredit')) {
            throw new \RuntimeException('WHMCS credit application is unavailable on this install.');
        }
        // (invoiceId, userId, amount, noEmail): applies exactly this amount. WHMCS 9 records it
        // as a payment through a credit note, so invoiceBalance() sees it.
        applyCredit($invoiceId, $clientId, $amount, true);
    }

    /**
     * Net amounts recorded on an invoice. WHMCS 9 books applied credit as a credit note
     * (billingnoteid set) — and also closes a CANCELLED invoice with a credit note of its own,
     * which moves no money. So on a cancelled invoice only $gatewayOnly amounts mean money,
     * and applied credit shows in the client's balance instead.
     */
    private function invoicePayments(int $invoiceId, bool $gatewayOnly = false): float
    {
        $query = Capsule::table('tblaccounts')->where('invoiceid', $invoiceId);
        if ($gatewayOnly) {
            $query->where('billingnoteid', 0);
        }
        $row = $query->selectRaw('COALESCE(SUM(amountin), 0) - COALESCE(SUM(amountout), 0) AS net')->first();
        return round((float) ($row->net ?? 0), 2);
    }

    /** The amount an earlier refund returned (its credit row), or null when there was none. */
    private function returnedCredit(int $clientId, string $description): ?float
    {
        $amount = Capsule::table('tblcredit')->where('clientid', $clientId)->where('description', $description)->value('amount');
        return $amount === null ? null : round((float) $amount, 2);
    }

    /** What RefundPolicy decides on, read from the purchase record, its invoice and the service's other invoices. */
    private function refundFacts(int $orderId, int $serviceId, ?array $purchase): array
    {
        $invoiceId = (int) ($purchase['invoice_id'] ?? 0);
        $invoice = $invoiceId > 0
            ? Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['userid', 'status', 'total', 'datepaid'])
            : null;
        return [
            'orderId'         => $orderId,
            'serviceId'       => $serviceId,
            'partnerClientId' => (int) $this->partner['client_id'],
            'purchase'        => $purchase === null ? null : [
                'state'     => (string) $purchase['state'],
                'clientId'  => (int) $purchase['client_id'],
                'serviceId' => (int) $purchase['service_id'],
                'invoiceId' => $invoiceId,
            ],
            'invoice'         => $invoice === null ? null : [
                'userId'   => (int) $invoice->userid,
                'status'   => (string) $invoice->status,
                'total'    => (float) $invoice->total,
                'datePaid' => (string) $invoice->datepaid,
            ],
            'items'           => $invoiceId > 0
                ? array_map(fn ($i) => ['type' => (string) $i->type, 'relid' => (int) $i->relid],
                    Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->get(['type', 'relid'])->all())
                : [],
            // A refund WHMCS's own Refund action booked: the rows the vpnhoodstore refund hook counts.
            'refundBooked'    => $invoiceId > 0 && Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)
                ->where('amountout', '>', 0)
                ->where(fn ($q) => $q->where('refundid', '>', 0)->orWhere('type', 'gateway_funds_out'))
                ->exists(),
            // Anything else invoiced for the service (a renewal, paid or not), unless cancelled.
            'laterInvoiceIds' => Capsule::table('tblinvoiceitems as i')
                ->join('tblinvoices as inv', 'inv.id', '=', 'i.invoiceid')
                ->where('i.relid', $serviceId)
                ->whereIn('i.type', ['Hosting', 'PromoHosting'])
                ->where('inv.id', '!=', $invoiceId)
                ->where('inv.status', '!=', 'Cancelled')
                ->orderBy('inv.id')
                ->distinct()
                ->pluck('inv.id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        ];
    }

    /** CSV (batch) delivery, by the same rule vpnhoodstore provisions and guards with. */
    private function isCsvDelivery(object $service): bool
    {
        if ($this->serviceProperty((int) $service->id, 'bulkDelivery') === 'yes') {
            return true;
        }
        // configoption4 is vpnhoodstore's "Token Delivery Method" — keep in step with its _ConfigOptions().
        $product = Capsule::table('tblproducts')->where('id', (int) $service->packageid)->first(['allowqty', 'configoption4']);
        return Helper::isCsvTokenDelivery(
            (int) ($product->configoption4 ?? 0),
            (int) ($service->qty ?? 1),
            (int) ($product->allowqty ?? 0)
        );
    }

    /** A service property (WHMCS stores them as product custom fields), read fresh from the database. */
    private function serviceProperty(int $serviceId, string $name): string
    {
        return (string) Capsule::table('tblcustomfieldsvalues as v')
            ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
            ->where('v.relid', $serviceId)
            ->where('f.type', 'product')
            ->whereRaw("LOWER(SUBSTRING_INDEX(f.fieldname, '|', 1)) = ?", [strtolower($name)])
            ->value('v.value');
    }

    private function partnerId(): int
    {
        return (int) $this->partner['id'];
    }
}
