<?php

/**
 * Analytics Engine Class
 *
 * Handles data processing, metrics calculation, and analytics for CheckoutPulse AI
 *
 * @package CheckoutPulse_AI
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CheckoutPulse Analytics Engine Class
 *
 * @class CheckoutPulse_Analytics_Engine
 * @version 1.0.0
 */
class CheckoutPulse_Analytics_Engine
{

    /**
     * Single instance of the class
     *
     * @var CheckoutPulse_Analytics_Engine
     */
    protected static $_instance = null;

    /**
     * Database manager instance
     *
     * @var CheckoutPulse_Database_Manager
     */
    private $db_manager;

    /**
     * Cache duration in seconds
     *
     * @var int
     */
    private $cache_duration = 300; // 5 minutes

    /**
     * Main CheckoutPulse Analytics Engine Instance
     *
     * @static
     * @return CheckoutPulse_Analytics_Engine - Main instance
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
    }

    /**
     * Get comprehensive analytics data
     *
     * @param array $args Query arguments
     * @return array Analytics data
     */
    public function get_analytics($args = array())
    {
        $defaults = array(
            'timeframe' => '7d',
            'gateway' => '',
            'force_refresh' => false
        );

        $args = wp_parse_args($args, $defaults);

        $cache_key = 'cp_ai_analytics_' . md5(serialize($args));

        if (!$args['force_refresh']) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }

        $analytics = array(
            'overview' => $this->get_overview_metrics($args),
            'trends' => $this->get_trend_analysis($args),
            'gateways' => $this->get_gateway_analysis($args),
            'errors' => $this->get_error_analysis($args),
            'patterns' => $this->get_pattern_analysis($args),
            'recommendations' => $this->get_recommendations($args)
        );

        set_transient($cache_key, $analytics, $this->cache_duration);

        return $analytics;
    }

    /**
     * Get overview metrics
     *
     * @param array $args Query arguments
     * @return array Overview metrics
     */
    private function get_overview_metrics($args)
    {
        $date_ranges = $this->get_date_ranges($args['timeframe']);

        // Current period metrics
        $current_metrics = $this->calculate_period_metrics(
            $date_ranges['from'],
            $date_ranges['to'],
            $args['gateway']
        );

        // Previous period metrics for comparison
        $previous_metrics = $this->calculate_period_metrics(
            $date_ranges['prev_from'],
            $date_ranges['prev_to'],
            $args['gateway']
        );

        // Calculate trends
        $trends = array(
            'failures' => $this->calculate_percentage_change(
                $current_metrics['total_failures'],
                $previous_metrics['total_failures']
            ),
            'amount' => $this->calculate_percentage_change(
                $current_metrics['total_amount_lost'],
                $previous_metrics['total_amount_lost']
            ),
            'rate' => $this->calculate_absolute_change(
                $current_metrics['failure_rate'],
                $previous_metrics['failure_rate']
            ),
            'avg_amount' => $this->calculate_percentage_change(
                $current_metrics['avg_failure_amount'],
                $previous_metrics['avg_failure_amount']
            )
        );

        return array(
            'current' => $current_metrics,
            'previous' => $previous_metrics,
            'trends' => $trends,
            'period' => array(
                'current' => array('from' => $date_ranges['from'], 'to' => $date_ranges['to']),
                'previous' => array('from' => $date_ranges['prev_from'], 'to' => $date_ranges['prev_to'])
            )
        );
    }

    /**
     * Get trend analysis
     *
     * @param array $args Query arguments
     * @return array Trend data
     */
    private function get_trend_analysis($args)
    {
        $date_ranges = $this->get_date_ranges($args['timeframe']);

        $group_by = $this->get_group_by_period($args['timeframe']);

        $trend_args = array(
            'date_from' => $date_ranges['from'],
            'date_to' => $date_ranges['to'],
            'group_by' => $group_by
        );

        if (!empty($args['gateway'])) {
            $trend_args['gateway'] = $args['gateway'];
        }

        $raw_data = $this->db_manager->get_failure_statistics($trend_args);

        return array(
            'timeline' => $this->format_timeline_data($raw_data, $group_by),
            'moving_average' => $this->calculate_moving_average($raw_data),
            'seasonality' => $this->detect_seasonality($raw_data),
            'anomalies' => $this->detect_anomalies($raw_data)
        );
    }

    /**
     * Get gateway analysis
     *
     * @param array $args Query arguments
     * @return array Gateway analysis
     */
    private function get_gateway_analysis($args)
    {
        $date_ranges = $this->get_date_ranges($args['timeframe']);

        $gateway_stats = $this->db_manager->get_failure_statistics(array(
            'date_from' => $date_ranges['from'],
            'date_to' => $date_ranges['to'],
            'group_by' => 'gateway'
        ));

        $analysis = array();

        foreach ($gateway_stats as $stat) {
            $gateway_id = $stat['group_key'];

            // Get success rate data
            $success_data = $this->get_gateway_success_rate($gateway_id, $date_ranges['from'], $date_ranges['to']);

            $analysis[$gateway_id] = array(
                'gateway' => $gateway_id,
                'failure_count' => intval($stat['failure_count']),
                'total_amount' => floatval($stat['total_amount']),
                'avg_amount' => floatval($stat['avg_amount']),
                'unique_orders' => intval($stat['unique_orders']),
                'success_rate' => $success_data['success_rate'],
                'total_attempts' => $success_data['total_attempts'],
                'performance_score' => $this->calculate_gateway_performance_score($stat, $success_data),
                'status' => $this->determine_gateway_status($stat, $success_data)
            );
        }

        // Sort by performance score
        usort($analysis, function ($a, $b) {
            return $b['performance_score'] <=> $a['performance_score'];
        });

        return array(
            'gateways' => $analysis,
            'summary' => $this->calculate_gateway_summary($analysis),
            'recommendations' => $this->get_gateway_recommendations($analysis)
        );
    }

    /**
     * Get error analysis
     *
     * @param array $args Query arguments
     * @return array Error analysis
     */
    private function get_error_analysis($args)
    {
        $date_ranges = $this->get_date_ranges($args['timeframe']);

        $error_stats = $this->db_manager->get_failure_statistics(array(
            'date_from' => $date_ranges['from'],
            'date_to' => $date_ranges['to'],
            'gateway' => $args['gateway'],
            'group_by' => 'error_code'
        ));

        $analysis = array();

        foreach ($error_stats as $stat) {
            if (empty($stat['group_key'])) {
                continue;
            }

            $error_code = $stat['group_key'];

            $analysis[] = array(
                'error_code' => $error_code,
                'count' => intval($stat['failure_count']),
                'total_amount' => floatval($stat['total_amount']),
                'avg_amount' => floatval($stat['avg_amount']),
                'percentage' => 0, // Will be calculated below
                'severity' => $this->determine_error_severity($error_code, intval($stat['failure_count'])),
                'description' => $this->get_error_description($error_code),
                'recommended_action' => $this->get_error_recommended_action($error_code)
            );
        }

        // Calculate percentages
        $total_errors = array_sum(array_column($analysis, 'count'));
        foreach ($analysis as &$error) {
            $error['percentage'] = $total_errors > 0 ? round(($error['count'] / $total_errors) * 100, 1) : 0;
        }

        // Sort by count
        usort($analysis, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        return array(
            'errors' => array_slice($analysis, 0, 10), // Top 10 errors
            'total_unique_errors' => count($analysis),
            'error_diversity_index' => $this->calculate_error_diversity($analysis),
            'critical_errors' => array_filter($analysis, function ($error) {
                return $error['severity'] === 'critical';
            })
        );
    }

    /**
     * Get pattern analysis
     *
     * @param array $args Query arguments
     * @return array Pattern analysis
     */
    private function get_pattern_analysis($args)
    {
        $date_ranges = $this->get_date_ranges($args['timeframe']);

        return array(
            'time_patterns' => $this->analyze_time_patterns($date_ranges['from'], $date_ranges['to'], $args['gateway']),
            'amount_patterns' => $this->analyze_amount_patterns($date_ranges['from'], $date_ranges['to'], $args['gateway']),
            'customer_patterns' => $this->analyze_customer_patterns($date_ranges['from'], $date_ranges['to'], $args['gateway']),
            'geographic_patterns' => $this->analyze_geographic_patterns($date_ranges['from'], $date_ranges['to'], $args['gateway'])
        );
    }

    /**
     * Get recommendations based on analysis
     *
     * @param array $args Query arguments
     * @return array Recommendations
     */
    private function get_recommendations($args)
    {
        $recommendations = array();

        // Get recent data for analysis
        $date_ranges = $this->get_date_ranges('24h');
        $recent_failures = $this->db_manager->get_payment_failures(array(
            'date_from' => $date_ranges['from'],
            'date_to' => $date_ranges['to'],
            'limit' => 100
        ));

        // Analyze patterns and generate recommendations
        $recommendations = array_merge(
            $recommendations,
            $this->generate_performance_recommendations($recent_failures),
            $this->generate_configuration_recommendations($recent_failures),
            $this->generate_monitoring_recommendations($recent_failures)
        );

        // Prioritize recommendations
        usort($recommendations, function ($a, $b) {
            $priority_order = array('critical' => 3, 'high' => 2, 'medium' => 1, 'low' => 0);
            return $priority_order[$b['priority']] <=> $priority_order[$a['priority']];
        });

        return array_slice($recommendations, 0, 5); // Top 5 recommendations
    }

    /**
     * Calculate period metrics
     *
     * @param string $from Start date
     * @param string $to End date
     * @param string $gateway Gateway filter
     * @return array Metrics
     */
    private function calculate_period_metrics($from, $to, $gateway = '')
    {
        global $wpdb;

        $where_clause = "failed_at BETWEEN %s AND %s";
        $params = array($from, $to);

        if (!empty($gateway)) {
            $where_clause .= " AND gateway = %s";
            $params[] = $gateway;
        }

        $sql = "SELECT 
                    COUNT(*) as total_failures,
                    SUM(amount) as total_amount_lost,
                    AVG(amount) as avg_failure_amount,
                    COUNT(DISTINCT order_id) as unique_failed_orders,
                    COUNT(DISTINCT customer_id) as unique_customers
                FROM {$this->db_manager->get_table_name('payment_failures')}
                WHERE {$where_clause}";

        $result = $wpdb->get_row($wpdb->prepare($sql, $params), ARRAY_A);

        // Get total orders for failure rate calculation
        $total_orders = $this->get_total_orders($from, $to, $gateway);
        $failure_rate = $total_orders > 0 ? round((intval($result['total_failures']) / $total_orders) * 100, 2) : 0;

        return array(
            'total_failures' => intval($result['total_failures'] ?? 0),
            'total_amount_lost' => floatval($result['total_amount_lost'] ?? 0),
            'avg_failure_amount' => floatval($result['avg_failure_amount'] ?? 0),
            'unique_failed_orders' => intval($result['unique_failed_orders'] ?? 0),
            'unique_customers' => intval($result['unique_customers'] ?? 0),
            'failure_rate' => $failure_rate,
            'total_orders' => $total_orders
        );
    }

    /**
     * Get total orders for a period
     *
     * @param string $from Start date
     * @param string $to End date
     * @param string $gateway Gateway filter
     * @return int Total orders
     */
    private function get_total_orders($from, $to, $gateway = '')
    {
        global $wpdb;

        $where_gateway = '';
        $params = array($from, $to);

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

        $result = $wpdb->get_var($wpdb->prepare($sql, $params));

        return intval($result ?? 0);
    }

    /**
     * Get date ranges based on timeframe
     *
     * @param string $timeframe Timeframe (1h, 24h, 7d, 30d)
     * @return array Date ranges
     */
    private function get_date_ranges($timeframe)
    {
        $now = current_time('timestamp');

        switch ($timeframe) {
            case '1h':
                $seconds = HOUR_IN_SECONDS;
                break;
            case '24h':
                $seconds = DAY_IN_SECONDS;
                break;
            case '7d':
                $seconds = 7 * DAY_IN_SECONDS;
                break;
            case '30d':
                $seconds = 30 * DAY_IN_SECONDS;
                break;
            default:
                $seconds = 7 * DAY_IN_SECONDS;
        }

        return array(
            'from' => date('Y-m-d H:i:s', $now - $seconds),
            'to' => current_time('mysql'),
            'prev_from' => date('Y-m-d H:i:s', $now - (2 * $seconds)),
            'prev_to' => date('Y-m-d H:i:s', $now - $seconds)
        );
    }

    /**
     * Get group by period based on timeframe
     *
     * @param string $timeframe Timeframe
     * @return string Group by period
     */
    private function get_group_by_period($timeframe)
    {
        switch ($timeframe) {
            case '1h':
            case '24h':
                return 'hour';
            case '7d':
            case '30d':
                return 'day';
            default:
                return 'day';
        }
    }

    /**
     * Calculate percentage change
     *
     * @param float $current Current value
     * @param float $previous Previous value
     * @return array Change data
     */
    private function calculate_percentage_change($current, $previous)
    {
        if ($previous == 0) {
            return array(
                'value' => $current > 0 ? 100 : 0,
                'direction' => $current > 0 ? 'up' : 'neutral',
                'type' => 'percentage'
            );
        }

        $change = (($current - $previous) / $previous) * 100;

        return array(
            'value' => round(abs($change), 1),
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'neutral'),
            'type' => 'percentage'
        );
    }

    /**
     * Calculate absolute change
     *
     * @param float $current Current value
     * @param float $previous Previous value
     * @return array Change data
     */
    private function calculate_absolute_change($current, $previous)
    {
        $change = $current - $previous;

        return array(
            'value' => round(abs($change), 2),
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'neutral'),
            'type' => 'absolute'
        );
    }

    /**
     * Format timeline data
     *
     * @param array $raw_data Raw statistics data
     * @param string $group_by Group by period
     * @return array Formatted timeline data
     */
    private function format_timeline_data($raw_data, $group_by)
    {
        $formatted = array();

        foreach ($raw_data as $data) {
            $period = $data['time_period'] ?? $data['group_key'];

            $formatted[] = array(
                'period' => $period,
                'failures' => intval($data['failure_count']),
                'amount' => floatval($data['total_amount']),
                'unique_orders' => intval($data['unique_orders']),
                'avg_amount' => floatval($data['avg_amount'])
            );
        }

        return $formatted;
    }

    /**
     * Calculate moving average
     *
     * @param array $data Data points
     * @param int $window Window size
     * @return array Moving average data
     */
    private function calculate_moving_average($data, $window = 3)
    {
        if (count($data) < $window) {
            return array();
        }

        $moving_avg = array();
        $values = array_column($data, 'failure_count');

        for ($i = $window - 1; $i < count($values); $i++) {
            $sum = 0;
            for ($j = 0; $j < $window; $j++) {
                $sum += $values[$i - $j];
            }
            $moving_avg[] = round($sum / $window, 2);
        }

        return $moving_avg;
    }

    /**
     * Detect seasonality patterns
     *
     * @param array $data Data points
     * @return array Seasonality analysis
     */
    private function detect_seasonality($data)
    {
        // Simple seasonality detection based on hour/day patterns
        $patterns = array();

        foreach ($data as $point) {
            if (isset($point['time_period'])) {
                $timestamp = strtotime($point['time_period']);
                $hour = date('H', $timestamp);
                $day = date('w', $timestamp);

                if (!isset($patterns['hourly'][$hour])) {
                    $patterns['hourly'][$hour] = 0;
                }
                if (!isset($patterns['daily'][$day])) {
                    $patterns['daily'][$day] = 0;
                }

                $patterns['hourly'][$hour] += intval($point['failure_count']);
                $patterns['daily'][$day] += intval($point['failure_count']);
            }
        }

        return $patterns;
    }

    /**
     * Detect anomalies in data
     *
     * @param array $data Data points
     * @return array Anomalies detected
     */
    private function detect_anomalies($data)
    {
        $values = array_column($data, 'failure_count');

        if (count($values) < 3) {
            return array();
        }

        $mean = array_sum($values) / count($values);
        $variance = 0;

        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }

        $std_dev = sqrt($variance / count($values));
        $threshold = $mean + (2 * $std_dev); // 2 standard deviations

        $anomalies = array();
        foreach ($data as $index => $point) {
            if (intval($point['failure_count']) > $threshold) {
                $anomalies[] = array(
                    'period' => $point['time_period'] ?? $point['group_key'],
                    'value' => intval($point['failure_count']),
                    'threshold' => round($threshold, 1),
                    'severity' => intval($point['failure_count']) > ($mean + 3 * $std_dev) ? 'high' : 'medium'
                );
            }
        }

        return $anomalies;
    }

    /**
     * Get gateway success rate
     *
     * @param string $gateway Gateway ID
     * @param string $from Start date
     * @param string $to End date
     * @return array Success rate data
     */
    private function get_gateway_success_rate($gateway, $from, $to)
    {
        $total_orders = $this->get_total_orders($from, $to, $gateway);

        global $wpdb;
        $failed_orders = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT order_id) FROM {$this->db_manager->get_table_name('payment_failures')}
             WHERE gateway = %s AND failed_at BETWEEN %s AND %s",
            $gateway,
            $from,
            $to
        ));

        $successful_orders = $total_orders - intval($failed_orders);
        $success_rate = $total_orders > 0 ? round(($successful_orders / $total_orders) * 100, 2) : 0;

        return array(
            'success_rate' => $success_rate,
            'total_attempts' => $total_orders,
            'successful_orders' => $successful_orders,
            'failed_orders' => intval($failed_orders)
        );
    }

    /**
     * Calculate gateway performance score
     *
     * @param array $failure_stats Failure statistics
     * @param array $success_data Success rate data
     * @return float Performance score (0-100)
     */
    private function calculate_gateway_performance_score($failure_stats, $success_data)
    {
        $success_rate = $success_data['success_rate'];
        $failure_count = intval($failure_stats['failure_count']);
        $avg_amount = floatval($failure_stats['avg_amount']);

        // Base score on success rate (0-100)
        $score = $success_rate;

        // Penalty for high failure count
        if ($failure_count > 50) {
            $score -= 10;
        } elseif ($failure_count > 20) {
            $score -= 5;
        }

        // Penalty for high-value failures
        if ($avg_amount > 100) {
            $score -= 5;
        }

        return max(0, min(100, $score));
    }

    /**
     * Determine gateway status
     *
     * @param array $failure_stats Failure statistics
     * @param array $success_data Success rate data
     * @return string Status (excellent, good, fair, poor, critical)
     */
    private function determine_gateway_status($failure_stats, $success_data)
    {
        $success_rate = $success_data['success_rate'];
        $failure_count = intval($failure_stats['failure_count']);

        if ($success_rate >= 98 && $failure_count < 5) {
            return 'excellent';
        } elseif ($success_rate >= 95 && $failure_count < 20) {
            return 'good';
        } elseif ($success_rate >= 90 && $failure_count < 50) {
            return 'fair';
        } elseif ($success_rate >= 80) {
            return 'poor';
        } else {
            return 'critical';
        }
    }

    /**
     * Calculate gateway summary
     *
     * @param array $gateways Gateway analysis data
     * @return array Summary statistics
     */
    private function calculate_gateway_summary($gateways)
    {
        if (empty($gateways)) {
            return array();
        }

        $total_failures = array_sum(array_column($gateways, 'failure_count'));
        $total_amount = array_sum(array_column($gateways, 'total_amount'));
        $avg_performance = array_sum(array_column($gateways, 'performance_score')) / count($gateways);

        $status_counts = array_count_values(array_column($gateways, 'status'));

        return array(
            'total_gateways' => count($gateways),
            'total_failures' => $total_failures,
            'total_amount_lost' => $total_amount,
            'avg_performance_score' => round($avg_performance, 1),
            'status_distribution' => $status_counts,
            'best_performing' => reset($gateways)['gateway'] ?? null,
            'worst_performing' => end($gateways)['gateway'] ?? null
        );
    }

    /**
     * Get gateway recommendations
     *
     * @param array $gateways Gateway analysis data
     * @return array Recommendations
     */
    private function get_gateway_recommendations($gateways)
    {
        $recommendations = array();

        foreach ($gateways as $gateway) {
            if ($gateway['status'] === 'critical') {
                $recommendations[] = array(
                    'type' => 'gateway_critical',
                    'priority' => 'critical',
                    'title' => "Critical: {$gateway['gateway']} gateway issues",
                    'description' => "Gateway {$gateway['gateway']} has a very low success rate and requires immediate attention.",
                    'action' => 'Review gateway configuration and contact provider support'
                );
            } elseif ($gateway['status'] === 'poor' && $gateway['failure_count'] > 20) {
                $recommendations[] = array(
                    'type' => 'gateway_poor',
                    'priority' => 'high',
                    'title' => "Poor performance: {$gateway['gateway']} gateway",
                    'description' => "Gateway {$gateway['gateway']} has elevated failure rates that may impact revenue.",
                    'action' => 'Monitor closely and consider backup payment options'
                );
            }
        }

        return $recommendations;
    }

    /**
     * Determine error severity
     *
     * @param string $error_code Error code
     * @param int $count Error count
     * @return string Severity level
     */
    private function determine_error_severity($error_code, $count)
    {
        // Define critical error patterns
        $critical_patterns = array('gateway_down', 'connection_failed', 'timeout', 'server_error');
        $high_patterns = array('card_declined', 'insufficient_funds', 'invalid_card');

        $error_lower = strtolower($error_code);

        foreach ($critical_patterns as $pattern) {
            if (strpos($error_lower, $pattern) !== false) {
                return 'critical';
            }
        }

        foreach ($high_patterns as $pattern) {
            if (strpos($error_lower, $pattern) !== false) {
                return $count > 10 ? 'high' : 'medium';
            }
        }

        return $count > 20 ? 'high' : ($count > 5 ? 'medium' : 'low');
    }

    /**
     * Get error description
     *
     * @param string $error_code Error code
     * @return string Error description
     */
    private function get_error_description($error_code)
    {
        $descriptions = array(
            'card_declined' => 'Customer\'s card was declined by the bank',
            'insufficient_funds' => 'Customer has insufficient funds',
            'invalid_card' => 'Invalid card number or details provided',
            'expired_card' => 'Customer\'s card has expired',
            'gateway_timeout' => 'Payment gateway response timeout',
            'connection_failed' => 'Connection to payment gateway failed',
            'authentication_failed' => 'Gateway authentication failure'
        );

        return $descriptions[$error_code] ?? 'Unknown payment error';
    }

    /**
     * Get error recommended action
     *
     * @param string $error_code Error code
     * @return string Recommended action
     */
    private function get_error_recommended_action($error_code)
    {
        $actions = array(
            'card_declined' => 'Contact customer to verify card details or try different payment method',
            'insufficient_funds' => 'Suggest customer tries a different payment method',
            'invalid_card' => 'Improve card validation on checkout form',
            'expired_card' => 'Add expiry date validation and customer notification',
            'gateway_timeout' => 'Check gateway configuration and network connectivity',
            'connection_failed' => 'Verify gateway credentials and API endpoints',
            'authentication_failed' => 'Review gateway authentication settings'
        );

        return $actions[$error_code] ?? 'Review error logs and contact gateway support';
    }

    /**
     * Calculate error diversity index
     *
     * @param array $errors Error data
     * @return float Diversity index (0-1)
     */
    private function calculate_error_diversity($errors)
    {
        if (empty($errors)) {
            return 0;
        }

        $total_errors = array_sum(array_column($errors, 'count'));
        $unique_errors = count($errors);

        // Shannon diversity index
        $diversity = 0;
        foreach ($errors as $error) {
            $proportion = $error['count'] / $total_errors;
            if ($proportion > 0) {
                $diversity -= $proportion * log($proportion);
            }
        }

        // Normalize to 0-1 scale
        $max_diversity = log($unique_errors);
        return $max_diversity > 0 ? $diversity / $max_diversity : 0;
    }

    /**
     * Analyze time patterns
     *
     * @param string $from Start date
     * @param string $to End date
     * @param string $gateway Gateway filter
     * @return array Time pattern analysis
     */
    private function analyze_time_patterns($from, $to, $gateway = '')
    {
        global $wpdb;

        $where_clause = "failed_at BETWEEN %s AND %s";
        $params = array($from, $to);

        if (!empty($gateway)) {
            $where_clause .= " AND gateway = %s";
            $params[] = $gateway;
        }

        // Hour of day analysis
        $hourly_sql = "SELECT HOUR(failed_at) as hour, COUNT(*) as count
                       FROM {$this->db_manager->get_table_name('payment_failures')}
                       WHERE {$where_clause}
                       GROUP BY HOUR(failed_at)
                       ORDER BY hour";

        $hourly_data = $wpdb->get_results($wpdb->prepare($hourly_sql, $params), ARRAY_A);

        // Day of week analysis
        $daily_sql = "SELECT DAYOFWEEK(failed_at) as day_of_week, COUNT(*) as count
                      FROM {$this->db_manager->get_table_name('payment_failures')}
                      WHERE {$where_clause}
                      GROUP BY DAYOFWEEK(failed_at)
                      ORDER BY day_of_week";

        $daily_data = $wpdb->get_results($wpdb->prepare($daily_sql, $params), ARRAY_A);

        return array(
            'hourly' => $hourly_data,
            'daily' => $daily_data,
            'peak_hour' => $this->find_peak_time($hourly_data),
            'peak_day' => $this->find_peak_day($daily_data)
        );
    }

    /**
     * Analyze amount patterns
     *
     * @param string $from Start date
     * @param string $to End date
     * @param string $gateway Gateway filter
     * @return array Amount pattern analysis
     */
    private function analyze_amount_patterns($from, $to, $gateway = '')
    {
        global $wpdb;

        $where_clause = "failed_at BETWEEN %s AND %s";
        $params = array($from, $to);

        if (!empty($gateway)) {
            $where_clause .= " AND gateway = %s";
            $params[] = $gateway;
        }

        // Amount range analysis
        $sql = "SELECT 
                    CASE 
                        WHEN amount < 25 THEN '0-25'
                        WHEN amount < 50 THEN '25-50'
                        WHEN amount < 100 THEN '50-100'
                        WHEN amount < 250 THEN '100-250'
                        WHEN amount < 500 THEN '250-500'
                        ELSE '500+'
                    END as amount_range,
                    COUNT(*) as count,
                    SUM(amount) as total_amount
                FROM {$this->db_manager->get_table_name('payment_failures')}
                WHERE {$where_clause}
                GROUP BY amount_range
                ORDER BY count DESC";

        $amount_data = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        return array(
            'ranges' => $amount_data,
            'avg_failure_amount' => $this->calculate_avg_failure_amount($from, $to, $gateway),
            'high_value_threshold' => $this->determine_high_value_threshold($amount_data)
        );
    }

    /**
     * Analyze customer patterns
     *
     * @param string $from Start date
     * @param string $to End date
     * @param string $gateway Gateway filter
     * @return array Customer pattern analysis
     */
    private function analyze_customer_patterns($from, $to, $gateway = '')
    {
        global $wpdb;

        $where_clause = "failed_at BETWEEN %s AND %s";
        $params = array($from, $to);

        if (!empty($gateway)) {
            $where_clause .= " AND gateway = %s";
            $params[] = $gateway;
        }

        // Repeat customer failures
        $sql = "SELECT customer_id, COUNT(*) as failure_count
                FROM {$this->db_manager->get_table_name('payment_failures')}
                WHERE {$where_clause} AND customer_id IS NOT NULL AND customer_id > 0
                GROUP BY customer_id
                HAVING failure_count > 1
                ORDER BY failure_count DESC
                LIMIT 10";

        $repeat_failures = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        return array(
            'repeat_failures' => $repeat_failures,
            'total_unique_customers' => $this->count_unique_customers($from, $to, $gateway),
            'guest_vs_registered' => $this->analyze_guest_vs_registered($from, $to, $gateway)
        );
    }

    /**
     * Analyze geographic patterns
     *
     * @param string $from Start date
     * @param string $to End date
     * @param string $gateway Gateway filter
     * @return array Geographic pattern analysis
     */
    private function analyze_geographic_patterns($from, $to, $gateway = '')
    {
        // This would require additional data collection (country, region)
        // For now, return placeholder structure
        return array(
            'countries' => array(),
            'regions' => array(),
            'note' => 'Geographic data collection not implemented yet'
        );
    }

    /**
     * Generate performance recommendations
     *
     * @param array $recent_failures Recent failure data
     * @return array Performance recommendations
     */
    private function generate_performance_recommendations($recent_failures)
    {
        $recommendations = array();

        // Analyze failure frequency
        if (count($recent_failures) > 20) {
            $recommendations[] = array(
                'type' => 'high_failure_rate',
                'priority' => 'high',
                'title' => 'High failure rate detected',
                'description' => 'Your store has experienced ' . count($recent_failures) . ' payment failures in the last 24 hours.',
                'action' => 'Review payment gateway configuration and consider backup payment methods'
            );
        }

        // Analyze error patterns
        $error_codes = array_column($recent_failures, 'error_code');
        $error_counts = array_count_values(array_filter($error_codes));

        foreach ($error_counts as $error => $count) {
            if ($count > 5) {
                $recommendations[] = array(
                    'type' => 'recurring_error',
                    'priority' => 'medium',
                    'title' => "Recurring error: {$error}",
                    'description' => "Error '{$error}' has occurred {$count} times recently.",
                    'action' => $this->get_error_recommended_action($error)
                );
            }
        }

        return $recommendations;
    }

    /**
     * Generate configuration recommendations
     *
     * @param array $recent_failures Recent failure data
     * @return array Configuration recommendations
     */
    private function generate_configuration_recommendations($recent_failures)
    {
        $recommendations = array();

        // Check for gateway diversity
        $gateways = array_unique(array_column($recent_failures, 'gateway'));

        if (count($gateways) == 1 && count($recent_failures) > 10) {
            $recommendations[] = array(
                'type' => 'single_gateway',
                'priority' => 'medium',
                'title' => 'Consider multiple payment gateways',
                'description' => 'All failures are from a single gateway. Adding backup payment options could improve success rates.',
                'action' => 'Configure additional payment gateways as backup options'
            );
        }

        return $recommendations;
    }

    /**
     * Generate monitoring recommendations
     *
     * @param array $recent_failures Recent failure data
     * @return array Monitoring recommendations
     */
    private function generate_monitoring_recommendations($recent_failures)
    {
        $recommendations = array();

        // Check alert configuration
        $alert_enabled = get_option('checkoutpulse_ai_email_notifications', 'yes');

        if ($alert_enabled !== 'yes' && count($recent_failures) > 5) {
            $recommendations[] = array(
                'type' => 'enable_alerts',
                'priority' => 'low',
                'title' => 'Enable email alerts',
                'description' => 'Email notifications are disabled. Enable them to get notified of payment issues in real-time.',
                'action' => 'Go to CheckoutPulse AI settings and enable email notifications'
            );
        }

        return $recommendations;
    }

    /**
     * Helper methods for pattern analysis
     */

    private function find_peak_time($hourly_data)
    {
        if (empty($hourly_data)) return null;

        $max_count = 0;
        $peak_hour = 0;

        foreach ($hourly_data as $data) {
            if ($data['count'] > $max_count) {
                $max_count = $data['count'];
                $peak_hour = $data['hour'];
            }
        }

        return array('hour' => $peak_hour, 'count' => $max_count);
    }

    private function find_peak_day($daily_data)
    {
        if (empty($daily_data)) return null;

        $days = array('Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday');
        $max_count = 0;
        $peak_day = 1;

        foreach ($daily_data as $data) {
            if ($data['count'] > $max_count) {
                $max_count = $data['count'];
                $peak_day = $data['day_of_week'];
            }
        }

        return array(
            'day_number' => $peak_day,
            'day_name' => $days[$peak_day - 1] ?? 'Unknown',
            'count' => $max_count
        );
    }

    private function calculate_avg_failure_amount($from, $to, $gateway = '')
    {
        global $wpdb;

        $where_clause = "failed_at BETWEEN %s AND %s";
        $params = array($from, $to);

        if (!empty($gateway)) {
            $where_clause .= " AND gateway = %s";
            $params[] = $gateway;
        }

        $sql = "SELECT AVG(amount) as avg_amount
                FROM {$this->db_manager->get_table_name('payment_failures')}
                WHERE {$where_clause}";

        return floatval($wpdb->get_var($wpdb->prepare($sql, $params)) ?? 0);
    }

    private function determine_high_value_threshold($amount_data)
    {
        $total_failures = array_sum(array_column($amount_data, 'count'));
        $high_value_failures = 0;

        foreach ($amount_data as $range) {
            if (in_array($range['amount_range'], array('250-500', '500+'))) {
                $high_value_failures += $range['count'];
            }
        }

        $high_value_percentage = $total_failures > 0 ? ($high_value_failures / $total_failures) * 100 : 0;

        return $high_value_percentage > 20 ? 250 : 500;
    }

    private function count_unique_customers($from, $to, $gateway = '')
    {
        global $wpdb;

        $where_clause = "failed_at BETWEEN %s AND %s";
        $params = array($from, $to);

        if (!empty($gateway)) {
            $where_clause .= " AND gateway = %s";
            $params[] = $gateway;
        }

        $sql = "SELECT COUNT(DISTINCT customer_id) as unique_customers
                FROM {$this->db_manager->get_table_name('payment_failures')}
                WHERE {$where_clause} AND customer_id IS NOT NULL AND customer_id > 0";

        return intval($wpdb->get_var($wpdb->prepare($sql, $params)) ?? 0);
    }

    private function analyze_guest_vs_registered($from, $to, $gateway = '')
    {
        global $wpdb;

        $where_clause = "failed_at BETWEEN %s AND %s";
        $params = array($from, $to);

        if (!empty($gateway)) {
            $where_clause .= " AND gateway = %s";
            $params[] = $gateway;
        }

        $sql = "SELECT 
                    CASE WHEN customer_id IS NULL OR customer_id = 0 THEN 'guest' ELSE 'registered' END as customer_type,
                    COUNT(*) as count
                FROM {$this->db_manager->get_table_name('payment_failures')}
                WHERE {$where_clause}
                GROUP BY customer_type";

        $result = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        $analysis = array('guest' => 0, 'registered' => 0);
        foreach ($result as $row) {
            $analysis[$row['customer_type']] = intval($row['count']);
        }

        return $analysis;
    }
}
