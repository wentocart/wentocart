<?php

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\AiHttp;
use App\Domain\Ai\Contracts\AiProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiProvider implements AiProvider
{
    public function __construct(
        protected string $apiKey,
        protected string $model = 'gemini-2.0-flash',
    ) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function complete(string $prompt, array $options = []): string
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Gemini API key is not configured.');
        }

        $model = (string) ($options['model'] ?? $this->model);
        $system = (string) ($options['system'] ?? 'You write clear ecommerce copy. Return only the requested content with no preamble.');
        $maxTokens = max(64, min(4000, (int) ($options['max_tokens'] ?? 1200)));
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent';
        $parts = [['text' => $prompt]];
        $images = is_array($options['images'] ?? null) ? $options['images'] : [];
        foreach ($images as $img) {
            $mime = is_array($img) ? (string) ($img['mime'] ?? 'image/jpeg') : 'image/jpeg';
            $b64 = is_array($img) ? (string) ($img['base64'] ?? '') : '';
            if ($b64 === '' && is_string($img) && str_starts_with($img, 'data:')) {
                if (preg_match('#^data:([^;]+);base64,(.+)$#', $img, $m)) {
                    $mime = $m[1];
                    $b64 = $m[2];
                }
            }
            if ($b64 === '') {
                continue;
            }
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $mime,
                    'data' => $b64,
                ],
            ];
        }

        $response = Http::withOptions(AiHttp::options())
            ->withQueryParameters(['key' => $this->apiKey])
            ->acceptJson()
            ->post($url, [
                'systemInstruction' => [
                    'parts' => [['text' => $system]],
                ],
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => $parts,
                    ],
                ],
                'generationConfig' => [
                    'temperature' => (float) ($options['temperature'] ?? 0.6),
                    'maxOutputTokens' => $maxTokens,
                ],
            ]);

        if (! $response->successful()) {
            $message = $response->json('error.message') ?: $response->body();
            throw new RuntimeException('Gemini request failed: '.trim((string) $message));
        }

        $text = trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));
        if ($text === '') {
            throw new RuntimeException('Gemini returned an empty response.');
        }

        return $text;
    }
}
