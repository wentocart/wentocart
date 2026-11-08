<?php

namespace App\Domain\Security;

use App\Domain\Security\Models\WebAuthnCredential;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasWebAuthn
{
    public function webauthnCredentials(): MorphMany
    {
        return $this->morphMany(WebAuthnCredential::class, 'authenticatable');
    }

    public function hasPasskeys(): bool
    {
        return $this->webauthnCredentials()->exists();
    }
}
