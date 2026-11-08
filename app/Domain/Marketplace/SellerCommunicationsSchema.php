<?php

namespace App\Domain\Marketplace;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class SellerCommunicationsSchema
{
    public static function ensure(): void
    {
        if (! Schema::hasTable('sellers')) {
            return;
        }
        if (! Schema::hasColumn('sellers', 'communications_blocked_at')) {
            Schema::table('sellers', function (Blueprint $table) {
                $table->timestamp('communications_blocked_at')->nullable();
            });
        }
    }
}
