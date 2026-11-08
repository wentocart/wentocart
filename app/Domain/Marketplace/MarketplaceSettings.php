<?php

namespace App\Domain\Marketplace;

use App\Domain\Marketplace\Services\SellerEarningAvailabilityService;
use App\Domain\Settings\Models\Setting;

class MarketplaceSettings
{
    /** Global store feature flag — separate from multishop_enabled. */
    public const MULTIVENDOR_ENABLED = 'multivendor_enabled';

    public const DEFAULT_COMMISSION = 'marketplace.default_commission';

    public const AUTO_APPROVE_PRODUCTS = 'marketplace.auto_approve_products';

    public const EARNING_RELEASE_MODE = 'marketplace.earning_release_mode';

    public const RETURN_WINDOW_DAYS = 'marketplace.return_window_days';

    public const CANCEL_WARNING_RATE = 'marketplace.cancel_warning_rate';

    public const CANCEL_CRITICAL_RATE = 'marketplace.cancel_critical_rate';

    public const RED_FLAG_LIMIT = 'marketplace.red_flag_limit';

    public const SELLER_FLAGS_ENABLED = 'marketplace.seller_flags_enabled';

    public const SUBSCRIPTION_PLANS_ENABLED = 'marketplace.subscription_plans_enabled';

    /** Configure → Marketplace → Sellers (order capabilities). */
    public const CAN_CREATE_INVOICE = 'marketplace.can_create_invoice';

    public const CAN_CREATE_SHIPMENT = 'marketplace.can_create_shipment';

    public const CAN_CANCEL_ORDER = 'marketplace.can_cancel_order';

    public const CAN_VIEW_CUSTOMERS = 'marketplace.can_view_customers';

    public const ENABLE_MINIMUM_ORDER_AMOUNT = 'marketplace.enable_minimum_order_amount';

    /** Configure → Marketplace → Sellers (registration & visibility). */
    public const APPROVAL_REQUIRED = 'marketplace.approval_required';

    public const SHOW_PUBLICLY = 'marketplace.show_publicly';

    public const MAX_UPLOAD_MB = 'marketplace.max_upload_mb';

    public const SELLER_CAN_CREATE_PRODUCT = 'marketplace.seller_can_create_product';

    public const SELLER_CAN_ASSIGN_PRODUCT = 'marketplace.seller_can_assign_product';

    /** Configure → Marketplace → Products */
    public const LOW_STOCK_THRESHOLD = 'marketplace.low_stock_threshold';

    public const ENABLE_STEPPED_PRODUCT_FORM = 'marketplace.enable_stepped_product_form';

    public const STEPPED_FORM_NAVIGATION = 'marketplace.stepped_form_navigation';

    public const SHOW_PROGRESS_BAR = 'marketplace.show_progress_bar';

    /** Configure → Magic AI → Marketplace Features */
    public const AI_ALLOW_REFERENCE_IMAGES = 'marketplace.ai_allow_reference_images';

    public const AI_MAX_REFERENCE_IMAGES = 'marketplace.ai_max_reference_images';

    public const AI_LET_SELLERS_CHOOSE_FIELDS = 'marketplace.ai_let_sellers_choose_fields';

    /** Configure → Marketplace → Marketing */
    public const ENABLE_PRODUCT_DISCOUNT_JOIN = 'marketplace.enable_product_discount_join';

    public const AUTO_APPROVE_DISCOUNT_JOIN = 'marketplace.auto_approve_discount_join';

    /**
     * Whether Multi-Vendor / Marketplace features are available for this store.
     * Default false (single-vendor ecommerce). Does not delete seller data when off.
     */
    public static function enabled(): bool
    {
        return self::flag(Setting::getValue(self::MULTIVENDOR_ENABLED, '0'));
    }

    public static function setEnabled(bool $enabled): void
    {
        Setting::setValue(self::MULTIVENDOR_ENABLED, $enabled ? '1' : '0');
    }

    public static function defaultCommission(): float
    {
        $value = Setting::getValue(self::DEFAULT_COMMISSION, '10');

        return max(0, min(100, (float) $value));
    }

    public static function autoApproveProducts(): bool
    {
        $value = Setting::getValue(self::AUTO_APPROVE_PRODUCTS, '0');

        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * When seller net earnings become available for payout.
     *
     * Defaults to on_payment so existing stores keep current behaviour until configured.
     */
    public static function earningReleaseMode(): string
    {
        $value = (string) Setting::getValue(self::EARNING_RELEASE_MODE, self::MODE_ON_PAYMENT);
        $allowed = [
            SellerEarningAvailabilityService::MODE_ON_PAYMENT,
            SellerEarningAvailabilityService::MODE_ON_DELIVERY,
            SellerEarningAvailabilityService::MODE_AFTER_RETURN_WINDOW,
        ];

        return in_array($value, $allowed, true)
            ? $value
            : SellerEarningAvailabilityService::MODE_ON_PAYMENT;
    }

    public static function returnWindowDays(): int
    {
        $value = (int) Setting::getValue(self::RETURN_WINDOW_DAYS, 7);

        return max(0, min(365, $value));
    }

    public static function cancelWarningRate(): float
    {
        return max(0, min(100, (float) Setting::getValue(self::CANCEL_WARNING_RATE, '2.5')));
    }

    public static function cancelCriticalRate(): float
    {
        return max(0, min(100, (float) Setting::getValue(self::CANCEL_CRITICAL_RATE, '5')));
    }

    public static function redFlagLimit(): int
    {
        return max(1, min(1000, (int) Setting::getValue(self::RED_FLAG_LIMIT, 3)));
    }

    public static function sellerFlagsEnabled(): bool
    {
        return self::flag(Setting::getValue(self::SELLER_FLAGS_ENABLED, '1'));
    }

    public static function subscriptionPlansEnabled(): bool
    {
        return self::flag(Setting::getValue(self::SUBSCRIPTION_PLANS_ENABLED, '0'));
    }

    public static function canCreateInvoice(): bool
    {
        return self::flag(Setting::getValue(self::CAN_CREATE_INVOICE, '1'));
    }

    public static function canCreateShipment(): bool
    {
        return self::flag(Setting::getValue(self::CAN_CREATE_SHIPMENT, '1'));
    }

    public static function canCancelOrder(): bool
    {
        return self::flag(Setting::getValue(self::CAN_CANCEL_ORDER, '1'));
    }

    public static function canViewCustomers(): bool
    {
        return self::flag(Setting::getValue(self::CAN_VIEW_CUSTOMERS, '1'));
    }

    public static function enableMinimumOrderAmount(): bool
    {
        return self::flag(Setting::getValue(self::ENABLE_MINIMUM_ORDER_AMOUNT, '0'));
    }

    public static function approvalRequired(): bool
    {
        return self::flag(Setting::getValue(self::APPROVAL_REQUIRED, '1'));
    }

    public static function showPublicly(): bool
    {
        return self::flag(Setting::getValue(self::SHOW_PUBLICLY, '1'));
    }

    /** Max File Upload Size (MB); empty/null → 10. */
    public static function maxUploadMb(): int
    {
        $raw = Setting::getValue(self::MAX_UPLOAD_MB, '10');
        if ($raw === null || $raw === '') {
            return 10;
        }

        return max(1, min(100, (int) $raw));
    }

    public static function maxUploadKb(): int
    {
        return self::maxUploadMb() * 1024;
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return [
            'enabled' => self::enabled(),
            'default_commission' => self::defaultCommission(),
            'auto_approve_products' => self::autoApproveProducts(),
            'earning_release_mode' => self::earningReleaseMode(),
            'return_window_days' => self::returnWindowDays(),
            'cancel_warning_rate' => self::cancelWarningRate(),
            'cancel_critical_rate' => self::cancelCriticalRate(),
            'red_flag_limit' => self::redFlagLimit(),
            'seller_flags_enabled' => self::sellerFlagsEnabled(),
            'subscription_plans_enabled' => self::subscriptionPlansEnabled(),
            'can_create_invoice' => self::canCreateInvoice(),
            'can_create_shipment' => self::canCreateShipment(),
            'can_cancel_order' => self::canCancelOrder(),
            'can_view_customers' => self::canViewCustomers(),
            'enable_minimum_order_amount' => self::enableMinimumOrderAmount(),
            'approval_required' => self::approvalRequired(),
            'show_publicly' => self::showPublicly(),
            'max_upload_mb' => self::maxUploadMb(),
            'seller_can_create_product' => self::sellerCanCreateProduct(),
            'seller_can_assign_product' => self::sellerCanAssignProduct(),
            'low_stock_threshold' => self::lowStockThreshold(),
            'enable_stepped_product_form' => self::enableSteppedProductForm(),
            'stepped_form_navigation' => self::steppedFormNavigation(),
            'show_progress_bar' => self::showProgressBar(),
            'ai_allow_reference_images' => self::aiAllowReferenceImages(),
            'ai_max_reference_images' => self::aiMaxReferenceImages(),
            'ai_let_sellers_choose_fields' => self::aiLetSellersChooseFields(),
            'enable_product_discount_join' => self::enableProductDiscountJoin(),
            'auto_approve_discount_join' => self::autoApproveDiscountJoin(),
        ];
    }

    public static function enableProductDiscountJoin(): bool
    {
        return self::flag(Setting::getValue(self::ENABLE_PRODUCT_DISCOUNT_JOIN, '1'));
    }

    public static function autoApproveDiscountJoin(): bool
    {
        return self::flag(Setting::getValue(self::AUTO_APPROVE_DISCOUNT_JOIN, '1'));
    }

    public static function sellerCanCreateProduct(): bool
    {
        return self::flag(Setting::getValue(self::SELLER_CAN_CREATE_PRODUCT, '1'));
    }

    public static function sellerCanAssignProduct(): bool
    {
        return self::flag(Setting::getValue(self::SELLER_CAN_ASSIGN_PRODUCT, '1'));
    }

    public static function lowStockThreshold(): int
    {
        $raw = Setting::getValue(self::LOW_STOCK_THRESHOLD, '10');
        if ($raw === null || $raw === '') {
            return 10;
        }

        return max(0, min(100000, (int) $raw));
    }

    public static function enableSteppedProductForm(): bool
    {
        return self::flag(Setting::getValue(self::ENABLE_STEPPED_PRODUCT_FORM, '0'));
    }

    public static function steppedFormNavigation(): string
    {
        $v = (string) Setting::getValue(self::STEPPED_FORM_NAVIGATION, 'tabs');

        return in_array($v, ['tabs', 'rail', 'horizontal', 'vertical'], true) ? $v : 'tabs';
    }

    public static function showProgressBar(): bool
    {
        return self::flag(Setting::getValue(self::SHOW_PROGRESS_BAR, '1'));
    }

    public static function aiAllowReferenceImages(): bool
    {
        return self::flag(Setting::getValue(self::AI_ALLOW_REFERENCE_IMAGES, '1'));
    }

    public static function aiMaxReferenceImages(): int
    {
        $raw = Setting::getValue(self::AI_MAX_REFERENCE_IMAGES, '3');
        if ($raw === null || $raw === '') {
            return 3;
        }

        return max(1, min(10, (int) $raw));
    }

    public static function aiLetSellersChooseFields(): bool
    {
        return self::flag(Setting::getValue(self::AI_LET_SELLERS_CHOOSE_FIELDS, '1'));
    }

    public static function save(array $data): void
    {
        if (array_key_exists('enabled', $data) || array_key_exists('multivendor_enabled', $data)) {
            self::setEnabled((bool) ($data['enabled'] ?? $data['multivendor_enabled']));
        }
        if (array_key_exists('default_commission', $data)) {
            Setting::setValue(self::DEFAULT_COMMISSION, (string) round((float) $data['default_commission'], 2));
        }
        if (array_key_exists('auto_approve_products', $data)) {
            Setting::setValue(self::AUTO_APPROVE_PRODUCTS, $data['auto_approve_products'] ? '1' : '0');
        }
        if (array_key_exists('earning_release_mode', $data)) {
            Setting::setValue(self::EARNING_RELEASE_MODE, (string) $data['earning_release_mode']);
        }
        if (array_key_exists('return_window_days', $data)) {
            Setting::setValue(self::RETURN_WINDOW_DAYS, (string) max(0, (int) $data['return_window_days']));
        }
        if (array_key_exists('cancel_warning_rate', $data)) {
            Setting::setValue(self::CANCEL_WARNING_RATE, (string) max(0, min(100, (float) $data['cancel_warning_rate'])));
        }
        if (array_key_exists('cancel_critical_rate', $data)) {
            Setting::setValue(self::CANCEL_CRITICAL_RATE, (string) max(0, min(100, (float) $data['cancel_critical_rate'])));
        }
        if (array_key_exists('red_flag_limit', $data)) {
            Setting::setValue(self::RED_FLAG_LIMIT, (string) max(1, min(1000, (int) $data['red_flag_limit'])));
        }
        if (array_key_exists('seller_flags_enabled', $data)) {
            Setting::setValue(self::SELLER_FLAGS_ENABLED, $data['seller_flags_enabled'] ? '1' : '0');
        }
        if (array_key_exists('subscription_plans_enabled', $data)) {
            Setting::setValue(self::SUBSCRIPTION_PLANS_ENABLED, $data['subscription_plans_enabled'] ? '1' : '0');
        }
        foreach ([
            'can_create_invoice' => self::CAN_CREATE_INVOICE,
            'can_create_shipment' => self::CAN_CREATE_SHIPMENT,
            'can_cancel_order' => self::CAN_CANCEL_ORDER,
            'can_view_customers' => self::CAN_VIEW_CUSTOMERS,
            'enable_minimum_order_amount' => self::ENABLE_MINIMUM_ORDER_AMOUNT,
            'approval_required' => self::APPROVAL_REQUIRED,
            'show_publicly' => self::SHOW_PUBLICLY,
            'seller_can_create_product' => self::SELLER_CAN_CREATE_PRODUCT,
            'seller_can_assign_product' => self::SELLER_CAN_ASSIGN_PRODUCT,
            'ai_allow_reference_images' => self::AI_ALLOW_REFERENCE_IMAGES,
            'ai_let_sellers_choose_fields' => self::AI_LET_SELLERS_CHOOSE_FIELDS,
            'enable_product_discount_join' => self::ENABLE_PRODUCT_DISCOUNT_JOIN,
            'auto_approve_discount_join' => self::AUTO_APPROVE_DISCOUNT_JOIN,
            'enable_stepped_product_form' => self::ENABLE_STEPPED_PRODUCT_FORM,
            'show_progress_bar' => self::SHOW_PROGRESS_BAR,
        ] as $key => $settingKey) {
            if (array_key_exists($key, $data)) {
                Setting::setValue($settingKey, $data[$key] ? '1' : '0');
            }
        }
        if (array_key_exists('low_stock_threshold', $data)) {
            $n = $data['low_stock_threshold'];
            Setting::setValue(
                self::LOW_STOCK_THRESHOLD,
                (string) max(0, min(100000, (int) ($n === null || $n === '' ? 10 : $n)))
            );
        }
        if (array_key_exists('stepped_form_navigation', $data)) {
            $nav = (string) $data['stepped_form_navigation'];
            Setting::setValue(
                self::STEPPED_FORM_NAVIGATION,
                in_array($nav, ['tabs', 'rail', 'horizontal', 'vertical'], true) ? $nav : 'tabs'
            );
        }
        if (array_key_exists('max_upload_mb', $data)) {
            $mb = $data['max_upload_mb'];
            if ($mb === null || $mb === '') {
                Setting::setValue(self::MAX_UPLOAD_MB, '10');
            } else {
                Setting::setValue(self::MAX_UPLOAD_MB, (string) max(1, min(100, (int) $mb)));
            }
        }
        if (array_key_exists('ai_max_reference_images', $data)) {
            $n = $data['ai_max_reference_images'];
            Setting::setValue(
                self::AI_MAX_REFERENCE_IMAGES,
                (string) max(1, min(10, (int) ($n === null || $n === '' ? 3 : $n)))
            );
        }
    }

    protected static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    private const MODE_ON_PAYMENT = 'on_payment';
}
