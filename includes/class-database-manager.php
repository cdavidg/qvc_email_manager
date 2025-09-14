<?php
/**
 * Database Manager Class
 * Gestor principal para las operaciones de base de datos del sistema de hooks
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class QvaClick_Hook_Database_Manager {
    
    /**
     * Versión actual de la base de datos
     */
    const DB_VERSION = '1.0';
    
    /**
     * Instancia singleton
     */
    private static $instance = null;
    
    /**
     * Constructor privado para patrón singleton
     */
    private function __construct() {
        // Cargar archivos de base de datos
        require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/database/create-tables.php';
        require_once QVC_EMAIL_MANAGER_PLUGIN_DIR . 'includes/database/upgrade.php';
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
     * Inicializar el sistema de base de datos
     */
    public static function init() {
        $instance = self::get_instance();
        
        // Hook para activación del plugin
        register_activation_hook(QVC_EMAIL_MANAGER_PLUGIN_DIR . 'qvaclick-email-manager.php', array($instance, 'on_plugin_activation'));
        
        // Hook para desactivación del plugin
        register_deactivation_hook(QVC_EMAIL_MANAGER_PLUGIN_DIR . 'qvaclick-email-manager.php', array($instance, 'on_plugin_deactivation'));
        
        // Verificar base de datos en cada carga de admin
        add_action('admin_init', array($instance, 'maybe_upgrade_database'));
        
        // Programar limpieza automática
        add_action('qvc_hooks_daily_cleanup', array($instance, 'daily_cleanup'));
        if (!wp_next_scheduled('qvc_hooks_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'qvc_hooks_daily_cleanup');
        }
    }
    
    /**
     * Crear todas las tablas del sistema
     */
    public static function create_tables() {
        return qvc_hooks_create_tables();
    }
    
    /**
     * Actualizar base de datos si es necesario
     */
    public static function upgrade_database() {
        return qvc_hooks_upgrade_database();
    }
    
    /**
     * Verificar versión de base de datos
     */
    public static function check_database_version() {
        $current_version = qvc_hooks_get_db_version();
        $target_version = QVC_EMAIL_MANAGER_VERSION . '_hooks_' . self::DB_VERSION;
        
        return array(
            'current' => $current_version,
            'target' => $target_version,
            'needs_upgrade' => version_compare($current_version, $target_version, '<')
        );
    }
    
    /**
     * Migrar datos existentes
     */
    public static function migrate_data() {
        // Aquí irían migraciones específicas de datos
        // Por ahora, no hay datos que migrar
        return array('status' => 'no_migration_needed');
    }
    
    /**
     * Verificar si las tablas existen
     */
    public static function tables_exist() {
        return qvc_hooks_tables_exist();
    }
    
    /**
     * Obtener estadísticas de la base de datos
     */
    public static function get_database_stats() {
        return qvc_hooks_get_database_stats();
    }
    
    /**
     * Verificar integridad de la base de datos
     */
    public static function check_integrity() {
        return qvc_hooks_check_database_integrity();
    }
    
    /**
     * Reparar base de datos
     */
    public static function repair_database() {
        return qvc_hooks_repair_database();
    }
    
    /**
     * Optimizar base de datos
     */
    public static function optimize_database() {
        return qvc_hooks_optimize_database();
    }
    
    /**
     * Limpiar logs antiguos
     */
    public static function cleanup_old_logs($days = 365) {
        return qvc_hooks_cleanup_old_logs($days);
    }
    
    /**
     * Callback para activación del plugin
     */
    public function on_plugin_activation() {
        // Crear tablas si no existen
        if (!self::tables_exist()) {
            $result = self::create_tables();
            
            // Log del resultado
            if (is_array($result)) {
                error_log('QvaClick Hook System: Tables created successfully');
            } else {
                error_log('QvaClick Hook System: Error creating tables');
            }
        } else {
            // Verificar si necesita actualización
            $version_check = self::check_database_version();
            if ($version_check['needs_upgrade']) {
                self::upgrade_database();
                error_log('QvaClick Hook System: Database upgraded to ' . $version_check['target']);
            }
        }
        
        // Programar limpieza automática
        if (!wp_next_scheduled('qvc_hooks_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'qvc_hooks_daily_cleanup');
        }
        
        // Flush rewrite rules (por si se agregan nuevos endpoints)
        flush_rewrite_rules();
    }
    
    /**
     * Callback para desactivación del plugin
     */
    public function on_plugin_deactivation() {
        // Cancelar tareas programadas
        wp_clear_scheduled_hook('qvc_hooks_daily_cleanup');
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log
        error_log('QvaClick Hook System: Plugin deactivated');
    }
    
    /**
     * Verificar si la base de datos necesita actualización
     */
    public function maybe_upgrade_database() {
        // Solo verificar en el admin
        if (!is_admin()) {
            return;
        }
        
        // Solo verificar una vez por sesión
        if (get_transient('qvc_hooks_db_check_done')) {
            return;
        }
        
        $version_check = self::check_database_version();
        
        if ($version_check['needs_upgrade']) {
            $result = self::upgrade_database();
            
            if ($result['status'] === 'upgraded') {
                // Mostrar notificación de admin
                add_action('admin_notices', array($this, 'show_upgrade_notice'));
                error_log('QvaClick Hook System: Auto-upgrade completed');
            }
        }
        
        // Marcar como verificado por 1 hora
        set_transient('qvc_hooks_db_check_done', true, HOUR_IN_SECONDS);
        // Ejecutar migraciones adicionales y verificaciones específicas
        $this->ensure_status_enum_deleted();
    }

    /**
     * Asegurar que la columna `status` en la tabla de campañas permita el valor 'deleted'.
     * Esta función es idempotente y sólo ejecutará ALTER TABLE si es necesario.
     */
    public function ensure_status_enum_deleted() {
        global $wpdb;
        $table = $wpdb->prefix . 'qvc_mass_emails';

        // Comprobar si la tabla existe
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$exists) {
            error_log('QvaClick DB Migration: table ' . $table . ' does not exist. Skipping status enum check.');
            return false;
        }

        // Obtener la definición actual de la columna status
        $row = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'status'), ARRAY_A);
        if (empty($row) || empty($row['Type'])) {
            error_log('QvaClick DB Migration: Could not fetch status column info for ' . $table);
            return false;
        }

        $type = $row['Type']; // ejemplo: enum('draft','scheduled',...)
        if (stripos($type, "'deleted'") !== false) {
            // Ya existe el valor 'deleted'
            return true;
        }

        // Construir nueva definición agregando 'deleted' antes del paréntesis de cierre
        // Extraer contenidos del enum
        if (preg_match("/^enum\((.*)\)$/i", $type, $m)) {
            $values = $m[1];
            $new_values = $values . ", 'deleted'";
            $sql = "ALTER TABLE {$table} MODIFY status enum({$new_values}) DEFAULT 'draft'";
            $res = $wpdb->query($sql);
            if ($res === false) {
                error_log('QvaClick DB Migration: Failed to ALTER TABLE to add deleted. WPDB Error: ' . $wpdb->last_error);
                return false;
            }
            error_log('QvaClick DB Migration: Added "deleted" to status enum on ' . $table);
            return true;
        }

        error_log('QvaClick DB Migration: status column type did not match enum pattern: ' . $type);
        return false;
    }
    
    /**
     * Mostrar aviso de actualización completada
     */
    public function show_upgrade_notice() {
        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <strong><?php _e('QvaClick Email Manager:', 'qvaclick-email-manager'); ?></strong>
                <?php _e('Sistema de hooks actualizado correctamente.', 'qvaclick-email-manager'); ?>
            </p>
        </div>
        <?php
    }
    
    /**
     * Limpieza diaria automática
     */
    public function daily_cleanup() {
        // Limpiar logs de más de 1 año
        $cleanup_result = self::cleanup_old_logs(365);
        
        // Optimizar tablas una vez por semana
        if (date('w') == 0) { // Domingo
            self::optimize_database();
        }
        
        // Log de limpieza
        if ($cleanup_result['deleted_logs'] > 0) {
            error_log("QvaClick Hook System: Daily cleanup - {$cleanup_result['deleted_logs']} old logs deleted");
        }
    }
    
    /**
     * Backup de emergencia antes de operaciones críticas
     */
    public static function emergency_backup() {
        return qvc_hooks_backup_tables();
    }
    
    /**
     * Restaurar desde backup
     */
    public static function restore_from_backup($backup_suffix) {
        global $wpdb;
        
        $table_prefix = $wpdb->prefix;
        $tables = array(
            'qvc_hook_emails',
            'qvc_email_logs',
            'qvc_hook_registry'
        );
        
        $results = array();
        
        foreach ($tables as $table) {
            $main_table = $table_prefix . $table;
            $backup_table = $main_table . '_backup_' . $backup_suffix;
            
            // Verificar que el backup existe
            if ($wpdb->get_var("SHOW TABLES LIKE '$backup_table'") === $backup_table) {
                // Truncar tabla principal
                $wpdb->query("TRUNCATE TABLE $main_table");
                
                // Restaurar desde backup
                $result = $wpdb->query("INSERT INTO $main_table SELECT * FROM $backup_table");
                $results[$table] = $result !== false ? 'restored' : 'failed';
            } else {
                $results[$table] = 'backup_not_found';
            }
        }
        
        return $results;
    }
    
    /**
     * Obtener información del sistema
     */
    public static function get_system_info() {
        global $wpdb;
        
        return array(
            'plugin_version' => QVC_EMAIL_MANAGER_VERSION,
            'db_version' => self::check_database_version(),
            'tables_exist' => self::tables_exist(),
            'database_stats' => self::get_database_stats(),
            'integrity_check' => self::check_integrity(),
            'mysql_version' => $wpdb->db_version(),
            'php_version' => PHP_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time')
        );
    }
    
    /**
     * Ejecutar test de performance
     */
    public static function performance_test() {
        $start_time = microtime(true);
        
        // Test de escritura
        $write_start = microtime(true);
        global $wpdb;
        $table_prefix = $wpdb->prefix;
        
        $wpdb->insert(
            $table_prefix . 'qvc_hook_registry',
            array(
                'hook_name' => 'test_performance_' . time(),
                'hook_type' => 'action',
                'source' => 'test',
                'description' => 'Performance test hook',
                'category' => 'test'
            )
        );
        $test_id = $wpdb->insert_id;
        $write_time = microtime(true) - $write_start;
        
        // Test de lectura
        $read_start = microtime(true);
        $wpdb->get_row("SELECT * FROM {$table_prefix}qvc_hook_registry WHERE id = $test_id");
        $read_time = microtime(true) - $read_start;
        
        // Test de eliminación
        $delete_start = microtime(true);
        $wpdb->delete($table_prefix . 'qvc_hook_registry', array('id' => $test_id));
        $delete_time = microtime(true) - $delete_start;
        
        $total_time = microtime(true) - $start_time;
        
        return array(
            'total_time' => round($total_time * 1000, 2) . 'ms',
            'write_time' => round($write_time * 1000, 2) . 'ms',
            'read_time' => round($read_time * 1000, 2) . 'ms',
            'delete_time' => round($delete_time * 1000, 2) . 'ms',
            'status' => $total_time < 0.1 ? 'excellent' : ($total_time < 0.5 ? 'good' : 'slow')
        );
    }
}
