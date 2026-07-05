# Promotion Engine — Shortcodes & Theme Integration

The plugin renders everything through **standard WooCommerce hooks**, so on a
standard theme it works with no setup. Some custom / headless / page-builder
storefronts don't fire all of WooCommerce's hooks. For those, place the
shortcodes below manually wherever you need the output.

All shortcodes are safe to leave in place on standard themes — each one
suppresses its auto-injected copy when used, so nothing is duplicated.

---

## `[promeng_savings]`

Shows the **"You saved on this purchase"** block: the total amount saved across
all active promotions, plus a small info icon that opens the list of the exact
promotions that produced the discount.

- Works for every promotion type (discount, Buy X Pay Y, Buy X Get Y).
- Renders nothing when the cart has no savings.

**Where to put it:** inside your cart / mini-cart / side-drawer template, near
the totals.

```
[promeng_savings]
```

Auto-injection (standard themes) uses, in order:
`woocommerce_cart_totals_before_order_total`, `woocommerce_before_cart`,
`woocommerce_before_cart_table`, `woocommerce_before_cart_totals`,
`woocommerce_proceed_to_checkout`, `woocommerce_after_cart_totals`,
and the mini-cart hooks `woocommerce_widget_shopping_cart_before_buttons`,
`woocommerce_widget_shopping_cart_total`, `woocommerce_after_mini_cart`.

---

## `[promeng_cart_messages]`

Shows the **encouragement nudges** (e.g. "Add 1 more to unlock the deal") for
automatic promotions the cart almost qualifies for.

- Cart page only by design (not checkout).
- Renders nothing when there are no relevant nudges.

```
[promeng_cart_messages]
```

Auto-injection uses `woocommerce_before_cart`, `woocommerce_before_cart_table`,
`woocommerce_before_cart_totals`, and `woocommerce_cart_totals_before_order_total`.

---

## Catalog product label

The promotion label banner on product images is injected on the standard
WooCommerce shop loop (`woocommerce_before_shop_loop_item` +
`woocommerce_product_get_image`) and on archive/search product grids. It is
intentionally **not** shown on cart, checkout or single-product thumbnails.

If your catalog grid is fully custom and renders product images without the
WooCommerce loop, expose the label in your template from:

```php
echo esc_html( apply_filters( 'promeng_product_label', '', $product_id ) );
```

(The plugin returns the promotion label for that product, or an empty string.)

---

## Important: scheduling vs. the status toggle

A promotion only applies when it is **both** toggled on **and** within its
active dates. If the end date has passed, the promotion stops applying and its
label disappears even though the status toggle still shows "active". The edit
screen summary and the Promotions dashboard both flag this state
("This promotion ended on …" / the **Ended** tab).

---

## App platform integration (optional)

The promotions plugin can limit a promotion to the **website**, the **app**, or
both. The platform controls (a selector on the promotion screen and a filter on
the dashboard) only appear when a companion *OC App Platform* plugin is active —
the app being a WebView wrapper that this companion plugin recognises by
user-agent. Without it, every promotion is simply a website promotion and no
platform choice is shown.

To activate the integration, the companion plugin only needs to signal its
presence and the current request's platform, in any of these ways:

```php
// 1) Presence — turns on the platform UI and channel enforcement.
define( 'OC_APP_PLATFORM', true );
// or:
add_filter( 'promeng_app_active', '__return_true' );

// 2) Current request platform — return 'app' when the shopper is in the app.
function oc_app_is_app_request() { /* user-agent check */ return $is_app; }
// or:
add_filter( 'promeng_current_channel', function () {
    return /* is app? */ 'app' : 'web';
} );
```

When active, a promotion stored with `channels = ['app']` is applied only on app
requests, `['web']` only on website requests, and `['web','app']` on both.
