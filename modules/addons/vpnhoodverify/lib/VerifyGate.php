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
