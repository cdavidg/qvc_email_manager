<?php
/**
 * Enhanced Cron Management for QvaClick Email Manager
 * Sistema mejorado de tareas programadas
 * 
 * @package QvaClick_Email_Manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class QvaClick_Enhanced_Cron_Manager {
    
    public function __construct() {
        // Register cron hooks
        add_action('qvc_check_enhanced_imap_emails', array($this, 'process_imap_emails'));
        add_action('qvc_cleanup_old_emails', array($this, 'cleanup_old_emails'));
        add_action('qvc_security_maintenance', array($this, 'security_maintenance'));
        add_action('qvc_classification_optimization', array($this, 'optimize_classification'));
        add_action('qvc_daily_reports', array($this, 'generate_daily_reports'));
        
        // Schedule cron events on activation
        add_action('init', array($this, 'schedule_events'));
    }
    
    /**
     * Schedule all cron events
     */
    public function schedule_events() {
        // Main IMAP processing (every 5 minutes)
        if (!wp_next_scheduled('qvc_check_enhanced_imap_emails')) {
            wp_schedule_event(time(), 'qvc_five_minutes', 'qvc_check_enhanced_imap_emails');
        }
        
        // Cleanup old emails (daily at 2 AM)
        if (!wp_next_scheduled('qvc_cleanup_old_emails')) {
            wp_schedule_event(strtotime('02:00:00'), 'daily', 'qvc_cleanup_old_emails');
        }
        
        // Security maintenance (every hour)
        if (!wp_next_scheduled('qvc_security_maintenance')) {
            wp_schedule_event(time(), 'hourly', 'qvc_security_maintenance');
        }
        
        // Classification optimization (daily at 3 AM)
        if (!wp_next_scheduled('qvc_classification_optimization')) {
            wp_schedule_event(strtotime('03:00:00'), 'daily', 'qvc_classification_optimization');
        }
        
        // Daily reports (daily at 8 AM)
        if (!wp_next_scheduled('qvc_daily_reports')) {
            wp_schedule_event(strtotime('08:00:00'), 'daily', 'qvc_daily_reports');
        }
    }
    
    /**
     * Add custom cron intervals
     */
    public static function add_cron_intervals($schedules) {
        $schedules['qvc_five_minutes'] = array(
            'interval' => 300, // 5 minutes
            'display' => 'Every 5 Minutes'
        );
        
        $schedules['qvc_fifteen_minutes'] = array(
            'interval' => 900, // 15 minutes
            'display' => 'Every 15 Minutes'
        );
        
        return $schedules;
    }
    
    /**
     * Process IMAP emails with enhanced security and classification
     */
    public function process_imap_emails() {
        error_log('QvaClick Debug: Starting enhanced IMAP processing');
        
        try {
            // Load enhanced IMAP reader
            require_once plugin_dir_path(__FILE__) . 'class-enhanced-imap-reader.php';
            
            $imap_reader = new QvaClick_Enhanced_IMAP_Reader();
            $result = $imap_reader->process_new_emails();
            
            if ($result['success']) {
                $stats = $result['results'];
                
                // Log processing results
                error_log(sprintf(
                    'QvaClick IMAP: Processed %d emails - %d tickets, %d leads, %d general, %d quarantined, %d spam blocked, %d errors',
                    $stats['processed'],
                    $stats['tickets_created'],
                    $stats['leads_created'],
                    $stats['general_stored'],
                    $stats['quarantined'],
                    $stats['spam_blocked'],
                    $stats['errors']
                ));
                
                // Update processing statistics
                $this->update_processing_stats($stats);
                
                // Send alerts if needed
                $this->check_processing_alerts($stats);
                
            } else {
                error_log('QvaClick Debug: IMAP processing failed');
                $this->send_error_alert('IMAP processing failed');
            }
            
        } catch (Exception $e) {
            error_log('QvaClick Debug: IMAP processing exception: ' . $e->getMessage());
            $this->send_error_alert('IMAP processing exception: ' . $e->getMessage());
        }
    }
    
    /**
     * Cleanup old emails and logs
     */
    public function cleanup_old_emails() {
        global $wpdb;
        
        $cleanup_settings = get_option('qvc_cleanup_settings', array(
            'quarantine_days' => 30,
            'processed_inbox_days' => 90,
            'security_log_days' => 60,
            'spam_log_days' => 30,
            'classification_log_days' => 180
        ));
        
        $cleaned = array();
        
        // Cleanup quarantine
        $table = $wpdb->prefix . 'qvc_email_quarantine';
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $cleanup_settings['quarantine_days']
        ));
        $cleaned['quarantine'] = $result;
        
        // Cleanup processed inbox emails
        $table = $wpdb->prefix . 'qvc_general_inbox';
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE status = 'processed' AND processed_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $cleanup_settings['processed_inbox_days']
        ));
        $cleaned['processed_inbox'] = $result;
        
        // Cleanup security logs
        $table = $wpdb->prefix . 'qvc_email_security_log';
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE scan_time < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $cleanup_settings['security_log_days']
        ));
        $cleaned['security_log'] = $result;
        
        // Cleanup spam logs
        $table = $wpdb->prefix . 'qvc_spam_log';
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE detected_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $cleanup_settings['spam_log_days']
        ));
        $cleaned['spam_log'] = $result;
        
        // Cleanup classification logs
        $table = $wpdb->prefix . 'qvc_email_classifications';
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $cleanup_settings['classification_log_days']
        ));
        $cleaned['classification_log'] = $result;
        
        error_log('QvaClick Cleanup: ' . json_encode($cleaned));
        
        // Optimize database tables
        $this->optimize_database_tables();
    }
    
    /**
     * Security maintenance tasks
     */
    public function security_maintenance() {
        // Update spam patterns based on recent detections
        $this->update_spam_patterns();
        
        // Check for new suspicious domains
        $this->check_suspicious_domains();
        
        // Update security statistics
        $this->update_security_stats();
        
        // Cleanup rate limiting transients
        $this->cleanup_rate_limiting();
    }
    
    /**
     * Optimize classification algorithms
     */
    public function optimize_classification() {
        // Analyze classification accuracy
        $accuracy_stats = $this->analyze_classification_accuracy();
        
        // Update keyword weights based on corrections
        $this->update_keyword_weights();
        
        // Optimize auto-assignment rules
        $this->optimize_assignment_rules();
        
        error_log('QvaClick Classification: Optimization completed - Accuracy: ' . $accuracy_stats['overall_accuracy'] . '%');
    }
    
    /**
     * Generate daily reports
     */
    public function generate_daily_reports() {
        $report_data = $this->collect_daily_stats();
        
        // Save report to database
        $this->save_daily_report($report_data);
        
        // Send email report if enabled
        if (get_option('qvc_daily_reports_enabled', false)) {
            $this->send_daily_report_email($report_data);
        }
        
        error_log('QvaClick Reports: Daily report generated');
    }
    
    /**
     * Update processing statistics
     */
    private function update_processing_stats($stats) {
        $current_stats = get_option('qvc_processing_stats', array(
            'total_processed' => 0,
            'tickets_created' => 0,
            'leads_created' => 0,
            'general_stored' => 0,
            'quarantined' => 0,
            'spam_blocked' => 0,
            'errors' => 0,
            'last_updated' => time()
        ));
        
        foreach ($stats as $key => $value) {
            if (isset($current_stats[$key])) {
                $current_stats[$key] += $value;
            }
        }
        
        $current_stats['last_updated'] = time();
        update_option('qvc_processing_stats', $current_stats);
    }
    
    /**
     * Check for processing alerts
     */
    private function check_processing_alerts($stats) {
        $alert_thresholds = get_option('qvc_alert_thresholds', array(
            'high_error_rate' => 0.2, // 20% error rate
            'high_quarantine_rate' => 0.3, // 30% quarantine rate
            'low_processing_rate' => 5 // Less than 5 emails per hour
        ));
        
        $total = $stats['processed'] + $stats['errors'];
        
        if ($total > 0) {
            $error_rate = $stats['errors'] / $total;
            $quarantine_rate = $stats['quarantined'] / $total;
            
            if ($error_rate > $alert_thresholds['high_error_rate']) {
                $this->send_alert('High error rate detected: ' . round($error_rate * 100, 2) . '%');
            }
            
            if ($quarantine_rate > $alert_thresholds['high_quarantine_rate']) {
                $this->send_alert('High quarantine rate detected: ' . round($quarantine_rate * 100, 2) . '%');
            }
        }
        
        if ($total < $alert_thresholds['low_processing_rate']) {
            $this->send_alert('Low email processing volume: ' . $total . ' emails in last interval');
        }
    }
    
    /**
     * Update spam patterns based on recent detections
     */
    private function update_spam_patterns() {
        global $wpdb;
        
        // Analyze recent spam detections
        $table = $wpdb->prefix . 'qvc_spam_log';
        $recent_spam = $wpdb->get_results("
            SELECT subject, sender_email 
            FROM {$table} 
            WHERE detected_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            AND confidence > 80
        ");
        
        if (!empty($recent_spam)) {
            $patterns = get_option('qvc_learned_spam_patterns', array());
            
            foreach ($recent_spam as $spam) {
                // Extract common patterns
                $domain = substr(strrchr($spam->sender_email, "@"), 1);
                $patterns['domains'][$domain] = ($patterns['domains'][$domain] ?? 0) + 1;
                
                // Common subject patterns
                if (preg_match('/\b(urgent|immediate|act now|limited time)\b/i', $spam->subject)) {
                    $patterns['urgent_words'] = ($patterns['urgent_words'] ?? 0) + 1;
                }
            }
            
            update_option('qvc_learned_spam_patterns', $patterns);
        }
    }
    
    /**
     * Check for suspicious domains
     */
    private function check_suspicious_domains() {
        global $wpdb;
        
        // Find domains with high bounce/quarantine rates
        $table = $wpdb->prefix . 'qvc_email_security_log';
        $suspicious_domains = $wpdb->get_results("
            SELECT 
                SUBSTRING_INDEX(sender_email, '@', -1) as domain,
                COUNT(*) as total_emails,
                SUM(CASE WHEN is_safe = 0 THEN 1 ELSE 0 END) as unsafe_emails,
                (SUM(CASE WHEN is_safe = 0 THEN 1 ELSE 0 END) / COUNT(*)) as risk_ratio
            FROM {$table} 
            WHERE scan_time > DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY domain
            HAVING total_emails >= 5 AND risk_ratio > 0.8
        ");
        
        $blacklist = get_option('qvc_email_blacklist', array());
        $updated = false;
        
        foreach ($suspicious_domains as $domain_data) {
            if (!in_array($domain_data->domain, $blacklist)) {
                $blacklist[] = $domain_data->domain;
                $updated = true;
                error_log("QvaClick Security: Auto-blacklisted domain: {$domain_data->domain} (risk ratio: {$domain_data->risk_ratio})");
            }
        }
        
        if ($updated) {
            update_option('qvc_email_blacklist', $blacklist);
        }
    }
    
    /**
     * Update security statistics
     */
    private function update_security_stats() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qvc_email_security_log';
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_scans,
                AVG(security_score) as avg_security_score,
                SUM(CASE WHEN is_safe = 1 THEN 1 ELSE 0 END) as safe_emails,
                SUM(CASE WHEN quarantined = 1 THEN 1 ELSE 0 END) as quarantined_emails
            FROM {$table} 
            WHERE scan_time > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        
        if ($stats && $stats->total_scans > 0) {
            $security_stats = array(
                'daily_scans' => $stats->total_scans,
                'avg_security_score' => round($stats->avg_security_score, 2),
                'safety_rate' => round(($stats->safe_emails / $stats->total_scans) * 100, 2),
                'quarantine_rate' => round(($stats->quarantined_emails / $stats->total_scans) * 100, 2),
                'last_updated' => time()
            );
            
            update_option('qvc_daily_security_stats', $security_stats);
        }
    }
    
    /**
     * Cleanup rate limiting transients
     */
    private function cleanup_rate_limiting() {
        global $wpdb;
        
        // Cleanup old rate limiting transients
        $wpdb->query("
            DELETE FROM {$wpdb->prefix}options 
            WHERE option_name LIKE '_transient_qvc_email_rate_%' 
            AND option_value = ''
        ");
    }
    
    /**
     * Analyze classification accuracy
     */
    private function analyze_classification_accuracy() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qvc_email_classifications';
        $accuracy_data = $wpdb->get_results("
            SELECT 
                category,
                COUNT(*) as total,
                SUM(CASE WHEN human_verified = 1 THEN 1 ELSE 0 END) as verified,
                AVG(confidence) as avg_confidence
            FROM {$table} 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY category
        ");
        
        $overall_accuracy = 0;
        $total_classified = 0;
        
        foreach ($accuracy_data as $data) {
            if ($data->total > 0) {
                $accuracy = ($data->verified / $data->total) * 100;
                $overall_accuracy += $accuracy * $data->total;
                $total_classified += $data->total;
            }
        }
        
        if ($total_classified > 0) {
            $overall_accuracy = $overall_accuracy / $total_classified;
        }
        
        return array(
            'overall_accuracy' => round($overall_accuracy, 2),
            'by_category' => $accuracy_data,
            'total_classified' => $total_classified
        );
    }
    
    /**
     * Update keyword weights based on corrections
     */
    private function update_keyword_weights() {
        // Implement machine learning algorithm to adjust weights
        // Based on human corrections and classification accuracy
        
        global $wpdb;
        $table = $wpdb->prefix . 'qvc_email_classifications';
        
        $corrections = $wpdb->get_results("
            SELECT * FROM {$table} 
            WHERE human_verified = 1 
            AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        
        // Simple learning algorithm - increase weights for accurate patterns
        $learning_data = get_option('qvc_classification_learning', array());
        
        foreach ($corrections as $correction) {
            $category = $correction->category;
            if (!isset($learning_data['successful_patterns'][$category])) {
                $learning_data['successful_patterns'][$category] = array();
            }
            
            // Extract keywords from subject and increase their weights
            $keywords = explode(' ', strtolower($correction->subject));
            foreach ($keywords as $keyword) {
                if (strlen($keyword) > 3) {
                    $learning_data['successful_patterns'][$category][$keyword] = 
                        ($learning_data['successful_patterns'][$category][$keyword] ?? 0) + 1;
                }
            }
        }
        
        update_option('qvc_classification_learning', $learning_data);
    }
    
    /**
     * Optimize assignment rules
     */
    private function optimize_assignment_rules() {
        // Analyze auto-assignment success rates
        global $wpdb;
        
        $tickets_table = $wpdb->prefix . 'qvc_support_tickets';
        $leads_table = $wpdb->prefix . 'qvc_sales_leads';
        
        // Check response times for auto-assigned vs manual assignments
        $auto_assigned_avg = $wpdb->get_var("
            SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at))
            FROM {$tickets_table}
            WHERE assigned_to IS NOT NULL 
            AND source = 'email_imap'
            AND updated_at IS NOT NULL
            AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        
        if ($auto_assigned_avg > 0) {
            $optimization_stats = array(
                'auto_assignment_avg_response_hours' => round($auto_assigned_avg, 2),
                'last_optimized' => time()
            );
            
            update_option('qvc_assignment_optimization_stats', $optimization_stats);
        }
    }
    
    /**
     * Collect daily statistics
     */
    private function collect_daily_stats() {
        global $wpdb;
        
        $stats = array();
        
        // General inbox stats
        $table = $wpdb->prefix . 'qvc_general_inbox';
        $stats['general_inbox'] = $wpdb->get_row("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'unread' THEN 1 ELSE 0 END) as unread,
                SUM(CASE WHEN created_at >= CURDATE() THEN 1 ELSE 0 END) as today
            FROM {$table}
        ");
        
        // Tickets stats
        $table = $wpdb->prefix . 'qvc_support_tickets';
        $stats['tickets'] = $wpdb->get_row("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
                SUM(CASE WHEN created_at >= CURDATE() THEN 1 ELSE 0 END) as today
            FROM {$table}
        ");
        
        // Sales leads stats
        $table = $wpdb->prefix . 'qvc_sales_leads';
        $stats['leads'] = $wpdb->get_row("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new,
                SUM(CASE WHEN created_at >= CURDATE() THEN 1 ELSE 0 END) as today
            FROM {$table}
        ");
        
        // Security stats
        $table = $wpdb->prefix . 'qvc_email_quarantine';
        $stats['security'] = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_quarantined,
                SUM(CASE WHEN created_at >= CURDATE() THEN 1 ELSE 0 END) as today_quarantined
            FROM {$table}
        ");
        
        return $stats;
    }
    
    /**
     * Save daily report
     */
    private function save_daily_report($data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qvc_daily_reports';
        
        $wpdb->insert(
            $table,
            array(
                'report_date' => current_time('Y-m-d'),
                'report_data' => json_encode($data),
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s')
        );
    }
    
    /**
     * Send daily report email
     */
    private function send_daily_report_email($data) {
        $to = get_option('qvc_reports_email', get_option('admin_email'));
        $subject = 'QvaClick Email Manager - Reporte Diario ' . current_time('Y-m-d');
        
        $message = "Reporte diario del sistema de emails QvaClick:\n\n";
        $message .= "BANDEJA GENERAL:\n";
        $message .= "- Total: {$data['general_inbox']->total}\n";
        $message .= "- Sin leer: {$data['general_inbox']->unread}\n";
        $message .= "- Hoy: {$data['general_inbox']->today}\n\n";
        
        $message .= "TICKETS DE SOPORTE:\n";
        $message .= "- Total: {$data['tickets']->total}\n";
        $message .= "- Abiertos: {$data['tickets']->open}\n";
        $message .= "- Hoy: {$data['tickets']->today}\n\n";
        
        $message .= "LEADS DE VENTAS:\n";
        $message .= "- Total: {$data['leads']->total}\n";
        $message .= "- Nuevos: {$data['leads']->new}\n";
        $message .= "- Hoy: {$data['leads']->today}\n\n";
        
        $message .= "SEGURIDAD:\n";
        $message .= "- Total en cuarentena: {$data['security']->total_quarantined}\n";
        $message .= "- Hoy en cuarentena: {$data['security']->today_quarantined}\n";
        
        wp_mail($to, $subject, $message);
    }
    
    /**
     * Optimize database tables
     */
    private function optimize_database_tables() {
        global $wpdb;
        
        $tables = array(
            $wpdb->prefix . 'qvc_general_inbox',
            $wpdb->prefix . 'qvc_email_quarantine',
            $wpdb->prefix . 'qvc_email_security_log',
            $wpdb->prefix . 'qvc_email_classifications',
            $wpdb->prefix . 'qvc_spam_log'
        );
        
        foreach ($tables as $table) {
            $wpdb->query("OPTIMIZE TABLE {$table}");
        }
    }
    
    /**
     * Send alert notifications
     */
    private function send_alert($message) {
        $alert_email = get_option('qvc_alert_email', get_option('admin_email'));
        $subject = 'QvaClick Email Manager - Alert';
        
        wp_mail($alert_email, $subject, $message);
        error_log('QvaClick Alert: ' . $message);
    }
    
    /**
     * Send error alert
     */
    private function send_error_alert($error_message) {
        $this->send_alert('Error en el sistema: ' . $error_message);
    }
    
    /**
     * Unschedule all events (for deactivation)
     */
    public static function unschedule_events() {
        wp_clear_scheduled_hook('qvc_check_enhanced_imap_emails');
        wp_clear_scheduled_hook('qvc_cleanup_old_emails');
        wp_clear_scheduled_hook('qvc_security_maintenance');
        wp_clear_scheduled_hook('qvc_classification_optimization');
        wp_clear_scheduled_hook('qvc_daily_reports');
    }
    
    /**
     * Create daily reports table
     */
    public static function create_reports_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'qvc_daily_reports';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            report_date date NOT NULL,
            report_data longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY report_date (report_date),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

// Add custom cron intervals
add_filter('cron_schedules', array('QvaClick_Enhanced_Cron_Manager', 'add_cron_intervals'));

// Initialize enhanced cron manager
new QvaClick_Enhanced_Cron_Manager();
?>
