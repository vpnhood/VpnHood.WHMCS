<?php

/**
 * VpnHood Store — a FULL refund revokes the key by default (lifecycle §8).
 *
 * When an invoice is refunded in full, every vpnhoodstore service billed on it is
 * terminated, so the money and the service go back together. Two deliberate
 * exceptions, both straight out of §8:
 *
 *  - *Refund and keep* — the goodwill gesture: set the `keepOnRefund` service
 *    property to `yes` before refunding and the key is left running to its
 *    original expiry, with the decision logged.
 *  - A PARTIAL refund never revokes. WHMCS fires InvoiceRefunded for EVERY refund
 *    transaction and hands the hook nothing but the invoice id, so the amount has
 *    to be read back here. §8 names a partial refund as the archetypal keep case
 *    (an apology on a sale that stands), and ending a customer's key over a few
 *    dollars handed back is exactly the "revoke by accident" this must not do.
 *    It is logged with the services it left running — silence would be its own
 *    accident, and the merchant can still revoke by hand.
 *
 * "In full" is two signals, either of which is enough: WHMCS has marked the invoice
 * Refunded, or the refunds booked against it add up to its total (several partial
 * refunds that reach the total ARE a full refund). Two, because WHMCS's core is
 * encoded — nothing here can read when it fires this hook or how it decides to
 * stamp the status, so the hook must be right under both behaviours.
 *
 * Store-channel (IAP) refunds go through their own pipeline, which terminates
 * before this hook sees the invoice — the not-Active guard makes the two
 * paths compose instead of double-terminating.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * How much of this invoice has actually been given back so far.
 *
 * `amountout` alone is NOT the refunded amount: WHMCS books late fees and
 * overpayment credit notes on the invoice with an `amountout` as well (seen live:
 * "Applied Debit Note for Late Fees"), so summing the column would let a late fee
 * push a partial refund over the line and revoke a key nobody meant to revoke.
 * A real refund is the typed ledger row WHMCS's own Refund action writes:
 * `gateway_funds_out`, carrying `refundid` = the payment it reverses.
 */
function vpnhoodstore_refundedTotal(int $invoiceId): float
{
    $refunds = Capsule::table('tblaccounts')
        ->where('invoiceid', $invoiceId)
        ->where('amountout', '>', 0)
        ->where(function ($q) {
            $q->where('refundid', '>', 0)->orWhere('type', 'gateway_funds_out');
        })
        ->sum('amountout');
    return round((float) $refunds, 2);
}

/** Named (not a closure) so the integration test can drive it directly. */
function vpnhoodstore_refundTerminateHook(array $vars): void
{
    try {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            return;
        }
        // Work out what there is to revoke BEFORE judging the amount: an invoice
        // carrying no revocable key of ours must stay completely silent.
        $candidates = [];
        $serviceIds = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->where('type', 'Hosting')
            ->pluck('relid')->all();
        foreach ($serviceIds as $serviceId) {
            $serviceId = (int) $serviceId;
            $service = Capsule::table('tblhosting as h')
                ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
                ->where('h.id', $serviceId)
                ->first(['h.domainstatus', 'p.servertype']);
            if ($service === null || (string) $service->servertype !== 'vpnhoodstore') {
                continue;
            }
            if (!in_array((string) $service->domainstatus, ['Active', 'Suspended'], true)) {
                continue; // already ended (e.g. the IAP refund pipeline got here first)
            }
            $candidates[] = $serviceId;
        }
        if ($candidates === []) {
            return;
        }

        // Two independent signals that the WHOLE sale is being undone, because the
        // core is encoded and cannot be read: WHMCS marking the invoice Refunded
        // (what a full refund through its own action does, and what an admin
        // declaring the invoice refunded means), or the refunds booked against it
        // adding up to its total. Either one revokes; neither one, and the key
        // stays. Money is stored to the cent, so compare to the half-cent.
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['total', 'status']);
        if ($invoice === null) {
            return;
        }
        $total = round((float) $invoice->total, 2);
        $refunded = vpnhoodstore_refundedTotal($invoiceId);
        $isFullRefund = (string) $invoice->status === 'Refunded'
            || ($total > 0 && $refunded >= $total - 0.005);
        if (!$isFullRefund) {
            localAPI('LogActivity', ['description' => sprintf(
                'vpnhoodstore: invoice #%d is not refunded in full (%.2f of %.2f given back, status %s) — service(s) #%s left running. '
                . 'A partial refund keeps the key on purpose (lifecycle §8); revoke by hand if the whole sale is being undone.',
                $invoiceId, $refunded, $total, (string) $invoice->status, implode(', #', $candidates))]);
            return;
        }

        foreach ($candidates as $serviceId) {
            $keep = Capsule::table('tblcustomfieldsvalues as v')
                ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
                ->where('v.relid', $serviceId)
                ->where('f.type', 'product')
                ->whereRaw("LOWER(SUBSTRING_INDEX(f.fieldname, '|', 1)) = 'keeponrefund'")
                ->value('v.value');
            if ((string) $keep === 'yes') {
                localAPI('LogActivity', ['description' =>
                    "vpnhoodstore: invoice #{$invoiceId} refunded — service #{$serviceId} deliberately kept (keepOnRefund)."]);
                continue;
            }
            $result = localAPI('ModuleTerminate', ['serviceid' => $serviceId]);
            localAPI('LogActivity', ['description' => ($result['result'] ?? '') === 'success'
                ? "vpnhoodstore: invoice #{$invoiceId} refunded in full — service #{$serviceId} terminated (refund revokes by default)."
                : "vpnhoodstore: invoice #{$invoiceId} refunded but service #{$serviceId} could NOT be terminated — revoke it by hand."]);
        }
    } catch (\Throwable $e) {
        logModuleCall('vpnhoodstore', 'hook.refund-terminate', $vars, $e->getMessage(), '');
    }
}

add_hook('InvoiceRefunded', 1, 'vpnhoodstore_refundTerminateHook');
