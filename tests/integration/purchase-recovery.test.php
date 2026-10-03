<?php
/**
 * purchase-recovery.test.php — the Hub's purchase records when things go wrong
 * (docs/ARCHITECTURE.md, "Purchases"). Runs ON the dev server, uploaded by
 * purchase-recovery.test.sh with lib/common.php; each subcommand is one scenario, and the
 * .sh runs the concurrent ones as parallel processes (PHP-CLI here cannot start any).
 *
 * Every order goes through the real Hub API over HTTPS as the test partner (HUB_URL,
 * HUB_KEY, HUB_SECRET). Each failure is set up the way it happens in production — too
 * little credit, an access server that rejects the token request, a credit change during a
 * rollback (a dev-only hook the .sh installs), a request that died after a step without
 * saving it (the purchase row set back), a lost service record — and each ends with the
 * recovery the design promises: one order, charged once, one token.
 *
 * Writes outside the Hub's own table are limited to what the failure needs: the test
 * reseller's credit (as tests/bootstrap/skeleton.php tops it up), one product setting
 * restored in a finally, and service properties/status through the WHMCS model and API.
 *
 * ⚠ Spends reseller (test) credit and provisions real tokens; every order it places is
 * terminated (normal delivery) or neutralized (CSV) before it exits.
 *
 * Usage: php purchase-recovery.test.php <scenario> [args]; prints a JSON report.
 */

require __DIR__ . '/lib/common.php';
require_once WEBROOT . '/modules/servers/vpnhoodstore/lib/AsyncApiClientFactory.php';
require_once WEBROOT . '/modules/servers/vpnhoodstore/lib/ApiService.php';
require_once WEBROOT . '/modules/addons/vpnhoodpartnerhub/lib/ApiException.php';
require_once WEBROOT . '/modules/addons/vpnhoodpartnerhub/lib/PartnerRepository.php';
require_once WEBROOT . '/modules/addons/vpnhoodpartnerhub/lib/PartnerApiController.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodPartnerHub\PartnerRepository;
use WHMCS\Module\Addon\VpnHoodPartnerHub\PurchaseProcessor;
use WHMCS\Module\Addon\VpnHoodPartnerHub\PurchaseRepository;

const REF_ONETIME   = 'reseller-one-month-premium-code';
const REF_RECURRING = 'reseller-one-month-premium-code-subscription';
const REF_CSV       = 'reseller-bulk-csv-premium-code';
const PRICE         = 1.75;
const RESULTS_DIR   = '/home/whmcsdev/tmp/vhrt';
const FAIL_FLAG     = '/home/whmcsdev/tmp/vhtest-rollback-interference';

$reseller = clientByEmail($db, RESELLER_EMAIL);
$partnerRow = $reseller ? Capsule::table('mod_vpnhood_partners')->where('client_id', $reseller['id'])->first() : null;
if (!$reseller || !$partnerRow) {
    bad('fixtures missing — run tests/bootstrap/init-skeleton.sh first');
    finish();
}
$partner = (array) $partnerRow;
$clientId = (int) $reseller['id'];
$run = getenv('RUN') ?: (string) time();

// -------------------------------------------------------------------- helpers

function hub(string $action, array $params = []): array
{
    $ch = curl_init(rtrim((string) getenv('HUB_URL'), '/') . '/modules/addons/vpnhoodpartnerhub/api.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['action' => $action] + $params),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Vpnhood-Key: ' . getenv('HUB_KEY'),
            'X-Vpnhood-Secret: ' . getenv('HUB_SECRET'),
        ],
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode((string) $body, true) ?: ['raw' => (string) $body]];
}

function order(string $ref, string $reference, ?string $key, array $extra = []): array
{
    $params = ['downstreamRef' => $ref, 'customerReference' => $reference];
    if ($key !== null) {
        $params['idempotencyKey'] = $key;
    }
    return hub('order', $params + $extra);
}

function orderIdOf(array $r): int
{
    return (int) ($r['body']['data']['keys'][0]['upstreamOrderId'] ?? ($r['body']['details']['upstreamOrderId'] ?? 0));
}

function codeOf(array $r): string
{
    return (string) ($r['body']['code'] ?? '');
}

function expect(bool $condition, string $message, $detail = null): bool
{
    $condition ? ok($message) : bad($message . ($detail !== null ? ' — ' . json_encode($detail) : ''));
    return $condition;
}

function credit(): float
{
    global $clientId;
    return round((float) Capsule::table('tblclients')->where('id', $clientId)->value('credit'), 2);
}

/** The test reseller's balance, set the way the bootstrap tops it up (with a credit-history row). */
function setCredit(float $amount, string $why): void
{
    global $clientId;
    $delta = round($amount - credit(), 2);
    Capsule::table('tblclients')->where('id', $clientId)->update(['credit' => number_format($amount, 2, '.', '')]);
    Capsule::table('tblcredit')->insert([
        'clientid' => $clientId, 'admin_id' => 0, 'date' => date('Y-m-d'), 'description' => "vhtest: $why",
        'amount' => $delta, 'relid' => 0, 'billing_note_id' => 0,
    ]);
}

function purchase(string $key): ?array
{
    global $partner;
    $row = Capsule::table(PurchaseRepository::TABLE)->where('partner_id', $partner['id'])->where('idempotency_key', $key)->first();
    return $row ? (array) $row : null;
}

function setPurchase(int $id, array $fields): void
{
    Capsule::table(PurchaseRepository::TABLE)->where('id', $id)->update($fields + ['updated_at' => date('Y-m-d H:i:s')]);
}

function processor(): PurchaseProcessor
{
    global $partner;
    return new PurchaseProcessor(new PartnerRepository(), new PurchaseRepository(), $partner);
}

function hubService(int $orderId): ?array
{
    global $clientId;
    $row = Capsule::table('tblhosting')->where('orderid', $orderId)->where('userid', $clientId)->first();
    return $row ? (array) $row : null;
}

function orderCount(): int
{
    global $clientId;
    return (int) Capsule::table('tblorders')->where('userid', $clientId)->count();
}

/** The access server's tokens for this customer and order — what VpnHood! MANAGER lists (step 2 of the procedure). */
function tokensOf(int $orderId): array
{
    global $clientId;
    $settings = Capsule::table('tbladdonmodules')->where('module', 'vpnhoodconfig')->pluck('value', 'setting');
    $client = \WHMCS\Module\Server\VpnHoodStore\AsyncApiClientFactory::getInstance('https://api.vpnhood.com/api', (string) $settings['APIKey'])
        ->createAsyncClient();
    $json = json_decode($client('/projects/' . $settings['ProjectId'] . '/access-tokens', 'GET', [
        'customerId' => (string) $clientId, 'orderId' => (string) $orderId, 'shopId' => 'WHMCS', 'recordCount' => 100,
    ]), true);
    return array_values(array_map(fn ($item) => (string) $item['accessToken']['accessTokenId'], $json['items'] ?? []));
}

function terminateOrder(int $orderId): void
{
    if ($orderId <= 0) {
        return;
    }
    $r = hub('terminate', ['upstreamOrderId' => $orderId]);
    expect($r['status'] === 200, "cleanup: order #$orderId terminated", $r['status'] === 200 ? null : $r['body']);
}

function hubProductId(string $ref): int
{
    global $partner;
    return (int) Capsule::table('mod_vpnhood_partner_products')->where('partner_id', $partner['id'])
        ->where('downstream_ref', $ref)->value('whmcs_product_id');
}

function serviceProp(int $serviceId, string $name): string
{
    global $db;
    return (string) serviceProperty($db, $serviceId, $name);
}

function saveServiceProps(int $serviceId, array $values): void
{
    \WHMCS\Service\Service::find($serviceId)->serviceProperties->save($values);
}

function setServiceStatus(int $serviceId, string $status): void
{
    $r = localAPI('UpdateClientProduct', ['serviceid' => $serviceId, 'status' => $status]);
    expect(($r['result'] ?? '') === 'success', "service #$serviceId set $status", $r);
}

/** The latest vpnhoodstore CreateAccount entry of the module log after $afterId — step 1 of the procedure. */
function storeCreateLog(int $afterId): ?array
{
    $row = Capsule::table('tblmodulelog')->where('id', '>', $afterId)->where('module', 'vpnhoodstore')
        ->where('action', 'like', '%CreateAccount')->orderBy('id', 'desc')->first();
    return $row ? (array) $row : null;
}

function lastModuleLogId(): int
{
    return (int) Capsule::table('tblmodulelog')->max('id');
}

/** Render the addon page's attention list for this partner. */
function attentionHtml(): string
{
    global $partner;
    if (!function_exists('vpnhoodpartnerhub_renderAttention')) {
        require_once WEBROOT . '/modules/addons/vpnhoodpartnerhub/vpnhoodpartnerhub.php';
    }
    ob_start();
    vpnhoodpartnerhub_renderAttention('addonmodules.php?module=vpnhoodpartnerhub', $partner);
    return (string) ob_get_clean();
}

function saveResult(string $name, array $r): void
{
    @mkdir(RESULTS_DIR, 0700, true);
    file_put_contents(RESULTS_DIR . "/$name.json", json_encode($r));
}

function loadResult(string $name): array
{
    return json_decode((string) @file_get_contents(RESULTS_DIR . "/$name.json"), true) ?: [];
}

// ------------------------------------------------------------------ scenarios

$scenario = $argv[1] ?? '';
switch ($scenario) {

// Plan test 5 — the insufficient-credit path applies nothing and releases the key.
case 'insufficient-credit':
    $before = credit();
    $key = "rt-$run-credit";
    setCredit(1.00, 'insufficient-credit scenario');
    try {
        $r = order(REF_ONETIME, $key, $key);
        expect($r['status'] === 402 && codeOf($r) === 'insufficient_credit', 'a keyed order with too little credit -> 402 insufficient_credit', $r);
        expect(credit() === 1.00, 'credit exactly unchanged (1.00)', credit());
        $p = purchase($key);
        expect($p !== null && $p['state'] === 'rolled_back' && $p['order_id'] === null, 'purchase rolled back, key released', $p);
        preg_match('/order #(\d+)/', (string) ($p['last_error'] ?? ''), $m);
        $rolledBack = (int) ($m[1] ?? 0);
        expect($rolledBack > 0 && Capsule::table('tblorders')->where('id', $rolledBack)->doesntExist(),
            "the rolled-back order #$rolledBack is gone", $p['last_error'] ?? null);
    } finally {
        setCredit($before, 'restore after insufficient-credit scenario');
    }
    $r = order(REF_ONETIME, $key, $key);
    expect($r['status'] === 200 && ($r['body']['data']['replayed'] ?? null) === false, 'topped up, the same key buys', $r);
    $p2 = purchase($key);
    expect($p2 !== null && (int) $p2['id'] === (int) $p['id'] && $p2['state'] === 'delivered', 'one purchase row for the key, delivered', $p2);
    expect(abs(credit() - ($before - PRICE)) < 0.001, 'charged once', ['before' => $before, 'after' => credit()]);
    terminateOrder(orderIdOf($r));
    break;

// Plan tests 6 and 15 — a failure after payment keeps the paid order; support finishes it.
case 'failure-after-payment':
case 'keyless-failure-after-payment':
    $keyed = $scenario === 'failure-after-payment';
    $reference = "rt-$run-" . ($keyed ? 'fail' : 'fail-keyless');
    $key = $keyed ? $reference : null;
    $productId = hubProductId(REF_ONETIME);
    $farm = (string) Capsule::table('tblproducts')->where('id', $productId)->value('configoption1');
    $before = credit();
    $logFrom = lastModuleLogId();
    $ordersBefore = orderCount();
    try {
        // An access server that rejects the token request: a server farm that does not exist.
        Capsule::table('tblproducts')->where('id', $productId)->update(['configoption1' => '00000000-0000-0000-0000-000000000000']);
        $r = order(REF_ONETIME, $reference, $key);
        expect($r['status'] === 409 && codeOf($r) === 'not_provisioned', 'provisioning fails after payment -> 409 not_provisioned', $r);
        $orderId = orderIdOf($r);
        $service = hubService($orderId);
        $invoiceStatus = (string) Capsule::table('tblinvoices')->where('id', (int) Capsule::table('tblorders')->where('id', $orderId)->value('invoiceid'))->value('status');
        expect($orderId > 0 && $service !== null, "the paid order #$orderId is kept, not deleted", $service);
        expect($invoiceStatus === 'Paid' && abs(credit() - ($before - PRICE)) < 0.001, 'its invoice is Paid, charged once', ['invoice' => $invoiceStatus, 'credit' => credit()]);
        expect(($service['domainstatus'] ?? '') !== 'Active' && serviceProp((int) $service['id'], 'accessTokenId') === '', 'the service is not provisioned', $service);
        $row = Capsule::table(PurchaseRepository::TABLE)->where('order_id', $orderId)->first();
        expect($row !== null && $row->state === 'needs_reconciliation' && strpos((string) $row->last_error, 'HTTP Error: 404') !== false,
            "the purchase needs reconciliation, with WHMCS's own failure reason", $row);
        // The product provisions on payment: that was the one attempt, never a second (AcceptOrder).
        $attempts = Capsule::table('tblactivitylog')
            ->where('description', 'like', 'Module Create Failed - Service ID: ' . (int) $service['id'] . ' -%')->count();
        expect($attempts === 1, "the module ran exactly once for service #{$service['id']} ($attempts)");
        expect(strpos(attentionHtml(), '#' . $row->id . '<br>') !== false, 'the addon page lists it under "needing attention"');

        if ($keyed) {
            $again = order(REF_ONETIME, $reference, $key);
            expect($again['status'] === 409 && codeOf($again) === 'needs_reconciliation', 'a keyed repeat is refused (409 needs_reconciliation), nothing bought', $again);
        } else {
            $link = hub('linkOrder', ['downstreamRef' => REF_ONETIME, 'customerReference' => $reference,
                'idempotencyKey' => "$reference-link", 'upstreamOrderId' => $orderId]);
            expect($link['status'] === 409 && codeOf($link) === 'link_rejected', 'an unfinished keyless order cannot be linked yet (409 link_rejected)', $link);
        }
        expect(orderCount() === $ordersBefore + 1 && abs(credit() - ($before - PRICE)) < 0.001, 'exactly one order and one charge so far');
    } finally {
        Capsule::table('tblproducts')->where('id', $productId)->update(['configoption1' => $farm]);
    }

    // The procedure, step 1: the module log names the failed call.
    $log = storeCreateLog($logFrom);
    $failedTokenPost = $log !== null && strpos((string) $log['response'], 'createAccessToken') !== false
        && strpos((string) $log['response'], 'getAccessCode') === false
        && preg_match('/HTTP Error: (4\d\d)/', (string) $log['request'], $status);
    expect((bool) $failedTokenPost, 'module log: the token request itself was rejected (HTTP ' . ($status[1] ?? '?') . ') — no token was created', $log['request'] ?? null);
    // Step 2: the access server holds no token for this customer and order.
    expect(tokensOf($orderId) === [], 'the access server lists no token for this customer and order');
    // Only then: Create on the service, and Retry.
    $create = localAPI('ModuleCreate', ['serviceid' => (int) $service['id']]);
    expect(($create['result'] ?? '') === 'success', "support presses Create on service #{$service['id']}", $create);
    $tokens = tokensOf($orderId);
    expect(count($tokens) === 1, 'one token now exists', $tokens);
    $message = processor()->retry((int) $row->id);
    expect(strpos($message, 'finished') !== false, 'admin Retry finishes it', $message);
    expect(Capsule::table('tblorders')->where('id', $orderId)->value('status') === 'Active', 'Retry accepted the order without running the module again');
    expect(strpos(attentionHtml(), '#' . $row->id . '<br>') === false, 'it left the attention list');

    if ($keyed) {
        $done = order(REF_ONETIME, $reference, $key);
        expect($done['status'] === 200 && orderIdOf($done) === $orderId && ($done['body']['data']['replayed'] ?? null) === true,
            'the keyed repeat now returns the original order', $done);
    } else {
        $code = hub('getAccessCode', ['upstreamOrderId' => $orderId]);
        expect($code['status'] === 200 && ($code['body']['data']['accessTokenId'] ?? '') === $tokens[0], 'getAccessCode returns its key', $code);
    }
    expect(abs(credit() - ($before - PRICE)) < 0.001 && tokensOf($orderId) === $tokens, 'still one charge and one token');
    terminateOrder($orderId);
    break;

// Plan test 7 — a rollback that cannot be verified keeps the key and goes to a person.
case 'failed-rollback':
    $before = credit();
    $key = "rt-$run-rollback";
    setCredit(1.00, 'failed-rollback scenario');
    touch(FAIL_FLAG);
    try {
        $r = order(REF_ONETIME, $key, $key);
    } finally {
        @unlink(FAIL_FLAG);
    }
    expect($r['status'] === 409 && codeOf($r) === 'needs_reconciliation', 'credit moved during the rollback -> 409 needs_reconciliation', $r);
    $p = purchase($key);
    expect($p !== null && $p['state'] === 'needs_reconciliation' && strpos((string) $p['last_error'], 'credit balance changed') !== false,
        'the purchase keeps its key and says why', $p);
    $again = order(REF_ONETIME, $key, $key);
    expect($again['status'] === 409 && codeOf($again) === 'needs_reconciliation', 'a repeat is refused, nothing bought', $again);
    $message = processor()->release((int) $p['id'], false);
    expect(strpos($message, 'released') !== false && purchase($key)['state'] === 'rolled_back', 'nothing was charged, so Release frees the key', $message);
    setCredit($before, 'restore after failed-rollback scenario');
    break;

// Plan test 13 — a request that died after a step, before saving it: the footprint decides.
case 'crash-resume':
    $key = "rt-$run-crash";
    $r = order(REF_ONETIME, $key, $key);
    $orderId = orderIdOf($r);
    expect($r['status'] === 200 && $orderId > 0, "keyed purchase delivered (order #$orderId)", $r);
    $p = purchase($key);
    $afterPurchase = credit();
    $tokens = tokensOf($orderId);
    expect(count($tokens) === 1, 'one token', $tokens);
    foreach (['created', 'ordered', 'paid'] as $state) {
        setPurchase((int) $p['id'], $state === 'created'
            ? ['state' => $state, 'order_id' => null, 'invoice_id' => null, 'service_id' => null]
            : ['state' => $state]);
        $again = order(REF_ONETIME, $key, $key);
        expect($again['status'] === 200 && orderIdOf($again) === $orderId && ($again['body']['data']['replayed'] ?? null) === true,
            "died after '$state' was saved: the repeat returns the same order", $again);
        expect(credit() === $afterPurchase && tokensOf($orderId) === $tokens && purchase($key)['state'] === 'delivered',
            "... no charge, no second token, delivered");
    }
    // Provably not ordered: a purchase that died before AddOrder ran.
    $fresh = "rt-$run-fresh";
    Capsule::table(PurchaseRepository::TABLE)->insert([
        'partner_id' => $partner['id'], 'client_id' => $clientId, 'idempotency_key' => $fresh, 'customer_reference' => $fresh,
        'product_id' => hubProductId(REF_ONETIME), 'billing_cycle' => 'onetime', 'state' => 'created',
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $new = order(REF_ONETIME, $fresh, $fresh);
    expect($new['status'] === 200 && ($new['body']['data']['replayed'] ?? null) === false && orderIdOf($new) !== $orderId,
        'died before AddOrder (no marked service, no unexplained order): the repeat orders', $new);
    expect(abs(credit() - ($afterPurchase - PRICE)) < 0.001, '... charged once');
    terminateOrder($orderId);
    terminateOrder(orderIdOf($new));
    break;

// Plan test 21 — the token was created but its record lost: adopt it, never create another.
case 'token-record-lost':
    $key = "rt-$run-lost";
    $r = order(REF_ONETIME, $key, $key);
    $orderId = orderIdOf($r);
    $service = hubService($orderId);
    $serviceId = (int) $service['id'];
    $token = serviceProp($serviceId, 'accessTokenId');
    $afterPurchase = credit();
    expect($r['status'] === 200 && $token !== '', "keyed purchase delivered (order #$orderId, token $token)", $r);
    // The shape a failed code read or property save leaves behind.
    saveServiceProps($serviceId, ['accessTokenId' => '']);
    setServiceStatus($serviceId, 'Pending');
    setPurchase((int) purchase($key)['id'], ['state' => 'paid']);
    $again = order(REF_ONETIME, $key, $key);
    expect($again['status'] === 409 && codeOf($again) === 'needs_reconciliation', 'the repeat is refused: the provisioning outcome is unknown', $again);
    expect(tokensOf($orderId) === [$token], 'nothing was created');
    // The procedure, step 2: the access server lists the token of this customer and order.
    $listed = tokensOf($orderId);
    expect(count($listed) === 1, 'the access server lists exactly one token for the order', $listed);
    saveServiceProps($serviceId, ['accessTokenId' => $listed[0]]);
    setServiceStatus($serviceId, 'Active');
    $message = processor()->retry((int) purchase($key)['id']);
    expect(strpos($message, 'finished') !== false, 'adopted: Retry finishes it', $message);
    $done = order(REF_ONETIME, $key, $key);
    expect($done['status'] === 200 && orderIdOf($done) === $orderId && ($done['body']['data']['keys'][0]['accessTokenId'] ?? '') === $token,
        'the repeat returns the original order and token', $done);
    expect(credit() === $afterPurchase && tokensOf($orderId) === [$token], 'one charge, one token, Create never pressed');
    terminateOrder($orderId);
    break;

// Plan tests 17 and 19 — CSV delivery: completion by the batch mark, replay and getAccessCode by type.
case 'csv':
    $productId = (int) Capsule::table('tblproducts')->where('slug', REF_CSV)->value('id')
        ?: (int) Capsule::table('tblproducts_slugs')->where('slug', REF_CSV)->where('active', 1)->value('product_id');
    $mapped = Capsule::table('mod_vpnhood_partner_products')->where('partner_id', $partner['id'])->where('downstream_ref', REF_CSV)->exists();
    if (!$mapped) {
        Capsule::table('mod_vpnhood_partner_products')->insert([
            'partner_id' => $partner['id'], 'downstream_ref' => REF_CSV, 'whmcs_product_id' => $productId,
            'billing_cycle_months' => 1, 'enabled' => 1,
        ]);
    }
    $orders = [];
    try {
        $key = "rt-$run-csv";
        $r = order(REF_CSV, $key, $key);
        $orderId = orderIdOf($r);
        $orders[] = $orderId;
        $csv = (string) ($r['body']['data']['keys'][0]['csv'] ?? '');
        expect($r['status'] === 200 && ($r['body']['data']['keys'][0]['deliveryType'] ?? '') === 'csv' && substr_count(trim($csv), "\n") === 1,
            "keyed CSV purchase delivered as a one-row batch (order #$orderId)", $r);
        $again = order(REF_CSV, $key, $key);
        expect(($again['body']['data']['replayed'] ?? null) === true && ($again['body']['data']['keys'][0]['csv'] ?? '') === $csv,
            'the repeat replays the same batch', $again);
        setPurchase((int) purchase($key)['id'], ['state' => 'paid']);
        $resumed = order(REF_CSV, $key, $key);
        expect($resumed['status'] === 200 && purchase($key)['state'] === 'delivered',
            "reset to 'paid': the batch mark proves provisioning, the repeat completes", $resumed);
        $code = hub('getAccessCode', ['upstreamOrderId' => $orderId]);
        expect($code['status'] === 200 && ($code['body']['data']['deliveryType'] ?? '') === 'csv', 'getAccessCode returns the batch of a CSV order', $code);

        $keyless = order(REF_CSV, "rt-$run-csv-keyless", null);
        $orders[] = orderIdOf($keyless);
        $code = hub('getAccessCode', ['upstreamOrderId' => orderIdOf($keyless)]);
        expect($code['status'] === 200 && ($code['body']['data']['deliveryType'] ?? '') === 'csv',
            'a keyless CSV order is recoverable through getAccessCode too', $code);
    } finally {
        if (!$mapped) {
            Capsule::table('mod_vpnhood_partner_products')->where('partner_id', $partner['id'])->where('downstream_ref', REF_CSV)->delete();
        }
        // Batch keys cannot be terminated (by design): expire and disable them like termination does.
        $api = new \WHMCS\Module\Server\VpnHoodStore\ApiService();
        foreach (array_filter($orders) as $orderId) {
            foreach (tokensOf($orderId) as $tokenId) {
                $api->updateAccessToken($tokenId, ['expirationTime' => ['value' => date('Y-m-d')], 'isEnabled' => ['value' => false]]);
            }
            ok("cleanup: the batch of order #$orderId expired and disabled");
        }
    }
    break;

// Plan test 12 — the client area and admin tab name the partner's reference.
case 'views':
    require_once WEBROOT . '/modules/servers/vpnhoodstore/vpnhoodstore.php';
    $key = "rt-$run-view";
    $r = order(REF_ONETIME, $key, $key);
    $orderId = orderIdOf($r);
    $service = hubService($orderId);
    $model = \WHMCS\Service\Service::find((int) $service['id']);
    $params = [
        'model' => $model, 'serviceid' => (int) $service['id'], 'userid' => $clientId, 'qty' => 1,
        'configoption4' => (string) Capsule::table('tblproducts')->where('id', $model->packageid)->value('configoption4'),
    ];
    $vars = vpnhoodstore_ClientArea($params)['templateVariables'] ?? [];
    expect(($vars['partnerOrderId'] ?? '') === (string) $orderId && ($vars['partnerReference'] ?? '') === $key,
        "client area: \"VpnHood order #$orderId — your reference $key\"", $vars);
    $tab = vpnhoodstore_AdminServicesTabFields($params);
    expect(strpos((string) ($tab['Partner reference'] ?? ''), $key) === 0 && strpos((string) ($tab['Partner purchase'] ?? ''), $key) !== false,
        'admin service tab: reference, key and state', $tab);
    terminateOrder($orderId);
    break;

// Plan test 9 (a) — while initialization is held, a keyed order waits and never buys.
case 'hold-init':
    $name = 'vhhub-' . md5(Capsule::connection()->getDatabaseName() . "\0init");
    Capsule::connection()->selectOne('SELECT GET_LOCK(?, 0) AS a', [$name]);
    sleep((int) ($argv[2] ?? 30));
    exit(0);

case 'init-wait':
    Capsule::table('tblconfiguration')->where('setting', 'VpnHoodPartnerHubPurchasesReady')->delete();
    $ordersBefore = orderCount();
    $started = microtime(true);
    $r = order(REF_ONETIME, "rt-$run-init", "rt-$run-init");
    $elapsed = microtime(true) - $started;
    expect($r['status'] === 409 && codeOf($r) === 'initializing' && $elapsed >= 19,
        sprintf('initialization held elsewhere: the order waits (%.0f s), then 409 initializing', $elapsed), $r);
    expect(orderCount() === $ordersBefore && purchase("rt-$run-init") === null, '... and never buys');
    break;

// Plan test 9 (b) — a back-fill that died midway is resumed by the next request.
case 'init-resume':
    // The rows the back-fill writes: keyless orders the request log answered with 200. (No
    // order runs before initialization finishes, so a real interrupted back-fill has no others.)
    $logged = [];
    foreach (Capsule::table('mod_vpnhood_partner_log')->where('action', 'order')->where('http_status', 200)->pluck('response') as $response) {
        foreach (json_decode((string) $response, true)['keys'] ?? [] as $k) {
            $logged[] = (int) ($k['upstreamOrderId'] ?? 0);
        }
    }
    $backfilled = Capsule::table(PurchaseRepository::TABLE)->whereNull('idempotency_key')->where('state', 'delivered')
        ->whereIn('order_id', $logged);
    $live = (clone $backfilled)->join('tblhosting as h', 'h.id', '=', PurchaseRepository::TABLE . '.service_id')
        ->pluck(PurchaseRepository::TABLE . '.order_id')->map('intval')->all();
    // As if the back-fill died midway: its rows gone, no marker.
    $backfilled->delete();
    Capsule::table('tblconfiguration')->where('setting', 'VpnHoodPartnerHubPurchasesReady')->delete();
    $r = hub('linkOrder', ['downstreamRef' => REF_ONETIME, 'customerReference' => 'x', 'idempotencyKey' => "rt-$run-init-resume", 'upstreamOrderId' => 999999999]);
    expect($r['status'] === 404, 'the next request initializes first', $r);
    $restored = Capsule::table(PurchaseRepository::TABLE)->whereNull('idempotency_key')->where('state', 'delivered')->pluck('order_id')->map('intval')->all();
    expect(array_diff($live, $restored) === [], 'every keyless order with a live service is back (' . count($live) . ')', array_diff($live, $restored));
    expect(Capsule::table('tblconfiguration')->where('setting', 'VpnHoodPartnerHubPurchasesReady')->value('value') === '1', 'the ready marker is set again');
    break;

// Plan test 14 — a renewal and an order compete for credit enough for one.
case 'shared-credit-order':
    $key = "rt-$run-renewable";
    $r = order(REF_RECURRING, $key, $key);
    $orderId = orderIdOf($r);
    expect($r['status'] === 200 && $orderId > 0, "recurring keyed purchase delivered (order #$orderId)", $r);
    $u = localAPI('UpdateClientProduct', ['serviceid' => (int) hubService($orderId)['id'], 'nextduedate' => date('Y-m-d', strtotime('-5 days'))]);
    expect(($u['result'] ?? '') === 'success', 'forced due five days ago (the .sh runs Generate Invoices next)', $u);
    saveResult('shared-credit', ['orderId' => $orderId, 'credit' => credit()]);
    break;

case 'shared-credit-arm':
    $state = loadResult('shared-credit');
    $invoice = (int) (new PartnerRepository())->outstandingRenewalInvoiceId((int) hubService((int) $state['orderId'])['id']);
    expect($invoice > 0, "a renewal invoice is outstanding (#$invoice)");
    setCredit(PRICE, 'shared-credit scenario: enough for one payment');
    saveResult('shared-credit', $state + ['invoice' => $invoice]);
    break;

case 'call-renew':
    saveResult('renew', hub('renew', ['upstreamOrderId' => (int) loadResult('shared-credit')['orderId']]));
    exit(0);

case 'call-order':
    saveResult('order-' . $argv[2], order(REF_ONETIME, $argv[2], $argv[2]));
    exit(0);

case 'shared-credit-check':
    $state = loadResult('shared-credit');
    $renew = loadResult('renew');
    $bought = loadResult('order-' . $argv[2]);
    $statuses = [$renew['status'] ?? 0, $bought['status'] ?? 0];
    sort($statuses);
    expect($statuses === [200, 402], 'renewal and order at once, credit for one: one succeeds, the other 402', ['renew' => $renew, 'order' => $bought]);
    expect(credit() === 0.00, 'credit is exactly 0.00, never negative', credit());
    if (($renew['status'] ?? 0) === 200) {
        expect(Capsule::table('tblinvoices')->where('id', $state['invoice'])->value('status') === 'Paid', 'the renewal invoice is Paid');
    }
    setCredit((float) $state['credit'], 'restore after shared-credit scenario');
    terminateOrder((int) $state['orderId']);
    terminateOrder(orderIdOf($bought));
    break;

// Plan test 8 (race) — two keys claim one keyless order at once: exactly one wins.
case 'link-race-setup':
    $r = order(REF_ONETIME, "rt-$run-race", null);
    expect($r['status'] === 200, 'keyless order to claim (#' . orderIdOf($r) . ')', $r);
    saveResult('race', ['orderId' => orderIdOf($r), 'reference' => "rt-$run-race"]);
    break;

case 'call-link':
    $race = loadResult('race');
    saveResult('link-' . $argv[2], hub('linkOrder', [
        'downstreamRef' => REF_ONETIME, 'customerReference' => $race['reference'], 'idempotencyKey' => $argv[2],
        'upstreamOrderId' => $race['orderId'],
    ]));
    exit(0);

case 'link-race-check':
    $race = loadResult('race');
    $a = loadResult('link-' . $argv[2]);
    $b = loadResult('link-' . $argv[3]);
    $outcomes = [codeOf($a) ?: (string) $a['status'], codeOf($b) ?: (string) $b['status']];
    sort($outcomes);
    expect($outcomes === ['200', 'already_claimed'], 'two keys linking one order at once: one wins, the other 409 already_claimed', [$a, $b]);
    $bound = Capsule::table(PurchaseRepository::TABLE)->where('order_id', $race['orderId'])->value('idempotency_key');
    expect(in_array($bound, [$argv[2], $argv[3]], true), "the order is bound to exactly one key ($bound)");
    terminateOrder((int) $race['orderId']);
    break;

default:
    bad("unknown scenario '$scenario'");
}

finish();
