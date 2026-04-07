# Custom WhatsApp Seller Bot Plugin Documentation

Complete guide to the WhatsApp bot plugin architecture, APIs, and operations.

## Quick Navigation

### For Developers
- **[ARCHITECTURE.md](./ARCHITECTURE.md)** — System design, patterns, data flow
- **[API_REFERENCE.md](./API_REFERENCE.md)** — All REST endpoints, parameters, responses
- **[CACHING_STRATEGY.md](./CACHING_STRATEGY.md)** — Cache layers, TTLs, invalidation

### For DevOps/Operators
- **[MIGRATION_GUIDE.md](./MIGRATION_GUIDE.md)** — Deployment, testing, monitoring
- **[TROUBLESHOOTING.md](./TROUBLESHOOTING.md)** — Common issues and solutions

### For Product/Design
- **[PHONE_NORMALIZATION.md](./PHONE_NORMALIZATION.md)** — Phone number formats (TN, FR, SN)

---

## Recent Updates (2026-04-06)

### Critical Fixes Implemented
✅ **Memory Exhaustion** — Cache suspension in repositories (FIX #1-3)  
✅ **Stale Data** — Nocache headers on all endpoints (FIX #5)  
✅ **Missing Classes** — All 17 classes now in loader (FIX #4)  
✅ **Redis Blocking** — SCAN instead of KEYS command (FIX #6)  

### Expected Improvements
- Redis memory: Stable at ~80MB (was exhausting 256MB)
- Cache hits: >60% on repeated requests
- Response times: Consistent 200-500ms
- Data freshness: Always current (nocache headers)

---

## Key Resources

**Configuration:** `config/constants.php`  
**Classes:** `includes/` (repositories, services, utilities, controllers)  
**Tests:** `tests/` (unit and integration tests)  
**Scripts:** `scripts/` (SQL utilities, setup helpers)

---

## Getting Started

### Local Development (XAMPP)
```bash
# Plugin is at:
C:\xampp\htdocs\ILEYCOM\wordpress\wp-content\plugins\custom-whatsapp-seller-bot

# Test endpoints:
curl http://localhost/wordpress/wp-json/whatsapp-bot/v1/seller/by-phone?phone=50354773
```

### Staging (Kinsta)
```bash
# URL: https://stg-newthemwolmartfromscratch-preprod.kinsta.cloud

# Deploy via Git:
git push origin main

# Or SFTP to /public/wp-content/plugins/custom-whatsapp-seller-bot/
```

### Production (Kinsta)
See [MIGRATION_GUIDE.md](./MIGRATION_GUIDE.md) for step-by-step deployment.

---

## Common Tasks

### Adding a New Endpoint
1. Create handler in `includes/services/class-cwsb-*-service.php`
2. Register route in `includes/controllers/class-cwsb-*-controller.php`
3. Add `prevent_response_caching()` to method
4. Document in [API_REFERENCE.md](./API_REFERENCE.md)

### Debugging Cache Issues
See [CACHING_STRATEGY.md](./CACHING_STRATEGY.md) → "Debugging & Diagnostics"

### Deploying Updates
See [MIGRATION_GUIDE.md](./MIGRATION_GUIDE.md) → "Phase 2: Staging Deployment"

---

## Support

**Issue?** Check [TROUBLESHOOTING.md](./TROUBLESHOOTING.md)  
**Question?** See [ARCHITECTURE.md](./ARCHITECTURE.md) for design details  
**API help?** Refer to [API_REFERENCE.md](./API_REFERENCE.md)

---

**Version:** 1.0.0 (with 2026-04-06 fixes)  
**Maintainer:** ILEYCOM Internships  
**Last Update:** 2026-04-06
