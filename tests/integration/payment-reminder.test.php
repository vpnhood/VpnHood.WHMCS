<?php
/**
 * payment-reminder.test.php — no payment reminder or overdue notice for the invoice of a purchase
 * the Hub has not finished (the hub addon's hooks.php, vpnhoodpartnerhub_hold_payment_reminder).
 * The Hub pays its orders' invoices from credit, so those mails are held while a purchase naming
 * the invoice waits: needs_reconciliation, or a step a repeat resumes. Delivered, or for an
 * invoice no purchase names, they go as before, and every other invoice mail always does.
 *
 * Each check calls run_hook('EmailPreSend'), what WHMCS runs before every send, with the template
 * and the invoice as relid, as the cron's reminder run passes them: nothing is mailed. One
 * SendEmail of a held notice checks that WHMCS's own sending stops it (a failing run mails the
 * test reseller once). The invoice
 * is a real Unpaid one of the test reseller (CreateInvoice), left Cancelled at the end, as WHMCS's
 * overdue cancellation leaves a parked one; the purchase rows naming it are written and removed
 * here, as a parked purchase cannot be made on demand without a fault hook.
 *
 * Runs ON the dev server (uploaded by payment-reminder.test.sh with lib/common.php).
 */

require __DIR__ . '/lib/common.php';
require_once WEBROOT . '/modules/addons/vpnhoodpartnerhub/lib/PurchaseRepository.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodPartnerHub\PurchaseRepository;

const REMINDERS = ['Invoice Payment Reminder', 'First Invoice Overdue Notice', 'Second Invoice Overdue Notice', 'Third Invoice Overdue Notice'];

$reseller = clientByEmail($db, RESELLER_EMAIL);
$partner = $reseller ? Capsule::table('mod_vpnhood_partners')->where('client_id', $reseller['id'])->first() : null;
$productId = $partner ? (int) Capsule::table('mod_vpnhood_partner_products')->where('partner_id', $partner->id)->value('whmcs_product_id') : 0;
if (!$reseller || !$partner || $productId === 0) {
    bad('fixtures missing — run tests/bootstrap/init-skeleton.sh first');
    finish();
}
$clientId = (int) $reseller['id'];

/** Whether any EmailPreSend hook stops this mail. */
function held(string $template, int $invoiceId): bool
{
    foreach ((array) run_hook('EmailPreSend', ['messagename' => $template, 'relid' => $invoiceId, 'mergefields' => []]) as $result) {
        if (is_array($result) && !empty($result['abortsend'])) {
            return true;
        }
    }
    return false;
}

/** The templates of $templates that a hook stops. */
function heldOf(array $templates, int $invoiceId): array
{
    return array_values(array_filter($templates, fn ($t) => held($t, $invoiceId)));
}

function setPurchaseState(int $purchaseId, string $state): void
{
    Capsule::table(PurchaseRepository::TABLE)->where('id', $purchaseId)->update(['state' => $state, 'updated_at' => date('Y-m-d H:i:s')]);
}

// WHMCS loads the hook with the addon: this file never includes hooks.php itself
function_exists('vpnhoodpartnerhub_hold_payment_reminder')
    ? ok("WHMCS loads the hub addon's reminder hook")
    : bad("the hub addon's hooks.php is not loaded (deploy it: scripts/deploy-dev.sh hub)");

$run = (string) time();
$invoiceId = 0;
$purchaseId = 0;
try {
    $created = localAPI('CreateInvoice', [
        'userid' => $clientId, 'status' => 'Unpaid', 'sendinvoice' => false,
        'date' => date('Y-m-d'), 'duedate' => date('Y-m-d'),
        'itemdescription1' => 'vhtest payment reminder', 'itemamount1' => 1.75, 'itemtaxed1' => false,
    ]);
    $invoiceId = (int) ($created['invoiceid'] ?? 0);
    if ($invoiceId === 0 || Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status') !== 'Unpaid') {
        throw new RuntimeException('CreateInvoice gave no Unpaid invoice: ' . json_encode($created));
    }

    heldOf(REMINDERS, $invoiceId) === []
        ? ok('an invoice no purchase names: its reminders go')
        : bad('held without a purchase: ' . json_encode(heldOf(REMINDERS, $invoiceId)));

    $purchaseId = (int) Capsule::table(PurchaseRepository::TABLE)->insertGetId([
        'partner_id' => (int) $partner->id, 'client_id' => $clientId, 'idempotency_key' => "vhtest-reminder-$run",
        'customer_reference' => "vhtest-reminder-$run", 'product_id' => $productId, 'billing_cycle' => 'onetime',
        'invoice_id' => $invoiceId, 'state' => PurchaseRepository::NEEDS_RECONCILIATION,
        'last_error' => 'vhtest: parked for payment-reminder.test.php',
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);
    heldOf(REMINDERS, $invoiceId) === REMINDERS
        ? ok('a parked purchase names the invoice: the reminder and the three overdue notices are held')
        : bad('parked, only these are held: ' . json_encode(heldOf(REMINDERS, $invoiceId)));
    $others = ['Invoice Created', 'Invoice Payment Confirmation', 'Invoice Modified'];
    heldOf($others, $invoiceId) === []
        ? ok('... and its other invoice mail goes')
        : bad('parked, other mail held: ' . json_encode(heldOf($others, $invoiceId)));
    heldOf(REMINDERS, $invoiceId + 1) === []
        ? ok("... and the next invoice's reminders go")
        : bad('another invoice held: ' . json_encode(heldOf(REMINDERS, $invoiceId + 1)));

    // WHMCS's own sending asks the hook too: the one real send of this file, stopped
    $logFrom = (int) Capsule::table('tblactivitylog')->max('id');
    $mailFrom = (int) Capsule::table('tblemails')->max('id');
    $sent = localAPI('SendEmail', ['messagename' => 'First Invoice Overdue Notice', 'id' => $invoiceId]);
    $abortLogged = Capsule::table('tblactivitylog')->where('id', '>', $logFrom)
        ->where('description', 'like', 'Email Sending Aborted by Hook%First Invoice Overdue Notice%')->exists();
    $mailed = Capsule::table('tblemails')->where('id', '>', $mailFrom)->where('userid', $clientId)->exists();
    ($abortLogged && !$mailed)
        ? ok("SendEmail of the overdue notice: WHMCS aborts it by the hook, nothing is mailed")
        : bad('SendEmail: ' . json_encode(['response' => $sent, 'abortLogged' => $abortLogged, 'mailed' => $mailed]));

    setPurchaseState($purchaseId, PurchaseRepository::ORDERED);
    held('First Invoice Overdue Notice', $invoiceId)
        ? ok('a request that died after ordering: held, the repeat pays it')
        : bad('ordered, the overdue notice goes');

    setPurchaseState($purchaseId, PurchaseRepository::DELIVERED);
    heldOf(REMINDERS, $invoiceId) === []
        ? ok('delivered: its reminders go as before')
        : bad('delivered, held: ' . json_encode(heldOf(REMINDERS, $invoiceId)));
} catch (Throwable $e) {
    bad($e->getMessage());
} finally {
    if ($purchaseId > 0) {
        Capsule::table(PurchaseRepository::TABLE)->where('id', $purchaseId)->delete();
    }
    if ($invoiceId > 0) {
        localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Cancelled']);
    }
    Capsule::table(PurchaseRepository::TABLE)->where('id', $purchaseId)->doesntExist()
        && ($invoiceId === 0 || Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status') === 'Cancelled')
        ? ok('cleanup: the purchase row is gone, the invoice Cancelled')
        : bad('cleanup left the purchase row or an Unpaid invoice');
}

finish();
