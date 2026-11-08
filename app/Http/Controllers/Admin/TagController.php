<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Tag;
use App\Domain\Localization\Models\Language;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TagController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string']]);
        $tag = Tag::query()->create();
        $tag->translations()->create([
            'language_code' => Language::defaultCode(),
            'name' => $data['name'],
            'slug' => Str::slug($data['name']),
        ]);

        return back()->with('success', 'Tag created.');
    }
}
