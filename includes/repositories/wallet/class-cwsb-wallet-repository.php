<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Wallet_Queries')) {
    require_once __DIR__ . '/class-cwsb-wallet-queries.php';
}

if (!class_exists('CWSB_Order_Resolver')) {
    require_once __DIR__ . '/../order/class-cwsb-order-resolver.php';
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

/**
 * Wallet repository facade.
 *
 * Delegates to CWSB_Wallet_Queries for SQL and CWSB_Order_Resolver for seller ID resolution.
 *
 * Public API:
 * - compute_wallet_by_seller_user_id()  — raw wallet totals by user ID
 * - find_wallet_by_flow_token()         — wallet totals by flow token (v1.0.10 endpoint)
 * - find_wallet_summary_by_flow_token() — summary shape: balance, currency, pending_balance, last_tx_at
 * - find_wallet_transactions_page_by_flow_token() — paginated completed-order transaction list
 */
class CWSB_Wallet_Repository
{
    /**
     * Compute wallet balances for a seller user ID.
     *
     * Returns full wallet structure including per-currency breakdown.
     */
    public static function compute_wallet_by_seller_user_id($seller_user_id)
    {
        $uid = (int) $seller_user_id;
        if ($uid <= 0) {
            return self::empty_wallet();
        }

        $commission_rate = defined('CWSB_WALLET_COMMISSION_RATE') ? (float) CWSB_WALLET_COMMISSION_RATE : 0.2261;
        $eur_to_tnd      = defined('CWSB_EUR_TO_TND_RATE')        ? (float) CWSB_EUR_TO_TND_RATE        : 3.358;

        $rows = CWSB_Wallet_Queries::compute_wallet_subtotals_by_seller($uid);

        $tnd_balance     = 0.0;
        $eur_balance     = 0.0;
        $delivered_count = 0;

        foreach ($rows as $row) {
            $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
            $subtotal = (float) ($row['total_subtotal'] ?? 0);
            $net      = $subtotal * (1 - $commission_rate);
            $count    = (int) ($row['order_count'] ?? 0);

            $delivered_count += $count;

            if ($currency === 'TND') {
                $tnd_balance += $net;
            } else {
                $eur_balance += $net;
            }
        }

        $combined_tnd = $tnd_balance + $eur_balance * $eur_to_tnd;

        return [
            'tnd_balance'           => round($tnd_balance, 4),
            'eur_balance'           => round($eur_balance, 4),
            'combined_tnd'          => round($combined_tnd, 4),
            'commission_rate'       => $commission_rate,
            'eur_to_tnd_rate'       => $eur_to_tnd,
            'delivered_order_count' => $delivered_count,
        ];
    }

    /**
     * Find wallet balances by seller flow token.
     *
     * Used by POST /seller/wallet/by-flow-token (v1.0.10).
     * Returns null if seller not found.
     */
    public static function find_wallet_by_flow_token($flow_token)
    {
        $token = CWSB_Utils::normalize_text($flow_token);
        if ($token === '') {
            return null;
        }

        $seller_user_id = CWSB_Order_Resolver::resolve_seller_user_id_by_flow_token($token);
        if ($seller_user_id <= 0) {
            return null;
        }

        return self::compute_wallet_by_seller_user_id($seller_user_id);
    }

    /**
     * Find wallet summary by seller flow token.
     *
     * Used by POST /seller/wallet/summary/by-flow-token.
     * Returns: { balance, currency, pending_balance, last_tx_at } or null if seller not found.
     */
    public static function find_wallet_summary_by_flow_token($flow_token)
    {
        $token = CWSB_Utils::normalize_text($flow_token);
        if ($token === '') {
            return null;
        }

        $seller_user_id = CWSB_Order_Resolver::resolve_seller_user_id_by_flow_token($token);
        if ($seller_user_id <= 0) {
            return null;
        }

        $commission_rate = defined('CWSB_WALLET_COMMISSION_RATE') ? (float) CWSB_WALLET_COMMISSION_RATE : 0.2261;
        $eur_to_tnd      = defined('CWSB_EUR_TO_TND_RATE')        ? (float) CWSB_EUR_TO_TND_RATE        : 3.358;

        $rows        = CWSB_Wallet_Queries::compute_wallet_subtotals_by_seller($seller_user_id);
        $tnd_balance = 0.0;
        $eur_balance = 0.0;

        foreach ($rows as $row) {
            $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
            $subtotal = (float) ($row['total_subtotal'] ?? 0);
            $net      = $subtotal * (1 - $commission_rate);

            if ($currency === 'TND') {
                $tnd_balance += $net;
            } else {
                $eur_balance += $net;
            }
        }

        $combined_tnd = $tnd_balance + $eur_balance * $eur_to_tnd;

        $raw_last_tx = CWSB_Wallet_Queries::find_wallet_last_tx_at_by_seller($seller_user_id);
        $last_tx_at  = null;
        if ($raw_last_tx !== null) {
            $formatted  = mysql2date('d/m/Y H:i', $raw_last_tx);
            $last_tx_at = ($formatted !== false && $formatted !== '') ? (string) $formatted : null;
        }

        return [
            'balance'         => round($combined_tnd, 4),
            'currency'        => 'TND',
            'pending_balance' => 0.0,
            'last_tx_at'      => $last_tx_at,
        ];
    }

    /**
     * Find paginated wallet transactions by seller flow token.
     *
     * Each transaction corresponds to one completed order.
     * Returns empty shape (not null) if seller not found — flow never errors out.
     *
     * Used by POST /seller/wallet/transactions/by-flow-token.
     */
    public static function find_wallet_transactions_page_by_flow_token($flow_token, $page, $limit)
    {
        $safe_page  = max(1, (int) $page);
        $page_limit = defined('CWSB_WALLET_TX_PAGE_LIMIT') ? (int) CWSB_WALLET_TX_PAGE_LIMIT : 5;
        $safe_limit = max(1, min((int) $limit, $page_limit));
        $offset     = ($safe_page - 1) * $safe_limit;

        $empty = [
            'transactions' => [],
            'page'         => $safe_page,
            'has_more'     => false,
            'next_page'    => null,
        ];

        $token = CWSB_Utils::normalize_text($flow_token);
        if ($token === '') {
            return $empty;
        }

        $seller_user_id = CWSB_Order_Resolver::resolve_seller_user_id_by_flow_token($token);
        if ($seller_user_id <= 0) {
            return $empty;
        }

        $commission_rate = defined('CWSB_WALLET_COMMISSION_RATE') ? (float) CWSB_WALLET_COMMISSION_RATE : 0.2261;

        // Probe limit + 1 to detect has_more without a COUNT query
        $probe_limit = $safe_limit + 1;
        $rows        = CWSB_Wallet_Queries::find_wallet_transaction_rows_by_seller($seller_user_id, $probe_limit, $offset);

        $has_more = count($rows) > $safe_limit;
        $rows     = array_slice($rows, 0, $safe_limit);

        $transactions = [];
        foreach ($rows as $row) {
            $order_id     = (int) ($row['order_id'] ?? 0);
            $order_number = CWSB_Utils::normalize_text((string) ($row['order_number'] ?? ''));
            if ($order_number === '') {
                $order_number = (string) $order_id;
            }

            $subtotal   = (float) ($row['articles_subtotal'] ?? 0);
            $net_amount = $subtotal * (1 - $commission_rate);
            $currency   = CWSB_Utils::normalize_text((string) ($row['currency'] ?? ''));
            $post_date  = (string) ($row['post_date'] ?? '');

            $created_at = '';
            if ($post_date !== '') {
                $formatted  = mysql2date('d/m/Y H:i', $post_date);
                $created_at = ($formatted !== false && $formatted !== '') ? (string) $formatted : $post_date;
            }

            $transactions[] = [
                'id'         => (string) $order_id,
                'label'      => 'Commande #' . $order_number,
                'amount'     => round($net_amount, 4),
                'currency'   => $currency,
                'type'       => 'credit',
                'created_at' => $created_at,
                'status'     => 'completed',
            ];
        }

        return [
            'transactions' => $transactions,
            'page'         => $safe_page,
            'has_more'     => $has_more,
            'next_page'    => $has_more ? ($safe_page + 1) : null,
        ];
    }

    /**
     * Empty wallet structure (used when seller_user_id is invalid).
     */
    private static function empty_wallet()
    {
        $commission_rate = defined('CWSB_WALLET_COMMISSION_RATE') ? (float) CWSB_WALLET_COMMISSION_RATE : 0.2261;
        $eur_to_tnd      = defined('CWSB_EUR_TO_TND_RATE')        ? (float) CWSB_EUR_TO_TND_RATE        : 3.358;

        return [
            'tnd_balance'           => 0.0,
            'eur_balance'           => 0.0,
            'combined_tnd'          => 0.0,
            'commission_rate'       => $commission_rate,
            'eur_to_tnd_rate'       => $eur_to_tnd,
            'delivered_order_count' => 0,
        ];
    }
}
