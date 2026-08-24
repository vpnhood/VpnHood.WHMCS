<?php
/**
 * bulk-guard.test.php — a bulk (CSV) order is STOCK, and the lifecycle hooks
 * must refuse it loudly instead of PATCHing an empty token id (lifecycle §8,
 * account-lifecycle.md; defect D4). Verifies V4 along the way: a module error
 * leaves the WHMCS service status untouched.
 *
 * Flow: order the CSV fixture product for the test buyer (qty 1 — CSV delivery
 * alone makes it bulk) → provisioned service carries bulkDelivery=yes and NO
 * accessTokenId → ModuleSuspend and ModuleTerminate answer the bulk error,
 * naming the batch, and the service stays Active.
 *
 * ⚠ createAccessTokenList creates ONE real token at the access manager that
 * nothing records (that is the point of the defect) — it stays behind on the
 * dev project. Same accepted footprint class as purchase-order.test.php.
 */

require __DIR__ . '/lib/common.php';

const BULK_SLUG = 'reseller-bulk-csv-premium-code';

$buyer = clientByEmail($db, BUYER_EMAIL);
if (!$buyer) {
    bad('fixtures missing — run tests/bootstrap/init-skeleton.sh first');
    finish();
}
$prod = one($db, "SELECT p.id FROM tblproducts p
    LEFT JOIN tblproducts_slugs s ON s.product_id=p.id AND s.active=1
    WHERE p.slug=? OR s.slug=? LIMIT 1", [BULK_SLUG, BULK_SLUG]);
if (!$prod) {
    bad('CSV fixture product missing — run tests/bootstrap/init-skeleton.sh (fixtures.json gained it)');
    finish();
}
ok("fixtures present (buyer #{$buyer['id']}, bulk product #{$prod['id']})");

$orderId = 0;
$serviceId = 0;
$invoiceId = 0;

try {
    $add = localAPI('AddOrder', [
        'clientid'       => $buyer['id'],
        'pid'            => $prod['id'],
        'billingcycle'   => 'onetime',
        'paymentmethod'  => 'banktransfer',
        'noemail'        => true,
        'noinvoiceemail' => true,
    ]);
    if (($add['result'] ?? '') !== 'success') {
        bad('AddOrder failed: ' . json_encode($add));
        finish();
    }
    $orderId = (int)$add['orderid'];
    $invoiceId = (int)($add['invoiceid'] ?? 0);
    $serviceId = (int) explode(',', (string)($add['productids'] ?? ''))[0];

    payInvoiceFromCredit($db, $invoiceId, (int)$buyer['id']);
    $accept = localAPI('AcceptOrder', ['orderid' => $orderId, 'autosetup' => true, 'sendemail' => false]);
    if (($accept['result'] ?? '') !== 'success') {
        bad('AcceptOrder failed: ' . json_encode($accept));
        finish();
    }
    ok("bulk order #$orderId / service #$serviceId provisioned through the CSV path");

    // -- the stock shape ------------------------------------------------------
    serviceProperty($db, $serviceId, 'bulkDelivery') === 'yes'
        ? ok('the service is marked bulkDelivery=yes at the sale')
        : bad('no bulkDelivery mark on a CSV sale');
    $tokenId = serviceProperty($db, $serviceId, 'accessTokenId');
    ($tokenId === null || $tokenId === '')
        ? ok('no single accessTokenId recorded — a batch has no single key')
        : bad("a CSV sale recorded accessTokenId=$tokenId");
    serviceProperty($db, $serviceId, 'isDefaultKey') === null
        ? ok('stock never becomes the default key')
        : bad('a bulk sale was marked as the default key');

    // -- lifecycle refuses loudly, and the status stays honest (V4) -----------
    $suspend = localAPI('ModuleSuspend', ['serviceid' => $serviceId, 'suspendreason' => 'bulk-guard test']);
    (($suspend['result'] ?? '') !== 'success'
        && stripos((string)($suspend['message'] ?? ''), 'bulk delivery') !== false
        && stripos((string)($suspend['message'] ?? ''), "orderId=") !== false)
        ? ok('ModuleSuspend refuses with the bulk error, naming the batch')
        : bad('ModuleSuspend answered: ' . json_encode($suspend));

    $status = one($db, 'SELECT domainstatus FROM tblhosting WHERE id=?', [$serviceId])['domainstatus'] ?? '?';
    $status === 'Active'
        ? ok('the service status is UNTOUCHED after the refusal (V4: no false Suspended)')
        : bad("service status changed to $status after a refused suspend");

    $terminate = localAPI('ModuleTerminate', ['serviceid' => $serviceId]);
    (($terminate['result'] ?? '') !== 'success'
        && stripos((string)($terminate['message'] ?? ''), 'bulk delivery') !== false)
        ? ok('ModuleTerminate refuses with the bulk error too')
        : bad('ModuleTerminate answered: ' . json_encode($terminate));

    (one($db, 'SELECT domainstatus FROM tblhosting WHERE id=?', [$serviceId])['domainstatus'] ?? '?') === 'Active'
        ? ok('still Active after the refused terminate — an administrator acts in MANAGER, not the panel')
        : bad('service status changed after a refused terminate');
} finally {
    // -- cleanup (the WHMCS side; the batch token stays on dev, see header) ---
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
    ok('cleanup done (order/service/invoice removed; ~2 USD of buyer credit consumed — the bootstrap tops it up)');
}

finish();
