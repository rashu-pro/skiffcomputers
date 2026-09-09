<?php

declare(strict_types=1);

namespace PosSync\OAuth;

use DateInterval;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResourceServer;
use PosSync\Keys;
use PosSync\OAuth\Grant\ClientCredentialsRefreshGrant;
use PosSync\OAuth\Repositories\AccessTokenRepository;
use PosSync\OAuth\Repositories\ClientRepository;
use PosSync\OAuth\Repositories\RefreshTokenRepository;
use PosSync\OAuth\Repositories\ScopeRepository;

class ServerFactory
{
    private const ACCESS_TOKEN_TTL = 'PT1H';
    private const REFRESH_TOKEN_TTL = 'P30D';

    public static function authorizationServer(): AuthorizationServer
    {
        $refreshTokenRepository = new RefreshTokenRepository();

        $server = new AuthorizationServer(
            new ClientRepository(),
            new AccessTokenRepository(),
            new ScopeRepository(),
            Keys::privateKeyPath(),
            Keys::encryptionKey()
        );

        $accessTokenTTL = new DateInterval(self::ACCESS_TOKEN_TTL);
        $refreshTokenTTL = new DateInterval(self::REFRESH_TOKEN_TTL);

        $clientCredentialsGrant = new ClientCredentialsRefreshGrant();
        $clientCredentialsGrant->setRefreshTokenRepository($refreshTokenRepository);
        $clientCredentialsGrant->setRefreshTokenTTL($refreshTokenTTL);
        $server->enableGrantType($clientCredentialsGrant, $accessTokenTTL);

        $refreshTokenGrant = new RefreshTokenGrant($refreshTokenRepository);
        $refreshTokenGrant->setRefreshTokenTTL($refreshTokenTTL);
        $server->enableGrantType($refreshTokenGrant, $accessTokenTTL);

        return $server;
    }

    public static function resourceServer(): ResourceServer
    {
        return new ResourceServer(
            new AccessTokenRepository(),
            Keys::publicKeyPath()
        );
    }
}
