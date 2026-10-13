<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Daily refresh of the VpnHood update check.
 *
 * The check itself lives in modules/widgets/vpnhoodupdates.php, which every
 * VpnHood package ships at that same path; this is only its clock. The daily cron
 * is the one place where waiting a few seconds on github.com costs nobody
 * anything — the dashboard widget then renders what this left behind and never
 * makes a request of its own.
 *
 * Every installed VpnHood addon registers this same hook, on purpose: whichever
 * packages an install has, something winds the clock. The cache TTL makes every
 * call after the first one that day a no-op, so the duplicates cost nothing.
 *
 * Best-effort by design: an install with no outbound access to github.com, or a
 * GitHub outage, must cost nothing but a "check failed" line. The daily cron is
 * never allowed to fail over a version check.
 */
add_hook('DailyCronJob', 1, function () {
    try {
        $check = ROOTDIR . '/modules/widgets/vpnhoodupdates.php';
        if (!is_readable($check)) {
            return;
        }
        require_once $check;
        VpnHoodUpdateCheck::refresh();
    } catch (\Throwable $e) {
        logModuleCall('vpnhood', 'hook.update-check', [], $e->getMessage(), '');
    }
});

/**
 * An update takes effect without an admin: WHMCS records an addon's new version only when an
 * admin opens Addon Modules, so every cron run catches up on one it has not recorded
 * (vpnhoodconfig_runPendingUpgrade); up to date, that is one indexed read. A failure is logged
 * and tried again at the next run. The module file is loaded once, guarded, as WHMCS may have
 * loaded it already.
 */
add_hook('AfterCronJob', 1, function () {
    try {
        if (!function_exists('vpnhoodconfig_runPendingUpgrade')) {
            require_once __DIR__ . '/vpnhoodconfig.php';
        }
        vpnhoodconfig_runPendingUpgrade();
    } catch (\Throwable $e) {
        logActivity('VpnHood! MANAGER Configuration: the upgrade failed, and runs again with the next cron: ' . $e->getMessage());
    }
});
