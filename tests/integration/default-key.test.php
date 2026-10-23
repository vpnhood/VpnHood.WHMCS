<?php
/**
 * default-key.test.php — the provisioning-time account-key marks and the
 * refund-revokes-by-default hook (lifecycle §8), on real vpnhoodstore orders:
 *
 *   1. the buyer's FIRST key gets isDefaultKey=yes + accessCodeHash; a SECOND
 *      purchase never steals the default slot;
 *   2. the InvoiceRefunded hook terminates a FULLY refunded service unless the
 *      Refund form's Reverse Payment box was left unticked — a refund from
 *      outside the form revokes — and leaves a partially refunded one running.
 *      The real form is driven by tests/e2e/refund.spec.mjs.
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

    // -- 2. refund revokes unless Reverse Payment is left unticked -----------
    require_once WEBROOT . '/includes/hooks/vpnhoodstore-refund-terminate.php';

    // a PARTIAL refund is the goodwill case (§8) and must never revoke — the bug
    // that terminated a live key over a few dollars handed back
    refundInvoice($db, $first['invoice'], 0.5);
    vpnhoodstore_refundTerminateHook(['invoiceid' => $first['invoice']]);
    (one($db, 'SELECT domainstatus FROM tblhosting WHERE id=?', [$first['service']])['domainstatus'] ?? '?') === 'Active'
        ? ok('a PARTIAL refund leaves the key running (never revoke by accident)')
        : bad('a partial refund terminated the service');

    // the rest of the money goes back: the partial becomes full → terminated.
    // This is the AMOUNT signal — the invoice is still Paid, the sum is what tells —
    // and no Refund form is in the request, so nobody chose to keep the key.
    refundInvoice($db, $first['invoice'], 1.0);
    vpnhoodstore_refundTerminateHook(['invoiceid' => $first['invoice']]);
    (one($db, 'SELECT domainstatus FROM tblhosting WHERE id=?', [$first['service']])['domainstatus'] ?? '?') === 'Terminated'
        ? ok('refunds from outside the Refund form that add up to the invoice total revoke the key')
        : bad('fully refunded service was not terminated');

    // deliberate keep: the Refund form's request as WHMCS 9.1 posts it, Reverse
    // Payment unticked. The invoice is not marked Refunded here: UpdateInvoice would
    // fire the hook inside localAPI, where $_POST is the API's parameters — a refund
    // from outside the form. The real form's Refunded status is refund.spec.mjs's.
    $_POST = ['sub' => 'refund', 'id' => (string)$second['invoice'], 'reverse' => '0'];
    refundInvoice($db, $second['invoice'], 1.0);
    vpnhoodstore_refundTerminateHook(['invoiceid' => $second['invoice']]);
    (one($db, 'SELECT domainstatus FROM tblhosting WHERE id=?', [$second['service']])['domainstatus'] ?? '?') === 'Active'
        ? ok('Reverse Payment unticked keeps the key running through a full refund (the deliberate choice)')
        : bad('Reverse Payment unticked did not keep the key');

    // the form of ANOTHER invoice's refund is no answer for this one
    $_POST['id'] = (string)$first['invoice'];
    vpnhoodstore_refundFormReverse($second['invoice']) === null
        ? ok("another invoice's Refund form is no answer for this one")
        : bad("another invoice's Refund form was read as this one's");

    // …and ticked, the same refund revokes it
    $_POST = ['sub' => 'refund', 'id' => (string)$second['invoice'], 'reverse' => '1'];
    vpnhoodstore_refundTerminateHook(['invoiceid' => $second['invoice']]);
    $_POST = [];
    (one($db, 'SELECT domainstatus FROM tblhosting WHERE id=?', [$second['service']])['domainstatus'] ?? '?') === 'Terminated'
        ? ok('Reverse Payment ticked revokes the key')
        : bad('Reverse Payment ticked did not revoke the key');
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
