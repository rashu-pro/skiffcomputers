<?php

declare(strict_types=1);

namespace PosSync\Admin;

use PosSync\Tables;

class ClientsPage
{
    private const SLUG = 'pos-sync-oauth-clients';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
    }

    public static function addMenu(): void
    {
        add_management_page(
            'POS Sync OAuth Clients',
            'POS Sync Clients',
            'manage_options',
            self::SLUG,
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'pos-sync'));
        }

        $newCredentials = null;

        if (isset($_POST['pos_sync_client_name']) && check_admin_referer('pos_sync_create_client')) {
            $name = sanitize_text_field(wp_unslash($_POST['pos_sync_client_name']));

            if ($name !== '') {
                $newCredentials = self::createClient($name);
            }
        }

        if (isset($_GET['revoke']) && check_admin_referer('pos_sync_revoke_client')) {
            self::revokeClient(sanitize_text_field(wp_unslash($_GET['revoke'])));
            echo '<div class="notice notice-success"><p>' . esc_html__('Client revoked.', 'pos-sync') . '</p></div>';
        }

        self::renderPage($newCredentials);
    }

    /**
     * @return array{client_id: string, client_secret: string}
     */
    private static function createClient(string $name): array
    {
        global $wpdb;

        $clientId = 'pos_' . bin2hex(random_bytes(16));
        $clientSecret = bin2hex(random_bytes(32));

        $wpdb->insert(
            Tables::clients(),
            [
                'client_id'   => $clientId,
                'name'        => $name,
                'secret_hash' => password_hash($clientSecret, PASSWORD_DEFAULT),
                'created_at'  => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%s']
        );

        return ['client_id' => $clientId, 'client_secret' => $clientSecret];
    }

    private static function revokeClient(string $clientId): void
    {
        global $wpdb;

        $wpdb->update(
            Tables::clients(),
            ['revoked_at' => current_time('mysql', true)],
            ['client_id' => $clientId],
            ['%s'],
            ['%s']
        );
    }

    /**
     * @param array{client_id: string, client_secret: string}|null $newCredentials
     */
    private static function renderPage(?array $newCredentials): void
    {
        global $wpdb;

        $table = Tables::clients();
        $clients = $wpdb->get_results("SELECT client_id, name, created_at, revoked_at FROM {$table} ORDER BY created_at DESC");

        echo '<div class="wrap"><h1>' . esc_html__('POS Sync OAuth Clients', 'pos-sync') . '</h1>';

        if ($newCredentials) {
            echo '<div class="notice notice-success"><p><strong>' .
                esc_html__('Save these credentials now — the secret will not be shown again.', 'pos-sync') .
                '</strong></p>';
            echo '<p>' . esc_html__('Client ID:', 'pos-sync') . ' <code>' . esc_html($newCredentials['client_id']) . '</code></p>';
            echo '<p>' . esc_html__('Client Secret:', 'pos-sync') . ' <code>' . esc_html($newCredentials['client_secret']) . '</code></p>';
            echo '</div>';
        }

        echo '<h2>' . esc_html__('Create new client', 'pos-sync') . '</h2>';
        echo '<form method="post">';
        wp_nonce_field('pos_sync_create_client');
        echo '<input type="text" name="pos_sync_client_name" placeholder="' .
            esc_attr__('Client name (e.g. Main POS terminal)', 'pos-sync') .
            '" required class="regular-text" /> ';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Generate client', 'pos-sync') . '</button>';
        echo '</form>';

        echo '<h2>' . esc_html__('Existing clients', 'pos-sync') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>' .
            '<th>' . esc_html__('Client ID', 'pos-sync') . '</th>' .
            '<th>' . esc_html__('Name', 'pos-sync') . '</th>' .
            '<th>' . esc_html__('Created', 'pos-sync') . '</th>' .
            '<th>' . esc_html__('Status', 'pos-sync') . '</th>' .
            '<th></th></tr></thead><tbody>';

        foreach ($clients as $client) {
            $isRevoked = !empty($client->revoked_at);
            $revokeUrl = wp_nonce_url(
                add_query_arg(['page' => self::SLUG, 'revoke' => $client->client_id], admin_url('tools.php')),
                'pos_sync_revoke_client'
            );

            echo '<tr>';
            echo '<td><code>' . esc_html($client->client_id) . '</code></td>';
            echo '<td>' . esc_html($client->name) . '</td>';
            echo '<td>' . esc_html($client->created_at) . '</td>';
            echo '<td>' . ($isRevoked ? esc_html__('Revoked', 'pos-sync') : esc_html__('Active', 'pos-sync')) . '</td>';
            echo '<td>' . (
                $isRevoked
                    ? ''
                    : '<a href="' . esc_url($revokeUrl) . '" onclick="return confirm(\'' .
                        esc_js(__('Revoke this client?', 'pos-sync')) . '\');">' .
                        esc_html__('Revoke', 'pos-sync') . '</a>'
            ) . '</td>';
            echo '</tr>';
        }

        if (empty($clients)) {
            echo '<tr><td colspan="5">' . esc_html__('No clients yet.', 'pos-sync') . '</td></tr>';
        }

        echo '</tbody></table></div>';
    }
}
