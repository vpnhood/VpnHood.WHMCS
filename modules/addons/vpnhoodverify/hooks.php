<?php

/**
 * VpnHood! Verify — the gates for unconfirmed email addresses.
 *
 * WHMCS's built-in email verification (General Settings -> Security -> Email
 * Verification) only mails a link and records the outcome; by design it blocks
 * nothing, so an unverified client browses the portal exactly like a verified one.
 * These hooks are the enforcement WHMCS leaves to us:
 *
 *  1. ClientAreaPage — while a client's address is unconfirmed, every client-area
 *     page redirects to the gate page.
 *  2. AfterShoppingCartCheckout — an unconfirmed client who has just checked out is
 *     sent to the gate page INSTEAD of the payment gateway. The order and the
 *     invoice exist (WHMCS created them before this hook fires), but no money moves
 *     until the address is confirmed; the invoice waits in the client area.
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
 *  - block order creation. Register-and-order-in-one-step keeps working: the
 *    account, the order and the invoice are created as today. Only the hop from
 *    checkout to the gateway is held, and only for clients the gate applies to.
 *  - touch the admin area, API orders, or app-store purchases. ClientAreaPage
 *    fires client-side only. AfterShoppingCartCheckout, on the other hand, fires
 *    for EVERY order WHMCS creates — the admin's order form and localAPI('AddOrder')
 *    included, which is how the Partner Hub and vpnhoodiap place theirs — so the
 *    checkout hold acts only when the request is the buyer's own cart.php session.
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

/**
 * WHMCS's own "Please check your email and follow the link…" banner never says
 * where the mail usually is. Append the spam hint whenever WHMCS shows that banner.
 *
 * Client-side, because WHMCS 9 gives a ClientAreaPage hook no LANG to rewrite and
 * the only server-side alternative is a lang/overrides/ file, which would not ship
 * or switch off with the addon. Only WHMCS's untouched English sentence is
 * extended — another language or an overridden string is left alone — and a theme
 * without the stock banner markup simply gets no hint.
 */
add_hook('ClientAreaFooterOutput', 1, function (array $vars) {
    if (empty($vars['showEmailVerificationBanner'])) {
        return '';
    }

    try {
        if (!VerifyGate::settings()['enabled']) {
            return '';
        }

        return VerifyGate::spamHintScript();
    } catch (\Throwable $e) {
        logModuleCall(VerifyGate::MODULE, 'hook.spamHint', '', $e->getMessage(), '');
        return '';
    }
});

/**
 * Hold the payment, not the order.
 *
 * With "Automatically redirect to gateway" on, WHMCS hands a fresh checkout straight
 * to the payment gateway — an off-site page no client-area hook can ever reach — so
 * a made-up address gets as far as a card form. The client, order and invoice have
 * already been created when this fires; what has not happened yet is the gateway
 * link being built, and that is the step redirecting here prevents. Once the address
 * is confirmed the ClientAreaPage gate steps aside and the unpaid invoice is payable
 * from the client area like any other.
 *
 * Only the buyer's own cart request is ever redirected — VerifyGate::checkoutHoldUrl
 * holds the whole decision (and its tests), this hook only carries it out.
 */
add_hook('AfterShoppingCartCheckout', 1, function (array $vars) {
    $orderId = (int) ($vars['OrderID'] ?? 0);

    try {
        $url = VerifyGate::checkoutHoldUrl($orderId, (int) ($vars['InvoiceID'] ?? 0));
        if ($url === '') {
            return;
        }

        header('Location: ' . $url);
        exit;
    } catch (\Throwable $e) {
        // Same posture as above: an exception lets the checkout continue as WHMCS
        // would have, and the client-area gate still stands behind it.
        logModuleCall(VerifyGate::MODULE, 'hook.checkoutGate', (string) $orderId, $e->getMessage(), '');
    }
});
