# Browser (e2e) tooling — dev WHMCS

Playwright-driven scripts against `https://whmcs-dev.vpnhood.com`. Two kinds live here:

- **Visual prep scripts** (`*-configure.mjs`) — open a headed browser, drive the admin
  UI up to a point, then **stop and leave the browser open** so a human reviews and
  confirms (they never click Save themselves).
- **Automated e2e tests** — none yet; add future Playwright suites here.

## Setup

```bash
cd tests/e2e
npm install
```

Admin credentials go in `<Vh root>/.user/whmcs/secrets-dev.json` (outside the repo,
next to the SSH key):

```json
{ "adminUser": "…", "adminPassword": "…" }
```

Env overrides: `WHMCS_ADMIN_USER`, `WHMCS_ADMIN_PASSWORD`, `WHMCS_DEV_URL`.

## Scripts

| Command | What it does |
| --- | --- |
| `npm run hub:configure` | Log in → Addon Modules → activate **VpnHood! Partner Hub** if needed → open Configure → uncheck *Require IP Allowlist*, fill *Order Payment Gateway* (`HUB_ORDER_GATEWAY`, default `banktransfer`), check *Full Administrator* → **waits without saving**; after you click *Save Changes* it asserts the WHMCS **“Changes Saved Successfully”** banner and prints PASS. |

**Pass criterion for admin saves:** WHMCS shows a “Changes Saved Successfully”
banner at the top of the page after a successful save — assert that banner in
any admin-side e2e check.

Deploy first (`scripts/deploy-dev.sh hub`) so the admin UI reflects the working tree.
