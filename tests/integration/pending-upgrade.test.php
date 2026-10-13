<?php
/**
 * pending-upgrade.test.php — the cron's catch-up of an update (each addon's hooks.php
 * AfterCronJob → <module>_runPendingUpgrade) on the dev WHMCS, for this repo's addons: a recorded
 * version older than the files becomes the files' version with one activity-log line, a second
 * run changes nothing, and a newer one (a rollback's) is recorded as the files' too. WHMCS loads
 * an addon's hooks.php only while the addon is in its AddonModulesHooks list, so that comes first.
 *
 * Runs ON the dev server (uploaded by pending-upgrade.test.sh with lib/common.php). Writes only the
 * addons' own version rows, and leaves each at its files' version, as WHMCS would.
 */

require __DIR__ . '/lib/common.php';

use WHMCS\Database\Capsule;

const MODULES = ['vpnhoodconfig', 'vpnhoodpartnerhub', 'vpnhoodverify'];

function recordedVersion(string $module): ?string
{
    $value = Capsule::table('tbladdonmodules')->where('module', $module)->where('setting', 'version')->value('value');
    return $value === null ? null : (string) $value;
}

function recordVersion(string $module, string $version): void
{
    Capsule::table('tbladdonmodules')->where('module', $module)->where('setting', 'version')->update(['value' => $version]);
}

function logLines(int $afterId, string $description): int
{
    return Capsule::table('tblactivitylog')->where('id', '>', $afterId)->where('description', $description)->count();
}

$hookList = explode(',', (string) Capsule::table('tblconfiguration')->where('setting', 'AddonModulesHooks')->value('value'));

foreach (MODULES as $module) {
    if (recordedVersion($module) === null) {
        bad("$module is not active on the dev install: activate it to test it");
        continue;
    }

    in_array($module, $hookList, true)
        ? ok("$module is in AddonModulesHooks, so WHMCS loads its cron hook")
        : bad("$module is active but not in AddonModulesHooks: WHMCS never loads its hooks.php; Save Changes on any addon in Addon Modules rebuilds the list");

    require_once WEBROOT . "/modules/addons/$module/$module.php";
    $config = ("{$module}_config")();
    $version = (string) $config['version'];
    $marker = (int) Capsule::table('tblactivitylog')->max('id');

    recordVersion($module, '0.0.1');
    ("{$module}_runPendingUpgrade")();
    $line = "{$config['name']} updated from 0.0.1 to $version.";
    (recordedVersion($module) === $version && logLines($marker, $line) === 1)
        ? ok("$module: an older recorded version becomes $version, with one activity-log line")
        : bad("$module after an update: " . json_encode(['recorded' => recordedVersion($module), 'lines' => logLines($marker, $line)]));

    ("{$module}_runPendingUpgrade")();
    (recordedVersion($module) === $version && logLines($marker, $line) === 1)
        ? ok("$module: a second run changes nothing")
        : bad("$module ran the upgrade again: " . json_encode(['recorded' => recordedVersion($module), 'lines' => logLines($marker, $line)]));

    recordVersion($module, '99.0.0');
    ("{$module}_runPendingUpgrade")();
    recordedVersion($module) === $version
        ? ok("$module: a rollback's older files are recorded as $version")
        : bad("$module after a rollback: recorded " . json_encode(recordedVersion($module)));
}

finish();
