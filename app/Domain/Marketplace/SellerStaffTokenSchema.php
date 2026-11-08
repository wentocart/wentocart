<?php

namespace App\Domain\Marketplace;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class SellerStaffTokenSchema
{
    public static function ensure(): void
    {
        SellerTeamSchema::ensure();
        if (Schema::hasTable('seller_staff_tokens')) {
            return;
        }
        Schema::create('seller_staff_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_user_id')->constrained('seller_users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('name', 80)->default('staff-session');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }
}
