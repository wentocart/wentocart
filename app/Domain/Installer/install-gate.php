<?php

/**
 * Installer screen for a closed unpack helper or a missing setup folder.
 * Sensitive paths stay forbidden. This page replaces PHP's plain "File not found."
 */
function wento_render_install_gate(string $continueUrl, string $variant = 'extract_removed'): void
{
    $extractRemoved = $variant === 'extract_removed';
    $steps = $extractRemoved
        ? [
            ['System Check', 'done'],
            ['Install Packages', 'done'],
            ['Store Information', 'on'],
            ['Database Setup', ''],
            ['Admin Account', ''],
            ['Installation', ''],
        ]
        : [
            ['System Check', 'on'],
            ['Install Packages', ''],
            ['Store Information', ''],
            ['Database Setup', ''],
            ['Admin Account', ''],
            ['Installation', ''],
        ];

    if ($extractRemoved) {
        $heading = 'Continue the installation';
        $lead = 'The unpack helper has finished and was removed. That is expected: <code>_extract.php</code> is not a public page, and application folders stay blocked.';
        $body = '<p>The shop files are already in this folder. Continue with the installation steps. You do not need to unpack the zip again.</p>';
        $actions = '<a class="btn-primary" href="'.htmlspecialchars($continueUrl, ENT_QUOTES, 'UTF-8').'">Continue installation →</a>';
        $callout = 'Step 2 is complete. Store information is next. Protected paths stay closed.';
        $status = 200;
    } else {
        $heading = 'Installer files are missing';
        $lead = 'This shop is not installed yet, and the setup wizard is not in this folder.';
        $body = '<p>Unpack a fresh <code>wentocart.zip</code>, then open the installer again. The steps below are the path the wizard follows.</p>';
        $actions = '<button type="button" class="btn-primary" onclick="location.reload()">Reload this page</button>';
        $callout = 'Start again from a new install package. Do not open application folders directly.';
        $status = 503;
    }

    if (! headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }

    $stepHtml = '';
    $n = 1;
    foreach ($steps as [$label, $state]) {
        $class = $state === '' ? 'step' : 'step '.$state;
        $mark = $state === 'done' ? '✓' : (string) $n;
        $stepHtml .= '<div class="'.$class.'"><div class="dot">'.$mark.'</div><div class="label">'
            .htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</div></div>';
        $n++;
    }

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        .'<title>'.htmlspecialchars($heading, ENT_QUOTES, 'UTF-8').' — WentoCart</title>'
        .'<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">'
        .'<style>'
        .'body{margin:0;min-height:100vh;font-family:"Plus Jakarta Sans",system-ui,sans-serif;color:#0f172a;background:radial-gradient(1200px 500px at 10% -10%,#ede9fe 0%,transparent 55%),radial-gradient(900px 400px at 100% 0%,#e0e7ff 0%,transparent 50%),#f3f4f8}'
        .'.shell{max-width:1120px;margin:0 auto;padding:28px 20px 48px}'
        .'.brand{display:flex;align-items:center;gap:12px;margin-bottom:22px;font-weight:800}'
        .'.mark{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;color:#fff;background:linear-gradient(145deg,#7c3aed,#5b21b6);box-shadow:0 10px 24px rgba(91,33,182,.35)}'
        .'.stepper{display:flex;gap:6px;justify-content:space-between;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:18px 16px;margin-bottom:20px;box-shadow:0 18px 50px rgba(15,23,42,.08);overflow-x:auto}'
        .'.step{flex:1;min-width:110px;text-align:center;color:#64748b;font-size:12px;font-weight:600;position:relative}'
        .'.step:not(:last-child)::after{content:"";position:absolute;top:15px;left:calc(50% + 18px);right:calc(-50% + 18px);height:2px;background:#e2e8f0}'
        .'.step .dot{width:30px;height:30px;border-radius:999px;margin:0 auto 8px;display:grid;place-items:center;background:#f1f5f9;color:#94a3b8;font-weight:800;position:relative;z-index:1}'
        .'.step.on{color:#5b21b6}.step.on .dot{background:#5b21b6;color:#fff;box-shadow:0 0 0 4px #ede9fe}'
        .'.step.on .label{text-decoration:underline;text-underline-offset:6px}'
        .'.step.done{color:#166534}.step.done .dot{background:#16a34a;color:#fff}'
        .'.step.done:not(:last-child)::after{background:#86efac}'
        .'.card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;box-shadow:0 18px 50px rgba(15,23,42,.08);display:grid;grid-template-columns:300px 1fr;min-height:420px;overflow:hidden}'
        .'.side{background:linear-gradient(180deg,#f5f3ff 0%,#faf5ff 100%);border-right:1px solid #ede9fe;padding:28px 24px;display:flex;flex-direction:column;gap:16px}'
        .'.side h2{margin:0;color:#5b21b6;font-size:1.25rem}.side p,.lead{margin:0;color:#64748b;line-height:1.55}'
        .'.callout{margin-top:auto;background:#efe9ff;border:1px solid #ddd6fe;border-radius:14px;padding:14px;color:#5b21b6;font-size:13px;line-height:1.45}'
        .'.callout strong{display:block;margin-bottom:4px}'
        .'.main{padding:28px}.main h1{margin:0 0 8px;font-size:1.55rem;letter-spacing:-.03em}'
        .'.main p{line-height:1.55;color:#334155}'
        .'code{background:#f1f5f9;padding:2px 6px;border-radius:6px;font-size:.92em}'
        .'.footer{margin-top:22px;padding-top:18px;border-top:1px solid #f1f5f9}'
        .'.btn-primary,button{border:0;border-radius:12px;padding:12px 18px;font:inherit;font-weight:700;cursor:pointer;background:linear-gradient(135deg,#7c3aed,#5b21b6);color:#fff;text-decoration:none;display:inline-flex}'
        .'@media(max-width:900px){.card{grid-template-columns:1fr}}'
        .'</style></head><body><div class="shell">'
        .'<div class="brand"><div class="mark" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M6 7h12l-1 12a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 7Z" stroke="#fff" stroke-width="1.8"/><path d="M9 7a3 3 0 0 1 6 0" stroke="#fff" stroke-width="1.8"/></svg></div>WentoCart</div>'
        .'<nav class="stepper" aria-label="Installation steps">'.$stepHtml.'</nav>'
        .'<div class="card"><aside class="side"><h2>Installation steps</h2><p>'.htmlspecialchars($callout, ENT_QUOTES, 'UTF-8').'</p>'
        .'<div class="callout"><strong>Secure installation</strong> The unpack helper and application source stay closed. Use the wizard for the steps below.</div></aside>'
        .'<section class="main"><h1>'.htmlspecialchars($heading, ENT_QUOTES, 'UTF-8').'</h1><p class="lead">'.$lead.'</p>'.$body
        .'<div class="footer">'.$actions.'</div></section></div></div></body></html>';
}
