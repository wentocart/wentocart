<?php

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\AiHttp;
use App\Domain\Ai\Contracts\AiProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnthropicProvider implements AiProvider
{
    public function __construct(
        protected string $apiKey,
        protected string $model = 'claude-sonnet-4-20250514',
    ) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function complete(string $prompt, array $options = []): string
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Anthropic API key is not configured.');
        }

        $model = (string) ($options['model'] ?? $this->model);
        $maxTokens = max(64, min(4000, (int) ($options['max_tokens'] ?? 1200)));
        $system = (string) ($options['system'] ?? 'You write clear ecommerce copy. Return only the requested content with no preamble.');

        $response = Http::withOptions(AiHttp::options())
            ->withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ])
            ->acceptJson()
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if (! $response->successful()) {
            $message = $response->json('error.message') ?: $response->body();
            throw new RuntimeException('Anthropic request failed: '.trim((string) $message));
        }

        $blocks = $response->json('content') ?? [];
        $text = '';
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('Anthropic returned an empty response.');
        }

        return $text;
    }
}
