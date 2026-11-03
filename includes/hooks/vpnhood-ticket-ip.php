<?php

/**
 * VpnHood — Clear Ticket IP Addresses: while the option is on (VpnHood! MANAGER Configuration,
 * off while unset), WHMCS keeps no IP address on tickets.
 *
 * WHMCS records the client's IP on the message that opens a ticket from the client area
 * (`tbltickets.ipaddress`; one opened through the API carries none) and shows it under that
 * message on the ticket page, to the client and in the admin area. It keeps one on each ticket
 * rating too (`tblticketfeedback.ip`); replies carry none, and no setting turns any of it off. A
 * ticket's IP is cleared as the ticket opens; the daily cron clears what is left: ratings, which
 * no hook announces, and the tickets stored before the option was on.
 *
 * No localAPI command writes either column, so both are written directly. The hook functions are
 * named, not closures, so tests/integration/ticket-ip.test.php can drive them.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

function vpnhood_ticket_ip_enabled(): bool
{
    return Capsule::table('tbladdonmodules')->where('module', 'vpnhoodconfig')
        ->where('setting', 'ClearTicketIpAddresses')->value('value') === 'on';
}

/** TicketOpen, TicketOpenAdmin: the new ticket keeps no IP. A failure never fails the ticket; the daily run clears it. */
function vpnhood_ticket_ip_clear_opened(array $vars): void
{
    $ticketId = (int) ($vars['ticketid'] ?? 0);
    try {
        if ($ticketId > 0 && vpnhood_ticket_ip_enabled()) {
            Capsule::table('tbltickets')->where('id', $ticketId)->update(['ipaddress' => '']);
        }
    } catch (\Throwable $e) {
        logActivity("VpnHood: the IP address of ticket #$ticketId could not be cleared; the daily cron tries again: "
            . $e->getMessage());
    }
}

/** DailyCronJob: no ticket and no rating keeps an IP. */
function vpnhood_ticket_ip_clear_stored(): void
{
    try {
        if (!vpnhood_ticket_ip_enabled()) {
            return;
        }
        Capsule::table('tbltickets')->where('ipaddress', '!=', '')->update(['ipaddress' => '']);
        Capsule::table('tblticketfeedback')->where('ip', '!=', '')->update(['ip' => '']);
    } catch (\Throwable $e) {
        logActivity('VpnHood: the IP addresses stored on tickets could not be cleared: ' . $e->getMessage());
    }
}

add_hook('TicketOpen', 1, 'vpnhood_ticket_ip_clear_opened');
add_hook('TicketOpenAdmin', 1, 'vpnhood_ticket_ip_clear_opened');
add_hook('DailyCronJob', 1, 'vpnhood_ticket_ip_clear_stored');
