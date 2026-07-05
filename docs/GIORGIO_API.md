# Promotion Engine ⇄ Giorgio — API Contract

This document describes how the **Giorgio backend** integrates with the
**Promotion Engine** WordPress/WooCommerce plugin running on a client store.

There are two independent directions:

| Direction | Purpose | Who calls whom |
|---|---|---|
| **Inbound** (Giorgio → plugin) | Push promotion **definitions** into the store | Giorgio calls the plugin's REST API |
| **Outbound** (plugin → Giorgio) | Report **redemptions** (and optionally serve definitions for pull) | The plugin calls Giorgio's API |

The plugin is the **runtime engine** (it computes and applies prices in the
store). Giorgio is the **authoring source** for its clients: a promotion
defined in Giorgio is pushed to the store, stored with `source = "giorgio"`
(read‑only in wp‑admin), and run by the engine. Redemptions flow back to
Giorgio for the unified dashboard.

All values the merchant needs are on the store's **Promotions → Giorgio Sync**
screen: the API base URL + API key (for outbound), and the Webhook URL +
Webhook secret (for inbound).

---

## 1. Inbound — Giorgio → plugin

Base namespace: `https://<store>/wp-json/promeng/v1`

### Auth
Every request must include the shared secret in a header:

```
X-Promeng-Secret: <webhook_secret>
```

The secret is shown (and regenerable) on the Giorgio Sync settings screen.
Requests without a valid secret get `401`.

### 1.1 Upsert promotions — `POST /promotions`

Create or update one or more promotions. Promotions are keyed by
`external_id` (your Giorgio promotion id). Sending the same `external_id`
again updates the existing record.

Body — a single object, a bare array, or `{ "promotions": [ ... ] }`:

```json
{
  "promotions": [
    {
      "external_id": "giorgio-1042",
      "name": "10% off poultry",
      "type": "discount",
      "active": true,
      "show_label": true,
      "requires_coupon": false,
      "coupon_code": null,
      "priority": 10,
      "starts_at": "2026-06-01 00:00:00",
      "ends_at": null,
      "weekdays": [0,1,2,3,4,5,6],
      "limit_per_order": null,
      "limit_per_customer": null,
      "channels": ["web"],
      "config": { "...type-specific..." }
    }
  ]
}
```

#### Common fields

| Field | Type | Notes |
|---|---|---|
| `external_id` | string | **Required.** Your Giorgio promotion id. Stable key for upsert/delete. |
| `name` | string | Display name (shown on labels/cart). |
| `type` | string | One of `discount`, `buy_x_pay_y`, `buy_x_get_y`. |
| `active` | bool | Whether the promotion runs. |
| `show_label` | bool | Show the catalog/cart label. Default `true`. |
| `requires_coupon` | bool | If `true`, applies only when the coupon code is entered at the cart/checkout. |
| `coupon_code` | string\|null | Coupon code that unlocks the promotion. Works for **all** types: `discount` maps to a native WooCommerce coupon; `buy_x_pay_y` / `buy_x_get_y` use a zero-amount "unlock token" coupon and the engine applies the real, cart-aware discount once the code is present. |
| `priority` | int | Lower runs first when several apply. Default `10`. |
| `starts_at` / `ends_at` | datetime\|null | `YYYY-MM-DD HH:MM:SS` (UTC). `null` = open-ended. |
| `weekdays` | int[] | Days the promo is active: `0`=Sunday … `6`=Saturday. Empty/all‑seven = every day. |
| `limit_per_order` | int\|null | Caps repetitions / benefit units per order. `null` = no cap. |
| `limit_per_customer` | int\|null | Max redemptions per customer. `null` = no cap. |
| `channels` | string[] | Platforms the promotion runs on, e.g. `["web"]`, `["app"]`, or `["web","app"]`. The store enforces `web`/`app` only when the OC App Platform plugin is installed (otherwise everything is `web`). Giorgio may also send `kiosk` / `pos` for its own channels. |

Product and category ids in `config` are **WooCommerce** product/category
ids on the target store (see §3 on id mapping).

#### `config` by type

**`discount`** — percentage or fixed amount off.
```json
{
  "applies_to": "all" | "products" | "categories" | "cart",
  "discount_type": "percent" | "fixed",
  "discount_value": 10,
  "product_ids": [55, 56],
  "category_ids": [12],
  "excluded_product_ids": [],
  "condition_min_items": 0,
  "condition_min_amount": 0,
  "limit_discounted_items": 0
}
```
- `applies_to` — what the discount targets. With `products`/`categories`,
  fill the matching id array. `cart` discounts the whole order.
- `condition_min_items` / `condition_min_amount` — optional thresholds the
  cart must meet for the discount to apply (`0` = no condition).
- `limit_discounted_items` — for percentage discounts, cap how many units get
  the discount (`0` = unlimited). Ignored for fixed amounts.

**`buy_x_pay_y`** — buy a quantity, pay a fixed total for the bundle.
```json
{
  "scope": "product" | "category",
  "product_ids": [55],
  "category_ids": [],
  "excluded_product_ids": [],
  "buy_quantity": 2,
  "pay_amount": 200
}
```
`buy_quantity` may be fractional for weight-sold goods (e.g. `2` = 2 kg).

**`buy_x_get_y`** — buy a condition, get a benefit (supports "Buy 4, Get 2").
```json
{
  "buy_applies_to": "all" | "products" | "categories",
  "buy_product_ids": [55],
  "buy_category_ids": [7],
  "buy_excluded_product_ids": [],
  "buy_min_items": 4,
  "buy_min_amount": 0,

  "benefit_quantity": 2,
  "benefit_applies_to": "products" | "categories" | "same" | "all",
  "benefit_product_ids": [99],
  "benefit_category_ids": [],
  "benefit_excluded_product_ids": [],
  "benefit_type": "free" | "percent" | "fixed",
  "benefit_value": 1,
  "auto_add": true,
  "apply_to_cheapest": false
}
```
*Buy side*
- `buy_min_items` — **X**: how many qualifying products earn one fulfillment
  (default `1`). The deal repeats — twice as many earns two fulfillments.
- `buy_min_amount` — optional minimum cart amount required (`0` = none).

*Get side*
- `benefit_quantity` — **how many units are given per fulfillment** (default
  `1`). `buy_min_items: 4` + `benefit_quantity: 2` = "Buy 4, Get 2". For a
  *shared* benefit pool (`same` / `all` / `categories`) each fulfillment
  consumes a whole group of `buy_min_items` paid **+** `benefit_quantity` free
  items from the cart — so "Buy 2 Get 2" over 4 eligible items frees 2, not 4.
  For a distinct benefit product (`products`) the free units are separate from
  the purchased ones.
- `benefit_applies_to` — what receives the benefit: `products` (specific
  `benefit_product_ids`), `categories` (`benefit_category_ids`), `same` (the
  same products/categories the customer must buy — for 5+1 style deals on the
  same items, no need to re-select them), or `all`.
- `benefit_excluded_product_ids` — products to exclude (for `categories`/`all`).
- `benefit_type`: `free` = benefit units are free, `percent` = `benefit_value`%
  off, `fixed` = the unit costs `benefit_value` (e.g. `1` → ₪1).
- `auto_add` — only when `benefit_applies_to: "products"` **and**
  `benefit_type: "free"`. When `true` (default) the free product(s) are added to
  the cart automatically once the customer qualifies; otherwise the discount
  applies only if the customer adds them.
- With `limit_per_order` empty the benefit applies to **all** earned units; set
  it to cap the number of benefit units per order.
- `apply_to_cheapest` — how the earned free/discounted units are allocated when
  the eligible items have different prices. Default `false` spreads the benefit
  across price tiers as required by Israeli consumer law: the free units are
  distributed so a cart of 4 items with 2 free frees one dearer and one cheaper
  item (not the two cheapest). Set `true` to discount only the cheapest units.

#### Response
```json
{
  "ok": true,
  "upserted": [ { "external_id": "giorgio-1042", "id": 7 } ],
  "errors": []
}
```
`207` is returned if some items in a batch failed (each listed under
`errors` with a message); valid items are still saved.

### 1.2 Delete a promotion — `DELETE /promotions/{external_id}`
Removes the Giorgio promotion with that id. `200` if removed, `404` if it
wasn't found. (Deactivating is usually better — send an upsert with
`"active": false`.)

### 1.3 List Giorgio promotions — `GET /promotions`
Returns the Giorgio-sourced promotions currently in the store, for
verification:
```json
{ "promotions": [ { "external_id": "giorgio-1042", "name": "...", "type": "discount", "active": true } ] }
```

---

## 2. Outbound — plugin → Giorgio

The plugin calls **your** Giorgio API. Base URL and API key are entered by
the merchant on the Giorgio Sync screen.

### Auth (sent by the plugin)
```
Authorization: Bearer <api_key>
Content-Type: application/json
```

### 2.1 Redemption report — `POST {base_url}/redemptions`
Sent on a schedule (≈1 minute after each order, with an hourly safety sweep)
and batched up to 100 rows. Implement this endpoint on the Giorgio side.

```json
{
  "redemptions": [
    {
      "promotion_external_id": "giorgio-1042",
      "promotion_name": "10% off poultry",
      "order_id": 8842,
      "customer_id": 31,
      "customer_email": "buyer@example.com",
      "channel": "web",
      "discount_amount": 14.90,
      "redeemed_at": "2026-06-11 13:05:22"
    }
  ]
}
```
- `promotion_external_id` is `null` for store-local promotions (ones not
  created in Giorgio) — keep or ignore them as you prefer.
- Respond with any `2xx` on success; the plugin then marks those rows
  reported and won't resend them. A non‑2xx leaves them pending for retry.

### 2.2 Definition pull (optional) — `GET {base_url}/promotions`
Used only for an initial/recovery sync if triggered. Return the same object
shape described in §1.1 under a `promotions` array. Push (§1.1) is the normal
path; you don't need this to get started.

---

## 3. Behaviour notes (important for the dashboard)

- **Scheduling vs. active.** A promotion runs only when it is **both**
  `active: true` **and** within its `starts_at`/`ends_at` window (and on an
  allowed weekday). An expired `ends_at` stops the promotion even if
  `active` is still `true`. To retire a promotion from Giorgio, send an upsert
  with `active: false` or an `ends_at` in the past; to schedule one, send a
  future `starts_at`.
- **Influencer assignment is store-side.** When the optional *OC Influencer
  Dashboard* plugin is installed, the merchant can tie a promotion's coupon
  code to an influencer (a WordPress user id) on the store. That mapping is not
  part of the Giorgio push (Giorgio doesn't know the store's WP user ids) and
  is managed locally. Redemptions still report normally via §2.1.
- **Local vs. Giorgio promotions.** Promotions created in wp-admin have
  `source = "local"`; ones pushed here have `source = "giorgio"` and are
  read-only in wp-admin. Both run identically and both report redemptions
  (local ones carry `promotion_external_id: null`).

---

## 4. Product / category id mapping

`config` ids are **WooCommerce** ids on the target store. Since Giorgio and
the store already share a catalog through the existing product sync, Giorgio
should translate its internal product/SKU references to the store's
WooCommerce ids before pushing. If it's easier to send SKUs, tell us and we'll
add a SKU→id resolve step in the plugin's mapper.

---

## 5. Quick test (curl)

Push a promotion:
```bash
curl -X POST "https://<store>/wp-json/promeng/v1/promotions" \
  -H "X-Promeng-Secret: <webhook_secret>" \
  -H "Content-Type: application/json" \
  -d '{"external_id":"giorgio-test","name":"Test 10%","type":"discount","active":true,
       "config":{"applies_to":"all","discount_type":"percent","discount_value":10}}'
```

Delete it:
```bash
curl -X DELETE "https://<store>/wp-json/promeng/v1/promotions/giorgio-test" \
  -H "X-Promeng-Secret: <webhook_secret>"
```
