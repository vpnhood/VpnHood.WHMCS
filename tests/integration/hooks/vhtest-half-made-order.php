<?php
/**
 * DEV-ONLY test hook. purchase-recovery.test.sh installs it into includes/hooks for its
 * half-made-order scenarios and removes it right after; it never ships.
 *
 * While the flag file holds "<client id> <times>", creating an invoice for that client throws, at
 * most <times> times: AddOrder then fails after WHMCS has created the order, its service and its
 * invoice, before the order names that invoice — the state WHMCS 9.1.0's AddOrder left on
 * production.
 */
if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

add_hook('InvoiceCreated', 1, function ($vars) {
    $flag = '/home/whmcsdev/tmp/vhtest-half-made-order';
    if (!is_file($flag)) {
        return;
    }
    [$clientId, $times] = array_map('intval', explode(' ', trim((string) @file_get_contents($flag))) + [1 => 1]);
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    if ($clientId <= 0 || $clientId !== (int) WHMCS\Database\Capsule::table('tblinvoices')->where('id', $invoiceId)->value('userid')) {
        return;
    }
    if ($times > 1) {
        file_put_contents($flag, $clientId . ' ' . ($times - 1));
    } else {
        @unlink($flag);
    }
    throw new \RuntimeException("vhtest: AddOrder failed after creating invoice #{$invoiceId}");
});
