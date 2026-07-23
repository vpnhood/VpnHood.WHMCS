<?php
/**
 * renew.test.php — renew the buyer's active recurring partner service.
 *
 * Runs ON the dev server (uploaded by renew.test.sh, alongside lib/common.php),
 * TWICE, in two stages (renew.test.sh orchestrates this — PHP-CLI on this box
 * has exec()/shell_exec()/proc_open() etc. all disabled, so the shell command
 * in between has to come from the SSH session, not from inside this script):
 *
 *   STAGE=force  (RENEW_STAGE=force) — find the buyer's Active recurring
 *     partner-type service and the reseller's upstream service, then force
 *     both due today via the official UpdateClientProduct API (simulating "a
 *     month has passed" — there is no real customer action for this step, so
 *     it's the one place this suite does not mirror client-area behavior;
 *     agreed as acceptable). Nothing about credit or dates is destructive yet.
 *
 *   [renew.test.sh runs WHMCS's own daily "Generate Invoices" automation task
 *    directly over SSH — crons/cron.php do --CreateInvoices — so both the
 *    buyer's and the reseller's renewal invoices are generated exactly as
 *    production would. This sweeps every due service on the install; fine
 *    here, every client on this dev WHMCS is a test account.]
 *
 *   STAGE=finish (default) — re-finds the same two services (no state needs
 *     to survive between stages: nothing consumes credit or changes dates in
 *     between), confirms both renewal invoices were generated, pays the
 *     buyer's from the buyer's own credit — which is what makes WHMCS itself
 *     advance nextduedate and fire the module's _Renew hook, relaying to the
 *     Hub's renew action, which settles the reseller's renewal invoice from
 *     the reseller's credit — then asserts both sides.
 *
 * Never runs the buyer/reseller cleanup — that only ever happens in
 * purchase-order.test.sh. Requires the buyer to currently hold an Active,
 * recurring, partner-type service (run purchase-order.test.sh with the
 * Recurring option first).
 *
 * Prints a JSON report per stage; exits non-zero if any assertion fails.
 */

require __DIR__ . '/lib/common.php';

const UPSTREAM_PRICE = 2.00;

$buyer = clientByEmail($db, BUYER_EMAIL);
$reseller = clientByEmail($db, RESELLER_EMAIL);
if (!$buyer || !$reseller) {
    bad('fixtures missing — run tests/bootstrap/init-skeleton.sh first');
    finish();
}

$buyerService = findBuyerPartnerService($db, (int)$buyer['id'], 'recurring', ['Active']);
$buyerServiceId = (int)$buyerService['hostingid'];
ok("buyer's active recurring partner service found: #$buyerServiceId ({$buyerService['productname']})");

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
if (!$resellerService || $resellerService['domainstatus'] !== 'Active') {
    bad("upstream reseller service not found/Active for order #$upstreamOrderId: " . json_encode($resellerService));
    finish();
}
$resellerServiceId = (int)$resellerService['id'];
ok("upstream order #$upstreamOrderId -> reseller service #$resellerServiceId Active");

$stage = getenv('RENEW_STAGE') ?: 'finish';
$today = date('Y-m-d');
// Forcing nextduedate to exactly today (the same day the service was likely
// just registered, in this test flow) makes WHMCS's invoice-generation cron
// silently no-op — it just re-reports the ORIGINAL purchase invoice instead
// of creating a new one, since the new "period" would start on the same day
// as the existing one. A few days in the past avoids that same-day collision
// and reliably produces a genuinely new renewal invoice (verified manually).
$forceDueDate = date('Y-m-d', strtotime('-5 days'));

if ($stage === 'force') {
    foreach ([$buyerServiceId => 'buyer', $resellerServiceId => 'reseller'] as $sid => $label) {
        $u = localAPI('UpdateClientProduct', ['serviceid' => $sid, 'nextduedate' => $forceDueDate]);
        if (($u['result'] ?? '') === 'success') {
            ok("$label service #$sid nextduedate forced to $forceDueDate");
        } else {
            bad("failed to force $label service #$sid due: " . json_encode($u));
        }
    }
    finish();
}

// ------------------------------------------------------------- STAGE=finish
function latestHostingInvoiceId(PDO $db, int $serviceId): int {
    $row = one(
        $db,
        "SELECT invoiceid FROM tblinvoiceitems WHERE type='Hosting' AND relid=? ORDER BY id DESC LIMIT 1",
        [$serviceId]
    );
    return (int)($row['invoiceid'] ?? 0);
}

$buyerCreditBefore = (float)one($db, 'SELECT credit FROM tblclients WHERE id=?', [$buyer['id']])['credit'];
$resellerCreditBefore = (float)one($db, 'SELECT credit FROM tblclients WHERE id=?', [$reseller['id']])['credit'];

$buyerInvoiceId = latestHostingInvoiceId($db, $buyerServiceId);
$resellerInvoiceId = latestHostingInvoiceId($db, $resellerServiceId);
$buyerInvoiceStatus = $buyerInvoiceId ? one($db, 'SELECT status FROM tblinvoices WHERE id=?', [$buyerInvoiceId])['status'] : null;
$resellerInvoiceStatus = $resellerInvoiceId ? one($db, 'SELECT status FROM tblinvoices WHERE id=?', [$resellerInvoiceId])['status'] : null;

if ($buyerInvoiceId && $buyerInvoiceStatus === 'Unpaid') {
    ok("buyer renewal invoice #$buyerInvoiceId generated");
} else {
    bad('buyer renewal invoice was not generated — did renew.test.sh run the cron/force stage first?');
    finish();
}
if ($resellerInvoiceId && $resellerInvoiceStatus === 'Unpaid') {
    ok("reseller renewal invoice #$resellerInvoiceId generated");
} else {
    bad('reseller renewal invoice was not generated — did renew.test.sh run the cron/force stage first?');
    finish();
}

// ------------------------------------------------- pay the buyer's invoice
// Paying it is what triggers WHMCS's standard renewal path: nextduedate
// advances and the module's _Renew hook fires, which relays to the Hub's
// renew action, which settles the reseller's own renewal invoice (above)
// from the reseller's credit.
$buyerApplied = payInvoiceFromCredit($db, $buyerInvoiceId, (int)$buyer['id']);
$buyerInvoiceStatusAfter = one($db, 'SELECT status FROM tblinvoices WHERE id=?', [$buyerInvoiceId])['status'] ?? '?';
if ($buyerInvoiceStatusAfter === 'Paid') {
    ok("buyer renewal invoice #$buyerInvoiceId paid from credit ($buyerApplied USD)");
} else {
    bad("buyer renewal invoice not Paid after applying credit (status: $buyerInvoiceStatusAfter, applied: $buyerApplied)");
    finish();
}

// ------------------------------------------------------------------- assert
$buyerCreditAfter = (float)one($db, 'SELECT credit FROM tblclients WHERE id=?', [$buyer['id']])['credit'];
$buyerSpent = round($buyerCreditBefore - $buyerCreditAfter, 2);
$buyerPrice = (float)one(
    $db,
    "SELECT monthly FROM tblpricing WHERE type='product' AND currency=1 AND relid=?",
    [$buyerService['packageid']]
)['monthly'];
if (abs($buyerSpent - $buyerPrice) < 0.001) {
    ok("buyer credit debited $buyerSpent USD ($buyerCreditBefore → $buyerCreditAfter)");
} else {
    bad("buyer credit debit wrong: spent $buyerSpent, expected $buyerPrice");
}

$buyerDueAfter = one($db, 'SELECT nextduedate FROM tblhosting WHERE id=?', [$buyerServiceId])['nextduedate'];
if ($buyerDueAfter > $today) {
    ok("buyer nextduedate advanced ($forceDueDate → $buyerDueAfter)");
} else {
    bad("buyer nextduedate did not advance (still $buyerDueAfter)");
}

$resellerInvoiceStatusAfter = one($db, 'SELECT status FROM tblinvoices WHERE id=?', [$resellerInvoiceId])['status'] ?? '?';
if ($resellerInvoiceStatusAfter === 'Paid') {
    ok("reseller renewal invoice #$resellerInvoiceId settled by the Hub's renew action");
} else {
    bad("reseller renewal invoice not Paid — the Hub renew relay did not settle it (status: $resellerInvoiceStatusAfter)");
}

$resellerCreditAfter = (float)one($db, 'SELECT credit FROM tblclients WHERE id=?', [$reseller['id']])['credit'];
$resellerSpent = round($resellerCreditBefore - $resellerCreditAfter, 2);
if (abs($resellerSpent - UPSTREAM_PRICE) < 0.001) {
    ok("reseller credit debited $resellerSpent USD ($resellerCreditBefore → $resellerCreditAfter)");
} else {
    bad("reseller credit debit wrong: spent $resellerSpent, expected " . UPSTREAM_PRICE);
}

$resellerDueAfter = one($db, 'SELECT nextduedate FROM tblhosting WHERE id=?', [$resellerServiceId])['nextduedate'];
if ($resellerDueAfter > $today) {
    ok("reseller nextduedate advanced ($forceDueDate → $resellerDueAfter)");
} else {
    bad("reseller nextduedate did not advance (still $resellerDueAfter)");
}

finish();
