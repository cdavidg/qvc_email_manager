<?php
/**
 * SMTP/IMAP Configuration Page
 * 
 * @package QvaClick_Email_Manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class QvaClick_SMTP_Config_Page {
    
    /**
     * Render the SMTP/IMAP configuration page
     */
    public static function render_page() {
        // Mostrar mensaje de éxito si viene de redirect
        if (isset($_GET['config_saved']) && $_GET['config_saved'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 __('✅ Configuración guardada exitosamente. La nueva configuración SMTP se aplicará inmediatamente a todos los emails de WordPress.', 'qvaclick-email-manager') . 
                 '</p></div>';
        }
        
        if (isset($_GET['test_sent']) && $_GET['test_sent'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 sprintf(__('✅ Email de prueba enviado exitosamente a: %s', 'qvaclick-email-manager'), esc_html($_GET['test_email'])) . 
                 '</p></div>';
        }
        
        if (isset($_GET['imap_success']) && $_GET['imap_success'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 sprintf(__('✅ Conexión IMAP exitosa. Buzón: %d mensajes (%d no leídos)', 'qvaclick-email-manager'), 
                         intval($_GET['message_count']), intval($_GET['unread_count'])) . 
                 '</p></div>';
        }
        
        if (isset($_GET['test_error'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . 
                 sprintf(__('❌ Error en la prueba: %s', 'qvaclick-email-manager'), esc_html($_GET['test_error'])) . 
                 '</p></div>';
        }
        
        // Handle form submission
        if (isset($_POST['submit_smtp_config'])) {
            self::save_smtp_config();
        }
        
        if (isset($_POST['test_smtp'])) {
            self::test_smtp_connection();
        }
        
        if (isset($_POST['test_imap'])) {
            self::test_imap_connection();
        }
        
        // Handle emergency stop
        if (isset($_POST['emergency_stop'])) {
            self::handle_emergency_stop();
        }
        
        // Get current configuration
        $smtp_config = get_option('qvc_smtp_config', self::get_default_config());
        $imap_config = get_option('qvc_imap_config', self::get_default_imap_config());
        
        ?>
        <div class="wrap">
            <h1><?php _e('Configuración SMTP/IMAP', 'qvaclick-email-manager'); ?></h1>
            
            <div class="notice notice-info">
                <p><?php _e('Configura tu servidor SMTP para envío de correos e IMAP para recepción. Esto reemplazará la configuración de WP Mail SMTP.', 'qvaclick-email-manager'); ?></p>
            </div>
            
            <div class="notice notice-warning">
                <p><strong>⚡ IMPORTANTE:</strong> <?php _e('Cuando habilites SMTP, TODOS los emails de WordPress (plugins, temas, notificaciones) usarán automáticamente tu servidor configurado.', 'qvaclick-email-manager'); ?></p>
                <p><?php _e('Esto incluye: WooCommerce, Contact Form 7, notificaciones de WordPress, emails de usuarios, etc.', 'qvaclick-email-manager'); ?></p>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('qvc_smtp_config_nonce', 'qvc_smtp_nonce'); ?>
                
                <div class="qvc-config-tabs">
                    <nav class="nav-tab-wrapper">
                        <a href="#smtp-config" class="nav-tab nav-tab-active"><?php _e('Configuración SMTP', 'qvaclick-email-manager'); ?></a>
                        <a href="#support-config" class="nav-tab"><?php _e('Email de Soporte', 'qvaclick-email-manager'); ?></a>
                        <a href="#imap-config" class="nav-tab"><?php _e('Configuración IMAP', 'qvaclick-email-manager'); ?></a>
                        <a href="#test-config" class="nav-tab"><?php _e('Pruebas', 'qvaclick-email-manager'); ?></a>
                        <a href="#emergency-config" class="nav-tab" style="background: #ff4444; color: white;"><?php _e('🚨 EMERGENCIA', 'qvaclick-email-manager'); ?></a>
                    </nav>
                    
                    <!-- SMTP Configuration Tab -->
                    <div id="smtp-config" class="tab-content active">
                        <h2><?php _e('Configuración del Servidor SMTP', 'qvaclick-email-manager'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="smtp_enabled"><?php _e('Habilitar SMTP', 'qvaclick-email-manager'); ?></label>
                                </th>
                                <td>
                                    <input type="checkbox" id="smtp_enabled" name="smtp_enabled" value="1" <?php checked($smtp_config['enabled'], 1); ?>>
                                    <p class="description"><?php _e('Activar el envío de correos por SMTP personalizado', 'qvaclick-email-manager'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="smtp_host"><?php _e('Servidor SMTP', 'qvaclick-email-manager'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="smtp_host" name="smtp_host" value="<?php echo esc_attr($smtp_config['smtp_host']); ?>" class="regular-text" required>
                                    <p class="description"><?php _e('Ej: mail.tudominio.com', 'qvaclick-email-manager'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="smtp_port"><?php _e('Puerto SMTP', 'qvaclick-email-manager'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="smtp_port" name="smtp_port" value="<?php echo esc_attr($smtp_config['smtp_port']); ?>" class="small-text" min="1" max="65535" required>
                                    <p class="description"><?php _e('Puerto común: 587 (TLS), 465 (SSL), 25 (sin encriptación)', 'qvaclick-email-manager'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="smtp_encryption"><?php _e('Encriptación', 'qvaclick-email-manager'); ?></label>
                                </th>
                                <td>
                                    <select id="smtp_encryption" name="smtp_encryption">
                                        <option value="" <?php selected($smtp_config['smtp_encryption'], ''); ?>><?php _e('Sin encriptación', 'qvaclick-email-manager'); ?></option>
                                        <option value="tls" <?php selected($smtp_config['smtp_encryption'], 'tls'); ?>><?php _e('TLS', 'qvaclick-email-manager'); ?></option>
                                        <option value="ssl" <?php selected($smtp_config['smtp_encryption'], 'ssl'); ?>><?php _e('SSL', 'qvaclick-email-manager'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="smtp_username"><?php _e('Usuario SMTP', 'qvaclick-email-manager'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="smtp_username" name="smtp_username" value="<?php echo esc_attr($smtp_config['smtp_username']); ?>" class="regular-text" required>
                                    <p class="description"><?php _e('Normalmente tu dirección de email completa', 'qvaclick-email-manager'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="smtp_password"><?php _e('Contraseña SMTP', 'qvaclick-email-manager'); ?></label>
                                </th>
                                <td>
                                    <input type="password" id="smtp_password" name="smtp_password" value="<?php echo esc_attr($smtp_config['smtp_password']); ?>" class="regular-text" required>
                                    <p class="description"><?php _e('Contraseña de tu cuenta de email', 'qvaclick-email-manager'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="from_email"><?php _e('Email "From"', 'qvaclick-email-manager'); ?></label>
                                </th>
                                <td>
                                    <input type="email" id="from_email" name="from_email" value="<?php echo esc_attr($smtp_config['from_email']); ?>" class="regular-text">
                                    <p class="description"><?php _e('Email que aparecerá como remitente. Deja vacío para usar el email de WordPress', 'qvaclick-email-manager'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="from_name"><?php _e('Nombre "From"', 'qvaclick-email-manager'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="from_name" name="from_name" value="<?php echo esc_attr($smtp_config['from_name']); ?>" class="regular-text">
                                    <p class="description"><?php _e('Nombre que aparecerá como remitente. Deja vacío para usar el nombre del sitio', 'qvaclick-email-manager'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="debug_mode"><?php _e('Modo Debug', 'qvaclick-email-manager'); ?></label>
                                </th>
                                <td>
                                    <input type="checkbox" id="debug_mode" name="debug_mode" value="1" <?php checked($smtp_config['debug_mode'], 1); ?>>
                                    <p class="description"><?php _e('Activar logs detallados para debug (solo para desarrollo)', 'qvaclick-email-manager'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- Support Email Configuration Tab -->
                    <div id="support-config" class="tab-content" style="display: none;">
                        <h2><?php _e('Configuración de Email de Soporte', 'qvaclick-email-manager'); ?></h2>
                        <p class="description" style="margin-bottom: 20px;">
                            <?php _e('Configura las direcciones de correo para el sistema de soporte. El email "From" se usa para envío, mientras que "Reply-To" se usa para respuestas.', 'qvaclick-email-manager'); ?>
                        </p>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="support_from_email"><?php _e('Email "From" (Envío)', 'qvaclick-email-manager'); ?></label>
                                </th>
                                <td>
                                    <input type="email" id="support_from_email" name="support_from_email" value="<?php echo esc_attr(get_option('qvc_support_email', 'no-reply@qvaclick.com')); ?>" class="regular-text">
                                    <p class="description"><?php _e('Email usado para enviar notificaciones (ej: no-reply@qvaclick.com)', 'qvaclick-email-manager'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="support_reply_to_email"><?php _e('Email "Reply-To" (Respuesta)', 'qvaclick-email-manager'); ?></label>
                                </th>
                                <td>
                                    <input type="email" id="support_reply_to_email" name="support_reply_to_email" value="<?php echo esc_attr(get_option('qvc_support_reply_to_email', 'support@qvaclick.com')); ?>" class="regular-text">
                                    <p class="description"><?php _e('Email al que los usuarios responderán (ej: support@qvaclick.com)', 'qvaclick-email-manager'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="support_from_name"><?php _e('Nombre del Remitente', 'qvaclick-email-manager'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="support_from_name" name="support_from_name" value="<?php echo esc_attr(get_option('qvc_support_from_name', 'Soporte QvaClick')); ?>" class="regular-text">
                                    <p class="description"><?php _e('Nombre que aparecerá como remitente en los emails', 'qvaclick-email-manager'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- IMAP Configuration Tab -->
                    <div id="imap-config" class="tab-content" style="display: none;">
                        <h2><?php _e('Configuración del Servidor IMAP', 'qvaclick-email-manager'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="imap_enabled"><?php _e('Habilitar IMAP', 'qvaclick-email-manager'); ?></label>
                                </th>
                                <td>
                                    <input type="checkbox" id="imap_enabled" name="imap_enabled" value="1" <?php checked($imap_config['enabled'], 1); ?>>
                                    <p class="description"><?php _e('Activar la lectura de correos entrantes vía IMAP', 'qvaclick-email-manager'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="imap_host"><?php _e('Servidor IMAP', 'qvaclick-email-manager'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="imap_host" name="imap_host" value="<?php echo esc_attr($imap_config['imap_host']); ?>" class="regular-text" required>
                                    <p class="description"><?php _e('Ej: mail.tudominio.com', 'qvaclick-email-manager'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="imap_port"><?php _e('Puerto IMAP', 'qvaclick-email-manager'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="imap_port" name="imap_port" value="<?php echo esc_attr($imap_config['imap_port']); ?>" class="small-text" min="1" max="65535" required>
                                    <p class="description"><?php _e('Puerto común: 993 (SSL), 143 (TLS/sin encriptación)', 'qvaclick-email-manager'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="imap_encryption"><?php _e('Encriptación IMAP', 'qvaclick-email-manager'); ?></label>
                                </th>
                                <td>
                                    <select id="imap_encryption" name="imap_encryption">
                                        <option value="" <?php selected($imap_config['imap_encryption'], ''); ?>><?php _e('Sin encriptación', 'qvaclick-email-manager'); ?></option>
                                        <option value="tls" <?php selected($imap_config['imap_encryption'], 'tls'); ?>><?php _e('TLS', 'qvaclick-email-manager'); ?></option>
                                        <option value="ssl" <?php selected($imap_config['imap_encryption'], 'ssl'); ?>><?php _e('SSL', 'qvaclick-email-manager'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="imap_username"><?php _e('Usuario IMAP', 'qvaclick-email-manager'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="imap_username" name="imap_username" value="<?php echo esc_attr($imap_config['imap_username']); ?>" class="regular-text" required>
                                    <p class="description"><?php _e('Normalmente tu dirección de email completa', 'qvaclick-email-manager'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="imap_password"><?php _e('Contraseña IMAP', 'qvaclick-email-manager'); ?></label>
                                </th>
                                <td>
                                    <input type="password" id="imap_password" name="imap_password" value="<?php echo esc_attr($imap_config['imap_password']); ?>" class="regular-text" required>
                                    <p class="description"><?php _e('Contraseña de tu cuenta de email', 'qvaclick-email-manager'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="imap_folder"><?php _e('Carpeta IMAP', 'qvaclick-email-manager'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="imap_folder" name="imap_folder" value="<?php echo esc_attr($imap_config['imap_folder']); ?>" class="regular-text">
                                    <p class="description"><?php _e('Carpeta a monitorear (por defecto: INBOX)', 'qvaclick-email-manager'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="check_interval"><?php _e('Intervalo de Verificación', 'qvaclick-email-manager'); ?></label>
                                </th>
                                <td>
                                    <select id="check_interval" name="check_interval">
                                        <option value="60" <?php selected($imap_config['check_interval'], 60); ?>><?php _e('Cada minuto', 'qvaclick-email-manager'); ?></option>
                                        <option value="300" <?php selected($imap_config['check_interval'], 300); ?>><?php _e('Cada 5 minutos', 'qvaclick-email-manager'); ?></option>
                                        <option value="900" <?php selected($imap_config['check_interval'], 900); ?>><?php _e('Cada 15 minutos', 'qvaclick-email-manager'); ?></option>
                                        <option value="1800" <?php selected($imap_config['check_interval'], 1800); ?>><?php _e('Cada 30 minutos', 'qvaclick-email-manager'); ?></option>
                                    </select>
                                    <p class="description"><?php _e('Frecuencia con la que se verificarán nuevos emails', 'qvaclick-email-manager'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- Test Configuration Tab -->
                    <div id="test-config" class="tab-content" style="display: none;">
                        <h2><?php _e('Pruebas de Conexión', 'qvaclick-email-manager'); ?></h2>
                        
                        <div class="qvc-test-section">
                            <h3><?php _e('Prueba SMTP', 'qvaclick-email-manager'); ?></h3>
                            <p><?php _e('Envía un email de prueba usando la configuración SMTP actual:', 'qvaclick-email-manager'); ?></p>
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="test_email"><?php _e('Email de Prueba', 'qvaclick-email-manager'); ?></label>
                                    </th>
                                    <td>
                                        <input type="email" id="test_email" name="test_email" value="<?php echo esc_attr(get_option('admin_email')); ?>" class="regular-text" required>
                                        <input type="submit" name="test_smtp" class="button" value="<?php _e('Enviar Email de Prueba', 'qvaclick-email-manager'); ?>">
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <hr>
                        
                        <div class="qvc-test-section">
                            <h3><?php _e('Prueba IMAP', 'qvaclick-email-manager'); ?></h3>
                            <p><?php _e('Verifica la conexión IMAP y muestra información del buzón:', 'qvaclick-email-manager'); ?></p>
                            
                            <p>
                                <input type="submit" name="test_imap" class="button" value="<?php _e('Probar Conexión IMAP', 'qvaclick-email-manager'); ?>">
                            </p>
                        </div>
                    </div>
                    
                    <!-- Emergency Tab -->
                    <div id="emergency-config" class="tab-content" style="display: none;">
                        <div class="qvc-emergency-section">
                            <h2 style="color: #ff0000;">🚨 PANEL DE EMERGENCIA</h2>
                            
                            <div class="notice notice-error">
                                <p><strong>⚠️ ADVERTENCIA:</strong> Estas acciones detienen inmediatamente todas las funciones de email. 
                                Úsalas solo en caso de emergencia como loops de spam o problemas críticos.</p>
                            </div>
                            
                            <div class="qvc-emergency-actions">
                                <h3>🛑 Acciones de Emergencia Disponibles</h3>
                                
                                <div class="emergency-action-card">
                                    <h4>🚨 DETENER TODO EL SISTEMA DE EMAIL</h4>
                                    <p>Deshabilita inmediatamente:</p>
                                    <ul>
                                        <li>✋ Todos los envíos de email (wp_mail)</li>
                                        <li>⏰ Todos los cron jobs relacionados con email</li>
                                        <li>🔌 Conexiones SMTP/IMAP</li>
                                        <li>📧 Creación de tickets automáticos</li>
                                        <li>🔄 Procesamiento de respuestas automáticas</li>
                                    </ul>
                                    
                                    <div class="emergency-button-container">
                                        <input type="submit" name="emergency_stop" class="button emergency-stop-button" 
                                               value="🚨 DETENER SISTEMA DE EMAIL AHORA" 
                                               onclick="return confirm('⚠️ CONFIRMACIÓN DE EMERGENCIA\\n\\n¿Estás seguro de que quieres DETENER COMPLETAMENTE el sistema de email?\\n\\nEsto detendrá:\\n- Todos los emails\\n- Todos los cron jobs\\n- Conexiones SMTP/IMAP\\n- Sistema de tickets\\n\\n¿CONTINUAR?');">
                                        
                                        <input type="submit" name="emergency_restore" class="button emergency-restore-button" 
                                               value="🔄 RESTAURAR SISTEMA" 
                                               onclick="return confirm('¿Estás seguro de que quieres RESTAURAR el sistema de email?\\n\\nEsto reactivará todas las funciones.');"
                                               style="margin-left: 10px;">
                                    </div>
                                </div>
                                
                                <div class="emergency-status-card">
                                    <h4>📊 Estado del Sistema</h4>
                                    <?php 
                                    $emergency_status = get_option('qvc_emergency_mode', false);
                                    $mu_plugin_exists = file_exists(WPMU_PLUGIN_DIR . '/qvc-emergency-stop.php');
                                    ?>
                                    
                                    <div class="status-indicator">
                                        <p><strong>Modo Emergencia:</strong> 
                                        <span class="status-<?php echo $emergency_status ? 'active' : 'inactive'; ?>">
                                            <?php echo $emergency_status ? '🔴 ACTIVO' : '🟢 INACTIVO'; ?>
                                        </span></p>
                                        
                                        <p><strong>MU-Plugin de Bloqueo:</strong> 
                                        <span class="status-<?php echo $mu_plugin_exists ? 'active' : 'inactive'; ?>">
                                            <?php echo $mu_plugin_exists ? '🔴 INSTALADO' : '🟢 NO INSTALADO'; ?>
                                        </span></p>
                                        
                                        <p><strong>SMTP Habilitado:</strong> 
                                        <span class="status-<?php echo $smtp_config['enabled'] ? 'active' : 'inactive'; ?>">
                                            <?php echo $smtp_config['enabled'] ? '🟢 SÍ' : '🔴 NO'; ?>
                                        </span></p>
                                        
                                        <p><strong>IMAP Habilitado:</strong> 
                                        <span class="status-<?php echo $imap_config['enabled'] ? 'active' : 'inactive'; ?>">
                                            <?php echo $imap_config['enabled'] ? '🟢 SÍ' : '🔴 NO'; ?>
                                        </span></p>
                                    </div>
                                </div>
                                
                                <div class="emergency-log-card">
                                    <h4>📋 Acciones de Emergencia Recientes</h4>
                                    <?php 
                                    $emergency_log = get_option('qvc_emergency_log', array());
                                    if (!empty($emergency_log)) {
                                        echo '<ul>';
                                        foreach (array_slice($emergency_log, -5) as $log_entry) {
                                            echo '<li><strong>' . $log_entry['timestamp'] . ':</strong> ' . $log_entry['action'] . '</li>';
                                        }
                                        echo '</ul>';
                                    } else {
                                        echo '<p>No hay acciones de emergencia registradas.</p>';
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <p class="submit">
                    <input type="submit" name="submit_smtp_config" class="button-primary" value="<?php _e('Guardar Configuración', 'qvaclick-email-manager'); ?>">
                </p>
            </form>
        </div>
        
        <style>
        .qvc-config-tabs .nav-tab-wrapper {
            margin-bottom: 0;
        }
        .qvc-config-tabs .tab-content {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-top: none;
            padding: 20px;
        }
        .qvc-config-tabs .tab-content.active {
            display: block !important;
        }
        .qvc-test-section {
            background: #f9f9f9;
            padding: 15px;
            margin: 15px 0;
            border-left: 4px solid #00a0d2;
        }
        
        /* Emergency Section Styles */
        .qvc-emergency-section {
            background: #fff;
            padding: 20px;
        }
        .emergency-action-card, .emergency-status-card, .emergency-log-card {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        .emergency-action-card {
            border-left: 5px solid #ff4444;
        }
        .emergency-status-card {
            border-left: 5px solid #00a0d2;
        }
        .emergency-log-card {
            border-left: 5px solid #ffba00;
        }
        .emergency-button-container {
            margin: 20px 0;
            padding: 15px;
            background: #fff;
            border: 2px dashed #ff4444;
            border-radius: 8px;
            text-align: center;
        }
        .emergency-stop-button {
            background: #ff4444 !important;
            color: white !important;
            border: none !important;
            padding: 12px 24px !important;
            font-size: 16px !important;
            font-weight: bold !important;
            border-radius: 6px !important;
            cursor: pointer !important;
            box-shadow: 0 2px 4px rgba(255, 68, 68, 0.3) !important;
        }
        .emergency-stop-button:hover {
            background: #ff0000 !important;
            box-shadow: 0 4px 8px rgba(255, 68, 68, 0.5) !important;
        }
        .emergency-restore-button {
            background: #00a0d2 !important;
            color: white !important;
            border: none !important;
            padding: 10px 20px !important;
            font-size: 14px !important;
            border-radius: 6px !important;
            cursor: pointer !important;
        }
        .emergency-restore-button:hover {
            background: #0073aa !important;
        }
        .status-indicator p {
            margin: 8px 0;
            font-size: 14px;
        }
        .status-active {
            color: #ff4444;
            font-weight: bold;
        }
        .status-inactive {
            color: #00aa00;
            font-weight: bold;
        }
        .emergency-action-card ul {
            margin: 10px 0;
            padding-left: 20px;
        }
        .emergency-action-card ul li {
            margin: 5px 0;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Función para cambiar de pestaña
            function switchToTab(tabId) {
                // Remove active class from all tabs and content
                $('.nav-tab').removeClass('nav-tab-active');
                $('.tab-content').removeClass('active').hide();
                
                // Add active class to target tab
                $('a[href="' + tabId + '"]').addClass('nav-tab-active');
                
                // Show corresponding content
                $(tabId).addClass('active').show();
            }
            
            // Manejar clics en pestañas
            $('.nav-tab').click(function(e) {
                e.preventDefault();
                var target = $(this).attr('href');
                switchToTab(target);
            });
            
            // Verificar si hay parámetro de pestaña en URL y activar la pestaña correcta
            var urlParams = new URLSearchParams(window.location.search);
            var activeTab = urlParams.get('tab');
            
            if (activeTab) {
                switchToTab('#' + activeTab);
            }
            
            // Auto-focus en campo de email de prueba si estamos en pestaña de pruebas
            if (activeTab === 'test-config') {
                $('#test_email').focus();
            }
        });
        </script>
        <?php
    }
    
    /**
     * Save SMTP configuration
     */
    private static function save_smtp_config() {
        if (!wp_verify_nonce($_POST['qvc_smtp_nonce'], 'qvc_smtp_config_nonce')) {
            wp_die(__('Security check failed', 'qvaclick-email-manager'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'qvaclick-email-manager'));
        }
        
        // Debug: Log what we received
        // error_log('QvaClick Debug: POST data received for config save'); // Desactivado para reducir logs
        // error_log('QvaClick Debug: SMTP enabled: ' . (isset($_POST['smtp_enabled']) ? 'YES' : 'NO')); // Desactivado para reducir logs
        // error_log('QvaClick Debug: IMAP enabled: ' . (isset($_POST['imap_enabled']) ? 'YES' : 'NO')); // Desactivado para reducir logs
        // error_log('QvaClick Debug: SMTP host: ' . (isset($_POST['smtp_host']) ? $_POST['smtp_host'] : 'NOT SET')); // Desactivado para reducir logs
        // error_log('QvaClick Debug: IMAP host: ' . (isset($_POST['imap_host']) ? $_POST['imap_host'] : 'NOT SET')); // Desactivado para reducir logs
        
        // SMTP Configuration
        $smtp_config = array(
            'enabled' => isset($_POST['smtp_enabled']),
            'smtp_host' => sanitize_text_field($_POST['smtp_host'] ?? ''),
            'smtp_port' => intval($_POST['smtp_port'] ?? 587),
            'smtp_encryption' => sanitize_text_field($_POST['smtp_encryption'] ?? 'tls'),
            'smtp_username' => sanitize_text_field($_POST['smtp_username'] ?? ''),
            'smtp_password' => $_POST['smtp_password'] ?? '', // Don't sanitize password
            'from_email' => sanitize_email($_POST['from_email'] ?? ''),
            'from_name' => sanitize_text_field($_POST['from_name'] ?? ''),
            'debug_mode' => isset($_POST['debug_mode'])
        );
        
        // IMAP Configuration
        $imap_config = array(
            'enabled' => isset($_POST['imap_enabled']),
            'imap_host' => sanitize_text_field($_POST['imap_host'] ?? ''),
            'imap_port' => intval($_POST['imap_port'] ?? 993),
            'imap_encryption' => sanitize_text_field($_POST['imap_encryption'] ?? 'ssl'),
            'imap_username' => sanitize_text_field($_POST['imap_username'] ?? ''),
            'imap_password' => $_POST['imap_password'] ?? '', // Don't sanitize password
            'imap_folder' => sanitize_text_field($_POST['imap_folder'] ?? 'INBOX'),
            'check_interval' => intval($_POST['check_interval'] ?? 300)
        );
        
        // Debug: Log what we're about to save
        // error_log('QvaClick Debug: About to save SMTP config: ' . print_r($smtp_config, true)); // Desactivado - genera mucho spam
        // error_log('QvaClick Debug: About to save IMAP config: ' . print_r($imap_config, true)); // Desactivado - genera mucho spam
        
        // Save configurations
        $smtp_saved = update_option('qvc_smtp_config', $smtp_config);
        $imap_saved = update_option('qvc_imap_config', $imap_config);
        
        // Support Email Configuration
        if (isset($_POST['support_from_email'])) {
            $support_from_email = sanitize_email($_POST['support_from_email']);
            $support_reply_to_email = sanitize_email($_POST['support_reply_to_email']);
            $support_from_name = sanitize_text_field($_POST['support_from_name']);

            // Normalizar emails para evitar dominios con prefijo www.
            $normalize = function($email) {
                if (empty($email) || !is_string($email)) return $email;
                $email = trim($email);
                $atPos = strrpos($email, '@');
                if ($atPos === false) return $email;
                $local = substr($email, 0, $atPos);
                $domain = strtolower(substr($email, $atPos + 1));
                if (strpos($domain, 'www.') === 0) {
                    $domain = substr($domain, 4);
                }
                return $local . '@' . $domain;
            };

            $support_from_email = $normalize($support_from_email);
            $support_reply_to_email = $normalize($support_reply_to_email);
            
            update_option('qvc_support_email', $support_from_email);
            update_option('qvc_support_reply_to_email', $support_reply_to_email);
            update_option('qvc_support_from_name', $support_from_name);
            
            // error_log('QvaClick Debug: Support email config saved - From: ' . $support_from_email . ', Reply-To: ' . $support_reply_to_email); // Desactivado para reducir logs
        }
        
        // Debug: Log save results
        // error_log('QvaClick Debug: SMTP config saved: ' . ($smtp_saved ? 'SUCCESS' : 'FAILED')); // Desactivado para reducir logs
        // error_log('QvaClick Debug: IMAP config saved: ' . ($imap_saved ? 'SUCCESS' : 'FAILED')); // Desactivado para reducir logs
        
        // Update cron schedule if IMAP is enabled and interval changed
        if ($imap_config['enabled']) {
            // Clear existing schedule
            wp_clear_scheduled_hook('qvc_check_imap_emails');
            
            // Set new schedule based on configured interval
            $interval_name = 'qvc_email_interval'; // Default
            switch ($imap_config['check_interval']) {
                case 60:
                    $interval_name = 'qvc_email_1min';
                    break;
                case 300:
                    $interval_name = 'qvc_email_interval'; // 5 min default
                    break;
                case 900:
                    $interval_name = 'qvc_email_15min';
                    break;
                case 1800:
                    $interval_name = 'qvc_email_30min';
                    break;
            }
            
            wp_schedule_event(time(), $interval_name, 'qvc_check_imap_emails');
            
            echo '<div class="notice notice-info"><p>' . 
                 sprintf(__('Programado verificación IMAP cada %d minutos.', 'qvaclick-email-manager'), 
                         $imap_config['check_interval'] / 60) . '</p></div>';
        } else {
            // Clear schedule if IMAP is disabled
            wp_clear_scheduled_hook('qvc_check_imap_emails');
        }
        
        // Clear wp_mail function cache to apply new SMTP settings immediately
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        // Forzar recarga de PHPMailer con nueva configuración
        global $phpmailer;
        if (isset($phpmailer) && is_object($phpmailer)) {
            $phpmailer = null; // Limpiar instancia global
        }
        
        // Log que la configuración se aplicará a todos los emails
        if ($smtp_config['enabled']) {
            // error_log('QvaClick Debug: SMTP configuration saved - ALL WordPress emails will now use ' . $smtp_config['smtp_host']); // Desactivado para reducir logs
        }

        // Redirigir con parámetro de éxito para mostrar mensaje y mantener en pestaña correcta
        $redirect_url = add_query_arg(array(
            'page' => 'qvc-smtp-config',
            'config_saved' => '1',
            'tab' => 'smtp-config'
        ), admin_url('admin.php'));
        
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * Test SMTP connection
     */
    private static function test_smtp_connection() {
        if (!wp_verify_nonce($_POST['qvc_smtp_nonce'], 'qvc_smtp_config_nonce')) {
            return;
        }
        
        $test_email = sanitize_email($_POST['test_email']);
        if (!$test_email) {
            $redirect_url = add_query_arg(array(
                'page' => 'qvc-smtp-config',
                'test_error' => urlencode('Email inválido para la prueba'),
                'tab' => 'test-config'
            ), admin_url('admin.php'));
            wp_redirect($redirect_url);
            exit;
        }
        
        // Probar usando wp_mail (que usará nuestra configuración SMTP)
        $subject = __('✅ Prueba SMTP - QvaClick Email Manager', 'qvaclick-email-manager');
        $message = self::get_test_email_content();
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: QvaClick System <noreply@qvaclick.com>'
        );
        
        // Log antes del envío
        error_log('QvaClick SMTP Test: Attempting to send test email to ' . $test_email);
        
        $sent = wp_mail($test_email, $subject, $message, $headers);
        
        if ($sent) {
            $redirect_url = add_query_arg(array(
                'page' => 'qvc-smtp-config',
                'test_sent' => '1',
                'test_email' => urlencode($test_email),
                'tab' => 'test-config'
            ), admin_url('admin.php'));
        } else {
            $redirect_url = add_query_arg(array(
                'page' => 'qvc-smtp-config',
                'test_error' => urlencode('Error al enviar email de prueba'),
                'tab' => 'test-config'
            ), admin_url('admin.php'));
        }
        
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * Get test email content
     */
    private static function get_test_email_content() {
        $current_time = current_time('mysql');
        $site_url = home_url();
        $site_name = get_bloginfo('name');
        
        return '
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Prueba SMTP - QvaClick Email Manager</title>
        </head>
        <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
            <div style="max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;">
                <h2 style="color: #0073aa; border-bottom: 2px solid #0073aa; padding-bottom: 10px;">
                    ✅ Prueba SMTP Exitosa
                </h2>
                
                <p>¡Felicitaciones! Este email confirma que la configuración SMTP de QvaClick Email Manager está funcionando correctamente.</p>
                
                <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #00a0d2; margin: 20px 0;">
                    <h3 style="margin-top: 0; color: #0073aa;">📊 Detalles de la Prueba</h3>
                    <ul style="margin: 0;">
                        <li><strong>🕒 Fecha y hora:</strong> ' . $current_time . '</li>
                        <li><strong>🌐 Sitio web:</strong> <a href="' . $site_url . '">' . $site_name . '</a></li>
                        <li><strong>📧 Sistema:</strong> QvaClick Email Manager V1</li>
                        <li><strong>⚙️ Método:</strong> SMTP Personalizado</li>
                    </ul>
                </div>
                
                <div style="background: #e7f7e7; padding: 15px; border-left: 4px solid #00aa00; margin: 20px 0;">
                    <h3 style="margin-top: 0; color: #006600;">✅ Estado del Sistema</h3>
                    <p style="margin: 0;">
                        <strong>🟢 Sistema Operativo:</strong> El sistema de email está configurado y funcionando correctamente.<br>
                        <strong>📨 Envíos:</strong> WordPress ahora usará tu configuración SMTP personalizada para todos los emails.<br>
                        <strong>🔧 Configuración:</strong> Puedes gestionar la configuración desde el panel de administración.
                    </p>
                </div>
                
                <hr style="margin: 30px 0; border: none; border-top: 1px solid #ddd;">
                
                <p style="color: #666; font-size: 12px; text-align: center; margin: 0;">
                    Este email fue generado automáticamente por QvaClick Email Manager<br>
                    <a href="' . $site_url . '/wp-admin/admin.php?page=qvc-smtp-config">Gestionar Configuración SMTP</a>
                </p>
            </div>
        </body>
        </html>';
    }
    
    /**
     * Test IMAP connection
     */
    private static function test_imap_connection() {
        if (!wp_verify_nonce($_POST['qvc_smtp_nonce'], 'qvc_smtp_config_nonce')) {
            return;
        }
        
        $imap_config = get_option('qvc_imap_config', self::get_default_imap_config());
        
        if (empty($imap_config['imap_host']) || empty($imap_config['imap_username'])) {
            $redirect_url = add_query_arg(array(
                'page' => 'qvc-smtp-config',
                'test_error' => urlencode('Configuración IMAP incompleta'),
                'tab' => 'test-config'
            ), admin_url('admin.php'));
            wp_redirect($redirect_url);
            exit;
        }
        
        // Build IMAP connection string
        $encryption = '';
        if ($imap_config['imap_encryption'] === 'ssl') {
            $encryption = '/ssl';
        } elseif ($imap_config['imap_encryption'] === 'tls') {
            $encryption = '/tls';
        }
        
        $mailbox = '{' . $imap_config['imap_host'] . ':' . $imap_config['imap_port'] . $encryption . '}' . $imap_config['imap_folder'];
        
        $connection = @imap_open($mailbox, $imap_config['imap_username'], $imap_config['imap_password']);
        
        if ($connection) {
            $status = imap_status($connection, $mailbox, SA_ALL);
            $message_count = $status->messages;
            $unread_count = $status->unseen;
            
            imap_close($connection);
            
            $redirect_url = add_query_arg(array(
                'page' => 'qvc-smtp-config',
                'imap_success' => '1',
                'message_count' => $message_count,
                'unread_count' => $unread_count,
                'tab' => 'test-config'
            ), admin_url('admin.php'));
        } else {
            $error = imap_last_error();
            $redirect_url = add_query_arg(array(
                'page' => 'qvc-smtp-config',
                'test_error' => urlencode('Error IMAP: ' . $error),
                'tab' => 'test-config'
            ), admin_url('admin.php'));
        }
        
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * Handle emergency stop actions
     */
    private static function handle_emergency_stop() {
        if (!wp_verify_nonce($_POST['qvc_smtp_nonce'], 'qvc_smtp_config_nonce')) {
            return;
        }
        
        if (isset($_POST['emergency_stop'])) {
            self::activate_emergency_mode();
        } elseif (isset($_POST['emergency_restore'])) {
            self::deactivate_emergency_mode();
        }
    }
    
    /**
     * Activate emergency mode - stop all email functions
     */
    private static function activate_emergency_mode() {
        // Log the emergency action
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'action' => 'Sistema de email detenido por emergencia',
            'user' => wp_get_current_user()->user_login
        );
        
        $emergency_log = get_option('qvc_emergency_log', array());
        $emergency_log[] = $log_entry;
        update_option('qvc_emergency_log', $emergency_log);
        
        // Set emergency mode flag
        update_option('qvc_emergency_mode', true);
        
        // Disable SMTP and IMAP
        $smtp_config = get_option('qvc_smtp_config', self::get_default_config());
        $smtp_config['enabled'] = false;
        update_option('qvc_smtp_config', $smtp_config);
        
        $imap_config = get_option('qvc_imap_config', self::get_default_imap_config());
        $imap_config['enabled'] = false;
        update_option('qvc_imap_config', $imap_config);
        
        // Create emergency MU plugin to block all emails
        $mu_plugins_dir = WP_CONTENT_DIR . '/mu-plugins';
        if (!is_dir($mu_plugins_dir)) {
            wp_mkdir_p($mu_plugins_dir);
        }
        
        $emergency_mu_plugin = '<?php
/**
 * QvaClick Emergency Email Stop
 * Created automatically during emergency mode
 * This plugin blocks ALL email functionality
 */

// Block wp_mail completely
add_filter("wp_mail", "__return_false", 999999);
add_filter("pre_wp_mail", "__return_false", 999999);

// Remove all email-related actions and filters
add_action("init", function() {
    // Remove all wp_mail hooks
    remove_all_actions("wp_mail");
    remove_all_actions("phpmailer_init");
    remove_all_filters("wp_mail");
    
    // Block Contact Form 7 emails
    add_filter("wpcf7_mail_sent", "__return_false");
    add_filter("wpcf7_skip_mail", "__return_true");
    
    // Block WooCommerce emails
    remove_all_actions("woocommerce_email_send_before");
    remove_all_actions("woocommerce_email_send_after");
    
    // Block other common email plugins
    remove_all_actions("ninja_forms_after_submission");
    remove_all_actions("gform_after_submission");
}, 1);

// Clear all email-related cron jobs
add_action("wp_loaded", function() {
    $crons = _get_cron_array();
    foreach ($crons as $timestamp => $cron) {
        foreach ($cron as $hook => $dings) {
            if (stripos($hook, "email") !== false || 
                stripos($hook, "mail") !== false || 
                stripos($hook, "ticket") !== false ||
                stripos($hook, "notification") !== false) {
                wp_unschedule_event($timestamp, $hook);
            }
        }
    }
});

// Add admin notice
add_action("admin_notices", function() {
    echo "<div class=\"notice notice-error is-dismissible\">
            <p><strong>🚨 MODO EMERGENCIA ACTIVO:</strong> 
            Todo el sistema de email está deshabilitado. 
            <a href=\"admin.php?page=qvc-smtp-config\">Gestionar en configuración SMTP</a>
            </p>
          </div>";
});

// Log all blocked attempts
add_action("wp_mail", function($to, $subject, $message) {
    error_log("QVC EMERGENCY: Email bloqueado - To: " . (is_array($to) ? implode(", ", $to) : $to) . " Subject: " . $subject);
    return false;
}, 1, 3);
?>';

        file_put_contents($mu_plugins_dir . '/qvc-emergency-stop.php', $emergency_mu_plugin);
        
        // Clear all email-related scheduled events
        wp_clear_scheduled_hook('qvc_check_imap_emails');
        wp_clear_scheduled_hook('qvc_process_email_queue');
        wp_clear_scheduled_hook('qvc_send_notifications');
        
        // Clear Action Scheduler email jobs
        if (class_exists('ActionScheduler')) {
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE hook LIKE '%email%' OR hook LIKE '%mail%' OR hook LIKE '%ticket%'");
        }
        
        echo '<div class="notice notice-success"><p><strong>🚨 MODO EMERGENCIA ACTIVADO:</strong> Todo el sistema de email ha sido detenido.</p></div>';
    }
    
    /**
     * Deactivate emergency mode - restore email functions
     */
    private static function deactivate_emergency_mode() {
        // Log the restore action
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'action' => 'Sistema de email restaurado desde emergencia',
            'user' => wp_get_current_user()->user_login
        );
        
        $emergency_log = get_option('qvc_emergency_log', array());
        $emergency_log[] = $log_entry;
        update_option('qvc_emergency_log', $emergency_log);
        
        // Remove emergency mode flag
        update_option('qvc_emergency_mode', false);
        
        // Remove emergency MU plugin
        $mu_plugin_file = WP_CONTENT_DIR . '/mu-plugins/qvc-emergency-stop.php';
        if (file_exists($mu_plugin_file)) {
            unlink($mu_plugin_file);
        }
        
        echo '<div class="notice notice-success"><p><strong>🔄 SISTEMA RESTAURADO:</strong> El modo emergencia ha sido desactivado. Puedes reactivar SMTP/IMAP manualmente.</p></div>';
    }

    /**
     * Get default SMTP configuration
     */
    private static function get_default_config() {
        return array(
            'enabled' => false,
            'smtp_host' => 'mail.notifresh.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'no-reply@qvaclick.com', // Email para autenticación SMTP
            'smtp_password' => '',
            'from_email' => 'no-reply@qvaclick.com', // Email de envío (respuestas automáticas)
            'from_name' => 'QvaClick Sistema',
            'debug_mode' => true // Activar debug por defecto para diagnóstico
        );
    }
    
    /**
     * Get default IMAP configuration
     */
    private static function get_default_imap_config() {
        return array(
            'enabled' => false,
            'imap_host' => 'mail.notifresh.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => '',
            'imap_password' => '',
            'imap_folder' => 'INBOX',
            'check_interval' => 300
        );
    }
}
