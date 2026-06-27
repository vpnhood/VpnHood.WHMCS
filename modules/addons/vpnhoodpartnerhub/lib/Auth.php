<?php

namespace WHMCS\Module\Addon\VpnHoodPartnerHub;

/**
 * Partner-scoped authentication for the Hub API.
 *
 * A partner authenticates with their public API key + secret, sent over HTTPS:
 *   X-Vpnhood-Key:    <api_key>
 *   X-Vpnhood-Secret: <api_secret>
 *
 * The secret is stored hashed (password_hash) so it is verified with
 * password_verify, not echoed back. On top of credentials we enforce partner
 * status and (optionally) an IP allowlist.
 *
 * Throwing ApiException short-circuits the request with the given HTTP status.
 */
class Auth
{
    private PartnerRepository $repo;

    public function __construct(PartnerRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Authenticate the current request and return the partner record.
     *
     * @throws ApiException
     */
    public function authenticate(array $headers, string $remoteIp): array
    {
        $apiKey = $headers['x-vpnhood-key'] ?? '';
        $secret = $headers['x-vpnhood-secret'] ?? '';

        if ($apiKey === '' || $secret === '') {
            throw new ApiException('Missing API credentials.', 401);
        }

        $partner = $this->repo->getPartnerByApiKey($apiKey);
        if ($partner === null || !password_verify($secret, $partner['api_secret_hash'])) {
            throw new ApiException('Invalid API credentials.', 401);
        }

        if ($partner['status'] !== 'active') {
            throw new ApiException('Partner account is suspended.', 403);
        }

        $this->enforceIpAllowlist($partner, $remoteIp);

        return $partner;
    }

    /**
     * @throws ApiException
     */
    private function enforceIpAllowlist(array $partner, string $remoteIp): void
    {
        $settings = $this->repo->settings();
        $allowlist = array_filter(array_map('trim', explode(',', (string) $partner['ip_allowlist'])));

        if (empty($allowlist)) {
            if ($settings['requireIpAllowlist']) {
                throw new ApiException('IP allowlist is required but empty for this partner.', 403);
            }
            return; // No allowlist configured and not required → allow.
        }

        if (!in_array($remoteIp, $allowlist, true)) {
            throw new ApiException('Request IP is not allowed.', 403);
        }
    }
}
