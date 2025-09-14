<?php
/**
 * QvaClick Email Classifier
 * Sistema de clasificación inteligente de emails
 * 
 * Clasificaciones:
 * - Support Tickets (soporte técnico)
 * - Sales Inquiries (consultas de ventas)
 * - General Inquiries (consultas generales)
 * - Spam/Unwanted (spam o no deseados)
 * - Administrative (administrativos)
 * - Newsletters (boletines)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class QvaClick_Email_Classifier {
    
    /**
     * Patrones de clasificación
     */
    private $classification_patterns;
    
    /**
     * Palabras clave por categoría
     */
    private $category_keywords;
    
    /**
     * Machine Learning simple basado en historial
     */
    private $learning_data;
    
    public function __construct() {
        $this->init_classification_patterns();
        $this->init_category_keywords();
        $this->load_learning_data();
    }
    
    /**
     * Clasificar email automáticamente
     * @param array $email_data Datos del email
     * @return array Resultado de clasificación
     */
    public function classify_email($email_data) {
        $classification = array(
            'category' => 'general_inquiries',
            'confidence' => 0,
            'suggested_actions' => array(),
            'priority' => 'medium',
            'auto_assign' => false,
            'assigned_to' => null,
            'tags' => array(),
            'requires_human' => true
        );
        
        $subject = strtolower($email_data['subject']);
        $body = strtolower($email_data['body']);
        $sender = strtolower($email_data['from']);
        $combined_content = $subject . ' ' . $body;
        
        // 1. CLASIFICACIÓN POR PATRONES DIRECTOS
        $pattern_classification = $this->classify_by_patterns($combined_content, $sender);
        if ($pattern_classification['confidence'] > 80) {
            $classification = array_merge($classification, $pattern_classification);
        }
        
        // 2. CLASIFICACIÓN POR PALABRAS CLAVE
        $keyword_classification = $this->classify_by_keywords($combined_content);
        if ($keyword_classification['confidence'] > $classification['confidence']) {
            $classification = array_merge($classification, $keyword_classification);
        }
        
        // 3. CLASIFICACIÓN POR HISTORIAL DEL REMITENTE
        $history_classification = $this->classify_by_sender_history($sender);
        if ($history_classification['confidence'] > 60) {
            // Combinar con clasificación actual
            $classification['confidence'] = max($classification['confidence'], $history_classification['confidence']);
            if ($history_classification['category'] !== 'unknown') {
                $classification['category'] = $history_classification['category'];
            }
        }
        
        // 4. DETECTAR URGENCIA Y PRIORIDAD
        $priority_analysis = $this->analyze_priority($combined_content);
        $classification['priority'] = $priority_analysis['priority'];
        $classification['tags'] = array_merge($classification['tags'], $priority_analysis['tags']);
        
        // 5. ASIGNACIÓN AUTOMÁTICA
        $auto_assign = $this->get_auto_assignment($classification['category'], $sender);
        if ($auto_assign['can_assign']) {
            $classification['auto_assign'] = true;
            $classification['assigned_to'] = $auto_assign['user_id'];
            $classification['requires_human'] = false;
        }
        
        // 6. SUGERIR ACCIONES
        $classification['suggested_actions'] = $this->suggest_actions($classification);
        
        // 7. APRENDER DE LA CLASIFICACIÓN
        $this->learn_from_classification($email_data, $classification);
        
        return $classification;
    }
    
    /**
     * Clasificar por patrones directos
     */
    private function classify_by_patterns($content, $sender) {
        $result = array('category' => 'unknown', 'confidence' => 0, 'tags' => array());
        
        foreach ($this->classification_patterns as $category => $patterns) {
            foreach ($patterns as $pattern => $confidence) {
                if (preg_match($pattern, $content) || preg_match($pattern, $sender)) {
                    if ($confidence > $result['confidence']) {
                        $result['category'] = $category;
                        $result['confidence'] = $confidence;
                        $result['tags'][] = 'pattern_match';
                    }
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Clasificar por palabras clave
     */
    private function classify_by_keywords($content) {
        $scores = array();
        
        foreach ($this->category_keywords as $category => $keywords) {
            $score = 0;
            $matches = 0;
            
            foreach ($keywords as $keyword => $weight) {
                $count = substr_count($content, $keyword);
                if ($count > 0) {
                    $score += $count * $weight;
                    $matches++;
                }
            }
            
            // Normalizar score
            if ($matches > 0) {
                $scores[$category] = min(100, $score);
            }
        }
        
        if (empty($scores)) {
            return array('category' => 'general_inquiries', 'confidence' => 30, 'tags' => array());
        }
        
        arsort($scores);
        $top_category = key($scores);
        $top_score = current($scores);
        
        return array(
            'category' => $top_category,
            'confidence' => $top_score,
            'tags' => array('keyword_match')
        );
    }
    
    /**
     * Clasificar basado en historial del remitente
     */
    private function classify_by_sender_history($sender) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qvc_email_classifications';
        
        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT category, COUNT(*) as count 
             FROM {$table} 
             WHERE sender_email = %s 
             AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY category 
             ORDER BY count DESC 
             LIMIT 5",
            $sender
        ));
        
        if (empty($history)) {
            return array('category' => 'unknown', 'confidence' => 0);
        }
        
        $total_emails = array_sum(array_column($history, 'count'));
        $top_category = $history[0];
        
        $confidence = ($top_category->count / $total_emails) * 100;
        
        return array(
            'category' => $top_category->category,
            'confidence' => min(95, $confidence),
            'tags' => array('history_based')
        );
    }
    
    /**
     * Analizar prioridad del email
     */
    private function analyze_priority($content) {
        $priority = 'medium';
        $tags = array();
        
        // ALTA PRIORIDAD
        $high_priority_patterns = array(
            '/urgent|emergency|critical|asap|immediately/i',
            '/help.*stuck|error.*blocking|system.*down/i',
            '/payment.*failed|billing.*issue|account.*suspended/i'
        );
        
        foreach ($high_priority_patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $priority = 'high';
                $tags[] = 'urgent';
                break;
            }
        }
        
        // BAJA PRIORIDAD
        if ($priority === 'medium') {
            $low_priority_patterns = array(
                '/suggestion|feedback|feature.*request/i',
                '/thank.*you|thanks|appreciation/i',
                '/newsletter|unsubscribe|marketing/i'
            );
            
            foreach ($low_priority_patterns as $pattern) {
                if (preg_match($pattern, $content)) {
                    $priority = 'low';
                    $tags[] = 'non_urgent';
                    break;
                }
            }
        }
        
        // DETECTAR TAGS ADICIONALES
        if (preg_match('/bug|error|issue|problem/i', $content)) {
            $tags[] = 'bug_report';
        }
        
        if (preg_match('/feature|enhancement|improvement/i', $content)) {
            $tags[] = 'feature_request';
        }
        
        if (preg_match('/question|how.*to|can.*you/i', $content)) {
            $tags[] = 'question';
        }
        
        return array('priority' => $priority, 'tags' => $tags);
    }
    
    /**
     * Obtener asignación automática
     */
    private function get_auto_assignment($category, $sender) {
        // Reglas de asignación automática
        $assignment_rules = get_option('qvc_auto_assignment_rules', array());
        
        $result = array('can_assign' => false, 'user_id' => null);
        
        // Verificar reglas por categoría
        if (isset($assignment_rules[$category])) {
            $rule = $assignment_rules[$category];
            
            if ($rule['enabled'] && !empty($rule['assigned_user'])) {
                $result['can_assign'] = true;
                $result['user_id'] = $rule['assigned_user'];
            }
        }
        
        // Verificar reglas por dominio del remitente
        $domain = substr(strrchr($sender, "@"), 1);
        if (isset($assignment_rules['domains'][$domain])) {
            $rule = $assignment_rules['domains'][$domain];
            
            if ($rule['enabled'] && !empty($rule['assigned_user'])) {
                $result['can_assign'] = true;
                $result['user_id'] = $rule['assigned_user'];
            }
        }
        
        return $result;
    }
    
    /**
     * Sugerir acciones basadas en clasificación
     */
    private function suggest_actions($classification) {
        $actions = array();
        
        switch ($classification['category']) {
            case 'support_tickets':
                $actions[] = 'create_support_ticket';
                $actions[] = 'send_auto_acknowledgment';
                if ($classification['priority'] === 'high') {
                    $actions[] = 'notify_manager';
                }
                break;
                
            case 'sales_inquiries':
                $actions[] = 'create_sales_lead';
                $actions[] = 'send_sales_template';
                $actions[] = 'notify_sales_team';
                break;
                
            case 'general_inquiries':
                // CORREGIDO: No enviar plantilla simple para consultas generales que puedan ser tickets
                // Solo agregar a la cola general para revisión manual
                $actions[] = 'add_to_general_queue';
                break;
                
            case 'spam':
                $actions[] = 'move_to_spam';
                $actions[] = 'update_spam_filters';
                break;
                
            case 'newsletters':
                $actions[] = 'process_subscription';
                $actions[] = 'update_mailing_list';
                break;
        }
        
        // Acciones adicionales basadas en tags
        if (in_array('urgent', $classification['tags'])) {
            $actions[] = 'escalate_priority';
        }
        
        if (in_array('bug_report', $classification['tags'])) {
            $actions[] = 'create_bug_ticket';
        }
        
        return $actions;
    }
    
    /**
     * Aprender de la clasificación para mejorar futuras clasificaciones
     */
    private function learn_from_classification($email_data, $classification) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qvc_email_classifications';
        
        $wpdb->insert(
            $table,
            array(
                'sender_email' => $email_data['from'],
                'subject' => $email_data['subject'],
                'category' => $classification['category'],
                'confidence' => $classification['confidence'],
                'priority' => $classification['priority'],
                'tags' => json_encode($classification['tags']),
                'auto_assigned' => $classification['auto_assign'] ? 1 : 0,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s')
        );
        
        // Actualizar estadísticas de aprendizaje
        $this->update_learning_stats($classification);
    }
    
    /**
     * Actualizar estadísticas de aprendizaje
     */
    private function update_learning_stats($classification) {
        $stats = get_option('qvc_classification_stats', array());
        
        $category = $classification['category'];
        
        if (!isset($stats[$category])) {
            $stats[$category] = array('count' => 0, 'avg_confidence' => 0);
        }
        
        $stats[$category]['count']++;
        $stats[$category]['avg_confidence'] = 
            (($stats[$category]['avg_confidence'] * ($stats[$category]['count'] - 1)) + $classification['confidence']) 
            / $stats[$category]['count'];
        
        update_option('qvc_classification_stats', $stats);
    }
    
    /**
     * Inicializar patrones de clasificación
     */
    private function init_classification_patterns() {
        $this->classification_patterns = array(
            'support_tickets' => array(
                '/support@|help@|technical@/i' => 90,
                '/bug|error|issue|problem|not.*working/i' => 85,
                '/help.*with|need.*assistance|technical.*support/i' => 80,
                '/ticket.*#\d+|reference.*#\d+/i' => 95
            ),
            'sales_inquiries' => array(
                '/sales@|info@|commercial@/i' => 90,
                '/price|cost|quote|budget|purchase/i' => 85,
                '/interested.*in|would.*like.*to.*buy/i' => 80,
                '/demo|trial|consultation/i' => 75
            ),
            'spam' => array(
                '/noreply@.*\d{5,}/i' => 95,
                '/unsubscribe.*here.*now/i' => 90,
                '/click.*here.*immediately/i' => 85,
                '/congratulations.*winner/i' => 95
            ),
            'newsletters' => array(
                '/newsletter@|marketing@|news@/i' => 90,
                '/unsubscribe|mailing.*list/i' => 85,
                '/weekly.*update|monthly.*newsletter/i' => 80
            ),
            'administrative' => array(
                '/admin@|noreply@|system@/i' => 85,
                '/password.*reset|account.*verification/i' => 90,
                '/automatic.*notification|system.*generated/i' => 85
            )
        );
    }
    
    /**
     * Inicializar palabras clave por categoría
     */
    private function init_category_keywords() {
        $this->category_keywords = array(
            'support_tickets' => array(
                'help' => 5, 'support' => 5, 'assistance' => 4, 'issue' => 4,
                'problem' => 4, 'bug' => 5, 'error' => 4, 'broken' => 4,
                'not working' => 6, 'technical' => 3, 'troubleshoot' => 5
            ),
            'sales_inquiries' => array(
                'price' => 5, 'cost' => 5, 'quote' => 6, 'purchase' => 5,
                'buy' => 4, 'sale' => 4, 'discount' => 4, 'offer' => 3,
                'demo' => 5, 'trial' => 5, 'consultation' => 4
            ),
            'general_inquiries' => array(
                'question' => 3, 'inquiry' => 4, 'information' => 3,
                'details' => 3, 'about' => 2, 'how' => 2, 'what' => 2,
                'when' => 2, 'where' => 2, 'why' => 2
            ),
            'newsletters' => array(
                'newsletter' => 6, 'unsubscribe' => 6, 'subscription' => 5,
                'mailing list' => 6, 'weekly' => 3, 'monthly' => 3,
                'update' => 2, 'news' => 3
            )
        );
    }
    
    /**
     * Cargar datos de aprendizaje
     */
    private function load_learning_data() {
        $this->learning_data = get_option('qvc_classification_learning', array());
    }
    
    /**
     * Crear tabla de clasificaciones
     */
    public static function create_classification_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'qvc_email_classifications';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            sender_email varchar(255) NOT NULL,
            subject text,
            category varchar(50) NOT NULL,
            confidence tinyint(3) NOT NULL,
            priority varchar(20) NOT NULL DEFAULT 'medium',
            tags text,
            auto_assigned tinyint(1) NOT NULL DEFAULT 0,
            human_verified tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY sender_email (sender_email),
            KEY category (category),
            KEY priority (priority),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Reclasificar email manualmente (aprendizaje)
     */
    public function reclassify_email($email_id, $new_category, $user_id) {
        global $wpdb;
        
        // Actualizar clasificación
        $wpdb->update(
            $wpdb->prefix . 'qvc_email_classifications',
            array(
                'category' => $new_category,
                'human_verified' => 1
            ),
            array('id' => $email_id),
            array('%s', '%d'),
            array('%d')
        );
        
        // Aprender de la corrección
        $this->learn_from_correction($email_id, $new_category);
        
        return true;
    }
    
    /**
     * Aprender de correcciones humanas
     */
    private function learn_from_correction($email_id, $correct_category) {
        // Implementar machine learning simple
        // Actualizar pesos de palabras clave y patrones
        $learning_data = get_option('qvc_classification_learning', array());
        
        if (!isset($learning_data['corrections'])) {
            $learning_data['corrections'] = array();
        }
        
        $learning_data['corrections'][] = array(
            'email_id' => $email_id,
            'correct_category' => $correct_category,
            'timestamp' => time()
        );
        
        update_option('qvc_classification_learning', $learning_data);
    }
}
?>
