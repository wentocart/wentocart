<?php

namespace App\Domain\Security;

use App\Domain\Security\Models\WebAuthnCredential;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class WebAuthn
{
    /**
     * @return array<string, mixed>
     */
    public function registerOptions(Model $user): array
    {
        $challenge = $this->challenge('webauthn.register');

        return [
            'rp' => [
                'name' => (string) config('wento.name', config('app.name')),
                'id' => $this->rpId(),
            ],
            'user' => [
                'id' => $this->b64u(pack('N', (int) $user->getKey())),
                'name' => (string) ($user->email ?? ('user-'.$user->getKey())),
                'displayName' => (string) ($user->name ?? $user->email ?? 'User'),
            ],
            'challenge' => $challenge,
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'timeout' => 60000,
            'attestation' => 'none',
            'authenticatorSelection' => [
                'residentKey' => 'preferred',
                'userVerification' => 'preferred',
            ],
            'excludeCredentials' => $user->webauthnCredentials()->get()->map(fn (WebAuthnCredential $row) => [
                'type' => 'public-key',
                'id' => $row->credential_id,
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function register(Model $user, array $payload): WebAuthnCredential
    {
        $client = $this->clientData($payload['client_data_json'] ?? '', 'webauthn.create');
        $this->assertChallenge($client, 'webauthn.register');
        $credentialId = $this->normalizeId((string) ($payload['credential_id'] ?? ''));
        $publicKey = $this->publicKeyPem((string) ($payload['public_key'] ?? ''));
        if ($credentialId === '' || $publicKey === '') {
            throw ValidationException::withMessages(['passkey' => 'That passkey could not be read.']);
        }
        if (WebAuthnCredential::query()->where('credential_id', $credentialId)->exists()) {
            throw ValidationException::withMessages(['passkey' => 'That passkey is already registered.']);
        }

        return $user->webauthnCredentials()->create([
            'credential_id' => $credentialId,
            'public_key' => $publicKey,
            'sign_count' => $this->signCount((string) ($payload['authenticator_data'] ?? '')),
            'name' => (string) ($payload['name'] ?? 'Passkey'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function assertionOptions(?Model $user = null): array
    {
        $allow = [];
        if ($user) {
            $allow = $user->webauthnCredentials()->get()->map(fn (WebAuthnCredential $row) => [
                'type' => 'public-key',
                'id' => $row->credential_id,
            ])->all();
        }

        return [
            'challenge' => $this->challenge('webauthn.assert'),
            'timeout' => 60000,
            'rpId' => $this->rpId(),
            'userVerification' => 'preferred',
            'allowCredentials' => $allow,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assert(Model $user, array $payload): WebAuthnCredential
    {
        $client = $this->clientData($payload['client_data_json'] ?? '', 'webauthn.get');
        $this->assertChallenge($client, 'webauthn.assert');
        $credentialId = $this->normalizeId((string) ($payload['credential_id'] ?? ''));
        $credential = $user->webauthnCredentials()->where('credential_id', $credentialId)->first();
        if (! $credential) {
            throw ValidationException::withMessages(['passkey' => 'Unknown passkey.']);
        }
        $authData = $this->b64uDec((string) ($payload['authenticator_data'] ?? ''));
        $signature = $this->b64uDec((string) ($payload['signature'] ?? ''));
        $clientJson = $this->b64uDec((string) ($payload['client_data_json'] ?? ''));
        if ($authData === '' || $signature === '' || $clientJson === '') {
            throw ValidationException::withMessages(['passkey' => 'Incomplete passkey assertion.']);
        }
        $payloadHash = $authData.hash('sha256', $clientJson, true);
        $ok = openssl_verify($payloadHash, $signature, $credential->public_key, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw ValidationException::withMessages(['passkey' => 'Passkey signature was rejected.']);
        }
        $count = $this->signCount((string) ($payload['authenticator_data'] ?? ''));
        if ($count < (int) $credential->sign_count) {
            throw ValidationException::withMessages(['passkey' => 'Passkey sign count moved backwards.']);
        }
        $credential->update(['sign_count' => $count]);

        return $credential->fresh();
    }

    public function challenge(string $bag): string
    {
        $bytes = random_bytes(32);
        $encoded = $this->b64u($bytes);
        session()->put($bag, $encoded);

        return $encoded;
    }

    /**
     * @return array<string, mixed>
     */
    protected function clientData(string $encoded, string $type): array
    {
        $json = $this->b64uDec($encoded);
        $data = json_decode($json, true);
        if (! is_array($data) || ($data['type'] ?? '') !== $type) {
            throw ValidationException::withMessages(['passkey' => 'Passkey client data was invalid.']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $client
     */
    protected function assertChallenge(array $client, string $bag): void
    {
        $expected = (string) session()->pull($bag);
        $got = $this->normalizeId((string) ($client['challenge'] ?? ''));
        if ($expected === '' || ! hash_equals($expected, $got)) {
            throw ValidationException::withMessages(['passkey' => 'Passkey challenge did not match.']);
        }
    }

    protected function publicKeyPem(string $encoded): string
    {
        $raw = $this->b64uDec($encoded);
        if ($raw === '') {
            return '';
        }
        if (str_contains($encoded, 'BEGIN PUBLIC KEY')) {
            return $encoded;
        }

        return "-----BEGIN PUBLIC KEY-----\n".trim(chunk_split(base64_encode($raw), 64, "\n"))."\n-----END PUBLIC KEY-----\n";
    }

    protected function signCount(string $encodedAuthData): int
    {
        $auth = $this->b64uDec($encodedAuthData);
        if (strlen($auth) < 37) {
            return 0;
        }
        $unpacked = unpack('N', substr($auth, 33, 4));

        return (int) ($unpacked[1] ?? 0);
    }

    protected function rpId(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : request()->getHost();
    }

    protected function normalizeId(string $value): string
    {
        return rtrim(strtr($value, '+/', '-_'), '=');
    }

    public function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public function b64uDec(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;

        return (string) base64_decode($data.($pad ? str_repeat('=', 4 - $pad) : ''), true);
    }
}
