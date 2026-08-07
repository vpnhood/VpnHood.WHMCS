# Test-environment bootstrap (dev WHMCS)

`init-skeleton.sh` ensures the dev WHMCS has everything tests rely on. It is
**idempotent and never destructive** — call it at the start of every test run:

```bash
tests/bootstrap/init-skeleton.sh
```

What it ensures (spec: [fixtures.json](fixtures.json), applied server-side by
`skeleton.php` via direct DB — WHMCS localAPI does not work under PHP-CLI on the box):

| Fixture | Value |
| --- | --- |
| Hub addon | **vpnhoodpartnerhub** activated + configured (Full Administrator access, IP allowlist off, gateway `banktransfer`); `mod_vpnhood_*` tables recreated if dropped |
| Product group | **VpnHood! CONNECT Reseller** (`vpnhood-connect-for-reseller`) |
| Product (onetime, $2.00) | **Reseller - One-Month Premium Code** (`reseller-one-month-premium-code`) |
| Product (recurring, $2.00/mo) | **Reseller - One-Month Premium Code (Subscription)** (`…-subscription`) |
| Reseller client | **`test-reseller@vpnhood.com`** ("Test Reseller") — credit topped up to **$500** |
| Hub partner | **Test Reseller**, linked to the reseller client, both products mapped (`downstream_ref` = product slug) |

When `VpnHood.WHMCS.Partner` is checked out alongside this repo, the same run
also applies its `tests/bootstrap/connector-fixtures.json` (connector install
config pointed at this Hub, the partner-shop products, and the **buyer** client
`test-buyer@vpnhood.com` — the buyer belongs to the connector side). Hub and
connector share the one dev WHMCS; the connector reaches the Hub over HTTPS
like any external partner would.

Credentials live in `<Vh root>/.user/account-dev.vpnhood.com/secrets.json` (`partnerApiKey`,
`partnerApiSecret`, `testClientPassword` are generated on first run; `adminUser`
/ `adminPassword` are for the e2e admin scripts). The script also regenerates
`tests/integration/.env`, so `tests/integration/hub-api.test.sh` runs directly
afterwards with no manual setup.

Both test clients get a client-area login (their email + `testClientPassword`)
for future storefront e2e flows.

Notes:

- Product existence is checked via `tblproducts_slugs` **and** `tblproducts.slug`
  (admin-created products only populate the former).
- The reseller's credit is only ever topped **up** to the minimum, never reduced.
- The partner secret hash is re-synced to `secrets.json` if they diverge.
