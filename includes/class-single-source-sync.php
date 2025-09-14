<?php
/**
 * Sincronizador Unidireccional QvaClick -> Redux
 * Asegura UNA SOLA FUENTE DE VERDAD en Redux
 */

class QvaClick_Single_Source_Sync {
    
    private static $instance = null;
    
    // Definición de subjects MAESTROS que deben existir
    private static $master_subjects = array(
        'user_registration' => 'Bienvenido a %site_name%',
        'user_verification' => 'Confirma tu cuenta en %site_name%',
        'password_reset' => 'Restablecer contraseña - %site_name%',
        'project_publication' => 'Tu proyecto ha sido publicado - %site_name%',
        'project_approval' => 'Proyecto aprobado en %site_name%',
        'project_rejection' => 'Proyecto rechazado en %site_name%',
        'project_update' => 'Actualización de proyecto - %site_name%',
        'new_message' => 'Nuevo mensaje en %site_name%',
        'admin_notification' => 'Notificación administrativa - %site_name%',
        // Subjects existentes en Redux que queremos mantener
        'fl_email_zoom_meet' => 'Tienes una invitación para reunión Zoom',
        'custom_offer' => 'Nueva oferta personalizada — %service_title%',
        'fl_package_expiray' => 'Tu paquete %package_name% vence en %no_of_days% días'
    );
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_init', array($this, 'check_and_sync'), 5); // Prioridad alta
    }
    
    /**
     * Verificar y sincronizar automáticamente
     */
    public function check_and_sync() {
        // Solo ejecutar para administradores y en páginas específicas
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Verificar si necesita sincronización
        if ($this->needs_sync()) {
            $this->force_sync_to_redux();
        }
    }
    
    /**
     * Verificar si necesita sincronización
     */
    private function needs_sync() {
        $redux_options = get_option('exertio_theme_options', array());
        
        // Verificar si todos los subjects maestros están en Redux
        foreach (self::$master_subjects as $base_key => $expected_subject) {
            // Preferir la clave real existente en Redux (p. ej. _sub)
            $existing = class_exists('QvaClick_Redux_Sync_Manager') ? QvaClick_Redux_Sync_Manager::get_email_subject($base_key) : null;
            $subject_key = $existing ? $existing['key'] : ($base_key . '_sub');
            
            if (!isset($redux_options[$subject_key]) || 
                trim($redux_options[$subject_key]) !== trim($expected_subject)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Forzar sincronización completa a Redux
     */
    public function force_sync_to_redux() {
        $redux_options = get_option('exertio_theme_options', array());
        $updated = false;
        
        error_log("QvaClick Single Source: Iniciando sincronización forzada...");
        
        foreach (self::$master_subjects as $base_key => $master_subject) {
            // Detectar claves reales existentes en Redux para sujet/body/switch
            $subject_info = class_exists('QvaClick_Redux_Sync_Manager') ? QvaClick_Redux_Sync_Manager::get_email_subject($base_key) : null;
            $subject_key = $subject_info ? $subject_info['key'] : ($base_key . '_sub');

            $body_info = class_exists('QvaClick_Redux_Sync_Manager') ? QvaClick_Redux_Sync_Manager::get_email_body($base_key) : null;
            $body_key = $body_info ? $body_info['key'] : ($base_key . '_email_body');

            $enabled_info = class_exists('QvaClick_Redux_Sync_Manager') ? QvaClick_Redux_Sync_Manager::get_email_enabled($base_key) : null;
            $switch_key = ($enabled_info && !empty($enabled_info['key'])) ? $enabled_info['key'] : ($base_key . '_switch');
            
            // 1. FORZAR SUBJECT MAESTRO
            if (!isset($redux_options[$subject_key]) || 
                trim($redux_options[$subject_key]) !== trim($master_subject)) {
                
                $old_value = $redux_options[$subject_key] ?? 'NO EXISTÍA';
                $redux_options[$subject_key] = $master_subject;
                $updated = true;
                
                error_log("QvaClick Single Source: Subject actualizado - $subject_key");
                error_log("  ANTES: $old_value");
                error_log("  DESPUÉS: $master_subject");
            }
            
            // 2. ASEGURAR BODY BÁSICO SI NO EXISTE
            if (!isset($redux_options[$body_key]) || empty(trim($redux_options[$body_key]))) {
                $redux_options[$body_key] = $this->get_default_body($base_key);
                $updated = true;
                error_log("QvaClick Single Source: Body creado - $body_key");
            }
            
            // 3. ASEGURAR SWITCH HABILITADO
            if (!isset($redux_options[$switch_key])) {
                $redux_options[$switch_key] = 1;
                $updated = true;
                error_log("QvaClick Single Source: Switch habilitado - $switch_key");
            }
        }
        
        // 4. LIMPIAR SUBJECTS DUPLICADOS O CONFLICTIVOS
        $this->clean_duplicate_subjects($redux_options);
        $updated = true;
        
        if ($updated) {
            update_option('exertio_theme_options', $redux_options);
            error_log("QvaClick Single Source: Sincronización completada y guardada en Redux");
            
            // Opcional: Mostrar notificación en admin
            add_action('admin_notices', array($this, 'sync_notice'));
        }
    }
    
    /**
     * Limpiar subjects duplicados o con nombres similares
     */
    private function clean_duplicate_subjects(&$redux_options) {
        $subjects_to_clean = array();
        
        // Buscar claves que puedan ser duplicados
        foreach ($redux_options as $key => $value) {
            if (strpos($key, '_subject') !== false || strpos($key, '_subj') !== false || strpos($key, '_sub') !== false) {
                // Extraer base_key
                $base_key = preg_replace('/_(subject|subj|sub)$/', '', $key);
                
                // Si no está en nuestros masters, marcarlo para revisión
                if (!isset(self::$master_subjects[$base_key])) {
                    $subjects_to_clean[$key] = $value;
                }
            }
        }
        
        error_log("QvaClick Single Source: Subjects para limpiar: " . implode(', ', array_keys($subjects_to_clean)));
        
        // Por ahora solo loggear, no eliminar automáticamente
        // En el futuro se puede implementar limpieza automática
    }
    
    /**
     * Obtener body por defecto para un base_key
     */
    private function get_default_body($base_key) {
        $default_bodies = array(
            'user_registration' => 'Hola %display_name%, bienvenido a %site_name%. Tu cuenta ha sido creada exitosamente.',
            'user_verification' => 'Hola %display_name%, confirma tu cuenta haciendo clic en: %verification_link%',
            'password_reset' => 'Hola %display_name%, restablece tu contraseña en: %reset_link%',
            'project_publication' => 'Hola %display_name%, tu proyecto ha sido publicado en %site_name%.',
            'project_approval' => 'Hola %display_name%, tu proyecto ha sido aprobado en %site_name%.',
            'project_rejection' => 'Hola %display_name%, tu proyecto ha sido rechazado en %site_name%.',
            'project_update' => 'Hola %display_name%, hay una actualización en tu proyecto en %site_name%.',
            'new_message' => 'Hola %display_name%, tienes un nuevo mensaje en %site_name%.',
            'admin_notification' => 'Notificación administrativa para %display_name% en %site_name%.'
        );
        
        return $default_bodies[$base_key] ?? "Email automático de %site_name% para %display_name%.";
    }
    
    /**
     * Mostrar notificación de sincronización
     */
    public function sync_notice() {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>QvaClick Email Manager:</strong> Subjects sincronizados con Redux. Todos los emails ahora usan una sola fuente de verdad.</p>
        </div>
        <?php
    }
    
    /**
     * Obtener report completo de sincronización
     */
    public static function get_sync_report() {
        $redux_options = get_option('exertio_theme_options', array());
        $report = array(
            'masters_in_redux' => 0,
            'masters_missing' => array(),
            'masters_different' => array(),
            'extra_subjects' => array(),
            'total_subjects_in_redux' => 0
        );
        
        // Contar subjects en Redux
        foreach ($redux_options as $key => $value) {
            if ((strpos($key, '_subject') !== false || strpos($key, '_subj') !== false || strpos($key, '_sub') !== false) && !empty($value)) {
                $report['total_subjects_in_redux']++;
            }
        }
        
        // Verificar masters
        foreach (self::$master_subjects as $base_key => $expected_subject) {
            $subject_info = class_exists('QvaClick_Redux_Sync_Manager') ? QvaClick_Redux_Sync_Manager::get_email_subject($base_key) : null;
            $subject_key = $subject_info ? $subject_info['key'] : ($base_key . '_sub');
            
            if (!isset($redux_options[$subject_key])) {
                $report['masters_missing'][] = $base_key;
            } elseif (trim($redux_options[$subject_key]) !== trim($expected_subject)) {
                $report['masters_different'][$base_key] = array(
                    'expected' => $expected_subject,
                    'current' => $redux_options[$subject_key]
                );
            } else {
                $report['masters_in_redux']++;
            }
        }
        
        // Buscar subjects extra
        foreach ($redux_options as $key => $value) {
            if ((strpos($key, '_subject') !== false || strpos($key, '_subj') !== false || strpos($key, '_sub') !== false) && !empty($value)) {
                $base_key = preg_replace('/_(subject|subj|sub)$/', '', $key);
                if (!isset(self::$master_subjects[$base_key])) {
                    $report['extra_subjects'][$key] = $value;
                }
            }
        }
        
        return $report;
    }
    
    /**
     * Ejecutar sincronización manual (para debug)
     */
    public static function manual_sync() {
        $instance = self::get_instance();
        $instance->force_sync_to_redux();
        return self::get_sync_report();
    }
}

// Inicializar automáticamente
QvaClick_Single_Source_Sync::get_instance();
