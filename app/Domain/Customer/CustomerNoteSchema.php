<?php

namespace App\Domain\Customer;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * existing shops create it on first use.
 */
final class CustomerNoteSchema
{
    public static function ensure(): void
    {
        if (Schema::hasTable('customer_notes')) {
            return;
        }

        Schema::create('customer_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('note');
            $table->boolean('customer_notified')->default(false);
            $table->timestamps();
            $table->index(['customer_id', 'created_at']);
        });
    }
}
