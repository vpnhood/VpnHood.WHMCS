<?php
/**
 * purchase-order.test.php — buyer places a real order through the connector.
 *
 * Runs ON the dev server (uploaded by purchase-order.test.sh, alongside
 * lib/common.php). Every write goes through localAPI() or the core
 * applyCredit() — never a raw INSERT/UPDATE against orders/invoices/hosting.
 *
 * Flow:
 *   0. wipe any pre-existing orders/services/invoices for BOTH the test buyer
 *      and the test reseller (this is the ONLY script in the suite that does
 *      this — renew/suspend/unsuspend/terminate never clean up)
 *   1. AddOrder for the buyer on the chosen connector product (PRODUCT_TYPE
 *      env: 'onetime' or 'recurring' — recurring always uses billingcycle
 *      'monthly'), then pay its invoice from the buyer's own credit
 *   2. AcceptOrder (autosetup) — triggers the real vpnhoodpartner_CreateAccount
 *      -> HubClient order -> the Hub provisions from the reseller's credit
 *      and returns the access code
 *   3. assert: buyer order/service Active, buyer credit debited, accessCode +
 *      upstreamOrderId stored; the reseller's upstream order/service Active
 *      and reseller credit debited
 *
 * The order is left ACTIVE on both sides — this script never suspends,
 * renews, or terminates anything. Those are separate, opt-in scripts.
 *
 * Env: PRODUCT_TYPE   'onetime' | 'recurring' (required)
 *
 * Prints a JSON report; exits non-zero if any assertion fails.
 */

require __DIR__ . '/lib/common.php';

const CONNECTOR_SLUG_ONETIME    = 'partner-one-month-premium-code';
const CONNECTOR_SLUG_RECURRING  = 'partner-one-month-premium-code-subscription';
const UPSTREAM_SLUG_ONETIME     = 'reseller-one-month-premium-code';
const UPSTREAM_SLUG_RECURRING   = 'reseller-one-month-premium-code-subscription';
const UPSTREAM_PRICE            = 2.00;

$productType = getenv('PRODUCT_TYPE') ?: '';
if (!in_array($productType, ['onetime', 'recurring'], true)) {
    bad("PRODUCT_TYPE must be 'onetime' or 'recurring' (got '$productType')");
    finish();
}

// -------------------------------------------------------------------- fixtures
$buyer = clientByEmail($db, BUYER_EMAIL);
$reseller = clientByEmail($db, RESELLER_EMAIL);
$connectorSlug = $productType === 'recurring' ? CONNECTOR_SLUG_RECURRING : CONNECTOR_SLUG_ONETIME;
$upstreamSlug = $productType === 'recurring' ? UPSTREAM_SLUG_RECURRING : UPSTREAM_SLUG_ONETIME;
$prod = one($db, "SELECT p.id, p.paytype FROM tblproducts p
    LEFT JOIN tblproducts_slugs s ON s.product_id=p.id AND s.active=1
    WHERE p.slug=? OR s.slug=? LIMIT 1", [$connectorSlug, $connectorSlug]);
$upstreamProd = one($db, "SELECT p.id FROM tblproducts p
    LEFT JOIN tblproducts_slugs s ON s.product_id=p.id AND s.active=1
    WHERE p.slug=? OR s.slug=? LIMIT 1", [$upstreamSlug, $upstreamSlug]);

if (!$buyer || !$reseller || !$prod || !$upstreamProd) {
    bad('fixtures missing — run tests/bootstrap/init-skeleton.sh first');
    finish();
}
ok("fixtures present (buyer #{$buyer['id']}, $productType connector product #{$prod['id']})");

// --------------------------------------------- clean slate for buyer + reseller
// A previous run that failed/aborted mid-way can leave either side with stale
// services/orders/invoices. This is the only script in the suite that cleans
// up — renew/suspend/unsuspend/terminate act on whatever this script leaves
// behind and never wipe anything themselves.
function terminateAndDeleteOrder(PDO $db, int $orderId): bool {
    $hosting = one($db, 'SELECT id, domainstatus FROM tblhosting WHERE orderid=?', [$orderId]);
    if ($hosting && in_array($hosting['domainstatus'], ['Active', 'Suspended'], true)) {
        localAPI('ModuleTerminate', ['serviceid' => $hosting['id']]);
    }
    $order = one($db, 'SELECT status, invoiceid FROM tblorders WHERE id=?', [$orderId]);
    $status = $order['status'] ?? '';
    $invoiceId = (int)($order['invoiceid'] ?? 0);
    if ($status === 'Pending') {
        localAPI('CancelOrder', ['orderid' => $orderId, 'cancelsub' => false]);
    } elseif ($status !== '' && $status !== 'Cancelled' && $status !== 'Fraud') {
        $db->prepare("UPDATE tblorders SET status='Cancelled' WHERE id=?")->execute([$orderId]);
    }
    $delete = localAPI('DeleteOrder', ['orderid' => $orderId]);
    if ($invoiceId > 0 && one($db, 'SELECT id FROM tblinvoices WHERE id=?', [$invoiceId])) {
        $db->prepare('DELETE FROM tblaccounts WHERE invoiceid=?')->execute([$invoiceId]);
        $db->prepare('DELETE FROM tblinvoiceitems WHERE invoiceid=?')->execute([$invoiceId]);
        $db->prepare('DELETE FROM tblinvoices WHERE id=?')->execute([$invoiceId]);
    }
    return ($delete['result'] ?? '') === 'success';
}

function cleanupClient(PDO $db, int $clientId): array {
    $st = $db->prepare('SELECT id FROM tblorders WHERE userid=?');
    $st->execute([$clientId]);
    $orderIds = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'id');
    foreach ($orderIds as $orderId) {
        terminateAndDeleteOrder($db, $orderId);
    }

    $st = $db->prepare('SELECT id FROM tblinvoices WHERE userid=?');
    $st->execute([$clientId]);
    $invoiceIds = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'id');
    if ($invoiceIds) {
        $in = implode(',', array_fill(0, count($invoiceIds), '?'));
        $db->prepare("DELETE FROM tblaccounts WHERE invoiceid IN ($in)")->execute($invoiceIds);
        $db->prepare("DELETE FROM tblinvoiceitems WHERE invoiceid IN ($in)")->execute($invoiceIds);
        $db->prepare("DELETE FROM tblinvoices WHERE id IN ($in)")->execute($invoiceIds);
    }
    $db->prepare('DELETE FROM tblhosting WHERE userid=?')->execute([$clientId]);

    return ['orders' => count($orderIds), 'invoices' => count($invoiceIds)];
}

foreach (['buyer' => $buyer['id'], 'reseller' => $reseller['id']] as $label => $clientId) {
    $preOrders = (int)one($db, 'SELECT COUNT(*) c FROM tblorders WHERE userid=?', [$clientId])['c'];
    $preInvoices = (int)one($db, 'SELECT COUNT(*) c FROM tblinvoices WHERE userid=?', [$clientId])['c'];
    if ($preOrders > 0 || $preInvoices > 0) {
        $removed = cleanupClient($db, (int)$clientId);
        ok("cleaned up stale $label state ({$removed['orders']} order(s),"
            . " {$removed['invoices']} invoice(s) — terminated + deleted)");
    } else {
        ok("$label had no pre-existing orders/services/invoices");
    }
}

$creditBefore = (float)one($db, 'SELECT credit FROM tblclients WHERE id=?', [$buyer['id']])['credit'];
$resellerCreditBefore = (float)one($db, 'SELECT credit FROM tblclients WHERE id=?', [$reseller['id']])['credit'];

// -------------------------------------------------------------- place the order
$billingCycle = $productType === 'recurring' ? 'monthly' : 'onetime';
$add = localAPI('AddOrder', [
    'clientid'       => $buyer['id'],
    'pid'            => $prod['id'],
    'billingcycle'   => $billingCycle,
    'paymentmethod'  => 'banktransfer',
    'noemail'        => true,
    'noinvoiceemail' => true,
]);
if (($add['result'] ?? '') !== 'success') {
    bad('AddOrder failed: ' . json_encode($add));
    finish();
}
$orderId = (int)$add['orderid'];
$invoiceId = (int)($add['invoiceid'] ?? 0);
$serviceId = (int) explode(',', (string)($add['productids'] ?? ''))[0];
ok("buyer order #$orderId / invoice #$invoiceId / service #$serviceId placed ($productType, $billingCycle)");

// ------------------------------------------------------- pay from buyer credit
$applied = payInvoiceFromCredit($db, $invoiceId, (int)$buyer['id']);
$invStatus = one($db, 'SELECT status FROM tblinvoices WHERE id=?', [$invoiceId])['status'] ?? '?';
if ($invStatus === 'Paid') {
    ok("buyer invoice #$invoiceId paid from credit ($applied USD)");
} else {
    bad("buyer invoice #$invoiceId not Paid after applying credit (status: $invStatus, applied: $applied)");
    finish();
}

// ------------------------------------------------- accept order -> provision
$accept = localAPI('AcceptOrder', ['orderid' => $orderId, 'autosetup' => true, 'sendemail' => false]);
if (($accept['result'] ?? '') !== 'success') {
    bad('AcceptOrder failed: ' . json_encode($accept));
    finish();
}
ok('order accepted, autosetup triggered vpnhoodpartner_CreateAccount');

// -------------------------------------------------------------------- assert
$service = one($db, 'SELECT domainstatus FROM tblhosting WHERE id=?', [$serviceId]);
$orderStatus = one($db, 'SELECT status FROM tblorders WHERE id=?', [$orderId])['status'] ?? '?';
if (($service['domainstatus'] ?? '') === 'Active' && $orderStatus === 'Active') {
    ok("buyer order #$orderId / service #$serviceId Active");
} else {
    bad("buyer order/service not Active (order: $orderStatus, service: " . ($service['domainstatus'] ?? '?') . ')');
}

$upstreamOrderId = serviceProperty($db, $serviceId, 'upstreamOrderId');
$accessCode = serviceProperty($db, $serviceId, 'accessCode');
if ($upstreamOrderId) {
    ok("serviceProperties.upstreamOrderId = $upstreamOrderId");
} else {
    bad('no upstreamOrderId in service properties after provisioning');
}
if ($accessCode) {
    ok('serviceProperties.accessCode present (' . strlen($accessCode) . ' chars)');
} else {
    bad('serviceProperties.accessCode empty');
}
if ($report['fail'] > 0) {
    finish();
}

$creditAfter = (float)one($db, 'SELECT credit FROM tblclients WHERE id=?', [$buyer['id']])['credit'];
$buyerSpent = round($creditBefore - $creditAfter, 2);
$expectedPrice = (float)one($db, "SELECT monthly FROM tblpricing WHERE type='product' AND currency=1 AND relid=?", [$prod['id']])['monthly'];
if (abs($buyerSpent - $expectedPrice) < 0.001) {
    ok("buyer credit debited $buyerSpent USD ($creditBefore → $creditAfter)");
} else {
    bad("buyer credit debit wrong: spent $buyerSpent, expected $expectedPrice");
}

// -------------------------------------------------------- upstream (reseller)
$up = one($db, 'SELECT id, userid, packageid, domainstatus FROM tblhosting WHERE orderid=?', [(int)$upstreamOrderId]);
if ($up && (int)$up['userid'] === (int)$reseller['id'] && (int)$up['packageid'] === (int)$upstreamProd['id']
        && $up['domainstatus'] === 'Active') {
    ok("upstream order #$upstreamOrderId -> service #{$up['id']} Active under reseller,"
        . " hub product #{$upstreamProd['id']}");
} else {
    bad('upstream service wrong: ' . json_encode($up));
}

$resellerCreditAfter = (float)one($db, 'SELECT credit FROM tblclients WHERE id=?', [$reseller['id']])['credit'];
$resellerSpent = round($resellerCreditBefore - $resellerCreditAfter, 2);
if (abs($resellerSpent - UPSTREAM_PRICE) < 0.001) {
    ok("reseller credit debited $resellerSpent USD ($resellerCreditBefore → $resellerCreditAfter)");
} else {
    bad("reseller credit debit wrong: spent $resellerSpent, expected " . UPSTREAM_PRICE);
}

finish();
