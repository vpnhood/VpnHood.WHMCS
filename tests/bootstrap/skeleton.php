<?php
/**
 * skeleton.php — idempotent test-environment bootstrap for the dev WHMCS.
 *
 * Runs ON the dev server (uploaded by init-skeleton.sh) with direct DB access,
 * because WHMCS localAPI is unavailable under PHP-CLI on this box. It applies
 * one or more fixture spec files in order — the hub spec from VpnHood.WHMCS and
 * (when present) the connector spec from VpnHood.WHMCS.Partner, since both
 * modules share this dev WHMCS.
 *
 * Spec sections (all optional): requiredModules, addons, productGroup,
 * products, clients, partner. Setting values may reference environment
 * variables as "${NAME}".
 *
 * Create-if-missing only: it never deletes or overwrites existing fixture
 * data, except (a) topping client credit up to the spec minimum, (b) aligning
 * addon settings to the spec, and (c) re-hashing the hub partner secret when
 * it does not match the one supplied.
 *
 * Usage: php skeleton.php <fixtures.json> [more-fixtures.json ...]
 * Env:   TEST_CLIENT_PASSWORD  client-area password for the test users
 *        PARTNER_API_KEY / PARTNER_API_SECRET  hub partner credentials
 *        WHMCS_DEV_URL         hub base url (used by the connector spec)
 *
 * Prints a JSON report; exits non-zero on failure.
 */

error_reporting(E_ALL);
const WEBROOT = '/home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html';

$report = ['created' => [], 'existing' => [], 'updated' => [], 'warnings' => [], 'ids' => []];

function fail(string $msg): never {
    fwrite(STDERR, $msg . "\n");
    exit(1);
}

$paths = array_slice($argv, 1) ?: fail('usage: php skeleton.php <fixtures.json> [more ...]');

require WEBROOT . '/configuration.php';
$db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_username, $db_password);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/** Column metadata cache: table => [column => DESCRIBE row]. */
function cols(PDO $db, string $table): array {
    static $cache = [];
    if (!isset($cache[$table])) {
        $cache[$table] = [];
        foreach ($db->query("DESCRIBE `$table`") as $c) {
            $cache[$table][$c['Field']] = $c;
        }
    }
    return $cache[$table];
}

/**
 * Insert $data into $table, auto-filling any NOT-NULL column that has no
 * default (WHMCS tables have many) with a neutral value for its type.
 */
function insertRow(PDO $db, string $table, array $data): int {
    $row = [];
    foreach (cols($db, $table) as $name => $c) {
        if (array_key_exists($name, $data)) {
            $row[$name] = $data[$name];
            continue;
        }
        if (stripos($c['Extra'], 'auto_increment') !== false) continue;
        if ($c['Null'] === 'YES' || $c['Default'] !== null) continue;
        $t = strtolower($c['Type']);
        if (preg_match('/^enum\(\'([^\']*)\'/', $c['Type'], $m)) {
            $row[$name] = $m[1];
        } elseif (preg_match('/int|decimal|float|double|bit/', $t)) {
            $row[$name] = 0;
        } elseif (str_contains($t, 'datetime') || str_contains($t, 'timestamp')) {
            $row[$name] = date('Y-m-d H:i:s');
        } elseif (str_contains($t, 'date')) {
            $row[$name] = date('Y-m-d');
        } else {
            $row[$name] = '';
        }
    }
    $fields = '`' . implode('`,`', array_keys($row)) . '`';
    $marks = implode(',', array_fill(0, count($row), '?'));
    $db->prepare("INSERT INTO `$table` ($fields) VALUES ($marks)")->execute(array_values($row));
    return (int)$db->lastInsertId();
}

function one(PDO $db, string $sql, array $args = []): ?array {
    $st = $db->prepare($sql);
    $st->execute($args);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

/** Substitute "${NAME}" with getenv(NAME); warn (once) on unset vars. */
function envSubst(string $value, array &$report): string {
    return preg_replace_callback('/\$\{([A-Z0-9_]+)\}/', function ($m) use (&$report) {
        $v = getenv($m[1]);
        if ($v === false) {
            $report['warnings'][] = "env var {$m[1]} not set — substituted empty string";
            return '';
        }
        return $v;
    }, $value);
}

function applySpec(PDO $db, array $spec, array &$report): void {

    // ---------------------------------------------------- module files present?
    foreach ($spec['requiredModules'] ?? [] as $rel) {
        if (is_dir(WEBROOT . '/' . $rel)) {
            $report['existing'][] = "module files '$rel'";
        } else {
            $report['warnings'][] = "module files MISSING: $rel — run scripts/deploy-dev.sh";
        }
    }

    // ------------------------------------------------------------------ addons
    // WHMCS addon activation = module listed in tblconfiguration.ActiveAddonModules
    // + its settings rows in tbladdonmodules (deactivation deletes the settings).
    foreach ($spec['addons'] ?? [] as $addon) {
        $m = $addon['module'];
        $act = one($db, "SELECT value FROM tblconfiguration WHERE setting='ActiveAddonModules'");
        $list = array_filter(explode(',', $act['value'] ?? ''));
        if (!in_array($m, $list, true)) {
            $list[] = $m;
            $db->prepare("UPDATE tblconfiguration SET value=? WHERE setting='ActiveAddonModules'")
               ->execute([implode(',', $list)]);
            $report['created'][] = "addon '$m' activated";
        } else {
            $report['existing'][] = "addon '$m' active";
        }

        foreach ($addon['settings'] as $k => $v) {
            $v = envSubst((string)$v, $report);
            $row = one($db, 'SELECT * FROM tbladdonmodules WHERE module=? AND setting=?', [$m, $k]);
            $shown = in_array($k, ['ApiSecret'], true) ? '<secret>' : $v;
            if (!$row) {
                insertRow($db, 'tbladdonmodules', ['module' => $m, 'setting' => $k, 'value' => $v]);
                $report['created'][] = "addon $m.$k = '$shown'";
            } elseif ((string)$row['value'] !== $v) {
                $db->prepare('UPDATE tbladdonmodules SET value=? WHERE module=? AND setting=?')
                   ->execute([$v, $m, $k]);
                $report['updated'][] = "addon $m.$k → '$shown'";
            }
        }
    }

    // ------------------------------------------------------------ product group
    $gid = null;
    if (!empty($spec['productGroup'])) {
        $g = $spec['productGroup'];
        $group = one($db, 'SELECT * FROM tblproductgroups WHERE slug=?', [$g['slug']]);
        if ($group) {
            $report['existing'][] = "product group '{$g['name']}' (#{$group['id']})";
            $gid = (int)$group['id'];
        } else {
            $gid = insertRow($db, 'tblproductgroups', [
                'name' => $g['name'], 'slug' => $g['slug'], 'hidden' => $g['hidden'],
                'order' => $g['order'], 'disabledgateways' => $g['disabledgateways'],
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $report['created'][] = "product group '{$g['name']}' (#$gid)";
        }
        $report['ids']['group:' . $g['slug']] = $gid;
    }

    // ---------------------------------------------------------------- products
    foreach ($spec['products'] ?? [] as $p) {
        // Products created via the admin UI leave tblproducts.slug empty and only
        // write tblproducts_slugs — check both, or we would create duplicates.
        $prod = one($db,
            'SELECT p.id FROM tblproducts p
             LEFT JOIN tblproducts_slugs s ON s.product_id = p.id AND s.active = 1
             WHERE p.slug = ? OR s.slug = ? LIMIT 1',
            [$p['slug'], $p['slug']]);
        if ($prod) {
            $pid = (int)$prod['id'];
            $report['existing'][] = "product '{$p['slug']}' (#$pid)";
        } else {
            $pid = insertRow($db, 'tblproducts', [
                'gid' => $gid, 'type' => $p['type'], 'name' => $p['name'], 'slug' => $p['slug'],
                'paytype' => $p['paytype'], 'autosetup' => $p['autosetup'],
                'servertype' => $p['servertype'], 'welcomeemail' => $p['welcomeemail'] ?? 0,
                'configoption1' => $p['configoption1'] ?? '', 'configoption2' => $p['configoption2'] ?? '',
                'configoption3' => $p['configoption3'] ?? '', 'configoption4' => $p['configoption4'] ?? '',
                'hidden' => 0, 'retired' => 0, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $report['created'][] = "product '{$p['slug']}' (#$pid, {$p['paytype']}, {$p['price']} USD)";
        }
        $report['ids']['product:' . $p['slug']] = $pid;

        // pricing (currency 1 = USD; onetime products keep their price in `monthly`)
        if (!one($db, "SELECT id FROM tblpricing WHERE type='product' AND currency=1 AND relid=?", [$pid])) {
            insertRow($db, 'tblpricing', [
                'type' => 'product', 'currency' => 1, 'relid' => $pid,
                'monthly' => $p['price'],
                'quarterly' => '-1.00', 'semiannually' => '-1.00', 'annually' => '-1.00',
                'biennially' => '-1.00', 'triennially' => '-1.00',
                'msetupfee' => '0.00', 'qsetupfee' => '0.00', 'ssetupfee' => '0.00',
                'asetupfee' => '0.00', 'bsetupfee' => '0.00', 'tsetupfee' => '0.00',
            ]);
            $report['created'][] = "pricing for '{$p['slug']}' ({$p['price']} USD {$p['paytype']})";
        }

        // store routing slug (tblproducts_slugs drives rp=/store/<group>/<product>)
        if (cols($db, 'tblproducts_slugs') &&
            !one($db, 'SELECT id FROM tblproducts_slugs WHERE product_id=? AND slug=?', [$pid, $p['slug']])) {
            insertRow($db, 'tblproducts_slugs', [
                'product_id' => $pid, 'group_id' => $gid, 'group_slug' => $spec['productGroup']['slug'],
                'slug' => $p['slug'], 'active' => 1,
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $report['created'][] = "store slug '{$spec['productGroup']['slug']}/{$p['slug']}'";
        }
    }

    // ----------------------------------------------------------------- clients
    $clientIds = [];
    foreach ($spec['clients'] ?? [] as $role => $c) {
        $row = one($db, 'SELECT id, credit, groupid FROM tblclients WHERE email=?', [$c['email']]);
        if ($row) {
            $cid = (int)$row['id'];
            $report['existing'][] = "$role client {$c['email']} (#$cid)";
            if (isset($c['groupid']) && (int)$row['groupid'] !== (int)$c['groupid']) {
                $db->prepare('UPDATE tblclients SET groupid=? WHERE id=?')->execute([(int)$c['groupid'], $cid]);
                $report['updated'][] = "$role client group → #{$c['groupid']}";
            }
        } else {
            $cid = insertRow($db, 'tblclients', [
                'firstname' => $c['firstname'], 'lastname' => $c['lastname'],
                'email' => $c['email'], 'status' => 'Active', 'currency' => 1,
                'groupid' => (int)($c['groupid'] ?? 0),
                'country' => 'US', 'datecreated' => date('Y-m-d'), 'credit' => '0.00',
            ]);
            $row = ['credit' => '0.00'];
            $report['created'][] = "$role client {$c['email']} (#$cid)";
        }
        $clientIds[$role] = $cid;
        $report['ids']["client:$role"] = $cid;

        // credit top-up to the spec minimum (never reduced)
        $min = (float)($c['minCredit'] ?? 0);
        if ($min > 0 && (float)$row['credit'] < $min) {
            $delta = $min - (float)$row['credit'];
            $db->prepare('UPDATE tblclients SET credit=? WHERE id=?')->execute([number_format($min, 2, '.', ''), $cid]);
            insertRow($db, 'tblcredit', [
                'clientid' => $cid, 'date' => date('Y-m-d'),
                'description' => 'Test skeleton credit top-up',
                'amount' => number_format($delta, 2, '.', ''),
            ]);
            $report['updated'][] = "$role client credit topped up by $delta to $min USD";
        }

        // client-area login (tblusers + tblusers_clients); optional — warn on failure
        $pass = getenv('TEST_CLIENT_PASSWORD');
        if ($pass) {
            try {
                $u = one($db, 'SELECT id FROM tblusers WHERE email=?', [$c['email']]);
                if (!$u) {
                    $uid = insertRow($db, 'tblusers', [
                        'first_name' => $c['firstname'], 'last_name' => $c['lastname'],
                        'email' => $c['email'], 'password' => password_hash($pass, PASSWORD_DEFAULT),
                        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    $report['created'][] = "$role user login {$c['email']} (#$uid)";
                } else {
                    $uid = (int)$u['id'];
                }
                $uc = cols($db, 'tblusers_clients');
                $userCol = isset($uc['auth_user_id']) ? 'auth_user_id' : 'userid';
                $clientCol = isset($uc['client_id']) ? 'client_id' : 'clientid';
                if (!one($db, "SELECT * FROM tblusers_clients WHERE `$userCol`=? AND `$clientCol`=?", [$uid, $cid])) {
                    insertRow($db, 'tblusers_clients', [
                        $userCol => $uid, $clientCol => $cid, 'owner' => 1,
                        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    $report['created'][] = "$role user↔client link";
                }
            } catch (Throwable $e) {
                $report['warnings'][] = "$role user login not ensured: " . $e->getMessage();
            }
        }
    }

    // ------------------------------------------------------------- hub partner
    if (!empty($spec['partner'])) {
        // module tables (deactivation preserves them; recreate if fully dropped)
        // — schema mirrors vpnhoodpartnerhub_activate(); keep them in sync.
        $db->exec("CREATE TABLE IF NOT EXISTS mod_vpnhood_partners (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL, INDEX (client_id),
            name VARCHAR(255) NOT NULL,
            api_key VARCHAR(64) NOT NULL UNIQUE,
            api_secret_hash VARCHAR(255) NOT NULL,
            status ENUM('active','suspended') NOT NULL DEFAULT 'active',
            ip_allowlist TEXT NULL,
            created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL)");
        $db->exec("CREATE TABLE IF NOT EXISTS mod_vpnhood_partner_products (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            partner_id INT UNSIGNED NOT NULL, INDEX (partner_id),
            downstream_ref VARCHAR(255) NOT NULL, INDEX (downstream_ref),
            whmcs_product_id INT UNSIGNED NOT NULL,
            billing_cycle_months INT UNSIGNED NOT NULL DEFAULT 1,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY partner_ref (partner_id, downstream_ref))");
        $db->exec("CREATE TABLE IF NOT EXISTS mod_vpnhood_partner_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            partner_id INT UNSIGNED NULL, INDEX (partner_id),
            action VARCHAR(64) NULL, remote_ip VARCHAR(64) NULL,
            http_status INT UNSIGNED NULL, request TEXT NULL, response TEXT NULL,
            created_at TIMESTAMP NULL)");

        $apiKey = getenv('PARTNER_API_KEY');
        $apiSecret = getenv('PARTNER_API_SECRET');
        if ($apiKey && $apiSecret) {
            $pt = $spec['partner'];
            $resellerId = $clientIds[$pt['clientRole']];
            $partner = one($db, 'SELECT * FROM mod_vpnhood_partners WHERE api_key=?', [$apiKey]);
            if (!$partner) {
                $partnerId = insertRow($db, 'mod_vpnhood_partners', [
                    'client_id' => $resellerId, 'name' => $pt['name'], 'api_key' => $apiKey,
                    'api_secret_hash' => password_hash($apiSecret, PASSWORD_DEFAULT),
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $report['created'][] = "hub partner '{$pt['name']}' (#$partnerId)";
            } else {
                $partnerId = (int)$partner['id'];
                $report['existing'][] = "hub partner '{$pt['name']}' (#$partnerId)";
                if (!password_verify($apiSecret, $partner['api_secret_hash'])) {
                    $db->prepare('UPDATE mod_vpnhood_partners SET api_secret_hash=? WHERE id=?')
                       ->execute([password_hash($apiSecret, PASSWORD_DEFAULT), $partnerId]);
                    $report['updated'][] = 'hub partner secret re-hashed to match secrets-dev.json';
                }
                if ((int)$partner['client_id'] !== $resellerId) {
                    $report['warnings'][] = "hub partner #$partnerId is linked to client #{$partner['client_id']}, spec expects #$resellerId — left unchanged";
                }
            }
            $report['ids']['partner'] = $partnerId;

            foreach ($pt['productRefs'] as $ref) {
                $pid = $report['ids']['product:' . $ref['downstream_ref']];
                $map = one($db, 'SELECT * FROM mod_vpnhood_partner_products WHERE partner_id=? AND downstream_ref=?',
                    [$partnerId, $ref['downstream_ref']]);
                if (!$map) {
                    insertRow($db, 'mod_vpnhood_partner_products', [
                        'partner_id' => $partnerId, 'downstream_ref' => $ref['downstream_ref'],
                        'whmcs_product_id' => $pid, 'billing_cycle_months' => $ref['billing_cycle_months'],
                        'enabled' => 1,
                    ]);
                    $report['created'][] = "partner product mapping '{$ref['downstream_ref']}' → product #$pid";
                } elseif ((int)$map['whmcs_product_id'] !== $pid) {
                    $db->prepare('UPDATE mod_vpnhood_partner_products SET whmcs_product_id=? WHERE id=?')
                       ->execute([$pid, $map['id']]);
                    $report['updated'][] = "partner product mapping '{$ref['downstream_ref']}' repointed to product #$pid";
                } else {
                    $report['existing'][] = "partner product mapping '{$ref['downstream_ref']}'";
                }
            }
        } else {
            $report['warnings'][] = 'PARTNER_API_KEY / PARTNER_API_SECRET not set — hub partner not ensured';
        }
    }
}

foreach ($paths as $path) {
    $spec = json_decode(@file_get_contents($path), true) ?: fail("cannot parse $path");
    applySpec($db, $spec, $report);
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
