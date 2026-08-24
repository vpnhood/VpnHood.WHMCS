<?php
/**
 * bulk-order.test.php — the MERCHANT's bulk purchase, end to end, with no
 * partner in the chain (lifecycle §8: "a bulk order is stock, not service"):
 * a real qty>1 order on the hub's CSV product → one batch call provisions
 * every key at the access manager → the service carries the stock marks and
 * no single key → the CSV export (the exact call the client area's Download
 * button makes) returns every code of the batch.
 *
 * Complements bulk-guard.test.php, which owns the refuse-loudly lifecycle
 * checks; this test owns the BUYING experience: quantity, batch, CSV.
 *
 * ⚠ Creates BULK_QTY real tokens at the access manager. Cleanup NEUTRALIZES
 * them the way real termination does (expire now + disable) when the CSV
 * exposes their token ids; otherwise they stay behind on the dev project —
 * the same accepted footprint class as bulk-guard.test.php, said loudly.
 *
 * Env: BULK_QTY (default 2).
 */

require __DIR__ . '/lib/common.php';

const BULK_SLUG = 'reseller-bulk-csv-premium-code';
$bulkQty = max(2, (int) (getenv('BULK_QTY') ?: 2)); // qty 1 is bulk-guard's shape; the merchant shape starts at 2

$buyer = clientByEmail($db, BUYER_EMAIL);
if (!$buyer) {
    bad('fixtures missing — run tests/bootstrap/init-skeleton.sh first');
    finish();
}
$prod = one($db, "SELECT p.id FROM tblproducts p
    LEFT JOIN tblproducts_slugs s ON s.product_id=p.id AND s.active=1
    WHERE p.slug=? OR s.slug=? LIMIT 1", [BULK_SLUG, BULK_SLUG]);
if (!$prod) {
    bad('CSV fixture product missing — run tests/bootstrap/init-skeleton.sh first');
    finish();
}
ok("fixtures present (buyer #{$buyer['id']}, bulk product #{$prod['id']}, qty $bulkQty)");

// the module classes behind the client area's Download button
require_once WEBROOT . '/modules/servers/vpnhoodstore/lib/AsyncApiClientFactory.php';
require_once WEBROOT . '/modules/servers/vpnhoodstore/lib/ApiService.php';

$orderId = 0;
$serviceId = 0;
$invoiceId = 0;
$tokenIdsToNeutralize = [];

try {
    // -- 1. the merchant places a real qty>1 order ----------------------------
    $add = localAPI('AddOrder', [
        'clientid'       => $buyer['id'],
        'pid'            => [$prod['id']],
        'billingcycle'   => ['onetime'],
        'paymentmethod'  => 'banktransfer',
        'noemail'        => true,
        'noinvoiceemail' => true,
    ]);
    if (($add['result'] ?? '') !== 'success') {
        bad('AddOrder failed: ' . json_encode($add));
        finish();
    }
    $orderId = (int) $add['orderid'];
    $invoiceId = (int) ($add['invoiceid'] ?? 0);
    $serviceId = (int) explode(',', (string) ($add['productids'] ?? ''))[0];

    // The AddOrder API cannot carry a quantity — probed on this WHMCS (9.0.3):
    // qty [array], qty scalar and configqty are all ignored (service lands qty=1).
    // Quantity is a CART concept; the order form writes tblhosting.qty. The test
    // stamps it the same way the cart would, BEFORE autosetup runs — provisioning
    // then reads the real quantity exactly as it does for a cart order. This is
    // the one deliberate exception to the writes-go-through-localAPI rule here.
    $db->prepare('UPDATE tblhosting SET qty=? WHERE id=?')->execute([$bulkQty, $serviceId]);

    payInvoiceFromCredit($db, $invoiceId, (int) $buyer['id']);
    $accept = localAPI('AcceptOrder', ['orderid' => $orderId, 'autosetup' => true, 'sendemail' => false]);
    if (($accept['result'] ?? '') !== 'success') {
        bad('AcceptOrder failed: ' . json_encode($accept));
        finish();
    }

    $service = one($db, 'SELECT domainstatus, qty FROM tblhosting WHERE id=?', [$serviceId]);
    ($service['domainstatus'] ?? '?') === 'Active'
        ? ok("bulk order #$orderId / service #$serviceId Active through the CSV path")
        : bad('service not Active: ' . json_encode($service));
    (int) ($service['qty'] ?? 0) === $bulkQty
        ? ok("the service carries the real quantity ($bulkQty)")
        : bad("service qty is {$service['qty']}, expected $bulkQty — AddOrder dropped the quantity");

    // -- 2. the stock shape ----------------------------------------------------
    serviceProperty($db, $serviceId, 'bulkDelivery') === 'yes'
        ? ok('marked bulkDelivery=yes at the sale')
        : bad('no bulkDelivery mark');
    $singleTokenId = serviceProperty($db, $serviceId, 'accessTokenId');
    ($singleTokenId === null || $singleTokenId === '')
        ? ok('no single accessTokenId — a batch has no single key')
        : bad("a bulk sale recorded accessTokenId=$singleTokenId");
    in_array(serviceProperty($db, $serviceId, 'isDefaultKey'), [null, ''], true)
        ? ok('stock never becomes the default key')
        : bad('a bulk sale was marked as the default key');

    // -- 3. the CSV the merchant downloads (same call as the client area) -----
    $apiService = new \WHMCS\Module\Server\VpnHoodStore\ApiService();
    $csv = $apiService->getAccessCodeCsvFile((string) $buyer['id'], (string) $orderId);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $csv)), fn ($line) => $line !== ''));
    count($lines) >= 1 + $bulkQty
        ? ok('CSV export answered with a header + one row per key')
        : bad('CSV too short (' . count($lines) . ' line(s)): ' . substr($csv, 0, 200));

    $header = array_map('trim', str_getcsv($lines[0]));
    ok('CSV columns: ' . implode(' | ', $header));

    // the export's own column names (observed): AccessCode carries the dashed
    // code (1111-2222-…, 20 digits), AccessTokenId the manager's token id
    $codeColumn = array_search('AccessCode', $header, true);
    $idColumn = array_search('AccessTokenId', $header, true);
    $codeColumn !== false
        ? ok('the CSV names an AccessCode column')
        : bad('no AccessCode column in the export');

    $dataRows = array_slice($lines, 1);
    $codes = [];
    foreach ($dataRows as $row) {
        $cells = array_map('trim', str_getcsv($row));
        $code = $codeColumn !== false ? str_replace('-', '', (string) ($cells[$codeColumn] ?? '')) : '';
        if (preg_match('/^[0-9A-Za-z]{20}$/', $code)) {
            $codes[] = $code;
        }
        if ($idColumn !== false && ($cells[$idColumn] ?? '') !== '') {
            $tokenIdsToNeutralize[] = (string) $cells[$idColumn];
        }
    }
    $codes = array_unique($codes);
    count($codes) === $bulkQty
        ? ok("the CSV carries exactly $bulkQty distinct access codes — the whole batch, delivered once")
        : bad('expected ' . $bulkQty . ' distinct codes, found ' . count($codes));
} finally {
    // -- cleanup ---------------------------------------------------------------
    // Neutralize the batch tokens exactly the way real termination does
    // (expire now + disable) — ModuleTerminate itself refuses on bulk, by design.
    if ($tokenIdsToNeutralize !== []) {
        $neutralized = 0;
        $expireNow = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        foreach ($tokenIdsToNeutralize as $tokenId) {
            try {
                $apiService = $apiService ?? new \WHMCS\Module\Server\VpnHoodStore\ApiService();
                $apiService->updateAccessToken($tokenId, [
                    'expirationTime' => ['value' => $expireNow],
                    'isEnabled'      => ['value' => false],
                ]);
                $neutralized++;
            } catch (\Throwable $e) {
                // reported below by the count mismatch
            }
        }
        $neutralized === count($tokenIdsToNeutralize)
            ? ok("cleanup: all $neutralized batch tokens expired + disabled at the access manager")
            : ok("cleanup: only $neutralized/" . count($tokenIdsToNeutralize) . ' batch tokens neutralized — the rest stay on dev');
    } else {
        ok('cleanup: the CSV exposes no token ids — the batch stays on the dev project (bulk-guard footprint class)');
    }

    if ($orderId > 0) {
        $order = one($db, 'SELECT status FROM tblorders WHERE id=?', [$orderId]);
        if (($order['status'] ?? '') === 'Pending') {
            localAPI('CancelOrder', ['orderid' => $orderId, 'cancelsub' => false]);
        } else {
            $db->prepare("UPDATE tblorders SET status='Cancelled' WHERE id=?")->execute([$orderId]);
        }
        localAPI('DeleteOrder', ['orderid' => $orderId]);
    }
    if ($serviceId > 0) {
        $db->prepare('DELETE FROM tblhosting WHERE id=?')->execute([$serviceId]);
    }
    if ($invoiceId > 0) {
        $db->prepare('DELETE FROM tblaccounts WHERE invoiceid=?')->execute([$invoiceId]);
        $db->prepare('DELETE FROM tblinvoiceitems WHERE invoiceid=?')->execute([$invoiceId]);
        $db->prepare('DELETE FROM tblinvoices WHERE id=?')->execute([$invoiceId]);
    }
    ok('cleanup done (order/service/invoice removed; buyer credit consumed is topped back up by the bootstrap)');
}

finish();
