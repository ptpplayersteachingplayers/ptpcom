<?php
/**
 * PTP Command Center — OpenPhone Contact Sync
 * Automatically creates/updates OpenPhone contacts when:
 *   - Camp booking confirmed
 *   - Training session booked  
 *   - Pipeline entry created
 *   - Abandoned cart detected
 * 
 * SETUP: In OpenPhone > Contacts > any contact > "Add property", create:
 *   - "Status" (string)    → Lead / Camp Booked / Training Booked / Repeat / Abandoned
 *   - "Camp" (string)      → Camp name(s)
 *   - "LTV" (number)       → Total lifetime value
 *   - "Player" (string)    → Child's name
 *   - "Source" (string)     → Where they came from
 * 
 * Then go to CC > Settings and hit "Sync OpenPhone Fields" to detect them.
 */
if (!defined('ABSPATH')) exit;

class CC_OpenPhone_Sync {

    private static $api_url = 'https://api.openphone.com/v1';

    /**
     * Get OpenPhone API key
     */
    private static function get_key() {
        return get_option('ptp_openphone_api_key', '') ?: get_option('ptp_cc_openphone_api_key', '');
    }

    /**
     * OpenPhone API request helper
     */
    private static function api($method, $endpoint, $body = null) {
        $key = self::get_key();
        if (!$key) return new WP_Error('no_key', 'OpenPhone API key not configured');

        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => $key,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 15,
        ];
        if ($body) $args['body'] = json_encode($body);

        $url = self::$api_url . '/' . ltrim($endpoint, '/');
        $resp = wp_remote_request($url, $args);

        if (is_wp_error($resp)) return $resp;
        $code = wp_remote_retrieve_response_code($resp);
        $data = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code >= 400) {
            error_log("[PTP-CC OpenPhone] API error $code: " . wp_remote_retrieve_body($resp));
            return new WP_Error('openphone_error', $data['message'] ?? "HTTP $code");
        }

        return $data;
    }

    // ═══════════════════════════════════════
    // CONTACT LOOKUP & MANAGEMENT
    // ═══════════════════════════════════════

    /**
     * Find OpenPhone contact by phone number
     */
    public static function find_contact_by_phone($phone) {
        $clean = preg_replace('/\D/', '', $phone);
        if (strlen($clean) === 10) $clean = '1' . $clean;
        $e164 = '+' . $clean;

        $result = self::api('GET', 'contacts?phoneNumber=' . urlencode($e164));
        if (is_wp_error($result)) return null;

        $contacts = $result['data'] ?? [];
        return !empty($contacts) ? $contacts[0] : null;
    }

    /**
     * Create a new OpenPhone contact
     */
    public static function create_contact($data) {
        $phone = $data['phone'] ?? '';
        $clean = preg_replace('/\D/', '', $phone);
        if (strlen($clean) === 10) $clean = '1' . $clean;
        $e164 = '+' . $clean;

        $body = [
            'defaultFields' => [
                'phoneNumbers' => [['value' => $e164, 'name' => 'Mobile']],
            ],
        ];

        if (!empty($data['first_name'])) $body['defaultFields']['firstName'] = $data['first_name'];
        if (!empty($data['last_name'])) $body['defaultFields']['lastName'] = $data['last_name'];
        if (!empty($data['email'])) $body['defaultFields']['emails'] = [['value' => $data['email'], 'name' => 'Primary']];
        if (!empty($data['company'])) $body['defaultFields']['company'] = $data['company'];

        // Custom fields
        $custom = self::build_custom_fields($data);
        if ($custom) $body['customFields'] = $custom;

        $result = self::api('POST', 'contacts', $body);
        if (is_wp_error($result)) return $result;

        return $result['data'] ?? $result;
    }

    /**
     * Update an existing OpenPhone contact
     */
    public static function update_contact($contact_id, $data) {
        $body = [];

        // Default fields
        $defaults = [];
        if (!empty($data['first_name'])) $defaults['firstName'] = $data['first_name'];
        if (!empty($data['last_name'])) $defaults['lastName'] = $data['last_name'];
        if (!empty($data['email'])) $defaults['emails'] = [['value' => $data['email'], 'name' => 'Primary']];
        if ($defaults) $body['defaultFields'] = $defaults;

        // Custom fields
        $custom = self::build_custom_fields($data);
        if ($custom) $body['customFields'] = $custom;

        if (!$body) return true; // Nothing to update

        $result = self::api('PATCH', 'contacts/' . $contact_id, $body);
        return is_wp_error($result) ? $result : true;
    }

    /**
     * Build custom fields array from data (public wrapper for Platform use)
     */
    public static function build_custom_fields_public($data) {
        return self::build_custom_fields($data);
    }

    /**
     * Build custom fields array from data
     * Maps our keys to OpenPhone custom field keys
     */
    private static function build_custom_fields($data) {
        $field_map = get_option('ptp_cc_openphone_field_map', []);
        if (empty($field_map)) return [];

        $custom = [];
        $mappings = [
            'status'  => $data['status'] ?? '',
            'camp'    => $data['camp'] ?? '',
            'ltv'     => $data['ltv'] ?? '',
            'player'  => $data['player'] ?? '',
            'source'  => $data['source'] ?? '',
        ];

        foreach ($mappings as $our_key => $value) {
            if ($value === '' || !isset($field_map[$our_key])) continue;
            $custom[] = [
                'key'   => $field_map[$our_key],
                'value' => (string)$value,
            ];
        }

        return $custom;
    }

    /**
     * Create or update contact — smart upsert
     */
    public static function sync_contact($data) {
        $phone = $data['phone'] ?? '';
        if (!$phone) return new WP_Error('no_phone', 'No phone number provided');

        $existing = self::find_contact_by_phone($phone);

        if ($existing) {
            $result = self::update_contact($existing['id'], $data);
            error_log("[PTP-CC OpenPhone] Updated contact {$existing['id']} - " . ($data['status'] ?? ''));
            return ['action' => 'updated', 'contact_id' => $existing['id']];
        } else {
            $result = self::create_contact($data);
            if (is_wp_error($result)) return $result;
            $new_id = $result['id'] ?? 'unknown';
            error_log("[PTP-CC OpenPhone] Created contact $new_id - " . ($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
            return ['action' => 'created', 'contact_id' => $new_id];
        }
    }

    // ═══════════════════════════════════════
    // EVENT HANDLERS — Auto-sync on events
    // ═══════════════════════════════════════

    /**
     * Camp booking confirmed — tag contact as Camp Booked
     */
    public static function on_camp_booked($row) {
        if (!is_array($row)) $row = (array)$row;

        $phone = $row['customer_phone'] ?? '';
        if (!$phone) return;

        // Get camp name from camp_id (ptp_camp_bookings has camp_id, not camp_name)
        $camp_name = '';
        if (!empty($row['camp_name'])) {
            $camp_name = $row['camp_name'];
        } elseif (!empty($row['camp_id'])) {
            $camp_name = get_the_title($row['camp_id']) ?: '';
        }

        // Calculate LTV
        $ltv = self::calculate_ltv_by_email($row['customer_email'] ?? '');

        // Parse name
        $parts = explode(' ', $row['customer_name'] ?? '', 2);

        self::sync_contact([
            'phone'      => $phone,
            'first_name' => $parts[0] ?? '',
            'last_name'  => $parts[1] ?? '',
            'email'      => $row['customer_email'] ?? '',
            'status'     => 'Camp Booked',
            'camp'       => $camp_name,
            'player'     => $row['camper_name'] ?? '',
            'ltv'        => $ltv,
            'source'     => $row['how_found_us'] ?? ($row['utm_source'] ?? ''),
        ]);

        // Log to CC activity
        self::log_activity('openphone_sync', 'Camp Booked: ' . ($row['customer_name'] ?? '') . ' → ' . ($camp_name ?: 'Unknown Camp'));
    }

    /**
     * Training session booked
     */
    public static function on_training_booked($booking_id) {
        global $wpdb;
        $bt = CC_DB::bookings();
        $pt = CC_DB::parents();

        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM $bt WHERE id=%d", $booking_id));
        if (!$booking) return;

        $parent = $wpdb->get_row($wpdb->prepare("SELECT * FROM $pt WHERE id=%d", $booking->parent_id));
        if (!$parent || !$parent->phone) return;

        $ltv = self::calculate_ltv_by_email($parent->email);
        $parts = explode(' ', $parent->display_name ?? '', 2);

        self::sync_contact([
            'phone'      => $parent->phone,
            'first_name' => $parts[0] ?? '',
            'last_name'  => $parts[1] ?? '',
            'email'      => $parent->email,
            'status'     => 'Training Booked',
            'ltv'        => $ltv,
        ]);

        self::log_activity('openphone_sync', 'Training Booked: ' . ($parent->display_name ?? '') . ' $' . ($booking->total_amount ?? 0));
    }

    /**
     * New pipeline lead
     */
    public static function on_pipeline_entry($app_id) {
        global $wpdb;
        $at = CC_DB::apps();
        $app = $wpdb->get_row($wpdb->prepare("SELECT * FROM $at WHERE id=%d", $app_id));
        if (!$app || !$app->phone) return;

        $parts = explode(' ', $app->parent_name ?? '', 2);

        self::sync_contact([
            'phone'      => $app->phone,
            'first_name' => $parts[0] ?? '',
            'last_name'  => $parts[1] ?? '',
            'email'      => $app->email,
            'status'     => 'Lead',
            'player'     => $app->child_name ?? '',
            'source'     => $app->source ?? '',
        ]);

        self::log_activity('openphone_sync', 'New Lead: ' . ($app->parent_name ?? ''));
    }

    /**
     * Pipeline status changed (converted, lost, etc.)
     */
    public static function on_pipeline_status_change($app_id, $new_status) {
        global $wpdb;
        $at = CC_DB::apps();
        $app = $wpdb->get_row($wpdb->prepare("SELECT * FROM $at WHERE id=%d", $app_id));
        if (!$app || !$app->phone) return;

        $status_map = [
            'pending'   => 'Lead',
            'contacted' => 'Contacted',
            'scheduled' => 'Scheduled',
            'converted' => 'Converted',
            'lost'      => 'Lost',
        ];

        self::sync_contact([
            'phone'  => $app->phone,
            'status' => $status_map[$new_status] ?? ucfirst($new_status),
        ]);
    }

    /**
     * Abandoned cart detected
     */
    public static function on_abandoned_cart($cart_data) {
        if (!is_array($cart_data)) $cart_data = (array)$cart_data;
        $phone = $cart_data['phone'] ?? '';
        if (!$phone) return;

        $parts = explode(' ', $cart_data['name'] ?? '', 2);

        self::sync_contact([
            'phone'      => $phone,
            'first_name' => $parts[0] ?? '',
            'last_name'  => $parts[1] ?? '',
            'email'      => $cart_data['email'] ?? '',
            'status'     => 'Abandoned Cart',
            'camp'       => $cart_data['camp_names'] ?? '',
        ]);

        self::log_activity('openphone_sync', 'Abandoned Cart: ' . ($cart_data['name'] ?? '') . ' $' . ($cart_data['cart_total'] ?? 0));
    }

    // ═══════════════════════════════════════
    // BULK SYNC — Push all contacts at once
    // ═══════════════════════════════════════

    /**
     * Sync all camp bookers to OpenPhone
     */
    public static function bulk_sync_camp_bookers() {
        global $wpdb;
        $cb = CC_DB::camp_bookings();
        if ($wpdb->get_var("SHOW TABLES LIKE '$cb'") !== $cb) return ['synced' => 0, 'error' => 'No camp bookings table'];

        $customers = $wpdb->get_results(
            "SELECT customer_name, customer_email, customer_phone, 
                    GROUP_CONCAT(DISTINCT camp_name SEPARATOR ', ') as camps,
                    GROUP_CONCAT(DISTINCT camper_name SEPARATOR ', ') as campers,
                    SUM(amount_paid) as total_spent,
                    COUNT(*) as booking_count,
                    MAX(how_found_us) as how_found
             FROM (
                SELECT b.*, p.post_title as camp_name 
                FROM $cb b LEFT JOIN {$wpdb->posts} p ON b.camp_id=p.ID
                WHERE b.status='confirmed' AND b.customer_phone IS NOT NULL AND b.customer_phone != ''
             ) sub
             GROUP BY customer_email
             ORDER BY total_spent DESC"
        );

        $synced = 0;
        foreach ($customers ?: [] as $c) {
            $parts = explode(' ', $c->customer_name ?? '', 2);
            $status = $c->booking_count > 1 ? 'Repeat Customer' : 'Camp Booked';

            $result = self::sync_contact([
                'phone'      => $c->customer_phone,
                'first_name' => $parts[0] ?? '',
                'last_name'  => $parts[1] ?? '',
                'email'      => $c->customer_email,
                'status'     => $status,
                'camp'       => $c->camps ?: '',
                'player'     => $c->campers ?: '',
                'ltv'        => round($c->total_spent, 2),
                'source'     => $c->how_found ?: '',
            ]);

            if (!is_wp_error($result)) $synced++;

            // Rate limit: OpenPhone allows ~60 req/min
            usleep(250000); // 250ms between requests
        }

        return ['synced' => $synced, 'total' => count($customers ?: [])];
    }

    /**
     * Sync all pipeline leads to OpenPhone
     */
    public static function bulk_sync_pipeline() {
        global $wpdb;
        $at = CC_DB::apps();
        $apps = $wpdb->get_results(
            "SELECT * FROM $at WHERE phone IS NOT NULL AND phone != '' ORDER BY created_at DESC LIMIT 500"
        );

        $synced = 0;
        $status_map = ['pending'=>'Lead','contacted'=>'Contacted','scheduled'=>'Scheduled','converted'=>'Converted','lost'=>'Lost'];

        foreach ($apps ?: [] as $a) {
            $parts = explode(' ', $a->parent_name ?? '', 2);
            $ltv = self::calculate_ltv_by_email($a->email);

            $result = self::sync_contact([
                'phone'      => $a->phone,
                'first_name' => $parts[0] ?? '',
                'last_name'  => $parts[1] ?? '',
                'email'      => $a->email,
                'status'     => $status_map[$a->status] ?? ucfirst($a->status),
                'player'     => $a->child_name ?? '',
                'ltv'        => $ltv,
                'source'     => $a->source ?? '',
            ]);

            if (!is_wp_error($result)) $synced++;
            usleep(250000);
        }

        return ['synced' => $synced, 'total' => count($apps ?: [])];
    }

    // ═══════════════════════════════════════
    // NEEDS OUTREACH — Smart outreach queue
    // ═══════════════════════════════════════

    /**
     * Get contacts that need messages sent
     * Returns a prioritized list of who to contact
     */
    public static function get_needs_outreach() {
        global $wpdb;
        $at = CC_DB::apps();
        $op = CC_DB::op_msgs();
        $cl = $wpdb->prefix . 'ptp_cc_call_log';
        $cb = CC_DB::camp_bookings();
        $ac = CC_DB::camp_abandoned();
        $sc = $wpdb->prefix . 'ptp_cc_scheduled_calls';

        $outreach = [];

        // 1. Camp bookers with NO outgoing SMS in last 7 days
        if ($wpdb->get_var("SHOW TABLES LIKE '$cb'") === $cb) {
            $recent_bookers = $wpdb->get_results(
                "SELECT customer_name as name, customer_email as email, customer_phone as phone,
                        GROUP_CONCAT(DISTINCT p.post_title SEPARATOR ', ') as camp_names,
                        SUM(b.amount_paid) as total_paid,
                        MAX(b.created_at) as booked_at
                 FROM $cb b LEFT JOIN {$wpdb->posts} p ON b.camp_id=p.ID
                 WHERE b.status='confirmed' AND b.customer_phone != '' AND b.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                 GROUP BY b.customer_phone
                 ORDER BY booked_at DESC"
            );

            foreach ($recent_bookers ?: [] as $b) {
                $phone_suffix = substr(preg_replace('/\D/', '', $b->phone), -10);
                if (!$phone_suffix) continue;

                // Check for recent outgoing SMS (detect schema: CC uses 'phone', TP may use to_number)
                $op_cols_cache = isset($op_cols_cache) ? $op_cols_cache : $wpdb->get_col("DESCRIBE $op", 0);
                if (in_array('to_number', $op_cols_cache)) {
                    $recent_sms = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM $op WHERE direction='outgoing' AND to_number LIKE %s AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)",
                        '%' . $phone_suffix
                    ));
                } else {
                    $recent_sms = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM $op WHERE direction='outgoing' AND phone LIKE %s AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)",
                        '%' . $phone_suffix
                    ));
                }

                if (!$recent_sms) {
                    $outreach[] = [
                        'type'     => 'camp_booked_no_sms',
                        'priority' => 1,
                        'name'     => $b->name,
                        'phone'    => $b->phone,
                        'email'    => $b->email,
                        'reason'   => 'Booked ' . $b->camp_names . ' ($' . round($b->total_paid) . ') — no text sent in 7 days',
                        'amount'   => $b->total_paid,
                        'date'     => $b->booked_at,
                        'action'   => 'Send welcome/confirmation text',
                    ];
                }
            }
        }

        // 2. Abandoned carts not recovered + not contacted
        if ($wpdb->get_var("SHOW TABLES LIKE '$ac'") === $ac) {
            $carts = $wpdb->get_results(
                "SELECT * FROM $ac WHERE status='abandoned' AND recovered_at IS NULL AND phone IS NOT NULL AND phone != ''
                 AND created_at > DATE_SUB(NOW(), INTERVAL 14 DAY)
                 ORDER BY cart_total DESC"
            );

            foreach ($carts ?: [] as $c) {
                $phone_suffix = substr(preg_replace('/\D/', '', $c->phone), -10);
                if (!isset($op_cols_cache)) $op_cols_cache = $wpdb->get_col("DESCRIBE $op", 0);
                if (in_array('to_number', $op_cols_cache)) {
                    $recent_sms = $phone_suffix ? $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM $op WHERE direction='outgoing' AND to_number LIKE %s AND created_at > %s",
                        '%' . $phone_suffix, $c->created_at
                    )) : 0;
                } else {
                    $recent_sms = $phone_suffix ? $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM $op WHERE direction='outgoing' AND phone LIKE %s AND created_at > %s",
                        '%' . $phone_suffix, $c->created_at
                    )) : 0;
                }

                if (!$recent_sms) {
                    $outreach[] = [
                        'type'     => 'abandoned_cart',
                        'priority' => 2,
                        'name'     => $c->name,
                        'phone'    => $c->phone,
                        'email'    => $c->email,
                        'reason'   => 'Abandoned $' . $c->cart_total . ' cart (' . ($c->camp_names ?: 'camp') . ') — not contacted',
                        'amount'   => $c->cart_total,
                        'date'     => $c->created_at,
                        'action'   => 'Recovery text',
                    ];
                }
            }
        }

        // 3. Pipeline leads with no follow-up in 3+ days
        $stale_leads = $wpdb->get_results(
            "SELECT a.*, 
                    (SELECT MAX(f.created_at) FROM " . CC_DB::follow_ups() . " f WHERE f.app_id=a.id) as last_followup
             FROM $at a
             WHERE a.status IN ('pending','contacted') AND a.phone IS NOT NULL AND a.phone != ''
             ORDER BY a.created_at DESC LIMIT 100"
        );

        foreach ($stale_leads ?: [] as $a) {
            $cutoff = $a->last_followup ?: $a->created_at;
            $days_since = floor((time() - strtotime($cutoff)) / 86400);

            if ($days_since >= 3) {
                $outreach[] = [
                    'type'     => 'stale_lead',
                    'priority' => 3,
                    'name'     => $a->parent_name,
                    'phone'    => $a->phone,
                    'email'    => $a->email,
                    'reason'   => ucfirst($a->status) . ' for ' . $days_since . ' days — player: ' . ($a->child_name ?? '?'),
                    'amount'   => 0,
                    'date'     => $cutoff,
                    'action'   => $a->status === 'pending' ? 'First outreach' : 'Follow-up',
                    'app_id'   => $a->id,
                ];
            }
        }

        // 4. Overdue scheduled calls
        if ($wpdb->get_var("SHOW TABLES LIKE '$sc'") === $sc) {
            $overdue = $wpdb->get_results(
                "SELECT * FROM $sc WHERE status='scheduled' AND scheduled_at < NOW() ORDER BY scheduled_at ASC LIMIT 20"
            );
            foreach ($overdue ?: [] as $s) {
                $outreach[] = [
                    'type'     => 'overdue_call',
                    'priority' => 1,
                    'name'     => $s->contact_name,
                    'phone'    => $s->contact_phone,
                    'email'    => $s->contact_email,
                    'reason'   => 'Overdue ' . $s->call_type . ' call — was scheduled ' . $s->scheduled_at,
                    'amount'   => 0,
                    'date'     => $s->scheduled_at,
                    'action'   => 'Call now',
                ];
            }
        }

        // Sort by priority then date
        usort($outreach, function ($a, $b) {
            if ($a['priority'] !== $b['priority']) return $a['priority'] - $b['priority'];
            return strcmp($b['date'] ?? '', $a['date'] ?? '');
        });

        return $outreach;
    }

    // ═══════════════════════════════════════
    // REST API
    // ═══════════════════════════════════════

    public static function register_routes() {
        $ns = 'ptp-cc/v1';
        $perm = function () { return current_user_can('manage_options'); };

        // Detect custom fields from OpenPhone
        register_rest_route($ns, '/openphone/fields', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_get_fields'], 'permission_callback' => $perm,
        ]);
        // Save field mapping
        register_rest_route($ns, '/openphone/fields', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'api_save_field_map'], 'permission_callback' => $perm,
        ]);
        // Sync status
        register_rest_route($ns, '/openphone/status', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_status'], 'permission_callback' => $perm,
        ]);
        // Bulk sync
        register_rest_route($ns, '/openphone/sync-camps', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'api_sync_camps'], 'permission_callback' => $perm,
        ]);
        register_rest_route($ns, '/openphone/sync-pipeline', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'api_sync_pipeline'], 'permission_callback' => $perm,
        ]);
        // Needs outreach
        register_rest_route($ns, '/outreach', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_outreach'], 'permission_callback' => $perm,
        ]);
        // Quick send from outreach queue
        register_rest_route($ns, '/outreach/send', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'api_outreach_send'], 'permission_callback' => $perm,
        ]);
    }

    public static function api_get_fields() {
        $result = self::api('GET', 'contact-custom-fields');
        if (is_wp_error($result)) return $result;

        $fields = $result['data'] ?? [];
        $current_map = get_option('ptp_cc_openphone_field_map', []);

        return [
            'fields'      => $fields,
            'current_map' => $current_map,
            'has_key'     => !empty(self::get_key()),
        ];
    }

    public static function api_save_field_map($req) {
        $map = $req->get_json_params();
        // map should be like: { status: "cf_xxx", camp: "cf_yyy", ltv: "cf_zzz", ... }
        $clean = [];
        foreach (['status', 'camp', 'ltv', 'player', 'source'] as $k) {
            if (!empty($map[$k])) $clean[$k] = sanitize_text_field($map[$k]);
        }
        update_option('ptp_cc_openphone_field_map', $clean);
        return ['saved' => true, 'map' => $clean];
    }

    public static function api_status() {
        $key = self::get_key();
        $map = get_option('ptp_cc_openphone_field_map', []);
        $last_sync = get_option('ptp_cc_openphone_last_sync', '');

        return [
            'configured'     => !empty($key),
            'fields_mapped'  => count($map),
            'field_map'      => $map,
            'last_sync'      => $last_sync,
            'auto_sync'      => get_option('ptp_cc_openphone_auto_sync', 'yes'),
        ];
    }

    public static function api_sync_camps() {
        $result = self::bulk_sync_camp_bookers();
        update_option('ptp_cc_openphone_last_sync', current_time('mysql'));
        return $result;
    }

    public static function api_sync_pipeline() {
        $result = self::bulk_sync_pipeline();
        update_option('ptp_cc_openphone_last_sync', current_time('mysql'));
        return $result;
    }

    public static function api_outreach() {
        return ['outreach' => self::get_needs_outreach()];
    }

    public static function api_outreach_send($req) {
        $b = $req->get_json_params();
        $phone = $b['phone'] ?? '';
        $message = $b['message'] ?? '';
        if (!$phone || !$message) return new WP_Error('missing', 'Phone and message required');

        $result = CC_DB::send_sms($phone, $message);
        if (is_wp_error($result)) return $result;

        return ['sent' => true];
    }

    // ═══════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════

    private static function calculate_ltv_by_email($email) {
        if (!$email) return 0;
        global $wpdb;
        $ltv = 0;

        // Training
        $bt = CC_DB::bookings();
        $pt = CC_DB::parents();
        if ($wpdb->get_var("SHOW TABLES LIKE '$pt'") === $pt) {
            $parent_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $pt WHERE email=%s LIMIT 1", $email));
            if ($parent_id && $wpdb->get_var("SHOW TABLES LIKE '$bt'") === $bt) {
                $ltv += (float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(total_amount),0) FROM $bt WHERE parent_id=%d", $parent_id));
            }
        }

        // Camps
        $cb = CC_DB::camp_bookings();
        if ($wpdb->get_var("SHOW TABLES LIKE '$cb'") === $cb) {
            $ltv += (float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM $cb WHERE LOWER(customer_email)=LOWER(%s) AND status='confirmed'", $email));
        }

        return round($ltv, 2);
    }

    private static function log_activity($type, $summary) {
        CC_DB::log($type, null, null, $summary, 'openphone_sync');
    }

    // ═══════════════════════════════════════
    // HOOKS REGISTRATION
    // ═══════════════════════════════════════

    public static function register_hooks() {
        // Only auto-sync if enabled
        if (get_option('ptp_cc_openphone_auto_sync', 'yes') !== 'yes') return;
        if (!self::get_key()) return;

        // Camp booking
        add_action('ptp_camp_booking_recorded', [__CLASS__, 'on_camp_booked'], 20, 1);

        // Training booking (TP fires ptp_booking_confirmed, not ptp_booking_paid)
        add_action('ptp_booking_confirmed', [__CLASS__, 'on_training_booked'], 20, 1);
        add_action('ptp_booking_completed', [__CLASS__, 'on_training_booked'], 20, 1);
    }
}
