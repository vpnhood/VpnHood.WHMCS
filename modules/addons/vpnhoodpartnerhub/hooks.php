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
