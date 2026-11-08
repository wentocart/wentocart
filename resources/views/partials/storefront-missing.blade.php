@if(in_array((int) ($httpStatus ?? 200), [404, 410], true))
<style nonce="{{ $cspNonce ?? '' }}">
    .wento-boot-missing{box-sizing:border-box;min-height:70vh;display:flex;align-items:center;justify-content:center;padding:48px 16px;background:#fafafa;color:#0f172a;font-family:ui-sans-serif,system-ui,sans-serif}
    .wento-boot-missing-card{width:min(100%,560px);background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:48px 32px;text-align:center}
    .wento-boot-missing-kicker{margin:0;font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#4f46e5}
    .wento-boot-missing h1{margin:8px 0 0;font-size:30px;line-height:1.2}
    .wento-boot-missing p{margin:12px 0 0;color:#64748b}
    .wento-boot-missing-actions{display:flex;flex-wrap:wrap;gap:12px;justify-content:center;margin-top:24px}
    .wento-boot-missing a{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:8px 16px;border-radius:8px;text-decoration:none;font-weight:600}
    .wento-boot-missing a.is-primary{background:#4f46e5;color:#fff}
    .wento-boot-missing a.is-ghost{background:#fff;color:#0f172a;border:1px solid #e2e8f0}
    #storefront:not(:empty) ~ .wento-boot-missing{display:none}
</style>
<section class="wento-boot-missing">
    <div class="wento-boot-missing-card">
        <p class="wento-boot-missing-kicker">{{ (int) $httpStatus === 410 ? '410' : '404' }}</p>
        <h1>{{ (int) $httpStatus === 410 ? 'Page gone' : 'Page not found' }}</h1>
        <p>{{ (int) $httpStatus === 410 ? 'This product or page is no longer available.' : 'This link is missing, or the product does not exist.' }}</p>
        <div class="wento-boot-missing-actions">
            <a class="is-primary" href="{{ \App\Support\WebBase::url('/') }}">Home</a>
            <a class="is-ghost" href="{{ \App\Support\WebBase::url('/catalog') }}">Shop</a>
        </div>
    </div>
</section>
@endif
