# Custom WhatsApp Seller Bot - Architecture Guide

## Overview

This plugin provides REST API endpoints for WhatsApp bot integration, enabling sellers to manage authentication, products, and orders through WhatsApp flows.

**Version:** 1.0.0  
**Namespace:** `whatsapp-bot/v1`  
**Requires:** WordPress 5.8+, WooCommerce 5.0+, PHP 8.0+

---

## Directory Structure

```
custom-whatsapp-seller-bot/
├── custom-whatsapp-seller-bot.php    ← Main plugin loader (40 lines)
├── config/                            ← Environment-specific settings
│   ├── constants.php                 ← All plugin constants
│   └── enums.php                     ← Status codes, cache TTLs
├── includes/
│   ├── api/                          ← REST endpoint handlers (by resource)
│   │   ├── class-sellers-endpoint.php
│   │   ├── class-products-endpoint.php
│   │   ├── class-orders-endpoint.php
│   │   └── class-auth-endpoint.php
│   ├── repositories/                 ← Data access layer
│   │   ├── class-cwsb-seller-*.php   (read, write, state)
│   │   ├── class-cwsb-product-*.php
│   │   ├── class-cwsb-order-*.php
│   │   └── class-cwsb-update-product-*.php
│   ├── services/                     ← Business logic
│   │   ├── class-cwsb-auth-*.php
│   │   ├── class-cwsb-pin-service.php
│   │   └── class-cwsb-update-product-service.php
│   ├── utilities/                    ← Helpers & infrastructure
│   │   ├── class-cwsb-cache.php      (object cache wrapper)
│   │   ├── class-cwsb-utils.php      (phone normalization, etc.)
│   │   └── class-cwsb-response.php   (REST response formatting)
│   ├── middleware/                   ← Request/response interceptors
│   │   └── class-cwsb-auth-middleware.php
│   ├── models/                       ← Data shape definitions
│   │   ├── class-seller-model.php
│   │   ├── class-product-model.php
│   │   └── class-order-model.php
│   ├── hooks.php                     ← All WordPress hooks
│   └── loader.php                    ← Class autoloading
├── database/
│   ├── schema.php                    ← Table definitions
│   └── migrations.php                ← Version-based upgrades
├── tests/
│   ├── unit/                         ← Isolated component tests
│   └── integration/                  ← Multi-component tests
└── docs/
    ├── ARCHITECTURE.md               ← This file
    ├── API_REFERENCE.md
    ├── CACHING_STRATEGY.md
    ├── PHONE_NORMALIZATION.md
    └── MIGRATION_GUIDE.md
```

---

## Core Design Patterns

### 1. **Repository Pattern**
Data access is centralized in `includes/repositories/` classes:
- **Sellers:** `CWSB_Seller_Repository` (find by phone, email, flow token)
- **Products:** `CWSB_Product_Repository` (find by seller, by id, by variation)
- **Orders:** `CWSB_Order_Repository` (find by seller, by status)

Each repository method:
- ✅ Checks cache first (via `CWSB_Cache`)
- ✅ Suspends WordPress auto-cache during large queries (`wp_suspend_cache_addition()`)
- ✅ Stores result in plugin cache with TTL
- ✅ Returns normalized data structure

### 2. **Service Layer**
Business logic lives in `includes/services/`:
- Builds on repositories (calls find* methods)
- Implements domain rules (auth flow, product updates)
- Handles validation and error cases
- Called by endpoint handlers

### 3. **REST Controller Pattern**
Endpoints defined in `includes/controllers/`:
- Each controller registers routes via `register_routes()`
- Each action method is a static function matching `register_rest_route()` callback
- Actions delegate to services, never directly to repositories
- Return responses via `CWSB_Response::ok()` / `error()`

### 4. **Middleware Pattern**
Request/response processing via `includes/middleware/`:
- `CWSB_Auth_Middleware`: Validates seller auth state, extracts phone/token
- Hooks into `rest_pre_dispatch` to process before routing
- Can short-circuit response if validation fails

### 5. **Utility/Helper Pattern**
Cross-cutting concerns in `includes/utilities/`:
- **CWSB_Cache**: WordPress object cache wrapper (hit/miss tracking, pattern deletion)
- **CWSB_Utils**: Phone normalization, text sanitization, extraction helpers
- **CWSB_Response**: Standardized REST response envelopes

---

## Data Flow

### Example: Get Seller by Phone

```
1. Client Request
   GET /wp-json/whatsapp-bot/v1/seller/by-phone?phone=50354773
   |
2. REST Routing → CWSB_Auth_Controller::register_routes()
   |
3. Middleware Processing (CWSB_Auth_Middleware)
   ├─ Validate request params
   └─ Normalize phone format
   |
4. Endpoint Handler (CWSB_Auth_Seller_Endpoints_Service::get_seller_by_phone)
   ├─ Call nocache_headers() [FIX #5]
   ├─ Delegate to repository
   └─ Format response
   |
5. Repository (CWSB_Seller_Read_Repository::find_vendor_by_phone)
   ├─ Check cache (CWSB_Cache::get)
   ├─ If miss:
   │  ├─ Suspend WordPress cache [FIX #1]
   │  ├─ Query users + usermeta with phone variants
   │  ├─ Resume WordPress cache
   │  └─ Store in plugin cache (60s TTL)
   └─ Return seller data
   |
6. Response (CWSB_Response)
   ├─ Wrap data in REST envelope
   └─ Set nocache headers [FIX #5]
   |
7. Client Receives
   {
     "data": { "seller": {...} },
     "code": 200,
     "message": "OK"
   }
```

---

## Caching Strategy

### Multi-Layer Approach

**Layer 1: Plugin Cache (CWSB_Cache)**
- Store: `wp_cache_set('seller:phone:8050354773', $seller, 'cwsb', 60)`
- Hit: Return immediately (fast)
- Miss: Query database

**Layer 2: WordPress Object Cache (Suspended During Large Queries)**
- Normally: WordPress auto-caches all post/usermeta queries
- Issue: Auto-cache + plugin cache = triple storage (1.5MB per product)
- Fix: `wp_suspend_cache_addition(true)` around large queries [FIX #1-3]
- Result: Only plugin cache stores large results

**Layer 3: Browser/Proxy Cache (DISABLED)**
- HTTP headers: `Cache-Control: no-cache, no-store, must-revalidate`
- Reason: Seller state changes frequently, must always be fresh
- Implementation: `nocache_headers()` + `DONOTCACHEPAGE` [FIX #5]

### Cache Invalidation

Prefix-based deletion via `CWSB_Cache::delete_pattern()`:
```php
// After seller state update:
CWSB_Cache::delete_pattern('seller:*');  // Clear all seller entries
```

**Optimization:** Uses Redis SCAN instead of KEYS to avoid blocking [FIX #6]

---

## Recent Fixes (2026-04-06)

### CRITICAL Fixes
1. **[FIX #1-3] Cache Suspension in Repositories**
   - Problem: Large result sets cached 3x (WordPress auto-cache + plugin cache + serialization)
   - Impact: 500KB→1.5MB per product, 256MB Redis exhausted in hours
   - Solution: Wrap large $wpdb queries with `wp_suspend_cache_addition(true/false)`
   - Files: product, order, seller-read repositories

### HIGH Fixes
2. **[FIX #4] Add Missing Class Requires**
   - Problem: 6 repository/service classes not in loader array
   - Impact: Classes loaded but not in documented array, hard to maintain
   - Solution: Add all 17 classes to `$cwsb_class_files` array in main plugin file

3. **[FIX #5] Add Nocache Headers**
   - Problem: REST endpoints cache responses at proxy/browser level
   - Impact: Sellers see stale auth state, outdated products, etc.
   - Solution: Call `nocache_headers()` in all endpoint handlers
   - Files: main plugin file + all endpoint methods

### LOW Fixes
4. **[FIX #6] Replace KEYS with SCAN**
   - Problem: Redis KEYS command blocks server during large pattern deletions
   - Impact: Brief Redis lockups during cache purges
   - Solution: Use SCAN loop instead (non-blocking cursor iteration)
   - File: cache utility class

---

## Phone Normalization

Sellers from different regions (Tunisia, France, Senegal) with various formats:

**Tunisia (Country Code: +216)**
- Canonical: `8050354773` (8-digit)
- Variants: `+21650354773`, `00216-50354773`, `216-50354773`

**France (Country Code: +33)**
- Canonical: `33782655322` (11-digit with 33)
- Variants: `+33782655322`, `0033782655322`
- Local: `0782655322` (matches French standard)

**Senegal (Rejected)**
- All formats rejected (221 country code)

See [PHONE_NORMALIZATION.md](./PHONE_NORMALIZATION.md) for full details.

---

## Testing Strategy

### Unit Tests (`tests/unit/`)
Test isolated components:
```php
// Example: Test phone normalization
class Test_Phone_Normalizer extends WP_UnitTestCase {
    public function test_tunisian_phone_formats() {
        $this->assertEquals(
            '8050354773',
            CWSB_Utils::normalize_phone('00216-50354773')
        );
    }
}
```

### Integration Tests (`tests/integration/`)
Test multi-component workflows:
```php
// Example: Test seller lookup with caching
class Test_Seller_Lookup extends WP_UnitTestCase {
    public function test_seller_by_phone_cache_hit() {
        // First call: queries DB
        $seller1 = CWSB_Seller_Repository::find_vendor_by_phone('50354773');
        
        // Second call: returns from cache
        $seller2 = CWSB_Seller_Repository::find_vendor_by_phone('50354773');
        
        $this->assertEquals($seller1, $seller2);
        $this->assertGreater($cache_hits, 0);
    }
}
```

### Manual API Testing (`scripts/`)
Use Postman or curl:
```bash
# Test seller lookup
curl -X GET "http://localhost/wordpress/wp-json/whatsapp-bot/v1/seller/by-phone?phone=50354773"

# Test with caching disabled
CWSB_DISABLE_PLUGIN_CACHE=1 curl ...
```

---

## Configuration & Customization

### Environment Variables

```bash
# Disable caching (useful for debugging)
export CWSB_DISABLE_PLUGIN_CACHE=1

# Enable performance logging
export WP_DEBUG=true
export WP_DEBUG_LOG=true
```

### WP-Config Constants

```php
// wp-config.php

// Disable caching
define('CWSB_DISABLE_PLUGIN_CACHE', true);

// Custom cache TTL (default: 60 seconds)
define('CWSB_CACHE_TTL', 120);

// Enable debug mode
define('WP_DEBUG', true);
define('CWSB_DEBUG_PERFORMANCE', true);
```

---

## Performance Considerations

### Query Optimization
- **Products:** Group by with JOINs (not n+1)
- **Orders:** Use wc_order_product_lookup table (faster than order_items)
- **Sellers:** Cache phone lookups (most frequent operation)

### Cache Tuning
- Seller phone lookups: 60s TTL (quick stale after phone change)
- Product lists: 60s TTL (product changes batched daily)
- Order summaries: 30s TTL (low change frequency, high query cost)

### Redis Memory
- **Kinsta Limit:** 256MB
- **Current Usage:** ~80MB (pre-fix estimates)
- **Safe Margin:** 50MB reserved
- **Auto-cache Disabled:** Saves 50-70MB

---

## Migration Guide

See [MIGRATION_GUIDE.md](./MIGRATION_GUIDE.md) for step-by-step production deployment.

---

## API Reference

See [API_REFERENCE.md](./API_REFERENCE.md) for full endpoint documentation.

---

## Troubleshooting

### Memory Exhaustion on Kinsta
- ✅ Caching suspension implemented (FIX #1-3)
- ✅ Auto-cache is now minimal
- ✅ Monitor: `wp plugin install redis-cache && wp redis-cache status`

### Stale Seller Data
- ✅ Nocache headers added (FIX #5)
- ✅ Check: `curl -i http://.../seller/by-phone?phone=50354773 | grep Cache-Control`
- Should show: `Cache-Control: no-cache, no-store, must-revalidate, private`

### Missing Classes on Startup
- ✅ All 17 required classes now in loader (FIX #4)
- ✅ Check: Look for errors in `wp_debug.log`

---

## Contributing

1. Add new endpoints to `includes/api/` (by resource)
2. Implement business logic in `includes/services/`
3. Data access via `includes/repositories/`
4. Add tests in `tests/unit/` and `tests/integration/`
5. Document in `docs/`
6. Update `custom-whatsapp-seller-bot.php` loader if adding classes

---

## License

ILEYCOM Internships 2026
