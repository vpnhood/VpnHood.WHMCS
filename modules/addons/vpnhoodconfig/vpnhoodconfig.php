<?php

function vpnhoodconfig_config()
{
    return [
        'name'        => 'VpnHood! MANAGER Configuration',
        'description' => 'Global settings for VpnHood! MANAGER module',
        'version'     => '1.2.4',
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

    // The check ships with every VpnHood package, at the same path in each, so this
    // page works whether or not any other package is installed alongside.
    $check = ROOTDIR . '/modules/widgets/vpnhoodupdates.php';
    if (!is_readable($check)) {
        return;
    }
    require_once $check;

    // "Check now" is a plain link on purpose: it only refetches a public version
    // number and rewrites a cache row, so the worst a forged click can achieve is
    // an early refresh. Anything that WROTE to the install would need a token.
    if (isset($_GET['vhcheck'])) {
        VpnHoodUpdateCheck::refresh(true);
        echo '<div class="infobox">Checked just now.</div>';
    }

    $status = VpnHoodUpdateCheck::status();
    echo '<h3>Installed VpnHood packages</h3>'
       . VpnHoodUpdateCheck::renderTable($status)
       . '<p class="text-muted">' . VpnHoodUpdateCheck::lastCheckedText($status)
       . ' &nbsp; <a href="addonmodules.php?module=vpnhoodconfig&vhcheck=1" class="btn btn-default btn-xs">Check now</a></p>';
}
