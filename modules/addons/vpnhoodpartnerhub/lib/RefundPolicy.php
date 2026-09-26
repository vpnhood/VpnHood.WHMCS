<?php

namespace WHMCS\Module\Addon\VpnHoodPartnerHub;

/**
 * Whether a partner may refund an order through the API (docs/ARCHITECTURE.md, "Service actions
 * and refunds"), decided from facts the caller reads. No database and no WHMCS here, so tests/unit checks
 * every rule directly.
 */
final class RefundPolicy
{
    public const DEFAULT_DAYS = 3;

    /** Invoice lines that bill the service itself (its price, and a promotion on it). */
    private const SERVICE_LINE_TYPES = ['Hosting', 'PromoHosting'];

    /**
     * The PartnerRefundDays setting: blank or never saved means the default, a whole number is
     * that many days, and anything else turns refunds off rather than guessing.
     */
    public static function days(?string $setting): int
    {
        $setting = trim((string) $setting);
        if ($setting === '') {
            return self::DEFAULT_DAYS;
        }
        return ctype_digit($setting) ? (int) $setting : 0;
    }

    /** The credit row a refund writes; finding it is how a repeat knows the refund is done. */
    public static function creditDescription(int $orderId, int $invoiceId): string
    {
        return "Partner Hub refund of order #{$orderId} (invoice #{$invoiceId})";
    }

    /**
     * Why the order cannot be refunded through the API, or null when it can.
     *
     * @param array{
     *   orderId: int,
     *   serviceId: int,
     *   partnerClientId: int,
     *   purchase: ?array{state: string, clientId: int, serviceId: int, invoiceId: int},
     *   invoice: ?array{userId: int, status: string, total: float, datePaid: string},
     *   items: list<array{type: string, relid: int}>,
     *   refundBooked: bool,
     *   laterInvoiceIds: list<int>
     * } $facts
     * @return array{status: int, code: string, message: string}|null
     */
    public static function refusal(array $facts, int $days, int $now): ?array
    {
        $orderId = $facts['orderId'];
        $byHand = "Contact VpnHood support to refund order #{$orderId} by hand.";

        $purchase = $facts['purchase'];
        if ($purchase === null || $purchase['clientId'] !== $facts['partnerClientId'] || $purchase['serviceId'] !== $facts['serviceId']) {
            return self::notRefundable("Order #{$orderId} was not bought through the Partner Hub on this account, so it cannot be refunded here. {$byHand}");
        }
        if ($purchase['state'] !== 'delivered') {
            return self::notRefundable("Order #{$orderId} never finished its purchase, so it cannot be refunded here. {$byHand}");
        }

        $invoiceId = $purchase['invoiceId'];
        $invoice = $facts['invoice'];
        if ($invoiceId <= 0 || $invoice === null) {
            return self::notRefundable("Order #{$orderId} has no invoice, so there is nothing to refund.");
        }
        if ($invoice['userId'] !== $facts['partnerClientId']) {
            return self::notRefundable("Invoice #{$invoiceId} of order #{$orderId} does not belong to this account. {$byHand}");
        }
        if ($invoice['status'] !== 'Paid' || $invoice['total'] < 0.005) {
            return self::notRefundable("Order #{$orderId} has nothing to refund: its invoice #{$invoiceId} is {$invoice['status']}"
                . ($invoice['total'] < 0.005 ? ' with a total of 0' : '') . '.');
        }

        if ($days <= 0) {
            return self::windowClosed("Refunds through the Partner Hub are turned off. Terminate order #{$orderId} to end its key; its credit is not returned.");
        }
        $paidAt = strtotime($invoice['datePaid']);
        if ($paidAt === false || $paidAt <= 0) {
            return self::notRefundable("Invoice #{$invoiceId} of order #{$orderId} has no payment date. {$byHand}");
        }
        $closesAt = $paidAt + $days * 86400;
        if ($now > $closesAt) {
            return self::windowClosed("The refund window of order #{$orderId} closed on " . date('Y-m-d H:i', $closesAt)
                . ", {$days} day(s) after its payment. Terminate the order to end its key; its credit is not returned.");
        }

        foreach ($facts['items'] as $item) {
            if ($item['relid'] !== $facts['serviceId'] || !in_array($item['type'], self::SERVICE_LINE_TYPES, true)) {
                return self::notRefundable("Invoice #{$invoiceId} of order #{$orderId} bills more than this key. {$byHand}");
            }
        }
        if ($facts['refundBooked']) {
            return self::notRefundable("A refund is already recorded on invoice #{$invoiceId} of order #{$orderId}. Contact VpnHood support to finish it by hand.");
        }
        if ($facts['laterInvoiceIds'] !== []) {
            return self::notRefundable("Order #{$orderId} has a later invoice (#" . implode(', #', $facts['laterInvoiceIds'])
                . '), such as a renewal. Only a key\'s first purchase is refundable, before anything else is invoiced for it.'
                . ' Terminate the order to end its key, or contact VpnHood support.');
        }
        return null;
    }

    private static function notRefundable(string $message): array
    {
        return ['status' => 409, 'code' => 'not_refundable', 'message' => $message];
    }

    private static function windowClosed(string $message): array
    {
        return ['status' => 409, 'code' => 'refund_window_closed', 'message' => $message];
    }
}
