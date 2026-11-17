<?php
/**
 * welcome-code.test.php — the purchase email carries the code
 * (includes/hooks/vpnhoodstore-welcome-code.php, scripts/welcome-code-template.php):
 *
 *   1. the product's welcome email of a single-code sale gets {$premium_code} = the code the
 *      access manager holds, and the mail WHMCS sends shows it;
 *   2. any other mail about the service gets no code;
 *   3. a bulk (CSV) service gets none;
 *   4. a code that cannot be read leaves the mail without it and logs why.
 *
 * Buys as a client of its own on the reserved .test domain, so the mail goes nowhere.
 * ⚠ Places ONE real order (a real token at the access manager); the service is terminated and
 * the client deleted in cleanup.
 */

require __DIR__ . '/lib/common.php';
require_once WEBROOT . '/modules/servers/vpnhoodstore/lib/AsyncApiClientFactory.php';
require_once WEBROOT . '/modules/servers/vpnhoodstore/lib/ApiService.php';

use WHMCS\Module\Server\VpnHoodStore\ApiService;

const KEY_SLUG = 'reseller-one-month-premium-code';

$product = one($db, "SELECT p.id, p.welcomeemail FROM tblproducts p
    LEFT JOIN tblproducts_slugs s ON s.product_id=p.id AND s.active=1
    WHERE p.slug=? OR s.slug=? LIMIT 1", [KEY_SLUG, KEY_SLUG]);
if (!$product) {
    bad('fixture product missing — run tests/bootstrap/init-skeleton.sh first');
    finish();
}
$welcome = one($db, 'SELECT name, message FROM tblemailtemplates WHERE id=?', [(int)$product['welcomeemail']]);
if (!$welcome || !str_contains((string)$welcome['message'], '{$premium_code}')) {
    bad('the product\'s welcome email does not show {$premium_code} — run scripts/welcome-code-template.php on the install');
    finish();
}

/** What the EmailPreSend hooks give a mail about the service. */
function mergeFieldsFor(string $messageName, int $serviceId): array
{
    $fields = [];
    foreach ((array) run_hook('EmailPreSend', ['messagename' => $messageName, 'relid' => $serviceId, 'mergefields' => []]) as $result) {
        $fields += is_array($result) ? $result : [];
    }
    return $fields;
}

$clientId = 0;
$serviceId = 0;
try {
    $client = localAPI('AddClient', [
        'firstname' => 'Welcome', 'lastname' => 'Code', 'email' => 'welcome-code-' . time() . '@vpnhood.test',
        'address1' => '1 Test St', 'city' => 'Test', 'state' => 'Test', 'postcode' => '00000', 'country' => 'US',
        'phonenumber' => '5555550100', 'password2' => bin2hex(random_bytes(12)), 'noemail' => true, 'skipvalidation' => true,
    ]);
    if (($client['result'] ?? '') !== 'success') {
        throw new RuntimeException('AddClient failed: ' . json_encode($client));
    }
    $clientId = (int) $client['clientid'];
    localAPI('AddCredit', ['clientid' => $clientId, 'description' => 'welcome-code test', 'amount' => 100]);

    $add = localAPI('AddOrder', [
        'clientid' => $clientId, 'pid' => (int)$product['id'], 'billingcycle' => 'onetime',
        'paymentmethod' => 'banktransfer', 'noemail' => true, 'noinvoiceemail' => true,
    ]);
    if (($add['result'] ?? '') !== 'success') {
        throw new RuntimeException('AddOrder failed: ' . json_encode($add));
    }
    $serviceId = (int) explode(',', (string)($add['productids'] ?? ''))[0];
    payInvoiceFromCredit($db, (int)$add['invoiceid'], $clientId);
    $accept = localAPI('AcceptOrder', ['orderid' => (int)$add['orderid'], 'autosetup' => true, 'sendemail' => false]);
    if (($accept['result'] ?? '') !== 'success') {
        throw new RuntimeException('AcceptOrder failed: ' . json_encode($accept));
    }
    $tokenId = (string) serviceProperty($db, $serviceId, 'accessTokenId');
    $code = (string) (json_decode((new ApiService())->getAccessCode($tokenId), true)['accessToken']['accessCode'] ?? '');
    $code !== '' ? ok("single-code sale provisioned (service #$serviceId)") : bad('the sale has no code at the access manager');
    // as the app shows it (AccessCodeUtils.Format)
    $dashed = implode('-', str_split($code, 4));

    // -- 1. the welcome email gets the code, and the sent mail shows it -------
    $fields = mergeFieldsFor($welcome['name'], $serviceId);
    ($fields['premium_code'] ?? null) === $dashed
        ? ok('the welcome email gets {$premium_code} = the code at the access manager, a dash after every four characters')
        : bad('premium_code in the welcome email: ' . json_encode($fields['premium_code'] ?? null));
    $lastMail = (int) (one($db, 'SELECT MAX(id) m FROM tblemails')['m'] ?? 0);
    $sent = localAPI('SendEmail', ['messagename' => $welcome['name'], 'id' => $serviceId]);
    $mail = one($db, 'SELECT message FROM tblemails WHERE id>? AND userid=? ORDER BY id DESC LIMIT 1', [$lastMail, $clientId]);
    $onItsOwnLine = $mail && preg_match('/<p dir="ltr"[^>]*>\s*<strong>' . preg_quote($dashed, '/') . '<\/strong>\s*<\/p>/', (string)$mail['message']);
    ($sent['result'] ?? '') === 'success' && $onItsOwnLine
        ? ok('the welcome email WHMCS sent shows the code on a left-to-right line of its own')
        : bad('sent welcome email: ' . json_encode(['send' => $sent, 'logged' => $mail !== null, 'hasCode' => $mail && str_contains((string)$mail['message'], $dashed)]));

    // -- 2. no other mail about the service gets it ----------------------------
    !array_key_exists('premium_code', mergeFieldsFor('Service Suspension Notification', $serviceId))
        ? ok('another mail about the service gets no code')
        : bad('the suspension notice got the code');

    // -- 3. a bulk service gets none ------------------------------------------
    $service = \WHMCS\Service\Service::findOrFail($serviceId);
    $service->serviceProperties->save(['bulkDelivery' => 'yes']);
    !array_key_exists('premium_code', mergeFieldsFor($welcome['name'], $serviceId))
        ? ok('a bulk (CSV) service gets no code')
        : bad('a bulk service got a code');
    $service->serviceProperties->save(['bulkDelivery' => '']);

    // -- 4. a code that cannot be read: no field, and the reason is logged ----
    $lastLog = (int) (one($db, 'SELECT MAX(id) m FROM tblactivitylog')['m'] ?? 0);
    $service->serviceProperties->save(['accessTokenId' => '']);
    $fields = mergeFieldsFor($welcome['name'], $serviceId);
    $service->serviceProperties->save(['accessTokenId' => $tokenId]);
    $logged = one($db, 'SELECT id FROM tblactivitylog WHERE id>? AND description LIKE ? LIMIT 1',
        [$lastLog, "%welcome email of service #$serviceId went out without its code%"]) !== null;
    !array_key_exists('premium_code', $fields) && $logged
        ? ok('an unreadable code leaves the mail without it, and the reason is logged')
        : bad('unreadable code: ' . json_encode(['field' => $fields['premium_code'] ?? null, 'logged' => $logged]));
} catch (Throwable $e) {
    bad('exception: ' . $e->getMessage());
} finally {
    if ($serviceId) {
        localAPI('ModuleTerminate', ['serviceid' => $serviceId]);
    }
    if ($clientId) {
        $deleted = localAPI('DeleteClient', ['clientid' => $clientId, 'deleteusers' => true, 'deletetransactions' => true]);
        ($deleted['result'] ?? '') === 'success' ? ok("cleanup: token ended, client #$clientId deleted") : bad('cleanup: ' . json_encode($deleted));
    }
}
finish();
