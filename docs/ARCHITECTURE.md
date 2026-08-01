# VpnHood.WHMCS — Architecture & Developer Guide

Developer-facing documentation for this repository. End-user/admin install steps live
in the per-module `README.md` files; this document explains **how the pieces fit
together and how to extend them**.

## Repositories

There are two repos in this product:

| Repo | Runs on | Contains | Audience |
|------|---------|----------|----------|
| **VpnHood.WHMCS** (this repo) | **our** WHMCS | `vpnhoodstore`, `vpnhoodconfig`, `vpnhoodpartnerhub` | internal |
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

**Reuse contract:** `ApiService` and `Helper` are the reusable provisioning primitives.
`vpnhoodpartnerhub` calls them directly — do not duplicate access-server logic elsewhere.

### `modules/addons/vpnhoodconfig/` (addon)
Global settings store (API Key, Project ID, reseller restriction settings) in
`tbladdonmodules`. Also drives the product-visibility hook
`includes/hooks/vpnhoodstore-hidden-reseller-products.php`.

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
