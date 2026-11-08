<?php

namespace App\Models;

use App\Domain\Api\Graphql\IntegrationTokenPermissions;
use App\Domain\Auth\Models\Profile;
use App\Domain\Auth\Models\Role;
use App\Domain\Auth\Models\Team;
use App\Domain\Auth\PageCatalog;
use App\Domain\Security\AdminPath;
use App\Domain\Security\HasWebAuthn;
use Laravel\Sanctum\PersonalAccessToken;
use App\Domain\Settings\MailEvents;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\ShopMailer;
use Database\Factories\AdminFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'first_name', 'last_name', 'email', 'password', 'is_enabled', 'team_id', 'profile_id', 'job_title', 'phone', 'note', 'avatar_path', 'jump_shortcut'])]
#[Hidden(['password', 'remember_token', 'totp_secret'])]
class Admin extends Authenticatable
{
    /** @use HasFactory<AdminFactory> */
    use HasApiTokens, HasFactory, HasWebAuthn, Notifiable;

    protected $guard = 'admin';

    protected static function newFactory(): AdminFactory
    {
        return AdminFactory::new();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_enabled' => 'boolean',
            'totp_confirmed_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'admin_role');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->roles->contains('slug', 'super_admin');
    }

    public static function superAdminCount(): int
    {
        return static::query()->whereHas('roles', fn ($query) => $query->where('slug', 'super_admin'))->count();
    }

    public function isLastSuperAdmin(): bool
    {
        return $this->isSuperAdmin() && static::superAdminCount() <= 1;
    }

    public function applyProfile(?Profile $profile): void
    {
        $this->profile_id = $profile?->id;
        $this->save();
    }

    public function grantSuperAdmin(): void
    {
        $superId = Role::query()->where('slug', 'super_admin')->value('id');
        if ($superId) {
            $this->roles()->sync([$superId]);
            $this->unsetRelation('roles');
        }
    }

    public function hasPermission(string $slug): bool
    {
        $slugs = $this->permissionSlugs();
        $allowed = in_array('*', $slugs, true) || in_array($slug, $slugs, true);
        if (! $allowed) {
            return false;
        }
        $token = $this->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            return IntegrationTokenPermissions::allows($token, $slug);
        }

        return true;
    }

    public function avatarUrl(): ?string
    {
        $path = trim((string) $this->avatar_path);
        if ($path === '') {
            return null;
        }

        return asset('storage/'.$path);
    }

    /** @return list<string> */
    public function permissionSlugs(): array
    {
        if ($this->isSuperAdmin()) {
            return ['*'];
        }

        $this->loadMissing(['profile.permissions', 'roles.permissions']);

        $granted = [];
        if ($this->profile) {
            $granted = array_merge($granted, $this->profile->permissions->pluck('slug')->all());
        }
        foreach ($this->roles as $role) {
            $granted = array_merge($granted, $role->permissions->pluck('slug')->all());
        }

        return PageCatalog::expand(array_values(array_unique($granted)));
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        if (! $this->is_enabled) {
            return;
        }

        $url = rtrim((string) Setting::getValue('shop_url', config('app.url')), '/')
            .'/'.AdminPath::segment().'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $this->email,
            ]);

        MailEvents::ensure();
        app(ShopMailer::class)->sendRecipient(
            MailEvents::ADMIN_FORGOT_PASSWORD,
            'staff',
            [
                'admin_name' => (string) ($this->name ?: $this->email),
                'admin_email' => (string) $this->email,
                'reset_url' => $url,
            ],
            $this->email,
        );
    }
}
