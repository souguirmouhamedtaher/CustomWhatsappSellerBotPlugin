# Phone Formats Audit (Full Repository Scan)

## Scope and Method
- Scan scope: all non-.git files in repository.
- Files scanned: 49.
- Total lines scanned: 9037.
- Method:
  - File inventory and line counts generated from repository root.
  - File-by-file review performed for all PHP files, tests, and docs.
  - Phone format conclusions are based only on implementation code and unit tests.
  - No inferred country support beyond explicit code branches.

## Source of Truth for Accepted Phone Formats
Primary implementation is in:
- includes/utilities/class-cwsb-utils.php:220

Supporting executable evidence is in:
- tests/unit/run.php:403
- tests/unit/run.php:410
- tests/unit/run.php:417
- tests/unit/run.php:423
- tests/unit/run.php:428

## Core Normalization Rules
From includes/utilities/class-cwsb-utils.php:220-265:

1. Input is reduced to digits only.
- Rule: `preg_replace('/\\D+/', '', (string) $phone)`.
- Consequence: spaces, plus signs, dashes, parentheses, and other non-digits are ignored before validation.

2. Tunisia support (canonical output: 216 + 8 digits).
- `00216` + 8 digits (13 total) -> strip leading `00`.
- `216` + 8 digits (11 total) -> accepted as-is.
- 8 digits local -> prefixed to `216`.

3. France support (canonical output: 33 + 9 digits).
- `0033` + 9 digits (13 total) -> strip leading `00`.
- `33` + 9 digits (11 total) -> accepted as-is.
- 10-digit local starting with `0` -> replaced with `33` + remaining 9 digits.

4. Everything else is rejected.
- Function returns empty string.
- Explicitly documented by comment as rejecting unsupported countries rather than guessing.

## Accepted Input Formats (Code-Backed)

| Input family | Accepted examples | Canonical stored/compared output | Evidence |
|---|---|---|---|
| Tunisia local | `50354773` | `21650354773` | includes/utilities/class-cwsb-utils.php:237-239, tests/unit/run.php:404 |
| Tunisia intl plus | `+21650354773` | `21650354773` | includes/utilities/class-cwsb-utils.php:228-233, tests/unit/run.php:405 |
| Tunisia intl 00 | `0021650354773` | `21650354773` | includes/utilities/class-cwsb-utils.php:228-231, tests/unit/run.php:406 |
| Tunisia canonical | `21650354773` | `21650354773` | includes/utilities/class-cwsb-utils.php:233-235, tests/unit/run.php:407 |
| France intl plus | `+33782655322` | `33782655322` | includes/utilities/class-cwsb-utils.php:248-253, tests/unit/run.php:411 |
| France canonical | `33782655322` | `33782655322` | includes/utilities/class-cwsb-utils.php:253-255, tests/unit/run.php:412 |
| France intl 00 | `0033782655322` | `33782655322` | includes/utilities/class-cwsb-utils.php:248-251, tests/unit/run.php:413 |
| France local 0-prefix | `0782655322` | `33782655322` | includes/utilities/class-cwsb-utils.php:257-259, tests/unit/run.php:414 |

## Rejected/Invalid Inputs (Code-Backed)

| Input | Result | Why |
|---|---|---|
| `123` | empty string | no supported branch matched | tests/unit/run.php:418 |
| empty string | empty string | digits normalization produces empty | includes/utilities/class-cwsb-utils.php:222-225, tests/unit/run.php:419 |
| `abc` | empty string | digits normalization produces empty | includes/utilities/class-cwsb-utils.php:222-225, tests/unit/run.php:420 |
| unsupported country formats | empty string | explicit reject branch | includes/utilities/class-cwsb-utils.php:261-263 |

## Flow Token Phone Extraction Format
From includes/utilities/class-cwsb-utils.php:267-278:

- Required pattern: `^flowtoken-(.+)-\d+$`
- Extracted segment: middle component between `flowtoken-` and trailing numeric suffix.
- Extracted value is passed through `normalize_phone`.

Validated in tests:
- `flowtoken-50354773-1234567890` -> `21650354773` (tests/unit/run.php:424)
- `flowtoken-21650354773-9999` -> `21650354773` (tests/unit/run.php:425)
- Invalid tokens return empty (tests/unit/run.php:429-431)

## Where Phone Inputs Are Accepted (API Surface)
From includes/controllers/auth/class-cwsb-auth-controller.php:

Direct `phone` parameter endpoints:
- `/seller/by-phone` (POST) at includes/controllers/auth/class-cwsb-auth-controller.php:32-37
- `/seller/state/by-phone` (POST) at includes/controllers/auth/class-cwsb-auth-controller.php:39-44
- `/seller/state/insert` (POST) at includes/controllers/auth/class-cwsb-auth-controller.php:63-71
- `/seller/session/mark-auth-portal-sent` (POST) at includes/controllers/auth/class-cwsb-auth-controller.php:103-111
- `/seller/products/by-flow-token` accepts optional `phone` or `flow_token` at includes/controllers/auth/class-cwsb-auth-controller.php:124-133

Flow-token endpoints that can indirectly resolve phone:
- `/seller/by-flow-token` at includes/controllers/auth/class-cwsb-auth-controller.php:46-51
- Add-product flow fallback extraction at includes/services/add-product/class-cwsb-add-product-actions-service.php:249-253

## Storage and Matching Behavior

State table schema:
- `phone VARCHAR(50) NOT NULL` in custom-whatsapp-seller-bot.php:76
- indexed by `KEY phone (phone)` in custom-whatsapp-seller-bot.php:88

Write-path normalization:
- Phone normalized before save/update in includes/repositories/seller/class-cwsb-seller-state-writer.php:53-54
- Required identity fields normalized before insert in includes/repositories/seller/class-cwsb-seller-state-writer.php:117-120
- Insert-by-phone rejects non-normalizable input in includes/repositories/seller/class-cwsb-seller-state-writer.php:147-149

Comparison variants builder:
- includes/utilities/class-cwsb-utils.php:282-321 creates canonical/local/00/+ and suffix references.

State-table lookup strategy:
- exact `IN (...)` plus normalized `REPLACE(...)` plus suffix `RIGHT(...)` in includes/repositories/seller/class-cwsb-seller-state-queries.php:30-42
- same strategy for row fetch in includes/repositories/seller/class-cwsb-seller-state-queries.php:57-69

Vendor lookup strategy:
- exact lookup in usermeta keys `billing_phone`, `phone`, `wcfm_phone` in includes/repositories/seller/class-cwsb-seller-vendor-queries.php:35-36
- normalized fallback using `REPLACE(...)` + suffix in includes/repositories/seller/class-cwsb-seller-vendor-queries.php:72-73

## Full-Scan Coverage Notes

All non-.git files were included in scan coverage.
PHP files with no phone/flow-token handling tokens (classified non-phone-relevant):
- config/constants.php
- includes/middleware/class-cwsb-auth-middleware.php
- includes/repositories/order/class-cwsb-order-mapper.php
- includes/repositories/order/class-cwsb-order-queries.php
- includes/repositories/product/class-cwsb-product-mapper.php
- includes/repositories/product/class-cwsb-product-queries.php
- includes/repositories/update-product/class-cwsb-update-product-queries.php
- includes/repositories/update-product/class-cwsb-update-product-repository.php
- includes/services/add-product/class-cwsb-add-product-support-service.php
- includes/services/auth/class-cwsb-pin-service.php
- includes/utilities/class-cwsb-logger.php
- includes/utilities/class-cwsb-plugin-updater.php
- includes/utilities/class-cwsb-response.php

## Consistency/Risk Findings (Evidence-Based)

Potential mismatch in two fallback resolvers:
- includes/repositories/order/class-cwsb-order-resolver.php:55-56 references `$refs['local8']` and `$refs['legacy216']`.
- includes/repositories/product/class-cwsb-product-resolver.php:41-42 references `$refs['local8']` and `$refs['legacy216']`.
- Current `phone_comparison_refs` returns keys: `canonical`, `local`, `legacy`, `intl00`, `intl_plus`, `suffix`, `suffix_length` (includes/utilities/class-cwsb-utils.php:287-320).

This means those two fallback paths reference keys not defined by current helper contract.
It does not change accepted input formats, but it may affect some phone fallback resolution behavior at runtime.

## Final Answer to "What phone numbers and formats are accepted?"
Accepted formats are exactly the Tunisia and France patterns implemented in `normalize_phone`:
- Tunisia: local 8-digit, `216...`, `+216...`, `00216...`
- France: local `0...` (10-digit), `33...`, `+33...`, `0033...`
- Any non-digit separators are tolerated because inputs are digit-normalized first.
- Other country formats are rejected (empty normalization result).
