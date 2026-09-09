<?php

declare(strict_types=1);

namespace PosSync\Rest;

use League\OAuth2\Server\Exception\OAuthServerException;
use PosSync\OAuth\ServerFactory;
use PosSync\Psr7Bridge;
use WP_Error;
use WP_REST_Request;

/**
 * Shared OAuth2 permission callback for the POS Sync REST endpoints.
 */
class Authentication
{
    /**
     * @return true|WP_Error
     */
    public static function authenticate(WP_REST_Request $request)
    {
        try {
            $psrRequest = ServerFactory::resourceServer()->validateAuthenticatedRequest(Psr7Bridge::requestFromGlobals());
            $request->set_param('_oauth_client_id', $psrRequest->getAttribute('oauth_client_id'));

            return true;
        } catch (OAuthServerException $exception) {
            error_log('POS Sync: OAuth authentication failed - ' . $exception->getMessage());

            return new WP_Error(
                'pos_sync_unauthorized',
                $exception->getMessage(),
                ['status' => $exception->getHttpStatusCode()]
            );
        }
    }
}
