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
        'version'     => '1.0.0',
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
            'OrderGateway' => [
                'FriendlyName' => 'Order Payment Gateway',
                'Type'         => 'text',
                'Size'         => '25',
                'Description'  => 'System name of an active payment gateway to tag partner order invoices with (e.g. banktransfer). Invoices are still settled from the partner\'s credit balance; this only labels them. Leave blank to use the WHMCS default.',
                'Default'      => '',
            ],
        ],
    ];
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
 * Drop database tables on deactivation.
 */
function vpnhoodpartnerhub_deactivate(): array
{
    try {
        $schema = Capsule::schema();
        $schema->dropIfExists('mod_vpnhood_partner_log');
        $schema->dropIfExists('mod_vpnhood_partner_products');
        $schema->dropIfExists('mod_vpnhood_partners');

        return [
            'status'      => 'success',
            'description' => 'VpnHood Partner Hub tables removed.',
        ];
    } catch (\Throwable $e) {
        return [
            'status'      => 'error',
            'description' => 'Unable to drop tables: ' . $e->getMessage(),
        ];
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
            $sub = $_POST['do'] ?? '';
            if ($sub === 'save') {
                $partnerId = (int) ($_POST['id'] ?? 0);
                $data = [
                    'client_id'    => (int) $_POST['client_id'],
                    'name'         => trim($_POST['name'] ?? ''),
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
                $repo->addProductMapping($partnerId, [
                    'downstream_ref'       => trim($_POST['downstream_ref']),
                    'whmcs_product_id'     => (int) $_POST['whmcs_product_id'],
                    'billing_cycle_months' => max(1, (int) $_POST['billing_cycle_months']),
                    'enabled'              => isset($_POST['enabled']) ? 1 : 0,
                ]);
                $notice = 'Product mapping added.';
                $noticeType = 'success';
                $action = 'edit';
                $_REQUEST['id'] = $partnerId;
            } elseif ($sub === 'product_delete') {
                $repo->deleteProductMapping((int) $_POST['mapping_id']);
                $notice = 'Product mapping removed.';
                $noticeType = 'success';
                $action = 'edit';
                $_REQUEST['id'] = (int) $_POST['partner_id'];
            }
        } catch (\Throwable $e) {
            $notice = 'Error: ' . htmlspecialchars($e->getMessage());
            $noticeType = 'danger';
        }
    }

    if ($notice !== '') {
        echo '<div class="alert alert-' . $noticeType . '">' . $notice . '</div>';
    }

    if ($action === 'edit' || $action === 'new') {
        vpnhoodpartnerhub_renderEditForm($repo, $modulelink, (int) ($_REQUEST['id'] ?? 0), $vars);
    } else {
        vpnhoodpartnerhub_renderList($repo, $modulelink);
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
        . '<th>ID</th><th>Name</th><th>WHMCS Client</th><th>API Key</th>'
        . '<th>Status</th><th>Credit Balance</th><th>Products</th><th></th></tr></thead><tbody>';

    if (empty($partners)) {
        echo '<tr><td colspan="8" class="text-center text-muted">No partners yet.</td></tr>';
    }

    foreach ($partners as $p) {
        $badge = $p['status'] === 'active' ? 'success' : 'default';
        echo '<tr>'
            . '<td>' . (int) $p['id'] . '</td>'
            . '<td>' . htmlspecialchars($p['name']) . '</td>'
            . '<td>#' . (int) $p['client_id'] . ' ' . htmlspecialchars($p['client_name']) . '</td>'
            . '<td><code>' . htmlspecialchars($p['api_key']) . '</code></td>'
            . '<td><span class="label label-' . $badge . '">' . htmlspecialchars($p['status']) . '</span></td>'
            . '<td>' . htmlspecialchars($p['balance_formatted']) . '</td>'
            . '<td>' . (int) $p['product_count'] . '</td>'
            . '<td><a class="btn btn-sm btn-default" href="' . $modulelink . '&action=edit&id=' . (int) $p['id'] . '">Manage</a></td>'
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
    echo '<input type="hidden" name="do" value="save">';
    echo '<input type="hidden" name="id" value="' . ($isEdit ? (int) $partner['id'] : 0) . '">';
    echo '<div class="form-group"><label>Partner Name</label>'
        . '<input type="text" name="name" class="form-control" required value="'
        . ($isEdit ? htmlspecialchars($partner['name']) : '') . '"></div>';
    echo '<div class="form-group"><label>WHMCS Client ID (holds the credit balance)</label>'
        . '<input type="number" name="client_id" class="form-control" required value="'
        . ($isEdit ? (int) $partner['client_id'] : '') . '">'
        . '<p class="help-block">The client account in THIS WHMCS whose credit balance is charged for the partner\'s orders.</p></div>';
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
    echo '<input type="hidden" name="do" value="regen"><input type="hidden" name="id" value="' . (int) $partner['id'] . '">';
    echo '<button type="submit" class="btn btn-warning btn-sm">Regenerate Secret</button>';
    echo '</form>';

    // -- Product mappings ---------------------------------------------------
    echo '<hr><h4>Allowed Products</h4>';
    $mappings = $repo->getProductMappings($partnerId);
    echo '<table class="table table-condensed"><thead><tr>'
        . '<th>Downstream Ref</th><th>Our Product</th><th>Cycle (months)</th><th>Enabled</th><th></th></tr></thead><tbody>';
    if (empty($mappings)) {
        echo '<tr><td colspan="5" class="text-muted">No products mapped. Partner cannot order until at least one is added.</td></tr>';
    }
    foreach ($mappings as $m) {
        echo '<tr>'
            . '<td><code>' . htmlspecialchars($m['downstream_ref']) . '</code></td>'
            . '<td>#' . (int) $m['whmcs_product_id'] . ' ' . htmlspecialchars($m['product_name']) . '</td>'
            . '<td>' . (int) $m['billing_cycle_months'] . '</td>'
            . '<td>' . ($m['enabled'] ? 'Yes' : 'No') . '</td>'
            . '<td><form method="post" action="' . $modulelink . '" style="margin:0">'
            . '<input type="hidden" name="do" value="product_delete">'
            . '<input type="hidden" name="partner_id" value="' . (int) $partner['id'] . '">'
            . '<input type="hidden" name="mapping_id" value="' . (int) $m['id'] . '">'
            . '<button class="btn btn-xs btn-danger">Delete</button></form></td>'
            . '</tr>';
    }
    echo '</tbody></table>';

    // Add mapping form
    $products = $repo->whmcsProducts();
    echo '<form method="post" action="' . $modulelink . '" class="form-inline">';
    echo '<input type="hidden" name="do" value="product_add"><input type="hidden" name="partner_id" value="' . (int) $partner['id'] . '">';
    echo '<input type="text" name="downstream_ref" class="form-control" placeholder="Downstream ref (e.g. vpn-monthly)" required> ';
    echo '<select name="whmcs_product_id" class="form-control" required>';
    foreach ($products as $prod) {
        echo '<option value="' . (int) $prod['id'] . '">#' . (int) $prod['id'] . ' ' . htmlspecialchars($prod['name']) . '</option>';
    }
    echo '</select> ';
    echo '<input type="number" name="billing_cycle_months" class="form-control" value="1" min="1" style="width:90px"> ';
    echo '<label><input type="checkbox" name="enabled" checked> Enabled</label> ';
    echo '<button class="btn btn-success">Add Product</button>';
    echo '</form>';
}
