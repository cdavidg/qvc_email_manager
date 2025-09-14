<?php
/**
 * IMAP Email Reader
 * 
 * @package QvaClick_Email_Manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class QvaClick_IMAP_Reader {
    
    private $connection;
    private $config;
    
    public function __construct() {
        $this->config = get_option('qvc_imap_config', array());
    }
    
    /**
     * Process new emails from IMAP with security and classification
     */
    public function process_new_emails() {
        error_log('QvaClick Debug: Starting IMAP email processing...');
        
        if (!$this->config['enabled']) {
            error_log('QvaClick Debug: IMAP is disabled');
            return false;
        }
        
        if (!$this->connect()) {
            error_log('QvaClick Debug: Failed to connect to IMAP server');
            return false;
        }
        
        error_log('QvaClick Debug: IMAP connection successful, checking for emails...');
        
        // Initialize security scanner and classifier
        require_once plugin_dir_path(__FILE__) . 'class-email-security-scanner.php';
        require_once plugin_dir_path(__FILE__) . 'class-email-classifier.php';
        
        $security_scanner = new QvaClick_Email_Security_Scanner();
        $classifier = new QvaClick_Email_Classifier();
        
        $processed = 0;
        $errors = 0;
        $quarantined = 0;
        
        try {
            // Get unread emails
            $emails = $this->get_unread_emails();
            
            if (empty($emails)) {
                error_log('QvaClick Debug: No unread emails found');
                return array('processed' => 0, 'errors' => 0, 'quarantined' => 0);
            }
            
            foreach ($emails as $email_num) {
                error_log("QvaClick Debug: Processing email #$email_num");
                
                $email_data = $this->get_email_data($email_num);
                
                if (!$email_data) {
                    error_log("QvaClick Debug: Failed to get email data for #$email_num");
                    $errors++;
                    continue;
                }
                
                // 1. ESCANEO DE SEGURIDAD
                $security_result = $security_scanner->scan_email($email_data);
                
                // Si está en cuarentena, no procesar
                if ($security_result['quarantine']) {
                    $this->quarantine_email($email_data, $security_result);
                    $this->mark_as_read($email_num);
                    $quarantined++;
                    continue;
                }
                
                // 2. CLASIFICACIÓN INTELIGENTE
                $classification = $classifier->classify_email($email_data);
                
                // 3. PROCESAR SEGÚN CLASIFICACIÓN
                if ($this->process_classified_email($email_data, $classification, $security_result)) {
                    $processed++;
                    $this->mark_as_read($email_num);
                } else {
                    $errors++;
                }
            }
            
        } catch (Exception $e) {
            error_log('QvaClick Debug: IMAP processing error: ' . $e->getMessage());
            $errors++;
        } finally {
            $this->disconnect();
        }
        
        // NUEVO: Procesar emails pendientes en bandeja general
        $pending_processed = $this->process_pending_inbox_emails();
        $processed += $pending_processed['processed'];
        $errors += $pending_processed['errors'];
        
        error_log("QvaClick Debug: Total processed: $processed, errors: $errors, quarantined: $quarantined, pending: {$pending_processed['processed']}");
        
        return array(
            'processed' => $processed,
            'errors' => $errors,
            'quarantined' => $quarantined
        );
    }
    
    /**
     * Connect to IMAP server
     */
    private function connect() {
        if (empty($this->config['imap_host']) || empty($this->config['imap_username'])) {
            return false;
        }
        
        // Build connection string
        $encryption = '';
        if ($this->config['imap_encryption'] === 'ssl') {
            $encryption = '/ssl';
        } elseif ($this->config['imap_encryption'] === 'tls') {
            $encryption = '/tls';
        }
        
        $mailbox = '{' . $this->config['imap_host'] . ':' . $this->config['imap_port'] . $encryption . '}' . $this->config['imap_folder'];
        
        $this->connection = @imap_open($mailbox, $this->config['imap_username'], $this->config['imap_password']);
        
        if (!$this->connection) {
            error_log('QvaClick Debug: IMAP connection failed: ' . imap_last_error());
            return false;
        }
        
        return true;
    }
    
    /**
     * Disconnect from IMAP server
     */
    private function disconnect() {
        if ($this->connection) {
            imap_close($this->connection);
            $this->connection = null;
        }
    }
    
    /**
     * Get unread email numbers
     */
    private function get_unread_emails() {
        $unread = imap_search($this->connection, 'UNSEEN');
        $emails = $unread ? $unread : array();
        error_log('QvaClick Debug: Found ' . count($emails) . ' unread emails');
        return $emails;
    }
    
    /**
     * Process a single email
     */
    private function process_single_email($email_num) {
        try {
            // Get email headers
            $header = imap_headerinfo($this->connection, $email_num);
            $structure = imap_fetchstructure($this->connection, $email_num);
            
            // Extract email data
            $from_email = $this->extract_email_address($header->from[0]);
            $from_name = $this->extract_name($header->from[0]);
            $subject = $this->decode_header($header->subject);
            $date = date('Y-m-d H:i:s', strtotime($header->date));
            
            // Get email body
            $body = $this->get_email_body($email_num, $structure);
            
            // Clean the body FIRST to get better content
            $body = $this->clean_email_content($body);
            
            error_log("QvaClick Debug: Processing email from $from_email - Subject: $subject");
            
            // IMPROVED: Check if this is a reply to an existing ticket
            $existing_ticket = $this->find_existing_ticket_advanced($from_email, $subject, $body);
            
            if ($existing_ticket) {
                error_log("QvaClick Debug: Found existing ticket: {$existing_ticket['ticket_id']}");
                return $this->add_reply_to_ticket($existing_ticket['ticket_id'], $from_email, $from_name, $body);
            }
            
            // Check if this should create a new ticket
            if ($this->should_create_ticket($from_email, $subject, $body)) {
                return $this->create_ticket_from_email($from_email, $from_name, $subject, $body);
            }
            
            error_log('QvaClick Debug: Email does not meet criteria for ticket creation');
            return true; // Consider it processed even if no action taken
            
        } catch (Exception $e) {
            error_log('QvaClick Debug: Error processing email #' . $email_num . ': ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Extract email address from address object
     */
    private function extract_email_address($address_obj) {
        if (isset($address_obj->mailbox) && isset($address_obj->host)) {
            return $address_obj->mailbox . '@' . $address_obj->host;
        }
        return '';
    }
    
    /**
     * Extract name from address object
     */
    private function extract_name($address_obj) {
        if (isset($address_obj->personal)) {
            return $this->decode_header($address_obj->personal);
        }
        return 'Usuario';
    }
    
    /**
     * Decode email header
     */
    private function decode_header($header) {
        $decoded = imap_mime_header_decode($header);
        $result = '';
        foreach ($decoded as $part) {
            $result .= $part->text;
        }
        return $result;
    }
    
    /**
     * Get email body content
     */
    private function get_email_body($email_num, $structure) {
        $body = '';
        
        if (!isset($structure->parts)) {
            // Simple email
            $body = imap_fetchbody($this->connection, $email_num, 1);
            
            if ($structure->encoding == 4) {
                $body = quoted_printable_decode($body);
            } elseif ($structure->encoding == 3) {
                $body = base64_decode($body);
            }
        } else {
            // Multi-part email
            foreach ($structure->parts as $part_num => $part) {
                $part_body = imap_fetchbody($this->connection, $email_num, $part_num + 1);
                
                if ($part->encoding == 4) {
                    $part_body = quoted_printable_decode($part_body);
                } elseif ($part->encoding == 3) {
                    $part_body = base64_decode($part_body);
                }
                
                // Prefer text/plain over text/html
                if (isset($part->subtype) && strtolower($part->subtype) === 'plain') {
                    $body = $part_body;
                    break;
                } elseif (empty($body) && isset($part->subtype) && strtolower($part->subtype) === 'html') {
                    $body = strip_tags($part_body);
                }
            }
        }
        
        return $body;
    }
    
    /**
     * Enhanced email content cleaning - MEJORADO para eliminar firmas y coletillas
     */
    private function clean_email_content($content) {
        // Convert to plain text if HTML
        if (strpos($content, '<html') !== false || strpos($content, '<body') !== false) {
            $content = strip_tags($content);
        }
        
        // Split into lines for processing
        $lines = explode("\n", $content);
        $cleaned_lines = [];
        $found_separator = false;
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip empty lines at start
            if (empty($line) && empty($cleaned_lines)) {
                continue;
            }
            
            // MEJORADO: Patrones más exhaustivos para detectar email trails
            $separators = [
                '/^[\-\=\_]{3,}/',                  // --- or === or ___
                '/^On .* wrote:/',                  // On [date] [person] wrote:
                '/^El .* escribió:/',               // Spanish version
                '/^En .* escribió:/',               // Alternate Spanish
                '/^From:.*Sent:.*To:.*Subject:/',   // Outlook headers
                '/^De:.*Enviado:.*Para:.*Asunto:/', // Spanish Outlook
                '/^\>.*wrote:/',                    // > Person wrote:
                '/^[0-9]{1,2}\/[0-9]{1,2}\/[0-9]{2,4}.*wrote:/', // Date wrote:
                '/^.*<.*@.*>.*wrote:/',             // email@domain wrote:
                '/^Begin forwarded message:/',      // Apple Mail
                '/^---------- Forwarded message /', // Gmail
                '/^From:.*\[mailto:/',              // Outlook forward
                '/^This email was sent by.*via/',   // Contact form signatures
                '/^-----Original Message-----/',     // Outlook original message
                '/^-----Mensaje original-----/',     // Spanish Outlook
                '/^From:.*$/',                      // Email headers
                '/^Sent:.*$/',
                '/^To:.*$/',
                '/^Subject:.*$/',
                '/^De:.*$/',
                '/^Enviado:.*$/',
                '/^Para:.*$/',
                '/^Asunto:.*$/',
                '/^Reply-To:.*$/',
                '/^Responder a:.*$/'
            ];
            
            foreach ($separators as $pattern) {
                if (preg_match($pattern, $line)) {
                    $found_separator = true;
                    break 2;
                }
            }
            
            // MEJORADO: Patrones más exhaustivos para firmas
            $signature_patterns = [
                '/^--$/',                           // Standard signature separator
                '/^Enviado desde mi /',             // Sent from my...
                '/^Sent from my /',
                '/^Get Outlook for /',
                '/^Descarga Outlook para/',
                '/^Obtener Outlook para/',
                '/^Este correo.*confidencial/',     // Confidentiality notices
                '/^This email.*confidential/',
                '/^AVISO LEGAL/',
                '/^LEGAL NOTICE/',
                '/^P\s*Por favor.*imprimir/',       // Think before printing
                '/^Please consider.*printing/',
                '/^\[cid:image/',                   // Embedded images
                '/^Virus-free.*avast/',             // Antivirus signatures
                '/^<#secure#>/',                    // Security signatures
                '/^ATENCIÓN.*virus/',
                '/^WARNING.*virus/',
                '/^Para ver este.*correo/',         // View this email...
                '/^Confidentiality Notice:/',       // Legal disclaimers
                '/^The information contained/',
                '/^La información contenida/',
                '/^Gracias/',                       // Common sign-offs
                '/^Thanks/',
                '/^Saludos/',
                '/^Best regards/',
                '/^Atentamente/',
                '/^Cordialmente/',
                '/^Un saludo/',
                '/^Kind regards/',
                '/^Sinceramente/',
                '/^www\..*\.com$/',                 // Website signatures
                '/^https?:\/\//',                   // URLs at line start
                '/^\+[0-9\s\-\(\)]+$/',            // Phone numbers
                '/^[A-Z][a-z]+\s+[A-Z][a-z]+$/',   // Name only lines
                '/^CEO|CTO|Director|Manager$/',     // Job titles
                '/^Powered by/',                    // Email provider signatures
                '/^Enviado por/',
                '/^\*\*\*.*\*\*\*/',               // *** disclaimers ***
                '/^IMPORTANTE:/',                   // Important notices
                '/^IMPORTANT:/',
                '/^NOTA:/',
                '/^NOTE:/',
                '/^P\.D\./',                        // Postscripts
                '/^P\.S\./',
                '/^PS:/',
                '/^PD:/'
            ];
            
            foreach ($signature_patterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    $found_separator = true;
                    break 2;
                }
            }
            
            // Stop at quoted content (lines starting with >)
            if (preg_match('/^>+\s*/', $line)) {
                break;
            }
            
            // Stop at email footers with multiple hyphens or equals
            if (preg_match('/^[\-=]{5,}/', $line)) {
                break;
            }
            
            // Add line if we haven't found a separator
            if (!$found_separator) {
                $cleaned_lines[] = $line;
            } else {
                break;
            }
        }
        
        // Join and clean up
        $cleaned = implode("\n", $cleaned_lines);
        
        // Remove excessive whitespace
        $cleaned = preg_replace('/\n{3,}/', "\n\n", $cleaned);
        $cleaned = trim($cleaned);
        
        // If content is too short after cleaning, return original (might be over-aggressive)
        if (strlen($cleaned) < 20 && strlen($content) > 50) {
            error_log("QvaClick Debug: Content cleaning too aggressive, keeping original");
            return trim($content);
        }
        
        return $cleaned;
    }
    
    /**
     * Mark email as read
     */
    private function mark_as_read($email_num) {
        imap_setflag_full($this->connection, $email_num, "\\Seen");
    }
    
    /**
     * Check if email should create a ticket
     */
    private function should_create_ticket($from_email, $subject, $body) {
        // PROTECCIÓN CONTRA BUCLE INFINITO - EXCLUIR EMAILS DE SISTEMA
        
        // 1. No procesar emails que vengan de nuestro propio sistema
        $system_emails = array(
            'noreply@qvaclick.com',
            'no-reply@qvaclick.com', // Email de respuestas automáticas (DEBE EXISTIR)
            'system@qvaclick.com',
            'support@qvaclick.com', // Email de recepción de tickets
            'soporte@qvaclick.com'
        );
        
        if (in_array(strtolower($from_email), array_map('strtolower', $system_emails))) {
            error_log("QvaClick Debug: BLOQUEADO - Email de sistema detectado: $from_email");
            return false;
        }
        
        // 2. REMOVED: No bloquear emails con Ticket # porque pueden ser respuestas a tickets inexistentes
        // La detección de tickets existentes se hace ANTES en find_existing_ticket_advanced
        
        // 3. No procesar confirmaciones automáticas
        $auto_subjects = array(
            'ticket', 'confirmaci', 'confirmation', 'automatic', 'automatico',
            'abierto en qvaclick', 'opened in qvaclick', 'respuesta automatica'
        );
        
        $subject_lower = strtolower($subject);
        foreach ($auto_subjects as $auto_pattern) {
            if (strpos($subject_lower, $auto_pattern) !== false) {
                error_log("QvaClick Debug: BLOQUEADO - Confirmación automática detectada: $subject");
                return false;
            }
        }
        
        // 4. No procesar si el body contiene texto de confirmación típico
        $body_lower = strtolower($body);
        $confirmation_patterns = array(
            'ticket creado automáticamente',
            'ticket created automatically',
            'no responder a este email',
            'do not reply to this email',
            'este es un mensaje automático'
        );
        
        foreach ($confirmation_patterns as $pattern) {
            if (strpos($body_lower, $pattern) !== false) {
                error_log("QvaClick Debug: BLOQUEADO - Contenido de confirmación detectado");
                return false;
            }
        }
        
        // Get support email addresses
        $admin_email_manager = QvaClick_Admin_Email_Manager::get_instance();
        $support_email = $admin_email_manager->get_support_from_email();
        
        // List of emails that receive support requests
        $support_emails = array(
            $support_email,
            'support@qvaclick.com',
            'soporte@qvaclick.com',
            get_option('admin_email')
        );
        
        // Check if email was sent to a support address
        // (This would need to be determined from email headers)
        
        // Check for support keywords in subject
        $support_keywords = array(
            'contacto', 'contact', 'soporte', 'support', 'ayuda', 'help',
            'problema', 'issue', 'consulta', 'question', 'error', 'bug'
        );
        
        $has_support_keyword = false;
        foreach ($support_keywords as $keyword) {
            if (strpos($subject_lower, $keyword) !== false) {
                $has_support_keyword = true;
                break;
            }
        }
        
        // Check message length (avoid very short messages)
        if (strlen(trim($body)) < 20) {
            error_log("QvaClick Debug: BLOQUEADO - Mensaje demasiado corto");
            return false;
        }
        
        // TEMPORALMENTE MÁS PERMISIVO: Crear ticket para casi todos los emails
        // Solo bloquear si es claramente un email automático o de sistema
        
        // Check for obvious automated emails
        $automated_patterns = [
            'unsubscribe', 'no-reply', 'noreply', 'mailer-daemon',
            'automated', 'automatic', 'delivery failure', 'mail delivery'
        ];
        
        $email_content = strtolower($from_email . ' ' . $subject . ' ' . $body);
        foreach ($automated_patterns as $pattern) {
            if (strpos($email_content, $pattern) !== false) {
                error_log("QvaClick Debug: BLOQUEADO - Email automático detectado: $pattern");
                return false;
            }
        }
        
        error_log("QvaClick Debug: PERMITIDO - Email válido de: $from_email - Asunto: $subject");
        return true; // TEMPORALMENTE: Crear tickets para la mayoría de emails
    }
    
    /**
     * Create ticket from email - Para qvc_support_tickets
     */
    private function create_ticket_from_email($from_email, $from_name, $subject, $body) {
        global $wpdb;
        
        // Check for duplicates
        if ($this->is_duplicate_ticket($from_email, $subject)) {
            error_log('QvaClick Debug: Duplicate ticket detected, skipping');
            return true; // Consider it processed
        }
        
        // Insert directly into qvc_support_tickets table
        $table_name = $wpdb->prefix . 'qvc_support_tickets';
        
        $ticket_data = array(
            'user_id' => 0,
            'user_email' => $from_email,
            'user_name' => $from_name,
            'subject' => $subject,
            'message' => $body,
            'status' => 'new', // Default status for IMAP tickets
            'priority' => 'normal',
            'type' => 'imap',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );
        
        $result = $wpdb->insert($table_name, $ticket_data);
        
        if ($result) {
            $ticket_id = $wpdb->insert_id;
            error_log('QvaClick Debug: Ticket created from IMAP - ID: ' . $ticket_id);
            
            // Activar hook de ordenamiento cronológico para ticket nuevo
            do_action('qvc_ticket_created', $ticket_id, array(
                'user_email' => $from_email,
                'subject' => $subject,
                'from_imap' => true,
                'email_timestamp' => current_time('timestamp')
            ));
            
            // Send auto-confirmation if configured
            $this->send_confirmation_email($ticket_id, $from_email, $from_name, $subject);
            
            return $ticket_id;
        }
        
        error_log('QvaClick Debug: Failed to create ticket from IMAP');
        return false;
    }
    
    /**
     * Add reply to existing ticket - IMPROVED para qvc_ticket_messages
     */
    private function add_reply_to_ticket($ticket_id, $from_email, $from_name, $body) {
        global $wpdb;
        
        // Validar parámetros obligatorios
        if (empty($from_email)) {
            error_log("QvaClick Debug: ❌ from_email is empty, cannot add reply");
            return false;
        }
        
        // Asegurar que from_name tenga un valor
        if (empty($from_name)) {
            $from_name = $from_email; // Usar email como fallback
        }
        
        error_log("QvaClick Debug: Adding reply - Email: $from_email, Name: $from_name, Ticket: $ticket_id");
        
        // Ensure we have the correct ticket_id format for messages table
        // The ticket_id from find_existing_ticket_advanced might be the database ID or ticket_id field
        $ticket_info = null;
        
        // Try to get the ticket information to ensure we have the right reference
        if (is_numeric($ticket_id)) {
            // If it's numeric, it might be the database ID
            $ticket_info = $wpdb->get_row($wpdb->prepare(
                "SELECT id, ticket_id FROM {$wpdb->prefix}qvc_support_tickets WHERE id = %d OR ticket_id = %s",
                intval($ticket_id), $ticket_id
            ), ARRAY_A);
        } else {
            // If it's not numeric, it's probably the ticket_id field
            $ticket_info = $wpdb->get_row($wpdb->prepare(
                "SELECT id, ticket_id FROM {$wpdb->prefix}qvc_support_tickets WHERE ticket_id = %s",
                $ticket_id
            ), ARRAY_A);
        }
        
        if (!$ticket_info) {
            error_log("QvaClick Debug: ❌ Could not find ticket information for: $ticket_id");
            return false;
        }
        
        // Use the ticket_id field for messages (this is usually what the messages table expects)
        $final_ticket_id = $ticket_info['ticket_id'] ?: $ticket_info['id'];
        
        error_log("QvaClick Debug: Adding reply to ticket. Original: $ticket_id, Final: $final_ticket_id");
        
        // Limpiar el contenido del email antes de guardarlo
        $clean_body = $this->clean_email_content($body);
        
        // Insert directly into qvc_ticket_messages table
        $messages_table = $wpdb->prefix . 'qvc_ticket_messages';
        
        $message_data = array(
            'ticket_id' => $final_ticket_id,
            'user_id' => 0,
            'user_email' => $from_email,
            'user_name' => $from_name,
            'user_type' => 'guest',
            'message' => $clean_body,
            'is_admin_reply' => 0,
            'created_at' => current_time('mysql')
        );
        
        $result = $wpdb->insert($messages_table, $message_data);
        
        if ($result) {
            $message_id = $wpdb->insert_id;
            error_log('QvaClick Debug: Reply added to ticket #' . $final_ticket_id . ' from IMAP - Message ID: ' . $message_id);
            
            // Activar hook de ordenamiento cronológico para mensaje nuevo
            do_action('qvc_ticket_message_created', $message_id, array(
                'user_email' => $from_email,
                'ticket_id' => $final_ticket_id,
                'from_imap' => true,
                'email_timestamp' => current_time('timestamp')
            ));
            
            // Reopen ticket if it was closed - use final_ticket_id
            $this->reopen_ticket_if_needed($final_ticket_id);
            
            // NUEVO: Notificar a administradores que el ticket ha sido actualizado
            if (class_exists('QvaClick_Notification_System')) {
                QvaClick_Notification_System::notify_ticket_updated($final_ticket_id, $from_email, "Nueva respuesta recibida");
                error_log("QvaClick Debug: ✅ Notification sent for updated ticket #$final_ticket_id");
            }
            
            return $message_id;
        }
        
        error_log('QvaClick Debug: Failed to add reply to ticket #' . $final_ticket_id);
        return false;
    }
    
    /**
     * Check for duplicate tickets
     */
    private function is_duplicate_ticket($email, $subject) {
        global $wpdb;
        
        $time_threshold = date('Y-m-d H:i:s', strtotime('-10 minutes'));
        $table_name = $wpdb->prefix . 'qvc_support_tickets';
        
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT ticket_id FROM $table_name 
             WHERE user_email = %s 
             AND subject = %s 
             AND created_at > %s 
             ORDER BY created_at DESC 
             LIMIT 1",
            $email,
            $subject,
            $time_threshold
        ));
        
        return !empty($existing);
    }
    
    /**
     * Reopen ticket if needed - MEJORADO para gestión de estados y respuestas
     */
    private function reopen_ticket_if_needed($ticket_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qvc_support_tickets';
        
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT status FROM {$table} WHERE ticket_id = %s",
            $ticket_id
        ));
        
        if ($ticket) {
            // CORREGIDO: Siempre marcar como "abierto" cuando hay respuesta de usuario
            // También actualizar el campo last_response_type para tracking
            $wpdb->update(
                $table,
                array(
                    'status' => 'open',
                    'last_response_type' => 'user_response',
                    'updated_at' => current_time('mysql')
                ),
                array('ticket_id' => $ticket_id)
            );
            
            error_log('QvaClick Debug: Ticket #' . $ticket_id . ' marcado como ABIERTO por respuesta de usuario');
        }
    }
    
    /**
     * Send confirmation email
     */
    private function send_confirmation_email($ticket_id, $user_email, $user_name, $subject) {
        $admin_email_manager = QvaClick_Admin_Email_Manager::get_instance();
        $support_email = $admin_email_manager->get_support_from_email();
        $support_name = $admin_email_manager->get_support_from_name();
        
        $email_subject = sprintf('Ticket #%s de soporte abierto en QvaClick', $ticket_id);
        
        $email_content = sprintf(
            'Estimado/a %s,

Hemos recibido su mensaje de soporte por email y le atenderemos lo antes posible.

Detalles del ticket:
- Ticket ID: #%s
- Asunto: %s
- Estado: Abierto

Su solicitud ha sido registrada automáticamente en nuestro sistema de soporte. Nuestro equipo revisará su consulta y le responderá a la brevedad posible.

Para agregar información adicional a este ticket, responda directamente a este email manteniendo el número de ticket en el asunto.

Gracias por contactar con QvaClick.

---
Equipo de Soporte QvaClick
https://qvaclick.com',
            $user_name,
            $ticket_id,
            $subject
        );
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $support_name . ' <' . $support_email . '>',
            'Reply-To: ' . $support_email
        );
        
        return wp_mail($user_email, $email_subject, $email_content, $headers);
    }
    
    /**
     * Obtener datos completos del email
     */
    private function get_email_data($email_num) {
        try {
            $header = imap_headerinfo($this->connection, $email_num);
            $structure = imap_fetchstructure($this->connection, $email_num);
            $body = $this->get_email_body($email_num, $structure);
            $attachments = $this->get_email_attachments($email_num);
            
            // Extraer email y nombre correctamente
            $from_email = isset($header->from[0]) ? $header->from[0]->mailbox . '@' . $header->from[0]->host : '';
            $from_name = isset($header->from[0]->personal) ? $this->decode_header($header->from[0]->personal) : $from_email;
            $subject = isset($header->subject) ? $this->decode_header($header->subject) : '';
            
            return array(
                'from' => $from_email, // Mantener para compatibilidad
                'from_email' => $from_email, // Nueva clave requerida
                'from_name' => $from_name, // Nueva clave requerida
                'subject' => $subject,
                'body' => $body,
                'date' => isset($header->date) ? $header->date : '',
                'attachments' => $attachments,
                'message_id' => isset($header->message_id) ? $header->message_id : '',
                'in_reply_to' => isset($header->in_reply_to) ? $header->in_reply_to : ''
            );
        } catch (Exception $e) {
            error_log('QvaClick Debug: Error getting email data: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtener attachments del email
     */
    private function get_email_attachments($email_num) {
        $attachments = array();
        
        try {
            $structure = imap_fetchstructure($this->connection, $email_num);
            
            if (isset($structure->parts)) {
                foreach ($structure->parts as $part_num => $part) {
                    if (isset($part->disposition) && strtolower($part->disposition) === 'attachment') {
                        $filename = '';
                        
                        if (isset($part->dparameters)) {
                            foreach ($part->dparameters as $param) {
                                if (strtolower($param->attribute) === 'filename') {
                                    $filename = $param->value;
                                    break;
                                }
                            }
                        }
                        
                        $attachments[] = array(
                            'filename' => $filename,
                            'size' => isset($part->bytes) ? $part->bytes : 0,
                            'type' => isset($part->subtype) ? $part->subtype : 'unknown'
                        );
                    }
                }
            }
        } catch (Exception $e) {
            error_log('QvaClick Debug: Error getting attachments: ' . $e->getMessage());
        }
        
        return $attachments;
    }
    
    /**
     * Poner email en cuarentena
     */
    private function quarantine_email($email_data, $security_result) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qvc_email_quarantine';
        
        $wpdb->insert(
            $table,
            array(
                'sender_email' => $email_data['from'],
                'subject' => $email_data['subject'],
                'body' => $email_data['body'],
                'security_score' => $security_result['security_score'],
                'threats_detected' => json_encode($security_result['threats_detected']),
                'quarantine_reason' => implode(', ', $security_result['threats_detected']),
                'status' => 'quarantined',
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
        );
        
        // Log de cuarentena
        error_log("QvaClick Security: Email quarantined from {$email_data['from']} - Reason: " . implode(', ', $security_result['threats_detected']));
    }
    
    /**
     * Procesar email clasificado
     */
    private function process_classified_email($email_data, $classification, $security_result) {
        try {
            // Debug: Verificar qué datos tenemos
            error_log("QvaClick Debug: Email data keys: " . implode(', ', array_keys($email_data)));
            error_log("QvaClick Debug: from_email = " . (isset($email_data['from_email']) ? $email_data['from_email'] : 'NOT SET'));
            error_log("QvaClick Debug: from_name = " . (isset($email_data['from_name']) ? $email_data['from_name'] : 'NOT SET'));
            
            // Validar que tenemos los datos necesarios
            if (!isset($email_data['from_email']) || empty($email_data['from_email'])) {
                error_log("QvaClick Debug: ❌ from_email is missing or empty");
                return false;
            }
            
            // PASO 1: SIEMPRE verificar si es respuesta a ticket existente
            // Esto debe ir ANTES de la clasificación por categoría
            $existing_ticket = $this->find_existing_ticket_advanced(
                $email_data['from_email'], 
                $email_data['subject'], 
                $email_data['body']
            );
            
            if ($existing_ticket) {
                error_log("QvaClick Debug: Email es respuesta a ticket existente: {$existing_ticket['ticket_id']}");
                return $this->add_reply_to_ticket(
                    $existing_ticket['ticket_id'], 
                    $email_data['from_email'], 
                    $email_data['from_name'], 
                    $email_data['body']
                );
            }
            
            // PASO 2: Si no es respuesta, procesar según clasificación
            switch ($classification['category']) {
                case 'support_tickets':
                    return $this->create_support_ticket($email_data, $classification);
                    
                case 'sales_inquiries':
                    return $this->create_sales_lead($email_data, $classification);
                    
                case 'general_inquiries':
                case 'administrative':
                case 'newsletters':
                    return $this->store_in_general_inbox($email_data, $classification);
                    
                case 'spam':
                    return $this->handle_spam_email($email_data, $classification);
                    
                default:
                    // Para categorías desconocidas, almacenar en inbox general
                    return $this->store_in_general_inbox($email_data, $classification);
            }
        } catch (Exception $e) {
            error_log('QvaClick Debug: Error processing classified email: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Crear ticket de soporte
     */
    private function create_support_ticket($email_data, $classification) {
        global $wpdb;
        
        // Verificar si ya existe un ticket para este email
        $existing_ticket = $this->find_existing_ticket($email_data);
        
        if ($existing_ticket) {
            // Agregar mensaje al ticket existente
            return $this->add_ticket_message($existing_ticket['id'], $email_data);
        }
        
        // Crear nuevo ticket
        $ticket_data = array(
            'user_email' => $email_data['from'],
            'subject' => $email_data['subject'],
            'message' => $email_data['body'],
            'status' => 'open',
            'priority' => $classification['priority'],
            'source' => 'email_imap',
            'created_at' => current_time('mysql')
        );
        
        if ($classification['auto_assign'] && $classification['assigned_to']) {
            $ticket_data['assigned_to'] = $classification['assigned_to'];
        }
        
        $table = $wpdb->prefix . 'qvc_support_tickets';
        $wpdb->insert($table, $ticket_data, array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d'));
        
        $ticket_id = $wpdb->insert_id;
        
        // Ejecutar acciones sugeridas
        $this->execute_suggested_actions($classification['suggested_actions'], $ticket_id, $email_data);
        
        return $ticket_id;
    }
    
    /**
     * Crear lead de ventas
     */
    private function create_sales_lead($email_data, $classification) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qvc_sales_leads';
        
        $lead_data = array(
            'email' => $email_data['from'],
            'subject' => $email_data['subject'],
            'message' => $email_data['body'],
            'status' => 'new',
            'priority' => $classification['priority'],
            'source' => 'email_imap',
            'created_at' => current_time('mysql')
        );
        
        if ($classification['auto_assign'] && $classification['assigned_to']) {
            $lead_data['assigned_to'] = $classification['assigned_to'];
        }
        
        $wpdb->insert($table, $lead_data, array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d'));
        
        $lead_id = $wpdb->insert_id;
        
        // Ejecutar acciones sugeridas
        $this->execute_suggested_actions($classification['suggested_actions'], $lead_id, $email_data);
        
        return $lead_id;
    }
    
    /**
     * Almacenar en bandeja de entrada general
     */
    private function store_in_general_inbox($email_data, $classification) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qvc_general_inbox';
        
        $inbox_data = array(
            'sender_email' => $email_data['from'],
            'subject' => $email_data['subject'],
            'body' => $email_data['body'],
            'category' => $classification['category'],
            'priority' => $classification['priority'],
            'confidence' => $classification['confidence'],
            'tags' => json_encode($classification['tags']),
            'status' => 'unread',
            'requires_action' => $classification['requires_human'] ? 1 : 0,
            'created_at' => current_time('mysql')
        );
        
        if ($classification['auto_assign'] && $classification['assigned_to']) {
            $inbox_data['assigned_to'] = $classification['assigned_to'];
            $inbox_data['status'] = 'assigned';
        }
        
        $wpdb->insert($table, $inbox_data, array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%d'));
        
        $inbox_id = $wpdb->insert_id;
        
        // Ejecutar acciones sugeridas
        $this->execute_suggested_actions($classification['suggested_actions'], $inbox_id, $email_data);
        
        // Notificar a administradores sobre nuevo email en bandeja general (cobertura legacy)
        if (class_exists('QvaClick_Notification_System')) {
            QvaClick_Notification_System::notify_new_email($inbox_id, $email_data['from'], $email_data['subject']);
        }
        
        return $inbox_id;
    }
    
    /**
     * Manejar email de spam
     */
    private function handle_spam_email($email_data, $classification) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qvc_spam_log';
        
        $wpdb->insert(
            $table,
            array(
                'sender_email' => $email_data['from'],
                'subject' => $email_data['subject'],
                'detected_at' => current_time('mysql'),
                'confidence' => $classification['confidence']
            ),
            array('%s', '%s', '%s', '%d')
        );
        
        // Actualizar filtros anti-spam automáticamente
        $this->update_spam_filters($email_data);
        
        return true;
    }
    
    /**
     * Ejecutar acciones sugeridas por el clasificador
     */
    private function execute_suggested_actions($actions, $item_id, $email_data) {
        foreach ($actions as $action) {
            switch ($action) {
                case 'send_auto_acknowledgment':
                    $this->send_auto_acknowledgment($email_data, $item_id);
                    break;
                    
                case 'send_sales_template':
                    $this->send_sales_template($email_data);
                    break;
                    
                case 'send_general_template':
                    $this->send_general_template($email_data);
                    break;
                    
                case 'notify_manager':
                    $this->notify_manager($email_data, $item_id);
                    break;
                    
                case 'notify_sales_team':
                    $this->notify_sales_team($email_data, $item_id);
                    break;
                    
                case 'update_spam_filters':
                    $this->update_spam_filters($email_data);
                    break;
            }
        }
    }
    
    /**
     * Enviar confirmación automática
     */
    private function send_auto_acknowledgment($email_data, $ticket_id) {
        $template = get_option('qvc_auto_acknowledgment_template', 
            'Gracias por contactarnos. Su consulta ha sido recibida y será atendida a la brevedad.');
        
        $this->send_template_email($email_data['from'], 'Consulta recibida', $template);
    }
    
    /**
     * Crear tablas necesarias para el sistema mejorado
     */
    public static function create_enhanced_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Tabla de cuarentena
        $quarantine_table = $wpdb->prefix . 'qvc_email_quarantine';
        $sql1 = "CREATE TABLE IF NOT EXISTS $quarantine_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            sender_email varchar(255) NOT NULL,
            subject text,
            body longtext,
            security_score tinyint(3) NOT NULL,
            threats_detected text,
            quarantine_reason text,
            status varchar(20) NOT NULL DEFAULT 'quarantined',
            reviewed_by bigint(20) NULL,
            reviewed_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY sender_email (sender_email),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Tabla de bandeja general
        $general_inbox_table = $wpdb->prefix . 'qvc_general_inbox';
        $sql2 = "CREATE TABLE IF NOT EXISTS $general_inbox_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            sender_email varchar(255) NOT NULL,
            subject text,
            body longtext,
            category varchar(50) NOT NULL,
            priority varchar(20) NOT NULL DEFAULT 'medium',
            confidence tinyint(3) NOT NULL,
            tags text,
            status varchar(20) NOT NULL DEFAULT 'unread',
            assigned_to bigint(20) NULL,
            requires_action tinyint(1) NOT NULL DEFAULT 0,
            read_at datetime NULL,
            processed_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY sender_email (sender_email),
            KEY category (category),
            KEY status (status),
            KEY priority (priority),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Tabla de leads de ventas
        $sales_leads_table = $wpdb->prefix . 'qvc_sales_leads';
        $sql3 = "CREATE TABLE IF NOT EXISTS $sales_leads_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            email varchar(255) NOT NULL,
            subject text,
            message longtext,
            status varchar(20) NOT NULL DEFAULT 'new',
            priority varchar(20) NOT NULL DEFAULT 'medium',
            assigned_to bigint(20) NULL,
            source varchar(50) NOT NULL DEFAULT 'email',
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY email (email),
            KEY status (status),
            KEY assigned_to (assigned_to),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Tabla de log de spam
        $spam_log_table = $wpdb->prefix . 'qvc_spam_log';
        $sql4 = "CREATE TABLE IF NOT EXISTS $spam_log_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            sender_email varchar(255) NOT NULL,
            subject text,
            detected_at datetime NOT NULL,
            confidence tinyint(3) NOT NULL,
            PRIMARY KEY (id),
            KEY sender_email (sender_email),
            KEY detected_at (detected_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
        dbDelta($sql4);
        
        // Crear tablas de seguridad y clasificación
        QvaClick_Email_Security_Scanner::create_security_table();
        QvaClick_Email_Classifier::create_classification_table();
    }
    
    /**
     * Get IMAP statistics
     */
    public function get_stats() {
        if (!$this->connect()) {
            return false;
        }
        
        $mailbox = '{' . $this->config['imap_host'] . ':' . $this->config['imap_port'] . '}' . $this->config['imap_folder'];
        $status = imap_status($this->connection, $mailbox, SA_ALL);
        
        $this->disconnect();
        
        return array(
            'total_messages' => $status->messages,
            'unread_messages' => $status->unseen,
            'recent_messages' => $status->recent
        );
    }
    
    /**
     * Find existing ticket based on email data
     */
    private function find_existing_ticket($email_data) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'qvaclick_tickets';
        
        // Buscar tickets del mismo remitente con asunto similar en los últimos 7 días
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name 
             WHERE user_email = %s 
             AND (subject LIKE %s OR %s LIKE CONCAT('%%', subject, '%%'))
             AND status != 'closed' 
             AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY created_at DESC 
             LIMIT 1",
            $email_data['from'],
            '%' . $wpdb->esc_like($email_data['subject']) . '%',
            $email_data['subject']
        ), ARRAY_A);
        
        return $existing;
    }
    
    /**
     * Add message to existing ticket
     */
    private function add_ticket_message($ticket_id, $email_data) {
        global $wpdb;
        
        $messages_table = $wpdb->prefix . 'qvaclick_ticket_messages';
        
        $result = $wpdb->insert(
            $messages_table,
            array(
                'ticket_id' => $ticket_id,
                'sender_email' => $email_data['from'],
                'message' => $email_data['body'],
                'is_from_client' => 1,
                'created_at' => current_time('mysql')
            )
        );
        
        if ($result) {
            // Actualizar el estado del ticket a 'open' si estaba cerrado
            $tickets_table = $wpdb->prefix . 'qvaclick_tickets';
            $wpdb->update(
                $tickets_table,
                array('status' => 'open', 'updated_at' => current_time('mysql')),
                array('id' => $ticket_id)
            );
            
            return $ticket_id;
        }
        
        return false;
    }
    
    /**
     * Update spam filters based on detected spam
     */
    private function update_spam_filters($email_data) {
        // Placeholder para actualización de filtros de spam
        error_log('QvaClick Debug: Updating spam filters for: ' . $email_data['from']);
    }
    
    /**
     * Send sales template email
     */
    private function send_sales_template($email_data) {
        $template = "Gracias por su interés en nuestros servicios. Un representante de ventas se pondrá en contacto con usted pronto.";
        return $this->send_template_email($email_data['from'], 'Consulta comercial recibida', $template);
    }
    
    /**
     * Send general template email
     */
    private function send_general_template($email_data) {
        $template = "Hemos recibido su mensaje y le responderemos a la brevedad posible.";
        return $this->send_template_email($email_data['from'], 'Mensaje recibido', $template);
    }
    
    /**
     * Send template email
     */
    private function send_template_email($to_email, $subject, $template) {
        $admin_email_manager = QvaClick_Admin_Email_Manager::get_instance();
        $support_email = $admin_email_manager->get_support_from_email();
        $support_name = $admin_email_manager->get_support_from_name();
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $support_name . ' <' . $support_email . '>',
            'Reply-To: ' . $support_email
        );
        
        return wp_mail($to_email, $subject, $template, $headers);
    }
    
    /**
     * Notify manager about important emails
     */
    private function notify_manager($email_data, $item_id) {
        $admin_email = get_option('admin_email');
        $subject = 'Nuevo email importante recibido - ID: ' . $item_id;
        $message = sprintf(
            "Se ha recibido un email importante:\n\nDe: %s\nAsunto: %s\nID: %s\n\nRevise el panel de administración para más detalles.",
            $email_data['from'],
            $email_data['subject'],
            $item_id
        );
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Notify sales team about new leads
     */
    private function notify_sales_team($email_data, $item_id) {
        $sales_email = get_option('qvaclick_sales_notification_email', get_option('admin_email'));
        $subject = 'Nuevo lead de ventas - ID: ' . $item_id;
        $message = sprintf(
            "Se ha recibido un nuevo lead de ventas:\n\nDe: %s\nAsunto: %s\nID: %s\n\nRevise el panel de ventas para seguimiento.",
            $email_data['from'],
            $email_data['subject'],
            $item_id
        );
        
        wp_mail($sales_email, $subject, $message);
    }
    
    /**
     * Advanced ticket detection - checks multiple patterns and email thread history
     */
    private function find_existing_ticket_advanced($from_email, $subject, $body) {
        global $wpdb;
        
        error_log("QvaClick Debug: find_existing_ticket_advanced - Email: $from_email, Subject: $subject");
        
        // Pattern 1: Standard ticket format (Ticket #XXX, TKT-XXX, REF:XXX)
        $patterns = [
            '/Ticket #([A-Z0-9\-]+)/i',
            '/TKT[:\-\s]?([A-Z0-9\-]+)/i',
            '/REF[:\-\s]?([A-Z0-9\-]+)/i',
            '/TICKET[:\-\s]?([A-Z0-9\-]+)/i',
            '/#([A-Z0-9\-]+)/',
            '/\[([A-Z0-9\-]+)\]/'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $subject, $matches)) {
                $ticket_id = trim($matches[1]);
                error_log("QvaClick Debug: Pattern '$pattern' found ticket ID: $ticket_id in subject");
                
                // Check in qvc_support_tickets table with multiple methods
                // Method 1: Direct ticket_id match
                $ticket = $wpdb->get_row($wpdb->prepare(
                    "SELECT ticket_id, id FROM {$wpdb->prefix}qvc_support_tickets WHERE ticket_id = %s",
                    $ticket_id
                ), ARRAY_A);
                
                if ($ticket) {
                    error_log("QvaClick Debug: ✅ Found existing ticket by ticket_id: $ticket_id");
                    return $ticket;
                }
                
                // Method 2: If it's numeric, try as ID
                if (is_numeric($ticket_id)) {
                    $ticket = $wpdb->get_row($wpdb->prepare(
                        "SELECT ticket_id, id FROM {$wpdb->prefix}qvc_support_tickets WHERE id = %d",
                        intval($ticket_id)
                    ), ARRAY_A);
                    
                    if ($ticket) {
                        error_log("QvaClick Debug: ✅ Found existing ticket by ID: $ticket_id");
                        return $ticket;
                    }
                }
                
                // Method 3: Check if the ticket_id column contains the TKT format
                $ticket = $wpdb->get_row($wpdb->prepare(
                    "SELECT ticket_id, id FROM {$wpdb->prefix}qvc_support_tickets WHERE ticket_id LIKE %s",
                    '%' . $ticket_id . '%'
                ), ARRAY_A);
                
                if ($ticket) {
                    error_log("QvaClick Debug: ✅ Found existing ticket by partial match: $ticket_id");
                    return $ticket;
                }
                
                error_log("QvaClick Debug: ❌ Ticket $ticket_id not found in database");
            }
        }
        
        // Pattern 2: Look for ticket ID in body (common in email threads)
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body, $matches)) {
                $ticket_id = trim($matches[1]);
                error_log("QvaClick Debug: Pattern '$pattern' found ticket ID: $ticket_id in body");
                
                // Same comprehensive search as above
                $ticket = $wpdb->get_row($wpdb->prepare(
                    "SELECT ticket_id, id FROM {$wpdb->prefix}qvc_support_tickets WHERE ticket_id = %s",
                    $ticket_id
                ), ARRAY_A);
                
                if ($ticket) {
                    error_log("QvaClick Debug: ✅ Found existing ticket in body by ticket_id: $ticket_id");
                    return $ticket;
                }
                
                if (is_numeric($ticket_id)) {
                    $ticket = $wpdb->get_row($wpdb->prepare(
                        "SELECT ticket_id, id FROM {$wpdb->prefix}qvc_support_tickets WHERE id = %d",
                        intval($ticket_id)
                    ), ARRAY_A);
                    
                    if ($ticket) {
                        error_log("QvaClick Debug: ✅ Found existing ticket in body by ID: $ticket_id");
                        return $ticket;
                    }
                }
                
                $ticket = $wpdb->get_row($wpdb->prepare(
                    "SELECT ticket_id, id FROM {$wpdb->prefix}qvc_support_tickets WHERE ticket_id LIKE %s",
                    '%' . $ticket_id . '%'
                ), ARRAY_A);
                
                if ($ticket) {
                    error_log("QvaClick Debug: ✅ Found existing ticket in body by partial match: $ticket_id");
                    return $ticket;
                }
                
                error_log("QvaClick Debug: ❌ Ticket $ticket_id (from body) not found in database");
            }
        }
        
        // Pattern 3: Check for similar subject lines from same email (within last 30 days)
        error_log("QvaClick Debug: No ticket ID found in patterns, checking for similar subjects...");
        $clean_subject = $this->normalize_subject($subject);
        $similar_tickets = $wpdb->get_results($wpdb->prepare(
            "SELECT ticket_id, subject FROM {$wpdb->prefix}qvc_support_tickets 
             WHERE customer_email = %s 
             AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
             ORDER BY created_at DESC LIMIT 5",
            $from_email
        ), ARRAY_A);
        
        foreach ($similar_tickets as $ticket) {
            $ticket_subject = $this->normalize_subject($ticket['subject']);
            if (strlen($clean_subject) > 5 && strlen($ticket_subject) > 5) {
                $similarity = similar_text($clean_subject, $ticket_subject, $percent);
                if ($percent > 70) { // 70% similarity threshold
                    return $ticket;
                }
            }
        }
        
        // Pattern 4: Check recent messages for thread continuity
        $recent_messages = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT tm.ticket_id 
             FROM {$wpdb->prefix}qvc_ticket_messages tm
             JOIN {$wpdb->prefix}qvc_support_tickets st ON tm.ticket_id = st.ticket_id
             WHERE st.customer_email = %s 
             AND tm.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY tm.created_at DESC LIMIT 3",
            $from_email
        ), ARRAY_A);
        
        if (!empty($recent_messages)) {
            return ['ticket_id' => $recent_messages[0]['ticket_id']];
        }
        
        return null;
    }
    
    /**
     * Normalize subject for comparison
     */
    private function normalize_subject($subject) {
        // Remove common prefixes and references
        $subject = preg_replace('/^(Re|Fwd?|Res?|Resp?|回复|答复):\s*/i', '', $subject);
        $subject = preg_replace('/\[.*?\]/', '', $subject);
        $subject = preg_replace('/Ticket #[A-Z0-9\-]+/i', '', $subject);
        $subject = preg_replace('/TKT[:\-\s]?[A-Z0-9\-]+/i', '', $subject);
        $subject = trim($subject);
        return strtolower($subject);
    }
    
    /**
     * Procesar emails pendientes en bandeja general
     * que no han sido asignados a tickets
     */
    private function process_pending_inbox_emails() {
        global $wpdb;
        
        $processed = 0;
        $errors = 0;
        
        error_log('QvaClick Debug: Processing pending emails from general inbox...');
        
        // Buscar emails en bandeja general que no han sido procesados como tickets
        // y que podrían ser respuestas a tickets existentes
        $pending_emails = $wpdb->get_results("
            SELECT id, sender_email, subject, body, created_at 
            FROM {$wpdb->prefix}qvc_general_inbox 
            WHERE status IN ('pending', 'unprocessed') 
            AND (category IS NULL OR category NOT IN ('ticket_reply', 'processed_ticket'))
            ORDER BY created_at ASC 
            LIMIT 50
        ", ARRAY_A);
        
        if (empty($pending_emails)) {
            error_log('QvaClick Debug: No pending emails found in general inbox');
            return array('processed' => 0, 'errors' => 0);
        }
        
        error_log('QvaClick Debug: Found ' . count($pending_emails) . ' pending emails in general inbox');
        
        foreach ($pending_emails as $email_row) {
            try {
                error_log("QvaClick Debug: Processing pending email ID {$email_row['id']} from {$email_row['sender_email']}");
                
                // Verificar si es respuesta a ticket existente
                $existing_ticket = $this->find_existing_ticket_advanced(
                    $email_row['sender_email'], 
                    $email_row['subject'], 
                    $email_row['body']
                );
                
                if ($existing_ticket) {
                    error_log("QvaClick Debug: Pending email is reply to ticket: {$existing_ticket['ticket_id']}");
                    
                    // Limpiar contenido
                    $clean_body = $this->clean_email_content($email_row['body']);
                    
                    // Agregar como respuesta al ticket
                    $reply_result = $this->add_reply_to_ticket(
                        $existing_ticket['ticket_id'],
                        $email_row['sender_email'],
                        $email_row['sender_email'], // Usar email como nombre si no tenemos nombre
                        $clean_body
                    );
                    
                    if ($reply_result) {
                        // Marcar como procesado en bandeja general
                        $wpdb->update(
                            $wpdb->prefix . 'qvc_general_inbox',
                            array(
                                'status' => 'processed_ticket',
                                'category' => 'ticket_reply',
                                'processed_at' => current_time('mysql')
                            ),
                            array('id' => $email_row['id'])
                        );
                        
                        $processed++;
                        error_log("QvaClick Debug: Successfully processed pending email as ticket reply");
                    } else {
                        $errors++;
                        error_log("QvaClick Debug: Failed to add pending email as ticket reply");
                    }
                } else {
                    // No es respuesta a ticket, verificar si debería crear nuevo ticket
                    if (strpos(strtolower($email_row['subject']), 'ticket') !== false || 
                        strpos(strtolower($email_row['subject']), 'soporte') !== false ||
                        strpos(strtolower($email_row['subject']), 'support') !== false) {
                        
                        error_log("QvaClick Debug: Pending email might be new support ticket");
                        
                        // Marcar para revisión manual
                        $wpdb->update(
                            $wpdb->prefix . 'qvc_general_inbox',
                            array(
                                'status' => 'review_required',
                                'category' => 'potential_ticket',
                                'requires_action' => 1
                            ),
                            array('id' => $email_row['id'])
                        );
                    } else {
                        // Email regular, mantener en bandeja general
                        $wpdb->update(
                            $wpdb->prefix . 'qvc_general_inbox',
                            array(
                                'status' => 'processed',
                                'processed_at' => current_time('mysql')
                            ),
                            array('id' => $email_row['id'])
                        );
                    }
                }
                
            } catch (Exception $e) {
                error_log('QvaClick Debug: Error processing pending email ID ' . $email_row['id'] . ': ' . $e->getMessage());
                $errors++;
            }
        }
        
        error_log("QvaClick Debug: Pending inbox processing completed - Processed: $processed, Errors: $errors");
        
        return array('processed' => $processed, 'errors' => $errors);
    }
}
