<?php
/**
 * QvaClick Email Security Scanner
 * Sistema avanzado de seguridad para emails entrantes
 * 
 * Protecciones implementadas:
 * - Detección de malware
 * - Filtrado de spam
 * - Análisis de attachments
 * - Detección de phishing
 * - Rate limiting por remitente
 * - Blacklists y whitelists
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class QvaClick_Email_Security_Scanner {
    
    /**
     * Configuración de seguridad
     */
    private $security_config;
    
    /**
     * Patrones de spam conocidos
     */
    private $spam_patterns;
    
    /**
     * Extensiones de archivo peligrosas
     */
    private $dangerous_extensions;
    
    public function __construct() {
        $this->load_security_config();
        $this->init_spam_patterns();
        $this->init_dangerous_extensions();
    }
    
    /**
     * Escanear email completo
     * @param array $email_data Datos del email
     * @return array Resultado del escaneo
     */
    public function scan_email($email_data) {
        $scan_result = array(
            'is_safe' => true,
            'security_score' => 100,
            'threats_detected' => array(),
            'warnings' => array(),
            'recommendations' => array(),
            'quarantine' => false,
            'auto_process' => true
        );
        
        // 1. VERIFICAR REMITENTE
        $sender_check = $this->check_sender_reputation($email_data['from']);
        if (!$sender_check['is_safe']) {
            $scan_result['is_safe'] = false;
            $scan_result['security_score'] -= 30;
            $scan_result['threats_detected'][] = 'Remitente sospechoso: ' . $sender_check['reason'];
            $scan_result['quarantine'] = true;
        }
        
        // 2. ANÁLISIS DE CONTENIDO
        $content_check = $this->analyze_content($email_data['body'], $email_data['subject']);
        if (!$content_check['is_safe']) {
            $scan_result['is_safe'] = false;
            $scan_result['security_score'] -= $content_check['severity'];
            $scan_result['threats_detected'] = array_merge($scan_result['threats_detected'], $content_check['threats']);
        }
        
        // 3. ANÁLISIS DE ATTACHMENTS
        if (!empty($email_data['attachments'])) {
            $attachment_check = $this->scan_attachments($email_data['attachments']);
            if (!$attachment_check['is_safe']) {
                $scan_result['is_safe'] = false;
                $scan_result['security_score'] -= 40;
                $scan_result['threats_detected'][] = 'Attachments peligrosos detectados';
                $scan_result['quarantine'] = true;
            }
        }
        
        // 4. DETECCIÓN DE PHISHING
        $phishing_check = $this->detect_phishing($email_data);
        if ($phishing_check['is_phishing']) {
            $scan_result['is_safe'] = false;
            $scan_result['security_score'] -= 50;
            $scan_result['threats_detected'][] = 'Posible intento de phishing';
            $scan_result['quarantine'] = true;
        }
        
        // 5. RATE LIMITING
        $rate_check = $this->check_rate_limits($email_data['from']);
        if (!$rate_check['is_safe']) {
            $scan_result['warnings'][] = 'Rate limit excedido para ' . $email_data['from'];
            $scan_result['auto_process'] = false;
        }
        
        // 6. VERIFICAR BLACKLIST/WHITELIST
        $list_check = $this->check_lists($email_data['from']);
        if ($list_check['blacklisted']) {
            $scan_result['is_safe'] = false;
            $scan_result['quarantine'] = true;
            $scan_result['threats_detected'][] = 'Remitente en blacklist';
        } elseif ($list_check['whitelisted']) {
            $scan_result['security_score'] = max($scan_result['security_score'], 75);
            $scan_result['auto_process'] = true;
        }
        
        // DECISIÓN FINAL
        if ($scan_result['security_score'] < 30) {
            $scan_result['quarantine'] = true;
            $scan_result['auto_process'] = false;
        } elseif ($scan_result['security_score'] < 60) {
            $scan_result['auto_process'] = false;
            $scan_result['warnings'][] = 'Email requiere revisión manual';
        }
        
        // LOG DE SEGURIDAD
        $this->log_security_scan($email_data, $scan_result);
        
        return $scan_result;
    }
    
    /**
     * Verificar reputación del remitente
     */
    private function check_sender_reputation($sender_email) {
        $result = array('is_safe' => true, 'reason' => '');
        
        // Extraer dominio
        $domain = substr(strrchr($sender_email, "@"), 1);
        
        // Verificar dominios conocidos de spam
        $spam_domains = array(
            'tempmail.org', '10minutemail.com', 'guerrillamail.com',
            'mailinator.com', 'trashmail.com', 'spam4.me'
        );
        
        if (in_array($domain, $spam_domains)) {
            $result['is_safe'] = false;
            $result['reason'] = 'Dominio de email temporal/spam';
            return $result;
        }
        
        // Verificar patrones sospechosos en el email
        $suspicious_patterns = array(
            '/noreply.*\d{5,}@/',  // noreply con muchos números
            '/admin.*\d{3,}@/',    // admin con números
            '/support.*\d{3,}@/',  // support con números
            '/[a-z]{20,}@/',       // strings muy largos
            '/\d{10,}@/'           // solo números largos
        );
        
        foreach ($suspicious_patterns as $pattern) {
            if (preg_match($pattern, $sender_email)) {
                $result['is_safe'] = false;
                $result['reason'] = 'Patrón de email sospechoso';
                break;
            }
        }
        
        // Verificar historial reciente del remitente
        $recent_count = $this->get_recent_email_count($sender_email);
        if ($recent_count > 10) { // Más de 10 emails en 1 hora
            $result['is_safe'] = false;
            $result['reason'] = 'Volumen excesivo de emails';
        }
        
        return $result;
    }
    
    /**
     * Analizar contenido del email
     */
    private function analyze_content($body, $subject) {
        $result = array(
            'is_safe' => true,
            'threats' => array(),
            'severity' => 0
        );
        
        $all_content = $subject . ' ' . $body;
        
        // PATRONES DE SPAM COMUNES
        foreach ($this->spam_patterns as $pattern => $severity) {
            if (preg_match($pattern, $all_content, $matches)) {
                $result['is_safe'] = false;
                $result['threats'][] = "Patrón de spam detectado: {$pattern}";
                $result['severity'] += $severity;
            }
        }
        
        // DETECCIÓN DE ENLACES SOSPECHOSOS
        $suspicious_links = $this->detect_suspicious_links($body);
        if (!empty($suspicious_links)) {
            $result['is_safe'] = false;
            $result['threats'][] = 'Enlaces sospechosos: ' . implode(', ', $suspicious_links);
            $result['severity'] += 25;
        }
        
        // ANÁLISIS DE IDIOMA Y ENCODING
        if ($this->has_suspicious_encoding($all_content)) {
            $result['threats'][] = 'Encoding sospechoso detectado';
            $result['severity'] += 15;
        }
        
        return $result;
    }
    
    /**
     * Escanear attachments
     */
    private function scan_attachments($attachments) {
        $result = array('is_safe' => true, 'threats' => array());
        
        foreach ($attachments as $attachment) {
            $filename = $attachment['filename'];
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            // Verificar extensiones peligrosas
            if (in_array($extension, $this->dangerous_extensions)) {
                $result['is_safe'] = false;
                $result['threats'][] = "Archivo peligroso: {$filename}";
                continue;
            }
            
            // Verificar nombres de archivo sospechosos
            if ($this->has_suspicious_filename($filename)) {
                $result['is_safe'] = false;
                $result['threats'][] = "Nombre de archivo sospechoso: {$filename}";
            }
            
            // Verificar tamaño del archivo
            if ($attachment['size'] > 10 * 1024 * 1024) { // 10MB
                $result['threats'][] = "Archivo muy grande: {$filename}";
            }
        }
        
        return $result;
    }
    
    /**
     * Detectar intentos de phishing
     */
    private function detect_phishing($email_data) {
        $result = array('is_phishing' => false, 'indicators' => array());
        
        $content = $email_data['subject'] . ' ' . $email_data['body'];
        
        // Patrones comunes de phishing
        $phishing_patterns = array(
            '/urgent.*action.*required/i',
            '/verify.*account.*immediately/i',
            '/suspend.*account/i',
            '/click.*here.*now/i',
            '/limited.*time.*offer/i',
            '/congratulations.*winner/i',
            '/security.*alert/i'
        );
        
        foreach ($phishing_patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $result['is_phishing'] = true;
                $result['indicators'][] = 'Patrón de phishing: ' . $pattern;
            }
        }
        
        // Verificar dominios que se hacen pasar por otros
        if ($this->has_domain_spoofing($email_data['from'])) {
            $result['is_phishing'] = true;
            $result['indicators'][] = 'Posible spoofing de dominio';
        }
        
        return $result;
    }
    
    /**
     * Verificar rate limits
     */
    private function check_rate_limits($sender_email) {
        $result = array('is_safe' => true);
        
        $hour_key = 'qvc_email_rate_' . md5($sender_email) . '_' . date('YmdH');
        $day_key = 'qvc_email_rate_' . md5($sender_email) . '_' . date('Ymd');
        
        $hour_count = get_transient($hour_key) ?: 0;
        $day_count = get_transient($day_key) ?: 0;
        
        // Límites configurables
        $hour_limit = $this->security_config['rate_limit_hour'] ?? 10;
        $day_limit = $this->security_config['rate_limit_day'] ?? 50;
        
        if ($hour_count >= $hour_limit || $day_count >= $day_limit) {
            $result['is_safe'] = false;
        }
        
        // Incrementar contadores
        set_transient($hour_key, $hour_count + 1, HOUR_IN_SECONDS);
        set_transient($day_key, $day_count + 1, DAY_IN_SECONDS);
        
        return $result;
    }
    
    /**
     * Verificar blacklist/whitelist
     */
    private function check_lists($sender_email) {
        $blacklist = get_option('qvc_email_blacklist', array());
        $whitelist = get_option('qvc_email_whitelist', array());
        
        $domain = substr(strrchr($sender_email, "@"), 1);
        
        return array(
            'blacklisted' => in_array($sender_email, $blacklist) || in_array($domain, $blacklist),
            'whitelisted' => in_array($sender_email, $whitelist) || in_array($domain, $whitelist)
        );
    }
    
    /**
     * Cargar configuración de seguridad
     */
    private function load_security_config() {
        $this->security_config = get_option('qvc_email_security_config', array(
            'scan_enabled' => true,
            'quarantine_threshold' => 30,
            'rate_limit_hour' => 10,
            'rate_limit_day' => 50,
            'scan_attachments' => true,
            'block_suspicious_domains' => true,
            'auto_learn_spam' => true
        ));
    }
    
    /**
     * Inicializar patrones de spam
     */
    private function init_spam_patterns() {
        $this->spam_patterns = array(
            '/make.*money.*fast/i' => 30,
            '/get.*rich.*quick/i' => 30,
            '/free.*money/i' => 25,
            '/click.*here.*now/i' => 20,
            '/urgent.*response/i' => 20,
            '/limited.*time/i' => 15,
            '/act.*now/i' => 15,
            '/\$\d+.*guaranteed/i' => 25,
            '/weight.*loss.*miracle/i' => 20,
            '/viagra|cialis/i' => 35,
            '/casino|lottery/i' => 25,
            '/congratulations.*winner/i' => 30
        );
    }
    
    /**
     * Inicializar extensiones peligrosas
     */
    private function init_dangerous_extensions() {
        $this->dangerous_extensions = array(
            'exe', 'bat', 'cmd', 'com', 'pif', 'scr', 'vbs', 'js',
            'jar', 'msi', 'dll', 'scf', 'lnk', 'inf', 'reg'
        );
    }
    
    /**
     * Detectar enlaces sospechosos
     */
    private function detect_suspicious_links($content) {
        $suspicious = array();
        
        // Extraer todos los enlaces
        preg_match_all('/https?:\/\/[^\s<>"\']+/i', $content, $matches);
        
        foreach ($matches[0] as $url) {
            $domain = parse_url($url, PHP_URL_HOST);
            
            // Verificar dominios sospechosos
            if ($this->is_suspicious_domain($domain)) {
                $suspicious[] = $domain;
            }
            
            // Verificar URLs acortadas
            if ($this->is_url_shortener($domain)) {
                $suspicious[] = $domain . ' (URL shortener)';
            }
        }
        
        return $suspicious;
    }
    
    /**
     * Verificar encoding sospechoso
     */
    private function has_suspicious_encoding($content) {
        // Verificar caracteres de control excesivos
        $control_chars = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content);
        
        // Verificar encoding base64 sospechoso
        $base64_matches = preg_match_all('/[A-Za-z0-9+\/]{50,}={0,2}/', $content);
        
        return $control_chars > 10 || $base64_matches > 3;
    }
    
    /**
     * Verificar nombre de archivo sospechoso
     */
    private function has_suspicious_filename($filename) {
        $suspicious_patterns = array(
            '/\.(exe|bat|cmd)\./',  // Doble extensión
            '/[A-Za-z0-9]{20,}\./', // Nombres muy largos
            '/\d{10,}\./',          // Solo números
            '/^\w{1,3}\.(exe|bat)/' // Nombres muy cortos con extensión peligrosa
        );
        
        foreach ($suspicious_patterns as $pattern) {
            if (preg_match($pattern, $filename)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Detectar spoofing de dominio
     */
    private function has_domain_spoofing($email) {
        $domain = substr(strrchr($email, "@"), 1);
        
        // Dominios legítimos que suelen ser spoofed
        $legitimate_domains = array(
            'paypal.com', 'amazon.com', 'google.com', 'microsoft.com',
            'apple.com', 'facebook.com', 'twitter.com', 'linkedin.com'
        );
        
        foreach ($legitimate_domains as $legit_domain) {
            // Verificar similitud sospechosa
            if ($domain !== $legit_domain && similar_text($domain, $legit_domain) / strlen($legit_domain) > 0.8) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Verificar si es dominio sospechoso
     */
    private function is_suspicious_domain($domain) {
        $suspicious_tlds = array('.tk', '.ml', '.ga', '.cf');
        
        foreach ($suspicious_tlds as $tld) {
            if (substr($domain, -strlen($tld)) === $tld) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Verificar si es acortador de URLs
     */
    private function is_url_shortener($domain) {
        $shorteners = array(
            'bit.ly', 'tinyurl.com', 't.co', 'goo.gl', 'ow.ly',
            'short.link', 'tiny.cc', 'is.gd', 'buff.ly'
        );
        
        return in_array($domain, $shorteners);
    }
    
    /**
     * Obtener cantidad reciente de emails del remitente
     */
    private function get_recent_email_count($sender_email) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qvc_email_security_log';
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} 
             WHERE sender_email = %s 
             AND scan_time > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $sender_email
        ));
        
        return intval($count);
    }
    
    /**
     * Registrar escaneo de seguridad
     */
    private function log_security_scan($email_data, $scan_result) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qvc_email_security_log';
        
        $wpdb->insert(
            $table,
            array(
                'sender_email' => $email_data['from'],
                'subject' => $email_data['subject'],
                'security_score' => $scan_result['security_score'],
                'is_safe' => $scan_result['is_safe'] ? 1 : 0,
                'threats_detected' => json_encode($scan_result['threats_detected']),
                'quarantined' => $scan_result['quarantine'] ? 1 : 0,
                'scan_time' => current_time('mysql')
            ),
            array('%s', '%s', '%d', '%d', '%s', '%d', '%s')
        );
        
        // Log adicional para amenazas críticas
        if (!$scan_result['is_safe']) {
            error_log("QvaClick Security: Threat detected from {$email_data['from']} - Score: {$scan_result['security_score']}");
        }
    }
    
    /**
     * Crear tabla de logs de seguridad
     */
    public static function create_security_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'qvc_email_security_log';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            sender_email varchar(255) NOT NULL,
            subject text,
            security_score tinyint(3) NOT NULL,
            is_safe tinyint(1) NOT NULL DEFAULT 0,
            threats_detected text,
            quarantined tinyint(1) NOT NULL DEFAULT 0,
            scan_time datetime NOT NULL,
            PRIMARY KEY (id),
            KEY sender_email (sender_email),
            KEY scan_time (scan_time),
            KEY security_score (security_score)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
?>
