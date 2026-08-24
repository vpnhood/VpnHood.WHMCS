<?php
/**
 * e2e-state.php — scenario driver for the browser (Playwright) tests. Runs ON
 * the dev box (uploaded by run-e2e.sh), prints one JSON line per invocation.
 *
 * Uses a DEDICATED client (e2e-buyer@vpnhood.test) so the browser tests can
 * never collide with the integration suites' shared buyer. Services for the
 * cart-notice scenarios are created WITHOUT autosetup (no access-manager
 * call — the notice only reads tblhosting), and the bulk scenario places a
 * real qty-2 CSV order whose batch tokens `clean` expires + disables again.
 *
 * Usage: E2E_CLIENT_PASSWORD=… php e2e-state.php <clean|onetime-key|renewing|bulk>
 */

require '/home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html/init.php';
require_once '/home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html/modules/servers/vpnhoodstore/lib/AsyncApiClientFactory.php';
require_once '/home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html/modules/servers/vpnhoodstore/lib/ApiService.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\VpnHoodStore\ApiService;

const E2E_EMAIL = 'e2e-buyer@vpnhood.test';
const PID_ONETIME = 15;   // reseller-one-month-premium-code (vpnhoodstore, onetime)
const PID_RECURRING = 16; // reseller-one-month-premium-code-subscription (vpnhoodstore, recurring)
const PID_BULK = 33;      // reseller-bulk-csv-premium-code (vpnhoodstore, CSV delivery)

function fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function api(string $command, array $params): array
{
    $result = localAPI($command, $params);
    if (($result['result'] ?? '') !== 'success') {
        fail("$command failed: " . json_encode($result));
    }
    return $result;
}

/** Place an order for the e2e client; returns [orderId, serviceId, invoiceId]. */
function placeOrder(int $clientId, int $productId, bool $autosetup): array
{
    $add = api('AddOrder', [
        'clientid'       => $clientId,
        'pid'            => [$productId],
        'billingcycle'   => ['onetime'],
        'paymentmethod'  => 'banktransfer',
        'noemail'        => true,
        'noinvoiceemail' => true,
    ]);
    $orderId = (int) $add['orderid'];
    $serviceId = (int) explode(',', (string) ($add['productids'] ?? ''))[0];
    $invoiceId = (int) ($add['invoiceid'] ?? 0);
    api('AcceptOrder', ['orderid' => $orderId, 'autosetup' => $autosetup, 'sendemail' => false]);
    if ($invoiceId > 0) {
        localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Cancelled']);
    }
    return [$orderId, $serviceId, $invoiceId];
}

/** Expire + disable the manager tokens behind a bulk service, via its own CSV export. */
function neutralizeBulkTokens(int $clientId, int $orderId): int
{
    try {
        $apiService = new ApiService();
        $csv = $apiService->getAccessCodeCsvFile((string) $clientId, (string) $orderId);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $csv)), fn ($l) => $l !== ''));
        if (count($lines) < 2) {
            return 0;
        }
        $header = array_map('trim', str_getcsv($lines[0]));
        $idColumn = array_search('AccessTokenId', $header, true);
        if ($idColumn === false) {
            return 0;
        }
        $expireNow = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d');
        $count = 0;
        foreach (array_slice($lines, 1) as $row) {
            $tokenId = trim((string) (str_getcsv($row)[$idColumn] ?? ''));
            if ($tokenId === '') {
                continue;
            }
            $apiService->updateAccessToken($tokenId, [
                'expirationTime' => ['value' => $expireNow],
                'isEnabled'      => ['value' => false],
            ]);
            $count++;
        }
        return $count;
    } catch (Throwable $e) {
        fwrite(STDERR, 'token neutralize skipped: ' . $e->getMessage() . "\n");
        return 0;
    }
}

$scenario = $argv[1] ?? '';

// -- the dedicated client ----------------------------------------------------
$clientId = (int) (Capsule::table('tblclients')->where('email', E2E_EMAIL)->value('id') ?? 0);
if ($clientId === 0) {
    $password = getenv('E2E_CLIENT_PASSWORD') ?: '';
    if ($password === '') {
        fail('E2E_CLIENT_PASSWORD is required to create the e2e client');
    }
    $result = api('AddClient', [
        'firstname'      => 'E2E',
        'lastname'       => 'Browser',
        'email'          => E2E_EMAIL,
        'password2'      => $password,
        'country'        => 'US',
        'skipvalidation' => true,
        'noemail'        => true,
    ]);
    $clientId = (int) $result['clientid'];
}

switch ($scenario) {
    case 'clean':
        $services = Capsule::table('tblhosting')->where('userid', $clientId)->get(['id', 'orderid']);
        foreach ($services as $service) {
            $isBulk = Capsule::table('tblcustomfieldsvalues as v')
                ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
                ->where('v.relid', (int) $service->id)
                ->where('f.fieldname', 'like', 'bulkDelivery%')
                ->where('v.value', 'yes')->exists();
            if ($isBulk) {
                neutralizeBulkTokens($clientId, (int) $service->orderid);
            } else {
                localAPI('ModuleTerminate', ['serviceid' => (int) $service->id]); // best-effort
            }
            Capsule::table('tblhosting')->where('id', (int) $service->id)->delete();
        }
        $orderIds = Capsule::table('tblorders')->where('userid', $clientId)->pluck('id')->all();
        foreach ($orderIds as $orderId) {
            localAPI('CancelOrder', ['orderid' => (int) $orderId, 'cancelsub' => false]);
            localAPI('DeleteOrder', ['orderid' => (int) $orderId]);
        }
        $invoiceIds = Capsule::table('tblinvoices')->where('userid', $clientId)->pluck('id')->all();
        foreach ($invoiceIds as $invoiceId) {
            Capsule::table('tblaccounts')->where('invoiceid', (int) $invoiceId)->delete();
            Capsule::table('tblinvoiceitems')->where('invoiceid', (int) $invoiceId)->delete();
            Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->delete();
        }
        echo json_encode(['clientId' => $clientId, 'cleaned' => count($services)]) . "\n";
        break;

    case 'onetime-key':
        [, $serviceId] = placeOrder($clientId, PID_ONETIME, autosetup: false);
        api('UpdateClientProduct', ['serviceid' => $serviceId, 'status' => 'Active']);
        echo json_encode(['clientId' => $clientId, 'serviceId' => $serviceId]) . "\n";
        break;

    case 'renewing':
        [, $serviceId] = placeOrder($clientId, PID_RECURRING, autosetup: false);
        api('UpdateClientProduct', [
            'serviceid'   => $serviceId,
            'status'      => 'Active',
            'nextduedate' => date('Y-m-d', strtotime('+20 days')),
        ]);
        echo json_encode(['clientId' => $clientId, 'serviceId' => $serviceId]) . "\n";
        break;

    case 'bulk':
        [$orderId, $serviceId] = placeOrder($clientId, PID_BULK, autosetup: false);
        // quantity is a cart concept (the AddOrder API ignores qty — probed);
        // stamp it the way the cart would, then provision the batch for real
        Capsule::table('tblhosting')->where('id', $serviceId)->update(['qty' => 2]);
        $module = localAPI('ModuleCreate', ['serviceid' => $serviceId]);
        if (($module['result'] ?? '') !== 'success') {
            fail('ModuleCreate failed: ' . json_encode($module));
        }
        api('UpdateClientProduct', ['serviceid' => $serviceId, 'status' => 'Active']);
        echo json_encode(['clientId' => $clientId, 'serviceId' => $serviceId, 'orderId' => $orderId]) . "\n";
        break;

    default:
        fail('unknown scenario: ' . $scenario);
}
