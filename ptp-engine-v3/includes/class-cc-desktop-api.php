<?php
/**
 * CC_Desktop_API
 *
 * Purpose-built REST endpoints for the PTP Engine desktop app.
 * All routes under ptp-cc/v1/desktop/*
 *
 * @since 2.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CC_Desktop_API {

    const NS = 'ptp-cc/v1';

    public static function register_routes() {
        $perm = [
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ];

        // Dashboard (single call for all stats)
        register_rest_route( self::NS, '/desktop/dashboard', array_merge( [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_dashboard' ] ], $perm ) );

        // Families CRUD
        register_rest_route( self::NS, '/desktop/families', array_merge( [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'list_families' ] ], $perm ) );
        register_rest_route( self::NS, '/desktop/families', array_merge( [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'create_family' ] ], $perm ) );
        register_rest_route( self::NS, '/desktop/families/(?P<id>\d+)', array_merge( [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_family' ] ], $perm ) );
        register_rest_route( self::NS, '/desktop/families/(?P<id>\d+)', array_merge( [ 'methods' => 'PUT', 'callback' => [ __CLASS__, 'update_family' ] ], $perm ) );
        register_rest_route( self::NS, '/desktop/families/(?P<id>\d+)', array_merge( [ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'delete_family' ] ], $perm ) );

        // Conversations + messaging
        register_rest_route( self::NS, '/desktop/conversations', array_merge( [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'list_conversations' ] ], $perm ) );
        register_rest_route( self::NS, '/desktop/thread/(?P<phone>[^/]+)', array_merge( [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_thread' ] ], $perm ) );
        register_rest_route( self::NS, '/desktop/send', array_merge( [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'send_message' ] ], $perm ) );

        // Ad spend
        register_rest_route( self::NS, '/desktop/spend', array_merge( [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'list_spend' ] ], $perm ) );
        register_rest_route( self::NS, '/desktop/spend', array_merge( [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'create_spend' ] ], $perm ) );
        register_rest_route( self::NS, '/desktop/spend/(?P<id>\d+)', array_merge( [ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'delete_spend' ] ], $perm ) );

        // Activity
        register_rest_route( self::NS, '/desktop/activity', array_merge( [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'list_activity' ] ], $perm ) );

        // Digest
        register_rest_route( self::NS, '/desktop/digest/preview', array_merge( [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'preview_digest' ] ], $perm ) );
        register_rest_route( self::NS, '/desktop/digest/send', array_merge( [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'send_digest' ] ], $perm ) );

        // Landing page stats
        register_rest_route( self::NS, '/desktop/landing/stats', array_merge( [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'landing_stats' ] ], $perm ) );

        // Health check / setup status
        register_rest_route( self::NS, '/desktop/health', array_merge( [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_health' ] ], $perm ) );

        // Real-time poll — lightweight, called every 2s
        register_rest_route( self::NS, '/desktop/poll', array_merge( [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'poll' ] ], $perm ) );
    }

    /* ═══════════════════════════════════════════
       DASHBOARD — single call, all stats
       ═══════════════════════════════════════════ */
    public static function get_dashboard( $req ) {
        global $wpdb;

        $ft = CC_DB::families();
        $at = CC_DB::activity();
        $mt = CC_DB::op_msgs();

        $today    = current_time( 'Y-m-d' );
        $week_ago = date( 'Y-m-d', strtotime( '-7 days', current_time( 'timestamp' ) ) );

        // Families
        $total_families = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $ft" );
        $total_revenue  = (float) $wpdb->get_var( "SELECT COALESCE(SUM(total_spent), 0) FROM $ft" );
        $new_7d         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $ft WHERE created_at >= %s", "$week_ago 00:00:00" ) );

        // Messages
        $msgs_today_in  = 0;
        $msgs_today_out = 0;
        $unread         = 0;
        if ( CC_DB::has_table( 'ptp_cc_openphone_messages' ) ) {
            $msgs_today_in  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $mt WHERE direction='incoming' AND created_at >= %s", "$today 00:00:00" ) );
            $msgs_today_out = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $mt WHERE direction='outgoing' AND created_at >= %s", "$today 00:00:00" ) );
            $unread         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $mt WHERE direction='incoming' AND is_read = 0" );
        }

        // Ad spend
        $spend_all    = 0;
        $leads_all    = 0;
        $spend_week   = 0;
        if ( CC_DB::has_table( 'ptp_cc_ad_spend' ) ) {
            $st = CC_DB::ad_spend();
            $spend_all  = (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount), 0) FROM $st" );
            $leads_all  = (int) $wpdb->get_var( "SELECT COALESCE(SUM(conversions), 0) FROM $st" );
            $spend_week = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount), 0) FROM $st WHERE spend_date >= %s", $week_ago ) );
        }

        $cac = $leads_all > 0 ? round( $spend_all / $leads_all, 0 ) : 0;

        // Pipeline — from tags table
        $pipeline = [];
        $stage_names = [ 'New Lead', 'Contacted', 'Camp Registered', 'Camp Attended', '48hr Window', 'Training Converted', 'Recurring', 'VIP' ];
        $tags_t = $wpdb->prefix . 'ptp_cc_tags';
        if ( CC_DB::has_table( 'ptp_cc_tags' ) ) {
            foreach ( $stage_names as $s ) {
                $pipeline[ $s ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT family_id) FROM $tags_t WHERE tag_name = %s", $s ) );
            }
        }

        // Landing page
        $lp_total = 0;
        $lp_today = 0;
        if ( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'ptp26_lead' LIMIT 1" ) ) {
            $lp_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'ptp26_lead'" );
            $lp_today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'ptp26_lead' AND post_date >= %s", "$today 00:00:00" ) );
        }

        // 48hr window families
        $w48 = [];
        if ( CC_DB::has_table( 'ptp_cc_tags' ) ) {
            $w48 = $wpdb->get_results(
                "SELECT f.id, f.display_name, f.phone, f.email
                 FROM $tags_t t JOIN $ft f ON t.family_id = f.id
                 WHERE t.tag_name = '48hr Window'
                 ORDER BY f.created_at DESC"
            );
        }

        // Conversion rate
        $conv_stages  = [ 'Training Converted', 'Recurring', 'VIP' ];
        $denom_stages = [ 'Camp Attended', '48hr Window', 'Training Converted', 'Recurring', 'VIP' ];
        $conv_n = 0; $conv_d = 0;
        foreach ( $conv_stages as $s )  $conv_n += $pipeline[ $s ] ?? 0;
        foreach ( $denom_stages as $s ) $conv_d += $pipeline[ $s ] ?? 0;
        $conv_rate = $conv_d > 0 ? round( ( $conv_n / $conv_d ) * 100, 1 ) : 0;

        return rest_ensure_response( [
            'total_families'  => $total_families,
            'total_revenue'   => $total_revenue,
            'new_families_7d' => $new_7d,
            'msgs_today_in'   => $msgs_today_in,
            'msgs_today_out'  => $msgs_today_out,
            'unread'          => $unread,
            'spend_all'       => $spend_all,
            'spend_week'      => $spend_week,
            'leads_all'       => $leads_all,
            'cac'             => $cac,
            'pipeline'        => $pipeline,
            'conv_rate'       => $conv_rate,
            'lp_total'        => $lp_total,
            'lp_today'        => $lp_today,
            'w48_families'    => $w48,
        ] );
    }

    /* ═══════════════════════════════════════════
       FAMILIES CRUD
       ═══════════════════════════════════════════ */
    public static function list_families( $req ) {
        global $wpdb;
        $ft = CC_DB::families();
        $search = sanitize_text_field( $req->get_param( 'search' ) ?? '' );
        $stage  = sanitize_text_field( $req->get_param( 'stage' ) ?? '' );

        $sql = "SELECT f.*, GROUP_CONCAT(DISTINCT t.tag_name SEPARATOR '||') as tags_str
                FROM $ft f
                LEFT JOIN {$wpdb->prefix}ptp_cc_tags t ON f.id = t.family_id";

        $where = [];
        $params = [];

        if ( $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = "(f.display_name LIKE %s OR f.phone LIKE %s OR f.email LIKE %s)";
            $params = array_merge( $params, [ $like, $like, $like ] );
        }

        if ( $stage ) {
            $where[] = "t.tag_name = %s";
            $params[] = $stage;
        }

        if ( ! empty( $where ) ) {
            $sql .= " WHERE " . implode( ' AND ', $where );
        }

        $sql .= " GROUP BY f.id ORDER BY f.created_at DESC LIMIT 5000";

        $families = $params ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) ) : $wpdb->get_results( $sql );

        // Enrich with children
        foreach ( $families as &$f ) {
            $f->tags = $f->tags_str ? explode( '||', $f->tags_str ) : [];
            unset( $f->tags_str );

            if ( CC_DB::has_table( 'ptp_cc_children' ) ) {
                $f->children = $wpdb->get_results( $wpdb->prepare(
                    "SELECT first_name, age, club, position FROM {$wpdb->prefix}ptp_cc_children WHERE family_id = %d", $f->id
                ) );
            } else {
                $f->children = [];
            }

            if ( CC_DB::has_table( 'ptp_cc_notes' ) ) {
                $f->notes = $wpdb->get_col( $wpdb->prepare(
                    "SELECT note_text FROM {$wpdb->prefix}ptp_cc_notes WHERE family_id = %d ORDER BY created_at DESC LIMIT 20", $f->id
                ) );
            } else {
                $f->notes = [];
            }
        }

        return rest_ensure_response( $families );
    }

    public static function create_family( $req ) {
        global $wpdb;
        $ft = CC_DB::families();
        $p  = $req->get_json_params();

        $wpdb->insert( $ft, [
            'display_name' => sanitize_text_field( $p['name'] ?? '' ),
            'phone'        => CC_DB::normalize_phone( $p['phone'] ?? '' ),
            'email'        => sanitize_email( $p['email'] ?? '' ),
            'city'         => sanitize_text_field( $p['city'] ?? '' ),
            'state'        => sanitize_text_field( $p['state'] ?? '' ),
            'notes'        => sanitize_textarea_field( $p['notes'] ?? '' ),
        ] );

        $id = $wpdb->insert_id;

        // Add child
        if ( ! empty( $p['kid_name'] ) && CC_DB::has_table( 'ptp_cc_children' ) ) {
            $wpdb->insert( $wpdb->prefix . 'ptp_cc_children', [
                'family_id'  => $id,
                'first_name' => sanitize_text_field( $p['kid_name'] ),
                'age'        => intval( $p['kid_age'] ?? 0 ),
                'club'       => sanitize_text_field( $p['club'] ?? '' ),
                'position'   => sanitize_text_field( $p['position'] ?? '' ),
            ] );
        }

        // Add tags
        if ( ! empty( $p['tags'] ) && CC_DB::has_table( 'ptp_cc_tags' ) ) {
            $tags = is_array( $p['tags'] ) ? $p['tags'] : explode( ',', $p['tags'] );
            foreach ( $tags as $tag ) {
                $tag = trim( sanitize_text_field( $tag ) );
                if ( $tag ) {
                    $wpdb->insert( $wpdb->prefix . 'ptp_cc_tags', [ 'family_id' => $id, 'tag_name' => $tag ] );
                }
            }
        }

        // Add stage as tag
        if ( ! empty( $p['stage'] ) && CC_DB::has_table( 'ptp_cc_tags' ) ) {
            $wpdb->insert( $wpdb->prefix . 'ptp_cc_tags', [ 'family_id' => $id, 'tag_name' => $p['stage'] ] );
        }

        CC_DB::log( 'family_created', 'family', $id, 'Created via desktop app: ' . ($p['name'] ?? ''), 'desktop' );

        return rest_ensure_response( [ 'id' => $id, 'ok' => true ] );
    }

    public static function get_family( $req ) {
        global $wpdb;
        $id = intval( $req['id'] );
        $ft = CC_DB::families();

        $f = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ft WHERE id = %d", $id ) );
        if ( ! $f ) return new \WP_Error( 'not_found', 'Family not found', [ 'status' => 404 ] );

        // Tags
        $f->tags = CC_DB::has_table( 'ptp_cc_tags' )
            ? $wpdb->get_col( $wpdb->prepare( "SELECT tag_name FROM {$wpdb->prefix}ptp_cc_tags WHERE family_id = %d", $id ) )
            : [];

        // Children
        $f->children = CC_DB::has_table( 'ptp_cc_children' )
            ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ptp_cc_children WHERE family_id = %d", $id ) )
            : [];

        // Notes
        $f->notes = CC_DB::has_table( 'ptp_cc_notes' )
            ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ptp_cc_notes WHERE family_id = %d ORDER BY created_at DESC", $id ) )
            : [];

        // Messages
        if ( CC_DB::has_table( 'ptp_cc_openphone_messages' ) && $f->phone ) {
            $f->messages = $wpdb->get_results( $wpdb->prepare(
                "SELECT direction, body, created_at FROM " . CC_DB::op_msgs() . " WHERE phone = %s ORDER BY created_at DESC LIMIT 20",
                CC_DB::normalize_phone( $f->phone )
            ) );
        } else {
            $f->messages = [];
        }

        // Revenue
        if ( CC_DB::has_table( 'ptp_cc_revenue' ) ) {
            $f->ltv = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}ptp_cc_revenue WHERE family_id = %d AND status = 'completed'", $id
            ) );
        }

        return rest_ensure_response( $f );
    }

    public static function update_family( $req ) {
        global $wpdb;
        $id = intval( $req['id'] );
        $ft = CC_DB::families();
        $p  = $req->get_json_params();

        $updates = [];
        if ( isset( $p['name'] ) )  $updates['display_name'] = sanitize_text_field( $p['name'] );
        if ( isset( $p['phone'] ) ) $updates['phone']        = CC_DB::normalize_phone( $p['phone'] );
        if ( isset( $p['email'] ) ) $updates['email']        = sanitize_email( $p['email'] );
        if ( isset( $p['city'] ) )  $updates['city']         = sanitize_text_field( $p['city'] );
        if ( isset( $p['state'] ) ) $updates['state']        = sanitize_text_field( $p['state'] );

        if ( ! empty( $updates ) ) {
            $wpdb->update( $ft, $updates, [ 'id' => $id ] );
        }

        // Update stage tag
        if ( isset( $p['stage'] ) && CC_DB::has_table( 'ptp_cc_tags' ) ) {
            $stage_names = [ 'New Lead', 'Contacted', 'Camp Registered', 'Camp Attended', '48hr Window', 'Training Converted', 'Recurring', 'VIP' ];
            // Remove old stage tags
            $placeholders = implode( ',', array_fill( 0, count( $stage_names ), '%s' ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}ptp_cc_tags WHERE family_id = %d AND tag_name IN ($placeholders)",
                array_merge( [ $id ], $stage_names )
            ) );
            // Add new stage
            $wpdb->insert( $wpdb->prefix . 'ptp_cc_tags', [ 'family_id' => $id, 'tag_name' => sanitize_text_field( $p['stage'] ) ] );
        }

        // Add note
        if ( ! empty( $p['note'] ) && CC_DB::has_table( 'ptp_cc_notes' ) ) {
            $wpdb->insert( $wpdb->prefix . 'ptp_cc_notes', [
                'family_id'  => $id,
                'note_text'  => sanitize_textarea_field( $p['note'] ),
                'note_type'  => 'manual',
                'created_by' => 'desktop',
            ] );
        }

        CC_DB::log( 'family_updated', 'family', $id, 'Updated via desktop: ' . json_encode( array_keys( $p ) ), 'desktop' );

        return rest_ensure_response( [ 'ok' => true ] );
    }

    public static function delete_family( $req ) {
        global $wpdb;
        $id = intval( $req['id'] );
        $ft = CC_DB::families();

        $name = $wpdb->get_var( $wpdb->prepare( "SELECT display_name FROM $ft WHERE id = %d", $id ) );
        $wpdb->delete( $ft, [ 'id' => $id ] );

        // Clean related
        if ( CC_DB::has_table( 'ptp_cc_tags' ) )     $wpdb->delete( $wpdb->prefix . 'ptp_cc_tags', [ 'family_id' => $id ] );
        if ( CC_DB::has_table( 'ptp_cc_children' ) )  $wpdb->delete( $wpdb->prefix . 'ptp_cc_children', [ 'family_id' => $id ] );
        if ( CC_DB::has_table( 'ptp_cc_notes' ) )     $wpdb->delete( $wpdb->prefix . 'ptp_cc_notes', [ 'family_id' => $id ] );

        CC_DB::log( 'family_deleted', 'family', $id, "Deleted $name via desktop", 'desktop' );

        return rest_ensure_response( [ 'ok' => true ] );
    }

    /* ═══════════════════════════════════════════
       CONVERSATIONS + MESSAGING
       ═══════════════════════════════════════════ */
    public static function list_conversations( $req ) {
        global $wpdb;
        $mt = CC_DB::op_msgs();
        if ( ! CC_DB::has_table( 'ptp_cc_openphone_messages' ) ) {
            return rest_ensure_response( [] );
        }

        $conversations = $wpdb->get_results(
            "SELECT m.phone,
                    MAX(m.created_at) as last_ts,
                    SUM(CASE WHEN m.direction='incoming' AND m.is_read=0 THEN 1 ELSE 0 END) as unread,
                    f.id as family_id, f.display_name, f.email
             FROM $mt m
             LEFT JOIN " . CC_DB::families() . " f ON f.phone = m.phone
             GROUP BY m.phone
             ORDER BY last_ts DESC
             LIMIT 100"
        );

        // Get last message for each
        foreach ( $conversations as &$c ) {
            $c->last_message = $wpdb->get_var( $wpdb->prepare(
                "SELECT body FROM $mt WHERE phone = %s ORDER BY created_at DESC LIMIT 1", $c->phone
            ) );
        }

        return rest_ensure_response( $conversations );
    }

    public static function get_thread( $req ) {
        global $wpdb;
        $phone = CC_DB::normalize_phone( $req['phone'] );
        $mt    = CC_DB::op_msgs();

        if ( ! CC_DB::has_table( 'ptp_cc_openphone_messages' ) ) {
            return rest_ensure_response( [] );
        }

        $messages = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, direction, body, created_at, is_read FROM $mt WHERE phone = %s ORDER BY created_at ASC LIMIT 200",
            $phone
        ) );

        // Mark as read
        $wpdb->query( $wpdb->prepare( "UPDATE $mt SET is_read = 1 WHERE phone = %s AND direction = 'incoming'", $phone ) );

        return rest_ensure_response( $messages );
    }

    public static function send_message( $req ) {
        $p     = $req->get_json_params();
        $phone = CC_DB::normalize_phone( $p['phone'] ?? '' );
        $body  = sanitize_textarea_field( $p['body'] ?? '' );

        if ( ! $phone || ! $body ) {
            return new \WP_Error( 'missing_data', 'Phone and body required', [ 'status' => 400 ] );
        }

        $result = CC_DB::send_sms( $phone, $body );
        CC_DB::log( 'sms_sent', 'message', null, "Desktop SMS to $phone: " . substr( $body, 0, 60 ), 'desktop' );

        return rest_ensure_response( [ 'ok' => ! is_wp_error( $result ), 'result' => $result ] );
    }

    /* ═══════════════════════════════════════════
       AD SPEND
       ═══════════════════════════════════════════ */
    public static function list_spend( $req ) {
        global $wpdb;
        if ( ! CC_DB::has_table( 'ptp_cc_ad_spend' ) ) return rest_ensure_response( [] );
        $st = CC_DB::ad_spend();
        $limit = intval( $req->get_param( 'limit' ) ?: 100 );
        return rest_ensure_response( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $st ORDER BY spend_date DESC LIMIT %d", $limit ) ) );
    }

    public static function create_spend( $req ) {
        global $wpdb;
        if ( ! CC_DB::has_table( 'ptp_cc_ad_spend' ) ) return new \WP_Error( 'no_table', 'Ad spend table not found', [ 'status' => 500 ] );
        $st = CC_DB::ad_spend();
        $p  = $req->get_json_params();

        $wpdb->insert( $st, [
            'spend_date'  => sanitize_text_field( $p['date'] ?? current_time( 'Y-m-d' ) ),
            'platform'    => sanitize_text_field( $p['platform'] ?? 'meta' ),
            'amount'      => floatval( $p['amount'] ?? 0 ),
            'campaign'    => sanitize_text_field( $p['campaign'] ?? '' ),
            'clicks'      => intval( $p['clicks'] ?? 0 ),
            'conversions' => intval( $p['leads'] ?? 0 ),
        ] );

        $id = $wpdb->insert_id;
        CC_DB::log( 'spend_logged', 'ad_spend', $id, "\${$p['amount']} {$p['platform']} \"{$p['campaign']}\"", 'desktop' );

        return rest_ensure_response( [ 'id' => $id, 'ok' => true ] );
    }

    public static function delete_spend( $req ) {
        global $wpdb;
        if ( ! CC_DB::has_table( 'ptp_cc_ad_spend' ) ) return new \WP_Error( 'no_table', 'Not found', [ 'status' => 500 ] );
        $wpdb->delete( CC_DB::ad_spend(), [ 'id' => intval( $req['id'] ) ] );
        return rest_ensure_response( [ 'ok' => true ] );
    }

    /* ═══════════════════════════════════════════
       ACTIVITY
       ═══════════════════════════════════════════ */
    public static function list_activity( $req ) {
        global $wpdb;
        $at = CC_DB::activity();
        $limit = intval( $req->get_param( 'limit' ) ?: 30 );
        return rest_ensure_response( $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $at ORDER BY created_at DESC LIMIT %d", $limit
        ) ) );
    }

    /* ═══════════════════════════════════════════
       DIGEST
       ═══════════════════════════════════════════ */
    public static function preview_digest( $req ) {
        if ( ! class_exists( 'CC_Daily_Digest' ) ) return new \WP_Error( 'missing', 'Digest class not loaded', [ 'status' => 500 ] );
        $data = CC_Daily_Digest::collect_data();
        return rest_ensure_response( [
            'text' => CC_Daily_Digest::build_text( $data ),
            'data' => $data,
        ] );
    }

    public static function send_digest( $req ) {
        if ( ! class_exists( 'CC_Daily_Digest' ) ) return new \WP_Error( 'missing', 'Digest class not loaded', [ 'status' => 500 ] );
        $sent = CC_Daily_Digest::send_digest();
        return rest_ensure_response( [ 'sent' => (bool) $sent ] );
    }

    /* ═══════════════════════════════════════════
       LANDING PAGE
       ═══════════════════════════════════════════ */
    public static function landing_stats( $req ) {
        if ( class_exists( 'CC_Landing_Bridge' ) ) {
            return CC_Landing_Bridge::api_stats( $req );
        }
        return rest_ensure_response( [ 'total_leads' => 0, 'today' => 0 ] );
    }

    /* ═══════════════════════════════════════════
       HEALTH CHECK
       ═══════════════════════════════════════════ */
    public static function get_health( $req ) {
        global $wpdb;

        $tables_needed = [
            'ptp_cc_families', 'ptp_cc_children', 'ptp_cc_tags', 'ptp_cc_notes',
            'ptp_cc_openphone_messages', 'ptp_cc_ad_spend', 'ptp_cc_activity_log', 'ptp_cc_revenue',
            'ptp_cc_email_campaigns', 'ptp_cc_email_sends',
        ];
        $tables = [];
        foreach ( $tables_needed as $t ) {
            $tables[ $t ] = CC_DB::has_table( $t );
        }

        $op_key  = get_option( 'ptp_openphone_api_key', '' ) ?: get_option( 'ptp_cc_openphone_api_key', '' );
        $op_from = get_option( 'ptp_openphone_from', '' ) ?: get_option( 'ptp_cc_openphone_phone_id', '' );

        $issues = [];
        foreach ( $tables as $t => $exists ) {
            if ( ! $exists ) $issues[] = "Missing table: $t — deactivate and reactivate the plugin";
        }
        if ( ! $op_key )  $issues[] = 'OpenPhone API key not set — go to WP Admin > PTP Engine settings';
        if ( ! $op_from ) $issues[] = 'OpenPhone phone number not configured';

        // Check is_read column
        $opm = CC_DB::op_msgs();
        $has_is_read = $wpdb->get_results( "SHOW COLUMNS FROM $opm LIKE 'is_read'" );
        if ( empty( $has_is_read ) ) $issues[] = 'Missing is_read column on messages — deactivate and reactivate';

        return rest_ensure_response( [
            'ok'      => empty( $issues ),
            'version' => defined( 'PTP_ENGINE_VER' ) ? PTP_ENGINE_VER : 'unknown',
            'tables'  => $tables,
            'openphone_key_set'  => ! empty( $op_key ),
            'openphone_from_set' => ! empty( $op_from ),
            'issues'  => $issues,
        ] );
    }

    /* ═══════════════════════════════════════════
       REAL-TIME POLL — called every 2s
       Single indexed query, ~1ms response time
       ═══════════════════════════════════════════ */
    public static function poll( $req ) {
        global $wpdb;
        $mt = CC_DB::op_msgs();

        if ( ! CC_DB::has_table( 'ptp_cc_openphone_messages' ) ) {
            return rest_ensure_response( [ 'new_msgs' => [], 'unread' => 0, 'last_id' => 0 ] );
        }

        $since_id = intval( $req->get_param( 'since_id' ) ?: 0 );
        $phone    = sanitize_text_field( $req->get_param( 'phone' ) ?? '' );

        $result = [
            'unread'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $mt WHERE direction='incoming' AND is_read = 0" ),
            'last_id' => (int) $wpdb->get_var( "SELECT MAX(id) FROM $mt" ),
        ];

        // If watching a specific thread, return new messages since last seen ID
        if ( $phone && $since_id > 0 ) {
            $phone = CC_DB::normalize_phone( $phone );
            $result['new_msgs'] = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, direction, body, created_at, is_read FROM $mt WHERE phone = %s AND id > %d ORDER BY created_at ASC",
                $phone, $since_id
            ) );

            // Mark new incoming as read since user is watching
            if ( ! empty( $result['new_msgs'] ) ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE $mt SET is_read = 1 WHERE phone = %s AND direction = 'incoming' AND id > %d",
                    $phone, $since_id
                ) );
            }
        } else {
            $result['new_msgs'] = [];
        }

        // Global: any new messages at all since last check?
        if ( $since_id > 0 ) {
            $result['has_new'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $mt WHERE id > %d", $since_id
            ) ) > 0;
        } else {
            $result['has_new'] = false;
        }

        return rest_ensure_response( $result );
    }
}
