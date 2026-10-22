<?php
/**
 * renewal-policy.test.php — unit tests of RenewalPolicy, the term rules of a partner key
 * (docs/ARCHITECTURE.md, "Renewals"). Pure PHP, no WHMCS and no database: run.sh runs it with the dev
 * box's PHP, since there is none locally.
 *
 * Usage: php renewal-policy.test.php <path of RenewalPolicy.php>
 */

error_reporting(E_ALL);
date_default_timezone_set('UTC');
require $argv[1] ?? __DIR__ . '/../../modules/addons/vpnhoodpartnerhub/lib/RenewalPolicy.php';

use WHMCS\Module\Addon\VpnHoodPartnerHub\RenewalPolicy;

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

function same($actual, $expected, string $message): void
{
    check($actual === $expected, $message, ['actual' => $actual, 'expected' => $expected]);
}

// -- cycles ---------------------------------------------------------------------
same(RenewalPolicy::cycleMonths('Monthly'), 1, 'Monthly is 1 month');
same(RenewalPolicy::cycleMonths('Semi-Annually'), 6, 'Semi-Annually is 6 months');
same(RenewalPolicy::cycleMonths('Triennially'), 36, 'Triennially is 36 months');
same(RenewalPolicy::cycleMonths('One Time'), 0, 'One Time never renews');
same(RenewalPolicy::cycleMonths('Free Account'), 0, 'Free Account never renews');

// -- months the way WHMCS adds them (31 Aug + 1 month = 1 Oct, seen on production) -----------
same(RenewalPolicy::addMonths('2026-10-21', 1), '2026-11-21', 'a month later, same day');
same(RenewalPolicy::addMonths('2026-08-31', 1), '2026-10-01', 'a day the next month lacks overflows, as WHMCS does');
same(RenewalPolicy::addMonths('2026-12-15', 12), '2027-12-15', 'a year later');
same(RenewalPolicy::nextDay('2026-12-31'), '2027-01-01', 'the next day crosses the year');

// -- never two cycles ---------------------------------------------------------------
check(RenewalPolicy::isPaidAhead('2026-11-22', '2026-10-21', 1), 'a new key (its first term and the extra day) is paid ahead');
check(RenewalPolicy::isPaidAhead('2026-11-21', '2026-10-21', 1), 'exactly one full cycle left is paid ahead');
check(!RenewalPolicy::isPaidAhead('2026-11-20', '2026-10-21', 1), 'a day less than a full cycle renews');
check(!RenewalPolicy::isPaidAhead('2026-11-22', '2026-11-21', 1), 'the store renewing on the term\'s last day renews');
check(!RenewalPolicy::isPaidAhead('2026-10-01', '2026-10-21', 1), 'a lapsed term renews');
check(RenewalPolicy::isPaidAhead('2027-10-22', '2026-10-21', 12), 'a new annual key is paid ahead');
check(!RenewalPolicy::isPaidAhead('2027-10-20', '2026-10-21', 12), 'an annual key short of a full year renews');
check(RenewalPolicy::isPaidAhead('2027-03-01', '2027-01-31', 1), 'renewed on 31 Jan from 1 Feb to 1 Mar, a repeat charges nothing (31 Jan + 1 month is 3 Mar)');
check(RenewalPolicy::isPaidAhead('2027-03-01', '2027-01-30', 1), 'and renewed on 30 Jan, likewise');

// Over every day of two years (one of them leap) and every cycle, from 40 days lapsed to a full
// cycle left: once renewed, a key is paid ahead, so no repeat, retry or month end charges twice;
// and a key bought or restarted today is paid ahead.
$broken = [];
foreach ([1, 3, 6, 12] as $months) {
    for ($day = new DateTimeImmutable('2027-01-01'); $day < new DateTimeImmutable('2029-01-01'); $day = $day->modify('+1 day')) {
        $today = $day->format('Y-m-d');
        for ($left = -40; $left <= 31 * $months; $left++) {
            $due = $day->modify("{$left} days")->format('Y-m-d');
            if (RenewalPolicy::isPaidAhead($due, $today, $months)) {
                continue;
            }
            $renewed = max(RenewalPolicy::addMonths($due, $months), (string) RenewalPolicy::restartedTermEnd($due, $today, $months));
            if (!RenewalPolicy::isPaidAhead($renewed, $today, $months)) {
                $broken[] = "{$months}-month key due {$due}, renewed on {$today} to {$renewed}";
            }
        }
        if (!RenewalPolicy::isPaidAhead(RenewalPolicy::nextDay(RenewalPolicy::addMonths($today, $months)), $today, $months)) {
            $broken[] = "{$months}-month key bought or restarted on {$today}";
        }
    }
}
check($broken === [], 'a renewal always leaves its key paid ahead, on every day of 2027-2028', array_slice($broken, 0, 3));

// -- the first term's extra day -------------------------------------------------------
same(RenewalPolicy::firstTermEnd('2026-10-21', '2026-11-21', 1), '2026-11-22', 'WHMCS\'s first term gets the extra day');
same(RenewalPolicy::firstTermEnd('2026-10-21', '2026-11-22', 1), '2026-11-22', 'a first term already extended stays where it is');
same(RenewalPolicy::firstTermEnd('2026-08-31', '2026-10-01', 1), '2026-10-02', 'the extra day follows WHMCS\'s overflowed first term');
same(RenewalPolicy::firstTermEnd('2026-10-21', '2026-12-21', 1), null, 'a renewed term is left alone');
same(RenewalPolicy::firstTermEnd('2026-10-21', '2026-11-25', 1), null, 'a term moved by hand is left alone');

// -- a lapsed term restarts today -------------------------------------------------------
same(RenewalPolicy::restartedTermEnd('2026-10-18', '2026-10-21', 1), '2026-11-22', 'a lapsed term restarts a cycle and a day from today');
same(RenewalPolicy::restartedTermEnd('2026-10-21', '2026-10-21', 1), null, 'a term due today has not lapsed');
same(RenewalPolicy::restartedTermEnd('2026-10-25', '2026-10-21', 1), null, 'a term still running has not lapsed');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
