<?php

namespace App\Domain\Api\Graphql;

use Symfony\Component\HttpFoundation\Response;

/**
 * Expose GraphQL execution timing — docs push APM to clients;
 * Wento returns Server-Timing on every GraphQL response for free.
 */
final class GraphqlTiming
{
    public const HEADER_MS = 'X-Wento-GQL-Duration-Ms';

    public const HEADER_OP = 'X-Wento-GQL-Op';

    /**
     * @template T of Response
     * @param  T  $response
     * @return T
     */
    public static function withHeaders(Response $response, float $startedAt, ?string $operationName = null): Response
    {
        $ms = max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
        $response->headers->set(self::HEADER_MS, (string) $ms);
        $response->headers->set('Server-Timing', 'gql;dur='.$ms);
        if (is_string($operationName) && $operationName !== '') {
            $response->headers->set(self::HEADER_OP, mb_substr($operationName, 0, 120));
        }

        return $response;
    }

    public static function start(): float
    {
        return (float) hrtime(true);
    }
}
