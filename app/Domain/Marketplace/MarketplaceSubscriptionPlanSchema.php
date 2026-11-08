<?php

namespace App\Domain\Marketplace;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class MarketplaceSubscriptionPlanSchema
{
    public static function ensure(): void
    {
        if (! Schema::hasTable('marketplace_subscription_plans')) {
            Schema::create('marketplace_subscription_plans', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('code', 64)->unique();
                $table->text('description')->nullable();
                $table->decimal('price', 12, 2)->default(0);
                $table->string('billing_period', 32)->default('month'); // month|year|lifetime
                $table->unsignedInteger('product_limit')->nullable();
                $table->unsignedInteger('order_limit')->nullable();
                $table->unsignedInteger('max_staff')->nullable();
                $table->boolean('allows_communication')->default(true);
                $table->boolean('is_enabled')->default(true);
                $table->unsignedInteger('position')->default(0);
                $table->json('permissions')->nullable();
                $table->json('allowed_product_types')->nullable();
                $table->decimal('commission_value', 8, 2)->nullable();
                $table->boolean('is_featured')->default(false);
                $table->boolean('is_default')->default(false);
                $table->unsignedInteger('duration_days')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('marketplace_seller_subscriptions')) {
            Schema::create('marketplace_seller_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('seller_id')->constrained('sellers')->cascadeOnDelete();
                $table->foreignId('plan_id')->constrained('marketplace_subscription_plans')->restrictOnDelete();
                $table->string('status', 32)->default('active'); // pending|active|expired|cancelled
                $table->string('payment_method', 40)->nullable();
                $table->decimal('price', 12, 2)->nullable();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->timestamps();
                $table->index(['seller_id', 'status']);
            });
        }

        if (Schema::hasTable('marketplace_subscription_plans')
            && ! Schema::hasColumn('marketplace_subscription_plans', 'max_staff')) {
            Schema::table('marketplace_subscription_plans', function (Blueprint $table) {
                $table->unsignedInteger('max_staff')->nullable();
            });
        }

        if (Schema::hasTable('marketplace_seller_subscriptions')
            && ! Schema::hasColumn('marketplace_seller_subscriptions', 'payment_method')) {
            Schema::table('marketplace_seller_subscriptions', function (Blueprint $table) {
                $table->string('payment_method', 40)->nullable();
                $table->decimal('price', 12, 2)->nullable();
            });
        }

        if (Schema::hasTable('marketplace_seller_subscriptions')
            && ! Schema::hasColumn('marketplace_seller_subscriptions', 'reminder_days_sent')) {
            Schema::table('marketplace_seller_subscriptions', function (Blueprint $table) {
                $table->json('reminder_days_sent')->nullable();
            });
        }

        if (Schema::hasTable('marketplace_subscription_plans')
            && ! Schema::hasColumn('marketplace_subscription_plans', 'allowed_product_types')) {
            Schema::table('marketplace_subscription_plans', function (Blueprint $table) {
                $table->json('allowed_product_types')->nullable();
            });
        }

        if (Schema::hasTable('marketplace_subscription_plans')
            && ! Schema::hasColumn('marketplace_subscription_plans', 'commission_value')) {
            Schema::table('marketplace_subscription_plans', function (Blueprint $table) {
                $table->decimal('commission_value', 8, 2)->nullable();
                $table->boolean('is_featured')->default(false);
                $table->boolean('is_default')->default(false);
            });
        }

        if (Schema::hasTable('marketplace_subscription_plans')
            && ! Schema::hasColumn('marketplace_subscription_plans', 'duration_days')) {
            Schema::table('marketplace_subscription_plans', function (Blueprint $table) {
                $table->unsignedInteger('duration_days')->nullable();
            });
        }

        if (Schema::hasTable('marketplace_seller_subscriptions')
            && ! Schema::hasColumn('marketplace_seller_subscriptions', 'paypal_token')) {
            Schema::table('marketplace_seller_subscriptions', function (Blueprint $table) {
                $table->string('paypal_token', 64)->nullable()->index();
            });
        }

        // Generic gateway token (Stripe / Razorpay / PayPal share paypal_token column).
        if (Schema::hasTable('marketplace_seller_subscriptions')
            && ! Schema::hasColumn('marketplace_seller_subscriptions', 'payment_token')) {
            Schema::table('marketplace_seller_subscriptions', function (Blueprint $table) {
                $table->string('payment_token', 64)->nullable()->index();
            });
        }
    }
}
