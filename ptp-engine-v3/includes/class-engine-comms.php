<?php
/**
 * PTP Engine — Comms Layer
 * Real-time SMS messaging, AI drafts, Customer 360, funnel analytics.
 * Refactored from PTP Comms Hub v8 into PTP Engine.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class PTP_Engine_Comms {

    private $openphone_key;
    private $openphone_from;
    private $default_phone = '+16106714778';

    public function __construct() {
        $this->openphone_key  = $this->op_key();
        $this->openphone_from = $this->op_from();
    }

    /* ─── Plugin Detection ─── */
    public function cc() { return true; } // Always true — we ARE the engine
    public function tp() { return class_exists( 'PTP_Database' ) || class_exists( 'PTP_REST' ); }
    public function lp() { return function_exists( 'ptp26_s' ); }
    public function gw() { return class_exists( 'PTP_Giveaway' ); }

    public function is_openphone_connected() { return ! empty( $this->openphone_key ); }

    private function op_key() {
        if ( defined( 'PTP_OP_KEY' ) && PTP_OP_KEY ) return PTP_OP_KEY;
        return get_option( 'ptp_ch_openphone_key', '' );
    }
    private function op_from() {
        return get_option( 'ptp_ch_openphone_from', '' );
    }

    /* ─── OpenPhone HTTP — raw key in Authorization header (NOT Bearer) ─── */
    private function op_request( $method, $endpoint, $body = null ) {
        if ( empty( $this->openphone_key ) ) {
            return new WP_Error( 'no_op_key', 'OpenPhone key not configured' );
        }
        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => $this->openphone_key,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 15,
        ];
        if ( $body ) {
            $args['body'] = is_array( $body ) ? wp_json_encode( $body ) : $body;
        }
        $response = wp_remote_request( 'https://api.openphone.com/v1' . $endpoint, $args );
        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 ) {
            return new WP_Error( 'op_error', "OpenPhone API {$code}", $data );
        }
        return $data;
    }

    /* ═══════════════════════════════════════════════════════════════════
       ADMIN MENU  (priority 50 — fires AFTER Command Center at 10)
       ═══════════════════════════════════════════════════════════════════ */

    /* ═══════════════════════════════════════════════════════════════════
       PWA — called from main plugin file on template_redirect
       ═══════════════════════════════════════════════════════════════════ */
    public function render_pwa() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }
        status_header( 200 );
        header( 'Content-Type: text/html; charset=UTF-8' );
        echo $this->pwa_html();
    }

    private function pwa_html() {
        $config = wp_json_encode( [
            'rest_url'            => rest_url( 'ptp-comms/v1/' ),
            'nonce'               => wp_create_nonce( 'wp_rest' ),
            'openphone_connected' => ! empty( $this->openphone_key ),
            'version'             => PTP_CH_VER,
            'phone'               => $this->default_phone,
            'plugins'             => [ 'cc' => $this->cc(), 'tp' => $this->tp(), 'lp' => $this->lp(), 'gw' => $this->gw() ],
        ], JSON_UNESCAPED_SLASHES );

        $manifest = wp_json_encode( [
            'name'             => 'PTP Comms Hub',
            'short_name'       => 'Comms',
            'display'          => 'standalone',
            'start_url'        => '/ptp-comms/',
            'scope'            => '/ptp-comms/',
            'theme_color'      => '#FCB900',
            'background_color' => '#0A0A0A',
            'icons'            => [ [ 'src' => $this->icon_svg(), 'sizes' => '192x192', 'type' => 'image/svg+xml' ] ],
        ], JSON_UNESCAPED_SLASHES );

        $js = PTP_CH_URL . 'assets/comms-app.js?v=' . PTP_CH_VER;

        return <<<HTML
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="#FCB900"><meta name="apple-mobile-web-app-capable" content="yes">
<title>PTP Comms Hub</title>
<link rel="manifest" href="data:application/manifest+json,{$manifest}">
<link rel="icon" href="{$this->icon_svg()}">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',sans-serif;background:#0A0A0A;color:#fff;overflow:hidden}#ptp-comms-root{width:100vw;height:100vh}</style>
</head><body>
<div id="ptp-comms-root"></div>
<script>window.PTP_COMMS={$config};</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/react/18.2.0/umd/react.production.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/react-dom/18.2.0/umd/react-dom.production.min.js"></script>
<script src="{$js}"></script>
</body></html>
HTML;
    }

    private function icon_svg() {
        return 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 192 192"><rect width="192" height="192" rx="24" fill="#0A0A0A"/><rect x="6" y="6" width="180" height="180" rx="20" fill="#FCB900"/><text x="96" y="126" font-family="Oswald" font-size="72" font-weight="700" text-anchor="middle" fill="#0A0A0A">CH</text></svg>' );
    }


    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix . 'ptp_ch_';

        $sql = [];

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}templates (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            category VARCHAR(50) DEFAULT 'quick_reply',
            body LONGTEXT NOT NULL,
            variables TEXT,
            usage_count INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_category (category)
        ) {$c};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}drafts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            family_id BIGINT UNSIGNED,
            phone VARCHAR(20),
            channel VARCHAR(20) DEFAULT 'sms',
            draft_body LONGTEXT NOT NULL,
            ai_context LONGTEXT,
            status VARCHAR(20) DEFAULT 'pending',
            approved_by BIGINT UNSIGNED,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_status (status),
            KEY idx_phone (phone)
        ) {$c};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}scheduled (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            phone VARCHAR(20) NOT NULL,
            body LONGTEXT NOT NULL,
            send_at DATETIME NOT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            sent_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_send_at (send_at),
            KEY idx_status (status)
        ) {$c};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}settings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            option_name VARCHAR(191) UNIQUE NOT NULL,
            option_value LONGTEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) {$c};";

        foreach ( $sql as $q ) {
            dbDelta( $q );
        }

        self::seed_templates();
    }

    private static function seed_templates() {
        global $wpdb;
        $t = $wpdb->prefix . 'ptp_ch_templates';

        if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ) > 0 ) return;

        $templates = [
            [ 'New Lead Welcome',    'welcome',    "Hey {name}! This is Luke from PTP Soccer Camps. Thanks for checking us out - what questions can I answer for you?" ],
            [ 'Camp Info',           'info',       "Great question! Our camps run Mon-Fri with current MLS and D1 college players as coaches. Kids ages 6-14. Check details at ptpsummercamps.com" ],
            [ 'Post-Camp Follow Up', 'followup',   "Hey {name}! Hope {child} had an amazing time at camp this week. We'd love to keep the momentum going - want to hear about our private training?" ],
            [ '48hr Window',         'conversion', "Hi {name}! Quick note - we have a special offer for camp families who book training within 48 hours. Want details?" ],
            [ 'Pricing',            'info',       "Our 2-session intro package is $160 total. That's 2 private sessions with a current MLS or D1 player. No commitment after that!" ],
            [ 'Schedule',           'info',       "We train 7 days a week across PA, NJ, DE, MD, and NY. What area works best for {child}?" ],
            [ 'Missed Call',        'recovery',   "Hey! Sorry I missed your call. This is Luke from PTP - what can I help with?" ],
            [ 'Tryout Prep',        'conversion', "Tryouts coming up? Our coaches know exactly what club coaches look for. We can put together a focused plan for {child}. Interested?" ],
        ];

        foreach ( $templates as $tpl ) {
            $wpdb->insert( $t, [
                'name'       => $tpl[0],
                'category'   => $tpl[1],
                'body'       => $tpl[2],
                'variables'  => wp_json_encode( [ '{name}', '{child}' ] ),
                'created_at' => current_time( 'mysql' ),
            ] );
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
       PHONE HELPERS
       ═══════════════════════════════════════════════════════════════════ */
    private function normalize_phone( $phone ) {
        $phone = preg_replace( '/[^\d+]/', '', $phone );
        if ( strlen( $phone ) === 10 && ! str_starts_with( $phone, '+' ) ) $phone = '+1' . $phone;
        if ( strlen( $phone ) === 11 && str_starts_with( $phone, '1' ) )   $phone = '+' . $phone;
        return $phone;
    }

    private function fmt_phone( $phone ) {
        $d = preg_replace( '/\D/', '', $phone );
        if ( strlen( $d ) === 11 && $d[0] === '1' ) $d = substr( $d, 1 );
        if ( strlen( $d ) === 10 ) return '(' . substr( $d, 0, 3 ) . ') ' . substr( $d, 3, 3 ) . '-' . substr( $d, 6 );
        return $phone;
    }

    /* ═══════════════════════════════════════════════════════════════════
       CONTACT LOOKUP  (8 sources)
       ═══════════════════════════════════════════════════════════════════ */
    private function lookup_name( $phone ) {
        $phone  = $this->normalize_phone( $phone );
        $last10 = substr( preg_replace( '/\D/', '', $phone ), -10 );
        global $wpdb;

        // 1. CC Families
        if ( $this->cc() ) {
            $name = $wpdb->get_var( $wpdb->prepare( "SELECT CONCAT(parent_first, ' ', parent_last) FROM {$wpdb->prefix}ptp_cc_families WHERE phone = %s LIMIT 1", $phone ) );
            if ( $name && trim( $name ) ) return trim( $name );
        }

        // 2. Training Platform
        if ( $this->tp() ) {
            $name = $wpdb->get_var( $wpdb->prepare(
                "SELECT display_name FROM {$wpdb->prefix}ptp_parents WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(phone,'-',''),' ',''),'(',''),')',''),10) = %s LIMIT 1", $last10
            ) );
            if ( $name ) return $name;
        }

        // 3. Landing Page (uses CPT ptp26_lead + postmeta)
        if ( $this->lp() ) {
            $name = $wpdb->get_var( $wpdb->prepare(
                "SELECT MAX(CASE WHEN pm.meta_key='_name' THEN pm.meta_value WHEN pm.meta_key='_kid_name' THEN pm.meta_value END)
                 FROM {$wpdb->prefix}posts p
                 JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id
                 WHERE p.post_type = 'ptp26_lead'
                 AND p.ID IN (SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = '_phone' AND meta_value = %s)
                 LIMIT 1", $phone
            ) );
            if ( $name ) return $name;
        }

        // 4. Giveaway
        if ( $this->gw() ) {
            $name = $wpdb->get_var( $wpdb->prepare( "SELECT parent_name FROM {$wpdb->prefix}ptp_giveaway_entries WHERE phone = %s LIMIT 1", $phone ) );
            if ( $name ) return $name;
        }

        // 5. OpenPhone (cached 1hr)
        $cached = get_transient( 'ptp_ch_contact_' . md5( $phone ) );
        if ( $cached ) return $cached;

        $contacts = $this->op_request( 'GET', '/contacts?limit=5&phoneNumber=' . urlencode( $phone ) );
        if ( ! is_wp_error( $contacts ) && ! empty( $contacts['data'] ) ) {
            $name = $contacts['data'][0]['displayName'] ?? '';
            if ( $name ) {
                set_transient( 'ptp_ch_contact_' . md5( $phone ), $name, 3600 );
                return $name;
            }
        }

        return 'Unknown';
    }

    /* ═══════════════════════════════════════════════════════════════════
       REST ROUTES
       ═══════════════════════════════════════════════════════════════════ */
    public function register_routes() {
        $ns   = 'ptp-comms/v1';
        $perm = [ 'permission_callback' => [ $this, 'check_perm' ] ];

        // OpenPhone
        register_rest_route( $ns, '/op/conversations',                    array_merge( [ 'methods' => 'GET',    'callback' => [ $this, 'api_conversations' ] ], $perm ) );
        register_rest_route( $ns, '/op/thread/(?P<phone>[^/]+)',          array_merge( [ 'methods' => 'GET',    'callback' => [ $this, 'api_thread' ] ],        $perm ) );
        register_rest_route( $ns, '/op/send',                             array_merge( [ 'methods' => 'POST',   'callback' => [ $this, 'api_send' ] ],          $perm ) );
        register_rest_route( $ns, '/op/poll',                             array_merge( [ 'methods' => 'GET',    'callback' => [ $this, 'api_poll' ] ],          $perm ) );

        // Customer 360
        register_rest_route( $ns, '/customer360/(?P<key>[^/]+)',          array_merge( [ 'methods' => 'GET',    'callback' => [ $this, 'api_c360' ] ],          $perm ) );

        // Analytics
        register_rest_route( $ns, '/funnel',                              array_merge( [ 'methods' => 'GET',    'callback' => [ $this, 'api_funnel' ] ],        $perm ) );
        register_rest_route( $ns, '/unified-stats',                       array_merge( [ 'methods' => 'GET',    'callback' => [ $this, 'api_stats' ] ],         $perm ) );

        // Contacts
        register_rest_route( $ns, '/contacts',                            array_merge( [ 'methods' => 'GET',    'callback' => [ $this, 'api_contacts' ] ],      $perm ) );
        register_rest_route( $ns, '/contacts/search',                     array_merge( [ 'methods' => 'GET',    'callback' => [ $this, 'api_contact_search' ] ],$perm ) );

        // Templates
        register_rest_route( $ns, '/templates',                           array_merge( [ 'methods' => 'GET',    'callback' => [ $this, 'api_templates' ] ],     $perm ) );
        register_rest_route( $ns, '/templates',                           array_merge( [ 'methods' => 'POST',   'callback' => [ $this, 'api_template_save' ] ], $perm ) );
        register_rest_route( $ns, '/templates/(?P<id>\d+)',               array_merge( [ 'methods' => 'DELETE', 'callback' => [ $this, 'api_template_del' ] ],  $perm ) );

        // Drafts
        register_rest_route( $ns, '/drafts',                              array_merge( [ 'methods' => 'GET',    'callback' => [ $this, 'api_drafts' ] ],        $perm ) );
        register_rest_route( $ns, '/drafts/generate',                     array_merge( [ 'methods' => 'POST',   'callback' => [ $this, 'api_draft_gen' ] ],     $perm ) );
        register_rest_route( $ns, '/drafts/(?P<id>\d+)/approve',          array_merge( [ 'methods' => 'POST',   'callback' => [ $this, 'api_draft_approve' ] ], $perm ) );
        register_rest_route( $ns, '/drafts/(?P<id>\d+)/discard',          array_merge( [ 'methods' => 'POST',   'callback' => [ $this, 'api_draft_discard' ] ], $perm ) );

        // Bulk
        register_rest_route( $ns, '/bulk/send',                           array_merge( [ 'methods' => 'POST',   'callback' => [ $this, 'api_bulk_send' ] ],     $perm ) );

        // CC passthrough
        register_rest_route( $ns, '/sequences',                           array_merge( [ 'methods' => 'GET',    'callback' => [ $this, 'api_sequences' ] ],     $perm ) );
        register_rest_route( $ns, '/campaigns',                           array_merge( [ 'methods' => 'GET',    'callback' => [ $this, 'api_campaigns' ] ],     $perm ) );

        // Giveaway
        register_rest_route( $ns, '/giveaway/entries',                    array_merge( [ 'methods' => 'GET',    'callback' => [ $this, 'api_gw_entries' ] ],    $perm ) );
        register_rest_route( $ns, '/giveaway/stats',                      array_merge( [ 'methods' => 'GET',    'callback' => [ $this, 'api_gw_stats' ] ],     $perm ) );

        // Landing Page
        register_rest_route( $ns, '/landing/leads',                       array_merge( [ 'methods' => 'GET',    'callback' => [ $this, 'api_lp_leads' ] ],     $perm ) );

        // Settings
        register_rest_route( $ns, '/settings',                            array_merge( [ 'methods' => 'GET',    'callback' => [ $this, 'api_settings_get' ] ],  $perm ) );
        register_rest_route( $ns, '/settings',                            array_merge( [ 'methods' => 'POST',   'callback' => [ $this, 'api_settings_save' ] ], $perm ) );

        // Health (public)
        register_rest_route( $ns, '/health', [ 'methods' => 'GET', 'callback' => [ $this, 'api_health' ], 'permission_callback' => '__return_true' ] );
    }

    public function check_perm() {
        return current_user_can( 'manage_options' );
    }

    /* ═══════════════════════════════════════════════════════════════════
       OPENPHONE ENDPOINTS
       ═══════════════════════════════════════════════════════════════════ */
    public function api_conversations( $req ) {
        $pid = $req->get_param( 'phoneNumberId' ) ?: $this->openphone_from;
        if ( empty( $pid ) ) return new WP_REST_Response( [ 'error' => 'No phone ID configured. Go to Settings.' ], 400 );

        $result = $this->op_request( 'GET', '/conversations?limit=100&phoneNumberId=' . urlencode( $pid ) );
        if ( is_wp_error( $result ) ) return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 500 );

        $convs = $result['data'] ?? [];
        foreach ( $convs as &$c ) {
            $phone = $c['participants'][0]['phoneNumber'] ?? ( $c['phoneNumber'] ?? '' );
            $c['phone']        = $phone;
            $c['name']         = $this->lookup_name( $phone );
            $c['unread_count'] = $c['unreadCount'] ?? 0;
            $c['last_message'] = $c['lastMessageBody'] ?? '';
            $c['timestamp']    = $c['lastMessageReceivedAt'] ?? ( $c['updatedAt'] ?? '' );
        }
        usort( $convs, fn( $a, $b ) => strtotime( $b['timestamp'] ?? '0' ) - strtotime( $a['timestamp'] ?? '0' ) );
        return new WP_REST_Response( $convs, 200 );
    }

    public function api_thread( $req ) {
        $phone = $this->normalize_phone( $req['phone'] ?? '' );
        if ( empty( $phone ) ) return new WP_REST_Response( [ 'error' => 'Phone required' ], 400 );

        $pid    = $req->get_param( 'phoneNumberId' ) ?: $this->openphone_from;
        $result = $this->op_request( 'GET', '/messages?limit=50&phoneNumberId=' . urlencode( $pid ) . '&participants=' . urlencode( $phone ) );
        if ( is_wp_error( $result ) ) return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 500 );

        $msgs = $result['data'] ?? [];
        usort( $msgs, fn( $a, $b ) => strtotime( $a['createdAt'] ?? '0' ) - strtotime( $b['createdAt'] ?? '0' ) );

        $thread = [];
        foreach ( $msgs as $m ) {
            $thread[] = [
                'id'        => $m['id'] ?? '',
                'body'      => $m['body'] ?? '',
                'direction' => $m['direction'] ?? 'inbound',
                'timestamp' => $m['createdAt'] ?? '',
                'status'    => $m['status'] ?? 'sent',
            ];
        }
        return new WP_REST_Response( $thread, 200 );
    }

    public function api_send( $req ) {
        $params = $req->get_json_params();
        $to     = $this->normalize_phone( $params['to'] ?? '' );
        $body   = sanitize_textarea_field( $params['body'] ?? '' );
        if ( empty( $to ) || empty( $body ) ) return new WP_REST_Response( [ 'error' => 'Phone and body required' ], 400 );

        $result = $this->op_request( 'POST', '/messages', [
            'from' => $this->openphone_from,
            'to'   => [ $to ],
            'body' => $body,
        ] );
        if ( is_wp_error( $result ) ) return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 500 );

        $this->sync_message_to_cc( $to, $body, 'outbound' );
        return new WP_REST_Response( [ 'success' => true, 'data' => $result ], 200 );
    }

    public function api_poll( $req ) {
        $since = $req->get_param( 'since' ) ?: ( time() - 300 );
        $phone = $req->get_param( 'phone' ) ?: '';
        $pid   = $req->get_param( 'phoneNumberId' ) ?: $this->openphone_from;

        $filter = '?limit=50&phoneNumberId=' . urlencode( $pid );
        if ( $phone ) $filter .= '&participants=' . urlencode( $this->normalize_phone( $phone ) );

        $result = $this->op_request( 'GET', '/messages' . $filter );
        if ( is_wp_error( $result ) ) return new WP_REST_Response( [], 200 );

        $msgs = array_filter( $result['data'] ?? [], fn( $m ) => strtotime( $m['createdAt'] ?? '0' ) > $since );
        return new WP_REST_Response( array_values( $msgs ), 200 );
    }

    private function sync_message_to_cc( $phone, $body, $direction ) {
        if ( ! class_exists( 'CC_DB' ) ) return;
        global $wpdb;
        $family = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}ptp_cc_families WHERE phone = %s LIMIT 1", $phone ) );
        $wpdb->insert( CC_DB::op_msgs(), [
            'family_id'  => $family ? $family->id : 0,
            'phone'      => $phone,
            'channel'    => 'sms',
            'body'       => $body,
            'direction'  => $direction,
            'status'     => 'sent',
            'created_at' => current_time( 'mysql' ),
        ] );
    }

    /* ═══════════════════════════════════════════════════════════════════
       CUSTOMER 360
       ═══════════════════════════════════════════════════════════════════ */
    public function api_c360( $req ) {
        $key = sanitize_text_field( $req['key'] ?? '' );
        if ( empty( $key ) ) return new WP_REST_Response( [ 'error' => 'Key required' ], 400 );

        $is_email = ( strpos( $key, '@' ) !== false );
        $phone    = $is_email ? '' : $this->normalize_phone( $key );
        $last10   = substr( preg_replace( '/\D/', '', $phone ), -10 );

        $profile = [ 'name' => 'Unknown', 'phone' => $phone, 'email' => '', 'cc' => null, 'training' => null, 'camps' => null, 'giveaway' => null, 'landing' => null, 'total_ltv' => 0, 'lead_score' => 0, 'sources' => [], 'tags' => [], 'timeline' => [] ];
        global $wpdb;

        // CC
        if ( $this->cc() ) {
            $col = $is_email ? 'email' : 'phone';
            $fam = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ptp_cc_families WHERE {$col} = %s LIMIT 1", $key ) );
            if ( $fam ) {
                $profile['name']  = trim( ( $fam->parent_first ?? '' ) . ' ' . ( $fam->parent_last ?? '' ) ) ?: 'Unknown';
                $profile['phone'] = $fam->phone ?? $phone;
                $profile['email'] = $fam->email ?? '';
                $profile['cc']    = [ 'stage' => $fam->stage ?? 'new_lead', 'source' => $fam->source ?? '', 'score' => $fam->score ?? 0, 'warmth' => $fam->warmth ?? 'cold', 'created' => $fam->created_at ?? '' ];
                $profile['sources'][] = 'Command Center';

                $kids = $wpdb->get_results( $wpdb->prepare( "SELECT first_name, age, club, position FROM {$wpdb->prefix}ptp_cc_children WHERE family_id = %d", $fam->id ) );
                $profile['cc']['children'] = $kids ?: [];

                $tags = $wpdb->get_col( $wpdb->prepare( "SELECT tag_name FROM {$wpdb->prefix}ptp_cc_tags WHERE family_id = %d", $fam->id ) );
                $profile['tags'] = $tags ?: [];

                $notes = $wpdb->get_results( $wpdb->prepare( "SELECT note_text, created_at FROM {$wpdb->prefix}ptp_cc_notes WHERE family_id = %d ORDER BY created_at DESC LIMIT 5", $fam->id ) );
                $profile['cc']['notes'] = $notes ?: [];

                $rev = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount) FROM {$wpdb->prefix}ptp_cc_revenue WHERE family_id = %d AND status = 'completed'", $fam->id ) );
                $profile['total_ltv'] += (float) $rev;
            }
        }

        // Training
        if ( $this->tp() && $last10 ) {
            $bookings = $wpdb->get_results( $wpdb->prepare(
                "SELECT b.*, t.display_name as trainer_name FROM {$wpdb->prefix}ptp_bookings b LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(b.phone,'-',''),' ',''),'(',''),')',''),10) = %s AND b.status = 'completed' AND b.payment_status = 'succeeded'", $last10
            ) );
            if ( $bookings ) {
                $ltv = array_sum( array_column( (array) $bookings, 'total_amount' ) );
                $profile['training'] = [ 'bookings' => count( $bookings ), 'ltv' => $ltv, 'trainers' => array_unique( array_filter( array_column( (array) $bookings, 'trainer_name' ) ) ) ];
                $profile['total_ltv'] += $ltv;
                $profile['sources'][] = 'Training';
            }
        }

        // Giveaway
        if ( $this->gw() ) {
            $entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ptp_giveaway_entries WHERE phone = %s OR email = %s LIMIT 1", $phone, $key ) );
            if ( $entry ) {
                $profile['giveaway'] = [ 'won' => (bool) ( $entry->winner ?? false ), 'date' => $entry->created_at ?? '' ];
                $profile['sources'][] = 'Giveaway';
            }
        }

        // Landing Page (CPT ptp26_lead + postmeta)
        if ( $this->lp() ) {
            $lead_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->prefix}posts p
                 JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id
                 WHERE p.post_type = 'ptp26_lead'
                 AND ((pm.meta_key = '_phone' AND pm.meta_value = %s) OR (pm.meta_key = '_email' AND pm.meta_value = %s))
                 LIMIT 1", $phone, $key
            ) );
            if ( $lead_id ) {
                $lp_meta = [];
                $raw = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->prefix}postmeta WHERE post_id = %d AND meta_key LIKE '\\_%'", $lead_id ) );
                foreach ( $raw as $m ) { $lp_meta[ ltrim( $m->meta_key, '_' ) ] = $m->meta_value; }
                $profile['landing'] = [
                    'source'   => $lp_meta['utm_source'] ?? 'organic',
                    'campaign' => $lp_meta['utm_campaign'] ?? '',
                    'path'     => $lp_meta['path'] ?? $lp_meta['lead_type'] ?? '',
                    'kid'      => $lp_meta['kid_name'] ?? '',
                    'notes'    => $lp_meta['notes'] ?? '',
                    'date'     => get_the_date( 'Y-m-d H:i:s', $lead_id ),
                ];
                $profile['sources'][] = 'Landing Page';
            }
        }

        // Score
        $score = 0;
        if ( $profile['cc'] ) {
            $score += match( $profile['cc']['stage'] ) {
                'vip' => 100, 'recurring' => 90, 'training_converted' => 80, 'in_48hr_window' => 70, 'camp_attended' => 60, 'camp_registered' => 50, 'contacted' => 30, default => 10
            };
        }
        if ( $profile['training'] ) { $score += min( ( $profile['training']['ltv'] ?? 0 ) / 10, 30 ); }
        $profile['lead_score'] = min( 100, $score );

        return new WP_REST_Response( $profile, 200 );
    }

    /* ═══════════════════════════════════════════════════════════════════
       CONTACTS
       ═══════════════════════════════════════════════════════════════════ */
    public function api_contacts( $req ) {
        if ( ! $this->cc() ) return new WP_REST_Response( [], 200 );
        global $wpdb;
        $page = max( 1, (int) $req->get_param( 'page' ) );
        $per  = 50;
        $off  = ( $page - 1 ) * $per;

        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, parent_first, parent_last, phone, email, stage, score, warmth, last_contacted, created_at FROM {$wpdb->prefix}ptp_cc_families ORDER BY updated_at DESC LIMIT %d OFFSET %d", $per, $off
        ) );
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_cc_families" );

        return new WP_REST_Response( [ 'data' => $rows, 'total' => $total, 'page' => $page, 'pages' => ceil( $total / $per ) ], 200 );
    }

    public function api_contact_search( $req ) {
        $q = sanitize_text_field( $req->get_param( 'q' ) ?? '' );
        if ( strlen( $q ) < 2 ) return new WP_REST_Response( [], 200 );
        global $wpdb;

        $results = [];
        if ( $this->cc() ) {
            $like = '%' . $wpdb->esc_like( $q ) . '%';
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, CONCAT(parent_first,' ',parent_last) as name, phone, email, stage FROM {$wpdb->prefix}ptp_cc_families WHERE parent_first LIKE %s OR parent_last LIKE %s OR phone LIKE %s OR email LIKE %s LIMIT 20",
                $like, $like, $like, $like
            ) );
        }
        return new WP_REST_Response( $results, 200 );
    }

    /* ═══════════════════════════════════════════════════════════════════
       TEMPLATES
       ═══════════════════════════════════════════════════════════════════ */
    public function api_templates( $req ) {
        global $wpdb;
        return new WP_REST_Response( $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ptp_ch_templates ORDER BY usage_count DESC, name ASC" ), 200 );
    }

    public function api_template_save( $req ) {
        global $wpdb;
        $t  = $wpdb->prefix . 'ptp_ch_templates';
        $p  = $req->get_json_params();
        $id = (int) ( $p['id'] ?? 0 );
        $data = [
            'name'     => sanitize_text_field( $p['name'] ?? '' ),
            'category' => sanitize_text_field( $p['category'] ?? 'quick_reply' ),
            'body'     => sanitize_textarea_field( $p['body'] ?? '' ),
        ];
        if ( $id > 0 ) {
            $wpdb->update( $t, $data, [ 'id' => $id ] );
        } else {
            $data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $t, $data );
            $id = $wpdb->insert_id;
        }
        return new WP_REST_Response( [ 'success' => true, 'id' => $id ], 200 );
    }

    public function api_template_del( $req ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'ptp_ch_templates', [ 'id' => (int) $req['id'] ] );
        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    /* ═══════════════════════════════════════════════════════════════════
       AI DRAFTS
       ═══════════════════════════════════════════════════════════════════ */
    public function api_drafts( $req ) {
        global $wpdb;
        return new WP_REST_Response( $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ptp_ch_drafts WHERE status = 'pending' ORDER BY created_at DESC LIMIT 50" ), 200 );
    }

    public function api_draft_gen( $req ) {
        $p     = $req->get_json_params();
        $phone = $this->normalize_phone( $p['phone'] ?? '' );
        $ctx   = sanitize_textarea_field( $p['context'] ?? '' );
        $goal  = sanitize_text_field( $p['goal'] ?? 'follow_up' );

        $api_key = defined( 'PTP_ANTHROPIC_KEY' ) && PTP_ANTHROPIC_KEY ? PTP_ANTHROPIC_KEY : get_option( 'ptp_ch_anthropic_key', '' );
        if ( empty( $api_key ) ) return new WP_REST_Response( [ 'error' => 'Anthropic API key not configured' ], 400 );

        $name   = $this->lookup_name( $phone );
        $prompt = "You are Luke from PTP Soccer Camps. Write a short, friendly SMS message (under 160 chars if possible) to {$name}.\nGoal: {$goal}\nContext: {$ctx}\nKeep it personal, conversational, and action-oriented. No emojis. Just the message text, nothing else.";

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'headers' => [
                'Content-Type'     => 'application/json',
                'x-api-key'        => $api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body'    => wp_json_encode( [
                'model'      => 'claude-sonnet-4-5-20250929',
                'max_tokens' => 300,
                'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
            ] ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) return new WP_REST_Response( [ 'error' => $response->get_error_message() ], 500 );

        $body       = json_decode( wp_remote_retrieve_body( $response ), true );
        $draft_text = $body['content'][0]['text'] ?? '';
        if ( empty( $draft_text ) ) return new WP_REST_Response( [ 'error' => 'AI returned empty response' ], 500 );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'ptp_ch_drafts', [
            'phone'      => $phone,
            'channel'    => 'sms',
            'draft_body' => $draft_text,
            'ai_context' => wp_json_encode( [ 'goal' => $goal, 'context' => $ctx, 'name' => $name ] ),
            'status'     => 'pending',
            'created_at' => current_time( 'mysql' ),
        ] );

        return new WP_REST_Response( [ 'success' => true, 'draft' => $draft_text, 'id' => $wpdb->insert_id ], 200 );
    }

    public function api_draft_approve( $req ) {
        $id = (int) $req['id'];
        global $wpdb;
        $t     = $wpdb->prefix . 'ptp_ch_drafts';
        $draft = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
        if ( ! $draft ) return new WP_REST_Response( [ 'error' => 'Draft not found' ], 404 );

        $result = $this->op_request( 'POST', '/messages', [
            'from' => $this->openphone_from,
            'to'   => [ $draft->phone ],
            'body' => $draft->draft_body,
        ] );
        if ( is_wp_error( $result ) ) return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 500 );

        $wpdb->update( $t, [ 'status' => 'sent', 'approved_by' => get_current_user_id(), 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );
        $this->sync_message_to_cc( $draft->phone, $draft->draft_body, 'outbound' );

        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    public function api_draft_discard( $req ) {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'ptp_ch_drafts', [ 'status' => 'discarded', 'updated_at' => current_time( 'mysql' ) ], [ 'id' => (int) $req['id'] ] );
        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    /* ═══════════════════════════════════════════════════════════════════
       BULK SMS
       ═══════════════════════════════════════════════════════════════════ */
    public function api_bulk_send( $req ) {
        $p      = $req->get_json_params();
        $phones = $p['phones'] ?? [];
        $body   = sanitize_textarea_field( $p['body'] ?? '' );
        if ( empty( $phones ) || empty( $body ) ) return new WP_REST_Response( [ 'error' => 'Phones and body required' ], 400 );

        $sent = 0; $failed = 0;
        foreach ( $phones as $phone ) {
            $phone  = $this->normalize_phone( $phone );
            $result = $this->op_request( 'POST', '/messages', [
                'from' => $this->openphone_from,
                'to'   => [ $phone ],
                'body' => $body,
            ] );
            if ( is_wp_error( $result ) ) { $failed++; } else { $sent++; }
            usleep( 110000 ); // 10 req/sec rate limit
        }
        return new WP_REST_Response( [ 'sent' => $sent, 'failed' => $failed ], 200 );
    }

    /* ═══════════════════════════════════════════════════════════════════
       SEQUENCES / CAMPAIGNS (passthrough from CC)
       ═══════════════════════════════════════════════════════════════════ */
    public function api_sequences( $req ) {
        if ( ! $this->cc() ) return new WP_REST_Response( [], 200 );
        global $wpdb;
        return new WP_REST_Response( $wpdb->get_results( "SELECT s.*, (SELECT COUNT(*) FROM {$wpdb->prefix}ptp_cc_sequence_enrollments se WHERE se.sequence_id = s.id AND se.status = 'active') as active_enrollments FROM {$wpdb->prefix}ptp_cc_sequences s ORDER BY s.name ASC" ), 200 );
    }

    public function api_campaigns( $req ) {
        if ( ! $this->cc() ) return new WP_REST_Response( [], 200 );
        global $wpdb;
        return new WP_REST_Response( $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ptp_cc_campaigns ORDER BY created_at DESC LIMIT 50" ), 200 );
    }

    /* ═══════════════════════════════════════════════════════════════════
       FUNNEL / STATS
       ═══════════════════════════════════════════════════════════════════ */
    public function api_funnel( $req ) {
        $days = max( 1, (int) ( $req->get_param( 'days' ) ?: 30 ) );
        $date = gmdate( 'Y-m-d H:i:s', time() - ( $days * 86400 ) );
        global $wpdb;

        $f = [ 'period_days' => $days, 'cc_families' => 0, 'contacted' => 0, 'camp_registered' => 0, 'camp_attended' => 0, 'training_converted' => 0, 'lp_leads' => 0, 'gw_entries' => 0, 'training_revenue' => 0, 'camp_revenue' => 0, 'total_revenue' => 0, 'ad_spend' => 0, 'blended_cac' => 0 ];

        if ( $this->cc() ) {
            $f['cc_families']        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_cc_families WHERE created_at > %s", $date ) );
            $f['contacted']          = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_cc_families WHERE stage NOT IN ('new_lead','lost') AND created_at > %s", $date ) );
            $f['camp_registered']    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_cc_families WHERE stage IN ('camp_registered','camp_attended','in_48hr_window','training_converted','recurring','vip') AND created_at > %s", $date ) );
            $f['camp_attended']      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_cc_families WHERE stage IN ('camp_attended','in_48hr_window','training_converted','recurring','vip') AND created_at > %s", $date ) );
            $f['training_converted'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_cc_families WHERE stage IN ('training_converted','recurring','vip') AND created_at > %s", $date ) );
            $f['ad_spend']           = (float) ( $wpdb->get_var( $wpdb->prepare( "SELECT SUM(spend) FROM {$wpdb->prefix}ptp_cc_ad_spend WHERE spend_date > %s", gmdate( 'Y-m-d', time() - $days * 86400 ) ) ) ?: 0 );
        }
        if ( $this->lp() ) {
            $f['lp_leads'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE post_type='ptp26_lead' AND post_date > %s", $date ) );
        }
        if ( $this->gw() ) {
            $f['gw_entries'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_giveaway_entries WHERE created_at > %s", $date ) );
        }
        if ( $this->tp() ) {
            $f['training_revenue'] = (float) ( $wpdb->get_var( $wpdb->prepare( "SELECT SUM(total_amount) FROM {$wpdb->prefix}ptp_bookings WHERE session_date > %s AND status='completed' AND payment_status='succeeded'", $date ) ) ?: 0 );
        }
        if ( class_exists( 'PTP_Camps' ) ) {
            $f['camp_revenue'] = (float) ( $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount_paid) FROM {$wpdb->prefix}ptp_camp_bookings WHERE created_at > %s AND status != 'refunded'", $date ) ) ?: 0 );
        }

        $f['total_revenue'] = $f['training_revenue'] + $f['camp_revenue'];
        $total_leads        = max( 1, $f['cc_families'] + $f['lp_leads'] + $f['gw_entries'] );
        $f['blended_cac']   = $f['ad_spend'] > 0 ? round( $f['ad_spend'] / $total_leads, 2 ) : 0;

        return new WP_REST_Response( $f, 200 );
    }

    public function api_stats( $req ) {
        global $wpdb;
        $s = [ 'revenue' => [ 'training' => 0, 'camp' => 0, 'total' => 0 ], 'pipeline' => [ 'new_lead' => 0, 'contacted' => 0, 'camp_registered' => 0, 'camp_attended' => 0, 'training_converted' => 0, 'recurring' => 0, 'vip' => 0 ], 'plugins' => [ 'cc' => $this->cc(), 'tp' => $this->tp(), 'lp' => $this->lp(), 'gw' => $this->gw() ], 'messages_today' => 0, 'drafts_pending' => 0 ];

        if ( $this->cc() ) {
            $stages = $wpdb->get_results( "SELECT stage, COUNT(*) as cnt FROM {$wpdb->prefix}ptp_cc_families GROUP BY stage" );
            foreach ( $stages as $row ) { $s['pipeline'][ $row->stage ] = (int) $row->cnt; }
            $s['messages_today'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . CC_DB::op_msgs() . " WHERE created_at > %s", gmdate( 'Y-m-d 00:00:00' ) ) );
        }
        if ( $this->tp() ) {
            $s['revenue']['training'] = (float) ( $wpdb->get_var( "SELECT SUM(total_amount) FROM {$wpdb->prefix}ptp_bookings WHERE status='completed' AND payment_status='succeeded'" ) ?: 0 );
        }
        if ( class_exists( 'PTP_Camps' ) ) {
            $s['revenue']['camp'] = (float) ( $wpdb->get_var( "SELECT SUM(amount_paid) FROM {$wpdb->prefix}ptp_camp_bookings WHERE status != 'refunded'" ) ?: 0 );
        }
        $s['revenue']['total'] = $s['revenue']['training'] + $s['revenue']['camp'];
        $s['drafts_pending']   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_ch_drafts WHERE status = 'pending'" );

        return new WP_REST_Response( $s, 200 );
    }

    /* ═══════════════════════════════════════════════════════════════════
       GIVEAWAY / LANDING PAGE
       ═══════════════════════════════════════════════════════════════════ */
    public function api_gw_entries( $req ) {
        if ( ! $this->gw() ) return new WP_REST_Response( [], 200 );
        global $wpdb;
        return new WP_REST_Response( $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ptp_giveaway_entries ORDER BY created_at DESC LIMIT 100" ), 200 );
    }

    public function api_gw_stats( $req ) {
        if ( ! $this->gw() ) return new WP_REST_Response( [ 'total' => 0, 'winners' => 0 ], 200 );
        global $wpdb;
        $r = $wpdb->get_row( "SELECT COUNT(*) as total, COUNT(CASE WHEN winner=1 THEN 1 END) as winners, COUNT(DISTINCT location) as locations FROM {$wpdb->prefix}ptp_giveaway_entries" );
        return new WP_REST_Response( [ 'total' => (int) $r->total, 'winners' => (int) $r->winners, 'locations' => (int) $r->locations, 'win_rate' => $r->total > 0 ? round( $r->winners / $r->total * 100, 1 ) : 0 ], 200 );
    }

    public function api_lp_leads( $req ) {
        if ( ! $this->lp() ) return new WP_REST_Response( [], 200 );
        global $wpdb;
        return new WP_REST_Response( $wpdb->get_results(
            "SELECT p.ID, p.post_date, MAX(CASE WHEN pm.meta_key='_name' THEN pm.meta_value END) as name, MAX(CASE WHEN pm.meta_key='_phone' THEN pm.meta_value END) as phone, MAX(CASE WHEN pm.meta_key='_email' THEN pm.meta_value END) as email, MAX(CASE WHEN pm.meta_key='_location' THEN pm.meta_value END) as location FROM {$wpdb->prefix}posts p JOIN {$wpdb->prefix}postmeta pm ON p.ID=pm.post_id WHERE p.post_type='ptp26_lead' GROUP BY p.ID ORDER BY p.post_date DESC LIMIT 100"
        ), 200 );
    }

    /* ═══════════════════════════════════════════════════════════════════
       SETTINGS
       ═══════════════════════════════════════════════════════════════════ */
    public function api_settings_get( $req ) {
        $anth = get_option( 'ptp_ch_anthropic_key', '' );
        return new WP_REST_Response( [
            'openphone_key'       => ! empty( $this->openphone_key ) ? str_repeat( '*', 8 ) . substr( $this->openphone_key, -6 ) : '',
            'openphone_from'      => $this->openphone_from,
            'openphone_connected' => ! empty( $this->openphone_key ),
            'anthropic_key'       => ! empty( $anth ) ? str_repeat( '*', 8 ) . substr( $anth, -6 ) : '',
            'anthropic_connected' => ! empty( $anth ) || ( defined( 'PTP_ANTHROPIC_KEY' ) && PTP_ANTHROPIC_KEY ),
            'version'             => PTP_CH_VER,
            'plugins'             => [ 'cc' => $this->cc(), 'tp' => $this->tp(), 'lp' => $this->lp(), 'gw' => $this->gw() ],
        ], 200 );
    }

    public function api_settings_save( $req ) {
        $p = $req->get_json_params();

        if ( isset( $p['openphone_key'] ) && $p['openphone_key'] !== '' && strpos( $p['openphone_key'], '***' ) === false ) {
            update_option( 'ptp_ch_openphone_key', sanitize_text_field( $p['openphone_key'] ) );
            $this->openphone_key = $p['openphone_key'];
        }
        if ( isset( $p['openphone_from'] ) ) {
            update_option( 'ptp_ch_openphone_from', sanitize_text_field( $p['openphone_from'] ) );
            $this->openphone_from = $p['openphone_from'];
        }
        if ( isset( $p['anthropic_key'] ) && $p['anthropic_key'] !== '' && strpos( $p['anthropic_key'], '***' ) === false ) {
            update_option( 'ptp_ch_anthropic_key', sanitize_text_field( $p['anthropic_key'] ) );
        }

        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    /* ─── Health ─── */
    public function api_health( $req ) {
        return new WP_REST_Response( [
            'status'    => 'healthy',
            'version'   => PTP_CH_VER,
            'openphone' => ! empty( $this->openphone_key ) ? 'connected' : 'disconnected',
            'plugins'   => [ 'cc' => $this->cc(), 'tp' => $this->tp(), 'lp' => $this->lp(), 'gw' => $this->gw() ],
        ], 200 );
    }
}
