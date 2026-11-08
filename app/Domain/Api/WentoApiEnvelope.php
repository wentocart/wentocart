<?php

namespace App\Domain\Api;

use Illuminate\Http\JsonResponse;

/**
 * Uniform REST JSON shape for Wento console/store APIs.
 *
 * Always snake_case at the wire. List responses include pagination under meta.
 */
final class WentoApiEnvelope
{
    /**
     * @param  array<string, mixed>|list<mixed>|null  $data
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public static function ok(mixed $data = null, array $meta = [], int $status = 200, array $headers = []): JsonResponse
    {
        $payload = ['data' => self::snakeKeys($data)];
        if ($meta !== []) {
            $payload['meta'] = self::snakeKeys($meta);
        }

        return response()->json($payload, $status, $headers);
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    public static function fail(string $message, int $status = 422, array $errors = []): JsonResponse
    {
        $payload = [
            'data' => null,
            'meta' => [
                'message' => $message,
            ],
        ];
        if ($errors !== []) {
            $payload['meta']['errors'] = self::snakeKeys($errors);
        }

        return response()->json($payload, $status);
    }

    public static function snakeKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $key => $item) {
            $nextKey = $isList ? $key : self::toSnake((string) $key);
            $out[$nextKey] = self::snakeKeys($item);
        }

        return $out;
    }

    public static function toSnake(string $key): string
    {
        if ($key === '' || str_contains($key, '_') || str_contains($key, '.')) {
            return $key;
        }
        $snake = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key);

        return strtolower((string) $snake);
    }
}
