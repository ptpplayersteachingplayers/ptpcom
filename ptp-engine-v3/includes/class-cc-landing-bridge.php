<?php
/**
 * CC_Landing_Bridge
 * 
 * Auto-syncs Landing Page v27 leads (ptp26_lead CPT) into PTP Engine CRM.
 * Hooks into save_post and creates/updates CC_DB families, children, tags,
 * notes, and activity log entries.
 * 
 * @since 2.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CC_Landing_Bridge {

    private static $processing = false;

    /**
     * Register all hooks
     */
    public static function init() {
        // Only run if Landing Page plugin is active
        if ( ! post_type_exists( 'ptp26_lead' ) && ! function_exists( 'ptp26_s' ) ) {
            // Hook later in case LP registers late
            add_action( 'init', [ __CLASS__, 'late_init' ], 99 );
            return;
        }
        self::register_hooks();
    }

    public static function late_init() {
        self::register_hooks();
    }

    private static function register_hooks() {
        // Fire on new lead creation
        add_action( 'save_post_ptp26_lead', [ __CLASS__, 'on_lead_saved' ], 20, 3 );

        // Fire on any LP form submission hook (if LP fires its own)
        add_action( 'ptp26_lead_submitted',  [ __CLASS__, 'on_lead_submitted' ], 10, 2 );
        add_action( 'ptp26_form_submitted',  [ __CLASS__, 'on_lead_submitted' ], 10, 2 );

        // REST endpoint for manual sync
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    /**
     * Register REST routes
     */
    public static function register_routes() {
        register_rest_route( 'ptp-cc/v1', '/landing/sync', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'api_sync_all' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ] );

        register_rest_route( 'ptp-cc/v1', '/landing/leads', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'api_list_leads' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ] );

        register_rest_route( 'ptp-cc/v1', '/landing/stats', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'api_stats' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ] );
    }

    /**
     * Handle save_post_ptp26_lead
     */
    public static function on_lead_saved( $post_id, $post, $update ) {
        if ( self::$processing ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) ) return;
        if ( $post->post_status === 'auto-draft' ) return;

        self::$processing = true;
        self::sync_lead( $post_id );
        self::$processing = false;
    }

    /**
     * Handle LP plugin's own action hook
     */
    public static function on_lead_submitted( $post_id, $data = [] ) {
        if ( self::$processing ) return;
        self::$processing = true;
        self::sync_lead( $post_id, $data );
        self::$processing = false;
    }

    /**
     * Core: sync a single lead into CRM
     */
    public static function sync_lead( $post_id, $extra = [] ) {
        global $wpdb;

        if ( ! class_exists( 'CC_DB' ) ) return;

        // Extract all meta from LP lead
        $meta = self::get_lead_meta( $post_id );
        if ( empty( $meta ) ) return;

        $name     = $meta['_name']     ?? $meta['_parent_name'] ?? ($extra['name'] ?? '');
        $phone    = CC_DB::normalize_phone( $meta['_phone'] ?? ($extra['phone'] ?? '') );
        $email    = sanitize_email( $meta['_email'] ?? ($extra['email'] ?? '') );
        $kid_name = $meta['_kid_name'] ?? $meta['_child_name'] ?? ($extra['kid_name'] ?? '');
        $kid_age  = $meta['_kid_age']  ?? $meta['_age']        ?? ($extra['kid_age'] ?? '');
        $location = $meta['_location'] ?? $meta['_city']       ?? ($extra['location'] ?? '');
        $state    = $meta['_state']    ?? ($extra['state'] ?? '');
        $utm_src  = $meta['_utm_source']   ?? '';
        $utm_camp = $meta['_utm_campaign'] ?? '';
        $utm_med  = $meta['_utm_medium']   ?? '';
        $notes_raw = $meta['_notes']   ?? $meta['_message']    ?? '';
        $club     = $meta['_club']     ?? '';
        $position = $meta['_position'] ?? '';

        if ( empty( $name ) && empty( $phone ) && empty( $email ) ) return;

        // Find or create family in CC_DB
        $families_table = CC_DB::families();
        $family_id = null;

        // Match by phone first, then email
        if ( $phone ) {
            $family_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $families_table WHERE phone = %s LIMIT 1", $phone
            ) );
        }
        if ( ! $family_id && $email ) {
            $family_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $families_table WHERE email = %s LIMIT 1", $email
            ) );
        }

        if ( $family_id ) {
            // Update existing family
            $updates = [];
            if ( $name )     $updates['display_name'] = $name;
            if ( $phone )    $updates['phone']        = $phone;
            if ( $email )    $updates['email']        = $email;
            if ( $location ) $updates['city']         = $location;
            if ( $state )    $updates['state']        = $state;

            if ( ! empty( $updates ) ) {
                $wpdb->update( $families_table, $updates, [ 'id' => $family_id ] );
            }
        } else {
            // Create new family
            $wpdb->insert( $families_table, [
                'display_name' => $name,
                'phone'        => $phone,
                'email'        => $email,
                'city'         => $location,
                'state'        => $state,
                'notes'        => $notes_raw ? $notes_raw : null,
                'total_spent'  => 0,
            ] );
            $family_id = $wpdb->insert_id;
        }

        if ( ! $family_id ) return;

        // Add child if kid_name provided
        if ( $kid_name ) {
            $children_table = $wpdb->prefix . 'ptp_cc_children';
            if ( CC_DB::has_table( 'ptp_cc_children' ) ) {
                $existing = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM $children_table WHERE family_id = %d AND first_name = %s LIMIT 1",
                    $family_id, $kid_name
                ) );
                if ( ! $existing ) {
                    $wpdb->insert( $children_table, [
                        'family_id'  => $family_id,
                        'first_name' => $kid_name,
                        'age'        => $kid_age ? intval( $kid_age ) : null,
                        'club'       => $club ?: null,
                        'position'   => $position ?: null,
                    ] );
                }
            }
        }

        // Add tags
        $tags_table = $wpdb->prefix . 'ptp_cc_tags';
        if ( CC_DB::has_table( 'ptp_cc_tags' ) ) {
            $auto_tags = [ 'Landing Page' ];
            if ( $location ) $auto_tags[] = $location;
            if ( $utm_src )  $auto_tags[] = 'utm:' . $utm_src;
            if ( $utm_camp ) $auto_tags[] = 'campaign:' . $utm_camp;

            foreach ( $auto_tags as $tag ) {
                $exists = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM $tags_table WHERE family_id = %d AND tag_name = %s LIMIT 1",
                    $family_id, $tag
                ) );
                if ( ! $exists ) {
                    $wpdb->insert( $tags_table, [
                        'family_id' => $family_id,
                        'tag_name'  => $tag,
                    ] );
                }
            }
        }

        // Add note
        $notes_table = $wpdb->prefix . 'ptp_cc_notes';
        if ( CC_DB::has_table( 'ptp_cc_notes' ) ) {
            $note_parts = [ "Landing Page lead #$post_id" ];
            if ( $utm_src )  $note_parts[] = "Source: $utm_src";
            if ( $utm_camp ) $note_parts[] = "Campaign: $utm_camp";
            if ( $notes_raw ) $note_parts[] = "Note: $notes_raw";

            $wpdb->insert( $notes_table, [
                'family_id'  => $family_id,
                'note_text'  => implode( ' | ', $note_parts ),
                'note_type'  => 'auto',
                'created_by' => 'landing_bridge',
            ] );
        }

        // Store LP post_id reference in postmeta for reverse lookup
        update_post_meta( $post_id, '_ptp_cc_family_id', $family_id );

        // Log activity
        CC_DB::log(
            'landing_lead_synced',
            'family',
            $family_id,
            sprintf( 'Lead "%s" synced from Landing Page #%d (utm: %s/%s)', $name, $post_id, $utm_src, $utm_camp ),
            'landing_bridge'
        );

        // Fire action for other systems to hook into
        do_action( 'ptp_engine_landing_lead_synced', $family_id, $post_id, $meta );

        error_log( "[PTP-Engine] Landing Bridge: synced lead #$post_id -> family #$family_id ($name)" );
    }

    /**
     * Get all meta for a lead post
     */
    private static function get_lead_meta( $post_id ) {
        global $wpdb;
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE '\\_%'",
            $post_id
        ) );
        $meta = [];
        foreach ( $results as $r ) {
            $meta[ $r->meta_key ] = $r->meta_value;
        }
        return $meta;
    }

    /**
     * API: Sync all existing LP leads into CRM
     */
    public static function api_sync_all( $req ) {
        global $wpdb;

        $leads = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'ptp26_lead' AND post_status IN ('publish','private') ORDER BY post_date DESC"
        );

        $synced = 0;
        foreach ( $leads as $id ) {
            self::sync_lead( $id );
            $synced++;
        }

        return rest_ensure_response( [
            'synced' => $synced,
            'message' => "$synced landing page leads synced to CRM",
        ] );
    }

    /**
     * API: List recent LP leads with CRM match status
     */
    public static function api_list_leads( $req ) {
        global $wpdb;

        $leads = $wpdb->get_results(
            "SELECT p.ID, p.post_date,
                    MAX(CASE WHEN pm.meta_key='_name' THEN pm.meta_value END) as name,
                    MAX(CASE WHEN pm.meta_key='_phone' THEN pm.meta_value END) as phone,
                    MAX(CASE WHEN pm.meta_key='_email' THEN pm.meta_value END) as email,
                    MAX(CASE WHEN pm.meta_key='_kid_name' THEN pm.meta_value END) as kid_name,
                    MAX(CASE WHEN pm.meta_key='_location' THEN pm.meta_value END) as location,
                    MAX(CASE WHEN pm.meta_key='_utm_source' THEN pm.meta_value END) as utm_source,
                    MAX(CASE WHEN pm.meta_key='_utm_campaign' THEN pm.meta_value END) as utm_campaign,
                    MAX(CASE WHEN pm.meta_key='_ptp_cc_family_id' THEN pm.meta_value END) as family_id
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'ptp26_lead'
             GROUP BY p.ID
             ORDER BY p.post_date DESC
             LIMIT 100"
        );

        return rest_ensure_response( $leads );
    }

    /**
     * API: Landing page stats
     */
    public static function api_stats( $req ) {
        global $wpdb;

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'ptp26_lead'"
        );

        $synced = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm 
             JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
             WHERE p.post_type = 'ptp26_lead' AND pm.meta_key = '_ptp_cc_family_id'"
        );

        $today = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'ptp26_lead' AND post_date >= %s",
            current_time( 'Y-m-d' ) . ' 00:00:00'
        ) );

        $week = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'ptp26_lead' AND post_date >= %s",
            date( 'Y-m-d', strtotime( '-7 days' ) ) . ' 00:00:00'
        ) );

        // Top UTM sources
        $sources = $wpdb->get_results(
            "SELECT pm.meta_value as source, COUNT(*) as count
             FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE p.post_type = 'ptp26_lead' AND pm.meta_key = '_utm_source' AND pm.meta_value != ''
             GROUP BY pm.meta_value
             ORDER BY count DESC
             LIMIT 10"
        );

        return rest_ensure_response( [
            'total_leads'  => $total,
            'synced_to_crm' => $synced,
            'unsynced'     => $total - $synced,
            'today'        => $today,
            'this_week'    => $week,
            'top_sources'  => $sources,
        ] );
    }
}
