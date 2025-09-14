/**
 * QvaClick Email Manager - Dashboard JavaScript
 * Funcionalidades interactivas para el dashboard principal
 * 
 * @since 3.0.0 (Fase 3 - Sprint 1)
 * @author David Guerra | @cedav95 | QvaClick Team
 */

(function($) {
    'use strict';
    
    // Variables globales
    let charts = {
        templatesUsage: null,
        templatesType: null,
        activityTimeline: null
    };
    
    let dashboardData = {
        stats: {},
        metrics: {},
        chartData: {}
    };
    
    /**
     * Inicialización del dashboard
     */
    $(document).ready(function() {
        initializeDashboard();
        setupEventListeners();
        loadChartData();
    });
    
    /**
     * Inicializar el dashboard
     */
    function initializeDashboard() {
        console.log('🚀 QvaClick Email Dashboard inicializando...');
        
        // Verificar que Chart.js esté disponible
        if (typeof Chart === 'undefined') {
            console.error('❌ Chart.js no está disponible');
            showNotification('Error: Chart.js no está cargado', 'error');
            return;
        }
        
        // Configuración global de Chart.js
        Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
        Chart.defaults.font.size = 12;
        Chart.defaults.color = '#6c7781';
        Chart.defaults.plugins.legend.display = false;
        Chart.defaults.responsive = true;
        Chart.defaults.maintainAspectRatio = false;
        
        console.log('✅ Dashboard inicializado correctamente');
    }
    
    /**
     * Configurar event listeners
     */
    function setupEventListeners() {
        // Botón refresh dashboard
        $('#qvc-refresh-dashboard').on('click', function(e) {
            e.preventDefault();
            refreshDashboard();
        });
        
        // Botón escanear templates
        $('#qvc-scan-templates').on('click', function(e) {
            e.preventDefault();
            scanTemplates();
        });
        
        // Botón exportar todo
        $('#qvc-export-all').on('click', function(e) {
            e.preventDefault();
            exportAllTemplates();
        });
        
        // Auto-refresh cada 5 minutos
        setInterval(function() {
            refreshDashboard(true); // Silent refresh
        }, 300000); // 5 minutos
        
        // Track dashboard view event
        trackEvent('dashboard_viewed');
        
        console.log('👂 Event listeners configurados');
    }
    
    /**
     * Track events via AJAX
     */
    function trackEvent(eventType, templateKey = '', additionalData = {}) {
        if (!qvcDashboard.ajaxurl) return;
        
        $.ajax({
            url: qvcDashboard.ajaxurl,
            type: 'POST',
            data: {
                action: 'qvc_track_metric',
                nonce: qvcDashboard.nonce,
                event_type: eventType,
                template_key: templateKey,
                data: additionalData
            },
            success: function(response) {
                // Silently track events
                console.log('📊 Event tracked:', eventType);
            },
            error: function() {
                // Fail silently for tracking
            }
        });
    }
    
    /**
     * Cargar datos para los gráficos
     */
    function loadChartData() {
        // Usar datos del servidor si están disponibles
        if (window.qvcChartData) {
            dashboardData.chartData = window.qvcChartData;
        } else {
            // Datos de ejemplo para desarrollo
            dashboardData.chartData = {
                templatesUsage: {
                    labels: ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'],
                    data: [12, 15, 18, 14, 22, 8, 5]
                },
                templatesType: {
                    labels: ['Email de Bienvenida', 'Notificaciones', 'Confirmaciones', 'Otros'],
                    data: [35, 25, 20, 20],
                    colors: ['#0073aa', '#46b450', '#ffb900', '#826eb4']
                },
                activityTimeline: {
                    labels: ['Hace 6h', 'Hace 5h', 'Hace 4h', 'Hace 3h', 'Hace 2h', 'Hace 1h', 'Ahora'],
                    data: [3, 7, 2, 8, 12, 6, 4]
                }
            };
        }
        
        // Inicializar gráficos
        initializeCharts();
    }
    
    /**
     * Inicializar todos los gráficos
     */
    function initializeCharts() {
        initTemplatesUsageChart();
        initTemplatesTypeChart();
        initActivityTimelineChart();
        
        console.log('📊 Gráficos inicializados');
    }
    
    /**
     * Gráfico de uso de templates
     */
    function initTemplatesUsageChart() {
        const ctx = document.getElementById('qvc-templates-usage-chart');
        if (!ctx) return;
        
        const data = dashboardData.chartData.templatesUsage;
        
        charts.templatesUsage = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Templates Utilizados',
                    data: data.data,
                    borderColor: '#0073aa',
                    backgroundColor: 'rgba(0, 115, 170, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#0073aa',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                plugins: {
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: '#ffffff',
                        bodyColor: '#ffffff',
                        borderColor: '#0073aa',
                        borderWidth: 1,
                        cornerRadius: 6,
                        displayColors: false,
                        callbacks: {
                            label: function(context) {
                                return 'Templates: ' + context.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                }
            }
        });
    }
    
    /**
     * Gráfico de templates por tipo
     */
    function initTemplatesTypeChart() {
        const ctx = document.getElementById('qvc-templates-type-chart');
        if (!ctx) return;
        
        const data = dashboardData.chartData.templatesType;
        
        charts.templatesType = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.data,
                    backgroundColor: data.colors,
                    borderWidth: 0,
                    hoverBorderWidth: 3,
                    hoverBorderColor: '#ffffff'
                }]
            },
            options: {
                cutout: '60%',
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: '#ffffff',
                        bodyColor: '#ffffff',
                        cornerRadius: 6,
                        displayColors: true,
                        callbacks: {
                            label: function(context) {
                                const percentage = Math.round((context.parsed / context.dataset.data.reduce((a, b) => a + b, 0)) * 100);
                                return context.label + ': ' + percentage + '%';
                            }
                        }
                    }
                }
            }
        });
    }
    
    /**
     * Gráfico de actividad timeline
     */
    function initActivityTimelineChart() {
        const ctx = document.getElementById('qvc-activity-timeline-chart');
        if (!ctx) return;
        
        const data = dashboardData.chartData.activityTimeline;
        
        charts.activityTimeline = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Actividad',
                    data: data.data,
                    backgroundColor: 'rgba(0, 115, 170, 0.7)',
                    borderColor: '#0073aa',
                    borderWidth: 1,
                    borderRadius: 4,
                    borderSkipped: false,
                }]
            },
            options: {
                plugins: {
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: '#ffffff',
                        bodyColor: '#ffffff',
                        cornerRadius: 6,
                        displayColors: false,
                        callbacks: {
                            label: function(context) {
                                return 'Modificaciones: ' + context.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            stepSize: 1
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }
    
    /**
     * Refrescar dashboard
     */
    function refreshDashboard(silent = false) {
        if (!silent) {
            showLoading(true);
            showNotification('Actualizando dashboard...', 'info');
        }
        
        console.log('🔄 Refrescando dashboard...');
        
        $.ajax({
            url: qvcDashboard.ajaxurl,
            type: 'POST',
            data: {
                action: 'qvc_dashboard_refresh',
                nonce: qvcDashboard.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Actualizar datos
                    dashboardData.stats = response.data.stats;
                    dashboardData.metrics = response.data.metrics;
                    
                    // Actualizar UI
                    updateStatsCards();
                    updateCharts();
                    
                    if (!silent) {
                        showNotification(response.data.message, 'success');
                    }
                    
                    console.log('✅ Dashboard actualizado correctamente');
                } else {
                    showNotification('Error al actualizar dashboard', 'error');
                    console.error('❌ Error en respuesta AJAX:', response);
                }
            },
            error: function(xhr, status, error) {
                showNotification('Error de conexión al actualizar dashboard', 'error');
                console.error('❌ Error AJAX:', error);
            },
            complete: function() {
                if (!silent) {
                    showLoading(false);
                }
            }
        });
    }
    
    /**
     * Escanear templates
     */
    function scanTemplates() {
        showLoading(true);
        showNotification('Escaneando templates...', 'info');
        
        // Track scan event
        trackEvent('scan_performed');
        
        console.log('🔍 Iniciando escaneo de templates...');
        
        // Simular escaneo (en producción esto llamaría a la función real)
        setTimeout(function() {
            showLoading(false);
            showNotification('Escaneo completado. Se encontraron 3 nuevos templates.', 'success');
            refreshDashboard(true);
            console.log('✅ Escaneo completado');
        }, 2000);
    }
    
    /**
     * Exportar todos los templates
     */
    function exportAllTemplates() {
        showNotification('Preparando exportación...', 'info');
        
        // Track export event
        trackEvent('templates_exported');
        
        console.log('📦 Preparando exportación...');
        
        // Simular exportación (en producción esto generaría el archivo)
        setTimeout(function() {
            // Crear un enlace de descarga simulado
            const blob = new Blob([JSON.stringify({
                exported_at: new Date().toISOString(),
                plugin_version: '3.0.0',
                templates: dashboardData.stats
            }, null, 2)], { type: 'application/json' });
            
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.style.display = 'none';
            a.href = url;
            a.download = 'qvc-email-templates-' + new Date().getTime() + '.json';
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);
            
            showNotification('Exportación completada correctamente', 'success');
            console.log('✅ Exportación completada');
        }, 1000);
    }
    
    /**
     * Actualizar cards de estadísticas
     */
    function updateStatsCards() {
        // Implementar actualización de cards cuando vengan datos del servidor
        console.log('📊 Actualizando stats cards...');
    }
    
    /**
     * Actualizar gráficos con nuevos datos
     */
    function updateCharts() {
        // Simular nuevos datos
        const newData = {
            templatesUsage: [15, 18, 20, 16, 25, 12, 8],
            activityTimeline: [5, 9, 3, 10, 14, 8, 6]
        };
        
        if (charts.templatesUsage) {
            charts.templatesUsage.data.datasets[0].data = newData.templatesUsage;
            charts.templatesUsage.update('active');
        }
        
        if (charts.activityTimeline) {
            charts.activityTimeline.data.datasets[0].data = newData.activityTimeline;
            charts.activityTimeline.update('active');
        }
        
        console.log('📈 Gráficos actualizados');
    }
    
    /**
     * Mostrar/ocultar loading overlay
     */
    function showLoading(show) {
        if (show) {
            $('#qvc-dashboard-loading').fadeIn(200);
        } else {
            $('#qvc-dashboard-loading').fadeOut(300);
        }
    }
    
    /**
     * Mostrar notificaciones
     */
    function showNotification(message, type = 'info') {
        // Crear elemento de notificación
        const notification = $('<div class="qvc-notification qvc-notification-' + type + '">' +
            '<span class="dashicons dashicons-' + getNotificationIcon(type) + '"></span>' +
            '<span class="qvc-notification-message">' + message + '</span>' +
            '<button class="qvc-notification-close" aria-label="Cerrar">&times;</button>' +
            '</div>');
        
        // Añadir estilos CSS inline para las notificaciones
        if (!$('#qvc-notification-styles').length) {
            $('<style id="qvc-notification-styles">' +
                '.qvc-notification { position: fixed; top: 32px; right: 20px; z-index: 999999; ' +
                'background: white; border-left: 4px solid #0073aa; padding: 12px 16px; ' +
                'border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); ' +
                'display: flex; align-items: center; gap: 8px; max-width: 400px; ' +
                'transform: translateX(100%); transition: transform 0.3s ease; }' +
                '.qvc-notification.show { transform: translateX(0); }' +
                '.qvc-notification-success { border-color: #46b450; }' +
                '.qvc-notification-error { border-color: #dc3232; }' +
                '.qvc-notification-warning { border-color: #ffb900; }' +
                '.qvc-notification .dashicons { flex-shrink: 0; }' +
                '.qvc-notification-message { flex: 1; font-size: 13px; }' +
                '.qvc-notification-close { background: none; border: none; font-size: 16px; ' +
                'cursor: pointer; padding: 0; margin-left: 8px; }' +
                '</style>').appendTo('head');
        }
        
        // Añadir al DOM
        $('body').append(notification);
        
        // Mostrar con animación
        setTimeout(function() {
            notification.addClass('show');
        }, 100);
        
        // Auto-ocultar después de 5 segundos
        setTimeout(function() {
            hideNotification(notification);
        }, 5000);
        
        // Event listener para cerrar
        notification.find('.qvc-notification-close').on('click', function() {
            hideNotification(notification);
        });
    }
    
    /**
     * Ocultar notificación
     */
    function hideNotification(notification) {
        notification.removeClass('show');
        setTimeout(function() {
            notification.remove();
        }, 300);
    }
    
    /**
     * Obtener icono para notificación
     */
    function getNotificationIcon(type) {
        const icons = {
            success: 'yes-alt',
            error: 'dismiss',
            warning: 'warning',
            info: 'info'
        };
        return icons[type] || 'info';
    }
    
    /**
     * Manejo de errores globales
     */
    window.addEventListener('error', function(e) {
        console.error('❌ Error global:', e.error);
        showNotification('Ha ocurrido un error inesperado', 'error');
    });
    
    // Exponer funciones globalmente para debugging
    window.qvcDashboardDebug = {
        refreshDashboard: refreshDashboard,
        scanTemplates: scanTemplates,
        exportAllTemplates: exportAllTemplates,
        charts: charts,
        data: dashboardData
    };
    
    console.log('✅ QvaClick Email Dashboard JavaScript cargado completamente');
    
})(jQuery);

    // ================================
    // INTERACTIVE CHARTS FUNCTIONALITY
    // ================================
    
    const InteractiveCharts = {
        charts: {},
        currentPeriod: '7_days',
        realtimeInterval: null,
        
        init: function() {
            console.log(' Initializing Interactive Charts...');
            this.bindEvents();
            this.loadAllCharts();
            this.startRealtimeUpdates();
        },
        
        bindEvents: function() {
            // Period selector
            $(document).on('change', '#qvc-analytics-period', (e) => {
                this.currentPeriod = $(e.target).val();
                this.refreshAllCharts();
            });
            
            // Chart type selectors
            $(document).on('change', '#qvc-trends-type', (e) => {
                this.loadChart('trends', $(e.target).val(), 'line');
            });
            
            $(document).on('change', '#qvc-comparison-type', (e) => {
                this.loadChart('comparison', $(e.target).val(), 'bar');
            });
            
            $(document).on('change', '#qvc-distribution-type', (e) => {
                this.loadChart('distribution', $(e.target).val(), 'doughnut');
            });
            
            // Export charts
            $(document).on('click', '#qvc-export-charts', () => {
                this.exportChartsData();
            });
        },
        
        loadAllCharts: function() {
            this.loadChart('trends', 'events_trend', 'line');
            this.loadChart('comparison', 'events_comparison', 'bar');
            this.loadChart('distribution', 'events_distribution', 'doughnut');
        },
        
        loadChart: function(chartId, chartType, chartStyle) {
            const canvasId = `qvc-${chartId}-chart`;
            const container = $(#${canvasId}).parent();
            
            // Show loading state
            this.showChartLoading(container);
            
            const ajaxData = {
                action: 'qvc_get_chart_data',
                nonce: qvcDashboard.nonce,
                chart_type: chartType,
                chart_style: chartStyle,
                period: this.currentPeriod
            };
            
            if (chartStyle === 'bar') {
                ajaxData.compare_period = this.getComparePeriod();
            }
            
            $.ajax({
                url: qvcDashboard.ajaxurl,
                type: 'POST',
                data: ajaxData,
                success: (response) => {
                    if (response.success) {
                        this.renderChart(canvasId, chartStyle, response.data, chartType);
                        this.hideChartLoading(container);
                    } else {
                        this.showChartError(container, response.data || 'Error al cargar gr�fico');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Chart load error:', error);
                    this.showChartError(container, 'Error de conexi�n');
                }
            });
        },
        
        renderChart: function(canvasId, type, data, chartType) {
            const ctx = document.getElementById(canvasId);
            if (!ctx) return;
            
            // Destroy existing chart
            if (this.charts[canvasId]) {
                this.charts[canvasId].destroy();
            }
            
            // Chart configuration based on type
            let config = this.getChartConfig(type, data, chartType);
            
            // Create new chart
            this.charts[canvasId] = new Chart(ctx, config);
        },
        
        getChartConfig: function(type, data, chartType) {
            const baseConfig = {
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                padding: 15,
                                font: {
                                    size: 12
                                }
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.9)',
                            titleColor: 'white',
                            bodyColor: 'white',
                            borderColor: 'rgba(255, 255, 255, 0.2)',
                            borderWidth: 1,
                            cornerRadius: 8,
                            displayColors: true,
                            padding: 12
                        }
                    },
                    animation: {
                        duration: 800,
                        easing: 'easeInOutQuart'
                    }
                }
            };
            
            switch (type) {
                case 'line':
                    return this.getLineChartConfig(baseConfig, chartType);
                case 'bar':
                    return this.getBarChartConfig(baseConfig, chartType);
                case 'doughnut':
                    return this.getDoughnutChartConfig(baseConfig, chartType);
                default:
                    return baseConfig;
            }
        },
        
        getLineChartConfig: function(config, chartType) {
            config.type = 'line';
            config.options.scales = {
                x: {
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    },
                    ticks: {
                        font: {
                            size: 11
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    },
                    ticks: {
                        font: {
                            size: 11
                        }
                    }
                }
            };
            
            // Special configuration for dual-axis charts
            if (chartType === 'user_activity_trend') {
                config.options.scales.y1 = {
                    type: 'linear',
                    position: 'right',
                    beginAtZero: true,
                    grid: {
                        drawOnChartArea: false
                    },
                    ticks: {
                        font: {
                            size: 11
                        }
                    }
                };
            }
            
            return config;
        },
        
        getBarChartConfig: function(config, chartType) {
            config.type = 'bar';
            config.options.scales = {
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        maxRotation: 45,
                        font: {
                            size: 11
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    },
                    ticks: {
                        font: {
                            size: 11
                        }
                    }
                }
            };
            
            config.options.plugins.legend.position = 'top';
            
            return config;
        },
        
        getDoughnutChartConfig: function(config, chartType) {
            config.type = 'doughnut';
            config.options.cutout = '50%';
            config.options.plugins.legend.position = 'right';
            
            // Add percentage display
            config.options.plugins.tooltip.callbacks = {
                label: function(context) {
                    const label = context.label || '';
                    const value = context.parsed;
                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                    const percentage = ((value / total) * 100).toFixed(1);
                    return `: ${value} (${percentage}%)`;
                }
            };
            
            return config;
        },
        
        refreshAllCharts: function() {
            const trends = $('#qvc-trends-type').val() || 'events_trend';
            const comparison = $('#qvc-comparison-type').val() || 'events_comparison';
            const distribution = $('#qvc-distribution-type').val() || 'events_distribution';
            
            this.loadChart('trends', trends, 'line');
            this.loadChart('comparison', comparison, 'bar');
            this.loadChart('distribution', distribution, 'doughnut');
        },
        
        getComparePeriod: function() {
            switch (this.currentPeriod) {
                case '24_hours': return '48_hours';
                case '7_days': return '14_days';
                case '30_days': return '60_days';
                case '90_days': return '180_days';
                default: return '14_days';
            }
        },
        
        showChartLoading: function(container) {
            container.html(`
                <div class="qvc-chart-loading">
                    <span class="dashicons dashicons-update-alt"></span>
                    <p>Cargando gr�fico...</p>
                </div>
            `);
        },
        
        hideChartLoading: function(container) {
            container.find('.qvc-chart-loading').remove();
        },
        
        showChartError: function(container, message) {
            container.html(`
                <div class="qvc-chart-error">
                    <span class="dashicons dashicons-warning"></span>
                    <h4>Error al cargar</h4>
                    <p>${message}</p>
                </div>
            `);
        },
        
        exportChartsData: function() {
            const trends = $('#qvc-trends-type').val() || 'events_trend';
            
            $.ajax({
                url: qvcDashboard.ajaxurl,
                type: 'POST',
                data: {
                    action: 'qvc_export_chart_data',
                    nonce: qvcDashboard.nonce,
                    chart_type: trends,
                    period: this.currentPeriod
                },
                success: (response) => {
                    if (response.success) {
                        // Download file
                        const blob = new Blob([response.data.data], { type: 'application/json' });
                        const url = window.URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = response.data.filename;
                        document.body.appendChild(a);
                        a.click();
                        window.URL.revokeObjectURL(url);
                        document.body.removeChild(a);
                        
                        showNotification('Datos exportados correctamente', 'success');
                    } else {
                        showNotification('Error al exportar datos', 'error');
                    }
                },
                error: () => {
                    showNotification('Error de conexi�n al exportar', 'error');
                }
            });
        },
        
        startRealtimeUpdates: function() {
            // Update real-time metrics every 30 seconds
            this.realtimeInterval = setInterval(() => {
                this.updateRealtimeMetrics();
            }, 30000);
            
            // Initial load
            this.updateRealtimeMetrics();
        },
        
        updateRealtimeMetrics: function() {
            $.ajax({
                url: qvcDashboard.ajaxurl,
                type: 'POST',
                data: {
                    action: 'qvc_dashboard_refresh',
                    nonce: qvcDashboard.nonce,
                    section: 'realtime_metrics'
                },
                success: (response) => {
                    if (response.success && response.data.realtime) {
                        const metrics = response.data.realtime;
                        $('#qvc-events-per-minute').text(metrics.events_per_minute || 0);
                        $('#qvc-active-users').text(metrics.active_users || 0);
                        $('#qvc-popular-template').text(metrics.popular_template || '-');
                    }
                },
                error: (xhr, status, error) => {
                    console.warn('Realtime metrics update failed:', error);
                }
            });
        }
    };
    
    // Expose InteractiveCharts globally
    window.qvcInteractiveCharts = InteractiveCharts;
    
    // Initialize interactive charts when document is ready
    $(document).ready(function() {
        // Wait for basic dashboard to load, then init interactive charts
        setTimeout(() => {
            if (typeof Chart !== 'undefined' && $('.qvc-analytics-section').length > 0) {
                InteractiveCharts.init();
            }
        }, 1000);
    });

