<?php

// Fired only when the plugin is deleted from the Plugins screen, not on deactivation.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';

if (!file_exists($autoload)) {
    return;
}

require_once $autoload;

PosSync\Uninstaller::run();
