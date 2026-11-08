<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Models\PriceAlert;
use App\Domain\Settings\ShopMailer;
use Illuminate\Console\Command;

class PriceAlertTickCommand extends Command
{
    protected $signature = 'wento:price-alerts-tick';

    protected $description = 'Email customers when a watched product price drops.';

    public function handle(ShopMailer $mailer): int
    {
        $sent = 0;
        PriceAlert::query()
            ->with('product')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($mailer, &$sent) {
                foreach ($rows as $alert) {
                    $product = $alert->product;
                    if (! $product) {
                        continue;
                    }
                    $current = (float) $product->sellingPrice();
                    if ($current + 0.009 >= (float) $alert->last_price) {
                        if (abs($current - (float) $alert->last_price) > 0.009) {
                            $alert->update(['last_price' => $current]);
                        }
                        continue;
                    }
                    $mailer->sendHtml(
                        (string) $alert->email,
                        'Price drop: '.($product->translation()?->name ?: $product->sku),
                        '<p>The price dropped from '.e(number_format((float) $alert->last_price, 2))
                        .' to '.e(number_format($current, 2)).'.</p>',
                        'price_alert',
                    );
                    $alert->update([
                        'last_price' => $current,
                        'notified_at' => now(),
                    ]);
                    $sent++;
                }
            });

        $this->info('Sent '.$sent.' price-drop email'.($sent === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
