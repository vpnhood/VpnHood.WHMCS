<?php

/**
 * VpnHood — refund-abuse memory (web sales only).
 *
 * When WE refund an invoice on this WHMCS, remember an anonymous one-way hash of
 * the refunded client's email so a later refund request can be checked against it
 * ("has this person been refunded before?"). Owner decisions (2026-08-10):
 *
 *  - Written at REFUND time, never at account deletion — deletion keeps no
 *    tombstone, and this table survives deletion on purpose (fraud prevention is
 *    its own legal basis, disclosed in the privacy policy and at refund time).
 *  - WEB sales only. Store purchases (Google Play / App Store) are excluded: the
 *    stores decide their own refunds and run their own abuse detection. Store
 *    refunds arrive on invoices paid by the `vpnhoodiap` gateway, so gateway is
 *    the filter.
 *  - A hash, not the email: sha256 of the lowercased CORE address (alias tricks
 *    collapsed, see vpnhood_refund_memory_core_email). It can answer "seen
 *    before?" but can never be turned back into the person. Retention is capped
 *    at 24 months, pruned opportunistically on every new entry.
 *
 * Honest limit: a new email address defeats it. It is a speed bump against
 * repeat refund abuse, not a wall — accepted for web sales.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * Collapse free alias tricks so one mailbox yields one hash: a "+tag" suffix is a
 * discardable alias on every major provider, and Gmail additionally ignores dots
 * in the local part and serves googlemail.com as the same mailbox. Without this,
 * a single Gmail account mints unlimited distinct addresses that would each look
 * "never refunded before". Any future reader checking against the table must
 * apply this same normalization before hashing.
 */
function vpnhood_refund_memory_core_email(string $email): string
{
    $atPos = strrpos($email, '@');
    if ($atPos === false) {
        return $email;
    }

    $local = explode('+', substr($email, 0, $atPos), 2)[0];
    $domain = substr($email, $atPos + 1);
    if ($domain === 'googlemail.com') {
        $domain = 'gmail.com';
    }
    if ($domain === 'gmail.com') {
        $local = str_replace('.', '', $local);
    }
    return $local . '@' . $domain;
}

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
            'email_hash' => hash('sha256', vpnhood_refund_memory_core_email($email)),
            'invoice_id' => $invoiceId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // retention cap: fraud memory older than 24 months expires
        Capsule::table('mod_vpnhood_refund_memory')
            ->where('created_at', '<', date('Y-m-d H:i:s', strtotime('-24 months')))
            ->delete();
    } catch (\Throwable $e) {
        // memory must never break a refund
        logModuleCall('vpnhood', 'hook.refundMemory', ['invoiceid' => $vars['invoiceid'] ?? null], $e->getMessage(), '');
    }
});
