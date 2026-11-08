<?php

namespace App\Domain\Plugin\Support;

use App\Domain\Plugin\Capabilities;
use App\Domain\Plugin\PluginManifest;
use App\Domain\Security\SafeUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Capability-gated HTTP client for certified extensions (http.outbound).
 */
class PluginHttp
{
    public function __construct(protected PluginManifest $manifest) {}

    /**
     * @param  array<string, mixed>  $options  headers, form, json, basic_auth [user, pass], timeout
     * @return array{status: int, body: string, json: mixed}
     */
    public function request(string $method, string $url, array $options = []): array
    {
        if (
            ! $this->manifest->can(Capabilities::HTTP_OUTBOUND)
            && ! $this->manifest->can(Capabilities::PAYMENT_DRIVER)
            && ! $this->manifest->can(Capabilities::SHIPPING_DRIVER)
        ) {
            throw ValidationException::withMessages(['http' => 'This extension cannot make outbound HTTP calls.']);
        }

        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        $allowed = array_map('strtolower', $this->manifest->outboundHosts);
        if ($allowed !== [] && $host !== '' && ! in_array($host, $allowed, true)) {
            throw ValidationException::withMessages(['http' => 'Host '.$host.' is not in outbound_hosts.']);
        }
        if (! str_starts_with($url, 'https://')) {
            throw ValidationException::withMessages(['http' => 'Only https outbound URLs are allowed.']);
        }
        try {
            SafeUrl::assertPublicHttp($url);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['http' => $e->getMessage()]);
        }

        $pending = Http::timeout((int) ($options['timeout'] ?? 30))
            ->withOptions(['allow_redirects' => false])
            ->withHeaders($options['headers'] ?? []);
        if (! empty($options['basic_auth']) && is_array($options['basic_auth'])) {
            $pending = $pending->withBasicAuth((string) $options['basic_auth'][0], (string) ($options['basic_auth'][1] ?? ''));
        }

        $method = strtoupper($method);
        $response = match ($method) {
            'GET' => $pending->get($url, $options['query'] ?? []),
            'POST' => isset($options['json'])
                ? $pending->asJson()->post($url, $options['json'])
                : $pending->asForm()->post($url, $options['form'] ?? []),
            'PUT' => isset($options['json'])
                ? $pending->asJson()->put($url, $options['json'])
                : $pending->asForm()->put($url, $options['form'] ?? []),
            'DELETE' => $pending->delete($url),
            default => throw new RuntimeException('Unsupported HTTP method.'),
        };

        $body = $response->body();
        $json = null;
        try {
            $json = $response->json();
        } catch (\Throwable) {
            $json = null;
        }

        return [
            'status' => $response->status(),
            'body' => $body,
            'json' => $json,
        ];
    }
}
