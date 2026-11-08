<?php

namespace App\Domain\Security;

use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Shared customer password strength (register, reset, profile).
 */
final class CustomerPassword
{
    /**
     * bcrypt only uses the first 72 bytes. A longer password would be
     * stored as if the extra characters were not there.
     */
    public const MAX_LENGTH = 72;

    /**
     * @return list<mixed>
     */
    public static function rules(bool $required = true, bool $confirmed = true): array
    {
        $rules = [];
        $rules[] = $required ? 'required' : 'nullable';
        if ($confirmed) {
            $rules[] = 'confirmed';
        }
        $rules[] = 'max:'.self::MAX_LENGTH;
        $rules[] = PasswordRule::min(8);
        $rules[] = 'regex:/^(?=.*[A-Z])(?=.*\d)[^\s]{8,'.self::MAX_LENGTH.'}$/';

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'password.required' => 'Enter a password.',
            'password.min' => 'Password must be 8 to 72 characters.',
            'password.max' => 'Password must be 8 to 72 characters.',
            'password.confirmed' => 'Confirm password does not match the password.',
            'password.regex' => 'Password needs a capital letter, a number, and no spaces.',
        ];
    }
}
