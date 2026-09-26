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
- `hooks.php` — the `ClientAreaPage` gate, and the `AfterShoppingCartCheckout` hold that
  sends an unverified client to the gate page instead of the payment gateway. **Lives
  inside the addon, not `includes/hooks/`, on purpose:** WHMCS loads an addon's `hooks.php`
  only while that addon is active, which makes deactivation a real kill switch for a module
  whose failure mode is locking every client out of the portal.
- `lib/VerifyGate.php` — settings reader, scope check, verified check, resend, gate URL,
  and `checkoutHoldUrl()` — the whole checkout-hold decision, kept out of the hook so
  `tests/integration/verify-checkout.test.php` can exercise it in-process.
- `templates/verify-email.tpl` — the gate page (invoice-aware when reached from checkout).

Scope is either `Every client` or `New clients only`, the latter keyed on
`tblclients.datecreated >= CutoffDate` (stamped at activation). The cutoff exists because
switching `EnableEmailVerification` on does **not** mail existing clients — gating them
would bar people who were never sent a link.

**Gates client-area pages, and holds the payment after checkout.** With WHMCS's
*Automatically redirect to gateway*, a fresh checkout goes straight to an off-site gateway
page no client-area hook can reach, so the checkout hook redirects an unverified client to
the gate page before the gateway link is built. The order and invoice are still created
(register-and-order-in-one-step keeps working); the unpaid invoice becomes payable once the
address is confirmed. Not gated: registration (WHMCS creates the client *then* mails the
link — there is nothing to hook), the admin area, API/`localAPI` orders and `vpnhoodiap`'s
`api.php` — `AfterShoppingCartCheckout` fires for *every* order WHMCS creates, so the hold
checks it is on `cart.php` with the order's owner logged in before acting. The whitelist
(`logout`, `verifyemail`,
`password-reset`, WHMCS's `/user/verify`, this addon's page, `vpnhoodiap`'s pages) is
load-bearing: WHMCS's link expires after 60 minutes and its own recovery advice is to log
in and request a new one. Any exception fails open.

### `modules/addons/vpnhoodpartnerhub/` (addon) — wholesale gateway
Turns our WHMCS into a partner-scoped wholesale API. **It adds only partner management +
a secured API; it does not own credit or provisioning.**

- `vpnhoodpartnerhub.php` — addon config, `_activate`/`_deactivate` (table create/drop),
  `_output` (admin UI for partners + product mappings + credentials + purchases needing
  attention).
- `api.php` — public POST/JSON endpoint. Bootstraps WHMCS via `init.php`, authenticates,
  dispatches, logs; sends `X-Vpnhood-Hub-Features` on every response.
- `lib/Auth.php` — key/secret auth (`X-Vpnhood-Key` / `X-Vpnhood-Secret`), status + IP gating.
- `lib/PartnerApiController.php` — the actions: request validation and dispatch.
- `lib/PurchaseProcessor.php` — ordering as a sequence of confirmed steps: `AddOrder`,
  payment from **native WHMCS credit**, `AcceptOrder`, then the access code read back through
  `ApiService`/`Helper`; resume, `linkOrder`, and the admin Retry/Release.
- `lib/PurchaseRepository.php` — `mod_vpnhood_partner_purchases`, its first-use initialization,
  the named locks, and the footprint queries.
- `lib/RefundPolicy.php` — whether an order may be refunded through the API, decided from facts
  the caller reads; no database, so `tests/unit` checks every rule (see *Service actions and
  refunds*).
- `lib/PartnerRepository.php` — data access + native credit reads.
- `lib/LocalApi.php` — `localAPI` with failures turned into readable 422s (see below).
- `lib/ApiException.php` — carries an HTTP status, and optionally a machine-readable `code`
  and `details`, for structured error responses.

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

mod_vpnhood_partner_purchases
  id, partner_id, client_id,
  idempotency_key (nullable, ascii_bin), customer_reference (≤191, nullable),
  product_id, billing_cycle            — the request that bought it
  order_id, invoice_id, service_id     — once AddOrder ran
  state, last_error, created_at, updated_at
  UNIQUE(partner_id, idempotency_key), UNIQUE(order_id), INDEX(partner_id, customer_reference), INDEX(state)
```

**Credit is NOT stored here** — it is the native WHMCS client credit
(`tblclients.credit`, history in `tblcredit`). This is intentional.

`mod_vpnhood_partner_purchases` has one row per Hub order, keyed or not (a keyless order of
quantity N is N rows). `state` is the last step whose result is **confirmed**:
`created → ordered → paid → provisioned → delivered`, or `rolled_back`, `needs_reconciliation`.
The Hub has no upgrade step, so `PurchaseRepository::ensureReady()` creates the table on first
use (`_activate` calls it too) under a named init lock, back-fills a keyless `delivered` row for
every order the request log answered with 200 (insert-if-absent by order id, so an interrupted
run is resumed), then sets `tblconfiguration.VpnHoodPartnerHubPurchasesReady`. `order` and
`linkOrder` wait for it (20 s, then `409 initializing`): a guard reading a half back-filled table
could miss a legacy order and buy twice. The index names are explicit because Laravel's
generated ones exceed MariaDB's 64 characters; a creation that fails midway drops the table so
the next request starts over.

## Request lifecycle — `order` action

1. `api.php` authenticates the partner (key/secret → status → IP allowlist).
2. `PartnerApiController::order` resolves `downstreamRef` → mapped, enabled product, then
   `resolveBillingCycle` picks the cycle: the connector's requested `billingCycle` when it is
   one of the product's available cycles, else the mapping's default (an unsupported requested
   cycle is rejected with `422`).
3. **With an `idempotencyKey`** (`PurchaseProcessor::orderKeyed`; the unit is always one):
   under a MariaDB named lock on (partner, key) — `GET_LOCK`, 20 s, else `409 in_progress` —
   look the key up.
   - No purchase: unless `confirmNewPurchase`, the **legacy guard** refuses with `409 reconcile`
     when live keyless orders of this partner carry the same `customerReference` (a response
     lost under an older connector). Otherwise create the row and run the steps below.
   - A purchase: the request must match it (product, cycle, reference — else `409
     key_mismatch`). `delivered` replays its current delivery (`replayed: true`) while the
     service is Active or Suspended, `409 key_spent` once it is terminated or cancelled;
     `rolled_back` starts over; `needs_reconciliation` answers `409`; any other state **resumes**.
4. **Without a key**, each unit gets a row and runs the steps — every call buys, as before.
5. The steps, each saving its result before the next starts:
   1. `AddOrder` → `ordered`. The request carries the purchase id in the admin-only product
      custom field `hubPurchaseId` (created on first use per product), so WHMCS itself writes it
      onto the service — the footprint that finds the order if this request dies before saving
      its ids. A rejected `AddOrder` → `rolled_back` and `422`.
   2. **Payment**, under the credit lock of the WHMCS client (`GET_LOCK`, shared with `renew`):
      re-read the credit and the invoice balance, and apply the **full** balance or nothing.
      WHMCS 9 books applied credit as a credit note plus a payment in `tblaccounts`, so
      `invoiceBalance()` sees it. Not enough → **rollback**: `CancelOrder` + `DeleteOrder`, then
      verify the order is gone or cancelled, the invoice cancelled with no gateway payment, and
      the credit equal to its value under the lock → `rolled_back` (the key is free again) and
      `402`; anything else → `needs_reconciliation` and `409`. Applied but not Paid, or a
      negative balance → `needs_reconciliation`.
   3. **Provisioning** → `provisioned` when the service is Active or Suspended with its delivery
      footprint: `accessTokenId` (normal delivery) or the `bulkDelivery` mark (CSV — a product
      configured for CSV delivers a batch even at quantity 1). **There is exactly one attempt.**
      A product set to provision *on payment* was provisioned by WHMCS itself while
      the invoice was paid in step 2; the Hub then only accepts the order (`AcceptOrder`,
      `autosetup` off) and never runs the module again. Only a product provisioned on *accept*
      gets its attempt from `AcceptOrder` (`autosetup` on). No footprint → `needs_reconciliation`
      and `409 not_provisioned`, with WHMCS's own error from its Module Queue in `last_error`:
      **the order is kept** — it is paid — and a person finishes it (below). A Hub product must
      never provision *on order*: WHMCS would create the token before any payment.
   4. Read the delivery → `delivered`. A failed read leaves it `provisioned` and answers
      `409 not_delivered`; reading has no side effect, so a repeat or `getAccessCode` retries it.
6. Response: `{ replayed, keys: [{ upstreamOrderId, customerReference, deliveryType, accessTokenId + accessCode | csv }] }`.

Why keys: a gateway timeout, or two concurrent Creates of one service, could buy a second key.
The connector learns `upstreamOrderId` only from the `order` response, so a lost response — a
`504` from the web gateway while the Hub still places and provisions the order — leaves its
service Pending, and Create again places a new order. A reference cannot fix this — service ids
repeat across installs, reinstalls and restored backups — a key saved per purchase can.

**Resume** (`PurchaseProcessor::resume`): the saved state proves only what finished; the next
step may have run before the request died. Its footprint decides, and only a provably absent
step is redone. From `created`: the marker on a service → adopt its order; no marker and no
order of the client since that no purchase accounts for → order now; otherwise a person.
From `ordered`: invoice Paid → `paid`, and provisioning goes on exactly as in step 3 (for a
product provisioned on payment, WHMCS's run during that payment was the attempt; `AcceptOrder`
never ran — the state after payment is saved first); Unpaid with nothing applied → pay;
anything else → a person. From `paid`:
a recorded token (or batch) on a live service → `provisioned`; otherwise a person —
**provisioning is never redone automatically**, because the token request can outlive the
request that sent it (the Hub's access-server client has no timeout) and a second
`AcceptOrder` could mint a second token.

**`linkOrder`** binds a keyless order the admin names to a key, under the key lock: the key
must be free (or already bound to that very order — the lost-response case, answered again),
the order this partner's, keyless, `delivered`, with the same product, cycle and reference, its
service Active or Suspended; then `UPDATE … SET idempotency_key WHERE … idempotency_key IS NULL`
must match exactly one row (`409 already_claimed` otherwise), and the order's delivery is
returned. It never buys; a Hub without the action answers `404`.

**Purchases needing attention** (the partner's page in the addon): `needs_reconciliation` rows,
and rows left in a step for more than ten minutes (a keyless request that died). **Retry**
re-checks the footprints and, when the service is provisioned and the invoice paid, reads the
key and marks it delivered, accepting a still-Pending order with `autosetup` off; **Release**
closes an unfinished purchase as `rolled_back` once no order or payment of it remains (or the
admin confirms the refund). Neither orders, pays or provisions.

**Paid but not provisioned — the support procedure** (also on that page). Create may run again
only after a confirmed failure *before* token creation, or confirmation that the creation
ended without a token. Elapsed time, an empty token list or "an error was logged" prove
nothing on their own: the module can create the token and then fail reading its code or
saving its id. Establish, for this purchase's creation request (customer id + order id, never
a service id):

1. **The access server's tokens** for this customer and order
   (`GET /api/projects/{projectId}/access-tokens?customerId=…&orderId=…&shopId=WHMCS`, or
   VpnHood! MANAGER): a token there is the outcome.
2. **Nothing listed?** That is final only once no creation request is still running. The access
   server's request log settles it: each `POST …/projects/{projectId}/access-tokens` since the
   order was placed needs its `Request finished` line (or the service restarted since, which
   ended it).
3. **Why it failed** — context, not proof: WHMCS's Activity Log (`Module Create Failed - Service
   ID: …`) and Utilities → Module Queue record the module's error even with module debug
   logging off; the Module Log has the call's details when it is on. The purchase's
   `last_error` repeats the queue's error. The Module Queue's **Retry** is a Create — never use
   it before this procedure.

A token exists → store its id on the service (`accessTokenId`; `bulkDelivery` = `yes` for a
batch) and set the service Active — never press Create then. Confirmed none and every request
finished → press Create on the service. Then Retry. Unresolved → it stays in the list.

**`upstreamOrderId` is the connector-facing handle** for every subsequent action (`renew`,
`suspend`, `unsuspend`, `terminate`, `refund`, `getOrder`, `getAccessCode`). It is the WHMCS **order id**;
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

`LocalApi::call()` wraps every `localAPI` call and turns a non-`success`
result into an `ApiException` with **422**, message `Upstream WHMCS rejected <Action>: <its
message>`. It must not be a 5xx, and that is not a style preference:

> **A CDN or proxy in front of WHMCS may replace the body of a 5xx origin response with its
> own error page.** The connector then receives `Invalid response from Hub (HTTP 502)` with
> the proxy's text, and the real reason never crosses the wire. A 4xx body passes through
> untouched.

For example, an **Order Payment Gateway** set to a gateway's display name rather than its
system name (such as `banktransfer`) fails every partner order with *"Invalid Payment Method.
Valid options include banktransfer,…"* — `AddOrder` only accepts the system name. The Hub
reports it correctly; as a 5xx, the sentence would never reach the partner. Keep new failure
paths in the 4xx range whenever the caller could do something about them.

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
the cart. Order cannot proceed."**

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
> (`PurchaseProcessor::payLocked` and `::settleInvoiceLocked`). If someone turns this setting
> back on, partner services silently revert to auto-renewing.

- **Order:** the payment step applies the order invoice's full balance from credit, or nothing,
  before provisioning — so ordering still fails closed on insufficient credit (`402` + rollback).
- **Renewal:** the cron-generated renewal invoice is left completely alone and stays Unpaid.
  `nextduedate` does not advance while it is unpaid, and the token expiry tracks `nextduedate`,
  so **access stops on the real term end** until the partner renews.
- **Renew:** `PartnerApiController::renew` pays the outstanding invoice from native credit
  (`402` if short, `409` if nothing outstanding, `409 service_ended` for an ended service) under
  the same credit lock as orders, held from its status check to its expiry update, so a renewal
  and an order competing for the last credit cannot both spend it. Paying a Hosting
  renewal invoice drives WHMCS's normal renewal — `nextduedate` advances one cycle and
  `vpnhoodstore_Renew` re-syncs the token; the call then re-asserts the token expiry
  idempotently.
- **Scope:** `isPartnerProductService` — the service's product is in
  `mod_vpnhood_partner_products`. Partner products are distinct from retail products, so retail
  is never affected and no per-service marker is needed. Non-Hub services (one-time products,
  anything created outside the Hub) fall back to a plain expiry re-sync (`resyncExpiry`).
- **Overdue automation:** the unpaid invoice goes overdue normally, so WHMCS's standard
  suspend/terminate automation applies. Suspension is harmless (the token is expiring anyway),
  but auto-**termination** would destroy the service before the partner can renew — control
  this in WHMCS *Automation Settings* (termination window), not in module code.

> Verified on WHMCS 9.0.7 (`renew.test.sh`, `purchase-recovery.test.sh`): `applyCredit()`
> consumes `tblclients.credit`, recorded as a credit note plus a `tblaccounts` payment, and
> paying the renewal invoice triggers the native renewal (`nextduedate` advance +
> `vpnhoodstore_Renew`). Cancelling an order returns credit applied to an **unpaid** invoice,
> but not to a **paid** one — which is why a paid order is never rolled back.

## Service actions and refunds

The rules a partner sees are in the addon `README.md` (*Refunds*); the reasons are here. The
business rule (partner-facing) is in the VpnHood repo, `docs/accounts/account-lifecycle.md` §8.

**One lock per account.** `suspend`, `unsuspend`, `terminate`, `renew` and `refund` run under
the partner client's credit lock (`PartnerApiController::withServiceLock`, 15 s, then `409
in_progress`) with their status check inside it. Without it an unsuspend that read "Suspended"
before a refund ended the key could finish after it and enable the key again. `renew` holds the
lock through settle and expiry update, so `settleInvoiceLocked` no longer takes its own.

**Termination is final through the API.** `suspend`, `unsuspend` and `renew` refuse an ended
service (Terminated, Cancelled, Fraud: `409 service_ended`); `terminate` still runs. Otherwise
refund → suspend → unsuspend → renew brought a refunded key back: suspend and unsuspend move
WHMCS's status back to Active, and a renewal sets the key's expiry from the old `nextduedate`,
so the partner got the refunded term back along with the one they paid for.

**Renew never pays for an ended key.** WHMCS bills a client's same-day renewals on one invoice,
and `renew` pays the invoice whole, so renewing one key paid for keys the partner had already
ended. `settleInvoiceLocked` first takes the `Hosting`/`PromoHosting` lines of Terminated,
Cancelled or Fraud services off the invoice (`UpdateInvoice` `deletelineids`, which
recalculates the total) and logs it. It checks at payment time, not on termination, because a
key can end in ways no hook sees (the status dropdown runs no module). Once anything is paid on
the invoice, taking lines off could leave it overpaid, so `renew` is refused instead (`409
renewal_blocked`) and the invoice is fixed by hand; with Automatic Credit Use off, only a
payment made by hand gets there.

**Refunds** (`PurchaseProcessor::refundLocked`, rules in `RefundPolicy`):

- **The refundable invoice is the Hub's own purchase record** (`mod_vpnhood_partner_purchases`,
  state `delivered`): the invoice the Hub paid from the partner's credit. A Paid invoice alone
  proves nothing: an admin can mark an invoice paid or write it off, create a service by hand,
  or move a service to another client (its invoices stay with the old one). Anything without
  that record is refused and refunded by hand.
- **Only the first purchase, and only while nothing else is invoiced for the service.** A
  renewal is never refunded through the API: ending the key would take the earlier, still-paid
  term. A later invoice, paid or not, also blocks refunding the purchase. A line `renew` took
  off an ended key no longer counts, so that key refunds inside its window: its renewal was
  never paid.
- **The key ends before the credit moves, every time** (`ModuleTerminate`, also on a
  Terminated service; verified to run the module again on WHMCS 9.0.7). A Terminated status
  does not prove the key is off (the status dropdown sets it without the module). A failure
  there returns nothing.
- **The credit row is the once-only guard, not the invoice status.** `AddCredit` writes
  `tblcredit` with the description `RefundPolicy::creditDescription()`; a repeat finds it and
  answers `refunded` with its amount. Marking the invoice Refunded was rejected: a crash
  between the status change and the credit would report a refund nobody received, and the
  status change fires the InvoiceRefunded hooks (the refund-terminate hook, and
  `vpnhood-refund-memory.php`, which would fingerprint the partner). If `AddCredit` fails after
  the key ended: `409 refund_incomplete` and an activity-log line saying to add the credit by
  hand **with that description**, which is what marks it done.
- **No feature flag.** A Hub without `refund` answers `404 Unknown action` and changes nothing,
  so the connector needs no gate; it says "does not offer refunds yet" instead.
- **Not built, on purpose:** an automatic refund when a partner refunds their own customer (a
  hook on the partner's WHMCS; resellers press Refund), and management codes for partners
  (mcode.vpnhood.com can only disable a code that was never used or is within 3 days of first
  use; Suspend and Terminate cover every connector order). The window is one Hub-wide setting,
  not per partner.

Known limits, left as they are:

- **Status drift.** When we suspend or terminate a partner's service from our WHMCS, the
  partner's WHMCS still shows it Active; nothing pushes the change downstream.
- **Our admin and WHMCS's cron do not take the Hub's lock.** The lock serializes the API's own
  actions. A key they end while a renewal is between its line check and its payment is still
  paid for; a key ended before that check is not.
- **`AddCredit` is not atomic** with the balance update, the same as every order's
  `applyCredit`: the window is a crash between two SQL statements inside one WHMCS call.

## Extending

- **New API action:** add a `case` in `PartnerApiController::handle`, implement a private
  method, document it in the addon `README.md` table and in the connector's API contract doc.
- **New partner attribute:** add a column in `_activate` (and handle upgrades — see below),
  surface it in `_output`, read it in `PartnerRepository`/`Auth`.
- **Schema upgrades:** `_activate` only creates tables when missing, and there is no upgrade
  step, so an installed Hub never re-runs it. A new table is created where it is first needed,
  under a lock, with a completion marker (see `PurchaseRepository::ensureReady()`); for changes
  to an already-installed table, guard with `Schema::hasColumn(...)` and `ALTER` — do not
  assume a fresh install. Name indexes explicitly (MariaDB allows 64 characters). Keep
  `_deactivate` in sync.
- **New API behaviour a connector depends on:** advertise it in
  `PartnerApiController::FEATURES` (the `X-Vpnhood-Hub-Features` header), so a connector can
  tell a Hub that has it from one that does not.
- **Never trust client-supplied ids:** every action scopes to `partner.client_id`
  (`ownedServiceByOrder()` enforces ownership). Preserve this when adding actions.
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

The integration suites in `tests/integration/` run against the dev WHMCS (see its README):
`hub-api.test.sh` (the API over HTTP, keyed and keyless ordering, reconcile, `linkOrder`),
`purchase-recovery.test.sh` (every failure path of a purchase and its recovery),
`connector-idempotency.test.sh` (the connector's side, and both directions of compatibility
with the previous releases), `refund.test.sh` (refunds, the service-action lock and the ended
guards, the connector's Refund button), plus the buyer lifecycle scripts. `tests/unit/run.sh`
runs the unit tests (pure PHP, e.g. `RefundPolicy`) with the dev box's PHP, since there is none
locally. By hand, against a live WHMCS:

1. Activate `vpnhoodpartnerhub`; confirm the four tables exist.
2. Create a partner linked to a WHMCS client, add credit, map a product.
3. `curl` the API: `getBalance`, then `order` — confirm an order+invoice were created,
   invoice paid from credit, credit decreased, `vpnhoodstore` provisioned a token, and a
   valid access code returned. Insufficient credit must roll back and return `402`.
   Repeat the `order` with the same `idempotencyKey`: same `upstreamOrderId`,
   `replayed: true`, credit unchanged.
4. Exercise `renew`/`suspend`/`unsuspend`/`terminate` and confirm effects + module log.
5. `refund` a new order: the service Terminated, the key disabled, the price back on the credit
   with one `Partner Hub refund of order #N (invoice #M)` row; a repeat returns nothing more.
