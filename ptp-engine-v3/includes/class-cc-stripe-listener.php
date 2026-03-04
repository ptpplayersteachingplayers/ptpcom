<?php
/**
 * PTP Command Center — Stripe / Booking Listener
 * Auto-converts applications when a booking is paid.
 * Links applications to parents by email/phone matching.
 */
if (!defined('ABSPATH')) exit;

class CC_Stripe_Listener {

    /**
     * Called when a PTP booking is marked paid/completed.
     * Hooks: ptp_booking_confirmed, ptp_booking_completed
     */
    public static function on_booking_paid($booking_id) {
        global $wpdb;
        $bt = CC_DB::bookings();
        $at = CC_DB::apps();
        $pt = CC_DB::parents();

        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM $bt WHERE id=%d", $booking_id));
        if (!$booking) return;

        // Get parent info
        $parent = $wpdb->get_row($wpdb->prepare("SELECT * FROM $pt WHERE id=%d", $booking->parent_id));
        if (!$parent) return;

        // Find matching application by email or phone
        $app = null;
        if ($parent->email) {
            $app = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $at WHERE email=%s AND status != 'converted' ORDER BY created_at DESC LIMIT 1",
                $parent->email
            ));
        }
        if (!$app && $parent->phone) {
            $phone_suffix = substr(preg_replace('/\D/', '', $parent->phone), -10);
            if ($phone_suffix) {
                $app = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $at WHERE phone LIKE %s AND status != 'converted' ORDER BY created_at DESC LIMIT 1",
                    '%' . $phone_suffix
                ));
            }
        }

        if (!$app) return;

        // Convert the application
        $old_status = $app->status;
        $wpdb->update($at, [
            'status' => 'converted',
        ], ['id' => $app->id]);

        // Log status change
        $wpdb->insert(CC_DB::seg_hist(), [
            'app_id'    => $app->id,
            'parent_id' => $parent->id,
            'old_value' => $old_status,
            'new_value' => 'converted',
            'reason'    => "Auto: Booking #{$booking_id} paid (\${$booking->total_amount})",
        ]);

        // Link app to parent
        $wpdb->insert(CC_DB::follow_ups(), [
            'app_id'    => $app->id,
            'parent_id' => $parent->id,
            'booking_id'=> $booking_id,
            'type'      => 'conversion',
            'method'    => 'system',
            'body'      => "Converted: Booking #{$booking_id} paid \${$booking->total_amount} by {$parent->display_name}",
            'sent_at'   => current_time('mysql'),
        ]);

        error_log("[PTP-CC Stripe] Auto-converted app #{$app->id} ({$app->parent_name}) via booking #{$booking_id}");
    }

    /**
     * Also listen for camp order completion to cross-link.
     */
    public static function on_camp_order_completed($order_id) {
        global $wpdb;
        $co = CC_DB::camp_orders();
        $at = CC_DB::apps();

        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $co WHERE id=%d", $order_id));
        if (!$order) return;

        // Find matching app
        $app = null;
        if ($order->billing_email) {
            $app = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $at WHERE email=%s ORDER BY created_at DESC LIMIT 1",
                $order->billing_email
            ));
        }

        if ($app && !in_array($app->status, ['converted', 'lost'])) {
            $old = $app->status;
            $wpdb->update($at, ['status' => 'converted'], ['id' => $app->id]);
            $wpdb->insert(CC_DB::seg_hist(), [
                'app_id'    => $app->id,
                'old_value' => $old,
                'new_value' => 'converted',
                'reason'    => "Auto: Camp order #{$order_id} (\${$order->total_amount})",
            ]);
        }

        // Fire attribution tracking
        if (class_exists('CC_Attribution') && $order->billing_email) {
            CC_Attribution::on_camp_order_completed($order_id);
        }
    }
}
