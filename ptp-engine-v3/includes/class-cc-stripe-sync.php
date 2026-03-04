<?php
/**
 * PTP Command Center — Stripe Sync
 * Pulls recent payments, charges, and customers directly from Stripe API.
 * Works with whichever Stripe key is available (TP or Camps config).
 */
if (!defined('ABSPATH')) exit;

class CC_Stripe_Sync {

    /**
     * Find the Stripe secret key from any available source
     */
    public static function get_stripe_key() {
        // 1. TP Stripe class
        if (class_exists('PTP_Stripe') && method_exists('PTP_Stripe', 'init')) {
            PTP_Stripe::init();
        }

        // 2. Check common option keys
        $candidates = [
            'ptp_stripe_live_secret',
            'ptp_stripe_secret_key',
            'ptp_stripe_test_secret',
        ];
        foreach ($candidates as $opt) {
            $val = get_option($opt, '');
            if ($val && strpos($val, 'sk_') === 0) return $val;
        }

        // 3. Check ptp_settings array
        foreach (['ptp_settings', 'ptp_stripe_settings', 'ptp_payment_settings'] as $opt) {
            $arr = get_option($opt, []);
            if (!is_array($arr)) continue;
            foreach ($arr as $k => $v) {
                if (is_string($v) && strpos($v, 'sk_') === 0) return $v;
            }
        }

        // 4. Camps helper function
        if (function_exists('ptp_camps_stripe_secret_key')) {
            $sk = ptp_camps_stripe_secret_key();
            if ($sk) return $sk;
        }

        // 5. CC-specific stored key
        return get_option('ptp_cc_stripe_secret_key', '');
    }

    /**
     * Check if Stripe is configured
     */
    public static function is_configured() {
        return !empty(self::get_stripe_key());
    }

    /**
     * Make a Stripe API request
     */
    private static function stripe_request($endpoint, $params = [], $method = 'GET') {
        $sk = self::get_stripe_key();
        if (!$sk) return new WP_Error('no_key', 'Stripe not configured');

        $url = 'https://api.stripe.com/v1/' . ltrim($endpoint, '/');
        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $sk,
                'Stripe-Version' => '2023-10-16',
            ],
            'timeout' => 20,
        ];

        if ($method === 'GET' && $params) {
            $url .= '?' . http_build_query($params);
        } elseif ($params) {
            $args['body'] = $params;
        }

        $resp = wp_remote_request($url, $args);
        if (is_wp_error($resp)) return $resp;

        $body = json_decode(wp_remote_retrieve_body($resp), true);
        $code = wp_remote_retrieve_response_code($resp);

        if ($code >= 400) {
            return new WP_Error('stripe_error', $body['error']['message'] ?? 'Stripe API error');
        }

        return $body;
    }

    // ═══════════════════════════════════════
    // REST API ROUTES
    // ═══════════════════════════════════════

    public static function register_routes() {
        $ns = 'ptp-cc/v1';

        register_rest_route($ns, '/stripe/status', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_status'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);

        register_rest_route($ns, '/stripe/recent-payments', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_recent_payments'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);

        register_rest_route($ns, '/stripe/sync', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'api_sync_now'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);

        register_rest_route($ns, '/stripe/customers', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_customers'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);

        register_rest_route($ns, '/stripe/customer/(?P<id>.+)', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_customer_detail'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);

        // Stripe webhook endpoint for CC
        register_rest_route($ns, '/webhooks/stripe', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'handle_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    // ═══════════════════════════════════════
    // API HANDLERS
    // ═══════════════════════════════════════

    public static function api_status() {
        $sk = self::get_stripe_key();
        $configured = !empty($sk);
        $mode = '';
        $account = null;

        if ($configured) {
            $mode = strpos($sk, 'sk_live_') === 0 ? 'live' : 'test';
            $acct = self::stripe_request('account');
            if (!is_wp_error($acct)) {
                $account = [
                    'id'       => $acct['id'] ?? '',
                    'name'     => $acct['settings']['dashboard']['display_name'] ?? $acct['business_profile']['name'] ?? '',
                    'email'    => $acct['email'] ?? '',
                    'country'  => $acct['country'] ?? '',
                ];
            }
        }

        return [
            'configured'    => $configured,
            'mode'          => $mode,
            'account'       => $account,
            'key_source'    => $configured ? (strpos(get_option('ptp_stripe_live_secret', ''), 'sk_') === 0 ? 'training_platform' : 'auto_detected') : 'none',
            'webhook_url'   => rest_url('ptp-cc/v1/webhooks/stripe'),
            'last_sync'     => get_option('ptp_cc_last_stripe_sync', ''),
        ];
    }

    /**
     * Pull recent payments from Stripe (charges + checkout sessions)
     */
    public static function api_recent_payments($req) {
        $limit = min((int)($req->get_param('limit') ?: 30), 100);
        $starting_after = $req->get_param('starting_after') ?: null;

        $params = ['limit' => $limit, 'expand[]' => 'data.customer'];
        if ($starting_after) $params['starting_after'] = $starting_after;

        // Get recent payment intents (more reliable than charges)
        $result = self::stripe_request('payment_intents', $params);
        if (is_wp_error($result)) return $result;

        $payments = [];
        foreach ($result['data'] ?? [] as $pi) {
            if ($pi['status'] !== 'succeeded') continue;

            $cust = $pi['customer'] ?? null;
            $cust_email = '';
            $cust_name = '';
            if (is_array($cust)) {
                $cust_email = $cust['email'] ?? '';
                $cust_name = $cust['name'] ?? '';
            }
            // Also check charges for receipt email
            if (!$cust_email && !empty($pi['latest_charge'])) {
                $charge = self::stripe_request('charges/' . $pi['latest_charge']);
                if (!is_wp_error($charge)) {
                    $cust_email = $charge['receipt_email'] ?? $charge['billing_details']['email'] ?? '';
                    if (!$cust_name) $cust_name = $charge['billing_details']['name'] ?? '';
                }
            }

            $payments[] = [
                'id'          => $pi['id'],
                'amount'      => $pi['amount'] / 100,
                'currency'    => $pi['currency'],
                'status'      => $pi['status'],
                'description' => $pi['description'] ?? '',
                'customer_id' => is_string($cust) ? $cust : ($cust['id'] ?? ''),
                'email'       => $cust_email,
                'name'        => $cust_name,
                'metadata'    => $pi['metadata'] ?? [],
                'created'     => date('Y-m-d H:i:s', $pi['created']),
                'in_cc'       => false, // will be enriched below
            ];
        }

        // Check which payments are already in CC
        if ($payments) {
            global $wpdb;
            // Check camp bookings
            $cb = $wpdb->prefix . 'ptp_camp_bookings';
            if ($wpdb->get_var("SHOW TABLES LIKE '$cb'") === $cb) {
                $pi_ids = array_column($payments, 'id');
                $placeholders = implode(',', array_fill(0, count($pi_ids), '%s'));
                $found = $wpdb->get_col($wpdb->prepare(
                    "SELECT stripe_payment_id FROM $cb WHERE stripe_payment_id IN ($placeholders)",
                    ...$pi_ids
                ));
                foreach ($payments as &$p) {
                    if (in_array($p['id'], $found)) $p['in_cc'] = true;
                }
                unset($p);
            }
            // Check training bookings
            $tb = CC_DB::bookings();
            if ($wpdb->get_var("SHOW TABLES LIKE '$tb'") === $tb) {
                foreach ($payments as &$p) {
                    if ($p['in_cc']) continue;
                    $found = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM $tb WHERE stripe_payment_id=%s OR stripe_checkout_id=%s LIMIT 1",
                        $p['id'], $p['id']
                    ));
                    if ($found) $p['in_cc'] = true;
                }
                unset($p);
            }
        }

        return [
            'payments' => $payments,
            'has_more' => $result['has_more'] ?? false,
            'count'    => count($payments),
        ];
    }

    /**
     * Run a full sync: pull recent Stripe data and match to CC contacts
     */
    public static function api_sync_now() {
        global $wpdb;
        $synced = 0;
        $matched = 0;

        // Pull last 100 successful payment intents
        $result = self::stripe_request('payment_intents', [
            'limit' => 100,
            'expand[]' => 'data.customer',
        ]);
        if (is_wp_error($result)) return $result;

        $at = CC_DB::apps();
        $pt = CC_DB::parents();

        foreach ($result['data'] ?? [] as $pi) {
            if ($pi['status'] !== 'succeeded') continue;
            $synced++;

            $cust = $pi['customer'] ?? null;
            $email = '';
            if (is_array($cust)) $email = $cust['email'] ?? '';
            if (!$email && !empty($pi['receipt_email'])) $email = $pi['receipt_email'];

            if (!$email) continue;

            // Guard: skip trivial amounts (< $5) and old payments (> 90 days)
            $amount_cents = $pi['amount'] ?? 0;
            if ($amount_cents < 500) continue; // Skip sub-$5 payments
            $pi_created = $pi['created'] ?? 0;
            if ($pi_created && (time() - $pi_created) > 90 * 86400) continue; // Skip payments older than 90 days

            // Match to pipeline
            $app = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $at WHERE email=%s AND status NOT IN ('converted','lost') ORDER BY created_at DESC LIMIT 1",
                $email
            ));
            if ($app) {
                $matched++;
                // Tag as having Stripe activity
                $wpdb->update($at, ['status' => 'converted'], ['id' => $app->id]);
                // Log
                CC_DB::log('stripe_sync', 'application', $app->id,
                    'Stripe payment $' . number_format($pi['amount'] / 100, 2) . ' matched to ' . $app->parent_name, 'stripe');
            }
        }

        update_option('ptp_cc_last_stripe_sync', current_time('mysql'));

        return [
            'synced'  => $synced,
            'matched' => $matched,
            'time'    => current_time('mysql'),
        ];
    }

    /**
     * List recent Stripe customers
     */
    public static function api_customers($req) {
        $limit = min((int)($req->get_param('limit') ?: 20), 100);
        $result = self::stripe_request('customers', ['limit' => $limit]);
        if (is_wp_error($result)) return $result;

        $customers = [];
        foreach ($result['data'] ?? [] as $c) {
            $customers[] = [
                'id'       => $c['id'],
                'email'    => $c['email'] ?? '',
                'name'     => $c['name'] ?? '',
                'phone'    => $c['phone'] ?? '',
                'created'  => date('Y-m-d H:i:s', $c['created']),
                'metadata' => $c['metadata'] ?? [],
            ];
        }

        return ['customers' => $customers];
    }

    /**
     * Get a single Stripe customer with their recent charges
     */
    public static function api_customer_detail($req) {
        $id = $req['id'];

        $cust = self::stripe_request('customers/' . $id);
        if (is_wp_error($cust)) return $cust;

        $charges = self::stripe_request('payment_intents', [
            'customer' => $id, 'limit' => 20,
        ]);

        $payments = [];
        if (!is_wp_error($charges)) {
            foreach ($charges['data'] ?? [] as $pi) {
                $payments[] = [
                    'id'          => $pi['id'],
                    'amount'      => $pi['amount'] / 100,
                    'status'      => $pi['status'],
                    'description' => $pi['description'] ?? '',
                    'created'     => date('Y-m-d H:i:s', $pi['created']),
                ];
            }
        }

        return [
            'customer' => [
                'id'    => $cust['id'],
                'email' => $cust['email'] ?? '',
                'name'  => $cust['name'] ?? '',
                'phone' => $cust['phone'] ?? '',
            ],
            'payments' => $payments,
            'total'    => array_sum(array_column($payments, 'amount')),
        ];
    }

    // ═══════════════════════════════════════
    // STRIPE WEBHOOK
    // ═══════════════════════════════════════

    public static function handle_webhook($request) {
        $body = $request->get_body();

        // ── Verify Stripe signature ──
        $sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $secret = get_option('ptp_cc_stripe_webhook_secret', '');
        if ($secret) {
            if (!$sig) {
                error_log('[PTP-CC Stripe] Webhook rejected: missing Stripe-Signature header');
                return new WP_Error('no_signature', 'Missing signature', ['status' => 403]);
            }
            // Parse Stripe signature: t=timestamp,v1=hash
            $parts = [];
            foreach (explode(',', $sig) as $item) {
                $kv = explode('=', $item, 2);
                if (count($kv) === 2) $parts[$kv[0]] = $kv[1];
            }
            $timestamp = $parts['t'] ?? '';
            $their_sig = $parts['v1'] ?? '';
            if (!$timestamp || !$their_sig) {
                error_log('[PTP-CC Stripe] Webhook rejected: malformed signature');
                return new WP_Error('bad_signature', 'Malformed signature', ['status' => 403]);
            }
            // Reject if older than 5 minutes (replay protection)
            if (abs(time() - (int)$timestamp) > 300) {
                error_log('[PTP-CC Stripe] Webhook rejected: timestamp too old');
                return new WP_Error('stale', 'Timestamp too old', ['status' => 403]);
            }
            $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
            if (!hash_equals($expected, $their_sig)) {
                error_log('[PTP-CC Stripe] Webhook rejected: signature mismatch');
                return new WP_Error('invalid_signature', 'Invalid signature', ['status' => 403]);
            }
        } else {
            error_log('[PTP-CC Stripe] Webhook REJECTED: no secret configured. Set ptp_cc_stripe_webhook_secret in wp_options.');
            return new WP_Error('no_secret', 'Stripe webhook secret not configured', ['status' => 403]);
        }

        $data = json_decode($body, true);
        $type = $data['type'] ?? '';
        $obj  = $data['data']['object'] ?? [];

        if (!$obj) return ['received' => true];

        global $wpdb;
        $at = CC_DB::apps();

        // Handle successful payments
        if (in_array($type, ['payment_intent.succeeded', 'checkout.session.completed'])) {
            $email = '';
            $amount = 0;
            $name = '';

            if ($type === 'payment_intent.succeeded') {
                $amount = ($obj['amount'] ?? 0) / 100;
                // Get customer email
                if (!empty($obj['customer'])) {
                    $cust = self::stripe_request('customers/' . $obj['customer']);
                    if (!is_wp_error($cust)) $email = $cust['email'] ?? '';
                }
                if (!$email) $email = $obj['receipt_email'] ?? '';
            } else {
                $amount = ($obj['amount_total'] ?? 0) / 100;
                $email = $obj['customer_email'] ?? $obj['customer_details']['email'] ?? '';
                $name = $obj['customer_details']['name'] ?? '';
            }

            if ($email) {
                // Auto-convert pipeline entry
                $app = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $at WHERE email=%s AND status NOT IN ('converted','lost') ORDER BY created_at DESC LIMIT 1",
                    $email
                ));
                if ($app) {
                    $old = $app->status;
                    $wpdb->update($at, ['status' => 'converted'], ['id' => $app->id]);

                    $seg = CC_DB::seg_hist();
                    if ($wpdb->get_var("SHOW TABLES LIKE '$seg'") === $seg) {
                        $wpdb->insert($seg, [
                            'app_id'    => $app->id,
                            'old_value' => $old,
                            'new_value' => 'converted',
                            'reason'    => "Stripe webhook: $type (\${$amount})",
                        ]);
                    }
                }

                // Log activity
                CC_DB::log('stripe_payment', 'application', $app->id ?? 0,
                    "Stripe: \${$amount} from " . ($name ?: $email) . " ($type)", 'stripe');
            }
        }

        return ['received' => true];
    }
}
