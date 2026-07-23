<?php
/**
 * terminate.test.php — terminate the buyer's partner service.
 *
 * Runs ON the dev server (uploaded by terminate.test.sh, alongside
 * lib/common.php). Never runs its own cleanup — it acts on whatever
 * purchase-order.test.sh (or suspend.test.sh) last left behind, and requires
 * the buyer to currently hold an Active OR Suspended, partner-type service
 * (either payment type — terminate applies to both one-time and recurring).
 *
 * Flow:
 *   1. find the buyer's Active/Suspended partner-type service (any payment
 *      type), and resolve the reseller's upstream service from its stored
 *      upstreamOrderId
 *   2. localAPI('ModuleTerminate') on the buyer's service — triggers the
 *      real vpnhoodpartner_TerminateAccount -> Hub terminate action -> the
 *      upstream service is terminated too, releasing the real access token
 *   3. assert both sides are Terminated
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

$buyerService = findBuyerPartnerService($db, (int)$buyer['id'], null, ['Active', 'Suspended']);
$buyerServiceId = (int)$buyerService['hostingid'];
ok("buyer's partner service found: #$buyerServiceId ({$buyerService['productname']},"
    . " {$buyerService['paytype']}, {$buyerService['domainstatus']})");

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

$terminate = localAPI('ModuleTerminate', ['serviceid' => $buyerServiceId]);
if (($terminate['result'] ?? '') !== 'success') {
    bad('ModuleTerminate failed: ' . json_encode($terminate));
    finish();
}
ok("buyer service #$buyerServiceId ModuleTerminate called, relayed through the connector to the Hub");

$buyerStatus = one($db, 'SELECT domainstatus FROM tblhosting WHERE id=?', [$buyerServiceId])['domainstatus'] ?? '?';
if ($buyerStatus === 'Terminated') {
    ok("buyer service #$buyerServiceId Terminated");
} else {
    bad("buyer service #$buyerServiceId not Terminated (status: $buyerStatus)");
}

$resellerStatus = one($db, 'SELECT domainstatus FROM tblhosting WHERE id=?', [$resellerServiceId])['domainstatus'] ?? '?';
if ($resellerStatus === 'Terminated') {
    ok("reseller service #$resellerServiceId Terminated (access token released)");
} else {
    bad("reseller service #$resellerServiceId not Terminated (status: $resellerStatus)");
}

finish();
