<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="app-base" content="{{ \App\Support\WebBase::path() }}">
    <meta name="admin-segment" content="{{ \App\Domain\Security\AdminPath::segment() }}">
    <meta name="csp-nonce" content="{{ $cspNonce ?? '' }}">
    <title inertia>{{ config('wento.name') }} Admin</title>
    @php
        $favicon = \App\Domain\Theme\ThemeSettingsCatalog::DEFAULT_FAVICON_URL;
        try {
            if (config('wento.installed')) {
                $store = app(\App\Domain\Store\StoreContext::class)->store();
                $saved = is_array($store?->theme_settings) ? ($store->theme_settings['favicon_url'] ?? '') : '';
                $favicon = \App\Domain\Theme\ThemeSettingsCatalog::resolvedFaviconUrl($saved);
            }
        } catch (\Throwable) {
            $favicon = \App\Domain\Theme\ThemeSettingsCatalog::DEFAULT_FAVICON_URL;
        }
        if (! preg_match('#^https?://#i', $favicon)) {
            $favicon = \App\Support\WebBase::url($favicon);
        }
    @endphp
    <link rel="icon" href="{{ $favicon }}">
    {{-- Do not load Google Fonts in <head>: a blocked stylesheet leaves a blank page. --}}
    <style nonce="{{ $cspNonce ?? '' }}">
        html, body { margin: 0; min-height: 100%; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; background: #F3F0FB; color: #171717; }
        .login-fallback { min-height: 100vh; display: grid; place-items: center; background: #F3F0FB; color: #171717; padding: 1.5rem; }
        .login-fallback form { width: 100%; max-width: 28rem; background: #fff; padding: 2rem; border-radius: 16px; box-shadow: 0 18px 50px rgba(91,33,182,0.08); }
        .login-fallback h1 { margin: 0 0 1rem; font-size: 1.5rem; }
        .login-fallback input { display: block; width: 100%; box-sizing: border-box; margin: 0 0 0.75rem; padding: 0.7rem 0.85rem; border: 1px solid #e5e5e5; border-radius: 12px; background: #fff; color: #171717; }
        .login-fallback button { width: 100%; padding: 0.8rem; border: 0; border-radius: 12px; background: #7c3aed; color: #fff; font-weight: 600; cursor: pointer; }
        .login-fallback .error { color: #dc2626; margin: 0 0 0.75rem; }
        .login-fallback a { color: #7c3aed; }
        .admin-loading-shell { min-height: 100vh; display: grid; grid-template-columns: 248px 1fr; }
        .admin-loading-sidebar { background: #fff; border-right: 1px solid #e5e5e5; }
        .admin-loading-main { padding: 2rem; color: #737373; }
        .admin-boot-error { padding: 2rem; color: #b91c1c; }
        @media (max-width: 768px) {
            .admin-loading-shell { grid-template-columns: 1fr; }
            .admin-loading-sidebar { display: none; }
        }
    </style>
    <script nonce="{{ $cspNonce ?? '' }}">
        (function () {
            var nonce = document.querySelector('meta[name="csp-nonce"]');
            nonce = nonce && nonce.getAttribute('content');
            if (!nonce || !Document.prototype.createElement) {
                return;
            }
            var create = Document.prototype.createElement;
            Document.prototype.createElement = function (name) {
                var el = create.apply(this, arguments);
                if (String(name).toLowerCase() === 'style') {
                    el.setAttribute('nonce', nonce);
                }
                return el;
            };
        })();
    </script>
    @vite(['resources/css/admin.css', 'resources/js/admin/app.jsx'])
    @inertiaHead
    <script nonce="{{ $cspNonce ?? '' }}">
        window.addEventListener('error', function (event) {
            var target = event.target;
            if (!target || (target.tagName !== 'SCRIPT' && target.tagName !== 'LINK')) {
                return;
            }
            var rel = (target.rel || '').toLowerCase();
            if (target.tagName === 'LINK' && (rel === 'preload' || rel === 'modulepreload')) {
                return;
            }
            var el = document.getElementById('app');
            if (!el || !el.querySelector('.admin-loading-shell, .login-fallback')) {
                return;
            }
            var key = 'wento-asset-reload';
            var n = Number(sessionStorage.getItem(key) || '0');
            if (n < 2) {
                sessionStorage.setItem(key, String(n + 1));
                window.setTimeout(function () {
                    window.location.reload();
                }, n === 0 ? 400 : 1200);
                return;
            }
            el.innerHTML = '<div class="admin-boot-error"><p>Admin assets failed to load.</p><p>A new build may still be writing files. Wait a moment, then reload.</p><p><a href="">Reload</a></p></div>';
        }, true);
    </script>
</head>
<body class="bg-[#F7F8FA] text-neutral-900 antialiased">
    <script data-page="app" type="application/json" nonce="{{ $cspNonce ?? '' }}">{!! json_encode($page, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !!}</script>
    <div id="app">
        @if (in_array($page['component'] ?? '', ['Auth/Login', 'Auth/ForgotPassword', 'Auth/ResetPassword'], true))
            <div class="login-fallback">
                @if (($page['component'] ?? '') === 'Auth/ForgotPassword')
                    <form method="post" action="{{ url('/admin/forgot-password') }}">
                        @csrf
                        <h1>Forgot Password?</h1>
                        <input name="email" type="email" placeholder="Email address" value="{{ old('email') }}" autocomplete="username" required>
                        @error('email')
                            <p class="error" role="alert">{{ $message }}</p>
                        @enderror
                        <button type="submit">Send Reset Link</button>
                        <p><a href="{{ url('/admin/login') }}">Back to Sign in</a></p>
                    </form>
                @elseif (($page['component'] ?? '') === 'Auth/ResetPassword')
                    <form method="post" action="{{ url('/admin/reset-password') }}">
                        @csrf
                        <h1>Set a new password</h1>
                        <input type="hidden" name="token" value="{{ $page['props']['token'] ?? request('token') }}">
                        <input name="email" type="email" placeholder="Email address" value="{{ old('email', $page['props']['email'] ?? request('email')) }}" autocomplete="username" required>
                        <input name="password" type="password" placeholder="Password" autocomplete="new-password" required>
                        <input name="password_confirmation" type="password" placeholder="Confirm password" autocomplete="new-password" required>
                        @error('email')
                            <p class="error" role="alert">{{ $message }}</p>
                        @enderror
                        <button type="submit">Save new password</button>
                    </form>
                @else
                    <form method="post" action="{{ url('/admin/login') }}">
                        @csrf
                        <h1>Sign in</h1>
                        <input name="email" type="email" placeholder="Email address" value="{{ old('email') }}" autocomplete="username" required>
                        <input name="password" type="password" placeholder="Password" autocomplete="current-password" required>
                        @error('email')
                            <p class="error" role="alert">{{ $message }}</p>
                        @enderror
                        <button type="submit">Sign in</button>
                        <p><a href="{{ url('/admin/forgot-password') }}">Forgot password?</a></p>
                    </form>
                @endif
            </div>
        @else
            <div class="admin-loading-shell">
                <div class="admin-loading-sidebar" aria-hidden="true"></div>
                <p class="admin-loading-main">Loading admin…</p>
            </div>
        @endif
    </div>
</body>
</html>
