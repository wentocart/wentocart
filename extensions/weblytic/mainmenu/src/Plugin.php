<?php

namespace Modules\Weblytic\Mainmenu;

use App\Domain\Plugin\Support\BasePlugin;
use Modules\Weblytic\Mainmenu\Http\ConfigureController;
use Modules\Weblytic\Mainmenu\Http\LinksController;
use Modules\Weblytic\Mainmenu\Http\MenuPayloadController;

class Plugin extends BasePlugin
{
    public function boot(): void
    {
        $this->menu()->group([
            'id' => 'mainmenu',
            'label' => 'Main menu',
            'label_key' => 'admin.module_nav.mainmenu',
            'icon' => 'menu',
            'items' => [
                ['page' => 'configure', 'label' => 'Configure', 'label_key' => 'admin.nav.configure'],
                ['page' => 'links', 'label' => 'Links', 'label_key' => 'admin.module_nav.mainmenu_links'],
            ],
        ]);

        $this->pages()->admin('configure', ConfigureController::class);
        $this->pages()->admin('links', LinksController::class);
        $this->pages()->front('menu', MenuPayloadController::class, false);
    }
}
