<?php

namespace App\Domain\Dashboard\Models;

use App\Domain\Auth\Models\Profile;
use App\Models\Admin;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'description', 'slug', 'visibility', 'is_default', 'is_enabled', 'created_by_admin_id',
])]
class Dashboard extends Model
{
    public const VISIBILITY = [
        'assigned' => 'Selected employee profiles',
        'all_admins' => 'Every admin',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    public function widgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class)->orderBy('position')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(Admin::class, 'dashboard_admin')->withTimestamps();
    }

    public function assignedProfiles(): BelongsToMany
    {
        return $this->belongsToMany(Profile::class, 'dashboard_profile')->withTimestamps();
    }

    public function scopeAccessibleTo(Builder $query, Admin $admin): Builder
    {
        if ($admin->isSuperAdmin() || $admin->hasPermission('dashboards.manage')) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($admin) {
            $inner->where('visibility', 'all_admins')
                ->orWhere('created_by_admin_id', $admin->id)
                ->orWhereHas('assignees', fn (Builder $assignees) => $assignees->whereKey($admin->id));

            if ($admin->profile_id) {
                $inner->orWhereHas(
                    'assignedProfiles',
                    fn (Builder $profiles) => $profiles->whereKey($admin->profile_id),
                );
            }
        });
    }

    public function isAccessibleTo(Admin $admin): bool
    {
        if ($admin->isSuperAdmin() || $admin->hasPermission('dashboards.manage')) {
            return true;
        }
        if ($this->visibility === 'all_admins') {
            return true;
        }
        if ((int) $this->created_by_admin_id === (int) $admin->id) {
            return true;
        }
        if ($this->relationLoaded('assignees') ? $this->assignees->contains('id', $admin->id) : $this->assignees()->whereKey($admin->id)->exists()) {
            return true;
        }
        if (! $admin->profile_id) {
            return false;
        }

        return $this->relationLoaded('assignedProfiles')
            ? $this->assignedProfiles->contains('id', $admin->profile_id)
            : $this->assignedProfiles()->whereKey($admin->profile_id)->exists();
    }

    public function visibilityLabel(): string
    {
        return self::VISIBILITY[$this->visibility] ?? $this->visibility;
    }
}
