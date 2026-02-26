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

function vpnhoodstore_ConfigOptions(): array {
    try {
        // Fetch the access code from the API.
        $apiService = new ApiService();
        $serverFarms = $apiService->getServerFarms();
        $accessTokenProfiles = $apiService->getAccessTokenProfiles();

        // Build options array from server farms
        $serverFarmOptions = [];
        foreach ($serverFarms as $item) {
            $serverFarmOptions[$item->serverFarm->serverFarmId] = $item->serverFarm->serverFarmName;
        }

        // Build options array from server farms
        $accessTokenProfileOptions = [];
        foreach ($accessTokenProfiles as $item) {
            $accessTokenProfileOptions[$item->accessTokenProfileId] = $item->accessTokenProfileName;
        }

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
                "Default" => "Premium Code",
            ],
            "accessTokenProfileId" => [
                "FriendlyName" => "Access Token Profile",
                "Type" => "dropdown",
                "Options" => $accessTokenProfileOptions,
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
        $apiService = new ApiService();

        $createParams = [
            'accessTokenProfileId' => $params['configoption3'],
            'serverFarmId'         => $params['configoption1'],
            'accessTokenName'      => $params['configoption2'],
            'count'                => 1,
            'customerId'           => (string)$params['userid'],
            'expirationTime'       => $params['model']['nextduedate'],
            'orderId'              => (string)$params['serviceid'],
            'shopId'               => 'WHMCS'
        ];

        $response = $apiService->createAccessToken($createParams);
        $data = json_decode($response, true);

        // Check if data exists and is the expected array
        if (!isset($data[0]['accessTokenId'])) {
            throw new Exception("Invalid API Response: Missing Access Token ID");
        }

        $accessTokenId = $data[0]['accessTokenId'];

        // Save the accessTokenId to the service properties.
        $params['model']->serviceProperties->save(['accessTokenId' => $accessTokenId]);
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
    $accessTokenId = null;

    // Fetch the access code from the API through the AJAX request.
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        try {
            $accessTokenId = $params['model']->serviceProperties->get('accessTokenId');

            // Fetch the access code from the API.
            $apiService = new ApiService();
            $response = $apiService->getAccessCode($accessTokenId);
            $jsonResult = json_decode($response);
            $accessCode = $jsonResult->accessToken->accessCode;
            echo $accessCode;
            exit;
        }
        catch (Exception $e) {

            // Create module log entry for the error.
            logModuleCall(
                'VpnHoodStore',
                'ClientArea',
                sprintf('Access Token ID: %s', $accessTokenId),
                sprintf('Error: %s', $e->getMessage())
            );

            // In an error condition, display an error page.
            return array(
                'tabOverviewReplacementTemplate' => 'error.tpl',
                'templateVariables' => array(
                    'usefulErrorHelper' => 'Error fetching access code: ' . $e->getMessage(),
                ),
            );
        }
    }

    return array(
        'templatefile' => 'clientarea',
    );
}


