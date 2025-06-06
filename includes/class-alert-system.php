<?php

/**
 * Alert System Class
 *
 * Handles alert thresholds, notifications, and escalation procedures
 *
 * @package CheckoutPulse_AI
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CheckoutPulse Alert System Class
 *
 * @class CheckoutPulse_Alert_System
 * @version 1.0.0
 */
class CheckoutPulse_Alert_System
{

    /**
     * Single instance of the class
     *
     * @var CheckoutPulse_Alert_System
     */
    protected static $_instance = null;

    /**
     * Database manager instance
     *
     * @var CheckoutPulse_Database_Manager
     */
    private $db_manager;

    /**
     * Payment monitor instance
     *
     * @var CheckoutPulse_Payment_Monitor
     */
    private $payment_monitor;

    /**
     * Alert configuration
     *
     * @var array
     */
    private $alert_config = array();

    /**
     * Email templates
     *
     * @var array
     */
    private $email_templates = array();

    /**
     * Main CheckoutPulse Alert System Instance
     *
     * @static
     * @return CheckoutPulse_Alert_System - Main instance
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db_manager = CheckoutPulse_Database_Manager::instance();
        $this->load_alert_configuration();
        $this->init_email_templates();
    }

    /**
     * Load alert configuration from options
     */
    private function load_alert_configuration()
    {
        $this->alert_config = array(
            'critical' => array(
                'rapid_failures' => array(
                    'threshold' => get_option('checkoutpulse_ai_critical_failure_threshold', 5),
                    'timeframe' => get_option('checkoutpulse_ai_critical_timeframe', 600), // 10 minutes
                    'cooldown' => get_option('checkoutpulse_ai_alert_cooldown', 1800), // 30 minutes
                    'priority' => 'critical'
                ),
                'gateway_down' => array(
                    'threshold' => get_option('checkoutpulse_ai_gateway_down_threshold', 3),
                    'timeframe' => 300, // 5 minutes
                    'same_gateway' => true,
                    'priority' => 'critical'
                ),
                'high_value_failure' => array(
                    'threshold_amount' => get_option('checkoutpulse_ai_high_value_threshold', 500),
                    'consecutive' => 2,
                    'priority' => 'critical'
                )
            ),
            'warning' => array(
                'elevated_failure_rate' => array(
                    'threshold_percentage' => get_option('checkoutpulse_ai_warning_failure_rate', 15),
                    'timeframe' => get_option('checkoutpulse_ai_warning_timeframe', 3600), // 1 hour
                    'minimum_attempts' => 10,
                    'priority' => 'warning'
                ),
                'gateway_degradation' => array(
                    'threshold_percentage' => 25,
                    'timeframe' => 1800, // 30 minutes
                    'minimum_attempts' => 5,
                    'priority' => 'warning'
                ),
                'unusual_error_spike' => array(
                    'threshold' => 3,
                    'timeframe' => 1800, // 30 minutes
                    'error_code_based' => true,
                    'priority' => 'warning'
                )
            ),
            'info' => array(
                'daily_summary' => array(
                    'schedule' => 'daily',
                    'time' => '09:00',
                    'include_trends' => true,
                    'priority' => 'info'
                ),
                'weekly_report' => array(
                    'schedule' => 'weekly',
                    'day' => 'monday',
                    'time' => '09:00',
                    'detailed_analysis' => true,
                    'priority' => 'info'
                )
            )
        );
    }

    /**
     * Initialize email templates
     */
    private function init_email_templates()
    {
        $site_name = get_bloginfo('name');

        $this->email_templates = array(
            'critical' => array(
                'subject' => sprintf(__('[URGENT] Payment System Alert - %s', 'checkoutpulse-ai'), $site_name),
                'priority' => 'high',
                'format' => 'html'
            ),
            'warning' => array(
                'subject' => sprintf(__('[Warning] Payment Issues Detected - %s', 'checkoutpulse-ai'), $site_name),
                'priority' => 'normal',
                'format' => 'html'
            ),
            'summary' => array(
                'subject' => sprintf(__('Daily Payment Summary - %s', 'checkoutpulse-ai'), $site_name),
                'priority' => 'low',
                'format' => 'html'
            )
        );
    }

    /**
     * Evaluate alerts based on new failure
     *
     * @param array $failure_data Failure data
     * @param int $failure_id Failure ID
     */
    public function evaluate_alerts($failure_data, $failure_id)
    {
        // Check critical alerts
        $this->check_rapid_failures($failure_data, $failure_id);
        $this->check_gateway_down($failure_data, $failure_id);
        $this->check_high_value_failures($failure_data, $failure_id);

        // Check warning alerts
        $this->check_elevated_failure_rate($failure_data, $failure_id);
        $this->check_gateway_degradation($failure_data, $failure_id);
        $this->check_unusual_error_spike($failure_data, $failure_id);
    }

    /**
     * Check for rapid failures (critical alert)
     *
     * @param array $failure_data Failure data
     * @param int $failure_id Failure ID
     */
    private function check_rapid_failures($failure_data, $failure_id)
    {
        $config = $this->alert_config['critical']['rapid_failures'];

        if (!$this->payment_monitor) {
            $this->payment_monitor = CheckoutPulse_Payment_Monitor::instance();
        }

        $recent_failures = $this->payment_monitor->get_recent_failure_count(
            $failure_data['gateway'],
            $config['timeframe'] / 60
        );

        if ($recent_failures >= $config['threshold']) {
            $alert_data = array(
                'alert_type' => 'rapid_failures',
                'alert_level' => 'critical',
                'message' => sprintf(
                    __('%d payment failures detected for %s gateway in the last %d minutes', 'checkoutpulse-ai'),
                    $recent_failures,
                    $failure_data['gateway'],
                    $config['timeframe'] / 60
                ),
                'threshold_data' => array(
                    'threshold' => $config['threshold'],
                    'actual' => $recent_failures,
                    'timeframe' => $config['timeframe'],
                    'gateway' => $failure_data['gateway']
                ),
                'failure_ids' => array($failure_id)
            );

            $this->send_alert_if_not_in_cooldown($alert_data, $config['cooldown']);
        }
    }

    /**
     * Check if gateway appears to be down (critical alert)
     *
     * @param array $failure_data Failure data
     * @param int $failure_id Failure ID
     */
    private function check_gateway_down($failure_data, $failure_id)
    {
        $config = $this->alert_config['critical']['gateway_down'];

        if (!$this->payment_monitor) {
            $this->payment_monitor = CheckoutPulse_Payment_Monitor::instance();
        }

        if ($this->payment_monitor->is_gateway_down($failure_data['gateway'], $config['threshold'])) {
            $alert_data = array(
                'alert_type' => 'gateway_down',
                'alert_level' => 'critical',
                'message' => sprintf(
                    __('Gateway %s appears to be down - %d consecutive failures detected', 'checkoutpulse-ai'),
                    $failure_data['gateway'],
                    $config['threshold']
                ),
                'threshold_data' => array(
                    'threshold' => $config['threshold'],
                    'gateway' => $failure_data['gateway'],
                    'timeframe' => $config['timeframe']
                ),
                'failure_ids' => array($failure_id)
            );

            $this->send_alert_if_not_in_cooldown($alert_data, $this->alert_config['critical']['rapid_failures']['cooldown']);
        }
    }

    /**
     * Check for high-value failures (critical alert)
     *
     * @param array $failure_data Failure data
     * @param int $failure_id Failure ID
     */
    private function check_high_value_failures($failure_data, $failure_id)
    {
        $config = $this->alert_config['critical']['high_value_failure'];

        if ($failure_data['amount'] >= $config['threshold_amount']) {
            // Check for consecutive high-value failures
            $recent_high_value_failures = $this->get_recent_high_value_failures(
                $config['threshold_amount'],
                $config['consecutive']
            );

            if (count($recent_high_value_failures) >= $config['consecutive']) {
                $total_amount = array_sum(array_column($recent_high_value_failures, 'amount'));

                $alert_data = array(
                    'alert_type' => 'high_value_failure',
                    'alert_level' => 'critical',
                    'message' => sprintf(
                        __('%d consecutive high-value payment failures detected. Total amount: %s %s', 'checkoutpulse-ai'),
                        count($recent_high_value_failures),
                        number_format($total_amount, 2),
                        $failure_data['currency']
                    ),
                    'threshold_data' => array(
                        'threshold_amount' => $config['threshold_amount'],
                        'consecutive_required' => $config['consecutive'],
                        'actual_consecutive' => count($recent_high_value_failures),
                        'total_amount' => $total_amount
                    ),
                    'failure_ids' => array_column($recent_high_value_failures, 'id')
                );

                $this->send_alert_if_not_in_cooldown($alert_data, $this->alert_config['critical']['rapid_failures']['cooldown']);
            }
        }
    }

    /**
     * Check for elevated failure rate (warning alert)
     *
     * @param array $failure_data Failure data
     * @param int $failure_id Failure ID
     */
    private function check_elevated_failure_rate($failure_data, $failure_id)
    {
        $config = $this->alert_config['warning']['elevated_failure_rate'];

        if (!$this->payment_monitor) {
            $this->payment_monitor = CheckoutPulse_Payment_Monitor::instance();
        }

        $failure_rate = $this->payment_monitor->get_failure_rate(
            $failure_data['gateway'],
            $config['timeframe'] / 60
        );

        if ($failure_rate >= $config['threshold_percentage']) {
            $alert_data = array(
                'alert_type' => 'elevated_failure_rate',
                'alert_level' => 'warning',
                'message' => sprintf(
                    __('Elevated failure rate detected for %s gateway: %s%% over the last hour', 'checkoutpulse-ai'),
                    $failure_data['gateway'],
                    number_format($failure_rate, 1)
                ),
                'threshold_data' => array(
                    'threshold_percentage' => $config['threshold_percentage'],
                    'actual_percentage' => $failure_rate,
                    'timeframe' => $config['timeframe'],
                    'gateway' => $failure_data['gateway']
                ),
                'failure_ids' => array($failure_id)
            );

            $this->send_alert_if_not_in_cooldown($alert_data, 3600); // 1 hour cooldown for warnings
        }
    }

    /**
     * Check for gateway degradation (warning alert)
     *
     * @param array $failure_data Failure data
     * @param int $failure_id Failure ID
     */
    private function check_gateway_degradation($failure_data, $failure_id)
    {
        $config = $this->alert_config['warning']['gateway_degradation'];

        if (!$this->payment_monitor) {
            $this->payment_monitor = CheckoutPulse_Payment_Monitor::instance();
        }

        $failure_rate = $this->payment_monitor->get_failure_rate(
            $failure_data['gateway'],
            $config['timeframe'] / 60
        );

        if ($failure_rate >= $config['threshold_percentage']) {
            $alert_data = array(
                'alert_type' => 'gateway_degradation',
                'alert_level' => 'warning',
                'message' => sprintf(
                    __('Gateway %s performance degradation: %s%% failure rate in the last 30 minutes', 'checkoutpulse-ai'),
                    $failure_data['gateway'],
                    number_format($failure_rate, 1)
                ),
                'threshold_data' => array(
                    'threshold_percentage' => $config['threshold_percentage'],
                    'actual_percentage' => $failure_rate,
                    'timeframe' => $config['timeframe'],
                    'gateway' => $failure_data['gateway']
                ),
                'failure_ids' => array($failure_id)
            );

            $this->send_alert_if_not_in_cooldown($alert_data, 1800); // 30 minute cooldown
        }
    }

    /**
     * Check for unusual error spike (warning alert)
     *
     * @param array $failure_data Failure data
     * @param int $failure_id Failure ID
     */
    private function check_unusual_error_spike($failure_data, $failure_id)
    {
        if (empty($failure_data['error_code'])) {
            return;
        }

        $config = $this->alert_config['warning']['unusual_error_spike'];
        $error_count = $this->get_recent_error_count(
            $failure_data['error_code'],
            $config['timeframe'] / 60
        );

        if ($error_count >= $config['threshold']) {
            $alert_data = array(
                'alert_type' => 'unusual_error_spike',
                'alert_level' => 'warning',
                'message' => sprintf(
                    __('Unusual error spike detected: "%s" occurred %d times in the last 30 minutes', 'checkoutpulse-ai'),
                    $failure_data['error_code'],
                    $error_count
                ),
                'threshold_data' => array(
                    'threshold' => $config['threshold'],
                    'actual' => $error_count,
                    'error_code' => $failure_data['error_code'],
                    'timeframe' => $config['timeframe']
                ),
                'failure_ids' => array($failure_id)
            );

            $this->send_alert_if_not_in_cooldown($alert_data, 1800); // 30 minute cooldown
        }
    }

    /**
     * Send alert if not in cooldown period
     *
     * @param array $alert_data Alert data
     * @param int $cooldown_seconds Cooldown period in seconds
     */
    private function send_alert_if_not_in_cooldown($alert_data, $cooldown_seconds)
    {
        $cooldown_key = 'cp_ai_alert_cooldown_' . $alert_data['alert_type'];
        $last_alert_time = get_transient($cooldown_key);

        if ($last_alert_time && (current_time('timestamp') - $last_alert_time) < $cooldown_seconds) {
            // Still in cooldown period
            return false;
        }

        // Send the alert
        $alert_id = $this->send_alert($alert_data);

        if ($alert_id) {
            // Set cooldown
            set_transient($cooldown_key, current_time('timestamp'), $cooldown_seconds);
            return true;
        }

        return false;
    }

    /**
     * Send alert notification
     *
     * @param array $alert_data Alert data
     * @return int|false Alert ID or false on failure
     */
    private function send_alert($alert_data)
    {
        // Log the alert
        $alert_id = $this->db_manager->insert_alert_log($alert_data);

        if (!$alert_id) {
            return false;
        }

        // Send email notification if enabled
        if (get_option('checkoutpulse_ai_email_notifications', 'yes') === 'yes') {
            $email_sent = $this->send_email_alert($alert_data);

            // Update delivery status
            $status = $email_sent ? 'delivered' : 'failed';
            $this->db_manager->update_alert_status($alert_id, $status);
        }

        // Show admin notice for critical alerts
        if ($alert_data['alert_level'] === 'critical') {
            $this->add_admin_notice($alert_data);
        }

        // Trigger action hook for third-party integrations
        do_action('checkoutpulse_ai_alert_sent', $alert_data, $alert_id);

        return $alert_id;
    }

    /**
     * Send email alert
     *
     * @param array $alert_data Alert data
     * @return bool Success status
     */
    private function send_email_alert($alert_data)
    {
        $template = $this->email_templates[$alert_data['alert_level']] ?? $this->email_templates['warning'];
        $recipients = $this->get_alert_recipients($alert_data['alert_level']);

        if (empty($recipients)) {
            return false;
        }

        $subject = $template['subject'];
        $message = $this->generate_email_content($alert_data);
        $headers = array('Content-Type: text/html; charset=UTF-8');

        if ($template['priority'] === 'high') {
            $headers[] = 'X-Priority: 1';
            $headers[] = 'X-MSMail-Priority: High';
        }

        foreach ($recipients as $recipient) {
            $personalized_message = str_replace('{recipient_name}', $this->get_recipient_name($recipient), $message);
            wp_mail($recipient, $subject, $personalized_message, $headers);
        }

        return true;
    }

    /**
     * Generate email content
     *
     * @param array $alert_data Alert data
     * @return string Email HTML content
     */
    private function generate_email_content($alert_data)
    {
        $site_name = get_bloginfo('name');
        $dashboard_url = admin_url('admin.php?page=checkoutpulse-ai');

        $css = '
        <style>
            .alert-email { font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; }
            .alert-header { background: #f8f9fa; padding: 20px; border-radius: 8px 8px 0 0; }
            .alert-critical { border-left: 5px solid #dc3545; }
            .alert-warning { border-left: 5px solid #ffc107; }
            .alert-content { padding: 20px; background: white; }
            .alert-details { background: #f8f9fa; padding: 15px; border-radius: 4px; margin: 15px 0; }
            .alert-button { display: inline-block; padding: 12px 24px; background: #007cba; color: white; text-decoration: none; border-radius: 4px; margin: 15px 0; }
            .alert-footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #666; border-radius: 0 0 8px 8px; }
        </style>';

        $alert_class = 'alert-' . $alert_data['alert_level'];
        $icon = $alert_data['alert_level'] === 'critical' ? '🚨' : '⚠️';

        $html = $css . '
        <div class="alert-email ' . $alert_class . '">
            <div class="alert-header">
                <h1>' . $icon . ' ' . ucfirst($alert_data['alert_level']) . ' Alert</h1>
                <p><strong>' . esc_html($site_name) . '</strong> - ' . current_time('F j, Y g:i A') . '</p>
            </div>
            
            <div class="alert-content">
                <h2>' . esc_html($alert_data['message']) . '</h2>
                
                <div class="alert-details">
                    <h3>Alert Details:</h3>
                    <ul>
                        <li><strong>Alert Type:</strong> ' . esc_html(str_replace('_', ' ', ucwords($alert_data['alert_type'], '_'))) . '</li>
                        <li><strong>Time:</strong> ' . current_time('Y-m-d H:i:s') . '</li>';

        if (!empty($alert_data['threshold_data'])) {
            foreach ($alert_data['threshold_data'] as $key => $value) {
                if (!is_array($value)) {
                    $html .= '<li><strong>' . esc_html(ucwords(str_replace('_', ' ', $key))) . ':</strong> ' . esc_html($value) . '</li>';
                }
            }
        }

        $html .= '
                    </ul>
                </div>

                <a href="' . esc_url($dashboard_url) . '" class="alert-button">View Dashboard</a>

                <h3>Recommended Actions:</h3>
                <ul>' . $this->get_recommended_actions($alert_data) . '</ul>
            </div>
            
            <div class="alert-footer">
                <p>This alert was generated by CheckoutPulse AI. You can adjust alert settings in your WordPress admin dashboard.</p>
            </div>
        </div>';

        return $html;
    }

    /**
     * Get recommended actions based on alert type
     *
     * @param array $alert_data Alert data
     * @return string HTML list items
     */
    private function get_recommended_actions($alert_data)
    {
        $actions = array();

        switch ($alert_data['alert_type']) {
            case 'rapid_failures':
            case 'gateway_down':
                $actions[] = 'Check payment gateway status and configuration';
                $actions[] = 'Contact payment processor support';
                $actions[] = 'Consider enabling backup payment methods';
                $actions[] = 'Monitor gateway performance closely';
                break;

            case 'high_value_failure':
                $actions[] = 'Review high-value failed orders individually';
                $actions[] = 'Contact affected customers directly';
                $actions[] = 'Check for fraud patterns';
                $actions[] = 'Consider manual payment processing';
                break;

            case 'elevated_failure_rate':
            case 'gateway_degradation':
                $actions[] = 'Monitor gateway performance trends';
                $actions[] = 'Check for maintenance windows';
                $actions[] = 'Review recent configuration changes';
                $actions[] = 'Consider temporary failover options';
                break;

            case 'unusual_error_spike':
                $actions[] = 'Investigate root cause of specific error';
                $actions[] = 'Check gateway documentation for error code';
                $actions[] = 'Review recent website changes';
                $actions[] = 'Contact technical support if needed';
                break;

            default:
                $actions[] = 'Review the CheckoutPulse AI dashboard for details';
                $actions[] = 'Monitor payment system performance';
                break;
        }

        return '<li>' . implode('</li><li>', array_map('esc_html', $actions)) . '</li>';
    }

    /**
     * Get alert recipients based on alert level
     *
     * @param string $alert_level Alert level
     * @return array Email addresses
     */
    private function get_alert_recipients($alert_level)
    {
        $admin_email = get_option('checkoutpulse_ai_admin_email', get_option('admin_email'));
        $recipients = array($admin_email);

        // Add additional recipients based on alert level
        if ($alert_level === 'critical') {
            $critical_emails = get_option('checkoutpulse_ai_critical_alert_emails', '');
            if (!empty($critical_emails)) {
                $additional = array_map('trim', explode(',', $critical_emails));
                $recipients = array_merge($recipients, $additional);
            }
        }

        return array_filter(array_unique($recipients), 'is_email');
    }

    /**
     * Get recipient name for personalization
     *
     * @param string $email Email address
     * @return string Recipient name
     */
    private function get_recipient_name($email)
    {
        $user = get_user_by('email', $email);
        return $user ? $user->display_name : 'Administrator';
    }

    /**
     * Add admin notice for critical alerts
     *
     * @param array $alert_data Alert data
     */
    private function add_admin_notice($alert_data)
    {
        $notice_key = 'cp_ai_admin_notice_' . $alert_data['alert_type'];

        set_transient($notice_key, array(
            'message' => $alert_data['message'],
            'level' => $alert_data['alert_level'],
            'dismissible' => false
        ), 3600); // Show for 1 hour

        // Hook to display the notice
        add_action('admin_notices', function () use ($notice_key) {
            $notice = get_transient($notice_key);
            if ($notice) {
                $class = $notice['level'] === 'critical' ? 'notice-error' : 'notice-warning';
                $dismissible = $notice['dismissible'] ? 'is-dismissible' : '';

                echo '<div class="notice ' . $class . ' ' . $dismissible . '">';
                echo '<p><strong>CheckoutPulse AI:</strong> ' . esc_html($notice['message']) . '</p>';
                echo '</div>';
            }
        });
    }

    /**
     * Get recent high-value failures
     *
     * @param float $threshold_amount Minimum amount
     * @param int $limit Number of failures to check
     * @return array Recent high-value failures
     */
    private function get_recent_high_value_failures($threshold_amount, $limit)
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, amount, failed_at FROM {$this->db_manager->get_table_name('payment_failures')} 
             WHERE amount >= %f 
             ORDER BY failed_at DESC 
             LIMIT %d",
            $threshold_amount,
            $limit
        ), ARRAY_A);
    }

    /**
     * Get recent error count
     *
     * @param string $error_code Error code
     * @param int $timeframe_minutes Timeframe in minutes
     * @return int Error count
     */
    private function get_recent_error_count($error_code, $timeframe_minutes)
    {
        global $wpdb;

        $since = date('Y-m-d H:i:s', current_time('timestamp') - ($timeframe_minutes * 60));

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->db_manager->get_table_name('payment_failures')} 
             WHERE error_code = %s AND failed_at >= %s",
            $error_code,
            $since
        ));
    }

    /**
     * Send daily summary email
     *
     * @param array $summary_data Summary data
     * @return bool Success status
     */
    public function send_daily_summary($summary_data)
    {
        $template = $this->email_templates['summary'];
        $recipients = $this->get_alert_recipients('info');

        if (empty($recipients)) {
            return false;
        }

        $subject = $template['subject'];
        $message = $this->generate_daily_summary_content($summary_data);
        $headers = array('Content-Type: text/html; charset=UTF-8');

        foreach ($recipients as $recipient) {
            wp_mail($recipient, $subject, $message, $headers);
        }

        return true;
    }

    /**
     * Send weekly report email
     *
     * @param array $report_data Report data
     * @return bool Success status
     */
    public function send_weekly_report($report_data)
    {
        $template = $this->email_templates['summary'];
        $recipients = $this->get_alert_recipients('info');

        if (empty($recipients)) {
            return false;
        }

        $subject = str_replace('Daily', 'Weekly', $template['subject']);
        $message = $this->generate_weekly_report_content($report_data);
        $headers = array('Content-Type: text/html; charset=UTF-8');

        foreach ($recipients as $recipient) {
            wp_mail($recipient, $subject, $message, $headers);
        }

        return true;
    }

    /**
     * Generate daily summary email content
     *
     * @param array $summary_data Summary data
     * @return string Email HTML content
     */
    private function generate_daily_summary_content($summary_data)
    {
        $site_name = get_bloginfo('name');
        $dashboard_url = admin_url('admin.php?page=checkoutpulse-ai');

        $html = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <div style="background: #007cba; color: white; padding: 20px; text-align: center;">
                <h1>Daily Payment Summary</h1>
                <p>' . esc_html($site_name) . ' - ' . date('F j, Y') . '</p>
            </div>
            
            <div style="padding: 20px; background: white;">
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-bottom: 20px;">
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 4px; text-align: center;">
                        <h3 style="margin: 0; color: #dc3545;">Total Failures</h3>
                        <p style="font-size: 24px; font-weight: bold; margin: 5px 0;">' . number_format($summary_data['total_failures']) . '</p>
                    </div>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 4px; text-align: center;">
                        <h3 style="margin: 0; color: #28a745;">Failure Rate</h3>
                        <p style="font-size: 24px; font-weight: bold; margin: 5px 0;">' . number_format($summary_data['failure_rate'], 1) . '%</p>
                    </div>
                </div>
                
                <p><strong>Total Amount Lost:</strong> $' . number_format($summary_data['total_amount_lost'], 2) . '</p>
                <p><strong>Average Failure Amount:</strong> $' . number_format($summary_data['avg_failure_amount'], 2) . '</p>
                
                <div style="text-align: center; margin: 20px 0;">
                    <a href="' . esc_url($dashboard_url) . '" style="display: inline-block; padding: 12px 24px; background: #007cba; color: white; text-decoration: none; border-radius: 4px;">View Full Dashboard</a>
                </div>
            </div>
        </div>';

        return $html;
    }

    /**
     * Generate weekly report email content
     *
     * @param array $report_data Report data
     * @return string Email HTML content
     */
    private function generate_weekly_report_content($report_data)
    {
        $site_name = get_bloginfo('name');
        $dashboard_url = admin_url('admin.php?page=checkoutpulse-ai');

        $summary = $report_data['summary'];
        $period = $report_data['period'];

        $html = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <div style="background: #007cba; color: white; padding: 20px; text-align: center;">
                <h1>Weekly Payment Report</h1>
                <p>' . esc_html($site_name) . ' - ' . $period['start'] . ' to ' . $period['end'] . '</p>
            </div>
            
            <div style="padding: 20px; background: white;">
                <h2>Weekly Summary</h2>
                <ul>
                    <li><strong>Total Failures:</strong> ' . number_format($summary['total_failures']) . '</li>
                    <li><strong>Failure Rate:</strong> ' . number_format($summary['failure_rate'], 1) . '%</li>
                    <li><strong>Total Amount Lost:</strong> $' . number_format($summary['total_amount_lost'], 2) . '</li>
                    <li><strong>Unique Failed Orders:</strong> ' . number_format($summary['unique_failed_orders']) . '</li>
                </ul>
                
                <div style="text-align: center; margin: 20px 0;">
                    <a href="' . esc_url($dashboard_url) . '" style="display: inline-block; padding: 12px 24px; background: #007cba; color: white; text-decoration: none; border-radius: 4px;">View Detailed Analytics</a>
                </div>
            </div>
        </div>';

        return $html;
    }

    /**
     * Send test alert
     *
     * @param string $alert_type Alert type to test
     * @return bool Success status
     */
    public function send_test_alert($alert_type = 'test')
    {
        $alert_data = array(
            'alert_type' => 'test_alert',
            'alert_level' => 'warning',
            'message' => __('This is a test alert from CheckoutPulse AI. If you received this, email notifications are working correctly.', 'checkoutpulse-ai'),
            'threshold_data' => array(
                'test_type' => $alert_type,
                'sent_at' => current_time('mysql')
            ),
            'failure_ids' => array()
        );

        return $this->send_alert($alert_data) !== false;
    }

    /**
     * Get alert configuration
     *
     * @return array Alert configuration
     */
    public function get_alert_config()
    {
        return $this->alert_config;
    }

    /**
     * Update alert configuration
     *
     * @param array $config New configuration
     */
    public function update_alert_config($config)
    {
        $this->alert_config = array_merge($this->alert_config, $config);

        // Save critical thresholds to options
        if (isset($config['critical']['rapid_failures'])) {
            update_option('checkoutpulse_ai_critical_failure_threshold', $config['critical']['rapid_failures']['threshold']);
            update_option('checkoutpulse_ai_critical_timeframe', $config['critical']['rapid_failures']['timeframe']);
            update_option('checkoutpulse_ai_alert_cooldown', $config['critical']['rapid_failures']['cooldown']);
        }

        if (isset($config['warning']['elevated_failure_rate'])) {
            update_option('checkoutpulse_ai_warning_failure_rate', $config['warning']['elevated_failure_rate']['threshold_percentage']);
            update_option('checkoutpulse_ai_warning_timeframe', $config['warning']['elevated_failure_rate']['timeframe']);
        }
    }
}
