<?php

/**
 * VpnHood Store — the checkout warning (lifecycle §8, decided 2026-08-13).
 *
 * A signed-in client who already holds something active is told what they have
 * before they pay — and NEVER blocked: one look, and Continue still continues.
 * The wording follows what they hold (does it continue on its own?), stays
 * silent when buying again is the correct action, and describes the key that
 * actually bears on the decision (the renewing one, else the longest-lived).
 * Bulk/reseller carts are skipped: no interactive checkout to warn in.
 *
 * Display-only by construction: a banner injected on cart pages. It can never
 * reject an order — a warning that blocks is a limit wearing a different hat.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

add_hook('ClientAreaHeadOutput', 1, function (array $vars) {
    try {
        if (($vars['filename'] ?? '') !== 'cart') {
            return '';
        }
        $clientId = (int) ($_SESSION['uid'] ?? 0);
        if ($clientId <= 0) {
            return '';
        }

        // every active vpnhoodstore service the client holds, newest expiry first
        $services = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->where('h.userid', $clientId)
            ->where('p.servertype', 'vpnhoodstore')
            ->whereIn('h.domainstatus', ['Active'])
            ->get(['h.id', 'h.nextduedate', 'p.paytype']);
        if ($services->isEmpty()) {
            return '';
        }

        $renewing = null;   // a subscription that continues on its own
        $longestLived = null; // else: the key with the furthest known end
        foreach ($services as $service) {
            $due = (string) $service->nextduedate;
            $pendingCancel = Capsule::table('tblcancelrequests')->where('relid', (int) $service->id)->exists();
            if ((string) $service->paytype === 'recurring' && !$pendingCancel
                && $due !== '' && $due !== '0000-00-00' && strtotime($due) >= strtotime(date('Y-m-d'))) {
                if ($renewing === null || strtotime($due) > strtotime((string) $renewing->nextduedate)) {
                    $renewing = $service;
                }
                continue;
            }
            if ((string) $service->paytype !== 'recurring') {
                // a one-time key: WHMCS cannot see its clock (it starts on first
                // use at the access manager) — describe it without a date
                $longestLived = $longestLived ?? $service;
            }
        }

        if ($renewing !== null) {
            $when = date('j M Y', strtotime((string) $renewing->nextduedate));
            $message = "You already have a subscription that renews on its own on {$when}. "
                . 'Buying again gives you a new, separate key — it does not extend or upgrade the one you have.';
        } elseif ($longestLived !== null) {
            $message = 'You already have an active key. Buying another gives you a new, separate key — '
                . 'it does not extend the one you have.';
        } else {
            return ''; // everything they hold is ending — buying again is the correct action
        }

        $safe = htmlspecialchars($message, ENT_QUOTES);
        return <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
    var host = document.querySelector('#order-standard_cart') || document.querySelector('.main-content') || document.body;
    if (!host) return;
    var notice = document.createElement('div');
    notice.className = 'alert alert-warning';
    notice.setAttribute('role', 'status');
    notice.textContent = '{$safe}';
    host.insertBefore(notice, host.firstChild);
});
</script>
HTML;
    } catch (\Throwable $e) {
        logModuleCall('vpnhoodstore', 'hook.cart-notice', '', $e->getMessage(), '');
        return '';
    }
});
