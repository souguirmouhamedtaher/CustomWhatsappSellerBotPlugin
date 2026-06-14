<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Response')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-response.php';
}

if (!class_exists('CWSB_Seller_Repository')) {
    require_once __DIR__ . '/../../repositories/seller/class-cwsb-seller-repository.php';
}

/**
 * Seller-related endpoint handlers.
 */
class CWSB_Auth_Seller_Core_Service
{
    /**
     * Applies anti-cache headers and cache-bypass flags for dynamic auth responses.
     *
     * The method calls the WordPress native `nocache_headers()` helper and sets common
     * no-cache constants so seller/session state endpoints are not cached by page-cache
     * plugins or intermediate proxies.
     */
    public static function prevent_response_caching()
    {
        nocache_headers();
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!defined('DONOTCACHEDB')) {
            define('DONOTCACHEDB', true);
        }
    }

    /**
     * Returns a paginated list of sellers from repository storage.
     *
     * It reads pagination values from `WP_REST_Request`, enforces safe bounds, then delegates
     * retrieval/count operations to repository methods before returning a standardized JSON
     * payload via the shared response utility.
     */
    public static function get_all_sellers(WP_REST_Request $request)
    {
        self::prevent_response_caching();
        $page = max(1, (int) $request->get_param('page'));
        $per_page = (int) $request->get_param('per_page');
        if ($per_page <= 0) {
            $per_page = 50;
        }
        $per_page = min($per_page, 200);

        $rows = CWSB_Seller_Repository::get_all_sellers($page, $per_page);
        $total = CWSB_Seller_Repository::count_all_sellers();

        return CWSB_Response::ok([
            'page' => $page,
            'per_page' => $per_page,
            'total' => (int) $total,
            'count' => is_array($rows) ? count($rows) : 0,
            'sellers' => is_array($rows) ? $rows : [],
        ]);
    }

    /**
     * Returns seller information resolved by phone number.
     *
     * The method accepts a WordPress REST request, delegates lookup to the seller repository,
     * and always returns a consistent `seller` field shape (`null` when not found).
     */
    public static function get_seller_by_phone(WP_REST_Request $request)
    {
        self::prevent_response_caching();
        $phone = (string) $request->get_param('phone');
        $seller = CWSB_Seller_Repository::find_vendor_by_phone($phone);

        return CWSB_Response::ok([
            'seller' => $seller ?: null,
        ]);
    }

    /**
     * Returns seller state row and vendor-existence flag for a phone number.
     *
     * After retrieving state data from repository storage, this method performs a vendor role
     * existence check and returns both artifacts so upstream clients can decide auth flow steps.
     */
    public static function get_seller_state_by_phone(WP_REST_Request $request)
    {
        self::prevent_response_caching();
        $phone = (string) $request->get_param('phone');
        $seller = CWSB_Seller_Repository::find_state_seller_by_phone($phone);

        if ($seller && !empty($seller['user_id'])) {
            $vendor_exists = CWSB_Seller_Vendor_Queries::check_user_is_vendor((int) $seller['user_id']);
        } else {
            $vendor_exists = false;
        }

        return CWSB_Response::ok([
            'seller'        => $seller ?: null,
            'vendor_exists' => $vendor_exists,
        ]);
    }

    /**
     * Returns seller information resolved by flow token.
     *
     * The method delegates token lookup to repository logic and wraps the result in the standard
     * response envelope expected by auth flow consumers.
     */
    public static function get_seller_by_flow_token(WP_REST_Request $request)
    {
        self::prevent_response_caching();
        $flow_token = (string) $request->get_param('flow_token');
        $seller = CWSB_Seller_Repository::find_vendor_by_flow_token($flow_token);

        return CWSB_Response::ok([
            'seller' => $seller ?: null,
        ]);
    }

    /**
     * Updates the seller verification code associated with a flow token.
     *
     * It validates required request fields, delegates persistence to the repository layer, and
     * returns either a validation error or a normalized success payload.
     */
    public static function update_seller_code(WP_REST_Request $request)
    {
        self::prevent_response_caching();
        $flow_token = (string) $request->get_param('flow_token');
        $code = (string) $request->get_param('code');

        if (trim($flow_token) === '' || trim($code) === '') {
            return CWSB_Response::error('invalid_request', 'flow_token and code are required.', 422);
        }

        $seller = CWSB_Seller_Repository::update_seller_code_by_flow_token($flow_token, $code);

        return CWSB_Response::ok([
            'seller' => $seller ?: null,
        ]);
    }

    /**
     * Inserts or updates seller auth-state data using phone as identity anchor.
     *
     * The method builds a state payload from optional request fields (token/session/reset metadata)
     * and delegates write logic to repository code that manages seller-state records.
     */
    public static function insert_seller_state(WP_REST_Request $request)
    {
        self::prevent_response_caching();
        $phone = (string) $request->get_param('phone');
        $flow_token = (string) $request->get_param('flow_token');
        $code_param = $request->get_param('code');

        if (trim($phone) === '') {
            return CWSB_Response::error('invalid_request', 'phone is required.', 422);
        }

        $state = [
            'flow_token' => $flow_token,
            'reset_token' => $request->get_param('reset_token'),
            'reset_token_expiry' => $request->get_param('reset_token_expiry'),
            'session_active_until' => $request->get_param('session_active_until'),
            'auth_portal_sent_at' => $request->get_param('auth_portal_sent_at'),
        ];

        if ($code_param !== null) {
            $state['code'] = (string) $code_param;
        }

        $seller = CWSB_Seller_Repository::insert_seller_state_by_phone($phone, $state);

        return CWSB_Response::ok([
            'seller' => $seller ?: null,
        ]);
    }

    /**
     * Activates a seller session by setting a positive session expiry timestamp.
     *
     * It validates required parameters from the WordPress REST request and delegates timestamp
     * persistence to repository logic for flow-token-scoped sessions.
     */
    public static function activate_seller_session(WP_REST_Request $request)
    {
        self::prevent_response_caching();
        $flow_token = (string) $request->get_param('flow_token');
        $session_active_until = (int) $request->get_param('session_active_until');

        if (trim($flow_token) === '' || $session_active_until <= 0) {
            return CWSB_Response::error('invalid_request', 'flow_token and session_active_until are required.', 422);
        }

        $seller = CWSB_Seller_Repository::set_session_active_until_by_flow_token($flow_token, $session_active_until);
        return CWSB_Response::ok(['seller' => $seller ?: null]);
    }

    /**
     * Deactivates a seller session by clearing active-until timestamp state.
     *
     * The method validates flow token presence and delegates the null-timestamp write to repository
     * storage, effectively terminating active seller session state.
     */
    public static function deactivate_seller_session(WP_REST_Request $request)
    {
        self::prevent_response_caching();
        $flow_token = (string) $request->get_param('flow_token');

        if (trim($flow_token) === '') {
            return CWSB_Response::error('invalid_request', 'flow_token is required.', 422);
        }

        $seller = CWSB_Seller_Repository::set_session_active_until_by_flow_token($flow_token, null);
        return CWSB_Response::ok(['seller' => $seller ?: null]);
    }

    /**
     * Returns sellers nearing session expiry and pending auth portal notification.
     *
     * It parses pagination and lead-time parameters, applies conservative limits, and delegates
     * selection logic to repository queries optimized for reminder workflows.
     */
    public static function get_pre_expiry_auth_pending_sellers(WP_REST_Request $request)
    {
        self::prevent_response_caching();
        $page = max(1, (int) $request->get_param('page'));
        $limit = (int) $request->get_param('limit');
        if ($limit <= 0) {
            $limit = 100;
        }
        $limit = min($limit, 300);

        $lead_minutes = (int) $request->get_param('lead_minutes');
        if ($lead_minutes <= 0) {
            $lead_minutes = 15;
        }
        $lead_minutes = min($lead_minutes, 1440);

        $rows = CWSB_Seller_Repository::get_pre_expiry_auth_pending_sellers($page, $limit, $lead_minutes);

        return CWSB_Response::ok([
            'page' => $page,
            'limit' => $limit,
            'lead_minutes' => $lead_minutes,
            'count' => is_array($rows) ? count($rows) : 0,
            'sellers' => is_array($rows) ? $rows : [],
        ]);
    }

    /**
     * Marks auth-portal notification as sent for a seller phone number.
     *
     * The method validates phone and timestamp semantics, defaults `sent_at` when omitted, then
     * delegates update behavior to repository logic and returns not-found/write outcomes clearly.
     */
    public static function mark_auth_portal_sent(WP_REST_Request $request)
    {
        self::prevent_response_caching();
        $phone = (string) $request->get_param('phone');
        $sent_at_param = $request->get_param('sent_at');
        $sent_at = $sent_at_param === null ? round(microtime(true) * 1000) : (int) $sent_at_param;

        if (trim($phone) === '') {
            return CWSB_Response::error('invalid_request', 'phone is required.', 422);
        }

        if ($sent_at <= 0) {
            return CWSB_Response::error('invalid_request', 'sent_at must be a positive timestamp.', 422);
        }

        $seller = CWSB_Seller_Repository::mark_auth_portal_sent_by_phone($phone, $sent_at);
        if (!$seller) {
            return CWSB_Response::error('not_found', 'Seller state not found for phone.', 404);
        }

        return CWSB_Response::ok([
            'seller' => $seller,
            'sent_at' => $sent_at,
        ]);
    }

    /**
     * Persists a password-reset token and expiry for a seller identified by email.
     *
     * The method validates required fields from the incoming request and delegates persistence
     * to repository logic, returning explicit error contracts for invalid input or write failures.
     */
    public static function set_seller_reset_token(WP_REST_Request $request)
    {
        $email = (string) $request->get_param('email');
        $reset_token = (string) $request->get_param('reset_token');
        $reset_token_expiry = (int) $request->get_param('reset_token_expiry');

        if (trim($email) === '' || trim($reset_token) === '' || $reset_token_expiry <= 0) {
            return CWSB_Response::error('invalid_request', 'email, reset_token and reset_token_expiry are required.', 422);
        }

        $seller = CWSB_Seller_Repository::set_reset_token_by_email($email, $reset_token, $reset_token_expiry);
        if ($seller === null) {
            return CWSB_Response::error('write_failed', 'Could not persist reset token — seller not found or DB error.', 500);
        }
        return CWSB_Response::ok(['seller' => $seller]);
    }

}
