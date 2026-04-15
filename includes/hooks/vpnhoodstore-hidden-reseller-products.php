<?php

if (!defined('WHMCS')) {
    die('You cannot access this file directly.');
}

use WHMCS\Database\Capsule;

/**
 * Helper to process menu filtering logic
 */
function removeMenuBasedOnUserGroup($menuContainer, $settings) {
    if (is_null($menuContainer) || !$menuContainer->hasChildren()) {
        return;
    }

    $currentUser = new \WHMCS\Authentication\CurrentUser;
    $client = $currentUser->client();

    $resellerMenus = $settings->restrictedProductGroupNames;
    $isAllowedClient = $client && in_array((int)$client->groupid, $settings->allowedClientGroups, true);

    if (!$isAllowedClient) {
        // CASE 1: Guest/Regular - Remove reseller items
        foreach ($resellerMenus as $item) {
            if (!is_null($menuContainer->getChild($item))) {
                $menuContainer->removeChild($item);
            }
        }
    } else {
        // CASE 2: Reseller - Keep ONLY reseller items
        // We collect names first to avoid modification issues during iteration
        $toRemove = [];
        foreach ($menuContainer->getChildren() as $child) {
            $name = $child->getName();
            if (!in_array($name, $resellerMenus)) {
                $toRemove[] = $name;
            }
        }

        foreach ($toRemove as $name) {
            $menuContainer->removeChild($name);
        }
    }
}

/**
 * Hook for Secondary Sidebar (Categories)
 */
add_hook('ClientAreaSecondarySidebar', 200, function ($secondarySidebar) {
    $settings = get_vpnhoodstore_addon_params();
    $categoriesMenu = $secondarySidebar->getChild('Categories');

    if ($settings && $categoriesMenu) {
        // 'Categories' is the standard internal name for the product group sidebar
        removeMenuBasedOnUserGroup($categoriesMenu, $settings);
    }
});

/**
 * Hook for Primary Navbar (Store/Categories)
 */
add_hook('ClientAreaPrimaryNavbar', 200, function($primaryNavbar) {
    $settings = get_vpnhoodstore_addon_params();
    $storeMenu = $primaryNavbar->getChild('Store');

    if ($settings && $storeMenu) {
        // Note: This filters the dropdown items under 'Store'
        removeMenuBasedOnUserGroup($storeMenu, $settings);
    }
});



/**
 * Settings Helper
 */
function get_vpnhoodstore_addon_params(): object | null {
    // Using a static variable to cache settings so it only queries the DB once
    // per page load, even if both hooks are called.
    static $cachedSettings = null;

    if ($cachedSettings !== null) {
        return $cachedSettings;
    }

    $data = Capsule::table('tbladdonmodules')
        ->where('module', 'vpnhoodconfig')
        ->pluck('value', 'setting');

    $keyGroups = 'AllowedClientGroups';
    $keyProducts = 'RestrictedProductGroupNames';

    if (empty($data[$keyGroups]) || empty($data[$keyProducts])) {
        return null;
    }

    $cachedSettings = (object)[
        'allowedClientGroups'         => array_map('intval', explode(',', $data[$keyGroups])),
        'restrictedProductGroupNames' => array_map('trim', explode(',', $data[$keyProducts]))
    ];

    return $cachedSettings;
}