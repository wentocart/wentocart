<?php

namespace App\Domain\Marketplace;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class FlagReasonSchema
{
    public static function ensure(): void
    {
        if (Schema::hasTable('marketplace_flag_reasons')) {
            return;
        }
        Schema::create('marketplace_flag_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('label', 160);
            $table->string('code', 64)->unique();
            $table->string('applies_to', 32)->default('both'); // seller|product|both
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }
}
