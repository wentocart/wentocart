function CardGhost({ compact = false }) {
    return (
        <div className="cx-card">
            <div className="cx-card-media">
                <div className="skeleton aspect-square rounded-none" />
            </div>
            <div className={`cx-card-body space-y-2 ${compact ? 'pt-2' : ''}`}>
                <div className="skeleton h-3 w-1/3" />
                <div className="skeleton h-4 w-5/6" />
                <div className="skeleton h-4 w-1/2" />
            </div>
        </div>
    );
}

function CardRow({ count = 6, compact = true }) {
    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
            {Array.from({ length: count }).map((_, i) => (
                <CardGhost key={i} compact={compact} />
            ))}
        </div>
    );
}

export function ProductSkeleton({ count = 8 }) {
    return (
        <div className="shop-cat-grid">
            {Array.from({ length: count }).map((_, i) => (
                <CardGhost key={i} compact />
            ))}
        </div>
    );
}

/** Classic home: hero + sides, trust, promos, product rails. */
export function HomeSkeleton({ productsOnly = false } = {}) {
    return (
        <div className="cx-home-skeleton" aria-busy="true" aria-label="Loading home">
            {!productsOnly ? (
                <>
                    <div className="shop-container cx-hero-wrap">
                        <div className="cx-hero-grid">
                            <div className="cx-hero-main">
                                <div className="skeleton h-[280px] w-full rounded-[10px] sm:h-[420px] lg:h-[620px]" />
                            </div>
                            <div className="cx-hero-sides hidden gap-3 lg:grid">
                                <div className="skeleton h-[300px] w-full rounded-[10px]" />
                                <div className="skeleton h-[300px] w-full rounded-[10px]" />
                            </div>
                        </div>
                    </div>

                    <section className="shop-container mt-6">
                        <div className="cx-trust grid grid-cols-2 gap-3 lg:grid-cols-4">
                            {Array.from({ length: 4 }).map((_, i) => (
                                <div key={i} className="flex items-center gap-3 rounded-[10px] border border-neutral-100 p-3">
                                    <div className="skeleton h-10 w-10 shrink-0 rounded-full" />
                                    <div className="min-w-0 flex-1 space-y-2">
                                        <div className="skeleton h-3 w-3/4" />
                                        <div className="skeleton h-3 w-1/2" />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </section>

                    <section className="shop-container cx-promo-section mt-6">
                        <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                            {Array.from({ length: 3 }).map((_, i) => (
                                <div key={i} className="skeleton h-40 w-full rounded-[10px] md:h-48" />
                            ))}
                        </div>
                    </section>
                </>
            ) : null}

            <section className="shop-container cx-sec mt-8">
                <div className="cx-sec-title mb-4">
                    <div className="skeleton h-7 w-56" />
                </div>
                <CardRow count={6} />
            </section>

            <section className="shop-container cx-sec mt-8">
                <div className="cx-sec-title mb-4">
                    <div className="skeleton h-7 w-48" />
                </div>
                <CardRow count={6} />
            </section>

            <section className="cx-category-band mt-8">
                <div className="shop-container cx-sec">
                    <div className="cx-sec-title mb-4">
                        <div className="skeleton h-7 w-72" />
                    </div>
                    <div className="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-7">
                        {Array.from({ length: 7 }).map((_, i) => (
                            <div key={i} className="space-y-2">
                                <div className="skeleton aspect-square w-full rounded-full" />
                                <div className="skeleton mx-auto h-3 w-16" />
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            <section className="shop-container cx-sec mt-8 mb-10">
                <div className="cx-sec-title mb-4">
                    <div className="skeleton h-7 w-40" />
                </div>
                <div className="flex flex-wrap items-center justify-center gap-6">
                    {Array.from({ length: 8 }).map((_, i) => (
                        <div key={i} className="skeleton h-12 w-24 rounded-[8px]" />
                    ))}
                </div>
            </section>
        </div>
    );
}

/** Classic PDP: breadcrumbs, gallery + buy box, tabs, related rail. */
export function ProductPageSkeleton() {
    return (
        <div className="shop-container py-6 pb-24 lg:pb-10 shop-classic-product-page" aria-busy="true" aria-label="Loading product">
            <div className="flex flex-wrap items-center gap-2">
                <div className="skeleton h-3 w-12" />
                <div className="skeleton h-3 w-3" />
                <div className="skeleton h-3 w-20" />
                <div className="skeleton h-3 w-3" />
                <div className="skeleton h-3 w-28" />
            </div>

            <div className="mt-5 grid gap-6 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)]">
                <div className="space-y-3">
                    <div className="skeleton aspect-square w-full rounded-[10px]" />
                    <div className="grid grid-cols-4 gap-2 sm:grid-cols-5">
                        {Array.from({ length: 5 }).map((_, i) => (
                            <div key={i} className="skeleton aspect-square w-full rounded-[8px]" />
                        ))}
                    </div>
                </div>
                <div className="space-y-4">
                    <div className="skeleton h-3 w-24" />
                    <div className="skeleton h-8 w-11/12" />
                    <div className="skeleton h-8 w-2/3" />
                    <div className="flex items-center gap-2">
                        <div className="skeleton h-4 w-24" />
                        <div className="skeleton h-4 w-16" />
                    </div>
                    <div className="skeleton h-10 w-40" />
                    <div className="space-y-2 pt-2">
                        <div className="skeleton h-3 w-full" />
                        <div className="skeleton h-3 w-5/6" />
                        <div className="skeleton h-3 w-4/6" />
                    </div>
                    <div className="flex items-center gap-3 pt-2">
                        <div className="skeleton h-11 w-28 rounded-[8px]" />
                        <div className="skeleton h-11 flex-1 rounded-[8px]" />
                    </div>
                    <div className="skeleton h-11 w-full rounded-[8px]" />
                    <div className="grid grid-cols-3 gap-2 pt-2">
                        {Array.from({ length: 3 }).map((_, i) => (
                            <div key={i} className="skeleton h-16 w-full rounded-[8px]" />
                        ))}
                    </div>
                </div>
            </div>

            <div className="mt-10 space-y-4">
                <div className="flex gap-4 border-b border-neutral-100 pb-3">
                    {Array.from({ length: 4 }).map((_, i) => (
                        <div key={i} className="skeleton h-4 w-24" />
                    ))}
                </div>
                <div className="space-y-2">
                    <div className="skeleton h-3 w-full" />
                    <div className="skeleton h-3 w-11/12" />
                    <div className="skeleton h-3 w-10/12" />
                    <div className="skeleton h-3 w-9/12" />
                </div>
            </div>

            <div className="mt-10">
                <div className="skeleton mb-4 h-7 w-56" />
                <CardRow count={5} />
            </div>
        </div>
    );
}

/** Category / catalog / search listing — mirrors current shop-cat layout. */
export function CategoryPageSkeleton() {
    return (
        <div className="shop-cat" aria-busy="true" aria-label="Loading category">
            <div className="shop-container shop-cat-inner">
                <div className="flex flex-wrap items-center gap-2">
                    <div className="skeleton h-3 w-12" />
                    <div className="skeleton h-3 w-3" />
                    <div className="skeleton h-3 w-24" />
                </div>

                <header className="shop-cat-hero is-text-only">
                    <div className="shop-cat-hero-copy space-y-2">
                        <div className="skeleton h-8 w-56 max-w-full" />
                        <div className="skeleton h-3 w-28" />
                        <div className="skeleton h-3 w-full max-w-xl" />
                        <div className="skeleton h-3 w-4/5 max-w-lg" />
                    </div>
                </header>
            </div>

            <div className="shop-container shop-cat-inner">
                <div className="shop-cat-layout">
                    <aside className="shop-cat-aside hidden lg:block">
                        <div className="shop-cat-filters">
                            <div className="shop-cat-filters-head">
                                <div className="skeleton h-5 w-20" />
                            </div>
                            {Array.from({ length: 4 }).map((_, i) => (
                                <section key={i} className="shop-cat-filter space-y-2">
                                    <div className="skeleton h-4 w-24" />
                                    <div className="skeleton h-3 w-full" />
                                    <div className="skeleton h-3 w-5/6" />
                                    <div className="skeleton h-3 w-2/3" />
                                </section>
                            ))}
                        </div>
                    </aside>

                    <div className="shop-cat-main">
                        <div className="shop-cat-toolbar">
                            <div className="skeleton h-4 w-48" />
                            <div className="shop-cat-toolbar-actions">
                                <div className="skeleton h-9 w-44 rounded-[10px]" />
                                <div className="skeleton h-9 w-20 rounded-[10px]" />
                            </div>
                        </div>
                        <ProductSkeleton count={8} />
                    </div>
                </div>
            </div>
        </div>
    );
}

export function BrandPageSkeleton() {
    return (
        <div className="shop-container py-8" aria-busy="true" aria-label="Loading brand">
            <div className="flex items-center gap-4">
                <div className="skeleton h-16 w-16 rounded-[10px]" />
                <div className="space-y-2">
                    <div className="skeleton h-7 w-40" />
                    <div className="skeleton h-3 w-64" />
                </div>
            </div>
            <div className="mt-8">
                <ProductSkeleton count={8} />
            </div>
        </div>
    );
}

export function CmsPageSkeleton() {
    return (
        <div className="shop-container py-10" aria-busy="true" aria-label="Loading page">
            <div className="skeleton h-9 w-72" />
            <div className="mt-6 max-w-3xl space-y-3">
                {Array.from({ length: 8 }).map((_, i) => (
                    <div key={i} className={`skeleton h-3 ${i % 3 === 0 ? 'w-full' : i % 3 === 1 ? 'w-11/12' : 'w-4/5'}`} />
                ))}
            </div>
        </div>
    );
}

/** Cart: lines column + summary card. */
export function CartPageSkeleton() {
    return (
        <section className="shop-cart-stage" aria-busy="true" aria-label="Loading cart">
            <div className="shop-container shop-cart-layout">
                <div className="shop-cart-main space-y-4">
                    <div className="skeleton h-9 w-48" />
                    <div className="shop-cart-group-head flex justify-between">
                        <div className="skeleton h-6 w-40" />
                        <div className="skeleton h-9 w-36 rounded-[10px]" />
                    </div>
                    {Array.from({ length: 2 }).map((_, i) => (
                        <div key={i} className="flex gap-4 rounded-[16px] border border-neutral-100 bg-white p-4">
                            <div className="skeleton h-24 w-24 rounded-[12px]" />
                            <div className="min-w-0 flex-1 space-y-3">
                                <div className="skeleton h-4 w-2/3" />
                                <div className="skeleton h-3 w-20" />
                                <div className="skeleton h-3 w-24" />
                                <div className="flex gap-3 pt-1">
                                    <div className="skeleton h-9 w-28 rounded-[10px]" />
                                    <div className="skeleton h-9 w-24 rounded-[10px]" />
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
                <aside className="shop-cart-summary space-y-3">
                    <div className="skeleton h-6 w-40" />
                    {Array.from({ length: 5 }).map((_, i) => (
                        <div key={i} className="flex justify-between gap-4">
                            <div className="skeleton h-3 w-24" />
                            <div className="skeleton h-3 w-16" />
                        </div>
                    ))}
                    <div className="skeleton h-11 w-full rounded-[12px]" />
                    <div className="skeleton h-11 w-full rounded-[12px]" />
                </aside>
            </div>
        </section>
    );
}

/** Checkout: three-column stage (contact / methods / summary). */
export function CheckoutPageSkeleton() {
    return (
        <section className="shop-checkout-stage" aria-busy="true" aria-label="Loading checkout">
            <div className="shop-container shop-checkout-shell">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-2">
                        <div className="skeleton h-9 w-48" />
                        <div className="skeleton h-3 w-72" />
                    </div>
                    <div className="skeleton h-8 w-56 rounded-full" />
                </div>
                <div className="shop-checkout-grid">
                    <div className="shop-checkout-panel space-y-3">
                        <div className="skeleton h-5 w-48" />
                        <div className="skeleton h-11 w-full rounded-[10px]" />
                        <div className="skeleton h-4 w-64" />
                        <div className="grid grid-cols-2 gap-3">
                            <div className="skeleton h-11 w-full rounded-[10px]" />
                            <div className="skeleton h-11 w-full rounded-[10px]" />
                        </div>
                        <div className="skeleton h-11 w-full rounded-[10px]" />
                        <div className="skeleton h-11 w-full rounded-[10px]" />
                        <div className="grid grid-cols-3 gap-3">
                            <div className="skeleton h-11 w-full rounded-[10px]" />
                            <div className="skeleton h-11 w-full rounded-[10px]" />
                            <div className="skeleton h-11 w-full rounded-[10px]" />
                        </div>
                    </div>
                    <div className="shop-checkout-mid space-y-4">
                        <div className="shop-checkout-panel space-y-3">
                            <div className="skeleton h-5 w-40" />
                            {Array.from({ length: 3 }).map((_, i) => (
                                <div key={i} className="skeleton h-14 w-full rounded-[12px]" />
                            ))}
                        </div>
                        <div className="shop-checkout-panel space-y-3">
                            <div className="skeleton h-5 w-40" />
                            {Array.from({ length: 4 }).map((_, i) => (
                                <div key={i} className="skeleton h-12 w-full rounded-[12px]" />
                            ))}
                        </div>
                    </div>
                    <aside className="shop-checkout-summary space-y-3">
                        <div className="flex justify-between">
                            <div className="skeleton h-5 w-36" />
                            <div className="skeleton h-4 w-16" />
                        </div>
                        <div className="flex gap-3">
                            <div className="skeleton h-12 w-12 rounded-[10px]" />
                            <div className="min-w-0 flex-1 space-y-2">
                                <div className="skeleton h-3 w-2/3" />
                                <div className="skeleton h-3 w-16" />
                            </div>
                        </div>
                        {Array.from({ length: 5 }).map((_, i) => (
                            <div key={i} className="flex justify-between gap-4">
                                <div className="skeleton h-3 w-24" />
                                <div className="skeleton h-3 w-14" />
                            </div>
                        ))}
                        <div className="skeleton h-12 w-full rounded-[12px]" />
                        <div className="skeleton h-12 w-full rounded-[12px]" />
                    </aside>
                </div>
            </div>
        </section>
    );
}

/** Account dashboard: stats + recent orders. */
export function AccountPageSkeleton() {
    return (
        <div className="shop-account-dash" aria-busy="true" aria-label="Loading account">
            <div className="space-y-2">
                <div className="skeleton h-9 w-64" />
                <div className="skeleton h-3 w-80" />
            </div>
            <div className="shop-account-stats">
                {Array.from({ length: 4 }).map((_, i) => (
                    <div key={i} className="rounded-[16px] border border-neutral-100 bg-white p-4">
                        <div className="skeleton mb-3 h-10 w-10 rounded-full" />
                        <div className="skeleton h-3 w-16" />
                        <div className="skeleton mt-2 h-8 w-10" />
                    </div>
                ))}
            </div>
            <div className="shop-account-panel space-y-4 p-6">
                <div className="flex justify-between">
                    <div className="skeleton h-5 w-36" />
                    <div className="skeleton h-4 w-28" />
                </div>
                <div className="skeleton mx-auto h-28 w-40" />
                <div className="skeleton mx-auto h-4 w-40" />
                <div className="skeleton mx-auto h-11 w-40 rounded-[12px]" />
            </div>
        </div>
    );
}

/** Auth pages: left copy + right form card. */
export function AuthPageSkeleton({ variant = 'login' }) {
    const register = variant === 'register';
    return (
        <section className="shop-auth-stage" aria-busy="true" aria-label="Loading account page">
            <div className="shop-container shop-auth-shell">
                <aside className="shop-auth-copy space-y-4">
                    <div className="skeleton h-3 w-28" />
                    <div className="skeleton h-10 w-11/12" />
                    <div className="skeleton h-10 w-3/4" />
                    <div className="skeleton h-3 w-full" />
                    <div className="skeleton h-3 w-5/6" />
                    <div className="mt-6 space-y-3">
                        {Array.from({ length: 3 }).map((_, i) => (
                            <div key={i} className="flex items-center gap-3">
                                <div className="skeleton h-9 w-9 rounded-full" />
                                <div className="min-w-0 flex-1 space-y-2">
                                    <div className="skeleton h-3 w-32" />
                                    <div className="skeleton h-3 w-48" />
                                </div>
                            </div>
                        ))}
                    </div>
                </aside>
                <div className="shop-auth-card space-y-4">
                    <div className="skeleton h-7 w-40" />
                    <div className="skeleton h-3 w-64" />
                    <div className="space-y-3 pt-2">
                        {register ? (
                            <>
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="skeleton h-11 w-full rounded-[12px]" />
                                    <div className="skeleton h-11 w-full rounded-[12px]" />
                                </div>
                                <div className="skeleton h-11 w-full rounded-[12px]" />
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="skeleton h-11 w-full rounded-[12px]" />
                                    <div className="skeleton h-11 w-full rounded-[12px]" />
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="skeleton h-11 w-full rounded-[12px]" />
                                    <div className="skeleton h-11 w-full rounded-[12px]" />
                                </div>
                                <div className="skeleton h-11 w-full rounded-[12px]" />
                            </>
                        ) : (
                            <>
                                <div className="skeleton h-11 w-full rounded-[12px]" />
                                {variant !== 'forgot' ? <div className="skeleton h-11 w-full rounded-[12px]" /> : null}
                            </>
                        )}
                        <div className="skeleton h-11 w-full rounded-[12px]" />
                        <div className="skeleton mx-auto h-3 w-40" />
                    </div>
                </div>
            </div>
        </section>
    );
}

/** Brief neutral frame while SmartPage resolves product vs category vs CMS. */
export function ResolveSkeleton() {
    return (
        <div className="shop-container py-8" aria-busy="true" aria-label="Loading">
            <div className="flex flex-wrap items-center gap-2">
                <div className="skeleton h-3 w-12" />
                <div className="skeleton h-3 w-3" />
                <div className="skeleton h-3 w-24" />
            </div>
            <div className="mt-6 space-y-3">
                <div className="skeleton h-8 w-56" />
                <div className="skeleton h-3 w-72" />
            </div>
            <div className="mt-8 grid gap-4 lg:grid-cols-2">
                <div className="skeleton min-h-[280px] w-full rounded-[10px]" />
                <div className="space-y-3">
                    <div className="skeleton h-6 w-4/5" />
                    <div className="skeleton h-6 w-3/5" />
                    <div className="skeleton h-3 w-full" />
                    <div className="skeleton h-3 w-5/6" />
                    <div className="skeleton h-3 w-2/3" />
                    <div className="skeleton mt-4 h-11 w-40 rounded-[8px]" />
                </div>
            </div>
        </div>
    );
}

/** Fallback while SmartPage resolves the URL type. */
export function PageSkeleton() {
    return <ResolveSkeleton />;
}

export function EmptyState({ title, body, action, className = '' }) {
    return (
        <div className={`rounded-[16px] border border-dashed border-neutral-200 bg-white px-6 py-16 text-center${className ? ` ${className}` : ''}`}>
            <h2 className="text-xl font-semibold">{title}</h2>
            {body ? <p className="mx-auto mt-2 max-w-md text-neutral-500">{body}</p> : null}
            {action ? <div className="mt-5">{action}</div> : null}
        </div>
    );
}

/** Pick the closest page frame from the current path before resolve finishes. */
export function skeletonForPath(pathname = '') {
    const path = String(pathname || '').replace(/^\//, '');
    if (!path) {
        return <HomeSkeleton />;
    }
    const bare = path.split('/')[0];

    if (bare === 'catalog' || bare === 'search' || bare === 'deal') {
        return <CategoryPageSkeleton />;
    }
    if (bare === 'cart') {
        return <CartPageSkeleton />;
    }
    if (bare === 'checkout') {
        return <CheckoutPageSkeleton />;
    }
    if (bare === 'login' || bare === 'register' || bare === 'forgot-password' || bare === 'reset-password') {
        return <AuthPageSkeleton variant={bare === 'register' ? 'register' : 'login'} />;
    }
    if (bare === 'account') {
        return <AccountPageSkeleton />;
    }
    if (bare === 'brand' || bare === 'brands' || path.startsWith('brand/')) {
        return <BrandPageSkeleton />;
    }
    const cms = new Set([
        'about', 'shipping', 'privacy', 'terms', 'contact', 'help', 'blog',
    ]);
    if (cms.has(bare) && !/^\d+-/.test(path)) {
        return <CmsPageSkeleton />;
    }
    // Product and category share /{id}-{slug} — keep a neutral frame until resolve finishes.
    if (/^\d+-/.test(path)) {
        return <ResolveSkeleton />;
    }
    return <CategoryPageSkeleton />;
}
