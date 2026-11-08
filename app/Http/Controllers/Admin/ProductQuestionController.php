<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductTranslation;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\ProductAnswer;
use App\Domain\Customer\Models\ProductQuestion;
use App\Domain\Customer\Support\QuestionCsvSchema;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductQuestionController extends Controller
{
    public function index(Request $request): Response
    {
        $sort = (string) $request->query('sort', 'id');
        if (! in_array($sort, ['id', 'product', 'customer', 'question', 'status', 'created_at'], true)) {
            $sort = 'id';
        }
        $filters = [
            'product' => trim((string) $request->query('product', '')),
            'customer' => trim((string) $request->query('customer', '')),
            'question' => trim((string) $request->query('question', '')),
            'status' => (string) $request->query('status', 'all'),
            'id_min' => trim((string) $request->query('id_min', '')),
            'id_max' => trim((string) $request->query('id_max', '')),
            'sort' => $sort,
            'dir' => strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc',
        ];

        $query = ProductQuestion::query()
            ->with(['product.translations', 'customer', 'answers']);
        if (in_array($filters['status'], [ProductQuestion::STATUS_PENDING, ProductQuestion::STATUS_APPROVED, ProductQuestion::STATUS_REJECTED], true)) {
            $query->where('status', $filters['status']);
        }
        if ($filters['id_min'] !== '') {
            $query->where((new ProductQuestion)->qualifyColumn('id'), '>=', (int) $filters['id_min']);
        }
        if ($filters['id_max'] !== '') {
            $query->where((new ProductQuestion)->qualifyColumn('id'), '<=', (int) $filters['id_max']);
        }
        if ($filters['product'] !== '') {
            $needle = $filters['product'];
            $query->whereHas('product.translations', fn ($t) => $t->where('name', 'like', '%'.$needle.'%'));
        }
        if ($filters['customer'] !== '') {
            $needle = $filters['customer'];
            $query->whereHas('customer', fn ($c) => $c->where('email', 'like', '%'.$needle.'%')->orWhere('name', 'like', '%'.$needle.'%'));
        }
        if ($filters['question'] !== '') {
            $query->where('body', 'like', '%'.$filters['question'].'%');
        }

        $dir = $filters['dir'];
        if ($filters['sort'] === 'product') {
            $locale = app()->getLocale();
            $prefix = \Illuminate\Support\Facades\DB::getTablePrefix();
            $productsTable = (new Product)->getTable();
            $translationsTable = (new ProductTranslation)->getTable();
            $rawTransName = "`{$prefix}{$translationsTable}`.`name`";
            $rawProdSku = "`{$prefix}{$productsTable}`.`sku`";
            $query
                ->leftJoin($productsTable, (new Product)->qualifyColumn('id'), '=', (new ProductQuestion)->qualifyColumn('product_id'))
                ->leftJoin($translationsTable, function ($join) use ($locale) {
                    $join->on((new ProductTranslation)->qualifyColumn('product_id'), '=', (new Product)->qualifyColumn('id'))
                        ->where((new ProductTranslation)->qualifyColumn('language_code'), '=', $locale);
                })
                ->orderByRaw("COALESCE({$rawTransName}, {$rawProdSku}) {$dir}")
                ->orderBy((new ProductQuestion)->qualifyColumn('id'), $dir)
                ->select((new ProductQuestion)->qualifyColumn('*'));
        } elseif ($filters['sort'] === 'customer') {
            $customersTable = (new Customer)->getTable();
            $query
                ->leftJoin($customersTable, (new Customer)->qualifyColumn('id'), '=', (new ProductQuestion)->qualifyColumn('customer_id'))
                ->orderBy((new Customer)->qualifyColumn('name'), $dir)
                ->orderBy((new Customer)->qualifyColumn('email'), $dir)
                ->orderBy((new ProductQuestion)->qualifyColumn('id'), $dir)
                ->select((new ProductQuestion)->qualifyColumn('*'));
        } elseif ($filters['sort'] === 'question') {
            $query->orderBy('body', $dir)->orderBy('id', $dir);
        } elseif ($filters['sort'] === 'status') {
            $query->orderBy('status', $dir)->orderBy('id', $dir);
        } elseif ($filters['sort'] === 'created_at') {
            $query->orderBy('created_at', $dir)->orderBy('id', $dir);
        } else {
            $query->orderBy('id', $dir);
        }

        $counts = [
            'all' => ProductQuestion::query()->count(),
            ProductQuestion::STATUS_PENDING => ProductQuestion::query()->where('status', ProductQuestion::STATUS_PENDING)->count(),
            ProductQuestion::STATUS_APPROVED => ProductQuestion::query()->where('status', ProductQuestion::STATUS_APPROVED)->count(),
            ProductQuestion::STATUS_REJECTED => ProductQuestion::query()->where('status', ProductQuestion::STATUS_REJECTED)->count(),
        ];

        $locale = app()->getLocale();

        return Inertia::render('Customers/Questions', [
            'filters' => $filters,
            'counts' => $counts,
            'questions' => $query->adminPaginate()->through(fn (ProductQuestion $question) => [
                'id' => $question->id,
                'body' => $question->body,
                'status' => $question->status ?: ($question->is_approved ? ProductQuestion::STATUS_APPROVED : ProductQuestion::STATUS_PENDING),
                'rejected_reason' => $question->rejected_reason,
                'created_at' => $question->created_at?->toDateString(),
                'customer' => [
                    'id' => $question->customer?->id,
                    'email' => $question->customer?->email,
                    'name' => $question->customer?->displayName(),
                ],
                'product' => [
                    'id' => $question->product?->id,
                    'name' => $question->product?->translation($locale)?->name ?: $question->product?->sku,
                ],
                'answers' => $question->answers->map(fn (ProductAnswer $answer) => [
                    'id' => $answer->id,
                    'body' => $answer->body,
                    'is_official' => $answer->is_official,
                    'created_at' => $answer->created_at?->toDateString(),
                ])->values()->all(),
            ]),
        ]);
    }

    public function moderate(Request $request, ProductQuestion $question): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:approve,reject,pending,unapprove,delete,answer'],
            'rejected_reason' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
        ]);

        if ($data['action'] === 'delete') {
            $question->delete();

            return back()->with('success', 'Question deleted.');
        }

        if ($data['action'] === 'approve') {
            $question->approve();
        } elseif ($data['action'] === 'reject') {
            $question->reject($data['rejected_reason'] ?? null);
        } elseif ($data['action'] === 'pending' || $data['action'] === 'unapprove') {
            $question->pending();
        } elseif ($data['action'] === 'answer') {
            $body = trim((string) ($data['body'] ?? ''));
            if ($body === '') {
                return back()->with('error', 'Write an answer first.');
            }
            $question->answers()->create([
                'admin_id' => $request->user('admin')?->id,
                'body' => $body,
                'is_official' => true,
                'is_approved' => true,
            ]);
            $question->approve();
        }

        return back()->with('success', 'Question updated.');
    }

    public function export()
    {
        return $this->csvDownload('questions.csv', QuestionCsvSchema::exportCsv());
    }

    public function importSample()
    {
        return $this->csvDownload('questions-sample.csv', QuestionCsvSchema::sampleCsv());
    }

    public function import(Request $request): RedirectResponse
    {
        return $this->csvImport($request, QuestionCsvSchema::import(...), 'questions');
    }

    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:approve,reject,pending,unapprove,delete'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $questions = ProductQuestion::query()->whereIn('id', $data['ids'])->get();
        $count = 0;

        foreach ($questions as $question) {
            if ($data['action'] === 'delete') {
                $question->delete();
                $count++;
                continue;
            }
            if ($data['action'] === 'approve') {
                $question->approve();
                $count++;
            } elseif ($data['action'] === 'reject') {
                $question->reject(null);
                $count++;
            } elseif ($data['action'] === 'pending' || $data['action'] === 'unapprove') {
                $question->pending();
                $count++;
            }
        }

        return back()->with('success', "Updated {$count} questions.");
    }
}
