<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\GiftCard;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\GiftCardService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Issued gift card management: list, inspect, disable, manually issue, resend.
 *
 * Product-level gift card configuration lives in ProductController; this screen
 * only deals with cards that have actually been issued.
 */
class GiftCardController extends Controller
{
    public function __construct(protected GiftCardService $giftCards) {}

    public function index(Request $request): Response
    {
        $filters = [
            'q' => trim($request->string('q')->toString()),
            'status' => $request->string('status')->toString() ?: 'all',
            'balance' => $request->string('balance')->toString(),
            'sort' => $request->string('sort')->toString() ?: 'id',
            'dir' => $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc',
        ];

        // Sweep lazily so the list reflects reality even without a scheduler run.
        $this->giftCards->expireDueCards();

        $cards = $this->giftCards->adminQuery($filters)
            ->adminPaginate()
            ->through(fn (GiftCard $card) => $this->giftCards->presentAdminRow($card));

        $counts = [];
        foreach (['active', 'used', 'disabled', 'cancelled', 'expired'] as $status) {
            $counts[$status] = GiftCard::query()->where('status', $status)->count();
        }
        $counts['all'] = GiftCard::query()->count();

        return Inertia::render('Catalog/GiftCards/Index', [
            'cards' => $cards,
            'filters' => $filters,
            'counts' => $counts,
        ]);
    }

    public function show(GiftCard $giftCard): Response
    {
        $giftCard->loadMissing(['customer', 'order', 'product.translations']);

        return Inertia::render('Catalog/GiftCards/Show', [
            'card' => $this->giftCards->presentAdminDetail($giftCard),
        ]);
    }

    /**
     * Legacy URL. Gift card products are configured on the product edit screen
     * (Gift Card tab). Manual issuance also happens there after the product is saved.
     */
    public function create(Request $request): RedirectResponse
    {
        $productId = (int) $request->integer('product_id');
        if ($productId > 0) {
            $product = Product::query()->find($productId);
            if ($product && $product->type === 'gift_card') {
                return redirect()
                    ->to('/admin/products/'.$product->id.'/edit?tab=gift_card')
                    ->with('success', 'Configure and issue gift cards from the product Gift Card tab.');
            }
        }

        return redirect()
            ->to('/admin/products/create?type=gift_card')
            ->with('success', 'Create a Gift Card product, then configure it on the Gift Card tab.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'sender_name' => ['nullable', 'string', 'max:255'],
            'gift_message' => ['nullable', 'string', 'max:2000'],
            'expires_at' => ['nullable', 'date'],
            'status' => ['nullable', 'in:active,disabled'],
            'reason' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
        ]);

        $product = Product::query()->findOrFail((int) $data['product_id']);
        $config = $this->giftCards->configForProduct($product);
        if (! $config) {
            throw ValidationException::withMessages([
                'product_id' => 'That product has no gift card configuration.',
            ]);
        }

        try {
            $card = $this->giftCards->issueManually($config, $data, auth('admin')->id());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()
            ->route('admin.gift-cards.show', $card)
            ->with('success', 'Gift card issued: '.$card->code);
    }

    public function updateStatus(Request $request, GiftCard $giftCard): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,disabled,cancelled'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->giftCards->adjust(
                $giftCard,
                0,
                $data['status'],
                $data['note'] ?? null,
                auth('admin')->id(),
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Gift card updated.');
    }

    public function resend(GiftCard $giftCard): RedirectResponse
    {
        $sent = $this->giftCards->resendEmail($giftCard);

        return back()->with(
            $sent ? 'success' : 'error',
            $sent
                ? 'Gift card email resent.'
                : 'No deliverable email address, or delivery is set to account only.',
        );
    }

    /** Lookup helper for the manual-issue form. */
    public function searchCustomers(Request $request): \Illuminate\Http\JsonResponse
    {
        $q = trim($request->string('q')->toString());
        if ($q === '') {
            return response()->json(['results' => []]);
        }

        $customers = Customer::query()
            ->where(function ($inner) use ($q) {
                $inner->where('email', 'like', '%'.$q.'%')->orWhere('name', 'like', '%'.$q.'%');
            })
            ->limit(20)
            ->get(['id', 'name', 'email'])
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => trim($customer->name.' · '.$customer->email),
            ]);

        return response()->json(['results' => $customers]);
    }

    /** Lookup helper for the manual-issue form. */
    public function searchOrders(Request $request): \Illuminate\Http\JsonResponse
    {
        $q = trim($request->string('q')->toString());
        if ($q === '') {
            return response()->json(['results' => []]);
        }

        $orders = Order::query()
            ->where('number', 'like', '%'.$q.'%')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'number', 'email'])
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'name' => trim($order->number.' · '.($order->email ?: '')),
            ]);

        return response()->json(['results' => $orders]);
    }
}