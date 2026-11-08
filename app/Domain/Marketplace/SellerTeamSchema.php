<?php

namespace App\Domain\Marketplace;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class SellerTeamSchema
{
    public static function ensure(): void
    {
        if (! Schema::hasTable('seller_roles')) {
            Schema::create('seller_roles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('seller_id')->constrained('sellers')->cascadeOnDelete();
                $table->string('name', 120);
                $table->string('description', 500)->nullable();
                $table->string('permission_type', 16)->default('custom'); // all|custom
                $table->json('permissions')->nullable();
                $table->timestamps();
                $table->unique(['seller_id', 'name']);
            });
        }

        if (! Schema::hasTable('seller_users')) {
            Schema::create('seller_users', function (Blueprint $table) {
                $table->id();
                $table->foreignId('seller_id')->constrained('sellers')->cascadeOnDelete();
                $table->foreignId('role_id')->constrained('seller_roles')->restrictOnDelete();
                $table->string('name', 120);
                $table->string('email', 190);
                $table->string('phone', 40)->nullable();
                $table->string('password');
                $table->boolean('is_enabled')->default(true);
                $table->timestamp('last_login_at')->nullable();
                $table->timestamps();
                $table->unique(['seller_id', 'email']);
            });
        }
    }
}
