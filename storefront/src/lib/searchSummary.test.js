import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import {
    buildSearchSummary,
    hasSearchQuery,
    searchRedirectPath,
    searchSummaryCountLabel,
} from './searchSummary.js';

const countLabels = {
    oneLabel: 'There is {count} product',
    manyLabel: 'There are {count} products',
    leadLabel: 'Find products by name, brand, or keyword.',
};

describe('searchRedirectPath', () => {
    it('allows internal catalog paths', () => {
        assert.equal(searchRedirectPath('/catalog/shoes'), '/catalog/shoes');
        assert.equal(searchRedirectPath('/c/audio?ref=1'), '/c/audio?ref=1');
    });

    it('rejects external, empty, and self-search targets', () => {
        assert.equal(searchRedirectPath('https://evil.example'), '');
        assert.equal(searchRedirectPath('//evil.example'), '');
        assert.equal(searchRedirectPath('\\windows\\path'), '');
        assert.equal(searchRedirectPath('/search'), '');
        assert.equal(searchRedirectPath('/search?q=x'), '');
        assert.equal(searchRedirectPath(''), '');
    });
});

describe('hasSearchQuery', () => {
    it('treats blank and whitespace as empty', () => {
        assert.equal(hasSearchQuery(''), false);
        assert.equal(hasSearchQuery('   '), false);
        assert.equal(hasSearchQuery('mer'), true);
    });
});

describe('searchSummaryCountLabel', () => {
    it('uses singular copy for exactly one product', () => {
        assert.equal(
            searchSummaryCountLabel({ total: 1, ...countLabels }),
            'There is 1 product',
        );
    });

    it('uses plural copy for zero and multiple products', () => {
        assert.equal(
            searchSummaryCountLabel({ total: 0, ...countLabels }),
            'There are 0 products',
        );
        assert.equal(
            searchSummaryCountLabel({ total: 33, ...countLabels }),
            'There are 33 products',
        );
    });

    it('falls back to the lead copy when total is missing', () => {
        assert.equal(
            searchSummaryCountLabel({ total: null, ...countLabels }),
            countLabels.leadLabel,
        );
    });
});

describe('buildSearchSummary', () => {
    const labels = {
        results: 'Search Results',
        title: 'Search',
        one: 'There is {count} product',
        many: 'There are {count} products',
        lead: 'Find products by name, brand, or keyword.',
    };

    it('uses Search Results with a highlighted query and plural count', () => {
        const summary = buildSearchSummary({ query: 'mer', total: 33, labels });
        assert.equal(summary.hasQuery, true);
        assert.equal(summary.query, 'mer');
        assert.equal(summary.title, 'Search Results');
        assert.equal(summary.countLabel, 'There are 33 products');
    });

    it('handles empty queries, one result, and zero results', () => {
        const empty = buildSearchSummary({ query: '', total: null, labels });
        assert.equal(empty.hasQuery, false);
        assert.equal(empty.query, '');
        assert.equal(empty.title, 'Search');
        assert.equal(empty.countLabel, labels.lead);

        const one = buildSearchSummary({ query: 'unique', total: 1, labels });
        assert.equal(one.title, 'Search Results');
        assert.equal(one.countLabel, 'There is 1 product');

        const zero = buildSearchSummary({ query: 'zzz', total: 0, labels });
        assert.equal(zero.hasQuery, true);
        assert.equal(zero.countLabel, 'There are 0 products');
    });

    it('trims query whitespace for display', () => {
        const summary = buildSearchSummary({ query: '  earbuds  ', total: 2, labels });
        assert.equal(summary.query, 'earbuds');
        assert.equal(summary.hasQuery, true);
        assert.equal(summary.countLabel, 'There are 2 products');
    });
});
