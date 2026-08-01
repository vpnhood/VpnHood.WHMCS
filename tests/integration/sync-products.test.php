<?php
/**
 * sync-products.test.php — the connector addon's "create missing products" sync.
 *
 * Runs ON the dev server (uploaded by sync-products.test.sh, alongside
 * lib/common.php). Exercises the real vpnhoodpartnerconfig sync against the live
 * Hub API — the same functions the addon page's button calls.
 *
 * Flow:
 *   1. offer ONE extra product to the test partner by adding a temporary mapping
 *      through the Hub's own PartnerRepository (the code path the Hub admin UI's
 *      "Add Product" button uses)
 *   2. read it back over the live Hub HTTP API, exactly as the addon page does
 *   3. run the sync with three refs ticked at once — the new one, one that
 *      already exists locally, and one the Hub never offered
 *   4. assert: exactly one product created, the existing one skipped (not
 *      duplicated), the un-offered one refused, and the new product is wired to
 *      the connector, hidden, unpriced, and carries exactly the upstream's cycles
 *   5. re-run to prove the sync is idempotent
 *
 * Cleans up after itself: both the created product and the temporary mapping are
 * removed, whether or not the assertions pass. Spends nothing and provisions
 * nothing — it never places an order.
 *
 * Prints a JSON report; exits non-zero if any assertion fails.
 */

require __DIR__ . '/lib/common.php';

require_once WEBROOT . '/modules/addons/vpnhoodpartnerconfig/vpnhoodpartnerconfig.php';
require_once WEBROOT . '/modules/addons/vpnhoodpartnerhub/lib/PartnerRepository.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodPartnerHub\PartnerRepository;
use WHMCS\Module\Server\VpnHoodPartner\HubClient;

/** Ref for the throwaway mapping; never collides with a real one. */
const PROBE_REF = 'zz-sync-probe';

// -------------------------------------------------------------------- fixtures
$repo = new PartnerRepository();
$partnerId = (int) (one($db, 'SELECT id FROM mod_vpnhood_partners ORDER BY id LIMIT 1')['id'] ?? 0);
$groupId = (int) (one($db, 'SELECT id FROM tblproductgroups ORDER BY id LIMIT 1')['id'] ?? 0);
// Any vpnhoodstore product will do — the sync only reads what the Hub reports about it.
$sourceProduct = (int) (one($db, "SELECT id FROM tblproducts WHERE servertype='vpnhoodstore' ORDER BY id LIMIT 1")['id'] ?? 0);

if ($partnerId <= 0 || $groupId <= 0 || $sourceProduct <= 0) {
    bad('fixtures missing — run tests/bootstrap/init-skeleton.sh first');
    finish();
}
ok("fixtures present (partner #$partnerId, group #$groupId, source product #$sourceProduct)");

$mappingId = 0;
$createdPid = 0;
try {
    // ------------------------------------------ offer one more product upstream
    $repo->addProductMapping($partnerId, [
        'downstream_ref'       => PROBE_REF,
        'whmcs_product_id'     => $sourceProduct,
        'billing_cycle_months' => $repo->productBillingCycleMonths($sourceProduct),
        'enabled'              => 1,
    ]);
    $mappingId = (int) one(
        $db,
        'SELECT id FROM mod_vpnhood_partner_products WHERE partner_id=? AND downstream_ref=?',
        [$partnerId, PROBE_REF]
    )['id'];
    ok("temporary hub mapping #$mappingId added (" . PROBE_REF . " -> product #$sourceProduct)");

    // ------------------------------------- read it back over the live Hub API
    vpnhoodpartnerconfig_loadHubClient();
    $hub = HubClient::fromConfig();
    $upstream = [];
    foreach ($hub->call('getProducts')['products'] ?? [] as $p) {
        $upstream[(string) $p['downstreamRef']] = $p;
    }
    if (!isset($upstream[PROBE_REF])) {
        bad('the new mapping did not appear in getProducts: ' . implode(', ', array_keys($upstream)));
        finish();
    }
    ok('connector sees the new product over the live Hub API');

    // --------------------------------------------------------------- run sync
    // Tick the missing one, one that already exists, and one never offered.
    $existingRefs = array_keys(array_intersect_key(vpnhoodpartnerconfig_localProductsByRef(), $upstream));
    $alreadyRef = $existingRefs[0] ?? '';
    $selected = array_values(array_filter([PROBE_REF, $alreadyRef, 'zz-not-offered']));

    $result = vpnhoodpartnerconfig_syncProducts($upstream, $selected, $groupId);

    if (count($result['created']) === 1) {
        ok('sync created exactly 1 product');
    } else {
        bad('sync created ' . count($result['created']) . ' product(s), expected 1');
    }
    if ($alreadyRef !== '' && $result['skipped'] === 1) {
        ok("existing product for ref '$alreadyRef' was skipped, not duplicated");
    } else {
        bad('existing-ref skip wrong: skipped=' . $result['skipped'] . ", alreadyRef='$alreadyRef'");
    }
    if (count($result['errors']) === 1 && strpos($result['errors'][0], 'zz-not-offered') !== false) {
        ok('a ref the Hub never offered was refused');
    } else {
        bad('un-offered ref not refused: ' . json_encode($result['errors']));
    }

    $createdPid = (int) array_key_first($result['created']);
    if ($createdPid <= 0) {
        finish();
    }

    // --------------------------------------------- assert the created product
    $p = one($db, 'SELECT * FROM tblproducts WHERE id=?', [$createdPid]);
    if ($p['servertype'] === 'vpnhoodpartner' && $p['configoption1'] === PROBE_REF) {
        ok("product #$createdPid is wired to vpnhoodpartner with the Upstream Product preselected");
    } else {
        bad("wiring wrong: servertype={$p['servertype']}, configoption1={$p['configoption1']}");
    }

    (int) $p['hidden'] === 1
        ? ok('product created hidden — it cannot be sold before it is priced')
        : bad('product is NOT hidden');
    (int) $p['gid'] === $groupId
        ? ok("product created in the chosen group #$groupId")
        : bad("wrong product group: {$p['gid']}");
    $p['paytype'] === $upstream[PROBE_REF]['paymentType']
        ? ok("Payment Type matches upstream ({$p['paytype']})")
        : bad("paytype {$p['paytype']} != upstream " . $upstream[PROBE_REF]['paymentType']);
    // The connector stores one upstream order id + access code per service, so a
    // synced product must never inherit "Allow Multiple Quantities".
    (int) $p['allowqty'] === 0
        ? ok('Allow Multiple Quantities left off')
        : bad("allowqty was set to {$p['allowqty']}");

    // WHMCS marks a disabled cycle with -1, so ">= 0" is "enabled".
    $pricing = one($db, "SELECT * FROM tblpricing WHERE type='product' AND relid=? ORDER BY currency", [$createdPid]);
    $expected = [];
    foreach ($upstream[PROBE_REF]['availableCycles'] as $months) {
        $expected[] = VPNHOODPARTNERCONFIG_CYCLE_COLUMNS[(int) $months];
    }
    $got = [];
    foreach (VPNHOODPARTNERCONFIG_CYCLE_COLUMNS as $column) {
        if ((float) $pricing[$column] >= 0) {
            $got[] = $column;
        }
    }
    sort($expected);
    sort($got);
    $expected === $got
        ? ok('enabled billing cycles match upstream exactly: ' . implode(', ', $got))
        : bad('cycles wrong — got [' . implode(',', $got) . '] expected [' . implode(',', $expected) . ']');

    $nonZero = array_filter($got, fn($column) => (float) $pricing[$column] != 0.0);
    $nonZero
        ? bad('a cycle was created with a non-zero price: ' . implode(', ', $nonZero))
        : ok('every enabled cycle is priced 0.00 — the partner sets their own retail price');

    vpnhoodpartnerconfig_needsPricing($createdPid)
        ? ok('the addon page flags the new product as "Needs pricing"')
        : bad('needsPricing() did not flag an unpriced product');

    // ------------------------------------------------ re-run must be a no-op
    $again = vpnhoodpartnerconfig_syncProducts($upstream, [PROBE_REF], $groupId);
    (count($again['created']) === 0 && $again['skipped'] === 1)
        ? ok('re-running the sync creates nothing (idempotent)')
        : bad('second run was not a no-op: ' . json_encode($again));

    $dupes = (int) one(
        $db,
        "SELECT COUNT(*) c FROM tblproducts WHERE servertype='vpnhoodpartner' AND configoption1=?",
        [PROBE_REF]
    )['c'];
    $dupes === 1 ? ok('still exactly one local product for the ref') : bad("$dupes products share the ref");
} catch (Throwable $e) {
    bad('exception: ' . $e->getMessage());
} finally {
    // WHMCS 9 has no DeleteProduct API, so the probe product is removed directly.
    // Nothing was ever ordered on it, so there is no order/invoice/service state.
    if ($createdPid > 0) {
        $db->prepare("DELETE FROM tblpricing WHERE type='product' AND relid=?")->execute([$createdPid]);
        $db->prepare('DELETE FROM tblproducts WHERE id=?')->execute([$createdPid]);
        ok("cleaned up probe product #$createdPid");
    }
    if ($mappingId > 0) {
        $repo->deleteProductMapping($mappingId);
        ok("cleaned up temporary hub mapping #$mappingId");
    }
}

finish();
