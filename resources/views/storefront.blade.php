<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    class="theme-{{ $theme['name'] ?? 'classic' }}"
    data-shop-appearance="{{ \App\Domain\Theme\ThemeSettingsCatalog::appearanceFor($theme['settings']['colors'] ?? []) }}"
    style="font-size: {{ $theme['settings']['font_size'] ?? '16px' }}"
>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo['title'] }}</title>
    @php
        $favicon = \App\Domain\Theme\ThemeSettingsCatalog::resolvedFaviconUrl($theme['settings']['favicon_url'] ?? '');
        if (! preg_match('#^https?://#i', $favicon)) {
            $favicon = \App\Support\WebBase::url($favicon);
        }
    @endphp
    @if($favicon !== '')
    <link rel="icon" href="{{ $favicon }}">
    @endif
    <meta name="description" content="{{ $seo['description'] }}">
    <meta name="robots" content="{{ $seo['robots'] ?? 'index,follow' }}">
    @if(!empty($seo['keywords']))
    <meta name="keywords" content="{{ $seo['keywords'] }}">
    @endif
    <link rel="canonical" href="{{ $seo['canonical'] }}">
    <meta property="og:title" content="{{ $seo['title'] }}">
    <meta property="og:description" content="{{ $seo['description'] }}">
    <meta property="og:url" content="{{ $seo['canonical'] }}">
    <meta property="og:type" content="{{ $seo['og_type'] ?? 'website' }}">
    @if(!empty($seo['og_image']))
    <meta property="og:image" content="{{ $seo['og_image'] }}">
    <link rel="preload" as="image" href="{{ $seo['og_image'] }}">
    @endif
    <meta name="twitter:card" content="{{ $seo['twitter_card'] ?? 'summary' }}">
    <meta name="twitter:title" content="{{ $seo['title'] }}">
    <meta name="twitter:description" content="{{ $seo['description'] }}">
    @if(!empty($seo['og_image']))
    <meta name="twitter:image" content="{{ $seo['og_image'] }}">
    @endif
    @foreach($seo['hreflang'] ?? [] as $row)
    <link rel="alternate" hreflang="{{ $row['lang'] }}" href="{{ $row['url'] }}">
    @endforeach
    <script type="application/ld+json" nonce="{{ $cspNonce ?? '' }}">{!! json_encode($seo['json_ld'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    @php
        $colors = $theme['settings']['colors'] ?? [];
        $radius = $theme['settings']['radius'] ?? '14px';
        $font = $theme['settings']['font'] ?? 'Plus Jakarta Sans';
        $fontSize = $theme['settings']['font_size'] ?? '16px';
        if (is_string($fontSize) && preg_match('/^(\d+(?:\.\d+)?)px$/', trim($fontSize), $fontSizeMatch)) {
            $fontSize = rtrim(rtrim(number_format(((float) $fontSizeMatch[1]) / 16, 4, '.', ''), '0'), '.').'rem';
        }
        $fontQuery = rawurlencode($font);
        $appearance = \App\Domain\Theme\ThemeSettingsCatalog::appearanceFor($colors);
    @endphp
    <link href="https://fonts.googleapis.com/css2?family={{ str_replace('%20', '+', $fontQuery) }}:wght@400;500;600;700&family=Plus+Jakarta+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">
    <style nonce="{{ $cspNonce ?? '' }}">
        :root {
            color-scheme: {{ $appearance }};
            --shop-accent: {{ $colors['accent'] ?? '#4F46E5' }};
            --shop-accent-soft: {{ $colors['accent_soft'] ?? '#EEF2FF' }};
            --shop-ink: {{ $colors['ink'] ?? '#0F172A' }};
            --shop-muted: {{ $colors['muted'] ?? '#64748B' }};
            --shop-surface: {{ $colors['surface'] ?? '#FAFAFA' }};
            --shop-card: {{ $colors['card'] ?? '#FFFFFF' }};
            --shop-header: {{ $colors['header'] ?? '#FFFFFF' }};
            --shop-footer: {{ $colors['footer'] ?? '#0F172A' }};
            --shop-sale: {{ $colors['sale'] ?? '#F43F5E' }};
            --shop-highlight: {{ $colors['highlight'] ?? '#F59E0B' }};
            --shop-radius: {{ $radius }};
            --shop-font: '{{ $font }}', 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
            --shop-font-size: {{ $fontSize }};
            --shop-border: {{ $appearance === 'dark' ? 'rgba(255,255,255,0.12)' : '#e2e8f0' }};
            --shop-on-accent: {{ $appearance === 'dark' ? '#0A0B10' : '#FFFFFF' }};
        }
    </style>
    @vite(['storefront/src/styles.css', 'storefront/src/main.jsx'])
    @foreach($theme['css'] ?? [] as $href)
    <link rel="stylesheet" href="{{ $href }}">
    @endforeach
</head>
<body class="bg-[var(--shop-surface)] text-[var(--shop-ink)] antialiased" style="font-size: var(--shop-font-size);">
    @if(!empty($seo['crawl_html']))
    <noscript>
        <section id="wento-crawl-content" class="wento-seo-fallback max-w-4xl mx-auto p-4 bg-white text-slate-800">
            {!! $seo['crawl_html'] !!}
        </section>
    </noscript>
    @if(\App\Domain\Seo\StorefrontSeoPolicy::isCrawler())
    <section id="wento-crawl-crawler" class="wento-seo-crawler" style="display:none;" aria-hidden="false">
        {!! $seo['crawl_html'] !!}
    </section>
    @endif
    @endif
    <div id="storefront"></div>
    @include('partials.storefront-missing')
    <script nonce="{{ $cspNonce ?? '' }}">window.__WENTO__ = @json($boot);</script>
    @if(!empty($i18nScriptUrl))
    <script nonce="{{ $cspNonce ?? '' }}" src="{{ $i18nScriptUrl }}"></script>
    @endif
    @include('partials.webmcp')
</body>
</html>
