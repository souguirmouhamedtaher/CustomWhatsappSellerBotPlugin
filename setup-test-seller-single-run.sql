-- Single-run setup for testing WhatsApp Seller Bot with an existing WP user
-- Works with default table prefix: wp_
-- Run in phpMyAdmin SQL tab or MySQL client.

-- ======================
-- 1) EDIT THESE VALUES
-- ======================
SET @target_email = 'sarra@example.com';      -- Existing WP user email to convert/use for test
SET @wa_phone = '21611222333';                  -- WhatsApp phone digits only
SET @source_seller_id = 0;                      -- Existing seller user_id to copy capabilities/products from (0 = skip)
SET @reassign_products = 1;   
SET @target_user_id = (
  SELECT ID FROM wp_users WHERE user_email = @target_email LIMIT 1
);

SELECT 'target_user_id' AS label, @target_user_id AS value;

-- ======================
-- 3) UPDATE/INSERT PHONE METAS
-- ======================
UPDATE wp_usermeta
SET meta_value = @wa_phone
WHERE @target_user_id > 0
  AND user_id = @target_user_id
  AND meta_key IN ('billing_phone', 'phone', 'wcfm_phone');

INSERT INTO wp_usermeta (user_id, meta_key, meta_value)
SELECT @target_user_id, 'billing_phone', @wa_phone
WHERE @target_user_id > 0
  AND NOT EXISTS (
    SELECT 1
    FROM wp_usermeta
    WHERE user_id = @target_user_id AND meta_key = 'billing_phone'
  );

INSERT INTO wp_usermeta (user_id, meta_key, meta_value)
SELECT @target_user_id, 'phone', @wa_phone
WHERE @target_user_id > 0
  AND NOT EXISTS (
    SELECT 1
    FROM wp_usermeta
    WHERE user_id = @target_user_id AND meta_key = 'phone'
  );

INSERT INTO wp_usermeta (user_id, meta_key, meta_value)
SELECT @target_user_id, 'wcfm_phone', @wa_phone
WHERE @target_user_id > 0
  AND NOT EXISTS (
    SELECT 1
    FROM wp_usermeta
    WHERE user_id = @target_user_id AND meta_key = 'wcfm_phone'
  );

-- ======================
-- 4) COPY SELLER CAPABILITIES (OPTIONAL)
-- ======================
-- Copies wp_capabilities from an existing seller account if @source_seller_id > 0
UPDATE wp_usermeta t
JOIN wp_usermeta s
  ON s.user_id = @source_seller_id
 AND s.meta_key = 'wp_capabilities'
SET t.meta_value = s.meta_value
WHERE @target_user_id > 0
  AND @source_seller_id > 0
  AND t.user_id = @target_user_id
  AND t.meta_key = 'wp_capabilities';

INSERT INTO wp_usermeta (user_id, meta_key, meta_value)
SELECT @target_user_id, 'wp_capabilities', s.meta_value
FROM wp_usermeta s
WHERE @target_user_id > 0
  AND @source_seller_id > 0
  AND s.user_id = @source_seller_id
  AND s.meta_key = 'wp_capabilities'
  AND NOT EXISTS (
    SELECT 1
    FROM wp_usermeta t
    WHERE t.user_id = @target_user_id
      AND t.meta_key = 'wp_capabilities'
  );

-- ======================
-- 5) UPSERT SELLER STATE FOR BOT
-- ======================
SET @flow_token = CONCAT('flowtoken-', @wa_phone, '-', ROUND(UNIX_TIMESTAMP(NOW(3)) * 1000));

INSERT INTO wp_cwsb_seller_state
(user_id, name, email, phone, flow_token, code, reset_token, reset_token_expiry, session_active_until, created_at, updated_at)
SELECT u.ID, u.display_name, u.user_email, @wa_phone, @flow_token, NULL, NULL, NULL, NULL, NOW(), NOW()
FROM wp_users u
WHERE @target_user_id > 0
  AND u.ID = @target_user_id
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  email = VALUES(email),
  phone = VALUES(phone),
  flow_token = VALUES(flow_token),
  updated_at = NOW();

-- ======================
-- 6) OPTIONAL: REASSIGN PRODUCTS FOR ORDERS/PRODUCTS TEST
-- ======================
UPDATE wp_posts
SET post_author = @target_user_id
WHERE @target_user_id > 0
  AND @source_seller_id > 0
  AND @reassign_products = 1
  AND post_type = 'product'
  AND post_author = @source_seller_id;

-- ======================
-- 7) VERIFICATION OUTPUT
-- ======================
SELECT 'resolved_user' AS section, ID, user_login, user_email
FROM wp_users
WHERE ID = @target_user_id;

SELECT 'usermeta_phone_and_caps' AS section, meta_key, meta_value
FROM wp_usermeta
WHERE user_id = @target_user_id
  AND meta_key IN ('billing_phone', 'phone', 'wcfm_phone', 'wp_capabilities');

SELECT 'seller_state' AS section, user_id, name, email, phone, flow_token, session_active_until
FROM wp_cwsb_seller_state
WHERE user_id = @target_user_id;

SELECT 'summary' AS section,
       @target_user_id AS target_user_id,
       @source_seller_id AS source_seller_id,
       @reassign_products AS reassigned_products,
       @flow_token AS generated_flow_token;
