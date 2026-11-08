<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Ops\AbilityRegistry;
use App\Domain\Ops\Operator;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Throwable;

class OperatorController extends Controller
{
    public function index(AbilityRegistry $abilities): Response
    {
        return Inertia::render('Ops/Operator', [
            'abilities' => $abilities->catalog(),
        ]);
    }

    public function preview(Request $request, Operator $operator): RedirectResponse
    {
        return $this->run($request, $operator, true);
    }

    public function execute(Request $request, Operator $operator): RedirectResponse
    {
        return $this->run($request, $operator, false);
    }

    protected function run(Request $request, Operator $operator, bool $dry): RedirectResponse
    {
        $data = $request->validate([
            'ability' => ['required', 'string', 'max:120'],
            'input' => ['required'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $input = $data['input'];
        if (is_string($input)) {
            $decoded = json_decode($input, true);
            if (! is_array($decoded)) {
                return back()->with('error', 'Input must be valid JSON.')->withInput();
            }
            $input = $decoded;
        }
        if (! is_array($input)) {
            return back()->with('error', 'Input must be an object.')->withInput();
        }

        try {
            $op = $dry
                ? $operator->preview($data['ability'], $input, ['source' => 'admin', 'reason' => $data['reason'] ?? null])
                : $operator->execute($data['ability'], $input, ['source' => 'admin', 'reason' => $data['reason'] ?? null]);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()
            ->route('admin.activity.show', $op)
            ->with('success', $dry
                ? 'Preview ready — '.$op->items()->count().' record(s). Approve from Activity or run Execute.'
                : $op->summary);
    }
}
