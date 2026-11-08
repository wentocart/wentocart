<?php

namespace App\Domain\Security\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['authenticatable_type', 'authenticatable_id', 'credential_id', 'public_key', 'sign_count', 'name'])]
class WebAuthnCredential extends Model
{
    protected $table = 'webauthn_credentials';
    protected function casts(): array
    {
        return [
            'sign_count' => 'integer',
        ];
    }

    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }
}
