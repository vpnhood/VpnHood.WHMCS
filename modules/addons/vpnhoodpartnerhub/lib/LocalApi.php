<?php

namespace WHMCS\Module\Addon\VpnHoodPartnerHub;

/**
 * WHMCS localAPI, with failures turned into an ApiException the partner can read.
 *
 * The status is 422 and NOT a 5xx, deliberately. WHMCS's own failure messages here are
 * business errors the partner must see to fix anything — "Invalid Payment Method", "Invalid
 * Product ID", and so on. A 5xx status gets intercepted by the CDN in front of this WHMCS:
 * Cloudflare replaces the body of a 502 with its own "error code: 502" page, so the connector
 * receives a response whose only content is the status code it already had. That turned a
 * one-line misconfiguration (an OrderGateway set to a gateway's display name instead of its
 * system name) into an undiagnosable failure — the real reason existed only in
 * mod_vpnhood_partner_log on this server, where the partner cannot see it. 4xx bodies pass
 * through untouched.
 */
final class LocalApi
{
    /**
     * @throws ApiException
     */
    public static function call(string $action, array $params): array
    {
        $params['responsetype'] = 'json';
        $result = localAPI($action, $params);

        if (($result['result'] ?? '') !== 'success') {
            $message = $result['message'] ?? ('localAPI ' . $action . ' failed');
            throw new ApiException('Upstream WHMCS rejected ' . $action . ': ' . $message, 422);
        }
        return $result;
    }

    /** Run an action without throwing; return the raw result (or an error array). */
    public static function tryCall(string $action, array $params): array
    {
        try {
            $params['responsetype'] = 'json';
            return localAPI($action, $params);
        } catch (\Throwable $e) {
            return ['result' => 'exception', 'message' => $e->getMessage()];
        }
    }
}
