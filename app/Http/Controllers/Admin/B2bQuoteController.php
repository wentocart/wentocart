<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Customer\Models\B2bQuote;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Services\QuoteService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class B2bQuoteController extends Controller
{
    public function index(Request $request, QuoteService $quotes): Response
    {
        $sort = (string) $request->query('sort', 'id');
        if (! in_array($sort, ['id', 'number', 'customer', 'status', 'total', 'created_at'], true)) {
            $sort = 'id';
        }
        $filters = [
            'id_min' => trim((string) $request->query('id_min', '')),
            'id_max' => trim((string) $request->query('id_max', '')),
            'number' => trim((string) $request->query('number', '')),
            'customer' => trim((string) $request->query('customer', '')),
            'status' => (string) $request->query('status', 'all'),
            'total_min' => trim((string) $request->query('total_min', '')),
            'total_max' => trim((string) $request->query('total_max', '')),
            'sort' => $sort,
            'dir' => strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc',
        ];

        $query = B2bQuote::query()->with(['customer:id,name,email', 'items']);

        if (in_array($filters['status'], [B2bQuote::STATUS_REQUESTED, B2bQuote::STATUS_ACCEPTED, B2bQuote::STATUS_DECLINED, B2bQuote::STATUS_CONVERTED], true)) {
            $query->where('status', $filters['status']);
        }
        if ($filters['id_min'] !== '') {
            $query->where((new B2bQuote)->qualifyColumn('id'), '>=', (int) $filters['id_min']);
        }
        if ($filters['id_max'] !== '') {
            $query->where((new B2bQuote)->qualifyColumn('id'), '<=', (int) $filters['id_max']);
        }
        if ($filters['number'] !== '') {
            $query->where((new B2bQuote)->qualifyColumn('number'), 'like', '%' . $filters['number'] . '%');
        }
        if ($filters['customer'] !== '') {
            $needle = $filters['customer'];
            $query->whereHas('customer', function ($c) use ($needle) {
                $c->where('name', 'like', "%{$needle}%")
                    ->orWhere('email', 'like', "%{$needle}%");
            });
        }
        if ($filters['total_min'] !== '' && is_numeric($filters['total_min'])) {
            $query->where((new B2bQuote)->qualifyColumn('total'), '>=', (float) $filters['total_min']);
        }
        if ($filters['total_max'] !== '' && is_numeric($filters['total_max'])) {
            $query->where((new B2bQuote)->qualifyColumn('total'), '<=', (float) $filters['total_max']);
        }

        $dir = $filters['dir'];
        if ($filters['sort'] === 'customer') {
            $customersTable = (new Customer)->getTable();
            $query
                ->leftJoin($customersTable, (new Customer)->qualifyColumn('id'), '=', (new B2bQuote)->qualifyColumn('customer_id'))
                ->orderBy((new Customer)->qualifyColumn('name'), $dir)
                ->orderBy((new Customer)->qualifyColumn('email'), $dir)
                ->orderBy((new B2bQuote)->qualifyColumn('id'), $dir)
                ->select((new B2bQuote)->qualifyColumn('*'));
        } elseif ($filters['sort'] === 'number') {
            $query->orderBy((new B2bQuote)->qualifyColumn('number'), $dir)->orderBy((new B2bQuote)->qualifyColumn('id'), $dir);
        } elseif ($filters['sort'] === 'status') {
            $query->orderBy((new B2bQuote)->qualifyColumn('status'), $dir)->orderBy((new B2bQuote)->qualifyColumn('id'), $dir);
        } elseif ($filters['sort'] === 'total') {
            $query->orderBy((new B2bQuote)->qualifyColumn('total'), $dir)->orderBy((new B2bQuote)->qualifyColumn('id'), $dir);
        } elseif ($filters['sort'] === 'created_at') {
            $query->orderBy((new B2bQuote)->qualifyColumn('created_at'), $dir)->orderBy((new B2bQuote)->qualifyColumn('id'), $dir);
        } else {
            $query->orderBy((new B2bQuote)->qualifyColumn('id'), $dir);
        }

        $counts = [
            'all' => B2bQuote::query()->count(),
            B2bQuote::STATUS_REQUESTED => B2bQuote::query()->where('status', B2bQuote::STATUS_REQUESTED)->count(),
            B2bQuote::STATUS_ACCEPTED => B2bQuote::query()->where('status', B2bQuote::STATUS_ACCEPTED)->count(),
            B2bQuote::STATUS_DECLINED => B2bQuote::query()->where('status', B2bQuote::STATUS_DECLINED)->count(),
            B2bQuote::STATUS_CONVERTED => B2bQuote::query()->where('status', B2bQuote::STATUS_CONVERTED)->count(),
        ];

        $rows = $query
            ->adminPaginate()
            ->through(fn (B2bQuote $quote) => array_merge($quotes->present($quote), [
                'created_at' => $quote->created_at?->toIso8601String(),
                'customer' => $quote->customer ? [
                    'id' => $quote->customer->id,
                    'name' => $quote->customer->name,
                    'email' => $quote->customer->email,
                ] : null,
            ]));

        return Inertia::render('Customers/Quotes', [
            'quotes' => $rows,
            'filters' => $filters,
            'counts' => $counts,
        ]);
    }

    public function accept(B2bQuote $quote): RedirectResponse
    {
        if ($quote->status !== B2bQuote::STATUS_REQUESTED) {
            return back()->with('error', 'This quote cannot be accepted.');
        }
        $quote->update(['status' => B2bQuote::STATUS_ACCEPTED]);

        return back()->with('success', 'Quote accepted.');
    }

    public function convert(B2bQuote $quote, \App\Domain\Customer\Services\QuoteService $quotes, \App\Domain\Checkout\Services\CartService $carts): RedirectResponse
    {
        if ($quote->status !== B2bQuote::STATUS_ACCEPTED) {
            return back()->with('error', 'Accept the quote before converting it.');
        }
        $customer = $quote->customer;
        if (! $customer) {
            return back()->with('error', 'This quote has no customer.');
        }
        $quotes->loadIntoCart($customer, $quote, $carts);

        return back()->with('success', 'Quote loaded into the customer cart. They can checkout at the quoted prices.');
    }

    public function decline(B2bQuote $quote): RedirectResponse
    {
        if (! in_array($quote->status, [B2bQuote::STATUS_REQUESTED, B2bQuote::STATUS_ACCEPTED], true)) {
            return back()->with('error', 'This quote cannot be declined.');
        }
        $quote->update(['status' => B2bQuote::STATUS_DECLINED]);

        return back()->with('success', 'Quote declined.');
    }

    public function destroy(B2bQuote $quote): RedirectResponse
    {
        $quote->delete();

        return back()->with('success', 'Quote deleted.');
    }

    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:accept,decline,delete'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $quotes = B2bQuote::query()->whereIn('id', $data['ids'])->get();
        $count = 0;

        foreach ($quotes as $quote) {
            if ($data['action'] === 'delete') {
                $quote->delete();
                $count++;
            } elseif ($data['action'] === 'accept' && $quote->status === B2bQuote::STATUS_REQUESTED) {
                $quote->update(['status' => B2bQuote::STATUS_ACCEPTED]);
                $count++;
            } elseif ($data['action'] === 'decline' && in_array($quote->status, [B2bQuote::STATUS_REQUESTED, B2bQuote::STATUS_ACCEPTED], true)) {
                $quote->update(['status' => B2bQuote::STATUS_DECLINED]);
                $count++;
            }
        }

        return back()->with('success', "Updated {$count} quotes.");
    }
}
