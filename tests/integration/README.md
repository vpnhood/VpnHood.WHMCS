# Partner Hub — integration tests

## connector-order.test.sh — buyer order end-to-end

The full chain on the dev WHMCS: the buyer orders the connector product, the
connector provisions through the Hub from the reseller's credit, the access
code lands in the service properties, credit is debited, and the service is
terminated again. Runs `tests/bootstrap/init-skeleton.sh` first (`SKIP_INIT=1`
to skip) and needs SSH + admin credentials (`secrets-dev.json`).
⚠ Spends ~2 USD reseller test credit and provisions a real access token
(released by the final terminate; keep it with `CONNECTOR_TERMINATE=0`).

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
- **Provisioning run** — set `HUB_RUN_PROVISION=1` to also exercise
  `order` → `getOrder` → `suspend` → `unsuspend` → `renew` → `terminate`.
  ⚠️ This **spends partner credit** and **provisions a real key** on the access
  server. By default it terminates the service again at the end (`HUB_TERMINATE=1`).

Exit code is non-zero if any assertion fails, so it is CI-friendly.

## Notes

- `HUB_INSECURE=1` adds `-k` to curl for self-signed dev certificates.
- The script only needs `bash` and `curl`. No `jq` dependency (it extracts the
  `upstreamServiceId` with a simple grep, which is fine for these flat responses).
