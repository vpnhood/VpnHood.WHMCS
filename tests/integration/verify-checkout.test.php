<?php
/**
 * verify-checkout.test.php — the vpnhoodverify checkout hold, fired through
 * WHMCS's own run_hook() exactly as cart.php fires it after an order:
 *
 *   1. a fresh (post-cutoff, unconfirmed) client with an unpaid order is HELD —
 *      the AfterShoppingCartCheckout hook redirects and exits before WHMCS
 *      would build the gateway link;
 *   2. the same client, once WHMCS marks the address confirmed, is NOT held;
 *   3. an order created outside the cart (API/admin/CLI — WHMCS fires the same
 *      hook for localAPI('AddOrder')) is never held, whoever it belongs to;
 *   4. the helpers behind it: the order resolves to its client, the gate URL
 *      carries the invoice, and an invoice is only "pending" for its owner.
 *
 * The hook itself is two lines around VerifyGate::checkoutHoldUrl() — the URL to
 * redirect to, or '' to let WHMCS carry on — so that decision is what gets
 * exercised here, in-process, with the request dressed up as the buyer's own
 * cart.php (SCRIPT_NAME + session uid) or as an API/CLI caller. (The box has
 * every process-spawning function disabled, so the exit-ing hook cannot be run
 * in a child; this is why the decision lives outside the hook.)
 *
 * Requires the addon active with Enforce Verification = Yes and a valid
 * cutoff (any scope). Creates one throwaway client and deletes it again.
 */

require __DIR__ . '/lib/common.php';
require_once WEBROOT . '/modules/addons/vpnhoodverify/lib/VerifyGate.php';

use WHMCS\Module\Addon\VpnHoodVerify\VerifyGate;

/** The hold decision for one order, with the request posing as cart.php (or not). */
function holdUrl(int $orderId, int $invoiceId, string $as = 'cart', int $sessionClientId = 0): string
{
    $_SERVER['SCRIPT_NAME'] = $as === 'cart' ? '/cart.php' : '/modules/addons/vpnhoodpartnerhub/api.php';
    $_SESSION['uid'] = $sessionClientId;
    try {
        return VerifyGate::checkoutHoldUrl($orderId, $invoiceId);
    } finally {
        unset($_SESSION['uid']);
        $_SERVER['SCRIPT_NAME'] = __FILE__;
    }
}

// -- preconditions --------------------------------------------------------------
$settings = VerifyGate::settings();
if (!$settings['enabled']) {
    bad('vpnhoodverify is not enforcing (GateEnabled off) — enable it on the dev addon first');
    finish();
}
if ($settings['scope'] === VerifyGate::SCOPE_NEW && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $settings['cutoff'])) {
    bad('vpnhoodverify cutoff is missing/malformed — nobody is in scope');
    finish();
}
ok('gate enforcing, scope "' . $settings['scope'] . '", cutoff ' . $settings['cutoff']);

$email = 'verify-hold-' . bin2hex(random_bytes(4)) . '@vpnhood.test';
$clientId = 0;

try {
    // -- a client WHMCS has not seen confirmed, created today (in scope) ------
    $add = localAPI('AddClient', [
        'firstname' => 'Verify', 'lastname' => 'Hold', 'email' => $email,
        'password2' => bin2hex(random_bytes(12)), 'country' => 'US',
        'skipvalidation' => true, 'noemail' => true,
    ]);
    if (($add['result'] ?? '') !== 'success') {
        throw new RuntimeException('AddClient failed: ' . json_encode($add));
    }
    $clientId = (int) $add['clientid'];
    VerifyGate::isEmailVerified($email) ? bad('fresh client already reads as verified') : ok('fresh client is unconfirmed');
    VerifyGate::clientInScope($clientId, $settings) ? ok('fresh client is in scope') : bad('fresh client is NOT in scope');

    $order = localAPI('AddOrder', [
        'clientid' => $clientId, 'pid' => 15, 'billingcycle' => 'onetime',
        'paymentmethod' => 'paymenthood', 'noemail' => true, 'noinvoiceemail' => true,
    ]);
    if (($order['result'] ?? '') !== 'success') {
        throw new RuntimeException('AddOrder failed: ' . json_encode($order));
    }
    $orderId = (int) $order['orderid'];
    $invoiceId = (int) ($order['invoiceid'] ?? 0);
    $invoiceId > 0 ? ok("order $orderId with unpaid invoice $invoiceId") : bad('order created without an invoice');

    // -- helpers -----------------------------------------------------------------
    VerifyGate::orderClientId($orderId) === $clientId ? ok('order resolves to its client') : bad('orderClientId mismatch');
    VerifyGate::orderClientId(0) === 0 ? ok('orderClientId(0) is 0') : bad('orderClientId(0) not 0');
    VerifyGate::pendingInvoiceId($clientId, $invoiceId) === $invoiceId ? ok('unpaid invoice is pending for its owner') : bad('owner invoice not pending');
    VerifyGate::pendingInvoiceId($clientId + 1, $invoiceId) === 0 ? ok('invoice is not pending for another client') : bad('foreign invoice leaked');
    VerifyGate::pendingInvoiceId($clientId, $invoiceId + 100000) === 0 ? ok('unknown invoice is not pending') : bad('unknown invoice pending');

    $systemUrl = rtrim((string) one($db, "SELECT value FROM tblconfiguration WHERE setting='SystemURL'")['value'], '/');
    $expected = $systemUrl . '/index.php?m=vpnhoodverify&invoice=' . $invoiceId;
    VerifyGate::gateUrl($invoiceId) === $expected ? ok("gate URL is $expected") : bad('gate URL is ' . VerifyGate::gateUrl($invoiceId));
    VerifyGate::gateUrl() === $systemUrl . '/index.php?m=vpnhoodverify' ? ok('gate URL without invoice') : bad('bare gate URL wrong');

    // -- the hold decision ----------------------------------------------------------
    // (this very script survived its own localAPI('AddOrder') above — which fires the
    // real hook — only because the decision ignores non-cart requests: case 3, literally)
    holdUrl($orderId, $invoiceId, 'cart', $clientId) === $expected ? ok('unconfirmed client on cart.php: held, redirect to the gate with the invoice') : bad('unconfirmed client was NOT held (got "' . holdUrl($orderId, $invoiceId, 'cart', $clientId) . '")');
    holdUrl($orderId, $invoiceId, 'api') === '' ? ok('same order outside the cart (API/CLI): never held') : bad('API-created order was held');
    holdUrl($orderId, $invoiceId, 'cart', $clientId + 1) === '' ? ok('cart.php with another client logged in: not held') : bad('held for a session that is not the order\'s owner');
    holdUrl($orderId, $invoiceId, 'cart', 0) === '' ? ok('cart.php with nobody logged in: not held') : bad('held without a logged-in owner');

    $user = \WHMCS\User\User::where('email', $email)->first();
    if ($user === null) {
        throw new RuntimeException('no tblusers row for ' . $email);
    }
    $user->setEmailVerificationCompleted();
    VerifyGate::isEmailVerified($email) ? ok('WHMCS now reads the address as confirmed') : bad('address still unconfirmed after setEmailVerificationCompleted');
    holdUrl($orderId, $invoiceId, 'cart', $clientId) === '' ? ok('confirmed client: checkout continues') : bad('confirmed client was held');

    // an order that does not exist is let through, never held
    holdUrl(0, 0, 'cart', $clientId) === '' ? ok('unknown order: checkout continues') : bad('unknown order was held');
} catch (Throwable $e) {
    bad('exception: ' . $e->getMessage());
} finally {
    if ($clientId > 0) {
        $del = localAPI('DeleteClient', ['clientid' => $clientId, 'deleteusers' => true]);
        ($del['result'] ?? '') === 'success' ? ok('throwaway client deleted') : bad('cleanup failed: ' . json_encode($del));
    }
}

finish();
