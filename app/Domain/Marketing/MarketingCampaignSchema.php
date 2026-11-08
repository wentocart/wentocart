<?php

namespace App\Domain\Marketing;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign audience columns (customer group segment). Fresh installs may already
 * have the column; existing shops get it on first campaign audience use.
 */
final class MarketingCampaignSchema
{
    public static function ensure(): void
    {
        if (! Schema::hasTable('newsletter_campaigns')) {
            return;
        }
        if (! Schema::hasColumn('newsletter_campaigns', 'customer_group_code')) {
            Schema::table('newsletter_campaigns', function (Blueprint $table) {
                $table->string('customer_group_code')->nullable();
            });
        }
    }
}
