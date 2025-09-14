<?php
/**
 * QvaClick Ticket Chronological Order
 * Normaliza timestamps en UTC para mensajes de ticket y ordena cronológicamente.
 * Integrado directamente en el plugin Email Manager V1.
 * 
 * @version 1.0.0
 * @author QvaClick
 */

if (!defined('ABSPATH')) exit;

class QvaClick_Ticket_Chronological_Order {
    
    /**
     * Clave meta utilizada para el timestamp canónico en UTC (segundos UNIX)
     */
    const UTC_TIMESTAMP_META = 'qvc_ts_utc';
    
    /**
     * Tablas que representan mensajes de tickets
     */
    private $ticket_tables;
    
    /**
     * Instancia singleton
     */
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->ticket_tables = array(
            'support_tickets' => $GLOBALS['wpdb']->prefix . 'qvc_support_tickets',
            'ticket_messages' => $GLOBALS['wpdb']->prefix . 'qvc_ticket_messages'
        );
        
        $this->init_hooks();
    }
    
    /**
     * Inicializar hooks de WordPress
     */
    private function init_hooks() {
        // Hook para cuando se crea/actualiza un ticket
        add_action('qvc_ticket_created', array($this, 'process_ticket_timestamp'), 10, 2);
        add_action('qvc_ticket_updated', array($this, 'process_ticket_timestamp'), 10, 2);
        
        // Hook para cuando se crea un mensaje de ticket
        add_action('qvc_ticket_message_created', array($this, 'process_message_timestamp'), 10, 2);
        
        // Filtros para modificar consultas y asegurar orden cronológico
        add_filter('qvc_get_ticket_messages_orderby', array($this, 'filter_messages_orderby'), 10, 2);
        add_filter('qvc_get_tickets_orderby', array($this, 'filter_tickets_orderby'), 10, 2);
        
        // Comando WP-CLI
        if (defined('WP_CLI') && constant('WP_CLI')) {
            $this->register_cli_command();
        }
    }
    
    /**
     * Procesar timestamp para un ticket
     */
    public function process_ticket_timestamp($ticket_id, $ticket_data = array()) {
        global $wpdb;
        
        // Obtener información del ticket
        $ticket = $this->get_ticket_data($ticket_id);
        if (!$ticket) return;
        
        $utc_timestamp = $this->compute_utc_timestamp_for_ticket($ticket, $ticket_data);
        
        // Guardar timestamp UTC normalizado
        $this->save_utc_timestamp('ticket', $ticket_id, $utc_timestamp);
        
        error_log("QvaClick Chrono: Timestamp UTC procesado para ticket #{$ticket_id}: " . $utc_timestamp);
    }
    
    /**
     * Procesar timestamp para un mensaje de ticket
     */
    public function process_message_timestamp($message_id, $message_data = array()) {
        global $wpdb;
        
        // Obtener información del mensaje
        $message = $this->get_message_data($message_id);
        if (!$message) return;
        
        $utc_timestamp = $this->compute_utc_timestamp_for_message($message, $message_data);
        
        // Guardar timestamp UTC normalizado
        $this->save_utc_timestamp('message', $message_id, $utc_timestamp);
        
        error_log("QvaClick Chrono: Timestamp UTC procesado para mensaje #{$message_id}: " . $utc_timestamp);
    }
    
    /**
     * Obtiene un timestamp UTC confiable para un ticket
     * Orden de preferencia:
     * 1) email_udate del IMAP
     * 2) email_date_raw parseado
     * 3) created_at del registro
     * 4) Timestamp actual
     */
    private function compute_utc_timestamp_for_ticket($ticket, $extra_data = array()) {
        // 1) Timestamp directo del email (si viene de IMAP)
        if (isset($extra_data['email_udate']) && is_numeric($extra_data['email_udate'])) {
            return (int) $extra_data['email_udate'];
        }
        
        // 2) Cabecera Date: del email
        if (isset($extra_data['email_date_raw']) && !empty($extra_data['email_date_raw'])) {
            try {
                $dt = new DateTimeImmutable($extra_data['email_date_raw']);
                return $dt->getTimestamp();
            } catch (Exception $e) {
                error_log("QvaClick Chrono: Error parseando email_date_raw: " . $e->getMessage());
            }
        }
        
        // 3) Campo created_at del ticket
        if (isset($ticket->created_at) && !empty($ticket->created_at)) {
            // Convertir a UTC si no lo está ya
            return $this->convert_mysql_datetime_to_utc($ticket->created_at);
        }
        
        // 4) Fallback: timestamp actual
        return time();
    }
    
    /**
     * Obtiene un timestamp UTC confiable para un mensaje
     */
    private function compute_utc_timestamp_for_message($message, $extra_data = array()) {
        // Misma lógica que para tickets
        return $this->compute_utc_timestamp_for_ticket($message, $extra_data);
    }
    
    /**
     * Convierte una fecha MySQL a timestamp UTC
     */
    private function convert_mysql_datetime_to_utc($mysql_datetime) {
        try {
            // Asumir que la fecha está en la zona horaria de WordPress
            $wp_timezone = get_option('timezone_string') ?: 'UTC';
            
            $dt = new DateTime($mysql_datetime, new DateTimeZone($wp_timezone));
            $dt->setTimezone(new DateTimeZone('UTC'));
            
            return $dt->getTimestamp();
        } catch (Exception $e) {
            error_log("QvaClick Chrono: Error convirtiendo fecha MySQL: " . $e->getMessage());
            return strtotime($mysql_datetime); // Fallback básico
        }
    }
    
    /**
     * Guardar timestamp UTC normalizado
     */
    private function save_utc_timestamp($type, $record_id, $timestamp) {
        global $wpdb;
        
        if ($type === 'ticket') {
            $table = $this->ticket_tables['support_tickets'];
            $id_field = 'id';
        } else {
            $table = $this->ticket_tables['ticket_messages'];
            $id_field = 'id';
        }
        
        // Agregar/actualizar columna UTC si no existe
        $this->ensure_utc_column_exists($table);
        
        // Actualizar el timestamp UTC
        $result = $wpdb->update(
            $table,
            array('utc_timestamp' => $timestamp),
            array($id_field => $record_id),
            array('%d'),
            array('%d')
        );
        
        if ($result === false) {
            error_log("QvaClick Chrono: Error guardando timestamp UTC para {$type} #{$record_id}: " . $wpdb->last_error);
        }
    }
    
    /**
     * Asegurar que existe la columna utc_timestamp en la tabla
     */
    private function ensure_utc_column_exists($table) {
        global $wpdb;
        
        static $columns_checked = array();
        
        if (in_array($table, $columns_checked)) {
            return; // Ya verificada
        }
        
        // Verificar si la columna existe
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'utc_timestamp'");
        
        if (empty($column_exists)) {
            // Agregar la columna
            $sql = "ALTER TABLE {$table} ADD COLUMN utc_timestamp INT(11) DEFAULT NULL, ADD INDEX idx_utc_timestamp (utc_timestamp)";
            $result = $wpdb->query($sql);
            
            if ($result !== false) {
                error_log("QvaClick Chrono: Columna utc_timestamp agregada a {$table}");
            } else {
                error_log("QvaClick Chrono: Error agregando columna utc_timestamp a {$table}: " . $wpdb->last_error);
            }
        }
        
        $columns_checked[] = $table;
    }
    
    /**
     * Filtrar ORDER BY para mensajes de tickets
     */
    public function filter_messages_orderby($orderby, $args) {
        // Asegurar orden cronológico por UTC timestamp
        return "utc_timestamp ASC, id ASC";
    }
    
    /**
     * Filtrar ORDER BY para tickets
     */
    public function filter_tickets_orderby($orderby, $args) {
        // Asegurar orden cronológico por UTC timestamp
        return "utc_timestamp ASC, id ASC";
    }
    
    /**
     * Obtener datos de un ticket
     */
    private function get_ticket_data($ticket_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->ticket_tables['support_tickets']} WHERE id = %d",
            $ticket_id
        ));
    }
    
    /**
     * Obtener datos de un mensaje
     */
    private function get_message_data($message_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->ticket_tables['ticket_messages']} WHERE id = %d",
            $message_id
        ));
    }
    
    /**
     * Helper: formatear timestamp UTC a zona horaria local
     */
    public static function format_local_time($utc_timestamp, $format = 'Y-m-d H:i:s') {
        if (empty($utc_timestamp)) return '';
        
        // Usar date() con zona horaria si wp_date no está disponible
        if (function_exists('wp_date')) {
            return wp_date($format, $utc_timestamp);
        } else {
            return date($format, $utc_timestamp);
        }
    }
    
    /**
     * Helper: diferencia de tiempo en formato humano
     */
    public static function human_time_diff_utc($utc_timestamp) {
        if (empty($utc_timestamp)) return '';
        
        $now_utc = time();
        
        // Usar human_time_diff si está disponible, sino fallback básico
        if (function_exists('human_time_diff')) {
            $ago_text = function_exists('__') ? __('ago') : 'ago';
            return human_time_diff($utc_timestamp, $now_utc) . ' ' . $ago_text;
        } else {
            $diff = $now_utc - $utc_timestamp;
            $units = array(
                'año' => 31536000,
                'mes' => 2592000,
                'día' => 86400,
                'hora' => 3600,
                'minuto' => 60,
                'segundo' => 1
            );
            
            foreach ($units as $name => $seconds) {
                if ($diff >= $seconds) {
                    $count = floor($diff / $seconds);
                    return $count . ' ' . ($count == 1 ? $name : $name . 's') . ' ago';
                }
            }
            return 'ahora';
        }
    }
    
    /**
     * Backfill: procesar registros existentes
     */
    public function backfill_existing_records() {
        global $wpdb;
        
        $updated = 0;
        
        // Procesar tickets existentes
        $tickets = $wpdb->get_results("SELECT id, created_at FROM {$this->ticket_tables['support_tickets']} WHERE utc_timestamp IS NULL OR utc_timestamp = 0");
        
        foreach ($tickets as $ticket) {
            $utc_timestamp = $this->convert_mysql_datetime_to_utc($ticket->created_at);
            $this->save_utc_timestamp('ticket', $ticket->id, $utc_timestamp);
            $updated++;
        }
        
        // Procesar mensajes existentes
        $messages = $wpdb->get_results("SELECT id, created_at FROM {$this->ticket_tables['ticket_messages']} WHERE utc_timestamp IS NULL OR utc_timestamp = 0");
        
        foreach ($messages as $message) {
            $utc_timestamp = $this->convert_mysql_datetime_to_utc($message->created_at);
            $this->save_utc_timestamp('message', $message->id, $utc_timestamp);
            $updated++;
        }
        
        return $updated;
    }
    
    /**
     * Registrar comando WP-CLI
     */
    private function register_cli_command() {
        if (!class_exists('WP_CLI')) return;
        
        if (class_exists('WP_CLI')) {
            call_user_func(array('WP_CLI', 'add_command'), 'qvc backfill-chrono-timestamps', array($this, 'cli_backfill_timestamps'));
        }
    }
    
    /**
     * Comando WP-CLI para backfill
     */
    public function cli_backfill_timestamps($args, $assoc_args) {
        if (class_exists('WP_CLI')) {
            call_user_func(array('WP_CLI', 'line'), 'Iniciando backfill de timestamps cronológicos...');
            
            $updated = $this->backfill_existing_records();
            
            call_user_func(array('WP_CLI', 'success'), "Backfill completado. Registros actualizados: {$updated}");
        }
    }
}

// Inicializar automáticamente
add_action('plugins_loaded', function() {
    QvaClick_Ticket_Chronological_Order::get_instance();
});
