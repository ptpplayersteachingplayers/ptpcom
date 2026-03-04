<?php
/**
 * PTP Command Center — Rules Engine
 * Evaluates inbound messages against ptp_cc_rules table.
 * Called from CC_Webhooks on every incoming message.
 */
if (!defined('ABSPATH')) exit;

class CC_Rules_Engine {

    /**
     * Evaluate an inbound message against all enabled rules.
     * Returns: ['matched' => bool, 'rule' => row|null, 'action_taken' => string|null]
     */
    public static function evaluate($phone, $message_body, $app_id = null, $parent_id = null) {
        global $wpdb;
        $rules = $wpdb->get_results(
            "SELECT * FROM " . CC_DB::rules() . " WHERE enabled=1 ORDER BY priority DESC"
        );

        if (!$rules) return ['matched' => false];

        $body_lower = strtolower(trim($message_body));

        foreach ($rules as $rule) {
            $triggered = false;

            switch ($rule->trigger_type) {
                case 'keyword':
                    $keywords = array_map('trim', array_map('strtolower', explode(',', $rule->trigger_value)));
                    foreach ($keywords as $kw) {
                        if ($kw && strpos($body_lower, $kw) !== false) {
                            $triggered = true;
                            break;
                        }
                    }
                    break;

                case 'intent':
                    // Simple intent detection by keyword groups
                    $intent_map = [
                        'pricing'    => ['price', 'cost', 'how much', 'rate', 'fee', 'expensive', 'afford', 'pay', 'payment', 'dollar', '$'],
                        'schedule'   => ['schedule', 'available', 'availability', 'when', 'time', 'book', 'open', 'slot'],
                        'cancel'     => ['cancel', 'refund', 'stop', 'quit', 'end', 'remove'],
                        'location'   => ['where', 'location', 'address', 'field', 'facility', 'directions'],
                        'age'        => ['age', 'how old', 'year old', 'young', 'too old', 'age group', 'age range'],
                    ];
                    $target_intent = strtolower(trim($rule->trigger_value));
                    if (isset($intent_map[$target_intent])) {
                        foreach ($intent_map[$target_intent] as $kw) {
                            if (strpos($body_lower, $kw) !== false) {
                                $triggered = true;
                                break;
                            }
                        }
                    }
                    break;

                case 'time':
                    if ($rule->trigger_value === 'outside_hours') {
                        $tz = new DateTimeZone('America/New_York');
                        $now = new DateTime('now', $tz);
                        $hour = (int)$now->format('G');
                        $biz_start = (int)get_option('ptp_cc_biz_hour_start', 8);
                        $biz_end   = (int)get_option('ptp_cc_biz_hour_end', 21);
                        $triggered = ($hour < $biz_start || $hour >= $biz_end);
                    } elseif ($rule->trigger_value === 'weekend') {
                        $tz = new DateTimeZone('America/New_York');
                        $now = new DateTime('now', $tz);
                        $day = (int)$now->format('N');
                        $triggered = ($day >= 6);
                    }
                    break;

                case 'regex':
                    if (@preg_match('/' . $rule->trigger_value . '/i', $message_body)) {
                        $triggered = true;
                    }
                    break;

                case 'all':
                    $triggered = true;
                    break;
            }

            if (!$triggered) continue;

            // Execute action
            $action_taken = self::execute($rule, $phone, $message_body, $app_id, $parent_id);

            // Log
            error_log("[PTP-CC Rules] Rule '{$rule->name}' triggered for $phone → action: {$rule->action_type}");

            CC_DB::log('rule_fired', 'rule', $rule->id, $rule->name . ': ' . $action_taken, 'system');

            return ['matched' => true, 'rule' => $rule, 'action_taken' => $action_taken];
        }

        return ['matched' => false];
    }

    /**
     * Execute a rule's action.
     */
    private static function execute($rule, $phone, $message_body, $app_id, $parent_id) {
        global $wpdb;

        switch ($rule->action_type) {
            case 'auto_reply':
                if (!$rule->action_value) return 'no_message';

                // Personalize
                $msg = $rule->action_value;
                if ($app_id) {
                    $app = $wpdb->get_row($wpdb->prepare(
                        "SELECT parent_name, child_name, trainer_name FROM " . CC_DB::apps() . " WHERE id=%d", $app_id
                    ));
                    if ($app) {
                        $first = explode(' ', $app->parent_name)[0] ?? '';
                        $msg = str_replace(['{name}','{child}','{trainer}'], [$first, $app->child_name ?: 'your player', $app->trainer_name ?: 'a coach'], $msg);
                    }
                }

                $result = CC_DB::send_sms($phone, $msg);
                if (!is_wp_error($result)) {
                    $wpdb->insert(CC_DB::op_msgs(), [
                        'app_id' => $app_id, 'parent_id' => $parent_id,
                        'phone' => CC_DB::normalize_phone($phone),
                        'direction' => 'outgoing', 'body' => $msg,
                    ]);
                }
                return 'auto_reply_sent';

            case 'create_draft':
                $wpdb->insert(CC_DB::drafts(), [
                    'app_id' => $app_id, 'phone' => CC_DB::normalize_phone($phone),
                    'draft_body' => $rule->action_value ?: "Respond to: $message_body",
                    'intent' => $rule->name, 'status' => 'pending',
                ]);
                return 'draft_created';

            case 'escalate':
                // Send admin notification
                $admin_email = get_option('admin_email');
                $subject = '[PTP CC] Escalated: ' . $phone;
                $body = "Inbound message from $phone triggered escalation rule '{$rule->name}'.\n\nMessage: $message_body\n\nApp ID: $app_id\n\nReview in Command Center.";
                wp_mail($admin_email, $subject, $body);
                return 'escalated';

            case 'tag':
                if ($app_id && $rule->action_value) {
                    $wpdb->update(CC_DB::apps(), [
                        'lead_temperature' => sanitize_text_field($rule->action_value)
                    ], ['id' => $app_id]);
                }
                return 'tagged';

            case 'do_not_reply':
                // Opt-out: mark app as lost, log reason
                if ($app_id) {
                    $old = $wpdb->get_var($wpdb->prepare(
                        "SELECT status FROM " . CC_DB::apps() . " WHERE id=%d", $app_id
                    ));
                    $wpdb->update(CC_DB::apps(), ['status' => 'lost'], ['id' => $app_id]);
                    $wpdb->insert(CC_DB::seg_hist(), [
                        'app_id' => $app_id, 'old_value' => $old,
                        'new_value' => 'lost', 'reason' => 'Opt-out: ' . $rule->name,
                    ]);
                }
                return 'opted_out';
        }

        return 'unknown_action';
    }
}
