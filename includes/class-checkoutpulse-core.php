<?php

/**
 * CheckoutPulse Core Class
 *
 * Main core functionality for CheckoutPulse AI
 *
 * @package CheckoutPulse_AI
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CheckoutPulse Core Class
 *
 * @class CheckoutPulse_Core
 * @version 1.0.0
 */
class CheckoutPulse_Core
{

    /**
     * Single instance of the class
     *
     * @var CheckoutPulse_Core
     */
    protected static $_instance = null;

    /**
     * Database manager instance
     *
     * @var CheckoutPulse_Database_Manager
     */
    private $db_manager;

    /**
     * Main CheckoutPulse Core Instance
     *
     * @static
     * @return CheckoutPulse_Core - Main instance
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
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks()
    {
        // AJAX hooks for admin dashboard
        add_action('wp_ajax_cp_get_dashboard_data', array($this, 'ajax_get_dashboard_data'));
        add_action('wp_ajax_cp_get_failure_details', array($this, 'ajax_get_failure_details'));
        add_action('wp_ajax_cp_export_data', array($this, 'ajax_export_data'));
        add_action('wp_ajax_cp_test_alert', array($this, 'ajax_test_alert'));

        // Scheduled event hooks
        add_action('checkoutpulse_ai_daily_summary', array($this, 'send_daily_summary'));
        add_action('checkoutpulse_ai_weekly_report', array($this, 'send_weekly_report'));
        add_action('checkoutpulse_ai_cleanup_old_data', array($this, 'cleanup_old_data'));

        // Schedule events if not already scheduled
        if (!wp_next_scheduled('checkoutpulse_ai_daily_summary')) {
            wp_schedule_event(strtotime('09:00:00'), 'daily', 'checkoutpulse_ai_daily_summary');
        }

        if (!wp_next_scheduled('checkoutpulse_ai_weekly_report')) {
            wp_schedule_event(strtotime('next monday 09:00:00'), 'weekly', 'checkoutpulse_ai_weekly_report');
        }

        if (!wp_next_scheduled('checkoutpulse_ai_cleanup_old_data')) {
            wp_schedule_event(time(), 'daily', 'checkoutpulse_ai_cleanup_old_data');
        }
    }

    /**
     * AJAX handler for dashboard data
     */
    public function ajax_get_dashboard_data()
    {
        check_ajax_referer('checkoutpulse_ai_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Insufficient permissions', 'checkoutpulse-ai'));
        }

        $timeframe = sanitize_text_field($_POST['timeframe'] ?? '24h');
        $gateway = sanitize_text_field($_POST['gateway'] ?? '');

        $data = $this->get_dashboard_data($timeframe, $gateway);

        wp_send_json_success($data);
    }

    /**
     * Get dashboard data
     *
     * @param string $timeframe Time period
     * @param string $gateway Payment gateway filter
     * @return array Dashboard data
     */
    public function get_dashboard_data($timeframe = '24h', $gateway = '')
    {
        $date_ranges = $this->get_date_ranges($timeframe);

        $args = array(
            'date_from' => $date_ranges['from'],
            'date_to' => $date_ranges['to']
        );

        if (!empty($gateway)) {
            $args['gateway'] = $gateway;
        }

        // Get current period statistics
        $current_stats = $this->get_period_statistics($args);

        // Get previous period for comparison
        $previous_args = array(
            'date_from' => $date_ranges['prev_from'],
            'date_to' => $date_ranges['prev_to']
        );
        if (!empty($gateway)) {
            $previous_args['gateway'] = $gateway;
        }

        $previous_stats = $this->get_period_statistics($previous_args);

        // Get recent failures
        $recent_failures = $this->db_manager->get_payment_failures(array(
            'limit' => 10,
            'gateway' => $gateway,
            'date_from' => $date_ranges['from'],
            'date_to' => $date_ranges['to']
        ));

        // Get trend data for charts
        $trend_data = $this->get_trend_data($timeframe, $gateway);

        // Get gateway breakdown
        $gateway_stats = $this->get_gateway_statistics($date_ranges['from'], $date_ranges['to']);

        // Get top error codes
        $error_codes = $this->get_top_error_codes($date_ranges['from'], $date_ranges['to'], $gateway);

        return array(
            'kpis' => array(
                'total_failures' => $current_stats['total_failures'],
                'failure_rate' => $current_stats['failure_rate'],
                'total_amount_lost' => $current_stats['total_amount_lost'],
                'avg_failure_amount' => $current_stats['avg_failure_amount'],
                'trends' => array(
                    'failures' => $this->calculate_trend($current_stats['total_failures'], $previous_stats['total_failures']),
                    'amount' => $this->calculate_trend($current_stats['total_amount_lost'], $previous_stats['total_amount_lost']),
                    'rate' => $this->calculate_trend($current_stats['failure_rate'], $previous_stats['failure_rate'])
                )
            ),
            'charts' => array(
                'timeline' => $trend_data,
                'gateways' => $gateway_stats,
                'error_codes' => $error_codes
            ),
            'recent_failures' => $recent_failures,
            'summary' => array(
                'timeframe' => $timeframe,
                'gateway' => $gateway,
                'updated_at' => current_time('mysql')
            )
        );
    }

    /**
     * Get date ranges based on timeframe
     *
     * @param string $timeframe
     * @return array Date ranges
     */
    private function get_date_ranges($timeframe)
    {
        $now = current_time('timestamp');

        switch ($timeframe) {
            case '1h':
                $from = date('Y-m-d H:i:s', $now - HOUR_IN_SECONDS);
                $prev_from = date('Y-m-d H:i:s', $now - (2 * HOUR_IN_SECONDS));
                $prev_to = date('Y-m-d H:i:s', $now - HOUR_IN_SECONDS);
                break;
            case '24h':
                $from = date('Y-m-d H:i:s', $now - DAY_IN_SECONDS);
                $prev_from = date('Y-m-d H:i:s', $now - (2 * DAY_IN_SECONDS));
                $prev_to = date('Y-m-d H:i:s', $now - DAY_IN_SECONDS);
                break;
            case '7d':
                $from = date('Y-m-d H:i:s', $now - (7 * DAY_IN_SECONDS));
                $prev_from = date('Y-m-d H:i:s', $now - (14 * DAY_IN_SECONDS));
                $prev_to = date('Y-m-d H:i:s', $now - (7 * DAY_IN_SECONDS));
                break;
            case '30d':
                $from = date('Y-m-d H:i:s', $now - (30 * DAY_IN_SECONDS));
                $prev_from = date('Y-m-d H:i:s', $now - (60 * DAY_IN_SECONDS));
                $prev_to = date('Y-m-d H:i:s', $now - (30 * DAY_IN_SECONDS));
                break;
            default:
                $from = date('Y-m-d H:i:s', $now - DAY_IN_SECONDS);
                $prev_from = date('Y-m-d H:i:s', $now - (2 * DAY_IN_SECONDS));
                $prev_to = date('Y-m-d H:i:s', $now - DAY_IN_SECONDS);
        }

        return array(
            'from' => $from,
            'to' => current_time('mysql'),
            'prev_from' => $prev_from,
            'prev_to' => $prev_to
        );
    }

    /**
     * Get period statistics
     *
     * @param array $args Query arguments
     * @return array Statistics
     */
    private function get_period_statistics($args)
    {
        global $wpdb;

        // Get total failures and amount
        $failure_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_failures,
                SUM(amount) as total_amount_lost,
                AVG(amount) as avg_failure_amount,
                COUNT(DISTINCT order_id) as unique_failed_orders
            FROM {$this->db_manager->get_table_name('payment_failures')}
            WHERE failed_at BETWEEN %s AND %s" .
                (!empty($args['gateway']) ? " AND gateway = %s" : ""),
            $args['date_from'],
            $args['date_to'],
            ...(!empty($args['gateway']) ? array($args['gateway']) : array())
        ), ARRAY_A);

        // Get total orders and successful payments for failure rate calculation
        $order_stats = $this->get_order_statistics($args['date_from'], $args['date_to'], $args['gateway'] ?? '');

        $total_failures = intval($failure_stats['total_failures'] ?? 0);
        $total_orders = intval($order_stats['total_orders'] ?? 0);
        $failure_rate = $total_orders > 0 ? round(($total_failures / $total_orders) * 100, 2) : 0;

        return array(
            'total_failures' => $total_failures,
            'total_amount_lost' => floatval($failure_stats['total_amount_lost'] ?? 0),
            'avg_failure_amount' => floatval($failure_stats['avg_failure_amount'] ?? 0),
            'unique_failed_orders' => intval($failure_stats['unique_failed_orders'] ?? 0),
            'failure_rate' => $failure_rate,
            'total_orders' => $total_orders
        );
    }

    /**
     * Get order statistics from WooCommerce
     *
     * @param string $date_from
     * @param string $date_to
     * @param string $gateway
     * @return array Order statistics
     */
    private function get_order_statistics($date_from, $date_to, $gateway = '')
    {
        global $wpdb;

        $where_gateway = '';
        $params = array($date_from, $date_to);

        if (!empty($gateway)) {
            $where_gateway = "AND pm.meta_value = %s";
            $params[] = $gateway;
        }

        $sql = "SELECT COUNT(DISTINCT p.ID) as total_orders
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_payment_method'
                WHERE p.post_type = 'shop_order'
                AND p.post_date BETWEEN %s AND %s
                {$where_gateway}";

        $result = $wpdb->get_row($wpdb->prepare($sql, $params), ARRAY_A);

        return array(
            'total_orders' => intval($result['total_orders'] ?? 0)
        );
    }

    /**
     * Calculate trend percentage
     *
     * @param float $current Current value
     * @param float $previous Previous value
     * @return array Trend data
     */
    private function calculate_trend($current, $previous)
    {
        if ($previous == 0) {
            $percentage = $current > 0 ? 100 : 0;
            $direction = $current > 0 ? 'up' : 'neutral';
        } else {
            $percentage = round((($current - $previous) / $previous) * 100, 2);
            $direction = $percentage > 0 ? 'up' : ($percentage < 0 ? 'down' : 'neutral');
        }

        return array(
            'percentage' => abs($percentage),
            'direction' => $direction,
            'current' => $current,
            'previous' => $previous
        );
    }

    /**
     * Get trend data for charts
     *
     * @param string $timeframe
     * @param string $gateway
     * @return array Trend data
     */
    private function get_trend_data($timeframe, $gateway = '')
    {
        $date_ranges = $this->get_date_ranges($timeframe);

        $group_by = ($timeframe === '1h') ? 'hour' : 'day';

        $args = array(
            'date_from' => $date_ranges['from'],
            'date_to' => $date_ranges['to'],
            'group_by' => $group_by
        );

        if (!empty($gateway)) {
            $args['gateway'] = $gateway;
        }

        return $this->db_manager->get_failure_statistics($args);
    }

    /**
     * Get gateway statistics
     *
     * @param string $date_from
     * @param string $date_to
     * @return array Gateway statistics
     */
    private function get_gateway_statistics($date_from, $date_to)
    {
        return $this->db_manager->get_failure_statistics(array(
            'date_from' => $date_from,
            'date_to' => $date_to,
            'group_by' => 'gateway'
        ));
    }

    /**
     * Get top error codes
     *
     * @param string $date_from
     * @param string $date_to
     * @param string $gateway
     * @return array Error code statistics
     */
    private function get_top_error_codes($date_from, $date_to, $gateway = '')
    {
        return $this->db_manager->get_failure_statistics(array(
            'date_from' => $date_from,
            'date_to' => $date_to,
            'gateway' => $gateway,
            'group_by' => 'error_code'
        ));
    }

    /**
     * AJAX handler for failure details
     */
    public function ajax_get_failure_details()
    {
        check_ajax_referer('checkoutpulse_ai_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Insufficient permissions', 'checkoutpulse-ai'));
        }

        $failure_id = intval($_POST['failure_id'] ?? 0);

        if ($failure_id <= 0) {
            wp_send_json_error(__('Invalid failure ID', 'checkoutpulse-ai'));
        }

        $failure = $this->get_failure_details($failure_id);

        if (!$failure) {
            wp_send_json_error(__('Failure not found', 'checkoutpulse-ai'));
        }

        wp_send_json_success($failure);
    }

    /**
     * Get detailed failure information
     *
     * @param int $failure_id
     * @return array|null Failure details
     */
    public function get_failure_details($failure_id)
    {
        global $wpdb;

        $failure = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->db_manager->get_table_name('payment_failures')} WHERE id = %d",
            $failure_id
        ), ARRAY_A);

        if (!$failure) {
            return null;
        }

        // Unserialize metadata
        if (!empty($failure['metadata'])) {
            $failure['metadata'] = maybe_unserialize($failure['metadata']);
        }

        // Get order details if order exists
        if (!empty($failure['order_id'])) {
            $order = wc_get_order($failure['order_id']);
            if ($order) {
                $failure['order_details'] = array(
                    'order_number' => $order->get_order_number(),
                    'order_status' => $order->get_status(),
                    'order_total' => $order->get_total(),
                    'currency' => $order->get_currency(),
                    'customer_email' => $order->get_billing_email(),
                    'order_date' => $order->get_date_created()->format('Y-m-d H:i:s'),
                    'order_url' => admin_url('post.php?post=' . $failure['order_id'] . '&action=edit')
                );
            }
        }

        return $failure;
    }

    /**
     * Send daily summary email
     */
    public function send_daily_summary()
    {
        if (get_option('checkoutpulse_ai_daily_summary') !== 'yes') {
            return;
        }

        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $data = $this->get_daily_summary_data($yesterday);

        $alert_system = CheckoutPulse_Alert_System::instance();
        $alert_system->send_daily_summary($data);
    }

    /**
     * Send weekly report email
     */
    public function send_weekly_report()
    {
        if (get_option('checkoutpulse_ai_weekly_report') !== 'yes') {
            return;
        }

        $last_week_start = date('Y-m-d', strtotime('last monday', strtotime('-1 week')));
        $last_week_end = date('Y-m-d', strtotime('last sunday', strtotime('-1 week')));

        $data = $this->get_weekly_report_data($last_week_start, $last_week_end);

        $alert_system = CheckoutPulse_Alert_System::instance();
        $alert_system->send_weekly_report($data);
    }

    /**
     * Get daily summary data
     *
     * @param string $date
     * @return array Summary data
     */
    private function get_daily_summary_data($date)
    {
        $from = $date . ' 00:00:00';
        $to = $date . ' 23:59:59';

        return $this->get_period_statistics(array(
            'date_from' => $from,
            'date_to' => $to
        ));
    }

    /**
     * Get weekly report data
     *
     * @param string $start_date
     * @param string $end_date
     * @return array Report data
     */
    private function get_weekly_report_data($start_date, $end_date)
    {
        $from = $start_date . ' 00:00:00';
        $to = $end_date . ' 23:59:59';

        $stats = $this->get_period_statistics(array(
            'date_from' => $from,
            'date_to' => $to
        ));

        $daily_breakdown = $this->db_manager->get_failure_statistics(array(
            'date_from' => $from,
            'date_to' => $to,
            'group_by' => 'day'
        ));

        $gateway_breakdown = $this->db_manager->get_failure_statistics(array(
            'date_from' => $from,
            'date_to' => $to,
            'group_by' => 'gateway'
        ));

        return array(
            'period' => array(
                'start' => $start_date,
                'end' => $end_date
            ),
            'summary' => $stats,
            'daily_breakdown' => $daily_breakdown,
            'gateway_breakdown' => $gateway_breakdown
        );
    }

    /**
     * Cleanup old data
     */
    public function cleanup_old_data()
    {
        $retention_days = intval(get_option('checkoutpulse_ai_data_retention_days', 90));
        $this->db_manager->cleanup_old_data($retention_days);
    }

    /**
     * AJAX handler for data export
     */
    public function ajax_export_data()
    {
        check_ajax_referer('checkoutpulse_ai_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Insufficient permissions', 'checkoutpulse-ai'));
        }

        $format = sanitize_text_field($_POST['format'] ?? 'csv');
        $timeframe = sanitize_text_field($_POST['timeframe'] ?? '30d');
        $gateway = sanitize_text_field($_POST['gateway'] ?? '');

        $data = $this->export_failure_data($format, $timeframe, $gateway);

        wp_send_json_success($data);
    }

    /**
     * Export failure data
     *
     * @param string $format Export format
     * @param string $timeframe Time period
     * @param string $gateway Gateway filter
     * @return array Export data
     */
    private function export_failure_data($format, $timeframe, $gateway = '')
    {
        $date_ranges = $this->get_date_ranges($timeframe);

        $args = array(
            'date_from' => $date_ranges['from'],
            'date_to' => $date_ranges['to'],
            'limit' => 10000 // Large limit for export
        );

        if (!empty($gateway)) {
            $args['gateway'] = $gateway;
        }

        $failures = $this->db_manager->get_payment_failures($args);

        if ($format === 'csv') {
            return $this->generate_csv_export($failures);
        } else {
            return array(
                'data' => $failures,
                'format' => 'json'
            );
        }
    }

    /**
     * Generate CSV export
     *
     * @param array $failures
     * @return array CSV data
     */
    private function generate_csv_export($failures)
    {
        $csv_data = "Order ID,Gateway,Error Code,Error Message,Amount,Currency,Failed At\n";

        foreach ($failures as $failure) {
            $csv_data .= sprintf(
                "%s,%s,%s,\"%s\",%s,%s,%s\n",
                $failure['order_id'],
                $failure['gateway'],
                $failure['error_code'] ?? '',
                str_replace('"', '""', $failure['error_message'] ?? ''),
                $failure['amount'],
                $failure['currency'],
                $failure['failed_at']
            );
        }

        return array(
            'data' => $csv_data,
            'format' => 'csv',
            'filename' => 'payment-failures-' . date('Y-m-d') . '.csv'
        );
    }

    /**
     * AJAX handler for testing alerts
     */
    public function ajax_test_alert()
    {
        check_ajax_referer('checkoutpulse_ai_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions', 'checkoutpulse-ai'));
        }

        $alert_type = sanitize_text_field($_POST['alert_type'] ?? 'test');

        $alert_system = CheckoutPulse_Alert_System::instance();
        $result = $alert_system->send_test_alert($alert_type);

        if ($result) {
            wp_send_json_success(__('Test alert sent successfully', 'checkoutpulse-ai'));
        } else {
            wp_send_json_error(__('Failed to send test alert', 'checkoutpulse-ai'));
        }
    }
}
