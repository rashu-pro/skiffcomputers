<?php

declare(strict_types=1);

namespace PosSync;

use RuntimeException;

class Keys
{
    public static function directory(): string
    {
        $upload = wp_upload_dir();

        return trailingslashit($upload['basedir']) . 'pos-sync-keys';
    }

    public static function privateKeyPath(): string
    {
        return self::directory() . '/private.pem';
    }

    public static function publicKeyPath(): string
    {
        return self::directory() . '/public.pem';
    }

    public static function encryptionKeyPath(): string
    {
        return self::directory() . '/encryption.key';
    }

    public static function ensureGenerated(): void
    {
        $dir = self::directory();

        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        self::protectDirectory($dir);

        if (!file_exists(self::privateKeyPath()) || !file_exists(self::publicKeyPath())) {
            self::generateRsaKeyPair();
        }

        if (!defined('POS_SYNC_ENCRYPTION_KEY') && !file_exists(self::encryptionKeyPath())) {
            self::generateEncryptionKey();
        }
    }

    public static function encryptionKey(): string
    {
        if (defined('POS_SYNC_ENCRYPTION_KEY') && POS_SYNC_ENCRYPTION_KEY !== '') {
            return POS_SYNC_ENCRYPTION_KEY;
        }

        return trim((string) file_get_contents(self::encryptionKeyPath()));
    }

    private static function protectDirectory(string $dir): void
    {
        $htaccess = $dir . '/.htaccess';

        if (!file_exists($htaccess)) {
            file_put_contents(
                $htaccess,
                "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
            );
        }

        $index = $dir . '/index.php';

        if (!file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
    }

    private static function generateRsaKeyPair(): void
    {
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        // On hosts without OPENSSL_CONF set (e.g. Windows/Laragon) openssl_pkey_new()
        // fails to find its config; point it at the copy bundled with the plugin.
        $opensslConf = __DIR__ . '/openssl.cnf';
        if (file_exists($opensslConf)) {
            $config['config'] = $opensslConf;
        }

        $resource = openssl_pkey_new($config);

        if ($resource === false) {
            throw new RuntimeException('Unable to generate OAuth2 RSA key pair: ' . openssl_error_string());
        }

        openssl_pkey_export($resource, $privateKeyPem, null, $config);
        $details = openssl_pkey_get_details($resource);

        file_put_contents(self::privateKeyPath(), $privateKeyPem);
        file_put_contents(self::publicKeyPath(), $details['key']);

        chmod(self::privateKeyPath(), 0600);
        chmod(self::publicKeyPath(), 0644);
    }

    private static function generateEncryptionKey(): void
    {
        file_put_contents(self::encryptionKeyPath(), base64_encode(random_bytes(32)));
        chmod(self::encryptionKeyPath(), 0600);
    }
}
