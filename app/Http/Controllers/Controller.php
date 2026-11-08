<?php

namespace App\Http\Controllers;

use App\Domain\Settings\AdminCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

abstract class Controller
{
    protected function csvDownload(string $filename, string $csv): StreamedResponse
    {
        return AdminCsv::download($filename, $csv);
    }

    protected function csvImport(Request $request, callable $importer, string $label): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:8192'],
            'delimiter' => ['nullable', 'string', 'max:8'],
        ]);
        $delimiter = trim((string) $request->input('delimiter', '')) ?: null;
        $count = $importer((string) $request->file('file')->getContent(), $delimiter);

        return back()->with('success', "Imported {$count} {$label}.");
    }
}
