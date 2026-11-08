<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="theme-{{ $theme['name'] ?? 'classic' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo['title'] }}</title>
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
    @foreach($theme['css'] ?? [] as $href)
    <link rel="stylesheet" href="{{ $href }}?v={{ urlencode($theme['version'] ?? '1') }}">
    @endforeach
    <script type="application/ld+json" nonce="{{ $cspNonce ?? '' }}">{!! json_encode($seo['json_ld'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
</head>
<body>
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
    @foreach($theme['js'] ?? [] as $src)
    <script type="module" nonce="{{ $cspNonce ?? '' }}" src="{{ $src }}?v={{ urlencode($theme['version'] ?? '1') }}"></script>
    @endforeach
</body>
</html>
