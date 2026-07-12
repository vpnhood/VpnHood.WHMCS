<?php
namespace WHMCS\Module\Server\VpnHoodStore;

use WHMCS\Module\Server\VpnHoodStore\ApiService;

class Helper {

    public const string DEFAULT_ACCESS_TOKEN_GROUP = 'None';

    /*** Create Access Token and save its ID to the service properties ***/
    public static function createAccessToken(array $params, array $createParams): void {
        $apiService = new ApiService();
        $response = $apiService->createAccessToken($createParams);
        $data = json_decode($response, true);

        // Check if data exists and is the expected array
        if (!isset($data[0]['accessTokenId'])) {
            throw new Exception("Invalid API Response: Missing Access Token ID");
        }

        $accessTokenId = $data[0]['accessTokenId'];

        // Save the accessTokenId to the service properties.
        $params['model']->serviceProperties->save(['accessTokenId' => $accessTokenId]);
    }

    /*** Create Access Token List ***/
    public static function createAccessTokenList(array $createParams): void {
        $apiService = new ApiService();
        $apiService->createAccessToken($createParams);
    }

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

    /*** Get Access Code from API ***/
    public static function getAccessCode(string $accessTokenId): void {
        $apiService = new ApiService();
        $response = $apiService->getAccessCode($accessTokenId);
        $jsonResult = json_decode($response);
        $accessCode = $jsonResult->accessToken->accessCode;
        echo $accessCode;
    }
    /*** Get Access Code as CSV file from API ***/
    public static function getAccessCodeCsvFile(string $customerId, string $orderId): void {
        $apiService = new ApiService();
        $response = $apiService->getAccessCodeCsvFile($customerId, $orderId);

        // Clear any previous output or headers
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Force the content type and bypass WHMCS default headers
        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="access_codes_' . $orderId . '.csv"');
        header('Access-Control-Expose-Headers: Content-Disposition');
        header('Content-Length: ' . strlen($response));
        header('X-Content-Type-Options: nosniff'); // Prevents browser from guessing the type
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo $response;
    }
}