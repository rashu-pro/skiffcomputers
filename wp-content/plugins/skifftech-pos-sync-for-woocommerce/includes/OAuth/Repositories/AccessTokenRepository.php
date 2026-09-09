<?php

declare(strict_types=1);

namespace PosSync\OAuth\Repositories;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use PosSync\OAuth\Entities\AccessTokenEntity;
use PosSync\Tables;

class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, string|null $userIdentifier = null): AccessTokenEntityInterface
    {
        $accessToken = new AccessTokenEntity();
        $accessToken->setClient($clientEntity);

        foreach ($scopes as $scope) {
            $accessToken->addScope($scope);
        }

        return $accessToken;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        global $wpdb;

        $wpdb->insert(
            Tables::accessTokens(),
            [
                'access_token_id' => $accessTokenEntity->getIdentifier(),
                'client_id'       => $accessTokenEntity->getClient()->getIdentifier(),
                'expires_at'      => $accessTokenEntity->getExpiryDateTime()->format('Y-m-d H:i:s'),
                'revoked'         => 0,
                'created_at'      => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%d', '%s']
        );
    }

    public function revokeAccessToken(string $tokenId): void
    {
        global $wpdb;

        $wpdb->update(
            Tables::accessTokens(),
            ['revoked' => 1],
            ['access_token_id' => $tokenId],
            ['%d'],
            ['%s']
        );
    }

    public function isAccessTokenRevoked(string $tokenId): bool
    {
        global $wpdb;

        $table = Tables::accessTokens();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT revoked FROM {$table} WHERE access_token_id = %s", $tokenId)
        );

        if (!$row) {
            return true;
        }

        return (int) $row->revoked === 1;
    }
}
