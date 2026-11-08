<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CloneHomeController extends Controller
{
    public function __invoke(Request $request, ?string $path = null): BinaryFileResponse|Response
    {
        $path = str_replace('\\', '/', rawurldecode((string) $path));
        $path = ltrim($path, '/');
        if ($path === '' || $path === 'en' || $path === 'en/') {
            $path = 'en/index.html';
        }

        $root = realpath(storage_path('app/clone-home'));
        abort_unless($root, 404);

        $file = $this->resolveFile($root, $path, $request->getQueryString());
        abort_unless($file, 404);

        if (str_ends_with(strtolower($file), '.html') || str_ends_with(strtolower($file), '.htm')) {
            $html = (string) file_get_contents($file);
            if (! str_contains($html, '<base ')) {
                $html = (string) preg_replace('/<head>/i', '<head><base href="/clone/en/">', $html, 1);
            }

            return response($html, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'public, max-age=3600',
            ]);
        }

        return response()->file($file, [
            'Content-Type' => $this->mime($file),
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    protected function resolveFile(string $root, string $path, ?string $query): ?string
    {
        $candidates = [$path];
        if (is_string($query) && $query !== '') {
            $candidates[] = $path.'%3F'.$query;
            $candidates[] = $path.'?'.$query;
        }

        foreach ($candidates as $relative) {
            $full = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));
            if ($full && is_file($full) && str_starts_with($full, $root.DIRECTORY_SEPARATOR)) {
                return $full;
            }
        }

        return null;
    }

    protected function mime(string $file): string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (str_contains($ext, '%3f')) {
            $ext = strtolower((string) strstr($ext, '%3f', true));
        }
        if (str_contains($ext, '?')) {
            $ext = strtolower((string) strstr($ext, '?', true));
        }

        return match ($ext) {
            'html', 'htm' => 'text/html; charset=UTF-8',
            'css' => 'text/css; charset=UTF-8',
            'js', 'mjs' => 'text/javascript; charset=UTF-8',
            'json' => 'application/json',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
            'map' => 'application/json',
            default => (string) (mime_content_type($file) ?: 'application/octet-stream'),
        };
    }
}
