<?php

/**
 * Shared state for the email-verification gate.
 *
 * Lives in lib/ because two entry points need it and they are loaded
 * independently: hooks.php (loaded by WHMCS only while the addon is active) and
 * vpnhoodverify.php (loaded when an admin opens the addon, or a client opens the
 * gate page). Neither can rely on the other having been included.
 *
 * The one rule this class encodes: WHMCS's own per-user tblusers.email_verified_at
 * is the ONLY authority on whether an address is confirmed. We never keep a
 * verified-flag of our own, so there is nothing here that can drift out of step
 * with what WHMCS itself believes.
 */

namespace WHMCS\Module\Addon\VpnHoodVerify;

use WHMCS\Database\Capsule;

class VerifyGate
{
    public const MODULE = 'vpnhoodverify';

    public const SCOPE_NEW = 'New clients only';
    public const SCOPE_ALL = 'Every client';

    /**
     * Pages a gated client may still reach.
     *
     * This list is what keeps the gate escapable, and it is not optional. WHMCS's
     * verification link lives 60 minutes, and WHMCS's own advice for an expired one
     * is "login to the client area to request a new link" — so a gate that bounced
     * every page would make recovery literally impossible. Logout, WHMCS's own
     * verification handler, and password reset always stay open.
     */
    private const ALWAYS_ALLOWED = ['logout', 'verifyemail', 'password-reset', 'pwreset'];

    /**
     * Addon settings as WHMCS stored them in tbladdonmodules.
     *
     * Note WHMCS writes a 'yesno' field as 'on' (or nothing at all), not 'yes' —
     * both spellings are accepted here for the same reason PartnerRepository does.
     *
     * @return array{enabled:bool, scope:string, cutoff:string, extraPages:string[]}
     */
    public static function settings(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $rows = Capsule::table('tbladdonmodules')
            ->where('module', self::MODULE)
            ->pluck('value', 'setting');

        $enabled = in_array((string) ($rows['GateEnabled'] ?? ''), ['on', 'yes', '1'], true);

        $extra = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ($rows['ExtraAllowedPages'] ?? ''))
        )));

        return $cached = [
            'enabled'    => $enabled,
            'scope'      => (string) ($rows['GateScope'] ?? self::SCOPE_NEW),
            'cutoff'     => trim((string) ($rows['CutoffDate'] ?? '')),
            'extraPages' => $extra,
        ];
    }

    /**
     * Whether the gate applies to this client at all.
     *
     * "New clients only" keys on tblclients.datecreated because switching WHMCS's
     * EnableEmailVerification on does NOT mail existing clients — they read as
     * unverified forever without ever having been sent a link. Gating them would
     * bar people who were never given the chance, so the cutoff (stamped at
     * activation) draws the line at the day the gate was turned on.
     *
     * A missing or malformed cutoff means we cannot tell who is new, so nobody is
     * gated — same fail-open posture as the rest of this module.
     */
    public static function clientInScope(int $clientId, array $settings): bool
    {
        if ($settings['scope'] === self::SCOPE_ALL) {
            return true;
        }

        $cutoff = $settings['cutoff'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutoff)) {
            return false;
        }

        return Capsule::table('tblclients')
            ->where('id', $clientId)
            ->where('datecreated', '>=', $cutoff)
            ->exists();
    }

    /** The client's email address, or '' when there is no such client. */
    public static function clientEmail(int $clientId): string
    {
        return (string) Capsule::table('tblclients')->where('id', $clientId)->value('email');
    }

    /**
     * Whether the current request is the shopping cart itself (cart.php), as
     * opposed to the admin area, an API call, a cron run or a CLI script — all of
     * which also fire AfterShoppingCartCheckout when they create an order.
     */
    public static function isCartRequest(): bool
    {
        return basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'cart.php';
    }

    /** The client an order belongs to, or 0 when there is no such order. */
    public static function orderClientId(int $orderId): int
    {
        if ($orderId <= 0) {
            return 0;
        }

        return (int) Capsule::table('tblorders')->where('id', $orderId)->value('userid');
    }

    /**
     * The invoice the gate page should point at once the address is confirmed:
     * the given one, provided it belongs to this client and is still unpaid.
     * Anything else returns 0 — the id arrives on the query string, and a client
     * must never be shown a link to an invoice that is not theirs.
     */
    public static function pendingInvoiceId(int $clientId, int $invoiceId): int
    {
        if ($clientId <= 0 || $invoiceId <= 0) {
            return 0;
        }

        $owned = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->where('userid', $clientId)
            ->where('status', 'Unpaid')
            ->exists();

        return $owned ? $invoiceId : 0;
    }

    /**
     * Absolute URL of the gate page, optionally carrying the invoice that is
     * waiting on the confirmation. Absolute because the checkout hook has no
     * $vars['systemurl'] to lean on the way ClientAreaPage does.
     */
    public static function gateUrl(int $invoiceId = 0): string
    {
        $systemUrl = rtrim((string) Capsule::table('tblconfiguration')
            ->where('setting', 'SystemURL')
            ->value('value'), '/');

        $url = $systemUrl . '/index.php?m=' . self::MODULE;

        return $invoiceId > 0 ? $url . '&invoice=' . $invoiceId : $url;
    }

    /**
     * The checkout hold, as a decision: where a freshly checked-out order should
     * be redirected instead of the payment gateway, or '' to let WHMCS carry on.
     *
     * '' whenever this is not the buyer's own cart request. WHMCS fires
     * AfterShoppingCartCheckout for every order it creates — localAPI('AddOrder')
     * from the Partner Hub, vpnhoodiap and scripts, and the admin order form
     * included — and a redirect-and-exit there would kill the caller mid-request.
     * So the order must come from cart.php with its owner logged in, be in scope,
     * and belong to an address WHMCS has not seen confirmed.
     */
    public static function checkoutHoldUrl(int $orderId, int $invoiceId): string
    {
        if (!self::isCartRequest()) {
            return '';
        }

        $settings = self::settings();
        if (!$settings['enabled']) {
            return '';
        }

        $clientId = self::orderClientId($orderId);
        if ($clientId <= 0 || $clientId !== (int) ($_SESSION['uid'] ?? 0)) {
            return '';
        }
        if (!self::clientInScope($clientId, $settings)) {
            return '';
        }

        $email = self::clientEmail($clientId);
        if ($email === '' || self::isEmailVerified($email)) {
            return '';
        }

        return self::gateUrl($invoiceId);
    }

    /**
     * Whether WHMCS itself has seen this address confirmed, read per user from
     * tblusers.email_verified_at. Deliberately independent of the global
     * EnableEmailVerification switch: that setting decides whether WHMCS *mails*
     * a link, not whether an address is confirmed, and the gate must keep letting
     * verified people through even if an admin turns the switch back off.
     */
    public static function isEmailVerified(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }

        $user = \WHMCS\User\User::where('email', $email)->first();

        return $user !== null && (bool) $user->emailVerified();
    }

    /**
     * Ask WHMCS to issue its own verification mail for the address.
     *
     * Works with the global switch off (WHMCS still mints the token), which is what
     * lets the gate page stay an escape route regardless of how the install is
     * configured. The link WHMCS sends lives 60 minutes, so this has to be callable
     * again rather than assume one mail is enough. Best-effort: a mail that cannot
     * be sent must never break the page that offers it.
     */
    public static function sendVerificationEmail(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }

        try {
            $user = \WHMCS\User\User::where('email', $email)->first();
            if ($user === null) {
                return false;
            }
            return (bool) $user->sendEmailVerification();
        } catch (\Throwable $e) {
            logModuleCall(self::MODULE, 'sendVerificationEmail', $email, $e->getMessage(), '');
            return false;
        }
    }

    /**
     * WHMCS's stock wording for its own "verify your email" banner, and what to add
     * to it. The banner sits at the top of every client-area page (the gate page
     * included) while an address is unconfirmed, and never mentions the one place
     * the mail usually is.
     */
    private const WHMCS_VERIFY_BANNER = 'Please check your email and follow the link to verify your email address.';
    private const SPAM_HINT = " Can't find it? Check your spam or junk folder.";

    /**
     * Footer script that appends the spam hint to WHMCS's verification banner.
     *
     * The banner is `.verification-banner.email-verification` in WHMCS's stock
     * theme and the ones built on it (lagom2 included); the hint goes onto the
     * text node holding the sentence, so the Resend button and the close control
     * are untouched. Matching the exact English sentence is the language guard:
     * anything else — a translation, a lang/overrides/ rewrite — is left as it is.
     */
    public static function spamHintScript(): string
    {
        $sentence = json_encode(self::WHMCS_VERIFY_BANNER, JSON_THROW_ON_ERROR);
        $hint = json_encode(self::SPAM_HINT, JSON_THROW_ON_ERROR);

        return '<script>(function(){'
            . 'var b=document.querySelector(".verification-banner.email-verification");if(!b)return;'
            . 'var w=document.createTreeWalker(b,NodeFilter.SHOW_TEXT),n;'
            . 'while((n=w.nextNode())){if(n.nodeValue.indexOf(' . $sentence . ')!==-1){'
            . 'n.nodeValue=n.nodeValue.replace(' . $sentence . ',' . $sentence . '+' . $hint . ');return;}}'
            . '})();</script>';
    }

    /**
     * Whether the page being requested is reachable while gated.
     *
     * Besides the always-open set, this module's own gate page is allowed (it is
     * where we redirect TO), and so is vpnhoodiap's — that addon runs its own
     * per-account verification gate, and shutting its pages would strand app-store
     * buyers on a page they cannot leave.
     */
    public static function pageAllowed(string $filename, array $settings): bool
    {
        if (in_array($filename, self::ALWAYS_ALLOWED, true)) {
            return true;
        }

        if (in_array($filename, $settings['extraPages'], true)) {
            return true;
        }

        $module = (string) ($_GET['m'] ?? '');
        if ($module === self::MODULE || $module === 'vpnhoodiap') {
            return true;
        }

        // WHMCS routes the mailed verification link through its own user endpoints,
        // which do not surface as a filename we can match on.
        $path = (string) ($_SERVER['REQUEST_URI'] ?? '');

        return str_contains($path, '/user/verify') || str_contains($path, 'verifyemail');
    }
}
