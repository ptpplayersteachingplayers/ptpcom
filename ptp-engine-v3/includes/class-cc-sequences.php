<?php
if (!defined('ABSPATH')) exit;

class CC_Sequences {

    private $steps = [
        ['step' => 1, 'delay_hours' => 2,   'name' => 'Warm Intro'],
        ['step' => 2, 'delay_hours' => 48,  'name' => 'Value Pitch'],
        ['step' => 3, 'delay_hours' => 120, 'name' => 'Social Proof'],
        ['step' => 4, 'delay_hours' => 240, 'name' => 'Last Chance'],
    ];

    public function run() {
        if (get_option('ptp_cc_auto_followup_enabled', 'yes') !== 'yes') return;

        // ── Mutex lock: prevent overlapping cron runs ──
        $lock_key = 'ptp_cc_seq_lock';
        if (get_transient($lock_key)) {
            error_log('[PTP-CC Sequences] Skipped: another run is still active');
            return;
        }
        set_transient($lock_key, time(), 10 * MINUTE_IN_SECONDS);

        global $wpdb;
        $at = CC_DB::apps();
        $fu = CC_DB::follow_ups();
        $mt = CC_DB::op_msgs();

        // Get all active applications that might need follow-up
        $apps = $wpdb->get_results("
            SELECT a.*,
                (SELECT COUNT(*) FROM $fu f WHERE f.app_id=a.id) as fu_count,
                (SELECT MAX(sent_at) FROM $fu f WHERE f.app_id=a.id) as last_fu_at,
                TIMESTAMPDIFF(HOUR, COALESCE(a.accepted_at, a.created_at), NOW()) as hours_since
            FROM $at a
            WHERE a.status IN('pending','accepted','contacted')
            AND a.phone != '' AND a.phone IS NOT NULL
        ");

        $sent = 0;
        $max_per_run = (int)get_option('ptp_cc_max_followups_per_run', 20);
        foreach ($apps as $app) {
            if ($sent >= $max_per_run) {
                error_log("[PTP-CC Sequences] Hit per-run cap ($max_per_run), stopping.");
                break;
            }
            if ($this->process_app($app, $wpdb, $mt)) $sent++;
        }

        error_log("[PTP-CC Sequences] Processed " . count($apps) . " apps, sent $sent messages");

        // Release lock
        delete_transient($lock_key);
    }

    private function process_app($app, $wpdb, $mt) {
        $step_done = (int)$app->fu_count;
        $hours = (int)$app->hours_since;

        // Check if parent responded since last follow-up
        if ($app->last_fu_at) {
            $resp = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $mt WHERE app_id=%d AND direction='incoming' AND created_at > %s",
                $app->id, $app->last_fu_at
            ));
            if ($resp > 0) return false; // Parent responded, skip auto
        }

        // Find next step
        $next = null;
        foreach ($this->steps as $s) {
            if ($s['step'] === $step_done + 1) { $next = $s; break; }
        }

        // All steps done -- mark lost after 14 days
        if (!$next) {
            if ($step_done >= count($this->steps) && $hours > 336) {
                if (!in_array($app->status, ['converted', 'lost'])) {
                    $wpdb->update(CC_DB::apps(), ['status' => 'lost'], ['id' => $app->id]);
                    $wpdb->insert(CC_DB::seg_hist(), [
                        'app_id' => $app->id, 'old_value' => $app->status,
                        'new_value' => 'lost', 'reason' => 'Auto: no response after full sequence',
                    ]);
                }
            }
            return false;
        }

        // Not time yet
        if ($hours < $next['delay_hours']) return false;

        // Business hours (configurable, default 8 AM - 9 PM ET)
        $tz = new DateTimeZone('America/New_York');
        $now = new DateTime('now', $tz);
        $hour = (int)$now->format('G');
        $biz_start = (int)get_option('ptp_cc_biz_hour_start', 8);
        $biz_end   = (int)get_option('ptp_cc_biz_hour_end', 21);
        if ($hour < $biz_start || $hour >= $biz_end) return false;

        // Generate message
        $msg = $this->generate($next['step'], $app);
        $require_approval = get_option('ptp_cc_followup_require_approval', 'no') === 'yes';

        if ($require_approval) {
            $wpdb->insert(CC_DB::drafts(), [
                'app_id' => $app->id, 'phone' => $app->phone,
                'draft_body' => $msg, 'intent' => 'sequence_step_' . $next['step'],
                'status' => 'pending',
            ]);
        } else {
            $result = CC_DB::send_sms($app->phone, $msg);
            if (is_wp_error($result)) {
                error_log('[PTP-CC] Sequence SMS failed for app ' . $app->id . ': ' . $result->get_error_message());
                return false;
            }
            // Log outgoing
            $wpdb->insert(CC_DB::op_msgs(), [
                'app_id' => $app->id, 'phone' => CC_DB::normalize_phone($app->phone),
                'direction' => 'outgoing', 'body' => $msg,
            ]);
        }

        // Log follow-up
        $wpdb->insert(CC_DB::follow_ups(), [
            'app_id' => $app->id, 'type' => 'auto_sequence',
            'method' => $require_approval ? 'draft' : 'sms',
            'body' => $msg, 'sent_at' => current_time('mysql'),
            'step_number' => $next['step'],
        ]);

        // Move to contacted if still pending
        if ($app->status === 'pending') {
            $wpdb->update(CC_DB::apps(), ['status' => 'contacted'], ['id' => $app->id]);
        }

        CC_DB::log('sequence_sent', 'application', $app->id, "Step {$next['step']}: {$next['name']}", 'cron');
        error_log("[PTP-CC] Sent step {$next['step']} to {$app->parent_name} ({$app->phone})");
        return true;
    }

    private function generate($step, $app) {
        $name = explode(' ', $app->parent_name)[0] ?: 'there';
        $child = $app->child_name ?: 'your player';
        $trainer = $app->trainer_name ?: 'one of our coaches';

        switch ($step) {
            case 1:
                $msg = "Hey {$name}! Thanks for signing up for a free session with PTP for {$child}.";
                if ($app->trainer_name) $msg .= " We've matched you with {$trainer} — they're going to love working together.";
                $msg .= " I'll be reaching out shortly to find a time that works. Is there a best day/time for you?";
                return $msg;

            case 2:
                return "Hi {$name}! Just following up about {$child}'s free training session with PTP. Our coaches are current MLS players and D1 athletes who actually play with kids during sessions — no boring drill lines. Want to lock in a time this week? Reply with your availability and I'll get {$child} on the schedule!";

            case 3:
                return "Hey {$name} — quick update: we have 500+ families across PA, NJ, and DE training with PTP right now, rated 4.9 stars. Parents love that their kids get real 1-on-1 attention from pro-level coaches." . ($app->trainer_name ? " {$trainer} still has spots open this week." : "") . " Want me to book {$child}'s free session?";

            case 4:
                return "Hi {$name}, just checking in one last time about {$child}'s free session! The offer is always open whenever the timing is right. Just text us back anytime and we'll get you set up. No pressure at all — we're here when you're ready!";

            default:
                return "Hey {$name}, following up about {$child}'s free session with PTP. Let us know when works!";
        }
    }
}
