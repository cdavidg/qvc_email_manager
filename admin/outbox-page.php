<?php
/**
 * Página de Administración - Bandeja de Salida
 * Gestión de todos los emails enviados desde el sistema
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class QvaClick_Outbox_Admin_Page {
    
    /**
     * Renderizar página principal de bandeja de salida
     */
    public static function render_page() {
        $admin_email_manager = QvaClick_Admin_Email_Manager::get_instance();
        
        // Procesar acciones
        if (isset($_POST['action'])) {
            self::process_actions();
        }
        
        // Obtener parámetros de filtrado
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 25;
        $offset = ($current_page - 1) * $per_page;
        
        $filters = array(
            'limit' => $per_page,
            'offset' => $offset,
            'status' => isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '',
            'email_type' => isset($_GET['email_type']) ? sanitize_text_field($_GET['email_type']) : '',
            'search' => isset($_GET['search']) ? sanitize_text_field($_GET['search']) : ''
        );
        
        $emails = $admin_email_manager->get_outbox_emails($filters);
        $stats = $admin_email_manager->get_outbox_stats();
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">📤 Bandeja de Salida</h1>
            <a href="#" class="page-title-action" onclick="location.reload()">Actualizar</a>
            
            <!-- Estadísticas rápidas -->
            <div class="qvc-outbox-stats" style="margin: 20px 0; display: flex; gap: 15px;">
                <div class="qvc-stat-card" style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; border-radius: 4px; min-width: 120px;">
                    <div style="font-size: 24px; font-weight: bold; color: #0073aa;"><?php echo $stats['total']; ?></div>
                    <div style="color: #666;">Total Emails</div>
                </div>
                <div class="qvc-stat-card" style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; border-radius: 4px; min-width: 120px;">
                    <div style="font-size: 24px; font-weight: bold; color: #46b450;"><?php echo $stats['sent']; ?></div>
                    <div style="color: #666;">Enviados</div>
                </div>
                <div class="qvc-stat-card" style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; border-radius: 4px; min-width: 120px;">
                    <div style="font-size: 24px; font-weight: bold; color: #dc3232;"><?php echo $stats['failed']; ?></div>
                    <div style="color: #666;">Fallidos</div>
                </div>
                <div class="qvc-stat-card" style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; border-radius: 4px; min-width: 120px;">
                    <div style="font-size: 24px; font-weight: bold; color: #ffb900;"><?php echo $stats['pending']; ?></div>
                    <div style="color: #666;">Pendientes</div>
                </div>
                <div class="qvc-stat-card" style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; border-radius: 4px; min-width: 120px;">
                    <div style="font-size: 24px; font-weight: bold; color: #0073aa;"><?php echo $stats['today']; ?></div>
                    <div style="color: #666;">Hoy</div>
                </div>
            </div>
            
            <!-- Filtros -->
            <div class="qvc-outbox-filters" style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; margin-bottom: 20px;">
                <form method="GET" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <input type="hidden" name="page" value="qvaclick-email-outbox">
                    
                    <label>Estado:</label>
                    <select name="status">
                        <option value="">Todos</option>
                        <option value="sent" <?php selected($filters['status'], 'sent'); ?>>Enviados</option>
                        <option value="failed" <?php selected($filters['status'], 'failed'); ?>>Fallidos</option>
                        <option value="pending" <?php selected($filters['status'], 'pending'); ?>>Pendientes</option>
                        <option value="retry" <?php selected($filters['status'], 'retry'); ?>>Reintento</option>
                    </select>
                    
                    <label>Tipo:</label>
                    <select name="email_type">
                        <option value="">Todos</option>
                        <option value="individual" <?php selected($filters['email_type'], 'individual'); ?>>Individual</option>
                        <option value="mass_campaign" <?php selected($filters['email_type'], 'mass_campaign'); ?>>Campaña</option>
                        <option value="support_ticket" <?php selected($filters['email_type'], 'support_ticket'); ?>>Soporte</option>
                        <option value="test" <?php selected($filters['email_type'], 'test'); ?>>Prueba</option>
                    </select>
                    
                    <label>Buscar:</label>
                    <input type="text" name="search" value="<?php echo esc_attr($filters['search']); ?>" placeholder="Email o asunto...">
                    
                    <input type="submit" class="button" value="Filtrar">
                    <a href="<?php echo admin_url('admin.php?page=qvaclick-email-outbox'); ?>" class="button">Limpiar</a>
                </form>
            </div>
            
            <!-- Tabla de emails -->
            <div class="qvc-outbox-table">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Tipo</th>
                            <th scope="col">Destinatario</th>
                            <th scope="col">Asunto</th>
                            <th scope="col">Estado</th>
                            <th scope="col">Fecha Creación</th>
                            <th scope="col">Fecha Envío</th>
                            <th scope="col">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($emails)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 20px;">
                                    No se encontraron emails con los filtros aplicados.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($emails as $email): ?>
                                <?php
                                $status_class = '';
                                $status_text = '';
                                switch ($email->status) {
                                    case 'sent':
                                        $status_class = 'qvc-status-sent';
                                        $status_text = '✅ Enviado';
                                        break;
                                    case 'failed':
                                        $status_class = 'qvc-status-failed';
                                        $status_text = '❌ Fallido';
                                        break;
                                    case 'pending':
                                        $status_class = 'qvc-status-pending';
                                        $status_text = '⏳ Pendiente';
                                        break;
                                    case 'retry':
                                        $status_class = 'qvc-status-retry';
                                        $status_text = '🔄 Reintento';
                                        break;
                                }
                                
                                $type_labels = array(
                                    'individual' => '👤 Individual',
                                    'mass_campaign' => '📢 Campaña',
                                    'support_ticket' => '🎫 Soporte',
                                    'system' => '⚙️ Sistema',
                                    'test' => '🧪 Prueba'
                                );
                                ?>
                                <tr>
                                    <td><?php echo $email->id; ?></td>
                                    <td><?php echo isset($type_labels[$email->email_type]) ? $type_labels[$email->email_type] : $email->email_type; ?></td>
                                    <td>
                                        <div style="max-width: 200px; overflow: hidden; text-overflow: ellipsis;">
                                            <?php echo esc_html($email->recipient_email); ?>
                                        </div>
                                        <?php if ($email->recipient_name): ?>
                                            <div style="font-size: 12px; color: #666;">
                                                <?php echo esc_html($email->recipient_name); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="max-width: 250px; overflow: hidden; text-overflow: ellipsis;">
                                            <?php echo esc_html($email->subject); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="<?php echo $status_class; ?>" style="padding: 4px 8px; border-radius: 3px; font-size: 12px;">
                                            <?php echo $status_text; ?>
                                        </span>
                                        <?php if ($email->retry_count > 0): ?>
                                            <div style="font-size: 11px; color: #666;">
                                                Reintentos: <?php echo $email->retry_count; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($email->created_at)); ?></td>
                                    <td>
                                        <?php if ($email->sent_at): ?>
                                            <?php echo date('d/m/Y H:i', strtotime($email->sent_at)); ?>
                                        <?php else: ?>
                                            <span style="color: #666;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="button button-small" onclick="viewEmailDetails(<?php echo $email->id; ?>)">
                                            Ver
                                        </button>
                                        <?php if (in_array($email->status, ['failed', 'retry'])): ?>
                                            <button type="button" class="button button-small" onclick="retryEmail(<?php echo $email->id; ?>)">
                                                Reintentar
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Herramientas de prueba -->
            <div class="qvc-test-tools" style="margin-top: 30px; background: #fff; padding: 20px; border: 1px solid #ccd0d4;">
                <h3>🧪 Herramientas de Prueba</h3>
                <form method="POST" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <input type="hidden" name="action" value="send_test_email">
                    <?php wp_nonce_field('qvc_send_test_email'); ?>
                    
                    <label>Email de prueba:</label>
                    <input type="email" name="test_email" placeholder="tu@email.com" required>
                    
                    <input type="submit" class="button button-primary" value="Enviar Email de Prueba">
                </form>
                
                <div style="margin-top: 15px; padding: 10px; background: #f1f1f1; border-left: 4px solid #0073aa;">
                    <strong>💡 Consejos para mejorar la entrega:</strong>
                    <ul style="margin: 10px 0 0 20px;">
                        <li>Configura un plugin SMTP como "WP Mail SMTP" para mejor entrega</li>
                        <li>Verifica que tu servidor tenga configurado correctamente el mail()</li>
                        <li>Revisa que los emails no estén siendo marcados como spam</li>
                        <li>Usa emails "From" del mismo dominio del sitio web</li>
                    </ul>
                </div>
            </div>
        </div>
        
        <style>
        .qvc-status-sent { background: #46b450; color: white; }
        .qvc-status-failed { background: #dc3232; color: white; }
        .qvc-status-pending { background: #ffb900; color: white; }
        .qvc-status-retry { background: #0073aa; color: white; }
        </style>
        
        <script>
        function viewEmailDetails(emailId) {
            // Abrir modal o ventana con detalles del email
            var nonce = '<?php echo wp_create_nonce('qvc_view_email_details'); ?>';
            var url = '<?php echo admin_url("admin-ajax.php"); ?>?action=qvc_view_email_details&email_id=' + emailId + '&nonce=' + nonce;
            window.open(url, 'EmailDetails', 'width=800,height=600,scrollbars=yes');
        }
        
        function retryEmail(emailId) {
            if (confirm('¿Estás seguro de que quieres reintentar enviar este email?')) {
                var form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="retry_email">' +
                                '<input type="hidden" name="email_id" value="' + emailId + '">' +
                                '<?php echo wp_nonce_field('qvc_retry_email', '_wpnonce', true, false); ?>';
                document.body.appendChild(form);
                form.submit();
            }
        }
        </script>
        <?php
    }
    
    /**
     * Procesar acciones de la página
     */
    private static function process_actions() {
        $admin_email_manager = QvaClick_Admin_Email_Manager::get_instance();
        
        switch ($_POST['action']) {
            case 'send_test_email':
                if (wp_verify_nonce($_POST['_wpnonce'], 'qvc_send_test_email')) {
                    $test_email = sanitize_email($_POST['test_email']);
                    if ($test_email) {
                        $subject = '🧪 Email de Prueba - ' . get_bloginfo('name');
                        $content = '<h2>Email de Prueba</h2>';
                        $content .= '<p>Este es un email de prueba enviado desde el sistema QvaClick Email Manager.</p>';
                        $content .= '<p><strong>Fecha:</strong> ' . current_time('mysql') . '</p>';
                        $content .= '<p><strong>Sitio:</strong> ' . get_bloginfo('name') . '</p>';
                        $content .= '<p>Si recibes este email, significa que el sistema de envío está funcionando correctamente.</p>';
                        
                        $result = $admin_email_manager->send_individual_email($test_email, $subject, $content, 'test');
                        
                        if ($result['success']) {
                            echo '<div class="notice notice-success"><p>✅ Email de prueba enviado correctamente a ' . $test_email . '</p></div>';
                        } else {
                            echo '<div class="notice notice-error"><p>❌ Error al enviar email de prueba: ' . (isset($result['error']) ? $result['error'] : 'Error desconocido') . '</p></div>';
                        }
                    }
                }
                break;
                
            case 'retry_email':
                if (wp_verify_nonce($_POST['_wpnonce'], 'qvc_retry_email')) {
                    $email_id = intval($_POST['email_id']);
                    $result = $admin_email_manager->retry_failed_email($email_id);
                    
                    if ($result) {
                        echo '<div class="notice notice-success"><p>✅ Email reenviado correctamente</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>❌ Error al reenviar email</p></div>';
                    }
                }
                break;
        }
    }
    
    /**
     * Renderizar solo el contenido de bandeja de salida (para usar en pestañas)
     */
    public static function render_outbox_content() {
        $admin_email_manager = QvaClick_Admin_Email_Manager::get_instance();
        
        // Obtener parámetros de filtrado
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 25;
        $offset = ($current_page - 1) * $per_page;
        
        $filters = array(
            'limit' => $per_page,
            'offset' => $offset,
            'status' => isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '',
            'email_type' => isset($_GET['email_type']) ? sanitize_text_field($_GET['email_type']) : '',
            'search' => isset($_GET['search']) ? sanitize_text_field($_GET['search']) : ''
        );
        
        $emails = $admin_email_manager->get_outbox_emails($filters);
        $stats = $admin_email_manager->get_outbox_stats();
        
        ?>
        <!-- Estadísticas rápidas -->
        <div class="qvc-outbox-stats" style="margin: 20px 0; display: flex; gap: 15px;">
            <div class="qvc-stat-card" style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; border-radius: 4px; min-width: 120px;">
                <div style="font-size: 24px; font-weight: bold; color: #0073aa;"><?php echo $stats['total']; ?></div>
                <div style="color: #666;">Total Emails</div>
            </div>
            <div class="qvc-stat-card" style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; border-radius: 4px; min-width: 120px;">
                <div style="font-size: 24px; font-weight: bold; color: #46b450;"><?php echo $stats['sent']; ?></div>
                <div style="color: #666;">Enviados</div>
            </div>
            <div class="qvc-stat-card" style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; border-radius: 4px; min-width: 120px;">
                <div style="font-size: 24px; font-weight: bold; color: #dc3232;"><?php echo $stats['failed']; ?></div>
                <div style="color: #666;">Fallaron</div>
            </div>
            <div class="qvc-stat-card" style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; border-radius: 4px; min-width: 120px;">
                <div style="font-size: 24px; font-weight: bold; color: #ffb900;"><?php echo $stats['pending']; ?></div>
                <div style="color: #666;">Pendientes</div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="qvc-outbox-filters" style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; margin-bottom: 20px;">
            <form method="get" action="">
                <input type="hidden" name="page" value="qvc-admin-email">
                <input type="hidden" name="action" value="outbox">
                
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <select name="status">
                        <option value="">Todos los estados</option>
                        <option value="sent" <?php selected($_GET['status'] ?? '', 'sent'); ?>>Enviados</option>
                        <option value="failed" <?php selected($_GET['status'] ?? '', 'failed'); ?>>Fallaron</option>
                        <option value="pending" <?php selected($_GET['status'] ?? '', 'pending'); ?>>Pendientes</option>
                    </select>
                    
                    <select name="email_type">
                        <option value="">Todos los tipos</option>
                        <option value="individual" <?php selected($_GET['email_type'] ?? '', 'individual'); ?>>Individual</option>
                        <option value="mass" <?php selected($_GET['email_type'] ?? '', 'mass'); ?>>Masivo</option>
                        <option value="test" <?php selected($_GET['email_type'] ?? '', 'test'); ?>>Prueba</option>
                    </select>
                    
                    <input type="text" name="search" placeholder="Buscar por email o asunto..." 
                           value="<?php echo esc_attr($_GET['search'] ?? ''); ?>" style="min-width: 200px;">
                    
                    <input type="submit" class="button" value="Filtrar">
                    <a href="<?php echo admin_url('admin.php?page=qvc-admin-email&action=outbox'); ?>" class="button">Limpiar</a>
                </div>
            </form>
        </div>
        
        <!-- Lista de emails -->
        <div class="qvc-outbox-list">
            <?php if (empty($emails)): ?>
                <div class="notice notice-info">
                    <p>No se encontraron emails con los filtros aplicados.</p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 40px;">ID</th>
                            <th>Destinatario</th>
                            <th>Asunto</th>
                            <th style="width: 100px;">Tipo</th>
                            <th style="width: 100px;">Estado</th>
                            <th style="width: 150px;">Fecha</th>
                            <th style="width: 120px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($emails as $email): ?>
                            <tr>
                                <td><?php echo $email->id; ?></td>
                                <td>
                                    <strong><?php echo esc_html($email->recipient_email); ?></strong>
                                    <?php if (!empty($email->recipient_name)): ?>
                                        <br><small><?php echo esc_html($email->recipient_name); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($email->subject); ?></strong>
                                    <?php if ($email->email_type === 'mass_campaign' && !empty($email->reference_id)): ?>
                                        <br><small>Campaña ID: <?php echo $email->reference_id; ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="qvc-email-type qvc-type-<?php echo esc_attr($email->email_type); ?>">
                                        <?php echo ucfirst($email->email_type); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    $status_class = 'qvc-status-' . $email->status;
                                    $status_text = $email->status;
                                    if ($email->status === 'sent') {
                                        $status_text = '✅ Enviado';
                                    } elseif ($email->status === 'failed') {
                                        $status_text = '❌ Falló';
                                    } elseif ($email->status === 'pending') {
                                        $status_text = '⏳ Pendiente';
                                    }
                                    ?>
                                    <span class="<?php echo esc_attr($status_class); ?>"><?php echo $status_text; ?></span>
                                </td>
                                <td>
                                    <?php echo date('d/m/Y H:i', strtotime($email->created_at)); ?>
                                </td>
                                <td>
                                    <button type="button" class="button button-small qvc-view-email" 
                                            data-id="<?php echo $email->id; ?>">Ver</button>
                                    <?php if ($email->status === 'failed'): ?>
                                        <button type="button" class="button button-small qvc-retry-email" 
                                                data-id="<?php echo $email->id; ?>">Reintentar</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
?>
