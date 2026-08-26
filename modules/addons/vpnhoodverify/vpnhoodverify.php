<?php

/**
 * VpnHood! Verify
 *
 * Forces client-area email verification, which WHMCS itself will not do. WHMCS's
 * own Email Verification setting (System Settings -> General Settings -> Security)
 * mails the link and records the result in tblusers.email_verified_at, but is
 * deliberately non-blocking: per WHMCS's docs, an unverified user "can access the
 * Client Area, services, and support resources normally". This addon supplies the
 * missing half — the enforcement — and nothing else. It stores no state, owns no
 * tables, and never decides for itself whether an address is confirmed.
 *
 * Standalone by design: it depends on no other VpnHood module, and the gate stops
 * existing the moment the addon is deactivated (WHMCS only loads an active addon's
 * hooks.php).
 *
 * @see hooks.php          the ClientAreaPage gate
 * @see lib/VerifyGate.php the shared state, and why WHMCS is the only authority
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodVerify\VerifyGate;

require_once __DIR__ . '/lib/VerifyGate.php';

/**
 * Addon configuration / metadata.
 */
function vpnhoodverify_config(): array
{
    return [
        'name'        => 'VpnHood! Verify',
        'description' => 'Requires clients to confirm their email address before the client area opens. WHMCS mails the link; this addon is what makes it mandatory.',
        'version'     => '1.1.0',
        'author'      => 'VpnHood',
        'fields'      => [
            'GateEnabled' => [
                'FriendlyName' => 'Enforce Verification',
                'Type'         => 'yesno',
                'Description'  => 'Redirect clients with an unconfirmed email address to the confirmation page. Turn this off to leave the addon installed but stop gating anyone.',
                'Default'      => 'no',
            ],
            'GateScope' => [
                'FriendlyName' => 'Applies To',
                'Type'         => 'dropdown',
                'Options'      => 'New clients only,Every client',
                'Description'  => 'Who the gate applies to. "New clients only" means clients created on or after the cutoff date below.',
                'Default'      => 'New clients only',
            ],
            'CutoffDate' => [
                'FriendlyName' => 'New-Client Cutoff (YYYY-MM-DD)',
                'Type'         => 'text',
                'Size'         => '12',
                'Description'  => 'Stamped with today\'s date when the addon is activated. Clients created before this date are never gated — turning WHMCS verification on does not mail existing clients, so they would otherwise be shut out without ever having been sent a link.',
                'Default'      => '',
            ],
            'ExtraAllowedPages' => [
                'FriendlyName' => 'Additional Allowed Pages',
                'Type'         => 'text',
                'Size'         => '60',
                'Description'  => 'Comma-separated page filenames that stay reachable while gated, on top of logout, verifyemail and password-reset. An escape hatch for a page we did not anticipate — no deploy needed. Example: contact,announcements',
                'Default'      => '',
            ],
        ],
    ];
}

/**
 * Stamp the cutoff at activation, so switching the addon on can never retroactively
 * gate the existing client base.
 *
 * WHMCS deletes an addon's settings rows on deactivation, so a later re-activation
 * legitimately re-stamps the cutoff to that day. That is the intended reading of
 * "new": new as of when the gate was last turned on.
 */
function vpnhoodverify_activate(): array
{
    try {
        $today = vpnhoodverify_stampCutoff();

        return [
            'status'      => 'success',
            'description' => 'Cutoff set to ' . $today . '. Enforcement is OFF until you enable it in the settings.',
        ];
    } catch (\Throwable $e) {
        return [
            'status'      => 'error',
            'description' => 'Could not stamp the cutoff date: ' . $e->getMessage(),
        ];
    }
}

/**
 * Write today's date into the CutoffDate setting, and return it.
 *
 * Called from `_activate()` and again from the admin page, because WHMCS may seed
 * an addon's default setting rows *after* `_activate()` has run — which would blank
 * what activation just stamped. Re-stamping only when the value is empty keeps that
 * from clobbering a date an admin deliberately typed.
 */
function vpnhoodverify_stampCutoff(): string
{
    $today = date('Y-m-d');

    $exists = Capsule::table('tbladdonmodules')
        ->where('module', VerifyGate::MODULE)
        ->where('setting', 'CutoffDate')
        ->exists();

    if ($exists) {
        Capsule::table('tbladdonmodules')
            ->where('module', VerifyGate::MODULE)
            ->where('setting', 'CutoffDate')
            ->update(['value' => $today]);
    } else {
        Capsule::table('tbladdonmodules')->insert([
            'module'  => VerifyGate::MODULE,
            'setting' => 'CutoffDate',
            'value'   => $today,
        ]);
    }

    return $today;
}

function vpnhoodverify_deactivate(): array
{
    // Nothing to tear down: no tables, no state. WHMCS clears the settings rows,
    // and with the addon inactive it stops loading hooks.php entirely.
    return ['status' => 'success', 'description' => 'The client-area gate is no longer active.'];
}

/**
 * Admin page: what the gate is currently doing, and to how many people.
 *
 * The headline number is "clients this gate would currently stop", because the
 * difference between that and zero is the support load an admin is signing up for.
 */
function vpnhoodverify_output($vars): void
{
    $settings = VerifyGate::settings();

    // An empty cutoff means activation's stamp never survived (WHMCS can seed its
    // defaults afterwards). Put it back now rather than leave the admin looking at
    // a "New clients only" gate that silently applies to nobody.
    if ($settings['cutoff'] === '') {
        try {
            $settings['cutoff'] = vpnhoodverify_stampCutoff();
        } catch (\Throwable $e) {
            // non-fatal: the warning below still tells the admin what to do
        }
    }

    $globalOn = (string) Capsule::table('tblconfiguration')
        ->where('setting', 'EnableEmailVerification')
        ->value('value');
    $globalOn = in_array(strtolower($globalOn), ['on', 'yes', '1'], true);

    echo '<h3>Status</h3>';

    if (!$globalOn) {
        echo '<div class="alert alert-warning">'
           . '<strong>WHMCS email verification is off.</strong> New clients are never sent a '
           . 'confirmation link, so anyone this gate stops can only escape via the '
           . '"Send me a new link" button on the gate page. Turn it on at '
           . '<em>System Settings &rarr; General Settings &rarr; Security &rarr; Email Verification</em>.'
           . '</div>';
    }

    if (!$settings['enabled']) {
        echo '<div class="alert alert-info">Enforcement is <strong>off</strong>. '
           . 'Nobody is being gated; the addon is installed but idle.</div>';
    }

    $blocked = vpnhoodverify_gatedClientCount($settings);

    echo '<table class="table table-condensed" style="width:auto;">'
       . '<tbody>'
       . '<tr><td>Enforcement</td><td><strong>' . ($settings['enabled'] ? 'ON' : 'off') . '</strong></td></tr>'
       . '<tr><td>Applies to</td><td>' . htmlspecialchars($settings['scope'], ENT_QUOTES) . '</td></tr>'
       . '<tr><td>New-client cutoff</td><td><code>'
       . htmlspecialchars($settings['cutoff'] !== '' ? $settings['cutoff'] : '(not set)', ENT_QUOTES)
       . '</code></td></tr>'
       . '<tr><td>WHMCS email verification</td><td>' . ($globalOn ? 'enabled' : '<strong>disabled</strong>') . '</td></tr>'
       . '<tr><td>Clients currently gated</td><td><strong>' . $blocked . '</strong></td></tr>'
       . '</tbody></table>';

    if ($settings['scope'] === VerifyGate::SCOPE_NEW && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $settings['cutoff'])) {
        echo '<div class="alert alert-danger">The cutoff date is missing or malformed, so '
           . '"New clients only" cannot tell who is new and <strong>nobody is gated</strong>. '
           . 'Set it to a valid YYYY-MM-DD date.</div>';
    }

    echo '<h3>If this locks someone out</h3>'
       . '<p>The gate always leaves <code>logout</code>, WHMCS\'s own verification link, and '
       . 'password reset reachable, and the gate page can mail a fresh link (WHMCS\'s expires '
       . 'after 60 minutes). To open the doors for everyone at once, set <em>Enforce '
       . 'Verification</em> to No, or deactivate this addon — that stops the gate loading at all. '
       . 'The admin area is never affected.</p>';
}

/**
 * How many clients the gate would stop right now: in scope, and with no confirmed
 * address in tblusers. Matched on lowercased email because tblclients and tblusers
 * are separate records that only agree by address.
 */
function vpnhoodverify_gatedClientCount(array $settings): int
{
    try {
        $query = Capsule::table('tblclients')
            ->whereNotExists(function ($q) {
                $q->select(Capsule::raw(1))
                  ->from('tblusers')
                  ->whereRaw('LOWER(tblusers.email) = LOWER(tblclients.email)')
                  ->whereNotNull('tblusers.email_verified_at');
            });

        if ($settings['scope'] === VerifyGate::SCOPE_NEW) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $settings['cutoff'])) {
                return 0;
            }
            $query->where('tblclients.datecreated', '>=', $settings['cutoff']);
        }

        return (int) $query->count();
    } catch (\Throwable $e) {
        return 0;
    }
}

/**
 * The gate page — the only client-area page this addon serves, and the only place
 * the redirect sends people. It exists to be escapable: the single action is to
 * mail a fresh confirmation link, because WHMCS's own expires after 60 minutes.
 */
function vpnhoodverify_clientarea($vars): array
{
    $clientId = (int) ($_SESSION['uid'] ?? 0);
    $email = $clientId > 0 ? VerifyGate::clientEmail($clientId) : '';

    $attempted = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'resend';
    $sent = $attempted && VerifyGate::sendVerificationEmail($email);

    // Someone who confirmed in another tab should not be stuck staring at this page.
    $verified = $email !== '' && VerifyGate::isEmailVerified($email);

    return [
        'pagetitle'    => 'Confirm your email address',
        'breadcrumb'   => ['index.php?m=' . VerifyGate::MODULE => 'Confirm your email address'],
        'templatefile' => 'verify-email',
        'requirelogin' => true,
        'vars'         => [
            'email'     => $email,
            'resent'    => $sent,
            'attempted' => $attempted,
            'verified'  => $verified,
            'module'    => VerifyGate::MODULE,
        ],
    ];
}
