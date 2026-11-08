<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('wento:extensions-tick')->everyFiveMinutes();
Schedule::command('wento:orders-cancel-unpaid')->everyFiveMinutes();
Schedule::command('wento:gift-cards-release-expired-holds')->everyFiveMinutes();
Schedule::command('wento:gift-cards-expire')->hourly();
Schedule::command('wento:carts-remind')->hourly();
// Every minute so hour-frequency plans renew near their next_renewal_at (not up to ~1h late).
Schedule::command('wento:subscriptions-tick')->everyMinute();
Schedule::command('wento:stock-alerts-tick')->hourly();
Schedule::command('wento:preorder-notify-tick')->hourly();
Schedule::command('wento:price-alerts-tick')->daily();

// Marketplace: release pending earnings when available_at has passed (return-window mode).
Schedule::command('wento:marketplace-release-earnings')->hourly();
Schedule::command('wento:marketplace-subscription-tick')->daily();
Schedule::command('wento:webhooks-tick')->everyMinute();
Schedule::command('wento:health-schedule')->daily();
Schedule::command('wento:prune-admin-api-audits')->daily();
Schedule::command('wento:maintain-storefront-keys --all')->daily()->at('02:15');
Schedule::command('wento:manage-storefront-keys unused --unused=90')->monthlyOn(1, '10:00');
