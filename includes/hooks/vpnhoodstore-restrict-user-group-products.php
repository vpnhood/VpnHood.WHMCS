<?php

if (!defined('WHMCS')) {
    die('You cannot access this file directly.');
}

use WHMCS\Database\Capsule;

/**
 * Secondary Sidebar
 */
add_hook('ClientAreaSecondarySidebar', 200, function ($secondarySidebar) {
    $settings = get_vpnhoodstore_addon_params();
    $categoriesMenu = $secondarySidebar->getChild('Categories');

    if ($settings && $categoriesMenu) {
        removeMenuBasedOnUserGroup($categoriesMenu, $settings);
    }
});

/**
 * Primary Navbar
 */
add_hook('ClientAreaPrimaryNavbar', 200, function($primaryNavbar) {
    $settings = get_vpnhoodstore_addon_params();
    $storeMenu = $primaryNavbar->getChild('Store');

    if ($settings && $storeMenu) {
        removeMenuBasedOnUserGroup($storeMenu, $settings);
    }
});

/**
 * Silent Cart Guard
 *
 * Guards a BROWSING CUSTOMER's cart only. isResellerUser() asks who is logged in, which is
 * meaningless for an order placed server-side: the Partner Hub calls localAPI('AddOrder')
 * from its api.php, where no client is authenticated, so the check returned false, every
 * restricted product was stripped, and WHMCS failed the order with "No items remain in the
 * cart. Order cannot proceed." — silently, for months. Skip the sources that have no
 * browsing user; keep guarding everything else (including an admin masquerading as a
 * client, where isResellerUser() correctly resolves the masqueraded client).
 */
add_hook('PreCalculateCartTotals', 200, function($vars) {
    $settings = get_vpnhoodstore_addon_params();
    if (!$settings) return;

    if (isServerSidePurchase()) {
        return;
    }

    if (isResellerUser($settings->allowedClientGroups)) {
        return;
    }

    if (empty($_SESSION['cart']['products']) || !is_array($_SESSION['cart']['products'])) {
        return;
    }

    $products = $_SESSION['cart']['products'];
    $pids = array_column($products, 'pid');

    // Optimized: single query
    $productGroups = Capsule::table('tblproducts')
        ->join('tblproductgroups', 'tblproducts.gid', '=', 'tblproductgroups.id')
        ->whereIn('tblproducts.id', $pids)
        ->pluck('tblproductgroups.name', 'tblproducts.id');

    $wasModified = false;

    foreach ($products as $key => $item) {
        $pid = (int)$item['pid'];

        if (!isset($productGroups[$pid])) {
            continue;
        }

        if (in_array($productGroups[$pid], $settings->restrictedProductGroupNames, true)) {
            unset($_SESSION['cart']['products'][$key]);
            $wasModified = true;
        }
    }

    if ($wasModified) {
        $_SESSION['cart']['products'] = array_values($_SESSION['cart']['products']);
        if (empty($_SESSION['cart']['products'])) {
            unset($_SESSION['cart']['products']);
        }
    }
});

/**
 * Menu Filter
 */
function removeMenuBasedOnUserGroup($menuContainer, $settings): void {
    if (!$menuContainer || !$menuContainer->hasChildren()) return;

    $restricted = $settings->restrictedProductGroupNames;
    if (empty($restricted)) return;

    if (isResellerUser($settings->allowedClientGroups)) return;

    foreach ($restricted as $item) {
        if ($menuContainer->getChild($item)) {
            $menuContainer->removeChild($item);
        }
    }
}

/**
 * Load Addon Settings (cached)
 */
function get_vpnhoodstore_addon_params(): object | null {
    static $cached = null;
    if ($cached !== null) return $cached;

    $data = Capsule::table('tbladdonmodules')
        ->where('module', 'vpnhoodconfig')
        ->pluck('value', 'setting');

    if (!isset($data['AllowedClientGroups'], $data['RestrictedProductGroupNames'])) {
        return null;
    }

    $cached = (object)[
        'allowedClientGroups'         => array_map('intval', explode(',', $data['AllowedClientGroups'])),
        'restrictedProductGroupNames' => array_map('trim', explode(',', $data['RestrictedProductGroupNames']))
    ];

    return $cached;
}

/**
 * Check User Group (cached)
 */
function isResellerUser(array $resellerUserGroups): bool {
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    $currentUser = new \WHMCS\Authentication\CurrentUser;
    $client = $currentUser->client();

    return $cached = ($client && in_array((int)$client->groupid, $resellerUserGroups, true));
}

/**
 * Is this order being placed server-side, with no browsing customer behind it?
 *
 * WHMCS stamps the cart with a `WHMCS\Order\OrderPurchaseSource` value. Only ADMIN (an
 * admin ordering in the admin area) and LOCAL_API (localAPI, which is how the Partner Hub
 * places every partner order) have no browsing user; CLIENT, CLIENT_API and an admin
 * masquerading as a client all do, and stay guarded. An unrecognised or missing value is
 * treated as a browsing customer, so the guard fails closed.
 */
function isServerSidePurchase(): bool {
    if (!isset($_SESSION['cart']['orderPurchaseSource'])) {
        return false;
    }

    return in_array((int)$_SESSION['cart']['orderPurchaseSource'], [
        \WHMCS\Order\OrderPurchaseSource::ADMIN,
        \WHMCS\Order\OrderPurchaseSource::LOCAL_API,
    ], true);
}