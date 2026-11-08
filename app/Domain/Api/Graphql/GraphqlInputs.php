<?php

namespace App\Domain\Api\Graphql;

/**
 */
final class GraphqlInputs
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function unwrap(array $args): array
    {
        $input = $args['input'] ?? null;
        if (! is_array($input)) {
            return $args;
        }
        unset($args['input']);

        return array_merge($input, $args);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function wrapLogin(array $payload): array
    {
        return array_merge($payload, [
            'customerLogin' => $payload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function wrapVerify(array $payload): array
    {
        $valid = (bool) ($payload['valid'] ?? $payload['success'] ?? false);

        return array_merge($payload, [
            'isValid' => $valid,
            'valid' => $valid,
            'verifyToken' => [
                'isValid' => $valid,
                'valid' => $valid,
                'success' => (bool) ($payload['success'] ?? $valid),
                'message' => $payload['message'] ?? null,
                'customer' => $payload['customer'] ?? null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function wrapCartToken(array $payload): array
    {
        $token = (string) ($payload['cartToken'] ?? $payload['token'] ?? '');

        return array_merge($payload, [
            'cartToken' => [
                'id' => $payload['id'] ?? $token,
                'cartToken' => $token,
                'token' => $token,
            ],
        ]);
    }
}
