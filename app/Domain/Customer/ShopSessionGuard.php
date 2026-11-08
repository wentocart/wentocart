<?php

namespace App\Domain\Customer;

use App\Domain\Installer\Models\ShopUrl;
use Illuminate\Auth\SessionGuard;

/**
 * Remember-me cookie is per shop. A login on /mobile must not sign in /eletronic.
 */
class ShopSessionGuard extends SessionGuard
{
    public function getRecallerName()
    {
        $matched = request()->attributes->get('wento.shop_url');
        $storeId = $matched instanceof ShopUrl ? (int) $matched->store_id : 0;

        return 'remember_'.$this->name.'_'.$storeId.'_'.sha1(static::class);
    }
}
