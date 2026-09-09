<?php

declare(strict_types=1);

namespace PosSync\OAuth\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use PosSync\OAuth\Entities\ScopeEntity;

class ScopeRepository implements ScopeRepositoryInterface
{
    private const SCOPES = ['sync'];

    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        if (!in_array($identifier, self::SCOPES, true)) {
            return null;
        }

        $scope = new ScopeEntity();
        $scope->setIdentifier($identifier);

        return $scope;
    }

    public function finalizeScopes(
        array $scopes,
        string $grantType,
        ClientEntityInterface $clientEntity,
        string|null $userIdentifier = null,
        ?string $authCodeId = null
    ): array {
        if (!empty($scopes)) {
            return $scopes;
        }

        $scope = $this->getScopeEntityByIdentifier('sync');

        return $scope ? [$scope] : [];
    }
}
