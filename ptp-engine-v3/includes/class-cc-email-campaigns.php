<?php
/**
 * CC_Email_Campaigns
 *
 * HTML email campaigns with family CRM segmentation.
 * Segments by stage, tags, location, kid age, source, LTV, date range.
 * Sends via wp_mail in batches of 25 (WP Cron).
 *
 * @since 2.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CC_Email_Campaigns {

    const NS = 'ptp-cc/v1';

    /* ═══════════════════════════════════════════
       TABLES
       ═══════════════════════════════════════════ */
    public static function campaigns_table() { return CC_DB::t( 'ptp_cc_email_campaigns' ); }
    public static function sends_table()     { return CC_DB::t( 'ptp_cc_email_sends' ); }

    public static function create_tables() {
        global $wpdb;
        $c = $wpdb->get_charset_collate();

        $wpdb->query( "CREATE TABLE IF NOT EXISTS " . self::campaigns_table() . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            subject VARCHAR(500) NOT NULL DEFAULT '',
            html_body LONGTEXT,
            plain_body TEXT,
            from_name VARCHAR(100) DEFAULT 'PTP Soccer Camps',
            from_email VARCHAR(200) DEFAULT '',
            reply_to VARCHAR(200) DEFAULT '',
            filters TEXT COMMENT 'JSON segment filters',
            status ENUM('draft','sending','paused','completed','cancelled') DEFAULT 'draft',
            audience_count INT DEFAULT 0,
            sent_count INT DEFAULT 0,
            failed_count INT DEFAULT 0,
            open_count INT DEFAULT 0,
            click_count INT DEFAULT 0,
            scheduled_at DATETIME DEFAULT NULL,
            started_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status)
        ) $c;" );

        $wpdb->query( "CREATE TABLE IF NOT EXISTS " . self::sends_table() . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_id BIGINT UNSIGNED NOT NULL,
            family_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(200) NOT NULL,
            recipient_name VARCHAR(200) DEFAULT '',
            status ENUM('pending','sent','failed','opened','clicked','bounced') DEFAULT 'pending',
            error_msg VARCHAR(500) DEFAULT '',
            sent_at DATETIME DEFAULT NULL,
            opened_at DATETIME DEFAULT NULL,
            clicked_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_campaign (campaign_id),
            INDEX idx_status (status),
            INDEX idx_family (family_id),
            INDEX idx_email (email)
        ) $c;" );
    }

    /* ═══════════════════════════════════════════
       REST ROUTES
       ═══════════════════════════════════════════ */
    public static function register_routes() {
        $perm = [ 'permission_callback' => function() { return current_user_can( 'manage_options' ); } ];

        // CRUD
        register_rest_route( self::NS, '/desktop/email-campaigns', array_merge( [ 'methods' => 'GET',  'callback' => [ __CLASS__, 'api_list' ] ], $perm ) );
        register_rest_route( self::NS, '/desktop/email-campaigns', array_merge( [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'api_create' ] ], $perm ) );
        register_rest_route( self::NS, '/desktop/email-campaigns/(?P<id>\d+)', array_merge( [ 'methods' => 'GET',    'callback' => [ __CLASS__, 'api_get' ] ], $perm ) );
        register_rest_route( self::NS, '/desktop/email-campaigns/(?P<id>\d+)', array_merge( [ 'methods' => 'PUT',    'callback' => [ __CLASS__, 'api_update' ] ], $perm ) );
        register_rest_route( self::NS, '/desktop/email-campaigns/(?P<id>\d+)', array_merge( [ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'api_delete' ] ], $perm ) );

        // Segment preview
        register_rest_route( self::NS, '/desktop/email-campaigns/segment-preview', array_merge( [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'api_segment_preview' ] ], $perm ) );

        // Send / pause
        register_rest_route( self::NS, '/desktop/email-campaigns/(?P<id>\d+)/send',  array_merge( [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'api_send' ] ], $perm ) );
        register_rest_route( self::NS, '/desktop/email-campaigns/(?P<id>\d+)/pause', array_merge( [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'api_pause' ] ], $perm ) );

        // Send stats
        register_rest_route( self::NS, '/desktop/email-campaigns/(?P<id>\d+)/stats', array_merge( [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'api_stats' ] ], $perm ) );

        // Test send (single email to admin)
        register_rest_route( self::NS, '/desktop/email-campaigns/(?P<id>\d+)/test', array_merge( [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'api_test_send' ] ], $perm ) );
    }

    /* ═══════════════════════════════════════════
       SEGMENT BUILDER — query families by filters
       ═══════════════════════════════════════════ */
    public static function build_segment( $filters ) {
        global $wpdb;
        $ft = CC_DB::families();
        $tt = $wpdb->prefix . 'ptp_cc_tags';
        $ct = $wpdb->prefix . 'ptp_cc_children';

        $where  = [ "f.email != '' AND f.email IS NOT NULL" ];
        $params = [];
        $joins  = [];

        // Stage filter (tags)
        if ( ! empty( $filters['stages'] ) ) {
            $stages = is_array( $filters['stages'] ) ? $filters['stages'] : [ $filters['stages'] ];
            if ( CC_DB::has_table( 'ptp_cc_tags' ) ) {
                $ph = implode( ',', array_fill( 0, count( $stages ), '%s' ) );
                $joins[] = "INNER JOIN $tt stg ON stg.family_id = f.id AND stg.tag_name IN ($ph)";
                $params = array_merge( $params, $stages );
            }
        }

        // Tags filter (custom tags, not stages)
        if ( ! empty( $filters['tags'] ) ) {
            $tags = is_array( $filters['tags'] ) ? $filters['tags'] : explode( ',', $filters['tags'] );
            $tags = array_map( 'trim', $tags );
            if ( CC_DB::has_table( 'ptp_cc_tags' ) ) {
                $ph = implode( ',', array_fill( 0, count( $tags ), '%s' ) );
                $joins[] = "INNER JOIN $tt ctg ON ctg.family_id = f.id AND ctg.tag_name IN ($ph)";
                $params = array_merge( $params, $tags );
            }
        }

        // Exclude tags
        if ( ! empty( $filters['exclude_tags'] ) ) {
            $xtags = is_array( $filters['exclude_tags'] ) ? $filters['exclude_tags'] : explode( ',', $filters['exclude_tags'] );
            $xtags = array_map( 'trim', $xtags );
            if ( CC_DB::has_table( 'ptp_cc_tags' ) ) {
                $ph = implode( ',', array_fill( 0, count( $xtags ), '%s' ) );
                $where[] = "f.id NOT IN (SELECT family_id FROM $tt WHERE tag_name IN ($ph))";
                $params = array_merge( $params, $xtags );
            }
        }

        // State / location
        if ( ! empty( $filters['state'] ) ) {
            $where[] = "f.state = %s";
            $params[] = sanitize_text_field( $filters['state'] );
        }

        // City
        if ( ! empty( $filters['city'] ) ) {
            $where[] = "f.city LIKE %s";
            $params[] = '%' . $wpdb->esc_like( $filters['city'] ) . '%';
        }

        // Kid age range
        if ( CC_DB::has_table( 'ptp_cc_children' ) ) {
            if ( ! empty( $filters['age_min'] ) || ! empty( $filters['age_max'] ) ) {
                $age_where = [];
                if ( ! empty( $filters['age_min'] ) ) {
                    $age_where[] = "ch.age >= %d";
                    $params[] = intval( $filters['age_min'] );
                }
                if ( ! empty( $filters['age_max'] ) ) {
                    $age_where[] = "ch.age <= %d";
                    $params[] = intval( $filters['age_max'] );
                }
                $joins[] = "INNER JOIN $ct ch ON ch.family_id = f.id AND " . implode( ' AND ', $age_where );
            }
        }

        // LTV range
        if ( ! empty( $filters['ltv_min'] ) ) {
            $where[] = "f.total_spent >= %f";
            $params[] = floatval( $filters['ltv_min'] );
        }
        if ( ! empty( $filters['ltv_max'] ) ) {
            $where[] = "f.total_spent <= %f";
            $params[] = floatval( $filters['ltv_max'] );
        }

        // Created date range
        if ( ! empty( $filters['created_after'] ) ) {
            $where[] = "f.created_at >= %s";
            $params[] = sanitize_text_field( $filters['created_after'] ) . ' 00:00:00';
        }
        if ( ! empty( $filters['created_before'] ) ) {
            $where[] = "f.created_at <= %s";
            $params[] = sanitize_text_field( $filters['created_before'] ) . ' 23:59:59';
        }

        // Search (name/email)
        if ( ! empty( $filters['search'] ) ) {
            $like = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
            $where[] = "(f.display_name LIKE %s OR f.email LIKE %s)";
            $params[] = $like;
            $params[] = $like;
        }

        $join_str = implode( ' ', $joins );
        $where_str = implode( ' AND ', $where );
        $sql = "SELECT DISTINCT f.id, f.display_name, f.email, f.phone, f.city, f.state, f.total_spent, f.created_at
                FROM $ft f $join_str WHERE $where_str ORDER BY f.created_at DESC LIMIT 5000";

        return $params
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) )
            : $wpdb->get_results( $sql );
    }

    /* ═══════════════════════════════════════════
       API HANDLERS
       ═══════════════════════════════════════════ */
    public static function api_list( $req ) {
        global $wpdb;
        $ct = self::campaigns_table();
        return rest_ensure_response( $wpdb->get_results( "SELECT * FROM $ct ORDER BY created_at DESC LIMIT 50" ) );
    }

    public static function api_create( $req ) {
        global $wpdb;
        $p = $req->get_json_params();
        $wpdb->insert( self::campaigns_table(), [
            'name'       => sanitize_text_field( $p['name'] ?? 'Untitled Campaign' ),
            'subject'    => sanitize_text_field( $p['subject'] ?? '' ),
            'html_body'  => wp_kses_post( $p['html_body'] ?? '' ),
            'plain_body' => sanitize_textarea_field( $p['plain_body'] ?? '' ),
            'from_name'  => sanitize_text_field( $p['from_name'] ?? 'PTP Soccer Camps' ),
            'from_email' => sanitize_email( $p['from_email'] ?? get_option( 'admin_email' ) ),
            'reply_to'   => sanitize_email( $p['reply_to'] ?? '' ),
            'filters'    => wp_json_encode( $p['filters'] ?? [] ),
        ] );
        $id = $wpdb->insert_id;

        // Calculate audience count
        $audience = self::build_segment( $p['filters'] ?? [] );
        $wpdb->update( self::campaigns_table(), [ 'audience_count' => count( $audience ) ], [ 'id' => $id ] );

        CC_DB::log( 'email_campaign_created', 'email_campaign', $id, sanitize_text_field( $p['name'] ?? '' ), 'desktop' );
        return rest_ensure_response( [ 'id' => $id, 'audience_count' => count( $audience ), 'ok' => true ] );
    }

    public static function api_get( $req ) {
        global $wpdb;
        $id = intval( $req['id'] );
        $c = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::campaigns_table() . " WHERE id = %d", $id ) );
        if ( ! $c ) return new \WP_Error( 'not_found', 'Campaign not found', [ 'status' => 404 ] );
        $c->filters = json_decode( $c->filters, true ) ?: [];

        // Get sends breakdown
        $st = self::sends_table();
        $c->sends = $wpdb->get_results( $wpdb->prepare(
            "SELECT status, COUNT(*) as cnt FROM $st WHERE campaign_id = %d GROUP BY status", $id
        ) );

        return rest_ensure_response( $c );
    }

    public static function api_update( $req ) {
        global $wpdb;
        $id = intval( $req['id'] );
        $p  = $req->get_json_params();

        $updates = [];
        if ( isset( $p['name'] ) )       $updates['name']       = sanitize_text_field( $p['name'] );
        if ( isset( $p['subject'] ) )     $updates['subject']    = sanitize_text_field( $p['subject'] );
        if ( isset( $p['html_body'] ) )   $updates['html_body']  = wp_kses_post( $p['html_body'] );
        if ( isset( $p['plain_body'] ) )  $updates['plain_body'] = sanitize_textarea_field( $p['plain_body'] );
        if ( isset( $p['from_name'] ) )   $updates['from_name']  = sanitize_text_field( $p['from_name'] );
        if ( isset( $p['from_email'] ) )  $updates['from_email'] = sanitize_email( $p['from_email'] );
        if ( isset( $p['reply_to'] ) )    $updates['reply_to']   = sanitize_email( $p['reply_to'] );
        if ( isset( $p['filters'] ) ) {
            $updates['filters'] = wp_json_encode( $p['filters'] );
            $audience = self::build_segment( $p['filters'] );
            $updates['audience_count'] = count( $audience );
        }

        if ( ! empty( $updates ) ) {
            $wpdb->update( self::campaigns_table(), $updates, [ 'id' => $id ] );
        }

        return rest_ensure_response( [ 'ok' => true ] );
    }

    public static function api_delete( $req ) {
        global $wpdb;
        $id = intval( $req['id'] );
        $wpdb->delete( self::campaigns_table(), [ 'id' => $id ] );
        $wpdb->delete( self::sends_table(), [ 'campaign_id' => $id ] );
        return rest_ensure_response( [ 'ok' => true ] );
    }

    public static function api_segment_preview( $req ) {
        $filters  = $req->get_json_params()['filters'] ?? [];
        $audience = self::build_segment( $filters );
        $preview  = array_slice( $audience, 0, 10 );
        return rest_ensure_response( [
            'count'   => count( $audience ),
            'preview' => array_map( function( $f ) {
                return [
                    'id'    => $f->id,
                    'name'  => $f->display_name,
                    'email' => $f->email,
                    'state' => $f->state,
                    'ltv'   => $f->total_spent,
                ];
            }, $preview ),
        ] );
    }

    /* ═══════════════════════════════════════════
       SEND / PAUSE
       ═══════════════════════════════════════════ */
    public static function api_send( $req ) {
        global $wpdb;
        $id = intval( $req['id'] );
        $ct = self::campaigns_table();
        $st = self::sends_table();

        $campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ct WHERE id = %d", $id ) );
        if ( ! $campaign ) return new \WP_Error( 'not_found', 'Not found', [ 'status' => 404 ] );
        if ( ! in_array( $campaign->status, [ 'draft', 'paused' ] ) ) {
            return new \WP_Error( 'bad_status', 'Campaign must be draft or paused', [ 'status' => 400 ] );
        }

        $filters  = json_decode( $campaign->filters, true ) ?: [];
        $audience = self::build_segment( $filters );

        if ( empty( $audience ) ) {
            return new \WP_Error( 'empty', 'No recipients match your segment', [ 'status' => 400 ] );
        }

        // Create send records for each recipient
        $existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $st WHERE campaign_id = %d", $id ) );
        if ( $existing === 0 ) {
            foreach ( $audience as $f ) {
                $wpdb->insert( $st, [
                    'campaign_id'    => $id,
                    'family_id'      => $f->id,
                    'email'          => $f->email,
                    'recipient_name' => $f->display_name,
                    'status'         => 'pending',
                ] );
            }
        }

        $wpdb->update( $ct, [
            'status'         => 'sending',
            'audience_count' => count( $audience ),
            'started_at'     => current_time( 'mysql' ),
        ], [ 'id' => $id ] );

        // Schedule immediate batch
        wp_schedule_single_event( time(), 'ptp_cc_email_batch', [ $id ] );

        CC_DB::log( 'email_campaign_started', 'email_campaign', $id,
            $campaign->name . ' — ' . count( $audience ) . ' recipients', 'desktop' );

        return rest_ensure_response( [ 'ok' => true, 'audience' => count( $audience ) ] );
    }

    public static function api_pause( $req ) {
        global $wpdb;
        $id = intval( $req['id'] );
        $wpdb->update( self::campaigns_table(), [ 'status' => 'paused' ], [ 'id' => $id ] );
        return rest_ensure_response( [ 'ok' => true ] );
    }

    public static function api_stats( $req ) {
        global $wpdb;
        $id = intval( $req['id'] );
        $st = self::sends_table();
        $stats = $wpdb->get_results( $wpdb->prepare(
            "SELECT status, COUNT(*) as cnt FROM $st WHERE campaign_id = %d GROUP BY status", $id
        ) );
        $result = [ 'pending' => 0, 'sent' => 0, 'failed' => 0, 'opened' => 0, 'clicked' => 0 ];
        foreach ( $stats as $s ) $result[ $s->status ] = (int) $s->cnt;
        $result['total'] = array_sum( $result );
        $result['open_rate'] = $result['sent'] > 0 ? round( $result['opened'] / $result['sent'] * 100, 1 ) : 0;
        return rest_ensure_response( $result );
    }

    public static function api_test_send( $req ) {
        global $wpdb;
        $id = intval( $req['id'] );
        $campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::campaigns_table() . " WHERE id = %d", $id ) );
        if ( ! $campaign ) return new \WP_Error( 'not_found', 'Not found', [ 'status' => 404 ] );

        $admin_email = get_option( 'admin_email' );
        $html = self::personalize( $campaign->html_body, (object) [
            'display_name' => 'Test Parent',
            'email'        => $admin_email,
            'id'           => 0,
        ] );

        $sent = self::send_one_email( $admin_email, $campaign->subject . ' [TEST]', $html, $campaign );
        return rest_ensure_response( [ 'sent' => $sent, 'to' => $admin_email ] );
    }

    /* ═══════════════════════════════════════════
       BATCH PROCESSOR (WP Cron)
       ═══════════════════════════════════════════ */
    public static function process_batch( $campaign_id ) {
        global $wpdb;
        $ct = self::campaigns_table();
        $st = self::sends_table();

        $campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ct WHERE id = %d", $campaign_id ) );
        if ( ! $campaign || $campaign->status !== 'sending' ) return;

        // Get next batch of 25 pending
        $batch = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $st WHERE campaign_id = %d AND status = 'pending' ORDER BY id ASC LIMIT 25",
            $campaign_id
        ) );

        if ( empty( $batch ) ) {
            // All done
            $wpdb->update( $ct, [
                'status'       => 'completed',
                'completed_at' => current_time( 'mysql' ),
                'sent_count'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $st WHERE campaign_id = %d AND status IN ('sent','opened','clicked')", $campaign_id ) ),
                'failed_count' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $st WHERE campaign_id = %d AND status = 'failed'", $campaign_id ) ),
            ], [ 'id' => $campaign_id ] );
            CC_DB::log( 'email_campaign_completed', 'email_campaign', $campaign_id, $campaign->name, 'cron' );
            return;
        }

        $sent = 0;
        $failed = 0;

        foreach ( $batch as $send ) {
            $html = self::personalize( $campaign->html_body, (object) [
                'display_name' => $send->recipient_name,
                'email'        => $send->email,
                'id'           => $send->family_id,
            ] );

            $ok = self::send_one_email( $send->email, $campaign->subject, $html, $campaign );

            if ( $ok ) {
                $wpdb->update( $st, [ 'status' => 'sent', 'sent_at' => current_time( 'mysql' ) ], [ 'id' => $send->id ] );
                $sent++;
            } else {
                $wpdb->update( $st, [ 'status' => 'failed', 'error_msg' => 'wp_mail failed' ], [ 'id' => $send->id ] );
                $failed++;
            }

            // Brief pause to avoid rate limits
            usleep( 100000 ); // 100ms
        }

        // Update campaign counts
        $wpdb->update( $ct, [
            'sent_count'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $st WHERE campaign_id = %d AND status IN ('sent','opened','clicked')", $campaign_id ) ),
            'failed_count' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $st WHERE campaign_id = %d AND status = 'failed'", $campaign_id ) ),
        ], [ 'id' => $campaign_id ] );

        // Schedule next batch in 5 seconds
        $remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $st WHERE campaign_id = %d AND status = 'pending'", $campaign_id ) );
        if ( $remaining > 0 ) {
            wp_schedule_single_event( time() + 5, 'ptp_cc_email_batch', [ $campaign_id ] );
        } else {
            // Mark complete
            $wpdb->update( $ct, [ 'status' => 'completed', 'completed_at' => current_time( 'mysql' ) ], [ 'id' => $campaign_id ] );
            CC_DB::log( 'email_campaign_completed', 'email_campaign', $campaign_id, $campaign->name . " — $sent sent, $failed failed", 'cron' );
        }
    }

    /* ═══════════════════════════════════════════
       EMAIL SENDING
       ═══════════════════════════════════════════ */
    private static function send_one_email( $to, $subject, $html, $campaign ) {
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        $from_name  = $campaign->from_name ?: 'PTP Soccer Camps';
        $from_email = $campaign->from_email ?: get_option( 'admin_email' );
        $headers[]  = "From: $from_name <$from_email>";
        $headers[]  = "List-Unsubscribe: <mailto:{$from_email}?subject=unsubscribe>";

        if ( $campaign->reply_to ) {
            $headers[] = "Reply-To: {$campaign->reply_to}";
        }

        // Append unsubscribe footer if not already present
        if ( stripos( $html, '{unsubscribe}' ) !== false ) {
            $unsub_text = '<p style="font-size:11px;color:#999;text-align:center;margin-top:24px;">If you no longer wish to receive these emails, reply with &quot;unsubscribe&quot; or <a href="mailto:' . esc_attr( $from_email ) . '?subject=unsubscribe" style="color:#999;">click here</a>.</p>';
            $html = str_replace( '{unsubscribe}', $unsub_text, $html );
        } elseif ( stripos( $html, 'unsubscribe' ) === false ) {
            $html .= '<p style="font-size:11px;color:#999;text-align:center;margin-top:24px;">PTP Soccer Camps &bull; If you no longer wish to receive these emails, reply with &quot;unsubscribe&quot; or email <a href="mailto:' . esc_attr( $from_email ) . '?subject=unsubscribe" style="color:#999;">' . esc_html( $from_email ) . '</a>.</p>';
        }

        return wp_mail( $to, $subject, $html, $headers );
    }

    /* ═══════════════════════════════════════════
       PERSONALIZATION
       ═══════════════════════════════════════════ */
    private static function personalize( $html, $family ) {
        $first = explode( ' ', $family->display_name ?: '' )[0] ?: 'there';
        $name  = $family->display_name ?: '';

        // Get child info if available
        $child_name = '';
        if ( $family->id && CC_DB::has_table( 'ptp_cc_children' ) ) {
            global $wpdb;
            $child = $wpdb->get_row( $wpdb->prepare(
                "SELECT first_name, age, club FROM {$wpdb->prefix}ptp_cc_children WHERE family_id = %d LIMIT 1",
                $family->id
            ) );
            if ( $child ) $child_name = $child->first_name;
        }

        return str_replace(
            [ '{first}', '{name}', '{child}', '{email}' ],
            [ esc_html( $first ), esc_html( $name ), esc_html( $child_name ?: 'your player' ), esc_html( $family->email ) ],
            $html
        );
    }

    /* ═══════════════════════════════════════════
       CRON HOOK
       ═══════════════════════════════════════════ */
    public static function register_cron() {
        add_action( 'ptp_cc_email_batch', [ __CLASS__, 'process_batch' ] );
    }
}
