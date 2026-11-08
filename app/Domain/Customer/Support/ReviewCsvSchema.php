<?php

namespace App\Domain\Customer\Support;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Support\CatalogCsvImport;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\Review;
use App\Domain\Settings\AdminCsv;

final class ReviewCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return ['product_sku', 'customer_email', 'rating', 'title', 'body', 'status'];
    }

    /** @return list<string> */
    public static function required(): array
    {
        return ['product_sku', 'customer_email', 'rating'];
    }

    /** @return list<array<string, mixed>> */
    public static function samples(): array
    {
        return [
            [
                'product_sku' => 'DEMO-SKU',
                'customer_email' => 'jane.shopper@example.com',
                'rating' => '5',
                'title' => 'Great quality',
                'body' => 'Arrived quickly and matches the listing.',
                'status' => 'approved',
            ],
            [
                'product_sku' => 'DEMO-SKU',
                'customer_email' => 'alex.wholesale@example.com',
                'rating' => '4',
                'title' => 'Solid product',
                'body' => 'Would buy again for the shop floor.',
                'status' => 'pending',
            ],
        ];
    }

    public static function sampleCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        return AdminCsv::stringify(self::columns(), self::samples(), $delimiter);
    }

    public static function exportCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        $rows = Review::query()
            ->with(['product:id,sku', 'customer:id,email'])
            ->orderByDesc('id')
            ->limit(10000)
            ->get()
            ->map(fn (Review $review) => [
                'product_sku' => $review->product?->sku,
                'customer_email' => $review->customer?->email,
                'rating' => $review->rating,
                'title' => $review->title,
                'body' => $review->body,
                'status' => $review->status,
            ])
            ->all();

        return AdminCsv::stringify(self::columns(), $rows, $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        $parsed = AdminCsv::parse($contents, self::columns(), 'Review', $delimiter, self::required());
        $errors = [];
        $count = 0;

        foreach ($parsed['rows'] as $index => $row) {
            $sku = AdminCsv::get($row, 'product_sku');
            $email = strtolower(AdminCsv::get($row, 'customer_email'));
            $rating = AdminCsv::int(AdminCsv::get($row, 'rating'), 0) ?: 0;
            $product = Product::query()->where('sku', $sku)->first();
            $customer = Customer::query()->where('email', $email)->first();
            $line = CatalogCsvImport::rowNumber($index);
            if (! $product) {
                $errors[] = "Row {$line}: unknown product SKU \"{$sku}\".";
                continue;
            }
            if (! $customer) {
                $errors[] = "Row {$line}: unknown customer email \"{$email}\".";
                continue;
            }
            if ($rating < 1 || $rating > 5) {
                $errors[] = "Row {$line}: rating must be between 1 and 5.";
                continue;
            }
            $status = strtolower(AdminCsv::get($row, 'status') ?: 'pending');
            if (! in_array($status, [Review::STATUS_PENDING, Review::STATUS_APPROVED, Review::STATUS_REJECTED], true)) {
                $status = Review::STATUS_PENDING;
            }
            Review::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'customer_id' => $customer->id,
                ],
                [
                    'rating' => $rating,
                    'title' => AdminCsv::nullable(AdminCsv::get($row, 'title')),
                    'body' => AdminCsv::get($row, 'body'),
                    'status' => $status,
                ]
            );
            $count++;
        }

        CatalogCsvImport::throwRowErrors($errors);

        return $count;
    }
}
