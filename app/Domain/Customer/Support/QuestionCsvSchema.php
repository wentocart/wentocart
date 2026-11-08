<?php

namespace App\Domain\Customer\Support;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Support\CatalogCsvImport;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\ProductQuestion;
use App\Domain\Settings\AdminCsv;

final class QuestionCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return ['product_sku', 'customer_email', 'body', 'status', 'answer'];
    }

    /** @return list<string> */
    public static function required(): array
    {
        return ['product_sku', 'customer_email', 'body'];
    }

    /** @return list<array<string, mixed>> */
    public static function samples(): array
    {
        return [
            [
                'product_sku' => 'DEMO-SKU',
                'customer_email' => 'jane.shopper@example.com',
                'body' => 'Does this include a power adapter?',
                'status' => 'approved',
                'answer' => 'Yes, a region-ready adapter is in the box.',
            ],
        ];
    }

    public static function sampleCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        return AdminCsv::stringify(self::columns(), self::samples(), $delimiter);
    }

    public static function exportCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        $rows = ProductQuestion::query()
            ->with(['product:id,sku', 'customer:id,email', 'answers'])
            ->orderByDesc('id')
            ->limit(10000)
            ->get()
            ->map(fn (ProductQuestion $question) => [
                'product_sku' => $question->product?->sku,
                'customer_email' => $question->customer?->email,
                'body' => $question->body,
                'status' => $question->status,
                'answer' => $question->answers->first()?->body,
            ])
            ->all();

        return AdminCsv::stringify(self::columns(), $rows, $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        $parsed = AdminCsv::parse($contents, self::columns(), 'Question', $delimiter, self::required());
        $errors = [];
        $count = 0;

        foreach ($parsed['rows'] as $index => $row) {
            $sku = AdminCsv::get($row, 'product_sku');
            $email = strtolower(AdminCsv::get($row, 'customer_email'));
            $body = AdminCsv::get($row, 'body');
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
            if ($body === '') {
                $errors[] = "Row {$line}: question body is required.";
                continue;
            }
            $status = strtolower(AdminCsv::get($row, 'status') ?: 'pending');
            if (! in_array($status, [ProductQuestion::STATUS_PENDING, ProductQuestion::STATUS_APPROVED, ProductQuestion::STATUS_REJECTED], true)) {
                $status = ProductQuestion::STATUS_PENDING;
            }
            $question = ProductQuestion::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'customer_id' => $customer->id,
                    'body' => $body,
                ],
                ['status' => $status]
            );
            $answer = AdminCsv::get($row, 'answer');
            if ($answer !== '') {
                $question->answers()->updateOrCreate(
                    ['is_official' => true],
                    [
                        'body' => $answer,
                        'is_official' => true,
                        'is_approved' => true,
                    ]
                );
            }
            $count++;
        }

        CatalogCsvImport::throwRowErrors($errors);

        return $count;
    }
}
