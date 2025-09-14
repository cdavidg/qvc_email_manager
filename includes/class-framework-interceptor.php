<?php
/**
 * Framework Function Interceptor
 * Intercepta funciones del framework Exertio para procesar emails a través del plugin QvaClick
 */

class QvaClick_Framework_Interceptor {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Inicializar hooks de interceptación
     */
    private function init_hooks() {
        // Verificar si la interceptación está habilitada
        $interceptor_enabled = get_option('qvc_email_interceptor_enabled', false);
        
        if ($interceptor_enabled) {
            // Hook de alta prioridad para interceptar antes de que se ejecuten las funciones originales
            add_action('init', array($this, 'setup_function_interceptors'), 5);
            
            // Hook para interceptar wp_mail y procesar subjects (solo para emails del framework)
            add_filter('wp_mail', array($this, 'process_email_before_send'), 10, 1);
        }
    }
    
    /**
     * Configurar interceptores de funciones específicas
     */
    public function setup_function_interceptors() {
        // Interceptar función de email de proyecto
        if (function_exists('fl_project_post_email')) {
            // Remover la función original si existe y reemplazarla
            add_action('fl_project_published', array($this, 'intercept_project_email'), 5, 2);
        }
    }
    
    /**
     * Interceptar y procesar email de proyecto publicado
     */
    public function intercept_project_email($user_id, $post_id) {
        if (empty($user_id) || empty($post_id)) {
            return;
        }
        
        // Intentar encontrar template en el plugin QvaClick
        $base_key = 'fl_project_published'; // Base key para este tipo de email
        
        if (class_exists('QvaClick_Email_Discovery')) {
            $templates = QvaClick_Email_Discovery::discover_email_templates();
            
            if (isset($templates[$base_key]) && !empty($templates[$base_key]['enabled'])) {
                // Usar template del plugin QvaClick
                $this->send_project_email_via_plugin($user_id, $post_id, $templates[$base_key]);
                return; // No ejecutar la función original
            }
        }
        
        // Si no hay template en el plugin, usar la función original pero con subject procesado
        $this->send_project_email_enhanced($user_id, $post_id);
    }
    
    /**
     * Enviar email usando template del plugin QvaClick
     */
    private function send_project_email_via_plugin($user_id, $post_id, $template) {
        $user_infos = get_userdata($user_id);
        if (!$user_infos) {
            return;
        }
        
        $to = $user_infos->user_email;
        $from = 'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>';
        $headers = array('Content-Type: text/html; charset=UTF-8', $from);
        
        // Variables para reemplazo
        $keywords = array(
            '%site_name%', 
            '%display_name%', 
            '%project_link%', 
            '%project_title%',
            '%email%',
            '%user_login%'
        );
        
        $replaces = array(
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            $user_infos->display_name,
            get_the_permalink($post_id),
            get_the_title($post_id),
            $user_infos->user_email,
            $user_infos->user_login
        );
        
        // Procesar subject y body
        $subject = str_replace($keywords, $replaces, $template['subject']);
        $body = str_replace($keywords, $replaces, $template['body']);
        
        // Aplicar plantilla base si está configurada
        if (class_exists('QvaClick_Base_Template_Manager')) {
            $base_template = QvaClick_Base_Template_Manager::get_base_template();
            if (!empty($base_template)) {
                $body = str_replace('{email_content}', $body, $base_template);
            }
        }
        
        // Log del email para debugging
        $this->log_email_send('project_published', $user_id, $post_id, $subject, 'plugin_template');
        
        wp_mail($to, $subject, $body, $headers);
    }
    
    /**
     * Enviar email usando función original pero con subject procesado
     */
    private function send_project_email_enhanced($user_id, $post_id) {
        $user_infos = get_userdata($user_id);
        if (!$user_infos) {
            return;
        }
        
        $to = $user_infos->user_email;
        $from = 'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>';
        $headers = array('Content-Type: text/html; charset=UTF-8', $from);
        
        // Variables para reemplazo
        $keywords = array(
            '%site_name%', 
            '%display_name%', 
            '%project_link%', 
            '%project_title%'
        );
        
        $replaces = array(
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            $user_infos->display_name,
            get_the_permalink($post_id),
            get_the_title($post_id)
        );
        
        // Obtener subject y body de las opciones
        $subject = fl_framework_get_options('fl_email_onproject_created_sub');
        $body = fl_framework_get_options('fl_email_onproject_created_email_body');
        
        // ⭐ AQUÍ ESTÁ LA CORRECCIÓN: Procesar también el subject
        $subject = str_replace($keywords, $replaces, $subject);
        $body = str_replace($keywords, $replaces, $body);
        
        // Log del email para debugging
        $this->log_email_send('project_published', $user_id, $post_id, $subject, 'framework_enhanced');
        
        wp_mail($to, $subject, $body, $headers);
    }
    
    /**
     * Interceptar wp_mail para procesar subjects que no hayan sido procesados
     */
    public function process_email_before_send($args) {
        // ==========================================
        // PROTECCIÓN TOTAL WOOCOMMERCE - PRIMERA LÍNEA DE DEFENSA
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
                    error_log('QvaClick Framework Interceptor: WooCommerce stack detected, skipping all processing');
                    return $args; // SALIR INMEDIATAMENTE sin tocar nada
                }
            }
        }
        
        // 2. Detectar indicadores de WooCommerce
        $woocommerce_indicators = array(
            'woocommerce', 'WooCommerce', 'wc-', 'WC_',
            'order', 'Order', 'pedido', 'Pedido',
            '#header_wrapper', '#body_content', '#body_content_inner',
            'email-styles', 'woocommerce-email',
            'order-details', 'order_details', 'billing_address', 'shipping_address'
        );
        
        $subject = isset($args['subject']) ? $args['subject'] : '';
        $message = isset($args['message']) ? $args['message'] : '';
        
        foreach ($woocommerce_indicators as $indicator) {
            if (strpos($message, $indicator) !== false || strpos($subject, $indicator) !== false) {
                error_log('QvaClick Framework Interceptor: WooCommerce indicator detected, skipping processing');
                return $args; // SALIR INMEDIATAMENTE sin tocar nada
            }
        }
        
        // Lista de patrones de emails que NO deben ser interceptados (críticos del sistema)
        $protected_patterns = array(
            'password',
            'reset',
            'activate',
            'verification',
            'confirm',
            'security',
            'login',
            'admin',
            'wordpress',
            // Agregar protección específica para WooCommerce
            'woocommerce',
            'order',
            'checkout',
            'payment'
        );
        
        // Verificar si es un email protegido
        $subject_lower = strtolower($args['subject']);
        foreach ($protected_patterns as $pattern) {
            if (strpos($subject_lower, $pattern) !== false) {
                return $args; // No interceptar emails críticos
            }
        }
        
        // Solo procesar si el subject contiene variables sin resolver y es un email del framework
        if (isset($args['subject']) && strpos($args['subject'], '%') !== false) {
            
            // Variables comunes que pueden estar en cualquier subject
            $common_keywords = array(
                '%site_name%',
                '%admin_email%',
                '%home_url%'
            );
            
            $common_replaces = array(
                wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
                get_option('admin_email'),
                home_url()
            );
            
            $args['subject'] = str_replace($common_keywords, $common_replaces, $args['subject']);
            
            // Log para debugging
            $this->log_email_send('wp_mail_filter', 0, 0, $args['subject'], 'wp_mail_interceptor');
        }
        
        return $args;
    }
    
    /**
     * Registrar envío de email para debugging
     */
    private function log_email_send($type, $user_id, $post_id, $subject, $method) {
        // Solo log si está habilitado el debug
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_entry = array(
                'timestamp' => current_time('mysql'),
                'type' => $type,
                'user_id' => $user_id,
                'post_id' => $post_id,
                'subject' => $subject,
                'method' => $method,
                'has_variables' => (strpos($subject, '%') !== false)
            );
            
            error_log('[QvaClick Email Interceptor] ' . json_encode($log_entry));
        }
    }
    
    /**
     * Crear template base para email de proyecto si no existe
     */
    public function create_project_template_if_missing() {
        if (!class_exists('QvaClick_Email_Discovery')) {
            return;
        }
        
        $templates = QvaClick_Email_Discovery::discover_email_templates();
        $base_key = 'fl_project_published';
        
        if (!isset($templates[$base_key])) {
            // Crear template base
            $exertio_options = get_option('exertio_theme_options', array());
            
            // Obtener subject y body actual del framework
            $current_subject = fl_framework_get_options('fl_email_onproject_created_sub');
            $current_body = fl_framework_get_options('fl_email_onproject_created_email_body');
            
            if (!empty($current_subject) || !empty($current_body)) {
                // Agregar el template al sistema QvaClick
                $exertio_options[$base_key . '_switch'] = '1'; // Habilitar
                $exertio_options[$base_key . '_subject'] = $current_subject ?: '📢 Tu proyecto ha sido publicado en %site_name%';
                $exertio_options[$base_key . '_body'] = $current_body ?: 'Hola %display_name%,<br><br>Tu proyecto "%project_title%" ha sido publicado exitosamente en %site_name%.<br><br>Puedes verlo aquí: %project_link%<br><br>¡Gracias por usar nuestros servicios!';
                
                update_option('exertio_theme_options', $exertio_options);
                
                // Log de creación
                $this->log_email_send('template_created', 0, 0, $current_subject, 'auto_creation');
            }
        }
    }
    
    /**
     * Obtener estadísticas de interceptación
     */
    public function get_interception_stats() {
        $stats = array(
            'project_emails_intercepted' => get_option('qvc_project_emails_intercepted', 0),
            'wp_mail_subjects_processed' => get_option('qvc_wp_mail_subjects_processed', 0),
            'last_project_email' => get_option('qvc_last_project_email_time', 'Never'),
            'templates_auto_created' => get_option('qvc_templates_auto_created', 0)
        );
        
        return $stats;
    }
}
