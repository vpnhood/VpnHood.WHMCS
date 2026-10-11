<?php
/**
 * partner-log.test.php — the partner API log's retention (modules/addons/vpnhoodpartnerhub/hooks.php)
 * on the dev WHMCS: the daily prune keeps the days PartnerLogRetentionDays names (90 while unset,
 * and while it is not a whole number) and deletes older calls. The setting is a tbladdonmodules
 * row no localAPI command writes: the test sets it and puts it back.
 *
 * Runs ON the dev server (uploaded by partner-log.test.sh with lib/common.php). Writes its own log
 * rows, as the Hub's api.php does, and removes them at the end. It never sets fewer days than the
 * default, so it deletes nothing the daily cron would not.
 */

require __DIR__ . '/lib/common.php';
require_once WEBROOT . '/modules/addons/vpnhoodpartnerhub/hooks.php';

use WHMCS\Database\Capsule;

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

/** The PartnerLogRetentionDays setting, as the addon's configuration stores it; null removes it. */
function setRetentionDays(?string $value): void
{
    Capsule::table('tbladdonmodules')->where('module', 'vpnhoodpartnerhub')->where('setting', 'PartnerLogRetentionDays')->delete();
    if ($value !== null) {
        Capsule::table('tbladdonmodules')->insert(['module' => 'vpnhoodpartnerhub', 'setting' => 'PartnerLogRetentionDays', 'value' => $value]);
    }
}

try {
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
