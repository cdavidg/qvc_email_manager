<?php
/**
 * Database Tables Creation
 * Crea las tablas necesarias para el sistema de hooks
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Crea todas las tablas necesarias para el sistema de hooks
 */
function qvc_hooks_create_tables() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    $table_prefix = $wpdb->prefix;
    
    // Tabla para emails configurados por hooks
    $table_hook_emails = $table_prefix . 'qvc_hook_emails';
    $sql_hook_emails = "CREATE TABLE $table_hook_emails (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        hook_name varchar(255) NOT NULL,
        status enum('active','inactive','draft') DEFAULT 'draft',
        email_to_type enum('admin','user','custom','multiple') NOT NULL,
        email_to_value text,
        subject varchar(500) NOT NULL,
        content longtext NOT NULL,
        use_base_template tinyint(1) DEFAULT 1,
        variables text,
        conditions text,
        priority int(11) DEFAULT 10,
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_by bigint(20) UNSIGNED,
        PRIMARY KEY (id),
        KEY hook_name (hook_name),
        KEY status (status),
        KEY created_by (created_by)
    ) $charset_collate;";
    
    // Tabla para logs de emails enviados
    $table_email_logs = $table_prefix . 'qvc_email_logs';
    $sql_email_logs = "CREATE TABLE $table_email_logs (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        hook_email_id bigint(20) UNSIGNED,
        hook_name varchar(255) NOT NULL,
        recipient_email varchar(320) NOT NULL,
        recipient_user_id bigint(20) UNSIGNED NULL,
        subject varchar(500) NOT NULL,
        status enum('sent','failed','pending','bounced') DEFAULT 'pending',
        sent_at timestamp NULL,
        opened_at timestamp NULL,
        clicked_at timestamp NULL,
        bounce_reason text NULL,
        hook_data longtext,
        email_content longtext,
        tracking_id varchar(32) UNIQUE,
        ip_address varchar(45),
        user_agent text,
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY hook_email_id (hook_email_id),
        KEY recipient_email (recipient_email),
        KEY status (status),
        KEY sent_at (sent_at),
        KEY tracking_id (tracking_id),
        KEY hook_name (hook_name)
    ) $charset_collate;";
    
    // Tabla para registro de hooks disponibles
    $table_hook_registry = $table_prefix . 'qvc_hook_registry';
    $sql_hook_registry = "CREATE TABLE $table_hook_registry (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        hook_name varchar(255) NOT NULL UNIQUE,
        hook_type enum('action','filter') NOT NULL,
        source varchar(100) NOT NULL,
        description text,
        parameters text,
        category varchar(100),
        is_active tinyint(1) DEFAULT 1,
        last_triggered timestamp NULL,
        trigger_count int(11) DEFAULT 0,
        discovered_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY hook_name (hook_name),
        KEY source (source),
        KEY category (category),
        KEY is_active (is_active)
    ) $charset_collate;";
    
    // Tabla para versioning de la base de datos
    $table_db_version = $table_prefix . 'qvc_hooks_db_version';
    $sql_db_version = "CREATE TABLE $table_db_version (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        version varchar(20) NOT NULL,
        applied_at timestamp DEFAULT CURRENT_TIMESTAMP,
        migration_log text,
        PRIMARY KEY (id),
        KEY version (version)
    ) $charset_collate;";
    
    // Incluir función de WordPress para crear tablas
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    // Crear las tablas
    $results = array();
    
    $results['hook_emails'] = dbDelta($sql_hook_emails);
    $results['email_logs'] = dbDelta($sql_email_logs);
    $results['hook_registry'] = dbDelta($sql_hook_registry);
    $results['db_version'] = dbDelta($sql_db_version);
    
    // Registrar la versión actual
    $current_version = QVC_EMAIL_MANAGER_VERSION . '_hooks_1.0';
    $wpdb->insert(
        $table_db_version,
        array(
            'version' => $current_version,
            'migration_log' => 'Initial hook system tables created: ' . json_encode($results)
        ),
        array('%s', '%s')
    );
    
    // Agregar foreign keys (después de crear las tablas)
    qvc_hooks_add_foreign_keys();
    
    // Poblar datos iniciales
    qvc_hooks_populate_initial_data();
    
    return $results;
}

/**
 * Agrega foreign keys a las tablas
 */
function qvc_hooks_add_foreign_keys() {
    global $wpdb;
    
    $table_prefix = $wpdb->prefix;
    
    // Solo agregar si no existen (para evitar errores en re-instalaciones)
    $existing_fks = $wpdb->get_results("
        SELECT CONSTRAINT_NAME 
        FROM information_schema.TABLE_CONSTRAINTS 
        WHERE TABLE_SCHEMA = '{$wpdb->dbname}' 
        AND TABLE_NAME = '{$table_prefix}qvc_email_logs' 
        AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ");
    
    if (empty($existing_fks)) {
        // Foreign key para hook_email_id
        $wpdb->query("
            ALTER TABLE {$table_prefix}qvc_email_logs 
            ADD CONSTRAINT fk_qvc_email_logs_hook_email_id 
            FOREIGN KEY (hook_email_id) 
            REFERENCES {$table_prefix}qvc_hook_emails(id) 
            ON DELETE CASCADE
        ");
        
        // Foreign key para created_by
        $wpdb->query("
            ALTER TABLE {$table_prefix}qvc_hook_emails 
            ADD CONSTRAINT fk_qvc_hook_emails_created_by 
            FOREIGN KEY (created_by) 
            REFERENCES {$table_prefix}users(ID) 
            ON DELETE SET NULL
        ");
        
        // Foreign key para recipient_user_id
        $wpdb->query("
            ALTER TABLE {$table_prefix}qvc_email_logs 
            ADD CONSTRAINT fk_qvc_email_logs_recipient_user_id 
            FOREIGN KEY (recipient_user_id) 
            REFERENCES {$table_prefix}users(ID) 
            ON DELETE SET NULL
        ");
    }
}

/**
 * Poblar datos iniciales
 */
function qvc_hooks_populate_initial_data() {
    global $wpdb;
    
    $table_prefix = $wpdb->prefix;
    $table_hook_registry = $table_prefix . 'qvc_hook_registry';
    
    // Hooks básicos de WordPress que siempre están disponibles
    $initial_hooks = array(
        array(
            'hook_name' => 'user_register',
            'hook_type' => 'action',
            'source' => 'wordpress',
            'description' => 'Se ejecuta cuando un nuevo usuario se registra',
            'parameters' => json_encode(['user_id' => 'ID del usuario registrado']),
            'category' => 'usuario'
        ),
        array(
            'hook_name' => 'wp_login',
            'hook_type' => 'action',
            'source' => 'wordpress',
            'description' => 'Se ejecuta cuando un usuario inicia sesión',
            'parameters' => json_encode(['user_login' => 'Nombre de usuario', 'user' => 'Objeto WP_User']),
            'category' => 'usuario'
        ),
        array(
            'hook_name' => 'wp_logout',
            'hook_type' => 'action',
            'source' => 'wordpress',
            'description' => 'Se ejecuta cuando un usuario cierra sesión',
            'parameters' => json_encode(['user_id' => 'ID del usuario']),
            'category' => 'usuario'
        ),
        array(
            'hook_name' => 'publish_post',
            'hook_type' => 'action',
            'source' => 'wordpress',
            'description' => 'Se ejecuta cuando se publica un post',
            'parameters' => json_encode(['post_id' => 'ID del post', 'post' => 'Objeto WP_Post']),
            'category' => 'contenido'
        ),
        array(
            'hook_name' => 'comment_post',
            'hook_type' => 'action',
            'source' => 'wordpress',
            'description' => 'Se ejecuta cuando se envía un comentario',
            'parameters' => json_encode(['comment_id' => 'ID del comentario', 'comment_approved' => 'Estado de aprobación']),
            'category' => 'comentarios'
        ),
        array(
            'hook_name' => 'wp_insert_user',
            'hook_type' => 'action',
            'source' => 'wordpress',
            'description' => 'Se ejecuta cuando se crea un usuario (programáticamente)',
            'parameters' => json_encode(['user_id' => 'ID del usuario', 'userdata' => 'Datos del usuario']),
            'category' => 'usuario'
        )
    );
    
    // Insertar hooks iniciales
    foreach ($initial_hooks as $hook) {
        $wpdb->insert($table_hook_registry, $hook);
    }
}

/**
 * Verificar si las tablas existen
 */
function qvc_hooks_tables_exist() {
    global $wpdb;
    
    $table_prefix = $wpdb->prefix;
    $required_tables = array(
        $table_prefix . 'qvc_hook_emails',
        $table_prefix . 'qvc_email_logs', 
        $table_prefix . 'qvc_hook_registry',
        $table_prefix . 'qvc_hooks_db_version'
    );
    
    foreach ($required_tables as $table) {
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return false;
        }
    }
    
    return true;
}

/**
 * Obtener la versión actual de la base de datos
 */
function qvc_hooks_get_db_version() {
    global $wpdb;
    
    $table_prefix = $wpdb->prefix;
    $table_db_version = $table_prefix . 'qvc_hooks_db_version';
    
    $version = $wpdb->get_var("
        SELECT version 
        FROM $table_db_version 
        ORDER BY applied_at DESC 
        LIMIT 1
    ");
    
    return $version ? $version : '0.0.0';
}

/**
 * Eliminar todas las tablas del sistema de hooks
 */
function qvc_hooks_drop_tables() {
    global $wpdb;
    
    $table_prefix = $wpdb->prefix;
    
    // Eliminar foreign keys primero
    $wpdb->query("SET FOREIGN_KEY_CHECKS = 0");
    
    // Eliminar tablas en orden correcto
    $tables = array(
        $table_prefix . 'qvc_email_logs',
        $table_prefix . 'qvc_hook_emails',
        $table_prefix . 'qvc_hook_registry',
        $table_prefix . 'qvc_hooks_db_version'
    );
    
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS $table");
    }
    
    $wpdb->query("SET FOREIGN_KEY_CHECKS = 1");
}

/**
 * Backup de tablas antes de upgrade
 */
function qvc_hooks_backup_tables() {
    global $wpdb;
    
    $table_prefix = $wpdb->prefix;
    $backup_suffix = '_backup_' . date('Y_m_d_H_i_s');
    
    $tables = array(
        $table_prefix . 'qvc_hook_emails',
        $table_prefix . 'qvc_email_logs',
        $table_prefix . 'qvc_hook_registry'
    );
    
    $backup_results = array();
    
    foreach ($tables as $table) {
        $backup_table = $table . $backup_suffix;
        $result = $wpdb->query("CREATE TABLE $backup_table AS SELECT * FROM $table");
        $backup_results[$table] = $result !== false ? 'success' : 'failed';
    }
    
    return $backup_results;
}
