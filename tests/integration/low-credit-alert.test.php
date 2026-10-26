<?php
/**
 * low-credit-alert.test.php — the low-credit email (addon README, "Low-credit alert"), over the real
 * Hub API as the test partner: the threshold is 100 while the setting is unset, off at 0; the
 * order that takes the credit below it emails the partner once; the orders after it send nothing;
 * a top-up back to the threshold arms it again; a renewal crossing it sends too; a slow mail
 * server does not delay the answer; without the template the order still succeeds and the activity
 * log says the email was not sent. Runs ON the dev server, uploaded by low-credit-alert.test.sh
 * with lib/common.php, which also installs the slow-mail hook (hooks/vhtest-slow-mail.php).
 *
 * Writes outside the API, each undone in a finally: the LowCreditThreshold setting, the template's
 * name (renamed for one step), the slow-mail flag file, a key's due date (UpdateClientProduct), and
 * one credit top-up (AddCredit), which stays.
 *
 * ⚠ Spends reseller (test) credit and provisions real tokens; every key is terminated at the end.
 */

require __DIR__ . '/lib/common.php';
require_once WEBROOT . '/modules/addons/vpnhoodpartnerhub/lib/PartnerRepository.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodPartnerHub\LowCreditAlert;
use WHMCS\Module\Addon\VpnHoodPartnerHub\PartnerRepository;

const REF_RECURRING = 'reseller-one-month-premium-code-subscription';
const PRICE         = 1.75;
const QUIET_SECONDS = 5; // how long a step that sends nothing is watched
const SLOW_MAIL_FLAG = '/home/whmcsdev/tmp/vhtest-slow-mail'; // while it exists, the hook delays the email
const SLOW_MAIL_SECONDS = 10;

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

function money(float $amount): string
{
    return number_format($amount, 2, '.', '');
}

/** The LowCreditThreshold setting, as the addon's configuration page saves it (null = never saved). */
function threshold(): ?string
{
    $value = Capsule::table('tbladdonmodules')->where('module', 'vpnhoodpartnerhub')->where('setting', 'LowCreditThreshold')->value('value');
    return $value === null ? null : (string) $value;
}

function setThreshold(?string $value): void
{
    $row = Capsule::table('tbladdonmodules')->where('module', 'vpnhoodpartnerhub')->where('setting', 'LowCreditThreshold');
    if ($value === null) {
        $row->delete();
    } elseif ((clone $row)->exists()) {
        $row->update(['value' => $value]);
    } else {
        Capsule::table('tbladdonmodules')->insert(['module' => 'vpnhoodpartnerhub', 'setting' => 'LowCreditThreshold', 'value' => $value]);
    }
}

/** Where the email and activity logs stand now; a step looks only at what comes after. */
function mark(): array
{
    return [(int) Capsule::table('tblemails')->max('id'), (int) Capsule::table('tblactivitylog')->max('id')];
}

function alertEmails(array $mark): array
{
    global $clientId, $subject;
    return Capsule::table('tblemails')->where('id', '>', $mark[0])->where('userid', $clientId)->where('subject', $subject)
        ->orderBy('id')->get(['id', 'to', 'message'])->all();
}

function alertLines(array $mark): array
{
    global $clientId;
    return Capsule::table('tblactivitylog')->where('id', '>', $mark[1])
        ->where('description', 'like', "Partner Hub: a payment took the credit of client #$clientId %")
        ->orderBy('id')->pluck('description')->all();
}

/** The email goes out after the answer: wait for its activity-log line, or watch a quiet step for a while. */
function settle(array $mark, bool $expectLine): void
{
    $deadline = microtime(true) + ($expectLine ? 30 : QUIET_SECONDS);
    while (microtime(true) < $deadline && !($expectLine && alertLines($mark) !== [])) {
        usleep(250000);
    }
}

function order(string $tag): array
{
    global $run, $orders, $clientId;
    $key = "low-credit-$tag-$run";
    $r = hub('order', ['downstreamRef' => REF_RECURRING, 'customerReference' => $key, 'idempotencyKey' => $key]);
    // a 409 for an order that was placed still names it, so the cleanup ends it
    $orderId = (int) ($r['body']['data']['keys'][0]['upstreamOrderId'] ?? $r['body']['details']['upstreamOrderId'] ?? 0);
    if ($orderId > 0) {
        $orders[] = $orderId;
    }
    expect($r['status'] === 200, "order $key placed", $r['status'] === 200 ? null : $r);
    $serviceId = (int) Capsule::table('tblhosting')->where('orderid', $orderId)->where('userid', $clientId)->value('id');
    return ['orderId' => $orderId, 'serviceId' => $serviceId];
}

/** One alert for this step, naming the balance after the payment and the threshold. */
function expectOneAlert(array $mark, float $balance, float $threshold, string $what): void
{
    global $clientId;
    settle($mark, true);
    $emails = alertEmails($mark);
    $lines = alertLines($mark);
    expect(count($emails) === 1, "$what: one low-credit email", count($emails));
    if ($emails !== []) {
        $message = (string) $emails[0]->message;
        $currencyId = (int) Capsule::table('tblclients')->where('id', $clientId)->value('currency');
        $want = [(string) formatCurrency($balance, $currencyId), (string) formatCurrency($threshold, $currencyId)];
        expect(strpos($message, $want[0]) !== false && strpos($message, $want[1]) !== false,
            "…it names the balance {$want[0]} and the threshold {$want[1]}", strip_tags($message));
        expect(stripos((string) $emails[0]->to, RESELLER_EMAIL) !== false, '…sent to the partner\'s client', (string) $emails[0]->to);
    }
    expect(count($lines) === 1 && strpos($lines[0], 'the low-credit email was sent.') !== false
        && strpos($lines[0], 'to ' . money($balance) . ',') !== false, '…and one activity-log line says it was sent', $lines);
}

function expectNoAlert(array $mark, string $what): void
{
    settle($mark, false);
    expect(alertEmails($mark) === [] && alertLines($mark) === [], "$what: no low-credit email", [alertEmails($mark), alertLines($mark)]);
}

$template = Capsule::table('tblemailtemplates')->where('name', LowCreditAlert::TEMPLATE)->where('language', '')->first(['id', 'subject', 'type', 'disabled']);
if (!expect($template !== null && $template->type === 'general' && !$template->disabled, 'the General template "' . LowCreditAlert::TEMPLATE . '" exists, enabled',
    $template)) {
    finish(); // an install activated before the alert creates it once: README, "Low-credit alert"
}
$subject = (string) $template->subject;
$orders = [];
$savedThreshold = threshold();

try {
    // ---- 1. never saved, the Hub reads the default; threshold 0 is off --------------------
    setThreshold(null);
    expect((new PartnerRepository())->settings()['lowCreditThreshold'] === LowCreditAlert::DEFAULT_THRESHOLD,
        'with the setting never saved, the threshold is ' . money(LowCreditAlert::DEFAULT_THRESHOLD));
    setThreshold('0');
    $mark = mark();
    order('off');
    expectNoAlert($mark, 'with threshold 0, an order');

    // ---- 2. the order that crosses the threshold alerts once ------------------------------
    $threshold = credit() - 1.00;
    setThreshold(money($threshold));
    $before = credit();
    $mark = mark();
    order('cross');
    expectOneAlert($mark, $before - PRICE, $threshold, 'an order from ' . money($before) . ' to below ' . money($threshold));

    // ---- 3. already below: the orders after it send nothing -------------------------------
    $mark = mark();
    order('below');
    expectNoAlert($mark, 'the next order, already below');

    // ---- 4. a top-up back to the threshold arms it again ------------------------------------
    $topUp = round($threshold - credit(), 2);
    $r = localAPI('AddCredit', ['clientid' => $clientId, 'description' => "low-credit-alert test top-up $run", 'amount' => $topUp]);
    expect(($r['result'] ?? '') === 'success' && abs(credit() - $threshold) < 0.001, "fixture: topped up $topUp, the credit is at the threshold", $r);
    $mark = mark();
    $again = order('again');
    expectOneAlert($mark, $threshold - PRICE, $threshold, 'after the top-up, the next order');

    // ---- 5. a renewal that crosses it alerts too ----------------------------------------
    $r = localAPI('UpdateClientProduct', ['serviceid' => $again['serviceId'], 'nextduedate' => date('Y-m-d', time() + 86400)]);
    expect(($r['result'] ?? '') === 'success', 'fixture: the key is due tomorrow', $r);
    $threshold = credit() - 1.00;
    setThreshold(money($threshold));
    $before = credit();
    $mark = mark();
    $r = hub('renew', ['upstreamOrderId' => $again['orderId']]);
    expect($r['status'] === 200 && ($r['body']['data']['status'] ?? '') === 'renewed', 'renew on the last day -> 200 renewed', $r);
    expectOneAlert($mark, $before - PRICE, $threshold, 'the renewal');

    // ---- 6. a slow mail server does not delay the answer --------------------------------
    // (exit skips a finally, so a failed fixture inside this try skips its step instead of finish())
    if (expect(is_file(WEBROOT . '/includes/hooks/vhtest-slow-mail.php') && touch(SLOW_MAIL_FLAG), 'fixture: mail takes '
        . SLOW_MAIL_SECONDS . ' s longer (the slow-mail hook)')) {
        $threshold = credit() - 1.00;
        setThreshold(money($threshold));
        $before = credit();
        $mark = mark();
        $started = microtime(true);
        order('slow-mail');
        $answeredIn = round(microtime(true) - $started, 1);
        expect($answeredIn < SLOW_MAIL_SECONDS && alertLines($mark) === [], "the order that crossed answered in $answeredIn s, before its email",
            alertLines($mark));
        expectOneAlert($mark, $before - PRICE, $threshold, '…and the email went out after it');
        unlink(SLOW_MAIL_FLAG);
    }

    // ---- 7. without the template, the order still goes through; the log says so --------------
    Capsule::table('tblemailtemplates')->where('id', $template->id)->update(['name' => LowCreditAlert::TEMPLATE . " (test $run)"]);
    $threshold = credit() - 1.00;
    setThreshold(money($threshold));
    $mark = mark();
    order('no-template');
    settle($mark, true);
    $lines = alertLines($mark);
    expect(alertEmails($mark) === [] && count($lines) === 1 && strpos($lines[0], 'the low-credit email was NOT sent: ') !== false,
        'with the template gone: no email, and the activity log says it was not sent', $lines);
} finally {
    if (is_file(SLOW_MAIL_FLAG)) {
        unlink(SLOW_MAIL_FLAG);
    }
    Capsule::table('tblemailtemplates')->where('id', $template->id)->update(['name' => LowCreditAlert::TEMPLATE]);
    setThreshold($savedThreshold);
    foreach ($orders as $orderId) {
        $r = hub('terminate', ['upstreamOrderId' => $orderId]);
        expect($r['status'] === 200, "cleanup: order #$orderId terminated", $r['status'] === 200 ? null : $r);
    }
}

finish();
