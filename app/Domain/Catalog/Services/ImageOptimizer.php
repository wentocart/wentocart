<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Settings\ObjectStorageSettings;
use App\Domain\Settings\ShopTools;
use Illuminate\Contracts\Filesystem\Filesystem;
use Throwable;

class ImageOptimizer
{
    /**
     * Decompression-bomb guard: GD decodes the full bitmap (w*h*4 bytes), so
     * refuse absurd inputs before imagecreatefromstring() can OOM the worker.
     * A 24MP camera photo (≈6000x4000, <10MB) still passes comfortably.
     */
    protected const MAX_BYTES = 20 * 1024 * 1024;

    protected const MAX_SIDE_PX = 8000;

    protected const MAX_PIXELS = 25000000;

    public function generate(?string $path): int
    {
        if (! is_string($path) || $path === '' || ! $this->gdReady()) {
            return 0;
        }

        $disk = $this->disk();
        if (! $disk->exists($path)) {
            return 0;
        }

        $source = $this->readBytes($disk, $path);
        if ($source === null || $this->tooLargeBytes($source)) {
            return 0;
        }

        $image = @imagecreatefromstring($source);
        if ($image === false) {
            return 0;
        }

        $srcW = imagesx($image);
        $srcH = imagesy($image);
        $count = 0;

        foreach (ShopTools::imageTypes() as $type) {
            $dest = $this->derivativePath($path, $type['id']);
            if ($this->writeSized($disk, $image, $srcW, $srcH, $dest, (int) $type['max_width'], (int) $type['max_height'])) {
                $count++;
            }
        }

        imagedestroy($image);

        return $count;
    }

    /**
     * Create missing sized/WebP derivatives. Returns paths that did not exist before.
     *
     * @return list<string>
     */
    public function generateMissing(?string $path): array
    {
        if (! is_string($path) || $path === '' || ! $this->gdReady()) {
            return [];
        }
        $disk = $this->disk();
        if (! $disk->exists($path)) {
            return [];
        }
        $pending = [];
        foreach (ShopTools::imageTypes() as $type) {
            $dest = $this->derivativePath($path, $type['id']);
            if (! $disk->exists($dest)) {
                $pending[] = $dest;
            }
        }
        if ($pending === []) {
            return [];
        }
        $this->generate($path);

        return array_values(array_filter($pending, fn (string $dest) => $disk->exists($dest)));
    }

    public function missingCount(int $cap = 501): int
    {
        if (! $this->gdReady()) {
            return 0;
        }
        $n = 0;
        ProductImage::query()->orderBy('id')->limit($cap)->each(function (ProductImage $image) use (&$n) {
            if (! $this->hasAllDerivatives((string) $image->path)) {
                $n++;
            }
        });

        return $n;
    }

    public function gdAvailable(): bool
    {
        return $this->gdReady();
    }

    /**
     * @return array{processed: int, files: int}
     */
    public function regenerate(bool $missingOnly = false): array
    {
        $processed = 0;
        $files = 0;

        ProductImage::query()->orderBy('id')->chunkById(50, function ($images) use ($missingOnly, &$processed, &$files) {
            foreach ($images as $image) {
                $processed++;
                if ($missingOnly && $this->hasAllDerivatives($image->path)) {
                    continue;
                }
                $files += $this->generate($image->path);
            }
        });

        return ['processed' => $processed, 'files' => $files];
    }

    public function publicPath(?string $path, string $type = 'home'): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }
        $derived = $this->derivativePath($path, $type);
        if ($this->disk()->exists($derived)) {
            return $derived;
        }

        return $path;
    }

    public function derivativePath(string $path, string $type): string
    {
        $info = pathinfo($path);
        $dir = $info['dirname'] ?? '';
        $name = $info['filename'] ?? 'image';
        $ext = ShopTools::webpEnabled() && function_exists('imagewebp') ? 'webp' : 'jpg';
        $file = $name.'-'.$type.'.'.$ext;

        return ($dir === '.' || $dir === '') ? $file : $dir.'/'.$file;
    }

    protected function hasAllDerivatives(string $path): bool
    {
        $disk = $this->disk();
        foreach (ShopTools::imageTypes() as $type) {
            if (! $disk->exists($this->derivativePath($path, $type['id']))) {
                return false;
            }
        }

        return true;
    }

    protected function writeSized(Filesystem $disk, \GdImage $source, int $srcW, int $srcH, string $dest, int $maxW, int $maxH): bool
    {
        $ratio = min($maxW / max($srcW, 1), $maxH / max($srcH, 1), 1);
        $w = max(1, (int) round($srcW * $ratio));
        $h = max(1, (int) round($srcH * $ratio));
        $canvas = imagecreatetruecolor($w, $h);
        if ($canvas === false) {
            return false;
        }
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $w, $h, $transparent);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $w, $h, $srcW, $srcH);

        $ok = false;
        $bytes = null;
        try {
            ob_start();
            if (str_ends_with($dest, '.webp') && function_exists('imagewebp')) {
                $ok = imagewebp($canvas, null, 82);
            } else {
                $ok = imagejpeg($canvas, null, 86);
            }
            $bytes = ob_get_clean();
        } catch (Throwable) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            $ok = false;
            $bytes = null;
        }
        imagedestroy($canvas);

        if (! $ok || ! is_string($bytes) || $bytes === '') {
            return false;
        }

        try {
            $disk->put($dest, $bytes);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    protected function disk(): Filesystem
    {
        return ObjectStorageSettings::disk();
    }

    protected function readBytes(Filesystem $disk, string $path): ?string
    {
        try {
            $source = $disk->get($path);
        } catch (Throwable) {
            return null;
        }

        return is_string($source) && $source !== '' ? $source : null;
    }

    protected function gdReady(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagecreatefromstring');
    }

    /**
     * Header-only size probe via getimagesizefromstring — safe on untrusted bytes.
     */
    protected function tooLargeBytes(string $source): bool
    {
        if (strlen($source) > self::MAX_BYTES) {
            return true;
        }
        $size = @getimagesizefromstring($source);
        if (! is_array($size)) {
            return false;
        }
        [$w, $h] = [(int) ($size[0] ?? 0), (int) ($size[1] ?? 0)];
        if ($w <= 0 || $h <= 0) {
            return true;
        }

        return $w > self::MAX_SIDE_PX || $h > self::MAX_SIDE_PX || ($w * $h) > self::MAX_PIXELS;
    }
}
