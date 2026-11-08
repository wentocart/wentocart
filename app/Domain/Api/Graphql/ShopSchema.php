<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Auth\SocialLogin;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Catalog\Services\ServiceProductService;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Customer\GdprSchema;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerAddress;
use App\Domain\Customer\Models\GdprRequest;
use App\Domain\Customer\Models\Review;
use App\Domain\Customer\Services\GdprService;
use App\Domain\Customer\Services\ReviewService;
use App\Domain\Customer\SupportService;
use App\Domain\Settings\CommunityFeatures;
use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Models\Language;
use App\Domain\Localization\Money;
use App\Domain\Marketing\Models\NewsletterSubscriber;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderDownload;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Services\OrderDownloadService;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Security\CustomerPassword;
use App\Domain\Security\SessionRevoker;
use App\Domain\Settings\CustomerSettings;
use App\Domain\Settings\GdprSettings;
use App\Domain\Settings\ProductSettings;
use App\Domain\Settings\SearchCatalogSettings;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Store\Models\Store;
use App\Domain\Store\ShopIdentity;
use App\Domain\Store\StoreContext;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Headless shop GraphQL surface.
 * Catalog, cart, placeOrder, wishlist, reviews, compare, addresses,
 * countries/locales/currencies/channels, paymentMethods, productBySku,
 * createReview, forgot/reset password.
 */
final class ShopSchema
{
    public static function build(): Schema
    {
        $productImageNode = new ObjectType([
            'name' => 'ProductImageNode',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'url' => Type::string(),
                'path' => Type::string(),
                'publicPath' => Type::string(),
                'alt' => Type::string(),
                'position' => Type::int(),
                'type' => Type::string(),
            ],
        ]);

        $productImageEdge = new ObjectType([
            'name' => 'ProductImageEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($productImageNode),
            ],
        ]);

        $productListing = new ObjectType([
            'name' => 'ProductListing',
            'fields' => [
                'id' => Type::id(),
                'url' => Type::string(),
                'metaTitle' => Type::string(),
                'metaDescription' => Type::string(),
                'metaKeywords' => Type::string(),
                'new' => Type::boolean(),
                'featured' => Type::boolean(),
                'status' => Type::int(),
            ],
        ]);

        $productsRelay = null;
        $productReviewConnection = null;
        $categoryConnection = null;
        $productAttributeValueConnection = null;
        $downloadableLinkConnection = null;
        $downloadableSampleConnection = null;
        $groupedProductConnection = null;
        $bundleOptionConnection = null;
        $customizableOptionConnection = null;
        $productVideoConnection = null;
        $bookingProductConnection = null;
        $shopAttributeConnection = null;
        $storeConnection = null;
        $productTemplateType = null;
        $productShopAttributeConnection = null;
        $productRatingSummary = new ObjectType([
            'name' => 'ProductRatingSummary',
            'fields' => [
                'rate' => Type::float(),
                'count' => Type::int(),
            ],
        ]);
        $product = new ObjectType([
            'name' => 'Product',
            'fields' => function () use (&$productsRelay, &$productReviewConnection, &$categoryConnection, &$productAttributeValueConnection, &$downloadableLinkConnection, &$downloadableSampleConnection, &$groupedProductConnection, &$bundleOptionConnection, &$customizableOptionConnection, &$productVideoConnection, &$bookingProductConnection, &$shopAttributeConnection, &$storeConnection, &$productTemplateType, &$productShopAttributeConnection, &$product, $productListing, $productImageEdge, $productRatingSummary) {
                $relationArgs = [
                    'first' => Type::int(),
                    'after' => Type::string(),
                    'limit' => Type::int(),
                ];
                $relationResolve = function (string $type) {
                    return function ($root, array $args) use ($type) {
                        $id = (int) ($root['_id'] ?? $root['id'] ?? 0);

                        return self::productRelationConnection($id, $type, $args);
                    };
                };

                return [
                    'id' => Type::nonNull(Type::id()),

                    'sku' => Type::string(),
                    'name' => Type::string(),
                    'slug' => Type::string(),
                    'url' => Type::string(),
                    'price' => Type::float(),
                    'formattedPrice' => Type::string(),
                    'comparePrice' => Type::float(),
                    'specialPrice' => Type::float(),
                    'cost' => Type::float(),
                    'weight' => Type::float(),
                    'inStock' => Type::boolean(),
                    'image' => Type::string(),
                    'baseImageUrl' => Type::string(),
                    'description' => Type::string(),
                    'shortDescription' => Type::string(),
                    'type' => Type::string(),
                    'status' => Type::int(),
                    'metaTitle' => Type::string(),
                    'metaDescription' => Type::string(),
                    'metaKeywords' => Type::string(),
                    'new' => Type::boolean(),
                    'featured' => Type::boolean(),
                    'isInWishlist' => Type::string(),
                    'isInCompare' => Type::string(),
                    'rating' => $productRatingSummary,
                    'listing' => $productListing,
                    'images' => new ObjectType([
                        'name' => 'ProductImagesConnection',
                        'fields' => [
                            'edges' => Type::nonNull(Type::listOf(Type::nonNull($productImageEdge))),
                            'totalCount' => Type::nonNull(Type::int()),
                        ],
                    ]),
                    'variantAxisOptions' => Type::string(),
                    'combinations' => Type::string(),
                    'relatedProducts' => [
                        'type' => Type::nonNull($productsRelay),
                        'args' => $relationArgs,
                        'resolve' => $relationResolve('related'),
                    ],
                    'upSells' => [
                        'type' => Type::nonNull($productsRelay),
                        'args' => $relationArgs,
                        'resolve' => $relationResolve('upsell'),
                    ],
                    'crossSells' => [
                        'type' => Type::nonNull($productsRelay),
                        'args' => $relationArgs,
                        'resolve' => $relationResolve('crosssell'),
                    ],
                    'approvedReviews' => [
                        'type' => Type::nonNull($productReviewConnection),
                        'args' => $relationArgs,
                        'resolve' => function ($root, array $args) {
                            $id = (int) ($root['_id'] ?? $root['id'] ?? 0);
                            $args['product_id'] = $id;
                            $args['status'] = 'approved';

                            return GraphqlProductReviews::connection($args);
                        },
                    ],
                    'reviews' => [
                        'type' => Type::nonNull($productReviewConnection),
                        'args' => array_merge($relationArgs, ['status' => Type::string()]),
                        'resolve' => function ($root, array $args) {
                            $id = (int) ($root['_id'] ?? $root['id'] ?? 0);
                            $args['product_id'] = $id;
                            if (! isset($args['status'])) {
                                $args['status'] = '';
                            }

                            return GraphqlProductReviews::connection($args);
                        },
                    ],
                    'categories' => [
                        'type' => Type::nonNull($categoryConnection),
                        'args' => $relationArgs,
                        'resolve' => function ($root, array $args) {
                            $id = (int) ($root['_id'] ?? $root['id'] ?? 0);

                            return self::productCategoriesConnection($id, $args);
                        },
                    ],
                    'attributeValues' => [
                        'type' => Type::nonNull($productAttributeValueConnection),
                        'args' => $relationArgs,
                        'resolve' => function ($root, array $args) {
                            $id = (int) ($root['_id'] ?? $root['id'] ?? 0);

                            return GraphqlShopAttributes::productAttributeValues($id, $args);
                        },
                    ],
                    'attributes' => [
                        'type' => Type::nonNull($productShopAttributeConnection),
                        'args' => $relationArgs,
                        'resolve' => function ($root, array $args) {
                            $id = (int) ($root['_id'] ?? $root['id'] ?? 0);

                            return GraphqlShopAttributes::productAttributes($id, $args);
                        },
                    ],
                    'downloadableLinks' => [
                        'type' => Type::nonNull($downloadableLinkConnection),
                        'args' => $relationArgs,
                        'resolve' => function ($root, array $args) {
                            $id = (int) ($root['_id'] ?? $root['id'] ?? 0);

                            return GraphqlProductDownloadables::links($id, $args);
                        },
                    ],
                    'downloadableSamples' => [
                        'type' => Type::nonNull($downloadableSampleConnection),
                        'args' => $relationArgs,
                        'resolve' => function ($root, array $args) {
                            $id = (int) ($root['_id'] ?? $root['id'] ?? 0);

                            return GraphqlProductDownloadables::samples($id, $args);
                        },
                    ],
                    'groupedProducts' => [
                        'type' => Type::nonNull($groupedProductConnection),
                        'args' => $relationArgs,
                        'resolve' => function ($root, array $args) {
                            $id = (int) ($root['_id'] ?? $root['id'] ?? 0);

                            return GraphqlProductComposition::groupedProducts($id, $args);
                        },
                    ],
                    'bundleOptions' => [
                        'type' => Type::nonNull($bundleOptionConnection),
                        'args' => $relationArgs,
                        'resolve' => function ($root, array $args) {
                            $id = (int) ($root['_id'] ?? $root['id'] ?? 0);

                            return GraphqlProductComposition::bundleOptions($id, $args);
                        },
                    ],
                    'customizableOptions' => [
                        'type' => Type::nonNull($customizableOptionConnection),
                        'args' => $relationArgs,
                        'resolve' => function ($root, array $args) {
                            $id = (int) ($root['_id'] ?? $root['id'] ?? 0);

                            return GraphqlProductComposition::customizableOptions($id, $args);
                        },
                    ],
                    'videos' => [
                        'type' => Type::nonNull($productVideoConnection),
                        'args' => $relationArgs,
                        'resolve' => function ($root, array $args) {
                            $id = (int) ($root['_id'] ?? $root['id'] ?? 0);

                            return self::productVideosConnection($id, $args);
                        },
                    ],
                    'bookingProducts' => [
                        'type' => Type::nonNull($bookingProductConnection),
                        'args' => $relationArgs,
                        'resolve' => function ($root, array $args) {
                            $id = (int) ($root['_id'] ?? $root['id'] ?? 0);

                            return GraphqlProductComposition::bookingProducts($id, $args);
                        },
                    ],
                    'variants' => [
                        'type' => Type::nonNull($productsRelay),
                        'args' => $relationArgs,
                        'resolve' => function ($root, array $args) {
                            $id = (int) ($root['_id'] ?? $root['id'] ?? 0);

                            return GraphqlProductConfigurable::variants($id, $args);
                        },
                    ],
                    'variantAxes' => [
                        'type' => Type::nonNull($shopAttributeConnection),
                        'args' => $relationArgs,
                        'resolve' => function ($root, array $args) {
                            $id = (int) ($root['_id'] ?? $root['id'] ?? 0);

                            return GraphqlProductConfigurable::variantAxes($id, $args);
                        },
                    ],
                    'productTemplate' => [
                        'type' => $productTemplateType,
                        'resolve' => function ($root) {
                            $id = (int) ($root['_id'] ?? $root['id'] ?? 0);

                            return GraphqlProductConfigurable::productTemplate($id);
                        },
                    ],
                    'stores' => [
                        'type' => Type::nonNull($storeConnection),
                        'args' => $relationArgs,
                        'resolve' => function ($root, array $args) {
                            $id = (int) ($root['_id'] ?? $root['id'] ?? 0);

                            return GraphqlProductConfigurable::stores($id, $args);
                        },
                    ],
                    'parent' => [
                        'type' => $product,
                        'resolve' => function ($root) {
                            $parentId = (int) ($root['parent_id'] ?? $root['parentId'] ?? 0);
                            if ($parentId < 1) {
                                return null;
                            }
                            $locale = app()->getLocale();
                            $row = Product::query()->where('is_enabled', true)->with(['translations', 'brand', 'images'])->find($parentId);

                            return $row ? self::mapProduct($row, $locale) : null;
                        },
                    ],
                    'bookingType' => Type::string(),
                ];
            },
        ]);

        $categoryTranslation = new ObjectType([
            'name' => 'CategoryTranslation',
            'fields' => [
                'id' => Type::id(),

                'categoryId' => Type::int(),
                'name' => Type::string(),
                'slug' => Type::string(),
                'urlPath' => Type::string(),
                'description' => Type::string(),
                'metaTitle' => Type::string(),
                'metaDescription' => Type::string(),
                'metaKeywords' => Type::string(),
                'locale' => Type::string(),
            ],
        ]);

        $category = new ObjectType([
            'name' => 'Category',
            'fields' => function () use (&$category, &$categoryConnection, $categoryTranslation) {
                return [
                    'id' => Type::nonNull(Type::id()),

                    'name' => Type::string(),
                    'slug' => Type::string(),
                    'url' => Type::string(),
                    'parent_id' => Type::id(),
                    'position' => Type::int(),
                    'status' => Type::int(),
                    'displayMode' => Type::string(),
                    'logoUrl' => Type::string(),
                    'bannerUrl' => Type::string(),
                    'logoPath' => Type::string(),
                    'bannerPath' => Type::string(),
                    'translation' => $categoryTranslation,
                    'children' => [
                        'type' => Type::nonNull($categoryConnection),
                        'args' => [
                            'first' => Type::int(),
                            'after' => Type::string(),
                            'limit' => Type::int(),
                        ],
                        'resolve' => function ($root, array $args) {
                            $id = (int) ($root['_id'] ?? $root['id'] ?? 0);

                            return self::categoryChildrenConnection($id, $args);
                        },
                    ],
                ];
            },
        ]);

        $shop = new ObjectType([
            'name' => 'Shop',
            'fields' => [
                'name' => Type::string(),
                'email' => Type::string(),
                'phone' => Type::string(),
                'currency' => Type::string(),
                'locale' => Type::string(),
            ],
        ]);

        $customer = new ObjectType([
            'name' => 'Customer',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'email' => Type::string(),
                'name' => Type::string(),
                'firstName' => Type::string(),
                'lastName' => Type::string(),
                'phone' => Type::string(),
            ],
        ]);

        $tokenRevoke = new ObjectType([
            'name' => 'TokenRevokeResult',
            'fields' => [
                'ok' => Type::nonNull(Type::boolean()),
            ],
        ]);

        $loginPayload = new ObjectType([
            'name' => 'CustomerLoginPayload',
            'fields' => [
                'id' => Type::id(),

                'access_token' => Type::nonNull(Type::string()),
                'accessToken' => Type::nonNull(Type::string()),
                'token' => Type::nonNull(Type::string()),
                'apiToken' => Type::string(),
                'token_type' => Type::nonNull(Type::string()),
                'success' => Type::nonNull(Type::boolean()),
                'message' => Type::string(),
                'customer' => Type::nonNull($customer),
            ],
        ]);

        $loginResult = new ObjectType([
            'name' => 'CustomerLoginResult',
            'fields' => [
                'id' => Type::id(),

                'access_token' => Type::nonNull(Type::string()),
                'accessToken' => Type::nonNull(Type::string()),
                'token' => Type::nonNull(Type::string()),
                'apiToken' => Type::string(),
                'token_type' => Type::nonNull(Type::string()),
                'success' => Type::nonNull(Type::boolean()),
                'message' => Type::string(),
                'customer' => Type::nonNull($customer),
                'customerLogin' => $loginPayload,
            ],
        ]);

        // Shop REST docs → createSocialLogin
        $socialLoginNested = new ObjectType([
            'name' => 'SocialLoginNested',
            'fields' => [
                'id' => Type::id(),

                'token' => Type::string(),
                'apiToken' => Type::string(),
                'firstName' => Type::string(),
                'lastName' => Type::string(),
                'email' => Type::string(),
                'phone' => Type::string(),
                'isNewCustomer' => Type::boolean(),
                'success' => Type::nonNull(Type::boolean()),
                'message' => Type::string(),
                'code' => Type::string(),
            ],
        ]);
        $socialLoginResult = new ObjectType([
            'name' => 'SocialLoginResult',
            'fields' => [
                'id' => Type::id(),

                'token' => Type::string(),
                'apiToken' => Type::string(),
                'accessToken' => Type::string(),
                'firstName' => Type::string(),
                'lastName' => Type::string(),
                'email' => Type::string(),
                'phone' => Type::string(),
                'isNewCustomer' => Type::boolean(),
                'success' => Type::nonNull(Type::boolean()),
                'message' => Type::string(),
                'code' => Type::string(),
                'customer' => $customer,
                'socialLogin' => $socialLoginNested,
            ],
        ]);

        $customerLoginInput = new InputObjectType([
            'name' => 'CreateCustomerLoginInput',
            'fields' => [
                'email' => Type::nonNull(Type::string()),
                'password' => Type::nonNull(Type::string()),
                'deviceName' => Type::string(),
            ],
        ]);

        $verifyTokenInput = new InputObjectType([
            'name' => 'CreateVerifyTokenInput',
            'fields' => [
                'token' => Type::string(),
            ],
        ]);

        $cartTokenInput = new InputObjectType([
            'name' => 'CreateCartTokenInput',
            'fields' => [
                'clientMutationId' => Type::string(),
            ],
        ]);

        $pageInfo = new ObjectType([
            'name' => 'PageInfo',
            'fields' => [
                'hasNextPage' => Type::nonNull(Type::boolean()),
                'hasPreviousPage' => Type::boolean(),
                'startCursor' => Type::string(),
                'endCursor' => Type::string(),
            ],
        ]);

        $shopAttributeOptionTranslation = new ObjectType([
            'name' => 'ShopAttributeOptionTranslation',
            'fields' => [
                'locale' => Type::string(),
                'label' => Type::string(),
            ],
        ]);

        $shopAttributeOption = new ObjectType([
            'name' => 'ShopAttributeOption',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'adminName' => Type::string(),
                'sortOrder' => Type::int(),
                'swatchValue' => Type::string(),
                'swatchValueUrl' => Type::string(),
                'translation' => $shopAttributeOptionTranslation,
            ],
        ]);

        $shopAttributeOptionEdge = new ObjectType([
            'name' => 'ShopAttributeOptionEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($shopAttributeOption),
            ],
        ]);

        $shopAttributeOptionConnection = new ObjectType([
            'name' => 'ShopAttributeOptionConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($shopAttributeOptionEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $shopAttributeTranslation = new ObjectType([
            'name' => 'ShopAttributeTranslation',
            'fields' => [
                'locale' => Type::string(),
                'name' => Type::string(),
            ],
        ]);

        $shopAttribute = new ObjectType([
            'name' => 'ShopAttribute',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'code' => Type::nonNull(Type::string()),
                'adminName' => Type::string(),
                'type' => Type::string(),
                'swatchType' => Type::string(),
                'position' => Type::int(),
                'minPrice' => Type::string(),
                'maxPrice' => Type::string(),
                'isRequired' => Type::string(),
                'isUnique' => Type::string(),
                'isFilterable' => Type::string(),
                'isComparable' => Type::string(),
                'isConfigurable' => Type::string(),
                'isUserDefined' => Type::string(),
                'isVisibleOnFront' => Type::string(),
                'valuePerLocale' => Type::string(),
                'valuePerStore' => Type::string(),
                'enableWysiwyg' => Type::string(),
                'translation' => $shopAttributeTranslation,
                'options' => $shopAttributeOptionConnection,
            ],
        ]);

        $shopAttributeEdge = new ObjectType([
            'name' => 'ShopAttributeEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($shopAttribute),
            ],
        ]);

        $shopAttributeConnection = new ObjectType([
            'name' => 'ShopAttributeConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($shopAttributeEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $productAttributeValueAttribute = new ObjectType([
            'name' => 'ProductAttributeValueAttribute',
            'fields' => [
                'id' => Type::id(),

                'code' => Type::string(),
                'adminName' => Type::string(),
            ],
        ]);

        $productAttributeValueNode = new ObjectType([
            'name' => 'ProductAttributeValue',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'value' => Type::string(),
                'attribute' => $productAttributeValueAttribute,
            ],
        ]);

        $productAttributeValueEdge = new ObjectType([
            'name' => 'ProductAttributeValueEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($productAttributeValueNode),
            ],
        ]);

        $productAttributeValueConnection = new ObjectType([
            'name' => 'ProductAttributeValueConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($productAttributeValueEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $productShopAttributeNode = new ObjectType([
            'name' => 'ProductShopAttribute',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'code' => Type::string(),
                'label' => Type::string(),
                'value' => Type::string(),
            ],
        ]);

        $productShopAttributeEdge = new ObjectType([
            'name' => 'ProductShopAttributeEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($productShopAttributeNode),
            ],
        ]);

        $productShopAttributeConnection = new ObjectType([
            'name' => 'ProductShopAttributeConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($productShopAttributeEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $downloadableTranslation = new ObjectType([
            'name' => 'ProductDownloadableTranslation',
            'fields' => [
                'locale' => Type::string(),
                'title' => Type::string(),
            ],
        ]);

        $downloadableLinkNode = new ObjectType([
            'name' => 'ProductDownloadableLink',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'price' => Type::float(),
                'formattedPrice' => Type::string(),
                'type' => Type::string(),
                'file' => Type::string(),
                'fileUrl' => Type::string(),
                'url' => Type::string(),
                'sampleFile' => Type::string(),
                'sampleFileUrl' => Type::string(),
                'sampleUrl' => Type::string(),
                'translation' => $downloadableTranslation,
            ],
        ]);

        $downloadableLinkEdge = new ObjectType([
            'name' => 'ProductDownloadableLinkEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($downloadableLinkNode),
            ],
        ]);

        $downloadableLinkConnection = new ObjectType([
            'name' => 'ProductDownloadableLinkConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($downloadableLinkEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $downloadableSampleNode = new ObjectType([
            'name' => 'ProductDownloadableSample',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'price' => Type::float(),
                'formattedPrice' => Type::string(),
                'type' => Type::string(),
                'file' => Type::string(),
                'fileUrl' => Type::string(),
                'url' => Type::string(),
                'sampleFile' => Type::string(),
                'sampleFileUrl' => Type::string(),
                'sampleUrl' => Type::string(),
                'translation' => $downloadableTranslation,
            ],
        ]);

        $downloadableSampleEdge = new ObjectType([
            'name' => 'ProductDownloadableSampleEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($downloadableSampleNode),
            ],
        ]);

        $downloadableSampleConnection = new ObjectType([
            'name' => 'ProductDownloadableSampleConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($downloadableSampleEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $groupedProductNode = new ObjectType([
            'name' => 'ProductGroupedProduct',
            'fields' => function () use (&$product) {
                return [
                    'id' => Type::nonNull(Type::id()),

                    'qty' => Type::int(),
                    'sortOrder' => Type::int(),
                    'isRequired' => Type::boolean(),
                    'optionGroup' => Type::string(),
                    'associatedProduct' => $product,
                ];
            },
        ]);

        $groupedProductEdge = new ObjectType([
            'name' => 'ProductGroupedProductEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($groupedProductNode),
            ],
        ]);

        $groupedProductConnection = new ObjectType([
            'name' => 'ProductGroupedProductConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($groupedProductEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $bundleOptionNode = new ObjectType([
            'name' => 'ProductBundleOption',
            'fields' => function () use (&$product) {
                return [
                    'id' => Type::nonNull(Type::id()),

                    'qty' => Type::int(),
                    'sortOrder' => Type::int(),
                    'isRequired' => Type::boolean(),
                    'optionGroup' => Type::string(),
                    'type' => Type::string(),
                    'label' => Type::string(),
                    'associatedProduct' => $product,
                ];
            },
        ]);

        $bundleOptionEdge = new ObjectType([
            'name' => 'ProductBundleOptionEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($bundleOptionNode),
            ],
        ]);

        $bundleOptionConnection = new ObjectType([
            'name' => 'ProductBundleOptionConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($bundleOptionEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $customizableOptionNode = new ObjectType([
            'name' => 'ProductCustomizableOption',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'type' => Type::string(),
                'label' => Type::string(),
                'isRequired' => Type::boolean(),
                'sortOrder' => Type::int(),
                'maxCharacters' => Type::int(),
                'price' => Type::float(),
                'priceType' => Type::string(),
                'options' => Type::string(),
            ],
        ]);

        $customizableOptionEdge = new ObjectType([
            'name' => 'ProductCustomizableOptionEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($customizableOptionNode),
            ],
        ]);

        $customizableOptionConnection = new ObjectType([
            'name' => 'ProductCustomizableOptionConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($customizableOptionEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $productVideoNode = new ObjectType([
            'name' => 'ProductVideo',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'type' => Type::string(),
                'path' => Type::string(),
                'url' => Type::string(),
                'position' => Type::int(),
            ],
        ]);

        $productVideoEdge = new ObjectType([
            'name' => 'ProductVideoEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($productVideoNode),
            ],
        ]);

        $productVideoConnection = new ObjectType([
            'name' => 'ProductVideoConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($productVideoEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $bookingAppointmentSlot = new ObjectType([
            'name' => 'BookingAppointmentSlot',
            'fields' => [
                'duration' => Type::float(),
                'breakTime' => Type::float(),
                'sameSlotAllDays' => Type::boolean(),
                'slots' => Type::string(),
            ],
        ]);
        $bookingRentalSlot = new ObjectType([
            'name' => 'BookingRentalSlot',
            'fields' => [
                'rentingType' => Type::string(),
                'dailyPrice' => Type::float(),
                'hourlyPrice' => Type::float(),
                'slots' => Type::string(),
            ],
        ]);
        $bookingDefaultSlot = new ObjectType([
            'name' => 'BookingDefaultSlot',
            'fields' => [
                'bookingType' => Type::string(),
                'duration' => Type::float(),
                'breakTime' => Type::float(),
                'slots' => Type::string(),
            ],
        ]);

        $bookingProductNode = new ObjectType([
            'name' => 'BookingProduct',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'type' => Type::string(),
                'qty' => Type::int(),
                'location' => Type::string(),
                'availableFrom' => Type::string(),
                'availableTo' => Type::string(),
                'startingPrice' => Type::float(),
                'formattedStartingPrice' => Type::string(),
                'startingRegularPrice' => Type::float(),
                'formattedStartingRegularPrice' => Type::string(),
                'duration' => Type::float(),
                'breakTime' => Type::float(),
                'appointmentSlot' => $bookingAppointmentSlot,
                'rentalSlot' => $bookingRentalSlot,
                'defaultSlot' => $bookingDefaultSlot,
                'tableSlot' => Type::string(),
                'eventTickets' => Type::string(),
            ],
        ]);

        $bookingProductEdge = new ObjectType([
            'name' => 'BookingProductEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($bookingProductNode),
            ],
        ]);

        $bookingProductConnection = new ObjectType([
            'name' => 'BookingProductConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($bookingProductEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $categoryEdge = new ObjectType([
            'name' => 'CategoryEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($category),
            ],
        ]);

        $categoryConnection = new ObjectType([
            'name' => 'CategoryConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($categoryEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $compareItemNode = new ObjectType([
            'name' => 'CompareItem',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'product' => $product,
                'customer' => $customer,
                'createdAt' => Type::string(),
                'updatedAt' => Type::string(),
            ],
        ]);

        $compareItemEdge = new ObjectType([
            'name' => 'CompareItemEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($compareItemNode),
            ],
        ]);

        $compareItemConnection = new ObjectType([
            'name' => 'CompareItemConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($compareItemEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $productEdge = new ObjectType([
            'name' => 'ProductEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($product),
            ],
        ]);

        $productsRelay = new ObjectType([
            'name' => 'ProductsRelayConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($productEdge))),
                'nodes' => Type::nonNull(Type::listOf(Type::nonNull($product))),
                'pageInfo' => Type::nonNull($pageInfo),
                'total' => Type::nonNull(Type::int()),
                'totalCount' => Type::nonNull(Type::int()),
                'totalItems' => Type::nonNull(Type::int()),
                // Legacy Wentocart offset page
                'items' => Type::nonNull(Type::listOf(Type::nonNull($product))),
            ],
        ]);

        $registerResult = new ObjectType([
            'name' => 'CustomerRegisterResult',
            'fields' => [
                'access_token' => Type::string(),
                'accessToken' => Type::string(),
                'token' => Type::string(),
                'token_type' => Type::string(),
                'success' => Type::boolean(),
                'message' => Type::string(),
                'customer' => Type::nonNull($customer),
                'pending' => Type::nonNull(Type::boolean()),
            ],
        ]);

        $shippingQuote = new ObjectType([
            'name' => 'ShippingQuote',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'code' => Type::string(),
                'title' => Type::string(),
                'description' => Type::string(),
                'price' => Type::float(),
                'basePrice' => Type::float(),
                'is_pickup' => Type::boolean(),
            ],
        ]);

        $countryRegion = new ObjectType([
            'name' => 'CountryRegion',
            'fields' => [
                'id' => Type::id(),
                'code' => Type::nonNull(Type::string()),
                'name' => Type::nonNull(Type::string()),
                'defaultName' => Type::string(),
            ],
        ]);

        $countryStateEdge = new ObjectType([
            'name' => 'CountryStateEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($countryRegion),
            ],
        ]);

        $countryStateConnection = new ObjectType([
            'name' => 'CountryStateConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($countryStateEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $country = new ObjectType([
            'name' => 'Country',
            'fields' => [
                'id' => Type::id(),

                'code' => Type::nonNull(Type::string()),
                'name' => Type::nonNull(Type::string()),
                'calling_code' => Type::string(),
                'has_regions' => Type::boolean(),
                'regions' => Type::nonNull(Type::listOf(Type::nonNull($countryRegion))),
                'states' => $countryStateConnection,
            ],
        ]);

        $countryEdge = new ObjectType([
            'name' => 'CountryEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($country),
            ],
        ]);

        $countryConnection = new ObjectType([
            'name' => 'CountryConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($countryEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $locale = new ObjectType([
            'name' => 'Locale',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'code' => Type::nonNull(Type::string()),
                'name' => Type::string(),
                'is_default' => Type::boolean(),
            ],
        ]);

        $localeEdge = new ObjectType([
            'name' => 'LocaleEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($locale),
            ],
        ]);

        $localeConnection = new ObjectType([
            'name' => 'LocaleConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($localeEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $currencyType = new ObjectType([
            'name' => 'Currency',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'code' => Type::nonNull(Type::string()),
                'name' => Type::string(),
                'symbol' => Type::string(),
                'exchange_rate' => Type::float(),
                'is_default' => Type::boolean(),
            ],
        ]);

        $storeRef = new ObjectType([
            'name' => 'Store',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'code' => Type::string(),
                'name' => Type::string(),
                'is_default' => Type::boolean(),
                'is_enabled' => Type::boolean(),
                'hostname' => Type::string(),
                'currencyCode' => Type::string(),
                'localeCode' => Type::string(),
                'rootCategoryId' => Type::id(),
                'theme' => Type::string(),
            ],
        ]);

        $storeEdge = new ObjectType([
            'name' => 'StorefrontStoreEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($storeRef),
            ],
        ]);

        $storeConnection = new ObjectType([
            'name' => 'StoreConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($storeEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $productTemplateType = new ObjectType([
            'name' => 'ProductTemplate',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'code' => Type::string(),
                'name' => Type::string(),
                'adminName' => Type::string(),
            ],
        ]);

        $themeInfo = new ObjectType([
            'name' => 'ShopTheme',
            'fields' => [
                'code' => Type::string(),
                'name' => Type::string(),
                'sectionTypes' => Type::listOf(Type::nonNull(Type::string())),
            ],
        ]);

        $homeModuleTranslation = new ObjectType([
            'name' => 'HomeModuleTranslation',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'homeModuleId' => Type::string(),
                'locale' => Type::string(),
                'options' => Type::string(),
            ],
        ]);

        $homeModule = new ObjectType([
            'name' => 'HomeModule',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'themeCode' => Type::string(),
                'type' => Type::nonNull(Type::string()),
                'name' => Type::string(),
                'sortOrder' => Type::int(),
                'status' => Type::string(),
                'storeId' => Type::string(),
                'moduleCode' => Type::string(),
                'createdAt' => Type::string(),
                'updatedAt' => Type::string(),
                'translation' => Type::nonNull($homeModuleTranslation),
            ],
        ]);

        $homeModuleEdge = new ObjectType([
            'name' => 'HomeModuleEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($homeModule),
            ],
        ]);

        $homeModuleConnection = new ObjectType([
            'name' => 'HomeModuleConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($homeModuleEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        // Shop REST → sections / section (Appearance published blocks)
        $shopSectionTranslation = new ObjectType([
            'name' => 'ShopSectionTranslation',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'sectionId' => Type::int(),
                'locale' => Type::string(),
                'options' => Type::string(),
            ],
        ]);

        $shopSection = new ObjectType([
            'name' => 'ShopSection',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'themeCode' => Type::string(),
                'storeId' => Type::int(),
                'type' => Type::nonNull(Type::string()),
                'name' => Type::string(),
                'sortOrder' => Type::int(),
                'status' => Type::string(),
                'createdAt' => Type::string(),
                'updatedAt' => Type::string(),
                'translation' => $shopSectionTranslation,
                'translations' => Type::listOf(Type::nonNull($shopSectionTranslation)),
            ],
        ]);

        $shopSectionEdge = new ObjectType([
            'name' => 'ShopSectionEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($shopSection),
            ],
        ]);

        $shopSectionConnection = new ObjectType([
            'name' => 'ShopSectionConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($shopSectionEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $paymentMethodType = new ObjectType([
            'name' => 'PaymentMethod',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'code' => Type::string(),
                'title' => Type::string(),
                'description' => Type::string(),
                'driver' => Type::string(),
                'image' => Type::string(),
                'sortOrder' => Type::int(),
            ],
        ]);

        $paymentMethodEdge = new ObjectType([
            'name' => 'PaymentMethodEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($paymentMethodType),
            ],
        ]);

        $paymentMethodConnection = new ObjectType([
            'name' => 'PaymentMethodConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($paymentMethodEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $cmsPage = new ObjectType([
            'name' => 'CmsPage',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'title' => Type::string(),
                'slug' => Type::string(),
                'short_description' => Type::string(),
                'body' => Type::string(),
                'meta_title' => Type::string(),
                'meta_description' => Type::string(),
            ],
        ]);

        $configuration = new ObjectType([
            'name' => 'ShopConfiguration',
            'fields' => [
                'store_name' => Type::string(),
                'store_email' => Type::string(),
                'currency' => Type::string(),
                'locale' => Type::string(),
                'guest_checkout' => Type::boolean(),
                'registration_enabled' => Type::boolean(),
            ],
        ]);

        $storeConfig = new ObjectType([
            'name' => 'ShopStoreConfig',
            'fields' => [
                'storeCode' => Type::string(),
                'locale' => Type::string(),
                'currency' => Type::string(),
                'storeName' => Type::string(),
                'storeEmail' => Type::string(),
                'guestCheckout' => Type::boolean(),
                'registrationEnabled' => Type::boolean(),
                'minimumOrderAmount' => Type::float(),
                'minimumOrderMessage' => Type::string(),
                'minimumOrderEnabled' => Type::boolean(),
                'wishlistEnabled' => Type::boolean(),
                'compareEnabled' => Type::boolean(),
                'compareMax' => Type::int(),
                'buyNowEnabled' => Type::boolean(),
                'imageSearchEnabled' => Type::boolean(),
                'productsPerPage' => Type::int(),
                'hideOutOfStock' => Type::boolean(),
                'catalogMode' => Type::boolean(),
                'showPrice' => Type::boolean(),
                'hidePricesGuests' => Type::boolean(),
                'emailVerification' => Type::string(),
                'newsletterEnabled' => Type::boolean(),
                'socialLoginProviders' => Type::listOf(Type::nonNull(Type::string())),
                'taxDisplay' => Type::string(),
                'reviewsEnabled' => Type::boolean(),
                'reviewWho' => Type::string(),
                'reviewModeration' => Type::string(),
                'gdprEnabled' => Type::boolean(),
                'euWithdrawalEnabled' => Type::boolean(),
                'miniCartOfferInfoEnabled' => Type::boolean(),
                'miniCartOfferInfo' => Type::string(),
                'couponsEnabled' => Type::boolean(),
                'checkoutLayout' => Type::string(),
                'maxCartItems' => Type::int(),
                'customerNotesEnabled' => Type::boolean(),
            ],
        ]);

        $cartItem = new ObjectType([
            'name' => 'CartItem',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'product_id' => Type::id(),
                'productId' => Type::id(),
                'name' => Type::string(),
                'qty' => Type::float(),
                'quantity' => Type::float(),
                'unit_price' => Type::float(),
                'basePrice' => Type::float(),
                'line_total' => Type::float(),
                'totalPrice' => Type::float(),
                'booking_date' => Type::string(),
                'booking_end_date' => Type::string(),
            ],
        ]);

        $cartItemEdge = new ObjectType([
            'name' => 'CartItemEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($cartItem),
            ],
        ]);

        $cartItemConnection = new ObjectType([
            'name' => 'CartItemConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($cartItemEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $customerBooking = new ObjectType([
            'name' => 'CustomerBooking',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'order_id' => Type::id(),
                'order_number' => Type::string(),
                'product_id' => Type::id(),
                'sku' => Type::string(),
                'name' => Type::string(),
                'qty' => Type::float(),
                'booking_date' => Type::string(),
                'booking_end_date' => Type::string(),
                'status' => Type::string(),
            ],
        ]);

        $bookingSlot = new ObjectType([
            'name' => 'BookingSlot',
            'fields' => [
                'slotId' => Type::string(),
                'from' => Type::string(),
                'to' => Type::string(),
                'timestamp' => Type::string(),
                'qty' => Type::string(),
                'time' => Type::string(),
                'slots' => Type::string(),
            ],
        ]);

        $cart = new ObjectType([
            'name' => 'Cart',
            'fields' => [
                'id' => Type::id(),
                'item_count' => Type::int(),
                'itemsCount' => Type::int(),
                'itemsQty' => Type::float(),
                'subtotal' => Type::float(),
                'discount' => Type::float(),
                'discountAmount' => Type::float(),
                'grand_total' => Type::float(),
                'grandTotal' => Type::float(),
                'taxTotal' => Type::float(),
                'shippingTotal' => Type::float(),
                'coupon_code' => Type::string(),
                'couponCode' => Type::string(),
                'items' => [
                    'type' => Type::nonNull($cartItemConnection),
                    'args' => [
                        'first' => Type::int(),
                        'after' => Type::string(),
                        'limit' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $list = is_array($root['itemsList'] ?? null)
                            ? $root['itemsList']
                            : (is_array($root['items'] ?? null) ? $root['items'] : []);

                        return self::cartItemsConnection($list, $args);
                    },
                ],
                // Wentocart list alias
                'itemsList' => Type::listOf(Type::nonNull($cartItem)),
            ],
        ]);

        $compatCartMutationResult = new ObjectType([
            'name' => 'CartMutationResult',
            'fields' => [
                'cart' => Type::nonNull($cart),
                'status' => Type::boolean(),
                'message' => Type::string(),
            ],
        ]);

        $statusResult = new ObjectType([
            'name' => 'StatusResult',
            'fields' => [
                'status' => Type::nonNull(Type::boolean()),
                'message' => Type::string(),
            ],
        ]);

        $cartTokenPayload = new ObjectType([
            'name' => 'CartTokenPayload',
            'fields' => [
                'id' => Type::id(),
                'cartToken' => Type::nonNull(Type::string()),
                'token' => Type::string(),
            ],
        ]);

        $cartTokenResult = new ObjectType([
            'name' => 'CartTokenResult',
            'fields' => [
                'cart_token' => Type::nonNull(Type::string()),
                'cartToken' => $cartTokenPayload,
                'cart' => Type::nonNull($cart),
            ],
        ]);

        $addressInput = new InputObjectType([
            'name' => 'AddressInput',
            'fields' => [
                'firstName' => Type::nonNull(Type::string()),
                'lastName' => Type::string(),
                'email' => Type::string(),
                'line1' => Type::nonNull(Type::string()),
                'address' => Type::string(),
                'line2' => Type::string(),
                'city' => Type::nonNull(Type::string()),
                'state' => Type::string(),
                'postalCode' => Type::string(),
                'phoneNumber' => Type::string(),
                'countryCode' => Type::nonNull(Type::string()),
                'phone' => Type::string(),
            ],
        ]);

        $customerOrderRef = null;
        $checkoutResult = new ObjectType([
            'name' => 'CheckoutResult',
            'fields' => function () use (&$customerOrderRef) {
                return [
                    'order_number' => Type::string(),
                    'order_id' => Type::id(),
                    'orderId' => Type::id(),
                    'grand_total' => Type::float(),
                    'payment_status' => Type::string(),
                    'checkout_reference' => Type::string(),
                    'success' => Type::boolean(),
                    'message' => Type::string(),
                    'redirect' => Type::boolean(),
                    'redirectUrl' => Type::string(),
                    'order' => $customerOrderRef,
                ];
            },
        ]);

        $checkoutAddressResult = new ObjectType([
            'name' => 'CheckoutAddressResult',
            'fields' => [
                'success' => Type::nonNull(Type::boolean()),
                'message' => Type::string(),
                'cartToken' => Type::string(),
                'billingFirstName' => Type::string(),
                'billingLastName' => Type::string(),
                'billingAddress' => Type::string(),
                'billingCity' => Type::string(),
                'billingState' => Type::string(),
                'billingPostcode' => Type::string(),
                'billingPhoneNumber' => Type::string(),
                'shippingFirstName' => Type::string(),
                'shippingLastName' => Type::string(),
                'shippingAddress' => Type::string(),
                'shippingCity' => Type::string(),
                'shippingState' => Type::string(),
                'shippingPostcode' => Type::string(),
                'shippingPhoneNumber' => Type::string(),
            ],
        ]);

        $checkoutMethodSaveResult = new ObjectType([
            'name' => 'CheckoutMethodSaveResult',
            'fields' => [
                'success' => Type::nonNull(Type::boolean()),
                'message' => Type::string(),
                'shippingMethodId' => Type::id(),
                'paymentMethodId' => Type::id(),
            ],
        ]);

        $checkoutPaymentMethod = new ObjectType([
            'name' => 'CheckoutPaymentMethod',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'code' => Type::string(),
                'title' => Type::string(),
            ],
        ]);

        $review = new ObjectType([
            'name' => 'Review',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'name' => Type::string(),
                'rating' => Type::int(),
                'title' => Type::string(),
                'comment' => Type::string(),
                'body' => Type::string(),
                'status' => Type::string(),
                'attachments' => Type::string(),
                'customer_name' => Type::string(),
                'productId' => Type::id(),
                'createdAt' => Type::string(),
                'updatedAt' => Type::string(),
                'created_at' => Type::string(),
            ],
        ]);

        $productReviewEdge = new ObjectType([
            'name' => 'ProductReviewEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($review),
            ],
        ]);

        $productReviewConnection = new ObjectType([
            'name' => 'ProductReviewConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($productReviewEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);
        $productReviewPayloadData = new ObjectType([
            'name' => 'ProductReviewPayloadData',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'name' => Type::string(),
                'title' => Type::string(),
                'rating' => Type::nonNull(Type::int()),
                'comment' => Type::string(),
                'status' => Type::nonNull(Type::string()),
                'attachments' => Type::string(),
                'createdAt' => Type::string(),
                'updatedAt' => Type::string(),
            ],
        ]);
        $createProductReviewResult = new ObjectType([
            'name' => 'CreateProductReviewResult',
            'fields' => [
                'productReview' => Type::nonNull($productReviewPayloadData),
                'clientMutationId' => Type::string(),
            ],
        ]);

        $updateProductReviewInput = new InputObjectType([
            'name' => 'UpdateProductReviewInput',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'productId' => Type::int(),
                'title' => Type::string(),
                'comment' => Type::string(),
                'rating' => Type::int(),
                'name' => Type::string(),
                'email' => Type::string(),
                'clientMutationId' => Type::string(),
            ],
        ]);

        $deleteProductReviewInput = new InputObjectType([
            'name' => 'DeleteProductReviewInput',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'clientMutationId' => Type::string(),
            ],
        ]);

        $deleteProductReviewPayloadData = new ObjectType([
            'name' => 'DeleteProductReviewPayloadData',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
            ],
        ]);

        $updateProductReviewResult = new ObjectType([
            'name' => 'UpdateProductReviewResult',
            'fields' => [
                'productReview' => Type::nonNull($productReviewPayloadData),
                'clientMutationId' => Type::string(),
            ],
        ]);

        $deleteProductReviewResult = new ObjectType([
            'name' => 'DeleteProductReviewResult',
            'fields' => [
                'productReview' => Type::nonNull($deleteProductReviewPayloadData),
                'clientMutationId' => Type::string(),
            ],
        ]);

        $wishlistToggle = new ObjectType([
            'name' => 'WishlistToggleResult',
            'fields' => [
                'wishlisted' => Type::nonNull(Type::boolean()),
                'product_id' => Type::nonNull(Type::id()),
            ],
        ]);

        $wishlistItem = new ObjectType([
            'name' => 'WishlistItem',
            'fields' => function () use (&$product, &$customer) {
                return [
                    'id' => Type::nonNull(Type::id()),

                    'product' => $product,
                    'customer' => $customer,
                    'createdAt' => Type::string(),
                    'updatedAt' => Type::string(),
                ];
            },
        ]);

        $wishlistItemEdge = new ObjectType([
            'name' => 'WishlistItemEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($wishlistItem),
            ],
        ]);

        $wishlistItemConnection = new ObjectType([
            'name' => 'WishlistItemConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($wishlistItemEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $createWishlistResult = new ObjectType([
            'name' => 'CreateWishlistResult',
            'fields' => [
                'wishlist' => Type::nonNull($wishlistItem),
                'clientMutationId' => Type::string(),
                // Legacy fields kept for older clients
                'wishlisted' => Type::boolean(),
                'product_id' => Type::id(),
            ],
        ]);

        $compatWishlistBook = new ObjectType([
            'name' => 'CustomerWishlist',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'items' => Type::nonNull($wishlistItemConnection),
            ],
        ]);

        $compatWishlistActionResult = new ObjectType([
            'name' => 'WishlistActionResult',
            'fields' => [
                'status' => Type::nonNull(Type::boolean()),
                'message' => Type::string(),
                'wishlist' => $compatWishlistBook,
            ],
        ]);

        $deleteWishlistResult = new ObjectType([
            'name' => 'DeleteWishlistResult',
            'fields' => [
                'wishlist' => Type::nonNull(new ObjectType([
                    'name' => 'DeleteWishlistPayloadData',
                    'fields' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                ])),
                'clientMutationId' => Type::string(),
                'wishlisted' => Type::boolean(),
                'product_id' => Type::id(),
            ],
        ]);

        $moveWishlistToCartResult = new ObjectType([
            'name' => 'MoveWishlistToCartResult',
            'fields' => function () use (&$cart) {
                return [
                    'wishlistToCart' => Type::nonNull(new ObjectType([
                        'name' => 'WishlistToCartPayload',
                        'fields' => [
                            'message' => Type::nonNull(Type::string()),
                        ],
                    ])),
                    'cart' => $cart,
                    'clientMutationId' => Type::string(),
                ];
            },
        ]);

        $compareToggle = new ObjectType([
            'name' => 'CompareToggleResult',
            'fields' => [
                'compared' => Type::nonNull(Type::boolean()),
                'product_id' => Type::nonNull(Type::id()),
                'ids' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
            ],
        ]);

        $address = new ObjectType([
            'name' => 'CustomerAddress',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'addressId' => Type::int(),
                'addressType' => Type::string(),
                'label' => Type::string(),
                'name' => Type::string(),
                'firstName' => Type::string(),
                'lastName' => Type::string(),
                'email' => Type::string(),
                'phone' => Type::string(),
                'phoneNumber' => Type::string(),
                'companyName' => Type::string(),
                'vatId' => Type::string(),
                'address' => Type::string(),
                'address1' => Type::string(),
                'address2' => Type::string(),
                'line1' => Type::string(),
                'line2' => Type::string(),
                'city' => Type::string(),
                'state' => Type::string(),
                'country' => Type::string(),
                'country_code' => Type::string(),
                'postcode' => Type::string(),
                'postal_code' => Type::string(),
                'zipCode' => Type::string(),
                'defaultAddress' => Type::boolean(),
                'useForShipping' => Type::boolean(),
                'defaultBilling' => Type::boolean(),
                'defaultShipping' => Type::boolean(),
                'is_default_shipping' => Type::boolean(),
                'is_default_billing' => Type::boolean(),
                'createdAt' => Type::string(),
                'updatedAt' => Type::string(),
            ],
        ]);

        $customerAddressMutationResult = new ObjectType([
            'name' => 'CustomerAddressMutationResult',
            'fields' => [
                'address' => Type::nonNull($address),
            ],
        ]);

        $storeSettingNode = new ObjectType([
            'name' => 'StoreSetting',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'code' => Type::nonNull(Type::string()),
                'value' => Type::string(),
                'storeCode' => Type::string(),
                'localeCode' => Type::string(),
            ],
        ]);

        $storeSettingEdge = new ObjectType([
            'name' => 'StoreSettingEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($storeSettingNode),
            ],
        ]);

        $storeSettingConnection = new ObjectType([
            'name' => 'StoreSettingsConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($storeSettingEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $customerAddressEdge = new ObjectType([
            'name' => 'CustomerAddressEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($address),
            ],
        ]);

        $customerAddressConnection = new ObjectType([
            'name' => 'CustomerAddressConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($customerAddressEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $addUpdateCustomerAddressInput = new InputObjectType([
            'name' => 'CreateAddUpdateCustomerAddressInput',
            'fields' => [
                'addressId' => Type::id(),
                'id' => Type::id(),
                'firstName' => Type::nonNull(Type::string()),
                'lastName' => Type::string(),
                'email' => Type::string(),
                'phone' => Type::string(),
                'phoneNumber' => Type::string(),
                'companyName' => Type::string(),
                'vatId' => Type::string(),
                'address1' => Type::string(),
                'address2' => Type::string(),
                'address' => Type::string(),
                'line1' => Type::string(),
                'line2' => Type::string(),
                'city' => Type::nonNull(Type::string()),
                'state' => Type::string(),
                'country' => Type::string(),
                'countryCode' => Type::string(),
                'postcode' => Type::string(),
                'postalCode' => Type::string(),
                'zipCode' => Type::string(),
                'defaultAddress' => Type::boolean(),
                'useForShipping' => Type::boolean(),
                'defaultBilling' => Type::boolean(),
                'defaultShipping' => Type::boolean(),
                'isDefaultBilling' => Type::boolean(),
                'isDefaultShipping' => Type::boolean(),
            ],
        ]);

        $addUpdateCustomerAddressResult = new ObjectType([
            'name' => 'CreateAddUpdateCustomerAddressResult',
            'fields' => [
                'addUpdateCustomerAddress' => Type::nonNull($address),
            ],
        ]);

        $deleteCustomerAddressPayload = new ObjectType([
            'name' => 'DeleteCustomerAddressPayload',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'success' => Type::nonNull(Type::boolean()),
                'message' => Type::string(),
            ],
        ]);

        $deleteCustomerAddressResult = new ObjectType([
            'name' => 'CreateDeleteCustomerAddressResult',
            'fields' => [
                'deleteCustomerAddress' => Type::nonNull($deleteCustomerAddressPayload),
            ],
        ]);

        $deleteCustomerAddressInput = new InputObjectType([
            'name' => 'CreateDeleteCustomerAddressInput',
            'fields' => [
                'id' => Type::id(),
                'addressId' => Type::id(),
            ],
        ]);

        $customerOrderAddress = new ObjectType([
            'name' => 'CustomerOrderAddress',
            'fields' => [
                'id' => Type::id(),
                'firstName' => Type::string(),
                'lastName' => Type::string(),
                'address' => Type::string(),
                'line1' => Type::string(),
                'line2' => Type::string(),
                'city' => Type::string(),
                'state' => Type::string(),
                'country' => Type::string(),
                'zipCode' => Type::string(),
                'phoneNumber' => Type::string(),
                'email' => Type::string(),
            ],
        ]);

        $customerOrderItem = new ObjectType([
            'name' => 'CustomerOrderItem',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'productId' => Type::id(),
                'productName' => Type::string(),
                'name' => Type::string(),
                'sku' => Type::string(),
                'quantity' => Type::float(),
                'qty' => Type::float(),
                'price' => Type::float(),
                'totalPrice' => Type::float(),
                'total' => Type::float(),
            ],
        ]);

        $customerOrderItemEdge = new ObjectType([
            'name' => 'CustomerOrderItemEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($customerOrderItem),
            ],
        ]);

        $customerOrderItemConnection = new ObjectType([
            'name' => 'CustomerOrderItemConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($customerOrderItemEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $customerShipmentConnection = null;
        $customerOrder = new ObjectType([
            'name' => 'CustomerOrder',
            'fields' => function () use (&$customerShipmentConnection, $customerOrderItemConnection, $customerOrderAddress) {
                $connArgs = [
                    'first' => Type::int(),
                    'after' => Type::string(),
                    'limit' => Type::int(),
                ];

                return [
                    'id' => Type::nonNull(Type::id()),

                    'number' => Type::string(),
                    'incrementId' => Type::string(),
                    'status' => Type::string(),
                    'payment_status' => Type::string(),
                    'grand_total' => Type::float(),
                    'grandTotal' => Type::float(),
                    'subtotal' => Type::float(),
                    'taxTotal' => Type::float(),
                    'shippingTotal' => Type::float(),
                    'discountAmount' => Type::float(),
                    'couponCode' => Type::string(),
                    'currency_code' => Type::string(),
                    'orderCurrencyCode' => Type::string(),
                    'shippingMethod' => Type::string(),
                    'paymentMethod' => Type::string(),
                    'itemsCount' => Type::int(),
                    'created_at' => Type::string(),
                    'createdAt' => Type::string(),
                    'billingAddress' => $customerOrderAddress,
                    'shippingAddress' => $customerOrderAddress,
                    'items' => [
                        'type' => Type::nonNull($customerOrderItemConnection),
                        'args' => $connArgs,
                        'resolve' => function ($root, array $args) {
                            $orderId = (int) ($root['_id'] ?? $root['id'] ?? 0);
                            $args['orderId'] = $orderId;

                            return GraphqlCustomerAccountExtras::orderItemsConnection($args);
                        },
                    ],
                    'shipments' => [
                        'type' => Type::nonNull($customerShipmentConnection),
                        'args' => $connArgs,
                        'resolve' => function ($root, array $args) {
                            $orderId = (int) ($root['_id'] ?? $root['id'] ?? 0);
                            $args['orderId'] = $orderId;

                            return GraphqlCustomerAccountExtras::shipmentsConnection($args);
                        },
                    ],
                ];
            },
        ]);
        $customerOrderRef = $customerOrder;

        $customerOrderEdge = new ObjectType([
            'name' => 'CustomerOrderEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($customerOrder),
            ],
        ]);

        $customerOrderConnection = new ObjectType([
            'name' => 'CustomerOrderConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($customerOrderEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
            ],
        ]);

        $customerProfile = new ObjectType([
            'name' => 'CustomerProfile',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'firstName' => Type::string(),
                'lastName' => Type::string(),
                'email' => Type::string(),
                'phone' => Type::string(),
                'gender' => Type::string(),
                'dateOfBirth' => Type::string(),
                'status' => Type::string(),
                'subscribedToNewsLetter' => Type::boolean(),
                'isVerified' => Type::string(),
                'isSuspended' => Type::string(),
                'image' => Type::string(),
                'name' => Type::string(),
                'success' => Type::boolean(),
                'message' => Type::string(),
            ],
        ]);

        $customerProfileUpdateResult = new ObjectType([
            'name' => 'CustomerProfileUpdateResult',
            'fields' => [
                'customerProfileUpdate' => Type::nonNull($customerProfile),
            ],
        ]);

        $verifyTokenPayload = new ObjectType([
            'name' => 'VerifyTokenPayload',
            'fields' => [
                'isValid' => Type::boolean(),
                'valid' => Type::boolean(),
                'success' => Type::boolean(),
                'message' => Type::string(),
                'customer' => $customer,
            ],
        ]);

        $accountActionResult = new ObjectType([
            'name' => 'CustomerAccountActionResult',
            'fields' => [
                'success' => Type::nonNull(Type::boolean()),
                'status' => Type::boolean(),
                'valid' => Type::boolean(),
                'isValid' => Type::boolean(),
                'message' => Type::string(),
                'customer' => $customer,
                'verifyToken' => $verifyTokenPayload,
            ],
        ]);

        $newsletterResult = new ObjectType([
            'name' => 'NewsletterResult',
            'fields' => [
                'ok' => Type::nonNull(Type::boolean()),
                'success' => Type::boolean(),
                'status' => Type::boolean(),
                'message' => Type::string(),
            ],
        ]);

        $gdprStatus = new ObjectType([
            'name' => 'GdprStatus',
            'fields' => [
                'enabled' => Type::nonNull(Type::boolean()),
            ],
        ]);

        $gdprRequest = new ObjectType([
            'name' => 'GdprRequest',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'reference' => Type::string(),
                'type' => Type::nonNull(Type::string()),
                'status' => Type::nonNull(Type::string()),
                'message' => Type::string(),
                'email' => Type::string(),
                'successMessage' => Type::string(),
                'revokedAt' => Type::string(),
                'createdAt' => Type::string(),
                'updatedAt' => Type::string(),
                'created_at' => Type::string(),
                'can_revoke' => Type::boolean(),
            ],
        ]);

        $downloadableProduct = new ObjectType([
            'name' => 'CustomerDownloadableProduct',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'productName' => Type::string(),
                'name' => Type::string(),
                'fileName' => Type::string(),
                'type' => Type::string(),
                'downloadBought' => Type::int(),
                'downloadUsed' => Type::int(),
                'downloadCanceled' => Type::int(),
                'status' => Type::string(),
                'downloadUrl' => Type::string(),
                'remainingDownloads' => Type::int(),
                'order_number' => Type::string(),
                'created_at' => Type::string(),
            ],
        ]);

        $contactUsResult = new ObjectType([
            'name' => 'ContactUsResult',
            'fields' => [
                'ok' => Type::nonNull(Type::boolean()),
                'success' => Type::nonNull(Type::boolean()),
                'message' => Type::string(),
            ],
        ]);

        $reorderOrderResult = new ObjectType([
            'name' => 'ReorderOrderResult',
            'fields' => [
                'success' => Type::nonNull(Type::boolean()),
                'message' => Type::nonNull(Type::string()),
                'orderId' => Type::nonNull(Type::id()),
                'itemsAddedCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $customerOwnedReview = new ObjectType([
            'name' => 'CustomerOwnedReview',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'productId' => Type::id(),
                'rating' => Type::int(),
                'title' => Type::string(),
                'comment' => Type::string(),
                'body' => Type::string(),
                'status' => Type::string(),
                'createdAt' => Type::string(),
                'created_at' => Type::string(),
            ],
        ]);

        $customerInvoiceItem = new ObjectType([
            'name' => 'CustomerInvoiceItem',
            'fields' => [
                'id' => Type::id(),
                'name' => Type::string(),
                'sku' => Type::string(),
                'qty' => Type::int(),
                'price' => Type::float(),
                'total' => Type::float(),
            ],
        ]);

        $customerInvoice = new ObjectType([
            'name' => 'CustomerInvoice',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'orderId' => Type::id(),
                'incrementId' => Type::string(),
                'invoiceNumber' => Type::string(),
                'invoice_number' => Type::string(),
                'status' => Type::string(),
                'grandTotal' => Type::float(),
                'grand_total' => Type::float(),
                'currencyCode' => Type::string(),
                'createdAt' => Type::string(),
                'downloadUrl' => Type::string(),
                'items' => Type::listOf(Type::nonNull($customerInvoiceItem)),
            ],
        ]);

        $customerShipmentItem = new ObjectType([
            'name' => 'CustomerShipmentItem',
            'fields' => [
                'id' => Type::nonNull(Type::id()),
                'name' => Type::string(),
                'sku' => Type::string(),
                'qty' => Type::float(),
            ],
        ]);

        $customerShipment = new ObjectType([
            'name' => 'CustomerShipment',
            'fields' => [
                'id' => Type::nonNull(Type::id()),

                'orderId' => Type::id(),
                'incrementId' => Type::string(),
                'status' => Type::string(),
                'carrier' => Type::string(),
                'carrierTitle' => Type::string(),
                'trackingNumber' => Type::string(),
                'trackNumber' => Type::string(),
                'trackingUrl' => Type::string(),
                'shippedAt' => Type::string(),
                'deliveredAt' => Type::string(),
                'createdAt' => Type::string(),
                'items' => Type::listOf(Type::nonNull($customerShipmentItem)),
            ],
        ]);

        $customerShipmentEdge = new ObjectType([
            'name' => 'CustomerShipmentEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($customerShipment),
            ],
        ]);

        $customerShipmentConnection = new ObjectType([
            'name' => 'CustomerShipmentConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($customerShipmentEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $shippingMethodEdge = new ObjectType([
            'name' => 'ShippingMethodEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($shippingQuote),
            ],
        ]);

        $shippingMethodConnection = new ObjectType([
            'name' => 'ShippingMethodConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($shippingMethodEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $estimateShippingResult = new ObjectType([
            'name' => 'EstimateShippingResult',
            'fields' => [
                'shippingMethods' => Type::nonNull($shippingMethodConnection),
            ],
        ]);

        $currencyEdge = new ObjectType([
            'name' => 'CurrencyEdge',
            'fields' => [
                'cursor' => Type::nonNull(Type::string()),
                'node' => Type::nonNull($currencyType),
            ],
        ]);

        $currencyConnection = new ObjectType([
            'name' => 'CurrencyConnection',
            'fields' => [
                'edges' => Type::nonNull(Type::listOf(Type::nonNull($currencyEdge))),
                'pageInfo' => Type::nonNull($pageInfo),
                'totalCount' => Type::nonNull(Type::int()),
            ],
        ]);

        $compatCancelOrderInner = new ObjectType([
            'name' => 'CancelOrderInner',
            'fields' => [
                'success' => Type::nonNull(Type::boolean()),
                'message' => Type::string(),
                'orderId' => Type::id(),
                'status' => Type::string(),
            ],
        ]);

        $cancelOrderResult = new ObjectType([
            'name' => 'CancelOrderResult',
            'fields' => [
                'success' => Type::nonNull(Type::boolean()),
                'message' => Type::string(),
                'order' => Type::nonNull($customerOrder),
                'cancelOrder' => $compatCancelOrderInner,
            ],
        ]);

        $query = new ObjectType([
            'name' => 'Query',
            'fields' => [
                'shop' => [
                    'type' => Type::nonNull($shop),
                    'resolve' => function () {
                        $locale = app()->getLocale();
                        $store = app(StoreContext::class)->store();
                        $identity = ShopIdentity::forStorefront($store, $locale);

                        return [
                            'name' => $identity['name'] ?? null,
                            'email' => $identity['email'] ?? ($store?->email),
                            'phone' => $identity['phone'] ?? null,
                            'currency' => (string) (config('wento.default_currency') ?: 'USD'),
                            'locale' => $locale,
                        ];
                    },
                ],
                'me' => [
                    'type' => $customer,
                    'resolve' => function () {
                        $user = auth('customer')->user();
                        if (! $user) {
                            return null;
                        }

                        return [
                            'id' => (string) $user->id,
                            'email' => $user->email,
                            'name' => $user->displayName(),
                            'phone' => $user->phone,
                        ];
                    },
                ],
                // Shop REST → readCustomerProfile
                'readCustomerProfile' => [
                    'type' => Type::nonNull($customerProfile),
                    'resolve' => fn () => GraphqlCustomerAccount::readProfile(),
                ],
                'createCustomerProfile' => [
                    'type' => Type::nonNull($customerProfile),
                    'resolve' => fn () => GraphqlCustomerAccount::readProfile(),
                ],
                // Store product lookup: id | sku | slug
                'product' => [
                    'type' => $product,
                    'args' => [
                        'id' => Type::id(),
                        'sku' => Type::string(),
                        'slug' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $locale = app()->getLocale();
                        $row = self::findShopProduct($args, $locale);

                        return $row ? self::mapProduct($row, $locale) : null;
                    },
                ],
                'productByUrl' => [
                    'type' => $product,
                    'args' => [
                        'url' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        $locale = app()->getLocale();
                        $slug = trim((string) $args['url']);
                        $slug = trim($slug, '/');
                        if (str_contains($slug, '/')) {
                            $slug = (string) Str::afterLast($slug, '/');
                        }
                        if ($slug === '') {
                            return null;
                        }
                        $row = Product::query()
                            ->where('is_enabled', true)
                            ->whereHas('translations', fn ($q) => $q->where('slug', $slug)->where('language_code', $locale))
                            ->with(['translations', 'brand', 'images'])
                            ->first();
                        if (! $row) {
                            $row = Product::query()
                                ->where('is_enabled', true)
                                ->whereHas('translations', fn ($q) => $q->where('slug', $slug))
                                ->with(['translations', 'brand', 'images'])
                                ->first();
                        }

                        return $row ? self::mapProduct($row, $locale) : null;
                    },
                ],
                'compareList' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($product))),
                    'resolve' => function () {
                        return self::resolveCompareList();
                    },
                ],
                // Shop REST → Compare (cursor connection)
                'compareItems' => [
                    'type' => Type::nonNull($compareItemConnection),
                    'args' => [
                        'first' => Type::int(),
                        'after' => Type::string(),
                        'limit' => Type::int(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCatalogConnections::compareItems($args),
                ],
                'compareItem' => [
                    'type' => $compareItemNode,
                    'args' => [
                        'id' => Type::id(),
                        'productId' => Type::id(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCatalogConnections::compareItem($args),
                ],
                'productBySku' => [
                    'type' => $product,
                    'args' => [
                        'sku' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        $locale = app()->getLocale();
                        $sku = trim((string) $args['sku']);
                        if ($sku === '') {
                            return null;
                        }
                        $row = Product::query()
                            ->where('is_enabled', true)
                            ->whereRaw('LOWER(sku) = ?', [mb_strtolower($sku)])
                            ->with(['translations', 'brand', 'images'])
                            ->first();

                        return $row ? self::mapProduct($row, $locale) : null;
                    },
                ],
                'productBySkU' => [
                    'type' => $product,
                    'args' => [
                        'sku' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        $locale = app()->getLocale();
                        $sku = trim((string) $args['sku']);
                        if ($sku === '') {
                            return null;
                        }
                        $row = Product::query()
                            ->where('is_enabled', true)
                            ->whereRaw('LOWER(sku) = ?', [mb_strtolower($sku)])
                            ->with(['translations', 'brand', 'images'])
                            ->first();

                        return $row ? self::mapProduct($row, $locale) : null;
                    },
                ],
                // Shop REST → products(first/after/query/filter) cursor connection (+ legacy items/total)
                'products' => [
                    'type' => Type::nonNull($productsRelay),
                    'args' => [
                        'q' => Type::string(),
                        'query' => Type::string(),
                        'filter' => Type::string(),
                        'categoryId' => Type::id(),
                        'minPrice' => Type::float(),
                        'maxPrice' => Type::float(),
                        'first' => Type::int(),
                        'after' => Type::string(),
                        'limit' => Type::int(),
                        'offset' => Type::int(),
                        'sortKey' => Type::string(),
                        'reverse' => Type::boolean(),
                    ],
                    'resolve' => fn ($root, array $args) => self::resolveProductsConnection($args),
                ],
                'productsConnection' => [
                    'type' => Type::nonNull($productsRelay),
                    'args' => [
                        'q' => Type::string(),
                        'query' => Type::string(),
                        'filter' => Type::string(),
                        'categoryId' => Type::id(),
                        'minPrice' => Type::float(),
                        'maxPrice' => Type::float(),
                        'first' => ['type' => Type::int(), 'defaultValue' => 12],
                        'after' => Type::string(),
                        'limit' => Type::int(),
                        'offset' => Type::int(),
                        'sortKey' => Type::string(),
                        'reverse' => Type::boolean(),
                    ],
                    'resolve' => fn ($root, array $args) => self::resolveProductsConnection($args),
                ],
                // Shop REST → attributes / attribute / categoryAttributeFilters
                'attributes' => [
                    'type' => Type::nonNull($shopAttributeConnection),
                    'args' => [
                        'first' => Type::int(),
                        'after' => Type::string(),
                        'limit' => Type::int(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlShopAttributes::attributes($args),
                ],
                'attribute' => [
                    'type' => Type::nonNull($shopAttribute),
                    'args' => [
                        'code' => Type::string(),
                        'id' => Type::id(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlShopAttributes::attribute($args),
                ],
                'categoryAttributeFilters' => [
                    'type' => Type::nonNull($shopAttributeConnection),
                    'args' => [
                        'categoryId' => Type::id(),
                        'categorySlug' => Type::string(),
                        'first' => Type::int(),
                        'after' => Type::string(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlShopAttributes::categoryAttributeFilters($args),
                ],
                'categories' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($category))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 24],
                    ],
                    'resolve' => function ($root, array $args) {
                        $locale = app()->getLocale();
                        $limit = max(1, min(100, (int) ($args['limit'] ?? 24)));

                        return Category::query()
                            ->where('is_enabled', true)
                            ->with('translations')
                            ->orderBy('position')
                            ->orderBy('id')
                            ->limit($limit)
                            ->get()
                            ->map(fn (Category $c) => self::mapCategory($c, $locale, false))
                            ->all();
                    },
                ],
                // Shop REST → categories cursor connection
                'categoriesConnection' => [
                    'type' => Type::nonNull($categoryConnection),
                    'args' => [
                        'first' => Type::int(),
                        'after' => Type::string(),
                        'limit' => Type::int(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCatalogConnections::categories($args),
                ],
                'treeCategories' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($category))),
                    'args' => [
                        'parentId' => Type::id(),
                        'depth' => ['type' => Type::int(), 'defaultValue' => 2],
                    ],
                    'resolve' => function ($root, array $args) {
                        $locale = app()->getLocale();
                        $depth = max(0, min(4, (int) ($args['depth'] ?? 2)));
                        $parentId = isset($args['parentId']) ? (int) $args['parentId'] : null;
                        $q = Category::query()->where('is_enabled', true)->with('translations')->orderBy('position')->orderBy('id');
                        if ($parentId) {
                            $q->where('parent_id', $parentId);
                        } else {
                            $q->whereNull('parent_id');
                        }

                        return $q->get()
                            ->map(fn (Category $c) => self::mapCategory($c, $locale, true, $depth))
                            ->all();
                    },
                ],
                'cart' => [
                    'type' => Type::nonNull($cart),
                    'resolve' => fn () => self::cartPayload(),
                ],
                // Legacy: product list for older clients
                'wishlist' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($product))),
                    'args' => [
                        'id' => Type::id(),
                    ],
                    'resolve' => function ($root, array $args) {
                        if (isset($args['id']) && trim((string) $args['id']) !== '') {
                            $one = GraphqlWishlist::findOne($args['id']);

                            return $one && isset($one['product']) ? [$one['product']] : [];
                        }

                        return self::resolveWishlist();
                    },
                ],
                // Shop REST → wishlists (cursor connection of WishlistItem)
                'wishlists' => [
                    'type' => Type::nonNull($wishlistItemConnection),
                    'args' => [
                        'first' => Type::int(),
                        'after' => Type::string(),
                        'limit' => Type::int(),
                        'sort' => Type::string(),
                        'order' => Type::string(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlWishlist::connection($args),
                ],
                // Shop REST → GDPR Data Requests
                'gdprStatus' => [
                    'type' => Type::nonNull($gdprStatus),
                    'resolve' => fn () => ['enabled' => GdprSettings::enabled()],
                ],
                'gdprRequests' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($gdprRequest))),
                    'resolve' => function () {
                        return self::listGdprRequests();
                    },
                ],
                'gdprRequest' => [
                    'type' => $gdprRequest,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::findGdprRequest((int) $args['id']);
                    },
                ],
                // Shop REST → Customer downloadable products
                'customerDownloadableProducts' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($downloadableProduct))),
                    'args' => [
                        'status' => Type::string(),
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listCustomerDownloadableProducts($args);
                    },
                ],
                // Shop REST docs → downloadableProducts / downloadableLinks
                'downloadableProducts' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($downloadableProduct))),
                    'args' => [
                        'status' => Type::string(),
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listCustomerDownloadableProducts($args);
                    },
                ],
                'downloadableLinks' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($downloadableProduct))),
                    'args' => [
                        'status' => Type::string(),
                        'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listCustomerDownloadableProducts($args);
                    },
                ],
                'customerDownloadableProduct' => [
                    'type' => $downloadableProduct,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::findCustomerDownloadableProduct((int) $args['id']);
                    },
                ],
                // Shop REST docs → downloadableProduct (singular)
                'downloadableProduct' => [
                    'type' => $downloadableProduct,
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::findCustomerDownloadableProduct((int) $args['id']);
                    },
                ],
                // Shop REST → productReviews (cursor connection; product_id + status + rating)
                'productReviews' => [
                    'type' => Type::nonNull($productReviewConnection),
                    'args' => [
                        'productId' => Type::id(),
                        'product_id' => Type::int(),
                        'status' => Type::string(),
                        'rating' => Type::int(),
                        'first' => Type::int(),
                        'after' => Type::string(),
                        'limit' => Type::int(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlProductReviews::connection($args),
                ],
                // Shop REST docs → reviews
                'reviews' => [
                    'type' => Type::nonNull($productReviewConnection),
                    'args' => [
                        'productId' => Type::id(),
                        'product_id' => Type::int(),
                        'status' => Type::string(),
                        'rating' => Type::int(),
                        'first' => Type::int(),
                        'after' => Type::string(),
                        'limit' => Type::int(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlProductReviews::connection($args),
                ],
                'compareProducts' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($product))),
                    'args' => [
                        'ids' => Type::nonNull(Type::listOf(Type::nonNull(Type::id()))),
                    ],
                    'resolve' => function ($root, array $args) {
                        if (! ProductSettings::compareEnabled()) {
                            return [];
                        }
                        $max = ProductSettings::compareMax();
                        $ids = collect($args['ids'] ?? [])
                            ->map(fn ($id) => (int) $id)
                            ->filter(fn (int $id) => $id > 0)
                            ->unique()
                            ->take($max)
                            ->values();
                        if ($ids->isEmpty()) {
                            return [];
                        }
                        $locale = app()->getLocale();
                        $order = $ids->flip();

                        return Product::query()->enabled()->whereIn('id', $ids->all())
                            ->with(['translations', 'images'])
                            ->get()
                            ->sortBy(fn (Product $p) => $order[$p->id] ?? PHP_INT_MAX)
                            ->values()
                            ->map(fn (Product $p) => self::mapProduct($p, $locale))
                            ->all();
                    },
                ],
                'customerAddresses' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($address))),
                    'resolve' => function () {
                        $customer = auth('customer')->user();
                        if (! $customer) {
                            throw new UserError('Authentication required.');
                        }

                        return $customer->addresses()
                            ->orderByDesc('is_default_shipping')
                            ->orderBy('id')
                            ->get()
                            ->map(fn (CustomerAddress $a) => GraphqlCustomerAddresses::map($a))
                            ->all();
                    },
                ],
                // Shop REST → getCustomerAddresses (cursor connection)
                'getCustomerAddresses' => [
                    'type' => Type::nonNull($customerAddressConnection),
                    'args' => [
                        'first' => Type::int(),
                        'after' => Type::string(),
                        'limit' => Type::int(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCustomerAddresses::connection($args),
                ],
                'myOrders' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($customerOrder))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 10],
                    ],
                    'resolve' => function ($root, array $args) {
                        $conn = GraphqlCustomerAccount::customerOrders([
                            'first' => (int) ($args['limit'] ?? 10),
                        ]);

                        return collect($conn['edges'] ?? [])->map(fn ($e) => $e['node'])->values()->all();
                    },
                ],
                // Shop REST → customerOrders (cursor connection)
                'customerOrders' => [
                    'type' => Type::nonNull($customerOrderConnection),
                    'args' => [
                        'first' => Type::int(),
                        'after' => Type::string(),
                        'limit' => Type::int(),
                        'status' => Type::string(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCustomerAccount::customerOrders($args),
                ],
                'customerOrder' => [
                    'type' => Type::nonNull($customerOrder),
                    'args' => [
                        'id' => Type::id(),
                        'incrementId' => Type::string(),
                        'number' => Type::string(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCustomerAccount::customerOrder($args),
                ],
                // Shop REST docs → order(id)
                'order' => [
                    'type' => Type::nonNull($customerOrder),
                    'args' => [
                        'id' => Type::id(),
                        'incrementId' => Type::string(),
                        'number' => Type::string(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCustomerAccount::customerOrder($args),
                ],
                // Shop REST → customerReviews / customerReview
                'customerReviews' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($customerOwnedReview))),
                    'args' => [
                        'first' => Type::int(),
                        'limit' => Type::int(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCustomerAccountExtras::reviews($args),
                ],
                'customerReview' => [
                    'type' => Type::nonNull($customerOwnedReview),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCustomerAccountExtras::review($args),
                ],
                // Shop REST → customerInvoices / customerInvoice
                'customerInvoices' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($customerInvoice))),
                    'args' => [
                        'first' => Type::int(),
                        'limit' => Type::int(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCustomerAccountExtras::invoices($args),
                ],
                'customerInvoice' => [
                    'type' => Type::nonNull($customerInvoice),
                    'args' => [
                        'id' => Type::id(),
                        'orderId' => Type::id(),
                        'incrementId' => Type::string(),
                        'number' => Type::string(),
                        'invoiceNumber' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        if (! empty($args['invoiceNumber']) && empty($args['number']) && empty($args['incrementId'])) {
                            $args['number'] = (string) $args['invoiceNumber'];
                        }

                        return GraphqlCustomerAccountExtras::invoice($args);
                    },
                ],
                // Shop REST → customerOrderShipments / customerOrderShipment
                'customerOrderShipments' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($customerShipment))),
                    'args' => [
                        'first' => Type::int(),
                        'limit' => Type::int(),
                        'orderId' => Type::id(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCustomerAccountExtras::shipments($args),
                ],
                'customerOrderShipment' => [
                    'type' => Type::nonNull($customerShipment),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCustomerAccountExtras::shipment($args),
                ],
                'myBookings' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($customerBooking))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                        'date_from' => Type::string(),
                        'date_to' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listCustomerBookings($args);
                    },
                ],
                // Shop REST docs → bookingProducts (customer booking history)
                'bookingProducts' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($customerBooking))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                        'date_from' => Type::string(),
                        'date_to' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::listCustomerBookings($args);
                    },
                ],
                'bookingSlots' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($bookingSlot))),
                    'args' => [
                        'id' => Type::nonNull(Type::int()),
                        'date' => Type::nonNull(Type::string()),
                        'productId' => Type::id(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $productId = (int) ($args['productId'] ?? $args['id']);
                        $date = trim((string) $args['date']);
                        $product = Product::query()->enabled()->find($productId);
                        if (! $product) {
                            return [];
                        }
                        $product->loadMissing('serviceSetting');

                        return app(ServiceProductService::class)->slotsForDate($product, $date);
                    },
                ],
                // Shop REST → countries / locales as Relay connections
                'countries' => [
                    'type' => Type::nonNull($countryConnection),
                    'args' => [
                        'first' => Type::int(),
                        'after' => Type::string(),
                        'limit' => Type::int(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlShopGeo::countriesConnection($args),
                ],
                // Wentocart list alias (pre-shape)
                'countriesList' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($country))),
                    'resolve' => fn () => GraphqlShopGeo::countriesList(),
                ],
                'locales' => [
                    'type' => Type::nonNull($localeConnection),
                    'args' => [
                        'first' => Type::int(),
                        'after' => Type::string(),
                        'limit' => Type::int(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlShopGeo::localesConnection($args),
                ],
                'localesList' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($locale))),
                    'resolve' => fn () => GraphqlShopGeo::localesList(),
                ],
                // Shop REST → currencies connection (+ list alias)
                'currencies' => [
                    'type' => Type::nonNull($currencyConnection),
                    'args' => [
                        'first' => Type::int(),
                        'after' => Type::string(),
                        'limit' => Type::int(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlShopGeo::currenciesConnection($args),
                ],
                'currenciesList' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($currencyType))),
                    'resolve' => fn () => GraphqlShopGeo::currenciesList(),
                ],
                // Shop REST → channels connection (+ list alias)
                'stores' => [
                    'type' => Type::nonNull($storeConnection),
                    'args' => [
                        'first' => Type::int(),
                        'after' => Type::string(),
                        'limit' => Type::int(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlShopGeo::storesConnection($args),
                ],
                'storesList' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($storeRef))),
                    'resolve' => fn () => GraphqlShopGeo::storesList(),
                ],
                // Shop REST → channel(code:) / channel(id:)
                'store' => [
                    'type' => Type::nonNull($storeRef),
                    'args' => [
                        'code' => Type::string(),
                        'id' => Type::id(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlHomeModules::store($args),
                ],
                // Shop REST → defaultStore / homeCategories / storeSettings
                'defaultStore' => [
                    'type' => Type::nonNull($storeRef),
                    'resolve' => fn () => GraphqlShopStoreSettings::defaultStore(),
                ],
                'homeCategories' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($category))),
                    'args' => [
                        'limit' => Type::int(),
                        'first' => Type::int(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlShopStoreSettings::homeCategories($args),
                ],
                'storeSettings' => [
                    'type' => Type::nonNull($storeSettingConnection),
                    'args' => [
                        'first' => Type::int(),
                        'after' => Type::string(),
                        'limit' => Type::int(),
                        'code' => Type::string(),
                        'slug' => Type::string(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlShopStoreSettings::storeSettings($args),
                ],
                // Shop REST → theme
                'theme' => [
                    'type' => Type::nonNull($themeInfo),
                    'resolve' => fn () => GraphqlHomeModules::theme(),
                ],
                // Shop REST → homeModules / homeModule
                'homeModules' => [
                    'type' => Type::nonNull($homeModuleConnection),
                    'args' => [
                        'first' => Type::int(),
                        'after' => Type::string(),
                        'limit' => Type::int(),
                        'type' => Type::string(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlHomeModules::connection($args),
                ],
                'homeModule' => [
                    'type' => Type::nonNull($homeModule),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlHomeModules::one($args),
                ],
                // Shop REST → sections / section (published Appearance blocks)
                'sections' => [
                    'type' => Type::nonNull($shopSectionConnection),
                    'args' => [
                        'type' => Type::string(),
                        'first' => Type::int(),
                        'after' => Type::string(),
                        'last' => Type::int(),
                        'before' => Type::string(),
                        'limit' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $conn = GraphqlHomeModules::sectionsConnection($args);
                        $conn['edges'] = array_map(function (array $edge) {
                            $node = $edge['node'];
                            $node['storeId'] = (int) ($node['storeId'] ?? 0);

                            return ['cursor' => $edge['cursor'], 'node' => $node];
                        }, $conn['edges']);

                        return $conn;
                    },
                ],
                'section' => [
                    'type' => Type::nonNull($shopSection),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        $node = GraphqlHomeModules::sectionOne($args);
                        $node['storeId'] = (int) ($node['storeId'] ?? 0);

                        return $node;
                    },
                ],
                // Shop REST → paymentMethods connection
                'paymentMethods' => [
                    'type' => Type::nonNull($paymentMethodConnection),
                    'args' => [
                        'cartId' => Type::id(),
                        'first' => Type::int(),
                        'after' => Type::string(),
                        'limit' => Type::int(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlShopGeo::paymentMethodsConnection($args),
                ],
                'paymentMethodsList' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($paymentMethodType))),
                    'resolve' => fn () => GraphqlShopGeo::paymentMethodsList(),
                ],
                // Shop REST → Checkout reads
                'collectionGetCheckoutAddresses' => [
                    'type' => Type::nonNull($checkoutAddressResult),
                    'resolve' => fn () => GraphqlCheckoutSteps::getAddresses(),
                ],
                'collectionShippingRates' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($shippingQuote))),
                    'resolve' => fn () => GraphqlCheckoutSteps::shippingRates(),
                ],
                'collectionPaymentMethods' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($checkoutPaymentMethod))),
                    'resolve' => fn () => GraphqlCheckoutSteps::paymentMethods(),
                ],
                'cmsPages' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($cmsPage))),
                    'args' => [
                        'limit' => ['type' => Type::int(), 'defaultValue' => 20],
                    ],
                    'resolve' => function ($root, array $args) {
                        $locale = app()->getLocale();
                        $limit = max(1, min(100, (int) ($args['limit'] ?? 20)));

                        return CmsPage::query()
                            ->public()
                            ->with('translations')
                            ->orderByDesc('id')
                            ->limit($limit)
                            ->get()
                            ->map(fn (CmsPage $page) => self::mapCmsPage($page, $locale))
                            ->all();
                    },
                ],
                'cmsPage' => [
                    'type' => $cmsPage,
                    'args' => [
                        'slug' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        $locale = app()->getLocale();
                        $slug = trim((string) $args['slug']);
                        $page = CmsPage::query()
                            ->public()
                            ->whereHas('translations', fn ($q) => $q->where('slug', $slug)->where('language_code', $locale))
                            ->with('translations')
                            ->first();
                        if (! $page) {
                            $page = CmsPage::query()
                                ->public()
                                ->whereHas('translations', fn ($q) => $q->where('slug', $slug))
                                ->with('translations')
                                ->first();
                        }

                        return $page ? self::mapCmsPage($page, $locale) : null;
                    },
                ],
                'configuration' => [
                    'type' => Type::nonNull($configuration),
                    'resolve' => function () {
                        $locale = app()->getLocale();
                        $identity = ShopIdentity::forStorefront(app(StoreContext::class)->store(), $locale);

                        return [
                            'store_name' => $identity['name'] ?? null,
                            'store_email' => $identity['email'] ?? null,
                            'currency' => (string) (config('wento.default_currency') ?: 'USD'),
                            'locale' => $locale,
                            'guest_checkout' => (bool) CustomerSettings::guestCheckout(),
                            'registration_enabled' => (bool) CustomerSettings::registrationEnabled(),
                        ];
                    },
                ],
                // Shop REST storeConfig — public non-secret settings
                'storeConfig' => [
                    'type' => Type::nonNull($storeConfig),
                    'resolve' => function () {
                        return GraphqlShopStoreConfig::payload();
                    },
                ],
            ],
        ]);

        $mutation = new ObjectType([
            'name' => 'Mutation',
            'fields' => [
                'createCartToken' => [
                    'type' => Type::nonNull($cartTokenResult),
                    'args' => [
                        'input' => $cartTokenInput,
                    ],
                    'resolve' => function () {
                        $carts = app(CartService::class);
                        $token = $carts->issueGuestCartToken();

                        return GraphqlInputs::wrapCartToken([
                            'id' => $token,
                            'cart_token' => $token,
                            'cartToken' => $token,
                            'token' => $token,
                            'cart' => self::cartPayload($carts),
                        ]);
                    },
                ],
                'cartAdd' => [
                    'type' => Type::nonNull($cart),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                        'qty' => ['type' => Type::float(), 'defaultValue' => 1],
                        'quantity' => Type::float(),
                        'combinationId' => Type::id(),
                        'bookingDate' => Type::string(),
                        'bookingEndDate' => Type::string(),
                        'rentalDuration' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::cartAddMutation($args);
                    },
                ],
                // Shop REST → Cart
                'createAddProductInCart' => [
                    'type' => Type::nonNull($cart),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                        'qty' => Type::float(),
                        'quantity' => Type::float(),
                        'combinationId' => Type::id(),
                        'bookingDate' => Type::string(),
                        'bookingEndDate' => Type::string(),
                        'rentalDuration' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::cartAddMutation($args);
                    },
                ],
                // Shop REST docs → createCart / addProductsToCart / updateCart / removeCartItem
                'createCart' => [
                    'type' => Type::nonNull($compatCartMutationResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'CreateCartInput',
                            'fields' => [
                                'clientMutationId' => Type::string(),
                            ],
                        ]),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCartMutations::createCart($args),
                ],
                'addProductsToCart' => [
                    'type' => Type::nonNull($compatCartMutationResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'AddProductsToCartInput',
                            'fields' => [
                                'cartId' => Type::id(),
                                'productId' => Type::id(),
                                'quantity' => Type::float(),
                                'qty' => Type::float(),
                                'combinationId' => Type::id(),
                                'items' => Type::listOf(new InputObjectType([
                                    'name' => 'AddProductsToCartItemInput',
                                    'fields' => [
                                        'productId' => Type::nonNull(Type::id()),
                                        'quantity' => Type::float(),
                                        'qty' => Type::float(),
                                        'combinationId' => Type::id(),
                                    ],
                                ])),
                            ],
                        ]),
                        'productId' => Type::id(),
                        'quantity' => Type::float(),
                        'qty' => Type::float(),
                        'combinationId' => Type::id(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCartMutations::addProductsToCart($args),
                ],
                'updateCart' => [
                    'type' => Type::nonNull($compatCartMutationResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'UpdateCartInput',
                            'fields' => [
                                'cartId' => Type::id(),
                                'cartItemId' => Type::id(),
                                'itemId' => Type::id(),
                                'quantity' => Type::float(),
                                'qty' => Type::float(),
                                'items' => Type::listOf(new InputObjectType([
                                    'name' => 'UpdateCartItemInput',
                                    'fields' => [
                                        'cartItemId' => Type::id(),
                                        'itemId' => Type::id(),
                                        'quantity' => Type::float(),
                                        'qty' => Type::float(),
                                    ],
                                ])),
                            ],
                        ]),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCartMutations::updateCart($args),
                ],
                'removeCartItem' => [
                    'type' => Type::nonNull($compatCartMutationResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'RemoveCartItemInput',
                            'fields' => [
                                'cartId' => Type::id(),
                                'cartItemId' => Type::id(),
                                'itemId' => Type::id(),
                            ],
                        ]),
                        'cartItemId' => Type::id(),
                        'itemId' => Type::id(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCartMutations::removeCartItem($args),
                ],
                'moveToWishlist' => [
                    'type' => Type::nonNull($statusResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'MoveToWishlistInput',
                            'fields' => [
                                'cartId' => Type::id(),
                                'cartItemId' => Type::id(),
                                'itemId' => Type::id(),
                                'productId' => Type::id(),
                            ],
                        ]),
                        'cartItemId' => Type::id(),
                        'productId' => Type::id(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCartMutations::moveToWishlist($args),
                ],
                'cartUpdateQty' => [
                    'type' => Type::nonNull($cart),
                    'args' => [
                        'itemId' => Type::id(),
                        'cartItemId' => Type::id(),
                        'qty' => Type::float(),
                        'quantity' => Type::float(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::cartUpdateQtyMutation($args);
                    },
                ],
                'createUpdateCartItem' => [
                    'type' => Type::nonNull($cart),
                    'args' => [
                        'itemId' => Type::id(),
                        'cartItemId' => Type::id(),
                        'qty' => Type::float(),
                        'quantity' => Type::float(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::cartUpdateQtyMutation($args);
                    },
                ],
                'cartRemoveItem' => [
                    'type' => Type::nonNull($cart),
                    'args' => [
                        'itemId' => Type::id(),
                        'cartItemId' => Type::id(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::cartRemoveItemMutation($args);
                    },
                ],
                'createRemoveCartItem' => [
                    'type' => Type::nonNull($cart),
                    'args' => [
                        'itemId' => Type::id(),
                        'cartItemId' => Type::id(),
                        'input' => new InputObjectType([
                            'name' => 'CreateRemoveCartItemInput',
                            'fields' => [
                                'cartItemId' => Type::id(),
                                'itemId' => Type::id(),
                                'clientMutationId' => Type::string(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        unset($args['input']);

                        return self::cartRemoveItemMutation(array_merge($args, $input));
                    },
                ],
                // Shop REST docs → createRemoveCartItems (bulk)
                'createRemoveCartItems' => [
                    'type' => Type::nonNull($cart),
                    'args' => [
                        'itemIds' => Type::listOf(Type::nonNull(Type::id())),
                        'cartItemIds' => Type::listOf(Type::nonNull(Type::id())),
                        'input' => new InputObjectType([
                            'name' => 'CreateRemoveCartItemsInput',
                            'fields' => [
                                'itemIds' => Type::listOf(Type::nonNull(Type::id())),
                                'cartItemIds' => Type::listOf(Type::nonNull(Type::id())),
                                'clientMutationId' => Type::string(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        unset($args['input']);

                        return self::cartRemoveItemsMutation(array_merge($args, $input));
                    },
                ],
                'createReadCart' => [
                    'type' => Type::nonNull($cart),
                    'resolve' => function () {
                        return self::cartPayload(app(CartService::class));
                    },
                ],
                'applyCoupon' => [
                    'type' => Type::nonNull($cart),
                    'args' => [
                        'code' => Type::string(),
                        'couponCode' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::applyCouponMutation($args);
                    },
                ],
                'createApplyCoupon' => [
                    'type' => Type::nonNull($cart),
                    'args' => [
                        'code' => Type::string(),
                        'couponCode' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::applyCouponMutation($args);
                    },
                ],
                'removeCoupon' => [
                    'type' => Type::nonNull($cart),
                    'resolve' => function () {
                        return self::removeCouponMutation();
                    },
                ],
                'createRemoveCoupon' => [
                    'type' => Type::nonNull($cart),
                    'resolve' => function () {
                        return self::removeCouponMutation();
                    },
                ],
                'createMergeCart' => [
                    'type' => Type::nonNull($cart),
                    'args' => [
                        'cartToken' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::mergeCartMutation($args);
                    },
                ],
                // Shop REST docs short aliases → cart
                'mergeCart' => [
                    'type' => Type::nonNull($cart),
                    'args' => [
                        'cartToken' => Type::string(),
                        'input' => new InputObjectType([
                            'name' => 'MergeCartInput',
                            'fields' => [
                                'cartToken' => Type::string(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];

                        return self::mergeCartMutation(array_merge($args, $input));
                    },
                ],
                'addItemToCart' => [
                    'type' => Type::nonNull($cart),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                        'qty' => Type::float(),
                        'quantity' => Type::float(),
                        'combinationId' => Type::id(),
                        'bookingDate' => Type::string(),
                        'bookingEndDate' => Type::string(),
                        'rentalDuration' => Type::int(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::cartAddMutation($args);
                    },
                ],
                'updateCartItem' => [
                    'type' => Type::nonNull($cart),
                    'args' => [
                        'itemId' => Type::id(),
                        'cartItemId' => Type::id(),
                        'qty' => Type::float(),
                        'quantity' => Type::float(),
                        'input' => new InputObjectType([
                            'name' => 'UpdateCartItemAliasInput',
                            'fields' => [
                                'cartId' => Type::id(),
                                'cartItemId' => Type::id(),
                                'itemId' => Type::id(),
                                'quantity' => Type::float(),
                                'qty' => Type::float(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        $merged = array_merge($args, $input);
                        $itemId = (int) ($merged['itemId'] ?? $merged['cartItemId'] ?? 0);
                        $qty = (float) ($merged['qty'] ?? $merged['quantity'] ?? 1);

                        return self::cartUpdateQtyMutation(['itemId' => $itemId, 'qty' => $qty]);
                    },
                ],
                // Shop REST → estimateShipping { shippingMethods { edges } }
                'estimateShipping' => [
                    'type' => Type::nonNull($estimateShippingResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'EstimateShippingInput',
                            'fields' => [
                                'cartId' => Type::id(),
                                'country' => Type::string(),
                                'state' => Type::string(),
                                'zipCode' => Type::string(),
                            ],
                        ]),
                        'country' => Type::string(),
                        'state' => Type::string(),
                        'postalCode' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        $merged = array_merge($args, $input);
                        $methods = self::estimateShippingMethods($merged);
                        $edges = [];
                        foreach ($methods as $i => $node) {
                            $edges[] = [
                                'cursor' => base64_encode('ship:'.($i + 1)),
                                'node' => $node,
                            ];
                        }

                        return [
                            'shippingMethods' => [
                                'edges' => $edges,
                                'pageInfo' => [
                                    'hasNextPage' => false,
                                    'hasPreviousPage' => false,
                                    'startCursor' => $edges[0]['cursor'] ?? null,
                                    'endCursor' => $edges !== [] ? $edges[array_key_last($edges)]['cursor'] : null,
                                ],
                                'totalCount' => count($edges),
                            ],
                        ];
                    },
                ],
                'estimateShippingList' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($shippingQuote))),
                    'args' => [
                        'country' => Type::nonNull(Type::string()),
                        'state' => Type::string(),
                        'postalCode' => Type::string(),
                    ],
                    'resolve' => fn ($root, array $args) => self::estimateShippingMethods($args),
                ],
                'placeOrder' => [
                    'type' => Type::nonNull($checkoutResult),
                    'args' => [
                        'email' => Type::nonNull(Type::string()),
                        'paymentMethodId' => Type::nonNull(Type::id()),
                        'shippingMethodId' => Type::id(),
                        'billingAddress' => $addressInput,
                        'shippingAddress' => $addressInput,
                        'customerNote' => Type::string(),
                        'acceptTerms' => Type::boolean(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlPlaceOrder::run($args),
                ],
                // Shop REST → Checkout sequence
                'createCheckoutAddress' => [
                    'type' => Type::nonNull($checkoutAddressResult),
                    'args' => [
                        'billingFirstName' => Type::nonNull(Type::string()),
                        'billingLastName' => Type::string(),
                        'billingEmail' => Type::nonNull(Type::string()),
                        'billingAddress' => Type::nonNull(Type::string()),
                        'billingCity' => Type::nonNull(Type::string()),
                        'billingCountry' => Type::nonNull(Type::string()),
                        'billingState' => Type::string(),
                        'billingPostcode' => Type::string(),
                        'billingPhoneNumber' => Type::string(),
                        'useForShipping' => Type::boolean(),
                        'shippingFirstName' => Type::string(),
                        'shippingLastName' => Type::string(),
                        'shippingAddress' => Type::string(),
                        'shippingCity' => Type::string(),
                        'shippingCountry' => Type::string(),
                        'shippingState' => Type::string(),
                        'shippingPostcode' => Type::string(),
                        'shippingPhoneNumber' => Type::string(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCheckoutSteps::saveAddress($args),
                ],
                'saveCheckoutAddresses' => [
                    'type' => Type::nonNull($checkoutAddressResult),
                    'args' => [
                        'billingFirstName' => Type::nonNull(Type::string()),
                        'billingLastName' => Type::string(),
                        'billingEmail' => Type::nonNull(Type::string()),
                        'billingAddress' => Type::nonNull(Type::string()),
                        'billingCity' => Type::nonNull(Type::string()),
                        'billingCountry' => Type::nonNull(Type::string()),
                        'billingState' => Type::string(),
                        'billingPostcode' => Type::string(),
                        'billingPhoneNumber' => Type::string(),
                        'useForShipping' => Type::boolean(),
                        'shippingFirstName' => Type::string(),
                        'shippingLastName' => Type::string(),
                        'shippingAddress' => Type::string(),
                        'shippingCity' => Type::string(),
                        'shippingCountry' => Type::string(),
                        'shippingState' => Type::string(),
                        'shippingPostcode' => Type::string(),
                        'shippingPhoneNumber' => Type::string(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCheckoutSteps::saveAddress($args),
                ],
                'createCheckoutShippingMethod' => [
                    'type' => Type::nonNull($checkoutMethodSaveResult),
                    'args' => [
                        'shippingMethodId' => Type::id(),
                        'methodId' => Type::id(),
                        'method' => Type::string(),
                        'code' => Type::string(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCheckoutSteps::saveShippingMethod($args),
                ],
                'createCheckoutPaymentMethod' => [
                    'type' => Type::nonNull($checkoutMethodSaveResult),
                    'args' => [
                        'paymentMethodId' => Type::id(),
                        'methodId' => Type::id(),
                        'method' => Type::string(),
                        'code' => Type::string(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCheckoutSteps::savePaymentMethod($args),
                ],
                'createCheckoutOrder' => [
                    'type' => Type::nonNull($checkoutResult),
                    'resolve' => fn () => GraphqlCheckoutSteps::placeOrder(),
                ],
                // Shop REST docs → createOrder / createGuestOrder / saveShippingMethod / savePaymentMethod
                'createOrder' => [
                    'type' => Type::nonNull($checkoutResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'PlaceOrderInput',
                            'fields' => [
                                'cartId' => Type::id(),
                                'billingAddressId' => Type::id(),
                                'shippingAddressId' => Type::id(),
                                'shippingMethod' => Type::string(),
                                'paymentMethod' => Type::string(),
                                'email' => Type::string(),
                                'paymentMethodId' => Type::id(),
                                'shippingMethodId' => Type::id(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createOrderFromInput($args);
                    },
                ],
                // Shop REST docs → createSaveOrder
                'createSaveOrder' => [
                    'type' => Type::nonNull($checkoutResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'CreateSaveOrderInput',
                            'fields' => [
                                'cartId' => Type::id(),
                                'billingAddressId' => Type::id(),
                                'shippingAddressId' => Type::id(),
                                'shippingMethod' => Type::string(),
                                'paymentMethod' => Type::string(),
                                'email' => Type::string(),
                                'paymentMethodId' => Type::id(),
                                'shippingMethodId' => Type::id(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createOrderFromInput($args);
                    },
                ],
                'createGuestOrder' => [
                    'type' => Type::nonNull($checkoutResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'CreateGuestOrderInput',
                            'fields' => [
                                'cartId' => Type::id(),
                                'shippingMethod' => Type::string(),
                                'paymentMethod' => Type::string(),
                                'billingAddress' => $addressInput,
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        $billing = is_array($input['billingAddress'] ?? null) ? $input['billingAddress'] : [];
                        if ($billing !== []) {
                            $email = (string) ($billing['email'] ?? $billing['phone'] ?? '');
                            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                throw new UserError('billingAddress.email is required for createGuestOrder.');
                            }
                            GraphqlCheckoutSteps::saveAddress([
                                'billingFirstName' => (string) ($billing['firstName'] ?? ''),
                                'billingLastName' => (string) ($billing['lastName'] ?? ''),
                                'billingEmail' => $email,
                                'billingAddress' => (string) ($billing['line1'] ?? $billing['address'] ?? ''),
                                'billingCity' => (string) ($billing['city'] ?? ''),
                                'billingCountry' => (string) ($billing['country'] ?? ''),
                                'billingState' => (string) ($billing['state'] ?? ''),
                                'billingPostcode' => (string) ($billing['postalCode'] ?? ''),
                                'billingPhoneNumber' => (string) ($billing['phone'] ?? $billing['phoneNumber'] ?? ''),
                                'useForShipping' => true,
                            ]);
                        }
                        if (! empty($input['shippingMethod'])) {
                            GraphqlCheckoutSteps::saveShippingMethod([
                                'code' => $input['shippingMethod'],
                                'method' => $input['shippingMethod'],
                            ]);
                        }
                        if (! empty($input['paymentMethod'])) {
                            GraphqlCheckoutSteps::savePaymentMethod([
                                'code' => $input['paymentMethod'],
                                'method' => $input['paymentMethod'],
                            ]);
                        }

                        return GraphqlCheckoutSteps::placeOrder();
                    },
                ],
                'saveShippingMethod' => [
                    'type' => Type::nonNull($checkoutMethodSaveResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'SaveShippingMethodInput',
                            'fields' => [
                                'cartId' => Type::id(),
                                'shippingMethodCode' => Type::string(),
                                'shippingMethodId' => Type::id(),
                                'method' => Type::string(),
                                'code' => Type::string(),
                            ],
                        ]),
                        'shippingMethodId' => Type::id(),
                        'code' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        $merged = array_merge($args, $input);
                        if (! empty($merged['shippingMethodCode']) && empty($merged['code'])) {
                            $merged['code'] = $merged['shippingMethodCode'];
                        }

                        return GraphqlCheckoutSteps::saveShippingMethod($merged);
                    },
                ],
                'savePaymentMethod' => [
                    'type' => Type::nonNull($checkoutMethodSaveResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'SavePaymentMethodInput',
                            'fields' => [
                                'cartId' => Type::id(),
                                'method' => Type::string(),
                                'paymentMethodId' => Type::id(),
                                'code' => Type::string(),
                            ],
                        ]),
                        'paymentMethodId' => Type::id(),
                        'method' => Type::string(),
                        'code' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];

                        return GraphqlCheckoutSteps::savePaymentMethod(array_merge($args, $input));
                    },
                ],
                // Shop REST docs short aliases → checkout
                'saveShipping' => [
                    'type' => Type::nonNull($checkoutMethodSaveResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'SaveShippingAliasInput',
                            'fields' => [
                                'cartId' => Type::id(),
                                'shippingMethodCode' => Type::string(),
                                'shippingMethodId' => Type::id(),
                                'method' => Type::string(),
                                'code' => Type::string(),
                            ],
                        ]),
                        'shippingMethodId' => Type::id(),
                        'code' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        $merged = array_merge($args, $input);
                        if (! empty($merged['shippingMethodCode']) && empty($merged['code'])) {
                            $merged['code'] = $merged['shippingMethodCode'];
                        }

                        return GraphqlCheckoutSteps::saveShippingMethod($merged);
                    },
                ],
                'savePayment' => [
                    'type' => Type::nonNull($checkoutMethodSaveResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'SavePaymentAliasInput',
                            'fields' => [
                                'cartId' => Type::id(),
                                'method' => Type::string(),
                                'paymentMethodId' => Type::id(),
                                'code' => Type::string(),
                            ],
                        ]),
                        'paymentMethodId' => Type::id(),
                        'method' => Type::string(),
                        'code' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];

                        return GraphqlCheckoutSteps::savePaymentMethod(array_merge($args, $input));
                    },
                ],
                'saveAddress' => [
                    'type' => Type::nonNull($customerAddressMutationResult),
                    'args' => [
                        'input' => $addUpdateCustomerAddressInput,
                        'firstName' => Type::string(),
                        'lastName' => Type::string(),
                        'address' => Type::string(),
                        'city' => Type::string(),
                        'state' => Type::string(),
                        'country' => Type::string(),
                        'zipCode' => Type::string(),
                        'phoneNumber' => Type::string(),
                        'defaultBilling' => Type::boolean(),
                        'defaultShipping' => Type::boolean(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCustomerAddresses::addCustomerAddress($args),
                ],
                'wishlistToggle' => [
                    'type' => Type::nonNull($wishlistToggle),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::toggleWishlistProduct($args);
                    },
                ],
                // Shop REST → Wishlist mutations
                'toggleWishlist' => [
                    'type' => Type::nonNull($wishlistToggle),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::toggleWishlistProduct($args);
                    },
                ],
                'createWishlist' => [
                    'type' => Type::nonNull($createWishlistResult),
                    'args' => [
                        'productId' => Type::id(),
                        'clientMutationId' => Type::string(),
                        'input' => new InputObjectType([
                            'name' => 'CreateWishlistInput',
                            'fields' => [
                                'productId' => Type::id(),
                                'clientMutationId' => Type::string(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $payload = GraphqlWishlist::createMutation($args);
                        $payload['wishlisted'] = true;
                        $payload['product_id'] = isset($payload['wishlist']['product']['_id'])
                            ? (string) $payload['wishlist']['product']['_id']
                            : null;

                        return $payload;
                    },
                ],
                'deleteWishlist' => [
                    'type' => Type::nonNull($deleteWishlistResult),
                    'args' => [
                        'id' => Type::id(),
                        'wishlistItemId' => Type::int(),
                        'productId' => Type::id(),
                        'clientMutationId' => Type::string(),
                        'input' => new InputObjectType([
                            'name' => 'DeleteWishlistInput',
                            'fields' => [
                                'id' => Type::id(),
                                'wishlistItemId' => Type::int(),
                                'productId' => Type::id(),
                                'clientMutationId' => Type::string(),
                            ],
                        ]),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlWishlist::deleteMutation($args),
                ],
                // Shop REST docs → addToWishlist / removeFromWishlist
                'addToWishlist' => [
                    'type' => Type::nonNull($compatWishlistActionResult),
                    'args' => [
                        'productId' => Type::id(),
                        'input' => new InputObjectType([
                            'name' => 'AddToWishlistInput',
                            'fields' => [
                                'productId' => Type::id(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        GraphqlWishlist::createMutation(array_merge($args, $input));

                        return [
                            'status' => true,
                            'message' => 'Added to wishlist.',
                            'wishlist' => [
                                'id' => 'default',
                                'items' => GraphqlWishlist::connection(['first' => 50]),
                            ],
                        ];
                    },
                ],
                'removeFromWishlist' => [
                    'type' => Type::nonNull($compatWishlistActionResult),
                    'args' => [
                        'wishlistItemId' => Type::id(),
                        'id' => Type::id(),
                        'productId' => Type::id(),
                        'input' => new InputObjectType([
                            'name' => 'RemoveFromWishlistInput',
                            'fields' => [
                                'wishlistItemId' => Type::id(),
                                'id' => Type::id(),
                                'productId' => Type::id(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        GraphqlWishlist::deleteMutation(array_merge($args, $input));

                        return [
                            'status' => true,
                            'message' => 'Removed from wishlist.',
                            'wishlist' => [
                                'id' => 'default',
                                'items' => GraphqlWishlist::connection(['first' => 50]),
                            ],
                        ];
                    },
                ],
                'createDeleteAllWishlists' => [
                    'type' => Type::nonNull($newsletterResult),
                    'resolve' => function () {
                        $customer = self::requireWishlistCustomer();
                        $customer->wishlist()->detach();

                        return ['ok' => true, 'message' => 'Wishlist cleared.'];
                    },
                ],
                'moveWishlistToCart' => [
                    'type' => Type::nonNull($moveWishlistToCartResult),
                    'args' => [
                        'wishlistItemId' => Type::int(),
                        'productId' => Type::id(),
                        'quantity' => Type::int(),
                        'qty' => Type::float(),
                        'clientMutationId' => Type::string(),
                        'input' => new InputObjectType([
                            'name' => 'MoveWishlistToCartInput',
                            'fields' => [
                                'wishlistItemId' => Type::int(),
                                'productId' => Type::id(),
                                'quantity' => Type::int(),
                                'clientMutationId' => Type::string(),
                            ],
                        ]),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlWishlist::moveToCartMutation($args),
                ],
                'compareToggle' => [
                    'type' => Type::nonNull($compareToggle),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        if (! ProductSettings::compareEnabled()) {
                            throw new UserError('Compare is disabled.');
                        }
                        $productId = (int) $args['productId'];
                        if (! Product::query()->enabled()->whereKey($productId)->exists()) {
                            throw new UserError('Product not found.');
                        }
                        $ids = self::compareIdsFromSession();
                        $on = false;
                        if (in_array($productId, $ids, true)) {
                            $ids = array_values(array_filter($ids, fn (int $id) => $id !== $productId));
                        } else {
                            $max = ProductSettings::compareMax();
                            if (count($ids) >= $max) {
                                throw new UserError('Compare list is full (max '.$max.').');
                            }
                            $ids[] = $productId;
                            $on = true;
                        }
                        self::storeCompareIds($ids);

                        return [
                            'compared' => $on,
                            'product_id' => (string) $productId,
                            'ids' => array_map('strval', $ids),
                        ];
                    },
                ],
                'compareClear' => [
                    'type' => Type::nonNull($newsletterResult),
                    'resolve' => function () {
                        self::storeCompareIds([]);

                        return ['ok' => true, 'message' => 'Compare list cleared.'];
                    },
                ],
                // Shop REST → Compare mutations
                'createCompareItem' => [
                    'type' => Type::nonNull($compareToggle),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        if (! ProductSettings::compareEnabled()) {
                            throw new UserError('Compare is disabled.');
                        }
                        $productId = (int) $args['productId'];
                        if (! Product::query()->enabled()->whereKey($productId)->exists()) {
                            throw new UserError('Product not found.');
                        }
                        $ids = self::compareIdsFromSession();
                        if (! in_array($productId, $ids, true)) {
                            $max = ProductSettings::compareMax();
                            if (count($ids) >= $max) {
                                throw new UserError('Compare list is full (max '.$max.').');
                            }
                            $ids[] = $productId;
                            self::storeCompareIds($ids);
                        }

                        return [
                            'compared' => true,
                            'product_id' => (string) $productId,
                            'ids' => array_map('strval', $ids),
                        ];
                    },
                ],
                'deleteCompareItem' => [
                    'type' => Type::nonNull($compareToggle),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        $productId = (int) $args['productId'];
                        $ids = array_values(array_filter(
                            self::compareIdsFromSession(),
                            fn (int $id) => $id !== $productId
                        ));
                        self::storeCompareIds($ids);

                        return [
                            'compared' => false,
                            'product_id' => (string) $productId,
                            'ids' => array_map('strval', $ids),
                        ];
                    },
                ],
                'createDeleteAllCompareItems' => [
                    'type' => Type::nonNull($newsletterResult),
                    'resolve' => function () {
                        self::storeCompareIds([]);

                        return ['ok' => true, 'message' => 'Compare list cleared.'];
                    },
                ],
                'wishlistMoveToCart' => [
                    'type' => Type::nonNull($cart),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                        'qty' => ['type' => Type::float(), 'defaultValue' => 1],
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::moveWishlistProductToCart($args);
                    },
                ],
                'moveCartItemToWishlist' => [
                    'type' => Type::nonNull($cart),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        $customer = auth('customer')->user();
                        if (! $customer) {
                            throw new UserError('Authentication required.');
                        }
                        if (! SearchCatalogSettings::wishlistEnabled()) {
                            throw new UserError('Wishlist is disabled.');
                        }
                        $productId = (int) $args['productId'];
                        $carts = app(CartService::class);
                        $cart = $carts->current($customer);
                        $item = $cart->items()->where('product_id', $productId)->first();
                        if (! $item) {
                            throw new UserError('Product is not in the cart.');
                        }
                        Product::query()->findOrFail($productId);
                        if (! $customer->wishlist()->where('product_id', $productId)->exists()) {
                            $customer->wishlist()->attach($productId);
                        }
                        $item->delete();
                        $cart->refresh();

                        return self::cartPayload($carts);
                    },
                ],
                'wishlistClear' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($product))),
                    'resolve' => function () {
                        $customer = auth('customer')->user();
                        if (! $customer) {
                            throw new UserError('Authentication required.');
                        }
                        $customer->wishlist()->detach();

                        return [];
                    },
                ],
                'revokeAccessToken' => [
                    'type' => Type::nonNull($tokenRevoke),
                    'resolve' => function () {
                        $customer = auth('customer')->user();
                        if (! $customer) {
                            throw new UserError('Authentication required.');
                        }
                        $token = $customer->currentAccessToken();
                        if ($token) {
                            $token->delete();
                        }

                        return ['ok' => true];
                    },
                ],
                'newsletterSubscribe' => [
                    'type' => Type::nonNull($newsletterResult),
                    'args' => [
                        'email' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::newsletterSubscribeMutation($args);
                    },
                ],
                // Shop REST → Newsletter
                'createNewsletter' => [
                    'type' => Type::nonNull($newsletterResult),
                    'args' => [
                        'email' => Type::string(),
                        'input' => new InputObjectType([
                            'name' => 'CreateNewsletterInput',
                            'fields' => [
                                'email' => Type::string(),
                                'clientMutationId' => Type::string(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        unset($args['input']);

                        return self::newsletterSubscribeMutation(array_merge($args, $input));
                    },
                ],
                'subscribe' => [
                    'type' => Type::nonNull($newsletterResult),
                    'args' => [
                        'email' => Type::string(),
                        'input' => new InputObjectType([
                            'name' => 'SubscribeNewsletterInput',
                            'fields' => [
                                'email' => Type::nonNull(Type::string()),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        unset($args['input']);

                        return self::newsletterSubscribeMutation(array_merge($args, $input));
                    },
                ],
                // Shop REST docs / auth guide → createUnsubscribeNewsletter
                'createUnsubscribeNewsletter' => [
                    'type' => Type::nonNull($newsletterResult),
                    'args' => [
                        'email' => Type::string(),
                        'input' => new InputObjectType([
                            'name' => 'CreateUnsubscribeNewsletterInput',
                            'fields' => [
                                'email' => Type::string(),
                                'clientMutationId' => Type::string(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        unset($args['input']);

                        return self::newsletterUnsubscribeMutation(array_merge($args, $input));
                    },
                ],
                'unsubscribeNewsletter' => [
                    'type' => Type::nonNull($newsletterResult),
                    'args' => [
                        'email' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::newsletterUnsubscribeMutation($args);
                    },
                ],
                // Shop REST → Contact Us (public)
                'createContactUs' => [
                    'type' => Type::nonNull($contactUsResult),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'email' => Type::nonNull(Type::string()),
                        'message' => Type::nonNull(Type::string()),
                        'contact' => Type::string(),
                        'phone' => Type::string(),
                        'orderNumber' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createContactUsMutation($args);
                    },
                ],
                'contactUs' => [
                    'type' => Type::nonNull($contactUsResult),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'email' => Type::nonNull(Type::string()),
                        'message' => Type::nonNull(Type::string()),
                        'contact' => Type::string(),
                        'phone' => Type::string(),
                        'orderNumber' => Type::string(),
                        'input' => new InputObjectType([
                            'name' => 'ContactUsAliasInput',
                            'fields' => [
                                'name' => Type::nonNull(Type::string()),
                                'email' => Type::nonNull(Type::string()),
                                'message' => Type::nonNull(Type::string()),
                                'contact' => Type::string(),
                                'phone' => Type::string(),
                                'orderNumber' => Type::string(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];

                        return self::createContactUsMutation(array_merge($args, $input));
                    },
                ],
                // Shop REST → Reorder Customer Order
                'createReorderOrder' => [
                    'type' => Type::nonNull($reorderOrderResult),
                    'args' => [
                        'orderId' => Type::id(),
                        'orderNumber' => Type::string(),
                        'input' => new InputObjectType([
                            'name' => 'CreateReorderOrderInput',
                            'fields' => [
                                'orderId' => Type::id(),
                                'orderNumber' => Type::string(),
                                'clientMutationId' => Type::string(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        unset($args['input']);

                        return self::createReorderOrderMutation(array_merge($args, $input));
                    },
                ],
                'reorderCustomerOrder' => [
                    'type' => Type::nonNull($reorderOrderResult),
                    'args' => [
                        'orderId' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createReorderOrderMutation($args);
                    },
                ],
                // Shop REST → createCancelOrder (flat args or input:; nested cancelOrder)
                'createCancelOrder' => [
                    'type' => Type::nonNull($cancelOrderResult),
                    'args' => [
                        'id' => Type::id(),
                        'orderId' => Type::id(),
                        'incrementId' => Type::string(),
                        'number' => Type::string(),
                        'comment' => Type::string(),
                        'reason' => Type::string(),
                        'input' => new InputObjectType([
                            'name' => 'CreateCancelOrderInput',
                            'fields' => [
                                'orderId' => Type::id(),
                                'id' => Type::id(),
                                'incrementId' => Type::string(),
                                'number' => Type::string(),
                                'comment' => Type::string(),
                                'reason' => Type::string(),
                                'clientMutationId' => Type::string(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        unset($args['input']);

                        return GraphqlCustomerAccountExtras::cancelOrder(array_merge($args, $input));
                    },
                ],
                // Shop REST → GDPR mutations
                'createGdprRequest' => [
                    'type' => Type::nonNull($gdprRequest),
                    'args' => [
                        'type' => Type::string(),
                        'message' => Type::string(),
                        'input' => new InputObjectType([
                            'name' => 'CreateGdprRequestInput',
                            'fields' => [
                                'type' => Type::string(),
                                'message' => Type::string(),
                                'clientMutationId' => Type::string(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        unset($args['input']);

                        return self::createGdprRequestMutation(array_merge($args, $input));
                    },
                ],
                'revokeGdprRequest' => [
                    'type' => Type::nonNull($gdprRequest),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'input' => new InputObjectType([
                            'name' => 'RevokeGdprRequestInput',
                            'fields' => [
                                'id' => Type::id(),
                                'clientMutationId' => Type::string(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        unset($args['input']);

                        return self::revokeGdprRequestMutation(array_merge($args, $input));
                    },
                ],
                'deleteGdprRequest' => [
                    'type' => Type::nonNull($newsletterResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                        'input' => new InputObjectType([
                            'name' => 'DeleteGdprRequestInput',
                            'fields' => [
                                'id' => Type::id(),
                                'clientMutationId' => Type::string(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        unset($args['input']);

                        return self::deleteGdprRequestMutation(array_merge($args, $input));
                    },
                ],
                'customerLogin' => [
                    'type' => Type::nonNull($loginResult),
                    'args' => [
                        'email' => Type::string(),
                        'password' => Type::string(),
                        'deviceName' => Type::string(),
                        'input' => $customerLoginInput,
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlInputs::wrapLogin(
                        GraphqlCustomerLogin::run(GraphqlInputs::unwrap($args))
                    ),
                ],
                // Shop REST → createLogin / createCustomerLogin (flat + input:)
                'createLogin' => [
                    'type' => Type::nonNull($loginResult),
                    'args' => [
                        'email' => Type::string(),
                        'password' => Type::string(),
                        'deviceName' => Type::string(),
                        'input' => $customerLoginInput,
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlInputs::wrapLogin(
                        GraphqlCustomerLogin::run(GraphqlInputs::unwrap($args))
                    ),
                ],
                'createCustomerLogin' => [
                    'type' => Type::nonNull($loginResult),
                    'args' => [
                        'email' => Type::string(),
                        'password' => Type::string(),
                        'deviceName' => Type::string(),
                        'input' => $customerLoginInput,
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlInputs::wrapLogin(
                        GraphqlCustomerLogin::run(GraphqlInputs::unwrap($args))
                    ),
                ],
                // Shop REST docs → createSocialLogin
                'createSocialLogin' => [
                    'type' => Type::nonNull($socialLoginResult),
                    'args' => [
                        'provider' => Type::string(),
                        'idToken' => Type::string(),
                        'accessToken' => Type::string(),
                        'deviceToken' => Type::string(),
                        'input' => new InputObjectType([
                            'name' => 'CreateSocialLoginInput',
                            'fields' => [
                                'provider' => Type::string(),
                                'idToken' => Type::string(),
                                'accessToken' => Type::string(),
                                'deviceToken' => Type::string(),
                                'clientMutationId' => Type::string(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        unset($args['input']);

                        return self::createSocialLoginMutation(array_merge($args, $input));
                    },
                ],
                'loginCustomer' => [
                    'type' => Type::nonNull($loginResult),
                    'args' => [
                        'email' => Type::string(),
                        'password' => Type::string(),
                        'deviceName' => Type::string(),
                        'input' => $customerLoginInput,
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlInputs::wrapLogin(
                        GraphqlCustomerLogin::run(GraphqlInputs::unwrap($args))
                    ),
                ],
                'registerCustomer' => [
                    'type' => Type::nonNull($registerResult),
                    'args' => [
                        'email' => Type::nonNull(Type::string()),
                        'password' => Type::nonNull(Type::string()),
                        'firstName' => Type::nonNull(Type::string()),
                        'lastName' => Type::string(),
                        'passwordConfirmation' => Type::string(),
                        'deviceName' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::registerCustomer($args);
                    },
                ],
                // Shop REST → createCustomer / createAccount
                'createCustomer' => [
                    'type' => Type::nonNull($registerResult),
                    'args' => [
                        'email' => Type::string(),
                        'password' => Type::string(),
                        'firstName' => Type::string(),
                        'lastName' => Type::string(),
                        'passwordConfirmation' => Type::string(),
                        'confirmPassword' => Type::string(),
                        'deviceName' => Type::string(),
                        'input' => new InputObjectType([
                            'name' => 'CreateCustomerInput',
                            'fields' => [
                                'email' => Type::string(),
                                'password' => Type::string(),
                                'firstName' => Type::string(),
                                'lastName' => Type::string(),
                                'passwordConfirmation' => Type::string(),
                                'confirmPassword' => Type::string(),
                                'phone' => Type::string(),
                                'subscribedToNewsLetter' => Type::boolean(),
                                'clientMutationId' => Type::string(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        unset($args['input']);

                        return self::registerCustomer(array_merge($args, $input));
                    },
                ],
                'createAccount' => [
                    'type' => Type::nonNull($registerResult),
                    'args' => [
                        'email' => Type::string(),
                        'password' => Type::string(),
                        'firstName' => Type::string(),
                        'lastName' => Type::string(),
                        'passwordConfirmation' => Type::string(),
                        'confirmPassword' => Type::string(),
                        'deviceName' => Type::string(),
                        'input' => new InputObjectType([
                            'name' => 'CreateAccountInput',
                            'fields' => [
                                'email' => Type::string(),
                                'password' => Type::string(),
                                'firstName' => Type::string(),
                                'lastName' => Type::string(),
                                'passwordConfirmation' => Type::string(),
                                'confirmPassword' => Type::string(),
                                'phone' => Type::string(),
                                'subscribedToNewsLetter' => Type::boolean(),
                                'clientMutationId' => Type::string(),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
                        unset($args['input']);

                        return self::registerCustomer(array_merge($args, $input));
                    },
                ],
                'createReview' => [
                    'type' => Type::nonNull($review),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                        'rating' => Type::nonNull(Type::int()),
                        'title' => Type::string(),
                        'body' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $customer = auth('customer')->user();
                        if (! $customer) {
                            throw new UserError('Authentication required.');
                        }
                        $rating = (int) $args['rating'];
                        if ($rating < 1 || $rating > 5) {
                            throw new UserError('Rating must be between 1 and 5.');
                        }
                        try {
                            $row = app(ReviewService::class)->submit($customer, [
                                'product_id' => (int) $args['productId'],
                                'rating' => $rating,
                                'title' => isset($args['title']) ? (string) $args['title'] : null,
                                'body' => isset($args['body']) ? (string) $args['body'] : null,
                            ]);
                        } catch (ValidationException $e) {
                            $messages = collect($e->errors())->flatten()->filter()->values()->all();
                            throw new UserError($messages[0] ?? 'Review could not be saved.');
                        }

                        return [
                            'id' => (string) $row->id,
                            'rating' => (int) $row->rating,
                            'title' => $row->title,
                            'body' => $row->body,
                            'customer_name' => $customer->displayName(),
                            'created_at' => optional($row->created_at)?->toIso8601String(),
                        ];
                    },
                ],
                // Shop REST → createProductReview (guest + Base64 attachments)
                'createProductReview' => [
                    'type' => Type::nonNull($createProductReviewResult),
                    'args' => [
                        'productId' => Type::nonNull(Type::id()),
                        'title' => Type::nonNull(Type::string()),
                        'comment' => Type::nonNull(Type::string()),
                        'rating' => Type::nonNull(Type::int()),
                        'name' => Type::nonNull(Type::string()),
                        'email' => Type::string(),
                        'attachments' => Type::string(),
                        'clientMutationId' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::createProductReviewMutation($args);
                    },
                ],
                // Shop REST → updateProductReview (owner-only)
                'updateProductReview' => [
                    'type' => Type::nonNull($updateProductReviewResult),
                    'args' => [
                        'input' => $updateProductReviewInput,
                        'id' => Type::id(),
                        'productId' => Type::int(),
                        'title' => Type::string(),
                        'comment' => Type::string(),
                        'rating' => Type::int(),
                        'name' => Type::string(),
                        'email' => Type::string(),
                        'clientMutationId' => Type::string(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlProductReviews::updateMutation($args),
                ],
                // Shop REST → deleteProductReview (owner-only hard delete)
                'deleteProductReview' => [
                    'type' => Type::nonNull($deleteProductReviewResult),
                    'args' => [
                        'input' => $deleteProductReviewInput,
                        'id' => Type::id(),
                        'clientMutationId' => Type::string(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlProductReviews::deleteMutation($args),
                ],
                'forgotPassword' => [
                    'type' => Type::nonNull($newsletterResult),
                    'args' => [
                        'email' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::forgotPasswordMutation($args);
                    },
                ],
                'createForgotPassword' => [
                    'type' => Type::nonNull($newsletterResult),
                    'args' => [
                        'email' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::forgotPasswordMutation($args);
                    },
                ],
                'forgotCustomerPassword' => [
                    'type' => Type::nonNull($newsletterResult),
                    'args' => [
                        'email' => Type::nonNull(Type::string()),
                        'input' => new InputObjectType([
                            'name' => 'ForgotCustomerPasswordInput',
                            'fields' => [
                                'email' => Type::nonNull(Type::string()),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];

                        return self::forgotPasswordMutation(array_merge($args, $input));
                    },
                ],
                'resetPassword' => [
                    'type' => Type::nonNull($newsletterResult),
                    'args' => [
                        'email' => Type::nonNull(Type::string()),
                        'token' => Type::nonNull(Type::string()),
                        'password' => Type::nonNull(Type::string()),
                        'passwordConfirmation' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::resetPasswordMutation($args);
                    },
                ],
                'resetCustomerPassword' => [
                    'type' => Type::nonNull($newsletterResult),
                    'args' => [
                        'email' => Type::nonNull(Type::string()),
                        'token' => Type::nonNull(Type::string()),
                        'password' => Type::nonNull(Type::string()),
                        'passwordConfirmation' => Type::nonNull(Type::string()),
                        'input' => new InputObjectType([
                            'name' => 'ResetCustomerPasswordInput',
                            'fields' => [
                                'email' => Type::nonNull(Type::string()),
                                'token' => Type::nonNull(Type::string()),
                                'password' => Type::nonNull(Type::string()),
                                'passwordConfirmation' => Type::nonNull(Type::string()),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];

                        return self::resetPasswordMutation(array_merge($args, $input));
                    },
                ],
                'createResetPassword' => [
                    'type' => Type::nonNull($newsletterResult),
                    'args' => [
                        'email' => Type::nonNull(Type::string()),
                        'token' => Type::nonNull(Type::string()),
                        'password' => Type::nonNull(Type::string()),
                        'passwordConfirmation' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => function ($root, array $args) {
                        return self::resetPasswordMutation($args);
                    },
                ],
                'updateCustomer' => [
                    'type' => Type::nonNull($customer),
                    'args' => [
                        'firstName' => Type::string(),
                        'lastName' => Type::string(),
                        'phone' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $profile = GraphqlCustomerAccount::updateProfile($args)['customerProfileUpdate'];

                        return [
                            'id' => (string) $profile['id'],
                            'email' => $profile['email'],
                            'name' => $profile['name'],
                            'phone' => $profile['phone'],
                        ];
                    },
                ],
                // Shop REST → createCustomerProfileUpdate
                'createCustomerProfileUpdate' => [
                    'type' => Type::nonNull($customerProfileUpdateResult),
                    'args' => [
                        'firstName' => Type::string(),
                        'lastName' => Type::string(),
                        'email' => Type::string(),
                        'phone' => Type::string(),
                        'gender' => Type::string(),
                        'dateOfBirth' => Type::string(),
                        'currentPassword' => Type::string(),
                        'password' => Type::string(),
                        'confirmPassword' => Type::string(),
                        'passwordConfirmation' => Type::string(),
                        'subscribedToNewsLetter' => Type::boolean(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCustomerAccount::updateProfile($args),
                ],
                'createCustomerProfileDelete' => [
                    'type' => Type::nonNull($accountActionResult),
                    'args' => [
                        'password' => Type::nonNull(Type::string()),
                        'input' => new InputObjectType([
                            'name' => 'CreateCustomerProfileDeleteInput',
                            'fields' => [
                                'password' => Type::nonNull(Type::string()),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];

                        return GraphqlCustomerAccount::deleteProfile(array_merge($args, $input));
                    },
                ],
                // Shop REST docs → createDeleteCustomer / deleteCustomerAccount
                'createDeleteCustomer' => [
                    'type' => Type::nonNull($accountActionResult),
                    'args' => [
                        'password' => Type::string(),
                        'input' => new InputObjectType([
                            'name' => 'CreateDeleteCustomerInput',
                            'fields' => [
                                'password' => Type::nonNull(Type::string()),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];

                        return GraphqlCustomerAccount::deleteProfile(array_merge($args, $input));
                    },
                ],
                'deleteCustomerAccount' => [
                    'type' => Type::nonNull($accountActionResult),
                    'args' => [
                        'password' => Type::string(),
                        'input' => new InputObjectType([
                            'name' => 'DeleteCustomerAccountInput',
                            'fields' => [
                                'password' => Type::nonNull(Type::string()),
                            ],
                        ]),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : [];

                        return GraphqlCustomerAccount::deleteProfile(array_merge($args, $input));
                    },
                ],
                'createLogout' => [
                    'type' => Type::nonNull($accountActionResult),
                    'resolve' => fn () => GraphqlCustomerAccount::logout(),
                ],
                'createVerifyToken' => [
                    'type' => Type::nonNull($accountActionResult),
                    'args' => [
                        'input' => $verifyTokenInput,
                        'token' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $args = GraphqlInputs::unwrap($args);
                        // Optional token in input is accepted for clients; Sanctum already bound.
                        unset($args['token']);

                        return GraphqlInputs::wrapVerify(GraphqlCustomerAccount::verifyToken());
                    },
                ],
                'changeCustomerPassword' => [
                    'type' => Type::nonNull($newsletterResult),
                    'args' => [
                        'input' => new InputObjectType([
                            'name' => 'ChangeCustomerPasswordInput',
                            'fields' => [
                                'oldPassword' => Type::string(),
                                'currentPassword' => Type::string(),
                                'newPassword' => Type::string(),
                                'password' => Type::string(),
                                'passwordConfirmation' => Type::string(),
                            ],
                        ]),
                        'oldPassword' => Type::string(),
                        'currentPassword' => Type::string(),
                        'newPassword' => Type::string(),
                        'password' => Type::string(),
                        'passwordConfirmation' => Type::string(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;
                        $user = auth('customer')->user();
                        if (! $user instanceof Customer) {
                            throw new UserError('Authentication required.');
                        }
                        $current = (string) ($input['oldPassword'] ?? $input['currentPassword'] ?? '');
                        if ($current === '' || ! Hash::check($current, (string) $user->password)) {
                            throw new UserError('Current password is incorrect.');
                        }
                        $password = (string) ($input['newPassword'] ?? $input['password'] ?? '');
                        $confirmation = (string) ($input['passwordConfirmation'] ?? $input['confirmPassword'] ?? '');
                        try {
                            validator(
                                [
                                    'password' => $password,
                                    'password_confirmation' => $confirmation,
                                ],
                                ['password' => CustomerPassword::rules()],
                                CustomerPassword::messages()
                            )->validate();
                        } catch (ValidationException $e) {
                            $messages = collect($e->errors())->flatten()->filter()->values()->all();
                            throw new UserError($messages[0] ?? 'Password change failed.');
                        }
                        $user->forceFill([
                            'password' => $password,
                            'remember_token' => Str::random(60),
                        ])->save();
                        SessionRevoker::revokeOthers('customer', $user->id);

                        return [
                            'ok' => true,
                            'success' => true,
                            'status' => true,
                            'message' => 'Password updated.',
                        ];
                    },
                ],
                'saveCustomerAddress' => [
                    'type' => Type::nonNull($address),
                    'args' => [
                        'id' => Type::id(),
                        'label' => Type::string(),
                        'firstName' => Type::nonNull(Type::string()),
                        'lastName' => Type::string(),
                        'line1' => Type::nonNull(Type::string()),
                        'line2' => Type::string(),
                        'city' => Type::nonNull(Type::string()),
                        'state' => Type::string(),
                        'postalCode' => Type::string(),
                        'countryCode' => Type::nonNull(Type::string()),
                        'phone' => Type::string(),
                        'isDefaultShipping' => Type::boolean(),
                        'isDefaultBilling' => Type::boolean(),
                    ],
                    'resolve' => function ($root, array $args) {
                        $user = auth('customer')->user();
                        if (! $user instanceof Customer) {
                            throw new UserError('Authentication required.');
                        }
                        $country = strtoupper(trim((string) $args['countryCode']));
                        if (strlen($country) !== 2) {
                            throw new UserError('Country must be a 2-letter ISO code.');
                        }
                        $data = [
                            'label' => isset($args['label']) ? trim((string) $args['label']) : null,
                            'first_name' => trim((string) $args['firstName']),
                            'last_name' => isset($args['lastName']) ? trim((string) $args['lastName']) : null,
                            'line1' => trim((string) $args['line1']),
                            'line2' => isset($args['line2']) ? trim((string) $args['line2']) : null,
                            'city' => trim((string) $args['city']),
                            'state' => isset($args['state']) ? trim((string) $args['state']) : null,
                            'postal_code' => isset($args['postalCode']) ? trim((string) $args['postalCode']) : null,
                            'country_code' => $country,
                            'phone' => isset($args['phone']) ? trim((string) $args['phone']) : null,
                            'is_default_shipping' => (bool) ($args['isDefaultShipping'] ?? false),
                            'is_default_billing' => (bool) ($args['isDefaultBilling'] ?? false),
                        ];
                        if ($data['first_name'] === '' || $data['line1'] === '' || $data['city'] === '') {
                            throw new UserError('firstName, line1, and city are required.');
                        }

                        $id = isset($args['id']) ? (int) $args['id'] : 0;
                        if ($id > 0) {
                            $row = $user->addresses()->whereKey($id)->first();
                            if (! $row) {
                                throw new UserError('Address not found.');
                            }
                            $row->fill($data)->save();
                        } else {
                            $max = CustomerSettings::maxSavedAddresses();
                            $count = $user->addresses()->count();
                            if ($max !== null && $count >= $max) {
                                throw new UserError('You have reached the maximum number of saved addresses.');
                            }
                            if (! CustomerSettings::allowMultipleAddresses() && $count >= 1) {
                                throw new UserError('Multiple addresses are not allowed.');
                            }
                            $row = $user->addresses()->create($data);
                        }

                        if ($row->is_default_shipping) {
                            $user->addresses()->whereKeyNot($row->id)->update(['is_default_shipping' => false]);
                        }
                        if ($row->is_default_billing) {
                            $user->addresses()->whereKeyNot($row->id)->update(['is_default_billing' => false]);
                        }

                        return self::mapAddress($row->fresh());
                    },
                ],
                'deleteCustomerAddress' => [
                    'type' => Type::nonNull($newsletterResult),
                    'args' => [
                        'id' => Type::nonNull(Type::id()),
                    ],
                    'resolve' => function ($root, array $args) {
                        $user = auth('customer')->user();
                        if (! $user instanceof Customer) {
                            throw new UserError('Authentication required.');
                        }
                        $row = $user->addresses()->whereKey((int) $args['id'])->first();
                        if (! $row) {
                            throw new UserError('Address not found.');
                        }
                        $row->delete();

                        return ['ok' => true, 'message' => 'Address deleted.'];
                    },
                ],
                // Shop REST → createAddUpdateCustomerAddress / createDeleteCustomerAddress
                'createAddUpdateCustomerAddress' => [
                    'type' => Type::nonNull($addUpdateCustomerAddressResult),
                    'args' => [
                        'input' => Type::nonNull($addUpdateCustomerAddressInput),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCustomerAddresses::addUpdate($args),
                ],
                'createDeleteCustomerAddress' => [
                    'type' => Type::nonNull($deleteCustomerAddressResult),
                    'args' => [
                        'input' => $deleteCustomerAddressInput,
                        'id' => Type::id(),
                        'addressId' => Type::id(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCustomerAddresses::delete($args),
                ],
                // Shop REST docs → addCustomerAddress / updateCustomerAddress
                'addCustomerAddress' => [
                    'type' => Type::nonNull($customerAddressMutationResult),
                    'args' => [
                        'input' => $addUpdateCustomerAddressInput,
                        'firstName' => Type::string(),
                        'lastName' => Type::string(),
                        'address' => Type::string(),
                        'address1' => Type::string(),
                        'city' => Type::string(),
                        'state' => Type::string(),
                        'country' => Type::string(),
                        'zipCode' => Type::string(),
                        'postalCode' => Type::string(),
                        'phoneNumber' => Type::string(),
                        'phone' => Type::string(),
                        'defaultBilling' => Type::boolean(),
                        'defaultShipping' => Type::boolean(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCustomerAddresses::addCustomerAddress($args),
                ],
                'updateCustomerAddress' => [
                    'type' => Type::nonNull($customerAddressMutationResult),
                    'args' => [
                        'input' => $addUpdateCustomerAddressInput,
                        'addressId' => Type::id(),
                        'id' => Type::id(),
                        'firstName' => Type::string(),
                        'lastName' => Type::string(),
                        'address' => Type::string(),
                        'address1' => Type::string(),
                        'city' => Type::string(),
                        'state' => Type::string(),
                        'country' => Type::string(),
                        'zipCode' => Type::string(),
                        'postalCode' => Type::string(),
                        'phoneNumber' => Type::string(),
                        'phone' => Type::string(),
                        'defaultBilling' => Type::boolean(),
                        'defaultShipping' => Type::boolean(),
                    ],
                    'resolve' => fn ($root, array $args) => GraphqlCustomerAddresses::updateCustomerAddress($args),
                ],
            ],
        ]);

        return new Schema(['query' => $query, 'mutation' => $mutation]);
    }

    /**
     * Shop REST → createSocialLogin (verify client-held Google/Facebook token).
     * Also used by REST POST /api/v1/store/social-logins (GraphQL-only beat).
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function createSocialLoginMutation(array $args): array
    {
        $fail = static function (string $code, string $message): array {
            $nested = [
                'id' => null,

                'token' => null,
                'apiToken' => null,
                'firstName' => null,
                'lastName' => null,
                'email' => null,
                'phone' => null,
                'isNewCustomer' => false,
                'success' => false,
                'message' => $message,
                'code' => $code,
            ];

            return array_merge($nested, [
                'accessToken' => null,
                'customer' => null,
                'socialLogin' => $nested,
            ]);
        };

        $provider = strtolower(trim((string) ($args['provider'] ?? '')));
        if ($provider === '') {
            return $fail('PROVIDER_NOT_SUPPORTED', 'A social login provider is required.');
        }
        if (! in_array($provider, SocialLogin::PROVIDERS, true)) {
            return $fail('PROVIDER_NOT_SUPPORTED', 'The provider is not supported.');
        }
        if (! SocialLogin::ready($provider)) {
            return $fail('PROVIDER_DISABLED', 'The provider is not enabled.');
        }

        $idToken = isset($args['idToken']) ? trim((string) $args['idToken']) : '';
        $accessToken = isset($args['accessToken']) ? trim((string) $args['accessToken']) : '';
        if ($idToken === '' && $accessToken === '') {
            return $fail('SOCIAL_TOKEN_REQUIRED', 'An idToken or accessToken is required.');
        }

        try {
            $profile = SocialLogin::profileFromClientToken(
                $provider,
                $idToken !== '' ? $idToken : null,
                $accessToken !== '' ? $accessToken : null,
            );
        } catch (Throwable $e) {
            $code = $e->getMessage();
            $messages = [
                'PROVIDER_NOT_SUPPORTED' => 'The provider is not supported.',
                'PROVIDER_DISABLED' => 'The provider is not enabled.',
                'SOCIAL_TOKEN_REQUIRED' => 'An idToken or accessToken is required.',
                'SOCIAL_TOKEN_INVALID' => 'The social login token is invalid.',
                'SOCIAL_TOKEN_AUDIENCE' => 'The Google token audience does not match this store.',
                'SOCIAL_EMAIL_REQUIRED' => 'The provider did not share an email address.',
            ];
            if (! isset($messages[$code])) {
                $code = 'SOCIAL_TOKEN_INVALID';
            }

            return $fail($code, $messages[$code]);
        }

        if (($profile['id'] ?? '') === '' || ($profile['email'] ?? '') === '' || ! filter_var($profile['email'], FILTER_VALIDATE_EMAIL)) {
            return $fail('SOCIAL_EMAIL_REQUIRED', 'The provider did not share an email address.');
        }

        $column = SocialLogin::idColumn($provider);
        $isNew = false;
        $linked = Customer::query()
            ->inCurrentShop()
            ->where($column, $profile['id'])
            ->first();
        if ($linked) {
            $customer = $linked;
        } else {
            $existing = Customer::query()
                ->inCurrentShop()
                ->where('email', $profile['email'])
                ->first();
            if ($existing) {
                $existing->forceFill([
                    $column => $profile['id'],
                    'email_verified_at' => $existing->email_verified_at ?: now(),
                ])->save();
                $customer = $existing;
            } else {
                $group = CustomerSettings::resolveDefaultGroup();
                $home = app(StoreContext::class)->customerHomeId() ?: app(StoreContext::class)->requireId();
                $full = trim($profile['first_name'].' '.$profile['last_name']);
                $customer = Customer::createTrusted([
                    'name' => $full !== '' ? $full : $profile['email'],
                    'first_name' => $profile['first_name'],
                    'last_name' => $profile['last_name'],
                    'email' => $profile['email'],
                    'password' => Str::password(40),
                    $column => $profile['id'],
                    'store_id' => $home,
                    'is_enabled' => CustomerSettings::accountActivation() === 'automatic',
                    'email_verified_at' => now(),
                    'newsletter' => false,
                    'customer_group' => $group?->code,
                    'customer_group_id' => $group?->id,
                ]);
                $isNew = true;
            }
        }

        if (! $customer->is_enabled) {
            return $fail('ACCOUNT_INACTIVE', 'This account is not activated.');
        }

        Auth::guard('customer')->setUser($customer);
        $device = trim((string) ($args['deviceToken'] ?? 'graphql-social'));
        $token = $customer->createToken(mb_substr($device !== '' ? $device : 'graphql-social', 0, 80))->plainTextToken;

        $nested = [
            'id' => (string) $customer->id,

            'token' => $token,
            'apiToken' => $token,
            'firstName' => (string) ($customer->first_name ?? $profile['first_name'] ?? ''),
            'lastName' => (string) ($customer->last_name ?? $profile['last_name'] ?? ''),
            'email' => (string) $customer->email,
            'phone' => $customer->phone,
            'isNewCustomer' => $isNew,
            'success' => true,
            'message' => $isNew ? 'Account created and signed in.' : 'Signed in successfully.',
            'code' => null,
        ];

        return array_merge($nested, [
            'accessToken' => $token,
            'customer' => [
                'id' => (string) $customer->id,
                'email' => $customer->email,
                'name' => $customer->name,
                'firstName' => (string) ($customer->first_name ?? ''),
                'lastName' => (string) ($customer->last_name ?? ''),
                'phone' => $customer->phone,
            ],
            'socialLogin' => $nested,
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function registerCustomer(array $args): array
    {
        if (! CustomerSettings::registrationEnabled()) {
            throw new UserError('Customer registration is disabled.');
        }
        if (CustomerSettings::mobileLoginEnabled()) {
            throw new UserError('SMS-verified registration is required for this store. Use the REST register endpoint.');
        }

        $email = strtolower(trim((string) ($args['email'] ?? '')));
        $password = (string) ($args['password'] ?? '');
        $confirm = $args['passwordConfirmation'] ?? $args['confirmPassword'] ?? null;
        if (is_string($confirm) && $confirm !== '' && ! hash_equals($password, $confirm)) {
            throw new UserError('Password confirmation does not match.');
        }
        $first = trim((string) ($args['firstName'] ?? ''));
        $last = trim((string) ($args['lastName'] ?? ''));
        if ($email === '' || $first === '' || strlen($password) < 8) {
            throw new UserError('Email, first name, and password (min 8 chars) are required.');
        }

        $home = app(StoreContext::class)->customerHomeId() ?: app(StoreContext::class)->requireId();
        if (Customer::query()->where('email', $email)->where('store_id', $home)->exists()) {
            throw new UserError('An account with this email already exists.');
        }

        $group = CustomerSettings::resolveDefaultGroup();
        $activation = CustomerSettings::accountActivation();
        $verification = CustomerSettings::emailVerification();
        $enabled = $activation === 'automatic';

        try {
            $customer = Customer::createTrusted([
                'name' => trim($first.' '.$last),
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'password' => $password,
                'store_id' => $home,
                'is_enabled' => $enabled,
                'customer_group' => $group?->code,
                'customer_group_id' => $group?->id,
                'email_verified_at' => $verification === 'disabled' ? now() : null,
                'newsletter' => (bool) ($args['subscribedToNewsLetter'] ?? false),
                'phone' => isset($args['phone']) ? trim((string) $args['phone']) : null,
            ]);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();
            throw new UserError($messages[0] ?? 'Registration failed.');
        } catch (Throwable $e) {
            throw new UserError($e->getMessage());
        }

        $pending = ! $enabled || $verification === 'required';
        $token = null;
        if (! $pending) {
            Auth::guard('customer')->setUser($customer);
            $device = trim((string) ($args['deviceName'] ?? 'graphql'));
            $token = $customer->createToken(mb_substr($device !== '' ? $device : 'graphql', 0, 80))->plainTextToken;
        }

        return [
            'access_token' => $token,
            'accessToken' => $token,
            'token' => $token,
            'token_type' => $token ? 'Bearer' : null,
            'success' => true,
            'message' => $pending ? 'Account created. Activation required.' : 'Account created.',
            'pending' => $pending,
            'customer' => [
                'id' => (string) $customer->id,
                'email' => $customer->email,
                'name' => $customer->name,
                'phone' => $customer->phone,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, message: string}
     */
    protected static function forgotPasswordMutation(array $args): array
    {
        $email = strtolower(trim((string) $args['email']));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new UserError('A valid email is required.');
        }
        Password::broker('customers')->sendResetLink(['email' => $email]);

        return ['ok' => true, 'message' => 'If that email exists, a reset link was sent.'];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, message: string}
     */
    protected static function resetPasswordMutation(array $args): array
    {
        $email = strtolower(trim((string) $args['email']));
        $password = (string) $args['password'];
        $confirmation = (string) $args['passwordConfirmation'];
        try {
            validator(
                [
                    'email' => $email,
                    'token' => (string) $args['token'],
                    'password' => $password,
                    'password_confirmation' => $confirmation,
                ],
                [
                    'email' => ['required', 'email'],
                    'token' => ['required', 'string'],
                    'password' => CustomerPassword::rules(),
                ],
                CustomerPassword::messages()
            )->validate();
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();
            throw new UserError($messages[0] ?? 'Password reset failed.');
        }

        $status = Password::broker('customers')->reset(
            [
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $confirmation,
                'token' => (string) $args['token'],
            ],
            function (Customer $customer, string $plain) {
                $customer->forceFill([
                    'password' => $plain,
                    'remember_token' => Str::random(60),
                ])->save();
                SessionRevoker::revokeOthers('customer', $customer->id);
            }
        );
        if ($status !== Password::PASSWORD_RESET) {
            throw new UserError('This reset link is invalid or expired.');
        }

        return ['ok' => true, 'message' => 'Password updated.'];
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function resolveProductsConnection(array $args): array
    {
        $locale = app()->getLocale();
        $q = trim((string) ($args['query'] ?? $args['q'] ?? ''));
        $useCursor = array_key_exists('first', $args) || array_key_exists('after', $args)
            || (! array_key_exists('limit', $args) && ! array_key_exists('offset', $args));

        $query = self::productSearchQuery($q, $locale, $args);
        $sortKey = strtoupper(trim((string) ($args['sortKey'] ?? 'ID')));
        $reverse = (bool) ($args['reverse'] ?? false);
        $dir = $reverse ? 'asc' : 'desc';
        match ($sortKey) {
            'TITLE', 'NAME' => $query->orderBy('id', $dir),
            'PRICE' => $query->orderBy('price', $dir)->orderBy('id', $dir),
            'CREATED_AT' => $query->orderBy('created_at', $dir)->orderBy('id', $dir),
            'UPDATED_AT' => $query->orderBy('updated_at', $dir)->orderBy('id', $dir),
            default => $query->orderBy('id', $dir),
        };

        $total = (clone $query)->count();

        if (! $useCursor) {
            $limit = max(1, min(100, (int) ($args['limit'] ?? 12)));
            $offset = max(0, (int) ($args['offset'] ?? 0));
            $items = $query->offset($offset)->limit($limit)->get()
                ->map(fn (Product $p) => self::mapProduct($p, $locale))
                ->all();
            $edges = collect($items)->map(fn (array $node) => [
                'cursor' => base64_encode('prod:'.($node['id'] ?? '0')),
                'node' => $node,
            ])->values()->all();

            return [
                'edges' => $edges,
                'nodes' => $items,
                'items' => $items,
                'pageInfo' => [
                    'hasNextPage' => ($offset + $limit) < $total,
                    'hasPreviousPage' => $offset > 0,
                    'startCursor' => $edges[0]['cursor'] ?? null,
                    'endCursor' => $edges !== [] ? $edges[array_key_last($edges)]['cursor'] : null,
                ],
                'total' => $total,
                'totalCount' => $total,
                'totalItems' => $total,
            ];
        }

        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 30)));
        $afterId = 0;
        if (! empty($args['after'])) {
            $decoded = base64_decode((string) $args['after'], true);
            if (is_string($decoded) && preg_match('/^prod:(\d+)$/', $decoded, $m)) {
                $afterId = (int) $m[1];
            }
        }
        if ($afterId > 0) {
            if ($dir === 'desc') {
                $query->where('id', '<', $afterId);
            } else {
                $query->where('id', '>', $afterId);
            }
        }
        $rows = $query->limit($first + 1)->get();
        $hasNext = $rows->count() > $first;
        $slice = $rows->take($first);
        $items = $slice->map(fn (Product $p) => self::mapProduct($p, $locale))->values()->all();
        $edges = $slice->map(fn (Product $p) => [
            'cursor' => base64_encode('prod:'.$p->id),
            'node' => self::mapProduct($p, $locale),
        ])->values()->all();
        $start = $slice->first();
        $end = $slice->last();

        return [
            'edges' => $edges,
            'nodes' => $items,
            'items' => $items,
            'pageInfo' => [
                'hasNextPage' => $hasNext,
                'hasPreviousPage' => $afterId > 0,
                'startCursor' => $start ? base64_encode('prod:'.$start->id) : null,
                'endCursor' => $end ? base64_encode('prod:'.$end->id) : null,
            ],
            'total' => $total,
            'totalCount' => $total,
            'totalItems' => $total,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Builder<\App\Domain\Catalog\Models\Product>
     */
    protected static function productSearchQuery(string $q, string $locale, array $filters = [])
    {
        $query = Product::query()->where('is_enabled', true)->with(['translations', 'images']);
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($inner) use ($like, $locale) {
                $inner->where('sku', 'like', $like)
                    ->orWhereHas('translations', function ($t) use ($like, $locale) {
                        $t->where('language_code', $locale)
                            ->where(function ($n) use ($like) {
                                $n->where('name', 'like', $like)->orWhere('slug', 'like', $like);
                            });
                    });
            });
        }

        $categoryId = isset($filters['categoryId']) ? (int) $filters['categoryId'] : 0;
        if ($categoryId > 0) {
            $query->where(function ($inner) use ($categoryId) {
                $inner->where('default_category_id', $categoryId)
                    ->orWhereHas('categories', fn ($c) => $c->where('categories.id', $categoryId));
            });
        }
        if (isset($filters['minPrice']) && $filters['minPrice'] !== null && $filters['minPrice'] !== '') {
            $query->where('price', '>=', (float) $filters['minPrice']);
        }
        if (isset($filters['maxPrice']) && $filters['maxPrice'] !== null && $filters['maxPrice'] !== '') {
            $query->where('price', '<=', (float) $filters['maxPrice']);
        }

        GraphqlShopAttributes::applyProductFilter($query, $filters);

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function cartPayload(?CartService $carts = null): array
    {
        $carts ??= app(CartService::class);
        $customer = auth('customer')->user();
        $cart = $carts->current($customer);
        try {
            $totals = $carts->summary(
                $cart,
                0.0,
                null,
                null,
                $customer,
                Money::currentCode(),
                true,
                null,
            );
        } catch (Throwable) {
            $totals = ['subtotal' => 0, 'grand_total' => 0];
        }
        $presented = $carts->present($cart, $totals);
        $items = [];
        foreach (($presented['items'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $qty = isset($row['qty']) ? (float) $row['qty'] : null;
            $unit = self::moneyAmount($row['unit_price'] ?? $row['price'] ?? null);
            $line = self::moneyAmount($row['line_total'] ?? $row['total'] ?? null);
            $items[] = [
                'id' => (string) ($row['id'] ?? ''),
                'product_id' => isset($row['product_id']) ? (string) $row['product_id'] : null,
                'productId' => isset($row['product_id']) ? (string) $row['product_id'] : null,
                'name' => $row['name'] ?? null,
                'qty' => $qty,
                'quantity' => $qty,
                'unit_price' => $unit,
                'basePrice' => $unit,
                'line_total' => $line,
                'totalPrice' => $line,
                'booking_date' => isset($row['booking_date']) ? (string) $row['booking_date'] : null,
                'booking_end_date' => isset($row['booking_end_date']) ? (string) $row['booking_end_date'] : null,
            ];
        }

        $couponCode = null;
        if (! empty($cart->discount_rule_id)) {
            $couponCode = \App\Domain\Promo\Models\DiscountRule::query()
                ->whereKey($cart->discount_rule_id)
                ->value('code');
        }

        $itemCount = count($items);
        $itemsQty = collect($items)->sum(fn (array $i) => (float) ($i['qty'] ?? 0));
        $subtotal = self::moneyAmount($totals['subtotal'] ?? null);
        $discount = self::moneyAmount($totals['discount'] ?? $totals['discount_total'] ?? null);
        $grand = self::moneyAmount($totals['grand_total'] ?? null);
        $tax = self::moneyAmount($totals['tax'] ?? $totals['tax_total'] ?? null);
        $shipping = self::moneyAmount($totals['shipping'] ?? $totals['shipping_total'] ?? null);

        return [
            'id' => isset($presented['id']) ? (string) $presented['id'] : (string) $cart->id,
            'item_count' => $itemCount,
            'itemsCount' => $itemCount,
            'itemsQty' => $itemsQty,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'discountAmount' => $discount,
            'grand_total' => $grand,
            'grandTotal' => $grand,
            'taxTotal' => $tax,
            'shippingTotal' => $shipping,
            'coupon_code' => $couponCode ? (string) $couponCode : null,
            'couponCode' => $couponCode ? (string) $couponCode : null,
            // items resolved as Relay connection; list kept for itemsList + legacy resolvers
            'items' => $items,
            'itemsList' => $items,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    protected static function cartItemsConnection(array $items, array $args): array
    {
        $normalized = [];
        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            $qty = isset($row['qty']) ? (float) $row['qty'] : null;
            $unit = self::moneyAmount($row['unit_price'] ?? $row['basePrice'] ?? null);
            $line = self::moneyAmount($row['line_total'] ?? $row['totalPrice'] ?? null);
            $productId = isset($row['product_id']) ? (string) $row['product_id'] : ($row['productId'] ?? null);
            $normalized[] = [
                'id' => (string) ($row['id'] ?? ''),
                'product_id' => $productId,
                'productId' => $productId,
                'name' => $row['name'] ?? null,
                'qty' => $qty,
                'quantity' => $qty,
                'unit_price' => $unit,
                'basePrice' => $unit,
                'line_total' => $line,
                'totalPrice' => $line,
                'booking_date' => $row['booking_date'] ?? null,
                'booking_end_date' => $row['booking_end_date'] ?? null,
            ];
        }
        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 50)));
        $after = 0;
        $afterCursor = trim((string) ($args['after'] ?? ''));
        if ($afterCursor !== '') {
            $decoded = base64_decode($afterCursor, true);
            if (is_string($decoded) && preg_match('/^ci:(\d+)$/', $decoded, $m)) {
                $after = (int) $m[1];
            }
        }
        $total = count($normalized);
        $slice = array_slice($normalized, $after, $first);
        $edges = [];
        foreach ($slice as $i => $node) {
            $idx = $after + $i + 1;
            $edges[] = [
                'cursor' => base64_encode('ci:'.$idx),
                'node' => $node,
            ];
        }

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => ($after + $first) < $total,
                'hasPreviousPage' => $after > 0,
                'startCursor' => $edges[0]['cursor'] ?? null,
                'endCursor' => $edges !== [] ? $edges[array_key_last($edges)]['cursor'] : null,
            ],
            'totalCount' => $total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapCmsPage(CmsPage $page, string $locale): array
    {
        $t = $page->translation($locale);

        return [
            'id' => (string) $page->id,
            'title' => $t?->title,
            'slug' => $t?->slug,
            'short_description' => $t?->short_description,
            'body' => $t?->body,
            'meta_title' => $t?->meta_title,
            'meta_description' => $t?->meta_description,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAddress(CustomerAddress $a): array
    {
        return GraphqlCustomerAddresses::map($a);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    public static function mapCategory(Category $c, string $locale, bool $withChildren = false, int $depth = 0): array
    {
        $t = $c->translation($locale);
        $slug = $t?->slug;
        $url = $slug ? '/catalog/category/'.$slug : null;
        $row = [
            'id' => (string) $c->id,

            'name' => $t?->name,
            'slug' => $slug,
            'url' => $url,
            'parent_id' => $c->parent_id !== null ? (string) $c->parent_id : null,
            'position' => (int) ($c->position ?? 0),
            'status' => $c->is_enabled ? 1 : 0,
            'displayMode' => 'products_and_description',
            'logoUrl' => $c->image_path ? '/storage/'.ltrim((string) $c->image_path, '/') : null,
            'bannerUrl' => $c->banner_path ? '/storage/'.ltrim((string) $c->banner_path, '/') : null,
            'logoPath' => $c->image_path ? (string) $c->image_path : null,
            'bannerPath' => $c->banner_path ? (string) $c->banner_path : null,
            'translation' => [
                'id' => $t?->id ? (string) $t->id : (string) $c->id,

                'categoryId' => (int) $c->id,
                'name' => $t?->name,
                'slug' => $slug,
                'urlPath' => $slug ?? '',
                'description' => $t?->description ?? null,
                'metaTitle' => $t?->meta_title ?? null,
                'metaDescription' => $t?->meta_description ?? null,
                'metaKeywords' => $t?->meta_keywords ?? null,
                'locale' => $locale,
            ],
        ];
        // children resolved via categoryChildrenConnection (cursor shape)
        return $row;
    }

    /**
     *
     * @param  array<string, mixed>  $args
     */
    protected static function findShopProduct(array $args, string $locale): ?Product
    {
        $with = ['translations', 'brand', 'images', 'variants.attributeValues.attribute'];
        $base = Product::query()->where('is_enabled', true)->with($with);

        $id = isset($args['id']) ? (int) $args['id'] : 0;
        if ($id > 0) {
            return (clone $base)->where('id', $id)->first();
        }

        $sku = trim((string) ($args['sku'] ?? ''));
        if ($sku !== '') {
            return (clone $base)->whereRaw('LOWER(sku) = ?', [mb_strtolower($sku)])->first();
        }

        $slugLookup = trim((string) ($args['slug'] ?? ''));
        if ($slugLookup === '') {
            return null;
        }
        $slugLookup = trim($slugLookup, '/');
        if (str_contains($slugLookup, '/')) {
            $slugLookup = (string) Str::afterLast($slugLookup, '/');
        }

        $row = (clone $base)
            ->whereHas('translations', fn ($q) => $q->where('slug', $slugLookup)->where('language_code', $locale))
            ->first();
        if ($row) {
            return $row;
        }

        return (clone $base)
            ->whereHas('translations', fn ($q) => $q->where('slug', $slugLookup))
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public static function mapProduct(Product $product, string $locale): array
    {
        $present = app(CatalogPresenter::class);
        $card = $present->product($product, $locale, false);
        $t = $product->translation($locale);

        $short = $card['short_description'] ?? $t?->short_description ?? null;
        $description = $card['description'] ?? $t?->description ?? null;
        $slug = $card['slug'] ?? $t?->slug;
        $image = is_string($card['image'] ?? null)
            ? $card['image']
            : (is_string($card['cover'] ?? null) ? $card['cover'] : ($card['thumbnail'] ?? null));
        $price = self::moneyAmount($card['price'] ?? null);
        $compare = self::moneyAmount($card['compare_price'] ?? null);
        $special = self::moneyAmount($card['sale_price'] ?? $product->sale_price ?? null);
        $compatType = self::productTypeKey((string) ($product->type ?? 'simple'));

        $product->loadMissing('images');
        $imageEdges = $product->images->values()->map(function ($img, int $i) {
            $path = (string) ($img->path ?? '');
            $url = (string) ($img->url ?? '');

            return [
                'cursor' => base64_encode('img:'.$img->id),
                'node' => [
                    'id' => (string) $img->id,
                    'url' => $url !== '' ? $url : ($path !== '' ? '/storage/'.ltrim($path, '/') : null),
                    'path' => $path !== '' ? $path : null,
                    'publicPath' => $path !== '' ? $path : null,
                    'alt' => method_exists($img, 'altText') ? $img->altText() : ($img->alt ?? null),
                    'position' => (int) ($img->position ?? $i),
                    'type' => 'image',
                ],
            ];
        })->all();

        [$superOpts, $combinations] = self::configurableJson($product);

        return [
            'id' => (string) $product->id,

            'sku' => $product->sku,
            'name' => $card['name'] ?? $t?->name,
            'slug' => $slug,
            'url' => $card['url'] ?? ($slug ? '/catalog/product/'.$slug : null),
            'price' => $price,
            'formattedPrice' => $price !== null ? number_format($price, 2, '.', '') : null,
            'comparePrice' => $compare,
            'specialPrice' => $special,
            'cost' => $product->cost_price !== null ? (float) $product->cost_price : null,
            'weight' => $product->weight !== null ? (float) $product->weight : null,
            'inStock' => (bool) ($card['purchasable'] ?? $card['in_stock'] ?? ($product->stock_status === 'in_stock')),
            'shortDescription' => $short,
            'description' => $description,
            'image' => $image,
            'baseImageUrl' => $image,
            'type' => $compatType,
            'status' => $product->is_enabled ? 1 : 0,
            'metaTitle' => $t?->meta_title ?? null,
            'metaDescription' => $t?->meta_description ?? null,
            'metaKeywords' => $t?->meta_keywords ?? null,
            'new' => (bool) ($product->is_new ?? false),
            'featured' => (bool) ($product->is_best_seller ?? false),
            'rating' => (function () use ($product) {
                $agg = $product->reviews()->where('is_approved', true)
                    ->selectRaw('COUNT(*) as review_count, AVG(rating) as avg_rating')
                    ->first();
                $count = (int) ($agg->review_count ?? 0);
                $rate = $count > 0 ? round((float) ($agg->avg_rating ?? 0), 2) : 0.0;

                return ['rate' => $rate, 'count' => $count];
            })(),
            'listing' => [
                'id' => (string) $product->id,
                'url' => $card['url'] ?? ($slug ? '/catalog/product/'.$slug : null),
                'metaTitle' => $t?->meta_title ?? null,
                'metaDescription' => $t?->meta_description ?? null,
                'metaKeywords' => $t?->meta_keywords ?? null,
                'new' => (bool) ($product->is_new ?? false),
                'featured' => (bool) ($product->is_best_seller ?? false),
                'status' => $product->is_enabled ? 1 : 0,
            ],
            'images' => [
                'edges' => $imageEdges,
                'totalCount' => count($imageEdges),
            ],
            'variantAxisOptions' => $superOpts,
            'combinations' => $combinations,
            'isInWishlist' => GraphqlWishlist::isInWishlist((int) $product->id),
            'isInCompare' => in_array((int) $product->id, self::compareIdsFromSession(), true) ? '1' : '0',
            'bookingType' => (function () use ($product) {
                if ($product->isRental()) {
                    return 'rental';
                }
                if ($product->isService()) {
                    $product->loadMissing('serviceSetting');

                    return ((string) ($product->serviceSetting?->service_type ?? 'appointment') === 'appointment')
                        ? 'appointment'
                        : 'default';
                }

                return null;
            })(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function cartPayloadPublic(?CartService $carts = null): array
    {
        return self::cartPayload($carts);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function estimateShippingMethods(array $args): array
    {
        $carts = app(CartService::class);
        $cart = $carts->current(auth('customer')->user());
        $country = strtoupper(trim((string) ($args['country'] ?? '')));
        if (strlen($country) !== 2) {
            throw new UserError('Country must be a 2-letter ISO code.');
        }

        return ShippingMethod::query()
            ->where('is_enabled', true)
            ->whereNull('seller_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(function (ShippingMethod $method) use ($carts, $cart, $country) {
                $price = $carts->quoteShipping($cart, $method, $country);

                return [
                    'id' => (string) $method->id,
                    'code' => $method->code ?? $method->type,
                    'title' => $method->name ?: ('Method #'.$method->id),
                    'description' => $method->description,
                    'price' => $price,
                    'basePrice' => $price,
                    'is_pickup' => $method->type === 'local_pickup',
                ];
            })
            ->values()
            ->all();
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    protected static function productVideosConnection(int $productId, array $args): array
    {
        $empty = [
            'edges' => [],
            'pageInfo' => [
                'hasNextPage' => false,
                'hasPreviousPage' => false,
                'startCursor' => null,
                'endCursor' => null,
            ],
            'totalCount' => 0,
        ];
        if ($productId < 1) {
            return $empty;
        }
        $product = Product::query()->find($productId);
        if (! $product) {
            return $empty;
        }

        $urls = [];
        $raw = trim((string) ($product->video_url ?? ''));
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        $urls[] = trim($item);
                    } elseif (is_array($item) && isset($item['url']) && is_string($item['url'])) {
                        $urls[] = trim($item['url']);
                    }
                }
            } else {
                $urls[] = $raw;
            }
        }
        $urls = array_values(array_unique(array_filter($urls)));
        $total = count($urls);
        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 30)));
        $after = 0;
        $afterCursor = trim((string) ($args['after'] ?? ''));
        if ($afterCursor !== '') {
            $decoded = base64_decode($afterCursor, true);
            if (is_string($decoded) && preg_match('/^vid:(\d+)$/', $decoded, $m)) {
                $after = (int) $m[1];
            }
        }
        $slice = array_slice($urls, $after, $first);
        $edges = [];
        foreach ($slice as $i => $url) {
            $idx = $after + $i + 1;
            $edges[] = [
                'cursor' => base64_encode('vid:'.$idx),
                'node' => [
                    'id' => (string) $idx,

                    'type' => str_contains($url, 'youtube') || str_contains($url, 'youtu.be') ? 'youtube' : 'file',
                    'path' => $url,
                    'url' => $url,
                    'position' => $idx,
                ],
            ];
        }

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => ($after + $first) < $total,
                'hasPreviousPage' => $after > 0,
                'startCursor' => $edges[0]['cursor'] ?? null,
                'endCursor' => $edges !== [] ? $edges[array_key_last($edges)]['cursor'] : null,
            ],
            'totalCount' => $total,
        ];
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    protected static function categoryChildrenConnection(int $parentId, array $args): array
    {
        $empty = [
            'edges' => [],
            'pageInfo' => [
                'hasNextPage' => false,
                'hasPreviousPage' => false,
                'startCursor' => null,
                'endCursor' => null,
            ],
            'totalCount' => 0,
        ];
        if ($parentId < 1) {
            return $empty;
        }

        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 30)));
        $afterId = 0;
        $after = trim((string) ($args['after'] ?? ''));
        if ($after !== '') {
            $decoded = base64_decode($after, true);
            if (is_string($decoded) && preg_match('/^chcat:(\d+)$/', $decoded, $m)) {
                $afterId = (int) $m[1];
            }
        }

        $q = Category::query()
            ->where('is_enabled', true)
            ->where('parent_id', $parentId)
            ->orderBy('position')
            ->orderBy('id');
        $total = (clone $q)->count();
        if ($afterId > 0) {
            $q->where('id', '>', $afterId);
        }
        $rows = $q->with('translations')->limit($first + 1)->get();
        $hasNext = $rows->count() > $first;
        $slice = $rows->take($first);
        $locale = app()->getLocale();
        $edges = $slice->map(fn (Category $c) => [
            'cursor' => base64_encode('chcat:'.$c->id),
            'node' => self::mapCategory($c, $locale, false),
        ])->values()->all();
        $end = $slice->last();

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => $hasNext,
                'hasPreviousPage' => $afterId > 0,
                'startCursor' => $edges[0]['cursor'] ?? null,
                'endCursor' => $end ? base64_encode('chcat:'.$end->id) : null,
            ],
            'totalCount' => $total,
        ];
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    protected static function productCategoriesConnection(int $productId, array $args): array
    {
        $empty = [
            'edges' => [],
            'pageInfo' => [
                'hasNextPage' => false,
                'hasPreviousPage' => false,
                'startCursor' => null,
                'endCursor' => null,
            ],
            'totalCount' => 0,
        ];
        if ($productId < 1) {
            return $empty;
        }
        $product = Product::query()->find($productId);
        if (! $product) {
            return $empty;
        }

        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 30)));
        $afterId = 0;
        $after = trim((string) ($args['after'] ?? ''));
        if ($after !== '') {
            $decoded = base64_decode($after, true);
            if (is_string($decoded) && preg_match('/^cat:(\d+)$/', $decoded, $m)) {
                $afterId = (int) $m[1];
            }
        }

        $q = $product->categories()->where('categories.is_enabled', true)->orderBy('categories.id');
        $total = (clone $q)->count();
        if ($afterId > 0) {
            $q->where('categories.id', '>', $afterId);
        }
        $rows = $q->with('translations')->limit($first + 1)->get();
        $hasNext = $rows->count() > $first;
        $slice = $rows->take($first);
        $locale = app()->getLocale();
        $edges = $slice->map(fn (Category $c) => [
            'cursor' => base64_encode('cat:'.$c->id),
            'node' => self::mapCategory($c, $locale, false),
        ])->values()->all();
        $end = $slice->last();

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => $hasNext,
                'hasPreviousPage' => $afterId > 0,
                'startCursor' => $edges[0]['cursor'] ?? null,
                'endCursor' => $end ? base64_encode('cat:'.$end->id) : null,
            ],
            'totalCount' => $total,
        ];
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function productRelationConnection(int $productId, string $type, array $args): array
    {
        $empty = [
            'edges' => [],
            'nodes' => [],
            'items' => [],
            'pageInfo' => [
                'hasNextPage' => false,
                'hasPreviousPage' => false,
                'startCursor' => null,
                'endCursor' => null,
            ],
            'total' => 0,
            'totalCount' => 0,
            'totalItems' => 0,
        ];
        if ($productId < 1) {
            return $empty;
        }

        $product = Product::query()->find($productId);
        if (! $product) {
            return $empty;
        }

        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 30)));
        $afterId = 0;
        $after = trim((string) ($args['after'] ?? ''));
        if ($after !== '') {
            $decoded = base64_decode($after, true);
            if (is_string($decoded) && preg_match('/^pr:(\d+)$/', $decoded, $m)) {
                $afterId = (int) $m[1];
            }
        }

        $q = $product->relationsOfType($type)->where('products.is_enabled', true)->orderBy('products.id');
        $total = (clone $q)->count();
        if ($afterId > 0) {
            $q->where('products.id', '>', $afterId);
        }
        $rows = $q->with(['translations', 'images'])->limit($first + 1)->get();
        $hasNext = $rows->count() > $first;
        $slice = $rows->take($first);
        $locale = app()->getLocale();
        $nodes = $slice->map(fn (Product $p) => self::mapProduct($p, $locale))->values()->all();
        $edges = $slice->map(fn (Product $p) => [
            'cursor' => base64_encode('pr:'.$p->id),
            'node' => self::mapProduct($p, $locale),
        ])->values()->all();
        $end = $slice->last();

        return [
            'edges' => $edges,
            'nodes' => $nodes,
            'items' => $nodes,
            'pageInfo' => [
                'hasNextPage' => $hasNext,
                'hasPreviousPage' => $afterId > 0,
                'startCursor' => $edges[0]['cursor'] ?? null,
                'endCursor' => $end ? base64_encode('pr:'.$end->id) : null,
            ],
            'total' => $total,
            'totalCount' => $total,
            'totalItems' => $total,
        ];
    }

    protected static function productTypeKey(string $type): string
    {
        return match ($type) {
            'combination', 'combinations', 'configurable' => 'configurable',
            'group', 'grouped' => 'grouped',
            'bundle' => 'bundle',
            'virtual' => 'virtual',
            'downloadable' => 'downloadable',
            'booking', 'service', 'rental' => 'booking',
            default => $type !== '' ? $type : 'simple',
        };
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    protected static function configurableJson(Product $product): array
    {
        if (! $product->isCombination()) {
            return [null, null];
        }

        try {
            $combo = app(\App\Domain\Catalog\Services\CombinationService::class)->presentStorefront($product);
        } catch (Throwable) {
            return [null, null];
        }

        $axes = $combo['axes'] ?? [];
        if ($axes === []) {
            return [null, null];
        }

        $super = collect($axes)->map(fn (array $axis) => [
            'code' => (string) ($axis['code'] ?? ''),
            'label' => (string) ($axis['name'] ?? $axis['code'] ?? ''),
            'options' => collect($axis['values'] ?? [])->map(fn (array $v) => [
                'id' => (int) ($v['id'] ?? 0),
                'label' => (string) ($v['value'] ?? $v['code'] ?? ''),
            ])->values()->all(),
        ])->values()->all();

        $codeByAttrId = collect($axes)
            ->mapWithKeys(fn (array $axis) => [(int) ($axis['attribute_id'] ?? 0) => (string) ($axis['code'] ?? '')])
            ->all();

        $combinations = [];
        $product->loadMissing(['variants.attributeValues.attribute']);
        foreach ($product->variants as $variant) {
            $vid = (string) $variant->id;
            $map = [];
            foreach ($variant->attributeValues as $value) {
                $code = (string) ($value->attribute?->code ?? $codeByAttrId[(int) $value->attribute_id] ?? '');
                if ($code !== '') {
                    $map[$code] = (int) $value->id;
                }
            }
            $combinations[$vid] = $map;
        }

        return [
            json_encode($super, JSON_UNESCAPED_SLASHES) ?: null,
            $combinations !== [] ? (json_encode($combinations, JSON_UNESCAPED_SLASHES) ?: null) : null,
        ];
    }

    protected static function moneyAmount(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (is_object($value) && method_exists($value, 'amount')) {
            return (float) $value->amount();
        }
        if (is_array($value) && isset($value['amount'])) {
            return (float) $value['amount'];
        }

        return null;
    }

    /** @return list<int> */
    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, success: bool, message: string}
     */
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function cartAddMutation(array $args): array
    {
        $carts = app(CartService::class);
        $customer = auth('customer')->user();
        $cart = $carts->current($customer);
        $product = Product::query()->enabled()->findOrFail((int) $args['productId']);
        $qty = (float) ($args['quantity'] ?? $args['qty'] ?? 1);
        if ($qty <= 0) {
            throw new UserError('Quantity must be greater than zero.');
        }
        $bookingDate = isset($args['bookingDate']) ? trim((string) $args['bookingDate']) : null;
        $bookingEndDate = isset($args['bookingEndDate']) ? trim((string) $args['bookingEndDate']) : null;
        try {
            $carts->add(
                $cart,
                $product,
                $qty,
                [],
                [],
                null,
                [],
                isset($args['combinationId']) ? (int) $args['combinationId'] : null,
                $bookingDate !== '' ? $bookingDate : null,
                null,
                null,
                null,
                $bookingEndDate !== '' ? $bookingEndDate : null,
                isset($args['rentalDuration']) ? (int) $args['rentalDuration'] : null,
            );
        } catch (ValidationException $e) {
            throw new UserError(collect($e->errors())->flatten()->first() ?: $e->getMessage());
        } catch (Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return self::cartPayload($carts);
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createOrderFromInput(array $args): array
    {
        $input = is_array($args['input'] ?? null) ? $args['input'] : [];
        if (! empty($input['shippingMethod']) || ! empty($input['shippingMethodId'])) {
            GraphqlCheckoutSteps::saveShippingMethod([
                'code' => $input['shippingMethod'] ?? null,
                'method' => $input['shippingMethod'] ?? null,
                'shippingMethodId' => $input['shippingMethodId'] ?? null,
            ]);
        }
        if (! empty($input['paymentMethod']) || ! empty($input['paymentMethodId'])) {
            GraphqlCheckoutSteps::savePaymentMethod([
                'code' => $input['paymentMethod'] ?? null,
                'method' => $input['paymentMethod'] ?? null,
                'paymentMethodId' => $input['paymentMethodId'] ?? null,
            ]);
        }

        return GraphqlCheckoutSteps::placeOrder();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listCustomerBookings(array $args): array
    {
        $customer = auth('customer')->user();
        if (! $customer) {
            throw new UserError('Authentication required.');
        }
        if (! DbSchema::hasColumn('order_items', 'booking_date')) {
            return [];
        }
        $limit = max(1, min(100, (int) ($args['limit'] ?? 20)));
        $q = OrderItem::query()
            ->with('order:id,number,status,customer_id')
            ->whereNotNull('booking_date')
            ->whereHas('order', fn ($oq) => $oq->where('customer_id', $customer->id))
            ->orderByDesc('booking_date');
        $from = trim((string) ($args['date_from'] ?? ''));
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $q->whereDate('booking_date', '>=', $from);
        }
        $to = trim((string) ($args['date_to'] ?? ''));
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $q->whereDate('booking_date', '<=', $to);
        }

        return $q->limit($limit)->get()->map(fn (OrderItem $item) => [
            'id' => (string) $item->id,
            'order_id' => (string) $item->order_id,
            'order_number' => (string) ($item->order?->number ?? ''),
            'product_id' => $item->product_id ? (string) $item->product_id : null,
            'sku' => (string) $item->sku,
            'name' => (string) $item->name,
            'qty' => (float) $item->qty,
            'booking_date' => optional($item->booking_date)?->toDateString(),
            'booking_end_date' => optional($item->booking_end_date)?->toDateString(),
            'status' => (string) ($item->order?->status ?? ''),
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function cartUpdateQtyMutation(array $args): array
    {
        $itemId = (int) ($args['cartItemId'] ?? $args['itemId'] ?? 0);
        $qty = (float) ($args['quantity'] ?? $args['qty'] ?? 0);
        if ($itemId < 1) {
            throw new UserError('Cart item id is required.');
        }
        if ($qty <= 0) {
            throw new UserError('Quantity must be greater than zero.');
        }
        $carts = app(CartService::class);
        $cart = $carts->current(auth('customer')->user());
        $carts->updateQty($cart, $itemId, $qty);

        return self::cartPayload($carts);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function cartRemoveItemMutation(array $args): array
    {
        $itemId = (int) ($args['cartItemId'] ?? $args['itemId'] ?? 0);
        if ($itemId < 1) {
            throw new UserError('Cart item id is required.');
        }
        $carts = app(CartService::class);
        $cart = $carts->current(auth('customer')->user());
        $cart->items()->where('id', $itemId)->delete();

        return self::cartPayload($carts);
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function cartRemoveItemsMutation(array $args): array
    {
        $raw = $args['itemIds'] ?? $args['cartItemIds'] ?? [];
        if (! is_array($raw) || $raw === []) {
            throw new UserError('itemIds is required and must be a non-empty array.');
        }
        $ids = collect($raw)->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->values()->all();
        if ($ids === []) {
            throw new UserError('itemIds is required and must be a non-empty array.');
        }
        $carts = app(CartService::class);
        $cart = $carts->current(auth('customer')->user());
        $cart->items()->whereIn('id', $ids)->delete();

        return self::cartPayload($carts);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function applyCouponMutation(array $args): array
    {
        $code = trim((string) ($args['couponCode'] ?? $args['code'] ?? ''));
        if ($code === '') {
            throw new UserError('Coupon code is required.');
        }
        $carts = app(CartService::class);
        $cart = $carts->current(auth('customer')->user());
        try {
            $carts->applyCoupon($cart, $code);
        } catch (Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return self::cartPayload($carts);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function removeCouponMutation(): array
    {
        $carts = app(CartService::class);
        $cart = $carts->current(auth('customer')->user());
        $cart->update(['discount_rule_id' => null, 'coupon_id' => null]);

        return self::cartPayload($carts);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function mergeCartMutation(array $args): array
    {
        $customer = auth('customer')->user();
        if (! $customer instanceof Customer) {
            throw new UserError('Authentication required.');
        }
        $token = trim((string) ($args['cartToken'] ?? ''));
        if ($token !== '') {
            if (strlen($token) > 80 || ! preg_match('/^[A-Za-z0-9._-]+$/', $token)) {
                throw new UserError('Invalid cart token.');
            }
            \Illuminate\Support\Facades\Session::put('wento_cart', $token);
            request()->headers->set('X-Cart-Token', $token);
        }
        $carts = app(CartService::class);
        $carts->current($customer);

        return self::cartPayload($carts);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, message: string}
     */
    protected static function newsletterSubscribeMutation(array $args): array
    {
        $email = strtolower(trim((string) ($args['email'] ?? '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new UserError('A valid email is required.');
        }
        $existing = NewsletterSubscriber::query()->where('email', $email)->first();
        if ($existing) {
            if ($existing->is_subscribed === false) {
                $existing->forceFill(['is_subscribed' => true])->save();

                return ['ok' => true, 'success' => true, 'message' => 'You are subscribed.'];
            }

            return ['ok' => true, 'success' => true, 'message' => 'Already subscribed.'];
        }
        NewsletterSubscriber::query()->create([
            'email' => $email,
            'is_subscribed' => true,
            'ip' => mb_substr((string) request()->ip(), 0, 45),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 255),
        ]);

        return ['ok' => true, 'success' => true, 'message' => 'You are subscribed.'];
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, success: bool, message: string}
     */
    protected static function newsletterUnsubscribeMutation(array $args): array
    {
        $email = strtolower(trim((string) ($args['email'] ?? '')));
        if ($email === '') {
            $customer = auth('customer')->user();
            $email = $customer ? strtolower(trim((string) $customer->email)) : '';
        }
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new UserError('A valid email is required.');
        }
        $row = NewsletterSubscriber::query()->where('email', $email)->first();
        if (! $row) {
            return ['ok' => true, 'success' => true, 'message' => 'Email is not subscribed.'];
        }
        if ($row->is_subscribed === false) {
            return ['ok' => true, 'success' => true, 'message' => 'Already unsubscribed.'];
        }
        $row->forceFill(['is_subscribed' => false])->save();

        return ['ok' => true, 'success' => true, 'message' => 'You are unsubscribed.'];
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array{productReview: array<string, mixed>, clientMutationId: ?string}
     */
    protected static function createProductReviewMutation(array $args): array
    {
        $rating = (int) $args['rating'];
        if ($rating < 1 || $rating > 5) {
            throw new UserError('Rating must be between 1 and 5.');
        }
        $title = trim((string) $args['title']);
        $comment = trim((string) $args['comment']);
        $name = trim((string) $args['name']);
        if ($title === '') {
            throw new UserError('Title is required.');
        }
        if ($comment === '') {
            throw new UserError('Comment is required.');
        }
        if ($name === '') {
            throw new UserError('Name is required.');
        }

        $customer = auth('customer')->user();
        if (! $customer instanceof Customer) {
            $customer = null;
        }

        $photos = [];
        $video = null;
        $attachmentsJson = trim((string) ($args['attachments'] ?? ''));
        if ($attachmentsJson !== '') {
            [$photos, $video] = self::decodeReviewDataUriAttachments($attachmentsJson);
        }

        $input = [
            'product_id' => (int) $args['productId'],
            'rating' => $rating,
            'title' => $title,
            'body' => $comment,
        ];
        if ($customer === null) {
            $email = strtolower(trim((string) ($args['email'] ?? '')));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new UserError('A valid email is required for guest reviews.');
            }
            $input['guest_name'] = $name;
            $input['guest_email'] = $email;
        }

        try {
            $row = app(ReviewService::class)->submit($customer, $input, $photos !== [] ? $photos : null, $video);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();
            throw new UserError($messages[0] ?? 'Review could not be saved.');
        } finally {
            foreach ($photos as $file) {
                if ($file instanceof UploadedFile && is_string($file->getPathname()) && is_file($file->getPathname())) {
                    @unlink($file->getPathname());
                }
            }
            if ($video instanceof UploadedFile && is_string($video->getPathname()) && is_file($video->getPathname())) {
                @unlink($video->getPathname());
            }
        }

        $displayName = $customer?->displayName() ?: (string) ($row->guest_name ?: $name);
        $attachmentPayload = self::reviewAttachmentsJson($row);

        return [
            'productReview' => [
                'id' => (string) $row->id,

                'name' => $displayName,
                'title' => (string) ($row->title ?? $title),
                'rating' => (int) $row->rating,
                'comment' => (string) ($row->body ?? $comment),
                'status' => (string) ($row->status ?: Review::STATUS_PENDING),
                'attachments' => $attachmentPayload,
                'createdAt' => optional($row->created_at)?->toIso8601String(),
                'updatedAt' => optional($row->updated_at)?->toIso8601String(),
            ],
            'clientMutationId' => isset($args['clientMutationId']) ? (string) $args['clientMutationId'] : null,
        ];
    }

    /**
     * @return array{0: list<UploadedFile>, 1: ?UploadedFile}
     */
    protected static function decodeReviewDataUriAttachments(string $attachmentsJson): array
    {
        $decoded = json_decode($attachmentsJson, true);
        if (! is_array($decoded)) {
            throw new UserError('Attachments must be a JSON array of data URIs.');
        }
        $photos = [];
        $video = null;
        foreach ($decoded as $uri) {
            if (! is_string($uri) || $uri === '') {
                throw new UserError('Invalid attachment data URI.');
            }
            if (! preg_match('#^data:(image|video)/([a-zA-Z0-9.+-]+);base64,(.+)$#s', $uri, $m)) {
                throw new UserError('Invalid attachment data URI.');
            }
            $kind = $m[1];
            $subtype = strtolower($m[2]);
            $binary = base64_decode($m[3], true);
            if ($binary === false) {
                throw new UserError('Invalid attachment data URI.');
            }
            if (strlen($binary) > 5 * 1024 * 1024) {
                throw new UserError('Each attachment must be 5 MB or smaller.');
            }
            $ext = match ($subtype) {
                'jpeg', 'jpg' => 'jpg',
                'png' => 'png',
                'webp' => 'webp',
                'gif' => 'gif',
                'mp4' => 'mp4',
                'webm' => 'webm',
                default => preg_replace('/[^a-z0-9]/', '', $subtype) ?: 'bin',
            };
            $tmp = tempnam(sys_get_temp_dir(), 'wento_review_');
            if ($tmp === false) {
                throw new UserError('Could not store attachment.');
            }
            $path = $tmp.'.'.$ext;
            rename($tmp, $path);
            file_put_contents($path, $binary);
            $upload = new UploadedFile($path, 'review.'.$ext, $kind.'/'.$subtype, null, true);
            if ($kind === 'video') {
                if ($video !== null) {
                    @unlink($path);
                    throw new UserError('Only one video attachment is allowed.');
                }
                $video = $upload;
            } else {
                $photos[] = $upload;
            }
        }

        return [$photos, $video];
    }

    protected static function reviewAttachmentsJson(Review $row): ?string
    {
        $items = [];
        foreach ($row->imageUrls() as $url) {
            $items[] = ['type' => 'image', 'url' => $url];
        }
        $videoUrl = $row->videoUrl();
        if (is_string($videoUrl) && $videoUrl !== '') {
            $items[] = ['type' => 'video', 'url' => $videoUrl];
        }

        return $items === [] ? null : json_encode($items, JSON_UNESCAPED_SLASHES);
    }

    protected static function createContactUsMutation(array $args): array
    {
        if (! CommunityFeatures::contactForm()) {
            throw new UserError('Contact form is disabled.');
        }
        $name = trim((string) $args['name']);
        $email = strtolower(trim((string) $args['email']));
        $message = trim((string) $args['message']);
        if ($name === '') {
            throw new UserError('Name is required.');
        }
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new UserError('A valid email is required.');
        }
        if ($message === '') {
            throw new UserError('Message is required.');
        }
        $phone = trim((string) ($args['contact'] ?? $args['phone'] ?? ''));
        if ($phone !== '') {
            $message = $message."\n\nPhone: ".$phone;
        }
        $orderNumber = trim((string) ($args['orderNumber'] ?? ''));
        $customer = auth('customer')->user();
        if (! $customer instanceof Customer) {
            $customer = null;
        }
        app(SupportService::class)->openFromContact(
            $name,
            $email,
            $message,
            $orderNumber !== '' ? $orderNumber : null,
            $customer,
        );

        return [
            'ok' => true,
            'success' => true,
            'message' => 'Message sent.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{success: bool, message: string, orderId: string, itemsAddedCount: int}
     */
    protected static function createReorderOrderMutation(array $args): array
    {
        $customer = self::requireCustomer();
        $orderId = (int) ($args['orderId'] ?? 0);
        $orderNumber = trim((string) ($args['orderNumber'] ?? ''));
        $q = Order::query()->where('customer_id', $customer->id);
        if ($orderId > 0) {
            $q->whereKey($orderId);
        } elseif ($orderNumber !== '') {
            $q->where('number', $orderNumber);
        } else {
            throw new UserError('Order not found.');
        }
        $order = $q->with('items.product')->first();
        if (! $order) {
            throw new UserError('Order not found (ID: '.($orderId ?: $orderNumber).')');
        }
        $carts = app(CartService::class);
        $cart = $carts->current($customer);
        $added = 0;
        foreach ($order->items as $item) {
            $product = $item->product;
            if (! $product || ! $product->is_enabled) {
                continue;
            }
            try {
                $carts->add(
                    $cart,
                    $product,
                    max(1, (int) $item->qty),
                    is_array($item->customization) ? $item->customization : [],
                    [],
                    $item->subscription_plan_id ? (int) $item->subscription_plan_id : null,
                    is_array($item->gift_card_data) ? $item->gift_card_data : [],
                    $item->combination_id ? (int) $item->combination_id : null,
                    $item->booking_date?->toDateString(),
                    null,
                    null,
                    is_array($item->bundle_selection) ? $item->bundle_selection : null,
                    $item->booking_end_date?->toDateString(),
                    $item->rental_duration ? (int) $item->rental_duration : null,
                );
                $added++;
            } catch (Throwable) {
                continue;
            }
        }

        return [
            'success' => $added > 0,
            'message' => $added > 0
                ? $added.' item(s) added to cart.'
                : 'No items were added to cart. All items are out of stock or unavailable.',
            'orderId' => (string) $order->id,
            'itemsAddedCount' => $added,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected static function mapCustomerDownloadableProduct(array $row): array
    {
        $available = (bool) ($row['available'] ?? false);
        $expired = (bool) ($row['expired'] ?? false);
        $status = $available ? 'available' : ($expired ? 'expired' : 'pending');
        $remaining = $row['remaining'] ?? null;

        return [
            'id' => (string) ($row['id'] ?? ''),

            'productName' => $row['product']['name'] ?? null,
            'name' => $row['name'] ?? null,
            'fileName' => $row['filename'] ?? ($row['original_filename'] ?? null),
            'type' => 'file',
            'downloadBought' => (int) ($row['max_uses'] ?? 0),
            'downloadUsed' => (int) ($row['uses'] ?? 0),
            'downloadCanceled' => 0,
            'status' => $status,
            'downloadUrl' => $row['download_url'] ?? null,
            'remainingDownloads' => $remaining === null ? null : (int) $remaining,
            'order_number' => $row['order']['number'] ?? null,
            'created_at' => $row['order']['created_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function listCustomerDownloadableProducts(array $args): array
    {
        $customer = self::requireCustomer();
        $limit = max(1, min(100, (int) ($args['limit'] ?? 50)));
        $status = strtolower(trim((string) ($args['status'] ?? '')));
        $rows = app(OrderDownloadService::class)->listForCustomer($customer);
        $mapped = array_map(fn (array $row) => self::mapCustomerDownloadableProduct($row), $rows);
        if (in_array($status, ['available', 'expired', 'pending'], true)) {
            $mapped = array_values(array_filter($mapped, fn (array $row) => ($row['status'] ?? '') === $status));
        }

        return array_slice($mapped, 0, $limit);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function findCustomerDownloadableProduct(int $id): ?array
    {
        $customer = self::requireCustomer();
        $row = OrderDownload::query()
            ->with(['attachment.translations', 'order', 'orderItem', 'product.translations'])
            ->whereKey($id)
            ->whereIn('order_id', $customer->orders()->select('id'))
            ->first();
        if (! $row) {
            return null;
        }
        $presented = app(OrderDownloadService::class)->present($row, app()->getLocale(), withContext: true);

        return self::mapCustomerDownloadableProduct($presented);
    }

    protected static function assertGdprEnabled(): void
    {
        if (! GdprSettings::enabled()) {
            throw new UserError('GDPR data requests are disabled. Please enable GDPR from the admin configuration.');
        }
        GdprSchema::ensure();
    }

    protected static function requireCustomer(): Customer
    {
        $customer = auth('customer')->user();
        if (! $customer instanceof Customer) {
            throw new UserError('Authentication required.');
        }

        return $customer;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapGdprRequest(GdprRequest $row, ?string $successMessage = null): array
    {
        $customer = $row->relationLoaded('customer') ? $row->customer : $row->customer()->first();
        $created = optional($row->created_at)?->toIso8601String();

        return [
            'id' => (string) $row->id,

            'reference' => $row->reference,
            'type' => (string) $row->type,
            'status' => (string) $row->status,
            'message' => $row->message,
            'email' => $customer?->email,
            'successMessage' => $successMessage,
            'revokedAt' => ((string) $row->status) === 'revoked'
                ? optional($row->updated_at)?->toIso8601String()
                : null,
            'createdAt' => $created,
            'updatedAt' => optional($row->updated_at)?->toIso8601String(),
            'created_at' => $created,
            'can_revoke' => in_array((string) $row->status, ['pending', 'processing'], true),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function listGdprRequests(): array
    {
        self::assertGdprEnabled();
        $customer = self::requireCustomer();

        return GdprRequest::query()
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (GdprRequest $row) => self::mapGdprRequest($row))
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function findGdprRequest(int $id): ?array
    {
        self::assertGdprEnabled();
        $customer = self::requireCustomer();
        $row = GdprRequest::query()
            ->where('customer_id', $customer->id)
            ->whereKey($id)
            ->first();

        return $row ? self::mapGdprRequest($row) : null;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function createGdprRequestMutation(array $args): array
    {
        self::assertGdprEnabled();
        $customer = self::requireCustomer();
        $type = trim((string) ($args['type'] ?? ''));
        $message = trim((string) ($args['message'] ?? ''));
        if ($type === '') {
            throw new UserError('The type field is required.');
        }
        if ($message === '') {
            throw new UserError('The message field is required.');
        }
        try {
            $row = app(GdprService::class)->create($customer, $type, $message);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();
            throw new UserError($messages[0] ?? 'GDPR request could not be created.');
        } catch (Throwable $e) {
            throw new UserError($e->getMessage() !== '' ? $e->getMessage() : 'GDPR request could not be created.');
        }

        return self::mapGdprRequest($row->loadMissing('customer'), 'Your GDPR request has been submitted.');
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function revokeGdprRequestMutation(array $args): array
    {
        self::assertGdprEnabled();
        $customer = self::requireCustomer();
        $row = GdprRequest::query()
            ->where('customer_id', $customer->id)
            ->whereKey((int) $args['id'])
            ->first();
        if (! $row) {
            throw new UserError('GDPR request not found.');
        }
        try {
            app(GdprService::class)->revoke($customer, $row);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();
            throw new UserError($messages[0] ?? 'GDPR request could not be revoked.');
        }
        $row->refresh();

        return self::mapGdprRequest($row->loadMissing('customer'), 'Your GDPR request has been revoked.');
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, message: string}
     */
    protected static function deleteGdprRequestMutation(array $args): array
    {
        self::assertGdprEnabled();
        $customer = self::requireCustomer();
        $row = GdprRequest::query()
            ->where('customer_id', $customer->id)
            ->whereKey((int) $args['id'])
            ->first();
        if (! $row) {
            throw new UserError('GDPR request not found.');
        }
        app(GdprService::class)->deleteRequest($customer, $row);

        return ['ok' => true, 'message' => 'GDPR request deleted.'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function resolveWishlist(): array
    {
        $customer = auth('customer')->user();
        if (! $customer) {
            throw new UserError('Authentication required.');
        }
        if (! SearchCatalogSettings::wishlistEnabled()) {
            return [];
        }
        $locale = app()->getLocale();
        $ids = $customer->wishlist()->pluck('product_id');

        return Product::query()->whereIn('id', $ids)->with(['translations', 'images'])->get()
            ->map(fn (Product $p) => self::mapProduct($p, $locale))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function resolveCompareList(): array
    {
        if (! ProductSettings::compareEnabled()) {
            return [];
        }
        $ids = self::compareIdsFromSession();
        if ($ids === []) {
            return [];
        }
        $locale = app()->getLocale();
        $order = array_flip($ids);

        return Product::query()->enabled()->whereIn('id', $ids)
            ->with(['translations', 'images'])
            ->get()
            ->sortBy(fn (Product $p) => $order[$p->id] ?? PHP_INT_MAX)
            ->values()
            ->map(fn (Product $p) => self::mapProduct($p, $locale))
            ->all();
    }

    protected static function requireWishlistCustomer(): \App\Domain\Customer\Models\Customer
    {
        $customer = auth('customer')->user();
        if (! $customer instanceof \App\Domain\Customer\Models\Customer) {
            throw new UserError('Authentication required.');
        }
        if (! SearchCatalogSettings::wishlistEnabled()) {
            throw new UserError('Wishlist is disabled.');
        }

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{wishlisted: bool, product_id: string}
     */
    protected static function toggleWishlistProduct(array $args): array
    {
        $customer = self::requireWishlistCustomer();
        $productId = (int) $args['productId'];
        Product::query()->findOrFail($productId);
        if ($customer->wishlist()->where('product_id', $productId)->exists()) {
            $customer->wishlist()->detach($productId);
            $on = false;
        } else {
            $customer->wishlist()->attach($productId);
            $on = true;
        }

        return [
            'wishlisted' => $on,
            'product_id' => (string) $productId,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function moveWishlistProductToCart(array $args): array
    {
        $customer = self::requireWishlistCustomer();
        $productId = (int) $args['productId'];
        if (! $customer->wishlist()->where('product_id', $productId)->exists()) {
            throw new UserError('Product is not in the wishlist.');
        }
        $product = Product::query()->enabled()->findOrFail($productId);
        $carts = app(CartService::class);
        $cart = $carts->current($customer);
        $carts->add($cart, $product, (float) ($args['qty'] ?? 1));
        $customer->wishlist()->detach($productId);

        return self::cartPayload($carts);
    }

    protected static function compareIdsFromSession(): array
    {
        $raw = session()->get('wento_compare_ids', []);
        $customer = auth('customer')->user();
        if ($customer instanceof \App\Domain\Customer\Models\Customer) {
            $profile = is_array($customer->profile) ? $customer->profile : [];
            if (is_array($profile['compare_ids'] ?? null) && $profile['compare_ids'] !== []) {
                $raw = $profile['compare_ids'];
            }
        }
        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /** @param  list<int>  $ids */
    protected static function storeCompareIds(array $ids): void
    {
        $ids = array_values($ids);
        session()->put('wento_compare_ids', $ids);
        $customer = auth('customer')->user();
        if ($customer instanceof \App\Domain\Customer\Models\Customer) {
            $profile = is_array($customer->profile) ? $customer->profile : [];
            $profile['compare_ids'] = $ids;
            $customer->forceFill(['profile' => $profile])->save();
        }
    }
}
