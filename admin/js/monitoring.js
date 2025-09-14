/**
 * QvaClick Monitoring JavaScript
 * Sistema de monitoreo en tiempo real con gráficos
 */

(function($) {
    'use strict';

    let monitoringCharts = {};
    let autoRefreshInterval;
    let isAutoRefreshActive = false;

    $(document).ready(function() {
        initializeMonitoring();
        setupEventHandlers();
        startAutoRefresh();
        createCharts();
    });

    /**
     * Inicializar sistema de monitoreo
     */
    function initializeMonitoring() {
        console.log('QvaClick Monitoring System initialized');
        
        // Añadir indicador de auto-refresh
        $('body').append('<div id="auto-refresh-indicator" class="auto-refresh-indicator">Actualizando...</div>');
        
        // Verificar si hay datos iniciales
        if (typeof window.qvcMonitoringData === 'undefined') {
            console.warn('No monitoring data available');
            return;
        }
        
        updateDashboard(window.qvcMonitoringData);
    }

    /**
     * Configurar manejadores de eventos
     */
    function setupEventHandlers() {
        // Limpiar alertas
        $('#clear-alerts').on('click', function() {
            if (confirm('¿Estás seguro de que quieres limpiar todas las alertas?')) {
                clearAlerts();
            }
        });

        // Resetear estadísticas
        $('#reset-stats').on('click', function() {
            if (confirm('¿Estás seguro de que quieres resetear todas las estadísticas? Esta acción no se puede deshacer.')) {
                resetStats();
            }
        });

        // Probar monitoreo
        $('#test-monitoring').on('click', function() {
            testMonitoring();
        });

        // Exportar datos
        $('#export-data').on('click', function() {
            exportMonitoringData();
        });

        // Toggle auto-refresh
        $(document).on('keydown', function(e) {
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                toggleAutoRefresh();
            }
        });
    }

    /**
     * Crear gráficos
     */
    function createCharts() {
        if (typeof Chart === 'undefined') {
            console.error('Chart.js not loaded');
            return;
        }

        createHourlyActivityChart();
        createCircuitBreakerChart();
    }

    /**
     * Crear gráfico de actividad por horas
     */
    function createHourlyActivityChart() {
        const ctx = document.getElementById('hourlyActivityChart');
        if (!ctx) return;

        const data = window.qvcMonitoringData || {};
        const emailStats = data.email_stats || {};
        const ticketStats = data.ticket_stats || {};

        // Preparar datos para las últimas 24 horas
        const hours = [];
        const emailData = [];
        const ticketData = [];

        for (let i = 23; i >= 0; i--) {
            const hour = new Date();
            hour.setHours(hour.getHours() - i);
            const hourKey = hour.getFullYear() + '-' + 
                           String(hour.getMonth() + 1).padStart(2, '0') + '-' + 
                           String(hour.getDate()).padStart(2, '0') + ' ' + 
                           String(hour.getHours()).padStart(2, '0');
            
            hours.push(hour.getHours() + ':00');
            emailData.push(emailStats.last_hour?.[hourKey] || 0);
            ticketData.push(ticketStats.last_hour?.[hourKey] || 0);
        }

        monitoringCharts.hourlyActivity = new Chart(ctx, {
            type: 'line',
            data: {
                labels: hours,
                datasets: [
                    {
                        label: 'Emails Enviados',
                        data: emailData,
                        borderColor: '#3498db',
                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                        tension: 0.4
                    },
                    {
                        label: 'Tickets Creados',
                        data: ticketData,
                        borderColor: '#e74c3c',
                        backgroundColor: 'rgba(231, 76, 60, 0.1)',
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top'
                    }
                }
            }
        });
    }

    /**
     * Crear gráfico de circuit breakers
     */
    function createCircuitBreakerChart() {
        const ctx = document.getElementById('circuitBreakerChart');
        if (!ctx) return;

        const data = window.qvcMonitoringData || {};
        const circuitStatus = data.circuit_status || {};
        const failureCounts = circuitStatus.failure_counts || {};

        const functions = Object.keys(failureCounts);
        const failures = Object.values(failureCounts);

        // Colores basados en el estado
        const colors = failures.map(count => {
            if (count >= 5) return '#e74c3c'; // Rojo (abierto)
            if (count > 0) return '#f39c12';   // Amarillo (parcial)
            return '#2ecc71';                  // Verde (cerrado)
        });

        monitoringCharts.circuitBreaker = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: functions.map(f => f.replace('qvc_', '')),
                datasets: [{
                    label: 'Fallos',
                    data: failures,
                    backgroundColor: colors,
                    borderColor: colors,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 10,
                        ticks: {
                            stepSize: 1
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
    }

    /**
     * Actualizar dashboard
     */
    function updateDashboard(data) {
        window.qvcMonitoringData = data;
        
        // Actualizar gráficos
        if (monitoringCharts.hourlyActivity) {
            updateHourlyActivityChart(data);
        }
        
        if (monitoringCharts.circuitBreaker) {
            updateCircuitBreakerChart(data);
        }
        
        // Actualizar estadísticas
        updateStats(data);
        
        // Actualizar indicadores de estado
        updateStatusIndicators(data);
    }

    /**
     * Actualizar gráfico de actividad
     */
    function updateHourlyActivityChart(data) {
        const chart = monitoringCharts.hourlyActivity;
        const emailStats = data.email_stats || {};
        const ticketStats = data.ticket_stats || {};

        // Actualizar datos
        for (let i = 0; i < 24; i++) {
            const hour = new Date();
            hour.setHours(hour.getHours() - (23 - i));
            const hourKey = hour.getFullYear() + '-' + 
                           String(hour.getMonth() + 1).padStart(2, '0') + '-' + 
                           String(hour.getDate()).padStart(2, '0') + ' ' + 
                           String(hour.getHours()).padStart(2, '0');
            
            chart.data.datasets[0].data[i] = emailStats.last_hour?.[hourKey] || 0;
            chart.data.datasets[1].data[i] = ticketStats.last_hour?.[hourKey] || 0;
        }

        chart.update('none');
    }

    /**
     * Actualizar gráfico de circuit breakers
     */
    function updateCircuitBreakerChart(data) {
        const chart = monitoringCharts.circuitBreaker;
        const circuitStatus = data.circuit_status || {};
        const failureCounts = circuitStatus.failure_counts || {};

        const failures = Object.values(failureCounts);
        const colors = failures.map(count => {
            if (count >= 5) return '#e74c3c';
            if (count > 0) return '#f39c12';
            return '#2ecc71';
        });

        chart.data.datasets[0].data = failures;
        chart.data.datasets[0].backgroundColor = colors;
        chart.data.datasets[0].borderColor = colors;
        
        chart.update('none');
    }

    /**
     * Actualizar estadísticas
     */
    function updateStats(data) {
        const emailTotal = data.email_stats?.total_sent || 0;
        const ticketTotal = data.ticket_stats?.total_created || 0;
        const autoReplyTotal = data.auto_reply_stats?.total_sent || 0;
        const alertsCount = data.alerts?.length || 0;

        $('.stat-card').each(function() {
            const $this = $(this);
            const $number = $this.find('.stat-number');
            const text = $this.find('h3').text();

            if (text.includes('Emails')) {
                animateNumber($number, emailTotal);
            } else if (text.includes('Tickets')) {
                animateNumber($number, ticketTotal);
            } else if (text.includes('Auto-Respuestas')) {
                animateNumber($number, autoReplyTotal);
            } else if (text.includes('Alertas')) {
                animateNumber($number, alertsCount);
                $this.toggleClass('warning', alertsCount > 10);
            }
        });
    }

    /**
     * Actualizar indicadores de estado
     */
    function updateStatusIndicators(data) {
        // Actualizar progress bars de rate limiters
        $('.rate-limit-card').each(function() {
            const $this = $(this);
            const action = $this.find('h4').text();
            const stats = data.rate_stats?.[action];
            
            if (stats) {
                const $fill = $this.find('.progress-fill');
                const $info = $this.find('.rate-info');
                
                $fill.css('width', stats.percentage + '%');
                $info.html(`<span>${stats.count}/${stats.limit}</span><span>${stats.percentage.toFixed(1)}%</span>`);
            }
        });
    }

    /**
     * Animar números
     */
    function animateNumber($element, targetValue) {
        const currentValue = parseInt($element.text().replace(/,/g, '')) || 0;
        const increment = Math.ceil((targetValue - currentValue) / 20);
        
        if (increment === 0) return;
        
        let current = currentValue;
        const timer = setInterval(() => {
            current += increment;
            if ((increment > 0 && current >= targetValue) || (increment < 0 && current <= targetValue)) {
                current = targetValue;
                clearInterval(timer);
            }
            $element.text(current.toLocaleString());
        }, 50);
    }

    /**
     * Iniciar auto-refresh
     */
    function startAutoRefresh() {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
        }

        autoRefreshInterval = setInterval(() => {
            refreshMonitoringData();
        }, 30000); // Cada 30 segundos

        isAutoRefreshActive = true;
    }

    /**
     * Detener auto-refresh
     */
    function stopAutoRefresh() {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
        }
        isAutoRefreshActive = false;
    }

    /**
     * Toggle auto-refresh
     */
    function toggleAutoRefresh() {
        if (isAutoRefreshActive) {
            stopAutoRefresh();
            showNotification('Auto-refresh desactivado', 'info');
        } else {
            startAutoRefresh();
            showNotification('Auto-refresh activado', 'success');
        }
    }

    /**
     * Refrescar datos de monitoreo
     */
    function refreshMonitoringData() {
        showRefreshIndicator();

        $.ajax({
            url: qvcMonitoring.ajaxurl,
            type: 'POST',
            data: {
                action: 'qvc_get_monitoring_data',
                nonce: qvcMonitoring.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateDashboard(response.data);
                    showNotification('Datos actualizados', 'success');
                } else {
                    showNotification('Error al actualizar datos', 'error');
                }
            },
            error: function() {
                showNotification('Error de conexión', 'error');
            },
            complete: function() {
                hideRefreshIndicator();
            }
        });
    }

    /**
     * Limpiar alertas
     */
    function clearAlerts() {
        showLoading();

        $.ajax({
            url: qvcMonitoring.ajaxurl,
            type: 'POST',
            data: {
                action: 'qvc_clear_alerts',
                nonce: qvcMonitoring.nonce
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    showNotification('Error al limpiar alertas', 'error');
                }
            },
            error: function() {
                showNotification('Error de conexión', 'error');
            },
            complete: function() {
                hideLoading();
            }
        });
    }

    /**
     * Resetear estadísticas
     */
    function resetStats() {
        showLoading();

        $.ajax({
            url: qvcMonitoring.ajaxurl,
            type: 'POST',
            data: {
                action: 'qvc_reset_stats',
                nonce: qvcMonitoring.nonce
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    showNotification('Error al resetear estadísticas', 'error');
                }
            },
            error: function() {
                showNotification('Error de conexión', 'error');
            },
            complete: function() {
                hideLoading();
            }
        });
    }

    /**
     * Probar monitoreo
     */
    function testMonitoring() {
        showNotification('Ejecutando prueba de monitoreo...', 'info');
        
        // Simular eventos de prueba
        setTimeout(() => {
            showNotification('Prueba completada - Revisa las alertas', 'success');
            refreshMonitoringData();
        }, 2000);
    }

    /**
     * Exportar datos de monitoreo
     */
    function exportMonitoringData() {
        const data = window.qvcMonitoringData || {};
        const exportData = {
            timestamp: new Date().toISOString(),
            ...data
        };

        const blob = new Blob([JSON.stringify(exportData, null, 2)], {
            type: 'application/json'
        });

        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `qvaclick-monitoring-${new Date().toISOString().split('T')[0]}.json`;
        a.click();
        URL.revokeObjectURL(url);

        showNotification('Datos exportados correctamente', 'success');
    }

    /**
     * Mostrar indicador de refresh
     */
    function showRefreshIndicator() {
        $('#auto-refresh-indicator').addClass('active');
    }

    /**
     * Ocultar indicador de refresh
     */
    function hideRefreshIndicator() {
        $('#auto-refresh-indicator').removeClass('active');
    }

    /**
     * Mostrar overlay de carga
     */
    function showLoading() {
        $('#monitoring-loading').show();
    }

    /**
     * Ocultar overlay de carga
     */
    function hideLoading() {
        $('#monitoring-loading').hide();
    }

    /**
     * Mostrar notificación
     */
    function showNotification(message, type = 'info') {
        const $notification = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
        $('.wrap h1').after($notification);
        
        // Auto-dismiss después de 3 segundos
        setTimeout(() => {
            $notification.fadeOut(() => $notification.remove());
        }, 3000);
    }

    // Cleanup al salir de la página
    $(window).on('beforeunload', function() {
        stopAutoRefresh();
        
        // Destruir gráficos
        Object.values(monitoringCharts).forEach(chart => {
            if (chart && typeof chart.destroy === 'function') {
                chart.destroy();
            }
        });
    });

})(jQuery);
