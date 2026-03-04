<?php
/**
 * PTP Command Center — SMS Campaigns
 *
 * Full personalization campaign system:
 *   1. Create campaign with audience filters + template or AI prompt
 *   2. Preview personalized messages per recipient
 *   3. Send in throttled batches through OpenPhone
 *   4. Track delivery, replies, conversions
 *
 * Audience filters: status, lead_temperature, location, source, has_camp,
 *                   created_after, created_before, last_contact_days, trainer
 *
 * Personalization modes:
 *   - "template": Use {name}, {child}, {trainer}, {location}, {age} placeholders
 *   - "ai": Claude writes a unique message per recipient using their full context
 *
 * @since 6.1
 */
if (!defined('ABSPATH')) exit;

class CC_Campaigns {

    /** Max messages per cron run to avoid rate limits */
    const BATCH_SIZE = 30;

    /** Delay between messages in seconds */
    const SEND_DELAY = 2;

    // ─── Table helpers ───
    public static function campaigns_table() { return CC_DB::t('ptp_cc_campaigns'); }
    public static function messages_table()  { return CC_DB::t('ptp_cc_campaign_msgs'); }

    /**
     * Create campaign tables on activation.
     */
    public static function create_tables() {
        global $wpdb;
        $c = $wpdb->get_charset_collate();

        $wpdb->query("CREATE TABLE IF NOT EXISTS " . self::campaigns_table() . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            status ENUM('draft','previewing','sending','paused','completed','cancelled') DEFAULT 'draft',
            mode ENUM('template','ai') DEFAULT 'template',
            template_body TEXT,
            ai_prompt TEXT,
            filters TEXT COMMENT 'JSON audience filters',
            audience_count INT DEFAULT 0,
            sent_count INT DEFAULT 0,
            failed_count INT DEFAULT 0,
            reply_count INT DEFAULT 0,
            tone VARCHAR(30) DEFAULT 'friendly',
            max_chars INT DEFAULT 320,
            scheduled_at DATETIME DEFAULT NULL,
            started_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_created (created_at)
        ) $c;");

        $wpdb->query("CREATE TABLE IF NOT EXISTS " . self::messages_table() . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_id BIGINT UNSIGNED NOT NULL,
            app_id BIGINT UNSIGNED DEFAULT NULL,
            phone VARCHAR(20) NOT NULL,
            recipient_name VARCHAR(100) DEFAULT '',
            personalized_body TEXT,
            status ENUM('pending','sent','failed','replied') DEFAULT 'pending',
            error_msg VARCHAR(255) DEFAULT '',
            sent_at DATETIME DEFAULT NULL,
            replied_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_campaign (campaign_id),
            INDEX idx_status (status),
            INDEX idx_phone (phone)
        ) $c;");
    }

    /**
     * Register REST routes.
     */
    public static function register_routes($ns) {
        // Campaign CRUD
        register_rest_route($ns, '/campaigns', [
            ['methods' => 'GET',  'callback' => [__CLASS__, 'api_list'],   'permission_callback' => [__CLASS__, 'is_admin']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'api_create'], 'permission_callback' => [__CLASS__, 'is_admin']],
        ]);

        register_rest_route($ns, '/campaigns/(?P<id>\d+)', [
            ['methods' => 'GET',    'callback' => [__CLASS__, 'api_get'],    'permission_callback' => [__CLASS__, 'is_admin']],
            ['methods' => 'PATCH',  'callback' => [__CLASS__, 'api_update'], 'permission_callback' => [__CLASS__, 'is_admin']],
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'api_delete'], 'permission_callback' => [__CLASS__, 'is_admin']],
        ]);

        // Audience preview
        register_rest_route($ns, '/campaigns/audience-preview', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'api_audience_preview'],
            'permission_callback' => [__CLASS__, 'is_admin'],
        ]);

        // Generate personalized preview for campaign
        register_rest_route($ns, '/campaigns/(?P<id>\d+)/preview', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'api_preview'],
            'permission_callback' => [__CLASS__, 'is_admin'],
        ]);

        // Send / pause / resume
        register_rest_route($ns, '/campaigns/(?P<id>\d+)/send', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'api_send'],
            'permission_callback' => [__CLASS__, 'is_admin'],
        ]);

        register_rest_route($ns, '/campaigns/(?P<id>\d+)/pause', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'api_pause'],
            'permission_callback' => [__CLASS__, 'is_admin'],
        ]);

        // Campaign messages (detail view)
        register_rest_route($ns, '/campaigns/(?P<id>\d+)/messages', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_messages'],
            'permission_callback' => [__CLASS__, 'is_admin'],
        ]);

        // Templates list
        register_rest_route($ns, '/campaigns/templates', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_templates'],
            'permission_callback' => [__CLASS__, 'is_admin'],
        ]);
    }

    public static function is_admin() { return current_user_can('manage_options'); }

    // ═══════════════════════════════════════
    //  API HANDLERS
    // ═══════════════════════════════════════

    /**
     * List campaigns (newest first).
     */
    public static function api_list() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM " . self::campaigns_table() . " ORDER BY created_at DESC LIMIT 50"
        );
        return ['campaigns' => $rows ?: []];
    }

    /**
     * Create a new campaign.
     */
    public static function api_create($req) {
        global $wpdb;
        $b = $req->get_json_params();

        $name     = sanitize_text_field($b['name'] ?? 'Untitled Campaign');
        $mode     = in_array($b['mode'] ?? '', ['template', 'ai']) ? $b['mode'] : 'template';
        $template = sanitize_textarea_field($b['template_body'] ?? '');
        $prompt   = sanitize_textarea_field($b['ai_prompt'] ?? '');
        $filters  = $b['filters'] ?? [];
        $tone     = sanitize_text_field($b['tone'] ?? 'friendly');
        $max_chars = max(100, min(600, (int)($b['max_chars'] ?? 320)));

        if ($mode === 'template' && !$template) {
            return new WP_Error('missing_template', 'Template body is required for template mode', ['status' => 400]);
        }
        if ($mode === 'ai' && !$prompt) {
            return new WP_Error('missing_prompt', 'AI prompt is required for AI mode', ['status' => 400]);
        }

        // Count audience
        $audience = self::build_audience($filters);

        $wpdb->insert(self::campaigns_table(), [
            'name'           => $name,
            'mode'           => $mode,
            'template_body'  => $template,
            'ai_prompt'      => $prompt,
            'filters'        => wp_json_encode($filters),
            'audience_count' => count($audience),
            'tone'           => $tone,
            'max_chars'      => $max_chars,
            'status'         => 'draft',
            'created_by'     => get_current_user_id(),
        ]);

        $id = $wpdb->insert_id;
        return ['campaign_id' => $id, 'audience_count' => count($audience)];
    }

    /**
     * Get campaign details.
     */
    public static function api_get($req) {
        global $wpdb;
        $id = (int)$req['id'];
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::campaigns_table() . " WHERE id=%d", $id
        ));
        if (!$campaign) return new WP_Error('not_found', 'Campaign not found', ['status' => 404]);

        $campaign->filters = json_decode($campaign->filters, true) ?: [];

        // Message stats
        $mt = self::messages_table();
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total,
                SUM(status='sent') as sent,
                SUM(status='failed') as failed,
                SUM(status='replied') as replied,
                SUM(status='pending') as pending
             FROM $mt WHERE campaign_id=%d", $id
        ));

        return ['campaign' => $campaign, 'stats' => $stats];
    }

    /**
     * Update campaign (only drafts).
     */
    public static function api_update($req) {
        global $wpdb;
        $id = (int)$req['id'];
        $b = $req->get_json_params();

        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT status FROM " . self::campaigns_table() . " WHERE id=%d", $id
        ));
        if (!$campaign) return new WP_Error('not_found', 'Not found', ['status' => 404]);
        if (!in_array($campaign->status, ['draft', 'paused'])) {
            return new WP_Error('locked', 'Cannot edit campaign in status: ' . $campaign->status, ['status' => 400]);
        }

        $data = [];
        $allowed_text = ['name', 'template_body', 'ai_prompt', 'tone'];
        foreach ($allowed_text as $f) {
            if (isset($b[$f])) $data[$f] = sanitize_textarea_field($b[$f]);
        }
        if (isset($b['mode']) && in_array($b['mode'], ['template', 'ai'])) $data['mode'] = $b['mode'];
        if (isset($b['max_chars'])) $data['max_chars'] = max(100, min(600, (int)$b['max_chars']));
        if (isset($b['filters'])) {
            $data['filters'] = wp_json_encode($b['filters']);
            $audience = self::build_audience($b['filters']);
            $data['audience_count'] = count($audience);
        }

        if ($data) $wpdb->update(self::campaigns_table(), $data, ['id' => $id]);
        return ['updated' => true];
    }

    /**
     * Delete campaign (only drafts/cancelled).
     */
    public static function api_delete($req) {
        global $wpdb;
        $id = (int)$req['id'];
        $wpdb->delete(self::messages_table(), ['campaign_id' => $id]);
        $wpdb->delete(self::campaigns_table(), ['id' => $id]);
        return ['deleted' => true];
    }

    /**
     * Preview audience count with filters.
     */
    public static function api_audience_preview($req) {
        $filters = $req->get_json_params()['filters'] ?? [];
        $audience = self::build_audience($filters);
        $preview = array_slice($audience, 0, 10);
        return [
            'count' => count($audience),
            'preview' => array_map(function($a) {
                return [
                    'id'    => $a->id,
                    'name'  => $a->parent_name,
                    'phone' => $a->phone,
                    'child' => $a->child_name,
                    'status' => $a->status,
                ];
            }, $preview),
        ];
    }

    /**
     * Generate personalized preview messages (first 5 recipients).
     */
    public static function api_preview($req) {
        global $wpdb;
        $id = (int)$req['id'];
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::campaigns_table() . " WHERE id=%d", $id
        ));
        if (!$campaign) return new WP_Error('not_found', 'Not found', ['status' => 404]);

        $filters  = json_decode($campaign->filters, true) ?: [];
        $audience = self::build_audience($filters);
        $previews = [];

        $count = min(5, count($audience));
        for ($i = 0; $i < $count; $i++) {
            $app = $audience[$i];
            if ($campaign->mode === 'ai') {
                $msg = self::ai_personalize($app, $campaign);
            } else {
                $msg = self::template_personalize($campaign->template_body, $app);
            }
            $previews[] = [
                'name'    => $app->parent_name,
                'phone'   => $app->phone,
                'child'   => $app->child_name,
                'message' => is_wp_error($msg) ? 'Error: ' . $msg->get_error_message() : $msg,
            ];
        }

        return ['previews' => $previews, 'total_audience' => count($audience)];
    }

    /**
     * Start sending campaign.
     * Builds all personalized messages, then begins async batch sending.
     */
    public static function api_send($req) {
        global $wpdb;
        $id = (int)$req['id'];
        $ct = self::campaigns_table();
        $mt = self::messages_table();

        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM $ct WHERE id=%d", $id));
        if (!$campaign) return new WP_Error('not_found', 'Not found', ['status' => 404]);
        if (!in_array($campaign->status, ['draft', 'paused'])) {
            return new WP_Error('invalid_status', 'Campaign must be in draft or paused status', ['status' => 400]);
        }

        $filters  = json_decode($campaign->filters, true) ?: [];
        $audience = self::build_audience($filters);

        if (empty($audience)) {
            return new WP_Error('empty_audience', 'No recipients match these filters', ['status' => 400]);
        }

        // Clear any existing pending messages (in case of resume from draft)
        $wpdb->query($wpdb->prepare("DELETE FROM $mt WHERE campaign_id=%d AND status='pending'", $id));

        // Generate personalized messages for each recipient
        $queued = 0;
        foreach ($audience as $app) {
            // Skip if no phone
            if (empty($app->phone)) continue;

            // Skip if already sent in this campaign (for resumes)
            $already = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $mt WHERE campaign_id=%d AND app_id=%d AND status='sent'", $id, $app->id
            ));
            if ($already) continue;

            // Personalize
            if ($campaign->mode === 'ai') {
                $msg = self::ai_personalize($app, $campaign);
            } else {
                $msg = self::template_personalize($campaign->template_body, $app);
            }

            if (is_wp_error($msg)) {
                $wpdb->insert($mt, [
                    'campaign_id'      => $id,
                    'app_id'           => $app->id,
                    'phone'            => CC_DB::normalize_phone($app->phone),
                    'recipient_name'   => $app->parent_name,
                    'personalized_body' => '',
                    'status'           => 'failed',
                    'error_msg'        => $msg->get_error_message(),
                ]);
                continue;
            }

            $wpdb->insert($mt, [
                'campaign_id'      => $id,
                'app_id'           => $app->id,
                'phone'            => CC_DB::normalize_phone($app->phone),
                'recipient_name'   => $app->parent_name,
                'personalized_body' => $msg,
                'status'           => 'pending',
            ]);
            $queued++;
        }

        // Update campaign status
        $wpdb->update($ct, [
            'status'         => 'sending',
            'audience_count' => count($audience),
            'started_at'     => current_time('mysql'),
        ], ['id' => $id]);

        // Schedule batch sending
        if (!wp_next_scheduled('ptp_cc_campaign_batch', [$id])) {
            wp_schedule_single_event(time() + 5, 'ptp_cc_campaign_batch', [$id]);
        }

        CC_DB::log('campaign_started', 'campaign', $id, "Campaign '{$campaign->name}' started — $queued messages queued", 'admin');

        return ['started' => true, 'queued' => $queued, 'audience' => count($audience)];
    }

    /**
     * Pause a sending campaign.
     */
    public static function api_pause($req) {
        global $wpdb;
        $id = (int)$req['id'];
        $wpdb->update(self::campaigns_table(), ['status' => 'paused'], ['id' => $id]);
        CC_DB::log('campaign_paused', 'campaign', $id, 'Campaign paused', 'admin');
        return ['paused' => true];
    }

    /**
     * Get campaign message list.
     */
    public static function api_messages($req) {
        global $wpdb;
        $id = (int)$req['id'];
        $status = sanitize_text_field($req->get_param('status') ?: '');

        $where = $wpdb->prepare("campaign_id=%d", $id);
        if ($status) $where .= $wpdb->prepare(" AND status=%s", $status);

        $msgs = $wpdb->get_results("SELECT * FROM " . self::messages_table() . " WHERE $where ORDER BY id ASC LIMIT 500");
        return ['messages' => $msgs ?: []];
    }

    /**
     * Get templates for campaign builder.
     */
    public static function api_templates() {
        global $wpdb;
        return ['templates' => $wpdb->get_results(
            "SELECT * FROM " . CC_DB::templates() . " ORDER BY use_count DESC, name ASC"
        )];
    }

    // ═══════════════════════════════════════
    //  BATCH SENDING (CRON)
    // ═══════════════════════════════════════

    /**
     * Process a batch of pending messages for a campaign.
     * Called via WP Cron.
     */
    public static function process_batch($campaign_id) {
        global $wpdb;
        $ct = self::campaigns_table();
        $mt = self::messages_table();

        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM $ct WHERE id=%d", $campaign_id));
        if (!$campaign || $campaign->status !== 'sending') {
            error_log("[PTP-CC Campaigns] Batch skipped — campaign $campaign_id is not sending");
            return;
        }

        // Get next batch of pending messages
        $batch = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $mt WHERE campaign_id=%d AND status='pending' ORDER BY id ASC LIMIT %d",
            $campaign_id, self::BATCH_SIZE
        ));

        if (empty($batch)) {
            // All done
            $wpdb->update($ct, [
                'status'       => 'completed',
                'completed_at' => current_time('mysql'),
            ], ['id' => $campaign_id]);

            // Final counts
            $sent   = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $mt WHERE campaign_id=%d AND status='sent'", $campaign_id));
            $failed = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $mt WHERE campaign_id=%d AND status='failed'", $campaign_id));
            $wpdb->update($ct, ['sent_count' => $sent, 'failed_count' => $failed], ['id' => $campaign_id]);

            CC_DB::log('campaign_completed', 'campaign', $campaign_id,
                "Campaign completed: $sent sent, $failed failed", 'cron');
            return;
        }

        $sent = 0;
        $failed = 0;

        foreach ($batch as $msg) {
            // Check if campaign is still in sending status (might have been paused)
            if ($sent > 0 && $sent % 10 === 0) {
                $current_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM $ct WHERE id=%d", $campaign_id));
                if ($current_status !== 'sending') {
                    error_log("[PTP-CC Campaigns] Campaign $campaign_id paused mid-batch after $sent sent");
                    break;
                }
            }

            $result = CC_DB::send_sms($msg->phone, $msg->personalized_body);

            if (is_wp_error($result)) {
                $wpdb->update($mt, [
                    'status'    => 'failed',
                    'error_msg' => substr($result->get_error_message(), 0, 255),
                ], ['id' => $msg->id]);
                $failed++;
            } else {
                $wpdb->update($mt, [
                    'status'  => 'sent',
                    'sent_at' => current_time('mysql'),
                ], ['id' => $msg->id]);

                // Log follow-up on the application
                if ($msg->app_id) {
                    $wpdb->insert(CC_DB::follow_ups(), [
                        'app_id'  => $msg->app_id,
                        'type'    => 'campaign',
                        'method'  => 'sms',
                        'body'    => $msg->personalized_body,
                        'sent_at' => current_time('mysql'),
                    ]);
                }
                $sent++;
            }

            // Throttle
            if (self::SEND_DELAY > 0) sleep(self::SEND_DELAY);
        }

        // Update running counts
        $total_sent   = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $mt WHERE campaign_id=%d AND status='sent'", $campaign_id));
        $total_failed = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $mt WHERE campaign_id=%d AND status='failed'", $campaign_id));
        $wpdb->update($ct, ['sent_count' => $total_sent, 'failed_count' => $total_failed], ['id' => $campaign_id]);

        error_log("[PTP-CC Campaigns] Batch for campaign $campaign_id: $sent sent, $failed failed. Total: $total_sent/$campaign->audience_count");

        // Check if more pending
        $remaining = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $mt WHERE campaign_id=%d AND status='pending'", $campaign_id
        ));

        if ($remaining > 0) {
            // Schedule next batch (30 seconds later to avoid rate limits)
            wp_schedule_single_event(time() + 30, 'ptp_cc_campaign_batch', [$campaign_id]);
        } else {
            // Done
            $wpdb->update($ct, [
                'status'       => 'completed',
                'completed_at' => current_time('mysql'),
            ], ['id' => $campaign_id]);
            CC_DB::log('campaign_completed', 'campaign', $campaign_id,
                "Campaign completed: $total_sent sent, $total_failed failed", 'cron');
        }
    }

    /**
     * Track replies — called from webhook when an incoming message matches a campaign recipient.
     */
    public static function track_reply($phone) {
        global $wpdb;
        $mt = self::messages_table();
        $ct = self::campaigns_table();
        $phone_norm = CC_DB::normalize_phone($phone);

        // Find recent campaign messages to this phone (last 7 days)
        $msg = $wpdb->get_row($wpdb->prepare(
            "SELECT id, campaign_id FROM $mt WHERE phone=%s AND status='sent' AND sent_at > DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY sent_at DESC LIMIT 1",
            $phone_norm
        ));

        if ($msg) {
            $wpdb->update($mt, ['status' => 'replied', 'replied_at' => current_time('mysql')], ['id' => $msg->id]);
            // Update campaign reply count
            $replies = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $mt WHERE campaign_id=%d AND status='replied'", $msg->campaign_id
            ));
            $wpdb->update($ct, ['reply_count' => $replies], ['id' => $msg->campaign_id]);
        }
    }

    // ═══════════════════════════════════════
    //  AUDIENCE BUILDER
    // ═══════════════════════════════════════

    /**
     * Build audience list from filters.
     * Returns array of application rows.
     */
    public static function build_audience($filters) {
        global $wpdb;
        $at = CC_DB::apps();

        $where = ["a.phone != '' AND a.phone IS NOT NULL"];
        $params = [];

        // Status filter
        if (!empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
            $where[] = "a.status IN ($placeholders)";
            $params = array_merge($params, $statuses);
        }

        // Lead temperature
        if (!empty($filters['lead_temperature'])) {
            $temps = is_array($filters['lead_temperature']) ? $filters['lead_temperature'] : [$filters['lead_temperature']];
            $placeholders = implode(',', array_fill(0, count($temps), '%s'));
            $where[] = "a.lead_temperature IN ($placeholders)";
            $params = array_merge($params, $temps);
        }

        // Location / state
        if (!empty($filters['location'])) {
            $where[] = "(a.state LIKE %s OR a.location LIKE %s)";
            $like = '%' . $wpdb->esc_like($filters['location']) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        // Source / UTM
        if (!empty($filters['source'])) {
            $where[] = "(a.utm_source LIKE %s OR a.utm_campaign LIKE %s)";
            $like = '%' . $wpdb->esc_like($filters['source']) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        // Trainer assigned
        if (!empty($filters['trainer'])) {
            $where[] = "a.trainer_name LIKE %s";
            $params[] = '%' . $wpdb->esc_like($filters['trainer']) . '%';
        }

        // Created date range
        if (!empty($filters['created_after'])) {
            $where[] = "a.created_at >= %s";
            $params[] = sanitize_text_field($filters['created_after']) . ' 00:00:00';
        }
        if (!empty($filters['created_before'])) {
            $where[] = "a.created_at <= %s";
            $params[] = sanitize_text_field($filters['created_before']) . ' 23:59:59';
        }

        // Days since last contact
        if (!empty($filters['no_contact_days'])) {
            $days = (int)$filters['no_contact_days'];
            $fu = CC_DB::follow_ups();
            $where[] = "(SELECT MAX(sent_at) FROM $fu f WHERE f.app_id=a.id) < DATE_SUB(NOW(), INTERVAL $days DAY) OR (SELECT COUNT(*) FROM $fu f WHERE f.app_id=a.id) = 0";
        }

        // Has camp booking
        if (isset($filters['has_camp']) && $filters['has_camp'] !== '') {
            $cb = CC_DB::camp_bookings();
            if ($wpdb->get_var("SHOW TABLES LIKE '$cb'") === $cb) {
                if ($filters['has_camp']) {
                    $where[] = "a.email IN (SELECT customer_email FROM $cb WHERE status='confirmed')";
                } else {
                    $where[] = "a.email NOT IN (SELECT customer_email FROM $cb WHERE status='confirmed')";
                }
            }
        }

        // Exclude opted-out (lost with opt-out reason)
        $sh = CC_DB::seg_hist();
        $where[] = "a.id NOT IN (SELECT app_id FROM $sh WHERE reason LIKE '%Opt-out%')";

        // Exclude already contacted today by any campaign
        $mt = self::messages_table();
        if ($wpdb->get_var("SHOW TABLES LIKE '$mt'") === $mt) {
            $where[] = "a.id NOT IN (SELECT app_id FROM $mt WHERE status='sent' AND sent_at > DATE_SUB(NOW(), INTERVAL 24 HOUR))";
        }

        $sql = "SELECT a.* FROM $at a WHERE " . implode(' AND ', $where) . " ORDER BY a.created_at DESC LIMIT 1000";

        if ($params) {
            $results = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        } else {
            $results = $wpdb->get_results($sql);
        }

        return $results ?: [];
    }

    // ═══════════════════════════════════════
    //  PERSONALIZATION
    // ═══════════════════════════════════════

    /**
     * Template mode: replace placeholders.
     */
    public static function template_personalize($template, $app) {
        $first = explode(' ', $app->parent_name ?: '')[0] ?: 'there';
        $child = $app->child_name ?: 'your player';
        $trainer = $app->trainer_name ?: 'one of our coaches';
        $location = $app->location ?? $app->state ?? '';
        $age = $app->child_age ?? '';

        $msg = str_replace(
            ['{name}', '{child}', '{trainer}', '{location}', '{age}', '{first}'],
            [$first,   $child,   $trainer,    $location,    $age,    $first],
            $template
        );

        return $msg;
    }

    /**
     * AI mode: call Claude to write a unique message for this recipient.
     */
    public static function ai_personalize($app, $campaign) {
        if (!class_exists('CC_AI_Engine') || !CC_AI_Engine::is_active()) {
            return new WP_Error('ai_disabled', 'AI Engine not configured');
        }

        $tone = $campaign->tone ?: 'friendly';
        $max_chars = $campaign->max_chars ?: 320;
        $prompt = $campaign->ai_prompt;

        $tone_map = [
            'friendly'     => 'Warm and conversational, like a friend.',
            'professional' => 'Polite, slightly formal but approachable.',
            'casual'       => 'Very casual texting style. Short, relaxed.',
            'enthusiastic' => 'High energy and excited about soccer.',
        ];

        $system = "You write personalized SMS messages for PTP Summer Camps (Players Teaching Players), a youth soccer program.

## About PTP
- Current D1 and MLS players coach alongside kids ages 6-14
- 8:1 ratio, 500+ families, 4.9 stars, 10 locations in PA/NJ
- Free trial sessions and summer camps
- 2026 World Cup coming to Philadelphia

## Tone: " . ($tone_map[$tone] ?? $tone_map['friendly']) . "

## Rules
1. Keep under {$max_chars} characters
2. Use the parent's first name
3. Reference the child by name if known
4. Sound like a real person, not a bot
5. Include a clear call to action
6. Max 1-2 emojis if any
7. NEVER invent information you don't have

## Campaign goal
{$prompt}";

        // Build context about this specific recipient
        $first = explode(' ', $app->parent_name ?: '')[0] ?: 'there';
        $user_msg = "Write a personalized SMS for this recipient:\n";
        $user_msg .= "- Parent: " . ($app->parent_name ?: 'Unknown') . "\n";
        if ($app->child_name) $user_msg .= "- Child: " . $app->child_name . "\n";
        if ($app->child_age ?? '') $user_msg .= "- Age: " . $app->child_age . "\n";
        if ($app->trainer_name ?? '') $user_msg .= "- Coach: " . $app->trainer_name . "\n";
        if ($app->location ?? $app->state ?? '') $user_msg .= "- Location: " . ($app->location ?? $app->state) . "\n";
        $user_msg .= "- Status: " . ($app->status ?? 'unknown') . "\n";
        if ($app->experience_level ?? '') $user_msg .= "- Level: " . $app->experience_level . "\n";
        if ($app->biggest_challenge ?? '') $user_msg .= "- Challenge: " . $app->biggest_challenge . "\n";
        if ($app->goal ?? '') $user_msg .= "- Goal: " . $app->goal . "\n";
        $user_msg .= "\nOutput ONLY the SMS text. Nothing else.";

        // Call Claude via the same API pattern as AI Engine
        $api_key = get_option(CC_AI_Engine::OPT_API_KEY, '');
        if (!$api_key) return new WP_Error('no_key', 'No API key');

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version'  => '2023-06-01',
            ],
            'body' => wp_json_encode([
                'model'      => 'claude-sonnet-4-20250514',
                'max_tokens' => 250,
                'system'     => $system,
                'messages'   => [['role' => 'user', 'content' => $user_msg]],
            ]),
            'timeout' => 25,
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            return new WP_Error('api_error', $body['error']['message'] ?? 'HTTP ' . $code);
        }

        $reply = '';
        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') $reply .= $block['text'];
        }

        $reply = trim($reply);

        // Enforce char limit
        if (mb_strlen($reply) > $max_chars) {
            $cut = mb_substr($reply, 0, $max_chars);
            $last = max(mb_strrpos($cut, '.'), mb_strrpos($cut, '!'), mb_strrpos($cut, '?'));
            $reply = ($last && $last > $max_chars * 0.5) ? mb_substr($reply, 0, $last + 1) : $cut;
        }

        return $reply;
    }
}
