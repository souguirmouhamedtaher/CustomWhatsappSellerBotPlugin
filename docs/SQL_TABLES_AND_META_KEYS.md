# SQL Tables, Fields, and Meta Keys Inventory

This document inventories database usage found in repository/query code, with emphasis on SQL statements and meta_key/meta_value patterns.

## Scope

- Included: `includes/repositories/**` (queries/repositories/writers/mappers where SQL appears)
- Included: `includes/services/add-product/**` for meta-key reads/writes and SQL lookups by meta
- Included: custom table schema in `custom-whatsapp-seller-bot.php`
- Focus: table names, referenced fields/columns, and meta keys

## Table Index

1. `wp_posts` (`$wpdb->posts`)
2. `wp_postmeta` (`$wpdb->postmeta`)
3. `wp_users` (`$wpdb->users`)
4. `wp_usermeta` (`$wpdb->usermeta`)
5. `wp_terms` (`$wpdb->terms`)
6. `wp_term_taxonomy` (`$wpdb->term_taxonomy`)
7. `wp_term_relationships` (`$wpdb->term_relationships`)
8. `wp_wcfm_marketplace_orders` (`$wpdb->prefix . 'wcfm_marketplace_orders'`)
9. `wp_woocommerce_order_items` (`$wpdb->prefix . 'woocommerce_order_items'`)
10. `wp_woocommerce_order_itemmeta` (`$wpdb->prefix . 'woocommerce_order_itemmeta'`)
11. `wp_cwsb_seller_state` (`$wpdb->prefix . 'cwsb_seller_state'`)

## Fields By Table

### 1) wp_posts

Referenced fields:

- `ID`
- `post_type`
- `post_status`
- `post_author`
- `post_title`
- `post_excerpt`
- `post_content`
- `post_date`
- `post_date_gmt`
- `post_parent`
- `post_name`
- `menu_order`

Used in:

- `includes/repositories/order/class-cwsb-order-queries.php`
- `includes/repositories/product/class-cwsb-product-queries.php`
- `includes/repositories/update-product/class-cwsb-update-product-queries.php`
- `includes/repositories/update-product/class-cwsb-update-product-writer.php`

### 2) wp_postmeta

Referenced fields:

- `post_id`
- `meta_key`
- `meta_value`

Used in:

- `includes/repositories/order/class-cwsb-order-queries.php`
- `includes/repositories/product/class-cwsb-product-queries.php`
- `includes/repositories/update-product/class-cwsb-update-product-queries.php`

### 3) wp_users

Referenced fields:

- `ID`
- `display_name`
- `user_email`

Used in:

- `includes/repositories/seller/class-cwsb-seller-vendor-queries.php`
- `includes/repositories/seller/class-cwsb-seller-state-queries.php`

### 4) wp_usermeta

Referenced fields:

- `user_id`
- `meta_key`
- `meta_value`

Used in:

- `includes/repositories/seller/class-cwsb-seller-vendor-queries.php`
- `includes/repositories/seller/class-cwsb-seller-state-queries.php`

### 5) wp_terms

Referenced fields:

- `term_id`
- `name`
- `slug`

Used in:

- `includes/repositories/product/class-cwsb-product-queries.php`
- `includes/repositories/update-product/class-cwsb-update-product-queries.php`

### 6) wp_term_taxonomy

Referenced fields:

- `term_taxonomy_id`
- `term_id`
- `taxonomy`
- `parent`

Used in:

- `includes/repositories/product/class-cwsb-product-queries.php`
- `includes/repositories/update-product/class-cwsb-update-product-queries.php`

### 7) wp_term_relationships

Referenced fields:

- `object_id`
- `term_taxonomy_id`

Used in:

- `includes/repositories/product/class-cwsb-product-queries.php`
- `includes/repositories/update-product/class-cwsb-update-product-queries.php`

### 8) wp_wcfm_marketplace_orders

Referenced fields:

- `order_id`
- `vendor_id`

Used in:

- `includes/repositories/order/class-cwsb-order-queries.php`

### 9) wp_woocommerce_order_items

Referenced fields:

- `order_item_id`
- `order_id`
- `order_item_type`
- `order_item_name`

Used in:

- `includes/repositories/order/class-cwsb-order-queries.php`
- `includes/repositories/order/class-cwsb-order-mapper.php`

### 10) wp_woocommerce_order_itemmeta

Referenced fields:

- `order_item_id`
- `meta_key`
- `meta_value`

Used in:

- `includes/repositories/order/class-cwsb-order-queries.php`
- `includes/repositories/order/class-cwsb-order-mapper.php`

### 11) wp_cwsb_seller_state

Referenced fields:

- `id`
- `user_id`
- `name`
- `email`
- `phone`
- `code`
- `flow_token`
- `reset_token`
- `reset_token_expiry`
- `session_active_until`
- `auth_portal_sent_at`
- `created_at`
- `updated_at`

Defined/used in:

- `custom-whatsapp-seller-bot.php` (CREATE TABLE and schema maintenance)
- `includes/repositories/seller/class-cwsb-seller-state-queries.php`

## Meta Key Inventory

### A) postmeta keys (`wp_postmeta.meta_key`)

From SQL projections/filters and postmeta API usage in repositories:

- `_sku`
- `_price`
- `_sale_price`
- `_regular_price`
- `_stock`
- `_manage_stock`
- `_thumbnail_id`
- `_product_image_gallery`
- `_regular_price_tnd`
- `_sale_price_tnd`
- `_regular_price_wmcp` — value is a JSON string `{"TND":"<value>"}`, decoded at read time by `CWSB_Utils::decode_wmcp_tnd()`
- `_sale_price_wmcp` — value is a JSON string `{"TND":"<value>"}`, decoded at read time by `CWSB_Utils::decode_wmcp_tnd()`
- `_price_tnd`
- `_stock_status`
- `_weight`
- `_length`
- `_width`
- `_height`
- `_cwsb_dim_unit`
- `_cwsb_weight_unit`
- `_cwsb_color`
- `_cwsb_size`
- `_cwsb_category_label`
- `_cwsb_subcategory_label`
- `_cwsb_idempotency_key`
- `_product_attributes`
- `regular_price_tnd`
- `sale_price_tnd`
- `price_tnd`

Order postmeta keys:

- `_order_number`
- `_customer_user`
- `_order_total`
- `_order_currency`
- `_payment_method_title`
- `_transaction_id`
- `_customer_note`
- `_order_shipping`
- `_shipping_total`
- `_billing_first_name`
- `_billing_last_name`
- `_billing_address_1`
- `_billing_address_2`
- `_billing_city`
- `_billing_state`
- `_billing_postcode`
- `_billing_country`
- `_shipping_first_name`
- `_shipping_last_name`
- `_shipping_address_1`
- `_shipping_address_2`
- `_shipping_city`
- `_shipping_state`
- `_shipping_postcode`
- `_shipping_country`

Pattern-based key usage:

- `attribute_%` (variation attributes)

Primary sources:

- `includes/repositories/product/class-cwsb-product-queries.php`
- `includes/repositories/update-product/class-cwsb-update-product-queries.php`
- `includes/repositories/update-product/class-cwsb-update-product-writer.php`
- `includes/repositories/order/class-cwsb-order-queries.php`
- `includes/repositories/order/class-cwsb-order-mapper.php`
- `includes/repositories/product/class-cwsb-product-mapper.php`
- `includes/services/add-product/class-cwsb-add-product-actions-service.php`
- `includes/services/add-product/class-cwsb-add-product-support-service.php`

### B) order_itemmeta keys (`wp_woocommerce_order_itemmeta.meta_key`)

- `_product_id`
- `_variation_id`
- `_qty`
- `_line_total`
- `_line_subtotal`

Primary sources:

- `includes/repositories/order/class-cwsb-order-queries.php`
- `includes/repositories/order/class-cwsb-order-mapper.php`

### C) usermeta keys (`wp_usermeta.meta_key`)

- `billing_phone`
- `phone`
- `wcfm_phone`
- `{$wpdb->prefix}capabilities` (resolved runtime key, typically `wp_capabilities`)

Primary sources:

- `includes/repositories/seller/class-cwsb-seller-vendor-queries.php`
- `includes/repositories/seller/class-cwsb-seller-state-queries.php`

### D) wp_options keys (`get_option()`)

Plugin configuration values stored in `wp_options`, not postmeta:

- `cwsb_api_key` — expected API key for request authentication
- `cwsb_eur_exchange_rate` — live EUR/TND exchange rate used for price conversion
- `cwsb_eur_fixed_markup` — fixed EUR amount added after rate conversion
- `cwsb_eur_rounding_decimals` — decimal precision for EUR price rounding

Primary sources:

- `includes/middleware/class-cwsb-auth-middleware.php` (`cwsb_api_key`)
- `includes/services/add-product/class-cwsb-add-product-support-service.php` (`cwsb_eur_exchange_rate`, `cwsb_eur_fixed_markup`)
- `includes/utilities/class-cwsb-utils.php` (`cwsb_eur_rounding_decimals`)

## SQL Shapes Detected (meta_key => meta_value)

Common pattern used extensively:

- `MAX(CASE WHEN <alias>.meta_key = '<key>' THEN <alias>.meta_value END) AS <field_alias>`

Used to pivot meta rows into flat result columns in order/product/update-product query classes.

## Notes

- The project relies on both raw SQL and WordPress meta APIs. Some keys are read in SQL and also written through `update_post_meta`/`delete_post_meta`.
- The seller-state table (`wp_cwsb_seller_state`) is a custom plugin table and is not a WordPress core table.
- Table names are shown with effective runtime names (default `wp_` prefix) and code-level `$wpdb` references.
