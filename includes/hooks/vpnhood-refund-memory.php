<?php

/**
 * VpnHood — refund memory: full website refunds, and the warning before the next one.
 *
 * A person refunded on the website once and asking again should not get a second refund
 * unnoticed. When an invoice is refunded in full (vpnhoodstore_isRefundedInFull: WHMCS marked
 * it Refunded, or the refunds booked against it reach its total), the refunded client's
 * address is remembered as a one-way hash with the date, for 24 months. When an admin opens a
 * Paid invoice — the only kind WHMCS offers to refund — the page warns if the client's address
 * is remembered. The warning informs and blocks nothing: WHMCS has no hook that runs before a
 * refund and can stop it, so the decision stays with the admin.
 *
 * Not recorded: a partial refund (goodwill, lifecycle §8), and a refund of an app-store
 * invoice (the `vpnhoodiappay` gateway: the store decides those refunds and keeps its own
 * records). One row per address, carrying the date of its last full refund; the daily cron
 * drops rows older than 24 months.
 *
 * The hash is the sha256 of the folded address (vpnhood_refund_memory_hash): it can answer
 * "refunded before?" but cannot be turned back into the address, and it survives the
 * client's deletion, which is its point. The hook functions are named, not closures, so
 * tests/integration/refund-memory.test.php can drive them without a refund of its own.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * One mailbox, one hash. Case and surrounding space never distinguish two people; a "+tag"
 * suffix is a free alias on every major provider; and Gmail ignores dots in the local part
 * and serves googlemail.com as the same mailbox. Without the folding, a single Gmail account
 * mints unlimited addresses that each look never refunded.
 */
function vpnhood_refund_memory_hash(string $email): string
{
    $email = strtolower(trim($email));
    $atPos = strrpos($email, '@');
    if ($atPos !== false) {
        $local = explode('+', substr($email, 0, $atPos), 2)[0];
        $domain = substr($email, $atPos + 1);
        if ($domain === 'googlemail.com') {
            $domain = 'gmail.com';
        }
        if ($domain === 'gmail.com') {
            $local = str_replace('.', '', $local);
        }
        $email = $local . '@' . $domain;
    }
    return hash('sha256', $email);
}

/** The client's address, or null when there is no person left to remember (a deleted client is anonymized). */
function vpnhood_refund_memory_client_email(int $clientId): ?string
{
    $email = strtolower(trim((string) Capsule::table('tblclients')->where('id', $clientId)->value('email')));
    return $email === '' || str_ends_with($email, '@anonymized.invalid') ? null : $email;
}

/**
 * The table, created on first use. Migration (2026-10): drop a few months after it ships. The
 * first shape kept one row per refunded invoice, store and partial refunds included; the rows
 * that still count (a website invoice, refunded in full) fold into one row per address, so
 * refunds recorded before this shape still warn. Rows went in as the refunds happened, so an
 * address's highest id carries its last refund's date.
 */
function vpnhood_refund_memory_prepare(): void
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_vpnhood_refund_memory')) {
        $schema->create('mod_vpnhood_refund_memory', function ($table) {
            $table->increments('id');
            $table->string('email_hash', 64)->unique();
            $table->timestamp('refunded_at')->nullable()->index();
        });
        return;
    }
    if (!$schema->hasColumn('mod_vpnhood_refund_memory', 'invoice_id')) {
        return;
    }
    foreach (Capsule::table('mod_vpnhood_refund_memory')->get() as $row) {
        $gateway = Capsule::table('tblinvoices')->where('id', (int) $row->invoice_id)->value('paymentmethod');
        if ($gateway === null || (string) $gateway === 'vpnhoodiappay' || !vpnhoodstore_isRefundedInFull((int) $row->invoice_id)) {
            Capsule::table('mod_vpnhood_refund_memory')->where('id', $row->id)->delete();
        }
    }
    Capsule::statement('DELETE a FROM mod_vpnhood_refund_memory a JOIN mod_vpnhood_refund_memory b'
        . ' ON a.email_hash = b.email_hash AND a.id < b.id');
    $schema->table('mod_vpnhood_refund_memory', function ($table) {
        $table->dropUnique(['invoice_id']);
        $table->dropColumn('invoice_id');
        $table->dropIndex(['email_hash']);
        $table->unique('email_hash');
    });
    Capsule::statement('ALTER TABLE mod_vpnhood_refund_memory CHANGE created_at refunded_at TIMESTAMP NULL');
}

/** InvoiceRefunded: remember the address once the whole sale of a website invoice is undone. */
function vpnhood_refund_memory_record(array $vars): void
{
    try {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        $invoice = $invoiceId > 0
            ? Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['userid', 'paymentmethod'])
            : null;
        if ($invoice === null || (string) $invoice->paymentmethod === 'vpnhoodiappay' || !vpnhoodstore_isRefundedInFull($invoiceId)) {
            return;
        }
        $email = vpnhood_refund_memory_client_email((int) $invoice->userid);
        if ($email === null) {
            return;
        }
        vpnhood_refund_memory_prepare();
        Capsule::table('mod_vpnhood_refund_memory')->updateOrInsert(
            ['email_hash' => vpnhood_refund_memory_hash($email)],
            ['refunded_at' => date('Y-m-d H:i:s')]
        );
    } catch (\Throwable $e) {
        // the memory must never break a refund
        logModuleCall('vpnhood', 'hook.refund-memory', $vars, $e->getMessage(), '');
    }
}

/**
 * AdminInvoicesControlsOutput: the warning on a Paid website invoice whose client's address
 * was refunded in full before. WHMCS puts the hook's output inside the Options tab of the
 * invoice page; the script moves the box above the tabs, so it is in view whichever tab the
 * admin opens, the Refund tab included. Returns '' when there is nothing to say.
 */
function vpnhood_refund_memory_warning(array $vars): string
{
    try {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        $invoice = $invoiceId > 0
            ? Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['userid', 'paymentmethod', 'status'])
            : null;
        if ($invoice === null || (string) $invoice->status !== 'Paid' || (string) $invoice->paymentmethod === 'vpnhoodiappay') {
            return '';
        }
        $email = vpnhood_refund_memory_client_email((int) $invoice->userid);
        if ($email === null) {
            return '';
        }
        vpnhood_refund_memory_prepare();
        $refundedAt = Capsule::table('mod_vpnhood_refund_memory')
            ->where('email_hash', vpnhood_refund_memory_hash($email))->value('refunded_at');
        if ($refundedAt === null) {
            return '';
        }
        return '<div id="vpnhoodRefundMemory" class="alert alert-warning" style="margin:10px 0">'
            . '<i class="fas fa-exclamation-triangle"></i> <strong>Refunded before:</strong> this email address got a full'
            . ' website refund on ' . htmlspecialchars(fromMySQLDate((string) $refundedAt)) . '.</div>'
            . '<script>(function () {'
            . 'var box = document.getElementById("vpnhoodRefundMemory");'
            . 'var tabs = box && box.closest(".tab-content");'
            . 'var nav = tabs && tabs.parentNode.querySelector("ul.nav-tabs");'
            . 'if (nav) { nav.parentNode.insertBefore(box, nav); }'
            . '})();</script>';
    } catch (\Throwable $e) {
        // a broken warning must never break the invoice page
        logModuleCall('vpnhood', 'hook.refund-warning', $vars, $e->getMessage(), '');
        return '';
    }
}

/** DailyCronJob: the memory keeps exactly what the privacy policy discloses, 24 months. */
function vpnhood_refund_memory_prune(): void
{
    try {
        vpnhood_refund_memory_prepare();
        Capsule::table('mod_vpnhood_refund_memory')
            ->where('refunded_at', '<', date('Y-m-d H:i:s', strtotime('-24 months')))
            ->delete();
    } catch (\Throwable $e) {
        logModuleCall('vpnhood', 'hook.refund-memory-prune', [], $e->getMessage(), '');
    }
}

add_hook('InvoiceRefunded', 1, 'vpnhood_refund_memory_record');
add_hook('AdminInvoicesControlsOutput', 1, 'vpnhood_refund_memory_warning');
add_hook('DailyCronJob', 1, 'vpnhood_refund_memory_prune');
