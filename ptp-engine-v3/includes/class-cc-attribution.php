<?php
/**
 * PTP Command Center — Ad Attribution & CAC Tracking
 *
 * Full attribution layer:
 *   1. Click-level tracking (fbclid/gclid/UTMs → visitor cookie → purchase)
 *   2. Meta Conversions API (CAPI) — send purchase events to Meta
 *   3. Google Offline Conversion Import — send conversions to Google Ads
 *   4. Ad Spend Sync — pull campaign spend from Meta Marketing API + Google Ads
 *   5. Attribution Dashboard — ROAS, CAC, cohort LTV analysis
 *
 * @since 7.0
 */
if (!defined('ABSPATH')) exit;

class CC_Attribution {

    const NS = 'ptp-cc/v1';

    // ─── Table helpers ───
    public static function touches_table()    { return CC_DB::t('ptp_cc_attribution_touches'); }
    public static function customer_table()   { return CC_DB::t('ptp_cc_customer_attribution'); }
    public static function ad_spend_table()   { return CC_DB::t('ptp_cc_ad_spend'); }

    // ═══════════════════════════════════════
    // TABLE CREATION
    // ═══════════════════════════════════════

    public static function create_tables() {
        global $wpdb;
        $c = $wpdb->get_charset_collate();

        // Attribution touches — every click/visit with ad params
        $t1 = self::touches_table();
        if ($wpdb->get_var("SHOW TABLES LIKE '$t1'") !== $t1) {
            $wpdb->query("CREATE TABLE $t1 (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                visitor_id VARCHAR(64) NOT NULL,
                session_id VARCHAR(64) NOT NULL DEFAULT '',

                -- Click identifiers
                fbclid VARCHAR(255) DEFAULT NULL,
                gclid VARCHAR(255) DEFAULT NULL,

                -- UTM params
                utm_source VARCHAR(200) DEFAULT NULL,
                utm_medium VARCHAR(200) DEFAULT NULL,
                utm_campaign VARCHAR(200) DEFAULT NULL,
                utm_content VARCHAR(200) DEFAULT NULL,
                utm_term VARCHAR(200) DEFAULT NULL,

                -- Context
                landing_page VARCHAR(500) DEFAULT NULL,
                referrer VARCHAR(500) DEFAULT NULL,
                device_type VARCHAR(20) DEFAULT NULL,

                -- Resolution (filled on conversion)
                customer_email VARCHAR(200) DEFAULT NULL,
                customer_phone VARCHAR(30) DEFAULT NULL,
                converted_at DATETIME DEFAULT NULL,
                conversion_type VARCHAR(30) DEFAULT NULL,
                conversion_id BIGINT UNSIGNED DEFAULT NULL,

                touched_at DATETIME DEFAULT CURRENT_TIMESTAMP,

                INDEX idx_visitor (visitor_id),
                INDEX idx_fbclid (fbclid(50)),
                INDEX idx_gclid (gclid(50)),
                INDEX idx_email (customer_email),
                INDEX idx_converted (converted_at),
                INDEX idx_touched (touched_at)
            ) $c;");
        }

        // Customer attribution — first/last touch per customer
        $t2 = self::customer_table();
        if ($wpdb->get_var("SHOW TABLES LIKE '$t2'") !== $t2) {
            $wpdb->query("CREATE TABLE $t2 (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_email VARCHAR(200) NOT NULL,
                customer_phone VARCHAR(30) DEFAULT NULL,

                -- First touch
                first_touch_source VARCHAR(200) DEFAULT NULL,
                first_touch_medium VARCHAR(200) DEFAULT NULL,
                first_touch_campaign VARCHAR(200) DEFAULT NULL,
                first_touch_fbclid VARCHAR(255) DEFAULT NULL,
                first_touch_gclid VARCHAR(255) DEFAULT NULL,
                first_touch_at DATETIME DEFAULT NULL,
                first_touch_landing VARCHAR(500) DEFAULT NULL,

                -- Last touch (what drove the conversion)
                last_touch_source VARCHAR(200) DEFAULT NULL,
                last_touch_medium VARCHAR(200) DEFAULT NULL,
                last_touch_campaign VARCHAR(200) DEFAULT NULL,
                last_touch_fbclid VARCHAR(255) DEFAULT NULL,
                last_touch_gclid VARCHAR(255) DEFAULT NULL,
                last_touch_at DATETIME DEFAULT NULL,

                -- Computed
                total_touches INT DEFAULT 0,
                days_to_convert INT DEFAULT NULL,
                acquisition_channel VARCHAR(50) DEFAULT NULL,

                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uk_email (customer_email),
                INDEX idx_channel (acquisition_channel),
                INDEX idx_first_source (first_touch_source),
                INDEX idx_first_at (first_touch_at)
            ) $c;");
        }

        // Ad spend — daily campaign-level spend from Meta + Google
        $t3 = self::ad_spend_table();
        if ($wpdb->get_var("SHOW TABLES LIKE '$t3'") !== $t3) {
            $wpdb->query("CREATE TABLE $t3 (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                platform ENUM('meta','google') NOT NULL,
                account_id VARCHAR(100) NOT NULL DEFAULT '',
                campaign_id VARCHAR(100) DEFAULT NULL,
                campaign_name VARCHAR(255) DEFAULT NULL,
                adset_id VARCHAR(100) DEFAULT NULL,
                adset_name VARCHAR(255) DEFAULT NULL,
                ad_id VARCHAR(100) DEFAULT NULL,
                ad_name VARCHAR(255) DEFAULT NULL,

                spend_date DATE NOT NULL,
                spend DECIMAL(10,2) NOT NULL DEFAULT 0,
                impressions INT DEFAULT 0,
                clicks INT DEFAULT 0,
                conversions INT DEFAULT 0,
                conversion_value DECIMAL(10,2) DEFAULT 0,
                cpm DECIMAL(10,2) DEFAULT 0,
                cpc DECIMAL(10,2) DEFAULT 0,
                ctr DECIMAL(6,4) DEFAULT 0,

                raw_data JSON DEFAULT NULL,
                synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,

                UNIQUE KEY uk_platform_date_campaign (platform, spend_date, campaign_id),
                INDEX idx_platform_date (platform, spend_date),
                INDEX idx_campaign (campaign_id),
                INDEX idx_spend_date (spend_date)
            ) $c;");
        }
    }

    // ═══════════════════════════════════════
    // REST ROUTES
    // ═══════════════════════════════════════

    public static function register_routes() {
        $admin_cb = function () { return current_user_can('manage_options'); };

        // Public: click tracking (no auth — called from frontend pixel)
        register_rest_route(self::NS, '/attribution/touch', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'api_record_touch'],
            'permission_callback' => '__return_true',
        ]);

        // Admin: dashboard endpoints
        register_rest_route(self::NS, '/attribution/overview', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_overview'],
            'permission_callback' => $admin_cb,
        ]);
        register_rest_route(self::NS, '/attribution/campaigns', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_campaigns'],
            'permission_callback' => $admin_cb,
        ]);
        register_rest_route(self::NS, '/attribution/cohorts', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_cohorts'],
            'permission_callback' => $admin_cb,
        ]);
        register_rest_route(self::NS, '/attribution/customer/(?P<email>.+)', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_customer_attribution'],
            'permission_callback' => $admin_cb,
        ]);
        register_rest_route(self::NS, '/attribution/touches', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_recent_touches'],
            'permission_callback' => $admin_cb,
        ]);

        // Ad Spend
        register_rest_route(self::NS, '/ad-spend/sync-status', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_sync_status'],
            'permission_callback' => $admin_cb,
        ]);
        register_rest_route(self::NS, '/ad-spend/sync-now', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'api_sync_now'],
            'permission_callback' => $admin_cb,
        ]);
        register_rest_route(self::NS, '/ad-spend/daily', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_daily_spend'],
            'permission_callback' => $admin_cb,
        ]);
        // Manual ad spend entry (for platforms not yet API-connected)
        register_rest_route(self::NS, '/ad-spend/manual', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'api_manual_spend'],
            'permission_callback' => $admin_cb,
        ]);

        // Meta/Google connection test
        register_rest_route(self::NS, '/attribution/meta/test', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_test_meta'],
            'permission_callback' => $admin_cb,
        ]);
        register_rest_route(self::NS, '/attribution/google/test', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_test_google'],
            'permission_callback' => $admin_cb,
        ]);

        // Settings
        register_rest_route(self::NS, '/attribution/settings', [
            ['methods' => 'GET',  'callback' => [__CLASS__, 'api_get_settings'],  'permission_callback' => $admin_cb],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'api_save_settings'], 'permission_callback' => $admin_cb],
        ]);
    }

    // ═══════════════════════════════════════
    // FRONTEND PIXEL — Record Touches
    // ═══════════════════════════════════════

    /**
     * Inject attribution pixel script on all frontend pages.
     * Captures fbclid/gclid/UTMs from URL, stores in cookie, POSTs to REST endpoint.
     */
    public static function inject_pixel() {
        if (is_admin()) return;
        $endpoint = rest_url(self::NS . '/attribution/touch');
        $nonce = wp_create_nonce('wp_rest');
        ?>
        <script>
        (function(){
            var COOKIE='ptp_attr';
            var VISITOR_COOKIE='ptp_vid';
            var EXPIRY=30; // days

            function setCk(n,v,d){var e=new Date();e.setDate(e.getDate()+d);document.cookie=n+'='+encodeURIComponent(v)+';expires='+e.toUTCString()+';path=/;SameSite=Lax';}
            function getCk(n){var m=document.cookie.match(new RegExp('(?:^|;\\s*)'+n+'=([^;]*)'));return m?decodeURIComponent(m[1]):null;}
            function uuid(){return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,function(c){var r=Math.random()*16|0;return(c==='x'?r:(r&0x3|0x8)).toString(16)});}
            function getParam(n){try{return new URLSearchParams(window.location.search).get(n)||null}catch(e){return null}}
            function detectDevice(){return window.innerWidth<768?'mobile':window.innerWidth<1024?'tablet':'desktop';}

            // Get or create visitor ID
            var vid=getCk(VISITOR_COOKIE);
            if(!vid){vid=uuid();setCk(VISITOR_COOKIE,vid,365);}

            // Check URL for ad params
            var fbclid=getParam('fbclid');
            var gclid=getParam('gclid');
            var utm_source=getParam('utm_source');
            var utm_medium=getParam('utm_medium');
            var utm_campaign=getParam('utm_campaign');
            var utm_content=getParam('utm_content');
            var utm_term=getParam('utm_term');

            // If we have ANY attribution params, store them
            var hasParams=fbclid||gclid||utm_source;
            if(hasParams){
                var attrData={
                    vid:vid,fbclid:fbclid,gclid:gclid,
                    utm_source:utm_source,utm_medium:utm_medium,utm_campaign:utm_campaign,
                    utm_content:utm_content,utm_term:utm_term,
                    landing:window.location.pathname,
                    ts:Date.now()
                };
                setCk(COOKIE,JSON.stringify(attrData),EXPIRY);

                // POST touch to server (non-blocking)
                var sid=uuid();
                try{
                    navigator.sendBeacon&&navigator.sendBeacon(
                        '<?php echo esc_url($endpoint); ?>',
                        new Blob([JSON.stringify({
                            visitor_id:vid,session_id:sid,
                            fbclid:fbclid,gclid:gclid,
                            utm_source:utm_source,utm_medium:utm_medium,
                            utm_campaign:utm_campaign,utm_content:utm_content,utm_term:utm_term,
                            landing_page:window.location.href,
                            referrer:document.referrer,
                            device_type:detectDevice(),
                            _wpnonce:'<?php echo $nonce; ?>'
                        })],{type:'application/json'})
                    );
                }catch(e){}
            }

            // Expose for checkout forms
            window.PTP_ATTRIBUTION={
                visitor_id:vid,
                getData:function(){
                    var raw=getCk(COOKIE);
                    try{return raw?JSON.parse(raw):null}catch(e){return null}
                }
            };

            // Auto-inject visitor_id into forms
            document.addEventListener('DOMContentLoaded',function(){
                // Look for checkout/application forms and inject hidden field
                var forms=document.querySelectorAll('form');
                forms.forEach(function(f){
                    if(f.querySelector('[name="ptp_visitor_id"]'))return;
                    var action=f.getAttribute('action')||'';
                    var hasCheckout=f.querySelector('[name="billing_email"],[name="customer_email"],[name="email"],[name="parent_email"]');
                    if(hasCheckout||action.indexOf('checkout')>-1||action.indexOf('book')>-1||action.indexOf('apply')>-1){
                        var h=document.createElement('input');
                        h.type='hidden';h.name='ptp_visitor_id';h.value=vid;
                        f.appendChild(h);
                    }
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * API: Record a touch (public, no auth).
     */
    public static function api_record_touch($req) {
        global $wpdb;
        $b = $req->get_json_params();
        if (empty($b)) $b = $req->get_params();

        $visitor_id = sanitize_text_field($b['visitor_id'] ?? '');
        if (!$visitor_id || strlen($visitor_id) < 8) {
            return new WP_Error('invalid', 'Missing visitor_id', ['status' => 400]);
        }

        // Rate limit: max 10 touches per visitor per hour
        $recent = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::touches_table() . " WHERE visitor_id=%s AND touched_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $visitor_id
        ));
        if ((int)$recent >= 10) {
            return ['ok' => true, 'throttled' => true];
        }

        $wpdb->insert(self::touches_table(), [
            'visitor_id'    => $visitor_id,
            'session_id'    => sanitize_text_field($b['session_id'] ?? ''),
            'fbclid'        => sanitize_text_field($b['fbclid'] ?? '') ?: null,
            'gclid'         => sanitize_text_field($b['gclid'] ?? '') ?: null,
            'utm_source'    => sanitize_text_field($b['utm_source'] ?? '') ?: null,
            'utm_medium'    => sanitize_text_field($b['utm_medium'] ?? '') ?: null,
            'utm_campaign'  => sanitize_text_field($b['utm_campaign'] ?? '') ?: null,
            'utm_content'   => sanitize_text_field($b['utm_content'] ?? '') ?: null,
            'utm_term'      => sanitize_text_field($b['utm_term'] ?? '') ?: null,
            'landing_page'  => esc_url_raw($b['landing_page'] ?? ''),
            'referrer'      => esc_url_raw($b['referrer'] ?? ''),
            'device_type'   => sanitize_text_field($b['device_type'] ?? ''),
        ]);

        return ['ok' => true, 'id' => $wpdb->insert_id];
    }

    // ═══════════════════════════════════════
    // CONVERSION RESOLUTION
    // ═══════════════════════════════════════

    /**
     * When a conversion happens, resolve the visitor_id to the customer email/phone
     * and compute first/last touch attribution.
     *
     * Called from camp booking, training booking, or application hooks.
     */
    public static function resolve_conversion($visitor_id, $email, $phone = '', $type = 'camp_booking', $conversion_id = 0) {
        global $wpdb;
        if (!$visitor_id || !$email) return;

        $tt = self::touches_table();
        $ct = self::customer_table();
        $now = current_time('mysql');

        // 1. Backfill email/phone on all touches for this visitor
        $wpdb->query($wpdb->prepare(
            "UPDATE $tt SET customer_email=%s, customer_phone=%s, converted_at=%s, conversion_type=%s, conversion_id=%d
             WHERE visitor_id=%s AND customer_email IS NULL",
            strtolower($email), $phone, $now, $type, $conversion_id, $visitor_id
        ));

        // 2. Also check if this email has other touches from different visitor_ids (multi-device)
        // by looking at existing touches that were already resolved to this email
        // (This handles cases where the same person used different devices)

        // 3. Compute first/last touch for this customer
        self::compute_attribution($email, $phone);

        // 4. Fire CAPI events
        self::fire_capi_purchase($email, $phone, $visitor_id, $type, $conversion_id);

        error_log("[PTP-CC Attribution] Resolved: visitor=$visitor_id email=$email type=$type id=$conversion_id");
    }

    /**
     * Compute first-touch and last-touch attribution for a customer.
     */
    public static function compute_attribution($email, $phone = '') {
        global $wpdb;
        $tt = self::touches_table();
        $ct = self::customer_table();
        $email_lower = strtolower(trim($email));

        // Get all touches for this email, ordered by time
        $touches = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $tt WHERE customer_email=%s ORDER BY touched_at ASC", $email_lower
        ));

        if (empty($touches)) {
            // No touches tracked — check if we have UTM data from the booking itself
            // (fallback for bookings made before pixel was installed)
            return;
        }

        // First touch with ad data (earliest touch that has any source info)
        $first = null;
        foreach ($touches as $t) {
            if ($t->utm_source || $t->fbclid || $t->gclid) {
                $first = $t;
                break;
            }
        }
        if (!$first) $first = $touches[0]; // fallback to absolute first

        // Last touch with ad data (most recent before conversion)
        $last = null;
        for ($i = count($touches) - 1; $i >= 0; $i--) {
            if ($touches[$i]->utm_source || $touches[$i]->fbclid || $touches[$i]->gclid) {
                $last = $touches[$i];
                break;
            }
        }
        if (!$last) $last = end($touches);

        // Determine acquisition channel
        $channel = 'direct';
        $src = strtolower($first->utm_source ?? '');
        if ($first->fbclid || in_array($src, ['facebook', 'fb', 'ig', 'instagram', 'meta'])) {
            $channel = 'meta';
        } elseif ($first->gclid || in_array($src, ['google', 'gads', 'google_ads'])) {
            $channel = 'google';
        } elseif (in_array($src, ['tiktok', 'tt'])) {
            $channel = 'tiktok';
        } elseif ($src) {
            $channel = strpos($src, 'organic') !== false ? 'organic' : 'referral';
        } elseif ($first->referrer && strpos($first->referrer, 'google.com') !== false) {
            $channel = 'organic';
        }

        $days_to_convert = null;
        $first_converted = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(converted_at) FROM $tt WHERE customer_email=%s AND converted_at IS NOT NULL", $email_lower
        ));
        if ($first_converted && $first->touched_at) {
            $days_to_convert = max(0, (int)((strtotime($first_converted) - strtotime($first->touched_at)) / 86400));
        }

        $data = [
            'customer_email'       => $email_lower,
            'customer_phone'       => $phone,
            'first_touch_source'   => $first->utm_source,
            'first_touch_medium'   => $first->utm_medium,
            'first_touch_campaign' => $first->utm_campaign,
            'first_touch_fbclid'   => $first->fbclid,
            'first_touch_gclid'    => $first->gclid,
            'first_touch_at'       => $first->touched_at,
            'first_touch_landing'  => $first->landing_page,
            'last_touch_source'    => $last->utm_source,
            'last_touch_medium'    => $last->utm_medium,
            'last_touch_campaign'  => $last->utm_campaign,
            'last_touch_fbclid'    => $last->fbclid,
            'last_touch_gclid'     => $last->gclid,
            'last_touch_at'        => $last->touched_at,
            'total_touches'        => count($touches),
            'days_to_convert'      => $days_to_convert,
            'acquisition_channel'  => $channel,
        ];

        // Upsert
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $ct WHERE customer_email=%s", $email_lower));
        if ($exists) {
            $wpdb->update($ct, $data, ['id' => $exists]);
        } else {
            $wpdb->insert($ct, $data);
        }
    }

    // ═══════════════════════════════════════
    // META CONVERSIONS API (CAPI)
    // ═══════════════════════════════════════

    /**
     * Send a purchase event to Meta CAPI.
     */
    public static function fire_capi_purchase($email, $phone, $visitor_id, $type, $conversion_id) {
        $pixel_id = get_option('ptp_cc_meta_pixel_id', '');
        $token    = get_option('ptp_cc_meta_access_token', '');
        if (!$pixel_id || !$token) return;

        global $wpdb;

        // Get conversion value
        $value = 0;
        $content_name = '';
        if ($type === 'camp_booking') {
            $bt = CC_DB::camp_bookings();
            if ($wpdb->get_var("SHOW TABLES LIKE '$bt'") === $bt) {
                $row = $wpdb->get_row($wpdb->prepare("SELECT amount_paid, camp_id FROM $bt WHERE id=%d", $conversion_id));
                if ($row) {
                    $value = (float)$row->amount_paid;
                    $content_name = get_the_title($row->camp_id) ?: 'Camp';
                }
            }
        } elseif ($type === 'training_booking') {
            $bt = CC_DB::bookings();
            $row = $wpdb->get_row($wpdb->prepare("SELECT total_amount FROM $bt WHERE id=%d", $conversion_id));
            if ($row) $value = (float)$row->total_amount;
            $content_name = 'Training Session';
        }

        // Get fbclid from touches
        $tt = self::touches_table();
        $fbclid = $wpdb->get_var($wpdb->prepare(
            "SELECT fbclid FROM $tt WHERE visitor_id=%s AND fbclid IS NOT NULL ORDER BY touched_at DESC LIMIT 1",
            $visitor_id
        ));

        $event = [
            'event_name'      => 'Purchase',
            'event_time'      => time(),
            'event_source_url' => site_url(),
            'action_source'   => 'website',
            'event_id'        => $type . '_' . $conversion_id . '_' . time(),
            'user_data'       => array_filter([
                'em'  => $email ? [hash('sha256', strtolower(trim($email)))] : null,
                'ph'  => $phone ? [hash('sha256', preg_replace('/\D/', '', $phone))] : null,
                'fbc' => $fbclid ? 'fb.1.' . (time() * 1000) . '.' . $fbclid : null,
                'client_ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ]),
            'custom_data' => [
                'value'            => $value,
                'currency'         => 'USD',
                'content_name'     => $content_name,
                'content_category' => ($type === 'camp_booking') ? 'camp' : 'training',
                'content_type'     => 'product',
            ],
        ];

        $url = "https://graph.facebook.com/v19.0/{$pixel_id}/events";
        $response = wp_remote_post($url, [
            'body'     => wp_json_encode([
                'data'         => [$event],
                'access_token' => $token,
            ]),
            'headers'  => ['Content-Type' => 'application/json'],
            'timeout'  => 10,
            'blocking' => false, // non-blocking so checkout isn't slowed
        ]);

        error_log("[PTP-CC CAPI] Sent Purchase event: email=" . substr($email, 0, 5) . "*** value=$value type=$type");
    }

    /**
     * Send a Lead event to Meta CAPI (for training applications).
     */
    public static function fire_capi_lead($email, $phone, $visitor_id) {
        $pixel_id = get_option('ptp_cc_meta_pixel_id', '');
        $token    = get_option('ptp_cc_meta_access_token', '');
        if (!$pixel_id || !$token) return;

        global $wpdb;
        $tt = self::touches_table();
        $fbclid = $wpdb->get_var($wpdb->prepare(
            "SELECT fbclid FROM $tt WHERE visitor_id=%s AND fbclid IS NOT NULL ORDER BY touched_at DESC LIMIT 1",
            $visitor_id
        ));

        $event = [
            'event_name'      => 'Lead',
            'event_time'      => time(),
            'event_source_url' => site_url(),
            'action_source'   => 'website',
            'event_id'        => 'lead_' . md5($email) . '_' . time(),
            'user_data'       => array_filter([
                'em'  => $email ? [hash('sha256', strtolower(trim($email)))] : null,
                'ph'  => $phone ? [hash('sha256', preg_replace('/\D/', '', $phone))] : null,
                'fbc' => $fbclid ? 'fb.1.' . (time() * 1000) . '.' . $fbclid : null,
            ]),
        ];

        wp_remote_post("https://graph.facebook.com/v19.0/{$pixel_id}/events", [
            'body'     => wp_json_encode(['data' => [$event], 'access_token' => $token]),
            'headers'  => ['Content-Type' => 'application/json'],
            'timeout'  => 10,
            'blocking' => false,
        ]);
    }

    // ═══════════════════════════════════════
    // GOOGLE OFFLINE CONVERSION IMPORT
    // ═══════════════════════════════════════

    /**
     * Send an offline conversion to Google Ads.
     */
    public static function fire_google_conversion($gclid, $value, $conversion_time = null) {
        $customer_id     = get_option('ptp_cc_google_customer_id', '');
        $conversion_action = get_option('ptp_cc_google_conversion_action', '');
        $developer_token = get_option('ptp_cc_google_developer_token', '');
        $access_token    = get_option('ptp_cc_google_access_token', '');

        if (!$customer_id || !$conversion_action || !$developer_token || !$access_token || !$gclid) return;

        $conversion_time = $conversion_time ?: date('Y-m-d H:i:sP');

        $body = [
            'conversions' => [[
                'gclid'              => $gclid,
                'conversionAction'   => "customers/{$customer_id}/conversionActions/{$conversion_action}",
                'conversionDateTime' => $conversion_time,
                'conversionValue'    => $value,
                'currencyCode'       => 'USD',
            ]],
            'partialFailure' => true,
        ];

        $cid = str_replace('-', '', $customer_id);
        $url = "https://googleads.googleapis.com/v16/customers/{$cid}:uploadClickConversions";

        wp_remote_post($url, [
            'body'    => wp_json_encode($body),
            'headers' => [
                'Content-Type'    => 'application/json',
                'Authorization'   => 'Bearer ' . $access_token,
                'developer-token' => $developer_token,
            ],
            'timeout'  => 10,
            'blocking' => false,
        ]);

        error_log("[PTP-CC Google] Sent offline conversion: gclid=" . substr($gclid, 0, 10) . "... value=$value");
    }

    // ═══════════════════════════════════════
    // AD SPEND SYNC
    // ═══════════════════════════════════════

    /**
     * Sync ad spend from Meta Marketing API.
     */
    public static function sync_meta_spend($date_from = null, $date_to = null) {
        $account_id   = get_option('ptp_cc_meta_ad_account_id', '');
        $access_token = get_option('ptp_cc_meta_access_token', '');
        if (!$account_id || !$access_token) return ['error' => 'Meta not configured'];

        $date_from = $date_from ?: date('Y-m-d', strtotime('-2 days'));
        $date_to   = $date_to ?: date('Y-m-d');

        $url = "https://graph.facebook.com/v19.0/act_{$account_id}/insights?" . http_build_query([
            'access_token' => $access_token,
            'fields'       => 'campaign_id,campaign_name,adset_id,adset_name,ad_id,ad_name,spend,impressions,clicks,actions,cpm,cpc,ctr',
            'level'        => 'campaign',
            'time_range'   => wp_json_encode(['since' => $date_from, 'until' => $date_to]),
            'time_increment' => 1, // daily breakdown
        ]);

        $response = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($response)) return ['error' => $response->get_error_message()];

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['data'])) return ['error' => $body['error']['message'] ?? 'Unknown Meta API error'];

        global $wpdb;
        $table = self::ad_spend_table();
        $synced = 0;

        foreach ($body['data'] as $row) {
            $date = $row['date_start'] ?? $date_from;

            // Extract conversions from actions array
            $conversions = 0;
            $conv_value = 0;
            foreach (($row['actions'] ?? []) as $action) {
                if (in_array($action['action_type'], ['offsite_conversion.fb_pixel_purchase', 'purchase'])) {
                    $conversions += (int)($action['value'] ?? 0);
                }
            }

            $data = [
                'platform'         => 'meta',
                'account_id'       => $account_id,
                'campaign_id'      => $row['campaign_id'] ?? '',
                'campaign_name'    => $row['campaign_name'] ?? '',
                'adset_id'         => $row['adset_id'] ?? null,
                'adset_name'       => $row['adset_name'] ?? null,
                'ad_id'            => $row['ad_id'] ?? null,
                'ad_name'          => $row['ad_name'] ?? null,
                'spend_date'       => $date,
                'spend'            => (float)($row['spend'] ?? 0),
                'impressions'      => (int)($row['impressions'] ?? 0),
                'clicks'           => (int)($row['clicks'] ?? 0),
                'conversions'      => $conversions,
                'conversion_value' => $conv_value,
                'cpm'              => (float)($row['cpm'] ?? 0),
                'cpc'              => (float)($row['cpc'] ?? 0),
                'ctr'              => (float)($row['ctr'] ?? 0),
                'raw_data'         => wp_json_encode($row),
                'synced_at'        => current_time('mysql'),
            ];

            // Upsert: ON DUPLICATE KEY UPDATE
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE platform='meta' AND spend_date=%s AND campaign_id=%s",
                $date, $data['campaign_id']
            ));

            if ($existing) {
                $wpdb->update($table, $data, ['id' => $existing]);
            } else {
                $wpdb->insert($table, $data);
            }
            $synced++;
        }

        update_option('ptp_cc_meta_last_sync', current_time('mysql'));
        return ['synced' => $synced, 'platform' => 'meta', 'range' => "$date_from to $date_to"];
    }

    /**
     * Sync ad spend from Google Ads Reporting API.
     */
    public static function sync_google_spend($date_from = null, $date_to = null) {
        $customer_id     = get_option('ptp_cc_google_customer_id', '');
        $developer_token = get_option('ptp_cc_google_developer_token', '');
        $access_token    = get_option('ptp_cc_google_access_token', '');
        if (!$customer_id || !$developer_token || !$access_token) return ['error' => 'Google Ads not configured'];

        $date_from = $date_from ?: date('Y-m-d', strtotime('-2 days'));
        $date_to   = $date_to ?: date('Y-m-d');

        $cid = str_replace('-', '', $customer_id);
        $query = "SELECT campaign.id, campaign.name, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value, segments.date FROM campaign WHERE segments.date BETWEEN '$date_from' AND '$date_to'";

        $url = "https://googleads.googleapis.com/v16/customers/{$cid}/googleAds:searchStream";
        $response = wp_remote_post($url, [
            'body'    => wp_json_encode(['query' => $query]),
            'headers' => [
                'Content-Type'    => 'application/json',
                'Authorization'   => 'Bearer ' . $access_token,
                'developer-token' => $developer_token,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) return ['error' => $response->get_error_message()];

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $results = $body[0]['results'] ?? $body['results'] ?? [];

        global $wpdb;
        $table = self::ad_spend_table();
        $synced = 0;

        foreach ($results as $row) {
            $campaign   = $row['campaign'] ?? [];
            $metrics    = $row['metrics'] ?? [];
            $date       = $row['segments']['date'] ?? $date_from;
            $spend      = ($metrics['costMicros'] ?? 0) / 1000000;

            $data = [
                'platform'         => 'google',
                'account_id'       => $customer_id,
                'campaign_id'      => $campaign['id'] ?? '',
                'campaign_name'    => $campaign['name'] ?? '',
                'spend_date'       => $date,
                'spend'            => $spend,
                'impressions'      => (int)($metrics['impressions'] ?? 0),
                'clicks'           => (int)($metrics['clicks'] ?? 0),
                'conversions'      => (int)($metrics['conversions'] ?? 0),
                'conversion_value' => (float)($metrics['conversionsValue'] ?? 0),
                'cpm'              => ($metrics['impressions'] ?? 0) > 0 ? round($spend / (($metrics['impressions'] ?? 1) / 1000), 2) : 0,
                'cpc'              => ($metrics['clicks'] ?? 0) > 0 ? round($spend / ($metrics['clicks'] ?? 1), 2) : 0,
                'ctr'              => ($metrics['impressions'] ?? 0) > 0 ? round(($metrics['clicks'] ?? 0) / ($metrics['impressions'] ?? 1) * 100, 4) : 0,
                'raw_data'         => wp_json_encode($row),
                'synced_at'        => current_time('mysql'),
            ];

            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE platform='google' AND spend_date=%s AND campaign_id=%s",
                $date, $data['campaign_id']
            ));

            if ($existing) {
                $wpdb->update($table, $data, ['id' => $existing]);
            } else {
                $wpdb->insert($table, $data);
            }
            $synced++;
        }

        update_option('ptp_cc_google_last_sync', current_time('mysql'));
        return ['synced' => $synced, 'platform' => 'google', 'range' => "$date_from to $date_to"];
    }

    /**
     * Cron: sync both platforms.
     */
    public static function cron_sync_spend() {
        $results = [];
        if (get_option('ptp_cc_meta_ad_account_id')) {
            $results['meta'] = self::sync_meta_spend();
        }
        if (get_option('ptp_cc_google_customer_id')) {
            $results['google'] = self::sync_google_spend();
        }
        error_log('[PTP-CC Attribution] Cron spend sync: ' . wp_json_encode($results));
    }

    /**
     * Cron: clean up old unconverted touches (90 days).
     */
    public static function cron_cleanup() {
        global $wpdb;
        $deleted = $wpdb->query(
            "DELETE FROM " . self::touches_table() . " WHERE customer_email IS NULL AND touched_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );
        if ($deleted) error_log("[PTP-CC Attribution] Cleaned up $deleted old unconverted touches");
    }

    // ═══════════════════════════════════════
    // DASHBOARD API ENDPOINTS
    // ═══════════════════════════════════════

    /**
     * ROAS overview — aggregated spend vs revenue by platform and period.
     */
    public static function api_overview($req) {
        global $wpdb;
        $period = $req->get_param('period') ?: 'month'; // month, quarter, year, all
        $spend_table = self::ad_spend_table();
        $attr_table  = self::customer_table();

        // Date range
        switch ($period) {
            case 'week':    $from = date('Y-m-d', strtotime('-7 days')); break;
            case 'month':   $from = date('Y-m-01'); break;
            case 'quarter': $from = date('Y-m-01', strtotime('first day of -2 months')); break;
            case 'year':    $from = date('Y-01-01'); break;
            default:        $from = '2020-01-01';
        }
        $to = date('Y-m-d');

        // Ad spend by platform
        $spend_by_platform = $wpdb->get_results($wpdb->prepare(
            "SELECT platform, COALESCE(SUM(spend),0) as total_spend, SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(conversions) as conversions
             FROM $spend_table WHERE spend_date BETWEEN %s AND %s GROUP BY platform",
            $from, $to
        )) ?: [];

        $total_spend = 0;
        $platform_data = [];
        foreach ($spend_by_platform as $row) {
            $total_spend += (float)$row->total_spend;
            $platform_data[$row->platform] = [
                'spend'       => (float)$row->total_spend,
                'impressions' => (int)$row->impressions,
                'clicks'      => (int)$row->clicks,
                'conversions' => (int)$row->conversions,
            ];
        }

        // Revenue from attributed customers (customers acquired in this period)
        $attributed_revenue = 0;
        $attributed_customers = 0;

        // Get camp revenue from attributed customers
        $cb = CC_DB::camp_bookings();
        $has_cb = $wpdb->get_var("SHOW TABLES LIKE '$cb'") === $cb;
        if ($has_cb) {
            $rev_row = $wpdb->get_row($wpdb->prepare(
                "SELECT COALESCE(SUM(b.amount_paid),0) as rev, COUNT(DISTINCT b.customer_email) as custs
                 FROM $cb b
                 INNER JOIN $attr_table a ON LOWER(b.customer_email) = a.customer_email
                 WHERE b.status='confirmed' AND b.created_at >= %s AND a.acquisition_channel IN ('meta','google')",
                $from . ' 00:00:00'
            ));
            $attributed_revenue += (float)($rev_row->rev ?? 0);
            $attributed_customers += (int)($rev_row->custs ?? 0);
        }

        // Training revenue from attributed customers
        $bt = CC_DB::bookings();
        if ($wpdb->get_var("SHOW TABLES LIKE '$bt'") === $bt) {
            $tr_row = $wpdb->get_row($wpdb->prepare(
                "SELECT COALESCE(SUM(b.total_amount),0) as rev
                 FROM $bt b
                 INNER JOIN " . CC_DB::parents() . " p ON b.parent_id = p.id
                 INNER JOIN $attr_table a ON LOWER(p.email) = a.customer_email
                 WHERE b.created_at >= %s AND a.acquisition_channel IN ('meta','google')",
                $from . ' 00:00:00'
            ));
            $attributed_revenue += (float)($tr_row->rev ?? 0);
        }

        // Total revenue (all channels) for context
        $total_revenue = 0;
        if ($has_cb) {
            $total_revenue += (float)$wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount_paid),0) FROM $cb WHERE status='confirmed' AND created_at >= %s",
                $from . ' 00:00:00'
            ));
        }
        if ($wpdb->get_var("SHOW TABLES LIKE '$bt'") === $bt) {
            $total_revenue += (float)$wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(total_amount),0) FROM $bt WHERE created_at >= %s",
                $from . ' 00:00:00'
            ));
        }

        // Blended CAC
        $blended_cac = ($attributed_customers > 0) ? round($total_spend / $attributed_customers, 2) : 0;

        // ROAS per platform
        foreach ($platform_data as $k => &$pd) {
            // Get revenue attributed to this platform
            $plat_rev = 0;
            if ($has_cb) {
                $plat_rev += (float)$wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(b.amount_paid),0) FROM $cb b
                     INNER JOIN $attr_table a ON LOWER(b.customer_email)=a.customer_email
                     WHERE b.status='confirmed' AND b.created_at >= %s AND a.acquisition_channel=%s",
                    $from . ' 00:00:00', $k
                ));
            }
            $pd['revenue'] = $plat_rev;
            $pd['roas'] = $pd['spend'] > 0 ? round($plat_rev / $pd['spend'], 2) : 0;
            $pd['cac'] = $pd['conversions'] > 0 ? round($pd['spend'] / $pd['conversions'], 2) : 0;
        }

        // Monthly trend (last 6 months)
        $monthly = $wpdb->get_results(
            "SELECT DATE_FORMAT(spend_date,'%Y-%m') as month, platform,
             COALESCE(SUM(spend),0) as spend, SUM(clicks) as clicks
             FROM $spend_table WHERE spend_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
             GROUP BY month, platform ORDER BY month ASC"
        ) ?: [];

        // Attribution channel breakdown (all customers)
        $channels = $wpdb->get_results(
            "SELECT acquisition_channel as channel, COUNT(*) as customers, AVG(days_to_convert) as avg_days
             FROM $attr_table GROUP BY acquisition_channel ORDER BY customers DESC"
        ) ?: [];

        return [
            'period'              => $period,
            'from'                => $from,
            'total_spend'         => $total_spend,
            'total_revenue'       => $total_revenue,
            'attributed_revenue'  => $attributed_revenue,
            'attributed_customers' => $attributed_customers,
            'organic_revenue'     => $total_revenue - $attributed_revenue,
            'blended_cac'         => $blended_cac,
            'overall_roas'        => $total_spend > 0 ? round($attributed_revenue / $total_spend, 2) : 0,
            'platforms'           => $platform_data,
            'monthly'             => $monthly,
            'channels'            => $channels,
        ];
    }

    /**
     * Campaign-level breakdown with spend + conversions + ROAS.
     */
    public static function api_campaigns($req) {
        global $wpdb;
        $period   = $req->get_param('period') ?: 'month';
        $platform = $req->get_param('platform') ?: '';
        $table    = self::ad_spend_table();
        $attr     = self::customer_table();

        $from = ($period === 'week') ? date('Y-m-d', strtotime('-7 days'))
              : (($period === 'year') ? date('Y-01-01') : date('Y-m-01'));

        $where = "spend_date >= '$from'";
        if ($platform) $where .= $wpdb->prepare(" AND platform=%s", $platform);

        $campaigns = $wpdb->get_results(
            "SELECT campaign_id, campaign_name, platform,
             COALESCE(SUM(spend),0) as spend, SUM(impressions) as impressions,
             SUM(clicks) as clicks, SUM(conversions) as conversions,
             AVG(cpm) as avg_cpm, AVG(cpc) as avg_cpc
             FROM $table WHERE $where
             GROUP BY campaign_id, campaign_name, platform
             ORDER BY spend DESC LIMIT 50"
        ) ?: [];

        // Enrich with attributed revenue
        $cb = CC_DB::camp_bookings();
        $has_cb = $wpdb->get_var("SHOW TABLES LIKE '$cb'") === $cb;

        foreach ($campaigns as &$c) {
            $campaign_name = $c->campaign_name;
            $rev = 0;
            $custs = 0;

            // Match by utm_campaign on customer attribution
            if ($campaign_name && $has_cb) {
                $r = $wpdb->get_row($wpdb->prepare(
                    "SELECT COALESCE(SUM(b.amount_paid),0) as rev, COUNT(DISTINCT b.customer_email) as custs
                     FROM $cb b
                     INNER JOIN $attr a ON LOWER(b.customer_email)=a.customer_email
                     WHERE b.status='confirmed' AND b.created_at >= %s
                     AND (a.first_touch_campaign=%s OR a.last_touch_campaign=%s)",
                    $from . ' 00:00:00', $campaign_name, $campaign_name
                ));
                $rev = (float)($r->rev ?? 0);
                $custs = (int)($r->custs ?? 0);
            }

            $c->attributed_revenue = $rev;
            $c->attributed_customers = $custs;
            $c->roas = (float)$c->spend > 0 ? round($rev / (float)$c->spend, 2) : 0;
            $c->cac = $custs > 0 ? round((float)$c->spend / $custs, 2) : 0;
        }

        return ['campaigns' => $campaigns, 'period' => $period, 'from' => $from];
    }

    /**
     * Cohort analysis: LTV by acquisition month and channel.
     */
    public static function api_cohorts($req) {
        global $wpdb;
        $attr  = self::customer_table();
        $cb    = CC_DB::camp_bookings();
        $has_cb = $wpdb->get_var("SHOW TABLES LIKE '$cb'") === $cb;
        $spend = self::ad_spend_table();

        $cohorts = $wpdb->get_results(
            "SELECT DATE_FORMAT(first_touch_at,'%Y-%m') as cohort_month, acquisition_channel as channel,
             COUNT(*) as customers, AVG(days_to_convert) as avg_days_to_convert
             FROM $attr WHERE first_touch_at IS NOT NULL
             GROUP BY cohort_month, channel ORDER BY cohort_month DESC LIMIT 24"
        ) ?: [];

        foreach ($cohorts as &$co) {
            $co->total_ltv = 0;
            $co->avg_ltv = 0;
            $co->avg_cac = 0;
            $co->ltv_cac_ratio = 0;

            if ($has_cb) {
                $ltv = $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(b.amount_paid),0) FROM $cb b
                     INNER JOIN $attr a ON LOWER(b.customer_email)=a.customer_email
                     WHERE b.status='confirmed'
                     AND DATE_FORMAT(a.first_touch_at,'%%Y-%%m')=%s AND a.acquisition_channel=%s",
                    $co->cohort_month, $co->channel
                ));
                $co->total_ltv = (float)$ltv;
                $co->avg_ltv = (int)$co->customers > 0 ? round((float)$ltv / (int)$co->customers, 2) : 0;
            }

            // Get spend for this cohort month + channel
            $plat = ($co->channel === 'meta') ? 'meta' : (($co->channel === 'google') ? 'google' : '');
            if ($plat) {
                $cohort_spend = (float)$wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(spend),0) FROM $spend WHERE platform=%s AND DATE_FORMAT(spend_date,'%%Y-%%m')=%s",
                    $plat, $co->cohort_month
                ));
                $co->total_spend = $cohort_spend;
                $co->avg_cac = (int)$co->customers > 0 ? round($cohort_spend / (int)$co->customers, 2) : 0;
                $co->ltv_cac_ratio = $co->avg_cac > 0 ? round($co->avg_ltv / $co->avg_cac, 1) : 0;
            }
        }

        return ['cohorts' => $cohorts];
    }

    /**
     * Per-customer attribution data (used in Customer360 enhancement).
     */
    public static function api_customer_attribution($req) {
        global $wpdb;
        $email = strtolower(urldecode($req['email']));
        $ct = self::customer_table();
        $tt = self::touches_table();

        $attr = $wpdb->get_row($wpdb->prepare("SELECT * FROM $ct WHERE customer_email=%s", $email));
        $touches = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $tt WHERE customer_email=%s ORDER BY touched_at ASC", $email
        )) ?: [];

        // Estimate CAC: spend for the campaign / conversions from that campaign in the same period
        $estimated_cac = null;
        if ($attr && $attr->first_touch_campaign && $attr->first_touch_at) {
            $spend_table = self::ad_spend_table();
            $month = date('Y-m', strtotime($attr->first_touch_at));
            $plat = $attr->acquisition_channel === 'meta' ? 'meta' : ($attr->acquisition_channel === 'google' ? 'google' : '');
            if ($plat) {
                $campaign_data = $wpdb->get_row($wpdb->prepare(
                    "SELECT COALESCE(SUM(spend),0) as spend, COALESCE(SUM(conversions),0) as convs
                     FROM $spend_table WHERE platform=%s AND campaign_name=%s AND DATE_FORMAT(spend_date,'%%Y-%%m')=%s",
                    $plat, $attr->first_touch_campaign, $month
                ));
                if ($campaign_data && (int)$campaign_data->convs > 0) {
                    $estimated_cac = round((float)$campaign_data->spend / (int)$campaign_data->convs, 2);
                }
            }
        }

        return [
            'attribution'   => $attr,
            'touches'       => $touches,
            'estimated_cac' => $estimated_cac,
        ];
    }

    /**
     * Recent attribution touches (admin view).
     */
    public static function api_recent_touches($req) {
        global $wpdb;
        $limit = min((int)($req->get_param('limit') ?: 50), 200);
        $touches = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::touches_table() . " ORDER BY touched_at DESC LIMIT %d", $limit
        )) ?: [];
        return ['touches' => $touches, 'total' => (int)$wpdb->get_var("SELECT COUNT(*) FROM " . self::touches_table())];
    }

    // ─── Ad Spend API ───

    public static function api_sync_status() {
        return [
            'meta_configured'    => (bool)get_option('ptp_cc_meta_ad_account_id'),
            'meta_last_sync'     => get_option('ptp_cc_meta_last_sync', ''),
            'google_configured'  => (bool)get_option('ptp_cc_google_customer_id'),
            'google_last_sync'   => get_option('ptp_cc_google_last_sync', ''),
            'capi_configured'    => (bool)get_option('ptp_cc_meta_pixel_id') && (bool)get_option('ptp_cc_meta_access_token'),
            'next_sync'          => wp_next_scheduled('ptp_cc_ad_spend_sync') ? date('Y-m-d H:i:s', wp_next_scheduled('ptp_cc_ad_spend_sync')) : 'Not scheduled',
        ];
    }

    public static function api_sync_now() {
        $results = [];
        if (get_option('ptp_cc_meta_ad_account_id')) {
            $results['meta'] = self::sync_meta_spend();
        }
        if (get_option('ptp_cc_google_customer_id')) {
            $results['google'] = self::sync_google_spend();
        }
        if (empty($results)) return ['error' => 'No ad platforms configured. Add Meta or Google credentials in Settings.'];
        return $results;
    }

    public static function api_daily_spend($req) {
        global $wpdb;
        $from = $req->get_param('from') ?: date('Y-m-01');
        $to   = $req->get_param('to') ?: date('Y-m-d');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::ad_spend_table() . " WHERE spend_date BETWEEN %s AND %s ORDER BY spend_date DESC, platform",
            $from, $to
        ));
        return ['data' => $rows ?: []];
    }

    public static function api_manual_spend($req) {
        global $wpdb;
        $b = $req->get_json_params();
        $wpdb->insert(self::ad_spend_table(), [
            'platform'      => sanitize_text_field($b['platform'] ?? 'meta'),
            'account_id'    => 'manual',
            'campaign_id'   => sanitize_text_field($b['campaign_id'] ?? 'manual_' . time()),
            'campaign_name' => sanitize_text_field($b['campaign_name'] ?? ''),
            'spend_date'    => sanitize_text_field($b['spend_date'] ?? date('Y-m-d')),
            'spend'         => (float)($b['spend'] ?? 0),
            'impressions'   => (int)($b['impressions'] ?? 0),
            'clicks'        => (int)($b['clicks'] ?? 0),
            'conversions'   => (int)($b['conversions'] ?? 0),
        ]);
        return ['ok' => true, 'id' => $wpdb->insert_id];
    }

    // ─── Connection tests ───

    public static function api_test_meta() {
        $token = get_option('ptp_cc_meta_access_token', '');
        $pixel = get_option('ptp_cc_meta_pixel_id', '');
        $acct  = get_option('ptp_cc_meta_ad_account_id', '');

        if (!$token) return ['connected' => false, 'error' => 'No access token configured'];

        // Test token validity
        $r = wp_remote_get("https://graph.facebook.com/v19.0/me?access_token=$token", ['timeout' => 10]);
        if (is_wp_error($r)) return ['connected' => false, 'error' => $r->get_error_message()];

        $body = json_decode(wp_remote_retrieve_body($r), true);
        if (isset($body['error'])) return ['connected' => false, 'error' => $body['error']['message']];

        return [
            'connected'  => true,
            'user'       => $body['name'] ?? $body['id'] ?? 'Unknown',
            'pixel_id'   => $pixel,
            'ad_account' => $acct,
            'capi_ready' => (bool)$pixel,
            'spend_ready' => (bool)$acct,
        ];
    }

    public static function api_test_google() {
        $cid = get_option('ptp_cc_google_customer_id', '');
        $token = get_option('ptp_cc_google_access_token', '');
        if (!$cid || !$token) return ['connected' => false, 'error' => 'Missing credentials'];
        return ['connected' => true, 'customer_id' => $cid, 'note' => 'Full validation requires API call'];
    }

    // ─── Settings ───

    public static function api_get_settings() {
        return [
            'meta_pixel_id'             => get_option('ptp_cc_meta_pixel_id', ''),
            'meta_access_token'         => get_option('ptp_cc_meta_access_token', '') ? '••••••••' : '',
            'meta_ad_account_id'        => get_option('ptp_cc_meta_ad_account_id', ''),
            'google_customer_id'        => get_option('ptp_cc_google_customer_id', ''),
            'google_developer_token'    => get_option('ptp_cc_google_developer_token', '') ? '••••••••' : '',
            'google_access_token'       => get_option('ptp_cc_google_access_token', '') ? '••••••••' : '',
            'google_conversion_action'  => get_option('ptp_cc_google_conversion_action', ''),
            'pixel_enabled'             => get_option('ptp_cc_attribution_pixel_enabled', '1'),
        ];
    }

    public static function api_save_settings($req) {
        $b = $req->get_json_params();
        $fields = [
            'meta_pixel_id'            => 'ptp_cc_meta_pixel_id',
            'meta_access_token'        => 'ptp_cc_meta_access_token',
            'meta_ad_account_id'       => 'ptp_cc_meta_ad_account_id',
            'google_customer_id'       => 'ptp_cc_google_customer_id',
            'google_developer_token'   => 'ptp_cc_google_developer_token',
            'google_access_token'      => 'ptp_cc_google_access_token',
            'google_conversion_action' => 'ptp_cc_google_conversion_action',
            'pixel_enabled'            => 'ptp_cc_attribution_pixel_enabled',
        ];
        foreach ($fields as $key => $opt) {
            if (isset($b[$key])) {
                $val = sanitize_text_field($b[$key]);
                // Don't overwrite tokens with masked value
                if ($val === '••••••••') continue;
                update_option($opt, $val);
            }
        }
        return ['saved' => true];
    }

    // ═══════════════════════════════════════
    // HOOKS — Wire into existing booking flows
    // ═══════════════════════════════════════

    /**
     * Register all WordPress hooks for attribution tracking.
     */
    public static function register_hooks() {
        // Inject pixel on frontend
        if (get_option('ptp_cc_attribution_pixel_enabled', '1') === '1') {
            add_action('wp_head', [__CLASS__, 'inject_pixel'], 5);
        }

        // Camp booking confirmed (from Camps plugin)
        add_action('ptp_camp_booking_confirmed', [__CLASS__, 'on_camp_booking'], 10, 2);
        // Fallback: if camps plugin fires a different hook
        add_action('ptp_camp_order_completed', [__CLASS__, 'on_camp_order_completed'], 10, 1);

        // Training booking paid
        add_action('ptp_booking_paid', [__CLASS__, 'on_training_booking'], 10, 1);
        add_action('ptp_booking_completed', [__CLASS__, 'on_training_booking'], 10, 1);

        // Training application submitted
        add_action('ptp_application_created', [__CLASS__, 'on_application_created'], 10, 2);
    }

    /**
     * Camp booking confirmed → resolve attribution + fire CAPI.
     */
    public static function on_camp_booking($booking_id, $booking_data = []) {
        $email = $booking_data['customer_email'] ?? '';
        $phone = $booking_data['customer_phone'] ?? '';
        $visitor_id = $booking_data['visitor_id'] ?? ($_POST['ptp_visitor_id'] ?? ($_COOKIE['ptp_vid'] ?? ''));

        if (!$email) {
            // Try to get from DB
            global $wpdb;
            $bt = CC_DB::camp_bookings();
            $row = $wpdb->get_row($wpdb->prepare("SELECT customer_email, customer_phone FROM $bt WHERE id=%d", $booking_id));
            if ($row) {
                $email = $row->customer_email;
                $phone = $row->customer_phone;
            }
        }

        if ($email && $visitor_id) {
            self::resolve_conversion($visitor_id, $email, $phone, 'camp_booking', $booking_id);
        } elseif ($email) {
            // No visitor_id but we have email — still compute attribution from any prior touches
            self::compute_attribution($email, $phone);
            // Still fire CAPI with email-based matching
            self::fire_capi_purchase($email, $phone, '', 'camp_booking', $booking_id);
        }

        // Fire Google conversion if gclid available
        if ($email) {
            global $wpdb;
            $gclid = $wpdb->get_var($wpdb->prepare(
                "SELECT gclid FROM " . self::touches_table() . " WHERE customer_email=%s AND gclid IS NOT NULL ORDER BY touched_at DESC LIMIT 1",
                strtolower($email)
            ));
            if ($gclid) {
                $value = $booking_data['amount_paid'] ?? 0;
                self::fire_google_conversion($gclid, $value);
            }
        }
    }

    /**
     * Camp order completed (fallback for unified camp orders).
     */
    public static function on_camp_order_completed($order_id) {
        global $wpdb;
        $co = CC_DB::camp_orders();
        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $co WHERE id=%d", $order_id));
        if (!$order) return;

        self::on_camp_booking($order_id, [
            'customer_email' => $order->billing_email,
            'customer_phone' => $order->billing_phone ?? '',
            'amount_paid'    => $order->total_amount,
        ]);
    }

    /**
     * Training booking paid → resolve attribution + fire CAPI.
     */
    public static function on_training_booking($booking_id) {
        global $wpdb;
        $bt = CC_DB::bookings();
        $pt = CC_DB::parents();

        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM $bt WHERE id=%d", $booking_id));
        if (!$booking) return;

        $parent = $wpdb->get_row($wpdb->prepare("SELECT * FROM $pt WHERE id=%d", $booking->parent_id));
        if (!$parent) return;

        $visitor_id = $_POST['ptp_visitor_id'] ?? ($_COOKIE['ptp_vid'] ?? '');

        if ($parent->email && $visitor_id) {
            self::resolve_conversion($visitor_id, $parent->email, $parent->phone ?? '', 'training_booking', $booking_id);
        } elseif ($parent->email) {
            self::compute_attribution($parent->email, $parent->phone ?? '');
            self::fire_capi_purchase($parent->email, $parent->phone ?? '', '', 'training_booking', $booking_id);
        }
    }

    /**
     * Training application submitted → fire Lead event.
     */
    public static function on_application_created($app_id, $app_data = []) {
        $email = $app_data['email'] ?? '';
        $phone = $app_data['phone'] ?? '';
        $visitor_id = $app_data['visitor_id'] ?? ($_POST['ptp_visitor_id'] ?? ($_COOKIE['ptp_vid'] ?? ''));

        if ($email && $visitor_id) {
            // Record the touch as a lead conversion
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "UPDATE " . self::touches_table() . " SET customer_email=%s, customer_phone=%s, conversion_type='application', conversion_id=%d
                 WHERE visitor_id=%s AND customer_email IS NULL",
                strtolower($email), $phone, $app_id, $visitor_id
            ));
            self::compute_attribution($email, $phone);
        }

        if ($email) {
            self::fire_capi_lead($email, $phone, $visitor_id ?: '');
        }
    }

    /**
     * Get attribution data for Customer360 integration.
     * Called from CC_API::get_customer360() to enrich profile.
     */
    public static function get_customer_attribution_data($email) {
        global $wpdb;
        $ct = self::customer_table();
        $tt = self::touches_table();
        $sp = self::ad_spend_table();

        $attr = $wpdb->get_row($wpdb->prepare("SELECT * FROM $ct WHERE customer_email=%s", strtolower(trim($email))));
        if (!$attr) return null;

        // Estimate CAC
        $estimated_cac = null;
        if ($attr->first_touch_campaign && $attr->first_touch_at) {
            $month = date('Y-m', strtotime($attr->first_touch_at));
            $plat = $attr->acquisition_channel === 'meta' ? 'meta' : ($attr->acquisition_channel === 'google' ? 'google' : '');
            if ($plat) {
                $cd = $wpdb->get_row($wpdb->prepare(
                    "SELECT COALESCE(SUM(spend),0) as spend, COALESCE(SUM(conversions),0) as convs
                     FROM $sp WHERE platform=%s AND campaign_name=%s AND DATE_FORMAT(spend_date,'%%Y-%%m')=%s",
                    $plat, $attr->first_touch_campaign, $month
                ));
                if ($cd && (int)$cd->convs > 0) {
                    $estimated_cac = round((float)$cd->spend / (int)$cd->convs, 2);
                }
            }
        }

        $touch_count = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $tt WHERE customer_email=%s", strtolower(trim($email))
        ));

        return [
            'acquisition_channel'  => $attr->acquisition_channel,
            'first_touch_source'   => $attr->first_touch_source,
            'first_touch_campaign' => $attr->first_touch_campaign,
            'first_touch_at'       => $attr->first_touch_at,
            'first_touch_landing'  => $attr->first_touch_landing,
            'last_touch_source'    => $attr->last_touch_source,
            'last_touch_campaign'  => $attr->last_touch_campaign,
            'total_touches'        => $attr->total_touches ?: $touch_count,
            'days_to_convert'      => $attr->days_to_convert,
            'estimated_cac'        => $estimated_cac,
        ];
    }
}
