# Sample kit

Reference Safe WentoCart app (`wento/sample-kit`).

It shows the community contract in one zip:

- Hosted **Customer Information** list (Catalog checkboxes + Bulk actions; rows live in this app’s settings)
- Product list column **Supplier** via an allowlisted join
- Customer list column **Notes**
- View slots on product, order, and customer admin screens
- Storefront slots on header, footer, home, product, category
- Account menu items: **Account board** (`/m/wento/sample-kit/board`) and **Store credit list** (`/m/wento/sample-kit/credits`) — Classic hosted DataTable (copy-paste: [docs/extensions/examples/storefront-account-list.md](../../../../../docs/extensions/examples/storefront-account-list.md))
- After-delete action that increments a setting

Pack:

```bash
php artisan wento:app-pack wento/sample-kit
```

Enable from **Design → Apps**. Full contract: [docs/APPS.md](../../../docs/APPS.md)
