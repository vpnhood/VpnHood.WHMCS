<?php

/**
 * VpnHood! Partner Hub — REST entry point.
 *
 * Public URL (on OUR WHMCS):
 *   https://your-whmcs.example.com/modules/addons/vpnhoodpartnerhub/api.php
 *
 * Auth headers (over HTTPS):
 *   X-Vpnhood-Key:    <partner api key>
 *   X-Vpnhood-Secret: <partner api secret>
 *
 * Request body: JSON  { "action": "...", ...params }
 * Response:     JSON  { "success": true, "data": {...} }  or
 *                     { "success": false, "error": "..." }
 */

use WHMCS\Module\Addon\VpnHoodPartnerHub\ApiException;
use WHMCS\Module\Addon\VpnHoodPartnerHub\Auth;
use WHMCS\Module\Addon\VpnHoodPartnerHub\PartnerApiController;
use WHMCS\Module\Addon\VpnHoodPartnerHub\PartnerRepository;

// Bootstrap WHMCS (gives us Capsule, localAPI, models, etc.).
require_once __DIR__ . '/../../../init.php';

require_once __DIR__ . '/lib/ApiException.php';
require_once __DIR__ . '/lib/PartnerRepository.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/PartnerApiController.php';

header('Content-Type: application/json; charset=utf-8');

$repo = new PartnerRepository();
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$partner = null;
$action = '';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new ApiException('Only POST is supported.', 405);
    }

    $raw = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        throw new ApiException('Request body must be valid JSON.', 400);
    }

    $action = (string) ($body['action'] ?? '');
    if ($action === '') {
        throw new ApiException('Missing "action".', 400);
    }

    $headers = vpnhoodpartnerhub_normalizedHeaders();
    $auth = new Auth($repo);
    $partner = $auth->authenticate($headers, $remoteIp);

    $controller = new PartnerApiController($repo, $partner);
    $data = $controller->handle($action, $body);

    $repo->log((int) $partner['id'], $action, $remoteIp, 200, $body, $data);
    vpnhoodpartnerhub_respond(200, ['success' => true, 'data' => $data]);
} catch (ApiException $e) {
    $status = $e->getHttpStatus();
    $repo->log($partner['id'] ?? null, $action, $remoteIp, $status, $raw ?? null, $e->getMessage());
    vpnhoodpartnerhub_respond($status, ['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    logModuleCall('vpnhoodpartnerhub', 'api', $action, $e->getMessage(), $e->getTraceAsString());
    $repo->log($partner['id'] ?? null, $action, $remoteIp, 500, $raw ?? null, $e->getMessage());
    vpnhoodpartnerhub_respond(500, ['success' => false, 'error' => 'Internal error.']);
}

/**
 * Return lower-cased request headers regardless of SAPI.
 */
function vpnhoodpartnerhub_normalizedHeaders(): array
{
    $headers = [];
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            $headers[strtolower($name)] = $value;
        }
        return $headers;
    }
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = $value;
        }
    }
    return $headers;
}

function vpnhoodpartnerhub_respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}
