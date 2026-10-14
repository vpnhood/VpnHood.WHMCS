<?php
/**
 * partner-log.test.php — the partner API log (modules/addons/vpnhoodpartnerhub) on the dev WHMCS:
 * a row never keeps a delivered key (PartnerRepository::log masks every access code and CSV batch),
 * and the daily prune (hooks.php) keeps the days PartnerLogRetentionDays names (90 while unset, and
 * while it is not a whole number) and deletes older calls. The setting is a tbladdonmodules row no
 * localAPI command writes: the test sets it and puts it back.
 *
 * Runs ON the dev server (uploaded by partner-log.test.sh with lib/common.php). Writes its own log
 * rows, as the Hub's api.php does, and removes them at the end. It never sets fewer days than the
 * default, so it deletes nothing the daily cron would not.
 */

require __DIR__ . '/lib/common.php';
require_once WEBROOT . '/modules/addons/vpnhoodpartnerhub/hooks.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodPartnerHub\PartnerRepository;

$marker = 'pl' . time();
$savedDays = Capsule::table('tbladdonmodules')->where('module', 'vpnhoodpartnerhub')
    ->where('setting', 'PartnerLogRetentionDays')->value('value');

/** A logged call, this many days old (a minute younger, so the boundary never races the clock). */
function logRow(string $marker, int $ageDays): int
{
    return (int) Capsule::table('mod_vpnhood_partner_log')->insertGetId([
        'partner_id'  => null,
        'action'      => "$marker-$ageDays",
        'remote_ip'   => '203.0.113.20',
        'http_status' => 200,
        'request'     => '{}',
        'response'    => '{}',
        'created_at'  => date('Y-m-d H:i:s', time() - $ageDays * 86400 + 60),
    ]);
}

function kept(int $rowId): bool
{
    return Capsule::table('mod_vpnhood_partner_log')->where('id', $rowId)->exists();
}

/** The request and response the log stored for this action, or null. */
function logged(string $action): ?array
{
    $row = Capsule::table('mod_vpnhood_partner_log')->where('action', $action)->orderBy('id', 'desc')->first();
    return $row === null ? null : ['request' => (string) $row->request, 'response' => (string) $row->response];
}

/** The PartnerLogRetentionDays setting, as the addon's configuration stores it; null removes it. */
function setRetentionDays(?string $value): void
{
    Capsule::table('tbladdonmodules')->where('module', 'vpnhoodpartnerhub')->where('setting', 'PartnerLogRetentionDays')->delete();
    if ($value !== null) {
        Capsule::table('tbladdonmodules')->insert(['module' => 'vpnhoodpartnerhub', 'setting' => 'PartnerLogRetentionDays', 'value' => $value]);
    }
}

try {
    // A response as api.php logs it: an array with a normal key and a CSV batch.
    $repo = new PartnerRepository();
    $code = "CODE-$marker-SECRET";
    $repo->log(null, "$marker-keys", '203.0.113.20', 200, ['action' => 'order'], ['keys' => [
        ['upstreamOrderId' => 1, 'deliveryType' => 'normal', 'accessTokenId' => "token-$marker", 'accessCode' => $code],
        ['upstreamOrderId' => 2, 'deliveryType' => 'csv', 'csv' => "code\n$code-1\n$code-2\n"],
    ]]);
    $keys = logged("$marker-keys");
    ($keys !== null && strpos($keys['response'], $code) === false && strpos($keys['response'], '"accessCode":"[masked]"') !== false
        && strpos($keys['response'], '"csv":"[masked: 3 lines]"') !== false && strpos($keys['response'], "token-$marker") !== false)
        ? ok('a logged response keeps no access code: the code and the batch masked, the token id kept')
        : bad('the logged response: ' . json_encode($keys));

    // A request as api.php logs it on an error: the raw JSON body, and a plain message.
    $repo->log(null, "$marker-raw", '203.0.113.20', 400, json_encode(['action' => 'x', 'accessCode' => $code]), 'A message.');
    $raw = logged("$marker-raw");
    ($raw !== null && strpos($raw['request'], $code) === false && strpos($raw['request'], '"accessCode":"[masked]"') !== false
        && $raw['response'] === 'A message.')
        ? ok('a JSON string body is masked the same way, and a plain message is kept as it is')
        : bad('the logged JSON string body: ' . json_encode($raw));

    setRetentionDays(null);
    $young = logRow($marker, 89);
    $old = logRow($marker, 91);
    vpnhoodpartnerhub_prune_log();
    (kept($young) && !kept($old))
        ? ok('while unset, the prune keeps a call 89 days old and deletes one 91 days old')
        : bad('the prune with the setting unset: ' . json_encode(['89 kept' => kept($young), '91 kept' => kept($old)]));

    setRetentionDays('soon');
    $youngAgain = logRow($marker, 89);
    $oldAgain = logRow($marker, 91);
    vpnhoodpartnerhub_prune_log();
    (kept($youngAgain) && !kept($oldAgain))
        ? ok('a setting that is not a whole number keeps the default')
        : bad('the prune with a setting that is not a whole number: '
            . json_encode(['89 kept' => kept($youngAgain), '91 kept' => kept($oldAgain)]));

    setRetentionDays('120');
    $within = logRow($marker, 119);
    $past = logRow($marker, 121);
    vpnhoodpartnerhub_prune_log();
    (kept($within) && !kept($past))
        ? ok('at 120 days, the prune keeps a call 119 days old and deletes one 121 days old')
        : bad('the prune at 120 days: ' . json_encode(['119 kept' => kept($within), '121 kept' => kept($past)]));
} catch (\Throwable $e) {
    bad('unexpected: ' . $e->getMessage());
} finally {
    setRetentionDays($savedDays === null ? null : (string) $savedDays);
    Capsule::table('mod_vpnhood_partner_log')->where('action', 'like', "$marker-%")->delete();
    ok('fixtures removed, the setting restored');
}

finish();
