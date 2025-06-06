<?php

/**
 * Dashboard Controller Class
 *
 * Handles admin dashboard interface and functionality
 *
 * @package CheckoutPulse_AI
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CheckoutPulse Dashboard Controller Class
 *
 * @class CheckoutPulse_Dashboard_Controller
 * @version 1.0.0
 */
class CheckoutPulse_Dashboard_Controller
{

    /**
     * Single instance of the class
     *
     * @var CheckoutPulse_Dashboard_Controller
     */
    protected static $_instance = null;

    /**
     * Core instance
     *
     * @var CheckoutPulse_Core
     */
    private $core;

    /**
     * Analytics engine instance
     *
     * @var CheckoutPulse_Analytics_Engine
     */
    private $analytics;

    /**
     * Settings manager instance
     *
     * @var CheckoutPulse_Settings_Manager
     */
    private $settings;

    /**
     * Main CheckoutPulse Dashboard Controller Instance
     *
     * @static
     * @return CheckoutPulse_Dashboard_Controller - Main instance
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
        $this->core = CheckoutPulse_Core::instance();
        $this->analytics = CheckoutPulse_Analytics_Engine::instance();
        $this->settings = CheckoutPulse_Settings_Manager::instance();

        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_init', array($this, 'handle_admin_actions'));

        // Add dashboard widget
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));

        // Add admin bar menu
        add_action('admin_bar_menu', array($this, 'add_admin_bar_menu'), 100);
    }

    /**
     * Add admin menu pages
     */
    public function add_admin_menu()
    {
        // Main menu page
        add_menu_page(
            __('CheckoutPulse AI', 'checkoutpulse-ai'),
            __('CheckoutPulse AI', 'checkoutpulse-ai'),
            'manage_woocommerce',
            'checkoutpulse-ai',
            array($this, 'render_dashboard_page'),
            'dashicons-chart-line',
            25
        );

        // Dashboard submenu
        add_submenu_page(
            'checkoutpulse-ai',
            __('Dashboard', 'checkoutpulse-ai'),
            __('Dashboard', 'checkoutpulse-ai'),
            'manage_woocommerce',
            'checkoutpulse-ai',
            array($this, 'render_dashboard_page')
        );

        // Analytics submenu
        add_submenu_page(
            'checkoutpulse-ai',
            __('Analytics', 'checkoutpulse-ai'),
            __('Analytics', 'checkoutpulse-ai'),
            'manage_woocommerce',
            'checkoutpulse-ai-analytics',
            array($this, 'render_analytics_page')
        );

        // Alerts submenu
        add_submenu_page(
            'checkoutpulse-ai',
            __('Alerts', 'checkoutpulse-ai'),
            __('Alerts', 'checkoutpulse-ai'),
            'manage_woocommerce',
            'checkoutpulse-ai-alerts',
            array($this, 'render_alerts_page')
        );

        // Settings submenu
        add_submenu_page(
            'checkoutpulse-ai',
            __('Settings', 'checkoutpulse-ai'),
            __('Settings', 'checkoutpulse-ai'),
            'manage_options',
            'checkoutpulse-ai-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook)
    {
        // Only load on CheckoutPulse AI pages
        if (strpos($hook, 'checkoutpulse-ai') === false) {
            return;
        }

        // Enqueue styles
        wp_enqueue_style(
            'checkoutpulse-ai-admin',
            CHECKOUTPULSE_AI_PLUGIN_URL . 'admin/css/admin-dashboard.css',
            array(),
            CHECKOUTPULSE_AI_VERSION
        );

        // Enqueue scripts
        wp_enqueue_script(
            'checkoutpulse-ai-admin',
            CHECKOUTPULSE_AI_PLUGIN_URL . 'admin/js/dashboard-charts.js',
            array('jquery', 'wp-util'),
            CHECKOUTPULSE_AI_VERSION,
            true
        );

        // Enqueue Chart.js
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js',
            array(),
            '3.9.1',
            true
        );

        // Localize script
        wp_localize_script('checkoutpulse-ai-admin', 'checkoutpulseAI', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('checkoutpulse_ai_nonce'),
            'strings' => array(
                'loading' => __('Loading...', 'checkoutpulse-ai'),
                'error' => __('An error occurred', 'checkoutpulse-ai'),
                'confirm_reset' => __('Are you sure you want to reset these settings?', 'checkoutpulse-ai'),
                'settings_saved' => __('Settings saved successfully', 'checkoutpulse-ai'),
                'test_alert_sent' => __('Test alert sent successfully', 'checkoutpulse-ai')
            )
        ));
    }

    /**
     * Handle admin actions
     */
    public function handle_admin_actions()
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // Handle manual data cleanup
        if (isset($_POST['cp_cleanup_data']) && wp_verify_nonce($_POST['_wpnonce'], 'cp_cleanup_data')) {
            $this->handle_data_cleanup();
        }

        // Handle export actions
        if (isset($_GET['cp_export']) && wp_verify_nonce($_GET['_wpnonce'], 'cp_export')) {
            $this->handle_export_action($_GET['cp_export']);
        }
    }

    /**
     * Render main dashboard page
     */
    public function render_dashboard_page()
    {
        $timeframe = sanitize_text_field($_GET['timeframe'] ?? '24h');
        $gateway = sanitize_text_field($_GET['gateway'] ?? '');

        $dashboard_data = $this->core->get_dashboard_data($timeframe, $gateway);
        $available_gateways = $this->get_available_gateways();

        include CHECKOUTPULSE_AI_PLUGIN_DIR . 'admin/partials/dashboard-main.php';
    }

    /**
     * Render analytics page
     */
    public function render_analytics_page()
    {
        $timeframe = sanitize_text_field($_GET['timeframe'] ?? '7d');
        $gateway = sanitize_text_field($_GET['gateway'] ?? '');

        $analytics_data = $this->analytics->get_analytics(array(
            'timeframe' => $timeframe,
            'gateway' => $gateway
        ));

        $available_gateways = $this->get_available_gateways();

        include CHECKOUTPULSE_AI_PLUGIN_DIR . 'admin/partials/analytics-page.php';
    }

    /**
     * Render alerts page
     */
    public function render_alerts_page()
    {
        $db_manager = CheckoutPulse_Database_Manager::instance();
        $alert_system = CheckoutPulse_Alert_System::instance();

        $recent_alerts = $db_manager->get_alert_logs(array(
            'limit' => 50,
            'date_from' => date('Y-m-d', strtotime('-30 days'))
        ));

        $alert_config = $alert_system->get_alert_config();

        include CHECKOUTPULSE_AI_PLUGIN_DIR . 'admin/partials/alerts-page.php';
    }

    /**
     * Render settings page
     */
    public function render_settings_page()
    {
        $settings_groups = $this->settings->get_settings_groups();
        $current_settings = $this->settings->get_all_settings();
        $active_tab = sanitize_text_field($_GET['tab'] ?? 'general');

        include CHECKOUTPULSE_AI_PLUGIN_DIR . 'admin/partials/settings-page.php';
    }

    /**
     * Add dashboard widget
     */
    public function add_dashboard_widget()
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        wp_add_dashboard_widget(
            'checkoutpulse_ai_widget',
            __('Payment Failures Summary', 'checkoutpulse-ai'),
            array($this, 'render_dashboard_widget')
        );
    }

    /**
     * Render dashboard widget
     */
    public function render_dashboard_widget()
    {
        $dashboard_data = $this->core->get_dashboard_data('24h');
        $kpis = $dashboard_data['kpis'];

        echo '<div class="checkoutpulse-widget">';
        echo '<div class="widget-stats">';

        echo '<div class="stat-item">';
        echo '<span class="stat-label">' . __('Failures (24h)', 'checkoutpulse-ai') . '</span>';
        echo '<span class="stat-value failures">' . number_format($kpis['total_failures']) . '</span>';
        if ($kpis['trends']['failures']['direction'] !== 'neutral') {
            $arrow = $kpis['trends']['failures']['direction'] === 'up' ? '↑' : '↓';
            $class = $kpis['trends']['failures']['direction'] === 'up' ? 'trend-up' : 'trend-down';
            echo '<span class="trend ' . $class . '">' . $arrow . ' ' . $kpis['trends']['failures']['percentage'] . '%</span>';
        }
        echo '</div>';

        echo '<div class="stat-item">';
        echo '<span class="stat-label">' . __('Failure Rate', 'checkoutpulse-ai') . '</span>';
        echo '<span class="stat-value rate">' . number_format($kpis['failure_rate'], 1) . '%</span>';
        echo '</div>';

        echo '<div class="stat-item">';
        echo '<span class="stat-label">' . __('Lost Revenue', 'checkoutpulse-ai') . '</span>';
        echo '<span class="stat-value amount">$' . number_format($kpis['total_amount_lost'], 2) . '</span>';
        echo '</div>';

        echo '</div>';

        echo '<div class="widget-actions">';
        echo '<a href="' . admin_url('admin.php?page=checkoutpulse-ai') . '" class="button button-primary">';
        echo __('View Full Dashboard', 'checkoutpulse-ai');
        echo '</a>';
        echo '</div>';

        echo '</div>';

        // Add widget styles
        echo '<style>
        .checkoutpulse-widget .widget-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 15px;
        }
        .checkoutpulse-widget .stat-item {
            text-align: center;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        .checkoutpulse-widget .stat-label {
            display: block;
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
        }
        .checkoutpulse-widget .stat-value {
            display: block;
            font-size: 18px;
            font-weight: bold;
            color: #333;
        }
        .checkoutpulse-widget .stat-value.failures {
            color: #dc3545;
        }
        .checkoutpulse-widget .stat-value.rate {
            color: #ffc107;
        }
        .checkoutpulse-widget .stat-value.amount {
            color: #28a745;
        }
        .checkoutpulse-widget .trend {
            font-size: 11px;
            margin-left: 5px;
        }
        .checkoutpulse-widget .trend-up {
            color: #dc3545;
        }
        .checkoutpulse-widget .trend-down {
            color: #28a745;
        }
        .checkoutpulse-widget .widget-actions {
            text-align: center;
        }
        </style>';
    }

    /**
     * Add admin bar menu
     */
    public function add_admin_bar_menu($wp_admin_bar)
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // Get recent failure count
        $recent_failures = CheckoutPulse_Payment_Monitor::instance()->get_recent_failure_count('', 60); // Last hour

        $badge_class = '';
        $badge_text = '';

        if ($recent_failures > 10) {
            $badge_class = ' critical';
            $badge_text = $recent_failures;
        } elseif ($recent_failures > 5) {
            $badge_class = ' warning';
            $badge_text = $recent_failures;
        }

        $wp_admin_bar->add_node(array(
            'id' => 'checkoutpulse-ai',
            'title' => '<span class="ab-icon"></span><span class="ab-label">CheckoutPulse</span>' .
                ($badge_text ? '<span class="cp-badge' . $badge_class . '">' . $badge_text . '</span>' : ''),
            'href' => admin_url('admin.php?page=checkoutpulse-ai'),
            'meta' => array(
                'title' => __('CheckoutPulse AI Dashboard', 'checkoutpulse-ai')
            )
        ));

        $wp_admin_bar->add_node(array(
            'parent' => 'checkoutpulse-ai',
            'id' => 'checkoutpulse-ai-dashboard',
            'title' => __('Dashboard', 'checkoutpulse-ai'),
            'href' => admin_url('admin.php?page=checkoutpulse-ai')
        ));

        $wp_admin_bar->add_node(array(
            'parent' => 'checkoutpulse-ai',
            'id' => 'checkoutpulse-ai-analytics',
            'title' => __('Analytics', 'checkoutpulse-ai'),
            'href' => admin_url('admin.php?page=checkoutpulse-ai-analytics')
        ));

        if ($recent_failures > 0) {
            $wp_admin_bar->add_node(array(
                'parent' => 'checkoutpulse-ai',
                'id' => 'checkoutpulse-ai-recent',
                'title' => sprintf(__('%d failures in last hour', 'checkoutpulse-ai'), $recent_failures),
                'href' => admin_url('admin.php?page=checkoutpulse-ai&timeframe=1h')
            ));
        }

        // Add admin bar styles
        add_action('wp_head', array($this, 'add_admin_bar_styles'));
        add_action('admin_head', array($this, 'add_admin_bar_styles'));
    }

    /**
     * Add admin bar styles
     */
    public function add_admin_bar_styles()
    {
        echo '<style>
        #wp-admin-bar-checkoutpulse-ai .ab-icon:before {
            content: "\f239";
            font-family: dashicons;
        }
        #wp-admin-bar-checkoutpulse-ai .cp-badge {
            background: #28a745;
            color: white;
            border-radius: 10px;
            padding: 2px 6px;
            font-size: 11px;
            margin-left: 5px;
            position: relative;
            top: -2px;
        }
        #wp-admin-bar-checkoutpulse-ai .cp-badge.warning {
            background: #ffc107;
            color: #212529;
        }
        #wp-admin-bar-checkoutpulse-ai .cp-badge.critical {
            background: #dc3545;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
        </style>';
    }

    /**
     * Get available payment gateways
     */
    private function get_available_gateways()
    {
        $gateways = array(
            '' => __('All Gateways', 'checkoutpulse-ai')
        );

        if (class_exists('WC_Payment_Gateways')) {
            $wc_gateways = WC_Payment_Gateways::instance()->payment_gateways();

            foreach ($wc_gateways as $gateway_id => $gateway) {
                if ($gateway->enabled === 'yes') {
                    $gateways[$gateway_id] = $gateway->get_title();
                }
            }
        }

        return $gateways;
    }

    /**
     * Handle data cleanup action
     */
    private function handle_data_cleanup()
    {
        $retention_days = intval($_POST['retention_days'] ?? 90);

        if ($retention_days < 1 || $retention_days > 365) {
            add_settings_error(
                'checkoutpulse_ai',
                'invalid_retention',
                __('Invalid retention period. Must be between 1 and 365 days.', 'checkoutpulse-ai'),
                'error'
            );
            return;
        }

        $db_manager = CheckoutPulse_Database_Manager::instance();
        $cleanup_results = $db_manager->cleanup_old_data($retention_days);

        $total_cleaned = array_sum($cleanup_results);

        add_settings_error(
            'checkoutpulse_ai',
            'cleanup_success',
            sprintf(
                __('Cleanup completed. Removed %d old records.', 'checkoutpulse-ai'),
                $total_cleaned
            ),
            'updated'
        );
    }

    /**
     * Handle export action
     */
    private function handle_export_action($export_type)
    {
        switch ($export_type) {
            case 'failures_csv':
                $this->export_failures_csv();
                break;
            case 'analytics_json':
                $this->export_analytics_json();
                break;
            case 'settings_json':
                $this->export_settings_json();
                break;
        }
    }

    /**
     * Export failures as CSV
     */
    private function export_failures_csv()
    {
        $timeframe = sanitize_text_field($_GET['timeframe'] ?? '30d');
        $gateway = sanitize_text_field($_GET['gateway'] ?? '');

        $export_data = $this->core->export_failure_data('csv', $timeframe, $gateway);

        if (isset($export_data['data'])) {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $export_data['filename'] . '"');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Expires: 0');

            echo $export_data['data'];
            exit;
        }
    }

    /**
     * Export analytics as JSON
     */
    private function export_analytics_json()
    {
        $timeframe = sanitize_text_field($_GET['timeframe'] ?? '30d');
        $gateway = sanitize_text_field($_GET['gateway'] ?? '');

        $analytics_data = $this->analytics->get_analytics(array(
            'timeframe' => $timeframe,
            'gateway' => $gateway
        ));

        $export_data = array(
            'version' => CHECKOUTPULSE_AI_VERSION,
            'exported_at' => current_time('mysql'),
            'timeframe' => $timeframe,
            'gateway' => $gateway,
            'analytics' => $analytics_data
        );

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="checkoutpulse-analytics-' . date('Y-m-d') . '.json"');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Expires: 0');

        echo json_encode($export_data, JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Export settings as JSON
     */
    private function export_settings_json()
    {
        $settings_data = $this->settings->get_all_settings();

        $export_data = array(
            'version' => CHECKOUTPULSE_AI_VERSION,
            'exported_at' => current_time('mysql'),
            'site_url' => get_site_url(),
            'settings' => $settings_data
        );

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="checkoutpulse-settings-' . date('Y-m-d') . '.json"');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Expires: 0');

        echo json_encode($export_data, JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Get plugin status for dashboard
     */
    public function get_plugin_status()
    {
        $db_manager = CheckoutPulse_Database_Manager::instance();

        return array(
            'monitoring_enabled' => $this->settings->get_setting('monitoring_enabled') === 'yes',
            'email_notifications' => $this->settings->get_setting('email_notifications') === 'yes',
            'data_retention_days' => $this->settings->get_setting('data_retention_days', 90),
            'monitored_gateways' => count($this->settings->get_setting('monitored_gateways', array())),
            'total_records' => array_sum($db_manager->get_record_counts()),
            'database_size' => array_sum($db_manager->get_table_sizes()) . ' MB',
            'last_cleanup' => get_option('checkoutpulse_ai_last_cleanup', __('Never', 'checkoutpulse-ai'))
        );
    }

    /**
     * Check if current page is CheckoutPulse AI page
     */
    public function is_checkoutpulse_page()
    {
        $screen = get_current_screen();
        return $screen && strpos($screen->id, 'checkoutpulse-ai') !== false;
    }

    /**
     * Get page title based on current page
     */
    public function get_page_title()
    {
        $screen = get_current_screen();

        if (!$screen) {
            return __('CheckoutPulse AI', 'checkoutpulse-ai');
        }

        switch ($screen->id) {
            case 'toplevel_page_checkoutpulse-ai':
                return __('Dashboard', 'checkoutpulse-ai');
            case 'checkoutpulse-ai_page_checkoutpulse-ai-analytics':
                return __('Analytics', 'checkoutpulse-ai');
            case 'checkoutpulse-ai_page_checkoutpulse-ai-alerts':
                return __('Alerts', 'checkoutpulse-ai');
            case 'checkoutpulse-ai_page_checkoutpulse-ai-settings':
                return __('Settings', 'checkoutpulse-ai');
            default:
                return __('CheckoutPulse AI', 'checkoutpulse-ai');
        }
    }

    /**
     * Add help tabs to admin pages
     */
    public function add_help_tabs()
    {
        $screen = get_current_screen();

        if (!$this->is_checkoutpulse_page()) {
            return;
        }

        $screen->add_help_tab(array(
            'id' => 'cp_overview',
            'title' => __('Overview', 'checkoutpulse-ai'),
            'content' => '<p>' . __('CheckoutPulse AI monitors your WooCommerce payment failures and provides actionable insights to help recover lost revenue.', 'checkoutpulse-ai') . '</p>'
        ));

        $screen->add_help_tab(array(
            'id' => 'cp_getting_started',
            'title' => __('Getting Started', 'checkoutpulse-ai'),
            'content' => '<p>' . __('1. Configure your alert thresholds in Settings<br>2. Select which payment gateways to monitor<br>3. Set up email notifications<br>4. Review your dashboard regularly', 'checkoutpulse-ai') . '</p>'
        ));

        $screen->set_help_sidebar(
            '<p><strong>' . __('For more information:', 'checkoutpulse-ai') . '</strong></p>' .
                '<p><a href="https://checkoutpulse.ai/docs" target="_blank">' . __('Documentation', 'checkoutpulse-ai') . '</a></p>' .
                '<p><a href="https://checkoutpulse.ai/support" target="_blank">' . __('Support', 'checkoutpulse-ai') . '</a></p>'
        );
    }
}
