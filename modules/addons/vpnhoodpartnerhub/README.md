# VpnHood! Partner Hub (upstream addon)

Installed on **your** WHMCS (the same one that runs `vpnhoodstore`). It turns your
WHMCS into a **wholesale gateway**: external partners who run their own storefront
(using the **VpnHood! Partner Connector** module) can order and provision VpnHood keys
against your WHMCS, paying from a **prepaid credit balance** they hold as a client on
your system.

This addon adds only two things:

- **Partner management** — partner records (linked to a WHMCS client that holds the
  credit), API key/secret, status, allowed-products map, optional IP allowlist.
- **A partner-scoped REST API** — that places orders via WHMCS `localAPI`
  (`AddOrder`/`AcceptOrder`), settles them from **native WHMCS credit**, runs the
  existing `vpnhoodstore` provisioning, and returns the access code.

It does **not** reimplement credit (native WHMCS credit is the spend limit) or
provisioning (the existing `vpnhoodstore` / `Helper` / `ApiService` do that).

## Installation

1. Copy `modules/addons/vpnhoodpartnerhub/` into your WHMCS `/modules/addons/`.
2. **System Settings → Addon Modules → VpnHood! Partner Hub → Activate**. Activation
   creates the tables `mod_vpnhood_partners`, `mod_vpnhood_partner_products`,
   `mod_vpnhood_partner_log` and `mod_vpnhood_partner_purchases` (an installed Hub that
   predates the last one creates it on its first order, and records the orders it placed
   before from the request log). **Deactivating preserves partner data** (partners keep their
   API credentials across a deactivate/reactivate); to remove the module's data
   permanently, drop those tables manually.

   > ⚠ **Deactivating does NOT preserve the addon's own settings.** WHMCS deletes every
   > `tbladdonmodules` row for a module the moment you deactivate it, so Require IP
   > Allowlist, Reference Currency and Order Payment Gateway all come back blank on
   > reactivation. The tables survive; the configuration does not. Write the settings down
   > before deactivating anything. (Verified on WHMCS 9.0.3 — this bites every addon,
   > including the partner-side connector, whose Hub URL / API key / API secret are wiped
   > the same way.)
3. Configure the addon: toggle **Require IP Allowlist**, set a reference currency for the
   admin balance display, and pick an **Order Payment Gateway**. That field is a dropdown
   of the gateways this install actually has, listed as *Display Name (system name)*; the
   stored value is always the system name (e.g. `banktransfer`), which is what WHMCS's
   `AddOrder` requires. The gateway only labels the partner order invoices — they are still
   settled from the partner's credit balance — but WHMCS needs a valid one. Set the
   **Partner Refund Window (days)**: how long after payment a partner can refund a new key
   through the API (default 3; `0` turns partner refunds off; see *Refunds*).

   The field reports the state of the current value in its own description on the
   configuration screen, including immediately after **Save Changes**: green when the value
   is an active gateway, red when it is not. A value inherited from an older version that
   is not a real gateway stays selected and is labelled `⚠ … — NOT a payment gateway`
   rather than being silently replaced, so nothing changes behind your back.

> Requires the existing `vpnhoodstore` server module and `vpnhoodconfig` addon to be
> installed and configured (the Hub provisions through them).

## Onboarding a partner

1. Open the addon (**Addons → VpnHood! Partner Hub**) and **Add Partner**:
   - **WHMCS Client ID** — the client account whose **credit balance** funds the
     partner's orders. Create/choose a client first, then add credit to it
     (Clients → Add Credit). WHMCS **Automatic Credit Use must stay OFF** — the Hub applies
     credit itself (see *Renewals are manual*).
   - **Status** — Active/Suspended.
   - **IP Allowlist** — optional, comma-separated.
2. On save you are shown the **API Key** and **API Secret** (the secret is shown once).
   Give these to the partner.
3. Under the partner, add **Allowed Products**: pick one of **your** `vpnhoodstore`
   products from the dropdown and click **Add Product**. The partner can only order
   products in this list. The billing cycle is derived automatically from the product's
   own pricing, and the product's WHMCS id is used as its `downstreamRef` in the API.

Each partner order creates a **recurring service** on the partner's client account, so
WHMCS invoices and charges their credit every cycle automatically.

## API

Endpoint (POST, JSON):

```
https://<your-whmcs>/modules/addons/vpnhoodpartnerhub/api.php
```

Headers:

```
X-Vpnhood-Key:    <api key>
X-Vpnhood-Secret: <api secret>
Content-Type:     application/json
```

Body: `{ "action": "<action>", ...params }`. Response:
`{ "success": true, "data": {...} }` or `{ "success": false, "error": "...", "code"?: "...", "details"?: {...} }`.
`code` names the failures a caller handles differently (below); `details` carries their data.
Every response also carries `X-Vpnhood-Hub-Features: idempotency-v1` — what this Hub
supports; a Hub that predates it sends nothing.

| Action | Params | Returns |
|--------|--------|---------|
| `getBalance` | — | `{ clientId, balance, currency }` |
| `getProducts` | — | `{ products: [{ downstreamRef, name, paymentType, allowMultipleQuantities, billingCycleMonths, availableCycles }] }` |
| `order` | `downstreamRef`, `billingCycle?`, `quantity?`, `customerReference?`, `idempotencyKey?`, `confirmNewPurchase?` | `{ replayed, keys: [{ upstreamOrderId, customerReference, deliveryType, accessTokenId + accessCode \| csv }] }` |
| `linkOrder` | `idempotencyKey`, `upstreamOrderId`, `downstreamRef`, `billingCycle?`, `customerReference?` | as `order`, plus `linked: true` |

> `downstreamRef` is the WHMCS product id (as a string). Partners should call `getProducts`
> to discover the available refs rather than hard-coding them. `paymentType` is the product's
> WHMCS Payment Type — `free`, `onetime`, or `recurring` — so the connector can flag a
> partner-side product whose Payment Type does not match. `availableCycles` lists the
> recurring cycle lengths in months (e.g. `[1, 12]`) that the product offers (only meaningful
> when `paymentType` is `recurring`).
>
> `billingCycle` (optional) is a WHMCS cycle name — `monthly`, `quarterly`, `semiannually`,
> `annually`, `biennially`, `triennially`. When omitted, the product's default mapped cycle is
> used. When provided, it must correspond to one of the product's `availableCycles`, otherwise
> the order is rejected with HTTP 422. For `onetime`/`free` products `billingCycle` is ignored
> (WHMCS reports such services' cycle as "One Time", which is not a cycle name) and the order
> is placed as `onetime`.
>
> `quantity` above 1 is rejected with HTTP 422 unless the product has **Allow Multiple
> Quantities** enabled on its Pricing tab (`allowMultipleQuantities` in `getProducts`).
| `renew` | `upstreamOrderId` | `{ status, nextDueDate }` |
| `suspend` | `upstreamOrderId`, `suspendReason?` | `{ status }` |
| `unsuspend` | `upstreamOrderId` | `{ status }` |
| `terminate` / `cancel` | `upstreamOrderId` | `{ status }` |
| `refund` | `upstreamOrderId` | `{ status: "refunded", amount }` — see *Refunds* |
| `getOrder` | `upstreamOrderId` | `{ status, nextDueDate }` |
| `getAccessCode` | `upstreamOrderId` | `{ deliveryType, accessTokenId, accessCode }`, or `{ deliveryType: "csv", csv }` for a CSV (batch) order |
| `getTransactions` | — | `{ transactions: [...] }` (native credit history) |

### Safe retries: `idempotencyKey`

An `order` whose response is lost — a timeout, or a gateway `504` while the order still
completes — can be repeated safely **with an `idempotencyKey`** (1-64 characters: letters,
digits, `.`, `-`, `_`; case-sensitive). The key buys exactly one unit, once: repeats with the
same key return that order — same `upstreamOrderId`, current code, `replayed: true` — and
charge nothing; two calls at once are serialized, the second waits up to 20 s for the first
(then `409 in_progress` — retry). Use one key per unit (`quantity` must be 1) and a new key
for every new purchase. **Without a key, every call buys**, as it always has.

| `code` | HTTP | Meaning |
|--------|------|---------|
| `in_progress` / `initializing` | 409 | still running (a call with the same key, another action on the same account, or the Hub's first-use setup) — retry |
| `key_mismatch` | 409 | the key belongs to a different request (product, billing cycle or `customerReference`) |
| `key_spent` | 409 | the key's order is terminated or cancelled; a replacement needs a new key |
| `reconcile` | 409 | orders placed **without** a key are live under this `customerReference` (`details.candidates`: `upstreamOrderId, product, billingCycle, status, placedAt`). If one of them is this purchase — its response was lost before you used keys — bind it with `linkOrder`; otherwise repeat the order with `confirmNewPurchase: true` |
| `insufficient_credit` | 402 | nothing was charged; top up and repeat |
| `not_provisioned` / `needs_reconciliation` | 409 | paid, but it could not finish; VpnHood support finishes it (`details.upstreamOrderId`). **Do not order again** — a keyed repeat is refused until then |
| `not_delivered` | 409 | provisioned, but the key could not be read: repeat, or `getAccessCode` |
| `link_rejected` / `already_claimed` | 404 / 409 | `linkOrder`: not this purchase (different product, cycle or reference, not live, never finished), or another key has it |
| `service_ended` | 409 | `suspend`, `unsuspend` or `renew` of an order whose service has ended (Terminated, Cancelled or Fraud; `details.status`): termination is final — buy a new key |
| `refund_window_closed` | 409 | `refund` after the refund window, or with partner refunds turned off; nothing changed (`terminate` still ends the key, without returning credit) |
| `not_refundable` | 409 | `refund` of an order the API does not refund (see *Refunds*); nothing changed — VpnHood refunds it by hand if it should be |
| `refund_incomplete` | 409 | `refund` ended the key but could not return the credit; VpnHood support finishes it (quote the invoice) |

`linkOrder` never buys: a Hub without it answers `404 Unknown action`. It binds the order you
name — only a live, finished, keyless order of yours placed with the same product, billing
cycle and `customerReference` — to the key, so the key's repeats return it; repeating the
link returns it again.

> **`upstreamOrderId` identifies an order everywhere.** It is the upstream WHMCS **order id**
> returned by `order`; the Hub resolves it to the underlying service itself, scoped to the
> calling partner's client. Ids from the request are never trusted — an order belonging to
> another partner resolves to `404`.
>
> **It is not the service id.** The upstream client area addresses services by their own id
> (`clientarea.php?action=productdetails&id=...`), and the two sequences hand the same number
> to unrelated records — service `502` and order `502` belong to different customers. Only the
> id `order` returned is accepted here; a service id resolves to `404`. When an id has to be
> quoted in a support exchange, quote `accessTokenId` instead: it is a GUID, unambiguous across
> both installs, and both `order` and `getAccessCode` return it.
>
> `getAccessCode` fetches the **current** code live from the access server. The connector
> stores `accessTokenId` for reference but does not send it: the Hub resolves the token from
> the partner's own order, so one partner can never read another's code. This is what backs the
> connector's client-area "Get Premium Code" button.

### Example

```bash
curl -X POST https://store.example.com/modules/addons/vpnhoodpartnerhub/api.php \
  -H "X-Vpnhood-Key: $KEY" -H "X-Vpnhood-Secret: $SECRET" \
  -H "Content-Type: application/json" \
  -d '{"action":"order","downstreamRef":"42","customerReference":"ABC123","idempotencyKey":"3f9c0b7e2d4a41c8"}'
```

## Renewals are manual

> **REQUIRED:** WHMCS **Automatic Credit Use must be OFF**
> (Configuration → System Settings → General Settings → Credit). This is what makes manual
> renewal work: with it off, nothing is ever paid from credit on its own, so renewal invoices
> stay Unpaid. The Hub applies credit explicitly for orders and for `renew`. **If this setting
> is turned back on, partner services silently start auto-renewing again.**

Recurring Hub products do **not** auto-renew. WHMCS still generates the renewal invoice and
its email as standard (partners can disable that notification on their side), but nothing
pays it — the partner's credit is never consumed. Nothing renews until the connector calls
`renew`.

- `renew` settles the outstanding renewal invoice from the partner's native credit, which
  advances the service one billing cycle and extends the access-server token.
- `402` — not enough credit to cover the invoice; nothing changes.
- `409` — no renewal invoice is outstanding yet. One exists once WHMCS has generated the
  upcoming renewal invoice (inside its Invoice Generation window before the due date).
- If `renew` is never called, the token expires on the term end date and the end customer's
  access stops until the partner renews.
- `renew` no longer accepts a `nextDueDate` override — WHMCS computes the new term when the
  invoice is paid.

## Refunds

`refund` undoes a sale inside the refund window: the key ends and the invoice total returns to
the partner's credit balance. `terminate` ends a key and returns nothing; `refund` is the only
way credit comes back through the API.

- **Window.** *Partner Refund Window (days)* from the moment the invoice was paid: 3 by default,
  `0` turns partner refunds off. After it: `409 refund_window_closed`, nothing changes.
- **Only a new key's first purchase.** The refundable invoice is the one the Hub paid from the
  partner's credit when it placed the order (its purchase record). A renewal is never refunded
  through the API, and neither is the purchase once anything else is invoiced for the service
  (a renewal invoice, paid or not): ending the key would take that term too, and WHMCS puts
  same-day renewals of several services on one invoice that `renew` pays whole.
- **Refused, and left to VpnHood to refund by hand** (`409 not_refundable`, nothing changes): an
  order the Hub did not sell to this partner (created or paid by hand, or moved from another
  client), a purchase that never finished, an invoice that is unpaid, zero, refunded or billing
  anything besides this key, and an invoice with a refund already booked on it.
- **Active, Suspended or Terminated** orders refund alike, so "terminate now, refund later"
  works inside the window. The key is ended again every time (`ModuleTerminate`), because a
  Terminated status does not prove the key is off (it can be set without the module); if that
  fails, nothing is returned.
- **Once.** The credit row a refund writes, `Partner Hub refund of order #N (invoice #M)`,
  marks it done: a repeat (a lost response, a second click) answers `refunded` with the same
  `amount` and returns nothing more. The invoice keeps its status: a Refunded status does not
  prove the credit was returned, and it would run the refund hooks.
- **Credit not returned after the key ended:** `409 refund_incomplete`, and the activity log
  names the order. Add the credit by hand with exactly that description; the row is what marks
  the refund done.
- **After a refund the order has ended:** `suspend`, `unsuspend` and `renew` answer `409
  service_ended`.

Every refund is written to the WHMCS activity log. Your own admin can still refund anything by
hand in WHMCS, renewals included; the window binds only the API.

## Safety model

- **Credit is the hard limit, applied in full or not at all.** The invoice of an order (and
  of a `renew`) is paid from credit only when the credit covers it, under a lock per WHMCS
  client that every Hub payment takes. Otherwise nothing is applied, the order is rolled back
  (`CancelOrder` then `DeleteOrder`) and a `402` is returned — nothing is provisioned on
  insufficient credit. Credit operations outside the Hub are not serialized by that lock; the
  check after applying catches a collision with one.
- **A paid order is never deleted.** A failure after payment leaves the order and its
  purchase record in place and answers `409`; a rollback that cannot be verified (the order
  or invoice left behind, the credit balance changed) is never taken as done. Either goes to
  **Purchases needing attention** on the partner's page (below).
- **No double charge on a repeat** with an `idempotencyKey` (above), including after a request
  that died midway: each step's footprint (the order WHMCS created, the invoice, the token
  recorded on the service) decides whether it happened; only a step that provably did not
  happen is redone, and provisioning never is.
- **Termination is final through the API.** `suspend`, `unsuspend` and `renew` refuse an
  ended service (`409 service_ended`), so no sequence of calls brings back a key that was
  terminated or refunded.
- **Actions on one account never interleave.** `suspend`, `unsuspend`, `terminate`, `renew`
  and `refund` run one at a time under the partner client's credit lock, with their status
  check inside it; a call that waits more than 15 s answers `409 in_progress`.
- **Scoped authorization.** Every action is scoped to the partner's own `client_id`; a
  partner can only order mapped products and only act on their own services.
- **Secret at rest.** The API secret is stored hashed (`password_hash`) and verified with
  `password_verify`; transport is expected over HTTPS.
- **Audit.** Every call is logged to `mod_vpnhood_partner_log` and errors to the WHMCS
  module log (`vpnhoodpartnerhub`).

## Purchases needing attention

The partner's page (**Addons → VpnHood! Partner Hub → Manage**) lists every purchase that
needs a person: `needs_reconciliation`, and requests that stopped midway more than ten
minutes ago. The partner list shows a banner while any exist. Each row links its order,
invoice and service, says what went wrong, and offers:

- **Retry** — re-checks the footprints and, when the service is provisioned and the invoice
  paid, reads the key and marks the purchase delivered (a keyed repeat from the partner then
  returns it). It accepts a still-Pending order without running the module. It never orders,
  pays or provisions.
- **Release** — closes an unfinished purchase as rolled back, which frees its key. Refused
  while an order or payment it made still exists, unless **Refund done** is ticked.

**Paid but not provisioned** is fixed on the service itself, and the page spells out the
procedure: establish what the original token creation did before anything creates a token
again — the tokens the access server lists for this customer and order, and, when it lists
none, its request log showing that every creation request since the order finished. WHMCS's
Activity Log and Module Queue say why it failed (never use the Module Queue's Retry — it is a
Create). An existing token is recorded on the service (never press Create then); only a
confirmed "no token, nothing pending" allows Create. Then **Retry**.

The Hub makes exactly one provisioning attempt per order: a product set to provision **on
payment** is provisioned by WHMCS while its invoice is paid, and the Hub never runs the module
again; a product provisioned **on accept** gets its attempt from `AcceptOrder`. Never set a
Hub product to provision **on order** — WHMCS would create the token before it is paid.

The client area of a partner's service shows "VpnHood order #N — your reference R", and the
admin service tab adds the reference, the key and the purchase state.
