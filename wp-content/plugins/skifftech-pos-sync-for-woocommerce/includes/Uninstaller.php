<?php

declare(strict_types=1);

namespace PosSync;

/**
 * Runs when the plugin is deleted from the Plugins screen (not on mere
 * deactivation). Removes everything the plugin created: OAuth tables,
 * the generated RSA/encryption keys, and any leftover options.
 */
class Uninstaller
{
    public static function run(): void
    {
        self::dropTables();
        self::deleteKeys();
        delete_option('pos_sync_api_key');
    }

    private static function dropTables(): void
    {
        global $wpdb;

        foreach ([Tables::refreshTokens(), Tables::accessTokens(), Tables::clients()] as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
    }

    private static function deleteKeys(): void
    {
        $dir = Keys::directory();

        if (!is_dir($dir)) {
            return;
        }

        foreach (['private.pem', 'public.pem', 'encryption.key', '.htaccess', 'index.php'] as $file) {
            $path = $dir . '/' . $file;
            if (file_exists($path)) {
                unlink($path);
            }
        }

        @rmdir($dir);
    }
}
