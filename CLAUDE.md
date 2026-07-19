# CLAUDE.md

Guidance for working in this repository.

## What this is

WHMCS integration for **VpnHood** VPN. Two integration models live across two repos:

- **VpnHood.WHMCS** (this repo) — runs on **our** WHMCS:
  - `vpnhoodstore` — provisions access tokens directly against the VpnHood access server.
  - `vpnhoodconfig` — global API settings + product-visibility hook.
  - `vpnhoodpartnerhub` — wholesale gateway: a partner-scoped API that lets external partner
    WHMCS installs order/provision against this WHMCS using their **native WHMCS credit**.
- **VpnHood.WHMCS.Partner** (separate repo) — the connector partners install on their own WHMCS.

## Read this first

**Before changing anything, read [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).** It is the
authoritative developer guide: module responsibilities, the Partner Hub data model, the
`order` request lifecycle, the reuse contract, and how to extend safely. Keep it updated
when you change architecture, the DB schema, or the API.

## Key rules

- **Reuse, don't duplicate provisioning.** Access-server calls go through
  `modules/servers/vpnhoodstore/lib/ApiService.php` and `Helper.php`. `vpnhoodpartnerhub`
  must call these, never re-implement access-server logic.
- **Native credit is the source of truth.** Do not build a custom credit ledger; partner
  spend is the WHMCS client credit (`tblclients.credit`). Never provision an unpaid order.
- **Scope every partner action** to `partner.client_id`; never trust ids from the request.
- **Hub API is a cross-repo contract.** If you change the action set or payloads, update the
  matching docs in the **VpnHood.WHMCS.Partner** repo in the same change, or the connector breaks.
- **Folder naming:** lowercase letters/numbers only (no underscores/spaces).
- **No build/lint/test tooling** is configured here (no PHP CLI in this environment). Verify
  on a live WHMCS — see the verification notes in `docs/ARCHITECTURE.md` and each README.

## Dev server & credentials

- Credentials live outside the repo in `..\..\.user\whmcs\` (i.e. `<Vh root>\.user\whmcs\`),
  following the `.user/<host>/` convention: `ssh.openssh` (private key), `ssh.ppk`, `ssh.pub`.
- Dev WHMCS for verification/testing: `ssh -i <Vh root>\.user\whmcs\ssh.openssh
  whmcsdev@webhost-ftps.vpnhood.com`, web root
  `/home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html`, site `https://whmcs-dev.vpnhood.com`.
- Integration tests: `tests/integration/` (needs a local, gitignored `.env`).

## Where things are

- Retail provisioning: `modules/servers/vpnhoodstore/`
- Global settings + hooks: `modules/addons/vpnhoodconfig/`, `includes/hooks/`
- Wholesale gateway: `modules/addons/vpnhoodpartnerhub/` (+ its `README.md` for the API)
- Developer guide: `docs/ARCHITECTURE.md`
