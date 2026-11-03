<?php
/**
 * ticket-ip.test.php — Clear Ticket IP Addresses (includes/hooks/vpnhood-ticket-ip.php) on the dev
 * WHMCS: off while unset, a ticket's and a rating's IP stay; on, an opening ticket's IP is cleared
 * through WHMCS's own TicketOpen and TicketOpenAdmin hooks, and the daily run clears the other
 * tickets' and the ratings'. A ticket opened through the API records no IP and a rating has no API,
 * so both IPs are fixtures written raw (tests/e2e/ticket-ip.spec.mjs covers the client-area path,
 * where WHMCS records one itself). The setting is a tbladdonmodules row no localAPI command writes:
 * the test sets it and puts it back. Its tickets and rating are removed.
 *
 * Runs ON the dev server (uploaded by ticket-ip.test.sh with lib/common.php).
 */

require __DIR__ . '/lib/common.php';
require_once WEBROOT . '/includes/hooks/vpnhood-ticket-ip.php';

use WHMCS\Database\Capsule;

const FIXTURE_IP = '203.0.113.7'; // TEST-NET-3: never a real client

$buyer = clientByEmail($db, BUYER_EMAIL);
if (!$buyer) {
    bad('fixtures missing — run tests/bootstrap/init-skeleton.sh first');
    finish();
}
$buyerId = (int) $buyer['id'];
$deptId = (int) Capsule::table('tblticketdepartments')->orderBy('id')->value('id');

/** The ClearTicketIpAddresses setting, as VpnHood! MANAGER Configuration stores it; null removes it. */
function setClearTicketIps(?string $value): void
{
    Capsule::table('tbladdonmodules')->where('module', 'vpnhoodconfig')->where('setting', 'ClearTicketIpAddresses')->delete();
    if ($value !== null) {
        Capsule::table('tbladdonmodules')->insert(['module' => 'vpnhoodconfig', 'setting' => 'ClearTicketIpAddresses', 'value' => $value]);
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

$saved = Capsule::table('tbladdonmodules')->where('module', 'vpnhoodconfig')->where('setting', 'ClearTicketIpAddresses')->value('value');
$tickets = [];
$ratingId = 0;
try {
    // off while unset: what WHMCS records stays
    setClearTicketIps(null);
    $tickets[] = $older = openTicket($buyerId, $deptId, 'vhtest ticket ip: older', false);
    Capsule::table('tbltickets')->where('id', $older)->update(['ipaddress' => FIXTURE_IP]);
    $ratingId = (int) Capsule::table('tblticketfeedback')->insertGetId([
        'ticketid' => $older, 'adminid' => 0, 'rating' => 5, 'comments' => 'vhtest ticket ip', 'datetime' => date('Y-m-d H:i:s'),
        'ip' => FIXTURE_IP,
    ]);
    run_hook('TicketOpen', ['ticketid' => $older]);
    vpnhood_ticket_ip_clear_stored();
    (ticketIp($older) === FIXTURE_IP && Capsule::table('tblticketfeedback')->where('id', $ratingId)->value('ip') === FIXTURE_IP)
        ? ok('off while unset: the ticket and the rating keep their IP')
        : bad('off, an IP was cleared: ' . json_encode(['ticket' => ticketIp($older)]));

    // on: WHMCS's own opening hooks clear the new ticket's IP, and only that ticket's
    setClearTicketIps('on');
    foreach (['TicketOpen' => false, 'TicketOpenAdmin' => true] as $hook => $byAdmin) {
        $tickets[] = $opened = openTicket($buyerId, $deptId, "vhtest ticket ip: $hook", $byAdmin);
        Capsule::table('tbltickets')->where('id', $opened)->update(['ipaddress' => FIXTURE_IP]);
        run_hook($hook, ['ticketid' => $opened]);
        (ticketIp($opened) === '' && ticketIp($older) === FIXTURE_IP)
            ? ok("on: $hook clears the opening ticket's IP and leaves the others")
            : bad("on, $hook: " . json_encode(['opened' => ticketIp($opened), 'older' => ticketIp($older)]));
    }

    // on: the daily run clears what is left
    vpnhood_ticket_ip_clear_stored();
    (ticketIp($older) === '' && Capsule::table('tblticketfeedback')->where('id', $ratingId)->value('ip') === '')
        ? ok("on: the daily run clears the older ticket's and the rating's IP")
        : bad('on, the daily run left an IP: ' . json_encode(['ticket' => ticketIp($older),
            'rating' => Capsule::table('tblticketfeedback')->where('id', $ratingId)->value('ip')]));
} finally {
    setClearTicketIps($saved);
    if ($ratingId > 0) {
        Capsule::table('tblticketfeedback')->where('id', $ratingId)->delete();
    }
    foreach ($tickets as $ticketId) {
        localAPI('DeleteTicket', ['ticketid' => $ticketId]);
    }
    Capsule::table('tbltickets')->whereIn('id', $tickets ?: [0])->doesntExist()
        ? ok('cleanup: the tickets are gone, the setting is back')
        : bad('cleanup left a ticket behind');
}

finish();
