# VpnHood.WHMCS — Architecture & Developer Guide

Developer-facing documentation for this repository. End-user/admin install steps live
in the per-module `README.md` files; this document explains **how the pieces fit
together and how to extend them**.

## Repositories

There are two repos in this product:

| Repo | Runs on | Contains | Audience |
|------|---------|----------|----------|
| **VpnHood.WHMCS** (this repo) | **our** WHMCS | `vpnhoodstore`, `vpnhoodconfig`, `vpnhoodpartnerhub`, `vpnhoodverify` | internal |
| **VpnHood.WHMCS.Partner** | a **partner's** WHMCS | `vpnhoodpartner` (connector) | external partners |

The connector is a **separate repo** on purpose: it ships to outside parties, must not
contain our access-server internals, and versions independently. See that repo's
`docs/DEVELOPMENT.md`.

## The two integration models

```
Model A (retail):   Our WHMCS ─ vpnhoodstore ─▶ VpnHood Access Server
Model B (wholesale): Partner WHMCS ─ vpnhoodpartner ─▶ Our WHMCS ─ vpnhoodpartnerhub ─ vpnhoodstore ─▶ Access Server
                                                          (paid from partner's native WHMCS credit)
```

Model B reuses Model A's provisioning path — it never re-implements access-server calls.

## Modules in this repo

### `modules/servers/vpnhoodstore/` (server/provisioning)
Provisions VpnHood access tokens directly against the access server. Core pieces:
- `vpnhoodstore.php` — WHMCS lifecycle hooks (`_CreateAccount`, `_Renew`, `_SuspendAccount`,
  `_UnsuspendAccount`, `_TerminateAccount`, `_ClientArea`) and `_ConfigOptions`.
- `lib/ApiService.php` — REST calls to `https://api.vpnhood.com/api` (token CRUD, access code,
  CSV export, lookups). Reads API key + project id from the `vpnhoodconfig` addon settings.
- `lib/Helper.php` — business logic: create/update tokens, renew/suspend, fetch access code/CSV.
  Stores the created token id in the service's `serviceProperties['accessTokenId']`.
- `lib/AsyncApiClientFactory.php` — cURL client (Bearer auth) singleton.

**Product config options** are stored positionally by WHMCS in `tblproducts.configoptionN`,
in the order `_ConfigOptions` declares them:

| Slot | Field | Notes |
|---|---|---|
| `configoption1` | Server Farm | serverFarmId |
| `configoption2` | Access Token Name | free text |
| `configoption3` | Access Token Profile | accessTokenProfileId |
| `configoption4` | Token Delivery Method | `0` = Normal, `1` = CSV |
| `configoption5` | Access Token Groups | accessTokenGroupId, `0` = None |

> **Inserting or reordering a field shifts every slot after it.** WHMCS does not migrate
> existing rows, so any product saved under the old layout keeps its old values in the old
> slots and must be re-saved in the admin UI. `configoption4`/`configoption5` were introduced
> by the Token Delivery Method field — before it, the access token group lived in
> `configoption4`. Prefer appending new fields last.

**Delivery mode** is decided by `Helper::isCsvTokenDelivery($deliveryType, $count, $allowQty)` —
the single source of truth, also called by `vpnhoodpartnerhub`. CSV (bulk) applies when the
product is explicitly set to CSV, or implicitly for Scaling Service products (`allowqty` 2)
ordered with more than one unit. Normal delivery stores an `accessTokenId` on the service;
CSV delivery does **not** — bulk keys are read back by `customerId` + `orderId`.

**Reuse contract:** `ApiService` and `Helper` are the reusable provisioning primitives.
`vpnhoodpartnerhub` calls them directly — do not duplicate access-server logic elsewhere.

### `modules/addons/vpnhoodconfig/` (addon)
Global settings store (API Key, Project ID, reseller restriction settings) in
`tbladdonmodules`. Also drives the product-visibility hook
`includes/hooks/vpnhoodstore-restrict-user-group-products.php`.

### `modules/addons/vpnhoodverify/` (addon) — forced email verification
Makes email verification mandatory for the client area. WHMCS's own
`EnableEmailVerification` (General Settings → Security) mails the link and records the
result in `tblusers.email_verified_at`, but is **deliberately non-blocking** — an
unverified client browses the portal normally. This addon supplies only the missing
enforcement: it owns no tables, keeps no verified-flag of its own, and treats
`tblusers.email_verified_at` as the sole authority.

- `vpnhoodverify.php` — settings, `_activate` (stamps the new-client cutoff), `_output`
  (admin status + "clients currently gated" count), `_clientarea` (the gate page).
- `hooks.php` — the `ClientAreaPage` gate. **Lives inside the addon, not `includes/hooks/`,
  on purpose:** WHMCS loads an addon's `hooks.php` only while that addon is active, which
  makes deactivation a real kill switch for a module whose failure mode is locking every
  client out of the portal.
- `lib/VerifyGate.php` — settings reader, scope check, verified check, resend.
- `templates/verify-email.tpl` — the gate page.

Scope is either `Every client` or `New clients only`, the latter keyed on
`tblclients.datecreated >= CutoffDate` (stamped at activation). The cutoff exists because
switching `EnableEmailVerification` on does **not** mail existing clients — gating them
would bar people who were never sent a link.

**Gates client-area pages only.** Not registration (WHMCS creates the client *then* mails
the link — there is nothing to hook), not checkout (it would break
register-and-order-in-one-step), not the admin area, and not `vpnhoodiap`'s `api.php`, so
app-store purchases keep working throughout. The whitelist (`logout`, `verifyemail`,
`password-reset`, WHMCS's `/user/verify`, this addon's page, `vpnhoodiap`'s pages) is
load-bearing: WHMCS's link expires after 60 minutes and its own recovery advice is to log
in and request a new one. Any exception fails open.

### `modules/addons/vpnhoodpartnerhub/` (addon) — wholesale gateway
Turns our WHMCS into a partner-scoped wholesale API. **It adds only partner management +
a secured API; it does not own credit or provisioning.**

- `vpnhoodpartnerhub.php` — addon config, `_activate`/`_deactivate` (table create/drop),
  `_output` (admin UI for partners + product mappings + credentials).
- `api.php` — public POST/JSON endpoint. Bootstraps WHMCS via `init.php`, authenticates,
  dispatches, logs.
- `lib/Auth.php` — key/secret auth (`X-Vpnhood-Key` / `X-Vpnhood-Secret`), status + IP gating.
- `lib/PartnerApiController.php` — the actions. Provisioning via WHMCS `localAPI`
  (`AddOrder`→`AcceptOrder`), settled from **native WHMCS credit**, then reads the access
  code back through `ApiService`/`Helper`.
- `lib/PartnerRepository.php` — data access + native credit reads.
- `lib/ApiException.php` — carries an HTTP status for structured error responses.

## Data model (Partner Hub)

Created by `vpnhoodpartnerhub_activate()`:

```
mod_vpnhood_partners
  id, client_id (→ tblclients.id, holds native credit),
  name, api_key (unique), api_secret_hash (password_hash),
  status (active|suspended), ip_allowlist (csv), created_at, updated_at

mod_vpnhood_partner_products
  id, partner_id (→ mod_vpnhood_partners.id),
  downstream_ref (partner-facing product key), whmcs_product_id (→ tblproducts.id),
  billing_cycle_months, enabled
  UNIQUE(partner_id, downstream_ref)

  The admin UI adds a mapping from just a product picker: downstream_ref is set to the
  whmcs_product_id (as a string), billing_cycle_months is derived from the product's
  pricing (PartnerRepository::productBillingCycleMonths), and enabled defaults to 1.
  The columns remain in the schema/API for forward compatibility.

mod_vpnhood_partner_log
  id, partner_id, action, remote_ip, http_status, request, response, created_at
```

**Credit is NOT stored here** — it is the native WHMCS client credit
(`tblclients.credit`, history in `tblcredit`). This is intentional.

## Request lifecycle — `order` action

1. `api.php` authenticates the partner (key/secret → status → IP allowlist).
2. `PartnerApiController::order` resolves `downstreamRef` → mapped, enabled product, then
   `resolveBillingCycle` picks the cycle: the connector's requested `billingCycle` when it is
   one of the product's available cycles, else the mapping's default (an unsupported requested
   cycle is rejected with `422`).
3. For each unit: `placeSingleOrder`:
   1. `localAPI('AddOrder')` — creates order + invoice; WHMCS auto-applies the client's
      credit to the invoice.
   2. `assertInvoicePaid` — **before provisioning**, require invoice `Paid`; otherwise
      roll back and throw `402`. Rollback is `CancelOrder` then `DeleteOrder` (WHMCS refuses
      to delete an order unless it is Cancelled/Fraud), and both results are logged to
      `mod_vpnhood_partner_log` (action `rollback`) so an incomplete teardown is never silent.
   3. `localAPI('AcceptOrder', autosetup)` — runs `vpnhoodstore_CreateAccount`.
   4. `readDelivery` — read `accessTokenId` from the service's `serviceProperties` and
      fetch the access code via `ApiService::getAccessCode` (or CSV for bulk).
4. Response: `{ keys: [{ upstreamOrderId, customerReference, deliveryType, accessTokenId + accessCode | csv }] }`.

**`upstreamOrderId` is the connector-facing handle** for every subsequent action (`renew`,
`suspend`, `unsuspend`, `terminate`, `getOrder`, `getAccessCode`). It is the WHMCS **order id**;
`ownedServiceByOrder()` resolves it to the service *and* scopes on `partner.client_id` in the
same query, so another partner's order simply returns `404`. `getAccessCode` re-reads the code
live from the access server, resolving `accessTokenId` from the partner's own service rather
than accepting it from the request.

It is deliberately **not** the service id, even though one Hub order maps to exactly one
service. Both ids are dense integer sequences over different tables, so the same number is
live in both for different customers, and a lookup that accepted either would silently act on
the wrong key. One handle, therefore: the order id — surfaced next to the key wherever a
partner can read it (`vpnhoodstore` client area and admin tab here, `vpnhoodpartner`'s admin
tab downstream), and named in the `404` when the other id arrives.

### Error statuses: never 5xx for a rejection the partner can act on

`PartnerApiController::localApi()` wraps every `localAPI` call and turns a non-`success`
result into an `ApiException` with **422**, message `Upstream WHMCS rejected <Action>: <its
message>`. It must not be a 5xx, and that is not a style preference:

> **Cloudflare replaces the body of a 5xx origin response with its own error page.** The
> connector therefore receives `Invalid response from Hub (HTTP 502): error code: 502` and
> the real reason never crosses the wire. A 4xx body passes through untouched.

This cost a live debugging session: every partner order was being rejected with *"Invalid
Payment Method. Valid options include banktransfer,…"* because the addon's **Order Payment
Gateway** was set to a gateway's display name (`Offline Crypto Transfer`) instead of its
system name (`banktransfer`) — `AddOrder` only accepts the system name. The Hub reported it
correctly; Cloudflare ate the sentence. Keep new failure paths in the 4xx range whenever the
caller could do something about them.

The misconfiguration itself is now unreachable: the setting is a dropdown built from
`tblpaymentgateways` (`vpnhoodpartnerhub_gatewayField()`), so only real system names can be
stored, and `vpnhoodpartnerhub_gatewayVerdict()` states the verdict on the current value in
the field's own description — the addon page's `vpnhoodpartnerhub_gatewayWarning()` banner
is not where an admin lands after **Save Changes**.

### The cart guard must not fire on a Hub order

`includes/hooks/vpnhoodstore-restrict-user-group-products.php` hooks `PreCalculateCartTotals`
and silently removes any cart item whose **product group name** appears in `vpnhoodconfig`'s
`RestrictedProductGroupNames`, unless the logged-in client is in `AllowedClientGroups`.

That check asks *who is browsing*. A Hub order has nobody browsing: `api.php` calls
`localAPI('AddOrder')` with no authenticated client, so `isResellerUser()` returned false,
every partner product was stripped, and WHMCS failed the order with **"No items remain in
the cart. Order cannot proceed."** On our production install both partner groups are listed
in that setting, so **no Hub order had ever succeeded** — the partner products had zero
services to their name.

`isServerSidePurchase()` now exempts `OrderPurchaseSource::ADMIN` and `::LOCAL_API`. Every
other source — `CLIENT`, `CLIENT_API`, an admin masquerading as a client, and a missing or
unrecognised value — stays guarded, so the customer-facing restriction is unchanged.

> Reproducing this needs an **HTTP** request, not `php script.php`. The guard returns early
> when `$_SESSION['cart']['products']` is empty, and there is no session under the CLI, so a
> CLI test passes no matter how badly the hook is broken. Two rounds of CLI testing here
> "cleared" the hook before an HTTP test reproduced the failure on the first attempt.

> **WHMCS deletes an addon's `tbladdonmodules` rows on deactivate.** Every setting comes
> back blank on reactivation (the module's own tables survive). Verified on 9.0.3. This
> applies to the partner-side `vpnhoodpartnerconfig` too, where it wipes the Hub URL, API
> key and API secret — and the secret is stored only as a hash upstream, so it cannot be
> read back and must be regenerated. Record settings before deactivating anything.

## Renewals — recurring Hub products are MANUAL

Recurring products sold through the Hub do **not** auto-renew. WHMCS generates the renewal
invoice and its email exactly as standard; it simply stays **Unpaid** until the partner calls
`renew`. Nothing is suppressed, reversed, or re-dated — no hook is involved.

> **REQUIRED SETTING:** WHMCS *Automatic Credit Use* must be **OFF**
> (Configuration → System Settings → General Settings → Credit). This is the mechanism: with
> it off, no invoice is ever paid from credit on its own, so renewal invoices naturally stay
> Unpaid. The Hub instead applies credit **explicitly**, only where it means to
> (`PartnerApiController::settleFromCredit`). If someone turns this setting back on, partner
> services silently revert to auto-renewing.

- **Order:** `placeSingleOrder` calls `settleFromCredit()` on the order invoice, then
  `assertInvoicePaid` before provisioning — so ordering still fails closed on insufficient
  credit (`402` + rollback).
- **Renewal:** the cron-generated renewal invoice is left completely alone and stays Unpaid.
  `nextduedate` does not advance while it is unpaid, and the token expiry tracks `nextduedate`,
  so **access stops on the real term end** until the partner renews.
- **Renew:** `PartnerApiController::renew` pays the outstanding invoice from native credit
  (`402` if short, `409` if nothing outstanding). Paying a Hosting renewal invoice drives
  WHMCS's normal renewal — `nextduedate` advances one cycle and `vpnhoodstore_Renew` re-syncs
  the token; the call then re-asserts the token expiry idempotently.
- **Scope:** `isPartnerProductService` — the service's product is in
  `mod_vpnhood_partner_products`. Partner products are distinct from retail products, so retail
  is never affected and no per-service marker is needed. Non-Hub services (one-time products,
  anything created outside the Hub) fall back to a plain expiry re-sync (`resyncExpiry`).
- **Overdue automation:** the unpaid invoice goes overdue normally, so WHMCS's standard
  suspend/terminate automation applies. Suspension is harmless (the token is expiring anyway),
  but auto-**termination** would destroy the service before the partner can renew — control
  this in WHMCS *Automation Settings* (termination window), not in module code.

> **Unverified against a live WHMCS.** Confirm that `applyCredit()` consumes
> `tblclients.credit` and that paying the renewal invoice triggers the native renewal
> (`nextduedate` advance + `vpnhoodstore_Renew`).

## Extending

- **New API action:** add a `case` in `PartnerApiController::handle`, implement a private
  method, document it in the addon `README.md` table and in the connector's API contract doc.
- **New partner attribute:** add a column in `_activate` (and handle upgrades — see below),
  surface it in `_output`, read it in `PartnerRepository`/`Auth`.
- **Schema upgrades:** `_activate` only creates tables when missing. For changes to an
  already-installed table, guard with `Schema::hasColumn(...)` and `ALTER` — do not assume a
  fresh install. Keep `_deactivate` in sync.
- **Never trust client-supplied ids:** every action scopes to `partner.client_id`
  (`ownedService()` enforces ownership). Preserve this when adding actions.
- **Reuse provisioning:** call `ApiService`/`Helper`; never hand-roll access-server requests.

## Versioning & releases

Every module in this repo carries the **same** version number — they are built, deployed and
supported together, so "what version are you on?" has exactly one answer, and it matches the
git tag.

- **`VERSION` (repo root) is the single source of truth.** Never hand-edit a version inside a
  module; it will be overwritten.
- **`scripts/set-version.sh`** stamps `VERSION` into every module. `--check` verifies they all
  agree and exits non-zero if not (CI runs this after stamping). Run it locally any time to
  re-sync; it is idempotent.
- **`.github/workflows/release.yml`** is run by hand (Actions → Release → Run workflow) —
  nothing is released on push, a release is always a deliberate act. It bumps the version
  (patch by default), stamps it, commits `Release vX.Y.Z`, tags, builds `vpnhoodhub.zip`
  (bundling the `vpnhoodiap` release pinned in `IAP_VERSION`) and publishes a GitHub Release.

Where the number lands, and why the two mechanisms differ:

| Module kind | Stored in | Shown to an admin |
|---|---|---|
| Addon (`vpnhoodconfig`, `vpnhoodpartnerhub`) | `'version'` in `<module>_config()` | Natively, in System Settings → Addon Modules |
| Server (`vpnhoodstore`) | `"version"` in `whmcs.json` | **Not natively** — WHMCS has no version display for provisioning modules, so `vpnhoodconfig_output()` reads the manifest back and renders it |

> `MetaData()`'s `APIVersion` is the WHMCS *module API* contract, not the module's own
> version — leave it alone.

The connector repo (**VpnHood.WHMCS.Partner**) has the same `VERSION` + script + workflow, but
versions **independently**: the two ship to different WHMCS installs on their own cadence. The
Hub API contract is what couples them, not the version number.

## Update notice & package contracts

WHMCS has no update channel for third-party modules (its own updater covers the core only),
so an install can sit years behind and nobody hears about it. Every VpnHood package therefore
ships the same self-contained check, `modules/widgets/vpnhoodupdates.php`, which renders a
**VpnHood! Modules** widget on the admin dashboard and the package table on our addon pages:
what is installed, whether GitHub has a newer release, and whether the packages fit each other.
It **only reports** — installing an update stays a deliberate human act.

- **Discovery is by `vhcontract.json`**, a static file next to every module: who it is, which
  package/repo it comes from, and the cross-module contract it `provides` or `requires`. A
  static file (not a function) is the point: any module can read any other's declaration
  without loading its code, which is what lets independently shipped packages cooperate.
- **The `store` contract** is the surface `vpnhoodiap` reuses from `vpnhoodstore` (its
  `ApiService` and the service properties provisioning writes). `vpnhoodstore` declares
  `provides.store`, `vpnhoodiap` declares `requires.store`; a provider that is installed but too
  old shows as a red mismatch, a provider that is absent is a supported shape (iap on a partner
  install degrades on purpose). Bump the level only when that surface changes in a way an
  older consumer would not survive. The connector does **not** provide `store` — iap never
  reaches into it.
- **Network only from the daily cron.** Each addon's `hooks.php` registers `DailyCronJob` →
  `VpnHoodUpdateCheck::refresh()`; the cache (24 h, failures retried hourly) lives in
  `tbladdonmodules` under `vpnhoodupdates`. Pages and the widget render the cache and never
  call GitHub; "Check now" on an addon page forces a refresh. Unauthenticated GitHub API on
  purpose — it runs on installs we do not own.
- **The widget file is byte-identical in all four repos** (hub, partner, iap, sign-in) at the
  same path — the filesystem de-duplicates, the last extract wins, every copy behaves the same.
  **If you change it, change all four**, and never replace `modules/widgets/` on a server: it is
  shared with WHMCS's own widgets (`deploy-dev.sh` overlays it).
- The table groups by **package**, since that is what an admin installs; modules inside one
  package that disagree on version mark it *half-deployed* and name the stale module, and such a
  package never reads as "up to date".

## Conventions

- PHP 7.4+; WHMCS `Capsule` ORM for DB; `logModuleCall()` for diagnostics.
- WHMCS module folder names: lowercase letters/numbers, no underscores/spaces.
- Secrets stored hashed; transport assumed HTTPS.
- No PHP toolchain is configured in this environment — there is no `composer`/lint step;
  verify changes on a real WHMCS instance (see each module README's verification notes).

## Testing / verification

There are no automated tests. Verify against a live WHMCS:
1. Activate `vpnhoodpartnerhub`; confirm the three tables exist.
2. Create a partner linked to a WHMCS client, add credit, map a product.
3. `curl` the API: `getBalance`, then `order` — confirm an order+invoice were created,
   invoice paid from credit, credit decreased, `vpnhoodstore` provisioned a token, and a
   valid access code returned. Insufficient credit must roll back and return `402`.
4. Exercise `renew`/`suspend`/`unsuspend`/`terminate` and confirm effects + module log.
