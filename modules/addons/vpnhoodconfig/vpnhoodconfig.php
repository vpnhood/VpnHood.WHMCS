<?php

function vpnhoodconfig_config()
{
    return [
        'name'        => 'VpnHood! MANAGER Configuration',
        'description' => 'Global settings for VpnHood! MANAGER module',
        'version'     => '1.0.2',
        'author'      => 'VpnHood',

        'fields' => [

            'APIKey' => [
                'FriendlyName' => 'API Key',
                'Type'         => 'text',
                'Size'         => '50',
                'Description'  => 'Enter your VpnHood! MANAGER API Key',
                'Default'      => ''
            ],

            'ProjectId' => [
                'FriendlyName' => 'Project ID',
                'Type'         => 'text',
                'Size'         => '50',
                'Description'  => 'Enter your VpnHood! MANAGER project id',
                'Default'      => ''
            ],

            'AllowedClientGroups' => [
                'FriendlyName' => 'Allowed Client Groups ّfor Restricted Products',
                'Type'         => 'text',
                'Size'         => '50',
                'Description'  => 'Enter client group IDs separated by commas. Example: 1,3,5',
                'Default'      => ''
            ],

            'RestrictedProductGroupNames' => [
                'FriendlyName' => 'Restricted Product Group Names',
                'Type'         => 'text',
                'Size'         => '50',
                'Description'  => 'Enter product group names separated by commas. Example: Product 1,Product 2',
                'Default'      => ''
            ],
        ]
    ];
}

function vpnhoodconfig_activate() {}
function vpnhoodconfig_deactivate() {}

function vpnhoodconfig_output($vars)
{
    echo '<p>Configure this addon from the <strong>System Settings → Addon Modules → VpnHood! MANAGER Configuration</strong></p>';
    echo vpnhoodconfig_moduleVersions();
}

/**
 * Show the installed version of every VpnHood module.
 *
 * WHMCS displays a version for *addon* modules on its own (from _config), but has
 * no equivalent for provisioning modules — so vpnhoodstore would otherwise report
 * its version nowhere. Server-module versions are read from the module's
 * whmcs.json manifest, which scripts/set-version.sh keeps in step with the repo
 * tag on every release.
 */
function vpnhoodconfig_moduleVersions(): string
{
    $modulesDir = dirname(__DIR__, 2); // .../modules

    // Provisioning modules only — addons report their own version to WHMCS.
    // vpnhoodpartner is the connector (from the VpnHood.WHMCS.Partner repo); it is
    // normally absent here and simply gets skipped when it is.
    $modules = [
        'VpnHood Store (provisioning)'             => $modulesDir . '/servers/vpnhoodstore/whmcs.json',
        'VpnHood Partner Connector (provisioning)' => $modulesDir . '/servers/vpnhoodpartner/whmcs.json',
    ];

    $rows = '';
    foreach ($modules as $label => $manifest) {
        $version = vpnhoodconfig_manifestVersion($manifest);
        if ($version === null) {
            continue; // module not installed on this WHMCS
        }
        $rows .= '<tr><td>' . htmlspecialchars($label, ENT_QUOTES) . '</td>'
               . '<td><code>' . htmlspecialchars($version, ENT_QUOTES) . '</code></td></tr>';
    }

    $config = vpnhoodconfig_config();
    $rows .= '<tr><td>VpnHood! MANAGER Configuration (addon)</td>'
           . '<td><code>' . htmlspecialchars($config['version'], ENT_QUOTES) . '</code></td></tr>';

    return '<h3>Installed module versions</h3>'
         . '<table class="table table-condensed" style="width:auto;">'
         . '<thead><tr><th>Module</th><th>Version</th></tr></thead>'
         . '<tbody>' . $rows . '</tbody></table>';
}

/** Read the "version" key from a module's whmcs.json, or null if not installed. */
function vpnhoodconfig_manifestVersion(string $manifest): ?string
{
    if (!is_readable($manifest)) {
        return null;
    }

    // WHMCS' own tooling writes these manifests, sometimes with a UTF-8 BOM.
    $raw = (string) file_get_contents($manifest);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return null;
    }

    return isset($json['version']) ? (string) $json['version'] : 'unversioned';
}