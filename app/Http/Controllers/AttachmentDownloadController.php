<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\Models\Attachment;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\AttachmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentDownloadController extends Controller
{
    public function __invoke(Request $request, Attachment $attachment, AttachmentService $attachments): StreamedResponse
    {
        $this->authorizeProductDocument($request, $attachment, $attachments);

        $disk = Storage::disk($attachment->disk);
        if (! $attachment->storage_path || ! $disk->exists($attachment->storage_path)) {
            abort(404);
        }

        $safeName = preg_replace('/[^\w.\- ()\[\]]+/u', '_', $attachment->original_filename) ?: 'download';

        return $disk->download($attachment->storage_path, $safeName, [
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
        ]);
    }

    public function preview(Request $request, Attachment $attachment, AttachmentService $attachments): BinaryFileResponse
    {
        $this->authorizeProductDocument($request, $attachment, $attachments);

        if (! str_starts_with((string) $attachment->mime_type, 'image/')) {
            abort(404);
        }

        $path = $attachments->resolvePhysicalPath($attachment);
        if (! $path) {
            abort(404);
        }

        return response()->file($path, [
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
        ]);
    }

    protected function authorizeProductDocument(Request $request, Attachment $attachment, AttachmentService $attachments): Product
    {
        $productId = (int) $request->query('product', 0);
        if ($productId <= 0) {
            abort(404);
        }

        $product = Product::query()->whereKey($productId)->first();
        if (! $product || ! $product->is_enabled) {
            abort(404);
        }

        $link = $product->attachmentLink((int) $attachment->id);
        if (! $link) {
            abort(404);
        }

        // A paid deliverable is only reachable through an issued order download token
        // (OrderDownloadService), never from the public product page.
        if ($attachments->roleOf((string) $link->role, $product) === AttachmentService::ROLE_PAID) {
            abort(403, 'This file is available after purchase from your account downloads.');
        }

        if (! config('wento.attachments.guest_download', false)
            && ! $request->user()
            && ! $request->user('customer')
            && ! $request->user('admin')) {
            abort(403);
        }

        return $product;
    }
}
