<?php

/**
 * VpnHood Store — a FULL refund follows the admin's Reverse Payment box (lifecycle §8).
 *
 * When an invoice is refunded in full, every vpnhoodstore service billed on it follows
 * the answer on WHMCS's own Refund form:
 *
 *  - *Reverse Payment* ticked — the sale is undone: the service is terminated, so the
 *    money and the service go back together.
 *  - Unticked — *refund and keep*, the goodwill gesture: the key is left running to its
 *    original expiry, with the decision logged.
 *  - A refund that did not come from that form (a gateway callback, the API) revokes:
 *    nobody chose to keep the service.
 *
 * A PARTIAL refund never revokes, whatever the box says. WHMCS fires InvoiceRefunded for
 * EVERY refund transaction and hands the hook nothing but the invoice id, so the amount has
 * to be read back here. §8 names a partial refund as the archetypal keep case (an apology
 * on a sale that stands), and ending a customer's key over a few dollars handed back is
 * exactly the "revoke by accident" this must not do. It is logged with the services it left
 * running — silence would be its own accident, and the merchant can still revoke by hand.
 *
 * Revoking a fully refunded RENEWAL invoice ends the whole service, by design, not just the
 * cycle it paid for: winding `nextduedate` back to the last paid cycle would make WHMCS invoice
 * the refunded cycle again. Leaving Reverse Payment unticked is the way to keep such a service.
 *
 * "In full" is vpnhoodstore_isRefundedInFull: WHMCS has marked the invoice Refunded,
 * or the refunds booked against it add up to its total. The refund memory
 * (vpnhood-refund-memory.php) judges by the same function.
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

/**
 * Whether the WHOLE sale is being undone. Two independent signals, either one enough,
 * because WHMCS's core is encoded — nothing here can read when it fires InvoiceRefunded
 * or how it decides to stamp the status, so the judgement must be right under both
 * behaviours: WHMCS has marked the invoice Refunded (what a full refund through its own
 * action does, and what an admin declaring the invoice refunded means), or the refunds
 * booked against it add up to its total (several partial refunds that reach the total
 * ARE a full refund). Money is stored to the cent, so compare to the half-cent.
 * Shared with vpnhood-refund-memory.php.
 */
function vpnhoodstore_isRefundedInFull(int $invoiceId): bool
{
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['total', 'status']);
    if ($invoice === null) {
        return false;
    }
    $total = round((float) $invoice->total, 2);
    return (string) $invoice->status === 'Refunded'
        || ($total > 0 && vpnhoodstore_refundedTotal($invoiceId) >= $total - 0.005);
}

/**
 * The Refund form's Reverse Payment box for this invoice: true ticked, false unticked, null when
 * the refund did not come from the form. WHMCS hands InvoiceRefunded nothing but the invoice id,
 * but fires it inside the form's own request, so the box is read from that (seen on WHMCS 9.1:
 * `sub=refund`, `id` the invoice, `reverse` a hidden 0 that the ticked box overrides with 1). A
 * WHMCS that renames the field answers null, and the service is revoked rather than kept.
 */
function vpnhoodstore_refundFormReverse(int $invoiceId): ?bool
{
    if (($_POST['sub'] ?? null) !== 'refund' || (int) ($_POST['id'] ?? 0) !== $invoiceId) {
        return null;
    }
    return match ($_POST['reverse'] ?? null) {
        '1' => true,
        '0' => false,
        default => null,
    };
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

        // A full refund revokes; anything less, and the key stays.
        if (!vpnhoodstore_isRefundedInFull($invoiceId)) {
            $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['total', 'status']);
            if ($invoice === null) {
                return;
            }
            localAPI('LogActivity', ['description' => sprintf(
                'vpnhoodstore: invoice #%d is not refunded in full (%.2f of %.2f given back, status %s) — service(s) #%s left running. '
                . 'A partial refund keeps the key on purpose (lifecycle §8); revoke by hand if the whole sale is being undone.',
                $invoiceId, vpnhoodstore_refundedTotal($invoiceId), round((float) $invoice->total, 2), (string) $invoice->status,
                implode(', #', $candidates))]);
            return;
        }

        $reverse = vpnhoodstore_refundFormReverse($invoiceId);
        foreach ($candidates as $serviceId) {
            if ($reverse === false) {
                localAPI('LogActivity', ['description' =>
                    "vpnhoodstore: invoice #{$invoiceId} refunded in full — service #{$serviceId} kept (Reverse Payment not ticked)."]);
                continue;
            }
            $why = $reverse === true ? 'Reverse Payment ticked' : 'not refunded from the Refund form';
            $result = localAPI('ModuleTerminate', ['serviceid' => $serviceId]);
            localAPI('LogActivity', ['description' => ($result['result'] ?? '') === 'success'
                ? "vpnhoodstore: invoice #{$invoiceId} refunded in full — service #{$serviceId} terminated ({$why})."
                : "vpnhoodstore: invoice #{$invoiceId} refunded but service #{$serviceId} could NOT be terminated — revoke it by hand."]);
        }
    } catch (\Throwable $e) {
        logModuleCall('vpnhoodstore', 'hook.refund-terminate', $vars, $e->getMessage(), '');
    }
}

add_hook('InvoiceRefunded', 1, 'vpnhoodstore_refundTerminateHook');
