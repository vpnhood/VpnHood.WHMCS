<?php

function vpnhoodconfig_config()
{
    return [
        'name'        => 'VpnHood! MANAGER Configuration',
        'description' => 'Global settings for VpnHood! MANAGER module',
        'version'     => '1.0',
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
}