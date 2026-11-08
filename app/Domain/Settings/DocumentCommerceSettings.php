<?php

namespace App\Domain\Settings;

final class DocumentCommerceSettings
{
    public const DEFAULT_INVOICE_FORMAT = 'INV-{number}';

    public const DEFAULT_INVOICE_START = 10001;

    public const DEFAULT_CREDIT_FORMAT = 'CN-{number}';

    /** @var list<string> */
    public const CREATE_AT_OPTIONS = ['order', 'payment', 'fulfillment'];

    /** @var list<string> */
    public const LEGACY_PROGRESS_STATUSES = ['processing', 'shipped', 'completed'];

    public static function invoicesEnabled(): bool
    {
        return ShopSetting::bool('documents.invoices_enabled', true);
    }

    public static function invoiceFormat(): string
    {
        $raw = trim((string) ShopSetting::get('documents.invoice_format', self::DEFAULT_INVOICE_FORMAT));
        if ($raw === '' || ! str_contains($raw, '{number}')) {
            return self::DEFAULT_INVOICE_FORMAT;
        }

        return $raw;
    }

    public static function invoiceStart(): int
    {
        $value = (int) ShopSetting::get('documents.invoice_start', self::DEFAULT_INVOICE_START);

        return max(1, min(999999999, $value));
    }

    public static function createAt(): string
    {
        $raw = trim((string) ShopSetting::get('documents.invoice_create_at', 'payment'));

        return in_array($raw, self::CREATE_AT_OPTIONS, true) ? $raw : 'payment';
    }

    /**
     * Statuses that create an invoice. Any one of them is enough, and the invoice stays after that.
     *
     * @param  list<string>  $codes
     * @return list<string>
     */
    public static function normalizeCreateStatuses(array $codes): array
    {
        $known = OrderSettings::statusCodes();
        $wanted = [];
        foreach ($codes as $code) {
            $code = OrderSettings::codeFromChoiceValue(trim((string) $code));
            if ($code !== '') {
                $wanted[$code] = true;
            }
        }
        $ordered = [];
        foreach ($known as $code) {
            if (isset($wanted[$code])) {
                $ordered[] = $code;
            }
        }

        return $ordered;
    }

    /**
     * @return list<string>
     */
    public static function createStatuses(): array
    {
        $raw = ShopSetting::get('documents.invoice_create_statuses');
        if (is_string($raw) && trim($raw) !== '') {
            $stored = self::normalizeCreateStatuses(explode(',', $raw));
            if ($stored !== []) {
                return $stored;
            }
        }

        return self::legacyCreateStatuses();
    }

    /**
     * @return list<string>
     */
    protected static function legacyCreateStatuses(): array
    {
        $known = OrderSettings::statusCodes();
        if (self::createAt() === 'order') {
            return $known;
        }

        $unlocked = \App\Domain\Order\Models\OrderStage::query()
            ->where('unlock_invoice', true)
            ->pluck('code')
            ->map(fn ($code) => trim((string) $code))
            ->filter()
            ->all();
        $picked = array_values(array_intersect($known, $unlocked));
        if ($picked !== []) {
            return $picked;
        }

        $fallback = array_values(array_intersect($known, self::LEGACY_PROGRESS_STATUSES));

        return $fallback !== [] ? $fallback : $known;
    }

    public static function autoSend(): bool
    {
        return ShopSetting::bool('documents.invoice_auto_send', false);
    }

    public static function creditFormat(): string
    {
        $raw = trim((string) ShopSetting::get('documents.credit_format', self::DEFAULT_CREDIT_FORMAT));
        if ($raw === '' || ! str_contains($raw, '{number}')) {
            return self::DEFAULT_CREDIT_FORMAT;
        }

        return $raw;
    }

    public static function packingSlips(): bool
    {
        return ShopSetting::bool('documents.packing_slips', true);
    }

    public static function orderPdf(): bool
    {
        return ShopSetting::bool('documents.order_pdf', true);
    }

    public static function formatInvoiceNumber(\App\Domain\Order\Models\Order $order): string
    {
        return self::assignNumber($order, 'invoice_number', 'invoice', self::invoiceFormat(), self::invoiceStart());
    }

    public static function formatCreditNumber(\App\Domain\Order\Models\Order $order): string
    {
        return self::assignNumber($order, 'credit_number', 'credit', self::creditFormat(), self::invoiceStart());
    }

    protected static function assignNumber(
        \App\Domain\Order\Models\Order $order,
        string $key,
        string $sequence,
        string $format,
        int $start,
    ): string {
        $details = is_array($order->fulfillment_details) ? $order->fulfillment_details : [];
        if (! empty($details[$key]) && is_string($details[$key])) {
            return $details[$key];
        }
        $seq = DocumentSequence::next($sequence, $start);
        $number = str_replace('{number}', (string) $seq, $format);
        $details[$key] = $number;
        $order->fulfillment_details = $details;
        if ($order->exists) {
            $order->save();
        }

        return $number;
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        return [
            'invoices_enabled' => self::invoicesEnabled(),
            'invoice_format' => self::invoiceFormat(),
            'invoice_start' => self::invoiceStart(),
            'invoice_create_at' => self::createAt(),
            'invoice_create_statuses' => self::createStatuses(),
            'status_choices' => OrderSettings::statusChoices(),
            'invoice_auto_send' => self::autoSend(),
            'credit_format' => self::creditFormat(),
            'packing_slips' => self::packingSlips(),
            'order_pdf' => self::orderPdf(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function storefrontPayload(): array
    {
        return [
            'invoices_enabled' => self::invoicesEnabled(),
            'packing_slips' => self::packingSlips(),
            'order_pdf' => self::orderPdf(),
        ];
    }
}
