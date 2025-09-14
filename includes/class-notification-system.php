<?php
/**
 * Sistema de Notificaciones QvaClick Email Manager
 * Maneja notificaciones visuales y por email para tickets y emails
 * 
 * @package QvaClick_Email_Manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class QvaClick_Notification_System {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_notification_badge'), 999);
        add_action('admin_bar_menu', array($this, 'add_admin_bar_notifications'), 999);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_notification_scripts'));
        add_action('wp_ajax_qvc_mark_notification_read', array($this, 'ajax_mark_notification_read'));
        add_action('wp_ajax_qvc_get_notifications', array($this, 'ajax_get_notifications'));
    }
    
    public function init() {
        $this->init_notifications_table();
    }
    
    /**
     * Crear tabla de notificaciones (metodo privado)
     */
    private function init_notifications_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'qvc_notifications';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            type varchar(50) NOT NULL,
            title varchar(255) NOT NULL,
            message text NOT NULL,
            reference_id varchar(50) DEFAULT NULL,
            reference_type varchar(50) DEFAULT NULL,
            user_id int(11) DEFAULT NULL,
            is_read tinyint(1) DEFAULT 0,
            priority enum('low','medium','high','urgent') DEFAULT 'medium',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            read_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY type (type),
            KEY is_read (is_read),
            KEY created_at (created_at),
            KEY user_id (user_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Crear notificación
     */
    public function create_notification($type, $title, $message, $reference_id = null, $reference_type = null, $priority = 'medium') {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'qvc_notifications';
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'reference_id' => $reference_id,
                'reference_type' => $reference_type,
                'priority' => $priority,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        if ($result) {
            $notification_id = $wpdb->insert_id;
            
            // Enviar notificación por email si está configurado
            $this->send_email_notification($type, $title, $message, $reference_id);
            
            // Log para debugging
            error_log("QvaClick Notification: {$type} - {$title}");
            
            return $notification_id;
        }
        
        return false;
    }
    
    /**
     * Obtener contadores de notificaciones
     */
    public function get_notification_counts() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'qvc_notifications';
        $tickets_table = $wpdb->prefix . 'qvc_support_tickets';
        
        // CORREGIDO: Contar emails sin leer en bandeja general
        $unread_emails = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}qvc_general_inbox WHERE status = 'pending'"
        );

        // CORREGIDO: Contar tickets ABIERTOS (no solo nuevos)
        // Los tickets abiertos incluyen: 'new' y 'open'
        $open_tickets = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$tickets_table} 
             WHERE status IN ('new', 'open')"
        );

        // Para compatibilidad con el sistema anterior
        $counts = (object) array(
            'new_emails' => (int)$unread_emails, // Ahora incluye emails sin leer
            'new_tickets' => (int)$open_tickets, // Ahora incluye todos los abiertos
            'updated_tickets' => 0,
            'urgent' => 0
        );

        // CORREGIDO: El badge suma emails sin leer + tickets abiertos
        $counts->total_unread = (int)$counts->new_emails + (int)$counts->new_tickets;

        return $counts;
    }
    
    /**
     * Agregar badge de notificaciones al menú
     */
    public function add_notification_badge() {
        global $menu, $submenu;
        
        $counts = $this->get_notification_counts();
        $total_unread = $counts->total_unread;
        
        if ($total_unread > 0) {
            // Buscar el menú de Email Manager
            foreach ($menu as $key => $menu_item) {
                if (isset($menu_item[2]) && $menu_item[2] === 'qvc-email-manager') {
                    // Verificar que no se haya agregado ya un badge
                    if (strpos($menu[$key][0], 'qvc-notification-badge') === false) {
                        $priority_class = $counts->urgent > 0 ? 'urgent' : 'normal';
                        $badge_html = sprintf(
                            ' <span class="qvc-notification-badge qvc-%s" data-count="%d">%d</span>',
                            $priority_class,
                            $total_unread,
                            $total_unread > 99 ? '99+' : $total_unread
                        );
                        $menu[$key][0] .= $badge_html;
                    }
                    break;
                }
            }

            // Badge también en el submenu "QVC Email" (slug qvc-admin-email)
            if (isset($submenu['qvc-email-manager']) && is_array($submenu['qvc-email-manager'])) {
                foreach ($submenu['qvc-email-manager'] as $skey => $sitem) {
                    if (isset($sitem[2]) && $sitem[2] === 'qvc-admin-email') {
                        if (strpos($submenu['qvc-email-manager'][$skey][0], 'qvc-notification-badge') === false) {
                            $priority_class = $counts->urgent > 0 ? 'urgent' : 'normal';
                            $badge_html = sprintf(
                                ' <span class="qvc-notification-badge qvc-%s" data-count="%d">%d</span>',
                                $priority_class,
                                $total_unread,
                                $total_unread > 99 ? '99+' : $total_unread
                            );
                            $submenu['qvc-email-manager'][$skey][0] .= $badge_html;
                        }
                        break;
                    }
                }
            }
        } else {
            // Si no hay notificaciones, limpiar badges existentes
            foreach ($menu as $key => $menu_item) {
                if (isset($menu_item[2]) && $menu_item[2] === 'qvc-email-manager') {
                    // Remover badge si no hay notificaciones
                    $menu[$key][0] = preg_replace('/<span class="qvc-notification-badge[^>]*>.*?<\/span>/', '', $menu[$key][0]);
                    break;
                }
            }

            if (isset($submenu['qvc-email-manager']) && is_array($submenu['qvc-email-manager'])) {
                foreach ($submenu['qvc-email-manager'] as $skey => $sitem) {
                    if (isset($sitem[2]) && $sitem[2] === 'qvc-admin-email') {
                        $submenu['qvc-email-manager'][$skey][0] = preg_replace('/<span class="qvc-notification-badge[^>]*>.*?<\/span>/', '', $submenu['qvc-email-manager'][$skey][0]);
                        break;
                    }
                }
            }
        }
    }
    
    /**
     * Agregar notificaciones a la barra de administración
     */
    public function add_admin_bar_notifications($wp_admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $counts = $this->get_notification_counts();
        $total_unread = $counts->total_unread;
        
        // Remover el nodo existente si ya existe
        $wp_admin_bar->remove_node('qvc-notifications');
        
        if ($total_unread > 0) {
            $wp_admin_bar->add_node(array(
                'id' => 'qvc-notifications',
                'title' => sprintf(
                    '<span class="ab-icon dashicons dashicons-email-alt"></span><span class="ab-label qvc-admin-bar-count">%d</span>',
                    $total_unread > 99 ? 99 : $total_unread
                ),
                'href' => admin_url('admin.php?page=qvc-email-manager&tab=notifications'),
                'meta' => array(
                    'title' => sprintf(__('QvaClick: %d notificaciones sin leer', 'qvaclick-email-manager'), $total_unread)
                )
            ));
            
            // Submenu con detalles
            if ($counts->new_tickets > 0) {
                $wp_admin_bar->add_node(array(
                    'parent' => 'qvc-notifications',
                    'id' => 'qvc-new-tickets',
                    'title' => sprintf(__('🎫 %d Tickets Nuevos', 'qvaclick-email-manager'), $counts->new_tickets),
                    'href' => admin_url('admin.php?page=qvc-admin-email&filter=new_tickets')
                ));
            }
            
            if ($counts->updated_tickets > 0) {
                $wp_admin_bar->add_node(array(
                    'parent' => 'qvc-notifications',
                    'id' => 'qvc-updated-tickets',
                    'title' => sprintf(__('🔄 %d Tickets Actualizados', 'qvaclick-email-manager'), $counts->updated_tickets),
                    'href' => admin_url('admin.php?page=qvc-admin-email&filter=updated_tickets')
                ));
            }
            
            if ($counts->new_emails > 0) {
                $wp_admin_bar->add_node(array(
                    'parent' => 'qvc-notifications',
                    'id' => 'qvc-new-emails',
                    'title' => sprintf(__('📧 %d Emails Nuevos', 'qvaclick-email-manager'), $counts->new_emails),
                    'href' => admin_url('admin.php?page=qvc-admin-email&tab=inbox')
                ));
            }
        }
    }
    
    /**
     * Cargar scripts y estilos de notificaciones
     */
    public function enqueue_notification_scripts($hook) {
    // Cargar los estilos y el JS en todas las páginas de admin para
    // garantizar que la burbuja de notificación siempre esté disponible
    // (es una pequeña regla CSS/JS que no impacta rendimiento).
        
        // CSS para las notificaciones
        wp_add_inline_style('wp-admin', '
            .qvc-notification-badge {
                background: #dc3232;
                color: white;
                border-radius: 10px;
                padding: 2px 6px;
                font-size: 11px;
                font-weight: bold;
                margin-left: 5px;
                display: inline-block;
                min-width: 16px;
                text-align: center;
                line-height: 1.2;
                vertical-align: middle;
            }
            
            /* CORREGIDO: Badge en línea con el texto del menú */
            .wp-menu-name .qvc-notification-badge {
                display: inline !important;
                vertical-align: baseline !important;
                margin-left: 5px !important;
                margin-top: 0 !important;
                position: relative !important;
                top: 0 !important;
            }

            /* Ajuste: no forzar flex en el nombre del menú para permitir nombres largos */
            .toplevel_page_qvc-email-manager > a .wp-menu-name {
                /* se mantiene el espacio para el badge, pero no se fuerza flex */
                gap: 8px;
            }
            
            .qvc-notification-badge.qvc-urgent {
                background: #ff4444;
                animation: qvc-pulse 1s infinite;
            }
            
            @keyframes qvc-pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.7; }
            }
            
            .qvc-admin-bar-count {
                background: #dc3232;
                border-radius: 10px;
                color: white;
                font-size: 11px;
                padding: 2px 5px;
                margin-left: 5px;
            }
            
            #wp-admin-bar-qvc-notifications .ab-icon:before {
                color: #dc3232;
            }
            
            /* Ocultar badges en submenus por defecto */
            .wp-submenu .qvc-notification-badge,
            .wp-submenu-head .qvc-notification-badge {
                display: none;
            }
            
            /* CORREGIDO: Ocultar también badges del sistema viejo */
            .wp-submenu .update-plugins,
            .wp-submenu-head .update-plugins {
                display: none !important;
            }
            
            /* Mostrar badge en el menú principal y en el submenu QVC Email */
            .toplevel_page_qvc-email-manager > a .qvc-notification-badge { display: inline-block !important; }
            #adminmenu .wp-submenu a[href*="page=qvc-admin-email"] .qvc-notification-badge { display: inline-block !important; }
            
            .qvc-notification-popup {
                position: fixed;
                top: 32px;
                right: 20px;
                background: white;
                border: 1px solid #ccc;
                border-radius: 4px;
                padding: 15px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                z-index: 999999;
                max-width: 300px;
                display: none;
            }
            
            .qvc-notification-popup.show {
                display: block;
                animation: slideIn 0.3s ease-out;
            }
            
            @keyframes slideIn {
                from { transform: translateX(100%); }
                to { transform: translateX(0); }
            }
        ');
        
        // JavaScript para notificaciones en tiempo real
        wp_add_inline_script('jquery', '
            jQuery(document).ready(function($) {
                // Verificar notificaciones cada 30 segundos
                setInterval(function() {
                    checkForNewNotifications();
                }, 30000);
                
                function checkForNewNotifications() {
                    $.ajax({
                        url: ajaxurl,
                        data: {
                            action: "qvc_get_notifications",
                            nonce: "' . wp_create_nonce('qvc_notifications') . '"
                        },
                        success: function(response) {
                            if (response.success && response.data.has_new) {
                                updateNotificationBadges(response.data.counts);
                                if (response.data.show_popup) {
                                    showNotificationPopup(response.data.latest);
                                }
                            }
                        }
                    });
                }
                
                function updateNotificationBadges(counts) {
                    var totalText = (counts.total_unread > 99 ? "99+" : counts.total_unread);
                    var badgeClass = counts.urgent > 0 ? "urgent" : "normal";

                    // Selectors
                    var topSelector = "#adminmenu a[href*=\"page=qvc-email-manager\"] .wp-menu-name";
                    var subSelector = "#adminmenu .wp-submenu a[href*=\"page=qvc-admin-email\"]";

                    if (counts.total_unread > 0) {
                        // Update existing badges
                        $(".qvc-notification-badge").text(totalText);

                        // Ensure top-level badge exists
                        if ($(topSelector + " .qvc-notification-badge").length === 0) {
                            var badgeHtmlTop = " <span class=\"qvc-notification-badge qvc-" + badgeClass + "\">" + totalText + "</span>";
                            $(topSelector).append(badgeHtmlTop);
                        }

                        // Ensure submenu badge exists
                        if ($(subSelector + " .qvc-notification-badge").length === 0) {
                            var badgeHtmlSub = " <span class=\"qvc-notification-badge qvc-" + badgeClass + "\">" + totalText + "</span>";
                            $(subSelector).append(badgeHtmlSub);
                        }

                        // Update admin bar
                        var $adminBarCount = $(".qvc-admin-bar-count");
                        if ($adminBarCount.length) {
                            $adminBarCount.text(counts.total_unread > 99 ? 99 : counts.total_unread);
                        }
                    }
                }
                
                function showNotificationPopup(notification) {
                    var popup = $(\'<div class="qvc-notification-popup">\' +
                        \'<h4>\' + notification.title + \'</h4>\' +
                        \'<p>\' + notification.message + \'</p>\' +
                        \'<button class="button-primary qvc-view-notification" data-id="\' + notification.id + \'">Ver</button> \' +
                        \'<button class="button qvc-dismiss-notification">Cerrar</button>\' +
                        \'</div>\');
                    
                    $("body").append(popup);
                    popup.addClass("show");
                    
                    // Auto-ocultar después de 5 segundos
                    setTimeout(function() {
                        popup.removeClass("show");
                        setTimeout(function() { popup.remove(); }, 300);
                    }, 5000);
                }
                
                // Event handlers
                $(document).on("click", ".qvc-dismiss-notification", function() {
                    $(this).closest(".qvc-notification-popup").removeClass("show");
                });
                
                $(document).on("click", ".qvc-view-notification", function() {
                    var notificationId = $(this).data("id");
                    // Marcar como leída y redirigir
                    window.location.href = "' . admin_url('admin.php?page=qvc-email-manager&tab=notifications&view=') . '" + notificationId;
                });
            });
        ');
    }
    
    /**
     * Enviar notificación por email
     */
    private function send_email_notification($type, $title, $message, $reference_id = null) {
        // Verificar si las notificaciones por email están habilitadas
        $email_notifications = get_option('qvc_email_notifications', array());
        
        if (!isset($email_notifications['enabled']) || !$email_notifications['enabled']) {
            return;
        }
        
        // Obtener admins para notificar
        $admin_emails = $this->get_admin_emails();
        
        if (empty($admin_emails)) {
            return;
        }
        
        // Preparar el email
        $subject = sprintf('[QvaClick] %s', $title);
        $email_body = $this->get_notification_email_template($type, $title, $message, $reference_id);
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: QvaClick Sistema <' . get_option('admin_email') . '>'
        );
        
        // Enviar a cada admin
        foreach ($admin_emails as $admin_email) {
            wp_mail($admin_email, $subject, $email_body, $headers);
        }
        
        error_log("QvaClick: Email notification sent to " . count($admin_emails) . " admins for: {$type}");
    }
    
    /**
     * Obtener emails de administradores
     */
    private function get_admin_emails() {
        $admins = get_users(array(
            'role' => 'administrator',
            'fields' => array('user_email')
        ));
        
        $emails = array();
        foreach ($admins as $admin) {
            $emails[] = $admin->user_email;
        }
        
        // Agregar emails adicionales configurados
        $extra_emails = get_option('qvc_notification_emails', array());
        if (!empty($extra_emails)) {
            $emails = array_merge($emails, $extra_emails);
        }
        
        return array_unique($emails);
    }
    
    /**
     * Template para emails de notificación
     */
    private function get_notification_email_template($type, $title, $message, $reference_id = null) {
        $site_name = get_bloginfo('name');
        $site_url = get_site_url();
        
        // URLs específicas según el tipo
    $action_url = admin_url('admin.php?page=qvc-email-manager');
        switch ($type) {
            case 'new_ticket':
            case 'updated_ticket':
                $action_url = admin_url('admin.php?page=qvc-admin-email&tab=tickets');
                if ($reference_id) {
                    $action_url .= '&ticket_id=' . urlencode($reference_id);
                }
                break;
            case 'new_email':
                $action_url = admin_url('admin.php?page=qvc-admin-email&tab=inbox');
                break;
        }
        
        $icons = array(
            'new_ticket' => '🎫',
            'updated_ticket' => '🔄',
            'new_email' => '📧',
            'system_alert' => '⚠️'
        );
        
        $icon = isset($icons[$type]) ? $icons[$type] : '🔔';
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title><?php echo esc_html($title); ?></title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #0073aa; color: white; padding: 20px; text-align: center; }
                .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
                .footer { background: #f1f1f1; padding: 15px; text-align: center; font-size: 12px; }
                .button { background: #0073aa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 15px 0; }
                .urgent { border-left: 4px solid #dc3232; padding-left: 15px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1><?php echo $icon; ?> <?php echo esc_html($site_name); ?></h1>
                    <p>Sistema de Notificaciones QvaClick</p>
                </div>
                
                <div class="content">
                    <h2><?php echo esc_html($title); ?></h2>
                    <p><?php echo nl2br(esc_html($message)); ?></p>
                    
                    <?php if ($reference_id): ?>
                    <p><strong>Referencia:</strong> <?php echo esc_html($reference_id); ?></p>
                    <?php endif; ?>
                    
                    <p>
                        <a href="<?php echo esc_url($action_url); ?>" class="button">Ver en Sistema</a>
                    </p>
                    
                    <p><small><strong>Fecha:</strong> <?php echo current_time('Y-m-d H:i:s'); ?></small></p>
                </div>
                
                <div class="footer">
                    <p>Este es un email automático del sistema <?php echo esc_html($site_name); ?></p>
                    <p><a href="<?php echo esc_url($site_url); ?>"><?php echo esc_html($site_url); ?></a></p>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * AJAX: Marcar notificación como leída
     */
    public function ajax_mark_notification_read() {
        check_ajax_referer('qvc_notifications', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $notification_id = intval($_POST['notification_id']);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'qvc_notifications';
        
        $result = $wpdb->update(
            $table_name,
            array(
                'is_read' => 1,
                'read_at' => current_time('mysql')
            ),
            array('id' => $notification_id),
            array('%d', '%s'),
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Notificación marcada como leída'));
        } else {
            wp_send_json_error(array('message' => 'Error al marcar notificación'));
        }
    }
    
    /**
     * AJAX: Obtener nuevas notificaciones
     */
    public function ajax_get_notifications() {
        check_ajax_referer('qvc_notifications', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $counts = $this->get_notification_counts();
        $last_check = get_user_meta(get_current_user_id(), 'qvc_last_notification_check', true);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'qvc_notifications';
        
        // Obtener notificaciones recientes
        $recent_notifications = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} 
                WHERE is_read = 0 AND created_at > %s 
                ORDER BY created_at DESC LIMIT 5",
                $last_check ? $last_check : date('Y-m-d H:i:s', strtotime('-1 hour'))
            )
        );
        
        // Actualizar último check
        update_user_meta(get_current_user_id(), 'qvc_last_notification_check', current_time('mysql'));
        
        wp_send_json_success(array(
            'counts' => $counts,
            'has_new' => !empty($recent_notifications),
            'latest' => !empty($recent_notifications) ? $recent_notifications[0] : null,
            'show_popup' => !empty($recent_notifications) && $counts->urgent > 0
        ));
    }
    
    // ==========================================
    // MÉTODOS PARA CREAR NOTIFICACIONES
    // ==========================================
    
    /**
     * Notificar nuevo ticket
     */
    public static function notify_new_ticket($ticket_id, $email, $subject) {
        $instance = self::get_instance();
        
        $title = 'Nuevo Ticket de Soporte';
        $message = sprintf(
            "Se ha creado un nuevo ticket.\n\nTicket: %s\nDe: %s\nAsunto: %s",
            $ticket_id,
            $email,
            $subject
        );
        
        return $instance->create_notification('new_ticket', $title, $message, $ticket_id, 'ticket', 'high');
    }
    
    /**
     * Notificar ticket actualizado
     */
    public static function notify_ticket_updated($ticket_id, $email, $subject) {
        $instance = self::get_instance();
        
        $title = 'Ticket Actualizado';
        $message = sprintf(
            "Se ha recibido una respuesta al ticket.\n\nTicket: %s\nDe: %s\nAsunto: %s",
            $ticket_id,
            $email,
            $subject
        );
        
        return $instance->create_notification('updated_ticket', $title, $message, $ticket_id, 'ticket', 'medium');
    }
    
    /**
     * Notificar nuevo email
     */
    public static function notify_new_email($email_id, $from_email, $subject) {
        $instance = self::get_instance();
        
        $title = 'Nuevo Email en Bandeja General';
        $message = sprintf(
            "Se ha recibido un nuevo email.\n\nDe: %s\nAsunto: %s",
            $from_email,
            $subject
        );
        
        return $instance->create_notification('new_email', $title, $message, $email_id, 'email', 'medium');
    }
    
    /**
     * Create notifications table on plugin activation
     */
    public static function create_notifications_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'qvc_notifications';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            type varchar(50) NOT NULL,
            title varchar(255) NOT NULL,
            message text NOT NULL,
            reference_id int(11) DEFAULT NULL,
            reference_table varchar(50) DEFAULT NULL,
            user_id int(11) DEFAULT NULL,
            read_status tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            read_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_type (type),
            KEY idx_read_status (read_status),
            KEY idx_user_id (user_id),
            KEY idx_created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        error_log('QvaClick Debug: Notifications table created/updated');
    }
}

// Inicializar el sistema de notificaciones
add_action('plugins_loaded', function() {
    if (class_exists('QvaClick_Email_Manager')) {
        QvaClick_Notification_System::get_instance();
    }
});
