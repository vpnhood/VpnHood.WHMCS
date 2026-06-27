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
            ->select('m.*', 'p.name as product_name')
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
        $rows = Capsule::table('tblproducts')->orderBy('name')->get(['id', 'name']);
        return array_map(fn($r) => (array) $r, $rows->all());
    }

    // -- Native WHMCS credit ------------------------------------------------

    /** Read the native WHMCS credit balance for a client. */
    public function getClientCredit(int $clientId): float
    {
        $value = Capsule::table('tblclients')->where('id', $clientId)->value('credit');
        return (float) ($value ?? 0);
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
