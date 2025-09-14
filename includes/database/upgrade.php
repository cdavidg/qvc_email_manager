<?php
/**
 * Database Upgrade System
 * Maneja las actualizaciones de la base de datos del sistema de hooks
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ejecutar upgrade de base de datos
 */
function qvc_hooks_upgrade_database() {
    $current_version = qvc_hooks_get_db_version();
    $target_version = QVC_EMAIL_MANAGER_VERSION . '_hooks_1.0';
    
    // Si ya está actualizada, no hacer nada
    if (version_compare($current_version, $target_version, '>=')) {
        return array('status' => 'up_to_date', 'version' => $current_version);
    }
    
    // Ejecutar migraciones según la versión
    $migration_results = array();
    
    // Primera instalación
    if ($current_version === '0.0.0' || empty($current_version)) {
        $migration_results['initial_install'] = qvc_hooks_create_tables();
    } else {
        // Futuras migraciones irán aquí
        $migration_results = qvc_hooks_run_migrations($current_version, $target_version);
    }
    
    return array(
        'status' => 'upgraded',
        'from_version' => $current_version,
        'to_version' => $target_version,
        'migrations' => $migration_results
    );
}

/**
 * Ejecutar migraciones específicas
 */
function qvc_hooks_run_migrations($from_version, $to_version) {
    global $wpdb;
    
    $migrations = array();
    
    // Ejemplo de migración futura
    // if (version_compare($from_version, '2.1.0_hooks_1.0', '<')) {
    //     $migrations['2.1.0_hooks_1.0'] = qvc_hooks_migrate_to_2_1_0();
    // }
    
    // Registrar la nueva versión
    $table_prefix = $wpdb->prefix;
    $table_db_version = $table_prefix . 'qvc_hooks_db_version';
    
    $wpdb->insert(
        $table_db_version,
        array(
            'version' => $to_version,
            'migration_log' => 'Migrations executed: ' . json_encode($migrations)
        ),
        array('%s', '%s')
    );
    
    return $migrations;
}

/**
 * Verificar integridad de las tablas
 */
function qvc_hooks_check_database_integrity() {
    global $wpdb;
    
    $table_prefix = $wpdb->prefix;
    $issues = array();
    
    // Verificar que las tablas existen
    $required_tables = array(
        'qvc_hook_emails',
        'qvc_email_logs',
        'qvc_hook_registry',
        'qvc_hooks_db_version'
    );
    
    foreach ($required_tables as $table) {
        $full_table_name = $table_prefix . $table;
        if ($wpdb->get_var("SHOW TABLES LIKE '$full_table_name'") !== $full_table_name) {
            $issues[] = "Missing table: $table";
        }
    }
    
    // Verificar foreign keys
    $foreign_keys = $wpdb->get_results("
        SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = '{$wpdb->dbname}' 
        AND TABLE_NAME LIKE '{$table_prefix}qvc_%'
        AND REFERENCED_TABLE_NAME IS NOT NULL
    ");
    
    $expected_fks = array(
        'fk_qvc_email_logs_hook_email_id',
        'fk_qvc_hook_emails_created_by',
        'fk_qvc_email_logs_recipient_user_id'
    );
    
    $existing_fks = array_column($foreign_keys, 'CONSTRAINT_NAME');
    
    foreach ($expected_fks as $expected_fk) {
        if (!in_array($expected_fk, $existing_fks)) {
            $issues[] = "Missing foreign key: $expected_fk";
        }
    }
    
    // Verificar índices
    $indices = $wpdb->get_results("
        SHOW INDEX FROM {$table_prefix}qvc_hook_emails
    ");
    
    $required_indices = array('hook_name', 'status', 'created_by');
    $existing_indices = array_column($indices, 'Key_name');
    
    foreach ($required_indices as $required_index) {
        if (!in_array($required_index, $existing_indices)) {
            $issues[] = "Missing index on qvc_hook_emails: $required_index";
        }
    }
    
    return array(
        'status' => empty($issues) ? 'healthy' : 'issues_found',
        'issues' => $issues,
        'tables_count' => count($required_tables),
        'foreign_keys_count' => count($foreign_keys)
    );
}

/**
 * Reparar problemas de base de datos
 */
function qvc_hooks_repair_database() {
    $integrity_check = qvc_hooks_check_database_integrity();
    
    if ($integrity_check['status'] === 'healthy') {
        return array('status' => 'no_repair_needed');
    }
    
    $repair_results = array();
    
    // Si faltan tablas, recrear todo
    $missing_tables = array_filter($integrity_check['issues'], function($issue) {
        return strpos($issue, 'Missing table:') === 0;
    });
    
    if (!empty($missing_tables)) {
        $repair_results['recreate_tables'] = qvc_hooks_create_tables();
    } else {
        // Reparar foreign keys si faltan
        $missing_fks = array_filter($integrity_check['issues'], function($issue) {
            return strpos($issue, 'Missing foreign key:') === 0;
        });
        
        if (!empty($missing_fks)) {
            $repair_results['foreign_keys'] = qvc_hooks_add_foreign_keys();
        }
        
        // Reparar índices si faltan
        $missing_indices = array_filter($integrity_check['issues'], function($issue) {
            return strpos($issue, 'Missing index') === 0;
        });
        
        if (!empty($missing_indices)) {
            $repair_results['indices'] = qvc_hooks_add_missing_indices();
        }
    }
    
    return array(
        'status' => 'repaired',
        'repairs' => $repair_results
    );
}

/**
 * Agregar índices faltantes
 */
function qvc_hooks_add_missing_indices() {
    global $wpdb;
    
    $table_prefix = $wpdb->prefix;
    $results = array();
    
    // Índices para qvc_hook_emails
    $wpdb->query("ALTER TABLE {$table_prefix}qvc_hook_emails ADD INDEX IF NOT EXISTS idx_hook_name (hook_name)");
    $wpdb->query("ALTER TABLE {$table_prefix}qvc_hook_emails ADD INDEX IF NOT EXISTS idx_status (status)");
    $wpdb->query("ALTER TABLE {$table_prefix}qvc_hook_emails ADD INDEX IF NOT EXISTS idx_created_by (created_by)");
    
    // Índices para qvc_email_logs
    $wpdb->query("ALTER TABLE {$table_prefix}qvc_email_logs ADD INDEX IF NOT EXISTS idx_hook_email_id (hook_email_id)");
    $wpdb->query("ALTER TABLE {$table_prefix}qvc_email_logs ADD INDEX IF NOT EXISTS idx_recipient_email (recipient_email)");
    $wpdb->query("ALTER TABLE {$table_prefix}qvc_email_logs ADD INDEX IF NOT EXISTS idx_status (status)");
    $wpdb->query("ALTER TABLE {$table_prefix}qvc_email_logs ADD INDEX IF NOT EXISTS idx_sent_at (sent_at)");
    $wpdb->query("ALTER TABLE {$table_prefix}qvc_email_logs ADD INDEX IF NOT EXISTS idx_tracking_id (tracking_id)");
    $wpdb->query("ALTER TABLE {$table_prefix}qvc_email_logs ADD INDEX IF NOT EXISTS idx_hook_name (hook_name)");
    
    // Índices para qvc_hook_registry
    $wpdb->query("ALTER TABLE {$table_prefix}qvc_hook_registry ADD INDEX IF NOT EXISTS idx_source (source)");
    $wpdb->query("ALTER TABLE {$table_prefix}qvc_hook_registry ADD INDEX IF NOT EXISTS idx_category (category)");
    $wpdb->query("ALTER TABLE {$table_prefix}qvc_hook_registry ADD INDEX IF NOT EXISTS idx_is_active (is_active)");
    
    $results['indices_added'] = 'completed';
    
    return $results;
}

/**
 * Optimizar tablas
 */
function qvc_hooks_optimize_database() {
    global $wpdb;
    
    $table_prefix = $wpdb->prefix;
    $tables = array(
        $table_prefix . 'qvc_hook_emails',
        $table_prefix . 'qvc_email_logs',
        $table_prefix . 'qvc_hook_registry',
        $table_prefix . 'qvc_hooks_db_version'
    );
    
    $results = array();
    
    foreach ($tables as $table) {
        $wpdb->query("OPTIMIZE TABLE $table");
        $results[$table] = 'optimized';
    }
    
    return $results;
}

/**
 * Limpiar logs antiguos
 */
function qvc_hooks_cleanup_old_logs($days = 365) {
    global $wpdb;
    
    $table_prefix = $wpdb->prefix;
    $table_email_logs = $table_prefix . 'qvc_email_logs';
    
    $cutoff_date = date('Y-m-d H:i:s', strtotime("-$days days"));
    
    $deleted = $wpdb->query($wpdb->prepare("
        DELETE FROM $table_email_logs 
        WHERE created_at < %s
    ", $cutoff_date));
    
    return array(
        'deleted_logs' => $deleted,
        'cutoff_date' => $cutoff_date
    );
}

/**
 * Estadísticas de la base de datos
 */
function qvc_hooks_get_database_stats() {
    global $wpdb;
    
    $table_prefix = $wpdb->prefix;
    
    $stats = array();
    
    // Contadores básicos
    $stats['hook_emails_count'] = $wpdb->get_var("SELECT COUNT(*) FROM {$table_prefix}qvc_hook_emails");
    $stats['email_logs_count'] = $wpdb->get_var("SELECT COUNT(*) FROM {$table_prefix}qvc_email_logs");
    $stats['hook_registry_count'] = $wpdb->get_var("SELECT COUNT(*) FROM {$table_prefix}qvc_hook_registry");
    
    // Estados de hook emails
    $stats['active_hooks'] = $wpdb->get_var("SELECT COUNT(*) FROM {$table_prefix}qvc_hook_emails WHERE status = 'active'");
    $stats['inactive_hooks'] = $wpdb->get_var("SELECT COUNT(*) FROM {$table_prefix}qvc_hook_emails WHERE status = 'inactive'");
    $stats['draft_hooks'] = $wpdb->get_var("SELECT COUNT(*) FROM {$table_prefix}qvc_hook_emails WHERE status = 'draft'");
    
    // Estados de emails
    $stats['sent_emails'] = $wpdb->get_var("SELECT COUNT(*) FROM {$table_prefix}qvc_email_logs WHERE status = 'sent'");
    $stats['failed_emails'] = $wpdb->get_var("SELECT COUNT(*) FROM {$table_prefix}qvc_email_logs WHERE status = 'failed'");
    $stats['pending_emails'] = $wpdb->get_var("SELECT COUNT(*) FROM {$table_prefix}qvc_email_logs WHERE status = 'pending'");
    
    // Emails del último mes
    $stats['emails_last_30_days'] = $wpdb->get_var("
        SELECT COUNT(*) FROM {$table_prefix}qvc_email_logs 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    
    // Hooks más utilizados
    $stats['top_hooks'] = $wpdb->get_results("
        SELECT hook_name, COUNT(*) as email_count 
        FROM {$table_prefix}qvc_email_logs 
        GROUP BY hook_name 
        ORDER BY email_count DESC 
        LIMIT 5
    ", ARRAY_A);
    
    // Tamaño de las tablas
    $table_sizes = $wpdb->get_results("
        SELECT table_name, 
               ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
        FROM information_schema.TABLES 
        WHERE table_schema = '{$wpdb->dbname}' 
        AND table_name LIKE '{$table_prefix}qvc_%'
    ", ARRAY_A);
    
    $stats['table_sizes'] = array();
    foreach ($table_sizes as $table) {
        $stats['table_sizes'][$table['table_name']] = $table['size_mb'] . ' MB';
    }
    
    return $stats;
}
