<?php

declare(strict_types=1);

namespace PosSync\OAuth\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use PosSync\OAuth\Entities\ClientEntity;
use PosSync\Tables;

class ClientRepository implements ClientRepositoryInterface
{
    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $row = $this->findActiveClientRow($clientIdentifier);

        if (!$row) {
            return null;
        }

        return new ClientEntity($row->client_id, $row->name);
    }

    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        $row = $this->findActiveClientRow($clientIdentifier);

        if (!$row || $clientSecret === null || $clientSecret === '') {
            return false;
        }

        return password_verify($clientSecret, $row->secret_hash);
    }

    private function findActiveClientRow(string $clientIdentifier): ?object
    {
        global $wpdb;

        $table = Tables::clients();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT client_id, name, secret_hash FROM {$table} WHERE client_id = %s AND revoked_at IS NULL",
                $clientIdentifier
            )
        );

        return $row ?: null;
    }
}
