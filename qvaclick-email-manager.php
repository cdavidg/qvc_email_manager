<?php
/**
 * Plugin Name: QvaClick Email Manager V1
 * Plugin URI: https://qvaclick.com/
 * Description: QvaClick Email Manager V1 es un plugin avanzado para la gestión de correos electrónicos en WordPress, diseñado para integrarse con el Tema Exertio y el plugin Exertio Framework. Ofrece funcionalidades como creación y gestión de plantillas de correo, envío masivo de campañas, sistema de tickets de soporte, integración con formularios de contacto, manejo de correos IMAP, configuración personalizada de SMTP, y un sistema de notificaciones. Incluye herramientas avanzadas como sincronización con Redux, tracking de emails, analytics, y soporte para eventos personalizados mediante hooks. Ideal para automatizar y optimizar la comunicación por correo electrónico en proyectos complejos.
 * Version: 3.2.2
 * Author: David Guerra | @cedav95 | QvaClick Team
 * License: GPL v2 or later
 * Text Domain: qvaclick-email-manager
 * Requires at least: 5.8
 * Tested up to: 6.3
 * Requires PHP: 7.4
 * Update URI: https://github.com/cdavidg/qvc_email_manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('QVC_EMAIL_MANAGER_VERSION', '3.2.2');
define('QVC_EMAIL_MANAGER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('QVC_EMAIL_MANAGER_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main Plugin Class
 */
class QvaClick_Email_Manager {
    
    private static $instance = null;
    
    /**
     * Plugin version (avoid undefined property notices in asset enqueue)
     */
    private $version = QVC_EMAIL_MANAGER_VERSION;
    
    /**
     * Admin email manager instance
     */
    private $admin_email_manager;
    
    /**
     * Admin email interface instance
     */
    private $admin_email_interface;
    
    /**
     * Enhanced cron manager instance
     */
    private $enhanced_cron_manager;
    
    /**
     * Notification system instance
     */
    private $notification_system;
    
    /**
     * Enhanced admin interface instance - REMOVIDO PARA EVITAR CONFLICTOS
     * USAR SOLO: class-admin-email-interface.php
     */
    // private $enhanced_admin_interface;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Hook de activación del plugin
        register_activation_hook(__FILE__, array($this, 'plugin_activation'));
        add_action('plugins_loaded', array($this, 'init'));
        // Registrar actualizaciones desde GitHub en admin
        if (is_admin()) {
            add_action('admin_init', array($this, 'init_github_updater'));
        }
    }
    
    public function init() {
        // Check if Exertio Framework is active
        if (!$this->is_exertio_framework_active()) {
            add_action('admin_notices', array($this, 'framework_missing_notice'));
            return;
        }

        // Initialize plugin
        $this->load_dependencies();
        $this->init_instances(); // Inicializar instancias después de cargar dependencias
        // Ensure DB migrations related to hooks/status are applied
        if (class_exists('QvaClick_Hook_Database_Manager')) {
            $dbm = QvaClick_Hook_Database_Manager::get_instance();
            // Ejecutar de forma silenciosa y segura
            $dbm->ensure_status_enum_deleted();
        }
        $this->ensure_caps();
        $this->setup_hooks();
    }

    /**
     * Inicializa el updater de GitHub para ofrecer actualizaciones automáticas.
     */
    public function init_github_updater() {
        require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-github-updater.php';
        $plugin_basename = plugin_basename(__FILE__);
        $updater = new QVC_GitHub_Updater(array(
            'owner' => 'cdavidg',
            'repo'  => 'qvc_email_manager',
            'plugin_basename' => $plugin_basename,
            'plugin_dirname'  => dirname($plugin_basename),
            'current_version' => QVC_EMAIL_MANAGER_VERSION,
        ));
        $updater->init();
    }
    
    private function ensure_caps() {
        // Garantizar que los administradores tengan la capacidad personalizada
        if (is_admin()) {
            $admin = get_role('administrator');
            if ($admin && !$admin->has_cap('qvc_manage_emails')) {
                $admin->add_cap('qvc_manage_emails');
            }
        }
    }
    
    private function is_exertio_framework_active() {
        return function_exists('fl_framework_get_options') || 
               (class_exists('Redux') && get_option('exertio_theme_options'));
    }
    
    public function framework_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php _e('QvaClick Email Manager requiere que Exertio Framework esté activo.', 'qvaclick-email-manager'); ?></p>
        </div>
        <?php
    }
    
    private function load_dependencies() {
        // Core classes principales
        require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-email-discovery.php';
        require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-base-template-manager.php';
        
        // Security Shield - NUEVO SISTEMA DE SEGURIDAD
        if (file_exists(QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-ticket-security-shield.php')) {
            require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-ticket-security-shield.php';
        }
        
        // Notification system
        require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-notification-system.php';
        
        // Enhanced security and classification system
        if (file_exists(QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-email-security-scanner.php')) {
            require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-email-security-scanner.php';
        }
        
        if (file_exists(QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-email-classifier.php')) {
            require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-email-classifier.php';
        }
        
        // Enhanced IMAP system - DESACTIVADO TEMPORALMENTE POR CONFLICTOS
        // if (file_exists(QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-enhanced-imap-reader.php')) {
        //     require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-enhanced-imap-reader.php';
        // }
        
        // IMAP reader legacy - REACTIVADO por estabilidad y funcionamiento
        if (file_exists(QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/imap-reader.php')) {
            require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/imap-reader.php';
        }
        
        // Enhanced admin interface - REMOVIDO PARA EVITAR CONFLICTOS
        // USAR SOLO: class-admin-email-interface.php
        // if (file_exists(QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-enhanced-admin-interface.php')) {
        //     require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-enhanced-admin-interface.php';
        // }
        
        // Enhanced cron management - DESACTIVADO COMPLETAMENTE
        // if (file_exists(QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-enhanced-cron-manager.php')) {
        //     require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-enhanced-cron-manager.php';
        // }
        
        // Legacy classes (mantener compatibilidad)
        if (file_exists(QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-admin-email-manager.php')) {
            require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-admin-email-manager.php';
        }
        
        if (file_exists(QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-admin-email-interface.php')) {
            require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-admin-email-interface.php';
        }
        
    // IMAP reader (legacy) deshabilitado para evitar conflictos
        require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-admin-interface.php';
        require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-rest-api.php';
        
    // IMAP Reader legacy no se carga; usar Enhanced IMAP Reader
        
        // Framework interceptor y dispatchers
        require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-framework-interceptor.php';
        require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-hook-dispatcher.php';
        
        // Sincronización y gestión
        require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-redux-sync-manager.php';
        require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-exertio-key-mapping.php';
        require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-single-source-sync.php';
        
        // Gestores principales de email
        require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-admin-email-manager.php';
        require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-admin-email-interface.php';
        
        // Páginas administrativas
        require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'admin/outbox-page.php';
        require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'admin/smtp-config-page.php';
        
        // Database manager
        require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-database-manager.php';
        
        // Ticket chronological order system
        require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-ticket-chronological-order.php';
        
        // Inicializar sistema de base de datos
        QvaClick_Hook_Database_Manager::init();
        
        // Comandos CLI (solo en contexto CLI)
        if (defined('WP_CLI') && WP_CLI) {
            $cli_file = QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/class-cli-commands.php';
            if (file_exists($cli_file)) {
                require_once $cli_file;
            }
        }
    }
    
    /**
     * Inicializar instancias después de que todos los archivos estén cargados
     */
    private function init_instances() {
        // Inicializar Security Shield PRIMERO
        if (class_exists('QvaClick_Ticket_Security_Shield')) {
            QvaClick_Ticket_Security_Shield::get_instance();
            // error_log('QvaClick Debug: Ticket Security Shield initialized'); // Desactivado - funciona correctamente
        } else {
            error_log('QvaClick Warning: Security Shield not available');
        }
        
        // Verificar que las clases existen antes de inicializar
        if (class_exists('QvaClick_Admin_Email_Manager')) {
            $this->admin_email_manager = QvaClick_Admin_Email_Manager::get_instance();
            $this->admin_email_manager->init();
        } else {
            // error_log('QvaClick Debug: QvaClick_Admin_Email_Manager class not found'); // Desactivado para reducir logs
        }
        
        if (class_exists('QvaClick_Admin_Email_Interface')) {
            $this->admin_email_interface = QvaClick_Admin_Email_Interface::get_instance();
        } else {
            // error_log('QvaClick Debug: QvaClick_Admin_Email_Interface class not found'); // Desactivado para reducir logs
        }
        
        // Enhanced Cron Manager - DESACTIVADO TEMPORALMENTE 
        // Volvemos al cron simple que usa el legacy IMAP reader
        // if (class_exists('QvaClick_Enhanced_Cron_Manager')) {
        //     $this->enhanced_cron_manager = new QvaClick_Enhanced_Cron_Manager();
        //     add_filter('cron_schedules', array('QvaClick_Enhanced_Cron_Manager', 'add_cron_intervals'));
        //     error_log('QvaClick Debug: Enhanced Cron Manager initialized');
        // } else {
        //     error_log('QvaClick Debug: QvaClick_Enhanced_Cron_Manager class not found');
        // }
        
        // error_log('QvaClick Debug: Using legacy IMAP processing (no Enhanced Cron Manager)'); // Desactivado - funciona correctamente
        
        // Enhanced Admin Interface - REMOVIDO PARA EVITAR CONFLICTOS
        // USAR SOLO: class-admin-email-interface.php - los métodos AJAX están duplicados
        // if (class_exists('QvaClick_Enhanced_Admin_Interface')) {
        //     $this->enhanced_admin_interface = new QvaClick_Enhanced_Admin_Interface();
        //     error_log('QvaClick Debug: Enhanced Admin Interface DESHABILITADO por conflictos');
        // }
        
        // Inicializar sistema de notificaciones
        if (class_exists('QvaClick_Notification_System')) {
            $this->notification_system = new QvaClick_Notification_System();
            // error_log('QvaClick Debug: Notification System initialized'); // Desactivado - funciona correctamente
        } else {
            // error_log('QvaClick Debug: QvaClick_Notification_System class not found'); // Desactivado para reducir logs
        }
    }

    /**
     * Método de activación del plugin
     */
    public function plugin_activation() {
        // Load dependencies first
        $this->load_dependencies();
        
        // Create enhanced database tables
        if (class_exists('QvaClick_Enhanced_IMAP_Reader')) {
            QvaClick_Enhanced_IMAP_Reader::create_enhanced_tables();
        }
        
        if (class_exists('QvaClick_Email_Security_Scanner')) {
            QvaClick_Email_Security_Scanner::create_security_table();
        }
        
        if (class_exists('QvaClick_Email_Classifier')) {
            QvaClick_Email_Classifier::create_classification_table();
        }
        
        if (class_exists('QvaClick_Enhanced_Cron_Manager')) {
            QvaClick_Enhanced_Cron_Manager::create_reports_table();
        }
        
        // Notification system database setup
        if (class_exists('QvaClick_Notification_System')) {
            QvaClick_Notification_System::create_notifications_table();
        }
        
        // Legacy database setup
        if (class_exists('QvaClick_Admin_Email_Manager')) {
            QvaClick_Admin_Email_Manager::create_tables();
        }
        
        // Initialize default options
        $this->init_default_options();
        
        // Crear las tablas de Admin Email
        $admin_email_manager = QvaClick_Admin_Email_Manager::get_instance();
        $admin_email_manager->create_tables();
        
        // Forzar la actualización de opciones de rewrite
        flush_rewrite_rules();
    }
    
    /**
     * Initialize default plugin options
     */
    private function init_default_options() {
        // Security scanner default config
        if (!get_option('qvc_email_security_config')) {
            update_option('qvc_email_security_config', array(
                'scan_enabled' => true,
                'quarantine_threshold' => 30,
                'rate_limit_hour' => 10,
                'rate_limit_day' => 50,
                'scan_attachments' => true,
                'block_suspicious_domains' => true,
                'auto_learn_spam' => true
            ));
        }
        
        // Classification default config
        if (!get_option('qvc_classification_stats')) {
            update_option('qvc_classification_stats', array());
        }
        
        // Auto assignment rules
        if (!get_option('qvc_auto_assignment_rules')) {
            update_option('qvc_auto_assignment_rules', array(
                'support_tickets' => array('enabled' => false, 'assigned_user' => ''),
                'sales_inquiries' => array('enabled' => false, 'assigned_user' => ''),
                'domains' => array()
            ));
        }
        
        // Cleanup settings
        if (!get_option('qvc_cleanup_settings')) {
            update_option('qvc_cleanup_settings', array(
                'quarantine_days' => 30,
                'processed_inbox_days' => 90,
                'security_log_days' => 60,
                'spam_log_days' => 30,
                'classification_log_days' => 180
            ));
        }
        
        // Alert settings
        if (!get_option('qvc_alert_thresholds')) {
            update_option('qvc_alert_thresholds', array(
                'high_error_rate' => 0.2,
                'high_quarantine_rate' => 0.3,
                'low_processing_rate' => 5
            ));
        }
        
        // Email templates
        if (!get_option('qvc_auto_acknowledgment_template')) {
            update_option('qvc_auto_acknowledgment_template', 
                'Gracias por contactarnos. Su consulta ha sido recibida y será atendida a la brevedad.');
        }
        
        if (!get_option('qvc_sales_template')) {
            update_option('qvc_sales_template', 
                'Gracias por su interés en nuestros servicios. Un miembro de nuestro equipo de ventas se pondrá en contacto con usted pronto.');
        }
        
        if (!get_option('qvc_general_template')) {
            update_option('qvc_general_template', 
                'Gracias por contactarnos. Hemos recibido su mensaje y le responderemos a la brevedad.');
        }
        
        // Email lists
        if (!get_option('qvc_email_blacklist')) {
            update_option('qvc_email_blacklist', array());
        }
        
        if (!get_option('qvc_email_whitelist')) {
            update_option('qvc_email_whitelist', array());
        }
        
        // Notification emails
        if (!get_option('qvc_manager_email')) {
            update_option('qvc_manager_email', get_option('admin_email'));
        }
        
        if (!get_option('qvc_sales_email')) {
            update_option('qvc_sales_email', 'ventas@qvaclick.com');
        }
        
        if (!get_option('qvc_alert_email')) {
            update_option('qvc_alert_email', get_option('admin_email'));
        }
        
        if (!get_option('qvc_reports_email')) {
            update_option('qvc_reports_email', get_option('admin_email'));
        }
        
        // Reports settings
        if (!get_option('qvc_daily_reports_enabled')) {
            update_option('qvc_daily_reports_enabled', false);
        }
    }
    
    /**
     * Get menu title with notification badges
     */
    private function get_menu_title_with_notifications() {
        // DESHABILITADO: Esta función está causando badges duplicados
        // Ahora usamos solo QvaClick_Notification_System para todos los badges
        return 'QVC Email';
        
        /* CÓDIGO ORIGINAL COMENTADO
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return 'Email Manager';
        }
        
        // Usar las tablas correctas qvc_support_tickets y qvc_ticket_messages
        $unread_count = 0;
        try {
            $tickets_table = $wpdb->prefix . 'qvc_support_tickets';
            
            // Contar tickets no cerrados
            $unread_count = (int) $wpdb->get_var("
                SELECT COUNT(*) 
                FROM {$tickets_table} 
                WHERE status IN ('new', 'open', 'in_progress', 'pending') 
                AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            
            if (!$unread_count) $unread_count = 0;
        } catch (Exception $e) {
            $unread_count = 0;
        }
        
        $title = 'Email Manager';
        
        if ($unread_count > 0) {
            $title .= ' <span class="update-plugins count-' . $unread_count . '"><span class="update-count">' . $unread_count . '</span></span>';
        }
        */
        
        return $title;
    }
    
    private function setup_hooks() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_ajax_qvc_email_apply_base_template', array($this, 'ajax_apply_base_template'));
        add_action('wp_ajax_qvc_email_preview_template', array($this, 'ajax_preview_template'));
        add_action('wp_ajax_qvc_email_preview_individual_template', array($this, 'ajax_preview_individual_template'));
        add_action('wp_ajax_qvc_email_preview_specific_template', array($this, 'ajax_preview_specific_template'));
    add_action('wp_ajax_qvc_email_send_test', array($this, 'ajax_send_test_email'));
    add_action('wp_ajax_qvc_email_export_templates', array($this, 'ajax_export_templates'));
    // Hook emails: preview, test send, apply base
    add_action('wp_ajax_qvc_hook_email_preview', array($this, 'ajax_hook_email_preview'));
    add_action('wp_ajax_qvc_hook_email_send_test', array($this, 'ajax_hook_email_send_test'));
    add_action('wp_ajax_qvc_hook_email_apply_base', array($this, 'ajax_hook_email_apply_base'));
    // Importación temporal de templates desde JSON
    add_action('wp_ajax_qvc_email_import_templates', array($this, 'ajax_import_templates'));
    // Sincronización con Exertio Framework
    add_action('wp_ajax_qvc_email_sync_exertio', array($this, 'ajax_sync_exertio'));
    
    // Inicializar interceptor de funciones del framework
    if (class_exists('QvaClick_Framework_Interceptor')) {
        QvaClick_Framework_Interceptor::get_instance();
    }
    add_action('wp_ajax_qvc_email_import_templates', array($this, 'ajax_import_templates'));

    // Admin Email AJAX handlers
    add_action('wp_ajax_qvc_load_recipient_preview', array($this, 'ajax_load_recipient_preview'));
    add_action('wp_ajax_qvc_create_support_ticket', array($this, 'ajax_create_support_ticket'));
    add_action('wp_ajax_qvc_send_mass_email_campaign', array($this, 'ajax_send_mass_email_campaign'));
    add_action('wp_ajax_qvc_search_users', array($this, 'ajax_search_users'));
    add_action('wp_ajax_qvc_view_campaign', array($this, 'ajax_view_campaign'));
    add_action('wp_ajax_qvc_duplicate_campaign', array($this, 'ajax_duplicate_campaign'));
    add_action('wp_ajax_qvc_delete_campaign', array($this, 'ajax_delete_campaign'));
    add_action('wp_ajax_qvc_get_campaigns', array($this, 'ajax_get_campaigns'));

    // Endpoints faltantes para contadores dinámicos en Admin Email
    add_action('wp_ajax_qvc_get_unread_count', array($this, 'ajax_get_unread_count'));
    add_action('wp_ajax_qvc_mark_ticket_read', array($this, 'ajax_mark_ticket_read'));

    // AJAX handlers para funciones de seguridad y tickets
    add_action('wp_ajax_qvc_delete_ticket', array('QvaClick_Admin_Email_Interface', 'ajax_delete_ticket'));
    add_action('wp_ajax_qvc_clean_resolved_tickets', array('QvaClick_Admin_Email_Interface', 'ajax_clean_resolved_tickets'));
    add_action('wp_ajax_qvc_quarantine_action', array('QvaClick_Admin_Email_Interface', 'ajax_quarantine_action'));
    add_action('wp_ajax_qvc_approve_quarantine', array('QvaClick_Admin_Email_Interface', 'ajax_approve_quarantine'));
    add_action('wp_ajax_qvc_reject_quarantine', array('QvaClick_Admin_Email_Interface', 'ajax_reject_quarantine'));
    add_action('wp_ajax_qvc_view_general_email', array('QvaClick_Admin_Email_Interface', 'ajax_view_general_email'));
    add_action('wp_ajax_qvc_update_general_email_status', array('QvaClick_Admin_Email_Interface', 'ajax_update_general_email_status'));
    add_action('wp_ajax_qvc_convert_email_to_ticket', array('QvaClick_Admin_Email_Interface', 'ajax_convert_email_to_ticket'));
    
    // AJAX handlers para gestión de bad words
    add_action('wp_ajax_qvc_save_bad_words', array('QvaClick_Admin_Email_Interface', 'ajax_save_bad_words'));
    add_action('wp_ajax_qvc_get_bad_words', array('QvaClick_Admin_Email_Interface', 'ajax_get_bad_words'));
    add_action('wp_ajax_qvc_reset_bad_words', array('QvaClick_Admin_Email_Interface', 'ajax_reset_bad_words'));

    // Inicializar dispatcher de hooks (envío de emails por eventos)
    if (class_exists('QvaClick_Hook_Dispatcher')) {
        QvaClick_Hook_Dispatcher::init();
    }
    
    // Conectar hooks para capturar emails de Contact Form 7
    add_action('init', array($this, 'init_contact_form_hooks'));
    
    // Hook PHPMailer para usar nuestra configuración SMTP
    add_action('phpmailer_init', array($this, 'configure_phpmailer'), 999);
    
    // Filtros adicionales para asegurar que TODOS los emails usen nuestra configuración
    add_filter('wp_mail_from', array($this, 'set_mail_from'));
    add_filter('wp_mail_from_name', array($this, 'set_mail_from_name'));
    
    // Inicializar cron para leer emails IMAP - REACTIVADO URGENTEMENTE
    // Sistema de fallback si Enhanced Cron Manager falla
    add_action('init', array($this, 'init_email_cron'));
    
    // error_log('QvaClick Email Manager: Sistema inicializado correctamente con IMAP activo'); // Desactivado - funciona correctamente
    }
    
    public function add_admin_menu() {
        // Helper de capacidad (permite filtrar o personalizar en el futuro)
        if (!function_exists('qvc_user_can_manage')) {
            function qvc_user_can_manage() {
                return current_user_can('qvc_manage_emails') || current_user_can('manage_options');
            }
        }
        add_menu_page(
            __('QVC Email', 'qvaclick-email-manager'),
            $this->get_menu_title_with_notifications(),
            'qvc_manage_emails',
            'qvc-email-manager',
            array($this, 'admin_email_page'),
            'dashicons-email-alt',
            30
        );
        
        // Submenus
        // 1) QVC Email (antes "Admin Email") como primer submenu y destino por defecto
        add_submenu_page(
            'qvc-email-manager',
            __('QVC Email', 'qvaclick-email-manager'),
            __('QVC Email', 'qvaclick-email-manager'),
            'qvc_manage_emails',
            'qvc-admin-email',
            array($this, 'admin_email_page')
        );
        // Eliminar el submenu duplicado automático que apunta al slug del padre
        // para evitar dos entradas "QVC Email" en el submenu
        if (function_exists('remove_submenu_page')) {
            remove_submenu_page('qvc-email-manager', 'qvc-email-manager');
        }

        add_submenu_page(
            'qvc-email-manager',
            __('Plantilla Base', 'qvaclick-email-manager'),
            __('Plantilla Base', 'qvaclick-email-manager'),
            'qvc_manage_emails',
            'qvc-email-base-template',
            array($this, 'base_template_page')
        );
        
        add_submenu_page(
            'qvc-email-manager',
            __('Lista de Emails', 'qvaclick-email-manager'),
            __('Lista de Emails', 'qvaclick-email-manager'),
            'qvc_manage_emails',
            'qvc-email-templates',
            array($this, 'templates_list_page')
        );
        
        // Hook system pages - REORGANIZADO Y LIMPIO
        add_submenu_page(
            'qvc-email-manager',
            __('Hook Manager', 'qvaclick-email-manager'),
            __('Hook Manager', 'qvaclick-email-manager'),
            'qvc_manage_emails',
            'qvc-hook-discovery',
            array($this, 'hook_discovery_page')
        );
        
        // (movido arriba) Admin Email -> QVC Email
        
        // NEW: Configuración SMTP/IMAP
        add_submenu_page(
            'qvc-email-manager',
            __('Configuración SMTP/IMAP', 'qvaclick-email-manager'),
            __('Config. Correo', 'qvaclick-email-manager'),
            'manage_options',
            'qvc-smtp-config',
            array($this, 'smtp_config_page')
        );
        
        // Registrar página oculta del Editor de Email por Hook para soportar enlaces directos (Editar)
        // Se mantiene oculta del menú, pero accesible vía URL: admin.php?page=qvaclick-email-hooks&id=123
        add_submenu_page(
            'qvc-email-manager',
            __('Editor Email por Hook', 'qvaclick-email-manager'),
            __('Editor Email por Hook', 'qvaclick-email-manager'),
            'qvc_manage_emails',
            'qvaclick-email-hooks',
            array($this, 'email_hook_creator_page')
        );
        // Ocultar del menú visualmente (mantener registro para evitar wp_die en admin.php?page=...)
        add_action('admin_head', function() {
            echo '<style>#toplevel_page_qvc-email-manager .wp-submenu a[href$="page=qvaclick-email-hooks"]{display:none!important;}</style>';
        });
    // Nota: el creador/gestor de Emails por Hook ahora vive dentro de Hook Manager (pestaña "Emails por Hook").
        
        // Páginas futuras (Fase 3) - comentadas hasta implementación
        /*
        add_submenu_page(
            'qvc-email-manager',
            __('Email Creator', 'qvaclick-email-manager'),
            __('Email Creator', 'qvaclick-email-manager'),
            'manage_options',
            'qvc-email-creator',
            array($this, 'email_creator_page')
        );
        
        add_submenu_page(
            'qvc-email-manager',
            __('Analytics', 'qvaclick-email-manager'),
            __('Analytics', 'qvaclick-email-manager'),
            'manage_options',
            'qvc-email-analytics',
            array($this, 'email_analytics_page')
        );
        
        add_submenu_page(
            'qvc-email-manager',
            __('Email Logs', 'qvaclick-email-manager'),
            __('Email Logs', 'qvaclick-email-manager'),
            'manage_options',
            'qvc-email-logs',
            array($this, 'email_logs_page')
        );
        */
    }
    
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'qvc-email') === false) {
            return;
        }
        
        wp_enqueue_editor();
        wp_enqueue_media();
        wp_enqueue_script('jquery-ui-sortable');
        
        wp_enqueue_style(
            'qvc-email-admin',
            plugin_dir_url(__FILE__) . 'assets/css/admin.css',
            array(),
            $this->version
        );
        
        // Cargar CSS específico para Admin Email
        if (strpos($hook, 'qvc-admin-email') !== false) {
            wp_enqueue_style(
                'qvc-admin-email',
                plugin_dir_url(__FILE__) . 'assets/css/admin-email.css',
                array('qvc-email-admin'),
                $this->version
            );
        }
        
        wp_enqueue_script(
            'qvc-email-admin',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            array('jquery', 'wp-editor'),
            $this->version,
            true
        );
        
        // Cargar JS específico para Admin Email
        if (strpos($hook, 'qvc-admin-email') !== false) {
            wp_enqueue_script(
                'qvc-admin-email-js',
                plugin_dir_url(__FILE__) . 'assets/js/admin-email.js',
                array('jquery', 'qvc-email-admin'),
                $this->version,
                true
            );
            
            // Cargar script de seguridad para funciones de tickets
            wp_enqueue_script(
                'qvc-security-admin',
                plugin_dir_url(__FILE__) . 'assets/security-admin.js',
                array('jquery', 'qvc-email-admin'),
                time(), // Forzar recarga con timestamp
                true
            );
            
            // Nota: scripts de depuración/standalone estaban causando doble envío.
            // Mantener solo 'qvc-security-admin' para manejar la eliminación de tickets.
        }
        
        // Localize script with data
        wp_localize_script('qvc-email-admin', 'qvcEmailManager', array(
            'nonce' => wp_create_nonce('qvc_email_nonce'),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'siteName' => get_bloginfo('name'),
            'siteUrl' => home_url(),
            'autoSave' => true,
            'messages' => array(
                'confirmApplyAll' => __('¿Aplicar la plantilla base a TODOS los templates? Esta acción no se puede deshacer fácilmente.', 'qvaclick-email-manager'),
                'applying' => __('Aplicando...', 'qvaclick-email-manager'),
                'applyToSelected' => __('Aplicar a Seleccionados', 'qvaclick-email-manager'),
                'unknownError' => __('Error desconocido', 'qvaclick-email-manager'),
                'ajaxError' => __('Error de conexión', 'qvaclick-email-manager'),
                'emptyTemplate' => __('La plantilla está vacía', 'qvaclick-email-manager'),
                'previewError' => __('Error al generar vista previa', 'qvaclick-email-manager'),
                'searchTemplates' => __('Buscar templates...', 'qvaclick-email-manager'),
                'clear' => __('Limpiar', 'qvaclick-email-manager'),
                'noResults' => __('No se encontraron templates que coincidan con la búsqueda.', 'qvaclick-email-manager'),
                'bulkActions' => __('Acciones masivas', 'qvaclick-email-manager'),
                'enable' => __('Activar', 'qvaclick-email-manager'),
                'disable' => __('Desactivar', 'qvaclick-email-manager'),
                'applyBase' => __('Aplicar plantilla base', 'qvaclick-email-manager'),
                'apply' => __('Aplicar', 'qvaclick-email-manager'),
                'autoSaved' => __('Guardado automático', 'qvaclick-email-manager')
            )
        ));

        // Localización específica para Admin Email
        if (strpos($hook, 'qvc-admin-email') !== false) {
            wp_localize_script('qvc-admin-email-js', 'adminEmail', array(
                'nonce' => wp_create_nonce('qvc_admin_email_nonce'),
                'ajaxurl' => admin_url('admin-ajax.php'),
                'messages' => array(
                    'confirmDelete' => __('¿Estás seguro de que quieres eliminar este elemento?', 'qvaclick-email-manager'),
                    'confirmDuplicate' => __('¿Estás seguro de que quieres duplicar esta campaña?', 'qvaclick-email-manager'),
                    'emailSending' => __('Enviando email...', 'qvaclick-email-manager'),
                    'emailSent' => __('Email enviado exitosamente', 'qvaclick-email-manager'),
                    'error' => __('Error al procesar la solicitud', 'qvaclick-email-manager')
                )
            ));
            
            // Localización específica para el script de seguridad
            wp_localize_script('qvc-security-admin', 'qvc_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('qvc_admin_nonce'),
                'admin_url' => admin_url(),
                'messages' => array(
                    'confirmDelete' => __('¿Estás seguro de que quieres eliminar este ticket?', 'qvaclick-email-manager'),
                    'confirmClean' => __('¿Quieres limpiar los tickets resueltos antiguos?', 'qvaclick-email-manager'),
                    'deleting' => __('Eliminando...', 'qvaclick-email-manager'),
                    'cleaning' => __('Limpiando...', 'qvaclick-email-manager'),
                    'success' => __('Operación completada exitosamente', 'qvaclick-email-manager'),
                    'error' => __('Error al procesar la solicitud', 'qvaclick-email-manager')
                )
            ));
        }
    }
    
    public function admin_page() {
        QvaClick_Email_Admin_Interface::render_main_page();
    }
    
    public function base_template_page() {
        QvaClick_Email_Admin_Interface::render_base_template_page();
    }
    
    public function templates_list_page() {
        QvaClick_Email_Admin_Interface::render_templates_list_page();
    }
    
    /**
     * Hook Manager Page - ÚNICA PÁGINA DE HOOKS (consolidada)
     */
    public function hook_discovery_page() {
        if (!file_exists(QVC_EMAIL_MANAGER_PLUGIN_DIR . 'admin/hook-manager-advanced.php')) {
            echo '<div class="wrap"><h1>Hook Manager</h1><div class="notice notice-error"><p>Archivo hook-manager-advanced.php no encontrado.</p></div></div>';
            return;
        }
        include QVC_EMAIL_MANAGER_PLUGIN_DIR . 'admin/hook-manager-advanced.php';
        qvaclick_email_hook_manager_advanced_page();
    }
    
    /**
     * Admin Email Page - Sistema de tickets y emails masivos
     */
    public function admin_email_page() {
        if (!class_exists('QvaClick_Admin_Email_Interface')) {
            echo '<div class="wrap"><h1>Admin Email</h1><div class="notice notice-error"><p>Clase QvaClick_Admin_Email_Interface no encontrada.</p></div></div>';
            return;
        }
        QvaClick_Admin_Email_Interface::render_admin_email_page();
    }
    
    /**
     * Bandeja de Salida Page - Registro de todos los emails enviados
     */
    public function outbox_page() {
        if (!class_exists('QvaClick_Outbox_Admin_Page')) {
            echo '<div class="wrap"><h1>Bandeja de Salida</h1><div class="notice notice-error"><p>Clase QvaClick_Outbox_Admin_Page no encontrada.</p></div></div>';
            return;
        }
        QvaClick_Outbox_Admin_Page::render_page();
    }

    public function email_hook_creator_page() {
        $file = QVC_EMAIL_MANAGER_PLUGIN_DIR . 'admin/email-hook-creator.php';
        if (file_exists($file)) {
            include $file;
            qvc_email_hook_creator_page();
        } else {
            echo '<div class="wrap"><h1>Crear Email por Hook</h1><div class="notice notice-error"><p>Archivo email-hook-creator.php no encontrado.</p></div></div>';
        }
    }

    // ===== Admin Email: AJAX utilitarios para contadores =====
    public function ajax_get_unread_count() {
        check_ajax_referer('qvc_admin_email_nonce', 'nonce');
        if (!(current_user_can('qvc_manage_emails') || current_user_can('manage_options'))) {
            wp_send_json_error(['message' => __('No autorizado', 'qvaclick-email-manager')]);
        }

        global $wpdb;
        $general_table = $wpdb->prefix . 'qvc_general_inbox';
        $tickets_table = $wpdb->prefix . 'qvc_support_tickets';
        $quarantine_table = $wpdb->prefix . 'qvc_email_quarantine';

        $general_unread = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$general_table} WHERE status IN ('unread','assigned')");
        $ticket_open = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tickets_table} WHERE status IN ('new','open','in_progress','on_hold')");

        $quarantine_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $quarantine_table));
        if (!$quarantine_exists) {
            $quarantine_table = $wpdb->prefix . 'qvc_quarantine';
        }
        $quarantine_count = 0;
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $quarantine_table))) {
            $quarantine_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$quarantine_table} WHERE status = 'quarantined'");
        }

        $total = max(0, $general_unread + $ticket_open + $quarantine_count);
        wp_send_json_success(['count' => $total, 'breakdown' => compact('general_unread','ticket_open','quarantine_count')]);
    }

    public function ajax_mark_ticket_read() {
        check_ajax_referer('qvc_admin_email_nonce', 'nonce');
        if (!(current_user_can('qvc_manage_emails') || current_user_can('manage_options'))) {
            wp_send_json_error(['message' => __('No autorizado', 'qvaclick-email-manager')]);
        }

        $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
        if ($ticket_id <= 0) {
            wp_send_json_error(['message' => 'ID inválido']);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'qvc_support_tickets';
        // Marcar abierto pero actualizado (no hay columna read_at en tickets por ahora)
        $updated = $wpdb->update($table, [ 'updated_at' => current_time('mysql') ], [ 'id' => $ticket_id ], [ '%s' ], [ '%d' ]);
        if ($updated === false) {
            wp_send_json_error(['message' => 'DB error: ' . $wpdb->last_error]);
        }
        wp_send_json_success(['updated' => (int)$updated]);
    }

    // ===== Hook Emails: AJAX handlers =====
    public function ajax_hook_email_preview() {
        check_ajax_referer('qvc_email_nonce', 'nonce');
        if (!(current_user_can('qvc_manage_emails') || current_user_can('manage_options'))) {
            wp_send_json_error(['message' => __('No autorizado', 'qvaclick-email-manager')]);
        }

        global $wpdb; $table = $wpdb->prefix . 'qvc_hook_emails';
        $id = isset($_POST['hook_email_id']) ? intval($_POST['hook_email_id']) : 0;
        if ($id <= 0) { wp_send_json_error(['message' => 'ID inválido']); }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id));
        if (!$row) { wp_send_json_error(['message' => 'Email no encontrado']); }

        // Build preview body
        $body = $row->content;
        $subject = $row->subject;
        
        // Default data for preview
        $data = [];
        if (class_exists('QvaClick_Base_Template_Manager')) {
            $subject = QvaClick_Base_Template_Manager::generate_preview($subject, $data);
            
            // VERIFICAR si el email ya tiene plantilla aplicada
            if (QvaClick_Base_Template_Manager::has_base_template_applied($body)) {
                // Si ya tiene plantilla, solo reemplazar placeholders SIN aplicar plantilla nuevamente
                $body = QvaClick_Base_Template_Manager::generate_preview($body, $data);
            } else {
                // Si no tiene plantilla, aplicar según configuración
                if (!empty($row->use_base_template)) {
                    // Primero reemplazar placeholders, luego aplicar plantilla base completa
                    $body_with_placeholders = QvaClick_Base_Template_Manager::generate_preview($body, $data);
                    $body = QvaClick_Base_Template_Manager::apply_to_html($body_with_placeholders);
                } else {
                    // Solo reemplazar placeholders y formatear
                    $body = QvaClick_Base_Template_Manager::generate_preview($body, $data);
                    if (method_exists('QvaClick_Base_Template_Manager', 'format_content_html')) {
                        $body = QvaClick_Base_Template_Manager::format_content_html($body);
                    }
                }
            }
        }

        $preview = '<div class="qvc-email-preview">';
        $preview .= '<h3>Asunto: ' . esc_html($subject) . '</h3>';
        $preview .= '<div class="qvc-email-body">' . $body . '</div>';
        $preview .= '</div>';
        wp_send_json_success(['preview' => $preview]);
    }

    public function ajax_hook_email_send_test() {
        check_ajax_referer('qvc_email_nonce', 'nonce');
        if (!(current_user_can('qvc_manage_emails') || current_user_can('manage_options'))) {
            wp_send_json_error(['message' => __('No autorizado', 'qvaclick-email-manager')]);
        }

        global $wpdb; $table = $wpdb->prefix . 'qvc_hook_emails';
        $id = isset($_POST['hook_email_id']) ? intval($_POST['hook_email_id']) : 0;
        $test_email = isset($_POST['test_email']) ? sanitize_email($_POST['test_email']) : '';
        if ($id <= 0) { wp_send_json_error(['message' => 'ID inválido']); }
        if (empty($test_email) || !is_email($test_email)) { wp_send_json_error(['message' => __('Email inválido.', 'qvaclick-email-manager')]); }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id));
        if (!$row) { wp_send_json_error(['message' => 'Email no encontrado']); }

        $user = get_user_by('email', $test_email);
        $data = array(
            'site_name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'home_url'  => home_url(),
            // URLs funcionales para evitar about:blank#blocked
            'login_url' => wp_login_url(),
            'dashboard_link' => home_url('/dashboard'),
            'project_link' => home_url('/dashboard/projects'),
            'profile_link' => home_url('/perfil'),
            'service_link' => home_url('/dashboard/services'),
            'order_link' => home_url('/dashboard/orders'),
            'site_url' => admin_url(),
            'verification_link' => home_url('/verify-account'),
            // Datos adicionales de ejemplo
            'project_title' => 'Desarrollo Web de Ejemplo',
            'service_title' => 'Servicio de Prueba',
            'service_cost' => '$100',
            'service_delivery' => '3 días',
            'service_description' => 'Descripción del servicio de ejemplo'
        );
        if ($user) {
            $data['display_name'] = $user->display_name ?: $user->user_login;
            $data['email'] = $user->user_email;
            $data['user_login'] = $user->user_login;
        } else {
            $data['display_name'] = __('Usuario Invitado', 'qvaclick-email-manager');
            $data['email'] = $test_email;
            $data['user_login'] = __('invitado', 'qvaclick-email-manager');
        }

        $subject = QvaClick_Base_Template_Manager::generate_preview($row->subject, $data);
        $body    = QvaClick_Base_Template_Manager::generate_preview($row->content, $data);
        if (method_exists('QvaClick_Base_Template_Manager', 'format_content_html')) {
            $body = QvaClick_Base_Template_Manager::format_content_html($body);
        }
        if (!empty($row->use_base_template)) {
            $base = QvaClick_Base_Template_Manager::get_base_template();
            $wrapped = str_replace('{{CONTENT}}', $body, $base);
            if (strpos($wrapped, $body) === false) { $wrapped .= "\n" . $body; }
            $body = $wrapped;
        }

        // Configurar headers con Reply-To correcto
        $admin_email_manager = QvaClick_Admin_Email_Manager::get_instance();
        $from_email = $admin_email_manager->get_support_from_email();
        $reply_to_email = $admin_email_manager->get_support_reply_to_email();
        $support_name = $admin_email_manager->get_support_from_name();
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $support_name . ' <' . $from_email . '>',
            'Reply-To: ' . $reply_to_email
        );
        
        $sent = wp_mail($test_email, $subject, $body, $headers);
        if ($sent) { wp_send_json_success(['message' => sprintf(__('Email de prueba enviado a %s', 'qvaclick-email-manager'), esc_html($test_email))]); }
        wp_send_json_error(['message' => __('No se pudo enviar el email de prueba.', 'qvaclick-email-manager')]);
    }

    public function ajax_hook_email_apply_base() {
        check_ajax_referer('qvc_email_nonce', 'nonce');
        if (!(current_user_can('qvc_manage_emails') || current_user_can('manage_options'))) {
            wp_send_json_error(['message' => __('No autorizado', 'qvaclick-email-manager')]);
        }
        global $wpdb; $table = $wpdb->prefix . 'qvc_hook_emails';
        $id = isset($_POST['hook_email_id']) ? intval($_POST['hook_email_id']) : 0;
        if ($id <= 0) { wp_send_json_error(['message' => 'ID inválido']); }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id));
        if (!$row) { wp_send_json_error(['message' => 'Email no encontrado']); }

        // Aplicar plantilla base al contenido y persistir
        if (class_exists('QvaClick_Base_Template_Manager')) {
            // VERIFICAR si el contenido ya tiene plantilla aplicada
            if (QvaClick_Base_Template_Manager::has_base_template_applied($row->content)) {
                wp_send_json_error(['message' => 'El email ya tiene la plantilla base aplicada.']);
                return;
            }
            
            if (method_exists('QvaClick_Base_Template_Manager', 'apply_to_html')) {
                $new_body = QvaClick_Base_Template_Manager::apply_to_html($row->content);
            } else {
                $base = QvaClick_Base_Template_Manager::get_base_template();
                $clean = QvaClick_Base_Template_Manager::generate_preview($row->content); // simple fallback
                $new_body = str_replace('{{CONTENT}}', $clean, $base);
                if (strpos($new_body, $clean) === false) { $new_body .= "\n" . $clean; }
            }
            $wpdb->update($table, [
                'content' => $new_body,
                'use_base_template' => 0,
            ], ['id' => $id]);
            if ($wpdb->last_error) { wp_send_json_error(['message' => $wpdb->last_error]); }
            wp_send_json_success(['message' => __('Plantilla base aplicada al email.', 'qvaclick-email-manager')]);
        }
        wp_send_json_error(['message' => 'No disponible']);
    }
    
    public function ajax_apply_base_template() {
        check_ajax_referer('qvc_email_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'qvaclick-email-manager'));
        }
        
        $base_template = wp_unslash($_POST['base_template']);
        $apply_to = $_POST['apply_to']; // 'all' or array of template keys
        
        $result = QvaClick_Base_Template_Manager::apply_to_templates($base_template, $apply_to);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    public function ajax_preview_template() {
        check_ajax_referer('qvc_email_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'qvaclick-email-manager'));
        }
        
        $template_content = wp_unslash($_POST['template_content']);
        $preview_data = $_POST['preview_data'];
        
        // VERIFICAR si el contenido ya tiene plantilla aplicada
        if (class_exists('QvaClick_Base_Template_Manager') && QvaClick_Base_Template_Manager::has_base_template_applied($template_content)) {
            // Si ya tiene plantilla, solo reemplazar placeholders SIN aplicar plantilla nuevamente
            $preview_html = QvaClick_Base_Template_Manager::generate_preview($template_content, $preview_data);
        } else {
            // Si no tiene plantilla, aplicar plantilla base completa
            // Primero reemplazar placeholders con datos de muestra
            $content_with_placeholders = QvaClick_Base_Template_Manager::generate_preview($template_content, $preview_data);
            
            // Luego aplicar la plantilla base completa con estilos
            $preview_html = QvaClick_Base_Template_Manager::apply_to_html($content_with_placeholders);
        }
        
        wp_send_json_success(array('preview' => $preview_html));
    }
    
    public function ajax_preview_individual_template() {
        check_ajax_referer('qvc_email_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Sin permisos suficientes.', 'qvaclick-email-manager'));
        }
        
        $template_key = sanitize_text_field($_POST['template_key']);
        $template_subject = sanitize_text_field($_POST['template_subject']);
        $template_body = wp_unslash($_POST['template_body']);
        
        // VERIFICAR si el contenido ya tiene plantilla aplicada
        if (class_exists('QvaClick_Base_Template_Manager') && QvaClick_Base_Template_Manager::has_base_template_applied($template_body)) {
            // Si ya tiene plantilla, solo reemplazar placeholders SIN aplicar plantilla nuevamente
            $preview_body = QvaClick_Base_Template_Manager::generate_preview($template_body);
        } else {
            // Si no tiene plantilla, aplicar plantilla base completa
            // Primero reemplazar placeholders con datos de muestra
            $content_with_placeholders = QvaClick_Base_Template_Manager::generate_preview($template_body);
            
            // Luego aplicar la plantilla base completa con estilos
            $preview_body = QvaClick_Base_Template_Manager::apply_to_html($content_with_placeholders);
        }
        
        $preview = '<div class="qvc-email-preview">';
        $preview .= '<h3>Asunto: ' . esc_html($template_subject) . '</h3>';
        $preview .= '<div class="qvc-email-body">' . $preview_body . '</div>';
        $preview .= '</div>';
        
        wp_send_json_success(array('preview' => $preview));
    }
    
    public function ajax_preview_specific_template() {
        check_ajax_referer('qvc_email_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Sin permisos suficientes.', 'qvaclick-email-manager'));
        }
        
        $template_key = sanitize_text_field($_POST['template_key']);
        $templates = QvaClick_Email_Discovery::discover_email_templates();
        
        if (!isset($templates[$template_key])) {
            wp_send_json_error(array('message' => __('Template no encontrado.', 'qvaclick-email-manager')));
        }
        
        $template = $templates[$template_key];
        
        // VERIFICAR si el contenido ya tiene plantilla aplicada
        if (class_exists('QvaClick_Base_Template_Manager') && QvaClick_Base_Template_Manager::has_base_template_applied($template['body'])) {
            // Si ya tiene plantilla, solo reemplazar placeholders SIN aplicar plantilla nuevamente
            $preview_body = QvaClick_Base_Template_Manager::generate_preview($template['body']);
        } else {
            // Si no tiene plantilla, aplicar plantilla base completa
            // Primero reemplazar placeholders con datos de muestra
            $content_with_placeholders = QvaClick_Base_Template_Manager::generate_preview($template['body']);
            
            // Luego aplicar la plantilla base completa con estilos
            $preview_body = QvaClick_Base_Template_Manager::apply_to_html($content_with_placeholders);
        }
        
        $preview = '<div class="qvc-email-preview">';
        $preview .= '<h3>Asunto: ' . esc_html($template['subject']) . '</h3>';
        $preview .= '<div class="qvc-email-body">' . $preview_body . '</div>';
        $preview .= '</div>';
        
        wp_send_json_success(array('preview' => $preview));
    }

    /**
     * Send a test email for a given template to a chosen email address
     */
    public function ajax_send_test_email() {
        check_ajax_referer('qvc_email_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'qvaclick-email-manager'));
        }

        $template_key = isset($_POST['template_key']) ? sanitize_text_field($_POST['template_key']) : '';
        $test_email   = isset($_POST['test_email']) ? sanitize_email($_POST['test_email']) : '';

        if (empty($template_key)) {
            wp_send_json_error(array('message' => __('Template no especificado.', 'qvaclick-email-manager')));
        }
        if (empty($test_email) || !is_email($test_email)) {
            wp_send_json_error(array('message' => __('Email inválido.', 'qvaclick-email-manager')));
        }

        // Get template
        $templates = QvaClick_Email_Discovery::discover_email_templates();
        if (!isset($templates[$template_key])) {
            wp_send_json_error(array('message' => __('Template no encontrado.', 'qvaclick-email-manager')));
        }
        $template = $templates[$template_key];

        // Build replacement data
        $user = get_user_by('email', $test_email);
        $data = array(
            'site_name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'home_url' => home_url(),
            // URLs funcionales para evitar about:blank#blocked
            'login_url' => wp_login_url(),
            'dashboard_link' => home_url('/dashboard'),
            'project_link' => home_url('/dashboard/projects'),
            'profile_link' => home_url('/perfil'),
            'service_link' => home_url('/dashboard/services'),
            'order_link' => home_url('/dashboard/orders'),
            'site_url' => admin_url(),
            'verification_link' => home_url('/verify-account'),
            // Datos adicionales de ejemplo
            'project_title' => 'Desarrollo Web de Ejemplo',
            'service_title' => 'Servicio de Prueba',
            'service_cost' => '$100',
            'service_delivery' => '3 días',
            'service_description' => 'Descripción del servicio de ejemplo'
        );
        if ($user) {
            $data['display_name'] = $user->display_name ?: $user->user_login;
            $data['email'] = $user->user_email;
            $data['user_login'] = $user->user_login;
        } else {
            // Fallbacks for non-registered emails
            $data['display_name'] = __('Usuario Invitado', 'qvaclick-email-manager');
            $data['email'] = $test_email;
            $data['user_login'] = __('invitado', 'qvaclick-email-manager');
        }

        // Resolve subject and body placeholders (supports %key% and {key})
        $subject = isset($template['subject']) ? $template['subject'] : '';
        $body    = isset($template['body']) ? $template['body'] : '';

        if (class_exists('QvaClick_Base_Template_Manager')) {
            $subject = QvaClick_Base_Template_Manager::generate_preview($subject, $data);
            $body    = QvaClick_Base_Template_Manager::generate_preview($body, $data);
        } else {
            // Minimal fallback replacement
            foreach ($data as $k => $v) {
                $subject = str_replace(array('%'.$k.'%','{'.$k.'}'), $v, $subject);
                $body    = str_replace(array('%'.$k.'%','{'.$k.'}'), $v, $body);
            }
        }

        // Compose headers with Reply-To correcto
        $admin_email_manager = QvaClick_Admin_Email_Manager::get_instance();
        $from_email = $admin_email_manager->get_support_from_email();
        $reply_to_email = $admin_email_manager->get_support_reply_to_email();
        $support_name = $admin_email_manager->get_support_from_name();
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $support_name . ' <' . $from_email . '>',
            'Reply-To: ' . $reply_to_email
        );

        // Send
        $sent = wp_mail($test_email, $subject, $body, $headers);
        if ($sent) {
            wp_send_json_success(array('message' => sprintf(__('Email de prueba enviado a %s', 'qvaclick-email-manager'), esc_html($test_email))))
            ;
        }
        wp_send_json_error(array('message' => __('No se pudo enviar el email de prueba.', 'qvaclick-email-manager')));
    }

    /**
     * Export all discovered email templates (admin AJAX)
     */
    public function ajax_export_templates() {
        check_ajax_referer('qvc_email_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'qvaclick-email-manager'));
        }
        $templates = QvaClick_Email_Discovery::discover_email_templates();
        // Map / reduce to essential export fields
        $export = array();
        foreach ($templates as $base_key => $t) {
            $export[] = array(
                'base_key' => $base_key,
                'name' => $t['name'],
                'enabled' => $t['enabled'],
                'subject' => $t['subject'],
                'body' => $t['body'],
                'placeholders' => $t['placeholders'],
                'last_modified' => $t['last_modified']
            );
        }
        wp_send_json_success($export);
    }

    /**
     * Importa templates desde un JSON y sobrescribe subject/body/habilitado.
     * Implementación temporal: no maneja backups individuales (el backup general existe al aplicar base).
     */
    public function ajax_import_templates() {
        check_ajax_referer('qvc_email_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'qvaclick-email-manager'));
        }
        if (empty($_POST['json'])) {
            wp_send_json_error(array('message' => __('No se recibió JSON.', 'qvaclick-email-manager')));
        }
        
        $raw = wp_unslash($_POST['json']);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            wp_send_json_error(array('message' => __('JSON inválido.', 'qvaclick-email-manager')));
        }

        // Realizar importación con limpieza y sincronización completa
        $result = $this->import_templates_with_cleanup($data);
        
        wp_send_json_success($result);
    }

    /**
     * Importación mejorada con limpieza previa y sincronización completa
     */
    private function import_templates_with_cleanup($import_data) {
        global $wpdb;
        
        $stats = array(
            'imported' => 0,
            'updated' => 0,
            'cleaned' => 0,
            'skipped' => 0,
            'errors' => array()
        );

        try {
            // 1. RESPALDO: Crear backup antes de limpiar
            $backup_file = $this->create_automatic_backup();
            if ($backup_file) {
                error_log("QvaClick Import: Backup creado automáticamente: $backup_file");
            }

            // 2. IDENTIFICAR TEMPLATES A IMPORTAR
            $templates_to_import = array();
            foreach ($import_data as $item) {
                if (!is_array($item) || empty($item['base_key'])) {
                    $stats['skipped']++;
                    continue;
                }
                
                $base_key = sanitize_text_field($item['base_key']);
                if (empty($item['body']) && empty($item['subject'])) {
                    $stats['skipped']++;
                    continue; // Skip empty templates
                }
                
                $templates_to_import[$base_key] = $item;
            }

            // 3. LIMPIAR DATOS PREVIOS DE LOS TEMPLATES QUE SE VAN A IMPORTAR
            $this->clean_existing_email_data($templates_to_import);
            $stats['cleaned'] = count($templates_to_import);

            // 4. IMPORTAR USANDO REDUX SYNC MANAGER (fuente única de verdad)
            foreach ($templates_to_import as $base_key => $item) {
                $is_new = true;
                
                try {
                    // Verificar si ya existe en Redux
                    $existing_body = QvaClick_Redux_Sync_Manager::get_email_body($base_key);
                    $existing_subject = QvaClick_Redux_Sync_Manager::get_email_subject($base_key);
                    
                    if ($existing_body || $existing_subject) {
                        $is_new = false;
                    }

                    // Importar body
                    if (!empty($item['body'])) {
                        $clean_body = wp_kses_post($item['body']);
                        if (QvaClick_Redux_Sync_Manager::save_email_body($base_key, $clean_body)) {
                            error_log("QvaClick Import: Body guardado para $base_key");
                        } else {
                            $stats['errors'][] = "No se pudo guardar body para: $base_key";
                        }
                    }

                    // Importar subject
                    if (!empty($item['subject'])) {
                        $clean_subject = sanitize_text_field($item['subject']);
                        if (QvaClick_Redux_Sync_Manager::save_email_subject($base_key, $clean_subject)) {
                            error_log("QvaClick Import: Subject guardado para $base_key");
                        } else {
                            $stats['errors'][] = "No se pudo guardar subject para: $base_key";
                        }
                    }

                    // Importar enabled status
                    if (isset($item['enabled'])) {
                        $enabled = (bool) $item['enabled'];
                        if (QvaClick_Redux_Sync_Manager::save_email_enabled($base_key, $enabled)) {
                            error_log("QvaClick Import: Enabled status guardado para $base_key");
                        }
                    }

                    // Contar estadísticas
                    if ($is_new) {
                        $stats['imported']++;
                    } else {
                        $stats['updated']++;
                    }

                } catch (Exception $e) {
                    $stats['errors'][] = "Error procesando $base_key: " . $e->getMessage();
                    error_log("QvaClick Import Error: " . $e->getMessage());
                }
            }

            // 5. FORZAR ACTUALIZACIÓN DE CACHE DE REDUX
            delete_transient('redux_exertio_theme_options');
            wp_cache_delete('exertio_theme_options', 'options');

            // 6. VERIFICAR SINCRONIZACIÓN
            $stats['sync_status'] = $this->verify_import_sync($templates_to_import);

            error_log("QvaClick Import Completed: " . print_r($stats, true));

        } catch (Exception $e) {
            $stats['errors'][] = "Error crítico durante importación: " . $e->getMessage();
            error_log("QvaClick Import Critical Error: " . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Limpiar datos previos de los templates que se van a importar
     */
    private function clean_existing_email_data($templates_to_import) {
        $redux_options = get_option('exertio_theme_options', array());
        $keys_to_clean = array();

        foreach (array_keys($templates_to_import) as $base_key) {
            // Identificar todas las claves relacionadas con este base_key
            foreach ($redux_options as $key => $value) {
                if (strpos($key, $base_key) === 0) {
                    // Esta clave pertenece al template que vamos a importar
                    if (preg_match('/^' . preg_quote($base_key, '/') . '_?(subject|subj|sub|body|message|template|content|switch|enabled|status)$/i', $key)) {
                        $keys_to_clean[] = $key;
                    }
                }
            }
        }

        // Limpiar las claves identificadas
        foreach ($keys_to_clean as $key) {
            unset($redux_options[$key]);
        }

        // Guardar opciones limpias
        update_option('exertio_theme_options', $redux_options);
        
        error_log("QvaClick Import: Limpiadas " . count($keys_to_clean) . " claves previas: " . implode(', ', $keys_to_clean));
    }

    /**
     * Crear backup automático antes de importar
     */
    private function create_automatic_backup() {
        try {
            $timestamp = date('Y-m-d_H-i-s');
            $backup_data = QvaClick_Email_Discovery::discover_email_templates();
            
            $backup_dir = QVC_EMAIL_MANAGER_PLUGIN_DIR . 'backups/';
            if (!is_dir($backup_dir)) {
                wp_mkdir_p($backup_dir);
            }
            
            $backup_file = $backup_dir . "auto_backup_before_import_$timestamp.json";
            $json_data = json_encode($backup_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            
            if (file_put_contents($backup_file, $json_data)) {
                return $backup_file;
            }
        } catch (Exception $e) {
            error_log("QvaClick Backup Error: " . $e->getMessage());
        }
        
        return false;
    }

    /**
     * Verificar que la importación y sincronización fueron exitosas
     */
    private function verify_import_sync($imported_templates) {
        $verification = array(
            'redux_accessible' => true,
            'exertio_accessible' => true,
            'templates_synced' => 0,
            'templates_missing' => array()
        );

        try {
            // Verificar que Redux tiene los datos
            $redux_options = get_option('exertio_theme_options', array());
            if (empty($redux_options)) {
                $verification['redux_accessible'] = false;
                return $verification;
            }

            // Verificar que Exertio puede leer los datos (simular fl_framework_get_options)
            if (function_exists('fl_framework_get_options')) {
                $exertio_data = fl_framework_get_options();
                if (empty($exertio_data)) {
                    $verification['exertio_accessible'] = false;
                }
            }

            // Verificar cada template importado
            foreach (array_keys($imported_templates) as $base_key) {
                $redux_body = QvaClick_Redux_Sync_Manager::get_email_body($base_key);
                $redux_subject = QvaClick_Redux_Sync_Manager::get_email_subject($base_key);
                
                if ($redux_body || $redux_subject) {
                    $verification['templates_synced']++;
                } else {
                    $verification['templates_missing'][] = $base_key;
                }
            }

        } catch (Exception $e) {
            $verification['error'] = $e->getMessage();
        }

        return $verification;
    }
    
    // ===== Admin Email AJAX Handlers =====
    
    /**
     * Cargar preview de destinatarios para email masivo
     */
    public function ajax_load_recipient_preview() {
    // Composer inline script uses wp_create_nonce('qvc_email_nonce') so accept that here
    check_ajax_referer('qvc_email_nonce', 'nonce');
        
        if (!current_user_can('qvc_manage_emails') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('No autorizado', 'qvaclick-email-manager')]);
        }
        
        $type = sanitize_text_field($_POST['recipient_type']);
        $filter = sanitize_text_field($_POST['recipient_filter']);
        
        $admin_email = QvaClick_Admin_Email_Manager::get_instance();
        $recipients = $admin_email->get_mass_email_recipients($type, $filter);
        
        $preview = sprintf(__('Se enviarán emails a %d destinatarios:', 'qvaclick-email-manager'), count($recipients));
        $preview .= '<ul style="max-height: 150px; overflow-y: auto; margin-top: 10px;">';
        
        $count = 0;
        foreach ($recipients as $recipient) {
            if ($count >= 10) {
                $remaining = count($recipients) - $count;
                $preview .= '<li><em>' . sprintf(__('... y %d más', 'qvaclick-email-manager'), $remaining) . '</em></li>';
                break;
            }
            $preview .= '<li>' . esc_html($recipient['name']) . ' (' . esc_html($recipient['email']) . ')</li>';
            $count++;
        }
        $preview .= '</ul>';
        
        wp_send_json_success(['preview' => $preview]);
    }
    
    /**
     * Crear ticket de soporte (para futuras integraciones frontend)
     */
    public function ajax_create_support_ticket() {
        check_ajax_referer('qvc_email_nonce', 'nonce');
        
        $data = array(
            'user_id' => get_current_user_id(),
            'user_email' => sanitize_email($_POST['user_email']),
            'user_name' => sanitize_text_field($_POST['user_name']),
            'subject' => sanitize_text_field($_POST['subject']),
            'message' => sanitize_textarea_field($_POST['message']),
            'category' => sanitize_text_field($_POST['category']),
            'priority' => sanitize_text_field($_POST['priority'])
        );
        
        $admin_email = QvaClick_Admin_Email_Manager::get_instance();
        $ticket_id = $admin_email->create_support_ticket($data);
        
        if ($ticket_id) {
            wp_send_json_success([
                'message' => __('Ticket creado correctamente', 'qvaclick-email-manager'),
                'ticket_id' => $ticket_id
            ]);
        } else {
            wp_send_json_error(['message' => __('Error al crear el ticket', 'qvaclick-email-manager')]);
        }
    }
    
    /**
     * Enviar campaña de email masivo
     */
    public function ajax_send_mass_email_campaign() {
        check_ajax_referer('qvc_email_nonce', 'nonce');
        
        if (!current_user_can('qvc_manage_emails') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('No autorizado', 'qvaclick-email-manager')]);
        }
        
        $campaign_id = intval($_POST['campaign_id']);
        
        $admin_email = QvaClick_Admin_Email_Manager::get_instance();
        $result = $admin_email->send_mass_email($campaign_id);
        
        if ($result) {
            wp_send_json_success([
                'message' => sprintf(__('Campaña enviada. %d emails enviados, %d fallaron.', 'qvaclick-email-manager'), 
                                   $result['sent'], $result['failed']),
                'sent' => $result['sent'],
                'failed' => $result['failed'],
                'total' => $result['total']
            ]);
        } else {
            wp_send_json_error(['message' => __('Error al enviar la campaña', 'qvaclick-email-manager')]);
        }
    }

    /**
     * AJAX: Buscar usuarios para selección específica
     */
    public function ajax_search_users() {
        check_ajax_referer('qvc_admin_email_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No tienes permisos para realizar esta acción');
        }

        $search_term = sanitize_text_field($_POST['search_term'] ?? '');
        
        if (strlen($search_term) < 2) {
            wp_send_json_error('El término de búsqueda debe tener al menos 2 caracteres');
        }

        if ($this->admin_email_manager) {
            $users = $this->admin_email_manager->search_users($search_term);
            wp_send_json_success(['users' => $users]);
        } else {
            wp_send_json_error('Admin Email Manager no está inicializado');
        }
    }

    /**
     * AJAX: Ver detalles de campaña
     */
    public function ajax_view_campaign() {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para realizar esta acción');
        }

        $campaign_id = intval($_GET['id'] ?? 0);
        
        if (!$campaign_id) {
            wp_die('ID de campaña inválido');
        }

        if ($this->admin_email_manager) {
            $campaign = $this->admin_email_manager->get_campaign_details($campaign_id);
            
            if (!$campaign) {
                wp_die('Campaña no encontrada');
            }

            // Mostrar detalles de la campaña
            echo '<h1>Detalles de Campaña: ' . esc_html($campaign['subject']) . '</h1>';
            echo '<p><strong>Fecha de envío:</strong> ' . esc_html($campaign['sent_at']) . '</p>';
            echo '<p><strong>Destinatarios:</strong> ' . esc_html($campaign['recipient_count']) . '</p>';
            echo '<p><strong>Estado:</strong> ' . esc_html($campaign['status']) . '</p>';
            echo '<h3>Contenido:</h3>';
            echo '<div>' . wp_kses_post($campaign['content']) . '</div>';
        } else {
            wp_die('Admin Email Manager no está inicializado');
        }
    }

    /**
     * AJAX: Duplicar campaña
     */
    public function ajax_duplicate_campaign() {
        check_ajax_referer('qvc_admin_email_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No tienes permisos para realizar esta acción');
        }

        $campaign_id = intval($_POST['id'] ?? 0);
        
        if (!$campaign_id) {
            wp_send_json_error('ID de campaña inválido');
        }

        if ($this->admin_email_manager) {
            $result = $this->admin_email_manager->duplicate_campaign($campaign_id);
            
            if ($result) {
                wp_send_json_success('Campaña duplicada exitosamente');
            } else {
                wp_send_json_error('Error al duplicar la campaña');
            }
        } else {
            wp_send_json_error('Admin Email Manager no está inicializado');
        }
    }

    /**
     * AJAX: Eliminar campaña
     */
    public function ajax_delete_campaign() {
        check_ajax_referer('qvc_admin_email_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No tienes permisos para realizar esta acción');
        }

        $campaign_id = intval($_POST['id'] ?? 0);
        
        if (!$campaign_id) {
            wp_send_json_error('ID de campaña inválido');
        }

        if ($this->admin_email_manager) {
            $result = $this->admin_email_manager->delete_campaign($campaign_id);
            
            if ($result) {
                wp_send_json_success('Campaña eliminada exitosamente');
            } else {
                wp_send_json_error('Error al eliminar la campaña');
            }
        } else {
            wp_send_json_error('Admin Email Manager no está inicializado');
        }
    }

    /**
     * AJAX: Obtener lista de campañas actualizada
     */
    public function ajax_get_campaigns() {
        check_ajax_referer('qvc_admin_email_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No tienes permisos para realizar esta acción');
        }

        if ($this->admin_email_manager && $this->admin_email_interface) {
            $campaigns = $this->admin_email_manager->get_campaigns();
            $html = $this->admin_email_interface->generate_campaigns_table_rows($campaigns);
            wp_send_json_success(['html' => $html]);
        } else {
            wp_send_json_error('Admin Email Manager no está inicializado');
        }
    }
    
    /**
     * Inicializar hooks para formularios de contacto
     */
    public function init_contact_form_hooks() {
        // Usar transient para evitar inicialización múltiple frecuente
        $last_init = get_transient('qvc_hooks_last_init');
        $current_time = time();
        
        // Solo hacer log detallado cada 60 segundos
        $should_log = false;
        if (!$last_init || ($current_time - $last_init) > 60) {
            $should_log = true;
            set_transient('qvc_hooks_last_init', $current_time, 120); // Cache por 2 minutos
        }
        
        // REACTIVADO - Hook principal para interceptar respuestas (ahora protegido)
        if (!has_filter('wp_mail', array($this, 'intercept_email_replies'))) {
            add_filter('wp_mail', array($this, 'intercept_email_replies'), 999, 1);
            if ($should_log) {
                error_log('QvaClick Debug: Email interception REACTIVATED with security protections');
            }
        }
        
        // Solo si Contact Form 7 está activo
        if (function_exists('wpcf7_get_current_contact_form')) {
            // REACTIVADO - Hook ANTES de enviar el email (ahora con protecciones)
            if (!has_action('wpcf7_before_send_mail', array($this, 'capture_cf7_submission'))) {
                add_action('wpcf7_before_send_mail', array($this, 'capture_cf7_submission'));
                if ($should_log) {
                    error_log('QvaClick Debug: Contact Form 7 before_send_mail hook registered - ESTE CREA TICKETS');
                }
            }
            
            // Hook DESPUÉS de enviar el email para confirmar
            if (!has_action('wpcf7_mail_sent', array($this, 'log_cf7_sent'))) {
                add_action('wpcf7_mail_sent', array($this, 'log_cf7_sent'));
                if ($should_log) {
                    error_log('QvaClick Debug: Contact Form 7 mail_sent hook registered - ESTE SOLO CONFIRMA');
                }
            }
        } else {
            if ($should_log) {
                error_log('QvaClick Debug: Contact Form 7 not available during init');
            }
        }
        
        // Log de configuración actual (solo cuando should_log es true)
        if ($should_log) {
            $admin_email_manager = QvaClick_Admin_Email_Manager::get_instance();
            $current_support_email = $admin_email_manager->get_support_from_email();
            
            // También mostrar configuración SMTP para comparar
            $smtp_config = get_option('qvc_smtp_config', array());
            $smtp_from_email = isset($smtp_config['from_email']) ? $smtp_config['from_email'] : 'NOT_CONFIGURED';
            
            // error_log('QvaClick Debug: Support email from admin: ' . $current_support_email . ' | SMTP from email: ' . $smtp_from_email); // Desactivado - funciona correctamente
        }
    }
    
    /**
     * Inicialización tardía de hooks para asegurar que CF7 esté cargado
     */
    public function late_init_hooks() {
        // Verificar nuevamente si CF7 está disponible
        if (function_exists('wpcf7_get_current_contact_form')) {
            // error_log('QvaClick Debug: Contact Form 7 available on wp_loaded'); // Desactivado para reducir logs
            
            // Re-registrar hooks por si acaso
            if (!has_action('wpcf7_before_send_mail', array($this, 'capture_cf7_submission'))) {
                add_action('wpcf7_before_send_mail', array($this, 'capture_cf7_submission'));
                // error_log('QvaClick Debug: Re-registered wpcf7_before_send_mail hook'); // Desactivado para reducir logs
            }
            
            // Re-registrar hook de confirmación de envío
            if (!has_action('wpcf7_mail_sent', array($this, 'log_cf7_sent'))) {
                add_action('wpcf7_mail_sent', array($this, 'log_cf7_sent'));
                // error_log('QvaClick Debug: Re-registered wpcf7_mail_sent hook'); // Desactivado para reducir logs
            }
        } else {
            // error_log('QvaClick Debug: Contact Form 7 still not available on wp_loaded'); // Desactivado para reducir logs
        }
    }
    
    /**
     * Capturar envío de Contact Form 7
     */
    public function capture_cf7_submission($contact_form) {
        $submission = WPCF7_Submission::get_instance();
        
        if (!$submission) {
            // error_log('QvaClick Debug: No submission instance found'); // Desactivado para reducir logs
            return;
        }
        
        // SEGURIDAD CRÍTICA: Verificar bucle de email
        if ($this->is_in_email_loop('cf7_submission', 'Contact Form 7 Submission')) {
            error_log('QvaClick: BUCLE DE EMAIL DETECTADO en CF7, deteniendo procesamiento');
            return;
        }
        
        $posted_data = $submission->get_posted_data();
        
        // Log para debug
        // error_log('QvaClick Debug: Contact Form 7 submission captured: ' . print_r($posted_data, true)); // Desactivado - genera mucho spam
        
        // SEGURIDAD ADICIONAL: Verificar si el mismo usuario está enviando formularios muy rápido
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $rate_limit_key = 'qvc_form_submission_' . md5($user_ip);
        $recent_submissions = get_transient($rate_limit_key);
        
        if ($recent_submissions && $recent_submissions >= 3) {
            error_log('QvaClick: RATE LIMIT - Usuario con IP ' . $user_ip . ' ha enviado demasiados formularios');
            return;
        }
        
        // Incrementar contador de envíos para esta IP
        $recent_submissions = $recent_submissions ? ($recent_submissions + 1) : 1;
        set_transient($rate_limit_key, $recent_submissions, 300); // 5 minutos
        
        // SAFETY CHECK: Verificar que no es un email automático o de confirmación
        if ($this->is_automated_email_submission($posted_data)) {
            error_log('QvaClick Debug: Automated email detected, skipping ticket creation');
            return;
        }
        
        // Extraer datos del formulario - Buscar campos comunes
        $email = $this->extract_email_from_form($posted_data);
        $name = $this->extract_name_from_form($posted_data);
        $subject = $this->extract_subject_from_form($posted_data);
        $message = $this->extract_message_from_form($posted_data);
        
        error_log("QvaClick Debug: Extracted - Email: $email, Name: $name, Subject: $subject");
        
        // SAFETY CHECK: No procesar emails de nuestro propio sistema
        $admin_email_manager = QvaClick_Admin_Email_Manager::get_instance();
        $support_email = $admin_email_manager->get_support_from_email();
        
        if ($email === $support_email || strpos($email, '@qvaclick.com') !== false) {
            error_log('QvaClick Debug: System email detected, skipping ticket creation');
            return;
        }
        
        // SAFETY CHECK: No procesar si el asunto parece ser una confirmación
        if ($this->is_confirmation_subject($subject)) {
            error_log('QvaClick Debug: Confirmation subject detected, skipping ticket creation');
            return;
        }
        
        // Si tenemos email y mensaje, crear ticket
        if (!empty($email) && !empty($message)) {
            
            // Verificar si ya existe un ticket reciente con los mismos datos
            if ($this->is_duplicate_ticket($email, $subject, $message)) {
                error_log('QvaClick Debug: Duplicate ticket detected, skipping creation');
                return;
            }
            
            $admin_email = QvaClick_Admin_Email_Manager::get_instance();
            if ($admin_email) {
                $ticket_data = array(
                    'user_id' => 0,
                    'user_email' => $email,
                    'user_name' => $name,
                    'subject' => $subject,
                    'message' => $message,
                    'category' => 'contact_form',
                    'priority' => 'normal'
                );
                
                $result = $admin_email->create_support_ticket($ticket_data);
                error_log('QvaClick Debug: Support ticket created: ' . ($result ? 'SUCCESS with ID: ' . $result : 'FAILED'));
                
                // Enviar email de confirmación automático
                if ($result) {
                    $this->send_auto_confirmation_email($result, $email, $name, $subject);
                }
            } else {
                error_log('QvaClick Debug: Admin Email Manager not available');
            }
        } else {
            error_log('QvaClick Debug: Missing required fields - Email: ' . ($email ? 'OK' : 'MISSING') . ', Message: ' . ($message ? 'OK' : 'MISSING'));
        }
    }
    
    /**
     * Log cuando Contact Form 7 envía exitosamente
     */
    public function log_cf7_sent($contact_form) {
        error_log('QvaClick Debug: Contact Form 7 email sent successfully');
    }
    
    /**
     * Interceptar TODOS los emails para debug
     */
    public function intercept_all_emails($atts) {
        // Log para debug - ver todos los emails que se envían
        error_log('QvaClick Debug: Email intercepted - To: ' . $atts['to'] . ', Subject: ' . $atts['subject']);
        
        // Si el email contiene palabras clave de soporte, crear ticket
        $support_keywords = ['contacto', 'contact', 'soporte', 'support', 'ayuda', 'help'];
        $subject_lower = strtolower($atts['subject']);
        
        foreach ($support_keywords as $keyword) {
            if (strpos($subject_lower, $keyword) !== false) {
                error_log('QvaClick Debug: Support email detected via wp_mail hook');
                
                // Intentar extraer información del email
                $this->create_ticket_from_email($atts);
                break;
            }
        }
        
        return $atts; // Continuar con el envío normal
    }
    
    /**
     * Crear ticket desde un email interceptado (versión mejorada)
     */
    private function create_ticket_from_email($email_atts) {
        $admin_email = QvaClick_Admin_Email_Manager::get_instance();
        if (!$admin_email) {
            return false;
        }
        
        // Extraer información básica
        $to_email = is_array($email_atts['to']) ? $email_atts['to'][0] : $email_atts['to'];
        $subject = $email_atts['subject'];
        $message = $email_atts['message'];
        
        // Intentar extraer el email del remitente desde headers
        $sender_email = '';
        $sender_name = 'Usuario';
        
        if (isset($email_atts['headers'])) {
            $headers = is_array($email_atts['headers']) ? implode("\n", $email_atts['headers']) : $email_atts['headers'];
            
            // Buscar Reply-To o From
            if (preg_match('/Reply-To:\s*(.+@.+)/i', $headers, $matches)) {
                $sender_email = trim($matches[1]);
            } elseif (preg_match('/From:\s*(.+@.+)/i', $headers, $matches)) {
                $sender_email = trim($matches[1]);
            }
        }
        
        // Si no tenemos email del remitente, usar uno por defecto pero registrar el problema
        if (empty($sender_email)) {
            $sender_email = 'contacto@qvaclick.com';
            error_log('QvaClick Debug: No sender email found in intercepted email, using default');
        }
        
        // Verificar duplicados
        if ($this->is_duplicate_ticket($sender_email, $subject, $message)) {
            error_log('QvaClick Debug: Duplicate ticket detected from intercepted email, skipping');
            return false;
        }
        
        $ticket_data = array(
            'user_id' => 0,
            'user_email' => $sender_email,
            'user_name' => $sender_name,
            'subject' => $subject,
            'message' => $this->clean_email_message($message),
            'category' => 'email_intercepted',
            'priority' => 'normal'
        );
        
        $result = $admin_email->create_support_ticket($ticket_data);
        
        if ($result) {
            error_log('QvaClick Debug: Ticket created from intercepted email - ID: ' . $result);
            
            // Enviar confirmación automática
            $this->send_auto_confirmation_email($result, $sender_email, $sender_name, $subject);
        } else {
            error_log('QvaClick Debug: Failed to create ticket from intercepted email');
        }
        
        return $result;
    }
    
    /**
     * Extraer email del formulario - Buscar diferentes nombres de campo
     */
    private function is_duplicate_ticket($email, $subject, $message) {
        global $wpdb;
        
        // Buscar tickets con el mismo email y asunto creados en los últimos 10 minutos
        // (aumentamos el tiempo para formularios de contact-us para ser más estrictos)
        $time_threshold = date('Y-m-d H:i:s', strtotime('-10 minutes'));
        
        $table_name = $wpdb->prefix . 'qvc_support_tickets';
        
        // Verificar por email y asunto (más estricto para contact-us)
        $existing_ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table_name 
             WHERE user_email = %s 
             AND subject = %s 
             AND created_at > %s 
             ORDER BY created_at DESC 
             LIMIT 1",
            $email,
            $subject,
            $time_threshold
        ));
        
        if ($existing_ticket) {
            error_log("QvaClick Debug: Duplicate ticket found for email $email with subject '$subject' within last 10 minutes");
            return true;
        }
        
        // Verificación adicional: mismo email con mensaje muy similar en últimos 5 minutos
        $shorter_threshold = date('Y-m-d H:i:s', strtotime('-5 minutes'));
        $message_hash = md5(trim(strtolower($message)));
        
        $similar_tickets = $wpdb->get_results($wpdb->prepare(
            "SELECT id, message FROM {$wpdb->prefix}qvc_support_messages 
             WHERE user_email = %s 
             AND created_at > %s 
             ORDER BY created_at DESC 
             LIMIT 5",
            $email,
            $shorter_threshold
        ));
        
        foreach ($similar_tickets as $ticket) {
            if (md5(trim(strtolower($ticket->message))) === $message_hash) {
                error_log("QvaClick Debug: Duplicate message content found for email $email within last 5 minutes");
                return true;
            }
        }
        
        return false;
    }

    /**
     * Envía email de confirmación automático al crear un ticket
     */
    private function send_auto_confirmation_email($ticket_id, $user_email, $user_name, $subject) {
        // SAFETY CHECK: Verificar si este email ya fue enviado para este ticket
        if ($this->is_confirmation_already_sent($ticket_id)) {
            error_log('QvaClick Debug: Auto-confirmation already sent for ticket #' . $ticket_id);
            return true;
        }
        
        // SAFETY CHECK: Verificar que no estemos en un loop de emails
        if ($this->is_in_email_loop($user_email, $subject)) {
            error_log('QvaClick Debug: Email loop detected, blocking confirmation for: ' . $user_email);
            return false;
        }
        
        // Obtener configuración de email de soporte
        $admin_email_manager = QvaClick_Admin_Email_Manager::get_instance();
        $support_email = $admin_email_manager->get_support_from_email();
        $support_name = $admin_email_manager->get_support_from_name();
        
        // SAFETY CHECK: No enviar a nuestro propio email de soporte
        if ($user_email === $support_email) {
            error_log('QvaClick Debug: Prevented loop - not sending confirmation to own support email');
            return false;
        }
        
        $email_subject = sprintf('Ticket #%s de soporte abierto en QvaClick', $ticket_id);
        
        $email_content = sprintf(
            'Estimado/a %s,

Hemos recibido su mensaje de soporte y le atenderemos lo antes posible.

Detalles del ticket:
- Ticket ID: #%s
- Asunto: %s
- Estado: Abierto

Su solicitud ha sido registrada en nuestro sistema de soporte. Nuestro equipo revisará su consulta y le responderá a la brevedad posible.

Si tiene información adicional que agregar a este ticket, puede responder directamente a este email y su respuesta se agregará automáticamente al ticket.

Manténgase atento a este email para recibir actualizaciones sobre su solicitud.

Gracias por contactar con QvaClick.

---
Equipo de Soporte QvaClick
https://qvaclick.com',
            $user_name,
            $ticket_id,
            $subject
        );
        
        // Aplicar template si está disponible y no está ya aplicado
        if (class_exists('QvaClick_Base_Template_Manager')) {
            // VERIFICAR si el contenido ya tiene plantilla aplicada
            if (!QvaClick_Base_Template_Manager::has_base_template_applied($email_content)) {
                // Solo aplicar plantilla si no la tiene ya
                $email_content = QvaClick_Base_Template_Manager::apply_to_html($email_content);
            }
        }
        
        // Marcar que este ticket ya recibió confirmación
        $this->mark_confirmation_sent($ticket_id);
        
        // CRÍTICO: Usar noreply para evitar bucles de respuesta automática
        $admin_email_manager = QvaClick_Admin_Email_Manager::get_instance();
        $from_email = $admin_email_manager->get_support_from_email();
        $reply_to_email = $admin_email_manager->get_support_reply_to_email();
        $support_name = $admin_email_manager->get_support_from_name();
        
        // USAR SOLO wp_mail DIRECTO - NO las funciones protegidas que pueden causar loops
        $sent = wp_mail(
            $user_email,
            $email_subject,
            $email_content,
            array(
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . $support_name . ' <' . $from_email . '>',
                'Reply-To: ' . $reply_to_email  // Email específico para respuestas de soporte
            )
        );
        
        error_log('QvaClick Debug: Auto-confirmation email sent SAFELY: ' . ($sent ? 'SUCCESS' : 'FAILED') . ' to ' . $user_email);
        
        return $sent;
    }
    
    /**
     * Verificar si ya se envió confirmación para este ticket
     */
    private function is_confirmation_already_sent($ticket_id) {
        $sent_confirmations = get_transient('qvc_sent_confirmations');
        if (!$sent_confirmations) {
            $sent_confirmations = array();
        }
        
        return in_array($ticket_id, $sent_confirmations);
    }
    
    /**
     * Marcar confirmación como enviada
     */
    private function mark_confirmation_sent($ticket_id) {
        $sent_confirmations = get_transient('qvc_sent_confirmations');
        if (!$sent_confirmations) {
            $sent_confirmations = array();
        }
        
        $sent_confirmations[] = $ticket_id;
        
        // Guardar por 1 hora
        set_transient('qvc_sent_confirmations', $sent_confirmations, 3600);
    }
    
    /**
     * Detectar si estamos en un loop de emails
     */
    private function is_in_email_loop($email, $subject) {
        $loop_cache_key = 'qvc_email_loop_' . md5($email . $subject);
        $recent_sends = get_transient($loop_cache_key);
        
        if (!$recent_sends) {
            $recent_sends = array();
        }
        
        $now = time();
        
        // Limpiar envíos antiguos (más de 5 minutos)
        $recent_sends = array_filter($recent_sends, function($timestamp) use ($now) {
            return ($now - $timestamp) < 300; // 5 minutos
        });
        
        // Si hay más de 2 envíos en 5 minutos, es un loop
        if (count($recent_sends) >= 2) {
            return true;
        }
        
        // Registrar este envío
        $recent_sends[] = $now;
        set_transient($loop_cache_key, $recent_sends, 300); // 5 minutos
        
        return false;
    }
    
    /**
     * Detectar si es un email automático o de confirmación
     */
    private function is_automated_email_submission($posted_data) {
        // Verificar indicadores de emails automáticos
        $indicators = array(
            'auto-reply', 'auto-response', 'noreply', 'no-reply', 
            'mailer-daemon', 'postmaster', 'automated', 'system'
        );
        
        $all_data = serialize($posted_data);
        
        foreach ($indicators as $indicator) {
            if (stripos($all_data, $indicator) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Verificar si el asunto parece ser de confirmación
     */
    private function is_confirmation_subject($subject) {
        $confirmation_patterns = array(
            'ticket.*#.*abierto', 'confirmación', 'confirmation', 
            'auto.*reply', 'respuesta.*automática', 'thank.*you',
            'gracias.*por.*contactar', 'recibido.*mensaje'
        );
        
        foreach ($confirmation_patterns as $pattern) {
            if (preg_match('/' . $pattern . '/i', $subject)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Intercepta emails para detectar respuestas a tickets
     */
    public function intercept_email_replies($args) {
        // ==========================================
        // PROTECCIÓN TOTAL WOOCOMMERCE
        // NO TOCAR NINGÚN EMAIL DE WOOCOMMERCE
        // ==========================================
        
        // 1. Verificar si WooCommerce está activo y enviando el email
        if (function_exists('WC') || class_exists('WooCommerce')) {
            // Detectar si el email viene del stack de WooCommerce
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
            foreach ($backtrace as $trace) {
                if (isset($trace['file']) && 
                    (strpos($trace['file'], 'woocommerce') !== false || 
                     strpos($trace['file'], 'wc-') !== false ||
                     (isset($trace['class']) && strpos(strtolower($trace['class']), 'wc') === 0) ||
                     (isset($trace['class']) && strpos($trace['class'], 'WooCommerce') !== false))) {
                    error_log('QvaClick Debug: WooCommerce stack detected in backtrace, skipping all processing');
                    return $args; // SALIR INMEDIATAMENTE sin tocar nada
                }
            }
        }
        
        // 2. Detectar indicadores de WooCommerce en el contenido
        $woocommerce_indicators = array(
            'woocommerce', 'WooCommerce', 'wc-', 'WC_',
            'order', 'Order', 'pedido', 'Pedido',
            '#header_wrapper', '#body_content', '#body_content_inner',
            'email-styles', 'woocommerce-email',
            'order-details', 'order_details', 'billing_address', 'shipping_address',
            'woocommerce/emails', 'wc_get_template',
            'Your order', 'Tu pedido', 'Order #', 'Pedido #',
            'order-received', 'checkout', 'thank-you'
        );
        
        // 3. Verificar headers de WooCommerce
        if (isset($args['headers'])) {
            $headers_string = is_array($args['headers']) ? implode(' ', $args['headers']) : $args['headers'];
            if (strpos(strtolower($headers_string), 'woocommerce') !== false ||
                strpos(strtolower($headers_string), 'wc-') !== false) {
                error_log('QvaClick Debug: WooCommerce headers detected, skipping processing');
                return $args;
            }
        }
        
        // 4. Verificar contenido del email
        $subject = isset($args['subject']) ? $args['subject'] : '';
        $message = isset($args['message']) ? $args['message'] : '';
        $to = isset($args['to']) ? $args['to'] : '';
        
        foreach ($woocommerce_indicators as $indicator) {
            if (strpos($message, $indicator) !== false || 
                strpos($subject, $indicator) !== false ||
                strpos($to, $indicator) !== false) {
                
                error_log('QvaClick Debug: WooCommerce indicator "' . $indicator . '" detected, skipping all processing - Subject: ' . $subject);
                return $args; // SALIR INMEDIATAMENTE sin tocar nada
            }
        }
        
        // 5. Verificar si viene de templates de WooCommerce
        if (strpos($message, '<style type="text/css">') !== false && 
            (strpos($message, '@media screen and (max-width: 600px)') !== false ||
             strpos($message, '#header_wrapper') !== false)) {
            error_log('QvaClick Debug: WooCommerce email template detected, skipping processing');
            return $args;
        }
        
        // Obtener email de soporte configurado dinámicamente
        $admin_email_manager = QvaClick_Admin_Email_Manager::get_instance();
        $support_email = $admin_email_manager->get_support_from_email();
        
        // Lista de emails que pueden recibir respuestas de soporte
        $support_emails = array(
            $support_email,
            'support@qvaclick.com',      // Email principal de soporte
            'soporte@qvaclick.com',      // Variante en español
            get_option('admin_email')     // Email de admin como backup final
        );
        
        // Eliminar duplicados y valores vacíos
        $support_emails = array_filter(array_unique($support_emails));
        
        error_log('QvaClick Debug: Email interception check for: ' . $args['to'] . ' | Support emails configured: ' . implode(', ', $support_emails));
        
        // Verificar si el email va a alguna dirección de soporte
        $is_support_email = false;
        $recipient = is_array($args['to']) ? $args['to'][0] : $args['to'];
        
        // Limpiar el email de destinatario (quitar espacios y formato)
        $recipient = trim(str_replace(array('<', '>'), '', $recipient));
        
        foreach ($support_emails as $support_addr) {
            if (stripos($recipient, $support_addr) !== false) {
                $is_support_email = true;
                error_log('QvaClick Debug: Match found with support email: ' . $support_addr);
                break;
            }
        }
        
        // Si no es un email de soporte, no interceptar
        if (!$is_support_email) {
            return $args;
        }
        
        error_log('QvaClick Debug: Support email detected, checking for ticket ID in subject: ' . $args['subject']);
        
        // Verificar si es una respuesta a un ticket (buscar ticket ID en subject)
        // Patrón actualizado para detectar tanto números como formato TKT-XXXXXXXX
        if (preg_match('/\[?Ticket #(TKT-[A-Z0-9]+|\d+)\]?/i', $args['subject'], $matches)) {
            $ticket_id = $matches[1];
            
            error_log('QvaClick Debug: Found ticket ID #' . $ticket_id . ' in email subject');
            
            // Agregar el email como mensaje al ticket
            $result = $this->add_email_reply_to_ticket($ticket_id, $args);
            
            error_log('QvaClick Debug: Email reply added to ticket #' . $ticket_id . ': ' . ($result ? 'SUCCESS' : 'FAILED'));
        } else {
            error_log('QvaClick Debug: No ticket ID found in subject, checking if it\'s a new support request');
            
            // Si no tiene ticket ID pero va a soporte, puede ser un nuevo ticket
            $this->maybe_create_ticket_from_email($args);
        }
        
        return $args;
    }
    
    /**
     * Verificar si un email entrante puede crear un nuevo ticket
     */
    private function maybe_create_ticket_from_email($email_args) {
        // Solo crear tickets automáticos si el email parece ser de soporte
        $support_keywords = ['contacto', 'contact', 'soporte', 'support', 'ayuda', 'help', 'problema', 'issue', 'consulta', 'question'];
        $subject_lower = strtolower($email_args['subject']);
        
        $is_support_request = false;
        foreach ($support_keywords as $keyword) {
            if (strpos($subject_lower, $keyword) !== false) {
                $is_support_request = true;
                break;
            }
        }
        
        if (!$is_support_request) {
            error_log('QvaClick Debug: Email does not appear to be a support request, skipping auto-ticket creation');
            return false;
        }
        
        error_log('QvaClick Debug: Creating ticket from direct email to support');
        
        // Intentar extraer información del email
        return $this->create_ticket_from_email($email_args);
    }
    
    /**
     * Agrega una respuesta por email a un ticket existente
     */
    private function add_email_reply_to_ticket($ticket_id, $email_args) {
        global $wpdb;
        
        // Verificar que el ticket existe
        $table = $wpdb->prefix . 'qvc_support_tickets';
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE ticket_id = %s",
            $ticket_id
        ));
        
        if (!$ticket) {
            error_log('QvaClick Debug: Ticket #' . $ticket_id . ' not found in database');
            return false;
        }
        
        // Extraer información del email de forma más robusta
        $from_email = '';
        $from_name = 'Usuario';
        
        // Primero intentar extraer desde headers
        if (isset($email_args['headers'])) {
            $headers = is_array($email_args['headers']) ? $email_args['headers'] : array($email_args['headers']);
            
            foreach ($headers as $header) {
                if (is_string($header)) {
                    // Buscar From header
                    if (stripos($header, 'From:') === 0) {
                        if (preg_match('/From:\s*(.+?)\s*<(.+?)>/', $header, $matches)) {
                            $from_name = trim($matches[1], '"');
                            $from_email = trim($matches[2]);
                        } elseif (preg_match('/From:\s*(.+@.+)/', $header, $matches)) {
                            $from_email = trim($matches[1]);
                        }
                        break;
                    }
                    // Buscar Reply-To como alternativa
                    elseif (stripos($header, 'Reply-To:') === 0 && empty($from_email)) {
                        if (preg_match('/Reply-To:\s*(.+@.+)/', $header, $matches)) {
                            $from_email = trim($matches[1]);
                        }
                    }
                }
            }
        }
        
        // Si no tenemos email del header, verificar si viene en el cuerpo del email
        if (empty($from_email)) {
            // Usar el email original del ticket como fallback
            $from_email = $ticket->user_email;
            $from_name = $ticket->user_name;
            error_log('QvaClick Debug: Using original ticket email as sender: ' . $from_email);
        }
        
        // Procesar el mensaje del email
        $message_content = $email_args['message'];
        
        // Limpiar el mensaje (remover headers, firmas automáticas, etc.)
        $message_content = $this->clean_email_message($message_content);
        
        // Agregar mensaje al ticket
        $admin_email = QvaClick_Admin_Email_Manager::get_instance();
        if ($admin_email) {
            $message_data = array(
                'user_id' => 0,
                'user_email' => $from_email,
                'user_name' => $from_name,
                'user_type' => 'guest',
                'message' => $message_content,
                'is_admin_reply' => 0
            );
            
            $result = $admin_email->add_ticket_message($ticket_id, $message_data);
            
            // Actualizar estado del ticket si estaba cerrado
            if (in_array($ticket->status, array('resolved', 'closed'))) {
                $wpdb->update(
                    $table,
                    array(
                        'status' => 'open',
                        'updated_at' => current_time('mysql')
                    ),
                    array('ticket_id' => $ticket_id)
                );
                error_log('QvaClick Debug: Ticket #' . $ticket_id . ' reopened due to new message');
            } else {
                // Actualizar timestamp de modificación
                $wpdb->update(
                    $table,
                    array('updated_at' => current_time('mysql')),
                    array('ticket_id' => $ticket_id)
                );
            }
            
            if ($result) {
                error_log('QvaClick Debug: Message added to ticket #' . $ticket_id . ' from ' . $from_email);
            }
            
            return $result;
        }
        
        error_log('QvaClick Debug: Admin Email Manager not available for ticket message');
        return false;
    }
    
    /**
     * Limpia el contenido del mensaje de email eliminando elementos no deseados
     */
    private function clean_email_message($message) {
        // Remover headers comunes de email
        $message = preg_replace('/^(From|To|Subject|Date|Message-ID|Content-Type|MIME-Version):.*$/mi', '', $message);
        
        // Remover líneas vacías excesivas
        $message = preg_replace('/\n\s*\n\s*\n/', "\n\n", $message);
        
        // Remover firmas automáticas comunes
        $signature_patterns = array(
            '/--\s*\n.*$/s',  // Firmas que empiezan con --
            '/^\s*Sent from my.*$/mi',  // "Sent from my iPhone", etc.
            '/^\s*Get Outlook for.*$/mi',  // Outlook mobile
            '/^\s*Enviado desde.*$/mi',  // "Enviado desde mi iPhone", etc.
        );
        
        foreach ($signature_patterns as $pattern) {
            $message = preg_replace($pattern, '', $message);
        }
        
        // Limpiar espacios en blanco al inicio y final
        $message = trim($message);
        
        return $message;
    }

    private function extract_email_from_form($posted_data) {
        $email_fields = ['your-email', 'email', 'user-email', 'contact-email', 'sender-email', 'from-email'];
        
        foreach ($email_fields as $field) {
            if (isset($posted_data[$field]) && !empty($posted_data[$field])) {
                return sanitize_email($posted_data[$field]);
            }
        }
        return '';
    }
    
    /**
     * Extraer nombre del formulario
     */
    private function extract_name_from_form($posted_data) {
        $name_fields = ['your-name', 'name', 'user-name', 'contact-name', 'sender-name', 'from-name', 'full-name'];
        
        foreach ($name_fields as $field) {
            if (isset($posted_data[$field]) && !empty($posted_data[$field])) {
                return sanitize_text_field($posted_data[$field]);
            }
        }
        return 'Invitado';
    }
    
    /**
     * Extraer asunto del formulario
     */
    private function extract_subject_from_form($posted_data) {
        $subject_fields = ['your-subject', 'subject', 'message-subject', 'contact-subject', 'topic'];
        
        foreach ($subject_fields as $field) {
            if (isset($posted_data[$field]) && !empty($posted_data[$field])) {
                return sanitize_text_field($posted_data[$field]);
            }
        }
        return 'Mensaje desde formulario de contacto';
    }
    
    /**
     * Extraer mensaje del formulario
     */
    private function extract_message_from_form($posted_data) {
        $message_fields = ['your-message', 'message', 'content', 'comment', 'description', 'details', 'inquiry'];
        
        foreach ($message_fields as $field) {
            if (isset($posted_data[$field]) && !empty($posted_data[$field])) {
                return sanitize_textarea_field($posted_data[$field]);
            }
        }
        return '';
    }
    
    // ===== SMTP/IMAP Configuration =====
    
    /**
     * Página de configuración SMTP/IMAP
     */
    public function smtp_config_page() {
        if (!class_exists('QvaClick_SMTP_Config_Page')) {
            echo '<div class="wrap"><h1>Configuración SMTP/IMAP</h1><div class="notice notice-error"><p>Clase QvaClick_SMTP_Config_Page no encontrada.</p></div></div>';
            return;
        }
        QvaClick_SMTP_Config_Page::render_page();
    }
    
    /**
     * Configurar PHPMailer con nuestras credenciales SMTP
     * ESTA FUNCIÓN AUTOMÁTICAMENTE HACE QUE TODOS LOS EMAILS DE WORDPRESS
     * USEN NUESTRO SERVIDOR SMTP CONFIGURADO
     */
    public function configure_phpmailer($phpmailer) {
        // ==========================================
        // PROTECCIÓN WOOCOMMERCE - PHPMAILER
        // Permitir que WooCommerce use su propia configuración si necesita
        // ==========================================
        
        // Verificar si es una llamada desde WooCommerce
        if (function_exists('WC') || class_exists('WooCommerce')) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);
            foreach ($backtrace as $trace) {
                if (isset($trace['file']) && 
                    (strpos($trace['file'], 'woocommerce') !== false || 
                     strpos($trace['file'], 'wc-') !== false ||
                     (isset($trace['class']) && strpos(strtolower($trace['class']), 'wc') === 0) ||
                     (isset($trace['class']) && strpos($trace['class'], 'WooCommerce') !== false))) {
                    error_log('QvaClick Debug: WooCommerce detected in PHPMailer init, applying SMTP but with care');
                    // No return aquí - permitir que use SMTP pero sin interferencias adicionales
                    break;
                }
            }
        }
        
        // Verificar que tenemos el objeto PHPMailer correcto
        if (!is_object($phpmailer)) {
            error_log('QvaClick Debug: PHPMailer object not provided');
            return;
        }
        
        // Obtener configuración SMTP
        $smtp_config = get_option('qvc_smtp_config', array());
        
        // Verificar si SMTP está habilitado y configurado
        if (empty($smtp_config) || !isset($smtp_config['enabled']) || !$smtp_config['enabled']) {
            error_log('QvaClick Debug: SMTP disabled or not configured, using default WordPress mail');
            return;
        }
        
        if (empty($smtp_config['smtp_host']) || empty($smtp_config['smtp_username']) || empty($smtp_config['smtp_password'])) {
            error_log('QvaClick Debug: SMTP config incomplete (missing host, username, or password), using default WordPress mail');
            return;
        }
        
        try {
            // CONFIGURACIÓN SMTP COMPLETA
            $phpmailer->isSMTP();
            $phpmailer->Host = $smtp_config['smtp_host'];
            $phpmailer->Port = intval($smtp_config['smtp_port'] ?? 587);
            $phpmailer->SMTPAuth = true;
            $phpmailer->Username = $smtp_config['smtp_username'];
            $phpmailer->Password = $smtp_config['smtp_password'];
            
            // Configurar encriptación
            $encryption = $smtp_config['smtp_encryption'] ?? 'tls';
            if ($encryption === 'tls') {
                $phpmailer->SMTPSecure = 'tls'; // Usar string directamente
            } elseif ($encryption === 'ssl') {
                $phpmailer->SMTPSecure = 'ssl'; // Usar string directamente
            }
            
            // Configurar FROM address (importante para evitar SPF/DKIM issues)
            if (!empty($smtp_config['from_email'])) {
                $from_name = $smtp_config['from_name'] ?? get_bloginfo('name');
                $phpmailer->setFrom($smtp_config['from_email'], $from_name);
                
                // Configurar Reply-To usando el email de Reply-To específico SIEMPRE
                $admin_email_manager = QvaClick_Admin_Email_Manager::get_instance();
                $reply_to_email = $admin_email_manager->get_support_reply_to_email();
                $support_name = $admin_email_manager->get_support_from_name();
                
                // CRÍTICO: Añadir Reply-To SIEMPRE para que las respuestas vayan a support
                $phpmailer->addReplyTo($reply_to_email, $support_name);
                error_log('QvaClick Debug: Reply-To añadido: ' . $reply_to_email);
            }
            
            // Configuraciones adicionales de seguridad y rendimiento
            $phpmailer->SMTPKeepAlive = false; // Evitar problemas de conexión persistente
            $phpmailer->Timeout = 30; // Timeout de 30 segundos
            $phpmailer->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );
            
            // Debug condicional
            if (!empty($smtp_config['debug_mode'])) {
                $phpmailer->SMTPDebug = 2; // SMTP::DEBUG_SERVER
                $phpmailer->Debugoutput = function($str, $level) {
                    error_log("QvaClick SMTP Debug: $str");
                };
            }
            
            error_log('QvaClick Debug: PHPMailer configured with custom SMTP settings - ALL WordPress emails will use: ' . $smtp_config['smtp_host']);
            
        } catch (Exception $e) {
            error_log('QvaClick Debug: SMTP configuration error: ' . $e->getMessage());
            // En caso de error, dejar que WordPress use el método por defecto
        } catch (Throwable $e) {
            error_log('QvaClick Debug: SMTP configuration fatal error: ' . $e->getMessage());
            // Capturar errores fatales también
        }
    }
    
    /**
     * Configurar FROM email para todos los emails de WordPress
     * Se ejecuta automáticamente via filtro wp_mail_from
     */
    public function set_mail_from($original_email_from) {
        // ==========================================
        // PROTECCIÓN WOOCOMMERCE - FROM EMAIL
        // NO CAMBIAR FROM en emails de WooCommerce
        // ==========================================
        
        // Verificar si es una llamada desde WooCommerce
        if (function_exists('WC') || class_exists('WooCommerce')) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);
            foreach ($backtrace as $trace) {
                if (isset($trace['file']) && 
                    (strpos($trace['file'], 'woocommerce') !== false || 
                     strpos($trace['file'], 'wc-') !== false ||
                     (isset($trace['class']) && strpos(strtolower($trace['class']), 'wc') === 0) ||
                     (isset($trace['class']) && strpos($trace['class'], 'WooCommerce') !== false))) {
                    error_log('QvaClick Debug: WooCommerce detected in set_mail_from, keeping original FROM: ' . $original_email_from);
                    return $original_email_from; // NO cambiar el FROM para WooCommerce
                }
            }
        }
        
        $smtp_config = get_option('qvc_smtp_config', array());
        
        // Solo cambiar si SMTP está habilitado y configurado
        if (!empty($smtp_config['enabled']) && !empty($smtp_config['from_email'])) {
            error_log('QvaClick Debug: Setting wp_mail FROM to: ' . $smtp_config['from_email'] . ' (original was: ' . $original_email_from . ')');
            return $smtp_config['from_email'];
        }
        
        return $original_email_from;
    }
    
    /**
     * Configurar FROM name para todos los emails de WordPress
     * Se ejecuta automáticamente via filtro wp_mail_from_name
     */
    public function set_mail_from_name($original_name_from) {
        // ==========================================
        // PROTECCIÓN WOOCOMMERCE - FROM NAME
        // NO CAMBIAR FROM_NAME en emails de WooCommerce
        // ==========================================
        
        // Verificar si es una llamada desde WooCommerce
        if (function_exists('WC') || class_exists('WooCommerce')) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);
            foreach ($backtrace as $trace) {
                if (isset($trace['file']) && 
                    (strpos($trace['file'], 'woocommerce') !== false || 
                     strpos($trace['file'], 'wc-') !== false ||
                     (isset($trace['class']) && strpos(strtolower($trace['class']), 'wc') === 0) ||
                     (isset($trace['class']) && strpos($trace['class'], 'WooCommerce') !== false))) {
                    error_log('QvaClick Debug: WooCommerce detected in set_mail_from_name, keeping original FROM_NAME: ' . $original_name_from);
                    return $original_name_from; // NO cambiar el FROM_NAME para WooCommerce
                }
            }
        }
        
        $smtp_config = get_option('qvc_smtp_config', array());
        
        // Solo cambiar si SMTP está habilitado y configurado
        if (!empty($smtp_config['enabled']) && !empty($smtp_config['from_name'])) {
            error_log('QvaClick Debug: Setting wp_mail FROM NAME to: ' . $smtp_config['from_name'] . ' (original was: ' . $original_name_from . ')');
            return $smtp_config['from_name'];
        }
        
        return $original_name_from;
    }
    
    /**
     * Inicializar cron para leer emails IMAP
     */
    public function init_email_cron() {
        // Registrar evento cron si no existe
        if (!wp_next_scheduled('qvc_check_imap_emails')) {
            wp_schedule_event(time(), 'qvc_email_interval', 'qvc_check_imap_emails');
        }
        
        // Agregar hook para ejecutar la función
        add_action('qvc_check_imap_emails', array($this, 'process_imap_emails'));
        
        // Agregar intervalo personalizado
        add_filter('cron_schedules', array($this, 'add_email_cron_intervals'));
    }
    
    /**
     * Agregar intervalos personalizados para cron
     */
    public function add_email_cron_intervals($schedules) {
        $schedules['qvc_email_interval'] = array(
            'interval' => 300, // 5 minutos por defecto
            'display'  => __('Cada 5 minutos (QvaClick Email)', 'qvaclick-email-manager')
        );
        
        $schedules['qvc_email_1min'] = array(
            'interval' => 60,
            'display'  => __('Cada minuto (QvaClick Email)', 'qvaclick-email-manager')
        );
        
        $schedules['qvc_email_15min'] = array(
            'interval' => 900,
            'display'  => __('Cada 15 minutos (QvaClick Email)', 'qvaclick-email-manager')
        );
        
        $schedules['qvc_email_30min'] = array(
            'interval' => 1800,
            'display'  => __('Cada 30 minutos (QvaClick Email)', 'qvaclick-email-manager')
        );
        
        return $schedules;
    }
    
    /**
     * Procesar emails IMAP - VOLVEMOS AL MÉTODO LEGACY QUE FUNCIONABA
     */
    public function process_imap_emails() {
        // error_log('QvaClick Debug: Legacy IMAP processing started'); // Desactivado - funciona correctamente
        
        // Usar directamente el IMAP Reader legacy que funcionaba mejor
        if (class_exists('QvaClick_IMAP_Reader')) {
            $imap_reader = new QvaClick_IMAP_Reader();
            $result = $imap_reader->process_new_emails();
            
            if ($result) {
                // error_log('QvaClick Debug: Legacy IMAP processing completed successfully'); // Desactivado - funciona correctamente
                return $result;
            } else {
                // error_log('QvaClick Debug: Legacy IMAP processing failed'); // Desactivado para reducir logs
                return false;
            }
        } else {
            // error_log('QvaClick Debug: QvaClick_IMAP_Reader class not found'); // Desactivado para reducir logs
            return false;
        }
    }
    
    /**
     * Sincronizar plantillas con Exertio Framework
     */
    public function ajax_sync_exertio() {
        check_ajax_referer('qvc_email_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'qvaclick-email-manager'));
        }

        try {
            // Con el nuevo Redux Sync Manager, la sincronización es automática
            // Pero podemos forzar una verificación y sincronización completa
            
            $sync_stats = array(
                'templates_synced' => 0,
                'redux_accessible' => false,
                'exertio_accessible' => false,
                'errors' => array()
            );

            // 1. Verificar que Redux esté accesible
            $redux_options = get_option('exertio_theme_options', array());
            if (!empty($redux_options)) {
                $sync_stats['redux_accessible'] = true;
            }

            // 2. Verificar que Exertio puede leer los datos
            if (function_exists('fl_framework_get_options')) {
                $exertio_data = fl_framework_get_options();
                if (!empty($exertio_data)) {
                    $sync_stats['exertio_accessible'] = true;
                }
            } else {
                // Fallback: verificar acceso directo a Redux
                $sync_stats['exertio_accessible'] = $sync_stats['redux_accessible'];
            }

            // 3. Obtener todos los emails y forzar sincronización si es necesario
            $all_emails = QvaClick_Redux_Sync_Manager::get_all_emails_from_redux();
            $sync_stats['templates_synced'] = count($all_emails);

            // 4. Limpiar caches para asegurar datos frescos
            delete_transient('redux_exertio_theme_options');
            wp_cache_delete('exertio_theme_options', 'options');
            
            // 5. Verificación adicional: asegurar que key patterns estén consistentes
            $this->normalize_email_keys_in_redux();

            error_log('QvaClick Email Manager: Sincronización Redux completada. Templates encontrados: ' . count($all_emails));

            wp_send_json_success(array(
                'message' => __('¡Sincronización completada! Ahora QvaClick y Exertio Framework leen la misma información.', 'qvaclick-email-manager'),
                'stats' => $sync_stats,
                'using_system' => 'Redux Sync Manager (Fuente única)',
                'data_source' => 'exertio_theme_options',
                'templates_found' => count($all_emails),
                'cache_cleared' => true
            ));

        } catch (Exception $e) {
            error_log('QvaClick Sync Error: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => __('Error durante la sincronización: ', 'qvaclick-email-manager') . $e->getMessage()
            ));
        }
    }

    /**
     * Normalizar claves de email en Redux para asegurar patrones consistentes
     */
    private function normalize_email_keys_in_redux() {
        $redux_options = get_option('exertio_theme_options', array());
        $changes_made = false;

        // Identificar emails que puedan tener claves inconsistentes
        $email_groups = array();
        
        foreach ($redux_options as $key => $value) {
            // Agrupar por base_key
            if (preg_match('/^(.+?)_(?:subject|subj|sub|body|message|template|content|switch|enabled|status)$/i', $key, $matches)) {
                $base_key = $matches[1];
                if (!isset($email_groups[$base_key])) {
                    $email_groups[$base_key] = array();
                }
                $email_groups[$base_key][$key] = $value;
            }
        }

        // Normalizar patrones donde sea necesario
        foreach ($email_groups as $base_key => $keys) {
            // Verificar y normalizar subjects a patrón '_sub'
            $subject_keys = array_keys(array_filter($keys, function($k) {
                return preg_match('/_(?:subject|subj|sub)$/i', $k);
            }, ARRAY_FILTER_USE_KEY));

            if (count($subject_keys) > 1) {
                // Múltiples claves de subject, consolidar a '_sub'
                $preferred_key = $base_key . '_sub';
                $subject_value = '';
                
                foreach ($subject_keys as $old_key) {
                    if (!empty($redux_options[$old_key])) {
                        $subject_value = $redux_options[$old_key];
                        break;
                    }
                }
                
                // Limpiar claves viejas y usar la preferida
                foreach ($subject_keys as $old_key) {
                    if ($old_key !== $preferred_key) {
                        unset($redux_options[$old_key]);
                        $changes_made = true;
                    }
                }
                
                if (!empty($subject_value)) {
                    $redux_options[$preferred_key] = $subject_value;
                    $changes_made = true;
                }
            }

            // Similar normalización para body keys
            $body_keys = array_keys(array_filter($keys, function($k) {
                return preg_match('/_(?:body|message|template|content)$/i', $k);
            }, ARRAY_FILTER_USE_KEY));

            if (count($body_keys) > 1) {
                $preferred_key = $base_key . '_body';
                $body_value = '';
                
                foreach ($body_keys as $old_key) {
                    if (!empty($redux_options[$old_key])) {
                        $body_value = $redux_options[$old_key];
                        break;
                    }
                }
                
                foreach ($body_keys as $old_key) {
                    if ($old_key !== $preferred_key) {
                        unset($redux_options[$old_key]);
                        $changes_made = true;
                    }
                }
                
                if (!empty($body_value)) {
                    $redux_options[$preferred_key] = $body_value;
                    $changes_made = true;
                }
            }
        }

        // Guardar cambios si se hicieron normalizaciones
        if ($changes_made) {
            update_option('exertio_theme_options', $redux_options);
            error_log('QvaClick: Normalized email key patterns in Redux');
        }
    }
}

// Initialize the plugin
QvaClick_Email_Manager::get_instance();

// Register WP-CLI commands if in CLI context
if (defined('WP_CLI') && WP_CLI) {
    if (class_exists('QvaClick_Email_CLI')) {
        WP_CLI::add_command('qvc-emails', 'QvaClick_Email_CLI');
    }
}

// Fin del archivo
