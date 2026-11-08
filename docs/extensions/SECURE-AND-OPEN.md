# Secure and open extensions

WentoCart is **free for the community**. Merchants download the shop, try it, then install extensions for specific needs.

**Secure** means every extension path is designed so a third-party zip cannot hack or silently own the shop.  
**Open** means extensions can still do real work: UI, their own data CRUD, react to events, and (with declared capabilities) create catalog data through **core APIs**.

These two words always travel together. Open never means “any PHP on the server.”

---

## Merchant promise

1. Upload a zip from **Design → Extensions**.
2. Review the **capability list** (what this extension may do).
3. Enable only if they accept those powers.
4. Core scans the zip (structure + Safe security rules).
5. Extension code only reaches **published** APIs.

If an extension needs a power it did not declare → install or boot **fails**. That is security, not inconvenience.

---

## Developer promise

You build openly **inside** the platform:

| You need | You use | Not allowed |
| --- | --- | --- |
| Button on PDP / column on Product list | slots / `list.extend` | Edit core React |
| Store rules, enquiries, mappings | `settings()` (full CRUD) | `DB::`, Eloquent on core tables |
| Clean up when product deleted | `actions()->after(...)` | Bypass hooks |
| Create a product that appears in Catalog | `catalog()->createProduct(...)` + `catalog.write` | Insert into `products` yourself |
| Assign a customer to a group (Prime, VIP, …) | `customers()->assignGroup(...)` + `customers.write` | Update `customers` yourself |
| Send activation / custom email | `mail()->sendTemplate(...)` + `mail.send` | Raw `Mail::` / SMTP |
| Take payment / call remote APIs | Certified capabilities | Unsigned “certified” zip |

Use `settings()` for the extension’s own data. Use published write helpers for catalog, customers, and mail.

---

## Safe vs certified

| | **Safe** (community default) | **Certified** (later review) |
| --- | --- | --- |
| Install | After scan | Marketplace / signed |
| UI | Menus, pages, slots, list columns | Same |
| Own data | Settings (+ later prefixed tables) | `schema.tables` |
| Core reads | `catalog.read`, `customers.read` | + `orders.read` |
| Core writes | Allowlisted helpers such as `catalog()->createProduct(...)` | Payments, shipping, outbound HTTP, full order dump |
| Blocked | `eval`, shell, `Illuminate\`, `DB::`, `$_GET` | Same, plus review |

**Admin always sees the capability labels before Enable.** Choice is informed; the platform still refuses undeclared or unsafe power.

---

## Security in every piece of work

When we add a new extension feature we always ask:

1. Is there a **published** surface (slot, list, action, write API)?
2. Is there a **capability** the merchant can see?
3. Does the call go through **core validation** (not raw SQL)?
4. Does the Safe scanner still hold?

If any answer is no, we do not ship that door.

---

## Related

- [EXTENSION-POINTS.md](EXTENSION-POINTS.md) — published ids
- [CODING-STANDARD.md](CODING-STANDARD.md) — BasePlugin helpers
- [STRUCTURE.md](STRUCTURE.md) — zip layout
