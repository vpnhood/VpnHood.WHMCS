<?php

function vpnhoodconfig_config() {
    return [
        'name' => 'VpnHood! MANAGER Configuration',
        'description' => 'Global settings for VpnHood! MANAGER module',
        'version' => '1.0',
        'author' => 'VpnHood',
        'fields' => [
            'APIKey' => [
                'FriendlyName' => 'API Key',
                'Type' => 'text',
                'Size' => '50',
                'Description' => 'Enter your VpnHood! MANAGER API Key',
                'Default' => ''
            ],
            'ProjectId' => [
                'FriendlyName' => 'Project ID',
                'Type' => 'text',
                'Size' => '50',
                'Description' => 'Enter your VpnHood! MANAGER project id (e.g. a8397485-783b-415e-aaba-098b1f45d9d0)',
                'Default' => ''
            ]
        ]
    ];
}

function vpnhoodconfig_activate() {
    // No database setup needed since we're using built-in config fields
}

function vpnhoodconfig_deactivate() {
    // No cleanup needed
}

function vpnhoodconfig_output($vars) {
    echo '<p>Config this addon from the <strong>System Settings → Addon Modules → VpnHood! MANAGER Configuration</strong></p>';
}