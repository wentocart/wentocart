<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UiKitController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('UiKit', [
            'previewUrl' => session('ui_kit_preview'),
        ]);
    }

    public function flash(Request $request): RedirectResponse
    {
        $type = $request->validate([
            'type' => ['required', 'in:success,error,warning,info'],
        ])['type'];

        $copy = [
            'success' => 'Saved. In a real page: return back()->with(\'success\', \'…\'); Layout renders <Flash />.',
            'error' => 'Save failed. In a real page: return back()->with(\'error\', \'…\');',
            'warning' => 'Something needs attention. In a real page: return back()->with(\'warning\', \'…\');',
            'info' => 'Heads up. In a real page: return back()->with(\'info\', \'…\');',
        ];

        return back()->with($type, $copy[$type]);
    }

    public function attempt(Request $request): RedirectResponse
    {
        $request->validate([
            'title' => ['required', 'string', 'min:3'],
            'body' => ['required', 'string'],
        ]);

        return back()->with('success', 'Dummy save succeeded. Empty the fields and try again to see FormErrors.');
    }

    public function upload(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'image' => ['required', 'image', 'max:2048'],
        ]);

        $path = $data['image']->store('ui-kit', 'public');

        return back()
            ->with('success', 'Image stored. Catalog should use the same ImageField + store() pattern.')
            ->with('ui_kit_preview', asset('storage/'.$path));
    }
}
