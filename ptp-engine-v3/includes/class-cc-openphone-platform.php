<?php
/**
 * PTP Command Center — OpenPhone Platform v1.0
 * 
 * Full OpenPhone API integration hub:
 *   - Conversations (list + full thread history)
 *   - Call Intelligence (transcripts, summaries, recordings)
 *   - Voicemail retrieval & queue
 *   - Contact management with externalId tagging
 *   - Webhook management (programmatic registration)
 *   - Message backfill sync (catches msgs sent from OP app)
 *   - Call analytics & communication stats
 *
 * @since 7.0
 */
if (!defined('ABSPATH')) exit;

class CC_OpenPhone_Platform {

    private static $api_url = 'https://api.openphone.com/v1';

    // ═══════════════════════════════════════
    // CORE API HELPER
    // ═══════════════════════════════════════

    private static function get_key() {
        return get_option('ptp_openphone_api_key', '') ?: get_option('ptp_cc_openphone_api_key', '');
    }

    private static function api($method, $endpoint, $body = null, $timeout = 15) {
        $key = self::get_key();
        if (!$key) return new WP_Error('no_key', 'OpenPhone API key not configured');

        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => $key,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => $timeout,
        ];
        if ($body) $args['body'] = json_encode($body);

        $url = self::$api_url . '/' . ltrim($endpoint, '/');
        $resp = wp_remote_request($url, $args);

        if (is_wp_error($resp)) return $resp;
        $code = wp_remote_retrieve_response_code($resp);
        $data = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code >= 400) {
            error_log("[PTP-CC OP Platform] API error $code on $method $endpoint: " . wp_remote_retrieve_body($resp));
            return new WP_Error('openphone_error', $data['message'] ?? "HTTP $code", ['status' => $code]);
        }

        return $data;
    }

    /**
     * Resolve the PTP phone number to an OpenPhone phoneNumberId
     */
    private static function get_phone_number_id() {
        $cached = get_transient('ptp_cc_op_phone_number_id');
        if ($cached) return $cached;

        $from = get_option('ptp_openphone_from', '') ?: get_option('ptp_cc_openphone_phone_id', '');
        if (!$from) return new WP_Error('no_from', 'No OpenPhone phone number configured');

        // If already a phoneNumberId (starts with PN)
        if (strpos($from, 'PN') === 0) {
            set_transient('ptp_cc_op_phone_number_id', $from, DAY_IN_SECONDS);
            return $from;
        }

        // Resolve from phone number
        $result = self::api('GET', 'phone-numbers');
        if (is_wp_error($result)) return $result;

        $clean_from = preg_replace('/\D/', '', $from);
        foreach (($result['data'] ?? []) as $pn) {
            $clean_pn = preg_replace('/\D/', '', $pn['phoneNumber'] ?? '');
            if (substr($clean_pn, -10) === substr($clean_from, -10)) {
                set_transient('ptp_cc_op_phone_number_id', $pn['id'], DAY_IN_SECONDS);
                return $pn['id'];
            }
        }

        return new WP_Error('not_found', 'Could not resolve phoneNumberId');
    }

    // ═══════════════════════════════════════
    // DATABASE
    // ═══════════════════════════════════════

    public static function table_call_intel()  { return CC_DB::t('ptp_cc_call_intel'); }
    public static function table_voicemails()  { return CC_DB::t('ptp_cc_voicemails'); }
    public static function table_op_webhooks() { return CC_DB::t('ptp_cc_op_webhooks'); }

    public static function create_tables() {
        global $wpdb;
        $c = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Call Intelligence — transcripts, summaries, recordings per call
        dbDelta("CREATE TABLE IF NOT EXISTS " . self::table_call_intel() . " (
            id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            call_id varchar(100) NOT NULL,
            phone varchar(20),
            direction enum('incoming','outgoing') DEFAULT 'incoming',
            duration int DEFAULT 0,
            status varchar(50) DEFAULT '',
            summary text DEFAULT NULL,
            transcript longtext DEFAULT NULL,
            recording_url text DEFAULT NULL,
            voicemail_url text DEFAULT NULL,
            voicemail_transcript text DEFAULT NULL,
            app_id bigint(20) UNSIGNED DEFAULT NULL,
            parent_id bigint(20) UNSIGNED DEFAULT NULL,
            tags text DEFAULT NULL,
            call_at datetime DEFAULT NULL,
            fetched_at datetime DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY call_id (call_id),
            KEY phone (phone),
            KEY app_id (app_id),
            KEY call_at (call_at)
        ) $c;");

        // Voicemail Queue — separate high-priority queue
        dbDelta("CREATE TABLE IF NOT EXISTS " . self::table_voicemails() . " (
            id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            call_id varchar(100) NOT NULL,
            phone varchar(20),
            caller_name varchar(200) DEFAULT '',
            duration int DEFAULT 0,
            transcript text DEFAULT NULL,
            audio_url text DEFAULT NULL,
            status enum('new','listened','actioned','dismissed') DEFAULT 'new',
            app_id bigint(20) UNSIGNED DEFAULT NULL,
            parent_id bigint(20) UNSIGNED DEFAULT NULL,
            notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            actioned_at datetime DEFAULT NULL,
            UNIQUE KEY call_id (call_id),
            KEY status (status),
            KEY phone (phone)
        ) $c;");

        // Registered webhooks tracking
        dbDelta("CREATE TABLE IF NOT EXISTS " . self::table_op_webhooks() . " (
            id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            webhook_id varchar(100) NOT NULL,
            resource_type varchar(50) NOT NULL,
            url text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY webhook_id (webhook_id)
        ) $c;");
    }

    // ═══════════════════════════════════════
    // 1. CONVERSATIONS
    // ═══════════════════════════════════════

    /**
     * List conversations from OpenPhone API
     */
    public static function list_conversations($phone_number_id = null, $limit = 50) {
        $pn_id = $phone_number_id ?: self::get_phone_number_id();
        if (is_wp_error($pn_id)) return $pn_id;

        $endpoint = 'conversations?phoneNumberIds[]=' . urlencode($pn_id) . '&maxResults=' . $limit;
        return self::api('GET', $endpoint);
    }

    /**
     * List messages for a conversation between our number and a participant
     */
    public static function list_messages($participant_phone, $limit = 50, $created_after = null) {
        $pn_id = self::get_phone_number_id();
        if (is_wp_error($pn_id)) return $pn_id;

        $clean = preg_replace('/\D/', '', $participant_phone);
        if (strlen($clean) === 10) $clean = '1' . $clean;
        $e164 = '+' . $clean;

        $params = http_build_query([
            'phoneNumberId'  => $pn_id,
            'participants[]' => $e164,
            'maxResults'     => $limit,
        ]);
        if ($created_after) $params .= '&createdAfter=' . urlencode($created_after);

        return self::api('GET', 'messages?' . $params);
    }

    /**
     * Get a single message by ID
     */
    public static function get_message($message_id) {
        return self::api('GET', 'messages/' . $message_id);
    }

    // ═══════════════════════════════════════
    // 2. CALL INTELLIGENCE
    // ═══════════════════════════════════════

    /**
     * List calls with optional filters
     */
    public static function list_calls($participant_phone = null, $limit = 50, $created_after = null) {
        $pn_id = self::get_phone_number_id();
        if (is_wp_error($pn_id)) return $pn_id;

        $params = ['phoneNumberId' => $pn_id, 'maxResults' => $limit];

        if ($participant_phone) {
            $clean = preg_replace('/\D/', '', $participant_phone);
            if (strlen($clean) === 10) $clean = '1' . $clean;
            $params['participants[]'] = '+' . $clean;
        }
        if ($created_after) $params['createdAfter'] = $created_after;

        return self::api('GET', 'calls?' . http_build_query($params));
    }

    /**
     * Get call by ID
     */
    public static function get_call($call_id) {
        return self::api('GET', 'calls/' . $call_id);
    }

    /**
     * Get call summary (AI-generated)
     */
    public static function get_call_summary($call_id) {
        return self::api('GET', 'call-summaries/' . $call_id);
    }

    /**
     * Get call transcript
     */
    public static function get_call_transcript($call_id) {
        return self::api('GET', 'call-transcripts/' . $call_id);
    }

    /**
     * Get call recordings
     */
    public static function get_call_recordings($call_id) {
        return self::api('GET', 'call-recordings/' . $call_id);
    }

    /**
     * Get voicemail for a call
     */
    public static function get_call_voicemail($call_id) {
        return self::api('GET', 'call-voicemails/' . $call_id);
    }

    /**
     * Fetch and store full call intelligence for a call
     */
    public static function capture_call_intel($call_id, $call_data = []) {
        global $wpdb;
        $table = self::table_call_intel();

        // Skip if already captured
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE call_id=%s", $call_id));
        if ($exists) return ['action' => 'exists', 'id' => $exists];

        // Get call details if not provided
        if (empty($call_data)) {
            $result = self::get_call($call_id);
            if (is_wp_error($result)) return $result;
            $call_data = $result['data'] ?? $result;
        }

        $direction = ($call_data['direction'] ?? '') === 'incoming' ? 'incoming' : 'outgoing';
        $phone_raw = $direction === 'incoming'
            ? ($call_data['from'] ?? '')
            : ($call_data['to'][0] ?? '');
        $phone = CC_DB::normalize_phone($phone_raw);
        $duration = $call_data['duration'] ?? 0;

        // Fetch summary
        $summary_text = null;
        $summary_result = self::get_call_summary($call_id);
        if (!is_wp_error($summary_result)) {
            $summary_text = $summary_result['data']['content'] ?? $summary_result['data']['summary'] ?? null;
        }

        // Fetch transcript
        $transcript_text = null;
        $transcript_result = self::get_call_transcript($call_id);
        if (!is_wp_error($transcript_result)) {
            $transcript_data = $transcript_result['data'] ?? [];
            if (is_array($transcript_data) && isset($transcript_data['dialogue'])) {
                // Format dialogue as readable transcript
                $lines = [];
                foreach ($transcript_data['dialogue'] as $d) {
                    $speaker = $d['speaker'] ?? 'Unknown';
                    $text = $d['content'] ?? $d['text'] ?? '';
                    $lines[] = "$speaker: $text";
                }
                $transcript_text = implode("\n", $lines);
            } elseif (is_string($transcript_data)) {
                $transcript_text = $transcript_data;
            } elseif (isset($transcript_data['content'])) {
                $transcript_text = $transcript_data['content'];
            }
        }

        // Fetch recording
        $recording_url = null;
        $rec_result = self::get_call_recordings($call_id);
        if (!is_wp_error($rec_result)) {
            $recordings = $rec_result['data'] ?? [];
            if (!empty($recordings)) {
                $recording_url = $recordings[0]['url'] ?? $recordings[0]['recordingUrl'] ?? null;
            }
        }

        // Fetch voicemail
        $vm_url = null;
        $vm_transcript = null;
        $vm_result = self::get_call_voicemail($call_id);
        if (!is_wp_error($vm_result) && !empty($vm_result['data'])) {
            $vm = $vm_result['data'];
            $vm_url = $vm['url'] ?? $vm['audioUrl'] ?? null;
            $vm_transcript = $vm['transcription'] ?? $vm['transcript'] ?? null;
        }

        // Match to pipeline app
        $phone_suffix = substr(preg_replace('/\D/', '', $phone), -10);
        $app = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM " . CC_DB::apps() . " WHERE phone LIKE %s ORDER BY created_at DESC LIMIT 1",
            '%' . $phone_suffix
        ));
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM " . CC_DB::parents() . " WHERE phone LIKE %s LIMIT 1",
            '%' . $phone_suffix
        ));

        // AI tags from summary
        $tags = null;
        if ($summary_text && class_exists('CC_AI_Engine') && CC_AI_Engine::is_active()) {
            $tag_prompt = "Extract 2-5 short tags from this call summary for a youth soccer camp CRM. Return comma-separated tags only. Summary: $summary_text";
            $tags = CC_AI_Engine::quick_complete($tag_prompt);
        }

        $wpdb->insert($table, [
            'call_id'              => $call_id,
            'phone'                => $phone,
            'direction'            => $direction,
            'duration'             => $duration,
            'status'               => $call_data['status'] ?? '',
            'summary'              => $summary_text,
            'transcript'           => $transcript_text,
            'recording_url'        => $recording_url,
            'voicemail_url'        => $vm_url,
            'voicemail_transcript' => $vm_transcript,
            'app_id'               => $app ? $app->id : null,
            'parent_id'            => $parent ? $parent->id : null,
            'tags'                 => $tags,
            'call_at'              => $call_data['createdAt'] ?? current_time('mysql'),
        ]);

        $intel_id = $wpdb->insert_id;

        // If voicemail exists, add to voicemail queue
        if ($vm_transcript || $vm_url) {
            self::queue_voicemail($call_id, $phone, $call_data, $vm_transcript, $vm_url, $app, $parent);
        }

        // Log activity
        CC_DB::log('call_intel_captured', 'call', $intel_id,
            "Call intel captured: {$duration}s {$direction}" . ($summary_text ? ' — ' . substr($summary_text, 0, 80) : ''),
            'openphone_platform'
        );

        return [
            'action'      => 'captured',
            'id'          => $intel_id,
            'has_summary' => !empty($summary_text),
            'has_transcript' => !empty($transcript_text),
            'has_recording'  => !empty($recording_url),
            'has_voicemail'  => !empty($vm_transcript) || !empty($vm_url),
        ];
    }

    /**
     * Add voicemail to priority queue
     */
    private static function queue_voicemail($call_id, $phone, $call_data, $transcript, $audio_url, $app, $parent) {
        global $wpdb;
        $table = self::table_voicemails();

        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE call_id=%s", $call_id));
        if ($exists) return;

        // Try to get caller name
        $caller_name = '';
        if ($app) {
            $caller_name = $wpdb->get_var($wpdb->prepare(
                "SELECT parent_name FROM " . CC_DB::apps() . " WHERE id=%d", $app->id
            )) ?: '';
        }
        if (!$caller_name && $parent) {
            $caller_name = $wpdb->get_var($wpdb->prepare(
                "SELECT display_name FROM " . CC_DB::parents() . " WHERE id=%d", $parent->id
            )) ?: '';
        }
        // Try OpenPhone contact
        if (!$caller_name) {
            $contact = CC_OpenPhone_Sync::find_contact_by_phone($phone);
            if ($contact) {
                $caller_name = trim(($contact['defaultFields']['firstName'] ?? '') . ' ' . ($contact['defaultFields']['lastName'] ?? ''));
            }
        }

        $wpdb->insert($table, [
            'call_id'    => $call_id,
            'phone'      => $phone,
            'caller_name' => $caller_name,
            'duration'   => $call_data['voicemailDuration'] ?? $call_data['duration'] ?? 0,
            'transcript' => $transcript,
            'audio_url'  => $audio_url,
            'status'     => 'new',
            'app_id'     => $app ? $app->id : null,
            'parent_id'  => $parent ? $parent->id : null,
        ]);
    }

    // ═══════════════════════════════════════
    // 3. CONTACT MANAGEMENT WITH EXTERNALID
    // ═══════════════════════════════════════

    /**
     * Create contact with externalId linking back to CC
     */
    public static function create_contact_linked($data) {
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
        if (!empty($data['last_name']))  $body['defaultFields']['lastName'] = $data['last_name'];
        if (!empty($data['email']))      $body['defaultFields']['emails'] = [['value' => $data['email'], 'name' => 'Primary']];
        if (!empty($data['company']))    $body['defaultFields']['company'] = $data['company'];

        // externalId for instant lookup — use app_id or parent_id
        if (!empty($data['app_id'])) {
            $body['externalId'] = 'ptp_app_' . $data['app_id'];
            $body['source'] = 'ptp-command-center';
        } elseif (!empty($data['parent_id'])) {
            $body['externalId'] = 'ptp_parent_' . $data['parent_id'];
            $body['source'] = 'ptp-command-center';
        }

        // Custom fields
        $custom = CC_OpenPhone_Sync::build_custom_fields_public($data);
        if ($custom) $body['customFields'] = $custom;

        return self::api('POST', 'contacts', $body);
    }

    /**
     * Look up contact by externalId (instant, no phone search needed)
     */
    public static function find_by_external_id($external_id) {
        $result = self::api('GET', 'contacts?externalIds[]=' . urlencode($external_id) . '&source=ptp-command-center');
        if (is_wp_error($result)) return null;

        $contacts = $result['data'] ?? [];
        return !empty($contacts) ? $contacts[0] : null;
    }

    /**
     * List all PTP-linked contacts
     */
    public static function list_ptp_contacts($page_token = null) {
        $endpoint = 'contacts?source=ptp-command-center&maxResults=50';
        if ($page_token) $endpoint .= '&pageToken=' . urlencode($page_token);
        return self::api('GET', $endpoint);
    }

    // ═══════════════════════════════════════
    // 4. WEBHOOK MANAGEMENT
    // ═══════════════════════════════════════

    /**
     * Register all PTP webhooks programmatically
     */
    public static function register_all_webhooks() {
        $site_url = home_url('/wp-json/ptp-cc/v1/webhooks/openphone');
        $secret = get_option('ptp_cc_openphone_webhook_secret', '');

        $webhook_types = [
            'calls'            => 'webhooks/calls',
            'messages'         => 'webhooks/messages',
            'call-summaries'   => 'webhooks/call-summaries',
            'call-transcripts' => 'webhooks/call-transcripts',
        ];

        $results = [];
        foreach ($webhook_types as $type => $endpoint) {
            $body = ['url' => $site_url];
            if ($secret) $body['webhookSecret'] = $secret;

            $result = self::api('POST', $endpoint, $body);
            if (!is_wp_error($result)) {
                $wh_id = $result['data']['id'] ?? $result['id'] ?? '';
                if ($wh_id) {
                    global $wpdb;
                    $wpdb->replace(self::table_op_webhooks(), [
                        'webhook_id'    => $wh_id,
                        'resource_type' => $type,
                        'url'           => $site_url,
                    ]);
                }
                $results[$type] = ['success' => true, 'id' => $wh_id];
            } else {
                $results[$type] = ['success' => false, 'error' => $result->get_error_message()];
            }
        }

        return $results;
    }

    /**
     * List registered webhooks
     */
    public static function list_webhooks() {
        return self::api('GET', 'webhooks');
    }

    /**
     * Delete a webhook
     */
    public static function delete_webhook($webhook_id) {
        global $wpdb;
        $result = self::api('DELETE', 'webhooks/' . $webhook_id);
        if (!is_wp_error($result)) {
            $wpdb->delete(self::table_op_webhooks(), ['webhook_id' => $webhook_id]);
        }
        return $result;
    }

    // ═══════════════════════════════════════
    // 5. MESSAGE BACKFILL SYNC
    // ═══════════════════════════════════════

    /**
     * Sync messages from OpenPhone API to local DB
     * Catches messages sent directly from the OpenPhone app
     */
    public static function backfill_messages($hours_back = 24) {
        global $wpdb;
        $table = CC_DB::op_msgs();

        $created_after = gmdate('Y-m-d\TH:i:s\Z', time() - ($hours_back * 3600));

        $pn_id = self::get_phone_number_id();
        if (is_wp_error($pn_id)) return $pn_id;

        // Get conversations to find active threads
        $convos = self::list_conversations($pn_id, 100);
        if (is_wp_error($convos)) return $convos;

        $synced = 0;
        $skipped = 0;

        foreach (($convos['data'] ?? []) as $convo) {
            $participants = $convo['participants'] ?? [];
            if (empty($participants)) continue;

            // Get external participant (not our number)
            $ext_phone = null;
            foreach ($participants as $p) {
                $pval = is_string($p) ? $p : ($p['phoneNumber'] ?? $p['value'] ?? '');
                if ($pval && substr(preg_replace('/\D/', '', $pval), -10) !== substr(preg_replace('/\D/', '', get_option('ptp_openphone_from', '')), -10)) {
                    $ext_phone = $pval;
                    break;
                }
            }
            if (!$ext_phone) continue;

            // Fetch messages for this conversation
            $msgs = self::list_messages($ext_phone, 50, $created_after);
            if (is_wp_error($msgs)) continue;

            foreach (($msgs['data'] ?? []) as $msg) {
                $op_id = $msg['id'] ?? '';
                if (!$op_id) continue;

                // Skip if already in DB
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table WHERE openphone_msg_id=%s", $op_id
                ));
                if ($exists) { $skipped++; continue; }

                $direction = ($msg['direction'] ?? '') === 'incoming' ? 'incoming' : 'outgoing';
                $phone = CC_DB::normalize_phone($ext_phone);
                $body = $msg['text'] ?? $msg['content'] ?? $msg['body'] ?? '';

                // Match to app/parent
                $phone_suffix = substr(preg_replace('/\D/', '', $phone), -10);
                $app = $wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM " . CC_DB::apps() . " WHERE phone LIKE %s ORDER BY created_at DESC LIMIT 1",
                    '%' . $phone_suffix
                ));
                $parent = $wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM " . CC_DB::parents() . " WHERE phone LIKE %s LIMIT 1",
                    '%' . $phone_suffix
                ));

                $wpdb->insert($table, [
                    'app_id'           => $app ? $app->id : null,
                    'parent_id'        => $parent ? $parent->id : null,
                    'phone'            => $phone,
                    'direction'        => $direction,
                    'body'             => $body,
                    'openphone_msg_id' => $op_id,
                    'created_at'       => $msg['createdAt'] ?? current_time('mysql'),
                ]);
                $synced++;
            }
        }

        update_option('ptp_cc_op_last_backfill', current_time('mysql'));

        return [
            'synced'  => $synced,
            'skipped' => $skipped,
            'from'    => $created_after,
        ];
    }

    /**
     * Backfill call intelligence for recent calls
     */
    public static function backfill_calls($hours_back = 48) {
        $created_after = gmdate('Y-m-d\TH:i:s\Z', time() - ($hours_back * 3600));
        $calls = self::list_calls(null, 100, $created_after);
        if (is_wp_error($calls)) return $calls;

        $results = ['captured' => 0, 'skipped' => 0, 'errors' => 0];
        foreach (($calls['data'] ?? []) as $call) {
            $cid = $call['id'] ?? '';
            if (!$cid) continue;

            $r = self::capture_call_intel($cid, $call);
            if (is_wp_error($r)) {
                $results['errors']++;
            } elseif (($r['action'] ?? '') === 'exists') {
                $results['skipped']++;
            } else {
                $results['captured']++;
            }
        }

        update_option('ptp_cc_op_last_call_backfill', current_time('mysql'));
        return $results;
    }

    // ═══════════════════════════════════════
    // 6. ANALYTICS
    // ═══════════════════════════════════════

    public static function get_stats() {
        global $wpdb;
        $msgs = CC_DB::op_msgs();
        $intel = self::table_call_intel();
        $vm = self::table_voicemails();

        $stats = [];

        // Messages
        $stats['total_messages'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM $msgs");
        $stats['incoming_messages'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM $msgs WHERE direction='incoming'");
        $stats['outgoing_messages'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM $msgs WHERE direction='outgoing'");
        $stats['messages_today'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM $msgs WHERE DATE(created_at) = CURDATE()");
        $stats['messages_7d'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM $msgs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");

        // Calls
        if ($wpdb->get_var("SHOW TABLES LIKE '$intel'") === $intel) {
            $stats['total_calls'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM $intel");
            $stats['calls_with_summary'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM $intel WHERE summary IS NOT NULL AND summary != ''");
            $stats['calls_with_transcript'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM $intel WHERE transcript IS NOT NULL AND transcript != ''");
            $stats['calls_with_recording'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM $intel WHERE recording_url IS NOT NULL");
            $stats['avg_call_duration'] = (int)$wpdb->get_var("SELECT AVG(duration) FROM $intel WHERE duration > 0");
            $stats['calls_7d'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM $intel WHERE call_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        }

        // Voicemails
        if ($wpdb->get_var("SHOW TABLES LIKE '$vm'") === $vm) {
            $stats['total_voicemails'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM $vm");
            $stats['new_voicemails'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM $vm WHERE status='new'");
        }

        // Unique contacts communicated with
        $stats['unique_contacts'] = (int)$wpdb->get_var("SELECT COUNT(DISTINCT phone) FROM $msgs WHERE phone IS NOT NULL AND phone != ''");

        // Response rate (incoming that got a reply within 24h)
        $stats['response_rate'] = null;
        $incoming_with_reply = (int)$wpdb->get_var(
            "SELECT COUNT(DISTINCT m1.phone) FROM $msgs m1
             WHERE m1.direction='incoming'
             AND m1.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             AND EXISTS (
                SELECT 1 FROM $msgs m2
                WHERE m2.phone = m1.phone AND m2.direction='outgoing'
                AND m2.created_at > m1.created_at
                AND m2.created_at <= DATE_ADD(m1.created_at, INTERVAL 24 HOUR)
             )"
        );
        $total_incoming_contacts = (int)$wpdb->get_var(
            "SELECT COUNT(DISTINCT phone) FROM $msgs WHERE direction='incoming' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        if ($total_incoming_contacts > 0) {
            $stats['response_rate'] = round(($incoming_with_reply / $total_incoming_contacts) * 100, 1);
        }

        // Config status
        $stats['api_configured'] = !empty(self::get_key());
        $stats['phone_number'] = get_option('ptp_openphone_from', '');
        $stats['last_backfill'] = get_option('ptp_cc_op_last_backfill', 'Never');
        $stats['last_call_backfill'] = get_option('ptp_cc_op_last_call_backfill', 'Never');
        $stats['webhook_secret_set'] = !empty(get_option('ptp_cc_openphone_webhook_secret', ''));

        return $stats;
    }

    /**
     * Communication activity by day (for charts)
     */
    public static function get_activity_chart($days = 30) {
        global $wpdb;
        $msgs = CC_DB::op_msgs();
        $intel = self::table_call_intel();

        $chart = [];

        // Messages per day
        $msg_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) as day, direction, COUNT(*) as cnt
             FROM $msgs WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY DATE(created_at), direction ORDER BY day ASC",
            $days
        ));

        foreach ($msg_rows ?: [] as $r) {
            if (!isset($chart[$r->day])) $chart[$r->day] = ['day' => $r->day, 'sms_in' => 0, 'sms_out' => 0, 'calls' => 0];
            if ($r->direction === 'incoming') $chart[$r->day]['sms_in'] = (int)$r->cnt;
            else $chart[$r->day]['sms_out'] = (int)$r->cnt;
        }

        // Calls per day
        if ($wpdb->get_var("SHOW TABLES LIKE '$intel'") === $intel) {
            $call_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT DATE(call_at) as day, COUNT(*) as cnt
                 FROM $intel WHERE call_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                 GROUP BY DATE(call_at) ORDER BY day ASC",
                $days
            ));
            foreach ($call_rows ?: [] as $r) {
                if (!isset($chart[$r->day])) $chart[$r->day] = ['day' => $r->day, 'sms_in' => 0, 'sms_out' => 0, 'calls' => 0];
                $chart[$r->day]['calls'] = (int)$r->cnt;
            }
        }

        ksort($chart);
        return array_values($chart);
    }

    // ═══════════════════════════════════════
    // REST API ROUTES
    // ═══════════════════════════════════════

    public static function register_routes($ns = 'ptp-cc/v1') {
        $perm = function () { return current_user_can('manage_options'); };

        // Dashboard stats
        register_rest_route($ns, '/op-platform/stats', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_stats'], 'permission_callback' => $perm,
        ]);

        // Activity chart
        register_rest_route($ns, '/op-platform/activity', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_activity'], 'permission_callback' => $perm,
        ]);

        // Conversations
        register_rest_route($ns, '/op-platform/conversations', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_conversations'], 'permission_callback' => $perm,
        ]);

        // Messages for a phone
        register_rest_route($ns, '/op-platform/messages/(?P<phone>[^/]+)', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_messages'], 'permission_callback' => $perm,
        ]);

        // Call intelligence list
        register_rest_route($ns, '/op-platform/calls', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_calls'], 'permission_callback' => $perm,
        ]);

        // Single call intel
        register_rest_route($ns, '/op-platform/calls/(?P<id>\d+)', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_call_detail'], 'permission_callback' => $perm,
        ]);

        // Capture call intel on-demand
        register_rest_route($ns, '/op-platform/calls/capture/(?P<call_id>[^/]+)', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'api_capture_call'], 'permission_callback' => $perm,
        ]);

        // Voicemail queue
        register_rest_route($ns, '/op-platform/voicemails', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_voicemails'], 'permission_callback' => $perm,
        ]);

        // Update voicemail status
        register_rest_route($ns, '/op-platform/voicemails/(?P<id>\d+)', [
            'methods' => 'PATCH', 'callback' => [__CLASS__, 'api_update_voicemail'], 'permission_callback' => $perm,
        ]);

        // Contacts (PTP-linked)
        register_rest_route($ns, '/op-platform/contacts', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_contacts'], 'permission_callback' => $perm,
        ]);

        // Webhooks management
        register_rest_route($ns, '/op-platform/webhooks', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_list_webhooks'], 'permission_callback' => $perm,
        ]);
        register_rest_route($ns, '/op-platform/webhooks/register', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'api_register_webhooks'], 'permission_callback' => $perm,
        ]);
        register_rest_route($ns, '/op-platform/webhooks/(?P<id>[^/]+)', [
            'methods' => 'DELETE', 'callback' => [__CLASS__, 'api_delete_webhook'], 'permission_callback' => $perm,
        ]);

        // Backfill
        register_rest_route($ns, '/op-platform/backfill/messages', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'api_backfill_messages'], 'permission_callback' => $perm,
        ]);
        register_rest_route($ns, '/op-platform/backfill/calls', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'api_backfill_calls'], 'permission_callback' => $perm,
        ]);

        // Export contacts CSV
        register_rest_route($ns, '/op-platform/export/contacts', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_export_contacts'], 'permission_callback' => $perm,
        ]);

        // Phone numbers (workspace)
        register_rest_route($ns, '/op-platform/phone-numbers', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_phone_numbers'], 'permission_callback' => $perm,
        ]);

        // Settings save
        register_rest_route($ns, '/op-platform/settings', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'api_save_settings'], 'permission_callback' => $perm,
        ]);
        register_rest_route($ns, '/op-platform/settings', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_get_settings'], 'permission_callback' => $perm,
        ]);
    }

    // ── API Handlers ──

    public static function api_stats() {
        return self::get_stats();
    }

    public static function api_activity($req) {
        $days = min(90, max(7, (int)($req->get_param('days') ?: 30)));
        return ['activity' => self::get_activity_chart($days)];
    }

    public static function api_conversations() {
        $result = self::list_conversations();
        if (is_wp_error($result)) return $result;

        // Enrich with local data
        global $wpdb;
        $conversations = [];
        foreach (($result['data'] ?? []) as $c) {
            $participants = $c['participants'] ?? [];
            $ext_phone = '';
            foreach ($participants as $p) {
                $pval = is_string($p) ? $p : ($p['phoneNumber'] ?? '');
                if ($pval) { $ext_phone = $pval; break; }
            }
            if (!$ext_phone) continue;

            $phone_suffix = substr(preg_replace('/\D/', '', $ext_phone), -10);

            // Match local
            $app = $wpdb->get_row($wpdb->prepare(
                "SELECT id, parent_name, child_name, status, lead_temperature FROM " . CC_DB::apps() .
                " WHERE phone LIKE %s ORDER BY created_at DESC LIMIT 1",
                '%' . $phone_suffix
            ));

            $conversations[] = [
                'id'            => $c['id'] ?? '',
                'phone'         => $ext_phone,
                'last_message'  => $c['lastMessage'] ?? null,
                'updated_at'    => $c['updatedAt'] ?? '',
                'app_id'        => $app ? $app->id : null,
                'name'          => $app ? $app->parent_name : '',
                'child'         => $app ? $app->child_name : '',
                'status'        => $app ? $app->status : '',
                'temperature'   => $app ? $app->lead_temperature : '',
            ];
        }

        return ['conversations' => $conversations];
    }

    public static function api_messages($req) {
        $phone = urldecode($req->get_param('phone'));
        $result = self::list_messages($phone, 100);
        if (is_wp_error($result)) return $result;
        return ['messages' => $result['data'] ?? []];
    }

    public static function api_calls($req) {
        global $wpdb;
        $table = self::table_call_intel();
        $phone = sanitize_text_field($req->get_param('phone') ?: '');
        $page = max(1, (int)($req->get_param('page') ?: 1));
        $per_page = 25;
        $offset = ($page - 1) * $per_page;

        $where = '1=1';
        $params = [];
        if ($phone) {
            $where .= " AND phone LIKE %s";
            $params[] = '%' . substr(preg_replace('/\D/', '', $phone), -10);
        }

        $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE $where", ...$params));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE $where ORDER BY call_at DESC LIMIT %d OFFSET %d",
            ...array_merge($params, [$per_page, $offset])
        ));

        return ['calls' => $rows, 'total' => $total, 'page' => $page, 'pages' => ceil($total / $per_page)];
    }

    public static function api_call_detail($req) {
        global $wpdb;
        $id = (int)$req->get_param('id');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table_call_intel() . " WHERE id=%d", $id));
        if (!$row) return new WP_Error('not_found', 'Call not found', ['status' => 404]);
        return ['call' => $row];
    }

    public static function api_capture_call($req) {
        $call_id = sanitize_text_field($req->get_param('call_id'));
        return self::capture_call_intel($call_id);
    }

    public static function api_voicemails($req) {
        global $wpdb;
        $table = self::table_voicemails();
        $status = sanitize_text_field($req->get_param('status') ?: '');

        $where = '1=1';
        $params = [];
        if ($status) {
            $where .= " AND status=%s";
            $params[] = $status;
        }

        $sql = "SELECT * FROM $table WHERE $where ORDER BY FIELD(status,'new','listened','actioned','dismissed'), created_at DESC LIMIT 100";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params)) : $wpdb->get_results($sql);

        return ['voicemails' => $rows ?: []];
    }

    public static function api_update_voicemail($req) {
        global $wpdb;
        $id = (int)$req->get_param('id');
        $b = $req->get_json_params();

        $update = [];
        if (isset($b['status'])) $update['status'] = sanitize_text_field($b['status']);
        if (isset($b['notes']))  $update['notes'] = sanitize_textarea_field($b['notes']);
        if (!empty($b['status']) && in_array($b['status'], ['actioned', 'dismissed'])) {
            $update['actioned_at'] = current_time('mysql');
        }

        if (!$update) return new WP_Error('nothing', 'No fields to update');

        $wpdb->update(self::table_voicemails(), $update, ['id' => $id]);
        return ['updated' => true];
    }

    public static function api_contacts($req) {
        $page_token = $req->get_param('page_token');
        $result = self::list_ptp_contacts($page_token);
        if (is_wp_error($result)) return $result;
        return ['contacts' => $result['data'] ?? [], 'next_page' => $result['nextPageToken'] ?? null];
    }

    public static function api_list_webhooks() {
        $remote = self::list_webhooks();
        global $wpdb;
        $local = $wpdb->get_results("SELECT * FROM " . self::table_op_webhooks() . " ORDER BY created_at DESC");
        return [
            'remote' => is_wp_error($remote) ? [] : ($remote['data'] ?? []),
            'local'  => $local ?: [],
        ];
    }

    public static function api_register_webhooks() {
        return ['results' => self::register_all_webhooks()];
    }

    public static function api_delete_webhook($req) {
        $id = sanitize_text_field($req->get_param('id'));
        $result = self::delete_webhook($id);
        return is_wp_error($result) ? $result : ['deleted' => true];
    }

    public static function api_backfill_messages($req) {
        $hours = min(168, max(1, (int)($req->get_param('hours') ?: 24)));
        return self::backfill_messages($hours);
    }

    public static function api_backfill_calls($req) {
        $hours = min(168, max(1, (int)($req->get_param('hours') ?: 48)));
        return self::backfill_calls($hours);
    }

    public static function api_phone_numbers() {
        $result = self::api('GET', 'phone-numbers');
        if (is_wp_error($result)) return $result;

        $numbers = [];
        foreach (($result['data'] ?? []) as $pn) {
            $numbers[] = [
                'id'          => $pn['id'] ?? '',
                'number'      => $pn['phoneNumber'] ?? '',
                'name'        => $pn['name'] ?? '',
                'type'        => $pn['type'] ?? '',
                'users'       => $pn['users'] ?? [],
                'restrictions' => $pn['restrictions'] ?? null,
            ];
        }
        return ['phone_numbers' => $numbers];
    }

    public static function api_save_settings($req) {
        $b = $req->get_json_params();

        if (isset($b['api_key'])) update_option('ptp_openphone_api_key', sanitize_text_field($b['api_key']));
        if (isset($b['phone_from'])) update_option('ptp_openphone_from', sanitize_text_field($b['phone_from']));
        if (isset($b['webhook_secret'])) update_option('ptp_cc_openphone_webhook_secret', sanitize_text_field($b['webhook_secret']));
        if (isset($b['auto_sync'])) update_option('ptp_cc_openphone_auto_sync', $b['auto_sync'] ? 'yes' : 'no');
        if (isset($b['auto_backfill'])) update_option('ptp_cc_op_auto_backfill', $b['auto_backfill'] ? 'yes' : 'no');
        if (isset($b['auto_call_intel'])) update_option('ptp_cc_op_auto_call_intel', $b['auto_call_intel'] ? 'yes' : 'no');

        // Clear cached phoneNumberId on phone change
        if (isset($b['phone_from'])) delete_transient('ptp_cc_op_phone_number_id');

        return ['saved' => true];
    }

    public static function api_get_settings() {
        return [
            'api_key'         => !empty(self::get_key()) ? '••••••••' . substr(self::get_key(), -4) : '',
            'api_key_set'     => !empty(self::get_key()),
            'phone_from'      => get_option('ptp_openphone_from', ''),
            'webhook_secret'  => !empty(get_option('ptp_cc_openphone_webhook_secret', '')) ? '••••set••••' : '',
            'webhook_secret_set' => !empty(get_option('ptp_cc_openphone_webhook_secret', '')),
            'auto_sync'       => get_option('ptp_cc_openphone_auto_sync', 'yes') === 'yes',
            'auto_backfill'   => get_option('ptp_cc_op_auto_backfill', 'no') === 'yes',
            'auto_call_intel' => get_option('ptp_cc_op_auto_call_intel', 'yes') === 'yes',
            'webhook_url'     => home_url('/wp-json/ptp-cc/v1/webhooks/openphone'),
            'field_map'       => get_option('ptp_cc_openphone_field_map', []),
        ];
    }

    // ═══════════════════════════════════════
    // EXPORT
    // ═══════════════════════════════════════

    /**
     * Export contacts as CSV.
     * Filters: ?status=confirmed, ?temp=hot, ?source=pipeline, ?camp=radnor,
     *          ?has_training=1, ?has_calls=1, ?search=keyword
     * Returns JSON with csv_data (base64) + filename + count.
     */
    public static function api_export_contacts($req) {
        global $wpdb;

        $at = CC_DB::apps();
        $pt = CC_DB::parents();
        $mt = CC_DB::op_msgs();
        $ci = self::table_call_intel();

        // ── 1. Pull all contacts from pipeline + parents ──

        $contacts = [];

        $apps = $wpdb->get_results(
            "SELECT a.id as app_id, a.parent_name as name, a.phone, a.email,
                    a.child_name, a.child_age, a.status, a.trainer_name as trainer,
                    a.lead_temperature as temp, a.camp_location, a.camp_week,
                    a.created_at, a.source as app_source
             FROM $at a WHERE a.phone IS NOT NULL AND a.phone != ''
             ORDER BY a.created_at DESC"
        );

        foreach ($apps as $a) {
            $key = CC_Inbox::phone_key($a->phone);
            if (!$key || isset($contacts[$key])) continue;
            $contacts[$key] = [
                'name'          => $a->name ?: '',
                'phone'         => CC_DB::normalize_phone($a->phone),
                'email'         => $a->email ?: '',
                'child_name'    => $a->child_name ?: '',
                'child_age'     => $a->child_age ?: '',
                'status'        => $a->status ?: '',
                'temp'          => $a->temp ?: '',
                'trainer'       => $a->trainer ?: '',
                'camp_location' => $a->camp_location ?: '',
                'camp_week'     => $a->camp_week ?: '',
                'source'        => $a->app_source ?: 'pipeline',
                'created_at'    => $a->created_at ?: '',
                'app_id'        => (int) $a->app_id,
            ];
        }

        // Merge parents not already in pipeline
        $parents = $wpdb->get_results(
            "SELECT p.display_name as name, p.phone, p.email FROM $pt p
             WHERE p.phone IS NOT NULL AND p.phone != ''"
        );
        foreach ($parents as $p) {
            $key = CC_Inbox::phone_key($p->phone);
            if (!$key) continue;
            if (!isset($contacts[$key])) {
                $contacts[$key] = [
                    'name' => $p->name ?: '', 'phone' => CC_DB::normalize_phone($p->phone),
                    'email' => $p->email ?: '', 'child_name' => '', 'child_age' => '',
                    'status' => '', 'temp' => '', 'trainer' => '',
                    'camp_location' => '', 'camp_week' => '', 'source' => 'training',
                    'created_at' => '', 'app_id' => null,
                ];
            } else {
                if (empty($contacts[$key]['email']) && $p->email) {
                    $contacts[$key]['email'] = $p->email;
                }
            }
        }

        // ── 2. Attach message stats (bulk) ──

        $msg_stats = $wpdb->get_results(
            "SELECT phone, COUNT(*) as msg_count,
                    SUM(CASE WHEN direction='incoming' THEN 1 ELSE 0 END) as incoming,
                    SUM(CASE WHEN direction='outgoing' THEN 1 ELSE 0 END) as outgoing,
                    MAX(created_at) as last_msg_at,
                    MAX(CASE WHEN direction='incoming' THEN created_at END) as last_incoming,
                    MAX(CASE WHEN direction='outgoing' THEN created_at END) as last_outgoing
             FROM $mt GROUP BY phone"
        );
        $sm = [];
        foreach ($msg_stats as $s) {
            $key = CC_Inbox::phone_key($s->phone);
            if ($key) $sm[$key] = $s;
        }

        // ── 3. Attach call stats (bulk) ──

        $call_stats = $wpdb->get_results(
            "SELECT phone, COUNT(*) as call_count,
                    SUM(duration) as total_duration,
                    MAX(call_at) as last_call_at,
                    SUM(CASE WHEN summary IS NOT NULL AND summary != '' THEN 1 ELSE 0 END) as calls_with_summary
             FROM $ci GROUP BY phone"
        );
        $cm = [];
        foreach ($call_stats as $cs) {
            $key = CC_Inbox::phone_key($cs->phone);
            if ($key) $cm[$key] = $cs;
        }

        // Merge stats into contacts
        foreach ($contacts as $key => &$c) {
            $s = $sm[$key] ?? null;
            $c['msg_count']     = $s ? (int) $s->msg_count : 0;
            $c['msg_incoming']  = $s ? (int) $s->incoming : 0;
            $c['msg_outgoing']  = $s ? (int) $s->outgoing : 0;
            $c['last_msg_at']   = $s ? $s->last_msg_at : '';
            $c['last_incoming'] = $s ? ($s->last_incoming ?: '') : '';
            $c['last_outgoing'] = $s ? ($s->last_outgoing ?: '') : '';

            $cs = $cm[$key] ?? null;
            $c['call_count']         = $cs ? (int) $cs->call_count : 0;
            $c['total_call_duration'] = $cs ? (int) $cs->total_duration : 0;
            $c['last_call_at']       = $cs ? ($cs->last_call_at ?: '') : '';
            $c['calls_with_summary'] = $cs ? (int) $cs->calls_with_summary : 0;
        }
        unset($c);

        // ── 4. Apply filters ──

        $f_status   = sanitize_text_field($req->get_param('status') ?: '');
        $f_temp     = sanitize_text_field($req->get_param('temp') ?: '');
        $f_source   = sanitize_text_field($req->get_param('source') ?: '');
        $f_camp     = sanitize_text_field($req->get_param('camp') ?: '');
        $f_trainer  = sanitize_text_field($req->get_param('trainer') ?: '');
        $f_training = $req->get_param('has_training');
        $f_calls    = $req->get_param('has_calls');
        $f_search   = sanitize_text_field($req->get_param('search') ?: '');

        $contacts = array_filter($contacts, function ($c) use ($f_status, $f_temp, $f_source, $f_camp, $f_trainer, $f_training, $f_calls, $f_search) {
            if ($f_status && strtolower($c['status']) !== strtolower($f_status)) return false;
            if ($f_temp && strtolower($c['temp']) !== strtolower($f_temp)) return false;
            if ($f_source && strtolower($c['source']) !== strtolower($f_source)) return false;
            if ($f_camp && stripos($c['camp_location'], $f_camp) === false) return false;
            if ($f_trainer && stripos($c['trainer'], $f_trainer) === false) return false;
            if ($f_training && $c['source'] !== 'training' && empty($c['trainer'])) return false;
            if ($f_calls && $c['call_count'] === 0) return false;
            if ($f_search) {
                $sl = strtolower($f_search);
                if (stripos($c['name'], $sl) === false && stripos($c['phone'], $sl) === false &&
                    stripos($c['child_name'], $sl) === false && stripos($c['email'], $sl) === false) {
                    return false;
                }
            }
            return true;
        });

        // ── 5. Build CSV ──

        $headers = [
            'Name', 'Phone', 'Email', 'Child Name', 'Child Age',
            'Status', 'Lead Temperature', 'Trainer',
            'Camp Location', 'Camp Week', 'Source', 'Signup Date',
            'Total Messages', 'Incoming', 'Outgoing',
            'Last Message', 'Last Incoming', 'Last Outgoing',
            'Total Calls', 'Call Duration (sec)', 'Calls with AI Summary',
            'Last Call',
        ];

        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, $headers);

        foreach ($contacts as $c) {
            fputcsv($csv, [
                $c['name'], $c['phone'], $c['email'], $c['child_name'], $c['child_age'],
                $c['status'], $c['temp'], $c['trainer'],
                $c['camp_location'], $c['camp_week'], $c['source'], $c['created_at'],
                $c['msg_count'], $c['msg_incoming'], $c['msg_outgoing'],
                $c['last_msg_at'], $c['last_incoming'], $c['last_outgoing'],
                $c['call_count'], $c['total_call_duration'], $c['calls_with_summary'],
                $c['last_call_at'],
            ]);
        }

        rewind($csv);
        $csv_data = stream_get_contents($csv);
        fclose($csv);

        $count = count($contacts);
        $date  = date('Y-m-d');
        $label = $f_status ?: ($f_camp ?: ($f_temp ?: 'all'));
        $filename = "ptp-contacts-{$label}-{$date}.csv";

        return [
            'csv_data'  => base64_encode($csv_data),
            'filename'  => $filename,
            'count'     => $count,
            'filters'   => array_filter([
                'status' => $f_status, 'temp' => $f_temp, 'source' => $f_source,
                'camp' => $f_camp, 'trainer' => $f_trainer, 'search' => $f_search,
            ]),
        ];
    }

    // ═══════════════════════════════════════
    // HOOKS & CRON
    // ═══════════════════════════════════════

    public static function register_hooks() {
        // Auto-capture call intel after webhook receives a completed call
        add_action('ptp_cc_capture_call_intel', [__CLASS__, 'async_capture_call_intel'], 10, 2);

        // Scheduled backfill
        add_action('ptp_cc_op_backfill', [__CLASS__, 'cron_backfill']);
    }

    /**
     * Async call intel capture (scheduled from webhook handler)
     */
    public static function async_capture_call_intel($call_id, $call_data = []) {
        if (get_option('ptp_cc_op_auto_call_intel', 'yes') !== 'yes') return;
        self::capture_call_intel($call_id, $call_data);
    }

    /**
     * Cron: periodic message + call backfill
     */
    public static function cron_backfill() {
        if (get_option('ptp_cc_op_auto_backfill', 'no') === 'yes') {
            self::backfill_messages(2); // Last 2 hours
        }
        // Always backfill calls (lighter weight)
        self::backfill_calls(4); // Last 4 hours
    }
}
