<?php
/**
 * common.php — shared helpers for the connector lifecycle test scripts
 * (purchase-order, renew, suspend, unsuspend, terminate).
 *
 * Runs ON the dev server (uploaded alongside each *.test.php). Provides DB
 * access, a tiny assertion/report harness, and lookups for "the buyer's
 * current connector service" so each lifecycle script can find its target
 * without any script having to pass state to another.
 *
 * Every write in these scripts goes through localAPI() or the core
 * applyCredit() function — the same mechanisms WHMCS's own checkout/admin
 * UI use — never a raw INSERT/UPDATE against orders, invoices, or hosting.
 */

error_reporting(E_ALL);
const WEBROOT = '/home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html';

const BUYER_EMAIL    = 'test-buyer@vpnhood.com';
const RESELLER_EMAIL = 'test-reseller@vpnhood.com';

require_once WEBROOT . '/init.php';
require_once ROOTDIR . '/includes/invoicefunctions.php';

/** @var PDO $db */
$db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_username, $db_password);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$report = ['steps' => [], 'pass' => 0, 'fail' => 0];
function ok(string $msg): void  { global $report; $report['steps'][] = "PASS $msg"; $report['pass']++; }
function bad(string $msg): void { global $report; $report['steps'][] = "FAIL $msg"; $report['fail']++; }
function finish(): never {
    global $report;
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit($report['fail'] > 0 ? 1 : 0);
}

function one(PDO $db, string $sql, array $args = []): ?array {
    $st = $db->prepare($sql);
    $st->execute($args);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

function clientByEmail(PDO $db, string $email): ?array {
    return one($db, 'SELECT id, credit FROM tblclients WHERE email=?', [$email]);
}

/**
 * Read a WHMCS "service property" (the hidden per-product custom field
 * mechanism $service->serviceProperties uses) directly from the DB.
 * Read-only — never used to write state.
 */
function serviceProperty(PDO $db, int $hostingId, string $name): ?string {
    $st = $db->prepare(
        "SELECT v.value FROM tblcustomfieldsvalues v
         JOIN tblcustomfields f ON f.id = v.fieldid
         WHERE v.relid = ? AND f.type = 'product'
           AND LOWER(SUBSTRING_INDEX(f.fieldname, '|', 1)) = ?"
    );
    $st->execute([$hostingId, strtolower($name)]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

/**
 * Find the buyer's current service on a "partner" (vpnhoodpartner-backed)
 * product, optionally restricted to a payment type. Fails loudly (via
 * finish()) if none is found or more than one candidate exists, since every
 * lifecycle script other than purchase-order assumes exactly one.
 *
 * $status: restrict to a domainstatus (default 'Active' — the normal case for
 * every script except suspend, which also accepts an already-Suspended
 * service so it's re-runnable).
 */
function findBuyerPartnerService(PDO $db, int $buyerId, ?string $payType = null, array $statuses = ['Active']): array {
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $sql = "SELECT h.id AS hostingid, h.orderid, h.domainstatus, h.packageid,
                   p.paytype, p.name AS productname
            FROM tblhosting h
            JOIN tblproducts p ON p.id = h.packageid
            WHERE h.userid = ? AND p.servertype = 'vpnhoodpartner'
              AND h.domainstatus IN ($placeholders)";
    $args = array_merge([$buyerId], $statuses);
    if ($payType !== null) {
        $sql .= ' AND p.paytype = ?';
        $args[] = $payType;
    }
    $sql .= ' ORDER BY h.id DESC';

    $st = $db->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        $typeDesc = $payType ? "$payType " : '';
        bad("no {$typeDesc}partner-type service found for the buyer in status ["
            . implode(',', $statuses) . '] — run purchase-order.test.sh first');
        finish();
    }
    if (count($rows) > 1) {
        bad('more than one matching buyer service found (#' . implode(', #', array_column($rows, 'hostingid'))
            . ') — run terminate.test.sh to clear extras before continuing');
        finish();
    }
    return $rows[0];
}

/**
 * Pay an invoice from a client's own native WHMCS credit, capped at the
 * outstanding balance. Mirrors PartnerApiController::settleFromCredit() in
 * the Hub. Returns the amount actually applied (0 if nothing was due/available).
 *
 * This build's applyCredit() signature is (invoiceId, userId, amount, noEmail)
 * — it applies a SPECIFIC amount, never "as much as available".
 */
function payInvoiceFromCredit(PDO $db, int $invoiceId, int $clientId): float {
    $inv = one($db, 'SELECT total FROM tblinvoices WHERE id=?', [$invoiceId]);
    $paid = (float) (one($db, 'SELECT COALESCE(SUM(amountin),0) s FROM tblaccounts WHERE invoiceid=?', [$invoiceId])['s'] ?? 0);
    $balance = round((float)($inv['total'] ?? 0) - $paid, 2);
    if ($balance <= 0) {
        return 0.0;
    }
    $credit = (float) (one($db, 'SELECT credit FROM tblclients WHERE id=?', [$clientId])['credit'] ?? 0);
    $amount = min($balance, $credit);
    if ($amount <= 0) {
        return 0.0;
    }
    applyCredit($invoiceId, $clientId, $amount, true);
    return $amount;
}
