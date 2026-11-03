<?php
/**
 * ticket-ip.test.php — Clear Ticket IP Addresses on the dev WHMCS, for both packages that carry it:
 * the hub's (includes/hooks/vpnhood-ticket-ip.php, VpnHood! MANAGER Configuration) and the partner
 * connector's (vpnhoodpartnerconfig's hooks.php, VpnHood.WHMCS.Partner), each on its own setting.
 * Off while unset, a ticket's and a rating's IP stay; with one package's option on, an opening
 * ticket's IP is cleared through WHMCS's own TicketOpen and TicketOpenAdmin hooks, and that package's
 * daily run clears the other tickets' and the ratings'. A ticket opened through the API records no
 * IP and a rating has no API, so both IPs are fixtures written raw (tests/e2e/ticket-ip.spec.mjs
 * covers the client-area path, where WHMCS records one itself). The settings are tbladdonmodules
 * rows no localAPI command writes: the test sets them and puts them back. Its tickets and rating
 * are removed. The connector's part runs when the connector is deployed (scripts/deploy-dev.sh partner).
 *
 * Runs ON the dev server (uploaded by ticket-ip.test.sh with lib/common.php).
 */

require __DIR__ . '/lib/common.php';
require_once WEBROOT . '/includes/hooks/vpnhood-ticket-ip.php';
$connectorHooks = WEBROOT . '/modules/addons/vpnhoodpartnerconfig/hooks.php';
if (!function_exists('vpnhoodpartnerconfig_clearStoredTicketIps') && is_readable($connectorHooks)) {
    require_once $connectorHooks; // WHMCS loads it only for an addon in its hook list
}

use WHMCS\Database\Capsule;

const FIXTURE_IP = '203.0.113.7'; // TEST-NET-3: never a real client

$buyer = clientByEmail($db, BUYER_EMAIL);
if (!$buyer) {
    bad('fixtures missing — run tests/bootstrap/init-skeleton.sh first');
    finish();
}
$buyerId = (int) $buyer['id'];
$deptId = (int) Capsule::table('tblticketdepartments')->orderBy('id')->value('id');

// each package: its setting's module, and its daily run
$packages = ['vpnhoodconfig' => 'vpnhood_ticket_ip_clear_stored'];
if (function_exists('vpnhoodpartnerconfig_clearStoredTicketIps')) {
    $packages['vpnhoodpartnerconfig'] = 'vpnhoodpartnerconfig_clearStoredTicketIps';
} else {
    bad('the partner connector is not deployed — run scripts/deploy-dev.sh partner');
}

/** A package's ClearTicketIpAddresses setting, as its addon stores it; null removes it. */
function setClearTicketIps(string $module, ?string $value): void
{
    Capsule::table('tbladdonmodules')->where('module', $module)->where('setting', 'ClearTicketIpAddresses')->delete();
    if ($value !== null) {
        Capsule::table('tbladdonmodules')->insert(['module' => $module, 'setting' => 'ClearTicketIpAddresses', 'value' => $value]);
    }
}

function ticketIp(int $ticketId): string
{
    return (string) Capsule::table('tbltickets')->where('id', $ticketId)->value('ipaddress');
}

function openTicket(int $clientId, int $deptId, string $subject, bool $byAdmin): int
{
    $opened = localAPI('OpenTicket', ['deptid' => $deptId, 'clientid' => $clientId, 'subject' => $subject,
        'message' => 'vhtest ticket ip', 'noemail' => true, 'admin' => $byAdmin]);
    if (($opened['result'] ?? '') !== 'success') {
        throw new RuntimeException('OpenTicket failed: ' . json_encode($opened));
    }
    return (int) $opened['id'];
}

$saved = [];
foreach (array_keys($packages) as $module) {
    $saved[$module] = Capsule::table('tbladdonmodules')->where('module', $module)->where('setting', 'ClearTicketIpAddresses')->value('value');
}
$tickets = [];
$ratingId = 0;
try {
    // off while unset, in every package: what WHMCS records stays
    foreach (array_keys($packages) as $module) {
        setClearTicketIps($module, null);
    }
    $tickets[] = $older = openTicket($buyerId, $deptId, 'vhtest ticket ip: older', false);
    $ratingId = (int) Capsule::table('tblticketfeedback')->insertGetId([
        'ticketid' => $older, 'adminid' => 0, 'rating' => 5, 'comments' => 'vhtest ticket ip', 'datetime' => date('Y-m-d H:i:s'),
        'ip' => FIXTURE_IP,
    ]);
    Capsule::table('tbltickets')->where('id', $older)->update(['ipaddress' => FIXTURE_IP]);
    run_hook('TicketOpen', ['ticketid' => $older]);
    foreach ($packages as $dailyRun) {
        $dailyRun();
    }
    (ticketIp($older) === FIXTURE_IP && Capsule::table('tblticketfeedback')->where('id', $ratingId)->value('ip') === FIXTURE_IP)
        ? ok('off while unset: the ticket and the rating keep their IP')
        : bad('off, an IP was cleared: ' . json_encode(['ticket' => ticketIp($older)]));

    foreach ($packages as $module => $dailyRun) {
        // only this package's option on: WHMCS's own opening hooks clear the new ticket's IP, and only that ticket's
        setClearTicketIps($module, 'on');
        Capsule::table('tbltickets')->where('id', $older)->update(['ipaddress' => FIXTURE_IP]);
        Capsule::table('tblticketfeedback')->where('id', $ratingId)->update(['ip' => FIXTURE_IP]);
        foreach (['TicketOpen' => false, 'TicketOpenAdmin' => true] as $hook => $byAdmin) {
            $tickets[] = $opened = openTicket($buyerId, $deptId, "vhtest ticket ip: $module $hook", $byAdmin);
            Capsule::table('tbltickets')->where('id', $opened)->update(['ipaddress' => FIXTURE_IP]);
            run_hook($hook, ['ticketid' => $opened]);
            (ticketIp($opened) === '' && ticketIp($older) === FIXTURE_IP)
                ? ok("$module on: $hook clears the opening ticket's IP and leaves the others")
                : bad("$module on, $hook: " . json_encode(['opened' => ticketIp($opened), 'older' => ticketIp($older)]));
        }

        // and its daily run clears what is left
        $dailyRun();
        (ticketIp($older) === '' && Capsule::table('tblticketfeedback')->where('id', $ratingId)->value('ip') === '')
            ? ok("$module on: the daily run clears the older ticket's and the rating's IP")
            : bad("$module on, the daily run left an IP: " . json_encode(['ticket' => ticketIp($older),
                'rating' => Capsule::table('tblticketfeedback')->where('id', $ratingId)->value('ip')]));
        setClearTicketIps($module, null);
    }
} finally {
    foreach ($saved as $module => $value) {
        setClearTicketIps($module, $value);
    }
    if ($ratingId > 0) {
        Capsule::table('tblticketfeedback')->where('id', $ratingId)->delete();
    }
    foreach ($tickets as $ticketId) {
        localAPI('DeleteTicket', ['ticketid' => $ticketId]);
    }
    Capsule::table('tbltickets')->whereIn('id', $tickets ?: [0])->doesntExist()
        ? ok('cleanup: the tickets are gone, the settings are back')
        : bad('cleanup left a ticket behind');
}

finish();
