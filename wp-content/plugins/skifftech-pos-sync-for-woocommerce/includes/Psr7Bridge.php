<?php

declare(strict_types=1);

namespace PosSync;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use WP_REST_Response;

class Psr7Bridge
{
    public static function requestFromGlobals(): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);

        return $creator->fromGlobals();
    }

    public static function newResponse(): ResponseInterface
    {
        return (new Psr17Factory())->createResponse();
    }

    public static function toWpResponse(ResponseInterface $response): WP_REST_Response
    {
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        $wpResponse = new WP_REST_Response($decoded ?? $body, $response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            $wpResponse->header($name, implode(', ', $values));
        }

        return $wpResponse;
    }
}
