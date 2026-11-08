<?php

namespace App\Http\Controllers\Installer;

use App\Domain\Installer\Models\ShopUrl;
use App\Domain\Security\AdminPath;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InstalledController extends Controller
{
    public function __invoke(Request $request): View
    {
        $live = rtrim($request->getSchemeAndHttpHost().$request->getBasePath(), '/');
        $configured = rtrim((string) config('app.url'), '/');
        $shop = ($configured !== '' && ShopUrl::sameServingOrigin($configured, $live))
            ? $configured
            : $live;

        return view('installed', [
            'name' => (string) config('wento.name'),
            'shopUrl' => $shop.'/',
            'adminUrl' => $shop.'/'.AdminPath::segment().'/login',
        ]);
    }
}
