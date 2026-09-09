<?php

declare(strict_types=1);

namespace PosSync\Rest;

use League\OAuth2\Server\Exception\OAuthServerException;
use PosSync\OAuth\ServerFactory;
use PosSync\Psr7Bridge;
use WP_REST_Response;

class TokenController
{
    public static function register(): void
    {
        register_rest_route('pos-sync/v1', '/oauth/token', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle(): WP_REST_Response
    {
        $server = ServerFactory::authorizationServer();

        try {
            $psrResponse = $server->respondToAccessTokenRequest(
                Psr7Bridge::requestFromGlobals(),
                Psr7Bridge::newResponse()
            );

            return Psr7Bridge::toWpResponse($psrResponse);
        } catch (OAuthServerException $exception) {
            error_log('POS Sync: OAuth token request failed - ' . $exception->getMessage());

            return Psr7Bridge::toWpResponse($exception->generateHttpResponse(Psr7Bridge::newResponse()));
        }
    }
}
