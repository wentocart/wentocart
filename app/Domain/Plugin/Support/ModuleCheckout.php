<?php

namespace App\Domain\Plugin\Support;

use App\Domain\Plugin\PluginManifest;

/**
 * Checkout helpers for Safe extensions (fees / due-today / method filters).
 */
class ModuleCheckout
{
    public function __construct(protected PluginManifest $manifest) {}

    /**
     * Register a totals adjuster. Callback receives summary context and may return rows:
     * ['type' => 'fee'|'due_now', 'code' => 'instalment_fee', 'label' => '…', 'amount' => 12.5]
     */
    public function adjust(callable $callback): void
    {
        ModuleBuses::checkoutAdjust($this->manifest, $callback);
    }

    /**
     * Hide payment methods. Callback receives context + methods; return ['hide_ids' => [...]] or hide_codes.
     */
    public function filterPayments(callable $callback): void
    {
        ModuleBuses::filterPayments($this->manifest, $callback);
    }

    /**
     * Hide shipping methods. Same return shape as filterPayments.
     */
    public function filterShipping(callable $callback): void
    {
        ModuleBuses::filterShipping($this->manifest, $callback);
    }
}
