<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Order\Services\OrderNumberService;
use App\Domain\Settings\CartCheckoutSettings;
use App\Domain\Settings\DocumentCommerceSettings;
use App\Domain\Settings\Models\FileChangeLog;
use App\Domain\Settings\OrderSettings;
use App\Domain\Settings\ReturnSettings;
use App\Domain\Settings\ShopSetting;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrderSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $tab = $request->query('tab', 'defaults');
        if (! in_array($tab, ['defaults', 'cart', 'returns', 'invoices'], true)) {
            $tab = 'defaults';
        }

        return Inertia::render('Settings/Order', array_merge(OrderSettings::payload(), [
            'tab' => $tab,
            'next_sequence' => app(OrderNumberService::class)->peekSequence(),
            'cart' => CartCheckoutSettings::payload(),
            'returns' => ReturnSettings::payload(),
            'invoices' => DocumentCommerceSettings::payload(),
        ]));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'number_format' => ['required', 'string', 'max:64', 'regex:/^[^\\{\\}]*\\{number\\}[^\\{\\}]*$/'],
            'number_start' => ['required', 'integer', 'min:1', 'max:999999999'],
            'default_status' => ['required', Rule::in(array_column(OrderSettings::statusChoices(), 'value'))],
            'min_amount' => ['required', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
            'customer_notes_enabled' => ['required', 'boolean'],
            'guest_lookup' => ['required', 'boolean'],
            'allow_cancellation' => ['required', 'boolean'],
            'cancellation_until' => ['required', 'string', Rule::in(OrderSettings::cancellationUntilValues())],
            'auto_cancel_unpaid' => ['required', 'boolean'],
            'unpaid_timeout_minutes' => ['required', 'integer', 'min:1', 'max:525600'],
            'reserve_stock_unpaid' => ['required', 'boolean'],
            'stock_reservation_minutes' => ['required', 'integer', 'min:1', 'max:525600'],
            'reduce_stock_at' => ['required', 'string', Rule::in(OrderSettings::reduceStockAtValues())],
            'restock_on_cancellation' => ['required', 'boolean'],
            'email_customer_confirmation' => ['required', 'boolean'],
            'email_admin_new_order' => ['required', 'boolean'],
            'admin_new_order_emails' => ['nullable', 'string', 'max:2000'],
        ], [
            'number_format.regex' => 'Enter an editable format that includes {number} once and no other braces. Example: ORD-{number}.',
        ]);

        if (! empty($data['max_amount']) && (float) $data['max_amount'] > 0 && (float) $data['max_amount'] < (float) $data['min_amount']) {
            return back()->withErrors([
                'max_amount' => 'Maximum order amount must be greater than or equal to the minimum.',
            ]);
        }

        $parsed = OrderSettings::parseEmailList((string) ($data['admin_new_order_emails'] ?? ''));
        if ($parsed['invalid']) {
            return back()->withErrors([
                'admin_new_order_emails' => 'Enter valid email addresses, separated by commas.',
            ]);
        }
        if ($data['email_admin_new_order'] && $parsed['emails'] === []) {
            return back()->withErrors([
                'admin_new_order_emails' => 'Enter at least one email address.',
            ]);
        }

        $previousStart = OrderSettings::numberStart();
        ShopSetting::put('orders.number_format', trim($data['number_format']));
        ShopSetting::put('orders.number_start', (int) $data['number_start']);
        ShopSetting::put('orders.default_status', OrderSettings::codeFromChoiceValue($data['default_status']));
        ShopSetting::put('orders.min_amount', round((float) $data['min_amount'], 2));
        ShopSetting::put(
            'orders.max_amount',
            ! empty($data['max_amount']) && (float) $data['max_amount'] > 0
                ? round((float) $data['max_amount'], 2)
                : null
        );
        ShopSetting::put('orders.customer_notes_enabled', (bool) $data['customer_notes_enabled']);
        ShopSetting::put('orders.guest_lookup', (bool) $data['guest_lookup']);
        ShopSetting::put('orders.allow_cancellation', (bool) $data['allow_cancellation']);
        ShopSetting::put('orders.cancellation_until', OrderSettings::normalizeCancellationUntil($data['cancellation_until']));
        ShopSetting::put('orders.auto_cancel_unpaid', (bool) $data['auto_cancel_unpaid']);
        ShopSetting::put('orders.unpaid_timeout_minutes', (int) $data['unpaid_timeout_minutes']);
        ShopSetting::put('orders.reserve_stock_unpaid', (bool) $data['reserve_stock_unpaid']);
        ShopSetting::put('orders.stock_reservation_minutes', (int) $data['stock_reservation_minutes']);
        ShopSetting::put('orders.reduce_stock_at', OrderSettings::normalizeReduceStockAt($data['reduce_stock_at']));
        ShopSetting::put('orders.restock_on_cancellation', (bool) $data['restock_on_cancellation']);
        ShopSetting::put('orders.email_customer_confirmation', (bool) $data['email_customer_confirmation']);
        ShopSetting::put('orders.email_admin_new_order', (bool) $data['email_admin_new_order']);
        ShopSetting::put('orders.admin_new_order_emails', implode(', ', $parsed['emails']));

        OrderSettings::syncSequenceFloor((int) $data['number_start'], $previousStart);
        FileChangeLog::record('updated', 'orders/settings');

        return back()->with('success', 'Order settings saved.');
    }
}
