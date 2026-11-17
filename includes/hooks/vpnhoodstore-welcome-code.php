<?php

/**
 * VpnHood Store — the purchase email carries the code (lifecycle §5, §7).
 *
 * A website buyer's way back to what they bought, after deleting their account or on a device that
 * never saw it, is the copy the purchase delivered to their inbox. So the welcome email of a
 * vpnhoodstore product gets the code as the merge field {$premium_code}, read from the access
 * manager as the mail goes out; the code itself is never stored here. It is written as the app
 * shows it (AccessCodeUtils.Format: a dash after every four characters); the app drops the dashes
 * when it reads one. The template shows it inside {if $premium_code}...{/if}, on its own line and
 * left to right, so a right-to-left mail cannot reorder its groups.
 *
 * A bulk (CSV) service has no single code and gets none: its codes are downloaded from the client
 * area. A mail whose code cannot be read still goes out, without it, and the failure is logged:
 * holding back the purchase mail would leave the buyer with less, and the client area shows the code
 * too.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\VpnHoodStore\ApiService;
use WHMCS\Module\Server\VpnHoodStore\Helper;

/** The code of a single-code vpnhoodstore service whose welcome email this is; null for any other mail. */
function vpnhoodstore_welcomeCode(string $messageName, int $serviceId): ?string
{
    if ($serviceId <= 0 || $messageName === '') {
        return null;
    }
    $service = Capsule::table('tblhosting as h')
        ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
        ->join('tblemailtemplates as t', 't.id', '=', 'p.welcomeemail')
        ->where('h.id', $serviceId)
        ->where('p.servertype', 'vpnhoodstore')
        ->where('t.type', 'product')
        ->where('t.name', $messageName)
        ->first(['h.qty', 'p.configoption4', 'p.allowqty']);
    if ($service === null) {
        return null;
    }

    $storeLib = ROOTDIR . '/modules/servers/vpnhoodstore/lib';
    require_once $storeLib . '/AsyncApiClientFactory.php';
    require_once $storeLib . '/ApiService.php';
    require_once $storeLib . '/Helper.php';

    $properties = \WHMCS\Service\Service::findOrFail($serviceId)->serviceProperties;
    if ($properties->get('bulkDelivery') === 'yes'
        || Helper::isCsvTokenDelivery((int) $service->configoption4, (int) $service->qty, (int) $service->allowqty)) {
        return null;
    }
    $accessTokenId = (string) $properties->get('accessTokenId');
    if ($accessTokenId === '') {
        throw new RuntimeException("service #$serviceId has no accessTokenId");
    }
    $json = json_decode((new ApiService())->getAccessCode($accessTokenId));
    $code = preg_replace('/[^A-Za-z0-9]/', '', (string) ($json->accessToken->accessCode ?? ''));
    if ($code === '') {
        throw new RuntimeException("the access manager returned no code for service #$serviceId");
    }
    return implode('-', str_split($code, 4));
}

add_hook('EmailPreSend', 1, function (array $vars) {
    $messageName = (string) ($vars['messagename'] ?? '');
    $serviceId = (int) ($vars['relid'] ?? 0);
    try {
        $code = vpnhoodstore_welcomeCode($messageName, $serviceId);
        return $code === null ? [] : ['premium_code' => $code];
    } catch (Throwable $e) {
        logModuleCall('vpnhoodstore', 'hook.welcome-code', ['message' => $messageName, 'serviceId' => $serviceId], $e->getMessage(), '');
        logActivity("VpnHood Store: the welcome email of service #$serviceId went out without its code: " . $e->getMessage());
        return [];
    }
});
