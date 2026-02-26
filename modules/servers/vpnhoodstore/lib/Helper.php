<?php
namespace WHMCS\Module\Server\VpnHoodStore;

use WHMCS\Module\Server\VpnHoodStore\ApiService;

class Helper {

    /*** Internal helper to update the token via API ***/
    public static function updateAccessToken(string $accessTokenId, string $expirationDate): void {
        $updateParams = [
            'expirationTime' => [
                'value' => $expirationDate
            ]
        ];

        $apiService = new ApiService();
        $apiService->updateAccessToken($accessTokenId, $updateParams);
    }

    /*** Handles Renewal and Un-suspension ***/
    public static function renewOrUnsuspend(array $params): string {
        try {
            $accessTokenId = $params['model']->serviceProperties->get('accessTokenId');
            $expirationDate = $params['model']['nextduedate'];

            // Call the static method using self::
            self::updateAccessToken($accessTokenId, $expirationDate);

            return 'success';
        } catch (\Exception $e) {
            logModuleCall('vpnhoodstore', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
            return "VpnHoodStore Error: " . $e->getMessage();
        }
    }

    /*** Handles Suspension and Termination ***/
    public static function suspendOrTerminate(array $params): string {
        try {
            $accessTokenId = $params['model']->serviceProperties->get('accessTokenId');

            // Prefix with \ for global PHP classes
            $date = new \DateTime('now', new \DateTimeZone('UTC'));
            $expirationDate = $date->format('Y-m-d');

            self::updateAccessToken($accessTokenId, $expirationDate);

            return 'success';
        } catch (\Exception $e) {
            logModuleCall('vpnhoodstore', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
            return "VpnHoodStore Error: " . $e->getMessage();
        }
    }
}