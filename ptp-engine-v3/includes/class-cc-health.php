<?php
/**
 * PTP Command Center — Health Check
 *
 * Single endpoint that tests every integration and reports status.
 * GET /wp-json/ptp-cc/v1/health
 *
 * @since 6.1
 */
if (!defined('ABSPATH')) exit;

class CC_Health {

    public static function register_routes($ns) {
        register_rest_route($ns, '/health', [
            'methods'  => 'GET',
            'callback' => [__CLASS__, 'run_checks'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route($ns, '/health/fix', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'auto_fix'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);
    }

    /**
     * Run all health checks.
     */
    public static function run_checks() {
        $checks = [];

        $checks['database']   = self::check_database();
        $checks['openphone']  = self::check_openphone();
        $checks['stripe']     = self::check_stripe();
        $checks['ai']         = self::check_ai();
        $checks['cron']       = self::check_cron();
        $checks['webhooks']   = self::check_webhooks();
        $checks['sms']        = self::check_sms_capability();

        // Overall status
        $statuses = array_column($checks, 'status');
        $overall = 'healthy';
        if (in_array('error', $statuses)) $overall = 'critical';
        elseif (in_array('warning', $statuses)) $overall = 'degraded';

        $pass = count(array_filter($statuses, function($s) { return $s === 'ok'; }));

        return [
            'overall'    => $overall,
            'pass'       => $pass,
            'total'      => count($checks),
            'checks'     => $checks,
            'version'    => PTP_ENGINE_VER,
            'db_version' => get_option('ptp_cc_db_version', 'unknown'),
            'php'        => phpversion(),
            'wp'         => get_bloginfo('version'),
            'timestamp'  => current_time('mysql'),
        ];
    }

    /**
     * Auto-fix common issues.
     */
    public static function auto_fix() {
        $fixed = [];

        // Recreate missing tables
        CC_DB::create_tables();
        CC_GCal::create_tables();
        if (class_exists('CC_Campaigns')) CC_Campaigns::create_tables();
        CC_DB::flush_table_cache();
        $fixed[] = 'Ran table creation (dbDelta)';

        // Reseed if empty
        CC_DB::seed_data();
        $fixed[] = 'Checked seed data';

        // Fix cron
        if (!wp_next_scheduled('ptp_cc_run_sequences')) {
            wp_schedule_event(time(), 'ptp_cc_30min', 'ptp_cc_run_sequences');
            $fixed[] = 'Rescheduled sequences cron';
        }
        if (!wp_next_scheduled('ptp_cc_lead_scoring')) {
            wp_schedule_event(time() + 300, 'hourly', 'ptp_cc_lead_scoring');
            $fixed[] = 'Rescheduled lead scoring cron';
        }

        // Update DB version
        update_option('ptp_cc_db_version', PTP_ENGINE_VER);
        $fixed[] = 'Updated DB version to ' . PTP_ENGINE_VER;

        // Flush table cache
        CC_DB::flush_table_cache();
        $fixed[] = 'Flushed table cache';

        // Re-run health
        $health = self::run_checks();

        return [
            'fixed'  => $fixed,
            'health' => $health,
        ];
    }

    // ═══════════════════════════════════════
    //  INDIVIDUAL CHECKS
    // ═══════════════════════════════════════

    /**
     * Check all required database tables exist and have data.
     */
    private static function check_database() {
        global $wpdb;

        $tables = [
            'apps'        => CC_DB::apps(),
            'parents'     => CC_DB::parents(),
            'follow_ups'  => CC_DB::follow_ups(),
            'op_msgs'     => CC_DB::op_msgs(),
            'drafts'      => CC_DB::drafts(),
            'rules'       => CC_DB::rules(),
            'templates'   => CC_DB::templates(),
            'activity'    => CC_DB::activity(),
            'seg_hist'    => CC_DB::seg_hist(),
            'expenses'    => CC_DB::expenses(),
        ];

        // Campaign tables (optional, new)
        if (class_exists('CC_Campaigns')) {
            $tables['campaigns']     = CC_Campaigns::campaigns_table();
            $tables['campaign_msgs'] = CC_Campaigns::messages_table();
        }

        $missing = [];
        $counts  = [];

        foreach ($tables as $label => $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
                $missing[] = $label;
            } else {
                $counts[$label] = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
            }
        }

        if ($missing) {
            return [
                'status'  => 'error',
                'message' => 'Missing tables: ' . implode(', ', $missing),
                'missing' => $missing,
                'fix'     => 'Click "Auto Fix" or deactivate/reactivate the plugin',
            ];
        }

        return [
            'status'  => 'ok',
            'message' => count($tables) . ' tables present',
            'counts'  => $counts,
        ];
    }

    /**
     * Check OpenPhone API connectivity.
     */
    private static function check_openphone() {
        $key = get_option('ptp_openphone_api_key', '') ?: get_option('ptp_cc_openphone_api_key', '');
        $pid = get_option('ptp_openphone_from', '') ?: get_option('ptp_cc_openphone_phone_id', '');

        if (!$key) {
            return [
                'status'  => 'error',
                'message' => 'No API key configured',
                'fix'     => 'Add ptp_openphone_api_key or ptp_cc_openphone_api_key in Settings',
            ];
        }

        if (!$pid) {
            return [
                'status'  => 'warning',
                'message' => 'API key set but no phone number configured (ptp_openphone_from)',
                'fix'     => 'Set ptp_openphone_from to your OpenPhone phone number (e.g., +16106714778)',
            ];
        }

        // Try a lightweight API call
        $r = wp_remote_get('https://api.openphone.com/v1/phone-numbers', [
            'headers' => ['Authorization' => $key],
            'timeout' => 10,
        ]);

        if (is_wp_error($r)) {
            return [
                'status'  => 'error',
                'message' => 'API request failed: ' . $r->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($r);
        if ($code === 401) {
            return [
                'status'  => 'error',
                'message' => 'Invalid API key (HTTP 401)',
                'fix'     => 'Check your OpenPhone API key',
            ];
        }

        if ($code !== 200) {
            return [
                'status'  => 'warning',
                'message' => 'API returned HTTP ' . $code,
            ];
        }

        $body = json_decode(wp_remote_retrieve_body($r), true);
        $numbers = $body['data'] ?? [];

        // Check if configured phone number exists in account
        // ptp_openphone_from stores the phone number (e.g., "+16106714778"), not the phoneNumberId
        $found = false;
        $resolved_id = '';
        foreach ($numbers as $num) {
            if (($num['number'] ?? '') === $pid || ($num['formattedNumber'] ?? '') === $pid || ($num['id'] ?? '') === $pid) {
                $found = true;
                $resolved_id = $num['id'] ?? '';
                break;
            }
        }

        return [
            'status'       => 'ok',
            'message'      => 'Connected — ' . count($numbers) . ' phone number(s)',
            'phone_id_ok'  => $found,
            'phone_id_msg' => $found
                ? 'Phone number matched (ID: ' . $resolved_id . ')'
                : 'WARNING: Configured phone "' . $pid . '" not found in account numbers',
        ];
    }

    /**
     * Check Stripe API connectivity.
     */
    private static function check_stripe() {
        $key = get_option('ptp_cc_stripe_secret', '') ?: get_option('ptp_stripe_secret_key', '');

        if (!$key) {
            return [
                'status'  => 'warning',
                'message' => 'No Stripe secret key configured',
                'fix'     => 'Set ptp_cc_stripe_secret or ptp_stripe_secret_key — needed for payment sync',
            ];
        }

        $webhook_secret = get_option('ptp_cc_stripe_webhook_secret', '');

        // Quick balance check (lightweight)
        $r = wp_remote_get('https://api.stripe.com/v1/balance', [
            'headers' => ['Authorization' => 'Bearer ' . $key],
            'timeout' => 10,
        ]);

        if (is_wp_error($r)) {
            return [
                'status'  => 'error',
                'message' => 'API request failed: ' . $r->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($r);
        if ($code === 401) {
            return [
                'status'  => 'error',
                'message' => 'Invalid API key (HTTP 401)',
            ];
        }

        $body = json_decode(wp_remote_retrieve_body($r), true);
        $available = 0;
        foreach ($body['available'] ?? [] as $b) {
            if (($b['currency'] ?? '') === 'usd') $available = $b['amount'] / 100;
        }

        return [
            'status'         => 'ok',
            'message'        => 'Connected — $' . number_format($available, 2) . ' available',
            'webhook_secret' => $webhook_secret ? 'configured' : 'NOT SET — webhook unverified',
            'last_sync'      => get_option('ptp_cc_last_stripe_sync', 'never'),
        ];
    }

    /**
     * Check Anthropic/Claude API connectivity.
     */
    private static function check_ai() {
        if (!class_exists('CC_AI_Engine')) {
            return [
                'status'  => 'warning',
                'message' => 'AI Engine class not loaded',
            ];
        }

        $key = get_option(CC_AI_Engine::OPT_API_KEY, '');
        $enabled = get_option(CC_AI_Engine::OPT_ENABLED, 'yes') === 'yes';

        if (!$enabled) {
            return [
                'status'  => 'warning',
                'message' => 'AI Engine is disabled',
                'fix'     => 'Enable in AI Engine → Settings',
            ];
        }

        if (!$key) {
            return [
                'status'  => 'warning',
                'message' => 'No Anthropic API key configured',
                'fix'     => 'Add your API key in AI Engine → Settings',
            ];
        }

        // Quick model list call to verify key
        $r = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $key,
                'anthropic-version'  => '2023-06-01',
            ],
            'body' => wp_json_encode([
                'model'      => 'claude-sonnet-4-20250514',
                'max_tokens' => 10,
                'messages'   => [['role' => 'user', 'content' => 'Reply with just the word OK']],
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($r)) {
            return [
                'status'  => 'error',
                'message' => 'API request failed: ' . $r->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($r);
        if ($code === 401) {
            return [
                'status'  => 'error',
                'message' => 'Invalid API key (HTTP 401)',
                'fix'     => 'Check your Anthropic API key',
            ];
        }

        if ($code !== 200) {
            $body = json_decode(wp_remote_retrieve_body($r), true);
            return [
                'status'  => 'warning',
                'message' => 'API returned HTTP ' . $code . ': ' . ($body['error']['message'] ?? 'unknown'),
            ];
        }

        $auto_draft = get_option(CC_AI_Engine::OPT_AUTO_DRAFT, 'yes') === 'yes';
        $tone = get_option(CC_AI_Engine::OPT_TONE, 'friendly');

        return [
            'status'     => 'ok',
            'message'    => 'Claude API connected',
            'model'      => CC_AI_Engine::MODEL,
            'auto_draft' => $auto_draft ? 'on' : 'off',
            'tone'       => $tone,
        ];
    }

    /**
     * Check WP Cron schedules.
     */
    private static function check_cron() {
        $seq_next = wp_next_scheduled('ptp_cc_run_sequences');
        $lead_next = wp_next_scheduled('ptp_cc_lead_scoring');

        $issues = [];
        if (!$seq_next) $issues[] = 'Sequences cron not scheduled';
        if (!$lead_next) $issues[] = 'Lead scoring cron not scheduled';

        // Check if WP Cron is disabled
        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

        if ($issues) {
            return [
                'status'  => 'error',
                'message' => implode('; ', $issues),
                'fix'     => 'Click "Auto Fix" to reschedule',
                'wp_cron_disabled' => $cron_disabled,
            ];
        }

        return [
            'status'  => 'ok',
            'message' => 'All cron jobs scheduled',
            'sequences_next'   => date('Y-m-d H:i:s', $seq_next),
            'lead_scoring_next' => date('Y-m-d H:i:s', $lead_next),
            'wp_cron_disabled'  => $cron_disabled,
            'wp_cron_note'      => $cron_disabled ? 'DISABLE_WP_CRON is true — make sure you have a real cron hitting wp-cron.php' : 'Using WP internal cron',
        ];
    }

    /**
     * Check webhook endpoints are reachable.
     */
    private static function check_webhooks() {
        $rest_url = rest_url('ptp-cc/v1/');

        $op_secret = get_option('ptp_cc_openphone_webhook_secret', '') ?: get_option('ptp_cc_openphone_webhook_token', '');
        $stripe_secret = get_option('ptp_cc_stripe_webhook_secret', '');

        $warnings = [];
        if (!$op_secret) $warnings[] = 'OpenPhone webhook secret/token not set — accepting unverified payloads';
        if (!$stripe_secret) $warnings[] = 'Stripe webhook secret not set — accepting unverified payloads';

        return [
            'status'  => $warnings ? 'warning' : 'ok',
            'message' => $warnings ? implode('; ', $warnings) : 'Webhook secrets configured',
            'endpoints' => [
                'openphone' => $rest_url . 'webhooks/openphone',
                'stripe'    => $rest_url . 'stripe/webhook',
            ],
            'openphone_secured' => !empty($op_secret),
            'stripe_secured'    => !empty($stripe_secret),
            'fix' => $warnings ? 'Set ptp_cc_openphone_webhook_secret and ptp_cc_stripe_webhook_secret in wp_options or Settings' : null,
        ];
    }

    /**
     * Check SMS sending capability.
     */
    private static function check_sms_capability() {
        // Check which SMS path is available
        $path = 'none';
        if (class_exists('PTP_SMS_V71')) {
            $path = 'PTP_SMS_V71 (Training Platform)';
        } elseif (class_exists('PTP_SMS')) {
            $path = 'PTP_SMS (Training Platform legacy)';
        } else {
            $key = get_option('ptp_openphone_api_key', '') ?: get_option('ptp_cc_openphone_api_key', '');
            $pid = get_option('ptp_openphone_from', '') ?: get_option('ptp_cc_openphone_phone_id', '');
            if ($key && $pid) {
                $path = 'CC direct → OpenPhone API';
            }
        }

        if ($path === 'none') {
            return [
                'status'  => 'error',
                'message' => 'No SMS sending method available',
                'fix'     => 'Configure OpenPhone API key + phone ID, or activate PTP Training Platform plugin',
            ];
        }

        // Count recent sends
        global $wpdb;
        $mt = CC_DB::op_msgs();
        $today = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM $mt WHERE direction='outgoing' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $week = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM $mt WHERE direction='outgoing' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        // Check retry queue
        $rq = CC_DB::retry_queue();
        $pending_retries = 0;
        $failed_retries = 0;
        if ($wpdb->get_var("SHOW TABLES LIKE '$rq'") === $rq) {
            $pending_retries = (int)$wpdb->get_var("SELECT COUNT(*) FROM $rq WHERE retry_count < 3");
            $failed_retries = (int)$wpdb->get_var("SELECT COUNT(*) FROM $rq WHERE retry_count >= 3");
        }

        $warnings = [];
        if ($pending_retries > 0) $warnings[] = "$pending_retries messages waiting to retry";
        if ($failed_retries > 0) $warnings[] = "$failed_retries messages permanently failed (3 attempts exhausted)";

        return [
            'status'          => $failed_retries > 0 ? 'warning' : 'ok',
            'message'         => 'SMS ready via ' . $path . ($warnings ? ' — ' . implode(', ', $warnings) : ''),
            'path'            => $path,
            'sent_today'      => $today,
            'sent_week'       => $week,
            'pending_retries' => $pending_retries,
            'failed_retries'  => $failed_retries,
        ];
    }
}
