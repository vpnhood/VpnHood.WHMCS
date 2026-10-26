<?php
/**
 * low-credit-alert.test.php — unit tests of LowCreditAlert's rules: which Hub payment emails a
 * partner about low credit (README, "Low-credit alert"), and how its setting reads. Pure PHP, no
 * WHMCS and no database: run.sh runs it with the dev box's PHP, since there is none locally.
 *
 * Usage: php low-credit-alert.test.php <path of LowCreditAlert.php>
 */

error_reporting(E_ALL);
require $argv[1] ?? __DIR__ . '/../../modules/addons/vpnhoodpartnerhub/lib/LowCreditAlert.php';

use WHMCS\Module\Addon\VpnHoodPartnerHub\LowCreditAlert;

$pass = 0;
$fail = 0;

function check(bool $condition, string $message): void
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS $message\n";
        return;
    }
    $fail++;
    echo "FAIL $message\n";
}

check(LowCreditAlert::crosses(50, 60, 40), 'a payment from above the threshold to below it alerts');
check(LowCreditAlert::crosses(50, 50, 48.25), 'from exactly the threshold to below it alerts');
check(LowCreditAlert::crosses(50, 50, 49.99), 'a cent below is below');
check(!LowCreditAlert::crosses(50, 60, 50), 'ending exactly at the threshold is not below it');
check(!LowCreditAlert::crosses(50, 60, 55), 'staying above it does not alert');
check(!LowCreditAlert::crosses(50, 45, 40), 'already below it: the alert went out with the payment that crossed');
check(!LowCreditAlert::crosses(50, 49.99, 0), 'a cent below, down to nothing: no second alert');
check(LowCreditAlert::crosses(50, 500, 0), 'one payment from far above down to nothing alerts');
check(!LowCreditAlert::crosses(0, 60, 0), 'threshold 0 is off');
check(!LowCreditAlert::crosses(-5, 60, -10), 'a negative threshold is off');
check(!LowCreditAlert::crosses(50, 50.004, 49.996), 'amounts count in cents: 49.996 is 50.00, not below');

// -- the setting -----------------------------------------------------------------------
check(LowCreditAlert::threshold(null) === 100.0, 'never saved: the default, 100');
check(LowCreditAlert::threshold('  ') === 100.0, 'blank: the default');
check(LowCreditAlert::threshold('250') === 250.0, 'an amount is the threshold');
check(LowCreditAlert::threshold(' 49.5 ') === 49.5, 'with cents, spaces trimmed');
check(LowCreditAlert::threshold('0') === 0.0, '0 turns it off');
check(LowCreditAlert::threshold('-20') === 0.0, 'a negative amount is off');
check(LowCreditAlert::threshold('100 USD') === 0.0, 'anything but an amount is off, as the refund window reads its days');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
