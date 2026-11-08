<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Security\RasterImageUpload;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EditorUploadController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'image' => RasterImageUpload::requiredRules(5120),
        ]);

        $path = RasterImageUpload::storePublic($request->file('image'), 'editor', 5120);

        return response()->json([
            'url' => asset('storage/'.$path),
        ]);
    }
}
