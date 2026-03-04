<?php
/**
 * PTP Command Center — Training Platform Bridge v2
 * Audited against ptp-training-platform-v138 + ptp-camps-v18.8.1 source.
 * @since 6.2
 */
if (!defined('ABSPATH')) exit;

class CC_Bridge {

    public static function init() {

        // ── FREE SESSION PIPELINE ──
        add_action('ptp_free_session_applied', [__CLASS__, 'on_free_session_submitted'], 10, 2);
        add_action('ptp_free_session_accepted', [__CLASS__, 'on_free_session_accepted'], 10, 3);
        add_action('ptp_free_session_call_completed', [__CLASS__, 'on_free_session_call_completed'], 10, 2);
        add_action('ptp_free_session_call_status_changed', [__CLASS__, 'on_call_status_changed'], 10, 3);
        add_action('ptp_free_session_conversion', [__CLASS__, 'on_free_session_conversion'], 10, 2);
        add_action('ptp_free_session_fully_converted', [__CLASS__, 'on_free_session_fully_converted'], 10, 2);

        // ── TRAINING BOOKINGS ──
        // TP fires ptp_booking_confirmed (5 files), NOT ptp_booking_paid
        add_action('ptp_booking_created', [__CLASS__, 'on_booking_created'], 10, 2);
        add_action('ptp_booking_confirmed', [__CLASS__, 'on_booking_confirmed'], 10, 1);
        add_action('ptp_booking_completed', [__CLASS__, 'on_booking_completed'], 10, 1);
        add_action('ptp_booking_ready_for_payout', [__CLASS__, 'on_booking_payout_ready'], 10, 1);
        add_action('ptp_training_booked', [__CLASS__, 'on_training_booked'], 10, 2);
        add_action('ptp_training_booking_created', [__CLASS__, 'on_training_booking_created'], 10, 2);
        add_action('ptp_recurring_booking_created', [__CLASS__, 'on_recurring_booking_created'], 10, 2);

        // ── PAYMENT / CHECKOUT ──
        add_action('ptp_checkout_completed', [__CLASS__, 'on_checkout_completed'], 10, 2);
        add_action('ptp_payment_complete', [__CLASS__, 'on_payment_complete'], 10, 2);

        // ── CAMP ORDERS ──
        // TP fires ptp_camp_payment_succeeded, NOT ptp_camp_order_paid
        add_action('ptp_camp_order_completed', [__CLASS__, 'on_camp_order_completed'], 10, 1);
        add_action('ptp_camp_order_created', [__CLASS__, 'on_camp_order_created'], 10, 2);
        add_action('ptp_camp_payment_succeeded', [__CLASS__, 'on_camp_payment_succeeded'], 10, 1);
        add_action('ptp_camp_checkout_completed', [__CLASS__, 'on_camp_checkout_completed'], 10, 2);
        add_action('ptp_camp_confirmation_sent', [__CLASS__, 'on_camp_confirmation_sent'], 10, 2);
        add_action('ptp_camp_charge_refunded', [__CLASS__, 'on_camp_refunded'], 10, 1);
        add_action('ptp_camp_crosssell_converted', [__CLASS__, 'on_camp_crosssell_converted'], 10, 2);
        add_action('ptp_camp_booking_recorded', [__CLASS__, 'on_camp_booking_recorded'], 10, 2);

        // ── ABANDONED CARTS ──
        add_action('ptp_camp_cart_abandoned', [__CLASS__, 'on_camp_cart_abandoned'], 10, 1);
        add_action('ptp_camp_cart_recovered', [__CLASS__, 'on_camp_cart_recovered'], 10, 1);
        add_action('ptp_camp_abandonment_email_sent', [__CLASS__, 'on_camp_abandonment_email_sent'], 10, 1);

        // ── STRIPE CSV IMPORT ──
        add_action('ptp_stripe_payment_imported', [__CLASS__, 'on_stripe_payment_imported'], 10, 1);

        // ── SESSION LIFECYCLE ──
        add_action('ptp_session_completed', [__CLASS__, 'on_session_completed'], 10, 2);
        add_action('ptp_session_reminder', [__CLASS__, 'on_session_reminder'], 10, 2);

        // ── SUBSCRIPTIONS ──
        add_action('ptp_subscription_created', [__CLASS__, 'on_subscription_event'], 10, 2);
        add_action('ptp_subscription_cancelled', [__CLASS__, 'on_subscription_event'], 10, 2);
        add_action('ptp_subscription_paused', [__CLASS__, 'on_subscription_event'], 10, 2);
        add_action('ptp_subscription_resumed', [__CLASS__, 'on_subscription_event'], 10, 2);
        add_action('ptp_subscription_payment_succeeded', [__CLASS__, 'on_subscription_event'], 10, 2);

        // ── MENTORSHIP LIFECYCLE ──
        add_action('ptp_mentorship_interest_submitted', [__CLASS__, 'on_mentorship_interest'], 10, 2);
        add_action('ptp_mentorship_intro_completed', [__CLASS__, 'on_mentorship_simple'], 10, 2);
        add_action('ptp_mentorship_package_purchased', [__CLASS__, 'on_mentorship_purchased'], 10, 2);
        add_action('ptp_mentorship_session_recap_ready', [__CLASS__, 'on_mentorship_simple'], 10, 2);
        add_action('ptp_mentorship_cancelled', [__CLASS__, 'on_mentorship_simple'], 10, 2);
        add_action('ptp_mentorship_package_completed', [__CLASS__, 'on_mentorship_simple'], 10, 2);
        add_action('ptp_mentorship_session_scheduled', [__CLASS__, 'on_mentorship_simple'], 10, 2);
        add_action('ptp_mentorship_video_submitted', [__CLASS__, 'on_mentorship_simple'], 10, 2);
        add_action('ptp_mentorship_video_reviewed', [__CLASS__, 'on_mentorship_simple'], 10, 2);
        add_action('ptp_mentorship_training_upsell', [__CLASS__, 'on_mentorship_simple'], 10, 2);
        add_action('ptp_mentorship_post_camp_outreach', [__CLASS__, 'on_mentorship_simple'], 10, 2);
        add_action('ptp_mentorship_payment_failed', [__CLASS__, 'on_mentorship_simple'], 10, 2);

        // ── TRAINER EVENTS ──
        add_action('ptp_trainer_approved', [__CLASS__, 'on_trainer_approved'], 10, 1);
        add_action('ptp_trainer_first_booking', [__CLASS__, 'on_trainer_first_booking'], 10, 1);

        // ── SMS CROSS-LOGGING ──
        add_action('ptp_sms_sent', [__CLASS__, 'on_tp_sms_sent'], 10, 3);
        add_action('ptp_sms_received', [__CLASS__, 'on_tp_sms_received'], 10, 3);
        add_action('ptp_openphone_incoming_for_chatbot', [__CLASS__, 'forward_to_chatbot'], 10, 3);

        // ── OPENPHONE EVENTS ──
        add_action('ptp_openphone_call', [__CLASS__, 'on_openphone_event'], 10, 2);
        add_action('ptp_op_lead_sms_received', [__CLASS__, 'on_openphone_event'], 10, 2);
        add_action('ptp_op_lead_call_completed', [__CLASS__, 'on_openphone_event'], 10, 2);
    }

    // ═══════════════ FREE SESSION ═══════════════

    public static function on_free_session_submitted($app_id, $app) {
        CC_DB::log('free_session_submitted', 'application', $app_id,
            self::p($app,'parent_name').' applied for free session for '.self::p($app,'child_name'), 'webhook');
    }

    public static function on_free_session_accepted($app_id, $app, $trainer_slug = '') {
        CC_DB::log('trainer_sent_to_parent', 'application', $app_id,
            "Trainer {$trainer_slug} sent to ".self::p($app,'parent_name'), 'admin');
        self::set_status($app_id, 'accepted', 'trainer sent to parent');
    }

    public static function on_free_session_call_completed($app_id, $app) {
        CC_DB::log('intro_call_completed', 'application', $app_id,
            "Intro call completed with ".self::p($app,'parent_name'), 'admin');
        global $wpdb;
        $wpdb->insert(CC_DB::follow_ups(), [
            'app_id'=>$app_id,'type'=>'call_completed','method'=>'call',
            'body'=>"Intro call completed".(self::p($app,'call_notes')?': '.self::p($app,'call_notes'):''),
            'sent_at'=>current_time('mysql'),
        ]);
    }

    public static function on_call_status_changed($app_id, $old, $new) {
        CC_DB::log('call_status_changed', 'application', $app_id, "Call: {$old} → {$new}", 'admin');
    }

    public static function on_free_session_conversion($app_id, $data = null) {
        CC_DB::log('free_session_conversion', 'application', $app_id, 'Free→paid conversion', 'system');
        self::set_status($app_id, 'converted', 'Free session conversion');
    }

    public static function on_free_session_fully_converted($app_id, $data = null) {
        CC_DB::log('free_session_fully_converted', 'application', $app_id, 'Full pipeline conversion', 'system');
        self::set_status($app_id, 'converted', 'Full conversion');
    }

    // ═══════════════ BOOKINGS ═══════════════

    public static function on_booking_created($booking_id, $booking = null) {
        $d = 'Training booking created';
        if (is_object($booking)) {
            if (!empty($booking->total_amount)) $d .= " — \${$booking->total_amount}";
            if (!empty($booking->trainer_name)) $d .= " with {$booking->trainer_name}";
        }
        CC_DB::log('booking_created', 'booking', $booking_id, $d, 'system');
    }

    /** The REAL payment hook — TP fires this, NOT ptp_booking_paid */
    public static function on_booking_confirmed($booking_id) {
        CC_DB::log('booking_confirmed', 'booking', $booking_id, "Booking #{$booking_id} confirmed & paid", 'stripe');
        self::convert_from_booking($booking_id);
    }

    public static function on_booking_completed($booking_id) {
        CC_DB::log('booking_completed', 'booking', $booking_id, "Session #{$booking_id} completed", 'system');
    }

    public static function on_booking_payout_ready($booking_id) {
        CC_DB::log('booking_payout_ready', 'booking', $booking_id, "#{$booking_id} ready for payout", 'system');
    }

    public static function on_training_booked($booking_id, $d = null) {
        CC_DB::log('training_booked', 'booking', $booking_id, 'Training session booked', 'system');
    }

    public static function on_training_booking_created($booking_id, $d = null) {
        CC_DB::log('training_booking_created', 'booking', $booking_id, 'Training booking created', 'system');
    }

    public static function on_recurring_booking_created($booking_id, $d = null) {
        CC_DB::log('recurring_booking_created', 'booking', $booking_id, 'Recurring session created', 'system');
    }

    // ═══════════════ CHECKOUT / PAYMENT ═══════════════

    public static function on_checkout_completed($order_id, $d = null) {
        $amt = is_object($d) ? ($d->total_amount ?? '') : '';
        CC_DB::log('checkout_completed', 'order', $order_id, 'Checkout completed'.($amt?" — \${$amt}":''), 'stripe');
    }

    public static function on_payment_complete($order_id, $d = null) {
        CC_DB::log('payment_complete', 'order', $order_id, 'Payment completed', 'stripe');
    }

    // ═══════════════ CAMP ORDERS ═══════════════

    public static function on_camp_order_completed($order_id) {
        CC_DB::log('camp_order_completed', 'camp_order', $order_id, "Camp order #{$order_id} completed", 'stripe');
        if (class_exists('CC_Stripe_Listener')) CC_Stripe_Listener::on_camp_order_completed($order_id);
    }

    public static function on_camp_order_created($order_id, $d = null) {
        CC_DB::log('camp_order_created', 'camp_order', $order_id, "Camp order #{$order_id} created", 'system');
    }

    /** The REAL camp payment hook — TP fires this, NOT ptp_camp_order_paid */
    public static function on_camp_payment_succeeded($order_id) {
        CC_DB::log('camp_payment_succeeded', 'camp_order', $order_id, "Camp payment succeeded #{$order_id}", 'stripe');
        self::convert_from_camp($order_id);
    }

    public static function on_camp_checkout_completed($order_id, $d = null) {
        CC_DB::log('camp_checkout_completed', 'camp_order', $order_id, 'Camp checkout completed', 'stripe');
    }

    public static function on_camp_confirmation_sent($order_id, $d = null) {
        CC_DB::log('camp_confirmation_sent', 'camp_order', $order_id, 'Camp confirmation email sent', 'system');
    }

    public static function on_camp_refunded($charge) {
        $oid = is_array($charge) ? ($charge['order_id'] ?? 0) : 0;
        CC_DB::log('camp_refunded', 'camp_order', $oid, 'Camp order refunded', 'stripe');
    }

    public static function on_camp_crosssell_converted($d, $ctx = null) {
        $id = is_object($d) ? ($d->id ?? 0) : (is_array($d) ? ($d['id'] ?? 0) : 0);
        CC_DB::log('camp_crosssell_converted', 'camp_order', $id, 'Cross-sell: training→camp upsell', 'system');
    }

    public static function on_camp_booking_recorded($booking_id, $d = null) {
        CC_DB::log('camp_booking_recorded', 'camp_booking', $booking_id, "Camp booking #{$booking_id} recorded", 'system');
    }

    // ═══════════════ SESSION LIFECYCLE ═══════════════

    public static function on_session_completed($session_id, $d = null) {
        CC_DB::log('session_completed', 'session', $session_id, "Session #{$session_id} completed", 'system');
    }

    public static function on_session_reminder($session_id, $d = null) {
        CC_DB::log('session_reminder', 'session', $session_id, 'Session reminder sent', 'system');
    }

    // ═══════════════ SUBSCRIPTIONS ═══════════════

    public static function on_subscription_event($sub_id, $d = null) {
        $hook = current_action();
        $event = str_replace('ptp_subscription_', '', $hook);
        $source = strpos($event, 'payment') !== false ? 'stripe' : 'system';
        CC_DB::log('subscription_' . $event, 'subscription', $sub_id, "Subscription {$event}", $source);
    }

    // ═══════════════ MENTORSHIP ═══════════════

    public static function on_mentorship_interest($pair_id, $d = null) {
        CC_DB::log('mentorship_interest', 'mentorship', $pair_id, 'Mentorship interest submitted', 'webhook');
        self::link_mentorship($pair_id, $d);
    }

    public static function on_mentorship_purchased($pair_id, $d = null) {
        $pkg = is_object($d) && isset($d->package_type) ? ": {$d->package_type}" : '';
        CC_DB::log('mentorship_purchased', 'mentorship', $pair_id, "Mentorship purchased{$pkg}", 'stripe');
        self::convert_from_mentorship($pair_id, $d);
    }

    /** Generic handler for all mentorship lifecycle events */
    public static function on_mentorship_simple($pair_id, $d = null) {
        $hook = current_action();
        $event = str_replace('ptp_mentorship_', '', $hook);
        $label = ucwords(str_replace('_', ' ', $event));
        $source = strpos($event, 'payment') !== false ? 'stripe' : 'system';
        CC_DB::log('mentorship_' . $event, 'mentorship', $pair_id, "Mentorship: {$label}", $source);
    }

    // ═══════════════ TRAINER ═══════════════

    public static function on_trainer_approved($trainer_id) {
        global $wpdb;
        $name = $wpdb->get_var($wpdb->prepare(
            "SELECT display_name FROM " . CC_DB::trainers() . " WHERE id=%d", $trainer_id));
        CC_DB::log('trainer_approved', 'trainer', $trainer_id, "Trainer approved: ".($name?:"#{$trainer_id}"), 'admin');
    }

    public static function on_trainer_first_booking($trainer_id) {
        CC_DB::log('trainer_first_booking', 'trainer', $trainer_id, 'First booking received', 'system');
    }

    // ═══════════════ SMS CROSS-LOGGING ═══════════════

    public static function on_tp_sms_sent($phone, $message, $result = null) {
        if (is_wp_error($result)) return;
        global $wpdb;
        $ph = CC_DB::normalize_phone($phone);
        $dup = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM ".CC_DB::op_msgs()." WHERE phone=%s AND direction='outgoing' AND body=%s AND created_at > DATE_SUB(NOW(), INTERVAL 5 SECOND)",
            $ph, $message));
        if ($dup) return;
        list($aid, $pid) = self::match_phone($ph);
        $wpdb->insert(CC_DB::op_msgs(), ['app_id'=>$aid,'parent_id'=>$pid,'phone'=>$ph,'direction'=>'outgoing','body'=>$message]);
    }

    public static function on_tp_sms_received($phone, $message, $data = null) {
        global $wpdb;
        $ph = CC_DB::normalize_phone($phone);
        $dup = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM ".CC_DB::op_msgs()." WHERE phone=%s AND direction='incoming' AND body=%s AND created_at > DATE_SUB(NOW(), INTERVAL 30 SECOND)",
            $ph, $message));
        if ($dup) return;
        list($aid, $pid) = self::match_phone($ph);
        $wpdb->insert(CC_DB::op_msgs(), ['app_id'=>$aid,'parent_id'=>$pid,'phone'=>$ph,'direction'=>'incoming','body'=>$message]);
    }

    public static function forward_to_chatbot($phone, $message, $raw = []) {
        if (!class_exists('PTP_Chatbot_API') || !method_exists('PTP_Chatbot_API','handle_incoming_sms')) return;
        try { PTP_Chatbot_API::handle_incoming_sms($phone, $message, $raw); }
        catch (\Exception $e) { error_log('[PTP-CC Bridge] Chatbot forward failed: '.$e->getMessage()); }
    }

    // ═══════════════ ABANDONED CARTS ═══════════════

    public static function on_camp_cart_abandoned($data) {
        if (!is_array($data)) $data = (array)$data;
        $name  = $data['name'] ?? '';
        $email = $data['email'] ?? '';
        $total = $data['cart_total'] ?? 0;
        $camps = $data['camp_names'] ?? '';

        CC_DB::log('camp_cart_abandoned', 'abandoned_cart', (int)($data['id'] ?? 0),
            "Abandoned cart: {$name} ({$email}) — \${$total} — {$camps}", 'system');

        // Match to existing application and update lead scoring
        if ($email || ($data['phone'] ?? '')) {
            self::convert_by_contact_status($email, $data['phone'] ?? '', 'contacted', 'Abandoned camp cart');
        }

        // Sync to OpenPhone
        if (class_exists('CC_OpenPhone_Sync') && !empty($data['phone'])) {
            CC_OpenPhone_Sync::on_abandoned_cart($data);
        }
    }

    public static function on_camp_cart_recovered($data) {
        if (!is_array($data)) $data = (array)$data;
        $email = $data['email'] ?? '';
        $pi_id = $data['stripe_pi_id'] ?? '';

        CC_DB::log('camp_cart_recovered', 'abandoned_cart', 0,
            "Cart recovered: {$email}" . ($pi_id ? " (PI: {$pi_id})" : ''), 'stripe');

        // Mark the application as converted
        if ($email) {
            self::convert_by_contact($email, '', "Abandoned cart recovered");
        }
    }

    public static function on_camp_abandonment_email_sent($data) {
        if (!is_array($data)) $data = (array)$data;
        $num   = $data['email_num'] ?? 0;
        $email = $data['email'] ?? '';
        $subj  = $data['subject'] ?? '';
        $label = ['', 'Cart saved', 'Spots filling up', 'Last call'][$num] ?? "Email #{$num}";

        CC_DB::log('camp_abandonment_email', 'abandoned_cart', (int)($data['cart_id'] ?? 0),
            "Recovery email #{$num} ({$label}) sent to {$email}: {$subj}", 'system');

        // Log as a follow-up on the matched application so it shows in the contact timeline
        $app = self::find_app_by_email($email);
        if ($app) {
            global $wpdb;
            $wpdb->insert(CC_DB::follow_ups(), [
                'app_id'  => $app->id,
                'type'    => 'abandonment_email',
                'method'  => 'email',
                'body'    => "Recovery email #{$num} ({$label}): {$subj}",
                'sent_at' => current_time('mysql'),
            ]);
        }
    }

    // ═══════════════ STRIPE CSV IMPORT ═══════════════

    public static function on_stripe_payment_imported($data) {
        if (!is_array($data)) $data = (array)$data;
        $status = $data['status'] ?? '';
        $amount = $data['amount'] ?? 0;
        $email  = $data['email'] ?? '';
        $name   = $data['name'] ?? '';
        $desc   = $data['description'] ?? '';
        $camps  = $data['has_camps'] ?? false;
        $type   = $camps ? 'camp' : 'training';

        if ($status === 'paid') {
            CC_DB::log('stripe_payment_imported', $type . '_payment', (int)($data['payment_id'] ?? 0),
                "Paid: {$name} ({$email}) — \${$amount} — {$desc}", 'csv_import');

            // Convert matching application
            if ($email) {
                self::convert_by_contact($email, $data['phone'] ?? '', "Stripe CSV import: paid \${$amount}");
            }

            // Sync to OpenPhone if phone present
            if (!empty($data['phone']) && class_exists('CC_OpenPhone_Sync')) {
                $parts = explode(' ', $name, 2);
                CC_OpenPhone_Sync::sync_contact([
                    'phone'      => $data['phone'],
                    'first_name' => $parts[0] ?? '',
                    'last_name'  => $parts[1] ?? '',
                    'email'      => $email,
                    'status'     => $camps ? 'Camp Booked' : 'Training Booked',
                ]);
            }
        } elseif ($status === 'incomplete' && $email) {
            // Only log if we have an email — anonymous incompletes are noise
            CC_DB::log('stripe_incomplete_imported', 'abandoned_payment', (int)($data['payment_id'] ?? 0),
                "Incomplete: {$name} ({$email}) — \${$amount} — {$desc}", 'csv_import');
        }
    }

    // ═══════════════ OPENPHONE EVENTS ═══════════════

    public static function on_openphone_event($data, $ctx = null) {
        $hook = current_action();
        $event = str_replace('ptp_', '', $hook);
        $label = ucwords(str_replace(['op_','_'], ['OpenPhone ',' '], $event));
        CC_DB::log($event, 'openphone', 0, $label, 'openphone');
    }

    // ═══════════════ HELPERS ═══════════════

    private static function p($d, $k) {
        if (is_object($d)) return $d->$k ?? '';
        if (is_array($d)) return $d[$k] ?? '';
        return '';
    }

    private static function match_phone($phone) {
        global $wpdb;
        $s = substr(preg_replace('/\D/', '', $phone), -10);
        if (!$s) return [null, null];
        $aid = $wpdb->get_var($wpdb->prepare("SELECT id FROM ".CC_DB::apps()." WHERE phone LIKE %s ORDER BY created_at DESC LIMIT 1", '%'.$s));
        $pid = $wpdb->get_var($wpdb->prepare("SELECT id FROM ".CC_DB::parents()." WHERE phone LIKE %s LIMIT 1", '%'.$s));
        return [$aid?(int)$aid:null, $pid?(int)$pid:null];
    }

    private static function set_status($app_id, $new, $reason) {
        global $wpdb;
        $cur = $wpdb->get_var($wpdb->prepare("SELECT status FROM ".CC_DB::apps()." WHERE id=%d", $app_id));
        if (!$cur || in_array($cur, ['converted','booked'])) return;
        $wpdb->update(CC_DB::apps(), ['status'=>$new], ['id'=>$app_id]);
        $wpdb->insert(CC_DB::seg_hist(), ['app_id'=>$app_id,'old_value'=>$cur,'new_value'=>$new,'reason'=>'Bridge: '.$reason]);
    }

    private static function convert_by_contact($email, $phone, $reason) {
        global $wpdb; $at = CC_DB::apps(); $app = null;
        if ($email) $app = $wpdb->get_row($wpdb->prepare("SELECT id,status FROM $at WHERE email=%s AND status!='converted' ORDER BY created_at DESC LIMIT 1", $email));
        if (!$app && $phone) { $s=substr(preg_replace('/\D/','',$phone),-10); if($s) $app=$wpdb->get_row($wpdb->prepare("SELECT id,status FROM $at WHERE phone LIKE %s AND status!='converted' ORDER BY created_at DESC LIMIT 1",'%'.$s)); }
        if ($app) { $wpdb->update($at,['status'=>'converted'],['id'=>$app->id]); $wpdb->insert(CC_DB::seg_hist(),['app_id'=>$app->id,'old_value'=>$app->status,'new_value'=>'converted','reason'=>'Auto: '.$reason]); }
    }

    /**
     * Like convert_by_contact but sets a specific status (e.g. 'contacted') instead of 'converted'.
     * Used for abandoned carts — they're engaged but haven't paid yet.
     */
    private static function convert_by_contact_status($email, $phone, $new_status, $reason) {
        global $wpdb;
        $at = CC_DB::apps();
        $app = null;
        if ($email) $app = $wpdb->get_row($wpdb->prepare("SELECT id,status FROM $at WHERE email=%s ORDER BY created_at DESC LIMIT 1", $email));
        if (!$app && $phone) { $s=substr(preg_replace('/\D/','',$phone),-10); if($s) $app=$wpdb->get_row($wpdb->prepare("SELECT id,status FROM $at WHERE phone LIKE %s ORDER BY created_at DESC LIMIT 1",'%'.$s)); }
        if ($app && !in_array($app->status, ['converted','booked'])) {
            $wpdb->update($at, ['status' => $new_status], ['id' => $app->id]);
            $wpdb->insert(CC_DB::seg_hist(), ['app_id'=>$app->id, 'old_value'=>$app->status, 'new_value'=>$new_status, 'reason'=>'Auto: '.$reason]);
        }
    }

    private static function find_app_by_email($email) {
        if (!$email) return null;
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM " . CC_DB::apps() . " WHERE email=%s ORDER BY created_at DESC LIMIT 1",
            strtolower($email)
        ));
    }

    private static function convert_from_booking($booking_id) {
        global $wpdb;
        $b = $wpdb->get_row($wpdb->prepare("SELECT parent_id FROM ".CC_DB::bookings()." WHERE id=%d", $booking_id));
        if (!$b || !$b->parent_id) return;
        $p = $wpdb->get_row($wpdb->prepare("SELECT email,phone FROM ".CC_DB::parents()." WHERE id=%d", $b->parent_id));
        if ($p) self::convert_by_contact($p->email, $p->phone, "Booking #{$booking_id} confirmed");
    }

    private static function convert_from_camp($order_id) {
        global $wpdb;
        $co = CC_DB::camp_orders();
        $email = $wpdb->get_var($wpdb->prepare("SELECT billing_email FROM $co WHERE id=%d", $order_id));
        if (!$email) {
            $cb = CC_DB::camp_bookings();
            if ($wpdb->get_var("SHOW TABLES LIKE '$cb'") === $cb)
                $email = $wpdb->get_var($wpdb->prepare("SELECT customer_email FROM $cb WHERE id=%d", $order_id));
        }
        if ($email) self::convert_by_contact($email, '', "Camp order #{$order_id} paid");
    }

    private static function convert_from_mentorship($pair_id, $d) {
        if (!$d) return;
        $pid = self::p($d, 'parent_id'); if (!$pid) return;
        global $wpdb;
        $p = $wpdb->get_row($wpdb->prepare("SELECT email,phone FROM ".CC_DB::parents()." WHERE id=%d", $pid));
        if ($p) self::convert_by_contact($p->email, $p->phone, "Mentorship #{$pair_id} purchased");
    }

    private static function link_mentorship($pair_id, $d) {
        if (!$d) return;
        $email = self::p($d,'parent_email') ?: self::p($d,'email');
        $phone = self::p($d,'parent_phone') ?: self::p($d,'phone');
        global $wpdb; $at = CC_DB::apps(); $app = null;
        if ($email) $app = $wpdb->get_row($wpdb->prepare("SELECT id FROM $at WHERE email=%s ORDER BY created_at DESC LIMIT 1", $email));
        if (!$app && $phone) { $s=substr(preg_replace('/\D/','',$phone),-10); if($s) $app=$wpdb->get_row($wpdb->prepare("SELECT id FROM $at WHERE phone LIKE %s ORDER BY created_at DESC LIMIT 1",'%'.$s)); }
        if ($app) CC_DB::log('mentorship_linked', 'application', $app->id, "Linked to mentorship #{$pair_id}", 'system');
    }
}
