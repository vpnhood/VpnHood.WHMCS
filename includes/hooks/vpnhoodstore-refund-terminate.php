<?php

/**
 * VpnHood Store — what a FULL refund does to the key (lifecycle §8).
 *
 * When an invoice is refunded in full, every vpnhoodstore service billed on it follows
 * the answer on WHMCS's own Refund form:
 *
 *  - *Reverse Payment* ticked — the sale is undone: the service is terminated, so the
 *    money and the service go back together.
 *  - Unticked (the form's default) — *refund and keep*: the key is left running to its
 *    original expiry, with the decision logged. Access usually stays after a refund; it
 *    depends on the customer, so the admin decides on the form (owner, 2026-11-11).
 *  - A refund that did not come from that form — a gateway reported it (a refund made in
 *    Stripe's or PayPal's own dashboard, a gateway callback) or the API booked it — keeps a
 *    subscription's key to the end of its paid cycle, but never more than one month, and
 *    then WHMCS ends it with nothing billed again: a monthly key runs out its cycle, a yearly
 *    one gets a month (owner, 2026-11-11: one month free is fine for anyone who paid us, not
 *    many months). A one-time code is a reseller's, and a reseller's refund always ends the
 *    code, so it is still terminated at once.
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

/**
 * The day a subscription refunded outside the Refund form stops: the end of its paid cycle (its
 * next due date), but never more than one month from today and never before today. Null for a
 * service with no cycle: a one-time code, which is a reseller's.
 */
function vpnhoodstore_refundKeepUntil(int $serviceId): ?string
{
    $service = Capsule::table('tblhosting')->where('id', $serviceId)->first(['billingcycle', 'nextduedate']);
    $due = (string) ($service->nextduedate ?? '');
    if ($service === null || in_array((string) $service->billingcycle, ['One Time', 'Free Account'], true)
        || $due === '' || str_starts_with($due, '0000')) {
        return null;
    }
    $today = new \DateTimeImmutable('today');
    // One month on, on the same day, or the next month's last day: 31 January keeps to the end of
    // February, never into March.
    $next = $today->modify('first day of next month');
    $cap = $next->setDate((int) $next->format('Y'), (int) $next->format('n'),
        min((int) $today->format('j'), (int) $next->format('t')))->format('Y-m-d');
    return max($today->format('Y-m-d'), min($due, $cap));
}

/**
 * Keep a subscription refunded outside the Refund form running to vpnhoodstore_refundKeepUntil(),
 * and let WHMCS end it there: an End of Billing Period cancellation request, which WHMCS never
 * invoices and which the daily cron terminates on the due date (checked on production: six such
 * requests got no renewal invoice; service #492 was terminated by the 02:00 cron on its due
 * date). The request goes in before the due date moves, so no invoice run sees the service
 * without it. A request the customer already made becomes that one: the refund decides when the
 * key ends. Returns the day, or null when the service has no cycle to keep it to.
 */
function vpnhoodstore_keepRefundedSubscription(int $serviceId, int $invoiceId): ?string
{
    $until = vpnhoodstore_refundKeepUntil($serviceId);
    if ($until === null) {
        return null;
    }

    $request = Capsule::table('tblcancelrequests')->where('relid', $serviceId)->orderBy('id', 'desc')->first(['id']);
    if ($request !== null) {
        Capsule::table('tblcancelrequests')->where('id', $request->id)
            ->update(['type' => 'End of Billing Period', 'updated_at' => date('Y-m-d H:i:s')]);
    } else {
        $added = localAPI('AddCancelRequest', ['serviceid' => $serviceId, 'type' => 'End of Billing Period',
            'reason' => "Refunded in full outside the Refund form (invoice #{$invoiceId}): kept until {$until}, then ended."]);
        if (($added['result'] ?? '') !== 'success') {
            throw new \RuntimeException('AddCancelRequest failed: ' . json_encode($added));
        }
    }

    $due = (string) Capsule::table('tblhosting')->where('id', $serviceId)->value('nextduedate');
    if ($until < $due) {
        $moved = localAPI('UpdateClientProduct', ['serviceid' => $serviceId, 'nextduedate' => $until]);
        if (($moved['result'] ?? '') !== 'success') {
            throw new \RuntimeException("moving the due date to {$until} failed: " . json_encode($moved));
        }
        // The key's expiry follows the due date (the module's Renew). If that call fails, the key
        // keeps its later expiry, and the cron's Terminate on the due date still ends it.
        $server = new \WHMCS\Module\Server();
        $renewed = $server->loadByServiceID($serviceId) ? $server->call('Renew') : 'the module did not load';
        if ($renewed !== 'success') {
            localAPI('LogActivity', ['description' => "vpnhoodstore: service #{$serviceId}'s key could not be moved to expire on "
                . "{$until} (" . (is_string($renewed) ? $renewed : json_encode($renewed)) . "); WHMCS still ends it that day."]);
        }
    }
    return $until;
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
            if ($reverse === null) {
                try {
                    $until = vpnhoodstore_keepRefundedSubscription($serviceId, $invoiceId);
                } catch (\Throwable $e) {
                    localAPI('LogActivity', ['description' => "vpnhoodstore: invoice #{$invoiceId} refunded in full outside the Refund "
                        . "form, but service #{$serviceId} could NOT be set to end on its own ({$e->getMessage()}): it keeps running "
                        . 'and billing; set its cancellation by hand.']);
                    continue;
                }
                if ($until !== null) {
                    localAPI('LogActivity', ['description' => "vpnhoodstore: invoice #{$invoiceId} refunded in full outside the Refund "
                        . "form — service #{$serviceId} kept until {$until} (the end of its paid cycle, at most one month), then "
                        . 'ended; nothing more is billed.']);
                    continue;
                }
            }
            $why = $reverse === true ? 'Reverse Payment ticked' : 'a one-time code refunded outside the Refund form';
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
