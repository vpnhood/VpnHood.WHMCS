<?php
/**
 * unsuspend.test.php — unsuspend the buyer's suspended partner service.
 *
 * Runs ON the dev server (uploaded by unsuspend.test.sh, alongside
 * lib/common.php). Never runs its own cleanup — it acts on whatever
 * suspend.test.sh last left behind, and requires the buyer to currently hold
 * a Suspended, partner-type service (either payment type — unsuspend applies
 * to both one-time and recurring).
 *
 * Flow:
 *   1. find the buyer's Suspended partner-type service (any payment type),
 *      and resolve the reseller's upstream service from its stored
 *      upstreamOrderId
 *   2. localAPI('ModuleUnsuspend') on the buyer's service — triggers the real
 *      vpnhoodpartner_UnsuspendAccount -> Hub unsuspend action -> the
 *      upstream service is unsuspended too
 *   3. assert both sides are Active
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

$buyerService = findBuyerPartnerService($db, (int)$buyer['id'], null, ['Suspended']);
$buyerServiceId = (int)$buyerService['hostingid'];
ok("buyer's suspended partner service found: #$buyerServiceId ({$buyerService['productname']}, {$buyerService['paytype']})");

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

$unsuspend = localAPI('ModuleUnsuspend', ['serviceid' => $buyerServiceId]);
if (($unsuspend['result'] ?? '') !== 'success') {
    bad('ModuleUnsuspend failed: ' . json_encode($unsuspend));
    finish();
}
ok("buyer service #$buyerServiceId ModuleUnsuspend called, relayed through the connector to the Hub");

$buyerStatus = one($db, 'SELECT domainstatus FROM tblhosting WHERE id=?', [$buyerServiceId])['domainstatus'] ?? '?';
if ($buyerStatus === 'Active') {
    ok("buyer service #$buyerServiceId Active");
} else {
    bad("buyer service #$buyerServiceId not Active (status: $buyerStatus)");
}

$resellerStatus = one($db, 'SELECT domainstatus FROM tblhosting WHERE id=?', [$resellerServiceId])['domainstatus'] ?? '?';
if ($resellerStatus === 'Active') {
    ok("reseller service #$resellerServiceId Active");
} else {
    bad("reseller service #$resellerServiceId not Active (status: $resellerStatus)");
}

finish();
