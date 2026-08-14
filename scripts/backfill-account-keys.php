<?php

/**
 * backfill-account-keys.php — one-shot, run ON the WHMCS box (dev or prod):
 *
 *   php scripts/backfill-account-keys.php [--dry-run]
 *
 * Brings existing vpnhoodstore services up to the account-keys era
 * (lifecycle §8 / §12):
 *
 *   1. accessCodeHash — every active single-sale service gets the one-way hash
 *      of its code (fetched live once from MANAGER; the code itself is still
 *      never persisted). Claim-by-code cannot find pre-era services without it.
 *   2. isDefaultKey — a client with EXACTLY ONE active, non-bulk key gets it
 *      marked as their default. A client with several gets nothing: the rule
 *      is ask-never-guess, and their default gets set by their own next
 *      deliberate act (or by support).
 *
 * Idempotent: marked/hashed services are skipped on re-runs.
 */

$webroot = '/home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html';
foreach ([$webroot, getcwd()] as $root) {
    if (file_exists($root . '/init.php')) {
        require_once $root . '/init.php';
        break;
    }
}
if (!defined('WHMCS')) {
    fwrite(STDERR, "could not find WHMCS init.php\n");
    exit(1);
}

use WHMCS\Database\Capsule;

$dryRun = in_array('--dry-run', $argv ?? [], true);
$storeLib = ROOTDIR . '/modules/servers/vpnhoodstore/lib';
require_once $storeLib . '/AsyncApiClientFactory.php';
require_once $storeLib . '/ApiService.php';
$apiService = new \WHMCS\Module\Server\VpnHoodStore\ApiService();

function propertyOf(int $serviceId, string $name): ?string
{
    $value = Capsule::table('tblcustomfieldsvalues as v')
        ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
        ->where('v.relid', $serviceId)->where('f.type', 'product')
        ->whereRaw("LOWER(SUBSTRING_INDEX(f.fieldname, '|', 1)) = ?", [strtolower($name)])
        ->value('v.value');
    return $value === null || $value === '' ? null : (string) $value;
}

$services = Capsule::table('tblhosting as h')
    ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
    ->where('p.servertype', 'vpnhoodstore')
    ->whereIn('h.domainstatus', ['Active', 'Suspended'])
    ->get(['h.id', 'h.userid']);

$hashed = $skippedBulk = $skippedNoToken = 0;
$clientActiveKeys = [];
foreach ($services as $service) {
    $serviceId = (int) $service->id;
    if (propertyOf($serviceId, 'bulkDelivery') === 'yes' || propertyOf($serviceId, 'accessTokenId') === null) {
        propertyOf($serviceId, 'bulkDelivery') === 'yes' ? $skippedBulk++ : $skippedNoToken++;
        continue;
    }
    $clientActiveKeys[(int) $service->userid][] = $serviceId;
    if (propertyOf($serviceId, 'accessCodeHash') !== null) {
        continue;
    }
    $json = json_decode((string) $apiService->getAccessCode(propertyOf($serviceId, 'accessTokenId')));
    $code = (string) ($json->accessToken->accessCode ?? '');
    if ($code === '') {
        echo "!! service #{$serviceId}: MANAGER returned no code — skipped\n";
        continue;
    }
    if (!$dryRun) {
        \WHMCS\Service\Service::find($serviceId)->serviceProperties->save(['accessCodeHash' => hash('sha256', trim($code))]);
    }
    $hashed++;
}

$defaulted = $ambiguous = 0;
foreach ($clientActiveKeys as $clientId => $serviceIds) {
    $hasDefault = false;
    foreach ($serviceIds as $serviceId) {
        if (propertyOf($serviceId, 'isDefaultKey') === 'yes') {
            $hasDefault = true;
            break;
        }
    }
    if ($hasDefault) {
        continue;
    }
    if (count($serviceIds) > 1) {
        $ambiguous++; // several keys, none marked: ask-never-guess — leave it
        continue;
    }
    if (!$dryRun) {
        \WHMCS\Service\Service::find($serviceIds[0])->serviceProperties->save(['isDefaultKey' => 'yes']);
    }
    $defaulted++;
}

echo ($dryRun ? '[dry-run] ' : '')
    . "hashed={$hashed} defaulted={$defaulted} ambiguous-clients={$ambiguous} "
    . "skipped-bulk={$skippedBulk} skipped-no-token={$skippedNoToken}\n";
