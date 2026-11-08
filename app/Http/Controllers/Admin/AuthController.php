<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Security\SecurityAuditLogger;
use App\Domain\Security\CustomerPassword;
use App\Domain\Security\SessionRevoker;
use App\Domain\Settings\PrivacySettings;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function create(): Response|RedirectResponse
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboards.index');
        }

        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'admin-login:'.$request->ip().'|'.strtolower(trim((string) $request->input('email')));
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return redirect()->route('admin.login')->withErrors([
                'email' => 'Too many sign-in tries. Wait a minute and try again.',
            ]);
        }

        if (! Auth::guard('admin')->attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            SecurityAuditLogger::adminLoginFailed((string) $request->input('email'), $request, 'invalid_credentials');

            return back()->withErrors(['email' => 'Invalid credentials.']);
        }

        $admin = Auth::guard('admin')->user();
        if (! $admin->is_enabled) {
            Auth::guard('admin')->logout();
            SecurityAuditLogger::adminLoginFailed((string) $request->input('email'), $request, 'account_disabled');
            abort(403, 'This account is disabled.');
        }

        RateLimiter::clear($key);
        SecurityAuditLogger::adminLogin($admin, $request);

        if ($admin->totp_confirmed_at || $admin->hasPasskeys()) {
            Auth::guard('admin')->logout();
            // Fresh session id before the MFA step so a pre-login fixed
            // session id cannot ride through the MFA pending window.
            $request->session()->regenerate();
            $request->session()->put('admin.mfa_pending_id', $admin->id);
            $request->session()->put('admin.mfa_remember', $request->boolean('remember'));

            return redirect()->route('admin.mfa.challenge');
        }

        $request->session()->regenerate();

        if (PrivacySettings::adminMfaRequired()) {
            return redirect()->route('admin.mfa.enroll');
        }

        return redirect()->route('admin.dashboards.index');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $admin = Auth::guard('admin')->user();
        if ($admin) {
            SecurityAuditLogger::adminLogout($admin, $request);
        }

        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    public function forgotCreate(): Response|RedirectResponse
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboards.index');
        }

        return Inertia::render('Auth/ForgotPassword');
    }

    public function forgotStore(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $key = 'admin-forgot:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return redirect()->route('admin.password.request')->withErrors([
                'email' => 'Too many reset tries. Wait a minute and try again.',
            ]);
        }

        RateLimiter::hit($key, 60);
        Password::broker('admins')->sendResetLink($request->only('email'));

        return back()->with('success', 'Check your email for the reset link.');
    }

    public function resetCreate(Request $request): Response|RedirectResponse
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboards.index');
        }

        return Inertia::render('Auth/ResetPassword', [
            'email' => (string) $request->query('email', ''),
            'token' => (string) $request->query('token', ''),
        ]);
    }

    public function resetStore(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => CustomerPassword::rules(),
        ], CustomerPassword::messages());

        $status = Password::broker('admins')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Admin $admin, string $password) {
                $admin->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();
                // Logged out here, so revoke every session of this admin —
                // a reset proves email access, not possession of other devices.
                SessionRevoker::revokeOthers('admin', $admin->id);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => 'This reset link is invalid or expired.']);
        }

        return redirect()->route('admin.login')->with('success', 'Your password was updated. Sign in with the new password.');
    }
}
