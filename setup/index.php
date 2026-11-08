<?php

require_once __DIR__.'/guard.php';
wento_setup_guard_boot(dirname(__DIR__));

require_once __DIR__.'/lib.php';

$permissionProblems = setup_permission_problems();
if ($permissionProblems !== []) {
    setup_render_permission_page($permissionProblems);
    exit;
}

setup_boot_session();
if (empty($_SESSION['setup_token'])) {
    $_SESSION['setup_token'] = bin2hex(random_bytes(16));
}

$preview = isset($_GET['preview']) && (string) $_GET['preview'] === '1';
$previewStep = max(0, min(5, (int) ($_GET['step'] ?? 0)));
$startStep = max(0, min(5, (int) ($_GET['start'] ?? 0)));

if (setup_already_installed()) {
    header('Location: '.rtrim(setup_base_url(), '/').'/installed', true, 302);
    exit;
}

$token = htmlspecialchars($_SESSION['setup_token'], ENT_QUOTES, 'UTF-8');
$detectedShopUrl = setup_base_url();
$appUrl = htmlspecialchars($detectedShopUrl, ENT_QUOTES, 'UTF-8');
$isPhpHelper = setup_is_php_development_server();
$shopUrlParts = setup_shop_url_parts($detectedShopUrl);
$shopHostUrl = htmlspecialchars($shopUrlParts['host_url'], ENT_QUOTES, 'UTF-8');
$shopPort = htmlspecialchars((string) $shopUrlParts['port'], ENT_QUOTES, 'UTF-8');
$brand = 'WentoCart';
$version = 'v1.0.0';
$isPreview = $preview ? 'true' : 'false';
$previewStepJs = (string) $previewStep;
$startStepJs = (string) $startStep;
$draftJson = json_encode(setup_draft_for_page(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
if (! is_string($draftJson)) {
    $draftJson = '{}';
}

$countryOptions = '';
foreach (setup_world_countries() as $country) {
    $code = htmlspecialchars($country['code'], ENT_QUOTES, 'UTF-8');
    $name = htmlspecialchars($country['name'], ENT_QUOTES, 'UTF-8');
    $countryOptions .= '<option value="'.$code.'">'.$name.'</option>';
}
$languageOptions = '';
foreach (setup_bundled_languages() as $language) {
    $code = htmlspecialchars($language['code'], ENT_QUOTES, 'UTF-8');
    $name = htmlspecialchars($language['name'], ENT_QUOTES, 'UTF-8');
    $selected = $language['code'] === 'en' ? ' selected' : '';
    $languageOptions .= '<option value="'.$code.'"'.$selected.'>'.$name.'</option>';
}
$regionMapJson = json_encode(setup_country_regions(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if (! is_string($regionMapJson)) {
    $regionMapJson = '{}';
}
$apacheShopUrl = $isPhpHelper ? setup_detect_apache_shop_url(dirname(__DIR__)) : null;
$apacheShopUrlJson = json_encode($apacheShopUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if (! is_string($apacheShopUrlJson)) {
    $apacheShopUrlJson = 'null';
}
$apacheShopUrlHtml = $apacheShopUrl !== null ? htmlspecialchars($apacheShopUrl, ENT_QUOTES, 'UTF-8') : '';

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $brand ?> Installer</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f3f4f8;
            --card: #fff;
            --ink: #0f172a;
            --muted: #64748b;
            --line: #e2e8f0;
            --brand: #5b21b6;
            --brand-2: #7c3aed;
            --brand-soft: #f3e8ff;
            --brand-mid: #ede9fe;
            --ok: #16a34a;
            --ok-soft: #f0fdf4;
            --bad: #dc2626;
            --bad-soft: #fef2f2;
            --info: #0369a1;
            --info-soft: #e0f2fe;
            --shadow: 0 18px 50px rgba(15, 23, 42, .08);
            --radius: 18px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Plus Jakarta Sans", system-ui, sans-serif;
            color: var(--ink);
            background:
                radial-gradient(1200px 500px at 10% -10%, #ede9fe 0%, transparent 55%),
                radial-gradient(900px 400px at 100% 0%, #e0e7ff 0%, transparent 50%),
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
        .brand strong { display: block; font-size: 1.15rem; letter-spacing: -.02em; }
        .brand .ver {
            display: inline-flex; margin-left: 8px; padding: 2px 8px; border-radius: 999px;
            background: #e2e8f0; color: var(--muted); font-size: 11px; font-weight: 700; vertical-align: middle;
        }
        .help {
            display: inline-flex; align-items: center; gap: 8px; color: var(--muted);
            text-decoration: none; font-size: 14px; font-weight: 600;
        }
        .help:hover { color: var(--brand); }
        .help i {
            width: 22px; height: 22px; border-radius: 999px; border: 1.5px solid #cbd5e1;
            display: grid; place-items: center; font-style: normal; font-size: 12px;
        }

        .stepper {
            display: flex; gap: 6px; align-items: flex-start; justify-content: space-between;
            background: var(--card); border: 1px solid var(--line); border-radius: 16px;
            padding: 18px 16px; margin-bottom: 20px; box-shadow: var(--shadow); overflow-x: auto;
        }
        .step {
            flex: 1; min-width: 110px; text-align: center; position: relative; color: var(--muted); font-size: 12px; font-weight: 600;
        }
        .step:not(:last-child)::after {
            content: ""; position: absolute; top: 15px; left: calc(50% + 18px); right: calc(-50% + 18px);
            height: 2px; background: #e2e8f0; z-index: 0;
        }
        .step.done:not(:last-child)::after { background: #86efac; }
        .step.on:not(:last-child)::after { background: linear-gradient(90deg, var(--brand-2), #e2e8f0); }
        .step .dot {
            width: 30px; height: 30px; border-radius: 999px; margin: 0 auto 8px;
            display: grid; place-items: center; background: #f1f5f9; color: #94a3b8;
            font-weight: 800; position: relative; z-index: 1; border: 2px solid transparent;
        }
        .step.on { color: var(--brand); }
        .step.on .dot { background: var(--brand); color: #fff; box-shadow: 0 0 0 4px #ede9fe; }
        .step.on .label { text-decoration: underline; text-underline-offset: 6px; text-decoration-thickness: 2px; }
        .step.done .dot { background: var(--ok); color: #fff; }
        .step.done { color: #166534; }

        .card {
            background: var(--card); border: 1px solid var(--line); border-radius: var(--radius);
            box-shadow: var(--shadow); overflow: hidden; display: grid; grid-template-columns: 300px 1fr; min-height: 560px;
        }
        .side {
            background: linear-gradient(180deg, #f5f3ff 0%, #faf5ff 100%);
            border-right: 1px solid #ede9fe; padding: 28px 24px; display: flex; flex-direction: column; gap: 18px;
        }
        .art {
            height: 160px; border-radius: 16px;
            background: radial-gradient(circle at 30% 30%, #ddd6fe, transparent 55%),
                        radial-gradient(circle at 75% 70%, #c4b5fd, transparent 48%),
                        #ede9fe;
            position: relative; overflow: hidden;
            display: grid; place-items: center;
        }
        .art svg { width: 140px; height: 120px; display: block; }
        .art .float-a { animation: floatA 3.2s ease-in-out infinite; }
        .art .float-b { animation: floatB 2.6s ease-in-out infinite; }
        .art .pulse { animation: pulseSoft 1.8s ease-in-out infinite; transform-origin: center; }
        .art .spin { animation: spinSlow 4s linear infinite; transform-origin: center; }
        .art .bounce { animation: bounceSoft 1.4s ease-in-out infinite; }
        .art .draw { stroke-dasharray: 48; stroke-dashoffset: 48; animation: drawIn 1.2s ease forwards 0.2s; }
        @keyframes floatA { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-8px); } }
        @keyframes floatB { 0%,100% { transform: translateY(0); } 50% { transform: translateY(6px); } }
        @keyframes pulseSoft { 0%,100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.08); opacity: .85; } }
        @keyframes spinSlow { to { transform: rotate(360deg); } }
        @keyframes bounceSoft { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }
        @keyframes drawIn { to { stroke-dashoffset: 0; } }
        .side h2 { margin: 0; font-size: 1.25rem; color: var(--brand); letter-spacing: -.02em; line-height: 1.3; }
        .side p { margin: 0; color: var(--muted); font-size: 14px; line-height: 1.55; }
        .callout {
            margin-top: auto; background: #efe9ff; border: 1px solid #ddd6fe; border-radius: 14px;
            padding: 14px; font-size: 13px; color: #5b21b6; line-height: 1.45;
        }
        .callout strong { display: block; margin-bottom: 4px; }
        .callout ul { margin: 8px 0 0; padding-left: 18px; }
        .callout.ok { background: var(--ok-soft); border-color: #bbf7d0; color: #166534; }
        .callout.info { background: #eff6ff; border-color: #bfdbfe; color: #1e40af; }

        .main { padding: 28px 28px 20px; display: flex; flex-direction: column; min-width: 0; }
        .main h1 { margin: 0 0 6px; font-size: 1.55rem; letter-spacing: -.03em; }
        .lead { margin: 0 0 22px; color: var(--muted); font-size: 14px; line-height: 1.5; }
        .panel { display: none; flex: 1; flex-direction: column; }
        .panel.on { display: flex; }
        .flash { margin-bottom: 14px; }
        .alert {
            white-space: pre-wrap;
            display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; border-radius: 12px; font-size: 14px; line-height: 1.45;
        }
        .alert.ok { background: var(--ok-soft); color: #166534; border: 1px solid #bbf7d0; }
        .alert.bad { background: var(--bad-soft); color: #991b1b; border: 1px solid #fecaca; }
        .alert.info { background: var(--brand-soft); color: #5b21b6; border: 1px solid #ddd6fe; }
        .alert .refresh { margin-left: auto; }

        .field[hidden], #helper-port-fields[hidden] { display: none !important; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 16px; }
        .grid .full { grid-column: 1 / -1; }
        .field label {
            display: flex; align-items: center; gap: 6px; margin: 0 0 6px;
            font-size: 13px; font-weight: 700; color: #334155;
        }
        .req { color: var(--bad); }
        .control {
            display: flex; align-items: center; gap: 10px;
            border: 1px solid #cbd5e1; border-radius: 12px; background: #fff; padding: 0 12px;
            transition: border-color .15s, box-shadow .15s;
        }
        .control:focus-within { border-color: var(--brand-2); box-shadow: 0 0 0 3px #ede9fe; }
        .control svg { flex: 0 0 auto; color: #94a3b8; }
        .control input, .control select, .control textarea {
            flex: 1; border: 0; outline: 0; background: transparent; padding: 12px 0; font: inherit; color: var(--ink); width: 100%;
        }
        .control textarea { resize: vertical; min-height: 84px; padding-top: 10px; }
        .hint { margin: 6px 0 0; font-size: 12px; color: var(--muted); }
        .hint code { font-size: 11px; background: #f1f5f9; padding: 1px 5px; border-radius: 4px; }
        .field-error {
            display: none; margin: 6px 0 0; font-size: 12px; font-weight: 600; color: var(--bad); line-height: 1.45;
        }
        .field.is-invalid .field-error { display: block; }
        .field.is-invalid .control {
            border-color: #fca5a5; box-shadow: 0 0 0 3px #fee2e2;
        }
        .field.is-ok .control { border-color: #86efac; }
        .url-detected {
            background: var(--brand-soft); border: 1px solid #ddd6fe; border-radius: 12px;
            padding: 12px 14px; font-size: 13px; line-height: 1.5; color: #4c1d95;
        }
        .url-detected strong { display: block; font-size: 12px; font-weight: 700; margin-bottom: 4px; }
        .url-detected code { font-size: 13px; word-break: break-all; background: #fff; padding: 2px 6px; border-radius: 6px; }
        .control input[readonly] { cursor: default; color: #334155; }
        .eye { border: 0; background: transparent; color: #94a3b8; cursor: pointer; padding: 4px; display: inline-flex; align-items: center; justify-content: center; }
        .eye svg { width: 18px; height: 18px; display: block; }
        .eye svg[hidden] { display: none !important; }

        table.checks { width: 100%; border-collapse: collapse; font-size: 14px; }
        table.checks th, table.checks td { text-align: left; padding: 12px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
        table.checks th { color: var(--muted); font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
        .status-pill {
            display: inline-flex; align-items: center; gap: 6px; font-weight: 700; font-size: 13px;
        }
        .status-pill.ok { color: var(--ok); }
        .status-pill.bad { color: var(--bad); }
        .ext { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
        .ext span { font-size: 11px; padding: 2px 8px; border-radius: 999px; border: 1px solid var(--line); color: var(--muted); }
        .ext span.ok { background: var(--ok-soft); border-color: #bbf7d0; color: var(--ok); }
        .ext span.bad { background: var(--bad-soft); border-color: #fecaca; color: var(--bad); }
        .row-hint { margin: 4px 0 0; font-size: 12px; color: var(--muted); }

        .progress-wrap { margin-bottom: 18px; }
        .progress-meta { display: flex; justify-content: space-between; font-size: 13px; font-weight: 700; margin-bottom: 8px; }
        .bar { height: 10px; background: #e2e8f0; border-radius: 999px; overflow: hidden; }
        .bar > i { display: block; height: 100%; width: 0; background: linear-gradient(90deg, var(--brand-2), var(--brand)); transition: width .35s cubic-bezier(.22,1,.36,1); position: relative; }
        .bar.bar-sm { height: 8px; }
        .bar.bar-sm > i::after { display: none; }
        .bar > i::after {
            content: ""; position: absolute; inset: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.35), transparent);
            animation: barSheen 1.6s infinite;
        }
        @keyframes barSheen { 0% { transform: translateX(-100%); } 100% { transform: translateX(100%); } }

        .tasks { list-style: none; margin: 0 0 14px; padding: 0; }
        .task {
            display: grid; grid-template-columns: 28px 1fr auto; gap: 12px; align-items: start;
            padding: 12px; border-radius: 14px; margin: 0 -4px;
            transition: background .25s ease;
        }
        .task[hidden] { display: none !important; }
        .task.run { background: #eff6ff; }
        .task .t-dot {
            width: 28px; height: 28px; border-radius: 999px; display: grid; place-items: center;
            background: #fff; color: #94a3b8; font-size: 12px; font-weight: 800;
            border: 2px solid #cbd5e1; margin-top: 1px;
        }
        .task.done .t-dot { background: var(--ok); border-color: var(--ok); color: #fff; }
        .task.run .t-dot { border-color: #2563eb; color: #2563eb; box-shadow: 0 0 0 4px rgba(37,99,235,.12); }
        .task.skip .t-dot { background: #f8fafc; border-color: #e2e8f0; color: #cbd5e1; }
        .task .t-title { font-weight: 700; font-size: 14px; letter-spacing: -.01em; }
        .task .t-sub { color: var(--muted); font-size: 12px; margin-top: 3px; line-height: 1.45; }
        .task.pending .t-title, .task.pending .t-sub { color: #94a3b8; }
        .task .t-counters { margin-top: 8px; display: grid; gap: 6px; }
        .task .t-counter {
            display: grid; grid-template-columns: 1fr auto; gap: 8px; align-items: center;
            font-size: 12px; color: #475569;
        }
        .task .t-counter strong { font-variant-numeric: tabular-nums; color: var(--ink); }
        .task .mini-bar { grid-column: 1 / -1; height: 6px; border-radius: 999px; background: #e2e8f0; overflow: hidden; }
        .task .mini-bar > i { display: block; height: 100%; width: 0; background: linear-gradient(90deg, #60a5fa, #2563eb); transition: width .25s ease; }
        .badge {
            font-size: 11px; font-weight: 800; padding: 4px 8px; border-radius: 999px; background: #f1f5f9; color: #64748b;
            align-self: start; margin-top: 2px;
        }
        .badge.ok { background: #dcfce7; color: #166534; }
        .badge.run { background: #dbeafe; color: #1d4ed8; }
        .badge.skip { background: #f8fafc; color: #94a3b8; }
        .spin-dot {
            width: 12px; height: 12px; border-radius: 50%;
            border: 2px solid rgba(37,99,235,.25); border-top-color: #2563eb;
            animation: spinFast .7s linear infinite;
        }

        .inst-details-toggle {
            display: inline-flex; align-items: center; gap: 6px; margin: 0 0 12px;
            padding: 0; border: 0; background: transparent; color: #475569;
            font: inherit; font-size: 13px; font-weight: 700; cursor: pointer;
        }
        .inst-details-toggle:hover { color: var(--brand); }
        .inst-details {
            border: 1px solid var(--line); background: #f8fafc;
            border-radius: 14px; padding: 12px; margin-bottom: 8px;
        }
        .inst-details[hidden] { display: none !important; }
        .inst-detail-live { margin-bottom: 10px; }
        .inst-detail-label {
            font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 8px;
            min-height: 1.2em;
        }

        .log {
            background: #0b1220; color: #cbd5e1; border-radius: 12px; padding: 12px 14px;
            font-family: ui-monospace, Consolas, monospace; font-size: 12px; height: 160px; overflow: auto; white-space: pre-wrap;
        }
        .log-head {
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            font-size: 12px; font-weight: 700; margin: 0 0 8px; color: #64748b;
        }

        details.adv {
            margin-top: 8px; border: 1px solid var(--line); border-radius: 12px; padding: 10px 14px; background: #f8fafc;
        }
        details.adv summary { cursor: pointer; font-weight: 700; color: #475569; }

        .strength { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-top: 10px; }
        .strength i { height: 6px; border-radius: 999px; background: #e2e8f0; }
        .strength[data-level="1"] i:nth-child(-n+1) { background: #f97316; }
        .strength[data-level="2"] i:nth-child(-n+2) { background: #eab308; }
        .strength[data-level="3"] i:nth-child(-n+3) { background: #22c55e; }
        .strength[data-level="4"] i { background: #16a34a; }
        .strength-label { margin-top: 6px; font-size: 12px; font-weight: 700; color: var(--muted); }
        .strength-label.strong { color: var(--ok); }

        .footer {
            margin-top: auto; padding-top: 18px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
            border-top: 1px solid #f1f5f9;
        }
        .footer .spacer { flex: 1; }
        .secure {
            width: 100%; margin-top: 8px; font-size: 12px; color: #166534; background: var(--ok-soft);
            border: 1px solid #bbf7d0; border-radius: 10px; padding: 10px 12px;
        }
        button, .btn {
            appearance: none; border: 0; border-radius: 12px; padding: 12px 18px; font: inherit; font-weight: 700;
            cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none;
        }
        button:disabled { opacity: .55; cursor: not-allowed; }
        .btn-primary { background: linear-gradient(135deg, var(--brand-2), var(--brand)); color: #fff; box-shadow: 0 10px 24px rgba(91,33,182,.28); }
        .btn-outline { background: #fff; color: var(--brand); border: 1.5px solid #c4b5fd; }
        .btn-ghost { background: transparent; color: var(--muted); }
        .btn-wait { background: #ede9fe; color: var(--brand); }
        .btn-busy { opacity: 1 !important; pointer-events: none; }
        .spin-inline {
            display: inline-block; width: 14px; height: 14px; border: 2px solid #c4b5fd;
            border-top-color: var(--brand); border-radius: 999px; animation: spinFast .7s linear infinite;
            vertical-align: -2px;
        }
        @keyframes spinFast { to { transform: rotate(360deg); } }

        @media (max-width: 900px) {
            .card { grid-template-columns: 1fr; }
            .side { border-right: 0; border-bottom: 1px solid #ede9fe; }
            .grid { grid-template-columns: 1fr; }
            .step { min-width: 96px; }
        }
    </style>
</head>
<body>
<div class="shell">
    <div class="topbar">
        <div class="brand">
            <img class="brand-logo" src="logo.jpeg" alt="<?= $brand ?>" width="40" height="40" onerror="this.onerror=null;this.src='../logo.jpeg'">
            <div>
                <strong><?= $brand ?><span class="ver"><?= $version ?></span></strong>
            </div>
        </div>
        <a class="help" href="https://wentocart.com/documentation/" target="_blank" rel="noopener" title="WentoCart Documentation">
            Need Help? <i>?</i>
        </a>
    </div>

    <nav class="stepper" aria-label="Installation steps">
        <div class="step on" id="tab-0"><div class="dot">1</div><div class="label">System Check</div></div>
        <div class="step" id="tab-1"><div class="dot">2</div><div class="label">Install Packages</div></div>
        <div class="step" id="tab-2"><div class="dot">3</div><div class="label">Store Information</div></div>
        <div class="step" id="tab-3"><div class="dot">4</div><div class="label">Database Setup</div></div>
        <div class="step" id="tab-4"><div class="dot">5</div><div class="label">Admin Account</div></div>
        <div class="step" id="tab-5"><div class="dot">6</div><div class="label">Installation</div></div>
    </nav>

    <div class="card">
        <aside class="side" id="side">
            <div class="art" id="side-art" aria-hidden="true"></div>
            <h2 id="side-title">Welcome to <?= $brand ?> Installer</h2>
            <p id="side-text">This wizard will guide you through installing your store. We check the PHP that opened this page only.</p>
            <div class="callout" id="side-callout"><strong>Secure &amp; Easy Installation</strong>Fix any red items, then continue. Other PHP installs on this PC are ignored.</div>
        </aside>

        <section class="main">
            <div class="flash" id="flash"></div>

            <div class="panel on" id="step-0">
                <h1>System Compatibility Check</h1>
                <p class="lead">We are checking your system environment to ensure a smooth installation.</p>
                <div style="overflow:auto">
                    <table class="checks">
                        <thead><tr><th>Environment</th><th>Found</th><th>Status</th></tr></thead>
                        <tbody id="compat-rows"></tbody>
                    </table>
                </div>
                <div class="footer">
                    <button type="button" class="btn-outline" id="reload-checks">Refresh Check</button>
                    <div class="spacer"></div>
                    <button type="button" class="btn-primary" id="go-prepare" disabled>Continue Setup →</button>
                </div>
            </div>

            <div class="panel" id="step-1">
                <h1>Installing Packages</h1>
                <p class="lead">We install the dependencies required to set up <?= $brand ?> on this copy. Stay on this page until it finishes.</p>
                <div class="progress-wrap">
                    <div class="progress-meta"><span>Overall Progress</span><span id="prep-pct">0%</span></div>
                    <div class="bar"><i id="bar"></i></div>
                </div>
                <ul class="tasks" id="prep-tasks">
                    <li class="task" data-phase="composer"><div class="t-dot">1</div><div><div class="t-title">Installing PHP Dependencies</div><div class="t-sub">Running composer install…</div></div><span class="badge">Pending</span><span class="t-time">-</span></li>
                    <li class="task" data-phase="npm"><div class="t-dot">2</div><div><div class="t-title">Installing Node.js Dependencies</div><div class="t-sub">Running npm install</div></div><span class="badge">Pending</span><span class="t-time">-</span></li>
                    <li class="task" data-phase="build"><div class="t-dot">3</div><div><div class="t-title">Building Frontend Assets</div><div class="t-sub">Running npm run build</div></div><span class="badge">Pending</span><span class="t-time">-</span></li>
                    <li class="task" data-phase="env"><div class="t-dot">4</div><div><div class="t-title">Configuring Environment</div><div class="t-sub">Setting up environment file…</div></div><span class="badge">Pending</span><span class="t-time">-</span></li>
                </ul>
                <div class="log-head">Installation Log</div>
                <div class="log" id="log">Waiting…</div>
                <div class="footer">
                    <button type="button" class="btn-outline" data-back="0">← Back</button>
                    <div class="spacer"></div>
                    <button type="button" class="btn-primary" id="start-prepare">Install Packages →</button>
                    <button type="button" class="btn-primary" id="go-store" disabled style="display:none">Continue →</button>
                </div>
            </div>

            <div class="panel" id="step-2">
                <h1>Store Information</h1>
                <p class="lead">Provide basic information about your store.</p>
                <div class="grid">
                    <div class="field">
                        <label>Store Name <span class="req">*</span></label>
                        <div class="control">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M4 10l8-6 8 6v9a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-9Z" stroke="currentColor" stroke-width="1.7"/></svg>
                            <input id="store_name" value="WentoCart" maxlength="120" autocomplete="organization">
                        </div>
                        <p class="hint">2–120 characters. This is the name customers see.</p>
                    </div>
                    <div class="field">
                        <label>Store Email <span class="req">*</span></label>
                        <div class="control">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M4 6h16v12H4V6Zm0 0 8 7 8-7" stroke="currentColor" stroke-width="1.7"/></svg>
                            <input id="store_email" type="email" value="" placeholder="shop@example.com" maxlength="190" autocomplete="email">
                        </div>
                        <p class="hint">Public contact email, e.g. shop@example.com</p>
                    </div>
                    <div class="field full">
                        <label>Shop address <span class="hint" style="font-weight:500">(optional, any country)</span></label>
                        <p class="hint" style="margin-top:0">Invoices and contact. Fill only what you have. Do not put the website URL here.</p>
                    </div>
                    <div class="field full">
                        <label>Country</label>
                        <div class="control">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7"/><path d="M3 12h18M12 3c3 3.5 3 14.5 0 18M12 3c-3 3.5-3 14.5 0 18" stroke="currentColor" stroke-width="1.7"/></svg>
                            <select id="store_country" autocomplete="country">
                                <option value="">Select country</option>
                                <?= $countryOptions ?>
                            </select>
                        </div>
                    </div>
                    <div class="field full">
                        <label>Address line 1</label>
                        <div class="control">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M4 10l8-6 8 6v9a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-9Z" stroke="currentColor" stroke-width="1.7"/></svg>
                            <input id="store_line1" maxlength="255" autocomplete="address-line1" placeholder="Street, building, shop number">
                        </div>
                    </div>
                    <div class="field full">
                        <label>Address line 2</label>
                        <div class="control">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M4 10l8-6 8 6v9a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-9Z" stroke="currentColor" stroke-width="1.7"/></svg>
                            <input id="store_line2" maxlength="255" autocomplete="address-line2" placeholder="Apartment, suite, unit">
                        </div>
                    </div>
                    <div class="field">
                        <label>City</label>
                        <div class="control">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 21s7-5.3 7-11a7 7 0 1 0-14 0c0 5.7 7 11 7 11Z" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="10" r="2.5" stroke="currentColor" stroke-width="1.7"/></svg>
                            <input id="store_city" maxlength="120" autocomplete="address-level2">
                        </div>
                    </div>
                    <div class="field" id="store-region-field" hidden>
                        <label>State / region / province</label>
                        <div class="control">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M4 6h16v4H4V6Zm2 8h6M4 14h16v6H4v-6Z" stroke="currentColor" stroke-width="1.7"/></svg>
                            <select id="store_region" autocomplete="address-level1">
                                <option value="">Select state</option>
                            </select>
                        </div>
                        <p class="hint">Shown only when this country has a state list.</p>
                    </div>
                    <div class="field">
                        <label>Postal / ZIP code</label>
                        <div class="control">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M3 9h18" stroke="currentColor" stroke-width="1.7"/></svg>
                            <input id="store_postal" maxlength="32" autocomplete="postal-code" placeholder="ZIP, PIN, postcode">
                        </div>
                    </div>
                    <div class="field">
                        <label>Default Currency</label>
                        <div class="control">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8" stroke="currentColor" stroke-width="1.7"/><path d="M12 7v10M9.5 9.5c.8-1 2-1.5 2.5-1.5s1.7.5 1.7 1.5S13 11 12 11s-2.2.5-2.2 1.7 1 1.8 2.2 1.8 1.8-.4 2.4-1.2" stroke="currentColor" stroke-width="1.7"/></svg>
                            <select id="currency">
                                <option value="USD" selected>USD (US Dollar)</option>
                                <option value="EUR">EUR (Euro)</option>
                                <option value="INR">INR (Indian Rupee)</option>
                                <option value="GBP">GBP (Pound)</option>
                            </select>
                        </div>
                    </div>
                    <div class="field">
                        <label>Language</label>
                        <div class="control">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7"/><path d="M3 12h18M12 3c3 3.5 3 14.5 0 18M12 3c-3 3.5-3 14.5 0 18" stroke="currentColor" stroke-width="1.7"/></svg>
                            <select id="language">
                                <?= $languageOptions ?>
                            </select>
                        </div>
                    </div>
                    <div class="field full">
                        <label>Shop address on this computer</label>
                        <?php if ($isPhpHelper && $apacheShopUrl !== null): ?>
                        <div class="grid" style="margin:0">
                            <label class="control" style="cursor:pointer;align-items:flex-start;grid-column:1 / -1">
                                <input type="radio" name="serve_via" id="serve_apache" value="apache" checked style="width:auto;margin-top:3px">
                                <span>XAMPP / Apache — <strong><?= $apacheShopUrlHtml ?></strong></span>
                            </label>
                            <p class="hint" style="grid-column:1 / -1;margin:0">Storefront: <code><?= $apacheShopUrlHtml ?>/</code> &nbsp; Admin: <code><?= $apacheShopUrlHtml ?>/admin</code>. Install.vbs still runs this wizard. After install, open the shop at that XAMPP address.</p>
                            <label class="control" style="cursor:pointer;align-items:flex-start;grid-column:1 / -1">
                                <input type="radio" name="serve_via" id="serve_helper" value="helper" style="width:auto;margin-top:3px">
                                <span>This installer window only</span>
                            </label>
                        </div>
                        <div id="helper-port-fields" hidden>
                        <div class="grid" style="margin:8px 0 0">
                            <div class="field">
                                <label>URL</label>
                                <div class="control">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M10 14a4 4 0 0 1 0-5.7l1.4-1.4a4 4 0 0 1 5.7 5.7L16 14M14 10a4 4 0 0 1 0 5.7l-1.4 1.4a4 4 0 0 1-5.7-5.7L8 10" stroke="currentColor" stroke-width="1.7"/></svg>
                                    <input value="<?= $shopHostUrl ?>" readonly tabindex="-1" autocomplete="off" spellcheck="false" aria-readonly="true">
                                </div>
                            </div>
                            <div class="field">
                                <label>Port</label>
                                <div class="control">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><rect x="4" y="5" width="16" height="14" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M8 9h8M8 12h5" stroke="currentColor" stroke-width="1.7"/></svg>
                                    <input id="shop_port" value="<?= $shopPort ?>" inputmode="numeric" autocomplete="off" spellcheck="false">
                                </div>
                            </div>
                        </div>
                        <p class="hint">URL cannot be edited. Change Port if you want a different number than this installer window.</p>
                        </div>
                        <?php elseif ($isPhpHelper): ?>
                        <div class="grid" style="margin:0">
                            <div class="field">
                                <label>URL</label>
                                <div class="control">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M10 14a4 4 0 0 1 0-5.7l1.4-1.4a4 4 0 0 1 5.7 5.7L16 14M14 10a4 4 0 0 1 0 5.7l-1.4 1.4a4 4 0 0 1-5.7-5.7L8 10" stroke="currentColor" stroke-width="1.7"/></svg>
                                    <input value="<?= $shopHostUrl ?>" readonly tabindex="-1" autocomplete="off" spellcheck="false" aria-readonly="true">
                                </div>
                            </div>
                            <div class="field">
                                <label>Port</label>
                                <div class="control">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><rect x="4" y="5" width="16" height="14" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M8 9h8M8 12h5" stroke="currentColor" stroke-width="1.7"/></svg>
                                    <input id="shop_port" value="<?= $shopPort ?>" inputmode="numeric" autocomplete="off" spellcheck="false">
                                </div>
                            </div>
                        </div>
                        <p class="hint">URL cannot be edited. Change Port if you want a different number than this installer window; we start that Port during install and open it when setup finishes. Leave this installer window open until then. After that you can set a public website in Admin → Store.</p>
                        <?php else: ?>
                        <div class="url-detected">
                            <strong>Installing at</strong>
                            <code id="detected-url-label"><?= $appUrl ?></code>
                        </div>
                        <p class="hint">This address is taken from the page you opened. It cannot be typed here. After install, you can set a public website address in Admin → Store.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="footer">
                    <button type="button" class="btn-outline" data-back="1">← Back</button>
                    <div class="spacer"></div>
                    <button type="button" class="btn-primary" id="go-db">Continue →</button>
                </div>
            </div>

            <div class="panel" id="step-3">
                <h1>Database Setup</h1>
                <p class="lead">Enter the MySQL connection details provided by your host or local database server. Use Test Connection before continuing.</p>
                <div class="grid">
                    <div class="field">
                        <label>Database Host</label>
                        <div class="control">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M3 9h18" stroke="currentColor" stroke-width="1.7"/></svg>
                            <input id="db_host" value="127.0.0.1" autocomplete="off">
                        </div>
                        <p class="hint">Usually 127.0.0.1 or localhost</p>
                    </div>
                    <div class="field">
                        <label>Database Port</label>
                        <div class="control">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M8 7h8v10H8V7Zm0 3h8M8 14h8" stroke="currentColor" stroke-width="1.7"/></svg>
                            <input id="db_port" value="3306" inputmode="numeric" autocomplete="off">
                        </div>
                        <p class="hint">MySQL port, usually 3306</p>
                    </div>
                    <div class="field">
                        <label>Database Name <span class="req">*</span></label>
                        <div class="control">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><ellipse cx="12" cy="6" rx="7" ry="3" stroke="currentColor" stroke-width="1.7"/><path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6" stroke="currentColor" stroke-width="1.7"/></svg>
                            <input id="db_database" value="wentocart" maxlength="64" autocomplete="off">
                        </div>
                        <p class="hint">Must be an empty database. Letters, numbers, and _ only.</p>
                    </div>
                    <div class="field">
                        <label>Database Username <span class="req">*</span></label>
                        <div class="control">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.7"/><path d="M5 19c1.5-3 4-4.5 7-4.5S17.5 16 19 19" stroke="currentColor" stroke-width="1.7"/></svg>
                            <input id="db_username" value="root" maxlength="80" autocomplete="off">
                        </div>
                        <p class="hint">MySQL user that can create tables in this database</p>
                    </div>
                    <div class="field">
                        <label>Database Password</label>
                        <div class="control">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M8 10V8a4 4 0 0 1 8 0v2" stroke="currentColor" stroke-width="1.7"/></svg>
                            <input id="db_password" type="password" autocomplete="new-password">
                            <button type="button" class="eye" data-toggle="db_password" aria-label="Show password">
                                <svg class="eye-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-hide" hidden viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 3l18 18"/><path d="M10.6 10.6A3 3 0 0 0 13.4 13.4"/><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a16.8 16.8 0 0 1-3.2 4.4"/><path d="M6.1 6.1A16.7 16.7 0 0 0 2 12s3.5 7 10 7a10.8 10.8 0 0 0 4.1-.8"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="field">
                        <label>Table Prefix <span class="req">*</span></label>
                        <div class="control">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M4 6h16v4H4V6Zm0 8h7v4H4v-4Zm9 0h7v4h-7v-4Z" stroke="currentColor" stroke-width="1.7"/></svg>
                            <input id="db_prefix" value="wento" maxlength="32" autocomplete="off" spellcheck="false">
                        </div>
                        <p class="hint">Suggestion: start with a letter, then letters or numbers. You may add <strong>one or more underscores</strong> (<code>_</code>), e.g. <code>wento</code>, <code>tiger_</code>, <code>tia_2_</code>, <code>Lo_as_as_</code>. No spaces or hyphens. Extra trailing _ becomes a single separator: tables are PREFIX_products.</p>
                        <p class="hint" id="prefix-preview"></p>
                    </div>
                </div>
                <div class="footer">
                    <button type="button" class="btn-outline" data-back="2">← Back</button>
                    <button type="button" class="btn-outline" id="test-db">Test Connection</button>
                    <div class="spacer"></div>
                    <button type="button" class="btn-primary" id="go-admin">Continue →</button>
                </div>
            </div>

            <div class="panel" id="step-4">
                <h1>Admin Account</h1>
                <p class="lead">Set up your administrator account to access the dashboard.</p>
                <div class="grid">
                    <div class="field">
                        <label>Admin Name <span class="req">*</span></label>
                        <div class="control">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.7"/><path d="M5 19c1.5-3 4-4.5 7-4.5S17.5 16 19 19" stroke="currentColor" stroke-width="1.7"/></svg>
                            <input id="admin_name" value="Administrator" maxlength="120" autocomplete="name">
                        </div>
                        <p class="hint">Shown in the admin panel</p>
                    </div>
                    <div class="field">
                        <label>Admin Email <span class="req">*</span></label>
                        <div class="control">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M4 6h16v12H4V6Zm0 0 8 7 8-7" stroke="currentColor" stroke-width="1.7"/></svg>
                            <input id="admin_email" type="email" placeholder="admin@wentocart.com" maxlength="190" autocomplete="email">
                        </div>
                        <p class="hint">This is the login email for /admin</p>
                    </div>
                    <div class="field">
                        <label>Admin Password <span class="req">*</span></label>
                        <div class="control">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M8 10V8a4 4 0 0 1 8 0v2" stroke="currentColor" stroke-width="1.7"/></svg>
                            <input id="admin_password" type="password" autocomplete="new-password">
                            <button type="button" class="eye" data-toggle="admin_password" aria-label="Show password">
                                <svg class="eye-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-hide" hidden viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 3l18 18"/><path d="M10.6 10.6A3 3 0 0 0 13.4 13.4"/><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a16.8 16.8 0 0 1-3.2 4.4"/><path d="M6.1 6.1A16.7 16.7 0 0 0 2 12s3.5 7 10 7a10.8 10.8 0 0 0 4.1-.8"/></svg>
                            </button>
                        </div>
                        <div class="strength" id="pw-strength" data-level="0"><i></i><i></i><i></i><i></i></div>
                        <div class="strength-label" id="pw-label">Use at least 8 characters</div>
                    </div>
                    <div class="field">
                        <label>Confirm Password <span class="req">*</span></label>
                        <div class="control">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M8 10V8a4 4 0 0 1 8 0v2" stroke="currentColor" stroke-width="1.7"/></svg>
                            <input id="admin_password_confirm" type="password" autocomplete="new-password">
                            <button type="button" class="eye" data-toggle="admin_password_confirm" aria-label="Show password">
                                <svg class="eye-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-hide" hidden viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 3l18 18"/><path d="M10.6 10.6A3 3 0 0 0 13.4 13.4"/><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a16.8 16.8 0 0 1-3.2 4.4"/><path d="M6.1 6.1A16.7 16.7 0 0 0 2 12s3.5 7 10 7a10.8 10.8 0 0 0 4.1-.8"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="full">
                        <div class="alert info">Important: This is the administrator account for your store’s backend. You can create additional staff accounts after installation.</div>
                    </div>
                    <div class="field full">
                        <label>Install demo data</label>
                        <div class="grid" style="grid-template-columns:1fr 1fr;gap:12px;margin-top:8px">
                            <label class="control" style="cursor:pointer;border:1px solid #e2e8f0;border-radius:12px;padding:12px">
                                <input type="radio" name="install_demo_data" id="install_demo_yes" value="1" style="width:auto;margin-right:8px">
                                Yes — sample products, customers, orders (offline images, fast)
                            </label>
                            <label class="control" style="cursor:pointer;border:1px solid #e2e8f0;border-radius:12px;padding:12px">
                                <input type="radio" name="install_demo_data" id="install_demo_no" value="0" checked style="width:auto;margin-right:8px">
                                No — empty catalog, ready for my data
                            </label>
                        </div>
                        <div class="strength-label">Demo data includes about 20 products, 20 customers, 20 orders, payment and shipping methods, and discount rules. Demo accounts use public passwords — only install demo data on a trial store, never on a live shop.</div>
                    </div>
                </div>
                <div class="footer">
                    <button type="button" class="btn-outline" data-back="3">← Back</button>
                    <div class="spacer"></div>
                    <button type="button" class="btn-primary" id="go-install">Continue →</button>
                </div>
            </div>

            <div class="panel" id="step-5">
                <h1>Installing your store</h1>
                <p class="lead">Setting everything up for you. Please keep this window open.</p>
                <div class="progress-wrap">
                    <div class="progress-meta"><span>Overall progress</span><span id="inst-pct">0%</span></div>
                    <div class="bar"><i id="inst-bar"></i></div>
                </div>
                <ul class="tasks" id="inst-tasks">
                    <li class="task pending" data-phase="database">
                        <div class="t-dot">1</div>
                        <div>
                            <div class="t-title">Database</div>
                            <div class="t-sub" data-default="Create your store tables…">Create your store tables…</div>
                        </div>
                        <span class="badge">Pending</span>
                    </li>
                    <li class="task pending" data-phase="configuration">
                        <div class="t-dot">2</div>
                        <div>
                            <div class="t-title">Store configuration</div>
                            <div class="t-sub" data-default="Languages, currencies, regions &amp; permissions">Languages, currencies, regions &amp; permissions</div>
                        </div>
                        <span class="badge">Pending</span>
                    </li>
                    <li class="task pending" data-phase="design">
                        <div class="t-dot">3</div>
                        <div>
                            <div class="t-title">Store design</div>
                            <div class="t-sub" data-default="Theme assets…">Theme assets…</div>
                        </div>
                        <span class="badge">Pending</span>
                    </li>
                    <li class="task pending" data-phase="demo">
                        <div class="t-dot">4</div>
                        <div>
                            <div class="t-title">Demo data</div>
                            <div class="t-sub" data-default="Sample products, customers, and orders…">Sample products, customers, and orders…</div>
                        </div>
                        <span class="badge">Pending</span>
                    </li>
                    <li class="task pending" data-phase="admin">
                        <div class="t-dot">5</div>
                        <div>
                            <div class="t-title">Admin account</div>
                            <div class="t-sub" data-default="Your administrator login…">Your administrator login…</div>
                        </div>
                        <span class="badge">Pending</span>
                    </li>
                    <li class="task pending" data-phase="finish">
                        <div class="t-dot">6</div>
                        <div>
                            <div class="t-title">Installation complete</div>
                            <div class="t-sub" data-default="Your store is ready…">Your store is ready…</div>
                        </div>
                        <span class="badge">Pending</span>
                    </li>
                </ul>
                <button type="button" class="inst-details-toggle" id="inst-details-toggle" aria-expanded="false">
                    ▸ View installation details
                </button>
                <div class="inst-details" id="inst-details" hidden>
                    <div class="inst-detail-live">
                        <div class="inst-detail-label" id="inst-detail-label">Waiting to start…</div>
                        <div class="progress-meta"><span id="inst-count-label">Progress</span><span id="inst-detail-pct">0%</span></div>
                        <div class="bar bar-sm"><i id="inst-detail-bar"></i></div>
                    </div>
                    <div class="log-head"><span>Technical log</span></div>
                    <div class="log" id="inst-log">Waiting to start…</div>
                </div>
                <div class="footer">
                    <button type="button" class="btn-outline" id="inst-back" data-back="4">← Back</button>
                    <div class="spacer"></div>
                    <button type="button" class="btn-wait" id="install-wait" disabled>
                        <span class="spin">◐</span> Please Wait…
                    </button>
                </div>
            </div>
        </section>
    </div>
</div>

<script>
const TOKEN = '<?= $token ?>';
const INSTALL_DRAFT = <?= $draftJson ?>;
const BRAND = '<?= $brand ?>';
const panels = [0,1,2,3,4,5].map((i) => document.getElementById('step-' + i));
const tabs = [0,1,2,3,4,5].map((i) => document.getElementById('tab-' + i));
let ready = false;
let prepared = false;
let pollTimer = null;
let installTimer = null;
let installPollMisses = 0;
const detectedAppUrl = <?= json_encode($detectedShopUrl, JSON_UNESCAPED_SLASHES) ?>;
const COUNTRY_REGIONS = <?= $regionMapJson ?>;
const APACHE_SHOP_URL = <?= $apacheShopUrlJson ?>;

const sideArts = [
    // 0 system check
    `<svg viewBox="0 0 140 120" fill="none" xmlns="http://www.w3.org/2000/svg">
      <rect class="float-a" x="28" y="22" width="72" height="52" rx="10" fill="#fff" stroke="#7c3aed" stroke-width="2.5"/>
      <rect class="float-a" x="36" y="30" width="56" height="28" rx="4" fill="#ede9fe"/>
      <circle class="pulse" cx="96" cy="78" r="18" fill="#5b21b6"/>
      <path class="draw" d="M88 78l5.5 5.5 11-12" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
      <rect x="48" y="82" width="32" height="6" rx="3" fill="#c4b5fd" opacity=".7"/>
    </svg>`,
    // 1 packages
    `<svg viewBox="0 0 140 120" fill="none" xmlns="http://www.w3.org/2000/svg">
      <g class="float-a">
        <path d="M40 48l30-14 30 14v36l-30 14-30-14V48Z" fill="#fff" stroke="#7c3aed" stroke-width="2.5"/>
        <path d="M40 48l30 14 30-14M70 62v36" stroke="#7c3aed" stroke-width="2.5"/>
      </g>
      <circle class="bounce" cx="108" cy="34" r="10" fill="#5b21b6"/>
      <rect class="float-b" x="18" y="28" width="16" height="16" rx="4" fill="#a78bfa"/>
      <rect class="pulse" x="100" y="78" width="18" height="18" rx="5" fill="#c4b5fd"/>
    </svg>`,
    // 2 store
    `<svg viewBox="0 0 140 120" fill="none" xmlns="http://www.w3.org/2000/svg">
      <g class="float-a">
        <path d="M34 50h72v42a8 8 0 0 1-8 8H42a8 8 0 0 1-8-8V50Z" fill="#fff" stroke="#7c3aed" stroke-width="2.5"/>
        <path d="M30 50l10-18h60l10 18H30Z" fill="#ede9fe" stroke="#7c3aed" stroke-width="2.5" stroke-linejoin="round"/>
        <rect x="58" y="66" width="24" height="34" rx="3" fill="#5b21b6"/>
      </g>
      <circle class="pulse" cx="108" cy="36" r="8" fill="#22c55e"/>
    </svg>`,
    // 3 database
    `<svg viewBox="0 0 140 120" fill="none" xmlns="http://www.w3.org/2000/svg">
      <g class="float-a">
        <ellipse cx="70" cy="34" rx="32" ry="12" fill="#ede9fe" stroke="#7c3aed" stroke-width="2.5"/>
        <path d="M38 34v36c0 6.6 14.3 12 32 12s32-5.4 32-12V34" stroke="#7c3aed" stroke-width="2.5" fill="#fff"/>
        <ellipse cx="70" cy="70" rx="32" ry="12" fill="#ede9fe" stroke="#7c3aed" stroke-width="2.5"/>
      </g>
      <circle class="bounce" cx="108" cy="56" r="10" fill="#5b21b6"/>
      <path d="M104 56h8M108 52v8" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
    </svg>`,
    // 4 admin
    `<svg viewBox="0 0 140 120" fill="none" xmlns="http://www.w3.org/2000/svg">
      <g class="float-a">
        <rect x="36" y="28" width="68" height="52" rx="10" fill="#fff" stroke="#7c3aed" stroke-width="2.5"/>
        <circle cx="70" cy="48" r="10" fill="#ede9fe" stroke="#7c3aed" stroke-width="2"/>
        <path d="M52 68c4-8 10-12 18-12s14 4 18 12" stroke="#7c3aed" stroke-width="2" stroke-linecap="round"/>
      </g>
      <g class="pulse">
        <path d="M100 70l14 6v12c0 8-6 14-14 18-8-4-14-10-14-18V76l14-6Z" fill="#5b21b6"/>
        <path d="M94 84l5 5 9-10" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
      </g>
    </svg>`,
    // 5 installing
    `<svg viewBox="0 0 140 120" fill="none" xmlns="http://www.w3.org/2000/svg">
      <rect class="float-b" x="34" y="30" width="72" height="50" rx="10" fill="#fff" stroke="#7c3aed" stroke-width="2.5"/>
      <circle class="spin" cx="70" cy="55" r="14" stroke="#c4b5fd" stroke-width="3" stroke-dasharray="60 20" fill="none"/>
      <circle class="pulse" cx="70" cy="55" r="6" fill="#5b21b6"/>
      <circle class="bounce" cx="108" cy="36" r="8" fill="#22c55e"/>
      <path d="M104 36l3 3 6-7" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>`,
];

const sideCopy = [
    { title: 'Welcome to ' + BRAND + ' Installer', text: 'This wizard will guide you through installing your store. We check the PHP that opened this page only.', callout: '<strong>Secure &amp; Easy Installation</strong>Fix any red items, then continue. Other PHP installs on this PC are ignored.', cls: '' },
    { title: 'Install Required Packages', text: 'Dependencies for setting up ' + BRAND + ' — PHP packages, frontend tools, and built assets for this copy.', callout: '<strong>What\'s included?</strong><ul><li>PHP application dependencies</li><li>Node.js packages</li><li>Admin &amp; storefront build</li><li>Environment configuration</li></ul>', cls: '' },
    { title: 'Tell us about your store', text: 'Please provide your store details. You can change these settings later from the admin panel.', callout: '<strong>Why we need this?</strong>This information configures your store name, contact email, and public URL.', cls: '' },
    { title: 'Database Connection', text: 'Your store needs a MySQL database. Enter the host, port, database name, and user credentials from your server or hosting panel.', callout: '<strong>Need help?</strong>Ask your host or database admin for connection details. Host is often <code>localhost</code> or <code>127.0.0.1</code>; port and password depend on your MySQL setup.', cls: 'info' },
    { title: 'Create Admin Account', text: 'This account will have full access to your store’s admin panel. Keep your credentials safe.', callout: '<strong>Tips for a strong password</strong><ul><li>Use at least 8 characters</li><li>Include upper &amp; lowercase</li><li>Add numbers and symbols</li><li>Avoid common words</li></ul>', cls: '' },
    { title: 'Installing your store', text: 'Setting everything up for you — stages update with real progress, not spammy log lines.', callout: '<strong>Please do not close this window</strong>This may take a few minutes depending on your server.', cls: '' },
];

function show(step) {
    panels.forEach((p, i) => p.classList.toggle('on', i === step));
    tabs.forEach((t, i) => {
        t.classList.toggle('on', i === step);
        t.classList.toggle('done', i < step);
        const dot = t.querySelector('.dot');
        if (i < step) dot.textContent = '✓';
        else dot.textContent = String(i + 1);
    });
    const s = sideCopy[step];
    document.getElementById('side-title').textContent = s.title;
    document.getElementById('side-text').textContent = s.text;
    document.getElementById('side-art').innerHTML = sideArts[step] || '';
    const call = document.getElementById('side-callout');
    call.className = 'callout' + (s.cls ? ' ' + s.cls : '');
    call.innerHTML = s.callout;
    flash('');
}
function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (ch) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[ch]));
}
function flash(text, ok) {
    const el = document.getElementById('flash');
    if (!text) { el.innerHTML = ''; return; }
    el.innerHTML = '<div class="alert ' + (ok ? 'ok' : 'bad') + '">' + escapeHtml(text) + '</div>';
}
function payload() {
    return {
        store_name: document.getElementById('store_name').value,
        store_email: document.getElementById('store_email').value,
        store_country: document.getElementById('store_country').value,
        store_line1: document.getElementById('store_line1').value,
        store_line2: document.getElementById('store_line2').value,
        store_city: document.getElementById('store_city').value,
        store_region: (document.getElementById('store-region-field') || {}).hidden ? '' : document.getElementById('store_region').value,
        store_postal: document.getElementById('store_postal').value,
        app_url: detectedAppUrl,
        serve_via: (document.getElementById('serve_apache') && document.getElementById('serve_apache').checked) ? 'apache' : 'helper',
        apache_url: APACHE_SHOP_URL || '',
        shop_port: (document.getElementById('shop_port') || {}).value || '',
        currency: document.getElementById('currency').value,
        language: document.getElementById('language').value,
        db_database: document.getElementById('db_database').value,
        db_username: document.getElementById('db_username').value,
        db_password: document.getElementById('db_password').value,
        db_prefix: document.getElementById('db_prefix').value,
        db_host: document.getElementById('db_host').value,
        db_port: document.getElementById('db_port').value,
        admin_name: document.getElementById('admin_name').value,
        admin_email: document.getElementById('admin_email').value,
        admin_password: document.getElementById('admin_password').value,
        install_demo_data: document.getElementById('install_demo_yes').checked,
    };
}
async function api(action, body) {
    const res = await fetch('api.php?action=' + action, {
        method: body ? 'POST' : 'GET',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-SETUP-TOKEN': TOKEN },
        body: body ? JSON.stringify(body) : null,
    });
    const json = await res.json().catch(() => ({ ok: false, message: 'Request failed.' }));
    if (!res.ok && !json.message) json.message = 'Request failed.';
    return json;
}
function renderChecks(c) {
    ready = !!c.ready;
    prepared = !!c.prepared;
    const tb = document.getElementById('compat-rows');
    tb.innerHTML = c.rows.map((row) => {
        const status = row.status || (row.ok ? 'Compatible' : 'Incomplete');
        let extra = row.hint ? '<div class="row-hint">' + row.hint + '</div>' : '';
        if (row.extensions) {
            extra += '<div class="ext">' + row.extensions.map((e) =>
                '<span class="' + (e.ok ? 'ok' : 'bad') + '">' + e.name + ': ' + (e.ok ? 'Yes' : 'No') + '</span>'
            ).join('') + '</div>';
        }
        return '<tr><td>' + row.label + '</td><td>' + row.value + extra + '</td><td><span class="status-pill ' + (row.ok ? 'ok' : 'bad') + '">' + (row.ok ? '✓ ' : '✕ ') + status + '</span></td></tr>';
    }).join('');
    document.getElementById('go-prepare').disabled = !ready;
    if (prepared) markPreparedUi();
}
function markPreparedUi() {
    prepared = true;
    document.getElementById('go-store').disabled = false;
    document.getElementById('go-store').style.display = '';
    document.getElementById('start-prepare').style.display = 'none';
    document.getElementById('prep-pct').textContent = '100%';
    document.getElementById('bar').style.width = '100%';
    document.querySelectorAll('#prep-tasks .task').forEach((t) => setTask(t, 'done'));
    const prepLog = document.getElementById('log');
    const prepText = (prepLog.textContent || '').trim();
    if (prepText === '' || prepText === 'Waiting…') {
        prepLog.textContent = 'Packages are already installed.\nReady to continue.';
    }
}
function restoreDraft(draft) {
    if (!draft || typeof draft !== 'object') return;
    const fields = {
        store_name: 'store_name',
        store_email: 'store_email',
        store_country: 'store_country',
        store_line1: 'store_line1',
        store_line2: 'store_line2',
        store_city: 'store_city',
        store_region: 'store_region',
        store_postal: 'store_postal',
        db_host: 'db_host',
        db_port: 'db_port',
        db_database: 'db_database',
        db_username: 'db_username',
        db_prefix: 'db_prefix',
        admin_name: 'admin_name',
        admin_email: 'admin_email',
        language: 'language',
        currency: 'currency',
    };
    const pendingRegion = draft.store_region || '';
    Object.entries(fields).forEach(([key, id]) => {
        const el = document.getElementById(id);
        if (!el || draft[key] === undefined || draft[key] === null || draft[key] === '') return;
        if (id === 'store_region') return;
        el.value = draft[key];
    });
    syncRegionField(pendingRegion);
    if (draft.serve_via === 'helper' && document.getElementById('serve_helper')) {
        document.getElementById('serve_helper').checked = true;
    }
    if (draft.serve_via === 'apache' && document.getElementById('serve_apache')) {
        document.getElementById('serve_apache').checked = true;
    }
    syncServeVia();
    if (draft.install_demo_data === false || draft.install_demo_data === 0 || draft.install_demo_data === '0') {
        const no = document.getElementById('install_demo_no');
        if (no) no.checked = true;
    }
    prefixPreview();
    updateStrength();
}
async function rememberDraft() {
    try {
        await api('save-draft', payload());
    } catch (e) {
        // The install step still saves a draft of its own.
    }
}
function showInstallRetry() {
    const btn = document.getElementById('install-wait');
    btn.disabled = false;
    btn.classList.remove('btn-wait');
    btn.classList.add('btn-primary');
    btn.innerHTML = 'Try again';
    btn.onclick = () => startInstall(true);
}
function resetInstallWait() {
    const btn = document.getElementById('install-wait');
    btn.disabled = true;
    btn.classList.add('btn-wait');
    btn.classList.remove('btn-primary');
    btn.innerHTML = '<span class="spin">◐</span> Please Wait…';
    btn.onclick = null;
}
async function resumeInstall() {
    if (PREVIEW) return false;
    try {
        const res = await fetch('api.php?action=install-status', { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
        const json = await res.json();
        const s = json && json.status;
        if (!s || (s.ok !== false && !s.running && s.ok !== true)) return false;
        if ((s.phase === 'idle' || s.phase === '') && !s.error && s.ok !== true) return false;
        if (s.ok === true) {
            show(5);
            paintInstall(s);
            openInstalledPage(s.redirect || installedFallbackUrl());
            return true;
        }
        show(5);
        paintInstall(s);
        if (s.running) {
            clearInterval(installTimer);
            installTimer = setInterval(pollInstallStatus, 800);
            return true;
        }
        flash(s.error || 'Install failed. Fix the problem, then try again.', false);
        document.getElementById('inst-back').disabled = false;
        showInstallRetry();
        return true;
    } catch (e) {
        return false;
    }
}
async function loadChecks() {
    const json = await api('checks');
    if (!json.ok) {
        flash(json.message || 'Could not read compatibility.', false);
        if (json.permission) show(0);
        return;
    }
    renderChecks(json.compatibility);
    restoreDraft(INSTALL_DRAFT);
    if (!json.compatibility.ready) {
        flash('Some requirements are not met. Fix the issues above and click Refresh Check.', false);
        show(0);
        return;
    }
    if (await resumeInstall()) return;
    // After wrapper unpack (?start=1), skip System Check — and skip Prepare when assets are already in the zip.
    if (START_STEP >= 1) {
        if (prepared) {
            show(2);
            flash('Packages are ready. Continue with store details.', true);
        } else {
            show(1);
            flash('');
        }
        return;
    }
    flash('Compatibility looks good. Continue setup.', true);
    show(0);
}
function prefixPreview() {
    const raw = (document.getElementById('db_prefix').value || '').trim();
    const el = document.getElementById('prefix-preview');
    const err = prefixError(raw);
    if (err) {
        el.textContent = raw ? err : 'Example tables: WENTO_admins, WENTO_products, WENTO_orders';
        return;
    }
    const stem = raw.replace(/_+$/, '').toUpperCase();
    el.textContent = 'Looks good. Tables: ' + stem + '_admins, ' + stem + '_products, ' + stem + '_orders';
}
function prefixRuleOk(prefix) {
    return prefixError(prefix) === '';
}
function prefixError(prefix) {
    const raw = (prefix || '').trim();
    if (!raw) {
        return 'Table prefix is required. Suggestion: start with a letter, e.g. wento or tiger_.';
    }
    if (/^[^A-Za-z]/.test(raw)) {
        return 'Must start with a letter (A–Z). Suggestion: tiger_ — not _tiger or 2tiger.';
    }
    if (/[^A-Za-z0-9_]/.test(raw)) {
        return 'Only letters, numbers, and underscores. You may use one or more _ (tiger_, tia_2_, Lo_as_as_). No spaces or hyphens.';
    }
    const stem = raw.replace(/_+$/, '');
    const alnum = stem.replace(/_/g, '');
    if (alnum.length < 2) {
        return 'Use at least two letters or numbers. Suggestion: ti_ is fine; n_ is too short.';
    }
    if (stem.length > 32 || alnum.length > 32) {
        return 'Keep the prefix to 32 characters or fewer.';
    }
    return '';
}
function setFieldError(input, message) {
    const field = input.closest('.field');
    if (!field) return;
    let err = field.querySelector('.field-error');
    if (!err) {
        err = document.createElement('p');
        err.className = 'field-error';
        field.appendChild(err);
    }
    field.classList.toggle('is-invalid', !!message);
    field.classList.toggle('is-ok', !message && String(input.value || '').trim() !== '');
    err.textContent = message || '';
    if (message) input.setAttribute('aria-invalid', 'true');
    else input.removeAttribute('aria-invalid');
}
function emailOk(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test((value || '').trim());
}
function fieldMessage(id) {
    const el = document.getElementById(id);
    if (!el) return '';
    const v = (el.value || '').trim();
    if (id === 'store_name') {
        if (!v) return 'Store name is required.';
        if (v.length < 2) return 'Use at least 2 characters.';
        return '';
    }
    if (id === 'store_email') {
        if (!v) return 'Store email is required. Suggestion: shop@example.com';
        if (!emailOk(v)) return 'Enter a valid email, e.g. shop@example.com';
        return '';
    }
    if (id === 'store_line1' || id === 'store_line2') {
        if (v.length > 255) return 'Keep this line under 255 characters.';
        return '';
    }
    if (id === 'store_city' || id === 'store_region') {
        if (v.length > 120) return 'Keep this under 120 characters.';
        return '';
    }
    if (id === 'store_postal') {
        if (v.length > 32) return 'Keep the postal code under 32 characters.';
        return '';
    }
    if (id === 'db_host') {
        if (!v) return 'Database host is required. Suggestion: 127.0.0.1';
        return '';
    }
    if (id === 'shop_port') {
        const n = Number(v);
        if (!v || !Number.isInteger(n) || n < 1 || n > 65535) return 'Port must be a number from 1 to 65535. Suggestion: 8099';
        return '';
    }
    if (id === 'db_port') {
        const n = Number(v);
        if (!v || !Number.isInteger(n) || n < 1 || n > 65535) return 'Port must be a number from 1 to 65535. Suggestion: 3306';
        return '';
    }
    if (id === 'db_database') {
        if (!v) return 'Database name is required.';
        if (!/^[A-Za-z][A-Za-z0-9_]*$/.test(v)) return 'Use a letter first, then letters, numbers, or _. No spaces.';
        return '';
    }
    if (id === 'db_username') {
        if (!v) return 'Database username is required.';
        return '';
    }
    if (id === 'db_prefix') return prefixError(v);
    if (id === 'admin_name') {
        if (!v) return 'Admin name is required.';
        return '';
    }
    if (id === 'admin_email') {
        if (!v) return 'Admin email is required. This is the /admin login.';
        if (!emailOk(v)) return 'Enter a valid email, e.g. admin@example.com';
        return '';
    }
    if (id === 'admin_password') {
        if (v.length < 8 || v.length > 72) return 'Use 8 to 72 characters.';
        if (!/[A-Z]/.test(v) || !/\d/.test(v) || /\s/.test(v)) return 'Add a capital letter and a number, with no spaces.';
        return '';
    }
    if (id === 'admin_password_confirm') {
        if (!v) return 'Confirm the password.';
        if (v !== document.getElementById('admin_password').value) return 'Password and confirm password do not match.';
        return '';
    }
    return '';
}
function checkField(id) {
    const el = document.getElementById(id);
    if (!el) return true;
    if (id === 'store_region') {
        const wrap = document.getElementById('store-region-field');
        if (wrap && wrap.hidden) return true;
    }
    if (id === 'shop_port' && document.getElementById('serve_apache') && document.getElementById('serve_apache').checked) {
        return true;
    }
    const msg = fieldMessage(id);
    setFieldError(el, msg);
    return !msg;
}
function checkFields(ids) {
    let first = null;
    ids.forEach((id) => {
        if (!checkField(id) && !first) first = document.getElementById(id);
    });
    if (first) {
        first.focus();
        flash('Fix the highlighted fields, then continue.', false);
        return false;
    }
    flash('');
    return true;
}
const storeFields = ['store_name', 'store_email', 'store_line1', 'store_line2', 'store_city', 'store_region', 'store_postal'].concat(
    document.getElementById('shop_port') ? ['shop_port'] : []
);
const dbFields = ['db_host', 'db_port', 'db_database', 'db_username', 'db_prefix'];
const adminFields = ['admin_name', 'admin_email', 'admin_password', 'admin_password_confirm'];
function bindLiveCheck(ids) {
    ids.forEach((id) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('blur', () => checkField(id));
        el.addEventListener('input', () => {
            const field = el.closest('.field');
            if (field && field.classList.contains('is-invalid')) checkField(id);
            if (id === 'db_prefix') prefixPreview();
            if (id === 'admin_password' || id === 'admin_password_confirm') {
                if (document.getElementById('admin_password_confirm').closest('.field')?.classList.contains('is-invalid')) {
                    checkField('admin_password_confirm');
                }
            }
        });
    });
}
function setTask(el, state) {
    el.classList.remove('done', 'run', 'skip', 'pending');
    const badge = el.querySelector('.badge');
    const dot = el.querySelector('.t-dot');
    const visible = [...el.parentElement.children].filter((node) => node.matches && node.matches('.task') && !node.hidden);
    const idx = visible.indexOf(el) + 1;
    if (state === 'done') {
        el.classList.add('done');
        badge.className = 'badge ok';
        badge.textContent = 'Done';
        dot.textContent = '✓';
    } else if (state === 'run') {
        el.classList.add('run');
        badge.className = 'badge run';
        badge.textContent = 'Active';
        dot.innerHTML = '<span class="spin-dot" aria-hidden="true"></span>';
    } else if (state === 'skip') {
        el.classList.add('skip');
        badge.className = 'badge skip';
        badge.textContent = 'Skipped';
        dot.textContent = '–';
    } else {
        el.classList.add('pending');
        badge.className = 'badge';
        badge.textContent = 'Waiting';
        dot.textContent = String(idx);
    }
}

const INSTALL_UI_PHASES_ALL = ['database', 'configuration', 'design', 'demo', 'admin', 'finish'];
let lastOverallPct = -1;
let lastDetailPct = -1;
let lastDetailPhase = '';
let lastDetailKey = '';
let openedInstalled = false;
/** @type {Record<string, {done:number,total:number,detail:string}>} */
const stageStats = {};

function wantsDemoData() {
    return document.getElementById('install_demo_yes')?.checked !== false;
}

function installPhases() {
    return wantsDemoData()
        ? INSTALL_UI_PHASES_ALL
        : ['database', 'configuration', 'design', 'admin', 'finish'];
}

function syncInstallTaskList() {
    const demo = wantsDemoData();
    document.querySelectorAll('#inst-tasks .task[data-phase="demo"]').forEach((t) => {
        t.hidden = !demo;
    });
    document.querySelectorAll('#inst-tasks .task[data-phase="design"]').forEach((t) => {
        t.hidden = false;
    });
    [...document.querySelectorAll('#inst-tasks .task')].filter((t) => !t.hidden).forEach((t, i) => {
        const dot = t.querySelector('.t-dot');
        if (dot && (t.classList.contains('pending') || !t.classList.contains('done') && !t.classList.contains('run'))) {
            dot.textContent = String(i + 1);
        }
        t.dataset.step = String(i + 1);
    });
}

function installUiPhase(phase) {
    if (phase === 'tables') return 'database';
    if (phase === 'seed') return 'configuration';
    const phases = installPhases();
    if (!wantsDemoData() && phase === 'demo') {
        return 'design';
    }
    if (phases.includes(phase)) return phase;
    return 'database';
}

function setBarWidth(el, pct) {
    const next = Math.max(0, Math.min(100, Number(pct) || 0));
    const prev = Number(String(el.style.width || '0').replace('%', '')) || 0;
    if (next < prev) return prev; // never shrink (stops flicker)
    if (next === prev) return prev;
    el.style.width = next + '%';
    return next;
}

function stageSubtitle(phase, state, detail, done, total) {
    if (phase === 'database') {
        if (state === 'done') {
            return total > 1 ? (total + ' / ' + total + ' tables created') : 'Store tables created';
        }
        if (total > 1) return done + ' / ' + total + ' tables';
        return detail || 'Create your store tables…';
    }
    if (phase === 'configuration') {
        return 'Languages, currencies, regions & permissions';
    }
    if (phase === 'design') {
        if (state === 'done') return 'Theme assets installed';
        if (state === 'skip') return 'Skipped';
        return detail || 'Installing theme assets…';
    }
    if (phase === 'demo') {
        if (state === 'skip') return 'Skipped — empty catalog';
        if (state === 'done') {
            return 'Sample catalog installed';
        }
        const counted = String(detail || '').match(/^(Categories|Brands|Products|Reviews|Discounts|Customers|Orders|Questions)\s+(\d+)\s*\/\s*(\d+)/i);
        if (counted) {
            const label = counted[1];
            const n = Number(counted[2]);
            const of = Number(counted[3]);
            const pct = Math.round((n / Math.max(1, of)) * 100);
            return '<div class="t-counters"><div class="t-counter"><span>' + label + '</span><strong>' + n + ' / ' + of + '</strong><div class="mini-bar"><i style="width:' + pct + '%"></i></div></div></div>';
        }
        return detail || 'Adding sample products…';
    }
    if (phase === 'admin') {
        if (state === 'done') return 'Administrator account ready';
        return detail || 'Saving your admin account…';
    }
    if (phase === 'finish') {
        return state === 'done' ? 'Your store is ready' : 'Finishing…';
    }
    return detail || '';
}

function paintInstall(s) {
    const pct = Math.max(0, Math.min(100, Number(s.percent) || 0));
    if (pct >= lastOverallPct) {
        lastOverallPct = setBarWidth(document.getElementById('inst-bar'), pct);
        document.getElementById('inst-pct').textContent = lastOverallPct + '%';
    }

    const rawPhase = String(s.phase || 'database');
    const uiPhase = installUiPhase(rawPhase);
    const phases = installPhases();
    const activeIdx = Math.max(0, phases.indexOf(uiPhase));
    const detail = s.detail || 'Working…';
    const done = Number(s.done || 0);
    const total = Number(s.total || 0);
    const detailKey = uiPhase + ':' + String(detail).replace(/\d+/g, '#');
    if (uiPhase !== lastDetailPhase || detailKey !== lastDetailKey) {
        lastDetailPhase = uiPhase;
        lastDetailKey = detailKey;
        lastDetailPct = -1;
        const detailBar = document.getElementById('inst-detail-bar');
        if (detailBar) detailBar.style.width = '0%';
    }
    stageStats[uiPhase] = { done, total, detail };
    const tasks = [...document.querySelectorAll('#inst-tasks .task')].filter((t) => !t.hidden);

    tasks.forEach((t, i) => {
        const phase = t.dataset.phase;
        const sub = t.querySelector('.t-sub');
        const st = stageStats[phase] || { done: 0, total: 0, detail: '' };
        let state = 'pending';
        const phaseIdx = phases.indexOf(phase);
        if (s.ok === true || i < activeIdx) {
            state = 'done';
        } else if (i === activeIdx && s.ok !== false) {
            state = 'run';
        }
        setTask(t, state);
        if (state === 'run') {
            sub.innerHTML = stageSubtitle(phase, state, detail, done, total);
        } else if (state === 'done') {
            sub.innerHTML = stageSubtitle(phase, state, st.detail, st.done, st.total) || sub.dataset.default || '';
        } else if (state === 'skip') {
            sub.textContent = stageSubtitle(phase, state, detail, done, total);
        } else if (sub.dataset.default) {
            sub.innerHTML = sub.dataset.default;
        }
    });

    const detailPct = Math.max(0, Math.min(100, Number(s.detail_pct) || 0));
    document.getElementById('inst-detail-label').textContent = detail;
    if (total > 0) {
        document.getElementById('inst-count-label').textContent = done + ' of ' + total;
    } else {
        document.getElementById('inst-count-label').textContent = 'Progress';
    }
    if (detailPct >= lastDetailPct) {
        lastDetailPct = setBarWidth(document.getElementById('inst-detail-bar'), detailPct);
        document.getElementById('inst-detail-pct').textContent = lastDetailPct + '%';
    }

    const log = document.getElementById('inst-log');
    if (typeof s.log === 'string' && s.log !== '') {
        log.textContent = s.log;
        log.scrollTop = 99999;
    }
}

function syncRegionField(preferred) {
    const country = document.getElementById('store_country');
    const wrap = document.getElementById('store-region-field');
    const select = document.getElementById('store_region');
    if (!country || !wrap || !select) return;
    const regions = COUNTRY_REGIONS[country.value] || [];
    const previous = preferred !== undefined ? preferred : select.value;
    if (!regions.length) {
        wrap.hidden = true;
        select.innerHTML = '<option value=""></option>';
        select.value = '';
        return;
    }
    wrap.hidden = false;
    select.innerHTML = '<option value="">Select state</option>' + regions.map((row) =>
        '<option value="' + escapeHtml(row.name) + '">' + escapeHtml(row.name) + '</option>'
    ).join('');
    const hit = regions.find((row) => row.name === previous || row.code === previous);
    select.value = hit ? hit.name : '';
}
function syncServeVia() {
    const box = document.getElementById('helper-port-fields');
    const apache = document.getElementById('serve_apache');
    if (!box) return;
    box.hidden = !!(apache && apache.checked);
}
function chosenShopOrigin() {
    if (document.getElementById('serve_apache') && document.getElementById('serve_apache').checked && APACHE_SHOP_URL) {
        return String(APACHE_SHOP_URL).replace(/\/$/, '');
    }
    const loc = window.location;
    const portEl = document.getElementById('shop_port');
    const port = Number((portEl && portEl.value) || '');
    const current = Number(loc.port || (loc.protocol === 'https:' ? '443' : '80'));
    if (Number.isInteger(port) && port >= 1 && port <= 65535 && port !== current) {
        return loc.protocol + '//' + loc.hostname + ':' + port;
    }
    return loc.origin.replace(/\/$/, '');
}

function installedFallbackUrl() {
    return chosenShopOrigin() + '/installed';
}

function openInstalledPage(next) {
    if (openedInstalled) return;
    openedInstalled = true;
    if (installTimer) {
        clearInterval(installTimer);
        installTimer = null;
    }
    document.querySelectorAll('#inst-tasks .task').forEach((t) => {
        if (t.hidden) return;
        setTask(t, 'done');
    });
    document.getElementById('inst-bar').style.width = '100%';
    document.getElementById('inst-pct').textContent = '100%';
    lastOverallPct = setBarWidth(document.getElementById('inst-bar'), 100);
    flash('Installation has completed. Opening ready page…', true);
    const url = next || installedFallbackUrl();
    setTimeout(() => { window.location.href = url; }, 700);
}

async function pollInstallStatus() {
    try {
        const res = await fetch('api.php?action=install-status', {
            method: 'GET',
            headers: { 'Accept': 'application/json', 'X-SETUP-TOKEN': TOKEN },
        });
        if (res.redirected && /\/installed(\/|$|\?)/i.test(res.url)) {
            openInstalledPage(res.url);
            return;
        }
        const raw = await res.text();
        let json = null;
        try { json = JSON.parse(raw); } catch (e) { json = null; }
        const ctype = (res.headers.get('content-type') || '').toLowerCase();
        if (res.status === 404 || res.status === 409 || !json || !ctype.includes('json')) {
            if (lastOverallPct >= 90 || res.status === 409) {
                openInstalledPage((json && json.redirect) || installedFallbackUrl());
            } else {
                noteInstallPollMiss(explainProgressFailure(raw, json), true);
            }
            return;
        }
        if (!json.ok || !json.status) {
            if (lastOverallPct >= 90) {
                openInstalledPage(json.redirect || installedFallbackUrl());
            } else {
                noteInstallPollMiss(json.message || 'Could not read install progress. Reload this page.');
            }
            return;
        }
        installPollMisses = 0;
        const s = json.status;
        paintInstall(s);
        if (s.ok === true) {
            openInstalledPage(s.redirect || ((s.shop_url || '/').replace(/\/$/, '') + '/installed'));
            return;
        }
        if (s.ok === false) {
            clearInterval(installTimer);
            installTimer = null;
            flash(s.error || 'Install failed. Fix the problem, then try again.', false);
            document.getElementById('inst-back').disabled = false;
            showInstallRetry();
            if (/database name has already been used/i.test(String(s.error || ''))) {
                show(3);
                flash(s.error || 'Choose another empty database, then continue.', false);
            }
        }
    } catch (e) {
        if (lastOverallPct >= 90) {
            openInstalledPage(installedFallbackUrl());
        } else {
            noteInstallPollMiss('Could not reach the installer status page. Reload this page.');
        }
    }
}

function explainProgressFailure(raw, json) {
    if (json && json.message) return json.message;
    const text = String(raw || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    if (/permission denied|failed to open stream|not writable/i.test(text)) {
        return 'The web server cannot write in the shop folder. Fix the folder permissions, then reload this page. Your details are kept.';
    }
    if (/SQLSTATE|Identifier name/i.test(text)) {
        return text.slice(0, 500);
    }
    return 'The installer could not read setup progress. Reload this page. Your details are kept, and you can try the installation again.';
}
function noteInstallPollMiss(message, immediate) {
    installPollMisses += 1;
    if (!immediate && installPollMisses < 3) return;
    clearInterval(installTimer);
    installTimer = null;
    flash(message, false);
    document.getElementById('inst-back').disabled = false;
    showInstallRetry();
}

document.getElementById('inst-details-toggle').onclick = () => {
    const box = document.getElementById('inst-details');
    const btn = document.getElementById('inst-details-toggle');
    const open = box.hasAttribute('hidden');
    if (open) {
        box.removeAttribute('hidden');
        btn.setAttribute('aria-expanded', 'true');
        btn.textContent = '▾ Hide installation details';
    } else {
        box.setAttribute('hidden', 'hidden');
        btn.setAttribute('aria-expanded', 'false');
        btn.textContent = '▸ View installation details';
    }
};

function paintPrepare(s) {
    const pct = s.percent || 0;
    document.getElementById('bar').style.width = pct + '%';
    document.getElementById('prep-pct').textContent = pct + '%';
    document.getElementById('log').textContent = s.log || '';
    document.getElementById('log').scrollTop = 99999;
    const phase = s.phase || '';
    const tasks = [...document.querySelectorAll('#prep-tasks .task')];
    let active = 0;
    if (phase === 'composer' || pct < 55) active = 0;
    else if (phase === 'npm' || pct < 75) active = 1;
    else if (pct < 95) active = 2;
    else active = 3;
    tasks.forEach((t, i) => {
        if (s.ok === true || i < active) setTask(t, 'done');
        else if (i === active && s.ok !== false) setTask(t, 'run');
        else setTask(t, 'pending');
    });
    if (s.ok === true) {
        markPreparedUi();
        flash('Packages installed. Continue.', true);
        clearInterval(pollTimer);
    }
    if (s.ok === false) {
        flash(s.error || 'Prepare failed.', false);
        clearInterval(pollTimer);
        document.getElementById('start-prepare').style.display = '';
        document.getElementById('start-prepare').disabled = false;
    }
}
async function pollStatus() {
    const json = await api('status');
    if (json.status) paintPrepare(json.status);
    if (json.prepared) markPreparedUi();
}

function passwordScore(pw) {
    let n = 0;
    if (pw.length >= 8) n++;
    if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) n++;
    if (/\d/.test(pw)) n++;
    if (/[^A-Za-z0-9]/.test(pw)) n++;
    return n;
}
function updateStrength() {
    const pw = document.getElementById('admin_password').value;
    const level = passwordScore(pw);
    document.getElementById('pw-strength').dataset.level = String(level);
    const label = document.getElementById('pw-label');
    const names = ['Use at least 8 characters', 'Weak password', 'Fair password', 'Strong password', 'Strong password'];
    label.textContent = names[level];
    label.classList.toggle('strong', level >= 3);
}

document.getElementById('reload-checks').onclick = loadChecks;
document.getElementById('go-prepare').onclick = () => { if (ready) show(1); };
document.getElementById('go-store').onclick = () => { if (prepared) { rememberDraft(); show(2); } };
document.getElementById('go-db').onclick = () => {
    if (!checkFields(storeFields)) return;
    rememberDraft();
    show(3);
};
document.getElementById('go-admin').onclick = async () => {
    if (!checkFields(dbFields)) return;
    const json = await runDbCheck('continue');
    if (!json || !json.ok) return;
    show(4);
};
document.querySelectorAll('[data-back]').forEach((b) => b.onclick = () => show(Number(b.dataset.back)));
document.getElementById('db_prefix').addEventListener('input', prefixPreview);
document.getElementById('admin_password').addEventListener('input', updateStrength);
bindLiveCheck(storeFields.concat(dbFields, adminFields));
document.querySelectorAll('.eye').forEach((btn) => {
    btn.onclick = () => {
        const input = document.getElementById(btn.dataset.toggle);
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        btn.querySelector('.eye-show')?.toggleAttribute('hidden', show);
        btn.querySelector('.eye-hide')?.toggleAttribute('hidden', !show);
    };
});

async function runDbCheck(mode) {
    const testBtn = document.getElementById('test-db');
    const contBtn = document.getElementById('go-admin');
    const testHtml = testBtn.innerHTML;
    const contHtml = contBtn.innerHTML;
    testBtn.disabled = true;
    contBtn.disabled = true;
    testBtn.classList.add('btn-busy');
    contBtn.classList.add('btn-busy');
    testBtn.innerHTML = '<span class="spin-inline" aria-hidden="true"></span> Testing…';
    if (mode === 'continue') {
        contBtn.innerHTML = '<span class="spin-inline" aria-hidden="true"></span> Checking…';
    }
    flash(mode === 'continue'
        ? 'Checking database connection and ensuring the database is empty…'
        : 'Testing database connection… Please wait.', true);
    try {
        const json = await api('test-db', payload());
        flash(json.message, json.ok);
        if (json.ok) rememberDraft();
        return json;
    } catch (e) {
        flash('Could not reach the installer API. Try again.', false);
        return { ok: false };
    } finally {
        testBtn.disabled = false;
        contBtn.disabled = false;
        testBtn.classList.remove('btn-busy');
        contBtn.classList.remove('btn-busy');
        testBtn.innerHTML = testHtml;
        contBtn.innerHTML = contHtml;
    }
}

document.getElementById('start-prepare').onclick = async () => {
    if (PREVIEW) {
        markPreparedUi();
        flash('Preview mode — prepare is simulated.', true);
        return;
    }
    flash('Starting package install…', true);
    document.getElementById('start-prepare').disabled = true;
    const json = await api('prepare', {});
    if (!json.ok) { flash(json.message, false); document.getElementById('start-prepare').disabled = false; return; }
    if (json.prepared) { markPreparedUi(); flash('Already prepared. Continue.', true); return; }
    clearInterval(pollTimer);
    pollTimer = setInterval(pollStatus, 1500);
    pollStatus();
};
document.getElementById('test-db').onclick = async () => {
    if (!checkFields(dbFields)) return;
    await runDbCheck('test');
};

async function simulateInstallPreview() {
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const log = [];
    const push = (line) => { log.push(line); return log.join('\n'); };
    const tables = 51;
    for (let i = 1; i <= tables; i += 3) {
        const n = Math.min(i, tables);
        paintInstall({
            phase: 'database', percent: Math.round((n / tables) * 40), detail: 'Tables ' + n + ' / ' + tables,
            detail_pct: Math.round((n / tables) * 100), done: n, total: tables,
            log: push(n === 1 ? 'Creating store tables…' : ('CREATE TABLE table_' + n)),
        });
        await sleep(40);
    }
    paintInstall({ phase: 'database', percent: 40, detail: 'Tables ' + tables + ' / ' + tables, detail_pct: 100, done: tables, total: tables, log: push('Database ready') });
    for (let i = 1; i <= 8; i++) {
        paintInstall({
            phase: 'configuration', percent: (wantsDemoData() ? 40 : 50) + Math.round((i / 8) * (wantsDemoData() ? 15 : 18)),
            detail: 'Languages, currencies, regions & permissions', detail_pct: Math.round((i / 8) * 100),
            done: i, total: 8, log: push('Config step ' + i),
        });
        await sleep(80);
    }
    paintInstall({ phase: 'design', percent: wantsDemoData() ? 58 : 72, detail: 'Installing theme assets…', detail_pct: 40, done: 0, total: 1, log: push('Installing store design…') });
    await sleep(300);
    paintInstall({ phase: 'design', percent: wantsDemoData() ? 62 : 85, detail: 'Theme assets installed', detail_pct: 100, done: 1, total: 1, log: push('Theme assets installed') });
    if (wantsDemoData()) {
    const products = 33;
    for (let i = 1; i <= products; i++) {
        paintInstall({
            phase: 'demo', percent: 62 + Math.round((i / products) * 28),
            detail: 'Products ' + i + ' / ' + products, detail_pct: Math.round((i / products) * 100),
            done: i, total: products, log: push('Product ' + i),
        });
        await sleep(35);
    }
    }
    for (let i = 1; i <= 3; i++) {
        paintInstall({
            phase: 'admin', percent: 90 + Math.round((i / 3) * 8),
            detail: i === 1 ? 'Saving your admin account…' : (i === 2 ? 'Saving store website address…' : 'Finishing admin settings…'),
            detail_pct: Math.round((i / 3) * 100), done: i, total: 3, log: push('Admin step ' + i),
        });
        await sleep(200);
    }
    paintInstall({
        phase: 'finish', percent: 100, detail: 'Installation completed', detail_pct: 100, done: 1, total: 1,
        log: push('Installation completed.'), ok: true,
    });
    document.querySelectorAll('#inst-tasks .task').forEach((t) => setTask(t, 'done'));
    flash('Preview simulation finished — this was not a real install.', true);
    document.getElementById('inst-back').disabled = false;
    document.getElementById('install-wait').textContent = 'Preview done';
}

document.getElementById('go-install').onclick = () => startInstall(false);

async function startInstall(useSavedSecrets) {
    if (!PREVIEW && !useSavedSecrets && !checkFields(adminFields)) return;
    show(5);
    resetInstallWait();
    lastOverallPct = -1;
    lastDetailPct = -1;
    lastDetailPhase = '';
    lastDetailKey = '';
    Object.keys(stageStats).forEach((k) => { delete stageStats[k]; });
    syncInstallTaskList();
    document.getElementById('inst-back').disabled = true;
    document.getElementById('inst-log').textContent = 'Starting…';
    document.getElementById('inst-detail-label').textContent = 'Starting…';
    paintInstall({ phase: 'database', percent: 2, detail: 'Starting…', detail_pct: 0, log: 'Starting…\n' });
    // Keep technical details collapsed — customer UI is the stage list.
    const box = document.getElementById('inst-details');
    const btn = document.getElementById('inst-details-toggle');
    box.setAttribute('hidden', 'hidden');
    btn.setAttribute('aria-expanded', 'false');
    btn.textContent = '▸ View installation details';

    if (PREVIEW) {
        flash('Preview mode — simulating install progress.', true);
        simulateInstallPreview();
        return;
    }

    const body = payload();
    if (useSavedSecrets) body.use_saved_secrets = '1';
    const json = await api('install', body);
    if (json.ok && json.status && json.status.ok === true) {
        openInstalledPage(json.status.redirect || json.redirect || installedFallbackUrl());
        return;
    }
    if (!json.ok) {
        const msg = json.message || 'Install failed.';
        if (/not found/i.test(msg)) {
            const status = await api('install-status');
            if (status.ok && status.status && status.status.ok === true) {
                openInstalledPage(status.status.redirect || installedFallbackUrl());
                return;
            }
        }
        flash(msg, false);
        document.getElementById('inst-back').disabled = false;
        showInstallRetry();
        if (/database name has already been used/i.test(msg) || /table prefix/i.test(msg)) {
            show(3);
            flash(msg, false);
        }
        if (/store name|store email|shop website/i.test(msg)) {
            show(2);
            flash(msg, false);
        }
        return;
    }
    clearInterval(installTimer);
    installTimer = setInterval(pollInstallStatus, 800);
    pollInstallStatus();
};

prefixPreview();
updateStrength();
syncRegionField();
syncServeVia();
document.getElementById('store_country')?.addEventListener('change', () => syncRegionField(''));
document.getElementById('serve_apache')?.addEventListener('change', syncServeVia);
document.getElementById('serve_helper')?.addEventListener('change', syncServeVia);
document.getElementById('install_demo_yes')?.addEventListener('change', syncInstallTaskList);
document.getElementById('install_demo_no')?.addEventListener('change', syncInstallTaskList);
syncInstallTaskList();

const PREVIEW = <?= $isPreview ?>;
const PREVIEW_STEP = <?= $previewStepJs ?>;
const START_STEP = <?= $startStepJs ?>;

if (PREVIEW) {
    // Sample look-and-feel only — no real prepare/install.
    ready = true;
    prepared = true;
    document.getElementById('go-prepare').disabled = false;
    document.getElementById('admin_email').value = 'admin@wentocart.com';
    document.getElementById('admin_password').value = 'Password1!';
    document.getElementById('admin_password_confirm').value = 'Password1!';
    updateStrength();
    renderChecks({
        ready: true,
        prepared: true,
        rows: [
            { label: 'PHP available', value: 'Yes (preview)', ok: true, status: 'Compatible' },
            { label: 'PHP version', value: '8.3.0', ok: true, status: 'Correct' },
            { label: 'php.ini extensions', value: 'Yes', ok: true, status: 'Compatible', extensions: [
                { name: 'pdo_mysql', ok: true }, { name: 'mbstring', ok: true }, { name: 'openssl', ok: true },
            ]},
            { label: 'Node.js available', value: 'Yes (v20.0.0)', ok: true, status: 'Compatible' },
        ],
    });
    markPreparedUi();
    document.getElementById('log').textContent = '[preview] Packages already prepared.\n[preview] Ready to continue.';
    show(PREVIEW_STEP);
    if (PREVIEW_STEP === 5) {
        document.getElementById('go-install').click();
    }
} else {
    document.getElementById('side-art').innerHTML = sideArts[0];
    loadChecks();
}
</script>
</body>
</html>
