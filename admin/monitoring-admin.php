<?php
/**
 * QvaClick Monitoring Admin Page
 * Página de administración del sistema de monitoreo
 * 
 * @package QvaClick_Email_Manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class QvaClick_Monitoring_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_qvc_get_monitoring_data', array($this, 'ajax_get_monitoring_data'));
        add_action('wp_ajax_qvc_clear_alerts', array($this, 'ajax_clear_alerts'));
        add_action('wp_ajax_qvc_reset_stats', array($this, 'ajax_reset_stats'));
    }
    
    /**
     * Añadir menú de administración
     */
    public function add_admin_menu() {
        add_submenu_page(
            'qvc-smtp-config',
            'Monitoreo QvaClick',
            'Monitoreo',
            'manage_options',
            'qvc-monitoring',
            array($this, 'render_monitoring_page')
        );
    }
    
    /**
     * Cargar scripts y estilos
     */
    public function enqueue_scripts($hook) {
        if ($hook !== 'qvaclick_page_qvc-monitoring') {
            return;
        }
        
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '3.9.1', true);
        wp_enqueue_script('qvc-monitoring', plugin_dir_url(dirname(__FILE__)) . 'admin/js/monitoring.js', array('jquery', 'chart-js'), '1.0.0', true);
        
        wp_localize_script('qvc-monitoring', 'qvcMonitoring', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('qvc_monitoring_nonce')
        ));
        
        wp_enqueue_style('qvc-monitoring', plugin_dir_url(dirname(__FILE__)) . 'admin/css/monitoring.css', array(), '1.0.0');
    }
    
    /**
     * Renderizar página de monitoreo
     */
    public function render_monitoring_page() {
        $monitoring = QvaClick_Monitoring_System::get_instance();
        $dashboard = $monitoring->get_monitoring_dashboard();
        
        ?>
        <div class="wrap">
            <h1>🔍 Monitoreo QvaClick</h1>
            
            <!-- Estado del Sistema -->
            <div class="qvc-monitoring-overview">
                <div class="status-card <?php echo $dashboard['emergency_mode'] ? 'emergency' : 'normal'; ?>">
                    <h2>Estado del Sistema</h2>
                    <?php if ($dashboard['emergency_mode']): ?>
                        <div class="status emergency">
                            <span class="dashicons dashicons-warning"></span>
                            <strong>MODO EMERGENCIA ACTIVO</strong>
                        </div>
                        <p>El sistema de email está completamente deshabilitado para prevenir spam.</p>
                        <a href="<?php echo admin_url('admin.php?page=qvc-smtp-config'); ?>" class="button button-primary">
                            Gestionar Emergencia
                        </a>
                    <?php else: ?>
                        <div class="status normal">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <strong>Sistema Operativo</strong>
                        </div>
                        <p>Todos los sistemas funcionando normalmente.</p>
                    <?php endif; ?>
                </div>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <h3>Emails Enviados</h3>
                        <div class="stat-number"><?php echo number_format($dashboard['email_stats']['total_sent'] ?? 0); ?></div>
                        <div class="stat-label">Total acumulado</div>
                    </div>
                    
                    <div class="stat-card">
                        <h3>Tickets Creados</h3>
                        <div class="stat-number"><?php echo number_format($dashboard['ticket_stats']['total_created'] ?? 0); ?></div>
                        <div class="stat-label">Total acumulado</div>
                    </div>
                    
                    <div class="stat-card">
                        <h3>Auto-Respuestas</h3>
                        <div class="stat-number"><?php echo number_format($dashboard['auto_reply_stats']['total_sent'] ?? 0); ?></div>
                        <div class="stat-label">Total enviadas</div>
                    </div>
                    
                    <div class="stat-card <?php echo count($dashboard['alerts']) > 10 ? 'warning' : ''; ?>">
                        <h3>Alertas Activas</h3>
                        <div class="stat-number"><?php echo count($dashboard['alerts']); ?></div>
                        <div class="stat-label">Últimas 24 horas</div>
                    </div>
                </div>
            </div>
            
            <!-- Gráficos -->
            <div class="qvc-monitoring-charts">
                <div class="chart-container">
                    <h3>Actividad por Horas</h3>
                    <canvas id="hourlyActivityChart" width="400" height="200"></canvas>
                </div>
                
                <div class="chart-container">
                    <h3>Estado de Circuit Breakers</h3>
                    <canvas id="circuitBreakerChart" width="400" height="200"></canvas>
                </div>
            </div>
            
            <!-- Alertas Recientes -->
            <div class="qvc-monitoring-alerts">
                <div class="section-header">
                    <h2>🚨 Alertas Recientes</h2>
                    <button id="clear-alerts" class="button">Limpiar Alertas</button>
                </div>
                
                <div class="alerts-container">
                    <?php if (empty($dashboard['alerts'])): ?>
                        <div class="no-alerts">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <p>No hay alertas activas. El sistema está funcionando correctamente.</p>
                        </div>
                    <?php else: ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Tiempo</th>
                                    <th>Tipo</th>
                                    <th>Severidad</th>
                                    <th>Detalles</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $recent_alerts = array_slice(array_reverse($dashboard['alerts']), 0, 20);
                                foreach ($recent_alerts as $alert): 
                                ?>
                                <tr class="alert-row <?php echo $alert['severity']; ?>">
                                    <td><?php echo date('H:i:s', strtotime($alert['timestamp'])); ?></td>
                                    <td><?php echo $this->format_alert_type($alert['type']); ?></td>
                                    <td>
                                        <span class="severity-badge <?php echo $alert['severity']; ?>">
                                            <?php echo $alert['severity'] === 'critical' ? '🚨 Crítico' : '⚠️ Advertencia'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $this->format_alert_details($alert); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Circuit Breakers -->
            <div class="qvc-monitoring-circuit-breakers">
                <h2>⚡ Circuit Breakers</h2>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Función</th>
                            <th>Estado</th>
                            <th>Fallos</th>
                            <th>Último Error</th>
                            <th>Próximo Intento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dashboard['circuit_status']['failure_counts'] as $function => $failures): ?>
                        <tr>
                            <td><code><?php echo esc_html($function); ?></code></td>
                            <td>
                                <?php if ($failures >= 5): ?>
                                    <span class="status-badge open">🔴 Abierto</span>
                                <?php elseif ($failures > 0): ?>
                                    <span class="status-badge half-open">🟡 Parcial</span>
                                <?php else: ?>
                                    <span class="status-badge closed">🟢 Cerrado</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $failures; ?>/5</td>
                            <td>
                                <?php 
                                $last_failure = $dashboard['circuit_status']['last_failures'][$function] ?? null;
                                echo $last_failure ? date('H:i:s', $last_failure) : 'N/A';
                                ?>
                            </td>
                            <td>
                                <?php 
                                if ($failures >= 5) {
                                    $next_attempt = ($dashboard['circuit_status']['last_failures'][$function] ?? 0) + 300; // 5 minutos
                                    echo date('H:i:s', $next_attempt);
                                } else {
                                    echo 'Inmediato';
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Rate Limiters -->
            <div class="qvc-monitoring-rate-limiters">
                <h2>⏱️ Rate Limiters</h2>
                
                <div class="rate-limit-grid">
                    <?php foreach ($dashboard['rate_stats'] as $action => $stats): ?>
                    <div class="rate-limit-card">
                        <h4><?php echo esc_html($action); ?></h4>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $stats['percentage']; ?>%"></div>
                        </div>
                        <div class="rate-info">
                            <span><?php echo $stats['count']; ?>/<?php echo $stats['limit']; ?></span>
                            <span><?php echo round($stats['percentage'], 1); ?>%</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Loop Detector -->
            <div class="qvc-monitoring-loop-detector">
                <h2>🔄 Detector de Loops</h2>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Función</th>
                            <th>Llamadas</th>
                            <th>Nivel de Riesgo</th>
                            <th>Ventana de Tiempo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dashboard['loop_stats'] as $function => $stats): ?>
                        <tr>
                            <td><code><?php echo esc_html($function); ?></code></td>
                            <td><?php echo $stats['calls_in_window']; ?></td>
                            <td>
                                <span class="risk-badge <?php echo strtolower($stats['risk_level']); ?>">
                                    <?php 
                                    switch($stats['risk_level']) {
                                        case 'HIGH': echo '🔴 Alto'; break;
                                        case 'MEDIUM': echo '🟡 Medio'; break;
                                        default: echo '🟢 Bajo'; break;
                                    }
                                    ?>
                                </span>
                            </td>
                            <td><?php echo $stats['window_seconds']; ?>s</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Acciones de Administración -->
            <div class="qvc-monitoring-actions">
                <h2>🛠️ Acciones de Administración</h2>
                
                <div class="action-buttons">
                    <button id="reset-stats" class="button button-secondary">
                        🔄 Resetear Estadísticas
                    </button>
                    
                    <button id="test-monitoring" class="button button-secondary">
                        🧪 Probar Monitoreo
                    </button>
                    
                    <button id="export-data" class="button button-secondary">
                        📊 Exportar Datos
                    </button>
                    
                    <a href="<?php echo admin_url('admin.php?page=qvc-smtp-config'); ?>" class="button button-primary">
                        ⚙️ Configuración SMTP
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Loading Overlay -->
        <div id="monitoring-loading" class="loading-overlay" style="display: none;">
            <div class="loading-spinner">
                <div class="spinner"></div>
                <p>Actualizando datos de monitoreo...</p>
            </div>
        </div>
        
        <script type="text/javascript">
            // Datos para los gráficos
            window.qvcMonitoringData = <?php echo json_encode($dashboard); ?>;
        </script>
        <?php
    }
    
    /**
     * Formatear tipo de alerta
     */
    private function format_alert_type($type) {
        $types = array(
            'high_email_volume' => 'Alto Volumen de Email',
            'high_ticket_volume' => 'Alto Volumen de Tickets',
            'spam_user' => 'Usuario Spam',
            'potential_reply_loop' => 'Posible Loop de Respuestas',
            'high_auto_reply_volume' => 'Alto Volumen de Auto-Respuestas',
            'circuit_breaker' => 'Circuit Breaker',
            'rate_limit' => 'Rate Limit',
            'loop_detection' => 'Detección de Loop'
        );
        
        return $types[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }
    
    /**
     * Formatear detalles de alerta
     */
    private function format_alert_details($alert) {
        $data = $alert['data'];
        
        switch ($alert['type']) {
            case 'high_email_volume':
                return "Enviados {$data['emails_this_hour']} emails esta hora a {$data['to']}";
                
            case 'high_ticket_volume':
                return "Creados {$data['tickets_this_hour']} tickets esta hora";
                
            case 'spam_user':
                return "Usuario {$data['user_email']} ha creado {$data['ticket_count']} tickets";
                
            case 'potential_reply_loop':
                return "Ticket #{$data['ticket_id']} ha generado {$data['reply_count']} auto-respuestas";
                
            case 'circuit_breaker':
                return $data['message'] ?? 'Circuit breaker activado';
                
            default:
                return json_encode($data);
        }
    }
    
    /**
     * AJAX: Obtener datos de monitoreo
     */
    public function ajax_get_monitoring_data() {
        check_ajax_referer('qvc_monitoring_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes');
        }
        
        $monitoring = QvaClick_Monitoring_System::get_instance();
        $dashboard = $monitoring->get_monitoring_dashboard();
        
        wp_send_json_success($dashboard);
    }
    
    /**
     * AJAX: Limpiar alertas
     */
    public function ajax_clear_alerts() {
        check_ajax_referer('qvc_monitoring_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes');
        }
        
        delete_option('qvc_monitoring_alerts');
        
        wp_send_json_success('Alertas limpiadas correctamente');
    }
    
    /**
     * AJAX: Resetear estadísticas
     */
    public function ajax_reset_stats() {
        check_ajax_referer('qvc_monitoring_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes');
        }
        
        delete_option('qvc_email_stats');
        delete_option('qvc_ticket_stats');
        delete_option('qvc_auto_reply_stats');
        delete_option('qvc_monitoring_alerts');
        
        wp_send_json_success('Estadísticas reseteadas correctamente');
    }
}

// Inicializar solo en admin
if (is_admin()) {
    new QvaClick_Monitoring_Admin();
}
