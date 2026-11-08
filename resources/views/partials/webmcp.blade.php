{{-- Browser WebMCP tool declarations (Shop REST parity + compare). Harmless without navigator.modelContext. --}}
<div id="wento-webmcp" class="hidden" hidden aria-hidden="true">
    <form action="{{ url('/webmcp/product') }}" method="GET" toolname="view_product" tooldescription="Open a product page by name, SKU, or URL key. Opens search results when no product matches.">
        <input type="text" name="query" required toolparamdescription="Product name, SKU, slug, or catalog path">
        <button type="submit" tabindex="-1">view_product</button>
    </form>
    <form action="{{ url('/webmcp/wishlist/add') }}" method="GET" toolname="add_to_wishlist" tooldescription="Add a product to the signed-in customer's wishlist and open the wishlist. Guests are sent to sign in.">
        <input type="text" name="query" required toolparamdescription="Product name, SKU, slug, or catalog path">
        <button type="submit" tabindex="-1">add_to_wishlist</button>
    </form>
    <form action="{{ url('/account/wishlist') }}" method="GET" toolname="view_wishlist" tooldescription="Open the customer wishlist page.">
        <button type="submit" tabindex="-1">view_wishlist</button>
    </form>
    <form action="{{ url('/cart') }}" method="GET" toolname="view_cart" tooldescription="Open the shopping cart.">
        <button type="submit" tabindex="-1">view_cart</button>
    </form>
    <form action="{{ url('/checkout') }}" method="GET" toolname="proceed_to_checkout" tooldescription="Open the checkout page. Does not place an order or take payment.">
        <button type="submit" tabindex="-1">proceed_to_checkout</button>
    </form>
    <form action="{{ url('/search') }}" method="GET" toolname="search_products" tooldescription="Search the catalog and open the results page.">
        <input type="text" name="q" required toolparamdescription="Search keywords">
        <button type="submit" tabindex="-1">search_products</button>
    </form>
    <form action="{{ url('/compare') }}" method="GET" toolname="view_compare_list" tooldescription="Open the product comparison page.">
        <button type="submit" tabindex="-1">view_compare_list</button>
    </form>
    <form action="{{ url('/webmcp/newsletter') }}" method="POST" toolname="subscribe_to_newsletter" tooldescription="Subscribe an email address to the store newsletter.">
        @csrf
        <input type="email" name="email" required toolparamdescription="Email address to subscribe">
        <button type="submit" tabindex="-1">subscribe_to_newsletter</button>
    </form>
</div>
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    try {
        if (typeof navigator === 'undefined' || !navigator.modelContext || typeof navigator.modelContext.provideContext !== 'function') {
            return;
        }
        var forms = document.querySelectorAll('form[toolname]');
        var tools = [];
        forms.forEach(function (form) {
            var name = form.getAttribute('toolname');
            if (!name) return;
            var description = form.getAttribute('tooldescription') || name;
            var properties = {};
            var required = [];
            form.querySelectorAll('input[name], textarea[name], select[name]').forEach(function (field) {
                if (field.name === '_token') return;
                var propDesc = field.getAttribute('toolparamdescription')
                    || field.getAttribute('aria-label')
                    || field.name;
                properties[field.name] = { type: 'string', description: propDesc };
                if (field.required) required.push(field.name);
            });
            tools.push({
                name: name,
                description: description,
                inputSchema: {
                    type: 'object',
                    properties: properties,
                    required: required,
                },
                execute: async function (args) {
                    args = args || {};
                    Object.keys(args).forEach(function (key) {
                        var el = form.querySelector('[name="' + key + '"]');
                        if (el) el.value = args[key];
                    });
                    form.submit();
                    return 'Executed "' + name + '".';
                },
            });
        });
        if (tools.length) {
            navigator.modelContext.provideContext({ tools: tools });
        }
    } catch (e) { /* ignore unsupported browsers */ }
})();
</script>
