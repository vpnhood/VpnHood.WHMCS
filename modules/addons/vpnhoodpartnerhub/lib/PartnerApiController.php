<?php

namespace WHMCS\Module\Addon\VpnHoodPartnerHub;

use WHMCS\Database\Capsule;

// Reuse the existing access-server client + helpers from the vpnhoodstore server module.
require_once __DIR__ . '/../../../servers/vpnhoodstore/lib/ApiService.php';
require_once __DIR__ . '/../../../servers/vpnhoodstore/lib/Helper.php';
require_once __DIR__ . '/LocalApi.php';
require_once __DIR__ . '/PurchaseRepository.php';
require_once __DIR__ . '/PurchaseProcessor.php';

use WHMCS\Module\Server\VpnHoodStore\Helper;

/**
 * Implements the partner-facing API actions.
 *
 * Provisioning is delegated to WHMCS localAPI (AddOrder/AcceptOrder), which runs
 * the existing vpnhoodstore server module against the VpnHood access server.
 * Payment uses the partner client's NATIVE WHMCS credit balance, applied EXPLICITLY
 * (PurchaseProcessor). We never provision an unpaid order: an order whose invoice cannot
 * be paid in full is rolled back and a 402 is returned.
 *
 * IMPORTANT (admin requirement): WHMCS "Automatic Credit Use" must be OFF, and the
 * partner's WHMCS client must carry enough credit. Auto-apply is deliberately
 * disabled so that RENEWAL invoices for Hub products are never paid on their own —
 * that is what makes recurring Hub products manual-renewal (see renew()). Turning
 * that setting back on silently restores auto-renewal for partner services.
 */
class PartnerApiController
{
    /** Upper bound on units per order, to prevent resource-exhaustion via a huge quantity. */
    private const MAX_ORDER_QUANTITY = 100;

    /** Advertised in the X-Vpnhood-Hub-Features header of every response (api.php). */
    public const FEATURES = 'idempotency-v1';

    private PartnerRepository $repo;
    private array $partner;

    public function __construct(PartnerRepository $repo, array $partner)
    {
        $this->repo = $repo;
        $this->partner = $partner;
    }

    /**
     * Dispatch an authenticated action to its handler.
     *
     * @throws ApiException
     */
    public function handle(string $action, array $body): array
    {
        switch ($action) {
            case 'getBalance':      return $this->getBalance();
            case 'getProducts':     return $this->getProducts();
            case 'order':           return $this->order($body);
            case 'linkOrder':       return $this->linkOrder($body);
            case 'renew':           return $this->renew($body);
            case 'suspend':         return $this->suspend($body);
            case 'unsuspend':       return $this->unsuspend($body);
            case 'terminate':       return $this->terminate($body);
            case 'cancel':          return $this->terminate($body); // alias
            case 'refund':          return $this->refund($body);
            case 'getOrder':        return $this->getOrder($body);
            case 'getAccessCode':   return $this->getAccessCode($body);
            case 'getTransactions': return $this->getTransactions();
            default:
                throw new ApiException("Unknown action: {$action}", 404);
        }
    }

    // -- Read endpoints -----------------------------------------------------

    private function getBalance(): array
    {
        $settings = $this->repo->settings();
        return [
            'clientId' => (int) $this->partner['client_id'],
            'balance'  => $this->repo->getClientCredit((int) $this->partner['client_id']),
            'currency' => $settings['currency'],
        ];
    }

    private function getProducts(): array
    {
        $mappings = $this->repo->getProductMappings((int) $this->partner['id']);
        $products = [];
        foreach ($mappings as $m) {
            if (!$m['enabled']) {
                continue;
            }
            $products[] = [
                'downstreamRef'      => $m['downstream_ref'],
                'name'               => $m['product_name'],
                // WHMCS "Payment Type" (free|onetime|recurring). The connector compares it
                // against the partner-side product so a mismatched type is caught at config
                // time; billing cycles only apply when this is 'recurring'.
                'paymentType'        => $this->normalizePaymentType($m['payment_type'] ?? ''),
                // Whether this product may be ordered with quantity > 1 in a single call
                // ("Allow Multiple Quantities" on the Pricing tab). The connector compares
                // it against the partner-side product so a mismatch is caught at config time.
                'allowMultipleQuantities' => (bool) ($m['allow_qty'] ?? false),
                'billingCycleMonths' => (int) $m['billing_cycle_months'],
                // Every recurring cycle the upstream product offers, so the connector
                // can list them and reject a customer cycle the product doesn't support.
                'availableCycles'    => $this->repo->productAvailableCycleMonths((int) $m['whmcs_product_id']),
            ];
        }
        return ['products' => $products];
    }

    private function getTransactions(): array
    {
        // Native WHMCS credit history for the partner's client.
        $rows = Capsule::table('tblcredit')
            ->where('clientid', $this->partner['client_id'])
            ->orderBy('id', 'desc')
            ->limit(100)
            ->get();

        $tx = [];
        foreach ($rows as $r) {
            $tx[] = [
                'date'        => $r->date,
                'description' => $r->description,
                'amount'      => (float) $r->amount,
            ];
        }
        return ['transactions' => $tx];
    }

    private function getOrder(array $body): array
    {
        $orderId = $this->requestedOrderId($body);
        $service = $this->ownedServiceByOrder($orderId);

        return [
            'upstreamOrderId' => $orderId,
            'status'          => $service->domainstatus,
            'nextDueDate'     => $service->nextduedate,
        ];
    }

    /**
     * Return an order's delivery, read live: the CURRENT access code (normal delivery) or the
     * batch (CSV delivery). Also how a caller recovers a key whose `order` response was lost
     * after provisioning (`409 not_delivered`).
     *
     * The accessTokenId is resolved from the partner's own service — never taken from the
     * request, so a partner cannot read another partner's token.
     */
    private function getAccessCode(array $body): array
    {
        $orderId = $this->requestedOrderId($body);
        $service = $this->ownedServiceByOrder($orderId);

        return array_merge(
            ['upstreamOrderId' => $orderId],
            $this->processor(false)->readDelivery((int) $service->id, $orderId)
        );
    }

    // -- Order (create + deliver) ------------------------------------------

    /**
     * Place an order. With an `idempotencyKey` the call is safe to repeat: the key buys one
     * unit, once, and every repeat returns that unit (PurchaseProcessor::orderKeyed). Without
     * one, every call buys, exactly as before keys existed.
     */
    private function order(array $body): array
    {
        $downstreamRef = (string) ($body['downstreamRef'] ?? '');
        $quantity = (int) ($body['quantity'] ?? 1);
        if ($quantity < 1 || $quantity > self::MAX_ORDER_QUANTITY) {
            throw new ApiException('quantity must be between 1 and ' . self::MAX_ORDER_QUANTITY . '.', 422);
        }
        $key = $this->requestedKey($body, false);
        if ($key !== '' && $quantity !== 1) {
            throw new ApiException(
                'An order with an idempotencyKey buys exactly one unit: send quantity 1, with one key per unit.',
                422
            );
        }
        $customerReference = (string) ($body['customerReference'] ?? '');
        if ($key !== '') {
            $this->assertReferenceLength($customerReference);
        }

        $mapping = $this->mappedProduct($downstreamRef);

        // Purchase-time enforcement of "Allow Multiple Quantities": bulk orders are only
        // accepted when the upstream product explicitly allows them on its Pricing tab.
        if ($quantity > 1 && !$this->repo->productAllowsMultipleQuantities((int) $mapping['whmcs_product_id'])) {
            throw new ApiException(
                "Product '{$downstreamRef}' does not allow multiple quantities; order quantity must be 1.",
                422
            );
        }

        $billingCycle = $this->resolveBillingCycle($mapping, (string) ($body['billingCycle'] ?? ''));
        $productId = (int) $mapping['whmcs_product_id'];
        $processor = $this->processor(true);

        if ($key !== '') {
            $result = $processor->orderKeyed(
                $productId,
                $billingCycle,
                $customerReference,
                $key,
                ($body['confirmNewPurchase'] ?? false) === true
            );
            return [
                'downstreamRef' => $downstreamRef,
                'quantity'      => 1,
                'replayed'      => $result['replayed'],
                'keys'          => [$result['key']],
            ];
        }

        // One key per unit. Each unit is its own WHMCS order/service, paid from credit. A
        // reference too long to record is still echoed back, as it always was.
        $recordable = mb_strlen($customerReference) <= PurchaseRepository::MAX_REFERENCE_LENGTH ? $customerReference : '';
        $keys = [];
        for ($i = 0; $i < $quantity; $i++) {
            try {
                $keys[] = array_merge(
                    $processor->placeUnkeyed($productId, $billingCycle, $recordable),
                    ['customerReference' => $customerReference]
                );
            } catch (ApiException $e) {
                throw $keys === [] ? $e : $this->withDeliveredUnits($e, $keys);
            }
        }

        return [
            'downstreamRef' => $downstreamRef,
            'quantity'      => $quantity,
            'replayed'      => false,
            'keys'          => $keys,
        ];
    }

    /**
     * Bind an order placed without a key (by an older connector, or before keys existed) to a
     * key, after its response was lost. Never places an order: a Hub without this action
     * answers 404, so a connector can never buy by mistake while trying to link.
     */
    private function linkOrder(array $body): array
    {
        $downstreamRef = (string) ($body['downstreamRef'] ?? '');
        $key = $this->requestedKey($body, true);
        $orderId = $this->requestedOrderId($body);
        $customerReference = (string) ($body['customerReference'] ?? '');
        $this->assertReferenceLength($customerReference);
        $mapping = $this->mappedProduct($downstreamRef);
        $billingCycle = $this->resolveBillingCycle($mapping, (string) ($body['billingCycle'] ?? ''));

        $entry = $this->processor(true)->link(
            (int) $mapping['whmcs_product_id'],
            $billingCycle,
            $customerReference,
            $key,
            $orderId
        );
        return [
            'downstreamRef' => $downstreamRef,
            'quantity'      => 1,
            'replayed'      => true,
            'linked'        => true,
            'keys'          => [$entry],
        ];
    }

    /** A failed unit of a multi-unit order must not hide the units already charged and delivered. */
    private function withDeliveredUnits(ApiException $e, array $keys): ApiException
    {
        $ids = array_map(fn ($k) => (int) $k['upstreamOrderId'], $keys);
        return new ApiException(
            $e->getMessage() . ' Units already delivered by this request: #' . implode(', #', $ids)
            . ' (fetch their keys with getAccessCode).',
            $e->getHttpStatus(),
            $e->getErrorCode(),
            array_merge($e->getDetails(), ['deliveredOrderIds' => $ids])
        );
    }

    /**
     * @param bool $needsPurchases whether the purchase records must be initialized first
     *                             (ordering and linking read them; renewals and key reads do not)
     * @throws ApiException
     */
    private function processor(bool $needsPurchases): PurchaseProcessor
    {
        $purchases = new PurchaseRepository();
        if ($needsPurchases && !$purchases->ensureReady(PurchaseProcessor::KEY_LOCK_SECONDS)) {
            throw new ApiException('The Hub is initializing its purchase records; retry shortly.', 409, 'initializing');
        }
        return new PurchaseProcessor($this->repo, $purchases, $this->partner);
    }

    // -- Lifecycle relays ---------------------------------------------------
    //
    // Every action on a service runs under the partner client's credit lock, with its status
    // check inside it (withServiceLock). A refund therefore never interleaves with another
    // action on the same account: an unsuspend finishing after a refund would revive its key.

    /**
     * Renew a service by settling its outstanding renewal invoice from the partner's
     * native WHMCS credit.
     *
     * Partner-Hub products are MANUAL RENEWAL. WHMCS generates the renewal invoice and
     * its email exactly as standard, but with "Automatic Credit Use" off nothing pays
     * it — it simply stays Unpaid and the partner's credit is never consumed. This
     * action pays it, which drives WHMCS's normal renewal path: nextduedate advances
     * one cycle and vpnhoodstore_Renew re-syncs the access-server token. If the partner
     * never calls it, the token expires on the term end date and access stops. Lines of keys
     * that have ended come off the invoice first, so a renewal never pays for them.
     *
     * Services whose product is not Hub-mapped (one-time products, or anything created
     * outside the Hub) keep the original expiry re-sync behavior. An ended service is
     * refused by both.
     */
    private function renew(array $body): array
    {
        $orderId = $this->requestedOrderId($body);
        return $this->withServiceLock(function () use ($orderId): array {
            $serviceId = (int) $this->liveServiceByOrder($orderId, 'renewed')->id;

            if (!$this->repo->isPartnerProductService($serviceId)) {
                return $this->resyncExpiry($orderId, $serviceId);
            }

            $invoiceId = $this->repo->outstandingRenewalInvoiceId($serviceId);
            if ($invoiceId === null) {
                throw new ApiException(
                    'No renewal invoice is currently outstanding for this service. Renewal becomes '
                    . 'available once WHMCS has generated the upcoming renewal invoice.',
                    409
                );
            }

            // In full or not at all: paying a Hosting renewal invoice is what triggers WHMCS's
            // standard renewal (nextduedate advance + vpnhoodstore_Renew).
            $this->processor(false)->settleInvoiceLocked($invoiceId);

            // Guarantee the token expiry matches the (now advanced) nextduedate, whether or
            // not paying the invoice already ran vpnhoodstore_Renew. Idempotent either way.
            $model = \WHMCS\Service\Service::find($serviceId);
            if ($model) {
                $result = Helper::renew(['model' => $model]);
                if ($result !== 'success') {
                    throw new ApiException($result, 502);
                }
            }

            return [
                'upstreamOrderId' => $orderId,
                'status'          => 'renewed',
                'nextDueDate'     => $this->repo->serviceNextDueDate($serviceId),
            ];
        });
    }

    /**
     * Re-sync the access-server token expiry to the service's current nextduedate,
     * with no billing. Used for services that are not Hub-mapped products.
     */
    private function resyncExpiry(int $orderId, int $serviceId): array
    {
        $model = \WHMCS\Service\Service::find($serviceId);
        if (!$model) {
            throw new ApiException('Service not found for renewal.', 404);
        }

        $result = Helper::renew(['model' => $model]);
        if ($result !== 'success') {
            throw new ApiException($result, 502);
        }

        return [
            'upstreamOrderId' => $orderId,
            'status'          => 'renewed',
            'nextDueDate'     => $model->nextduedate ?? null,
        ];
    }

    private function suspend(array $body): array
    {
        $orderId = $this->requestedOrderId($body);
        $suspendReason = (string) ($body['suspendReason'] ?? '');
        return $this->withServiceLock(function () use ($orderId, $suspendReason): array {
            $params = ['serviceid' => (int) $this->liveServiceByOrder($orderId, 'suspended')->id];
            if ($suspendReason !== '') {
                $params['suspendreason'] = $suspendReason;
            }
            LocalApi::call('ModuleSuspend', $params);
            return ['upstreamOrderId' => $orderId, 'status' => 'suspended'];
        });
    }

    private function unsuspend(array $body): array
    {
        $orderId = $this->requestedOrderId($body);
        return $this->withServiceLock(function () use ($orderId): array {
            LocalApi::call('ModuleUnsuspend', ['serviceid' => (int) $this->liveServiceByOrder($orderId, 'unsuspended')->id]);
            return ['upstreamOrderId' => $orderId, 'status' => 'active'];
        });
    }

    private function terminate(array $body): array
    {
        $orderId = $this->requestedOrderId($body);
        return $this->withServiceLock(function () use ($orderId): array {
            LocalApi::call('ModuleTerminate', ['serviceid' => (int) $this->ownedServiceByOrder($orderId)->id]);
            return ['upstreamOrderId' => $orderId, 'status' => 'terminated'];
        });
    }

    /**
     * Refund an order the Hub sold: its key ends and the invoice total returns to the partner's
     * credit, inside the admin-set window (PurchaseProcessor::refundLocked, RefundPolicy).
     */
    private function refund(array $body): array
    {
        $orderId = $this->requestedOrderId($body);
        $processor = $this->processor(true);
        $days = $this->repo->settings()['refundDays'];
        return $this->withServiceLock(
            fn (): array => $processor->refundLocked($orderId, (int) $this->ownedServiceByOrder($orderId)->id, $days)
        );
    }

    // -- Helpers ------------------------------------------------------------

    /**
     * Run a service action under the partner client's credit lock, the lock every payment from
     * that credit takes too (PurchaseProcessor).
     *
     * @throws ApiException
     */
    private function withServiceLock(callable $action): array
    {
        $purchases = new PurchaseRepository();
        $lock = PurchaseRepository::creditLock((int) $this->partner['client_id']);
        if (!$purchases->lock($lock, PurchaseProcessor::CREDIT_LOCK_SECONDS)) {
            throw new ApiException('Another request on this account is still running; retry shortly.', 409, 'in_progress');
        }
        try {
            return $action();
        } finally {
            $purchases->unlock($lock);
        }
    }

    /**
     * The order's service, refused once it has ended: termination is final through this API, so
     * no suspend, unsuspend or renewal brings back a key that was terminated or refunded.
     *
     * @throws ApiException
     */
    private function liveServiceByOrder(int $orderId, string $actionPast)
    {
        $service = $this->ownedServiceByOrder($orderId);
        $status = (string) $service->domainstatus;
        if (in_array($status, PurchaseProcessor::ENDED_STATUSES, true)) {
            throw new ApiException(
                "Order #{$orderId} is {$status}: an ended key cannot be {$actionPast}. Buy a new key instead.",
                409,
                'service_ended',
                ['upstreamOrderId' => $orderId, 'status' => $status]
            );
        }
        return $service;
    }

    /**
     * Read and validate the upstream order id from a request body.
     *
     * @throws ApiException
     */
    private function requestedOrderId(array $body): int
    {
        $orderId = (int) ($body['upstreamOrderId'] ?? 0);
        if ($orderId <= 0) {
            throw new ApiException('upstreamOrderId is required.', 422);
        }
        return $orderId;
    }

    /**
     * @throws ApiException
     */
    private function requestedKey(array $body, bool $required): string
    {
        $key = $body['idempotencyKey'] ?? '';
        if (!is_string($key)) {
            throw new ApiException('idempotencyKey must be a string.', 422);
        }
        if ($key === '') {
            if ($required) {
                throw new ApiException('idempotencyKey is required.', 422);
            }
            return '';
        }
        if (!preg_match(PurchaseRepository::KEY_PATTERN, $key)) {
            throw new ApiException(
                'idempotencyKey must be 1-64 characters: letters, digits, ".", "-" or "_".',
                422
            );
        }
        return $key;
    }

    /**
     * @throws ApiException
     */
    private function assertReferenceLength(string $reference): void
    {
        if (mb_strlen($reference) > PurchaseRepository::MAX_REFERENCE_LENGTH) {
            throw new ApiException(
                'customerReference must be at most ' . PurchaseRepository::MAX_REFERENCE_LENGTH . ' characters.',
                422
            );
        }
    }

    /**
     * @throws ApiException
     */
    private function mappedProduct(string $downstreamRef): array
    {
        $mapping = $this->repo->resolveProduct((int) $this->partner['id'], $downstreamRef);
        if ($mapping === null) {
            throw new ApiException("Product '{$downstreamRef}' is not available to this partner.", 403);
        }
        return $mapping;
    }

    /**
     * Load the service belonging to an upstream ORDER, scoped to this partner's client.
     *
     * The Hub places one order per unit, so a Hub order maps to exactly one service. The
     * userid condition is what prevents a partner from acting on another partner's order —
     * never look an order up without it.
     *
     * @throws ApiException
     */
    private function ownedServiceByOrder(int $orderId)
    {
        $service = Capsule::table('tblhosting')
            ->where('orderid', $orderId)
            ->where('userid', (int) $this->partner['client_id'])
            ->first();

        if ($service === null) {
            // Name the id we wanted. The client area addresses services by their OWN id
            // (clientarea.php?action=productdetails&id=...), and the two sequences hand the
            // same number to unrelated records — a partner who read the number off that page
            // sends a service id and, without this sentence, gets a bare 404 (seen 2026-09-01).
            throw new ApiException(
                'Order not found for this partner. upstreamOrderId is our WHMCS ORDER id, the one '
                . '"order" returned — not the service id shown on the client-area service page.',
                404
            );
        }
        return $service;
    }

    /**
     * Decide which WHMCS billing cycle to order.
     *
     * Falls back to the mapping's default cycle when the connector sends none. When a
     * cycle IS requested, it must be one the upstream product actually offers, otherwise
     * we reject the order (purchase-time enforcement of the cycle contract).
     *
     * @throws ApiException
     */
    private function resolveBillingCycle(array $mapping, string $requested): string
    {
        // One-time/free products have no recurring cycle. WHMCS reports such a service's
        // cycle as "One Time" (the connector relays it verbatim), which is not a cycle
        // name — so skip cycle resolution entirely and place the order as 'onetime'.
        if ($this->repo->productPaymentType((int) $mapping['whmcs_product_id']) !== 'recurring') {
            return 'onetime';
        }

        $default = $this->billingCycleName((int) $mapping['billing_cycle_months']);
        $requested = strtolower(trim($requested));
        if ($requested === '' || $requested === $default) {
            return $default;
        }

        $requestedMonths = $this->cycleNameToMonths($requested);
        $available = $this->repo->productAvailableCycleMonths((int) $mapping['whmcs_product_id']);
        if ($requestedMonths === 0 || !in_array($requestedMonths, $available, true)) {
            throw new ApiException(
                "Billing cycle '{$requested}' is not available for product '{$mapping['downstream_ref']}'.",
                422
            );
        }

        return $requested;
    }

    /** Normalize a WHMCS "Payment Type" to one of free|onetime|recurring (defaulting to recurring). */
    private function normalizePaymentType($paytype): string
    {
        $paytype = strtolower(trim((string) $paytype));
        return in_array($paytype, ['free', 'onetime', 'recurring'], true) ? $paytype : 'recurring';
    }

    private function cycleNameToMonths(string $name): int
    {
        switch (strtolower($name)) {
            case 'monthly':      return 1;
            case 'quarterly':    return 3;
            case 'semiannually': return 6;
            case 'annually':     return 12;
            case 'biennially':   return 24;
            case 'triennially':  return 36;
            default:             return 0;
        }
    }

    private function billingCycleName(int $months): string
    {
        switch ($months) {
            case 1:  return 'monthly';
            case 3:  return 'quarterly';
            case 6:  return 'semiannually';
            case 12: return 'annually';
            case 24: return 'biennially';
            case 36: return 'triennially';
            default: return 'monthly';
        }
    }
}
