<?php

declare(strict_types=1);

namespace PosSync;

class Activator
{
    public static function activate(): void
    {
        self::createTables();
        Keys::ensureGenerated();

        // Hard cutover from the old static bearer token scheme.
        delete_option('pos_sync_api_key');
    }

    private static function createTables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();

        $clients = Tables::clients();
        $accessTokens = Tables::accessTokens();
        $refreshTokens = Tables::refreshTokens();

        dbDelta("CREATE TABLE {$clients} (
  client_id varchar(80) NOT NULL,
  name varchar(191) NOT NULL,
  secret_hash varchar(255) NOT NULL,
  created_at datetime NOT NULL,
  revoked_at datetime DEFAULT NULL,
  PRIMARY KEY  (client_id)
) {$charsetCollate};");

        dbDelta("CREATE TABLE {$accessTokens} (
  access_token_id varchar(100) NOT NULL,
  client_id varchar(80) NOT NULL,
  expires_at datetime NOT NULL,
  revoked tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (access_token_id),
  KEY client_id (client_id)
) {$charsetCollate};");

        dbDelta("CREATE TABLE {$refreshTokens} (
  refresh_token_id varchar(100) NOT NULL,
  access_token_id varchar(100) NOT NULL,
  expires_at datetime NOT NULL,
  revoked tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (refresh_token_id),
  KEY access_token_id (access_token_id)
) {$charsetCollate};");
    }
}
