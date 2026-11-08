<?php

namespace App\Domain\Settings;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\Models\SellerReturn;
use App\Domain\Marketplace\Models\SellerWithdrawal;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\Models\EmailEventSetting;
use App\Domain\Settings\Models\EmailLog;
use App\Domain\Settings\Models\EmailTemplate;
use App\Domain\Settings\Models\Setting;
use App\Domain\Store\StoreContext;
use App\Mail\ShopHtmlMail;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ShopMailer
{
    public function sendEvent(string $event, array $vars, ?string $customerEmail = null): void
    {
        $this->sendRecipient($event, 'customer', $vars, $customerEmail);
        $this->sendRecipient($event, 'admin', $vars, $this->adminEmail());
    }

    public function sendRecipient(string $event, string $recipient, array $vars, ?string $to): void
    {
        $def = MailEvents::recipient($event, $recipient);
        if (! $def) {
            return;
        }

        $required = (bool) $def['required'];
        if (! $required && ! EmailEventSetting::enabled($event, $recipient)) {
            return;
        }

        $this->sendTemplate($def['template'], $vars, $to);
    }

    public function sendTemplate(string $code, array $vars, ?string $to): void
    {
        $to = is_string($to) ? trim($to) : '';
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $template = EmailTemplate::query()->where('code', $code)->where('is_enabled', true)->first();
        if (! $template) {
            return;
        }

        $vars = $this->withShopVars($vars);
        $locale = isset($vars['language_code']) && is_string($vars['language_code'])
            ? $vars['language_code']
            : null;
        $localized = TemplateContentLocales::resolveEmail($template, $locale);
        $subject = EmailTemplate::replaceVars($localized['subject'], $vars);
        $body = EmailTemplate::replaceVars($localized['body'], $vars);

        $this->prepareMailer();
        $storeId = app(StoreContext::class)->id();

        try {
            Mail::to($to)->send(new ShopHtmlMail($body, $subject));
            $this->logMail($to, $subject, $code, 'sent', null, $storeId);
        } catch (Throwable $e) {
            $this->logMail($to, $subject, $code, 'failed', $e->getMessage(), $storeId);
            $this->reportQuietly($e);
        }
    }

    public function sendTest(string $to, string $subject, string $html): void
    {
        $this->prepareMailer();
        $storeId = app(StoreContext::class)->id();
        try {
            Mail::to($to)->send(new ShopHtmlMail($html, $subject));
            $this->logMail($to, $subject, 'smtp_test', 'sent', null, $storeId);
        } catch (Throwable $e) {
            $this->logMail($to, $subject, 'smtp_test', 'failed', $e->getMessage(), $storeId);
            throw $e;
        }
    }

    public function sendHtml(string $to, string $subject, string $html, string $code = 'invoice'): bool
    {
        $to = trim($to);
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $this->prepareMailer();
        $storeId = app(StoreContext::class)->id();
        try {
            Mail::to($to)->send(new ShopHtmlMail($html, $subject));
            $this->logMail($to, $subject, $code, 'sent', null, $storeId);

            return true;
        } catch (Throwable $e) {
            $this->logMail($to, $subject, $code, 'failed', $e->getMessage(), $storeId);
            $this->reportQuietly($e);

            return false;
        }
    }

    protected function prepareMailer(): void
    {
        MailRuntime::apply();
        MailRuntime::assertCanSend();
    }

    /**
     * report() itself writes to the log channel; when the log file is not writable
     * (common when CLI and Apache use different users), that must not break checkout.
     */
    protected function reportQuietly(Throwable $e): void
    {
        try {
            report($e);
        } catch (Throwable) {
            // Optional.
        }
    }

    protected function logMail(string $to, string $subject, string $template, string $status, ?string $error, int $storeId): void
    {
        try {
            EmailLog::query()->create([
                'store_id' => $storeId > 0 ? $storeId : null,
                'to_email' => $to,
                'subject' => $subject,
                'template_code' => $template,
                'status' => $status,
                'error' => $error,
            ]);
        } catch (Throwable) {
            // Logs are optional.
        }
    }

    public function customerCreated(Customer $customer): void
    {
        $this->sendEvent(MailEvents::CUSTOMER_CREATED, $this->customerVars($customer), $customer->email);
    }

    public function orderCreated(Order $order): void
    {
        $vars = $this->orderVars($order);
        if (\App\Domain\Settings\OrderSettings::emailCustomerConfirmation()) {
            $this->sendRecipient(MailEvents::ORDER_CREATED, 'customer', $vars, $order->email);
        }
        if (\App\Domain\Settings\OrderSettings::emailAdminNewOrder()) {
            foreach (\App\Domain\Settings\OrderSettings::adminNewOrderEmails() as $email) {
                $this->sendRecipient(MailEvents::ORDER_CREATED, 'admin', $vars, $email);
            }
        }
        app(\App\Domain\Order\InvoiceDocument::class)->maybeEmail($order, $this);
    }

    public function orderPaid(Order $order): void
    {
        $this->sendEvent(MailEvents::ORDER_PAID, $this->orderVars($order), $order->email);
        app(\App\Domain\Order\InvoiceDocument::class)->maybeEmail($order, $this);
    }

    public function cartAbandoned(string $email, string $name, string $cartUrl): void
    {
        $this->sendRecipient(MailEvents::CART_ABANDONED, 'customer', [
            'customer_name' => $name !== '' ? $name : 'there',
            'cart_url' => $cartUrl,
        ], $email);
    }

    public function sellerApproved(Seller $seller): void
    {
        $this->sendRecipient(
            MailEvents::SELLER_APPROVED,
            'seller',
            $this->sellerVars($seller),
            $this->sellerEmail($seller),
        );
    }

    public function sellerOrderCreated(Seller $seller, Order $order, SellerOrder $sellerOrder): void
    {
        $vars = array_merge($this->orderVars($order), $this->sellerVars($seller), [
            'seller_order_total' => (string) $sellerOrder->grand_total,
            'earning_total' => (string) $sellerOrder->earning_total,
        ]);
        $this->sendRecipient(
            MailEvents::SELLER_ORDER_CREATED,
            'seller',
            $vars,
            $this->sellerEmail($seller),
        );
    }

    public function sellerApplicationSubmitted(Seller $seller): void
    {
        $this->sendRecipient(
            MailEvents::SELLER_APPLICATION_SUBMITTED,
            'admin',
            $this->sellerVars($seller),
            $this->adminEmail(),
        );
    }

    public function sellerRejected(Seller $seller, ?string $reason): void
    {
        $vars = array_merge($this->sellerVars($seller), [
            'rejection_reason' => (string) ($reason ?: 'Not specified'),
        ]);
        $this->sendRecipient(
            MailEvents::SELLER_REJECTED,
            'seller',
            $vars,
            $this->sellerEmail($seller),
        );
    }

    public function sellerSuspended(Seller $seller, ?string $reason): void
    {
        $vars = array_merge($this->sellerVars($seller), [
            'suspension_reason' => (string) ($reason ?: 'Not specified'),
        ]);
        $this->sendRecipient(
            MailEvents::SELLER_SUSPENDED,
            'seller',
            $vars,
            $this->sellerEmail($seller),
        );
    }

    public function sellerProductSubmitted(Seller $seller, SellerProduct $listing): void
    {
        $vars = array_merge($this->sellerVars($seller), [
            'product_name' => (string) ($listing->name ?: 'Product #'.$listing->product_id),
        ]);
        $this->sendRecipient(
            MailEvents::SELLER_PRODUCT_SUBMITTED,
            'admin',
            $vars,
            $this->adminEmail(),
        );
    }

    public function sellerProductApproved(Seller $seller, SellerProduct $listing): void
    {
        $vars = array_merge($this->sellerVars($seller), [
            'product_name' => (string) ($listing->name ?: 'Product #'.$listing->product_id),
        ]);
        $this->sendRecipient(
            MailEvents::SELLER_PRODUCT_APPROVED,
            'seller',
            $vars,
            $this->sellerEmail($seller),
        );
    }

    public function sellerProductRejected(Seller $seller, SellerProduct $listing, ?string $reason): void
    {
        $vars = array_merge($this->sellerVars($seller), [
            'product_name' => (string) ($listing->name ?: 'Product #'.$listing->product_id),
            'rejection_reason' => (string) ($reason ?: 'Not specified'),
        ]);
        $this->sendRecipient(
            MailEvents::SELLER_PRODUCT_REJECTED,
            'seller',
            $vars,
            $this->sellerEmail($seller),
        );
    }

    public function sellerOrderConfirmed(Seller $seller, SellerOrder $sellerOrder): void
    {
        $vars = array_merge($this->sellerVars($seller), [
            'seller_order_number' => (string) $sellerOrder->number,
        ]);
        $this->sendRecipient(
            MailEvents::SELLER_ORDER_CONFIRMED,
            'seller',
            $vars,
            $this->sellerEmail($seller),
        );
    }

    public function sellerOrderShipped(Seller $seller, SellerOrder $sellerOrder, ?string $trackingNumber): void
    {
        $sellerOrder->loadMissing('order');
        $vars = array_merge(
            $sellerOrder->order ? $this->orderVars($sellerOrder->order) : [],
            $this->sellerVars($seller),
            [
                'seller_order_number' => (string) $sellerOrder->number,
                'tracking_number' => (string) ($trackingNumber ?: '—'),
            ],
        );
        $this->sendRecipient(
            MailEvents::SELLER_ORDER_SHIPPED,
            'seller',
            $vars,
            $this->sellerEmail($seller),
        );
        if ($sellerOrder->order) {
            $this->sendRecipient(
                MailEvents::SELLER_ORDER_SHIPPED,
                'customer',
                $vars,
                $sellerOrder->order->email,
            );
        }
    }

    public function sellerOrderDelivered(Seller $seller, SellerOrder $sellerOrder): void
    {
        $vars = array_merge($this->sellerVars($seller), [
            'seller_order_number' => (string) $sellerOrder->number,
        ]);
        $this->sendRecipient(
            MailEvents::SELLER_ORDER_DELIVERED,
            'seller',
            $vars,
            $this->sellerEmail($seller),
        );
    }

    public function returnRequested(Seller $seller, SellerReturn $return, Customer $customer): void
    {
        $return->loadMissing('order');
        $vars = array_merge(
            $this->sellerVars($seller),
            $this->customerVars($customer),
            [
                'return_number' => (string) $return->number,
                'order_number' => (string) ($return->order?->number ?: '—'),
            ],
        );
        $this->sendRecipient(
            MailEvents::SELLER_RETURN_REQUESTED,
            'seller',
            $vars,
            $this->sellerEmail($seller),
        );
    }

    public function returnApproved(Seller $seller, SellerReturn $return): void
    {
        $vars = array_merge($this->sellerVars($seller), [
            'return_number' => (string) $return->number,
        ]);
        $this->sendRecipient(
            MailEvents::SELLER_RETURN_APPROVED,
            'seller',
            $vars,
            $this->sellerEmail($seller),
        );
    }

    public function refundProcessed(Order $order, SellerReturn $return): void
    {
        $vars = array_merge($this->orderVars($order), [
            'refund_total' => (string) $return->refund_total,
            'return_number' => (string) $return->number,
        ]);
        $this->sendRecipient(
            MailEvents::SELLER_REFUND_PROCESSED,
            'customer',
            $vars,
            $order->email,
        );
    }

    public function refundProcessedSeller(Seller $seller, SellerReturn $return): void
    {
        $vars = array_merge($this->sellerVars($seller), [
            'return_number' => (string) $return->number,
            'refund_total' => (string) $return->refund_total,
        ]);
        $this->sendRecipient(
            MailEvents::SELLER_REFUND_PROCESSED,
            'seller',
            $vars,
            $this->sellerEmail($seller),
        );
    }

    public function payoutRequested(Seller $seller, SellerWithdrawal $withdrawal): void
    {
        $vars = array_merge($this->sellerVars($seller), [
            'payout_amount' => number_format((float) $withdrawal->amount, 2),
        ]);
        $this->sendRecipient(
            MailEvents::SELLER_PAYOUT_REQUESTED,
            'admin',
            $vars,
            $this->adminEmail(),
        );
        $this->sendRecipient(
            MailEvents::SELLER_PAYOUT_REQUESTED,
            'seller',
            $vars,
            $this->sellerEmail($seller),
        );
    }

    public function payoutPaid(Seller $seller, SellerWithdrawal $withdrawal): void
    {
        $vars = array_merge($this->sellerVars($seller), [
            'payout_amount' => number_format((float) $withdrawal->amount, 2),
        ]);
        $this->sendRecipient(
            MailEvents::SELLER_PAYOUT_PAID,
            'seller',
            $vars,
            $this->sellerEmail($seller),
        );
    }

    public function payoutFailed(Seller $seller, SellerWithdrawal $withdrawal, ?string $reason): void
    {
        $vars = array_merge($this->sellerVars($seller), [
            'payout_amount' => number_format((float) $withdrawal->amount, 2),
            'failure_reason' => (string) ($reason ?: 'Not specified'),
        ]);
        $this->sendRecipient(
            MailEvents::SELLER_PAYOUT_FAILED,
            'seller',
            $vars,
            $this->sellerEmail($seller),
        );
    }

    /**
     * @return array<string, string>
     */
    public function sellerVars(Seller $seller): array
    {
        $name = trim(($seller->first_name ?? '').' '.($seller->last_name ?? ''));
        if ($name === '') {
            $name = (string) ($seller->profile_name ?: $seller->shop_name ?: 'Seller');
        }

        return [
            'seller_name' => $name,
            'seller_email' => (string) $this->sellerEmail($seller),
            'shop_name_seller' => (string) $seller->shop_name,
            'commission_rate' => (string) $seller->commission_rate,
        ];
    }

    protected function sellerEmail(Seller $seller): ?string
    {
        foreach ([$seller->email, $seller->contact_email] as $email) {
            if (is_string($email) && trim($email) !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return trim($email);
            }
        }

        return null;
    }

    public function withShopVars(array $vars): array
    {
        return array_merge([
            'shop_name' => $this->shopValue('name', 'shop_name', config('app.name')),
            'shop_url' => $this->shopValue('url', 'shop_url', config('app.url')),
            'shop_email' => $this->shopValue('email', 'shop_email', config('mail.from.address')),
        ], $vars);
    }

    /**
     * @return array<string, string>
     */
    public function customerVars(Customer $customer): array
    {
        return [
            'customer_name' => $customer->displayName(),
            'customer_email' => (string) $customer->email,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function orderVars(Order $order): array
    {
        $billing = $order->billing_address ?? [];
        $name = trim(($billing['first_name'] ?? '').' '.($billing['last_name'] ?? ''));
        if ($name === '') {
            $name = $order->customer?->displayName() ?: 'Customer';
        }

        $order->loadMissing('items');
        $preorderLines = $order->items
            ->filter(fn ($item) => is_array($item->preorder_data) && ! empty($item->preorder_data['enabled']))
            ->map(function ($item) {
                $data = $item->preorder_data;
                $parts = [(string) $item->name];
                if (! empty($data['status'])) {
                    $parts[] = 'status '.$data['status'];
                }
                if (! empty($data['release_at'])) {
                    $parts[] = 'release '.substr((string) $data['release_at'], 0, 10);
                }
                if (($data['remaining_balance'] ?? 0) > 0) {
                    $parts[] = 'remaining '.$data['remaining_balance'];
                }

                return implode(' · ', $parts);
            })
            ->values()
            ->all();

        return [
            'customer_name' => $name,
            'customer_email' => (string) ($order->email ?: $order->customer?->email),
            'order_number' => (string) $order->number,
            'grand_total' => (string) $order->grand_total,
            'status' => (string) $order->status,
            'payment_status' => (string) $order->payment_status,
            'has_preorder' => $preorderLines !== [] ? '1' : '0',
            'preorder_summary' => $preorderLines !== [] ? implode('; ', $preorderLines) : '',
        ];
    }

    public function shopInbox(): ?string
    {
        return $this->adminEmail();
    }

    public function inboundContact(string $name, string $email, string $message, ?string $orderNumber = null): void
    {
        $to = $this->shopInbox();
        if (! $to) {
            return;
        }
        $order = $orderNumber ? '<p>Order: '.e($orderNumber).'</p>' : '';
        $html = '<p>From '.e($name).' &lt;'.e($email).'&gt;</p>'.$order.'<p>'.nl2br(e($message)).'</p>';
        $this->sendTest($to, 'Contact form: '.$name, $html);
    }

    public function backInStock(string $email, string $productName, string $url, ?string $imageUrl = null): void
    {
        $image = '';
        if (is_string($imageUrl) && $imageUrl !== '') {
            $image = '<p><img src="'.e($imageUrl).'" alt="'.e($productName).'" width="160" style="max-width:160px;height:auto;border:0;" /></p>';
        }
        $html = $image
            .'<p>'.e($productName).' is back in stock.</p>'
            .'<p><a href="'.e($url).'">View product</a></p>';
        if (! $this->sendHtml($email, $productName.' is back in stock', $html, 'back_in_stock')) {
            throw new \RuntimeException('Back-in-stock email could not be delivered.');
        }
    }

    public function preorderAvailable(string $email, string $productName, string $url): void
    {
        $html = '<p>'.e($productName).' is now available for purchase.</p><p><a href="'.e($url).'">View product</a></p>';
        $this->sendTest($email, $productName.' is now available', $html);
    }

    public function stockLow(string $productName, string $sku, string $qty, string $threshold, string $adminUrl): void
    {
        MailEvents::ensure();
        $this->sendRecipient(MailEvents::STOCK_LOW, 'admin', [
            'product_name' => $productName,
            'sku' => $sku,
            'qty' => $qty,
            'threshold' => $threshold,
            'admin_url' => $adminUrl,
        ], $this->adminEmail());
    }

    protected function adminEmail(): ?string
    {
        $email = $this->shopValue('email', 'shop_email', null);

        return is_string($email) && $email !== '' ? $email : null;
    }

    protected function shopValue(string $storeAttr, string $settingKey, mixed $default): mixed
    {
        $store = app(StoreContext::class)->store();
        if ($storeAttr === 'url') {
            $url = $store?->primaryUrl()?->root();
            if (is_string($url) && $url !== '') {
                return $url;
            }
        } elseif ($store && filled($store->{$storeAttr} ?? null)) {
            return $store->{$storeAttr};
        }

        return Setting::getValue($settingKey, $default);
    }
}
