<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CWSB_Response')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-response.php';
}

if (!class_exists('CWSB_Logger')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-logger.php';
}

if (!class_exists('CWSB_Utils')) {
    require_once __DIR__ . '/../../utilities/class-cwsb-utils.php';
}

if (!class_exists('CWSB_Admin_Ops_Repository')) {
    require_once __DIR__ . '/../../repositories/admin/class-cwsb-admin-ops-repository.php';
}

/**
 * Admin endpoints business logic.
 */
class CWSB_Admin_Service
{
    private static function actor_email(WP_REST_Request $request)
    {
        return CWSB_Utils::normalize_text((string) $request->get_header('x-admin-actor'));
    }

    private static function normalized_phone($value)
    {
        return CWSB_Utils::normalize_phone((string) $value);
    }

    private static function default_meta()
    {
        return [
            'request_id' => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('cwsb_', true),
        ];
    }

    public static function search_sellers(WP_REST_Request $request)
    {
        $params = [
            'q'        => $request->get_param('q'),
            'status'   => $request->get_param('status'),
            'city'     => $request->get_param('city'),
            'category' => $request->get_param('category'),
            'page'     => $request->get_param('page'),
            'per_page' => $request->get_param('per_page'),
            'sort'     => $request->get_param('sort'),
        ];

        $data = CWSB_Admin_Ops_Repository::search_sellers($params);
        $meta = self::default_meta();
        $meta['page'] = (int) $data['page'];
        $meta['per_page'] = (int) $data['per_page'];
        $meta['total'] = (int) $data['total'];
        $meta['has_more'] = (bool) $data['has_more'];

        return CWSB_Response::ok([
            'sellers' => isset($data['sellers']) ? $data['sellers'] : [],
        ], 200, $meta);
    }

    public static function seller_profile(WP_REST_Request $request)
    {
        $phone = self::normalized_phone($request->get_param('phone'));
        if ($phone === '') {
            return CWSB_Response::error('invalid_request', 'phone is required.', 422);
        }

        $profile = CWSB_Admin_Ops_Repository::find_profile_by_phone($phone);
        if (!is_array($profile)) {
            return CWSB_Response::error('not_found', 'Seller not found.', 404);
        }

        return CWSB_Response::ok($profile, 200, self::default_meta());
    }

    public static function reset_pin(WP_REST_Request $request)
    {
        $phone = self::normalized_phone($request->get_param('phone'));
        if ($phone === '') {
            return CWSB_Response::error('invalid_request', 'phone is required.', 422);
        }

        $request_id = self::default_meta()['request_id'];

        $seller = CWSB_Admin_Ops_Repository::reset_pin($phone);

        if (!is_array($seller)) {
            return CWSB_Response::error('write_failed', 'Unable to reset seller PIN.', 500);
        }

        return CWSB_Response::ok(['ok' => true], 200, ['request_id' => $request_id]);
    }

    public static function force_logout(WP_REST_Request $request)
    {
        $phone = self::normalized_phone($request->get_param('phone'));
        if ($phone === '') {
            return CWSB_Response::error('invalid_request', 'phone is required.', 422);
        }

        $request_id = self::default_meta()['request_id'];

        $seller = CWSB_Admin_Ops_Repository::force_logout($phone);

        if (!is_array($seller)) {
            return CWSB_Response::error('write_failed', 'Unable to force logout seller.', 500);
        }

        return CWSB_Response::ok(['ok' => true], 200, ['request_id' => $request_id]);
    }

    public static function block_seller(WP_REST_Request $request)
    {
        $phone = self::normalized_phone($request->get_param('phone'));
        if ($phone === '') {
            return CWSB_Response::error('invalid_request', 'phone is required.', 422);
        }

        $actor_email = self::actor_email($request);
        $reason = CWSB_Utils::normalize_text((string) $request->get_param('reason'));
        $request_id = self::default_meta()['request_id'];

        $seller = CWSB_Admin_Ops_Repository::set_block_status($phone, true, $reason, $actor_email);

        if (!is_array($seller)) {
            return CWSB_Response::error('write_failed', 'Unable to block seller.', 500);
        }

        return CWSB_Response::ok(['ok' => true], 200, ['request_id' => $request_id]);
    }

    public static function unblock_seller(WP_REST_Request $request)
    {
        $phone = self::normalized_phone($request->get_param('phone'));
        if ($phone === '') {
            return CWSB_Response::error('invalid_request', 'phone is required.', 422);
        }

        $actor_email = self::actor_email($request);
        $request_id = self::default_meta()['request_id'];

        $seller = CWSB_Admin_Ops_Repository::set_block_status($phone, false, '', $actor_email);

        if (!is_array($seller)) {
            return CWSB_Response::error('write_failed', 'Unable to unblock seller.', 500);
        }

        return CWSB_Response::ok(['ok' => true], 200, ['request_id' => $request_id]);
    }

    public static function bulk_action(WP_REST_Request $request)
    {
        $action = CWSB_Utils::normalize_text((string) $request->get_param('action'));
        $phones = $request->get_param('phones');
        $payload = $request->get_param('payload');
        $actor_email = self::actor_email($request);
        $request_id = self::default_meta()['request_id'];

        if (!is_array($phones) || empty($phones)) {
            return CWSB_Response::error('invalid_request', 'phones must be a non-empty array.', 422);
        }

        if (count($phones) > CWSB_Admin_Ops_Repository::BULK_HARD_MAX) {
            return CWSB_Response::error('bulk_limit_exceeded', 'phones length exceeds hard max of 200.', 422);
        }

        if (count($phones) > CWSB_Admin_Ops_Repository::BULK_MAX) {
            return CWSB_Response::error('bulk_limit_exceeded', 'phones length exceeds max of 50 for block 1.', 422);
        }

        $allowed = ['send_menu', 'force_logout', 'block', 'unblock', 'tag'];
        if (!in_array($action, $allowed, true)) {
            return CWSB_Response::error('invalid_action', 'Unsupported bulk action.', 422);
        }

        $results = [];
        foreach ($phones as $phone_value) {
            $phone = self::normalized_phone($phone_value);
            if ($phone === '') {
                $results[] = ['phone' => (string) $phone_value, 'ok' => false, 'error' => 'invalid_phone'];
                continue;
            }

            if ($action === 'send_menu' || $action === 'tag') {
                $results[] = ['phone' => $phone, 'ok' => false, 'error' => 'not_supported_in_block_1'];
                continue;
            }

            $ok = false;
            if ($action === 'force_logout') {
                $seller = CWSB_Admin_Ops_Repository::force_logout($phone);
                $ok = is_array($seller);
            } elseif ($action === 'block') {
                $reason = '';
                if (is_array($payload) && isset($payload['reason'])) {
                    $reason = CWSB_Utils::normalize_text($payload['reason']);
                }
                $seller = CWSB_Admin_Ops_Repository::set_block_status($phone, true, $reason, $actor_email);
                $ok = is_array($seller);
            } elseif ($action === 'unblock') {
                $seller = CWSB_Admin_Ops_Repository::set_block_status($phone, false, '', $actor_email);
                $ok = is_array($seller);
            }

            if ($ok) {
                $results[] = ['phone' => $phone, 'ok' => true];
            } else {
                $results[] = ['phone' => $phone, 'ok' => false, 'error' => 'action_failed'];
            }
        }

        return CWSB_Response::ok(['results' => $results], 200, ['request_id' => $request_id]);
    }
}
