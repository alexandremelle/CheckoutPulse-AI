/**
 * CheckoutPulse AI Dashboard Charts and Interactions
 *
 * @package CheckoutPulse_AI
 * @since 1.0.0
 */

(function($) {
    'use strict';

    // Global variables
    let checkoutPulseCharts = {};
    let refreshInterval = null;
    let currentTimeframe = '24h';
    let currentGateway = '';

    /**
     * Initialize dashboard functionality
     */
    function initDashboard() {
        bindEvents();
        initializeCharts();
        startAutoRefresh();
        setupRealTimeUpdates();
    }

    /**
     * Bind event handlers
     */
    function bindEvents() {
        // Timeframe selector
        $(document).on('change', '.cp-timeframe-selector', handleTimeframeChange);
        
        // Gateway filter
        $(document).on('change', '.cp-gateway-filter', handleGatewayChange);
        
        // Refresh button
        $(document).on('click', '.cp-refresh-btn', handleManualRefresh);
        
        // Export buttons
        $(document).on('click', '.cp-export-btn', handleExport);
        
        // Settings form
        $(document).on('submit', '.cp-settings-form', handleSettingsSubmit);
        
        // Test alert button
        $(document).on('click', '.cp-test-alert-btn', handleTestAlert);
        
        // Tab switching
        $(document).on('click', '.cp-nav-tab', handleTabSwitch);
        
        // Settings tabs
        $(document).on('click', '.cp-settings-tab', handleSettingsTabSwitch);
        
        // Reset settings
        $(document).on('click', '.cp-reset-settings-btn', handleResetSettings);
        
        // Import/Export settings
        $(document).on('click', '.cp-export-settings-btn', handleExportSettings);
        $(document).on('change', '.cp-import-settings-input', handleImportSettings);
        
        // Failure details modal
        $(document).on('click', '.cp-failure-details-btn', handleFailureDetails);
        
        // Close modal
        $(document).on('click', '.cp-modal-close, .cp-modal-overlay', handleModalClose);
        
        // Keyboard shortcuts
        $(document).on('keydown', handleKeyboardShortcuts);
    }

    /**
     * Handle timeframe changes
     */
    function handleTimeframeChange(e) {
        currentTimeframe = $(e.target).val();
        refreshDashboard();
        updateURL();
    }

    /**
     * Handle gateway filter changes
     */
    function handleGatewayChange(e) {
        currentGateway = $(e.target).val();
        refreshDashboard();
        updateURL();
    }

    /**
     * Handle manual refresh
     */
    function handleManualRefresh(e) {
        e.preventDefault();
        showLoading();
        refreshDashboard();
    }

    /**
     * Handle export actions
     */
    function handleExport(e) {
        e.preventDefault();
        const exportType = $(e.target).data('export-type');
        const exportUrl = buildExportUrl(exportType);
        
        // Create hidden link and trigger download
        const link = document.createElement('a');
        link.href = exportUrl;
        link.download = '';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    /**
     * Handle settings form submission
     */
    function handleSettingsSubmit(e) {
        e.preventDefault();
        
        const form = $(e.target);
        const formData = new FormData(form[0]);
        const settings = {};
        
        // Convert FormData to object
        for (let [key, value] of formData.entries()) {
            if (key === 'action' || key === 'nonce') continue;
            
            // Handle checkbox groups
            if (settings[key]) {
                if (!Array.isArray(settings[key])) {
                    settings[key] = [settings[key]];
                }
                settings[key].push(value);
            } else {
                settings[key] = value;
            }
        }
        
        saveSettings(settings);
    }

    /**
     * Handle test alert
     */
    function handleTestAlert(e) {
        e.preventDefault();
        
        const button = $(e.target);
        const alertType = button.data('alert-type') || 'test';
        
        button.prop('disabled', true).text(checkoutpulseAI.strings.loading);
        
        $.ajax({
            url: checkoutpulseAI.ajaxurl,
            type: 'POST',
            data: {
                action: 'cp_test_alert',
                alert_type: alertType,
                nonce: checkoutpulseAI.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotification(checkoutpulseAI.strings.test_alert_sent, 'success');
                } else {
                    showNotification(response.data.message || checkoutpulseAI.strings.error, 'error');
                }
            },
            error: function() {
                showNotification(checkoutpulseAI.strings.error, 'error');
            },
            complete: function() {
                button.prop('disabled', false).text('Send Test Alert');
            }
        });
    }

    /**
     * Handle tab switching
     */
    function handleTabSwitch(e) {
        e.preventDefault();
        
        const tab = $(e.target);
        const tabId = tab.data('tab');
        
        // Update active tab
        $('.cp-nav-tab').removeClass('active');
        tab.addClass('active');
        
        // Show corresponding content
        $('.cp-tab-content').hide();
        $('#cp-tab-' + tabId).show();
        
        // Update URL
        const url = new URL(window.location);
        url.searchParams.set('tab', tabId);
        window.history.pushState({}, '', url);
    }

    /**
     * Handle settings tab switching
     */
    function handleSettingsTabSwitch(e) {
        e.preventDefault();
        
        const tab = $(e.target);
        const tabId = tab.data('tab');
        
        // Update active tab
        $('.cp-settings-tab').removeClass('active');
        tab.addClass('active');
        
        // Show corresponding content
        $('.cp-settings-group').hide();
        $('#cp-settings-' + tabId).show();
        
        // Update URL
        const url = new URL(window.location);
        url.searchParams.set('settings_tab', tabId);
        window.history.pushState({}, '', url);
    }

    /**
     * Handle reset settings
     */
    function handleResetSettings(e) {
        e.preventDefault();
        
        if (!confirm(checkoutpulseAI.strings.confirm_reset)) {
            return;
        }
        
        const group = $(e.target).data('group') || 'all';
        
        $.ajax({
            url: checkoutpulseAI.ajaxurl,
            type: 'POST',
            data: {
                action: 'cp_reset_settings',
                group: group,
                nonce: checkoutpulseAI.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotification(response.data.message, 'success');
                    location.reload();
                } else {
                    showNotification(response.data.message || checkoutpulseAI.strings.error, 'error');
                }
            },
            error: function() {
                showNotification(checkoutpulseAI.strings.error, 'error');
            }
        });
    }

    /**
     * Handle export settings
     */
    function handleExportSettings(e) {
        e.preventDefault();
        
        $.ajax({
            url: checkoutpulseAI.ajaxurl,
            type: 'POST',
            data: {
                action: 'cp_export_settings',
                nonce: checkoutpulseAI.nonce
            },
            success: function(response) {
                if (response.success) {
                    downloadFile(JSON.stringify(response.data.data, null, 2), response.data.filename, 'application/json');
                } else {
                    showNotification(response.data.message || checkoutpulseAI.strings.error, 'error');
                }
            },
            error: function() {
                showNotification(checkoutpulseAI.strings.error, 'error');
            }
        });
    }

    /**
     * Handle import settings
     */
    function handleImportSettings(e) {
        const file = e.target.files[0];
        if (!file) return;
        
        const reader = new FileReader();
        reader.onload = function(event) {
            try {
                const importData = JSON.parse(event.target.result);
                
                $.ajax({
                    url: checkoutpulseAI.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'cp_import_settings',
                        import_data: JSON.stringify(importData),
                        nonce: checkoutpulseAI.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            showNotification(response.data.message, 'success');
                            location.reload();
                        } else {
                            showNotification(response.data.message || checkoutpulseAI.strings.error, 'error');
                        }
                    },
                    error: function() {
                        showNotification(checkoutpulseAI.strings.error, 'error');
                    }
                });
            } catch (error) {
                showNotification('Invalid JSON file', 'error');
            }
        };
        reader.readAsText(file);
    }

    /**
     * Handle failure details modal
     */
    function handleFailureDetails(e) {
        e.preventDefault();
        
        const failureId = $(e.target).data('failure-id');
        
        $.ajax({
            url: checkoutpulseAI.ajaxurl,
            type: 'POST',
            data: {
                action: 'cp_get_failure_details',
                failure_id: failureId,
                nonce: checkoutpulseAI.nonce
            },
            success: function(response) {
                if (response.success) {
                    showFailureModal(response.data);
                } else {
                    showNotification(response.data.message || checkoutpulseAI.strings.error, 'error');
                }
            },
            error: function() {
                showNotification(checkoutpulseAI.strings.error, 'error');
            }
        });
    }

    /**
     * Handle modal close
     */
    function handleModalClose(e) {
        if (e.target === e.currentTarget) {
            $('.cp-modal').hide();
        }
    }

    /**
     * Handle keyboard shortcuts
     */
    function handleKeyboardShortcuts(e) {
        // ESC key closes modals
        if (e.keyCode === 27) {
            $('.cp-modal').hide();
        }
        
        // Ctrl/Cmd + R refreshes dashboard
        if ((e.ctrlKey || e.metaKey) && e.keyCode === 82) {
            e.preventDefault();
            refreshDashboard();
        }
    }

    /**
     * Initialize charts
     */
    function initializeCharts() {
        initFailureTimelineChart();
        initGatewayBreakdownChart();
        initErrorCodeChart();
        initTrendChart();
    }

    /**
     * Initialize failure timeline chart
     */
    function initFailureTimelineChart() {
        const canvas = document.getElementById('cp-failure-timeline-chart');
        if (!canvas) return;
        
        const ctx = canvas.getContext('2d');
        
        checkoutPulseCharts.timeline = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'Payment Failures',
                    data: [],
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            title: function(context) {
                                return formatChartTooltipTitle(context[0].label);
                            },
                            label: function(context) {
                                return context.parsed.y + ' failures';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Time'
                        }
                    },
                    y: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Failures'
                        },
                        beginAtZero: true
                    }
                },
                interaction: {
                    mode: 'nearest',
                    axis: 'x',
                    intersect: false
                }
            }
        });
    }

    /**
     * Initialize gateway breakdown chart
     */
    function initGatewayBreakdownChart() {
        const canvas = document.getElementById('cp-gateway-breakdown-chart');
        if (!canvas) return;
        
        const ctx = canvas.getContext('2d');
        
        checkoutPulseCharts.gateway = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: [],
                datasets: [{
                    data: [],
                    backgroundColor: [
                        '#667eea',
                        '#764ba2',
                        '#f093fb',
                        '#f5576c',
                        '#4facfe',
                        '#00f2fe'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((context.parsed / total) * 100).toFixed(1);
                                return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    /**
     * Initialize error code chart
     */
    function initErrorCodeChart() {
        const canvas = document.getElementById('cp-error-code-chart');
        if (!canvas) return;
        
        const ctx = canvas.getContext('2d');
        
        checkoutPulseCharts.errorCode = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: [],
                datasets: [{
                    label: 'Error Occurrences',
                    data: [],
                    backgroundColor: 'rgba(102, 126, 234, 0.8)',
                    borderColor: '#667eea',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            title: function(context) {
                                return 'Error: ' + context[0].label;
                            },
                            label: function(context) {
                                return context.parsed.y + ' occurrences';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Error Codes'
                        }
                    },
                    y: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Occurrences'
                        },
                        beginAtZero: true
                    }
                }
            }
        });
    }

    /**
     * Initialize trend chart
     */
    function initTrendChart() {
        const canvas = document.getElementById('cp-trend-chart');
        if (!canvas) return;
        
        const ctx = canvas.getContext('2d');
        
        checkoutPulseCharts.trend = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [
                    {
                        label: 'Current Period',
                        data: [],
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
                        tension: 0.4
                    },
                    {
                        label: 'Previous Period',
                        data: [],
                        borderColor: '#6c757d',
                        backgroundColor: 'rgba(108, 117, 125, 0.1)',
                        tension: 0.4,
                        borderDash: [5, 5]
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    x: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Time'
                        }
                    },
                    y: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Failures'
                        },
                        beginAtZero: true
                    }
                }
            }
        });
    }

    /**
     * Refresh dashboard data
     */
    function refreshDashboard() {
        $.ajax({
            url: checkoutpulseAI.ajaxurl,
            type: 'POST',
            data: {
                action: 'cp_get_dashboard_data',
                timeframe: currentTimeframe,
                gateway: currentGateway,
                nonce: checkoutpulseAI.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateDashboard(response.data);
                } else {
                    showNotification(response.data.message || checkoutpulseAI.strings.error, 'error');
                }
            },
            error: function() {
                showNotification(checkoutpulseAI.strings.error, 'error');
            },
            complete: function() {
                hideLoading();
            }
        });
    }

    /**
     * Update dashboard with new data
     */
    function updateDashboard(data) {
        updateKPIs(data.kpis);
        updateCharts(data.charts);
        updateRecentFailures(data.recent_failures);
        updateLastUpdated(data.summary.updated_at);
    }

    /**
     * Update KPI cards
     */
    function updateKPIs(kpis) {
        // Update failure count
        $('.cp-kpi-failures .cp-kpi-value').text(formatNumber(kpis.total_failures));
        updateTrend('.cp-kpi-failures .cp-kpi-trend', kpis.trends.failures);
        
        // Update failure rate
        $('.cp-kpi-rate .cp-kpi-value').text(formatPercentage(kpis.failure_rate));
        updateTrend('.cp-kpi-rate .cp-kpi-trend', kpis.trends.rate);
        
        // Update lost amount
        $('.cp-kpi-amount .cp-kpi-value').text(formatCurrency(kpis.total_amount_lost));
        updateTrend('.cp-kpi-amount .cp-kpi-trend', kpis.trends.amount);
        
        // Update average amount
        $('.cp-kpi-avg .cp-kpi-value').text(formatCurrency(kpis.avg_failure_amount));
    }

    /**
     * Update trend indicator
     */
    function updateTrend(selector, trend) {
        const element = $(selector);
        element.removeClass('up down neutral');
        element.addClass(trend.direction);
        
        let icon = '→';
        if (trend.direction === 'up') icon = '↑';
        if (trend.direction === 'down') icon = '↓';
        
        element.text(icon + ' ' + trend.value + (trend.type === 'percentage' ? '%' : ''));
    }

    /**
     * Update charts with new data
     */
    function updateCharts(charts) {
        // Update timeline chart
        if (checkoutPulseCharts.timeline && charts.timeline) {
            updateTimelineChart(charts.timeline);
        }
        
        // Update gateway chart
        if (checkoutPulseCharts.gateway && charts.gateways) {
            updateGatewayChart(charts.gateways);
        }
        
        // Update error code chart
        if (checkoutPulseCharts.errorCode && charts.error_codes) {
            updateErrorCodeChart(charts.error_codes);
        }
    }

    /**
     * Update timeline chart
     */
    function updateTimelineChart(data) {
        const chart = checkoutPulseCharts.timeline;
        
        chart.data.labels = data.map(item => formatChartLabel(item.period));
        chart.data.datasets[0].data = data.map(item => item.failures);
        
        chart.update('none');
    }

    /**
     * Update gateway chart
     */
    function updateGatewayChart(data) {
        const chart = checkoutPulseCharts.gateway;
        
        chart.data.labels = data.map(item => item.group_key || 'Unknown');
        chart.data.datasets[0].data = data.map(item => item.failure_count);
        
        chart.update('none');
    }

    /**
     * Update error code chart
     */
    function updateErrorCodeChart(data) {
        const chart = checkoutPulseCharts.errorCode;
        
        // Limit to top 10 errors
        const topErrors = data.slice(0, 10);
        
        chart.data.labels = topErrors.map(item => item.group_key || 'Unknown');
        chart.data.datasets[0].data = topErrors.map(item => item.failure_count);
        
        chart.update('none');
    }

    /**
     * Update recent failures table
     */
    function updateRecentFailures(failures) {
        const tbody = $('.cp-recent-failures tbody');
        tbody.empty();
        
        if (failures.length === 0) {
            tbody.append('<tr><td colspan="6" class="cp-text-center">No recent failures</td></tr>');
            return;
        }
        
        failures.forEach(function(failure) {
            const row = $('<tr></tr>');
            row.append('<td>#' + failure.order_id + '</td>');
            row.append('<td>' + failure.gateway + '</td>');
            row.append('<td>' + (failure.error_code || 'Unknown') + '</td>');
            row.append('<td>' + formatCurrency(failure.amount) + '</td>');
            row.append('<td>' + formatDate(failure.failed_at) + '</td>');
            row.append('<td><button class="cp-btn cp-btn-sm cp-failure-details-btn" data-failure-id="' + failure.id + '">Details</button></td>');
            tbody.append(row);
        });
    }

    /**
     * Save settings
     */
    function saveSettings(settings) {
        $.ajax({
            url: checkoutpulseAI.ajaxurl,
            type: 'POST',
            data: {
                action: 'cp_save_settings',
                settings: settings,
                nonce: checkoutpulseAI.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotification(checkoutpulseAI.strings.settings_saved, 'success');
                } else {
                    showNotification(response.data.message || checkoutpulseAI.strings.error, 'error');
                }
            },
            error: function() {
                showNotification(checkoutpulseAI.strings.error, 'error');
            }
        });
    }

    /**
     * Show failure details modal
     */
    function showFailureModal(failure) {
        const modal = $(`
            <div class="cp-modal" style="display: block;">
                <div class="cp-modal-overlay"></div>
                <div class="cp-modal-content">
                    <div class="cp-modal-header">
                        <h3>Failure Details - Order #${failure.order_id}</h3>
                        <button class="cp-modal-close">&times;</button>
                    </div>
                    <div class="cp-modal-body">
                        <div class="cp-failure-details">
                            <div class="cp-detail-group">
                                <label>Gateway:</label>
                                <span>${failure.gateway}</span>
                            </div>
                            <div class="cp-detail-group">
                                <label>Error Code:</label>
                                <span>${failure.error_code || 'Unknown'}</span>
                            </div>
                            <div class="cp-detail-group">
                                <label>Error Message:</label>
                                <span>${failure.error_message || 'No message available'}</span>
                            </div>
                            <div class="cp-detail-group">
                                <label>Amount:</label>
                                <span>${formatCurrency(failure.amount)} ${failure.currency}</span>
                            </div>
                            <div class="cp-detail-group">
                                <label>Failed At:</label>
                                <span>${formatDate(failure.failed_at)}</span>
                            </div>
                            ${failure.order_details ? `
                                <div class="cp-detail-group">
                                    <label>Customer Email:</label>
                                    <span>${failure.order_details.customer_email}</span>
                                </div>
                                <div class="cp-detail-group">
                                    <label>Order Status:</label>
                                    <span>${failure.order_details.order_status}</span>
                                </div>
                            ` : ''}
                        </div>
                    </div>
                    <div class="cp-modal-footer">
                        ${failure.order_details ? `<a href="${failure.order_details.order_url}" class="cp-btn cp-btn-primary" target="_blank">View Order</a>` : ''}
                        <button class="cp-btn cp-btn-secondary cp-modal-close">Close</button>
                    </div>
                </div>
            </div>
        `);
        
        $('body').append(modal);
    }

    /**
     * Start auto-refresh
     */
    function startAutoRefresh() {
        // Refresh every 5 minutes
        refreshInterval = setInterval(refreshDashboard, 300000);
    }

    /**
     * Setup real-time updates (WebSocket would go here)
     */
    function setupRealTimeUpdates() {
        // Placeholder for WebSocket implementation
        // This would connect to a WebSocket server for real-time updates
    }

    /**
     * Utility functions
     */
    function formatNumber(num) {
        return new Intl.NumberFormat().format(num);
    }

    function formatCurrency(amount) {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD'
        }).format(amount);
    }

    function formatPercentage(num) {
        return parseFloat(num).toFixed(1) + '%';
    }

    function formatDate(dateString) {
        return new Date(dateString).toLocaleString();
    }

    function formatChartLabel(label) {
        // Format chart labels based on timeframe
        const date = new Date(label);
        if (currentTimeframe === '1h') {
            return date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        } else if (currentTimeframe === '24h') {
            return date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        } else {
            return date.toLocaleDateString();
        }
    }

    function formatChartTooltipTitle(label) {
        return 'Time: ' + label;
    }

    function buildExportUrl(exportType) {
        const params = new URLSearchParams({
            cp_export: exportType,
            timeframe: currentTimeframe,
            gateway: currentGateway,
            _wpnonce: checkoutpulseAI.nonce
        });
        
        return window.location.pathname + '?' + params.toString();
    }

    function updateURL() {
        const url = new URL(window.location);
        url.searchParams.set('timeframe', currentTimeframe);
        url.searchParams.set('gateway', currentGateway);
        window.history.replaceState({}, '', url);
    }

    function updateLastUpdated(timestamp) {
        $('.cp-last-updated').text('Last updated: ' + formatDate(timestamp));
    }

    function showLoading() {
        $('.cp-loading-overlay').show();
    }

    function hideLoading() {
        $('.cp-loading-overlay').hide();
    }

    function showNotification(message, type) {
        const notification = $(`
            <div class="cp-notification cp-notification-${type}">
                <span>${message}</span>
                <button class="cp-notification-close">&times;</button>
            </div>
        `);
        
        $('body').append(notification);
        
        // Auto-hide after 5 seconds
        setTimeout(function() {
            notification.fadeOut(function() {
                notification.remove();
            });
        }, 5000);
        
        // Handle manual close
        notification.find('.cp-notification-close').on('click', function() {
            notification.fadeOut(function() {
                notification.remove();
            });
        });
    }

    function downloadFile(content, filename, mimeType) {
        const blob = new Blob([content], {type: mimeType});
        const url = URL.createObjectURL(blob);
        
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        URL.revokeObjectURL(url);
    }

    // Initialize when DOM is ready
    $(document).ready(function() {
        if ($('.checkoutpulse-ai-admin').length > 0) {
            initDashboard();
        }
    });

    // Cleanup on page unload
    $(window).on('beforeunload', function() {
        if (refreshInterval) {
            clearInterval(refreshInterval);
        }
        
        // Destroy charts
        Object.values(checkoutPulseCharts).forEach(function(chart) {
            if (chart && typeof chart.destroy === 'function') {
                chart.destroy();
            }
        });
    });

})(jQuery);