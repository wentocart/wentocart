<?php

namespace App\Domain\Settings;

use App\Domain\Settings\Models\EmailEventSetting;
use App\Domain\Settings\Models\EmailTemplate;

/**
 * Shop email events the admin can switch on or off.
 * New events can be added here later without changing the UI shape.
 */
final class MailEvents
{
    public const CUSTOMER_CREATED = 'customer.created';

    public const CUSTOMER_FORGOT_PASSWORD = 'customer.forgot_password';

    public const ADMIN_FORGOT_PASSWORD = 'admin.forgot_password';

    public const ORDER_CREATED = 'order.created';

    public const ORDER_PAID = 'order.paid';

    public const CART_ABANDONED = 'cart.abandoned';

    public const SUBSCRIPTION_RENEWAL = 'subscription.renewal';

    public const GIFT_CARD_ISSUED = 'gift_card.issued';

    public const STOCK_LOW = 'stock.low';

    public const PRODUCT_REVIEW_SUBMITTED = 'product.review_submitted';

    public const PRODUCT_REVIEW_REPLIED = 'product.review_replied';

    public const SELLER_APPLICATION_SUBMITTED = 'seller.application_submitted';

    public const SELLER_APPROVED = 'seller.approved';

    public const SELLER_REJECTED = 'seller.rejected';

    public const SELLER_SUSPENDED = 'seller.suspended';

    public const SELLER_PRODUCT_SUBMITTED = 'seller.product_submitted';

    public const SELLER_PRODUCT_APPROVED = 'seller.product_approved';

    public const SELLER_PRODUCT_REJECTED = 'seller.product_rejected';

    public const SELLER_ORDER_CREATED = 'seller.order_created';

    public const SELLER_ORDER_CONFIRMED = 'seller.order_confirmed';

    public const SELLER_ORDER_SHIPPED = 'seller.order_shipped';

    public const SELLER_ORDER_DELIVERED = 'seller.order_delivered';

    public const SELLER_RETURN_REQUESTED = 'seller.return_requested';

    public const SELLER_RETURN_APPROVED = 'seller.return_approved';

    public const SELLER_REFUND_PROCESSED = 'seller.refund_processed';

    public const SELLER_PAYOUT_REQUESTED = 'seller.payout_requested';

    public const SELLER_PAYOUT_PAID = 'seller.payout_paid';

    public const SELLER_PAYOUT_FAILED = 'seller.payout_failed';

    /**
     * @return list<array{
     *     code: string,
     *     name: string,
     *     hint: string,
     *     group: string,
     *     recipients: list<array{key: string, label: string, hint: string, required: bool, template: string}>
     * }>
     */
    public static function catalog(): array
    {
        return [
            [
                'code' => self::CUSTOMER_CREATED,
                'name' => 'New customer created',
                'hint' => 'Account opened from the storefront or created by staff.',
                'group' => 'customers',
                'recipients' => [
                    [
                        'key' => 'customer',
                        'label' => 'Welcome email to customer',
                        'hint' => 'Sent to the new account email.',
                        'required' => false,
                        'template' => 'welcome',
                    ],
                    [
                        'key' => 'admin',
                        'label' => 'Notification to admin',
                        'hint' => 'Sent to the store email in Settings.',
                        'required' => false,
                        'template' => 'customer_created_admin',
                    ],
                ],
            ],
            [
                'code' => self::CUSTOMER_FORGOT_PASSWORD,
                'name' => 'Forgot password',
                'hint' => 'Customer asked to recover their storefront login.',
                'group' => 'customers',
                'recipients' => [
                    [
                        'key' => 'customer',
                        'label' => 'Recovery email to customer',
                        'hint' => 'Required. The customer cannot reset a password without this email.',
                        'required' => true,
                        'template' => 'forgot_password',
                    ],
                ],
            ],
            [
                'code' => self::ADMIN_FORGOT_PASSWORD,
                'name' => 'Admin forgot password',
                'hint' => 'Staff asked to recover their admin login.',
                'group' => 'customers',
                'recipients' => [
                    [
                        'key' => 'staff',
                        'label' => 'Recovery email to staff',
                        'hint' => 'Required. Staff cannot reset a password without this email.',
                        'required' => true,
                        'template' => 'admin_forgot_password',
                    ],
                ],
            ],
            [
                'code' => self::ORDER_CREATED,
                'name' => 'New order created',
                'hint' => 'Fired when a customer places an order from the storefront.',
                'group' => 'orders',
                'recipients' => [
                    [
                        'key' => 'customer',
                        'label' => 'Order confirmation to customer',
                        'hint' => 'Storefront checkout only.',
                        'required' => false,
                        'template' => 'order_placed',
                    ],
                    [
                        'key' => 'admin',
                        'label' => 'Order notification to admin',
                        'hint' => 'Storefront checkout only.',
                        'required' => false,
                        'template' => 'order_created_admin',
                    ],
                ],
            ],
            [
                'code' => self::ORDER_PAID,
                'name' => 'Payment received',
                'hint' => 'Fired when an order payment status becomes paid.',
                'group' => 'orders',
                'recipients' => [
                    [
                        'key' => 'customer',
                        'label' => 'Payment confirmation to customer',
                        'hint' => 'Sent to the order email.',
                        'required' => false,
                        'template' => 'order_paid_customer',
                    ],
                    [
                        'key' => 'admin',
                        'label' => 'Payment notification to admin',
                        'hint' => 'Sent to the store email in Settings.',
                        'required' => false,
                        'template' => 'order_paid_admin',
                    ],
                ],
            ],
            [
                'code' => self::CART_ABANDONED,
                'name' => 'Abandoned cart',
                'hint' => 'Reminder when a signed-in customer leaves items in the cart.',
                'group' => 'orders',
                'recipients' => [
                    [
                        'key' => 'customer',
                        'label' => 'Reminder to customer',
                        'hint' => 'Sent to the customer account email.',
                        'required' => false,
                        'template' => 'cart_abandoned',
                    ],
                ],
            ],
            [
                'code' => self::SUBSCRIPTION_RENEWAL,
                'name' => 'Subscription renewal due',
                'hint' => 'Fired when a billed plan is due and no stored card can auto-charge.',
                'group' => 'orders',
                'recipients' => [
                    [
                        'key' => 'customer',
                        'label' => 'Renewal reminder to customer',
                        'hint' => 'Asks the customer to pay the next cycle.',
                        'required' => false,
                        'template' => 'subscription_renewal',
                    ],
                ],
            ],
            [
                'code' => self::GIFT_CARD_ISSUED,
                'name' => 'Gift card issued',
                'hint' => 'Sent when a paid order issues a gift card code.',
                'group' => 'orders',
                'recipients' => [
                    [
                        'key' => 'customer',
                        'label' => 'Gift card to recipient',
                        'hint' => 'Sent to the recipient email, or the buyer if none was given.',
                        'required' => true,
                        'template' => 'gift_card_issued',
                    ],
                ],
            ],
            [
                'code' => self::STOCK_LOW,
                'name' => 'Low stock',
                'hint' => 'Fired hourly when a product marked Notify when low stock drops to its alert level.',
                'group' => 'catalog',
                'recipients' => [
                    [
                        'key' => 'admin',
                        'label' => 'Low-stock notice to admin',
                        'hint' => 'Sent to the store email in Settings. Turned off per product with Notify when low stock.',
                        'required' => false,
                        'template' => 'stock_low',
                    ],
                ],
            ],
            [
                'code' => self::PRODUCT_REVIEW_SUBMITTED,
                'name' => 'Product review submitted',
                'hint' => 'Fired when a customer submits or updates a product review.',
                'group' => 'customers',
                'recipients' => [
                    [
                        'key' => 'admin',
                        'label' => 'New review notice to admin',
                        'hint' => 'Sent to the store email in Settings so staff can moderate.',
                        'required' => false,
                        'template' => 'product_review_submitted_admin',
                    ],
                    [
                        'key' => 'customer',
                        'label' => 'Review received confirmation',
                        'hint' => 'Optional acknowledgement to the reviewer.',
                        'required' => false,
                        'template' => 'product_review_submitted_customer',
                    ],
                ],
            ],
            [
                'code' => self::PRODUCT_REVIEW_REPLIED,
                'name' => 'Shop replied to a review',
                'hint' => 'Fired when staff saves a merchant reply on a product review.',
                'group' => 'customers',
                'recipients' => [
                    [
                        'key' => 'customer',
                        'label' => 'Reply notice to reviewer',
                        'hint' => 'Sent to the customer who wrote the review.',
                        'required' => false,
                        'template' => 'product_review_replied_customer',
                    ],
                ],
            ],
            [
                'code' => self::SELLER_APPLICATION_SUBMITTED,
                'name' => 'Seller application submitted',
                'hint' => 'Fired when a customer submits or resubmits a seller application.',
                'group' => 'marketplace',
                'recipients' => [
                    [
                        'key' => 'admin',
                        'label' => 'Application notification to admin',
                        'hint' => 'Sent to the store email in Settings.',
                        'required' => false,
                        'template' => 'seller_application_submitted_admin',
                    ],
                ],
            ],
            [
                'code' => self::SELLER_APPROVED,
                'name' => 'Seller approved',
                'hint' => 'Fired when admin approves a seller application.',
                'group' => 'marketplace',
                'recipients' => [
                    [
                        'key' => 'seller',
                        'label' => 'Approval email to seller',
                        'hint' => 'Sent to the seller account email.',
                        'required' => false,
                        'template' => 'seller_approved',
                    ],
                ],
            ],
            [
                'code' => self::SELLER_REJECTED,
                'name' => 'Seller rejected',
                'hint' => 'Fired when admin rejects a pending seller application.',
                'group' => 'marketplace',
                'recipients' => [
                    [
                        'key' => 'seller',
                        'label' => 'Rejection email to seller',
                        'hint' => 'Sent to the seller account email.',
                        'required' => false,
                        'template' => 'seller_rejected',
                    ],
                ],
            ],
            [
                'code' => self::SELLER_SUSPENDED,
                'name' => 'Seller suspended',
                'hint' => 'Fired when admin suspends an active seller.',
                'group' => 'marketplace',
                'recipients' => [
                    [
                        'key' => 'seller',
                        'label' => 'Suspension email to seller',
                        'hint' => 'Sent to the seller account email.',
                        'required' => false,
                        'template' => 'seller_suspended',
                    ],
                ],
            ],
            [
                'code' => self::SELLER_PRODUCT_SUBMITTED,
                'name' => 'Seller product submitted',
                'hint' => 'Fired when a seller listing enters pending moderation.',
                'group' => 'marketplace',
                'recipients' => [
                    [
                        'key' => 'admin',
                        'label' => 'Moderation notification to admin',
                        'hint' => 'Sent to the store email in Settings.',
                        'required' => false,
                        'template' => 'seller_product_submitted_admin',
                    ],
                ],
            ],
            [
                'code' => self::SELLER_PRODUCT_APPROVED,
                'name' => 'Seller product approved',
                'hint' => 'Fired when admin approves a pending seller product.',
                'group' => 'marketplace',
                'recipients' => [
                    [
                        'key' => 'seller',
                        'label' => 'Approval email to seller',
                        'hint' => 'Sent to the seller account email.',
                        'required' => false,
                        'template' => 'seller_product_approved',
                    ],
                ],
            ],
            [
                'code' => self::SELLER_PRODUCT_REJECTED,
                'name' => 'Seller product rejected',
                'hint' => 'Fired when admin rejects a pending seller product.',
                'group' => 'marketplace',
                'recipients' => [
                    [
                        'key' => 'seller',
                        'label' => 'Rejection email to seller',
                        'hint' => 'Sent to the seller account email.',
                        'required' => false,
                        'template' => 'seller_product_rejected',
                    ],
                ],
            ],
            [
                'code' => self::SELLER_ORDER_CREATED,
                'name' => 'Seller new order',
                'hint' => 'Fired when a storefront order includes this seller’s products.',
                'group' => 'marketplace',
                'recipients' => [
                    [
                        'key' => 'seller',
                        'label' => 'New order email to seller',
                        'hint' => 'Sent when their slice of an order is created.',
                        'required' => false,
                        'template' => 'seller_order_created',
                    ],
                ],
            ],
            [
                'code' => self::SELLER_ORDER_CONFIRMED,
                'name' => 'Seller order confirmed',
                'hint' => 'Fired when a seller order moves into processing.',
                'group' => 'marketplace',
                'recipients' => [
                    [
                        'key' => 'seller',
                        'label' => 'Confirmation email to seller',
                        'hint' => 'Sent when the seller order is confirmed for fulfillment.',
                        'required' => false,
                        'template' => 'seller_order_confirmed',
                    ],
                ],
            ],
            [
                'code' => self::SELLER_ORDER_SHIPPED,
                'name' => 'Seller order shipped',
                'hint' => 'Fired when a seller marks their shipment as shipped.',
                'group' => 'marketplace',
                'recipients' => [
                    [
                        'key' => 'seller',
                        'label' => 'Shipped email to seller',
                        'hint' => 'Optional seller confirmation.',
                        'required' => false,
                        'template' => 'seller_order_shipped',
                    ],
                    [
                        'key' => 'customer',
                        'label' => 'Shipped email to customer',
                        'hint' => 'Sent to the order email with tracking when available.',
                        'required' => false,
                        'template' => 'seller_order_shipped_customer',
                    ],
                ],
            ],
            [
                'code' => self::SELLER_ORDER_DELIVERED,
                'name' => 'Seller order delivered',
                'hint' => 'Fired when a seller marks their shipment as delivered.',
                'group' => 'marketplace',
                'recipients' => [
                    [
                        'key' => 'seller',
                        'label' => 'Delivered email to seller',
                        'hint' => 'Optional seller confirmation.',
                        'required' => false,
                        'template' => 'seller_order_delivered',
                    ],
                ],
            ],
            [
                'code' => self::SELLER_RETURN_REQUESTED,
                'name' => 'Return requested',
                'hint' => 'Fired when a customer requests a return on a seller order.',
                'group' => 'marketplace',
                'recipients' => [
                    [
                        'key' => 'seller',
                        'label' => 'Return request email to seller',
                        'hint' => 'Sent to the seller account email.',
                        'required' => false,
                        'template' => 'seller_return_requested',
                    ],
                ],
            ],
            [
                'code' => self::SELLER_RETURN_APPROVED,
                'name' => 'Return approved',
                'hint' => 'Fired when a seller or admin approves a return request.',
                'group' => 'marketplace',
                'recipients' => [
                    [
                        'key' => 'seller',
                        'label' => 'Return approved email to seller',
                        'hint' => 'Sent to the seller account email.',
                        'required' => false,
                        'template' => 'seller_return_approved',
                    ],
                ],
            ],
            [
                'code' => self::SELLER_REFUND_PROCESSED,
                'name' => 'Refund processed',
                'hint' => 'Fired when a seller return refund is finalized.',
                'group' => 'marketplace',
                'recipients' => [
                    [
                        'key' => 'customer',
                        'label' => 'Refund email to customer',
                        'hint' => 'Sent to the order email.',
                        'required' => false,
                        'template' => 'seller_refund_processed_customer',
                    ],
                    [
                        'key' => 'seller',
                        'label' => 'Refund email to seller',
                        'hint' => 'Sent to the seller account email.',
                        'required' => false,
                        'template' => 'seller_refund_processed_seller',
                    ],
                ],
            ],
            [
                'code' => self::SELLER_PAYOUT_REQUESTED,
                'name' => 'Payout requested',
                'hint' => 'Fired when a seller requests a withdrawal.',
                'group' => 'marketplace',
                'recipients' => [
                    [
                        'key' => 'admin',
                        'label' => 'Payout request notification to admin',
                        'hint' => 'Sent to the store email in Settings.',
                        'required' => false,
                        'template' => 'seller_payout_requested_admin',
                    ],
                    [
                        'key' => 'seller',
                        'label' => 'Payout request confirmation to seller',
                        'hint' => 'Sent to the seller account email.',
                        'required' => false,
                        'template' => 'seller_payout_requested',
                    ],
                ],
            ],
            [
                'code' => self::SELLER_PAYOUT_PAID,
                'name' => 'Payout paid',
                'hint' => 'Fired when admin marks a payout as paid.',
                'group' => 'marketplace',
                'recipients' => [
                    [
                        'key' => 'seller',
                        'label' => 'Payout paid email to seller',
                        'hint' => 'Sent to the seller account email.',
                        'required' => false,
                        'template' => 'seller_payout_paid',
                    ],
                ],
            ],
            [
                'code' => self::SELLER_PAYOUT_FAILED,
                'name' => 'Payout failed',
                'hint' => 'Fired when admin marks a payout as failed.',
                'group' => 'marketplace',
                'recipients' => [
                    [
                        'key' => 'seller',
                        'label' => 'Payout failed email to seller',
                        'hint' => 'Sent to the seller account email.',
                        'required' => false,
                        'template' => 'seller_payout_failed',
                    ],
                ],
            ],
        ];
    }

    public static function codes(): array
    {
        return array_column(self::catalog(), 'code');
    }

    public static function recipient(string $event, string $recipient): ?array
    {
        foreach (self::catalog() as $item) {
            if ($item['code'] !== $event) {
                continue;
            }
            foreach ($item['recipients'] as $row) {
                if ($row['key'] === $recipient) {
                    return $row;
                }
            }
        }

        return null;
    }

    public static function isRequired(string $event, string $recipient): bool
    {
        return (bool) (self::recipient($event, $recipient)['required'] ?? false);
    }

    public static function ensure(?int $storeId = null): void
    {
        // Check each table — templates can gain store_id before events (or vice versa) mid-migrate.
        $eventsHaveStore = \Illuminate\Support\Facades\Schema::hasTable('email_event_settings')
            && \Illuminate\Support\Facades\Schema::hasColumn('email_event_settings', 'store_id');
        $templatesHaveStore = \Illuminate\Support\Facades\Schema::hasTable('email_templates')
            && \Illuminate\Support\Facades\Schema::hasColumn('email_templates', 'store_id');

        if (($eventsHaveStore || $templatesHaveStore) && $storeId === null) {
            try {
                $storeId = \App\Domain\Store\Models\Store::query()->where('is_default', true)->value('id');
            } catch (\Throwable) {
                $storeId = null;
            }
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('email_event_settings')) {
            foreach (self::catalog() as $event) {
                foreach ($event['recipients'] as $row) {
                    $keys = ['event_code' => $event['code'], 'recipient' => $row['key']];
                    $values = ['is_enabled' => true];
                    if ($eventsHaveStore && $storeId) {
                        $keys['store_id'] = $storeId;
                        $values['store_id'] = $storeId;
                    }
                    EmailEventSetting::withoutGlobalScope('shop')->firstOrCreate($keys, $values);
                }
            }
        }

        if (! \Illuminate\Support\Facades\Schema::hasTable('email_templates')) {
            return;
        }

        foreach (self::systemTemplates() as $code => $attrs) {
            $keys = ['code' => $code];
            if ($templatesHaveStore && $storeId) {
                $keys['store_id'] = $storeId;
            }
            $template = EmailTemplate::withoutGlobalScope('shop')->firstOrNew($keys);
            $isNew = ! $template->exists;
            $template->fill([
                'name' => $attrs['name'],
                'event_code' => $attrs['event_code'],
                'recipient' => $attrs['recipient'],
                'is_custom' => false,
            ]);
            if ($isNew) {
                $template->is_enabled = true;
            }
            if ($templatesHaveStore && $storeId) {
                $template->store_id = $storeId;
            } elseif (! $templatesHaveStore && array_key_exists('store_id', $template->getAttributes())) {
                unset($template->store_id);
            }
            if ($isNew || trim((string) $template->body) === '') {
                $template->subject = $attrs['subject'];
                $template->body = $attrs['body'];
            } else {
                $template->subject = $template->subject ?: $attrs['subject'];
            }
            $template->save();
        }

        $status = EmailTemplate::withoutGlobalScope('shop')->where('code', 'order_status');
        if ($templatesHaveStore && $storeId) {
            $status->where('store_id', $storeId);
        }
        $status->update([
            'event_code' => null,
            'recipient' => 'customer',
            'is_custom' => false,
        ]);
    }

    /**
     * @return array<string, array{name: string, event_code: ?string, recipient: string, subject: string, body: string}>
     */
    public static function systemTemplates(): array
    {
        return [
            'welcome' => [
                'name' => 'Welcome email',
                'event_code' => self::CUSTOMER_CREATED,
                'recipient' => 'customer',
                'subject' => 'Welcome to {{shop_name}}',
                'body' => DefaultMailHtml::make(
                    'Welcome to {{shop_name}}',
                    '<p style="margin:0 0 16px;">Hi {{customer_name}},</p><p style="margin:0 0 16px;">Your account is ready. You can sign in anytime and pick up where you left off.</p>',
                    'Visit the shop',
                    '{{shop_url}}',
                ),
            ],
            'customer_created_admin' => [
                'name' => 'New customer (admin)',
                'event_code' => self::CUSTOMER_CREATED,
                'recipient' => 'admin',
                'subject' => 'New customer: {{customer_name}}',
                'body' => DefaultMailHtml::make(
                    'New customer',
                    '<p style="margin:0 0 16px;">{{customer_name}} ({{customer_email}}) just created an account.</p>',
                ),
            ],
            'forgot_password' => [
                'name' => 'Password recovery',
                'event_code' => self::CUSTOMER_FORGOT_PASSWORD,
                'recipient' => 'customer',
                'subject' => 'Reset your {{shop_name}} password',
                'body' => DefaultMailHtml::make(
                    'Reset your password',
                    '<p style="margin:0 0 16px;">Hi {{customer_name}},</p><p style="margin:0 0 16px;">Use the button below to choose a new password. If you did not ask for this, you can ignore the email.</p>',
                    'Reset password',
                    '{{reset_url}}',
                ),
            ],
            'admin_forgot_password' => [
                'name' => 'Admin password recovery',
                'event_code' => self::ADMIN_FORGOT_PASSWORD,
                'recipient' => 'staff',
                'subject' => 'Reset your {{shop_name}} admin password',
                'body' => DefaultMailHtml::make(
                    'Reset your admin password',
                    '<p style="margin:0 0 16px;">Hi {{admin_name}},</p><p style="margin:0 0 16px;">Use the button below to choose a new admin password. If you did not ask for this, you can ignore the email.</p>',
                    'Reset password',
                    '{{reset_url}}',
                ),
            ],
            'order_placed' => [
                'name' => 'Order confirmation',
                'event_code' => self::ORDER_CREATED,
                'recipient' => 'customer',
                'subject' => 'Order {{order_number}} received',
                'body' => DefaultMailHtml::make(
                    'Thanks for your order',
                    '<p style="margin:0 0 16px;">Hi {{customer_name}},</p><p style="margin:0 0 16px;">We received order <strong>{{order_number}}</strong>. Total: <strong>{{grand_total}}</strong>.</p>',
                    'View shop',
                    '{{shop_url}}',
                ),
            ],
            'order_created_admin' => [
                'name' => 'New order (admin)',
                'event_code' => self::ORDER_CREATED,
                'recipient' => 'admin',
                'subject' => 'New order {{order_number}}',
                'body' => DefaultMailHtml::make(
                    'New storefront order',
                    '<p style="margin:0 0 16px;">Order <strong>{{order_number}}</strong> was placed by {{customer_name}} ({{customer_email}}). Total: <strong>{{grand_total}}</strong>.</p>',
                ),
            ],
            'order_paid_customer' => [
                'name' => 'Payment confirmation',
                'event_code' => self::ORDER_PAID,
                'recipient' => 'customer',
                'subject' => 'Payment received for {{order_number}}',
                'body' => DefaultMailHtml::make(
                    'Payment received',
                    '<p style="margin:0 0 16px;">Hi {{customer_name}},</p><p style="margin:0 0 16px;">We received payment for order <strong>{{order_number}}</strong>. Total: <strong>{{grand_total}}</strong>.</p>',
                    'Visit the shop',
                    '{{shop_url}}',
                ),
            ],
            'order_paid_admin' => [
                'name' => 'Payment received (admin)',
                'event_code' => self::ORDER_PAID,
                'recipient' => 'admin',
                'subject' => 'Payment received: {{order_number}}',
                'body' => DefaultMailHtml::make(
                    'Payment received',
                    '<p style="margin:0 0 16px;">Order <strong>{{order_number}}</strong> is now paid. Customer: {{customer_name}} ({{customer_email}}). Total: <strong>{{grand_total}}</strong>.</p>',
                ),
            ],
            'cart_abandoned' => [
                'name' => 'Abandoned cart',
                'event_code' => self::CART_ABANDONED,
                'recipient' => 'customer',
                'subject' => 'You left items in your cart',
                'body' => DefaultMailHtml::make(
                    'Your cart is waiting',
                    '<p style="margin:0 0 16px;">Hi {{customer_name}},</p><p style="margin:0 0 16px;">You still have items in your cart. Return to finish checkout:</p>',
                    'Return to cart',
                    '{{cart_url}}',
                ),
            ],
            'subscription_renewal' => [
                'name' => 'Subscription renewal',
                'event_code' => self::SUBSCRIPTION_RENEWAL,
                'recipient' => 'customer',
                'subject' => 'Your {{plan_name}} subscription is due',
                'body' => DefaultMailHtml::make(
                    'Subscription due',
                    '<p style="margin:0 0 16px;">Hi {{customer_name}},</p><p style="margin:0 0 16px;">Your <strong>{{plan_name}}</strong> subscription for {{product_name}} is due. Pay invoice <strong>{{renewal_order}}</strong> from the link below. This shop does not store cards.</p>',
                    'Pay renewal',
                    '{{pay_url}}',
                ),
            ],
            'gift_card_issued' => [
                'name' => 'Gift card issued',
                'event_code' => self::GIFT_CARD_ISSUED,
                'recipient' => 'customer',
                'subject' => 'Your {{shop_name}} gift card',
                'body' => DefaultMailHtml::make(
                    'You received a gift card',
                    '<p style="margin:0 0 16px;">Hi {{recipient_name}},</p><p style="margin:0 0 16px;">{{sender_name}} sent you a gift card for <strong>{{balance}}</strong>.</p><p style="margin:0 0 16px;">Code: <strong>{{gift_card_code}}</strong></p><p style="margin:0 0 16px;">{{gift_message}}</p>',
                    'Shop now',
                    '{{shop_url}}',
                ),
            ],
            'stock_low' => [
                'name' => 'Low stock',
                'event_code' => self::STOCK_LOW,
                'recipient' => 'admin',
                'subject' => '{{product_name}} is low stock ({{qty}} left)',
                'body' => DefaultMailHtml::make(
                    'Low stock',
                    '<p style="margin:0 0 16px;"><strong>{{product_name}}</strong> ({{sku}}) is down to <strong>{{qty}}</strong>. The alert level is {{threshold}}.</p>',
                    'Open product',
                    '{{admin_url}}',
                ),
            ],
            'product_review_submitted_admin' => [
                'name' => 'Product review submitted (admin)',
                'event_code' => self::PRODUCT_REVIEW_SUBMITTED,
                'recipient' => 'admin',
                'subject' => 'New review for {{product_name}} ({{rating}}/5)',
                'body' => DefaultMailHtml::make(
                    'New product review',
                    '<p style="margin:0 0 16px;">{{customer_name}} ({{customer_email}}) reviewed <strong>{{product_name}}</strong>.</p><p style="margin:0 0 16px;">Rating: <strong>{{rating}}/5</strong>. Status: {{review_status}}. Title: {{review_title}}</p>',
                    'Moderate reviews',
                    '{{admin_url}}/reviews',
                ),
            ],
            'product_review_submitted_customer' => [
                'name' => 'Product review submitted (customer)',
                'event_code' => self::PRODUCT_REVIEW_SUBMITTED,
                'recipient' => 'customer',
                'subject' => 'We received your review of {{product_name}}',
                'body' => DefaultMailHtml::make(
                    'Thanks for your review',
                    '<p style="margin:0 0 16px;">Hi {{customer_name}},</p><p style="margin:0 0 16px;">We received your {{rating}}/5 review of <strong>{{product_name}}</strong>. Status: {{review_status}}.</p>',
                    'View the shop',
                    '{{shop_url}}',
                ),
            ],
            'product_review_replied_customer' => [
                'name' => 'Shop reply to review',
                'event_code' => self::PRODUCT_REVIEW_REPLIED,
                'recipient' => 'customer',
                'subject' => '{{shop_name}} replied to your review of {{product_name}}',
                'body' => DefaultMailHtml::make(
                    'The shop replied',
                    '<p style="margin:0 0 16px;">Hi {{customer_name}},</p><p style="margin:0 0 16px;">The shop replied to your review of <strong>{{product_name}}</strong>:</p><p style="margin:0 0 16px;">{{admin_reply}}</p>',
                    'View the shop',
                    '{{shop_url}}',
                ),
            ],
            'seller_approved' => [
                'name' => 'Seller approved',
                'event_code' => self::SELLER_APPROVED,
                'recipient' => 'seller',
                'subject' => 'Your seller shop is approved',
                'body' => DefaultMailHtml::make(
                    'You are approved to sell',
                    '<p style="margin:0 0 16px;">Hi {{seller_name}},</p><p style="margin:0 0 16px;">Your shop <strong>{{shop_name_seller}}</strong> is approved. Sign in to manage products, orders, and payouts.</p><p style="margin:0 0 16px;">Commission rate: <strong>{{commission_rate}}%</strong>.</p>',
                    'Seller portal',
                    '{{shop_url}}/account/seller',
                ),
            ],
            'seller_order_created' => [
                'name' => 'Seller new order',
                'event_code' => self::SELLER_ORDER_CREATED,
                'recipient' => 'seller',
                'subject' => 'New order {{order_number}} for {{shop_name_seller}}',
                'body' => DefaultMailHtml::make(
                    'You have a new order',
                    '<p style="margin:0 0 16px;">Hi {{seller_name}},</p><p style="margin:0 0 16px;">Order <strong>{{order_number}}</strong> includes your products. Customer: {{customer_name}}. Your slice total: <strong>{{seller_order_total}}</strong>. Estimated earning: <strong>{{earning_total}}</strong>.</p>',
                    'View seller orders',
                    '{{shop_url}}/account/seller/orders',
                ),
            ],
            'seller_application_submitted_admin' => [
                'name' => 'Seller application (admin)',
                'event_code' => self::SELLER_APPLICATION_SUBMITTED,
                'recipient' => 'admin',
                'subject' => 'New seller application: {{shop_name_seller}}',
                'body' => DefaultMailHtml::make(
                    'New seller application',
                    '<p style="margin:0 0 16px;">{{seller_name}} applied to sell as <strong>{{shop_name_seller}}</strong> ({{seller_email}}).</p>',
                ),
            ],
            'seller_rejected' => [
                'name' => 'Seller rejected',
                'event_code' => self::SELLER_REJECTED,
                'recipient' => 'seller',
                'subject' => 'Seller application update for {{shop_name_seller}}',
                'body' => DefaultMailHtml::make(
                    'Application not approved',
                    '<p style="margin:0 0 16px;">Hi {{seller_name}},</p><p style="margin:0 0 16px;">Your seller application for <strong>{{shop_name_seller}}</strong> was not approved.</p><p style="margin:0 0 16px;">Reason: {{rejection_reason}}</p>',
                ),
            ],
            'seller_suspended' => [
                'name' => 'Seller suspended',
                'event_code' => self::SELLER_SUSPENDED,
                'recipient' => 'seller',
                'subject' => 'Your seller shop {{shop_name_seller}} is suspended',
                'body' => DefaultMailHtml::make(
                    'Shop suspended',
                    '<p style="margin:0 0 16px;">Hi {{seller_name}},</p><p style="margin:0 0 16px;">Your shop <strong>{{shop_name_seller}}</strong> has been suspended.</p><p style="margin:0 0 16px;">Reason: {{suspension_reason}}</p>',
                ),
            ],
            'seller_product_submitted_admin' => [
                'name' => 'Seller product submitted (admin)',
                'event_code' => self::SELLER_PRODUCT_SUBMITTED,
                'recipient' => 'admin',
                'subject' => 'Product awaiting review: {{product_name}}',
                'body' => DefaultMailHtml::make(
                    'Product moderation',
                    '<p style="margin:0 0 16px;">Seller <strong>{{shop_name_seller}}</strong> submitted <strong>{{product_name}}</strong> for review.</p>',
                ),
            ],
            'seller_product_approved' => [
                'name' => 'Seller product approved',
                'event_code' => self::SELLER_PRODUCT_APPROVED,
                'recipient' => 'seller',
                'subject' => 'Product approved: {{product_name}}',
                'body' => DefaultMailHtml::make(
                    'Product approved',
                    '<p style="margin:0 0 16px;">Hi {{seller_name}},</p><p style="margin:0 0 16px;">Your product <strong>{{product_name}}</strong> is approved and can be sold.</p>',
                ),
            ],
            'seller_product_rejected' => [
                'name' => 'Seller product rejected',
                'event_code' => self::SELLER_PRODUCT_REJECTED,
                'recipient' => 'seller',
                'subject' => 'Product not approved: {{product_name}}',
                'body' => DefaultMailHtml::make(
                    'Product not approved',
                    '<p style="margin:0 0 16px;">Hi {{seller_name}},</p><p style="margin:0 0 16px;">Your product <strong>{{product_name}}</strong> was not approved.</p><p style="margin:0 0 16px;">Reason: {{rejection_reason}}</p>',
                ),
            ],
            'seller_order_confirmed' => [
                'name' => 'Seller order confirmed',
                'event_code' => self::SELLER_ORDER_CONFIRMED,
                'recipient' => 'seller',
                'subject' => 'Order {{seller_order_number}} confirmed',
                'body' => DefaultMailHtml::make(
                    'Order confirmed',
                    '<p style="margin:0 0 16px;">Hi {{seller_name}},</p><p style="margin:0 0 16px;">Seller order <strong>{{seller_order_number}}</strong> is confirmed and ready for fulfillment.</p>',
                ),
            ],
            'seller_order_shipped' => [
                'name' => 'Seller order shipped (seller)',
                'event_code' => self::SELLER_ORDER_SHIPPED,
                'recipient' => 'seller',
                'subject' => 'Shipment marked shipped: {{seller_order_number}}',
                'body' => DefaultMailHtml::make(
                    'Shipment shipped',
                    '<p style="margin:0 0 16px;">Hi {{seller_name}},</p><p style="margin:0 0 16px;">Seller order <strong>{{seller_order_number}}</strong> is marked shipped. Tracking: {{tracking_number}}</p>',
                ),
            ],
            'seller_order_shipped_customer' => [
                'name' => 'Seller order shipped (customer)',
                'event_code' => self::SELLER_ORDER_SHIPPED,
                'recipient' => 'customer',
                'subject' => 'Part of order {{order_number}} has shipped',
                'body' => DefaultMailHtml::make(
                    'Shipment update',
                    '<p style="margin:0 0 16px;">Hi {{customer_name}},</p><p style="margin:0 0 16px;">Items from <strong>{{shop_name_seller}}</strong> in order <strong>{{order_number}}</strong> have shipped. Tracking: {{tracking_number}}</p>',
                ),
            ],
            'seller_order_delivered' => [
                'name' => 'Seller order delivered',
                'event_code' => self::SELLER_ORDER_DELIVERED,
                'recipient' => 'seller',
                'subject' => 'Order {{seller_order_number}} delivered',
                'body' => DefaultMailHtml::make(
                    'Delivery complete',
                    '<p style="margin:0 0 16px;">Hi {{seller_name}},</p><p style="margin:0 0 16px;">Seller order <strong>{{seller_order_number}}</strong> is marked delivered.</p>',
                ),
            ],
            'seller_return_requested' => [
                'name' => 'Return requested (seller)',
                'event_code' => self::SELLER_RETURN_REQUESTED,
                'recipient' => 'seller',
                'subject' => 'Return request {{return_number}}',
                'body' => DefaultMailHtml::make(
                    'Return requested',
                    '<p style="margin:0 0 16px;">Hi {{seller_name}},</p><p style="margin:0 0 16px;">Customer {{customer_name}} requested return <strong>{{return_number}}</strong> for order {{order_number}}.</p>',
                ),
            ],
            'seller_return_approved' => [
                'name' => 'Return approved (seller)',
                'event_code' => self::SELLER_RETURN_APPROVED,
                'recipient' => 'seller',
                'subject' => 'Return {{return_number}} approved',
                'body' => DefaultMailHtml::make(
                    'Return approved',
                    '<p style="margin:0 0 16px;">Hi {{seller_name}},</p><p style="margin:0 0 16px;">Return <strong>{{return_number}}</strong> was approved.</p>',
                ),
            ],
            'seller_refund_processed_customer' => [
                'name' => 'Refund processed (customer)',
                'event_code' => self::SELLER_REFUND_PROCESSED,
                'recipient' => 'customer',
                'subject' => 'Refund processed for order {{order_number}}',
                'body' => DefaultMailHtml::make(
                    'Refund processed',
                    '<p style="margin:0 0 16px;">Hi {{customer_name}},</p><p style="margin:0 0 16px;">A refund of <strong>{{refund_total}}</strong> was processed for order <strong>{{order_number}}</strong>.</p>',
                ),
            ],
            'seller_refund_processed_seller' => [
                'name' => 'Refund processed (seller)',
                'event_code' => self::SELLER_REFUND_PROCESSED,
                'recipient' => 'seller',
                'subject' => 'Refund processed: {{return_number}}',
                'body' => DefaultMailHtml::make(
                    'Refund processed',
                    '<p style="margin:0 0 16px;">Hi {{seller_name}},</p><p style="margin:0 0 16px;">Return <strong>{{return_number}}</strong> was refunded. Amount: <strong>{{refund_total}}</strong>.</p>',
                ),
            ],
            'seller_payout_requested_admin' => [
                'name' => 'Payout requested (admin)',
                'event_code' => self::SELLER_PAYOUT_REQUESTED,
                'recipient' => 'admin',
                'subject' => 'Payout request from {{shop_name_seller}}',
                'body' => DefaultMailHtml::make(
                    'Payout requested',
                    '<p style="margin:0 0 16px;">Seller <strong>{{shop_name_seller}}</strong> requested a payout of <strong>{{payout_amount}}</strong>.</p>',
                ),
            ],
            'seller_payout_requested' => [
                'name' => 'Payout requested (seller)',
                'event_code' => self::SELLER_PAYOUT_REQUESTED,
                'recipient' => 'seller',
                'subject' => 'Payout request received',
                'body' => DefaultMailHtml::make(
                    'Payout request received',
                    '<p style="margin:0 0 16px;">Hi {{seller_name}},</p><p style="margin:0 0 16px;">We received your payout request for <strong>{{payout_amount}}</strong>.</p>',
                ),
            ],
            'seller_payout_paid' => [
                'name' => 'Payout paid',
                'event_code' => self::SELLER_PAYOUT_PAID,
                'recipient' => 'seller',
                'subject' => 'Payout of {{payout_amount}} sent',
                'body' => DefaultMailHtml::make(
                    'Payout sent',
                    '<p style="margin:0 0 16px;">Hi {{seller_name}},</p><p style="margin:0 0 16px;">Your payout of <strong>{{payout_amount}}</strong> was marked paid.</p>',
                ),
            ],
            'seller_payout_failed' => [
                'name' => 'Payout failed',
                'event_code' => self::SELLER_PAYOUT_FAILED,
                'recipient' => 'seller',
                'subject' => 'Payout could not be completed',
                'body' => DefaultMailHtml::make(
                    'Payout failed',
                    '<p style="margin:0 0 16px;">Hi {{seller_name}},</p><p style="margin:0 0 16px;">Your payout of <strong>{{payout_amount}}</strong> could not be completed.</p><p style="margin:0 0 16px;">Reason: {{failure_reason}}</p>',
                ),
            ],
            'order_status' => [
                'name' => 'Order status',
                'event_code' => null,
                'recipient' => 'customer',
                'subject' => 'Order {{order_number}} is now {{status}}',
                'body' => DefaultMailHtml::make(
                    'Order update',
                    '<p style="margin:0 0 16px;">Hi {{customer_name}},</p><p style="margin:0 0 16px;">Order <strong>{{order_number}}</strong> is now <strong>{{status}}</strong>.</p>',
                ),
            ],
        ];
    }
}
