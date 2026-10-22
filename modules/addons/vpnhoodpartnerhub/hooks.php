<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/PartnerRepository.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodPartnerHub\LocalApi;
use WHMCS\Module\Addon\VpnHoodPartnerHub\PartnerRepository;
use WHMCS\Module\Addon\VpnHoodPartnerHub\RenewalPolicy;

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
 * PreModuleCreate: a new recurring partner key's first term ends a day after its cycle
 * (RenewalPolicy::firstTermEnd, docs/ARCHITECTURE.md "Renewals"). Set here, before vpnhoodstore
 * creates the token, the token is created with that expiry: paying the order's invoice has moved
 * the due date to the cycle's end by now. A failure aborts the create, so the purchase waits for
 * a person (needs_reconciliation) rather than delivering a key without its day.
 */
function vpnhoodpartnerhub_start_term(array $vars): array
{
    $serviceId = (int) ($vars['params']['serviceid'] ?? 0);
    try {
        if ($serviceId <= 0 || !(new PartnerRepository())->isPartnerProductService($serviceId)) {
            return [];
        }
        require_once __DIR__ . '/lib/ApiException.php';
        require_once __DIR__ . '/lib/LocalApi.php';
        require_once __DIR__ . '/lib/RenewalPolicy.php';
        $service = Capsule::table('tblhosting')->where('id', $serviceId)->first(['regdate', 'nextduedate', 'billingcycle']);
        $months = RenewalPolicy::cycleMonths((string) $service->billingcycle);
        $termEnd = $months > 0 ? RenewalPolicy::firstTermEnd((string) $service->regdate, (string) $service->nextduedate, $months) : null;
        if ($termEnd !== null && (string) $service->nextduedate !== $termEnd) {
            LocalApi::call('UpdateClientProduct', ['serviceid' => $serviceId, 'nextduedate' => $termEnd]);
        }
        return [];
    } catch (\Throwable $e) {
        $error = "service #{$serviceId}'s first term could not be set, so its key was not created: " . $e->getMessage();
        logActivity('VpnHood! Partner Hub: ' . $error);
        return ['abortcmd' => true, 'abortWithError' => $error];
    }
}

add_hook('PreModuleCreate', 1, 'vpnhoodpartnerhub_start_term');

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
