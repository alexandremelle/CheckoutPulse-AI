<?php

/**
 * Payment Monitor Class
 *
 * Monitors WooCommerce payment attempts and captures failures
 *
 * @package CheckoutPulse_AI
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CheckoutPulse Payment Monitor Class
 *
 * @class CheckoutPulse_Payment_Monitor
 * @version 1.0.0
 */
class CheckoutPulse_Payment_Monitor
{

    /**
     * Single instance of the class
     *
     * @var CheckoutPulse_Payment_Monitor
     */
    protected static $_instance = null;

    /**
     * Database manager instance
     *
     * @var CheckoutPulse_Database_Manager
     */
    private $db_manager;

    /**
     * Alert system instance
     *
     * @var CheckoutPulse_Alert_System
     */
    private $alert_system;

    /**
     * Monitored gateways
     *
     * @var array
     */
    private $monitored_gateways = array();

    /**
     * Main CheckoutPulse Payment Monitor Instance
     *
     * @static
     * @return CheckoutPulse_Payment_Monitor - Main instance
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
        $this->load_monitored_gateways();
    }

    /**
     * Initialize WordPress and WooCommerce hooks
     */
    private function init_hooks()
    {
        // Only monitor if enabled
        if (get_option('checkoutpulse_ai_monitoring_enabled', 'yes') !== 'yes') {
            return;
        }

        // Core WooCommerce payment hooks
        add_action('woocommerce_order_status_failed', array($this, 'capture_order_failure'), 10, 2);
        add_action('woocommerce_checkout_order_processed', array($this, 'track_checkout_attempt'), 10, 3);

        // Payment gateway specific hooks
        add_action('woocommerce_payment_complete', array($this, 'track_payment_success'), 10, 1);

        // Gateway-specific error hooks
        add_action('wc_stripe_payment_error', array($this, 'capture_stripe_error'), 10, 2);
        add_action('woocommerce_gateway_paypal_return', array($this, 'track_paypal_return'), 10, 2);

        // Generic payment method failure hooks
        add_action('woocommerce_payment_failed_before_processing', array($this, 'capture_payment_failure_before_processing'), 10, 2);

        // Order status transition hooks for comprehensive tracking
        add_action('woocommerce_order_status_changed', array($this, 'track_order_status_change'), 10, 4);

        // HPOS compatibility hooks
        add_action('woocommerce_new_order', array($this, 'track_new_order'), 10, 1);

        // Hook for payment method failures
        add_filter('woocommerce_payment_successful_result', array($this, 'validate_payment_result'), 10, 2);
    }

    /**
     * Load monitored gateways from settings
     */
    private function load_monitored_gateways()
    {
        $default_gateways = array('paypal', 'stripe', 'bacs', 'cheque', 'cod');
        $this->monitored_gateways = get_option('checkoutpulse_ai_monitored_gateways', $default_gateways);
    }

    /**
     * Check if gateway should be monitored
     *
     * @param string $gateway Gateway ID
     * @return bool
     */
    private function should_monitor_gateway($gateway)
    {
        return in_array($gateway, $this->monitored_gateways) || empty($this->monitored_gateways);
    }

    /**
     * Capture order failure
     *
     * @param int $order_id Order ID
     * @param WC_Order $order Order object
     */
    public function capture_order_failure($order_id, $order = null)
    {
        if (!$order) {
            $order = wc_get_order($order_id);
        }

        if (!$order) {
            return;
        }

        $payment_method = $order->get_payment_method();

        if (!$this->should_monitor_gateway($payment_method)) {
            return;
        }

        $failure_data = $this->extract_failure_data($order);
        $failure_data['error_message'] = $this->get_order_failure_reason($order);

        $failure_id = $this->db_manager->insert_payment_failure($failure_data);

        if ($failure_id) {
            $this->trigger_alert_evaluation($failure_data, $failure_id);
        }
    }

    /**
     * Track checkout attempt
     *
     * @param int $order_id Order ID
     * @param array $posted_data Posted checkout data
     * @param WC_Order $order Order object
     */
    public function track_checkout_attempt($order_id, $posted_data, $order)
    {
        // This tracks successful checkout processing
        // We'll use this for calculating failure rates
        $payment_method = $order->get_payment_method();

        if (!$this->should_monitor_gateway($payment_method)) {
            return;
        }

        // Store successful attempt data for analytics
        $this->store_checkout_attempt($order, 'processed');
    }

    /**
     * Track payment success
     *
     * @param int $order_id Order ID
     */
    public function track_payment_success($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        $payment_method = $order->get_payment_method();

        if (!$this->should_monitor_gateway($payment_method)) {
            return;
        }

        $this->store_checkout_attempt($order, 'success');
    }

    /**
     * Capture Stripe-specific errors
     *
     * @param WC_Order $order Order object
     * @param array $error_data Error information
     */
    public function capture_stripe_error($order, $error_data)
    {
        if (!$this->should_monitor_gateway('stripe')) {
            return;
        }

        $failure_data = $this->extract_failure_data($order);
        $failure_data['error_code'] = $error_data['code'] ?? 'stripe_error';
        $failure_data['error_message'] = $error_data['message'] ?? 'Stripe payment error';
        $failure_data['metadata'] = array(
            'stripe_error' => $error_data,
            'source' => 'stripe_webhook'
        );

        $failure_id = $this->db_manager->insert_payment_failure($failure_data);

        if ($failure_id) {
            $this->trigger_alert_evaluation($failure_data, $failure_id);
        }
    }

    /**
     * Track PayPal return
     *
     * @param WC_Order $order Order object
     * @param array $return_data Return data from PayPal
     */
    public function track_paypal_return($order, $return_data)
    {
        if (!$this->should_monitor_gateway('paypal')) {
            return;
        }

        // Check if PayPal returned an error
        if (isset($return_data['payment_status']) && $return_data['payment_status'] === 'Failed') {
            $failure_data = $this->extract_failure_data($order);
            $failure_data['error_code'] = $return_data['reason_code'] ?? 'paypal_failed';
            $failure_data['error_message'] = $return_data['pending_reason'] ?? 'PayPal payment failed';
            $failure_data['metadata'] = array(
                'paypal_return' => $return_data,
                'source' => 'paypal_return'
            );

            $failure_id = $this->db_manager->insert_payment_failure($failure_data);

            if ($failure_id) {
                $this->trigger_alert_evaluation($failure_data, $failure_id);
            }
        }
    }

    /**
     * Capture payment failure before processing
     *
     * @param WC_Order $order Order object
     * @param array $payment_data Payment data
     */
    public function capture_payment_failure_before_processing($order, $payment_data)
    {
        $payment_method = $order->get_payment_method();

        if (!$this->should_monitor_gateway($payment_method)) {
            return;
        }

        $failure_data = $this->extract_failure_data($order);
        $failure_data['error_message'] = 'Payment failed before processing';
        $failure_data['metadata'] = array(
            'payment_data' => $payment_data,
            'source' => 'before_processing'
        );

        $failure_id = $this->db_manager->insert_payment_failure($failure_data);

        if ($failure_id) {
            $this->trigger_alert_evaluation($failure_data, $failure_id);
        }
    }

    /**
     * Track order status changes
     *
     * @param int $order_id Order ID
     * @param string $old_status Old status
     * @param string $new_status New status
     * @param WC_Order $order Order object
     */
    public function track_order_status_change($order_id, $old_status, $new_status, $order)
    {
        // Track transitions to failed status
        if ($new_status === 'failed' && $old_status !== 'failed') {
            $this->capture_order_failure($order_id, $order);
        }

        // Track cancelled orders that might indicate payment issues
        if ($new_status === 'cancelled' && in_array($old_status, array('pending', 'on-hold'))) {
            $payment_method = $order->get_payment_method();

            if ($this->should_monitor_gateway($payment_method)) {
                $failure_data = $this->extract_failure_data($order);
                $failure_data['error_message'] = 'Order cancelled (potential payment failure)';
                $failure_data['metadata'] = array(
                    'old_status' => $old_status,
                    'new_status' => $new_status,
                    'source' => 'status_change'
                );

                $failure_id = $this->db_manager->insert_payment_failure($failure_data);

                if ($failure_id) {
                    $this->trigger_alert_evaluation($failure_data, $failure_id);
                }
            }
        }
    }

    /**
     * Track new order creation (HPOS compatibility)
     *
     * @param WC_Order $order Order object
     */
    public function track_new_order($order)
    {
        // Additional tracking for HPOS-enabled stores
        $order_id = $order->get_id();
        $payment_method = $order->get_payment_method();

        if (!$this->should_monitor_gateway($payment_method)) {
            return;
        }

        // Store the order attempt for rate calculations
        $this->store_checkout_attempt($order, 'created');
    }

    /**
     * Validate payment result for potential failures
     *
     * @param array $result Payment result
     * @param WC_Order $order Order object
     * @return array Modified result
     */
    public function validate_payment_result($result, $order)
    {
        // Check if payment result indicates failure
        if (isset($result['result']) && $result['result'] === 'failure') {
            $payment_method = $order->get_payment_method();

            if ($this->should_monitor_gateway($payment_method)) {
                $failure_data = $this->extract_failure_data($order);
                $failure_data['error_message'] = $result['message'] ?? 'Payment result indicates failure';
                $failure_data['metadata'] = array(
                    'payment_result' => $result,
                    'source' => 'payment_result_validation'
                );

                $failure_id = $this->db_manager->insert_payment_failure($failure_data);

                if ($failure_id) {
                    $this->trigger_alert_evaluation($failure_data, $failure_id);
                }
            }
        }

        return $result;
    }

    /**
     * Extract failure data from order
     *
     * @param WC_Order $order Order object
     * @return array Failure data
     */
    private function extract_failure_data($order)
    {
        $customer_ip = $this->get_customer_ip();
        $user_agent = $this->get_user_agent();

        return array(
            'order_id' => $order->get_id(),
            'gateway' => $order->get_payment_method(),
            'amount' => $order->get_total(),
            'currency' => $order->get_currency(),
            'customer_id' => $order->get_customer_id(),
            'user_agent_hash' => $user_agent ? hash('sha256', $user_agent) : null,
            'ip_hash' => $customer_ip ? hash('sha256', $customer_ip) : null,
            'failed_at' => current_time('mysql'),
            'metadata' => array(
                'order_number' => $order->get_order_number(),
                'billing_country' => $order->get_billing_country(),
                'billing_email' => $order->get_billing_email(),
                'order_status' => $order->get_status(),
                'created_via' => $order->get_created_via()
            )
        );
    }

    /**
     * Get order failure reason
     *
     * @param WC_Order $order Order object
     * @return string Failure reason
     */
    private function get_order_failure_reason($order)
    {
        // Try to get failure reason from order notes
        $notes = wc_get_order_notes(array(
            'order_id' => $order->get_id(),
            'limit' => 5,
            'orderby' => 'date_created',
            'order' => 'DESC'
        ));

        foreach ($notes as $note) {
            $content = strtolower($note->content);
            if (
                strpos($content, 'payment') !== false &&
                (strpos($content, 'failed') !== false || strpos($content, 'error') !== false)
            ) {
                return $note->content;
            }
        }

        // Check order meta for payment errors
        $payment_error = $order->get_meta('_payment_error');
        if ($payment_error) {
            return $payment_error;
        }

        // Check for gateway-specific error meta
        $gateway_error = $order->get_meta('_' . $order->get_payment_method() . '_error');
        if ($gateway_error) {
            return $gateway_error;
        }

        return 'Payment failed - reason unknown';
    }

    /**
     * Store checkout attempt for analytics
     *
     * @param WC_Order $order Order object
     * @param string $status Attempt status
     */
    private function store_checkout_attempt($order, $status)
    {
        // Store successful attempts in transient for rate calculations
        $cache_key = 'cp_ai_attempts_' . date('Y-m-d-H');
        $attempts = get_transient($cache_key) ?: array();

        $attempts[] = array(
            'order_id' => $order->get_id(),
            'gateway' => $order->get_payment_method(),
            'amount' => $order->get_total(),
            'status' => $status,
            'timestamp' => current_time('timestamp')
        );

        set_transient($cache_key, $attempts, HOUR_IN_SECONDS * 2);
    }

    /**
     * Get customer IP address
     *
     * @return string|null IP address
     */
    private function get_customer_ip()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }
        return null;
    }

    /**
     * Get user agent
     *
     * @return string|null User agent
     */
    private function get_user_agent()
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? null;
    }

    /**
     * Trigger alert evaluation
     *
     * @param array $failure_data Failure data
     * @param int $failure_id Failure ID
     */
    private function trigger_alert_evaluation($failure_data, $failure_id)
    {
        // Initialize alert system if not already done
        if (!$this->alert_system) {
            $this->alert_system = CheckoutPulse_Alert_System::instance();
        }

        // Trigger alert evaluation
        $this->alert_system->evaluate_alerts($failure_data, $failure_id);
    }

    /**
     * Get recent failure count for gateway
     *
     * @param string $gateway Gateway ID
     * @param int $timeframe_minutes Time frame in minutes
     * @return int Failure count
     */
    public function get_recent_failure_count($gateway, $timeframe_minutes = 10)
    {
        global $wpdb;

        $since = date('Y-m-d H:i:s', current_time('timestamp') - ($timeframe_minutes * 60));

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->db_manager->get_table_name('payment_failures')} 
             WHERE gateway = %s AND failed_at >= %s",
            $gateway,
            $since
        ));
    }

    /**
     * Get failure rate for gateway
     *
     * @param string $gateway Gateway ID
     * @param int $timeframe_minutes Time frame in minutes
     * @return float Failure rate percentage
     */
    public function get_failure_rate($gateway, $timeframe_minutes = 60)
    {
        $since = current_time('timestamp') - ($timeframe_minutes * 60);

        // Get failures from database
        global $wpdb;
        $failure_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->db_manager->get_table_name('payment_failures')} 
             WHERE gateway = %s AND failed_at >= %s",
            $gateway,
            date('Y-m-d H:i:s', $since)
        ));

        // Get total attempts from transients
        $total_attempts = 0;
        $hours_to_check = ceil($timeframe_minutes / 60);

        for ($i = 0; $i < $hours_to_check; $i++) {
            $hour_timestamp = $since + ($i * HOUR_IN_SECONDS);
            $cache_key = 'cp_ai_attempts_' . date('Y-m-d-H', $hour_timestamp);
            $attempts = get_transient($cache_key) ?: array();

            foreach ($attempts as $attempt) {
                if ($attempt['gateway'] === $gateway && $attempt['timestamp'] >= $since) {
                    $total_attempts++;
                }
            }
        }

        if ($total_attempts === 0) {
            return 0;
        }

        return round(($failure_count / $total_attempts) * 100, 2);
    }

    /**
     * Check if gateway appears to be down
     *
     * @param string $gateway Gateway ID
     * @param int $consecutive_failures Number of consecutive failures to consider gateway down
     * @return bool True if gateway appears down
     */
    public function is_gateway_down($gateway, $consecutive_failures = 3)
    {
        global $wpdb;

        $recent_orders = $wpdb->get_results($wpdb->prepare(
            "SELECT id, failed_at FROM {$this->db_manager->get_table_name('payment_failures')} 
             WHERE gateway = %s 
             ORDER BY failed_at DESC 
             LIMIT %d",
            $gateway,
            $consecutive_failures
        ));

        if (count($recent_orders) < $consecutive_failures) {
            return false;
        }

        // Check if all recent attempts failed within last 5 minutes
        $cutoff_time = date('Y-m-d H:i:s', current_time('timestamp') - (5 * 60));

        foreach ($recent_orders as $order) {
            if ($order->failed_at < $cutoff_time) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get monitored gateways
     *
     * @return array Monitored gateways
     */
    public function get_monitored_gateways()
    {
        return $this->monitored_gateways;
    }

    /**
     * Update monitored gateways
     *
     * @param array $gateways Gateway IDs to monitor
     */
    public function update_monitored_gateways($gateways)
    {
        $this->monitored_gateways = $gateways;
        update_option('checkoutpulse_ai_monitored_gateways', $gateways);
    }
}
