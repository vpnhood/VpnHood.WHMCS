<?php

/**
 * VpnHood! Partner Hub
 *
 * Upstream (wholesale) addon installed on OUR WHMCS. It lets external partners
 * (who run their OWN WHMCS with the "VpnHood Partner" connector module) place
 * orders against our WHMCS on behalf of their own customers, paying from the
 * partner's native WHMCS credit balance. Provisioning is delegated to the
 * existing "vpnhoodstore" server module, which talks to the VpnHood access server.
 *
 * This addon only adds: partner management (auth + allowed products) and a
 * partner-scoped REST API. It does NOT reimplement credit (native WHMCS credit
 * is the spend limit) or provisioning (vpnhoodstore/Helper do that).
 *
 * @see modules/servers/vpnhoodstore/  provisioning path that is reused
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/PartnerRepository.php';

use WHMCS\Module\Addon\VpnHoodPartnerHub\PartnerRepository;

/**
 * Addon configuration / metadata.
 */
function vpnhoodpartnerhub_config(): array
{
    return [
        'name'        => 'VpnHood! Partner Hub',
        'description' => 'Wholesale partner gateway: lets external partner WHMCS installs order and provision VpnHood keys against this WHMCS using the partner\'s native credit balance.',
        'version'     => '1.0.7',
        'author'      => 'VpnHood',
        'fields'      => [
            'RequireIpAllowlist' => [
                'FriendlyName' => 'Require IP Allowlist',
                'Type'         => 'yesno',
                'Description'  => 'When enabled, a partner can only call the API from an IP listed on their record. Partners with an empty allowlist are rejected.',
                'Default'      => 'no',
            ],
            'DefaultCurrency' => [
                'FriendlyName' => 'Reference Currency Code',
                'Type'         => 'text',
                'Size'         => '6',
                'Description'  => 'ISO currency code shown in the admin balance view (informational only). Example: USD',
                'Default'      => 'USD',
            ],
            'OrderGateway' => vpnhoodpartnerhub_gatewayField(),
        ],
    ];
}

/**
 * Build the Order Payment Gateway field.
 *
 * WHMCS's AddOrder takes a gateway's SYSTEM name (banktransfer) while the admin area
 * everywhere else shows its DISPLAY name ("Offline Crypto Transfer"), so typing what you
 * can see is the natural mistake — and it breaks every partner order with "Invalid Payment
 * Method" while leaving this page looking perfectly configured.
 *
 * A free-text box cannot be got right by inspection, so the field is a dropdown of the
 * gateways this install actually has: the wrong value can no longer be entered. The
 * verdict on the CURRENT value is folded into this field's own Description, because that
 * is the only thing rendered on the configuration screen — the addon's own page (where
 * vpnhoodpartnerhub_gatewayWarning() prints) is not where an admin lands after Save.
 *
 * Falls back to the original text box if the gateway list cannot be read, so a
 * configuration screen is never lost to a failed query.
 */
function vpnhoodpartnerhub_gatewayField(): array
{
    $field = [
        'FriendlyName' => 'Order Payment Gateway',
        'Type'         => 'text',
        'Size'         => '25',
        'Description'  => 'System name of an active payment gateway to tag partner order invoices with (e.g. banktransfer). Invoices are still settled from the partner\'s credit balance; this only labels them. Leave blank to use the WHMCS default.',
        'Default'      => '',
    ];

    try {
        $gateways = vpnhoodpartnerhub_activeGateways();
        if (!$gateways) {
            return $field;
        }

        $configured = vpnhoodpartnerhub_configuredGateway();

        $options = ['' => 'Use the WHMCS default'];
        foreach ($gateways as $system => $display) {
            $options[$system] = $display === $system ? $system : $display . ' (' . $system . ')';
        }

        // An unrecognised stored value stays selectable, and named as the fault it is:
        // dropping it from the list would make merely opening this page silently rewrite
        // the setting to something the admin never chose.
        if ($configured !== '' && !isset($gateways[$configured])) {
            $options[$configured] = '⚠ ' . $configured . ' — NOT a payment gateway';
        }

        unset($field['Size']);
        $field['Type'] = 'dropdown';
        $field['Options'] = $options;
        $field['Description'] = 'Tags partner order invoices with this gateway. They are still settled from '
            . 'the partner\'s credit balance; this only labels them.'
            . vpnhoodpartnerhub_gatewayVerdict($configured, $gateways);

        return $field;
    } catch (\Throwable $e) {
        // Never let a decorative query cost the admin their configuration screen.
        return $field;
    }
}

/**
 * Active payment gateways on this install, as system name => display name.
 */
function vpnhoodpartnerhub_activeGateways(): array
{
    $gateways = [];
    foreach (Capsule::table('tblpaymentgateways')->where('setting', 'name')->get() as $row) {
        $system = trim((string) $row->gateway);
        if ($system !== '') {
            $gateways[$system] = trim((string) $row->value) ?: $system;
        }
    }

    return $gateways;
}

/**
 * The currently stored OrderGateway value, read straight from the DB.
 *
 * Read here rather than taken from $vars because _config() is also called before the
 * settings are handed to it, and because after a Save this is the freshly written value.
 */
function vpnhoodpartnerhub_configuredGateway(): string
{
    return trim((string) Capsule::table('tbladdonmodules')
        ->where('module', 'vpnhoodpartnerhub')
        ->where('setting', 'OrderGateway')
        ->value('value'));
}

/**
 * State-of-the-setting line appended to the field description on the config screen.
 *
 * Reports in every case — a missing banner would be ambiguous between "fine" and
 * "the check did not run".
 */
function vpnhoodpartnerhub_gatewayVerdict(string $configured, array $gateways): string
{
    if ($configured === '') {
        return '<br><span style="color:#3c8dbc;">Currently blank &mdash; partner order invoices use the WHMCS '
            . 'default gateway. That is a valid setting.</span>';
    }

    if (isset($gateways[$configured])) {
        return '<br><span style="color:#3c763d;">&#10003; Currently <strong>'
            . htmlspecialchars($configured, ENT_QUOTES) . '</strong> ('
            . htmlspecialchars($gateways[$configured], ENT_QUOTES) . '), an active gateway on this install.</span>';
    }

    return '<br><span style="color:#a94442;"><strong>&#9888; '
        . htmlspecialchars($configured, ENT_QUOTES) . ' is not a payment gateway on this install, so every '
        . 'partner order will fail</strong> with &ldquo;Invalid Payment Method&rdquo;. '
        . vpnhoodpartnerhub_gatewayHint($configured, $gateways) . '</span>';
}

/**
 * Point at the right value: name the system name outright when the configured string is
 * recognisably a display name, otherwise list what this install actually has.
 */
function vpnhoodpartnerhub_gatewayHint(string $configured, array $gateways): string
{
    foreach ($gateways as $system => $display) {
        if (strcasecmp($display, $configured) === 0) {
            $safe = htmlspecialchars($system, ENT_QUOTES);

            return 'That is the <em>display name</em> of the <code>' . $safe . '</code> gateway &mdash; select '
                . '<code>' . $safe . '</code> instead.';
        }
    }

    return 'Active gateway system names on this install: ' . implode(', ', array_map(
        static fn ($n) => '<code>' . htmlspecialchars($n, ENT_QUOTES) . '</code>',
        array_keys($gateways)
    )) . '.';
}

/**
 * Create database tables on activation.
 */
function vpnhoodpartnerhub_activate(): array
{
    try {
        $schema = Capsule::schema();

        if (!$schema->hasTable('mod_vpnhood_partners')) {
            $schema->create('mod_vpnhood_partners', function ($table) {
                $table->increments('id');
                $table->integer('client_id')->unsigned()->index();
                $table->string('name');
                $table->string('api_key', 64)->unique();
                $table->string('api_secret_hash');
                $table->enum('status', ['active', 'suspended'])->default('active');
                $table->text('ip_allowlist')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (!$schema->hasTable('mod_vpnhood_partner_products')) {
            $schema->create('mod_vpnhood_partner_products', function ($table) {
                $table->increments('id');
                $table->integer('partner_id')->unsigned()->index();
                $table->string('downstream_ref')->index(); // partner-facing product identifier
                $table->integer('whmcs_product_id')->unsigned(); // our product (tblproducts.id)
                $table->integer('billing_cycle_months')->unsigned()->default(1);
                $table->boolean('enabled')->default(true);
                $table->unique(['partner_id', 'downstream_ref']);
            });
        }

        if (!$schema->hasTable('mod_vpnhood_partner_log')) {
            $schema->create('mod_vpnhood_partner_log', function ($table) {
                $table->increments('id');
                $table->integer('partner_id')->unsigned()->nullable()->index();
                $table->string('action', 64)->nullable();
                $table->string('remote_ip', 64)->nullable();
                $table->integer('http_status')->unsigned()->nullable();
                $table->text('request')->nullable();
                $table->text('response')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        return [
            'status'      => 'success',
            'description' => 'VpnHood Partner Hub tables created successfully.',
        ];
    } catch (\Throwable $e) {
        return [
            'status'      => 'error',
            'description' => 'Unable to create tables: ' . $e->getMessage(),
        ];
    }
}

/**
 * Deactivation preserves all data. Partners' API keys/secrets, product mappings and the
 * audit log live only in these tables — dropping them here once silently destroyed every
 * partner credential during a routine deactivate/reactivate, breaking all connectors
 * (their stored API keys no longer matched anything). Reactivation is harmless: activate()
 * only creates tables that do not exist. To remove the module's data permanently, drop the
 * mod_vpnhood_partner_log / mod_vpnhood_partner_products / mod_vpnhood_partners tables
 * manually after uninstalling.
 */
function vpnhoodpartnerhub_deactivate(): array
{
    return [
        'status'      => 'success',
        'description' => 'VpnHood Partner Hub deactivated. Partner data and tables were preserved;'
            . ' reactivating restores full operation with the same API credentials.',
    ];
}

/**
 * Per-admin-session CSRF token for this addon's state-changing POST forms.
 * Stored in the WHMCS admin session; compared in constant time on POST.
 */
function vpnhoodpartnerhub_csrfToken(): string
{
    if (empty($_SESSION['vpnhoodpartnerhub_csrf'])) {
        $_SESSION['vpnhoodpartnerhub_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['vpnhoodpartnerhub_csrf'];
}

/** Hidden token field to embed in every POST form. */
function vpnhoodpartnerhub_csrfField(): string
{
    return '<input type="hidden" name="token" value="' . htmlspecialchars(vpnhoodpartnerhub_csrfToken()) . '">';
}

/**
 * Reject a POST whose CSRF token is missing or does not match the session token.
 *
 * @throws \RuntimeException
 */
function vpnhoodpartnerhub_assertCsrf(): void
{
    $token = $_POST['token'] ?? '';
    if (!is_string($token) || $token === '' || empty($_SESSION['vpnhoodpartnerhub_csrf'])
        || !hash_equals((string) $_SESSION['vpnhoodpartnerhub_csrf'], $token)) {
        throw new \RuntimeException('Invalid or expired security token. Please reload the page and try again.');
    }
}

/**
 * Admin area output: partner management UI.
 */
function vpnhoodpartnerhub_output(array $vars): void
{
    $repo = new PartnerRepository();
    $modulelink = $vars['modulelink'];
    $action = $_REQUEST['action'] ?? 'list';
    $notice = '';
    $noticeType = 'info';

    // -- Handle POST actions ------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            vpnhoodpartnerhub_assertCsrf();
            $sub = $_POST['do'] ?? '';
            if ($sub === 'save') {
                $partnerId = (int) ($_POST['id'] ?? 0);
                $clientId = (int) ($_POST['client_id'] ?? 0);
                if ($clientId <= 0) {
                    throw new \RuntimeException('Please select a WHMCS client for this partner.');
                }
                $data = [
                    'client_id'    => $clientId,
                    'name'         => $repo->clientDisplayName($clientId),
                    'status'       => ($_POST['status'] ?? 'active') === 'suspended' ? 'suspended' : 'active',
                    'ip_allowlist' => trim($_POST['ip_allowlist'] ?? ''),
                ];

                if ($partnerId > 0) {
                    $repo->updatePartner($partnerId, $data);
                    $notice = 'Partner updated.';
                } else {
                    $created = $repo->createPartner($data);
                    $partnerId = $created['id'];
                    $notice = 'Partner created. API Key: <code>' . htmlspecialchars($created['api_key'])
                        . '</code> &nbsp; API Secret (shown once): <code>' . htmlspecialchars($created['api_secret'])
                        . '</code>';
                }
                $noticeType = 'success';
                $action = 'edit';
                $_REQUEST['id'] = $partnerId;
            } elseif ($sub === 'regen') {
                $partnerId = (int) $_POST['id'];
                $secret = $repo->regenerateSecret($partnerId);
                $notice = 'New API Secret (shown once): <code>' . htmlspecialchars($secret) . '</code>';
                $noticeType = 'success';
                $action = 'edit';
                $_REQUEST['id'] = $partnerId;
            } elseif ($sub === 'product_add') {
                $partnerId = (int) $_POST['partner_id'];
                $productId = (int) ($_POST['whmcs_product_id'] ?? 0);
                if ($productId <= 0) {
                    throw new \RuntimeException('Please select a product.');
                }
                if ($repo->productMappingExists($partnerId, $productId)) {
                    throw new \RuntimeException('That product is already mapped to this partner.');
                }
                $repo->addProductMapping($partnerId, [
                    'downstream_ref'       => (string) $productId,
                    'whmcs_product_id'     => $productId,
                    'billing_cycle_months' => $repo->productBillingCycleMonths($productId),
                    'enabled'              => 1,
                ]);
                $notice = 'Product added.';
                $noticeType = 'success';
                $action = 'edit';
                $_REQUEST['id'] = $partnerId;
            } elseif ($sub === 'product_delete') {
                $repo->deleteProductMapping((int) $_POST['mapping_id']);
                $notice = 'Product mapping removed.';
                $noticeType = 'success';
                $action = 'edit';
                $_REQUEST['id'] = (int) $_POST['partner_id'];
            } elseif ($sub === 'partner_delete') {
                $repo->deletePartner((int) $_POST['id']);
                $notice = 'Partner removed.';
                $noticeType = 'success';
                $action = 'list';
            }
        } catch (\Throwable $e) {
            $notice = 'Error: ' . htmlspecialchars($e->getMessage());
            $noticeType = 'danger';
        }
    }

    if ($notice !== '') {
        echo '<div class="alert alert-' . $noticeType . '">' . $notice . '</div>';
    }

    echo vpnhoodpartnerhub_gatewayWarning();

    if ($action === 'edit' || $action === 'new') {
        vpnhoodpartnerhub_renderEditForm($repo, $modulelink, (int) ($_REQUEST['id'] ?? 0), $vars);
    } else {
        vpnhoodpartnerhub_renderList($repo, $modulelink);
    }
}

/**
 * Warn when the configured Order Payment Gateway is not a real gateway.
 *
 * WHMCS's AddOrder takes a gateway's SYSTEM name (banktransfer), while the admin
 * area everywhere else shows its DISPLAY name ("Offline Crypto Transfer"), so
 * typing what you can see is the natural mistake — and it breaks every partner
 * order with "Invalid Payment Method" while leaving this page looking perfectly
 * configured. Worse, the failure is invisible downstream: the partner just gets a
 * rejected order.
 *
 * Blank is valid and means "use the WHMCS default", so only a non-empty value is
 * checked.
 */
function vpnhoodpartnerhub_gatewayWarning(): string
{
    try {
        $configured = vpnhoodpartnerhub_configuredGateway();
        if ($configured === '') {
            return '';
        }

        $gateways = vpnhoodpartnerhub_activeGateways();
        if (!$gateways || isset($gateways[$configured])) {
            return '';
        }

        return '<div class="alert alert-danger">'
            . '<strong>Order Payment Gateway is not a valid gateway.</strong> '
            . '<code>' . htmlspecialchars($configured, ENT_QUOTES) . '</code> is not the system name of an active '
            . 'payment gateway, so <strong>every partner order will fail</strong> with '
            . '&ldquo;Invalid Payment Method&rdquo;. '
            . vpnhoodpartnerhub_gatewayHint($configured, $gateways)
            . ' Fix it in <em>Addon Modules &rarr; VpnHood! Partner Hub &rarr; Configure</em>, or clear it to use the '
            . 'WHMCS default.</div>';
    } catch (\Throwable $e) {
        // A warning that cannot be computed must never take the admin page down.
        return '';
    }
}

/**
 * Render the partner list view.
 */
function vpnhoodpartnerhub_renderList(PartnerRepository $repo, string $modulelink): void
{
    $partners = $repo->allPartnersWithBalance();

    echo '<p><a href="' . $modulelink . '&action=new" class="btn btn-primary">+ Add Partner</a></p>';
    echo '<table class="table table-striped"><thead><tr>'
        . '<th>ID</th><th>Client</th><th>API Key</th>'
        . '<th>Status</th><th>Credit Balance</th><th>Products</th><th></th></tr></thead><tbody>';

    if (empty($partners)) {
        echo '<tr><td colspan="7" class="text-center text-muted">No partners yet.</td></tr>';
    }

    foreach ($partners as $p) {
        $badge = $p['status'] === 'active' ? 'success' : 'default';
        $clientUrl = 'clientssummary.php?userid=' . (int) $p['client_id'];
        echo '<tr>'
            . '<td>' . (int) $p['id'] . '</td>'
            . '<td><a href="' . $clientUrl . '">#' . (int) $p['client_id'] . ' '
            . htmlspecialchars($p['client_name']) . '</a></td>'
            . '<td><code>' . htmlspecialchars($p['api_key']) . '</code></td>'
            . '<td><span class="label label-' . $badge . '">' . htmlspecialchars($p['status']) . '</span></td>'
            . '<td>' . htmlspecialchars($p['balance_formatted']) . '</td>'
            . '<td>' . (int) $p['product_count'] . '</td>'
            . '<td><a class="btn btn-sm btn-default" href="' . $modulelink . '&action=edit&id=' . (int) $p['id'] . '">Manage</a> '
            . '<form method="post" action="' . $modulelink . '" style="display:inline"'
            . ' onsubmit="return confirm(\'Delete this partner? Its product mappings and logs are removed. This cannot be undone.\');">'
            . vpnhoodpartnerhub_csrfField()
            . '<input type="hidden" name="do" value="partner_delete">'
            . '<input type="hidden" name="id" value="' . (int) $p['id'] . '">'
            . '<button type="submit" class="btn btn-sm btn-danger">Delete</button></form></td>'
            . '</tr>';
    }

    echo '</tbody></table>';
}

/**
 * Render the create/edit partner form (and product mappings when editing).
 */
function vpnhoodpartnerhub_renderEditForm(PartnerRepository $repo, string $modulelink, int $partnerId, array $vars): void
{
    $partner = $partnerId > 0 ? $repo->getPartner($partnerId) : null;
    $isEdit = $partner !== null;

    echo '<p><a href="' . $modulelink . '" class="btn btn-default btn-sm">&laquo; Back to list</a></p>';
    echo '<h3>' . ($isEdit ? 'Edit Partner' : 'New Partner') . '</h3>';

    echo '<form method="post" action="' . $modulelink . '">';
    echo vpnhoodpartnerhub_csrfField();
    echo '<input type="hidden" name="do" value="save">';
    echo '<input type="hidden" name="id" value="' . ($isEdit ? (int) $partner['id'] : 0) . '">';

    $clients = $repo->whmcsClients();
    $currentClientId = $isEdit ? (int) $partner['client_id'] : 0;
    echo '<div class="form-group"><label>WHMCS Client (holds the credit balance)</label>'
        . '<select id="vh-client-select" name="client_id" class="form-control" required>';
    echo '<option value="">— Select a client —</option>';
    foreach ($clients as $c) {
        $selected = ((int) $c['id'] === $currentClientId) ? ' selected' : '';
        echo '<option value="' . (int) $c['id'] . '"' . $selected . '>' . htmlspecialchars($c['label']) . '</option>';
    }
    echo '</select>'
        . '<p class="help-block">The client account in THIS WHMCS whose credit balance is charged for the'
        . ' partner\'s orders. The partner name is taken from this client.</p></div>';
    // Upgrade to a searchable dropdown when the WHMCS admin select2 is available; a plain select otherwise.
    echo '<script>if (window.jQuery && jQuery.fn.select2) {'
        . ' jQuery(function(){ jQuery("#vh-client-select").select2({width:"100%"}); }); }</script>';
    echo '<div class="form-group"><label>Status</label><select name="status" class="form-control">'
        . '<option value="active"' . ($isEdit && $partner['status'] === 'active' ? ' selected' : '') . '>Active</option>'
        . '<option value="suspended"' . ($isEdit && $partner['status'] === 'suspended' ? ' selected' : '') . '>Suspended</option>'
        . '</select></div>';
    echo '<div class="form-group"><label>IP Allowlist (comma separated, optional)</label>'
        . '<input type="text" name="ip_allowlist" class="form-control" value="'
        . ($isEdit ? htmlspecialchars($partner['ip_allowlist']) : '') . '"></div>';
    echo '<button type="submit" class="btn btn-primary">Save</button>';
    echo '</form>';

    if (!$isEdit) {
        return;
    }

    // -- Credentials --------------------------------------------------------
    echo '<hr><h4>API Credentials</h4>';
    echo '<p>API Key: <code>' . htmlspecialchars($partner['api_key']) . '</code></p>';
    echo '<form method="post" action="' . $modulelink . '" onsubmit="return confirm(\'Regenerate the API secret? The current secret stops working immediately.\');">';
    echo vpnhoodpartnerhub_csrfField();
    echo '<input type="hidden" name="do" value="regen"><input type="hidden" name="id" value="' . (int) $partner['id'] . '">';
    echo '<button type="submit" class="btn btn-warning btn-sm">Regenerate Secret</button>';
    echo '</form>';

    // -- Product mappings ---------------------------------------------------
    echo '<hr><h4>Allowed Products</h4>';
    $mappings = $repo->getProductMappings($partnerId);
    echo '<table class="table table-condensed"><thead><tr>'
        . '<th>Product</th><th>Payment Type</th><th>Available Cycles</th><th>Multi Qty</th><th></th></tr></thead><tbody>';
    if (empty($mappings)) {
        echo '<tr><td colspan="5" class="text-muted">No products mapped. Partner cannot order until at least one is added.</td></tr>';
    }
    foreach ($mappings as $m) {
        $payType = $repo->productPaymentType((int) $m['whmcs_product_id']);
        // Cycles only exist for recurring products; a one-time price lives in the
        // "monthly" pricing column and must not render as a phantom Monthly cycle.
        $cyclesText = '—';
        if ($payType === 'recurring') {
            $cycles = $repo->productAvailableCycles((int) $m['whmcs_product_id']);
            $cyclesText = $cycles ? implode(', ', $cycles) : '—';
        }
        echo '<tr>'
            . '<td>#' . (int) $m['whmcs_product_id'] . ' ' . htmlspecialchars($m['product_name']) . '</td>'
            . '<td>' . htmlspecialchars(vpnhoodpartnerhub_payTypeLabel($payType)) . '</td>'
            . '<td>' . htmlspecialchars($cyclesText) . '</td>'
            . '<td>' . ($repo->productAllowsMultipleQuantities((int) $m['whmcs_product_id']) ? 'Yes' : '—') . '</td>'
            . '<td><form method="post" action="' . $modulelink . '" style="margin:0">'
            . vpnhoodpartnerhub_csrfField()
            . '<input type="hidden" name="do" value="product_delete">'
            . '<input type="hidden" name="partner_id" value="' . (int) $partner['id'] . '">'
            . '<input type="hidden" name="mapping_id" value="' . (int) $m['id'] . '">'
            . '<button class="btn btn-xs btn-danger">Delete</button></form></td>'
            . '</tr>';
    }
    echo '</tbody></table>';

    // Add mapping form: pick a product; its billing cycle is derived automatically.
    $products = $repo->whmcsProducts();
    echo '<form method="post" action="' . $modulelink . '" class="form-inline">';
    echo vpnhoodpartnerhub_csrfField();
    echo '<input type="hidden" name="do" value="product_add"><input type="hidden" name="partner_id" value="' . (int) $partner['id'] . '">';
    echo '<select name="whmcs_product_id" class="form-control" required>';
    echo '<option value="">— Select a product —</option>';
    foreach ($products as $prod) {
        echo '<option value="' . (int) $prod['id'] . '">#' . (int) $prod['id'] . ' ' . htmlspecialchars($prod['name'])
            . ' — ' . htmlspecialchars(vpnhoodpartnerhub_payTypeLabel((string) ($prod['paytype'] ?? ''))) . '</option>';
    }
    echo '</select> ';
    echo '<button class="btn btn-success">Add Product</button>';
    echo '</form>';
}

/** Human label for a WHMCS "Payment Type" (free|onetime|recurring). */
function vpnhoodpartnerhub_payTypeLabel(string $paytype): string
{
    $labels = ['free' => 'Free', 'onetime' => 'One Time', 'recurring' => 'Recurring'];
    return $labels[strtolower(trim($paytype))] ?? 'Recurring';
}
