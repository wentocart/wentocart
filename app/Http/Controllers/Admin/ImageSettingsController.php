<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Services\ImageOptimizer;
use App\Domain\Settings\Models\FileChangeLog;
use App\Domain\Settings\ShopSetting;
use App\Domain\Settings\ShopTools;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ImageSettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Settings/Images', [
            'types' => ShopTools::imageTypes(),
            'webp' => ShopTools::webpEnabled(),
            'gd' => function_exists('imagecreatetruecolor'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'webp' => ['required', 'boolean'],
            'types' => ['required', 'array', 'min:1'],
            'types.*.id' => ['required', 'string', 'max:40'],
            'types.*.max_width' => ['required', 'integer', 'min:16', 'max:4000'],
            'types.*.max_height' => ['required', 'integer', 'min:16', 'max:4000'],
        ]);

        ShopSetting::put('images.webp', (bool) $data['webp']);
        ShopSetting::put('images.types', $data['types']);
        FileChangeLog::record('updated', 'images/types');

        return back()->with('success', 'Image types saved.');
    }

    public function regenerate(Request $request, ImageOptimizer $images): RedirectResponse
    {
        $data = $request->validate([
            'missing_only' => ['nullable', 'boolean'],
        ]);

        $result = $images->regenerate((bool) ($data['missing_only'] ?? false));
        FileChangeLog::record('regenerated', 'catalog/images');

        return back()->with('success', 'Regenerated '.$result['files'].' files from '.$result['processed'].' images.');
    }
}
