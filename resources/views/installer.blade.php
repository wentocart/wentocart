<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Install {{ $shop }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; }
        .wrap { max-width: 720px; margin: 40px auto; background: #1e293b; padding: 32px; border-radius: 16px; }
        h1 { margin: 0 0 8px; font-size: 1.5rem; }
        .lead { color: #94a3b8; margin: 0 0 24px; }
        .steps { display: flex; gap: 8px; margin-bottom: 24px; font-size: 12px; }
        .steps span { flex: 1; text-align: center; padding: 8px; border-radius: 8px; background: #0f172a; color: #64748b; }
        .steps span.on { background: #0369a1; color: #fff; }
        label { display: block; margin: 12px 0 4px; font-size: 14px; }
        input, select, textarea { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #334155; background: #0f172a; color: #fff; box-sizing: border-box; }
        button, .btn { margin-top: 16px; padding: 12px 16px; border: 0; border-radius: 8px; background: #38bdf8; color: #0f172a; font-weight: 700; cursor: pointer; }
        .btn-row { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn-row .btn, .btn-row button { flex: 1; }
        .ghost { background: #334155; color: #e2e8f0; }
        .ok { color: #4ade80; } .bad { color: #f87171; }
        .hint { font-size: 13px; color: #94a3b8; margin: 6px 0 0; }
        .preview { background: #0f172a; border-radius: 8px; padding: 12px; font-size: 13px; margin-top: 12px; }
        .error { background: #4c1d24; color: #fecdd3; padding: 10px 12px; border-radius: 8px; margin-bottom: 16px; }
        .note { background: #0f172a; padding: 10px 12px; border-radius: 8px; font-size: 13px; color: #94a3b8; }
        .addr-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        @media (max-width: 640px) { .addr-row { grid-template-columns: 1fr; } }
        .url-detected { background: #0f172a; border-radius: 8px; padding: 10px 12px; font-size: 13px; color: #cbd5e1; margin: 8px 0; }
        .url-detected code { color: #fff; word-break: break-all; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { text-align: left; padding: 8px; border-bottom: 1px solid #334155; vertical-align: top; }
        th { color: #94a3b8; font-weight: 500; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Install {{ $shop }}</h1>
    <p class="lead">Version {{ $version }}. Extract the zip, double-click INSTALL.bat, then fill these steps.</p>

    <div class="steps">
        <span id="tab-0" class="on">1. Compatibility</span>
        <span id="tab-1">2. Store</span>
        <span id="tab-2">3. Database</span>
        <span id="tab-3">4. Admin</span>
    </div>

    @if ($errors->any())
        <div class="error">{{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ url('/install') }}" id="install-form">
        @csrf
        <input type="hidden" name="language" value="en">
        <input type="hidden" name="currency" value="USD">

        <div class="panel on" id="step-0">
            <p class="hint">Step 1 compares this machine with version {{ $version }}. PHP, Node, and extensions cannot be upgraded from this page. “Prepare this copy” only installs Composer packages, builds the frontend, and unlocks folders.</p>
            <table>
                <thead>
                    <tr><th>Check</th><th>You have</th><th>Need</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @foreach ($checks as $check)
                        <tr>
                            <td>{{ $check['label'] }}</td>
                            <td>{{ $check['current'] }}</td>
                            <td>{{ $check['required'] }}</td>
                            <td class="{{ $check['ok'] ? 'ok' : 'bad' }}">
                                {{ $check['ok'] ? 'OK' : 'Update required' }}
                                @if (! $check['ok'] && $check['hint'])
                                    <div class="hint">{{ $check['hint'] }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if (! $ready)
                <p class="error">Fix every “Update required” row before store setup. Install the listed version (or newer), enable the missing PHP extension in php.ini, then reload.</p>
            @endif
            <div class="btn-row">
                @if (collect($checks)->contains(fn ($c) => $c['fixable']))
                    <button type="button" class="btn ghost" id="repair">Prepare this copy</button>
                @endif
                @if ($ready)
                    <button type="button" class="btn" data-next="1">Continue</button>
                @else
                    <button type="button" class="btn" disabled>Continue</button>
                @endif
            </div>
            <p class="hint" id="repair-msg"></p>
        </div>

        <div class="panel" id="step-1">
            <label>Store name</label>
            <input name="store_name" value="{{ old('store_name', $shop) }}" required>
            <label>Store email</label>
            <input name="store_email" type="email" value="{{ old('store_email') }}" required>
            <label>Shop address (optional, any country)</label>
            <p class="hint">Invoices and contact. Fill only what you have. Not the website URL.</p>
            <label>Country</label>
            <select name="store_country" autocomplete="country">
                <option value="">Select country</option>
                @foreach ($countries as $country)
                    <option value="{{ $country['code'] }}" @selected(old('store_country') === $country['code'])>{{ $country['name'] }}</option>
                @endforeach
            </select>
            <label>Address line 1</label>
            <input name="store_line1" maxlength="255" autocomplete="address-line1" placeholder="Street, building, shop number" value="{{ old('store_line1') }}">
            <label>Address line 2</label>
            <input name="store_line2" maxlength="255" autocomplete="address-line2" placeholder="Apartment, suite, unit" value="{{ old('store_line2') }}">
            <div class="addr-row">
                <div>
                    <label>City</label>
                    <input name="store_city" maxlength="120" autocomplete="address-level2" value="{{ old('store_city') }}">
                </div>
                <div>
                    <label>State / region / province</label>
                    <input name="store_region" maxlength="120" autocomplete="address-level1" placeholder="State, county, or region" value="{{ old('store_region') }}">
                </div>
            </div>
            <label>Postal / ZIP code</label>
            <input name="store_postal" maxlength="32" autocomplete="postal-code" placeholder="ZIP, PIN, postcode" value="{{ old('store_postal') }}">
            <label>This copy's website URL</label>
            <div class="url-detected">Installing at <code>{{ $appUrl }}</code></div>
            <input name="app_url" value="{{ old('app_url', $appUrl) }}" required autocomplete="off" readonly>
            <p class="hint">Detected from the browser. Host, port, and folder must match this page. Change the public domain later under Admin → Store. Do not type a street address here.</p>
            <div class="btn-row">
                <button type="button" class="btn ghost" data-next="0">Back</button>
                <button type="button" class="btn" data-next="2">Continue</button>
            </div>
        </div>

        <div class="panel" id="step-2">
            <input type="hidden" name="db_connection" value="mysql">
            <label>DB name</label>
            <input name="db_database" value="{{ old('db_database', config('wento.codename')) }}" required>
            <label>DB username</label>
            <input name="db_username" value="{{ old('db_username', 'root') }}">
            <label>DB password</label>
            <input name="db_password" type="password" value="{{ old('db_password') }}">
            <label>Table prefix</label>
            <input name="db_prefix" id="db_prefix" value="{{ old('db_prefix', 'wento') }}" maxlength="32" required placeholder="wento" autocomplete="off" spellcheck="false">
            <p class="hint">Start with a letter, then letters, numbers, and underscores (tiger_, tia_2_, Lo_as_as_). No spaces or hyphens. Tables: PREFIX_products.</p>
            <div class="preview" id="prefix-preview"></div>
            <details class="note" style="margin-top:12px">
                <summary>Advanced (host / port)</summary>
                <label>DB host</label>
                <input name="db_host" value="{{ old('db_host', '127.0.0.1') }}">
                <label>DB port</label>
                <input name="db_port" value="{{ old('db_port', '3306') }}">
            </details>
            <div class="btn-row">
                <button type="button" class="btn ghost" data-next="1">Back</button>
                <button type="button" class="btn ghost" id="test-db">Test connection</button>
                <button type="button" class="btn" data-next="3">Continue</button>
            </div>
            <p class="hint" id="test-msg"></p>
        </div>

        <div class="panel" id="step-3">
            <label>Admin name</label>
            <input name="admin_name" value="{{ old('admin_name', 'Administrator') }}" required>
            <label>Admin email</label>
            <input name="admin_email" type="email" value="{{ old('admin_email') }}" required>
            <label>Admin password</label>
            <input name="admin_password" type="password" minlength="8" required>
            <p class="hint">This is the login for /admin. Store email on step 2 is the public shop contact.</p>
            <fieldset style="border:1px solid #334155;border-radius:8px;padding:12px 16px;margin-top:16px">
                <legend style="padding:0 6px;color:#94a3b8">Install demo data</legend>
                <label style="display:flex;align-items:center;gap:8px;margin:8px 0">
                    <input type="radio" name="install_demo_data" value="1" checked style="width:auto">
                    Yes — sample products, customers, orders, and discount rules
                </label>
                <label style="display:flex;align-items:center;gap:8px;margin:8px 0">
                    <input type="radio" name="install_demo_data" value="0" style="width:auto">
                    No — start with a minimal empty shop
                </label>
            </fieldset>
            <div class="btn-row">
                <button type="button" class="btn ghost" data-next="2">Back</button>
                <button type="submit">Install</button>
            </div>
        </div>
    </form>
</div>
<script>
    const panels = [0, 1, 2, 3].map((i) => document.getElementById('step-' + i));
    const tabs = [0, 1, 2, 3].map((i) => document.getElementById('tab-' + i));
    const prefix = document.getElementById('db_prefix');
    const preview = document.getElementById('prefix-preview');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    function show(step) {
        panels.forEach((p, i) => p.classList.toggle('on', i === step));
        tabs.forEach((t, i) => t.classList.toggle('on', i === step));
    }

    function renderPreview() {
        const stem = (prefix.value || '').replace(/_+$/, '').replace(/[^A-Za-z0-9_]/g, '').toUpperCase();
        const p = stem ? stem + '_' : '';
        preview.textContent = p
            ? ('Tables: ' + p + 'admins, ' + p + 'products, ' + p + 'orders')
            : 'Enter a prefix to preview table names.';
    }

    document.querySelectorAll('[data-next]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const next = Number(btn.dataset.next);
            const current = panels.findIndex((p) => p.classList.contains('on'));
            if (next > current) {
                const fields = panels[current].querySelectorAll('[required]');
                for (const field of fields) {
                    if (!field.checkValidity()) {
                        field.reportValidity();
                        return;
                    }
                }
            }
            show(next);
        });
    });

    prefix.addEventListener('input', renderPreview);
    renderPreview();

    const repair = document.getElementById('repair');
    if (repair) {
        repair.addEventListener('click', async () => {
            const msg = document.getElementById('repair-msg');
            msg.textContent = 'Preparing…';
            const res = await fetch('{{ url('/install/repair') }}', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            });
            const json = await res.json().catch(() => ({ message: 'Prepare failed.' }));
            msg.textContent = json.message || (res.ok ? 'Done. Reload the page.' : 'Failed');
            msg.className = 'hint ' + (res.ok ? 'ok' : 'bad');
            if (res.ok) {
                window.location.reload();
            }
        });
    }

    document.getElementById('test-db').addEventListener('click', async () => {
        const msg = document.getElementById('test-msg');
        msg.textContent = 'Testing…';
        const body = new FormData(document.getElementById('install-form'));
        const res = await fetch('{{ url('/install/test-db') }}', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body,
        });
        const json = await res.json().catch(() => ({ message: 'Could not test connection.' }));
        msg.textContent = json.message || (res.ok ? 'OK' : 'Failed');
        msg.className = 'hint ' + (res.ok ? 'ok' : 'bad');
    });
</script>
</body>
</html>
