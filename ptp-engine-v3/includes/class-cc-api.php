<?php
if (!defined('ABSPATH')) exit;

class CC_API {

    private $ns = 'ptp-cc/v1';

    public function register_routes() {
        // Pipeline: free session applications
        register_rest_route($this->ns, '/applications', [
            'methods' => 'GET', 'callback' => [$this, 'get_applications'], 'permission_callback' => [$this, 'is_admin'],
        ]);
        register_rest_route($this->ns, '/applications/(?P<id>\d+)', [
            ['methods' => 'GET', 'callback' => [$this, 'get_application'], 'permission_callback' => [$this, 'is_admin']],
            ['methods' => 'PATCH', 'callback' => [$this, 'update_application'], 'permission_callback' => [$this, 'is_admin']],
        ]);
        register_rest_route($this->ns, '/applications/(?P<id>\d+)/follow-up', [
            'methods' => 'POST', 'callback' => [$this, 'send_follow_up'], 'permission_callback' => [$this, 'is_admin'],
        ]);

        // Parents (converted families)
        register_rest_route($this->ns, '/parents', [
            'methods' => 'GET', 'callback' => [$this, 'get_parents'], 'permission_callback' => [$this, 'is_admin'],
        ]);
        register_rest_route($this->ns, '/parents/(?P<id>\d+)', [
            'methods' => 'GET', 'callback' => [$this, 'get_parent_detail'], 'permission_callback' => [$this, 'is_admin'],
        ]);

        // Trainers (coaches)
        register_rest_route($this->ns, '/trainers', [
            'methods' => 'GET', 'callback' => [$this, 'get_trainers'], 'permission_callback' => [$this, 'is_admin'],
        ]);

        // Bookings (paid sessions)
        register_rest_route($this->ns, '/bookings', [
            'methods' => 'GET', 'callback' => [$this, 'get_bookings'], 'permission_callback' => [$this, 'is_admin'],
        ]);

        // Camp orders
        register_rest_route($this->ns, '/camps', [
            'methods' => 'GET', 'callback' => [$this, 'get_camp_orders'], 'permission_callback' => [$this, 'is_admin'],
        ]);
        register_rest_route($this->ns, '/camps/(?P<id>\d+)', [
            'methods' => 'GET', 'callback' => [$this, 'get_camp_order_detail'], 'permission_callback' => [$this, 'is_admin'],
        ]);

        // Dashboard stats
        register_rest_route($this->ns, '/stats', [
            'methods' => 'GET', 'callback' => [$this, 'get_stats'], 'permission_callback' => [$this, 'is_admin'],
        ]);
        register_rest_route($this->ns, '/revenue', [
            'methods' => 'GET', 'callback' => [$this, 'get_revenue'], 'permission_callback' => [$this, 'is_admin'],
        ]);

        // Sequences
        register_rest_route($this->ns, '/sequences/active', [
            'methods' => 'GET', 'callback' => [$this, 'get_active_sequences'], 'permission_callback' => [$this, 'is_admin'],
        ]);

        // SMS
        register_rest_route($this->ns, '/send-sms', [
            'methods' => 'POST', 'callback' => [$this, 'send_sms'], 'permission_callback' => [$this, 'is_admin'],
        ]);

        // Messages (OpenPhone log)
        register_rest_route($this->ns, '/messages/(?P<phone>[^/]+)', [
            'methods' => 'GET', 'callback' => [$this, 'get_messages'], 'permission_callback' => [$this, 'is_admin'],
        ]);

        // AI Drafts
        register_rest_route($this->ns, '/drafts', [
            'methods' => 'GET', 'callback' => [$this, 'get_drafts'], 'permission_callback' => [$this, 'is_admin'],
        ]);
        register_rest_route($this->ns, '/drafts/(?P<id>\d+)/(?P<action>approve|reject)', [
            'methods' => 'POST', 'callback' => [$this, 'handle_draft'], 'permission_callback' => [$this, 'is_admin'],
        ]);

        // Rules
        register_rest_route($this->ns, '/rules', [
            ['methods' => 'GET', 'callback' => [$this, 'get_rules'], 'permission_callback' => [$this, 'is_admin']],
            ['methods' => 'POST', 'callback' => [$this, 'create_rule'], 'permission_callback' => [$this, 'is_admin']],
        ]);
        register_rest_route($this->ns, '/rules/(?P<id>\d+)', [
            ['methods' => 'PATCH', 'callback' => [$this, 'update_rule'], 'permission_callback' => [$this, 'is_admin']],
            ['methods' => 'DELETE', 'callback' => [$this, 'delete_rule'], 'permission_callback' => [$this, 'is_admin']],
        ]);

        // Follow-up history
        register_rest_route($this->ns, '/follow-ups/(?P<app_id>\d+)', [
            'methods' => 'GET', 'callback' => [$this, 'get_follow_ups'], 'permission_callback' => [$this, 'is_admin'],
        ]);
    }

    public function is_admin() { return current_user_can('manage_options'); }

    // ═══════════════════════════════════════
    // FREE SESSION APPLICATIONS (the pipeline)
    // ═══════════════════════════════════════

    public function get_applications($req) {
        global $wpdb;
        $t = CC_DB::apps();
        $fu = CC_DB::follow_ups();

        $status = $req->get_param('status');
        $search = $req->get_param('search');
        $limit = min((int)($req->get_param('limit') ?: 500), 1000);

        $where = '1=1';
        $params = [];
        if ($status && $status !== 'all') { $where .= ' AND a.status=%s'; $params[] = $status; }
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (a.parent_name LIKE %s OR a.email LIKE %s OR a.phone LIKE %s OR a.child_name LIKE %s)';
            array_push($params, $like, $like, $like, $like);
        }

        $sql = "SELECT a.*,
            (SELECT COUNT(*) FROM $fu f WHERE f.app_id=a.id) as follow_up_count,
            (SELECT MAX(sent_at) FROM $fu f WHERE f.app_id=a.id) as last_follow_up,
            DATEDIFF(NOW(), a.created_at) as days_since_apply,
            DATEDIFF(NOW(), COALESCE(a.accepted_at, a.created_at)) as days_since_action
            FROM $t a WHERE $where ORDER BY a.created_at DESC LIMIT %d";
        $params[] = $limit;

        $apps = $wpdb->get_results($wpdb->prepare($sql, ...$params));

        // Stage counts
        $counts = $wpdb->get_results("SELECT status, COUNT(*) as c FROM $t GROUP BY status");
        $stage_counts = [];
        foreach ($counts as $c) $stage_counts[$c->status] = (int)$c->c;

        return ['applications' => $apps, 'stage_counts' => $stage_counts, 'total' => array_sum($stage_counts)];
    }

    public function get_application($req) {
        global $wpdb;
        $app = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . CC_DB::apps() . " WHERE id=%d", $req['id']));
        if (!$app) return new WP_Error('404', 'Not found', ['status' => 404]);

        // Get follow-ups
        $fus = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . CC_DB::follow_ups() . " WHERE app_id=%d ORDER BY created_at DESC", $app->id
        ));

        // Get matched trainers info
        $matched = [];
        if ($app->trainer_slug) {
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT id,display_name,slug,photo_url,hourly_rate,location,phone,email FROM " . CC_DB::trainers() . " WHERE slug=%s",
                $app->trainer_slug
            ));
            if ($trainer) $matched[] = $trainer;
        }

        return ['application' => $app, 'follow_ups' => $fus, 'matched_trainers' => $matched];
    }

    public function update_application($req) {
        global $wpdb;
        $t = CC_DB::apps();
        $id = (int)$req['id'];
        $body = $req->get_json_params();

        $allowed = ['status','call_status','call_notes','call_scheduled_at','lead_temperature','trainer_slug','trainer_name','admin_notes'];
        $data = [];
        foreach ($allowed as $f) {
            if (isset($body[$f])) $data[$f] = sanitize_text_field($body[$f]);
        }
        if (isset($body['call_notes'])) $data['call_notes'] = sanitize_textarea_field($body['call_notes']);
        if (isset($body['admin_notes'])) $data['admin_notes'] = sanitize_textarea_field($body['admin_notes']);

        // Track status change
        if (isset($body['status'])) {
            $old = $wpdb->get_var($wpdb->prepare("SELECT status FROM $t WHERE id=%d", $id));
            if ($old !== $body['status']) {
                if ($body['status'] === 'accepted') $data['accepted_at'] = current_time('mysql');
                $wpdb->insert(CC_DB::seg_hist(), [
                    'app_id' => $id, 'old_value' => $old,
                    'new_value' => $body['status'], 'reason' => 'CRM update',
                ]);
            }
        }

        if (!empty($data)) $wpdb->update($t, $data, ['id' => $id]);
        $app = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id));
        return ['success' => true, 'application' => $app];
    }

    public function send_follow_up($req) {
        global $wpdb;
        $id = (int)$req['id'];
        $body = $req->get_json_params();
        $msg = $body['body'] ?? '';

        $app = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . CC_DB::apps() . " WHERE id=%d", $id));
        if (!$app) return new WP_Error('404', 'Not found', ['status' => 404]);

        if ($msg && $app->phone) {
            $result = CC_DB::send_sms($app->phone, $msg);
            if (is_wp_error($result)) {
                return new WP_Error('sms_fail', $result->get_error_message(), ['status' => 500]);
            }
        }

        $count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . CC_DB::follow_ups() . " WHERE app_id=%d", $id));
        $wpdb->insert(CC_DB::follow_ups(), [
            'app_id' => $id, 'type' => 'manual', 'method' => 'sms',
            'body' => $msg, 'sent_at' => current_time('mysql'),
            'step_number' => $count + 1,
        ]);

        // Move to follow_up status if still pending/accepted
        if (in_array($app->status, ['pending', 'accepted'])) {
            $wpdb->update(CC_DB::apps(), ['status' => 'contacted'], ['id' => $id]);
        }

        return ['success' => true, 'follow_up_count' => $count + 1];
    }

    // ═══════════════════════════════════════
    // PARENTS (converted families)
    // ═══════════════════════════════════════

    public function get_parents($req) {
        global $wpdb;
        $pt = CC_DB::parents();
        $bt = CC_DB::bookings();
        $search = $req->get_param('search');
        $limit = min((int)($req->get_param('limit') ?: 200), 500);

        $where = '1=1';
        $params = [];
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (p.display_name LIKE %s OR p.email LIKE %s OR p.phone LIKE %s)';
            array_push($params, $like, $like, $like);
        }

        $sql = "SELECT p.*,
            (SELECT COUNT(*) FROM $bt b WHERE b.parent_id=p.id AND b.status='completed') as completed_sessions,
            (SELECT COUNT(*) FROM $bt b WHERE b.parent_id=p.id AND b.status IN('pending','confirmed')) as upcoming_sessions,
            (SELECT COALESCE(SUM(b.total_amount),0) FROM $bt b WHERE b.parent_id=p.id AND b.payment_status='paid') as total_paid
            FROM $pt p WHERE $where ORDER BY p.created_at DESC LIMIT %d";
        $params[] = $limit;

        $parents = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params)) : $wpdb->get_results($sql);
        return ['parents' => $parents];
    }

    public function get_parent_detail($req) {
        global $wpdb;
        $id = (int)$req['id'];
        $parent = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . CC_DB::parents() . " WHERE id=%d", $id));
        if (!$parent) return new WP_Error('404', 'Not found', ['status' => 404]);

        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . CC_DB::players() . " WHERE parent_id=%d", $id
        ));

        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, t.display_name as trainer_name FROM " . CC_DB::bookings() . " b
            LEFT JOIN " . CC_DB::trainers() . " t ON b.trainer_id=t.id
            WHERE b.parent_id=%d ORDER BY b.session_date DESC LIMIT 20", $id
        ));

        $reviews = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . CC_DB::reviews() . " WHERE parent_id=%d", $id
        ));

        return ['parent' => $parent, 'players' => $players, 'bookings' => $bookings, 'reviews' => $reviews];
    }

    // ═══════════════════════════════════════
    // TRAINERS
    // ═══════════════════════════════════════

    public function get_trainers() {
        global $wpdb;
        $t = CC_DB::trainers();
        $b = CC_DB::bookings();
        $trainers = $wpdb->get_results(
            "SELECT t.id,t.display_name,t.slug,t.email,t.phone,t.photo_url,t.hourly_rate,t.location,
            t.status,t.average_rating,t.review_count,t.total_sessions,t.total_earnings,
            (SELECT COUNT(*) FROM $b b WHERE b.trainer_id=t.id AND b.status IN('pending','confirmed') AND b.session_date >= CURDATE()) as upcoming
            FROM $t t WHERE t.status='approved' ORDER BY t.display_name"
        );
        return ['trainers' => $trainers];
    }

    // ═══════════════════════════════════════
    // BOOKINGS
    // ═══════════════════════════════════════

    public function get_bookings($req) {
        global $wpdb;
        $bt = CC_DB::bookings();
        $tt = CC_DB::trainers();
        $pt = CC_DB::parents();
        $status = $req->get_param('status');
        $trainer = $req->get_param('trainer_id');
        $limit = min((int)($req->get_param('limit') ?: 100), 500);

        $where = '1=1';
        $params = [];
        if ($status) { $where .= ' AND b.status=%s'; $params[] = $status; }
        if ($trainer) { $where .= ' AND b.trainer_id=%d'; $params[] = (int)$trainer; }

        $sql = "SELECT b.*, t.display_name as trainer_name, t.photo_url as trainer_photo,
            p.display_name as parent_name, p.phone as parent_phone, p.email as parent_email
            FROM $bt b LEFT JOIN $tt t ON b.trainer_id=t.id LEFT JOIN $pt p ON b.parent_id=p.id
            WHERE $where ORDER BY b.session_date DESC LIMIT %d";
        $params[] = $limit;

        $bookings = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        return ['bookings' => $bookings];
    }

    // ═══════════════════════════════════════
    // CAMP ORDERS
    // ═══════════════════════════════════════

    public function get_camp_orders($req) {
        global $wpdb;
        $ct = CC_DB::camp_orders();
        $ci = CC_DB::camp_items();

        // Safety: camp tables may not exist
        if ($wpdb->get_var("SHOW TABLES LIKE '$ct'") !== $ct) {
            return ['orders' => []];
        }
        $status = $req->get_param('status');
        $limit = min((int)($req->get_param('limit') ?: 100), 500);

        $where = '1=1';
        $params = [];
        if ($status) { $where .= ' AND o.status=%s'; $params[] = $status; }

        $sql = "SELECT o.*,
            (SELECT COUNT(*) FROM $ci i WHERE i.order_id=o.id) as camper_count,
            (SELECT GROUP_CONCAT(DISTINCT CONCAT(i.camper_first_name,' ',i.camper_last_name) SEPARATOR ', ') FROM $ci i WHERE i.order_id=o.id) as camper_names,
            (SELECT GROUP_CONCAT(DISTINCT i.camp_name SEPARATOR ', ') FROM $ci i WHERE i.order_id=o.id) as camp_names
            FROM $ct o WHERE $where ORDER BY o.created_at DESC LIMIT %d";
        $params[] = $limit;

        $orders = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        return ['orders' => $orders];
    }

    public function get_camp_order_detail($req) {
        global $wpdb;
        $co = CC_DB::camp_orders();
        if ($wpdb->get_var("SHOW TABLES LIKE '$co'") !== $co) {
            return new WP_Error('404', 'Camp tables not found', ['status' => 404]);
        }
        $id = (int)$req['id'];
        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . CC_DB::camp_orders() . " WHERE id=%d", $id));
        if (!$order) return new WP_Error('404', 'Not found', ['status' => 404]);
        $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . CC_DB::camp_items() . " WHERE order_id=%d", $id));
        return ['order' => $order, 'items' => $items];
    }

    // ═══════════════════════════════════════
    // STATS & REVENUE (Training + Camps)
    // ═══════════════════════════════════════

    public function get_stats() {
        global $wpdb;
        $at = CC_DB::apps();
        $bt = CC_DB::bookings();
        $pt = CC_DB::parents();
        $dt = CC_DB::drafts();
        $tt = CC_DB::trainers();
        $co = CC_DB::camp_orders();
        $ci = CC_DB::camp_items();

        // Camp stats (prefer ptp_camp_bookings, fallback to unified)
        $camp_orders = 0; $camp_revenue = 0; $camp_campers = 0;
        $cb = CC_DB::camp_bookings();
        if ($wpdb->get_var("SHOW TABLES LIKE '$cb'") === $cb) {
            $camp_orders = (int)$wpdb->get_var("SELECT COUNT(*) FROM $cb WHERE status='confirmed'");
            $camp_revenue = (float)$wpdb->get_var("SELECT COALESCE(SUM(amount_paid),0) FROM $cb WHERE status='confirmed'");
            $camp_campers = $camp_orders;
        } elseif ($wpdb->get_var("SHOW TABLES LIKE '$co'") === $co) {
            $camp_orders = (int)$wpdb->get_var("SELECT COUNT(*) FROM $co WHERE status='completed'");
            $camp_revenue = (float)$wpdb->get_var("SELECT COALESCE(SUM(total_amount),0) FROM $co WHERE payment_status='completed'");
        }
        if (!$camp_campers && $wpdb->get_var("SHOW TABLES LIKE '$ci'") === $ci) {
            $camp_campers = (int)$wpdb->get_var("SELECT COUNT(*) FROM $ci");
        }

        return [
            'apps_total' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $at"),
            'apps_pending' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $at WHERE status='pending'"),
            'apps_accepted' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $at WHERE status='accepted'"),
            'apps_converted' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $at WHERE status='converted'"),
            'apps_today' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $at WHERE DATE(created_at) = CURDATE()"),
            'apps_week' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $at WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),
            'parents_total' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $pt"),
            'bookings_upcoming' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $bt WHERE status IN('pending','confirmed') AND session_date >= CURDATE()"),
            'bookings_completed' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $bt WHERE status='completed'"),
            'trainers_active' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $tt WHERE status='approved'"),
            'pending_drafts' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $dt WHERE status='pending'"),
            // Camp stats
            'camp_orders' => $camp_orders,
            'camp_revenue' => $camp_revenue,
            'camp_campers' => $camp_campers,
            'needs_follow_up' => $wpdb->get_results(
                "SELECT a.*, DATEDIFF(NOW(), COALESCE(a.accepted_at, a.created_at)) as days_since,
                (SELECT COUNT(*) FROM " . CC_DB::follow_ups() . " f WHERE f.app_id=a.id) as fu_count
                FROM $at a WHERE a.status IN('pending','accepted','contacted')
                AND DATEDIFF(NOW(), COALESCE(a.accepted_at, a.created_at)) >= 1
                ORDER BY a.created_at ASC LIMIT 20"
            ),
            'by_trainer' => $wpdb->get_results(
                "SELECT trainer_name, COUNT(*) as total,
                SUM(CASE WHEN status='converted' THEN 1 ELSE 0 END) as converted
                FROM $at WHERE trainer_name IS NOT NULL AND trainer_name != '' GROUP BY trainer_name"
            ),
            'lead_temps' => $wpdb->get_results(
                "SELECT lead_temperature as temp, COUNT(*) as c FROM $at WHERE lead_temperature != '' GROUP BY lead_temperature"
            ),
        ];
    }

    public function get_revenue() {
        global $wpdb;
        $bt = CC_DB::bookings();
        $co = CC_DB::camp_orders();

        // Training revenue
        $training = $wpdb->get_row("SELECT
            COALESCE(SUM(total_amount),0) as total_revenue,
            COALESCE(SUM(platform_fee),0) as total_platform_fee,
            COALESCE(SUM(trainer_payout),0) as total_trainer_payouts,
            COUNT(*) as total_bookings,
            COUNT(DISTINCT parent_id) as paying_families
            FROM $bt WHERE payment_status='paid'");

        $training_monthly = $wpdb->get_results("SELECT
            DATE_FORMAT(session_date, '%Y-%m') as month,
            COALESCE(SUM(total_amount),0) as revenue,
            COUNT(*) as bookings
            FROM $bt WHERE payment_status='paid'
            GROUP BY month ORDER BY month DESC LIMIT 12");

        // Camp revenue (prefer ptp_camp_bookings, fallback to unified)
        $camp = (object)['total_revenue'=>0,'total_orders'=>0,'total_campers'=>0];
        $camp_monthly = [];
        $cb = CC_DB::camp_bookings();
        if ($wpdb->get_var("SHOW TABLES LIKE '$cb'") === $cb) {
            $camp = $wpdb->get_row("SELECT
                COALESCE(SUM(amount_paid),0) as total_revenue,
                COUNT(*) as total_orders, COUNT(DISTINCT customer_email) as total_campers
                FROM $cb WHERE status='confirmed'") ?: $camp;
            $camp_monthly = $wpdb->get_results("SELECT
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COALESCE(SUM(amount_paid),0) as revenue, COUNT(*) as orders
                FROM $cb WHERE status='confirmed' GROUP BY month ORDER BY month DESC LIMIT 12");
        } elseif ($wpdb->get_var("SHOW TABLES LIKE '$co'") === $co) {
            $camp = $wpdb->get_row("SELECT
                COALESCE(SUM(total_amount),0) as total_revenue,
                COUNT(*) as total_orders,
                (SELECT COUNT(*) FROM " . CC_DB::camp_items() . ") as total_campers
                FROM $co WHERE payment_status='completed'") ?: $camp;
            $camp_monthly = $wpdb->get_results("SELECT
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COALESCE(SUM(total_amount),0) as revenue, COUNT(*) as orders
                FROM $co WHERE payment_status='completed' GROUP BY month ORDER BY month DESC LIMIT 12");
        }

        return [
            'training' => $training,
            'training_monthly' => $training_monthly,
            'camp' => $camp,
            'camp_monthly' => $camp_monthly,
            'combined_revenue' => (float)($training->total_revenue ?? 0) + (float)($camp->total_revenue ?? 0),
        ];
    }

    // ═══════════════════════════════════════
    // SEQUENCES
    // ═══════════════════════════════════════

    public function get_active_sequences() {
        global $wpdb;
        $at = CC_DB::apps();
        $fu = CC_DB::follow_ups();
        $mt = CC_DB::op_msgs();

        $apps = $wpdb->get_results(
            "SELECT a.id, a.parent_name, a.child_name, a.phone, a.email, a.status,
            a.trainer_name, a.lead_temperature, a.created_at, a.accepted_at,
            (SELECT COUNT(*) FROM $fu f WHERE f.app_id=a.id) as fu_count,
            (SELECT MAX(sent_at) FROM $fu f WHERE f.app_id=a.id) as last_fu,
            TIMESTAMPDIFF(HOUR, COALESCE(a.accepted_at, a.created_at), NOW()) as hours_since
            FROM $at a WHERE a.status IN('pending','accepted','contacted')
            ORDER BY a.created_at DESC"
        );

        $sequences = [];
        foreach ($apps as $a) {
            $has_response = false;
            if ($a->last_fu) {
                $resp = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $mt WHERE app_id=%d AND direction='incoming' AND created_at > %s",
                    $a->id, $a->last_fu
                ));
                $has_response = $resp > 0;
            }
            $sequences[] = [
                'app_id' => $a->id, 'name' => $a->parent_name, 'child' => $a->child_name,
                'phone' => $a->phone, 'trainer' => $a->trainer_name,
                'hours_since' => (int)$a->hours_since, 'steps_done' => (int)$a->fu_count,
                'has_response' => $has_response, 'lead_temp' => $a->lead_temperature,
                'status' => $has_response ? 'responded' : ((int)$a->fu_count >= 4 ? 'exhausted' : 'active'),
            ];
        }
        return ['sequences' => $sequences];
    }

    // ═══════════════════════════════════════
    // SMS & MESSAGES
    // ═══════════════════════════════════════

    public function send_sms($req) {
        $body = $req->get_json_params();
        $to = $body['to'] ?? '';
        $msg = $body['body'] ?? '';

        if (!$to || !$msg) return new WP_Error('missing', 'to and body required', ['status' => 400]);

        $result = CC_DB::send_sms($to, $msg);
        if (is_wp_error($result)) {
            return new WP_Error('sms_fail', $result->get_error_message(), ['status' => 500]);
        }
        // CC_DB::send_sms already logs the outgoing message via log_outgoing()
        return ['success' => true];
    }

    public function get_messages($req) {
        global $wpdb;
        $phone = CC_DB::normalize_phone(urldecode($req['phone']));
        $msgs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . CC_DB::op_msgs() . " WHERE phone=%s ORDER BY created_at DESC LIMIT 50", $phone
        ));
        return ['messages' => $msgs];
    }

    // ═══════════════════════════════════════
    // DRAFTS & RULES
    // ═══════════════════════════════════════

    public function get_drafts() {
        global $wpdb;
        return ['drafts' => $wpdb->get_results("SELECT * FROM " . CC_DB::drafts() . " WHERE status='pending' ORDER BY created_at DESC")];
    }

    public function handle_draft($req) {
        global $wpdb;
        $dt = CC_DB::drafts();
        $id = (int)$req['id'];
        $action = $req['action'];
        $draft = $wpdb->get_row($wpdb->prepare("SELECT * FROM $dt WHERE id=%d", $id));
        if (!$draft) return new WP_Error('404', 'Not found', ['status' => 404]);

        if ($action === 'approve') {
            // Send approved draft via OpenPhone
            CC_DB::send_sms($draft->phone, $draft->draft_body);
            $wpdb->update($dt, ['status' => 'approved'], ['id' => $id]);
        } else {
            $wpdb->update($dt, ['status' => 'rejected'], ['id' => $id]);
        }
        return ['success' => true];
    }

    public function get_rules() {
        global $wpdb;
        return ['rules' => $wpdb->get_results("SELECT * FROM " . CC_DB::rules() . " ORDER BY priority DESC")];
    }

    public function update_rule($req) {
        global $wpdb;
        $body = $req->get_json_params();
        $data = [];
        if (isset($body['enabled'])) $data['enabled'] = (int)$body['enabled'];
        if (!empty($data)) $wpdb->update(CC_DB::rules(), $data, ['id' => (int)$req['id']]);
        return ['success' => true];
    }

    public function get_follow_ups($req) {
        global $wpdb;
        $fus = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . CC_DB::follow_ups() . " WHERE app_id=%d ORDER BY created_at DESC", (int)$req['app_id']
        ));
        return ['follow_ups' => $fus];
    }

    // Additional routes registered separately
    public function register_extended_routes() {
        // Activity timeline
        register_rest_route($this->ns, '/timeline/(?P<app_id>\d+)', [
            'methods' => 'GET', 'callback' => [$this, 'get_timeline'], 'permission_callback' => [$this, 'is_admin'],
        ]);

        // Bulk actions
        register_rest_route($this->ns, '/bulk/status', [
            'methods' => 'POST', 'callback' => [$this, 'bulk_status'], 'permission_callback' => [$this, 'is_admin'],
        ]);
        register_rest_route($this->ns, '/bulk/sms', [
            'methods' => 'POST', 'callback' => [$this, 'bulk_sms'], 'permission_callback' => [$this, 'is_admin'],
        ]);

        // CSV export
        register_rest_route($this->ns, '/export/applications', [
            'methods' => 'GET', 'callback' => [$this, 'export_applications'], 'permission_callback' => [$this, 'is_admin'],
        ]);
        register_rest_route($this->ns, '/export/families', [
            'methods' => 'GET', 'callback' => [$this, 'export_families'], 'permission_callback' => [$this, 'is_admin'],
        ]);
        register_rest_route($this->ns, '/export/camps', [
            'methods' => 'GET', 'callback' => [$this, 'export_camps'], 'permission_callback' => [$this, 'is_admin'],
        ]);

        // Unread / new message count
        register_rest_route($this->ns, '/unread', [
            'methods' => 'GET', 'callback' => [$this, 'get_unread'], 'permission_callback' => [$this, 'is_admin'],
        ]);

        // Family cross-reference (links parent to camp orders)
        register_rest_route($this->ns, '/parents/(?P<id>\d+)/camps', [
            'methods' => 'GET', 'callback' => [$this, 'get_parent_camps'], 'permission_callback' => [$this, 'is_admin'],
        ]);

        // Assign trainer to application
        register_rest_route($this->ns, '/applications/(?P<id>\d+)/assign', [
            'methods' => 'POST', 'callback' => [$this, 'assign_trainer'], 'permission_callback' => [$this, 'is_admin'],
        ]);
    }

    // ═══════════════════════════════════════
    // ACTIVITY TIMELINE
    // ═══════════════════════════════════════

    public function get_timeline($req) {
        global $wpdb;
        $app_id = (int)$req['app_id'];
        $events = [];

        // Follow-ups (manual + auto)
        $fus = $wpdb->get_results($wpdb->prepare(
            "SELECT id, type, method, body, step_number, response_received, response_at,
            sent_at as created_at FROM " . CC_DB::follow_ups() . " WHERE app_id=%d", $app_id
        ));
        foreach ($fus as $f) {
            $events[] = [
                'type' => $f->type === 'auto_sequence' ? 'auto_sms' : ($f->method === 'call' ? 'call' : 'manual_sms'),
                'body' => $f->body,
                'step' => $f->step_number,
                'responded' => (bool)$f->response_received,
                'response_at' => $f->response_at,
                'created_at' => $f->created_at,
            ];
        }

        // Messages (OpenPhone log for this app)
        $msgs = $wpdb->get_results($wpdb->prepare(
            "SELECT direction, body, created_at FROM " . CC_DB::op_msgs() . " WHERE app_id=%d", $app_id
        ));
        foreach ($msgs as $m) {
            $events[] = [
                'type' => $m->direction === 'incoming' ? 'inbound_msg' : 'outbound_msg',
                'body' => $m->body,
                'created_at' => $m->created_at,
            ];
        }

        // Status changes
        $changes = $wpdb->get_results($wpdb->prepare(
            "SELECT old_value, new_value, reason, created_at FROM " . CC_DB::seg_hist() . " WHERE app_id=%d", $app_id
        ));
        foreach ($changes as $c) {
            $events[] = [
                'type' => 'status_change',
                'body' => ($c->old_value ?? '(new)') . ' → ' . $c->new_value . ($c->reason ? ' (' . $c->reason . ')' : ''),
                'created_at' => $c->created_at,
            ];
        }

        // Drafts
        $drafts = $wpdb->get_results($wpdb->prepare(
            "SELECT status, draft_body, intent, created_at FROM " . CC_DB::drafts() . " WHERE app_id=%d", $app_id
        ));
        foreach ($drafts as $d) {
            $events[] = [
                'type' => 'draft_' . $d->status,
                'body' => $d->draft_body,
                'intent' => $d->intent,
                'created_at' => $d->created_at,
            ];
        }

        // Sort by time descending
        usort($events, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        return ['events' => $events, 'count' => count($events)];
    }

    // ═══════════════════════════════════════
    // RULE CRUD
    // ═══════════════════════════════════════

    public function create_rule($req) {
        global $wpdb;
        $b = $req->get_json_params();
        $data = [
            'name'          => sanitize_text_field($b['name'] ?? 'New Rule'),
            'trigger_type'  => sanitize_text_field($b['trigger_type'] ?? 'keyword'),
            'trigger_value' => sanitize_text_field($b['trigger_value'] ?? ''),
            'action_type'   => sanitize_text_field($b['action_type'] ?? 'auto_reply'),
            'action_value'  => sanitize_textarea_field($b['action_value'] ?? ''),
            'priority'      => (int)($b['priority'] ?? 0),
            'enabled'       => (int)($b['enabled'] ?? 1),
        ];
        $wpdb->insert(CC_DB::rules(), $data);
        $rule = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . CC_DB::rules() . " WHERE id=%d", $wpdb->insert_id));
        return ['success' => true, 'rule' => $rule];
    }

    public function delete_rule($req) {
        global $wpdb;
        $wpdb->delete(CC_DB::rules(), ['id' => (int)$req['id']]);
        return ['success' => true];
    }

    // ═══════════════════════════════════════
    // BULK ACTIONS
    // ═══════════════════════════════════════

    public function bulk_status($req) {
        global $wpdb;
        $b = $req->get_json_params();
        $ids = $b['ids'] ?? [];
        $status = sanitize_text_field($b['status'] ?? '');
        if (!$ids || !$status) return new WP_Error('missing', 'ids and status required', ['status' => 400]);

        $valid_statuses = ['pending','contacted','accepted','booked','converted','lost'];
        if (!in_array($status, $valid_statuses)) return new WP_Error('invalid', 'Invalid status', ['status' => 400]);

        $updated = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            $old = $wpdb->get_var($wpdb->prepare("SELECT status FROM " . CC_DB::apps() . " WHERE id=%d", $id));
            if ($old && $old !== $status) {
                $data = ['status' => $status];
                if ($status === 'accepted') $data['accepted_at'] = current_time('mysql');
                $wpdb->update(CC_DB::apps(), $data, ['id' => $id]);
                $wpdb->insert(CC_DB::seg_hist(), [
                    'app_id' => $id, 'old_value' => $old,
                    'new_value' => $status, 'reason' => 'Bulk update',
                ]);
                $updated++;
            }
        }
        return ['success' => true, 'updated' => $updated];
    }

    public function bulk_sms($req) {
        global $wpdb;
        $b = $req->get_json_params();
        $ids = $b['ids'] ?? [];
        $message = sanitize_textarea_field($b['message'] ?? '');
        if (!$ids || !$message) return new WP_Error('missing', 'ids and message required', ['status' => 400]);

        $sent = 0; $failed = 0;
        foreach ($ids as $id) {
            $app = $wpdb->get_row($wpdb->prepare(
                "SELECT id, phone, parent_name, child_name FROM " . CC_DB::apps() . " WHERE id=%d", (int)$id
            ));
            if (!$app || !$app->phone) { $failed++; continue; }

            // Personalize
            $first = explode(' ', $app->parent_name)[0] ?? '';
            $msg = str_replace(['{name}','{child}'], [$first, $app->child_name ?: 'your player'], $message);

            $result = CC_DB::send_sms($app->phone, $msg);
            if (is_wp_error($result)) { $failed++; continue; }

            // Log follow-up (CC_DB::send_sms already logs to op_msgs)
            $wpdb->insert(CC_DB::follow_ups(), [
                'app_id' => $app->id, 'type' => 'bulk_sms', 'method' => 'sms',
                'body' => $msg, 'sent_at' => current_time('mysql'),
            ]);
            $sent++;
        }
        return ['success' => true, 'sent' => $sent, 'failed' => $failed];
    }

    // ═══════════════════════════════════════
    // CSV EXPORT
    // ═══════════════════════════════════════

    public function export_applications() {
        global $wpdb;
        $apps = $wpdb->get_results(
            "SELECT parent_name, email, phone, child_name, child_age, status,
            lead_temperature, trainer_name, club, position, experience_level,
            biggest_challenge, goal, state, utm_source, utm_campaign, admin_notes, created_at
            FROM " . CC_DB::apps() . " ORDER BY created_at DESC", ARRAY_A
        );
        return $this->csv_response($apps, 'ptp-applications');
    }

    public function export_families() {
        global $wpdb;
        $parents = $wpdb->get_results(
            "SELECT p.display_name, p.email, p.phone, p.city, p.state,
            (SELECT COUNT(*) FROM " . CC_DB::bookings() . " b WHERE b.parent_id=p.id) as total_sessions,
            (SELECT COALESCE(SUM(b.total_amount),0) FROM " . CC_DB::bookings() . " b WHERE b.parent_id=p.id AND b.payment_status='paid') as total_spent,
            p.created_at
            FROM " . CC_DB::parents() . " p ORDER BY p.created_at DESC", ARRAY_A
        );
        return $this->csv_response($parents, 'ptp-families');
    }

    public function export_camps() {
        global $wpdb;
        $co = CC_DB::camp_orders();
        if ($wpdb->get_var("SHOW TABLES LIKE '$co'") !== $co) {
            return $this->csv_response([], 'ptp-camps');
        }
        $orders = $wpdb->get_results(
            "SELECT o.order_number, o.billing_first_name, o.billing_last_name, o.billing_email,
            o.billing_phone, o.total_amount, o.discount_amount, o.discount_code,
            o.payment_status, o.status, o.referral_code_used,
            (SELECT COUNT(*) FROM " . CC_DB::camp_items() . " i WHERE i.order_id=o.id) as campers,
            (SELECT GROUP_CONCAT(DISTINCT i.camp_name SEPARATOR '; ') FROM " . CC_DB::camp_items() . " i WHERE i.order_id=o.id) as camps,
            o.created_at
            FROM $co o ORDER BY o.created_at DESC", ARRAY_A
        );
        return $this->csv_response($orders, 'ptp-camps');
    }

    private function csv_response($rows, $filename) {
        if (!$rows) return ['csv' => '', 'filename' => $filename . '.csv', 'count' => 0];
        $headers = array_keys($rows[0]);
        $csv = implode(',', $headers) . "\n";
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(function($v) {
                return '"' . str_replace('"', '""', $v ?? '') . '"';
            }, $row)) . "\n";
        }
        return ['csv' => $csv, 'filename' => $filename . '.csv', 'count' => count($rows)];
    }

    // ═══════════════════════════════════════
    // UNREAD COUNT
    // ═══════════════════════════════════════

    public function get_unread() {
        global $wpdb;
        $last_check = get_option('ptp_cc_last_inbox_check', '2000-01-01 00:00:00');

        $new_incoming = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . CC_DB::op_msgs() . " WHERE direction='incoming' AND created_at > %s",
            $last_check
        ));

        $new_apps = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . CC_DB::apps() . " WHERE status='pending' AND created_at > %s",
            $last_check
        ));

        return [
            'new_messages' => $new_incoming,
            'new_apps' => $new_apps,
            'last_check' => $last_check,
        ];
    }

    // ═══════════════════════════════════════
    // PARENT ↔ CAMP CROSS-REFERENCE
    // ═══════════════════════════════════════

    public function get_parent_camps($req) {
        global $wpdb;
        $id = (int)$req['id'];
        $parent = $wpdb->get_row($wpdb->prepare("SELECT email, phone FROM " . CC_DB::parents() . " WHERE id=%d", $id));
        if (!$parent) return new WP_Error('404', 'Not found', ['status' => 404]);

        $camp_total = 0;
        $orders = [];
        $camp_bookings = [];

        // Source 1: ptp_camp_bookings (primary)
        $cb = CC_DB::camp_bookings();
        if ($this->camp_table_ok($cb) && $parent->email) {
            $camp_bookings = $wpdb->get_results($wpdb->prepare(
                "SELECT b.*, p.post_title as camp_title
                 FROM $cb b LEFT JOIN {$wpdb->posts} p ON b.camp_id=p.ID
                 WHERE LOWER(b.customer_email)=LOWER(%s) ORDER BY b.created_at DESC",
                $parent->email
            ));
            foreach ($camp_bookings as $b) {
                if ($b->status === 'confirmed') $camp_total += (float)$b->amount_paid;
            }
        }

        // Source 2: ptp_unified_camp_orders (fallback)
        $co = CC_DB::camp_orders();
        $phone_suffix = substr(preg_replace('/\D/', '', $parent->phone ?: ''), -10);
        if ($this->camp_table_ok($co)) {
            if ($parent->email) {
                $orders = $wpdb->get_results($wpdb->prepare(
                    "SELECT o.*,
                    (SELECT COUNT(*) FROM " . CC_DB::camp_items() . " i WHERE i.order_id=o.id) as camper_count,
                    (SELECT GROUP_CONCAT(DISTINCT i.camp_name SEPARATOR ', ') FROM " . CC_DB::camp_items() . " i WHERE i.order_id=o.id) as camp_names
                    FROM $co o WHERE o.billing_email=%s" . ($phone_suffix ? " OR o.billing_phone LIKE %s" : "") . "
                    ORDER BY o.created_at DESC",
                    $parent->email, '%' . $phone_suffix
                ));
            } elseif ($phone_suffix) {
                $orders = $wpdb->get_results($wpdb->prepare(
                    "SELECT o.*,
                    (SELECT COUNT(*) FROM " . CC_DB::camp_items() . " i WHERE i.order_id=o.id) as camper_count,
                    (SELECT GROUP_CONCAT(DISTINCT i.camp_name SEPARATOR ', ') FROM " . CC_DB::camp_items() . " i WHERE i.order_id=o.id) as camp_names
                    FROM $co o WHERE o.billing_phone LIKE %s ORDER BY o.created_at DESC",
                    '%' . $phone_suffix
                ));
            }
            // Only add unified totals if camp_bookings didn't return data
            if (empty($camp_bookings)) {
                foreach ($orders as $o) $camp_total += (float)$o->total_amount;
            }
        }

        return [
            'camp_orders' => $orders,
            'camp_bookings' => $camp_bookings,
            'camp_total' => $camp_total,
        ];
    }

    // ═══════════════════════════════════════
    // ASSIGN TRAINER
    // ═══════════════════════════════════════

    public function assign_trainer($req) {
        global $wpdb;
        $id = (int)$req['id'];
        $b = $req->get_json_params();
        $trainer_id = (int)($b['trainer_id'] ?? 0);

        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id, display_name, slug FROM " . CC_DB::trainers() . " WHERE id=%d", $trainer_id
        ));
        if (!$trainer) return new WP_Error('404', 'Trainer not found', ['status' => 404]);

        $wpdb->update(CC_DB::apps(), [
            'trainer_name' => $trainer->display_name,
            'trainer_slug' => $trainer->slug,
        ], ['id' => $id]);

        return ['success' => true, 'trainer' => $trainer];
    }

    // ═══════════════════════════════════════
    // TEMPLATES CRUD
    // ═══════════════════════════════════════

    public function register_template_routes() {
        register_rest_route($this->ns, '/templates', [
            ['methods' => 'GET', 'callback' => [$this, 'get_templates'], 'permission_callback' => [$this, 'is_admin']],
            ['methods' => 'POST', 'callback' => [$this, 'create_template'], 'permission_callback' => [$this, 'is_admin']],
        ]);
        register_rest_route($this->ns, '/templates/(?P<id>\d+)', [
            ['methods' => 'PATCH', 'callback' => [$this, 'update_template'], 'permission_callback' => [$this, 'is_admin']],
            ['methods' => 'DELETE', 'callback' => [$this, 'delete_template'], 'permission_callback' => [$this, 'is_admin']],
        ]);
        register_rest_route($this->ns, '/templates/(?P<id>\d+)/use', [
            'methods' => 'POST', 'callback' => [$this, 'use_template'], 'permission_callback' => [$this, 'is_admin'],
        ]);

        // Global search
        register_rest_route($this->ns, '/search', [
            'methods' => 'GET', 'callback' => [$this, 'global_search'], 'permission_callback' => [$this, 'is_admin'],
        ]);

        // Activity log
        register_rest_route($this->ns, '/activity', [
            'methods' => 'GET', 'callback' => [$this, 'get_activity_log'], 'permission_callback' => [$this, 'is_admin'],
        ]);

        // Lead score refresh
        register_rest_route($this->ns, '/lead-score/(?P<app_id>\d+)', [
            'methods' => 'POST', 'callback' => [$this, 'refresh_lead_score'], 'permission_callback' => [$this, 'is_admin'],
        ]);

        // Cron status
        register_rest_route($this->ns, '/cron-status', [
            'methods' => 'GET', 'callback' => [$this, 'get_cron_status'], 'permission_callback' => [$this, 'is_admin'],
        ]);
    }

    public function get_templates() {
        global $wpdb;
        return ['templates' => $wpdb->get_results(
            "SELECT * FROM " . CC_DB::templates() . " ORDER BY use_count DESC, name ASC"
        )];
    }

    public function create_template($req) {
        global $wpdb;
        $b = $req->get_json_params();
        $wpdb->insert(CC_DB::templates(), [
            'name'     => sanitize_text_field($b['name'] ?? 'New Template'),
            'category' => sanitize_text_field($b['category'] ?? 'general'),
            'body'     => sanitize_textarea_field($b['body'] ?? ''),
        ]);
        CC_DB::log('template_created', 'template', $wpdb->insert_id, $b['name'] ?? '', 'admin');
        return ['success' => true, 'id' => $wpdb->insert_id];
    }

    public function update_template($req) {
        global $wpdb;
        $b = $req->get_json_params();
        $data = [];
        if (isset($b['name']))     $data['name']     = sanitize_text_field($b['name']);
        if (isset($b['category'])) $data['category'] = sanitize_text_field($b['category']);
        if (isset($b['body']))     $data['body']     = sanitize_textarea_field($b['body']);
        if (!empty($data)) $wpdb->update(CC_DB::templates(), $data, ['id' => (int)$req['id']]);
        return ['success' => true];
    }

    public function delete_template($req) {
        global $wpdb;
        $wpdb->delete(CC_DB::templates(), ['id' => (int)$req['id']]);
        return ['success' => true];
    }

    public function use_template($req) {
        global $wpdb;
        $id = (int)$req['id'];
        $b = $req->get_json_params();
        $tpl = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . CC_DB::templates() . " WHERE id=%d", $id));
        if (!$tpl) return new WP_Error('404', 'Not found', ['status' => 404]);

        // Personalize
        $msg = $tpl->body;
        if (isset($b['name'])) $msg = str_replace('{name}', $b['name'], $msg);
        if (isset($b['child'])) $msg = str_replace('{child}', $b['child'], $msg);
        if (isset($b['trainer'])) $msg = str_replace('{trainer}', $b['trainer'], $msg);

        // Increment use count
        $wpdb->query($wpdb->prepare("UPDATE " . CC_DB::templates() . " SET use_count=use_count+1 WHERE id=%d", $id));

        return ['success' => true, 'message' => $msg, 'template' => $tpl->name];
    }

    // ═══════════════════════════════════════
    // GLOBAL SEARCH
    // ═══════════════════════════════════════

    public function global_search($req) {
        global $wpdb;
        $q = sanitize_text_field($req->get_param('q') ?? '');
        if (strlen($q) < 2) return ['results' => []];

        $like = '%' . $wpdb->esc_like($q) . '%';
        $results = [];

        // Applications
        $apps = $wpdb->get_results($wpdb->prepare(
            "SELECT id, parent_name as name, email, phone, child_name, status, 'application' as type
            FROM " . CC_DB::apps() . "
            WHERE parent_name LIKE %s OR email LIKE %s OR phone LIKE %s OR child_name LIKE %s
            LIMIT 10", $like, $like, $like, $like
        ));
        foreach ($apps as $a) $results[] = $a;

        // Parents
        $parents = $wpdb->get_results($wpdb->prepare(
            "SELECT id, display_name as name, email, phone, '' as child_name, '' as status, 'parent' as type
            FROM " . CC_DB::parents() . "
            WHERE display_name LIKE %s OR email LIKE %s OR phone LIKE %s
            LIMIT 10", $like, $like, $like
        ));
        foreach ($parents as $p) $results[] = $p;

        // Camp orders
        $co = CC_DB::camp_orders();
        if ($wpdb->get_var("SHOW TABLES LIKE '$co'") === $co) {
            $camps = $wpdb->get_results($wpdb->prepare(
                "SELECT id, CONCAT(billing_first_name,' ',billing_last_name) as name, billing_email as email,
                billing_phone as phone, '' as child_name, payment_status as status, 'camp_order' as type
                FROM $co
                WHERE billing_first_name LIKE %s OR billing_last_name LIKE %s OR billing_email LIKE %s OR billing_phone LIKE %s
                LIMIT 10", $like, $like, $like, $like
            ));
            foreach ($camps as $c) $results[] = $c;
        }

        // Trainers
        $trainers = $wpdb->get_results($wpdb->prepare(
            "SELECT id, display_name as name, email, phone, '' as child_name, status, 'trainer' as type
            FROM " . CC_DB::trainers() . "
            WHERE display_name LIKE %s OR email LIKE %s
            LIMIT 5", $like, $like
        ));
        foreach ($trainers as $t) $results[] = $t;

        return ['results' => $results, 'query' => $q, 'count' => count($results)];
    }

    // ═══════════════════════════════════════
    // ACTIVITY LOG
    // ═══════════════════════════════════════

    public function get_activity_log($req) {
        global $wpdb;
        $limit = min((int)($req->get_param('limit') ?: 50), 200);
        $entity_type = $req->get_param('entity_type');
        $entity_id = $req->get_param('entity_id');

        $where = '1=1';
        $params = [];
        if ($entity_type) { $where .= ' AND entity_type=%s'; $params[] = $entity_type; }
        if ($entity_id) { $where .= ' AND entity_id=%d'; $params[] = (int)$entity_id; }

        $sql = "SELECT * FROM " . CC_DB::activity() . " WHERE $where ORDER BY created_at DESC LIMIT %d";
        $params[] = $limit;

        $logs = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        return ['activity' => $logs];
    }

    // ═══════════════════════════════════════
    // LEAD SCORE REFRESH
    // ═══════════════════════════════════════

    public function refresh_lead_score($req) {
        if (!class_exists('CC_Lead_Scoring')) return new WP_Error('missing', 'Lead scoring not loaded');
        $result = CC_Lead_Scoring::score_single((int)$req['app_id']);
        if (!$result) return new WP_Error('404', 'Not found', ['status' => 404]);
        return $result;
    }

    // ═══════════════════════════════════════
    // CRON STATUS
    // ═══════════════════════════════════════

    public function get_cron_status() {
        $seq_next = wp_next_scheduled('ptp_cc_run_sequences');
        $score_next = wp_next_scheduled('ptp_cc_lead_scoring');
        return [
            'sequences' => [
                'scheduled' => (bool)$seq_next,
                'next_run'  => $seq_next ? date('Y-m-d H:i:s', $seq_next) : null,
                'interval'  => '30 min',
            ],
            'lead_scoring' => [
                'scheduled' => (bool)$score_next,
                'next_run'  => $score_next ? date('Y-m-d H:i:s', $score_next) : null,
                'interval'  => 'hourly',
            ],
            'server_time' => current_time('mysql'),
        ];
    }

    // ═══════════════════════════════════════
    // TRAINING LINKS CRUD
    // ═══════════════════════════════════════

    public function register_training_link_routes() {
        register_rest_route($this->ns, '/training-links', [
            ['methods' => 'GET', 'callback' => [$this, 'get_training_links'], 'permission_callback' => [$this, 'is_admin']],
            ['methods' => 'POST', 'callback' => [$this, 'create_training_link'], 'permission_callback' => [$this, 'is_admin']],
        ]);
        register_rest_route($this->ns, '/training-links/(?P<id>\d+)', [
            ['methods' => 'PATCH', 'callback' => [$this, 'update_training_link'], 'permission_callback' => [$this, 'is_admin']],
            ['methods' => 'DELETE', 'callback' => [$this, 'delete_training_link'], 'permission_callback' => [$this, 'is_admin']],
        ]);
    }

    public function get_training_links() {
        global $wpdb;
        $links = $wpdb->get_results(
            "SELECT l.*, t.display_name as trainer_name, t.photo_url as trainer_photo
             FROM " . CC_DB::training_links() . " l
             LEFT JOIN " . CC_DB::trainers() . " t ON l.trainer_id=t.id
             ORDER BY l.created_at DESC"
        );
        return ['links' => $links];
    }

    public function create_training_link($req) {
        global $wpdb;
        $b = $req->get_json_params();
        $code = sanitize_title($b['code'] ?? '') ?: wp_generate_password(8, false);

        // Ensure unique code
        while ($wpdb->get_var($wpdb->prepare("SELECT id FROM " . CC_DB::training_links() . " WHERE code=%s", $code))) {
            $code = wp_generate_password(8, false);
        }

        $wpdb->insert(CC_DB::training_links(), [
            'code' => $code,
            'title' => sanitize_text_field($b['title'] ?? 'Training Session'),
            'description' => sanitize_textarea_field($b['description'] ?? ''),
            'trainer_id' => !empty($b['trainer_id']) ? (int)$b['trainer_id'] : null,
            'session_type' => sanitize_text_field($b['session_type'] ?? '1on1'),
            'duration_minutes' => (int)($b['duration_minutes'] ?? 60),
            'price' => (float)($b['price'] ?? 70),
            'location' => sanitize_text_field($b['location'] ?? ''),
            'max_bookings' => (int)($b['max_bookings'] ?? 0),
            'expires_at' => !empty($b['expires_at']) ? sanitize_text_field($b['expires_at']) : null,
            'status' => 'active',
        ]);

        $id = $wpdb->insert_id;
        $url = home_url('/book/' . $code);
        CC_DB::log('training_link_created', 'training_link', $id, "Link: $code — $url", 'admin');

        return ['success' => true, 'id' => $id, 'code' => $code, 'url' => $url];
    }

    public function update_training_link($req) {
        global $wpdb;
        $b = $req->get_json_params();
        $data = [];
        $int_fields = ['trainer_id', 'duration_minutes', 'max_bookings'];
        $float_fields = ['price'];
        $allowed = ['title','description','trainer_id','session_type','duration_minutes','price','location','max_bookings','status','expires_at'];
        foreach ($allowed as $f) {
            if (isset($b[$f])) {
                if (in_array($f, $float_fields)) {
                    $data[$f] = round((float)$b[$f], 2);
                } elseif (in_array($f, $int_fields)) {
                    $data[$f] = (int)$b[$f];
                } elseif ($f === 'description') {
                    $data[$f] = sanitize_textarea_field($b[$f]);
                } else {
                    $data[$f] = sanitize_text_field($b[$f]);
                }
            }
        }
        if (!empty($data)) $wpdb->update(CC_DB::training_links(), $data, ['id' => (int)$req['id']]);
        return ['success' => true];
    }

    public function delete_training_link($req) {
        global $wpdb;
        $wpdb->update(CC_DB::training_links(), ['status' => 'archived'], ['id' => (int)$req['id']]);
        return ['success' => true];
    }

    // ═══════════════════════════════════════
    // BOOKING MANAGEMENT (Admin)
    // ═══════════════════════════════════════

    public function register_booking_routes() {
        register_rest_route($this->ns, '/bookings/(?P<id>\d+)', [
            ['methods' => 'PATCH', 'callback' => [$this, 'update_booking'], 'permission_callback' => [$this, 'is_admin']],
        ]);
        register_rest_route($this->ns, '/bookings/create', [
            'methods' => 'POST', 'callback' => [$this, 'admin_create_booking'], 'permission_callback' => [$this, 'is_admin'],
        ]);
    }

    public function update_booking($req) {
        global $wpdb;
        $b = $req->get_json_params();
        $data = [];
        $allowed = ['status','payment_status','session_date','session_time','trainer_id','notes','total_amount','location'];
        foreach ($allowed as $f) {
            if (isset($b[$f])) $data[$f] = sanitize_text_field($b[$f]);
        }
        if (!empty($data)) $wpdb->update(CC_DB::bookings(), $data, ['id' => (int)$req['id']]);
        CC_DB::log('booking_updated', 'booking', (int)$req['id'], wp_json_encode($data), 'admin');
        return ['success' => true];
    }

    public function admin_create_booking($req) {
        global $wpdb;
        $b = $req->get_json_params();

        $wpdb->insert(CC_DB::bookings(), [
            'parent_id' => (int)($b['parent_id'] ?? 0),
            'trainer_id' => !empty($b['trainer_id']) ? (int)$b['trainer_id'] : null,
            'session_date' => sanitize_text_field($b['session_date'] ?? ''),
            'session_time' => sanitize_text_field($b['session_time'] ?? ''),
            'duration_minutes' => (int)($b['duration_minutes'] ?? 60),
            'location' => sanitize_text_field($b['location'] ?? ''),
            'total_amount' => (float)($b['total_amount'] ?? 0),
            'status' => sanitize_text_field($b['status'] ?? 'pending'),
            'payment_status' => sanitize_text_field($b['payment_status'] ?? 'unpaid'),
            'notes' => sanitize_textarea_field($b['notes'] ?? ''),
            'booked_via' => 'admin',
        ]);

        CC_DB::log('booking_created', 'booking', $wpdb->insert_id, 'Admin-created booking', 'admin');
        return ['success' => true, 'id' => $wpdb->insert_id];
    }

    // ═══════════════════════════════════════
    // FAMILY MANAGEMENT (Standalone)
    // ═══════════════════════════════════════

    public function register_family_routes() {
        register_rest_route($this->ns, '/families/create', [
            'methods' => 'POST', 'callback' => [$this, 'create_family'], 'permission_callback' => [$this, 'is_admin'],
        ]);
        register_rest_route($this->ns, '/families/(?P<id>\d+)', [
            ['methods' => 'PATCH', 'callback' => [$this, 'update_family'], 'permission_callback' => [$this, 'is_admin']],
        ]);
        register_rest_route($this->ns, '/families/(?P<id>\d+)/send-link', [
            'methods' => 'POST', 'callback' => [$this, 'send_training_link'], 'permission_callback' => [$this, 'is_admin'],
        ]);
    }

    public function create_family($req) {
        global $wpdb;
        $b = $req->get_json_params();
        $wpdb->insert(CC_DB::parents(), [
            'display_name' => sanitize_text_field($b['name'] ?? ''),
            'email' => sanitize_email($b['email'] ?? ''),
            'phone' => sanitize_text_field($b['phone'] ?? ''),
            'city' => sanitize_text_field($b['city'] ?? ''),
            'state' => sanitize_text_field($b['state'] ?? ''),
        ]);
        CC_DB::log('family_created', 'family', $wpdb->insert_id, $b['name'] ?? '', 'admin');
        return ['success' => true, 'id' => $wpdb->insert_id];
    }

    public function update_family($req) {
        global $wpdb;
        $b = $req->get_json_params();
        $data = [];
        if (isset($b['name'])) $data['display_name'] = sanitize_text_field($b['name']);
        if (isset($b['email'])) $data['email'] = sanitize_email($b['email']);
        if (isset($b['phone'])) $data['phone'] = sanitize_text_field($b['phone']);
        if (isset($b['notes'])) $data['notes'] = sanitize_textarea_field($b['notes']);
        if (!empty($data)) $wpdb->update(CC_DB::parents(), $data, ['id' => (int)$req['id']]);
        return ['success' => true];
    }

    public function send_training_link($req) {
        global $wpdb;
        $b = $req->get_json_params();
        $family_id = (int)$req['id'];
        $link_id = (int)($b['link_id'] ?? 0);

        $parent = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . CC_DB::parents() . " WHERE id=%d", $family_id));
        if (!$parent) return new WP_Error('404', 'Family not found', ['status' => 404]);

        $link = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . CC_DB::training_links() . " WHERE id=%d", $link_id));
        if (!$link) return new WP_Error('404', 'Training link not found', ['status' => 404]);

        $url = home_url('/book/' . $link->code);
        $first = explode(' ', $parent->display_name)[0];
        $msg = $b['message'] ?? "Hey $first! Here's your training link to book a session with PTP: $url";

        if ($parent->phone) {
            $result = CC_DB::send_sms($parent->phone, $msg);
            if (is_wp_error($result)) return new WP_Error('sms_fail', $result->get_error_message(), ['status' => 500]);
        }

        CC_DB::log('training_link_sent', 'family', $family_id, "Link '{$link->title}' sent to {$parent->display_name}", 'admin');
        return ['success' => true, 'url' => $url];
    }

    // ═══════════════════════════════════════
    // TRAINER MANAGEMENT (Standalone)
    // ═══════════════════════════════════════

    public function register_trainer_mgmt_routes() {
        register_rest_route($this->ns, '/trainers/create', [
            'methods' => 'POST', 'callback' => [$this, 'create_trainer'], 'permission_callback' => [$this, 'is_admin'],
        ]);
        register_rest_route($this->ns, '/trainers/(?P<id>\d+)', [
            ['methods' => 'PATCH', 'callback' => [$this, 'update_trainer'], 'permission_callback' => [$this, 'is_admin']],
        ]);
    }

    public function create_trainer($req) {
        global $wpdb;
        $b = $req->get_json_params();
        $name = sanitize_text_field($b['name'] ?? '');
        $slug = sanitize_title($name);

        $wpdb->insert(CC_DB::trainers(), [
            'display_name' => $name,
            'slug' => $slug,
            'email' => sanitize_email($b['email'] ?? ''),
            'phone' => sanitize_text_field($b['phone'] ?? ''),
            'bio' => sanitize_textarea_field($b['bio'] ?? ''),
            'hourly_rate' => (float)($b['hourly_rate'] ?? 70),
            'location' => sanitize_text_field($b['location'] ?? ''),
            'sport' => sanitize_text_field($b['sport'] ?? 'soccer'),
            'credentials' => sanitize_textarea_field($b['credentials'] ?? ''),
            'status' => 'approved',
        ]);

        CC_DB::log('trainer_created', 'trainer', $wpdb->insert_id, $name, 'admin');
        return ['success' => true, 'id' => $wpdb->insert_id];
    }

    public function update_trainer($req) {
        global $wpdb;
        $b = $req->get_json_params();
        $data = [];
        $allowed = ['display_name','email','phone','bio','hourly_rate','location','sport','credentials','status','photo_url'];
        foreach ($allowed as $f) {
            if (isset($b[$f])) {
                if ($f === 'hourly_rate') {
                    $data[$f] = round((float)$b[$f], 2);
                } elseif (in_array($f, ['bio', 'credentials'])) {
                    $data[$f] = sanitize_textarea_field($b[$f]);
                } elseif ($f === 'photo_url') {
                    $data[$f] = esc_url_raw($b[$f]);
                } else {
                    $data[$f] = sanitize_text_field($b[$f]);
                }
            }
        }
        if (!empty($data)) $wpdb->update(CC_DB::trainers(), $data, ['id' => (int)$req['id']]);
        return ['success' => true];
    }

    // ═══════════════════════════════════════
    // CAMPS — Full Integration
    // ═══════════════════════════════════════

    public function register_camp_routes() {
        register_rest_route($this->ns, '/camps/stats', [
            'methods' => 'GET', 'callback' => [$this, 'get_camp_stats_full'], 'permission_callback' => [$this, 'is_admin'],
        ]);
        register_rest_route($this->ns, '/camps/bookings', [
            'methods' => 'GET', 'callback' => [$this, 'get_camp_bookings_list'], 'permission_callback' => [$this, 'is_admin'],
        ]);
        register_rest_route($this->ns, '/camps/listings', [
            'methods' => 'GET', 'callback' => [$this, 'get_camp_listings'], 'permission_callback' => [$this, 'is_admin'],
        ]);
        register_rest_route($this->ns, '/camps/abandoned', [
            'methods' => 'GET', 'callback' => [$this, 'get_camp_abandoned'], 'permission_callback' => [$this, 'is_admin'],
        ]);
        register_rest_route($this->ns, '/camps/attribution', [
            'methods' => 'GET', 'callback' => [$this, 'get_camp_attribution'], 'permission_callback' => [$this, 'is_admin'],
        ]);
        register_rest_route($this->ns, '/camps/customers', [
            'methods' => 'GET', 'callback' => [$this, 'get_camp_customers'], 'permission_callback' => [$this, 'is_admin'],
        ]);
    }

    private function camp_table_ok($t) {
        global $wpdb;
        return $wpdb->get_var("SHOW TABLES LIKE '$t'") === $t;
    }

    /**
     * Consolidated camp stats from ALL sources
     */
    public function get_camp_stats_full() {
        global $wpdb;
        $result = [
            'total_revenue' => 0, 'total_bookings' => 0, 'unique_families' => 0,
            'avg_order' => 0, 'abandoned_count' => 0, 'abandoned_value' => 0,
            'recovered_count' => 0, 'recovered_value' => 0,
            'by_camp' => [], 'by_source' => [], 'monthly' => [],
            'coupon_usage' => 0, 'referral_usage' => 0,
            'total_discount' => 0,
        ];

        // Primary: ptp_camp_bookings
        $bt = CC_DB::camp_bookings();
        $has_bt = $this->camp_table_ok($bt);

        if ($has_bt) {
            $r = $wpdb->get_row("SELECT COALESCE(SUM(amount_paid),0) as rev, COUNT(*) as cnt,
                COUNT(DISTINCT customer_email) as families, AVG(amount_paid) as avg_order,
                SUM(CASE WHEN coupon_code!='' THEN 1 ELSE 0 END) as coupon_uses,
                SUM(CASE WHEN referral_code!='' THEN 1 ELSE 0 END) as ref_uses,
                COALESCE(SUM(discount_amount),0) as total_disc
                FROM $bt WHERE status='confirmed'");
            $result['total_revenue'] = (float)($r->rev ?? 0);
            $result['total_bookings'] = (int)($r->cnt ?? 0);
            $result['unique_families'] = (int)($r->families ?? 0);
            $result['avg_order'] = round((float)($r->avg_order ?? 0), 2);
            $result['coupon_usage'] = (int)($r->coupon_uses ?? 0);
            $result['referral_usage'] = (int)($r->ref_uses ?? 0);
            $result['total_discount'] = (float)($r->total_disc ?? 0);

            // By camp
            $result['by_camp'] = $wpdb->get_results(
                "SELECT b.camp_id, p.post_title as camp_name,
                 COUNT(*) as cnt, COALESCE(SUM(b.amount_paid),0) as rev
                 FROM $bt b LEFT JOIN {$wpdb->posts} p ON b.camp_id=p.ID
                 WHERE b.status='confirmed' GROUP BY b.camp_id ORDER BY cnt DESC LIMIT 30"
            );

            // By source (how_found_us)
            $cols = $wpdb->get_col("DESCRIBE $bt", 0);
            if (in_array('how_found_us', $cols)) {
                $result['by_source'] = $wpdb->get_results(
                    "SELECT how_found_us as source, COUNT(*) as cnt, COALESCE(SUM(amount_paid),0) as rev
                     FROM $bt WHERE status='confirmed' AND how_found_us!='' GROUP BY how_found_us ORDER BY cnt DESC"
                );
            }

            // Monthly
            $result['monthly'] = $wpdb->get_results(
                "SELECT DATE_FORMAT(created_at,'%Y-%m') as month, COUNT(*) as cnt, COALESCE(SUM(amount_paid),0) as rev
                 FROM $bt WHERE status='confirmed' GROUP BY month ORDER BY month DESC LIMIT 12"
            );
        }

        // Secondary: ptp_unified_camp_orders (only if primary doesn't exist)
        if (!$has_bt) {
            $co = CC_DB::camp_orders();
            if ($this->camp_table_ok($co)) {
                $r2 = $wpdb->get_row("SELECT COALESCE(SUM(total_amount),0) as rev, COUNT(*) as cnt,
                    COUNT(DISTINCT billing_email) as families FROM $co WHERE payment_status='completed'");
                $result['total_revenue'] = (float)($r2->rev ?? 0);
                $result['total_bookings'] = (int)($r2->cnt ?? 0);
                $result['unique_families'] = (int)($r2->families ?? 0);
                if ($result['total_bookings'] > 0) {
                    $result['avg_order'] = round($result['total_revenue'] / $result['total_bookings'], 2);
                }
            }
        }

        // Abandoned carts
        $ac = CC_DB::camp_abandoned();
        if ($this->camp_table_ok($ac)) {
            $ab = $wpdb->get_row("SELECT
                SUM(CASE WHEN status='abandoned' THEN 1 ELSE 0 END) as ab_cnt,
                SUM(CASE WHEN status='recovered' THEN 1 ELSE 0 END) as rec_cnt,
                COALESCE(SUM(CASE WHEN status='abandoned' THEN cart_total ELSE 0 END),0) as ab_val,
                COALESCE(SUM(CASE WHEN status='recovered' THEN cart_total ELSE 0 END),0) as rec_val
                FROM $ac");
            $result['abandoned_count'] = (int)($ab->ab_cnt ?? 0);
            $result['abandoned_value'] = (float)($ab->ab_val ?? 0);
            $result['recovered_count'] = (int)($ab->rec_cnt ?? 0);
            $result['recovered_value'] = (float)($ab->rec_val ?? 0);
        }

        return $result;
    }

    /**
     * All camp bookings from ptp_camp_bookings with camp title join
     */
    public function get_camp_bookings_list($req) {
        global $wpdb;
        $bt = CC_DB::camp_bookings();
        if (!$this->camp_table_ok($bt)) {
            // Fallback to unified orders
            return $this->get_camp_orders($req);
        }

        $limit = min((int)($req->get_param('limit') ?: 300), 500);
        $search = $req->get_param('search');
        $camp_id = $req->get_param('camp_id');

        $where = '1=1'; $params = [];
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (b.customer_name LIKE %s OR b.customer_email LIKE %s OR b.camper_name LIKE %s)';
            array_push($params, $like, $like, $like);
        }
        if ($camp_id) { $where .= ' AND b.camp_id=%d'; $params[] = (int)$camp_id; }

        $sql = "SELECT b.*, p.post_title as camp_title
                FROM $bt b LEFT JOIN {$wpdb->posts} p ON b.camp_id=p.ID
                WHERE $where ORDER BY b.created_at DESC LIMIT %d";
        $params[] = $limit;

        $bookings = $wpdb->get_results($wpdb->prepare($sql, ...$params));

        return ['bookings' => $bookings, 'source' => 'camp_bookings'];
    }

    /**
     * Camp listings from ptp_camp CPT with enrollment counts
     */
    public function get_camp_listings() {
        global $wpdb;
        $camps = get_posts([
            'post_type' => 'ptp_camp', 'post_status' => 'publish',
            'posts_per_page' => 100, 'orderby' => 'meta_value', 'meta_key' => '_camp_start_date', 'order' => 'ASC',
        ]);

        if (empty($camps)) return ['camps' => []];

        $bt = CC_DB::camp_bookings();
        $has_bt = $this->camp_table_ok($bt);

        // Also check order_items for enrollment
        $oi = CC_DB::camp_items();
        $has_oi = $this->camp_table_ok($oi);

        $result = [];
        foreach ($camps as $camp) {
            $id = $camp->ID;
            $capacity = (int)(get_post_meta($id, '_camp_capacity', true) ?: 60);
            $enrolled = 0; $revenue = 0;

            if ($has_bt) {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT COUNT(*) as cnt, COALESCE(SUM(amount_paid),0) as rev FROM $bt WHERE camp_id=%d AND status='confirmed'", $id
                ));
                $enrolled = (int)$row->cnt;
                $revenue = (float)$row->rev;
            } elseif ($has_oi) {
                $enrolled = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $oi WHERE camp_id=%d AND status!='refunded'", $id
                ));
            }

            $price = (float)(get_post_meta($id, '_camp_price', true) ?: 525);
            $sale = (float)(get_post_meta($id, '_camp_sale_price', true) ?: 0);

            $result[] = [
                'id' => $id, 'title' => $camp->post_title,
                'start_date' => get_post_meta($id, '_camp_start_date', true),
                'end_date' => get_post_meta($id, '_camp_end_date', true),
                'date_short' => get_post_meta($id, '_camp_date_short', true) ?: get_post_meta($id, '_camp_date', true),
                'time' => get_post_meta($id, '_camp_time', true),
                'location' => get_post_meta($id, '_camp_location_short', true) ?: get_post_meta($id, '_camp_location', true),
                'state' => get_post_meta($id, '_camp_state', true),
                'price' => $price, 'sale_price' => $sale,
                'capacity' => $capacity, 'enrolled' => $enrolled,
                'pct' => $capacity > 0 ? round($enrolled / $capacity * 100) : 0,
                'revenue' => $revenue,
            ];
        }
        return ['camps' => $result];
    }

    /**
     * Abandoned carts from ptp_camp_abandoned_carts
     */
    public function get_camp_abandoned($req) {
        global $wpdb;
        $ac = CC_DB::camp_abandoned();
        if (!$this->camp_table_ok($ac)) return ['carts' => [], 'stats' => null];

        $carts = $wpdb->get_results("SELECT * FROM $ac ORDER BY created_at DESC LIMIT 200");
        $stats = $wpdb->get_row("SELECT COUNT(*) as total,
            SUM(CASE WHEN status='abandoned' THEN 1 ELSE 0 END) as abandoned,
            SUM(CASE WHEN status='recovered' THEN 1 ELSE 0 END) as recovered,
            COALESCE(SUM(CASE WHEN status='abandoned' THEN cart_total ELSE 0 END),0) as ab_value,
            COALESCE(SUM(CASE WHEN status='recovered' THEN cart_total ELSE 0 END),0) as rec_value,
            AVG(CASE WHEN status='abandoned' THEN cart_total ELSE NULL END) as avg_cart
            FROM $ac");

        return ['carts' => $carts, 'stats' => $stats];
    }

    /**
     * Camp attribution: UTM sources, campaigns, how_found_us
     */
    public function get_camp_attribution() {
        global $wpdb;
        $bt = CC_DB::camp_bookings();
        if (!$this->camp_table_ok($bt)) return ['utm_sources' => [], 'utm_campaigns' => [], 'how_found' => []];

        $cols = $wpdb->get_col("DESCRIBE $bt", 0);
        $result = ['utm_sources' => [], 'utm_campaigns' => [], 'how_found' => []];

        if (in_array('utm_source', $cols)) {
            $result['utm_sources'] = $wpdb->get_results(
                "SELECT utm_source as source, COUNT(*) as cnt, COALESCE(SUM(amount_paid),0) as rev
                 FROM $bt WHERE status='confirmed' AND utm_source!='' GROUP BY utm_source ORDER BY rev DESC"
            );
        }
        if (in_array('utm_campaign', $cols)) {
            $result['utm_campaigns'] = $wpdb->get_results(
                "SELECT utm_campaign as campaign, COUNT(*) as cnt, COALESCE(SUM(amount_paid),0) as rev
                 FROM $bt WHERE status='confirmed' AND utm_campaign!='' GROUP BY utm_campaign ORDER BY rev DESC"
            );
        }
        if (in_array('how_found_us', $cols)) {
            $result['how_found'] = $wpdb->get_results(
                "SELECT how_found_us as source, COUNT(*) as cnt, COALESCE(SUM(amount_paid),0) as rev
                 FROM $bt WHERE status='confirmed' AND how_found_us!='' GROUP BY how_found_us ORDER BY cnt DESC"
            );
        }
        return $result;
    }

    /**
     * Unique camp customers with LTV from camp_bookings
     */
    public function get_camp_customers($req) {
        global $wpdb;
        $bt = CC_DB::camp_bookings();
        if (!$this->camp_table_ok($bt)) return ['customers' => []];

        $limit = min((int)($req->get_param('limit') ?: 200), 500);
        $search = $req->get_param('search');

        $where = "status='confirmed'"; $params = [];
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (customer_name LIKE %s OR customer_email LIKE %s OR camper_name LIKE %s)';
            array_push($params, $like, $like, $like);
        }

        $sql = "SELECT customer_email as email, MAX(customer_name) as name, MAX(customer_phone) as phone,
                COUNT(*) as bookings, COALESCE(SUM(amount_paid),0) as total_spent,
                GROUP_CONCAT(DISTINCT camper_name SEPARATOR ', ') as campers,
                MIN(created_at) as first_booking, MAX(created_at) as last_booking
                FROM $bt WHERE $where
                GROUP BY customer_email ORDER BY total_spent DESC LIMIT %d";
        $params[] = $limit;

        $customers = $wpdb->get_results($wpdb->prepare($sql, ...$params));

        // Cross-reference: check if any camp customers are also in training pipeline
        $pt = CC_DB::parents();
        foreach ($customers as &$c) {
            $c->in_training = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $pt WHERE email=%s", $c->email
            ));
        }

        return ['customers' => $customers];
    }

    // ═══════════════════════════════════════
    // CUSTOMER 360 — Unified Profile
    // ═══════════════════════════════════════

    public function register_customer360_routes() {
        register_rest_route($this->ns, '/customer360/(?P<key>.+)', [
            'methods' => 'GET', 'callback' => [$this, 'get_customer360'], 'permission_callback' => [$this, 'is_admin'],
        ]);
        register_rest_route($this->ns, '/customer360-search', [
            'methods' => 'GET', 'callback' => [$this, 'search_customers_global'], 'permission_callback' => [$this, 'is_admin'],
        ]);
    }

    /**
     * Global customer search across ALL tables — returns deduplicated contacts
     */
    public function search_customers_global($req) {
        global $wpdb;
        $q = $req->get_param('q');
        if (!$q || strlen($q) < 2) return ['results' => []];
        $like = '%' . $wpdb->esc_like($q) . '%';
        $seen = [];
        $results = [];

        // Pipeline
        $apps = $wpdb->get_results($wpdb->prepare(
            "SELECT parent_name as name, email, phone, 'pipeline' as source FROM " . CC_DB::apps() .
            " WHERE parent_name LIKE %s OR email LIKE %s OR phone LIKE %s LIMIT 20", $like, $like, $like
        ));
        foreach ($apps ?: [] as $r) {
            $key = strtolower(trim($r->email ?: $r->phone));
            if ($key && !isset($seen[$key])) { $seen[$key] = 1; $r->lookup = $r->email ?: $r->phone; $results[] = $r; }
        }

        // Families
        $parents = $wpdb->get_results($wpdb->prepare(
            "SELECT display_name as name, email, phone, 'family' as source FROM " . CC_DB::parents() .
            " WHERE display_name LIKE %s OR email LIKE %s OR phone LIKE %s LIMIT 20", $like, $like, $like
        ));
        foreach ($parents ?: [] as $r) {
            $key = strtolower(trim($r->email ?: $r->phone));
            if ($key && !isset($seen[$key])) { $seen[$key] = 1; $r->lookup = $r->email ?: $r->phone; $results[] = $r; }
        }

        // Camp bookings
        $cb = CC_DB::camp_bookings();
        if ($this->camp_table_ok($cb)) {
            $camp_custs = $wpdb->get_results($wpdb->prepare(
                "SELECT customer_name as name, customer_email as email, customer_phone as phone, 'camp' as source FROM $cb" .
                " WHERE customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s GROUP BY customer_email LIMIT 20",
                $like, $like, $like
            ));
            foreach ($camp_custs ?: [] as $r) {
                $key = strtolower(trim($r->email ?: $r->phone));
                if ($key && !isset($seen[$key])) { $seen[$key] = 1; $r->lookup = $r->email ?: $r->phone; $results[] = $r; }
            }
        }

        return ['results' => array_slice($results, 0, 25)];
    }

    /**
     * Customer 360: Everything about one contact in one call
     * key = email address or phone number
     */
    public function get_customer360($req) {
        global $wpdb;
        $key = urldecode($req['key']);

        // Short cache (5 min) to avoid heavy queries on rapid browsing
        $cache_key = 'ptp_c360_' . md5($key);
        $cached = get_transient($cache_key);
        if ($cached !== false && !$req->get_param('nocache')) {
            return $cached;
        }

        $is_email = strpos($key, '@') !== false;
        $phone_suffix = '';
        if (!$is_email) {
            $phone_suffix = substr(preg_replace('/\D/', '', $key), -10);
        }

        $profile = [
            'key' => $key, 'name' => '', 'email' => '', 'phone' => '',
            'first_seen' => '', 'tags' => [],
        ];
        $timeline = [];

        // ── 1. Pipeline entries ──
        $at = CC_DB::apps();
        if ($is_email) {
            $apps = $wpdb->get_results($wpdb->prepare("SELECT * FROM $at WHERE email=%s ORDER BY created_at DESC", $key));
        } else {
            $apps = $wpdb->get_results($wpdb->prepare("SELECT * FROM $at WHERE phone LIKE %s ORDER BY created_at DESC", '%'.$phone_suffix));
        }
        foreach ($apps ?: [] as $a) {
            if (!$profile['name']) $profile['name'] = $a->parent_name;
            if (!$profile['email'] && $a->email) $profile['email'] = $a->email;
            if (!$profile['phone'] && $a->phone) $profile['phone'] = $a->phone;
            $timeline[] = [
                'type' => 'pipeline', 'date' => $a->created_at,
                'title' => 'Pipeline Entry: ' . ($a->status ?: 'pending'),
                'detail' => 'Player: ' . ($a->child_name ?: '-') . ' | Age: ' . ($a->child_age ?: '-'),
                'meta' => ['id' => $a->id, 'status' => $a->status, 'source' => $a->source ?? ''],
            ];
            // Follow-ups (CC standalone uses app_id, TP may use application_id)
            $fu_table = CC_DB::follow_ups();
            if (!isset($fu_cols)) $fu_cols = $wpdb->get_col("DESCRIBE $fu_table", 0);
            $fu_col = in_array('application_id', $fu_cols) ? 'application_id' : 'app_id';
            $fups = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $fu_table WHERE $fu_col=%d ORDER BY created_at ASC", $a->id
            ));
            foreach ($fups ?: [] as $f) {
                $timeline[] = [
                    'type' => 'follow_up', 'date' => $f->created_at,
                    'title' => 'Follow-up (Step ' . ($f->step ?? $f->step_number ?? '?') . ')',
                    'detail' => $f->message_preview ?? $f->body ?? ($f->type ?? 'sms'),
                    'meta' => ['step' => $f->step ?? $f->step_number ?? 0],
                ];
            }
        }

        // ── 2. Family / Parent record ──
        $pt = CC_DB::parents();
        if ($is_email) {
            $parent = $wpdb->get_row($wpdb->prepare("SELECT * FROM $pt WHERE email=%s LIMIT 1", $key));
        } else {
            $parent = $wpdb->get_row($wpdb->prepare("SELECT * FROM $pt WHERE phone LIKE %s LIMIT 1", '%'.$phone_suffix));
        }
        if ($parent) {
            if (!$profile['name']) $profile['name'] = $parent->display_name;
            if (!$profile['email']) $profile['email'] = $parent->email;
            if (!$profile['phone']) $profile['phone'] = $parent->phone;
            $profile['family_id'] = $parent->id;
            $profile['tags'][] = 'Training Family';

            // Training bookings
            $bt = CC_DB::bookings();
            $bks = $wpdb->get_results($wpdb->prepare("SELECT * FROM $bt WHERE parent_id=%d ORDER BY created_at DESC", $parent->id));
            foreach ($bks ?: [] as $b) {
                $trainer = '';
                if (!empty($b->trainer_id)) {
                    $trainer = $wpdb->get_var($wpdb->prepare("SELECT display_name FROM " . CC_DB::trainers() . " WHERE id=%d", $b->trainer_id));
                }
                $timeline[] = [
                    'type' => 'training_booking', 'date' => $b->created_at,
                    'title' => 'Training Booking' . ($b->session_date ? ': ' . $b->session_date : ''),
                    'detail' => ($trainer ? 'Coach: ' . $trainer . ' | ' : '') . '$' . ($b->total_amount ?? 0),
                    'meta' => ['amount' => $b->total_amount ?? 0, 'status' => $b->status ?? ''],
                ];
            }

            // Players
            $pl = CC_DB::players();
            $players = $wpdb->get_results($wpdb->prepare("SELECT * FROM $pl WHERE parent_id=%d", $parent->id));
            $profile['players'] = $players ?: [];
        }

        // ── 3. Camp bookings ──
        $cb = CC_DB::camp_bookings();
        if ($this->camp_table_ok($cb)) {
            $email_for_camp = $profile['email'] ?: ($is_email ? $key : '');
            if ($email_for_camp) {
                $camp_bks = $wpdb->get_results($wpdb->prepare(
                    "SELECT b.*, p.post_title as camp_title FROM $cb b LEFT JOIN {$wpdb->posts} p ON b.camp_id=p.ID WHERE LOWER(b.customer_email)=LOWER(%s) ORDER BY b.created_at DESC",
                    $email_for_camp
                ));
            } elseif ($phone_suffix) {
                $camp_bks = $wpdb->get_results($wpdb->prepare(
                    "SELECT b.*, p.post_title as camp_title FROM $cb b LEFT JOIN {$wpdb->posts} p ON b.camp_id=p.ID WHERE b.customer_phone LIKE %s ORDER BY b.created_at DESC",
                    '%'.$phone_suffix
                ));
            } else {
                $camp_bks = [];
            }
            foreach ($camp_bks ?: [] as $b) {
                if (!$profile['name'] && $b->customer_name) $profile['name'] = $b->customer_name;
                if (!$profile['email'] && $b->customer_email) $profile['email'] = $b->customer_email;
                if (!$profile['phone'] && $b->customer_phone) $profile['phone'] = $b->customer_phone;
                $profile['tags'][] = 'Camp Customer';
                $detail = ($b->camp_title ?: 'Camp #' . $b->camp_id) . ' | $' . $b->amount_paid;
                if ($b->camper_name) $detail .= ' | Camper: ' . $b->camper_name;
                $timeline[] = [
                    'type' => 'camp_booking', 'date' => $b->created_at,
                    'title' => 'Camp Purchase: ' . ($b->camp_title ?: 'Camp'),
                    'detail' => $detail,
                    'meta' => [
                        'amount' => $b->amount_paid, 'status' => $b->status,
                        'camper' => $b->camper_name ?? '', 'camp_id' => $b->camp_id,
                        'coupon' => $b->coupon_code ?? '', 'referral' => $b->referral_code ?? '',
                        'utm_source' => $b->utm_source ?? '', 'how_found' => $b->how_found_us ?? '',
                    ],
                ];
            }
            $profile['tags'] = array_unique($profile['tags']);
        }

        // ── 4. SMS messages ──
        $op = CC_DB::op_msgs();
        if ($phone_suffix || (!$is_email && $key)) {
            $phone_like = '%' . ($phone_suffix ?: $key);
            // Detect schema: CC standalone uses 'phone', TP may use from_number/to_number
            $op_cols = $wpdb->get_col("DESCRIBE $op", 0);
            if (in_array('from_number', $op_cols)) {
                $msgs = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM $op WHERE from_number LIKE %s OR to_number LIKE %s ORDER BY created_at DESC LIMIT 50",
                    $phone_like, $phone_like
                ));
            } else {
                $msgs = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM $op WHERE phone LIKE %s ORDER BY created_at DESC LIMIT 50",
                    $phone_like
                ));
            }
            foreach ($msgs ?: [] as $m) {
                $dir = $m->direction ?? 'outgoing';
                $timeline[] = [
                    'type' => 'sms_' . $dir, 'date' => $m->created_at,
                    'title' => ($dir === 'incoming' ? 'SMS Received' : 'SMS Sent'),
                    'detail' => mb_substr($m->body ?? '', 0, 120),
                    'meta' => ['direction' => $dir],
                ];
            }
        }
        // Also match by email-linked phone from parent record
        if ($is_email && $profile['phone']) {
            $ps2 = substr(preg_replace('/\D/', '', $profile['phone']), -10);
            if ($ps2 && $ps2 !== $phone_suffix) {
                if (!isset($op_cols)) $op_cols = $wpdb->get_col("DESCRIBE $op", 0);
                if (in_array('from_number', $op_cols)) {
                    $msgs2 = $wpdb->get_results($wpdb->prepare(
                        "SELECT * FROM $op WHERE from_number LIKE %s OR to_number LIKE %s ORDER BY created_at DESC LIMIT 50",
                        '%'.$ps2, '%'.$ps2
                    ));
                } else {
                    $msgs2 = $wpdb->get_results($wpdb->prepare(
                        "SELECT * FROM $op WHERE phone LIKE %s ORDER BY created_at DESC LIMIT 50",
                        '%'.$ps2
                    ));
                }
                foreach ($msgs2 ?: [] as $m) {
                    $dir = $m->direction ?? 'outgoing';
                    $timeline[] = [
                        'type' => 'sms_' . $dir, 'date' => $m->created_at,
                        'title' => ($dir === 'incoming' ? 'SMS Received' : 'SMS Sent'),
                        'detail' => mb_substr($m->body ?? '', 0, 120),
                        'meta' => ['direction' => $dir],
                    ];
                }
            }
        }

        // ── 5. Call log ──
        $cl = $wpdb->prefix . 'ptp_cc_call_log';
        if ($wpdb->get_var("SHOW TABLES LIKE '$cl'") === $cl) {
            $phone_for_calls = $phone_suffix ?: substr(preg_replace('/\D/', '', $profile['phone'] ?? ''), -10);
            if ($phone_for_calls) {
                $calls = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM $cl WHERE from_number LIKE %s OR to_number LIKE %s ORDER BY created_at DESC LIMIT 30",
                    '%'.$phone_for_calls, '%'.$phone_for_calls
                ));
                foreach ($calls ?: [] as $c) {
                    $dur = $c->duration_seconds > 0 ? ' (' . floor($c->duration_seconds / 60) . 'm ' . ($c->duration_seconds % 60) . 's)' : '';
                    $timeline[] = [
                        'type' => 'call_' . $c->direction, 'date' => $c->created_at,
                        'title' => ucfirst($c->direction) . ' Call' . $dur,
                        'detail' => $c->notes ?: ($c->status ?? 'completed'),
                        'meta' => ['duration' => $c->duration_seconds, 'direction' => $c->direction, 'status' => $c->status],
                    ];
                }
            }
        }

        // ── 6. Scheduled calls ──
        $sc = $wpdb->prefix . 'ptp_cc_scheduled_calls';
        if ($wpdb->get_var("SHOW TABLES LIKE '$sc'") === $sc) {
            $phone_for_sched = $phone_suffix ?: substr(preg_replace('/\D/', '', $profile['phone'] ?? ''), -10);
            $email_for_sched = $profile['email'] ?: ($is_email ? $key : '');
            $where_parts = [];
            $params = [];
            if ($phone_for_sched) { $where_parts[] = "contact_phone LIKE %s"; $params[] = '%'.$phone_for_sched; }
            if ($email_for_sched) { $where_parts[] = "contact_email=%s"; $params[] = $email_for_sched; }
            if ($where_parts) {
                $sched = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM $sc WHERE " . implode(' OR ', $where_parts) . " ORDER BY scheduled_at DESC LIMIT 20",
                    ...$params
                ));
                foreach ($sched ?: [] as $s) {
                    $timeline[] = [
                        'type' => 'scheduled_call', 'date' => $s->scheduled_at,
                        'title' => 'Scheduled Call: ' . ($s->call_type ?: 'follow_up') . ' (' . $s->status . ')',
                        'detail' => $s->notes ?: ($s->outcome_notes ?? ''),
                        'meta' => ['status' => $s->status, 'outcome' => $s->outcome ?? '', 'call_type' => $s->call_type],
                    ];
                }
            }
        }

        // ── 7. Abandoned carts ──
        $ac = CC_DB::camp_abandoned();
        if ($this->camp_table_ok($ac) && ($profile['email'] || ($is_email && $key))) {
            $ab_email = $profile['email'] ?: $key;
            $abcarts = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $ac WHERE LOWER(email)=LOWER(%s) ORDER BY created_at DESC", $ab_email
            ));
            foreach ($abcarts ?: [] as $ab) {
                $timeline[] = [
                    'type' => 'abandoned_cart', 'date' => $ab->created_at,
                    'title' => 'Abandoned Cart ($' . $ab->cart_total . ')',
                    'detail' => ($ab->camp_names ?: 'Unknown camps') . ' | Status: ' . $ab->status . ' | Emails sent: ' . $ab->emails_sent,
                    'meta' => ['amount' => $ab->cart_total, 'status' => $ab->status, 'recovered' => $ab->recovered_at],
                ];
            }
        }

        // ── 8. Stripe imported payments ──
        $sp_table = $wpdb->prefix . 'ptp_stripe_payments';
        if ($wpdb->get_var("SHOW TABLES LIKE '$sp_table'") === $sp_table && ($profile['email'] || ($is_email && $key))) {
            $sp_email = $profile['email'] ?: $key;
            $stripe_payments = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $sp_table WHERE LOWER(customer_email)=LOWER(%s) ORDER BY created_at_utc DESC", $sp_email
            ));
            foreach ($stripe_payments ?: [] as $sp) {
                // Skip duplicates if we already have the camp/training booking from native tables
                $type_label = $sp->has_camps ? 'Camp' : 'Training';
                $status_label = $sp->status === 'paid' ? 'Paid' : ($sp->status === 'incomplete' ? 'Incomplete' : ucfirst($sp->status));
                $timeline[] = [
                    'type' => 'stripe_payment', 'date' => $sp->created_at_utc,
                    'title' => "Stripe {$type_label}: {$status_label} (\${$sp->amount})",
                    'detail' => $sp->description,
                    'meta' => [
                        'amount' => (float)$sp->amount,
                        'status' => $sp->status,
                        'has_camps' => (bool)$sp->has_camps,
                        'has_training' => (bool)$sp->has_training,
                        'camp_ids' => $sp->camp_ids,
                        'stripe_id' => $sp->stripe_charge_id,
                        'matched_cart' => $sp->matched_abandoned_cart_id,
                    ],
                ];
                // Fill profile from Stripe data if missing
                if (!$profile['name'] && $sp->customer_name) $profile['name'] = $sp->customer_name;
                if (!$profile['phone'] && $sp->customer_phone) $profile['phone'] = $sp->customer_phone;
            }
        }

        // ── Sort timeline by date descending ──
        usort($timeline, function ($a, $b) {
            return strcmp($b['date'] ?? '', $a['date'] ?? '');
        });

        // ── Compute LTV (includes Stripe imported paid amounts) ──
        $training_ltv = 0;
        $camp_ltv = 0;
        $stripe_seen = []; // deduplicate: if native booking + stripe import exist, only count once
        foreach ($timeline as $e) {
            if ($e['type'] === 'training_booking' && !empty($e['meta']['amount'])) $training_ltv += (float)$e['meta']['amount'];
            if ($e['type'] === 'camp_booking' && !empty($e['meta']['amount']) && ($e['meta']['status'] ?? '') !== 'refunded') $camp_ltv += (float)$e['meta']['amount'];
            // Only count Stripe payments if no native booking covers them
            if ($e['type'] === 'stripe_payment' && ($e['meta']['status'] ?? '') === 'paid') {
                $sid = $e['meta']['stripe_id'] ?? '';
                if ($sid && isset($stripe_seen[$sid])) continue;
                $stripe_seen[$sid] = true;
                // Only add to LTV if we don't have native booking data (avoid double-counting)
                // Heuristic: if there are 0 native bookings of this type, count stripe data
                if ($e['meta']['has_camps'] && $camp_ltv == 0) $camp_ltv += (float)($e['meta']['amount'] ?? 0);
                elseif ($e['meta']['has_training'] && $training_ltv == 0) $training_ltv += (float)($e['meta']['amount'] ?? 0);
            }
        }

        $profile['training_ltv'] = $training_ltv;
        $profile['camp_ltv'] = $camp_ltv;
        $profile['total_ltv'] = $training_ltv + $camp_ltv;
        $profile['first_seen'] = !empty($timeline) ? end($timeline)['date'] : '';
        $profile['touchpoints'] = count($timeline);
        $profile['timeline_count'] = count($timeline);

        // Attribution data (acquisition channel, CAC, campaign)
        $profile['attribution'] = null;
        if (class_exists('CC_Attribution') && $profile['email']) {
            $profile['attribution'] = CC_Attribution::get_customer_attribution_data($profile['email']);
        }

        $result = ['profile' => $profile, 'timeline' => $timeline];
        set_transient($cache_key, $result, 300); // 5-minute cache
        return $result;
    }

    // ═══════════════════════════════════════
    // FINANCE — Expenses, P&L, Budgets
    // ═══════════════════════════════════════

    public function register_finance_routes() {
        // Expenses CRUD
        register_rest_route($this->ns, '/finance/expenses', [
            ['methods' => 'GET', 'callback' => [$this, 'get_expenses'], 'permission_callback' => [$this, 'is_admin']],
            ['methods' => 'POST', 'callback' => [$this, 'create_expense'], 'permission_callback' => [$this, 'is_admin']],
        ]);
        register_rest_route($this->ns, '/finance/expenses/(?P<id>\d+)', [
            ['methods' => 'PATCH', 'callback' => [$this, 'update_expense'], 'permission_callback' => [$this, 'is_admin']],
            ['methods' => 'DELETE', 'callback' => [$this, 'delete_expense'], 'permission_callback' => [$this, 'is_admin']],
        ]);
        // P&L / Summary
        register_rest_route($this->ns, '/finance/summary', [
            'methods' => 'GET', 'callback' => [$this, 'get_finance_summary'], 'permission_callback' => [$this, 'is_admin'],
        ]);
        // Monthly breakdown
        register_rest_route($this->ns, '/finance/monthly', [
            'methods' => 'GET', 'callback' => [$this, 'get_finance_monthly'], 'permission_callback' => [$this, 'is_admin'],
        ]);
        register_rest_route($this->ns, '/openphone/settings', [
            'methods' => 'GET', 'callback' => [$this, 'get_openphone_settings'], 'permission_callback' => [$this, 'is_admin'],
        ]);
        register_rest_route($this->ns, '/openphone/settings', [
            'methods' => 'POST', 'callback' => [$this, 'save_openphone_settings'], 'permission_callback' => [$this, 'is_admin'],
        ]);
    }

    // ── Expense CRUD ──

    public function get_expenses($req) {
        global $wpdb;
        $t = CC_DB::expenses();
        $cat = $req->get_param('category');
        $from = $req->get_param('from');
        $to = $req->get_param('to');
        $limit = min((int)($req->get_param('limit') ?: 200), 500);

        $where = ['1=1'];
        $params = [];
        if ($cat) { $where[] = 'category=%s'; $params[] = $cat; }
        if ($from) { $where[] = 'expense_date>=%s'; $params[] = $from; }
        if ($to) { $where[] = 'expense_date<=%s'; $params[] = $to; }

        $sql = "SELECT * FROM $t WHERE " . implode(' AND ', $where) . " ORDER BY expense_date DESC LIMIT %d";
        $params[] = $limit;
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));

        // Totals
        $sql2 = "SELECT COALESCE(SUM(amount),0) as total, COUNT(*) as cnt FROM $t WHERE " . implode(' AND ', $where);
        $agg = $params ? $wpdb->get_row($wpdb->prepare($sql2, ...$params)) : $wpdb->get_row($sql2);

        // By category
        $sql3 = "SELECT category, COALESCE(SUM(amount),0) as total, COUNT(*) as cnt FROM $t WHERE " . implode(' AND ', $where) . " GROUP BY category ORDER BY total DESC";
        $by_cat = $params ? $wpdb->get_results($wpdb->prepare($sql3, ...$params)) : $wpdb->get_results($sql3);

        return [
            'expenses'    => $rows ?: [],
            'total'       => (float)($agg->total ?? 0),
            'count'       => (int)($agg->cnt ?? 0),
            'by_category' => $by_cat ?: [],
        ];
    }

    public function create_expense($req) {
        global $wpdb;
        $b = $req->get_json_params();

        $data = [
            'category'       => sanitize_text_field($b['category'] ?? 'other'),
            'subcategory'    => sanitize_text_field($b['subcategory'] ?? ''),
            'description'    => sanitize_text_field($b['description'] ?? ''),
            'amount'         => round((float)($b['amount'] ?? 0), 2),
            'expense_date'   => sanitize_text_field($b['expense_date'] ?? date('Y-m-d')),
            'vendor'         => sanitize_text_field($b['vendor'] ?? ''),
            'payment_method' => sanitize_text_field($b['payment_method'] ?? ''),
            'receipt_url'    => esc_url_raw($b['receipt_url'] ?? ''),
            'is_recurring'   => (int)(!empty($b['is_recurring'])),
            'recur_interval' => sanitize_text_field($b['recur_interval'] ?? ''),
            'tags'           => sanitize_text_field($b['tags'] ?? ''),
            'notes'          => sanitize_textarea_field($b['notes'] ?? ''),
            'created_by'     => get_current_user_id(),
        ];

        if ($data['amount'] <= 0) return new WP_Error('invalid', 'Amount must be > 0', ['status' => 400]);

        $wpdb->insert(CC_DB::expenses(), $data);
        return ['id' => $wpdb->insert_id, 'created' => true];
    }

    public function update_expense($req) {
        global $wpdb;
        $id = (int)$req['id'];
        $b = $req->get_json_params();
        $data = [];
        $allowed = ['category','subcategory','description','amount','expense_date','vendor','payment_method','receipt_url','is_recurring','recur_interval','tags','notes'];
        foreach ($allowed as $f) {
            if (isset($b[$f])) {
                $data[$f] = $f === 'amount' ? round((float)$b[$f], 2) :
                    ($f === 'is_recurring' ? (int)$b[$f] :
                    ($f === 'notes' ? sanitize_textarea_field($b[$f]) : sanitize_text_field($b[$f])));
            }
        }
        if ($data) $wpdb->update(CC_DB::expenses(), $data, ['id' => $id]);
        return ['updated' => true];
    }

    public function delete_expense($req) {
        global $wpdb;
        $wpdb->delete(CC_DB::expenses(), ['id' => (int)$req['id']]);
        return ['deleted' => true];
    }

    // ── Finance Summary (P&L) ──

    public function get_finance_summary($req) {
        global $wpdb;
        $year = $req->get_param('year') ?: date('Y');
        $from = "$year-01-01";
        $to = "$year-12-31";

        // === REVENUE ===
        $training_rev = 0;
        $camp_rev = 0;

        // Training bookings
        $bt = CC_DB::bookings();
        if ($wpdb->get_var("SHOW TABLES LIKE '$bt'") === $bt) {
            $training_rev = (float)$wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(total_amount),0) FROM $bt WHERE created_at BETWEEN %s AND %s",
                "$from 00:00:00", "$to 23:59:59"
            ));
        }

        // Camp bookings (primary source)
        $cb = CC_DB::camp_bookings();
        if ($this->camp_table_ok($cb)) {
            $camp_rev = (float)$wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount_paid),0) FROM $cb WHERE status='confirmed' AND created_at BETWEEN %s AND %s",
                "$from 00:00:00", "$to 23:59:59"
            ));
        }
        // Fallback to unified camp orders
        if (!$camp_rev) {
            $co = CC_DB::camp_orders();
            if ($this->camp_table_ok($co)) {
                $camp_rev = (float)$wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(total_amount),0) FROM $co WHERE payment_status='completed' AND created_at BETWEEN %s AND %s",
                    "$from 00:00:00", "$to 23:59:59"
                ));
            }
        }

        $total_rev = $training_rev + $camp_rev;

        // === EXPENSES ===
        $et = CC_DB::expenses();
        $total_exp = 0;
        $exp_by_cat = [];
        if ($wpdb->get_var("SHOW TABLES LIKE '$et'") === $et) {
            $total_exp = (float)$wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount),0) FROM $et WHERE expense_date BETWEEN %s AND %s", $from, $to
            ));
            $exp_by_cat = $wpdb->get_results($wpdb->prepare(
                "SELECT category, COALESCE(SUM(amount),0) as total, COUNT(*) as cnt FROM $et WHERE expense_date BETWEEN %s AND %s GROUP BY category ORDER BY total DESC",
                $from, $to
            )) ?: [];
        }

        // === MONTHLY BREAKDOWN (optimized: GROUP BY instead of 12 loops) ===
        $months = [];
        $monthly_training = [];
        $monthly_camp = [];
        $monthly_exp = [];

        // Training by month
        if ($wpdb->get_var("SHOW TABLES LIKE '$bt'") === $bt) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT MONTH(created_at) as mo, COALESCE(SUM(total_amount),0) as rev
                 FROM $bt WHERE created_at BETWEEN %s AND %s GROUP BY MONTH(created_at)",
                "$from 00:00:00", "$to 23:59:59"
            ));
            foreach ($rows ?: [] as $r) $monthly_training[(int)$r->mo] = (float)$r->rev;
        }

        // Camp by month
        if ($this->camp_table_ok($cb)) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT MONTH(created_at) as mo, COALESCE(SUM(amount_paid),0) as rev
                 FROM $cb WHERE status='confirmed' AND created_at BETWEEN %s AND %s GROUP BY MONTH(created_at)",
                "$from 00:00:00", "$to 23:59:59"
            ));
            foreach ($rows ?: [] as $r) $monthly_camp[(int)$r->mo] = (float)$r->rev;
        }
        // Fallback: unified camp orders (only if primary had no data)
        if (empty($monthly_camp)) {
            $co_t = CC_DB::camp_orders();
            if ($this->camp_table_ok($co_t)) {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT MONTH(created_at) as mo, COALESCE(SUM(total_amount),0) as rev
                     FROM $co_t WHERE payment_status='completed' AND created_at BETWEEN %s AND %s GROUP BY MONTH(created_at)",
                    "$from 00:00:00", "$to 23:59:59"
                ));
                foreach ($rows ?: [] as $r) $monthly_camp[(int)$r->mo] = (float)$r->rev;
            }
        }

        // Expenses by month
        if ($wpdb->get_var("SHOW TABLES LIKE '$et'") === $et) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT MONTH(expense_date) as mo, COALESCE(SUM(amount),0) as total
                 FROM $et WHERE expense_date BETWEEN %s AND %s GROUP BY MONTH(expense_date)",
                $from, $to
            ));
            foreach ($rows ?: [] as $r) $monthly_exp[(int)$r->mo] = (float)$r->total;
        }

        for ($m = 1; $m <= 12; $m++) {
            $mo = str_pad($m, 2, '0', STR_PAD_LEFT);
            $mfrom = "$year-$mo-01";
            $m_training = $monthly_training[$m] ?? 0;
            $m_camp = $monthly_camp[$m] ?? 0;
            $m_exp = $monthly_exp[$m] ?? 0;

            $months[] = [
                'month'    => $mo,
                'label'    => date('M', strtotime($mfrom)),
                'training' => $m_training,
                'camps'    => $m_camp,
                'revenue'  => $m_training + $m_camp,
                'expenses' => $m_exp,
                'profit'   => ($m_training + $m_camp) - $m_exp,
            ];
        }

        return [
            'year'         => $year,
            'training_rev' => $training_rev,
            'camp_rev'     => $camp_rev,
            'total_rev'    => $total_rev,
            'total_exp'    => $total_exp,
            'net_profit'   => $total_rev - $total_exp,
            'margin'       => $total_rev > 0 ? round(($total_rev - $total_exp) / $total_rev * 100, 1) : 0,
            'exp_by_cat'   => $exp_by_cat,
            'months'       => $months,
        ];
    }

    public function get_finance_monthly($req) {
        // Alias — summary already includes monthly
        return $this->get_finance_summary($req);
    }

    // ── OpenPhone Settings ──

    public function get_openphone_settings() {
        $key = get_option('ptp_openphone_api_key', '') ?: get_option('ptp_cc_openphone_api_key', '');
        $phone = get_option('ptp_openphone_from', '') ?: get_option('ptp_cc_openphone_phone_id', '');
        // Test connection if key exists
        $connected = false;
        $account_name = '';
        if ($key) {
            $r = wp_remote_get('https://api.openphone.com/v1/phone-numbers', [
                'headers' => ['Authorization' => $key, 'Content-Type' => 'application/json'],
                'timeout' => 10,
            ]);
            if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
                $connected = true;
                $data = json_decode(wp_remote_retrieve_body($r), true);
                $nums = $data['data'] ?? [];
                if (!empty($nums[0]['formattedNumber'])) {
                    $account_name = $nums[0]['formattedNumber'];
                }
            }
        }
        return [
            'has_key'      => !empty($key),
            'phone'        => $phone,
            'connected'    => $connected,
            'account_name' => $account_name,
            'source'       => $key ? (get_option('ptp_openphone_api_key') ? 'training_platform' : 'command_center') : '',
        ];
    }

    public function save_openphone_settings($req) {
        $body = $req->get_json_params();
        $key = sanitize_text_field($body['api_key'] ?? '');
        $phone = sanitize_text_field($body['phone'] ?? '');

        if ($key) {
            // Validate key first
            $r = wp_remote_get('https://api.openphone.com/v1/phone-numbers', [
                'headers' => ['Authorization' => $key, 'Content-Type' => 'application/json'],
                'timeout' => 10,
            ]);
            if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) {
                return new \WP_Error('invalid_key', 'OpenPhone API key is invalid. Check your key and try again.', ['status' => 400]);
            }
            update_option('ptp_cc_openphone_api_key', $key);
            // Also set TP's option so both plugins share the key
            if (!get_option('ptp_openphone_api_key')) {
                update_option('ptp_openphone_api_key', $key);
            }

            // Auto-detect phone number if not provided
            if (!$phone) {
                $data = json_decode(wp_remote_retrieve_body($r), true);
                $nums = $data['data'] ?? [];
                if (!empty($nums[0]['number'])) {
                    $phone = $nums[0]['number'];
                }
            }
        }

        if ($phone) {
            update_option('ptp_cc_openphone_phone_id', $phone);
            if (!get_option('ptp_openphone_from')) {
                update_option('ptp_openphone_from', $phone);
            }
        }

        // Clear cached phoneNumberId so it re-resolves
        delete_transient('ptp_cc_op_phone_id');
        delete_transient('ptp_op_phone_id');

        return $this->get_openphone_settings();
    }
}
