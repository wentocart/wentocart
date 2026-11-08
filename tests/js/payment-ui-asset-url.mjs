import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { webPath } from '../../storefront/src/webPath.js';

const source = readFileSync(new URL('../../storefront/src/lib/paymentUiRegistry.js', import.meta.url), 'utf8');
assert.match(source, /return webPath\(`\/plugins\/\$\{vendor\}\/\$\{name\}\/\$\{file\}`\)/);

function assetUrl(vendor, name, file) {
    return webPath(`/plugins/${vendor}/${name}/${file}`);
}

function boot(basePath, assetBase = basePath) {
    globalThis.window = {
        __WENTO__: {
            base_path: basePath,
            asset_base: assetBase,
        },
        location: { origin: 'http://localhost' },
    };
}

boot('/shop');
assert.equal(
    assetUrl('acme', 'payments', 'assets/checkout.js'),
    '/shop/plugins/acme/payments/assets/checkout.js',
);

boot('');
assert.equal(
    assetUrl('acme', 'payments', 'assets/checkout.js'),
    '/plugins/acme/payments/assets/checkout.js',
);

boot('/store/virtual', '/store');
assert.equal(
    assetUrl('acme', 'payments', 'assets/checkout.js'),
    '/store/plugins/acme/payments/assets/checkout.js',
);

console.log('payment-ui-asset-url: ok');
