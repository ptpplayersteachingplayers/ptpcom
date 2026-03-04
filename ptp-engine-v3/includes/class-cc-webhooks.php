<?php
if (!defined('ABSPATH')) exit;

class CC_Webhooks {

    public function register_routes() {
        register_rest_route('ptp-cc/v1', '/webhooks/openphone', [
            'methods' => 'POST', 'callback' => [$this, 'handle_openphone'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_openphone($req) {
        // ── Verify webhook authenticity ──
        $secret = get_option('ptp_cc_openphone_webhook_secret', '');
        if ($secret) {
            $sig = $req->get_header('x-openphone-signature')
                ?: $req->get_header('openphone-signature')
                ?: ($_SERVER['HTTP_X_OPENPHONE_SIGNATURE'] ?? '');
            if (!$sig) {
                error_log('[PTP-CC] OpenPhone webhook rejected: missing signature header');
                return new WP_Error('no_signature', 'Missing signature', ['status' => 403]);
            }
            $body_raw = $req->get_body();
            $expected = hash_hmac('sha256', $body_raw, $secret);
            if (!hash_equals($expected, $sig)) {
                // Also try base64-encoded variant
                $expected_b64 = base64_encode(hash_hmac('sha256', $body_raw, $secret, true));
                if (!hash_equals($expected_b64, $sig)) {
                    error_log('[PTP-CC] OpenPhone webhook rejected: signature mismatch');
                    return new WP_Error('invalid_signature', 'Invalid signature', ['status' => 403]);
                }
            }
        } else {
            // Fallback: check for a shared token in query param
            $token = $req->get_param('token') ?: '';
            $expected_token = get_option('ptp_cc_openphone_webhook_token', '');
            if ($expected_token && $token !== $expected_token) {
                error_log('[PTP-CC] OpenPhone webhook rejected: invalid token');
                return new WP_Error('invalid_token', 'Invalid token', ['status' => 403]);
            }
            if (!$expected_token) {
                error_log('[PTP-CC] OpenPhone webhook REJECTED: no secret/token configured. Set ptp_cc_openphone_webhook_secret in wp_options.');
                return new WP_Error('no_secret', 'Webhook secret not configured. Set ptp_cc_openphone_webhook_secret in wp_options.', ['status' => 403]);
            }
        }

        global $wpdb;
        $body = $req->get_json_params();
        $type = $body['type'] ?? '';
        $data = $body['data'] ?? $body['object'] ?? [];

        error_log('[PTP-CC] OpenPhone webhook: ' . $type . ' | ' . wp_json_encode(array_keys($data)));

        // ── Messages ──
        if (in_array($type, ['message.received', 'message.created'])) {
            return $this->handle_message($data, $wpdb);
        }

        // ── Calls ──
        if (in_array($type, ['call.completed', 'call.ringing', 'call.recorded'])) {
            return $this->handle_call($data, $type, $wpdb);
        }

        // ── Call Summaries (new webhook type) ──
        if (in_array($type, ['call_summary.completed', 'callSummary.completed'])) {
            return $this->handle_call_summary($data, $wpdb);
        }

        // ── Call Transcripts (new webhook type) ──
        if (in_array($type, ['call_transcript.completed', 'callTranscript.completed'])) {
            return $this->handle_call_transcript($data, $wpdb);
        }

        // ── Contact events ──
        if (in_array($type, ['contact.created', 'contact.updated'])) {
            error_log('[PTP-CC] Contact event: ' . $type);
            return ['received' => true];
        }

        return ['received' => true, 'type' => $type, 'handled' => false];
    }

    private function handle_message($data, $wpdb) {
        $direction = ($data['direction'] ?? '') === 'incoming' ? 'incoming' : 'outgoing';
        $phone_raw = $direction === 'incoming'
            ? ($data['from'] ?? $data['participants'][0] ?? '')
            : ($data['to'][0] ?? $data['participants'][0] ?? '');
        $phone = CC_DB::normalize_phone($phone_raw);
        $msg_body = $data['content'] ?? $data['body'] ?? $data['text'] ?? '';
        $op_id = $data['id'] ?? '';

        // Dedupe
        if ($op_id) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM " . CC_DB::op_msgs() . " WHERE openphone_msg_id=%s", $op_id
            ));
            if ($exists) return ['received' => true, 'duplicate' => true];
        }

        // Match to application by phone (last 10 digits)
        $phone_suffix = substr(preg_replace('/\D/', '', $phone), -10);
        $app = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, parent_name, child_name, trainer_name FROM " . CC_DB::apps() . " WHERE phone LIKE %s ORDER BY created_at DESC LIMIT 1",
            '%' . $phone_suffix
        ));

        // Match to parent by phone
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM " . CC_DB::parents() . " WHERE phone LIKE %s LIMIT 1",
            '%' . $phone_suffix
        ));

        // Also try matching to camp orders by billing_phone
        $camp_parent_id = null;
        $co = CC_DB::camp_orders();
        if ($wpdb->get_var("SHOW TABLES LIKE '$co'") === $co) {
            $camp_order = $wpdb->get_row($wpdb->prepare(
                "SELECT id, billing_email FROM $co WHERE billing_phone LIKE %s LIMIT 1",
                '%' . $phone_suffix
            ));
            if ($camp_order) {
                // Try to cross-link to ptp_parents by email
                if (!$parent && $camp_order->billing_email) {
                    $parent = $wpdb->get_row($wpdb->prepare(
                        "SELECT id FROM " . CC_DB::parents() . " WHERE email=%s LIMIT 1",
                        $camp_order->billing_email
                    ));
                }
            }
        }

        $app_id = $app ? $app->id : null;
        $parent_id = $parent ? $parent->id : null;

        // Log message
        $wpdb->insert(CC_DB::op_msgs(), [
            'app_id'           => $app_id,
            'parent_id'        => $parent_id,
            'phone'            => $phone,
            'direction'        => $direction,
            'body'             => $msg_body,
            'openphone_msg_id' => $op_id,
        ]);

        $response = [
            'received'    => true,
            'matched_app' => $app_id,
            'matched_parent' => $parent_id,
        ];

        // Incoming message handling
        if ($direction === 'incoming') {
            // Mark response on active follow-up
            if ($app_id) {
                $last_fu = $wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM " . CC_DB::follow_ups() . " WHERE app_id=%d AND response_received=0 ORDER BY sent_at DESC LIMIT 1",
                    $app_id
                ));
                if ($last_fu) {
                    $wpdb->update(CC_DB::follow_ups(), [
                        'response_received' => 1,
                        'response_at'       => current_time('mysql'),
                    ], ['id' => $last_fu->id]);
                }
            }

            CC_DB::log('message_received', 'application', $app_id, substr($msg_body, 0, 100), 'webhook');

            // ── Run Rules Engine ──
            $rule_matched = false;
            if (class_exists('CC_Rules_Engine')) {
                $rule_result = CC_Rules_Engine::evaluate($phone, $msg_body, $app_id, $parent_id);
                $rule_matched = $rule_result['matched'];
                $response['rule_matched'] = $rule_result['matched'];
                if ($rule_result['matched']) {
                    $response['rule_name'] = $rule_result['rule']->name;
                    $response['action'] = $rule_result['action_taken'];
                }
            }

            // ── AI Auto-Draft (if no rule matched) ──
            if (class_exists('CC_AI_Engine')) {
                CC_AI_Engine::maybe_auto_draft($phone, $msg_body, $app_id, $parent_id, $rule_matched);
            }

            // ── Track campaign replies ──
            if (class_exists('CC_Campaigns')) {
                CC_Campaigns::track_reply($phone);
            }

            // ── Fan-out to Training Platform handlers ──
            // 1. OpenPhone webhook handler (logs to TP's SMS tables)
            if (class_exists('PTP_OpenPhone_Webhook')) {
                do_action('ptp_cc_incoming_sms', $phone, $msg_body, $app_id, $parent_id);
            }
            // 2. Chatbot API (conversational AI replies)
            do_action('ptp_openphone_incoming_for_chatbot', $phone, $msg_body, $data);
        }

        return $response;
    }

    private function handle_call($data, $type, $wpdb) {
        $direction = ($data['direction'] ?? '') === 'incoming' ? 'incoming' : 'outgoing';
        $phone_raw = $direction === 'incoming'
            ? ($data['from'] ?? '')
            : ($data['to'][0] ?? '');
        $phone = CC_DB::normalize_phone($phone_raw);
        $duration = $data['duration'] ?? $data['voicemailDuration'] ?? 0;
        $status = $data['status'] ?? $type;
        $recording = $data['recordingUrl'] ?? null;

        // Match to app
        $phone_suffix = substr(preg_replace('/\D/', '', $phone), -10);
        $app = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM " . CC_DB::apps() . " WHERE phone LIKE %s ORDER BY created_at DESC LIMIT 1",
            '%' . $phone_suffix
        ));

        // Log as a follow-up event
        if ($app) {
            $wpdb->insert(CC_DB::follow_ups(), [
                'app_id'    => $app->id,
                'type'      => 'call_' . $direction,
                'method'    => 'call',
                'body'      => "Call ($direction) — Duration: {$duration}s" . ($recording ? " | Recording: $recording" : ''),
                'sent_at'   => current_time('mysql'),
            ]);

            // Update call status on app
            if ($direction === 'outgoing' && $type === 'call.completed') {
                $wpdb->update(CC_DB::apps(), [
                    'call_status' => $duration > 30 ? 'connected' : 'no_answer',
                ], ['id' => $app->id]);
            }

            // Mark response if incoming call
            if ($direction === 'incoming') {
                $last_fu = $wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM " . CC_DB::follow_ups() . " WHERE app_id=%d AND response_received=0 ORDER BY sent_at DESC LIMIT 1",
                    $app->id
                ));
                if ($last_fu) {
                    $wpdb->update(CC_DB::follow_ups(), [
                        'response_received' => 1,
                        'response_at'       => current_time('mysql'),
                    ], ['id' => $last_fu->id]);
                }
            }
        }

        error_log("[PTP-CC] Call $direction $phone — {$duration}s — app:" . ($app ? $app->id : 'none'));

        // ── Schedule call intelligence capture (async, non-blocking) ──
        if ($type === 'call.completed' && class_exists('CC_OpenPhone_Platform')) {
            $call_id = $data['id'] ?? '';
            if ($call_id) {
                // Schedule for 30s later to let OpenPhone generate summary/transcript
                wp_schedule_single_event(time() + 30, 'ptp_cc_capture_call_intel', [$call_id, $data]);
            }
        }

        return [
            'received'    => true,
            'call'        => true,
            'direction'   => $direction,
            'duration'    => $duration,
            'matched_app' => $app ? $app->id : null,
        ];
    }

    private function handle_call_summary($data, $wpdb) {
        $call_id = $data['callId'] ?? $data['id'] ?? '';
        if (!$call_id) return ['received' => true, 'skipped' => 'no call_id'];

        error_log("[PTP-CC] Call summary webhook for: $call_id");

        // Update existing call intel record if it exists
        if (class_exists('CC_OpenPhone_Platform')) {
            $table = CC_OpenPhone_Platform::table_call_intel();
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE call_id=%s", $call_id));

            $summary = $data['content'] ?? $data['summary'] ?? '';
            if ($exists && $summary) {
                $wpdb->update($table, ['summary' => $summary], ['call_id' => $call_id]);
                return ['received' => true, 'updated' => true];
            }

            // If no record yet, capture full intel
            CC_OpenPhone_Platform::capture_call_intel($call_id);
        }

        return ['received' => true];
    }

    private function handle_call_transcript($data, $wpdb) {
        $call_id = $data['callId'] ?? $data['id'] ?? '';
        if (!$call_id) return ['received' => true, 'skipped' => 'no call_id'];

        error_log("[PTP-CC] Call transcript webhook for: $call_id");

        if (class_exists('CC_OpenPhone_Platform')) {
            $table = CC_OpenPhone_Platform::table_call_intel();
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE call_id=%s", $call_id));

            $transcript = $data['content'] ?? $data['transcript'] ?? '';
            if (is_array($transcript) && isset($transcript['dialogue'])) {
                $lines = [];
                foreach ($transcript['dialogue'] as $d) {
                    $lines[] = ($d['speaker'] ?? '?') . ': ' . ($d['content'] ?? $d['text'] ?? '');
                }
                $transcript = implode("\n", $lines);
            }

            if ($exists && $transcript) {
                $wpdb->update($table, ['transcript' => $transcript], ['call_id' => $call_id]);
                return ['received' => true, 'updated' => true];
            }

            CC_OpenPhone_Platform::capture_call_intel($call_id);
        }

        return ['received' => true];
    }
}
