<?php
/**
 * connector-idempotency.test.php — the connector's side of idempotent ordering, driven the
 * way a partner's WHMCS drives it: localAPI ModuleCreate/ModuleTerminate on the buyer's
 * connector service, relayed to the co-installed Hub. Runs ON the dev server, uploaded by
 * connector-idempotency.test.sh with lib/common.php; the .sh deploys the older connector or
 * Hub releases some scenarios need, and runs the concurrent ones as parallel processes.
 *
 * ⚠ Spends buyer and reseller (test) credit and provisions real tokens; the services it
 * creates are terminated before it exits.
 *
 * Usage: php connector-idempotency.test.php <scenario> [args]; prints a JSON report.
 */

require __DIR__ . '/lib/common.php';

use WHMCS\Database\Capsule;

const CONNECTOR_SLUG = 'partner-one-month-premium-code';
const HUB_PRICE      = 1.75;
const RESULTS_DIR    = '/home/whmcsdev/tmp/vhci';

$buyer = clientByEmail($db, BUYER_EMAIL);
$reseller = clientByEmail($db, RESELLER_EMAIL);
$productId = (int) (one($db, "SELECT p.id FROM tblproducts p LEFT JOIN tblproducts_slugs s ON s.product_id=p.id AND s.active=1
    WHERE p.slug=? OR s.slug=? LIMIT 1", [CONNECTOR_SLUG, CONNECTOR_SLUG])['id'] ?? 0);
if (!$buyer || !$reseller || $productId === 0) {
    bad('fixtures missing — run tests/bootstrap/init-skeleton.sh first');
    finish();
}
$buyerId = (int) $buyer['id'];
$resellerId = (int) $reseller['id'];

// -------------------------------------------------------------------- helpers

function expect(bool $condition, string $message, $detail = null): bool
{
    $condition ? ok($message) : bad($message . ($detail !== null ? ' — ' . json_encode($detail) : ''));
    return $condition;
}

function resellerCredit(): float
{
    global $resellerId;
    return round((float) Capsule::table('tblclients')->where('id', $resellerId)->value('credit'), 2);
}

function resellerOrders(): int
{
    global $resellerId;
    return (int) Capsule::table('tblorders')->where('userid', $resellerId)->count();
}

function prop(int $serviceId, string $name): string
{
    global $db;
    return (string) serviceProperty($db, $serviceId, $name);
}

function saveProps(int $serviceId, array $values): void
{
    \WHMCS\Service\Service::find($serviceId)->serviceProperties->save($values);
}

function status(int $serviceId): string
{
    return (string) Capsule::table('tblhosting')->where('id', $serviceId)->value('domainstatus');
}

function setStatus(int $serviceId, string $status): void
{
    localAPI('UpdateClientProduct', ['serviceid' => $serviceId, 'status' => $status]);
}

/** A buyer order on the connector product, left unpaid so nothing provisions on its own. */
function newBuyerService(): int
{
    global $buyerId, $productId;
    $add = localAPI('AddOrder', [
        'clientid' => $buyerId, 'pid' => $productId, 'billingcycle' => 'onetime', 'paymentmethod' => 'banktransfer',
        'noemail' => true, 'noinvoiceemail' => true,
    ]);
    expect(($add['result'] ?? '') === 'success', 'buyer order placed (service #' . ($add['productids'] ?? '?') . ')', $add);
    return (int) explode(',', (string) ($add['productids'] ?? ''))[0];
}

function create(int $serviceId): array
{
    return localAPI('ModuleCreate', ['serviceid' => $serviceId]);
}

/** The buyer service's Module tab, as the admin sees it. */
function moduleTab(int $serviceId): array
{
    global $buyerId;
    require_once WEBROOT . '/modules/servers/vpnhoodpartner/vpnhoodpartner.php';
    return vpnhoodpartner_AdminServicesTabFields(['serviceid' => $serviceId, 'userid' => $buyerId,
        'model' => \WHMCS\Service\Service::find($serviceId)]);
}

/** "Save Changes" on the service page with the Module tab's reconcile inputs. */
function saveModuleTab(int $serviceId, array $post): void
{
    global $buyerId;
    require_once WEBROOT . '/modules/servers/vpnhoodpartner/vpnhoodpartner.php';
    $_POST = $post;
    vpnhoodpartner_AdminServicesTabFieldsSave(['serviceid' => $serviceId, 'userid' => $buyerId,
        'model' => \WHMCS\Service\Service::find($serviceId)]);
    $_POST = [];
}

function hubFeatures(): ?array
{
    $stored = json_decode((string) Capsule::table('tblconfiguration')->where('setting', 'VpnHoodPartnerHubFeatures')->value('value'), true);
    return is_array($stored) ? (array) ($stored['features'] ?? []) : null;
}

function hubClient(): \WHMCS\Module\Server\VpnHoodPartner\HubClient
{
    require_once WEBROOT . '/modules/servers/vpnhoodpartner/lib/HubClient.php';
    return \WHMCS\Module\Server\VpnHoodPartner\HubClient::fromConfig();
}

function terminate(int $serviceId): void
{
    if ($serviceId <= 0 || !in_array(status($serviceId), ['Active', 'Suspended'], true)) {
        return;
    }
    $r = localAPI('ModuleTerminate', ['serviceid' => $serviceId]);
    expect(($r['result'] ?? '') === 'success', "cleanup: buyer service #$serviceId terminated (upstream too)", $r);
}

/** Terminate an upstream order directly, for orders no buyer service points at any more. */
function terminateUpstream(int $orderId): void
{
    try {
        hubClient()->call('terminate', ['upstreamOrderId' => $orderId]);
        ok("cleanup: upstream order #$orderId terminated");
    } catch (\Throwable $e) {
        bad("cleanup: upstream order #$orderId: " . $e->getMessage());
    }
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

// Plan test 1 — the lost response: Create again returns the same order, no charge.
case 'lost-response':
    $serviceId = newBuyerService();
    $credit = resellerCredit();
    $first = create($serviceId);
    $upstream = prop($serviceId, 'upstreamOrderId');
    $key = prop($serviceId, 'idempotencyKey');
    expect(($first['result'] ?? '') === 'success' && $upstream !== '' && $key !== '', "Create provisions (upstream order #$upstream, key saved first)", $first);
    expect(abs(resellerCredit() - ($credit - HUB_PRICE)) < 0.001, 'the reseller is charged once');
    // What the partner sees after a lost response: no order id, the service still Pending.
    saveProps($serviceId, ['upstreamOrderId' => '', 'accessCode' => '', 'accessTokenId' => '']);
    setStatus($serviceId, 'Pending');
    $again = create($serviceId);
    expect(($again['result'] ?? '') === 'success' && prop($serviceId, 'upstreamOrderId') === $upstream && status($serviceId) === 'Active',
        'Create again recovers the same upstream order and activates the service', $again);
    expect(abs(resellerCredit() - ($credit - HUB_PRICE)) < 0.001 && prop($serviceId, 'idempotencyKey') === $key, '... without a second charge');
    terminate($serviceId);
    break;

// Plan test 2 — two Creates of one service at once: one upstream order.
case 'concurrent-setup':
    $serviceId = newBuyerService();
    saveResult('concurrent', ['serviceId' => $serviceId, 'credit' => resellerCredit(), 'orders' => resellerOrders()]);
    break;

case 'module-create':
    saveResult('create-' . $argv[2], create((int) loadResult('concurrent')['serviceId']));
    exit(0);

case 'concurrent-check':
    $state = loadResult('concurrent');
    $serviceId = (int) $state['serviceId'];
    $results = [loadResult('create-a'), loadResult('create-b')];
    expect(($results[0]['result'] ?? '') === 'success' && ($results[1]['result'] ?? '') === 'success', 'both concurrent Creates succeed', $results);
    expect(resellerOrders() === $state['orders'] + 1 && abs(resellerCredit() - ($state['credit'] - HUB_PRICE)) < 0.001,
        'one upstream order, one charge', ['orders' => resellerOrders() - $state['orders'], 'charged' => $state['credit'] - resellerCredit()]);
    saveResult('concurrent', $state + ['upstream' => prop($serviceId, 'upstreamOrderId'), 'key' => prop($serviceId, 'idempotencyKey')]);
    break;

// Plan test 3 — Terminate, then Create: a new key and a new order; the old key is spent.
case 'terminate-create':
    $state = loadResult('concurrent');
    $serviceId = (int) $state['serviceId'];
    $r = localAPI('ModuleTerminate', ['serviceid' => $serviceId]);
    expect(($r['result'] ?? '') === 'success' && prop($serviceId, 'idempotencyKey') === '', 'Terminate relays and clears the key', $r);
    $credit = resellerCredit();
    $r = create($serviceId);
    $newUpstream = prop($serviceId, 'upstreamOrderId');
    expect(($r['result'] ?? '') === 'success' && $newUpstream !== '' && $newUpstream !== $state['upstream'] && prop($serviceId, 'idempotencyKey') !== $state['key'],
        "Create after Terminate buys a new order (#$newUpstream) under a new key", $r);
    expect(abs(resellerCredit() - ($credit - HUB_PRICE)) < 0.001, '... charged once');
    try {
        hubClient()->call('order', ['downstreamRef' => 'reseller-one-month-premium-code', 'quantity' => 1,
            'customerReference' => (string) $serviceId, 'idempotencyKey' => $state['key']]);
        bad('a late retry with the old key was accepted');
    } catch (\WHMCS\Module\Server\VpnHoodPartner\HubApiException $e) {
        expect($e->getErrorCode() === 'key_spent', 'a late retry with the old key -> 409 key_spent', $e->getMessage());
    }
    terminate($serviceId);
    break;

// Plan tests 8 and 18, part 1 — under the OLD connector: buy, then lose the responses.
case 'legacy-setup':
    $services = [newBuyerService(), newBuyerService()];
    $upstream = [];
    foreach ($services as $serviceId) {
        $r = create($serviceId);
        $upstream[] = (int) prop($serviceId, 'upstreamOrderId');
        expect(($r['result'] ?? '') === 'success' && prop($serviceId, 'idempotencyKey') === '',
            "the old connector orders without a key (service #$serviceId -> order #" . end($upstream) . ')', $r);
        saveProps($serviceId, ['upstreamOrderId' => '', 'accessCode' => '', 'accessTokenId' => '']);
        setStatus($serviceId, 'Pending');
    }
    saveResult('legacy', ['services' => $services, 'upstream' => $upstream]);
    break;

// Part 2 — after upgrading to this connector: reconcile, link, order a new key.
case 'legacy-reconcile':
    $state = loadResult('legacy');
    [$s1, $s2] = $state['services'];
    [$u1, $u2] = $state['upstream'];
    Capsule::table('tblconfiguration')->where('setting', 'VpnHoodPartnerHubFeatures')->delete();
    $credit = resellerCredit();
    $orders = resellerOrders();

    $r = create($s1);
    expect(($r['result'] ?? '') !== 'success' && strpos((string) ($r['message'] ?? ''), "#$u1") !== false && strpos((string) ($r['message'] ?? ''), 'Module tab') !== false,
        'Create of the upgraded service stops: the Hub asks to reconcile order #' . $u1, $r);
    expect(resellerOrders() === $orders && resellerCredit() === $credit, '... nothing bought');
    expect(in_array('idempotency-v1', hubFeatures() ?? [], true), 'with an empty cache, the 409 itself taught the connector idempotency-v1', hubFeatures());
    $tab = moduleTab($s1);
    expect(strpos((string) ($tab['VpnHood reconcile'] ?? ''), 'name="vhLinkOrderId"') !== false && strpos((string) $tab['VpnHood reconcile'], "#$u1") !== false,
        'the Module tab lists the candidate and offers Link', $tab);

    saveModuleTab($s2, ['vhLinkOrderId' => (string) $u1]);
    $r = create($s2);
    expect(($r['result'] ?? '') !== 'success' && strpos((string) ($r['message'] ?? ''), 'different customerReference') !== false,
        "linking another service's order is refused (its reference differs)", $r);

    saveModuleTab($s1, ['vhLinkOrderId' => (string) $u1]);
    $r = create($s1);
    expect(($r['result'] ?? '') === 'success' && prop($s1, 'upstreamOrderId') === (string) $u1 && status($s1) === 'Active',
        "Link, then Create: service #$s1 gets its original order #$u1 back", $r);
    expect(resellerCredit() === $credit && prop($s1, 'hubReconcile') === '' && prop($s1, 'hubLinkOrderId') === '', '... without a charge, reconcile state cleared');

    saveModuleTab($s2, ['vhOrderNewKey' => '1']);
    $r = create($s2);
    $u3 = (int) prop($s2, 'upstreamOrderId');
    expect(($r['result'] ?? '') === 'success' && $u3 > 0 && !in_array($u3, [$u1, $u2], true),
        "\"Order a new key\", then Create: a new purchase (#$u3)", $r);
    expect(abs(resellerCredit() - ($credit - HUB_PRICE)) < 0.001, '... charged once');

    terminate($s1);
    terminate($s2);
    terminateUpstream($u2);
    break;

// Plan tests 11 and 20 — this connector against Hub v1.2.8 (deployed by the .sh).
case 'old-hub':
    require_once WEBROOT . '/modules/servers/vpnhoodpartner/vpnhoodpartner.php';
    expect(in_array('idempotency-v1', hubFeatures() ?? [], true), 'before: the cache says idempotency-v1 (from the new Hub)', hubFeatures());
    hubClient()->call('getBalance');
    expect(hubFeatures() === [] && !hubClient()->supports('idempotency-v1'), 'a valid answer without the header clears it', hubFeatures());

    $timeout = new \WHMCS\Module\Server\VpnHoodPartner\HubApiException('Connection to VpnHood Partner Hub failed: timed out', 0, false);
    $message = vpnhoodpartner_createErrorMessage($timeout, hubClient());
    expect(strpos($message, 'Do not press Create again') !== false, 'after a timeout, the admin is told NOT to press Create again', $message);

    $serviceId = newBuyerService();
    saveProps($serviceId, ['hubReconcile' => json_encode(['code' => 'reconcile', 'at' => date('Y-m-d H:i'), 'details' => ['candidates' => []]])]);
    $tab = (string) (moduleTab($serviceId)['VpnHood reconcile'] ?? '');
    expect(strpos($tab, 'no longer reports idempotency-v1') !== false && strpos($tab, 'vhLinkOrderId') === false,
        'the Module tab offers no Link (linkOrder is never sent to this Hub)', $tab);
    saveProps($serviceId, ['hubReconcile' => '']);

    $credit = resellerCredit();
    $r = create($serviceId);
    $first = prop($serviceId, 'upstreamOrderId');
    expect(($r['result'] ?? '') === 'success' && $first !== '', "a purchase works against the old Hub (order #$first; the key is ignored)", $r);
    saveProps($serviceId, ['upstreamOrderId' => '']);
    setStatus($serviceId, 'Pending');
    create($serviceId);
    $second = prop($serviceId, 'upstreamOrderId');
    expect($second !== '' && $second !== $first && abs(resellerCredit() - ($credit - 2 * HUB_PRICE)) < 0.001,
        "... and a repeat buys again (#$second): why the message says not to press Create", ['first' => $first, 'second' => $second]);
    terminate($serviceId);
    terminateUpstream((int) $first);
    break;

case 'new-hub-again':
    require_once WEBROOT . '/modules/servers/vpnhoodpartner/vpnhoodpartner.php';
    hubClient()->call('getBalance');
    expect(hubClient()->supports('idempotency-v1'), 'back on the new Hub: idempotency-v1 learned again');
    $timeout = new \WHMCS\Module\Server\VpnHoodPartner\HubApiException('Connection to VpnHood Partner Hub failed: timed out', 0, false);
    $message = vpnhoodpartner_createErrorMessage($timeout, hubClient());
    expect(strpos($message, 'returns it without charging twice') !== false, 'after a timeout, the admin is told Create again is safe', $message);
    break;

// Plan test 10 — the OLD connector against this Hub: a repeat buys again, exactly as before.
case 'old-connector-repeat':
    $serviceId = newBuyerService();
    $credit = resellerCredit();
    $r = create($serviceId);
    $first = prop($serviceId, 'upstreamOrderId');
    expect(($r['result'] ?? '') === 'success' && $first !== '' && prop($serviceId, 'idempotencyKey') === '',
        "the old connector buys through the new Hub (order #$first)", $r);
    $r = create($serviceId);
    $second = prop($serviceId, 'upstreamOrderId');
    expect(($r['result'] ?? '') === 'success' && $second !== $first && abs(resellerCredit() - ($credit - 2 * HUB_PRICE)) < 0.001,
        "a repeated Create buys again (#$second), as it always did", $r);
    terminate($serviceId);
    terminateUpstream((int) $first);
    break;

default:
    bad("unknown scenario '$scenario'");
}

finish();
