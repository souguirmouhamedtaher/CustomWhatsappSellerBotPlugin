<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Auth_Seller_Core_Service')) {
    require_once __DIR__ . '/class-cwsb-auth-seller-core-service.php';
}

if (!class_exists('CWSB_Auth_Product_Endpoints_Service')) {
    require_once __DIR__ . '/class-cwsb-auth-product-endpoints-service.php';
}

if (!class_exists('CWSB_Auth_Order_Endpoints_Service')) {
    require_once __DIR__ . '/class-cwsb-auth-order-endpoints-service.php';
}

if (!class_exists('CWSB_Auth_Wallet_Endpoints_Service')) {
    require_once __DIR__ . '/class-cwsb-auth-wallet-endpoints-service.php';
}

/**
 * Backward-compatible facade for auth endpoint handlers.
 */
class CWSB_Auth_Seller_Endpoints_Service
{
    /**
     * Proxies seller-list requests to the core seller service implementation.
     *
     * This facade method keeps backward compatibility for callers while forwarding the
     * WordPress `WP_REST_Request` object unchanged to the domain service that handles
     * pagination, repository access, and response shaping.
     */
    public static function get_all_sellers(WP_REST_Request $request)
    {
        return CWSB_Auth_Seller_Core_Service::get_all_sellers($request);
    }

    /**
     * Proxies seller-by-phone lookup requests to the core seller service layer.
     *
     * The method exists as a stable facade entrypoint and delegates all validation,
     * repository resolution, and response formatting to the underlying core handler.
     */
    public static function get_seller_by_phone(WP_REST_Request $request)
    {
        return CWSB_Auth_Seller_Core_Service::get_seller_by_phone($request);
    }

    /**
     * Proxies seller-state-by-phone requests to the core seller service layer.
     *
     * This wrapper preserves legacy call paths while forwarding the WordPress REST request
     * to logic that resolves seller state and vendor-existence metadata.
     */
    public static function get_seller_state_by_phone(WP_REST_Request $request)
    {
        return CWSB_Auth_Seller_Core_Service::get_seller_state_by_phone($request);
    }

    /**
     * Proxies seller-by-flow-token requests to the core seller service handler.
     *
     * The facade keeps route-to-service mapping stable and delegates all token lookup logic
     * to the core service without mutating request parameters.
     */
    public static function get_seller_by_flow_token(WP_REST_Request $request)
    {
        return CWSB_Auth_Seller_Core_Service::get_seller_by_flow_token($request);
    }

    /**
     * Proxies seller code-update requests to the core seller service.
     *
     * This method acts as a compatibility layer and forwards the incoming REST request to
     * logic that validates required fields and persists seller code changes.
     */
    public static function update_seller_code(WP_REST_Request $request)
    {
        return CWSB_Auth_Seller_Core_Service::update_seller_code($request);
    }

    /**
     * Proxies seller-state insertion requests to the core seller service.
     *
     * The method delegates full state assembly and persistence behavior to the core handler,
     * including optional flow/session/reset metadata fields.
     */
    public static function insert_seller_state(WP_REST_Request $request)
    {
        return CWSB_Auth_Seller_Core_Service::insert_seller_state($request);
    }

    /**
     * Proxies seller session-activation requests to the core seller service.
     *
     * This wrapper forwards flow token and session expiry context to logic that updates
     * seller session state in persistence.
     */
    public static function activate_seller_session(WP_REST_Request $request)
    {
        return CWSB_Auth_Seller_Core_Service::activate_seller_session($request);
    }

    /**
     * Proxies seller session-deactivation requests to the core seller service.
     *
     * The method preserves existing facade API signatures while delegating state reset logic
     * to the core service implementation.
     */
    public static function deactivate_seller_session(WP_REST_Request $request)
    {
        return CWSB_Auth_Seller_Core_Service::deactivate_seller_session($request);
    }

    /**
     * Proxies pre-expiry auth-pending seller listing requests to the core service.
     *
     * It forwards pagination and lead-time parameters so the core layer can compute the
     * candidate seller set for reminder workflows.
     */
    public static function get_pre_expiry_auth_pending_sellers(WP_REST_Request $request)
    {
        return CWSB_Auth_Seller_Core_Service::get_pre_expiry_auth_pending_sellers($request);
    }

    /**
     * Proxies auth-portal notification marking requests to the core seller service.
     *
     * This facade keeps endpoint wiring stable while delegating timestamp validation and
     * persistence updates to the core implementation.
     */
    public static function mark_auth_portal_sent(WP_REST_Request $request)
    {
        return CWSB_Auth_Seller_Core_Service::mark_auth_portal_sent($request);
    }

    /**
     * Proxies seller reset-token assignment requests to the core seller service.
     *
     * The method forwards the REST request unchanged so the core layer can validate required
     * fields and persist password-reset token state.
     */
    public static function set_seller_reset_token(WP_REST_Request $request)
    {
        return CWSB_Auth_Seller_Core_Service::set_seller_reset_token($request);
    }

    /**
     * Proxies seller product-list requests to the product endpoints service.
     *
     * This wrapper preserves historical class contracts while forwarding paging and identity
     * parameters to product-domain handlers.
     */
    public static function get_seller_products_by_flow_token(WP_REST_Request $request)
    {
        return CWSB_Auth_Product_Endpoints_Service::get_seller_products_by_flow_token($request);
    }

    /**
     * Proxies seller XOF product-list requests to the product endpoints service.
     *
     * The facade keeps dedicated XOF route callbacks stable and delegates actual retrieval/mapping
     * behavior to the product service layer.
     */
    public static function get_seller_products_by_flow_token_xof(WP_REST_Request $request)
    {
        return CWSB_Auth_Product_Endpoints_Service::get_seller_products_by_flow_token_xof($request);
    }

    /**
     * Proxies seller product-by-id requests to the product endpoints service.
     *
     * This wrapper allows controller callbacks to remain anchored to the seller facade while
     * product-specific repository access is handled in the product service.
     */
    public static function get_seller_product_by_id(WP_REST_Request $request)
    {
        return CWSB_Auth_Product_Endpoints_Service::get_seller_product_by_id($request);
    }

    /**
     * Proxies seller XOF product-by-id requests to the product endpoints service.
     *
     * It forwards the request to XOF-aware mapping logic and maintains backward-compatible
     * facade semantics for existing route registrations.
     */
    public static function get_seller_product_by_id_xof(WP_REST_Request $request)
    {
        return CWSB_Auth_Product_Endpoints_Service::get_seller_product_by_id_xof($request);
    }

    /**
     * Proxies seller product-variation requests to the product endpoints service.
     *
     * The method forwards product and variation identifiers for repository-level variation
     * resolution and response formatting.
     */
    public static function get_seller_product_variation_by_id(WP_REST_Request $request)
    {
        return CWSB_Auth_Product_Endpoints_Service::get_seller_product_variation_by_id($request);
    }

    /**
     * Proxies seller XOF product-variation requests to the product endpoints service.
     *
     * This facade method provides a stable callback target for XOF variation routes while
     * delegating value mapping to XOF-aware product handlers.
     */
    public static function get_seller_product_variation_by_id_xof(WP_REST_Request $request)
    {
        return CWSB_Auth_Product_Endpoints_Service::get_seller_product_variation_by_id_xof($request);
    }

    /**
     * Proxies seller order-list requests to the order endpoints service.
     *
     * The wrapper preserves facade-level API compatibility and delegates full order retrieval
     * behavior to order-domain handlers.
     */
    public static function get_seller_orders_by_flow_token(WP_REST_Request $request)
    {
        return CWSB_Auth_Order_Endpoints_Service::get_seller_orders_by_flow_token($request);
    }

    /**
     * Proxies seller order-counter requests to the order endpoints service.
     *
     * The method forwards flow-token context to logic that aggregates status counters for
     * seller order dashboards.
     */
    public static function get_seller_order_counters_by_flow_token(WP_REST_Request $request)
    {
        return CWSB_Auth_Order_Endpoints_Service::get_seller_order_counters_by_flow_token($request);
    }

    /**
     * Proxies seller paged order-list requests to the order endpoints service.
     *
     * This wrapper forwards filter and pagination parameters to order service logic that
     * computes paged summaries.
     */
    public static function get_seller_order_list_by_flow_token(WP_REST_Request $request)
    {
        return CWSB_Auth_Order_Endpoints_Service::get_seller_order_list_by_flow_token($request);
    }

    /**
     * Proxies seller order-detail requests to the order endpoints service.
     *
     * The facade keeps controller wiring stable while repository-backed detail resolution is
     * performed by order-domain handlers.
     */
    public static function get_seller_order_by_id(WP_REST_Request $request)
    {
        return CWSB_Auth_Order_Endpoints_Service::get_seller_order_by_id($request);
    }

    /**
     * Proxies seller order-articles requests to the order endpoints service.
     *
     * The method forwards article pagination context and lets order handlers enforce ownership
     * checks and payload normalization.
     */
    public static function get_seller_order_articles_by_id(WP_REST_Request $request)
    {
        return CWSB_Auth_Order_Endpoints_Service::get_seller_order_articles_by_id($request);
    }

    /**
     * Proxies seller wallet-balance requests to the wallet endpoints service.
     *
     * The facade delegates wallet assembly and not-found handling to the wallet-domain
     * implementation without modifying request payload.
     */
    public static function get_seller_wallet_by_flow_token(WP_REST_Request $request)
    {
        return CWSB_Auth_Wallet_Endpoints_Service::get_seller_wallet_by_flow_token($request);
    }

    /**
     * Proxies seller wallet-summary requests to the wallet endpoints service.
     *
     * This wrapper routes summary retrieval calls to wallet-domain handlers while preserving
     * the historical service entrypoint used by controllers.
     */
    public static function get_seller_wallet_summary_by_flow_token(WP_REST_Request $request)
    {
        return CWSB_Auth_Wallet_Endpoints_Service::get_seller_wallet_summary_by_flow_token($request);
    }

    /**
     * Proxies seller wallet-transactions requests to the wallet endpoints service.
     *
     * The method forwards pagination and identity parameters to wallet-domain logic that builds
     * transaction pages for seller finance views.
     */
    public static function get_seller_wallet_transactions_by_flow_token(WP_REST_Request $request)
    {
        return CWSB_Auth_Wallet_Endpoints_Service::get_seller_wallet_transactions_by_flow_token($request);
    }
}
