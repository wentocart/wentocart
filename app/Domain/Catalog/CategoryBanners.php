<?php

namespace App\Domain\Catalog;

/**
 * Category hero / thumb images — prefers offline demo pack, then remote Unsplash.
 */
final class CategoryBanners
{
    /**
     * Wide lifestyle photo for category page heroes.
     */
    public static function urlFor(?string $slug): string
    {
        return DemoMediaPack::categoryUrl((string) $slug, 1400, 700);
    }

    /**
     * Square / pill thumbnail for subcategory chips.
     */
    public static function thumbUrlFor(?string $slug): string
    {
        return DemoMediaPack::categoryThumbUrl((string) $slug);
    }

    /** Remote fallback when the local demo pack file is missing. */
    public static function remoteUrlFor(?string $slug, int $w = 1400, int $h = 700): string
    {
        $slug = strtolower(trim((string) $slug));
        $id = DemoMediaPack::unsplashId('cat-'.$slug)
            ?: DemoMediaPack::unsplashId('cat-catalog')
            ?: '1441986300917-64674bd600d8';

        return DemoMediaPack::unsplashDownloadUrl($id, $w, $h);
    }
}
