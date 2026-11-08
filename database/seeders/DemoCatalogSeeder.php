<?php

namespace Database\Seeders;

use App\Domain\Installer\DemoCatalogInstaller;
use Illuminate\Database\Seeder;

class DemoCatalogSeeder extends Seeder
{
    public const TARGET_PRODUCTS = DemoCatalogInstaller::TARGET_PRODUCTS;

    public const TARGET_CUSTOMERS = DemoCatalogInstaller::TARGET_CUSTOMERS;

    public const TARGET_ORDERS = DemoCatalogInstaller::TARGET_ORDERS;

    public function run(): void
    {
        app(DemoCatalogInstaller::class)->run();
    }

    public function refreshStorefrontMedia(): void
    {
        app(DemoCatalogInstaller::class)->refreshStorefrontMedia();
    }
}
