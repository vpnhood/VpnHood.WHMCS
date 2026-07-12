<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/** * Even on latest WHMCS, manual require is the safest "fallback"
 * to ensure functions are available during Cron and API calls.
 */
require_once __DIR__ . '/lib/Helper.php';
require_once __DIR__ . '/lib/ApiService.php';

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
function vpnhoodstore_ConfigOptions(): array {
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
        $accessTokenGroupOptions[0] = Helper::DEFAULT_ACCESS_TOKEN_GROUP; // Add a default option for "None"
        foreach ($accessTokenGroups as $item) {
            $accessTokenGroupOptions[$item->accessTokenGroupId] = $item->accessTokenGroupName;
        }

        // Build options array for token delivery methods.
        $tokenDeliveryMethods = ["Normal", "CSV"];

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
                "Default" => "Normal",
            ],
            "accessTokenGroupsId" => [
                "FriendlyName" => "Access Token Groups",
                "Type" => "dropdown",
                "Options" => $accessTokenGroupOptions,
                "Description" => "Only work if Token Delivery Method is CSV.",
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

function vpnhoodstore_CreateAccount(array $params): string {
    try {
        $isNormalTokenDelivery = $params['configoption4'] == 0; //0 is normal and 1 is CSV for reseller
        $accessTokenGroupId = $params['configoption5'] === Helper::DEFAULT_ACCESS_TOKEN_GROUP ? null : $params['configoption5']; //Only set group id for CSV (Reseller)
        $count = (int)$params['qty'] ?? 1; //Quantity > 1 = CSV delivery in the client are.

        $isOneTimeProduct = $params['model']->product->paytype === "One Time"; //Check if the product is one time payment
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

        if ($isNormalTokenDelivery)
            Helper::createAccessToken($params, $createParams);
        else
            Helper::createAccessTokenList($createParams);

        return 'success';
    }
    catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall('vpnhoodstore', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return "VpnHoodStore Provisioning Failed: " . $e->getMessage();
    }
}


function vpnhoodstore_Renew(array $params): string {
    return Helper::renewOrUnsuspend($params);
}

function vpnhoodstore_SuspendAccount(array $params): string{
    return Helper::suspendOrTerminate($params);
}
function vpnhoodstore_UnsuspendAccount(array $params): string{
    return Helper::renewOrUnsuspend($params);
}

function vpnhoodstore_TerminateAccount(array $params): string{
    return Helper::suspendOrTerminate($params);
}

function vpnhoodstore_ClientArea(array $params): array {
    $isNormalTokenDelivery = $params['configoption4'] == 0; //0 is normal and 1 is CSV for reseller

    // Fetch the access code from the API through the AJAX request.
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        try {

            // Returns Access Code as Normal Token Delivery in the client area
            if ($isNormalTokenDelivery){
               $accessTokenId = $params['model']->serviceProperties->get('accessTokenId');
                Helper::getAccessCode($accessTokenId);
            }

            // Returns a download link as CSV Token Delivery for resellers in the client area.
            else{
                $customerId = (string)$params['userid'];
                $orderId = (string)$params['model']['orderid'];
                Helper::getAccessCodeCsvFile($customerId, $orderId);
            }

            exit;
        }
        catch (Exception $e) {

            // Create module log entry for the error.
            logModuleCall(
                'VpnHoodStore',
                'ClientArea',
                sprintf('Is Normal Token Delivery: %s', $isNormalTokenDelivery),
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

    return array('templatefile' => $isNormalTokenDelivery ? 'clientarea' : 'clientarea-reseller.tpl');
}