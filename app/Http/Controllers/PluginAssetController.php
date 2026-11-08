<?php

namespace App\Http\Controllers;

use App\Domain\Plugin\PluginRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class PluginAssetController extends Controller
{
    public function __invoke(Request $request, PluginRegistry $registry, string $vendor, string $name, string $path): Response
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            abort(404);
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($ext, ['svg', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'css', 'js', 'mjs', 'woff2'], true)) {
            abort(404);
        }
        if (in_array($ext, ['js', 'mjs'], true) && ! str_starts_with($path, 'assets/')) {
            abort(404);
        }
        $manifest = $registry->find($vendor, $name);
        if (! $manifest) {
            abort(404);
        }
        $full = $manifest->path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        $real = realpath($full);
        $root = realpath($manifest->path);
        if (! $real || ! $root || ! str_starts_with($real, $root) || ! is_file($real)) {
            abort(404);
        }

        $response = new BinaryFileResponse($real);
        $mime = match ($ext) {
            'css' => 'text/css; charset=utf-8',
            'js', 'mjs' => 'application/javascript; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'woff2' => 'font/woff2',
            default => null,
        };
        if ($mime !== null) {
            $response->headers->set('Content-Type', $mime);
        }
        // Catalog logos (preview.*) must render in <img>. Other SVGs stay attachment+CSP to blunt XSS.
        $isPreviewImage = (bool) preg_match('/^preview\.(svg|png|jpe?g|webp|gif)$/i', $path);
        if ($ext === 'svg') {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
            if (! $isPreviewImage) {
                $response->headers->set('Content-Disposition', 'attachment; filename="'.basename($real).'"');
            } else {
                $response->headers->set('Content-Disposition', 'inline; filename="'.basename($real).'"');
            }
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
