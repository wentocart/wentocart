<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Order\Models\EuWithdrawalRequest;
use App\Domain\Order\Services\EuWithdrawalService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EuWithdrawalRequestController extends Controller
{
    public function approve(EuWithdrawalRequest $euWithdrawalRequest, Request $request, EuWithdrawalService $withdrawals): RedirectResponse
    {
        $data = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $withdrawals->approve($euWithdrawalRequest, auth('admin')->id(), $data['admin_note'] ?? null);

        return $this->backToOrder($euWithdrawalRequest, 'EU withdrawal approved.');
    }

    public function reject(EuWithdrawalRequest $euWithdrawalRequest, Request $request, EuWithdrawalService $withdrawals): RedirectResponse
    {
        $data = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $withdrawals->reject($euWithdrawalRequest, auth('admin')->id(), $data['admin_note'] ?? null);

        return $this->backToOrder($euWithdrawalRequest, 'EU withdrawal rejected.');
    }

    public function complete(EuWithdrawalRequest $euWithdrawalRequest, Request $request, EuWithdrawalService $withdrawals): RedirectResponse
    {
        $data = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $withdrawals->complete($euWithdrawalRequest, $data['admin_note'] ?? null);

        return $this->backToOrder($euWithdrawalRequest, 'EU withdrawal marked completed.');
    }

    protected function backToOrder(EuWithdrawalRequest $request, string $message): RedirectResponse
    {
        $request->loadMissing('order');
        if ($request->order) {
            return redirect()->route('admin.orders.edit', $request->order)->with('success', $message);
        }

        return back()->with('success', $message);
    }
}
