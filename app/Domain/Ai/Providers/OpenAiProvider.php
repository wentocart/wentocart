<?php

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\AiHttp;
use App\Domain\Ai\Contracts\AiProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiProvider implements AiProvider
{
    public function __construct(
        protected string $apiKey,
        protected string $model = 'gpt-4o-mini',
        protected string $baseUrl = 'https://api.openai.com/v1',
        protected string $driverName = 'openai',
    ) {
        $this->baseUrl = rtrim($this->baseUrl !== '' ? $this->baseUrl : 'https://api.openai.com/v1', '/');
    }

    public function name(): string
    {
        return $this->driverName;
    }

    public function complete(string $prompt, array $options = []): string
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('API key is not configured.');
        }

        $model = (string) ($options['model'] ?? $this->model);
        $temperature = (float) ($options['temperature'] ?? 0.6);
        $maxTokens = (int) ($options['max_tokens'] ?? 1200);
        $images = is_array($options['images'] ?? null) ? $options['images'] : [];
        $userContent = $prompt;
        if ($images !== []) {
            $parts = [['type' => 'text', 'text' => $prompt]];
            foreach ($images as $img) {
                $url = is_array($img) ? (string) ($img['url'] ?? $img['data_url'] ?? '') : (string) $img;
                if ($url === '') {
                    continue;
                }
                $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
            }
            $userContent = $parts;
        }

        $response = Http::withOptions(AiHttp::options())
            ->withToken($this->apiKey)
            ->acceptJson()
            ->post($this->baseUrl.'/chat/completions', [
                'model' => $model,
                'temperature' => $temperature,
                'max_tokens' => max(64, min(4000, $maxTokens)),
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => (string) ($options['system'] ?? 'You write clear ecommerce copy. Return only the requested content with no preamble.'),
                    ],
                    [
                        'role' => 'user',
                        'content' => $userContent,
                    ],
                ],
            ]);

        if (! $response->successful()) {
            $message = $response->json('error.message') ?: $response->body();
            throw new RuntimeException('OpenAI-compatible request failed: '.trim((string) $message));
        }

        $text = trim((string) data_get($response->json(), 'choices.0.message.content', ''));
        if ($text === '') {
            throw new RuntimeException('Provider returned an empty response.');
        }

        return $text;
    }
}
