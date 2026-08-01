# Partner Hub — integration tests

## Connector lifecycle scripts — buyer↔connector↔Hub end-to-end

Five separate scripts, one per lifecycle action, all driving the real buyer
journey on the dev WHMCS through `localAPI()` and the core `applyCredit()` —
never a raw INSERT/UPDATE against orders, invoices, or hosting. Each uploads
its `.test.php` (plus the shared `lib/common.php`) over SSH and runs it on the
dev box; each needs SSH + admin credentials (`secrets-dev.json`).

**`purchase-order.test.sh`** — the only script that cleans up, and the only
entry point that creates a new service. Runs `tests/bootstrap/init-skeleton.sh`
first (`SKIP_INIT=1` to skip), then wipes any pre-existing orders/services/
invoices for **both** the test buyer and reseller (any hosting still Active/
Suspended is terminated first, releasing its real access token) so every run
starts from a clean slate. It then asks — interactively, unless
`PRODUCT_TYPE=onetime|recurring` is set — whether to buy a One-time or
Recurring product (always a one-month billing cycle), places a real
`AddOrder` for the buyer, pays the resulting invoice from the buyer's own
credit, and accepts the order (`autosetup`) so the connector really
provisions through the Hub from the reseller's credit. Asserts: buyer
order/service Active, buyer credit debited, `accessCode`/`upstreamOrderId`
stored, and the reseller's upstream order/service Active with reseller credit
debited too. ⚠ Spends real buyer **and** reseller (test) credit and
provisions a real access token. The order is left **Active** on both sides —
this script never suspends, renews, or terminates anything.

**`renew.test.sh`** — requires an Active, **recurring**, partner-type service
for the buyer (buy one with `purchase-order.test.sh` first; renew never
applies to one-time products). Forces both the buyer's and the reseller's
service due a few days in the past (`UpdateClientProduct` — there's no real
customer action for "a month has passed", so this is the one place the suite
doesn't mirror client-area behavior), then runs WHMCS's own real "Generate
Invoices" cron task directly over SSH (PHP-CLI here has `exec`/`shell_exec`/
`proc_open` disabled, so the shell step can't run from inside the PHP script)
so both sides get a genuine renewal invoice, then pays the buyer's from the
buyer's credit — which is what makes WHMCS itself advance `nextduedate` and
fire the module's `_Renew` hook, relaying through the Hub to settle the
reseller's own renewal invoice from the reseller's credit. Asserts both sides:
invoice Paid, credit debited, `nextduedate` advanced. ⚠ Spends real buyer and
reseller credit, and runs WHMCS's real cron task (sweeps every due service on
this dev WHMCS — fine here, every client on it is a test account). Does
**not** clean up.

**`suspend.test.sh`** / **`unsuspend.test.sh`** / **`terminate.test.sh`** —
each finds the buyer's current **partner-type** service (`servertype =
vpnhoodpartner`), **regardless of payment type** (one-time or recurring both
qualify), in the status the action expects (Active for suspend, Suspended for
unsuspend, Active-or-Suspended for terminate), and calls the matching
`localAPI('Module...')` action — the same one the admin panel's button uses —
which relays through the real `vpnhoodpartner_*` hook to the Hub, asserting
both the buyer's and the reseller's service end up in the expected status.
None of these three clean up before or after running.

Only `purchase-order.test.sh` ever wipes state; the other four act on
whatever it last left behind.

## sync-products.test.sh — the connector addon's product sync

**`sync-products.test.sh`** — covers the `vpnhoodpartnerconfig` addon page's "create
missing products" button (the `VpnHood.WHMCS.Partner` repo). It offers one extra
product to the test partner via a temporary Hub mapping (added through the Hub's own
`PartnerRepository`, the code path the admin UI uses), reads it back over the live Hub
HTTP API exactly as the addon page does, then runs the real sync with three refs at
once — the new one, one that already exists locally, and one the Hub never offered.
Asserts exactly one product created, the existing one skipped rather than duplicated,
the un-offered one refused, and that the new product is wired to `vpnhoodpartner` with
the right `configoption1`, hidden, priced 0.00 on exactly the upstream's billing cycles,
with `allowqty` off — then re-runs to prove idempotency.

Independent of the lifecycle scripts and safe to run anytime: it places no order, so it
spends **no credit** and provisions **no access token**. It removes both the product it
creates and the temporary mapping whether or not the assertions pass.

## hub-api.test.sh — Hub API black-box test

A black-box smoke test for the `vpnhoodpartnerhub` API. It drives the live HTTP
endpoint exactly as a partner connector would, and asserts the auth, read, order,
and lifecycle behaviour.

These are **integration** tests: they need a real WHMCS running the Hub addon.
There is no PHP unit-test harness in this project (no toolchain is configured).

## Prerequisites

On the WHMCS running the Hub:

1. `vpnhoodpartnerhub` activated (tables created) and `vpnhoodstore` / `vpnhoodconfig`
   configured against the access server.
2. A **partner** created (Addons → VpnHood! Partner Hub), linked to a WHMCS client.
3. At least one **product mapping** (a `downstreamRef` → one of your `vpnhoodstore`
   products) enabled for that partner.
4. For the provisioning run only: the partner's client must hold **enough credit**
   for one order.

## Running

```bash
cd tests/integration
cp .env.example .env          # then edit .env with your URL + partner key/secret
./hub-api.test.sh
```

`.env` is gitignored. Credentials are read from the environment — nothing is
hard-coded in the script or committed.

- **Read-only by default** — auth failures, `getBalance`, `getProducts`, and the
  "unknown product → 403" check. Safe to run anytime; spends nothing.
- **Provisioning run** — set `HUB_RUN_PROVISION=1` to exercise
  `order` → `getOrder` → `getAccessCode`. ⚠️ This **spends partner credit** and
  **provisions a real key** on the access server. The order is left **Active**
  afterward — nothing else runs automatically.
- **Lifecycle jobs** — renewal, suspension, and termination are separate,
  opt-in jobs layered on top of a provisioning run; none of them run unless
  you ask for them explicitly:
  - `HUB_RUN_SUSPEND=1` — `suspend` → `unsuspend`
  - `HUB_RUN_RENEW=1` — `renew` (expects 409 if no renewal invoice is due yet)
  - `HUB_RUN_TERMINATE=1` — `terminate`

Exit code is non-zero if any assertion fails, so it is CI-friendly.

## Notes

- `HUB_INSECURE=1` adds `-k` to curl for self-signed dev certificates.
- The script only needs `bash` and `curl`. No `jq` dependency (it extracts the
  `upstreamOrderId` with a simple grep, which is fine for these flat responses).
