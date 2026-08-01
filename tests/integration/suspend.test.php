<?php
/**
 * suspend.test.php — suspend the buyer's active partner service.
 *
 * Runs ON the dev server (uploaded by suspend.test.sh, alongside
 * lib/common.php). Never runs its own cleanup — it acts on whatever
 * purchase-order.test.sh last left behind, and requires the buyer to
 * currently hold an Active, partner-type service (either payment type —
 * suspend applies to both one-time and recurring).
 *
 * Flow:
 *   1. find the buyer's Active partner-type service (any payment type), and
 *      resolve the reseller's upstream service from its stored upstreamOrderId
 *   2. localAPI('ModuleSuspend') on the buyer's service — triggers the real
 *      vpnhoodpartner_SuspendAccount -> Hub suspend action -> the upstream
 *      service is suspended too
 *   3. assert both sides are Suspended and the reseller's suspendreason
 *      matches what was passed to ModuleSuspend (the connector must relay it,
 *      not just the status)
 *
 * Prints a JSON report; exits non-zero if any assertion fails.
 */

require __DIR__ . '/lib/common.php';

$buyer = clientByEmail($db, BUYER_EMAIL);
$reseller = clientByEmail($db, RESELLER_EMAIL);
if (!$buyer || !$reseller) {
    bad('fixtures missing — run tests/bootstrap/init-skeleton.sh first');
    finish();
}

$buyerService = findBuyerPartnerService($db, (int)$buyer['id'], null, ['Active']);
$buyerServiceId = (int)$buyerService['hostingid'];
ok("buyer's active partner service found: #$buyerServiceId ({$buyerService['productname']}, {$buyerService['paytype']})");

$upstreamOrderId = (int) serviceProperty($db, $buyerServiceId, 'upstreamOrderId');
if ($upstreamOrderId <= 0) {
    bad("service #$buyerServiceId has no upstreamOrderId in its service properties");
    finish();
}
$resellerService = one(
    $db,
    'SELECT id, domainstatus FROM tblhosting WHERE orderid=? AND userid=?',
    [$upstreamOrderId, $reseller['id']]
);
if (!$resellerService) {
    bad("upstream reseller service not found for order #$upstreamOrderId");
    finish();
}
$resellerServiceId = (int)$resellerService['id'];
ok("upstream order #$upstreamOrderId -> reseller service #$resellerServiceId ({$resellerService['domainstatus']})");

$reason = 'suspend.test.php';
$suspend = localAPI('ModuleSuspend', ['serviceid' => $buyerServiceId, 'suspendreason' => $reason]);
if (($suspend['result'] ?? '') !== 'success') {
    bad('ModuleSuspend failed: ' . json_encode($suspend));
    finish();
}
ok("buyer service #$buyerServiceId ModuleSuspend called, relayed through the connector to the Hub");

$buyerRow = one($db, 'SELECT domainstatus, suspendreason FROM tblhosting WHERE id=?', [$buyerServiceId]);
if (($buyerRow['domainstatus'] ?? '?') === 'Suspended') {
    ok("buyer service #$buyerServiceId Suspended");
} else {
    bad("buyer service #$buyerServiceId not Suspended (status: " . ($buyerRow['domainstatus'] ?? '?') . ')');
}
if (($buyerRow['suspendreason'] ?? '') === $reason) {
    ok("buyer service #$buyerServiceId suspendreason stored: $reason");
} else {
    $got = $buyerRow['suspendreason'] ?? '';
    bad("buyer service #$buyerServiceId suspendreason mismatch (expected '$reason', got '$got')");
}

$resellerRow = one($db, 'SELECT domainstatus, suspendreason FROM tblhosting WHERE id=?', [$resellerServiceId]);
if (($resellerRow['domainstatus'] ?? '?') === 'Suspended') {
    ok("reseller service #$resellerServiceId Suspended");
} else {
    bad("reseller service #$resellerServiceId not Suspended (status: " . ($resellerRow['domainstatus'] ?? '?') . ')');
}
if (($resellerRow['suspendreason'] ?? '') === $reason) {
    ok("reseller service #$resellerServiceId suspendreason synced: $reason");
} else {
    $got = $resellerRow['suspendreason'] ?? '';
    bad("reseller service #$resellerServiceId suspendreason NOT synced (expected '$reason', got '$got')");
}

finish();
