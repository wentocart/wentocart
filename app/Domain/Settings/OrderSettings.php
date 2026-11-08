<?php

namespace App\Domain\Settings;

use App\Domain\Localization\Money;
use App\Domain\Order\Models\OrderStage;
use App\Domain\Settings\Models\Setting;
use App\Domain\Store\StoreContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

final class OrderSettings
{
    public const DEFAULT_NUMBER_FORMAT = 'WENTO-{number}';

    public const DEFAULT_START = 10001;

    public const DEFAULT_STATUS = 'pending';

    public const DEFAULT_PAYMENT_SUCCESS_STATUS = 'processing';

    public const DEFAULT_PAYMENT_FAIL_STATUS = 'cancelled';

    public const DEFAULT_CANCEL_UNTIL = 'processing';

    /** @var array<string, string> */
    private const LEGACY_CANCEL_UNTIL = [
        'before_processing' => 'processing',
        'before_shipped' => 'shipped',
    ];

    public const DEFAULT_UNPAID_TIMEOUT_MINUTES = 1440;

    public const DEFAULT_RESERVE_MINUTES = 30;

    public const DEFAULT_REDUCE_STOCK_AT = 'pending';

    /** @var list<string> */
    public const STATUS_OPTIONS = ['pending', 'processing'];

    /** @var list<string> */
    public const PAYMENT_FAIL_STATUS_OPTIONS = ['cancelled', 'pending'];

    /**
     * Older saved values. New saves store an order-status code.
     *
     * @var list<string>
     */
    public const CANCEL_UNTIL_OPTIONS = ['before_processing', 'before_shipped'];

    /**
     * Older saved values. New saves store an order-status code.
     *
     * @var list<string>
     */
    public const REDUCE_STOCK_AT_OPTIONS = ['order', 'payment', 'processing'];

    // ── Order defaults ───────────────────────────────────────────────

    public static function numberFormat(): string
    {
        $raw = trim((string) ShopSetting::get('orders.number_format', self::DEFAULT_NUMBER_FORMAT));
        if ($raw === '' || ! str_contains($raw, '{number}')) {
            return self::DEFAULT_NUMBER_FORMAT;
        }

        return $raw;
    }

    public static function numberPrefix(): string
    {
        $format = self::numberFormat();
        $pos = strpos($format, '{number}');

        return $pos === false ? 'WENTO-' : substr($format, 0, $pos);
    }

    public static function numberStart(): int
    {
        return self::clampInt(ShopSetting::get('orders.number_start', self::DEFAULT_START), 1, 999999999, self::DEFAULT_START);
    }

    public static function formatNumber(int $sequence): string
    {
        return str_replace('{number}', (string) $sequence, self::numberFormat());
    }

    /**
     * Enabled order statuses for the current shop.
     * Select `value` is the stage id; `code` remains for business logic / stored settings.
     *
     * @return list<array{value: string, code: string, label: string}>
     */
    public static function statusChoices(): array
    {
        $choices = [];
        try {
            \App\Domain\Order\OrderStageCatalog::seed();
            $rows = OrderStage::query()->enabled()->ordered()->get(['id', 'code', 'name']);
        } catch (\Throwable) {
            $rows = [];
        }
        foreach ($rows as $stage) {
            $code = trim((string) $stage->code);
            if ($code === '') {
                continue;
            }
            $choices[$code] = [
                'value' => (string) $stage->id,
                'code' => $code,
                'label' => (string) ($stage->name ?: $code),
            ];
        }
        if ($choices === []) {
            $choices = [
                'pending' => ['value' => 'pending', 'code' => 'pending', 'label' => 'Pending'],
                'processing' => ['value' => 'processing', 'code' => 'processing', 'label' => 'Processing'],
            ];
        }

        return array_values($choices);
    }

    /**
     * @return list<string>
     */
    public static function statusCodes(): array
    {
        return array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row['code'] ?? ''),
            self::statusChoices()
        )));
    }

    public static function codeFromChoiceValue(string|int|null $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return self::DEFAULT_STATUS;
        }
        foreach (self::statusChoices() as $choice) {
            if ((string) ($choice['value'] ?? '') === $raw || (string) ($choice['code'] ?? '') === $raw) {
                return (string) $choice['code'];
            }
        }

        return self::DEFAULT_STATUS;
    }

    public static function choiceValueFromCode(string $code): string
    {
        $code = trim($code);
        foreach (self::statusChoices() as $choice) {
            if ((string) ($choice['code'] ?? '') === $code) {
                return (string) $choice['value'];
            }
        }

        return (string) (self::statusChoices()[0]['value'] ?? self::DEFAULT_STATUS);
    }

    public static function defaultStatus(): string
    {
        $raw = trim((string) ShopSetting::get('orders.default_status', self::DEFAULT_STATUS));
        $codes = self::statusCodes();
        // Accept legacy stored codes or newer stage ids.
        $asCode = self::codeFromChoiceValue($raw);

        if (in_array($asCode, $codes, true)) {
            return $asCode;
        }

        return in_array(self::DEFAULT_STATUS, $codes, true) ? self::DEFAULT_STATUS : (string) ($codes[0] ?? self::DEFAULT_STATUS);
    }

    /**
     * Min and max are stored in the shop default currency.
     * $grand is the checkout total in $currency.
     */
    public static function assertWithinTotal(float $grand, ?string $currency = null): void
    {
        $currency = strtoupper(trim((string) ($currency ?: Money::currentCode())));
        if ($currency === '') {
            $currency = Money::defaultCode();
        }
        $base = Money::toDefault($grand, $currency);
        $min = self::minimumOrderEnabled() ? self::minAmount() : 0.0;
        if ($min > 0 && $base + 0.00001 < $min) {
            throw ValidationException::withMessages([
                'order' => 'Minimum order amount is '.Money::format(Money::fromDefault($min, $currency), $currency).'.',
            ]);
        }
        $max = self::maxAmount();
        if ($max !== null && $base - 0.00001 > $max) {
            throw ValidationException::withMessages([
                'order' => 'Maximum order amount is '.Money::format(Money::fromDefault($max, $currency), $currency).'.',
            ]);
        }
    }

    public static function minAmount(): float
    {
        return max(0, round((float) ShopSetting::get('orders.min_amount', 0), 2));
    }

    public static function maxAmount(): ?float
    {
        $raw = ShopSetting::get('orders.max_amount', null);
        if ($raw === null || $raw === '' || (float) $raw <= 0) {
            return null;
        }

        return round((float) $raw, 2);
    }

    /**
     * When off, min amount is not enforced (even if a value is stored).
     */
    public static function minimumOrderEnabled(): bool
    {
        return ShopSetting::bool('orders.minimum_order_enabled', true);
    }

    /**
     */
    public static function adminReorderEnabled(): bool
    {
        return ShopSetting::bool('orders.admin_reorder_enabled', true);
    }

    /**
     * Returns null when OK, else a REST-shaped message for HTTP 422.
     */
    public static function assertAdminPlaceMinimum(float $subtotal, float $discountTotal = 0.0): ?string
    {
        if (! self::minimumOrderEnabled()) {
            return null;
        }
        $min = self::minAmount();
        if ($min <= 0) {
            return null;
        }
        $includeDiscount = ShopSetting::bool('orders.minimum_order_include_discount', false);
        $comparable = $includeDiscount ? max(0.0, $subtotal - $discountTotal) : $subtotal;
        $minWhole = (float) (int) $min;
        if ($comparable + 0.00001 < $minWhole) {
            return 'The minimum order amount is not met.';
        }

        return null;
    }

    // ── Checkout & customer ──────────────────────────────────────────

    /**
     * Canonical home is Configure → Customers. Kept here so order/checkout
     * callers keep working without importing CustomerSettings everywhere.
     */
    public static function guestCheckout(): bool
    {
        return CustomerSettings::guestCheckout();
    }

    public static function customerNotesEnabled(): bool
    {
        return ShopSetting::bool('orders.customer_notes_enabled', true);
    }

    public static function guestLookup(): bool
    {
        return ShopSetting::bool('orders.guest_lookup', false);
    }

    public static function allowCancellation(): bool
    {
        return ShopSetting::bool('orders.allow_cancellation', true);
    }

    /**
     * Map a stored cutoff onto an order-status code.
     * before_processing and before_shipped stay valid so older shops keep working.
     */
    public static function canonicalCancellationUntil(string $raw): string
    {
        $raw = strtolower(trim($raw));

        return self::LEGACY_CANCEL_UNTIL[$raw] ?? $raw;
    }

    /**
     * True when $status is earlier in $orderedCodes than $cutoff.
     * The cutoff itself is not cancellable: "until Shipped" means pending and processing.
     *
     * @param  list<string>  $orderedCodes
     */
    public static function statusComesBeforeCutoff(string $status, string $cutoff, array $orderedCodes): bool
    {
        $status = strtolower(trim($status));
        $cutoff = strtolower(trim($cutoff));
        $ordered = [];
        foreach ($orderedCodes as $code) {
            $code = strtolower(trim((string) $code));
            if ($code !== '' && ! in_array($code, $ordered, true)) {
                $ordered[] = $code;
            }
        }
        $statusIndex = array_search($status, $ordered, true);
        $cutoffIndex = array_search($cutoff, $ordered, true);
        if ($statusIndex === false || $cutoffIndex === false) {
            return false;
        }

        return $statusIndex < $cutoffIndex;
    }

    /**
     * @return list<string>
     */
    public static function cancellationUntilValues(): array
    {
        return array_values(array_unique(array_merge(
            array_column(self::statusChoices(), 'value'),
            self::statusCodes(),
            self::CANCEL_UNTIL_OPTIONS,
        )));
    }

    public static function normalizeCancellationUntil(string $raw): string
    {
        $raw = self::canonicalCancellationUntil($raw);
        $raw = self::codeFromChoiceValue($raw);
        $codes = self::statusCodes();
        if (in_array($raw, $codes, true)) {
            return $raw;
        }
        if (in_array(self::DEFAULT_CANCEL_UNTIL, $codes, true)) {
            return self::DEFAULT_CANCEL_UNTIL;
        }

        return (string) ($codes[0] ?? self::DEFAULT_CANCEL_UNTIL);
    }

    public static function cancellationUntil(): string
    {
        $raw = (string) ShopSetting::get('orders.cancellation_until', self::DEFAULT_CANCEL_UNTIL);

        return self::normalizeCancellationUntil($raw);
    }

    /**
     * Whether a buyer may cancel an order in the given status.
     * The saved cutoff is an order status. Cancellation stays open for every
     * earlier status and closes once the order reaches the cutoff.
     */
    public static function buyerMayCancelStatus(string $status): bool
    {
        if (! self::allowCancellation()) {
            return false;
        }

        $status = strtolower(trim($status));
        if (in_array($status, ['cancelled', 'refunded'], true)) {
            return false;
        }

        return self::statusComesBeforeCutoff(
            $status,
            self::cancellationUntil(),
            self::statusCodes(),
        );
    }

    // ── Payment & inventory ──────────────────────────────────────────

    public static function statusAfterPaymentSuccess(): string
    {
        $raw = trim((string) ShopSetting::get('orders.status_after_payment_success', self::DEFAULT_PAYMENT_SUCCESS_STATUS));

        return in_array($raw, self::STATUS_OPTIONS, true) ? $raw : self::DEFAULT_PAYMENT_SUCCESS_STATUS;
    }

    public static function statusAfterPaymentFail(): string
    {
        $raw = trim((string) ShopSetting::get('orders.status_after_payment_fail', self::DEFAULT_PAYMENT_FAIL_STATUS));

        return in_array($raw, self::PAYMENT_FAIL_STATUS_OPTIONS, true) ? $raw : self::DEFAULT_PAYMENT_FAIL_STATUS;
    }

    public static function autoCancelUnpaid(): bool
    {
        return ShopSetting::bool('orders.auto_cancel_unpaid', false);
    }

    public static function unpaidTimeoutMinutes(): int
    {
        return self::clampInt(ShopSetting::get('orders.unpaid_timeout_minutes', self::DEFAULT_UNPAID_TIMEOUT_MINUTES), 1, 525600, self::DEFAULT_UNPAID_TIMEOUT_MINUTES);
    }

    public static function reserveStockUnpaid(): bool
    {
        return ShopSetting::bool('orders.reserve_stock_unpaid', false);
    }

    public static function stockReservationMinutes(): int
    {
        return self::clampInt(ShopSetting::get('orders.stock_reservation_minutes', self::DEFAULT_RESERVE_MINUTES), 1, 525600, self::DEFAULT_RESERVE_MINUTES);
    }

    /**
     * order = when the order is first pending. payment = when it reaches Processing.
     */
    public static function canonicalReduceStockAt(string $raw): string
    {
        $raw = strtolower(trim($raw));

        return match ($raw) {
            'order' => 'pending',
            'payment' => 'processing',
            default => $raw,
        };
    }

    /**
     * @return list<string>
     */
    public static function reduceStockAtValues(): array
    {
        return array_values(array_unique(array_merge(
            array_column(self::statusChoices(), 'value'),
            self::statusCodes(),
            self::REDUCE_STOCK_AT_OPTIONS,
        )));
    }

    public static function normalizeReduceStockAt(string $raw): string
    {
        $raw = self::canonicalReduceStockAt($raw);
        $raw = self::codeFromChoiceValue($raw);
        $codes = self::statusCodes();
        if (in_array($raw, $codes, true)) {
            return $raw;
        }
        if (in_array(self::DEFAULT_REDUCE_STOCK_AT, $codes, true)) {
            return self::DEFAULT_REDUCE_STOCK_AT;
        }

        return (string) ($codes[0] ?? self::DEFAULT_REDUCE_STOCK_AT);
    }

    public static function reduceStockAt(): string
    {
        $raw = (string) ShopSetting::get('orders.reduce_stock_at', self::DEFAULT_REDUCE_STOCK_AT);

        return self::normalizeReduceStockAt($raw);
    }

    public static function restockOnCancellation(): bool
    {
        return ShopSetting::bool('orders.restock_on_cancellation', true);
    }

    public static function shouldDeductForStatus(string $status): bool
    {
        return strtolower(trim($status)) === self::reduceStockAt();
    }

    public static function shouldDeductOnPlace(): bool
    {
        return self::shouldDeductForStatus(self::defaultStatus());
    }

    public static function shouldReserveOnPlace(): bool
    {
        if (self::shouldDeductOnPlace()) {
            return false;
        }

        return self::reserveStockUnpaid();
    }

    public static function shouldDeductOnPayment(): bool
    {
        return false;
    }

    public static function shouldDeductOnProcessing(): bool
    {
        return self::shouldDeductForStatus('processing');
    }

    // ── Notifications ────────────────────────────────────────────────

    public static function emailCustomerConfirmation(): bool
    {
        return ShopSetting::bool('orders.email_customer_confirmation', true);
    }

    public static function emailAdminNewOrder(): bool
    {
        return ShopSetting::bool('orders.email_admin_new_order', true);
    }

    /**
     * Store contact address used when no recipient list has been saved yet.
     */
    public static function storeNotifyEmail(): string
    {
        $store = app(StoreContext::class)->store();
        $email = strtolower(trim((string) ($store->email ?? '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = strtolower(trim((string) Setting::getValue('shop_email', '')));
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * Addresses that receive a new-order email. An empty saved list falls back to the store email.
     *
     * @return list<string>
     */
    public static function adminNewOrderEmails(): array
    {
        $saved = self::parseEmailList((string) ShopSetting::get('orders.admin_new_order_emails', ''));
        if ($saved['invalid'] || $saved['emails'] === []) {
            $fallback = self::storeNotifyEmail();

            return $fallback !== '' ? [$fallback] : [];
        }

        return $saved['emails'];
    }

    public static function adminNewOrderEmailsText(): string
    {
        $raw = trim((string) ShopSetting::get('orders.admin_new_order_emails', ''));
        if ($raw !== '') {
            return $raw;
        }

        return self::storeNotifyEmail();
    }

    /**
     * @return array{emails: list<string>, invalid: bool}
     */
    public static function parseEmailList(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', trim($raw)) ?: [];
        $emails = [];
        $invalid = false;
        foreach ($parts as $part) {
            $email = strtolower(trim((string) $part));
            if ($email === '') {
                continue;
            }
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalid = true;
                continue;
            }
            $emails[$email] = $email;
        }

        return ['emails' => array_values($emails), 'invalid' => $invalid];
    }

    /**
     * This shop's counter. A shared install-wide counter let another shop's
     * higher numbers override a start of 1.
     */
    public static function sequenceKey(): string
    {
        $name = 'orders.sequence';
        $context = app(StoreContext::class);
        if ($context->enabled()) {
            if ($context->contextKind() === 'all') {
                return $name;
            }
            if ($context->contextKind() === 'group') {
                return ShopSetting::groupKey($context->contextGroupId(), $name);
            }
        }
        $storeId = $context->id();

        return $storeId > 0 ? ShopSetting::namespaced($name, $storeId) : $name;
    }

    /**
     * Point this shop's counter at a starting number the merchant just changed.
     * Saving the rest of the form leaves the counter where the last order left it.
     */
    public static function syncSequenceFloor(int $start, ?int $previousStart = null): void
    {
        $start = max(1, $start);
        $floor = $start - 1;
        $currentRaw = Setting::query()->where('key', self::sequenceKey())->value('value');
        $hasCurrent = $currentRaw !== null && $currentRaw !== '';
        $current = $hasCurrent ? (int) $currentRaw : $floor;
        $startChanged = $previousStart !== null && $previousStart !== $start;

        if ($startChanged || ($hasCurrent && $current < $floor)) {
            Setting::query()->updateOrCreate(
                ['key' => self::sequenceKey()],
                ['value' => (string) $floor]
            );
            Cache::forget('wento.settings');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        return [
            'defaults' => [
                'number_format' => self::numberFormat(),
                'number_start' => self::numberStart(),
                'default_status' => self::choiceValueFromCode(self::defaultStatus()),
                'min_amount' => self::minAmount(),
                'max_amount' => self::maxAmount(),
            ],
            'checkout' => [
                'customer_notes_enabled' => self::customerNotesEnabled(),
                'guest_lookup' => self::guestLookup(),
                'allow_cancellation' => self::allowCancellation(),
                'cancellation_until' => self::choiceValueFromCode(self::cancellationUntil()),
            ],
            'payment' => [
                'status_after_payment_success' => self::statusAfterPaymentSuccess(),
                'status_after_payment_fail' => self::statusAfterPaymentFail(),
                'auto_cancel_unpaid' => self::autoCancelUnpaid(),
                'unpaid_timeout_minutes' => self::unpaidTimeoutMinutes(),
                'reserve_stock_unpaid' => self::reserveStockUnpaid(),
                'stock_reservation_minutes' => self::stockReservationMinutes(),
                'reduce_stock_at' => self::choiceValueFromCode(self::reduceStockAt()),
                'restock_on_cancellation' => self::restockOnCancellation(),
            ],
            'notifications' => [
                'email_customer_confirmation' => self::emailCustomerConfirmation(),
                'email_admin_new_order' => self::emailAdminNewOrder(),
                'admin_new_order_emails' => self::adminNewOrderEmailsText(),
                'store_email' => self::storeNotifyEmail(),
            ],
            'statusChoices' => self::statusChoices(),
            'currency' => self::limitCurrency(),
            'statusOptions' => self::STATUS_OPTIONS,
            'paymentFailStatusOptions' => self::PAYMENT_FAIL_STATUS_OPTIONS,
            'cancelUntilOptions' => array_column(self::statusChoices(), 'value'),
            'reduceStockAtOptions' => self::REDUCE_STOCK_AT_OPTIONS,
        ];
    }

    /**
     * Storefront / API bootstrap.
     *
     * @return array<string, mixed>
     */
    public static function storefrontPayload(): array
    {
        return [
            'guest_checkout' => self::guestCheckout(),
            'customer_notes_enabled' => self::customerNotesEnabled(),
            'allow_cancellation' => self::allowCancellation(),
            'cancellation_until' => self::cancellationUntil(),
            'min_amount' => self::minAmount(),
            'max_amount' => self::maxAmount(),
            'amount_currency' => Money::defaultCode(),
        ];
    }

    /**
     * @return array{code: string, symbol: string, name: string}
     */
    public static function limitCurrency(): array
    {
        $currency = Money::defaultCurrency();

        return [
            'code' => $currency?->code ?? Money::defaultCode(),
            'symbol' => $currency?->symbol ?: ($currency?->code ?? Money::defaultCode()),
            'name' => $currency?->name ?: ($currency?->code ?? Money::defaultCode()),
        ];
    }

    protected static function clampInt(mixed $raw, int $min, int $max, int $default): int
    {
        $value = (int) $raw;
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }

        return $value >= 0 ? $value : $default;
    }
}
