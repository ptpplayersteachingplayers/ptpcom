<?php
/**
 * PTP Engine — Uninstall
 *
 * CRM tables (ptp_cc_*) are preserved to prevent data loss.
 * To fully remove all data, drop wp_ptp_cc_* and wp_ptp_ch_* tables manually.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;

/* ─── Comms Hub tables (safe to drop — templates & drafts are regenerable) ─── */
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ptp_ch_templates" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ptp_ch_drafts" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ptp_ch_scheduled" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ptp_ch_settings" );

/* ─── Options: Engine ─── */
$options = [
    // Engine
    'ptp_engine_version',
    'ptp_engine_db_version',

    // CC (legacy)
    'ptp_cc_activated_at',
    'ptp_cc_db_version',

    // OpenPhone
    'ptp_openphone_api_key',
    'ptp_openphone_from',
    'ptp_cc_openphone_api_key',
    'ptp_cc_openphone_phone_id',
    'ptp_cc_openphone_webhook_secret',
    'ptp_cc_op_auto_backfill',
    'ptp_cc_op_auto_call_intel',
    'ptp_cc_op_last_backfill',
    'ptp_ch_openphone_key',
    'ptp_ch_openphone_from',

    // AI
    'ptp_cc_ai_api_key',
    'ptp_cc_ai_enabled',
    'ptp_cc_ai_auto_draft',
    'ptp_ch_anthropic_key',

    // Stripe
    'ptp_cc_stripe_secret_key',

    // Google Calendar
    'ptp_cc_google_refresh_token',
    'ptp_cc_google_client_id',
    'ptp_cc_google_client_secret',

    // Comms Hub
    'ptp_comms_hub_version',
];

foreach ( $options as $opt ) {
    delete_option( $opt );
}

/* ─── Transients ─── */
delete_transient( 'ptp_engine_admin_bar_stats' );
delete_transient( 'ptp_cc_admin_bar_stats' );

/* ─── Cron hooks ─── */
$crons = [
    'ptp_cc_run_sequences',
    'ptp_cc_lead_scoring',
    'ptp_cc_retry_queue',
    'ptp_cc_op_backfill',
    'ptp_cc_ad_spend_sync',
    'ptp_cc_attribution_cleanup',
];
foreach ( $crons as $hook ) {
    wp_clear_scheduled_hook( $hook );
}
