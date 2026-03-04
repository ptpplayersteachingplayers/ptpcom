<?php
/**
 * Plugin Name: PTP Engine
 * Description: The relationship engine behind PTP — CRM, SMS, AI, Stripe, Google Calendar, Attribution, OpenPhone Platform, and real-time Comms Hub in one plugin.
 * Version: 3.0
 * Author: Luke Martelli
 * Author URI: https://ptpsummercamps.com
 * Text Domain: ptp-engine
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* ═══════════════════════════════════════════════════════════════════════════
   SAFETY: Prevent fatal if old CC or Comms Hub plugin is still active.
   ═══════════════════════════════════════════════════════════════════════════ */
$_ptp_engine_conflict = false;
if ( defined( 'PTP_CC_VER' ) && ! defined( 'PTP_ENGINE_VER' ) ) $_ptp_engine_conflict = true;
if ( defined( 'PTP_CH_VER' ) && ! defined( 'PTP_ENGINE_VER' ) ) $_ptp_engine_conflict = true;

if ( $_ptp_engine_conflict ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>PTP Engine:</strong> '
             . 'Please deactivate <strong>PTP Command Center</strong> and/or <strong>PTP Comms Hub</strong> '
             . 'before activating PTP Engine. They contain the same code and will conflict.</p></div>';
    } );
    return;
}
unset( $_ptp_engine_conflict );

/* ═══════════════════════════════════════════════════════════════════════════
   CONSTANTS
   ═══════════════════════════════════════════════════════════════════════════ */
define( 'PTP_ENGINE_VER',  '3.0' );
define( 'PTP_ENGINE_PATH', plugin_dir_path( __FILE__ ) );
define( 'PTP_ENGINE_URL',  plugin_dir_url( __FILE__ ) );
define( 'PTP_ENGINE_DIR',  __DIR__ );

// Backward-compat aliases
if ( ! defined( 'PTP_CC_VER' ) )     define( 'PTP_CC_VER',     PTP_ENGINE_VER );
if ( ! defined( 'PTP_CC_DB_VER' ) )  define( 'PTP_CC_DB_VER',  PTP_ENGINE_VER );
if ( ! defined( 'PTP_CC_PATH' ) )    define( 'PTP_CC_PATH',    PTP_ENGINE_PATH );
if ( ! defined( 'PTP_CC_URL' ) )     define( 'PTP_CC_URL',     PTP_ENGINE_URL );
if ( ! defined( 'PTP_CH_VER' ) )     define( 'PTP_CH_VER',     PTP_ENGINE_VER );
if ( ! defined( 'PTP_CH_PATH' ) )    define( 'PTP_CH_PATH',    PTP_ENGINE_PATH );
if ( ! defined( 'PTP_CH_URL' ) )     define( 'PTP_CH_URL',     PTP_ENGINE_URL );

/* ═══════════════════════════════════════════════════════════════════════════
   LOAD ALL CLASSES
   ═══════════════════════════════════════════════════════════════════════════ */
require_once PTP_ENGINE_PATH . 'includes/class-cc-db.php';
require_once PTP_ENGINE_PATH . 'includes/class-cc-api.php';
require_once PTP_ENGINE_PATH . 'includes/class-cc-public.php';
require_once PTP_ENGINE_PATH . 'includes/class-cc-webhooks.php';
require_once PTP_ENGINE_PATH . 'includes/class-cc-sequences.php';
require_once PTP_ENGINE_PATH . 'includes/class-cc-rules-engine.php';
require_once PTP_ENGINE_PATH . 'includes/class-cc-ai-engine.php';
require_once PTP_ENGINE_PATH . 'includes/class-cc-campaigns.php';
require_once PTP_ENGINE_PATH . 'includes/class-cc-health.php';
require_once PTP_ENGINE_PATH . 'includes/class-cc-inbox.php';
require_once PTP_ENGINE_PATH . 'includes/class-cc-lead-scoring.php';
require_once PTP_ENGINE_PATH . 'includes/class-cc-stripe-listener.php';
require_once PTP_ENGINE_PATH . 'includes/class-cc-stripe-sync.php';
require_once PTP_ENGINE_PATH . 'includes/class-cc-openphone-sync.php';
require_once PTP_ENGINE_PATH . 'includes/class-cc-openphone-platform.php';
require_once PTP_ENGINE_PATH . 'includes/class-cc-bridge.php';
require_once PTP_ENGINE_PATH . 'includes/class-cc-gcal.php';
require_once PTP_ENGINE_PATH . 'includes/class-cc-attribution.php';
require_once PTP_ENGINE_PATH . 'includes/class-cc-landing-bridge.php';
require_once PTP_ENGINE_PATH . 'includes/class-cc-daily-digest.php';
require_once PTP_ENGINE_PATH . 'includes/class-cc-desktop-api.php';
require_once PTP_ENGINE_PATH . 'includes/class-cc-email-campaigns.php';

// Admin — NEW: unified React app loader
require_once PTP_ENGINE_PATH . 'admin/class-cc-admin.php';

// Comms Hub (messaging layer)
require_once PTP_ENGINE_PATH . 'includes/class-engine-comms.php';

/* ═══════════════════════════════════════════════════════════════════════════
   CUSTOM CRON INTERVALS
   ═══════════════════════════════════════════════════════════════════════════ */
add_filter( 'cron_schedules', function ( $s ) {
    $s['ptp_engine_5min']  = [ 'interval' => 300,   'display' => 'Every 5 Minutes' ];
    $s['ptp_engine_30min'] = [ 'interval' => 1800,  'display' => 'Every 30 Minutes' ];
    $s['ptp_engine_6hr']   = [ 'interval' => 21600, 'display' => 'Every 6 Hours' ];
    $s['ptp_cc_5min']  = [ 'interval' => 300,   'display' => 'Every 5 Minutes' ];
    $s['ptp_cc_30min'] = [ 'interval' => 1800,  'display' => 'Every 30 Minutes' ];
    $s['ptp_cc_6hr']   = [ 'interval' => 21600, 'display' => 'Every 6 Hours' ];
    return $s;
} );

/* ═══════════════════════════════════════════════════════════════════════════
   PLUGINS LOADED
   ═══════════════════════════════════════════════════════════════════════════ */
add_action( 'plugins_loaded', function () {
    CC_Bridge::init();
    CC_Landing_Bridge::init();
    CC_Daily_Digest::init();
    CC_OpenPhone_Sync::register_hooks();
    CC_OpenPhone_Platform::register_hooks();
    CC_Attribution::register_hooks();
}, 20 );

/* ═══════════════════════════════════════════════════════════════════════════
   PUBLIC PAGES
   ═══════════════════════════════════════════════════════════════════════════ */
add_action( 'init', function () {
    CC_Public::init();
} );

/* ═══════════════════════════════════════════════════════════════════════════
   ACTIVATION
   ═══════════════════════════════════════════════════════════════════════════ */
register_activation_hook( __FILE__, function () {
    CC_DB::create_tables();
    CC_DB::seed_data();
    CC_GCal::create_tables();
    CC_Campaigns::create_tables();
    CC_OpenPhone_Platform::create_tables();
    CC_Attribution::create_tables();
    PTP_Engine_Comms::create_tables();
    CC_Email_Campaigns::create_tables();
    CC_Daily_Digest::schedule();

    $crons = [
        'ptp_cc_run_sequences'       => [ 'ptp_cc_30min',  0 ],
        'ptp_cc_ad_spend_sync'       => [ 'ptp_cc_6hr',    3600 ],
        'ptp_cc_attribution_cleanup' => [ 'daily',         7200 ],
        'ptp_cc_lead_scoring'        => [ 'hourly',        300 ],
        'ptp_cc_retry_queue'         => [ 'ptp_cc_5min',   60 ],
        'ptp_cc_op_backfill'         => [ 'hourly',        600 ],
    ];
    foreach ( $crons as $hook => $cfg ) {
        if ( ! wp_next_scheduled( $hook ) ) {
            wp_schedule_event( time() + $cfg[1], $cfg[0], $hook );
        }
    }

    add_rewrite_rule( '^ptp-comms/?$', 'index.php?ptp_comms_pwa=1', 'top' );
    flush_rewrite_rules();

    update_option( 'ptp_engine_version', PTP_ENGINE_VER );
    update_option( 'ptp_cc_activated_at', current_time( 'mysql' ) );
} );

/* ═══════════════════════════════════════════════════════════════════════════
   DEACTIVATION
   ═══════════════════════════════════════════════════════════════════════════ */
register_deactivation_hook( __FILE__, function () {
    $hooks = [
        'ptp_cc_run_sequences', 'ptp_cc_lead_scoring', 'ptp_cc_retry_queue',
        'ptp_cc_op_backfill', 'ptp_cc_ad_spend_sync', 'ptp_cc_attribution_cleanup',
        'ptp_engine_daily_digest',
    ];
    foreach ( $hooks as $hook ) wp_clear_scheduled_hook( $hook );
    flush_rewrite_rules();
} );

/* ═══════════════════════════════════════════════════════════════════════════
   CRON ACTIONS
   ═══════════════════════════════════════════════════════════════════════════ */
add_action( 'ptp_cc_run_sequences', function () { ( new CC_Sequences() )->run(); } );
add_action( 'ptp_cc_lead_scoring',        [ 'CC_Lead_Scoring', 'run' ] );
add_action( 'ptp_cc_retry_queue',         [ 'CC_DB', 'process_retry_queue' ] );
add_action( 'ptp_cc_ai_generate_draft',   [ 'CC_AI_Engine', 'async_generate_draft' ], 10, 4 );
add_action( 'ptp_cc_op_backfill',         [ 'CC_OpenPhone_Platform', 'cron_backfill' ] );
add_action( 'ptp_cc_capture_call_intel',  [ 'CC_OpenPhone_Platform', 'async_capture_call_intel' ], 10, 2 );
add_action( 'ptp_cc_campaign_batch',      [ 'CC_Campaigns', 'process_batch' ], 10, 1 );
add_action( 'ptp_cc_email_batch',         [ 'CC_Email_Campaigns', 'process_batch' ], 10, 1 );
add_action( 'ptp_cc_ad_spend_sync',       [ 'CC_Attribution', 'cron_sync_spend' ] );
add_action( 'ptp_cc_attribution_cleanup', [ 'CC_Attribution', 'cron_cleanup' ] );

/* ═══════════════════════════════════════════════════════════════════════════
   REST API
   ═══════════════════════════════════════════════════════════════════════════ */
add_action( 'rest_api_init', function () {
    $api = new CC_API();
    $api->register_routes();
    $api->register_extended_routes();
    $api->register_template_routes();
    $api->register_booking_routes();
    $api->register_training_link_routes();
    $api->register_family_routes();
    $api->register_trainer_mgmt_routes();
    $api->register_camp_routes();
    $api->register_customer360_routes();
    $api->register_finance_routes();

    ( new CC_Webhooks() )->register_routes();
    CC_Public::register_routes();
    CC_GCal::register_routes();
    CC_Stripe_Sync::register_routes();
    CC_OpenPhone_Sync::register_routes();
    CC_OpenPhone_Platform::register_routes( 'ptp-cc/v1' );
    CC_AI_Engine::register_routes( 'ptp-cc/v1' );
    CC_Campaigns::register_routes( 'ptp-cc/v1' );
    CC_Health::register_routes( 'ptp-cc/v1' );
    CC_Inbox::register_routes( 'ptp-cc/v1' );
    CC_Attribution::register_routes();

    $comms = new PTP_Engine_Comms();
    $comms->register_routes();

    CC_Desktop_API::register_routes();
    CC_Email_Campaigns::register_routes();
} );

/* ═══════════════════════════════════════════════════════════════════════════
   ADMIN MENU — CC_Admin handles menu + React app loading
   ═══════════════════════════════════════════════════════════════════════════ */
add_action( 'admin_menu', [ 'CC_Admin', 'register_menu' ] );

/* ═══════════════════════════════════════════════════════════════════════════
   COMMS HUB PWA SUPPORT
   ═══════════════════════════════════════════════════════════════════════════ */
add_action( 'init', function () {
    add_rewrite_rule( '^ptp-comms/?$', 'index.php?ptp_comms_pwa=1', 'top' );
    add_rewrite_tag( '%ptp_comms_pwa%', '([0-1]{1})' );
} );
add_action( 'template_redirect', function () {
    if ( get_query_var( 'ptp_comms_pwa' ) ) {
        $comms = new PTP_Engine_Comms();
        $comms->render_pwa();
        exit;
    }
} );

/* ═══════════════════════════════════════════════════════════════════════════
   NONCE REFRESH
   ═══════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_ptp_ch_refresh_nonce', function () {
    wp_send_json_success( [ 'nonce' => wp_create_nonce( 'wp_rest' ) ] );
} );

/* ═══════════════════════════════════════════════════════════════════════════
   ADMIN BAR
   ═══════════════════════════════════════════════════════════════════════════ */
add_action( 'admin_bar_menu', function ( $bar ) {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $stats = get_transient( 'ptp_engine_admin_bar_stats' );
    if ( $stats === false ) {
        global $wpdb;
        $pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . CC_DB::apps() . " WHERE status='pending'" );
        $drafts_t = CC_DB::drafts();
        $drafts = 0;
        if ( CC_DB::has_table_public( str_replace( $wpdb->prefix, '', $drafts_t ) ) ) {
            $drafts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $drafts_t WHERE status='pending'" );
        }
        $stats = [ 'pending' => $pending, 'drafts' => $drafts ];
        set_transient( 'ptp_engine_admin_bar_stats', $stats, 120 );
    }
    $bar->add_node( [ 'id' => 'ptp-engine', 'title' => '<span class="ab-icon dashicons-superhero"></span> PTP' . ( $stats['pending'] ? " ({$stats['pending']})" : '' ), 'href' => admin_url( 'admin.php?page=ptp-engine' ) ] );
    $bar->add_node( [ 'parent' => 'ptp-engine', 'id' => 'ptp-engine-pipeline', 'title' => "Pipeline ({$stats['pending']} pending)", 'href' => admin_url( 'admin.php?page=ptp-engine' ) ] );
    if ( $stats['drafts'] ) {
        $bar->add_node( [ 'parent' => 'ptp-engine', 'id' => 'ptp-engine-drafts', 'title' => "<span style='color:#E53935'>{$stats['drafts']} draft(s) need approval</span>", 'href' => admin_url( 'admin.php?page=ptp-engine' ) ] );
    }
}, 999 );

/* ═══════════════════════════════════════════════════════════════════════════
   STRIPE WEBHOOK LISTENER
   ═══════════════════════════════════════════════════════════════════════════ */
add_action( 'ptp_booking_paid',      [ 'CC_Stripe_Listener', 'on_booking_paid' ] );
add_action( 'ptp_booking_completed', [ 'CC_Stripe_Listener', 'on_booking_paid' ] );

/* ═══════════════════════════════════════════════════════════════════════════
   ADMIN STYLES
   ═══════════════════════════════════════════════════════════════════════════ */
add_action( 'admin_head', function () {
    echo '<style>#wpadminbar #wp-admin-bar-ptp-engine .ab-icon:before{top:2px}</style>';
} );

/* ═══════════════════════════════════════════════════════════════════════════
   DB VERSION CHECK — auto-upgrade schema on admin_init
   ═══════════════════════════════════════════════════════════════════════════ */
add_action( 'admin_init', function () {
    $crons = [ 'ptp_cc_run_sequences' => 'ptp_cc_30min', 'ptp_cc_lead_scoring' => 'hourly', 'ptp_cc_retry_queue' => 'ptp_cc_5min' ];
    foreach ( $crons as $hook => $interval ) {
        if ( ! wp_next_scheduled( $hook ) ) wp_schedule_event( time(), $interval, $hook );
    }
    CC_Daily_Digest::schedule();
    if ( get_option( 'ptp_engine_db_version' ) !== PTP_ENGINE_VER ) {
        CC_DB::create_tables();
        CC_DB::seed_data();
        CC_GCal::create_tables();
        CC_Campaigns::create_tables();
        CC_OpenPhone_Platform::create_tables();
        CC_Attribution::create_tables();
        PTP_Engine_Comms::create_tables();
        CC_Email_Campaigns::create_tables();
        update_option( 'ptp_engine_db_version', PTP_ENGINE_VER );
    }
} );
