<?php
/**
 * Email Discovery Class
 * Detecta automáticamente todas las plantillas de email en Redux
 */

class QvaClick_Email_Discovery {
    
    /**
     * Descubre todas las plantillas de email en el sistema
     * ACTUALIZADO: Ahora usa Redux Sync Manager para total sincronización
     */
    public static function discover_email_templates() {
        // Usar el nuevo Redux Sync Manager que mantiene sincronización total
        if (class_exists('QvaClick_Redux_Sync_Manager')) {
            return QvaClick_Redux_Sync_Manager::get_all_emails_from_redux();
        }
        
        // Fallback al método anterior si el Sync Manager no está disponible
        return self::discover_email_templates_legacy();
    }
    
    /**
     * Método legacy de discovery (mantenido como fallback)
     */
    private static function discover_email_templates_legacy() {
        $redux_options = get_option('exertio_theme_options', array());
        $email_templates = array();
        
        foreach ($redux_options as $key => $value) {
            // Detectar patrones de email templates
            if (self::is_email_template_key($key, $value)) {
                $template_info = self::parse_template_key($key, $value);
                if ($template_info) {
                    $base_key = $template_info['base_key'];
                    
                    if (!isset($email_templates[$base_key])) {
                        $email_templates[$base_key] = array(
                            'name' => $template_info['name'],
                            'base_key' => $base_key,
                            'subject_key' => null,
                            'body_key' => null,
                            'switch_key' => null,
                            'subject' => '',
                            'body' => '',
                            'enabled' => true,
                            'last_modified' => '',
                            'placeholders' => array()
                        );
                    }
                    
                    // Asignar el valor según el tipo
                    switch ($template_info['type']) {
                        case 'subject':
                            $email_templates[$base_key]['subject_key'] = $key;
                            $email_templates[$base_key]['subject'] = $value;
                            break;
                        case 'body':
                            $email_templates[$base_key]['body_key'] = $key;
                            $email_templates[$base_key]['body'] = $value;
                            break;
                        case 'switch':
                            $email_templates[$base_key]['switch_key'] = $key;
                            $email_templates[$base_key]['enabled'] = (bool) $value;
                            break;
                    }
                }
            }
        }
        
        // Limpiar templates incompletos (sin body)
        $email_templates = array_filter($email_templates, function($template) {
            return !empty($template['body_key']) && !empty($template['body']);
        });
        
        // Buscar subjects faltantes de manera inteligente
        foreach ($email_templates as $base_key => &$template) {
            if (empty($template['subject_key']) || empty($template['subject'])) {
                $found_subject = self::find_missing_subject($base_key, $template, $redux_options);
                if ($found_subject) {
                    $template['subject_key'] = $found_subject['key'];
                    $template['subject'] = $found_subject['value'];
                }
            }
        }
        
        // Detectar placeholders para cada template
        foreach ($email_templates as $base_key => &$template) {
            $template['placeholders'] = self::extract_placeholders($template['body']);
            $template['last_modified'] = self::get_template_last_modified($base_key);
        }
        
        return $email_templates;
    }
    
    /**
     * Busca inteligentemente el subject de un template
     */
    private static function find_missing_subject($base_key, $template, $redux_options) {
        $body_key = $template['body_key'];
        
        // Patrones para generar claves de subject probables
        $possible_subject_keys = array();
        
        // Método 1: Reemplazar sufijo del body
        if (preg_match('/(.+)(_body|_message|_template)$/i', $body_key, $matches)) {
            $base = $matches[1];
            $possible_subject_keys[] = $base . '_sub';
            $possible_subject_keys[] = $base . '_subj';
            $possible_subject_keys[] = $base . '_subject';
        }
        
        // Método 2: Agregar sufijos al base_key
        $possible_subject_keys[] = $base_key . '_sub';
        $possible_subject_keys[] = $base_key . '_subj';
        $possible_subject_keys[] = $base_key . '_subject';
        
        // Método 3: Buscar patrones específicos de Exertio
        if (strpos($base_key, 'fl_') === 0) {
            $possible_subject_keys[] = $base_key . '_email_sub';
            $possible_subject_keys[] = $base_key . '_email_subject';
        }
        
        // Método 4: Buscar por similitud de nombre
        foreach ($redux_options as $key => $value) {
            if (preg_match('/(_sub|_subj|_subject)$/i', $key) && 
                is_string($value) && 
                strlen($value) > 3 && 
                strlen($value) < 200) {
                
                // Verificar si el key es similar al base_key
                $clean_key = preg_replace('/(_sub|_subj|_subject)$/i', '', $key);
                if (levenshtein($clean_key, $base_key) <= 3) {
                    $possible_subject_keys[] = $key;
                }
            }
        }
        
        // Buscar la primera clave que exista
        foreach ($possible_subject_keys as $subject_key) {
            if (isset($redux_options[$subject_key]) && 
                is_string($redux_options[$subject_key]) &&
                !empty(trim($redux_options[$subject_key]))) {
                return array(
                    'key' => $subject_key,
                    'value' => $redux_options[$subject_key]
                );
            }
        }
        
        return null;
    }
    
    /**
     * Verifica si una clave parece ser de un template de email
     */
    private static function is_email_template_key($key, $value) {
        // Patrones de claves de email ampliados
        $patterns = array(
            '_sub$',           // subject
            '_subj$',          // subject 
            '_subject$',       // subject completo
            '_body$',          // body
            '_message$',       // body
            '_templ$',         // template body
            '_template$',      // template body
            'email.*switch$',  // email switch
            'email.*send$',    // email send toggle
            '_switch$',        // switch general
            '_send$'           // send toggle
        );
        
        foreach ($patterns as $pattern) {
            if (preg_match('/' . $pattern . '/i', $key)) {
                return true;
            }
        }
        
        // Patrones específicos de Exertio
        if (preg_match('/^fl_.*_(sub|subj|subject|body|message|switch|send)$/i', $key)) {
            return true;
        }
        
        // También verificar si contiene HTML típico de emails
        if (is_string($value) && strlen($value) > 50 && 
            (strpos($value, '<table') !== false || 
             strpos($value, '<html') !== false ||
             strpos($value, '<body') !== false ||
             strpos($value, '%') !== false)) { // placeholders
            return true;
        }
        
        return false;
    }
    
    /**
     * Parsea una clave de template y extrae información
     */
    private static function parse_template_key($key, $value) {
        // Determinar tipo con patrones ampliados
        $type = 'unknown';
        if (preg_match('/(_sub|_subj|_subject)$/i', $key)) {
            $type = 'subject';
        } elseif (preg_match('/(_body|_message|_templ|_template)$/i', $key)) {
            $type = 'body';
        } elseif (preg_match('/(switch|send)$/i', $key)) {
            $type = 'switch';
        }
        
        // Extraer base key removiendo sufijos ampliados
        $base_key = preg_replace('/(_sub|_subj|_subject|_body|_message|_templ|_template|_switch|_send)$/i', '', $key);
        
        // Generar nombre legible
        $name = self::generate_readable_name($base_key);
        
        return array(
            'base_key' => $base_key,
            'type' => $type,
            'name' => $name
        );
    }
    
    /**
     * Genera un nombre legible desde una clave
     */
    private static function generate_readable_name($base_key) {
        // Mapeo de claves conocidas
        $known_names = array(
            'fl_new_user_admin' => 'Nuevo Usuario (Admin)',
            'fl_new_user_welcome' => 'Bienvenida Usuario',
            'fl_user_email_verification' => 'Verificación Email',
            'fl_user_email_account_activate' => 'Activación Cuenta',
            'fl_user_email_account_deactivate' => 'Desactivación Cuenta',
            'fl_email_sendto_Admin_account_activation' => 'Activación Admin',
            'fl_user_reset_pwd' => 'Reset Password',
            'fl_email_onproject_created' => 'Proyecto Creado',
            'fl_email_onproject_update' => 'Proyecto Actualizado',
            'fl_onservice_created' => 'Servicio Creado',
            'fl_email_offer_received' => 'Oferta Recibida',
            'custom_offer' => 'Oferta Personalizada'
        );
        
        if (isset($known_names[$base_key])) {
            return $known_names[$base_key];
        }
        
        // Generar nombre automático
        $name = str_replace(array('fl_', '_email', '_'), array('', '', ' '), $base_key);
        return ucwords($name);
    }
    
    /**
     * Extrae placeholders de un template
     */
    private static function extract_placeholders($content) {
        $placeholders = array();
        
        // Buscar patrones %placeholder%
        if (preg_match_all('/%([^%]+)%/', $content, $matches)) {
            $placeholders = array_merge($placeholders, $matches[1]);
        }
        
        // Buscar patrones {placeholder}
        if (preg_match_all('/\{([^}]+)\}/', $content, $matches)) {
            $placeholders = array_merge($placeholders, $matches[1]);
        }
        
        return array_unique($placeholders);
    }
    
    /**
     * Obtiene la fecha de última modificación de un template
     */
    private static function get_template_last_modified($base_key) {
        // Por ahora retornamos la fecha actual
        // En el futuro podríamos implementar un log de cambios
        return current_time('mysql');
    }
    
    /**
     * Obtiene estadísticas de los templates
     */
    public static function get_templates_stats() {
        $templates = self::discover_email_templates();
        
        return array(
            'total' => count($templates),
            'enabled' => count(array_filter($templates, function($t) { return $t['enabled']; })),
            'disabled' => count(array_filter($templates, function($t) { return !$t['enabled']; })),
            'with_custom_content' => count(array_filter($templates, function($t) { 
                return strlen($t['body']) > 100; 
            }))
        );
    }
    
    /**
     * Guarda una opción individual de template
     */
    public static function save_template_option($option_key, $value) {
        $options = get_option('exertio_theme_options', array());
        $options[$option_key] = $value;
        
        // Actualizar en Redux
        update_option('exertio_theme_options', $options);
        
        return true;
    }
    
    /**
     * Obtiene una opción individual de template
     * FIXED: Leer de la misma fuente donde escribimos para consistencia
     */
    public static function get_template_option($option_key, $default = '') {
        $options = get_option('exertio_theme_options', array());
        return isset($options[$option_key]) ? $options[$option_key] : $default;
    }
}
