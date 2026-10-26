<?php

namespace WHMCS\Module\Addon\VpnHoodPartnerHub;

use WHMCS\Database\Capsule;

require_once __DIR__ . '/LocalApi.php';

/**
 * The low-credit email (README, "Low-credit alert"): a Hub payment that takes a partner's credit
 * from at or above the LowCreditThreshold setting to below it emails the partner once. Nothing is
 * stored: the payments after it start below the threshold and send nothing, until a top-up has
 * brought the credit back to it.
 */
final class LowCreditAlert
{
    public const TEMPLATE = 'VpnHood Partner Low Credit';
    public const DEFAULT_THRESHOLD = 100.0;

    /** The LowCreditThreshold setting: blank keeps the default, anything but an amount turns the alert off. */
    public static function threshold(?string $setting): float
    {
        $setting = trim((string) $setting);
        if ($setting === '') {
            return self::DEFAULT_THRESHOLD;
        }
        return is_numeric($setting) ? max(0.0, round((float) $setting, 2)) : 0.0;
    }

    /** Whether a payment from $before to $after takes the credit below $threshold; 0 is off. */
    public static function crosses(float $threshold, float $before, float $after): bool
    {
        $threshold = round($threshold, 2);
        return $threshold > 0 && round($before, 2) >= $threshold && round($after, 2) < $threshold;
    }

    /**
     * Called right after a Hub payment, under the client's credit lock. The email waits for the end
     * of the request, and under PHP-FPM for the partner's answer to have gone out: a slow mail
     * server must neither hold the lock nor push the answer past the web server's timeout, as a
     * keyless connector repeats a lost answer by buying again.
     */
    public static function afterPayment(int $clientId, float $before, float $after, float $threshold): void
    {
        if (self::crosses($threshold, $before, $after)) {
            register_shutdown_function(static function () use ($clientId, $after, $threshold): void {
                self::send($clientId, $after, $threshold);
            });
        }
    }

    /**
     * Activation's: the email in its current wording, as a General template an admin can edit; one
     * by that name is left alone. An install activated before the alert existed runs this by hand.
     */
    public static function createTemplate(): void
    {
        if (Capsule::table('tblemailtemplates')->where('name', self::TEMPLATE)->exists()) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        Capsule::table('tblemailtemplates')->insert([
            'type'          => 'general',
            'name'          => self::TEMPLATE,
            'subject'       => 'Your credit balance is low',
            'message'       => '<p>Dear {$client_name},</p>'
                . '<p>Your credit balance is now {$low_credit_balance}, below {$low_credit_threshold}. An order or'
                . ' renewal that your credit cannot cover is refused, so please top up your credit in time.</p>'
                . '<p>This reminder is sent once. The next one comes only after your credit has been topped up to'
                . ' {$low_credit_threshold} or more and falls below it again.</p>'
                . '<p>{$signature}</p>',
            'attachments'   => '',
            'fromname'      => '',
            'fromemail'     => '',
            'disabled'      => 0,
            'custom'        => 1,
            'language'      => '',
            'copyto'        => '',
            'blind_copy_to' => '',
            'plaintext'     => 0,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }

    private static function send(int $clientId, float $balance, float $threshold): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request(); // the partner's answer goes out now; the rest runs after it
        }
        $currencyId = (int) Capsule::table('tblclients')->where('id', $clientId)->value('currency');
        $result = LocalApi::tryCall('SendEmail', [
            'messagename' => self::TEMPLATE,
            'id'          => $clientId,
            'customvars'  => base64_encode(serialize([
                'low_credit_balance'   => (string) formatCurrency($balance, $currencyId),
                'low_credit_threshold' => (string) formatCurrency($threshold, $currencyId),
            ])),
        ]);
        $sent = ($result['result'] ?? '') === 'success';
        logActivity('Partner Hub: a payment took the credit of client #' . $clientId . ' to ' . number_format($balance, 2, '.', '')
            . ', below the low-credit alert threshold of ' . number_format($threshold, 2, '.', '') . '; '
            . ($sent ? 'the low-credit email was sent.' : 'the low-credit email was NOT sent: ' . ($result['message'] ?? 'no reason given') . '.'),
            $clientId);
    }
}
