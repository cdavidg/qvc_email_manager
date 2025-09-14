<?php
/**
 * Admin Email Manager Class
 * Gestiona el sistema de tickets de soporte y envío masivo de emails
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class QvaClick_Admin_Email_Manager {
    /**
     * Changelog:
     *  - 2025-09-14: Rewrote get_mass_email_recipients to use WP_User_Query/get_users
     *    with defensive checks to avoid fatal errors when building recipient lists.
     *    This addresses crashes when user meta or roles are missing and ensures
     *    invalid emails are skipped. Added logging for debugging recipient counts.
     *    Next steps: implement batching/pagination for large lists and rate limiting.
     */
    
    private static $instance = null;
    private $last_mail_error = null;
    private $last_recipient_total = 0;
    
    /**
     * Normaliza direcciones de email de QvaClick:
     * - trim espacios
     * - convierte el dominio a minúsculas
     * - elimina prefijo "www." del dominio (support@www.qvaclick.com -> support@qvaclick.com)
     */
    private function normalize_email($email) {
        if (empty($email) || !is_string($email)) {
            return $email;
        }
        $email = trim($email);
        // Separar local y dominio
        $atPos = strrpos($email, '@');
        if ($atPos === false) {
            return $email; // no es un email válido, devolver tal cual
        }
        $local = substr($email, 0, $atPos);
        $domain = strtolower(substr($email, $atPos + 1));
        if (strpos($domain, 'www.') === 0) {
            $domain = substr($domain, 4);
        }
        return $local . '@' . $domain;
    }
    
    /**
     * Obtener instancia singleton
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Devuelve el total de destinatarios calculado en la última llamada
     */
    public function get_last_recipient_total() {
        return intval($this->last_recipient_total);
    }
    
    private function __construct() {
        add_action('init', array($this, 'init'));
    }
    
    public function init() {
        // Verificar si las tablas ya están creadas
        $tables_created = get_option('qvc_admin_email_tables_created', false);
        
        if (!$tables_created) {
            $this->create_tables();
            update_option('qvc_admin_email_tables_created', true);
        }
        
        // Hooks para procesar emails entrantes de soporte (solo lectura)
        add_filter('wp_mail_from', array($this, 'set_support_from_email'));
        add_filter('wp_mail_from_name', array($this, 'set_support_from_name'));
        
        // Hook para tracking de emails (activado)
        add_action('wp_mail_succeeded', array($this, 'process_incoming_support_email'));
    }
    
    /**
     * Crear tablas de base de datos para admin emails
     */
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Verificar si las tablas ya existen
        $table_support_tickets = $wpdb->prefix . 'qvc_support_tickets';
        $table_ticket_messages = $wpdb->prefix . 'qvc_ticket_messages';
        $table_mass_emails = $wpdb->prefix . 'qvc_mass_emails';
        $table_mass_email_logs = $wpdb->prefix . 'qvc_mass_email_logs';
        
        // Solo crear tablas si no existen
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_support_tickets'") != $table_support_tickets) {
            $sql_support = "CREATE TABLE $table_support_tickets (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                ticket_id varchar(50) NOT NULL,
                user_id bigint(20) UNSIGNED NULL,
                user_email varchar(320) NOT NULL,
                user_name varchar(255) NOT NULL,
                user_type enum('freelancer','employer','admin','guest') DEFAULT 'guest',
                subject varchar(500) NOT NULL,
                status enum('open','in_progress','resolved','closed') DEFAULT 'open',
                priority enum('low','normal','high','urgent') DEFAULT 'normal',
                category varchar(100) DEFAULT 'general',
                created_at timestamp DEFAULT CURRENT_TIMESTAMP,
                updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                assigned_to bigint(20) UNSIGNED NULL,
                resolved_at timestamp NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ticket_id (ticket_id),
                KEY user_id (user_id),
                KEY user_email (user_email),
                KEY status (status),
                KEY priority (priority),
                KEY assigned_to (assigned_to)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql_support);
        }
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_ticket_messages'") != $table_ticket_messages) {
            $sql_messages = "CREATE TABLE $table_ticket_messages (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                ticket_id varchar(50) NOT NULL,
                user_id bigint(20) UNSIGNED NULL,
                user_email varchar(320) NOT NULL,
                user_name varchar(255) NOT NULL,
                user_type enum('freelancer','employer','admin','guest') DEFAULT 'guest',
                message longtext NOT NULL,
                is_admin_reply tinyint(1) DEFAULT 0,
                attachments text NULL,
                created_at timestamp DEFAULT CURRENT_TIMESTAMP,
                read_at timestamp NULL,
                PRIMARY KEY (id),
                KEY ticket_id (ticket_id),
                KEY user_id (user_id),
                KEY created_at (created_at),
                KEY is_admin_reply (is_admin_reply)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql_messages);
        }
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_mass_emails'") != $table_mass_emails) {
            $sql_mass = "CREATE TABLE $table_mass_emails (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                campaign_name varchar(255) NOT NULL,
                subject varchar(500) NOT NULL,
                content longtext NOT NULL,
                recipient_type enum('all','freelancers','employers','admins','specific_user','custom_list') NOT NULL,
                recipient_filter text NULL,
                status enum('draft','scheduled','sending','sent','failed') DEFAULT 'draft',
                scheduled_at timestamp NULL,
                sent_at timestamp NULL,
                total_recipients int(11) DEFAULT 0,
                sent_count int(11) DEFAULT 0,
                failed_count int(11) DEFAULT 0,
                created_by bigint(20) UNSIGNED NOT NULL,
                created_at timestamp DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY status (status),
                KEY recipient_type (recipient_type),
                KEY created_by (created_by),
                KEY scheduled_at (scheduled_at)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql_mass);
        }
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_mass_email_logs'") != $table_mass_email_logs) {
            $sql_mass_logs = "CREATE TABLE $table_mass_email_logs (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                mass_email_id bigint(20) UNSIGNED NOT NULL,
                recipient_email varchar(320) NOT NULL,
                recipient_user_id bigint(20) UNSIGNED NULL,
                status enum('sent','failed','bounced','opened','clicked') DEFAULT 'sent',
                sent_at timestamp DEFAULT CURRENT_TIMESTAMP,
                opened_at timestamp NULL,
                clicked_at timestamp NULL,
                error_message text NULL,
                tracking_id varchar(32),
                PRIMARY KEY (id),
                KEY mass_email_id (mass_email_id),
                KEY recipient_email (recipient_email),
                KEY status (status),
                UNIQUE KEY tracking_id (tracking_id)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql_mass_logs);
        }
        
        // Tabla para bandeja de salida general (separada de campañas)
        $table_outbox = $wpdb->prefix . 'qvc_email_outbox';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_outbox'") != $table_outbox) {
            $sql_outbox = "CREATE TABLE $table_outbox (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                email_type enum('individual','mass_campaign','support_ticket','system','test') NOT NULL,
                reference_id bigint(20) UNSIGNED NULL,
                sender_name varchar(255) NOT NULL,
                sender_email varchar(320) NOT NULL,
                recipient_email varchar(320) NOT NULL,
                recipient_name varchar(255) NULL,
                subject varchar(500) NOT NULL,
                content longtext NOT NULL,
                headers text NULL,
                status enum('pending','sent','failed','retry','cancelled') DEFAULT 'pending',
                sent_at timestamp NULL,
                created_at timestamp DEFAULT CURRENT_TIMESTAMP,
                created_by bigint(20) UNSIGNED NOT NULL,
                error_message text NULL,
                retry_count int(3) DEFAULT 0,
                tracking_id varchar(32),
                smtp_debug text NULL,
                PRIMARY KEY (id),
                KEY email_type (email_type),
                KEY status (status),
                KEY created_by (created_by),
                KEY sent_at (sent_at),
                KEY recipient_email (recipient_email),
                UNIQUE KEY tracking_id (tracking_id)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql_outbox);
        }
    }
    
    /**
     * Enviar email individual y registrarlo en bandeja de salida
     */
    public function send_individual_email($to, $subject, $content, $type = 'individual', $reference_id = null) {
        global $wpdb;
        
        $current_user_id = get_current_user_id();
        $tracking_id = wp_generate_password(32, false);
        
        // Configurar headers para mejorar entrega
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->get_support_from_name() . ' <' . $this->get_support_from_email() . '>',
            'Reply-To: ' . $this->get_support_from_email()
        );
        
        // Preparar datos para bandeja de salida
        $outbox_data = array(
            'email_type' => $type,
            'reference_id' => $reference_id,
            'sender_name' => $this->get_support_from_name(),
            'sender_email' => $this->get_support_from_email(),
            'recipient_email' => is_array($to) ? implode(', ', $to) : $to,
            'recipient_name' => $this->get_recipient_name($to),
            'subject' => $subject,
            'content' => $content,
            'headers' => json_encode($headers),
            'status' => 'pending',
            'created_by' => $current_user_id,
            'tracking_id' => $tracking_id
        );
        
        // Insertar en bandeja de salida
        $outbox_table = $wpdb->prefix . 'qvc_email_outbox';
        $outbox_id = $wpdb->insert($outbox_table, $outbox_data);
        
        if (!$outbox_id) {
            return false;
        }
        
        // Intentar envío con debug mejorado
        $smtp_debug = array();
        $smtp_debug['timestamp'] = current_time('mysql');
        $smtp_debug['to'] = $to;
        $smtp_debug['subject'] = $subject;
        
        // Verificar configuración SMTP
        $smtp_debug['smtp_config'] = $this->check_smtp_configuration();
        
        try {
            // Detect pre_wp_mail short-circuit (eg. dev/emergency MU-plugins)
            $mail_atts = array('to' => $to, 'subject' => $subject, 'message' => $content, 'headers' => $headers);
            $pre_wp = apply_filters('pre_wp_mail', null, $mail_atts);
            if ($pre_wp !== null) {
                // pre_wp_mail short-circuited the sending (likely dev/emergency mode)
                $smtp_debug['pre_wp_mail'] = $pre_wp;
                $sent = false;
                $this->last_mail_error = 'pre_wp_mail short-circuited sending (value: ' . var_export($pre_wp, true) . ')';
            } else {
                // Hook para capturar errores de wp_mail
                add_action('wp_mail_failed', array($this, 'capture_mail_error'));

                $sent = wp_mail($to, $subject, $content, $headers);
            }
            
            $status = $sent ? 'sent' : 'failed';
            $error_message = $sent ? null : ($this->last_mail_error ? $this->last_mail_error : 'wp_mail returned false - check SMTP configuration');
            
            // Actualizar bandeja de salida
            $wpdb->update(
                $outbox_table,
                array(
                    'status' => $status,
                    'sent_at' => $sent ? current_time('mysql') : null,
                    'error_message' => $error_message,
                    'smtp_debug' => json_encode($smtp_debug)
                ),
                array('id' => $outbox_id)
            );
            
            return array(
                'success' => $sent,
                'outbox_id' => $outbox_id,
                'tracking_id' => $tracking_id,
                'debug' => $smtp_debug
            );
            
        } catch (Exception $e) {
            // Error en el envío
            $wpdb->update(
                $outbox_table,
                array(
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'smtp_debug' => json_encode($smtp_debug)
                ),
                array('id' => $outbox_id)
            );
            
            return array(
                'success' => false,
                'error' => $e->getMessage(),
                'outbox_id' => $outbox_id
            );
        }
    }
    
    /**
     * Verificar configuración SMTP
     */
    private function check_smtp_configuration() {
        $config = array(
            'smtp_enabled' => false,
            'smtp_host' => '',
            'smtp_port' => '',
            'smtp_auth' => false,
            'from_email' => get_option('admin_email'),
            'from_name' => get_bloginfo('name')
        );
        
        // Verificar si hay plugin SMTP activo
        if (function_exists('wp_mail_smtp')) {
            $config['smtp_plugin'] = 'WP Mail SMTP';
            $config['smtp_enabled'] = true;
        } elseif (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            $config['phpmailer'] = 'Available';
        }
        
        // Verificar configuración del servidor
        $config['server_mail'] = function_exists('mail');
        $config['php_version'] = PHP_VERSION;
        
        return $config;
    }
    
    /**
     * Obtener nombre del destinatario
     */
    private function get_recipient_name($email) {
        if (is_array($email)) {
            $email = $email[0];
        }
        
        $user = get_user_by('email', $email);
        if ($user) {
            return $user->display_name;
        }
        
        return '';
    }
    
    /**
     * Capturar errores de wp_mail
     */
    public function capture_mail_error($wp_error) {
        $this->last_mail_error = $wp_error->get_error_message();
    }
    
    /**
     * Obtener emails de la bandeja de salida
     */
    public function get_outbox_emails($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'limit' => 50,
            'offset' => 0,
            'status' => '',
            'email_type' => '',
            'date_from' => '',
            'date_to' => '',
            'search' => ''
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $table = $wpdb->prefix . 'qvc_email_outbox';
        $where_conditions = array('1=1');
        $where_values = array();
        
        if (!empty($args['status'])) {
            $where_conditions[] = "status = %s";
            $where_values[] = $args['status'];
        }
        
        if (!empty($args['email_type'])) {
            $where_conditions[] = "email_type = %s";
            $where_values[] = $args['email_type'];
        }
        
        if (!empty($args['search'])) {
            $where_conditions[] = "(subject LIKE %s OR recipient_email LIKE %s)";
            $where_values[] = '%' . $args['search'] . '%';
            $where_values[] = '%' . $args['search'] . '%';
        }
        
        if (!empty($args['date_from'])) {
            $where_conditions[] = "created_at >= %s";
            $where_values[] = $args['date_from'];
        }
        
        if (!empty($args['date_to'])) {
            $where_conditions[] = "created_at <= %s";
            $where_values[] = $args['date_to'] . ' 23:59:59';
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $query = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $where_values[] = $args['limit'];
        $where_values[] = $args['offset'];
        
        if (!empty($where_values)) {
            $results = $wpdb->get_results($wpdb->prepare($query, $where_values));
        } else {
            $results = $wpdb->get_results($query);
        }
        
        return $results;
    }
    
    /**
     * Obtener estadísticas de la bandeja de salida
     */
    public function get_outbox_stats() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qvc_email_outbox';
        
        $stats = array(
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'sent' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'sent'"),
            'failed' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'failed'"),
            'pending' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'"),
            'today' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE DATE(created_at) = CURDATE()"),
            'this_week' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE WEEK(created_at) = WEEK(NOW())"),
            'this_month' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE MONTH(created_at) = MONTH(NOW())")
        );
        
        return $stats;
    }
    
    /**
     * Reintentar envío de email fallido
     */
    public function retry_failed_email($outbox_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qvc_email_outbox';
        $email = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND status IN ('failed', 'retry')",
            $outbox_id
        ));
        
        if (!$email) {
            return false;
        }
        
        // Incrementar contador de reintentos
        $retry_count = $email->retry_count + 1;
        
        if ($retry_count > 3) {
            // Máximo 3 reintentos
            $wpdb->update(
                $table,
                array('status' => 'cancelled', 'error_message' => 'Maximum retry attempts reached'),
                array('id' => $outbox_id)
            );
            return false;
        }
        
        // Marcar como reintento
        $wpdb->update(
            $table,
            array('status' => 'retry', 'retry_count' => $retry_count),
            array('id' => $outbox_id)
        );
        
        // Intentar envío nuevamente
        $headers = json_decode($email->headers, true);
        $sent = wp_mail($email->recipient_email, $email->subject, $email->content, $headers);
        
        $status = $sent ? 'sent' : 'failed';
        $error_message = $sent ? null : 'Retry failed - wp_mail returned false';
        
        $wpdb->update(
            $table,
            array(
                'status' => $status,
                'sent_at' => $sent ? current_time('mysql') : null,
                'error_message' => $error_message
            ),
            array('id' => $outbox_id)
        );
        
        return $sent;
    }
    
    /**
     * Generar ticket ID único
     */
    public function generate_ticket_id() {
        return 'TKT-' . strtoupper(wp_generate_password(8, false));
    }
    
    /**
     * Obtener email de soporte (from)
     */
    public function get_support_from_email() {
        $email = get_option('qvc_support_email', 'no-reply@qvaclick.com');
        $normalized = $this->normalize_email($email);
        
        // Si todavía no está configurado o es el valor por defecto viejo, usar no-reply@qvaclick.com
        if (empty($normalized) || $normalized === 'reinier.mujica@gmail.com' || $normalized === get_option('admin_email')) {
            $normalized = 'no-reply@qvaclick.com';
        }
        // Persistir corrección si cambió
        if ($normalized !== $email) {
            update_option('qvc_support_email', $normalized);
        }
        
        return $normalized;
    }
    
    /**
     * Obtener email para Reply-To (donde llegan las respuestas)
     */
    public function get_support_reply_to_email() {
        $email = get_option('qvc_support_reply_to_email', 'support@qvaclick.com');
        $normalized = $this->normalize_email($email);
        
        // Si todavía no está configurado, usar support@qvaclick.com
        if (empty($normalized)) {
            $normalized = 'support@qvaclick.com';
        }
        // Persistir corrección si cambió
        if ($normalized !== $email) {
            update_option('qvc_support_reply_to_email', $normalized);
        }
        
        return $normalized;
    }
    
    /**
     * Obtener nombre de soporte (from name)
     */
    public function get_support_from_name() {
        $name = get_option('qvc_support_from_name', 'Soporte QvaClick');
        
        // Si está vacío o es el nombre del sitio genérico, usar nombre específico
        if (empty($name) || $name === get_bloginfo('name')) {
            $name = 'Soporte QvaClick';
            update_option('qvc_support_from_name', $name);
        }
        
        return $name;
    }
    
    /**
     * Crear nuevo ticket de soporte
     */
    public function create_support_ticket($data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qvc_support_tickets';
        $ticket_id = $this->generate_ticket_id();
        
        $user_type = $this->get_user_type($data['user_id']);
        
        $result = $wpdb->insert(
            $table,
            array(
                'ticket_id' => $ticket_id,
                'user_id' => $data['user_id'],
                'user_email' => $data['user_email'],
                'user_name' => $data['user_name'],
                'user_type' => $user_type,
                'subject' => $data['subject'],
                'category' => isset($data['category']) ? $data['category'] : 'general',
                'priority' => isset($data['priority']) ? $data['priority'] : 'normal'
            ),
            array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        if ($result) {
            // Crear primer mensaje del ticket
            $this->add_ticket_message($ticket_id, array(
                'user_id' => $data['user_id'],
                'user_email' => $data['user_email'],
                'user_name' => $data['user_name'],
                'user_type' => $user_type,
                'message' => $data['message'],
                'is_admin_reply' => 0
            ));
            
            return $ticket_id;
        }
        
        return false;
    }
    
    /**
     * Agregar mensaje a ticket
     */
    public function add_ticket_message($ticket_id, $data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qvc_ticket_messages';
        
        return $wpdb->insert(
            $table,
            array(
                'ticket_id' => $ticket_id,
                'user_id' => $data['user_id'],
                'user_email' => $data['user_email'],
                'user_name' => $data['user_name'],
                'user_type' => $data['user_type'],
                'message' => $data['message'],
                'is_admin_reply' => isset($data['is_admin_reply']) ? $data['is_admin_reply'] : 0,
                'attachments' => isset($data['attachments']) ? json_encode($data['attachments']) : null
            ),
            array('%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s')
        );
    }
    
    /**
     * Obtener tipo de usuario
     */
    public function get_user_type($user_id) {
        if (!$user_id) return 'guest';
        
        $user = get_user_by('id', $user_id);
        if (!$user) return 'guest';
        
        // Detectar tipo basado en roles o meta
        if (user_can($user_id, 'manage_options')) {
            return 'admin';
        }
        
        // Verificar si es freelancer o employer según meta o roles
        $user_meta = get_user_meta($user_id, 'user_type', true);
        if ($user_meta && in_array($user_meta, ['freelancer', 'employer'])) {
            return $user_meta;
        }
        
        // También podemos verificar por roles personalizados si existen
        if (in_array('freelancer', $user->roles)) {
            return 'freelancer';
        }
        if (in_array('employer', $user->roles)) {
            return 'employer';
        }
        
        return 'guest';
    }
    
    /**
     * Obtener tickets con paginación y filtros
     */
    public function get_tickets($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'status' => '',
            'priority' => '',
            'assigned_to' => '',
            'user_type' => '',
            'page' => 1,
            'per_page' => 20,
            'orderby' => 'created_at',
            'order' => 'DESC'
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $table = $wpdb->prefix . 'qvc_support_tickets';
        $where = array('1=1');
        $where_values = array();
        
        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $where_values[] = $args['status'];
        }
        
        if (!empty($args['priority'])) {
            $where[] = 'priority = %s';
            $where_values[] = $args['priority'];
        }
        
        if (!empty($args['assigned_to'])) {
            $where[] = 'assigned_to = %d';
            $where_values[] = $args['assigned_to'];
        }
        
        if (!empty($args['user_type'])) {
            $where[] = 'user_type = %s';
            $where_values[] = $args['user_type'];
        }
        
        $where_clause = implode(' AND ', $where);
        $offset = ($args['page'] - 1) * $args['per_page'];
        
        // CORREGIDO: Incluir last_response_type en la consulta
        $sql = "SELECT *, last_response_type FROM {$table} WHERE {$where_clause} 
                ORDER BY {$args['orderby']} {$args['order']} 
                LIMIT %d OFFSET %d";
        
        $where_values[] = $args['per_page'];
        $where_values[] = $offset;
        
        if (!empty($where_values)) {
            $results = $wpdb->get_results($wpdb->prepare($sql, $where_values));
        } else {
            $results = $wpdb->get_results($sql);
        }
        
        // Obtener total para paginación
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";
        if (!empty($where_values) && count($where_values) > 2) {
            $total = $wpdb->get_var($wpdb->prepare($count_sql, array_slice($where_values, 0, -2)));
        } else {
            $total = $wpdb->get_var($count_sql);
        }
        
        return array(
            'tickets' => $results,
            'total' => $total,
            'pages' => ceil($total / $args['per_page'])
        );
    }
    
    /**
     * Obtener mensajes de un ticket
     */
    public function get_ticket_messages($ticket_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qvc_ticket_messages';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE ticket_id = %s ORDER BY created_at ASC",
            $ticket_id
        ));
    }
    
    /**
     * Obtener recipients para email masivo
     */
    public function get_mass_email_recipients($type, $filter = null) {
        global $wpdb;
        
        error_log('QVC Email Manager: get_mass_email_recipients iniciado - type: ' . $type . ', filter: ' . $filter);
        
        $recipients = array();

        // Soporte para filtros serializados en JSON desde la UI (ids, create_tickets, custom_email)
        $parsed_filter = null;
        if (!empty($filter) && is_string($filter) && ($filter[0] === '{' || $filter[0] === '[')) {
            $decoded = json_decode($filter, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $parsed_filter = $decoded;
            }
        }
        // Pagination: defaults, can be provided inside parsed_filter
        $page = 1;
        $per_page = 0; // 0 => no pagination (return all)
        if ($parsed_filter) {
            if (isset($parsed_filter['page'])) $page = max(1, intval($parsed_filter['page']));
            if (isset($parsed_filter['per_page'])) $per_page = max(0, intval($parsed_filter['per_page']));
        }

        // Helpers
        $add_user_to_recipients = function($user_obj) use (&$recipients) {
            if (!$user_obj) return;
            // WP_User or DB row
            $id = is_object($user_obj) && isset($user_obj->ID) ? $user_obj->ID : null;
            $email = is_object($user_obj) && isset($user_obj->user_email) ? $user_obj->user_email : (is_array($user_obj) && isset($user_obj['user_email']) ? $user_obj['user_email'] : null);
            $name = is_object($user_obj) && isset($user_obj->display_name) ? $user_obj->display_name : (is_array($user_obj) && isset($user_obj['display_name']) ? $user_obj['display_name'] : $email);
            if (!$email || !is_email($email)) return;
            $recipients[] = array(
                'id' => $id,
                'email' => $email,
                'name' => $name ?: $email
            );
        };

        // CASE: all users
        if ($type === 'all') {
            // Use WP_User_Query to support pagination if requested
            $args = array('orderby' => 'display_name', 'fields' => array('ID','user_email','display_name'));
            if ($per_page > 0) {
                $args['number'] = $per_page;
                $args['paged'] = $page;
            }
            $q = new WP_User_Query($args);
            $users = $q->get_results();
            foreach ($users as $u) {
                $add_user_to_recipients($u);
            }
            error_log('QVC Email Manager: get_mass_email_recipients all users count: ' . count($recipients));
            $this->last_recipient_total = count($recipients);
            return $recipients;
        }

        // CASE: selected IDs from parsed_filter
        if ($parsed_filter && isset($parsed_filter['ids']) && is_array($parsed_filter['ids']) && count($parsed_filter['ids']) > 0) {
            $ids = array_map('intval', $parsed_filter['ids']);
            // support pagination over explicit ids
            if ($per_page > 0) {
                $offset = ($page - 1) * $per_page;
                $slice = array_slice($ids, $offset, $per_page);
            } else {
                $slice = $ids;
            }
            $users = get_users(array('include' => $slice, 'fields' => array('ID','user_email','display_name')));
            foreach ($users as $u) $add_user_to_recipients($u);
            // Añadir correo custom si existe
            if (!empty($parsed_filter['custom_email']) && is_email($parsed_filter['custom_email'])) {
                $add_user_to_recipients(array('user_email' => $parsed_filter['custom_email'], 'display_name' => $parsed_filter['custom_email']));
            }
            error_log('QVC Email Manager: get_mass_email_recipients parsed ids count: ' . count($recipients));
            $this->last_recipient_total = count($recipients);
            return $recipients;
        }

        // CASE: admins
        if ($type === 'admins') {
            $args = array('role__in' => array('Administrator','administrator'), 'fields' => array('ID','user_email','display_name'));
            if ($per_page > 0) { $args['number'] = $per_page; $args['paged'] = $page; }
            $q = new WP_User_Query($args);
            $users = $q->get_results();
            // Fallback: also check capability meta if role not set
            if (empty($users)) {
                $args2 = array('meta_key' => $wpdb->prefix . 'capabilities', 'fields' => array('ID','user_email','display_name'));
                if ($per_page > 0) { $args2['number'] = $per_page; $args2['paged'] = $page; }
                $q2 = new WP_User_Query($args2);
                $users = $q2->get_results();
            }
            foreach ($users as $u) $add_user_to_recipients($u);
            error_log('QVC Email Manager: get_mass_email_recipients admins count: ' . count($recipients));
            $this->last_recipient_total = count($recipients);
            return $recipients;
        }

        // CASE: freelancers / employers - combine meta user_type and role lookup
        if ($type === 'freelancers' || $type === 'employers') {
            $usertype = $type === 'freelancers' ? 'freelancer' : 'employer';

            $found_ids = array();

            // 1) Users with user_type meta
            $q1 = new WP_User_Query(array(
                'meta_key' => 'user_type',
                'meta_value' => $usertype,
                'fields' => array('ID','user_email','display_name'),
                'number' => -1
            ));
            $users_meta = $q1->get_results();
            if (!empty($users_meta)) {
                foreach ($users_meta as $u) {
                    $found_ids[$u->ID] = $u;
                }
            }

            // 1b) Also consider _active_profile meta used by Exertio MU plugin (values '1' => employer, '2' => freelancer)
            if (defined('QVC_EXERTIO_META_KEY')) {
                $raw_value = $usertype === 'freelancer' ? QVC_EXERTIO_FREELANCER_VALUE : QVC_EXERTIO_EMPLOYER_VALUE;
                $q1b = new WP_User_Query(array(
                    'meta_key' => QVC_EXERTIO_META_KEY,
                    'meta_value' => $raw_value,
                    'fields' => array('ID','user_email','display_name'),
                    'number' => -1
                ));
                $users_exertio = $q1b->get_results();
                if (!empty($users_exertio)) {
                    foreach ($users_exertio as $u) {
                        $found_ids[$u->ID] = $u;
                    }
                }
            }

            // 2) Users with role named freelancer/employer (try both lowercase and ucfirst)
            $role_name = rtrim($usertype, 's'); // 'freelancers' -> 'freelancer'
            // Prefer resolved slugs from Exertio MU helper if available
            if (function_exists('qvc_detect_role_slugs')) {
                $resolved = qvc_detect_role_slugs();
                $role_candidates = array();
                if (!empty($resolved[$role_name])) $role_candidates[] = $resolved[$role_name];
                // always include plain slug variants as fallback
                $role_candidates[] = $role_name;
                $role_candidates[] = ucfirst($role_name);
                $role_candidates = array_values(array_unique(array_filter($role_candidates)));
            } else {
                $role_candidates = array($role_name, ucfirst($role_name));
            }

            $q2 = new WP_User_Query(array(
                'role__in' => $role_candidates,
                'fields' => array('ID','user_email','display_name'),
                'number' => -1
            ));
            $users_role = $q2->get_results();
            if (!empty($users_role)) {
                foreach ($users_role as $u) {
                    $found_ids[$u->ID] = $u;
                }
            }

            // 3) As a last resort, attempt a broader meta/capabilities lookup
            if (empty($found_ids)) {
                $like = '%' . $wpdb->esc_like($role_name) . '%';
                $meta_key_caps = $wpdb->prefix . 'capabilities';
                $meta_key_user_type = 'user_type';

                $conditions = array();
                $values = array();

                // Prefer checking user_type meta
                $conditions[] = "(m.meta_key = %s AND m.meta_value = %s)";
                $values[] = $meta_key_user_type;
                $values[] = $usertype;

                // If Exertio meta key exists, include it
                if (defined('QVC_EXERTIO_META_KEY')) {
                    $conditions[] = "(m.meta_key = %s AND m.meta_value = %s)";
                    $values[] = QVC_EXERTIO_META_KEY;
                    $values[] = ($usertype === 'freelancer' ? QVC_EXERTIO_FREELANCER_VALUE : QVC_EXERTIO_EMPLOYER_VALUE);
                }

                // Finally search capabilities serialized meta for role slug
                $conditions[] = "(m.meta_key = %s AND m.meta_value LIKE %s)";
                $values[] = $meta_key_caps;
                $values[] = $like;

                $where_sql = implode(' OR ', $conditions);
                array_unshift($values, $where_sql);

                // Build prepared SQL dynamic
                $sql = "SELECT u.ID, u.user_email, u.display_name FROM {$wpdb->users} u JOIN {$wpdb->usermeta} m ON m.user_id = u.ID WHERE " . $where_sql . " GROUP BY u.ID";
                // Prepare with values
                $prepared = $wpdb->prepare($sql, array_slice($values, 1));
                $rows = $wpdb->get_results($prepared);
                if (!empty($rows)) {
                    foreach ($rows as $r) $found_ids[$r->ID] = $r;
                }
            }

            foreach ($found_ids as $u) $add_user_to_recipients($u);
            error_log('QVC Email Manager: get_mass_email_recipients ' . $type . ' count: ' . count($recipients));
            $this->last_recipient_total = count($recipients);
            return $recipients;
        }

        // CASE: specific_user
        if ($type === 'specific_user' && $filter) {
            // Si el filter es JSON ya lo manejamos arriba
            if (is_numeric($filter)) {
                $user = get_user_by('id', intval($filter));
                $add_user_to_recipients($user);
            } elseif (is_email($filter)) {
                $user = get_user_by('email', $filter);
                $add_user_to_recipients($user);
            } else {
                $q = new WP_User_Query(array(
                    'search' => '*' . $filter . '*',
                    'search_columns' => array('display_name','user_login','user_email'),
                    'number' => 10,
                    'fields' => array('ID','user_email','display_name')
                ));
                $users = $q->get_results();
                if (!empty($users)) {
                    foreach ($users as $u) $add_user_to_recipients($u);
                }
            }
            error_log('QVC Email Manager: get_mass_email_recipients specific_user count: ' . count($recipients));
            $this->last_recipient_total = count($recipients);
            return $recipients;
        }

        // CASE: custom_list - comma separated emails
        if ($type === 'custom_list' && $filter) {
            $emails = array_map('trim', explode(',', $filter));
            foreach ($emails as $email) {
                if (!is_email($email)) continue;
                $user = get_user_by('email', $email);
                $add_user_to_recipients($user ?: array('user_email' => $email, 'display_name' => $email));
            }
            error_log('QVC Email Manager: get_mass_email_recipients custom_list count: ' . count($recipients));
            $this->last_recipient_total = count($recipients);
            return $recipients;
        }

        // Default: return empty recipients and log
    error_log('QVC Email Manager: get_mass_email_recipients - unknown type or no recipients found for type: ' . $type);
    $this->last_recipient_total = 0;
    return $recipients;
    }
    
    /**
     * Crear campaña de email masivo
     */
    public function create_mass_email($data) {
        global $wpdb;
        
        error_log('QVC Email Manager: create_mass_email iniciado');
        error_log('QVC Email Manager: create_mass_email data - ' . print_r($data, true));
        
        $table = $wpdb->prefix . 'qvc_mass_emails';
        
        // Obtener recipients para calcular total
        $recipients = $this->get_mass_email_recipients($data['recipient_type'], $data['recipient_filter']);
        error_log('QVC Email Manager: Recipients encontrados: ' . count($recipients));
        
        $result = $wpdb->insert(
            $table,
            array(
                'campaign_name' => $data['campaign_name'],
                'subject' => $data['subject'],
                'content' => $data['content'],
                'recipient_type' => $data['recipient_type'],
                'recipient_filter' => $data['recipient_filter'],
                'status' => isset($data['status']) ? $data['status'] : 'draft',
                'scheduled_at' => isset($data['scheduled_at']) ? $data['scheduled_at'] : null,
                'total_recipients' => count($recipients),
                'created_by' => get_current_user_id()
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d')
        );
        
        if ($result) {
            $campaign_id = $wpdb->insert_id;
            error_log('QVC Email Manager: Campaña creada exitosamente con ID: ' . $campaign_id);
            return $campaign_id;
        } else {
            error_log('QVC Email Manager: Error al crear campaña - ' . $wpdb->last_error);
        }
        
        return false;
    }
    
    /**
     * Enviar email masivo
     */
    public function send_mass_email($mass_email_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qvc_mass_emails';
        $log_table = $wpdb->prefix . 'qvc_mass_email_logs';
        
        $mass_email = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $mass_email_id
        ));
        
        if (!$mass_email) {
            return false;
        }
        
        // Actualizar status a enviando
        $wpdb->update($table, array('status' => 'sending'), array('id' => $mass_email_id));
        
        // Parse recipient_filter to detect per-recipient create-ticket flags
        $create_ticket_ids = array();
        $custom_email_flag = null;
        if (!empty($mass_email->recipient_filter) && (strpos($mass_email->recipient_filter, '{') === 0 || strpos($mass_email->recipient_filter, '[') === 0)) {
            $decoded = json_decode($mass_email->recipient_filter, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (!empty($decoded['create_ticket_user_ids']) && is_array($decoded['create_ticket_user_ids'])) {
                    $create_ticket_ids = array_map('intval', $decoded['create_ticket_user_ids']);
                }
                if (!empty($decoded['custom_email'])) {
                    $custom_email_flag = $decoded['custom_email'];
                }
            }
        }

        $recipients = $this->get_mass_email_recipients($mass_email->recipient_type, $mass_email->recipient_filter);
        $sent_count = 0;
        $failed_count = 0;
        
        // Log para debugging
        error_log('QVC Email Manager: Starting mass email send. Campaign ID: ' . $mass_email_id . ', Recipients count: ' . count($recipients));
        
        foreach ($recipients as $recipient) {
            $tracking_id = wp_generate_password(32, false);

            // Personalizar contenido
            $subject = $this->personalize_content($mass_email->subject, $recipient);
            $content = $this->personalize_content($mass_email->content, $recipient);

            // Aplicar plantilla base si es necesario
            if (class_exists('QvaClick_Base_Template_Manager')) {
                $content = QvaClick_Base_Template_Manager::apply_to_html($content);
            }

            $headers = array('Content-Type: text/html; charset=UTF-8');

            // NUEVO: Registrar en bandeja de salida ANTES del envío
            $outbox_table = $wpdb->prefix . 'qvc_email_outbox';
            $outbox_data = array(
                'email_type' => 'mass_campaign',
                'reference_id' => $mass_email_id, // Para vincular con la campaña
                'sender_name' => $this->get_support_from_name(),
                'sender_email' => $this->get_support_from_email(),
                'recipient_email' => $recipient['email'],
                'recipient_name' => $recipient['name'],
                'subject' => $subject,
                'content' => $content,
                'headers' => json_encode($headers),
                'status' => 'pending',
                'created_by' => get_current_user_id(),
                'tracking_id' => $tracking_id
            );

            $outbox_result = $wpdb->insert($outbox_table, $outbox_data);
            if ($outbox_result === false) {
                error_log('QVC Email Manager: Error inserting into outbox - ' . $wpdb->last_error);
                continue; // Saltar este destinatario si no se pudo registrar
            }
            $outbox_id = $wpdb->insert_id;

            // Preparar debug por destinatario
            $smtp_debug = array();
            $smtp_debug['timestamp'] = current_time('mysql');
            $smtp_debug['to'] = $recipient['email'];
            $smtp_debug['subject'] = $subject;
            $smtp_debug['smtp_config'] = $this->check_smtp_configuration();

            // Reiniciar último error conocido
            $this->last_mail_error = null;

            // Enviar email
            // Detect pre_wp_mail short-circuit before attempting send
            $mail_atts = array('to' => $recipient['email'], 'subject' => $subject, 'message' => $content, 'headers' => $headers);
            $pre_wp = apply_filters('pre_wp_mail', null, $mail_atts);
            if ($pre_wp !== null) {
                $smtp_debug['pre_wp_mail'] = $pre_wp;
                $sent = false;
                // Capture a descriptive error
                $error_message = 'pre_wp_mail short-circuited sending (value: ' . var_export($pre_wp, true) . ')';
            } else {
                // Attach temporary wp_mail_failed listener to capture PHPMailer errors
                add_action('wp_mail_failed', array($this, 'capture_mail_error'));
                $sent = wp_mail($recipient['email'], $subject, $content, $headers);
                // Detach listener
                remove_action('wp_mail_failed', array($this, 'capture_mail_error'));
            }

            // Build a useful error message
            if ($sent) {
                $error_message = null;
            } else {
                if (empty($error_message)) {
                    $error_message = $this->last_mail_error ? $this->last_mail_error : 'wp_mail returned false - check SMTP configuration';
                }
            }

            // Actualizar estado en bandeja de salida, persistir smtp_debug
            $status = $sent ? 'sent' : 'failed';
            $wpdb->update(
                $outbox_table,
                array(
                    'status' => $status,
                    'sent_at' => $sent ? current_time('mysql') : null,
                    'error_message' => $error_message,
                    'smtp_debug' => json_encode($smtp_debug)
                ),
                array('id' => $outbox_id)
            );

            // Log del envío (mantener sistema existente)
            $log_status = $sent ? 'sent' : 'failed';
            $wpdb->insert(
                $log_table,
                array(
                    'mass_email_id' => $mass_email_id,
                    'recipient_email' => $recipient['email'],
                    'recipient_user_id' => $recipient['id'],
                    'status' => $log_status,
                    'tracking_id' => $tracking_id,
                    'outbox_id' => $outbox_id, // Vincular con bandeja de salida
                    'error_message' => $error_message
                )
            );

            if ($sent) {
                $sent_count++;

                // Si este destinatario está marcado para crear ticket, crear uno
                if (!empty($recipient['id']) && in_array(intval($recipient['id']), $create_ticket_ids)) {
                    // Construir datos del ticket
                    $ticket_data = array(
                        'user_id' => intval($recipient['id']),
                        'user_email' => $recipient['email'],
                        'user_name' => $recipient['name'],
                        'subject' => 'Campaña: ' . $mass_email->campaign_name,
                        'message' => 'Se ha enviado un email de la campaña "' . $mass_email->campaign_name . '" al usuario.\n\nContenido:\n' . strip_tags($content),
                        'category' => 'campaign',
                        'priority' => 'normal'
                    );

                    $ticket_id = $this->create_support_ticket($ticket_data);
                    if ($ticket_id) {
                        error_log('QVC Email Manager: Created ticket ' . $ticket_id . ' for user ' . $recipient['email']);
                    } else {
                        error_log('QVC Email Manager: Failed to create ticket for user ' . $recipient['email']);
                    }
                }
            } else {
                $failed_count++;
            }
        }
        
        // Actualizar estadísticas finales. Determinar estado final según resultados
        if ($failed_count === 0 && $sent_count > 0) {
            $final_status = 'sent';
            $sent_at = current_time('mysql');
        } elseif ($sent_count === 0) {
            $final_status = 'failed';
            $sent_at = null;
        } else {
            $final_status = 'partial';
            $sent_at = current_time('mysql');
        }

        $wpdb->update(
            $table,
            array(
                'status' => $final_status,
                'sent_at' => $sent_at,
                'sent_count' => $sent_count,
                'failed_count' => $failed_count
            ),
            array('id' => $mass_email_id)
        );
        
        return array(
            'sent' => $sent_count,
            'failed' => $failed_count,
            'total' => count($recipients)
        );
    }
    
    /**
     * Personalizar contenido con variables del usuario
     */
    private function personalize_content($content, $recipient) {
        $replacements = array(
            '{{user_name}}' => $recipient['name'],
            '{{user_email}}' => $recipient['email'],
            '{{site_name}}' => get_bloginfo('name'),
            '{{site_url}}' => home_url()
        );
        
        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }
    
    /**
     * Procesar email entrante de soporte (hook para futuras integraciones)
     */
    public function process_incoming_support_email($mail_data) {
        // Aquí se puede implementar lógica para procesar emails entrantes
        // Por ejemplo, crear tickets automáticamente desde emails recibidos
    }
    
    /**
     * Configurar email de soporte
     */
    public function set_support_from_email($email) {
        return get_option('qvc_support_email', $email);
    }
    
    public function set_support_from_name($name) {
        return get_option('qvc_support_from_name', $name);
    }
    
    /**
     * Generar pixel de tracking para apertura de email
     */
    public function generate_tracking_pixel($tracking_id) {
        $tracking_url = home_url("/wp-admin/admin-ajax.php?action=qvc_track_email_open&tid=" . $tracking_id);
        return '<img src="' . $tracking_url . '" width="1" height="1" style="display:none;" alt="">';
    }
    
    /**
     * Generar URL de tracking para enlaces
     */
    public function generate_tracking_url($original_url, $tracking_id) {
        return add_query_arg(array(
            'action' => 'qvc_track_email_click',
            'tid' => $tracking_id,
            'url' => urlencode($original_url)
        ), admin_url('admin-ajax.php'));
    }
    
    /**
     * Procesar tracking de apertura de email
     */
    public function track_email_open($tracking_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qvc_mass_email_logs';
        $wpdb->update(
            $table,
            array(
                'status' => 'opened',
                'opened_at' => current_time('mysql')
            ),
            array('tracking_id' => $tracking_id),
            array('%s', '%s'),
            array('%s')
        );
        
        // Retornar pixel transparente
        header('Content-Type: image/png');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Pixel transparente 1x1
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        exit;
    }
    
    /**
     * Procesar tracking de clic en enlaces
     */
    public function track_email_click($tracking_id, $url) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qvc_mass_email_logs';
        $wpdb->update(
            $table,
            array(
                'status' => 'clicked',
                'clicked_at' => current_time('mysql')
            ),
            array('tracking_id' => $tracking_id),
            array('%s', '%s'),
            array('%s')
        );
        
        // Redirigir al URL original
        wp_redirect(urldecode($url));
        exit;
    }
    
    /**
     * Capturar emails del formulario de contacto
     */
    public function capture_contact_form_email($form_data) {
        // Hook para Contact Form 7, Gravity Forms, etc.
        if (isset($form_data['your-email']) && isset($form_data['your-message'])) {
            $ticket_data = array(
                'user_id' => 0, // Usuario invitado
                'user_email' => sanitize_email($form_data['your-email']),
                'user_name' => sanitize_text_field($form_data['your-name'] ?? 'Invitado'),
                'subject' => sanitize_text_field($form_data['your-subject'] ?? 'Mensaje desde formulario de contacto'),
                'message' => sanitize_textarea_field($form_data['your-message']),
                'category' => 'contact_form',
                'priority' => 'normal'
            );
            
            $this->create_support_ticket($ticket_data);
        }
    }
    
    /**
     * Webhook para recibir emails de support@qvaclick.com
     */
    public function process_incoming_email_webhook() {
        // Verificar que sea una request POST válida
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_die('Method not allowed', 405);
        }
        
        // Verificar token de seguridad
        $token = get_option('qvc_webhook_token', '');
        if (empty($token) || $_GET['token'] !== $token) {
            wp_die('Unauthorized', 401);
        }
        
        $input = file_get_contents('php://input');
        $email_data = json_decode($input, true);
        
        if (!$email_data || !isset($email_data['from']) || !isset($email_data['subject'])) {
            wp_die('Invalid data', 400);
        }
        
        // Crear ticket desde email entrante
        $ticket_data = array(
            'user_id' => 0,
            'user_email' => sanitize_email($email_data['from']),
            'user_name' => sanitize_text_field($email_data['from_name'] ?? $email_data['from']),
            'subject' => sanitize_text_field($email_data['subject']),
            'message' => wp_kses_post($email_data['body'] ?? $email_data['text']),
            'category' => 'email_support',
            'priority' => 'normal'
        );
        
        $ticket_id = $this->create_support_ticket($ticket_data);
        
        wp_send_json_success(array('ticket_id' => $ticket_id));
    }
    
    /**
     * Configurar webhook automáticamente
     */
    public function setup_email_webhook() {
        $token = get_option('qvc_webhook_token');
        if (empty($token)) {
            $token = wp_generate_password(32, false);
            update_option('qvc_webhook_token', $token);
        }
        
        $webhook_url = home_url("/wp-admin/admin-ajax.php?action=qvc_incoming_email&token=" . $token);
        
        return array(
            'webhook_url' => $webhook_url,
            'token' => $token,
            'instructions' => 'Configure este webhook en su proveedor de email para recibir emails automáticamente'
        );
    }

    /**
     * Buscar usuarios para selección específica
     */
    public function search_users($search_term) {
        global $wpdb;
        
        $search_term = '%' . $wpdb->esc_like($search_term) . '%';
        
        $users = $wpdb->get_results($wpdb->prepare("
            SELECT ID, user_login, user_email, display_name 
            FROM {$wpdb->users} 
            WHERE user_login LIKE %s 
               OR user_email LIKE %s 
               OR display_name LIKE %s
            ORDER BY display_name, user_login
            LIMIT 20
        ", $search_term, $search_term, $search_term));
        
        $results = array();
        foreach ($users as $user) {
            $user_type = $this->get_user_type($user->ID);
            $results[] = array(
                'id' => $user->ID,
                'name' => $user->display_name ?: $user->user_login,
                'email' => $user->user_email,
                'type' => $user_type
            );
        }
        
        return $results;
    }

    /**
     * Obtener detalles de una campaña
     */
    public function get_campaign_details($campaign_id) {
        global $wpdb;
        
        $campaign = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}qvc_mass_emails 
            WHERE id = %d
        ", $campaign_id), ARRAY_A);
        
        if ($campaign) {
            // Obtener estadísticas adicionales
            $stats = $wpdb->get_row($wpdb->prepare("
                SELECT 
                    COUNT(*) as total_sent,
                    SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened,
                    SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked
                FROM {$wpdb->prefix}qvc_mass_email_logs 
                WHERE mass_email_id = %d
            ", $campaign_id), ARRAY_A);
            
            $campaign['stats'] = $stats;
        }
        
        return $campaign;
    }

    /**
     * Duplicar una campaña
     */
    public function duplicate_campaign($campaign_id) {
        global $wpdb;
        
        $campaign = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}qvc_mass_emails 
            WHERE id = %d
        ", $campaign_id), ARRAY_A);
        
        if (!$campaign) {
            return false;
        }
        
        // Crear nueva campaña con datos duplicados
        unset($campaign['id']);
        $campaign['subject'] = '[COPIA] ' . $campaign['subject'];
        $campaign['status'] = 'draft';
        $campaign['sent_at'] = null;
        $campaign['created_at'] = current_time('mysql');
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'qvc_mass_emails',
            $campaign
        );
        
        return $result !== false;
    }

    /**
     * Eliminar una campaña
     */
    public function delete_campaign($campaign_id) {
        global $wpdb;
        
        // Primero eliminar los logs relacionados
        $wpdb->delete(
            $wpdb->prefix . 'qvc_mass_email_logs',
            array('mass_email_id' => $campaign_id),
            array('%d')
        );
        
        // Luego eliminar la campaña
        $result = $wpdb->delete(
            $wpdb->prefix . 'qvc_mass_emails',
            array('id' => $campaign_id),
            array('%d')
        );
        
        return $result !== false;
    }

    /**
     * Obtener lista de campañas
     */
    public function get_campaigns($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'limit' => 20,
            'offset' => 0,
            'status' => null,
            'order_by' => 'created_at',
            'order' => 'DESC'
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $where = '';
        $where_values = array();
        
        if ($args['status']) {
            $where = ' WHERE status = %s';
            $where_values[] = $args['status'];
        }
        
        $campaigns = $wpdb->get_results($wpdb->prepare("
            SELECT 
                me.*,
                COUNT(mel.id) as recipient_count,
                SUM(CASE WHEN mel.opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened_count,
                SUM(CASE WHEN mel.clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked_count
            FROM {$wpdb->prefix}qvc_mass_emails me
            LEFT JOIN {$wpdb->prefix}qvc_mass_email_logs mel ON me.id = mel.mass_email_id
            {$where}
            GROUP BY me.id
            ORDER BY {$args['order_by']} {$args['order']}
            LIMIT %d OFFSET %d
        ", array_merge($where_values, array($args['limit'], $args['offset']))), ARRAY_A);
        
        return $campaigns;
    }
}
