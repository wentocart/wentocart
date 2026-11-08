import { recentlyViewed } from '../lib/storage.js';
import { recentlyViewedEnabled } from '../lib/commerce.js';
import { useStore } from '../state/StoreState.jsx';
import { t } from '../i18n.js';
import ProductCard from './ProductCard.jsx';
import HomeCarousel, {
    productCarouselBreakpoints,
    productCarouselFallback,
} from './HomeCarousel.jsx';

export default function RecentlyViewed({ excludeId }) {
    const store = useStore();
    if (!recentlyViewedEnabled(store.shop)) {
        return null;
    }
    const rows = recentlyViewed()
        .filter((row) => Number(row.id) !== Number(excludeId))
        .slice(0, 8);
    if (!rows.length) {
        return null;
    }

    return (
        <section className="shop-pdp2-related">
            <div className="shop-pdp2-related-head">
                <h2>{t('storefront.pdp.recently_viewed', 'Recently viewed')}</h2>
            </div>
            <HomeCarousel
                items={rows}
                breakpoints={productCarouselBreakpoints(5)}
                fallback={productCarouselFallback(5)}
                gap={20}
                speedMs={250}
                showControls
                showDots={false}
                className="shop-pdp2-related-carousel"
                renderItem={(product) => <ProductCard product={product} compact />}
            />
        </section>
    );
}
