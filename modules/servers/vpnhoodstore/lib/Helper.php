<?php
namespace WHMCS\Module\Server\VpnHoodStore;

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\VpnHoodStore\ApiService;

class Helper {

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

        // Claim-by-code needs to find the service behind a pasted code, and this
        // install never persists codes — so store a one-way hash instead (MANAGER
        // cannot search by code; probed 2026-08-14). The hash opens nothing.
        $accessCode = (string)($data[0]['accessCode'] ?? '');
        if ($accessCode === '') {
            $codeJson = json_decode($apiService->getAccessCode($accessTokenId));
            $accessCode = (string)($codeJson->accessToken->accessCode ?? '');
        }
        if ($accessCode !== '')
            $params['model']->serviceProperties->save(['accessCodeHash' => hash('sha256', trim($accessCode))]);

        // The FIRST key a client buys becomes their default at purchase time
        // (lifecycle §8) — later purchases never steal that slot, and stock never
        // qualifies (this is the single-sale path only).
        self::markDefaultKeyIfFirst($params);
    }

    /**
     * Mark this service as the client's default key when no other active service
     * of theirs is marked. The default is what the app applies for the buyer
     * themselves, and what the store-purchase gate refuses on — a deliberate
     * remove/change in the app is what ever clears it.
     */
    public static function markDefaultKeyIfFirst(array $params): void {
        $clientId = (int)$params['userid'];
        $hasDefault = Capsule::table('tblhosting as h')
            ->join('tblcustomfieldsvalues as v', 'v.relid', '=', 'h.id')
            ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
            ->where('h.userid', $clientId)
            ->whereIn('h.domainstatus', ['Pending', 'Active', 'Suspended'])
            ->where('f.type', 'product')
            ->whereRaw("LOWER(SUBSTRING_INDEX(f.fieldname, '|', 1)) = 'isdefaultkey'")
            ->where('v.value', 'yes')
            ->exists();
        if (!$hasDefault)
            $params['model']->serviceProperties->save(['isDefaultKey' => 'yes']);
    }

    /*** Create Access Token List ***/
    public static function createAccessTokenList(array $createParams): void {
        $apiService = new ApiService();
        $apiService->createAccessToken($createParams);
    }

    /**
     * A bulk (CSV) order delivers its keys ONCE, as a batch, and stores no
     * accessTokenId — so no lifecycle action has a single token to act on. Acting
     * anyway used to mean PATCHing an EMPTY token id: nothing changed on the access
     * manager, the panel reported success, and every key in the batch kept working
     * behind a service marked Suspended. Decided behaviour (VpnHood repo,
     * docs/accounts/account-lifecycle.md §8): refuse loudly, name the batch, and
     * leave it to an administrator in VpnHood! MANAGER.
     *
     * Detection: the explicit bulkDelivery mark written at provisioning; services
     * sold before the mark existed are recognised by the same rule that routed them
     * to CSV delivery at purchase. An empty token id WITHOUT these signals is a
     * half-provisioned single sale — the callers refuse that separately, with its
     * own message, never as bulk.
     */
    public static function bulkDeliveryError(array $params, string $actionPast): ?string {
        $isBulk = $params['model']->serviceProperties->get('bulkDelivery') === 'yes'
            || self::isCsvTokenDelivery((int)$params['configoption4'], (int)($params['qty']), (int)$params['model']->product->allowqty);
        if (!$isBulk)
            return null;

        $error = "VpnHoodStore: bulk delivery — this service's keys were handed over as a CSV batch, "
            . "so there is no single key to be {$actionPast}. Nothing was changed. "
            . "Manage the batch in VpnHood! MANAGER: customerId={$params['userid']}, orderId={$params['model']['orderid']}, shop=WHMCS.";
        logModuleCall('vpnhoodstore', 'bulkDeliveryGuard', ['serviceId' => $params['serviceid'] ?? null, 'action' => $actionPast], $error);
        return $error;
    }

    /*** Internal helper to update the token via API ***/
    public static function updateAccessToken(string $accessTokenId, array $updateParams): void {
        $apiService = new ApiService();
        $apiService->updateAccessToken($accessTokenId, $updateParams);
    }

    /*** Handles Renewal ***/
    public static function renew(array $params): string {
        if (($bulkError = self::bulkDeliveryError($params, 'renewed')) !== null)
            return $bulkError;
        try {
            $accessTokenId = (string)$params['model']->serviceProperties->get('accessTokenId');
            if ($accessTokenId === '')
                return 'VpnHoodStore Error: no accessTokenId recorded on this service (provisioning incomplete?) — nothing was changed.';
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
        if (($bulkError = self::bulkDeliveryError($params, 'suspended')) !== null)
            return $bulkError;
        try {
            $accessTokenId = (string)$params['model']->serviceProperties->get('accessTokenId');
            if ($accessTokenId === '')
                return 'VpnHoodStore Error: no accessTokenId recorded on this service (provisioning incomplete?) — nothing was changed.';
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
        if (($bulkError = self::bulkDeliveryError($params, 'unsuspended')) !== null)
            return $bulkError;
        try {
            $accessTokenId = (string)$params['model']->serviceProperties->get('accessTokenId');
            if ($accessTokenId === '')
                return 'VpnHoodStore Error: no accessTokenId recorded on this service (provisioning incomplete?) — nothing was changed.';
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
        if (($bulkError = self::bulkDeliveryError($params, 'terminated')) !== null)
            return $bulkError;
        try {
            $accessTokenId = (string)$params['model']->serviceProperties->get('accessTokenId');
            if ($accessTokenId === '')
                return 'VpnHoodStore Error: no accessTokenId recorded on this service (provisioning incomplete?) — nothing was changed.';

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


    /**
     * Single source of truth for the delivery mode. CSV (bulk) delivery applies when the
     * product is explicitly configured for it ("Token Delivery Method" = CSV), or implicitly
     * for Scaling Service products (allowqty 2) ordered with more than one unit.
     * Also used by vpnhoodpartnerhub when reading back a provisioned key.
     *
     * @param int $deliveryType product configoption4: 0 = Normal, 1 = CSV
     * @param int $count        units ordered (service qty)
     * @param int $allowQty     product allowqty: 0 = No, 1 = Multiple Services, 2 = Scaling Service
     */
    public static function isCsvTokenDelivery(int $deliveryType, int $count, int $allowQty): bool {
        return $deliveryType === 1 || ($count > 1 && $allowQty === 2);
    }
}