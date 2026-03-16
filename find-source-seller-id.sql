-- Standalone helper: find source seller user_id candidates
-- Run this first, then copy chosen seller_user_id into @source_seller_id
-- in setup-test-seller-single-run.sql

-- Optional filters (leave '' to disable a filter)
SET @source_seller_email = '';      -- e.g. 'seller@example.com'
SET @source_seller_phone = '';      -- e.g. '21612345678' (digits only)

SELECT
  u.ID AS seller_user_id,
  u.user_login,
  u.user_email,
  COALESCE(MAX(CASE WHEN um_phone.meta_key = 'billing_phone' THEN um_phone.meta_value END), '') AS billing_phone,
  COALESCE(MAX(CASE WHEN um_phone.meta_key = 'phone' THEN um_phone.meta_value END), '') AS phone,
  COALESCE(MAX(CASE WHEN um_phone.meta_key = 'wcfm_phone' THEN um_phone.meta_value END), '') AS wcfm_phone,
  COUNT(DISTINCT p.ID) AS products_count
FROM wp_users u
INNER JOIN wp_usermeta caps
  ON caps.user_id = u.ID
 AND caps.meta_key = 'wp_capabilities'
LEFT JOIN wp_usermeta um_phone
  ON um_phone.user_id = u.ID
 AND um_phone.meta_key IN ('billing_phone', 'phone', 'wcfm_phone')
LEFT JOIN wp_posts p
  ON p.post_author = u.ID
 AND p.post_type = 'product'
WHERE caps.meta_value LIKE '%"wcfm_vendor"%'
  AND (
    @source_seller_email = ''
    OR LOWER(u.user_email) = LOWER(@source_seller_email)
  )
  AND (
    @source_seller_phone = ''
    OR REPLACE(REPLACE(REPLACE(COALESCE(um_phone.meta_value, ''), '+', ''), ' ', ''), '-', '') = @source_seller_phone
    OR RIGHT(REPLACE(REPLACE(REPLACE(COALESCE(um_phone.meta_value, ''), '+', ''), ' ', ''), '-', ''), 8) = RIGHT(@source_seller_phone, 8)
  )
GROUP BY u.ID, u.user_login, u.user_email
ORDER BY products_count DESC, u.ID DESC;
