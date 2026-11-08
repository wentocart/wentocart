<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Installation has completed — {{ $name }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet" nonce="{{ $cspNonce ?? '' }}">
    <style nonce="{{ $cspNonce ?? '' }}">
        :root {
            --bg: #f3f4f8;
            --ink: #0f172a;
            --muted: #64748b;
            --line: #e2e8f0;
            --brand: #5b21b6;
            --brand-2: #7c3aed;
            --ok: #16a34a;
            --shadow: 0 18px 50px rgba(15, 23, 42, .08);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Plus Jakarta Sans", system-ui, sans-serif;
            color: var(--ink);
            background:
                radial-gradient(1200px 500px at 10% -10%, #ede9fe 0%, transparent 55%),
                radial-gradient(900px 400px at 100% 0%, #dcfce7 0%, transparent 45%),
                var(--bg);
        }
        .shell { max-width: 1120px; margin: 0 auto; padding: 28px 20px 48px; }
        .topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 22px; }
        .brand { display: flex; align-items: center; gap: 12px; }
        .brand-logo {
            width: 40px; height: 40px; border-radius: 10px; object-fit: contain;
            background: #fff; border: 1px solid var(--line);
            box-shadow: 0 4px 14px rgba(15, 23, 42, .08);
        }
        .brand strong { font-size: 1.15rem; }
        .ver {
            display: inline-flex; margin-left: 8px; padding: 2px 8px; border-radius: 999px;
            background: #e2e8f0; color: var(--muted); font-size: 11px; font-weight: 700; vertical-align: middle;
        }
        .help {
            display: inline-flex; align-items: center; gap: 8px; color: var(--muted);
            text-decoration: none; font-size: 14px; font-weight: 600;
        }
        .stepper {
            display: flex; gap: 6px; justify-content: space-between;
            background: #fff; border: 1px solid var(--line); border-radius: 16px;
            padding: 18px 16px; margin-bottom: 20px; box-shadow: var(--shadow); overflow-x: auto;
        }
        .step { flex: 1; min-width: 110px; text-align: center; color: #166534; font-size: 12px; font-weight: 600; position: relative; }
        .step:not(:last-child)::after {
            content: ""; position: absolute; top: 15px; left: calc(50% + 18px); right: calc(-50% + 18px);
            height: 2px; background: #86efac;
        }
        .step .dot {
            width: 30px; height: 30px; border-radius: 999px; margin: 0 auto 8px;
            display: grid; place-items: center; background: var(--ok); color: #fff; font-weight: 800;
        }
        .step.on { color: var(--brand); }
        .step.on .dot { background: var(--brand); box-shadow: 0 0 0 4px #ede9fe; }
        .step.on .label { text-decoration: underline; text-underline-offset: 6px; text-decoration-thickness: 2px; }

        .card {
            background: #fff; border: 1px solid var(--line); border-radius: 18px;
            box-shadow: var(--shadow); overflow: hidden; display: grid; grid-template-columns: 300px 1fr; min-height: 520px;
        }
        .side {
            background: linear-gradient(180deg, #f5f3ff 0%, #f0fdf4 100%);
            border-right: 1px solid #ede9fe; padding: 28px 24px; display: flex; flex-direction: column; gap: 18px;
        }
        .art {
            height: 150px; border-radius: 16px;
            background: radial-gradient(circle at 35% 35%, #bbf7d0, transparent 55%),
                        radial-gradient(circle at 70% 65%, #c4b5fd, transparent 50%),
                        #ecfdf5;
            position: relative;
        }
        .art::after {
            content: "✓"; position: absolute; right: 28px; bottom: 28px;
            width: 48px; height: 48px; border-radius: 999px; background: var(--ok); color: #fff;
            display: grid; place-items: center; font-size: 22px; font-weight: 800;
            box-shadow: 0 10px 24px rgba(22, 163, 74, .35);
        }
        .side h2 { margin: 0; font-size: 1.35rem; color: var(--brand); letter-spacing: -.02em; }
        .side p { margin: 0; color: var(--muted); font-size: 14px; line-height: 1.55; }
        .callout {
            margin-top: auto; background: #efe9ff; border: 1px solid #ddd6fe; border-radius: 14px;
            padding: 14px; font-size: 13px; color: #5b21b6; line-height: 1.45;
        }
        .main { padding: 32px 28px 24px; display: flex; flex-direction: column; }
        .hero-check {
            width: 56px; height: 56px; border-radius: 999px; background: #dcfce7; color: var(--ok);
            display: grid; place-items: center; font-size: 28px; font-weight: 800; margin-bottom: 14px;
        }
        h1 { margin: 0 0 8px; font-size: 1.7rem; letter-spacing: -.03em; }
        .lead { margin: 0 0 24px; color: var(--muted); font-size: 14px; line-height: 1.5; }
        .links { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px; }
        .link-card {
            border: 1px solid var(--line); border-radius: 14px; padding: 16px; background: #fafafa;
        }
        .link-card.shop { background: #f0fdf4; border-color: #bbf7d0; }
        .link-card.admin { background: #f5f3ff; border-color: #ddd6fe; }
        .link-card .kicker { font-size: 12px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; margin-bottom: 8px; }
        .link-card.shop .kicker { color: #166534; }
        .link-card.admin .kicker { color: var(--brand); }
        .url-row {
            display: flex; align-items: center; gap: 8px;
            background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px;
        }
        .url-row code { flex: 1; min-width: 0; font-size: 13px; line-height: 1.4; overflow-wrap: break-word; word-break: normal; color: var(--ink); }
        .url-row a {
            flex: 0 0 auto; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center;
            text-decoration: none;
        }
        .url-row a svg { width: 16px; height: 16px; }
        .link-card.shop .url-row a { background: #dcfce7; color: #166534; }
        .link-card.admin .url-row a { background: #ede9fe; color: var(--brand); }
        .notice {
            background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af;
            border-radius: 12px; padding: 12px 14px; font-size: 13px; line-height: 1.45; margin-bottom: 18px;
        }
        .footer { margin-top: auto; display: flex; gap: 10px; flex-wrap: wrap; padding-top: 8px; }
        .footer .spacer { flex: 1; }
        a.btn, .btn {
            appearance: none; border: 0; border-radius: 12px; padding: 12px 18px; font: inherit; font-weight: 700;
            cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none;
        }
        .btn svg { flex: 0 0 auto; }
        .btn-primary { background: linear-gradient(135deg, var(--brand-2), var(--brand)); color: #fff; box-shadow: 0 10px 24px rgba(91,33,182,.28); }
        @media (max-width: 900px) {
            .card { grid-template-columns: 1fr; }
            .links { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="shell">
    <div class="topbar">
        <div class="brand">
            <img class="brand-logo" src="{{ asset('logo.jpeg') }}" alt="{{ $name }}" width="40" height="40" onerror="this.onerror=null;this.src='/logo.jpeg'">
            <strong>{{ $name }}<span class="ver">v1.0.0</span></strong>
        </div>
        <a class="help" href="https://wentocart.com/documentation/" target="_blank" rel="noopener">Need Help?</a>
    </div>

    <nav class="stepper" aria-label="Installation steps">
        <div class="step"><div class="dot">✓</div><div>System Check</div></div>
        <div class="step"><div class="dot">✓</div><div>Install Packages</div></div>
        <div class="step"><div class="dot">✓</div><div>Store Information</div></div>
        <div class="step"><div class="dot">✓</div><div>Database Setup</div></div>
        <div class="step"><div class="dot">✓</div><div>Admin Account</div></div>
        <div class="step on"><div class="dot">6</div><div class="label">Installation</div></div>
    </nav>

    <div class="card">
        <aside class="side">
            <div class="art" aria-hidden="true"></div>
            <h2>Congratulations!</h2>
            <p>Your store has been installed successfully and is ready to use.</p>
            <div class="callout"><strong>What's Next?</strong>Customize your store, add products, and start managing your business.</div>
        </aside>
        <section class="main">
            <div class="hero-check" aria-hidden="true">✓</div>
            <h1>Installation has completed!</h1>
            <p class="lead">Access your store using the links below. Admin email and password stay private — use the credentials you created during setup.</p>

            <div class="links">
                <div class="link-card shop">
                    <div class="kicker">Frontend (Your Store)</div>
                    <div class="url-row">
                        <code>{!! preg_replace('#(?<=[:/])#', '<wbr>', e($shopUrl)) !!}</code>
                        <a href="{{ $shopUrl }}" title="Open shop website" target="_blank" rel="noopener">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                        </a>
                    </div>
                </div>
                <div class="link-card admin">
                    <div class="kicker">Backend (Admin Panel)</div>
                    <div class="url-row">
                        <code>{!! preg_replace('#(?<=[:/])#', '<wbr>', e($adminUrl)) !!}</code>
                        <a href="{{ $adminUrl }}" title="Open admin panel" target="_blank" rel="noopener">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                        </a>
                    </div>
                </div>
            </div>

            <div class="notice">Keep these links safe. You can change store URLs later from settings. Passwords are never shown on this page.</div>

            <div class="footer">
                <a class="btn btn-primary" href="{{ $shopUrl }}" target="_blank" rel="noopener">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                    Open shop website
                </a>
                <div class="spacer"></div>
                <a class="btn btn-primary" href="{{ $adminUrl }}" target="_blank" rel="noopener">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M8 10h8M8 14h5"/></svg>
                    Go to Admin Panel
                </a>
            </div>
        </section>
    </div>
</div>
</body>
</html>
