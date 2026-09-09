<?php

declare(strict_types=1);

namespace PosSync;

class Tables
{
    public static function clients(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'pos_sync_oauth_clients';
    }

    public static function accessTokens(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'pos_sync_oauth_access_tokens';
    }

    public static function refreshTokens(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'pos_sync_oauth_refresh_tokens';
    }
}
