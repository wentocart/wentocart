<?php

namespace App\Domain\Order;

use App\Domain\Order\Models\OrderStage;
use App\Support\HexColor;

final class OrderStageCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            [
                'code' => 'pending',
                'name' => 'Pending',
                'buyer_label' => 'Order received',
                'buyer_note' => 'New order waiting for review or payment.',
                'tone' => HexColor::TONES['warning'],
                'lane' => 'inbox',
                'tracker_step' => 1,
                'settle_payment' => false,
                'unlock_invoice' => false,
                'unlock_delivery_slip' => false,
                'unlock_credit_slip' => false,
                'stock_move' => 'none',
                'notify_buyer' => true,
                'mail_template' => 'order_status',
                'buyer_may_pay' => true,
                'buyer_may_cancel' => true,
                'is_system' => true,
                'position' => 10,
            ],
            [
                'code' => 'processing',
                'name' => 'Processing',
                'buyer_label' => 'We’re preparing your order',
                'buyer_note' => 'Payment is accepted and the shop is packing.',
                'tone' => HexColor::TONES['primary'],
                'lane' => 'pack',
                'tracker_step' => 2,
                'settle_payment' => true,
                'unlock_invoice' => true,
                'unlock_delivery_slip' => false,
                'unlock_credit_slip' => false,
                'stock_move' => 'none',
                'notify_buyer' => true,
                'mail_template' => 'order_status',
                'buyer_may_pay' => false,
                'buyer_may_cancel' => false,
                'is_system' => true,
                'position' => 20,
            ],
            [
                'code' => 'shipped',
                'name' => 'Shipped',
                'buyer_label' => 'On the way',
                'buyer_note' => 'Handed to the carrier.',
                'tone' => HexColor::TONES['info'],
                'lane' => 'transit',
                'tracker_step' => 3,
                'settle_payment' => true,
                'unlock_invoice' => true,
                'unlock_delivery_slip' => true,
                'unlock_credit_slip' => false,
                'stock_move' => 'none',
                'notify_buyer' => true,
                'mail_template' => 'order_status',
                'buyer_may_pay' => false,
                'buyer_may_cancel' => false,
                'is_system' => true,
                'position' => 30,
            ],
            [
                'code' => 'completed',
                'name' => 'Delivered',
                'buyer_label' => 'Delivered',
                'buyer_note' => 'Customer received the package.',
                'tone' => HexColor::TONES['success'],
                'lane' => 'done',
                'tracker_step' => 4,
                'settle_payment' => true,
                'unlock_invoice' => true,
                'unlock_delivery_slip' => true,
                'unlock_credit_slip' => false,
                'stock_move' => 'none',
                'notify_buyer' => true,
                'mail_template' => 'order_status',
                'buyer_may_pay' => false,
                'buyer_may_cancel' => false,
                'is_system' => true,
                'position' => 40,
            ],
            [
                'code' => 'cancelled',
                'name' => 'Cancelled',
                'buyer_label' => 'Cancelled',
                'buyer_note' => 'Order stopped; stock can return to warehouse.',
                'tone' => HexColor::TONES['danger'],
                'lane' => 'void',
                'tracker_step' => null,
                'settle_payment' => false,
                'unlock_invoice' => false,
                'unlock_delivery_slip' => false,
                'unlock_credit_slip' => false,
                'stock_move' => 'return',
                'notify_buyer' => true,
                'mail_template' => 'order_status',
                'buyer_may_pay' => false,
                'buyer_may_cancel' => false,
                'is_system' => true,
                'position' => 50,
            ],
            [
                'code' => 'refunded',
                'name' => 'Refunded',
                'buyer_label' => 'Refunded',
                'buyer_note' => 'Payment returned to the customer.',
                'tone' => HexColor::TONES['danger'],
                'lane' => 'reverse',
                'tracker_step' => null,
                'settle_payment' => false,
                'unlock_invoice' => false,
                'unlock_delivery_slip' => false,
                'unlock_credit_slip' => true,
                'stock_move' => 'return',
                'notify_buyer' => true,
                'mail_template' => 'order_status',
                'buyer_may_pay' => false,
                'buyer_may_cancel' => false,
                'is_system' => true,
                'position' => 60,
            ],
        ];
    }

    public static function seed(): void
    {
        OrderStageSchema::ensure();
        PaymentStatusCatalog::ensure();
        SalesChannelCatalog::ensure();

        foreach (self::defaults() as $row) {
            OrderStage::withoutGlobalScope('shop')->firstOrCreate(
                ['code' => $row['code']],
                array_merge($row, [
                    'is_enabled' => true,
                    'notify_staff' => false,
                    'next_stage_ids' => null,
                ])
            );
        }

        OrderStageSchema::backfill();
    }
}
