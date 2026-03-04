<?php
/**
 * PTP Command Center — Google Calendar Integration
 * 
 * Handles OAuth2 flow, token refresh, and event CRUD for Google Calendar.
 * Also manages scheduled calls and call logging with OpenPhone.
 */
if (!defined('ABSPATH')) exit;

class CC_GCal {

    const OPTION_TOKENS  = 'ptp_cc_gcal_tokens';
    const OPTION_CREDS   = 'ptp_cc_gcal_credentials';
    const OPTION_CAL_ID  = 'ptp_cc_gcal_calendar_id';
    const AUTH_URL       = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL      = 'https://oauth2.googleapis.com/token';
    const CAL_API        = 'https://www.googleapis.com/calendar/v3';
    const SCOPES         = 'https://www.googleapis.com/auth/calendar';

    // ═══════════════════════════════════════
    // DB TABLES
    // ═══════════════════════════════════════

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // Scheduled calls / tasks
        $t1 = $wpdb->prefix . 'ptp_cc_scheduled_calls';
        if ($wpdb->get_var("SHOW TABLES LIKE '$t1'") !== $t1) {
            $wpdb->query("CREATE TABLE $t1 (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                contact_name VARCHAR(255) DEFAULT '',
                contact_phone VARCHAR(50) DEFAULT '',
                contact_email VARCHAR(255) DEFAULT '',
                application_id BIGINT UNSIGNED DEFAULT 0,
                family_id BIGINT UNSIGNED DEFAULT 0,
                call_type VARCHAR(30) DEFAULT 'follow_up',
                scheduled_at DATETIME NOT NULL,
                duration_minutes INT DEFAULT 15,
                notes TEXT,
                status VARCHAR(20) DEFAULT 'scheduled',
                gcal_event_id VARCHAR(255) DEFAULT '',
                completed_at DATETIME DEFAULT NULL,
                outcome VARCHAR(50) DEFAULT '',
                outcome_notes TEXT,
                created_by BIGINT UNSIGNED DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_scheduled (scheduled_at),
                INDEX idx_app (application_id),
                INDEX idx_family (family_id)
            ) $charset;");
        }

        // Call log (auto-captured from OpenPhone + manual)
        $t2 = $wpdb->prefix . 'ptp_cc_call_log';
        if ($wpdb->get_var("SHOW TABLES LIKE '$t2'") !== $t2) {
            $wpdb->query("CREATE TABLE $t2 (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                direction VARCHAR(10) DEFAULT 'outbound',
                from_number VARCHAR(50) DEFAULT '',
                to_number VARCHAR(50) DEFAULT '',
                contact_name VARCHAR(255) DEFAULT '',
                contact_email VARCHAR(255) DEFAULT '',
                duration_seconds INT DEFAULT 0,
                status VARCHAR(20) DEFAULT 'completed',
                recording_url VARCHAR(500) DEFAULT '',
                openphone_call_id VARCHAR(255) DEFAULT '',
                notes TEXT,
                application_id BIGINT UNSIGNED DEFAULT 0,
                family_id BIGINT UNSIGNED DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_to (to_number),
                INDEX idx_from (from_number),
                INDEX idx_dir (direction),
                INDEX idx_created (created_at)
            ) $charset;");
        }
    }

    // ═══════════════════════════════════════
    // OAUTH2 FLOW
    // ═══════════════════════════════════════

    /**
     * Get the OAuth redirect URI (WP admin callback)
     */
    public static function redirect_uri() {
        return admin_url('admin.php?page=ptp-cc-gcal-callback');
    }

    /**
     * Build the Google OAuth authorization URL
     */
    public static function get_auth_url() {
        $creds = get_option(self::OPTION_CREDS, []);
        if (empty($creds['client_id'])) return '';

        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => $creds['client_id'],
            'redirect_uri'  => self::redirect_uri(),
            'response_type' => 'code',
            'scope'         => self::SCOPES,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => wp_create_nonce('ptp_cc_gcal_auth'),
        ]);
    }

    /**
     * Exchange authorization code for tokens
     */
    public static function exchange_code($code) {
        $creds = get_option(self::OPTION_CREDS, []);
        if (empty($creds['client_id']) || empty($creds['client_secret'])) {
            return new WP_Error('no_creds', 'Google credentials not configured');
        }

        $resp = wp_remote_post(self::TOKEN_URL, [
            'body' => [
                'code'          => $code,
                'client_id'     => $creds['client_id'],
                'client_secret' => $creds['client_secret'],
                'redirect_uri'  => self::redirect_uri(),
                'grant_type'    => 'authorization_code',
            ],
        ]);

        if (is_wp_error($resp)) return $resp;

        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!empty($body['access_token'])) {
            $tokens = [
                'access_token'  => $body['access_token'],
                'refresh_token' => $body['refresh_token'] ?? '',
                'expires_at'    => time() + ($body['expires_in'] ?? 3600),
            ];
            update_option(self::OPTION_TOKENS, $tokens);
            return $tokens;
        }

        return new WP_Error('token_error', $body['error_description'] ?? 'Failed to get tokens');
    }

    /**
     * Get a valid access token, refreshing if expired
     */
    public static function get_access_token() {
        $tokens = get_option(self::OPTION_TOKENS, []);
        if (empty($tokens['access_token'])) return '';

        // Still valid
        if (!empty($tokens['expires_at']) && $tokens['expires_at'] > time() + 60) {
            return $tokens['access_token'];
        }

        // Refresh
        if (empty($tokens['refresh_token'])) return '';

        $creds = get_option(self::OPTION_CREDS, []);
        $resp = wp_remote_post(self::TOKEN_URL, [
            'body' => [
                'refresh_token' => $tokens['refresh_token'],
                'client_id'     => $creds['client_id'],
                'client_secret' => $creds['client_secret'],
                'grant_type'    => 'refresh_token',
            ],
        ]);

        if (is_wp_error($resp)) return '';

        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!empty($body['access_token'])) {
            $tokens['access_token'] = $body['access_token'];
            $tokens['expires_at'] = time() + ($body['expires_in'] ?? 3600);
            update_option(self::OPTION_TOKENS, $tokens);
            return $tokens['access_token'];
        }

        return '';
    }

    /**
     * Check if Google Calendar is connected
     */
    public static function is_connected() {
        $tokens = get_option(self::OPTION_TOKENS, []);
        return !empty($tokens['refresh_token']);
    }

    /**
     * Disconnect Google Calendar
     */
    public static function disconnect() {
        delete_option(self::OPTION_TOKENS);
    }

    // ═══════════════════════════════════════
    // GOOGLE CALENDAR API
    // ═══════════════════════════════════════

    private static function api_request($method, $endpoint, $body = null) {
        $token = self::get_access_token();
        if (!$token) return new WP_Error('no_token', 'Not connected to Google Calendar');

        $cal_id = get_option(self::OPTION_CAL_ID, 'primary');
        $url = self::CAL_API . '/calendars/' . urlencode($cal_id) . $endpoint;

        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 15,
        ];
        if ($body) $args['body'] = json_encode($body);

        $resp = wp_remote_request($url, $args);
        if (is_wp_error($resp)) return $resp;

        $code = wp_remote_retrieve_response_code($resp);
        $data = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code >= 400) {
            return new WP_Error('gcal_error', $data['error']['message'] ?? 'Google Calendar API error', ['status' => $code]);
        }

        return $data;
    }

    /**
     * Create a Google Calendar event for a scheduled call
     */
    public static function create_event($call) {
        $start = new DateTime($call['scheduled_at']);
        $end = clone $start;
        $end->modify('+' . ($call['duration_minutes'] ?? 15) . ' minutes');

        $desc = "Call Type: " . ($call['call_type'] ?? 'follow_up') . "\n";
        if (!empty($call['contact_phone'])) $desc .= "Phone: " . $call['contact_phone'] . "\n";
        if (!empty($call['contact_email'])) $desc .= "Email: " . $call['contact_email'] . "\n";
        if (!empty($call['notes'])) $desc .= "\nNotes:\n" . $call['notes'];

        $event = [
            'summary'     => 'PTP Call: ' . ($call['contact_name'] ?: 'Unknown'),
            'description' => $desc,
            'start'       => [
                'dateTime' => $start->format('c'),
                'timeZone' => wp_timezone_string(),
            ],
            'end' => [
                'dateTime' => $end->format('c'),
                'timeZone' => wp_timezone_string(),
            ],
            'reminders' => [
                'useDefault' => false,
                'overrides'  => [
                    ['method' => 'popup', 'minutes' => 10],
                ],
            ],
            'colorId' => self::get_color_id($call['call_type'] ?? ''),
        ];

        return self::api_request('POST', '/events', $event);
    }

    /**
     * Update a Google Calendar event
     */
    public static function update_event($event_id, $updates) {
        return self::api_request('PATCH', '/events/' . urlencode($event_id), $updates);
    }

    /**
     * Delete a Google Calendar event
     */
    public static function delete_event($event_id) {
        return self::api_request('DELETE', '/events/' . urlencode($event_id));
    }

    /**
     * Get upcoming events from Google Calendar
     */
    public static function get_events($max = 20, $days_ahead = 14) {
        $token = self::get_access_token();
        if (!$token) return [];

        $cal_id = get_option(self::OPTION_CAL_ID, 'primary');
        $now = new DateTime('now', wp_timezone());
        $future = clone $now;
        $future->modify('+' . $days_ahead . ' days');

        $url = self::CAL_API . '/calendars/' . urlencode($cal_id) . '/events?' . http_build_query([
            'timeMin'      => $now->format('c'),
            'timeMax'      => $future->format('c'),
            'maxResults'   => $max,
            'singleEvents' => 'true',
            'orderBy'      => 'startTime',
            'q'            => 'PTP',
        ]);

        $resp = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 15,
        ]);

        if (is_wp_error($resp)) return [];
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        return $data['items'] ?? [];
    }

    /**
     * Color IDs for different call types
     */
    private static function get_color_id($type) {
        $map = [
            'follow_up'   => '5',  // banana (yellow)
            'intro'       => '10', // basil (green)
            'consultation'=> '7',  // peacock (blue)
            'closing'     => '6',  // tangerine (orange)
            'support'     => '3',  // grape (purple)
        ];
        return $map[$type] ?? '5';
    }

    // ═══════════════════════════════════════
    // OPENPHONE CALL WEBHOOK HANDLER
    // ═══════════════════════════════════════

    /**
     * Handle OpenPhone webhook for call events
     * POST to /wp-json/ptp-cc/v1/webhooks/openphone-call
     */
    public static function handle_call_webhook($request) {
        $body = $request->get_json_params();
        $event_type = $body['type'] ?? '';

        // OpenPhone sends call.completed, call.ringing, etc.
        if (strpos($event_type, 'call.completed') === false && strpos($event_type, 'call.ended') === false) {
            return ['ok' => true, 'skipped' => true];
        }

        $call_data = $body['data'] ?? $body['object'] ?? [];
        if (empty($call_data)) return ['ok' => true, 'no_data' => true];

        global $wpdb;
        $log_table = $wpdb->prefix . 'ptp_cc_call_log';

        // Prevent duplicates
        $op_id = $call_data['id'] ?? '';
        if ($op_id) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $log_table WHERE openphone_call_id=%s", $op_id
            ));
            if ($exists) return ['ok' => true, 'duplicate' => true];
        }

        $direction = $call_data['direction'] ?? 'outbound';
        $from = $call_data['from'] ?? '';
        $to = $call_data['to'] ?? '';
        $duration = intval($call_data['duration'] ?? $call_data['completedDuration'] ?? 0);
        $status = $call_data['status'] ?? 'completed';

        // Try to match contact by phone number
        $contact_phone = $direction === 'inbound' ? $from : $to;
        $phone_suffix = substr(preg_replace('/\D/', '', $contact_phone), -10);
        $contact_name = '';
        $app_id = 0;
        $family_id = 0;

        if ($phone_suffix) {
            // Check pipeline
            $app = $wpdb->get_row($wpdb->prepare(
                "SELECT id, parent_name FROM " . CC_DB::apps() . " WHERE phone LIKE %s LIMIT 1",
                '%' . $phone_suffix
            ));
            if ($app) {
                $contact_name = $app->parent_name;
                $app_id = $app->id;
            }

            // Check families
            if (!$contact_name) {
                $parent = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, display_name FROM " . CC_DB::parents() . " WHERE phone LIKE %s LIMIT 1",
                    '%' . $phone_suffix
                ));
                if ($parent) {
                    $contact_name = $parent->display_name;
                    $family_id = $parent->id;
                }
            }
        }

        $wpdb->insert($log_table, [
            'direction'          => $direction,
            'from_number'        => sanitize_text_field($from),
            'to_number'          => sanitize_text_field($to),
            'contact_name'       => sanitize_text_field($contact_name),
            'duration_seconds'   => $duration,
            'status'             => sanitize_text_field($status),
            'recording_url'      => esc_url_raw($call_data['recording'] ?? $call_data['recordingUrl'] ?? ''),
            'openphone_call_id'  => sanitize_text_field($op_id),
            'application_id'     => $app_id,
            'family_id'          => $family_id,
        ]);

        // Auto-log in activity log
        $activity_table = $wpdb->prefix . 'ptp_cc_activity_log';
        if ($wpdb->get_var("SHOW TABLES LIKE '$activity_table'") === $activity_table) {
            $wpdb->insert($activity_table, [
                'type'    => 'call_' . $direction,
                'summary' => ucfirst($direction) . ' call ' . ($contact_name ? "with $contact_name" : "to $contact_phone") . " ({$duration}s)",
                'ref_id'  => $app_id ?: $family_id,
            ]);
        }

        // If there's a scheduled call for this number around this time, mark it completed
        $scheduled = $wpdb->prefix . 'ptp_cc_scheduled_calls';
        if ($phone_suffix && $wpdb->get_var("SHOW TABLES LIKE '$scheduled'") === $scheduled) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $scheduled SET status='completed', completed_at=NOW(), outcome='call_completed'
                 WHERE status='scheduled' AND contact_phone LIKE %s
                 AND scheduled_at BETWEEN DATE_SUB(NOW(), INTERVAL 30 MINUTE) AND DATE_ADD(NOW(), INTERVAL 30 MINUTE)
                 LIMIT 1",
                '%' . $phone_suffix
            ));
        }

        return ['ok' => true, 'logged' => true, 'contact' => $contact_name];
    }

    // ═══════════════════════════════════════
    // REST API ROUTES
    // ═══════════════════════════════════════

    public static function register_routes() {
        $ns = 'ptp-cc/v1';

        // Google Calendar OAuth
        register_rest_route($ns, '/gcal/status', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_status'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);
        register_rest_route($ns, '/gcal/connect', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'api_connect'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);
        register_rest_route($ns, '/gcal/disconnect', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'api_disconnect'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);
        register_rest_route($ns, '/gcal/events', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_events'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);

        // Scheduled calls
        register_rest_route($ns, '/calls/scheduled', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_get_scheduled'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);
        register_rest_route($ns, '/calls/schedule', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'api_schedule_call'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);
        register_rest_route($ns, '/calls/scheduled/(?P<id>\d+)', [
            'methods' => 'PATCH', 'callback' => [__CLASS__, 'api_update_scheduled'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);
        register_rest_route($ns, '/calls/scheduled/(?P<id>\d+)', [
            'methods' => 'DELETE', 'callback' => [__CLASS__, 'api_delete_scheduled'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);
        register_rest_route($ns, '/calls/complete/(?P<id>\d+)', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'api_complete_call'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);

        // Call log
        register_rest_route($ns, '/calls/log', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_get_log'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);
        register_rest_route($ns, '/calls/log', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'api_log_call'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);

        // Call stats
        register_rest_route($ns, '/calls/stats', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_call_stats'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);

        // OpenPhone call webhook (no auth — webhook secret validated)
        register_rest_route($ns, '/webhooks/openphone-call', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'handle_call_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    // ═══════════════════════════════════════
    // API HANDLERS
    // ═══════════════════════════════════════

    public static function api_status() {
        $creds = get_option(self::OPTION_CREDS, []);
        return [
            'connected'    => self::is_connected(),
            'has_creds'    => !empty($creds['client_id']),
            'calendar_id'  => get_option(self::OPTION_CAL_ID, 'primary'),
            'auth_url'     => self::get_auth_url(),
            'redirect_uri' => self::redirect_uri(),
        ];
    }

    public static function api_connect($req) {
        $body = $req->get_json_params();

        // Save credentials if provided
        if (!empty($body['client_id'])) {
            update_option(self::OPTION_CREDS, [
                'client_id'     => sanitize_text_field($body['client_id']),
                'client_secret' => sanitize_text_field($body['client_secret'] ?? ''),
            ]);
        }
        if (!empty($body['calendar_id'])) {
            update_option(self::OPTION_CAL_ID, sanitize_text_field($body['calendar_id']));
        }

        return ['auth_url' => self::get_auth_url(), 'redirect_uri' => self::redirect_uri()];
    }

    public static function api_disconnect() {
        self::disconnect();
        return ['disconnected' => true];
    }

    public static function api_events() {
        if (!self::is_connected()) return ['events' => [], 'connected' => false];
        return ['events' => self::get_events(30, 14), 'connected' => true];
    }

    // ── Scheduled Calls ──

    public static function api_get_scheduled($req) {
        global $wpdb;
        $t = $wpdb->prefix . 'ptp_cc_scheduled_calls';
        $status = $req->get_param('status') ?: 'scheduled';
        $limit = min((int)($req->get_param('limit') ?: 50), 200);

        $where = $status === 'all' ? '1=1' : $wpdb->prepare('status=%s', $status);
        $calls = $wpdb->get_results("SELECT * FROM $t WHERE $where ORDER BY scheduled_at ASC LIMIT $limit");

        // Count by status
        $counts = $wpdb->get_results("SELECT status, COUNT(*) as cnt FROM $t GROUP BY status", OBJECT_K);

        return ['calls' => $calls, 'counts' => $counts];
    }

    public static function api_schedule_call($req) {
        global $wpdb;
        $b = $req->get_json_params();
        $t = $wpdb->prefix . 'ptp_cc_scheduled_calls';

        $data = [
            'contact_name'     => sanitize_text_field($b['contact_name'] ?? ''),
            'contact_phone'    => sanitize_text_field($b['contact_phone'] ?? ''),
            'contact_email'    => sanitize_email($b['contact_email'] ?? ''),
            'application_id'   => (int)($b['application_id'] ?? 0),
            'family_id'        => (int)($b['family_id'] ?? 0),
            'call_type'        => sanitize_text_field($b['call_type'] ?? 'follow_up'),
            'scheduled_at'     => sanitize_text_field($b['scheduled_at'] ?? ''),
            'duration_minutes' => (int)($b['duration_minutes'] ?? 15),
            'notes'            => sanitize_textarea_field($b['notes'] ?? ''),
            'status'           => 'scheduled',
            'created_by'       => get_current_user_id(),
        ];

        if (empty($data['scheduled_at'])) {
            return new WP_Error('missing', 'scheduled_at is required', ['status' => 400]);
        }

        $wpdb->insert($t, $data);
        $id = $wpdb->insert_id;

        // Sync to Google Calendar if connected
        $gcal_event_id = '';
        if (self::is_connected()) {
            $data['id'] = $id;
            $result = self::create_event($data);
            if (!is_wp_error($result) && !empty($result['id'])) {
                $gcal_event_id = $result['id'];
                $wpdb->update($t, ['gcal_event_id' => $gcal_event_id], ['id' => $id]);
            }
        }

        return ['id' => $id, 'gcal_synced' => !empty($gcal_event_id), 'gcal_event_id' => $gcal_event_id];
    }

    public static function api_update_scheduled($req) {
        global $wpdb;
        $id = (int)$req['id'];
        $b = $req->get_json_params();
        $t = $wpdb->prefix . 'ptp_cc_scheduled_calls';

        $data = [];
        $allowed = ['contact_name','contact_phone','contact_email','call_type','scheduled_at','duration_minutes','notes','status'];
        foreach ($allowed as $f) {
            if (isset($b[$f])) $data[$f] = is_numeric($b[$f]) ? $b[$f] : sanitize_text_field($b[$f]);
        }

        if (!empty($data)) {
            $wpdb->update($t, $data, ['id' => $id]);

            // Update Google Calendar event if linked
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id));
            if ($row && $row->gcal_event_id && self::is_connected()) {
                $updates = [];
                if (isset($data['scheduled_at'])) {
                    $start = new DateTime($data['scheduled_at']);
                    $end = clone $start;
                    $end->modify('+' . ($row->duration_minutes ?: 15) . ' minutes');
                    $updates['start'] = ['dateTime' => $start->format('c'), 'timeZone' => wp_timezone_string()];
                    $updates['end']   = ['dateTime' => $end->format('c'), 'timeZone' => wp_timezone_string()];
                }
                if (isset($data['contact_name'])) {
                    $updates['summary'] = 'PTP Call: ' . $data['contact_name'];
                }
                if (!empty($updates)) {
                    self::update_event($row->gcal_event_id, $updates);
                }
            }
        }

        return ['updated' => true];
    }

    public static function api_delete_scheduled($req) {
        global $wpdb;
        $id = (int)$req['id'];
        $t = $wpdb->prefix . 'ptp_cc_scheduled_calls';

        $row = $wpdb->get_row($wpdb->prepare("SELECT gcal_event_id FROM $t WHERE id=%d", $id));
        if ($row && $row->gcal_event_id && self::is_connected()) {
            self::delete_event($row->gcal_event_id);
        }

        $wpdb->delete($t, ['id' => $id]);
        return ['deleted' => true];
    }

    public static function api_complete_call($req) {
        global $wpdb;
        $id = (int)$req['id'];
        $b = $req->get_json_params();
        $t = $wpdb->prefix . 'ptp_cc_scheduled_calls';

        $wpdb->update($t, [
            'status'       => 'completed',
            'completed_at' => current_time('mysql'),
            'outcome'      => sanitize_text_field($b['outcome'] ?? 'completed'),
            'outcome_notes'=> sanitize_textarea_field($b['outcome_notes'] ?? ''),
        ], ['id' => $id]);

        // Mark Google Calendar event as done
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id));
        if ($row && $row->gcal_event_id && self::is_connected()) {
            self::update_event($row->gcal_event_id, [
                'summary'  => '[DONE] PTP Call: ' . $row->contact_name,
                'colorId'  => '8', // graphite
            ]);
        }

        // Also log in call log
        $log_t = $wpdb->prefix . 'ptp_cc_call_log';
        $wpdb->insert($log_t, [
            'direction'      => 'outbound',
            'to_number'      => $row->contact_phone ?? '',
            'contact_name'   => $row->contact_name ?? '',
            'contact_email'  => $row->contact_email ?? '',
            'status'         => 'completed',
            'notes'          => sanitize_textarea_field($b['outcome_notes'] ?? ''),
            'application_id' => $row->application_id ?? 0,
            'family_id'      => $row->family_id ?? 0,
        ]);

        return ['completed' => true];
    }

    // ── Call Log ──

    public static function api_get_log($req) {
        global $wpdb;
        $t = $wpdb->prefix . 'ptp_cc_call_log';
        $limit = min((int)($req->get_param('limit') ?: 100), 500);
        $direction = $req->get_param('direction');

        $where = '1=1';
        $params = [];
        if ($direction) {
            $where = 'direction=%s';
            $params[] = $direction;
        }

        $sql = "SELECT * FROM $t WHERE $where ORDER BY created_at DESC LIMIT %d";
        $params[] = $limit;
        $calls = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        return ['calls' => $calls];
    }

    public static function api_log_call($req) {
        global $wpdb;
        $b = $req->get_json_params();
        $t = $wpdb->prefix . 'ptp_cc_call_log';

        $wpdb->insert($t, [
            'direction'      => sanitize_text_field($b['direction'] ?? 'outbound'),
            'from_number'    => sanitize_text_field($b['from_number'] ?? ''),
            'to_number'      => sanitize_text_field($b['to_number'] ?? ''),
            'contact_name'   => sanitize_text_field($b['contact_name'] ?? ''),
            'contact_email'  => sanitize_email($b['contact_email'] ?? ''),
            'duration_seconds'=> (int)($b['duration_seconds'] ?? 0),
            'status'         => sanitize_text_field($b['status'] ?? 'completed'),
            'notes'          => sanitize_textarea_field($b['notes'] ?? ''),
            'application_id' => (int)($b['application_id'] ?? 0),
            'family_id'      => (int)($b['family_id'] ?? 0),
        ]);

        return ['id' => $wpdb->insert_id];
    }

    // ── Stats ──

    public static function api_call_stats() {
        global $wpdb;
        $sched = $wpdb->prefix . 'ptp_cc_scheduled_calls';
        $log = $wpdb->prefix . 'ptp_cc_call_log';

        $result = [
            'today_scheduled'  => 0, 'today_completed'  => 0,
            'week_scheduled'   => 0, 'week_completed'   => 0,
            'overdue'          => 0, 'total_calls'      => 0,
            'avg_duration'     => 0, 'inbound_today'    => 0,
        ];

        if ($wpdb->get_var("SHOW TABLES LIKE '$sched'") === $sched) {
            $result['today_scheduled'] = (int)$wpdb->get_var(
                "SELECT COUNT(*) FROM $sched WHERE status='scheduled' AND DATE(scheduled_at)=CURDATE()"
            );
            $result['today_completed'] = (int)$wpdb->get_var(
                "SELECT COUNT(*) FROM $sched WHERE status='completed' AND DATE(completed_at)=CURDATE()"
            );
            $result['week_scheduled'] = (int)$wpdb->get_var(
                "SELECT COUNT(*) FROM $sched WHERE status='scheduled' AND YEARWEEK(scheduled_at)=YEARWEEK(NOW())"
            );
            $result['overdue'] = (int)$wpdb->get_var(
                "SELECT COUNT(*) FROM $sched WHERE status='scheduled' AND scheduled_at < NOW()"
            );
        }

        if ($wpdb->get_var("SHOW TABLES LIKE '$log'") === $log) {
            $result['total_calls'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM $log");
            $result['avg_duration'] = (int)$wpdb->get_var(
                "SELECT AVG(duration_seconds) FROM $log WHERE duration_seconds > 0"
            );
            $result['inbound_today'] = (int)$wpdb->get_var(
                "SELECT COUNT(*) FROM $log WHERE direction='inbound' AND DATE(created_at)=CURDATE()"
            );
        }

        return $result;
    }

    // ═══════════════════════════════════════
    // ADMIN OAUTH CALLBACK PAGE
    // ═══════════════════════════════════════

    public static function register_admin_page() {
        add_submenu_page(null, 'GCal Callback', 'GCal Callback', 'manage_options', 'ptp-cc-gcal-callback', [__CLASS__, 'handle_callback_page']);
    }

    public static function handle_callback_page() {
        $code = $_GET['code'] ?? '';
        $state = $_GET['state'] ?? '';
        $error = $_GET['error'] ?? '';

        if ($error) {
            echo '<div class="notice notice-error"><p>Google Calendar connection failed: ' . esc_html($error) . '</p></div>';
            echo '<p><a href="' . admin_url('admin.php?page=ptp-engine') . '">Back to PTP Engine</a></p>';
            return;
        }

        if (!$code || !wp_verify_nonce($state, 'ptp_cc_gcal_auth')) {
            echo '<div class="notice notice-error"><p>Invalid callback. Please try again.</p></div>';
            return;
        }

        $result = self::exchange_code($code);

        if (is_wp_error($result)) {
            echo '<div class="notice notice-error"><p>Failed: ' . esc_html($result->get_error_message()) . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>Google Calendar connected successfully!</p></div>';
        }

        echo '<script>setTimeout(function(){ window.location.href = "' . esc_url(admin_url('admin.php?page=ptp-engine')) . '"; }, 2000);</script>';
        echo '<p>Redirecting to Command Center...</p>';
    }
}
