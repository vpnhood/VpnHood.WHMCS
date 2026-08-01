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
   `mod_vpnhood_partner_log`. **Deactivating preserves all data** (partners keep their API
   credentials across a deactivate/reactivate); to remove the module's data permanently,
   drop those tables manually.
3. Configure the addon: toggle **Require IP Allowlist**, set a reference currency for the
   admin balance display, and set **Order Payment Gateway** to the system name of an active
   gateway (e.g. `banktransfer`). The gateway only labels the partner order invoices — they
   are still settled from the partner's credit balance — but WHMCS needs a valid one.

> Requires the existing `vpnhoodstore` server module and `vpnhoodconfig` addon to be
> installed and configured (the Hub provisions through them).

## Onboarding a partner

1. Open the addon (**Addons → VpnHood! Partner Hub**) and **Add Partner**:
   - **WHMCS Client ID** — the client account whose **credit balance** funds the
     partner's orders. Create/choose a client first, then add credit to it
     (Clients → Add Credit). WHMCS automatic credit application must remain enabled.
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
`{ "success": true, "data": {...} }` or `{ "success": false, "error": "..." }`.

| Action | Params | Returns |
|--------|--------|---------|
| `getBalance` | — | `{ clientId, balance, currency }` |
| `getProducts` | — | `{ products: [{ downstreamRef, name, paymentType, allowMultipleQuantities, billingCycleMonths, availableCycles }] }` |
| `order` | `downstreamRef`, `billingCycle?`, `quantity?`, `customerReference?` | `{ keys: [{ upstreamOrderId, customerReference, deliveryType, accessTokenId + accessCode \| csv }] }` |

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
| `getOrder` | `upstreamOrderId` | `{ status, nextDueDate }` |
| `getAccessCode` | `upstreamOrderId` | `{ accessTokenId, accessCode }` |
| `getTransactions` | — | `{ transactions: [...] }` (native credit history) |

> **`upstreamOrderId` identifies an order everywhere.** It is the upstream WHMCS **order id**
> returned by `order`; the Hub resolves it to the underlying service itself, scoped to the
> calling partner's client. Ids from the request are never trusted — an order belonging to
> another partner resolves to `404`.
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
  -d '{"action":"order","downstreamRef":"42","customerReference":"ABC123"}'
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

## Safety model

- **Credit is the hard limit.** The order endpoint checks that the generated invoice was
  settled from credit before provisioning; if not, the order is rolled back (`CancelOrder`
  then `DeleteOrder`) and a `402` is returned — nothing is provisioned on insufficient credit.
- **Scoped authorization.** Every action is scoped to the partner's own `client_id`; a
  partner can only order mapped products and only act on their own services.
- **Secret at rest.** The API secret is stored hashed (`password_hash`) and verified with
  `password_verify`; transport is expected over HTTPS.
- **Audit.** Every call is logged to `mod_vpnhood_partner_log` and errors to the WHMCS
  module log (`vpnhoodpartnerhub`).
