<?php

/**
 * Plugin Name: CheckoutPulse AI
 * Plugin URI: https://checkoutpulse.ai
 * Description: Advanced WooCommerce payment failure monitoring and analytics plugin that provides actionable insights to recover lost revenue.
 * Version: 1.0.0
 * Author: CheckoutPulse Team
 * Author URI: https://checkoutpulse.ai
 * Text Domain: checkoutpulse-ai
 * Domain Path: /languages
 * Requires at least: 5.8
 * Tested up to: 6.4
 * Requires PHP: 8.0
 * WC requires at least: 6.0
 * WC tested up to: 8.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Network: false
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CHECKOUTPULSE_AI_VERSION', '1.0.0');
define('CHECKOUTPULSE_AI_PLUGIN_FILE', __FILE__);
define('CHECKOUTPULSE_AI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CHECKOUTPULSE_AI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CHECKOUTPULSE_AI_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main CheckoutPulse AI Class
 * 
 * @class CheckoutPulse_AI
 * @version 1.0.0
 */
final class CheckoutPulse_AI
{

    /**
     * The single instance of the class.
     *
     * @var CheckoutPulse_AI
     * @since 1.0.0
     */
    protected static $_instance = null;

    /**
     * Main CheckoutPulse AI Instance.
     *
     * Ensures only one instance of CheckoutPulse AI is loaded or can be loaded.
     *
     * @since 1.0.0
     * @static
     * @return CheckoutPulse_AI - Main instance.
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * CheckoutPulse AI Constructor.
     * 
     * @since 1.0.0
     */
    public function __construct()
    {
        $this->define_constants();
        $this->includes();
        $this->init_hooks();

        do_action('checkoutpulse_ai_loaded');
    }

    /**
     * Define CheckoutPulse AI Constants.
     * 
     * @since 1.0.0
     */
    private function define_constants()
    {
        $this->define('CHECKOUTPULSE_AI_ABSPATH', dirname(CHECKOUTPULSE_AI_PLUGIN_FILE) . '/');
        $this->define('CHECKOUTPULSE_AI_TABLE_PREFIX', 'cp_ai_');
    }

    /**
     * Define constant if not already set.
     *
     * @param string $name  Constant name.
     * @param string|bool $value Constant value.
     */
    private function define($name, $value)
    {
        if (!defined($name)) {
            define($name, $value);
        }
    }

    /**
     * What type of request is this?
     *
     * @param  string $type admin, ajax, cron or frontend.
     * @return bool
     */
    private function is_request($type)
    {
        switch ($type) {
            case 'admin':
                return is_admin();
            case 'ajax':
                return defined('DOING_AJAX');
            case 'cron':
                return defined('DOING_CRON');
            case 'frontend':
                return (!is_admin() || defined('DOING_AJAX')) && !defined('DOING_CRON');
        }
    }

    /**
     * Include required core files used in admin and on the frontend.
     * 
     * @since 1.0.0
     */
    public function includes()
    {
        // Core classes
        include_once CHECKOUTPULSE_AI_ABSPATH . 'includes/class-checkoutpulse-core.php';
        include_once CHECKOUTPULSE_AI_ABSPATH . 'includes/class-database-manager.php';
        include_once CHECKOUTPULSE_AI_ABSPATH . 'includes/class-payment-monitor.php';
        include_once CHECKOUTPULSE_AI_ABSPATH . 'includes/class-alert-system.php';
        include_once CHECKOUTPULSE_AI_ABSPATH . 'includes/class-analytics-engine.php';
        include_once CHECKOUTPULSE_AI_ABSPATH . 'includes/class-settings-manager.php';

        if ($this->is_request('admin')) {
            include_once CHECKOUTPULSE_AI_ABSPATH . 'includes/class-dashboard-controller.php';
        }
    }

    /**
     * Hook into actions and filters.
     * 
     * @since 1.0.0
     */
    private function init_hooks()
    {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('init', array($this, 'init'), 0);
        add_action('plugins_loaded', array($this, 'check_environment'));
    }

    /**
     * Init CheckoutPulse AI when WordPress Initialises.
     * 
     * @since 1.0.0
     */
    public function init()
    {
        // Before init action.
        do_action('before_checkoutpulse_ai_init');

        // Set up localisation.
        $this->load_plugin_textdomain();

        // Init action.
        do_action('checkoutpulse_ai_init');
    }

    /**
     * Load Localisation files.
     *
     * Note: the first-loaded translation file overrides any following ones if the same translation is present.
     *
     * Locales found in:
     *      - WP_LANG_DIR/checkoutpulse-ai/checkoutpulse-ai-LOCALE.mo
     *      - WP_LANG_DIR/plugins/checkoutpulse-ai-LOCALE.mo
     */
    public function load_plugin_textdomain()
    {
        if (function_exists('determine_locale')) {
            $locale = determine_locale();
        } else {
            $locale = is_admin() ? get_user_locale() : get_locale();
        }

        $locale = apply_filters('plugin_locale', $locale, 'checkoutpulse-ai');

        unload_textdomain('checkoutpulse-ai');
        load_textdomain('checkoutpulse-ai', WP_LANG_DIR . '/checkoutpulse-ai/checkoutpulse-ai-' . $locale . '.mo');
        load_plugin_textdomain('checkoutpulse-ai', false, plugin_basename(dirname(__FILE__)) . '/languages');
    }

    /**
     * Check if environment meets requirements
     * 
     * @since 1.0.0
     */
    public function check_environment()
    {
        if (!$this->is_environment_compatible()) {
            add_action('admin_notices', array($this, 'admin_notice_environment_incompatible'));
            return;
        }

        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', array($this, 'admin_notice_woocommerce_inactive'));
            return;
        }

        // Initialize core components
        $this->init_components();
    }

    /**
     * Initialize core components
     * 
     * @since 1.0.0
     */
    private function init_components()
    {
        // Initialize database manager first
        CheckoutPulse_Database_Manager::instance();

        // Initialize core components
        CheckoutPulse_Core::instance();
        CheckoutPulse_Payment_Monitor::instance();
        CheckoutPulse_Alert_System::instance();
        CheckoutPulse_Analytics_Engine::instance();
        CheckoutPulse_Settings_Manager::instance();

        // Initialize admin components if in admin
        if ($this->is_request('admin')) {
            CheckoutPulse_Dashboard_Controller::instance();
        }
    }

    /**
     * Check if the server environment is compatible with this plugin.
     *
     * @since 1.0.0
     * @return bool
     */
    private function is_environment_compatible()
    {
        return version_compare(PHP_VERSION, '8.0', '>=');
    }

    /**
     * Check if WooCommerce is active
     *
     * @since 1.0.0
     * @return bool
     */
    private function is_woocommerce_active()
    {
        return class_exists('WooCommerce');
    }

    /**
     * Display admin notice for incompatible environment
     * 
     * @since 1.0.0
     */
    public function admin_notice_environment_incompatible()
    {
        echo '<div class="notice notice-error"><p>';
        echo sprintf(
            /* translators: %s: required PHP version */
            esc_html__('CheckoutPulse AI requires PHP version %s or higher. Please contact your hosting provider to upgrade PHP.', 'checkoutpulse-ai'),
            '8.0'
        );
        echo '</p></div>';
    }

    /**
     * Display admin notice for inactive WooCommerce
     * 
     * @since 1.0.0
     */
    public function admin_notice_woocommerce_inactive()
    {
        echo '<div class="notice notice-error"><p>';
        echo sprintf(
            /* translators: %1$s: plugin name, %2$s: WooCommerce link */
            esc_html__('%1$s requires WooCommerce to be installed and activated. %2$s', 'checkoutpulse-ai'),
            '<strong>CheckoutPulse AI</strong>',
            '<a href="' . esc_url(admin_url('plugin-install.php?s=woocommerce&tab=search&type=term')) . '">' . esc_html__('Install WooCommerce', 'checkoutpulse-ai') . '</a>'
        );
        echo '</p></div>';
    }

    /**
     * Plugin activation hook
     * 
     * @since 1.0.0
     */
    public function activate()
    {
        if (!$this->is_environment_compatible()) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                sprintf(
                    /* translators: %s: required PHP version */
                    esc_html__('CheckoutPulse AI requires PHP version %s or higher.', 'checkoutpulse-ai'),
                    '8.0'
                )
            );
        }

        if (!$this->is_woocommerce_active()) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(esc_html__('CheckoutPulse AI requires WooCommerce to be installed and activated.', 'checkoutpulse-ai'));
        }

        // Create database tables
        require_once CHECKOUTPULSE_AI_ABSPATH . 'includes/class-database-manager.php';
        CheckoutPulse_Database_Manager::create_tables();

        // Set default options
        $this->set_default_options();

        // Set activation timestamp
        update_option('checkoutpulse_ai_activated_time', time());

        // Clear any cached data
        wp_cache_flush();
    }

    /**
     * Plugin deactivation hook
     * 
     * @since 1.0.0
     */
    public function deactivate()
    {
        // Clear scheduled events
        wp_clear_scheduled_hook('checkoutpulse_ai_daily_summary');
        wp_clear_scheduled_hook('checkoutpulse_ai_weekly_report');
        wp_clear_scheduled_hook('checkoutpulse_ai_cleanup_old_data');

        // Clear cached data
        wp_cache_flush();
    }

    /**
     * Set default plugin options
     * 
     * @since 1.0.0
     */
    private function set_default_options()
    {
        $default_settings = array(
            'monitoring_enabled' => 'yes',
            'data_retention_days' => 90,
            'critical_failure_threshold' => 5,
            'critical_timeframe' => 600,
            'warning_failure_rate' => 15,
            'warning_timeframe' => 3600,
            'alert_cooldown' => 1800,
            'email_notifications' => 'yes',
            'admin_email' => get_option('admin_email'),
            'daily_summary' => 'yes',
            'weekly_report' => 'yes',
            'monitored_gateways' => array('paypal', 'stripe', 'bacs', 'cheque', 'cod')
        );

        foreach ($default_settings as $key => $value) {
            $option_name = 'checkoutpulse_ai_' . $key;
            if (false === get_option($option_name)) {
                update_option($option_name, $value);
            }
        }
    }

    /**
     * Get the plugin url.
     *
     * @return string
     */
    public function plugin_url()
    {
        return untrailingslashit(plugins_url('/', __FILE__));
    }

    /**
     * Get the plugin path.
     *
     * @return string
     */
    public function plugin_path()
    {
        return untrailingslashit(plugin_dir_path(__FILE__));
    }

    /**
     * Get Ajax URL.
     *
     * @return string
     */
    public function ajax_url()
    {
        return admin_url('admin-ajax.php', 'relative');
    }
}

/**
 * Main instance of CheckoutPulse AI.
 *
 * Returns the main instance of CP to prevent the need to use globals.
 *
 * @since  1.0.0
 * @return CheckoutPulse_AI
 */
function CheckoutPulse_AI()
{
    return CheckoutPulse_AI::instance();
}

// Global for backwards compatibility.
$GLOBALS['checkoutpulse_ai'] = CheckoutPulse_AI();
