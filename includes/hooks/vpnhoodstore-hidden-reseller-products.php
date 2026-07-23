<?php
/**
 * VpnHood Store - Product Visibility & Permission Enforcement
 *
 * Multi-layered restriction system for WHMCS 9+:
 *   Layer 1: Navigation & sidebar menu filtering
 *   Layer 2: Store/cart page access control (redirect)
 *   Layer 3: Cart add validation (error message)
 *   Layer 4: Cart contents cleanup (silent removal, safety net)
 *   Layer 5: Checkout validation (final enforcement)
 *
 * Two-way logic:
 *   - Allowed client groups see ONLY restricted product groups
 *   - All other clients (including guests) see ONLY non-restricted product groups
 *
 * Configuration is read from the vpnhoodconfig addon module settings:
 *   - AllowedClientGroups: comma-separated client group IDs
 *   - RestrictedProductGroupNames: comma-separated product group names
 */

if (!defined('WHMCS')) {
    die('You cannot access this file directly.');
}

use WHMCS\Database\Capsule;

// =====================================================================
// HELPER FUNCTIONS
// =====================================================================

/**
 * Load addon settings (cached per request).
 */
function get_vpnhoodstore_addon_params(): object|null
{
    static $cachedSettings = null;

    if ($cachedSettings !== null) {
        return $cachedSettings;
    }

    $data = Capsule::table('tbladdonmodules')
        ->where('module', 'vpnhoodconfig')
        ->pluck('value', 'setting');

    $keyGroups   = 'AllowedClientGroups';
    $keyProducts = 'RestrictedProductGroupNames';

    if (empty($data[$keyGroups]) || empty($data[$keyProducts])) {
        return null;
    }

    $cachedSettings = (object) [
        'allowedClientGroups'         => array_map('intval', explode(',', $data[$keyGroups])),
        'restrictedProductGroupNames' => array_map('trim', explode(',', $data[$keyProducts])),
    ];

    return $cachedSettings;
}

/**
 * Check whether the current client belongs to an allowed group (cached per request).
 */
function vpnhood_is_client_allowed(): bool
{
    static $result = null;
    if ($result !== null) {
        return $result;
    }

    $settings = get_vpnhoodstore_addon_params();
    if (!$settings) {
        $result = false;
        return false;
    }

    $currentUser = new \WHMCS\Authentication\CurrentUser;
    $client      = $currentUser->client();
    $result      = $client && in_array((int) $client->groupid, $settings->allowedClientGroups, true);

    return $result;
}

/**
 * Check whether a product belongs to a restricted product group.
 */
function vpnhood_is_product_restricted(int $pid): bool
{
    static $cache = [];
    if (isset($cache[$pid])) {
        return $cache[$pid];
    }

    $settings = get_vpnhoodstore_addon_params();
    if (!$settings) {
        return $cache[$pid] = false;
    }

    $groupName = Capsule::table('tblproducts')
        ->join('tblproductgroups', 'tblproducts.gid', '=', 'tblproductgroups.id')
        ->where('tblproducts.id', $pid)
        ->value('tblproductgroups.name');

    return $cache[$pid] = $groupName && in_array($groupName, $settings->restrictedProductGroupNames);
}

/**
 * Check whether a product group is restricted by its ID.
 */
function vpnhood_is_group_restricted(int $gid): bool
{
    static $cache = [];
    if (isset($cache[$gid])) {
        return $cache[$gid];
    }

    $settings = get_vpnhoodstore_addon_params();
    if (!$settings) {
        return $cache[$gid] = false;
    }

    $groupName = Capsule::table('tblproductgroups')
        ->where('id', $gid)
        ->value('name');

    return $cache[$gid] = $groupName && in_array($groupName, $settings->restrictedProductGroupNames);
}

/**
 * Determine if the current client should be denied access to a product/group.
 *
 * Two-way enforcement:
 *   - Non-allowed client accessing restricted item  → deny
 *   - Allowed client accessing non-restricted item  → deny
 */
function vpnhood_should_deny(bool $isRestricted): bool
{
    $isAllowed = vpnhood_is_client_allowed();
    return (!$isAllowed && $isRestricted) || ($isAllowed && !$isRestricted);
}

/**
 * Ordering enforcement (layers 3–5) applies ONLY to client-area web requests.
 *
 * WHMCS defines CLIENTAREA solely in client-facing entry scripts (cart.php,
 * clientarea.php, …). Server-side order flows — admin-placed orders, cron, and
 * localAPI AddOrder as used by the vpnhoodpartnerhub addon — run without a
 * client session; filtering them silently empties the cart and breaks
 * legitimate provisioning ("No items remain in the cart").
 */
function vpnhood_cart_enforcement_applies(): bool
{
    return defined('CLIENTAREA');
}

// =====================================================================
// LAYER 1 – NAVIGATION & SIDEBAR FILTERING
// =====================================================================

/**
 * Filter children of a menu container based on client group.
 */
function removeMenuBasedOnUserGroup($menuContainer, $settings)
{
    if (is_null($menuContainer) || !$menuContainer->hasChildren()) {
        return;
    }

    $restrictedNames = $settings->restrictedProductGroupNames;
    $isAllowed       = vpnhood_is_client_allowed();

    if (!$isAllowed) {
        // Regular / guest – remove restricted items
        foreach ($restrictedNames as $item) {
            if (!is_null($menuContainer->getChild($item))) {
                $menuContainer->removeChild($item);
            }
        }
    } else {
        // Allowed client – keep ONLY restricted items
        $toRemove = [];
        foreach ($menuContainer->getChildren() as $child) {
            if (!in_array($child->getName(), $restrictedNames)) {
                $toRemove[] = $child->getName();
            }
        }
        foreach ($toRemove as $name) {
            $menuContainer->removeChild($name);
        }
    }
}

add_hook('ClientAreaSecondarySidebar', 200, function ($secondarySidebar) {
    $settings      = get_vpnhoodstore_addon_params();
    $categoriesMenu = $secondarySidebar->getChild('Categories');

    if ($settings && $categoriesMenu) {
        removeMenuBasedOnUserGroup($categoriesMenu, $settings);
    }
});

add_hook('ClientAreaPrimaryNavbar', 200, function ($primaryNavbar) {
    $settings  = get_vpnhoodstore_addon_params();
    $storeMenu = $primaryNavbar->getChild('Store');

    if ($settings && $storeMenu) {
        removeMenuBasedOnUserGroup($storeMenu, $settings);
    }
});

// =====================================================================
// LAYER 2 – STORE / CART PAGE ACCESS CONTROL
//   a) Filter product group listings on the main store page
//   b) Filter individual products within a group page
//   c) Redirect if the client tries to browse a restricted group or
//      product directly via URL
// =====================================================================

add_hook('ClientAreaPageCart', 1, function ($vars) {
    $settings = get_vpnhoodstore_addon_params();
    if (!$settings) {
        return;
    }

    // --- 2c: Redirect on direct gid/pid URL access ---

    $gid = isset($_GET['gid']) ? (int) $_GET['gid'] : 0;
    if ($gid > 0 && vpnhood_should_deny(vpnhood_is_group_restricted($gid))) {
        vpnhood_redirect_away();
    }

    $pid = isset($_GET['pid']) ? (int) $_GET['pid'] : 0;
    if ($pid > 0 && vpnhood_should_deny(vpnhood_is_product_restricted($pid))) {
        vpnhood_redirect_away();
    }

    $overrides = [];

    // --- 2a: Filter product groups on the store landing page ---

    if (!empty($vars['productgroups'])) {
        $filtered = [];
        foreach ($vars['productgroups'] as $group) {
            $groupId   = (int) ($group['gid'] ?? $group['id'] ?? 0);
            $groupName = $group['name'] ?? '';

            $isRestricted = in_array($groupName, $settings->restrictedProductGroupNames);
            if ($groupId > 0 && !$isRestricted) {
                $isRestricted = vpnhood_is_group_restricted($groupId);
            }

            if (!vpnhood_should_deny($isRestricted)) {
                $filtered[] = $group;
            }
        }
        $overrides['productgroups'] = $filtered;
    }

    // --- 2b: Filter individual products within a group page ---

    if (!empty($vars['products'])) {
        $filtered = [];
        foreach ($vars['products'] as $product) {
            $productId = (int) ($product['pid'] ?? $product['id'] ?? 0);
            if ($productId > 0 && vpnhood_should_deny(vpnhood_is_product_restricted($productId))) {
                continue;
            }
            $filtered[] = $product;
        }
        $overrides['products'] = $filtered;
    }

    return $overrides;
});

/**
 * Safe redirect to the WHMCS client area home page.
 */
function vpnhood_redirect_away(): void
{
    $systemUrl = rtrim(\WHMCS\Config\Setting::getValue('SystemURL'), '/');
    header('Location: ' . $systemUrl . '/index.php');
    exit;
}

// =====================================================================
// LAYER 3 – CART ADD VALIDATION
// Return a human-readable error when an unauthorised client tries to
// add a restricted product to the cart (covers AJAX & form POSTs).
// =====================================================================

add_hook('ShoppingCartValidateProductUpdate', 1, function ($vars) {
    if (!vpnhood_cart_enforcement_applies()) {
        return [];
    }
    $settings = get_vpnhoodstore_addon_params();
    if (!$settings) {
        return [];
    }

    $pid = (int) ($vars['pid'] ?? ($_REQUEST['pid'] ?? 0));
    if ($pid <= 0) {
        return [];
    }

    if (vpnhood_should_deny(vpnhood_is_product_restricted($pid))) {
        return ['You do not have permission to order this product.'];
    }

    return [];
});

// =====================================================================
// LAYER 4 – CART CONTENTS CLEANUP  (silent safety net)
// Silently removes any restricted products that somehow ended up in the
// session cart (e.g. session tampering, race conditions).
// =====================================================================

add_hook('PreCalculateCartTotals', 1, function ($vars) {
    if (!vpnhood_cart_enforcement_applies()) {
        return;
    }
    $settings = get_vpnhoodstore_addon_params();

    if (!$settings || empty($_SESSION['cart']['products'])) {
        return;
    }

    $wasModified = false;

    foreach ($_SESSION['cart']['products'] as $key => $item) {
        $pid          = (int) $item['pid'];
        $isRestricted = vpnhood_is_product_restricted($pid);

        if (vpnhood_should_deny($isRestricted)) {
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

// =====================================================================
// LAYER 5 – CHECKOUT VALIDATION  (final enforcement)
// Even if all other layers somehow miss it, block the actual checkout.
// =====================================================================

add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
    if (!vpnhood_cart_enforcement_applies()) {
        return [];
    }
    $settings = get_vpnhoodstore_addon_params();
    if (!$settings || empty($_SESSION['cart']['products'])) {
        return [];
    }

    foreach ($_SESSION['cart']['products'] as $item) {
        $pid = (int) $item['pid'];
        if (vpnhood_should_deny(vpnhood_is_product_restricted($pid))) {
            return ['Your cart contains products you are not authorized to purchase. Please remove them and try again.'];
        }
    }

    return [];
});