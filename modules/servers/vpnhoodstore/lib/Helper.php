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
    public static function updateAccessToken(string $accessTokenId, array $updateParams): void {
        $apiService = new ApiService();
        $apiService->updateAccessToken($accessTokenId, $updateParams);
    }

    /*** Handles Renewal ***/
    public static function renew(array $params): string {
        try {
            $accessTokenId = $params['model']->serviceProperties->get('accessTokenId');
            $expirationDate = $params['model']['nextduedate'];
            $updateParams = [
                'expirationTime' => [
                    'value' => $expirationDate
                ]
            ];

            // Call the static method using self::
            self::updateAccessToken($accessTokenId, $updateParams);

            return 'success';
        } catch (\Exception $e) {
            logModuleCall('vpnhoodstore', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
            return "VpnHoodStore Error: " . $e->getMessage();
        }
    }

    /*** Handles Suspension ***/
    public static function suspend(array $params): string {
        try {
            $accessTokenId = $params['model']->serviceProperties->get('accessTokenId');
            $updateParams = [
                'isEnabled' => [
                    'value' => false
                ]
            ];

            self::updateAccessToken($accessTokenId, $updateParams);

            return 'success';
        } catch (\Exception $e) {
            logModuleCall('vpnhoodstore', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
            return "VpnHoodStore Error: " . $e->getMessage();
        }
    }

    /*** Handles Un-suspension ***/
    public static function unsuspend(array $params): string {
        try {
            $accessTokenId = $params['model']->serviceProperties->get('accessTokenId');
            $updateParams = [
                'isEnabled' => [
                    'value' => true
                ]
            ];

            // Call the static method using self::
            self::updateAccessToken($accessTokenId, $updateParams);

            return 'success';
        } catch (\Exception $e) {
            logModuleCall('vpnhoodstore', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
            return "VpnHoodStore Error: " . $e->getMessage();
        }
    }

    /*** Handles Termination ***/
    public static function termination(array $params): string {
        try {
            $accessTokenId = $params['model']->serviceProperties->get('accessTokenId');

            // Expire as of now, not nextduedate: nextduedate is unset ('0000-00-00', not a
            // valid date) for one-time products, and even when set (recurring), it's the
            // *next scheduled billing date* — using it here would leave the token valid
            // until then instead of actually terminating access immediately.
            $expirationDate = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');

            $updateParams = [
                'expirationTime' => [
                    'value' => $expirationDate
                ],
                'isEnabled' => [
                    'value' => false
                ]
            ];

            self::updateAccessToken($accessTokenId, $updateParams);

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

    public static function isCsvTokenDelivery(array $params): bool {
        return self::isCsvTokenDeliveryFor(
            (int)($params['qty']),
            (int) $params['model']->product->allowqty
        );
    }

    /**
     * Single source of truth for the delivery mode: CSV (bulk) delivery applies only
     * to Scaling Service products (allowqty 2) ordered with more than one unit.
     * Also used by vpnhoodpartnerhub when reading back a provisioned key.
     */
    public static function isCsvTokenDeliveryFor(int $count, int $allowQty): bool {
        // allowqty: 0 = No, 1 = Multiple Services, 2 = Scaling Service
        return $count > 1 && $allowQty === 2;
    }
}