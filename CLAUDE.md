# CLAUDE.md

Guidance for working in this repository.

## What this is

WHMCS integration for **VpnHood** VPN. Two integration models live across two repos, plus the
in-app purchase addon this repo's release bundles:

- **VpnHood.WHMCS** (this repo) — runs on **our** WHMCS:
  - `vpnhoodstore` — provisions access tokens directly against the VpnHood access server.
  - `vpnhoodconfig` — global API settings + product-visibility hook.
  - `vpnhoodpartnerhub` — wholesale gateway: a partner-scoped API that lets external partner
    WHMCS installs order/provision against this WHMCS using their **native WHMCS credit**.
  - `vpnhoodverify` — forces client-area email verification (WHMCS's own setting only
    mails the link; it blocks nothing).
- **VpnHood.WHMCS.Partner** (separate repo) — the connector partners install on their own WHMCS.
- **VpnHood.WHMCS.Iap** (separate repo) — the `vpnhoodiap` addon (app-store purchases → WHMCS
  orders). The release package bundles it **verbatim**, at the release pinned in `IAP_VERSION`.

## Read this first

**Before changing anything, read [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).** It is the
authoritative developer guide: module responsibilities, the Partner Hub data model, the
`order` request lifecycle, the reuse contract, and how to extend safely. Keep it updated
when you change architecture, the DB schema, or the API.

## Key rules

- **Folder naming:** lowercase letters/numbers only (no underscores/spaces).
- **Never hand-edit a module's version.** The root `VERSION` file is the single source of
  truth; `scripts/set-version.sh` stamps it into every module. The release workflow bumps it
  when run by hand — see "Versioning & releases" in `docs/ARCHITECTURE.md`.
- **Bump the version for ANY change to a module's files** — templates, hooks, a comment —
  not only schema or DB changes. WHMCS decides what to reinstall by comparing the stamped
  version, so an edit that ships under an unchanged number is an edit that silently does
  not reach the install.
- **No build/lint/test tooling** is configured here (no PHP CLI in this environment). The only
  CI is `.github/workflows/release.yml`, which versions and tags — it does not build or test.
  Verify on a live WHMCS — see the verification notes in `docs/ARCHITECTURE.md` and each README.

## Production vs. dev/test layout

Production code (what gets deployed to a WHMCS) lives ONLY under `modules/` and
`includes/`. Everything for development and testing stays in its own folder — never
mix test/dev files into the production tree:

- `VERSION` + `scripts/set-version.sh` — the repo version and the script that stamps it into
  every module. `.github/workflows/release.yml`, run by hand, bumps, tags and releases.
- `IAP_VERSION` — the `vpnhoodiap` release the package bundles.
- `scripts/` — dev tooling. `scripts/deploy-dev.sh [hub|partner|iap|all]` publishes the
  modules to the dev WHMCS (staged upload + md5 verify + server-side `php -l` + API
  smoke check). It deploys only `modules/` and `includes/hooks`, so test files can
  never reach the server.
- `tests/` — all tests. `tests/bootstrap/init-skeleton.sh` idempotently ensures the
  dev-WHMCS fixtures (group, products, test clients, hub partner) — run it before any
  test. `tests/integration/` = API tests; `tests/e2e/` = Playwright browser tooling.
- `docs/` — developer docs.

## Dev server & credentials

- Credentials live outside the repo: admin logins in `<Vh root>\.user\account-dev.vpnhood.com\secrets.json`
  (the `.user/<host>/` convention), and the SSH key in `<Vh root>\.user\ssh\ssh.openssh`.
- Dev WHMCS for verification/testing: `ssh -i <Vh root>\.user\ssh\ssh.openssh
  whmcsdev@webhost-ftps.vpnhood.com`, web root
  `/home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html`, site `https://whmcs-dev.vpnhood.com`.

## Where things are

- Retail provisioning: `modules/servers/vpnhoodstore/`
- Global settings + hooks: `modules/addons/vpnhoodconfig/`, `includes/hooks/`
- Wholesale gateway: `modules/addons/vpnhoodpartnerhub/` (+ its `README.md` for the API)
- Forced email verification: `modules/addons/vpnhoodverify/` (+ its `README.md`)
- Developer guide: `docs/ARCHITECTURE.md`

## Area rules

Loaded automatically by file path. When a task touches an area before any of its files has been
opened (a question, a plan), read the rule file first. A new rule for one area goes into its rule
file.

- [.claude/rules/partner-hub.md](.claude/rules/partner-hub.md) — the Partner Hub's rules: reuse
  `vpnhoodstore`'s provisioning, native credit only, scope to `partner.client_id`, the hub API
  as a cross-repo contract.
