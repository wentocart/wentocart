<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Security\Totp;
use App\Domain\Security\WebAuthn;
use App\Domain\Settings\PrivacySettings;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class MfaController extends Controller
{
    public function __construct(protected WebAuthn $webauthn) {}

    public function challenge(): Response|RedirectResponse
    {
        if (! session('admin.mfa_pending_id')) {
            return redirect()->route('admin.login');
        }
        $admin = Admin::query()->find((int) session('admin.mfa_pending_id'));

        return Inertia::render('Auth/MfaChallenge', [
            'has_passkeys' => (bool) $admin?->hasPasskeys(),
            'has_totp' => (bool) $admin?->totp_confirmed_at,
        ]);
    }

    public function verifyChallenge(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $id = (int) session('admin.mfa_pending_id');
        $admin = Admin::query()->find($id);
        if (! $admin || ! $admin->totp_secret || ! Totp::verify((string) $admin->totp_secret, $request->string('code')->toString())) {
            return back()->withErrors(['code' => 'Invalid authenticator code.']);
        }

        return $this->complete($request, $admin);
    }

    public function enroll(): Response|RedirectResponse
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin) {
            return redirect()->route('admin.login');
        }
        if (($admin->totp_confirmed_at || $admin->hasPasskeys()) && ! PrivacySettings::adminMfaRequired()) {
            return redirect()->route('admin.dashboards.index');
        }
        if (! $admin->totp_secret) {
            $admin->forceFill(['totp_secret' => Totp::secret()])->save();
        }
        $issuer = (string) config('wento.name', config('app.name'));

        return Inertia::render('Auth/MfaEnroll', [
            'otpauth' => Totp::provisioningUri((string) $admin->totp_secret, (string) $admin->email, $issuer),
            'secret' => $admin->totp_secret,
            'has_passkeys' => $admin->hasPasskeys(),
        ]);
    }

    public function confirmEnroll(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $admin = Auth::guard('admin')->user();
        if (! $admin || ! $admin->totp_secret || ! Totp::verify((string) $admin->totp_secret, $request->string('code')->toString())) {
            return back()->withErrors(['code' => 'Invalid authenticator code.']);
        }
        $admin->forceFill(['totp_confirmed_at' => now()])->save();

        return redirect()->route('admin.dashboards.index')->with('success', 'Authenticator app connected.');
    }

    public function passkeyOptions(Request $request): JsonResponse
    {
        $admin = $this->pendingAdmin() ?: Auth::guard('admin')->user();
        if (! $admin) {
            abort(401);
        }

        return response()->json($this->webauthn->assertionOptions($admin));
    }

    public function passkeyAssert(Request $request): JsonResponse
    {
        $admin = $this->pendingAdmin();
        if (! $admin) {
            abort(401);
        }
        $this->webauthn->assert($admin, $request->all());
        Auth::guard('admin')->login($admin, (bool) session('admin.mfa_remember'));
        $request->session()->forget(['admin.mfa_pending_id', 'admin.mfa_remember']);
        $request->session()->regenerate();

        return response()->json(['ok' => true, 'redirect' => route('admin.dashboards.index')]);
    }

    public function passkeyRegisterOptions(): JsonResponse
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin, 401);

        return response()->json($this->webauthn->registerOptions($admin));
    }

    public function passkeyRegister(Request $request): RedirectResponse
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin, 401);
        $this->webauthn->register($admin, $request->all());

        return back()->with('success', 'Passkey saved.');
    }

    protected function pendingAdmin(): ?Admin
    {
        $id = (int) session('admin.mfa_pending_id');

        return $id > 0 ? Admin::query()->find($id) : null;
    }

    protected function complete(Request $request, Admin $admin): RedirectResponse
    {
        Auth::guard('admin')->login($admin, (bool) session('admin.mfa_remember'));
        $request->session()->forget(['admin.mfa_pending_id', 'admin.mfa_remember']);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboards.index');
    }
}
