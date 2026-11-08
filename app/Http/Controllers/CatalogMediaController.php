<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\Support\DefaultImage;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves catalog files from storage/app/public when the public/storage link
 * is missing. Never depends on an Apache rewrite of /storage.
 */
class CatalogMediaController extends Controller
{
    public function show(string $path): BinaryFileResponse
    {
        $relative = str_replace('\\', '/', $path);
        $relative = ltrim($relative, '/');
        if ($relative === '' || str_contains($relative, '..')) {
            return $this->placeholder();
        }

        $full = storage_path('app/public/'.$relative);
        $realBase = realpath(storage_path('app/public'));
        $realFile = is_file($full) ? realpath($full) : false;
        if (
            is_string($realBase)
            && is_string($realFile)
            && str_starts_with(strtolower($realFile), strtolower($realBase))
        ) {
            return response()->file($realFile);
        }

        return $this->placeholder();
    }

    private function placeholder(): BinaryFileResponse
    {
        $file = public_path(ltrim(DefaultImage::path(DefaultImage::MD), '/'));
        if (! is_file($file)) {
            $file = public_path('images/default/p-md-default.png');
        }

        abort_unless(is_file($file), 404);

        return response()->file($file);
    }
}
