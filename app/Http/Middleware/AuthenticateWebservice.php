<?php

namespace App\Http\Middleware;

use App\Domain\Ops\Models\WebserviceKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWebservice
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        if ($request->query->has('ws_key') || $request->query->has('api_key') || $request->query->has('key')) {
            return response()->json([
                'error' => 'Pass the API key in the Authorization header. Query-string keys are not allowed.',
            ], 400);
        }

        $plain = $this->bearer($request);
        if ($plain === null) {
            return $this->deny(401, 'Missing or invalid Authorization bearer token.');
        }

        $authLimit = 'ws-auth:'.(string) $request->ip();
        if (RateLimiter::tooManyAttempts($authLimit, 20)) {
            return response()->json(['error' => 'Too many failed authentication attempts.'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($authLimit));
        }

        $key = WebserviceKey::findByPlain($plain);
        if (! $key || ! $key->isUsable()) {
            RateLimiter::hit($authLimit, 60);

            return $this->deny(401, 'Missing or invalid Authorization bearer token.');
        }
        if (! $key->allowsIp($request->ip())) {
            return $this->deny(403, 'This key cannot be used from this address.');
        }

        $limitKey = 'ws:'.$key->id;
        if (RateLimiter::tooManyAttempts($limitKey, 60)) {
            return response()->json(['error' => 'Too many requests.'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($limitKey));
        }
        RateLimiter::hit($limitKey, 60);

        foreach ($scopes as $scope) {
            if ($scope !== '' && ! $key->hasScope($scope)) {
                return $this->deny(403, 'This key cannot access that resource.');
            }
        }

        $key->touchUsage($request->ip());
        $request->attributes->set('webservice_key', $key);

        return $next($request);
    }

    protected function bearer(Request $request): ?string
    {
        $header = trim((string) $request->header('Authorization', ''));
        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }
        $token = trim(substr($header, 7));
        if ($token === '' || ! str_starts_with($token, WebserviceKey::PREFIX)) {
            return null;
        }

        return $token;
    }

    protected function deny(int $status, string $message): Response
    {
        return response()->json(['error' => $message], $status)
            ->header('WWW-Authenticate', 'Bearer realm="webservice"');
    }
}
