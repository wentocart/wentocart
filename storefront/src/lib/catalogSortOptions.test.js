import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

globalThis.window = globalThis.window || { __WENTO__: { i18n: { messages: {} } } };

const { CATALOG_SORT_OPTIONS, catalogSortOptions, defaultCatalogSort } = await import('./commerce.js');

describe('catalogSortOptions', () => {
    it('exposes the full shared option set without duplicates', () => {
        const values = CATALOG_SORT_OPTIONS.map((row) => row.value);
        assert.deepEqual(values, [
            'relevance',
            'popularity',
            'newest',
            'price_asc',
            'price_desc',
            'rating',
            'discount',
        ]);
        assert.equal(values.length, new Set(values).size);
    });

    it('prefers shop bootstrap options and hides price sorts when needed', () => {
        const shop = {
            commerce: {
                search: {
                    default_sort: 'discount',
                    sort_options: [
                        { value: 'relevance', label: 'Relevance' },
                        { value: 'price_asc', label: 'Price: Low to High' },
                        { value: 'discount', label: 'Biggest Discount' },
                        { value: 'discount', label: 'Duplicate' },
                    ],
                },
            },
        };

        assert.deepEqual(
            catalogSortOptions(shop).map((row) => row.value),
            ['relevance', 'price_asc', 'discount'],
        );
        assert.deepEqual(
            catalogSortOptions(shop, { hidePrices: true }).map((row) => row.value),
            ['relevance', 'discount'],
        );
        assert.equal(defaultCatalogSort(shop), 'discount');
        assert.equal(defaultCatalogSort({ commerce: { search: { default_sort: 'nope' } } }), 'popularity');
    });
});
