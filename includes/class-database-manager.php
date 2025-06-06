<?php

/**
 * Database Manager Class
 *
 * Handles all database operations for CheckoutPulse AI
 *
 * @package CheckoutPulse_AI
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CheckoutPulse Database Manager Class
 *
 * @class CheckoutPulse_Database_Manager
 * @version 1.0.0
 */
class CheckoutPulse_Database_Manager
{

    /**
     * Single instance of the class
     *
     * @var CheckoutPulse_Database_Manager
     */
    protected static $_instance = null;

    /**
     * Table names
     *
     * @var array
     */
    private $tables = array();

    /**
     * Main CheckoutPulse Database Manager Instance
     *
     * @static
     * @return CheckoutPulse_Database_Manager - Main instance
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
        global $wpdb;

        $this->tables = array(
            'payment_failures' => $wpdb->prefix . CHECKOUTPULSE_AI_TABLE_PREFIX . 'payment_failures',
            'alert_logs' => $wpdb->prefix . CHECKOUTPULSE_AI_TABLE_PREFIX . 'alert_logs',
            'plugin_settings' => $wpdb->prefix . CHECKOUTPULSE_AI_TABLE_PREFIX . 'plugin_settings'
        );
    }

    /**
     * Get table name
     *
     * @param string $table Table identifier
     * @return string Table name
     */
    public function get_table_name($table)
    {
        return isset($this->tables[$table]) ? $this->tables[$table] : '';
    }

    /**
     * Create plugin database tables
     *
     * @static
     */
    public static function create_tables()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_prefix = $wpdb->prefix . CHECKOUTPULSE_AI_TABLE_PREFIX;

        // Payment failures table
        $payment_failures_table = $table_prefix . 'payment_failures';
        $sql_payment_failures = "CREATE TABLE $payment_failures_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            gateway varchar(50) NOT NULL,
            error_code varchar(100) DEFAULT NULL,
            error_message text,
            amount decimal(10,2) NOT NULL DEFAULT 0.00,
            currency varchar(3) NOT NULL DEFAULT 'USD',
            user_agent_hash varchar(64) DEFAULT NULL,
            ip_hash varchar(64) DEFAULT NULL,
            failed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            metadata longtext,
            customer_id bigint(20) unsigned DEFAULT NULL,
            retry_count int(11) DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_order_id (order_id),
            KEY idx_gateway (gateway),
            KEY idx_failed_at (failed_at),
            KEY idx_error_code (error_code),
            KEY idx_customer_id (customer_id),
            KEY idx_gateway_failed_at (gateway, failed_at)
        ) $charset_collate;";

        // Alert logs table
        $alert_logs_table = $table_prefix . 'alert_logs';
        $sql_alert_logs = "CREATE TABLE $alert_logs_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            alert_type varchar(50) NOT NULL,
            alert_level varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            sent_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            recipient varchar(255) DEFAULT NULL,
            delivery_status varchar(20) DEFAULT 'pending',
            metadata longtext,
            failure_ids text,
            threshold_data longtext,
            PRIMARY KEY (id),
            KEY idx_alert_type (alert_type),
            KEY idx_alert_level (alert_level),
            KEY idx_sent_at (sent_at),
            KEY idx_delivery_status (delivery_status)
        ) $charset_collate;";

        // Plugin settings table
        $plugin_settings_table = $table_prefix . 'plugin_settings';
        $sql_plugin_settings = "CREATE TABLE $plugin_settings_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            option_name varchar(191) NOT NULL,
            option_value longtext,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_option_name (option_name),
            KEY idx_updated_at (updated_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        dbDelta($sql_payment_failures);
        dbDelta($sql_alert_logs);
        dbDelta($sql_plugin_settings);

        // Update database version
        update_option('checkoutpulse_ai_db_version', '1.0.0');
    }

    /**
     * Insert payment failure record
     *
     * @param array $data Failure data
     * @return int|false Insert ID or false on failure
     */
    public function insert_payment_failure($data)
    {
        global $wpdb;

        $defaults = array(
            'order_id' => 0,
            'gateway' => '',
            'error_code' => null,
            'error_message' => null,
            'amount' => 0.00,
            'currency' => 'USD',
            'user_agent_hash' => null,
            'ip_hash' => null,
            'failed_at' => current_time('mysql'),
            'metadata' => null,
            'customer_id' => null,
            'retry_count' => 0
        );

        $data = wp_parse_args($data, $defaults);

        // Serialize metadata if it's an array
        if (is_array($data['metadata'])) {
            $data['metadata'] = serialize($data['metadata']);
        }

        $result = $wpdb->insert(
            $this->get_table_name('payment_failures'),
            $data,
            array(
                '%d', // order_id
                '%s', // gateway
                '%s', // error_code
                '%s', // error_message
                '%f', // amount
                '%s', // currency
                '%s', // user_agent_hash
                '%s', // ip_hash
                '%s', // failed_at
                '%s', // metadata
                '%d', // customer_id
                '%d'  // retry_count
            )
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get payment failures with filters
     *
     * @param array $args Query arguments
     * @return array Payment failure records
     */
    public function get_payment_failures($args = array())
    {
        global $wpdb;

        $defaults = array(
            'limit' => 20,
            'offset' => 0,
            'order_by' => 'failed_at',
            'order' => 'DESC',
            'gateway' => null,
            'date_from' => null,
            'date_to' => null,
            'error_code' => null,
            'order_id' => null
        );

        $args = wp_parse_args($args, $defaults);

        $where_clauses = array('1=1');
        $where_values = array();

        if (!empty($args['gateway'])) {
            $where_clauses[] = 'gateway = %s';
            $where_values[] = $args['gateway'];
        }

        if (!empty($args['date_from'])) {
            $where_clauses[] = 'failed_at >= %s';
            $where_values[] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $where_clauses[] = 'failed_at <= %s';
            $where_values[] = $args['date_to'];
        }

        if (!empty($args['error_code'])) {
            $where_clauses[] = 'error_code = %s';
            $where_values[] = $args['error_code'];
        }

        if (!empty($args['order_id'])) {
            $where_clauses[] = 'order_id = %d';
            $where_values[] = $args['order_id'];
        }

        $where_sql = implode(' AND ', $where_clauses);
        $order_by = sanitize_sql_orderby($args['order_by'] . ' ' . $args['order']);

        $sql = "SELECT * FROM {$this->get_table_name('payment_failures')} 
                WHERE {$where_sql} 
                ORDER BY {$order_by} 
                LIMIT %d OFFSET %d";

        $where_values[] = $args['limit'];
        $where_values[] = $args['offset'];

        if (!empty($where_values)) {
            $sql = $wpdb->prepare($sql, $where_values);
        }

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Get payment failure statistics
     *
     * @param array $args Query arguments
     * @return array Statistics data
     */
    public function get_failure_statistics($args = array())
    {
        global $wpdb;

        $defaults = array(
            'date_from' => null,
            'date_to' => null,
            'gateway' => null,
            'group_by' => 'day' // day, hour, gateway, error_code
        );

        $args = wp_parse_args($args, $defaults);

        $where_clauses = array('1=1');
        $where_values = array();

        if (!empty($args['date_from'])) {
            $where_clauses[] = 'failed_at >= %s';
            $where_values[] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $where_clauses[] = 'failed_at <= %s';
            $where_values[] = $args['date_to'];
        }

        if (!empty($args['gateway'])) {
            $where_clauses[] = 'gateway = %s';
            $where_values[] = $args['gateway'];
        }

        $where_sql = implode(' AND ', $where_clauses);

        switch ($args['group_by']) {
            case 'hour':
                $group_by = "DATE_FORMAT(failed_at, '%Y-%m-%d %H:00:00')";
                $select_group = "DATE_FORMAT(failed_at, '%Y-%m-%d %H:00:00') as time_period";
                break;
            case 'gateway':
                $group_by = 'gateway';
                $select_group = 'gateway as group_key';
                break;
            case 'error_code':
                $group_by = 'error_code';
                $select_group = 'error_code as group_key';
                break;
            default: // day
                $group_by = "DATE(failed_at)";
                $select_group = "DATE(failed_at) as time_period";
                break;
        }

        $sql = "SELECT 
                    {$select_group},
                    COUNT(*) as failure_count,
                    SUM(amount) as total_amount,
                    COUNT(DISTINCT order_id) as unique_orders,
                    AVG(amount) as avg_amount
                FROM {$this->get_table_name('payment_failures')} 
                WHERE {$where_sql} 
                GROUP BY {$group_by}
                ORDER BY {$group_by}";

        if (!empty($where_values)) {
            $sql = $wpdb->prepare($sql, $where_values);
        }

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Insert alert log record
     *
     * @param array $data Alert data
     * @return int|false Insert ID or false on failure
     */
    public function insert_alert_log($data)
    {
        global $wpdb;

        $defaults = array(
            'alert_type' => '',
            'alert_level' => 'info',
            'message' => '',
            'sent_at' => current_time('mysql'),
            'recipient' => null,
            'delivery_status' => 'pending',
            'metadata' => null,
            'failure_ids' => null,
            'threshold_data' => null
        );

        $data = wp_parse_args($data, $defaults);

        // Serialize arrays
        foreach (array('metadata', 'threshold_data') as $field) {
            if (is_array($data[$field])) {
                $data[$field] = serialize($data[$field]);
            }
        }

        if (is_array($data['failure_ids'])) {
            $data['failure_ids'] = implode(',', $data['failure_ids']);
        }

        $result = $wpdb->insert(
            $this->get_table_name('alert_logs'),
            $data,
            array(
                '%s', // alert_type
                '%s', // alert_level
                '%s', // message
                '%s', // sent_at
                '%s', // recipient
                '%s', // delivery_status
                '%s', // metadata
                '%s', // failure_ids
                '%s'  // threshold_data
            )
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update alert delivery status
     *
     * @param int $alert_id Alert ID
     * @param string $status Delivery status
     * @return bool Success status
     */
    public function update_alert_status($alert_id, $status)
    {
        global $wpdb;

        return $wpdb->update(
            $this->get_table_name('alert_logs'),
            array('delivery_status' => $status),
            array('id' => $alert_id),
            array('%s'),
            array('%d')
        ) !== false;
    }

    /**
     * Get alert logs
     *
     * @param array $args Query arguments
     * @return array Alert log records
     */
    public function get_alert_logs($args = array())
    {
        global $wpdb;

        $defaults = array(
            'limit' => 50,
            'offset' => 0,
            'alert_type' => null,
            'alert_level' => null,
            'date_from' => null,
            'date_to' => null
        );

        $args = wp_parse_args($args, $defaults);

        $where_clauses = array('1=1');
        $where_values = array();

        if (!empty($args['alert_type'])) {
            $where_clauses[] = 'alert_type = %s';
            $where_values[] = $args['alert_type'];
        }

        if (!empty($args['alert_level'])) {
            $where_clauses[] = 'alert_level = %s';
            $where_values[] = $args['alert_level'];
        }

        if (!empty($args['date_from'])) {
            $where_clauses[] = 'sent_at >= %s';
            $where_values[] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $where_clauses[] = 'sent_at <= %s';
            $where_values[] = $args['date_to'];
        }

        $where_sql = implode(' AND ', $where_clauses);

        $sql = "SELECT * FROM {$this->get_table_name('alert_logs')} 
                WHERE {$where_sql} 
                ORDER BY sent_at DESC 
                LIMIT %d OFFSET %d";

        $where_values[] = $args['limit'];
        $where_values[] = $args['offset'];

        if (!empty($where_values)) {
            $sql = $wpdb->prepare($sql, $where_values);
        }

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Clean up old data based on retention settings
     *
     * @param int $retention_days Number of days to retain data
     * @return array Cleanup results
     */
    public function cleanup_old_data($retention_days = 90)
    {
        global $wpdb;

        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));

        $results = array(
            'payment_failures' => 0,
            'alert_logs' => 0
        );

        // Clean up payment failures
        $results['payment_failures'] = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->get_table_name('payment_failures')} WHERE failed_at < %s",
                $cutoff_date
            )
        );

        // Clean up alert logs
        $results['alert_logs'] = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->get_table_name('alert_logs')} WHERE sent_at < %s",
                $cutoff_date
            )
        );

        return $results;
    }

    /**
     * Get database table sizes
     *
     * @return array Table sizes
     */
    public function get_table_sizes()
    {
        global $wpdb;

        $results = array();

        foreach ($this->tables as $key => $table_name) {
            $size = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'size_mb' 
                     FROM information_schema.TABLES 
                     WHERE table_schema = %s AND table_name = %s",
                    DB_NAME,
                    $table_name
                )
            );

            $results[$key] = $size ? $size : 0;
        }

        return $results;
    }

    /**
     * Get record counts for all tables
     *
     * @return array Record counts
     */
    public function get_record_counts()
    {
        global $wpdb;

        $results = array();

        foreach ($this->tables as $key => $table_name) {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
            $results[$key] = $count ? intval($count) : 0;
        }

        return $results;
    }
}
