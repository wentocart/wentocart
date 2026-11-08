<?php

namespace App\Console\Commands;

use App\Domain\Checkout\Models\Cart;
use App\Domain\Settings\PrivacySettings;
use App\Domain\Settings\ShopMailer;
use Illuminate\Console\Command;

class AbandonedCartCommand extends Command
{
    protected $signature = 'wento:carts-remind {--minutes= : Minutes idle before a reminder}';

    protected $description = 'Email signed-in customers who left items in the cart.';

    public function handle(ShopMailer $mailer, \App\Domain\Checkout\Services\CartRecoveryService $recovery): int
    {
        $minutes = (int) ($this->option('minutes') ?: PrivacySettings::abandonedCartMinutes());
        $cutoff = now()->subMinutes(max(15, $minutes));
        $carts = Cart::query()
            ->whereNotNull('customer_id')
            ->where('updated_at', '<', $cutoff)
            ->whereHas('items')
            ->with(['customer:id,email,name', 'items'])
            ->limit(100)
            ->get();

        $sent = 0;
        foreach ($carts as $cart) {
            $meta = is_array($cart->meta) ? $cart->meta : [];
            if (! empty($meta['abandoned_reminded_at'])) {
                continue;
            }
            $email = $cart->customer?->email;
            if (! is_string($email) || $email === '') {
                continue;
            }
            $recoveryUrl = $recovery->recoveryUrl($cart);
            $mailer->cartAbandoned(
                $email,
                (string) ($cart->customer?->name ?? ''),
                $recoveryUrl,
            );
            $meta = is_array($cart->fresh()->meta) ? $cart->fresh()->meta : [];
            $meta['abandoned_reminded_at'] = now()->toIso8601String();
            $cart->update(['meta' => $meta]);
            $sent++;
        }

        $this->info('Reminded '.$sent.' abandoned cart'.($sent === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
