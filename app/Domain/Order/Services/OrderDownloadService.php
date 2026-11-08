<?php

namespace App\Domain\Order\Services;

use App\Domain\Catalog\Models\Attachment;
use App\Domain\Catalog\Services\AttachmentService;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderDownload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderDownloadService
{
    public const DEFAULT_MAX_USES = 5;

    public const DEFAULT_EXPIRY_DAYS = 30;

    public const VIRTUAL_TYPES = ['virtual', 'service', 'downloadable'];

    public const HEADER_REMAINING = 'X-Wento-Download-Remaining';

    public const HEADER_USES = 'X-Wento-Download-Uses';

    public const HEADER_MAX_USES = 'X-Wento-Download-Max-Uses';

    public const HEADER_AVAILABLE = 'X-Wento-Download-Available';

    public function issueFor(Order $order): void
    {
        $order->load(['items.product.attachments']);
        $roles = app(AttachmentService::class);

        foreach ($order->items as $item) {
            $product = $item->product;
            if (! $product || ! in_array((string) $product->type, self::VIRTUAL_TYPES, true)) {
                continue;
            }
            foreach ($product->attachments as $file) {
                // Public documents are downloadable from the product page by anyone,
                // so issuing a paid token for them would be pointless noise.
                $role = $roles->roleOf($product->attachmentLink((int) $file->id)?->role, $product);
                if ($role !== AttachmentService::ROLE_PAID) {
                    continue;
                }
                if (OrderDownload::query()->where('order_item_id', $item->id)->where('attachment_id', $file->id)->exists()) {
                    continue;
                }
                OrderDownload::query()->create([
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'product_id' => $product->id,
                    'attachment_id' => $file->id,
                    'token' => bin2hex(random_bytes(20)),
                    'filename' => $file->original_filename ?: $file->name,
                    'max_uses' => self::DEFAULT_MAX_USES,
                    'uses' => 0,
                    'expires_at' => now()->addDays(self::DEFAULT_EXPIRY_DAYS),
                ]);
            }
        }
    }

    /** Whether the order's payment/status allows customers to access issued download tokens. */
    public function orderAllowsDownloads(?Order $order): bool
    {
        if (! $order) {
            return false;
        }

        $status = strtolower((string) $order->status);
        if (in_array($status, ['cancelled', 'canceled', 'failed', 'void'], true)) {
            return false;
        }

        return in_array((string) $order->payment_status, ['paid', 'partial'], true);
    }

    /** @return list<array<string, mixed>> */
    public function presentForOrder(Order $order): array
    {
        if (! $this->orderAllowsDownloads($order)) {
            return [];
        }

        return OrderDownload::query()
            ->with(['attachment.translations', 'product.translations', 'orderItem'])
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get()
            ->map(function (OrderDownload $row) use ($order) {
                // Avoid lazy-load violations; order_item_id may be stale after line edits.
                $row->setRelation('order', $order);

                return $this->present($row, withContext: true);
            })
            ->values()
            ->all();
    }

    /**
     * Rich listing rows for the account downloads page and PDP/order detail.
     *
     * @return list<array<string, mixed>>
     */
    public function listForCustomer(\App\Domain\Customer\Models\Customer $customer): array
    {
        $rows = OrderDownload::query()
            ->with([
                'attachment.translations',
                'order',
                'orderItem',
                'product.translations',
                'product.brand.translations',
            ])
            ->whereIn('order_id', $customer->orders()->select('id'))
            ->orderByDesc('id')
            ->get()
            // Tokens may exist before payment; hide rows the customer cannot use yet.
            ->filter(fn (OrderDownload $row) => $this->orderAllowsDownloads($row->order))
            ->values();

        $locale = app()->getLocale();

        return $rows
            ->map(fn (OrderDownload $row) => $this->present($row, $locale, withContext: true))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function present(OrderDownload $row, ?string $locale = null, bool $withContext = false): array
    {
        $locale = $locale ?: app()->getLocale();
        $attachment = $this->attachmentForDownload($row, persistRebind: true);
        $expiresAt = $row->expires_at;
        $daysLeft = $expiresAt !== null && ! $row->isExpired()
            ? max(0, (int) now()->diffInDays($expiresAt, false))
            : 0;

        $isImage = str_starts_with((string) $attachment?->mime_type, 'image/');
        $entitled = $row->isAvailable() && $this->orderAllowsDownloads($row->relationLoaded('order') ? $row->order : $row->order()->first());
        $fileReady = $attachment !== null && $this->storagePathExists($attachment);
        $translation = ($attachment && $attachment->relationLoaded('translations'))
            ? $attachment->translation($locale)
            : null;

        $data = [
            'id' => (int) $row->id,
            'token' => $row->token,
            'filename' => $row->filename,
            'original_filename' => $attachment?->original_filename ?: $row->filename,
            'name' => $translation?->name ?: ($attachment?->name ?: $row->filename),
            'description' => $translation?->description ?: $attachment?->description,
            'mime_type' => $attachment?->mime_type,
            'file_type_label' => $attachment ? AttachmentService::fileTypeLabel($attachment) : null,
            'file_size' => (int) ($attachment?->file_size ?? 0),
            'file_size_label' => AttachmentService::formatSize((int) ($attachment?->file_size ?? 0)),
            'is_image' => $isImage,
            'max_uses' => (int) $row->max_uses,
            'uses' => (int) $row->uses,
            'remaining' => $row->remaining(),
            'expires_at' => $expiresAt?->toIso8601String(),
            'days_left' => $daysLeft,
            'expired' => $row->isExpired() || ! $fileReady,
            'available' => $entitled && $fileReady,
            'download_url' => url('/api/v1/account/downloads/'.$row->token),
            // Token-gated inline preview — never a public attachment URL.
            'preview_url' => $isImage && $fileReady
                ? url('/api/v1/account/downloads/'.$row->token.'/preview')
                : null,
        ];

        if (! $withContext) {
            return $data;
        }

        $order = $row->order;
        $product = $row->product;
        $presenter = app(CatalogPresenter::class);

        return [
            ...$data,
            'order' => $order ? [
                'id' => (int) $order->id,
                'number' => $order->number,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'created_at' => $order->created_at?->toIso8601String(),
                'url' => '/account/orders/'.$order->number,
            ] : null,
            'product' => $product ? [
                'id' => (int) $product->id,
                'name' => $product->translation($locale)?->name ?: $product->sku,
                'sku' => $product->sku,
                'type' => $product->type,
                'url' => $presenter->product($product, $locale)['url'] ?? null,
                'cover_url' => $presenter->product($product, $locale)['cover_url'] ?? null,
            ] : null,
            'order_item' => $row->orderItem ? [
                'id' => (int) $row->orderItem->id,
                'qty' => (float) $row->orderItem->qty,
                'name' => $row->orderItem->name,
            ] : null,
        ];
    }

    public function download(OrderDownload $row): StreamedResponse
    {
        [$file, $locked] = DB::transaction(function () use ($row) {
            /** @var OrderDownload $locked */
            $locked = OrderDownload::query()
                ->whereKey($row->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($row->relationLoaded('order')) {
                $locked->setRelation('order', $row->order);
            } else {
                $locked->loadMissing('order');
            }

            $this->assertCustomerAccess($locked);
            $file = $this->resolveAttachmentFile($locked);
            $locked->increment('uses');
            $locked->refresh();

            return [$file, $locked];
        });

        $disk = $file->disk ?: 'local';
        $response = Storage::disk($disk)->download(
            (string) $file->storage_path,
            $locked->filename ?: basename((string) $file->storage_path),
        );
        $this->applyEntitlementHeaders($response, $locked);

        return $response;
    }

    protected function applyEntitlementHeaders(Response $response, OrderDownload $row): void
    {
        $order = $row->relationLoaded('order') ? $row->order : $row->order()->first();
        $available = $row->isAvailable() && $this->orderAllowsDownloads($order);

        $response->headers->set(self::HEADER_REMAINING, (string) $row->remaining());
        $response->headers->set(self::HEADER_USES, (string) (int) $row->uses);
        $response->headers->set(self::HEADER_MAX_USES, (string) (int) $row->max_uses);
        $response->headers->set(self::HEADER_AVAILABLE, $available ? '1' : '0');
    }

    public function preview(OrderDownload $row): BinaryFileResponse
    {
        $this->assertCustomerAccess($row);
        $file = $this->resolveAttachmentFile($row);
        if (! str_starts_with((string) $file->mime_type, 'image/')) {
            abort(404);
        }

        $path = app(AttachmentService::class)->resolvePhysicalPath($file);
        if (! $path) {
            abort(404, 'File is missing.');
        }

        return response()->file($path, [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
        ]);
    }

    protected function assertCustomerAccess(OrderDownload $row): void
    {
        $order = $row->relationLoaded('order') ? $row->order : $row->order()->first();
        if (! $this->orderAllowsDownloads($order)) {
            abort(403, 'This download is not available for this order.');
        }
        if (! $row->isAvailable()) {
            abort(410, 'This download is no longer available.');
        }
    }

    protected function resolveAttachmentFile(OrderDownload $row): Attachment
    {
        $file = $this->attachmentForDownload($row, persistRebind: true);
        if (! $file) {
            abort(404, 'File is missing.');
        }
        if (! $this->storagePathExists($file)) {
            abort(404, 'File is missing.');
        }

        return $file;
    }

    /**
     * Resolve the attachment for a download token. When the stored attachment_id
     * was deleted/replaced, rebind to the product's current paid file with the
     * same original filename so existing order tokens keep working.
     */
    protected function attachmentForDownload(OrderDownload $row, bool $persistRebind = false): ?Attachment
    {
        $file = $row->relationLoaded('attachment') ? $row->attachment : null;
        if (! $file && $row->attachment_id) {
            $file = Attachment::query()->find($row->attachment_id);
            if ($file) {
                $row->setRelation('attachment', $file);
            }
        }

        if ($file) {
            return $file;
        }

        return $this->rebindAttachment($row, $persistRebind);
    }

    protected function rebindAttachment(OrderDownload $row, bool $persist = false): ?Attachment
    {
        $filename = trim((string) $row->filename);
        if ($filename === '' || ! $row->product_id) {
            return null;
        }

        $candidates = Attachment::query()
            ->whereHas('products', function ($query) use ($row) {
                $query->where('products.id', $row->product_id)
                    ->where('attachment_product.role', AttachmentService::ROLE_PAID);
            })
            ->where(function ($query) use ($filename) {
                $query->where('original_filename', $filename)
                    ->orWhere('stored_filename', $filename)
                    ->orWhere('name', $filename);
            })
            ->orderByDesc('id')
            ->get();

        $match = $candidates->first(fn (Attachment $candidate) => $this->storagePathExists($candidate))
            ?? $candidates->first();

        if (! $match) {
            return null;
        }

        if ($persist && (int) $row->attachment_id !== (int) $match->id) {
            $row->forceFill(['attachment_id' => $match->id])->save();
        }
        $row->setRelation('attachment', $match);

        return $match;
    }

    protected function storagePathExists(Attachment $file): bool
    {
        $path = (string) $file->storage_path;
        if ($path === '') {
            return false;
        }

        $disk = $file->disk ?: 'local';

        try {
            return Storage::disk($disk)->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }
}
