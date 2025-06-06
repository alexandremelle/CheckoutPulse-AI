<?php

/**
 * Settings Manager Class
 *
 * Handles plugin settings and configuration management
 *
 * @package CheckoutPulse_AI
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CheckoutPulse Settings Manager Class
 *
 * @class CheckoutPulse_Settings_Manager
 * @version 1.0.0
 */
class CheckoutPulse_Settings_Manager
{

    /**
     * Single instance of the class
     *
     * @var CheckoutPulse_Settings_Manager
     */
    protected static $_instance = null;

    /**
     * Settings groups
     *
     * @var array
     */
    private $settings_groups = array();

    /**
     * Default settings
     *
     * @var array
     */
    private $default_settings = array();

    /**
     * Main CheckoutPulse Settings Manager Instance
     *
     * @static
     * @return CheckoutPulse_Settings_Manager - Main instance
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
        $this->init_default_settings();
        $this->init_settings_groups();
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks()
    {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_cp_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_cp_reset_settings', array($this, 'ajax_reset_settings'));
        add_action('wp_ajax_cp_export_settings', array($this, 'ajax_export_settings'));
        add_action('wp_ajax_cp_import_settings', array($this, 'ajax_import_settings'));
    }

    /**
     * Initialize default settings
     */
    private function init_default_settings()
    {
        $this->default_settings = array(
            // General Settings
            'monitoring_enabled' => 'yes',
            'data_retention_days' => 90,
            'cleanup_frequency' => 'daily',

            // Alert Thresholds - Critical
            'critical_failure_threshold' => 5,
            'critical_timeframe' => 600, // 10 minutes
            'gateway_down_threshold' => 3,
            'high_value_threshold' => 500,
            'high_value_consecutive' => 2,

            // Alert Thresholds - Warning
            'warning_failure_rate' => 15,
            'warning_timeframe' => 3600, // 1 hour
            'gateway_degradation_rate' => 25,
            'error_spike_threshold' => 3,
            'error_spike_timeframe' => 1800, // 30 minutes

            // Alert Settings
            'alert_cooldown' => 1800, // 30 minutes
            'email_notifications' => 'yes',
            'admin_notices' => 'yes',
            'sound_alerts' => 'no',

            // Recipients
            'admin_email' => get_option('admin_email'),
            'critical_alert_emails' => '',
            'warning_alert_emails' => '',

            // Scheduled Reports
            'daily_summary' => 'yes',
            'daily_summary_time' => '09:00',
            'weekly_report' => 'yes',
            'weekly_report_day' => 'monday',
            'weekly_report_time' => '09:00',

            // Gateway Settings
            'monitored_gateways' => array('paypal', 'stripe', 'bacs', 'cheque', 'cod'),
            'gateway_priorities' => array(),

            // Advanced Settings
            'debug_mode' => 'no',
            'log_level' => 'error',
            'api_access' => 'no',
            'webhook_url' => '',
            'custom_css' => '',

            // Performance Settings
            'cache_duration' => 300, // 5 minutes
            'batch_size' => 100,
            'async_processing' => 'yes',

            // Privacy Settings
            'anonymize_data' => 'yes',
            'data_export_enabled' => 'yes',
            'third_party_sharing' => 'no'
        );
    }

    /**
     * Initialize settings groups
     */
    private function init_settings_groups()
    {
        $this->settings_groups = array(
            'general' => array(
                'title' => __('General Settings', 'checkoutpulse-ai'),
                'description' => __('Basic plugin configuration and monitoring settings.', 'checkoutpulse-ai'),
                'fields' => array(
                    'monitoring_enabled' => array(
                        'type' => 'checkbox',
                        'title' => __('Enable Monitoring', 'checkoutpulse-ai'),
                        'description' => __('Enable payment failure monitoring and data collection.', 'checkoutpulse-ai'),
                        'default' => 'yes'
                    ),
                    'data_retention_days' => array(
                        'type' => 'number',
                        'title' => __('Data Retention (Days)', 'checkoutpulse-ai'),
                        'description' => __('Number of days to keep failure data before automatic cleanup.', 'checkoutpulse-ai'),
                        'default' => 90,
                        'min' => 7,
                        'max' => 365
                    ),
                    'cleanup_frequency' => array(
                        'type' => 'select',
                        'title' => __('Cleanup Frequency', 'checkoutpulse-ai'),
                        'description' => __('How often to run automatic data cleanup.', 'checkoutpulse-ai'),
                        'options' => array(
                            'daily' => __('Daily', 'checkoutpulse-ai'),
                            'weekly' => __('Weekly', 'checkoutpulse-ai'),
                            'monthly' => __('Monthly', 'checkoutpulse-ai')
                        ),
                        'default' => 'daily'
                    )
                )
            ),

            'thresholds' => array(
                'title' => __('Alert Thresholds', 'checkoutpulse-ai'),
                'description' => __('Configure when alerts should be triggered based on failure patterns.', 'checkoutpulse-ai'),
                'fields' => array(
                    'critical_failure_threshold' => array(
                        'type' => 'number',
                        'title' => __('Critical Failure Threshold', 'checkoutpulse-ai'),
                        'description' => __('Number of failures to trigger critical alert.', 'checkoutpulse-ai'),
                        'default' => 5,
                        'min' => 1,
                        'max' => 50
                    ),
                    'critical_timeframe' => array(
                        'type' => 'number',
                        'title' => __('Critical Timeframe (Minutes)', 'checkoutpulse-ai'),
                        'description' => __('Time window for critical failure threshold.', 'checkoutpulse-ai'),
                        'default' => 10,
                        'min' => 1,
                        'max' => 60
                    ),
                    'warning_failure_rate' => array(
                        'type' => 'number',
                        'title' => __('Warning Failure Rate (%)', 'checkoutpulse-ai'),
                        'description' => __('Failure rate percentage to trigger warning alert.', 'checkoutpulse-ai'),
                        'default' => 15,
                        'min' => 1,
                        'max' => 100,
                        'step' => '0.1'
                    ),
                    'high_value_threshold' => array(
                        'type' => 'number',
                        'title' => __('High Value Threshold', 'checkoutpulse-ai'),
                        'description' => __('Order amount considered high-value for special alerts.', 'checkoutpulse-ai'),
                        'default' => 500,
                        'min' => 10,
                        'step' => '0.01'
                    )
                )
            ),

            'notifications' => array(
                'title' => __('Notification Settings', 'checkoutpulse-ai'),
                'description' => __('Configure how and when you receive alert notifications.', 'checkoutpulse-ai'),
                'fields' => array(
                    'email_notifications' => array(
                        'type' => 'checkbox',
                        'title' => __('Email Notifications', 'checkoutpulse-ai'),
                        'description' => __('Send email alerts when thresholds are exceeded.', 'checkoutpulse-ai'),
                        'default' => 'yes'
                    ),
                    'admin_email' => array(
                        'type' => 'email',
                        'title' => __('Primary Admin Email', 'checkoutpulse-ai'),
                        'description' => __('Primary email address for receiving alerts.', 'checkoutpulse-ai'),
                        'default' => get_option('admin_email')
                    ),
                    'critical_alert_emails' => array(
                        'type' => 'textarea',
                        'title' => __('Critical Alert Recipients', 'checkoutpulse-ai'),
                        'description' => __('Additional email addresses for critical alerts (comma-separated).', 'checkoutpulse-ai'),
                        'placeholder' => 'email1@example.com, email2@example.com'
                    ),
                    'alert_cooldown' => array(
                        'type' => 'number',
                        'title' => __('Alert Cooldown (Minutes)', 'checkoutpulse-ai'),
                        'description' => __('Minimum time between identical alerts to prevent spam.', 'checkoutpulse-ai'),
                        'default' => 30,
                        'min' => 1,
                        'max' => 1440
                    )
                )
            ),

            'reports' => array(
                'title' => __('Scheduled Reports', 'checkoutpulse-ai'),
                'description' => __('Configure automatic summary reports and their delivery schedule.', 'checkoutpulse-ai'),
                'fields' => array(
                    'daily_summary' => array(
                        'type' => 'checkbox',
                        'title' => __('Daily Summary', 'checkoutpulse-ai'),
                        'description' => __('Send daily payment failure summary email.', 'checkoutpulse-ai'),
                        'default' => 'yes'
                    ),
                    'daily_summary_time' => array(
                        'type' => 'time',
                        'title' => __('Daily Summary Time', 'checkoutpulse-ai'),
                        'description' => __('Time to send daily summary (24-hour format).', 'checkoutpulse-ai'),
                        'default' => '09:00'
                    ),
                    'weekly_report' => array(
                        'type' => 'checkbox',
                        'title' => __('Weekly Report', 'checkoutpulse-ai'),
                        'description' => __('Send weekly detailed payment analysis report.', 'checkoutpulse-ai'),
                        'default' => 'yes'
                    ),
                    'weekly_report_day' => array(
                        'type' => 'select',
                        'title' => __('Weekly Report Day', 'checkoutpulse-ai'),
                        'description' => __('Day of the week to send weekly reports.', 'checkoutpulse-ai'),
                        'options' => array(
                            'monday' => __('Monday', 'checkoutpulse-ai'),
                            'tuesday' => __('Tuesday', 'checkoutpulse-ai'),
                            'wednesday' => __('Wednesday', 'checkoutpulse-ai'),
                            'thursday' => __('Thursday', 'checkoutpulse-ai'),
                            'friday' => __('Friday', 'checkoutpulse-ai'),
                            'saturday' => __('Saturday', 'checkoutpulse-ai'),
                            'sunday' => __('Sunday', 'checkoutpulse-ai')
                        ),
                        'default' => 'monday'
                    )
                )
            ),

            'gateways' => array(
                'title' => __('Payment Gateways', 'checkoutpulse-ai'),
                'description' => __('Select which payment gateways to monitor for failures.', 'checkoutpulse-ai'),
                'fields' => array(
                    'monitored_gateways' => array(
                        'type' => 'checkbox_group',
                        'title' => __('Monitored Gateways', 'checkoutpulse-ai'),
                        'description' => __('Select payment gateways to monitor for failures.', 'checkoutpulse-ai'),
                        'options' => $this->get_available_gateways(),
                        'default' => array('paypal', 'stripe', 'bacs', 'cheque', 'cod')
                    )
                )
            ),

            'advanced' => array(
                'title' => __('Advanced Settings', 'checkoutpulse-ai'),
                'description' => __('Advanced configuration options for experienced users.', 'checkoutpulse-ai'),
                'fields' => array(
                    'debug_mode' => array(
                        'type' => 'checkbox',
                        'title' => __('Debug Mode', 'checkoutpulse-ai'),
                        'description' => __('Enable detailed logging for troubleshooting.', 'checkoutpulse-ai'),
                        'default' => 'no'
                    ),
                    'cache_duration' => array(
                        'type' => 'number',
                        'title' => __('Cache Duration (Seconds)', 'checkoutpulse-ai'),
                        'description' => __('How long to cache dashboard data.', 'checkoutpulse-ai'),
                        'default' => 300,
                        'min' => 60,
                        'max' => 3600
                    ),
                    'anonymize_data' => array(
                        'type' => 'checkbox',
                        'title' => __('Anonymize Data', 'checkoutpulse-ai'),
                        'description' => __('Hash IP addresses and user agents for privacy.', 'checkoutpulse-ai'),
                        'default' => 'yes'
                    ),
                    'webhook_url' => array(
                        'type' => 'url',
                        'title' => __('Webhook URL', 'checkoutpulse-ai'),
                        'description' => __('Optional webhook URL for alert notifications.', 'checkoutpulse-ai'),
                        'placeholder' => 'https://your-webhook-url.com/endpoint'
                    )
                )
            )
        );
    }

    /**
     * Register WordPress settings
     */
    public function register_settings()
    {
        foreach ($this->settings_groups as $group_key => $group) {
            foreach ($group['fields'] as $field_key => $field) {
                $option_name = 'checkoutpulse_ai_' . $field_key;

                register_setting(
                    'checkoutpulse_ai_' . $group_key,
                    $option_name,
                    array(
                        'type' => $this->get_field_type($field['type']),
                        'sanitize_callback' => array($this, 'sanitize_setting'),
                        'default' => $field['default'] ?? ''
                    )
                );
            }
        }
    }

    /**
     * Get field type for WordPress settings API
     *
     * @param string $field_type Field type
     * @return string WordPress field type
     */
    private function get_field_type($field_type)
    {
        $type_mapping = array(
            'checkbox' => 'boolean',
            'checkbox_group' => 'array',
            'number' => 'number',
            'email' => 'string',
            'url' => 'string',
            'time' => 'string',
            'textarea' => 'string',
            'select' => 'string'
        );

        return $type_mapping[$field_type] ?? 'string';
    }

    /**
     * Sanitize setting value
     *
     * @param mixed $value Setting value
     * @return mixed Sanitized value
     */
    public function sanitize_setting($value)
    {
        if (is_array($value)) {
            return array_map('sanitize_text_field', $value);
        }

        return sanitize_text_field($value);
    }

    /**
     * Get setting value
     *
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return mixed Setting value
     */
    public function get_setting($key, $default = null)
    {
        $option_name = 'checkoutpulse_ai_' . $key;

        if ($default === null && isset($this->default_settings[$key])) {
            $default = $this->default_settings[$key];
        }

        return get_option($option_name, $default);
    }

    /**
     * Update setting value
     *
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @return bool Success status
     */
    public function update_setting($key, $value)
    {
        $option_name = 'checkoutpulse_ai_' . $key;
        return update_option($option_name, $value);
    }

    /**
     * Get all settings
     *
     * @return array All settings
     */
    public function get_all_settings()
    {
        $settings = array();

        foreach ($this->default_settings as $key => $default) {
            $settings[$key] = $this->get_setting($key, $default);
        }

        return $settings;
    }

    /**
     * Get settings groups
     *
     * @return array Settings groups
     */
    public function get_settings_groups()
    {
        return $this->settings_groups;
    }

    /**
     * Get available payment gateways
     *
     * @return array Available gateways
     */
    private function get_available_gateways()
    {
        $gateways = array();

        if (class_exists('WC_Payment_Gateways')) {
            $wc_gateways = WC_Payment_Gateways::instance()->payment_gateways();

            foreach ($wc_gateways as $gateway_id => $gateway) {
                if ($gateway->enabled === 'yes') {
                    $gateways[$gateway_id] = $gateway->get_title();
                }
            }
        }

        // Add common gateways as fallback
        if (empty($gateways)) {
            $gateways = array(
                'paypal' => 'PayPal',
                'stripe' => 'Stripe',
                'bacs' => 'Bank Transfer',
                'cheque' => 'Check Payment',
                'cod' => 'Cash on Delivery'
            );
        }

        return $gateways;
    }

    /**
     * AJAX handler for saving settings
     */
    public function ajax_save_settings()
    {
        check_ajax_referer('checkoutpulse_ai_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'checkoutpulse-ai'));
        }

        $settings = $_POST['settings'] ?? array();
        $updated = 0;
        $errors = array();

        foreach ($settings as $key => $value) {
            // Validate setting key
            if (!array_key_exists($key, $this->default_settings)) {
                $errors[] = sprintf(__('Invalid setting key: %s', 'checkoutpulse-ai'), $key);
                continue;
            }

            // Sanitize and validate value
            $sanitized_value = $this->validate_setting_value($key, $value);

            if ($sanitized_value !== false) {
                if ($this->update_setting($key, $sanitized_value)) {
                    $updated++;
                }
            } else {
                $errors[] = sprintf(__('Invalid value for setting: %s', 'checkoutpulse-ai'), $key);
            }
        }

        if (!empty($errors)) {
            wp_send_json_error(array(
                'message' => __('Some settings could not be saved', 'checkoutpulse-ai'),
                'errors' => $errors
            ));
        } else {
            wp_send_json_success(array(
                'message' => sprintf(__('%d settings saved successfully', 'checkoutpulse-ai'), $updated),
                'updated' => $updated
            ));
        }
    }

    /**
     * Validate setting value
     *
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @return mixed Validated value or false if invalid
     */
    private function validate_setting_value($key, $value)
    {
        // Get field configuration
        $field_config = $this->get_field_config($key);

        if (!$field_config) {
            return false;
        }

        switch ($field_config['type']) {
            case 'checkbox':
                return in_array($value, array('yes', 'no', true, false, 1, 0)) ? ($value ? 'yes' : 'no') : false;

            case 'checkbox_group':
                return is_array($value) ? array_map('sanitize_text_field', $value) : false;

            case 'number':
                $num = floatval($value);
                $min = $field_config['min'] ?? null;
                $max = $field_config['max'] ?? null;

                if ($min !== null && $num < $min) return false;
                if ($max !== null && $num > $max) return false;

                return $num;

            case 'email':
                return is_email($value) ? sanitize_email($value) : false;

            case 'url':
                return empty($value) || filter_var($value, FILTER_VALIDATE_URL) ? esc_url_raw($value) : false;

            case 'time':
                return preg_match('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/', $value) ? sanitize_text_field($value) : false;

            default:
                return sanitize_text_field($value);
        }
    }

    /**
     * Get field configuration
     *
     * @param string $key Setting key
     * @return array|null Field configuration
     */
    private function get_field_config($key)
    {
        foreach ($this->settings_groups as $group) {
            if (isset($group['fields'][$key])) {
                return $group['fields'][$key];
            }
        }
        return null;
    }

    /**
     * AJAX handler for resetting settings
     */
    public function ajax_reset_settings()
    {
        check_ajax_referer('checkoutpulse_ai_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'checkoutpulse-ai'));
        }

        $group = sanitize_text_field($_POST['group'] ?? 'all');
        $reset_count = 0;

        if ($group === 'all') {
            // Reset all settings
            foreach ($this->default_settings as $key => $default) {
                if ($this->update_setting($key, $default)) {
                    $reset_count++;
                }
            }
        } else {
            // Reset specific group
            if (isset($this->settings_groups[$group])) {
                foreach ($this->settings_groups[$group]['fields'] as $key => $field) {
                    if ($this->update_setting($key, $field['default'] ?? '')) {
                        $reset_count++;
                    }
                }
            }
        }

        wp_send_json_success(array(
            'message' => sprintf(__('%d settings reset to defaults', 'checkoutpulse-ai'), $reset_count),
            'reset_count' => $reset_count
        ));
    }

    /**
     * AJAX handler for exporting settings
     */
    public function ajax_export_settings()
    {
        check_ajax_referer('checkoutpulse_ai_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'checkoutpulse-ai'));
        }

        $settings = $this->get_all_settings();

        $export_data = array(
            'version' => CHECKOUTPULSE_AI_VERSION,
            'exported_at' => current_time('mysql'),
            'site_url' => get_site_url(),
            'settings' => $settings
        );

        wp_send_json_success(array(
            'data' => $export_data,
            'filename' => 'checkoutpulse-ai-settings-' . date('Y-m-d') . '.json'
        ));
    }

    /**
     * AJAX handler for importing settings
     */
    public function ajax_import_settings()
    {
        check_ajax_referer('checkoutpulse_ai_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'checkoutpulse-ai'));
        }

        $import_data = $_POST['import_data'] ?? '';

        if (empty($import_data)) {
            wp_send_json_error(__('No import data provided', 'checkoutpulse-ai'));
        }

        $data = json_decode(stripslashes($import_data), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(__('Invalid JSON data', 'checkoutpulse-ai'));
        }

        if (!isset($data['settings']) || !is_array($data['settings'])) {
            wp_send_json_error(__('Invalid settings data format', 'checkoutpulse-ai'));
        }

        $imported = 0;
        $errors = array();

        foreach ($data['settings'] as $key => $value) {
            if (array_key_exists($key, $this->default_settings)) {
                $validated_value = $this->validate_setting_value($key, $value);

                if ($validated_value !== false) {
                    if ($this->update_setting($key, $validated_value)) {
                        $imported++;
                    }
                } else {
                    $errors[] = sprintf(__('Invalid value for setting: %s', 'checkoutpulse-ai'), $key);
                }
            }
        }

        if (!empty($errors) && $imported === 0) {
            wp_send_json_error(array(
                'message' => __('Import failed - no valid settings found', 'checkoutpulse-ai'),
                'errors' => $errors
            ));
        } else {
            wp_send_json_success(array(
                'message' => sprintf(__('%d settings imported successfully', 'checkoutpulse-ai'), $imported),
                'imported' => $imported,
                'errors' => $errors
            ));
        }
    }

    /**
     * Get default settings
     *
     * @return array Default settings
     */
    public function get_default_settings()
    {
        return $this->default_settings;
    }

    /**
     * Check if settings are at defaults
     *
     * @return bool True if all settings are at default values
     */
    public function are_settings_default()
    {
        $current_settings = $this->get_all_settings();

        foreach ($this->default_settings as $key => $default) {
            if ($current_settings[$key] !== $default) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get setting validation rules
     *
     * @param string $key Setting key
     * @return array Validation rules
     */
    public function get_validation_rules($key)
    {
        $field_config = $this->get_field_config($key);

        if (!$field_config) {
            return array();
        }

        $rules = array(
            'type' => $field_config['type'],
            'required' => $field_config['required'] ?? false
        );

        // Add type-specific rules
        switch ($field_config['type']) {
            case 'number':
                if (isset($field_config['min'])) $rules['min'] = $field_config['min'];
                if (isset($field_config['max'])) $rules['max'] = $field_config['max'];
                if (isset($field_config['step'])) $rules['step'] = $field_config['step'];
                break;

            case 'select':
            case 'checkbox_group':
                $rules['options'] = array_keys($field_config['options'] ?? array());
                break;
        }

        return $rules;
    }
}
