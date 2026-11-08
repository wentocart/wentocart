<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Ops\CommandIndex;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommandPaletteController extends Controller
{
    public function search(Request $request, CommandIndex $index): JsonResponse
    {
        $admin = $request->user('admin');
        if (! $admin instanceof Admin) {
            abort(403);
        }

        return response()->json($index->search((string) $request->query('q', ''), $admin));
    }
}
