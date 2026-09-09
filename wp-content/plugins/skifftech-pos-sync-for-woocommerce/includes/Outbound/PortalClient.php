<?php

declare(strict_types=1);

namespace PosSync\Outbound;

use WP_Error;

/**
 * OAuth2 client-credentials client for calling the POS portal's own API
 * (WooCommerce -> POS direction). Base URL and credentials come from
 * constants defined in wp-config.php.
 */
class PortalClient
{
    private const TOKEN_TRANSIENT = 'pos_sync_outbound_access_token';
    private const EXPIRY_BUFFER_SECONDS = 30;
    private const MIN_TTL_SECONDS = 60;
    private const FALLBACK_TTL_SECONDS = 300;

    public static function baseUrl(): string
    {
        return defined('POS_SYNC_OUTBOUND_BASE_URL') ? rtrim(POS_SYNC_OUTBOUND_BASE_URL, '/') : '';
    }

    /**
     * Performs an authenticated request against the POS portal API, adding
     * the Bearer access token and retrying once with a fresh token on a 401.
     *
     * @param array<string, mixed> $args wp_remote_get()/wp_remote_post() args (without Authorization header)
     *
     * @return array|WP_Error wp_remote_* response, or WP_Error if no token could be obtained
     */
    public static function request(string $method, string $path, array $args = [])
    {
        $token = self::getAccessToken();

        if ($token === null) {
            return new WP_Error('pos_sync_outbound_auth_failed', 'Unable to authenticate with the POS portal.');
        }

        $url = self::baseUrl() . $path;
        $response = self::send($method, $url, self::withAuthHeader($args, $token));

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 401) {
            delete_transient(self::TOKEN_TRANSIENT);
            $token = self::getAccessToken();

            if ($token !== null) {
                $response = self::send($method, $url, self::withAuthHeader($args, $token));
            }
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array|WP_Error
     */
    private static function send(string $method, string $url, array $args)
    {
        return $method === 'POST' ? wp_remote_post($url, $args) : wp_remote_get($url, $args);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private static function withAuthHeader(array $args, string $token): array
    {
        $args['headers'] = array_merge($args['headers'] ?? [], ['Authorization' => 'Bearer ' . $token]);

        return $args;
    }

    private static function getAccessToken(): ?string
    {
        $cached = get_transient(self::TOKEN_TRANSIENT);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return self::requestNewToken();
    }

    private static function requestNewToken(): ?string
    {
        if (
            !defined('POS_SYNC_OUTBOUND_BASE_URL')
            || !defined('POS_SYNC_OUTBOUND_CLIENT_ID')
            || !defined('POS_SYNC_OUTBOUND_CLIENT_SECRET')
            || POS_SYNC_OUTBOUND_CLIENT_SECRET === ''
        ) {
            error_log('POS Sync: Outbound OAuth2 credentials are not configured in wp-config.php');

            return null;
        }

        $response = wp_remote_post(self::baseUrl() . '/oauth/token', [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body'    => [
                'grant_type'    => 'client_credentials',
                'client_id'     => POS_SYNC_OUTBOUND_CLIENT_ID,
                'client_secret' => POS_SYNC_OUTBOUND_CLIENT_SECRET,
            ],
        ]);

        if (is_wp_error($response)) {
            error_log('POS Sync: Outbound token request failed - ' . $response->get_error_message());

            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($data['access_token'])) {
            error_log('POS Sync: Outbound token request returned HTTP ' . $code . ' - ' . wp_remote_retrieve_body($response));

            return null;
        }

        $ttl = isset($data['expires_in'])
            ? max(self::MIN_TTL_SECONDS, (int) $data['expires_in'] - self::EXPIRY_BUFFER_SECONDS)
            : self::FALLBACK_TTL_SECONDS;

        set_transient(self::TOKEN_TRANSIENT, $data['access_token'], $ttl);

        return $data['access_token'];
    }
}
