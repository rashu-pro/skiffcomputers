<?php

declare(strict_types=1);

namespace PosSync\OAuth\Repositories;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use PosSync\OAuth\Entities\RefreshTokenEntity;
use PosSync\Tables;

class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return new RefreshTokenEntity();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        global $wpdb;

        $wpdb->insert(
            Tables::refreshTokens(),
            [
                'refresh_token_id' => $refreshTokenEntity->getIdentifier(),
                'access_token_id'  => $refreshTokenEntity->getAccessToken()->getIdentifier(),
                'expires_at'       => $refreshTokenEntity->getExpiryDateTime()->format('Y-m-d H:i:s'),
                'revoked'          => 0,
                'created_at'       => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%d', '%s']
        );
    }

    public function revokeRefreshToken(string $tokenId): void
    {
        global $wpdb;

        $wpdb->update(
            Tables::refreshTokens(),
            ['revoked' => 1],
            ['refresh_token_id' => $tokenId],
            ['%d'],
            ['%s']
        );
    }

    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        global $wpdb;

        $table = Tables::refreshTokens();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT revoked FROM {$table} WHERE refresh_token_id = %s", $tokenId)
        );

        if (!$row) {
            return true;
        }

        return (int) $row->revoked === 1;
    }
}
