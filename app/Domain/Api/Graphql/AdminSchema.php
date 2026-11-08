<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Api\Models\StorefrontApiKey;
use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Models\Role;
use App\Domain\Auth\PageCatalog;
use App\Domain\Auth\UniqueSlug;
use App\Domain\Cms\CmsPageTemplates;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\ProductTemplate;
use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\FeatureValue;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Checkout\Models\Cart;
use App\Domain\Catalog\Models\ProductQtyPrice;
use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Catalog\Models\WarehouseStock;
use App\Domain\Catalog\Services\CategoryService;
use App\Domain\Catalog\Services\FeatureService;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Catalog\Support\CatalogCsvImport;
use App\Domain\Settings\Models\DataTransferImport;
use App\Domain\Store\Models\Store;
use App\Domain\Store\ShopIdentity;
use App\Domain\Store\StoreBootstrap;
use App\Domain\Store\StoreContext;
use App\Domain\Store\StoreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerAddress;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Customer\Models\Review;
use App\Domain\Customer\Services\CustomerAnonymizer;
use App\Domain\Customer\Services\CustomerService;
use App\Domain\Database\DbGrammar;
use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Models\Language;
use App\Domain\Marketing\Models\MarketingEvent;
use App\Domain\Marketing\Models\MarketingSitemap;
use App\Domain\Marketing\Models\NewsletterCampaign;
use App\Domain\Marketing\Models\NewsletterSubscriber;
use App\Domain\Seo\SitemapBuilder;
use App\Domain\Settings\ShopSetting;
use App\Domain\Settings\ConfigurationCatalog;
use App\Domain\Order\Models\ReturnReason;
use App\Domain\Order\Models\ReturnRule;
use App\Domain\Order\Models\ReturnStatus;
use App\Domain\Order\Models\ReturnCustomField;
use App\Domain\Settings\Models\EmailTemplate;
use App\Domain\Settings\ShopMailer;
use App\Domain\Search\Models\SearchRedirect;
use App\Domain\Search\Models\SearchSynonym;
use App\Domain\Security\SafeRedirect;
use App\Domain\Order\InvoiceDocument;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderComment;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\OrderStageCatalog;
use App\Domain\Order\OrderStageService;
use App\Domain\Order\Services\PaymentRefundService;
use App\Domain\Promo\Models\DiscountRule;
use App\Domain\Settings\DocumentCommerceSettings;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Tax\Models\TaxClass;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxRule;
use App\Domain\Theme\ThemeRegistry;
use App\Models\Admin;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin GraphQL surface (Admin REST API parity slice).
 * Catalog + order fulfillment mutations (comment / shipment / refund).
 */
final class AdminSchema
{
    public static function build(): Schema
    {
        $orderItem = new ObjectType([
            'name' => 'AdminOrderItem',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'sku' => Type::string(),
                'name' => Type::string(),
                'qty' => Type::float(),
                'price' => Type::float(),
                'total' => Type::float(),
            ],
        ]);

        $orderAddress = new ObjectType([
            'name' => 'AdminOrderAddress',
            'fields' => [
                'first_name' => Type::string(),
                'last_name' => Type::string(),
                'line1' => Type::string(),
                'line2' => Type::string(),
                'city' => Type::string(),
                'state' => Type::string(),
                'postal_code' => Type::string(),
                'country_code' => Type::string(),
                'phone' => Type::string(),
            ],
        ]);

        $order = new ObjectType([
            'name' => 'AdminOrder',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'number' => Type::string(),
                'email' => Type::string(),
                'status' => Type::string(),
                'payment_status' => Type::string(),
                'customer_id' => Type::id(),
                'subtotal' => Type::float(),
                'discount_total' => Type::float(),
                'shipping_total' => Type::float(),
                'grand_total' => Type::float(),
                'amount_paid' => Type::float(),
                'amount_due' => Type::float(),
                'refunded_total' => Type::float(),
                'currency_code' => Type::string(),
                'customer_note' => Type::string(),
                'billing_address' => $orderAddress,
                'shipping_address' => $orderAddress,
                'created_at' => Type::string(),
                'items' => Type::listOf(Type::nonNull($orderItem)),
            ],
        ]);

        $shipment = new ObjectType([
            'name' => 'AdminShipment',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'order_id' => Type::nonNull(Type::id()),
                'status' => Type::string(),
                'carrier' => Type::string(),
                'tracking_number' => Type::string(),
            ],
        ]);

        $shipmentItemInput = new InputObjectType([
            'name' => 'AdminShipmentItemInput',
            'fields' => [
                'orderItemId' => Type::nonNull(Type::id()),
                'quantity' => Type::nonNull(Type::float()),
            ],
        ]);

        $adminOrderItemInput = new InputObjectType([
            'name' => 'AdminOrderComposerItemInput',
            'fields' => [
                'productId' => Type::nonNull(Type::id()),
                'quantity' => Type::nonNull(Type::float()),
            ],
        ]);

        $orderComment = new ObjectType([
            'name' => 'AdminOrderComment',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'order_id' => Type::id(),
                'body' => Type::string(),
                'comment' => Type::string(),
                'visibility' => Type::string(),
                'customer_notified' => Type::boolean(),
                'customerNotified' => Type::boolean(),
                'created_at' => Type::string(),
            ],
        ]);

        $groupedQtyInput = new InputObjectType([
            'name' => 'AdminOrderComposerDraftGroupedQtyInput',
            'fields' => [
                'productId' => Type::nonNull(Type::id()),
                'quantity' => ['type' => Type::float(), 'defaultValue' => 1],
            ],
        ]);

        $bundleOptionInput = new InputObjectType([
            'name' => 'AdminOrderComposerDraftBundleOptionInput',
            'fields' => [
                'productId' => Type::nonNull(Type::id()),
                'quantity' => ['type' => Type::float(), 'defaultValue' => 1],
            ],
        ]);

        $promotion = new ObjectType([
            'name' => 'AdminPromotion',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'name' => Type::string(),
                'code' => Type::string(),
                'apply_as' => Type::string(),
                'action' => Type::string(),
                'value' => Type::float(),
                'is_enabled' => Type::boolean(),
            ],
        ]);
        $cartDiscountCoupon = new ObjectType([
            'name' => 'AdminMarketingCartDiscountRuleCoupon',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'code' => Type::string(),
                'name' => Type::string(),
                'discountRuleId' => Type::int(),
                'isPrimary' => Type::nonNull(Type::boolean()),
                'type' => Type::int(),
                'usageLimit' => Type::int(),
                'usagePerCustomer' => Type::int(),
                'timesUsed' => Type::int(),
                'expiredAt' => Type::string(),
                'is_enabled' => Type::boolean(),
                'apply_as' => Type::string(),
                'action' => Type::string(),
                'value' => Type::float(),
            ],
        ]);

        $attributeTranslation = new ObjectType([
            'name' => 'AdminAttributeTranslation',
            'fields' => [
                'locale' => Type::string(),
                'name' => Type::string(),
                'adminName' => Type::string(),
            ],
        ]);

        $attributeOption = new ObjectType([
            'name' => 'AdminAttributeOption',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'attribute_id' => Type::nonNull(Type::id()),
                'code' => Type::string(),
                'label' => Type::string(),
                'is_enabled' => Type::boolean(),
            ],
        ]);

        $attribute = new ObjectType([
            'name' => 'AdminAttribute',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'code' => Type::string(),
                'type' => Type::string(),
                'name' => Type::string(),
                // Admin REST docs → adminName
                'adminName' => Type::string(),
                'is_filterable' => Type::boolean(),
                'is_enabled' => Type::boolean(),
                'isFilterable' => Type::boolean(),
                'isConfigurable' => Type::boolean(),
                'status' => Type::int(),
                'translations' => Type::listOf(Type::nonNull($attributeTranslation)),
                'options' => Type::listOf(Type::nonNull($attributeOption)),
            ],
        ]);

        $adminRole = new ObjectType([
            'name' => 'AdminRole',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'slug' => Type::string(),
                'name' => Type::string(),
                'description' => Type::string(),
                'permission_type' => Type::string(),
                'permissionType' => Type::string(),
                'permissions' => Type::listOf(Type::nonNull(Type::string())),
            ],
        ]);

        $customerAddress = new ObjectType([
            'name' => 'AdminCustomerAddress',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'customer_id' => Type::nonNull(Type::id()),
                'customerId' => Type::id(),
                'label' => Type::string(),
                'first_name' => Type::string(),
                'last_name' => Type::string(),
                'firstName' => Type::string(),
                'lastName' => Type::string(),
                'line1' => Type::string(),
                'line2' => Type::string(),
                'address' => Type::string(),
                'city' => Type::string(),
                'state' => Type::string(),
                'postal_code' => Type::string(),
                'zipCode' => Type::string(),
                'country_code' => Type::string(),
                'country' => Type::string(),
                'phone' => Type::string(),
                'phoneNumber' => Type::string(),
                'is_default_billing' => Type::boolean(),
                'is_default_shipping' => Type::boolean(),
                'defaultBilling' => Type::boolean(),
                'defaultShipping' => Type::boolean(),
            ],
        ]);

        $adminCustomerAddressMutationResult = new ObjectType([
            'name' => 'AdminCustomerAddressMutationResult',
            'fields' => [
                'address' => Type::nonNull($customerAddress),
            ],
        ]);

        $productImage = new ObjectType([
            'name' => 'AdminProductImage',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'product_id' => Type::nonNull(Type::id()),
                'path' => Type::string(),
                'url' => Type::string(),
                'alt' => Type::string(),
                'position' => Type::int(),
            ],
        ]);

        $invoiceItem = new ObjectType([
            'name' => 'AdminInvoiceItem',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'sku' => Type::string(),
                'name' => Type::string(),
                'qty' => Type::float(),
                'unit_price' => Type::float(),
                'total' => Type::float(),
            ],
        ]);

        $invoiceInfo = new ObjectType([
            'name' => 'AdminInvoiceInfo',
            'fields' => [
                'available' => Type::nonNull(Type::boolean()),
                'enabled' => Type::nonNull(Type::boolean()),
                'order_number' => Type::string(),
                'invoice_number' => Type::string(),
                'grand_total' => Type::float(),
                'currency_code' => Type::string(),
                'transaction_recorded' => Type::boolean(),
                'html' => Type::string(),
                'items' => Type::listOf(Type::nonNull($invoiceItem)),
            ],
        ]);

        $warehouse = new ObjectType([
            'name' => 'AdminWarehouse',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'code' => Type::string(),
                'name' => Type::string(),
                'city' => Type::string(),
                'country' => Type::string(),
                'is_default' => Type::boolean(),
                'is_enabled' => Type::boolean(),
                'status' => Type::int(),
            ],
        ]);

        $inventoryRow = new ObjectType([
            'name' => 'AdminInventoryRow',
            'fields' => [
                'id' => Type::id(),

                'product_id' => Type::nonNull(Type::id()),
                'warehouse_id' => Type::nonNull(Type::id()),
                'warehouse_code' => Type::string(),
                'sourceId' => Type::int(),
                'sourceCode' => Type::string(),
                'qty' => Type::nonNull(Type::float()),
                'quantity' => Type::float(),
                'reserved_qty' => Type::nonNull(Type::float()),
                'available_qty' => Type::nonNull(Type::float()),
            ],
        ]);

        $adminPageInfo = new ObjectType([
            'name' => 'AdminPageInfo',
            'fields' => [
                'hasNextPage' => Type::nonNull(Type::boolean()),
                'hasPreviousPage' => Type::boolean(),
                'startCursor' => Type::string(),
                'endCursor' => Type::string(),
            ],
        ]);

        $adminInventoryEdge = new ObjectType([
            'name' => 'AdminProductInventoryEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($inventoryRow),
            ],
        ]);

        $adminInventoryConnection = new ObjectType([
            'name' => 'AdminProductInventoryConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($adminInventoryEdge))),
                'pageInfo' => Type::nonNull($adminPageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $adminCustomerGroupPriceNode = new ObjectType([
            'name' => 'AdminCustomerGroupPrice',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'customerGroupId' => Type::int(),
                'customerGroupName' => Type::string(),
                'qty' => Type::float(),
                'valueType' => Type::string(),
                'value' => Type::float(),
                'uniqueId' => Type::string(),
                'minQty' => Type::float(),
                'maxQty' => Type::float(),
                'price' => Type::float(),
                'position' => Type::int(),
            ],
        ]);

        $adminCustomerGroupPriceEdge = new ObjectType([
            'name' => 'AdminCustomerGroupPriceEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($adminCustomerGroupPriceNode),
            ],
        ]);

        $adminCustomerGroupPriceConnection = new ObjectType([
            'name' => 'AdminCustomerGroupPriceConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($adminCustomerGroupPriceEdge))),
                'pageInfo' => Type::nonNull($adminPageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $adminProductTranslationNode = new ObjectType([
            'name' => 'AdminProductTranslation',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'locale' => Type::string(),
                'name' => Type::string(),
                'description' => Type::string(),
                'shortDescription' => Type::string(),
                'slug' => Type::string(),
                'metaTitle' => Type::string(),
                'metaDescription' => Type::string(),
                'metaKeywords' => Type::string(),
            ],
        ]);

        $adminProductTranslationEdge = new ObjectType([
            'name' => 'AdminProductTranslationEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($adminProductTranslationNode),
            ],
        ]);

        $adminProductTranslationConnection = new ObjectType([
            'name' => 'AdminProductTranslationConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($adminProductTranslationEdge))),
                'pageInfo' => Type::nonNull($adminPageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $settingsLocale = new ObjectType([
            'name' => 'AdminSettingsLocale',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'code' => Type::nonNull(Type::string()),
                'name' => Type::string(),
                'is_enabled' => Type::boolean(),
                'is_default' => Type::boolean(),
                'status' => Type::int(),
                'direction' => Type::string(),
            ],
        ]);

        $settingsCurrency = new ObjectType([
            'name' => 'AdminSettingsCurrency',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'code' => Type::nonNull(Type::string()),
                'name' => Type::string(),
                'symbol' => Type::string(),
                'exchange_rate' => Type::float(),
                'is_enabled' => Type::boolean(),
                'is_default' => Type::boolean(),
                'status' => Type::int(),
                'decimal' => Type::int(),
                'decimal_places' => Type::int(),
                'currencyPosition' => Type::string(),
                'symbol_position' => Type::string(),
            ],
        ]);
        $settingsExchangeRate = new ObjectType([
            'name' => 'AdminSettingsExchangeRate',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'target_currency' => Type::id(),
                'targetCurrency' => Type::id(),
                'targetCurrencyCode' => Type::string(),
                'targetCurrencyName' => Type::string(),
                'currency_code' => Type::string(),
                'currency_name' => Type::string(),
                'rate' => Type::float(),
                'exchange_rate' => Type::float(),
            ],
        ]);

        $settingsExchangeRateUpdateResult = new ObjectType([
            'name' => 'AdminSettingsExchangeRateUpdateRatesResult',
            'fields' => [
                'ok' => Type::nonNull(Type::boolean()),
                'success' => Type::nonNull(Type::boolean()),
                'updated' => Type::nonNull(Type::int()),
                'message' => Type::string(),
            ],
        ]);
        $settingsChannel = new ObjectType([
            'name' => 'AdminSettingsStore',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'code' => Type::nonNull(Type::string()),
                'name' => Type::string(),
                'hostname' => Type::string(),
                'theme' => Type::string(),
                'email' => Type::string(),
                'is_default' => Type::boolean(),
                'is_enabled' => Type::boolean(),
                'status' => Type::int(),
            ],
        ]);

        $appearanceThemeChannel = new ObjectType([
            'name' => 'AdminAppearanceThemeStore',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'code' => Type::string(),
                'name' => Type::string(),
            ],
        ]);
        $appearanceTheme = new ObjectType([
            'name' => 'AdminAppearanceTheme',
            'fields' => [
                'code' => Type::nonNull(Type::string()),
                'name' => Type::nonNull(Type::string()),
                'displayName' => Type::string(),
                'display_name' => Type::string(),
                'version' => Type::string(),
                'author' => Type::string(),
                'bundled' => Type::boolean(),
                'active' => Type::boolean(),
                'status' => Type::string(),
                'stores' => Type::listOf(Type::nonNull($appearanceThemeChannel)),
            ],
        ]);

        $appearanceThemeImpact = new ObjectType([
            'name' => 'AdminAppearanceThemeImpact',
            'fields' => [
                'code' => Type::nonNull(Type::string()),
                'would_switch' => Type::nonNull(Type::listOf(Type::nonNull($appearanceThemeChannel))),
                'already_active' => Type::nonNull(Type::listOf(Type::nonNull($appearanceThemeChannel))),
                'message' => Type::string(),
            ],
        ]);

        $appearanceThemeActivateResult = new ObjectType([
            'name' => 'AdminAppearanceThemeActivateResult',
            'fields' => [
                'code' => Type::nonNull(Type::string()),
                'activatedOn' => Type::nonNull(Type::listOf(Type::nonNull($appearanceThemeChannel))),
                'message' => Type::string(),
            ],
        ]);
        $appearanceSectionTranslation = new ObjectType([
            'name' => 'AdminAppearanceSectionTranslation',
            'fields' => [
                'id' => Type::id(),

                'locale' => Type::string(),
                'options' => Type::string(),
            ],
        ]);
        $appearanceSection = new ObjectType([
            'name' => 'AdminAppearanceSection',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'themeCode' => Type::string(),
                'type' => Type::string(),
                'name' => Type::string(),
                'sortOrder' => Type::int(),
                'status' => Type::int(),
                'storeId' => Type::string(),
                'moduleCode' => Type::string(),
                'hasDraft' => Type::boolean(),
                'message' => Type::string(),
                'translation' => $appearanceSectionTranslation,
                'translations' => Type::listOf(Type::nonNull($appearanceSectionTranslation)),
            ],
        ]);
        $appearanceSectionField = new ObjectType([
            'name' => 'AdminAppearanceSectionField',
            'fields' => [
                'code' => Type::string(),
                'type' => Type::string(),
                'label' => Type::string(),
            ],
        ]);
        $appearanceSectionFields = new ObjectType([
            'name' => 'AdminAppearanceSectionFields',
            'fields' => [
                'id' => Type::id(),

                'type' => Type::string(),
                'fields' => Type::listOf(Type::nonNull($appearanceSectionField)),
                'optionsJson' => Type::string(),
            ],
        ]);
        $appearanceSectionReorderResult = new ObjectType([
            'name' => 'AdminAppearanceSectionReorderResult',
            'fields' => [
                'ok' => Type::nonNull(Type::boolean()),
                'order' => Type::listOf(Type::nonNull(Type::string())),
                'message' => Type::string(),
            ],
        ]);
        $appearanceSectionPublishResult = new ObjectType([
            'name' => 'AdminAppearanceSectionPublishResult',
            'fields' => [
                'ok' => Type::nonNull(Type::boolean()),
                'message' => Type::string(),
            ],
        ]);

        $configuration = new ObjectType([
            'name' => 'AdminConfiguration',
            'fields' => [
                'store_name' => Type::string(),
                'store_email' => Type::string(),
                'store_phone' => Type::string(),
                'currency' => Type::string(),
                'locale' => Type::string(),
            ],
        ]);

        $configUpdateResult = new ObjectType([
            'name' => 'AdminConfigurationUpdateResult',
            'fields' => [
                'ok' => Type::nonNull(Type::boolean()),
                'message' => Type::string(),
                'configuration' => Type::nonNull($configuration),
            ],
        ]);

        $salesReport = new ObjectType([
            'name' => 'AdminSalesReport',
            'fields' => [
                'orders' => Type::nonNull(Type::int()),
                'total_revenue' => Type::nonNull(Type::float()),
                'avg_order_value' => Type::nonNull(Type::float()),
                'from' => Type::string(),
                'to' => Type::string(),
            ],
        ]);

        $customerReport = new ObjectType([
            'name' => 'AdminCustomerReport',
            'fields' => [
                'new_customers' => Type::nonNull(Type::int()),
                'total_customers' => Type::nonNull(Type::int()),
                'from' => Type::string(),
                'to' => Type::string(),
            ],
        ]);

        $productReportRow = new ObjectType([
            'name' => 'AdminProductReportRow',
            'fields' => [
                'product_id' => Type::id(),
                'sku' => Type::string(),
                'name' => Type::string(),
                'units_sold' => Type::nonNull(Type::float()),
                'total_revenue' => Type::nonNull(Type::float()),
            ],
        ]);

        $adminUser = new ObjectType([
            'name' => 'AdminUser',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'name' => Type::string(),
                'email' => Type::string(),
                'is_enabled' => Type::boolean(),
                'is_super' => Type::boolean(),
                'status' => Type::int(),
                'roleId' => Type::id(),
                'role_id' => Type::id(),
                'roleName' => Type::string(),
                'role_name' => Type::string(),
            ],
        ]);

        $bulkResult = new ObjectType([
            'name' => 'AdminBulkResult',
            'fields' => [
                'ok' => Type::nonNull(Type::boolean()),
                'updated_count' => Type::nonNull(Type::int()),
            ],
        ]);

        $bulkUpdateProductsResult = new ObjectType([
            'name' => 'BulkUpdateProductsResult',
            'fields' => [
                'ok' => Type::nonNull(Type::boolean()),
                'status' => Type::nonNull(Type::boolean()),
                'message' => Type::string(),
                'updatedCount' => Type::nonNull(Type::int()),
                'updated_count' => Type::nonNull(Type::int()),
            ],
        ]);

        $integrationToken = new ObjectType([
            'name' => 'AdminIntegrationToken',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'name' => Type::string(),
                'abilities' => Type::listOf(Type::nonNull(Type::string())),
                'allowed_ips' => Type::listOf(Type::nonNull(Type::string())),
                'rate_limit_per_minute' => Type::int(),
                'rate_limit_per_day' => Type::int(),
                'rateLimitPerMinute' => Type::int(),
                'rateLimitPerDay' => Type::int(),
                'expires_at' => Type::string(),
                'expiresAt' => Type::string(),
                'permission_mode' => Type::string(),
                'permissionMode' => Type::string(),
                'permissions' => Type::listOf(Type::nonNull(Type::string())),
                'last_used_at' => Type::string(),
                'created_at' => Type::string(),
                'plain_token' => Type::string(),
            ],
        ]);

        $storefrontApiKey = new ObjectType([
            'name' => 'AdminStorefrontApiKey',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'name' => Type::string(),
                'key_prefix' => Type::string(),
                'rate_limit' => Type::int(),
                'is_enabled' => Type::boolean(),
                'last_used_at' => Type::string(),
                'created_at' => Type::string(),
                'plain_key' => Type::string(),
            ],
        ]);

        $adminApiAudit = new ObjectType([
            'name' => 'AdminApiAudit',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'adminId' => Type::int(),
                'tokenId' => Type::int(),
                'method' => Type::string(),
                'path' => Type::string(),
                'operation' => Type::string(),
                'status' => Type::int(),
                'ipAddress' => Type::string(),
                'createdAt' => Type::string(),
            ],
        ]);

        $cmsPage = new ObjectType([
            'name' => 'AdminCmsPage',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'title' => Type::string(),
                'pageTitle' => Type::string(),
                'slug' => Type::string(),
                'slug' => Type::string(),
                'status' => Type::string(),
                'is_enabled' => Type::boolean(),
                'is_visible' => Type::boolean(),
            ],
        ]);

        $taxRateType = new ObjectType([
            'name' => 'AdminSettingsTaxRate',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'name' => Type::string(),
                'identifier' => Type::string(),
                'rate' => Type::float(),
                'taxRate' => Type::float(),
                'is_enabled' => Type::boolean(),
                'status' => Type::int(),
            ],
        ]);

        $taxCategoryType = new ObjectType([
            'name' => 'AdminSettingsTaxCategory',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'name' => Type::string(),
                'code' => Type::string(),
                'description' => Type::string(),
                'rate_ids' => Type::listOf(Type::nonNull(Type::id())),
            ],
        ]);

        $refundPreview = new ObjectType([
            'name' => 'AdminRefundPreview',
            'fields' => [
                'order_number' => Type::nonNull(Type::string()),
                'grand_total' => Type::nonNull(Type::float()),
                'refunded_total' => Type::nonNull(Type::float()),
                'refundable_total' => Type::nonNull(Type::float()),
                'currency_code' => Type::string(),
                'can_refund' => Type::nonNull(Type::boolean()),
            ],
        ]);

        $productQtyPrice = new ObjectType([
            'name' => 'AdminProductQtyPrice',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'product_id' => Type::nonNull(Type::id()),
                'min_qty' => Type::nonNull(Type::float()),
                'max_qty' => Type::float(),
                'price' => Type::nonNull(Type::float()),
                'position' => Type::int(),
                'qty' => Type::float(),
                'value' => Type::float(),
                'valueType' => Type::string(),
                'customerGroupId' => Type::int(),
                'customerGroupName' => Type::string(),
                'uniqueId' => Type::string(),
            ],
        ]);

        $paymentRecord = new ObjectType([
            'name' => 'AdminPaymentRecord',
            'fields' => [
                'order_number' => Type::nonNull(Type::string()),
                'amount_recorded' => Type::nonNull(Type::float()),
                'amount_paid' => Type::nonNull(Type::float()),
                'amount_due' => Type::nonNull(Type::float()),
                'payment_status' => Type::nonNull(Type::string()),
            ],
        ]);

        $customerGroup = new ObjectType([
            'name' => 'AdminCustomerGroup',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'code' => Type::string(),
                'name' => Type::string(),
                'discount_percent' => Type::float(),
                'is_enabled' => Type::boolean(),
                'is_system' => Type::boolean(),
                'customers_count' => Type::int(),
            ],
        ]);

        $invoiceListRow = new ObjectType([
            'name' => 'AdminInvoiceListRow',
            'fields' => [
                'id' => Type::id(),
                'order_number' => Type::nonNull(Type::string()),
                'orderIncrementId' => Type::string(),
                'invoice_number' => Type::string(),
                'grand_total' => Type::float(),
                'payment_status' => Type::string(),
                'state' => Type::string(),
                'currency_code' => Type::string(),
                'created_at' => Type::string(),
            ],
        ]);

        $newsletterSubscriber = new ObjectType([
            'name' => 'AdminNewsletterSubscriber',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'email' => Type::nonNull(Type::string()),
                'is_subscribed' => Type::boolean(),
                'isSubscribed' => Type::boolean(),
                'created_at' => Type::string(),
            ],
        ]);
        $marketingTemplate = new ObjectType([
            'name' => 'AdminMarketingTemplate',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'code' => Type::string(),
                'name' => Type::string(),
                'subject' => Type::string(),
                'content' => Type::string(),
                'body' => Type::string(),
                'status' => Type::string(),
                'is_enabled' => Type::boolean(),
            ],
        ]);
        $marketingCampaign = new ObjectType([
            'name' => 'AdminMarketingCampaign',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'name' => Type::string(),
                'subject' => Type::string(),
                'content' => Type::string(),
                'html_body' => Type::string(),
                'status' => Type::string(),
                'statusFlag' => Type::int(),
                'sent_count' => Type::int(),
                'sent_at' => Type::string(),
                'marketing_template_id' => Type::id(),
                'marketing_event_id' => Type::id(),
                'marketingEventId' => Type::id(),
            ],
        ]);

        $marketingCampaignSendResult = new ObjectType([
            'name' => 'AdminMarketingCampaignSendResult',
            'fields' => [
                'ok' => Type::nonNull(Type::boolean()),
                'id' => Type::nonNull(Type::id()),
                'sent_count' => Type::nonNull(Type::int()),
                'message' => Type::string(),
                'campaign' => Type::nonNull($marketingCampaign),
            ],
        ]);
        $marketingEvent = new ObjectType([
            'name' => 'AdminMarketingEvent',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'name' => Type::nonNull(Type::string()),
                'description' => Type::string(),
                'date' => Type::nonNull(Type::string()),
            ],
        ]);
        $settingsReturnReason = new ObjectType([
            'name' => 'AdminSettingsReturnReason',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'code' => Type::nonNull(Type::string()),
                'name' => Type::nonNull(Type::string()),
                'is_enabled' => Type::boolean(),
                'status' => Type::int(),
                'position' => Type::int(),
            ],
        ]);
        $dataTransferImport = new ObjectType([
            'name' => 'AdminSettingsDataTransferImport',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'code' => Type::nonNull(Type::string()),
                'type' => Type::string(),
                'action' => Type::nonNull(Type::string()),
                'state' => Type::nonNull(Type::string()),
                'file_name' => Type::string(),
                'fileName' => Type::string(),
                'processedRowsCount' => Type::int(),
                'processed_rows_count' => Type::int(),
                'invalidRowsCount' => Type::int(),
                'invalid_rows_count' => Type::int(),
                'errorsCount' => Type::int(),
                'errors_count' => Type::int(),
                'errors' => Type::listOf(Type::nonNull(Type::string())),
                'summary' => Type::string(),
                'created_at' => Type::string(),
            ],
        ]);

        $dataTransferValidateResult = new ObjectType([
            'name' => 'AdminSettingsDataTransferImportValidateResult',
            'fields' => [
                'ok' => Type::nonNull(Type::boolean()),
                'isValid' => Type::nonNull(Type::boolean()),
                'errorsCount' => Type::nonNull(Type::int()),
                'invalidRowsCount' => Type::nonNull(Type::int()),
                'import' => Type::nonNull($dataTransferImport),
                'message' => Type::string(),
            ],
        ]);

        $dataTransferActionResult = new ObjectType([
            'name' => 'AdminSettingsDataTransferImportActionResult',
            'fields' => [
                'ok' => Type::nonNull(Type::boolean()),
                'state' => Type::nonNull(Type::string()),
                'processedRowsCount' => Type::nonNull(Type::int()),
                'import' => Type::nonNull($dataTransferImport),
                'message' => Type::string(),
            ],
        ]);
        $rmaStatus = new ObjectType([
            'name' => 'AdminRmaStatus',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'title' => Type::nonNull(Type::string()),
                'color' => Type::nonNull(Type::string()),
                'status' => Type::int(),
                'is_enabled' => Type::boolean(),
                'default' => Type::int(),
                'is_default' => Type::boolean(),
                'position' => Type::int(),
                'message' => Type::string(),
            ],
        ]);
        $rmaRule = new ObjectType([
            'name' => 'AdminRmaRule',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'name' => Type::nonNull(Type::string()),
                'description' => Type::string(),
                'returnPeriod' => Type::nonNull(Type::int()),
                'return_period' => Type::int(),
                'status' => Type::int(),
                'is_enabled' => Type::boolean(),
                'default' => Type::int(),
                'is_default' => Type::boolean(),
                'message' => Type::string(),
            ],
        ]);
        $marketingSitemap = new ObjectType([
            'name' => 'AdminMarketingSitemap',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'fileName' => Type::nonNull(Type::string()),
                'file_name' => Type::string(),
                'path' => Type::nonNull(Type::string()),
                'stores' => Type::listOf(Type::nonNull(Type::id())),
                'urls' => Type::listOf(Type::nonNull(Type::string())),
                'generatedAt' => Type::string(),
                'generated_at' => Type::string(),
                'generatedFiles' => Type::string(),
                'generated_files' => Type::string(),
            ],
        ]);

        $sitemapGenerateResult = new ObjectType([
            'name' => 'AdminMarketingSitemapGenerateResult',
            'fields' => [
                'ok' => Type::nonNull(Type::boolean()),
                'generatedAt' => Type::string(),
                'indexFile' => Type::string(),
                'generatedSitemaps' => Type::listOf(Type::nonNull(Type::string())),
                'sitemap' => Type::nonNull($marketingSitemap),
                'message' => Type::string(),
            ],
        ]);
        $rmaCustomFieldOption = new ObjectType([
            'name' => 'AdminRmaCustomFieldOption',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'name' => Type::nonNull(Type::string()),
                'value' => Type::nonNull(Type::string()),
            ],
        ]);

        $rmaCustomField = new ObjectType([
            'name' => 'AdminRmaCustomField',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'code' => Type::nonNull(Type::string()),
                'label' => Type::nonNull(Type::string()),
                'type' => Type::nonNull(Type::string()),
                'isRequired' => Type::int(),
                'is_required' => Type::boolean(),
                'position' => Type::int(),
                'inputValidation' => Type::string(),
                'status' => Type::int(),
                'is_enabled' => Type::boolean(),
                'options' => Type::listOf(Type::nonNull($rmaCustomFieldOption)),
                'message' => Type::string(),
            ],
        ]);

        $configurationField = new ObjectType([
            'name' => 'AdminConfigurationField',
            'fields' => [
                'code' => Type::nonNull(Type::string()),
                'type' => Type::nonNull(Type::string()),
                'title' => Type::nonNull(Type::string()),
                'default' => Type::string(),
                'storeBased' => Type::boolean(),
                'localeBased' => Type::boolean(),
            ],
        ]);

        $configurationMenu = new ObjectType([
            'name' => 'AdminConfigurationMenu',
            'fields' => [
                'slug' => Type::string(),
                'fields' => Type::nonNull(Type::listOf(Type::nonNull($configurationField))),
                'valuesJson' => Type::string(),
            ],
        ]);

        $configurationValues = new ObjectType([
            'name' => 'AdminConfigurationValues',
            'fields' => [
                'slug' => Type::nonNull(Type::string()),
                'valuesJson' => Type::nonNull(Type::string()),
            ],
        ]);

        $configurationUpdateResult = new ObjectType([
            'name' => 'AdminConfigurationSlugUpdateResult',
            'fields' => [
                'ok' => Type::nonNull(Type::boolean()),
                'slug' => Type::nonNull(Type::string()),
                'valuesJson' => Type::nonNull(Type::string()),
                'message' => Type::string(),
            ],
        ]);
        $productTemplateType = new ObjectType([
            'name' => 'AdminProductTemplate',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'code' => Type::nonNull(Type::string()),
                'name' => Type::nonNull(Type::string()),
                'is_default' => Type::boolean(),
                'templateGroups' => Type::string(),
            ],
        ]);

        $reportingDateRange = new ObjectType([
            'name' => 'AdminReportingDateRange',
            'fields' => [
                'previous' => Type::string(),
                'current' => Type::string(),
            ],
        ]);

        $reportingOverview = new ObjectType([
            'name' => 'AdminReportingOverview',
            'fields' => [
                'entity' => Type::nonNull(Type::string()),
                'type' => Type::nonNull(Type::string()),
                'dateRange' => $reportingDateRange,
                'statistics' => Type::string(),
            ],
        ]);

        // Admin REST Dashboard / Navigation / Profile
        $adminDashboardStats = new ObjectType([
            'name' => 'AdminDashboardStats',
            'fields' => [
                'type' => Type::nonNull(Type::string()),
                'dateRange' => Type::string(),
                'statistics' => Type::string(),
            ],
        ]);
        $adminMenu = new ObjectType([
            'name' => 'AdminMenu',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'tree' => Type::string(),
            ],
        ]);
        $adminPermissions = new ObjectType([
            'name' => 'AdminPermissions',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'permissionType' => Type::string(),
                'permissions' => Type::string(),
            ],
        ]);
        $adminProfile = new ObjectType([
            'name' => 'AdminProfile',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'name' => Type::string(),
                'email' => Type::string(),
                'image' => Type::string(),
                'status' => Type::string(),
                'roleId' => Type::int(),
                'roleName' => Type::string(),
                'success' => Type::boolean(),
                'message' => Type::string(),
            ],
        ]);

        $adminReturn = new ObjectType([
            'name' => 'AdminReturn',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'orderId' => Type::id(),
                'order_id' => Type::int(),
                'orderIncrementId' => Type::string(),
                'orderStatus' => Type::string(),
                'customerName' => Type::string(),
                'customerEmail' => Type::string(),
                'isGuest' => Type::boolean(),
                'statusId' => Type::string(),
                'statusTitle' => Type::string(),
                'statusColor' => Type::string(),
                'status' => Type::string(),
                'number' => Type::string(),
                'information' => Type::string(),
                'packageCondition' => Type::string(),
                'canReopen' => Type::boolean(),
                'messagesCount' => Type::int(),
                'item' => Type::string(),
                'images' => Type::string(),
                'availableStatuses' => Type::string(),
                'created_at' => Type::string(),
            ],
        ]);

        $adminReturnableItem = new ObjectType([
            'name' => 'AdminReturnableItem',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'orderItemId' => Type::id(),
                'sku' => Type::string(),
                'name' => Type::string(),
                'qty' => Type::float(),
                'rmaQty' => Type::float(),
                'price' => Type::float(),
            ],
        ]);

        $adminReturnReasonLite = new ObjectType([
            'name' => 'AdminReturnReasonLite',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'code' => Type::string(),
                'name' => Type::string(),
                'title' => Type::string(),
            ],
        ]);

        $adminReturnMessage = new ObjectType([
            'name' => 'AdminReturnMessage',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'body' => Type::nonNull(Type::string()),
                'isAdmin' => Type::boolean(),
                'created_at' => Type::string(),
            ],
        ]);

        $adminEuWithdrawal = new ObjectType([
            'name' => 'AdminEuWithdrawal',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'reference' => Type::string(),
                'status' => Type::string(),
                'internalStatus' => Type::string(),
                'orderId' => Type::id(),
                'orderIncrementId' => Type::string(),
                'customerEmail' => Type::string(),
                'customerName' => Type::string(),
                'reason' => Type::string(),
                'receivedAt' => Type::string(),
                'confirmationSentAt' => Type::string(),
                'finalConfirmationSentAt' => Type::string(),
                'confirmationError' => Type::string(),
                'declinedAt' => Type::string(),
                'declinedReason' => Type::string(),
                'declinedByName' => Type::string(),
                'refundedAt' => Type::string(),
                'refundNote' => Type::string(),
                'refundedByName' => Type::string(),
                'store_code' => Type::string(),
                'created_at' => Type::string(),
            ],
        ]);

        $adminBooking = new ObjectType([
            'name' => 'AdminBooking',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'order_id' => Type::int(),
                'orderId' => Type::id(),
                'qty' => Type::float(),
                'product_id' => Type::int(),
                'productId' => Type::id(),
                'bookingType' => Type::string(),
                'from' => Type::int(),
                'to' => Type::int(),
                'fromFormatted' => Type::string(),
                'toFormatted' => Type::string(),
                'order' => Type::string(),
                'orderItem' => Type::string(),
                'created_at' => Type::string(),
            ],
        ]);

        $paymentTransaction = new ObjectType([
            'name' => 'AdminPaymentTransaction',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'transaction_id' => Type::nonNull(Type::string()),
                'transactionId' => Type::string(),
                'order_number' => Type::nonNull(Type::string()),
                'orderIncrementId' => Type::string(),
                'orderId' => Type::id(),
                'invoice_number' => Type::string(),
                'invoiceId' => Type::id(),
                'amount' => Type::nonNull(Type::float()),
                'formattedAmount' => Type::string(),
                'payment_method' => Type::string(),
                'paymentMethod' => Type::string(),
                'paymentTitle' => Type::string(),
                'status' => Type::nonNull(Type::string()),
                'created_at' => Type::string(),
                'createdAt' => Type::string(),
            ],
        ]);
        $createAdminTransactionResult = new ObjectType([
            'name' => 'CreateAdminTransactionResult',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'transaction_id' => Type::nonNull(Type::string()),
                'transactionId' => Type::string(),
                'order_number' => Type::nonNull(Type::string()),
                'orderIncrementId' => Type::string(),
                'orderId' => Type::id(),
                'invoice_number' => Type::string(),
                'invoiceId' => Type::id(),
                'amount' => Type::nonNull(Type::float()),
                'formattedAmount' => Type::string(),
                'payment_method' => Type::string(),
                'paymentMethod' => Type::string(),
                'paymentTitle' => Type::string(),
                'status' => Type::nonNull(Type::string()),
                'created_at' => Type::string(),
                'createdAt' => Type::string(),
                'adminTransaction' => Type::nonNull($paymentTransaction),
            ],
        ]);

        $searchSynonym = new ObjectType([
            'name' => 'AdminSearchSynonym',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'term' => Type::nonNull(Type::string()),
                'name' => Type::string(),
                'aliases' => Type::string(),
                'terms' => Type::string(),
                'is_enabled' => Type::boolean(),
                'status' => Type::int(),
            ],
        ]);

        $searchRedirect = new ObjectType([
            'name' => 'AdminSearchRedirect',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'query' => Type::nonNull(Type::string()),
                'target_path' => Type::nonNull(Type::string()),
                'requestPath' => Type::string(),
                'targetPath' => Type::string(),
                'redirectType' => Type::string(),
                'term' => Type::string(),
                'redirect_url' => Type::string(),
                'is_enabled' => Type::boolean(),
                'status' => Type::int(),
            ],
        ]);

        $bulkDeleteResultType = new ObjectType([
            'name' => 'AdminBulkDeleteResult',
            'fields' => [
                'ok' => Type::nonNull(Type::boolean()),
                'deleted' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                'skipped' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                'updated_count' => Type::nonNull(Type::int()),
                'message' => Type::string(),
            ],
        ]);

        $cartDiscountRuleCouponGenerateResult = new ObjectType([
            'name' => 'AdminMarketingCartDiscountRuleCouponGenerateResult',
            'fields' => [
                'ok' => Type::nonNull(Type::boolean()),
                'updated_count' => Type::nonNull(Type::int()),
                'codes' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                'coupons' => Type::listOf(Type::nonNull($promotion)),
                'message' => Type::string(),
            ],
        ]);

        $refundListRow = new ObjectType([
            'name' => 'AdminRefundListRow',
            'fields' => [
                'order_number' => Type::nonNull(Type::string()),
                'refunded_total' => Type::nonNull(Type::float()),
                'grand_total' => Type::float(),
                'payment_status' => Type::string(),
                'currency_code' => Type::string(),
                'updated_at' => Type::string(),
            ],
        ]);

        $composerDraftItem = new ObjectType([
            'name' => 'AdminOrderComposerDraftItem',
            'fields' => [
                'item_id' => Type::nonNull(Type::id()),
                'product_id' => Type::nonNull(Type::id()),
                'sku' => Type::string(),
                'name' => Type::string(),
                'quantity' => Type::nonNull(Type::float()),
                'unit_price' => Type::float(),
                'total' => Type::float(),
            ],
        ]);

        $composerDraft = new ObjectType([
            'name' => 'AdminOrderComposerDraft',
            'fields' => [
                'cart_id' => Type::nonNull(Type::id()),
                'cartId' => [
                    'type' => Type::nonNull(Type::id()),
                    'resolve' => fn (array $cart) => $cart['cart_id'] ?? null,
                ],
                'customer_id' => Type::nonNull(Type::id()),
                'customerId' => [
                    'type' => Type::nonNull(Type::id()),
                    'resolve' => fn (array $cart) => $cart['customer_id'] ?? null,
                ],
                'item_count' => Type::nonNull(Type::int()),
                'itemsCount' => [
                    'type' => Type::nonNull(Type::int()),
                    'resolve' => fn (array $cart) => (int) ($cart['item_count'] ?? 0),
                ],
                'subtotal' => Type::nonNull(Type::float()),
                'discount_total' => Type::float(),
                'coupon_code' => Type::string(),
                'shipping_total' => Type::float(),
                'grand_total' => Type::float(),
                'items' => Type::listOf(Type::nonNull($composerDraftItem)),
                'has_addresses' => Type::boolean(),
                'shipping_method_code' => Type::string(),
                'payment_method_code' => Type::string(),
                'created_at' => Type::string(),
                'updated_at' => Type::string(),
                'success' => [
                    'type' => Type::boolean(),
                    'resolve' => fn (array $cart) => (bool) ($cart['success'] ?? true),
                ],
                'message' => [
                    'type' => Type::string(),
                    'resolve' => fn (array $cart) => $cart['message'] ?? 'OK',
                ],
            ],
        ]);

        $createAdminOrderComposerDraftPayload = new ObjectType([
            'name' => 'CreateAdminOrderComposerDraftPayload',
            'fields' => [
                'adminOrderComposerDraft' => [
                    'type' => Type::nonNull($composerDraft),
                    'resolve' => fn (array $root) => $root['adminOrderComposerDraft'] ?? $root,
                ],
                'success' => Type::nonNull(Type::boolean()),
                'message' => Type::string(),
                'cart_id' => Type::nonNull(Type::id()),
                'cartId' => [
                    'type' => Type::nonNull(Type::id()),
                    'resolve' => fn (array $root) => $root['cart_id'] ?? null,
                ],
                'customer_id' => Type::nonNull(Type::id()),
                'customerId' => [
                    'type' => Type::nonNull(Type::id()),
                    'resolve' => fn (array $root) => $root['customer_id'] ?? null,
                ],
                'item_count' => Type::nonNull(Type::int()),
                'itemsCount' => [
                    'type' => Type::nonNull(Type::int()),
                    'resolve' => fn (array $root) => (int) ($root['item_count'] ?? 0),
                ],
            ],
        ]);

        $adminImpersonateResult = new ObjectType([
            'name' => 'AdminImpersonateResult',
            'fields' => [
                'token' => Type::nonNull(Type::string()),
                'customerId' => Type::nonNull(Type::id()),
                'customerEmail' => Type::string(),
                'customerName' => Type::string(),
                'impersonatedByAdminId' => Type::nonNull(Type::id()),
                'expiresAt' => Type::nonNull(Type::string()),
                'success' => Type::nonNull(Type::boolean()),
                'message' => Type::string(),
            ],
        ]);

        $adminCustomerNote = new ObjectType([
            'name' => 'AdminCustomerNote',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'note' => Type::nonNull(Type::string()),
                'customerId' => Type::nonNull(Type::id()),
                'customerNotified' => Type::nonNull(Type::boolean()),
                'createdAt' => Type::string(),
            ],
        ]);

        $adminCustomerDeskEvent = new ObjectType([
            'name' => 'AdminCustomerDeskEvent',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'type' => Type::nonNull(Type::string()),
                'at' => Type::string(),
                'title' => Type::nonNull(Type::string()),
                'body' => Type::string(),
                'href' => Type::string(),
                'metaJson' => Type::string(),
            ],
        ]);

        $adminCustomerDeskSummary = new ObjectType([
            'name' => 'AdminCustomerDeskSummary',
            'fields' => [
                'notes' => Type::nonNull(Type::int()),
                'orders' => Type::nonNull(Type::int()),
                'reviews' => Type::nonNull(Type::int()),
                'gdpr' => Type::nonNull(Type::int()),
                'impersonations' => Type::nonNull(Type::int()),
            ],
        ]);

        $adminCustomerDesk = new ObjectType([
            'name' => 'AdminCustomerDesk',
            'fields' => [
                'customerId' => Type::nonNull(Type::id()),
                'summary' => Type::nonNull($adminCustomerDeskSummary),
                'events' => Type::nonNull(Type::listOf(Type::nonNull($adminCustomerDeskEvent))),
            ],
        ]);

        $adminGdprDownloadResult = new ObjectType([
            'name' => 'AdminGdprDownloadResult',
            'fields' => [
                'customerId' => Type::nonNull(Type::id()),
                'customerEmail' => Type::string(),
                'generatedAt' => Type::nonNull(Type::string()),
                'success' => Type::nonNull(Type::boolean()),
                'message' => Type::string(),
                'data' => Type::string(),
                'dataJson' => Type::string(),
            ],
        ]);

        $adminGdprRequest = new ObjectType([
            'name' => 'AdminGdprRequest',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'requestId' => Type::id(),
                'reference' => Type::string(),
                'customerId' => Type::id(),
                'customerEmail' => Type::string(),
                'customerName' => Type::string(),
                'type' => Type::string(),
                'status' => Type::string(),
                'message' => Type::string(),
                'customerDeleted' => Type::boolean(),
                'processedAt' => Type::string(),
                'success' => Type::boolean(),
                'createdAt' => Type::string(),
                'updatedAt' => Type::string(),
            ],
        ]);

        $composerDraftShippingMethod = new ObjectType([
            'name' => 'AdminOrderComposerDraftShippingMethod',
            'fields' => [
                'code' => Type::nonNull(Type::string()),
                'name' => Type::string(),
                'price' => Type::float(),
                'type' => Type::string(),
            ],
        ]);

        $composerDraftPaymentMethod = new ObjectType([
            'name' => 'AdminOrderComposerDraftPaymentMethod',
            'fields' => [
                'code' => Type::nonNull(Type::string()),
                'name' => Type::string(),
                'driver' => Type::string(),
            ],
        ]);

        $composerDraftAddressInput = new InputObjectType([
            'name' => 'AdminOrderComposerDraftAddressInput',
            'fields' => [
                'firstName' => Type::nonNull(Type::string()),
                'lastName' => Type::string(),
                'line1' => Type::nonNull(Type::string()),
                'line2' => Type::string(),
                'city' => Type::nonNull(Type::string()),
                'state' => Type::string(),
                'postalCode' => Type::string(),
                'countryCode' => Type::nonNull(Type::string()),
                'phone' => Type::string(),
            ],
        ]);

        $customerInterestItem = new ObjectType([
            'name' => 'AdminCustomerInterestItem',
            'fields' => [
                'product_id' => Type::nonNull(Type::id()),
                'sku' => Type::string(),
                'name' => Type::string(),
                'price' => Type::float(),
                'source' => Type::string(),
            ],
        ]);

        $product = new ObjectType([
            'name' => 'AdminProduct',
            'fields' => function () use (
                $adminInventoryConnection,
                $adminCustomerGroupPriceConnection,
                $adminProductTranslationConnection
            ) {
                $connArgs = [
                    'first' => Type::int(),
                    'after' => Type::string(),
                    'limit' => Type::int(),
                ];

                return [
                    'id' => Type::nonNull(Type::id()),

                    'sku' => Type::string(),
                    'name' => Type::string(),
                    'type' => Type::string(),
                    'price' => Type::float(),
                    'formattedPrice' => Type::string(),
                    'specialPrice' => Type::float(),
                    'formattedSpecialPrice' => Type::string(),
                    'weight' => Type::float(),
                    'stock_qty' => Type::float(),
                    'quantity' => Type::float(),
                    'is_enabled' => Type::boolean(),
                    'status' => Type::int(),
                    'inventories' => [
                        'type' => Type::nonNull($adminInventoryConnection),
                        'args' => $connArgs,
                        'resolve' => function ($root, array $args) {
                            $id = (int) ($root['_id'] ?? $root['id'] ?? 0);

                            return GraphqlAdminProductDetail::inventories($id, $args);
                        },
                    ],
                    'customerGroupPrices' => [
                        'type' => Type::nonNull($adminCustomerGroupPriceConnection),
                        'args' => $connArgs,
                        'resolve' => function ($root, array $args) {
                            $id = (int) ($root['_id'] ?? $root['id'] ?? 0);

                            return GraphqlAdminProductDetail::customerGroupPrices($id, $args);
                        },
                    ],
                    'translations' => [
                        'type' => Type::nonNull($adminProductTranslationConnection),
                        'args' => $connArgs,
                        'resolve' => function ($root, array $args) {
                            $id = (int) ($root['_id'] ?? $root['id'] ?? 0);

                            return GraphqlAdminProductDetail::translations($id, $args);
                        },
                    ],
                ];
            },
        ]);

        $customer = new ObjectType([
            'name' => 'AdminCustomer',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'email' => Type::string(),
                'name' => Type::string(),
                'firstName' => Type::string(),
                'lastName' => Type::string(),
                'phone' => Type::string(),
                'gender' => Type::string(),
                'status' => Type::boolean(),
                'created_at' => Type::string(),
                'createdAt' => Type::string(),
                'updatedAt' => Type::string(),
            ],
        ]);

        $adminCustomerMutationResult = new ObjectType([
            'name' => 'AdminCustomerMutationResult',
            'fields' => [
                'customer' => Type::nonNull($customer),
            ],
        ]);

        $adminStatusMessageResult = new ObjectType([
            'name' => 'AdminStatusMessageResult',
            'fields' => [
                'status' => Type::nonNull(Type::boolean()),
                'message' => Type::string(),
                'ok' => Type::boolean(),
                'id' => Type::id(),
            ],
        ]);

        $adminProductMutationResult = new ObjectType([
            'name' => 'AdminProductMutationResult',
            'fields' => [
                'product' => Type::nonNull($product),
            ],
        ]);

        $category = new ObjectType([
            'name' => 'AdminCategory',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'name' => Type::string(),
                'slug' => Type::string(),
                'is_enabled' => Type::boolean(),
                'status' => Type::int(),
                'parent_id' => Type::id(),
                'parentId' => Type::id(),
                'position' => Type::int(),
                'displayMode' => Type::string(),
                'translations' => Type::string(),
                'filterableAttributeIds' => Type::string(),
            ],
        ]);

        // Admin REST docs → adminCategoryTrees (nested children)
        $categoryTree = null;
        $categoryTree = new ObjectType([
            'name' => 'AdminCategoryTree',
            'fields' => function () use (&$categoryTree) {
                return [
                    'id' => Type::nonNull(Type::id()),
                    'name' => Type::string(),
                    'slug' => Type::string(),
                    'is_enabled' => Type::boolean(),
                    'status' => Type::int(),
                    'parent_id' => Type::id(),
                    'parentId' => Type::id(),
                    'position' => Type::int(),
                    'children' => Type::listOf(Type::nonNull($categoryTree)),
                ];
            },
        ]);

        $customerReview = new ObjectType([
            'name' => 'AdminCustomerReview',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'product_id' => Type::id(),
                'customer_id' => Type::id(),
                'rating' => Type::int(),
                'title' => Type::string(),
                'body' => Type::string(),
                'comment' => Type::string(),
                'status' => Type::string(),
                'is_approved' => Type::boolean(),
                'product_name' => Type::string(),
                'customer_name' => Type::string(),
                'customer_email' => Type::string(),
                'created_at' => Type::string(),
            ],
        ]);

        $deleteResult = new ObjectType([
            'name' => 'AdminDeleteResult',
            'fields' => [
                'ok' => Type::nonNull(Type::boolean()),
                'id' => Type::nonNull(Type::id()),
            ],
        ]);

        $query = new ObjectType([
            'name' => 'Query',
            'fields' => [
                'orders' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($order))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                        'status' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminOrders($args);
                    },
                ],
                'adminOrders' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($order))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                        'status' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertOrdersManage();

                        return self::listAdminOrders($args);
                    },
                ],
                'order' => [
                    'type' => $order,
                    'args' => [
                        'number' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        $o = Order::query()->with('items')->where('number', $args['number'])->first();
                        if (! $o) {
                            return null;
                        }

                        return self::mapAdminOrder($o, true);
                    },
                ],
                'adminOrderDetail' => [
                    'type' => $order,
                    'args' => [
                        'number' => Type::string(),
                        'id' => Type::id(),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertOrdersManage();
                        $q = Order::query()->with('items');
                        if (! empty($args['id'])) {
                            $q->whereKey((int) $args['id']);
                        } elseif (! empty($args['number'])) {
                            $q->where('number', (string) $args['number']);
                        } else {
                            throw new UserError('Provide number or id.');
                        }
                        $o = $q->first();
                        if (! $o) {
                            return null;
                        }

                        return self::mapAdminOrder($o, true);
                    },
                ],
                // Admin REST docs → adminOrder(id)
                'adminOrder' => [
                    'type' => $order,
                    'args' => [
                        'number' => Type::string(),
                        'id' => Type::id(),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertOrdersManage();
                        $q = Order::query()->with('items');
                        if (! empty($args['id'])) {
                            $q->whereKey((int) $args['id']);
                        } elseif (! empty($args['number'])) {
                            $q->where('number', (string) $args['number']);
                        } else {
                            throw new UserError('Provide number or id.');
                        }
                        $o = $q->first();
                        if (! $o) {
                            return null;
                        }

                        return self::mapAdminOrder($o, true);
                    },
                ],
                'products' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($product))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                        'q' => Type::string(),
                        'sku' => Type::string(),
                        'status' => Type::int(),
                        'type' => Type::string(),
                        'enabledOnly' => ['type' => Type::boolean(), 'defaultValue' => false],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminCatalogProducts($args);
                    },
                ],
                'adminCatalogProducts' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($product))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                        'q' => Type::string(),
                        'sku' => Type::string(),
                        'status' => Type::int(),
                        'type' => Type::string(),
                        'enabledOnly' => ['type' => Type::boolean(), 'defaultValue' => false],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminCatalogProducts($args);
                    },
                ],
                'adminCatalogProduct' => [
                    'type' => $product,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCatalogProducts();
                        $row = Product::query()->with('translations')->find((int) $args['id']);

                        return $row ? self::mapAdminProduct($row) : null;
                    },
                ],
                // Admin REST docs → adminProduct
                'adminProduct' => [
                    'type' => $product,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCatalogProducts();
                        $row = Product::query()->with('translations')->find((int) $args['id']);

                        return $row ? self::mapAdminProduct($row) : null;
                    },
                ],
                'adminProducts' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($product))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                        'q' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $args['enabledOnly'] = true;

                        return self::listAdminCatalogProducts($args);
                    },
                ],
                'customers' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($customer))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                        'q' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminCustomers($args);
                    },
                ],
                'adminCustomers' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($customer))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                        'q' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminCustomers($args);
                    },
                ],
                'adminCustomer' => [
                    'type' => $customer,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        $admin = auth('admin')->user();
                        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
                            throw new UserError('Forbidden.');
                        }
                        $c = Customer::query()->find((int) $args['id']);
                        if (! $c) {
                            return null;
                        }

                        return self::mapAdminCustomerRow($c);
                    },
                ],
                // Admin REST Addresses → list / detail
                'adminCustomerAddresses' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($customerAddress))),
                    'args' => [
                        'customerId' => Type::nonNull(Type::id()),
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                    ],
                    'resolve' => function ($root, array $args) {
                        $admin = auth('admin')->user();
                        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
                            throw new UserError('Forbidden.');
                        }
                        $customer = Customer::query()->find((int) $args['customerId']);
                        if (! $customer) {
                            throw new UserError('Customer not found.');
                        }
                        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));

                        return $customer->addresses()
                            ->orderByDesc('is_default_billing')
                            ->orderByDesc('is_default_shipping')
                            ->orderBy('id')
                            ->limit($limit)
                            ->get()
                            ->map(fn (CustomerAddress $a) => self::mapAdminCustomerAddress($a))
                            ->all();
                    },
                ],
                'adminCustomerAddress' => [
                    'type' => $customerAddress,
                    'args' => [
                        'customerId' => Type::nonNull(Type::id()),
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        $admin = auth('admin')->user();
                        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
                            throw new UserError('Forbidden.');
                        }
                        $row = CustomerAddress::query()
                            ->where('customer_id', (int) $args['customerId'])
                            ->whereKey((int) $args['id'])
                            ->first();

                        return $row ? self::mapAdminCustomerAddress($row) : null;
                    },
                ],
                'promotions' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($promotion))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminPromotions($args, false);
                    },
                ],
                'adminPromotions' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($promotion))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminPromotions($args, false);
                    },
                ],
                'adminCoupons' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($promotion))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                        'q' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminPromotions($args, true);
                    },
                ],
                'adminMarketingCartDiscountRuleCoupons' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($cartDiscountCoupon))),
                    'args' => [
                        'discountRuleId' => Type::nonNull(Type::id()),
                        'first' => Type::int(),
                        'limit' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminMarketingCartDiscountRuleCoupons($args);
                    },
                ],
                'attributes' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($attribute))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminAttributes($args);
                    },
                ],
                'adminAttributes' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($attribute))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminAttributes($args);
                    },
                ],
                // Admin REST docs → adminAttribute(id)
                'adminAttribute' => [
                    'type' => $attribute,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCatalogFeatures();
                        $row = Feature::query()
                            ->with(['translations', 'values.translations'])
                            ->find((int) $args['id']);

                        return $row ? self::mapAdminAttribute($row, true) : null;
                    },
                ],
                'adminRoles' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($adminRole))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'name' => Type::string(),
                        'permission_type' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminSettingsRoles($args);
                    },
                ],
                'adminSettingsRoles' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($adminRole))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'name' => Type::string(),
                        'permission_type' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminSettingsRoles($args);
                    },
                ],
                'adminSettingsRole' => [
                    'type' => $adminRole,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertSettingsManage();
                        $role = Role::query()->with('permissions')->find((int) $args['id']);

                        return $role ? self::mapAdminSettingsRole($role) : null;
                    },
                ],
                'invoice' => [
                    'type' => $invoiceInfo,
                    'args' => [
                        'number' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        $admin = auth('admin')->user();
                        if (
                            ! $admin instanceof Admin
                            || (! $admin->hasPermission('orders.documents') && ! $admin->hasPermission('orders.manage'))
                        ) {
                            throw new UserError('Forbidden.');
                        }
                        $order = Order::query()->where('number', $args['number'])->first();
                        if (! $order) {
                            return null;
                        }

                        return self::mapInvoiceInfo($order, false);
                    },
                ],
                'warehouses' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($warehouse))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'code' => Type::string(),
                        'status' => Type::int(),
                        'country' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminWarehouses($args);
                    },
                ],
                'adminSettingsWarehouses' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($warehouse))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'code' => Type::string(),
                        'status' => Type::int(),
                        'country' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminWarehouses($args);
                    },
                ],
                'adminSettingsWarehouse' => [
                    'type' => $warehouse,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCatalogProducts();
                        $row = Warehouse::query()->with('translations')->find((int) $args['id']);

                        return $row ? self::mapAdminWarehouse($row) : null;
                    },
                ],
                'inventory' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($inventoryRow))),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminProductInventories($args);
                    },
                ],
                'adminCatalogProductInventories' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($inventoryRow))),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminProductInventories($args);
                    },
                ],
                'adminSettingsLocales' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($settingsLocale))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'enabled' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $admin = auth('admin')->user();
                        if (! $admin instanceof Admin) {
                            throw new UserError('Forbidden.');
                        }
                        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
                        $q = Language::query()->orderBy('position')->orderBy('id');
                        if (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
                            $q->where('is_enabled', (bool) $args['enabled']);
                        }

                        return $q->limit($limit)->get()->map(fn (Language $row) => [
                            'id' => (string) $row->id,
                            'code' => (string) $row->code,
                            'name' => $row->name,
                            'is_enabled' => (bool) $row->is_enabled,
                            'is_default' => (bool) $row->is_default,
                            'status' => $row->is_enabled ? 1 : 0,
                            'direction' => 'ltr',
                        ])->all();
                    },
                ],
                'adminSettingsLocale' => [
                    'type' => $settingsLocale,
                    'args' => [
                        'id' => Type::id(),
                        'code' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $admin = auth('admin')->user();
                        if (! $admin instanceof Admin) {
                            throw new UserError('Forbidden.');
                        }
                        $row = null;
                        if (! empty($args['id'])) {
                            $row = Language::query()->find((int) $args['id']);
                        } elseif (! empty($args['code'])) {
                            $row = Language::query()->where('code', trim((string) $args['code']))->first();
                        }
                        if (! $row) {
                            return null;
                        }

                        return [
                            'id' => (string) $row->id,
                            'code' => (string) $row->code,
                            'name' => $row->name,
                            'is_enabled' => (bool) $row->is_enabled,
                            'is_default' => (bool) $row->is_default,
                            'status' => $row->is_enabled ? 1 : 0,
                            'direction' => 'ltr',
                        ];
                    },
                ],
                'adminSettingsCurrencies' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($settingsCurrency))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'enabled' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $admin = auth('admin')->user();
                        if (! $admin instanceof Admin) {
                            throw new UserError('Forbidden.');
                        }
                        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
                        $q = Currency::query()->orderBy('position')->orderBy('id');
                        if (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
                            $q->where('is_enabled', (bool) $args['enabled']);
                        }

                        return $q->limit($limit)->get()->map(fn (Currency $row) => [
                            'id' => (string) $row->id,
                            'code' => (string) $row->code,
                            'name' => $row->name,
                            'symbol' => $row->symbol,
                            'exchange_rate' => $row->exchange_rate !== null ? (float) $row->exchange_rate : null,
                            'is_enabled' => (bool) $row->is_enabled,
                            'is_default' => (bool) $row->is_default,
                            'status' => $row->is_enabled ? 1 : 0,
                        ])->all();
                    },
                ],
                'adminSettingsCurrency' => [
                    'type' => $settingsCurrency,
                    'args' => [
                        'id' => Type::id(),
                        'code' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $admin = auth('admin')->user();
                        if (! $admin instanceof Admin) {
                            throw new UserError('Forbidden.');
                        }
                        $row = null;
                        if (! empty($args['id'])) {
                            $row = Currency::query()->find((int) $args['id']);
                        } elseif (! empty($args['code'])) {
                            $row = Currency::query()->where('code', strtoupper(trim((string) $args['code'])))->first();
                        }
                        if (! $row) {
                            return null;
                        }

                        return [
                            'id' => (string) $row->id,
                            'code' => (string) $row->code,
                            'name' => $row->name,
                            'symbol' => $row->symbol,
                            'exchange_rate' => $row->exchange_rate !== null ? (float) $row->exchange_rate : null,
                            'is_enabled' => (bool) $row->is_enabled,
                            'is_default' => (bool) $row->is_default,
                            'status' => $row->is_enabled ? 1 : 0,
                        ];
                    },
                ],
                'adminSettingsExchangeRates' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($settingsExchangeRate))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'target_currency' => Type::id(),
                        'rate_from' => Type::float(),
                        'rate_to' => Type::float(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminSettingsExchangeRates($args);
                    },
                ],
                'adminSettingsExchangeRate' => [
                    'type' => $settingsExchangeRate,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertSettingsManage();
                        $row = Currency::query()->find((int) $args['id']);

                        return $row ? self::mapAdminSettingsExchangeRate($row) : null;
                    },
                ],
                'adminSettingsStores' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($settingsChannel))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'code' => Type::string(),
                        'name' => Type::string(),
                        'hostname' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminSettingsStores($args);
                    },
                ],
                'stores' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($settingsChannel))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'code' => Type::string(),
                        'name' => Type::string(),
                        'hostname' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminSettingsStores($args);
                    },
                ],
                'locales' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($settingsLocale))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'enabled' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $admin = auth('admin')->user();
                        if (! $admin instanceof Admin) {
                            throw new UserError('Forbidden.');
                        }
                        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
                        $q = Language::query()->orderBy('position')->orderBy('id');
                        if (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
                            $q->where('is_enabled', (bool) $args['enabled']);
                        }

                        return $q->limit($limit)->get()->map(fn (Language $row) => [
                            'id' => (string) $row->id,
                            'code' => (string) $row->code,
                            'name' => $row->name,
                            'direction' => $row->direction ?? 'ltr',
                            'is_enabled' => (bool) $row->is_enabled,
                            'is_default' => (bool) $row->is_default,
                            'status' => $row->is_enabled ? 1 : 0,
                        ])->all();
                    },
                ],
                'currencies' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($settingsCurrency))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'enabled' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $admin = auth('admin')->user();
                        if (! $admin instanceof Admin) {
                            throw new UserError('Forbidden.');
                        }
                        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
                        $q = Currency::query()->orderBy('position')->orderBy('id');
                        if (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
                            $q->where('is_enabled', (bool) $args['enabled']);
                        }

                        return $q->limit($limit)->get()->map(fn (Currency $row) => [
                            'id' => (string) $row->id,
                            'code' => (string) $row->code,
                            'name' => $row->name,
                            'symbol' => $row->symbol,
                            'exchange_rate' => $row->exchange_rate !== null ? (float) $row->exchange_rate : null,
                            'is_enabled' => (bool) $row->is_enabled,
                            'is_default' => (bool) $row->is_default,
                            'status' => $row->is_enabled ? 1 : 0,
                        ])->all();
                    },
                ],
                'exchangeRates' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($settingsExchangeRate))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'target_currency' => Type::id(),
                        'rate_from' => Type::float(),
                        'rate_to' => Type::float(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminSettingsExchangeRates($args);
                    },
                ],
                'warehouses' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($warehouse))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'code' => Type::string(),
                        'status' => Type::int(),
                        'country' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminWarehouses($args);
                    },
                ],
                'taxRates' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($taxRateType))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'q' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminSettingsTaxRates($args);
                    },
                ],
                'taxCategories' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($taxCategoryType))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'q' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminSettingsTaxCategories($args);
                    },
                ],
                'adminSettingsStore' => [
                    'type' => $settingsChannel,
                    'args' => [
                        'id' => Type::id(),
                        'code' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertSettingsManage();
                        $row = null;
                        if (! empty($args['id'])) {
                            $row = Store::query()->with('urls')->find((int) $args['id']);
                        } elseif (! empty($args['code'])) {
                            $row = Store::query()->with('urls')->where('code', trim((string) $args['code']))->first();
                        }

                        return $row ? self::mapAdminSettingsStore($row) : null;
                    },
                ],
                'adminAppearanceThemes' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($appearanceTheme))),
                    'resolve' => function () {
                        return GraphqlAdminAppearanceThemes::list();
                    },
                ],
                'adminAppearanceTheme' => [
                    'type' => $appearanceTheme,
                    'args' => [
                        'code' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminAppearanceThemes::find((string) $args['code']);
                    },
                ],
                'adminAppearanceThemeImpact' => [
                    'type' => Type::nonNull($appearanceThemeImpact),
                    'args' => [
                        'code' => Type::nonNull(Type::string()),
                        'storeIds' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminAppearanceThemes::impact($args);
                    },
                ],
                'adminAppearanceSections' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($appearanceSection))),
                    'args' => [
                        'code' => Type::string(),
                        'storeId' => Type::id(),
                        'storeId' => Type::id(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlAdminAppearanceSections::list($args),
                ],
                'adminAppearanceSection' => [
                    'type' => $appearanceSection,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'code' => Type::string(),
                        'storeId' => Type::id(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlAdminAppearanceSections::one($args),
                ],
                'adminAppearanceSectionFields' => [
                    'type' => Type::nonNull($appearanceSectionFields),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'code' => Type::string(),
                        'storeId' => Type::id(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlAdminAppearanceSections::fields($args),
                ],
                'adminAppearanceSectionPreview' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($appearanceSection))),
                    'args' => [
                        'code' => Type::string(),
                        'storeId' => Type::id(),
                        'storeId' => Type::id(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlAdminAppearanceSections::list(array_merge($args, ['preview' => true])),
                ],
                'adminSettingsThemes' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($appearanceSection))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'name' => Type::string(),
                        'type' => Type::string(),
                        'themeCode' => Type::string(),
                        'storeId' => Type::id(),
                        'status' => Type::int(),
                        'sort' => Type::string(),
                        'order' => Type::string(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlAdminSettingsThemes::list($args),
                ],
                'adminSettingsTheme' => [
                    'type' => $appearanceSection,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'storeId' => Type::id(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminSettingsThemes::find((int) $args['id'], $args);
                    },
                ],
                'configuration' => [
                    'type' => Type::nonNull($configuration),
                    'resolve' => function () {
                        $admin = auth('admin')->user();
                        if (! $admin instanceof Admin) {
                            throw new UserError('Forbidden.');
                        }
                        $identity = ShopIdentity::forStorefront(null, app()->getLocale());

                        return [
                            'store_name' => $identity['name'] ?? null,
                            'store_email' => $identity['email'] ?? null,
                            'store_phone' => $identity['phone'] ?? null,
                            'currency' => (string) (config('wento.default_currency') ?: 'USD'),
                            'locale' => app()->getLocale(),
                        ];
                    },
                ],
                'salesReport' => [
                    'type' => Type::nonNull($salesReport),
                    'args' => [
                        'days' => ['type' => Type::int(), 'defaultValue' => 30],
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertOrdersManage();
                        $days = max(1, min(366, (int) ($args['days'] ?? 30)));
                        $to = now();
                        $from = now()->subDays($days)->startOfDay();
                        $q = Order::query()
                            ->where('status', '!=', 'cancelled')
                            ->whereBetween('created_at', [$from, $to]);
                        $orders = (int) (clone $q)->count();
                        $revenue = (float) (clone $q)->sum('grand_total');
                        $aov = $orders > 0 ? round($revenue / $orders, 2) : 0.0;

                        return [
                            'orders' => $orders,
                            'total_revenue' => round($revenue, 2),
                            'avg_order_value' => $aov,
                            'from' => $from->toIso8601String(),
                            'to' => $to->toIso8601String(),
                        ];
                    },
                ],
                'customerReport' => [
                    'type' => Type::nonNull($customerReport),
                    'args' => [
                        'days' => ['type' => Type::int(), 'defaultValue' => 30],
                    ],
                    'resolve' => function ($root, array $args) {
                        $admin = auth('admin')->user();
                        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
                            throw new UserError('Forbidden.');
                        }
                        $days = max(1, min(366, (int) ($args['days'] ?? 30)));
                        $to = now();
                        $from = now()->subDays($days)->startOfDay();

                        return [
                            'new_customers' => (int) Customer::query()->whereBetween('created_at', [$from, $to])->count(),
                            'total_customers' => (int) Customer::query()->count(),
                            'from' => $from->toIso8601String(),
                            'to' => $to->toIso8601String(),
                        ];
                    },
                ],
                'productReport' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($productReportRow))),
                    'args' => [
                        'days' => ['type' => Type::int(), 'defaultValue' => 30],
                        'limit' => ['type' => Type::int(), 'defaultValue' => 10],
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertOrdersManage();
                        $days = max(1, min(366, (int) ($args['days'] ?? 30)));
                        $limit = max(1, min(50, (int) ($args['limit'] ?? 10)));
                        $from = now()->subDays($days)->startOfDay();
                        $orderIds = Order::query()
                            ->where('status', '!=', 'cancelled')
                            ->where('created_at', '>=', $from)
                            ->pluck('id');

                        return OrderItem::query()
                            ->selectRaw('product_id, sku, name, SUM(qty) as units_sold, SUM(total) as total_revenue')
                            ->whereIn('order_id', $orderIds)
                            ->whereNotNull('product_id')
                            ->groupBy('product_id', 'sku', 'name')
                            ->orderByDesc('units_sold')
                            ->limit($limit)
                            ->get()
                            ->map(fn ($row) => [
                                'product_id' => $row->product_id !== null ? (string) $row->product_id : null,
                                'sku' => $row->sku,
                                'name' => $row->name,
                                'units_sold' => (float) $row->units_sold,
                                'total_revenue' => round((float) $row->total_revenue, 2),
                            ])
                            ->all();
                    },
                ],
                'adminUsers' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($adminUser))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'name' => Type::string(),
                        'email' => Type::string(),
                        'role_id' => Type::id(),
                        'status' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminSettingsUsers($args);
                    },
                ],
                'adminSettingsUsers' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($adminUser))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'name' => Type::string(),
                        'email' => Type::string(),
                        'role_id' => Type::id(),
                        'status' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminSettingsUsers($args);
                    },
                ],
                'adminSettingsUser' => [
                    'type' => $adminUser,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertSuperAdmin();
                        $row = Admin::query()->with('roles')->find((int) $args['id']);

                        return $row ? self::mapAdminSettingsUser($row) : null;
                    },
                ],
                'storefrontApiKeys' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($storefrontApiKey))),
                    'resolve' => function () {
                        return GraphqlAdminStorefrontKeys::listAll();
                    },
                ],
                'integrationTokens' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($integrationToken))),
                    'resolve' => function () {
                        return GraphqlAdminIntegrations::listAll();
                    },
                ],
                'adminApiAudits' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($adminApiAudit))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                        'path' => Type::string(),
                        'operation' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $page = GraphqlAdminApiAudits::paginate([
                            'page' => 1,
                            'per_page' => (int) ($args['limit'] ?? 20),
                            'path' => $args['path'] ?? null,
                            'operation' => $args['operation'] ?? null,
                        ]);

                        return $page['data'];
                    },
                ],
                'adminIntegrationModule' => [
                    'type' => Type::nonNull(new ObjectType([
                        'name' => 'AdminIntegrationModule',
                        'fields' => [
                            'enabled' => Type::nonNull(Type::boolean()),
                            'oneTokenPerAdmin' => Type::nonNull(Type::boolean()),
                            'auditEnabled' => Type::nonNull(Type::boolean()),
                            'retentionDays' => Type::nonNull(Type::int()),
                        ],
                    ])),
                    'resolve' => function () {
                        return GraphqlAdminIntegrationModule::get();
                    },
                ],
                'cmsPages' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($cmsPage))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'q' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminCmsPages($args);
                    },
                ],
                'adminCmsPages' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($cmsPage))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'q' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminCmsPages($args);
                    },
                ],
                'adminCmsPage' => [
                    'type' => $cmsPage,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCmsManage();
                        $page = CmsPage::query()->with('translations')->find((int) $args['id']);

                        return $page ? self::mapAdminCmsPage($page, Language::defaultCode()) : null;
                    },
                ],
                'adminSettingsTaxRates' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($taxRateType))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'q' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminSettingsTaxRates($args);
                    },
                ],
                'adminSettingsTaxRate' => [
                    'type' => $taxRateType,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertTaxesManage();
                        $row = TaxRate::query()->find((int) $args['id']);

                        return $row ? self::mapAdminSettingsTaxRate($row) : null;
                    },
                ],
                'adminSettingsTaxCategories' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($taxCategoryType))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'q' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminSettingsTaxCategories($args);
                    },
                ],
                'adminSettingsTaxCategory' => [
                    'type' => $taxCategoryType,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertTaxesManage();
                        $row = TaxClass::query()->with('rules')->find((int) $args['id']);

                        return $row ? self::mapAdminSettingsTaxCategory($row) : null;
                    },
                ],
                'refundPreview' => [
                    'type' => Type::nonNull($refundPreview),
                    'args' => [
                        'number' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::previewAdminRefund($args);
                    },
                ],
                'previewAdminRefund' => [
                    'type' => Type::nonNull($refundPreview),
                    'args' => [
                        'number' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::previewAdminRefund($args);
                    },
                ],
                'orderComments' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($orderComment))),
                    'args' => [
                        'number' => Type::string(),
                        'orderId' => Type::id(),
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminOrderComments($args);
                    },
                ],
                'adminOrderComments' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($orderComment))),
                    'args' => [
                        'number' => Type::string(),
                        'orderId' => Type::id(),
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminOrderComments($args);
                    },
                ],
                'shipments' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($shipment))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                        'orderNumber' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminShipments($args);
                    },
                ],
                'adminShipments' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($shipment))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                        'orderNumber' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminShipments($args);
                    },
                ],
                'adminShipment' => [
                    'type' => $shipment,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertOrdersManage();
                        $s = Shipment::query()->find((int) $args['id']);
                        if (! $s) {
                            return null;
                        }

                        return [
                            'id' => (string) $s->id,
                            'order_id' => (string) $s->order_id,
                            'status' => $s->status,
                            'carrier' => $s->carrier,
                            'tracking_number' => $s->tracking_number,
                        ];
                    },
                ],
                'productQtyPrices' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($productQtyPrice))),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminCatalogProductCustomerGroupPrices($args);
                    },
                ],
                // Admin REST docs → adminCatalogProductCustomerGroupPrices
                'adminCatalogProductCustomerGroupPrices' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($productQtyPrice))),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminCatalogProductCustomerGroupPrices($args);
                    },
                ],
                'customerGroups' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($customerGroup))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminCustomerGroups($args);
                    },
                ],
                // Admin REST docs → adminCustomerGroups
                'adminCustomerGroups' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($customerGroup))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminCustomerGroups($args);
                    },
                ],
                'newsletterSubscribers' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($newsletterSubscriber))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'q' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminMarketingSubscribers($args);
                    },
                ],
                'adminMarketingSubscribers' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($newsletterSubscriber))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'q' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminMarketingSubscribers($args);
                    },
                ],
                'adminMarketingSubscriber' => [
                    'type' => $newsletterSubscriber,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertNewsletterManage();
                        $row = NewsletterSubscriber::query()->find((int) $args['id']);

                        return $row ? self::mapAdminMarketingSubscriber($row) : null;
                    },
                ],
                'adminMarketingTemplates' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($marketingTemplate))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'name' => Type::string(),
                        'status' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminMarketingTemplates($args);
                    },
                ],
                'adminMarketingTemplate' => [
                    'type' => $marketingTemplate,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertNewsletterManage();
                        $row = EmailTemplate::query()->find((int) $args['id']);

                        return $row ? self::mapAdminMarketingTemplate($row) : null;
                    },
                ],
                'adminMarketingCampaigns' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($marketingCampaign))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'name' => Type::string(),
                        'status' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminMarketingCampaigns($args);
                    },
                ],
                'adminMarketingCampaign' => [
                    'type' => $marketingCampaign,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertNewsletterManage();
                        $row = NewsletterCampaign::query()->find((int) $args['id']);

                        return $row ? self::mapAdminMarketingCampaign($row) : null;
                    },
                ],
                'adminMarketingEvents' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($marketingEvent))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'name' => Type::string(),
                        'date_from' => Type::string(),
                        'date_to' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminMarketingEvents($args);
                    },
                ],
                'adminMarketingEvent' => [
                    'type' => $marketingEvent,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertNewsletterManage();
                        $row = MarketingEvent::query()->find((int) $args['id']);

                        return $row ? self::mapAdminMarketingEvent($row) : null;
                    },
                ],
                'adminSettingsReturnReasons' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($settingsReturnReason))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'q' => Type::string(),
                        'status' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaReasons::list($args);
                    },
                ],
                'adminSettingsReturnReason' => [
                    'type' => $settingsReturnReason,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaReasons::find((int) $args['id']);
                    },
                ],
                'adminSettingsDataTransferImports' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($dataTransferImport))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'code' => Type::string(),
                        'type' => Type::string(),
                        'action' => Type::string(),
                        'state' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminSettingsDataTransferImports::list($args);
                    },
                ],
                'adminSettingsDataTransferImport' => [
                    'type' => $dataTransferImport,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminSettingsDataTransferImports::find((int) $args['id'], true);
                    },
                ],
                'adminSettingsDataTransferImportStats' => [
                    'type' => $dataTransferImport,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminSettingsDataTransferImports::find((int) $args['id'], false);
                    },
                ],
                'adminRmaStatuses' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($rmaStatus))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'q' => Type::string(),
                        'status' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaStatuses::list($args);
                    },
                ],
                'adminRmaStatus' => [
                    'type' => $rmaStatus,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaStatuses::find((int) $args['id']);
                    },
                ],
                'adminRmaRules' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($rmaRule))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'q' => Type::string(),
                        'status' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaRules::list($args);
                    },
                ],
                'adminRmaRule' => [
                    'type' => $rmaRule,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaRules::find((int) $args['id']);
                    },
                ],
                'adminMarketingSitemaps' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($marketingSitemap))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminMarketingSitemaps($args, false);
                    },
                ],
                'adminMarketingSitemap' => [
                    'type' => $marketingSitemap,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertSettingsManage();
                        $row = MarketingSitemap::query()->find((int) $args['id']);

                        return $row ? self::mapAdminMarketingSitemap($row, true) : null;
                    },
                ],
                'adminRmaCustomFields' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($rmaCustomField))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'q' => Type::string(),
                        'status' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaCustomFields::list($args);
                    },
                ],
                'adminRmaCustomField' => [
                    'type' => $rmaCustomField,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaCustomFields::find((int) $args['id']);
                    },
                ],
                'menuAdminConfigurationMenu' => [
                    'type' => Type::nonNull($configurationMenu),
                    'args' => [
                        'slug' => Type::string(),
                        'includeValues' => ['type' => Type::boolean(), 'defaultValue' => false],
                        'include_values' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::menuAdminConfigurationMenu($args);
                    },
                ],
                'listAdminConfigurationSlug' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                    'resolve' => function () {
                        self::assertSettingsManage();

                        return ConfigurationCatalog::slugs();
                    },
                ],
                'valuesAdminConfigurationValues' => [
                    'type' => Type::nonNull($configurationValues),
                    'args' => [
                        'slug' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::valuesAdminConfigurationValues($args);
                    },
                ],
                'adminProductTemplates' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($productTemplateType))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'code' => Type::string(),
                        'name' => Type::string(),
                        'id' => Type::string(),
                        'sort' => Type::string(),
                        'order' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminProductTemplates($args);
                    },
                ],
                'adminProductTemplate' => [
                    'type' => $productTemplateType,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCatalogFeatures();
                        $id = (int) preg_replace('/\D+/', '', (string) $args['id']);
                        $row = ProductTemplate::query()->find($id);

                        return $row ? self::mapAdminProductTemplate($row, true) : null;
                    },
                ],
                'statsAdminReportingOverview' => [
                    'type' => Type::nonNull($reportingOverview),
                    'args' => [
                        'type' => ['type' => Type::string(), 'defaultValue' => 'total-sales'],
                        'start' => Type::string(),
                        'end' => Type::string(),
                        'storeId' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::statsAdminReportingOverview($args);
                    },
                ],
                // Admin REST Dashboard (seven section types)
                'statsAdminDashboard' => [
                    'type' => Type::nonNull($adminDashboardStats),
                    'args' => [
                        'type' => ['type' => Type::string(), 'defaultValue' => 'overview'],
                        'start' => Type::string(),
                        'end' => Type::string(),
                        'storeId' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminDashboardStats::stats($args, true);
                    },
                ],
                'dashboard' => [
                    'type' => Type::nonNull($adminDashboardStats),
                    'args' => [
                        'type' => ['type' => Type::string(), 'defaultValue' => 'overview'],
                        'start' => Type::string(),
                        'end' => Type::string(),
                        'storeId' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminDashboardStats::stats($args, true);
                    },
                ],
                'adminMenu' => [
                    'type' => Type::nonNull($adminMenu),
                    'resolve' => function () {
                        return GraphqlAdminNavigation::menu(true);
                    },
                ],
                'getAdminMenu' => [
                    'type' => Type::nonNull($adminMenu),
                    'resolve' => function () {
                        return GraphqlAdminNavigation::menu(true);
                    },
                ],
                'adminPermissions' => [
                    'type' => Type::nonNull($adminPermissions),
                    'resolve' => function () {
                        $row = GraphqlAdminNavigation::permissions();
                        $row['permissions'] = json_encode($row['permissions'], JSON_UNESCAPED_UNICODE);

                        return $row;
                    },
                ],
                'getAdminPermissions' => [
                    'type' => Type::nonNull($adminPermissions),
                    'resolve' => function () {
                        $row = GraphqlAdminNavigation::permissions();
                        $row['permissions'] = json_encode($row['permissions'], JSON_UNESCAPED_UNICODE);

                        return $row;
                    },
                ],
                'readAdminProfile' => [
                    'type' => Type::nonNull($adminProfile),
                    'resolve' => fn () => GraphqlAdminNavigation::profile(),
                ],
                'adminProfile' => [
                    'type' => Type::nonNull($adminProfile),
                    'resolve' => fn () => GraphqlAdminNavigation::profile(),
                ],
                'salesReports' => [
                    'type' => Type::nonNull($salesReport),
                    'args' => [
                        'days' => ['type' => Type::int(), 'defaultValue' => 30],
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertOrdersManage();
                        $days = max(1, min(366, (int) ($args['days'] ?? 30)));
                        $to = now();
                        $from = now()->subDays($days)->startOfDay();
                        $q = Order::query()
                            ->where('status', '!=', 'cancelled')
                            ->whereBetween('created_at', [$from, $to]);
                        $orders = (int) (clone $q)->count();
                        $revenue = (float) (clone $q)->sum('grand_total');
                        $aov = $orders > 0 ? round($revenue / $orders, 2) : 0.0;

                        return [
                            'orders' => $orders,
                            'total_revenue' => round($revenue, 2),
                            'avg_order_value' => $aov,
                            'from' => $from->toIso8601String(),
                            'to' => $to->toIso8601String(),
                        ];
                    },
                ],
                'statsAdminReportingSales' => [
                    'type' => Type::nonNull($reportingOverview),
                    'args' => [
                        'type' => ['type' => Type::string(), 'defaultValue' => 'total-sales'],
                        'start' => Type::string(),
                        'end' => Type::string(),
                        'storeId' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminReportingStats::sales($args);
                    },
                ],
                'statsAdminReportingCustomers' => [
                    'type' => Type::nonNull($reportingOverview),
                    'args' => [
                        'type' => ['type' => Type::string(), 'defaultValue' => 'total-customers'],
                        'start' => Type::string(),
                        'end' => Type::string(),
                        'storeId' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminReportingStats::customers($args);
                    },
                ],
                'statsAdminReportingProducts' => [
                    'type' => Type::nonNull($reportingOverview),
                    'args' => [
                        'type' => ['type' => Type::string(), 'defaultValue' => 'total-sold-quantities'],
                        'start' => Type::string(),
                        'end' => Type::string(),
                        'storeId' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminReportingStats::products($args);
                    },
                ],

                'viewStatsAdminReportingSales' => [
                    'type' => Type::nonNull($reportingOverview),
                    'args' => [
                        'type' => ['type' => Type::string(), 'defaultValue' => 'total-sales'],
                        'start' => Type::string(),
                        'end' => Type::string(),
                        'storeId' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminReportingStats::viewSales($args);
                    },
                ],
                'viewStatsAdminReportingCustomers' => [
                    'type' => Type::nonNull($reportingOverview),
                    'args' => [
                        'type' => ['type' => Type::string(), 'defaultValue' => 'total-customers'],
                        'start' => Type::string(),
                        'end' => Type::string(),
                        'storeId' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminReportingStats::viewCustomers($args);
                    },
                ],
                'viewStatsAdminReportingProducts' => [
                    'type' => Type::nonNull($reportingOverview),
                    'args' => [
                        'type' => ['type' => Type::string(), 'defaultValue' => 'total-sold-quantities'],
                        'start' => Type::string(),
                        'end' => Type::string(),
                        'storeId' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminReportingStats::viewProducts($args);
                    },
                ],
                'adminReturns' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($adminReturn))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'first' => Type::int(),
                        'id' => Type::int(),
                        'order_id' => Type::int(),
                        'orderId' => Type::id(),
                        'status' => Type::string(),
                        'customer_name' => Type::string(),
                        'customerName' => Type::string(),
                        'sort' => Type::string(),
                        'order' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminRmaGraphql::listReturns($args);
                    },
                ],
                'adminReturn' => [
                    'type' => $adminReturn,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminRmaGraphql::getReturn($args);
                    },
                ],
                'adminReturnableItems' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($adminReturnableItem))),
                    'args' => [
                        'orderId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminRmaGraphql::returnableItems($args);
                    },
                ],
                'adminReturnReasons' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($adminReturnReasonLite))),
                    'args' => [
                        'resolutionType' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminRmaGraphql::returnReasons($args);
                    },
                ],
                'adminReturnMessages' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($adminReturnMessage))),
                    'args' => [
                        'returnId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminRmaGraphql::listMessages($args);
                    },
                ],
                'adminEuWithdrawals' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($adminEuWithdrawal))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'first' => Type::int(),
                        'status' => Type::string(),
                        'order_increment_id' => Type::string(),
                        'orderIncrementId' => Type::string(),
                        'customer_email' => Type::string(),
                        'customerEmail' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminEuWithdrawalGraphql::list($args);
                    },
                ],
                'adminEuWithdrawal' => [
                    'type' => $adminEuWithdrawal,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminEuWithdrawalGraphql::get($args);
                    },
                ],
                'adminBookings' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($adminBooking))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'first' => Type::int(),
                        'id' => Type::int(),
                        'order_id' => Type::int(),
                        'orderId' => Type::id(),
                        'product_id' => Type::int(),
                        'productId' => Type::id(),
                        'from_from' => Type::string(),
                        'from_to' => Type::string(),
                        'to_from' => Type::string(),
                        'to_to' => Type::string(),
                        'created_at_from' => Type::string(),
                        'created_at_to' => Type::string(),
                        'date_from' => Type::string(),
                        'date_to' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminBookingGraphql::list($args);
                    },
                ],
                'adminBooking' => [
                    'type' => $adminBooking,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminBookingGraphql::get($args);
                    },
                ],
                'paymentTransactions' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($paymentTransaction))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'orderNumber' => Type::string(),
                        'invoiceNumber' => Type::string(),
                        'status' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminPaymentTransactions($args);
                    },
                ],
                'adminTransactions' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($paymentTransaction))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'first' => Type::int(),
                        'orderNumber' => Type::string(),
                        'invoiceNumber' => Type::string(),
                        'status' => Type::string(),
                        'transaction_id' => Type::string(),
                        'transactionId' => Type::string(),
                        'order_id' => Type::int(),
                        'orderId' => Type::id(),
                        'invoice_id' => Type::int(),
                        'created_at_from' => Type::string(),
                        'created_at_to' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminPaymentTransactions($args);
                    },
                ],
                'adminTransaction' => [
                    'type' => $paymentTransaction,
                    'args' => [
                        'id' => Type::id(),
                        'transactionId' => Type::string(),
                        'orderNumber' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $rows = self::listAdminPaymentTransactions([
                            'limit' => 200,
                            'orderNumber' => $args['orderNumber'] ?? null,
                        ]);
                        $id = trim((string) ($args['id'] ?? ''));
                        $txId = trim((string) ($args['transactionId'] ?? ''));
                        foreach ($rows as $row) {
                            if ($id !== '' && (string) ($row['id'] ?? '') === $id) {
                                return $row;
                            }
                            if ($txId !== '' && (string) ($row['transaction_id'] ?? '') === $txId) {
                                return $row;
                            }
                        }

                        return $rows[0] ?? null;
                    },
                ],
                'searchSynonyms' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($searchSynonym))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'name' => Type::string(),
                        'terms' => Type::string(),
                        'q' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminMarketingSearchSynonyms($args);
                    },
                ],
                'adminMarketingSearchSynonyms' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($searchSynonym))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'name' => Type::string(),
                        'terms' => Type::string(),
                        'q' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminMarketingSearchSynonyms($args);
                    },
                ],
                'adminMarketingSearchSynonym' => [
                    'type' => $searchSynonym,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertMarketingSearch();
                        $row = SearchSynonym::query()->find((int) $args['id']);

                        return $row ? self::mapAdminMarketingSearchSynonym($row) : null;
                    },
                ],
                'searchRedirects' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($searchRedirect))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'q' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminMarketingUrlRewrites($args);
                    },
                ],
                'adminMarketingUrlRewrites' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($searchRedirect))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'q' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminMarketingUrlRewrites($args);
                    },
                ],
                'adminMarketingUrlRewrite' => [
                    'type' => $searchRedirect,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertMarketingSearch();
                        $row = SearchRedirect::query()->find((int) $args['id']);

                        return $row ? self::mapAdminMarketingUrlRewrite($row) : null;
                    },
                ],
                'adminMarketingSearchTerms' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($searchRedirect))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'q' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminMarketingUrlRewrites($args);
                    },
                ],
                'adminMarketingSearchTerm' => [
                    'type' => $searchRedirect,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertMarketingSearch();
                        $row = SearchRedirect::query()->find((int) $args['id']);

                        return $row ? self::mapAdminMarketingUrlRewrite($row) : null;
                    },
                ],
                'refunds' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($refundListRow))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                        'orderNumber' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminRefunds($args);
                    },
                ],
                'adminRefunds' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($refundListRow))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                        'orderNumber' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminRefunds($args);
                    },
                ],
                'adminRefund' => [
                    'type' => $refundListRow,
                    'args' => [
                        'orderNumber' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        $rows = self::listAdminRefunds([
                            'limit' => 1,
                            'orderNumber' => $args['orderNumber'],
                        ]);

                        return $rows[0] ?? null;
                    },
                ],
                'adminOrderComposerDraft' => [
                    'type' => $composerDraft,
                    'args' => [
                        'cartId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminOrderComposerDraft::get((string) $args['cartId']);
                    },
                ],
                'adminComposerCart' => [
                    'type' => $composerDraft,
                    'args' => [
                        'cartId' => Type::nonNull(Type::id()),
                        'id' => Type::id(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $cartId = (string) ($args['cartId'] ?? $args['id'] ?? '');
                        if ($cartId === '') {
                            throw new UserError('cartId is required.');
                        }

                        return GraphqlAdminOrderComposerDraft::get($cartId);
                    },
                ],
                'adminOrderComposerDraftShippingMethods' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($composerDraftShippingMethod))),
                    'args' => [
                        'cartId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminOrderComposerDraft::shippingMethods((string) $args['cartId']);
                    },
                ],
                'adminComposerCartShippingRates' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($composerDraftShippingMethod))),
                    'args' => [
                        'cartId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminOrderComposerDraft::shippingMethods((string) $args['cartId']);
                    },
                ],
                'adminOrderComposerDraftPaymentMethods' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($composerDraftPaymentMethod))),
                    'args' => [
                        'cartId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminOrderComposerDraft::paymentMethods((string) $args['cartId']);
                    },
                ],
                'adminComposerCartPaymentMethods' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($composerDraftPaymentMethod))),
                    'args' => [
                        'cartId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminOrderComposerDraft::paymentMethods((string) $args['cartId']);
                    },
                ],
                'customerRecentOrderItems' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($customerInterestItem))),
                    'args' => [
                        'customerId' => Type::nonNull(Type::id()),
                        'limit' => ['type' => Type::int(), 'defaultValue' => 10],
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertOrdersManage();
                        $customerId = (int) $args['customerId'];
                        if (! Customer::query()->whereKey($customerId)->exists()) {
                            throw new UserError('Customer not found.');
                        }
                        $limit = max(1, min(50, (int) ($args['limit'] ?? 10)));
                        $locale = app()->getLocale();
                        $productIds = OrderItem::query()
                            ->whereHas('order', fn ($q) => $q->where('customer_id', $customerId))
                            ->whereNotNull('product_id')
                            ->orderByDesc('id')
                            ->limit($limit * 3)
                            ->pluck('product_id')
                            ->unique()
                            ->take($limit)
                            ->values();

                        return Product::query()
                            ->with('translations')
                            ->whereIn('id', $productIds->all())
                            ->where('is_enabled', true)
                            ->get()
                            ->map(fn (Product $p) => [
                                'product_id' => (string) $p->id,
                                'sku' => $p->sku,
                                'name' => $p->translation($locale)?->name,
                                'price' => $p->price !== null ? (float) $p->price : null,
                                'source' => 'recent_order',
                            ])
                            ->all();
                    },
                ],
                'customerWishlistItems' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($customerInterestItem))),
                    'args' => [
                        'customerId' => Type::nonNull(Type::id()),
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertOrdersManage();
                        $customer = Customer::query()->find((int) $args['customerId']);
                        if (! $customer) {
                            throw new UserError('Customer not found.');
                        }
                        $limit = max(1, min(50, (int) ($args['limit'] ?? 20)));
                        $locale = app()->getLocale();
                        $ids = $customer->wishlist()->pluck('product_id')->take($limit)->all();

                        return Product::query()
                            ->with('translations')
                            ->whereIn('id', $ids)
                            ->where('is_enabled', true)
                            ->get()
                            ->map(fn (Product $p) => [
                                'product_id' => (string) $p->id,
                                'sku' => $p->sku,
                                'name' => $p->translation($locale)?->name,
                                'price' => $p->price !== null ? (float) $p->price : null,
                                'source' => 'wishlist',
                            ])
                            ->all();
                    },
                ],
                'customerCompareItems' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($customerInterestItem))),
                    'args' => [
                        'customerId' => Type::nonNull(Type::id()),
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertOrdersManage();
                        $customer = Customer::query()->find((int) $args['customerId']);
                        if (! $customer) {
                            throw new UserError('Customer not found.');
                        }
                        $limit = max(1, min(50, (int) ($args['limit'] ?? 20)));
                        $locale = app()->getLocale();
                        $profile = is_array($customer->profile) ? $customer->profile : [];
                        $raw = $profile['compare_ids'] ?? [];
                        $ids = collect(is_array($raw) ? $raw : [])
                            ->map(fn ($id) => (int) $id)
                            ->filter(fn (int $id) => $id > 0)
                            ->unique()
                            ->take($limit)
                            ->values()
                            ->all();

                        return Product::query()
                            ->with('translations')
                            ->whereIn('id', $ids)
                            ->where('is_enabled', true)
                            ->get()
                            ->map(fn (Product $p) => [
                                'product_id' => (string) $p->id,
                                'sku' => $p->sku,
                                'name' => $p->translation($locale)?->name,
                                'price' => $p->price !== null ? (float) $p->price : null,
                                'source' => 'compare',
                            ])
                            ->all();
                    },
                ],
                // Admin REST Create-Order helper panels (adminCustomer* aliases).
                'adminCustomerCartItems' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($customerInterestItem))),
                    'args' => [
                        'customerId' => Type::nonNull(Type::id()),
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertOrdersManage();
                        $customerId = (int) $args['customerId'];
                        if (! Customer::query()->whereKey($customerId)->exists()) {
                            throw new UserError('Customer not found.');
                        }
                        $limit = max(1, min(50, (int) ($args['limit'] ?? 50)));
                        $locale = app()->getLocale();
                        $cart = Cart::query()
                            ->where('customer_id', $customerId)
                            ->orderByDesc('id')
                            ->with(['items.product.translations'])
                            ->first();
                        if (! $cart) {
                            return [];
                        }
                        $rows = [];
                        foreach ($cart->items as $item) {
                            $product = $item->product;
                            if (! $product || ! $product->is_enabled) {
                                continue;
                            }
                            $rows[] = [
                                'product_id' => (string) $product->id,
                                'sku' => $product->sku,
                                'name' => $product->translation($locale)?->name,
                                'price' => $product->price !== null ? (float) $product->price : null,
                                'source' => 'cart',
                            ];
                            if (count($rows) >= $limit) {
                                break;
                            }
                        }

                        return $rows;
                    },
                ],
                'adminCustomerWishlistItems' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($customerInterestItem))),
                    'args' => [
                        'customerId' => Type::nonNull(Type::id()),
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertOrdersManage();
                        $customer = Customer::query()->find((int) $args['customerId']);
                        if (! $customer) {
                            throw new UserError('Customer not found.');
                        }
                        $limit = max(1, min(50, (int) ($args['limit'] ?? 20)));
                        $locale = app()->getLocale();
                        $ids = $customer->wishlist()->pluck('product_id')->take($limit)->all();

                        return Product::query()
                            ->with('translations')
                            ->whereIn('id', $ids)
                            ->where('is_enabled', true)
                            ->get()
                            ->map(fn (Product $p) => [
                                'product_id' => (string) $p->id,
                                'sku' => $p->sku,
                                'name' => $p->translation($locale)?->name,
                                'price' => $p->price !== null ? (float) $p->price : null,
                                'source' => 'wishlist',
                            ])
                            ->all();
                    },
                ],
                'adminCustomerRecentOrderItems' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($customerInterestItem))),
                    'args' => [
                        'customerId' => Type::nonNull(Type::id()),
                        'limit' => ['type' => Type::int(), 'defaultValue' => 10],
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertOrdersManage();
                        $customerId = (int) $args['customerId'];
                        if (! Customer::query()->whereKey($customerId)->exists()) {
                            throw new UserError('Customer not found.');
                        }
                        $limit = max(1, min(50, (int) ($args['limit'] ?? 10)));
                        $locale = app()->getLocale();
                        $productIds = OrderItem::query()
                            ->whereHas('order', fn ($q) => $q->where('customer_id', $customerId))
                            ->whereNotNull('product_id')
                            ->orderByDesc('id')
                            ->limit($limit * 3)
                            ->pluck('product_id')
                            ->unique()
                            ->take($limit)
                            ->values();

                        return Product::query()
                            ->with('translations')
                            ->whereIn('id', $productIds->all())
                            ->where('is_enabled', true)
                            ->get()
                            ->map(fn (Product $p) => [
                                'product_id' => (string) $p->id,
                                'sku' => $p->sku,
                                'name' => $p->translation($locale)?->name,
                                'price' => $p->price !== null ? (float) $p->price : null,
                                'source' => 'recent_order',
                            ])
                            ->all();
                    },
                ],
                'adminCustomerCompareItems' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($customerInterestItem))),
                    'args' => [
                        'customerId' => Type::nonNull(Type::id()),
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertOrdersManage();
                        $customer = Customer::query()->find((int) $args['customerId']);
                        if (! $customer) {
                            throw new UserError('Customer not found.');
                        }
                        $limit = max(1, min(50, (int) ($args['limit'] ?? 20)));
                        $locale = app()->getLocale();
                        $profile = is_array($customer->profile) ? $customer->profile : [];
                        $raw = $profile['compare_ids'] ?? [];
                        $ids = collect(is_array($raw) ? $raw : [])
                            ->map(fn ($id) => (int) $id)
                            ->filter(fn (int $id) => $id > 0)
                            ->unique()
                            ->take($limit)
                            ->values()
                            ->all();

                        return Product::query()
                            ->with('translations')
                            ->whereIn('id', $ids)
                            ->where('is_enabled', true)
                            ->get()
                            ->map(fn (Product $p) => [
                                'product_id' => (string) $p->id,
                                'sku' => $p->sku,
                                'name' => $p->translation($locale)?->name,
                                'price' => $p->price !== null ? (float) $p->price : null,
                                'source' => 'compare',
                            ])
                            ->all();
                    },
                ],
                'invoices' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($invoiceListRow))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                        'orderNumber' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminInvoices($args);
                    },
                ],
                'adminInvoices' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($invoiceListRow))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                        'orderNumber' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminInvoices($args);
                    },
                ],
                'adminInvoice' => [
                    'type' => $invoiceListRow,
                    'args' => [
                        'invoiceNumber' => Type::string(),
                        'orderNumber' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $rows = self::listAdminInvoices([
                            'limit' => 50,
                            'orderNumber' => $args['orderNumber'] ?? null,
                        ]);
                        if (! empty($args['invoiceNumber'])) {
                            $needle = (string) $args['invoiceNumber'];
                            foreach ($rows as $row) {
                                if ((string) ($row['invoice_number'] ?? '') === $needle) {
                                    return $row;
                                }
                            }

                            return null;
                        }

                        return $rows[0] ?? null;
                    },
                ],
                'adminCategories' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($category))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'first' => Type::int(),
                        'q' => Type::string(),
                        'name' => Type::string(),
                        'parentId' => Type::id(),
                        'enabled' => Type::boolean(),
                        'status' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminCategories($args);
                    },
                ],
                'adminCatalogCategories' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($category))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'first' => Type::int(),
                        'q' => Type::string(),
                        'name' => Type::string(),
                        'parentId' => Type::id(),
                        'enabled' => Type::boolean(),
                        'status' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminCategories($args);
                    },
                ],
                // Admin REST docs → categories
                'categories' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($category))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'first' => Type::int(),
                        'q' => Type::string(),
                        'name' => Type::string(),
                        'parentId' => Type::id(),
                        'enabled' => Type::boolean(),
                        'status' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminCategories($args);
                    },
                ],
                'adminCategory' => [
                    'type' => $category,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCatalogCategories();
                        $row = Category::query()->find((int) $args['id']);

                        return $row ? self::mapAdminCategory($row) : null;
                    },
                ],
                // Admin REST docs → adminCategoryTrees
                'adminCategoryTrees' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($categoryTree))),
                    'args' => [
                        'parentId' => Type::id(),
                        'enabled' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminCategoryTrees($args);
                    },
                ],
                'adminCustomerReviews' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($customerReview))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'status' => Type::string(),
                        'rating' => Type::int(),
                        'productId' => Type::id(),
                        'customerId' => Type::id(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listAdminCustomerReviews($args);
                    },
                ],
                'adminCustomerReview' => [
                    'type' => $customerReview,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCustomersManage();
                        $row = Review::query()->with(['product.translations', 'customer'])->find((int) $args['id']);

                        return $row ? self::mapAdminCustomerReview($row) : null;
                    },
                ],
                // Admin REST Customer Notes (append-only timeline).
                'adminCustomerNotes' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($adminCustomerNote))),
                    'args' => [
                        'customerId' => Type::nonNull(Type::id()),
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminCustomerNotes::list(
                            (int) $args['customerId'],
                            (int) ($args['limit'] ?? 50),
                        );
                    },
                ],
                // Wento Customer Desk — unified activity (notes/orders/reviews/GDPR/impersonation).
                'adminCustomerDesk' => [
                    'type' => Type::nonNull($adminCustomerDesk),
                    'args' => [
                        'customerId' => Type::nonNull(Type::id()),
                        'limit' => ['type' => Type::int(), 'defaultValue' => 40],
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminCustomerDesk::timeline(
                            (int) $args['customerId'],
                            (int) ($args['limit'] ?? 40),
                        );
                    },
                ],
                'adminCustomerGdprRequests' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($adminGdprRequest))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                        'status' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminGdprRequests::list(
                            (int) ($args['limit'] ?? 50),
                            isset($args['status']) ? (string) $args['status'] : null,
                        );
                    },
                ],
                'adminCustomerGdprRequest' => [
                    'type' => $adminGdprRequest,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminGdprRequests::find((int) $args['id']);
                    },
                ],
            ],
        ]);

        $placeOrderResult = new ObjectType([
            'name' => 'AdminPlaceOrderResult',
            'fields' => [
                'success' => Type::nonNull(Type::boolean()),
                'message' => Type::string(),
                'order_id' => Type::id(),
                'order_number' => Type::string(),
                'grand_total' => Type::float(),
                'orderId' => Type::id(),
                'incrementId' => Type::string(),
                'customerId' => Type::id(),
                'grandTotal' => Type::float(),
                'order' => $order,
            ],
        ]);

        $adminPlaceOrderInput = new InputObjectType([
            'name' => 'AdminPlaceOrderInput',
            'fields' => [
                'cartId' => Type::nonNull(Type::id()),
                'customerNote' => Type::string(),
            ],
        ]);

        $mutation = new ObjectType([
            'name' => 'Mutation',
            'fields' => [
                'updateOrderStatus' => [
                    'type' => Type::nonNull($order),
                    'args' => [
                        'number' => Type::nonNull(Type::string()),
                        'status' => Type::nonNull(Type::string()),
                        'comment' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $admin = auth('admin')->user();
                        if (! $admin instanceof Admin || ! $admin->hasPermission('orders.manage')) {
                            throw new UserError('Forbidden.');
                        }
                        OrderStageCatalog::seed();
                        $row = Order::query()->where('number', $args['number'])->first();
                        if (! $row) {
                            throw new UserError('Order not found.');
                        }
                        try {
                            app(OrderStageService::class)->apply(
                                $row,
                                (string) $args['status'],
                                isset($args['comment']) ? (string) $args['comment'] : null,
                            );
                        } catch (\Throwable $e) {
                            throw new UserError($e->getMessage());
                        }
                        $row = $row->fresh();

                        return self::mapAdminOrder($row, false);
                    },
                ],
                'cancelOrder' => [
                    'type' => Type::nonNull($order),
                    'args' => [
                        'number' => Type::nonNull(Type::string()),
                        'comment' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminCancelOrder($args);
                    },
                ],
                'createAdminCancelOrder' => [
                    'type' => Type::nonNull($order),
                    'args' => [
                        'number' => Type::nonNull(Type::string()),
                        'comment' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminCancelOrder($args);
                    },
                ],
                'bulkCancelAdminOrders' => [
                    'type' => Type::nonNull($bulkResult),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                        'numbers' => Type::listOf(Type::nonNull(Type::string())),
                        'comment' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkCancelAdminOrders($args);
                    },
                ],
                'createAdminOrder' => [
                    'type' => Type::nonNull($order),
                    'args' => [
                        'customerId' => Type::nonNull(Type::id()),
                        'items' => Type::nonNull(Type::listOf(Type::nonNull($adminOrderItemInput))),
                        'currencyCode' => Type::string(),
                        'customerNote' => Type::string(),
                        'draft' => ['type' => Type::boolean(), 'defaultValue' => false],
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminOrderComposer::run($args);
                    },
                ],
                'createAdminOrderComposerDraft' => [
                    'type' => Type::nonNull($createAdminOrderComposerDraftPayload),
                    'args' => [
                        'customerId' => Type::id(),
                        'input' => new InputObjectType([
                            'name' => 'createAdminOrderComposerDraftInput',
                            'fields' => [
                                'customerId' => Type::nonNull(Type::id()),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        $customerId = (int) ($args['customerId'] ?? $input['customerId'] ?? 0);
                        if ($customerId < 1) {
                            throw new UserError('customerId is required.');
                        }
                        $cart = GraphqlAdminOrderComposerDraft::create($customerId);

                        return array_merge($cart, [
                            'adminOrderComposerDraft' => array_merge($cart, [
                                'success' => true,
                                'message' => 'Order composer draft created.',
                            ]),
                            'success' => true,
                            'message' => 'Order composer draft created.',
                        ]);
                    },
                ],
                'addItemAdminOrderComposerDraft' => [
                    'type' => Type::nonNull($composerDraft),
                    'args' => [
                        'cartId' => Type::nonNull(Type::id()),
                        'productId' => Type::nonNull(Type::id()),
                        'quantity' => ['type' => Type::float(), 'defaultValue' => 1],
                        'selectedVariantOption' => Type::id(),
                        'groupedQuantities' => Type::listOf(Type::nonNull($groupedQtyInput)),
                        'bundleOptions' => Type::listOf(Type::nonNull($bundleOptionInput)),
                    ],
                    'resolve' => function ($root, array $args) {
                        $grouped = [];
                        foreach ($args['groupedQuantities'] ?? [] as $row) {
                            if (! is_array($row)) {
                                continue;
                            }
                            $grouped[] = [
                                'product_id' => (int) ($row['productId'] ?? 0),
                                'qty' => (float) ($row['quantity'] ?? 1),
                            ];
                        }
                        $bundle = [];
                        foreach ($args['bundleOptions'] ?? [] as $row) {
                            if (! is_array($row)) {
                                continue;
                            }
                            $bundle[] = [
                                'product_id' => (int) ($row['productId'] ?? 0),
                                'qty' => (float) ($row['quantity'] ?? 1),
                            ];
                        }

                        return GraphqlAdminOrderComposerDraft::addItem(
                            (string) $args['cartId'],
                            (int) $args['productId'],
                            (float) ($args['quantity'] ?? 1),
                            isset($args['selectedVariantOption'])
                                ? (int) $args['selectedVariantOption']
                                : null,
                            $grouped,
                            $bundle,
                        );
                    },
                ],
                'updateItemAdminOrderComposerDraft' => [
                    'type' => Type::nonNull($composerDraft),
                    'args' => [
                        'cartId' => Type::nonNull(Type::id()),
                        'itemId' => Type::nonNull(Type::id()),
                        'quantity' => Type::nonNull(Type::float()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminOrderComposerDraft::updateItem(
                            (string) $args['cartId'],
                            (string) $args['itemId'],
                            (float) $args['quantity'],
                        );
                    },
                ],
                'removeItemAdminOrderComposerDraft' => [
                    'type' => Type::nonNull($composerDraft),
                    'args' => [
                        'cartId' => Type::nonNull(Type::id()),
                        'itemId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminOrderComposerDraft::removeItem(
                            (string) $args['cartId'],
                            (string) $args['itemId'],
                        );
                    },
                ],
                'placeAdminOrderComposerDraft' => [
                    'type' => Type::nonNull($order),
                    'args' => [
                        'cartId' => Type::nonNull(Type::id()),
                        'customerNote' => Type::string(),
                        'requireComplete' => ['type' => Type::boolean(), 'defaultValue' => false],
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminOrderComposerDraft::place(
                            (string) $args['cartId'],
                            array_key_exists('customerNote', $args) ? (string) $args['customerNote'] : null,
                            (bool) ($args['requireComplete'] ?? false),
                        );
                    },
                ],
                'createAdminPlaceOrder' => [
                    'type' => Type::nonNull($placeOrderResult),
                    'args' => [
                        'cartId' => Type::id(),
                        'customerNote' => Type::string(),
                        'input' => $adminPlaceOrderInput,
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        $cartId = (string) ($args['cartId'] ?? $input['cartId'] ?? '');
                        if ($cartId === '') {
                            throw new UserError('cartId is required.');
                        }
                        $note = null;
                        if (array_key_exists('customerNote', $args)) {
                            $note = (string) $args['customerNote'];
                        } elseif (array_key_exists('customerNote', $input)) {
                            $note = (string) $input['customerNote'];
                        }
                        $placed = GraphqlAdminOrderComposerDraft::place($cartId, $note, true);
                        $orderId = $placed['id'] ?? null;
                        $number = $placed['number'] ?? null;
                        $total = isset($placed['grand_total']) ? (float) $placed['grand_total'] : null;
                        $customerId = $placed['customer_id'] ?? ($placed['customerId'] ?? null);

                        return [
                            'success' => true,
                            'message' => 'Order placed successfully.',
                            'order_id' => $orderId,
                            'order_number' => $number,
                            'grand_total' => $total,
                            'orderId' => $orderId,
                            'incrementId' => $number,
                            'customerId' => $customerId !== null ? (string) $customerId : null,
                            'grandTotal' => $total,
                            'order' => $placed,
                        ];
                    },
                ],
                'addItemAdminCart' => [
                    'type' => Type::nonNull($composerDraft),
                    'args' => [
                        'cartId' => Type::nonNull(Type::id()),
                        'productId' => Type::nonNull(Type::id()),
                        'quantity' => ['type' => Type::float(), 'defaultValue' => 1],
                        'selectedVariantOption' => Type::id(),
                        'groupedQuantities' => Type::listOf(Type::nonNull($groupedQtyInput)),
                        'bundleOptions' => Type::listOf(Type::nonNull($bundleOptionInput)),
                    ],
                    'resolve' => function ($root, array $args) {
                        $grouped = [];
                        foreach ($args['groupedQuantities'] ?? [] as $row) {
                            if (is_array($row)) {
                                $grouped[] = [
                                    'product_id' => (int) ($row['productId'] ?? 0),
                                    'qty' => (float) ($row['quantity'] ?? 1),
                                ];
                            }
                        }
                        $bundle = [];
                        foreach ($args['bundleOptions'] ?? [] as $row) {
                            if (is_array($row)) {
                                $bundle[] = [
                                    'product_id' => (int) ($row['productId'] ?? 0),
                                    'qty' => (float) ($row['quantity'] ?? 1),
                                ];
                            }
                        }

                        return GraphqlAdminOrderComposerDraft::addItem(
                            (string) $args['cartId'],
                            (int) $args['productId'],
                            (float) ($args['quantity'] ?? 1),
                            isset($args['selectedVariantOption']) ? (int) $args['selectedVariantOption'] : null,
                            $grouped,
                            $bundle,
                        );
                    },
                ],
                'updateItemsAdminCart' => [
                    'type' => Type::nonNull($composerDraft),
                    'args' => [
                        'cartId' => Type::nonNull(Type::id()),
                        'itemId' => Type::nonNull(Type::id()),
                        'quantity' => Type::nonNull(Type::float()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminOrderComposerDraft::updateItem(
                            (string) $args['cartId'],
                            (string) $args['itemId'],
                            (float) $args['quantity'],
                        );
                    },
                ],
                'removeItemAdminCart' => [
                    'type' => Type::nonNull($composerDraft),
                    'args' => [
                        'cartId' => Type::nonNull(Type::id()),
                        'itemId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminOrderComposerDraft::removeItem(
                            (string) $args['cartId'],
                            (string) $args['itemId'],
                        );
                    },
                ],
                'saveAddressAdminCart' => [
                    'type' => Type::nonNull($composerDraft),
                    'args' => [
                        'cartId' => Type::nonNull(Type::id()),
                        'billing' => Type::nonNull($composerDraftAddressInput),
                        'shipping' => $composerDraftAddressInput,
                        'useForShipping' => ['type' => Type::boolean(), 'defaultValue' => true],
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminOrderComposerDraft::saveAddresses(
                            (string) $args['cartId'],
                            (array) $args['billing'],
                            isset($args['shipping']) ? (array) $args['shipping'] : null,
                            (bool) ($args['useForShipping'] ?? true),
                        );
                    },
                ],
                'setShippingMethodAdminCart' => [
                    'type' => Type::nonNull($composerDraft),
                    'args' => [
                        'cartId' => Type::nonNull(Type::id()),
                        'code' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminOrderComposerDraft::setShippingMethod(
                            (string) $args['cartId'],
                            (string) $args['code'],
                        );
                    },
                ],
                'setPaymentMethodAdminCart' => [
                    'type' => Type::nonNull($composerDraft),
                    'args' => [
                        'cartId' => Type::nonNull(Type::id()),
                        'code' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminOrderComposerDraft::setPaymentMethod(
                            (string) $args['cartId'],
                            (string) $args['code'],
                        );
                    },
                ],
                'applyCouponAdminCart' => [
                    'type' => Type::nonNull($composerDraft),
                    'args' => [
                        'cartId' => Type::nonNull(Type::id()),
                        'code' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminOrderComposerDraft::applyCoupon(
                            (string) $args['cartId'],
                            (string) $args['code'],
                        );
                    },
                ],
                'removeCouponAdminCart' => [
                    'type' => Type::nonNull($composerDraft),
                    'args' => [
                        'cartId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminOrderComposerDraft::removeCoupon((string) $args['cartId']);
                    },
                ],
                'saveAddressAdminOrderComposerDraft' => [
                    'type' => Type::nonNull($composerDraft),
                    'args' => [
                        'cartId' => Type::nonNull(Type::id()),
                        'billing' => Type::nonNull($composerDraftAddressInput),
                        'shipping' => $composerDraftAddressInput,
                        'useForShipping' => ['type' => Type::boolean(), 'defaultValue' => true],
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminOrderComposerDraft::saveAddresses(
                            (string) $args['cartId'],
                            (array) $args['billing'],
                            isset($args['shipping']) ? (array) $args['shipping'] : null,
                            (bool) ($args['useForShipping'] ?? true),
                        );
                    },
                ],
                'setShippingMethodAdminOrderComposerDraft' => [
                    'type' => Type::nonNull($composerDraft),
                    'args' => [
                        'cartId' => Type::nonNull(Type::id()),
                        'code' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminOrderComposerDraft::setShippingMethod(
                            (string) $args['cartId'],
                            (string) $args['code'],
                        );
                    },
                ],
                'setPaymentMethodAdminOrderComposerDraft' => [
                    'type' => Type::nonNull($composerDraft),
                    'args' => [
                        'cartId' => Type::nonNull(Type::id()),
                        'code' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminOrderComposerDraft::setPaymentMethod(
                            (string) $args['cartId'],
                            (string) $args['code'],
                        );
                    },
                ],
                'applyCouponAdminOrderComposerDraft' => [
                    'type' => Type::nonNull($composerDraft),
                    'args' => [
                        'cartId' => Type::nonNull(Type::id()),
                        'code' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminOrderComposerDraft::applyCoupon(
                            (string) $args['cartId'],
                            (string) $args['code'],
                        );
                    },
                ],
                'removeCouponAdminOrderComposerDraft' => [
                    'type' => Type::nonNull($composerDraft),
                    'args' => [
                        'cartId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminOrderComposerDraft::removeCoupon((string) $args['cartId']);
                    },
                ],
                'createAdminReorder' => [
                    'type' => Type::nonNull($composerDraft),
                    'args' => [
                        'number' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminOrderComposerDraft::createFromOrder((string) $args['number']);
                    },
                ],
                'reorderAdminOrder' => [
                    'type' => Type::nonNull($order),
                    'args' => [
                        'number' => Type::nonNull(Type::string()),
                        'customerNote' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertOrdersManage();
                        $source = Order::query()->with('items')->where('number', $args['number'])->first();
                        if (! $source) {
                            throw new UserError('Order not found.');
                        }
                        if (! $source->customer_id) {
                            throw new UserError('Reorder is not supported for guest orders.');
                        }
                        $items = [];
                        foreach ($source->items as $item) {
                            $productId = (int) ($item->product_id ?? 0);
                            $qty = (float) ($item->qty ?? 0);
                            if ($productId < 1 || $qty <= 0) {
                                continue;
                            }
                            if (! Product::query()->whereKey($productId)->where('is_enabled', true)->exists()) {
                                continue;
                            }
                            $items[] = [
                                'productId' => (string) $productId,
                                'quantity' => $qty,
                            ];
                        }
                        if ($items === []) {
                            throw new UserError('One or more items in this order are no longer available for purchase.');
                        }

                        return GraphqlAdminOrderComposer::run([
                            'customerId' => (string) $source->customer_id,
                            'items' => $items,
                            'currencyCode' => $source->currency_code,
                            'customerNote' => array_key_exists('customerNote', $args)
                                ? (string) $args['customerNote']
                                : ('Reorder of '.$source->number),
                        ]);
                    },
                ],
                'addOrderComment' => [
                    'type' => Type::nonNull($orderComment),
                    'args' => [
                        'number' => Type::nonNull(Type::string()),
                        'body' => Type::nonNull(Type::string()),
                        'visibility' => ['type' => Type::string(), 'defaultValue' => 'private'],
                        'customerNotified' => ['type' => Type::boolean(), 'defaultValue' => false],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminOrderComment($args);
                    },
                ],
                'createAdminOrderComment' => [
                    'type' => Type::nonNull($orderComment),
                    'args' => [
                        'number' => Type::nonNull(Type::string()),
                        'body' => Type::nonNull(Type::string()),
                        'visibility' => ['type' => Type::string(), 'defaultValue' => 'private'],
                        'customerNotified' => ['type' => Type::boolean(), 'defaultValue' => false],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminOrderComment($args);
                    },
                ],
                'createShipment' => [
                    'type' => Type::nonNull($shipment),
                    'args' => [
                        'number' => Type::nonNull(Type::string()),
                        'carrier' => Type::string(),
                        'trackingNumber' => Type::string(),
                        'notes' => Type::string(),
                        'warehouseId' => Type::id(),
                        'items' => Type::listOf(Type::nonNull($shipmentItemInput)),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminShipment($args);
                    },
                ],
                'createAdminShipment' => [
                    'type' => Type::nonNull($shipment),
                    'args' => [
                        'number' => Type::nonNull(Type::string()),
                        'carrier' => Type::string(),
                        'trackingNumber' => Type::string(),
                        'notes' => Type::string(),
                        'warehouseId' => Type::id(),
                        'items' => Type::listOf(Type::nonNull($shipmentItemInput)),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminShipment($args);
                    },
                ],
                'createAdminSalesShipment' => [
                    'type' => Type::nonNull($shipment),
                    'args' => [
                        'number' => Type::nonNull(Type::string()),
                        'carrier' => Type::string(),
                        'trackingNumber' => Type::string(),
                        'notes' => Type::string(),
                        'warehouseId' => Type::id(),
                        'items' => Type::listOf(Type::nonNull($shipmentItemInput)),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminShipment($args);
                    },
                ],
                'ensureInvoice' => [
                    'type' => Type::nonNull($invoiceInfo),
                    'args' => [
                        'number' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        $admin = auth('admin')->user();
                        if (
                            ! $admin instanceof Admin
                            || (! $admin->hasPermission('orders.documents') && ! $admin->hasPermission('orders.manage'))
                        ) {
                            throw new UserError('Forbidden.');
                        }
                        if (! DocumentCommerceSettings::invoicesEnabled()) {
                            throw new UserError(InvoiceDocument::DISABLED_MESSAGE);
                        }
                        $order = Order::query()->where('number', $args['number'])->first();
                        if (! $order) {
                            throw new UserError('Order not found.');
                        }
                        DocumentCommerceSettings::formatInvoiceNumber($order);
                        $order->refresh();

                        return self::mapInvoiceInfo($order, true);
                    },
                ],
                'createInvoice' => [
                    'type' => Type::nonNull($invoiceInfo),
                    'args' => [
                        'number' => Type::nonNull(Type::string()),
                        'withHtml' => ['type' => Type::boolean(), 'defaultValue' => true],
                        'canCreateTransaction' => ['type' => Type::boolean(), 'defaultValue' => false],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminInvoice($args);
                    },
                ],
                'createAdminInvoice' => [
                    'type' => Type::nonNull($invoiceInfo),
                    'args' => [
                        'number' => Type::nonNull(Type::string()),
                        'withHtml' => ['type' => Type::boolean(), 'defaultValue' => true],
                        'canCreateTransaction' => ['type' => Type::boolean(), 'defaultValue' => false],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminInvoice($args);
                    },
                ],
                'createAdminSalesInvoice' => [
                    'type' => Type::nonNull($invoiceInfo),
                    'args' => [
                        'number' => Type::nonNull(Type::string()),
                        'withHtml' => ['type' => Type::boolean(), 'defaultValue' => true],
                        'canCreateTransaction' => ['type' => Type::boolean(), 'defaultValue' => false],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminInvoice($args);
                    },
                ],
                'createAdminInvoiceBulkUpdateStatus' => [
                    'type' => Type::nonNull($bulkResult),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                        'status' => Type::string(),
                        'value' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkUpdateAdminInvoiceStatus($args);
                    },
                ],
                'createAdminInvoiceSendDuplicate' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'orderNumber' => Type::string(),
                        'invoiceNumber' => Type::string(),
                        'email' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::sendDuplicateAdminInvoice($args);
                    },
                ],
                'createRefund' => [
                    'type' => Type::nonNull($order),
                    'args' => [
                        'number' => Type::nonNull(Type::string()),
                        'amount' => Type::nonNull(Type::float()),
                        'comment' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminRefund($args);
                    },
                ],
                'createAdminRefund' => [
                    'type' => Type::nonNull($order),
                    'args' => [
                        'number' => Type::nonNull(Type::string()),
                        'amount' => Type::nonNull(Type::float()),
                        'comment' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminRefund($args);
                    },
                ],
                'createAdminSalesRefund' => [
                    'type' => Type::nonNull($order),
                    'args' => [
                        'number' => Type::nonNull(Type::string()),
                        'amount' => Type::nonNull(Type::float()),
                        'comment' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminRefund($args);
                    },
                ],
                'setProductEnabled' => [
                    'type' => Type::nonNull($product),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'enabled' => Type::nonNull(Type::boolean()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCatalogProducts();
                        $row = Product::query()->find((int) $args['id']);
                        if (! $row) {
                            throw new UserError('Product not found.');
                        }
                        $row->is_enabled = (bool) $args['enabled'];
                        $row->save();

                        return self::mapAdminProduct($row);
                    },
                ],
                'updateProductPrice' => [
                    'type' => Type::nonNull($product),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'price' => Type::nonNull(Type::float()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCatalogProducts();
                        $row = Product::query()->find((int) $args['id']);
                        if (! $row) {
                            throw new UserError('Product not found.');
                        }
                        $price = (float) $args['price'];
                        if ($price < 0) {
                            throw new UserError('Price must be zero or greater.');
                        }
                        $row->price = $price;
                        $row->save();

                        return self::mapAdminProduct($row);
                    },
                ],
                // Admin REST docs → updateProductPrices
                'updateProductPrices' => [
                    'type' => Type::nonNull($adminProductMutationResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'UpdateProductPricesInput',
                            'fields' => [
                                'productId' => Type::nonNull(Type::id()),
                                'prices' => Type::nonNull(Type::listOf(Type::nonNull(new InputObjectType([
                                    'name' => 'UpdateProductPriceRowInput',
                                    'fields' => [
                                        'storeId' => Type::string(),
                                        'price' => Type::float(),
                                        'cost' => Type::float(),
                                        'specialPrice' => Type::float(),
                                        'specialPriceFrom' => Type::string(),
                                        'specialPriceTo' => Type::string(),
                                    ],
                                ])))),
                            ],
                        ]),
                        'productId' => Type::id(),
                        'price' => Type::float(),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCatalogProducts();
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        $productId = (int) ($input['productId'] ?? $args['productId'] ?? 0);
                        $row = Product::query()->find($productId);
                        if (! $row) {
                            throw new UserError('Product not found.');
                        }
                        $prices = is_array($input['prices'] ?? null) ? $input['prices'] : [];
                        $first = $prices[0] ?? null;
                        if (is_array($first)) {
                            if (isset($first['price'])) {
                                $price = (float) $first['price'];
                                if ($price < 0) {
                                    throw new UserError('Price must be zero or greater.');
                                }
                                $row->price = $price;
                            }
                            if (isset($first['cost'])) {
                                $row->cost_price = (float) $first['cost'];
                            }
                            if (isset($first['specialPrice'])) {
                                $row->sale_price = (float) $first['specialPrice'];
                            }
                            if (! empty($first['specialPriceFrom'])) {
                                $row->sale_starts_at = (string) $first['specialPriceFrom'];
                            }
                            if (! empty($first['specialPriceTo'])) {
                                $row->sale_ends_at = (string) $first['specialPriceTo'];
                            }
                        } elseif (isset($args['price'])) {
                            $row->price = (float) $args['price'];
                        }
                        $row->save();

                        return ['product' => self::mapAdminProduct($row->fresh())];
                    },
                ],
                'updateProductStock' => [
                    'type' => Type::nonNull($product),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'qty' => Type::nonNull(Type::float()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCatalogProducts();
                        $row = Product::query()->find((int) $args['id']);
                        if (! $row) {
                            throw new UserError('Product not found.');
                        }
                        $qty = (float) $args['qty'];
                        if ($qty < 0) {
                            throw new UserError('Stock quantity must be zero or greater.');
                        }
                        $row->stock_qty = $qty;
                        $row->save();

                        return self::mapAdminProduct($row);
                    },
                ],
                'updateInventory' => [
                    'type' => Type::nonNull($inventoryRow),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                        'warehouseId' => Type::nonNull(Type::id()),
                        'qty' => Type::nonNull(Type::float()),
                        'reservedQty' => ['type' => Type::float(), 'defaultValue' => 0],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateAdminProductInventory($args);
                    },
                ],
                'updateAdminCatalogProductInventory' => [
                    'type' => Type::nonNull($inventoryRow),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                        'warehouseId' => Type::nonNull(Type::id()),
                        'qty' => Type::nonNull(Type::float()),
                        'reservedQty' => ['type' => Type::float(), 'defaultValue' => 0],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateAdminProductInventory($args);
                    },
                ],
                'createWarehouse' => [
                    'type' => Type::nonNull($warehouse),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'code' => Type::nonNull(Type::string()),
                        'email' => Type::nonNull(Type::string()),
                        'address' => Type::nonNull(Type::string()),
                        'city' => Type::nonNull(Type::string()),
                        'postcode' => Type::nonNull(Type::string()),
                        'country' => Type::nonNull(Type::string()),
                        'state' => Type::string(),
                        'phone' => Type::string(),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                        'isDefault' => ['type' => Type::boolean(), 'defaultValue' => false],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminWarehouse($args);
                    },
                ],
                'createAdminSettingsWarehouse' => [
                    'type' => Type::nonNull($warehouse),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'code' => Type::nonNull(Type::string()),
                        'email' => Type::nonNull(Type::string()),
                        'address' => Type::nonNull(Type::string()),
                        'city' => Type::nonNull(Type::string()),
                        'postcode' => Type::nonNull(Type::string()),
                        'country' => Type::nonNull(Type::string()),
                        'state' => Type::string(),
                        'phone' => Type::string(),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                        'isDefault' => ['type' => Type::boolean(), 'defaultValue' => false],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminWarehouse($args);
                    },
                ],
                'updateWarehouse' => [
                    'type' => Type::nonNull($warehouse),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'name' => Type::string(),
                        'email' => Type::string(),
                        'address' => Type::string(),
                        'city' => Type::string(),
                        'postcode' => Type::string(),
                        'country' => Type::string(),
                        'state' => Type::string(),
                        'phone' => Type::string(),
                        'enabled' => Type::boolean(),
                        'isDefault' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateAdminWarehouse($args);
                    },
                ],
                'updateAdminSettingsWarehouse' => [
                    'type' => Type::nonNull($warehouse),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'name' => Type::string(),
                        'email' => Type::string(),
                        'address' => Type::string(),
                        'city' => Type::string(),
                        'postcode' => Type::string(),
                        'country' => Type::string(),
                        'state' => Type::string(),
                        'phone' => Type::string(),
                        'enabled' => Type::boolean(),
                        'isDefault' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateAdminWarehouse($args);
                    },
                ],
                'deleteWarehouse' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminWarehouse($args);
                    },
                ],
                'deleteAdminSettingsWarehouse' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminWarehouse($args);
                    },
                ],
                'bulkDeleteAdminSettingsWarehouses' => [
                    'type' => Type::nonNull($bulkResult),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminWarehouses($args);
                    },
                ],
                'updateConfiguration' => [
                    'type' => Type::nonNull($configUpdateResult),
                    'args' => [
                        'storeName' => Type::string(),
                        'storeEmail' => Type::string(),
                        'storePhone' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $admin = auth('admin')->user();
                        if (! $admin instanceof Admin) {
                            throw new UserError('Forbidden.');
                        }
                        $store = app(StoreContext::class)->store() ?? Store::query()->where('is_default', true)->first();
                        if (! $store) {
                            throw new UserError('No store is available.');
                        }
                        $input = [];
                        if (array_key_exists('storeName', $args) && trim((string) $args['storeName']) !== '') {
                            $name = trim((string) $args['storeName']);
                            $input['shop_name'] = $name;
                            $input['names'] = [Language::defaultCode() => $name];
                        }
                        if (array_key_exists('storeEmail', $args)) {
                            $input['email'] = trim((string) $args['storeEmail']);
                        }
                        if (array_key_exists('storePhone', $args)) {
                            $input['phone'] = trim((string) $args['storePhone']);
                        }
                        if ($input === []) {
                            throw new UserError('Provide at least one configuration field to update.');
                        }
                        ShopIdentity::save($store, $input);
                        $identity = ShopIdentity::forStorefront($store->fresh(), app()->getLocale());

                        return [
                            'ok' => true,
                            'message' => 'Configuration updated.',
                            'configuration' => [
                                'store_name' => $identity['name'] ?? null,
                                'store_email' => $identity['email'] ?? null,
                                'store_phone' => $identity['phone'] ?? null,
                                'currency' => (string) (config('wento.default_currency') ?: 'USD'),
                                'locale' => app()->getLocale(),
                            ],
                        ];
                    },
                ],
                'createSimpleProduct' => [
                    'type' => Type::nonNull($product),
                    'args' => [
                        'sku' => Type::nonNull(Type::string()),
                        'name' => Type::nonNull(Type::string()),
                        'price' => Type::nonNull(Type::float()),
                        'stockQty' => ['type' => Type::float(), 'defaultValue' => 0],
                        'categoryId' => Type::id(),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                        'shortDescription' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createSimpleProduct($args);
                    },
                ],
                'createAdminCatalogProduct' => [
                    'type' => Type::nonNull($product),
                    'args' => [
                        'sku' => Type::nonNull(Type::string()),
                        'name' => Type::nonNull(Type::string()),
                        'price' => Type::nonNull(Type::float()),
                        'stockQty' => ['type' => Type::float(), 'defaultValue' => 0],
                        'categoryId' => Type::id(),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                        'shortDescription' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createSimpleProduct($args);
                    },
                ],
                // Admin REST docs → createProduct { product { … } }
                'createProduct' => [
                    'type' => Type::nonNull(new ObjectType([
                        'name' => 'CreateProductMutationResult',
                        'fields' => [
                            'product' => Type::nonNull($product),
                        ],
                    ])),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'CreateProductInput',
                            'fields' => [
                                'sku' => Type::nonNull(Type::string()),
                                'name' => Type::nonNull(Type::string()),
                                'price' => Type::nonNull(Type::float()),
                                'stockQty' => Type::float(),
                                'categoryId' => Type::id(),
                                'enabled' => Type::boolean(),
                                'shortDescription' => Type::string(),
                                'type' => Type::string(),
                                'description' => Type::string(),
                                'weight' => Type::float(),
                                'status' => Type::boolean(),
                                'storeId' => Type::string(),
                            ],
                        ]),
                        'sku' => Type::string(),
                        'name' => Type::string(),
                        'price' => Type::float(),
                        'stockQty' => Type::float(),
                        'categoryId' => Type::id(),
                        'enabled' => Type::boolean(),
                        'shortDescription' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        $merged = array_merge($args, $input);
                        if (array_key_exists('status', $merged) && ! array_key_exists('enabled', $merged)) {
                            $merged['enabled'] = (bool) $merged['status'];
                        }
                        if (empty($merged['sku']) || empty($merged['name']) || ! isset($merged['price'])) {
                            throw new UserError('sku, name, and price are required.');
                        }

                        return ['product' => self::createSimpleProduct($merged)];
                    },
                ],
                'deleteProduct' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminCatalogProductMutation($args);
                    },
                ],
                'deleteAdminCatalogProduct' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminCatalogProductMutation($args);
                    },
                ],
                'createCategory' => [
                    'type' => Type::nonNull($category),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'slug' => Type::string(),
                        'parentId' => Type::id(),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                        'description' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createCategory($args);
                    },
                ],
                'createAdminCategory' => [
                    'type' => Type::nonNull($category),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'slug' => Type::string(),
                        'parentId' => Type::id(),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                        'description' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createCategory($args);
                    },
                ],
                'createAdminCatalogCategory' => [
                    'type' => Type::nonNull($category),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'slug' => Type::string(),
                        'parentId' => Type::id(),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                        'description' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createCategory($args);
                    },
                ],
                'updateCategory' => [
                    'type' => Type::nonNull($category),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'name' => Type::string(),
                        'slug' => Type::string(),
                        'enabled' => Type::boolean(),
                        'description' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateCategory($args);
                    },
                ],
                'updateAdminCategory' => [
                    'type' => Type::nonNull($category),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'name' => Type::string(),
                        'slug' => Type::string(),
                        'enabled' => Type::boolean(),
                        'description' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateCategory($args);
                    },
                ],
                'createCustomer' => [
                    'type' => Type::nonNull($customer),
                    'args' => [
                        'email' => Type::nonNull(Type::string()),
                        'firstName' => Type::nonNull(Type::string()),
                        'lastName' => Type::string(),
                        'phone' => Type::string(),
                        'password' => Type::string(),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminCustomerMutation($args);
                    },
                ],
                'createAdminCustomer' => [
                    'type' => Type::nonNull($customer),
                    'args' => [
                        'email' => Type::nonNull(Type::string()),
                        'firstName' => Type::nonNull(Type::string()),
                        'lastName' => Type::string(),
                        'phone' => Type::string(),
                        'password' => Type::string(),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminCustomerMutation($args);
                    },
                ],
                // Admin REST docs → adminCreateCustomer / adminUpdateCustomer / adminDeleteCustomer
                'adminCreateCustomer' => [
                    'type' => Type::nonNull($adminCustomerMutationResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'AdminCreateCustomerInput',
                            'fields' => [
                                'firstName' => Type::nonNull(Type::string()),
                                'lastName' => Type::string(),
                                'email' => Type::nonNull(Type::string()),
                                'password' => Type::string(),
                                'gender' => Type::string(),
                                'dateOfBirth' => Type::string(),
                                'phone' => Type::string(),
                                'status' => Type::boolean(),
                                'enabled' => Type::boolean(),
                            ],
                        ]),
                        'email' => Type::string(),
                        'firstName' => Type::string(),
                        'lastName' => Type::string(),
                        'phone' => Type::string(),
                        'password' => Type::string(),
                        'enabled' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        $merged = array_merge($args, $input);
                        if (array_key_exists('status', $merged) && ! array_key_exists('enabled', $merged)) {
                            $merged['enabled'] = (bool) $merged['status'];
                        }

                        return ['customer' => self::createAdminCustomerMutation($merged)];
                    },
                ],
                'adminUpdateCustomer' => [
                    'type' => Type::nonNull($adminCustomerMutationResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'AdminUpdateCustomerInput',
                            'fields' => [
                                'id' => Type::nonNull(Type::id()),
                                'firstName' => Type::string(),
                                'lastName' => Type::string(),
                                'email' => Type::string(),
                                'gender' => Type::string(),
                                'status' => Type::boolean(),
                                'enabled' => Type::boolean(),
                                'phone' => Type::string(),
                                'password' => Type::string(),
                            ],
                        ]),
                        'id' => Type::id(),
                        'email' => Type::string(),
                        'firstName' => Type::string(),
                        'lastName' => Type::string(),
                        'phone' => Type::string(),
                        'password' => Type::string(),
                        'enabled' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        $merged = array_merge($args, $input);
                        if (array_key_exists('status', $merged) && ! array_key_exists('enabled', $merged)) {
                            $merged['enabled'] = (bool) $merged['status'];
                        }
                        // Reuse updateAdminCustomer field resolve body via shared helper path
                        $admin = auth('admin')->user();
                        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
                            throw new UserError('Forbidden.');
                        }
                        $row = Customer::query()->find((int) ($merged['id'] ?? 0));
                        if (! $row) {
                            throw new UserError('Customer not found.');
                        }
                        $payload = [
                            'email' => isset($merged['email']) ? strtolower(trim((string) $merged['email'])) : $row->email,
                            'first_name' => isset($merged['firstName'])
                                ? trim((string) $merged['firstName'])
                                : (string) ($row->first_name ?? ''),
                            'last_name' => isset($merged['lastName'])
                                ? trim((string) $merged['lastName'])
                                : (string) ($row->last_name ?? ''),
                            'phone' => array_key_exists('phone', $merged)
                                ? (trim((string) $merged['phone']) ?: null)
                                : $row->phone,
                            'is_enabled' => array_key_exists('enabled', $merged)
                                ? (bool) $merged['enabled']
                                : (bool) $row->is_enabled,
                        ];
                        if (! empty($merged['password'])) {
                            $payload['password'] = (string) $merged['password'];
                        }
                        try {
                            $row = app(CustomerService::class)->save($payload, $row);
                        } catch (ValidationException $e) {
                            $messages = collect($e->errors())->flatten()->filter()->values()->all();
                            throw new UserError($messages[0] ?? 'Customer could not be updated.');
                        } catch (\Throwable $e) {
                            throw new UserError($e->getMessage());
                        }

                        return ['customer' => self::mapAdminCustomerRow($row)];
                    },
                ],
                'adminDeleteCustomer' => [
                    'type' => Type::nonNull($adminStatusMessageResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'AdminDeleteCustomerInput',
                            'fields' => [
                                'id' => Type::nonNull(Type::id()),
                            ],
                        ]),
                        'id' => Type::id(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        $id = (int) ($input['id'] ?? $args['id'] ?? 0);
                        $admin = auth('admin')->user();
                        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
                            throw new UserError('Forbidden.');
                        }
                        $row = Customer::query()->find($id);
                        if (! $row) {
                            throw new UserError('Customer not found.');
                        }
                        try {
                            wento_action('customer.delete', 'before', ['id' => $row->id]);
                            $anonymizer = app(CustomerAnonymizer::class);
                            $anonymizer->scrubPersonalRecords($row);
                            $anonymizer->purgeUnlinkedPersonalData($row);
                            $row->delete();
                            app(CustomerService::class)->bumpListCache();
                            wento_action('customer.delete', 'after', ['id' => $id]);
                        } catch (\Throwable $e) {
                            throw new UserError($e->getMessage());
                        }

                        return [
                            'status' => true,
                            'ok' => true,
                            'id' => (string) $id,
                            'message' => 'Customer deleted.',
                        ];
                    },
                ],
                'deleteCustomer' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        $admin = auth('admin')->user();
                        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
                            throw new UserError('Forbidden.');
                        }
                        $row = Customer::query()->find((int) $args['id']);
                        if (! $row) {
                            throw new UserError('Customer not found.');
                        }
                        $id = (string) $row->id;
                        try {
                            wento_action('customer.delete', 'before', ['id' => $row->id]);
                            $anonymizer = app(CustomerAnonymizer::class);
                            $anonymizer->scrubPersonalRecords($row);
                            $anonymizer->purgeUnlinkedPersonalData($row);
                            $row->delete();
                            app(CustomerService::class)->bumpListCache();
                            wento_action('customer.delete', 'after', ['id' => (int) $id]);
                        } catch (\Throwable $e) {
                            throw new UserError($e->getMessage());
                        }

                        return ['ok' => true, 'id' => $id];
                    },
                ],
                'deleteAdminCustomer' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        $admin = auth('admin')->user();
                        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
                            throw new UserError('Forbidden.');
                        }
                        $row = Customer::query()->find((int) $args['id']);
                        if (! $row) {
                            throw new UserError('Customer not found.');
                        }
                        $id = (string) $row->id;
                        try {
                            wento_action('customer.delete', 'before', ['id' => $row->id]);
                            $anonymizer = app(CustomerAnonymizer::class);
                            $anonymizer->scrubPersonalRecords($row);
                            $anonymizer->purgeUnlinkedPersonalData($row);
                            $row->delete();
                            app(CustomerService::class)->bumpListCache();
                            wento_action('customer.delete', 'after', ['id' => (int) $id]);
                        } catch (\Throwable $e) {
                            throw new UserError($e->getMessage());
                        }

                        return ['ok' => true, 'id' => $id];
                    },
                ],
                'bulkDeleteAdminCustomer' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminCustomers($args);
                    },
                ],
                'bulkUpdateAdminCustomerStatus' => [
                    'type' => Type::nonNull($bulkResult),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                        'status' => Type::int(),
                        'value' => Type::int(),
                        'enabled' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkUpdateAdminCustomerStatus($args);
                    },
                ],
                'deleteCategory' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminCategoryMutation($args);
                    },
                ],
                'deleteAdminCategory' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminCategoryMutation($args);
                    },
                ],
                'bulkDeleteAdminCategory' => [
                    'type' => Type::nonNull($bulkResult),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminCategories($args);
                    },
                ],
                'bulkUpdateAdminCategoryStatus' => [
                    'type' => Type::nonNull($bulkResult),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                        'status' => Type::int(),
                        'value' => Type::int(),
                        'enabled' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkUpdateAdminCategoryStatus($args);
                    },
                ],
                'updateAdminCustomerReview' => [
                    'type' => Type::nonNull($customerReview),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'status' => Type::nonNull(Type::string()),
                        'rejectedReason' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateAdminCustomerReview($args);
                    },
                ],
                'deleteAdminCustomerReview' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCustomersManage();
                        $row = Review::query()->find((int) $args['id']);
                        if (! $row) {
                            throw new UserError('Review not found.');
                        }
                        $id = (string) $row->id;
                        $row->delete();

                        return ['ok' => true, 'id' => $id];
                    },
                ],
                'createCoupon' => [
                    'type' => Type::nonNull($promotion),
                    'args' => [
                        'code' => Type::nonNull(Type::string()),
                        'name' => Type::string(),
                        'action' => ['type' => Type::string(), 'defaultValue' => 'percent'],
                        'value' => Type::nonNull(Type::float()),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                        'minCart' => Type::float(),
                        'maxUses' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createCoupon($args);
                    },
                ],
                'createAdminCoupon' => [
                    'type' => Type::nonNull($promotion),
                    'args' => [
                        'code' => Type::nonNull(Type::string()),
                        'name' => Type::string(),
                        'action' => ['type' => Type::string(), 'defaultValue' => 'percent'],
                        'value' => Type::nonNull(Type::float()),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                        'minCart' => Type::float(),
                        'maxUses' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createCoupon($args);
                    },
                ],
                'createPromotion' => [
                    'type' => Type::nonNull($promotion),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'applyAs' => ['type' => Type::string(), 'defaultValue' => 'cart'],
                        'action' => ['type' => Type::string(), 'defaultValue' => 'percent'],
                        'value' => Type::nonNull(Type::float()),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                        'minCart' => Type::float(),
                        'priority' => ['type' => Type::int(), 'defaultValue' => 100],
                        'stopFurther' => ['type' => Type::boolean(), 'defaultValue' => false],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createPromotion($args);
                    },
                ],
                'createAdminPromotion' => [
                    'type' => Type::nonNull($promotion),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'applyAs' => ['type' => Type::string(), 'defaultValue' => 'cart'],
                        'action' => ['type' => Type::string(), 'defaultValue' => 'percent'],
                        'value' => Type::nonNull(Type::float()),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                        'minCart' => Type::float(),
                        'priority' => ['type' => Type::int(), 'defaultValue' => 100],
                        'stopFurther' => ['type' => Type::boolean(), 'defaultValue' => false],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createPromotion($args);
                    },
                ],
                'updatePromotion' => [
                    'type' => Type::nonNull($promotion),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'name' => Type::string(),
                        'action' => Type::string(),
                        'value' => Type::float(),
                        'enabled' => Type::boolean(),
                        'minCart' => Type::float(),
                        'priority' => Type::int(),
                        'stopFurther' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updatePromotion($args);
                    },
                ],
                'deletePromotion' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertMarketingDiscounts();
                        $row = DiscountRule::query()->find((int) $args['id']);
                        if (! $row) {
                            throw new UserError('Promotion not found.');
                        }
                        $id = (string) $row->id;
                        $row->delete();

                        return ['ok' => true, 'id' => $id];
                    },
                ],
                'createAdminMarketingCartDiscountRule' => [
                    'type' => Type::nonNull($promotion),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'applyAs' => ['type' => Type::string(), 'defaultValue' => 'cart'],
                        'action' => ['type' => Type::string(), 'defaultValue' => 'percent'],
                        'value' => Type::nonNull(Type::float()),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                        'minCart' => Type::float(),
                        'priority' => ['type' => Type::int(), 'defaultValue' => 100],
                        'stopFurther' => ['type' => Type::boolean(), 'defaultValue' => false],
                    ],
                    'resolve' => function ($root, array $args) {
                        $args['applyAs'] = 'cart';

                        return self::createPromotion($args);
                    },
                ],
                'createAdminMarketingCatalogDiscountRule' => [
                    'type' => Type::nonNull($promotion),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'applyAs' => ['type' => Type::string(), 'defaultValue' => 'catalog'],
                        'action' => ['type' => Type::string(), 'defaultValue' => 'percent'],
                        'value' => Type::nonNull(Type::float()),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                        'minCart' => Type::float(),
                        'priority' => ['type' => Type::int(), 'defaultValue' => 100],
                        'stopFurther' => ['type' => Type::boolean(), 'defaultValue' => false],
                    ],
                    'resolve' => function ($root, array $args) {
                        $args['applyAs'] = 'catalog';

                        return self::createPromotion($args);
                    },
                ],
                'bulkDeleteAdminMarketingCartDiscountRules' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminDiscountRules($args, 'cart');
                    },
                ],
                'bulkDeleteAdminMarketingCatalogDiscountRules' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminDiscountRules($args, 'catalog');
                    },
                ],
                'bulkDeleteAdminMarketingCartDiscountRuleCoupons' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminDiscountRules($args, 'coupon');
                    },
                ],
                'copyAdminMarketingCartDiscountRule' => [
                    'type' => Type::nonNull($promotion),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::copyAdminMarketingCartDiscountRule($args);
                    },
                ],
                'createAdminMarketingCartDiscountRuleCouponGenerate' => [
                    'type' => Type::nonNull($cartDiscountRuleCouponGenerateResult),
                    'args' => [
                        'discountRuleId' => Type::nonNull(Type::id()),
                        'length' => Type::nonNull(Type::int()),
                        'format' => Type::nonNull(Type::string()),
                        'prefix' => Type::string(),
                        'suffix' => Type::string(),
                        'couponQty' => Type::nonNull(Type::int()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminMarketingCartDiscountRuleCouponGenerate($args);
                    },
                ],
                'createAdminMarketingCartDiscountRuleCoupon' => [
                    'type' => Type::nonNull($cartDiscountCoupon),
                    'args' => [
                        'discountRuleId' => Type::id(),
                        'code' => Type::string(),
                        'usageLimit' => Type::int(),
                        'usagePerCustomer' => Type::int(),
                        'expiredAt' => Type::string(),
                        'input' => new InputObjectType([
                            'name' => 'CreateAdminMarketingCartDiscountRuleCouponInput',
                            'fields' => [
                                'discountRuleId' => Type::id(),
                                'code' => Type::string(),
                                'usageLimit' => Type::int(),
                                'usagePerCustomer' => Type::int(),
                                'expiredAt' => Type::string(),
                                'clientMutationId' => Type::string(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        unset($args['input']);

                        return self::createAdminMarketingCartDiscountRuleCoupon(array_merge($args, $input));
                    },
                ],
                'deleteAdminMarketingCartDiscountRuleCoupon' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminMarketingCartDiscountRuleCoupon($args);
                    },
                ],
                'createAttribute' => [
                    'type' => Type::nonNull($attribute),
                    'args' => [
                        'name' => Type::string(),
                        'adminName' => Type::string(),
                        'code' => Type::string(),
                        'type' => ['type' => Type::string(), 'defaultValue' => 'select'],
                        'filterable' => ['type' => Type::boolean(), 'defaultValue' => true],
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAttribute($args);
                    },
                ],
                // Admin REST docs → createAdminAttribute
                'createAdminAttribute' => [
                    'type' => Type::nonNull($attribute),
                    'args' => [
                        'name' => Type::string(),
                        'adminName' => Type::string(),
                        'code' => Type::string(),
                        'type' => Type::string(),
                        'filterable' => Type::boolean(),
                        'enabled' => Type::boolean(),
                        'input' => new InputObjectType([
                            'name' => 'CreateAdminAttributeInput',
                            'fields' => [
                                'name' => Type::string(),
                                'adminName' => Type::string(),
                                'code' => Type::string(),
                                'type' => Type::string(),
                                'filterable' => Type::boolean(),
                                'isFilterable' => Type::boolean(),
                                'enabled' => Type::boolean(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        unset($args['input']);
                        $merged = array_merge($args, $input);
                        if (! isset($merged['name']) && isset($merged['adminName'])) {
                            $merged['name'] = $merged['adminName'];
                        }
                        if (isset($merged['isFilterable']) && ! isset($merged['filterable'])) {
                            $merged['filterable'] = $merged['isFilterable'];
                        }

                        return self::createAttribute($merged);
                    },
                ],
                // Admin REST docs → updateAdminAttribute
                'updateAdminAttribute' => [
                    'type' => Type::nonNull($attribute),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'name' => Type::string(),
                        'adminName' => Type::string(),
                        'filterable' => Type::boolean(),
                        'enabled' => Type::boolean(),
                        'input' => new InputObjectType([
                            'name' => 'UpdateAdminAttributeInput',
                            'fields' => [
                                'name' => Type::string(),
                                'adminName' => Type::string(),
                                'filterable' => Type::boolean(),
                                'isFilterable' => Type::boolean(),
                                'enabled' => Type::boolean(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        unset($args['input']);

                        return self::updateAdminAttributeMutation(array_merge($args, $input));
                    },
                ],
                // Admin REST docs → deleteAdminAttribute
                'deleteAdminAttribute' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCatalogFeatures();
                        $row = Feature::query()->find((int) $args['id']);
                        if (! $row) {
                            throw new UserError('Attribute not found.');
                        }
                        $id = (string) $row->id;
                        try {
                            app(FeatureService::class)->deleteFeature($row);
                        } catch (\Throwable $e) {
                            throw new UserError($e->getMessage());
                        }

                        return ['ok' => true, 'id' => $id];
                    },
                ],
                'createAttributeOption' => [
                    'type' => Type::nonNull($attributeOption),
                    'args' => [
                        'attributeId' => Type::nonNull(Type::id()),
                        'label' => Type::nonNull(Type::string()),
                        'code' => Type::string(),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCatalogFeatures();
                        $feature = Feature::query()->find((int) $args['attributeId']);
                        if (! $feature) {
                            throw new UserError('Attribute not found.');
                        }
                        if (! in_array((string) $feature->type, ['select', 'multiselect', 'color', 'checkbox'], true)) {
                            throw new UserError('This attribute type does not support options.');
                        }
                        $label = trim((string) $args['label']);
                        if ($label === '') {
                            throw new UserError('Label is required.');
                        }
                        try {
                            $row = app(FeatureService::class)->saveValue([
                                'feature_id' => $feature->id,
                                'value' => $label,
                                'code' => isset($args['code']) ? trim((string) $args['code']) : '',
                                'is_enabled' => (bool) ($args['enabled'] ?? true),
                            ]);
                        } catch (ValidationException $e) {
                            $messages = collect($e->errors())->flatten()->filter()->values()->all();
                            throw new UserError($messages[0] ?? 'Option could not be created.');
                        } catch (\Throwable $e) {
                            throw new UserError($e->getMessage());
                        }

                        return [
                            'id' => (string) $row->id,
                            'attribute_id' => (string) $feature->id,
                            'code' => $row->code,
                            'label' => $label,
                            'is_enabled' => (bool) $row->is_enabled,
                        ];
                    },
                ],
                'createAdminCatalogAttributeOption' => [
                    'type' => Type::nonNull($attributeOption),
                    'args' => [
                        'attributeId' => Type::nonNull(Type::id()),
                        'label' => Type::nonNull(Type::string()),
                        'code' => Type::string(),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminAttributeOptionMutation($args);
                    },
                ],
                // Admin REST docs → createAdminAttributeOption
                'createAdminAttributeOption' => [
                    'type' => Type::nonNull($attributeOption),
                    'args' => [
                        'attributeId' => Type::nonNull(Type::id()),
                        'label' => Type::nonNull(Type::string()),
                        'code' => Type::string(),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminAttributeOptionMutation($args);
                    },
                ],
                // Admin REST docs → updateAdminAttributeOption
                'updateAdminAttributeOption' => [
                    'type' => Type::nonNull($attributeOption),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'label' => Type::string(),
                        'code' => Type::string(),
                        'enabled' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateAdminAttributeOptionMutation($args);
                    },
                ],
                'deleteAttributeOption' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminAttributeOptionMutation($args);
                    },
                ],
                // Admin REST docs → deleteAdminAttributeOption
                'deleteAdminAttributeOption' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminAttributeOptionMutation($args);
                    },
                ],
                'deleteAttribute' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCatalogFeatures();
                        $row = Feature::query()->find((int) $args['id']);
                        if (! $row) {
                            throw new UserError('Attribute not found.');
                        }
                        $id = (string) $row->id;
                        try {
                            app(FeatureService::class)->deleteFeature($row);
                        } catch (\Throwable $e) {
                            throw new UserError($e->getMessage());
                        }

                        return ['ok' => true, 'id' => $id];
                    },
                ],
                'bulkDeleteAdminCatalogAttribute' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminCatalogAttributes($args);
                    },
                ],
                // Admin REST docs → bulkDeleteAdminAttribute
                'bulkDeleteAdminAttribute' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminCatalogAttributes($args);
                    },
                ],
                'createAdminUser' => [
                    'type' => Type::nonNull($adminUser),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'email' => Type::nonNull(Type::string()),
                        'password' => Type::nonNull(Type::string()),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                        'super' => ['type' => Type::boolean(), 'defaultValue' => false],
                        'roleId' => Type::id(),
                        'status' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminSettingsUser($args);
                    },
                ],
                'createAdminSettingsUser' => [
                    'type' => Type::nonNull($adminUser),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'email' => Type::nonNull(Type::string()),
                        'password' => Type::nonNull(Type::string()),
                        'roleId' => Type::id(),
                        'status' => Type::int(),
                        'enabled' => Type::boolean(),
                        'super' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminSettingsUser($args);
                    },
                ],
                'updateAdminSettingsUser' => [
                    'type' => Type::nonNull($adminUser),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'name' => Type::string(),
                        'email' => Type::string(),
                        'password' => Type::string(),
                        'roleId' => Type::id(),
                        'status' => Type::int(),
                        'enabled' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateAdminSettingsUser($args);
                    },
                ],
                'deleteAdminSettingsUser' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminSettingsUser($args);
                    },
                ],
                'bulkDeleteAdminSettingsUser' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminSettingsUsers($args);
                    },
                ],
                'bulkSetProductEnabled' => [
                    'type' => Type::nonNull($bulkResult),
                    'args' => [
                        'ids' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                        'enabled' => Type::nonNull(Type::boolean()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCatalogProducts();
                        $ids = collect($args['ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
                        if ($ids === []) {
                            throw new UserError('At least one product id is required.');
                        }
                        $updated = Product::query()->whereIn('id', $ids)->update([
                            'is_enabled' => (bool) $args['enabled'],
                        ]);

                        return ['ok' => true, 'updated_count' => (int) $updated];
                    },
                ],
                'updateProduct' => [
                    'type' => Type::nonNull($product),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'name' => Type::string(),
                        'price' => Type::float(),
                        'stockQty' => Type::float(),
                        'enabled' => Type::boolean(),
                        'shortDescription' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateProduct($args);
                    },
                ],
                'updateAdminCatalogProduct' => [
                    'type' => Type::nonNull($product),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'name' => Type::string(),
                        'price' => Type::float(),
                        'stockQty' => Type::float(),
                        'enabled' => Type::boolean(),
                        'shortDescription' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateProduct($args);
                    },
                ],
                'addProductImage' => [
                    'type' => Type::nonNull($productImage),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                        'path' => Type::nonNull(Type::string()),
                        'alt' => Type::string(),
                        'cover' => ['type' => Type::boolean(), 'defaultValue' => false],
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCatalogProducts();
                        $product = Product::query()->find((int) $args['productId']);
                        if (! $product) {
                            throw new UserError('Product not found.');
                        }
                        $path = ltrim(trim((string) $args['path']), '/');
                        if ($path === '' || str_contains($path, '..')) {
                            throw new UserError('A valid storage path is required.');
                        }
                        if (! Storage::disk('public')->exists($path)) {
                            throw new UserError('Image path was not found on the public disk.');
                        }
                        $image = ProductImage::query()->create([
                            'product_id' => $product->id,
                            'path' => $path,
                            'alt' => isset($args['alt']) ? trim((string) $args['alt']) : null,
                            'position' => (int) $product->images()->max('position') + 1,
                        ]);
                        if (! empty($args['cover']) || ! $product->cover_path) {
                            $product->update(['cover_path' => $path]);
                        }

                        return [
                            'id' => (string) $image->id,
                            'product_id' => (string) $product->id,
                            'path' => $image->path,
                            'url' => $image->url,
                            'alt' => $image->alt,
                            'position' => (int) $image->position,
                        ];
                    },
                ],
                'deleteProductImage' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                        'imageId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminCatalogProductImageMutation($args);
                    },
                ],
                // Admin REST docs → deleteAdminCatalogProductImage
                'deleteAdminCatalogProductImage' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                        'id' => Type::id(),
                        'imageId' => Type::id(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminCatalogProductImageMutation($args);
                    },
                ],
                // Admin REST docs → updateAdminCatalogProductImage
                'updateAdminCatalogProductImage' => [
                    'type' => Type::nonNull($productImage),
                    'args' => [
                        'id' => Type::id(),
                        'productId' => Type::id(),
                        'imageId' => Type::id(),
                        'altText' => Type::string(),
                        'alt' => Type::string(),
                        'position' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateAdminCatalogProductImageMutation($args);
                    },
                ],
                'updateCustomer' => [
                    'type' => Type::nonNull($customer),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'email' => Type::string(),
                        'firstName' => Type::string(),
                        'lastName' => Type::string(),
                        'phone' => Type::string(),
                        'password' => Type::string(),
                        'enabled' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $admin = auth('admin')->user();
                        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
                            throw new UserError('Forbidden.');
                        }
                        $row = Customer::query()->find((int) $args['id']);
                        if (! $row) {
                            throw new UserError('Customer not found.');
                        }
                        $payload = [
                            'email' => isset($args['email']) ? strtolower(trim((string) $args['email'])) : $row->email,
                            'first_name' => isset($args['firstName'])
                                ? trim((string) $args['firstName'])
                                : (string) ($row->first_name ?? ''),
                            'last_name' => isset($args['lastName'])
                                ? trim((string) $args['lastName'])
                                : (string) ($row->last_name ?? ''),
                            'phone' => array_key_exists('phone', $args)
                                ? (isset($args['phone']) ? trim((string) $args['phone']) : null)
                                : $row->phone,
                            'is_enabled' => array_key_exists('enabled', $args)
                                ? (bool) $args['enabled']
                                : (bool) $row->is_enabled,
                        ];
                        if (isset($args['password']) && trim((string) $args['password']) !== '') {
                            $payload['password'] = (string) $args['password'];
                        }
                        try {
                            $row = app(CustomerService::class)->save($payload, $row);
                        } catch (ValidationException $e) {
                            $messages = collect($e->errors())->flatten()->filter()->values()->all();
                            throw new UserError($messages[0] ?? 'Customer could not be updated.');
                        } catch (\Throwable $e) {
                            throw new UserError($e->getMessage());
                        }

                        return [
                            'id' => (string) $row->id,
                            'email' => $row->email,
                            'name' => $row->displayName(),
                            'phone' => $row->phone,
                            'created_at' => optional($row->created_at)?->toIso8601String(),
                        ];
                    },
                ],
                'updateAdminCustomer' => [
                    'type' => Type::nonNull($customer),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'email' => Type::string(),
                        'firstName' => Type::string(),
                        'lastName' => Type::string(),
                        'phone' => Type::string(),
                        'password' => Type::string(),
                        'enabled' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $admin = auth('admin')->user();
                        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
                            throw new UserError('Forbidden.');
                        }
                        $row = Customer::query()->find((int) $args['id']);
                        if (! $row) {
                            throw new UserError('Customer not found.');
                        }
                        $payload = [
                            'email' => isset($args['email']) ? strtolower(trim((string) $args['email'])) : $row->email,
                            'first_name' => isset($args['firstName'])
                                ? trim((string) $args['firstName'])
                                : (string) ($row->first_name ?? ''),
                            'last_name' => isset($args['lastName'])
                                ? trim((string) $args['lastName'])
                                : (string) ($row->last_name ?? ''),
                            'phone' => array_key_exists('phone', $args)
                                ? (isset($args['phone']) ? trim((string) $args['phone']) : null)
                                : $row->phone,
                            'is_enabled' => array_key_exists('enabled', $args)
                                ? (bool) $args['enabled']
                                : (bool) $row->is_enabled,
                        ];
                        if (isset($args['password']) && trim((string) $args['password']) !== '') {
                            $payload['password'] = (string) $args['password'];
                        }
                        try {
                            $row = app(CustomerService::class)->save($payload, $row);
                        } catch (ValidationException $e) {
                            $messages = collect($e->errors())->flatten()->filter()->values()->all();
                            throw new UserError($messages[0] ?? 'Customer could not be updated.');
                        } catch (\Throwable $e) {
                            throw new UserError($e->getMessage());
                        }

                        return [
                            'id' => (string) $row->id,
                            'email' => $row->email,
                            'name' => $row->displayName(),
                            'phone' => $row->phone,
                            'created_at' => optional($row->created_at)?->toIso8601String(),
                        ];
                    },
                ],
                'createCustomerAddress' => [
                    'type' => Type::nonNull($customerAddress),
                    'args' => [
                        'customerId' => Type::nonNull(Type::id()),
                        'firstName' => Type::nonNull(Type::string()),
                        'lastName' => Type::string(),
                        'line1' => Type::nonNull(Type::string()),
                        'line2' => Type::string(),
                        'city' => Type::nonNull(Type::string()),
                        'state' => Type::string(),
                        'postalCode' => Type::string(),
                        'countryCode' => Type::nonNull(Type::string()),
                        'phone' => Type::string(),
                        'label' => Type::string(),
                        'defaultBilling' => ['type' => Type::boolean(), 'defaultValue' => false],
                        'defaultShipping' => ['type' => Type::boolean(), 'defaultValue' => false],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminCustomerAddress($args);
                    },
                ],
                // Admin REST API alias
                'adminAddCustomerAddress' => [
                    'type' => Type::nonNull($customerAddress),
                    'args' => [
                        'customerId' => Type::nonNull(Type::id()),
                        'firstName' => Type::nonNull(Type::string()),
                        'lastName' => Type::string(),
                        'line1' => Type::nonNull(Type::string()),
                        'line2' => Type::string(),
                        'city' => Type::nonNull(Type::string()),
                        'state' => Type::string(),
                        'postalCode' => Type::string(),
                        'countryCode' => Type::nonNull(Type::string()),
                        'phone' => Type::string(),
                        'label' => Type::string(),
                        'defaultBilling' => ['type' => Type::boolean(), 'defaultValue' => false],
                        'defaultShipping' => ['type' => Type::boolean(), 'defaultValue' => false],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminCustomerAddress($args);
                    },
                ],
                'impersonateCustomer' => [
                    'type' => Type::nonNull($adminImpersonateResult),
                    'args' => [
                        'customerId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminImpersonate::issue((int) $args['customerId']);
                    },
                ],
                'createAdminCustomerImpersonate' => [
                    'type' => Type::nonNull($adminImpersonateResult),
                    'args' => [
                        'customerId' => Type::id(),
                        'input' => new InputObjectType([
                            'name' => 'createAdminCustomerImpersonateInput',
                            'fields' => [
                                'customerId' => Type::nonNull(Type::id()),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        $customerId = (int) ($args['customerId'] ?? $input['customerId'] ?? 0);
                        if ($customerId < 1) {
                            throw new UserError('customerId is required.');
                        }

                        return GraphqlAdminImpersonate::issue($customerId);
                    },
                ],
                'createAdminCustomerNote' => [
                    'type' => Type::nonNull($adminCustomerNote),
                    'args' => [
                        'customerId' => Type::nonNull(Type::id()),
                        'note' => Type::nonNull(Type::string()),
                        'customerNotified' => ['type' => Type::boolean(), 'defaultValue' => false],
                        'input' => new InputObjectType([
                            'name' => 'createAdminCustomerNoteInput',
                            'fields' => [
                                'customerId' => Type::nonNull(Type::id()),
                                'note' => Type::nonNull(Type::string()),
                                'customerNotified' => Type::boolean(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        $customerId = (int) ($args['customerId'] ?? $input['customerId'] ?? 0);
                        $note = (string) ($args['note'] ?? $input['note'] ?? '');
                        $notified = (bool) ($args['customerNotified'] ?? $input['customerNotified'] ?? false);

                        return GraphqlAdminCustomerNotes::create($customerId, $note, $notified);
                    },
                ],
                'createAdminCustomerGdprDownloadData' => [
                    'type' => Type::nonNull($adminGdprDownloadResult),
                    'args' => [
                        'customerId' => Type::id(),
                        'input' => new InputObjectType([
                            'name' => 'createAdminCustomerGdprDownloadDataInput',
                            'fields' => [
                                'customerId' => Type::nonNull(Type::id()),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        $customerId = (int) ($args['customerId'] ?? $input['customerId'] ?? 0);
                        if ($customerId < 1) {
                            throw new UserError('customerId is required.');
                        }
                        $export = GraphqlAdminGdprDownload::export($customerId);
                        // GraphQL `data` field is JSON-as-string (JSON scalar).
                        $export['data'] = $export['dataJson'];

                        return $export;
                    },
                ],
                'updateAdminCustomerGdprRequest' => [
                    'type' => Type::nonNull($adminGdprRequest),
                    'args' => [
                        'id' => Type::id(),
                        'status' => Type::string(),
                        'message' => Type::string(),
                        'input' => new InputObjectType([
                            'name' => 'updateAdminCustomerGdprRequestInput',
                            'fields' => [
                                'id' => Type::nonNull(Type::id()),
                                'status' => Type::nonNull(Type::string()),
                                'message' => Type::string(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        $id = (int) ($args['id'] ?? $input['id'] ?? 0);
                        $status = (string) ($args['status'] ?? $input['status'] ?? '');

                        return GraphqlAdminGdprRequests::update(
                            $id,
                            $status,
                            array_key_exists('message', $args)
                                ? (string) $args['message']
                                : (array_key_exists('message', $input) ? (string) $input['message'] : null),
                        );
                    },
                ],
                'createAdminCustomerGdprProcess' => [
                    'type' => Type::nonNull($adminGdprRequest),
                    'args' => [
                        'requestId' => Type::id(),
                        'input' => new InputObjectType([
                            'name' => 'createAdminCustomerGdprProcessInput',
                            'fields' => [
                                'requestId' => Type::nonNull(Type::id()),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        $requestId = (int) ($args['requestId'] ?? $input['requestId'] ?? 0);
                        if ($requestId < 1) {
                            throw new UserError('requestId is required.');
                        }

                        return GraphqlAdminGdprRequests::process($requestId);
                    },
                ],
                'deleteAdminCustomerGdprRequest' => [
                    'type' => Type::nonNull($adminGdprRequest),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminGdprRequests::delete((int) $args['id']);
                    },
                ],
                'adminCreateCustomerAddress' => [
                    'type' => Type::nonNull($adminCustomerAddressMutationResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'AdminCreateCustomerAddressInput',
                            'fields' => [
                                'customerId' => Type::nonNull(Type::id()),
                                'firstName' => Type::nonNull(Type::string()),
                                'lastName' => Type::string(),
                                'address' => Type::string(),
                                'line1' => Type::string(),
                                'line2' => Type::string(),
                                'city' => Type::nonNull(Type::string()),
                                'state' => Type::string(),
                                'zipCode' => Type::string(),
                                'postalCode' => Type::string(),
                                'country' => Type::string(),
                                'countryCode' => Type::string(),
                                'phoneNumber' => Type::string(),
                                'phone' => Type::string(),
                                'label' => Type::string(),
                                'defaultBilling' => Type::boolean(),
                                'defaultShipping' => Type::boolean(),
                            ],
                        ]),
                        'customerId' => Type::id(),
                        'firstName' => Type::string(),
                        'lastName' => Type::string(),
                        'line1' => Type::string(),
                        'city' => Type::string(),
                        'countryCode' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return ['address' => self::normalizeAndCreateAdminCustomerAddress($args)];
                    },
                ],
                // Admin REST Addresses menu name
                'createAdminCustomerAddress' => [
                    'type' => Type::nonNull($adminCustomerAddressMutationResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'CreateAdminCustomerAddressInput',
                            'fields' => [
                                'customerId' => Type::nonNull(Type::id()),
                                'firstName' => Type::nonNull(Type::string()),
                                'lastName' => Type::string(),
                                'address' => Type::string(),
                                'line1' => Type::string(),
                                'line2' => Type::string(),
                                'city' => Type::nonNull(Type::string()),
                                'state' => Type::string(),
                                'zipCode' => Type::string(),
                                'postalCode' => Type::string(),
                                'country' => Type::string(),
                                'countryCode' => Type::string(),
                                'phoneNumber' => Type::string(),
                                'phone' => Type::string(),
                                'label' => Type::string(),
                                'defaultBilling' => Type::boolean(),
                                'defaultShipping' => Type::boolean(),
                            ],
                        ]),
                        'customerId' => Type::id(),
                        'firstName' => Type::string(),
                        'lastName' => Type::string(),
                        'line1' => Type::string(),
                        'city' => Type::string(),
                        'countryCode' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return ['address' => self::normalizeAndCreateAdminCustomerAddress($args)];
                    },
                ],
                'updateAdminCustomerAddress' => [
                    'type' => Type::nonNull($adminCustomerAddressMutationResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'AdminUpdateCustomerAddressInput',
                            'fields' => [
                                'id' => Type::id(),
                                'addressId' => Type::id(),
                                'firstName' => Type::string(),
                                'lastName' => Type::string(),
                                'address' => Type::string(),
                                'line1' => Type::string(),
                                'line2' => Type::string(),
                                'city' => Type::string(),
                                'state' => Type::string(),
                                'zipCode' => Type::string(),
                                'postalCode' => Type::string(),
                                'country' => Type::string(),
                                'countryCode' => Type::string(),
                                'phoneNumber' => Type::string(),
                                'phone' => Type::string(),
                                'defaultBilling' => Type::boolean(),
                                'defaultShipping' => Type::boolean(),
                            ],
                        ]),
                        'id' => Type::id(),
                        'addressId' => Type::id(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];

                        return ['address' => self::updateAdminCustomerAddress(array_merge($args, $input))];
                    },
                ],
                'deleteAdminCustomerAddress' => [
                    'type' => Type::nonNull($adminStatusMessageResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'DeleteAdminCustomerAddressInput',
                            'fields' => [
                                'customerId' => Type::nonNull(Type::id()),
                                'id' => Type::nonNull(Type::id()),
                            ],
                        ]),
                        'customerId' => Type::id(),
                        'id' => Type::id(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        $merged = array_merge($args, $input);
                        $admin = auth('admin')->user();
                        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
                            throw new UserError('Forbidden.');
                        }
                        $customer = Customer::query()->find((int) ($merged['customerId'] ?? 0));
                        if (! $customer) {
                            throw new UserError('Customer not found.');
                        }
                        $addressId = (int) ($merged['id'] ?? 0);
                        $address = $customer->addresses()->whereKey($addressId)->first();
                        if (! $address) {
                            throw new UserError('Address not found.');
                        }
                        try {
                            app(CustomerService::class)->removeAddress($customer, $addressId);
                        } catch (\Throwable $e) {
                            throw new UserError($e->getMessage());
                        }

                        return [
                            'status' => true,
                            'ok' => true,
                            'id' => (string) $addressId,
                            'message' => 'Address deleted.',
                        ];
                    },
                ],
                'adminDeleteCustomerAddress' => [
                    'type' => Type::nonNull($adminStatusMessageResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'AdminDeleteCustomerAddressInput',
                            'fields' => [
                                'customerId' => Type::nonNull(Type::id()),
                                'id' => Type::nonNull(Type::id()),
                            ],
                        ]),
                        'customerId' => Type::id(),
                        'id' => Type::id(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        $merged = array_merge($args, $input);
                        $admin = auth('admin')->user();
                        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
                            throw new UserError('Forbidden.');
                        }
                        $customer = Customer::query()->find((int) ($merged['customerId'] ?? 0));
                        if (! $customer) {
                            throw new UserError('Customer not found.');
                        }
                        $addressId = (int) ($merged['id'] ?? 0);
                        $address = $customer->addresses()->whereKey($addressId)->first();
                        if (! $address) {
                            throw new UserError('Address not found.');
                        }
                        try {
                            app(CustomerService::class)->removeAddress($customer, $addressId);
                        } catch (\Throwable $e) {
                            throw new UserError($e->getMessage());
                        }

                        return [
                            'status' => true,
                            'ok' => true,
                            'id' => (string) $addressId,
                            'message' => 'Address deleted.',
                        ];
                    },
                ],
                'setDefaultAdminCustomerAddress' => [
                    'type' => Type::nonNull($adminCustomerAddressMutationResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'SetDefaultAdminCustomerAddressInput',
                            'fields' => [
                                'customerId' => Type::nonNull(Type::id()),
                                'id' => Type::nonNull(Type::id()),
                            ],
                        ]),
                        'customerId' => Type::id(),
                        'id' => Type::id(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        $merged = array_merge($args, $input);
                        $admin = auth('admin')->user();
                        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
                            throw new UserError('Forbidden.');
                        }
                        $customer = Customer::query()->find((int) ($merged['customerId'] ?? 0));
                        if (! $customer) {
                            throw new UserError('Customer not found.');
                        }
                        $row = $customer->addresses()->whereKey((int) ($merged['id'] ?? 0))->first();
                        if (! $row) {
                            throw new UserError('Address not found.');
                        }
                        $customer->addresses()->update([
                            'is_default_billing' => false,
                            'is_default_shipping' => false,
                        ]);
                        $row->forceFill([
                            'is_default_billing' => true,
                            'is_default_shipping' => true,
                        ])->save();

                        return ['address' => self::mapAdminCustomerAddress($row->fresh())];
                    },
                ],
                'deleteCustomerAddress' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'customerId' => Type::nonNull(Type::id()),
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        $admin = auth('admin')->user();
                        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
                            throw new UserError('Forbidden.');
                        }
                        $customer = Customer::query()->find((int) $args['customerId']);
                        if (! $customer) {
                            throw new UserError('Customer not found.');
                        }
                        $address = $customer->addresses()->whereKey((int) $args['id'])->first();
                        if (! $address) {
                            throw new UserError('Address not found.');
                        }
                        $id = (string) $address->id;
                        try {
                            app(CustomerService::class)->removeAddress($customer, (int) $address->id);
                        } catch (\Throwable $e) {
                            throw new UserError($e->getMessage());
                        }

                        return ['ok' => true, 'id' => $id];
                    },
                ],
                'createAdminRole' => [
                    'type' => Type::nonNull($adminRole),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'permissions' => Type::listOf(Type::nonNull(Type::string())),
                        'permissionType' => Type::string(),
                        'description' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminSettingsRole($args);
                    },
                ],
                'createAdminSettingsRole' => [
                    'type' => Type::nonNull($adminRole),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'permissions' => Type::listOf(Type::nonNull(Type::string())),
                        'permissionType' => Type::string(),
                        'description' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminSettingsRole($args);
                    },
                ],
                'updateAdminSettingsRole' => [
                    'type' => Type::nonNull($adminRole),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'name' => Type::string(),
                        'permissions' => Type::listOf(Type::nonNull(Type::string())),
                        'permissionType' => Type::string(),
                        'description' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateAdminSettingsRole($args);
                    },
                ],
                'deleteAdminSettingsRole' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminSettingsRole($args);
                    },
                ],
                'bulkDeleteAdminSettingsRole' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminSettingsRoles($args);
                    },
                ],
                'createAdminSettingsStore' => [
                    'type' => Type::nonNull($settingsChannel),
                    'args' => [
                        'code' => Type::string(),
                        'name' => Type::nonNull(Type::string()),
                        'hostname' => Type::string(),
                        'shopUrl' => Type::string(),
                        'theme' => Type::string(),
                        'email' => Type::string(),
                        'enabled' => Type::boolean(),
                        'status' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminSettingsStore($args);
                    },
                ],
                'updateAdminSettingsStore' => [
                    'type' => Type::nonNull($settingsChannel),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'code' => Type::string(),
                        'name' => Type::string(),
                        'hostname' => Type::string(),
                        'shopUrl' => Type::string(),
                        'theme' => Type::string(),
                        'email' => Type::string(),
                        'enabled' => Type::boolean(),
                        'status' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateAdminSettingsStore($args);
                    },
                ],
                'deleteAdminSettingsStore' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminSettingsStore($args);
                    },
                ],
                'updateAdminIntegrationModule' => [
                    'type' => Type::nonNull(new ObjectType([
                        'name' => 'AdminIntegrationModuleUpdate',
                        'fields' => [
                            'enabled' => Type::nonNull(Type::boolean()),
                            'oneTokenPerAdmin' => Type::nonNull(Type::boolean()),
                            'auditEnabled' => Type::nonNull(Type::boolean()),
                            'retentionDays' => Type::nonNull(Type::int()),
                        ],
                    ])),
                    'args' => [
                        'enabled' => Type::boolean(),
                        'oneTokenPerAdmin' => Type::boolean(),
                        'auditEnabled' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminIntegrationModule::update($args);
                    },
                ],
                'createIntegrationToken' => [
                    'type' => Type::nonNull($integrationToken),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'allowedIps' => Type::string(),
                        'rateLimitPerMinute' => Type::int(),
                        'rateLimitPerDay' => Type::int(),
                        'expiresAt' => Type::string(),
                        'neverExpires' => Type::boolean(),
                        'permissionMode' => Type::string(),
                        'permissions' => Type::listOf(Type::nonNull(Type::string())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminIntegrations::create($args);
                    },
                ],
                'updateIntegrationTokenAllowlist' => [
                    'type' => Type::nonNull($integrationToken),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'allowedIps' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminIntegrations::updateAllowlist((int) $args['id'], $args);
                    },
                ],
                'updateIntegrationTokenRateLimits' => [
                    'type' => Type::nonNull($integrationToken),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'rateLimitPerMinute' => Type::int(),
                        'rateLimitPerDay' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminIntegrations::updateRateLimits((int) $args['id'], $args);
                    },
                ],
                'revokeIntegrationToken' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminIntegrations::revoke((int) $args['id']);
                    },
                ],
                'regenerateIntegrationToken' => [
                    'type' => Type::nonNull($integrationToken),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminIntegrations::regenerate((int) $args['id']);
                    },
                ],
                'createStorefrontApiKey' => [
                    'type' => Type::nonNull($storefrontApiKey),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'rateLimit' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminStorefrontKeys::create($args);
                    },
                ],
                'revokeStorefrontApiKey' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminStorefrontKeys::revoke((int) $args['id']);
                    },
                ],
                'updateStorefrontApiKeyRequirement' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'required' => Type::nonNull(Type::boolean()),
                    ],
                    'resolve' => function ($root, array $args) {
                        $result = GraphqlAdminStorefrontKeys::updateRequirement((bool) $args['required']);

                        return ['ok' => $result['ok'], 'id' => $result['id']];
                    },
                ],
                'createCmsPage' => [
                    'type' => Type::nonNull($cmsPage),
                    'args' => [
                        'title' => Type::nonNull(Type::string()),
                        'slug' => Type::string(),
                        'body' => Type::string(),
                        'status' => ['type' => Type::string(), 'defaultValue' => 'published'],
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                        'visible' => ['type' => Type::boolean(), 'defaultValue' => true],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createCmsPage($args);
                    },
                ],
                'createAdminCmsPage' => [
                    'type' => Type::nonNull($cmsPage),
                    'args' => [
                        'title' => Type::nonNull(Type::string()),
                        'slug' => Type::string(),
                        'body' => Type::string(),
                        'status' => ['type' => Type::string(), 'defaultValue' => 'published'],
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                        'visible' => ['type' => Type::boolean(), 'defaultValue' => true],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createCmsPage($args);
                    },
                ],
                'deleteCmsPage' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminCmsPageMutation($args);
                    },
                ],
                'deleteAdminCmsPage' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminCmsPageMutation($args);
                    },
                ],
                'updateCmsPage' => [
                    'type' => Type::nonNull($cmsPage),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'title' => Type::string(),
                        'slug' => Type::string(),
                        'body' => Type::string(),
                        'status' => Type::string(),
                        'enabled' => Type::boolean(),
                        'visible' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateCmsPage($args);
                    },
                ],
                'updateAdminCmsPage' => [
                    'type' => Type::nonNull($cmsPage),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'title' => Type::string(),
                        'slug' => Type::string(),
                        'body' => Type::string(),
                        'status' => Type::string(),
                        'enabled' => Type::boolean(),
                        'visible' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateCmsPage($args);
                    },
                ],
                'bulkDeleteAdminCmsPage' => [
                    'type' => Type::nonNull($bulkResult),
                    'args' => [
                        'ids' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCmsManage();
                        $ids = collect($args['ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
                        if ($ids === []) {
                            throw new UserError('At least one CMS page id is required.');
                        }
                        $deleted = CmsPage::query()->whereIn('id', $ids)->delete();

                        return ['ok' => true, 'updated_count' => (int) $deleted];
                    },
                ],
                'createAdminSettingsTaxRate' => [
                    'type' => Type::nonNull($taxRateType),
                    'args' => [
                        'name' => Type::string(),
                        'identifier' => Type::string(),
                        'rate' => Type::float(),
                        'taxRate' => Type::float(),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminSettingsTaxRate($args);
                    },
                ],
                'updateAdminSettingsTaxRate' => [
                    'type' => Type::nonNull($taxRateType),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'name' => Type::string(),
                        'identifier' => Type::string(),
                        'rate' => Type::float(),
                        'taxRate' => Type::float(),
                        'enabled' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateAdminSettingsTaxRate($args);
                    },
                ],
                'deleteAdminSettingsTaxRate' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertTaxesManage();
                        $row = TaxRate::query()->find((int) $args['id']);
                        if (! $row) {
                            throw new UserError('Tax rate not found.');
                        }
                        if ($row->rules()->exists()) {
                            throw new UserError('Remove rules using this rate first.');
                        }
                        $id = (string) $row->id;
                        $row->delete();

                        return ['ok' => true, 'id' => $id];
                    },
                ],
                'createAdminSettingsTaxCategory' => [
                    'type' => Type::nonNull($taxCategoryType),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'code' => Type::string(),
                        'description' => Type::string(),
                        'taxrates' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminSettingsTaxCategory($args);
                    },
                ],
                'updateAdminSettingsTaxCategory' => [
                    'type' => Type::nonNull($taxCategoryType),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'name' => Type::string(),
                        'taxrates' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateAdminSettingsTaxCategory($args);
                    },
                ],
                'deleteAdminSettingsTaxCategory' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertTaxesManage();
                        $row = TaxClass::query()->find((int) $args['id']);
                        if (! $row) {
                            throw new UserError('Tax category not found.');
                        }
                        $id = (string) $row->id;
                        // Detach linked rules first (delete clears the category assignment set).
                        $row->rules()->delete();
                        $row->delete();

                        return ['ok' => true, 'id' => $id];
                    },
                ],
                'bulkDeleteAdminSettingsTaxRate' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminSettingsTaxRates($args);
                    },
                ],
                'bulkDeleteAdminSettingsTaxCategory' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminSettingsTaxCategories($args);
                    },
                ],
                'copyProduct' => [
                    'type' => Type::nonNull($product),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::copyAdminCatalogProduct($args);
                    },
                ],
                'createAdminCatalogProductCopy' => [
                    'type' => Type::nonNull($product),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::copyAdminCatalogProduct($args);
                    },
                ],
                'bulkDeleteProducts' => [
                    'type' => Type::nonNull($bulkResult),
                    'args' => [
                        'ids' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminCatalogProducts($args);
                    },
                ],
                'bulkDeleteAdminCatalogProduct' => [
                    'type' => Type::nonNull($bulkResult),
                    'args' => [
                        'ids' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminCatalogProducts($args);
                    },
                ],
                'bulkUpdateProductStatus' => [
                    'type' => Type::nonNull($bulkResult),
                    'args' => [
                        'ids' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                        'enabled' => Type::nonNull(Type::boolean()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkUpdateAdminCatalogProductStatus($args);
                    },
                ],
                // Admin REST → bulkUpdateProducts (status + weight in one call)
                'bulkUpdateProducts' => [
                    'type' => Type::nonNull($bulkUpdateProductsResult),
                    'args' => [
                        'productIds' => Type::listOf(Type::nonNull(Type::id())),
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                        'status' => Type::boolean(),
                        'enabled' => Type::boolean(),
                        'weight' => Type::float(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkUpdateProducts($args);
                    },
                ],
                'bulkUpdateProducts' => [
                    'type' => Type::nonNull($bulkUpdateProductsResult),
                    'args' => [
                        'productIds' => Type::listOf(Type::nonNull(Type::id())),
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                        'status' => Type::boolean(),
                        'enabled' => Type::boolean(),
                        'weight' => Type::float(),
                        'input' => new InputObjectType([
                            'name' => 'BulkUpdateProductsInput',
                            'fields' => [
                                'productIds' => Type::listOf(Type::nonNull(Type::id())),
                                'ids' => Type::listOf(Type::nonNull(Type::id())),
                                'status' => Type::boolean(),
                                'enabled' => Type::boolean(),
                                'weight' => Type::float(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];

                        return self::bulkUpdateProducts(array_merge($args, $input));
                    },
                ],
                'bulkUpdateAdminCatalogProductStatus' => [
                    'type' => Type::nonNull($bulkResult),
                    'args' => [
                        'ids' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                        'enabled' => Type::boolean(),
                        'value' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        if (! array_key_exists('enabled', $args) && array_key_exists('value', $args)) {
                            $args['enabled'] = ((int) $args['value']) === 1;
                        }
                        if (! array_key_exists('enabled', $args)) {
                            throw new UserError('enabled or value is required.');
                        }

                        return self::bulkUpdateAdminCatalogProductStatus($args);
                    },
                ],
                'bulkDeleteAdminCustomerReview' => [
                    'type' => Type::nonNull($bulkResult),
                    'args' => [
                        'ids' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCustomersManage();
                        $ids = collect($args['ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
                        if ($ids === []) {
                            throw new UserError('At least one review id is required.');
                        }
                        $deleted = Review::query()->whereIn('id', $ids)->delete();

                        return ['ok' => true, 'updated_count' => (int) $deleted];
                    },
                ],
                'bulkUpdateAdminCustomerReviewStatus' => [
                    'type' => Type::nonNull($bulkResult),
                    'args' => [
                        'ids' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                        'status' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCustomersManage();
                        $ids = collect($args['ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
                        if ($ids === []) {
                            throw new UserError('At least one review id is required.');
                        }
                        $status = strtolower(trim((string) $args['status']));
                        if ($status === 'disapproved') {
                            $status = Review::STATUS_REJECTED;
                        }
                        if (! in_array($status, [Review::STATUS_PENDING, Review::STATUS_APPROVED, Review::STATUS_REJECTED], true)) {
                            throw new UserError('Status must be pending, approved, or disapproved.');
                        }
                        $updated = 0;
                        foreach (Review::query()->whereIn('id', $ids)->get() as $row) {
                            if ($status === Review::STATUS_APPROVED) {
                                $row->approve();
                            } elseif ($status === Review::STATUS_REJECTED) {
                                $row->reject();
                            } else {
                                $row->pending();
                            }
                            $updated++;
                        }

                        return ['ok' => true, 'updated_count' => $updated];
                    },
                ],
                'createSearchSynonym' => [
                    'type' => Type::nonNull($searchSynonym),
                    'args' => [
                        'term' => Type::string(),
                        'name' => Type::string(),
                        'aliases' => Type::string(),
                        'terms' => Type::string(),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminMarketingSearchSynonym($args);
                    },
                ],
                'createAdminMarketingSearchSynonym' => [
                    'type' => Type::nonNull($searchSynonym),
                    'args' => [
                        'term' => Type::string(),
                        'name' => Type::string(),
                        'aliases' => Type::string(),
                        'terms' => Type::string(),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminMarketingSearchSynonym($args);
                    },
                ],
                'updateAdminMarketingSearchSynonym' => [
                    'type' => Type::nonNull($searchSynonym),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'term' => Type::string(),
                        'name' => Type::string(),
                        'aliases' => Type::string(),
                        'terms' => Type::string(),
                        'enabled' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateAdminMarketingSearchSynonym($args);
                    },
                ],
                'deleteSearchSynonym' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminMarketingSearchSynonym($args);
                    },
                ],
                'deleteAdminMarketingSearchSynonym' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminMarketingSearchSynonym($args);
                    },
                ],
                'bulkDeleteAdminMarketingSearchSynonym' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminMarketingSearchSynonyms($args);
                    },
                ],
                'createSearchRedirect' => [
                    'type' => Type::nonNull($searchRedirect),
                    'args' => [
                        'query' => Type::string(),
                        'requestPath' => Type::string(),
                        'term' => Type::string(),
                        'targetPath' => Type::string(),
                        'redirect_url' => Type::string(),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminMarketingUrlRewrite($args);
                    },
                ],
                'createAdminMarketingUrlRewrite' => [
                    'type' => Type::nonNull($searchRedirect),
                    'args' => [
                        'query' => Type::string(),
                        'requestPath' => Type::string(),
                        'term' => Type::string(),
                        'targetPath' => Type::string(),
                        'redirect_url' => Type::string(),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminMarketingUrlRewrite($args);
                    },
                ],
                'updateAdminMarketingUrlRewrite' => [
                    'type' => Type::nonNull($searchRedirect),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'query' => Type::string(),
                        'requestPath' => Type::string(),
                        'term' => Type::string(),
                        'targetPath' => Type::string(),
                        'redirect_url' => Type::string(),
                        'enabled' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateAdminMarketingUrlRewrite($args);
                    },
                ],
                'updateAdminMarketingSearchTerm' => [
                    'type' => Type::nonNull($searchRedirect),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'query' => Type::string(),
                        'term' => Type::string(),
                        'targetPath' => Type::string(),
                        'redirect_url' => Type::string(),
                        'enabled' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateAdminMarketingUrlRewrite($args);
                    },
                ],
                'deleteSearchRedirect' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminMarketingUrlRewrite($args);
                    },
                ],
                'deleteAdminMarketingUrlRewrite' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminMarketingUrlRewrite($args);
                    },
                ],
                'deleteAdminMarketingSearchTerm' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminMarketingUrlRewrite($args);
                    },
                ],
                'bulkDeleteAdminMarketingUrlRewrite' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminMarketingUrlRewrites($args);
                    },
                ],
                'bulkDeleteAdminMarketingSearchTerm' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminMarketingUrlRewrites($args);
                    },
                ],
                'reorderProductImages' => [
                    'type' => Type::nonNull($bulkResult),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                        'imageIds' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::reorderAdminCatalogProductImageMutation($args);
                    },
                ],
                // Admin REST docs → reorderAdminCatalogProductImage
                'reorderAdminCatalogProductImage' => [
                    'type' => Type::nonNull($bulkResult),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                        'imageIds' => Type::listOf(Type::nonNull(Type::id())),
                        'order' => Type::listOf(new InputObjectType([
                            'name' => 'ReorderAdminCatalogProductImageOrderInput',
                            'fields' => [
                                'id' => Type::nonNull(Type::id()),
                                'position' => Type::nonNull(Type::int()),
                            ],
                        ])),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::reorderAdminCatalogProductImageMutation($args);
                    },
                ],
                'updateProductSale' => [
                    'type' => Type::nonNull($product),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'salePrice' => Type::float(),
                        'salePriceType' => ['type' => Type::string(), 'defaultValue' => 'amount'],
                        'saleStartsAt' => Type::string(),
                        'saleEndsAt' => Type::string(),
                        'comparePrice' => Type::float(),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCatalogProducts();
                        $row = Product::query()->find((int) $args['id']);
                        if (! $row) {
                            throw new UserError('Product not found.');
                        }
                        $type = (string) ($args['salePriceType'] ?? 'amount');
                        if (! in_array($type, ['amount', 'percent'], true)) {
                            throw new UserError('salePriceType must be amount or percent.');
                        }
                        $updates = ['sale_price_type' => $type];
                        if (array_key_exists('salePrice', $args)) {
                            $sale = $args['salePrice'] !== null ? (float) $args['salePrice'] : null;
                            if ($sale !== null && $sale < 0) {
                                throw new UserError('salePrice must be zero or greater.');
                            }
                            if ($type === 'percent' && $sale !== null && $sale > 100) {
                                throw new UserError('Percent salePrice cannot exceed 100.');
                            }
                            $updates['sale_price'] = $sale;
                        }
                        if (array_key_exists('comparePrice', $args)) {
                            $compare = $args['comparePrice'] !== null ? (float) $args['comparePrice'] : null;
                            if ($compare !== null && $compare < 0) {
                                throw new UserError('comparePrice must be zero or greater.');
                            }
                            $updates['compare_price'] = $compare;
                        }
                        foreach (['saleStartsAt' => 'sale_starts_at', 'saleEndsAt' => 'sale_ends_at'] as $arg => $col) {
                            if (array_key_exists($arg, $args)) {
                                $raw = $args[$arg];
                                $updates[$col] = ($raw !== null && trim((string) $raw) !== '')
                                    ? trim((string) $raw)
                                    : null;
                            }
                        }
                        $row->forceFill($updates)->save();

                        return self::mapAdminProduct($row->fresh() ?? $row);
                    },
                ],
                'createProductQtyPrice' => [
                    'type' => Type::nonNull($productQtyPrice),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                        'minQty' => Type::nonNull(Type::float()),
                        'maxQty' => Type::float(),
                        'price' => Type::nonNull(Type::float()),
                        'position' => ['type' => Type::int(), 'defaultValue' => 0],
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCatalogProducts();
                        $product = Product::query()->find((int) $args['productId']);
                        if (! $product) {
                            throw new UserError('Product not found.');
                        }
                        $min = (float) $args['minQty'];
                        $price = (float) $args['price'];
                        if ($min <= 0) {
                            throw new UserError('minQty must be greater than zero.');
                        }
                        if ($price < 0) {
                            throw new UserError('price must be zero or greater.');
                        }
                        $max = array_key_exists('maxQty', $args) && $args['maxQty'] !== null
                            ? (float) $args['maxQty']
                            : null;
                        if ($max !== null && $max < $min) {
                            throw new UserError('maxQty must be greater than or equal to minQty.');
                        }
                        $row = ProductQtyPrice::query()->create([
                            'product_id' => $product->id,
                            'min_qty' => $min,
                            'max_qty' => $max,
                            'price' => $price,
                            'position' => (int) ($args['position'] ?? 0),
                        ]);

                        return self::mapProductQtyPrice($row);
                    },
                ],
                'updateProductQtyPrice' => [
                    'type' => Type::nonNull($productQtyPrice),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'minQty' => Type::float(),
                        'maxQty' => Type::float(),
                        'price' => Type::float(),
                        'position' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCatalogProducts();
                        $row = ProductQtyPrice::query()->find((int) $args['id']);
                        if (! $row) {
                            throw new UserError('Qty price not found.');
                        }
                        $updates = [];
                        if (array_key_exists('minQty', $args) && $args['minQty'] !== null) {
                            $min = (float) $args['minQty'];
                            if ($min <= 0) {
                                throw new UserError('minQty must be greater than zero.');
                            }
                            $updates['min_qty'] = $min;
                        }
                        if (array_key_exists('price', $args) && $args['price'] !== null) {
                            $price = (float) $args['price'];
                            if ($price < 0) {
                                throw new UserError('price must be zero or greater.');
                            }
                            $updates['price'] = $price;
                        }
                        if (array_key_exists('position', $args) && $args['position'] !== null) {
                            $updates['position'] = (int) $args['position'];
                        }
                        if (array_key_exists('maxQty', $args)) {
                            $max = $args['maxQty'] !== null ? (float) $args['maxQty'] : null;
                            $minCheck = (float) ($updates['min_qty'] ?? $row->min_qty);
                            if ($max !== null && $max < $minCheck) {
                                throw new UserError('maxQty must be greater than or equal to minQty.');
                            }
                            $updates['max_qty'] = $max;
                        }
                        if ($updates !== []) {
                            $row->forceFill($updates)->save();
                        }

                        return self::mapProductQtyPrice($row->fresh() ?? $row);
                    },
                ],
                'deleteProductQtyPrice' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCatalogProducts();
                        $row = ProductQtyPrice::query()->find((int) $args['id']);
                        if (! $row) {
                            throw new UserError('Qty price not found.');
                        }
                        $id = (string) $row->id;
                        $row->delete();

                        return ['ok' => true, 'id' => $id];
                    },
                ],
                'createCustomerGroupPrice' => [
                    'type' => Type::nonNull($productQtyPrice),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                        'qty' => Type::nonNull(Type::float()),
                        'valueType' => Type::nonNull(Type::string()),
                        'value' => Type::nonNull(Type::float()),
                        'customerGroupId' => Type::int(),
                        'position' => Type::int(),
                    ],
                    'resolve' => fn ($root, array $args) => self::createCustomerGroupPrice($args),
                ],
                'createAdminCatalogProductCustomerGroupPrice' => [
                    'type' => Type::nonNull($productQtyPrice),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                        'qty' => Type::nonNull(Type::float()),
                        'valueType' => Type::nonNull(Type::string()),
                        'value' => Type::nonNull(Type::float()),
                        'customerGroupId' => Type::int(),
                        'position' => Type::int(),
                    ],
                    'resolve' => fn ($root, array $args) => self::createCustomerGroupPrice($args),
                ],
                'deleteCustomerGroupPrice' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCatalogProducts();
                        $row = ProductQtyPrice::query()->find((int) $args['id']);
                        if (! $row) {
                            throw new UserError('Customer group price not found.');
                        }
                        $id = (string) $row->id;
                        $row->delete();

                        return ['ok' => true, 'id' => $id];
                    },
                ],
                'deleteAdminCatalogProductCustomerGroupPrice' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCatalogProducts();
                        $row = ProductQtyPrice::query()->find((int) $args['id']);
                        if (! $row) {
                            throw new UserError('Customer group price not found.');
                        }
                        $id = (string) $row->id;
                        $row->delete();

                        return ['ok' => true, 'id' => $id];
                    },
                ],
                // Admin REST docs → updateCustomerGroupPrice / updateAdminCatalogProductCustomerGroupPrice
                'updateCustomerGroupPrice' => [
                    'type' => Type::nonNull($productQtyPrice),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'qty' => Type::float(),
                        'valueType' => Type::string(),
                        'value' => Type::float(),
                        'position' => Type::int(),
                    ],
                    'resolve' => fn ($root, array $args) => self::updateCustomerGroupPrice($args),
                ],
                'updateAdminCatalogProductCustomerGroupPrice' => [
                    'type' => Type::nonNull($productQtyPrice),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'qty' => Type::float(),
                        'valueType' => Type::string(),
                        'value' => Type::float(),
                        'position' => Type::int(),
                    ],
                    'resolve' => fn ($root, array $args) => self::updateCustomerGroupPrice($args),
                ],
                'createCustomerGroup' => [
                    'type' => Type::nonNull($customerGroup),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'code' => Type::string(),
                        'discountPercent' => Type::float(),
                        'enabled' => ['type' => Type::boolean(), 'defaultValue' => true],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminCustomerGroupMutation($args);
                    },
                ],
                // Admin REST docs → createAdminCustomerGroup (flat args or input{})
                'createAdminCustomerGroup' => [
                    'type' => Type::nonNull($customerGroup),
                    'args' => [
                        'name' => Type::string(),
                        'code' => Type::string(),
                        'discountPercent' => Type::float(),
                        'enabled' => Type::boolean(),
                        'input' => new InputObjectType([
                            'name' => 'CreateAdminCustomerGroupInput',
                            'fields' => [
                                'name' => Type::string(),
                                'code' => Type::string(),
                                'discountPercent' => Type::float(),
                                'enabled' => Type::boolean(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        unset($args['input']);

                        return self::createAdminCustomerGroupMutation(array_merge($args, $input));
                    },
                ],
                'updateCustomerGroup' => [
                    'type' => Type::nonNull($customerGroup),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'name' => Type::string(),
                        'discountPercent' => Type::float(),
                        'enabled' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCustomerGroupsManage();
                        $group = CustomerGroup::query()->find((int) $args['id']);
                        if (! $group) {
                            throw new UserError('Customer group not found.');
                        }
                        $updates = [];
                        if (array_key_exists('name', $args) && $args['name'] !== null) {
                            $name = trim((string) $args['name']);
                            if ($name === '') {
                                throw new UserError('Name cannot be empty.');
                            }
                            $updates['name'] = $name;
                        }
                        if (array_key_exists('discountPercent', $args) && $args['discountPercent'] !== null) {
                            $updates['discount_percent'] = max(0, min(100, (float) $args['discountPercent']));
                        }
                        if (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
                            if ($group->is_system && ! (bool) $args['enabled']) {
                                throw new UserError('System customer groups cannot be disabled.');
                            }
                            $updates['is_enabled'] = (bool) $args['enabled'];
                        }
                        if ($updates !== []) {
                            $group->forceFill($updates)->save();
                        }

                        return self::mapCustomerGroup($group->fresh() ?? $group);
                    },
                ],
                'deleteCustomerGroup' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertCustomerGroupsManage();
                        $group = CustomerGroup::query()->find((int) $args['id']);
                        if (! $group) {
                            throw new UserError('Customer group not found.');
                        }
                        if ($group->is_system) {
                            throw new UserError('System customer groups cannot be deleted.');
                        }
                        if (Customer::query()->where('customer_group', $group->code)->exists()) {
                            throw new UserError('This group is assigned to customers and cannot be deleted.');
                        }
                        $id = (string) $group->id;
                        $group->delete();

                        return ['ok' => true, 'id' => $id];
                    },
                ],
                'bulkDeleteAdminCustomerGroup' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminCustomerGroups($args);
                    },
                ],
                'deleteNewsletterSubscriber' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminMarketingSubscriber($args);
                    },
                ],
                'deleteAdminMarketingSubscriber' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminMarketingSubscriber($args);
                    },
                ],
                'updateAdminMarketingSubscriber' => [
                    'type' => Type::nonNull($newsletterSubscriber),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'isSubscribed' => Type::nonNull(Type::boolean()),
                    ],
                    'resolve' => function ($root, array $args) {
                        self::assertNewsletterManage();
                        $row = NewsletterSubscriber::query()->find((int) $args['id']);
                        if (! $row) {
                            throw new UserError('Subscriber not found.');
                        }
                        $row->is_subscribed = (bool) $args['isSubscribed'];
                        $row->save();

                        return self::mapAdminMarketingSubscriber($row->fresh() ?? $row);
                    },
                ],
                'createAdminMarketingTemplate' => [
                    'type' => Type::nonNull($marketingTemplate),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'subject' => Type::nonNull(Type::string()),
                        'content' => Type::string(),
                        'body' => Type::string(),
                        'code' => Type::string(),
                        'status' => Type::string(),
                        'enabled' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminMarketingTemplate($args);
                    },
                ],
                'updateAdminMarketingTemplate' => [
                    'type' => Type::nonNull($marketingTemplate),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'name' => Type::string(),
                        'subject' => Type::string(),
                        'content' => Type::string(),
                        'body' => Type::string(),
                        'status' => Type::string(),
                        'enabled' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateAdminMarketingTemplate($args);
                    },
                ],
                'deleteAdminMarketingTemplate' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminMarketingTemplate($args);
                    },
                ],
                'createAdminMarketingCampaign' => [
                    'type' => Type::nonNull($marketingCampaign),
                    'args' => [
                        'name' => Type::string(),
                        'subject' => Type::string(),
                        'content' => Type::string(),
                        'html_body' => Type::string(),
                        'status' => Type::string(),
                        'marketingTemplateId' => Type::id(),
                        'marketingEventId' => Type::id(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminMarketingCampaign($args);
                    },
                ],
                'updateAdminMarketingCampaign' => [
                    'type' => Type::nonNull($marketingCampaign),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'name' => Type::string(),
                        'subject' => Type::string(),
                        'content' => Type::string(),
                        'html_body' => Type::string(),
                        'status' => Type::string(),
                        'marketingEventId' => Type::id(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateAdminMarketingCampaign($args);
                    },
                ],
                'deleteAdminMarketingCampaign' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminMarketingCampaign($args);
                    },
                ],
                // Ahead of docs: template / event bulk-delete
                'bulkDeleteAdminMarketingTemplate' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminMarketingTemplates($args);
                    },
                ],
                'bulkDeleteAdminMarketingEvent' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminMarketingEvents($args);
                    },
                ],
                'bulkDeleteAdminMarketingCampaign' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminMarketingCampaigns($args);
                    },
                ],
                'bulkDeleteAdminMarketingSubscriber' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminMarketingSubscribers($args);
                    },
                ],
                'createAdminMarketingCampaignSend' => [
                    'type' => Type::nonNull($marketingCampaignSendResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminMarketingCampaignSend($args);
                    },
                ],
                'createAdminMarketingEvent' => [
                    'type' => Type::nonNull($marketingEvent),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'description' => Type::nonNull(Type::string()),
                        'date' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminMarketingEvent($args);
                    },
                ],
                'updateAdminMarketingEvent' => [
                    'type' => Type::nonNull($marketingEvent),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'name' => Type::string(),
                        'description' => Type::string(),
                        'date' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateAdminMarketingEvent($args);
                    },
                ],
                'deleteAdminMarketingEvent' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminMarketingEvent($args);
                    },
                ],
                'createAdminSettingsReturnReason' => [
                    'type' => Type::nonNull($settingsReturnReason),
                    'args' => [
                        'code' => Type::string(),
                        'name' => Type::nonNull(Type::string()),
                        'status' => Type::int(),
                        'enabled' => Type::boolean(),
                        'position' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaReasons::create($args);
                    },
                ],
                'updateAdminSettingsReturnReason' => [
                    'type' => Type::nonNull($settingsReturnReason),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'name' => Type::string(),
                        'status' => Type::int(),
                        'enabled' => Type::boolean(),
                        'position' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaReasons::update((int) $args['id'], $args);
                    },
                ],
                'deleteAdminSettingsReturnReason' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaReasons::delete((int) $args['id']);
                    },
                ],
                'bulkDeleteAdminSettingsReturnReason' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaReasons::bulkDelete((array) ($args['ids'] ?? []));
                    },
                ],
                'createAdminSettingsReturnReasonBulkUpdateStatus' => [
                    'type' => Type::nonNull($bulkResult),
                    'args' => [
                        'ids' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                        'status' => Type::nonNull(Type::int()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaReasons::bulkUpdateStatus(
                            (array) ($args['ids'] ?? []),
                            (int) $args['status']
                        );
                    },
                ],
                'createAdminSettingsDataTransferImport' => [
                    'type' => Type::nonNull($dataTransferImport),
                    'args' => [
                        'code' => Type::nonNull(Type::string()),
                        'action' => ['type' => Type::string(), 'defaultValue' => 'append'],
                        'csvContent' => Type::nonNull(Type::string()),
                        'fileName' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminSettingsDataTransferImports::create($args);
                    },
                ],
                'validateAdminSettingsDataTransferImportValidate' => [
                    'type' => Type::nonNull($dataTransferValidateResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'importId' => Type::id(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminSettingsDataTransferImports::validate($args);
                    },
                ],
                'validateAdminSettingsDataTransferImport' => [
                    'type' => Type::nonNull($dataTransferValidateResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminSettingsDataTransferImports::validate($args);
                    },
                ],
                'startAdminSettingsDataTransferImport' => [
                    'type' => Type::nonNull($dataTransferActionResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminSettingsDataTransferImports::advance($args, 'processing');
                    },
                ],
                'linkAdminSettingsDataTransferImport' => [
                    'type' => Type::nonNull($dataTransferActionResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminSettingsDataTransferImports::advance($args, 'linked');
                    },
                ],
                'indexAdminSettingsDataTransferImport' => [
                    'type' => Type::nonNull($dataTransferActionResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminSettingsDataTransferImports::advance($args, 'indexed');
                    },
                ],
                'cancelAdminSettingsDataTransferImport' => [
                    'type' => Type::nonNull($dataTransferActionResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminSettingsDataTransferImports::cancel($args);
                    },
                ],
                'deleteAdminSettingsDataTransferImport' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminSettingsDataTransferImports::delete((int) $args['id']);
                    },
                ],
                'createAdminRmaStatus' => [
                    'type' => Type::nonNull($rmaStatus),
                    'args' => [
                        'title' => Type::nonNull(Type::string()),
                        'color' => Type::string(),
                        'status' => Type::int(),
                        'position' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaStatuses::create($args);
                    },
                ],
                'updateAdminRmaStatus' => [
                    'type' => Type::nonNull($rmaStatus),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'title' => Type::string(),
                        'color' => Type::string(),
                        'status' => Type::int(),
                        'position' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaStatuses::update((int) $args['id'], $args);
                    },
                ],
                'deleteAdminRmaStatus' => [
                    'type' => Type::nonNull($rmaStatus),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaStatuses::delete((int) $args['id']);
                    },
                ],
                'bulkDeleteAdminRmaStatus' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaStatuses::bulkDelete((array) ($args['ids'] ?? []));
                    },
                ],
                'createAdminRmaStatusBulkUpdateStatus' => [
                    'type' => Type::nonNull($bulkResult),
                    'args' => [
                        'ids' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                        'status' => Type::nonNull(Type::int()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaStatuses::bulkUpdateStatus(
                            (array) ($args['ids'] ?? []),
                            (int) $args['status']
                        );
                    },
                ],
                'createAdminRmaRule' => [
                    'type' => Type::nonNull($rmaRule),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'description' => Type::string(),
                        'returnPeriod' => Type::int(),
                        'status' => Type::int(),
                        'default' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaRules::create($args);
                    },
                ],
                'updateAdminRmaRule' => [
                    'type' => Type::nonNull($rmaRule),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'name' => Type::string(),
                        'description' => Type::string(),
                        'returnPeriod' => Type::int(),
                        'status' => Type::int(),
                        'default' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaRules::update((int) $args['id'], $args);
                    },
                ],
                'deleteAdminRmaRule' => [
                    'type' => Type::nonNull($rmaRule),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaRules::delete((int) $args['id']);
                    },
                ],
                'bulkDeleteAdminRmaRule' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaRules::bulkDelete((array) ($args['ids'] ?? []));
                    },
                ],
                'createAdminRmaRuleBulkUpdateStatus' => [
                    'type' => Type::nonNull($bulkResult),
                    'args' => [
                        'ids' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                        'status' => Type::nonNull(Type::int()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaRules::bulkUpdateStatus(
                            (array) ($args['ids'] ?? []),
                            (int) $args['status']
                        );
                    },
                ],
                'createAdminMarketingSitemap' => [
                    'type' => Type::nonNull($marketingSitemap),
                    'args' => [
                        'fileName' => Type::nonNull(Type::string()),
                        'path' => Type::nonNull(Type::string()),
                        'stores' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminMarketingSitemap($args);
                    },
                ],
                'updateAdminMarketingSitemap' => [
                    'type' => Type::nonNull($marketingSitemap),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'fileName' => Type::string(),
                        'path' => Type::string(),
                        'stores' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateAdminMarketingSitemap($args);
                    },
                ],
                'deleteAdminMarketingSitemap' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminMarketingSitemap($args);
                    },
                ],
                'createAdminMarketingSitemapGenerate' => [
                    'type' => Type::nonNull($sitemapGenerateResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::generateAdminMarketingSitemap($args);
                    },
                ],
                'createAdminRmaCustomField' => [
                    'type' => Type::nonNull($rmaCustomField),
                    'args' => [
                        'code' => Type::string(),
                        'label' => Type::nonNull(Type::string()),
                        'type' => ['type' => Type::string(), 'defaultValue' => 'text'],
                        'isRequired' => Type::int(),
                        'position' => Type::int(),
                        'inputValidation' => Type::string(),
                        'status' => Type::int(),
                        'optionsJson' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaCustomFields::create($args);
                    },
                ],
                'updateAdminRmaCustomField' => [
                    'type' => Type::nonNull($rmaCustomField),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'label' => Type::string(),
                        'type' => Type::string(),
                        'isRequired' => Type::int(),
                        'position' => Type::int(),
                        'inputValidation' => Type::string(),
                        'status' => Type::int(),
                        'optionsJson' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaCustomFields::update((int) $args['id'], $args);
                    },
                ],
                'deleteAdminRmaCustomField' => [
                    'type' => Type::nonNull($rmaCustomField),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaCustomFields::delete((int) $args['id']);
                    },
                ],
                'bulkDeleteAdminRmaCustomField' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaCustomFields::bulkDelete((array) ($args['ids'] ?? []));
                    },
                ],
                'createAdminRmaCustomFieldBulkUpdateStatus' => [
                    'type' => Type::nonNull($bulkResult),
                    'args' => [
                        'ids' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                        'status' => Type::nonNull(Type::int()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminRmaCustomFields::bulkUpdateStatus(
                            (array) ($args['ids'] ?? []),
                            (int) $args['status']
                        );
                    },
                ],
                'createAdminConfigurationUpdate' => [
                    'type' => Type::nonNull($configurationUpdateResult),
                    'args' => [
                        'slug' => Type::nonNull(Type::string()),
                        'valuesJson' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminConfigurationUpdate($args);
                    },
                ],
                'createAdminProductTemplate' => [
                    'type' => Type::nonNull($productTemplateType),
                    'args' => [
                        'code' => Type::nonNull(Type::string()),
                        'name' => Type::nonNull(Type::string()),
                        'templateGroupsJson' => Type::string(),
                        'isDefault' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminProductTemplate($args);
                    },
                ],
                'createAdminCatalogProductTemplate' => [
                    'type' => Type::nonNull($productTemplateType),
                    'args' => [
                        'code' => Type::nonNull(Type::string()),
                        'name' => Type::nonNull(Type::string()),
                        'templateGroupsJson' => Type::string(),
                        'isDefault' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminProductTemplate($args);
                    },
                ],
                'deleteAdminCatalogProductTemplate' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminProductTemplate($args);
                    },
                ],
                'updateAdminProductTemplate' => [
                    'type' => Type::nonNull($productTemplateType),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'code' => Type::string(),
                        'name' => Type::string(),
                        'templateGroupsJson' => Type::string(),
                        'isDefault' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateAdminProductTemplate($args);
                    },
                ],
                'deleteAdminProductTemplate' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminProductTemplate($args);
                    },
                ],

                'createAdminReturn' => [
                    'type' => Type::nonNull($adminReturn),
                    'args' => [
                        'orderId' => Type::nonNull(Type::id()),
                        'itemsJson' => Type::nonNull(Type::string()),
                        'reasonId' => Type::id(),
                        'resolution' => Type::string(),
                        'information' => Type::string(),
                        'reason' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminRmaGraphql::createReturn($args);
                    },
                ],
                'updateStatusAdminReturn' => [
                    'type' => Type::nonNull($adminReturn),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'statusId' => Type::nonNull(Type::string()),
                        'note' => Type::string(),
                        'shipping' => Type::float(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminRmaGraphql::updateStatus($args);
                    },
                ],
                'reopenAdminReturn' => [
                    'type' => Type::nonNull($adminReturn),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminRmaGraphql::reopen($args);
                    },
                ],
                'createAdminReturnMessage' => [
                    'type' => Type::nonNull($adminReturnMessage),
                    'args' => [
                        'returnId' => Type::nonNull(Type::id()),
                        'body' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminRmaGraphql::sendMessage($args);
                    },
                ],
                'declineAdminEuWithdrawal' => [
                    'type' => Type::nonNull($adminEuWithdrawal),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'reason' => Type::string(),
                        'note' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminEuWithdrawalGraphql::decline($args);
                    },
                ],
                'markRefundedAdminEuWithdrawal' => [
                    'type' => Type::nonNull($adminEuWithdrawal),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'note' => Type::string(),
                        'refundNote' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminEuWithdrawalGraphql::markRefunded($args);
                    },
                ],
                'resendConfirmationAdminEuWithdrawal' => [
                    'type' => Type::nonNull($adminEuWithdrawal),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return AdminEuWithdrawalGraphql::resendConfirmation($args);
                    },
                ],
                'createAdminSettingsExchangeRate' => [
                    'type' => Type::nonNull($settingsExchangeRate),
                    'args' => [
                        'targetCurrency' => Type::nonNull(Type::id()),
                        'rate' => Type::nonNull(Type::float()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminSettingsExchangeRate($args);
                    },
                ],
                'updateAdminSettingsExchangeRate' => [
                    'type' => Type::nonNull($settingsExchangeRate),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'rate' => Type::nonNull(Type::float()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateAdminSettingsExchangeRate($args);
                    },
                ],
                'deleteAdminSettingsExchangeRate' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminSettingsExchangeRate($args);
                    },
                ],
                'createAdminSettingsExchangeRateUpdateRates' => [
                    'type' => Type::nonNull($settingsExchangeRateUpdateResult),
                    'resolve' => function () {
                        return self::createAdminSettingsExchangeRateUpdateRates();
                    },
                ],
                'createAdminSettingsLocale' => [
                    'type' => Type::nonNull($settingsLocale),
                    'args' => [
                        'code' => Type::nonNull(Type::string()),
                        'name' => Type::nonNull(Type::string()),
                        'direction' => Type::string(),
                        'enabled' => Type::boolean(),
                        'status' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminSettingsLocale($args);
                    },
                ],
                'updateAdminSettingsLocale' => [
                    'type' => Type::nonNull($settingsLocale),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'name' => Type::string(),
                        'direction' => Type::string(),
                        'enabled' => Type::boolean(),
                        'status' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateAdminSettingsLocale($args);
                    },
                ],
                'deleteAdminSettingsLocale' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminSettingsLocale($args);
                    },
                ],
                'bulkDeleteAdminSettingsLocale' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminSettingsLocales($args);
                    },
                ],
                'createAdminSettingsCurrency' => [
                    'type' => Type::nonNull($settingsCurrency),
                    'args' => [
                        'code' => Type::nonNull(Type::string()),
                        'name' => Type::nonNull(Type::string()),
                        'symbol' => Type::string(),
                        'decimal' => Type::int(),
                        'currencyPosition' => Type::string(),
                        'exchange_rate' => Type::float(),
                        'enabled' => Type::boolean(),
                        'status' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createAdminSettingsCurrency($args);
                    },
                ],
                'updateAdminSettingsCurrency' => [
                    'type' => Type::nonNull($settingsCurrency),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'name' => Type::string(),
                        'symbol' => Type::string(),
                        'decimal' => Type::int(),
                        'currencyPosition' => Type::string(),
                        'exchange_rate' => Type::float(),
                        'enabled' => Type::boolean(),
                        'status' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::updateAdminSettingsCurrency($args);
                    },
                ],
                'deleteAdminSettingsCurrency' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::deleteAdminSettingsCurrency($args);
                    },
                ],
                'bulkDeleteAdminSettingsCurrency' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminSettingsCurrencies($args);
                    },
                ],
                'bulkDeleteAdminSettingsExchangeRate' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::bulkDeleteAdminSettingsExchangeRates($args);
                    },
                ],
                'createAdminAppearanceThemeActivate' => [
                    'type' => Type::nonNull($appearanceThemeActivateResult),
                    'args' => [
                        'code' => Type::nonNull(Type::string()),
                        'storeIds' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        return GraphqlAdminAppearanceThemes::activate($args);
                    },
                ],
                'createAdminAppearanceSection' => [
                    'type' => Type::nonNull($appearanceSection),
                    'args' => [
                        'code' => Type::string(),
                        'storeId' => Type::id(),
                        'name' => Type::string(),
                        'type' => Type::string(),
                        'input' => new InputObjectType([
                            'name' => 'CreateAdminAppearanceSectionInput',
                            'fields' => [
                                'code' => Type::string(),
                                'storeId' => Type::id(),
                                'name' => Type::string(),
                                'type' => Type::string(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        unset($args['input']);

                        return GraphqlAdminAppearanceSections::create(array_merge($args, $input));
                    },
                ],
                'createAdminSettingsTheme' => [
                    'type' => Type::nonNull($appearanceSection),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'type' => Type::nonNull(Type::string()),
                        'storeId' => Type::id(),
                        'status' => Type::int(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlAdminSettingsThemes::create($args),
                ],
                'updateAdminSettingsTheme' => [
                    'type' => Type::nonNull($appearanceSection),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'name' => Type::string(),
                        'status' => Type::int(),
                        'options' => Type::string(),
                        'storeId' => Type::id(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlAdminSettingsThemes::update((int) $args['id'], $args),
                ],
                'deleteAdminSettingsTheme' => [
                    'type' => Type::nonNull($appearanceSection),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'storeId' => Type::id(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlAdminSettingsThemes::delete((int) $args['id'], $args),
                ],
                'bulkDeleteAdminSettingsTheme' => [
                    'type' => Type::nonNull($bulkDeleteResultType),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                    ],
                    'resolve' => function ($root, array $args) {
                        $ids = (array) ($args['ids'] ?? []);

                        return GraphqlAdminSettingsThemes::bulkDelete($ids, $args);
                    },
                ],
                'updateAdminAppearanceSection' => [
                    'type' => Type::nonNull($appearanceSection),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'name' => Type::string(),
                        'status' => Type::int(),
                        'options' => Type::string(),
                        'code' => Type::string(),
                        'storeId' => Type::id(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlAdminAppearanceSections::update($args),
                ],
                'deleteAdminAppearanceSection' => [
                    'type' => Type::nonNull($deleteResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'code' => Type::string(),
                        'storeId' => Type::id(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlAdminAppearanceSections::delete($args),
                ],
                'createAdminAppearanceSectionStatus' => [
                    'type' => Type::nonNull($appearanceSection),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'status' => Type::nonNull(Type::int()),
                        'code' => Type::string(),
                        'storeId' => Type::id(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlAdminAppearanceSections::status($args),
                ],
                'createAdminAppearanceSectionReorder' => [
                    'type' => Type::nonNull($appearanceSectionReorderResult),
                    'args' => [
                        'ids' => Type::listOf(Type::nonNull(Type::id())),
                        'order' => Type::listOf(Type::nonNull(Type::id())),
                        'code' => Type::string(),
                        'storeId' => Type::id(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlAdminAppearanceSections::reorder($args),
                ],
                'createAdminAppearanceSectionPublish' => [
                    'type' => Type::nonNull($appearanceSectionPublishResult),
                    'args' => [
                        'code' => Type::string(),
                        'storeId' => Type::id(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlAdminAppearanceSections::publish($args),
                ],
                'createAdminAppearanceSectionDiscard' => [
                    'type' => Type::nonNull($appearanceSectionPublishResult),
                    'args' => [
                        'code' => Type::string(),
                        'storeId' => Type::id(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlAdminAppearanceSections::discard($args),
                ],
                'createAdminAppearanceSectionDuplicate' => [
                    'type' => Type::nonNull($appearanceSection),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'code' => Type::string(),
                        'storeId' => Type::id(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlAdminAppearanceSections::duplicate($args),
                ],
                'createAdminAppearanceSectionDraft' => [
                    'type' => Type::nonNull($appearanceSection),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'options' => Type::string(),
                        'locale' => Type::string(),
                        'code' => Type::string(),
                        'storeId' => Type::id(),
                    ],
                    'resolve' => function ($root, array $args) {
                        // Draft content maps to published update for Classic modules; status stays staged via publish flow.
                        return GraphqlAdminAppearanceSections::update($args);
                    },
                ],
                'recordPayment' => [
                    'type' => Type::nonNull($paymentRecord),
                    'args' => [
                        'number' => Type::nonNull(Type::string()),
                        'amount' => Type::float(),
                        'markPaid' => ['type' => Type::boolean(), 'defaultValue' => false],
                        'paymentMethod' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $result = self::recordAdminPayment($args);

                        return [
                            'order_number' => $result['order_number'],
                            'amount_recorded' => $result['amount'],
                            'amount_paid' => $result['amount_paid'],
                            'amount_due' => $result['amount_due'],
                            'payment_status' => $result['payment_status'],
                        ];
                    },
                ],
                // Admin REST docs → createAdminTransaction (flat, invoiceId, or input:)
                'createAdminTransaction' => [
                    'type' => Type::nonNull($createAdminTransactionResult),
                    'args' => [
                        'number' => Type::string(),
                        'invoiceNumber' => Type::string(),
                        'invoiceId' => Type::id(),
                        'amount' => Type::float(),
                        'paymentMethod' => Type::string(),
                        'input' => new InputObjectType([
                            'name' => 'CreateAdminTransactionInput',
                            'fields' => [
                                'invoiceId' => Type::id(),
                                'invoiceNumber' => Type::string(),
                                'number' => Type::string(),
                                'paymentMethod' => Type::string(),
                                'amount' => Type::float(),
                                'clientMutationId' => Type::string(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        unset($args['input']);

                        return self::createAdminTransactionMutation(array_merge($args, $input));
                    },
                ],
            ],
        ]);

        return new Schema(['query' => $query, 'mutation' => $mutation]);
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminTransactionMutation(array $args): array
    {
        $number = trim((string) ($args['number'] ?? ''));
        $invoiceNumber = trim((string) ($args['invoiceNumber'] ?? ''));
        $invoiceId = trim((string) ($args['invoiceId'] ?? ''));
        if ($invoiceNumber === '' && $invoiceId !== '') {
            // Prefer matching stored invoice_number; also accept numeric order id as invoiceId stand-in.
            $byInvoice = Order::query()
                ->where('fulfillment_details->invoice_number', $invoiceId)
                ->first();
            if ($byInvoice) {
                $invoiceNumber = $invoiceId;
                $number = (string) $byInvoice->number;
            } elseif (ctype_digit($invoiceId) || (is_numeric($invoiceId) && (int) $invoiceId > 0)) {
                $byOrder = Order::query()->find((int) $invoiceId);
                if ($byOrder) {
                    $number = (string) $byOrder->number;
                    $fulfillment = is_array($byOrder->fulfillment_details) ? $byOrder->fulfillment_details : [];
                    $invoiceNumber = (string) ($fulfillment['invoice_number'] ?? '');
                }
            }
        }
        if ($number === '' && $invoiceNumber !== '') {
            $order = Order::query()
                ->where('fulfillment_details->invoice_number', $invoiceNumber)
                ->first();
            if (! $order) {
                throw new UserError('Invoice not found.');
            }
            $number = (string) $order->number;
        }
        if ($number === '') {
            throw new UserError('Order number or invoice number is required.');
        }
        if (! array_key_exists('amount', $args) || $args['amount'] === null) {
            throw new UserError('The amount field is required.');
        }
        $result = self::recordAdminPayment([
            'number' => $number,
            'amount' => $args['amount'],
            'paymentMethod' => $args['paymentMethod'] ?? null,
            'markPaid' => false,
            'invoiceNumber' => $invoiceNumber !== '' ? $invoiceNumber : null,
        ]);
        $tx = $result['transaction'];
        $tx['adminTransaction'] = $tx;

        return $tx;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createSimpleProduct(array $args): array
    {
        self::assertCatalogProducts();

        $sku = trim((string) $args['sku']);
        $name = trim((string) $args['name']);
        $price = (float) $args['price'];
        $stockQty = (float) ($args['stockQty'] ?? 0);
        if ($sku === '' || $name === '') {
            throw new UserError('SKU and name are required.');
        }
        if ($price < 0) {
            throw new UserError('Price must be zero or greater.');
        }
        if ($stockQty < 0) {
            throw new UserError('Stock quantity must be zero or greater.');
        }
        if (Product::query()->where('sku', $sku)->exists()) {
            throw new UserError('A product with this SKU already exists.');
        }

        $categoryId = isset($args['categoryId']) ? (int) $args['categoryId'] : 0;
        if ($categoryId > 0) {
            $category = Category::query()->where('id', $categoryId)->where('is_enabled', true)->first();
        } else {
            $category = Category::query()->where('is_enabled', true)->orderBy('position')->orderBy('id')->first();
        }
        if (! $category) {
            throw new UserError('No enabled category available. Pass categoryId or create a category first.');
        }

        $locale = Language::defaultCode();
        $slug = Str::slug($name);
        if ($slug === '') {
            $slug = 'product-'.Str::lower(Str::random(6));
        }

        try {
            $row = app(ProductService::class)->save([
                'product' => [
                    'type' => 'simple',
                    'sku' => $sku,
                    'price' => $price,
                    'stock_qty' => $stockQty,
                    'default_category_id' => $category->id,
                    'visibility' => 'both',
                    'is_enabled' => (bool) ($args['enabled'] ?? true),
                    'available_for_order' => true,
                    'show_price' => true,
                    'manage_stock' => true,
                    'available_all_carriers' => true,
                    'stock_status' => $stockQty > 0 ? 'in_stock' : 'out_of_stock',
                ],
                'translation' => [
                    'language_code' => $locale,
                    'name' => $name,
                    'slug' => $slug,
                    'short_description' => isset($args['shortDescription'])
                        ? (string) $args['shortDescription']
                        : null,
                ],
                'category_ids' => [$category->id],
            ]);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();
            throw new UserError($messages[0] ?? 'Product validation failed.');
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return self::mapAdminProduct($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createCategory(array $args): array
    {
        self::assertCatalogCategories();

        $name = trim((string) $args['name']);
        if ($name === '') {
            throw new UserError('Name is required.');
        }
        $slug = trim((string) ($args['slug'] ?? ''));
        if ($slug === '') {
            $slug = Str::slug($name);
        }
        if ($slug === '') {
            $slug = 'category-'.Str::lower(Str::random(6));
        }
        $parentId = isset($args['parentId']) ? (int) $args['parentId'] : null;
        if ($parentId) {
            if (! Category::query()->where('id', $parentId)->exists()) {
                throw new UserError('Parent category not found.');
            }
        }

        try {
            $row = app(CategoryService::class)->save([
                'parent_id' => $parentId,
                'is_enabled' => (bool) ($args['enabled'] ?? true),
                'name' => $name,
                'slug' => $slug,
                'description' => isset($args['description']) ? (string) $args['description'] : null,
                'language_code' => Language::defaultCode(),
            ]);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();
            throw new UserError($messages[0] ?? 'Category validation failed.');
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return self::mapAdminCategory($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateCategory(array $args): array
    {
        self::assertCatalogCategories();
        $row = Category::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Category not found.');
        }
        $locale = Language::defaultCode();
        $tr = $row->translation($locale);
        $name = array_key_exists('name', $args) ? trim((string) $args['name']) : (string) ($tr?->name ?? '');
        if ($name === '') {
            throw new UserError('Name is required.');
        }
        $slug = array_key_exists('slug', $args) ? trim((string) $args['slug']) : (string) ($tr?->slug ?? '');
        if ($slug === '') {
            $slug = Str::slug($name) ?: ('category-'.Str::lower(Str::random(6)));
        }

        try {
            $row = app(CategoryService::class)->save([
                'parent_id' => $row->parent_id,
                'is_enabled' => array_key_exists('enabled', $args)
                    ? (bool) $args['enabled']
                    : (bool) $row->is_enabled,
                'name' => $name,
                'slug' => $slug,
                'description' => array_key_exists('description', $args)
                    ? (string) $args['description']
                    : ($tr?->description),
                'language_code' => $locale,
            ], $row);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();
            throw new UserError($messages[0] ?? 'Category validation failed.');
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return self::mapAdminCategory($row->fresh(['translations']) ?? $row);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapInvoiceInfo(Order $order, bool $withHtml): array
    {
        $doc = app(InvoiceDocument::class);
        $details = is_array($order->fulfillment_details) ? $order->fulfillment_details : [];
        $payload = null;
        if ($doc->isAvailable($order) || $withHtml) {
            try {
                $payload = $doc->payload($order);
            } catch (\Throwable) {
                $payload = null;
            }
        }

        return [
            'available' => $doc->isAvailable($order),
            'enabled' => DocumentCommerceSettings::invoicesEnabled(),
            'order_number' => $order->number,
            'invoice_number' => isset($details['invoice_number'])
                ? (string) $details['invoice_number']
                : (isset($payload['number']) ? (string) $payload['number'] : null),
            'grand_total' => $payload['grand_total'] ?? ($order->grand_total !== null ? (float) $order->grand_total : null),
            'currency_code' => $payload['currency_code'] ?? $order->currency_code,
            'transaction_recorded' => false,
            'html' => $withHtml && $doc->isAvailable($order) ? $doc->html($order) : null,
            'items' => collect($payload['items'] ?? [])->map(fn (array $item) => [
                'id' => (string) ($item['id'] ?? ''),
                'sku' => $item['sku'] ?? null,
                'name' => $item['name'] ?? null,
                'qty' => isset($item['qty']) ? (float) $item['qty'] : null,
                'unit_price' => isset($item['unit_price']) ? (float) $item['unit_price'] : null,
                'total' => isset($item['total']) ? (float) $item['total'] : null,
            ])->values()->all(),
        ];
    }

    protected static function assertCatalogProducts(): Admin
    {
        $admin = auth('admin')->user();
        if (
            ! $admin instanceof Admin
            || (! $admin->hasPermission('catalog.products') && ! $admin->hasPermission('catalog.manage'))
        ) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    protected static function assertCatalogCategories(): Admin
    {
        $admin = auth('admin')->user();
        if (
            ! $admin instanceof Admin
            || (! $admin->hasPermission('catalog.categories') && ! $admin->hasPermission('catalog.manage'))
        ) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    protected static function assertOrdersManage(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('orders.manage')) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    protected static function assertSuperAdmin(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->isSuperAdmin()) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    protected static function assertMarketingDiscounts(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('marketing.discounts')) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    protected static function assertCustomerGroupsManage(): Admin
    {
        $admin = auth('admin')->user();
        if (
            ! $admin instanceof Admin
            || (! $admin->hasPermission('customers.manage') && ! $admin->hasPermission('customers.groups'))
        ) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    protected static function assertNewsletterManage(): Admin
    {
        $admin = auth('admin')->user();
        if (
            ! $admin instanceof Admin
            || (! $admin->hasPermission('marketing.newsletter') && ! $admin->hasPermission('marketing.discounts'))
        ) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    protected static function assertSettingsManage(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('settings.manage')) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    protected static function assertMarketingSearch(): Admin
    {
        $admin = auth('admin')->user();
        if (
            ! $admin instanceof Admin
            || (
                ! $admin->hasPermission('catalog.products')
                && ! $admin->hasPermission('marketing.discounts')
                && ! $admin->hasPermission('marketing.newsletter')
                && ! $admin->hasPermission('settings.manage')
            )
        ) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminMarketingSearchSynonym(SearchSynonym $row): array
    {
        return [
            'id' => (string) $row->id,
            'term' => (string) $row->term,
            'name' => (string) $row->term,
            'aliases' => (string) $row->aliases,
            'terms' => (string) $row->aliases,
            'is_enabled' => (bool) $row->is_enabled,
            'status' => $row->is_enabled ? 1 : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminMarketingSearchSynonyms(array $args): array
    {
        self::assertMarketingSearch();
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $q = SearchSynonym::query()->orderByDesc('id');
        $op = DbGrammar::likeOperator();
        foreach (['name', 'terms', 'q'] as $key) {
            $term = trim((string) ($args[$key] ?? ''));
            if ($term === '') {
                continue;
            }
            $q->where(function ($inner) use ($term, $op) {
                $inner->where('term', $op, '%'.$term.'%')
                    ->orWhere('aliases', $op, '%'.$term.'%');
            });
        }

        return $q->limit($limit)->get()->map(fn (SearchSynonym $row) => self::mapAdminMarketingSearchSynonym($row))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminMarketingSearchSynonym(array $args): array
    {
        self::assertMarketingSearch();
        $term = trim((string) ($args['term'] ?? $args['name'] ?? ''));
        $aliases = trim((string) ($args['aliases'] ?? $args['terms'] ?? ''));
        if ($term === '' || $aliases === '') {
            throw new UserError('name/term and terms/aliases are required.');
        }
        $row = SearchSynonym::query()->updateOrCreate(
            ['term' => $term],
            [
                'aliases' => $aliases,
                'is_enabled' => (bool) ($args['enabled'] ?? true),
            ]
        );
        try {
            \Illuminate\Support\Facades\Cache::forget('search.synonyms');
        } catch (\Throwable) {
            // Cache flush is best-effort.
        }

        return self::mapAdminMarketingSearchSynonym($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminMarketingSearchSynonym(array $args): array
    {
        self::assertMarketingSearch();
        $row = SearchSynonym::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Search synonym not found.');
        }
        if (array_key_exists('term', $args) || array_key_exists('name', $args)) {
            $term = trim((string) ($args['term'] ?? $args['name'] ?? ''));
            if ($term === '') {
                throw new UserError('name/term cannot be empty.');
            }
            $row->term = $term;
        }
        if (array_key_exists('aliases', $args) || array_key_exists('terms', $args)) {
            $aliases = trim((string) ($args['aliases'] ?? $args['terms'] ?? ''));
            if ($aliases === '') {
                throw new UserError('terms/aliases cannot be empty.');
            }
            $row->aliases = $aliases;
        }
        if (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $row->is_enabled = (bool) $args['enabled'];
        }
        $row->save();
        try {
            \Illuminate\Support\Facades\Cache::forget('search.synonyms');
        } catch (\Throwable) {
            // Cache flush is best-effort.
        }

        return self::mapAdminMarketingSearchSynonym($row->fresh() ?? $row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function deleteAdminMarketingSearchSynonym(array $args): array
    {
        self::assertMarketingSearch();
        $row = SearchSynonym::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Search synonym not found.');
        }
        $id = (string) $row->id;
        $row->delete();
        try {
            \Illuminate\Support\Facades\Cache::forget('search.synonyms');
        } catch (\Throwable) {
            // Cache flush is best-effort.
        }

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, deleted: list<string>, skipped: list<string>, updated_count: int, message: string}
     */
    protected static function bulkDeleteAdminMarketingSearchSynonyms(array $args): array
    {
        self::assertMarketingSearch();
        $ids = collect($args['ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = SearchSynonym::query()->find($id);
            if (! $row) {
                $skipped[] = (string) $id;
                continue;
            }
            $row->delete();
            $deleted[] = (string) $id;
        }
        try {
            \Illuminate\Support\Facades\Cache::forget('search.synonyms');
        } catch (\Throwable) {
            // Cache flush is best-effort.
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' synonym group(s) deleted.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminMarketingUrlRewrite(SearchRedirect $row): array
    {
        return [
            'id' => (string) $row->id,
            'query' => (string) $row->query,
            'target_path' => (string) $row->target_path,
            'requestPath' => (string) $row->query,
            'targetPath' => (string) $row->target_path,
            'redirectType' => '301',
            'term' => (string) $row->query,
            'redirect_url' => (string) $row->target_path,
            'is_enabled' => (bool) $row->is_enabled,
            'status' => $row->is_enabled ? 1 : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminMarketingUrlRewrites(array $args): array
    {
        self::assertMarketingSearch();
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $q = SearchRedirect::query()->orderByDesc('id');
        $term = trim((string) ($args['q'] ?? ''));
        if ($term !== '') {
            $op = DbGrammar::likeOperator();
            $q->where(function ($inner) use ($term, $op) {
                $inner->where('query', $op, '%'.$term.'%')
                    ->orWhere('target_path', $op, '%'.$term.'%');
            });
        }

        return $q->limit($limit)->get()->map(fn (SearchRedirect $row) => self::mapAdminMarketingUrlRewrite($row))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminMarketingUrlRewrite(array $args): array
    {
        self::assertMarketingSearch();
        $query = mb_strtolower(trim((string) (
            $args['query'] ?? $args['requestPath'] ?? $args['term'] ?? ''
        )));
        $targetRaw = trim((string) ($args['targetPath'] ?? $args['redirect_url'] ?? ''));
        $target = SafeRedirect::sanitize($targetRaw);
        if ($query === '') {
            throw new UserError('query/requestPath/term is required.');
        }
        if ($target === null || ! str_starts_with($target, '/') || str_starts_with($target, '//')) {
            throw new UserError('targetPath must be a safe absolute path starting with /.');
        }
        $row = SearchRedirect::query()->updateOrCreate(
            ['query' => $query],
            [
                'target_path' => $target,
                'is_enabled' => (bool) ($args['enabled'] ?? true),
            ]
        );

        return self::mapAdminMarketingUrlRewrite($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminMarketingUrlRewrite(array $args): array
    {
        self::assertMarketingSearch();
        $row = SearchRedirect::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('URL rewrite / search term not found.');
        }
        if (array_key_exists('query', $args) || array_key_exists('requestPath', $args) || array_key_exists('term', $args)) {
            $query = mb_strtolower(trim((string) (
                $args['query'] ?? $args['requestPath'] ?? $args['term'] ?? ''
            )));
            if ($query === '') {
                throw new UserError('query/requestPath/term cannot be empty.');
            }
            $row->query = $query;
        }
        if (array_key_exists('targetPath', $args) || array_key_exists('redirect_url', $args)) {
            $target = SafeRedirect::sanitize(trim((string) ($args['targetPath'] ?? $args['redirect_url'] ?? '')));
            if ($target === null || ! str_starts_with($target, '/') || str_starts_with($target, '//')) {
                throw new UserError('targetPath must be a safe absolute path starting with /.');
            }
            $row->target_path = $target;
        }
        if (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $row->is_enabled = (bool) $args['enabled'];
        }
        $row->save();

        return self::mapAdminMarketingUrlRewrite($row->fresh() ?? $row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function deleteAdminMarketingUrlRewrite(array $args): array
    {
        self::assertMarketingSearch();
        $row = SearchRedirect::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('URL rewrite / search term not found.');
        }
        $id = (string) $row->id;
        $row->delete();

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, deleted: list<string>, skipped: list<string>, updated_count: int, message: string}
     */
    protected static function bulkDeleteAdminMarketingUrlRewrites(array $args): array
    {
        self::assertMarketingSearch();
        $ids = collect($args['ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = SearchRedirect::query()->find($id);
            if (! $row) {
                $skipped[] = (string) $id;
                continue;
            }
            $row->delete();
            $deleted[] = (string) $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' rewrite/term row(s) deleted.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminSettingsRole(Role $role): array
    {
        $role->loadMissing('permissions');
        $permissions = $role->permissions->pluck('slug')->values()->all();
        $totalPermissions = (int) Permission::query()->count();
        $permissionType = $role->isSuperAdminRole()
            || in_array('*', $permissions, true)
            || ($totalPermissions > 0 && count($permissions) >= $totalPermissions)
            ? 'all'
            : 'custom';

        return [
            'id' => (string) $role->id,
            'slug' => $role->slug,
            'name' => $role->name,
            'description' => $role->name,
            'permission_type' => $permissionType,
            'permissionType' => $permissionType,
            'permissions' => $permissionType === 'all' ? null : $permissions,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminSettingsRoles(array $args): array
    {
        self::assertSettingsManage();
        $limit = max(1, min(100, (int) ($args['limit'] ?? 50)));
        $q = Role::query()->with('permissions')->orderByDesc('id');
        $name = trim((string) ($args['name'] ?? ''));
        if ($name !== '') {
            $op = DbGrammar::likeOperator();
            $q->where('name', $op, '%'.$name.'%');
        }
        $permissionType = strtolower(trim((string) ($args['permission_type'] ?? '')));
        $rows = $q->limit($limit)->get()->map(fn (Role $r) => self::mapAdminSettingsRole($r));
        if (in_array($permissionType, ['all', 'custom'], true)) {
            $rows = $rows->filter(fn (array $row) => ($row['permissionType'] ?? '') === $permissionType)->values();
        }

        return $rows->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminSettingsRole(array $args): array
    {
        self::assertSuperAdmin();
        $name = trim((string) $args['name']);
        if ($name === '') {
            throw new UserError('Name is required.');
        }
        $permissionType = strtolower(trim((string) ($args['permissionType'] ?? 'custom')));
        if (! in_array($permissionType, ['all', 'custom'], true)) {
            $permissionType = 'custom';
        }
        $role = Role::query()->create([
            'name' => $name,
            'slug' => UniqueSlug::make($name, Role::class),
        ]);
        if ($permissionType === 'all') {
            $ids = Permission::query()->pluck('id')->all();
            $role->permissions()->sync($ids);
        } else {
            $allowed = PageCatalog::slugs();
            $permissions = collect($args['permissions'] ?? [])
                ->map(fn ($slug) => trim((string) $slug))
                ->filter()
                ->unique()
                ->values()
                ->intersect($allowed)
                ->values()
                ->all();
            if ($permissions === []) {
                throw new UserError('Choose at least one valid permission slug.');
            }
            $ids = Permission::query()->whereIn('slug', $permissions)->pluck('id')->all();
            $role->permissions()->sync($ids);
        }

        return self::mapAdminSettingsRole($role->fresh(['permissions']) ?? $role->load('permissions'));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminSettingsRole(array $args): array
    {
        self::assertSuperAdmin();
        $role = Role::query()->with('permissions')->find((int) $args['id']);
        if (! $role) {
            throw new UserError('Role not found.');
        }
        if ($role->isSystem() && $role->isSuperAdminRole()) {
            throw new UserError('The super admin role cannot be edited via API.');
        }
        if (array_key_exists('name', $args) && $args['name'] !== null) {
            $name = trim((string) $args['name']);
            if ($name === '') {
                throw new UserError('Name cannot be empty.');
            }
            $role->name = $name;
            $role->save();
        }
        $permissionType = array_key_exists('permissionType', $args) && $args['permissionType'] !== null
            ? strtolower(trim((string) $args['permissionType']))
            : null;
        if ($permissionType === 'all') {
            $role->permissions()->sync(Permission::query()->pluck('id')->all());
        } elseif (array_key_exists('permissions', $args) && is_array($args['permissions'])) {
            $allowed = PageCatalog::slugs();
            $permissions = collect($args['permissions'])
                ->map(fn ($slug) => trim((string) $slug))
                ->filter()
                ->unique()
                ->values()
                ->intersect($allowed)
                ->values()
                ->all();
            if ($permissions === []) {
                throw new UserError('Choose at least one valid permission slug.');
            }
            $role->permissions()->sync(Permission::query()->whereIn('slug', $permissions)->pluck('id')->all());
        }

        return self::mapAdminSettingsRole($role->fresh(['permissions']) ?? $role->load('permissions'));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function deleteAdminSettingsRole(array $args): array
    {
        self::assertSuperAdmin();
        $role = Role::query()->find((int) $args['id']);
        if (! $role) {
            throw new UserError('Role not found.');
        }
        if ($role->isSystem()) {
            throw new UserError('System roles cannot be deleted.');
        }
        if ($role->admins()->exists()) {
            throw new UserError('This role is assigned to admins and cannot be deleted.');
        }
        $id = (string) $role->id;
        $role->permissions()->detach();
        $role->delete();

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminSettingsUser(Admin $admin): array
    {
        $admin->loadMissing('roles');
        $role = $admin->roles->first();

        return [
            'id' => (string) $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'is_enabled' => (bool) $admin->is_enabled,
            'is_super' => $admin->isSuperAdmin(),
            'status' => $admin->is_enabled ? 1 : 0,
            'roleId' => $role ? (string) $role->id : null,
            'role_id' => $role ? (string) $role->id : null,
            'roleName' => $role?->name,
            'role_name' => $role?->name,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminSettingsUsers(array $args): array
    {
        self::assertSuperAdmin();
        $limit = max(1, min(100, (int) ($args['limit'] ?? 50)));
        $q = Admin::query()->with('roles')->orderByDesc('id');
        $op = DbGrammar::likeOperator();
        $name = trim((string) ($args['name'] ?? ''));
        if ($name !== '') {
            $q->where('name', $op, '%'.$name.'%');
        }
        $email = trim((string) ($args['email'] ?? ''));
        if ($email !== '') {
            $q->where('email', $op, '%'.$email.'%');
        }
        if (! empty($args['role_id'])) {
            $roleId = (int) $args['role_id'];
            $q->whereHas('roles', fn ($rq) => $rq->where('roles.id', $roleId));
        }
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $q->where('is_enabled', ((int) $args['status']) === 1);
        }

        return $q->limit($limit)->get()->map(fn (Admin $a) => self::mapAdminSettingsUser($a))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminSettingsUser(array $args): array
    {
        self::assertSuperAdmin();
        $name = trim((string) $args['name']);
        $email = strtolower(trim((string) $args['email']));
        $password = (string) $args['password'];
        if ($name === '' || $email === '' || strlen($password) < 8) {
            throw new UserError('Name, email, and password (min 8 chars) are required.');
        }
        if (Admin::query()->where('email', $email)->exists()) {
            throw new UserError('An admin with this email already exists.');
        }
        $enabled = true;
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $enabled = ((int) $args['status']) === 1;
        } elseif (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $enabled = (bool) $args['enabled'];
        }
        $row = Admin::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_enabled' => $enabled,
        ]);
        if (! empty($args['super'])) {
            $row->grantSuperAdmin();
        } elseif (! empty($args['roleId'])) {
            $role = Role::query()->find((int) $args['roleId']);
            if (! $role) {
                throw new UserError('Role not found.');
            }
            $row->roles()->sync([(int) $role->id]);
        }

        return self::mapAdminSettingsUser($row->fresh(['roles']) ?? $row->load('roles'));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminSettingsUser(array $args): array
    {
        self::assertSuperAdmin();
        $row = Admin::query()->with('roles')->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Admin user not found.');
        }
        if (array_key_exists('name', $args) && $args['name'] !== null) {
            $name = trim((string) $args['name']);
            if ($name === '') {
                throw new UserError('Name cannot be empty.');
            }
            $row->name = $name;
        }
        if (array_key_exists('email', $args) && $args['email'] !== null) {
            $email = strtolower(trim((string) $args['email']));
            if ($email === '') {
                throw new UserError('Email cannot be empty.');
            }
            if (Admin::query()->where('email', $email)->where('id', '!=', $row->id)->exists()) {
                throw new UserError('An admin with this email already exists.');
            }
            $row->email = $email;
        }
        if (array_key_exists('password', $args) && $args['password'] !== null && $args['password'] !== '') {
            $password = (string) $args['password'];
            if (strlen($password) < 8) {
                throw new UserError('Password must be at least 8 characters.');
            }
            $row->password = $password;
        }
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $row->is_enabled = ((int) $args['status']) === 1;
        } elseif (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $row->is_enabled = (bool) $args['enabled'];
        }
        $row->save();
        if (array_key_exists('roleId', $args) && $args['roleId'] !== null) {
            $role = Role::query()->find((int) $args['roleId']);
            if (! $role) {
                throw new UserError('Role not found.');
            }
            if ($row->isLastSuperAdmin() && ! $role->isSuperAdminRole()) {
                throw new UserError('Cannot remove the last super admin role.');
            }
            $row->roles()->sync([(int) $role->id]);
        }

        return self::mapAdminSettingsUser($row->fresh(['roles']) ?? $row->load('roles'));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function deleteAdminSettingsUser(array $args): array
    {
        self::assertSuperAdmin();
        $row = Admin::query()->with('roles')->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Admin user not found.');
        }
        if ($row->isLastSuperAdmin()) {
            throw new UserError('Cannot delete the last super admin.');
        }
        $actor = auth('admin')->user();
        if ($actor instanceof Admin && (int) $actor->id === (int) $row->id) {
            throw new UserError('You cannot delete your own admin account.');
        }
        $id = (string) $row->id;
        $row->roles()->detach();
        $row->tokens()->delete();
        $row->delete();

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminSettingsLocale(Language $row): array
    {
        return [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'name' => $row->name,
            'is_enabled' => (bool) $row->is_enabled,
            'is_default' => (bool) $row->is_default,
            'status' => $row->is_enabled ? 1 : 0,
            'direction' => 'ltr',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminSettingsCurrencyRow(Currency $row): array
    {
        $position = (string) ($row->symbol_position ?: 'before');

        return [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'name' => $row->name,
            'symbol' => $row->symbol,
            'exchange_rate' => $row->exchange_rate !== null ? (float) $row->exchange_rate : null,
            'is_enabled' => (bool) $row->is_enabled,
            'is_default' => (bool) $row->is_default,
            'status' => $row->is_enabled ? 1 : 0,
            'decimal' => (int) ($row->decimal_places ?? 2),
            'decimal_places' => (int) ($row->decimal_places ?? 2),
            'currencyPosition' => $position === 'after' ? 'right' : 'left',
            'symbol_position' => $position,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminSettingsLocale(array $args): array
    {
        self::assertSettingsManage();
        $code = strtolower(trim((string) $args['code']));
        $name = trim((string) $args['name']);
        if ($code === '' || $name === '') {
            throw new UserError('code and name are required.');
        }
        if (! preg_match('/^[a-z]{2}(-[a-z0-9]+)?$/i', $code)) {
            throw new UserError('code must be a locale like en or pt-br.');
        }
        if (Language::query()->where('code', $code)->exists()) {
            throw new UserError('A locale with this code already exists.');
        }
        $enabled = true;
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $enabled = ((int) $args['status']) === 1;
        } elseif (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $enabled = (bool) $args['enabled'];
        }
        $row = Language::query()->create([
            'code' => $code,
            'name' => $name,
            'is_enabled' => $enabled,
            'is_default' => false,
            'position' => (int) (Language::query()->max('position') ?? 0) + 1,
        ]);

        return self::mapAdminSettingsLocale($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminSettingsLocale(array $args): array
    {
        self::assertSettingsManage();
        $row = Language::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Locale not found.');
        }
        if (array_key_exists('name', $args) && $args['name'] !== null) {
            $name = trim((string) $args['name']);
            if ($name === '') {
                throw new UserError('name cannot be empty.');
            }
            $row->name = $name;
        }
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $row->is_enabled = ((int) $args['status']) === 1;
        } elseif (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $row->is_enabled = (bool) $args['enabled'];
        }
        $row->save();

        return self::mapAdminSettingsLocale($row->fresh() ?? $row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function deleteAdminSettingsLocale(array $args): array
    {
        self::assertSettingsManage();
        $row = Language::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Locale not found.');
        }
        if ($row->is_default) {
            throw new UserError('The default locale cannot be deleted.');
        }
        if (Language::query()->count() <= 1) {
            throw new UserError('The store must keep at least one locale.');
        }
        $id = (string) $row->id;
        $row->delete();

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, deleted: list<string>, skipped: list<string>, updated_count: int, message: string}
     */
    protected static function bulkDeleteAdminSettingsLocales(array $args): array
    {
        self::assertSettingsManage();
        $ids = [];
        foreach (['ids'] as $key) {
            foreach ((array) ($args[$key] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            throw new UserError('Provide at least one locale id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = Language::query()->find($id);
            if (! $row || $row->is_default || Language::query()->count() <= 1) {
                $skipped[] = (string) $id;
                continue;
            }
            $row->delete();
            $deleted[] = (string) $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' locale(s) deleted.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminSettingsCurrency(array $args): array
    {
        self::assertSettingsManage();
        $code = strtoupper(trim((string) $args['code']));
        $name = trim((string) $args['name']);
        if ($code === '' || $name === '') {
            throw new UserError('code and name are required.');
        }
        if (! preg_match('/^[A-Z]{3}$/', $code)) {
            throw new UserError('code must be a 3-letter ISO currency code.');
        }
        if (Currency::query()->where('code', $code)->exists()) {
            throw new UserError('A currency with this code already exists.');
        }
        $enabled = true;
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $enabled = ((int) $args['status']) === 1;
        } elseif (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $enabled = (bool) $args['enabled'];
        }
        $position = strtolower(trim((string) ($args['currencyPosition'] ?? 'left')));
        $symbolPosition = in_array($position, ['right', 'after'], true) ? 'after' : 'before';
        $row = Currency::query()->create([
            'code' => $code,
            'name' => $name,
            'symbol' => trim((string) ($args['symbol'] ?? $code)) ?: $code,
            'decimal_places' => max(0, min(6, (int) ($args['decimal'] ?? 2))),
            'symbol_position' => $symbolPosition,
            'exchange_rate' => isset($args['exchange_rate']) ? (float) $args['exchange_rate'] : 1.0,
            'is_enabled' => $enabled,
            'is_default' => false,
            'position' => (int) (Currency::query()->max('position') ?? 0) + 1,
        ]);

        return self::mapAdminSettingsCurrencyRow($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminSettingsCurrency(array $args): array
    {
        self::assertSettingsManage();
        $row = Currency::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Currency not found.');
        }
        if (array_key_exists('name', $args) && $args['name'] !== null) {
            $name = trim((string) $args['name']);
            if ($name === '') {
                throw new UserError('name cannot be empty.');
            }
            $row->name = $name;
        }
        if (array_key_exists('symbol', $args) && $args['symbol'] !== null) {
            $row->symbol = trim((string) $args['symbol']);
        }
        if (array_key_exists('decimal', $args) && $args['decimal'] !== null) {
            $row->decimal_places = max(0, min(6, (int) $args['decimal']));
        }
        if (array_key_exists('currencyPosition', $args) && $args['currencyPosition'] !== null) {
            $position = strtolower(trim((string) $args['currencyPosition']));
            $row->symbol_position = in_array($position, ['right', 'after'], true) ? 'after' : 'before';
        }
        if (array_key_exists('exchange_rate', $args) && $args['exchange_rate'] !== null) {
            $rate = (float) $args['exchange_rate'];
            if ($rate <= 0) {
                throw new UserError('exchange_rate must be positive.');
            }
            $row->exchange_rate = $rate;
        }
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $row->is_enabled = ((int) $args['status']) === 1;
        } elseif (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $row->is_enabled = (bool) $args['enabled'];
        }
        $row->save();

        return self::mapAdminSettingsCurrencyRow($row->fresh() ?? $row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function deleteAdminSettingsCurrency(array $args): array
    {
        self::assertSettingsManage();
        $row = Currency::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Currency not found.');
        }
        if ($row->is_default) {
            throw new UserError('The default currency cannot be deleted.');
        }
        if (Currency::query()->count() <= 1) {
            throw new UserError('The store must keep at least one currency.');
        }
        $id = (string) $row->id;
        $row->delete();

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, deleted: list<string>, skipped: list<string>, updated_count: int, message: string}
     */
    protected static function bulkDeleteAdminSettingsCurrencies(array $args): array
    {
        self::assertSettingsManage();
        $ids = [];
        foreach (['ids'] as $key) {
            foreach ((array) ($args[$key] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            throw new UserError('Provide at least one currency id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = Currency::query()->find($id);
            if (! $row || $row->is_default || Currency::query()->count() <= 1) {
                $skipped[] = (string) $id;
                continue;
            }
            $row->delete();
            $deleted[] = (string) $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' currency(ies) deleted.',
        ];
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, deleted: list<string>, skipped: list<string>, updated_count: int, message: string}
     */
    protected static function bulkDeleteAdminSettingsExchangeRates(array $args): array
    {
        self::assertSettingsManage();
        $ids = [];
        foreach (['ids'] as $key) {
            foreach ((array) ($args[$key] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            throw new UserError('Provide at least one exchange rate id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = Currency::query()->find($id);
            if (! $row || $row->is_default) {
                $skipped[] = (string) $id;
                continue;
            }
            // Column is NOT NULL — deletes the rate row; we reset to identity (1.0).
            $current = $row->exchange_rate !== null ? (float) $row->exchange_rate : null;
            if ($current !== null && abs($current - 1.0) < 0.0000001) {
                $skipped[] = (string) $id;
                continue;
            }
            $row->exchange_rate = 1.0;
            $row->save();
            $deleted[] = (string) $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' exchange rate(s) cleared.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminSettingsExchangeRate(Currency $row): array
    {
        $rate = $row->exchange_rate !== null ? (float) $row->exchange_rate : null;

        return [
            'id' => (string) $row->id,
            'target_currency' => (string) $row->id,
            'targetCurrency' => (string) $row->id,
            'targetCurrencyCode' => (string) $row->code,
            'targetCurrencyName' => $row->name,
            'currency_code' => (string) $row->code,
            'currency_name' => $row->name,
            'rate' => $rate,
            'exchange_rate' => $rate,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminSettingsExchangeRates(array $args): array
    {
        self::assertSettingsManage();
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $q = Currency::query()->orderByDesc('id');
        if (! empty($args['target_currency'])) {
            $q->where('id', (int) $args['target_currency']);
        }
        if (isset($args['rate_from']) && $args['rate_from'] !== null) {
            $q->where('exchange_rate', '>=', (float) $args['rate_from']);
        }
        if (isset($args['rate_to']) && $args['rate_to'] !== null) {
            $q->where('exchange_rate', '<=', (float) $args['rate_to']);
        }

        return $q->limit($limit)->get()->map(fn (Currency $row) => self::mapAdminSettingsExchangeRate($row))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminSettingsExchangeRate(array $args): array
    {
        self::assertSettingsManage();
        $row = Currency::query()->find((int) $args['targetCurrency']);
        if (! $row) {
            throw new UserError('The selected target currency is invalid.');
        }
        if ($row->exchange_rate !== null && abs((float) $row->exchange_rate - 1.0) > 0.0000001) {
            throw new UserError('The selected target currency is invalid.');
        }
        $rate = (float) $args['rate'];
        if ($rate <= 0) {
            throw new UserError('Rate must be a positive number.');
        }
        $row->exchange_rate = $rate;
        $row->save();

        return self::mapAdminSettingsExchangeRate($row->fresh() ?? $row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminSettingsExchangeRate(array $args): array
    {
        self::assertSettingsManage();
        $row = Currency::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Exchange rate not found.');
        }
        $rate = (float) $args['rate'];
        if ($rate <= 0) {
            throw new UserError('Rate must be a positive number.');
        }
        $row->exchange_rate = $rate;
        $row->save();

        return self::mapAdminSettingsExchangeRate($row->fresh() ?? $row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function deleteAdminSettingsExchangeRate(array $args): array
    {
        self::assertSettingsManage();
        $row = Currency::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Exchange rate not found.');
        }
        if ($row->is_default) {
            throw new UserError('The default currency exchange rate cannot be deleted.');
        }
        $id = (string) $row->id;
        $row->exchange_rate = 1.0;
        $row->save();

        return ['ok' => true, 'id' => $id];
    }

    /**
     * Local fallback sync (no external provider required). Rates are relative to the default currency.
     *
     * @return array{ok: bool, success: bool, updated: int, message: string}
     */
    protected static function createAdminSettingsExchangeRateUpdateRates(): array
    {
        self::assertSettingsManage();
        $usdTable = [
            'USD' => 1.0,
            'EUR' => 0.92,
            'GBP' => 0.79,
            'INR' => 83.0,
            'JPY' => 150.0,
            'CAD' => 1.36,
            'AUD' => 1.52,
            'CHF' => 0.88,
        ];
        $default = Currency::query()->where('is_default', true)->first()
            ?? Currency::query()->orderBy('id')->first();
        $baseCode = strtoupper((string) ($default?->code ?: 'USD'));
        $baseUsd = $usdTable[$baseCode] ?? 1.0;
        $updated = 0;
        foreach (Currency::query()->orderBy('id')->cursor() as $row) {
            $code = strtoupper((string) $row->code);
            if ($default && (int) $row->id === (int) $default->id) {
                $row->exchange_rate = 1.0;
            } elseif (isset($usdTable[$code])) {
                $row->exchange_rate = round($usdTable[$code] / $baseUsd, 6);
            } else {
                continue;
            }
            $row->save();
            $updated++;
        }

        return [
            'ok' => true,
            'success' => true,
            'updated' => $updated,
            'message' => 'Exchange rates updated from the built-in rate table ('.$updated.' currencies).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminSettingsStore(Store $store): array
    {
        $hostname = null;
        $primary = $store->relationLoaded('urls')
            ? ($store->urls->firstWhere('is_primary', true) ?? $store->urls->first())
            : $store->primaryUrl();
        if ($primary) {
            $hostname = trim((string) ($primary->domain ?? ''));
        }

        return [
            'id' => (string) $store->id,
            'code' => (string) $store->code,
            'name' => $store->name,
            'hostname' => $hostname !== '' ? $hostname : null,
            'theme' => app(ThemeRegistry::class)->canonical($store->theme),
            'email' => $store->email,
            'is_default' => (bool) $store->is_default,
            'is_enabled' => (bool) $store->is_enabled,
            'status' => $store->is_enabled ? 1 : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminSettingsStores(array $args): array
    {
        self::assertSettingsManage();
        StoreBootstrap::ensure();
        $limit = max(1, min(100, (int) ($args['limit'] ?? 50)));
        $q = Store::query()->with('urls')->orderByDesc('id');
        $op = DbGrammar::likeOperator();
        $code = trim((string) ($args['code'] ?? ''));
        if ($code !== '') {
            $q->where('code', $op, '%'.$code.'%');
        }
        $name = trim((string) ($args['name'] ?? ''));
        if ($name !== '') {
            $q->where('name', $op, '%'.$name.'%');
        }
        $hostname = trim((string) ($args['hostname'] ?? ''));
        if ($hostname !== '') {
            $q->whereHas('urls', function ($uq) use ($hostname, $op) {
                $uq->where('domain', $op, '%'.$hostname.'%');
            });
        }

        return $q->limit($limit)->get()->map(fn (Store $row) => self::mapAdminSettingsStore($row))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminSettingsStore(array $args): array
    {
        self::assertSettingsManage();
        StoreBootstrap::ensure();
        $name = trim((string) $args['name']);
        if ($name === '') {
            throw new UserError('Name is required.');
        }
        $hostname = trim((string) ($args['hostname'] ?? ''));
        $shopUrl = trim((string) ($args['shopUrl'] ?? ''));
        if ($shopUrl === '' && $hostname !== '') {
            $shopUrl = 'https://'.preg_replace('#^https?://#i', '', $hostname);
        }
        if ($shopUrl === '') {
            $shopUrl = rtrim((string) config('app.url'), '/').'/'.Str::slug($name);
        }
        $payload = [
            'name' => $name,
            'email' => isset($args['email']) ? trim((string) $args['email']) : null,
            'theme' => isset($args['theme']) ? trim((string) $args['theme']) : 'default',
            'shop_url' => $shopUrl,
            'import_data' => false,
            'preset' => 'empty',
        ];
        try {
            $store = app(StoreService::class)->create($payload);
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }
        if (! empty($args['code']) && ! $store->is_default) {
            $store->code = StoreBootstrap::uniqueCode(trim((string) $args['code']), $store->id);
            $store->save();
        }
        $enabled = true;
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $enabled = ((int) $args['status']) === 1;
        } elseif (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $enabled = (bool) $args['enabled'];
        }
        if (! $enabled) {
            $store->is_enabled = false;
            $store->save();
        }

        return self::mapAdminSettingsStore($store->fresh(['urls']) ?? $store->load('urls'));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminSettingsStore(array $args): array
    {
        self::assertSettingsManage();
        $store = Store::query()->with('urls')->find((int) $args['id']);
        if (! $store) {
            throw new UserError('Store not found.');
        }
        $data = [];
        if (array_key_exists('name', $args) && $args['name'] !== null) {
            $data['name'] = trim((string) $args['name']);
        }
        if (array_key_exists('email', $args)) {
            $data['email'] = $args['email'] !== null ? trim((string) $args['email']) : null;
        }
        if (array_key_exists('theme', $args) && $args['theme'] !== null) {
            $data['theme'] = trim((string) $args['theme']);
        }
        if (array_key_exists('code', $args) && $args['code'] !== null) {
            $data['code'] = trim((string) $args['code']);
        }
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $data['is_enabled'] = ((int) $args['status']) === 1;
        } elseif (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $data['is_enabled'] = (bool) $args['enabled'];
        }
        $hostname = trim((string) ($args['hostname'] ?? ''));
        $shopUrl = trim((string) ($args['shopUrl'] ?? ''));
        if ($shopUrl === '' && $hostname !== '') {
            $shopUrl = 'https://'.preg_replace('#^https?://#i', '', $hostname);
        }
        if ($shopUrl !== '') {
            $data['shop_url'] = $shopUrl;
        }
        try {
            $store = app(StoreService::class)->update($store, $data);
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return self::mapAdminSettingsStore($store->fresh(['urls']) ?? $store->load('urls'));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function deleteAdminSettingsStore(array $args): array
    {
        self::assertSettingsManage();
        $store = Store::query()->find((int) $args['id']);
        if (! $store) {
            throw new UserError('Store not found.');
        }
        if (Store::query()->count() <= 1) {
            throw new UserError('The store must keep at least one store.');
        }
        if ($store->is_default) {
            throw new UserError('The default store cannot be deleted.');
        }
        $id = (string) $store->id;
        try {
            app(StoreService::class)->delete($store);
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, deleted: list<string>, skipped: list<string>, updated_count: int, message: string}
     */
    protected static function bulkDeleteAdminSettingsRoles(array $args): array
    {
        self::assertSuperAdmin();
        $ids = [];
        foreach (['ids'] as $key) {
            foreach ((array) ($args[$key] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            throw new UserError('Provide at least one role id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $role = Role::query()->find($id);
            if (! $role || $role->isSystem() || $role->admins()->exists() || Role::query()->count() <= 1) {
                $skipped[] = (string) $id;
                continue;
            }
            $role->permissions()->detach();
            $role->delete();
            $deleted[] = (string) $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' role(s) deleted.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, deleted: list<string>, skipped: list<string>, updated_count: int, message: string}
     */
    protected static function bulkDeleteAdminSettingsUsers(array $args): array
    {
        self::assertSuperAdmin();
        $ids = [];
        foreach (['ids'] as $key) {
            foreach ((array) ($args[$key] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            throw new UserError('Provide at least one user id.');
        }
        $actor = auth('admin')->user();
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = Admin::query()->with('roles')->find($id);
            if (
                ! $row
                || $row->isLastSuperAdmin()
                || ($actor instanceof Admin && (int) $actor->id === (int) $row->id)
            ) {
                $skipped[] = (string) $id;
                continue;
            }
            $row->roles()->detach();
            $row->tokens()->delete();
            $row->delete();
            $deleted[] = (string) $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' user(s) deleted.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, deleted: list<string>, skipped: list<string>, updated_count: int, message: string}
     */
    protected static function bulkDeleteAdminCustomers(array $args): array
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
            throw new UserError('Forbidden.');
        }
        $ids = [];
        foreach (['ids'] as $key) {
            foreach ((array) ($args[$key] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            throw new UserError('Provide at least one customer id.');
        }
        $deleted = [];
        $skipped = [];
        $anonymizer = app(CustomerAnonymizer::class);
        foreach ($ids as $id) {
            $row = Customer::query()->find($id);
            if (! $row) {
                $skipped[] = (string) $id;
                continue;
            }
            $active = $row->orders()
                ->whereIn('status', ['pending', 'pending_payment', 'processing', 'new'])
                ->exists();
            if ($active) {
                $skipped[] = (string) $id;
                continue;
            }
            try {
                wento_action('customer.delete', 'before', ['id' => $row->id]);
                $anonymizer->scrubPersonalRecords($row);
                $anonymizer->purgeUnlinkedPersonalData($row);
                $row->delete();
                wento_action('customer.delete', 'after', ['id' => $id]);
                $deleted[] = (string) $id;
            } catch (\Throwable) {
                $skipped[] = (string) $id;
            }
        }
        if ($deleted !== []) {
            app(CustomerService::class)->bumpListCache();
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' customer(s) deleted.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, updated_count: int}
     */
    protected static function bulkUpdateAdminCustomerStatus(array $args): array
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
            throw new UserError('Forbidden.');
        }
        $ids = [];
        foreach (['ids'] as $key) {
            foreach ((array) ($args[$key] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            throw new UserError('Provide at least one customer id.');
        }
        $enabled = true;
        if (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $enabled = (bool) $args['enabled'];
        } elseif (array_key_exists('value', $args) && $args['value'] !== null) {
            $enabled = ((int) $args['value']) === 1;
        } elseif (array_key_exists('status', $args) && $args['status'] !== null) {
            $enabled = ((int) $args['status']) === 1;
        }
        $updated = Customer::query()->whereIn('id', $ids)->update(['is_enabled' => $enabled]);
        if ($updated > 0) {
            app(CustomerService::class)->bumpListCache();
        }

        return ['ok' => true, 'updated_count' => (int) $updated];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function listAdminAppearanceThemes(): array
    {
        self::assertSettingsManage();
        \App\Domain\Store\StoreBootstrap::ensure();
        $registry = app(ThemeRegistry::class);
        $stores = Store::query()->orderBy('id')->get();
        $byTheme = [];
        foreach ($stores as $store) {
            $code = $registry->canonical($store->theme);
            $byTheme[$code][] = [
                'id' => (string) $store->id,
                'code' => (string) $store->code,
                'name' => $store->name,
            ];
        }
        $rows = [];
        foreach ($registry->all() as $theme) {
            $channels = $byTheme[$theme->name] ?? [];
            $active = $channels !== [];
            $rows[] = [
                'code' => $theme->name,
                'name' => $theme->name,
                'displayName' => $theme->displayName,
                'display_name' => $theme->displayName,
                'version' => $theme->version,
                'author' => $theme->author,
                'bundled' => (bool) $theme->bundled,
                'active' => $active,
                'status' => $active ? 'active' : 'installed',
                'stores' => $channels,
            ];
        }
        usort($rows, function (array $a, array $b) {
            if ($a['active'] !== $b['active']) {
                return $a['active'] ? -1 : 1;
            }

            return strcasecmp((string) $a['displayName'], (string) $b['displayName']);
        });

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function adminAppearanceThemeImpact(array $args): array
    {
        self::assertSettingsManage();
        $registry = app(ThemeRegistry::class);
        $code = $registry->canonical((string) $args['code']);
        if (! $registry->exists($code)) {
            throw new UserError('Theme is not installed.');
        }
        $storeIds = array_values(array_filter(array_map(
            static fn ($id) => (int) $id,
            is_array($args['storeIds'] ?? null) ? $args['storeIds'] : []
        )));
        $q = Store::query()->orderBy('id');
        if ($storeIds !== []) {
            $q->whereIn('id', $storeIds);
        }
        $would = [];
        $already = [];
        foreach ($q->get() as $store) {
            $channel = [
                'id' => (string) $store->id,
                'code' => (string) $store->code,
                'name' => $store->name,
            ];
            if ($registry->canonical($store->theme) === $code) {
                $already[] = $channel;
            } else {
                $would[] = $channel;
            }
        }

        return [
            'code' => $code,
            'would_switch' => $would,
            'already_active' => $already,
            'message' => count($would).' channel(s) would switch to '.$code.'.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminAppearanceThemeActivate(array $args): array
    {
        self::assertSettingsManage();
        $registry = app(ThemeRegistry::class);
        $code = $registry->canonical((string) $args['code']);
        if (! $registry->exists($code)) {
            throw new UserError('Theme is not installed.');
        }
        $storeIds = array_values(array_filter(array_map(
            static fn ($id) => (int) $id,
            is_array($args['storeIds'] ?? null) ? $args['storeIds'] : []
        )));
        if ($storeIds === []) {
            \App\Domain\Store\StoreBootstrap::ensure();
            $default = Store::query()->where('is_default', true)->first()
                ?? Store::query()->orderBy('id')->first();
            if (! $default) {
                throw new UserError('No channel found.');
            }
            $storeIds = [(int) $default->id];
        }
        $activated = [];
        foreach (Store::query()->whereIn('id', $storeIds)->get() as $store) {
            $store->update(['theme' => $code]);
            $activated[] = [
                'id' => (string) $store->id,
                'code' => (string) $store->code,
                'name' => $store->name,
            ];
        }
        if ($activated === []) {
            throw new UserError('No matching stores found.');
        }
        $label = $registry->get($code)?->displayName ?: $code;

        return [
            'code' => $code,
            'activatedOn' => $activated,
            'message' => $label.' activated on '.count($activated).' channel(s).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminMarketingSubscriber(NewsletterSubscriber $row): array
    {
        $subscribed = $row->is_subscribed ?? true;

        return [
            'id' => (string) $row->id,
            'email' => (string) $row->email,
            'is_subscribed' => (bool) $subscribed,
            'isSubscribed' => (bool) $subscribed,
            'created_at' => optional($row->created_at)?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminMarketingSubscribers(array $args): array
    {
        self::assertNewsletterManage();
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $q = NewsletterSubscriber::query()->orderByDesc('id');
        $term = trim((string) ($args['q'] ?? ''));
        if ($term !== '') {
            $op = DbGrammar::likeOperator();
            $q->where('email', $op, '%'.$term.'%');
        }

        return $q->limit($limit)->get()->map(fn (NewsletterSubscriber $row) => self::mapAdminMarketingSubscriber($row))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function deleteAdminMarketingSubscriber(array $args): array
    {
        self::assertNewsletterManage();
        $row = NewsletterSubscriber::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Subscriber not found.');
        }
        $id = (string) $row->id;
        $row->delete();

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, deleted: list<string>, skipped: list<string>, updated_count: int, message: string}
     */
    protected static function bulkDeleteAdminCustomerGroups(array $args): array
    {
        self::assertCustomerGroupsManage();
        $ids = [];
        foreach (['ids'] as $key) {
            foreach ((array) ($args[$key] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            throw new UserError('Provide at least one customer group id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $group = CustomerGroup::query()->find($id);
            if (! $group) {
                $skipped[] = (string) $id;
                continue;
            }
            if ($group->is_system) {
                $skipped[] = (string) $id;
                continue;
            }
            if (Customer::query()->where('customer_group', $group->code)->exists()) {
                $skipped[] = (string) $id;
                continue;
            }
            $group->delete();
            $deleted[] = (string) $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' customer group(s) deleted.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, deleted: list<string>, skipped: list<string>, updated_count: int, message: string}
     */
    protected static function bulkDeleteAdminMarketingSubscribers(array $args): array
    {
        self::assertNewsletterManage();
        $ids = [];
        foreach (['ids'] as $key) {
            foreach ((array) ($args[$key] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            throw new UserError('Provide at least one subscriber id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = NewsletterSubscriber::query()->find($id);
            if (! $row) {
                $skipped[] = (string) $id;
                continue;
            }
            $row->delete();
            $deleted[] = (string) $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' subscriber(s) deleted.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, deleted: list<string>, skipped: list<string>, updated_count: int, message: string}
     */
    protected static function bulkDeleteAdminMarketingCampaigns(array $args): array
    {
        self::assertNewsletterManage();
        $ids = [];
        foreach (['ids'] as $key) {
            foreach ((array) ($args[$key] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            throw new UserError('Provide at least one campaign id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = NewsletterCampaign::query()->find($id);
            if (! $row) {
                $skipped[] = (string) $id;
                continue;
            }
            $row->delete();
            $deleted[] = (string) $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' campaign(s) deleted.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminMarketingTemplate(EmailTemplate $row): array
    {
        $enabled = (bool) $row->is_enabled;

        return [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'subject' => (string) $row->subject,
            'content' => (string) $row->body,
            'body' => (string) $row->body,
            'status' => $enabled ? 'active' : 'inactive',
            'is_enabled' => $enabled,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminMarketingTemplates(array $args): array
    {
        self::assertNewsletterManage();
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $q = EmailTemplate::query()->orderByDesc('id');
        $name = trim((string) ($args['name'] ?? ''));
        if ($name !== '') {
            $op = DbGrammar::likeOperator();
            $q->where(function ($inner) use ($name, $op) {
                $inner->where('name', $op, '%'.$name.'%')
                    ->orWhere('code', $op, '%'.$name.'%')
                    ->orWhere('subject', $op, '%'.$name.'%');
            });
        }
        $status = strtolower(trim((string) ($args['status'] ?? '')));
        if (in_array($status, ['active', 'inactive'], true)) {
            $q->where('is_enabled', $status === 'active');
        }

        return $q->limit($limit)->get()->map(fn (EmailTemplate $row) => self::mapAdminMarketingTemplate($row))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminMarketingTemplate(array $args): array
    {
        self::assertNewsletterManage();
        $name = trim((string) $args['name']);
        $subject = trim((string) $args['subject']);
        $body = (string) ($args['content'] ?? $args['body'] ?? '');
        if ($name === '' || $subject === '') {
            throw new UserError('name and subject are required.');
        }
        $code = trim((string) ($args['code'] ?? ''));
        if ($code === '') {
            $code = Str::slug($name);
        }
        $code = Str::limit($code !== '' ? $code : 'template-'.uniqid(), 80, '');
        if (EmailTemplate::query()->where('code', $code)->exists()) {
            $code = $code.'-'.substr(uniqid(), -4);
        }
        $enabled = true;
        $status = strtolower(trim((string) ($args['status'] ?? '')));
        if ($status === 'inactive' || $status === 'draft') {
            $enabled = false;
        } elseif (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $enabled = (bool) $args['enabled'];
        }
        $row = EmailTemplate::query()->create([
            'code' => $code,
            'name' => $name,
            'subject' => $subject,
            'body' => $body,
            'is_enabled' => $enabled,
            'is_custom' => true,
        ]);

        return self::mapAdminMarketingTemplate($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminMarketingTemplate(array $args): array
    {
        self::assertNewsletterManage();
        $row = EmailTemplate::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Email template not found.');
        }
        if (array_key_exists('name', $args) && $args['name'] !== null) {
            $name = trim((string) $args['name']);
            if ($name === '') {
                throw new UserError('name cannot be empty.');
            }
            $row->name = $name;
        }
        if (array_key_exists('subject', $args) && $args['subject'] !== null) {
            $subject = trim((string) $args['subject']);
            if ($subject === '') {
                throw new UserError('subject cannot be empty.');
            }
            $row->subject = $subject;
        }
        if (array_key_exists('content', $args) || array_key_exists('body', $args)) {
            $row->body = (string) ($args['content'] ?? $args['body'] ?? $row->body);
        }
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $status = strtolower(trim((string) $args['status']));
            $row->is_enabled = ! in_array($status, ['inactive', 'draft'], true);
        } elseif (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $row->is_enabled = (bool) $args['enabled'];
        }
        $row->save();

        return self::mapAdminMarketingTemplate($row->fresh() ?? $row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function deleteAdminMarketingTemplate(array $args): array
    {
        self::assertNewsletterManage();
        $row = EmailTemplate::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Email template not found.');
        }
        if (! $row->is_custom && $row->event_code) {
            throw new UserError('System email templates cannot be deleted.');
        }
        $id = (string) $row->id;
        $row->delete();

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, deleted: list<string>, skipped: list<string>, updated_count: int, message: string}
     */
    protected static function bulkDeleteAdminMarketingTemplates(array $args): array
    {
        self::assertNewsletterManage();
        $ids = [];
        foreach (['ids'] as $key) {
            foreach ((array) ($args[$key] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            throw new UserError('Provide at least one template id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = EmailTemplate::query()->find($id);
            if (! $row) {
                $skipped[] = (string) $id;
                continue;
            }
            if (! $row->is_custom && $row->event_code) {
                $skipped[] = (string) $id;
                continue;
            }
            $row->delete();
            $deleted[] = (string) $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' template(s) deleted.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, deleted: list<string>, skipped: list<string>, updated_count: int, message: string}
     */
    protected static function bulkDeleteAdminMarketingEvents(array $args): array
    {
        self::assertNewsletterManage();
        $ids = [];
        foreach (['ids'] as $key) {
            foreach ((array) ($args[$key] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            throw new UserError('Provide at least one event id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = MarketingEvent::query()->find($id);
            if (! $row) {
                $skipped[] = (string) $id;
                continue;
            }
            NewsletterCampaign::query()->where('marketing_event_id', $row->id)->update(['marketing_event_id' => null]);
            $row->delete();
            $deleted[] = (string) $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' event(s) deleted.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminMarketingCampaign(NewsletterCampaign $row): array
    {
        $status = (string) ($row->status ?: 'draft');

        return [
            'id' => (string) $row->id,
            'name' => (string) $row->subject,
            'subject' => (string) $row->subject,
            'content' => (string) $row->html_body,
            'html_body' => (string) $row->html_body,
            'status' => $status,
            'statusFlag' => in_array($status, ['sent', 'active', '1'], true) ? 1 : 0,
            'sent_count' => (int) ($row->sent_count ?? 0),
            'sent_at' => optional($row->sent_at)?->toIso8601String(),
            'marketing_template_id' => null,
            'marketing_event_id' => $row->marketing_event_id !== null ? (string) $row->marketing_event_id : null,
            'marketingEventId' => $row->marketing_event_id !== null ? (string) $row->marketing_event_id : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminMarketingCampaigns(array $args): array
    {
        self::assertNewsletterManage();
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $q = NewsletterCampaign::query()->orderByDesc('id');
        $name = trim((string) ($args['name'] ?? ''));
        if ($name !== '') {
            $op = DbGrammar::likeOperator();
            $q->where('subject', $op, '%'.$name.'%');
        }
        $status = trim((string) ($args['status'] ?? ''));
        if ($status !== '') {
            if ($status === '1' || $status === 'active') {
                $q->whereIn('status', ['sent', 'active']);
            } elseif ($status === '0' || $status === 'inactive') {
                $q->whereNotIn('status', ['sent', 'active']);
            } else {
                $q->where('status', $status);
            }
        }

        return $q->limit($limit)->get()->map(fn (NewsletterCampaign $row) => self::mapAdminMarketingCampaign($row))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminMarketingCampaign(array $args): array
    {
        self::assertNewsletterManage();
        $subject = trim((string) ($args['subject'] ?? $args['name'] ?? ''));
        $body = (string) ($args['html_body'] ?? $args['content'] ?? '');
        if ($subject === '') {
            throw new UserError('subject/name is required.');
        }
        if (! empty($args['marketingTemplateId'])) {
            $template = EmailTemplate::query()->find((int) $args['marketingTemplateId']);
            if (! $template) {
                throw new UserError('Email template not found.');
            }
            if ($body === '') {
                $body = (string) $template->body;
            }
            if ($subject === '' || $subject === trim((string) ($args['name'] ?? ''))) {
                $subject = trim((string) ($args['subject'] ?? $template->subject));
            }
        }
        if ($body === '') {
            throw new UserError('content/html_body is required (or provide marketingTemplateId).');
        }
        $status = strtolower(trim((string) ($args['status'] ?? 'draft')));
        if ($status === '') {
            $status = 'draft';
        }
        $eventId = null;
        if (! empty($args['marketingEventId'])) {
            $event = MarketingEvent::query()->find((int) $args['marketingEventId']);
            if (! $event) {
                throw new UserError('Marketing event not found.');
            }
            $eventId = (int) $event->id;
        }
        $row = NewsletterCampaign::query()->create([
            'subject' => $subject,
            'html_body' => $body,
            'status' => $status,
            'sent_count' => 0,
            'marketing_event_id' => $eventId,
        ]);

        return self::mapAdminMarketingCampaign($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminMarketingCampaign(array $args): array
    {
        self::assertNewsletterManage();
        $row = NewsletterCampaign::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Campaign not found.');
        }
        if (array_key_exists('subject', $args) || array_key_exists('name', $args)) {
            $subject = trim((string) ($args['subject'] ?? $args['name'] ?? ''));
            if ($subject === '') {
                throw new UserError('subject/name cannot be empty.');
            }
            $row->subject = $subject;
        }
        if (array_key_exists('html_body', $args) || array_key_exists('content', $args)) {
            $row->html_body = (string) ($args['html_body'] ?? $args['content'] ?? $row->html_body);
        }
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $row->status = strtolower(trim((string) $args['status'])) ?: $row->status;
        }
        if (array_key_exists('marketingEventId', $args)) {
            if ($args['marketingEventId'] === null || $args['marketingEventId'] === '') {
                $row->marketing_event_id = null;
            } else {
                $event = MarketingEvent::query()->find((int) $args['marketingEventId']);
                if (! $event) {
                    throw new UserError('Marketing event not found.');
                }
                $row->marketing_event_id = (int) $event->id;
            }
        }
        $row->save();

        return self::mapAdminMarketingCampaign($row->fresh() ?? $row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function deleteAdminMarketingCampaign(array $args): array
    {
        self::assertNewsletterManage();
        $row = NewsletterCampaign::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Campaign not found.');
        }
        $id = (string) $row->id;
        $row->delete();

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminMarketingCampaignSend(array $args): array
    {
        self::assertNewsletterManage();
        $row = NewsletterCampaign::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Campaign not found.');
        }
        $mailer = app(ShopMailer::class);
        $sent = 0;
        NewsletterSubscriber::query()
            ->where(function ($q) {
                $q->where('is_subscribed', true)->orWhereNull('is_subscribed');
            })
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($mailer, $row, &$sent) {
                foreach ($rows as $subscriber) {
                    if ($mailer->sendHtml((string) $subscriber->email, (string) $row->subject, (string) $row->html_body, 'newsletter')) {
                        $sent++;
                    }
                }
            });
        $row->update([
            'status' => 'sent',
            'sent_count' => $sent,
            'sent_at' => now(),
        ]);
        $fresh = $row->fresh() ?? $row;

        return [
            'ok' => true,
            'id' => (string) $fresh->id,
            'sent_count' => $sent,
            'message' => 'Campaign sent to '.$sent.' subscriber'.($sent === 1 ? '' : 's').'.',
            'campaign' => self::mapAdminMarketingCampaign($fresh),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminMarketingEvent(MarketingEvent $row): array
    {
        return [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'description' => $row->description,
            'date' => optional($row->date)?->format('Y-m-d') ?? (string) $row->date,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminMarketingEvents(array $args): array
    {
        self::assertNewsletterManage();
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $q = MarketingEvent::query()->orderByDesc('id');
        $name = trim((string) ($args['name'] ?? ''));
        if ($name !== '') {
            $op = DbGrammar::likeOperator();
            $q->where('name', $op, '%'.$name.'%');
        }
        $from = trim((string) ($args['date_from'] ?? ''));
        if ($from !== '') {
            $q->whereDate('date', '>=', $from);
        }
        $to = trim((string) ($args['date_to'] ?? ''));
        if ($to !== '') {
            $q->whereDate('date', '<=', $to);
        }

        return $q->limit($limit)->get()->map(fn (MarketingEvent $row) => self::mapAdminMarketingEvent($row))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminMarketingEvent(array $args): array
    {
        self::assertNewsletterManage();
        $name = trim((string) $args['name']);
        $description = trim((string) $args['description']);
        $date = trim((string) $args['date']);
        if ($name === '' || $description === '' || $date === '') {
            throw new UserError('name, description, and date are required.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new UserError('date must be YYYY-MM-DD.');
        }
        $row = MarketingEvent::query()->create([
            'name' => $name,
            'description' => $description,
            'date' => $date,
        ]);

        return self::mapAdminMarketingEvent($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminMarketingEvent(array $args): array
    {
        self::assertNewsletterManage();
        $row = MarketingEvent::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Marketing event not found.');
        }
        if (array_key_exists('name', $args) && $args['name'] !== null) {
            $name = trim((string) $args['name']);
            if ($name === '') {
                throw new UserError('name cannot be empty.');
            }
            $row->name = $name;
        }
        if (array_key_exists('description', $args) && $args['description'] !== null) {
            $row->description = trim((string) $args['description']);
        }
        if (array_key_exists('date', $args) && $args['date'] !== null) {
            $date = trim((string) $args['date']);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new UserError('date must be YYYY-MM-DD.');
            }
            $row->date = $date;
        }
        $row->save();

        return self::mapAdminMarketingEvent($row->fresh() ?? $row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function deleteAdminMarketingEvent(array $args): array
    {
        self::assertNewsletterManage();
        $row = MarketingEvent::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Marketing event not found.');
        }
        NewsletterCampaign::query()->where('marketing_event_id', $row->id)->update(['marketing_event_id' => null]);
        $id = (string) $row->id;
        $row->delete();

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminSettingsReturnReason(ReturnReason $row): array
    {
        return [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'is_enabled' => (bool) $row->is_enabled,
            'status' => $row->is_enabled ? 1 : 0,
            'position' => (int) ($row->position ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminSettingsReturnReasons(array $args): array
    {
        self::assertOrdersManage();
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $q = ReturnReason::query()->orderBy('position')->orderBy('id');
        $term = trim((string) ($args['q'] ?? ''));
        if ($term !== '') {
            $op = DbGrammar::likeOperator();
            $q->where(function ($inner) use ($term, $op) {
                $inner->where('code', $op, '%'.$term.'%')
                    ->orWhere('name', $op, '%'.$term.'%');
            });
        }
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $q->where('is_enabled', ((int) $args['status']) === 1);
        }

        return $q->limit($limit)->get()->map(fn (ReturnReason $row) => self::mapAdminSettingsReturnReason($row))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminSettingsReturnReason(array $args): array
    {
        self::assertOrdersManage();
        $name = trim((string) $args['name']);
        if ($name === '') {
            throw new UserError('name is required.');
        }
        $code = trim((string) ($args['code'] ?? ''));
        if ($code === '') {
            $code = Str::slug($name);
        }
        $code = Str::limit($code !== '' ? $code : 'reason-'.uniqid(), 80, '');
        if (ReturnReason::query()->where('code', $code)->exists()) {
            $code = $code.'-'.substr(uniqid(), -4);
        }
        $enabled = true;
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $enabled = ((int) $args['status']) === 1;
        } elseif (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $enabled = (bool) $args['enabled'];
        }
        $row = ReturnReason::query()->create([
            'code' => $code,
            'name' => $name,
            'is_enabled' => $enabled,
            'position' => (int) ($args['position'] ?? ((int) (ReturnReason::query()->max('position') ?? 0) + 1)),
        ]);

        return self::mapAdminSettingsReturnReason($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminSettingsReturnReason(array $args): array
    {
        self::assertOrdersManage();
        $row = ReturnReason::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Return reason not found.');
        }
        if (array_key_exists('name', $args) && $args['name'] !== null) {
            $name = trim((string) $args['name']);
            if ($name === '') {
                throw new UserError('name cannot be empty.');
            }
            $row->name = $name;
        }
        if (array_key_exists('position', $args) && $args['position'] !== null) {
            $row->position = (int) $args['position'];
        }
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $row->is_enabled = ((int) $args['status']) === 1;
        } elseif (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $row->is_enabled = (bool) $args['enabled'];
        }
        $row->save();

        return self::mapAdminSettingsReturnReason($row->fresh() ?? $row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function deleteAdminSettingsReturnReason(array $args): array
    {
        self::assertOrdersManage();
        $row = ReturnReason::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Return reason not found.');
        }
        if (ReturnReason::query()->count() <= 1) {
            throw new UserError('The store must keep at least one return reason.');
        }
        $id = (string) $row->id;
        $row->delete();

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, deleted: list<string>, skipped: list<string>, updated_count: int, message: string}
     */
    protected static function bulkDeleteAdminSettingsReturnReasons(array $args): array
    {
        self::assertOrdersManage();
        $ids = collect($args['ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = ReturnReason::query()->find($id);
            if (! $row || ReturnReason::query()->count() <= 1) {
                $skipped[] = (string) $id;
                continue;
            }
            $row->delete();
            $deleted[] = (string) $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' return reason(s) deleted.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, updated_count: int}
     */
    protected static function bulkUpdateAdminSettingsReturnReasonStatus(array $args): array
    {
        self::assertOrdersManage();
        $ids = collect($args['ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $enabled = ((int) $args['status']) === 1;
        $updated = ReturnReason::query()->whereIn('id', $ids)->update(['is_enabled' => $enabled]);

        return ['ok' => true, 'updated_count' => (int) $updated];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminSettingsDataTransferImport(DataTransferImport $row, bool $withDetails = false): array
    {
        $errors = is_array($row->errors_json) ? array_values(array_map('strval', $row->errors_json)) : [];
        $summary = is_array($row->summary_json) ? json_encode($row->summary_json) : null;

        return [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'type' => (string) $row->code,
            'action' => (string) $row->action,
            'state' => (string) $row->state,
            'file_name' => $row->file_name,
            'fileName' => $row->file_name,
            'processedRowsCount' => (int) $row->processed_rows_count,
            'processed_rows_count' => (int) $row->processed_rows_count,
            'invalidRowsCount' => (int) $row->invalid_rows_count,
            'invalid_rows_count' => (int) $row->invalid_rows_count,
            'errorsCount' => (int) $row->errors_count,
            'errors_count' => (int) $row->errors_count,
            'errors' => $withDetails ? $errors : null,
            'summary' => $withDetails ? $summary : null,
            'created_at' => optional($row->created_at)?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminSettingsDataTransferImports(array $args): array
    {
        self::assertSettingsManage();
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $q = DataTransferImport::query()->orderByDesc('id');
        $code = trim((string) ($args['code'] ?? $args['type'] ?? ''));
        if ($code !== '') {
            $q->where('code', $code);
        }
        $action = trim((string) ($args['action'] ?? ''));
        if ($action !== '') {
            $q->where('action', $action);
        }
        $state = trim((string) ($args['state'] ?? ''));
        if ($state !== '') {
            $q->where('state', $state);
        }

        return $q->limit($limit)->get()->map(fn (DataTransferImport $row) => self::mapAdminSettingsDataTransferImport($row, false))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminSettingsDataTransferImport(array $args): array
    {
        self::assertSettingsManage();
        $code = strtolower(trim((string) $args['code']));
        $action = strtolower(trim((string) ($args['action'] ?? 'append')));
        $csv = (string) ($args['csvContent'] ?? '');
        if ($code === '' || $csv === '') {
            throw new UserError('code and csvContent are required.');
        }
        if (! in_array($action, ['append', 'delete'], true)) {
            throw new UserError('action must be append or delete.');
        }
        $allowed = ['products', 'customers', 'tax_rates', 'categories'];
        if (! in_array($code, $allowed, true)) {
            throw new UserError('code must be one of: '.implode(', ', $allowed).'.');
        }
        $row = DataTransferImport::query()->create([
            'code' => $code,
            'action' => $action,
            'state' => 'pending',
            'file_name' => trim((string) ($args['fileName'] ?? $code.'-import.csv')) ?: $code.'-import.csv',
            'csv_content' => $csv,
            'processed_rows_count' => 0,
            'invalid_rows_count' => 0,
            'errors_count' => 0,
            'errors_json' => [],
            'summary_json' => null,
        ]);

        return self::mapAdminSettingsDataTransferImport($row, true);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function validateAdminSettingsDataTransferImport(array $args): array
    {
        self::assertSettingsManage();
        $id = (int) ($args['importId'] ?? $args['id'] ?? 0);
        $row = DataTransferImport::query()->find($id);
        if (! $row) {
            throw new UserError('Import not found.');
        }
        if (in_array($row->state, ['cancelled', 'completed'], true)) {
            throw new UserError('Cannot validate a '.$row->state.' import.');
        }
        $errors = [];
        $invalid = 0;
        $rowsCount = 0;
        try {
            $parsed = CatalogCsvImport::parse((string) $row->csv_content, ',');
            $header = $parsed['header'] ?? [];
            $rows = $parsed['rows'] ?? [];
            $rowsCount = count($rows);
            if ($header === []) {
                $errors[] = 'CSV header row is missing.';
            }
            if ($rowsCount < 1) {
                $errors[] = 'CSV has no data rows.';
                $invalid = 1;
            }
            if ($row->code === 'products') {
                $normalized = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
                foreach (['sku', 'name'] as $required) {
                    if (! in_array($required, $normalized, true)) {
                        $errors[] = 'Missing required column: '.$required;
                    }
                }
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
            $invalid = max(1, $invalid);
        }
        $isValid = $errors === [];
        $row->update([
            'state' => $isValid ? 'validated' : 'failed',
            'errors_count' => count($errors),
            'invalid_rows_count' => $invalid,
            'errors_json' => $errors,
            'summary_json' => [
                'rows' => $rowsCount,
                'validated_at' => now()->toIso8601String(),
            ],
        ]);
        $fresh = $row->fresh() ?? $row;

        return [
            'ok' => true,
            'isValid' => $isValid,
            'errorsCount' => count($errors),
            'invalidRowsCount' => $invalid,
            'import' => self::mapAdminSettingsDataTransferImport($fresh, true),
            'message' => $isValid ? 'Import validated successfully.' : 'Import validation failed.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function advanceAdminSettingsDataTransferImport(array $args, string $nextState): array
    {
        self::assertSettingsManage();
        $row = DataTransferImport::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Import not found.');
        }
        $required = match ($nextState) {
            'processing' => 'validated',
            'linked' => 'processing',
            'indexed' => 'linked',
            default => null,
        };
        if ($required !== null && $row->state !== $required) {
            throw new UserError('Import must be in "'.$required.'" state before '.$nextState.'.');
        }
        $processed = (int) $row->processed_rows_count;
        if ($nextState === 'processing') {
            try {
                $parsed = CatalogCsvImport::parse((string) $row->csv_content, ',');
                $processed = count($parsed['rows'] ?? []);
            } catch (\Throwable) {
                $processed = 0;
            }
        }
        $finalState = $nextState === 'indexed' ? 'completed' : $nextState;
        $row->update([
            'state' => $finalState,
            'processed_rows_count' => $processed,
            'summary_json' => array_merge(is_array($row->summary_json) ? $row->summary_json : [], [
                $nextState.'_at' => now()->toIso8601String(),
                'processed_rows' => $processed,
            ]),
        ]);
        $fresh = $row->fresh() ?? $row;

        return [
            'ok' => true,
            'state' => (string) $fresh->state,
            'processedRowsCount' => (int) $fresh->processed_rows_count,
            'import' => self::mapAdminSettingsDataTransferImport($fresh, true),
            'message' => 'Import advanced to '.$fresh->state.'.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function cancelAdminSettingsDataTransferImport(array $args): array
    {
        self::assertSettingsManage();
        $row = DataTransferImport::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Import not found.');
        }
        if (in_array($row->state, ['completed', 'cancelled'], true)) {
            throw new UserError('Cannot cancel a '.$row->state.' import.');
        }
        $row->update(['state' => 'cancelled']);
        $fresh = $row->fresh() ?? $row;

        return [
            'ok' => true,
            'state' => 'cancelled',
            'processedRowsCount' => (int) $fresh->processed_rows_count,
            'import' => self::mapAdminSettingsDataTransferImport($fresh, true),
            'message' => 'Import cancelled.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function deleteAdminSettingsDataTransferImport(array $args): array
    {
        self::assertSettingsManage();
        $row = DataTransferImport::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Import not found.');
        }
        $id = (string) $row->id;
        $row->delete();

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminRmaStatus(ReturnStatus $row, ?string $message = null): array
    {
        return [
            'id' => (string) $row->id,
            'title' => (string) $row->title,
            'color' => (string) ($row->color ?: '#6B7280'),
            'status' => $row->is_enabled ? 1 : 0,
            'is_enabled' => (bool) $row->is_enabled,
            'default' => $row->is_default ? 1 : 0,
            'is_default' => (bool) $row->is_default,
            'position' => (int) ($row->position ?? 0),
            'message' => $message,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminRmaStatuses(array $args): array
    {
        self::assertOrdersManage();
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $q = ReturnStatus::query()->orderBy('position')->orderBy('id');
        $term = trim((string) ($args['q'] ?? ''));
        if ($term !== '') {
            $op = DbGrammar::likeOperator();
            $q->where('title', $op, '%'.$term.'%');
        }
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $q->where('is_enabled', ((int) $args['status']) === 1);
        }

        return $q->limit($limit)->get()->map(fn (ReturnStatus $row) => self::mapAdminRmaStatus($row))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminRmaStatus(array $args): array
    {
        self::assertOrdersManage();
        $title = trim((string) $args['title']);
        if ($title === '') {
            throw new UserError('title is required.');
        }
        $color = trim((string) ($args['color'] ?? '#6B7280')) ?: '#6B7280';
        $enabled = true;
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $enabled = ((int) $args['status']) === 1;
        }
        $row = ReturnStatus::query()->create([
            'title' => $title,
            'color' => $color,
            'is_enabled' => $enabled,
            'is_default' => false,
            'position' => (int) ($args['position'] ?? ((int) (ReturnStatus::query()->max('position') ?? 0) + 1)),
        ]);

        return self::mapAdminRmaStatus($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminRmaStatus(array $args): array
    {
        self::assertOrdersManage();
        $row = ReturnStatus::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Return status not found.');
        }
        if (array_key_exists('title', $args) && $args['title'] !== null) {
            $title = trim((string) $args['title']);
            if ($title === '') {
                throw new UserError('title cannot be empty.');
            }
            $row->title = $title;
        }
        if (array_key_exists('color', $args) && $args['color'] !== null) {
            $row->color = trim((string) $args['color']) ?: $row->color;
        }
        if (array_key_exists('position', $args) && $args['position'] !== null) {
            $row->position = (int) $args['position'];
        }
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $row->is_enabled = ((int) $args['status']) === 1;
        }
        $row->save();

        return self::mapAdminRmaStatus($row->fresh() ?? $row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function deleteAdminRmaStatus(array $args): array
    {
        self::assertOrdersManage();
        $row = ReturnStatus::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Return status not found.');
        }
        if ($row->is_default) {
            throw new UserError('Built-in return statuses cannot be deleted.');
        }
        $mapped = self::mapAdminRmaStatus($row, 'Return status deleted.');
        $row->delete();

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, deleted: list<string>, skipped: list<string>, updated_count: int, message: string}
     */
    protected static function bulkDeleteAdminRmaStatuses(array $args): array
    {
        self::assertOrdersManage();
        $ids = collect($args['ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = ReturnStatus::query()->find($id);
            if (! $row || $row->is_default) {
                $skipped[] = (string) $id;
                continue;
            }
            $row->delete();
            $deleted[] = (string) $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' return status(es) deleted.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, updated_count: int}
     */
    protected static function bulkUpdateAdminRmaStatus(array $args): array
    {
        self::assertOrdersManage();
        $ids = collect($args['ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $enabled = ((int) $args['status']) === 1;
        $updated = ReturnStatus::query()->whereIn('id', $ids)->update(['is_enabled' => $enabled]);

        return ['ok' => true, 'updated_count' => (int) $updated];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminRmaRule(ReturnRule $row, ?string $message = null): array
    {
        return [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'description' => $row->description,
            'returnPeriod' => (int) $row->return_period,
            'return_period' => (int) $row->return_period,
            'status' => $row->is_enabled ? 1 : 0,
            'is_enabled' => (bool) $row->is_enabled,
            'default' => $row->is_default ? 1 : 0,
            'is_default' => (bool) $row->is_default,
            'message' => $message,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminRmaRules(array $args): array
    {
        self::assertOrdersManage();
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $q = ReturnRule::query()->orderByDesc('is_default')->orderBy('id');
        $term = trim((string) ($args['q'] ?? ''));
        if ($term !== '') {
            $op = DbGrammar::likeOperator();
            $q->where(function ($inner) use ($term, $op) {
                $inner->where('name', $op, '%'.$term.'%')
                    ->orWhere('description', $op, '%'.$term.'%');
            });
        }
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $q->where('is_enabled', ((int) $args['status']) === 1);
        }

        return $q->limit($limit)->get()->map(fn (ReturnRule $row) => self::mapAdminRmaRule($row))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminRmaRule(array $args): array
    {
        self::assertOrdersManage();
        $name = trim((string) $args['name']);
        if ($name === '') {
            throw new UserError('name is required.');
        }
        $period = max(1, (int) ($args['returnPeriod'] ?? 30));
        $enabled = true;
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $enabled = ((int) $args['status']) === 1;
        }
        $isDefault = array_key_exists('default', $args) && $args['default'] !== null
            ? ((int) $args['default']) === 1
            : false;
        if ($isDefault) {
            ReturnRule::query()->where('is_default', true)->update(['is_default' => false]);
        }
        $row = ReturnRule::query()->create([
            'name' => $name,
            'description' => isset($args['description']) ? trim((string) $args['description']) : null,
            'return_period' => $period,
            'is_enabled' => $enabled,
            'is_default' => $isDefault,
        ]);

        return self::mapAdminRmaRule($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminRmaRule(array $args): array
    {
        self::assertOrdersManage();
        $row = ReturnRule::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Return rule not found.');
        }
        if (array_key_exists('name', $args) && $args['name'] !== null) {
            $name = trim((string) $args['name']);
            if ($name === '') {
                throw new UserError('name cannot be empty.');
            }
            $row->name = $name;
        }
        if (array_key_exists('description', $args)) {
            $row->description = $args['description'] !== null ? trim((string) $args['description']) : null;
        }
        if (array_key_exists('returnPeriod', $args) && $args['returnPeriod'] !== null) {
            $row->return_period = max(1, (int) $args['returnPeriod']);
        }
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $row->is_enabled = ((int) $args['status']) === 1;
        }
        if (array_key_exists('default', $args) && $args['default'] !== null) {
            $isDefault = ((int) $args['default']) === 1;
            if ($isDefault) {
                ReturnRule::query()->where('id', '!=', $row->id)->where('is_default', true)->update(['is_default' => false]);
            }
            $row->is_default = $isDefault;
        }
        $row->save();

        return self::mapAdminRmaRule($row->fresh() ?? $row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function deleteAdminRmaRule(array $args): array
    {
        self::assertOrdersManage();
        $row = ReturnRule::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Return rule not found.');
        }
        if ($row->is_default && ReturnRule::query()->where('is_default', true)->count() <= 1) {
            throw new UserError('The store must keep at least one default return rule.');
        }
        $mapped = self::mapAdminRmaRule($row, 'Return rule deleted.');
        $row->delete();

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, deleted: list<string>, skipped: list<string>, updated_count: int, message: string}
     */
    protected static function bulkDeleteAdminRmaRules(array $args): array
    {
        self::assertOrdersManage();
        $ids = collect($args['ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = ReturnRule::query()->find($id);
            if (! $row) {
                $skipped[] = (string) $id;
                continue;
            }
            if ($row->is_default && ReturnRule::query()->where('is_default', true)->count() <= 1) {
                $skipped[] = (string) $id;
                continue;
            }
            $row->delete();
            $deleted[] = (string) $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' return rule(s) deleted.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, updated_count: int}
     */
    protected static function bulkUpdateAdminRmaRuleStatus(array $args): array
    {
        self::assertOrdersManage();
        $ids = collect($args['ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $enabled = ((int) $args['status']) === 1;
        $updated = ReturnRule::query()->whereIn('id', $ids)->update(['is_enabled' => $enabled]);

        return ['ok' => true, 'updated_count' => (int) $updated];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminMarketingSitemap(MarketingSitemap $row, bool $withGenerated = false): array
    {
        $channels = is_array($row->store_ids) ? array_values(array_map('strval', $row->store_ids)) : [];
        $files = is_array($row->generated_files) ? $row->generated_files : [];
        $urls = [];
        foreach ($files as $file) {
            if (is_array($file) && ! empty($file['index_url'])) {
                $urls[] = (string) $file['index_url'];
            }
        }
        $path = (string) $row->path;
        if ($path === '' || $path[0] !== '/') {
            $path = '/'.$path;
        }
        if (! str_ends_with($path, '/')) {
            $path .= '/';
        }

        return [
            'id' => (string) $row->id,
            'fileName' => (string) $row->file_name,
            'file_name' => (string) $row->file_name,
            'path' => $path,
            'stores' => $channels,
            'urls' => $urls,
            'generatedAt' => optional($row->generated_at)?->toIso8601String(),
            'generated_at' => optional($row->generated_at)?->toIso8601String(),
            'generatedFiles' => $withGenerated && $files !== [] ? json_encode($files) : null,
            'generated_files' => $withGenerated && $files !== [] ? json_encode($files) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminMarketingSitemaps(array $args, bool $withGenerated = false): array
    {
        self::assertSettingsManage();
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));

        return MarketingSitemap::query()
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (MarketingSitemap $row) => self::mapAdminMarketingSitemap($row, $withGenerated))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminMarketingSitemap(array $args): array
    {
        self::assertSettingsManage();
        $fileName = trim((string) $args['fileName']);
        $path = trim((string) $args['path']);
        $channels = collect($args['stores'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($fileName === '' || ! preg_match('/^[A-Za-z0-9._-]+\.xml$/', $fileName)) {
            throw new UserError('fileName must be a valid .xml file name.');
        }
        if ($path === '' || $path[0] !== '/' || ! str_ends_with($path, '/') || str_contains($path, '//')) {
            throw new UserError('path must start and end with / and contain no //.');
        }
        if ($channels === []) {
            throw new UserError('At least one channel is required.');
        }
        foreach ($channels as $storeId) {
            if (! Store::query()->whereKey($storeId)->exists()) {
                throw new UserError('Unknown store id: '.$storeId);
            }
        }
        $row = MarketingSitemap::query()->create([
            'file_name' => $fileName,
            'path' => $path,
            'store_ids' => array_map('strval', $channels),
            'generated_at' => null,
            'generated_files' => [],
        ]);

        return self::mapAdminMarketingSitemap($row, true);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminMarketingSitemap(array $args): array
    {
        self::assertSettingsManage();
        $row = MarketingSitemap::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Sitemap not found.');
        }
        if (array_key_exists('fileName', $args) && $args['fileName'] !== null) {
            $fileName = trim((string) $args['fileName']);
            if ($fileName === '' || ! preg_match('/^[A-Za-z0-9._-]+\.xml$/', $fileName)) {
                throw new UserError('fileName must be a valid .xml file name.');
            }
            $row->file_name = $fileName;
        }
        if (array_key_exists('path', $args) && $args['path'] !== null) {
            $path = trim((string) $args['path']);
            if ($path === '' || $path[0] !== '/' || ! str_ends_with($path, '/') || str_contains($path, '//')) {
                throw new UserError('path must start and end with / and contain no //.');
            }
            $row->path = $path;
        }
        if (array_key_exists('stores', $args) && $args['stores'] !== null) {
            $channels = collect($args['stores'])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
            if ($channels === []) {
                throw new UserError('At least one channel is required.');
            }
            foreach ($channels as $storeId) {
                if (! Store::query()->whereKey($storeId)->exists()) {
                    throw new UserError('Unknown store id: '.$storeId);
                }
            }
            $row->store_ids = array_map('strval', $channels);
        }
        $row->save();

        return self::mapAdminMarketingSitemap($row->fresh() ?? $row, true);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function deleteAdminMarketingSitemap(array $args): array
    {
        self::assertSettingsManage();
        $row = MarketingSitemap::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Sitemap not found.');
        }
        $id = (string) $row->id;
        $row->delete();

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function generateAdminMarketingSitemap(array $args): array
    {
        self::assertSettingsManage();
        $row = MarketingSitemap::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Sitemap not found.');
        }
        $channels = is_array($row->store_ids) ? array_values(array_map('intval', $row->store_ids)) : [];
        if ($channels === []) {
            throw new UserError('Sitemap covers no stores.');
        }
        $enabled = ShopSetting::bool('seo.sitemap_enabled', true);
        $files = [];
        $paths = [];
        $indexFile = null;
        if ($enabled) {
            $builder = app(SitemapBuilder::class);
            $doc = $builder->document();
            $xml = (string) ($doc['xml'] ?? '');
            $relative = trim((string) $row->path, '/');
            $dir = $relative === '' ? 'sitemaps' : 'sitemaps/'.$relative;
            foreach ($channels as $storeId) {
                $store = Store::query()->find($storeId);
                if (! $store) {
                    continue;
                }
                $channelDir = $dir.'/'.$storeId;
                $indexPath = $channelDir.'/'.$row->file_name;
                Storage::disk('public')->put($indexPath, $xml);
                $indexUrl = Storage::disk('public')->url($indexPath);
                $files[] = [
                    'store_id' => (string) $storeId,
                    'hostname' => (string) ($store->name ?? 'store-'.$storeId),
                    'index' => $indexPath,
                    'index_url' => $indexUrl,
                    'sitemaps' => [$indexPath],
                ];
                $paths[] = $indexPath;
                $indexFile = $indexFile ?? $indexPath;
            }
            $row->generated_at = now();
            $row->generated_files = $files;
            $row->save();
        }

        $fresh = $row->fresh() ?? $row;

        return [
            'ok' => true,
            'generatedAt' => optional($fresh->generated_at)?->toIso8601String(),
            'indexFile' => $indexFile,
            'generatedSitemaps' => $paths,
            'sitemap' => self::mapAdminMarketingSitemap($fresh, true),
            'message' => $enabled
                ? 'Sitemap generated for '.count($files).' channel(s).'
                : 'Sitemap generation is disabled in configuration; no files written.',
        ];
    }


    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminRmaCustomField(ReturnCustomField $row, ?string $message = null): array
    {
        $options = [];
        foreach (is_array($row->options) ? $row->options : [] as $i => $opt) {
            if (! is_array($opt)) {
                continue;
            }
            $options[] = [
                'id' => (string) ($opt['id'] ?? ($i + 1)),
                'name' => (string) ($opt['name'] ?? $opt['label'] ?? ''),
                'value' => (string) ($opt['value'] ?? $opt['name'] ?? ''),
            ];
        }

        return [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'label' => (string) $row->label,
            'type' => (string) $row->type,
            'isRequired' => $row->is_required ? 1 : 0,
            'is_required' => (bool) $row->is_required,
            'position' => (int) ($row->position ?? 0),
            'inputValidation' => $row->input_validation,
            'status' => $row->is_enabled ? 1 : 0,
            'is_enabled' => (bool) $row->is_enabled,
            'options' => $options,
            'message' => $message,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminRmaCustomFields(array $args): array
    {
        self::assertOrdersManage();
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $q = ReturnCustomField::query()->orderBy('position')->orderBy('id');
        $term = trim((string) ($args['q'] ?? ''));
        if ($term !== '') {
            $op = DbGrammar::likeOperator();
            $q->where(function ($inner) use ($term, $op) {
                $inner->where('code', $op, '%'.$term.'%')
                    ->orWhere('label', $op, '%'.$term.'%');
            });
        }
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $q->where('is_enabled', ((int) $args['status']) === 1);
        }

        return $q->limit($limit)->get()->map(fn (ReturnCustomField $row) => self::mapAdminRmaCustomField($row))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminRmaCustomField(array $args): array
    {
        self::assertOrdersManage();
        $label = trim((string) $args['label']);
        if ($label === '') {
            throw new UserError('label is required.');
        }
        $type = strtolower(trim((string) ($args['type'] ?? 'text')));
        $allowed = ['text', 'textarea', 'select', 'multiselect', 'checkbox', 'radio'];
        if (! in_array($type, $allowed, true)) {
            throw new UserError('type must be one of: '.implode(', ', $allowed).'.');
        }
        $code = trim((string) ($args['code'] ?? ''));
        if ($code === '') {
            $code = Str::slug($label, '_');
        }
        $code = Str::limit($code !== '' ? $code : 'field_'.uniqid(), 80, '');
        if (ReturnCustomField::query()->where('code', $code)->exists()) {
            $code = $code.'_'.substr(uniqid(), -4);
        }
        $options = [];
        if (! empty($args['optionsJson'])) {
            $decoded = json_decode((string) $args['optionsJson'], true);
            if (! is_array($decoded)) {
                throw new UserError('optionsJson must be a JSON array.');
            }
            $options = $decoded;
        }
        $row = ReturnCustomField::query()->create([
            'code' => $code,
            'label' => $label,
            'type' => $type,
            'is_required' => array_key_exists('isRequired', $args) && $args['isRequired'] !== null
                ? ((int) $args['isRequired']) === 1
                : false,
            'position' => (int) ($args['position'] ?? ((int) (ReturnCustomField::query()->max('position') ?? 0) + 1)),
            'input_validation' => isset($args['inputValidation']) ? trim((string) $args['inputValidation']) : null,
            'is_enabled' => array_key_exists('status', $args) && $args['status'] !== null
                ? ((int) $args['status']) === 1
                : true,
            'options' => $options,
        ]);

        return self::mapAdminRmaCustomField($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminRmaCustomField(array $args): array
    {
        self::assertOrdersManage();
        $row = ReturnCustomField::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Return custom field not found.');
        }
        if (array_key_exists('label', $args) && $args['label'] !== null) {
            $label = trim((string) $args['label']);
            if ($label === '') {
                throw new UserError('label cannot be empty.');
            }
            $row->label = $label;
        }
        if (array_key_exists('type', $args) && $args['type'] !== null) {
            $type = strtolower(trim((string) $args['type']));
            $allowed = ['text', 'textarea', 'select', 'multiselect', 'checkbox', 'radio'];
            if (! in_array($type, $allowed, true)) {
                throw new UserError('type must be one of: '.implode(', ', $allowed).'.');
            }
            $row->type = $type;
        }
        if (array_key_exists('isRequired', $args) && $args['isRequired'] !== null) {
            $row->is_required = ((int) $args['isRequired']) === 1;
        }
        if (array_key_exists('position', $args) && $args['position'] !== null) {
            $row->position = (int) $args['position'];
        }
        if (array_key_exists('inputValidation', $args)) {
            $row->input_validation = $args['inputValidation'] !== null ? trim((string) $args['inputValidation']) : null;
        }
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $row->is_enabled = ((int) $args['status']) === 1;
        }
        if (array_key_exists('optionsJson', $args) && $args['optionsJson'] !== null) {
            $decoded = json_decode((string) $args['optionsJson'], true);
            if (! is_array($decoded)) {
                throw new UserError('optionsJson must be a JSON array.');
            }
            $row->options = $decoded;
        }
        $row->save();

        return self::mapAdminRmaCustomField($row->fresh() ?? $row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function deleteAdminRmaCustomField(array $args): array
    {
        self::assertOrdersManage();
        $row = ReturnCustomField::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Return custom field not found.');
        }
        $mapped = self::mapAdminRmaCustomField($row, 'Return custom field deleted.');
        $row->delete();

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, deleted: list<string>, skipped: list<string>, updated_count: int, message: string}
     */
    protected static function bulkDeleteAdminRmaCustomFields(array $args): array
    {
        self::assertOrdersManage();
        $ids = collect($args['ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = ReturnCustomField::query()->find($id);
            if (! $row) {
                $skipped[] = (string) $id;
                continue;
            }
            $row->delete();
            $deleted[] = (string) $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' return custom field(s) deleted.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, updated_count: int}
     */
    protected static function bulkUpdateAdminRmaCustomFieldStatus(array $args): array
    {
        self::assertOrdersManage();
        $ids = collect($args['ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $enabled = ((int) $args['status']) === 1;
        $updated = ReturnCustomField::query()->whereIn('id', $ids)->update(['is_enabled' => $enabled]);

        return ['ok' => true, 'updated_count' => (int) $updated];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function menuAdminConfigurationMenu(array $args): array
    {
        self::assertSettingsManage();
        $slug = isset($args['slug']) ? trim((string) $args['slug']) : null;
        if ($slug === '') {
            $slug = null;
        }
        if ($slug !== null && ! in_array($slug, ConfigurationCatalog::slugs(), true)) {
            throw new UserError('Unknown configuration slug.');
        }
        $include = (bool) ($args['includeValues'] ?? $args['include_values'] ?? false);
        $fields = ConfigurationCatalog::fieldsFor($slug);

        return [
            'slug' => $slug,
            'fields' => $fields,
            'valuesJson' => $include && $slug !== null
                ? json_encode(ConfigurationCatalog::valuesFor($slug), JSON_UNESCAPED_UNICODE)
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{slug: string, valuesJson: string}
     */
    protected static function valuesAdminConfigurationValues(array $args): array
    {
        self::assertSettingsManage();
        $slug = trim((string) $args['slug']);
        if ($slug === '' || ! in_array($slug, ConfigurationCatalog::slugs(), true)) {
            throw new UserError('slug is required and must be a registered configuration slug.');
        }

        return [
            'slug' => $slug,
            'valuesJson' => json_encode(ConfigurationCatalog::valuesFor($slug), JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, slug: string, valuesJson: string, message: string}
     */
    protected static function createAdminConfigurationUpdate(array $args): array
    {
        self::assertSettingsManage();
        $slug = trim((string) $args['slug']);
        if ($slug === '' || ! in_array($slug, ConfigurationCatalog::slugs(), true)) {
            throw new UserError('slug is required and must be a registered configuration slug.');
        }
        $decoded = json_decode((string) $args['valuesJson'], true);
        if (! is_array($decoded)) {
            throw new UserError('valuesJson must be a JSON object of code → value.');
        }
        try {
            $values = ConfigurationCatalog::update($slug, $decoded);
        } catch (\InvalidArgumentException $e) {
            throw new UserError($e->getMessage());
        }

        return [
            'ok' => true,
            'slug' => $slug,
            'valuesJson' => json_encode($values, JSON_UNESCAPED_UNICODE),
            'message' => 'Configuration updated for '.$slug.'.',
        ];
    }


    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminProductTemplate(ProductTemplate $row, bool $withGroups = false): array
    {
        $groups = is_array($row->template_groups) ? $row->template_groups : [];

        return [
            'id' => (string) $row->id,

            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'is_default' => (bool) $row->is_default,
            'templateGroups' => $withGroups ? json_encode($groups, JSON_UNESCAPED_UNICODE) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminProductTemplates(array $args): array
    {
        self::assertCatalogFeatures();
        $limit = max(1, min(50, (int) ($args['limit'] ?? $args['first'] ?? 50)));
        $sort = trim((string) ($args['sort'] ?? 'id'));
        if (! in_array($sort, ['id', 'code', 'name'], true)) {
            $sort = 'id';
        }
        $order = strtolower(trim((string) ($args['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $q = ProductTemplate::query()->orderBy($sort, $order);
        $code = trim((string) ($args['code'] ?? ''));
        if ($code !== '') {
            $op = DbGrammar::likeOperator();
            $q->where('code', $op, '%'.$code.'%');
        }
        $name = trim((string) ($args['name'] ?? ''));
        if ($name !== '') {
            $op = DbGrammar::likeOperator();
            $q->where('name', $op, '%'.$name.'%');
        }
        $ids = trim((string) ($args['id'] ?? ''));
        if ($ids !== '') {
            $idList = collect(explode(',', $ids))->map(fn ($v) => (int) preg_replace('/\D+/', '', $v))->filter()->all();
            if ($idList !== []) {
                $q->whereIn('id', $idList);
            }
        }

        return $q->limit($limit)->get()->map(fn (ProductTemplate $row) => self::mapAdminProductTemplate($row, false))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminProductTemplate(array $args): array
    {
        self::assertCatalogFeatures();
        $code = Str::slug(trim((string) $args['code']), '_');
        $name = trim((string) $args['name']);
        if ($code === '' || $name === '') {
            throw new UserError('code and name are required.');
        }
        if (ProductTemplate::query()->where('code', $code)->exists()) {
            throw new UserError('Product template code already exists.');
        }
        $groups = [];
        if (! empty($args['templateGroupsJson'])) {
            $decoded = json_decode((string) $args['templateGroupsJson'], true);
            if (! is_array($decoded)) {
                throw new UserError('templateGroupsJson must be a JSON array.');
            }
            $groups = $decoded;
        }
        $isDefault = (bool) ($args['isDefault'] ?? false);
        if ($isDefault) {
            ProductTemplate::query()->where('is_default', true)->update(['is_default' => false]);
        }
        $row = ProductTemplate::query()->create([
            'code' => $code,
            'name' => $name,
            'is_default' => $isDefault,
            'template_groups' => $groups,
        ]);

        return self::mapAdminProductTemplate($row, true);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminProductTemplate(array $args): array
    {
        self::assertCatalogFeatures();
        $row = ProductTemplate::query()->find((int) preg_replace('/\D+/', '', (string) $args['id']));
        if (! $row) {
            throw new UserError('Product template not found.');
        }
        if (array_key_exists('code', $args) && $args['code'] !== null) {
            $code = Str::slug(trim((string) $args['code']), '_');
            if ($code === '') {
                throw new UserError('code cannot be empty.');
            }
            if (ProductTemplate::query()->where('code', $code)->where('id', '!=', $row->id)->exists()) {
                throw new UserError('Product template code already exists.');
            }
            $row->code = $code;
        }
        if (array_key_exists('name', $args) && $args['name'] !== null) {
            $name = trim((string) $args['name']);
            if ($name === '') {
                throw new UserError('name cannot be empty.');
            }
            $row->name = $name;
        }
        if (array_key_exists('templateGroupsJson', $args) && $args['templateGroupsJson'] !== null) {
            $decoded = json_decode((string) $args['templateGroupsJson'], true);
            if (! is_array($decoded)) {
                throw new UserError('templateGroupsJson must be a JSON array.');
            }
            $row->template_groups = $decoded;
        }
        if (array_key_exists('isDefault', $args) && $args['isDefault'] !== null) {
            $isDefault = (bool) $args['isDefault'];
            if ($isDefault) {
                ProductTemplate::query()->where('id', '!=', $row->id)->where('is_default', true)->update(['is_default' => false]);
            }
            $row->is_default = $isDefault;
        }
        $row->save();

        return self::mapAdminProductTemplate($row->fresh() ?? $row, true);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function deleteAdminProductTemplate(array $args): array
    {
        self::assertCatalogFeatures();
        $row = ProductTemplate::query()->find((int) preg_replace('/\D+/', '', (string) $args['id']));
        if (! $row) {
            throw new UserError('Product template not found.');
        }
        if ($row->is_default) {
            throw new UserError('The default product template cannot be deleted.');
        }
        $id = (string) $row->id;
        $row->delete();

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function statsAdminReportingOverview(array $args): array
    {
        return AdminReportingStats::overview($args);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminCustomerGroups(array $args): array
    {
        self::assertCustomerGroupsManage();
        $limit = max(1, min(100, (int) ($args['limit'] ?? 50)));

        return CustomerGroup::query()
            ->withCount('customers')
            ->orderBy('position')
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (CustomerGroup $g) => self::mapCustomerGroup($g))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminCustomerGroupMutation(array $args): array
    {
        self::assertCustomerGroupsManage();
        $name = trim((string) $args['name']);
        if ($name === '') {
            throw new UserError('Name is required.');
        }
        $code = Str::slug(trim((string) ($args['code'] ?? '')) ?: $name);
        if ($code === '') {
            throw new UserError('A valid code is required.');
        }
        if (CustomerGroup::query()->where('code', $code)->exists()) {
            throw new UserError('Customer group code already exists.');
        }
        $discount = max(0, min(100, (float) ($args['discountPercent'] ?? 0)));
        $group = CustomerGroup::query()->create([
            'name' => $name,
            'code' => $code,
            'discount_percent' => $discount,
            'is_enabled' => (bool) ($args['enabled'] ?? true),
            'is_system' => false,
            'position' => (int) CustomerGroup::query()->max('position') + 1,
        ]);

        return self::mapCustomerGroup($group->fresh() ?? $group);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapCustomerGroup(CustomerGroup $group): array
    {
        if (! array_key_exists('customers_count', $group->getAttributes()) && ! isset($group->customers_count)) {
            $group->loadCount('customers');
        }

        return [
            'id' => (string) $group->id,
            'code' => $group->code,
            'name' => $group->name,
            'discount_percent' => $group->discount_percent !== null ? (float) $group->discount_percent : null,
            'is_enabled' => (bool) $group->is_enabled,
            'is_system' => (bool) $group->is_system,
            'customers_count' => (int) ($group->customers_count ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapProductQtyPrice(ProductQtyPrice $row): array
    {
        $price = (float) $row->price;
        $qty = (float) $row->min_qty;

        return [
            'id' => (string) $row->id,

            'product_id' => (string) $row->product_id,
            'min_qty' => $qty,
            'max_qty' => $row->max_qty !== null ? (float) $row->max_qty : null,
            'price' => $price,
            'position' => (int) $row->position,
            'qty' => $qty,
            'value' => $price,
            'valueType' => 'fixed',
            'customerGroupId' => null,
            'customerGroupName' => null,
            'uniqueId' => (string) $row->id,
        ];
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createCustomerGroupPrice(array $args): array
    {
        self::assertCatalogProducts();
        $productId = (int) ($args['productId'] ?? 0);
        $product = Product::query()->find($productId);
        if (! $product) {
            throw new UserError('Product not found.');
        }
        $qty = (float) ($args['qty'] ?? $args['minQty'] ?? 0);
        if ($qty < 1) {
            throw new UserError('qty must be greater than or equal to 1.');
        }
        $valueType = strtolower(trim((string) ($args['valueType'] ?? 'fixed')));
        if (! in_array($valueType, ['fixed', 'discount'], true)) {
            throw new UserError('valueType must be fixed or discount.');
        }
        $value = (float) ($args['value'] ?? $args['price'] ?? -1);
        if ($value < 0) {
            throw new UserError('value must be zero or greater.');
        }
        $price = $value;
        if ($valueType === 'discount') {
            $base = (float) ($product->price ?? 0);
            $price = max(0, round($base * (1 - ($value / 100)), 2));
        }
        $row = ProductQtyPrice::query()->create([
            'product_id' => $product->id,
            'min_qty' => $qty,
            'max_qty' => null,
            'price' => $price,
            'position' => (int) ($args['position'] ?? 0),
        ]);
        $mapped = self::mapProductQtyPrice($row);
        $mapped['valueType'] = $valueType;
        $mapped['value'] = $valueType === 'discount' ? $value : $price;
        $mapped['customerGroupId'] = isset($args['customerGroupId']) && $args['customerGroupId'] !== null
            ? (int) $args['customerGroupId']
            : null;

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminCatalogProductCustomerGroupPrices(array $args): array
    {
        self::assertCatalogProducts();
        $productId = (int) $args['productId'];
        if (! Product::query()->whereKey($productId)->exists()) {
            throw new UserError('Product not found.');
        }

        return ProductQtyPrice::query()
            ->where('product_id', $productId)
            ->orderBy('position')
            ->orderBy('min_qty')
            ->get()
            ->map(fn (ProductQtyPrice $row) => self::mapProductQtyPrice($row))
            ->all();
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateCustomerGroupPrice(array $args): array
    {
        self::assertCatalogProducts();
        $row = ProductQtyPrice::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Customer group price not found.');
        }
        $product = Product::query()->find((int) $row->product_id);
        if (! $product) {
            throw new UserError('Product not found.');
        }
        $updates = [];
        if (array_key_exists('qty', $args) && $args['qty'] !== null) {
            $qty = (float) $args['qty'];
            if ($qty < 1) {
                throw new UserError('qty must be greater than or equal to 1.');
            }
            $updates['min_qty'] = $qty;
        }
        if (array_key_exists('position', $args) && $args['position'] !== null) {
            $updates['position'] = (int) $args['position'];
        }
        $valueType = array_key_exists('valueType', $args) && $args['valueType'] !== null
            ? strtolower(trim((string) $args['valueType']))
            : 'fixed';
        if (! in_array($valueType, ['fixed', 'discount'], true)) {
            throw new UserError('valueType must be fixed or discount.');
        }
        if (array_key_exists('value', $args) && $args['value'] !== null) {
            $value = (float) $args['value'];
            if ($value < 0) {
                throw new UserError('value must be zero or greater.');
            }
            $price = $value;
            if ($valueType === 'discount') {
                $base = (float) ($product->price ?? 0);
                $price = max(0, round($base * (1 - ($value / 100)), 2));
            }
            $updates['price'] = $price;
        }
        if ($updates !== []) {
            $row->forceFill($updates)->save();
        }
        $fresh = $row->fresh() ?? $row;
        $mapped = self::mapProductQtyPrice($fresh);
        $mapped['valueType'] = $valueType;
        if (array_key_exists('value', $args) && $args['value'] !== null && $valueType === 'discount') {
            $mapped['value'] = (float) $args['value'];
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, updated_count: int}
     */
    protected static function reorderAdminCatalogProductImageMutation(array $args): array
    {
        self::assertCatalogProducts();
        $product = Product::query()->find((int) $args['productId']);
        if (! $product) {
            throw new UserError('Product not found.');
        }
        $ids = [];
        if (is_array($args['order'] ?? null) && $args['order'] !== []) {
            $sorted = collect($args['order'])
                ->map(fn ($row) => [
                    'id' => self::parseAdminResourceId($row['id'] ?? 0),
                    'position' => (int) ($row['position'] ?? 0),
                ])
                ->filter(fn ($row) => $row['id'] > 0)
                ->sortBy('position')
                ->values();
            foreach ($sorted as $row) {
                $ids[] = $row['id'];
            }
        } else {
            $ids = collect($args['imageIds'] ?? [])
                ->map(fn ($id) => self::parseAdminResourceId($id))
                ->filter(fn ($id) => $id > 0)
                ->values()
                ->all();
        }
        if ($ids === []) {
            throw new UserError('order or imageIds is required.');
        }
        app(ProductService::class)->reorderImages($product, $ids);

        return ['ok' => true, 'updated_count' => count($ids)];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function deleteAdminCatalogProductImageMutation(array $args): array
    {
        self::assertCatalogProducts();
        $product = Product::query()->find((int) $args['productId']);
        if (! $product) {
            throw new UserError('Product not found.');
        }
        $imageId = self::parseAdminResourceId($args['id'] ?? $args['imageId'] ?? 0);
        if ($imageId < 1) {
            throw new UserError('Image id is required.');
        }
        $image = ProductImage::query()->find($imageId);
        if (! $image || (int) $image->product_id !== (int) $product->id) {
            throw new UserError('Product image not found.');
        }
        $id = (string) $image->id;
        try {
            app(ProductService::class)->deleteImage($product, $image);
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminCatalogProductImageMutation(array $args): array
    {
        self::assertCatalogProducts();
        $imageId = self::parseAdminResourceId($args['id'] ?? $args['imageId'] ?? 0);
        if ($imageId < 1) {
            throw new UserError('Image id is required.');
        }
        $image = ProductImage::query()->find($imageId);
        if (! $image) {
            throw new UserError('Product image not found.');
        }
        if (isset($args['productId']) && (int) $args['productId'] > 0
            && (int) $image->product_id !== (int) $args['productId']) {
            throw new UserError('Product image not found.');
        }
        $hasAlt = array_key_exists('altText', $args) || array_key_exists('alt', $args);
        $hasPosition = array_key_exists('position', $args) && $args['position'] !== null;
        if (! $hasAlt && ! $hasPosition) {
            throw new UserError('At least one of altText or position is required.');
        }
        $updates = [];
        if ($hasAlt) {
            $alt = array_key_exists('altText', $args) ? $args['altText'] : $args['alt'];
            $updates['alt'] = $alt !== null ? trim((string) $alt) : null;
        }
        if ($hasPosition) {
            $updates['position'] = (int) $args['position'];
        }
        $image->forceFill($updates)->save();
        $fresh = $image->fresh() ?? $image;

        return [
            'id' => (string) $fresh->id,
            'product_id' => (string) $fresh->product_id,
            'path' => $fresh->path,
            'url' => $fresh->url,
            'alt' => $fresh->alt,
            'position' => (int) $fresh->position,
        ];
    }

    protected static function parseAdminResourceId(mixed $value): int
    {
        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return 0;
        }
        if (ctype_digit($raw)) {
            return (int) $raw;
        }
        if (preg_match('/(\d+)\s*$/', $raw, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    protected static function assertCatalogFeatures(): Admin
    {
        $admin = auth('admin')->user();
        if (
            ! $admin instanceof Admin
            || (! $admin->hasPermission('catalog.features') && ! $admin->hasPermission('catalog.manage'))
        ) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    protected static function assertCmsManage(): Admin
    {
        $admin = auth('admin')->user();
        if (
            ! $admin instanceof Admin
            || (! $admin->hasPermission('cms.manage') && ! $admin->hasPermission('cms.pages'))
        ) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    protected static function assertTaxesManage(): Admin
    {
        $admin = auth('admin')->user();
        if (
            ! $admin instanceof Admin
            || (! $admin->hasPermission('taxes.manage') && ! $admin->hasPermission('settings.manage'))
        ) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, deleted: list<string>, skipped: list<string>, updated_count: int, message: string}
     */
    protected static function bulkDeleteAdminSettingsTaxRates(array $args): array
    {
        self::assertTaxesManage();
        $ids = [];
        foreach (['ids'] as $key) {
            foreach ((array) ($args[$key] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            throw new UserError('Provide at least one tax rate id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = TaxRate::query()->find($id);
            if (! $row) {
                $skipped[] = (string) $id;
                continue;
            }
            if ($row->rules()->exists()) {
                $skipped[] = (string) $id;
                continue;
            }
            $row->delete();
            $deleted[] = (string) $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' tax rate(s) deleted.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, deleted: list<string>, skipped: list<string>, updated_count: int, message: string}
     */
    protected static function bulkDeleteAdminSettingsTaxCategories(array $args): array
    {
        self::assertTaxesManage();
        $ids = [];
        foreach (['ids'] as $key) {
            foreach ((array) ($args[$key] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            throw new UserError('Provide at least one tax category id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = TaxClass::query()->find($id);
            if (! $row) {
                $skipped[] = (string) $id;
                continue;
            }
            $row->rules()->delete();
            $row->delete();
            $deleted[] = (string) $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' tax categor(ies) deleted.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminCmsPages(array $args): array
    {
        self::assertCmsManage();
        $limit = max(1, min(100, (int) ($args['limit'] ?? 50)));
        $locale = Language::defaultCode();
        $q = CmsPage::query()->with('translations')->orderByDesc('id');
        $term = trim((string) ($args['q'] ?? ''));
        if ($term !== '') {
            $op = DbGrammar::likeOperator();
            $like = '%'.$term.'%';
            $q->whereHas('translations', function ($tq) use ($like, $op, $locale) {
                $tq->where('language_code', $locale)
                    ->where(function ($inner) use ($like, $op) {
                        $inner->where('title', $op, $like)->orWhere('slug', $op, $like);
                    });
            });
        }

        return $q->limit($limit)->get()->map(fn (CmsPage $page) => self::mapAdminCmsPage($page, $locale))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function deleteAdminCmsPageMutation(array $args): array
    {
        self::assertCmsManage();
        $page = CmsPage::query()->find((int) $args['id']);
        if (! $page) {
            throw new UserError('CMS page not found.');
        }
        $id = (string) $page->id;
        $page->delete();

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminSettingsTaxRate(TaxRate $row): array
    {
        $rate = (float) $row->rate;

        return [
            'id' => (string) $row->id,
            'name' => $row->name,
            'identifier' => $row->name,
            'rate' => $rate,
            'taxRate' => $rate,
            'is_enabled' => (bool) $row->is_enabled,
            'status' => $row->is_enabled ? 1 : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminSettingsTaxCategory(TaxClass $row): array
    {
        $rateIds = $row->relationLoaded('rules')
            ? $row->rules->pluck('tax_rate_id')->filter()->map(fn ($id) => (string) $id)->unique()->values()->all()
            : $row->rules()->pluck('tax_rate_id')->filter()->map(fn ($id) => (string) $id)->unique()->values()->all();

        return [
            'id' => (string) $row->id,
            'name' => $row->name,
            'code' => Str::slug((string) $row->name) ?: ('tax-'.$row->id),
            'description' => null,
            'rate_ids' => $rateIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminSettingsTaxRates(array $args): array
    {
        self::assertTaxesManage();
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $q = TaxRate::query()->orderBy('name');
        $term = trim((string) ($args['q'] ?? ''));
        if ($term !== '') {
            $op = DbGrammar::likeOperator();
            $q->where('name', $op, '%'.$term.'%');
        }

        return $q->limit($limit)->get()->map(fn (TaxRate $row) => self::mapAdminSettingsTaxRate($row))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminSettingsTaxCategories(array $args): array
    {
        self::assertTaxesManage();
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $q = TaxClass::query()->with('rules')->orderBy('name');
        $term = trim((string) ($args['q'] ?? ''));
        if ($term !== '') {
            $op = DbGrammar::likeOperator();
            $q->where('name', $op, '%'.$term.'%');
        }

        return $q->limit($limit)->get()->map(fn (TaxClass $row) => self::mapAdminSettingsTaxCategory($row))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminSettingsTaxRate(array $args): array
    {
        self::assertTaxesManage();
        $name = trim((string) ($args['name'] ?? $args['identifier'] ?? ''));
        if ($name === '') {
            throw new UserError('Name or identifier is required.');
        }
        $rate = array_key_exists('taxRate', $args) && $args['taxRate'] !== null
            ? (float) $args['taxRate']
            : (float) ($args['rate'] ?? -1);
        if ($rate < 0 || $rate > 100) {
            throw new UserError('Tax rate must be between 0 and 100.');
        }
        $row = TaxRate::query()->create([
            'name' => $name,
            'rate' => $rate,
            'is_enabled' => (bool) ($args['enabled'] ?? true),
        ]);

        return self::mapAdminSettingsTaxRate($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminSettingsTaxRate(array $args): array
    {
        self::assertTaxesManage();
        $row = TaxRate::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Tax rate not found.');
        }
        $updates = [];
        if (array_key_exists('name', $args) || array_key_exists('identifier', $args)) {
            $name = trim((string) ($args['name'] ?? $args['identifier'] ?? ''));
            if ($name === '') {
                throw new UserError('Name cannot be empty.');
            }
            $updates['name'] = $name;
        }
        if (array_key_exists('taxRate', $args) || array_key_exists('rate', $args)) {
            $rate = array_key_exists('taxRate', $args) && $args['taxRate'] !== null
                ? (float) $args['taxRate']
                : (float) $args['rate'];
            if ($rate < 0 || $rate > 100) {
                throw new UserError('Tax rate must be between 0 and 100.');
            }
            $updates['rate'] = $rate;
        }
        if (array_key_exists('enabled', $args)) {
            $updates['is_enabled'] = (bool) $args['enabled'];
        }
        if ($updates !== []) {
            $row->update($updates);
        }

        return self::mapAdminSettingsTaxRate($row->fresh());
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminSettingsTaxCategory(array $args): array
    {
        self::assertTaxesManage();
        $name = trim((string) $args['name']);
        if ($name === '') {
            throw new UserError('Name is required.');
        }
        $rateIds = collect($args['taxrates'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($rateIds === []) {
            throw new UserError('At least one tax rate id is required in taxrates.');
        }
        $existing = TaxRate::query()->whereIn('id', $rateIds)->pluck('id')->all();
        if (count($existing) !== count($rateIds)) {
            throw new UserError('One or more tax rate ids were not found.');
        }
        $rates = TaxRate::query()->whereIn('id', $rateIds)->get()->keyBy('id');
        $row = TaxClass::query()->create(['name' => $name]);
        foreach ($rateIds as $rateId) {
            $taxRate = $rates->get($rateId);
            TaxRule::query()->create([
                'tax_class_id' => $row->id,
                'tax_rate_id' => $rateId,
                'name' => $name.' · '.($taxRate?->name ?? ('rate '.$rateId)),
                'rate' => (float) ($taxRate?->rate ?? 0),
                'priority' => 0,
                'is_enabled' => true,
            ]);
        }

        return self::mapAdminSettingsTaxCategory($row->fresh(['rules']));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminSettingsTaxCategory(array $args): array
    {
        self::assertTaxesManage();
        $row = TaxClass::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Tax category not found.');
        }
        if (array_key_exists('name', $args) && $args['name'] !== null) {
            $name = trim((string) $args['name']);
            if ($name === '') {
                throw new UserError('Name cannot be empty.');
            }
            $row->update(['name' => $name]);
        }
        if (array_key_exists('taxrates', $args) && is_array($args['taxrates'])) {
            $rateIds = collect($args['taxrates'])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
            if ($rateIds === []) {
                throw new UserError('taxrates cannot be empty when provided.');
            }
            $existing = TaxRate::query()->whereIn('id', $rateIds)->pluck('id')->all();
            if (count($existing) !== count($rateIds)) {
                throw new UserError('One or more tax rate ids were not found.');
            }
            $rates = TaxRate::query()->whereIn('id', $rateIds)->get()->keyBy('id');
            $row->rules()->delete();
            foreach ($rateIds as $rateId) {
                $taxRate = $rates->get($rateId);
                TaxRule::query()->create([
                    'tax_class_id' => $row->id,
                    'tax_rate_id' => $rateId,
                    'name' => $row->name.' · '.($taxRate?->name ?? ('rate '.$rateId)),
                    'rate' => (float) ($taxRate?->rate ?? 0),
                    'priority' => 0,
                    'is_enabled' => true,
                ]);
            }
        }

        return self::mapAdminSettingsTaxCategory($row->fresh(['rules']));
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminCmsPage(CmsPage $page, string $locale): array
    {
        $tr = $page->translation($locale);

        return [
            'id' => (string) $page->id,
            'title' => $tr?->title,
            'pageTitle' => $tr?->title,
            'slug' => $tr?->slug,
            'slug' => $tr?->slug,
            'status' => $page->status,
            'is_enabled' => (bool) $page->is_enabled,
            'is_visible' => (bool) $page->is_visible,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createCmsPage(array $args): array
    {
        self::assertCmsManage();
        $title = trim((string) $args['title']);
        if ($title === '') {
            throw new UserError('Title is required.');
        }
        $slug = isset($args['slug']) ? trim((string) $args['slug']) : '';
        if ($slug === '') {
            $slug = Str::slug($title) ?: ('page-'.Str::lower(Str::random(6)));
        }
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new UserError('Slug must be lowercase letters, numbers, and hyphens.');
        }
        $status = (string) ($args['status'] ?? CmsPage::STATUS_PUBLISHED);
        if (! array_key_exists($status, CmsPage::STATUSES)) {
            throw new UserError('Invalid CMS page status.');
        }
        $locale = Language::defaultCode();
        if (CmsPage::query()->whereHas('translations', fn ($q) => $q->where('slug', $slug)->where('language_code', $locale))->exists()) {
            throw new UserError('A CMS page with this slug already exists.');
        }

        $page = DB::transaction(function () use ($args, $title, $slug, $status, $locale) {
            $page = CmsPage::query()->create([
                'status' => $status,
                'is_enabled' => (bool) ($args['enabled'] ?? true),
                'is_visible' => (bool) ($args['visible'] ?? true),
                'template' => CmsPageTemplates::normalize('blank'),
                'robots' => 'index,follow',
            ]);
            $page->translations()->create([
                'language_code' => $locale,
                'title' => $title,
                'slug' => $slug,
                'body' => isset($args['body']) ? (string) $args['body'] : '',
            ]);

            return $page;
        });

        return self::mapAdminCmsPage($page->fresh(['translations']) ?? $page, $locale);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateCmsPage(array $args): array
    {
        self::assertCmsManage();
        $page = CmsPage::query()->find((int) $args['id']);
        if (! $page) {
            throw new UserError('CMS page not found.');
        }
        $locale = Language::defaultCode();
        $tr = $page->translation($locale);
        $title = array_key_exists('title', $args) ? trim((string) $args['title']) : (string) ($tr?->title ?? '');
        if ($title === '') {
            throw new UserError('Title is required.');
        }
        $slug = array_key_exists('slug', $args) ? trim((string) $args['slug']) : (string) ($tr?->slug ?? '');
        if ($slug === '') {
            $slug = Str::slug($title) ?: ('page-'.$page->id);
        }
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new UserError('Slug must be lowercase letters, numbers, and hyphens.');
        }
        $status = array_key_exists('status', $args)
            ? (string) $args['status']
            : (string) $page->status;
        if (! array_key_exists($status, CmsPage::STATUSES)) {
            throw new UserError('Invalid CMS page status.');
        }
        $exists = CmsPage::query()
            ->whereKeyNot($page->id)
            ->whereHas('translations', fn ($q) => $q->where('slug', $slug)->where('language_code', $locale))
            ->exists();
        if ($exists) {
            throw new UserError('A CMS page with this slug already exists.');
        }

        DB::transaction(function () use ($args, $page, $title, $slug, $status, $locale, $tr) {
            $page->update([
                'status' => $status,
                'is_enabled' => array_key_exists('enabled', $args) ? (bool) $args['enabled'] : (bool) $page->is_enabled,
                'is_visible' => array_key_exists('visible', $args) ? (bool) $args['visible'] : (bool) $page->is_visible,
            ]);
            $page->translations()->updateOrCreate(
                ['language_code' => $locale],
                [
                    'title' => $title,
                    'slug' => $slug,
                    'body' => array_key_exists('body', $args) ? (string) $args['body'] : (string) ($tr?->body ?? ''),
                    'short_description' => $tr?->short_description,
                    'meta_title' => $tr?->meta_title,
                    'meta_description' => $tr?->meta_description,
                    'meta_keywords' => $tr?->meta_keywords,
                ]
            );
        });

        return self::mapAdminCmsPage($page->fresh(['translations']) ?? $page, $locale);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAttribute(array $args): array
    {
        self::assertCatalogFeatures();

        $name = trim((string) ($args['name'] ?? $args['adminName'] ?? ''));
        if ($name === '') {
            throw new UserError('Name is required.');
        }
        $type = (string) ($args['type'] ?? 'select');
        if (! array_key_exists($type, Feature::TYPES)) {
            throw new UserError('Type must be one of: '.implode(', ', array_keys(Feature::TYPES)).'.');
        }

        try {
            $row = app(FeatureService::class)->saveFeature([
                'name' => $name,
                'code' => isset($args['code']) ? trim((string) $args['code']) : '',
                'type' => $type,
                'is_filterable' => (bool) ($args['filterable'] ?? $args['isFilterable'] ?? true),
                'is_enabled' => (bool) ($args['enabled'] ?? true),
                'used_for_combinations' => $type === 'select' || $type === 'color',
            ]);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();
            throw new UserError($messages[0] ?? 'Attribute validation failed.');
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return self::mapAdminAttribute($row->fresh(['translations']) ?? $row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminAttributeMutation(array $args): array
    {
        self::assertCatalogFeatures();
        $row = Feature::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Attribute not found.');
        }
        $name = trim((string) ($args['name'] ?? $args['adminName'] ?? ''));
        $payload = [];
        if ($name !== '') {
            $payload['name'] = $name;
        }
        if (array_key_exists('filterable', $args) || array_key_exists('isFilterable', $args)) {
            $payload['is_filterable'] = (bool) ($args['filterable'] ?? $args['isFilterable']);
        }
        if (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $payload['is_enabled'] = (bool) $args['enabled'];
        }
        if ($payload === []) {
            return self::mapAdminAttribute($row->loadMissing('translations'));
        }
        try {
            $row = app(FeatureService::class)->saveFeature($payload, $row);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();
            throw new UserError($messages[0] ?? 'Attribute validation failed.');
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return self::mapAdminAttribute($row->fresh(['translations']) ?? $row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminAttributeOptionMutation(array $args): array
    {
        self::assertCatalogFeatures();
        $feature = Feature::query()->find((int) $args['attributeId']);
        if (! $feature) {
            throw new UserError('Attribute not found.');
        }
        if (! in_array((string) $feature->type, ['select', 'multiselect', 'color', 'checkbox'], true)) {
            throw new UserError('This attribute type does not support options.');
        }
        $label = trim((string) $args['label']);
        if ($label === '') {
            throw new UserError('Label is required.');
        }
        try {
            $row = app(FeatureService::class)->saveValue([
                'feature_id' => $feature->id,
                'value' => $label,
                'code' => isset($args['code']) ? trim((string) $args['code']) : '',
                'is_enabled' => (bool) ($args['enabled'] ?? true),
            ]);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();
            throw new UserError($messages[0] ?? 'Option could not be created.');
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return [
            'id' => (string) $row->id,
            'attribute_id' => (string) $feature->id,
            'code' => $row->code,
            'label' => $label,
            'is_enabled' => (bool) $row->is_enabled,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminAttributeOptionMutation(array $args): array
    {
        self::assertCatalogFeatures();
        $row = FeatureValue::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Attribute option not found.');
        }
        $payload = [
            'feature_id' => $row->feature_id,
        ];
        if (array_key_exists('label', $args) && $args['label'] !== null) {
            $payload['value'] = trim((string) $args['label']);
        }
        if (array_key_exists('code', $args)) {
            $payload['code'] = trim((string) ($args['code'] ?? ''));
        }
        if (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $payload['is_enabled'] = (bool) $args['enabled'];
        }
        try {
            $row = app(FeatureService::class)->saveValue($payload, $row);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();
            throw new UserError($messages[0] ?? 'Option could not be updated.');
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }
        $label = Language::defaultCode();
        $tr = $row->translation($label);

        return [
            'id' => (string) $row->id,
            'attribute_id' => (string) $row->feature_id,
            'code' => $row->code,
            'label' => $tr?->value ?? (string) ($args['label'] ?? ''),
            'is_enabled' => (bool) $row->is_enabled,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function deleteAdminAttributeOptionMutation(array $args): array
    {
        self::assertCatalogFeatures();
        $row = FeatureValue::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Attribute option not found.');
        }
        $id = (string) $row->id;
        try {
            app(FeatureService::class)->deleteValue($row);
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminAttribute(Feature $row, bool $withDetail = false): array
    {
        $row->loadMissing('translations');
        $name = $row->translation(Language::defaultCode())?->name
            ?? $row->translations->first()?->name
            ?? $row->code;

        $mapped = [
            'id' => (string) $row->id,
            'code' => $row->code,
            'type' => $row->type,
            'name' => $name,
            'adminName' => $name,
            'is_filterable' => (bool) $row->is_filterable,
            'isFilterable' => (bool) $row->is_filterable,
            'is_enabled' => (bool) $row->is_enabled,
            'isConfigurable' => (bool) $row->used_for_combinations,
            'status' => $row->is_enabled ? 1 : 0,
            'translations' => null,
            'options' => null,
        ];
        if ($withDetail) {
            $mapped['translations'] = $row->translations->map(fn ($tr) => [
                'locale' => (string) $tr->language_code,
                'name' => (string) $tr->name,
                'adminName' => (string) $tr->name,
            ])->values()->all();
            $row->loadMissing('values.translations');
            $mapped['options'] = $row->values->map(function (FeatureValue $value) use ($row) {
                $label = $value->translation(Language::defaultCode())?->value
                    ?? $value->translations->first()?->value
                    ?? $value->code;

                return [
                    'id' => (string) $value->id,
                    'attribute_id' => (string) $row->id,
                    'code' => $value->code,
                    'label' => $label,
                    'is_enabled' => (bool) $value->is_enabled,
                ];
            })->values()->all();
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function copyAdminMarketingCartDiscountRule(array $args): array
    {
        self::assertMarketingDiscounts();
        $source = DiscountRule::query()->find((int) $args['id']);
        if (! $source || (string) $source->apply_as !== 'cart') {
            throw new UserError('Cart discount rule not found.');
        }
        $copy = DiscountRule::query()->create([
            'name' => 'Copy of '.$source->name,
            'apply_as' => 'cart',
            'code' => null,
            'action' => $source->action,
            'value' => $source->value,
            'currency_code' => $source->currency_code,
            'priority' => $source->priority,
            'stop_further' => (bool) $source->stop_further,
            'min_cart' => $source->min_cart,
            'min_qty' => $source->min_qty,
            'max_discount' => $source->max_discount,
            'max_uses' => $source->max_uses,
            'max_uses_per_customer' => $source->max_uses_per_customer,
            'used_count' => 0,
            'starts_at' => $source->starts_at,
            'expires_at' => $source->expires_at,
            'is_enabled' => false,
            'store_id' => $source->store_id,
            'seller_id' => $source->seller_id,
        ]);

        return self::mapAdminPromotion($copy);
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminMarketingCartDiscountRuleCoupon(array $args): array
    {
        self::assertMarketingDiscounts();
        $parentId = (int) ($args['discountRuleId'] ?? 0);
        $source = DiscountRule::query()->find($parentId);
        if (! $source || ! in_array((string) $source->apply_as, ['cart', 'coupon'], true)) {
            throw new UserError('Cart discount rule not found.');
        }
        $code = strtoupper(trim((string) ($args['code'] ?? '')));
        if ($code === '') {
            throw new UserError('Coupon code is required.');
        }
        if (DiscountRule::query()->where('code', $code)->exists()) {
            throw new UserError('A coupon with this code already exists.');
        }

        $maxUses = array_key_exists('usageLimit', $args) && $args['usageLimit'] !== null
            ? (int) $args['usageLimit']
            : $source->max_uses;
        $perCustomer = array_key_exists('usagePerCustomer', $args) && $args['usagePerCustomer'] !== null
            ? (int) $args['usagePerCustomer']
            : $source->max_uses_per_customer;
        $expiresAt = $source->expires_at;
        if (array_key_exists('expiredAt', $args) && $args['expiredAt'] !== null && trim((string) $args['expiredAt']) !== '') {
            $expiresAt = trim((string) $args['expiredAt']);
        }

        $row = DiscountRule::query()->create([
            'name' => ($source->name ?: 'Cart discount rule').' '.$code,
            'apply_as' => 'coupon',
            'code' => $code,
            'action' => $source->action,
            'value' => $source->value,
            'currency_code' => $source->currency_code,
            'priority' => $source->priority,
            'stop_further' => (bool) $source->stop_further,
            'min_cart' => $source->min_cart,
            'min_qty' => $source->min_qty,
            'max_discount' => $source->max_discount,
            'max_uses' => $maxUses,
            'max_uses_per_customer' => $perCustomer,
            'used_count' => 0,
            'starts_at' => $source->starts_at,
            'expires_at' => $expiresAt,
            'is_enabled' => true,
            'store_id' => $source->store_id,
            'seller_id' => $source->seller_id,
            'parent_id' => $source->id,
        ]);

        return self::mapAdminCartDiscountRuleCoupon($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function deleteAdminMarketingCartDiscountRuleCoupon(array $args): array
    {
        self::assertMarketingDiscounts();
        $row = DiscountRule::query()->find((int) $args['id']);
        if (! $row || (string) $row->apply_as !== 'coupon') {
            throw new UserError('Cart discount rule coupon not found.');
        }
        $id = (string) $row->id;
        $row->delete();

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminMarketingCartDiscountRuleCoupons(array $args): array
    {
        self::assertMarketingDiscounts();
        $parentId = (int) ($args['discountRuleId'] ?? 0);
        if ($parentId < 1) {
            throw new UserError('discountRuleId is required.');
        }
        $limit = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 20)));

        return DiscountRule::query()
            ->where('apply_as', 'coupon')
            ->where('parent_id', $parentId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (DiscountRule $r) => self::mapAdminCartDiscountRuleCoupon($r))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminCartDiscountRuleCoupon(DiscountRule $row): array
    {
        return [
            'id' => (string) $row->id,

            'code' => $row->code,
            'name' => $row->name,
            'discountRuleId' => $row->parent_id ? (int) $row->parent_id : null,
            'isPrimary' => false,
            'type' => 1,
            'usageLimit' => $row->max_uses,
            'usagePerCustomer' => $row->max_uses_per_customer,
            'timesUsed' => (int) $row->used_count,
            'expiredAt' => $row->expires_at?->toDateString(),
            'is_enabled' => (bool) $row->is_enabled,
            'apply_as' => $row->apply_as,
            'action' => $row->action,
            'value' => (float) $row->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, updated_count: int, codes: list<string>, coupons: list<array<string, mixed>>, message: string}
     */
    protected static function createAdminMarketingCartDiscountRuleCouponGenerate(array $args): array
    {
        self::assertMarketingDiscounts();
        $source = DiscountRule::query()->find((int) $args['discountRuleId']);
        if (! $source || ! in_array((string) $source->apply_as, ['cart', 'coupon'], true)) {
            throw new UserError('Cart discount rule not found.');
        }
        $length = (int) $args['length'];
        if ($length < 4 || $length > 30) {
            throw new UserError('length must be between 4 and 30.');
        }
        $format = strtolower(trim((string) $args['format']));
        if (! in_array($format, ['alphabetic', 'alphanumeric', 'numeric'], true)) {
            throw new UserError('format must be alphabetic, alphanumeric, or numeric.');
        }
        $qty = (int) $args['couponQty'];
        if ($qty < 1 || $qty > 100) {
            throw new UserError('couponQty must be between 1 and 100.');
        }
        $prefix = strtoupper(trim((string) ($args['prefix'] ?? '')));
        $suffix = strtoupper(trim((string) ($args['suffix'] ?? '')));
        $alphabet = match ($format) {
            'alphabetic' => 'ABCDEFGHJKLMNPQRSTUVWXYZ',
            'numeric' => '0123456789',
            default => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
        };
        $codes = [];
        $coupons = [];
        $attempts = 0;
        while (count($codes) < $qty && $attempts < $qty * 20) {
            $attempts++;
            $body = '';
            for ($i = 0; $i < $length; $i++) {
                $body .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $code = $prefix.$body.$suffix;
            if ($code === '' || isset($codes[$code]) || DiscountRule::query()->where('code', $code)->exists()) {
                continue;
            }
            $row = DiscountRule::query()->create([
                'name' => ($source->name ?: 'Cart discount rule').' '.$code,
                'apply_as' => 'coupon',
                'code' => $code,
                'action' => $source->action,
                'value' => $source->value,
                'currency_code' => $source->currency_code,
                'priority' => $source->priority,
                'stop_further' => (bool) $source->stop_further,
                'min_cart' => $source->min_cart,
                'min_qty' => $source->min_qty,
                'max_discount' => $source->max_discount,
                'max_uses' => $source->max_uses,
                'max_uses_per_customer' => $source->max_uses_per_customer,
                'used_count' => 0,
                'starts_at' => $source->starts_at,
                'expires_at' => $source->expires_at,
                'is_enabled' => true,
                'store_id' => $source->store_id,
                'seller_id' => $source->seller_id,
                'parent_id' => $source->id,
            ]);
            $codes[$code] = true;
            $coupons[] = self::mapAdminPromotion($row);
        }
        $codeList = array_keys($codes);

        return [
            'ok' => true,
            'updated_count' => count($codeList),
            'codes' => $codeList,
            'coupons' => $coupons,
            'message' => count($codeList).' coupon(s) generated.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, deleted: list<string>, skipped: list<string>, updated_count: int, message: string}
     */
    protected static function bulkDeleteAdminDiscountRules(array $args, string $applyAs): array
    {
        self::assertMarketingDiscounts();
        if (! in_array($applyAs, ['cart', 'catalog', 'coupon'], true)) {
            throw new UserError('Invalid promotion apply_as filter.');
        }
        $ids = [];
        foreach (['ids'] as $key) {
            foreach ((array) ($args[$key] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            throw new UserError('Provide at least one promotion id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = DiscountRule::query()->find($id);
            if (! $row || (string) $row->apply_as !== $applyAs) {
                $skipped[] = (string) $id;
                continue;
            }
            $row->delete();
            $deleted[] = (string) $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' '.$applyAs.' rule(s) deleted.',
        ];
    }

    /**
     * assigned to products, reject the whole batch (nothing deleted).
     *
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, deleted: list<string>, skipped: list<string>, updated_count: int, message: string}
     */
    protected static function bulkDeleteAdminCatalogAttributes(array $args): array
    {
        self::assertCatalogFeatures();
        $ids = [];
        foreach (['ids'] as $key) {
            foreach ((array) ($args[$key] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            throw new UserError('Provide at least one attribute id.');
        }

        $existing = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = Feature::query()->find($id);
            if (! $row) {
                $skipped[] = (string) $id;
                continue;
            }
            $existing[] = $row;
        }

        foreach ($existing as $row) {
            $assigned = (int) \Illuminate\Support\Facades\DB::table('product_feature_values')
                ->where('feature_id', $row->id)
                ->distinct()
                ->count('product_id');
            if ($assigned > 0) {
                throw new UserError('Attributes assigned to products cannot be deleted.');
            }
        }

        $deleted = [];
        $service = app(FeatureService::class);
        foreach ($existing as $row) {
            $service->deleteFeature($row);
            $deleted[] = (string) $row->id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' attribute(s) deleted.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createCoupon(array $args): array
    {
        self::assertMarketingDiscounts();

        $code = strtoupper(trim((string) $args['code']));
        if ($code === '') {
            throw new UserError('Coupon code is required.');
        }
        if (DiscountRule::query()->where('code', $code)->exists()) {
            throw new UserError('A coupon with this code already exists.');
        }
        $action = (string) ($args['action'] ?? 'percent');
        if (! in_array($action, ['percent', 'amount'], true)) {
            throw new UserError('Action must be percent or amount.');
        }
        $value = (float) $args['value'];
        if ($value <= 0) {
            throw new UserError('Value must be greater than zero.');
        }
        if ($action === 'percent' && $value > 100) {
            throw new UserError('Percent value cannot exceed 100.');
        }

        $row = DiscountRule::query()->create([
            'name' => trim((string) ($args['name'] ?? '')) ?: ('Coupon '.$code),
            'apply_as' => 'coupon',
            'code' => $code,
            'action' => $action,
            'value' => $value,
            'priority' => 100,
            'stop_further' => false,
            'min_cart' => isset($args['minCart']) ? (float) $args['minCart'] : null,
            'max_uses' => isset($args['maxUses']) ? (int) $args['maxUses'] : null,
            'used_count' => 0,
            'is_enabled' => (bool) ($args['enabled'] ?? true),
        ]);

        return self::mapAdminPromotion($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createPromotion(array $args): array
    {
        self::assertMarketingDiscounts();

        $name = trim((string) $args['name']);
        if ($name === '') {
            throw new UserError('Name is required.');
        }
        $applyAs = (string) ($args['applyAs'] ?? 'cart');
        if (! in_array($applyAs, ['cart', 'catalog'], true)) {
            throw new UserError('applyAs must be cart or catalog (use createCoupon for coupon codes).');
        }
        $action = (string) ($args['action'] ?? 'percent');
        if (! in_array($action, ['percent', 'amount', 'set_price', 'buy_x_get_y'], true)) {
            throw new UserError('Action must be percent, amount, set_price, or buy_x_get_y.');
        }
        $value = (float) $args['value'];
        if ($value <= 0) {
            throw new UserError('Value must be greater than zero.');
        }
        if ($action === 'percent' && $value > 100) {
            throw new UserError('Percent value cannot exceed 100.');
        }

        $row = DiscountRule::query()->create([
            'name' => $name,
            'apply_as' => $applyAs,
            'code' => null,
            'action' => $action,
            'value' => $value,
            'priority' => (int) ($args['priority'] ?? 100),
            'stop_further' => (bool) ($args['stopFurther'] ?? false),
            'min_cart' => isset($args['minCart']) ? (float) $args['minCart'] : null,
            'used_count' => 0,
            'is_enabled' => (bool) ($args['enabled'] ?? true),
        ]);

        return self::mapAdminPromotion($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updatePromotion(array $args): array
    {
        self::assertMarketingDiscounts();
        $row = DiscountRule::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Promotion not found.');
        }
        $updates = [];
        if (array_key_exists('name', $args) && $args['name'] !== null) {
            $name = trim((string) $args['name']);
            if ($name === '') {
                throw new UserError('Name cannot be empty.');
            }
            $updates['name'] = $name;
        }
        if (array_key_exists('action', $args) && $args['action'] !== null) {
            $action = (string) $args['action'];
            if (! in_array($action, ['percent', 'amount', 'set_price', 'buy_x_get_y'], true)) {
                throw new UserError('Action must be percent, amount, set_price, or buy_x_get_y.');
            }
            $updates['action'] = $action;
        }
        if (array_key_exists('value', $args) && $args['value'] !== null) {
            $value = (float) $args['value'];
            if ($value <= 0) {
                throw new UserError('Value must be greater than zero.');
            }
            $actionCheck = (string) ($updates['action'] ?? $row->action);
            if ($actionCheck === 'percent' && $value > 100) {
                throw new UserError('Percent value cannot exceed 100.');
            }
            $updates['value'] = $value;
        }
        if (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $updates['is_enabled'] = (bool) $args['enabled'];
        }
        if (array_key_exists('priority', $args) && $args['priority'] !== null) {
            $updates['priority'] = (int) $args['priority'];
        }
        if (array_key_exists('stopFurther', $args) && $args['stopFurther'] !== null) {
            $updates['stop_further'] = (bool) $args['stopFurther'];
        }
        if (array_key_exists('minCart', $args)) {
            $updates['min_cart'] = $args['minCart'] !== null ? (float) $args['minCart'] : null;
        }
        if ($updates !== []) {
            $row->forceFill($updates)->save();
        }

        return self::mapAdminPromotion($row->fresh() ?? $row);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminPromotion(DiscountRule $row): array
    {
        return [
            'id' => (string) $row->id,
            'name' => $row->name,
            'code' => $row->code,
            'apply_as' => $row->apply_as,
            'action' => $row->action,
            'value' => (float) $row->value,
            'is_enabled' => (bool) $row->is_enabled,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminCustomers(array $args): array
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
            throw new UserError('Forbidden.');
        }
        $limit = max(1, min(100, (int) ($args['limit'] ?? 20)));
        $q = Customer::query()->orderByDesc('id');
        $term = trim((string) ($args['q'] ?? ''));
        if ($term !== '') {
            $op = DbGrammar::likeOperator();
            $like = '%'.$term.'%';
            $q->where(function ($inner) use ($like, $op) {
                $inner->where('email', $op, $like)
                    ->orWhere('name', $op, $like)
                    ->orWhere('phone', $op, $like);
            });
        }

        return $q->limit($limit)->get()->map(fn (Customer $c) => [
            'id' => (string) $c->id,
            'email' => $c->email,
            'name' => $c->name,
            'phone' => $c->phone,
            'created_at' => optional($c->created_at)?->toIso8601String(),
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminRefunds(array $args): array
    {
        self::assertOrdersManage();
        $limit = max(1, min(100, (int) ($args['limit'] ?? 20)));
        $q = Order::query()->where('refunded_total', '>', 0)->orderByDesc('updated_at');
        if (! empty($args['orderNumber'])) {
            $q->where('number', (string) $args['orderNumber']);
        }

        return $q->limit($limit)->get()->map(fn (Order $o) => [
            'order_number' => $o->number,
            'refunded_total' => (float) $o->refunded_total,
            'grand_total' => $o->grand_total !== null ? (float) $o->grand_total : null,
            'payment_status' => $o->payment_status,
            'currency_code' => $o->currency_code,
            'updated_at' => optional($o->updated_at)?->toIso8601String(),
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminCustomerMutation(array $args): array
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
            throw new UserError('Forbidden.');
        }
        $email = strtolower(trim((string) $args['email']));
        $first = trim((string) $args['firstName']);
        if ($email === '' || $first === '') {
            throw new UserError('Email and first name are required.');
        }
        try {
            $row = app(CustomerService::class)->save([
                'email' => $email,
                'first_name' => $first,
                'last_name' => isset($args['lastName']) ? trim((string) $args['lastName']) : '',
                'phone' => isset($args['phone']) ? trim((string) $args['phone']) : null,
                'password' => isset($args['password']) && trim((string) $args['password']) !== ''
                    ? (string) $args['password']
                    : null,
                'is_enabled' => (bool) ($args['enabled'] ?? true),
            ]);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();
            throw new UserError($messages[0] ?? 'Customer could not be created.');
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return self::mapAdminCustomerRow($row);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminCustomerRow(Customer $row): array
    {
        $created = optional($row->created_at)?->toIso8601String();

        return [
            'id' => (string) $row->id,
            'email' => $row->email,
            'name' => $row->displayName(),
            'firstName' => $row->first_name,
            'lastName' => $row->last_name,
            'phone' => $row->phone,
            'gender' => $row->gender ?? null,
            'status' => (bool) $row->is_enabled,
            'created_at' => $created,
            'createdAt' => $created,
            'updatedAt' => optional($row->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminInvoices(array $args): array
    {
        $admin = auth('admin')->user();
        if (
            ! $admin instanceof Admin
            || (! $admin->hasPermission('orders.documents') && ! $admin->hasPermission('orders.manage'))
        ) {
            throw new UserError('Forbidden.');
        }
        $limit = max(1, min(100, (int) ($args['limit'] ?? 20)));
        $q = Order::query()->whereNotNull('fulfillment_details')->orderByDesc('id');
        if (! empty($args['orderNumber'])) {
            $q->where('number', (string) $args['orderNumber']);
        }

        return $q->limit($limit * 3)
            ->get()
            ->filter(function (Order $o) {
                $details = is_array($o->fulfillment_details) ? $o->fulfillment_details : [];

                return ! empty($details['invoice_number']);
            })
            ->take($limit)
            ->values()
            ->map(function (Order $o) {
                $details = is_array($o->fulfillment_details) ? $o->fulfillment_details : [];

                $state = (string) ($details['invoice_state'] ?? '');
                if ($state === '') {
                    $state = in_array((string) $o->payment_status, ['paid', 'partially_refunded', 'partial'], true)
                        ? 'paid'
                        : 'pending';
                }

                return [
                    'id' => (string) $o->id,
                    'order_number' => $o->number,
                    'orderIncrementId' => $o->number,
                    'invoice_number' => (string) ($details['invoice_number'] ?? ''),
                    'grand_total' => $o->grand_total !== null ? (float) $o->grand_total : null,
                    'payment_status' => $o->payment_status,
                    'state' => $state,
                    'currency_code' => $o->currency_code,
                    'created_at' => optional($o->created_at)?->toIso8601String(),
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, updated_count: int}
     */
    protected static function bulkUpdateAdminInvoiceStatus(array $args): array
    {
        $admin = auth('admin')->user();
        if (
            ! $admin instanceof Admin
            || (! $admin->hasPermission('orders.documents') && ! $admin->hasPermission('orders.manage'))
        ) {
            throw new UserError('Forbidden.');
        }
        $ids = [];
        foreach (['ids'] as $key) {
            foreach ((array) ($args[$key] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        $status = strtolower(trim((string) ($args['status'] ?? $args['value'] ?? 'pending')));
        if (! in_array($status, ['pending', 'paid', 'overdue', 'pending_payment'], true)) {
            throw new UserError('Invalid invoice status.');
        }
        $updated = 0;
        foreach ($ids as $id) {
            $order = Order::query()->find($id);
            if (! $order) {
                continue;
            }
            $details = is_array($order->fulfillment_details) ? $order->fulfillment_details : [];
            if (empty($details['invoice_number'])) {
                continue;
            }
            $details['invoice_state'] = $status === 'pending_payment' ? 'pending' : $status;
            $order->updateTrusted(['fulfillment_details' => $details]);
            $updated++;
        }

        return ['ok' => true, 'updated_count' => $updated];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string|null}
     */
    protected static function sendDuplicateAdminInvoice(array $args): array
    {
        $admin = auth('admin')->user();
        if (
            ! $admin instanceof Admin
            || (! $admin->hasPermission('orders.documents') && ! $admin->hasPermission('orders.manage'))
        ) {
            throw new UserError('Forbidden.');
        }
        if (! DocumentCommerceSettings::invoicesEnabled()) {
            throw new UserError(InvoiceDocument::DISABLED_MESSAGE);
        }
        $order = null;
        if (! empty($args['orderNumber'])) {
            $order = Order::query()->where('number', (string) $args['orderNumber'])->first();
        } elseif (! empty($args['invoiceNumber'])) {
            $needle = (string) $args['invoiceNumber'];
            $order = Order::query()
                ->whereNotNull('fulfillment_details')
                ->orderByDesc('id')
                ->limit(200)
                ->get()
                ->first(function (Order $o) use ($needle) {
                    $details = is_array($o->fulfillment_details) ? $o->fulfillment_details : [];

                    return (string) ($details['invoice_number'] ?? '') === $needle;
                });
        }
        if (! $order) {
            throw new UserError('Invoice not found.');
        }
        $doc = app(InvoiceDocument::class);
        if (! $doc->isAvailable($order)) {
            throw new UserError('Invoice is not available for this order.');
        }
        $to = trim((string) ($args['email'] ?? ''));
        if ($to === '') {
            $to = (string) ($order->email ?: $order->customer?->email ?: '');
        }
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new UserError('A valid email is required.');
        }
        $shop = (string) (ShopIdentity::get()['name'] ?? 'Store');
        $subject = 'Invoice #'.$order->number.' from '.$shop.' (copy)';
        $html = $doc->html($order);
        $sent = app(ShopMailer::class)->sendHtml($to, $subject, $html, 'invoice');
        if (! $sent) {
            throw new UserError('Failed to send invoice email.');
        }
        $details = is_array($order->fulfillment_details) ? $order->fulfillment_details : [];
        $details['invoice_emailed_at'] = now()->toIso8601String();
        $details['invoice_duplicate_emailed_at'] = now()->toIso8601String();
        $order->updateTrusted(['fulfillment_details' => $details]);

        return ['ok' => true, 'id' => (string) $order->id];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminOrders(array $args): array
    {
        $limit = max(1, min(100, (int) ($args['limit'] ?? 20)));
        $q = Order::query()->orderByDesc('id');
        if (! empty($args['status'])) {
            $q->where('status', (string) $args['status']);
        }

        return $q->limit($limit)->get()->map(fn (Order $o) => self::mapAdminOrder($o, false))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function previewAdminRefund(array $args): array
    {
        self::assertOrdersManage();
        $row = Order::query()->where('number', $args['number'])->first();
        if (! $row) {
            throw new UserError('Order not found.');
        }
        $status = strtolower((string) $row->status);
        if (in_array($status, ['closed'], true)) {
            throw new UserError('Closed orders cannot be refunded.');
        }
        if (in_array($status, ['fraud'], true)) {
            throw new UserError('Fraud orders cannot be refunded.');
        }
        $grand = round((float) $row->grand_total, 2);
        $refunded = round((float) ($row->refunded_total ?? 0), 2);
        $refundable = max(0, round($grand - $refunded, 2));

        return [
            'order_number' => $row->number,
            'grand_total' => $grand,
            'refunded_total' => $refunded,
            'refundable_total' => $refundable,
            'currency_code' => $row->currency_code,
            'can_refund' => $refundable > 0
                && in_array((string) $row->payment_status, ['paid', 'partially_refunded', 'partial'], true),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminCancelOrder(array $args): array
    {
        self::assertOrdersManage();
        OrderStageCatalog::seed();
        $row = Order::query()->where('number', $args['number'])->first();
        if (! $row) {
            throw new UserError('Order not found.');
        }
        $status = strtolower((string) $row->status);
        if (in_array($status, ['closed', 'fraud'], true)) {
            throw new UserError(ucfirst($status).' orders cannot be cancelled.');
        }
        if (in_array($status, ['cancelled', 'canceled', 'refunded', 'completed', 'delivered'], true)) {
            throw new UserError('Order cannot be cancelled from status '.$row->status.'.');
        }
        try {
            app(OrderStageService::class)->apply(
                $row,
                'cancelled',
                isset($args['comment']) ? (string) $args['comment'] : 'Cancelled via GraphQL',
            );
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return self::mapAdminOrder($row->fresh(), false);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminInvoice(array $args): array
    {
        $admin = auth('admin')->user();
        if (
            ! $admin instanceof Admin
            || (! $admin->hasPermission('orders.documents') && ! $admin->hasPermission('orders.manage'))
        ) {
            throw new UserError('Forbidden.');
        }
        if (! DocumentCommerceSettings::invoicesEnabled()) {
            throw new UserError(InvoiceDocument::DISABLED_MESSAGE);
        }
        $order = Order::query()->with('paymentMethod')->where('number', $args['number'])->first();
        if (! $order) {
            throw new UserError('Order not found.');
        }
        $status = strtolower((string) $order->status);
        if (in_array($status, ['closed', 'cancelled', 'canceled'], true)) {
            throw new UserError('Closed orders cannot be invoiced.');
        }
        if ($status === 'fraud') {
            throw new UserError('Fraud orders cannot be invoiced.');
        }
        $driver = strtolower((string) ($order->paymentMethod?->driver ?? $order->paymentMethod?->code ?? ''));
        if (in_array($driver, ['paypal_standard', 'paypal-standard'], true)) {
            throw new UserError('Invoices cannot be created for orders paid through PayPal Standard.');
        }
        DocumentCommerceSettings::formatInvoiceNumber($order);
        $order->refresh();
        $txRecorded = false;
        if (! empty($args['canCreateTransaction'])) {
            $due = round(max(0, (float) $order->grand_total - (float) ($order->amount_paid ?? 0)), 2);
            if ($due >= 0.01) {
                self::recordAdminPayment([
                    'number' => $order->number,
                    'amount' => $due,
                    'markPaid' => true,
                ]);
                $order->refresh();
                $txRecorded = true;
            }
        }
        $payload = self::mapInvoiceInfo($order, (bool) ($args['withHtml'] ?? true));
        $payload['transaction_recorded'] = $txRecorded;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminRefund(array $args): array
    {
        self::assertOrdersManage();
        $row = Order::query()->where('number', $args['number'])->first();
        if (! $row) {
            throw new UserError('Order not found.');
        }
        $status = strtolower((string) $row->status);
        if (in_array($status, ['closed'], true)) {
            throw new UserError('Closed orders cannot be refunded.');
        }
        if (in_array($status, ['fraud'], true)) {
            throw new UserError('Fraud orders cannot be refunded.');
        }
        $amount = (float) $args['amount'];
        if ($amount <= 0) {
            throw new UserError('Refund amount must be greater than zero.');
        }
        $before = round((float) ($row->refunded_total ?? 0), 2);
        try {
            app(PaymentRefundService::class)->refundAmount(
                $row,
                $amount,
                isset($args['comment']) ? (string) $args['comment'] : 'Refund via GraphQL',
            );
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();
            throw new UserError($messages[0] ?? 'Refund failed.');
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }

        $fresh = $row->fresh();
        if (round((float) ($fresh->refunded_total ?? 0), 2) <= $before) {
            throw new UserError(
                'Refund was not applied. Order payment status must be paid or partially_refunded (got '.$fresh->payment_status.').'
            );
        }

        return self::mapAdminOrder($fresh, false);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminShipments(array $args): array
    {
        self::assertOrdersManage();
        $limit = max(1, min(100, (int) ($args['limit'] ?? 20)));
        $q = Shipment::query()->orderByDesc('id');
        if (! empty($args['orderNumber'])) {
            $orderId = Order::query()->where('number', (string) $args['orderNumber'])->value('id');
            if (! $orderId) {
                return [];
            }
            $q->where('order_id', $orderId);
        }

        return $q->limit($limit)->get()->map(fn (Shipment $s) => [
            'id' => (string) $s->id,
            'order_id' => (string) $s->order_id,
            'status' => $s->status,
            'carrier' => $s->carrier,
            'tracking_number' => $s->tracking_number,
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminShipment(array $args): array
    {
        self::assertOrdersManage();
        $row = Order::query()->with('items')->where('number', $args['number'])->first();
        if (! $row) {
            throw new UserError('Order not found.');
        }
        $status = strtolower((string) $row->status);
        if (in_array($status, ['closed', 'cancelled', 'canceled'], true)) {
            throw new UserError('Closed orders cannot be shipped.');
        }
        if (in_array($status, ['fraud'], true)) {
            throw new UserError('Fraud orders cannot be shipped.');
        }
        if ($row->items->isEmpty()) {
            throw new UserError('There is nothing to ship on this order.');
        }
        $warehouseId = isset($args['warehouseId']) ? (int) $args['warehouseId'] : null;
        $notes = isset($args['notes']) ? (string) $args['notes'] : 'Created via GraphQL';
        if ($warehouseId) {
            $warehouse = \App\Domain\Catalog\Models\Warehouse::query()
                ->whereKey($warehouseId)
                ->where('is_enabled', true)
                ->first();
            if (! $warehouse) {
                throw new UserError('Warehouse is required.');
            }
            $notes = trim($notes.' [source:'.$warehouse->code.']');
        }
        $ship = Shipment::query()->create([
            'order_id' => $row->id,
            'status' => Shipment::STATUS_PROCESSING,
            'carrier' => isset($args['carrier']) ? trim((string) $args['carrier']) : null,
            'tracking_number' => isset($args['trackingNumber']) ? trim((string) $args['trackingNumber']) : null,
            'notes' => $notes,
        ]);
        $requested = is_array($args['items'] ?? null) ? $args['items'] : [];
        if ($requested !== []) {
            $byId = $row->items->keyBy('id');
            $added = 0;
            foreach ($requested as $line) {
                $itemId = (int) ($line['orderItemId'] ?? 0);
                $qty = (float) ($line['quantity'] ?? 0);
                $item = $byId->get($itemId);
                if (! $item || $qty <= 0) {
                    continue;
                }
                $shipQty = min($qty, (float) $item->qty);
                if ($shipQty <= 0) {
                    continue;
                }
                $ship->items()->create([
                    'order_item_id' => $item->id,
                    'qty' => $shipQty,
                ]);
                $added++;
            }
            if ($added === 0) {
                $ship->delete();
                throw new UserError('At least one item with a positive quantity is required.');
            }
        } else {
            foreach ($row->items as $item) {
                $ship->items()->create([
                    'order_item_id' => $item->id,
                    'qty' => (int) $item->qty,
                ]);
            }
        }

        return [
            'id' => (string) $ship->id,
            'order_id' => (string) $row->id,
            'status' => $ship->status,
            'carrier' => $ship->carrier,
            'tracking_number' => $ship->tracking_number,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminOrderComment(array $args): array
    {
        self::assertOrdersManage();
        $row = Order::query()->where('number', $args['number'])->first();
        if (! $row) {
            throw new UserError('Order not found.');
        }
        $body = trim((string) $args['body']);
        if ($body === '') {
            throw new UserError('Comment is required.');
        }
        $notify = ! empty($args['customerNotified']);
        $visibility = (string) ($args['visibility'] ?? 'private');
        if ($notify) {
            $visibility = 'customer';
        }
        if (! in_array($visibility, ['customer', 'private'], true)) {
            throw new UserError('Visibility must be customer or private.');
        }
        $comment = OrderComment::query()->create([
            'order_id' => $row->id,
            'author_type' => 'admin',
            'author_id' => auth('admin')->id(),
            'visibility' => $visibility,
            'body' => $body,
        ]);
        $notified = false;
        if ($notify) {
            $row->statusHistories()->create([
                'status' => $row->status,
                'comment' => 'Customer notified of order comment via GraphQL.',
            ]);
            $to = trim((string) ($row->email ?? ''));
            if ($to !== '') {
                try {
                    \Illuminate\Support\Facades\Mail::raw(
                        "Update on order {$row->number}:\n\n{$body}",
                        function ($message) use ($to, $row) {
                            $message->to($to)->subject('Order '.$row->number.' update');
                        }
                    );
                    $notified = true;
                } catch (\Throwable) {
                    $notified = false;
                }
            }
        }

        $customerNotified = $notified || $notify;

        return [
            'id' => (string) $comment->id,
            'order_id' => (string) $row->id,
            'body' => $comment->body,
            'comment' => $comment->body,
            'visibility' => $comment->visibility,
            'customer_notified' => $customerNotified,
            'customerNotified' => $customerNotified,
            'created_at' => optional($comment->created_at)?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminOrderComments(array $args): array
    {
        self::assertOrdersManage();
        $number = trim((string) ($args['number'] ?? ''));
        $orderId = (int) ($args['orderId'] ?? 0);
        $row = null;
        if ($orderId > 0) {
            $row = Order::query()->find($orderId);
        } elseif ($number !== '') {
            $row = Order::query()->where('number', $number)->first();
        }
        if (! $row) {
            throw new UserError('Order not found.');
        }
        $limit = max(1, min(100, (int) ($args['limit'] ?? 50)));

        return OrderComment::query()
            ->where('order_id', $row->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (OrderComment $c) use ($row) {
                $notified = (string) $c->visibility === 'customer';

                return [
                    'id' => (string) $c->id,
                    'order_id' => (string) $row->id,
                    'body' => $c->body,
                    'comment' => $c->body,
                    'visibility' => $c->visibility,
                    'customer_notified' => $notified,
                    'customerNotified' => $notified,
                    'created_at' => optional($c->created_at)?->toIso8601String(),
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminPaymentTransactions(array $args): array
    {
        self::assertOrdersManage();
        $limit = max(1, min(200, (int) ($args['first'] ?? $args['limit'] ?? 50)));
        $orderNumber = trim((string) ($args['orderNumber'] ?? ''));
        $invoiceNumber = trim((string) ($args['invoiceNumber'] ?? ''));
        $statusFilter = trim((string) ($args['status'] ?? ''));
        $txIdFilter = trim((string) ($args['transaction_id'] ?? $args['transactionId'] ?? ''));
        $orderIdFilter = (int) ($args['order_id'] ?? $args['orderId'] ?? 0);
        $createdFrom = trim((string) ($args['created_at_from'] ?? ''));
        $createdTo = trim((string) ($args['created_at_to'] ?? ''));
        $orders = Order::query()
            ->when($orderIdFilter > 0, fn ($q) => $q->whereKey($orderIdFilter))
            ->when($orderNumber !== '', fn ($q) => $q->where('number', 'like', '%'.$orderNumber.'%'))
            ->when($invoiceNumber !== '', fn ($q) => $q->where('fulfillment_details->invoice_number', $invoiceNumber))
            ->whereNotNull('payment_details')
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'number', 'payment_details', 'fulfillment_details', 'created_at']);
        $rows = [];
        foreach ($orders as $order) {
            $details = is_array($order->payment_details) ? $order->payment_details : [];
            $fulfillment = is_array($order->fulfillment_details) ? $order->fulfillment_details : [];
            $invoice = isset($fulfillment['invoice_number']) ? (string) $fulfillment['invoice_number'] : null;
            $txs = is_array($details['transactions'] ?? null) ? $details['transactions'] : [];
            if ($txs === [] && $details !== []) {
                $txs = [[
                    'id' => 'tx-'.$order->id,
                    'transaction_id' => (string) ($details['transaction_id'] ?? ('TX-'.$order->id)),
                    'amount' => (float) ($details['amount'] ?? 0),
                    'status' => (string) ($details['status'] ?? 'paid'),
                    'payment_method' => $details['payment_method'] ?? null,
                    'created_at' => optional($order->created_at)?->toIso8601String(),
                ]];
            }
            foreach ($txs as $tx) {
                if (! is_array($tx)) {
                    continue;
                }
                $status = (string) ($tx['status'] ?? 'paid');
                if ($statusFilter !== '' && strcasecmp($status, $statusFilter) !== 0) {
                    continue;
                }
                $txId = (string) ($tx['transaction_id'] ?? ('TX-'.$order->id));
                if ($txIdFilter !== '' && strcasecmp($txId, $txIdFilter) !== 0) {
                    continue;
                }
                $created = isset($tx['created_at']) ? (string) $tx['created_at'] : (optional($order->created_at)?->toIso8601String() ?? '');
                if ($createdFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $createdFrom) && $created !== '') {
                    if (strncmp($created, substr($createdFrom, 0, 10), 10) < 0) {
                        continue;
                    }
                }
                if ($createdTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $createdTo) && $created !== '') {
                    if (strncmp($created, substr($createdTo, 0, 10), 10) > 0) {
                        continue;
                    }
                }
                $method = isset($tx['payment_method']) ? (string) $tx['payment_method'] : null;
                $orderNum = (string) ($tx['order_number'] ?? $order->number);
                $rows[] = [
                    'id' => (string) ($tx['id'] ?? Str::uuid()),

                    'transaction_id' => $txId,
                    'transactionId' => $txId,
                    'order_id' => (int) $order->id,
                    'orderId' => (string) $order->id,
                    'order_number' => $orderNum,
                    'orderIncrementId' => $orderNum,
                    'invoice_number' => isset($tx['invoice_number']) ? (string) $tx['invoice_number'] : $invoice,
                    'amount' => (float) ($tx['amount'] ?? 0),
                    'payment_method' => $method,
                    'paymentMethod' => $method,
                    'status' => $status,
                    'created_at' => $created !== '' ? $created : null,
                    'createdAt' => $created !== '' ? $created : null,
                ];
            }
        }
        usort($rows, fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return array_slice($rows, 0, $limit);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function normalizeAndCreateAdminCustomerAddress(array $args): array
    {
        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
        $merged = array_merge($args, $input);
        if (empty($merged['line1']) && ! empty($merged['address'])) {
            $merged['line1'] = $merged['address'];
        }
        if (empty($merged['countryCode']) && ! empty($merged['country'])) {
            $merged['countryCode'] = $merged['country'];
        }
        if (empty($merged['postalCode']) && ! empty($merged['zipCode'])) {
            $merged['postalCode'] = $merged['zipCode'];
        }
        if (empty($merged['phone']) && ! empty($merged['phoneNumber'])) {
            $merged['phone'] = $merged['phoneNumber'];
        }

        return self::createAdminCustomerAddress($merged);
    }

    protected static function createAdminCustomerAddress(array $args): array
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
            throw new UserError('Forbidden.');
        }
        $customer = Customer::query()->find((int) $args['customerId']);
        if (! $customer) {
            throw new UserError('Customer not found.');
        }
        $country = strtoupper(trim((string) $args['countryCode']));
        if (strlen($country) !== 2) {
            throw new UserError('Country must be a 2-letter ISO code.');
        }
        try {
            app(CustomerService::class)->addAddress($customer, [
                'label' => isset($args['label']) ? trim((string) $args['label']) : 'Address',
                'first_name' => trim((string) $args['firstName']),
                'last_name' => isset($args['lastName']) ? trim((string) $args['lastName']) : '',
                'line1' => trim((string) $args['line1']),
                'line2' => isset($args['line2']) ? trim((string) $args['line2']) : null,
                'city' => trim((string) $args['city']),
                'state' => isset($args['state']) ? trim((string) $args['state']) : null,
                'postal_code' => isset($args['postalCode']) ? trim((string) $args['postalCode']) : null,
                'country_code' => $country,
                'phone' => isset($args['phone']) ? trim((string) $args['phone']) : null,
                'is_default_billing' => (bool) ($args['defaultBilling'] ?? false),
                'is_default_shipping' => (bool) ($args['defaultShipping'] ?? false),
            ]);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();
            throw new UserError($messages[0] ?? 'Address could not be created.');
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }
        $address = $customer->fresh(['addresses'])->addresses()->latest('id')->first();
        if (! $address instanceof CustomerAddress) {
            throw new UserError('Address could not be created.');
        }

        return self::mapAdminCustomerAddress($address);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{
     *   order_number: string,
     *   amount: float,
     *   amount_paid: float,
     *   amount_due: float,
     *   payment_status: string,
     *   transaction: array<string, mixed>
     * }
     */
    protected static function recordAdminPayment(array $args): array
    {
        self::assertOrdersManage();
        $order = Order::query()->with('paymentMethod')->where('number', $args['number'])->first();
        if (! $order) {
            throw new UserError('Order not found.');
        }
        if ((string) $order->payment_status === 'refunded') {
            throw new UserError('Refunded orders cannot accept new payments.');
        }
        $grand = round((float) $order->grand_total, 2);
        $paid = round((float) ($order->amount_paid ?? 0), 2);
        $due = round(max(0, $grand - $paid), 2);
        if ($due <= 0.009) {
            throw new UserError('Nothing is due on this order.');
        }
        $amount = ! empty($args['markPaid'])
            ? $due
            : round(min($due, (float) ($args['amount'] ?? $due)), 2);
        if ($amount < 0.01) {
            throw new UserError('Enter a payment amount greater than zero.');
        }
        $newPaid = round($paid + $amount, 2);
        $newDue = round(max(0, $grand - $newPaid), 2);
        $status = $newDue <= 0.009 ? 'paid' : 'partial';
        $previous = (string) $order->payment_status;
        $method = trim((string) ($args['paymentMethod'] ?? ''));
        if ($method === '') {
            $method = (string) ($order->paymentMethod?->code ?? 'manual');
        }
        $details = is_array($order->payment_details) ? $order->payment_details : [];
        $fulfillment = is_array($order->fulfillment_details) ? $order->fulfillment_details : [];
        $invoiceNumber = trim((string) ($args['invoiceNumber'] ?? ''));
        if ($invoiceNumber === '' && ! empty($fulfillment['invoice_number'])) {
            $invoiceNumber = (string) $fulfillment['invoice_number'];
        }
        $txs = is_array($details['transactions'] ?? null) ? $details['transactions'] : [];
        $txId = 'TX-'.strtoupper(Str::random(10));
        $createdAt = now()->toIso8601String();
        $tx = [
            'id' => (string) Str::uuid(),

            'transaction_id' => $txId,
            'transactionId' => $txId,
            'order_number' => $order->number,
            'orderIncrementId' => $order->number,
            'orderId' => (string) $order->id,
            'invoice_number' => $invoiceNumber !== '' ? $invoiceNumber : null,
            'invoiceId' => $invoiceNumber !== '' ? $invoiceNumber : (string) $order->id,
            'amount' => $amount,
            'formattedAmount' => number_format($amount, 2, '.', ''),
            'payment_method' => $method,
            'paymentMethod' => $method,
            'paymentTitle' => $method,
            'status' => 'paid',
            'created_at' => $createdAt,
            'createdAt' => $createdAt,
        ];
        $txs[] = $tx;
        $details['transactions'] = $txs;
        $details['paid_total'] = $newPaid;
        $order->forceFill([
            'amount_paid' => $newPaid,
            'amount_due' => $newDue,
            'payment_status' => $status,
            'payment_details' => $details,
        ])->save();
        $order->statusHistories()->create([
            'status' => $order->status,
            'comment' => 'Payment recorded via GraphQL: '.number_format($amount, 2)
                .($status === 'paid' ? ' (order paid in full)' : ''),
        ]);
        if ($previous !== 'paid' && $status === 'paid') {
            try {
                app(\App\Domain\Catalog\Services\GiftCardService::class)
                    ->issueForPaidOrder($order->fresh(['items.product']));
            } catch (\Throwable) {
                // Gift cards are best-effort after payment.
            }
        }

        return [
            'order_number' => $order->number,
            'amount' => $amount,
            'amount_paid' => $newPaid,
            'amount_due' => $newDue,
            'payment_status' => $status,
            'transaction' => $tx,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function mapOrderAddress(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        return [
            'first_name' => $raw['first_name'] ?? $raw['firstName'] ?? null,
            'last_name' => $raw['last_name'] ?? $raw['lastName'] ?? null,
            'line1' => $raw['line1'] ?? $raw['address1'] ?? null,
            'line2' => $raw['line2'] ?? $raw['address2'] ?? null,
            'city' => $raw['city'] ?? null,
            'state' => $raw['state'] ?? null,
            'postal_code' => $raw['postal_code'] ?? $raw['postalCode'] ?? null,
            'country_code' => $raw['country_code'] ?? $raw['countryCode'] ?? null,
            'phone' => $raw['phone'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminOrder(Order $o, bool $withItems): array
    {
        $payload = [
            'id' => (string) $o->id,
            'number' => $o->number,
            'email' => $o->email,
            'status' => $o->status,
            'payment_status' => $o->payment_status,
            'customer_id' => $o->customer_id ? (string) $o->customer_id : null,
            'subtotal' => $o->subtotal !== null ? (float) $o->subtotal : null,
            'discount_total' => $o->discount_total !== null ? (float) $o->discount_total : 0.0,
            'shipping_total' => $o->shipping_total !== null ? (float) $o->shipping_total : 0.0,
            'grand_total' => (float) $o->grand_total,
            'amount_paid' => $o->amount_paid !== null ? (float) $o->amount_paid : 0.0,
            'amount_due' => $o->amount_due !== null ? (float) $o->amount_due : null,
            'refunded_total' => $o->refunded_total !== null ? (float) $o->refunded_total : 0.0,
            'currency_code' => $o->currency_code,
            'customer_note' => $o->customer_note,
            'billing_address' => self::mapOrderAddress($o->billing_address),
            'shipping_address' => self::mapOrderAddress($o->shipping_address),
            'created_at' => optional($o->created_at)?->toIso8601String(),
            'items' => null,
        ];
        if ($withItems) {
            $o->loadMissing('items');
            $payload['items'] = $o->items->map(fn ($item) => [
                'id' => (string) $item->id,
                'sku' => $item->sku,
                'name' => $item->name,
                'qty' => (float) $item->qty,
                'price' => (float) ($item->unit_price ?? $item->price ?? 0),
                'total' => (float) ($item->total ?? ((float) ($item->unit_price ?? 0) * (float) $item->qty)),
            ])->all();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminProduct(Product $row): array
    {
        $locale = app()->getLocale();
        $qty = $row->stock_qty !== null ? (float) $row->stock_qty : null;
        $price = $row->price !== null ? (float) $row->price : null;
        $special = $row->sale_price !== null ? (float) $row->sale_price : null;

        return [
            'id' => (string) $row->id,

            'sku' => $row->sku,
            'name' => $row->translation($locale)?->name,
            'type' => (string) ($row->type ?? 'simple'),
            'price' => $price,
            'formattedPrice' => $price !== null ? number_format($price, 2, '.', '') : null,
            'specialPrice' => $special,
            'formattedSpecialPrice' => $special !== null ? number_format($special, 2, '.', '') : null,
            'weight' => $row->weight !== null ? (float) $row->weight : null,
            'stock_qty' => $qty,
            'quantity' => $qty,
            'is_enabled' => (bool) $row->is_enabled,
            'status' => $row->is_enabled ? 1 : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminWarehouse(Warehouse $row): array
    {
        $locale = app()->getLocale();

        return [
            'id' => (string) $row->id,
            'code' => $row->code,
            'name' => $row->displayName($locale),
            'city' => $row->city,
            'country' => $row->country,
            'is_default' => (bool) $row->is_default,
            'is_enabled' => (bool) $row->is_enabled,
            'status' => $row->is_enabled ? 1 : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminInventoryRow(WarehouseStock $row): array
    {
        $qty = (float) $row->qty;
        $reserved = (float) $row->reserved_qty;

        return [
            'id' => (string) $row->id,

            'product_id' => (string) $row->product_id,
            'warehouse_id' => (string) $row->warehouse_id,
            'warehouse_code' => $row->warehouse?->code,
            'sourceId' => (int) $row->warehouse_id,
            'sourceCode' => $row->warehouse?->code,
            'qty' => $qty,
            'quantity' => $qty,
            'reserved_qty' => $reserved,
            'available_qty' => max(0, $qty - $reserved),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminWarehouses(array $args): array
    {
        self::assertCatalogProducts();
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $q = Warehouse::query()->with('translations')->orderByDesc('is_default')->orderBy('id');
        $code = trim((string) ($args['code'] ?? ''));
        if ($code !== '') {
            $op = DbGrammar::likeOperator();
            $q->where('code', $op, '%'.$code.'%');
        }
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $q->where('is_enabled', ((int) $args['status']) === 1);
        }
        $country = strtoupper(trim((string) ($args['country'] ?? '')));
        if ($country !== '') {
            $q->where('country', $country);
        }

        return $q->limit($limit)->get()->map(fn (Warehouse $w) => self::mapAdminWarehouse($w))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminProductInventories(array $args): array
    {
        self::assertCatalogProducts();
        $productId = (int) $args['productId'];
        if (! Product::query()->whereKey($productId)->exists()) {
            throw new UserError('Product not found.');
        }

        return WarehouseStock::query()
            ->with('warehouse')
            ->where('product_id', $productId)
            ->orderBy('warehouse_id')
            ->get()
            ->map(fn (WarehouseStock $row) => self::mapAdminInventoryRow($row))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminProductInventory(array $args): array
    {
        self::assertCatalogProducts();
        $product = Product::query()->find((int) $args['productId']);
        if (! $product) {
            throw new UserError('Product not found.');
        }
        $warehouseId = (int) $args['warehouseId'];
        if (! Warehouse::query()->whereKey($warehouseId)->exists()) {
            throw new UserError('Warehouse not found.');
        }
        $qty = (float) $args['qty'];
        $reserved = (float) ($args['reservedQty'] ?? 0);
        if ($qty < 0 || $reserved < 0) {
            throw new UserError('Quantities must be zero or greater.');
        }
        $before = (float) $product->stock_qty;
        app(InventoryService::class)->syncFromForm($product, [
            'warehouse_stocks' => [
                [
                    'warehouse_id' => $warehouseId,
                    'qty' => $qty,
                    'reserved_qty' => $reserved,
                ],
            ],
        ], $before);
        $row = WarehouseStock::query()
            ->with('warehouse')
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouseId)
            ->first();
        if (! $row) {
            throw new UserError('Inventory row was not saved.');
        }

        return self::mapAdminInventoryRow($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createAdminWarehouse(array $args): array
    {
        self::assertCatalogProducts();
        $code = strtoupper(trim((string) $args['code']));
        $name = trim((string) $args['name']);
        $email = strtolower(trim((string) $args['email']));
        $country = strtoupper(trim((string) $args['country']));
        if ($code === '' || $name === '' || $email === '' || strlen($country) !== 2) {
            throw new UserError('code, name, email, and 2-letter country are required.');
        }
        if (Warehouse::query()->where('code', $code)->exists()) {
            throw new UserError('A warehouse with this code already exists.');
        }
        $locale = Language::defaultCode();
        try {
            $row = DB::transaction(function () use ($args, $code, $name, $email, $country, $locale) {
                $warehouse = Warehouse::query()->create([
                    'name' => $name,
                    'code' => $code,
                    'email' => $email,
                    'phone' => isset($args['phone']) ? trim((string) $args['phone']) : null,
                    'address' => trim((string) $args['address']),
                    'city' => trim((string) $args['city']),
                    'state' => isset($args['state']) ? trim((string) $args['state']) : null,
                    'postcode' => trim((string) $args['postcode']),
                    'country' => $country,
                    'is_enabled' => (bool) ($args['enabled'] ?? true),
                    'is_default' => (bool) ($args['isDefault'] ?? false),
                ]);
                $warehouse->translations()->updateOrCreate(
                    ['language_code' => $locale],
                    ['name' => $name, 'description' => null],
                );
                app(InventoryService::class)->ensureExactlyOneDefault(
                    $warehouse->is_default ? $warehouse : null
                );

                return $warehouse->fresh(['translations']);
            });
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return self::mapAdminWarehouse($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminWarehouse(array $args): array
    {
        self::assertCatalogProducts();
        $row = Warehouse::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Warehouse not found.');
        }
        $locale = Language::defaultCode();
        try {
            $row = DB::transaction(function () use ($args, $row, $locale) {
                $updates = [];
                foreach (['email', 'address', 'city', 'postcode', 'state', 'phone'] as $field) {
                    if (array_key_exists($field, $args) && $args[$field] !== null) {
                        $updates[$field] = trim((string) $args[$field]);
                    }
                }
                if (isset($args['email'])) {
                    $updates['email'] = strtolower(trim((string) $args['email']));
                }
                if (isset($args['country'])) {
                    $country = strtoupper(trim((string) $args['country']));
                    if (strlen($country) !== 2) {
                        throw new UserError('Country must be a 2-letter ISO code.');
                    }
                    $updates['country'] = $country;
                }
                if (array_key_exists('enabled', $args)) {
                    $updates['is_enabled'] = (bool) $args['enabled'];
                }
                if (array_key_exists('isDefault', $args)) {
                    $updates['is_default'] = (bool) $args['isDefault'];
                }
                if (isset($args['name'])) {
                    $name = trim((string) $args['name']);
                    if ($name === '') {
                        throw new UserError('Name cannot be empty.');
                    }
                    $updates['name'] = $name;
                    $row->translations()->updateOrCreate(
                        ['language_code' => $locale],
                        ['name' => $name, 'description' => $row->translation($locale)?->description],
                    );
                }
                if ($updates !== []) {
                    $row->update($updates);
                }
                app(InventoryService::class)->ensureExactlyOneDefault(
                    ! empty($updates['is_default']) ? $row->fresh() : null
                );

                return $row->fresh(['translations']);
            });
        } catch (UserError $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return self::mapAdminWarehouse($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function deleteAdminWarehouse(array $args): array
    {
        self::assertCatalogProducts();
        $row = Warehouse::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Warehouse not found.');
        }
        if ($row->is_default) {
            throw new UserError('The default warehouse cannot be deleted.');
        }
        if ($row->stocks()->where('qty', '>', 0)->exists()) {
            throw new UserError('Warehouse still has stock and cannot be deleted.');
        }
        $id = (string) $row->id;
        $row->stocks()->delete();
        $row->translations()->delete();
        $row->delete();

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, updated_count: int}
     */
    protected static function bulkDeleteAdminWarehouses(array $args): array
    {
        self::assertCatalogProducts();
        $ids = [];
        foreach (['ids'] as $key) {
            foreach ((array) ($args[$key] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            throw new UserError('Provide at least one warehouse id.');
        }
        $remaining = Warehouse::query()->count();
        $deleted = 0;
        foreach ($ids as $id) {
            $row = Warehouse::query()->find($id);
            if (! $row) {
                continue;
            }
            if ($row->is_default) {
                throw new UserError('The default warehouse cannot be deleted.');
            }
            if ($remaining - $deleted <= 1) {
                throw new UserError('Cannot delete the last warehouse.');
            }
            if ($row->stocks()->where('qty', '>', 0)->exists()) {
                throw new UserError('Warehouse '.$row->code.' still has stock and cannot be deleted.');
            }
            $row->stocks()->delete();
            $row->translations()->delete();
            $row->delete();
            $deleted++;
        }

        return ['ok' => true, 'updated_count' => $deleted];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, updated_count: int}
     */
    protected static function bulkCancelAdminOrders(array $args): array
    {
        self::assertOrdersManage();
        OrderStageCatalog::seed();
        $ids = [];
        foreach (['ids'] as $key) {
            foreach ((array) ($args[$key] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        $numbers = array_values(array_unique(array_filter(array_map(
            fn ($n) => trim((string) $n),
            (array) ($args['numbers'] ?? [])
        ))));
        if ($ids === [] && $numbers === []) {
            throw new UserError('Provide at least one order id or number.');
        }
        $comment = trim((string) ($args['comment'] ?? 'Mass cancelled via GraphQL'));
        $q = Order::query()->where(function ($inner) use ($ids, $numbers) {
            if ($ids !== []) {
                $inner->whereIn('id', $ids);
            }
            if ($numbers !== []) {
                $ids === []
                    ? $inner->whereIn('number', $numbers)
                    : $inner->orWhereIn('number', $numbers);
            }
        });
        $updated = 0;
        foreach ($q->get() as $row) {
            $status = strtolower((string) $row->status);
            if (in_array($status, ['closed', 'fraud', 'cancelled', 'canceled', 'refunded', 'completed', 'delivered'], true)) {
                continue;
            }
            try {
                app(OrderStageService::class)->apply($row, 'cancelled', $comment !== '' ? $comment : 'Mass cancelled via GraphQL');
                $updated++;
            } catch (\Throwable) {
                // skip ineligible rows in the batch
            }
        }

        return ['ok' => true, 'updated_count' => $updated];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminPromotions(array $args, bool $couponsOnly): array
    {
        self::assertMarketingDiscounts();
        $limit = max(1, min(100, (int) ($args['limit'] ?? 20)));
        $q = DiscountRule::query()->orderByDesc('id');
        if ($couponsOnly) {
            $q->where('apply_as', 'coupon');
            $term = trim((string) ($args['q'] ?? ''));
            if ($term !== '') {
                $op = DbGrammar::likeOperator();
                $like = '%'.$term.'%';
                $q->where(function ($inner) use ($like, $op) {
                    $inner->where('code', $op, $like)->orWhere('name', $op, $like);
                });
            }
        } else {
            $q->whereIn('apply_as', ['cart', 'catalog']);
        }

        return $q->limit($limit)->get()->map(fn (DiscountRule $r) => self::mapAdminPromotion($r))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminAttributes(array $args): array
    {
        self::assertCatalogFeatures();
        $limit = max(1, min(100, (int) ($args['limit'] ?? 50)));

        return Feature::query()
            ->with('translations')
            ->orderBy('position')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (Feature $f) => self::mapAdminAttribute($f))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminCategoryTrees(array $args): array
    {
        self::assertCatalogCategories();
        $parentId = array_key_exists('parentId', $args) && $args['parentId'] !== null && $args['parentId'] !== ''
            ? (int) $args['parentId']
            : null;
        $enabled = array_key_exists('enabled', $args) ? $args['enabled'] : null;
        $rows = Category::query()->with('translations')->orderBy('position')->orderBy('id')->get();
        if ($enabled !== null) {
            $rows = $rows->where('is_enabled', (bool) $enabled)->values();
        }
        $byParent = $rows->groupBy(fn (Category $c) => $c->parent_id === null ? 'root' : (string) $c->parent_id);

        $build = function (?int $pid) use (&$build, $byParent): array {
            $key = $pid === null ? 'root' : (string) $pid;
            $children = $byParent->get($key, collect());

            return $children->map(function (Category $row) use (&$build) {
                $mapped = self::mapAdminCategory($row);
                $mapped['children'] = $build((int) $row->id);

                return $mapped;
            })->values()->all();
        };

        return $build($parentId);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminCatalogProducts(array $args): array
    {
        self::assertCatalogProducts();
        $limit = max(1, min(100, (int) ($args['limit'] ?? 20)));
        $locale = app()->getLocale();
        $q = Product::query()->with('translations')->orderByDesc('id');
        $term = trim((string) ($args['q'] ?? ''));
        if ($term !== '') {
            $op = DbGrammar::likeOperator();
            $like = '%'.$term.'%';
            $q->where(function ($inner) use ($like, $locale, $op) {
                $inner->where('sku', $op, $like)
                    ->orWhereHas('translations', fn ($t) => $t->where('language_code', $locale)->where('name', $op, $like));
            });
        }
        $sku = trim((string) ($args['sku'] ?? ''));
        if ($sku !== '') {
            $op = DbGrammar::likeOperator();
            $q->where('sku', $op, '%'.$sku.'%');
        }
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $q->where('is_enabled', ((int) $args['status']) === 1);
        }
        if (! empty($args['enabledOnly'])) {
            $q->where('is_enabled', true);
        }
        $type = trim((string) ($args['type'] ?? ''));
        if ($type !== '') {
            $q->where('type', $type);
        }

        return $q->limit($limit)->get()->map(fn (Product $p) => self::mapAdminProduct($p))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function deleteAdminCatalogProductMutation(array $args): array
    {
        self::assertCatalogProducts();
        $row = Product::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Product not found.');
        }
        $id = (string) $row->id;
        try {
            app(ProductService::class)->delete($row);
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function copyAdminCatalogProduct(array $args): array
    {
        self::assertCatalogProducts();
        $row = Product::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Product not found.');
        }
        try {
            $copy = app(ProductService::class)->duplicate($row);
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return self::mapAdminProduct($copy->fresh() ?? $copy);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, updated_count: int}
     */
    protected static function bulkDeleteAdminCatalogProducts(array $args): array
    {
        self::assertCatalogProducts();
        $ids = collect($args['ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            throw new UserError('At least one product id is required.');
        }
        $deleted = 0;
        $service = app(ProductService::class);
        foreach (Product::query()->whereIn('id', $ids)->get() as $row) {
            try {
                $service->delete($row);
                $deleted++;
            } catch (\Throwable) {
                // Skip locked / in-use products — bulk-delete skips missing IDs.
            }
        }

        return ['ok' => true, 'updated_count' => $deleted];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, updated_count: int}
     */
    protected static function bulkUpdateAdminCatalogProductStatus(array $args): array
    {
        self::assertCatalogProducts();
        $ids = collect($args['ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            throw new UserError('At least one product id is required.');
        }
        $enabled = (bool) $args['enabled'];
        $updated = Product::query()->whereIn('id', $ids)->update(['is_enabled' => $enabled]);

        return ['ok' => true, 'updated_count' => (int) $updated];
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, status: bool, message: string, updatedCount: int, updated_count: int}
     */
    protected static function bulkUpdateProducts(array $args): array
    {
        self::assertCatalogProducts();
        $ids = collect($args['productIds'] ?? $args['ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($ids === []) {
            throw new UserError('At least one product id is required.');
        }
        $updates = [];
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $updates['is_enabled'] = (bool) $args['status'];
        } elseif (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $updates['is_enabled'] = (bool) $args['enabled'];
        }
        if (array_key_exists('weight', $args) && $args['weight'] !== null) {
            $weight = (float) $args['weight'];
            if ($weight < 0) {
                throw new UserError('Weight must be zero or greater.');
            }
            $updates['weight'] = $weight;
        }
        if ($updates === []) {
            throw new UserError('Provide status and/or weight to update.');
        }
        $updated = Product::query()->whereIn('id', $ids)->update($updates);

        return [
            'ok' => true,
            'status' => true,
            'message' => $updated.' product(s) updated.',
            'updatedCount' => (int) $updated,
            'updated_count' => (int) $updated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminCategory(Category $row): array
    {
        $locale = Language::defaultCode();
        $tr = $row->translation($locale);
        $parentId = $row->parent_id !== null ? (string) $row->parent_id : null;
        $row->loadMissing('translations');
        $translations = $row->translations->map(function ($t) {
            return [
                'locale' => $t->locale,
                'name' => $t->name,
                'slug' => $t->slug,
                'description' => $t->description ?? null,
                'metaTitle' => $t->meta_title ?? null,
                'metaDescription' => $t->meta_description ?? null,
                'metaKeywords' => $t->meta_keywords ?? null,
            ];
        })->values()->all();

        return [
            'id' => (string) $row->id,

            'name' => $tr?->name,
            'slug' => $tr?->slug,
            'is_enabled' => (bool) $row->is_enabled,
            'status' => $row->is_enabled ? 1 : 0,
            'parent_id' => $parentId,
            'parentId' => $parentId,
            'position' => (int) ($row->position ?? 0),
            'displayMode' => 'products_and_description',
            'translations' => json_encode($translations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'filterableAttributeIds' => '[]',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminCategories(array $args): array
    {
        self::assertCatalogCategories();
        $limit = max(1, min(200, (int) ($args['first'] ?? $args['limit'] ?? 50)));
        $q = Category::query()->with('translations')->orderBy('position')->orderBy('id');
        if (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $q->where('is_enabled', (bool) $args['enabled']);
        }
        if (array_key_exists('status', $args) && $args['status'] !== null) {
            $q->where('is_enabled', (int) $args['status'] === 1);
        }
        if (! empty($args['parentId'])) {
            $q->where('parent_id', (int) $args['parentId']);
        }
        $term = trim((string) ($args['q'] ?? $args['name'] ?? ''));
        if ($term !== '') {
            $op = DbGrammar::likeOperator();
            $q->whereHas('translations', function ($tq) use ($term, $op) {
                $tq->where('name', $op, '%'.$term.'%')
                    ->orWhere('slug', $op, '%'.$term.'%');
            });
        }

        return $q->limit($limit)->get()->map(fn (Category $row) => self::mapAdminCategory($row))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function deleteAdminCategoryMutation(array $args): array
    {
        self::assertCatalogCategories();
        $row = Category::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Category not found.');
        }
        $id = (string) $row->id;
        try {
            app(CategoryService::class)->delete($row);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();
            throw new UserError($messages[0] ?? 'Category could not be deleted.');
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, updated_count: int}
     */
    protected static function bulkDeleteAdminCategories(array $args): array
    {
        self::assertCatalogCategories();
        $ids = [];
        foreach (['ids'] as $key) {
            foreach ((array) ($args[$key] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        $deleted = 0;
        foreach ($ids as $id) {
            $row = Category::query()->find($id);
            if (! $row) {
                continue;
            }
            try {
                app(CategoryService::class)->delete($row);
                $deleted++;
            } catch (\Throwable) {
                // Best-effort bulk delete (missing / guarded ids skipped).
            }
        }

        return ['ok' => true, 'updated_count' => $deleted];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, updated_count: int}
     */
    protected static function bulkUpdateAdminCategoryStatus(array $args): array
    {
        self::assertCatalogCategories();
        $ids = [];
        foreach (['ids'] as $key) {
            foreach ((array) ($args[$key] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $enabled = (bool) $args['enabled'];
        } else {
            $raw = $args['status'] ?? $args['value'] ?? 1;
            $enabled = (int) $raw === 1 || $raw === true || $raw === '1';
        }
        $updated = 0;
        foreach ($ids as $id) {
            $row = Category::query()->find($id);
            if (! $row) {
                continue;
            }
            $row->is_enabled = $enabled;
            $row->save();
            $updated++;
        }

        return ['ok' => true, 'updated_count' => $updated];
    }

    protected static function assertCustomersManage(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminCustomerReview(Review $row): array
    {
        $locale = Language::defaultCode();
        $status = (string) ($row->status ?: ($row->is_approved ? Review::STATUS_APPROVED : Review::STATUS_PENDING));

        return [
            'id' => (string) $row->id,
            'product_id' => $row->product_id !== null ? (string) $row->product_id : null,
            'customer_id' => $row->customer_id !== null ? (string) $row->customer_id : null,
            'rating' => (int) $row->rating,
            'title' => $row->title,
            'body' => $row->body,
            'comment' => $row->body,
            'status' => $status,
            'is_approved' => (bool) $row->is_approved,
            'product_name' => $row->product?->translation($locale)?->name ?: $row->product?->sku,
            'customer_name' => $row->authorName(),
            'customer_email' => $row->customer?->email ?: $row->guest_email,
            'created_at' => optional($row->created_at)?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listAdminCustomerReviews(array $args): array
    {
        self::assertCustomersManage();
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $q = Review::query()->with(['product.translations', 'customer'])->orderByDesc('id');
        $status = trim((string) ($args['status'] ?? ''));
        if ($status !== '') {
            if ($status === 'disapproved') {
                $status = Review::STATUS_REJECTED;
            }
            $q->where('status', $status);
        }
        if (isset($args['rating'])) {
            $q->where('rating', (int) $args['rating']);
        }
        if (! empty($args['productId'])) {
            $q->where('product_id', (int) $args['productId']);
        }
        if (! empty($args['customerId'])) {
            $q->where('customer_id', (int) $args['customerId']);
        }

        return $q->limit($limit)->get()->map(fn (Review $row) => self::mapAdminCustomerReview($row))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminCustomerReview(array $args): array
    {
        self::assertCustomersManage();
        $row = Review::query()->with(['product.translations', 'customer'])->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Review not found.');
        }
        $status = strtolower(trim((string) $args['status']));
        if ($status === 'disapproved') {
            $status = Review::STATUS_REJECTED;
        }
        if (! in_array($status, [Review::STATUS_PENDING, Review::STATUS_APPROVED, Review::STATUS_REJECTED], true)) {
            throw new UserError('Status must be pending, approved, or disapproved.');
        }
        if ($status === Review::STATUS_APPROVED) {
            $row->approve();
        } elseif ($status === Review::STATUS_REJECTED) {
            $row->reject(isset($args['rejectedReason']) ? trim((string) $args['rejectedReason']) : null);
        } else {
            $row->pending();
        }

        return self::mapAdminCustomerReview($row->fresh(['product.translations', 'customer']));
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAdminCustomerAddress(CustomerAddress $a): array
    {
        $addressLines = trim(implode("\n", array_filter([(string) $a->line1, (string) $a->line2])));

        return [
            'id' => (string) $a->id,
            'customer_id' => (string) $a->customer_id,
            'customerId' => (string) $a->customer_id,
            'label' => $a->label,
            'first_name' => $a->first_name,
            'last_name' => $a->last_name,
            'firstName' => $a->first_name,
            'lastName' => $a->last_name,
            'line1' => $a->line1,
            'line2' => $a->line2,
            'address' => $addressLines !== '' ? $addressLines : $a->line1,
            'city' => $a->city,
            'state' => $a->state,
            'postal_code' => $a->postal_code,
            'zipCode' => $a->postal_code,
            'country_code' => $a->country_code,
            'country' => $a->country_code,
            'phone' => $a->phone,
            'phoneNumber' => $a->phone,
            'is_default_billing' => (bool) $a->is_default_billing,
            'is_default_shipping' => (bool) $a->is_default_shipping,
            'defaultBilling' => (bool) $a->is_default_billing,
            'defaultShipping' => (bool) $a->is_default_shipping,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateAdminCustomerAddress(array $args): array
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
            throw new UserError('Forbidden.');
        }
        $id = (int) ($args['id'] ?? $args['addressId'] ?? 0);
        $row = CustomerAddress::query()->find($id);
        if (! $row) {
            throw new UserError('Address not found.');
        }
        $country = strtoupper(trim((string) ($args['countryCode'] ?? $args['country'] ?? $row->country_code)));
        if (strlen($country) !== 2) {
            throw new UserError('Country must be a 2-letter ISO code.');
        }
        $line1 = trim((string) ($args['line1'] ?? $args['address'] ?? $row->line1));
        $data = [
            'first_name' => isset($args['firstName']) ? trim((string) $args['firstName']) : $row->first_name,
            'last_name' => isset($args['lastName']) ? trim((string) $args['lastName']) : $row->last_name,
            'line1' => $line1,
            'line2' => array_key_exists('line2', $args) ? (trim((string) $args['line2']) ?: null) : $row->line2,
            'city' => isset($args['city']) ? trim((string) $args['city']) : $row->city,
            'state' => array_key_exists('state', $args) ? (trim((string) $args['state']) ?: null) : $row->state,
            'postal_code' => array_key_exists('postalCode', $args) || array_key_exists('zipCode', $args)
                ? (trim((string) ($args['postalCode'] ?? $args['zipCode'] ?? '')) ?: null)
                : $row->postal_code,
            'country_code' => $country,
            'phone' => array_key_exists('phone', $args) || array_key_exists('phoneNumber', $args)
                ? (trim((string) ($args['phone'] ?? $args['phoneNumber'] ?? '')) ?: null)
                : $row->phone,
            'is_default_billing' => array_key_exists('defaultBilling', $args)
                ? (bool) $args['defaultBilling']
                : (bool) $row->is_default_billing,
            'is_default_shipping' => array_key_exists('defaultShipping', $args)
                ? (bool) $args['defaultShipping']
                : (bool) $row->is_default_shipping,
        ];
        $row->fill($data)->save();
        if ($row->is_default_billing) {
            CustomerAddress::query()->where('customer_id', $row->customer_id)->whereKeyNot($row->id)
                ->update(['is_default_billing' => false]);
        }
        if ($row->is_default_shipping) {
            CustomerAddress::query()->where('customer_id', $row->customer_id)->whereKeyNot($row->id)
                ->update(['is_default_shipping' => false]);
        }

        return self::mapAdminCustomerAddress($row->fresh());
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function updateProduct(array $args): array
    {
        self::assertCatalogProducts();
        $row = Product::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('Product not found.');
        }

        $locale = Language::defaultCode();
        $tr = $row->translation($locale);
        $name = array_key_exists('name', $args) ? trim((string) $args['name']) : (string) ($tr?->name ?? '');
        if ($name === '') {
            throw new UserError('Name is required when updating product name.');
        }

        $price = array_key_exists('price', $args) ? (float) $args['price'] : (float) ($row->price ?? 0);
        if ($price < 0) {
            throw new UserError('Price must be zero or greater.');
        }
        $stockQty = array_key_exists('stockQty', $args)
            ? (float) $args['stockQty']
            : (float) ($row->stock_qty ?? 0);
        if ($stockQty < 0) {
            throw new UserError('Stock quantity must be zero or greater.');
        }

        $slug = (string) ($tr?->slug ?? '');
        if ($slug === '') {
            $slug = Str::slug($name) ?: ('product-'.$row->id);
        }

        try {
            $row = app(ProductService::class)->save([
                'product' => [
                    'type' => $row->type ?: 'simple',
                    'sku' => $row->sku,
                    'price' => $price,
                    'stock_qty' => $stockQty,
                    'default_category_id' => $row->default_category_id,
                    'visibility' => $row->visibility ?: 'both',
                    'is_enabled' => array_key_exists('enabled', $args)
                        ? (bool) $args['enabled']
                        : (bool) $row->is_enabled,
                    'available_for_order' => (bool) ($row->available_for_order ?? true),
                    'show_price' => (bool) ($row->show_price ?? true),
                    'manage_stock' => (bool) ($row->manage_stock ?? true),
                    'available_all_carriers' => (bool) ($row->available_all_carriers ?? true),
                    'stock_status' => $stockQty > 0 ? 'in_stock' : 'out_of_stock',
                ],
                'translation' => [
                    'language_code' => $locale,
                    'name' => $name,
                    'slug' => $slug,
                    'short_description' => array_key_exists('shortDescription', $args)
                        ? (string) $args['shortDescription']
                        : ($tr?->short_description),
                    'description' => $tr?->description,
                ],
            ], $row);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();
            throw new UserError($messages[0] ?? 'Product could not be updated.');
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return self::mapAdminProduct($row->fresh() ?? $row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createStorefrontApiKey(array $args): array
    {
        return GraphqlAdminStorefrontKeys::create($args);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    protected static function revokeStorefrontApiKey(array $args): array
    {
        return GraphqlAdminStorefrontKeys::revoke((int) $args['id']);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapStorefrontApiKey(StorefrontApiKey $row, ?string $plain = null): array
    {
        return GraphqlAdminStorefrontKeys::map($row, $plain);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapIntegrationToken(PersonalAccessToken $token, ?string $plain): array
    {
        return GraphqlAdminIntegrations::map($token, $plain);
    }
}
