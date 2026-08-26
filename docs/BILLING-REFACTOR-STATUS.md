# Billing Refactor — Status & AI Handoff

> The **current state** of the Store.Server → WHMCS billing refactor: what is built,
> where it lives, how to work on it, and what remains. Written for developers (and
> their AI assistants) picking up the work. The full design document (architecture,
> flow charts, security model, phasing) lives in the owner's private ops repo — ask
> for it if you need the deep rationale. Keep this file updated as phases complete.
> Last updated: 2026-08-26.

## What this is

VpnHood.Store.Server is being replaced by a WHMCS addon (`vpnhoodiap`) that turns
app-store purchases (Google Play live, Apple live, Microsoft later) into WHMCS
clients/orders/paid invoices, delivering access codes through the existing
provisioning modules. The client apps talk to a backend-agnostic **Portal API v1**
(JSON action envelope) — no WHMCS concept ever appears on the wire, so the backend
can be swapped later without touching the apps.

## Repos and where the work lives

| Repo | Branch | What it holds |
| --- | --- | --- |
| [VpnHood.WHMCS.Iap](https://github.com/vpnhood/VpnHood.WHMCS.Iap) (public) | `main` | The whole PHP module: addon `vpnhoodiap`, gateway `vpnhoodiappay`, hooks, unit + integration tests, release workflow. Own version stream. |
| VpnHood.WHMCS (this repo) | `main` | Hub modules (`vpnhoodstore`, `vpnhoodconfig`, `vpnhoodpartnerhub`) + release workflow bundling vpnhoodiap verbatim (`IAP_VERSION` pin). `scripts/deploy-dev.sh iap` deploys the sibling Iap repo to dev. |
| VpnHood.WHMCS.Partner | `main` | Partner connector + release workflow, same vpnhoodiap bundling. |
| VpnHood (client) | `refactor/billing-abstractions` | `src/AppLib/VpnHood.AppLib.Portal` (C# Portal API client: auth/order/account providers), `src/AppLib/VpnHood.AppLib.Ios.AppStore` (StoreKit 2 + Sign in with Apple), Android wiring behind `AppConfigs.PortalBaseUri` (null = legacy Store.Server), tests in `VpnHood.AppLib.Test` (`PortalTest`, `TestPortalServer`). |

Docs inside the repos: Iap `README.md` + `CLAUDE.md` (module architecture, API
contract, test harness), this repo's [ARCHITECTURE.md](ARCHITECTURE.md),
`VpnHood/src/AppLib/VpnHood.AppLib.Ios.AppStore/swift/README.md` (Swift facade
contract).

## Shipped state (2026-08-26)

- **Releases live**: Iap **v1.2.1**, hub **v1.1.0** (bundling that Iap verbatim).
  **Production (`account.vpnhood.com`) runs hub 1.1.0 + iap 1.2.1 since 2026-08-26**,
  with `_upgrade()` verified against live data. The partner repo pins
  `IAP_VERSION` 1.2.1; its release is dispatched separately ("ship iap").
- **Google Play**: full pipeline implemented (verify, RTDN webhook with Pub/Sub OIDC
  auth, renewals, voided sweep, reconciliation cron). Proven end-to-end on dev WHMCS
  with a fake store adapter (real order, paid invoice, real access code from
  api.vpnhood.com, idempotent replay, cross-user 403). **Live e2e still pending** —
  blocked on external setup (below).
- **Apple**: fully implemented both sides. PHP: JWKS→PEM, Sign in with Apple,
  App Store Server API (.p8 ES256 token, prod→sandbox fallback), JWS x5c chain
  pinned to Apple Root CA-G3, ASSN V2 event mapping. C#: `AppStoreBillingProvider`,
  `AppleAuthenticationProvider`, Swift facade sources + build script (xcframework
  not yet built — needs a Mac).
- **Tests**: Iap 47/47 unit + 5 integration suites green on dev WHMCS;
  client `PortalTest` 7/7 green.
- **Android**: composition switches to Portal when `AppConfigs.PortalBaseUri` is set;
  currently null, so production still uses Store.Server. The flip is the switchover.

## How to work on it

- Dev WHMCS: `whmcs-dev.vpnhood.com` (WHMCS 9.0.3, PHP 8.3). Credentials and SSH
  keys live in the owner's private ops repo — ask; **never commit secrets to any
  repo, never echo them into logs**.
- Deploy the module: `scripts/deploy-dev.sh iap` (run from this repo; ships the
  sibling Iap working tree). The overlay never deletes remote files — remove stale
  files by hand when renaming.
- Test: `scripts/test-dev.sh [all|unit|integration|<name>]` (Iap repo; runs PHP on
  the server over SSH — no local PHP needed).
- Client tests: `dotnet test` on `VpnHood.AppLib.Test` (PortalTest uses a scripted
  in-process HTTP server, no backend needed).
- Releasing: always **Iap first** (Actions → Release), then hub/partner (their
  workflows download the pinned Iap release asset). Bump `IAP_VERSION` in hub and
  partner when pinning a new Iap release. Shipped versions are immutable — the
  workflows enforce payload-diff guards; bump, never republish changed code.

## Invariants — do not break these

1. **No WHMCS concept on the Portal wire, ever.** No WHMCS ids, error strings, or
   field names in api.php responses. The contract owns api.php, not the reverse.
2. **Gateway is `vpnhoodiappay`, never `vpnhoodiap`.** WHMCS loads addon and gateway
   config functions in one admin request; a shared prefix is a site-wide fatal
   ("cannot redeclare vpnhoodiap_config").
3. **The bundled vpnhoodiap.zip is verbatim.** Hub/partner packages never restamp
   its version — WHMCS keys `_upgrade()` on the version inside `vpnhoodiap_config()`,
   and the same code must carry the same number on every install.
4. **`redeem()` uses a MySQL advisory lock (`GET_LOCK`), not a DB transaction** —
   WHMCS localAPI commands commit internally and would silently end an outer
   transaction.
5. **`adapter->finalize()` (acknowledge/consume) only after provisioning succeeds.**
   Unacknowledged Google purchases auto-refund in ~3 days — that is the fail-safe.
6. **Proofs and webhook payloads are pointers.** Entitlement state is always
   re-fetched from the store API; never trust client- or webhook-supplied data.
   Tenant comes from config, never from the payload.
7. **Webhooks never 5xx after auth** — record failures, answer 200, or the store
   retry-loops. Dedup on `(store, message_id)`.
8. **Purchase tokens and store order ids never go back to clients.** Id tokens,
   proofs and session tokens are redacted from audit logs (`vpnhoodiap_redact`).
9. **No secrets in any repo.** Store credentials live in WHMCS settings
   (EncryptPassword, write-only in the admin UI).
10. **Session binding guard**: a verified purchase's obfuscated account id must
    equal the session's `external_uid`, else 403 — never provision across users.
11. C# house rules: required `CancellationToken` (no `= default`), no null
    suppression (`!`), one public type per file, BCL types in signatures.

## Remaining work (in order)

1. **W0 external setup** (owner-only, blocks the Google live check): Play Console
   service-account grant, Pub/Sub topic + OIDC push subscription → webhook URL,
   SA JSON entered in the addon's Apps tab.
2. **Google live e2e**: real idToken → `POST /auth/sessions`, internal-testing purchase →
   `POST /billing/purchases` returns the code, RTDN renewal/cancel/refund cycles, Play
   Console test notification.
3. **Build `VpnHoodStoreKit.xcframework` on a Mac** (`swift/build-xcframework.sh`)
   and commit it (conditional NativeReference picks it up).
4. **Android switchover**: set `AppConfigs.PortalBaseUri`, ship, monitor; Store.Server
   then serves old app versions only. **Held by owner (2026-08-26): existing users are
   not touched until the new app publishes** (this also holds the production
   `backfill-account-keys.php` run).
5. **Connect.Ios app** creation + wiring (AppleAuthenticationProvider →
   PortalAuthenticationProvider → AppStoreBillingProvider → PortalAccountProvider);
   StoreKit sandbox e2e incl. Restore Purchases.
6. **C4/W5 Microsoft Store** (client provider + adapter; no webhook exists —
   client-push + daily re-validation).
7. **M1 migration**: export Store.Server SQL → create/link WHMCS clients by email →
   seed `_users`/`_purchases`.
8. **Decommission Store.Server** only after 4 + 7 and old-version churn: freeze →
   shut down → archive the repo (do not delete; history is needed).
9. Ops: enable `EnableEmailVerification` on installs running vpnhoodiap (the
   account gate treats existing emails as unverified while it is off — fail-closed).
