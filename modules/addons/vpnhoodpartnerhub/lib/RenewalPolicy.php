<?php

namespace WHMCS\Module\Addon\VpnHoodPartnerHub;

/**
 * Where a partner key's term ends and when a renewal may charge for it (docs/ARCHITECTURE.md,
 * "Renewals"). Dates are WHMCS dates (Y-m-d), and months are added the way WHMCS adds them,
 * overflowing into the next month (31 Aug + 1 month = 1 Oct). No database and no WHMCS here, so
 * tests/unit checks every rule directly.
 */
final class RenewalPolicy
{
    private const CYCLE_MONTHS = [
        'Monthly'       => 1,
        'Quarterly'     => 3,
        'Semi-Annually' => 6,
        'Annually'      => 12,
        'Biennially'    => 24,
        'Triennially'   => 36,
    ];

    /** Months in a WHMCS billing cycle; 0 for one that never renews (One Time, Free Account). */
    public static function cycleMonths(string $billingCycle): int
    {
        return self::CYCLE_MONTHS[$billingCycle] ?? 0;
    }

    /**
     * A full cycle or more is still paid: renewing now would hold two, so it charges nothing.
     * Measured back from the due date (the term's last cycle has not started), not forward from
     * today: overflowing months are not monotonic (31 Jan + 1 month = 3 Mar, 1 Feb + 1 month =
     * 1 Mar), so a key renewed on 31 Jan from 1 Feb to 1 Mar would look short of 3 Mar, and a
     * repeat would charge a second cycle.
     */
    public static function isPaidAhead(string $nextDueDate, string $today, int $months): bool
    {
        return self::day($nextDueDate)->modify("-{$months} months")->format('Y-m-d') >= $today;
    }

    /**
     * A new key's first term ends a day after its cycle: a store that resells the key renews at
     * the hour of the original purchase on the cycle's last day, and the key must still run then.
     * Null once the term is not WHMCS's first cycle any more (renewed, or moved by hand).
     */
    public static function firstTermEnd(string $regDate, string $nextDueDate, int $months): ?string
    {
        $cycleEnd = self::addMonths($regDate, $months);
        $termEnd = self::nextDay($cycleEnd);
        return in_array($nextDueDate, [$cycleEnd, $termEnd], true) ? $termEnd : null;
    }

    /**
     * A lapsed term renews from today, with the first term's extra day: from its old due date the
     * renewal would pay for the days that passed unpaid. Null while the term has not lapsed.
     */
    public static function restartedTermEnd(string $nextDueDate, string $today, int $months): ?string
    {
        return $nextDueDate < $today ? self::nextDay(self::addMonths($today, $months)) : null;
    }

    public static function addMonths(string $date, int $months): string
    {
        return self::day($date)->modify("+{$months} months")->format('Y-m-d');
    }

    public static function nextDay(string $date): string
    {
        return self::day($date)->modify('+1 day')->format('Y-m-d');
    }

    private static function day(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }
}
