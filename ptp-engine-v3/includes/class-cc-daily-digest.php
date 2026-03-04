<?php
/**
 * CC_Daily_Digest
 * 
 * Sends a daily summary email to admin with:
 * - Pipeline snapshot (stage counts)
 * - Ad spend summary (today + week + blended CAC)
 * - Message activity (inbound/outbound/unread)
 * - New leads (last 24hr)
 * - Funnel conversion rates
 * - 48hr window alerts
 * - Prioritized action items
 * 
 * Runs via WP Cron daily. Can also be triggered manually via REST API.
 * 
 * @since 2.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CC_Daily_Digest {

    const OPTION_RECIPIENTS = 'ptp_engine_digest_recipients';
    const OPTION_ENABLED    = 'ptp_engine_digest_enabled';
    const OPTION_LAST_SENT  = 'ptp_engine_digest_last_sent';
    const CRON_HOOK         = 'ptp_engine_daily_digest';

    /**
     * Register hooks
     */
    public static function init() {
        add_action( self::CRON_HOOK, [ __CLASS__, 'send_digest' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
        add_action( 'admin_init',    [ __CLASS__, 'register_settings' ] );
    }

    /**
     * Schedule the cron
     */
    public static function schedule() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            // Schedule for 7am local time tomorrow
            $tomorrow_7am = strtotime( 'tomorrow 7:00am', current_time( 'timestamp' ) );
            $utc_time     = $tomorrow_7am - ( get_option( 'gmt_offset' ) * 3600 );
            wp_schedule_event( $utc_time, 'daily', self::CRON_HOOK );
        }
    }

    /**
     * Unschedule the cron
     */
    public static function unschedule() {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) wp_unschedule_event( $ts, self::CRON_HOOK );
    }

    /**
     * Register REST routes
     */
    public static function register_routes() {
        register_rest_route( 'ptp-cc/v1', '/digest/send', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'api_send_now' ],
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ] );

        register_rest_route( 'ptp-cc/v1', '/digest/preview', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'api_preview' ],
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ] );

        register_rest_route( 'ptp-cc/v1', '/digest/settings', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'api_get_settings' ],
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ] );

        register_rest_route( 'ptp-cc/v1', '/digest/settings', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'api_update_settings' ],
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ] );
    }

    /**
     * Register admin settings
     */
    public static function register_settings() {
        register_setting( 'ptp_engine_settings', self::OPTION_RECIPIENTS );
        register_setting( 'ptp_engine_settings', self::OPTION_ENABLED );
    }

    /**
     * Get recipient emails
     */
    public static function get_recipients() {
        $stored = get_option( self::OPTION_RECIPIENTS, '' );
        if ( ! $stored ) {
            return [ get_option( 'admin_email' ) ];
        }
        return array_filter( array_map( 'trim', explode( ',', $stored ) ) );
    }

    /**
     * Check if digest is enabled
     */
    public static function is_enabled() {
        return get_option( self::OPTION_ENABLED, '1' ) === '1';
    }

    /**
     * Collect all data for the digest
     */
    public static function collect_data() {
        global $wpdb;

        $data = [
            'date'       => current_time( 'l, F j, Y' ),
            'site_name'  => get_bloginfo( 'name' ),
            'site_url'   => home_url(),
            'admin_url'  => admin_url( 'admin.php?page=ptp-engine' ),
        ];

        $families_t = CC_DB::families();
        $activity_t = CC_DB::activity();
        $messages_t = CC_DB::op_msgs();
        $ad_spend_t = CC_DB::ad_spend();

        $today     = current_time( 'Y-m-d' );
        $yesterday = date( 'Y-m-d', strtotime( '-1 day', current_time( 'timestamp' ) ) );
        $week_ago  = date( 'Y-m-d', strtotime( '-7 days', current_time( 'timestamp' ) ) );

        // ── FAMILIES ──
        $data['total_families'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $families_t" );
        $data['total_revenue']  = (float) $wpdb->get_var( "SELECT COALESCE(SUM(total_spent), 0) FROM $families_t" );

        // New families (24hr)
        $data['new_families_24h'] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $families_t WHERE created_at >= %s", "$yesterday 00:00:00"
        ) );

        // New families (7d)
        $data['new_families_7d'] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $families_t WHERE created_at >= %s", "$week_ago 00:00:00"
        ) );

        // Recent family details
        $data['recent_families'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, display_name, email, phone, city, created_at FROM $families_t WHERE created_at >= %s ORDER BY created_at DESC LIMIT 10",
            "$week_ago 00:00:00"
        ) );

        // ── PIPELINE STAGES (from tags) ──
        $tags_t = $wpdb->prefix . 'ptp_cc_tags';
        $data['pipeline'] = [];

        // Define stages and count families in each
        // Pipeline tracked via tags or activity - count what we can
        $stages = [
            'New Lead', 'Contacted', 'Camp Registered', 'Camp Attended',
            '48hr Window', 'Training Converted', 'Recurring', 'VIP'
        ];
        foreach ( $stages as $stage ) {
            if ( CC_DB::has_table( 'ptp_cc_tags' ) ) {
                $count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(DISTINCT family_id) FROM $tags_t WHERE tag_name = %s", $stage
                ) );
                $data['pipeline'][ $stage ] = $count;
            }
        }

        // ── LANDING PAGE LEADS ──
        $data['lp_total'] = 0;
        $data['lp_today'] = 0;
        $data['lp_week']  = 0;
        if ( post_type_exists( 'ptp26_lead' ) || $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'ptp26_lead' LIMIT 1" ) ) {
            $data['lp_total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'ptp26_lead'" );
            $data['lp_today'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'ptp26_lead' AND post_date >= %s",
                "$today 00:00:00"
            ) );
            $data['lp_week'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'ptp26_lead' AND post_date >= %s",
                "$week_ago 00:00:00"
            ) );
        }

        // ── MESSAGES ──
        $data['msgs_today_in']  = 0;
        $data['msgs_today_out'] = 0;
        $data['msgs_week_in']   = 0;
        $data['msgs_week_out']  = 0;

        if ( CC_DB::has_table( 'ptp_cc_openphone_messages' ) ) {
            $data['msgs_today_in']  = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $messages_t WHERE direction = 'inbound' AND created_at >= %s", "$today 00:00:00"
            ) );
            $data['msgs_today_out'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $messages_t WHERE direction = 'outbound' AND created_at >= %s", "$today 00:00:00"
            ) );
            $data['msgs_week_in']   = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $messages_t WHERE direction = 'inbound' AND created_at >= %s", "$week_ago 00:00:00"
            ) );
            $data['msgs_week_out']  = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $messages_t WHERE direction = 'outbound' AND created_at >= %s", "$week_ago 00:00:00"
            ) );
        }

        // ── AD SPEND ──
        $data['spend_today']       = 0;
        $data['spend_week']        = 0;
        $data['spend_all']         = 0;
        $data['leads_today']       = 0;
        $data['leads_week']        = 0;
        $data['leads_all']         = 0;
        $data['spend_by_platform'] = [];

        if ( CC_DB::has_table( 'ptp_cc_ad_spend' ) ) {
            $data['spend_today'] = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM $ad_spend_t WHERE spend_date = %s", $today
            ) );
            $data['spend_week'] = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM $ad_spend_t WHERE spend_date >= %s", $week_ago
            ) );
            $data['spend_all'] = (float) $wpdb->get_var(
                "SELECT COALESCE(SUM(amount), 0) FROM $ad_spend_t"
            );

            // Leads from attribution or ad_spend table
            $data['spend_by_platform'] = $wpdb->get_results( $wpdb->prepare(
                "SELECT platform, SUM(amount) as total_spend, SUM(clicks) as total_clicks, SUM(conversions) as total_leads
                 FROM $ad_spend_t WHERE spend_date >= %s GROUP BY platform",
                $week_ago
            ) );
        }

        // ── REVENUE (from revenue table) ──
        $rev_t = $wpdb->prefix . 'ptp_cc_revenue';
        $data['revenue_today'] = 0;
        $data['revenue_week']  = 0;
        if ( CC_DB::has_table( 'ptp_cc_revenue' ) ) {
            $data['revenue_today'] = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM $rev_t WHERE revenue_date >= %s AND status = 'completed'", "$today 00:00:00"
            ) );
            $data['revenue_week'] = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM $rev_t WHERE revenue_date >= %s AND status = 'completed'", "$week_ago 00:00:00"
            ) );
        }

        // ── CAMP BOOKINGS ──
        $data['camp_bookings_week'] = 0;
        if ( CC_DB::has_table( 'ptp_camp_bookings' ) || CC_DB::has_table( 'ptp_unified_camp_orders' ) ) {
            $camp_t = CC_DB::camp_orders();
            $data['camp_bookings_week'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $camp_t WHERE created_at >= %s", "$week_ago 00:00:00"
            ) );
        }

        // ── TRAINING BOOKINGS ──
        $data['training_bookings_week'] = 0;
        if ( CC_DB::has_table( 'ptp_bookings' ) ) {
            $book_t = CC_DB::bookings();
            $data['training_bookings_week'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $book_t WHERE created_at >= %s", "$week_ago 00:00:00"
            ) );
        }

        // ── GIVEAWAY ──
        $data['giveaway_entries_week'] = 0;
        if ( CC_DB::has_table( 'ptp_giveaway_entries' ) ) {
            $data['giveaway_entries_week'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_giveaway_entries WHERE created_at >= %s", "$week_ago 00:00:00"
            ) );
        }

        // ── RECENT ACTIVITY ──
        $data['recent_activity'] = [];
        if ( CC_DB::has_table( 'ptp_cc_activity_log' ) ) {
            $data['recent_activity'] = $wpdb->get_results( $wpdb->prepare(
                "SELECT action, entity_type, detail, created_at FROM $activity_t WHERE created_at >= %s ORDER BY created_at DESC LIMIT 20",
                "$yesterday 00:00:00"
            ) );
        }

        // ── BLENDED CAC ──
        $total_leads = $data['lp_week'] + $data['new_families_7d'];
        $data['cac_week'] = $total_leads > 0 ? round( $data['spend_week'] / $total_leads, 2 ) : 0;

        $total_leads_all = $data['lp_total'] + $data['total_families'];
        $data['cac_all'] = $total_leads_all > 0 ? round( $data['spend_all'] / $total_leads_all, 2 ) : 0;

        return $data;
    }

    /**
     * Build the email HTML
     */
    public static function build_email( $data ) {
        $gold   = '#FCB900';
        $black  = '#0A0A0A';
        $border = '#E0DFDB';
        $muted  = '#918F89';
        $green  = '#2D8A4E';
        $red    = '#C62828';
        $blue   = '#1565C0';

        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:0;background:#F5F4F0;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;color:<?php echo $black; ?>">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#F5F4F0;padding:20px 0">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#FFFFFF;border:2px solid <?php echo $border; ?>">

<!-- Header -->
<tr><td style="background:<?php echo $black; ?>;padding:20px 30px">
    <table width="100%"><tr>
        <td><span style="font-family:'Helvetica Neue',sans-serif;font-size:22px;font-weight:800;color:<?php echo $gold; ?>;letter-spacing:2px">PTP ENGINE</span><br>
        <span style="font-size:12px;color:#888;letter-spacing:1px">DAILY DIGEST</span></td>
        <td align="right"><span style="font-size:13px;color:#CCCCCC"><?php echo $data['date']; ?></span></td>
    </tr></table>
</td></tr>

<!-- Summary Bar -->
<tr><td style="padding:20px 30px;border-bottom:2px solid <?php echo $border; ?>">
    <table width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td width="25%" style="text-align:center;padding:10px">
            <div style="font-size:28px;font-weight:800;color:<?php echo $black; ?>"><?php echo $data['total_families']; ?></div>
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:<?php echo $muted; ?>">Families</div>
        </td>
        <td width="25%" style="text-align:center;padding:10px">
            <div style="font-size:28px;font-weight:800;color:<?php echo $green; ?>">$<?php echo number_format($data['total_revenue']); ?></div>
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:<?php echo $muted; ?>">Revenue</div>
        </td>
        <td width="25%" style="text-align:center;padding:10px">
            <div style="font-size:28px;font-weight:800;color:<?php echo $blue; ?>">$<?php echo number_format($data['spend_week']); ?></div>
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:<?php echo $muted; ?>">Spend (7d)</div>
        </td>
        <td width="25%" style="text-align:center;padding:10px">
            <div style="font-size:28px;font-weight:800;color:<?php echo $gold; ?>">$<?php echo number_format($data['cac_week']); ?></div>
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:<?php echo $muted; ?>">CAC (7d)</div>
        </td>
    </tr>
    </table>
</td></tr>

<!-- Landing Page Leads -->
<tr><td style="padding:20px 30px;border-bottom:1px solid <?php echo $border; ?>">
    <div style="font-size:12px;text-transform:uppercase;letter-spacing:2px;color:<?php echo $muted; ?>;font-weight:700;margin-bottom:12px">LANDING PAGE LEADS</div>
    <table width="100%">
    <tr>
        <td width="33%"><strong style="font-size:20px;color:<?php echo $data['lp_today'] > 0 ? $green : $muted; ?>"><?php echo $data['lp_today']; ?></strong><br><span style="font-size:11px;color:<?php echo $muted; ?>">Today</span></td>
        <td width="33%"><strong style="font-size:20px"><?php echo $data['lp_week']; ?></strong><br><span style="font-size:11px;color:<?php echo $muted; ?>">This Week</span></td>
        <td width="33%"><strong style="font-size:20px"><?php echo $data['lp_total']; ?></strong><br><span style="font-size:11px;color:<?php echo $muted; ?>">All Time</span></td>
    </tr>
    </table>
</td></tr>

<!-- Messages -->
<tr><td style="padding:20px 30px;border-bottom:1px solid <?php echo $border; ?>">
    <div style="font-size:12px;text-transform:uppercase;letter-spacing:2px;color:<?php echo $muted; ?>;font-weight:700;margin-bottom:12px">MESSAGING (VIA OPENPHONE)</div>
    <table width="100%">
    <tr>
        <td width="25%"><strong style="font-size:18px;color:<?php echo $blue; ?>"><?php echo $data['msgs_today_in']; ?></strong><br><span style="font-size:11px;color:<?php echo $muted; ?>">Inbound Today</span></td>
        <td width="25%"><strong style="font-size:18px;color:<?php echo $gold; ?>"><?php echo $data['msgs_today_out']; ?></strong><br><span style="font-size:11px;color:<?php echo $muted; ?>">Outbound Today</span></td>
        <td width="25%"><strong style="font-size:18px"><?php echo $data['msgs_week_in']; ?></strong><br><span style="font-size:11px;color:<?php echo $muted; ?>">Inbound (7d)</span></td>
        <td width="25%"><strong style="font-size:18px"><?php echo $data['msgs_week_out']; ?></strong><br><span style="font-size:11px;color:<?php echo $muted; ?>">Outbound (7d)</span></td>
    </tr>
    </table>
</td></tr>

<!-- Ad Spend -->
<tr><td style="padding:20px 30px;border-bottom:1px solid <?php echo $border; ?>">
    <div style="font-size:12px;text-transform:uppercase;letter-spacing:2px;color:<?php echo $muted; ?>;font-weight:700;margin-bottom:12px">AD SPEND</div>
    <table width="100%" style="font-size:13px">
    <tr style="border-bottom:1px solid <?php echo $border; ?>">
        <td style="padding:6px 0;font-weight:700">Today</td>
        <td align="right" style="padding:6px 0;font-weight:700;color:<?php echo $blue; ?>">$<?php echo number_format($data['spend_today']); ?></td>
    </tr>
    <tr style="border-bottom:1px solid <?php echo $border; ?>">
        <td style="padding:6px 0;font-weight:700">This Week</td>
        <td align="right" style="padding:6px 0;font-weight:700;color:<?php echo $blue; ?>">$<?php echo number_format($data['spend_week']); ?></td>
    </tr>
    <tr>
        <td style="padding:6px 0;font-weight:700">All Time</td>
        <td align="right" style="padding:6px 0;font-weight:700">$<?php echo number_format($data['spend_all']); ?></td>
    </tr>
    </table>
    <?php if ( ! empty( $data['spend_by_platform'] ) ) : ?>
    <div style="margin-top:10px;font-size:12px;color:<?php echo $muted; ?>">
        <?php foreach ( $data['spend_by_platform'] as $p ) : ?>
            <strong><?php echo ucfirst($p->platform); ?></strong>: $<?php echo number_format($p->total_spend); ?> (<?php echo (int)$p->total_clicks; ?> clicks, <?php echo (int)$p->total_leads; ?> leads) &nbsp;|&nbsp;
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</td></tr>

<!-- Bookings -->
<tr><td style="padding:20px 30px;border-bottom:1px solid <?php echo $border; ?>">
    <div style="font-size:12px;text-transform:uppercase;letter-spacing:2px;color:<?php echo $muted; ?>;font-weight:700;margin-bottom:12px">BOOKINGS (7 DAYS)</div>
    <table width="100%">
    <tr>
        <td width="33%"><strong style="font-size:20px;color:<?php echo $green; ?>"><?php echo $data['camp_bookings_week']; ?></strong><br><span style="font-size:11px;color:<?php echo $muted; ?>">Camp Orders</span></td>
        <td width="33%"><strong style="font-size:20px;color:<?php echo $gold; ?>"><?php echo $data['training_bookings_week']; ?></strong><br><span style="font-size:11px;color:<?php echo $muted; ?>">Training Sessions</span></td>
        <td width="33%"><strong style="font-size:20px"><?php echo $data['giveaway_entries_week']; ?></strong><br><span style="font-size:11px;color:<?php echo $muted; ?>">Giveaway Entries</span></td>
    </tr>
    </table>
</td></tr>

<!-- New Families -->
<?php if ( ! empty( $data['recent_families'] ) ) : ?>
<tr><td style="padding:20px 30px;border-bottom:1px solid <?php echo $border; ?>">
    <div style="font-size:12px;text-transform:uppercase;letter-spacing:2px;color:<?php echo $muted; ?>;font-weight:700;margin-bottom:12px">NEW CONTACTS (7 DAYS)</div>
    <table width="100%" style="font-size:12px">
    <?php foreach ( $data['recent_families'] as $f ) : ?>
    <tr style="border-bottom:1px solid <?php echo $border; ?>">
        <td style="padding:6px 0"><strong><?php echo esc_html($f->display_name); ?></strong></td>
        <td style="padding:6px 0;color:<?php echo $muted; ?>"><?php echo esc_html($f->city ?: ''); ?></td>
        <td style="padding:6px 0;color:<?php echo $muted; ?>;font-size:11px"><?php echo date('M j', strtotime($f->created_at)); ?></td>
    </tr>
    <?php endforeach; ?>
    </table>
</td></tr>
<?php endif; ?>

<!-- Recent Activity -->
<?php if ( ! empty( $data['recent_activity'] ) ) : ?>
<tr><td style="padding:20px 30px;border-bottom:1px solid <?php echo $border; ?>">
    <div style="font-size:12px;text-transform:uppercase;letter-spacing:2px;color:<?php echo $muted; ?>;font-weight:700;margin-bottom:12px">RECENT ACTIVITY</div>
    <table width="100%" style="font-size:12px">
    <?php foreach ( array_slice($data['recent_activity'], 0, 10) as $a ) : ?>
    <tr style="border-bottom:1px solid <?php echo $border; ?>">
        <td style="padding:5px 0;width:60px;color:<?php echo $muted; ?>;font-size:11px"><?php echo date('g:ia', strtotime($a->created_at)); ?></td>
        <td style="padding:5px 0"><?php echo esc_html( $a->action . ($a->detail ? ': ' . substr($a->detail, 0, 80) : '') ); ?></td>
    </tr>
    <?php endforeach; ?>
    </table>
</td></tr>
<?php endif; ?>

<!-- Action Items -->
<tr><td style="padding:20px 30px;background:#FFFDE7;border-bottom:2px solid <?php echo $gold; ?>">
    <div style="font-size:12px;text-transform:uppercase;letter-spacing:2px;color:<?php echo $gold; ?>;font-weight:700;margin-bottom:12px">ACTION ITEMS</div>
    <table width="100%" style="font-size:13px">
    <?php
    $actions = [];
    if ( $data['msgs_today_in'] > $data['msgs_today_out'] ) {
        $gap = $data['msgs_today_in'] - $data['msgs_today_out'];
        $actions[] = [ 'urgent', "{$gap} inbound messages still need replies" ];
    }
    if ( isset($data['pipeline']['48hr Window']) && $data['pipeline']['48hr Window'] > 0 ) {
        $actions[] = [ 'urgent', "{$data['pipeline']['48hr Window']} families in 48hr conversion window — follow up NOW" ];
    }
    if ( $data['new_families_24h'] > 0 ) {
        $actions[] = [ 'high', "{$data['new_families_24h']} new families joined in 24hr — send welcome messages" ];
    }
    if ( $data['lp_today'] > 0 ) {
        $actions[] = [ 'high', "{$data['lp_today']} new landing page leads today — verify CRM sync" ];
    }
    if ( $data['spend_today'] > 0 && $data['lp_today'] == 0 ) {
        $actions[] = [ 'medium', "Spent \${$data['spend_today']} today with 0 LP leads — check ad targeting" ];
    }
    if ( $data['cac_week'] > 35 ) {
        $actions[] = [ 'medium', "Weekly CAC is \${$data['cac_week']} (target: <\$30) — review ad efficiency" ];
    }
    if ( empty( $actions ) ) {
        $actions[] = [ 'low', 'No urgent items — keep building momentum' ];
    }
    foreach ( $actions as $i => $a ) :
        $color = $a[0] === 'urgent' ? $red : ($a[0] === 'high' ? $gold : $muted);
    ?>
    <tr>
        <td style="padding:6px 0;width:20px;vertical-align:top;font-size:16px;color:<?php echo $color; ?>">
            <?php echo $a[0] === 'urgent' ? '!!' : ($a[0] === 'high' ? '!' : '—'); ?>
        </td>
        <td style="padding:6px 0;color:<?php echo $a[0] === 'urgent' ? $red : $black; ?>;font-weight:<?php echo $a[0] === 'urgent' ? '700' : '400'; ?>">
            <?php echo esc_html($a[1]); ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </table>
</td></tr>

<!-- Footer -->
<tr><td style="padding:16px 30px;background:<?php echo $black; ?>;text-align:center">
    <a href="<?php echo $data['admin_url']; ?>" style="color:<?php echo $gold; ?>;font-size:12px;font-weight:700;text-decoration:none;letter-spacing:1.5px;text-transform:uppercase">OPEN PTP ENGINE</a>
    <br><span style="font-size:10px;color:#666;margin-top:6px;display:inline-block">PTP Soccer Camps — ptpsummercamps.com</span>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    /**
     * Build plain-text version
     */
    public static function build_text( $data ) {
        $lines = [];
        $lines[] = "PTP ENGINE DAILY DIGEST";
        $lines[] = $data['date'];
        $lines[] = str_repeat( '=', 50 );
        $lines[] = "";
        $lines[] = "OVERVIEW";
        $lines[] = "  Families: {$data['total_families']}";
        $lines[] = "  Revenue: $" . number_format($data['total_revenue']);
        $lines[] = "  Spend (7d): $" . number_format($data['spend_week']);
        $lines[] = "  CAC (7d): $" . number_format($data['cac_week']);
        $lines[] = "";
        $lines[] = "LANDING PAGE LEADS";
        $lines[] = "  Today: {$data['lp_today']} | Week: {$data['lp_week']} | Total: {$data['lp_total']}";
        $lines[] = "";
        $lines[] = "MESSAGES";
        $lines[] = "  Today In: {$data['msgs_today_in']} | Out: {$data['msgs_today_out']}";
        $lines[] = "  Week In: {$data['msgs_week_in']} | Out: {$data['msgs_week_out']}";
        $lines[] = "";
        $lines[] = "AD SPEND";
        $lines[] = "  Today: $" . number_format($data['spend_today']);
        $lines[] = "  Week: $" . number_format($data['spend_week']);
        $lines[] = "  All Time: $" . number_format($data['spend_all']);
        $lines[] = "";
        $lines[] = "BOOKINGS (7d)";
        $lines[] = "  Camps: {$data['camp_bookings_week']} | Training: {$data['training_bookings_week']} | Giveaway: {$data['giveaway_entries_week']}";
        $lines[] = "";
        $lines[] = "New Families (24h): {$data['new_families_24h']}";
        $lines[] = "New Families (7d): {$data['new_families_7d']}";
        $lines[] = "";
        $lines[] = str_repeat( '=', 50 );
        $lines[] = "Open PTP Engine: {$data['admin_url']}";

        return implode( "\n", $lines );
    }

    /**
     * Send the digest email
     */
    public static function send_digest() {
        if ( ! self::is_enabled() ) return;
        if ( ! class_exists( 'CC_DB' ) ) return;

        $data       = self::collect_data();
        $html       = self::build_email( $data );
        $text       = self::build_text( $data );
        $recipients = self::get_recipients();
        $subject    = sprintf(
            'PTP Engine Daily Digest — %s | %d families | $%s revenue',
            current_time( 'M j' ),
            $data['total_families'],
            number_format( $data['total_revenue'] )
        );

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: PTP Engine <' . get_option('admin_email') . '>',
        ];

        $sent = wp_mail( $recipients, $subject, $html, $headers );

        update_option( self::OPTION_LAST_SENT, current_time( 'mysql' ) );

        CC_DB::log( 'daily_digest_sent', 'system', null,
            sprintf( 'Digest sent to %s (%s)', implode(', ', $recipients), $sent ? 'success' : 'failed' ),
            'digest'
        );

        error_log( '[PTP-Engine] Daily Digest sent to: ' . implode(', ', $recipients) . ' — ' . ($sent ? 'OK' : 'FAILED') );

        return $sent;
    }

    /**
     * API: Send digest now
     */
    public static function api_send_now( $req ) {
        $result = self::send_digest();
        return rest_ensure_response( [
            'sent'       => (bool) $result,
            'recipients' => self::get_recipients(),
            'last_sent'  => get_option( self::OPTION_LAST_SENT ),
        ] );
    }

    /**
     * API: Preview digest HTML
     */
    public static function api_preview( $req ) {
        $data = self::collect_data();
        return rest_ensure_response( [
            'html'    => self::build_email( $data ),
            'text'    => self::build_text( $data ),
            'data'    => $data,
            'subject' => sprintf( 'PTP Engine Daily Digest — %s', current_time( 'M j' ) ),
        ] );
    }

    /**
     * API: Get settings
     */
    public static function api_get_settings( $req ) {
        return rest_ensure_response( [
            'enabled'    => self::is_enabled(),
            'recipients' => self::get_recipients(),
            'last_sent'  => get_option( self::OPTION_LAST_SENT, 'never' ),
            'next_run'   => wp_next_scheduled( self::CRON_HOOK ) ? date( 'Y-m-d H:i:s', wp_next_scheduled( self::CRON_HOOK ) ) : 'not scheduled',
        ] );
    }

    /**
     * API: Update settings
     */
    public static function api_update_settings( $req ) {
        $params = $req->get_json_params();

        if ( isset( $params['enabled'] ) ) {
            update_option( self::OPTION_ENABLED, $params['enabled'] ? '1' : '0' );
            if ( $params['enabled'] ) {
                self::schedule();
            } else {
                self::unschedule();
            }
        }

        if ( isset( $params['recipients'] ) ) {
            $emails = is_array( $params['recipients'] )
                ? implode( ',', array_map( 'sanitize_email', $params['recipients'] ) )
                : sanitize_text_field( $params['recipients'] );
            update_option( self::OPTION_RECIPIENTS, $emails );
        }

        return rest_ensure_response( [
            'enabled'    => self::is_enabled(),
            'recipients' => self::get_recipients(),
        ] );
    }
}
