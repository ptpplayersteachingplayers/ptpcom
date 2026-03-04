<?php
/**
 * PTP Command Center — AI Reply Engine
 *
 * Generates contextual SMS draft replies using Anthropic's Claude API.
 * All generated replies go to the drafts queue for human approval — never auto-sent.
 *
 * Flow:
 *   1. Incoming SMS arrives via OpenPhone webhook
 *   2. Rules Engine evaluates first (keyword/intent/time matches)
 *   3. If no rule matched AND AI auto-draft is enabled → this class generates a draft
 *   4. Draft appears in AI Engine tab for approve/reject
 *   5. Can also be triggered manually from Inbox or Customer 360
 *
 * @since 6.1
 */
if (!defined('ABSPATH')) exit;

class CC_AI_Engine {

    /** Anthropic API endpoint */
    const API_URL = 'https://api.anthropic.com/v1/messages';

    /** Model to use */
    const MODEL = 'claude-sonnet-4-20250514';

    /** Max tokens for reply generation */
    const MAX_TOKENS = 300;

    // ─── Settings keys ───
    const OPT_API_KEY       = 'ptp_cc_ai_api_key';
    const OPT_ENABLED       = 'ptp_cc_ai_enabled';
    const OPT_AUTO_DRAFT    = 'ptp_cc_ai_auto_draft';
    const OPT_TONE          = 'ptp_cc_ai_tone';
    const OPT_CONTEXT       = 'ptp_cc_ai_extra_context';
    const OPT_MAX_LENGTH    = 'ptp_cc_ai_max_sms_chars';
    const OPT_STATS         = 'ptp_cc_ai_stats';

    /**
     * Register REST API routes.
     */
    public static function register_routes($ns) {
        register_rest_route($ns, '/ai/generate-reply', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'api_generate_reply'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route($ns, '/ai/generate-reply-for-phone', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'api_generate_for_phone'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route($ns, '/ai/settings', [
            [
                'methods'  => 'GET',
                'callback' => [__CLASS__, 'api_get_settings'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            ],
            [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'api_save_settings'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            ],
        ]);

        register_rest_route($ns, '/ai/stats', [
            'methods'  => 'GET',
            'callback' => [__CLASS__, 'api_get_stats'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);
    }

    // ═══════════════════════════════════════
    //  PUBLIC API HANDLERS
    // ═══════════════════════════════════════

    /**
     * Generate an AI draft reply for a specific application.
     * POST /ai/generate-reply  { app_id: int, ?user_hint: string }
     */
    public static function api_generate_reply($req) {
        $b      = $req->get_json_params();
        $app_id = (int)($b['app_id'] ?? 0);
        $hint   = sanitize_text_field($b['user_hint'] ?? '');

        if (!$app_id) {
            return new WP_Error('missing_app', 'app_id is required', ['status' => 400]);
        }

        $result = self::generate_for_app($app_id, $hint);
        if (is_wp_error($result)) return $result;

        return $result;
    }

    /**
     * Generate an AI draft reply for a phone number (inbox context).
     * POST /ai/generate-reply-for-phone  { phone: string, ?user_hint: string }
     */
    public static function api_generate_for_phone($req) {
        global $wpdb;
        $b     = $req->get_json_params();
        $phone = sanitize_text_field($b['phone'] ?? '');
        $hint  = sanitize_text_field($b['user_hint'] ?? '');

        if (!$phone) {
            return new WP_Error('missing_phone', 'phone is required', ['status' => 400]);
        }

        $phone_norm = CC_DB::normalize_phone($phone);
        $suffix     = substr(preg_replace('/\D/', '', $phone_norm), -10);

        // Try to find a matching application
        $app = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM " . CC_DB::apps() . " WHERE phone LIKE %s ORDER BY created_at DESC LIMIT 1",
            '%' . $suffix
        ));

        if ($app) {
            return self::generate_for_app($app->id, $hint);
        }

        // No app match — generate with phone-only context
        return self::generate_for_phone($phone_norm, $hint);
    }

    /**
     * Get AI settings.
     */
    public static function api_get_settings() {
        return [
            'enabled'       => get_option(self::OPT_ENABLED, 'yes') === 'yes',
            'auto_draft'    => get_option(self::OPT_AUTO_DRAFT, 'yes') === 'yes',
            'tone'          => get_option(self::OPT_TONE, 'friendly'),
            'extra_context' => get_option(self::OPT_CONTEXT, ''),
            'max_sms_chars' => (int)get_option(self::OPT_MAX_LENGTH, 320),
            'has_api_key'   => !empty(get_option(self::OPT_API_KEY, '')),
        ];
    }

    /**
     * Save AI settings.
     */
    public static function api_save_settings($req) {
        $b = $req->get_json_params();

        if (isset($b['api_key'])) {
            $key = sanitize_text_field($b['api_key']);
            if ($key) update_option(self::OPT_API_KEY, $key);
        }
        if (isset($b['enabled'])) {
            update_option(self::OPT_ENABLED, $b['enabled'] ? 'yes' : 'no');
        }
        if (isset($b['auto_draft'])) {
            update_option(self::OPT_AUTO_DRAFT, $b['auto_draft'] ? 'yes' : 'no');
        }
        if (isset($b['tone'])) {
            $allowed = ['friendly', 'professional', 'casual', 'enthusiastic'];
            $tone = sanitize_text_field($b['tone']);
            if (in_array($tone, $allowed)) update_option(self::OPT_TONE, $tone);
        }
        if (isset($b['extra_context'])) {
            update_option(self::OPT_CONTEXT, sanitize_textarea_field($b['extra_context']));
        }
        if (isset($b['max_sms_chars'])) {
            update_option(self::OPT_MAX_LENGTH, max(100, min(600, (int)$b['max_sms_chars'])));
        }

        return ['saved' => true];
    }

    /**
     * Get AI generation stats.
     */
    public static function api_get_stats() {
        global $wpdb;
        $dt = CC_DB::drafts();

        $total     = (int)$wpdb->get_var("SELECT COUNT(*) FROM $dt WHERE intent LIKE 'ai_%'");
        $approved  = (int)$wpdb->get_var("SELECT COUNT(*) FROM $dt WHERE intent LIKE 'ai_%' AND status='approved'");
        $rejected  = (int)$wpdb->get_var("SELECT COUNT(*) FROM $dt WHERE intent LIKE 'ai_%' AND status='rejected'");
        $pending   = (int)$wpdb->get_var("SELECT COUNT(*) FROM $dt WHERE intent LIKE 'ai_%' AND status='pending'");

        return [
            'total'         => $total,
            'approved'      => $approved,
            'rejected'      => $rejected,
            'pending'       => $pending,
            'approval_rate' => $total > 0 ? round(($approved / max($approved + $rejected, 1)) * 100) : 0,
        ];
    }

    // ═══════════════════════════════════════
    //  WEBHOOK INTEGRATION (auto-draft)
    // ═══════════════════════════════════════

    /**
     * Called from CC_Webhooks after rules engine runs.
     * If no rule matched and AI auto-draft is on, generate a draft.
     */
    public static function maybe_auto_draft($phone, $message_body, $app_id, $parent_id, $rule_matched) {
        // Skip if rule already handled it
        if ($rule_matched) return;

        // Skip if AI is disabled or auto-draft is off
        if (get_option(self::OPT_ENABLED, 'yes') !== 'yes') return;
        if (get_option(self::OPT_AUTO_DRAFT, 'yes') !== 'yes') return;
        if (empty(get_option(self::OPT_API_KEY, ''))) return;

        // Skip very short messages (likely "ok", "k", "thanks", etc.)
        $body_clean = strtolower(trim($message_body));
        $skip_patterns = ['ok', 'k', 'thanks', 'thank you', 'thx', 'ty', 'cool', 'great', 'sounds good', 'perfect', 'awesome', 'got it', 'yes', 'no', 'yep', 'nope', 'sure', 'will do', '👍', '👌'];
        if (in_array($body_clean, $skip_patterns) || strlen($body_clean) < 3) return;

        // Don't auto-draft for opt-out signals
        $optout = ['stop', 'unsubscribe', 'opt out', 'remove me', 'leave me alone'];
        foreach ($optout as $phrase) {
            if (strpos($body_clean, $phrase) !== false) return;
        }

        // Rate limit: max 1 AI draft per phone per hour
        $rate_key = 'ptp_ai_draft_' . md5($phone);
        if (get_transient($rate_key)) return;
        set_transient($rate_key, 1, 3600);

        // Generate async via a single-fire scheduled event (non-blocking)
        $args = [$phone, $message_body, $app_id, $parent_id];
        if (!wp_next_scheduled('ptp_cc_ai_generate_draft', $args)) {
            wp_schedule_single_event(time(), 'ptp_cc_ai_generate_draft', $args);
        }
    }

    /**
     * Async handler: actually call Claude and create the draft.
     */
    public static function async_generate_draft($phone, $message_body, $app_id, $parent_id) {
        if ($app_id) {
            $result = self::generate_for_app($app_id, '', $message_body);
        } else {
            $result = self::generate_for_phone($phone, '', $message_body);
        }

        if (is_wp_error($result)) {
            error_log('[PTP-CC AI] Auto-draft failed: ' . $result->get_error_message());
        } else {
            error_log('[PTP-CC AI] Auto-draft created for ' . $phone . ': ' . substr($result['draft_body'] ?? '', 0, 60) . '...');
        }
    }

    // ═══════════════════════════════════════
    //  CORE GENERATION LOGIC
    // ═══════════════════════════════════════

    /**
     * Generate a reply for an application (full context).
     *
     * @param int    $app_id       Application ID
     * @param string $hint         Optional user instruction (e.g. "ask about availability this week")
     * @param string $last_inbound Optional: the inbound message that triggered this (for auto-draft)
     * @return array|WP_Error
     */
    public static function generate_for_app($app_id, $hint = '', $last_inbound = '') {
        global $wpdb;

        $app = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . CC_DB::apps() . " WHERE id=%d", $app_id
        ));
        if (!$app) return new WP_Error('not_found', 'Application not found', ['status' => 404]);

        // Gather context
        $context = self::build_app_context($app, $wpdb);
        $history = self::get_conversation_history($app->phone, $app_id, $wpdb);

        // Build messages for Claude
        $system  = self::build_system_prompt($context, $hint);
        $user_msg = self::build_user_message($history, $last_inbound, $hint, $context);

        // Call Claude
        $reply = self::call_claude($system, $user_msg);
        if (is_wp_error($reply)) return $reply;

        // Save as draft
        $draft_id = self::save_draft($reply, $app_id, $app->phone, $hint);

        // Track stats
        self::increment_stat('generated');

        return [
            'draft_id'   => $draft_id,
            'draft_body' => $reply,
            'app_id'     => $app_id,
            'phone'      => $app->phone,
            'context'    => [
                'name'     => $app->parent_name,
                'child'    => $app->child_name,
                'status'   => $app->status,
                'messages' => count($history),
            ],
        ];
    }

    /**
     * Generate a reply for a phone number (minimal context).
     */
    public static function generate_for_phone($phone, $hint = '', $last_inbound = '') {
        global $wpdb;

        $phone_norm = CC_DB::normalize_phone($phone);
        $suffix = substr(preg_replace('/\D/', '', $phone_norm), -10);

        // Try to find parent info
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . CC_DB::parents() . " WHERE phone LIKE %s LIMIT 1",
            '%' . $suffix
        ));

        $context = [
            'name'   => $parent ? $parent->display_name : 'Unknown',
            'email'  => $parent ? $parent->email : '',
            'phone'  => $phone_norm,
            'status' => 'unknown',
            'child'  => '',
            'source' => 'phone_only',
        ];

        $history = self::get_conversation_history($phone_norm, null, $wpdb);
        $system  = self::build_system_prompt($context, $hint);
        $user_msg = self::build_user_message($history, $last_inbound, $hint, $context);

        $reply = self::call_claude($system, $user_msg);
        if (is_wp_error($reply)) return $reply;

        $draft_id = self::save_draft($reply, null, $phone_norm, $hint);
        self::increment_stat('generated');

        return [
            'draft_id'   => $draft_id,
            'draft_body' => $reply,
            'phone'      => $phone_norm,
            'context'    => $context,
        ];
    }

    // ═══════════════════════════════════════
    //  CONTEXT BUILDERS
    // ═══════════════════════════════════════

    /**
     * Build rich context from an application record.
     */
    private static function build_app_context($app, $wpdb) {
        $ctx = [
            'name'        => $app->parent_name ?: 'Unknown',
            'first_name'  => explode(' ', $app->parent_name ?: '')[0] ?: 'there',
            'email'       => $app->email ?: '',
            'phone'       => $app->phone ?: '',
            'child'       => $app->child_name ?: '',
            'child_age'   => $app->child_age ?? '',
            'status'      => $app->status ?: 'unknown',
            'source'      => $app->source ?? 'unknown',
            'trainer'     => $app->trainer_name ?? '',
            'location'    => $app->location ?? '',
            'created'     => $app->created_at ?? '',
            'notes'       => $app->notes ?? '',
            'lead_temp'   => $app->lead_temperature ?? '',
            'lead_score'  => $app->lead_score ?? '',
        ];

        // Camp history
        $cb = CC_DB::camp_bookings();
        if ($wpdb->get_var("SHOW TABLES LIKE '$cb'") === $cb) {
            $camps = $wpdb->get_results($wpdb->prepare(
                "SELECT b.camp_id, p.post_title as camp_name, b.camper_name, b.status, b.amount_paid, b.created_at
                 FROM $cb b LEFT JOIN {$wpdb->posts} p ON b.camp_id=p.ID
                 WHERE b.customer_email=%s OR b.customer_phone LIKE %s ORDER BY b.created_at DESC LIMIT 5",
                $ctx['email'], '%' . substr(preg_replace('/\D/', '', $ctx['phone']), -10)
            ));
            $ctx['camp_history'] = $camps ?: [];
        }

        // Follow-up count & last date
        $fu = CC_DB::follow_ups();
        $fu_info = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) as total, MAX(sent_at) as last_sent FROM $fu WHERE app_id=%d", $app->id
        ));
        $ctx['followups_sent'] = $fu_info ? (int)$fu_info->total : 0;
        $ctx['last_followup']  = $fu_info ? $fu_info->last_sent : '';

        return $ctx;
    }

    /**
     * Get conversation history (last 20 messages) for context.
     */
    private static function get_conversation_history($phone, $app_id, $wpdb) {
        $mt = CC_DB::op_msgs();
        $suffix = substr(preg_replace('/\D/', '', $phone), -10);

        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT direction, body, created_at FROM $mt
             WHERE (phone LIKE %s" . ($app_id ? " OR app_id=%d" : "") . ")
             ORDER BY created_at DESC LIMIT 20",
            '%' . $suffix,
            ...($app_id ? [$app_id] : [])
        ));

        // Reverse so oldest first for the prompt
        return array_reverse($messages ?: []);
    }

    // ═══════════════════════════════════════
    //  PROMPT CONSTRUCTION
    // ═══════════════════════════════════════

    /**
     * Build the system prompt with PTP business context.
     */
    private static function build_system_prompt($contact_ctx, $hint = '') {
        $tone = get_option(self::OPT_TONE, 'friendly');
        $max_chars = (int)get_option(self::OPT_MAX_LENGTH, 320);
        $extra = get_option(self::OPT_CONTEXT, '');

        $tone_instructions = [
            'friendly'      => 'Warm, conversational, like a friend who happens to run a great soccer program. Use first names. Light energy.',
            'professional'  => 'Polite and professional but still approachable. Slightly more formal than texting a friend.',
            'casual'        => 'Very casual texting style. Short sentences, relaxed. Like texting a buddy.',
            'enthusiastic'  => 'High energy, excited about soccer and the kid\'s development. Use exclamation points naturally (not excessively).',
        ];

        $system = <<<PROMPT
You are the AI assistant for PTP Summer Camps (Players Teaching Players), a youth soccer training and camp business.

## About PTP
- Founded by Luke Martelli, a D1 soccer player at Villanova University with a Philadelphia Union Academy background
- PTP's unique selling point: current NCAA D1 athletes and MLS players don't just coach — they actually PLAY alongside the kids during sessions
- Maintains an 8:1 coach-to-camper ratio for ages 6-14
- Serves 500+ families across Pennsylvania, New Jersey, and Delaware
- 4.9-star rating from parents
- Offers free 1-on-1 training trial sessions and week-long summer camps at 10 locations
- 1-on-1 training starts at \$70/hour with pro-level coaches
- The 2026 World Cup is coming to Philadelphia — great talking point for getting kids excited about soccer

## Target audience
- Parents of kids ages 6-14, beginner to intermediate level
- NOT competitive/elite/travel team players — PTP is about fun, growth, and mentorship
- Parents want their kids to develop skills AND have a great time

## Your role
You are drafting an SMS reply on behalf of Luke / the PTP team. This is a TEXT MESSAGE, not an email.

## Tone
{$tone_instructions[$tone]}

## Rules
1. Keep replies under {$max_chars} characters (this is a text message)
2. Always use the parent's first name if known
3. Reference the child's name if known
4. Reference the assigned trainer/coach if known
5. NEVER invent information — if you don't know something, don't make it up
6. NEVER promise specific dates, times, or discounts unless told to
7. Always include a clear call-to-action (question, next step, or invitation to reply)
8. Sound like a real person texting, NOT a corporate auto-reply or chatbot
9. Don't use emojis excessively — one or two max, and only if it fits the tone
10. If the parent seems frustrated or upset, acknowledge their concern first
11. If the message is about cancellation or opt-out, be gracious and don't push
12. Match the parent's energy — if they're brief, be brief; if they're chatty, you can be too

PROMPT;

        if ($extra) {
            $system .= "\n## Additional business context\n{$extra}\n";
        }

        if ($hint) {
            $system .= "\n## Operator instruction\nThe PTP team member reviewing this draft specifically asked: \"{$hint}\"\nFactor this into your reply.\n";
        }

        return $system;
    }

    /**
     * Build the user message with conversation history and contact context.
     */
    private static function build_user_message($history, $last_inbound, $hint, $context) {
        $parts = [];

        // Contact info
        $parts[] = "## Contact info";
        $parts[] = "- Name: " . ($context['name'] ?? 'Unknown');
        if (!empty($context['child']))     $parts[] = "- Child: " . $context['child'];
        if (!empty($context['child_age'])) $parts[] = "- Child age: " . $context['child_age'];
        if (!empty($context['status']))    $parts[] = "- Pipeline status: " . $context['status'];
        if (!empty($context['trainer']))   $parts[] = "- Assigned coach: " . $context['trainer'];
        if (!empty($context['location']))  $parts[] = "- Location: " . $context['location'];
        if (!empty($context['lead_temp'])) $parts[] = "- Lead temperature: " . $context['lead_temp'];
        if (!empty($context['source']))    $parts[] = "- Source: " . $context['source'];
        if (!empty($context['notes']))     $parts[] = "- Notes: " . $context['notes'];
        if (!empty($context['followups_sent'])) $parts[] = "- Follow-ups already sent: " . $context['followups_sent'];

        // Camp history
        if (!empty($context['camp_history'])) {
            $parts[] = "\n## Camp history";
            foreach ($context['camp_history'] as $camp) {
                $parts[] = "- " . ($camp->camp_name ?? 'Camp') . " (" . ($camp->camper_name ?? '?') . ") — " . ($camp->status ?? 'unknown') . ($camp->amount_paid ? " \${$camp->amount_paid}" : '');
            }
        }

        // Conversation history
        if ($history) {
            $parts[] = "\n## Recent conversation (oldest first)";
            foreach ($history as $msg) {
                $who = $msg->direction === 'incoming' ? 'PARENT' : 'PTP';
                $ts = $msg->created_at ? date('M j g:ia', strtotime($msg->created_at)) : '';
                $body = substr($msg->body, 0, 500); // Truncate very long messages
                $parts[] = "[{$who} {$ts}] {$body}";
            }
        }

        // The actual request
        if ($last_inbound) {
            $parts[] = "\n## Latest inbound message (what we're replying to)";
            $parts[] = $last_inbound;
        }

        $parts[] = "\n---\nWrite a single SMS reply. Output ONLY the message text, nothing else.";

        return implode("\n", $parts);
    }

    // ═══════════════════════════════════════
    //  CLAUDE API CALL
    // ═══════════════════════════════════════

    /**
     * Call the Anthropic API.
     *
     * @param string $system  System prompt
     * @param string $user    User message
     * @return string|WP_Error  The generated reply text
     */
    private static function call_claude($system, $user) {
        $api_key = get_option(self::OPT_API_KEY, '');
        if (!$api_key) {
            return new WP_Error('no_api_key', 'Anthropic API key not configured. Go to AI Engine → Settings.', ['status' => 400]);
        }

        $payload = [
            'model'      => self::MODEL,
            'max_tokens' => self::MAX_TOKENS,
            'system'     => $system,
            'messages'   => [
                ['role' => 'user', 'content' => $user],
            ],
        ];

        $response = wp_remote_post(self::API_URL, [
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version'  => '2023-06-01',
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log('[PTP-CC AI] API request failed: ' . $response->get_error_message());
            return new WP_Error('api_error', 'Failed to reach AI service: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $err_msg = $body['error']['message'] ?? ('HTTP ' . $code);
            error_log('[PTP-CC AI] API error ' . $code . ': ' . $err_msg);

            if ($code === 401) {
                return new WP_Error('auth_error', 'Invalid API key. Check your Anthropic API key in AI Settings.');
            }
            if ($code === 429) {
                return new WP_Error('rate_limit', 'AI rate limit hit. Try again in a moment.');
            }

            return new WP_Error('api_error', 'AI service error: ' . $err_msg, ['status' => $code]);
        }

        // Extract text from response
        $reply = '';
        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $reply .= $block['text'];
            }
        }

        $reply = trim($reply);

        if (!$reply) {
            return new WP_Error('empty_reply', 'AI returned an empty response');
        }

        // Enforce character limit
        $max_chars = (int)get_option(self::OPT_MAX_LENGTH, 320);
        if (mb_strlen($reply) > $max_chars) {
            // Try to cut at last sentence boundary
            $cut = mb_substr($reply, 0, $max_chars);
            $last_period = max(mb_strrpos($cut, '.'), mb_strrpos($cut, '!'), mb_strrpos($cut, '?'));
            if ($last_period && $last_period > $max_chars * 0.5) {
                $reply = mb_substr($reply, 0, $last_period + 1);
            } else {
                $reply = $cut;
            }
        }

        return $reply;
    }

    // ═══════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════

    /**
     * Save a generated reply as a pending draft.
     */
    private static function save_draft($reply, $app_id, $phone, $hint = '') {
        global $wpdb;

        $intent = 'ai_generated';
        if ($hint) $intent = 'ai_hint:' . substr($hint, 0, 80);

        $wpdb->insert(CC_DB::drafts(), [
            'app_id'     => $app_id,
            'phone'      => CC_DB::normalize_phone($phone),
            'draft_body' => $reply,
            'intent'     => $intent,
            'status'     => 'pending',
        ]);

        return $wpdb->insert_id;
    }

    /**
     * Increment an AI stat counter.
     */
    private static function increment_stat($key) {
        $stats = get_option(self::OPT_STATS, []);
        $month = date('Y-m');
        if (!isset($stats[$month])) $stats[$month] = [];
        $stats[$month][$key] = ($stats[$month][$key] ?? 0) + 1;

        // Keep only last 6 months
        $cutoff = date('Y-m', strtotime('-6 months'));
        foreach (array_keys($stats) as $m) {
            if ($m < $cutoff) unset($stats[$m]);
        }

        update_option(self::OPT_STATS, $stats);
    }

    /**
     * Check if AI engine is properly configured and enabled.
     */
    public static function is_active() {
        return get_option(self::OPT_ENABLED, 'yes') === 'yes'
            && !empty(get_option(self::OPT_API_KEY, ''));
    }
}
