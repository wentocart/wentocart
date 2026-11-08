<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Localization\LocalizationImportService;
use App\Domain\Localization\LocalizationPackService;
use App\Domain\Localization\Models\LocalizationImportBatch;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LocalizationImportController extends Controller
{
    public function index(LocalizationPackService $packs): Response
    {
        return Inertia::render('Localization/ImportExport', [
            'packs' => $packs->available(),
            'batches' => LocalizationImportBatch::query()->latest()->limit(10)->get(),
        ]);
    }

    public function export(Request $request, LocalizationImportService $imports): StreamedResponse
    {
        $type = $request->string('type')->toString();
        abort_unless(in_array($type, ['countries', 'currencies'], true), 404);

        $csv = $type === 'countries' ? $imports->exportCountriesCsv() : $imports->exportCurrenciesCsv();
        $filename = $type.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function stage(Request $request, LocalizationImportService $imports): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:countries,currencies'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $batch = $imports->stageCsv($data['type'], $request->file('file'));

        return redirect()
            ->route('admin.localization.import')
            ->with('success', "Staged {$batch->row_count} {$batch->type} rows. Review and apply.")
            ->with('import_batch_id', $batch->id);
    }

    public function apply(LocalizationImportBatch $batch, LocalizationImportService $imports): RedirectResponse
    {
        $batch = $imports->apply($batch);
        $result = $batch->result ?? [];

        return back()->with(
            'success',
            "Import applied: {$result['created']} created, {$result['updated']} updated."
            .(empty($result['errors']) ? '' : ' Some rows had errors.')
        );
    }
}
