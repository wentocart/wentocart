import { Navigate } from 'react-router-dom';

/** Shop settings live on the profile page — keep this route for old links and sidebar. */
export default function SellerShop() {
    return <Navigate to="/account/seller/profile#seller-shop-form" replace />;
}
