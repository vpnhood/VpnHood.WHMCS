<?php
/**
 * refund.test.php — partner refunds (docs/ARCHITECTURE.md, "Service actions and refunds"): the Hub's `refund` action
 * over the real API as the test partner, the lifecycle guards and the lock it relies on, and the
 * connector's Refund button on a buyer service. Runs ON the dev server, uploaded by
 * refund.test.sh with lib/common.php; the .sh runs the concurrent scenarios as parallel
 * processes (PHP-CLI here cannot start any) and deploys the older Hub one scenario needs.
 *
 * Writes outside the API are limited to what a scenario sets up, each undone in a finally: the
 * PartnerRefundDays setting, an invoice's payment date and a zero-price line (UpdateInvoice),
 * renewal invoices (GenInvoices; any left Unpaid is cancelled; one gets 0.50 applied, one a line
 * set to 0.00, one a manual discount line), one refund ledger row, and the purchase record's
 * state or order id.
 *
 * ⚠ Spends reseller and buyer (test) credit and provisions real tokens; every order it places
 * ends refunded or terminated.
 *
 * Usage: php refund.test.php <scenario> [args]; prints a JSON report.
 */

require __DIR__ . '/lib/common.php';
require_once WEBROOT . '/modules/servers/vpnhoodstore/lib/AsyncApiClientFactory.php';
require_once WEBROOT . '/modules/servers/vpnhoodstore/lib/ApiService.php';
require_once WEBROOT . '/modules/addons/vpnhoodpartnerhub/lib/ApiException.php';
require_once WEBROOT . '/modules/addons/vpnhoodpartnerhub/lib/PartnerRepository.php';
require_once WEBROOT . '/modules/addons/vpnhoodpartnerhub/lib/PartnerApiController.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodPartnerHub\PurchaseRepository;
use WHMCS\Module\Server\VpnHoodStore\ApiService;

const REF_ONETIME    = 'reseller-one-month-premium-code';
const REF_RECURRING  = 'reseller-one-month-premium-code-subscription';
const CONNECTOR_SLUG = 'partner-one-month-premium-code';
const PRICE          = 1.75;
const RESULTS_DIR    = '/home/whmcsdev/tmp/vhrf';

$reseller = clientByEmail($db, RESELLER_EMAIL);
$buyer = clientByEmail($db, BUYER_EMAIL);
$partnerRow = $reseller ? Capsule::table('mod_vpnhood_partners')->where('client_id', $reseller['id'])->first() : null;
$connectorProductId = (int) (one($db, "SELECT p.id FROM tblproducts p LEFT JOIN tblproducts_slugs s ON s.product_id=p.id AND s.active=1
    WHERE p.slug=? OR s.slug=? LIMIT 1", [CONNECTOR_SLUG, CONNECTOR_SLUG])['id'] ?? 0);
if (!$reseller || !$buyer || !$partnerRow || $connectorProductId === 0) {
    bad('fixtures missing — run tests/bootstrap/init-skeleton.sh first');
    finish();
}
$partner = (array) $partnerRow;
$clientId = (int) $reseller['id'];
$buyerId = (int) $buyer['id'];
$run = getenv('RUN') ?: (string) time();

// -------------------------------------------------------------------- helpers

function hub(string $action, array $params = []): array
{
    $ch = curl_init(rtrim((string) getenv('HUB_URL'), '/') . '/modules/addons/vpnhoodpartnerhub/api.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['action' => $action] + $params),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Vpnhood-Key: ' . getenv('HUB_KEY'),
            'X-Vpnhood-Secret: ' . getenv('HUB_SECRET'),
        ],
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode((string) $body, true) ?: ['raw' => (string) $body]];
}

function expect(bool $condition, string $message, $detail = null): bool
{
    $condition ? ok($message) : bad($message . ($detail !== null ? ' — ' . json_encode($detail) : ''));
    return $condition;
}

function codeOf(array $r): string
{
    return (string) ($r['body']['code'] ?? '');
}

/** The test reseller's credit balance. */
function credit(): float
{
    global $clientId;
    return round((float) Capsule::table('tblclients')->where('id', $clientId)->value('credit'), 2);
}

function hubService(int $orderId): array
{
    global $clientId;
    return (array) Capsule::table('tblhosting')->where('orderid', $orderId)->where('userid', $clientId)->first();
}

function status(int $serviceId): string
{
    return (string) Capsule::table('tblhosting')->where('id', $serviceId)->value('domainstatus');
}

function prop(int $serviceId, string $name): string
{
    global $db;
    return (string) serviceProperty($db, $serviceId, $name);
}

/** The key as the access server has it: enabled or not. */
function keyEnabled(int $serviceId): ?bool
{
    $tokenId = prop($serviceId, 'accessTokenId');
    if ($tokenId === '') {
        return null;
    }
    $json = json_decode((new ApiService())->getAccessCode($tokenId), true);
    $enabled = $json['accessToken']['isEnabled'] ?? null;
    return $enabled === null ? null : (bool) $enabled;
}

/** A keyed order as the test partner: its order, service and invoice ids. */
function buy(string $ref, string $tag): array
{
    global $run;
    $key = "rf-$run-$tag";
    $r = hub('order', ['downstreamRef' => $ref, 'customerReference' => $key, 'idempotencyKey' => $key]);
    expect($r['status'] === 200, "order $key placed", $r['status'] === 200 ? null : $r);
    $orderId = (int) ($r['body']['data']['keys'][0]['upstreamOrderId'] ?? 0);
    return [
        'orderId'   => $orderId,
        'serviceId' => (int) (hubService($orderId)['id'] ?? 0),
        'invoiceId' => (int) Capsule::table('tblorders')->where('id', $orderId)->value('invoiceid'),
    ];
}

function refund(int $orderId): array
{
    return hub('refund', ['upstreamOrderId' => $orderId]);
}

function expectRefunded(array $r, string $what): void
{
    expect($r['status'] === 200 && ($r['body']['data']['status'] ?? '') === 'refunded'
        && abs((float) ($r['body']['data']['amount'] ?? -1) - PRICE) < 0.001, "$what -> 200 refunded " . PRICE, $r);
}

function expectRefused(array $r, string $code, string $needle, string $what): void
{
    expect($r['status'] === 409 && codeOf($r) === $code && strpos((string) ($r['body']['error'] ?? ''), $needle) !== false,
        "$what -> 409 $code", $r);
}

/** Terminated here, and the key off on the access server. */
function expectEnded(array $o, string $what): void
{
    $status = status($o['serviceId']);
    $enabled = keyEnabled($o['serviceId']);
    expect($status === 'Terminated' && $enabled === false, "$what: the service is Terminated and its key disabled",
        ['status' => $status, 'keyEnabled' => $enabled]);
}

/** Still live here, and the key still on. */
function expectLive(array $o, string $what): void
{
    $status = status($o['serviceId']);
    $enabled = keyEnabled($o['serviceId']);
    expect(in_array($status, ['Active', 'Suspended'], true) && ($status === 'Suspended' || $enabled === true),
        "$what: the service is still $status with its key", ['status' => $status, 'keyEnabled' => $enabled]);
}

/** The refund credit rows of an order (RefundPolicy::creditDescription). */
function refundRows(array $o): int
{
    global $clientId;
    return (int) Capsule::table('tblcredit')->where('clientid', $clientId)
        ->where('description', "Partner Hub refund of order #{$o['orderId']} (invoice #{$o['invoiceId']})")->count();
}

function lastActivityId(): int
{
    return (int) Capsule::table('tblactivitylog')->max('id');
}

function activityLogged(int $afterId, string $needle): bool
{
    return Capsule::table('tblactivitylog')->where('id', '>', $afterId)->where('description', 'like', '%' . $needle . '%')->exists();
}

function terminateOrder(int $orderId): void
{
    if ($orderId <= 0 || !in_array(status((int) (hubService($orderId)['id'] ?? 0)), ['Active', 'Suspended'], true)) {
        return;
    }
    $r = hub('terminate', ['upstreamOrderId' => $orderId]);
    expect($r['status'] === 200, "cleanup: order #$orderId terminated", $r['status'] === 200 ? null : $r);
}

function refundDaysSetting(): ?string
{
    $value = Capsule::table('tbladdonmodules')->where('module', 'vpnhoodpartnerhub')->where('setting', 'PartnerRefundDays')->value('value');
    return $value === null ? null : (string) $value;
}

/** The PartnerRefundDays setting, as the addon's configuration page saves it (null = never saved). */
function setRefundDays(?string $value): void
{
    $row = Capsule::table('tbladdonmodules')->where('module', 'vpnhoodpartnerhub')->where('setting', 'PartnerRefundDays');
    if ($value === null) {
        $row->delete();
    } elseif ($row->exists()) {
        $row->update(['value' => $value]);
    } else {
        Capsule::table('tbladdonmodules')->insert(['module' => 'vpnhoodpartnerhub', 'setting' => 'PartnerRefundDays', 'value' => $value]);
    }
}

function setDatePaid(int $invoiceId, int $timestamp): void
{
    $date = date('Y-m-d H:i:s', $timestamp);
    $r = localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'datepaid' => $date]);
    $stored = (string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('datepaid');
    expect(($r['result'] ?? '') === 'success' && $stored === $date, "invoice #$invoiceId now paid on $date", [$r, $stored]);
}

function purchaseOf(int $orderId): array
{
    return (array) Capsule::table(PurchaseRepository::TABLE)->where('order_id', $orderId)->first();
}

function setPurchase(int $id, array $fields): void
{
    Capsule::table(PurchaseRepository::TABLE)->where('id', $id)->update($fields + ['updated_at' => date('Y-m-d H:i:s')]);
}

/** A buyer order on the connector product, left unpaid so nothing provisions on its own. */
function newBuyerService(): int
{
    global $buyerId, $connectorProductId;
    $add = localAPI('AddOrder', [
        'clientid' => $buyerId, 'pid' => $connectorProductId, 'billingcycle' => 'onetime', 'paymentmethod' => 'banktransfer',
        'noemail' => true, 'noinvoiceemail' => true,
    ]);
    expect(($add['result'] ?? '') === 'success', 'buyer order placed (service #' . ($add['productids'] ?? '?') . ')', $add);
    return (int) explode(',', (string) ($add['productids'] ?? ''))[0];
}

/**
 * Two new recurring keys renewing on one invoice: both due in 5 days, then GenInvoices. Every
 * order goes into $made and every renewal invoice into $invoices, for the caller's cleanup. The
 * invoice id is 0 when WHMCS did not put both on one Unpaid invoice.
 */
function sharedRenewal(string $tag, array &$made, array &$invoices): array
{
    $a = $made[] = buy(REF_RECURRING, "$tag-a");
    $b = $made[] = buy(REF_RECURRING, "$tag-b");
    $due = date('Y-m-d', time() + 5 * 86400);
    foreach ([$a, $b] as $o) {
        localAPI('UpdateClientProduct', ['serviceid' => $o['serviceId'], 'nextduedate' => $due]);
    }
    $g = localAPI('GenInvoices', ['serviceids' => [$a['serviceId'], $b['serviceId']], 'noemails' => true]);
    $renewalOf = fn (array $o): int => (int) Capsule::table('tblinvoiceitems as i')
        ->join('tblinvoices as inv', 'inv.id', '=', 'i.invoiceid')
        ->where('i.relid', $o['serviceId'])->where('i.type', 'Hosting')->where('inv.id', '!=', $o['invoiceId'])
        ->where('inv.status', 'Unpaid')->value('inv.id');
    $ofA = $renewalOf($a);
    $ofB = $renewalOf($b);
    array_push($invoices, ...array_filter([$ofA, $ofB]));
    $shared = expect($ofA > 0 && $ofA === $ofB, "$tag: both keys renew on one invoice #$ofA", [$ofA, $ofB, $g]);
    return [$a, $b, $shared ? $ofA : 0];
}

function hasLine(int $invoiceId, int $serviceId): bool
{
    return Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->where('relid', $serviceId)
        ->whereIn('type', ['Hosting', 'PromoHosting'])->exists();
}

function nextDue(int $serviceId): string
{
    return (string) Capsule::table('tblhosting')->where('id', $serviceId)->value('nextduedate');
}

function invoiceStatus(int $invoiceId): string
{
    return (string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status');
}

function saveResult(string $name, array $r): void
{
    @mkdir(RESULTS_DIR, 0700, true);
    file_put_contents(RESULTS_DIR . "/$name.json", json_encode($r));
}

function loadResult(string $name): array
{
    return json_decode((string) @file_get_contents(RESULTS_DIR . "/$name.json"), true) ?: [];
}

// ------------------------------------------------------------------ scenarios

$scenario = $argv[1] ?? '';
switch ($scenario) {

// A new key refunded inside the window: the key ends, the price returns once, and the order is
// final through the API from then on.
case 'refund':
    $before = credit();
    $o = buy(REF_ONETIME, 'basic');
    expect(abs(credit() - ($before - PRICE)) < 0.001, 'the order charged ' . PRICE, credit());
    expectLive($o, 'before the refund');
    $logFrom = lastActivityId();
    expectRefunded(refund($o['orderId']), 'refund inside the window');
    expectEnded($o, 'after the refund');
    expect(abs(credit() - $before) < 0.001, 'the price is back on the credit', ['before' => $before, 'after' => credit()]);
    expect(refundRows($o) === 1, 'one credit row, named after the order and invoice', refundRows($o));
    expect(activityLogged($logFrom, "order #{$o['orderId']} refunded"), 'the refund is in the activity log');
    expectRefunded(refund($o['orderId']), 'a repeated refund');
    expect(abs(credit() - $before) < 0.001 && refundRows($o) === 1, 'the repeat returned nothing more', ['credit' => credit(), 'rows' => refundRows($o)]);
    foreach (['suspend' => 'suspended', 'unsuspend' => 'unsuspended', 'renew' => 'renewed'] as $action => $past) {
        expectRefused(hub($action, ['upstreamOrderId' => $o['orderId']]), 'service_ended', "cannot be $past", "$action on the refunded order");
    }
    $r = hub('terminate', ['upstreamOrderId' => $o['orderId']]);
    expect($r['status'] === 200, 'terminate on the refunded order still succeeds (the module runs again)', $r);
    expectEnded($o, 'after terminating it again');
    break;

// "Terminate now, refund later": the refund ends the key again (ModuleTerminate on a Terminated
// service still runs the module) and returns the price.
case 'terminate-first':
    $before = credit();
    $o = buy(REF_ONETIME, 'terminate-first');
    $r = hub('terminate', ['upstreamOrderId' => $o['orderId']]);
    expect($r['status'] === 200, 'terminated first', $r);
    $logFrom = lastActivityId();
    expectRefunded(refund($o['orderId']), 'refund of the terminated order');
    $terminatedAgain = Capsule::table('tblactivitylog')->where('id', '>', $logFrom)->where('description', 'like', '%Terminate%')
        ->where('description', 'like', "%{$o['serviceId']}%")->exists();
    expect($terminatedAgain, 'the refund ran the module Terminate again on the Terminated service');
    expect(abs(credit() - $before) < 0.001, 'the price is back on the credit', ['before' => $before, 'after' => credit()]);
    expectEnded($o, 'after the refund');
    break;

case 'suspended':
    $before = credit();
    $o = buy(REF_ONETIME, 'suspended');
    $r = hub('suspend', ['upstreamOrderId' => $o['orderId']]);
    expect($r['status'] === 200, 'suspended first', $r);
    expectRefunded(refund($o['orderId']), 'refund of the suspended order');
    expectEnded($o, 'after the refund');
    expect(abs(credit() - $before) < 0.001, 'the price is back on the credit', credit());
    expectRefused(hub('unsuspend', ['upstreamOrderId' => $o['orderId']]), 'service_ended', 'cannot be unsuspended', 'unsuspend after the refund');
    break;

// The window: 7 days by default, the admin's PartnerRefundDays when set, and 0 turns refunds off.
// A refused refund ends nothing and returns nothing.
case 'window':
    $previous = refundDaysSetting();
    $o = $off = $in = null;
    try {
        setRefundDays(null);
        $in = buy(REF_ONETIME, 'window-in');
        setDatePaid($in['invoiceId'], time() - 6 * 86400);
        expectRefunded(refund($in['orderId']), 'paid 6 days ago, inside the default 7-day window');
        $o = buy(REF_ONETIME, 'window');
        $before = credit();
        setDatePaid($o['invoiceId'], time() - 8 * 86400);
        expectRefused(refund($o['orderId']), 'refund_window_closed', 'closed on', 'paid 8 days ago, with the default 7-day window');
        expectLive($o, 'after the refused refund');
        expect(abs(credit() - $before) < 0.001, 'the refused refund returned nothing', credit());
        setRefundDays('10');
        expectRefunded(refund($o['orderId']), 'the same order with PartnerRefundDays = 10');
        expectEnded($o, 'after that refund');
        $off = buy(REF_ONETIME, 'window-off');
        setRefundDays('0');
        expectRefused(refund($off['orderId']), 'refund_window_closed', 'turned off', 'a new order with PartnerRefundDays = 0');
        expectLive($off, 'after the refused refund');
    } finally {
        setRefundDays($previous);
        terminateOrder((int) ($off['orderId'] ?? 0));
        terminateOrder((int) ($o['orderId'] ?? 0));
        terminateOrder((int) ($in['orderId'] ?? 0));
    }
    break;

// A key with a later invoice (a renewal, paid or not) is not refundable: ending it would take
// the renewal term too, or leave a renewal line another renewal could pay.
case 'later-invoice':
    $o = buy(REF_RECURRING, 'later');
    $renewal = 0;
    try {
        $u = localAPI('UpdateClientProduct', ['serviceid' => $o['serviceId'], 'nextduedate' => date('Y-m-d', time() + 5 * 86400)]);
        $g = localAPI('GenInvoices', ['serviceids' => [$o['serviceId']], 'noemails' => true]);
        $renewal = (int) Capsule::table('tblinvoiceitems as i')->join('tblinvoices as inv', 'inv.id', '=', 'i.invoiceid')
            ->where('i.relid', $o['serviceId'])->where('i.type', 'Hosting')->where('inv.id', '!=', $o['invoiceId'])
            ->where('inv.status', 'Unpaid')->value('inv.id');
        expect($renewal > 0, "a renewal invoice #$renewal was generated", [$u, $g]);
        $before = credit();
        expectRefused(refund($o['orderId']), 'not_refundable', "later invoice (#$renewal)", 'a key with a renewal invoice');
        expectLive($o, 'after the refused refund');
        expect(abs(credit() - $before) < 0.001, 'the refused refund returned nothing', credit());
    } finally {
        if ($renewal > 0) {
            $c = localAPI('UpdateInvoice', ['invoiceid' => $renewal, 'status' => 'Cancelled']);
            expect(($c['result'] ?? '') === 'success', "cleanup: renewal invoice #$renewal cancelled", $c);
        }
        terminateOrder($o['orderId']);
    }
    break;

// Renew never pays for an ended key. WHMCS bills same-day renewals on one invoice; renewing B
// takes off the line of A, terminated meanwhile, before paying. With a payment already on the
// invoice, a line on it not tied to a key, or nothing else on it left to pay, the renewal is
// refused instead and pays nothing.
case 'ended-line':
    $made = $invoices = [];
    try {
        [$a, $b, $invoiceId] = sharedRenewal('ended', $made, $invoices);
        if ($invoiceId === 0) {
            break;
        }
        $linesOfB = round((float) Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)
            ->where('relid', $b['serviceId'])->whereIn('type', ['Hosting', 'PromoHosting'])->sum('amount'), 2);
        expect(hub('terminate', ['upstreamOrderId' => $a['orderId']])['status'] === 200, 'A terminated through the Hub');
        expect(hasLine($invoiceId, $a['serviceId']), "A's line is still on invoice #$invoiceId after its termination");
        $dueOfA = nextDue($a['serviceId']);
        $dueOfB = nextDue($b['serviceId']);
        $before = credit();
        $log = lastActivityId();
        $r = hub('renew', ['upstreamOrderId' => $b['orderId']]);
        expect($r['status'] === 200 && ($r['body']['data']['status'] ?? '') === 'renewed', 'B renewed', $r);
        expect(strtotime(nextDue($b['serviceId'])) > strtotime($dueOfB), "B's due date moved on", [$dueOfB, nextDue($b['serviceId'])]);
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['status', 'total']);
        expect($invoice->status === 'Paid' && abs((float) $invoice->total - $linesOfB) < 0.001,
            "invoice #$invoiceId is Paid, for B's line alone ($linesOfB)", $invoice);
        expect(!hasLine($invoiceId, $a['serviceId']), "A's line is off the invoice");
        expect(abs($before - credit() - $linesOfB) < 0.001, "the credit paid B's line only", [$before, credit()]);
        expect(status($a['serviceId']) === 'Terminated' && nextDue($a['serviceId']) === $dueOfA,
            'A is still Terminated, its due date unchanged', [status($a['serviceId']), $dueOfA, nextDue($a['serviceId'])]);
        expect(activityLogged($log, "took ended order(s) #{$a['orderId']} off renewal invoice #$invoiceId"),
            'the activity log names the removal');
        // Nothing is invoiced for A any more, so its first purchase refunds inside the window.
        $paidForA = round((float) Capsule::table('tblinvoices')->where('id', $a['invoiceId'])->value('total'), 2);
        $before = credit();
        $r = refund($a['orderId']);
        expect($r['status'] === 200 && ($r['body']['data']['status'] ?? '') === 'refunded'
            && abs((float) ($r['body']['data']['amount'] ?? -1) - $paidForA) < 0.001
            && abs(credit() - $before - $paidForA) < 0.001, "A refunds its $paidForA to the credit", [$r, $before, credit()]);

        [$c, $d, $invoiceId] = sharedRenewal('ended-paid', $made, $invoices);
        if ($invoiceId === 0) {
            break;
        }
        $p = localAPI('ApplyCredit', ['invoiceid' => $invoiceId, 'amount' => 0.5, 'noemail' => true]);
        expect(($p['result'] ?? '') === 'success', "0.50 applied to invoice #$invoiceId", $p);
        expect(hub('terminate', ['upstreamOrderId' => $c['orderId']])['status'] === 200, 'C terminated through the Hub');
        $before = credit();
        expectRefused(hub('renew', ['upstreamOrderId' => $d['orderId']]), 'renewal_blocked', "#$invoiceId",
            'renew D with a payment already on the invoice');
        expect(hasLine($invoiceId, $c['serviceId']) && invoiceStatus($invoiceId) === 'Unpaid',
            "C's line stays and the invoice is still Unpaid");
        expect(abs(credit() - $before) < 0.001, 'nothing more was paid', credit());

        // A free F: taking E's line off would leave 0.00, which WHMCS keeps Unpaid for good.
        [$e, $f, $invoiceId] = sharedRenewal('ended-free', $made, $invoices);
        if ($invoiceId === 0) {
            break;
        }
        // WHMCS edits an existing line only with all three arrays; without itemtaxed it throws a TypeError.
        $lineOfF = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->where('relid', $f['serviceId'])
            ->where('type', 'Hosting')->first(['id', 'description', 'taxed']);
        $u = localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'itemdescription' => [$lineOfF->id => $lineOfF->description],
            'itemamount' => [$lineOfF->id => 0], 'itemtaxed' => [$lineOfF->id => (bool) $lineOfF->taxed]]);
        expect(($u['result'] ?? '') === 'success', "F's line on invoice #$invoiceId set to 0.00", $u);
        expect(hub('terminate', ['upstreamOrderId' => $e['orderId']])['status'] === 200, 'E terminated through the Hub');
        $before = credit();
        expectRefused(hub('renew', ['upstreamOrderId' => $f['orderId']]), 'renewal_blocked', 'nothing else',
            'renew F with nothing else left to pay');
        expect(hasLine($invoiceId, $e['serviceId']) && invoiceStatus($invoiceId) === 'Unpaid',
            "E's line stays and the invoice is still Unpaid");
        expect(abs(credit() - $before) < 0.001, 'nothing was paid', credit());

        // A discount typed in by hand names no key, so it may have been meant for the ended G.
        [$g, $h, $invoiceId] = sharedRenewal('ended-manual', $made, $invoices);
        if ($invoiceId === 0) {
            break;
        }
        $u = localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'newitemdescription' => ['vhtest discount'],
            'newitemamount' => [-0.5], 'newitemtaxed' => [false]]);
        expect(($u['result'] ?? '') === 'success', "a manual discount line added to invoice #$invoiceId", $u);
        expect(hub('terminate', ['upstreamOrderId' => $g['orderId']])['status'] === 200, 'G terminated through the Hub');
        $before = credit();
        expectRefused(hub('renew', ['upstreamOrderId' => $h['orderId']]), 'renewal_blocked', 'not tied to a key',
            'renew H with a manual line on the invoice');
        expect(hasLine($invoiceId, $g['serviceId']) && invoiceStatus($invoiceId) === 'Unpaid',
            "G's line stays and the invoice is still Unpaid");
        expect(abs(credit() - $before) < 0.001, 'nothing was paid', credit());

        // With no ended key on it, the same manual line changes nothing: the renewal pays as before.
        [$i, $j, $invoiceId] = sharedRenewal('live-manual', $made, $invoices);
        if ($invoiceId === 0) {
            break;
        }
        $u = localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'newitemdescription' => ['vhtest discount'],
            'newitemamount' => [-0.5], 'newitemtaxed' => [false]]);
        expect(($u['result'] ?? '') === 'success', "a manual discount line added to invoice #$invoiceId", $u);
        $dueOfJ = nextDue($j['serviceId']);
        $r = hub('renew', ['upstreamOrderId' => $j['orderId']]);
        expect($r['status'] === 200 && ($r['body']['data']['status'] ?? '') === 'renewed'
            && invoiceStatus($invoiceId) === 'Paid' && strtotime(nextDue($j['serviceId'])) > strtotime($dueOfJ),
            "J renews, invoice #$invoiceId Paid, with no ended key on it", [$r, invoiceStatus($invoiceId)]);
    } finally {
        foreach (array_unique($invoices) as $id) {
            if (invoiceStatus($id) === 'Unpaid') {
                $x = localAPI('UpdateInvoice', ['invoiceid' => $id, 'status' => 'Cancelled']);
                expect(($x['result'] ?? '') === 'success', "cleanup: renewal invoice #$id cancelled", $x);
            }
        }
        foreach ($made as $o) {
            terminateOrder($o['orderId']);
        }
    }
    break;

// Only what the Hub itself sold and was paid for can be refunded here; the rest is refunded by
// hand. Each case is set up on one order and undone again, and the order then refunds.
case 'records':
    $o = buy(REF_ONETIME, 'records');
    $purchase = purchaseOf($o['orderId']);
    $before = credit();
    $lineId = 0;
    $ledgerId = 0;
    try {
        setPurchase((int) $purchase['id'], ['state' => 'needs_reconciliation']);
        expectRefused(refund($o['orderId']), 'not_refundable', 'never finished', 'an unfinished purchase');
        setPurchase((int) $purchase['id'], ['state' => $purchase['state']]);

        setPurchase((int) $purchase['id'], ['order_id' => null]);
        expectRefused(refund($o['orderId']), 'not_refundable', 'not bought through the Partner Hub', 'an order with no purchase record');
        setPurchase((int) $purchase['id'], ['order_id' => $o['orderId']]);

        $u = localAPI('UpdateInvoice', ['invoiceid' => $o['invoiceId'], 'newitemdescription' => ['vhtest extra line'],
            'newitemamount' => [0], 'newitemtaxed' => [false]]);
        $lineId = (int) Capsule::table('tblinvoiceitems')->where('invoiceid', $o['invoiceId'])->where('description', 'vhtest extra line')->value('id');
        $invoiceStatus = (string) Capsule::table('tblinvoices')->where('id', $o['invoiceId'])->value('status');
        expect($lineId > 0 && $invoiceStatus === 'Paid', 'a zero-price line added by hand; the invoice is still Paid', [$u, $invoiceStatus]);
        expectRefused(refund($o['orderId']), 'not_refundable', 'bills more than this key', 'an invoice with another line');
        localAPI('UpdateInvoice', ['invoiceid' => $o['invoiceId'], 'deletelineids' => [$lineId]]);
        $lineId = 0;

        $payment = (int) Capsule::table('tblaccounts')->where('invoiceid', $o['invoiceId'])->where('amountin', '>', 0)->value('id');
        $ledgerId = (int) Capsule::table('tblaccounts')->insertGetId([
            'userid' => (int) $purchase['client_id'], 'currency' => 0, 'gateway' => 'banktransfer', 'date' => date('Y-m-d H:i:s'),
            'description' => 'vhtest refund booked by hand', 'amountin' => 0, 'fees' => 0, 'amountout' => 0.5, 'rate' => 1,
            'transid' => "vhtest-$run", 'invoiceid' => $o['invoiceId'], 'refundid' => $payment, 'billingnoteid' => 0, 'type' => '', 'relid' => 0,
        ]);
        expectRefused(refund($o['orderId']), 'not_refundable', 'already recorded', 'an invoice with a refund booked by hand');
    } finally {
        setPurchase((int) $purchase['id'], ['state' => $purchase['state'], 'order_id' => $o['orderId']]);
        if ($lineId > 0) {
            localAPI('UpdateInvoice', ['invoiceid' => $o['invoiceId'], 'deletelineids' => [$lineId]]);
        }
        if ($ledgerId > 0) {
            Capsule::table('tblaccounts')->where('id', $ledgerId)->delete();
        }
    }
    expectLive($o, 'after every refused refund');
    expect(abs(credit() - $before) < 0.001, 'the refused refunds returned nothing', credit());
    expectRefunded(refund($o['orderId']), 'once restored, the order refunds');
    expectEnded($o, 'after that refund');
    break;

// Parallel requests (refund.test.sh starts them): two refunds of one order, and an unsuspend
// racing a refund while the test holds the partner's credit lock.
case 'setup':
    $name = $argv[2] ?? 'x';
    $o = buy(REF_ONETIME, $name);
    if (($argv[3] ?? '') === 'suspend') {
        $r = hub('suspend', ['upstreamOrderId' => $o['orderId']]);
        expect($r['status'] === 200, 'suspended', $r);
    }
    saveResult($name, $o + ['creditAfterOrder' => credit()]);
    break;

case 'call':
    [, , $action, $name, $tag] = $argv + [4 => 'x'];
    $o = loadResult($name);
    $started = microtime(true);
    $r = hub($action, ['upstreamOrderId' => (int) $o['orderId']]);
    saveResult("$name-$action-$tag", $r + ['elapsed' => round(microtime(true) - $started, 1)]);
    ok("$action of order #{$o['orderId']} answered {$r['status']} after " . round(microtime(true) - $started, 1) . ' s');
    break;

case 'hold-lock':
    $seconds = (int) ($argv[2] ?? 5);
    $purchases = new PurchaseRepository();
    $lock = PurchaseRepository::creditLock($clientId);
    expect($purchases->lock($lock, 5), "the test holds the partner's credit lock for {$seconds} s");
    sleep($seconds);
    $purchases->unlock($lock);
    break;

case 'concurrent-check':
    $o = loadResult('concurrent');
    $a = loadResult('concurrent-refund-a');
    $b = loadResult('concurrent-refund-b');
    expectRefunded($a, 'the first of two concurrent refunds');
    expectRefunded($b, 'the second of two concurrent refunds');
    expect(abs(credit() - ($o['creditAfterOrder'] + PRICE)) < 0.001 && refundRows($o) === 1, 'the price came back exactly once',
        ['credit' => credit(), 'expected' => $o['creditAfterOrder'] + PRICE, 'rows' => refundRows($o)]);
    expectEnded($o, 'after both');
    break;

case 'lock-check':
    $o = loadResult('lock');
    $unsuspend = loadResult('lock-unsuspend-x');
    $refund = loadResult('lock-refund-x');
    expectRefunded($refund, 'the refund that waited for the lock');
    expect(($refund['elapsed'] ?? 0) >= 3, 'it waited for the held lock', $refund['elapsed'] ?? null);
    expect($unsuspend['status'] === 200 || codeOf($unsuspend) === 'service_ended',
        'the unsuspend ran entirely before the refund (200) or after it (409 service_ended)', $unsuspend);
    expectEnded($o, 'whichever ran first');
    expect(abs(credit() - ($o['creditAfterOrder'] + PRICE)) < 0.001, 'the price came back once',
        ['credit' => credit(), 'expected' => $o['creditAfterOrder'] + PRICE]);
    break;

case 'timeout-check':
    $o = loadResult('timeout');
    $suspend = loadResult('timeout-suspend-x');
    expect($suspend['status'] === 409 && codeOf($suspend) === 'in_progress', 'a suspend that cannot get the lock -> 409 in_progress', $suspend);
    expect(($suspend['elapsed'] ?? 0) >= 14, 'after waiting for it', $suspend['elapsed'] ?? null);
    expectLive($o, 'the refused suspend changed nothing');
    terminateOrder((int) $o['orderId']);
    break;

// The connector's Refund button (ModuleCustom, as the admin's button runs it) on a buyer service.
case 'connector-refund':
    $service = newBuyerService();
    $c = localAPI('ModuleCreate', ['serviceid' => $service]);
    expect(($c['result'] ?? '') === 'success' && status($service) === 'Active', 'the buyer service is provisioned through the Hub', $c);
    $o = ['orderId' => (int) prop($service, 'upstreamOrderId')];
    $o['serviceId'] = (int) (hubService($o['orderId'])['id'] ?? 0);
    $o['invoiceId'] = (int) Capsule::table('tblorders')->where('id', $o['orderId'])->value('invoiceid');
    $before = credit();
    $logFrom = lastActivityId();
    $r = localAPI('ModuleCustom', ['serviceid' => $service, 'func_name' => 'Refund']);
    expect(($r['result'] ?? '') === 'success', 'Refund on the buyer service succeeds', $r);
    expect(status($service) === 'Terminated', 'the buyer service is Terminated', status($service));
    expect(prop($service, 'idempotencyKey') === '', 'its idempotency key is cleared, so a later Create buys anew', prop($service, 'idempotencyKey'));
    expectEnded($o, 'upstream');
    expect(abs(credit() - ($before + PRICE)) < 0.001, "the partner's VpnHood credit is back", ['before' => $before, 'after' => credit()]);
    expect(activityLogged($logFrom, "VpnHood Partner: order #{$o['orderId']} refunded"), "the connector logged the refund in the partner's activity log");
    $r = localAPI('ModuleCustom', ['serviceid' => $service, 'func_name' => 'Refund']);
    expect(($r['result'] ?? '') === 'success' && abs(credit() - ($before + PRICE)) < 0.001, 'Refund again succeeds and returns nothing more', [$r, credit()]);
    break;

// The connector against a Hub that predates refunds (refund.test.sh deploys it in between).
case 'connector-old-hub-setup':
    $service = newBuyerService();
    $c = localAPI('ModuleCreate', ['serviceid' => $service]);
    expect(($c['result'] ?? '') === 'success', 'the buyer service is provisioned through the Hub', $c);
    saveResult('old-hub', ['service' => $service, 'credit' => credit()]);
    break;

case 'connector-old-hub':
    $saved = loadResult('old-hub');
    $service = (int) $saved['service'];
    $r = localAPI('ModuleCustom', ['serviceid' => $service, 'func_name' => 'Refund']);
    expect(($r['result'] ?? '') !== 'success' && strpos((string) ($r['message'] ?? ''), 'does not offer refunds yet') !== false,
        'Refund against the older Hub says it offers no refunds', $r);
    expect(status($service) === 'Active' && abs(credit() - (float) $saved['credit']) < 0.001, 'and changes nothing',
        ['status' => status($service), 'credit' => credit()]);
    break;

case 'connector-old-hub-cleanup':
    $service = (int) loadResult('old-hub')['service'];
    $r = localAPI('ModuleTerminate', ['serviceid' => $service]);
    expect(($r['result'] ?? '') === 'success', "cleanup: buyer service #$service terminated (upstream too)", $r);
    break;

default:
    bad("unknown scenario '$scenario'");
}

finish();
