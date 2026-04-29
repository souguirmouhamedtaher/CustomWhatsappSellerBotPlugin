# Database Schema Reference

Every table and column used in the plugin's raw SQL queries.
Meta key/value pairs are listed explicitly — no guesses, no omissions.

Source files analysed:
- `includes/repositories/wallet/class-cwsb-wallet-queries.php`
- `includes/repositories/order/class-cwsb-order-queries.php`
- `includes/repositories/seller/class-cwsb-seller-vendor-queries.php`
- `includes/repositories/seller/class-cwsb-seller-state-queries.php`
- `includes/repositories/product/class-cwsb-product-queries.php`
- `includes/repositories/update-product/class-cwsb-update-product-queries.php`
- `includes/repositories/seller/class-cwsb-seller-state-writer.php`
- `includes/repositories/update-product/class-cwsb-update-product-writer.php`

---

## Tables Overview

| # | Table (with `{prefix}`) | Origin |
|---|-------------------------|--------|
| 1 | `{prefix}posts` | WordPress core |
| 2 | `{prefix}postmeta` | WordPress core |
| 3 | `{prefix}users` | WordPress core |
| 4 | `{prefix}usermeta` | WordPress core |
| 5 | `{prefix}term_relationships` | WordPress core |
| 6 | `{prefix}term_taxonomy` | WordPress core |
| 7 | `{prefix}terms` | WordPress core |
| 8 | `{prefix}woocommerce_order_items` | WooCommerce |
| 9 | `{prefix}woocommerce_order_itemmeta` | WooCommerce |
| 10 | `{prefix}wcfm_marketplace_orders` | WCFM Marketplace |
| 11 | `{prefix}cwsb_seller_state` | This plugin (custom table) |

---

## 1. `{prefix}posts`

**Columns referenced in SQL:**

| Column | Used as / filter value |
|--------|------------------------|
| `ID` | SELECT, JOIN ON, WHERE |
| `post_type` | WHERE — see values below |
| `post_status` | SELECT, WHERE — see values below |
| `post_date` | SELECT, ORDER BY |
| `post_date_gmt` | ORDER BY |
| `post_excerpt` | SELECT (order customer note / product short description) |
| `post_title` | SELECT (product name) |
| `post_content` | SELECT (product full description) |
| `post_author` | SELECT, WHERE (seller user ID) |
| `post_parent` | WHERE `= product_id` (variation parent lookup) |
| `menu_order` | ORDER BY (product variations) |

**`post_type` values used in WHERE:**

| Value | Context |
|-------|---------|
| `'shop_order'` | Order queries |
| `'product'` | Product queries |
| `'product_variation'` | Variation existence check |

**`post_status` values used in WHERE / IN / NOT IN:**

| Value | Context |
|-------|---------|
| `'wc-completed'` | Wallet queries — completed orders only |
| `'wc-shipped'` | Order status filter — in_delivery |
| `'wc-in-delivery'` | Order status filter — in_delivery |
| `'publish'` | Active products |
| `'private'` | Active products |
| `'draft'` | Editable products |
| `'pending'` | Editable products |
| `'trash'` | Excluded from all order queries (NOT IN) |
| `'auto-draft'` | Excluded from all order queries (NOT IN) |

---

## 2. `{prefix}postmeta`

**Columns referenced in SQL:**

| Column | Used as |
|--------|---------|
| `post_id` | JOIN ON, WHERE |
| `meta_key` | WHERE, CASE WHEN, LIKE pattern |
| `meta_value` | SELECT (via CASE WHEN / COALESCE) |

### meta_key values — Orders (`shop_order`)

| meta_key | Description |
|----------|-------------|
| `_order_number` | Human-readable order reference |
| `_customer_user` | WordPress user ID of the customer |
| `_order_total` | Grand total of the order |
| `_order_currency` | Currency code (e.g. `TND`, `EUR`) |
| `_order_shipping` | Shipping cost |
| `_shipping_total` | Shipping total (WooCommerce native) |
| `_order_tax` | Tax amount |
| `_cart_discount` | Cart-level discount |
| `_payment_method_title` | Payment method label |
| `_transaction_id` | External payment transaction ID |
| `_customer_note` | Note left by the customer |
| `_billing_first_name` | Billing address |
| `_billing_last_name` | Billing address |
| `_billing_address_1` | Billing address line 1 |
| `_billing_address_2` | Billing address line 2 |
| `_billing_city` | Billing city |
| `_billing_state` | Billing state/region |
| `_billing_postcode` | Billing postcode |
| `_billing_country` | Billing country |
| `_shipping_first_name` | Shipping address |
| `_shipping_last_name` | Shipping address |
| `_shipping_address_1` | Shipping address line 1 |
| `_shipping_address_2` | Shipping address line 2 |
| `_shipping_city` | Shipping city |
| `_shipping_state` | Shipping state/region |
| `_shipping_postcode` | Shipping postcode |
| `_shipping_country` | Shipping country |

### meta_key values — Products (`product` / `product_variation`)

| meta_key | Description |
|----------|-------------|
| `_sku` | Product SKU |
| `_price` | Active/effective price (EUR) |
| `_regular_price` | Regular price (EUR) |
| `_sale_price` | Sale price (EUR) |
| `_regular_price_tnd` | Regular price (TND, raw) |
| `_sale_price_tnd` | Sale price (TND, raw) |
| `_regular_price_wmcp` | Regular price (TND, WMCP JSON format) |
| `_sale_price_wmcp` | Sale price (TND, WMCP JSON format) |
| `_price_tnd` | Active/effective price (TND) |
| `_stock` | Stock quantity |
| `_manage_stock` | Stock management flag (`yes` / `no`) |
| `_stock_status` | Stock status (`instock` / `outofstock`) |
| `_thumbnail_id` | Attachment ID of the cover image |
| `_product_image_gallery` | Comma-separated attachment IDs for gallery images |
| `_product_attributes` | Serialized product attributes array |
| `_length` | Product length (shipping dimension) |
| `_width` | Product width (shipping dimension) |
| `_height` | Product height (shipping dimension) |
| `_weight` | Product weight |
| `_cwsb_dim_unit` | Dimension unit (e.g. `cm`) — plugin custom |
| `_cwsb_weight_unit` | Weight unit (e.g. `kg`) — plugin custom |
| `_cwsb_color` | Color attribute value — plugin custom |
| `_cwsb_size` | Size attribute value — plugin custom |
| `_cwsb_category_label` | Display label for the assigned category — plugin custom |
| `_cwsb_subcategory_label` | Display label for the assigned subcategory — plugin custom |
| `attribute_%` | Variation attribute values (queried via `LIKE 'attribute_%'`) |

---

## 3. `{prefix}users`

**Columns referenced in SQL:**

| Column | Used as |
|--------|---------|
| `ID` | SELECT (`AS user_id`), JOIN ON, WHERE |
| `display_name` | SELECT (`AS name`) |
| `user_email` | SELECT (`AS email`), WHERE (email lookup) |

---

## 4. `{prefix}usermeta`

**Columns referenced in SQL:**

| Column | Used as |
|--------|---------|
| `user_id` | JOIN ON, WHERE |
| `meta_key` | WHERE, JOIN ON |
| `meta_value` | SELECT (via COALESCE), WHERE LIKE |

### meta_key values

| meta_key | Description |
|----------|-------------|
| `{prefix}capabilities` | WordPress capabilities serialized blob. Joined/filtered with `LIKE '%"wcfm_vendor"%'` to identify WCFM seller accounts. The actual key name is dynamic: `$wpdb->prefix . 'capabilities'` (e.g. `wp_capabilities`). |
| `billing_phone` | Phone number stored as WooCommerce billing field |
| `phone` | Generic phone number meta |
| `wcfm_phone` | Phone number stored by WCFM Marketplace |

---

## 5. `{prefix}term_relationships`

**Columns referenced in SQL:**

| Column | Used as |
|--------|---------|
| `object_id` | WHERE (product ID), JOIN ON |
| `term_taxonomy_id` | JOIN ON |

---

## 6. `{prefix}term_taxonomy`

**Columns referenced in SQL:**

| Column | Used as |
|--------|---------|
| `term_taxonomy_id` | JOIN ON |
| `term_id` | JOIN ON |
| `taxonomy` | WHERE `= 'product_cat'` (also used parametrically in term name lookups) |
| `parent` | SELECT — `parent = 0` identifies top-level category; `parent > 0` identifies subcategory |

---

## 7. `{prefix}terms`

**Columns referenced in SQL:**

| Column | Used as |
|--------|---------|
| `term_id` | JOIN ON, SELECT |
| `name` | SELECT, ORDER BY |
| `slug` | SELECT |

---

## 8. `{prefix}woocommerce_order_items`

**Columns referenced in SQL:**

| Column | Used as |
|--------|---------|
| `order_item_id` | SELECT, JOIN ON, GROUP BY, ORDER BY |
| `order_item_name` | SELECT (product name captured at time of order) |
| `order_id` | WHERE (filter by order) |
| `order_item_type` | WHERE `= 'line_item'` |

---

## 9. `{prefix}woocommerce_order_itemmeta`

**Columns referenced in SQL:**

| Column | Used as |
|--------|---------|
| `order_item_id` | JOIN ON |
| `meta_key` | CASE WHEN |
| `meta_value` | SELECT (via CASE WHEN) |

### meta_key values

| meta_key | Description |
|----------|-------------|
| `_product_id` | WooCommerce product ID for the line item |
| `_variation_id` | Variation ID (`0` or empty if not a variation) |
| `_qty` | Quantity ordered |
| `_line_total` | Line total after discount |
| `_line_subtotal` | Line subtotal before discount |

---

## 10. `{prefix}wcfm_marketplace_orders`

**Columns referenced in SQL:**

| Column | Used as |
|--------|---------|
| `order_id` | JOIN ON — maps to `{prefix}posts.ID` |
| `vendor_id` | WHERE — maps to seller's WordPress `users.ID` |

---

## 11. `{prefix}cwsb_seller_state` (plugin custom table)

**Columns referenced in SQL:**

| Column | Type | Used as |
|--------|------|---------|
| `user_id` | INT — PK / FK → `wp_users.ID` | SELECT, WHERE, INSERT key, UPDATE key |
| `name` | VARCHAR | SELECT, INSERT, UPDATE |
| `email` | VARCHAR | SELECT, INSERT, UPDATE |
| `phone` | VARCHAR | SELECT, WHERE (phone lookup), INSERT, UPDATE |
| `code` | VARCHAR | SELECT (PIN code), INSERT, UPDATE |
| `flow_token` | VARCHAR | SELECT, WHERE (token lookup), INSERT, UPDATE |
| `reset_token` | VARCHAR | SELECT, INSERT, UPDATE |
| `reset_token_expiry` | BIGINT (ms epoch) | SELECT, INSERT, UPDATE |
| `session_active_until` | BIGINT (ms epoch) | SELECT, WHERE (`> now_ms`), INSERT, UPDATE |
| `auth_portal_sent_at` | BIGINT (ms epoch) | SELECT, WHERE (pre-expiry check), INSERT, UPDATE |
