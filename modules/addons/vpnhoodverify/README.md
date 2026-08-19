# VpnHood! Verify

Makes email verification **mandatory** for the WHMCS client area.

## Why this exists

WHMCS already has email verification — *System Settings → General Settings → **Security**
→ Email Verification* (`EnableEmailVerification`). It mails a confirmation link when a
client is created or changes address, and records the outcome in
`tblusers.email_verified_at`. What it does **not** do is block anything. From WHMCS's own
documentation:

> The user can access the Client Area, services, and support resources normally prior to
> email verification completion.

It also never mails existing clients retroactively — they simply read as unverified
forever.

So WHMCS supplies the mail and the verified state; this addon supplies the missing
enforcement, and nothing else. It owns no tables, stores no verification state of its own,
and never decides for itself whether an address is confirmed — `tblusers.email_verified_at`
is the only authority.

## What it does and does not gate

**Gated:** client-area pages. An unverified client is redirected to a confirmation page
that can mail them a fresh link.

**Not gated, deliberately:**

- **Registration.** WHMCS creates the client record and *then* mails the link, so there is
  no "confirm before the account exists" step to hook into. Forcing verification always
  means *the account exists, but the portal stays shut*.
- **Ordering / checkout.** Gating checkout would break register-and-order-in-one-step — a
  brand-new client cannot possibly have clicked a link yet.
- **The admin area.** `ClientAreaPage` fires client-side only.
- **App-store purchases.** `vpnhoodiap`'s `api.php` is not a client-area page, so
  subscriptions bought in the app keep working throughout.

## Settings

| Setting | Meaning |
|---|---|
| **Enforce Verification** | Master on/off. Off leaves the addon installed but gating nobody. |
| **Applies To** | `New clients only` (default) or `Every client`. |
| **New-Client Cutoff** | `YYYY-MM-DD`, stamped with today's date at activation. Backs *New clients only*: clients created before it are never gated. |
| **Additional Allowed Pages** | Comma-separated page filenames that stay reachable while gated, on top of the built-in list. An escape hatch that needs no deploy. |

### Why the cutoff exists

Enabling `EnableEmailVerification` does **not** send a link to existing clients. Gating
them would bar people who were never given the chance to verify. The cutoff — stamped when
you activate the addon — draws the line at the day the gate was switched on. Deactivating
clears WHMCS's settings rows, so re-activating legitimately re-stamps it; "new" means new
as of when the gate was last turned on.

Choose `Every client` only if you intend a forced re-verification of your whole client
base. They can still escape via the resend button, but expect support contacts.

## Getting out of trouble

The failure mode of a gate like this is locking clients out of the portal, so there are
five independent ways out, in order of reach:

1. **Deactivate the addon.** WHMCS loads `modules/addons/<name>/hooks.php` only while the
   addon is active, so deactivating stops the gate loading at all. This is why the hook
   lives here and not in `includes/hooks/`.
2. **Enforce Verification → No.**
3. **Additional Allowed Pages**, for a page we did not anticipate.
4. The built-in whitelist: `logout`, `verifyemail`, `password-reset`, `pwreset`, WHMCS's
   own `/user/verify` handler, this addon's gate page, and `vpnhoodiap`'s pages.
5. **Fail-open on any exception** — a gate that cannot read its own state does not bar the
   door; it logs to the module log and lets the request through.

The whitelist is not cosmetic. WHMCS's verification link lives **60 minutes**, and WHMCS's
own advice for an expired one is "login to the client area to request a new link" — a gate
that bounced every page would make recovery impossible.

## Files

| File | Purpose |
|---|---|
| `vpnhoodverify.php` | Settings, activation (cutoff stamp), admin status page, gate page |
| `hooks.php` | The `ClientAreaPage` gate. Loaded by WHMCS only while the addon is active |
| `lib/VerifyGate.php` | Shared settings/verification helpers used by both entry points |
| `templates/verify-email.tpl` | The gate page |

## Verification (no local test tooling — use the dev WHMCS)

Deploy with `scripts/deploy-dev.sh hub`, then at `https://whmcs-dev.vpnhood.com`:

1. Addon inactive → the client area behaves normally (proves the kill switch).
2. Activate; confirm the admin page shows the cutoff stamped with today and enforcement
   off. Set **Enforce Verification = Yes**, **Applies To = New clients only**.
3. An existing test client browses freely. Register a fresh client → every client-area
   page redirects to the gate.
4. From the gate: **logout works**, the resend button sends mail, and following the link
   opens the portal. Confirm `tblusers.email_verified_at` is now set.
5. Switch to **Every client** → the existing test client is gated too; verifying opens it.
6. Confirm admin login and `modules/addons/vpnhoodpartnerhub/api.php` are unaffected.
