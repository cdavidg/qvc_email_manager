<?php
/**
 * QvaClick Ticket Security Shield
 * Sistema de seguridad avanzado para el sistema de tickets
 * 
 * @package QvaClick_Email_Manager
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class QvaClick_Ticket_Security_Shield {
    
    private static $instance = null;
    
    /**
     * Bad words y patrones de phishing
     */
    private $bad_words = [
        // Crypto/Phishing keywords
        'bitcoin', 'btc', 'crypto', 'wallet', 'metamask', 'binance', 'coinbase',
        'ethereum', 'eth', 'usdt', 'usdc', 'defi', 'nft', 'blockchain',
        'approve', 'connect wallet', 'verify wallet', 'claim', 'airdrop',
        'mining', 'investment', 'roi', 'profit guarantee', 'make money fast',
        
        // Phishing patterns
        'click here', 'urgent action', 'verify account', 'suspended account',
        'confirm identity', 'update payment', 'billing issue', 'refund pending',
        'tax refund', 'government', 'irs', 'bank transfer', 'wire transfer',
        
        // Spam indicators
        'viagra', 'pharmacy', 'discount', 'free money', 'lottery winner',
        'inheritance', 'million dollars', 'beneficiary', 'transfer funds',
        'business proposal', 'investment opportunity', 'confidential',
        
        // Malicious content
        'download', 'install', 'run this', 'execute', 'script', 'payload',
        'backdoor', 'trojan', 'malware', 'virus', 'hack', 'exploit'
    ];
    
    /**
     * Patrones regex sospechosos
     */
    private $suspicious_patterns = [
        // URLs sospechosas
        '/bit\.ly|tinyurl|t\.co|goo\.gl|short\.link|tiny\.cc/i',
        
        // Emails sospechosos
        '/[a-z0-9._%+-]+@(gmail|yahoo|hotmail|outlook)\.com/i',
        
        // Dominios sospechosos comunes
        '/\.tk|\.ml|\.ga|\.cf|\.gq/i',
        
        // Base64 encoded content
        '/[A-Za-z0-9+\/]{20,}={0,2}/',
        
        // Hex encoded content
        '/[0-9a-fA-F]{32,}/',
        
        // JavaScript injection attempts
        '/<script|javascript:|onload=|onerror=/i',
        
        // SQL injection attempts
        '/union\s+select|drop\s+table|insert\s+into|delete\s+from/i',
        
        // Command injection
        '/\|\s*wget|\|\s*curl|\|\s*bash|\|\s*sh\s/i'
    ];
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'init'));
    }
    
    public function init() {
        // Crear tabla de cuarentena si no existe
        $this->create_quarantine_table();
        
        // Hooks de seguridad
        add_filter('qvc_before_save_ticket', array($this, 'scan_ticket_content'), 10, 2);
        add_filter('qvc_before_save_message', array($this, 'scan_message_content'), 10, 2);
        add_action('qvc_quarantine_item', array($this, 'move_to_quarantine'), 10, 3);
    }
    
    /**
     * Crear tabla de cuarentena
     */
    public function create_quarantine_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'qvc_security_quarantine';
        $charset_collate = $wpdb->get_charset_collate();
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $sql = "CREATE TABLE $table_name (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                item_type enum('ticket','message','email') NOT NULL,
                item_id varchar(100) NOT NULL,
                original_data longtext NOT NULL,
                threat_level enum('low','medium','high','critical') DEFAULT 'medium',
                threat_reasons longtext NOT NULL,
                source_ip varchar(45) DEFAULT '',
                user_agent text DEFAULT '',
                user_id bigint(20) UNSIGNED NULL,
                user_email varchar(320) DEFAULT '',
                quarantined_at timestamp DEFAULT CURRENT_TIMESTAMP,
                reviewed_by bigint(20) UNSIGNED NULL,
                reviewed_at timestamp NULL,
                status enum('quarantined','approved','rejected','deleted') DEFAULT 'quarantined',
                admin_notes text DEFAULT '',
                PRIMARY KEY (id),
                KEY item_type (item_type),
                KEY threat_level (threat_level),
                KEY status (status),
                KEY quarantined_at (quarantined_at)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }
    
    /**
     * Escanear contenido de ticket
     */
    public function scan_ticket_content($ticket_data, $context = 'create') {
        $content = $ticket_data['subject'] . ' ' . ($ticket_data['message'] ?? '');
        $scan_result = $this->comprehensive_scan($content);
        
        if ($scan_result['is_threat']) {
            // Mover a cuarentena
            $this->move_to_quarantine('ticket', $ticket_data, $scan_result);
            
            // Bloquear la creación del ticket
            wp_die(
                'Tu mensaje ha sido bloqueado por razones de seguridad. Si crees que es un error, contacta al administrador.',
                'Contenido Bloqueado',
                array('response' => 403)
            );
        }
        
        return $ticket_data;
    }
    
    /**
     * Escanear contenido de mensaje
     */
    public function scan_message_content($message_data, $context = 'create') {
        $content = $message_data['message'] ?? '';
        $scan_result = $this->comprehensive_scan($content);
        
        if ($scan_result['is_threat']) {
            // Mover a cuarentena
            $this->move_to_quarantine('message', $message_data, $scan_result);
            
            // Bloquear el mensaje
            wp_die(
                'Tu mensaje ha sido bloqueado por razones de seguridad. Si crees que es un error, contacta al administrador.',
                'Contenido Bloqueado',
                array('response' => 403)
            );
        }
        
        return $message_data;
    }
    
    /**
     * Escaneo comprehensivo de contenido
     */
    public function comprehensive_scan($content) {
        $threats = array();
        $threat_level = 'low';
        
        // Limpiar y normalizar el contenido
        $clean_content = strtolower(wp_strip_all_tags($content));
        $clean_content = preg_replace('/\s+/', ' ', $clean_content);
        
        // 1. Verificar bad words
        foreach ($this->bad_words as $bad_word) {
            if (strpos($clean_content, strtolower($bad_word)) !== false) {
                $threats[] = "Bad word detected: {$bad_word}";
                $threat_level = 'high';
            }
        }
        
        // 2. Verificar patrones sospechosos
        foreach ($this->suspicious_patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $threats[] = "Suspicious pattern detected: {$pattern}";
                $threat_level = ($threat_level === 'low') ? 'medium' : $threat_level;
            }
        }
        
        // 3. Verificar URLs externas
        if ($this->has_external_urls($content)) {
            $threats[] = "External URLs detected";
            $threat_level = ($threat_level === 'low') ? 'medium' : 'high';
        }
        
        // 4. Verificar contenido codificado
        if ($this->has_encoded_content($content)) {
            $threats[] = "Encoded content detected";
            $threat_level = 'critical';
        }
        
        // 5. Verificar longitud y calidad del mensaje
        $word_count = str_word_count($clean_content);
        if ($word_count < 5) {
            $threats[] = "Message too short (possible spam)";
            $threat_level = ($threat_level === 'low') ? 'medium' : $threat_level;
        }
        
        // 6. Verificar caracteres especiales sospechosos
        if (preg_match('/[^\x20-\x7E\s\p{L}\p{N}\p{P}\p{S}]/u', $content)) {
            $threats[] = "Suspicious characters detected";
            $threat_level = ($threat_level === 'low') ? 'medium' : $threat_level;
        }
        
        return array(
            'is_threat' => !empty($threats),
            'threats' => $threats,
            'threat_level' => $threat_level,
            'content_length' => strlen($content),
            'word_count' => $word_count
        );
    }
    
    /**
     * Verificar URLs externas
     */
    private function has_external_urls($content) {
        preg_match_all('/https?:\/\/[^\s<>"\'\(\)]+/i', $content, $matches);
        
        if (empty($matches[0])) {
            return false;
        }
        
        $site_domain = parse_url(home_url(), PHP_URL_HOST);
        
        foreach ($matches[0] as $url) {
            $url_domain = parse_url($url, PHP_URL_HOST);
            if ($url_domain && $url_domain !== $site_domain) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Verificar contenido codificado
     */
    private function has_encoded_content($content) {
        // Base64
        if (preg_match('/[A-Za-z0-9+\/]{20,}={0,2}/', $content)) {
            return true;
        }
        
        // Hex
        if (preg_match('/[0-9a-fA-F]{20,}/', $content)) {
            return true;
        }
        
        // URL encoding extensive
        if (preg_match('/%[0-9a-fA-F]{2}.*%[0-9a-fA-F]{2}.*%[0-9a-fA-F]{2}/', $content)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Mover item a cuarentena
     */
    public function move_to_quarantine($item_type, $item_data, $scan_result) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'qvc_security_quarantine';
        
        $wpdb->insert(
            $table_name,
            array(
                'item_type' => $item_type,
                'item_id' => $item_data['ticket_id'] ?? uniqid('quar_'),
                'original_data' => wp_json_encode($item_data),
                'threat_level' => $scan_result['threat_level'],
                'threat_reasons' => wp_json_encode($scan_result['threats']),
                'source_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'user_id' => get_current_user_id() ?: null,
                'user_email' => $item_data['user_email'] ?? '',
                'status' => 'quarantined'
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );
        
        // Log del evento
        error_log("[QVC Security] Item moved to quarantine: {$item_type} - Threats: " . implode(', ', $scan_result['threats']));
    }
    
    /**
     * Obtener items en cuarentena
     */
    public function get_quarantine_items($limit = 50, $offset = 0) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'qvc_security_quarantine';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} 
             ORDER BY quarantined_at DESC 
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));
    }
    
    /**
     * Aprobar item de cuarentena
     */
    public function approve_quarantine_item($item_id, $admin_notes = '') {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'qvc_security_quarantine';
        
        return $wpdb->update(
            $table_name,
            array(
                'status' => 'approved',
                'reviewed_by' => get_current_user_id(),
                'reviewed_at' => current_time('mysql'),
                'admin_notes' => $admin_notes
            ),
            array('id' => $item_id),
            array('%s', '%d', '%s', '%s'),
            array('%d')
        );
    }
    
    /**
     * Rechazar item de cuarentena
     */
    public function reject_quarantine_item($item_id, $admin_notes = '') {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'qvc_security_quarantine';
        
        return $wpdb->update(
            $table_name,
            array(
                'status' => 'rejected',
                'reviewed_by' => get_current_user_id(),
                'reviewed_at' => current_time('mysql'),
                'admin_notes' => $admin_notes
            ),
            array('id' => $item_id),
            array('%s', '%d', '%s', '%s'),
            array('%d')
        );
    }
    
    /**
     * Eliminar item de cuarentena permanentemente
     */
    public function delete_quarantine_item($item_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'qvc_security_quarantine';
        
        return $wpdb->delete(
            $table_name,
            array('id' => $item_id),
            array('%d')
        );
    }
    
    /**
     * Verificar si un usuario está en lista negra
     */
    public function is_user_blacklisted($user_email) {
        $blacklisted_domains = array(
            'tempmail.com', '10minutemail.com', 'guerrillamail.com',
            'mailinator.com', 'throwaway.email', 'temp-mail.org'
        );
        
        $domain = substr(strrchr($user_email, "@"), 1);
        
        return in_array(strtolower($domain), $blacklisted_domains);
    }
    
    /**
     * Obtener estadísticas de seguridad
     */
    public function get_security_stats() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'qvc_security_quarantine';
        
        $stats = array();
        
        // Total en cuarentena
        $stats['total_quarantined'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name} WHERE status = 'quarantined'"
        );
        
        // Por nivel de amenaza
        $stats['by_threat_level'] = $wpdb->get_results(
            "SELECT threat_level, COUNT(*) as count 
             FROM {$table_name} 
             WHERE status = 'quarantined' 
             GROUP BY threat_level"
        );
        
        // Últimos 7 días
        $stats['last_7_days'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name} 
             WHERE quarantined_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        
        return $stats;
    }
    
    /**
     * Obtener total de elementos en cuarentena para paginación
     */
    public function get_quarantine_total() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'qvc_quarantine';
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'quarantined'");
        
        return intval($count);
    }
    
    /**
     * Obtener bad words configuradas
     */
    public function get_bad_words() {
        $custom_words = get_option('qvc_security_bad_words', array());
        return array_merge($this->bad_words, $custom_words);
    }
    
    /**
     * Guardar bad words personalizadas
     */
    public function save_bad_words($words) {
        if (!is_array($words)) {
            $words = array_map('trim', explode(',', $words));
        }
        
        // Sanitizar palabras
        $sanitized_words = array();
        foreach ($words as $word) {
            $word = sanitize_text_field(trim($word));
            if (!empty($word)) {
                $sanitized_words[] = strtolower($word);
            }
        }
        
        return update_option('qvc_security_bad_words', $sanitized_words);
    }
    
    /**
     * Obtener bad words por defecto para phishing
     */
    public function get_default_phishing_words() {
        return [
            // Crypto/Phishing keywords
            'bitcoin', 'btc', 'crypto', 'wallet', 'metamask', 'binance', 'coinbase',
            'ethereum', 'eth', 'usdt', 'usdc', 'defi', 'nft', 'blockchain',
            'approve', 'connect wallet', 'verify wallet', 'claim', 'airdrop',
            'mining', 'investment', 'roi', 'profit guarantee', 'make money fast',
            
            // Phishing patterns
            'click here', 'urgent action', 'verify account', 'suspended account',
            'confirm identity', 'update payment', 'billing issue', 'refund pending',
            'tax refund', 'government', 'irs', 'bank transfer', 'wire transfer',
            
            // Spanish phishing
            'haz clic aquí', 'acción urgente', 'verificar cuenta', 'cuenta suspendida',
            'confirmar identidad', 'actualizar pago', 'problema facturación',
            'reembolso pendiente', 'devolución impuestos', 'gobierno', 'hacienda',
            'transferencia bancaria', 'dinero fácil', 'ganancia garantizada',
            
            // Spam indicators
            'viagra', 'pharmacy', 'discount', 'free money', 'lottery winner',
            'inheritance', 'million dollars', 'beneficiary', 'transfer funds',
            'business proposal', 'investment opportunity', 'confidential',
            'descuento', 'dinero gratis', 'ganador lotería', 'herencia',
            'millones', 'beneficiario', 'transferir fondos', 'propuesta negocio',
            'oportunidad inversión', 'confidencial',
            
            // Malicious content
            'download', 'install', 'run this', 'execute', 'script', 'payload',
            'backdoor', 'trojan', 'malware', 'virus', 'hack', 'exploit',
            'descargar', 'instalar', 'ejecutar', 'script', 'virus', 'hackear'
        ];
    }
    
    /**
     * Resetear bad words a valores por defecto
     */
    public function reset_bad_words_to_default() {
        return update_option('qvc_security_bad_words', array());
    }
}
