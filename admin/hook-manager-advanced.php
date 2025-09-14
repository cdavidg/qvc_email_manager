<?php
/**
 * Hook Manager Advanced - Interfaz completa para gestión de hooks
 * Fase 2: Hook Discovery System
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Página principal del Hook Manager con discovery system
 */
function qvaclick_email_hook_manager_advanced_page() {
    // Verificar permisos (permite rol personalizado)
    if (!function_exists('qvc_user_can_manage')) {
        function qvc_user_can_manage() {
            return current_user_can('qvc_manage_emails') || current_user_can('manage_options');
        }
    }
    if (!qvc_user_can_manage()) {
        wp_die(__('No tienes permisos para acceder a esta página.'));
    }
    
    // FUNCIÓN DE DEBUG AUTOMÁTICA - FORZAR CREACIÓN DE TABLA CORRECTA
    if (isset($_GET['debug_force_table']) && $_GET['debug_force_table'] === '1') {
        qvaclick_force_create_correct_table();
    }
    
    // AUTO-REPARACIÓN: Si la tabla no tiene la columna parameters, la agrega automáticamente
    global $wpdb;
    $table_name = $wpdb->prefix . 'qvc_hook_registry';
    $columns = $wpdb->get_results("SHOW COLUMNS FROM `$table_name` LIKE 'parameters'");
    if (empty($columns)) {
        // La columna no existe, agregarla automáticamente
        $wpdb->query("ALTER TABLE `$table_name` ADD COLUMN `parameters` longtext AFTER `description`");
        $wpdb->query("UPDATE `$table_name` SET `parameters` = '[]' WHERE `parameters` IS NULL");
        error_log("QvaClick AUTO-REPAIR: Columna 'parameters' agregada automáticamente");
    }
    
    // Nota: QvaClick_Hook_Discovery está deshabilitado temporalmente
    // La funcionalidad de discovery automático no está disponible en esta versión
    
    // Procesar acciones
    $message = '';
    $message_type = '';
    
    if (isset($_POST['action']) && wp_verify_nonce($_POST['qvaclick_nonce'], 'qvaclick_hook_action')) {
        switch ($_POST['action']) {
            case 'fix_database':
                $result = qvaclick_fix_database_and_discovery();
                if ($result['success']) {
                    $message = 'Éxito: ' . $result['message'];
                    $message_type = 'success';
                } else {
                    $message = 'Error: ' . $result['message'];
                    if (isset($result['diagnosis'])) {
                        $message .= '<br><br><strong>DIAGNÓSTICO:</strong><br>';
                        $message .= '<pre>' . print_r($result['diagnosis'], true) . '</pre>';
                    }
                    $message_type = 'error';
                }
                break;
                
            case 'simple_table_creation':
                $result = qvaclick_simple_table_creation();
                if ($result['success']) {
                    $message = 'Tabla básica creada: ' . $result['message'];
                    $message_type = 'success';
                } else {
                    $message = 'Error en creación simple: ' . $result['message'];
                    $message_type = 'error';
                }
                break;
                
            case 'update_table_structure':
                $result = qvaclick_update_table_structure();
                if ($result['success']) {
                    $message = 'Estructura de tabla actualizada: ' . $result['message'];
                    $message_type = 'success';
                } else {
                    $message = 'Error actualizando estructura: ' . $result['message'];
                    $message_type = 'error';
                }
                break;
                
            case 'analyze_configured_emails':
                $result = qvaclick_analyze_configured_emails();
                if ($result['success']) {
                    $message = 'Análisis de emails completado: ' . $result['message'];
                    $message_type = 'success';
                } else {
                    $message = 'Error en análisis: ' . $result['message'];
                    $message_type = 'error';
                }
                break;

            case 'create_hook_email':
                // Crear un nuevo email por hook desde la pestaña
                global $wpdb;
                $table_emails = $wpdb->prefix . 'qvc_hook_emails';
                $hook_name = sanitize_text_field($_POST['hook_name'] ?? '');
                $name = sanitize_text_field($_POST['name'] ?? '');
                $email_to_type = sanitize_text_field($_POST['email_to_type'] ?? 'user');
                $email_to_value = sanitize_text_field($_POST['email_to_value'] ?? 'receiver');
                $subject = sanitize_text_field($_POST['subject'] ?? '');
                $content = wp_kses_post($_POST['content'] ?? '');
                $conditions = wp_unslash($_POST['conditions'] ?? '{}');
                $use_base_template = isset($_POST['use_base_template']) ? 1 : 0;
                $status = sanitize_text_field($_POST['status'] ?? 'active');
                if (!$hook_name || !$name || !$subject || !$content) {
                    $message = 'Completa los campos obligatorios (Hook, Nombre, Asunto, Contenido)';
                    $message_type = 'error';
                } else {
                    $wpdb->insert($table_emails, array(
                        'name' => $name,
                        'hook_name' => $hook_name,
                        'status' => in_array($status, array('active','inactive','draft'), true) ? $status : 'active',
                        'email_to_type' => in_array($email_to_type, array('admin','user','custom','multiple'), true) ? $email_to_type : 'user',
                        'email_to_value' => $email_to_value,
                        'subject' => $subject,
                        'content' => $content,
                        'use_base_template' => $use_base_template,
                        'variables' => '',
                        'conditions' => $conditions,
                        'priority' => 10,
                        'created_by' => get_current_user_id(),
                    ));
                    if ($wpdb->last_error) {
                        $message = 'Error al crear email: ' . esc_html($wpdb->last_error);
                        $message_type = 'error';
                    } else {
                        $message = 'Email por hook creado correctamente';
                        $message_type = 'success';
                    }
                }
                break;
                
            case 'discover_hooks':
                $result = qvaclick_run_hooks_discovery();
                if ($result['success']) {
                    $message = sprintf(
                        'Discovery completado: %d hooks descubiertos en %s ms', 
                        $result['data']['hooks_discovered'], 
                        $result['data']['execution_time_ms']
                    );
                    $message_type = 'success';
                } else {
                    $message = 'Error durante el discovery: ' . $result['message'];
                    $message_type = 'error';
                }
                break;
                
            case 'toggle_hook':
                if (isset($_POST['hook_id'])) {
                    $result = qvaclick_toggle_hook_status($_POST['hook_id']);
                    if ($result) {
                        $message = 'Estado del hook actualizado correctamente';
                        $message_type = 'success';
                    } else {
                        $message = 'Error al actualizar el estado del hook';
                        $message_type = 'error';
                    }
                }
                break;
            
            case 'delete_hook_email':
                if (!empty($_POST['hook_email_id'])) {
                    $hook_email_id = intval($_POST['hook_email_id']);
                    global $wpdb;
                    $table_emails = $wpdb->prefix . 'qvc_hook_emails';
                    // Borrar el email (los logs se borrarán en cascada si existe FK)
                    $deleted = $wpdb->delete($table_emails, array('id' => $hook_email_id), array('%d'));
                    if ($deleted !== false) {
                        $message = 'Email por hook eliminado correctamente';
                        $message_type = 'success';
                    } else {
                        $message = 'No se pudo eliminar el email por hook.';
                        if ($wpdb->last_error) { $message .= ' Error: ' . esc_html($wpdb->last_error); }
                        $message_type = 'error';
                    }
                }
                break;
        }
    }
    
    // Obtener datos para la interfaz
    $hooks = qvaclick_get_all_discovered_hooks();
    $discovery_stats = qvaclick_get_discovery_statistics();
    $hook_stats = qvaclick_get_hook_usage_stats();
    
    // DEBUG: Información de depuración temporal
    $debug_info = array(
        'hooks_count' => is_array($hooks) ? count($hooks) : 0,
        'hooks_type' => gettype($hooks),
        'total_hooks_stat' => $discovery_stats['total_hooks'],
        'database_table_exists' => qvaclick_check_hook_table_exists()
    );
    
    ?>
    <div class="wrap qvc-hook-manager">
        <h1>
            <span class="dashicons dashicons-admin-generic"></span>
            Hook Manager - Sistema Avanzado
        </h1>
        <p class="description">
            Sistema completo de gestión de hooks para emails automáticos. 
            Detecta automáticamente eventos disponibles en WordPress, Exertio Framework y WooCommerce.
        </p>
        
        <?php if ($message): ?>
            <div class="notice notice-<?php echo $message_type; ?> is-dismissible">
                <p><?php echo $message; ?></p>
            </div>
        <?php endif; ?>
        
        <!-- DEBUG: Información temporal para diagnosticar el problema -->
        <div class="notice notice-warning">
            <h3>🔧 DEBUG - Información del Sistema</h3>
            <ul>
                <li><strong>Hooks encontrados en array:</strong> <?php echo $debug_info['hooks_count']; ?></li>
                <li><strong>Tipo de variable hooks:</strong> <?php echo $debug_info['hooks_type']; ?></li>
                <li><strong>Total hooks en estadísticas:</strong> <?php echo $debug_info['total_hooks_stat']; ?></li>
                <li><strong>Tabla existe:</strong> <?php echo $debug_info['database_table_exists'] ? 'SÍ' : 'NO'; ?></li>
                <?php if (!empty($hooks)): ?>
                    <li><strong>Primer hook ejemplo:</strong> <?php echo esc_html($hooks[0]->hook_name ?? 'N/A'); ?></li>
                <?php endif; ?>
            </ul>
            
            <?php if (!$debug_info['database_table_exists']): ?>
                <div style="background: #fee; padding: 10px; border-left: 4px solid #d00; margin: 10px 0;">
                    <strong>⚠️ PROBLEMA DETECTADO:</strong> Las tablas de base de datos no existen. Esto explica por qué no ves hooks.
                </div>
                
                <!-- Opción 1: Diagnóstico completo -->
                <form method="post" style="margin-top: 10px;">
                    <?php wp_nonce_field('qvaclick_hook_action', 'qvaclick_nonce'); ?>
                    <input type="hidden" name="action" value="fix_database">
                    <button type="submit" class="button button-primary" style="background: #00a32a;">
                        🔧 DIAGNÓSTICO COMPLETO - Crear Tablas con Análisis Detallado
                    </button>
                    <p><em>Analiza el problema en detalle y crea las tablas necesarias.</em></p>
                </form>
                
                <!-- Opción 2: SQL simple -->
                <form method="post" style="margin-top: 15px;">
                    <?php wp_nonce_field('qvaclick_hook_action', 'qvaclick_nonce'); ?>
                    <input type="hidden" name="action" value="simple_table_creation">
                    <button type="submit" class="button button-secondary" style="background: #2271b1;">
                        📋 MÉTODO SIMPLE - Solo Crear Tabla Básica
                    </button>
                    <p><em>Crear solo la tabla principal con estructura mínima.</em></p>
                </form>
                
                <!-- Opción 3: Actualizar estructura -->
                <form method="post" style="margin-top: 15px;">
                    <?php wp_nonce_field('qvaclick_hook_action', 'qvaclick_nonce'); ?>
                    <input type="hidden" name="action" value="update_table_structure">
                    <button type="submit" class="button button-secondary" style="background: #f0ad4e;">
                        🔧 AGREGAR COLUMNA PARAMETERS
                    </button>
                    <p><em>Agrega la columna 'parameters' a la tabla existente.</em></p>
                </form>
                
                <!-- Opción 4: Analizar emails configurados -->
                <form method="post" style="margin-top: 15px;">
                    <?php wp_nonce_field('qvaclick_hook_action', 'qvaclick_nonce'); ?>
                    <input type="hidden" name="action" value="analyze_configured_emails">
                    <button type="submit" class="button button-secondary" style="background: #17a2b8;">
                        📊 ANALIZAR 45 EMAILS CONFIGURADOS
                    </button>
                    <p><em>Extrae hooks de emails existentes y actualiza descripciones.</em></p>
                </form>
                
            <?php else: ?>
                <div style="background: #e7f7d3; padding: 10px; border-left: 4px solid #4caf50; margin: 10px 0;">
                    <strong>✅ TABLAS OK:</strong> Las tablas existen. Si no ves hooks, ejecuta discovery manual.
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Dashboard de Estadísticas -->
        <div class="qvc-dashboard-section">
            <h2>📊 Dashboard del Sistema</h2>
            <div class="qvc-stats-grid">
                <div class="qvc-stat-card">
                    <div class="qvc-stat-number"><?php echo esc_html($discovery_stats['total_hooks']); ?></div>
                    <div class="qvc-stat-label">Hooks Detectados</div>
                    <div class="qvc-stat-icon">🔗</div>
                </div>
                
                <div class="qvc-stat-card">
                    <div class="qvc-stat-number"><?php echo esc_html($hook_stats['active_hooks']); ?></div>
                    <div class="qvc-stat-label">Hooks Activos</div>
                    <div class="qvc-stat-icon">✅</div>
                </div>
                
                <div class="qvc-stat-card">
                    <div class="qvc-stat-number"><?php echo esc_html($hook_stats['configured_emails']); ?></div>
                    <div class="qvc-stat-label">Emails Configurados</div>
                    <div class="qvc-stat-icon">📧</div>
                </div>
                
                <div class="qvc-stat-card">
                    <div class="qvc-stat-number"><?php echo esc_html($discovery_stats['sources_count']); ?></div>
                    <div class="qvc-stat-label">Fuentes Detectadas</div>
                    <div class="qvc-stat-icon">📦</div>
                </div>
            </div>
        </div>
        
        <!-- Controles Principales -->
        <div class="qvc-main-controls">
            <div class="control-group">
                <h3>🔍 Discovery System</h3>
                <form method="post" style="display: inline-block;">
                    <input type="hidden" name="action" value="discover_hooks">
                    <?php wp_nonce_field('qvaclick_hook_action', 'qvaclick_nonce'); ?>
                    <button type="submit" class="button button-primary button-large">
                        <span class="dashicons dashicons-search"></span>
                        Ejecutar Discovery Automático
                    </button>
                </form>
                <p class="description">Escanea WordPress, Exertio Framework, WooCommerce y plugins activos</p>
            </div>
            
            <div class="control-group">
                <h3>⚙️ Gestión</h3>
                <a href="<?php echo admin_url('admin.php?page=qvc-hook-discovery#hook-emails'); ?>" class="button button-secondary">
                    <span class="dashicons dashicons-plus-alt"></span>
                    Crear Nuevo Email Hook
                </a>
                <a href="<?php echo admin_url('admin.php?page=qvaclick-email-analytics'); ?>" class="button button-secondary">
                    <span class="dashicons dashicons-chart-area"></span>
                    Ver Analytics Completo
                </a>
            </div>
        </div>
        
        <!-- Navegación por Tabs -->
        <div class="qvc-tabs-container">
            <nav class="nav-tab-wrapper">
                <a href="#hooks-overview" class="nav-tab nav-tab-active" data-tab="hooks-overview">
                    📋 Vista General
                </a>
                <a href="#hooks-by-source" class="nav-tab" data-tab="hooks-by-source">
                    📦 Por Fuente
                </a>
                <a href="#hooks-by-category" class="nav-tab" data-tab="hooks-by-category">
                    🏷️ Por Categoría
                </a>
                <a href="#discovery-analytics" class="nav-tab" data-tab="discovery-analytics">
                    📈 Analytics Discovery
                </a>
                <a href="#hook-emails" class="nav-tab" data-tab="hook-emails">
                    📧 Emails por Hook
                </a>
            </nav>
            
            <!-- Tab: Vista General -->
            <div id="hooks-overview" class="tab-content active">
                <div class="tab-header">
                    <h3>Lista Completa de Hooks Detectados</h3>
                    <div class="qvc-filters">
                        <select id="source-filter" class="filter-select">
                            <option value="">🔽 Todas las fuentes</option>
                            <option value="wordpress">WordPress Core</option>
                            <option value="exertio">Exertio Framework</option>
                            <option value="woocommerce">WooCommerce</option>
                            <option value="theme">Tema Activo</option>
                            <option value="custom">Personalizado</option>
                        </select>
                        
                        <select id="category-filter" class="filter-select">
                            <option value="">🏷️ Todas las categorías</option>
                            <option value="usuario">👤 Usuario</option>
                            <option value="proyecto">💼 Proyecto</option>
                            <option value="servicio">🛠️ Servicio</option>
                            <option value="woocommerce">🛒 WooCommerce</option>
                            <option value="contenido">📝 Contenido</option>
                            <option value="comentarios">💬 Comentarios</option>
                            <option value="sistema">⚙️ Sistema</option>
                        </select>
                        
                        <input type="text" id="search-hooks" class="filter-input" placeholder="🔍 Buscar hooks..." />
                        
                        <button id="clear-filters" class="button">Limpiar Filtros</button>
                    </div>
                </div>
                
                <div class="hooks-table-container">
                    <?php if (empty($hooks)): ?>
                        <div class="no-hooks-message">
                            <div class="icon">🔍</div>
                            <h3>No se han detectado hooks</h3>
                            <p>Ejecuta el discovery automático para escanear tu instalación de WordPress</p>
                            <form method="post">
                                <input type="hidden" name="action" value="discover_hooks">
                                <?php wp_nonce_field('qvaclick_hook_action', 'qvaclick_nonce'); ?>
                                <button type="submit" class="button button-primary">Comenzar Discovery</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <table class="wp-list-table widefat fixed striped hooks-table" id="hooks-table">
                            <thead>
                                <tr>
                                    <th class="column-hook-name">Hook Name</th>
                                    <th class="column-type">Tipo</th>
                                    <th class="column-source">Fuente</th>
                                    <th class="column-category">Categoría</th>
                                    <th class="column-description">Descripción</th>
                                    <th class="column-parameters">Parámetros</th>
                                    <th class="column-status">Estado</th>
                                    <th class="column-actions">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($hooks as $hook): ?>
                                    <tr data-source="<?php echo esc_attr($hook->source); ?>" 
                                        data-category="<?php echo esc_attr($hook->category); ?>"
                                        data-hook-name="<?php echo esc_attr($hook->hook_name); ?>">
                                        <td class="column-hook-name">
                                            <strong class="hook-name-text"><?php echo esc_html($hook->hook_name); ?></strong>
                                            <div class="hook-meta">
                                                <span class="hook-id">ID: <?php echo esc_html($hook->id); ?></span>
                                            </div>
                                        </td>
                                        <td class="column-type">
                                            <span class="hook-type-badge hook-type-<?php echo esc_attr($hook->hook_type); ?>">
                                                <?php echo $hook->hook_type === 'action' ? '⚡ Action' : '🔄 Filter'; ?>
                                            </span>
                                        </td>
                                        <td class="column-source">
                                            <span class="hook-source-badge hook-source-<?php echo esc_attr($hook->source); ?>">
                                                <?php 
                                                $source_icons = array(
                                                    'wordpress' => '🏠',
                                                    'exertio' => '🎨',
                                                    'woocommerce' => '🛒',
                                                    'theme' => '🎭',
                                                    'custom' => '🔧'
                                                );
                                                echo ($source_icons[$hook->source] ?? '📦') . ' ' . esc_html(ucfirst($hook->source));
                                                ?>
                                            </span>
                                        </td>
                                        <td class="column-category">
                                            <span class="hook-category-badge">
                                                <?php 
                                                $category_icons = array(
                                                    'usuario' => '👤',
                                                    'proyecto' => '💼',
                                                    'servicio' => '🛠️',
                                                    'woocommerce' => '🛒',
                                                    'contenido' => '📝',
                                                    'comentarios' => '💬',
                                                    'sistema' => '⚙️',
                                                    'custom' => '🔧'
                                                );
                                                echo ($category_icons[$hook->category] ?? '🏷️') . ' ' . esc_html(ucfirst($hook->category));
                                                ?>
                                            </span>
                                        </td>
                                        <td class="column-description">
                                            <div class="hook-description">
                                                <?php echo esc_html($hook->description ?: 'Sin descripción disponible'); ?>
                                            </div>
                                        </td>
                                        <td class="column-parameters">
                                            <?php 
                                            $parameters_json = isset($hook->parameters) ? $hook->parameters : '[]';
                                            $parameters = json_decode($parameters_json, true);
                                            if (is_array($parameters) && !empty($parameters)): 
                                            ?>
                                                <div class="hook-parameters">
                                                    <button class="button-link show-params" data-hook-id="<?php echo esc_attr($hook->id); ?>">
                                                        📋 Ver parámetros (<?php echo count($parameters); ?>)
                                                    </button>
                                                    <div class="params-popup" id="params-<?php echo esc_attr($hook->id); ?>" style="display: none;">
                                                        <ul>
                                                            <?php foreach ($parameters as $param => $desc): ?>
                                                                <li><code><?php echo esc_html($param); ?></code>: <?php echo esc_html($desc); ?></li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span class="no-params">Sin parámetros</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="column-status">
                                            <div class="status-container">
                                                <?php if ($hook->is_active): ?>
                                                    <span class="status-badge status-active">✅ Activo</span>
                                                <?php else: ?>
                                                    <span class="status-badge status-inactive">❌ Inactivo</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="column-actions">
                                            <div class="action-buttons">
                                                <form method="post" style="display: inline;">
                                                    <input type="hidden" name="action" value="toggle_hook">
                                                    <input type="hidden" name="hook_id" value="<?php echo esc_attr($hook->id); ?>">
                                                    <?php wp_nonce_field('qvaclick_hook_action', 'qvaclick_nonce'); ?>
                                                    <button type="submit" class="button button-small toggle-btn">
                                                        <?php echo $hook->is_active ? '🔴 Desactivar' : '🟢 Activar'; ?>
                                                    </button>
                                                </form>
                                                
                                                <a href="<?php echo admin_url('admin.php?page=qvaclick-email-hooks&hook=' . urlencode($hook->hook_name)); ?>" 
                                                   class="button button-primary button-small">
                                                    📧 Crear Email
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Tab: Por Fuente -->
            <div id="hooks-by-source" class="tab-content">
                <div class="tab-header">
                    <h3>Hooks Organizados por Fuente</h3>
                    <p class="description">Vista agrupada de hooks según su origen de detección</p>
                </div>
                
                <div class="source-groups">
                    <?php 
                    $hooks_by_source = array();
                    foreach ($hooks as $hook) {
                        $hooks_by_source[$hook->source][] = $hook;
                    }
                    
                    $source_info = array(
                        'wordpress' => array('name' => 'WordPress Core', 'icon' => '🏠', 'desc' => 'Hooks nativos del sistema WordPress'),
                        'exertio' => array('name' => 'Exertio Framework', 'icon' => '🎨', 'desc' => 'Hooks específicos del tema y framework Exertio'),
                        'woocommerce' => array('name' => 'WooCommerce', 'icon' => '🛒', 'desc' => 'Hooks del sistema de e-commerce WooCommerce'),
                        'theme' => array('name' => 'Tema Activo', 'icon' => '🎭', 'desc' => 'Hooks detectados en el tema actualmente en uso'),
                        'custom' => array('name' => 'Personalizado', 'icon' => '🔧', 'desc' => 'Hooks personalizados y de otros plugins')
                    );
                    
                    foreach ($hooks_by_source as $source => $source_hooks):
                        $info = $source_info[$source] ?? array('name' => ucfirst($source), 'icon' => '📦', 'desc' => 'Hooks de origen ' . $source);
                    ?>
                        <div class="source-group">
                            <div class="source-header">
                                <h4>
                                    <?php echo $info['icon']; ?> 
                                    <?php echo esc_html($info['name']); ?>
                                    <span class="count-badge"><?php echo count($source_hooks); ?> hooks</span>
                                </h4>
                                <p class="source-description"><?php echo esc_html($info['desc']); ?></p>
                            </div>
                            
                            <div class="hooks-grid">
                                <?php foreach (array_slice($source_hooks, 0, 12) as $hook): ?>
                                    <div class="hook-card">
                                        <div class="hook-card-header">
                                            <strong><?php echo esc_html($hook->hook_name); ?></strong>
                                            <span class="hook-type-small"><?php echo esc_html($hook->hook_type); ?></span>
                                        </div>
                                        <div class="hook-card-body">
                                            <div class="hook-category"><?php echo esc_html($hook->category); ?></div>
                                            <div class="hook-desc-short"><?php echo esc_html(substr($hook->description, 0, 100)); ?>...</div>
                                        </div>
                                        <div class="hook-card-footer">
                                            <span class="status-mini <?php echo $hook->is_active ? 'active' : 'inactive'; ?>">
                                                <?php echo $hook->is_active ? '✅' : '❌'; ?>
                                            </span>
                                            <a href="<?php echo admin_url('admin.php?page=qvaclick-email-hooks&hook=' . urlencode($hook->hook_name)); ?>" 
                                               class="create-email-mini">📧</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                
                                <?php if (count($source_hooks) > 12): ?>
                                    <div class="hook-card more-card">
                                        <div class="more-content">
                                            <div class="more-number">+<?php echo count($source_hooks) - 12; ?></div>
                                            <div class="more-text">hooks adicionales</div>
                                            <a href="#hooks-overview" class="view-all-link">Ver todos</a>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Resto de tabs pendientes... -->
            <div id="hooks-by-category" class="tab-content">
                <h3>🏷️ Hooks por Categoría</h3>
                <p><em>Contenido en desarrollo...</em></p>
            </div>
            
            <div id="discovery-analytics" class="tab-content">
                <h3>📈 Analytics del Discovery</h3>
                <p><em>Contenido en desarrollo...</em></p>
            </div>

            <!-- Tab: Emails por Hook -->
            <div id="hook-emails" class="tab-content">
                <div class="tab-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
                    <h3>Emails por Hook Configurados</h3>
                    <button type="button" class="button button-secondary" onclick="document.getElementById('qvc-create-hook-email').style.display = (document.getElementById('qvc-create-hook-email').style.display==='none'?'block':'none');">
                        <span class="dashicons dashicons-plus-alt"></span>
                        Crear nuevo
                    </button>
                </div>

                <div id="qvc-create-hook-email" style="display:none;margin:12px 0;padding:12px;border:1px solid #ccd0d4;border-radius:4px;background:#fff;">
                    <form method="post">
                        <?php wp_nonce_field('qvaclick_hook_action', 'qvaclick_nonce'); ?>
                        <input type="hidden" name="action" value="create_hook_email" />
                        <table class="form-table">
                            <tr>
                                <th><label for="qvc-new-hook">Hook</label></th>
                                <td>
                                    <input type="text" id="qvc-new-hook" name="hook_name" class="regular-text" placeholder="exertio_notification_filter o offer_received" required />
                                    <p class="description">Puedes usar el nombre de la acción (exertio_notification_filter) + condiciones, o directamente el n_type como hook (offer_received)</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="qvc-new-name">Nombre</label></th>
                                <td><input type="text" id="qvc-new-name" name="name" class="regular-text" required /></td>
                            </tr>
                            <tr>
                                <th>Destinatario</th>
                                <td>
                                    <select name="email_to_type">
                                        <option value="user">Usuario</option>
                                        <option value="admin">Admin</option>
                                        <option value="custom">Custom</option>
                                        <option value="multiple">Múltiples</option>
                                    </select>
                                    <input type="text" name="email_to_value" class="regular-text" placeholder="receiver | sender | user:123 | email@dominio" value="receiver" />
                                </td>
                            </tr>
                            <tr>
                                <th><label for="qvc-new-subject">Asunto</label></th>
                                <td><input type="text" id="qvc-new-subject" name="subject" class="regular-text" required /></td>
                            </tr>
                            <tr>
                                <th><label for="qvc-new-content">Contenido</label></th>
                                <td>
                                    <textarea id="qvc-new-content" name="content" rows="6" class="large-text" placeholder="Hola {receiver_name}..." required></textarea>
                                    <p class="description">Placeholders: {receiver_name}, {sender_name}, {project_title}, {project_link}, {site_name}</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="qvc-new-conditions">Condiciones (JSON)</label></th>
                                <td><input type="text" id="qvc-new-conditions" name="conditions" class="regular-text" placeholder='{"n_type":"offer_received","sender_type":"employer"}' /></td>
                            </tr>
                            <tr>
                                <th>Opciones</th>
                                <td>
                                    <label><input type="checkbox" name="use_base_template" checked /> Usar plantilla base</label>
                                    &nbsp;&nbsp;
                                    <label>Estado: 
                                        <select name="status">
                                            <option value="active">Activo</option>
                                            <option value="inactive">Inactivo</option>
                                            <option value="draft">Borrador</option>
                                        </select>
                                    </label>
                                </td>
                            </tr>
                        </table>
                        <p class="submit"><button type="submit" class="button button-primary">Crear Email</button></p>
                    </form>
                </div>
                <?php
                global $wpdb;
                $hook_emails_table = $wpdb->prefix . 'qvc_hook_emails';
                $emails = $wpdb->get_results("SELECT id, name, hook_name, subject, content, status, created_at, updated_at FROM {$hook_emails_table} ORDER BY id DESC");
                ?>
                <?php if (empty($emails)): ?>
                    <div class="no-hooks-message">
                        <div class="icon">📧</div>
                        <h3>No hay emails por hook configurados</h3>
                        <p>Crea uno nuevo para comenzar</p>
                    </div>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Hook</th>
                                <th>Asunto</th>
                                <th>Estado</th>
                                <th>Placeholders</th>
                                <th>Última Modificación</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($emails as $em): ?>
                            <tr>
                                <td><strong><?php echo esc_html($em->name); ?></strong></td>
                                <td><code><?php echo esc_html($em->hook_name); ?></code></td>
                                <td><?php echo esc_html(wp_trim_words($em->subject, 12)); ?></td>
                                <td>
                                    <?php 
                                    $s = strtolower($em->status);
                                    $label = ($s === 'active') ? 'Activo' : (($s === 'draft') ? 'Borrador' : 'Inactivo');
                                    ?>
                                    <span class="hook-type-badge" style="padding:2px 6px;border-radius:4px;background:<?php echo ($s==='active')?'#e7f7d3':(($s==='draft')?'#ffe8a1':'#fde2e2'); ?>;">
                                        <?php echo esc_html($label); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $text = (string)($em->subject . ' ' . ($em->content ?? ''));
                                    $ph = array();
                                    if (preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $text, $m1)) {
                                        foreach ($m1[1] as $k) { $ph[$k] = true; }
                                    }
                                    if (preg_match_all('/%([a-zA-Z0-9_]+)%/', $text, $m2)) {
                                        foreach ($m2[1] as $k) { $ph[$k] = true; }
                                    }
                                    $keys = array_keys($ph);
                                    if (!empty($keys)) {
                                        $shown = array_map('esc_html', array_slice($keys, 0, 3));
                                        echo implode(', ', $shown);
                                        if (count($keys) > 3) {
                                            echo ' <span class="qvc-more-placeholders">+' . (count($keys) - 3) . '</span>';
                                        }
                                    } else {
                                        echo '<span class="qvc-no-placeholders">' . esc_html__('Sin placeholders', 'qvaclick-email-manager') . '</span>';
                                    }
                                    ?>
                                </td>
                                <td><?php echo esc_html(date('d/m/Y H:i', strtotime($em->updated_at ?: $em->created_at ?: 'now'))); ?></td>
                                <td style="display:flex;gap:6px;align-items:center;">
                                    <a class="button button-small" href="<?php echo admin_url('admin.php?page=qvaclick-email-hooks&id=' . intval($em->id) . '&mark_seen=1'); ?>">Editar</a>
                                    <form method="post" action="<?php echo admin_url('admin.php?page=qvc-hook-discovery#hook-emails'); ?>" onsubmit="return confirm('¿Eliminar este email por hook? Esta acción no se puede deshacer.');" style="display:inline;">
                                        <?php wp_nonce_field('qvaclick_hook_action', 'qvaclick_nonce'); ?>
                                        <input type="hidden" name="action" value="delete_hook_email" />
                                        <input type="hidden" name="hook_email_id" value="<?php echo intval($em->id); ?>" />
                                        <button type="submit" class="button button-small button-link-delete" style="color:#b32d2e;">Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- CSS Avanzado -->
    <style>
    .qvc-hook-manager {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }
    
    .qvc-dashboard-section {
        background: #fff;
        border: 1px solid #c3c4c7;
        border-radius: 8px;
        padding: 25px;
        margin: 25px 0;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    
    .qvc-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }
    
    .qvc-stat-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 25px;
        border-radius: 12px;
        text-align: center;
        position: relative;
        overflow: hidden;
        transition: transform 0.2s ease;
    }
    
    .qvc-stat-card:hover {
        transform: translateY(-2px);
    }
    
    .qvc-stat-number {
        font-size: 3em;
        font-weight: bold;
        line-height: 1;
        margin-bottom: 8px;
    }
    
    .qvc-stat-label {
        font-size: 1.1em;
        opacity: 0.9;
        margin-bottom: 10px;
    }
    
    .qvc-stat-icon {
        position: absolute;
        top: 15px;
        right: 15px;
        font-size: 1.5em;
        opacity: 0.7;
    }
    
    .qvc-main-controls {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 25px;
        margin: 25px 0;
    }
    
    .control-group {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 8px;
        border-left: 4px solid #007cba;
    }
    
    .control-group h3 {
        margin: 0 0 10px 0;
        color: #2c3e50;
    }
    
    .qvc-tabs-container {
        background: #fff;
        border: 1px solid #c3c4c7;
        border-radius: 8px;
        margin: 25px 0;
    }
    
    .nav-tab-wrapper {
        border-bottom: 1px solid #c3c4c7;
        margin: 0;
        padding: 0 20px;
    }
    
    .tab-content {
        display: none;
        padding: 25px;
    }
    
    .tab-content.active {
        display: block;
    }
    
    .tab-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        padding-bottom: 15px;
        border-bottom: 2px solid #f1f1f1;
    }
    
    .qvc-filters {
        display: flex;
        gap: 15px;
        align-items: center;
    }
    
    .filter-select, .filter-input {
        padding: 8px 12px;
        border: 2px solid #ddd;
        border-radius: 6px;
        font-size: 14px;
    }
    
    .hooks-table-container {
        overflow-x: auto;
    }
    
    .hooks-table {
        min-width: 1200px;
    }
    
    .hooks-table th {
        background: #f8f9fa;
        font-weight: 600;
        padding: 12px 8px;
        border-bottom: 2px solid #dee2e6;
    }
    
    .hooks-table td {
        padding: 12px 8px;
        vertical-align: top;
    }
    
    .hook-name-text {
        font-family: 'Courier New', monospace;
        font-size: 13px;
        color: #2563eb;
    }
    
    .hook-meta {
        font-size: 11px;
        color: #6b7280;
        margin-top: 4px;
    }
    
    .hook-type-badge, .hook-source-badge, .hook-category-badge {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 500;
        white-space: nowrap;
    }
    
    .hook-type-action {
        background: #dcfce7;
        color: #166534;
    }
    
    .hook-type-filter {
        background: #fef3c7;
        color: #92400e;
    }
    
    .hook-source-wordpress {
        background: #dbeafe;
        color: #1e40af;
    }
    
    .hook-source-exertio {
        background: #f3e8ff;
        color: #7c3aed;
    }
    
    .hook-source-woocommerce {
        background: #fce7f3;
        color: #be185d;
    }
    
    .hook-description {
        max-width: 300px;
        line-height: 1.4;
        font-size: 13px;
    }
    
    .status-badge {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 500;
    }
    
    .status-active {
        background: #dcfce7;
        color: #166534;
    }
    
    .status-inactive {
        background: #fee2e2;
        color: #dc2626;
    }
    
    .action-buttons {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
    
    .no-hooks-message {
        text-align: center;
        padding: 60px 20px;
        color: #6b7280;
    }
    
    .no-hooks-message .icon {
        font-size: 4em;
        margin-bottom: 20px;
    }
    
    .source-groups {
        display: flex;
        flex-direction: column;
        gap: 30px;
    }
    
    .source-group {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 25px;
    }
    
    .source-header h4 {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0 0 10px 0;
        font-size: 1.3em;
    }
    
    .count-badge {
        background: #f3f4f6;
        color: #374151;
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 0.8em;
        font-weight: normal;
    }
    
    .hooks-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 15px;
        margin-top: 20px;
    }
    
    .hook-card {
        background: #f9fafb;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 15px;
        transition: all 0.2s ease;
    }
    
    .hook-card:hover {
        border-color: #3b82f6;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }
    
    .hook-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }
    
    .hook-card-header strong {
        font-family: 'Courier New', monospace;
        font-size: 13px;
        color: #1f2937;
    }
    
    .hook-type-small {
        background: #e5e7eb;
        color: #6b7280;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 10px;
    }
    
    .hook-card-body {
        margin-bottom: 12px;
    }
    
    .hook-category {
        font-size: 12px;
        color: #6b7280;
        margin-bottom: 6px;
    }
    
    .hook-desc-short {
        font-size: 12px;
        line-height: 1.4;
        color: #4b5563;
    }
    
    .hook-card-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .more-card {
        background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
        border-style: dashed;
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 120px;
    }
    
    .more-content {
        text-align: center;
        color: #6b7280;
    }
    
    .more-number {
        font-size: 2em;
        font-weight: bold;
        margin-bottom: 5px;
    }
    
    .params-popup {
        position: absolute;
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        z-index: 1000;
        max-width: 300px;
    }
    
    .show-params {
        color: #3b82f6;
        text-decoration: none;
        font-size: 12px;
    }
    
    .show-params:hover {
        text-decoration: underline;
    }
    </style>
    
    <!-- JavaScript Avanzado -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Sistema de tabs
        const tabs = document.querySelectorAll('.nav-tab');
        const tabContents = document.querySelectorAll('.tab-content');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                
                tabs.forEach(t => t.classList.remove('nav-tab-active'));
                tabContents.forEach(tc => tc.classList.remove('active'));
                
                this.classList.add('nav-tab-active');
                const targetTab = this.getAttribute('data-tab');
                document.getElementById(targetTab).classList.add('active');
            });
        });
        
        // Sistema de filtros
        const sourceFilter = document.getElementById('source-filter');
        const categoryFilter = document.getElementById('category-filter');
        const searchInput = document.getElementById('search-hooks');
        const clearFiltersBtn = document.getElementById('clear-filters');
        const hooksTable = document.getElementById('hooks-table');
        
        function filterHooks() {
            if (!hooksTable) return;
            
            const sourceValue = sourceFilter ? sourceFilter.value.toLowerCase() : '';
            const categoryValue = categoryFilter ? categoryFilter.value.toLowerCase() : '';
            const searchValue = searchInput ? searchInput.value.toLowerCase() : '';
            
            const rows = hooksTable.querySelectorAll('tbody tr');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const source = row.getAttribute('data-source') || '';
                const category = row.getAttribute('data-category') || '';
                const hookName = row.getAttribute('data-hook-name') || '';
                const text = row.textContent.toLowerCase();
                
                const matchesSource = !sourceValue || source === sourceValue;
                const matchesCategory = !categoryValue || category === categoryValue;
                const matchesSearch = !searchValue || 
                    text.includes(searchValue) || 
                    hookName.toLowerCase().includes(searchValue);
                
                if (matchesSource && matchesCategory && matchesSearch) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Mostrar contador de resultados
            updateResultsCounter(visibleCount, rows.length);
        }
        
        function updateResultsCounter(visible, total) {
            let counter = document.querySelector('.results-counter');
            if (!counter) {
                counter = document.createElement('div');
                counter.className = 'results-counter';
                const tabHeader = document.querySelector('#hooks-overview .tab-header');
                if (tabHeader) {
                    tabHeader.appendChild(counter);
                }
            }
            
            if (visible === total) {
                counter.textContent = `Mostrando ${total} hooks`;
            } else {
                counter.textContent = `Mostrando ${visible} de ${total} hooks`;
            }
        }
        
        // Event listeners para filtros
        if (sourceFilter) sourceFilter.addEventListener('change', filterHooks);
        if (categoryFilter) categoryFilter.addEventListener('change', filterHooks);
        if (searchInput) {
            searchInput.addEventListener('input', debounce(filterHooks, 300));
        }
        
        if (clearFiltersBtn) {
            clearFiltersBtn.addEventListener('click', function() {
                if (sourceFilter) sourceFilter.value = '';
                if (categoryFilter) categoryFilter.value = '';
                if (searchInput) searchInput.value = '';
                filterHooks();
            });
        }
        
        // Mostrar/ocultar parámetros de hooks
        document.querySelectorAll('.show-params').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const hookId = this.getAttribute('data-hook-id');
                const popup = document.getElementById('params-' + hookId);
                
                if (popup) {
                    const isVisible = popup.style.display !== 'none';
                    popup.style.display = isVisible ? 'none' : 'block';
                    
                    if (!isVisible) {
                        // Posicionar popup
                        const rect = this.getBoundingClientRect();
                        popup.style.position = 'fixed';
                        popup.style.top = (rect.bottom + 5) + 'px';
                        popup.style.left = rect.left + 'px';
                        
                        // Cerrar al hacer click fuera
                        setTimeout(() => {
                            document.addEventListener('click', function closePopup(e) {
                                if (!popup.contains(e.target) && e.target !== btn) {
                                    popup.style.display = 'none';
                                    document.removeEventListener('click', closePopup);
                                }
                            });
                        }, 100);
                    }
                }
            });
        });
        
        // Función de debounce para optimizar búsqueda
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
        
        // Inicializar contador de resultados
        filterHooks();
    });
    </script>
    <?php
}

/**
 * Funciones auxiliares para el Hook Manager Avanzado
 */

function qvaclick_simple_table_creation() {
    try {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'qvc_hook_registry';
        
        // PASO 1: Eliminar tabla si existe para empezar limpio
        $wpdb->query("DROP TABLE IF EXISTS `$table_name`");
        
        // PASO 2: SQL ultra-simple y probado (sin claves duplicadas)
        $sql = "CREATE TABLE `$table_name` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `hook_name` varchar(255) NOT NULL,
            `hook_type` varchar(20) DEFAULT 'action',
            `source` varchar(100) DEFAULT 'wordpress',
            `description` text,
            `parameters` longtext,
            `category` varchar(100) DEFAULT 'sistema',
            `is_active` tinyint(1) DEFAULT 1,
            `discovered_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        
        // PASO 3: Ejecutar directamente con wpdb->query
        $result = $wpdb->query($sql);
        
        // PASO 4: Verificar errores
        if ($wpdb->last_error) {
            throw new Exception('Error SQL: ' . $wpdb->last_error);
        }
        
        // PASO 5: Verificar si existe ahora
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if (!$table_exists) {
            throw new Exception('La tabla no se creó correctamente');
        }
        
        // PASO 6: Insertar hooks básicos para prueba
        $test_hooks = array(
            array('wp_loaded', 'action', 'wordpress', 'WordPress completamente cargado', '[]', 'sistema'),
            array('init', 'action', 'wordpress', 'Inicialización de WordPress', '[]', 'sistema'),
            array('wp_head', 'action', 'wordpress', 'Cabecera de la página', '[]', 'contenido'),
            array('wp_footer', 'action', 'wordpress', 'Pie de página', '[]', 'contenido'),
            array('user_register', 'action', 'wordpress', 'Nuevo usuario registrado', '{"user_id":"ID del usuario registrado"}', 'usuario')
        );
        
        foreach ($test_hooks as $hook) {
            $wpdb->insert($table_name, array(
                'hook_name' => $hook[0],
                'hook_type' => $hook[1],
                'source' => $hook[2],
                'description' => $hook[3],
                'parameters' => $hook[4],
                'category' => $hook[5]
            ));
        }
        
        $hooks_inserted = $wpdb->get_var("SELECT COUNT(*) FROM `$table_name`");
        
        return array(
            'success' => true,
            'message' => "✅ ÉXITO: Tabla creada correctamente con $hooks_inserted hooks de prueba. Prefijo detectado: {$wpdb->prefix}"
        );
        
    } catch (Exception $e) {
        return array(
            'success' => false,
            'message' => $e->getMessage()
        );
    }
}

function qvaclick_update_table_structure() {
    try {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'qvc_hook_registry';
        
        // PASO 1: Verificar si la tabla existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if (!$table_exists) {
            return array(
                'success' => false,
                'message' => 'La tabla no existe. Usa "Recrear Tabla Simple" primero.'
            );
        }
        
        // PASO 2: Verificar si la columna parameters ya existe
        $columns = $wpdb->get_results("SHOW COLUMNS FROM `$table_name`");
        $has_parameters = false;
        
        foreach ($columns as $column) {
            if ($column->Field === 'parameters') {
                $has_parameters = true;
                break;
            }
        }
        
        if ($has_parameters) {
            return array(
                'success' => true,
                'message' => 'La columna parameters ya existe. Tabla actualizada.'
            );
        }
        
        // PASO 3: Agregar la columna parameters
        $sql = "ALTER TABLE `$table_name` ADD COLUMN `parameters` longtext AFTER `description`";
        $result = $wpdb->query($sql);
        
        if ($wpdb->last_error) {
            throw new Exception('Error SQL: ' . $wpdb->last_error);
        }
        
        // PASO 4: Verificar que se agregó correctamente
        $columns_after = $wpdb->get_results("SHOW COLUMNS FROM `$table_name`");
        $parameters_added = false;
        
        foreach ($columns_after as $column) {
            if ($column->Field === 'parameters') {
                $parameters_added = true;
                break;
            }
        }
        
        if (!$parameters_added) {
            throw new Exception('No se pudo agregar la columna parameters');
        }
        
        // PASO 5: Actualizar registros existentes para evitar NULL
        $wpdb->query("UPDATE `$table_name` SET `parameters` = '[]' WHERE `parameters` IS NULL");
        
        $total_hooks = $wpdb->get_var("SELECT COUNT(*) FROM `$table_name`");
        
        return array(
            'success' => true,
            'message' => "✅ Columna 'parameters' agregada exitosamente. Total hooks: $total_hooks"
        );
        
    } catch (Exception $e) {
        return array(
            'success' => false,
            'message' => $e->getMessage()
        );
    }
}

function qvaclick_force_create_correct_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'qvc_hook_registry';
    
    // Forzar eliminación y recreación
    $wpdb->query("DROP TABLE IF EXISTS `$table_name`");
    
    // Crear tabla con estructura COMPLETA
    $sql = "CREATE TABLE `$table_name` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `hook_name` varchar(255) NOT NULL,
        `hook_type` varchar(20) DEFAULT 'action',
        `source` varchar(100) DEFAULT 'wordpress',
        `description` text,
        `parameters` longtext,
        `category` varchar(100) DEFAULT 'sistema',
        `is_active` tinyint(1) DEFAULT 1,
        `discovered_at` datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    $result = $wpdb->query($sql);
    
    // Mensaje de debug en el log
    error_log("QvaClick DEBUG: Tabla recreada forzadamente. SQL ejecutado: $sql");
    error_log("QvaClick DEBUG: Resultado: " . ($result !== false ? 'SUCCESS' : 'FAILED'));
    if ($wpdb->last_error) {
        error_log("QvaClick DEBUG: Error SQL: " . $wpdb->last_error);
    }
    
    // Insertar algunos hooks de prueba
    $test_hooks = array(
        array('wp_loaded', 'action', 'wordpress', 'WordPress completamente cargado', '[]', 'sistema'),
        array('init', 'action', 'wordpress', 'Inicialización de WordPress', '[]', 'sistema'),
        array('user_register', 'action', 'wordpress', 'Nuevo usuario registrado', '{"user_id":"ID del usuario"}', 'usuario')
    );
    
    foreach ($test_hooks as $hook) {
        $wpdb->insert($table_name, array(
            'hook_name' => $hook[0],
            'hook_type' => $hook[1],
            'source' => $hook[2],
            'description' => $hook[3],
            'parameters' => $hook[4],
            'category' => $hook[5]
        ));
    }
    
    $hooks_count = $wpdb->get_var("SELECT COUNT(*) FROM `$table_name`");
    error_log("QvaClick DEBUG: Tabla creada con $hooks_count hooks de prueba");
    
    return true;
}

function qvaclick_analyze_configured_emails() {
    try {
        global $wpdb;
        
        $hook_emails_table = $wpdb->prefix . 'qvc_hook_emails';
        $hook_registry_table = $wpdb->prefix . 'qvc_hook_registry';
        
        // PASO 1: Obtener todos los emails configurados
        $configured_emails = $wpdb->get_results("\r
            SELECT hook_name, subject, content, status, created_at \r
            FROM $hook_emails_table \r
            ORDER BY hook_name\r
        ");
        
        if (empty($configured_emails)) {
            return array(
                'success' => false,
                'message' => 'No se encontraron emails configurados en la base de datos.'
            );
        }
        
        $total_emails = count($configured_emails);
        $hooks_analyzed = array();
        $exertio_hooks = array();
        $non_exertio_hooks = array();
        $missing_hooks = array();
        
        // PASO 2: Analizar cada email para extraer información del hook
        foreach ($configured_emails as $email) {
            $hook_name = $email->hook_name;
            
            // Verificar si el hook existe en el registro
            $existing_hook = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $hook_registry_table WHERE hook_name = %s",
                $hook_name
            ));
            
            // Detectar fuente del hook basado en su nombre
            $detected_source = qvaclick_detect_hook_source_from_name($hook_name);
            $detected_description = qvaclick_generate_hook_description($hook_name, $email->subject);
            
            $hook_info = array(
                'hook_name' => $hook_name,
                'email_subject' => $email->subject,
                'email_status' => $email->status,
                'detected_source' => $detected_source,
                'detected_description' => $detected_description,
                'exists_in_registry' => !empty($existing_hook),
                'registry_source' => $existing_hook ? $existing_hook->source : null,
                'needs_update' => false
            );
            
            // Determinar si necesita actualización
            if ($existing_hook) {
                if ($existing_hook->source !== $detected_source || 
                    empty($existing_hook->description) || 
                    $existing_hook->description === 'Sin descripción disponible') {
                    $hook_info['needs_update'] = true;
                }
            } else {
                $missing_hooks[] = $hook_name;
            }
            
            // Categorizar por fuente
            if ($detected_source === 'exertio') {
                $exertio_hooks[] = $hook_info;
            } else {
                $non_exertio_hooks[] = $hook_info;
            }
            
            $hooks_analyzed[] = $hook_info;
        }
        
        // PASO 3: Actualizar hooks en el registro
        $updated_count = 0;
        $inserted_count = 0;
        
        foreach ($hooks_analyzed as $hook_info) {
            if ($hook_info['exists_in_registry'] && $hook_info['needs_update']) {
                // Actualizar hook existente
                $wpdb->update(
                    $hook_registry_table,
                    array(
                        'source' => $hook_info['detected_source'],
                        'description' => $hook_info['detected_description'],
                        'category' => qvaclick_categorize_hook_by_name($hook_info['hook_name'])
                    ),
                    array('hook_name' => $hook_info['hook_name'])
                );
                $updated_count++;
            } elseif (!$hook_info['exists_in_registry']) {
                // Insertar hook faltante
                $wpdb->insert(
                    $hook_registry_table,
                    array(
                        'hook_name' => $hook_info['hook_name'],
                        'hook_type' => 'action', // Por defecto
                        'source' => $hook_info['detected_source'],
                        'description' => $hook_info['detected_description'],
                        'parameters' => '[]',
                        'category' => qvaclick_categorize_hook_by_name($hook_info['hook_name']),
                        'is_active' => 1
                    )
                );
                $inserted_count++;
            }
        }
        
        // PASO 4: Log detallado
        error_log("QvaClick EMAIL ANALYSIS COMPLETE:");
        error_log("- Total emails analyzed: $total_emails");
        error_log("- Exertio hooks found: " . count($exertio_hooks));
        error_log("- Non-Exertio hooks found: " . count($non_exertio_hooks));
        error_log("- Missing hooks inserted: $inserted_count");
        error_log("- Existing hooks updated: $updated_count");
        
        return array(
            'success' => true,
            'message' => "✅ ANÁLISIS COMPLETADO: $total_emails emails analizados. Exertio: " . count($exertio_hooks) . ", Otros: " . count($non_exertio_hooks) . ". Insertados: $inserted_count, Actualizados: $updated_count",
            'data' => array(
                'total_emails' => $total_emails,
                'exertio_hooks' => count($exertio_hooks),
                'non_exertio_hooks' => count($non_exertio_hooks),
                'inserted' => $inserted_count,
                'updated' => $updated_count,
                'missing_hooks' => $missing_hooks
            )
        );
        
    } catch (Exception $e) {
        return array(
            'success' => false,
            'message' => 'Error en análisis: ' . $e->getMessage()
        );
    }
}

// Funciones auxiliares para el análisis
function qvaclick_detect_hook_source_from_name($hook_name) {
    if (strpos($hook_name, 'exertio_') === 0 || strpos($hook_name, 'fl_') === 0) {
        return 'exertio';
    }
    if (strpos($hook_name, 'woocommerce_') === 0 || strpos($hook_name, 'wc_') === 0) {
        return 'woocommerce';
    }
    if (strpos($hook_name, 'wp_') === 0 || strpos($hook_name, 'admin_') === 0 || strpos($hook_name, 'user_') === 0) {
        return 'wordpress';
    }
    return 'custom';
}

function qvaclick_generate_hook_description($hook_name, $email_subject) {
    // Generar descripción basada en el nombre del hook y el asunto del email
    $base_description = '';
    
    if (strpos($hook_name, 'exertio_') === 0) {
        if (strpos($hook_name, 'project') !== false) {
            if (strpos($hook_name, 'created') !== false) $base_description = 'Se ejecuta cuando se crea un nuevo proyecto';
            elseif (strpos($hook_name, 'completed') !== false) $base_description = 'Se ejecuta cuando se completa un proyecto';
            elseif (strpos($hook_name, 'started') !== false) $base_description = 'Se ejecuta cuando se inicia un proyecto';
            elseif (strpos($hook_name, 'cancelled') !== false) $base_description = 'Se ejecuta cuando se cancela un proyecto';
            else $base_description = 'Relacionado con proyectos en Exertio';
        } elseif (strpos($hook_name, 'service') !== false) {
            if (strpos($hook_name, 'purchased') !== false) $base_description = 'Se ejecuta cuando se compra un servicio';
            elseif (strpos($hook_name, 'created') !== false) $base_description = 'Se ejecuta cuando se crea un servicio';
            else $base_description = 'Relacionado con servicios en Exertio';
        } elseif (strpos($hook_name, 'notification') !== false) {
            $base_description = 'Se ejecuta para enviar notificaciones en Exertio';
        } elseif (strpos($hook_name, 'proposal') !== false) {
            $base_description = 'Relacionado con propuestas de proyecto';
        } elseif (strpos($hook_name, 'payment') !== false || strpos($hook_name, 'payout') !== false) {
            $base_description = 'Relacionado con pagos en Exertio';
        } else {
            $base_description = 'Hook personalizado de Exertio Framework';
        }
    } else {
        $base_description = "Se ejecuta en el contexto: " . str_replace('_', ' ', $hook_name);
    }
    
    // Agregar contexto del email si es útil
    if (!empty($email_subject) && $email_subject !== $hook_name) {
        $base_description .= " (Email: $email_subject)";
    }
    
    return $base_description;
}

function qvaclick_categorize_hook_by_name($hook_name) {
    if (strpos($hook_name, 'project') !== false) return 'proyecto';
    if (strpos($hook_name, 'service') !== false) return 'servicio';
    if (strpos($hook_name, 'user') !== false || strpos($hook_name, 'register') !== false) return 'usuario';
    if (strpos($hook_name, 'payment') !== false || strpos($hook_name, 'payout') !== false) return 'pago';
    if (strpos($hook_name, 'notification') !== false) return 'notificacion';
    if (strpos($hook_name, 'woocommerce') !== false) return 'woocommerce';
    if (strpos($hook_name, 'comment') !== false) return 'comentarios';
    if (strpos($hook_name, 'post') !== false || strpos($hook_name, 'page') !== false) return 'contenido';
    return 'custom';
}

function qvaclick_fix_database_and_discovery() {
    try {
        global $wpdb;
        
        // DIAGNÓSTICO COMPLETO
        $diagnosis = array();
        
        // 1. Verificar conexión a BD
        $diagnosis['db_connection'] = $wpdb->check_connection();
        $diagnosis['db_name'] = DB_NAME;
        $diagnosis['table_prefix'] = $wpdb->prefix;
        
        // 2. Verificar permisos de usuario actual
        $user_info = $wpdb->get_results("SHOW GRANTS FOR CURRENT_USER()");
        $diagnosis['db_grants'] = $user_info;
        
        // 3. Verificar si dbDelta está disponible
        if (!function_exists('dbDelta')) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        }
        $diagnosis['dbdelta_available'] = function_exists('dbDelta');
        
        // 4. Verificar espacio en disco
        $diagnosis['abspath_writable'] = is_writable(ABSPATH);
        
        // 5. Intentar crear tabla con SQL directo primero
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'qvc_hook_registry';
        
        // SQL simplificado para test
        $sql_test = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            hook_name varchar(255) NOT NULL,
            hook_type enum('action','filter') NOT NULL DEFAULT 'action',
            source varchar(100) NOT NULL DEFAULT 'wordpress',
            description text,
            category varchar(100) DEFAULT 'sistema',
            is_active tinyint(1) DEFAULT 1,
            discovered_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY hook_name (hook_name)
        ) $charset_collate;";
        
        // Intentar con query directo
        $direct_result = $wpdb->query($sql_test);
        $diagnosis['direct_sql_result'] = $direct_result;
        $diagnosis['direct_sql_error'] = $wpdb->last_error;
        
        // Verificar si se creó
        $table_exists_after = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        $diagnosis['table_created'] = $table_exists_after;
        
        if ($table_exists_after) {
            // Auto-discovery temporalmente deshabilitado
            $discovery_result = array(
                'hooks_discovered' => 0,
                'message' => 'Hook discovery temporalmente deshabilitado'
            );
            
            return array(
                'success' => true,
                'message' => 'ÉXITO: Tabla creada. Auto-discovery deshabilitado temporalmente.', 
                'diagnosis' => $diagnosis,
                'data' => $discovery_result
            );
        } else {
            // Si no se creó, devolver diagnóstico completo
            return array(
                'success' => false,
                'message' => 'DIAGNÓSTICO COMPLETO - Ver detalles',
                'diagnosis' => $diagnosis
            );
        }
        
    } catch (Exception $e) {
        return array(
            'success' => false,
            'message' => 'Error durante diagnóstico: ' . $e->getMessage(),
            'diagnosis' => isset($diagnosis) ? $diagnosis : array()
        );
    }
}

function qvaclick_check_hook_table_exists() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'qvc_hook_registry';
    $query = $wpdb->prepare("SHOW TABLES LIKE %s", $table_name);
    return $wpdb->get_var($query) === $table_name;
}

function qvaclick_run_hooks_discovery() {
    try {
        // Auto-discovery temporalmente deshabilitado
        return array(
            'success' => false,
            'message' => 'Hook Discovery temporalmente deshabilitado. Use discovery manual.'
        );
    } catch (Exception $e) {
        return array(
            'success' => false,
            'message' => 'Error durante discovery: ' . $e->getMessage()
        );
    }
}

function qvaclick_get_all_discovered_hooks() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'qvc_hook_registry';
    
    return $wpdb->get_results("
        SELECT * FROM $table_name 
        ORDER BY source, category, hook_name
    ");
}

function qvaclick_get_discovery_statistics() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'qvc_hook_registry';
    
    $stats = array(
        'total_hooks' => 0,
        'sources_count' => 0,
        'by_source' => array(),
        'by_category' => array(),
        'by_type' => array()
    );
    
    // Total de hooks
    $stats['total_hooks'] = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    
    // Por fuente
    $sources = $wpdb->get_results("
        SELECT source, COUNT(*) as count 
        FROM $table_name 
        GROUP BY source 
        ORDER BY count DESC
    ");
    
    $stats['sources_count'] = count($sources);
    $stats['by_source'] = $sources;
    
    // Por categoría
    $stats['by_category'] = $wpdb->get_results("
        SELECT category, COUNT(*) as count 
        FROM $table_name 
        GROUP BY category 
        ORDER BY count DESC
    ");
    
    // Por tipo
    $stats['by_type'] = $wpdb->get_results("
        SELECT hook_type, COUNT(*) as count 
        FROM $table_name 
        GROUP BY hook_type 
        ORDER BY count DESC
    ");
    
    return $stats;
}

function qvaclick_get_hook_usage_stats() {
    global $wpdb;
    
    $hook_registry_table = $wpdb->prefix . 'qvc_hook_registry';
    $hook_emails_table = $wpdb->prefix . 'qvc_hook_emails';
    
    $stats = array(
        'active_hooks' => 0,
        'configured_emails' => 0,
        'total_emails_sent' => 0
    );
    
    // Hooks activos
    $stats['active_hooks'] = $wpdb->get_var("
        SELECT COUNT(*) FROM $hook_registry_table 
        WHERE is_active = 1
    ");
    
    // Emails configurados
    $stats['configured_emails'] = $wpdb->get_var("
        SELECT COUNT(*) FROM $hook_emails_table 
        WHERE status = 'active'
    ");
    
    // Total de emails enviados (desde logs)
    $email_logs_table = $wpdb->prefix . 'qvc_email_logs';
    $stats['total_emails_sent'] = $wpdb->get_var("
        SELECT COUNT(*) FROM $email_logs_table 
        WHERE status = 'sent'
    ");
    
    return $stats;
}

function qvaclick_toggle_hook_status($hook_id) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'qvc_hook_registry';
    
    // Obtener estado actual
    $current_status = $wpdb->get_var($wpdb->prepare(
        "SELECT is_active FROM $table_name WHERE id = %d",
        $hook_id
    ));
    
    if ($current_status === null) {
        return false;
    }
    
    // Cambiar estado
    $new_status = $current_status ? 0 : 1;
    
    $result = $wpdb->update(
        $table_name,
        array('is_active' => $new_status),
        array('id' => $hook_id),
        array('%d'),
        array('%d')
    );
    
    return $result !== false;
}
?>
