<?php

namespace App\Domain\Health;

use App\Domain\Ai\ContentWriter;
use App\Domain\Catalog\Models\Product;
use App\Domain\Health\Models\HealthAiProposal;
use App\Domain\Health\Models\HealthFinding;
use App\Domain\Localization\Models\Language;
use App\Domain\Store\StoreContext;
use App\Models\Admin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class AiHealthService
{
    public function generateForFinding(HealthFinding $finding, int $limit = 20): array
    {
        $settings = HealthSettings::all();
        if (! ($settings['ai_enabled'] ?? true)) {
            throw new RuntimeException('AI suggestions are disabled in Store Health settings.');
        }

        $task = match ($finding->rule_code) {
            'seo.missing_title', 'seo.title_too_short' => 'meta_title',
            'seo.missing_description', 'seo.description_too_short', 'seo.duplicate_description' => 'meta_description',
            'content.missing_keywords' => 'meta_keywords',
            'content.missing_focus' => 'focus_keyword',
            'images.missing_alt' => 'image_alt',
            'content.missing_description', 'content.thin_description' => 'product_description',
            default => null,
        };
        if ($task === null) {
            throw new RuntimeException('AI cannot generate a fix for this issue yet.');
        }

        $locale = Language::defaultCode();
        $products = Product::query()->with('brand')->where('is_enabled', true)->limit(max(50, $limit * 3))->get();
        $created = [];
        foreach ($products as $product) {
            $tr = $product->translation($locale);
            if (! $tr) {
                continue;
            }
            $needs = match ($task) {
                'meta_title' => trim((string) $tr->meta_title) === '',
                'meta_description' => trim((string) $tr->meta_description) === '' || mb_strlen((string) $tr->meta_description) < 70,
                'meta_keywords' => trim((string) $tr->meta_keywords) === '',
                'focus_keyword' => trim((string) ($tr->focus_keyword ?? '')) === '',
                'product_description' => mb_strlen(strip_tags((string) $tr->description)) < 80,
                'image_alt' => true,
                default => false,
            };
            if (! $needs && $task !== 'image_alt') {
                continue;
            }

            $context = [
                'product_name' => (string) $tr->name,
                'sku' => (string) $product->sku,
                'brand' => (string) ($product->brand?->name ?? ''),
            ];
            $current = match ($task) {
                'meta_title' => (string) $tr->meta_title,
                'meta_description' => (string) $tr->meta_description,
                'meta_keywords' => (string) $tr->meta_keywords,
                'focus_keyword' => (string) ($tr->focus_keyword ?? ''),
                'product_description' => strip_tags((string) ($tr->short_description ?: $tr->description)),
                default => '',
            };

            try {
                $generated = app(ContentWriter::class)->write([
                    'action' => 'seo',
                    'field' => $task === 'product_description' ? 'short_description' : $task,
                    'text' => $current !== '' ? $current : (string) $tr->name,
                    'locale' => $locale,
                    'context' => $context,
                ]);
            } catch (Throwable $e) {
                $created[] = ['error' => $e->getMessage(), 'product_id' => $product->id];
                continue;
            }

            $admin = Auth::guard('admin')->user();
            $proposal = HealthAiProposal::query()->create([
                'uuid' => (string) Str::uuid(),
                'store_id' => app(StoreContext::class)->id() ?: null,
                'resource_type' => 'PRODUCT',
                'resource_id' => $product->id,
                'task_type' => $task,
                'status' => 'generated',
                'input_context' => $context,
                'current_content' => $current,
                'generated_output' => $generated,
                'approved_by' => null,
            ]);
            $created[] = [
                'id' => $proposal->id,
                'resource_id' => $product->id,
                'label' => (string) $tr->name,
                'current' => $current,
                'suggested' => $generated,
                'task_type' => $task,
                'status' => 'generated',
            ];
            if (count($created) >= $limit) {
                break;
            }
        }

        return $created;
    }

    public function applyProposal(HealthAiProposal $proposal): HealthAiProposal
    {
        if (! in_array($proposal->status, ['generated', 'approved'], true)) {
            throw new RuntimeException('This AI proposal cannot be applied.');
        }
        $settings = HealthSettings::all();
        if (($settings['ai_require_approval'] ?? true) && $proposal->status !== 'approved') {
            throw new RuntimeException('Approve the AI suggestion before applying.');
        }

        $product = Product::query()->findOrFail($proposal->resource_id);
        $locale = Language::defaultCode();
        $tr = $product->translation($locale);
        if (! $tr) {
            throw new RuntimeException('Product translation missing.');
        }
        $value = trim((string) ($proposal->edited_output ?: $proposal->generated_output));
        $field = match ($proposal->task_type) {
            'meta_title' => 'meta_title',
            'meta_description' => 'meta_description',
            'meta_keywords' => 'meta_keywords',
            'focus_keyword' => 'focus_keyword',
            'product_description', 'short_description' => 'short_description',
            default => null,
        };
        if (! $field) {
            throw new RuntimeException('Unsupported AI task.');
        }
        $tr->update([$field => $value]);
        $admin = Auth::guard('admin')->user();
        $proposal->update([
            'status' => 'applied',
            'applied_at' => now(),
            'approved_by' => $admin instanceof Admin ? $admin->id : $proposal->approved_by,
        ]);
        HealthOverview::flush();

        return $proposal->fresh() ?? $proposal;
    }

    public function approve(HealthAiProposal $proposal): HealthAiProposal
    {
        $admin = Auth::guard('admin')->user();
        $proposal->update([
            'status' => 'approved',
            'approved_by' => $admin instanceof Admin ? $admin->id : null,
        ]);

        return $proposal->fresh() ?? $proposal;
    }
}
