<?php

/**
 * xiProx Cloud — language strings. WHMCS server modules keep these minimal;
 * most user-facing text lives in templates/overview.tpl. Provided so the module
 * folder is i18n-ready for translators.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$_LANG = [
    'xiprox_start' => 'Start',
    'xiprox_stop' => 'Stop',
    'xiprox_restart' => 'Restart',
    'xiprox_reinstall' => 'Reinstall',
    'xiprox_rotate_ip' => 'Rotate IP',
    'xiprox_open_panel' => 'Open Control Panel',
    'xiprox_not_provisioned' => 'This service is not provisioned yet.',
    'xiprox_suspended_notice' => 'Service suspended — your provider has not cleared their bill with the datacenter.',
];
