import '@vitejs/plugin-react/preamble';
import { StrictMode, Suspense, lazy } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Route, Routes } from 'react-router-dom';
/* styles.css is a dedicated Vite entry in storefront.blade.php — do not import here
   or Laravel Vite will inject the same stylesheet twice. */
import Shell from './Shell.jsx';
import { StoreProvider } from './state/StoreState.jsx';
import Home from './pages/Home.jsx';
import { RouteFallback } from './components/RouteFallback.jsx';
import { shopBasePath } from './webPath.js';
import { useShopSession } from './state/useShopSession.js';

const localePrefix = typeof window !== 'undefined' ? String(window.__WENTO__?.locale_prefix || '').replace(/\/$/, '') : '';
const routerBasename = [shopBasePath(), localePrefix].filter(Boolean).join('') || undefined;

/** Lazy-load a named export as a route component. */
function lazyNamed(loader, exportName) {
    return lazy(() => loader().then((m) => ({ default: m[exportName] })));
}

const Catalog = lazy(() => import('./pages/Catalog.jsx'));
const Deal = lazy(() => import('./pages/Deal.jsx'));
const Cart = lazy(() => import('./pages/Cart.jsx'));
const Checkout = lazy(() => import('./pages/Checkout.jsx'));
const OrderSuccess = lazy(() => import('./pages/OrderSuccess.jsx'));
const Login = lazy(() => import('./pages/Login.jsx'));
const Register = lazy(() => import('./pages/Register.jsx'));
const RegisterPending = lazy(() => import('./pages/RegisterPending.jsx'));
const ForgotPassword = lazy(() => import('./pages/ForgotPassword.jsx'));
const ResetPassword = lazy(() => import('./pages/ResetPassword.jsx'));
const Account = lazy(() => import('./pages/Account.jsx'));
const AccountLayout = lazyNamed(() => import('./pages/Account.jsx'), 'AccountLayout');
const Orders = lazy(() => import('./pages/Orders.jsx'));
const Preorders = lazy(() => import('./pages/Preorders.jsx'));
const StockAlerts = lazy(() => import('./pages/StockAlerts.jsx'));
const OrderDetail = lazy(() => import('./pages/OrderDetail.jsx'));
const Wishlist = lazy(() => import('./pages/Wishlist.jsx'));
const Profile = lazy(() => import('./pages/Profile.jsx'));
const GdprRequests = lazy(() => import('./pages/GdprRequests.jsx'));
const CookiePreferences = lazyNamed(() => import('./pages/GdprRequests.jsx'), 'CookiePreferences');
const Addresses = lazy(() => import('./pages/Addresses.jsx'));
const Vouchers = lazy(() => import('./pages/Vouchers.jsx'));
const GiftCards = lazy(() => import('./pages/GiftCards.jsx'));
const Downloads = lazy(() => import('./pages/Downloads.jsx'));
const Quotes = lazy(() => import('./pages/Quotes.jsx'));
const SharedWishlist = lazy(() => import('./pages/SharedWishlist.jsx'));
const Subscriptions = lazy(() => import('./pages/Subscriptions.jsx'));
const SubscriptionDetail = lazy(() => import('./pages/SubscriptionDetail.jsx'));
const Reviews = lazy(() => import('./pages/Reviews.jsx'));
const Help = lazy(() => import('./pages/Help.jsx'));
const OrderStatus = lazy(() => import('./pages/OrderStatus.jsx'));
const Brands = lazy(() => import('./pages/Brands.jsx'));
const Cms = lazy(() => import('./pages/Cms.jsx'));
const Blog = lazy(() => import('./pages/Blog.jsx'));
const NotFound = lazy(() => import('./pages/NotFound.jsx'));
const Shop = lazy(() => import('./pages/Shop.jsx'));
const ModulePage = lazy(() => import('./pages/ModulePage.jsx'));
const Search = lazy(() => import('./pages/Search.jsx'));
const Compare = lazy(() => import('./pages/Compare.jsx'));
const SmartPage = lazy(() => import('./pages/SmartPage.jsx'));
const Brand = lazy(() => import('./pages/Brand.jsx'));
const Supplier = lazy(() => import('./pages/Supplier.jsx'));

const SellerLayout = lazy(() => import('./pages/seller/SellerLayout.jsx'));
const SellerHome = lazy(() => import('./pages/seller/SellerHome.jsx'));
const SellerProfile = lazy(() => import('./pages/seller/SellerProfile.jsx'));
const SellerShop = lazy(() => import('./pages/seller/SellerShop.jsx'));
const SellerProducts = lazy(() => import('./pages/seller/SellerProducts.jsx'));
const SellerProductForm = lazyNamed(() => import('./pages/seller/SellerProducts.jsx'), 'SellerProductForm');
const SellerInventory = lazy(() => import('./pages/seller/SellerInventory.jsx'));
const SellerOrders = lazy(() => import('./pages/seller/SellerOrders.jsx'));
const SellerOrderDetail = lazyNamed(() => import('./pages/seller/SellerOrders.jsx'), 'SellerOrderDetail');
const SellerShipments = lazy(() => import('./pages/seller/SellerShipments.jsx'));
const SellerCommission = lazyNamed(() => import('./pages/seller/SellerFinance.jsx'), 'SellerCommission');
const SellerEarnings = lazyNamed(() => import('./pages/seller/SellerFinance.jsx'), 'SellerEarnings');
const SellerLedger = lazyNamed(() => import('./pages/seller/SellerFinance.jsx'), 'SellerLedger');
const SellerRevenue = lazyNamed(() => import('./pages/seller/SellerFinance.jsx'), 'SellerRevenue');
const SellerPayouts = lazyNamed(() => import('./pages/seller/SellerFinance.jsx'), 'SellerPayouts');
const SellerShipping = lazy(() => import('./pages/seller/SellerShipping.jsx'));
const SellerPayments = lazy(() => import('./pages/seller/SellerPayments.jsx'));
const SellerDiscounts = lazy(() => import('./pages/seller/SellerDiscounts.jsx'));
const SellerCategories = lazy(() => import('./pages/seller/SellerCategories.jsx'));
const SellerCategoryForm = lazyNamed(() => import('./pages/seller/SellerCategories.jsx'), 'SellerCategoryForm');
const SellerBrands = lazy(() => import('./pages/seller/SellerBrands.jsx'));
const SellerBrandForm = lazyNamed(() => import('./pages/seller/SellerBrands.jsx'), 'SellerBrandForm');
const SellerFeatures = lazy(() => import('./pages/seller/SellerFeatures.jsx'));
const SellerFeatureForm = lazyNamed(() => import('./pages/seller/SellerFeatures.jsx'), 'SellerFeatureForm');
const SellerFeatureValues = lazy(() => import('./pages/seller/SellerFeatureValues.jsx'));
const SellerFeatureValueForm = lazyNamed(() => import('./pages/seller/SellerFeatureValues.jsx'), 'SellerFeatureValueForm');
const SellerSuppliers = lazy(() => import('./pages/seller/SellerSuppliers.jsx'));
const SellerSupplierForm = lazyNamed(() => import('./pages/seller/SellerSuppliers.jsx'), 'SellerSupplierForm');
const SellerAttributes = lazy(() => import('./pages/seller/SellerAttributes.jsx'));
const SellerAttributeForm = lazyNamed(() => import('./pages/seller/SellerAttributes.jsx'), 'SellerAttributeForm');
const SellerAttributeValues = lazy(() => import('./pages/seller/SellerAttributeValues.jsx'));
const SellerAttributeValueForm = lazyNamed(() => import('./pages/seller/SellerAttributeValues.jsx'), 'SellerAttributeValueForm');
const SellerMessages = lazy(() => import('./pages/seller/SellerMessages.jsx'));
const SellerReturns = lazy(() => import('./pages/seller/SellerReturns.jsx'));
const SellerReviews = lazy(() => import('./pages/seller/SellerReviews.jsx'));
const SellerSettings = lazy(() => import('./pages/seller/SellerSettings.jsx'));

import { GrowlProvider } from './components/Growl.jsx';

function SessionRoutes({ children }) {
    const { currency, locale } = useShopSession();

    return <Routes key={`${locale}:${currency}`}>{children}</Routes>;
}

createRoot(document.getElementById('storefront')).render(
    <StrictMode>
        <GrowlProvider>
            <StoreProvider>
            <BrowserRouter basename={routerBasename}>
                <Suspense fallback={<RouteFallback />}>
                    <SessionRoutes>
                        <Route path="/account/seller" element={<SellerLayout />}>
                            <Route index element={<SellerHome />} />
                            <Route path="profile" element={<SellerProfile />} />
                            <Route path="shop" element={<SellerShop />} />
                            <Route path="settings" element={<SellerSettings />} />
                            <Route path="products" element={<SellerProducts />} />
                            <Route path="products/new" element={<SellerProductForm />} />
                            <Route path="products/:id" element={<SellerProductForm />} />
                            <Route path="inventory" element={<SellerInventory />} />
                            <Route path="orders" element={<SellerOrders />} />
                            <Route path="orders/:id" element={<SellerOrderDetail />} />
                            <Route path="shipments" element={<SellerShipments />} />
                            <Route path="returns" element={<SellerReturns />} />
                            <Route path="reviews" element={<SellerReviews />} />
                            <Route path="commission" element={<SellerCommission />} />
                            <Route path="earnings" element={<SellerEarnings />} />
                            <Route path="ledger" element={<SellerLedger />} />
                            <Route path="transactions" element={<SellerLedger titleKey="transactions" />} />
                            <Route path="revenue" element={<SellerRevenue />} />
                            <Route path="shipping" element={<SellerShipping />} />
                            <Route path="payments" element={<SellerPayments />} />
                            <Route path="discounts" element={<SellerDiscounts />} />
                            <Route path="categories" element={<SellerCategories />} />
                            <Route path="categories/new" element={<SellerCategoryForm />} />
                            <Route path="categories/:id" element={<SellerCategoryForm />} />
                            <Route path="feature-values" element={<SellerFeatureValues />} />
                            <Route path="feature-values/new" element={<SellerFeatureValueForm />} />
                            <Route path="feature-values/:id" element={<SellerFeatureValueForm />} />
                            <Route path="features/:featureId/values" element={<SellerFeatureValues />} />
                            <Route path="features/:featureId/values/new" element={<SellerFeatureValueForm />} />
                            <Route path="features" element={<SellerFeatures />} />
                            <Route path="features/new" element={<SellerFeatureForm />} />
                            <Route path="features/:id" element={<SellerFeatureForm />} />
                            <Route path="brands" element={<SellerBrands />} />
                            <Route path="brands/new" element={<SellerBrandForm />} />
                            <Route path="brands/:id" element={<SellerBrandForm />} />
                            <Route path="suppliers" element={<SellerSuppliers />} />
                            <Route path="suppliers/new" element={<SellerSupplierForm />} />
                            <Route path="suppliers/:id" element={<SellerSupplierForm />} />
                            <Route path="attribute-values" element={<SellerAttributeValues />} />
                            <Route path="attribute-values/new" element={<SellerAttributeValueForm />} />
                            <Route path="attribute-values/:id" element={<SellerAttributeValueForm />} />
                            <Route path="attributes/:attributeId/values" element={<SellerAttributeValues />} />
                            <Route path="attributes/:attributeId/values/new" element={<SellerAttributeValueForm />} />
                            <Route path="attributes" element={<SellerAttributes />} />
                            <Route path="attributes/new" element={<SellerAttributeForm />} />
                            <Route path="attributes/:id" element={<SellerAttributeForm />} />
                            <Route path="messages" element={<SellerMessages />} />
                            <Route path="payouts" element={<SellerPayouts />} />
                        </Route>
                        <Route element={<Shell />}>
                            <Route path="/" element={<Home />} />
                            <Route path="/catalog" element={<Catalog />} />
                            <Route path="/new-arrivals" element={<Catalog slug="new-arrivals" />} />
                            <Route path="/deal" element={<Deal />} />
                            <Route path="/search" element={<Search />} />
                            <Route path="/compare" element={<Compare />} />
                            <Route path="/brands" element={<Brands />} />
                            <Route path="/brand/:slug" element={<Brand />} />
                            <Route path="/shop/:slug" element={<Shop />} />
                            <Route path="/supplier/:slug" element={<Supplier />} />
                            <Route path="/cart" element={<Cart />} />
                            <Route path="/checkout" element={<Checkout />} />
                            <Route path="/order/:number" element={<OrderSuccess />} />
                            <Route path="/login" element={<Login />} />
                            <Route path="/register" element={<Register />} />
                            <Route path="/register/pending" element={<RegisterPending />} />
                            <Route path="/forgot-password" element={<ForgotPassword />} />
                            <Route path="/reset-password" element={<ResetPassword />} />
                            <Route path="/help" element={<Help />} />
                            <Route path="/order-status" element={<OrderStatus />} />
                            <Route path="/blog" element={<Blog />} />
                            {/* CMS pages — one design for every content page, driven by layout.hero. */}
                            <Route path="/about" element={<Cms slug="about" />} />
                            <Route path="/shipping" element={<Cms slug="shipping" />} />
                            <Route path="/returns" element={<Cms slug="returns" />} />
                            <Route path="/privacy" element={<Cms slug="privacy" />} />
                            <Route path="/terms" element={<Cms slug="terms" />} />
                            <Route path="/faq" element={<Cms slug="faq" />} />
                            <Route path="/contact" element={<Cms slug="contact" />} />
                            <Route path="/stores" element={<Cms slug="stores" />} />
                            <Route path="/payment-security" element={<Cms slug="payment-security" />} />
                            <Route path="/order-tracking" element={<Cms slug="order-tracking" />} />
                            <Route path="/warranty" element={<Cms slug="warranty" />} />
                            <Route path="/wishlist/shared/:token" element={<SharedWishlist />} />
                            <Route path="/404" element={<NotFound />} />
                            <Route path="/m/:vendor/:name/:page" element={<ModulePage />} />
                            <Route path="/account" element={<AccountLayout />}>
                                <Route index element={<Account />} />
                                <Route path="orders" element={<Orders />} />
                                <Route path="orders/:number" element={<OrderDetail />} />
                                <Route path="preorders" element={<Preorders />} />
                                <Route path="stock-alerts" element={<StockAlerts />} />
                                <Route path="wishlist" element={<Wishlist />} />
                                <Route path="profile" element={<Profile />} />
                                <Route path="gdpr" element={<GdprRequests />} />
                                <Route path="gdpr/cookies" element={<CookiePreferences />} />
                                <Route path="addresses" element={<Addresses />} />
                                <Route path="addresses/:id" element={<Addresses />} />
                                <Route path="vouchers" element={<Vouchers />} />
                                <Route path="gift-cards" element={<GiftCards />} />
                                <Route path="downloads" element={<Downloads />} />
                                <Route path="quotes" element={<Quotes />} />
                                <Route path="subscriptions" element={<Subscriptions />} />
                                <Route path="subscriptions/:id" element={<SubscriptionDetail />} />
                                <Route path="reviews" element={<Reviews />} />
                                <Route path="help" element={<Help compact />} />
                                <Route path="become-seller" element={<SellerHome />} />
                            </Route>
                            <Route path="*" element={<SmartPage />} />
                        </Route>
                    </SessionRoutes>
                </Suspense>
            </BrowserRouter>
        </StoreProvider>
        </GrowlProvider>
    </StrictMode>,
);
