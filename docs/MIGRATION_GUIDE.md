# WordPress Plugin Migration & Deployment Guide

Structured guide for transitioning from monolithic plugin to modular architecture with proper testing and validation.

---

## Phase 1: Dev Environment Validation (Local XAMPP)

### Step 1: Verify All Fixes in Browser

```bash
# Terminal 1: Start XAMPP (if not running)
# Already running at http://localhost/xampp

# Terminal 2: Test each endpoint individually

# 1. Test seller lookup (exercises FIX #1, #2, #3, #5)
curl -i "http://localhost/wordpress/wp-json/whatsapp-bot/v1/seller/by-phone?phone=50354773"

# Expected response:
# - Status: 200
# - Body: { "data": { "seller": {...} }, "code": 200 }
# - Headers: Cache-Control: no-cache, no-store, must-revalidate, private

# 2. Test with French phone (exercises phone normalization)
curl -i "http://localhost/wordpress/wp-json/whatsapp-bot/v1/seller/by-phone?phone=0033782655322"

# 3. Test product list (exercises FIX #1)
curl -i "http://localhost/wordpress/wp-json/whatsapp-bot/v1/products/by-seller-phone?phone=50354773"

# 4. Test order counters (exercises FIX #2, #3)
curl -i "http://localhost/wordpress/wp-json/whatsapp-bot/v1/orders/status-counters?flow_token=abc123"
```

### Step 2: Monitor Redis Usage

```bash
# Check Redis memory via Kinsta console (when deployed)
# OR locally via Docker/WSL if running Redis separately:

# Check memory before high-load test:
redis-cli INFO memory | grep used_memory_human

# Run cache stress test:
for i in {1..100}; do
  curl "http://localhost/wordpress/wp-json/whatsapp-bot/v1/seller/by-phone?phone=50354773"
done

# Check memory after:
redis-cli INFO memory | grep used_memory_human

# Expected: Memory stays relatively flat (cache suspension working)
```

### Step 3: Verify Class Loader (FIX #4)

```php
<?php
// Add to test file temporarily:

// Login to WordPress admin
// Go to Appearance → Theme File Editor
// Add this to functions.php temporarily:

add_action('admin_init', function() {
    // Check if all required classes exist
    $classes = [
        'CWSB_Response',
        'CWSB_Cache',
        'CWSB_Utils',
        'CWSB_Auth_Middleware',
        'CWSB_Seller_Repository',
        'CWSB_Seller_Read_Repository',
        'CWSB_Seller_State_Repository',
        'CWSB_Order_Repository',
        'CWSB_Product_Repository',
        'CWSB_Update_Product_Repository',
        'CWSB_Pin_Service',
        'CWSB_Auth_Cache_Endpoints_Service',
        'CWSB_Auth_Seller_Endpoints_Service',
        'CWSB_Update_Product_Service',
        'CWSB_Auth_Controller',
        'CWSB_Add_Product_Controller',
        'CWSB_Update_Product_Controller',
    ];
    
    foreach ($classes as $class) {
        if (!class_exists($class)) {
            error_log("MISSING CLASS: $class");
        }
    }
    
    error_log("✅ All required classes present!");
});
?>
```

### Step 4: Test Cache Suspension

```bash
# Enable debug logging
# Edit config/constants.php:
define('CWSB_DEBUG_QUERIES', true);
define('CWSB_DEBUG_PERFORMANCE', true);

# Run endpoint:
curl "http://localhost/wordpress/wp-json/whatsapp-bot/v1/seller/by-phone?phone=50354773"

# Check wp-content/debug.log for:
# ✅ "CWSB products page built in XXms"
# ✅ "Suspend WordPress cache"
# ❌ Should NOT see "triple-caching" warnings
```

---

## Phase 2: Staging Deployment (Kinsta Sandbox)

### Step 1: Update Plugin on Kinsta

**Method A: Via SFTP**
```bash
# Download latest from local:
# C:\xampp\htdocs\ILEYCOM\wordpress\wp-content\plugins\custom-whatsapp-seller-bot\

# Upload to Kinsta via SFTP/FileZilla:
# sftp://user@host:/public/wp-content/plugins/custom-whatsapp-seller-bot/

# Or via WordPress admin:
# Dashboard → Plugins → Upload Plugin
# Select zip file from custom-whatsapp-seller-bot/
```

**Method B: Via Git (Recommended)**
```bash
# If using GitHub for plugin repo:
cd C:\xampp\htdocs\ILEYCOM\wordpress\wp-content\plugins\custom-whatsapp-seller-bot

# Commit fixes to GitHub
git add -A
git commit -m "Fix: Implement Kinsta audit findings (cache suspension, nocache headers, class loader, SCAN optimization)"
git push origin main

# On Kinsta server (via SSH):
ssh user@kinsta-staging-server
cd /public/wp-content/plugins/custom-whatsapp-seller-bot
git pull origin main
```

### Step 2: Clear Caches

```bash
# Via Kinsta Dashboard:
# 1. Go to Tools → Cache
# 2. Clear All Caches

# Via WP-CLI:
ssh user@kinsta-staging-server
wp cache flush
wp object-cache flush

# Verify cache backend:
wp redis-cache status
# Expected: "✓ Redis object cache is connected and working"
```

### Step 3: Run Remote API Tests

```bash
# Use Postman or curl against staging URL:
# https://stg-newthemwolmartfromscratch-preprod.kinsta.cloud/

# 1. Test seller lookup (main endpoint)
curl -i "https://stg-newthemwolmartfromscratch-preprod.kinsta.cloud/wp-json/whatsapp-bot/v1/seller/by-phone?phone=50354773" \
  -H "Content-Type: application/json"

# 2. Test with French phone (FIX verification)
curl -i "https://stg-newthemwolmartfromscratch-preprod.kinsta.cloud/wp-json/whatsapp-bot/v1/seller/by-phone?phone=0033782655322" \
  -H "Content-Type: application/json"

# 3. Check cache headers
curl -i -X GET "https://stg-newthemwolmartfromscratch-preprod.kinsta.cloud/wp-json/whatsapp-bot/v1/seller/by-phone?phone=50354773" | grep Cache-Control

# Expected Cache-Control header:
# Cache-Control: no-cache, no-store, must-revalidate, private
```

### Step 4: Monitor Staging Redis

**Via Kinsta Dashboard:**
1. Go to `Kinsta MyKinsta`
2. Select staging environment
3. Click "Analytics" or "Add-ons"
4. Check Redis memory graph
   - **Before fixes:** Sharp climb to 256MB over hours
   - **After fixes:** Steady state at ~80-100MB

**Expected metrics after fixes:**
- Memory stable (not climbing)
- Response times consistent (200-500ms)
- Cache hits > 60% on repeated seller lookups

### Step 5: Test with Load Tool

```bash
# Use Apache Bench or similar:
ab -n 1000 -c 10 \
  "https://stg-newthemwolmartfromscratch-preprod.kinsta.cloud/wp-json/whatsapp-bot/v1/seller/by-phone?phone=50354773"

# Expected:
# - Requests/sec: > 50
# - Failed requests: 0
# - Memory: Stable (not climbing past 256MB)
```

---

## Phase 3: Verify Database Integrity

### Step 1: Backup Production Database

```sql
-- Via Kinsta's PhpMyAdmin or WP-CLI:

wp db export backup-2026-04-06-before-fixes.sql

-- Or manually:
-- Dashboard → WP Admin → Tools → Export
-- Select all content, products, orders, users
```

### Step 2: Check Seller Records

```sql
-- Verify French seller (user 199) exists:
SELECT u.ID, u.display_name, u.user_email
FROM wp_users u
WHERE u.ID = 199;

-- Should return: 199 | French Name | email@example.com

-- Check state table:
SELECT * FROM wp_cwsb_seller_state WHERE user_id = 199;

-- Should have flow_token, reset_token, session_active_until, etc.
```

### Step 3: Verify Phone Formats

```sql
-- Check phone storage formats used by plugin:
SELECT DISTINCT phone FROM wp_cwsb_seller_state LIMIT 10;

-- Examples expected:
-- 8050354773 (Tunisia, 8-digit canonical)
-- 33782655322 (France)
-- 33782655322 (France with 33 prefix)
```

---

## Phase 4: Production Deployment

### Step 1: Schedule Maintenance Window

- Low-traffic time (avoid peak hours)
- 30-minute window
- Communicate to team: "Maintenance: Plugin updates for performance"

### Step 2: Backup Production

```bash
# Via Kinsta SSH:
ssh user@kinsta-production-server

# Full backup:
wp db export backup-2026-04-06-production-before.sql

# Plugin backup:
cp -r /public/wp-content/plugins/custom-whatsapp-seller-bot \
      /public/backups/custom-whatsapp-seller-bot-2026-04-06
```

### Step 3: Deploy Plugin

**Via Git (Recommended):**
```bash
cd /public/wp-content/plugins/custom-whatsapp-seller-bot
git pull origin main
# Run any post-deploy steps:
wp plugin deactivate custom-whatsapp-seller-bot
wp plugin activate custom-whatsapp-seller-bot
```

**Via Manual Upload:**
```bash
# Upload zip file via Kinsta Dashboard
# Or SFTP transfer all files from local
```

### Step 4: Clear Production Caches

```bash
# Via Kinsta Dashboard:
# Tools → Cache → Clear All Caches

# Via WP-CLI:
wp cache flush
wp object-cache flush
wp rewrite flush
```

### Step 5: Verify Endpoints

```bash
# Test main endpoints with real production data:

curl -i "https://newthemwolmartfromscratch.com/wp-json/whatsapp-bot/v1/seller/by-phone?phone=50354773" \
  -H "Authorization: Bearer YOUR_BEARER_TOKEN" \
  -H "Content-Type: application/json"

# Check response:
# - Status: 200 ✅
# - Cache headers present ✅
# - Seller data correct ✅
```

### Step 6: Monitor After Deploy

**First 1 hour:**
- Check error logs: `tail -f wp-content/debug.log`
- Monitor Redis memory (should be stable)
- Test bot functionality manually (send WhatsApp message to trigger endpoints)
- Monitor response times (should be <1s per request)

**First 24 hours:**
- Review daily error logs
- Check Redis memory graph (should not climb past 200MB)
- Verify no seller complaints about stale data
- Confirm bot works end-to-end

---

## Phase 5: Rollback Plan (If Issues)

### Quick Rollback

```bash
# If critical issues within 1 hour of deploy:

# Option 1: Revert to previous plugin version
ssh user@kinsta-production-server
cd /public/wp-content/plugins/custom-whatsapp-seller-bot
git revert HEAD --no-edit
git push origin main

# Option 2: Restore from backup
cp -r /public/backups/custom-whatsapp-seller-bot-2026-04-06 \
      /public/wp-content/plugins/custom-whatsapp-seller-bot

# Restart:
wp plugin deactivate custom-whatsapp-seller-bot
wp plugin activate custom-whatsapp-seller-bot
wp cache flush
```

### Database Rollback

```bash
# If database corruption detected:
wp db import backup-2026-04-06-production-before.sql
wp cache flush
```

---

## Validation Checklist

### Pre-Deployment
- [ ] All fixes verified on local XAMPP
- [ ] Endpoints return correct status codes
- [ ] Cache headers present (no-cache, no-store)
- [ ] Phone normalization works (TN, FR, SN variants)
- [ ] All 17 classes load without errors
- [ ] Memory stable during stress test (1000+ requests)
- [ ] Database backup created

### Post-Deployment
- [ ] Plugin activated on production
- [ ] Test endpoints responding with 200
- [ ] Redis memory stable (not climbing)
- [ ] No errors in wp-content/debug.log
- [ ] Manual bot test successful (send WhatsApp message)
- [ ] Cache-Control headers verified
- [ ] Team notified of completion

---

## Monitoring & Maintenance

### Daily (First Week)
- Check `wp-content/debug.log` for errors
- Verify Redis memory from Kinsta dashboard
- Test one endpoint manually

### Weekly
- Review weekly error logs
- Check Redis memory trends (should plateau)
- Verify bot automation is functioning

### Monthly
- Review performance metrics
- Update cache TTLs if needed (based on usage patterns)
- Plan next optimization phase

---

## Support & Troubleshooting

### If Endpoints Return 500 Error
```bash
# 1. Check error log:
tail -f wp-content/debug.log

# 2. Verify all classes loaded:
wp eval 'echo class_exists("CWSB_Seller_Repository") ? "✅" : "❌";'

# 3. Check REST API is enabled:
wp rest-api list
```

### If Memory Still Exhausted at 256MB
```bash
# 1. Verify cache suspension is working:
grep "Suspend WordPress cache" wp-content/debug.log

# 2. Check if CWSB_DISABLE_PLUGIN_CACHE is set somewhere:
grep -r "CWSB_DISABLE_PLUGIN_CACHE" .

# 3. Reduce cache TTL:
define('CWSB_CACHE_TTL', 30);  # Down from 60
```

### If Seller Data is Stale
```bash
# 1. Verify nocache headers are sent:
curl -i "https://.../seller/by-phone?phone=50354773" | grep Cache-Control

# 2. Clear browser cache manually
# 3. Test incognito window to rule out browser caching
```

---

## Next Optimization Phases

### Phase 2 (Future: 2026-Q2)
- [ ] Move endpoints to separate API resource files (sellers/, products/, orders/)
- [ ] Add models/ folder with data shape validation
- [ ] Implement automated unit tests (tests/unit/)
- [ ] Setup integration tests with WordPress test suite

### Phase 3 (Future: 2026-Q3)
- [ ] Add database migrations (database/migrations/)
- [ ] Implement API versioning (v2 endpoints)
- [ ] Add request/response logging middleware
- [ ] Setup performance profiling hooks

---

**Created:** 2026-04-06  
**Last Updated:** 2026-04-06  
**Status:** Ready for Staging Deployment
