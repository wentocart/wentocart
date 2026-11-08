<?php

namespace App\Domain\Auth;

use App\Domain\Settings\ShopSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google, Facebook, and LinkedIn sign-in. A provider is offered on the shop only when
 * the merchant turns it on and saves both keys.
 */
final class SocialLogin
{
    /** @var list<string> */
    public const PROVIDERS = ['google', 'facebook', 'linkedin'];

    public static function ready(string $provider): bool
    {
        return self::enabled($provider)
            && self::clientId($provider) !== ''
            && self::secret($provider) !== '';
    }

    public static function enabled(string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true)
            && ShopSetting::bool(self::key($provider, 'enabled'), false);
    }

    public static function clientId(string $provider): string
    {
        return trim((string) ShopSetting::get(self::key($provider, 'client_id'), ''));
    }

    public static function secret(string $provider): string
    {
        $raw = trim((string) ShopSetting::get(self::key($provider, 'secret'), ''));
        if ($raw === '') {
            return '';
        }
        try {
            $plain = Crypt::decryptString($raw);

            return trim($plain);
        } catch (Throwable) {
            return $raw;
        }
    }

    public static function putSecret(string $provider, string $plain): void
    {
        $plain = trim($plain);
        ShopSetting::put(
            self::key($provider, 'secret'),
            $plain === '' ? '' : Crypt::encryptString($plain)
        );
    }

    public static function redirectUri(string $provider): string
    {
        return url('/auth/'.$provider.'/callback');
    }

    /**
     * Customer column that stores the provider subject id.
     */
    public static function idColumn(string $provider): string
    {
        return match ($provider) {
            'facebook' => 'facebook_id',
            'linkedin' => 'linkedin_id',
            default => 'google_id',
        };
    }

    public static function authorizeUrl(string $provider, string $state): string
    {
        $query = [
            'client_id' => self::clientId($provider),
            'redirect_uri' => self::redirectUri($provider),
            'state' => $state,
            'response_type' => 'code',
        ];
        if ($provider === 'facebook') {
            $query['scope'] = 'email,public_profile';

            return 'https://www.facebook.com/v21.0/dialog/oauth?'.http_build_query($query);
        }
        if ($provider === 'linkedin') {
            $query['scope'] = 'openid profile email';

            return 'https://www.linkedin.com/oauth/v2/authorization?'.http_build_query($query);
        }

        $query['scope'] = 'openid email profile';
        $query['prompt'] = 'select_account';

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($query);
    }

    /**
     * @return array{id: string, email: string, first_name: string, last_name: string}
     */
    public static function exchange(string $provider, string $code): array
    {
        if ($provider === 'facebook') {
            return self::facebookProfile($code);
        }
        if ($provider === 'linkedin') {
            return self::linkedinProfile($code);
        }

        return self::googleProfile($code);
    }

    /**
     *
     * @return array{id: string, email: string, first_name: string, last_name: string}
     */
    public static function profileFromClientToken(string $provider, ?string $idToken, ?string $accessToken): array
    {
        $provider = strtolower(trim($provider));
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new \RuntimeException('PROVIDER_NOT_SUPPORTED');
        }
        if (! self::ready($provider)) {
            throw new \RuntimeException('PROVIDER_DISABLED');
        }
        $idToken = $idToken !== null ? trim($idToken) : '';
        $accessToken = $accessToken !== null ? trim($accessToken) : '';
        if ($idToken === '' && $accessToken === '') {
            throw new \RuntimeException('SOCIAL_TOKEN_REQUIRED');
        }

        try {
            if ($provider === 'google') {
                return $idToken !== ''
                    ? self::googleIdTokenProfile($idToken)
                    : self::googleAccessTokenProfile($accessToken);
            }
            if ($provider === 'linkedin') {
                return self::linkedinAccessTokenProfile($accessToken !== '' ? $accessToken : $idToken);
            }

            return self::facebookAccessTokenProfile($accessToken !== '' ? $accessToken : $idToken);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (Throwable) {
            throw new \RuntimeException('SOCIAL_TOKEN_INVALID');
        }
    }

    /**
     * @return array{id: string, email: string, first_name: string, last_name: string}
     */
    protected static function googleIdTokenProfile(string $idToken): array
    {
        $user = Http::timeout(15)->get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $idToken,
        ]);
        if (! $user->ok()) {
            throw new \RuntimeException('SOCIAL_TOKEN_INVALID');
        }
        $aud = (string) $user->json('aud');
        $clientId = self::clientId('google');
        if ($clientId !== '' && $aud !== '' && ! hash_equals($clientId, $aud)) {
            throw new \RuntimeException('SOCIAL_TOKEN_AUDIENCE');
        }

        return self::profile(
            (string) ($user->json('sub') ?? ''),
            (string) ($user->json('email') ?? ''),
            (string) ($user->json('given_name') ?? ''),
            (string) ($user->json('family_name') ?? ''),
            (string) ($user->json('name') ?? ''),
        );
    }

    /**
     * @return array{id: string, email: string, first_name: string, last_name: string}
     */
    protected static function googleAccessTokenProfile(string $accessToken): array
    {
        $user = Http::withToken($accessToken)->timeout(15)->get('https://openidconnect.googleapis.com/v1/userinfo');
        if (! $user->ok()) {
            throw new \RuntimeException('SOCIAL_TOKEN_INVALID');
        }

        return self::profile(
            (string) ($user->json('sub') ?? ''),
            (string) ($user->json('email') ?? ''),
            (string) ($user->json('given_name') ?? ''),
            (string) ($user->json('family_name') ?? ''),
            (string) ($user->json('name') ?? ''),
        );
    }

    /**
     * @return array{id: string, email: string, first_name: string, last_name: string}
     */
    protected static function facebookAccessTokenProfile(string $accessToken): array
    {
        $user = Http::withToken($accessToken)->timeout(15)->get('https://graph.facebook.com/me', [
            'fields' => 'id,email,first_name,last_name,name',
            'appsecret_proof' => hash_hmac('sha256', $accessToken, self::secret('facebook')),
        ]);
        if (! $user->ok()) {
            throw new \RuntimeException('SOCIAL_TOKEN_INVALID');
        }

        return self::profile(
            (string) ($user->json('id') ?? ''),
            (string) ($user->json('email') ?? ''),
            (string) ($user->json('first_name') ?? ''),
            (string) ($user->json('last_name') ?? ''),
            (string) ($user->json('name') ?? ''),
        );
    }

    /**
     * @return array{id: string, email: string, first_name: string, last_name: string}
     */
    protected static function linkedinAccessTokenProfile(string $accessToken): array
    {
        $user = Http::withToken($accessToken)->timeout(15)->get('https://api.linkedin.com/v2/userinfo');
        if (! $user->ok()) {
            throw new \RuntimeException('SOCIAL_TOKEN_INVALID');
        }

        return self::profile(
            (string) ($user->json('sub') ?? ''),
            (string) ($user->json('email') ?? ''),
            (string) ($user->json('given_name') ?? ''),
            (string) ($user->json('family_name') ?? ''),
            (string) ($user->json('name') ?? ''),
        );
    }

    /**
     * Flags the storefront may see. Secrets stay on the server.
     *
     * @return array{google: bool, facebook: bool, linkedin: bool}
     */
    public static function publicFlags(): array
    {
        return [
            'google' => self::ready('google'),
            'facebook' => self::ready('facebook'),
            'linkedin' => self::ready('linkedin'),
        ];
    }

    /**
     * @return array{google: array<string, mixed>, facebook: array<string, mixed>, linkedin: array<string, mixed>}
     */
    public static function adminPayload(): array
    {
        $row = function (string $provider): array {
            return [
                'enabled' => self::enabled($provider),
                'client_id' => self::clientId($provider),
                'secret_set' => self::secret($provider) !== '',
                'redirect' => self::redirectUri($provider),
            ];
        };

        return [
            'google' => $row('google'),
            'facebook' => $row('facebook'),
            'linkedin' => $row('linkedin'),
        ];
    }

    /**
     * @return array{id: string, email: string, first_name: string, last_name: string}
     */
    protected static function googleProfile(string $code): array
    {
        $token = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => self::clientId('google'),
            'client_secret' => self::secret('google'),
            'redirect_uri' => self::redirectUri('google'),
            'grant_type' => 'authorization_code',
        ]);
        $access = (string) $token->json('access_token');
        if (! $token->ok() || $access === '') {
            throw new \RuntimeException('Google token exchange failed.');
        }

        $user = Http::withToken($access)->timeout(15)->get('https://openidconnect.googleapis.com/v1/userinfo');
        if (! $user->ok()) {
            throw new \RuntimeException('Google profile request failed.');
        }

        return self::profile(
            (string) $user->json('sub'),
            (string) $user->json('email'),
            (string) $user->json('given_name'),
            (string) $user->json('family_name'),
            (string) $user->json('name'),
        );
    }

    /**
     * @return array{id: string, email: string, first_name: string, last_name: string}
     */
    protected static function facebookProfile(string $code): array
    {
        $token = Http::timeout(15)->get('https://graph.facebook.com/v21.0/oauth/access_token', [
            'code' => $code,
            'client_id' => self::clientId('facebook'),
            'client_secret' => self::secret('facebook'),
            'redirect_uri' => self::redirectUri('facebook'),
        ]);
        $access = (string) $token->json('access_token');
        if (! $token->ok() || $access === '') {
            throw new \RuntimeException('Facebook token exchange failed.');
        }

        $user = Http::withToken($access)->timeout(15)->get('https://graph.facebook.com/me', [
            'fields' => 'id,email,first_name,last_name,name',
            'appsecret_proof' => hash_hmac('sha256', $access, self::secret('facebook')),
        ]);
        if (! $user->ok()) {
            throw new \RuntimeException('Facebook profile request failed.');
        }

        return self::profile(
            (string) $user->json('id'),
            (string) $user->json('email'),
            (string) $user->json('first_name'),
            (string) $user->json('last_name'),
            (string) $user->json('name'),
        );
    }

    /**
     * @return array{id: string, email: string, first_name: string, last_name: string}
     */
    protected static function linkedinProfile(string $code): array
    {
        $token = Http::asForm()->timeout(15)->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => self::clientId('linkedin'),
            'client_secret' => self::secret('linkedin'),
            'redirect_uri' => self::redirectUri('linkedin'),
        ]);
        $access = (string) $token->json('access_token');
        if (! $token->ok() || $access === '') {
            throw new \RuntimeException('LinkedIn token exchange failed.');
        }

        return self::linkedinAccessTokenProfile($access);
    }

    /**
     * @return array{id: string, email: string, first_name: string, last_name: string}
     */
    protected static function profile(string $id, string $email, string $first, string $last, string $name): array
    {
        $email = strtolower(trim($email));
        $first = trim($first);
        $last = trim($last);
        if ($first === '' && $name !== '') {
            $parts = preg_split('/\s+/', trim($name), 2) ?: [];
            $first = $parts[0] ?? '';
            $last = $last !== '' ? $last : ($parts[1] ?? '');
        }

        return [
            'id' => trim($id),
            'email' => $email,
            'first_name' => $first !== '' ? $first : 'Customer',
            'last_name' => $last,
        ];
    }

    protected static function key(string $provider, string $field): string
    {
        return 'customers.social.'.$provider.'.'.$field;
    }
}
