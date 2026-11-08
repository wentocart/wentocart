<?php

namespace App\Domain\Customer;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Fresh installs get these tables from the schema dump. Existing shops
 * create them the first time GDPR is used.
 */
final class GdprSchema
{
    public static function ensure(): void
    {
        if (! Schema::hasTable('gdpr_requests')) {
            Schema::create('gdpr_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->string('reference', 32)->nullable()->unique();
                $table->string('type', 16);
                $table->string('status', 16)->default('pending');
                $table->string('message', 500);
                $table->json('requested_changes')->nullable();
                $table->timestamps();
                $table->index(['status', 'type']);
            });
        } else {
            if (! Schema::hasColumn('gdpr_requests', 'requested_changes')) {
                Schema::table('gdpr_requests', function (Blueprint $table) {
                    $table->json('requested_changes')->nullable()->after('message');
                });
            }
            if (! Schema::hasColumn('gdpr_requests', 'reference')) {
                Schema::table('gdpr_requests', function (Blueprint $table) {
                    $table->string('reference', 32)->nullable()->unique()->after('customer_id');
                });
            }
        }

        if (Schema::hasColumn('gdpr_requests', 'reference')) {
            self::backfillReferences();
        }

        if (! Schema::hasTable('gdpr_cookie_consents')) {
            Schema::create('gdpr_cookie_consents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->unique()->constrained('customers')->cascadeOnDelete();
                $table->boolean('strictly_necessary')->default(true);
                $table->boolean('basic')->default(false);
                $table->boolean('experience')->default(false);
                $table->boolean('measurements')->default(false);
                $table->boolean('targeting')->default(false);
                $table->timestamps();
            });
        }
    }

    public static function newReference(): string
    {
        do {
            $reference = 'GDPR-'.strtoupper(Str::random(8));
        } while (DB::table('gdpr_requests')->where('reference', $reference)->exists());

        return $reference;
    }

    private static function backfillReferences(): void
    {
        $ids = DB::table('gdpr_requests')->whereNull('reference')->pluck('id');
        foreach ($ids as $id) {
            DB::table('gdpr_requests')->where('id', $id)->update(['reference' => self::newReference()]);
        }
    }
}
