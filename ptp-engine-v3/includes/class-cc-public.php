<?php
/**
 * PTP Command Center — Public Booking System
 * Shareable training links, public booking page, Stripe checkout
 * Shortcode: [ptp_book] or auto-route via /book/{code}
 */
if (!defined('ABSPATH')) exit;

class CC_Public {

    public static function init() {
        add_shortcode('ptp_book', [__CLASS__, 'render_booking_page']);
        add_shortcode('ptp_cc_book', [__CLASS__, 'render_booking_page']);

        // Pretty URL: /book/{code}
        add_action('template_redirect', [__CLASS__, 'handle_book_route']);
    }

    public static function register_routes() {
        // Public booking API (no auth required)
        register_rest_route('ptp-cc/v1', '/public/link/(?P<code>[a-zA-Z0-9_-]+)', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_get_link'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('ptp-cc/v1', '/public/book', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'api_submit_booking'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('ptp-cc/v1', '/public/trainers', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'api_get_trainers'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Handle /book/{code} pretty URL
     */
    public static function handle_book_route() {
        // Support subdirectory installs: compare relative to home_url path
        $home_path = rtrim(parse_url(home_url(), PHP_URL_PATH) ?: '', '/');
        $request_path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
        $relative = $home_path ? ltrim(substr($request_path, strlen($home_path)), '/') : ltrim($request_path, '/');
        if (!preg_match('#^book/([a-zA-Z0-9_-]+)$#', $relative, $m)) return;

        $code = sanitize_text_field($m[1]);
        global $wpdb;
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . CC_DB::training_links() . " WHERE code=%s AND status='active'", $code
        ));

        if (!$link) {
            wp_die('This training link is no longer available.', 'PTP', ['response' => 404]);
        }

        self::render_full_page($link);
        exit;
    }

    /**
     * Shortcode: [ptp_book code="abc123"]
     */
    public static function render_booking_page($atts = []) {
        $atts = shortcode_atts(['code' => ''], $atts);
        $code = $atts['code'] ?: ($_GET['code'] ?? '');

        if (!$code) {
            return '<div style="text-align:center;padding:40px;font-family:Inter,sans-serif;color:#555">No training link specified.</div>';
        }

        global $wpdb;
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . CC_DB::training_links() . " WHERE code=%s AND status='active'", $code
        ));

        if (!$link) {
            return '<div style="text-align:center;padding:40px;font-family:Inter,sans-serif;color:#E53935">This training link has expired or is no longer available.</div>';
        }

        $trainer = null;
        if ($link->trainer_id) {
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT display_name, photo_url, bio, hourly_rate, location, average_rating, review_count FROM " . CC_DB::trainers() . " WHERE id=%d",
                $link->trainer_id
            ));
        }

        ob_start();
        self::output_booking_form($link, $trainer);
        return ob_get_clean();
    }

    /**
     * Full standalone page (for /book/{code} route)
     */
    private static function render_full_page($link) {
        global $wpdb;
        $trainer = null;
        if ($link->trainer_id) {
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT display_name, photo_url, bio, hourly_rate, location, average_rating, review_count FROM " . CC_DB::trainers() . " WHERE id=%d",
                $link->trainer_id
            ));
        }
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($link->title); ?> - PTP Training</title>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',system-ui,sans-serif;background:#FAFAFA;color:#1A1A1A;-webkit-font-smoothing:antialiased}
        .ptp-book-wrap{max-width:600px;margin:0 auto;padding:20px}
        .ptp-header{text-align:center;padding:30px 0 20px}
        .ptp-logo{display:inline-flex;align-items:center;gap:10px;margin-bottom:16px}
        .ptp-logo-box{width:40px;height:40px;background:#FCB900;display:flex;align-items:center;justify-content:center;font-family:'Oswald',sans-serif;font-weight:800;font-size:14px}
        .ptp-logo-text{font-family:'Oswald',sans-serif;font-weight:700;font-size:20px;text-transform:uppercase;line-height:1}
        .ptp-logo-gold{color:#FCB900}
    </style>
</head>
<body>
    <div class="ptp-book-wrap">
        <div class="ptp-header">
            <div class="ptp-logo">
                <div class="ptp-logo-box">PTP</div>
                <div>
                    <div class="ptp-logo-text">Players Teaching</div>
                    <div class="ptp-logo-text ptp-logo-gold">Players</div>
                </div>
            </div>
        </div>
        <?php self::output_booking_form($link, $trainer); ?>
    </div>
    <script>
    <?php self::output_booking_js(); ?>
    </script>
</body>
</html>
        <?php
    }

    /**
     * Output the booking form HTML
     */
    private static function output_booking_form($link, $trainer) {
        $api = rest_url('ptp-cc/v1/public');
        $types = ['1on1' => '1-on-1 Training', 'small_group' => 'Small Group', 'team' => 'Team Session', 'camp' => 'Camp', 'free_session' => 'Free Session'];
        $type_label = $types[$link->session_type] ?? $link->session_type;
        ?>
        <div id="ptp-booking-root" data-code="<?php echo esc_attr($link->code); ?>" data-api="<?php echo esc_attr($api); ?>">
        <div style="background:#fff;border:1px solid #E8E8E8;border-radius:6px;overflow:hidden;margin-bottom:16px">
            <?php if ($trainer && $trainer->photo_url): ?>
            <div style="height:180px;background:url('<?php echo esc_url($trainer->photo_url); ?>') center/cover;position:relative">
                <div style="position:absolute;bottom:0;left:0;right:0;background:linear-gradient(transparent,rgba(0,0,0,.7));padding:16px">
                    <div style="color:#fff;font-family:'Oswald',sans-serif;font-size:18px;font-weight:700;text-transform:uppercase"><?php echo esc_html($trainer->display_name); ?></div>
                    <?php if ($trainer->average_rating > 0): ?>
                    <div style="color:#FCB900;font-size:12px;font-weight:600"><?php echo number_format($trainer->average_rating, 1); ?> stars (<?php echo (int)$trainer->review_count; ?> reviews)</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <div style="padding:20px">
                <div style="font-family:'Oswald',sans-serif;font-size:20px;font-weight:700;text-transform:uppercase;margin-bottom:4px"><?php echo esc_html($link->title); ?></div>
                <?php if ($link->description): ?>
                <div style="font-size:13px;color:#555;margin-bottom:12px;line-height:1.5"><?php echo esc_html($link->description); ?></div>
                <?php endif; ?>
                <div style="display:flex;gap:12px;flex-wrap:wrap">
                    <div style="background:#FFF8E1;padding:6px 12px;border-radius:4px;font-size:12px;font-weight:600"><span style="color:#FCB900">$<?php echo number_format($link->price, 0); ?></span> / session</div>
                    <div style="background:#F5F5F5;padding:6px 12px;border-radius:4px;font-size:12px;color:#555"><?php echo (int)$link->duration_minutes; ?> min</div>
                    <div style="background:#F5F5F5;padding:6px 12px;border-radius:4px;font-size:12px;color:#555"><?php echo esc_html($type_label); ?></div>
                    <?php if ($link->location): ?>
                    <div style="background:#F5F5F5;padding:6px 12px;border-radius:4px;font-size:12px;color:#555"><?php echo esc_html($link->location); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Booking Form -->
        <div style="background:#fff;border:1px solid #E8E8E8;border-radius:6px;padding:20px">
            <div style="font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;text-transform:uppercase;margin-bottom:16px">Book Your Session</div>
            <form id="ptp-book-form" onsubmit="return ptpSubmitBooking(event)">
                <input type="hidden" name="link_code" value="<?php echo esc_attr($link->code); ?>">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
                    <div>
                        <label style="font-size:9px;font-weight:700;color:#999;text-transform:uppercase;font-family:'Oswald',sans-serif;display:block;margin-bottom:4px">Parent Name *</label>
                        <input type="text" name="parent_name" required style="width:100%;padding:10px 12px;border:1px solid #E8E8E8;border-radius:4px;font-size:13px;font-family:'Inter',sans-serif;background:#F5F5F5">
                    </div>
                    <div>
                        <label style="font-size:9px;font-weight:700;color:#999;text-transform:uppercase;font-family:'Oswald',sans-serif;display:block;margin-bottom:4px">Player Name *</label>
                        <input type="text" name="child_name" required style="width:100%;padding:10px 12px;border:1px solid #E8E8E8;border-radius:4px;font-size:13px;font-family:'Inter',sans-serif;background:#F5F5F5">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
                    <div>
                        <label style="font-size:9px;font-weight:700;color:#999;text-transform:uppercase;font-family:'Oswald',sans-serif;display:block;margin-bottom:4px">Email *</label>
                        <input type="email" name="email" required style="width:100%;padding:10px 12px;border:1px solid #E8E8E8;border-radius:4px;font-size:13px;font-family:'Inter',sans-serif;background:#F5F5F5">
                    </div>
                    <div>
                        <label style="font-size:9px;font-weight:700;color:#999;text-transform:uppercase;font-family:'Oswald',sans-serif;display:block;margin-bottom:4px">Phone *</label>
                        <input type="tel" name="phone" required style="width:100%;padding:10px 12px;border:1px solid #E8E8E8;border-radius:4px;font-size:13px;font-family:'Inter',sans-serif;background:#F5F5F5">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
                    <div>
                        <label style="font-size:9px;font-weight:700;color:#999;text-transform:uppercase;font-family:'Oswald',sans-serif;display:block;margin-bottom:4px">Player Age</label>
                        <input type="text" name="child_age" style="width:100%;padding:10px 12px;border:1px solid #E8E8E8;border-radius:4px;font-size:13px;font-family:'Inter',sans-serif;background:#F5F5F5">
                    </div>
                    <div>
                        <label style="font-size:9px;font-weight:700;color:#999;text-transform:uppercase;font-family:'Oswald',sans-serif;display:block;margin-bottom:4px">Preferred Date *</label>
                        <input type="date" name="session_date" required min="<?php echo date('Y-m-d'); ?>" style="width:100%;padding:10px 12px;border:1px solid #E8E8E8;border-radius:4px;font-size:13px;font-family:'Inter',sans-serif;background:#F5F5F5">
                    </div>
                </div>
                <div style="margin-bottom:12px">
                    <label style="font-size:9px;font-weight:700;color:#999;text-transform:uppercase;font-family:'Oswald',sans-serif;display:block;margin-bottom:4px">Preferred Time</label>
                    <select name="session_time" style="width:100%;padding:10px 12px;border:1px solid #E8E8E8;border-radius:4px;font-size:13px;font-family:'Inter',sans-serif;background:#F5F5F5">
                        <option value="">Select a time...</option>
                        <option value="9:00 AM">9:00 AM</option><option value="10:00 AM">10:00 AM</option>
                        <option value="11:00 AM">11:00 AM</option><option value="12:00 PM">12:00 PM</option>
                        <option value="1:00 PM">1:00 PM</option><option value="2:00 PM">2:00 PM</option>
                        <option value="3:00 PM">3:00 PM</option><option value="4:00 PM">4:00 PM</option>
                        <option value="5:00 PM">5:00 PM</option><option value="6:00 PM">6:00 PM</option>
                        <option value="7:00 PM">7:00 PM</option><option value="8:00 PM">8:00 PM</option>
                    </select>
                </div>
                <div style="margin-bottom:16px">
                    <label style="font-size:9px;font-weight:700;color:#999;text-transform:uppercase;font-family:'Oswald',sans-serif;display:block;margin-bottom:4px">Notes (optional)</label>
                    <textarea name="notes" rows="2" style="width:100%;padding:10px 12px;border:1px solid #E8E8E8;border-radius:4px;font-size:13px;font-family:'Inter',sans-serif;background:#F5F5F5;resize:vertical"></textarea>
                </div>
                <button type="submit" id="ptp-book-btn" style="width:100%;padding:14px;background:#FCB900;color:#0A0A0A;border:none;font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;text-transform:uppercase;cursor:pointer;border-radius:4px;letter-spacing:.5px">Book Session<?php if ($link->price > 0) echo ' - $' . number_format($link->price, 0); ?></button>
                <div id="ptp-book-status" style="text-align:center;margin-top:12px;font-size:12px;display:none"></div>
            </form>
        </div>

        <!-- Success State (hidden) -->
        <div id="ptp-book-success" style="display:none;background:#E8F5E9;border:1px solid #A5D6A7;border-radius:6px;padding:30px;text-align:center;margin-top:16px">
            <div style="font-size:32px;margin-bottom:8px">&#10003;</div>
            <div style="font-family:'Oswald',sans-serif;font-size:18px;font-weight:700;text-transform:uppercase;color:#2E7D32;margin-bottom:8px">Booking Confirmed!</div>
            <div style="font-size:13px;color:#555;line-height:1.5">We'll reach out to confirm your session details. Check your phone for a text from PTP.</div>
        </div>
        </div>
        <?php
    }

    /**
     * Inline JS for the booking form
     */
    private static function output_booking_js() {
        ?>
        function ptpSubmitBooking(e) {
            e.preventDefault();
            var form = document.getElementById('ptp-book-form');
            var btn = document.getElementById('ptp-book-btn');
            var status = document.getElementById('ptp-book-status');
            var root = document.getElementById('ptp-booking-root');
            var api = root.getAttribute('data-api');

            btn.disabled = true;
            btn.textContent = 'SUBMITTING...';
            status.style.display = 'none';

            var data = {};
            new FormData(form).forEach(function(v, k) { data[k] = v; });

            fetch(api + '/book', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) {
                    form.style.display = 'none';
                    document.getElementById('ptp-book-success').style.display = 'block';
                    if (res.stripe_url) window.location.href = res.stripe_url;
                } else {
                    status.style.display = 'block';
                    status.style.color = '#E53935';
                    status.textContent = res.message || 'Something went wrong. Please try again.';
                    btn.disabled = false;
                    btn.textContent = 'BOOK SESSION';
                }
            })
            .catch(function() {
                status.style.display = 'block';
                status.style.color = '#E53935';
                status.textContent = 'Network error. Please try again.';
                btn.disabled = false;
                btn.textContent = 'BOOK SESSION';
            });
            return false;
        }
        <?php
    }

    // ═══ PUBLIC API ENDPOINTS ═══

    public static function api_get_link($req) {
        global $wpdb;
        $code = sanitize_text_field($req['code']);
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT l.*, t.display_name as trainer_name, t.photo_url as trainer_photo, t.bio as trainer_bio,
                    t.average_rating, t.review_count, t.location as trainer_location
             FROM " . CC_DB::training_links() . " l
             LEFT JOIN " . CC_DB::trainers() . " t ON l.trainer_id=t.id
             WHERE l.code=%s AND l.status='active'", $code
        ));

        if (!$link) return new WP_Error('404', 'Link not found or expired', ['status' => 404]);
        if ($link->expires_at && strtotime($link->expires_at) < time()) {
            return new WP_Error('expired', 'This training link has expired', ['status' => 410]);
        }
        if ($link->max_bookings > 0 && $link->total_booked >= $link->max_bookings) {
            return new WP_Error('full', 'All spots are booked', ['status' => 410]);
        }

        return ['link' => $link];
    }

    public static function api_submit_booking($req) {
        // ── Rate limiting: max 5 bookings per IP per hour ──
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $rate_key = 'ptp_book_limit_' . md5($ip);
        $count = (int)get_transient($rate_key);
        if ($count >= 5) {
            return new WP_Error('rate_limit', 'Too many booking attempts. Please try again later.', ['status' => 429]);
        }
        set_transient($rate_key, $count + 1, 3600);

        global $wpdb;
        $b = $req->get_json_params();
        $code = sanitize_text_field($b['link_code'] ?? '');

        if (!$code) return new WP_Error('missing', 'Training link code required', ['status' => 400]);

        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . CC_DB::training_links() . " WHERE code=%s AND status='active'", $code
        ));
        if (!$link) return new WP_Error('404', 'Link not found', ['status' => 404]);

        $parent_name = sanitize_text_field($b['parent_name'] ?? '');
        $email = sanitize_email($b['email'] ?? '');
        $phone = sanitize_text_field($b['phone'] ?? '');
        $child_name = sanitize_text_field($b['child_name'] ?? '');
        $child_age = sanitize_text_field($b['child_age'] ?? '');
        $session_date = sanitize_text_field($b['session_date'] ?? '');
        $session_time = sanitize_text_field($b['session_time'] ?? '');
        $notes = sanitize_textarea_field($b['notes'] ?? '');

        if (!$parent_name || !$email || !$phone || !$session_date) {
            return ['success' => false, 'message' => 'Please fill in all required fields.'];
        }

        // Find or create family
        $parent_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . CC_DB::parents() . " WHERE email=%s LIMIT 1", $email
        ));
        if (!$parent_id) {
            $wpdb->insert(CC_DB::parents(), [
                'display_name' => $parent_name,
                'email' => $email,
                'phone' => $phone,
            ]);
            $parent_id = $wpdb->insert_id;
        }

        // Create booking
        $wpdb->insert(CC_DB::bookings(), [
            'parent_id' => $parent_id,
            'trainer_id' => $link->trainer_id,
            'training_link_id' => $link->id,
            'session_date' => $session_date,
            'session_time' => $session_time,
            'duration_minutes' => $link->duration_minutes,
            'location' => $link->location,
            'total_amount' => $link->price,
            'status' => 'pending',
            'payment_status' => $link->price > 0 ? 'unpaid' : 'free',
            'notes' => $notes,
            'booked_via' => 'training_link',
        ]);
        $booking_id = $wpdb->insert_id;

        // Also create pipeline entry
        $wpdb->insert(CC_DB::apps(), [
            'parent_name' => $parent_name,
            'email' => $email,
            'phone' => $phone,
            'child_name' => $child_name,
            'child_age' => $child_age,
            'status' => $link->price > 0 ? 'booked' : 'accepted',
            'trainer_name' => $link->trainer_id ? $wpdb->get_var($wpdb->prepare(
                "SELECT display_name FROM " . CC_DB::trainers() . " WHERE id=%d", $link->trainer_id
            )) : null,
        ]);

        // Increment link bookings
        $wpdb->query($wpdb->prepare(
            "UPDATE " . CC_DB::training_links() . " SET total_booked=total_booked+1 WHERE id=%d", $link->id
        ));

        // Activity log
        CC_DB::log('booking_from_link', 'booking', $booking_id,
            "$parent_name booked via link '{$link->title}' for $child_name on $session_date", 'public');

        // Auto-SMS confirmation
        if ($phone) {
            $first = explode(' ', $parent_name)[0];
            $msg = "Hey $first! Your training session for $child_name on $session_date is confirmed. We'll text you with final details. - PTP";
            CC_DB::send_sms($phone, $msg);
        }

        return ['success' => true, 'booking_id' => $booking_id];
    }

    public static function api_get_trainers() {
        global $wpdb;
        $trainers = $wpdb->get_results(
            "SELECT id, display_name, photo_url, bio, hourly_rate, location, sport,
                    average_rating, review_count, total_sessions
             FROM " . CC_DB::trainers() . " WHERE status='approved' ORDER BY display_name"
        );
        return ['trainers' => $trainers];
    }
}
