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

add_hook('PreCalculateCartTotals', 1, function($vars) {
    $settings = get_vpnhoodstore_addon_params();

    // Exit if settings are missing or session cart has no products
    if (!$settings || empty($_SESSION['cart']['products'])) {
        return;
    }

    $currentUser = new \WHMCS\Authentication\CurrentUser;
    $client = $currentUser->client();

    // Check if the current client belongs to the authorized Reseller groups
    $isAllowedClient = $client && in_array((int)$client->groupid, $settings->allowedClientGroups, true);

    $wasModified = false;

    foreach ($_SESSION['cart']['products'] as $key => $item) {
        $pid = (int)$item['pid'];

        // Get product group name to check restrictions
        $productData = Capsule::table('tblproducts')
            ->join('tblproductgroups', 'tblproducts.gid', '=', 'tblproductgroups.id')
            ->where('tblproducts.id', $pid)
            ->select('tblproductgroups.name as group_name')
            ->first();

        if ($productData) {
            $isRestricted = in_array($productData->group_name, $settings->restrictedProductGroupNames);

            /**
             * Enforcement Logic:
             * - If user is a Reseller but the product is Regular -> Remove
             * - If user is Regular but the product is Restricted -> Remove
             */
            $isResellerBuyingRegular = ($isAllowedClient && !$isRestricted);
            $isRegularBuyingRestricted = (!$isAllowedClient && $isRestricted);

            if ($isResellerBuyingRegular || $isRegularBuyingRestricted) {
                unset($_SESSION['cart']['products'][$key]);
                $wasModified = true;
            }
        }
    }

    // If products were removed, re-index the array to prevent gaps in keys
    if ($wasModified) {
        $_SESSION['cart']['products'] = array_values($_SESSION['cart']['products']);

        // If no products remain, clean up the session key
        if (empty($_SESSION['cart']['products'])) {
            unset($_SESSION['cart']['products']);
        }
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