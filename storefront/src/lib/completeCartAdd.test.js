import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

globalThis.window = globalThis.window || { __WENTO__: { i18n: { messages: {} } } };

const { completeCartAdd, cartAddWasBlocked, maxOrderQtyOnGrid, quantityCartLine } = await import('./commerce.js');

function harness() {
    const pings = [];
    let opened = 0;
    return {
        pings,
        get opened() {
            return opened;
        },
        ping: (message, type = 'success') => {
            pings.push({ message, type });
        },
        openCart: () => {
            opened += 1;
        },
    };
}

describe('completeCartAdd', () => {
    it('opens the mini-cart on a clean success without toasts', () => {
        const h = harness();
        const result = completeCartAdd({
            ping: h.ping,
            openCart: h.openCart,
            data: {},
            open: true,
        });

        assert.equal(h.opened, 1);
        assert.deepEqual(h.pings, []);
        assert.equal(result.opened, true);
        assert.deepEqual(result.notices, []);
    });

    it('keeps the mini-cart closed and toasts when quantity cannot increase', () => {
        const h = harness();
        const result = completeCartAdd({
            ping: h.ping,
            openCart: h.openCart,
            data: {
                quantity_notices: [{
                    code: 'no_increase',
                    min: 1,
                    step: 4,
                    max: 4,
                    before: 1,
                    wanted: 2,
                    after: 1,
                }],
            },
        });

        assert.equal(h.opened, 0);
        assert.equal(result.opened, false);
        assert.equal(true, cartAddWasBlocked({
            quantity_notices: [{ code: 'no_increase', before: 1, after: 1 }],
        }));
        assert.equal(h.pings.length, 1);
        assert.equal(h.pings[0].type, 'warning');
        assert.match(h.pings[0].message, /No more can be added/i);
    });

    it('opens the mini-cart for step snaps without a warning growl', () => {
        const h = harness();
        const result = completeCartAdd({
            ping: h.ping,
            openCart: h.openCart,
            data: {
                quantity_notices: [{
                    code: 'step',
                    min: 1,
                    step: 2,
                    max: null,
                    before: 25,
                    wanted: 38,
                    after: 27,
                }],
            },
        });

        assert.equal(h.opened, 1);
        assert.equal(result.opened, true);
        assert.equal(result.blocked, false);
        assert.equal(false, cartAddWasBlocked({
            quantity_notices: [{ code: 'step', before: 25, after: 27 }],
        }));
        assert.deepEqual(h.pings, []);
    });

    it('toasts success when the drawer stays closed on a clean add', () => {
        const h = harness();
        completeCartAdd({
            ping: h.ping,
            openCart: h.openCart,
            data: {},
            open: false,
        });

        assert.equal(h.opened, 0);
        assert.equal(h.pings.length, 1);
        assert.equal(h.pings[0].type, 'success');
        assert.match(h.pings[0].message, /Added to cart/i);
    });

    it('honors notify:false even when warnings are present', () => {
        const h = harness();
        completeCartAdd({
            ping: h.ping,
            openCart: h.openCart,
            data: {
                quantity_notices: [{
                    code: 'at_max',
                    min: 1,
                    step: 1,
                    max: 2,
                    before: 2,
                    wanted: 3,
                    after: 2,
                }],
            },
            notify: false,
        });

        assert.equal(h.opened, 0);
        assert.deepEqual(h.pings, []);
    });
});

describe('quantityCartLine with step grid', () => {
    it('treats min 1 / step 4 / max 4 with qty 1 as already at the limit', () => {
        assert.equal(maxOrderQtyOnGrid(1, 4, 4), 1);
        assert.match(
            quantityCartLine(1, 4, 1, 4),
            /already in your cart, which is the maximum/i,
        );
    });

    it('reports room to the next valid grid quantity', () => {
        assert.equal(maxOrderQtyOnGrid(1, 2, 5), 5);
        assert.match(quantityCartLine(1, 5, 1, 2), /add 4 more/i);
    });
});
