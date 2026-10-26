<?php
/**
 * DEV-ONLY test hook. low-credit-alert.test.sh installs it into includes/hooks and removes it on
 * exit; it never ships.
 *
 * While the flag file exists, sending the low-credit email takes 10 s longer: a slow mail server,
 * which must not delay the Hub's answer to the order that crossed the threshold.
 */
if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

add_hook('EmailPreSend', 1, function ($vars) {
    if (($vars['messagename'] ?? '') === 'VpnHood Partner Low Credit' && is_file('/home/whmcsdev/tmp/vhtest-slow-mail')) {
        sleep(10);
    }
});
