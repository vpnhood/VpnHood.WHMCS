<?php

namespace WHMCS\Module\Addon\VpnHoodPartnerHub;

use WHMCS\Database\Capsule;

// Reuse the existing access-server client + helpers from the vpnhoodstore server module.
require_once __DIR__ . '/../../../servers/vpnhoodstore/lib/ApiService.php';
require_once __DIR__ . '/../../../servers/vpnhoodstore/lib/Helper.php';

use WHMCS\Module\Server\VpnHoodStore\ApiService;
use WHMCS\Module\Server\VpnHoodStore\Helper;

/**
 * Implements the partner-facing API actions.
 *
 * Provisioning is delegated to WHMCS localAPI (AddOrder/AcceptOrder), which runs
 * the existing vpnhoodstore server module against the VpnHood access server.
 * Payment uses the partner client's NATIVE WHMCS credit balance, applied EXPLICITLY
 * by settleFromCredit(). We never provision an unpaid order: if the invoice is not
 * Paid after ordering, the order is rolled back and a 402 is returned.
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
            case 'renew':           return $this->renew($body);
            case 'suspend':         return $this->suspend($body);
            case 'unsuspend':       return $this->unsuspend($body);
            case 'terminate':       return $this->terminate($body);
            case 'cancel':          return $this->terminate($body); // alias
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
     * Return the CURRENT access code for an order, fetched live from the access server.
     *
     * The connector stores only the order id and calls this on demand (its "Get Premium
     * Code" button), mirroring how vpnhoodstore's client area fetches the code. The
     * accessTokenId is resolved here from the partner's own service — it is never taken
     * from the request, so a partner cannot read another partner's token.
     */
    private function getAccessCode(array $body): array
    {
        $orderId = $this->requestedOrderId($body);
        $service = $this->ownedServiceByOrder($orderId);

        $accessTokenId = $this->serviceProperty($service, 'accessTokenId');
        if (!$accessTokenId) {
            throw new ApiException('No access token is available for this order.', 404);
        }

        $json = json_decode((new ApiService())->getAccessCode($accessTokenId));
        $accessCode = $json->accessToken->accessCode ?? null;
        if ($accessCode === null) {
            throw new ApiException('The access server did not return an access code.', 502);
        }

        return [
            'upstreamOrderId' => $orderId,
            'accessTokenId'   => $accessTokenId,
            'accessCode'      => $accessCode,
        ];
    }

    // -- Order (create + deliver) ------------------------------------------

    private function order(array $body): array
    {
        $downstreamRef = (string) ($body['downstreamRef'] ?? '');
        $quantity = (int) ($body['quantity'] ?? 1);
        if ($quantity < 1 || $quantity > self::MAX_ORDER_QUANTITY) {
            throw new ApiException('quantity must be between 1 and ' . self::MAX_ORDER_QUANTITY . '.', 422);
        }
        $customerReference = (string) ($body['customerReference'] ?? '');

        $mapping = $this->repo->resolveProduct((int) $this->partner['id'], $downstreamRef);
        if ($mapping === null) {
            throw new ApiException("Product '{$downstreamRef}' is not available to this partner.", 403);
        }

        // Purchase-time enforcement of "Allow Multiple Quantities": bulk orders are only
        // accepted when the upstream product explicitly allows them on its Pricing tab.
        if ($quantity > 1 && !$this->repo->productAllowsMultipleQuantities((int) $mapping['whmcs_product_id'])) {
            throw new ApiException(
                "Product '{$downstreamRef}' does not allow multiple quantities; order quantity must be 1.",
                422
            );
        }

        $billingCycle = $this->resolveBillingCycle($mapping, (string) ($body['billingCycle'] ?? ''));
        $keys = [];

        // One key per unit. Each unit is its own WHMCS order/service, paid from credit.
        for ($i = 0; $i < $quantity; $i++) {
            $keys[] = $this->placeSingleOrder(
                (int) $mapping['whmcs_product_id'],
                $billingCycle,
                $customerReference
            );
        }

        return [
            'downstreamRef' => $downstreamRef,
            'quantity'      => $quantity,
            'keys'          => $keys,
        ];
    }

    /**
     * Place one order, settle it from credit, provision, and return the key.
     *
     * @throws ApiException
     */
    private function placeSingleOrder(int $productId, string $billingCycle, string $customerReference): array
    {
        $clientId = (int) $this->partner['client_id'];

        // 1. Create the order (and its invoice). Credit is NOT auto-applied — WHMCS
        //    "Automatic Credit Use" is off so renewal invoices are never auto-paid.
        $orderParams = [
            'clientid'       => $clientId,
            'pid'            => $productId,
            'billingcycle'   => $billingCycle,
            'noemail'        => true,
            'noinvoiceemail' => true,
        ];
        $gateway = $this->repo->settings()['orderGateway'];
        if ($gateway !== '') {
            $orderParams['paymentmethod'] = $gateway;
        }
        $add = $this->localApi('AddOrder', $orderParams);

        $orderId = (int) $add['orderid'];
        $invoiceId = (int) ($add['invoiceid'] ?? 0);
        $serviceId = (int) $this->firstId($add['productids'] ?? '');

        try {
            // 2. Settle this order invoice from the partner's credit explicitly, then
            //    require Paid before provisioning.
            $this->settleFromCredit($invoiceId);
            $this->assertInvoicePaid($invoiceId);

            // 3. Accept the order → triggers vpnhoodstore_CreateAccount provisioning.
            $this->localApi('AcceptOrder', [
                'orderid'   => $orderId,
                'autosetup' => true,
                'sendemail' => false,
            ]);

            // 4. Read back the provisioned key for synchronous delivery.
            $delivery = $this->readDelivery($serviceId, $orderId, $clientId);
        } catch (ApiException $e) {
            // Roll back: remove the unprovisioned order/invoice/service entirely.
            $this->safeDeleteOrder($orderId);
            throw $e;
        }

        return array_merge([
            'upstreamOrderId'   => $orderId,
            'customerReference' => $customerReference,
        ], $delivery);
    }

    /**
     * Apply the client's native WHMCS credit to an invoice.
     *
     * WHMCS "Automatic Credit Use" is deliberately OFF on this install, so nothing is
     * ever paid from credit on its own. That is what makes recurring Partner-Hub
     * products manual-renewal: their renewal invoices are generated as standard and
     * simply stay Unpaid. Credit is applied only where we explicitly mean it — the
     * initial order invoice, and an outstanding renewal invoice in renew().
     *
     * @throws ApiException
     */
    private function settleFromCredit(int $invoiceId): void
    {
        if ($invoiceId === 0) {
            return; // Free product — no invoice to settle.
        }

        require_once ROOTDIR . '/includes/invoicefunctions.php';
        if (!function_exists('applyCredit')) {
            throw new ApiException('WHMCS credit application is unavailable on this install.', 500);
        }

        applyCredit($invoiceId);
    }

    /**
     * @throws ApiException
     */
    private function assertInvoicePaid(int $invoiceId): void
    {
        if ($invoiceId === 0) {
            return; // Free product (no invoice) — nothing to settle.
        }

        $status = Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status');
        if ($status !== 'Paid') {
            $balance = $this->repo->getClientCredit((int) $this->partner['client_id']);
            throw new ApiException(
                "Insufficient credit to cover the order. Available balance: {$balance}.",
                402
            );
        }
    }

    /**
     * Read the access code (Normal) or CSV (bulk) for a provisioned service.
     * Reuses the access-server client from the vpnhoodstore module.
     */
    private function readDelivery(int $serviceId, int $orderId, int $clientId): array
    {
        $isNormal = $this->isNormalDelivery($serviceId);
        $apiService = new ApiService();

        if ($isNormal) {
            $service = $this->ownedService($serviceId);
            $accessTokenId = $this->serviceProperty($service, 'accessTokenId');
            if (!$accessTokenId) {
                throw new ApiException('Provisioning succeeded but no access token was found.', 502);
            }
            $json = json_decode($apiService->getAccessCode($accessTokenId));
            return [
                'deliveryType' => 'normal',
                // The connector persists accessTokenId and re-fetches the code on demand
                // via getAccessCode; accessCode here is the value at provisioning time.
                'accessTokenId' => $accessTokenId,
                'accessCode'    => $json->accessToken->accessCode ?? null,
            ];
        }

        // Bulk/CSV delivery: return the CSV payload for the partner to pass on.
        $csv = $apiService->getAccessCodeCsvFile((string) $clientId, (string) $orderId);
        return [
            'deliveryType' => 'csv',
            'csv'          => $csv,
        ];
    }

    // -- Lifecycle relays ---------------------------------------------------

    /**
     * Renew a service by settling its outstanding renewal invoice from the partner's
     * native WHMCS credit.
     *
     * Partner-Hub products are MANUAL RENEWAL. WHMCS generates the renewal invoice and
     * its email exactly as standard, but with "Automatic Credit Use" off nothing pays
     * it — it simply stays Unpaid and the partner's credit is never consumed. This
     * action pays it, which drives WHMCS's normal renewal path: nextduedate advances
     * one cycle and vpnhoodstore_Renew re-syncs the access-server token. If the partner
     * never calls it, the token expires on the term end date and access stops.
     *
     * Services whose product is not Hub-mapped (one-time products, or anything created
     * outside the Hub) keep the original expiry re-sync behavior.
     */
    private function renew(array $body): array
    {
        $orderId = $this->requestedOrderId($body);
        $serviceId = (int) $this->ownedServiceByOrder($orderId)->id;

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

        $balance = $this->repo->invoiceBalance($invoiceId);
        $credit = $this->repo->getClientCredit((int) $this->partner['client_id']);
        if ($credit + 0.005 < $balance) {
            throw new ApiException(
                "Insufficient credit to renew. Invoice balance: {$balance}, available: {$credit}.",
                402
            );
        }

        // Settle from native credit: paying a Hosting renewal invoice is what triggers
        // WHMCS's standard renewal (nextduedate advance + vpnhoodstore_Renew).
        $this->settleFromCredit($invoiceId);

        if (Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status') !== 'Paid') {
            throw new ApiException('Renewal invoice could not be settled from credit.', 402);
        }

        // Guarantee the token expiry matches the (now advanced) nextduedate, whether or
        // not paying the invoice already ran vpnhoodstore_Renew. Idempotent either way.
        $model = \WHMCS\Service\Service::find($serviceId);
        if ($model) {
            $result = Helper::renewOrUnsuspend(['model' => $model]);
            if ($result !== 'success') {
                throw new ApiException($result, 502);
            }
        }

        return [
            'upstreamOrderId' => $orderId,
            'status'          => 'renewed',
            'nextDueDate'     => $this->repo->serviceNextDueDate($serviceId),
        ];
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

        $result = Helper::renewOrUnsuspend(['model' => $model]);
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
        $serviceId = (int) $this->ownedServiceByOrder($orderId)->id;
        $this->localApi('ModuleSuspend', ['serviceid' => $serviceId]);
        return ['upstreamOrderId' => $orderId, 'status' => 'suspended'];
    }

    private function unsuspend(array $body): array
    {
        $orderId = $this->requestedOrderId($body);
        $serviceId = (int) $this->ownedServiceByOrder($orderId)->id;
        $this->localApi('ModuleUnsuspend', ['serviceid' => $serviceId]);
        return ['upstreamOrderId' => $orderId, 'status' => 'active'];
    }

    private function terminate(array $body): array
    {
        $orderId = $this->requestedOrderId($body);
        $serviceId = (int) $this->ownedServiceByOrder($orderId)->id;
        $this->localApi('ModuleTerminate', ['serviceid' => $serviceId]);
        return ['upstreamOrderId' => $orderId, 'status' => 'terminated'];
    }

    // -- Helpers ------------------------------------------------------------

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
     * Load the service belonging to an upstream ORDER, scoped to this partner's client.
     *
     * The Hub places one order per unit (placeSingleOrder), so a Hub order maps to exactly
     * one service. The userid condition is what prevents a partner from acting on another
     * partner's order — never look an order up without it.
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
            throw new ApiException('Order not found for this partner.', 404);
        }
        return $service;
    }

    /**
     * Load a service by its own id and assert it belongs to this partner's client.
     * Used internally during ordering, where the service id is already known.
     *
     * @throws ApiException
     */
    private function ownedService(int $serviceId)
    {
        $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        if ($service === null || (int) $service->userid !== (int) $this->partner['client_id']) {
            throw new ApiException('Service not found for this partner.', 404);
        }
        return $service;
    }

    /** Whether a service's product uses Normal (single) token delivery. */
    private function isNormalDelivery(int $serviceId): bool
    {
        // vpnhoodstore stores token delivery in product config option 4 (0=Normal, 1=CSV).
        $packageId = Capsule::table('tblhosting')->where('id', $serviceId)->value('packageid');
        $value = Capsule::table('tblproducts')->where('id', $packageId)->value('configoption4');
        return (string) $value === '0' || $value === null || $value === '';
    }

    /** Read a stored service property (e.g. accessTokenId) via the WHMCS Service model. */
    private function serviceProperty($service, string $key): ?string
    {
        try {
            $model = \WHMCS\Service\Service::find($service->id);
            if ($model) {
                $value = $model->serviceProperties->get($key);
                if ($value) {
                    return (string) $value;
                }
            }
        } catch (\Throwable $e) {
            // Fall through to null.
        }
        return null;
    }

    /**
     * @throws ApiException
     */
    private function localApi(string $action, array $params): array
    {
        $params['responsetype'] = 'json';
        $result = localAPI($action, $params);

        if (($result['result'] ?? '') !== 'success') {
            $message = $result['message'] ?? ('localAPI ' . $action . ' failed');
            throw new ApiException($message, 502);
        }
        return $result;
    }

    /**
     * Fully remove a failed order (order + invoice + provisioned service).
     *
     * WHMCS DeleteOrder refuses unless the order is Cancelled/Fraud
     * ("The order status must be in Cancelled or Fraud to be deleted"), so we
     * cancel first and then delete. Both calls are best-effort and their results
     * are logged so an incomplete rollback is never silent.
     */
    private function safeDeleteOrder(int $orderId): void
    {
        $cancel = $this->tryLocalApi('CancelOrder', ['orderid' => $orderId, 'cancelsub' => false]);
        $delete = $this->tryLocalApi('DeleteOrder', ['orderid' => $orderId]);

        try {
            $this->repo->log(
                (int) ($this->partner['id'] ?? 0),
                'rollback',
                null,
                0,
                ['orderid' => $orderId],
                ['cancel' => $cancel, 'delete' => $delete]
            );
        } catch (\Throwable $e) {
            // Logging must never break rollback.
        }
    }

    /** Run a localAPI action without throwing; return the raw result (or an error array). */
    private function tryLocalApi(string $action, array $params): array
    {
        try {
            $params['responsetype'] = 'json';
            return localAPI($action, $params);
        } catch (\Throwable $e) {
            return ['result' => 'exception', 'message' => $e->getMessage()];
        }
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

    /** AddOrder returns comma-separated product ids; take the first. */
    private function firstId($csv): int
    {
        $parts = explode(',', (string) $csv);
        return (int) ($parts[0] ?? 0);
    }
}
