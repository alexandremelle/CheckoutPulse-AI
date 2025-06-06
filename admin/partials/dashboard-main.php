<?php

/**
 * CheckoutPulse AI Main Dashboard Template
 *
 * @package CheckoutPulse_AI
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

$controller = CheckoutPulse_Dashboard_Controller::instance();
$plugin_status = $controller->get_plugin_status();
?>

<div class="checkoutpulse-ai-admin wrap">
    <!-- Header -->
    <div class="cp-header">
        <div class="cp-header-content">
            <div>
                <h1><?php esc_html_e('CheckoutPulse AI Dashboard', 'checkoutpulse-ai'); ?></h1>
                <p><?php esc_html_e('Monitor and analyze payment failures in real-time', 'checkoutpulse-ai'); ?></p>
            </div>
            <div class="cp-status">
                <div class="cp-status-indicator <?php echo $plugin_status['monitoring_enabled'] ? 'active' : 'inactive'; ?>"></div>
                <span><?php echo $plugin_status['monitoring_enabled'] ? __('Monitoring Active', 'checkoutpulse-ai') : __('Monitoring Inactive', 'checkoutpulse-ai'); ?></span>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="cp-filters">
        <div class="cp-filter-group">
            <label><?php esc_html_e('Timeframe', 'checkoutpulse-ai'); ?></label>
            <select class="cp-timeframe-selector">
                <option value="1h" <?php selected($timeframe, '1h'); ?>><?php esc_html_e('Last Hour', 'checkoutpulse-ai'); ?></option>
                <option value="24h" <?php selected($timeframe, '24h'); ?>><?php esc_html_e('Last 24 Hours', 'checkoutpulse-ai'); ?></option>
                <option value="7d" <?php selected($timeframe, '7d'); ?>><?php esc_html_e('Last 7 Days', 'checkoutpulse-ai'); ?></option>
                <option value="30d" <?php selected($timeframe, '30d'); ?>><?php esc_html_e('Last 30 Days', 'checkoutpulse-ai'); ?></option>
            </select>
        </div>

        <div class="cp-filter-group">
            <label><?php esc_html_e('Gateway', 'checkoutpulse-ai'); ?></label>
            <select class="cp-gateway-filter">
                <?php foreach ($available_gateways as $gateway_id => $gateway_name) : ?>
                    <option value="<?php echo esc_attr($gateway_id); ?>" <?php selected($gateway, $gateway_id); ?>>
                        <?php echo esc_html($gateway_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="cp-filter-actions">
            <button class="cp-btn cp-btn-primary cp-refresh-btn">
                <?php esc_html_e('Refresh', 'checkoutpulse-ai'); ?>
            </button>
            <button class="cp-btn cp-btn-secondary cp-export-btn" data-export-type="failures_csv">
                <?php esc_html_e('Export CSV', 'checkoutpulse-ai'); ?>
            </button>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="cp-grid cp-grid-4">
        <div class="cp-card cp-kpi-card cp-kpi-failures">
            <div class="cp-card-body">
                <div class="cp-kpi-label"><?php esc_html_e('Total Failures', 'checkoutpulse-ai'); ?></div>
                <div class="cp-kpi-value failures"><?php echo number_format($dashboard_data['kpis']['total_failures']); ?></div>
                <?php if ($dashboard_data['kpis']['trends']['failures']['direction'] !== 'neutral') : ?>
                    <div class="cp-kpi-trend <?php echo esc_attr($dashboard_data['kpis']['trends']['failures']['direction']); ?>">
                        <?php
                        $icon = $dashboard_data['kpis']['trends']['failures']['direction'] === 'up' ? '↑' : '↓';
                        echo $icon . ' ' . esc_html($dashboard_data['kpis']['trends']['failures']['percentage']) . '%';
                        ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="cp-card cp-kpi-card cp-kpi-rate">
            <div class="cp-card-body">
                <div class="cp-kpi-label"><?php esc_html_e('Failure Rate', 'checkoutpulse-ai'); ?></div>
                <div class="cp-kpi-value rate"><?php echo number_format($dashboard_data['kpis']['failure_rate'], 1); ?>%</div>
                <?php if ($dashboard_data['kpis']['trends']['rate']['direction'] !== 'neutral') : ?>
                    <div class="cp-kpi-trend <?php echo esc_attr($dashboard_data['kpis']['trends']['rate']['direction']); ?>">
                        <?php
                        $icon = $dashboard_data['kpis']['trends']['rate']['direction'] === 'up' ? '↑' : '↓';
                        echo $icon . ' ' . esc_html($dashboard_data['kpis']['trends']['rate']['value']);
                        ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="cp-card cp-kpi-card cp-kpi-amount">
            <div class="cp-card-body">
                <div class="cp-kpi-label"><?php esc_html_e('Lost Revenue', 'checkoutpulse-ai'); ?></div>
                <div class="cp-kpi-value amount">$<?php echo number_format($dashboard_data['kpis']['total_amount_lost'], 2); ?></div>
                <?php if ($dashboard_data['kpis']['trends']['amount']['direction'] !== 'neutral') : ?>
                    <div class="cp-kpi-trend <?php echo esc_attr($dashboard_data['kpis']['trends']['amount']['direction']); ?>">
                        <?php
                        $icon = $dashboard_data['kpis']['trends']['amount']['direction'] === 'up' ? '↑' : '↓';
                        echo $icon . ' ' . esc_html($dashboard_data['kpis']['trends']['amount']['percentage']) . '%';
                        ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="cp-card cp-kpi-card cp-kpi-avg">
            <div class="cp-card-body">
                <div class="cp-kpi-label"><?php esc_html_e('Avg. Failure Amount', 'checkoutpulse-ai'); ?></div>
                <div class="cp-kpi-value amount">$<?php echo number_format($dashboard_data['kpis']['avg_failure_amount'], 2); ?></div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="cp-grid cp-grid-2">
        <!-- Timeline Chart -->
        <div class="cp-card">
            <div class="cp-card-header">
                <h3><?php esc_html_e('Failure Timeline', 'checkoutpulse-ai'); ?></h3>
            </div>
            <div class="cp-card-body">
                <div class="cp-chart-container">
                    <canvas id="cp-failure-timeline-chart"></canvas>
                </div>
            </div>
        </div>

        <!-- Gateway Breakdown -->
        <div class="cp-card">
            <div class="cp-card-header">
                <h3><?php esc_html_e('Gateway Breakdown', 'checkoutpulse-ai'); ?></h3>
            </div>
            <div class="cp-card-body">
                <div class="cp-chart-container">
                    <canvas id="cp-gateway-breakdown-chart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Error Analysis and Recent Failures -->
    <div class="cp-grid cp-grid-2">
        <!-- Top Error Codes -->
        <div class="cp-card">
            <div class="cp-card-header">
                <h3><?php esc_html_e('Top Error Codes', 'checkoutpulse-ai'); ?></h3>
            </div>
            <div class="cp-card-body">
                <div class="cp-chart-container">
                    <canvas id="cp-error-code-chart"></canvas>
                </div>
            </div>
        </div>

        <!-- Recent Failures -->
        <div class="cp-card">
            <div class="cp-card-header">
                <h3><?php esc_html_e('Recent Failures', 'checkoutpulse-ai'); ?></h3>
                <a href="<?php echo admin_url('admin.php?page=checkoutpulse-ai-analytics'); ?>" class="cp-btn cp-btn-sm">
                    <?php esc_html_e('View All', 'checkoutpulse-ai'); ?>
                </a>
            </div>
            <div class="cp-card-body">
                <table class="cp-table cp-recent-failures">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Order', 'checkoutpulse-ai'); ?></th>
                            <th><?php esc_html_e('Gateway', 'checkoutpulse-ai'); ?></th>
                            <th><?php esc_html_e('Error', 'checkoutpulse-ai'); ?></th>
                            <th><?php esc_html_e('Amount', 'checkoutpulse-ai'); ?></th>
                            <th><?php esc_html_e('Time', 'checkoutpulse-ai'); ?></th>
                            <th><?php esc_html_e('Actions', 'checkoutpulse-ai'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($dashboard_data['recent_failures'])) : ?>
                            <tr>
                                <td colspan="6" class="cp-text-center">
                                    <?php esc_html_e('No recent failures found', 'checkoutpulse-ai'); ?>
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($dashboard_data['recent_failures'] as $failure) : ?>
                                <tr>
                                    <td>#<?php echo esc_html($failure['order_id']); ?></td>
                                    <td><?php echo esc_html($failure['gateway']); ?></td>
                                    <td><?php echo esc_html($failure['error_code'] ?? __('Unknown', 'checkoutpulse-ai')); ?></td>
                                    <td>$<?php echo number_format($failure['amount'], 2); ?></td>
                                    <td><?php echo esc_html(human_time_diff(strtotime($failure['failed_at']))); ?> <?php esc_html_e('ago', 'checkoutpulse-ai'); ?></td>
                                    <td>
                                        <button class="cp-btn cp-btn-sm cp-failure-details-btn" data-failure-id="<?php echo esc_attr($failure['id']); ?>">
                                            <?php esc_html_e('Details', 'checkoutpulse-ai'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Alerts and Recommendations -->
    <div class="cp-grid cp-grid-2">
        <!-- Active Alerts -->
        <div class="cp-card">
            <div class="cp-card-header">
                <h3><?php esc_html_e('Active Alerts', 'checkoutpulse-ai'); ?></h3>
                <a href="<?php echo admin_url('admin.php?page=checkoutpulse-ai-alerts'); ?>" class="cp-btn cp-btn-sm">
                    <?php esc_html_e('Manage Alerts', 'checkoutpulse-ai'); ?>
                </a>
            </div>
            <div class="cp-card-body">
                <?php
                $recent_alerts = CheckoutPulse_Database_Manager::instance()->get_alert_logs(array(
                    'limit' => 5,
                    'date_from' => date('Y-m-d', strtotime('-24 hours'))
                ));
                ?>

                <?php if (empty($recent_alerts)) : ?>
                    <div class="cp-alert cp-alert-success">
                        <?php esc_html_e('No recent alerts - system is operating normally.', 'checkoutpulse-ai'); ?>
                    </div>
                <?php else : ?>
                    <?php foreach ($recent_alerts as $alert) : ?>
                        <div class="cp-alert cp-alert-<?php echo esc_attr($alert['alert_level']); ?>">
                            <strong><?php echo esc_html(ucfirst($alert['alert_level'])); ?>:</strong>
                            <?php echo esc_html($alert['message']); ?>
                            <small class="cp-alert-time">
                                <?php echo esc_html(human_time_diff(strtotime($alert['created_at']))); ?> <?php esc_html_e('ago', 'checkoutpulse-ai'); ?>
                            </small>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="cp-card">
            <div class="cp-card-header">
                <h3><?php esc_html_e('Quick Actions', 'checkoutpulse-ai'); ?></h3>
            </div>
            <div class="cp-card-body">
                <div class="cp-quick-actions">
                    <a href="<?php echo admin_url('admin.php?page=checkoutpulse-ai-settings'); ?>" class="cp-btn cp-btn-primary cp-btn-block">
                        <?php esc_html_e('Configure Alerts', 'checkoutpulse-ai'); ?>
                    </a>

                    <button class="cp-btn cp-btn-secondary cp-btn-block cp-test-alert-btn" data-alert-type="test">
                        <?php esc_html_e('Send Test Alert', 'checkoutpulse-ai'); ?>
                    </button>

                    <a href="<?php echo admin_url('admin.php?page=checkoutpulse-ai-analytics'); ?>" class="cp-btn cp-btn-secondary cp-btn-block">
                        <?php esc_html_e('View Analytics', 'checkoutpulse-ai'); ?>
                    </a>

                    <button class="cp-btn cp-btn-warning cp-btn-block cp-export-btn" data-export-type="analytics_json">
                        <?php esc_html_e('Export Data', 'checkoutpulse-ai'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- System Status -->
    <div class="cp-card">
        <div class="cp-card-header">
            <h3><?php esc_html_e('System Status', 'checkoutpulse-ai'); ?></h3>
        </div>
        <div class="cp-card-body">
            <div class="cp-grid cp-grid-4">
                <div class="cp-status-item">
                    <div class="cp-status-label"><?php esc_html_e('Monitoring', 'checkoutpulse-ai'); ?></div>
                    <div class="cp-status-value <?php echo $plugin_status['monitoring_enabled'] ? 'enabled' : 'disabled'; ?>">
                        <?php echo $plugin_status['monitoring_enabled'] ? __('Enabled', 'checkoutpulse-ai') : __('Disabled', 'checkoutpulse-ai'); ?>
                    </div>
                </div>

                <div class="cp-status-item">
                    <div class="cp-status-label"><?php esc_html_e('Email Alerts', 'checkoutpulse-ai'); ?></div>
                    <div class="cp-status-value <?php echo $plugin_status['email_notifications'] ? 'enabled' : 'disabled'; ?>">
                        <?php echo $plugin_status['email_notifications'] ? __('Enabled', 'checkoutpulse-ai') : __('Disabled', 'checkoutpulse-ai'); ?>
                    </div>
                </div>

                <div class="cp-status-item">
                    <div class="cp-status-label"><?php esc_html_e('Monitored Gateways', 'checkoutpulse-ai'); ?></div>
                    <div class="cp-status-value"><?php echo intval($plugin_status['monitored_gateways']); ?></div>
                </div>

                <div class="cp-status-item">
                    <div class="cp-status-label"><?php esc_html_e('Database Size', 'checkoutpulse-ai'); ?></div>
                    <div class="cp-status-value"><?php echo esc_html($plugin_status['database_size']); ?></div>
                </div>
            </div>
        </div>
        <div class="cp-card-footer">
            <div class="cp-last-updated">
                <?php esc_html_e('Last updated:', 'checkoutpulse-ai'); ?> <?php echo esc_html(current_time('F j, Y g:i A')); ?>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div class="cp-loading-overlay" style="display: none;">
        <div class="cp-loading">
            <div class="cp-spinner"></div>
            <?php esc_html_e('Loading...', 'checkoutpulse-ai'); ?>
        </div>
    </div>
</div>

<!-- Initialize dashboard data for JavaScript -->
<script type="text/javascript">
    var checkoutPulseDashboardData = <?php echo json_encode($dashboard_data); ?>;
</script>

<style>
    .cp-btn-block {
        display: block;
        width: 100%;
        margin-bottom: 10px;
    }

    .cp-quick-actions {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .cp-status-item {
        text-align: center;
        padding: 15px;
        background: #f8f9fa;
        border-radius: 4px;
    }

    .cp-status-label {
        font-size: 12px;
        color: #666;
        margin-bottom: 5px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .cp-status-value {
        font-size: 16px;
        font-weight: 600;
    }

    .cp-status-value.enabled {
        color: #28a745;
    }

    .cp-status-value.disabled {
        color: #dc3545;
    }

    .cp-alert-time {
        display: block;
        margin-top: 5px;
        opacity: 0.7;
    }

    .cp-loading-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(255, 255, 255, 0.8);
        z-index: 9999;
        display: flex;
        justify-content: center;
        align-items: center;
    }
</style>