<?php
/**
 * default-key.test.php — the provisioning-time account-key marks and the
 * refund-revokes-by-default hook (lifecycle §8), on real vpnhoodstore orders:
 *
 *   1. the buyer's FIRST key gets isDefaultKey=yes + accessCodeHash; a SECOND
 *      purchase never steals the default slot;
 *   2. the InvoiceRefunded hook terminates a FULLY refunded service by default —
 *      leaves a partially refunded one running, and deliberately keeps even a
 *      fully refunded one when keepOnRefund=yes is set.
 *
 * ⚠ Places TWO real orders (real tokens at the access manager); both are
 * terminated and deleted in cleanup.
 */

require __DIR__ . '/lib/common.php';

const KEY_SLUG = 'reseller-one-month-premium-code';

$buyer = clientByEmail($db, BUYER_EMAIL);
if (!$buyer) {
    bad('fixtures missing — run tests/bootstrap/init-skeleton.sh first');
    finish();
}
$prod = one($db, "SELECT p.id FROM tblproducts p
    LEFT JOIN tblproducts_slugs s ON s.product_id=p.id AND s.active=1
    WHERE p.slug=? OR s.slug=? LIMIT 1", [KEY_SLUG, KEY_SLUG]);
if (!$prod) {
    bad('fixture product missing — run tests/bootstrap/init-skeleton.sh first');
    finish();
}

// The buyer must start with NO active default: earlier suites (purchase-order &
// friends) run against the partner connector, whose services also honour the
// default mark — so this test wipes the buyer's services first, exactly like
// purchase-order.test.php does, and is therefore ordered before/after them by
// the runner, never concurrently.
$stale = $db->prepare("SELECT id FROM tblhosting WHERE userid=? AND domainstatus IN ('Active','Suspended')");
$stale->execute([$buyer['id']]);
foreach ($stale->fetchAll(PDO::FETCH_ASSOC) as $row) {
    localAPI('ModuleTerminate', ['serviceid' => (int)$row['id']]);
    $db->prepare("UPDATE tblhosting SET domainstatus='Terminated' WHERE id=?")->execute([(int)$row['id']]);
}

$orders = [];

function placeKeyOrder(PDO $db, int $clientId, int $productId): array
{
    $add = localAPI('AddOrder', [
        'clientid' => $clientId, 'pid' => $productId, 'billingcycle' => 'onetime',
        'paymentmethod' => 'banktransfer', 'noemail' => true, 'noinvoiceemail' => true,
    ]);
    if (($add['result'] ?? '') !== 'success') {
        throw new RuntimeException('AddOrder failed: ' . json_encode($add));
    }
    $orderId = (int)$add['orderid'];
    $invoiceId = (int)($add['invoiceid'] ?? 0);
    $serviceId = (int) explode(',', (string)($add['productids'] ?? ''))[0];
    payInvoiceFromCredit($db, $invoiceId, $clientId);
    $accept = localAPI('AcceptOrder', ['orderid' => $orderId, 'autosetup' => true, 'sendemail' => false]);
    if (($accept['result'] ?? '') !== 'success') {
        throw new RuntimeException('AcceptOrder failed: ' . json_encode($accept));
    }
    return ['order' => $orderId, 'invoice' => $invoiceId, 'service' => $serviceId];
}

/**
 * Give money back on an invoice until $fraction of its total has been refunded in
 * all, and return what this call booked (0 when that much was already back).
 *
 * This is the ONE place these tests write a row by hand instead of going through
 * localAPI, and the reason is that the API cannot do it: `AddTransaction` refuses
 * an invoice that is already Paid ("The system cannot modify the updated_at
 * attribute on an invoice that is in the Paid status"), which every refundable
 * invoice is. So the row is written in exactly the shape WHMCS's own admin Refund
 * action produces — copied off a real refund on the dev box (invoice #613):
 * `gateway_funds_out`, `refundid` pointing at the payment it reverses, the
 * payment's own gateway/currency/rate. The hook reads that shape back; anything
 * looser would test a fiction.
 */
function refundInvoice(PDO $db, int $invoiceId, float $fraction): float
{
    $invoice = one($db, 'SELECT total FROM tblinvoices WHERE id=?', [$invoiceId]);
    if ($invoice === null) {
        throw new RuntimeException("invoice #$invoiceId vanished");
    }
    $payment = one($db, 'SELECT id FROM tblaccounts WHERE invoiceid=? AND amountin>0 AND amountout=0 ORDER BY id LIMIT 1', [$invoiceId]);
    if ($payment === null) {
        throw new RuntimeException("invoice #$invoiceId carries no payment to refund");
    }
    $total = round((float)$invoice['total'], 2);
    $already = (float) (one($db, 'SELECT COALESCE(SUM(amountout),0) s FROM tblaccounts WHERE invoiceid=? AND refundid>0', [$invoiceId])['s'] ?? 0);
    $amount = round(($fraction >= 1.0 ? $total : round($total * $fraction, 2)) - $already, 2);
    if ($amount <= 0) {
        return 0.0;
    }
    $st = $db->prepare(
        "INSERT INTO tblaccounts (userid, currency, gateway, date, description, amountin, fees,
                                  amountout, rate, transid, invoiceid, refundid, type, relid)
         SELECT userid, currency, gateway, NOW(), CONCAT('Refund of Transaction ID ', id), 0.00, 0.00,
                ?, rate, CONCAT('refund_', id), invoiceid, id, 'gateway_funds_out', 0
         FROM tblaccounts WHERE id = ?"
    );
    $st->execute([$amount, (int)$payment['id']]);
    return $amount;
}

try {
    // -- 1. first key = default; second never steals it -----------------------
    $first = placeKeyOrder($db, (int)$buyer['id'], (int)$prod['id']);
    $orders[] = $first;
    ok("first order placed (service #{$first['service']})");

    serviceProperty($db, $first['service'], 'isDefaultKey') === 'yes'
        ? ok('the FIRST key bought became the default at purchase time')
        : bad('first key not marked default');
    ($hash1 = serviceProperty($db, $first['service'], 'accessCodeHash')) !== null
        ? ok('accessCodeHash stored (claim-by-code can find this sale)')
        : bad('no accessCodeHash on the first sale');

    $second = placeKeyOrder($db, (int)$buyer['id'], (int)$prod['id']);
    $orders[] = $second;
    // WHMCS materializes an EMPTY value row for every field of the product, so
    // "not marked" reads back as '' (or no row at all) — never as 'yes'
    in_array(serviceProperty($db, $second['service'], 'isDefaultKey'), [null, ''], true)
        ? ok('a SECOND purchase never steals the default slot')
        : bad('second key was marked default');
    $hash2 = serviceProperty($db, $second['service'], 'accessCodeHash');
    ($hash2 !== null && $hash2 !== $hash1)
        ? ok('each sale carries its own code hash')
        : bad('second sale hash wrong: ' . json_encode($hash2));

    // -- 2. refund revokes by default; keepOnRefund keeps deliberately --------
    require_once WEBROOT . '/includes/hooks/vpnhoodstore-refund-terminate.php';

    // a PARTIAL refund is the goodwill case (§8) and must never revoke — the bug
    // that terminated a live key over a few dollars handed back
    refundInvoice($db, $first['invoice'], 0.5);
    vpnhoodstore_refundTerminateHook(['invoiceid' => $first['invoice']]);
    (one($db, 'SELECT domainstatus FROM tblhosting WHERE id=?', [$first['service']])['domainstatus'] ?? '?') === 'Active'
        ? ok('a PARTIAL refund leaves the key running (never revoke by accident)')
        : bad('a partial refund terminated the service');

    // the rest of the money goes back: the partial becomes full → terminated.
    // This is the AMOUNT signal — the invoice is still Paid, the sum is what tells.
    refundInvoice($db, $first['invoice'], 1.0);
    vpnhoodstore_refundTerminateHook(['invoiceid' => $first['invoice']]);
    (one($db, 'SELECT domainstatus FROM tblhosting WHERE id=?', [$first['service']])['domainstatus'] ?? '?') === 'Terminated'
        ? ok('refunds that add up to the invoice total revoke the key — money and service go back together')
        : bad('fully refunded service was not terminated');

    // deliberate keep, on the other signal: WHMCS marks the invoice Refunded
    $svc = \WHMCS\Service\Service::find($second['service']);
    $svc->serviceProperties->save(['keepOnRefund' => 'yes']);
    refundInvoice($db, $second['invoice'], 1.0);
    localAPI('UpdateInvoice', ['invoiceid' => $second['invoice'], 'status' => 'Refunded']);
    vpnhoodstore_refundTerminateHook(['invoiceid' => $second['invoice']]);
    (one($db, 'SELECT domainstatus FROM tblhosting WHERE id=?', [$second['service']])['domainstatus'] ?? '?') === 'Active'
        ? ok('keepOnRefund=yes keeps the key running through a full refund (the deliberate choice)')
        : bad('keepOnRefund was ignored');

    // …and without the mark, the Refunded status alone revokes it
    $svc->serviceProperties->save(['keepOnRefund' => 'no']);
    vpnhoodstore_refundTerminateHook(['invoiceid' => $second['invoice']]);
    (one($db, 'SELECT domainstatus FROM tblhosting WHERE id=?', [$second['service']])['domainstatus'] ?? '?') === 'Terminated'
        ? ok('an invoice WHMCS marked Refunded revokes the key on its own')
        : bad('invoice marked Refunded did not revoke the key');
} catch (RuntimeException $e) {
    bad($e->getMessage());
} finally {
    foreach ($orders as $order) {
        if ((one($db, 'SELECT domainstatus FROM tblhosting WHERE id=?', [$order['service']])['domainstatus'] ?? '') !== 'Terminated') {
            localAPI('ModuleTerminate', ['serviceid' => $order['service']]);
        }
        $status = one($db, 'SELECT status FROM tblorders WHERE id=?', [$order['order']])['status'] ?? '';
        if ($status === 'Pending') {
            localAPI('CancelOrder', ['orderid' => $order['order'], 'cancelsub' => false]);
        } elseif ($status !== '' && $status !== 'Cancelled') {
            $db->prepare("UPDATE tblorders SET status='Cancelled' WHERE id=?")->execute([$order['order']]);
        }
        localAPI('DeleteOrder', ['orderid' => $order['order']]);
        $db->prepare('DELETE FROM tblhosting WHERE id=?')->execute([$order['service']]);
        $db->prepare('DELETE FROM tblaccounts WHERE invoiceid=?')->execute([$order['invoice']]);
        $db->prepare('DELETE FROM tblinvoiceitems WHERE invoiceid=?')->execute([$order['invoice']]);
        $db->prepare('DELETE FROM tblinvoices WHERE id=?')->execute([$order['invoice']]);
    }
    ok('cleanup done (both orders terminated + deleted; ~4 USD of buyer credit consumed)');
}

finish();
