<?php

/**
 * VpnHood Store — a refund revokes the key by default (lifecycle §8).
 *
 * When an invoice is refunded, every vpnhoodstore service billed on it is
 * terminated, so the money and the service go back together. *Refund and
 * keep* — the goodwill gesture — is the DELIBERATE choice: set the
 * `keepOnRefund` service property to `yes` before refunding and the key is
 * left running to its original expiry, with the decision logged.
 *
 * Store-channel (IAP) refunds go through their own pipeline, which terminates
 * before this hook sees the invoice — the not-Active guard makes the two
 * paths compose instead of double-terminating.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/** Named (not a closure) so the integration test can drive it directly. */
function vpnhoodstore_refundTerminateHook(array $vars): void
{
    try {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            return;
        }
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
                ? "vpnhoodstore: invoice #{$invoiceId} refunded — service #{$serviceId} terminated (refund revokes by default)."
                : "vpnhoodstore: invoice #{$invoiceId} refunded but service #{$serviceId} could NOT be terminated — revoke it by hand."]);
        }
    } catch (\Throwable $e) {
        logModuleCall('vpnhoodstore', 'hook.refund-terminate', $vars, $e->getMessage(), '');
    }
}

add_hook('InvoiceRefunded', 1, 'vpnhoodstore_refundTerminateHook');
