<?php

namespace Tests\Unit;

use App\Domain\Auth\PageCatalog;
use PHPUnit\Framework\TestCase;

class PageCatalogTest extends TestCase
{
    public function test_catalog_manage_unlocks_catalog_pages(): void
    {
        $expanded = PageCatalog::expand(['catalog.manage']);

        $this->assertContains('catalog.manage', $expanded);
        $this->assertContains('catalog', $expanded);
        $this->assertContains('catalog.categories', $expanded);
        $this->assertContains('catalog.inventory', $expanded);
        $this->assertTrue(PageCatalog::grants(['catalog.manage'], 'catalog.brands'));
        $this->assertTrue(PageCatalog::grants(['catalog.manage'], 'catalog.suppliers'));
        $this->assertTrue(PageCatalog::grants(['catalog.manage'], 'catalog.inventory'));
        $this->assertTrue(PageCatalog::grants(['catalog'], 'catalog.inventory'));
        $this->assertTrue(PageCatalog::grants(['cms.manage'], 'cms.blocks'));
        $this->assertTrue(PageCatalog::grants(['cms.manage'], 'cms.pages'));
        $this->assertTrue(PageCatalog::grants(['settings.manage'], 'settings.urls'));
        $this->assertTrue(PageCatalog::grants(['settings.manage'], 'settings.seo'));
        $this->assertTrue(PageCatalog::grants(['settings.manage'], 'settings.ai'));
        $this->assertTrue(PageCatalog::grants(['settings.manage'], 'settings.images'));
        $this->assertTrue(PageCatalog::grants(['settings.manage'], 'settings.performance'));
        $this->assertTrue(PageCatalog::grants(['settings.manage'], 'settings.advanced'));
        $this->assertTrue(PageCatalog::grants(['settings.manage'], 'localization.languages'));
        $this->assertTrue(PageCatalog::grants(['localization.translations'], 'localization.translations'));
        $this->assertTrue(PageCatalog::grants(['multivendor.manage'], 'multivendor.seller-requests'));
        $this->assertTrue(PageCatalog::grants(['multivendor.manage'], 'multivendor.products'));
        $this->assertTrue(PageCatalog::grants(['multivendor.manage'], 'multivendor.reviews'));
        $this->assertTrue(PageCatalog::grants(['multivendor.manage'], 'multivendor.settings'));
        $this->assertFalse(PageCatalog::grants(['catalog.manage'], 'customers.customers'));
    }

    public function test_specific_page_does_not_unlock_siblings(): void
    {
        $this->assertTrue(PageCatalog::grants(['catalog'], 'catalog'));
        $this->assertTrue(PageCatalog::grants(['multivendor.manage'], 'multivendor.seller-requests'));
        $this->assertTrue(PageCatalog::grants(['multivendor.manage'], 'multivendor.commissions'));
        $this->assertFalse(PageCatalog::grants(['catalog'], 'catalog.categories'));
        $this->assertFalse(PageCatalog::grants(['catalog'], 'catalog.manage'));
    }
}
