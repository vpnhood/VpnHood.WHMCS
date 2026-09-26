<?php
/**
 * refund-policy.test.php — unit tests of RefundPolicy, the rules a partner refund is decided by
 * (docs/ARCHITECTURE.md, "Service actions and refunds"). Pure PHP, no WHMCS and no database: run.sh runs it with
 * the dev box's PHP, since there is none locally.
 *
 * Usage: php refund-policy.test.php <path of RefundPolicy.php>
 */

error_reporting(E_ALL);
date_default_timezone_set('UTC');
require $argv[1] ?? __DIR__ . '/../../modules/addons/vpnhoodpartnerhub/lib/RefundPolicy.php';

use WHMCS\Module\Addon\VpnHoodPartnerHub\RefundPolicy;

const DAY = 86400;
const PAID = '2026-09-20 10:00:00';

$pass = 0;
$fail = 0;

function check(bool $condition, string $message, $detail = null): void
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS $message\n";
        return;
    }
    $fail++;
    echo "FAIL $message" . ($detail !== null ? ' — ' . json_encode($detail) : '') . "\n";
}

/** A refundable order: bought through the Hub, paid 2.00 on PAID, one line for its service. */
function facts(array $purchase = [], array $invoice = [], array $other = []): array
{
    return array_replace([
        'orderId'         => 1475,
        'serviceId'       => 1874,
        'partnerClientId' => 22,
        'purchase'        => array_replace(['state' => 'delivered', 'clientId' => 22, 'serviceId' => 1874, 'invoiceId' => 1852], $purchase),
        'invoice'         => array_replace(['userId' => 22, 'status' => 'Paid', 'total' => 2.0, 'datePaid' => PAID], $invoice),
        'items'           => [['type' => 'Hosting', 'relid' => 1874]],
        'refundBooked'    => false,
        'laterInvoiceIds' => [],
    ], $other);
}

function refusal(array $facts, int $days = 3, ?int $now = null): ?array
{
    return RefundPolicy::refusal($facts, $days, $now ?? strtotime(PAID) + DAY);
}

function refused(?array $refusal, string $code, string $needle, string $case): void
{
    check(
        $refusal !== null && $refusal['status'] === 409 && $refusal['code'] === $code && strpos($refusal['message'], $needle) !== false,
        "$case -> $code",
        $refusal
    );
}

// -- The PartnerRefundDays setting ---------------------------------------------------------

foreach ([
    [null, 7, 'never saved'], ['', 7, 'blank'], ['  ', 7, 'spaces'], ['0', 0, '0'], ['3', 3, '3'], ['10', 10, '10'],
    [' 7 ', 7, 'padded 7'], ['-1', 0, 'negative'], ['2.5', 0, 'a fraction'], ['abc', 0, 'text'],
] as [$setting, $days, $case]) {
    check(RefundPolicy::days($setting) === $days, "days($case) = $days", RefundPolicy::days($setting));
}

check(
    RefundPolicy::creditDescription(1475, 1852) === 'Partner Hub refund of order #1475 (invoice #1852)',
    'the credit row names the order and the invoice',
    RefundPolicy::creditDescription(1475, 1852)
);

// -- Refundable ----------------------------------------------------------------------------

check(refusal(facts()) === null, 'a delivered order, paid a day ago, is refundable', refusal(facts()));
check(refusal(facts([], [], ['items' => [['type' => 'Hosting', 'relid' => 1874], ['type' => 'PromoHosting', 'relid' => 1874]]])) === null,
    'a promotion line on the same service is still the key itself');
check(refusal(facts([], [], []), 3, strtotime(PAID) + 3 * DAY) === null, 'refundable at the last second of the window');
check(refusal(facts(), 10, strtotime(PAID) + 9 * DAY) === null, 'a 10-day window is honoured');

// -- Not bought through the Hub by this partner --------------------------------------------

refused(refusal(facts([], [], ['purchase' => null])), 'not_refundable', 'not bought through the Partner Hub', 'no purchase record');
refused(refusal(facts(['clientId' => 23])), 'not_refundable', 'not bought through the Partner Hub', 'a purchase of another client');
refused(refusal(facts(['serviceId' => 1875])), 'not_refundable', 'not bought through the Partner Hub', 'a purchase of another service');
refused(refusal(facts(['state' => 'needs_reconciliation'])), 'not_refundable', 'never finished', 'an unfinished purchase');
refused(refusal(facts(['state' => 'rolled_back'])), 'not_refundable', 'never finished', 'a rolled-back purchase');

// -- Nothing paid to refund ------------------------------------------------------------------

refused(refusal(facts(['invoiceId' => 0])), 'not_refundable', 'has no invoice', 'a free order (no invoice)');
refused(refusal(facts([], [], ['invoice' => null])), 'not_refundable', 'has no invoice', 'a deleted invoice');
refused(refusal(facts([], ['userId' => 23])), 'not_refundable', 'does not belong to this account', 'an invoice of another client');
refused(refusal(facts([], ['status' => 'Unpaid'])), 'not_refundable', 'is Unpaid', 'an unpaid invoice');
refused(refusal(facts([], ['status' => 'Refunded'])), 'not_refundable', 'is Refunded', 'an invoice refunded by hand');
refused(refusal(facts([], ['status' => 'Cancelled'])), 'not_refundable', 'is Cancelled', 'a cancelled invoice');
refused(refusal(facts([], ['total' => 0.0])), 'not_refundable', 'with a total of 0', 'a zero total');
refused(refusal(facts([], ['datePaid' => '0000-00-00 00:00:00'])), 'not_refundable', 'has no payment date', 'no payment date');

// -- The window ------------------------------------------------------------------------------

refused(refusal(facts(), 0), 'refund_window_closed', 'turned off', 'PartnerRefundDays = 0');
refused(refusal(facts(), 3, strtotime(PAID) + 3 * DAY + 1), 'refund_window_closed', 'closed on 2026-09-23 10:00',
    'one second after the 3-day window');
refused(refusal(facts(), 10, strtotime(PAID) + 11 * DAY), 'refund_window_closed', '10 day(s) after its payment',
    'after a 10-day window');

// -- What the invoice bills, and what came after -------------------------------------------

refused(refusal(facts([], [], ['items' => [['type' => 'Hosting', 'relid' => 1874], ['type' => 'Hosting', 'relid' => 1875]]])),
    'not_refundable', 'bills more than this key', 'an invoice that also bills another service');
refused(refusal(facts([], [], ['items' => [['type' => 'Hosting', 'relid' => 1874], ['type' => '', 'relid' => 0]]])),
    'not_refundable', 'bills more than this key', 'an invoice with a line added by hand');
refused(refusal(facts([], [], ['items' => [['type' => 'Addon', 'relid' => 1874]]])),
    'not_refundable', 'bills more than this key', 'an addon line with the same id');
refused(refusal(facts([], [], ['refundBooked' => true])), 'not_refundable', 'already recorded', 'a refund booked by hand');
refused(refusal(facts([], [], ['laterInvoiceIds' => [1900, 1901]])), 'not_refundable', 'later invoice (#1900, #1901)',
    'a key with later invoices (a renewal)');

// -- Precedence: the partner reads the reason that matters most ----------------------------

refused(refusal(facts(['state' => 'needs_reconciliation']), 0), 'not_refundable', 'never finished',
    'an unfinished purchase is named before a closed window');
refused(refusal(facts([], [], ['laterInvoiceIds' => [1900]]), 3, strtotime(PAID) + 5 * DAY), 'refund_window_closed', 'closed on',
    'a closed window is named before a later invoice');

echo "== $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
