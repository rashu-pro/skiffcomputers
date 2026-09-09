<?php

declare(strict_types=1);

namespace PosSync\OAuth\Entities;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

class ClientEntity implements ClientEntityInterface
{
    use ClientTrait;
    use EntityTrait;

    public function __construct(string $clientId, string $name)
    {
        $this->identifier = $clientId;
        $this->name = $name;
        $this->redirectUri = '';
        $this->isConfidential = true;
    }
}
