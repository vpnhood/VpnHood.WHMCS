<?php
/**
 * refund-memory.test.php — the refund memory and its warning (includes/hooks/vpnhood-refund-memory.php)
 * on the dev WHMCS: a full website refund is remembered once per address, a partial one and a
 * store one are not, the warning shows on another Paid invoice of the same address and not on
 * the refunded invoice itself (nor on a store invoice), and a row past 24 months is pruned.
 *
 * Runs ON the dev server (uploaded by refund-memory.test.sh with lib/common.php). Places no
 * order: the invoices are standalone (CreateInvoice), paid through AddInvoicePayment, and
 * refunded with the ledger row WHMCS's own Refund action writes (refundInvoice in lib/common.php);
 * two of them are then set Refunded through UpdateInvoice, so the real InvoiceRefunded hook runs
 * too. The hook functions are otherwise called by name. The buyer's memory row, the invoices and
 * their ledger rows are removed at the end.
 */

require __DIR__ . '/lib/common.php';
require_once WEBROOT . '/includes/hooks/vpnhoodstore-refund-terminate.php';
require_once WEBROOT . '/includes/hooks/vpnhood-refund-memory.php';

use WHMCS\Database\Capsule;

$buyer = clientByEmail($db, BUYER_EMAIL);
if (!$buyer) {
    bad('fixtures missing — run tests/bootstrap/init-skeleton.sh first');
    finish();
}
$buyerId = (int) $buyer['id'];
$hash = vpnhood_refund_memory_hash(BUYER_EMAIL);
$marker = 'rm' . time();
$invoices = [];

/** A Paid standalone invoice on the given gateway, carrying the payment row a refund reverses. */
function paidInvoice(int $clientId, string $gateway, string $tag): int
{
    global $invoices, $marker;
    $created = localAPI('CreateInvoice', [
        'userid' => $clientId, 'status' => 'Unpaid', 'sendinvoice' => false, 'paymentmethod' => $gateway,
        'date' => date('Y-m-d'), 'duedate' => date('Y-m-d'),
        'itemdescription1' => "vhtest refund memory $marker $tag", 'itemamount1' => 3.00, 'itemtaxed1' => 0,
    ]);
    $invoiceId = (int) ($created['invoiceid'] ?? 0);
    if ($invoiceId <= 0) {
        throw new RuntimeException('CreateInvoice failed: ' . json_encode($created));
    }
    $invoices[] = $invoiceId;
    $paid = localAPI('AddInvoicePayment', [
        'invoiceid' => $invoiceId, 'transid' => "vhtest-$marker-$tag", 'gateway' => $gateway, 'amount' => 3.00, 'noemail' => true,
    ]);
    if (($paid['result'] ?? '') !== 'success') {
        throw new RuntimeException('AddInvoicePayment failed: ' . json_encode($paid));
    }
    return $invoiceId;
}

function memoryRows(string $hash): array
{
    return Capsule::table('mod_vpnhood_refund_memory')->where('email_hash', $hash)->get()->map(fn ($r) => (array) $r)->all();
}

function setRefundedAt(string $hash, string $date): void
{
    Capsule::table('mod_vpnhood_refund_memory')->where('email_hash', $hash)->update(['refunded_at' => $date]);
}

function warning(int $invoiceId): string
{
    return vpnhood_refund_memory_warning(['invoiceid' => $invoiceId]);
}

try {
    // -- the folding: one mailbox, one hash -----------------------------------
    vpnhood_refund_memory_hash(' Test.Buyer+promo@GMAIL.com ') === vpnhood_refund_memory_hash('testbuyer@googlemail.com')
        ? ok('case, space, a +tag, Gmail dots and googlemail.com fold into one hash')
        : bad('two spellings of one Gmail mailbox hash differently');
    vpnhood_refund_memory_hash('test.buyer@vpnhood.com') !== vpnhood_refund_memory_hash('testbuyer@vpnhood.com')
        ? ok('dots stay significant outside Gmail')
        : bad('dots were folded on a non-Gmail domain');

    vpnhood_refund_memory_prepare();
    Capsule::table('mod_vpnhood_refund_memory')->where('email_hash', $hash)->delete(); // earlier dev refunds of the buyer

    // -- a full website refund is remembered, once ---------------------------
    $full = paidInvoice($buyerId, 'banktransfer', 'full');
    refundInvoice($db, $full, 0.5);
    vpnhood_refund_memory_record(['invoiceid' => $full]);
    memoryRows($hash) === []
        ? ok('half of the invoice given back: nothing remembered yet')
        : bad('a partial refund was remembered');
    refundInvoice($db, $full, 1.0);
    vpnhood_refund_memory_record(['invoiceid' => $full]); // WHMCS fires InvoiceRefunded per refund transaction
    $rows = memoryRows($hash);
    (count($rows) === 1 && strtotime((string) $rows[0]['refunded_at']) > time() - 300)
        ? ok('refunds adding up to the total remember the address with the date')
        : bad('full refund not remembered: ' . json_encode($rows));
    $updated = localAPI('UpdateInvoice', ['invoiceid' => $full, 'status' => 'Refunded']); // the real hook runs here
    (($updated['result'] ?? '') === 'success' && count(memoryRows($hash)) === 1)
        ? ok('the invoice marked Refunded (the real InvoiceRefunded hook) keeps one row per address')
        : bad('a second row, or the update failed: ' . json_encode([$updated, memoryRows($hash)]));

    // -- a partial refund and a store refund change nothing -------------------
    setRefundedAt($hash, '2026-01-01 00:00:00');
    $partial = paidInvoice($buyerId, 'banktransfer', 'partial');
    refundInvoice($db, $partial, 0.5);
    vpnhood_refund_memory_record(['invoiceid' => $partial]);
    $store = paidInvoice($buyerId, 'vpnhoodiappay', 'store');
    refundInvoice($db, $store, 1.0);
    vpnhood_refund_memory_record(['invoiceid' => $store]);
    localAPI('UpdateInvoice', ['invoiceid' => $store, 'status' => 'Refunded']);
    $rows = memoryRows($hash);
    (count($rows) === 1 && (string) $rows[0]['refunded_at'] === '2026-01-01 00:00:00')
        ? ok('a partial refund and a full store refund leave the memory as it was')
        : bad('a partial or store refund touched the memory: ' . json_encode($rows));

    // -- the warning ----------------------------------------------------------
    $html = warning($partial);
    (str_contains($html, 'Refunded before') && str_contains($html, fromMySQLDate('2026-01-01 00:00:00')))
        ? ok('a Paid website invoice of the remembered address warns, with the date')
        : bad('no warning on the Paid invoice: ' . $html);
    warning($full) === ''
        ? ok('the refunded invoice itself does not warn')
        : bad('a warning on the Refunded invoice');
    $storePaid = paidInvoice($buyerId, 'vpnhoodiappay', 'store-paid');
    warning($storePaid) === ''
        ? ok('a store invoice never warns')
        : bad('a warning on a store invoice');
    warning(0) === '' ? ok('no invoice, no warning') : bad('a warning without an invoice');

    // -- the prune ------------------------------------------------------------
    setRefundedAt($hash, date('Y-m-d H:i:s', strtotime('-25 months')));
    vpnhood_refund_memory_prune();
    memoryRows($hash) === []
        ? ok('a row older than 24 months is pruned')
        : bad('the old row survived the prune');
    warning($partial) === ''
        ? ok('and the warning is gone with it')
        : bad('a warning after the prune');
} catch (\Throwable $e) {
    bad('exception: ' . $e->getMessage());
} finally {
    Capsule::table('mod_vpnhood_refund_memory')->where('email_hash', $hash)->delete();
    foreach ($invoices as $invoiceId) {
        $db->prepare('DELETE FROM tblaccounts WHERE invoiceid=?')->execute([$invoiceId]);
        $db->prepare('DELETE FROM tblinvoiceitems WHERE invoiceid=?')->execute([$invoiceId]);
        $db->prepare('DELETE FROM tblinvoices WHERE id=?')->execute([$invoiceId]);
    }
    ok('cleanup done (' . count($invoices) . " invoices and the buyer's memory row removed)");
}

finish();
