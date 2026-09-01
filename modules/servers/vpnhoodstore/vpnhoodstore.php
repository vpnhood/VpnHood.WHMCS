<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/** * Even on latest WHMCS, manual require is the safest "fallback"
 * to ensure functions are available during Cron and API calls.
 */
require_once __DIR__ . '/lib/Helper.php';
require_once __DIR__ . '/lib/ApiService.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\VpnHoodStore\ApiService;
use WHMCS\Module\Server\VpnHoodStore\Helper;

function vpnhoodstore_MetaData(): array {
    return array(
        'DisplayName' => 'VpnHood Store Integration',
        'APIVersion' => '1.0.0', // Use API Version 1.1
        'RequiresServer' => false, // Set true if the module requires a server to work
    );
}

/*** Fetch the access code options from the API for product settings in the admin area. ***/
function vpnhoodstore_ConfigOptions(array $params = []): array {
    try {
        $apiService = new ApiService();
        $serverFarms = $apiService->getServerFarms();
        $accessTokenProfiles = $apiService->getAccessTokenProfiles();
        $accessTokenGroups = $apiService->getAccessTokenGroups();

        // Build options array from server farms.
        $serverFarmOptions = [];
        foreach ($serverFarms as $item) {
            $serverFarmOptions[$item->serverFarm->serverFarmId] = $item->serverFarm->serverFarmName;
        }

        // Build options array from access token profiles.
        $accessTokenProfileOptions = [];
        foreach ($accessTokenProfiles as $item) {
            $accessTokenProfileOptions[$item->accessTokenProfileId] = $item->accessTokenProfileName;
        }

        // Build options array from access token groups.
        $accessTokenGroupOptions = [];
        $accessTokenGroupOptions[0] = 'None'; // Add a default option for "None"
        foreach ($accessTokenGroups as $item) {
            $accessTokenGroupOptions[$item->accessTokenGroupId] = $item->accessTokenGroupName;
        }

        // Build options array for token delivery methods. Keys are explicit because WHMCS
        // stores the *key* in configoptionN, and Helper::isCsvTokenDelivery() compares on it.
        $tokenDeliveryMethods = [0 => "Normal", 1 => "CSV"];

        // Reseller (Scaling Service) heads-up, folded into a real field's description so it
        // renders reliably (a separate 'none' field is not always shown by WHMCS).
        $scalingNotice = vpnhoodstore_scalingServiceNotice($params);

        return [
            "serverFarmId" => [
                "FriendlyName" => "Server Farm",
                "Type" => "dropdown",
                "Options" => $serverFarmOptions,
                "Default" => "",
            ],
            "accessTokenName" => [
                "FriendlyName" => "Access Token Name",
                "Type" => "text",
                "Size" => "30",
                "Description" => "The name you want to use to create tokens.",
                "Default" => "VpnHood Official",
            ],
            "accessTokenProfilesId" => [
                "FriendlyName" => "Access Token Profile",
                "Type" => "dropdown",
                "Options" => $accessTokenProfileOptions,
                "Default" => "",
            ],
            "tokenDelivery" => [
                "FriendlyName" => "Token Delivery Method",
                "Type" => "dropdown",
                "Options" => $tokenDeliveryMethods,
                "Description" => "The CSV is for the resellers and returns a download link in the client area.",
                "Default" => 0,
            ],
            "accessTokenGroupsId" => [
                "FriendlyName" => "Access Token Groups",
                "Type" => "dropdown",
                "Options" => $accessTokenGroupOptions,
                "Description" => "Optional (If this product is for resellers only, it is better to select the Reseller option so that it can be separated in the Manager panel.)" . $scalingNotice,
                "Default" => "",
            ],
        ];
    }
    catch (Exception $e) {
        logModuleCall('vpnhoodstore', __FUNCTION__, $e->getMessage(), $e->getTraceAsString());
        return [
            "error" => [
                "FriendlyName" => "VpnHoodStore ConfigOption Error",
                "Type" => "none",
                "Description" => "<div class='alert alert-danger' style='margin-bottom: 0;'>No Config found in the <b>System Settings > Addon Modules > VpnHood! MANAGER Configuration</b></div>",
            ],
        ];
    }
}

/** Render a Bootstrap alert box for a product-config notice. */
function vpnhoodstore_configAlert(string $level, string $html): string {
    return "<div class='alert alert-{$level}' style='margin-top:8px;margin-bottom:0;'>" . $html . '</div>';
}

/**
 * Config-time notice shown on the product edit page when "Allow Multiple Quantities" is set to
 * Scaling Service (allowqty = 2) — the reseller / CSV bulk-delivery mode.
 *
 * WHMCS does not reliably pass 'pid' into _ConfigOptions on the product edit page; the product
 * id is in the request there (configproducts.php?action=edit&id=X).
 */
function vpnhoodstore_scalingServiceNotice(array $params): string {
    $pid = (int) ($params['pid'] ?? ($_REQUEST['id'] ?? 0));
    if ($pid <= 0) {
        return '';
    }

    $allowQty = (int) Capsule::table('tblproducts')->where('id', $pid)->value('allowqty');
    if ($allowQty !== 2) { // 2 = Scaling Service
        return '';
    }

    return vpnhoodstore_configAlert(
        'danger',
        'Note: You have selected "Allow Multiple Quantities" as "Scaling Service" in the Pricing tab; this option is for resellers.'
    );
}

function vpnhoodstore_CreateAccount(array $params): string {
    try {
        $accessTokenGroupId = (int)$params['configoption5'] === 0 ? null : $params['configoption5']; // "None" is key 0 (falsy) -> no group
        $count = (int)($params['qty']);

        $isOneTimeProduct = $params['model']->product->paytype === "onetime"; //Check if the product is one time payment
        $expirationTime = $isOneTimeProduct ? null : $params['model']['nextduedate']; //Set expiration time for recurring products only

        // Access Token create params
        $createParams = [
            'accessTokenProfileId' => $params['configoption3'],
            'serverFarmId'         => $params['configoption1'],
            'accessTokenGroupId'   => $accessTokenGroupId,
            'accessTokenName'      => $params['configoption2'],
            'count'                => $count,
            'customerId'           => (string)$params['userid'],
            'expirationTime'       => $expirationTime,
            'orderId'              => (string)$params['model']['orderid'],
            'shopId'               => 'WHMCS'
        ];

        if (Helper::isCsvTokenDelivery((int)$params['configoption4'], $count, (int)$params['model']->product->allowqty)) {
            Helper::createAccessTokenList($createParams);
            // bulk = stock: no single token exists behind this service, and every
            // lifecycle action must learn that from an explicit mark rather than
            // from a missing id (a half-failed single sale also has no id).
            $params['model']->serviceProperties->save(['bulkDelivery' => 'yes']);
        }
        else
            Helper::createAccessToken($params, $createParams);

        return 'success';
    }
    catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall('vpnhoodstore', __FUNCTION__, $e->getMessage(), $e->getTraceAsString());
        return "VpnHoodStore Provisioning Failed: " . $e->getMessage();
    }
}


function vpnhoodstore_Renew(array $params): string {
    return Helper::renew($params);
}

function vpnhoodstore_SuspendAccount(array $params): string{
    return Helper::suspend($params);
}
function vpnhoodstore_UnsuspendAccount(array $params): string{
    return Helper::unsuspend($params);
}

function vpnhoodstore_TerminateAccount(array $params): string{
    return Helper::termination($params);
}

function vpnhoodstore_ClientArea(array $params): array {
    $isCsvTokenDelivery = Helper::isCsvTokenDelivery((int)$params['configoption4'], (int)($params['qty']), (int)$params['model']->product->allowqty);

    // Fetch the access code from the API through the AJAX request.
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        try {

            // Returns a download link as CSV Token Delivery for resellers in the client area.
            if ($isCsvTokenDelivery) {
                $customerId = (string)$params['userid'];
                $orderId = (string)$params['model']['orderid'];
                Helper::getAccessCodeCsvFile($customerId, $orderId);
            }

            // Returns Access Code as Normal Token Delivery in the client area.
            else{
                $accessTokenId = $params['model']->serviceProperties->get('accessTokenId');
                Helper::getAccessCode($accessTokenId);
            }

            exit;
        }
        catch (Exception $e) {

            // Create module log entry for the error.
            logModuleCall(
                'VpnHoodStore',
                'ClientArea',
                sprintf('Is CSV Token Delivery: %s', $isCsvTokenDelivery ? 'yes' : 'no'),
                sprintf('Error: %s', $e->getMessage())
            );

            // In an error condition, display an error page.
            return array(
                'tabOverviewReplacementTemplate' => 'error.tpl',
                'templateVariables' => array(
                    'usefulErrorHelper' => 'Error fetching access code or download link: ' . $e->getMessage(),
                ),
            );
        }
    }

    return array(
        'templatefile' => $isCsvTokenDelivery ? 'clientarea-reseller.tpl' : 'clientarea.tpl',
        'templateVariables' => vpnhoodstore_storeBadgeVars($params)
            + array('partnerOrderId' => vpnhoodstore_partnerOrderId($params)),
    );
}

/**
 * Store-purchase badge for the client area.
 *
 * A service sold through an app store carries a 'purchasedVia' service property,
 * written by the vpnhoodiap addon at provisioning time (the property NAME is the
 * whole contract between the two — this module never calls into that addon, and
 * works unchanged on installs where it is not present). Everything else on the
 * install has no such property, gets an empty label, and renders no badge.
 *
 * It matters because the money never moved here: the customer paid Google/Apple/
 * Microsoft, renewals are charged there, and cancellation or refund can only be
 * done there. Saying so on the page is what prevents a ticket asking us to refund
 * a charge we never took.
 */
function vpnhoodstore_storeBadgeVars(array $params): array {
    try {
        $store = (string) $params['model']->serviceProperties->get('purchasedVia');
    } catch (Throwable $e) {
        $store = '';
    }

    return array(
        'purchasedVia'      => $store,
        'purchasedViaLabel' => vpnhoodstore_storeLabel($store),
    );
}

/** Store id → the name a customer knows it by. '' when this was not a store purchase. */
function vpnhoodstore_storeLabel(string $store): string {
    return match ($store) {
        'googleplay' => 'Google Play',
        'appstore'   => 'the Apple App Store',
        'microsoft'  => 'the Microsoft Store',
        default      => '',
    };
}

/**
 * The WHMCS ORDER id behind this service — but only for a partner-hub client.
 *
 * A partner drives these keys through the Hub API, where the handle is the order id,
 * while every client-area link addresses the SERVICE id. Both are dense sequences over
 * different tables, so the same number is live in both for different customers: a partner
 * read `502` off this page, sent it as `upstreamOrderId`, and it was another customer's
 * order (2026-09-01). Naming the order id next to the key is what stops that.
 *
 * Empty for everyone else — retail buyers never call the API — and empty on installs
 * without the Hub addon, which is why the partner table is probed before it is read: this
 * module ships standalone and must not assume the addon is present.
 */
function vpnhoodstore_partnerOrderId(array $params): string {
    try {
        if (!Capsule::schema()->hasTable('mod_vpnhood_partners'))
            return '';

        $isPartner = Capsule::table('mod_vpnhood_partners')
            ->where('client_id', (int)$params['userid'])
            ->exists();

        return $isPartner ? (string)($params['model']['orderid'] ?? '') : '';
    }
    catch (Throwable $e) {
        return '';
    }
}

/**
 * Admin service page: show which store sold this service, so an admin reading the
 * order sees what the customer sees without opening the IAP addon, plus — for partner
 * clients — the order id their API calls must carry, so support can answer "which id?"
 * from the page it is being asked about. Read-only — the purchase and order records own
 * these values, neither is editable here.
 */
function vpnhoodstore_AdminServicesTabFields(array $params): array {
    $fields = array();

    $label = vpnhoodstore_storeBadgeVars($params)['purchasedViaLabel'];
    if ($label !== '') {
        $fields['Purchased via'] = htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . ' <em>(billed by the store — cancellations and refunds happen there)</em>';
    }

    $partnerOrderId = vpnhoodstore_partnerOrderId($params);
    if ($partnerOrderId !== '') {
        $fields['Partner API order id'] = '#' . htmlspecialchars($partnerOrderId, ENT_QUOTES, 'UTF-8')
            . ' <em>(the <code>upstreamOrderId</code> this partner must send — not the service id in the page address)</em>';
    }

    return $fields;
}