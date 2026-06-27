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
 * Payment uses the partner client's NATIVE WHMCS credit balance — WHMCS applies
 * available credit to the generated invoice automatically. We never provision an
 * unpaid order: if the invoice is not Paid after ordering, the order is rolled
 * back and a 402 is returned.
 *
 * IMPORTANT (admin requirement): the partner's WHMCS client must carry enough
 * credit, and WHMCS automatic credit application must remain enabled, so the
 * order invoice is settled from credit at generation time.
 */
class PartnerApiController
{
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
                'billingCycleMonths' => (int) $m['billing_cycle_months'],
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
        $serviceId = (int) ($body['upstreamServiceId'] ?? 0);
        $service = $this->ownedService($serviceId);

        return [
            'upstreamServiceId' => $serviceId,
            'status'            => $service->domainstatus,
            'nextDueDate'       => $service->nextduedate,
        ];
    }

    // -- Order (create + deliver) ------------------------------------------

    private function order(array $body): array
    {
        $downstreamRef = (string) ($body['downstreamRef'] ?? '');
        $quantity = max(1, (int) ($body['quantity'] ?? 1));
        $customerReference = (string) ($body['customerReference'] ?? '');

        $mapping = $this->repo->resolveProduct((int) $this->partner['id'], $downstreamRef);
        if ($mapping === null) {
            throw new ApiException("Product '{$downstreamRef}' is not available to this partner.", 403);
        }

        $billingCycle = $this->billingCycleName((int) $mapping['billing_cycle_months']);
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

        // 1. Create the order (and its invoice). WHMCS applies credit automatically.
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
            // 2. Ensure the invoice was settled from credit before provisioning.
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
            'upstreamServiceId' => $serviceId,
            'orderId'           => $orderId,
            'customerReference' => $customerReference,
        ], $delivery);
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
                'accessCode'   => $json->accessToken->accessCode ?? null,
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
     * Sync the access-server token expiry to the service's current nextduedate.
     *
     * Routine charging is handled natively: each order created a RECURRING service
     * on the partner's client, so WHMCS invoices it every cycle and settles it from
     * the partner's credit automatically. This endpoint lets the connector force an
     * expiry re-sync (e.g. after an early/manual renewal upstream). It reuses the
     * existing vpnhoodstore Helper so the access-server logic is not duplicated.
     */
    private function renew(array $body): array
    {
        $serviceId = (int) ($body['upstreamServiceId'] ?? 0);
        $this->ownedService($serviceId);

        // Optionally extend the term when the connector requests a specific paid-through date.
        if (!empty($body['nextDueDate'])) {
            $this->localApi('UpdateClientProduct', [
                'serviceid'   => $serviceId,
                'nextduedate' => date('Y-m-d', strtotime((string) $body['nextDueDate'])),
            ]);
        }

        $model = \WHMCS\Service\Service::find($serviceId);
        if (!$model) {
            throw new ApiException('Service not found for renewal.', 404);
        }

        $result = Helper::renewOrUnsuspend(['model' => $model]);
        if ($result !== 'success') {
            throw new ApiException($result, 502);
        }

        return [
            'upstreamServiceId' => $serviceId,
            'status'            => 'renewed',
            'nextDueDate'       => $model->nextduedate ?? null,
        ];
    }

    private function suspend(array $body): array
    {
        $serviceId = (int) ($body['upstreamServiceId'] ?? 0);
        $this->ownedService($serviceId);
        $this->localApi('ModuleSuspend', ['serviceid' => $serviceId]);
        return ['upstreamServiceId' => $serviceId, 'status' => 'suspended'];
    }

    private function unsuspend(array $body): array
    {
        $serviceId = (int) ($body['upstreamServiceId'] ?? 0);
        $this->ownedService($serviceId);
        $this->localApi('ModuleUnsuspend', ['serviceid' => $serviceId]);
        return ['upstreamServiceId' => $serviceId, 'status' => 'active'];
    }

    private function terminate(array $body): array
    {
        $serviceId = (int) ($body['upstreamServiceId'] ?? 0);
        $this->ownedService($serviceId);
        $this->localApi('ModuleTerminate', ['serviceid' => $serviceId]);
        return ['upstreamServiceId' => $serviceId, 'status' => 'terminated'];
    }

    // -- Helpers ------------------------------------------------------------

    /**
     * Load a service and assert it belongs to this partner's client.
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

    private function safeDeleteOrder(int $orderId): void
    {
        try {
            localAPI('DeleteOrder', ['orderid' => $orderId]);
        } catch (\Throwable $e) {
            // Best-effort rollback; surface nothing further.
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
