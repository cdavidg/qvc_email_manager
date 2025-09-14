<?php
/**
 * Redux Sync Manager - Sincronización completa entre QvaClick y Exertio Framework
 * 
 * Esta clase asegura que QvaClick escriba directamente en exertio_theme_options
 * manteniendo total sincronización con Exertio Framework
 */

class QvaClick_Redux_Sync_Manager {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'init_sync_hooks'));
    }
    
    /**
     * Inicializar hooks de sincronización
     */
    public function init_sync_hooks() {
        // Hook para interceptar actualizaciones de QvaClick y sincronizar con Redux
        add_action('update_option_qvc_email_base_template', array($this, 'sync_base_template'), 10, 3);
        
        // Hook para interceptar cuando se guarden subjects desde QvaClick
        add_filter('qvc_before_save_email_subject', array($this, 'save_subject_to_redux'), 10, 3);
        add_filter('qvc_before_save_email_body', array($this, 'save_body_to_redux'), 10, 3);
        add_filter('qvc_before_save_email_enabled', array($this, 'save_enabled_to_redux'), 10, 3);
    }
    
    /**
     * Obtener subject directamente de Redux
     */
    public static function get_email_subject($base_key) {
        $redux_options = get_option('exertio_theme_options', array());
        
        // Intentar diferentes patrones de claves para subjects
        $possible_keys = array(
            $base_key . '_subject',
            $base_key . '_subj', 
            $base_key . '_sub',
            $base_key . '_email_subject'
        );
        
        foreach ($possible_keys as $key) {
            if (isset($redux_options[$key]) && !empty($redux_options[$key])) {
                return array(
                    'key' => $key,
                    'value' => $redux_options[$key]
                );
            }
        }
        
        return null;
    }
    
    /**
     * Guardar subject directamente en Redux
     */
    public static function save_email_subject($base_key, $subject) {
        $redux_options = get_option('exertio_theme_options', array());
        
        // Determinar la clave correcta para el subject
        $subject_key = self::get_subject_key_for_base($base_key, $redux_options);
        
        // Actualizar en Redux
        $redux_options[$subject_key] = $subject;
        
        // Guardar inmediatamente
        $result = update_option('exertio_theme_options', $redux_options);
        
        // Log para debug
        error_log("QvaClick Redux Sync: Saved subject for $base_key -> $subject_key = $subject");
        
        return $result;
    }
    
    /**
     * Obtener body directamente de Redux
     */
    public static function get_email_body($base_key) {
        $redux_options = get_option('exertio_theme_options', array());
        
        // Intentar diferentes patrones para body
        $possible_keys = array(
            $base_key . '_body',
            $base_key . '_message',
            $base_key . '_template',
            $base_key . '_email_body',
            $base_key . '_message_body',
            $base_key . '_email_template',
            $base_key . '_content'
        );
        
        foreach ($possible_keys as $key) {
            if (isset($redux_options[$key]) && !empty($redux_options[$key])) {
                return array(
                    'key' => $key,
                    'value' => $redux_options[$key]
                );
            }
        }
        
        return null;
    }
    
    /**
     * Guardar body directamente en Redux
     */
    public static function save_email_body($base_key, $body) {
        $redux_options = get_option('exertio_theme_options', array());
        
        // Determinar la clave correcta para el body
        $body_key = self::get_body_key_for_base($base_key, $redux_options);
        
        // Actualizar en Redux
        $redux_options[$body_key] = $body;
        
        // Guardar inmediatamente
        $result = update_option('exertio_theme_options', $redux_options);
        
        // Log para debug
        error_log("QvaClick Redux Sync: Saved body for $base_key -> $body_key");
        
        return $result;
    }
    
    /**
     * Obtener estado enabled/disabled directamente de Redux
     */
    public static function get_email_enabled($base_key) {
        $redux_options = get_option('exertio_theme_options', array());
        
        // Intentar diferentes patrones para switches
        $possible_keys = array(
            $base_key . '_switch',
            $base_key . '_enabled',
            $base_key . '_status',
            $base_key . '_on',
            // En Exertio muchos toggles son la clave base sin sufijo (p. ej. fl_email_onproject_created)
            $base_key
        );
        
        foreach ($possible_keys as $key) {
            if (isset($redux_options[$key])) {
                return array(
                    'key' => $key,
                    'value' => (bool) $redux_options[$key]
                );
            }
        }
        
        // Por defecto, asumir habilitado si no se encuentra switch
        return array(
            'key' => null,
            'value' => true
        );
    }
    
    /**
     * Guardar estado enabled directamente en Redux
     */
    public static function save_email_enabled($base_key, $enabled) {
        $redux_options = get_option('exertio_theme_options', array());
        
        // Determinar la clave correcta para el switch
        $switch_key = self::get_switch_key_for_base($base_key, $redux_options);
        
        // Actualizar en Redux
        $redux_options[$switch_key] = $enabled ? 1 : 0;
        
        // Guardar inmediatamente
        $result = update_option('exertio_theme_options', $redux_options);
        
        // Log para debug
        error_log("QvaClick Redux Sync: Saved enabled for $base_key -> $switch_key = " . ($enabled ? 'true' : 'false'));
        
        return $result;
    }
    
    /**
     * Determinar la clave correcta para subject de un base_key
     */
    private static function get_subject_key_for_base($base_key, $redux_options) {
        // Usar el mapeo exacto de Exertio Framework
        if (class_exists('QvaClick_Exertio_Key_Mapping')) {
            $exact_key = QvaClick_Exertio_Key_Mapping::get_subject_key($base_key);
            return $exact_key;
        }
        
        // Fallback: verificar si ya existe alguna clave
        $existing = self::get_email_subject($base_key);
        if ($existing) {
            return $existing['key'];
        }

        // Si no existe, usar el patrón más común de Exertio para subject: "_sub"
        return $base_key . '_sub';
    }
    
    /**
     * Determinar la clave correcta para body de un base_key
     */
    private static function get_body_key_for_base($base_key, $redux_options) {
        // Usar el mapeo exacto de Exertio Framework
        if (class_exists('QvaClick_Exertio_Key_Mapping')) {
            $exact_key = QvaClick_Exertio_Key_Mapping::get_body_key($base_key);
            return $exact_key;
        }
        
        // Fallback: verificar si ya existe alguna clave
        $existing = self::get_email_body($base_key);
        if ($existing) {
            return $existing['key'];
        }

        // Si no existe, usar el patrón más común de Exertio para body: "_email_body"
        return $base_key . '_email_body';
    }
    
    /**
     * Determinar la clave correcta para switch de un base_key
     */
    private static function get_switch_key_for_base($base_key, $redux_options) {
        // Primero verificar si ya existe alguna clave
        $existing = self::get_email_enabled($base_key);
        if ($existing['key']) {
            return $existing['key'];
        }
        
        // Si no existe, usar el patrón más común de Exertio
        return $base_key . '_switch';
    }
    
    /**
     * Obtener TODOS los emails desde Redux (reemplaza el discovery actual)
     */
    public static function get_all_emails_from_redux() {
        $redux_options = get_option('exertio_theme_options', array());
        $emails = array();
        
        // Agrupar por base_key
        $grouped = array();
        
    foreach ($redux_options as $key => $value) {
            
            // Detectar patterns de email (normalizando sufijos usados por Exertio)
            // Soportar: _subject|_subj|_sub|_email_subject
            //           _body|_message|_template|_email_body|_message_body|_email_template|_content
            //           _switch|_enabled|_status y también clave base como toggle (p. ej. fl_email_onproject_created)
            $base_key = null;
            $type = null;

            // 1) Subjects
            if (preg_match('/^(.+?)_(?:email_)?(subject|subj|sub)$/i', $key, $m)) {
                $base_key = $m[1];
                $type = 'subject';
            }
            // 2) Bodies/templates/contents
            elseif (preg_match('/^(.+?)_(?:email_|message_)?(body|message|template|content)$/i', $key, $m)) {
                $base_key = $m[1];
                // Normalizamos todos a 'body' para consumir en el render
                $type = in_array(strtolower($m[2]), array('body','message','template','content')) ? strtolower($m[2]) : 'body';
            }
            // 3) Switches
            elseif (preg_match('/^(.+?)_(switch|enabled|status)$/i', $key, $m)) {
                $base_key = $m[1];
                $type = strtolower($m[2]);
            }
            // 4) Flags base (Exertio): si la clave base está activa y existen hermanos _sub/_body, trátalo como 'enabled'
            elseif (array_key_exists($key, $redux_options) && is_scalar($redux_options[$key])) {
                // Solo considerar como toggle si existen claves hermanas conocidas
                if (isset($redux_options[$key . '_sub']) || isset($redux_options[$key . '_subject']) || isset($redux_options[$key . '_email_subject'])
                    || isset($redux_options[$key . '_body']) || isset($redux_options[$key . '_email_body']) || isset($redux_options[$key . '_message_body']) || isset($redux_options[$key . '_message']) || isset($redux_options[$key . '_template']) || isset($redux_options[$key . '_content'])) {
                    $base_key = $key;
                    $type = 'enabled';
                }
            }
            
            if ($base_key !== null && $type !== null) {
                
                if (!isset($grouped[$base_key])) {
                    $grouped[$base_key] = array();
                }
                
                $grouped[$base_key][$type] = array(
                    'key' => $key,
                    'value' => $value
                );
            }
        }
        
        // Construir lista de emails
        foreach ($grouped as $base_key => $data) {
            // Solo incluir si tiene al menos body
            if (!isset($data['body']) && !isset($data['message']) && !isset($data['template']) && !isset($data['content'])) {
                continue;
            }
            
            $subject_data = $data['subject'] ?? $data['subj'] ?? $data['sub'] ?? null;
            $body_data = $data['body'] ?? $data['message'] ?? $data['template'] ?? $data['content'] ?? null;
            $switch_data = $data['switch'] ?? $data['enabled'] ?? $data['status'] ?? null;
            
            $emails[$base_key] = array(
                'base_key' => $base_key,
                'name' => ucwords(str_replace('_', ' ', $base_key)),
                'subject_key' => $subject_data ? $subject_data['key'] : null,
                'subject' => $subject_data ? $subject_data['value'] : '',
                'body_key' => $body_data ? $body_data['key'] : null,
                'body' => $body_data ? $body_data['value'] : '',
                'switch_key' => $switch_data ? $switch_data['key'] : null,
                'enabled' => $switch_data ? (bool) $switch_data['value'] : true,
                'placeholders' => self::extract_placeholders($body_data ? $body_data['value'] : ''),
                'last_modified' => '',
                'source' => 'redux_direct'
            );
        }
        
        return $emails;
    }
    
    /**
     * Extraer placeholders de un texto
     */
    private static function extract_placeholders($text) {
        if (empty($text)) {
            return array();
        }
        
        $placeholders = array();
        
        // Buscar placeholders con formato %variable%
        if (preg_match_all('/%([a-zA-Z_][a-zA-Z0-9_]*)%/', $text, $matches)) {
            foreach ($matches[0] as $match) {
                if (!in_array($match, $placeholders)) {
                    $placeholders[] = $match;
                }
            }
        }
        
        // Buscar placeholders con formato {variable}
        if (preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $text, $matches)) {
            foreach ($matches[0] as $match) {
                if (!in_array($match, $placeholders)) {
                    $placeholders[] = $match;
                }
            }
        }
        
        return $placeholders;
    }
    
    /**
     * Hook callbacks para sincronización automática
     */
    public function save_subject_to_redux($subject, $base_key, $old_subject) {
        self::save_email_subject($base_key, $subject);
        return $subject;
    }
    
    public function save_body_to_redux($body, $base_key, $old_body) {
        self::save_email_body($base_key, $body);
        return $body;
    }
    
    public function save_enabled_to_redux($enabled, $base_key, $old_enabled) {
        self::save_email_enabled($base_key, $enabled);
        return $enabled;
    }
    
    /**
     * Sincronizar plantilla base (si es necesario)
     */
    public function sync_base_template($old_value, $value, $option) {
        // La plantilla base puede seguir en wp_options ya que es específica de QvaClick
        // Pero podríamos también guardarla en Redux si Exertio la necesita
    }
    
    /**
     * Migrar datos existentes de QvaClick a Redux (función de migración)
     */
    public static function migrate_qvaclick_to_redux() {
        // Esta función se puede ejecutar una vez para migrar cualquier dato existente
        $qvc_options = get_option('qvc_email_templates', array());
        
        if (!empty($qvc_options)) {
            error_log("QvaClick Redux Sync: Iniciando migración de datos existentes...");
            
            foreach ($qvc_options as $base_key => $template) {
                if (isset($template['subject'])) {
                    self::save_email_subject($base_key, $template['subject']);
                }
                if (isset($template['body'])) {
                    self::save_email_body($base_key, $template['body']);
                }
                if (isset($template['enabled'])) {
                    self::save_email_enabled($base_key, $template['enabled']);
                }
            }
            
            error_log("QvaClick Redux Sync: Migración completada");
        }
    }
}

// Inicializar automáticamente
QvaClick_Redux_Sync_Manager::get_instance();
