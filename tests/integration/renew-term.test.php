<?php
/**
 * renew-term.test.php — a partner key's term (docs/ARCHITECTURE.md, "Renewals"), over the real Hub
 * API as the test partner: a new key's first term ends a day after its cycle, its token at the
 * hour the order was placed; `renew` pays one cycle whenever less than a full cycle is left (the
 * invoice generated on the spot), answers `current` and charges nothing otherwise, and restarts a
 * lapsed term from today — also when the request that paid it died before the restart. Runs ON
 * the dev server, uploaded by renew-term.test.sh with lib/common.php.
 *
 * Moves the key's due date with UpdateClientProduct to stand in for the days a real term takes.
 *
 * ⚠ Spends reseller (test) credit and provisions one real token; the key is terminated at the end.
 */

require __DIR__ . '/lib/common.php';
require_once WEBROOT . '/modules/servers/vpnhoodstore/lib/AsyncApiClientFactory.php';
require_once WEBROOT . '/modules/servers/vpnhoodstore/lib/ApiService.php';
require_once WEBROOT . '/modules/addons/vpnhoodpartnerhub/lib/RenewalPolicy.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodPartnerHub\RenewalPolicy;
use WHMCS\Module\Server\VpnHoodStore\ApiService;

const REF_RECURRING = 'reseller-one-month-premium-code-subscription';
const PRICE         = 1.75;

$reseller = clientByEmail($db, RESELLER_EMAIL);
if (!$reseller) {
    bad('fixtures missing — run tests/bootstrap/init-skeleton.sh first');
    finish();
}
$clientId = (int) $reseller['id'];
$run = getenv('RUN') ?: (string) time();

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

function credit(): float
{
    global $clientId;
    return round((float) Capsule::table('tblclients')->where('id', $clientId)->value('credit'), 2);
}

function service(int $serviceId): object
{
    return Capsule::table('tblhosting')->where('id', $serviceId)->first();
}

function setDueDate(int $serviceId, string $date): void
{
    $r = localAPI('UpdateClientProduct', ['serviceid' => $serviceId, 'nextduedate' => $date]);
    expect(($r['result'] ?? '') === 'success' && (string) service($serviceId)->nextduedate === $date,
        "fixture: the key is due $date", $r);
}

/** Paid and unpaid renewal invoices of the key, besides its order's invoice. */
function renewalInvoices(int $serviceId, int $orderInvoiceId): array
{
    return Capsule::table('tblinvoiceitems as i')->join('tblinvoices as inv', 'inv.id', '=', 'i.invoiceid')
        ->where('i.type', 'Hosting')->where('i.relid', $serviceId)->where('inv.id', '!=', $orderInvoiceId)
        ->orderBy('inv.id')->pluck('inv.status', 'inv.id')->all();
}

/** The token's expiry on the access server is the due date at the hour the order was placed. */
function expectTokenAtDue(int $serviceId, string $what): void
{
    $service = service($serviceId);
    $tokenId = (string) serviceProperty($GLOBALS['db'], $serviceId, 'accessTokenId');
    $token = json_decode((new ApiService())->getAccessCode($tokenId), true)['accessToken'] ?? [];
    $orderedAt = (string) Capsule::table('tblorders')->where('id', (int) $service->orderid)->value('date');
    $want = strtotime($service->nextduedate . ' ' . date('H:i:s', strtotime($orderedAt)));
    $got = strtotime((string) ($token['expirationTime'] ?? '') . (preg_match('/(Z|[+-]\d\d:\d\d)$/', (string) ($token['expirationTime'] ?? '')) ? '' : 'Z'));
    expect($got === $want, "$what: the token expires on {$service->nextduedate} at the order's hour",
        ['token' => $token['expirationTime'] ?? null, 'want' => gmdate('c', $want)]);
}

function expectRenew(int $orderId, string $status, string $what): array
{
    $r = hub('renew', ['upstreamOrderId' => $orderId]);
    expect($r['status'] === 200 && ($r['body']['data']['status'] ?? '') === $status, "$what -> 200 $status", $r);
    return $r;
}

$orderId = 0;
try {
    // ---- 1. a new key: the first term ends a day after its cycle -------------------------
    $key = "term-$run";
    $creditBefore = credit();
    $r = hub('order', ['downstreamRef' => REF_RECURRING, 'customerReference' => $key, 'idempotencyKey' => $key]);
    // a 409 for an order that was placed still names it, so the cleanup ends it
    $orderId = (int) ($r['body']['data']['keys'][0]['upstreamOrderId'] ?? $r['body']['details']['upstreamOrderId'] ?? 0);
    if (!expect($r['status'] === 200, "order $key placed", $r['status'] === 200 ? null : $r)) {
        finish();
    }
    $hosting = Capsule::table('tblhosting')->where('orderid', $orderId)->where('userid', $clientId)->first();
    if ($hosting === null) {
        bad('no service behind the order');
        finish();
    }
    $serviceId = (int) $hosting->id;
    $orderInvoiceId = (int) Capsule::table('tblorders')->where('id', $orderId)->value('invoiceid');
    expect(abs($creditBefore - credit() - PRICE) < 0.001, 'the order charged ' . PRICE . ' once', [$creditBefore, credit()]);
    $firstEnd = RenewalPolicy::nextDay(RenewalPolicy::addMonths((string) $hosting->regdate, 1));
    expect((string) $hosting->nextduedate === $firstEnd, "the first term ends a day after the cycle ($firstEnd)",
        ['regdate' => $hosting->regdate, 'nextduedate' => $hosting->nextduedate]);
    expect((string) $hosting->nextinvoicedate === (string) $hosting->nextduedate, 'the next invoice date moved with it',
        ['nextinvoicedate' => $hosting->nextinvoicedate]);
    expectTokenAtDue($serviceId, 'the new key');

    // ---- 2. renewing a key with a full cycle ahead charges nothing ------------------------
    $creditBefore = credit();
    $r = expectRenew($orderId, 'current', 'renew right after the purchase');
    expect(($r['body']['data']['nextDueDate'] ?? '') === $firstEnd && abs(credit() - $creditBefore) < 0.001
        && renewalInvoices($serviceId, $orderInvoiceId) === [], '…no charge, no invoice, the term unchanged',
        [$r['body']['data'] ?? null, credit(), renewalInvoices($serviceId, $orderInvoiceId)]);

    // ---- 3. the store renews on the term's last day: one cycle, the invoice made on the spot ----
    $lastDay = date('Y-m-d', time() + 86400);
    setDueDate($serviceId, $lastDay);
    $creditBefore = credit();
    $r = expectRenew($orderId, 'renewed', 'renew on the last day');
    $want = RenewalPolicy::addMonths($lastDay, 1);
    expect(($r['body']['data']['nextDueDate'] ?? '') === $want && abs($creditBefore - credit() - PRICE) < 0.001,
        "…charged " . PRICE . " once, the term moved one cycle to $want", [$r['body']['data'] ?? null, $creditBefore, credit()]);
    expect(array_values(renewalInvoices($serviceId, $orderInvoiceId)) === ['Paid'], '…its renewal invoice is Paid',
        renewalInvoices($serviceId, $orderInvoiceId));
    expectTokenAtDue($serviceId, 'after the renewal');

    // ---- 4. never two cycles: the repeat charges nothing -------------------------------
    $creditBefore = credit();
    expectRenew($orderId, 'current', 'renew again at once');
    expect(abs(credit() - $creditBefore) < 0.001 && count(renewalInvoices($serviceId, $orderInvoiceId)) === 1,
        '…no second charge, no second invoice', [credit(), renewalInvoices($serviceId, $orderInvoiceId)]);

    // ---- 5. early: 20 days left, outside the Invoice Generation window ---------------------
    $early = date('Y-m-d', time() + 20 * 86400);
    setDueDate($serviceId, $early);
    $creditBefore = credit();
    $r = expectRenew($orderId, 'renewed', 'renew with 20 days left');
    expect(($r['body']['data']['nextDueDate'] ?? '') === RenewalPolicy::addMonths($early, 1) && abs($creditBefore - credit() - PRICE) < 0.001,
        '…charged once, one cycle on', [$r['body']['data'] ?? null, $creditBefore, credit()]);

    // ---- 6. a lapsed term restarts today --------------------------------------------
    setDueDate($serviceId, date('Y-m-d', time() - 3 * 86400));
    $creditBefore = credit();
    $r = expectRenew($orderId, 'renewed', 'renew a term that ended 3 days ago');
    $restarted = RenewalPolicy::nextDay(RenewalPolicy::addMonths(date('Y-m-d'), 1));
    expect(($r['body']['data']['nextDueDate'] ?? '') === $restarted && abs($creditBefore - credit() - PRICE) < 0.001,
        "…charged once, the term restarted today and ends $restarted", [$r['body']['data'] ?? null, $creditBefore, credit()]);
    $after = service($serviceId);
    expect((string) $after->nextinvoicedate === (string) $after->nextduedate, '…the next invoice date moved with it',
        ['nextduedate' => $after->nextduedate, 'nextinvoicedate' => $after->nextinvoicedate]);
    expectTokenAtDue($serviceId, 'after the restart');
    expectRenew($orderId, 'current', 'and once more right away');

    // ---- 7. a request that paid a lapsed renewal and died before restarting it -------------
    // Paid here the way the Hub pays (credit, in full), without the restart that follows.
    $lapsed = date('Y-m-d', time() - 5 * 86400);
    setDueDate($serviceId, $lapsed);
    localAPI('GenInvoices', ['serviceids' => [$serviceId], 'noemails' => true]);
    $unpaid = array_keys(renewalInvoices($serviceId, $orderInvoiceId), 'Unpaid', true);
    if (!expect(count($unpaid) === 1, 'fixture: one renewal invoice for the lapsed term', renewalInvoices($serviceId, $orderInvoiceId))) {
        finish();
    }
    payInvoiceFromCredit($db, $unpaid[0], $clientId);
    expect((string) service($serviceId)->nextduedate === RenewalPolicy::addMonths($lapsed, 1),
        'fixture: paid, WHMCS advanced the term from its old due date', ['nextduedate' => service($serviceId)->nextduedate]);
    $creditBefore = credit();
    $r = expectRenew($orderId, 'current', 'the next renew');
    expect(($r['body']['data']['nextDueDate'] ?? '') === $restarted && abs(credit() - $creditBefore) < 0.001,
        "…finishes the restart ($restarted) and charges nothing", [$r['body']['data'] ?? null, $creditBefore, credit()]);
    expectTokenAtDue($serviceId, 'after the finished restart');
} finally {
    if ($orderId > 0) {
        $r = hub('terminate', ['upstreamOrderId' => $orderId]);
        expect($r['status'] === 200, "cleanup: order #$orderId terminated", $r['status'] === 200 ? null : $r);
    }
}

finish();
