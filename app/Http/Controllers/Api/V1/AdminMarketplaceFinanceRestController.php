<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\Events\MarketplaceEvent;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerEarning;
use App\Domain\Marketplace\Models\SellerWithdrawal;
use App\Domain\Marketplace\Services\MarketplaceLifecycleBroker;
use App\Domain\Marketplace\Services\SellerCatalogService;
use App\Domain\Marketplace\Services\SellerPayoutService;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 */
class AdminMarketplaceFinanceRestController extends Controller
{
    public function payoutsIndex(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $perPage = min(100, max(1, (int) $request->query('per_page', $request->query('limit', 20))));
        $page = max(1, (int) $request->query('page', 1));
        $q = SellerWithdrawal::query()->with('seller:id,shop_name,shop_slug')->orderByDesc('id');
        $sellerId = (int) ($request->query('seller_id') ?? $request->query('sellerId') ?? 0);
        if ($sellerId > 0) {
            $q->where('seller_id', $sellerId);
        }
        $status = trim((string) ($request->query('status') ?? ''));
        if ($status !== '' && in_array($status, SellerWithdrawal::STATUSES, true)) {
            $q->where('status', $status);
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (SellerWithdrawal $row) => $this->payoutRow($row))
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    public function payoutsShow(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $row = SellerWithdrawal::query()->with(['seller', 'paymentMethod'])->find($id);
        if (! $row) {
            return response()->json(['message' => 'Payout not found.', 'error' => 'not_found'], 404);
        }

        return response()->json(['data' => $this->payoutRow($row, true)]);
    }

    public function payoutsStore(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $sellerId = (int) ($request->input('seller_id') ?? $request->input('sellerId') ?? 0);
        $seller = Seller::query()->find($sellerId);
        if (! $seller) {
            return response()->json(['message' => 'Seller not found.', 'error' => 'validation'], 422);
        }
        $amount = (float) ($request->input('amount') ?? 0);
        if ($amount <= 0) {
            return response()->json(['message' => 'amount must be greater than zero.', 'error' => 'validation'], 422);
        }
        try {
            $row = app(SellerCatalogService::class)->requestWithdrawal($seller, [
                'amount' => $amount,
                'note' => $request->input('note'),
                'seller_payment_method_id' => $request->input('seller_payment_method_id')
                    ?? $request->input('sellerPaymentMethodId'),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'validation'], 422);
        }

        return response()->json(['data' => $this->payoutRow($row->fresh(['seller']) ?? $row, true)], 201);
    }

    public function payoutsProcess(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $row = SellerWithdrawal::query()->find($id);
        if (! $row) {
            return response()->json(['message' => 'Payout not found.', 'error' => 'not_found'], 404);
        }
        $action = trim((string) ($request->input('action') ?? ''));
        if (! in_array($action, ['review', 'approve', 'reject', 'process', 'pay', 'fail', 'retry'], true)) {
            return response()->json([
                'message' => 'action must be review|approve|reject|process|pay|fail|retry.',
                'error' => 'validation',
            ], 422);
        }
        if ($action === 'pay') {
            $row->payout_reference = (string) ($request->input('payout_reference')
                ?? $request->input('payoutReference')
                ?? ('OFFLINE-'.$row->id));
            $row->payout_rail = (string) ($request->input('payout_rail') ?? $request->input('payoutRail') ?? 'offline');
            $row->save();
        }
        try {
            app(SellerCatalogService::class)->processWithdrawal(
                $row,
                $action,
                Auth::guard('admin')->id() ? (int) Auth::guard('admin')->id() : null,
                $request->input('note'),
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'process_error'], 422);
        }

        return response()->json([
            'message' => 'Payout updated successfully.',
            'data' => $this->payoutRow($row->fresh(['seller']) ?? $row, true),
        ]);
    }

    public function earningsIndex(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $perPage = min(100, max(1, (int) $request->query('per_page', $request->query('limit', 20))));
        $page = max(1, (int) $request->query('page', 1));
        $q = SellerEarning::query()
            ->with(['seller:id,shop_name,shop_slug', 'sellerOrder:id,number,currency_code'])
            ->orderByDesc('id');
        $sellerId = (int) ($request->query('seller_id') ?? $request->query('sellerId') ?? 0);
        if ($sellerId > 0) {
            $q->where('seller_id', $sellerId);
        }
        $status = trim((string) ($request->query('status') ?? ''));
        if ($status !== '' && in_array($status, ['pending', 'available', 'paid', 'refunded'], true)) {
            $q->where('status', $status);
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (SellerEarning $row) => $this->earningRow($row))
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    public function earningsShow(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $row = SellerEarning::query()
            ->with(['seller:id,shop_name,shop_slug', 'sellerOrder:id,number,currency_code'])
            ->find($id);
        if (! $row) {
            return response()->json(['message' => 'Earning not found.', 'error' => 'not_found'], 404);
        }

        return response()->json(['data' => $this->earningRow($row)]);
    }

    public function earningsUpdateStatus(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $row = SellerEarning::query()->find($id);
        if (! $row) {
            return response()->json(['message' => 'Earning not found.', 'error' => 'not_found'], 404);
        }
        $status = trim((string) ($request->input('status') ?? ''));
        if (! in_array($status, ['pending', 'available'], true)) {
            return response()->json(['message' => 'status must be pending or available.', 'error' => 'validation'], 422);
        }
        if (in_array($row->status, [SellerEarning::STATUS_PAID, SellerEarning::STATUS_REFUNDED], true)) {
            return response()->json([
                'message' => 'Paid or refunded earnings cannot be changed here.',
                'error' => 'validation',
            ], 422);
        }
        $before = (string) $row->status;
        $row->update(['status' => $status]);
        app(MarketplaceLifecycleBroker::class)->dispatch(MarketplaceEvent::earningAdjusted(
            (int) $row->id,
            (int) $row->seller_id,
            Auth::guard('admin')->id() ? (int) Auth::guard('admin')->id() : null,
            $before,
            $status,
        ));

        return response()->json(['data' => $this->earningRow($row->fresh(['seller', 'sellerOrder']) ?? $row)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payoutRow(SellerWithdrawal $row, bool $detail = false): array
    {
        $data = [
            'id' => (int) $row->id,
            'sellerId' => (int) $row->seller_id,
            'shopName' => (string) ($row->seller?->shop_name ?? ''),
            'amount' => (float) $row->amount,
            'status' => (string) $row->status,
            'note' => (string) ($row->note ?? ''),
            'payoutReference' => (string) ($row->payout_reference ?? ''),
            'payoutRail' => (string) ($row->payout_rail ?? ''),
            'createdAt' => optional($row->created_at)?->toIso8601String(),
            'processedAt' => optional($row->processed_at)?->toIso8601String(),
        ];
        if ($detail && $row->seller) {
            $data['availableBalance'] = app(SellerPayoutService::class)->availablePayoutAmount($row->seller);
            $data['payoutBankAccount'] = (string) ($row->seller->payout_bank_account ?? '');
            $data['payoutPaypalEmail'] = (string) ($row->seller->payout_paypal_email ?? '');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function earningRow(SellerEarning $row): array
    {
        return [
            'id' => (int) $row->id,
            'sellerId' => (int) $row->seller_id,
            'shopName' => (string) ($row->seller?->shop_name ?? ''),
            'orderNumber' => (string) ($row->sellerOrder?->number ?? ''),
            'grossAmount' => (float) $row->gross_amount,
            'commissionAmount' => (float) $row->commission_amount,
            'commissionRate' => $row->commission_rate !== null ? (float) $row->commission_rate : null,
            'netAmount' => (float) $row->net_amount,
            'status' => (string) $row->status,
            'currencyCode' => (string) ($row->sellerOrder?->currency_code ?? ''),
            'createdAt' => optional($row->created_at)?->toIso8601String(),
        ];
    }

    private function guard(): ?JsonResponse
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }

        return null;
    }
}
