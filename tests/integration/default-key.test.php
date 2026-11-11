<?php
/**
 * default-key.test.php — the provisioning-time account-key marks and the
 * refund-revokes-by-default hook (lifecycle §8), on real vpnhoodstore orders:
 *
 *   1. the buyer's FIRST key gets isDefaultKey=yes + accessCodeHash; a SECOND
 *      purchase never steals the default slot;
 *   2. the InvoiceRefunded hook terminates a FULLY refunded service unless the
 *      Refund form's Reverse Payment box was left unticked — a one-time code
 *      refunded from outside the form is revoked — and leaves a partially
 *      refunded one running. The real form is driven by tests/e2e/refund.spec.mjs.
 *   3. a SUBSCRIPTION refunded in full from outside the form keeps its key to the
 *      end of its paid cycle, at most one month: an End of Billing Period
 *      cancellation request, the due date (and the key's expiry) cut to one month
 *      when the cycle runs longer, an open renewal invoice cancelled, and a
 *      request the customer made turned into that one.
 *
 * ⚠ Places FOUR real orders (real tokens at the access manager); all are
 * terminated and deleted in cleanup.
 */

require __DIR__ . '/lib/common.php';
require_once WEBROOT . '/modules/servers/vpnhoodstore/lib/AsyncApiClientFactory.php';
require_once WEBROOT . '/modules/servers/vpnhoodstore/lib/ApiService.php';

use WHMCS\Module\Server\VpnHoodStore\ApiService;

const KEY_SLUG = 'reseller-one-month-premium-code';
const SUB_SLUG = 'reseller-one-month-premium-code-subscription';

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

$sub = one($db, "SELECT p.id FROM tblproducts p
    LEFT JOIN tblproducts_slugs s ON s.product_id=p.id AND s.active=1
    WHERE p.slug=? OR s.slug=? LIMIT 1", [SUB_SLUG, SUB_SLUG]);
if (!$sub) {
    bad('fixture subscription product missing — run tests/bootstrap/init-skeleton.sh first');
    finish();
}

$orders = [];
$renewals = [];

function placeKeyOrder(PDO $db, int $clientId, int $productId, string $cycle = 'onetime'): array
{
    $add = localAPI('AddOrder', [
        'clientid' => $clientId, 'pid' => $productId, 'billingcycle' => $cycle,
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

/** The service's status, due date and the newest cancellation request's type. */
function serviceState(PDO $db, int $serviceId): array
{
    $h = one($db, 'SELECT domainstatus, nextduedate, nextinvoicedate FROM tblhosting WHERE id=?', [$serviceId]) ?? [];
    $r = one($db, 'SELECT type FROM tblcancelrequests WHERE relid=? ORDER BY id DESC LIMIT 1', [$serviceId]);
    return $h + ['request' => $r['type'] ?? null];
}

/** The key's expiry date (Y-m-d) and enabled flag at the access manager. */
function keyState(PDO $db, int $serviceId): array
{
    $tokenId = (string) serviceProperty($db, $serviceId, 'accessTokenId');
    $token = json_decode((new ApiService())->getAccessCode($tokenId), true)['accessToken'] ?? [];
    return ['expires' => substr((string) ($token['expirationTime'] ?? ''), 0, 10), 'enabled' => $token['isEnabled'] ?? null];
}

/** One month from today, clamped to the next month's last day (the hook's cap). */
function oneMonthOn(): string
{
    $today = new DateTimeImmutable('today');
    $next = $today->modify('first day of next month');
    return $next->setDate((int) $next->format('Y'), (int) $next->format('n'),
        min((int) $today->format('j'), (int) $next->format('t')))->format('Y-m-d');
}

function lastLogId(PDO $db): int
{
    return (int) (one($db, 'SELECT MAX(id) m FROM tblactivitylog')['m'] ?? 0);
}

function loggedSince(PDO $db, int $afterId, string $needle): bool
{
    return one($db, 'SELECT id FROM tblactivitylog WHERE id>? AND description LIKE ? LIMIT 1', [$afterId, "%$needle%"]) !== null;
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
    // and no Refund form is in the request; a one-time code is a reseller's, and a
    // reseller's refund always ends the code.
    refundInvoice($db, $first['invoice'], 1.0);
    vpnhoodstore_refundTerminateHook(['invoiceid' => $first['invoice']]);
    (one($db, 'SELECT domainstatus FROM tblhosting WHERE id=?', [$first['service']])['domainstatus'] ?? '?') === 'Terminated'
        ? ok('refunds from outside the Refund form that add up to the invoice total revoke a one-time key')
        : bad('fully refunded one-time service was not terminated');

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

    // -- 3. a subscription refunded outside the form: kept to its cycle's end, at most a month
    // monthly: due in 5 days with its renewal invoice already out → kept to that due date, and
    // the open renewal invoice is cancelled so nothing more is charged
    $monthly = placeKeyOrder($db, (int)$buyer['id'], (int)$sub['id'], 'monthly');
    $orders[] = $monthly;
    $due = date('Y-m-d', time() + 5 * 86400);
    localAPI('UpdateClientProduct', ['serviceid' => $monthly['service'], 'nextduedate' => $due]);
    localAPI('GenInvoices', ['serviceids' => [$monthly['service']], 'noemails' => true]);
    $renewal = (int) (one($db, "SELECT inv.id FROM tblinvoiceitems i JOIN tblinvoices inv ON inv.id=i.invoiceid
        WHERE i.relid=? AND i.type='Hosting' AND inv.id<>? AND inv.status='Unpaid' LIMIT 1",
        [$monthly['service'], $monthly['invoice']])['id'] ?? 0);
    $renewals[] = $renewal;
    $renewal > 0 ? ok("a renewal invoice #$renewal is open for the monthly key") : bad('no renewal invoice was generated');
    $log = lastLogId($db);
    refundInvoice($db, $monthly['invoice'], 1.0);
    vpnhoodstore_refundTerminateHook(['invoiceid' => $monthly['invoice']]);
    $s = serviceState($db, $monthly['service']);
    ($s['domainstatus'] === 'Active' && $s['nextduedate'] === $due && $s['request'] === 'End of Billing Period')
        ? ok("a monthly key refunded outside the form keeps running to its cycle's end ($due), with an end-of-period cancellation")
        : bad('the monthly key was not kept to its cycle end: ' . json_encode($s));
    keyState($db, $monthly['service'])['enabled'] === true
        ? ok('its key is still on') : bad('its key was switched off: ' . json_encode(keyState($db, $monthly['service'])));
    $renewalStatus = (string) (one($db, 'SELECT status FROM tblinvoices WHERE id=?', [$renewal])['status'] ?? '');
    $renewalStatus === 'Cancelled'
        ? ok("the open renewal invoice #$renewal was cancelled, so nothing more is charged")
        : bad("the open renewal invoice #$renewal is $renewalStatus");
    loggedSince($db, $log, "service #{$monthly['service']} kept until $due")
        ? ok('the activity log names the day it ends') : bad('no activity-log line for the kept monthly key');

    // a year ahead, with the customer's own Immediate cancellation → cut to one month: due date,
    // key expiry and an end-of-period request
    $yearly = placeKeyOrder($db, (int)$buyer['id'], (int)$sub['id'], 'monthly');
    $orders[] = $yearly;
    localAPI('UpdateClientProduct', ['serviceid' => $yearly['service'], 'nextduedate' => date('Y-m-d', time() + 365 * 86400)]);
    $server = new \WHMCS\Module\Server();
    $server->loadByServiceID($yearly['service']);
    $server->call('Renew');
    $before = keyState($db, $yearly['service']);
    $before['expires'] > date('Y-m-d', time() + 300 * 86400)
        ? ok("the long key expires on {$before['expires']} before the refund") : bad('the long key was not set up: ' . json_encode($before));
    $c = localAPI('AddCancelRequest', ['serviceid' => $yearly['service'], 'type' => 'Immediate', 'reason' => 'vhtest: the customer cancels']);
    ($c['result'] ?? '') === 'success' ? ok('the customer asked for an Immediate cancellation') : bad('AddCancelRequest failed: ' . json_encode($c));
    refundInvoice($db, $yearly['invoice'], 1.0);
    vpnhoodstore_refundTerminateHook(['invoiceid' => $yearly['invoice']]);
    $cap = oneMonthOn();
    $s = serviceState($db, $yearly['service']);
    $k = keyState($db, $yearly['service']);
    ($s['domainstatus'] === 'Active' && $s['nextduedate'] === $cap && $s['nextinvoicedate'] === $cap)
        ? ok("a key with a year ahead is cut to one month: due on $cap") : bad('the long key was not cut to a month: ' . json_encode($s));
    ($k['expires'] === $cap && $k['enabled'] === true)
        ? ok("its key now expires on $cap and is still on") : bad('its key was not moved to the month: ' . json_encode($k));
    $s['request'] === 'End of Billing Period'
        ? ok("the customer's Immediate request became the end-of-period one") : bad('the cancellation request is ' . json_encode($s['request']));
} catch (RuntimeException $e) {
    bad($e->getMessage());
} finally {
    foreach (array_filter($renewals) as $id) {
        if ((one($db, 'SELECT status FROM tblinvoices WHERE id=?', [$id])['status'] ?? '') === 'Unpaid') {
            localAPI('UpdateInvoice', ['invoiceid' => $id, 'status' => 'Cancelled']);
        }
        $db->prepare('DELETE FROM tblinvoiceitems WHERE invoiceid=?')->execute([$id]);
        $db->prepare('DELETE FROM tblinvoices WHERE id=?')->execute([$id]);
    }
    foreach ($orders as $order) {
        $db->prepare('DELETE FROM tblcancelrequests WHERE relid=?')->execute([$order['service']]);
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
    ok('cleanup done (every order terminated + deleted; ~8 USD of buyer credit consumed)');
}

finish();
