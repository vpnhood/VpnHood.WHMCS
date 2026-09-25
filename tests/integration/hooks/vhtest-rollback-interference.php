<?php
/**
 * DEV-ONLY test hook. purchase-recovery.test.sh installs it into includes/hooks for its
 * failed-rollback scenario and removes it right after; it never ships.
 *
 * While the flag file exists, cancelling an order moves its client's credit by 0.01 — an
 * outside credit operation landing in the middle of a Hub rollback, which the Hub must
 * detect instead of releasing the key.
 */
if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

add_hook('CancelOrder', 1, function ($vars) {
    if (!is_file('/home/whmcsdev/tmp/vhtest-rollback-interference')) {
        return;
    }
    $clientId = (int) WHMCS\Database\Capsule::table('tblorders')->where('id', (int) ($vars['orderid'] ?? 0))->value('userid');
    if ($clientId > 0) {
        localAPI('AddCredit', ['clientid' => $clientId, 'description' => 'vhtest: credit moved during a rollback', 'amount' => 0.01]);
    }
});
