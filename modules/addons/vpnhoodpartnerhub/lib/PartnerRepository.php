<?php

namespace WHMCS\Module\Addon\VpnHoodPartnerHub;

use WHMCS\Database\Capsule;

/**
 * Data access for partners, product mappings, and native WHMCS credit balance.
 *
 * Secrets are stored hashed (password_hash); the plaintext secret is returned
 * only at creation/regeneration time and never persisted in clear.
 */
class PartnerRepository
{
    /** Fetch addon settings (stored by WHMCS in tbladdonmodules). */
    public function settings(): array
    {
        $rows = Capsule::table('tbladdonmodules')
            ->where('module', 'vpnhoodpartnerhub')
            ->pluck('value', 'setting');

        return [
            'requireIpAllowlist' => (($rows['RequireIpAllowlist'] ?? 'no') === 'on'
                || ($rows['RequireIpAllowlist'] ?? 'no') === 'yes'),
            'currency'           => $rows['DefaultCurrency'] ?? 'USD',
            'orderGateway'       => trim($rows['OrderGateway'] ?? ''),
        ];
    }

    // -- Partners -----------------------------------------------------------

    public function getPartner(int $id): ?array
    {
        $row = Capsule::table('mod_vpnhood_partners')->where('id', $id)->first();
        return $row ? (array) $row : null;
    }

    /** Look up a partner by its public API key (for authentication). */
    public function getPartnerByApiKey(string $apiKey): ?array
    {
        $row = Capsule::table('mod_vpnhood_partners')->where('api_key', $apiKey)->first();
        return $row ? (array) $row : null;
    }

    public function allPartnersWithBalance(): array
    {
        $settings = $this->settings();
        $partners = Capsule::table('mod_vpnhood_partners')->orderBy('id')->get();
        $result = [];

        foreach ($partners as $row) {
            $row = (array) $row;
            $client = Capsule::table('tblclients')->where('id', $row['client_id'])->first();
            $row['client_name'] = $client ? trim($client->firstname . ' ' . $client->lastname) : '(missing)';
            $balance = $this->getClientCredit($row['client_id']);
            $row['balance_formatted'] = number_format($balance, 2) . ' ' . $settings['currency'];
            $row['product_count'] = Capsule::table('mod_vpnhood_partner_products')
                ->where('partner_id', $row['id'])->count();
            $result[] = $row;
        }

        return $result;
    }

    public function createPartner(array $data): array
    {
        $apiKey = $this->generateKey(40);
        $secret = $this->generateKey(48);

        $id = Capsule::table('mod_vpnhood_partners')->insertGetId([
            'client_id'       => $data['client_id'],
            'name'            => $data['name'],
            'api_key'         => $apiKey,
            'api_secret_hash' => password_hash($secret, PASSWORD_DEFAULT),
            'status'          => $data['status'],
            'ip_allowlist'    => $data['ip_allowlist'],
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        return ['id' => $id, 'api_key' => $apiKey, 'api_secret' => $secret];
    }

    public function updatePartner(int $id, array $data): void
    {
        Capsule::table('mod_vpnhood_partners')->where('id', $id)->update([
            'client_id'    => $data['client_id'],
            'name'         => $data['name'],
            'status'       => $data['status'],
            'ip_allowlist' => $data['ip_allowlist'],
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /** Delete a partner and all of its product mappings and logs. */
    public function deletePartner(int $id): void
    {
        Capsule::table('mod_vpnhood_partner_products')->where('partner_id', $id)->delete();
        Capsule::table('mod_vpnhood_partner_log')->where('partner_id', $id)->delete();
        Capsule::table('mod_vpnhood_partners')->where('id', $id)->delete();
    }

    public function regenerateSecret(int $id): string
    {
        $secret = $this->generateKey(48);
        Capsule::table('mod_vpnhood_partners')->where('id', $id)->update([
            'api_secret_hash' => password_hash($secret, PASSWORD_DEFAULT),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);
        return $secret;
    }

    // -- Product mappings ---------------------------------------------------

    public function getProductMappings(int $partnerId): array
    {
        $rows = Capsule::table('mod_vpnhood_partner_products as m')
            ->leftJoin('tblproducts as p', 'm.whmcs_product_id', '=', 'p.id')
            ->where('m.partner_id', $partnerId)
            ->select('m.*', 'p.name as product_name', 'p.paytype as payment_type', 'p.allowqty as allow_qty')
            ->orderBy('m.id')
            ->get();

        return array_map(fn($r) => (array) $r, $rows->all());
    }

    /** Resolve a partner's downstream product reference to a mapped, enabled product. */
    public function resolveProduct(int $partnerId, string $downstreamRef): ?array
    {
        $row = Capsule::table('mod_vpnhood_partner_products')
            ->where('partner_id', $partnerId)
            ->where('downstream_ref', $downstreamRef)
            ->where('enabled', 1)
            ->first();
        return $row ? (array) $row : null;
    }

    /** Whether this partner already has the given product mapped. */
    public function productMappingExists(int $partnerId, int $productId): bool
    {
        return Capsule::table('mod_vpnhood_partner_products')
            ->where('partner_id', $partnerId)
            ->where('whmcs_product_id', $productId)
            ->exists();
    }

    /**
     * Recurring billing cycles, in ascending length. Keyed by the tblpricing column;
     * a cycle is "enabled" for a product when that column is >= 0 (WHMCS stores -1
     * for a disabled cycle).
     */
    private function cycleDefinitions(): array
    {
        return [
            'monthly'      => ['months' => 1,  'label' => 'Monthly'],
            'quarterly'    => ['months' => 3,  'label' => 'Quarterly'],
            'semiannually' => ['months' => 6,  'label' => 'Semi-Annually'],
            'annually'     => ['months' => 12, 'label' => 'Annually'],
            'biennially'   => ['months' => 24, 'label' => 'Biennially'],
            'triennially'  => ['months' => 36, 'label' => 'Triennially'],
        ];
    }

    /** Default-currency pricing row for a product, or null. */
    private function productPricing(int $productId): ?object
    {
        return Capsule::table('tblpricing')
            ->where('type', 'product')
            ->where('relid', $productId)
            ->orderBy('currency')
            ->first();
    }

    /**
     * Derive a product's billing cycle (in months) from its WHMCS pricing.
     * Picks the shortest enabled recurring cycle, falling back to monthly.
     */
    public function productBillingCycleMonths(int $productId): int
    {
        $pricing = $this->productPricing($productId);
        if ($pricing) {
            foreach ($this->cycleDefinitions() as $column => $def) {
                if (isset($pricing->$column) && (float) $pricing->$column >= 0) {
                    return $def['months'];
                }
            }
        }

        return 1;
    }

    /**
     * Human-readable labels of every recurring cycle enabled on a product
     * (e.g. ['Monthly', 'Annually']).
     */
    public function productAvailableCycles(int $productId): array
    {
        $pricing = $this->productPricing($productId);
        $labels = [];
        if ($pricing) {
            foreach ($this->cycleDefinitions() as $column => $def) {
                if (isset($pricing->$column) && (float) $pricing->$column >= 0) {
                    $labels[] = $def['label'];
                }
            }
        }

        return $labels;
    }

    /**
     * Billing cycle lengths (in months) of every recurring cycle enabled on a
     * product (e.g. [1, 12]). Used by the partner API so the connector can validate
     * the customer's chosen cycle against what the upstream product actually offers.
     */
    public function productAvailableCycleMonths(int $productId): array
    {
        $pricing = $this->productPricing($productId);
        $months = [];
        if ($pricing) {
            foreach ($this->cycleDefinitions() as $column => $def) {
                if (isset($pricing->$column) && (float) $pricing->$column >= 0) {
                    $months[] = $def['months'];
                }
            }
        }

        return $months;
    }

    /**
     * WHMCS "Payment Type" (free|onetime|recurring) of a product. Billing cycles only
     * exist for recurring products; one-time/free products store their price in the
     * "monthly" pricing column, which must not be read as a real Monthly cycle.
     */
    public function productPaymentType(int $productId): string
    {
        $paytype = strtolower(trim((string) Capsule::table('tblproducts')->where('id', $productId)->value('paytype')));
        return in_array($paytype, ['free', 'onetime', 'recurring'], true) ? $paytype : 'recurring';
    }

    /** Whether the product has "Allow Multiple Quantities" enabled on its Pricing tab. */
    public function productAllowsMultipleQuantities(int $productId): bool
    {
        return (bool) Capsule::table('tblproducts')->where('id', $productId)->value('allowqty');
    }

    public function addProductMapping(int $partnerId, array $data): void
    {
        Capsule::table('mod_vpnhood_partner_products')->insert([
            'partner_id'           => $partnerId,
            'downstream_ref'       => $data['downstream_ref'],
            'whmcs_product_id'     => $data['whmcs_product_id'],
            'billing_cycle_months' => $data['billing_cycle_months'],
            'enabled'              => $data['enabled'],
        ]);
    }

    public function deleteProductMapping(int $mappingId): void
    {
        Capsule::table('mod_vpnhood_partner_products')->where('id', $mappingId)->delete();
    }

    public function whmcsProducts(): array
    {
        $rows = Capsule::table('tblproducts')->orderBy('name')->get(['id', 'name', 'paytype']);
        return array_map(fn($r) => (array) $r, $rows->all());
    }

    /** All WHMCS clients, with a display label for the partner picker. */
    public function whmcsClients(): array
    {
        $rows = Capsule::table('tblclients')
            ->orderBy('companyname')
            ->orderBy('firstname')
            ->get(['id', 'firstname', 'lastname', 'companyname', 'email']);

        return array_map(function ($r) {
            $r = (array) $r;
            $name = $this->composeClientName($r['companyname'], $r['firstname'], $r['lastname'], $r['email']);
            $r['label'] = '#' . $r['id'] . ' ' . $name . ' <' . $r['email'] . '>';
            $r['display_name'] = $name;
            return $r;
        }, $rows->all());
    }

    /** Human-readable name for a client, used as the partner's name. */
    public function clientDisplayName(int $clientId): string
    {
        $c = Capsule::table('tblclients')->where('id', $clientId)->first();
        if (!$c) {
            return 'Client #' . $clientId;
        }
        return $this->composeClientName($c->companyname, $c->firstname, $c->lastname, $c->email);
    }

    /** Prefer company name, then full name, then email, then a client-id fallback. */
    private function composeClientName(?string $company, ?string $first, ?string $last, ?string $email): string
    {
        $company = trim((string) $company);
        if ($company !== '') {
            return $company;
        }
        $full = trim(trim((string) $first) . ' ' . trim((string) $last));
        if ($full !== '') {
            return $full;
        }
        return trim((string) $email) !== '' ? trim((string) $email) : '(no name)';
    }

    // -- Native WHMCS credit ------------------------------------------------

    /** Read the native WHMCS credit balance for a client. */
    public function getClientCredit(int $clientId): float
    {
        $value = Capsule::table('tblclients')->where('id', $clientId)->value('credit');
        return (float) ($value ?? 0);
    }

    // -- Manual renewal (recurring Partner-Hub products) --------------------

    /**
     * Whether a service's product is a Partner-Hub-mapped product. Partner products
     * are distinct from retail products (hidden from the retail store), so membership
     * in mod_vpnhood_partner_products is a reliable "this is a partner order" signal
     * without any per-service tagging.
     */
    public function isPartnerProductService(int $serviceId): bool
    {
        $packageId = Capsule::table('tblhosting')->where('id', $serviceId)->value('packageid');
        if (!$packageId) {
            return false;
        }
        return Capsule::table('mod_vpnhood_partner_products')
            ->where('whmcs_product_id', $packageId)
            ->exists();
    }

    /** Most recent Unpaid invoice carrying a Hosting line for this service, or null. */
    public function outstandingRenewalInvoiceId(int $serviceId): ?int
    {
        $id = Capsule::table('tblinvoiceitems as i')
            ->join('tblinvoices as inv', 'i.invoiceid', '=', 'inv.id')
            ->where('i.type', 'Hosting')
            ->where('i.relid', $serviceId)
            ->where('inv.status', 'Unpaid')
            ->orderBy('inv.id', 'desc')
            ->value('inv.id');

        return $id ? (int) $id : null;
    }

    /** Outstanding balance (invoice total minus recorded payments). */
    public function invoiceBalance(int $invoiceId): float
    {
        $total = (float) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('total');
        $paid = (float) Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountin');
        return round($total - $paid, 2);
    }

    public function serviceNextDueDate(int $serviceId): ?string
    {
        return Capsule::table('tblhosting')->where('id', $serviceId)->value('nextduedate');
    }

    // -- Logging ------------------------------------------------------------

    public function log(?int $partnerId, string $action, ?string $remoteIp, int $httpStatus, $request, $response): void
    {
        try {
            Capsule::table('mod_vpnhood_partner_log')->insert([
                'partner_id'  => $partnerId,
                'action'      => $action,
                'remote_ip'   => $remoteIp,
                'http_status' => $httpStatus,
                'request'     => is_string($request) ? $request : json_encode($request),
                'response'    => is_string($response) ? $response : json_encode($response),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Logging must never break the request.
        }
    }

    private function generateKey(int $length): string
    {
        return substr(bin2hex(random_bytes($length)), 0, $length);
    }
}
