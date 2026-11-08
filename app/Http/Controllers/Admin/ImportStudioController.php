<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Support\CatalogCsvImport;
use App\Domain\Ops\Operator;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Throwable;

class ImportStudioController extends Controller
{
    public const SESSION_KEY = 'ops.import.draft';

    /** @var list<string> */
    public const FIELDS = ['sku', 'name', 'price', 'stock_qty', 'is_enabled', 'short_description'];

    public function index(Request $request): Response
    {
        return Inertia::render('Ops/Import', [
            'fields' => self::FIELDS,
            'draft' => $request->session()->get(self::SESSION_KEY),
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:8192'],
            'delimiter' => ['nullable', 'string', 'max:8'],
        ]);
        $delimiter = $data['delimiter'] ?: ',';
        if ($delimiter === 'tab') {
            $delimiter = "\t";
        }
        $parsed = CatalogCsvImport::parse($request->file('file')->getContent(), $delimiter);
        CatalogCsvImport::assertHasDataRows($parsed['rows'], 'Product');

        $headers = $parsed['header'];
        $mapping = [];
        foreach (self::FIELDS as $field) {
            $match = collect($headers)->first(fn ($h) => strtolower((string) $h) === $field);
            $mapping[$field] = $match ?: '';
        }

        $request->session()->put(self::SESSION_KEY, [
            'headers' => $headers,
            'rows' => array_slice($parsed['rows'], 0, 500),
            'mapping' => $mapping,
            'errors' => [],
        ]);

        return back()->with('success', count($parsed['rows']).' rows loaded. Map columns next.');
    }

    public function map(Request $request): RedirectResponse
    {
        $draft = $request->session()->get(self::SESSION_KEY);
        if (! is_array($draft)) {
            return back()->with('error', 'Upload a CSV first.');
        }
        $mapping = is_array($request->input('mapping')) ? $request->input('mapping') : [];
        $draft['mapping'] = $mapping;
        $errors = [];
        foreach (['sku', 'name'] as $required) {
            if (trim((string) ($mapping[$required] ?? '')) === '') {
                $errors[] = 'Map the '.$required.' column.';
            }
        }
        $draft['errors'] = $errors;
        $request->session()->put(self::SESSION_KEY, $draft);
        if ($errors !== []) {
            return back()->with('error', implode(' ', $errors));
        }

        return back()->with('success', 'Columns mapped. Preview or import when ready.');
    }

    public function preview(Request $request, Operator $operator): RedirectResponse
    {
        return $this->run($request, $operator, true);
    }

    public function execute(Request $request, Operator $operator): RedirectResponse
    {
        return $this->run($request, $operator, false);
    }

    public function clear(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return back()->with('success', 'Import draft cleared.');
    }

    protected function run(Request $request, Operator $operator, bool $dry): RedirectResponse
    {
        $draft = $request->session()->get(self::SESSION_KEY);
        if (! is_array($draft)) {
            return back()->with('error', 'Upload a CSV first.');
        }
        $rows = $this->mappedRows($draft);
        try {
            $op = $dry
                ? $operator->preview('catalog.import.products', ['rows' => $rows], ['source' => 'import'])
                : $operator->execute('catalog.import.products', ['rows' => $rows], ['source' => 'import']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', $e->getMessage());
        }

        if (! $dry) {
            $request->session()->forget(self::SESSION_KEY);
        }

        return redirect()->route('admin.activity.show', $op)->with('success', $dry
            ? 'Import preview ready.'
            : 'Import finished. You can roll it back from Activity.');
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return list<array<string, mixed>>
     */
    protected function mappedRows(array $draft): array
    {
        $mapping = is_array($draft['mapping'] ?? null) ? $draft['mapping'] : [];
        $out = [];
        foreach ($draft['rows'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $mapped = [];
            foreach (self::FIELDS as $field) {
                $header = (string) ($mapping[$field] ?? '');
                $mapped[$field] = $header !== '' ? ($row[$header] ?? '') : '';
            }
            $out[] = $mapped;
        }

        return $out;
    }
}
