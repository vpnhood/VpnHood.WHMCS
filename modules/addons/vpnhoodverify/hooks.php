<?php

/**
 * VpnHood! Verify — the client-area gate for unconfirmed email addresses.
 *
 * WHMCS's built-in email verification (General Settings -> Security -> Email
 * Verification) only mails a link and records the outcome; by design it blocks
 * nothing, so an unverified client browses the portal exactly like a verified one.
 * This hook is the enforcement WHMCS leaves to us: while a client's address is
 * unconfirmed, every client-area page redirects to the gate page.
 *
 * This file lives inside the addon rather than in includes/hooks/ on purpose.
 * WHMCS loads modules/addons/<name>/hooks.php ONLY while the addon is activated,
 * which makes deactivating the addon a real kill switch — the one property that
 * matters most for a module whose failure mode is locking every client out of the
 * portal. Four more ways out, in order of reach: GateEnabled=no, ExtraAllowedPages,
 * the built-in whitelist, and failing open on any exception.
 *
 * What it deliberately does NOT do:
 *  - block registration. WHMCS creates the client record and then mails the link,
 *    so there is no "confirm before the account exists" to hook into.
 *  - block ordering. Gating checkout would break register-and-order-in-one-step,
 *    where the client cannot possibly have clicked a link yet.
 *  - touch the admin area. ClientAreaPage fires client-side only.
 *  - touch app-store purchases. vpnhoodiap's api.php is not a client-area page.
 */

if (!defined('WHMCS')) {
    die('You cannot access this file directly.');
}

require_once __DIR__ . '/lib/VerifyGate.php';

use WHMCS\Module\Addon\VpnHoodVerify\VerifyGate;

add_hook('ClientAreaPage', 1, function (array $vars) {
    $clientId = (int) ($_SESSION['uid'] ?? 0);
    if ($clientId <= 0) {
        return []; // not logged in — login, register and the cart must stay open
    }

    try {
        $settings = VerifyGate::settings();
        if (!$settings['enabled']) {
            return [];
        }

        if (VerifyGate::pageAllowed((string) ($vars['filename'] ?? ''), $settings)) {
            return [];
        }

        if (!VerifyGate::clientInScope($clientId, $settings)) {
            return [];
        }

        $email = VerifyGate::clientEmail($clientId);
        if ($email === '' || VerifyGate::isEmailVerified($email)) {
            return [];
        }

        header('Location: ' . rtrim((string) ($vars['systemurl'] ?? ''), '/')
            . '/index.php?m=' . VerifyGate::MODULE);
        exit;
    } catch (\Throwable $e) {
        // A gate that cannot read its own state must not bar the door. Fail open
        // and leave a trail in the module log for the admin.
        logModuleCall(VerifyGate::MODULE, 'hook.clientAreaGate', (string) $clientId, $e->getMessage(), '');
        return [];
    }
});
