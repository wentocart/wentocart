<?php

namespace App\Http\Controllers;

use App\Domain\Theme\ThemeRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ThemeAssetController extends Controller
{
    /** @var list<string> */
    protected array $allowed = [
        'css', 'js', 'mjs', 'map', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'ico',
        'woff', 'woff2', 'ttf', 'otf', 'json', 'html', 'txt',
    ];

    public function __invoke(Request $request, ThemeRegistry $themes, string $theme, string $path): BinaryFileResponse
    {
        $code = $themes->canonical($theme);
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            abort(404);
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($ext, $this->allowed, true)) {
            abort(404);
        }

        $candidates = [
            $themes->publicRoot().DIRECTORY_SEPARATOR.$code.DIRECTORY_SEPARATOR.$path,
            ($themes->path($code) ?: '').DIRECTORY_SEPARATOR.$path,
        ];
        foreach ($candidates as $file) {
            if ($file !== DIRECTORY_SEPARATOR.$path && is_file($file)) {
                return response()->file($file, [
                    'Cache-Control' => 'public, max-age=86400',
                ]);
            }
        }

        abort(404);
    }
}
