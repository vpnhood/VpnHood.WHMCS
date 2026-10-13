<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/PartnerRepository.php';

use WHMCS\Module\Addon\VpnHoodPartnerHub\PartnerRepository;

/** DailyCronJob: the partner API log keeps the days PartnerLogRetentionDays names (90 while unset). */
function vpnhoodpartnerhub_prune_log(): void
{
    try {
        (new PartnerRepository())->pruneLog();
    } catch (\Throwable $e) {
        logModuleCall('vpnhoodpartnerhub', 'hook.log-prune', [], $e->getMessage(), '');
    }
}

add_hook('DailyCronJob', 1, 'vpnhoodpartnerhub_prune_log');

/**
 * An update takes effect without an admin: WHMCS records an addon's new version only when an
 * admin opens Addon Modules, so every cron run catches up on one it has not recorded
 * (vpnhoodpartnerhub_runPendingUpgrade); up to date, that is one indexed read. A failure is
 * logged and tried again at the next run. The module file is loaded once, guarded, as WHMCS may
 * have loaded it already.
 */
add_hook('AfterCronJob', 1, function () {
    try {
        if (!function_exists('vpnhoodpartnerhub_runPendingUpgrade')) {
            require_once __DIR__ . '/vpnhoodpartnerhub.php';
        }
        vpnhoodpartnerhub_runPendingUpgrade();
    } catch (\Throwable $e) {
        logActivity('VpnHood! Partner Hub: the upgrade failed, and runs again with the next cron: ' . $e->getMessage());
    }
});
