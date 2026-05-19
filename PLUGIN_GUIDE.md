# Custom WhatsApp Seller Bot Plugin Guide

Version reference: `1.0.17`

---

## Purpose

This guide explains the plugin from first principles down to the implementation details in this repository. It is written for a developer who can read PHP but may not yet be comfortable with WordPress plugin internals.

It covers four things:

1. what a WordPress plugin is, both theoretically and technically
2. how this plugin boots and integrates into WordPress
3. how requests move through the architecture from route to SQL
4. how versioning, Git tags, GitHub releases, and WordPress updates fit together

Supporting references:

- `docs/ENDPOINTS.md` for the endpoint catalog
- `docs/ARCHITECTURE.md` for the existing architecture notes

---

## Table of Contents

1. [What Is a WordPress Plugin?](#1-what-is-a-wordpress-plugin)
2. [The Main Plugin File](#2-the-main-plugin-file)
3. [Native WordPress Functions and APIs Used](#3-native-wordpress-functions-and-apis-used)
4. [Plugin Architecture](#4-plugin-architecture)
5. [Data Model and External Tables](#5-data-model-and-external-tables)
6. [Request Lifecycle Example: POST /seller/dashboard/by-phone](#6-request-lifecycle-example-post-sellerdashboardby-phone)
7. [Versioning and GitHub-Based Plugin Updates](#7-versioning-and-github-based-plugin-updates)
8. [Release Workflow Used in This Repository](#8-release-workflow-used-in-this-repository)
9. [Caching Strategy and Redis Behavior](#9-caching-strategy-and-redis-behavior)
10. [Practical Reading Order for This Repository](#10-practical-reading-order-for-this-repository)
11. [Summary](#11-summary)

---

## 1. What Is a WordPress Plugin?

### Theoretical View

A WordPress plugin is a package of PHP code that extends WordPress without modifying WordPress core. It is WordPress's extension mechanism. Instead of editing WordPress itself, you place a plugin in `wp-content/plugins/` and let WordPress load it during runtime.

A plugin can:

- register REST API endpoints
- create database tables
- hook into WordPress actions and filters
- add admin pages
- integrate with WooCommerce or other plugins
- expose business logic to external systems

This repository implements a custom API layer for a WhatsApp seller bot. In practice, the plugin acts as a bridge between WordPress users, WooCommerce products and orders, WCFM marketplace mappings, a custom seller-state table, and external clients calling `/wp-json/whatsapp-bot/v1/...`.

### Technical View

WordPress discovers plugins by scanning plugin files for a header comment block. In this repository, that block is at the top of `custom-whatsapp-seller-bot.php`:

```php
/*
Plugin Name: Custom WhatsApp Seller Bot
Description: Seller lookup endpoints for WhatsApp bot.
Version: 1.0.17
Author: ILEYCOM-INTERNSHIPS
*/
```

WordPress reads that header to determine:

- the plugin's display name in the admin UI
- the installed version
- author information
- metadata used by update tooling

The main file is therefore both:

- the metadata entrypoint WordPress scans
- the runtime bootstrap file that defines constants, loads dependencies, registers hooks, and wires the plugin into WordPress

---

## 2. The Main Plugin File

The plugin root file is `custom-whatsapp-seller-bot.php`. It is the first file WordPress relies on for this plugin.

### Core responsibilities

| Responsibility | What it does |
|---|---|
| Plugin metadata | Declares `Plugin Name`, `Description`, `Version`, and `Author` |
| Access guard | Prevents direct access with the `ABSPATH` check |
| Global constants | Defines `CWSB_NS`, `CWSB_PLUGIN_DIR`, `CWSB_PLUGIN_BASENAME`, `CWSB_UPDATER_REPO_OWNER`, and `CWSB_UPDATER_REPO_NAME` |
| Shared bootstrap loading | Loads `config/constants.php` and `includes/utilities/class-cwsb-plugin-updater.php` |
| Bootstrap functions | Defines `cwsb_create_tables()`, `cwsb_ensure_tables()`, `cwsb_upgrade_schema_if_needed()`, `cwsb_register_rest_routes()`, and `cwsb_bootstrap_plugin_updater()` |
| WordPress integration | Registers activation hooks, runtime hooks, REST initialization, and response filters |

### Why the `ABSPATH` check exists

Many files in the plugin begin with:

```php
if (!defined('ABSPATH')) {
    exit;
}
```

`ABSPATH` is a WordPress core constant. If it is not defined, the file was probably requested directly instead of being loaded through WordPress. Exiting early prevents accidental direct execution.

### Activation-time work vs runtime work

This plugin deliberately separates one-time setup from ongoing runtime behavior.

**Activation-time work**

- `register_activation_hook(__FILE__, 'cwsb_create_tables')`
- creates the custom seller state table when the plugin is activated

**Runtime work**

- `add_action('plugins_loaded', ...)`
- `add_action('rest_api_init', ...)`
- `add_filter('rest_pre_serve_request', ...)`

Runtime hooks are important because activation is not guaranteed to be the only moment the plugin needs to heal state. If a database is restored without re-running activation, `cwsb_ensure_tables()` and `cwsb_upgrade_schema_if_needed()` make the plugin more self-healing.

---

## 3. Native WordPress Functions and APIs Used

This plugin is a good example of how a serious WordPress integration is built out of native WordPress primitives.

### Quick reference table

| WordPress API | Where it is used | Why it matters here |
|---|---|---|
| `register_activation_hook()` | `custom-whatsapp-seller-bot.php` | Creates the custom table on activation |
| `add_action()` | `custom-whatsapp-seller-bot.php`, updater class | Hooks plugin logic into WordPress lifecycle |
| `add_filter()` | main file, updater class | Modifies REST response serving and update data |
| `plugin_dir_path()` | main file, constants file | Builds stable file-system paths |
| `plugin_basename()` | main file, updater class | Identifies the plugin inside WordPress update internals |
| `dbDelta()` | `cwsb_create_tables()` | Safely creates or upgrades the custom table |
| `register_rest_route()` | controller classes | Registers REST endpoints |
| `WP_REST_Request` | service methods | Carries params, headers, and body data |
| `WP_REST_Response` | `CWSB_Response` | Produces standardized JSON responses |
| `WP_Error` | middleware and services | Produces structured REST errors |
| `nocache_headers()` | main file and dashboard service | Prevents stale responses |
| `get_option()` | middleware and pricing logic | Reads plugin configuration |
| `get_site_transient()` / `set_site_transient()` | updater class | Caches GitHub release payloads |
| `get_terms()` | add-product services | Reads product taxonomy data |
| `hash_equals()` | auth middleware | Compares API keys safely |
| `$wpdb->prepare()` and result helpers | repository query classes | Performs safe SQL reads |
| `microtime()` / `error_log()` | order flows | Performance timing and diagnostics |
| `ABSPATH` / `WP_DEBUG` | many files | Access guard and debug configuration |

### `register_activation_hook()`

Used in `custom-whatsapp-seller-bot.php`:

```php
register_activation_hook(__FILE__, 'cwsb_create_tables');
```

This tells WordPress to run `cwsb_create_tables()` when the plugin is activated. In this plugin, that function creates the custom `wp_cwsb_seller_state` table using `dbDelta()`.

### `add_action()`

Used in `custom-whatsapp-seller-bot.php` to hook into WordPress execution points:

- `plugins_loaded` for table checks, schema upgrades, and updater bootstrap
- `rest_api_init` for table checks and REST route registration

This is how the plugin inserts itself into WordPress's runtime lifecycle without modifying core.

### `add_filter()`

Used in `custom-whatsapp-seller-bot.php` and `includes/utilities/class-cwsb-plugin-updater.php`.

Examples:

- `rest_pre_serve_request` to apply no-cache behavior for this plugin's REST responses
- `pre_set_site_transient_update_plugins` to inject GitHub-based update metadata
- `plugins_api` to populate the plugin details modal in WordPress admin

Actions are for doing things. Filters are for transforming data flowing through WordPress.

### `plugin_dir_path()`

Used when defining `CWSB_PLUGIN_DIR`.

This gives the absolute path of the plugin directory, which makes `require_once` paths reliable across environments.

### `plugin_basename()`

Used when defining `CWSB_PLUGIN_BASENAME` and again inside `CWSB_Plugin_Updater`.

WordPress identifies installed plugins by basename, so updater logic needs it to inject update information into the correct slot.

### `dbDelta()`

Used inside `cwsb_create_tables()`.

`dbDelta()` is WordPress's schema-safe table creation and migration helper. It can create or adjust a table definition without blindly dropping and recreating it. That is why the plugin uses it for `wp_cwsb_seller_state`.

### `register_rest_route()`

Used in all controller classes, including:

- `includes/controllers/auth/class-cwsb-auth-controller.php`
- `includes/controllers/add-product/class-cwsb-add-product-controller.php`
- `includes/controllers/update-product/class-cwsb-update-product-controller.php`
- `includes/controllers/dashboard/class-cwsb-dashboard-controller.php`

This is the core WordPress REST API registration primitive. It binds an HTTP method, a route path, a callback, a permission callback, and argument requirements.

In this plugin, every route lives under the namespace `whatsapp-bot/v1`.

### `WP_REST_Request`

Used as the request object type for endpoint handlers across service and controller layers.

Examples:

- `CWSB_Dashboard_Seller_Service::get_dashboard_seller_by_phone(WP_REST_Request $request)`
- `CWSB_Auth_Product_Endpoints_Service::get_seller_products_by_flow_token(WP_REST_Request $request)`

This object provides access to request parameters, headers, and input data.

### `WP_REST_Response` and `WP_Error`

The plugin wraps most successful responses through `CWSB_Response::ok()` and most failures through `CWSB_Response::error()`.

`CWSB_Response` internally builds `WP_REST_Response` objects and sets UTF-8 JSON headers. Authentication failures in `CWSB_Auth_Middleware` return `WP_Error` objects with proper HTTP status codes.

This keeps payload shape and HTTP behavior consistent across the plugin.

### `nocache_headers()`

Used in two places:

- `cwsb_send_rest_no_cache_headers()` in the main plugin file
- `CWSB_Dashboard_Seller_Service::prevent_response_caching()`

The plugin is careful about freshness because many endpoints expose rapidly changing seller, session, product, and dashboard state. `nocache_headers()` makes proxies and browsers less likely to serve stale responses.

### `get_option()`

Used in several places.

Examples:

- `CWSB_Auth_Middleware::require_api_key()` reads `cwsb_api_key`
- add-product support services read pricing options such as `cwsb_eur_exchange_rate`, `cwsb_eur_fixed_markup`, and `cwsb_eur_rounding_decimals`

This is WordPress's native configuration storage mechanism.

### `get_site_transient()` / `set_site_transient()` / `delete_site_transient()`

Used in `CWSB_Plugin_Updater`.

The updater caches the GitHub release payload for six hours using a site transient keyed from the repository owner and repo name. That avoids repeatedly calling the GitHub API on every plugin update check.

### `get_terms()`

Used by the add-product services to list product categories and subcategories.

This is the standard WordPress taxonomy API. It allows the plugin to read product category structures without writing raw SQL against taxonomy tables in that part of the code.

### `hash_equals()`

Used in `CWSB_Auth_Middleware::require_api_key()`.

This is a timing-safe string comparison function. It is important for secrets such as API keys because naive string comparison can leak timing information.

### `$wpdb->prepare()`, `get_row()`, `get_results()`, `get_var()`, `get_col()`

These appear throughout the repository query classes.

Their roles are:

- `$wpdb->prepare()` for safe SQL parameter binding
- `get_row()` for one result row
- `get_results()` for multiple rows
- `get_var()` for one scalar value
- `get_col()` for a single-column result set

This plugin uses them heavily in seller, order, product, wallet, and update-product query layers.

### `microtime()` and `error_log()`

Used mainly in order flows and other performance-sensitive areas.

The plugin uses them to record execution timing during expensive operations like order aggregation and list building.

### `ABSPATH` and `WP_DEBUG`

These are core constants used throughout the plugin.

- `ABSPATH` protects files from direct access
- `WP_DEBUG` feeds debug-related constants like `CWSB_DEBUG_PERFORMANCE` and `CWSB_DEBUG_QUERIES`

---

## 4. Plugin Architecture

The codebase follows a layered structure. That matters because the same endpoint often touches several files, and the logic is intentionally split by responsibility.

### High-level directory roles

| Path | Role |
|---|---|
| `custom-whatsapp-seller-bot.php` | Plugin bootstrap and global hook wiring |
| `config/` | Shared constants and environment-wide defaults |
| `includes/controllers/` | REST route registration layer |
| `includes/services/` | Endpoint-level business logic |
| `includes/repositories/` | Data access orchestration and facades |
| `includes/middleware/` | Authentication and request guards |
| `includes/utilities/` | Shared helpers, updater logic, logging, and normalization |
| `docs/` | Existing repository documentation |
| `tests/` | Test and helper scripts |

### Layer model

The common runtime path is:

```text
Bootstrap -> Controller -> Service -> Repository -> Query/Writer/Normalizer -> Response
```

### Responsibilities by layer

**Bootstrap**

- defines plugin metadata and constants
- loads shared files
- registers WordPress hooks
- loads controllers during `rest_api_init`

**Controller**

- registers routes with `register_rest_route()`
- chooses handler callbacks
- defines argument rules and permission callbacks

**Service**

- validates request semantics
- applies endpoint-specific business rules
- normalizes parameters
- invokes repository methods
- returns a standardized response

**Repository**

- coordinates data retrieval or writes
- resolves identifiers such as `phone -> user_id` or `flow_token -> seller`
- combines raw rows into domain objects

**Query / Writer / Normalizer**

- query classes execute SQL
- writer classes persist changes
- normalizer and mapper classes convert raw rows into API-friendly payloads

### Facade patterns used in this plugin

Two important facades structure the code.

**`CWSB_Auth_Seller_Endpoints_Service`**

- acts as a backward-compatible facade over four specialized service classes
- delegates seller core, product, order, and wallet endpoint calls to their owning services

**`CWSB_Seller_Repository`**

- acts as a facade over seller read and seller state repositories
- exposes a stable surface while letting the implementation split between reads and writes

That separation keeps controllers simple and lets internal implementation move without changing the public route handlers.

---

## 5. Data Model and External Tables

### Custom table: `wp_cwsb_seller_state`

This plugin creates and maintains a custom table named with the current WordPress prefix plus `cwsb_seller_state`.

### Key columns

| Column | Purpose |
|---|---|
| `user_id` | Links the bot state to a WordPress user |
| `name` | Cached seller name |
| `email` | Cached seller email |
| `phone` | Phone lookup key |
| `code` | Bot or auth code state |
| `flow_token` | WhatsApp flow tracking token |
| `reset_token` | Reset workflow token |
| `reset_token_expiry` | Reset token expiration |
| `session_active_until` | Seller session validity timestamp |
| `auth_portal_sent_at` | Portal notification timestamp |
| timestamps | Created and updated audit fields |

It also defines indexes for lookup-heavy fields such as `email`, `phone`, `flow_token`, `reset_token`, `session_active_until`, and `auth_portal_sent_at`.

### Why not just use `wp_usermeta`?

Because this plugin manages a workflow state machine for a WhatsApp bot, not just user profile metadata.

`wp_usermeta` is suitable for generic user metadata, but this plugin needs:

- fast indexed lookups by bot-specific fields
- a controlled schema with explicit semantics
- data that belongs to session or auth flow state rather than generic user profile data
- self-healing schema evolution managed by the plugin itself

### Core and external tables used

| Table | Source system | Used for |
|---|---|---|
| `wp_users` | WordPress core | Seller identities |
| `wp_usermeta` | WordPress core | Vendor capabilities, phones, seller metadata |
| `wp_posts` | WordPress and WooCommerce | Products, orders, variations |
| `wp_postmeta` | WordPress and WooCommerce | Product and order metadata |
| `wp_terms` / `wp_termmeta` | WordPress taxonomy | Category and taxonomy metadata |
| `wp_wcfm_marketplace_orders` | WCFM | Seller-to-order marketplace mapping |
| `wp_woocommerce_order_items` | WooCommerce | Order line items |
| `wp_woocommerce_order_itemmeta` | WooCommerce | Per-item order metadata |

The plugin does not replace those systems. It coordinates them.

---

## 6. Request Lifecycle Example: `POST /seller/dashboard/by-phone`

This is the cleanest example of the full call chain.

### End-to-end flow

1. **WordPress loads the plugin bootstrap**
   WordPress reads `custom-whatsapp-seller-bot.php`, defines constants, loads shared dependencies, and registers hooks.

2. **`rest_api_init` triggers route registration**
   `cwsb_register_rest_routes()` loads controller classes and calls their `register_routes()` methods.

3. **Dashboard routes are registered**
   `CWSB_Dashboard_Controller::register_routes()` registers:
   - `GET /seller/dashboard/all`
   - `GET /seller/dashboard/active`
   - `POST /seller/dashboard/by-phone`
   - `POST /seller/dashboard/by-email`

4. **The permission callback runs first**
   `CWSB_Auth_Middleware::require_api_key()` resolves the expected API key in this order:
   1. WordPress option `cwsb_api_key`
   2. constant `CWSB_API_KEY`
   3. environment variable `WP_PLUGIN_API_KEY`

5. **The API key is compared safely**
   The middleware reads the `x-api-key` request header and compares it with `hash_equals()`. If validation fails, it returns a `WP_Error` with status `401` or `403`.

6. **The dashboard service handles the request**
   `CWSB_Dashboard_Seller_Service::get_dashboard_seller_by_phone(WP_REST_Request $request)`:
   - disables caching through `nocache_headers()` and the `DONOTCACHEPAGE` / `DONOTCACHEDB` flags
   - reads the `phone` parameter from the request
   - validates that it is present
   - calls `CWSB_Seller_Repository::find_dashboard_seller_by_phone($phone)`

7. **The seller repository delegates to the read repository**
   `CWSB_Seller_Repository::find_dashboard_seller_by_phone()` forwards the call to `CWSB_Seller_Read_Repository::find_dashboard_seller_by_phone()`.

8. **The read repository resolves seller identity first**
   `CWSB_Seller_Read_Repository::find_dashboard_seller_by_phone()`:
   - resolves the vendor by phone using seller or vendor lookup logic
   - extracts the seller `user_id`
   - calls `CWSB_Seller_Read_Queries::find_dashboard_seller_row_by_user_id((int) $vendor['user_id'])`
   - normalizes the phone with `CWSB_Utils::normalize_phone()`
   - sends the row through `CWSB_Seller_Read_Normalizer::normalize_seller_row_for_dashboard()`

9. **The query layer executes SQL**
   The dashboard SQL lives in `includes/repositories/seller/class-cwsb-seller-vendor-queries.php`, including:
   - `get_dashboard_seller_rows()`
   - `get_active_dashboard_seller_rows()`
   - `find_dashboard_seller_row_by_user_id()`

10. **The query composes data from several tables**
    It pulls together:
    - seller identity from `wp_users`
    - vendor capability from `wp_usermeta`
    - phone fields from multiple meta keys
    - bot state from `wp_cwsb_seller_state`
    - `product_count` from `wp_posts`
    - `order_count` from `wp_wcfm_marketplace_orders`

11. **The response is wrapped consistently**
    The service returns:

```php
return CWSB_Response::ok(['seller' => $seller ?: null]);
```

`CWSB_Response::ok()` converts the payload into a `WP_REST_Response`, normalizes string encoding, sets `Content-Type: application/json; charset=UTF-8`, and preserves a consistent JSON envelope:

```json
{
  "success": true,
  "data": {
    "seller": { ... }
  }
}
```

### Short mental model

```text
HTTP request
+-> REST route registration
+-> permission callback
+-> dashboard service
+-> seller repository facade
+-> read repository
+-> SQL query layer
+-> normalizer
+-> CWSB_Response::ok()
+-> JSON response
+```

---

## 7. Versioning and GitHub-Based Plugin Updates

This plugin does not rely on the WordPress.org plugin directory for updates. Instead, it teaches WordPress how to treat GitHub releases as plugin updates.

### Where the installed version comes from

The installed version is the `Version:` field in the header comment of `custom-whatsapp-seller-bot.php`.

`CWSB_Plugin_Updater` reads that value using `get_file_data()` inside `read_current_version()`.

### How the updater is bootstrapped

The main plugin file calls:

```php
add_action('plugins_loaded', 'cwsb_bootstrap_plugin_updater', 7);
```

That function calls:

```php
CWSB_Plugin_Updater::bootstrap(
    __FILE__,
    CWSB_UPDATER_REPO_OWNER,
    CWSB_UPDATER_REPO_NAME
);
```

The updater then registers three hooks:

- `pre_set_site_transient_update_plugins`
- `plugins_api`
- `upgrader_process_complete`

### How it talks to GitHub

`CWSB_Plugin_Updater::get_latest_release()` calls:

```text
https://api.github.com/repos/{owner}/{repo}/releases/latest
```

It sends:

- an `Accept: application/vnd.github+json` header
- a custom `User-Agent`
- a 15-second timeout

Then it reads fields such as:

- `tag_name`
- `assets`
- `browser_download_url`
- `zipball_url`
- `html_url`
- `name`
- `body`
- `published_at`

It strips the leading `v` from the tag to derive the semantic version used for WordPress version comparison.

### How package selection works

The updater prefers a release asset ending in `.zip`. If several exist, it prefers one whose filename contains the repository name. If no asset zip is found, it falls back to GitHub's `zipball_url`.

That chosen package URL becomes the update package WordPress downloads.

### How the update is injected into WordPress

When WordPress prepares the `update_plugins` transient, the updater's `inject_update()` method compares:

- installed version from the plugin header
- latest version from GitHub

If the GitHub version is newer, it inserts an update object into the transient response for this plugin.

That is what makes WordPress show an available update in the admin UI even though the source of truth is GitHub releases.

### The six-hour cache

The updater caches the latest release payload with:

- `get_site_transient($this->cache_key)`
- `set_site_transient($this->cache_key, $release, self::CACHE_TTL)`

`CACHE_TTL` is defined as:

```php
const CACHE_TTL = 6 * HOUR_IN_SECONDS;
```

This prevents repeated GitHub API calls during normal admin activity.

### Cache invalidation after upgrade

`clear_cache_after_upgrade()` listens to `upgrader_process_complete` and deletes the site transient after a plugin update.

That matters because once the plugin is updated, the cached release data may no longer reflect the installed version.

---

## 8. Release Workflow Used in This Repository

The release flow used for recent versions such as `v1.0.15`, `v1.0.16`, and `v1.0.17` is straightforward.

### Release steps

1. **Bump the plugin header version**
   Update the `Version:` value in `custom-whatsapp-seller-bot.php`.

2. **Commit the code**

```bash
git add .
git commit -m "fix: ..."
```

3. **Create a Git tag**

```bash
git tag v1.0.17
```

The updater expects GitHub releases or tags that map cleanly to semantic versions. The code strips a leading `v`, so `v1.0.17` becomes `1.0.17` for comparison.

4. **Push branch and tags**

```bash
git push
git push --tags
```

5. **Let WordPress detect the update**
   WordPress checks the update transient, the plugin updater injects the newer version, and the admin UI can offer the update like a native plugin update.

---

## 9. Caching Strategy and Redis Behavior

Caching in this plugin is intentional and split into two categories: response freshness controls and metadata caching.

### What is actively cached

1. **Updater metadata only**
   `CWSB_Plugin_Updater` uses site transients to cache GitHub release metadata for six hours:
   - `get_site_transient($this->cache_key)`
   - `set_site_transient($this->cache_key, $release, self::CACHE_TTL)`
   - `delete_site_transient($this->cache_key)` after upgrades

2. **WooCommerce internal product cache cleanup after writes**
   Product create/update flows call:
   - `clean_term_cache(...)`
   - `clean_post_cache(...)`
   - `wc_delete_product_transients(...)`

These are active and should be preserved.

### What is intentionally not cached

All plugin REST responses under the `whatsapp-bot/v1` namespace are explicitly marked non-cacheable:

- global filter in `custom-whatsapp-seller-bot.php` (`rest_pre_serve_request`)
- sets `DONOTCACHEPAGE` and `DONOTCACHEDB`
- calls `nocache_headers()`

Auth and dashboard services also set the same flags locally, which is redundant but harmless.

### Redis behavior in this plugin context

Redis object cache can still cache WordPress internals globally, but this plugin does **not** implement an active app-level seller/product response cache. That means:

1. Redis does not automatically make plugin REST responses cached when no-cache flags are set.
2. Redis can back transients/object cache used by WordPress, including updater metadata.
3. Fresh API reads remain the default behavior for seller/product/order/dashboard endpoints.

### Removed dead cache scaffolding

The plugin previously contained seller/product cache scaffolding that is now removed:

- removed no-op seller invalidator class
- removed empty product invalidation method
- removed unused seller cache-key helper methods
- removed dead invalidation call sites from writers

Result: the code now matches runtime reality with less misleading cache code.

---

## 10. Practical Reading Order for This Repository

If you are trying to understand the plugin quickly, read the code in this order:

1. `custom-whatsapp-seller-bot.php`
2. `config/constants.php`
3. `includes/middleware/class-cwsb-auth-middleware.php`
4. `includes/utilities/class-cwsb-response.php`
5. `includes/controllers/dashboard/class-cwsb-dashboard-controller.php`
6. `includes/services/dashboard/class-cwsb-dashboard-seller-service.php`
7. `includes/repositories/seller/class-cwsb-seller-repository.php`
8. `includes/repositories/seller/class-cwsb-seller-read-repository.php`
9. `includes/repositories/seller/class-cwsb-seller-vendor-queries.php`
10. `includes/utilities/class-cwsb-plugin-updater.php`

Then expand outward into:

- auth flows
- product read and update flows
- order flows
- wallet flows

---

## 11. Summary

This repository is not just a few REST routes. It is a structured WordPress integration layer that:

- boots like a normal WordPress plugin through a main entry file
- uses WordPress hooks to attach activation-time and runtime behavior
- exposes a custom REST API namespace for an external WhatsApp bot
- uses service and repository layers to keep business logic separated from SQL
- stores bot-specific workflow state in a custom table
- integrates with WooCommerce and WCFM data
- ships updates through GitHub tags and releases while still appearing as a WordPress-updateable plugin

### Final mental model

Keep this model in mind while working in the codebase:

```text
WordPress plugin bootstrap
-> hooks into WordPress lifecycle
-> registers REST routes
-> authenticates API requests
-> delegates business logic to services
-> delegates reads and writes to repositories
-> executes SQL in query classes
-> returns standardized JSON responses
-> ships updates through GitHub releases
```

That is the core structure of the plugin from startup to production update flow.
