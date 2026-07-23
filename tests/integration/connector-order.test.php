<?php
/**
 * connector-order.test.php — end-to-end buyer order through the connector.
 *
 * Runs ON the dev server (uploaded by connector-order.test.sh). Flow:
 *   1. buyer orders the connector product (order + service seeded via DB —
 *      WHMCS localAPI is unavailable under PHP-CLI on this box)
 *   2. admin "Create" module command over HTTP triggers
 *      vpnhoodpartner_CreateAccount → HubClient order → the Hub provisions
 *      from the reseller's credit and returns the access code
 *   3. assert: service properties (upstreamServiceId/accessCode), the upstream
 *      service on the hub side, and the reseller credit debit
 *   4. terminate (default) to release the upstream service + access token
 *
 * ⚠ SPENDS reseller credit and provisions a REAL key on the access server
 *   (terminated again at the end unless CONNECTOR_TERMINATE=0).
 *
 * Env: ADMIN_USER, ADMIN_PASS   WHMCS admin login (for the module command)
 *      SITE_URL                 default https://whmcs-dev.vpnhood.com
 *      CONNECTOR_TERMINATE      default 1
 *
 * Prints a JSON report; exits non-zero if any assertion fails.
 */

error_reporting(E_ALL);
const WEBROOT = '/home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html';

const BUYER_EMAIL     = 'test-buyer@vpnhood.com';
const RESELLER_EMAIL  = 'test-reseller@vpnhood.com';
const CONNECTOR_SLUG  = 'partner-one-month-premium-code';
const UPSTREAM_SLUG   = 'reseller-one-month-premium-code';
const UPSTREAM_PRICE  = 2.00;
const LOCAL_PRICE     = '3.00';

$SITE = rtrim(getenv('SITE_URL') ?: 'https://whmcs-dev.vpnhood.com', '/');
$ADMIN_USER = getenv('ADMIN_USER') ?: '';
$ADMIN_PASS = getenv('ADMIN_PASS') ?: '';
$TERMINATE = getenv('CONNECTOR_TERMINATE') !== '0';

$report = ['steps' => [], 'pass' => 0, 'fail' => 0];
function ok(string $msg): void  { global $report; $report['steps'][] = "PASS $msg"; $report['pass']++; }
function bad(string $msg): void { global $report; $report['steps'][] = "FAIL $msg"; $report['fail']++; }
function finish(): never {
    global $report;
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit($report['fail'] > 0 ? 1 : 0);
}

require WEBROOT . '/configuration.php';
$db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_username, $db_password);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function one(PDO $db, string $sql, array $args = []): ?array {
    $st = $db->prepare($sql);
    $st->execute($args);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

/** Insert with neutral values for NOT-NULL-no-default columns (same as skeleton.php). */
function insertRow(PDO $db, string $table, array $data): int {
    static $cache = [];
    if (!isset($cache[$table])) {
        $cache[$table] = [];
        foreach ($db->query("DESCRIBE `$table`") as $c) $cache[$table][$c['Field']] = $c;
    }
    $row = [];
    foreach ($cache[$table] as $name => $c) {
        if (array_key_exists($name, $data)) { $row[$name] = $data[$name]; continue; }
        if (stripos($c['Extra'], 'auto_increment') !== false) continue;
        if ($c['Null'] === 'YES' || $c['Default'] !== null) continue;
        $t = strtolower($c['Type']);
        if (preg_match('/^enum\(\'([^\']*)\'/', $c['Type'], $m)) $row[$name] = $m[1];
        elseif (preg_match('/int|decimal|float|double|bit/', $t)) $row[$name] = 0;
        elseif (str_contains($t, 'datetime') || str_contains($t, 'timestamp')) $row[$name] = date('Y-m-d H:i:s');
        elseif (str_contains($t, 'date')) $row[$name] = date('Y-m-d');
        else $row[$name] = '';
    }
    $fields = '`' . implode('`,`', array_keys($row)) . '`';
    $marks = implode(',', array_fill(0, count($row), '?'));
    $db->prepare("INSERT INTO `$table` ($fields) VALUES ($marks)")->execute(array_values($row));
    return (int)$db->lastInsertId();
}

// ---------------------------------------------------------------- admin client
$COOKIES = tempnam(sys_get_temp_dir(), 'whmcsjar');
function req(string $url, ?array $post = null): array {
    global $COOKIES;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => 1, CURLOPT_FOLLOWLOCATION => 1, CURLOPT_TIMEOUT => 120,
        CURLOPT_COOKIEJAR => $COOKIES, CURLOPT_COOKIEFILE => $COOKIES,
        CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, 1); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $body];
}
function csrfFrom(string $html): string {
    if (preg_match('/name="token"\s+value="([a-f0-9]+)"/i', $html, $m)) return $m[1];
    if (preg_match('/csrfToken\s*=\s*[\'"]([a-f0-9]+)[\'"]/i', $html, $m)) return $m[1];
    return '';
}
function adminLogin(string $site, string $user, string $pass): bool {
    [, $page] = req("$site/admin/login.php");
    $token = csrfFrom($page);
    [, $after] = req("$site/admin/dologin.php", ['username' => $user, 'password' => $pass, 'token' => $token]);
    return !str_contains($after, 'dologin.php') && !str_contains($after, 'Login to WHMCS');
}
/** Run an admin service module command (create/terminate/…) and return the response body. */
function moduleCommand(string $site, int $clientId, int $serviceId, string $op): string {
    [, $page] = req("$site/admin/clientsservices.php?userid=$clientId&id=$serviceId");
    // The service page's runModuleCommand() JS embeds the one valid token for
    // module commands in its request string — the generic page tokens are NOT
    // accepted for modop, so scrape exactly that one.
    $token = preg_match('/&token=([a-f0-9]+)"/', $page, $m) ? $m[1] : csrfFrom($page);
    [, $body] = req("$site/admin/clientsservices.php", [
        'userid' => $clientId, 'id' => $serviceId, 'modop' => $op, 'ajax' => '1', 'token' => $token,
    ]);
    return $body;
}

// -------------------------------------------------------------------- fixtures
$buyer = one($db, 'SELECT id FROM tblclients WHERE email=?', [BUYER_EMAIL]);
$reseller = one($db, 'SELECT id, credit FROM tblclients WHERE email=?', [RESELLER_EMAIL]);
$prod = one($db, "SELECT p.id, p.paytype FROM tblproducts p
    LEFT JOIN tblproducts_slugs s ON s.product_id=p.id AND s.active=1
    WHERE p.slug=? OR s.slug=? LIMIT 1", [CONNECTOR_SLUG, CONNECTOR_SLUG]);
$upstreamProd = one($db, "SELECT p.id FROM tblproducts p
    LEFT JOIN tblproducts_slugs s ON s.product_id=p.id AND s.active=1
    WHERE p.slug=? OR s.slug=? LIMIT 1", [UPSTREAM_SLUG, UPSTREAM_SLUG]);

if (!$buyer || !$reseller || !$prod || !$upstreamProd) { bad('fixtures missing — run tests/bootstrap/init-skeleton.sh first'); finish(); }
if ($ADMIN_USER === '' || $ADMIN_PASS === '') { bad('ADMIN_USER / ADMIN_PASS not set'); finish(); }
ok('fixtures present (buyer #' . $buyer['id'] . ', connector product #' . $prod['id'] . ')');
$creditBefore = (float)$reseller['credit'];

// ------------------------------------------------------- buyer order + service
$orderId = insertRow($db, 'tblorders', [
    'userid' => $buyer['id'], 'ordernum' => (int)(time() . rand(10, 99)),
    'date' => date('Y-m-d H:i:s'), 'amount' => LOCAL_PRICE,
    'paymentmethod' => 'banktransfer', 'status' => 'Pending', 'ipaddress' => '127.0.0.1',
]);
$serviceId = insertRow($db, 'tblhosting', [
    'userid' => $buyer['id'], 'orderid' => $orderId, 'packageid' => $prod['id'],
    'regdate' => date('Y-m-d'), 'nextduedate' => date('Y-m-d'), 'nextinvoicedate' => date('Y-m-d'),
    'firstpaymentamount' => LOCAL_PRICE, 'amount' => LOCAL_PRICE,
    'billingcycle' => $prod['paytype'] === 'onetime' ? 'One Time' : 'Monthly',
    'paymentmethod' => 'banktransfer', 'domainstatus' => 'Pending',
]);
ok("buyer order #$orderId / service #$serviceId seeded");

// --------------------------------------------------------- provision via admin
if (!adminLogin($SITE, $ADMIN_USER, $ADMIN_PASS)) { bad('admin login failed'); finish(); }
ok('admin login');

$resp = moduleCommand($SITE, (int)$buyer['id'], $serviceId, 'create');
$snippet = trim(mb_substr(strip_tags($resp), 0, 200));

// Source of truth is the DB — read the service properties the module persisted.
$props = [];
$rows = $db->prepare(
    "SELECT f.fieldname, v.value FROM tblcustomfieldsvalues v
     JOIN tblcustomfields f ON f.id = v.fieldid
     WHERE v.relid = ? AND f.type = 'product'");
$rows->execute([$serviceId]);
foreach ($rows as $r) {
    $name = strtolower(preg_replace('/\|.*$/', '', $r['fieldname']));
    $props[trim($name)] = $r['value'];
}
$upstreamServiceId = (int)($props['upstreamserviceid'] ?? 0);
$accessCode = (string)($props['accesscode'] ?? '');

if ($upstreamServiceId > 0) ok("serviceProperties.upstreamServiceId = $upstreamServiceId");
else bad("no upstreamServiceId after module create — response: $snippet");
if ($accessCode !== '') ok('serviceProperties.accessCode present (' . strlen($accessCode) . ' chars)');
else bad('serviceProperties.accessCode empty');
if ($report['fail'] > 0) finish();

// -------------------------------------------------------- hub-side assertions
$up = one($db, 'SELECT userid, packageid, domainstatus FROM tblhosting WHERE id=?', [$upstreamServiceId]);
if ($up && (int)$up['userid'] === (int)$reseller['id'] && (int)$up['packageid'] === (int)$upstreamProd['id']
        && $up['domainstatus'] === 'Active') {
    ok("upstream service #$upstreamServiceId Active under reseller, hub product #{$upstreamProd['id']}");
} else {
    bad('upstream service wrong: ' . json_encode($up));
}

$creditAfter = (float)one($db, 'SELECT credit FROM tblclients WHERE id=?', [$reseller['id']])['credit'];
$spent = round($creditBefore - $creditAfter, 2);
if (abs($spent - UPSTREAM_PRICE) < 0.001) ok("reseller credit debited $spent USD ($creditBefore → $creditAfter)");
else bad("reseller credit debit wrong: spent $spent, expected " . UPSTREAM_PRICE);

// Local bookkeeping so the seeded rows look like a completed order.
$db->prepare("UPDATE tblhosting SET domainstatus='Active' WHERE id=?")->execute([$serviceId]);
$db->prepare("UPDATE tblorders SET status='Active' WHERE id=?")->execute([$orderId]);

// ------------------------------------------------------------------- terminate
if ($TERMINATE) {
    moduleCommand($SITE, (int)$buyer['id'], $serviceId, 'terminate');
    $upStatus = one($db, 'SELECT domainstatus FROM tblhosting WHERE id=?', [$upstreamServiceId])['domainstatus'] ?? '?';
    if ($upStatus === 'Terminated') ok('upstream service terminated (access token released)');
    else bad("upstream service not terminated (status: $upStatus)");
    $db->prepare("UPDATE tblhosting SET domainstatus='Terminated' WHERE id=?")->execute([$serviceId]);
} else {
    $report['steps'][] = 'NOTE terminate skipped (CONNECTOR_TERMINATE=0) — service left active';
}

@unlink($COOKIES);
finish();
