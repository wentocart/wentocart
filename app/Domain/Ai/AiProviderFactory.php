<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Contracts\AiProvider;
use App\Domain\Ai\Providers\AnthropicProvider;
use App\Domain\Ai\Providers\GeminiProvider;
use App\Domain\Ai\Providers\OpenAiProvider;
use App\Domain\Security\SafeUrl;
use RuntimeException;

class AiProviderFactory
{
    protected static ?AiProvider $fake = null;

    public static function fake(?AiProvider $provider): void
    {
        self::$fake = $provider;
    }

    /**
     * @param  array{driver: string, api_key?: ?string, model?: ?string, base_url?: ?string}  $connection
     */
    public static function make(array $connection): AiProvider
    {
        if (self::$fake) {
            return self::$fake;
        }

        $driver = (string) ($connection['driver'] ?? '');
        $key = trim((string) ($connection['api_key'] ?? ''));
        $model = trim((string) ($connection['model'] ?? ''));

        return match ($driver) {
            'openai' => new OpenAiProvider($key, $model !== '' ? $model : 'gpt-4o-mini'),
            'openai_compatible' => (function () use ($key, $model, $connection): OpenAiProvider {
                $baseUrl = rtrim((string) ($connection['base_url'] ?? ''), '/');
                // SSRF guard at the choke point: covers connections stored before
                // admin-side validation existed.
                SafeUrl::assertPublicHttp($baseUrl, false);

                return new OpenAiProvider(
                    $key,
                    $model !== '' ? $model : 'gpt-4o-mini',
                    $baseUrl,
                    'openai_compatible',
                );
            })(),
            'anthropic' => new AnthropicProvider($key, $model !== '' ? $model : 'claude-sonnet-4-20250514'),
            'gemini' => new GeminiProvider($key, $model !== '' ? $model : 'gemini-2.0-flash'),
            default => throw new RuntimeException('Unknown AI provider driver: '.$driver),
        };
    }
}
