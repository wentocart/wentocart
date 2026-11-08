<?php

namespace App\Domain\Auth;

use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\CustomerSettings;
use App\Domain\Settings\SmsSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class SmsOtp
{
    public const PROOF_TTL_MINUTES = 15;

    /**
     * Post-password step for an already-authenticated customer record.
     */
    public function send(Customer $customer): string
    {
        return $this->sendTo('customer:'.$customer->id, (string) $customer->phone, 'otp');
    }

    /**
     * Pre-login step for a phone number with no customer in context yet
     * (registration, passwordless login, number change).
     */
    public function sendTo(string $scope, string $phone, string $purpose = 'login'): string
    {
        $phone = trim($phone);
        $key = $this->key($scope.'|'.$phone);
        $state = $this->state($key);
        $cooldown = CustomerSettings::otpResendSeconds();
        if ($state !== null && $cooldown > 0 && (time() - (int) $state['sent_at']) < $cooldown) {
            $retry = $cooldown - (time() - (int) $state['sent_at']);
            throw new TooManyRequestsHttpException($retry, 'Wait '.$retry.'s before requesting a new code.');
        }

        $code = (string) random_int(100000, 999999);
        // A resend issues a fresh code but never resets the attempt counter.
        Cache::put($key, [
            'hash' => Hash::make($code),
            'attempts' => (int) ($state['attempts'] ?? 0),
            'sent_at' => time(),
        ], now()->addMinutes(CustomerSettings::otpExpiryMinutes()));
        app(SmsSender::class)->send($phone, 'Your sign-in code is '.$code, $purpose);

        return $code;
    }

    public function verify(Customer|string $customerOrScope, string $code, ?string $phone = null): bool
    {
        // A Customer model carries its number, so the lookup key matches
        // send(), which binds the code to customer id + phone.
        $key = $customerOrScope instanceof Customer
            ? $this->key('customer:'.$customerOrScope->id.'|'.trim((string) $customerOrScope->phone))
            : $this->key($customerOrScope.'|'.trim((string) $phone));
        $state = $this->state($key);
        if (! is_array($state) || ! is_string($state['hash'] ?? null) || $state['hash'] === '') {
            return false;
        }
        if ((int) $state['attempts'] >= CustomerSettings::otpMaxAttempts()) {
            return false;
        }
        $state['attempts'] = (int) $state['attempts'] + 1;
        $ok = Hash::check(trim($code), $state['hash']);
        if ($ok) {
            Cache::forget($key);

            return true;
        }
        Cache::put($key, $state, now()->addMinutes(CustomerSettings::otpExpiryMinutes()));

        return false;
    }

    public function attemptsLeft(Customer|string $customerOrScope, ?string $phone = null): int
    {
        $key = $customerOrScope instanceof Customer
            ? $this->key('customer:'.$customerOrScope->id.'|'.trim((string) $customerOrScope->phone))
            : $this->key($customerOrScope.'|'.trim((string) $phone));
        $state = $this->state($key);

        return max(0, CustomerSettings::otpMaxAttempts() - (int) ($state['attempts'] ?? 0));
    }

    /**
     * Single-use proof that a phone number passed OTP verification, handed
     * to registration / passwordless login / number change.
     */
    public function issueProof(string $phone, string $purpose): string
    {
        $token = Str::random(40);
        Cache::put($this->proofKey($token), [
            'phone' => $phone,
            'purpose' => $purpose,
        ], now()->addMinutes(self::PROOF_TTL_MINUTES));

        return $token;
    }

    /**
     * Consume a proof token. Returns the verified phone or null.
     */
    public function consumeProof(string $token, string $purpose): ?string
    {
        $key = $this->proofKey(trim($token));
        $state = $this->state($key);
        if (! is_array($state) || ($state['purpose'] ?? null) !== $purpose || ! is_string($state['phone'] ?? null)) {
            return null;
        }
        Cache::forget($key);

        return $state['phone'];
    }

    public function revealInResponse(): bool
    {
        return app()->environment('local', 'testing');
    }

    protected function key(string $suffix): string
    {
        return 'sms-otp:'.sha1($suffix);
    }

    protected function proofKey(string $token): string
    {
        return 'sms-proof:'.sha1($token);
    }

    private function state(string $key): ?array
    {
        $state = Cache::get($key);

        return is_array($state) ? $state : null;
    }
}
