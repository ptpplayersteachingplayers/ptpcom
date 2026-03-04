<?php
/**
 * PTP Command Center — Unified Inbox v2
 *
 * Fixed against real OpenPhone API & ptp-training-platform-v138.
 *
 * Critical fixes over v1:
 *   1. Resolves phoneNumberId from phone number via /v1/phone-numbers
 *      (ptp_openphone_from stores "+16106714778", not "PNxxxxxxx")
 *   2. Uses http_build_query for participants[] array encoding
 *   3. Reads 'text' field from OpenPhone messages (not 'body')
 *   4. Falls back to TP's PTP_OpenPhone_Bridge for message reads
 *
 * @since 6.2
 */
if (!defined('ABSPATH')) exit;

class CC_Inbox {

    /** Cache resolved phoneNumberId. */
    private static $phone_number_id = null;

    public static function register_routes($ns) {
        register_rest_route($ns, '/inbox/contacts', [
            'methods'  => 'GET',
            'callback' => [__CLASS__, 'api_contacts'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);
        register_rest_route($ns, '/inbox/thread/(?P<phone>[^/]+)', [
            'methods'  => 'GET',
            'callback' => [__CLASS__, 'api_thread'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);
        register_rest_route($ns, '/inbox/send', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'api_send'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);
        register_rest_route($ns, '/inbox/sync/(?P<phone>[^/]+)', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'api_sync_thread'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);
        register_rest_route($ns, '/inbox/sync', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'api_sync_all'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);
        register_rest_route($ns, '/inbox/send-check', [
            'methods'  => 'GET',
            'callback' => [__CLASS__, 'api_send_check'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);
    }

    // ═══════════════════════════════════════
    //  CONTACTS LIST
    // ═══════════════════════════════════════

    public static function api_contacts($req) {
        global $wpdb;

        $search = sanitize_text_field($req->get_param('search') ?: '');
        $page   = max(1, (int)($req->get_param('page') ?: 1));
        $per_page = min(100, max(20, (int)($req->get_param('per_page') ?: 50)));

        $at = CC_DB::apps();
        $pt = CC_DB::parents();
        $mt = CC_DB::op_msgs();

        // ── 1. Gather all unique phones with context ──

        $app_contacts = $wpdb->get_results(
            "SELECT a.id as app_id, a.parent_name as name, a.phone, a.child_name as child,
                    a.status, a.trainer_name as trainer, a.lead_temperature as temp, 'app' as source
             FROM $at a WHERE a.phone IS NOT NULL AND a.phone != '' ORDER BY a.created_at DESC"
        );

        $parent_contacts = $wpdb->get_results(
            "SELECT p.id as parent_id, p.display_name as name, p.phone, p.email, 'parent' as source
             FROM $pt p WHERE p.phone IS NOT NULL AND p.phone != ''"
        );

        // Phones in op_msgs that don't match any app or parent
        $orphan_phones = $wpdb->get_results(
            "SELECT DISTINCT m.phone FROM $mt m
             WHERE m.phone NOT IN (SELECT DISTINCT phone FROM $at WHERE phone IS NOT NULL AND phone != '')
             AND m.phone NOT IN (SELECT DISTINCT phone FROM $pt WHERE phone IS NOT NULL AND phone != '')
             GROUP BY m.phone"
        );

        // ── 2. Merge into unified map (keyed by last 10 digits) ──
        $contacts = [];

        foreach ($app_contacts as $a) {
            $key = self::phone_key($a->phone);
            if (!$key || isset($contacts[$key])) continue;
            $contacts[$key] = [
                'phone' => CC_DB::normalize_phone($a->phone), 'name' => $a->name ?: '',
                'child' => $a->child ?: '', 'status' => $a->status ?: '',
                'trainer' => $a->trainer ?: '', 'temp' => $a->temp ?: '',
                'app_id' => (int)$a->app_id, 'source' => 'pipeline',
            ];
        }

        foreach ($parent_contacts as $p) {
            $key = self::phone_key($p->phone);
            if (!$key) continue;
            if (!isset($contacts[$key])) {
                $contacts[$key] = [
                    'phone' => CC_DB::normalize_phone($p->phone), 'name' => $p->name ?: '',
                    'child' => '', 'status' => '', 'trainer' => '', 'temp' => '',
                    'app_id' => null, 'source' => 'training',
                ];
            } elseif (empty($contacts[$key]['name']) && $p->name) {
                $contacts[$key]['name'] = $p->name;
            }
        }

        foreach ($orphan_phones as $o) {
            $key = self::phone_key($o->phone);
            if (!$key || isset($contacts[$key])) continue;
            $contacts[$key] = [
                'phone' => $o->phone, 'name' => '', 'child' => '', 'status' => '',
                'trainer' => '', 'temp' => '', 'app_id' => null, 'source' => 'openphone',
            ];
        }

        // ── 3. Bulk fetch message stats (single query instead of N+1) ──
        $msg_stats = $wpdb->get_results(
            "SELECT phone,
                    MAX(created_at) as last_at,
                    MAX(CASE WHEN direction='outgoing' THEN created_at END) as last_out_at,
                    SUBSTRING_INDEX(GROUP_CONCAT(body ORDER BY created_at DESC SEPARATOR '|||'), '|||', 1) as last_body,
                    SUBSTRING_INDEX(GROUP_CONCAT(direction ORDER BY created_at DESC SEPARATOR '|||'), '|||', 1) as last_dir,
                    COUNT(*) as msg_count,
                    SUM(CASE WHEN direction='incoming' THEN 1 ELSE 0 END) as incoming_count
             FROM $mt
             GROUP BY phone"
        );

        // Index by phone key
        $stats_map = [];
        foreach ($msg_stats as $s) {
            $key = self::phone_key($s->phone);
            if ($key) $stats_map[$key] = $s;
        }

        // Bulk unread counts: incoming messages after last outgoing, per phone
        $unread_rows = $wpdb->get_results(
            "SELECT m.phone, COUNT(*) as unread
             FROM $mt m
             WHERE m.direction = 'incoming'
             AND m.created_at > COALESCE(
                 (SELECT MAX(m2.created_at) FROM $mt m2 WHERE m2.phone = m.phone AND m2.direction = 'outgoing'),
                 '1970-01-01'
             )
             GROUP BY m.phone"
        );
        $unread_map = [];
        foreach ($unread_rows as $u) {
            $key = self::phone_key($u->phone);
            if ($key) $unread_map[$key] = (int)$u->unread;
        }

        // ── 4. Attach stats to contacts ──
        foreach ($contacts as $key => &$c) {
            $s = $stats_map[$key] ?? null;
            if ($s) {
                $c['last_msg']  = substr($s->last_body, 0, 80);
                $c['last_dir']  = $s->last_dir;
                $c['last_at']   = $s->last_at;
                $c['msg_count'] = (int)$s->msg_count;
                $c['unread']    = $unread_map[$key] ?? 0;
            } else {
                $c['last_msg']  = '';
                $c['last_dir']  = '';
                $c['last_at']   = '';
                $c['unread']    = 0;
                $c['msg_count'] = 0;
            }
        }
        unset($c);

        // ── 5. Search filter ──
        if ($search) {
            $sl = strtolower($search);
            $contacts = array_filter($contacts, function ($c) use ($sl) {
                return stripos($c['name'], $sl) !== false
                    || stripos($c['phone'], $sl) !== false
                    || stripos($c['child'], $sl) !== false;
            });
        }

        // ── 6. Sort: unread first, then most recent message, then by name ──
        usort($contacts, function ($a, $b) {
            // Unread always first
            if ($a['unread'] > 0 && $b['unread'] == 0) return -1;
            if ($b['unread'] > 0 && $a['unread'] == 0) return 1;
            // Then by most recent message
            if ($a['last_at'] && $b['last_at']) return strcmp($b['last_at'], $a['last_at']);
            if ($a['last_at']) return -1;
            if ($b['last_at']) return 1;
            // Contacts with no messages: sort by name
            return strcmp($a['name'], $b['name']);
        });

        $all = array_values($contacts);
        $total_contacts = count($all);
        $total_pages = max(1, ceil($total_contacts / $per_page));
        $page = min($page, $total_pages);
        $offset = ($page - 1) * $per_page;
        $paged = array_slice($all, $offset, $per_page);

        $total_unread = 0;
        foreach ($all as $c) $total_unread += $c['unread'];

        return [
            'contacts'      => $paged,
            'total'         => $total_contacts,
            'total_unread'  => $total_unread,
            'page'          => $page,
            'per_page'      => $per_page,
            'total_pages'   => $total_pages,
        ];
    }

    // ═══════════════════════════════════════
    //  CONVERSATION THREAD
    // ═══════════════════════════════════════

    public static function api_thread($req) {
        global $wpdb;
        $phone = CC_DB::normalize_phone(urldecode($req['phone']));
        $sync  = $req->get_param('sync') === '1';
        $mt    = CC_DB::op_msgs();

        // Auto-sync from OpenPhone if we haven't in the last 5 minutes
        $sync_key = 'ptp_cc_thread_sync_' . md5($phone);
        $last_sync = get_transient($sync_key);
        $did_sync = false;
        if ($sync || !$last_sync) {
            $result = self::sync_from_openphone($phone);
            if (!is_wp_error($result)) {
                set_transient($sync_key, time(), 5 * MINUTE_IN_SECONDS);
                $did_sync = true;
            }
        }

        $msgs = $wpdb->get_results($wpdb->prepare(
            "SELECT id, direction, body, openphone_msg_id, created_at
             FROM $mt WHERE phone=%s ORDER BY created_at DESC LIMIT 100", $phone
        ));

        return [
            'phone' => $phone, 'messages' => $msgs ?: [],
            'context' => self::get_contact_context($phone), 'synced' => $did_sync,
        ];
    }

    // ═══════════════════════════════════════
    //  SEND MESSAGE
    // ═══════════════════════════════════════

    public static function api_send($req) {
        $b    = $req->get_json_params();
        $to   = sanitize_text_field($b['to'] ?? '');
        $body = sanitize_textarea_field($b['body'] ?? '');
        if (!$to || !$body) return new WP_Error('missing', 'to and body required', ['status' => 400]);

        $phone  = CC_DB::normalize_phone($to);
        $result = CC_DB::send_sms($phone, $body);

        if (is_wp_error($result)) {
            error_log('[PTP-CC Inbox] Send failed for ' . $phone . ': ' . $result->get_error_message());
            return new WP_Error('sms_fail', $result->get_error_message(), ['status' => 500]);
        }

        if ($result === false) {
            error_log('[PTP-CC Inbox] Send returned false for ' . $phone);
            return new WP_Error('sms_fail', 'SMS send returned false — check error log', ['status' => 500]);
        }

        return ['success' => true, 'phone' => $phone];
    }

    /**
     * Diagnostics: check what send path is available and why it might fail.
     * Hit /wp-json/ptp-cc/v1/inbox/send-check to see status.
     */
    public static function api_send_check() {
        $diag = [
            'ptp_sms_v71_exists' => class_exists('PTP_SMS_V71'),
            'ptp_sms_exists'     => class_exists('PTP_SMS'),
            'openphone_api_key'  => !empty(get_option('ptp_openphone_api_key', '')),
            'cc_api_key'         => !empty(get_option('ptp_cc_openphone_api_key', '')),
            'openphone_from'     => get_option('ptp_openphone_from', ''),
            'cc_phone_id'        => get_option('ptp_cc_openphone_phone_id', ''),
            'resolved_pnid'      => get_transient('ptp_cc_op_phone_id') ?: 'not cached',
        ];

        // Determine which path send will use
        if ($diag['ptp_sms_v71_exists']) {
            $diag['send_path'] = 'PTP_SMS_V71 (Training Platform v71)';
            $diag['sms_enabled'] = class_exists('PTP_SMS_V71') && method_exists('PTP_SMS_V71', 'is_enabled') ? PTP_SMS_V71::is_enabled() : 'unknown';
        } elseif ($diag['ptp_sms_exists']) {
            $diag['send_path'] = 'PTP_SMS (Training Platform)';
        } elseif ($diag['openphone_api_key'] || $diag['cc_api_key']) {
            $diag['send_path'] = 'CC direct → OpenPhone API';
        } else {
            $diag['send_path'] = 'NONE — no SMS method available!';
        }

        // Check retry queue
        global $wpdb;
        $rq = CC_DB::retry_queue();
        if ($wpdb->get_var("SHOW TABLES LIKE '$rq'") === $rq) {
            $diag['retry_pending'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM $rq WHERE retry_count < 3");
            $diag['retry_failed']  = (int)$wpdb->get_var("SELECT COUNT(*) FROM $rq WHERE retry_count >= 3");
            $diag['retry_recent']  = $wpdb->get_results("SELECT phone, last_error, retry_count, created_at FROM $rq ORDER BY created_at DESC LIMIT 5");
        } else {
            $diag['retry_table'] = 'not created yet — visit wp-admin to trigger DB upgrade';
        }

        return $diag;
    }

    // ═══════════════════════════════════════
    //  OPENPHONE SYNC
    // ═══════════════════════════════════════

    public static function api_sync_thread($req) {
        $phone  = CC_DB::normalize_phone(urldecode($req['phone']));
        $result = self::sync_from_openphone($phone);
        if (is_wp_error($result)) return $result;
        return ['synced' => $result['imported'], 'skipped' => $result['skipped'], 'phone' => $phone];
    }

    /**
     * Bulk sync: pull recent messages from OpenPhone.
     *
     * Strategy: get all phones in our contacts, sync each one.
     * (TP doesn't use /v1/conversations either — it syncs per-phone.)
     */
    public static function api_sync_all() {
        $key = self::get_op_key();
        if (!$key) return new WP_Error('no_config', 'OpenPhone API key not configured', ['status' => 400]);

        $pnid = self::resolve_phone_number_id();
        if (!$pnid) return new WP_Error('no_phone_id', 'Could not resolve OpenPhone phoneNumberId. Check API key and phone number.', ['status' => 400]);

        global $wpdb;
        $mt = CC_DB::op_msgs();

        // Get all unique phones we have messages for + all app/parent phones
        $phones = $wpdb->get_col("SELECT DISTINCT phone FROM $mt WHERE phone != '' AND phone IS NOT NULL");
        $app_phones = $wpdb->get_col("SELECT DISTINCT phone FROM " . CC_DB::apps() . " WHERE phone IS NOT NULL AND phone != ''");
        $parent_phones = $wpdb->get_col("SELECT DISTINCT phone FROM " . CC_DB::parents() . " WHERE phone IS NOT NULL AND phone != ''");

        // Merge and normalize
        $all_phones = [];
        foreach (array_merge($phones, $app_phones, $parent_phones) as $p) {
            $norm = CC_DB::normalize_phone($p);
            $key10 = substr(preg_replace('/\D/', '', $norm), -10);
            if ($key10 && !isset($all_phones[$key10])) {
                $all_phones[$key10] = $norm;
            }
        }

        $total_imported = 0;
        $contacts_synced = 0;
        $errors = 0;

        foreach ($all_phones as $phone) {
            $result = self::sync_from_openphone($phone);
            if (!is_wp_error($result)) {
                $total_imported += $result['imported'];
                if ($result['imported'] > 0) $contacts_synced++;
            } else {
                $errors++;
            }

            // Rate limit: don't hammer the API
            if ($contacts_synced > 0 && $contacts_synced % 10 === 0) {
                usleep(500000); // 500ms pause every 10 contacts
            }
        }

        update_option('ptp_cc_last_op_sync', current_time('mysql'));

        return [
            'total_contacts'    => count($all_phones),
            'contacts_synced'   => $contacts_synced,
            'messages_imported' => $total_imported,
            'errors'            => $errors,
        ];
    }

    /**
     * Pull messages from OpenPhone API for a specific phone and import.
     *
     * Uses same API pattern as TP's PTP_OpenPhone_Bridge::get_messages():
     *   GET /v1/messages?phoneNumberId=PNxxx&participants[]=+1xxx&maxResults=50
     *
     * Falls back to TP's bridge class if available for reliability.
     */
    private static function sync_from_openphone($phone) {
        global $wpdb;
        $mt = CC_DB::op_msgs();

        // ── Try TP's bridge first (most reliable, already handles phoneNumberId) ──
        if (class_exists('PTP_OpenPhone_Bridge') && method_exists('PTP_OpenPhone_Bridge', 'get_messages')) {
            $tp_msgs = PTP_OpenPhone_Bridge::get_messages($phone, 50);
            if (!empty($tp_msgs) && !is_wp_error($tp_msgs)) {
                return self::import_messages($phone, $tp_msgs, 'tp_bridge');
            }
        }

        // ── Direct API call ──
        $key  = self::get_op_key();
        $pnid = self::resolve_phone_number_id();

        if (!$key || !$pnid) {
            return new WP_Error('no_config', 'OpenPhone not configured or phoneNumberId unresolvable');
        }

        // Build URL with proper array encoding (like TP does with http_build_query)
        $params = [
            'phoneNumberId' => $pnid,
            'participants'  => [$phone],
            'maxResults'    => 50,
        ];
        $url = 'https://api.openphone.com/v1/messages?' . http_build_query($params);

        $r = wp_remote_get($url, [
            'headers' => ['Authorization' => $key, 'Content-Type' => 'application/json'],
            'timeout' => 15,
        ]);

        if (is_wp_error($r)) return $r;

        $code = wp_remote_retrieve_response_code($r);
        if ($code !== 200) {
            $err = json_decode(wp_remote_retrieve_body($r), true);
            return new WP_Error('api_error', ($err['message'] ?? 'HTTP ' . $code) . " (phoneNumberId={$pnid})");
        }

        $data = json_decode(wp_remote_retrieve_body($r), true);
        $messages = $data['data'] ?? [];

        return self::import_messages($phone, $messages, 'api');
    }

    /**
     * Import messages into op_msgs table, deduplicating.
     *
     * @param string $phone  Normalized E.164 phone
     * @param array  $messages  Array of message objects (from API or TP bridge)
     * @param string $source  'api' or 'tp_bridge' (affects field names)
     */
    private static function import_messages($phone, $messages, $source) {
        global $wpdb;
        $mt = CC_DB::op_msgs();

        // Match to app/parent
        $suffix = substr(preg_replace('/\D/', '', $phone), -10);
        $app_id = $suffix ? $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . CC_DB::apps() . " WHERE phone LIKE %s ORDER BY created_at DESC LIMIT 1", '%' . $suffix
        )) : null;
        $parent_id = $suffix ? $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . CC_DB::parents() . " WHERE phone LIKE %s LIMIT 1", '%' . $suffix
        )) : null;

        $imported = 0;
        $skipped  = 0;

        foreach ($messages as $msg) {
            if ($source === 'tp_bridge') {
                // TP bridge returns: id, direction (inbound/outbound), body, created_at
                $op_id   = $msg['id'] ?? '';
                $body    = $msg['body'] ?? '';
                $dir     = ($msg['direction'] ?? '') === 'inbound' ? 'incoming' : 'outgoing';
                $created = $msg['created_at'] ?? '';
            } else {
                // Direct OpenPhone API: id, direction (incoming/outgoing), text, createdAt
                $op_id   = $msg['id'] ?? '';
                // OpenPhone returns body in 'text' field (NOT 'body')
                $body    = $msg['text'] ?? $msg['body'] ?? $msg['content'] ?? '';
                $dir     = ($msg['direction'] ?? '') === 'incoming' ? 'incoming' : 'outgoing';
                $created = $msg['createdAt'] ?? $msg['created_at'] ?? '';
            }

            // Parse timestamp
            if ($created) {
                $ts = strtotime($created);
                $created = $ts ? date('Y-m-d H:i:s', $ts) : current_time('mysql');
            } else {
                $created = current_time('mysql');
            }

            // Skip empty (media-only)
            if (!trim($body)) continue;

            // Dedupe by openphone_msg_id
            if ($op_id) {
                $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $mt WHERE openphone_msg_id=%s", $op_id));
                if ($exists) { $skipped++; continue; }
            }

            // Dedupe by phone + body + timestamp within 30 seconds
            $dup = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $mt WHERE phone=%s AND body=%s AND ABS(TIMESTAMPDIFF(SECOND, created_at, %s)) < 30",
                $phone, $body, $created
            ));
            if ($dup) {
                if ($op_id) $wpdb->update($mt, ['openphone_msg_id' => $op_id], ['id' => $dup]);
                $skipped++;
                continue;
            }

            // Insert
            $wpdb->insert($mt, [
                'app_id'           => $app_id ? (int)$app_id : null,
                'parent_id'        => $parent_id ? (int)$parent_id : null,
                'phone'            => $phone,
                'direction'        => $dir,
                'body'             => $body,
                'openphone_msg_id' => $op_id ?: null,
                'created_at'       => $created,
            ]);
            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    // ═══════════════════════════════════════
    //  OPENPHONE phoneNumberId RESOLUTION
    // ═══════════════════════════════════════

    /**
     * Resolve the OpenPhone phoneNumberId (PNxxxxxxx) from the configured
     * phone number (+16106714778).
     *
     * ptp_openphone_from stores a phone number like "+16106714778",
     * but the GET /v1/messages endpoint requires phoneNumberId (PNxxxxxxx).
     *
     * Mirrors TP's PTP_OpenPhone_Bridge::get_phone_number_id() logic:
     *   1. Check transient cache
     *   2. Call GET /v1/phone-numbers
     *   3. Match configured number → return its ID
     *   4. Cache for 24 hours
     */
    private static function resolve_phone_number_id() {
        if (self::$phone_number_id) return self::$phone_number_id;

        // Check if ptp_cc_openphone_phone_id is already a PN... ID
        $explicit = get_option('ptp_cc_openphone_phone_id', '');
        if ($explicit && strpos($explicit, 'PN') === 0) {
            self::$phone_number_id = $explicit;
            return $explicit;
        }

        // Check TP's transient cache first
        $cached = get_transient('ptp_op_phone_id');
        if ($cached) {
            self::$phone_number_id = $cached;
            return $cached;
        }

        // Check CC's own cache
        $cc_cached = get_transient('ptp_cc_op_phone_id');
        if ($cc_cached) {
            self::$phone_number_id = $cc_cached;
            return $cc_cached;
        }

        // Resolve via API
        $key = self::get_op_key();
        if (!$key) return null;

        $r = wp_remote_get('https://api.openphone.com/v1/phone-numbers', [
            'headers' => ['Authorization' => $key, 'Content-Type' => 'application/json'],
            'timeout' => 15,
        ]);

        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) {
            error_log('[PTP-CC Inbox] Failed to resolve phoneNumberId: ' .
                (is_wp_error($r) ? $r->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($r)));
            return null;
        }

        $data    = json_decode(wp_remote_retrieve_body($r), true);
        $numbers = $data['data'] ?? [];
        $from    = self::get_op_from_number();

        foreach ($numbers as $num) {
            $match_number = $num['number'] ?? '';
            $match_formatted = $num['formattedNumber'] ?? '';
            if ($match_number === $from || $match_formatted === $from) {
                $pnid = $num['id'];
                set_transient('ptp_cc_op_phone_id', $pnid, DAY_IN_SECONDS);
                self::$phone_number_id = $pnid;
                return $pnid;
            }
        }

        // Fallback: use first number
        if (!empty($numbers[0]['id'])) {
            $pnid = $numbers[0]['id'];
            set_transient('ptp_cc_op_phone_id', $pnid, DAY_IN_SECONDS);
            self::$phone_number_id = $pnid;
            error_log("[PTP-CC Inbox] Could not match '{$from}' — using first number: {$pnid}");
            return $pnid;
        }

        error_log("[PTP-CC Inbox] No phone numbers found in OpenPhone account");
        return null;
    }

    // ═══════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════

    private static function get_contact_context($phone) {
        global $wpdb;
        $suffix = substr(preg_replace('/\D/', '', $phone), -10);
        $ctx = ['phone' => $phone, 'name' => '', 'child' => '', 'status' => '', 'app_id' => null, 'source' => 'unknown'];
        if (!$suffix) return $ctx;

        $app = $wpdb->get_row($wpdb->prepare(
            "SELECT id, parent_name, child_name, status, trainer_name, lead_temperature, email
             FROM " . CC_DB::apps() . " WHERE phone LIKE %s ORDER BY created_at DESC LIMIT 1", '%' . $suffix
        ));
        if ($app) {
            return array_merge($ctx, [
                'app_id' => (int)$app->id, 'name' => $app->parent_name, 'child' => $app->child_name,
                'status' => $app->status, 'trainer' => $app->trainer_name, 'temp' => $app->lead_temperature,
                'email' => $app->email, 'source' => 'pipeline',
            ]);
        }

        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT id, display_name, email FROM " . CC_DB::parents() . " WHERE phone LIKE %s LIMIT 1", '%' . $suffix
        ));
        if ($parent) {
            return array_merge($ctx, [
                'name' => $parent->display_name, 'email' => $parent->email, 'source' => 'training',
            ]);
        }

        $ctx['source'] = 'openphone';
        return $ctx;
    }

    private static function phone_key($phone) {
        return substr(preg_replace('/\D/', '', $phone), -10) ?: '';
    }

    private static function get_op_key() {
        return get_option('ptp_openphone_api_key', '') ?: get_option('ptp_cc_openphone_api_key', '');
    }

    /** Get the configured FROM phone number (e.g., "+16106714778") */
    private static function get_op_from_number() {
        return get_option('ptp_openphone_from', '') ?: get_option('ptp_cc_openphone_phone_id', '');
    }
}
