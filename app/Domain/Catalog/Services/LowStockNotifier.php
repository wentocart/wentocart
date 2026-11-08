<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Notify\AdminInbox;
use App\Domain\Security\AdminPath;
use App\Domain\Settings\InventorySettings;
use App\Domain\Settings\MailEvents;
use App\Domain\Settings\ProductSettings;
use App\Domain\Settings\ShopMailer;
use Throwable;

class LowStockNotifier
{
    public function __construct(protected ShopMailer $mailer) {}

    public function tick(int $limit = 100): int
    {
        if (! InventorySettings::notifications()) {
            return 0;
        }

        MailEvents::ensure();
        $this->clearRecovered();

        $sent = 0;
        $limit = max(1, $limit);
        $default = ProductSettings::defaultLowStockAlert();

        Product::query()
            ->where('notify_low_stock', true)
            ->where('manage_stock', true)
            ->where('is_enabled', true)
            ->whereNull('low_stock_notified_at')
            ->whereRaw(
                '(products.stock_qty - COALESCE(products.reserved_qty, 0)) <= CASE'
                .' WHEN COALESCE(products.low_stock_alert, 0) > 0 THEN products.low_stock_alert'
                .' WHEN COALESCE(products.low_stock_threshold, 0) > 0 THEN products.low_stock_threshold'
                .' ELSE ? END',
                [$default],
            )
            ->with('translations')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (Product $product) use (&$sent) {
                $qty = $product->availableStock();
                $threshold = $this->threshold($product);
                if ($qty > $threshold) {
                    return;
                }
                $name = $product->translation()?->name ?: (string) $product->sku;
                $qtyLabel = $this->formatQty($qty);
                $this->mailer->stockLow(
                    $name,
                    (string) $product->sku,
                    $qtyLabel,
                    (string) $threshold,
                    AdminPath::publicAbsolute('/products/'.$product->id.'/edit'),
                );
                AdminInbox::stockLow($product, $name, $qtyLabel);
                $product->update(['low_stock_notified_at' => now()]);
                $sent++;
            });

        return $sent;
    }

    /**
     * Bell immediately when a sale crosses the alert line.
     * Email stays on the scheduled tick, which also rings the bell once.
     */
    public function bellIfLow(Product $product): void
    {
        if (! InventorySettings::notifications()) {
            return;
        }

        try {
            $product = $product->fresh('translations') ?? $product;
        } catch (Throwable) {
            return;
        }

        if (! $product->notify_low_stock || ! $product->manage_stock || ! $product->is_enabled) {
            return;
        }

        $qty = $product->availableStock();
        if ($qty > $this->threshold($product)) {
            return;
        }

        AdminInbox::stockLow(
            $product,
            $product->translation()?->name ?: (string) $product->sku,
            $this->formatQty($qty),
        );
    }

    protected function clearRecovered(): void
    {
        $default = ProductSettings::defaultLowStockAlert();

        Product::query()
            ->whereNotNull('low_stock_notified_at')
            ->whereRaw(
                '(products.stock_qty - COALESCE(products.reserved_qty, 0)) > CASE'
                .' WHEN COALESCE(products.low_stock_alert, 0) > 0 THEN products.low_stock_alert'
                .' WHEN COALESCE(products.low_stock_threshold, 0) > 0 THEN products.low_stock_threshold'
                .' ELSE ? END',
                [$default],
            )
            ->update(['low_stock_notified_at' => null]);
    }

    protected function threshold(Product $product): int
    {
        $alert = (int) ($product->low_stock_alert ?? 0);
        if ($alert > 0) {
            return $alert;
        }
        $threshold = (int) ($product->low_stock_threshold ?? 0);
        if ($threshold > 0) {
            return $threshold;
        }

        return ProductSettings::defaultLowStockAlert();
    }

    protected function formatQty(float $qty): string
    {
        $formatted = number_format($qty, 3, '.', '');

        return rtrim(rtrim($formatted, '0'), '.') ?: '0';
    }
}
