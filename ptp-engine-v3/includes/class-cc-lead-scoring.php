<?php
/**
 * PTP Command Center — Lead Scoring Engine
 * Runs hourly via WP Cron. Calculates lead temperature (hot/warm/cold)
 * based on engagement signals, recency, and profile completeness.
 *
 * Optimized: bulk pre-loads cross-platform data to avoid N+1 queries.
 */
if (!defined('ABSPATH')) exit;

class CC_Lead_Scoring {

    /**
     * Score all active leads and update lead_temperature.
     */
    public static function run() {
        global $wpdb;
        $at = CC_DB::apps();
        $fu = CC_DB::follow_ups();
        $mt = CC_DB::op_msgs();

        $apps = $wpdb->get_results("
            SELECT a.*,
                (SELECT COUNT(*) FROM $fu f WHERE f.app_id=a.id) as fu_count,
                (SELECT COUNT(*) FROM $mt m WHERE m.app_id=a.id AND m.direction='incoming') as inbound_msgs,
                (SELECT MAX(m.created_at) FROM $mt m WHERE m.app_id=a.id AND m.direction='incoming') as last_inbound,
                TIMESTAMPDIFF(HOUR, a.created_at, NOW()) as hours_since_apply
            FROM $at a
            WHERE a.status NOT IN('converted','lost')
        ");

        if (!$apps) return;

        // ── Pre-load cross-platform data in bulk (eliminates N+1) ──
        $parent_by_email = [];
        $parent_by_phone = [];
        $parent_rows = $wpdb->get_results("SELECT id, email, phone FROM " . CC_DB::parents());
        foreach ($parent_rows as $p) {
            if ($p->email) $parent_by_email[strtolower($p->email)] = $p->id;
            if ($p->phone) {
                $suffix = substr(preg_replace('/\D/', '', $p->phone), -10);
                if ($suffix) $parent_by_phone[$suffix] = $p->id;
            }
        }

        $booking_counts = [];
        $rows = $wpdb->get_results("SELECT parent_id, COUNT(*) as c FROM " . CC_DB::bookings() . " WHERE payment_status='paid' GROUP BY parent_id");
        foreach ($rows as $r) $booking_counts[(int)$r->parent_id] = (int)$r->c;

        $review_counts = [];
        $rows = $wpdb->get_results("SELECT parent_id, COUNT(*) as c FROM " . CC_DB::reviews() . " GROUP BY parent_id");
        foreach ($rows as $r) $review_counts[(int)$r->parent_id] = (int)$r->c;

        $mentorship_counts = [];
        $mentorship_t = $wpdb->prefix . 'ptp_mentorship_pairs';
        if ($wpdb->get_var("SHOW TABLES LIKE '$mentorship_t'") === $mentorship_t) {
            $rows = $wpdb->get_results("SELECT parent_id, COUNT(*) as c FROM $mentorship_t WHERE status='active' GROUP BY parent_id");
            foreach ($rows as $r) $mentorship_counts[(int)$r->parent_id] = (int)$r->c;
        }

        $camp_order_emails = [];
        $co = CC_DB::camp_orders();
        if ($wpdb->get_var("SHOW TABLES LIKE '$co'") === $co) {
            $rows = $wpdb->get_results("SELECT LOWER(billing_email) as email, COUNT(*) as c FROM $co WHERE payment_status='completed' GROUP BY LOWER(billing_email)");
            foreach ($rows as $r) $camp_order_emails[$r->email] = (int)$r->c;
        }

        $ctx = compact('parent_by_email', 'parent_by_phone', 'booking_counts', 'review_counts', 'mentorship_counts', 'camp_order_emails');

        $updated = 0;
        foreach ($apps as $app) {
            $score = self::calculate($app, $ctx);
            $temp = $score >= 70 ? 'hot' : ($score >= 40 ? 'warm' : 'cold');

            if ($app->lead_temperature !== $temp) {
                $wpdb->update($at, ['lead_temperature' => $temp], ['id' => $app->id]);
                $updated++;
            }
        }

        error_log("[PTP-CC Lead Scoring] Scored " . count($apps) . " leads, updated $updated temperatures");
    }

    /**
     * Calculate lead score 0-100.
     * When $ctx is provided (bulk run), uses pre-loaded data. Otherwise queries per-lead (single scoring).
     */
    private static function calculate($app, $ctx = null) {
        $score = 0;

        // ── Engagement (0-40 pts) ──
        $inbound = (int)$app->inbound_msgs;
        if ($inbound >= 3)      $score += 40;
        elseif ($inbound >= 2)  $score += 30;
        elseif ($inbound >= 1)  $score += 20;

        if ($inbound > 0 && (int)$app->fu_count > 0) $score += 5;

        // ── Recency (0-25 pts) ──
        $hours = (int)$app->hours_since_apply;
        if ($app->last_inbound) {
            $last_hrs = (time() - strtotime($app->last_inbound)) / 3600;
            if ($last_hrs < 2)        $score += 25;
            elseif ($last_hrs < 24)   $score += 20;
            elseif ($last_hrs < 72)   $score += 12;
            elseif ($last_hrs < 168)  $score += 5;
        } else {
            if ($hours < 24)          $score += 15;
            elseif ($hours < 72)      $score += 8;
            elseif ($hours < 168)     $score += 3;
        }

        // ── Profile completeness (0-15 pts) ──
        if ($app->child_name)           $score += 2;
        if ($app->child_age)            $score += 2;
        if ($app->club)                 $score += 3;
        if ($app->position)             $score += 2;
        if ($app->biggest_challenge)    $score += 3;
        if ($app->goal)                 $score += 3;

        // ── Pipeline stage (0-15 pts) ──
        switch ($app->status) {
            case 'booked':    $score += 15; break;
            case 'accepted':  $score += 10; break;
            case 'contacted': $score += 5;  break;
            case 'pending':   $score += 0;  break;
        }

        // ── Cross-platform engagement (0-25 bonus pts) ──
        if ($ctx) {
            // Bulk mode: use pre-loaded lookup maps (0 queries per lead)
            $parent_id = null;
            if (!empty($app->email)) {
                $parent_id = $ctx['parent_by_email'][strtolower($app->email)] ?? null;
            }
            if (!$parent_id && !empty($app->phone)) {
                $suffix = substr(preg_replace('/\D/', '', $app->phone), -10);
                if ($suffix) $parent_id = $ctx['parent_by_phone'][$suffix] ?? null;
            }

            if ($parent_id) {
                $bookings = $ctx['booking_counts'][$parent_id] ?? 0;
                if ($bookings >= 3)      $score += 15;
                elseif ($bookings >= 1)  $score += 10;

                if (($ctx['review_counts'][$parent_id] ?? 0) > 0) $score += 5;
                if (($ctx['mentorship_counts'][$parent_id] ?? 0) > 0) $score += 10;
            }

            if (!empty($app->email) && ($ctx['camp_order_emails'][strtolower($app->email)] ?? 0) > 0) {
                $score += 10;
            }
        } else {
            // Single-lead mode: per-lead queries (used by score_single API)
            global $wpdb;
            $parent_id = null;
            if (!empty($app->email)) {
                $parent_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM " . CC_DB::parents() . " WHERE email=%s LIMIT 1", $app->email
                ));
            }
            if (!$parent_id && !empty($app->phone)) {
                $suffix = substr(preg_replace('/\D/', '', $app->phone), -10);
                if ($suffix) {
                    $parent_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM " . CC_DB::parents() . " WHERE phone LIKE %s LIMIT 1", '%' . $suffix
                    ));
                }
            }
            if ($parent_id) {
                $bookings = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM " . CC_DB::bookings() . " WHERE parent_id=%d AND payment_status='paid'", $parent_id
                ));
                if ($bookings >= 3)      $score += 15;
                elseif ($bookings >= 1)  $score += 10;

                $reviews = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM " . CC_DB::reviews() . " WHERE parent_id=%d", $parent_id
                ));
                if ($reviews > 0) $score += 5;

                $mentorship_t = $wpdb->prefix . 'ptp_mentorship_pairs';
                if ($wpdb->get_var("SHOW TABLES LIKE '$mentorship_t'") === $mentorship_t) {
                    $active = (int)$wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM $mentorship_t WHERE parent_id=%d AND status='active'", $parent_id
                    ));
                    if ($active > 0) $score += 10;
                }
            }
            $co = CC_DB::camp_orders();
            if (!empty($app->email) && $wpdb->get_var("SHOW TABLES LIKE '$co'") === $co) {
                $camp_orders = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $co WHERE billing_email=%s AND payment_status='completed'", $app->email
                ));
                if ($camp_orders > 0) $score += 10;
            }
        }

        // ── Negative signals ──
        if ((int)$app->fu_count >= 4 && $inbound === 0) {
            $score -= 20;
        }
        if ($hours > 720 && $inbound === 0) { // 30 days
            $score -= 15;
        }

        return max(0, min(100, $score));
    }

    /**
     * Score a single lead on-demand (used by API).
     */
    public static function score_single($app_id) {
        global $wpdb;
        $at = CC_DB::apps();
        $fu = CC_DB::follow_ups();
        $mt = CC_DB::op_msgs();

        $app = $wpdb->get_row($wpdb->prepare("
            SELECT a.*,
                (SELECT COUNT(*) FROM $fu f WHERE f.app_id=a.id) as fu_count,
                (SELECT COUNT(*) FROM $mt m WHERE m.app_id=a.id AND m.direction='incoming') as inbound_msgs,
                (SELECT MAX(m.created_at) FROM $mt m WHERE m.app_id=a.id AND m.direction='incoming') as last_inbound,
                TIMESTAMPDIFF(HOUR, a.created_at, NOW()) as hours_since_apply
            FROM $at a WHERE a.id=%d
        ", $app_id));

        if (!$app) return null;

        $score = self::calculate($app);
        $temp = $score >= 70 ? 'hot' : ($score >= 40 ? 'warm' : 'cold');
        $wpdb->update($at, ['lead_temperature' => $temp], ['id' => $app_id]);

        return ['score' => $score, 'temperature' => $temp];
    }
}
