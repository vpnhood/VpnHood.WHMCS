# VpnHood! Partner Hub (upstream addon)

Installed on **your** WHMCS (the same one that runs `vpnhoodstore`). It turns your
WHMCS into a **wholesale gateway**: external partners who run their own storefront
(using the **VpnHood Partner Connector** module) can order and provision VpnHood keys
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
   `mod_vpnhood_partner_log`.
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
3. Under the partner, add **Allowed Products**: map a **Downstream Ref** (the string the
   partner uses, e.g. `vpn-monthly`) to one of **your** `vpnhoodstore` products and a
   billing cycle. The partner can only order products in this list.

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
| `getProducts` | — | `{ products: [{ downstreamRef, name, billingCycleMonths }] }` |
| `order` | `downstreamRef`, `quantity?`, `customerReference?` | `{ keys: [{ upstreamServiceId, orderId, deliveryType, accessCode|csv }] }` |
| `renew` | `upstreamServiceId`, `nextDueDate?` | `{ status, nextDueDate }` |
| `suspend` | `upstreamServiceId` | `{ status }` |
| `unsuspend` | `upstreamServiceId` | `{ status }` |
| `terminate` / `cancel` | `upstreamServiceId` | `{ status }` |
| `getOrder` | `upstreamServiceId` | `{ status, nextDueDate }` |
| `getTransactions` | — | `{ transactions: [...] }` (native credit history) |

### Example

```bash
curl -X POST https://store.example.com/modules/addons/vpnhoodpartnerhub/api.php \
  -H "X-Vpnhood-Key: $KEY" -H "X-Vpnhood-Secret: $SECRET" \
  -H "Content-Type: application/json" \
  -d '{"action":"order","downstreamRef":"vpn-monthly","customerReference":"ABC123"}'
```

## Safety model

- **Credit is the hard limit.** The order endpoint checks that the generated invoice was
  settled from credit before provisioning; if not, the order is rolled back (`DeleteOrder`)
  and a `402` is returned — nothing is provisioned on insufficient credit.
- **Scoped authorization.** Every action is scoped to the partner's own `client_id`; a
  partner can only order mapped products and only act on their own services.
- **Secret at rest.** The API secret is stored hashed (`password_hash`) and verified with
  `password_verify`; transport is expected over HTTPS.
- **Audit.** Every call is logged to `mod_vpnhood_partner_log` and errors to the WHMCS
  module log (`vpnhoodpartnerhub`).
