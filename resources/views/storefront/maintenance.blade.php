<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $shop }} — {{ __('Maintenance') }}</title>
    <meta name="robots" content="noindex,nofollow">
    <style nonce="{{ $cspNonce ?? '' }}">
        :root {
            --ink: #14212b;
            --muted: #5b6b76;
            --card: rgba(255, 255, 255, 0.88);
            --line: rgba(20, 33, 43, 0.08);
            --accent: #0f766e;
            --accent-soft: #ccfbf1;
            --header-bg: #14323a;
            --banner-bg: #0f766e;
        }
        * { box-sizing: border-box; }
        html, body { min-height: 100%; }
        body {
            margin: 0;
            color: var(--ink);
            font-family: "Source Sans 3", "Segoe UI", "Helvetica Neue", sans-serif;
            background:
                radial-gradient(1100px 640px at 12% -8%, #d7ebe8 0%, transparent 58%),
                radial-gradient(900px 520px at 100% 0%, #e7eef6 0%, transparent 52%),
                linear-gradient(165deg, #f3f6f8 0%, #eef2f4 48%, #e8eef0 100%);
        }
        .page {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .topbar {
            flex: 0 0 auto;
            background: var(--header-bg);
            color: #ecf4f3;
            padding: 0.85rem 1.25rem;
            text-align: center;
            font-size: 0.95rem;
            line-height: 1.45;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .topbar a { color: #99f6e4; font-weight: 600; }
        .topbar p { margin: 0; }
        .topbar strong { color: #fff; }
        .shell {
            flex: 1 1 auto;
            display: grid;
            place-items: center;
            padding: 2.5rem 1.25rem;
            padding-bottom: calc(2.5rem + var(--banner-space, 0px));
        }
        .card {
            width: min(40rem, 100%);
            background: var(--card);
            backdrop-filter: blur(14px);
            border: 1px solid var(--line);
            border-radius: 1.5rem;
            box-shadow: 0 28px 80px -40px rgba(20, 33, 43, 0.55);
            padding: clamp(1.75rem, 4vw, 2.75rem);
        }
        .mark {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 3rem;
            height: 3rem;
            border-radius: 999px;
            background: var(--accent-soft);
            color: var(--accent);
            margin-bottom: 1.25rem;
        }
        .mark svg { width: 1.35rem; height: 1.35rem; }
        .brand {
            margin: 0 0 0.35rem;
            font-family: Fraunces, Georgia, "Times New Roman", serif;
            font-size: clamp(1.85rem, 4vw, 2.4rem);
            font-weight: 600;
            letter-spacing: -0.02em;
            line-height: 1.15;
        }
        .eyebrow {
            margin: 0 0 1.5rem;
            color: var(--muted);
            font-size: 0.95rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            font-weight: 600;
        }
        .rich {
            color: var(--muted);
            font-size: 1.05rem;
            line-height: 1.65;
        }
        .rich > *:first-child { margin-top: 0; }
        .rich > *:last-child { margin-bottom: 0; }
        .rich h1, .rich h2, .rich h3, .rich h4 {
            color: var(--ink);
            font-family: Fraunces, Georgia, "Times New Roman", serif;
            font-weight: 600;
            letter-spacing: -0.02em;
            line-height: 1.25;
            margin: 1.25rem 0 0.65rem;
        }
        .rich h1 { font-size: 1.75rem; }
        .rich h2 { font-size: 1.45rem; }
        .rich h3 { font-size: 1.2rem; }
        .rich p { margin: 0 0 1rem; }
        .rich ul, .rich ol {
            margin: 0 0 1.15rem;
            padding-left: 1.2rem;
        }
        .rich li { margin: 0.35rem 0; }
        .rich a { color: var(--accent); font-weight: 600; }
        .rich img { max-width: 100%; height: auto; border-radius: 0.75rem; }
        .rich blockquote {
            margin: 1rem 0;
            padding: 0.75rem 1rem;
            border-left: 3px solid var(--accent);
            background: rgba(15, 118, 110, 0.06);
            border-radius: 0 0.75rem 0.75rem 0;
        }
        .foot {
            margin-top: 1.75rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--line);
            color: var(--muted);
            font-size: 0.9rem;
        }
        .foot.rich { font-size: 0.9rem; }
        .foot p { margin: 0; }
        .ground {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 20;
            background: var(--banner-bg);
            color: #ecfdf8;
            padding: 0.9rem 1.25rem;
            text-align: center;
            font-size: 0.95rem;
            line-height: 1.45;
            box-shadow: 0 -12px 40px -24px rgba(15, 118, 110, 0.7);
        }
        .ground a { color: #ccfbf1; font-weight: 600; }
        .ground p { margin: 0; }
        .ground strong { color: #fff; }
        body.has-banner { --banner-space: 4.5rem; }
    </style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Source+Sans+3:wght@400;600&display=swap" rel="stylesheet">
</head>
<body @class(['has-banner' => filled($banner)])>
    <div class="page">
        @if (filled($header))
            <header class="topbar" role="banner">
                <div class="rich">{!! $header !!}</div>
            </header>
        @endif

        <div class="shell">
            <main class="card" role="main">
                <div class="mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 3v4"/>
                        <path d="M12 17v4"/>
                        <path d="M3 12h4"/>
                        <path d="M17 12h4"/>
                        <circle cx="12" cy="12" r="3.5"/>
                        <path d="M5.6 5.6 8 8"/>
                        <path d="M16 16l2.4 2.4"/>
                        <path d="M18.4 5.6 16 8"/>
                        <path d="M8 16l-2.4 2.4"/>
                    </svg>
                </div>
                <p class="eyebrow">{{ __('Temporarily closed') }}</p>
                <h1 class="brand">{{ $shop }}</h1>
                <div class="rich">
                    {!! $message !!}
                </div>
                @if (filled($footer))
                    <div class="foot rich">
                        {!! $footer !!}
                    </div>
                @endif
            </main>
        </div>
    </div>

    @if (filled($banner))
        <aside class="ground" role="complementary" aria-label="{{ __('Maintenance notice') }}">
            <div class="rich">{!! $banner !!}</div>
        </aside>
    @endif
</body>
</html>
