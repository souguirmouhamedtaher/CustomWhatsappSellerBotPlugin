<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Response')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-response.php';
}

if (!class_exists('CWSB_Wallet_Repository')) {
    require_once __DIR__ . '/../../repositories/wallet/class-cwsb-wallet-repository.php';
}

/**
 * Wallet endpoint handlers.
 *
 * Handles all POST /seller/wallet/* routes:
 * - /seller/wallet/by-flow-token         (v1.0.10, moved here from auth-order-endpoints)
 * - /seller/wallet/summary/by-flow-token  (v1.0.11)
 * - /seller/wallet/transactions/by-flow-token (v1.0.11)
 */
class CWSB_Auth_Wallet_Endpoints_Service
{
    /**
     * GET full wallet balances (tnd_balance, eur_balance, combined_tnd, …).
     * Used by POST /seller/wallet/by-flow-token.
     */
    public static function get_seller_wallet_by_flow_token(WP_REST_Request $request)
    {
        $flow_token = (string) $request->get_param('flow_token');

        if (trim($flow_token) === '') {
            return CWSB_Response::error('invalid_request', 'flow_token is required.', 422);
        }

        $wallet = CWSB_Wallet_Repository::find_wallet_by_flow_token($flow_token);

        if ($wallet === null) {
            return CWSB_Response::error('not_found', 'Seller not found for given flow_token.', 404);
        }

        return CWSB_Response::ok(['wallet' => $wallet]);
    }

    /**
     * GET wallet summary: balance, currency, pending_balance, last_tx_at.
     * Used by POST /seller/wallet/summary/by-flow-token.
     */
    public static function get_seller_wallet_summary_by_flow_token(WP_REST_Request $request)
    {
        $flow_token = (string) $request->get_param('flow_token');

        if (trim($flow_token) === '') {
            return CWSB_Response::error('invalid_request', 'flow_token is required.', 422);
        }

        $summary = CWSB_Wallet_Repository::find_wallet_summary_by_flow_token($flow_token);

        if ($summary === null) {
            return CWSB_Response::error('not_found', 'Seller not found for given flow_token.', 404);
        }

        return CWSB_Response::ok([
            'balance'         => $summary['balance'],
            'currency'        => $summary['currency'],
            'pending_balance' => $summary['pending_balance'],
            'last_tx_at'      => $summary['last_tx_at'],
        ]);
    }

    /**
     * GET paginated wallet transactions (one per completed order).
     * Used by POST /seller/wallet/transactions/by-flow-token.
     *
     * Returns empty shape (200) if seller not found — flow never errors out.
     */
    public static function get_seller_wallet_transactions_by_flow_token(WP_REST_Request $request)
    {
        $flow_token = (string) $request->get_param('flow_token');

        if (trim($flow_token) === '') {
            return CWSB_Response::error('invalid_request', 'flow_token is required.', 422);
        }

        $page  = max(1, (int) $request->get_param('page'));
        $limit = (int) $request->get_param('limit');
        if ($limit <= 0) {
            $limit = defined('CWSB_WALLET_TX_PAGE_LIMIT') ? (int) CWSB_WALLET_TX_PAGE_LIMIT : 5;
        }

        $paged = CWSB_Wallet_Repository::find_wallet_transactions_page_by_flow_token($flow_token, $page, $limit);

        return CWSB_Response::ok([
            'transactions' => isset($paged['transactions']) && is_array($paged['transactions']) ? $paged['transactions'] : [],
            'page'         => isset($paged['page'])      ? (int)  $paged['page']      : $page,
            'has_more'     => !empty($paged['has_more']),
            'next_page'    => isset($paged['next_page']) ? $paged['next_page']         : null,
        ]);
    }
}
