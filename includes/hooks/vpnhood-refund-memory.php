<?php

/**
 * VpnHood — refund memory (web sales only).
 *
 * When an invoice is refunded, records a one-way fingerprint of the refunded
 * client's email for the retention period (24 months), so a repeat refund can be
 * recognised. The fingerprint is the sha256 of the lowercased core address (alias
 * tricks collapsed, see vpnhood_refund_memory_core_email): it can answer "seen
 * before?" but cannot be turned back into the address. Expired rows are pruned on
 * every new entry.
 *
 * Invoices paid by the `vpnhoodiap` gateway are app-store purchases (Google Play /
 * App Store), whose refunds the stores handle, so they are skipped.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

add_hook('InvoiceRefunded', 1, function (array $vars) {
    try {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            return;
        }

        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['userid', 'paymentmethod']);
        if ($invoice === null) {
            return;
        }
        // store-billed invoices are the stores' own refund domain — out of scope
        if ((string) $invoice->paymentmethod === 'vpnhoodiap') {
            return;
        }

        $email = strtolower(trim((string) Capsule::table('tblclients')
            ->where('id', (int) $invoice->userid)->value('email')));
        // no address, or an already-anonymized (deleted) client — nothing meaningful to remember
        if ($email === '' || str_ends_with($email, '@anonymized.invalid')) {
            return;
        }

        $schema = Capsule::schema();
        if (!$schema->hasTable('mod_vpnhood_refund_memory')) {
            $schema->create('mod_vpnhood_refund_memory', function ($table) {
                $table->increments('id');
                $table->string('email_hash', 64)->index();
                $table->integer('invoice_id')->unsigned()->unique(); // partial-refund replays collapse to one row
                $table->timestamp('created_at')->nullable()->index();
            });
        }

        Capsule::table('mod_vpnhood_refund_memory')->insertOrIgnore([
            'email_hash' => hash('sha256', $email),
            'invoice_id' => $invoiceId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // fingerprints older than the retention period expire
        Capsule::table('mod_vpnhood_refund_memory')
            ->where('created_at', '<', date('Y-m-d H:i:s', strtotime('-24 months')))
            ->delete();
    } catch (\Throwable $e) {
        // memory must never break a refund
        logModuleCall('vpnhood', 'hook.refundMemory', ['invoiceid' => $vars['invoiceid'] ?? null], $e->getMessage(), '');
    }
});
