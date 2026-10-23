---
paths:
  - "modules/addons/vpnhoodpartnerhub/**"
---

## Key rules

- **Reuse, don't duplicate provisioning.** Access-server calls go through
  `modules/servers/vpnhoodstore/lib/ApiService.php` and `Helper.php`. `vpnhoodpartnerhub`
  must call these, never re-implement access-server logic.
- **Native credit is the source of truth.** Do not build a custom credit ledger; partner
  spend is the WHMCS client credit (`tblclients.credit`). Never provision an unpaid order.
- **Scope every partner action** to `partner.client_id`; never trust ids from the request.
- **Hub API is a cross-repo contract.** If you change the action set or payloads, update the
  matching docs in the **VpnHood.WHMCS.Partner** repo in the same change, or the connector breaks.
