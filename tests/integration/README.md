# Partner Hub — integration tests

## Connector lifecycle scripts — buyer↔connector↔Hub end-to-end

Five separate scripts, one per lifecycle action, all driving the real buyer
journey on the dev WHMCS through `localAPI()` and the core `applyCredit()` —
never a raw INSERT/UPDATE against orders, invoices, or hosting. Each uploads
its `.test.php` (plus the shared `lib/common.php`) over SSH and runs it on the
dev box; each needs SSH + admin credentials (`secrets.json`).

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

## purchase-recovery.test.sh — purchases when things go wrong

**`purchase-recovery.test.sh [scenario ...]`** — runs the scenarios of
`purchase-recovery.test.php` on the dev box, each a failure set up the way it happens in
production and ending in the recovery the design promises (one order, charged once, one
token). Orders go through the real Hub API over HTTPS as the test partner (`.env`).

| Scenario | What it proves |
| --- | --- |
| `insufficient-credit` | too little credit: `402`, nothing applied, rollback verified, key released; topped up, the same key buys once |
| `failure-after-payment` | an access server that rejects the token request (a non-existent server farm, restored in a `finally`): the paid order is kept (`409 not_provisioned`), a keyed repeat is refused; the support procedure's evidence (module log: the token `POST` itself rejected; no token listed), Create on the service, Retry; the repeat returns the original order |
| `keyless-failure-after-payment` | the same without a key: an unfinished order cannot be linked yet; after the fix, `getAccessCode` returns its key |
| `failed-rollback` | credit moving during a rollback (a dev-only hook, `hooks/vhtest-rollback-interference.php`, installed and removed around it): `needs_reconciliation`, the key kept, Release frees it |
| `crash-resume` | the purchase row set back to `created`, `ordered`, `paid` (a request that died before saving): each repeat returns the same order, no charge, no second token; a row that died before `AddOrder` orders |
| `token-record-lost` | token created, its id lost: the repeat is refused, the listed token is adopted, Retry finishes it — Create never pressed |
| `csv` | a CSV product (mapped for the run): batch delivery, replay, completion by the batch mark, `getAccessCode` by type (keyed and keyless); the batches are expired and disabled afterwards |
| `views` | the client area's "VpnHood order #N — your reference R" and the admin tab's purchase record |
| `link-race` | two keys linking one keyless order at once: exactly one wins |
| `shared-credit` | a renewal and an order at once with credit for one: one succeeds, one `402`, never negative (runs WHMCS's Generate Invoices) |
| `init` | first-use initialization held elsewhere: a keyed order waits, then `409 initializing`, never buys; an interrupted back-fill is resumed |

⚠ Spends reseller (test) credit and provisions real tokens; everything it orders is
terminated (or, for CSV batches, expired and disabled).

## connector-idempotency.test.sh — the connector's side, and compatibility

**`connector-idempotency.test.sh [phase ...]`** — drives the connector the way a partner's
WHMCS does (`ModuleCreate`/`ModuleTerminate` on the buyer's service), deploying older
releases from their git tags where a phase needs them and the working tree of both repos
again on exit:

- `current` — a lost response recovered by Create again (same order, no charge), two
  concurrent Creates of one service (one order), Terminate then Create (a new key and
  order; the old key `409 key_spent`).
- `old-connector` — the connector release partners run today (`OLD_CONNECTOR`, default
  `v1.2.1`, an older release) against this Hub: a repeated Create buys again, as it always
  did, and the whole buyer lifecycle passes (`purchase-order`, `suspend`, `unsuspend`,
  `terminate`, `renew`).
- `legacy` — orders placed by the old connector, their responses "lost", then this
  connector: Create stops with `reconcile` (and learns `idempotency-v1` from that `409` with
  an empty cache), the Module tab offers Link, a wrong link is refused, Link returns the
  original order, "Order a new key" buys once.
- `old-hub` — this connector against a Hub without `idempotency-v1` (`OLD_HUB`, default `v1.2.8`): the
  cached `idempotency-v1` is dropped, the admin is told not to press Create again, no Link is
  offered, a repeat there buys again; back on this Hub, the feature is learned again.

⚠ Spends buyer and reseller (test) credit; `old-connector` runs `purchase-order.test.sh`,
which wipes the buyer's and reseller's earlier orders.

## refund.test.sh — partner refunds

**`refund.test.sh [scenario ...]`** — runs the scenarios of `refund.test.php` on the dev box.
Orders go through the real Hub API over HTTPS as the test partner (`.env`); the connector
scenarios press the Refund button through `ModuleCustom` on a buyer service.

| Scenario | What it proves |
| --- | --- |
| `refund` | a new key refunds: key disabled, service Terminated, the price back once with its credit row and activity-log line; a repeat answers `refunded` and returns nothing more; `suspend`/`unsuspend`/`renew` then `409 service_ended`, `terminate` still runs |
| `terminate-first` | terminate, then refund: the module Terminate runs again on the Terminated service, the price comes back |
| `suspended` | a suspended key refunds; `unsuspend` is refused afterwards |
| `window` | with the default 7 days, paid 6 days ago refunds; paid 8 days ago: `refund_window_closed`, nothing ended or returned; `PartnerRefundDays` = 10 refunds it; `0` refuses (refunds off) |
| `later-invoice` | a key with a renewal invoice (`GenInvoices`, then cancelled) is `not_refundable` |
| `ended-line` | two keys on one renewal invoice, one terminated: renewing the other takes the ended key's line off, pays only its own and moves its due date on; the ended key's due date stays and its purchase refunds to the credit. With 0.50 already paid on such an invoice, or the live key's line at 0.00 (nothing else left to pay), `renew` is `409 renewal_blocked` and pays nothing |
| `records` | an unfinished purchase, no purchase record, an extra invoice line, a refund booked by hand: each `not_refundable` and changes nothing; restored, the order refunds |
| `concurrent` | two refunds of one order at once: both `refunded`, one credit |
| `lock` | an unsuspend and a refund queued behind the partner's credit lock (held by the test): whichever runs first, the key ends and the price returns once |
| `timeout` | a suspend that cannot get the lock in 15 s: `409 in_progress`, nothing changed |
| `connector` | the connector's Refund: buyer service Terminated, idempotency key cleared, the reseller's credit back, pressing it again returns nothing more; against the previous Hub release (`OLD_HUB`, default `v1.2.9`, deployed from its tag and replaced again on exit) it says the Hub does not offer refunds and changes nothing |

⚠ Spends reseller and buyer (test) credit and provisions real tokens; every order ends refunded
or terminated. Settings, invoice dates and lines, a ledger row and purchase-record fields a
scenario changes are restored in a `finally`.

The rules themselves (`RefundPolicy`) are unit-tested without WHMCS: `tests/unit/run.sh`.

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
5. The code under test deployed: `scripts/deploy-dev.sh hub` (`all` for the connector too).
   The `.test.sh` scripts upload only their own test files, so they test whatever the dev
   WHMCS runs at the time.

## Running

```bash
cd tests/integration
cp .env.example .env          # then edit .env with your URL + partner key/secret
./hub-api.test.sh
```

`.env` is gitignored. Credentials are read from the environment — nothing is
hard-coded in the script or committed.

- **Read-only by default** — auth failures, the `X-Vpnhood-Hub-Features` header on success
  and error responses, `getBalance`, `getProducts`, the "unknown product → 403" check, and
  request validation (a malformed or multi-unit `idempotencyKey`, `linkOrder` without a key).
  Safe to run anytime; spends nothing.
- **Provisioning run** — set `HUB_RUN_PROVISION=1`. ⚠️ This **spends partner credit** and
  **provisions real keys** on the access server:
  - a **keyed** order placed as two concurrent identical calls, then repeated: one order,
    charged once, the others `replayed`; the same key with another reference `409
    key_mismatch`; `getOrder` and `getAccessCode`. This order is left **Active** unless
    `HUB_RUN_TERMINATE=1`.
  - **keyless** orders: a repeat buys a second order; a keyed order under their reference
    `409 reconcile` listing both; `linkOrder` binds one (and answers again when repeated),
    refuses another order for the same key and another key for the same order; the linked key
    replays it; `confirmNewPurchase` buys; after they are terminated, the key is `key_spent`.
    These orders are always terminated at the end.
- **Lifecycle jobs** — renewal, suspension, and termination are separate,
  opt-in jobs layered on top of a provisioning run; none of them run unless
  you ask for them explicitly:
  - `HUB_RUN_SUSPEND=1` — `suspend` → (a suspended keyed order still replays) → `unsuspend`
  - `HUB_RUN_RENEW=1` — `renew` (expects 409 if no renewal invoice is due yet)
  - `HUB_RUN_TERMINATE=1` — `terminate`; then the key is `key_spent`, and a new key buys a
    replacement, terminated too

Exit code is non-zero if any assertion fails, so it is CI-friendly.

## Notes

- `HUB_INSECURE=1` adds `-k` to curl for self-signed dev certificates.
- The script only needs `bash` and `curl`. No `jq` dependency (it extracts the
  `upstreamOrderId` with a simple grep, which is fine for these flat responses).
