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
 *   3. assert both sides are Suspended
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

$suspend = localAPI('ModuleSuspend', ['serviceid' => $buyerServiceId, 'suspendreason' => 'suspend.test.php']);
if (($suspend['result'] ?? '') !== 'success') {
    bad('ModuleSuspend failed: ' . json_encode($suspend));
    finish();
}
ok("buyer service #$buyerServiceId ModuleSuspend called, relayed through the connector to the Hub");

$buyerStatus = one($db, 'SELECT domainstatus FROM tblhosting WHERE id=?', [$buyerServiceId])['domainstatus'] ?? '?';
if ($buyerStatus === 'Suspended') {
    ok("buyer service #$buyerServiceId Suspended");
} else {
    bad("buyer service #$buyerServiceId not Suspended (status: $buyerStatus)");
}

$resellerStatus = one($db, 'SELECT domainstatus FROM tblhosting WHERE id=?', [$resellerServiceId])['domainstatus'] ?? '?';
if ($resellerStatus === 'Suspended') {
    ok("reseller service #$resellerServiceId Suspended");
} else {
    bad("reseller service #$resellerServiceId not Suspended (status: $resellerStatus)");
}

finish();
