<?php
/**
 * PTP Command Center — Standalone Database Layer
 * Creates ALL tables. If PTP Training Platform tables exist, reads from those.
 * Otherwise uses its own cc_ prefixed tables.
 */
if (!defined('ABSPATH')) exit;

class CC_DB {
    private static $table_cache = [];

    public static function t($n) {
        global $wpdb;
        return $wpdb->prefix . $n;
    }

    // Smart table getters — use TP tables if they exist, otherwise CC standalone
    public static function has_table($name) {
        if (isset(self::$table_cache[$name])) return self::$table_cache[$name];
        global $wpdb;
        $t = $wpdb->prefix . $name;
        self::$table_cache[$name] = ($wpdb->get_var("SHOW TABLES LIKE '$t'") === $t);
        return self::$table_cache[$name];
    }

    /**
     * Clear table cache (useful after table creation or in tests)
     */
    public static function flush_table_cache() {
        self::$table_cache = [];
    }

    /**
     * Public table existence check (cached)
     */
    public static function has_table_public($name) {
        return self::has_table($name);
    }

    // Core tables — prefer TP, fallback to CC standalone
    public static function apps() {
        return self::has_table('ptp_session_applications')
            ? self::t('ptp_session_applications')
            : self::t('ptp_cc_applications');
    }
    public static function parents() {
        return self::has_table('ptp_parents')
            ? self::t('ptp_parents')
            : self::t('ptp_cc_families');
    }
    public static function families() {
        return self::t('ptp_cc_families');
    }
    public static function players() {
        return self::has_table('ptp_players')
            ? self::t('ptp_players')
            : self::t('ptp_cc_players');
    }
    public static function bookings() {
        return self::has_table('ptp_bookings')
            ? self::t('ptp_bookings')
            : self::t('ptp_cc_bookings');
    }
    public static function trainers() {
        return self::has_table('ptp_trainers')
            ? self::t('ptp_trainers')
            : self::t('ptp_cc_trainers');
    }
    public static function reviews() {
        return self::has_table('ptp_reviews')
            ? self::t('ptp_reviews')
            : self::t('ptp_cc_reviews');
    }

    // Camp tables
    public static function camp_orders() { return self::t('ptp_unified_camp_orders'); }
    public static function camp_items()  { return self::t('ptp_camp_order_items'); }

    // CRM-only tables (always CC)
    public static function follow_ups()    { return self::t('ptp_cc_follow_ups'); }
    public static function op_msgs()       { return self::t('ptp_cc_openphone_messages'); }
    public static function drafts()        { return self::t('ptp_cc_ai_drafts'); }
    public static function rules()         { return self::t('ptp_cc_rules'); }
    public static function seg_hist()      { return self::t('ptp_cc_segment_history'); }
    public static function templates()     { return self::t('ptp_cc_templates'); }
    public static function activity()      { return self::t('ptp_cc_activity_log'); }

    // NEW standalone tables
    public static function training_links() { return self::t('ptp_cc_training_links'); }
    public static function retry_queue()    { return self::t('ptp_cc_sms_retry_queue'); }

    // Camp tables (from PTP Camps plugin)
    public static function camp_bookings()  { return self::t('ptp_camp_bookings'); }
    public static function camp_abandoned() {
        // Camps plugin creates ptp_camp_abandoned_carts (has status, cart_total, camp_names)
        // TP's ptp_abandoned_carts is for TRAINING session carts — different schema
        return self::t('ptp_camp_abandoned_carts');
    }
    public static function coupons()        { return self::t('ptp_coupons'); }
    public static function referrals()      { return self::t('ptp_referral_codes'); }
    public static function camp_waitlist()  {
        // Camps plugin creates ptp_camp_waitlist (camp waitlists)
        // TP's ptp_waitlist is for training product waitlists — different
        return self::t('ptp_camp_waitlist');
    }

    // Finance tables
    public static function expenses()       { return self::t('ptp_cc_expenses'); }

    // Attribution tables
    public static function attr_touches()  { return self::t('ptp_cc_attribution_touches'); }
    public static function attr_customers(){ return self::t('ptp_cc_customer_attribution'); }
    public static function ad_spend()      { return self::t('ptp_cc_ad_spend'); }

    public static function normalize_phone($p) {
        $d = preg_replace('/\D/', '', $p);
        if (!$d) return '';
        // US assumption: 10 digits → prepend country code 1
        if (strlen($d) === 10) $d = '1' . $d;
        // 11 digits starting with 1 = standard US; other lengths left as-is
        return '+' . $d;
    }

    /**
     * Create ALL tables on activation
     */
    public static function create_tables() {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ═══ STANDALONE CORE TABLES (only created if TP tables don't exist) ═══

        if (!self::has_table('ptp_session_applications')) {
            dbDelta("CREATE TABLE IF NOT EXISTS " . self::t('ptp_cc_applications') . " (
                id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                parent_name varchar(200),
                email varchar(200),
                phone varchar(30),
                child_name varchar(200),
                child_age varchar(10) DEFAULT NULL,
                club varchar(200) DEFAULT NULL,
                position varchar(100) DEFAULT NULL,
                experience_level varchar(50) DEFAULT NULL,
                biggest_challenge text,
                goal text,
                state varchar(50) DEFAULT NULL,
                status varchar(30) DEFAULT 'pending',
                lead_temperature varchar(20) DEFAULT NULL,
                trainer_slug varchar(100) DEFAULT NULL,
                trainer_name varchar(200) DEFAULT NULL,
                call_status varchar(50) DEFAULT NULL,
                call_notes text,
                call_scheduled_at datetime DEFAULT NULL,
                admin_notes text,
                accepted_at datetime DEFAULT NULL,
                utm_source varchar(200) DEFAULT NULL,
                utm_campaign varchar(200) DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                KEY status (status),
                KEY email (email),
                KEY phone (phone),
                KEY created_at (created_at),
                KEY status_created (status, created_at)
            ) $c;");
        }

        if (!self::has_table('ptp_parents')) {
            dbDelta("CREATE TABLE IF NOT EXISTS " . self::t('ptp_cc_families') . " (
                id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                display_name varchar(200),
                email varchar(200),
                phone varchar(30),
                city varchar(100) DEFAULT NULL,
                state varchar(50) DEFAULT NULL,
                zip varchar(20) DEFAULT NULL,
                notes text,
                total_spent decimal(10,2) DEFAULT 0,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                KEY email (email),
                KEY phone (phone)
            ) $c;");
        }

        if (!self::has_table('ptp_players')) {
            dbDelta("CREATE TABLE IF NOT EXISTS " . self::t('ptp_cc_players') . " (
                id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                parent_id bigint(20) UNSIGNED,
                first_name varchar(100),
                last_name varchar(100),
                age varchar(10) DEFAULT NULL,
                position varchar(100) DEFAULT NULL,
                club varchar(200) DEFAULT NULL,
                experience_level varchar(50) DEFAULT NULL,
                notes text,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                KEY parent_id (parent_id)
            ) $c;");
        }

        if (!self::has_table('ptp_trainers')) {
            dbDelta("CREATE TABLE IF NOT EXISTS " . self::t('ptp_cc_trainers') . " (
                id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                display_name varchar(200),
                slug varchar(100),
                email varchar(200),
                phone varchar(30),
                photo_url text,
                bio text,
                hourly_rate decimal(8,2) DEFAULT 70,
                location varchar(200) DEFAULT NULL,
                sport varchar(50) DEFAULT 'soccer',
                credentials text,
                status varchar(30) DEFAULT 'approved',
                average_rating decimal(3,2) DEFAULT 0,
                review_count int DEFAULT 0,
                total_sessions int DEFAULT 0,
                total_earnings decimal(10,2) DEFAULT 0,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                KEY slug (slug),
                KEY status (status)
            ) $c;");
        }

        if (!self::has_table('ptp_bookings')) {
            dbDelta("CREATE TABLE IF NOT EXISTS " . self::t('ptp_cc_bookings') . " (
                id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                parent_id bigint(20) UNSIGNED,
                trainer_id bigint(20) UNSIGNED DEFAULT NULL,
                player_id bigint(20) UNSIGNED DEFAULT NULL,
                training_link_id bigint(20) UNSIGNED DEFAULT NULL,
                session_date date,
                session_time varchar(20) DEFAULT NULL,
                duration_minutes int DEFAULT 60,
                location varchar(500) DEFAULT NULL,
                total_amount decimal(10,2) DEFAULT 0,
                platform_fee decimal(10,2) DEFAULT 0,
                trainer_payout decimal(10,2) DEFAULT 0,
                status varchar(30) DEFAULT 'pending',
                payment_status varchar(30) DEFAULT 'unpaid',
                stripe_session_id varchar(255) DEFAULT NULL,
                stripe_payment_intent varchar(255) DEFAULT NULL,
                notes text,
                booked_via varchar(50) DEFAULT 'admin',
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                KEY parent_id (parent_id),
                KEY trainer_id (trainer_id),
                KEY session_date (session_date),
                KEY status (status),
                KEY training_link_id (training_link_id),
                KEY parent_payment (parent_id, payment_status),
                KEY trainer_status_date (trainer_id, status, session_date)
            ) $c;");
        }

        if (!self::has_table('ptp_reviews')) {
            dbDelta("CREATE TABLE IF NOT EXISTS " . self::t('ptp_cc_reviews') . " (
                id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                parent_id bigint(20) UNSIGNED,
                trainer_id bigint(20) UNSIGNED,
                booking_id bigint(20) UNSIGNED DEFAULT NULL,
                rating int DEFAULT 5,
                review_text text,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                KEY parent_id (parent_id),
                KEY trainer_id (trainer_id)
            ) $c;");
        }

        // ═══ TRAINING LINKS TABLE (always CC) ═══
        dbDelta("CREATE TABLE IF NOT EXISTS " . self::training_links() . " (
            id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code varchar(50) UNIQUE,
            title varchar(200),
            description text,
            trainer_id bigint(20) UNSIGNED DEFAULT NULL,
            session_type varchar(50) DEFAULT '1on1',
            duration_minutes int DEFAULT 60,
            price decimal(10,2) DEFAULT 70,
            location varchar(500) DEFAULT NULL,
            max_bookings int DEFAULT 0,
            total_booked int DEFAULT 0,
            available_dates text,
            available_times text,
            stripe_price_id varchar(200) DEFAULT NULL,
            status varchar(20) DEFAULT 'active',
            expires_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            KEY code (code),
            KEY trainer_id (trainer_id),
            KEY status (status)
        ) $c;");

        // ═══ CRM TABLES (always CC) ═══
        dbDelta("CREATE TABLE IF NOT EXISTS " . self::follow_ups() . " (
            id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            app_id bigint(20) UNSIGNED DEFAULT NULL,
            parent_id bigint(20) UNSIGNED DEFAULT NULL,
            booking_id bigint(20) UNSIGNED DEFAULT NULL,
            type varchar(50) DEFAULT 'manual',
            method varchar(20) DEFAULT 'sms',
            body text,
            step_number int DEFAULT 0,
            sent_at datetime DEFAULT CURRENT_TIMESTAMP,
            response_received tinyint(1) DEFAULT 0,
            response_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            KEY app_id (app_id),
            KEY parent_id (parent_id),
            KEY app_sent (app_id, sent_at)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS " . self::op_msgs() . " (
            id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            app_id bigint(20) UNSIGNED DEFAULT NULL,
            parent_id bigint(20) UNSIGNED DEFAULT NULL,
            phone varchar(20),
            direction enum('incoming','outgoing') DEFAULT 'outgoing',
            body text,
            openphone_msg_id varchar(100) DEFAULT NULL,
            is_read tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            KEY app_id (app_id),
            KEY phone (phone),
            KEY openphone_msg_id (openphone_msg_id),
            KEY phone_date (phone, created_at),
            KEY phone_dir (phone, direction),
            KEY app_dir (app_id, direction, created_at)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS " . self::drafts() . " (
            id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            app_id bigint(20) UNSIGNED DEFAULT NULL,
            phone varchar(20),
            draft_body text,
            intent varchar(100) DEFAULT NULL,
            status enum('pending','approved','rejected') DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            KEY status (status)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS " . self::retry_queue() . " (
            id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            phone varchar(20) NOT NULL,
            body text NOT NULL,
            source varchar(50) DEFAULT 'inbox',
            app_id bigint(20) UNSIGNED DEFAULT NULL,
            retry_count tinyint(3) UNSIGNED DEFAULT 0,
            last_error varchar(255) DEFAULT NULL,
            next_retry_at datetime DEFAULT CURRENT_TIMESTAMP,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            KEY next_retry (next_retry_at, retry_count),
            KEY phone (phone)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS " . self::rules() . " (
            id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name varchar(100),
            trigger_type varchar(50),
            trigger_value varchar(255),
            action_type varchar(50),
            action_value text,
            priority int DEFAULT 0,
            enabled tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS " . self::seg_hist() . " (
            id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            app_id bigint(20) UNSIGNED DEFAULT NULL,
            parent_id bigint(20) UNSIGNED DEFAULT NULL,
            old_value varchar(50),
            new_value varchar(50),
            reason varchar(255) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            KEY app_id (app_id)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS " . self::templates() . " (
            id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name varchar(100),
            category varchar(50) DEFAULT 'general',
            body text,
            use_count int DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            KEY category (category)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS " . self::activity() . " (
            id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            actor varchar(50) DEFAULT 'system',
            action varchar(100),
            entity_type varchar(50),
            entity_id bigint(20) UNSIGNED DEFAULT NULL,
            detail text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            KEY entity (entity_type, entity_id),
            KEY actor (actor),
            KEY created_at (created_at)
        ) $c;");

        // Expenses / Finance tracking
        $exp = $wpdb->prefix . 'ptp_cc_expenses';
        if ($wpdb->get_var("SHOW TABLES LIKE '$exp'") !== $exp) {
            $wpdb->query("CREATE TABLE $exp (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                category VARCHAR(50) NOT NULL DEFAULT 'other',
                subcategory VARCHAR(100) DEFAULT '',
                description VARCHAR(500) NOT NULL DEFAULT '',
                amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                expense_date DATE NOT NULL,
                vendor VARCHAR(255) DEFAULT '',
                payment_method VARCHAR(50) DEFAULT '',
                receipt_url VARCHAR(500) DEFAULT '',
                is_recurring TINYINT(1) DEFAULT 0,
                recur_interval VARCHAR(20) DEFAULT '',
                recur_end_date DATE DEFAULT NULL,
                tags VARCHAR(255) DEFAULT '',
                notes TEXT,
                stripe_charge_id VARCHAR(255) DEFAULT '',
                created_by BIGINT UNSIGNED DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_category (category),
                INDEX idx_date (expense_date),
                INDEX idx_vendor (vendor(50)),
                INDEX idx_recurring (is_recurring)
            ) $c;");
        }

        // Flush table cache so newly created tables are recognized
        self::flush_table_cache();

        // ── Additional CRM tables (v2) ──────────────────────────────────

        // Children per family
        dbDelta("CREATE TABLE IF NOT EXISTS " . self::t('ptp_cc_children') . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            family_id BIGINT UNSIGNED NOT NULL,
            first_name VARCHAR(100) NOT NULL DEFAULT '',
            age INT DEFAULT 0,
            club VARCHAR(200) DEFAULT '',
            position VARCHAR(100) DEFAULT '',
            skill_level VARCHAR(50) DEFAULT '',
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_family (family_id)
        ) $c;");

        // Notes per family
        dbDelta("CREATE TABLE IF NOT EXISTS " . self::t('ptp_cc_notes') . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            family_id BIGINT UNSIGNED NOT NULL,
            note_text TEXT NOT NULL,
            note_type VARCHAR(50) DEFAULT 'general',
            created_by VARCHAR(100) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_family (family_id),
            KEY idx_type (note_type)
        ) $c;");

        // Tags per family
        dbDelta("CREATE TABLE IF NOT EXISTS " . self::t('ptp_cc_tags') . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            family_id BIGINT UNSIGNED NOT NULL,
            tag_name VARCHAR(100) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_family (family_id),
            KEY idx_tag (tag_name)
        ) $c;");

        // Revenue tracking per family
        dbDelta("CREATE TABLE IF NOT EXISTS " . self::t('ptp_cc_revenue') . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            family_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            type VARCHAR(50) DEFAULT 'training',
            source VARCHAR(100) DEFAULT '',
            stripe_charge_id VARCHAR(255) DEFAULT '',
            status VARCHAR(30) DEFAULT 'completed',
            description VARCHAR(500) DEFAULT '',
            revenue_date DATE DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_family (family_id),
            KEY idx_type (type),
            KEY idx_status (status)
        ) $c;");

        // Ad spend tracking
        dbDelta("CREATE TABLE IF NOT EXISTS " . self::t('ptp_cc_ad_spend') . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            spend_date DATE NOT NULL,
            platform VARCHAR(50) DEFAULT 'meta',
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            campaign VARCHAR(255) DEFAULT '',
            clicks INT DEFAULT 0,
            conversions INT DEFAULT 0,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_date (spend_date),
            KEY idx_platform (platform)
        ) $c;");

        // Sequences (automated follow-up flows)
        dbDelta("CREATE TABLE IF NOT EXISTS " . self::t('ptp_cc_sequences') . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            trigger_event VARCHAR(100) DEFAULT '',
            steps LONGTEXT,
            status VARCHAR(20) DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_status (status)
        ) $c;");

        // Sequence enrollments
        dbDelta("CREATE TABLE IF NOT EXISTS " . self::t('ptp_cc_sequence_enrollments') . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sequence_id BIGINT UNSIGNED NOT NULL,
            family_id BIGINT UNSIGNED NOT NULL,
            current_step INT DEFAULT 0,
            status VARCHAR(20) DEFAULT 'active',
            next_action_at DATETIME DEFAULT NULL,
            enrolled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME DEFAULT NULL,
            KEY idx_sequence (sequence_id),
            KEY idx_family (family_id),
            KEY idx_status (status),
            KEY idx_next (next_action_at)
        ) $c;");

        // ── Migrations for existing installs ──
        // Add is_read column to openphone_messages if missing
        $opm = self::op_msgs();
        $col_exists = $wpdb->get_results( "SHOW COLUMNS FROM $opm LIKE 'is_read'" );
        if ( empty( $col_exists ) ) {
            $wpdb->query( "ALTER TABLE $opm ADD COLUMN is_read TINYINT(1) DEFAULT 0 AFTER openphone_msg_id" );
        }
        // Add index for fast poll queries (direction + is_read)
        $idx_exists = $wpdb->get_results( "SHOW INDEX FROM $opm WHERE Key_name = 'idx_dir_read'" );
        if ( empty( $idx_exists ) ) {
            $wpdb->query( "ALTER TABLE $opm ADD INDEX idx_dir_read (direction, is_read)" );
        }

        // Final cache flush after v2 tables
        self::flush_table_cache();
    }

    /**
     * Seed default data
     */
    public static function seed_data() {
        global $wpdb;

        // Seed rules
        $rt = self::rules();
        if (!(int)$wpdb->get_var("SELECT COUNT(*) FROM $rt")) {
            $wpdb->insert($rt, ['name'=>'After Hours','trigger_type'=>'time','trigger_value'=>'outside_hours','action_type'=>'auto_reply','action_value'=>'Thanks for texting PTP! We\'ll get back to you first thing in the morning.','priority'=>1,'enabled'=>1]);
            $wpdb->insert($rt, ['name'=>'Pricing Inquiry','trigger_type'=>'intent','trigger_value'=>'pricing','action_type'=>'auto_reply','action_value'=>'Great question! Our 1-on-1 training sessions start at $70/hr with current MLS and D1 athletes. Want me to match you with a coach?','priority'=>2,'enabled'=>1]);
            $wpdb->insert($rt, ['name'=>'STOP','trigger_type'=>'keyword','trigger_value'=>'STOP','action_type'=>'do_not_reply','action_value'=>'','priority'=>99,'enabled'=>1]);
        }

        // Seed templates
        $tt = self::templates();
        if (!(int)$wpdb->get_var("SELECT COUNT(*) FROM $tt")) {
            $seeds = [
                ['Intro','intro','Hey {name}! This is Luke from PTP Soccer. Thanks for signing {child} up -- I\'ll be reaching out soon to lock in a time for the free session. Any preferred day/time?'],
                ['Availability Check','scheduling','Hi {name}! Just checking in -- do you have any availability this week for {child}\'s session? We\'re flexible on days/times.'],
                ['Booking Confirm','booking','Awesome {name}! {child} is all set. See you at the session -- {child} is going to love it. Text me if anything changes!'],
                ['Post-Session','retention','Hey {name}! Hope {child} had a blast today. Our coaches loved working with them. Want to set up a regular schedule? We have weekly and monthly packages.'],
                ['Send Training Link','link','Hey {name}! Here\'s {child}\'s training link to book a session: {link} -- Pick any date/time that works for you!'],
                ['Referral Ask','referral','{name}, so glad {child} is loving PTP! If you know any other families who\'d be interested, we\'d love to offer them a free session too.'],
                ['Camp Promo','camp','Hey {name}! PTP summer camps are filling up fast. Pro-level coaches, small groups, and {child} will have a blast. Want me to save a spot?'],
                ['Re-engage','reengagement','Hey {name}! Haven\'t heard from you in a while -- the free session offer is still open whenever {child} is ready. No pressure!'],
                ['World Cup','promo','Hey {name}! With the World Cup coming to Philly this summer, it\'s the perfect time for {child} to level up. Our coaches are current MLS/D1 players. Want to book a session?'],
            ];
            foreach ($seeds as $s) {
                $wpdb->insert($tt, ['name'=>$s[0],'category'=>$s[1],'body'=>$s[2]]);
            }
        }
    }

    /**
     * Log an activity event.
     */
    public static function log($action, $entity_type = null, $entity_id = null, $detail = '', $actor = 'system') {
        global $wpdb;
        $wpdb->insert(self::activity(), [
            'actor'       => $actor,
            'action'      => $action,
            'entity_type' => $entity_type,
            'entity_id'   => $entity_id,
            'detail'      => $detail,
        ]);
    }

    /**
     * Send SMS via OpenPhone
     */
    public static function send_sms($phone, $message, $source = 'inbox') {
        $phone = self::normalize_phone($phone);

        // Primary: use Training Platform's unified SMS class
        if (class_exists('PTP_SMS_V71')) {
            error_log('[PTP-CC] send_sms: using PTP_SMS_V71 path');
            if (!PTP_SMS_V71::is_enabled()) PTP_SMS_V71::init();
            $result = PTP_SMS_V71::send($phone, $message);
            if (!is_wp_error($result) && $result !== false) {
                self::log_outgoing($phone, $message);
                return $result;
            }
            $err = is_wp_error($result) ? $result->get_error_message() : 'PTP_SMS_V71 returned false';
            error_log('[PTP-CC] send_sms: PTP_SMS_V71 FAILED: ' . $err);
            self::queue_retry($phone, $message, $source, $err);
            return is_wp_error($result) ? $result : new \WP_Error('sms_fail', $err);
        }
        if (class_exists('PTP_SMS') && !class_exists('PTP_SMS_V71')) {
            error_log('[PTP-CC] send_sms: using PTP_SMS path');
            if (method_exists('PTP_SMS', 'is_enabled') && !PTP_SMS::is_enabled()) {
                PTP_SMS::init();
            }
            $result = PTP_SMS::send($phone, $message);
            if (!is_wp_error($result) && $result !== false) {
                self::log_outgoing($phone, $message);
                return $result;
            }
            $err = is_wp_error($result) ? $result->get_error_message() : 'PTP_SMS returned false';
            error_log('[PTP-CC] send_sms: PTP_SMS FAILED: ' . $err);
            self::queue_retry($phone, $message, $source, $err);
            return is_wp_error($result) ? $result : new \WP_Error('sms_fail', $err);
        }

        // Fallback: CC's own OpenPhone sender
        error_log('[PTP-CC] send_sms: no TP SMS class found, using CC fallback');
        $key = get_option('ptp_openphone_api_key', '') ?: get_option('ptp_cc_openphone_api_key', '');

        if (!$key) {
            self::queue_retry($phone, $message, $source, 'OpenPhone API key not configured');
            return new \WP_Error('no_openphone', 'OpenPhone API key not configured.');
        }

        // Resolve phoneNumberId (POST /v1/messages requires PNxxx, not phone number)
        $pnid = self::resolve_openphone_id($key);
        if (!$pnid) {
            self::queue_retry($phone, $message, $source, 'Could not resolve phoneNumberId');
            return new \WP_Error('no_phone_id', 'Could not resolve OpenPhone phoneNumberId.');
        }

        $r = wp_remote_post('https://api.openphone.com/v1/messages', [
            'headers' => ['Authorization' => $key, 'Content-Type' => 'application/json'],
            'body' => wp_json_encode(['from' => $pnid, 'to' => [$phone], 'content' => $message]),
            'timeout' => 15,
        ]);

        if (is_wp_error($r)) {
            self::queue_retry($phone, $message, $source, $r->get_error_message());
            return $r;
        }

        $code = wp_remote_retrieve_response_code($r);
        $body = json_decode(wp_remote_retrieve_body($r), true);

        if ($code < 200 || $code >= 300) {
            $err = $body['message'] ?? 'HTTP ' . $code;
            self::queue_retry($phone, $message, $source, $err);
            return new \WP_Error('openphone_error', $err);
        }

        self::log_outgoing($phone, $message);
        return $body;
    }

    /**
     * Queue a failed SMS for retry. Max 3 retries with exponential backoff.
     */
    private static function queue_retry($phone, $message, $source, $error) {
        global $wpdb;
        $rq = self::retry_queue();

        // Don't queue if already queued with same phone+body
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $rq WHERE phone=%s AND body=%s AND retry_count < 3",
            $phone, $message
        ));
        if ($exists) return;

        // Resolve app_id
        $app_id = null;
        $suffix = substr(preg_replace('/\D/', '', $phone), -10);
        if ($suffix) {
            $app_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM " . self::apps() . " WHERE phone LIKE %s ORDER BY created_at DESC LIMIT 1",
                '%' . $suffix
            ));
        }

        $wpdb->insert($rq, [
            'phone'         => $phone,
            'body'          => $message,
            'source'        => $source,
            'app_id'        => $app_id ? (int)$app_id : null,
            'retry_count'   => 0,
            'last_error'    => substr($error, 0, 255),
            'next_retry_at' => gmdate('Y-m-d H:i:s', time() + 300), // 5 min
        ]);

        error_log("[PTP-CC] SMS queued for retry: $phone — $error");
    }

    /**
     * Process retry queue. Called by cron every 5 minutes.
     * Exponential backoff: 5min, 20min, 60min, then give up.
     */
    public static function process_retry_queue() {
        global $wpdb;
        $rq = self::retry_queue();
        $now = current_time('mysql');

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $rq WHERE retry_count < 3 AND next_retry_at <= %s ORDER BY next_retry_at ASC LIMIT 10",
            $now
        ));

        if (!$items) return;

        $lock_key = 'ptp_cc_retry_lock';
        if (get_transient($lock_key)) return;
        set_transient($lock_key, 1, 5 * MINUTE_IN_SECONDS);

        $sent = 0;
        $failed = 0;

        foreach ($items as $item) {
            // Try sending directly (bypass queue_retry to avoid infinite loop)
            $result = self::send_sms_direct($item->phone, $item->body);

            if (!is_wp_error($result) && $result !== false) {
                // Success! Remove from queue
                $wpdb->delete($rq, ['id' => $item->id]);
                self::log_outgoing($item->phone, $item->body, $item->app_id);
                error_log("[PTP-CC] Retry succeeded for {$item->phone} (attempt " . ($item->retry_count + 1) . ")");
                $sent++;
            } else {
                // Increment retry count + exponential backoff
                $new_count = $item->retry_count + 1;
                $backoff = [300, 1200, 3600]; // 5min, 20min, 60min
                $delay = $backoff[min($new_count, count($backoff) - 1)];
                $err = is_wp_error($result) ? $result->get_error_message() : 'Send returned false';

                if ($new_count >= 3) {
                    // Max retries reached — mark as failed permanently
                    $wpdb->update($rq, [
                        'retry_count' => $new_count,
                        'last_error'  => 'GAVE UP after 3 attempts: ' . substr($err, 0, 200),
                    ], ['id' => $item->id]);
                    error_log("[PTP-CC] SMS retry GAVE UP for {$item->phone}: $err");
                } else {
                    $wpdb->update($rq, [
                        'retry_count'   => $new_count,
                        'last_error'    => substr($err, 0, 255),
                        'next_retry_at' => gmdate('Y-m-d H:i:s', time() + $delay),
                    ], ['id' => $item->id]);
                    error_log("[PTP-CC] SMS retry #{$new_count} failed for {$item->phone}, next in {$delay}s: $err");
                }
                $failed++;
            }
        }

        delete_transient($lock_key);
        if ($sent || $failed) {
            error_log("[PTP-CC] Retry queue processed: {$sent} sent, {$failed} failed");
        }
    }

    /**
     * Direct send (no retry queueing) to avoid recursion.
     */
    private static function send_sms_direct($phone, $message) {
        $phone = self::normalize_phone($phone);

        if (class_exists('PTP_SMS_V71')) {
            if (!PTP_SMS_V71::is_enabled()) PTP_SMS_V71::init();
            return PTP_SMS_V71::send($phone, $message);
        }
        if (class_exists('PTP_SMS') && !class_exists('PTP_SMS_V71')) {
            if (method_exists('PTP_SMS', 'is_enabled') && !PTP_SMS::is_enabled()) {
                PTP_SMS::init();
            }
            return PTP_SMS::send($phone, $message);
        }

        $key = get_option('ptp_openphone_api_key', '') ?: get_option('ptp_cc_openphone_api_key', '');
        if (!$key) return new \WP_Error('no_openphone', 'No API key');

        $pnid = self::resolve_openphone_id($key);
        if (!$pnid) return new \WP_Error('no_phone_id', 'No phoneNumberId');

        $r = wp_remote_post('https://api.openphone.com/v1/messages', [
            'headers' => ['Authorization' => $key, 'Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['from' => $pnid, 'to' => [$phone], 'content' => $message]),
            'timeout' => 15,
        ]);

        if (is_wp_error($r)) return $r;
        $code = wp_remote_retrieve_response_code($r);
        if ($code < 200 || $code >= 300) {
            $body = json_decode(wp_remote_retrieve_body($r), true);
            return new \WP_Error('openphone_error', $body['message'] ?? 'HTTP ' . $code);
        }
        return json_decode(wp_remote_retrieve_body($r), true);
    }

    private static function log_outgoing($phone, $message, $app_id = null, $parent_id = null) {
        global $wpdb;
        if (!$app_id) {
            $suffix = substr(preg_replace('/\D/', '', $phone), -10);
            if ($suffix) {
                $app_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM " . self::apps() . " WHERE phone LIKE %s ORDER BY created_at DESC LIMIT 1",
                    '%' . $suffix
                ));
                if (!$parent_id) {
                    $parent_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM " . self::parents() . " WHERE phone LIKE %s LIMIT 1",
                        '%' . $suffix
                    ));
                }
            }
        }
        $wpdb->insert(self::op_msgs(), [
            'app_id' => $app_id ? (int)$app_id : null,
            'parent_id' => $parent_id ? (int)$parent_id : null,
            'phone' => $phone, 'direction' => 'outgoing', 'body' => $message,
        ]);
    }

    /**
     * Resolve OpenPhone phoneNumberId from configured phone number.
     * Caches result for 24 hours.
     */
    private static function resolve_openphone_id($api_key) {
        $cached = get_transient('ptp_cc_op_phone_id');
        if ($cached) return $cached;
        $tp_cached = get_transient('ptp_op_phone_id');
        if ($tp_cached) return $tp_cached;

        $configured = get_option('ptp_cc_openphone_phone_id', '');
        if ($configured && strpos($configured, 'PN') === 0) return $configured;

        $r = wp_remote_get('https://api.openphone.com/v1/phone-numbers', [
            'headers' => ['Authorization' => $api_key, 'Content-Type' => 'application/json'],
            'timeout' => 15,
        ]);
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) return null;

        $numbers = json_decode(wp_remote_retrieve_body($r), true)['data'] ?? [];
        $from = get_option('ptp_openphone_from', '') ?: get_option('ptp_cc_openphone_phone_id', '');

        foreach ($numbers as $num) {
            if (($num['number'] ?? '') === $from || ($num['formattedNumber'] ?? '') === $from) {
                set_transient('ptp_cc_op_phone_id', $num['id'], DAY_IN_SECONDS);
                return $num['id'];
            }
        }
        if (!empty($numbers[0]['id'])) {
            set_transient('ptp_cc_op_phone_id', $numbers[0]['id'], DAY_IN_SECONDS);
            return $numbers[0]['id'];
        }
        return null;
    }
}
