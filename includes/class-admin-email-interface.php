<?php
/**
 * Admin Email UI - class wrapper restored
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Class declaration: methods in this file are part of this class
class QvaClick_Admin_Email_Interface {
    // singleton instance
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Initialize hooks as originally implemented
        $this->init_hooks();
    }

    /**
     * Inicializar hooks y acciones AJAX
     */
    private function init_hooks() {
        // Hooks AJAX para bandeja general
        add_action('wp_ajax_qvc_view_general_email', array($this, 'ajax_view_general_email'));
        add_action('wp_ajax_qvc_update_general_email_status', array($this, 'ajax_update_general_email_status'));
        add_action('wp_ajax_qvc_convert_email_to_ticket', array($this, 'ajax_convert_email_to_ticket'));
        
        // Nuevos hooks AJAX para seguridad
        add_action('wp_ajax_qvc_delete_ticket', array($this, 'ajax_delete_ticket'));
        add_action('wp_ajax_qvc_clean_resolved_tickets', array($this, 'ajax_clean_resolved_tickets'));
        add_action('wp_ajax_qvc_quarantine_action', array($this, 'ajax_quarantine_action'));
        add_action('wp_ajax_qvc_approve_quarantine', array($this, 'ajax_approve_quarantine'));
        add_action('wp_ajax_qvc_reject_quarantine', array($this, 'ajax_reject_quarantine'));
        
        // Enqueue scripts y estilos
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        // AJAX endpoint para precarga/filtrado de destinatarios en el compositor
        add_action('wp_ajax_qvc_load_recipient_preview', array($this, 'ajax_load_recipient_preview'));
    }

    /**
     * Enqueue scripts y estilos para el admin
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'qvc-admin-email') !== false) {
            wp_enqueue_script('qvc-admin-email-js', plugin_dir_url(__FILE__) . '../assets/admin-script.js', array('jquery'), '1.0.0', true);
            wp_enqueue_style('qvc-admin-email-css', plugin_dir_url(__FILE__) . '../assets/admin-style.css', array(), '1.0.0');
            
            wp_localize_script('qvc-admin-email-js', 'qvc_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('qvc_admin_nonce')
            ));
        }
    }

    /**
     * AJAX: Cargar vista previa / listado de destinatarios según filtros
     */
    public function ajax_load_recipient_preview() {
        // Verificar nonce enviado desde el script (campo 'nonce')
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'qvc_email_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
        }

        $type = isset($_POST['recipient_type']) ? sanitize_text_field($_POST['recipient_type']) : 'all'; 
        $filter = isset($_POST['recipient_filter']) ? sanitize_text_field($_POST['recipient_filter']) : ''; 
        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1; 
        $per_page = isset($_POST['per_page']) ? max(1, intval($_POST['per_page'])) : 20; 

        if (!class_exists('QvaClick_Admin_Email_Manager')) {
            wp_send_json_error(array('message' => 'Manager class missing'));
        }

        $manager = QvaClick_Admin_Email_Manager::get_instance();
        // If filter is plain string and we have pagination params, wrap in JSON to pass them
        $filter_payload = $filter;
        if (!empty($filter) || $type === 'all' || $type === 'admins' || $type === 'freelancers' || $type === 'employers') {
            // try to parse existing JSON
            $parsed = null;
            if (!empty($filter) && ($filter[0] === '{' || $filter[0] === '[')) {
                $decoded = json_decode($filter, true);
                if (json_last_error() === JSON_ERROR_NONE) $parsed = $decoded;
            }
            if (!is_array($parsed)) $parsed = array();
            $parsed['page'] = $page;
            $parsed['per_page'] = $per_page;
            $filter_payload = wp_json_encode($parsed);
        }

        $recipients = $manager->get_mass_email_recipients($type === 'specific_user' ? 'specific_user' : $type, $filter_payload);

        // Construir HTML simple con tabla de resultados
    $html = '';
    $html .= '<div class="qvc-recipient-panel">';
    $html .= '<div style="margin-bottom:8px;"><strong>' . intval(count($recipients)) . ' ' . __('destinatarios (página ' . $page . ')', 'qvaclick-email-manager') . '</strong></div>';
        $html .= '<table class="wp-list-table widefat" id="qvc-recipient-table"><thead><tr><th style="width:24px;"></th><th>' . __('ID', 'qvaclick-email-manager') . '</th><th>' . __('Nombre', 'qvaclick-email-manager') . '</th><th>' . __('Email', 'qvaclick-email-manager') . '</th><th>' . __('Ticket', 'qvaclick-email-manager') . '</th></tr></thead><tbody>';

        foreach ($recipients as $r) {
            $uid = esc_attr($r['id'] ?? '');
            $uemail = esc_attr($r['email'] ?? '');
            $uname = esc_html($r['name'] ?? $uemail);

            $html .= '<tr data-user-id="' . $uid . '" data-user-email="' . $uemail . '" data-user-name="' . esc_attr($r['name'] ?? $uemail) . '">';
            $html .= '<td><input type="checkbox" class="qvc-select-user" value="' . $uid . '" data-email="' . $uemail . '" data-name="' . esc_attr($r['name'] ?? $uemail) . '"></td>';
            $html .= '<td>' . $uid . '</td>';
            $html .= '<td>' . $uname . '</td>';
            $html .= '<td>' . $uemail . '</td>';
            $html .= '<td><input type="checkbox" class="qvc-create-ticket-user" title="' . __('Crear ticket para este usuario al enviar', 'qvaclick-email-manager') . '"></td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';

        // Basic pagination controls (Prev / Next) - only when per_page > 0
        $controls = '';
        if ($per_page > 0) {
            $controls = '<div style="display:flex; justify-content:space-between; margin-top:8px;">';
            $prev_disabled = $page <= 1 ? 'disabled' : '';
            // Disable next when returned results are less than per_page (last page)
            $next_disabled = (count($recipients) < $per_page) ? 'disabled' : '';
            $controls .= '<button class="button qvc-recipient-prev" data-page="' . ($page - 1) . '" ' . $prev_disabled . '>' . __('Prev', 'qvaclick-email-manager') . '</button>';
            $controls .= '<button class="button qvc-recipient-next" data-page="' . ($page + 1) . '" ' . $next_disabled . '>' . __('Next', 'qvaclick-email-manager') . '</button>';
            $controls .= '</div>';
        }

    wp_send_json_success(array('preview' => $html . $controls, 'count' => count($recipients), 'recipients' => $recipients, 'page' => $page, 'per_page' => $per_page));
    }
    
    /**
     * Renderiza la página principal de Admin Email
     */
    public static function render_admin_email_page() {
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'outbox';
        $ticket_id = isset($_GET['ticket_id']) ? sanitize_text_field($_GET['ticket_id']) : '';
        
        ?>
        <div class="wrap qvc-admin-email-wrap">
            <h1 class="wp-heading-inline">
                <?php _e('Admin Email', 'qvaclick-email-manager'); ?>
            </h1>
            
            <!-- Navegación de pestañas (orden personalizado) -->
            <nav class="nav-tab-wrapper wp-clearfix">
                <!-- 1) Enviar Mail (antes Email Masivo) -->
                <a href="<?php echo admin_url('admin.php?page=qvc-admin-email&action=mass_email'); ?>" 
                   class="nav-tab <?php echo $action === 'mass_email' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Enviar Mail', 'qvaclick-email-manager'); ?>
                </a>

                <!-- 2) Tickets de Soporte -->
                <a href="<?php echo admin_url('admin.php?page=qvc-admin-email&action=inbox'); ?>" 
                   class="nav-tab <?php echo $action === 'inbox' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Tikets de Soporte', 'qvaclick-email-manager'); ?>
                    <?php 
                    $unread_count = self::get_unread_tickets_count();
                    if ($unread_count > 0): 
                    ?>
                        <span class="qvc-unread-badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </a>

                <!-- 3) Bandeja de Salida -->
                <a href="<?php echo admin_url('admin.php?page=qvc-admin-email&action=outbox'); ?>" 
                   class="nav-tab <?php echo $action === 'outbox' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Bandeja de Salida', 'qvaclick-email-manager'); ?>
                </a>

                <!-- 4) Bandeja de entrada (general) -->
                <a href="<?php echo admin_url('admin.php?page=qvc-admin-email&action=general_inbox'); ?>" 
                   class="nav-tab <?php echo $action === 'general_inbox' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Bandeja de entrada', 'qvaclick-email-manager'); ?>
                    <?php 
                    $general_unread = self::get_general_inbox_unread_count();
                    if ($general_unread > 0): 
                    ?>
                        <span class="qvc-unread-badge"><?php echo $general_unread; ?></span>
                    <?php endif; ?>
                </a>

                <!-- 5) Campañas -->
                <a href="<?php echo admin_url('admin.php?page=qvc-admin-email&action=campaigns'); ?>" 
                   class="nav-tab <?php echo $action === 'campaigns' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Campañas', 'qvaclick-email-manager'); ?>
                </a>

                <!-- 6) Cuarentena -->
                <a href="<?php echo admin_url('admin.php?page=qvc-admin-email&action=quarantine'); ?>" 
                   class="nav-tab <?php echo $action === 'quarantine' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Cuarentena', 'qvaclick-email-manager'); ?>
                    <?php 
                    $quarantine_count = self::get_quarantine_count();
                    if ($quarantine_count > 0): 
                    ?>
                        <span class="qvc-quarantine-badge"><?php echo $quarantine_count; ?></span>
                    <?php endif; ?>
                </a>

                <!-- 7) Configuración -->
                <a href="<?php echo admin_url('admin.php?page=qvc-admin-email&action=settings'); ?>" 
                   class="nav-tab <?php echo $action === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Configuración', 'qvaclick-email-manager'); ?>
                </a>
            </nav>
            
            <div class="qvc-admin-email-content">
                <?php
                switch ($action) {
                    case 'outbox':
                        self::render_outbox();
                        break;
                    case 'general_inbox':
                        self::render_general_inbox();
                        break;
                    case 'inbox':
                        if (!empty($ticket_id)) {
                            self::render_ticket_detail($ticket_id);
                        } else {
                            self::render_inbox();
                        }
                        break;
                    case 'quarantine':
                        self::render_quarantine();
                        break;
                    case 'mass_email':
                        self::render_mass_email_composer();
                        break;
                    case 'campaigns':
                        self::render_campaigns_list();
                        break;
                    case 'settings':
                        self::render_settings();
                        break;
                    default:
                        self::render_inbox();
                        break;
                }
                ?>
            </div>
        </div>
        
        <style>
        .qvc-admin-email-wrap {
            margin: 20px 0;
        }
        .qvc-unread-badge {
            background: #d63638;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 11px;
            margin-left: 5px;
        }
        .qvc-ticket-row {
            display: flex;
            align-items: center;
            padding: 12px;
            border-bottom: 1px solid #ddd;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .qvc-ticket-row:hover {
            background-color: #f8f9fa;
        }
        .qvc-ticket-row.unread {
            background-color: #fff3cd;
            font-weight: bold;
        }
        .qvc-ticket-priority {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            margin-right: 12px;
        }
        .qvc-ticket-priority.high { background-color: #dc3545; }
        .qvc-ticket-priority.normal { background-color: #28a745; }
        .qvc-ticket-priority.low { background-color: #6c757d; }
        .qvc-ticket-priority.urgent { background-color: #fd7e14; }
        .qvc-ticket-info {
            flex: 1;
        }
        .qvc-ticket-subject {
            font-size: 14px;
            margin: 0 0 4px 0;
        }
        .qvc-ticket-meta {
            font-size: 12px;
            color: #666;
        }
        .qvc-ticket-actions {
            margin-left: auto;
        }
        .qvc-status-badge {
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            text-transform: uppercase;
        }
        .qvc-status-badge.open { background-color: #cfe2ff; color: #0f3660; }
        .qvc-status-badge.in_progress { background-color: #fff3cd; color: #664d03; }
        .qvc-status-badge.resolved { background-color: #d1e7dd; color: #0f5132; }
        .qvc-status-badge.closed { background-color: #f8d7da; color: #721c24; }
        
        /* NUEVO: Indicadores de respuesta */
        .qvc-response-indicator {
            font-size: 10px;
            font-weight: bold;
            margin-left: 8px;
            padding: 2px 6px;
            border-radius: 3px;
            text-transform: lowercase;
        }
        .qvc-response-indicator.user-response {
            background-color: #f8d7da;
            color: #721c24;
        }
        .qvc-response-indicator.admin-response {
            background-color: #d1e7dd;
            color: #0f5132;
        }
        .qvc-message-thread {
            max-width: 800px;
            margin: 20px 0;
        }
        .qvc-message {
            margin: 20px 0;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #007cba;
        }
        .qvc-message.admin-reply {
            background-color: #f0f6fc;
            border-left-color: #28a745;
            margin-left: 40px;
        }
        .qvc-message.user-message {
            background-color: #fff;
            border: 1px solid #ddd;
        }
        .qvc-message-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 12px;
            color: #666;
        }
        .qvc-message-content {
            line-height: 1.6;
        }
        .qvc-mass-email-form {
            width: 100%;
            max-width: none;
            background: white;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .qvc-form-row {
            margin-bottom: 20px;
        }
        .qvc-form-row label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        .qvc-form-row input,
        .qvc-form-row select,
        .qvc-form-row textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .qvc-recipient-preview {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
            font-size: 12px;
        }
        .qvc-campaigns-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .qvc-campaigns-table th,
        .qvc-campaigns-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .qvc-campaigns-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        </style>
        <?php
    }
    
    /**
     * Renderiza la bandeja de entrada de tickets
     */
    private static function render_inbox() {
        $admin_email = QvaClick_Admin_Email_Manager::get_instance();
        
        // Obtener filtros
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $priority = isset($_GET['priority']) ? sanitize_text_field($_GET['priority']) : '';
        $user_type = isset($_GET['user_type']) ? sanitize_text_field($_GET['user_type']) : '';
        $page = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
        
        $args = array(
            'status' => $status,
            'priority' => $priority,
            'user_type' => $user_type,
            'page' => $page,
            'per_page' => 20
        );
        
        $result = $admin_email->get_tickets($args);
        $tickets = $result['tickets'];
        $total_pages = $result['pages'];
        ?>
        
        <div class="qvc-inbox-header">
            <h2><?php _e('Tickets de Soporte', 'qvaclick-email-manager'); ?></h2>
            
            <!-- Filtros -->
            <div class="qvc-filters">
                <form method="get" action="">
                    <input type="hidden" name="page" value="qvc-admin-email">
                    <input type="hidden" name="action" value="inbox">
                    
                    <select name="status">
                        <option value=""><?php _e('Todos los estados', 'qvaclick-email-manager'); ?></option>
                        <option value="new" <?php selected($status, 'new'); ?>><?php _e('Nuevo', 'qvaclick-email-manager'); ?></option>
                        <option value="open" <?php selected($status, 'open'); ?>><?php _e('Abierto', 'qvaclick-email-manager'); ?></option>
                        <option value="in_progress" <?php selected($status, 'in_progress'); ?>><?php _e('En progreso', 'qvaclick-email-manager'); ?></option>
                        <option value="on_hold" <?php selected($status, 'on_hold'); ?>><?php _e('En espera', 'qvaclick-email-manager'); ?></option>
                        <option value="resolved" <?php selected($status, 'resolved'); ?>><?php _e('Resuelto', 'qvaclick-email-manager'); ?></option>
                        <option value="closed" <?php selected($status, 'closed'); ?>><?php _e('Cerrado', 'qvaclick-email-manager'); ?></option>
                    </select>
                    
                    <select name="priority">
                        <option value=""><?php _e('Todas las prioridades', 'qvaclick-email-manager'); ?></option>
                        <option value="low" <?php selected($priority, 'low'); ?>><?php _e('Baja', 'qvaclick-email-manager'); ?></option>
                        <option value="normal" <?php selected($priority, 'normal'); ?>><?php _e('Normal', 'qvaclick-email-manager'); ?></option>
                        <option value="high" <?php selected($priority, 'high'); ?>><?php _e('Alta', 'qvaclick-email-manager'); ?></option>
                        <option value="urgent" <?php selected($priority, 'urgent'); ?>><?php _e('Urgente', 'qvaclick-email-manager'); ?></option>
                    </select>
                    
                    <select name="user_type">
                        <option value=""><?php _e('Todos los tipos', 'qvaclick-email-manager'); ?></option>
                        <option value="freelancer" <?php selected($user_type, 'freelancer'); ?>><?php _e('Freelancer', 'qvaclick-email-manager'); ?></option>
                        <option value="employer" <?php selected($user_type, 'employer'); ?>><?php _e('Employer', 'qvaclick-email-manager'); ?></option>
                        <option value="admin" <?php selected($user_type, 'admin'); ?>><?php _e('Admin', 'qvaclick-email-manager'); ?></option>
                        <option value="guest" <?php selected($user_type, 'guest'); ?>><?php _e('Invitado', 'qvaclick-email-manager'); ?></option>
                    </select>
                    
                    <button type="submit" class="button"><?php _e('Filtrar', 'qvaclick-email-manager'); ?></button>
                    <a href="<?php echo admin_url('admin.php?page=qvc-admin-email&action=inbox'); ?>" class="button"><?php _e('Limpiar Filtros', 'qvaclick-email-manager'); ?></a>
                    
                    <!-- Nueva funcionalidad de limpieza de tickets resueltos -->
                    <button type="button" id="qvc-clean-resolved" class="button button-secondary" style="margin-left: 10px;">
                        <?php _e('Limpiar Tickets Resueltos', 'qvaclick-email-manager'); ?>
                    </button>
                </form>
            </div>
        </div>
        
        <div class="qvc-tickets-list">
            <?php if (empty($tickets)): ?>
                <div class="qvc-no-tickets">
                    <p><?php _e('No hay tickets que mostrar.', 'qvaclick-email-manager'); ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($tickets as $ticket): ?>
                    <div class="qvc-ticket-row <?php echo esc_attr($ticket->status); ?>" 
                         onclick="location.href='<?php echo admin_url('admin.php?page=qvc-admin-email&action=inbox&ticket_id=' . $ticket->ticket_id); ?>'">
                        
                        <div class="qvc-ticket-priority <?php echo esc_attr($ticket->priority); ?>" 
                             title="<?php echo esc_attr(ucfirst($ticket->priority)); ?>"></div>
                        
                        <div class="qvc-ticket-info">
                            <h4 class="qvc-ticket-subject">
                                <?php echo esc_html($ticket->subject); ?>
                                <?php 
                                // NUEVO: Indicador visual de tipo de respuesta
                                if (isset($ticket->last_response_type)) {
                                    if ($ticket->last_response_type === 'user_response') {
                                        echo '<span class="qvc-response-indicator user-response">&lt;user response&gt;</span>';
                                    } elseif ($ticket->last_response_type === 'admin_response') {
                                        echo '<span class="qvc-response-indicator admin-response">&lt;admin response&gt;</span>';
                                    }
                                }
                                ?>
                            </h4>
                            <div class="qvc-ticket-meta">
                                <strong><?php echo esc_html($ticket->user_name); ?></strong> 
                                (<?php echo esc_html($ticket->user_email); ?>) - 
                                <?php echo esc_html(ucfirst($ticket->user_type)); ?> - 
                                <?php echo esc_html($ticket->ticket_id); ?> - 
                                <?php 
                                $ticket_time = strtotime($ticket->created_at . ' GMT');
                                $current_time = current_time('timestamp', true);
                                echo esc_html(human_time_diff($ticket_time, $current_time)); 
                                ?> <?php _e('ago', 'qvaclick-email-manager'); ?>
                            </div>
                        </div>
                        
                        <div class="qvc-ticket-actions">
                            <span class="qvc-status-badge <?php echo esc_attr($ticket->status); ?>">
                                <?php echo esc_html(self::get_status_label($ticket->status)); ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <!-- Paginación -->
                <?php if ($total_pages > 1): ?>
                    <div class="qvc-pagination">
                        <?php
                        $pagination_args = array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => __('&laquo;'),
                            'next_text' => __('&raquo;'),
                            'total' => $total_pages,
                            'current' => $page
                        );
                        echo paginate_links($pagination_args);
                        ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Renderiza el detalle de un ticket
     */
    private static function render_ticket_detail($ticket_id) {
        global $wpdb;
        
        $admin_email = QvaClick_Admin_Email_Manager::get_instance();
        
        // Obtener ticket
        $table = $wpdb->prefix . 'qvc_support_tickets';
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE ticket_id = %s",
            $ticket_id
        ));
        
        if (!$ticket) {
            echo '<div class="notice notice-error"><p>' . __('Ticket no encontrado.', 'qvaclick-email-manager') . '</p></div>';
            return;
        }
        
        // Marcar como leído al abrir el ticket (cambiar de 'new' a 'open')
        if ($ticket->status === 'new') {
            $wpdb->update(
                $table,
                array('status' => 'open'),
                array('ticket_id' => $ticket_id)
            );
            $ticket->status = 'open';
        }
        
        // Obtener mensajes
        $messages = $admin_email->get_ticket_messages($ticket_id);
        
        // Procesar cambio de estado sin respuesta
        if (isset($_POST['change_status_only']) && wp_verify_nonce($_POST['qvc_nonce'], 'qvc_change_status')) {
            $new_status = sanitize_text_field($_POST['ticket_status']);
            
            // Actualizar status del ticket
            $wpdb->update(
                $table,
                array('status' => $new_status),
                array('ticket_id' => $ticket_id)
            );
            
            $ticket->status = $new_status;
            echo '<div class="notice notice-success"><p>' . __('Estado del ticket actualizado.', 'qvaclick-email-manager') . '</p></div>';
        }
        
        // Procesar respuesta de admin si se envió
        if (isset($_POST['admin_reply']) && wp_verify_nonce($_POST['qvc_nonce'], 'qvc_admin_reply')) {
            $reply_content = sanitize_textarea_field($_POST['reply_content']);
            $new_status = sanitize_text_field($_POST['ticket_status']);
            
            // Validar que para estados resuelto/cerrado se requiera respuesta
            if (($new_status === 'resolved' || $new_status === 'closed') && empty($reply_content)) {
                echo '<div class="notice notice-error"><p>' . __('Se requiere una respuesta para marcar como resuelto o cerrado.', 'qvaclick-email-manager') . '</p></div>';
            } else if (!empty($reply_content)) {
                // Agregar respuesta
                $admin_email->add_ticket_message($ticket_id, array(
                    'user_id' => get_current_user_id(),
                    'user_email' => wp_get_current_user()->user_email,
                    'user_name' => wp_get_current_user()->display_name,
                    'user_type' => 'admin',
                    'message' => $reply_content,
                    'is_admin_reply' => 1
                ));
                
                // Actualizar status del ticket y marcar como admin response
                $wpdb->update(
                    $table,
                    array(
                        'status' => $new_status,
                        'last_response_type' => 'admin_response',
                        'updated_at' => current_time('mysql')
                    ),
                    array('ticket_id' => $ticket_id)
                );
                
                // Enviar email al usuario
                $subject = sprintf(__('Re: %s [Ticket #%s]', 'qvaclick-email-manager'), $ticket->subject, $ticket_id);
                $content = $reply_content;
                
                if (class_exists('QvaClick_Base_Template_Manager')) {
                    $content = QvaClick_Base_Template_Manager::apply_to_html($content);
                }
                
                wp_mail($ticket->user_email, $subject, $content, array('Content-Type: text/html; charset=UTF-8'));
                
                // Recargar mensajes
                $messages = $admin_email->get_ticket_messages($ticket_id);
                $ticket->status = $new_status;
                
                echo '<div class="notice notice-success"><p>' . __('Respuesta enviada correctamente.', 'qvaclick-email-manager') . '</p></div>';
            }
        }
        ?>
        
        <div class="qvc-ticket-detail">
            <div class="qvc-ticket-header">
                <h2><?php echo esc_html($ticket->subject); ?></h2>
                <div class="qvc-ticket-info">
                    <p><strong><?php _e('Ticket ID:', 'qvaclick-email-manager'); ?></strong> <?php echo esc_html($ticket->ticket_id); ?></p>
                    <p><strong><?php _e('Usuario:', 'qvaclick-email-manager'); ?></strong> <?php echo esc_html($ticket->user_name); ?> (<?php echo esc_html($ticket->user_email); ?>)</p>
                    <p><strong><?php _e('Tipo:', 'qvaclick-email-manager'); ?></strong> <?php echo esc_html(ucfirst($ticket->user_type)); ?></p>
                    <p><strong><?php _e('Estado:', 'qvaclick-email-manager'); ?></strong> 
                        <span class="qvc-status-badge <?php echo esc_attr($ticket->status); ?>">
                            <?php echo esc_html(ucfirst(str_replace('_', ' ', $ticket->status))); ?>
                        </span>
                    </p>
                    <p><strong><?php _e('Prioridad:', 'qvaclick-email-manager'); ?></strong> 
                        <span class="qvc-priority-badge <?php echo esc_attr($ticket->priority); ?>">
                            <?php echo esc_html(ucfirst($ticket->priority)); ?>
                        </span>
                    </p>
                    <p><strong><?php _e('Creado:', 'qvaclick-email-manager'); ?></strong> <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($ticket->created_at))); ?></p>
                </div>
                
                <div class="qvc-ticket-actions">
                    <a href="<?php echo admin_url('admin.php?page=qvc-admin-email&action=inbox'); ?>" class="button">
                        ← <?php _e('Volver a la bandeja', 'qvaclick-email-manager'); ?>
                    </a>
                    
                    <!-- Botón de eliminar ticket con confirmación -->
                    <button type="button" id="qvc-delete-ticket" class="button button-link-delete" 
                            data-ticket-id="<?php echo esc_attr($ticket->ticket_id); ?>"
                            style="margin-left: 10px; color: #d63638;">
                        🗑️ <?php _e('Eliminar Ticket', 'qvaclick-email-manager'); ?>
                    </button>
                </div>
            </div>
            
            <!-- Hilo de mensajes mejorado -->
            <div class="qvc-message-thread">
                <h3><?php _e('Historial de Conversación', 'qvaclick-email-manager'); ?></h3>
                
                <?php if (empty($messages)): ?>
                    <div class="qvc-no-messages">
                        <p><?php _e('No hay mensajes en este ticket.', 'qvaclick-email-manager'); ?></p>
                    </div>
                <?php else: ?>
                    <?php 
                    $message_count = count($messages);
                    foreach ($messages as $index => $message): 
                        $is_latest = ($index === $message_count - 1);
                        $message_type = $message->is_admin_reply ? 'admin-reply' : 'user-message';
                        $message_source = $message->is_admin_reply ? 'admin' : 'email';
                        
                        // Detectar si el mensaje vino por email (no admin)
                        if (!$message->is_admin_reply && $message->user_type === 'guest') {
                            $message_source = 'email-reply';
                        }
                    ?>
                        <div class="qvc-message <?php echo $message_type; ?> <?php echo $is_latest ? 'latest-message' : ''; ?>" data-source="<?php echo $message_source; ?>">
                            <div class="qvc-message-header">
                                <div class="qvc-message-author-info">
                                    <span class="qvc-message-author">
                                        <?php echo esc_html($message->user_name); ?>
                                        <span class="qvc-message-email">(<?php echo esc_html($message->user_email); ?>)</span>
                                    </span>
                                    
                                    <div class="qvc-message-badges">
                                        <?php if ($message->is_admin_reply): ?>
                                            <span class="qvc-admin-badge"><?php _e('ADMIN', 'qvaclick-email-manager'); ?></span>
                                        <?php else: ?>
                                            <?php if ($message->user_type === 'guest' && strpos($message->created_at, $ticket->created_at) !== 0): ?>
                                                <span class="qvc-email-badge" title="<?php _e('Respuesta por email', 'qvaclick-email-manager'); ?>">
                                                    📧 <?php _e('Email', 'qvaclick-email-manager'); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="qvc-original-badge"><?php _e('Original', 'qvaclick-email-manager'); ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <?php if ($is_latest): ?>
                                            <span class="qvc-latest-badge"><?php _e('Más reciente', 'qvaclick-email-manager'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="qvc-message-meta">
                                    <span class="qvc-message-date">
                                        <?php 
                                        $message_time = strtotime($message->created_at . ' GMT');
                                        $current_time = current_time('timestamp', true);
                                        
                                        // Mostrar fecha completa y tiempo relativo
                                        echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $message_time));
                                        echo ' <span class="relative-time">(' . esc_html(human_time_diff($message_time, $current_time)) . ' ago)</span>';
                                        ?>
                                    </span>
                                    
                                    <span class="qvc-message-number">#<?php echo ($index + 1); ?></span>
                                </div>
                            </div>
                            
                            <div class="qvc-message-content">
                                <?php echo wp_kses_post(wpautop($message->message)); ?>
                            </div>
                            
                            <!-- Información adicional para mensajes por email -->
                            <?php if (!$message->is_admin_reply && $message->user_type === 'guest' && strpos($message->created_at, $ticket->created_at) !== 0): ?>
                                <div class="qvc-message-footer">
                                    <small class="qvc-email-info">
                                        <em><?php _e('Este mensaje fue recibido por email en respuesta al ticket.', 'qvaclick-email-manager'); ?></em>
                                    </small>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    
                    <!-- Resumen de la conversación -->
                    <div class="qvc-conversation-summary">
                        <p><strong><?php _e('Resumen:', 'qvaclick-email-manager'); ?></strong> 
                        <?php 
                        $admin_messages = array_filter($messages, function($m) { return $m->is_admin_reply; });
                        $user_messages = array_filter($messages, function($m) { return !$m->is_admin_reply; });
                        
                        printf(
                            __('%d mensajes total: %d del usuario, %d respuestas del administrador', 'qvaclick-email-manager'),
                            count($messages),
                            count($user_messages),
                            count($admin_messages)
                        );
                        ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Formulario de respuesta -->
            <div class="qvc-reply-form">
                <!-- Cambio rápido de estado sin respuesta -->
                <div class="qvc-quick-status-change">
                    <h3><?php _e('Cambio rápido de estado', 'qvaclick-email-manager'); ?></h3>
                    <form method="post" action="" style="display: inline-block; margin-bottom: 20px;">
                        <?php wp_nonce_field('qvc_change_status', 'qvc_nonce'); ?>
                        
                        <select name="ticket_status" style="margin-right: 10px;">
                            <option value="open" <?php selected($ticket->status, 'open'); ?>><?php _e('Abierto', 'qvaclick-email-manager'); ?></option>
                            <option value="in_progress" <?php selected($ticket->status, 'in_progress'); ?>><?php _e('En progreso', 'qvaclick-email-manager'); ?></option>
                            <option value="on_hold" <?php selected($ticket->status, 'on_hold'); ?>><?php _e('En espera', 'qvaclick-email-manager'); ?></option>
                        </select>
                        
                        <button type="submit" name="change_status_only" class="button">
                            <?php _e('Cambiar Estado', 'qvaclick-email-manager'); ?>
                        </button>
                        
                        <small style="display: block; margin-top: 5px; color: #666;">
                            <?php _e('Para marcar como resuelto o cerrado, debe enviar una respuesta.', 'qvaclick-email-manager'); ?>
                        </small>
                    </form>
                </div>
                
                <!-- Formulario de respuesta con estado -->
                <h3><?php _e('Responder al ticket', 'qvaclick-email-manager'); ?></h3>
                
                <form method="post" action="">
                    <?php wp_nonce_field('qvc_admin_reply', 'qvc_nonce'); ?>
                    
                    <div class="qvc-form-row">
                        <label for="reply_content"><?php _e('Respuesta:', 'qvaclick-email-manager'); ?></label>
                        <textarea name="reply_content" id="reply_content" rows="8" placeholder="<?php _e('Escriba su respuesta aquí. Esta será enviada por email al usuario.', 'qvaclick-email-manager'); ?>"></textarea>
                        <small style="color: #666;">
                            <?php _e('* Respuesta obligatoria para marcar como resuelto o cerrado', 'qvaclick-email-manager'); ?>
                        </small>
                    </div>
                    
                    <div class="qvc-form-row">
                        <label for="ticket_status"><?php _e('Cambiar estado a:', 'qvaclick-email-manager'); ?></label>
                        <select name="ticket_status" id="ticket_status">
                            <option value="open" <?php selected($ticket->status, 'open'); ?>><?php _e('Abierto', 'qvaclick-email-manager'); ?></option>
                            <option value="in_progress" <?php selected($ticket->status, 'in_progress'); ?>><?php _e('En progreso', 'qvaclick-email-manager'); ?></option>
                            <option value="on_hold" <?php selected($ticket->status, 'on_hold'); ?>><?php _e('En espera', 'qvaclick-email-manager'); ?></option>
                            <option value="resolved" <?php selected($ticket->status, 'resolved'); ?>><?php _e('Resuelto', 'qvaclick-email-manager'); ?></option>
                            <option value="closed" <?php selected($ticket->status, 'closed'); ?>><?php _e('Cerrado', 'qvaclick-email-manager'); ?></option>
                        </select>
                    </div>
                    
                    <div class="qvc-form-actions">
                        <button type="submit" name="admin_reply" class="button button-primary">
                            <?php _e('Enviar Respuesta', 'qvaclick-email-manager'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
    
    /**
     * Renderiza el compositor de email masivo
     */
    public static function render_mass_email_composer() {
        // Procesar envío del formulario
        if (isset($_POST['send_mass_email']) && wp_verify_nonce($_POST['qvc_nonce'], 'qvc_mass_email')) {
            error_log('QVC Email Manager: Procesando formulario de email masivo');
            
            // Construir recipient_filter y determinar el tipo efectivo según selección explícita
            $recipient_filter = '';
            $effective_type = isset($_POST['recipient_type']) ? sanitize_text_field($_POST['recipient_type']) : 'all';

            $ids = array();
            $create_ids = array();
            if (!empty($_POST['selected_user_ids'])) {
                $ids = array_filter(array_map('intval', explode(',', $_POST['selected_user_ids'])));
            }
            if (!empty($_POST['create_ticket_user_ids'])) {
                $create_ids = array_filter(array_map('intval', explode(',', $_POST['create_ticket_user_ids'])));
            }

            if (!empty($ids)) {
                // Si hay usuarios seleccionados, forzar envío solo a esos usuarios
                $recipient_filter = wp_json_encode(array(
                    'ids' => array_values($ids),
                    'create_ticket_user_ids' => array_values($create_ids)
                ));
                $effective_type = 'specific_user';
            } else {
                // Mantener filtro de búsqueda libre si aplica (p.ej., para specific_user)
                $recipient_filter = isset($_POST['recipient_filter']) ? sanitize_text_field($_POST['recipient_filter']) : '';
            }

            $send_now_flag = !empty($_POST['send_now']);
            $data = array(
                'campaign_name' => sanitize_text_field($_POST['campaign_name']),
                'subject' => sanitize_text_field($_POST['subject']),
                'content' => wp_kses_post($_POST['content']),
                'recipient_type' => $effective_type,
                'recipient_filter' => $recipient_filter,
                'status' => $send_now_flag ? 'sending' : 'draft'
            );
            
            error_log('QVC Email Manager: Datos de campaña - ' . print_r($data, true));
            
            $admin_email = QvaClick_Admin_Email_Manager::get_instance();
            $campaign_id = $admin_email->create_mass_email($data);
            
            error_log('QVC Email Manager: Campaign ID creado: ' . $campaign_id);
            
            if ($campaign_id) {
                if ($send_now_flag) {
                    error_log('QVC Email Manager: Iniciando envío de campaña ID: ' . $campaign_id);
                    $result = $admin_email->send_mass_email($campaign_id);
                    error_log('QVC Email Manager: Resultado del envío - ' . print_r($result, true));
                    
                    if ($result && is_array($result)) {
                        echo '<div class="notice notice-success"><p>' . 
                             sprintf(__('Campaña enviada. %d emails enviados, %d fallaron.', 'qvaclick-email-manager'), 
                                    $result['sent'], $result['failed']) . 
                             '</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>' . 
                             __('Error al enviar la campaña. Revisa los logs para más detalles.', 'qvaclick-email-manager') . 
                             '</p></div>';
                    }
                } else {
                    echo '<div class="notice notice-success"><p>' . __('Borrador guardado correctamente.', 'qvaclick-email-manager') . '</p></div>';
                }
            } else {
                echo '<div class="notice notice-error"><p>' . __('Error al crear la campaña.', 'qvaclick-email-manager') . '</p></div>';
            }
        }
        ?>
        
        <div class="qvc-mass-email-composer">
            <h2><?php _e('Enviar Email Masivo', 'qvaclick-email-manager'); ?></h2>

            <form method="post" action="" class="qvc-mass-email-form" style="display:flex; gap:24px; align-items:flex-start; width:100%; box-sizing:border-box;">
                <?php wp_nonce_field('qvc_mass_email', 'qvc_nonce'); ?>

                <!-- Left column: editor and main fields -->
                <div style="flex:1; min-width:0;">
                    <div class="qvc-form-row">
                        <label for="campaign_name"><?php _e('Nombre de Campaña:', 'qvaclick-email-manager'); ?></label>
                        <input type="text" name="campaign_name" id="campaign_name" required>
                    </div>



                    <div class="qvc-form-row">
                        <label for="subject"><?php _e('Asunto:', 'qvaclick-email-manager'); ?></label>
                        <input type="text" name="subject" id="subject" required>
                    </div>

                    <div class="qvc-form-row">
                        <label for="content"><?php _e('Contenido:', 'qvaclick-email-manager'); ?></label>
                        <?php 
                        wp_editor('', 'content', array(
                            'textarea_name' => 'content',
                            'media_buttons' => true,
                            'textarea_rows' => 15,
                            'teeny' => false
                        )); 
                        ?>
                        <p class="description">
                            <?php _e('Variables disponibles: {{user_name}}, {{user_email}}, {{site_name}}, {{site_url}}', 'qvaclick-email-manager'); ?>
                        </p>
                    </div>

                    <!-- Hidden inputs for selection (kept here so they submit with the form) -->
                    <input type="hidden" name="selected_user_ids" id="selected_user_ids" value="">
                    <input type="hidden" name="create_ticket_user_ids" id="create_ticket_user_ids" value="">

                    <div class="qvc-form-actions" style="margin-top:12px;">
                        <button type="submit" name="send_mass_email" class="button button-secondary">
                            <?php _e('Guardar Borrador', 'qvaclick-email-manager'); ?>
                        </button>
                        <button type="submit" name="send_mass_email" value="send_now" class="button button-primary" 
                                onclick="return qvcConfirmSendNow(this.form);">
                            <?php _e('Enviar Ahora', 'qvaclick-email-manager'); ?>
                        </button>
                        <input type="hidden" name="send_now" value="">
                    </div>
                </div>

                <!-- Right column: recipient selector and preview -->
                <aside style="flex:1 1 360px; min-width:320px; max-width:46%; box-sizing:border-box;">
                    <div style="background:#fff; border:1px solid #ddd; padding:16px; border-radius:4px; height:100%; box-sizing:border-box;">
                        <h3><?php _e('Seleccionar destinatarios', 'qvaclick-email-manager'); ?></h3>

                        <div class="qvc-form-row">
                            <label for="recipient_type"><?php _e('Filtro rápido:', 'qvaclick-email-manager'); ?></label>
                            <select name="recipient_type" id="recipient_type" onchange="toggleRecipientFilter()">
                                <option value="all"><?php _e('Todos los usuarios', 'qvaclick-email-manager'); ?></option>
                                <option value="freelancers"><?php _e('Solo Freelancers', 'qvaclick-email-manager'); ?></option>
                                <option value="employers"><?php _e('Solo Employers', 'qvaclick-email-manager'); ?></option>
                                <option value="admins"><?php _e('Solo Administradores', 'qvaclick-email-manager'); ?></option>
                                <option value="specific_user"><?php _e('Buscar usuarios', 'qvaclick-email-manager'); ?></option>

                            </select>
                            <div style="margin-top:8px; display:flex; gap:6px; flex-wrap:wrap;">
                                <button type="button" class="button" onclick="setRecipientType('all')"><?php _e('Todos', 'qvaclick-email-manager'); ?></button>
                                <button type="button" class="button" onclick="setRecipientType('freelancers')"><?php _e('Freelancers', 'qvaclick-email-manager'); ?></button>
                                <button type="button" class="button" onclick="setRecipientType('employers')"><?php _e('Employers', 'qvaclick-email-manager'); ?></button>
                                <button type="button" class="button" onclick="setRecipientType('admins')"><?php _e('Admins', 'qvaclick-email-manager'); ?></button>
                            </div>
                            <div id="segment_selection_notice" class="notice notice-info" style="display:none;margin-top:8px;">
                                <p style="margin:8px 0;">&nbsp;</p>
                            </div>
                        </div>

                        <div class="qvc-form-row" id="recipient_filter_row" style="display: none;">
                            <label for="recipient_filter"><?php _e('Búsqueda de usuarios:', 'qvaclick-email-manager'); ?></label>
                            <input type="text" name="recipient_filter" id="recipient_filter" placeholder="<?php _e('Escribe nombre, email o ID para buscar...', 'qvaclick-email-manager'); ?>" oninput="loadRecipientPreview()">
                        </div>

                        <div id="user_search_results" style="display: none; margin-top: 10px;">
                            <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px;">
                                <div id="search_results_list"></div>
                            </div>
                        </div>

                        <div id="selected_users" style="display: none; margin-top: 10px;">
                            <label><?php _e('Usuarios seleccionados:', 'qvaclick-email-manager'); ?></label>
                            <div id="selected_users_list" style="border: 1px solid #ddd; border-radius: 4px; padding: 10px; min-height: 50px; background: #f9f9f9;">
                                <em><?php _e('Ningún usuario seleccionado', 'qvaclick-email-manager'); ?></em>
                            </div>
                        </div>

                        <div id="recipient_preview" class="qvc-recipient-preview" style="margin-top:12px;">
                            <!-- AJAX preview will be injected here -->
                        </div>
                    </div>
                </aside>

            </form>
        </div>
        
        <script>
        function toggleRecipientFilter() {
            const type = document.getElementById('recipient_type').value;
            const filterRow = document.getElementById('recipient_filter_row');
            const preview = document.getElementById('recipient_preview');
            if (type === 'specific_user') {
                filterRow.style.display = 'block';
            } else {
                filterRow.style.display = 'none';
            }
            updateSegmentNotice();
            loadRecipientPreview();
        }
        
        // Helper para botones de selección rápida
        var qvc_send_to_all_segment = false;
        var qvc_active_segment = null;
        var qvc_selected_users_backup = null;
        var qvc_create_ticket_users_backup = null;
        function setRecipientType(type) {
            var sel = document.getElementById('recipient_type');
            if (!sel) return;
            // Toggle behavior: if clicking same active segment, revert to specific_user (selection only)
            if (qvc_active_segment === type) {
                qvc_active_segment = null;
                qvc_send_to_all_segment = false;
                sel.value = 'specific_user';
                // Restore previous selection if available
                if (qvc_selected_users_backup) { qvc_selected_users = qvc_selected_users_backup; }
                if (qvc_create_ticket_users_backup) { qvc_create_ticket_users = qvc_create_ticket_users_backup; }
                qvc_selected_users_backup = null;
                qvc_create_ticket_users_backup = null;
                // Show filter for specific_user
                if (typeof jQuery !== 'undefined') { jQuery(sel).trigger('change'); } else { toggleRecipientFilter(); }
                updateSelectedUsers();
                // Explicitly hide banner
                (function(){ var n = document.getElementById('segment_selection_notice'); if (n) { var p = n.querySelector('p'); if (p) p.textContent=''; n.style.display='none'; } })();
                updateSegmentNotice();
                loadRecipientPreview(1);
                return;
            }

            // Activate new segment: store current selection and clear it
            qvc_active_segment = type;
            qvc_send_to_all_segment = true;
            qvc_selected_users_backup = Object.assign({}, qvc_selected_users || {});
            qvc_create_ticket_users_backup = Object.assign({}, qvc_create_ticket_users || {});
            qvc_selected_users = {};
            qvc_create_ticket_users = {};
            updateSelectedUsers();

            sel.value = type;
            // Limpiar filtros de búsqueda cuando corresponde
            if (type !== 'specific_user') {
                var rf = document.getElementById('recipient_filter');
                if (rf) rf.value = '';
            }
            // Disparar cambios de UI y refrescar preview
            if (typeof jQuery !== 'undefined') { jQuery(sel).trigger('change'); } else { toggleRecipientFilter(); }
            updateSegmentNotice();
            loadRecipientPreview(1);
        }

        function getSegmentLabel(type) {
            switch (type) {
                case 'all': return '<?php echo esc_js(__('usuarios', 'qvaclick-email-manager')); ?>';
                case 'freelancers': return '<?php echo esc_js(__('Freelancers', 'qvaclick-email-manager')); ?>';
                case 'employers': return '<?php echo esc_js(__('Employers', 'qvaclick-email-manager')); ?>';
                case 'admins': return '<?php echo esc_js(__('Administradores', 'qvaclick-email-manager')); ?>';
                default: return '';
            }
        }

        function updateSegmentNotice() {
            var sel = document.getElementById('recipient_type');
            var notice = document.getElementById('segment_selection_notice');
            if (!sel || !notice) return;
            var type = sel.value;
            if (type === 'specific_user') {
                notice.style.display = 'none';
                return;
            }
            if (!qvc_send_to_all_segment) { notice.style.display = 'none'; return; }
            var label = getSegmentLabel(type);
            if (!label) { notice.style.display = 'none'; return; }
            var p = notice.querySelector('p');
            if (p) p.textContent = '<?php echo esc_js(__('Se enviará a todos los', 'qvaclick-email-manager')); ?> ' + label + '.';
            notice.style.display = 'block';
        }
        
        // Pagination state
        var qvc_recipient_page = 1;
        var qvc_recipient_per_page = 20; // default per user request

        function loadRecipientPreview(page) {
            if (!page) page = qvc_recipient_page || 1;
            qvc_recipient_page = page;
            const type = document.getElementById('recipient_type').value;
            const filter = document.getElementById('recipient_filter').value;

            // AJAX call to load preview
            const data = new FormData();
            data.append('action', 'qvc_load_recipient_preview');
            data.append('recipient_type', type);
            data.append('recipient_filter', filter);
            data.append('page', page);
            data.append('per_page', qvc_recipient_per_page);
            data.append('nonce', '<?php echo wp_create_nonce('qvc_email_nonce'); ?>');

            fetch(ajaxurl, {
                method: 'POST',
                body: data
            })
            .then(response => response.json())
            .then(result => {
                const preview = document.getElementById('recipient_preview');
                if (result.success) {
                    preview.innerHTML = result.data.preview;
                    preview.style.display = 'block';

                    // Después de inyectar la tabla, conectar eventos para selección
                    attachRecipientTableEvents();

                    // Wire pagination buttons
                    const prev = preview.querySelector('.qvc-recipient-prev');
                    const next = preview.querySelector('.qvc-recipient-next');
                    if (prev) prev.addEventListener('click', function(e){ e.preventDefault(); var p = parseInt(this.getAttribute('data-page')) || 1; if (p<1) p=1; loadRecipientPreview(p); });
                    if (next) next.addEventListener('click', function(e){ e.preventDefault(); var p = parseInt(this.getAttribute('data-page')) || 1; loadRecipientPreview(p); });
                } else {
                    preview.style.display = 'none';
                }
            });
        }
        
        // Load initial preview
        document.addEventListener('DOMContentLoaded', function(){
            updateSegmentNotice();
            loadRecipientPreview();
            var sel = document.getElementById('recipient_type');
            if (sel) {
                sel.addEventListener('click', function(){ qvc_send_to_all_segment = false; qvc_active_segment = null; updateSegmentNotice(); });
                sel.addEventListener('change', function(){ qvc_send_to_all_segment = false; qvc_active_segment = null; updateSegmentNotice(); });
            }
        });

        function qvcConfirmSendNow(form) {
            try { updateSelectedUsers(); } catch(e) {}
            var type = (document.getElementById('recipient_type')||{}).value || 'all';
            var selectedIdsField = document.getElementById('selected_user_ids');
            var selectedIds = selectedIdsField && selectedIdsField.value ? selectedIdsField.value.split(',').filter(Boolean) : [];
            var message;
            // If an active segment is toggled on, always confirm as segment
            if (qvc_active_segment && qvc_send_to_all_segment && type === qvc_active_segment) {
                var label = getSegmentLabel(type) || '<?php echo esc_js(__('usuarios', 'qvaclick-email-manager')); ?>';
                var preview = document.getElementById('recipient_preview');
                var count = '?';
                if (preview && preview.textContent) {
                    var m = preview.textContent.match(/(\d+)\s+destinatarios/i);
                    if (m) count = m[1];
                }
                message = '<?php echo esc_js(__('¿Enviar inmediatamente a todos los', 'qvaclick-email-manager')); ?>' + ' ' + label + ' ('+count+' <?php echo esc_js(__('destinatarios', 'qvaclick-email-manager')); ?>)?';
            } else if (selectedIds.length > 0) {
                message = '<?php echo esc_js(__('¿Enviar inmediatamente a los siguientes usuarios seleccionados?', 'qvaclick-email-manager')); ?>' + "\n\n" + selectedIds.map(function(id){return 'ID: '+id;}).join(', ');
            } else {
                // Not a segment and no explicit user list; build a generic confirmation using current preview count
                var preview = document.getElementById('recipient_preview');
                var count = '?';
                if (preview && preview.textContent) {
                    var m = preview.textContent.match(/(\d+)\s+destinatarios/i);
                    if (m) count = m[1];
                }
                if (count === '?' || count === '0') {
                    alert('<?php echo esc_js(__('No hay destinatarios para enviar. Ajusta la selección antes de continuar.', 'qvaclick-email-manager')); ?>');
                    return false;
                }
                message = '<?php echo esc_js(__('¿Enviar inmediatamente a', 'qvaclick-email-manager')); ?>' + ' ' + count + ' <?php echo esc_js(__('destinatarios', 'qvaclick-email-manager')); ?>?';
            }
            if (confirm(message)) {
                form.send_now.value = '1';
                return true;
            }
            return false;
        }
        document.getElementById('recipient_filter').addEventListener('input', loadRecipientPreview);

        // Persistent selection across pages
        var qvc_selected_users = {}; // map id -> {id,email,name}
        var qvc_create_ticket_users = {}; // map id -> true

        function attachRecipientTableEvents() {
            const table = document.getElementById('qvc-recipient-table');
            if (!table) return;

            // Pre-check rows from persistent selection
            table.querySelectorAll('tbody tr').forEach(function(row){
                const uid = row.getAttribute('data-user-id');
                if (!uid) return;
                const cb = row.querySelector('.qvc-select-user');
                const ticketCb = row.querySelector('.qvc-create-ticket-user');
                if (cb && qvc_selected_users[uid]) cb.checked = true;
                if (ticketCb && qvc_create_ticket_users[uid]) ticketCb.checked = true;
            });

            // Segment mode: visually select all and disable toggles on this page
            if (qvc_active_segment && qvc_send_to_all_segment) {
                table.querySelectorAll('.qvc-select-user').forEach(function(cb){ cb.checked = true; cb.disabled = true; });
                table.querySelectorAll('.qvc-create-ticket-user').forEach(function(cb){ cb.disabled = true; });
            } else {
                table.querySelectorAll('.qvc-select-user').forEach(function(cb){ cb.disabled = false; });
                table.querySelectorAll('.qvc-create-ticket-user').forEach(function(cb){ cb.disabled = false; });
            }

            // Delegation for selection checkboxes
            table.querySelectorAll('.qvc-select-user').forEach(function(cb) {
                cb.addEventListener('change', function() {
                    const row = this.closest('tr');
                    if (!row) return;
                    const uid = this.value;
                    const email = this.getAttribute('data-email');
                    const name = this.getAttribute('data-name');
                    if (this.checked) {
                        qvc_selected_users[uid] = {id: uid, email: email, name: name};
                    } else {
                        delete qvc_selected_users[uid];
                        // also remove ticket flag if any
                        if (qvc_create_ticket_users[uid]) delete qvc_create_ticket_users[uid];
                    }
                    updateSelectedUsers();
                });
            });

            // Delegation for create-ticket per user
            table.querySelectorAll('.qvc-create-ticket-user').forEach(function(cb) {
                cb.addEventListener('change', function() {
                    const row = this.closest('tr');
                    if (!row) return;
                    const uid = row.getAttribute('data-user-id');
                    if (this.checked) {
                        qvc_create_ticket_users[uid] = true;
                    } else {
                        delete qvc_create_ticket_users[uid];
                    }
                    updateSelectedUsers();
                });
            });
        }

        function updateSelectedUsers() {
            const selectedContainer = document.getElementById('selected_users_list');
            const hidden = document.getElementById('selected_user_ids');
            const createHidden = document.getElementById('create_ticket_user_ids');
            selectedContainer.innerHTML = '';

            // In segment mode, do not populate hidden IDs; show an informative message
            if (qvc_active_segment && qvc_send_to_all_segment) {
                hidden.value = '';
                createHidden.value = '';
                if (selectedContainer) {
                    selectedContainer.innerHTML = '<em><?php _e('Se enviará a todo el segmento seleccionado', 'qvaclick-email-manager'); ?></em>';
                    var selWrap = document.getElementById('selected_users');
                    if (selWrap) selWrap.style.display = 'block';
                }
                return;
            }

            const selectedIds = Object.keys(qvc_selected_users || {});
            if (selectedIds.length === 0) {
                selectedContainer.innerHTML = '<em><?php _e('Ningún usuario seleccionado', 'qvaclick-email-manager'); ?></em>';
                document.getElementById('selected_users').style.display = 'none';
            } else {
                document.getElementById('selected_users').style.display = 'block';
                selectedIds.forEach(function(uid){
                    const u = qvc_selected_users[uid];
                    const item = document.createElement('div');
                    item.style.marginBottom = '6px';
                    item.textContent = (u.name ? u.name + ' - ' : '') + u.email + ' (ID: ' + uid + ')';
                    selectedContainer.appendChild(item);
                });
            }

            hidden.value = selectedIds.join(',');
            createHidden.value = Object.keys(qvc_create_ticket_users || {}).join(',');
        }

        // Asegurarse de que el listado se actualice antes de submit
        document.querySelector('.qvc-mass-email-form').addEventListener('submit', function() {
            updateSelectedUsers();
        });
        </script>
        <?php
    }
    
    /**
     * Renderiza la lista de campañas
     */
    private static function render_campaigns_list() {
        global $wpdb;
        
        // Procesar acciones
        self::process_campaign_actions();
        
        // Obtener vista (campañas normales o papelera)
        $view = isset($_GET['campaign_view']) ? sanitize_text_field($_GET['campaign_view']) : 'active';
        
        $table = $wpdb->prefix . 'qvc_mass_emails';
        
        if ($view === 'trash') {
            $campaigns = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE TRIM(status) = %s ORDER BY created_at DESC LIMIT %d", 'deleted', 50));
            $page_title = __('Papelera de Campañas', 'qvaclick-email-manager');
        } else {
            // Excluir registros con status 'deleted' defensivamente (NULL or whitespace)
            $campaigns = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE (status IS NULL OR TRIM(status) != %s) ORDER BY created_at DESC LIMIT %d", 'deleted', 50));
            $page_title = __('Campañas de Email Masivo', 'qvaclick-email-manager');
        }
        ?>
        
        <div class="qvc-campaigns-list">
            <?php
            // Mostrar notices pasadas por redirect (notice=trashed|restored|deleted|error_*)
            if (isset($_GET['notice'])) {
                $notice = sanitize_text_field($_GET['notice']);
                $map = array(
                    'trashed' => array('type' => 'success', 'text' => __('Campaña movida a la papelera.', 'qvaclick-email-manager')),
                    'restored' => array('type' => 'success', 'text' => __('Campaña restaurada exitosamente.', 'qvaclick-email-manager')),
                    'deleted' => array('type' => 'success', 'text' => __('Campaña eliminada permanentemente.', 'qvaclick-email-manager')),
                    'error_trash' => array('type' => 'error', 'text' => __('Error al mover la campaña a la papelera.', 'qvaclick-email-manager')),
                    'error_restore' => array('type' => 'error', 'text' => __('Error al restaurar la campaña.', 'qvaclick-email-manager')),
                    'error_delete' => array('type' => 'error', 'text' => __('Error al eliminar la campaña.', 'qvaclick-email-manager')),
                );
                if (!empty($map[$notice])) {
                    $n = $map[$notice];
                    echo '<div class="notice notice-' . esc_attr($n['type']) . '"><p>' . esc_html($n['text']) . '</p></div>';
                }
            }
            ?>
            <div class="qvc-campaigns-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2><?php echo $page_title; ?></h2>
                <div class="qvc-campaigns-nav">
                    <a href="<?php echo admin_url('admin.php?page=qvc-admin-email&action=campaigns&campaign_view=active'); ?>" 
                       class="button <?php echo $view === 'active' ? 'button-primary' : 'button-secondary'; ?>">
                        <?php _e('Campañas Activas', 'qvaclick-email-manager'); ?>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=qvc-admin-email&action=campaigns&campaign_view=trash'); ?>" 
                       class="button <?php echo $view === 'trash' ? 'button-primary' : 'button-secondary'; ?>">
                        <?php _e('Papelera', 'qvaclick-email-manager'); ?>
                    </a>
                </div>
            </div>
            
            <table class="qvc-campaigns-table wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Campaña', 'qvaclick-email-manager'); ?></th>
                        <th><?php _e('Asunto', 'qvaclick-email-manager'); ?></th>
                        <th><?php _e('Destinatarios', 'qvaclick-email-manager'); ?></th>
                        <th><?php _e('Estado', 'qvaclick-email-manager'); ?></th>
                        <th><?php _e('Estadísticas', 'qvaclick-email-manager'); ?></th>
                        <th><?php _e('Fecha', 'qvaclick-email-manager'); ?></th>
                        <th><?php _e('Acciones', 'qvaclick-email-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($campaigns)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px;">
                                <?php if ($view === 'trash'): ?>
                                    <?php _e('No hay campañas en la papelera.', 'qvaclick-email-manager'); ?>
                                <?php else: ?>
                                    <?php _e('No hay campañas disponibles.', 'qvaclick-email-manager'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($campaigns as $campaign): ?>
                            <tr>
                                <td><strong><?php echo esc_html($campaign->campaign_name); ?></strong></td>
                                <td><?php echo esc_html($campaign->subject); ?></td>
                                <td>
                                    <?php echo esc_html(ucfirst(str_replace('_', ' ', $campaign->recipient_type))); ?>
                                    <br>
                                    <small><?php echo esc_html($campaign->total_recipients); ?> destinatarios</small>
                                </td>
                                <td>
                                    <span class="qvc-status-badge qvc-status-<?php echo esc_attr($campaign->status); ?>">
                                        <?php 
                                        $status_labels = [
                                            'draft' => __('Borrador', 'qvaclick-email-manager'),
                                            'sending' => __('Enviando', 'qvaclick-email-manager'),
                                            'sent' => __('Enviado', 'qvaclick-email-manager'),
                                            'failed' => __('Falló', 'qvaclick-email-manager'),
                                            'deleted' => __('Eliminado', 'qvaclick-email-manager')
                                        ];
                                        echo esc_html($status_labels[$campaign->status] ?? ucfirst($campaign->status));
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($campaign->status === 'sent'): ?>
                                        ✅ <?php echo esc_html($campaign->sent_count); ?> enviados<br>
                                        <?php if ($campaign->failed_count > 0): ?>
                                            ❌ <?php echo esc_html($campaign->failed_count); ?> fallaron
                                        <?php endif; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo esc_html(date('d/m/Y H:i', strtotime($campaign->created_at))); ?>
                                    <?php if ($campaign->sent_at): ?>
                                        <br><small>Enviado: <?php echo esc_html(date('d/m/Y H:i', strtotime($campaign->sent_at))); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($view === 'trash'): ?>
                                        <!-- Acciones en papelera -->
                                        <button type="button" class="button button-small qvc-restore-campaign" 
                                                data-campaign-id="<?php echo $campaign->id; ?>"
                                                title="<?php _e('Restaurar campaña', 'qvaclick-email-manager'); ?>">
                                            <?php _e('Restaurar', 'qvaclick-email-manager'); ?>
                                        </button>
                                        <button type="button" class="button button-small button-link-delete qvc-delete-permanent-campaign" 
                                                data-campaign-id="<?php echo $campaign->id; ?>"
                                                
                                                title="<?php _e('Eliminar permanentemente', 'qvaclick-email-manager'); ?>">
                                            <?php _e('Eliminar definitivamente', 'qvaclick-email-manager'); ?>
                                        </button>
                                    <?php else: ?>
                                        <!-- Acciones normales -->
                                        <?php if ($campaign->status === 'draft'): ?>
                                            <button type="button" class="button button-small button-primary qvc-send-campaign" 
                                                    data-campaign-id="<?php echo $campaign->id; ?>"
                                                    data-campaign-name="<?php echo esc_attr($campaign->campaign_name); ?>"
                                                    
                                                    title="<?php _e('Enviar campaña', 'qvaclick-email-manager'); ?>">
                                                <?php _e('Enviar', 'qvaclick-email-manager'); ?>
                                            </button>
                                            <button type="button" class="button button-small qvc-edit-campaign" 
                                                    data-campaign-id="<?php echo $campaign->id; ?>"
                                                    title="<?php _e('Editar campaña', 'qvaclick-email-manager'); ?>">
                                                <?php _e('Editar', 'qvaclick-email-manager'); ?>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <button type="button" class="button button-small qvc-view-campaign" 
                                                data-campaign-id="<?php echo $campaign->id; ?>"
                                                title="<?php _e('Ver detalles', 'qvaclick-email-manager'); ?>">
                                            <?php _e('Ver', 'qvaclick-email-manager'); ?>
                                        </button>
                                        
                                        <button type="button" class="button button-small qvc-duplicate-campaign" 
                                                data-campaign-id="<?php echo $campaign->id; ?>"
                                                title="<?php _e('Duplicar campaña', 'qvaclick-email-manager'); ?>">
                                            <?php _e('Duplicar', 'qvaclick-email-manager'); ?>
                                        </button>
                                        
                                        <button type="button" class="button button-small button-link-delete qvc-trash-campaign" 
                                                data-campaign-id="<?php echo $campaign->id; ?>"
                                                
                                                title="<?php _e('Mover a papelera', 'qvaclick-email-manager'); ?>">
                                            <?php _e('Papelera', 'qvaclick-email-manager'); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Estilos CSS -->
        <style>
        .qvc-status-badge {
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
        }
        .qvc-status-draft { background: #f0f0f1; color: #646970; }
        .qvc-status-sending { background: #d63638; color: white; }
        .qvc-status-sent { background: #00a32a; color: white; }
        .qvc-status-failed { background: #d63638; color: white; }
        .qvc-status-deleted { background: #646970; color: white; }
        
        .qvc-campaigns-table td { vertical-align: top; }
        .qvc-campaigns-table .button { margin: 2px; }
        </style>
        
        <!-- JavaScript para manejar acciones -->
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Enviar campaña
            document.querySelectorAll('.qvc-send-campaign').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (!confirm('<?php echo esc_js(__('¿Estás seguro de enviar esta campaña ahora?', 'qvaclick-email-manager')); ?>')) return;
                    var campaignId = this.getAttribute('data-campaign-id');
                    window.location.href = '<?php echo admin_url('admin.php?page=qvc-admin-email&action=campaigns&campaign_action=send&campaign_id='); ?>' + campaignId + '&_wpnonce=<?php echo wp_create_nonce('qvc_campaign_action'); ?>';
                });
            });
            
            // Editar campaña
            document.querySelectorAll('.qvc-edit-campaign').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var campaignId = this.getAttribute('data-campaign-id');
                    window.location.href = '<?php echo admin_url('admin.php?page=qvc-admin-email&action=mass_email&edit_campaign='); ?>' + campaignId;
                });
            });
            
            // Ver detalles
            document.querySelectorAll('.qvc-view-campaign').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var campaignId = this.getAttribute('data-campaign-id');
                    window.location.href = '<?php echo admin_url('admin.php?page=qvc-admin-email&action=campaigns&campaign_action=view&campaign_id='); ?>' + campaignId;
                });
            });
            
            // Duplicar campaña
            document.querySelectorAll('.qvc-duplicate-campaign').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var campaignId = this.getAttribute('data-campaign-id');
                    window.location.href = '<?php echo admin_url('admin.php?page=qvc-admin-email&action=campaigns&campaign_action=duplicate&campaign_id='); ?>' + campaignId + '&_wpnonce=<?php echo wp_create_nonce('qvc_campaign_action'); ?>';
                });
            });
            
            // Mover a papelera
            document.querySelectorAll('.qvc-trash-campaign').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (!confirm('<?php echo esc_js(__('¿Enviar esta campaña a la papelera?', 'qvaclick-email-manager')); ?>')) return;
                    var campaignId = this.getAttribute('data-campaign-id');
                    window.location.href = '<?php echo admin_url('admin.php?page=qvc-admin-email&action=campaigns&campaign_action=trash&campaign_id='); ?>' + campaignId + '&_wpnonce=<?php echo wp_create_nonce('qvc_campaign_action'); ?>';
                });
            });
            
            // Restaurar de papelera
            document.querySelectorAll('.qvc-restore-campaign').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (!confirm('<?php echo esc_js(__('Restaurar esta campaña?', 'qvaclick-email-manager')); ?>')) return;
                    var campaignId = this.getAttribute('data-campaign-id');
                    window.location.href = '<?php echo admin_url('admin.php?page=qvc-admin-email&action=campaigns&campaign_action=restore&campaign_id='); ?>' + campaignId + '&_wpnonce=<?php echo wp_create_nonce('qvc_campaign_action'); ?>';
                });
            });
            
            // Eliminar permanentemente
            document.querySelectorAll('.qvc-delete-permanent-campaign').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (!confirm('<?php echo esc_js(__('¿Estás seguro? Esta acción es permanente.', 'qvaclick-email-manager')); ?>')) return;
                    var campaignId = this.getAttribute('data-campaign-id');
                    window.location.href = '<?php echo admin_url('admin.php?page=qvc-admin-email&action=campaigns&campaign_action=delete_permanent&campaign_id='); ?>' + campaignId + '&_wpnonce=<?php echo wp_create_nonce('qvc_campaign_action'); ?>';
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Procesar acciones de campañas (enviar, editar, ver, duplicar, eliminar)
     */
    private static function process_campaign_actions() {
        if (!isset($_GET['campaign_action']) || !isset($_GET['campaign_id'])) {
            return;
        }
        
        $action = sanitize_text_field($_GET['campaign_action']);
        $campaign_id = intval($_GET['campaign_id']);
        
        // Log para debug
        error_log("QvaClick Debug: Procesando acción $action para campaña $campaign_id");
        
        // Verificar nonce para acciones que modifican datos
        $modify_actions = ['send', 'duplicate', 'trash', 'restore', 'delete_permanent'];
        if (in_array($action, $modify_actions)) {
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'qvc_campaign_action')) {
                error_log("QvaClick Debug: Fallo verificación nonce para acción $action");
                wp_die(__('Nonce verification failed.', 'qvaclick-email-manager'));
            }
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'qvc_mass_emails';
        
    switch ($action) {
            case 'send':
                // Enviar campaña
                $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $campaign_id));
                if ($campaign && $campaign->status === 'draft') {
                    $admin_email = QvaClick_Admin_Email_Manager::get_instance();
                    $result = $admin_email->send_mass_email($campaign_id);
                    
                    if ($result && is_array($result)) {
                        echo '<div class="notice notice-success"><p>' . 
                             sprintf(__('Campaña enviada. %d emails enviados, %d fallaron.', 'qvaclick-email-manager'), 
                                    $result['sent'], $result['failed']) . 
                             '</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>' . __('Error al enviar la campaña.', 'qvaclick-email-manager') . '</p></div>';
                    }
                }
                break;
                
            case 'duplicate':
                // Duplicar campaña
                $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $campaign_id));
                if ($campaign) {
                    $new_data = array(
                        'campaign_name' => $campaign->campaign_name . ' (Copia)',
                        'subject' => $campaign->subject,
                        'content' => $campaign->content,
                        'recipient_type' => $campaign->recipient_type,
                        'recipient_filter' => $campaign->recipient_filter,
                        'status' => 'draft',
                        'total_recipients' => $campaign->total_recipients,
                        'created_by' => get_current_user_id()
                    );
                    
                    $result = $wpdb->insert($table, $new_data);
                    if ($result) {
                        echo '<div class="notice notice-success"><p>' . __('Campaña duplicada exitosamente.', 'qvaclick-email-manager') . '</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>' . __('Error al duplicar la campaña.', 'qvaclick-email-manager') . '</p></div>';
                    }
                }
                break;
                
            case 'trash':
                // Mover a papelera (soft delete)
                error_log("QvaClick Debug: Intentando mover campaña $campaign_id a papelera");
                // Leer estado actual para diagnóstico
                $current_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE id = %d", $campaign_id));
                error_log("QvaClick Debug: Estado actual antes de trash para campaña $campaign_id: " . var_export($current_status, true));

                $result = $wpdb->update($table, array('status' => 'deleted'), array('id' => $campaign_id));
                // Registrar resultados de la operación
                error_log("QvaClick Debug: wpdb->update returned: " . var_export($result, true));
                if (!empty($wpdb->last_error)) {
                    error_log("QvaClick Debug: wpdb->last_error: " . $wpdb->last_error);
                }
                if (isset($wpdb->rows_affected)) {
                    error_log("QvaClick Debug: wpdb->rows_affected after update: " . intval($wpdb->rows_affected));
                }

                // Mejores comprobaciones de éxito
                if ($result === false || (!empty($wpdb->last_error))) {
                    $msg = 'error_trash';
                } else {
                    // Si no se afectaron filas y el estado previo no era 'deleted', tratamos como error
                    $prior = strtolower(trim((string)$current_status));
                    if ((isset($wpdb->rows_affected) && $wpdb->rows_affected === 0) && $prior !== 'deleted') {
                        error_log("QvaClick Debug: Actualización no afectó filas pero estado previo (" . $prior . ") no es 'deleted'. Posible problema de permisos o condición WHERE.");
                        $msg = 'error_trash';
                    } else {
                        // Éxito o ya estaba eliminado
                        $msg = ($prior === 'deleted') ? 'already_trashed' : 'trashed';
                    }
                }
                wp_redirect(admin_url('admin.php?page=qvc-admin-email&action=campaigns&notice=' . $msg));
                exit;
                break;
                
            case 'restore':
                // Restaurar de papelera -> volver a 'draft'
                $current_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE id = %d", $campaign_id));
                error_log("QvaClick Debug: Intentando restaurar campaña $campaign_id desde estado: " . var_export($current_status, true));
                $result = $wpdb->update($table, array('status' => 'draft'), array('id' => $campaign_id));
                if (!empty($wpdb->last_error)) {
                    error_log("QvaClick Debug: wpdb->last_error restore: " . $wpdb->last_error);
                }
                if (isset($wpdb->rows_affected)) {
                    error_log("QvaClick Debug: wpdb->rows_affected after restore: " . intval($wpdb->rows_affected));
                }
                if ($result === false || (!empty($wpdb->last_error))) {
                    $msg = 'error_restore';
                } else {
                    $msg = (($wpdb->rows_affected ?? 0) === 0 && strtolower(trim((string)$current_status)) !== 'draft') ? 'error_restore' : 'restored';
                }
                wp_redirect(admin_url('admin.php?page=qvc-admin-email&action=campaigns&notice=' . $msg));
                exit;
                break;
                break;
                
            case 'delete_permanent':
                // Eliminar permanentemente (borrar campaña y logs)
                error_log("QvaClick Debug: Intentando eliminar permanentemente campaña $campaign_id");
                $ok1 = $wpdb->delete($table, array('id' => $campaign_id));
                if (!empty($wpdb->last_error)) {
                    error_log("QvaClick Debug: wpdb->last_error delete_permanent: " . $wpdb->last_error);
                }
                $ok2 = $wpdb->delete($wpdb->prefix . 'qvc_mass_email_logs', array('mass_email_id' => $campaign_id));
                error_log("QvaClick Debug: delete returned ok1=" . var_export($ok1, true) . " ok2=" . var_export($ok2, true));
                $msg = ($ok1 === false) ? 'error_delete' : 'deleted';
                wp_redirect(admin_url('admin.php?page=qvc-admin-email&action=campaigns&notice=' . $msg));
                exit;
                break;
                break;
                
            case 'view':
                // Ver detalles de campaña
                self::render_campaign_details($campaign_id);
                return; // No renderizar la lista de campañas
        }
    }
    
    /**
     * Renderizar detalles de una campaña
     */
    private static function render_campaign_details($campaign_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qvc_mass_emails';
        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $campaign_id));
        
        if (!$campaign) {
            echo '<div class="notice notice-error"><p>' . __('Campaña no encontrada.', 'qvaclick-email-manager') . '</p></div>';
            return;
        }
        
        // Obtener logs de envío
        $logs_table = $wpdb->prefix . 'qvc_mass_email_logs';
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$logs_table} WHERE mass_email_id = %d ORDER BY sent_at DESC LIMIT 100",
            $campaign_id
        ));
        
        ?>
        <div class="qvc-campaign-details">
            <div style="margin-bottom: 20px;">
                <a href="<?php echo admin_url('admin.php?page=qvc-admin-email&action=campaigns'); ?>" class="button">
                    ← <?php _e('Volver a Campañas', 'qvaclick-email-manager'); ?>
                </a>
            </div>
            
            <h2><?php _e('Detalles de Campaña:', 'qvaclick-email-manager'); ?> <?php echo esc_html($campaign->campaign_name); ?></h2>
            
            <div class="qvc-campaign-info" style="background: white; padding: 20px; border: 1px solid #ccd0d4; margin-bottom: 20px;">
                <h3><?php _e('Información General', 'qvaclick-email-manager'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th><?php _e('Nombre de Campaña:', 'qvaclick-email-manager'); ?></th>
                        <td><?php echo esc_html($campaign->campaign_name); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Asunto:', 'qvaclick-email-manager'); ?></th>
                        <td><?php echo esc_html($campaign->subject); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Estado:', 'qvaclick-email-manager'); ?></th>
                        <td>
                            <span class="qvc-status-badge qvc-status-<?php echo esc_attr($campaign->status); ?>">
                                <?php echo esc_html(ucfirst($campaign->status)); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Tipo de Destinatarios:', 'qvaclick-email-manager'); ?></th>
                        <td><?php echo esc_html(ucfirst(str_replace('_', ' ', $campaign->recipient_type))); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Total Destinatarios:', 'qvaclick-email-manager'); ?></th>
                        <td><?php echo esc_html($campaign->total_recipients); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Enviados:', 'qvaclick-email-manager'); ?></th>
                        <td><?php echo esc_html($campaign->sent_count); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Fallidos:', 'qvaclick-email-manager'); ?></th>
                        <td><?php echo esc_html($campaign->failed_count); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Creado:', 'qvaclick-email-manager'); ?></th>
                        <td><?php echo esc_html(date('d/m/Y H:i:s', strtotime($campaign->created_at))); ?></td>
                    </tr>
                    <?php if ($campaign->sent_at): ?>
                    <tr>
                        <th><?php _e('Enviado:', 'qvaclick-email-manager'); ?></th>
                        <td><?php echo esc_html(date('d/m/Y H:i:s', strtotime($campaign->sent_at))); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
            
            <div class="qvc-campaign-content" style="background: white; padding: 20px; border: 1px solid #ccd0d4; margin-bottom: 20px;">
                <h3><?php _e('Contenido del Email', 'qvaclick-email-manager'); ?></h3>
                <div style="border: 1px solid #ddd; padding: 15px; background: #f9f9f9;">
                    <?php echo wp_kses_post($campaign->content); ?>
                </div>
            </div>
            
            <?php if (!empty($logs)): ?>
            <div class="qvc-campaign-logs" style="background: white; padding: 20px; border: 1px solid #ccd0d4;">
                <h3><?php _e('Historial de Envíos', 'qvaclick-email-manager'); ?></h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Destinatario', 'qvaclick-email-manager'); ?></th>
                            <th><?php _e('Estado', 'qvaclick-email-manager'); ?></th>
                            <th><?php _e('Fecha de Envío', 'qvaclick-email-manager'); ?></th>
                            <th><?php _e('Error', 'qvaclick-email-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log->recipient_email); ?></td>
                            <td>
                                <span class="qvc-status-badge qvc-status-<?php echo esc_attr($log->status); ?>">
                                    <?php echo esc_html($log->status); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html(date('d/m/Y H:i:s', strtotime($log->sent_at))); ?></td>
                            <td><?php echo esc_html($log->error_message ?: '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Renderiza la página de configuración
     */
    private static function render_settings() {
        // Guardar configuración
        if (isset($_POST['save_settings']) && wp_verify_nonce($_POST['qvc_nonce'], 'qvc_settings')) {
            update_option('qvc_support_email', sanitize_email($_POST['support_email']));
            update_option('qvc_support_from_name', sanitize_text_field($_POST['support_from_name']));
            update_option('qvc_auto_assign_tickets', sanitize_text_field($_POST['auto_assign_tickets']));
            update_option('qvc_ticket_categories', sanitize_textarea_field($_POST['ticket_categories']));
            
            echo '<div class="notice notice-success"><p>' . __('Configuración guardada.', 'qvaclick-email-manager') . '</p></div>';
        }
        
        $support_email = get_option('qvc_support_email', get_option('admin_email'));
        $support_from_name = get_option('qvc_support_from_name', get_bloginfo('name'));
        $auto_assign = get_option('qvc_auto_assign_tickets', '');
        $categories = get_option('qvc_ticket_categories', "general\nplatform\nbilling\ntechnical");
        ?>
        
        <div class="qvc-settings-page">
            <h2><?php _e('Configuración de Admin Email', 'qvaclick-email-manager'); ?></h2>
            
            <form method="post" action="" class="qvc-settings-form">
                <?php wp_nonce_field('qvc_settings', 'qvc_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Email de Soporte', 'qvaclick-email-manager'); ?></th>
                        <td>
                            <input type="email" name="support_email" value="<?php echo esc_attr($support_email); ?>" class="regular-text">
                            <p class="description"><?php _e('Email desde el cual se enviarán las respuestas de soporte.', 'qvaclick-email-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Nombre del Remitente', 'qvaclick-email-manager'); ?></th>
                        <td>
                            <input type="text" name="support_from_name" value="<?php echo esc_attr($support_from_name); ?>" class="regular-text">
                            <p class="description"><?php _e('Nombre que aparecerá como remitente de los emails.', 'qvaclick-email-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Asignación Automática', 'qvaclick-email-manager'); ?></th>
                        <td>
                            <select name="auto_assign_tickets">
                                <option value=""><?php _e('Sin asignación automática', 'qvaclick-email-manager'); ?></option>
                                <?php
                                $admins = get_users(array('role' => 'administrator'));
                                foreach ($admins as $admin) {
                                    echo '<option value="' . $admin->ID . '"' . selected($auto_assign, $admin->ID, false) . '>' . 
                                         esc_html($admin->display_name) . '</option>';
                                }
                                ?>
                            </select>
                            <p class="description"><?php _e('Asignar nuevos tickets automáticamente a este usuario.', 'qvaclick-email-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Categorías de Tickets', 'qvaclick-email-manager'); ?></th>
                        <td>
                            <textarea name="ticket_categories" rows="5" class="large-text"><?php echo esc_textarea($categories); ?></textarea>
                            <p class="description"><?php _e('Una categoría por línea. Estas aparecerán como opciones en los formularios de tickets.', 'qvaclick-email-manager'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" name="save_settings" class="button button-primary">
                        <?php _e('Guardar Configuración', 'qvaclick-email-manager'); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }
    
    /**
     * Obtiene el número de tickets sin leer
     */
    /**
     * Obtiene las etiquetas legibles de los estados
     */
    private static function get_status_label($status) {
        $labels = array(
            'new' => __('Nuevo', 'qvaclick-email-manager'),
            'open' => __('Abierto', 'qvaclick-email-manager'),
            'in_progress' => __('En progreso', 'qvaclick-email-manager'),
            'on_hold' => __('En espera', 'qvaclick-email-manager'),
            'resolved' => __('Resuelto', 'qvaclick-email-manager'),
            'closed' => __('Cerrado', 'qvaclick-email-manager')
        );
        
        return isset($labels[$status]) ? $labels[$status] : ucfirst(str_replace('_', ' ', $status));
    }
    
    /**
     * Obtiene el número de tickets no leídos
     */
    private static function get_unread_tickets_count() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qvc_support_tickets';
        return $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'open'");
    }

    /**
     * Genera las filas de la tabla de campañas
     */
    public function generate_campaigns_table_rows($campaigns) {
        if (empty($campaigns)) {
            return '<tr><td colspan="7">No hay campañas disponibles</td></tr>';
        }

        $html = '';
        foreach ($campaigns as $campaign) {
            $status_class = 'qvc-status-' . esc_attr($campaign['status']);
            $status_text = ucfirst($campaign['status']);
            
            if ($campaign['status'] === 'sent') {
                $status_text = 'Enviada';
            } elseif ($campaign['status'] === 'draft') {
                $status_text = 'Borrador';
            } elseif ($campaign['status'] === 'sending') {
                $status_text = 'Enviando';
            }

            $open_rate = 0;
            $click_rate = 0;
            
            if ($campaign['recipient_count'] > 0) {
                $open_rate = round(($campaign['opened_count'] / $campaign['recipient_count']) * 100, 1);
                $click_rate = round(($campaign['clicked_count'] / $campaign['recipient_count']) * 100, 1);
            }

            $html .= '<tr>';
            $html .= '<td>' . esc_html($campaign['subject']) . '</td>';
            $html .= '<td><span class="' . esc_attr($status_class) . '">' . esc_html($status_text) . '</span></td>';
            $html .= '<td>' . esc_html($campaign['recipient_count']) . '</td>';
            $html .= '<td>' . esc_html($open_rate) . '%</td>';
            $html .= '<td>' . esc_html($click_rate) . '%</td>';
            $html .= '<td>' . esc_html($campaign['created_at']) . '</td>';
            $html .= '<td>';
            $html .= '<button type="button" class="button button-small qvc-view-campaign" data-id="' . esc_attr($campaign['id']) . '">Ver</button> ';
            $html .= '<button type="button" class="button button-small qvc-duplicate-campaign" data-id="' . esc_attr($campaign['id']) . '">Duplicar</button> ';
            $html .= '<button type="button" class="button button-small qvc-delete-campaign" data-id="' . esc_attr($campaign['id']) . '">Eliminar</button>';
            $html .= '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        echo $html;
    }
    
    /**
     * Renderiza la página de Bandeja de Salida
     */
    public static function render_outbox() {
        if (!class_exists('QvaClick_Outbox_Admin_Page')) {
            echo '<div class="notice notice-error"><p>' . __('Clase QvaClick_Outbox_Admin_Page no encontrada.', 'qvaclick-email-manager') . '</p></div>';
            return;
        }
        
        // Renderizar la bandeja de salida como parte de Admin Email
        QvaClick_Outbox_Admin_Page::render_outbox_content();
    }
    
    /**
     * Get general inbox unread count
     */
    public static function get_general_inbox_unread_count() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'qvc_general_inbox';
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'unread'");
        
        return (int) $count;
    }
    
    /**
     * Get quarantine count
     */
    public static function get_quarantine_count() {
        global $wpdb;
        // Prefer the new table name; fall back to legacy if needed
        $table_name = $wpdb->prefix . 'qvc_quarantine';
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        if (!$exists) {
            $table_name = $wpdb->prefix . 'qvc_email_quarantine';
        }
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'quarantined'");
        
        return (int) $count;
    }
    
    /**
     * Render general inbox page
     */
    public static function render_general_inbox() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'qvc_general_inbox';
        
        // Procesar acciones
        if (isset($_POST['action']) && wp_verify_nonce($_POST['_wpnonce'], 'qvc_general_inbox_action')) {
            $action = sanitize_text_field($_POST['action']);
            $email_ids = isset($_POST['email_ids']) ? array_map('intval', $_POST['email_ids']) : array();
            
            if (!empty($email_ids)) {
                switch ($action) {
                    case 'mark_read':
                        $wpdb->query($wpdb->prepare(
                            "UPDATE $table_name SET status = 'read' WHERE id IN (" . implode(',', array_fill(0, count($email_ids), '%d')) . ")",
                            ...$email_ids
                        ));
                        echo '<div class="notice notice-success"><p>Emails marcados como leídos.</p></div>';
                        break;
                    case 'mark_unread':
                        $wpdb->query($wpdb->prepare(
                            "UPDATE $table_name SET status = 'unread' WHERE id IN (" . implode(',', array_fill(0, count($email_ids), '%d')) . ")",
                            ...$email_ids
                        ));
                        echo '<div class="notice notice-success"><p>Emails marcados como no leídos.</p></div>';
                        break;
                    case 'delete':
                        $wpdb->query($wpdb->prepare(
                            "DELETE FROM $table_name WHERE id IN (" . implode(',', array_fill(0, count($email_ids), '%d')) . ")",
                            ...$email_ids
                        ));
                        echo '<div class="notice notice-success"><p>Emails eliminados.</p></div>';
                        break;
                }
            }
        }
        
        // Filtros
        $category = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '';
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        
        // Construir consulta
        $where_conditions = array('1=1');
        $query_params = array();
        
        if (!empty($category)) {
            $where_conditions[] = 'category = %s';
            $query_params[] = $category;
        }
        
        if (!empty($status)) {
            $where_conditions[] = 'status = %s';
            $query_params[] = $status;
        }
        
        if (!empty($search)) {
            $where_conditions[] = '(sender_email LIKE %s OR subject LIKE %s)';
            $query_params[] = '%' . $wpdb->esc_like($search) . '%';
            $query_params[] = '%' . $wpdb->esc_like($search) . '%';
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        // Paginación
        $per_page = 20;
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($page - 1) * $per_page;
        
        // Obtener total
        $total_query = "SELECT COUNT(*) FROM $table_name WHERE $where_clause";
        if (!empty($query_params)) {
            $total = $wpdb->get_var($wpdb->prepare($total_query, ...$query_params));
        } else {
            $total = $wpdb->get_var($total_query);
        }
        
        // Obtener emails
        $query = "SELECT * FROM $table_name WHERE $where_clause ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $final_params = array_merge($query_params, array($per_page, $offset));
        
        if (!empty($query_params)) {
            $emails = $wpdb->get_results($wpdb->prepare($query, ...$final_params));
        } else {
            $emails = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset));
        }
        
        ?>
        <div class="qvc-general-inbox">
            <h2><?php _e('Bandeja General', 'qvaclick-email-manager'); ?></h2>
            
            <!-- Filtros -->
            <div class="qvc-filters">
                <form method="get" action="">
                    <input type="hidden" name="page" value="qvc-admin-email">
                    <input type="hidden" name="action" value="general_inbox">
                    
                    <select name="category">
                        <option value=""><?php _e('Todas las categorías', 'qvaclick-email-manager'); ?></option>
                        <option value="general" <?php selected($category, 'general'); ?>><?php _e('General', 'qvaclick-email-manager'); ?></option>
                        <option value="admin" <?php selected($category, 'admin'); ?>><?php _e('Administración', 'qvaclick-email-manager'); ?></option>
                        <option value="newsletter" <?php selected($category, 'newsletter'); ?>><?php _e('Newsletter', 'qvaclick-email-manager'); ?></option>
                    </select>
                    
                    <select name="status">
                        <option value=""><?php _e('Todos los estados', 'qvaclick-email-manager'); ?></option>
                        <option value="unread" <?php selected($status, 'unread'); ?>><?php _e('No leído', 'qvaclick-email-manager'); ?></option>
                        <option value="read" <?php selected($status, 'read'); ?>><?php _e('Leído', 'qvaclick-email-manager'); ?></option>
                    </select>
                    
                    <input type="text" name="search" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Buscar...', 'qvaclick-email-manager'); ?>">
                    
                    <input type="submit" class="button" value="<?php _e('Filtrar', 'qvaclick-email-manager'); ?>">
                    <a href="<?php echo admin_url('admin.php?page=qvc-admin-email&action=general_inbox'); ?>" class="button"><?php _e('Limpiar', 'qvaclick-email-manager'); ?></a>
                </form>
            </div>
            
            <!-- Lista de emails -->
            <form method="post" action="">
                <?php wp_nonce_field('qvc_general_inbox_action'); ?>
                
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="action">
                            <option value=""><?php _e('Acciones en lote', 'qvaclick-email-manager'); ?></option>
                            <option value="mark_read"><?php _e('Marcar como leído', 'qvaclick-email-manager'); ?></option>
                            <option value="mark_unread"><?php _e('Marcar como no leído', 'qvaclick-email-manager'); ?></option>
                            <option value="delete"><?php _e('Eliminar', 'qvaclick-email-manager'); ?></option>
                        </select>
                        <input type="submit" class="button action" value="<?php _e('Aplicar', 'qvaclick-email-manager'); ?>">
                    </div>
                </div>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <input type="checkbox" id="cb-select-all">
                            </td>
                            <th><?php _e('Remitente', 'qvaclick-email-manager'); ?></th>
                            <th><?php _e('Asunto', 'qvaclick-email-manager'); ?></th>
                            <th><?php _e('Categoría', 'qvaclick-email-manager'); ?></th>
                            <th><?php _e('Estado', 'qvaclick-email-manager'); ?></th>
                            <th><?php _e('Fecha', 'qvaclick-email-manager'); ?></th>
                            <th><?php _e('Acciones', 'qvaclick-email-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($emails)): ?>
                            <tr>
                                <td colspan="7"><?php _e('No hay emails en la bandeja general.', 'qvaclick-email-manager'); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($emails as $email): ?>
                                <tr class="<?php echo $email->status === 'unread' ? 'qvc-unread' : ''; ?>">
                                    <th scope="row" class="check-column">
                                        <input type="checkbox" name="email_ids[]" value="<?php echo $email->id; ?>">
                                    </th>
                                    <td><?php echo esc_html($email->sender_email); ?></td>
                                    <td>
                                        <strong><?php echo esc_html($email->subject); ?></strong>
                                        <?php if ($email->status === 'unread'): ?>
                                            <span class="qvc-unread-indicator">●</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="qvc-category-badge qvc-category-<?php echo esc_attr($email->category); ?>">
                                            <?php echo esc_html(ucfirst($email->category)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="qvc-status-<?php echo esc_attr($email->status); ?>">
                                            <?php echo $email->status === 'unread' ? __('No leído', 'qvaclick-email-manager') : __('Leído', 'qvaclick-email-manager'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html(date('Y-m-d H:i', strtotime($email->created_at))); ?></td>
                                    <td>
                                        <div class="qvc-email-actions">
                                            <a href="#" class="qvc-view-email button button-small" data-email-id="<?php echo $email->id; ?>">
                                                <?php _e('Ver', 'qvaclick-email-manager'); ?>
                                            </a>
                                            <a href="#" class="qvc-convert-to-ticket button button-small" data-email-id="<?php echo $email->id; ?>">
                                                <?php _e('→ Ticket', 'qvaclick-email-manager'); ?>
                                            </a>
                                            <a href="#" class="qvc-mark-read button button-small" data-email-id="<?php echo $email->id; ?>">
                                                <?php echo $email->status === 'read' ? __('No Leído', 'qvaclick-email-manager') : __('Leído', 'qvaclick-email-manager'); ?>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>
            
            <!-- Paginación -->
            <?php if ($total > $per_page): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        $total_pages = ceil($total / $per_page);
                        $pagination_args = array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'total' => $total_pages,
                            'current' => $page,
                            'show_all' => false,
                            'type' => 'plain',
                        );
                        echo paginate_links($pagination_args);
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Modal para ver email -->
        <div id="qvc-email-modal" class="qvc-modal-overlay" style="display: none;">
            <div class="qvc-modal-content">
                <div class="qvc-modal-header">
                    <h3><?php _e('Ver Email', 'qvaclick-email-manager'); ?></h3>
                    <span class="qvc-modal-close" onclick="closeEmailModal()">&times;</span>
                </div>
                <div class="qvc-modal-body">
                    <div id="qvc-email-details"></div>
                </div>
                <div class="qvc-modal-footer">
                    <button type="button" class="button button-primary" id="qvc-convert-modal-ticket">
                        <?php _e('Crear Ticket', 'qvaclick-email-manager'); ?>
                    </button>
                    <button type="button" class="button" onclick="closeEmailModal()">
                        <?php _e('Cerrar', 'qvaclick-email-manager'); ?>
                    </button>
                </div>
            </div>
        </div>
        
        <style>
        .qvc-email-actions {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .qvc-email-actions .button {
            font-size: 11px;
            padding: 4px 8px;
            min-height: auto;
        }
        
        .qvc-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 100000;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .qvc-modal-content {
            background: white;
            max-width: 800px;
            width: 90%;
            max-height: 90%;
            border-radius: 4px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .qvc-modal-header {
            padding: 20px;
            border-bottom: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8f9fa;
        }
        
        .qvc-modal-header h3 {
            margin: 0;
            font-size: 18px;
        }
        
        .qvc-modal-close {
            font-size: 24px;
            cursor: pointer;
            color: #999;
            line-height: 1;
        }
        
        .qvc-modal-close:hover {
            color: #333;
        }
        
        .qvc-modal-body {
            padding: 20px;
            max-height: 60vh;
            overflow-y: auto;
        }
        
        .qvc-modal-footer {
            padding: 20px;
            border-top: 1px solid #ddd;
            text-align: right;
            background: #f8f9fa;
        }
        
        .qvc-modal-footer .button {
            margin-left: 10px;
        }
        
        .qvc-email-details {
            margin-bottom: 20px;
        }
        
        .qvc-detail-row {
            display: flex;
            margin-bottom: 15px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        
        .qvc-detail-row label {
            font-weight: 600;
            min-width: 120px;
            color: #555;
        }
        
        .qvc-detail-row span,
        .qvc-detail-row select {
            flex: 1;
        }
        
        .qvc-email-content-display {
            border: 1px solid #ddd;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 4px;
            max-height: 300px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 13px;
            line-height: 1.4;
        }
        
        .qvc-unread {
            background-color: #f0f8ff;
        }
        
        .qvc-unread-indicator {
            color: #0073aa;
            font-weight: bold;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Ver email
            $('.qvc-view-email').on('click', function(e) {
                e.preventDefault();
                var emailId = $(this).data('email-id');
                viewGeneralEmail(emailId);
            });
            
            // Convertir a ticket
            $('.qvc-convert-to-ticket').on('click', function(e) {
                e.preventDefault();
                var emailId = $(this).data('email-id');
                if (confirm('¿Estás seguro de que quieres convertir este email en un ticket de soporte?')) {
                    convertEmailToTicket(emailId);
                }
            });
            
            // Marcar como leído/no leído
            $('.qvc-mark-read').on('click', function(e) {
                e.preventDefault();
                var emailId = $(this).data('email-id');
                var currentStatus = $(this).closest('tr').hasClass('qvc-unread') ? 'unread' : 'read';
                var newStatus = currentStatus === 'read' ? 'unread' : 'read';
                updateEmailStatus(emailId, newStatus);
            });
            
            // Convertir desde modal
            $('#qvc-convert-modal-ticket').on('click', function() {
                var emailId = $(this).data('email-id');
                if (emailId && confirm('¿Convertir este email en ticket?')) {
                    convertEmailToTicket(emailId);
                    closeEmailModal();
                }
            });
        });
        
        function viewGeneralEmail(emailId) {
            var data = {
                action: 'qvc_view_general_email',
                email_id: emailId,
                nonce: qvc_ajax.nonce
            };
            
            jQuery.post(qvc_ajax.ajax_url, data, function(response) {
                if (response.success) {
                    showEmailModal(response.data);
                } else {
                    alert('Error: ' + response.data);
                }
            });
        }
        
        function showEmailModal(emailData) {
            var detailsHtml = '<div class="qvc-email-details">';
            detailsHtml += '<div class="qvc-detail-row"><label>De:</label><span>' + emailData.sender_email + '</span></div>';
            detailsHtml += '<div class="qvc-detail-row"><label>Asunto:</label><span>' + emailData.subject + '</span></div>';
            detailsHtml += '<div class="qvc-detail-row"><label>Fecha:</label><span>' + emailData.created_at + '</span></div>';
            detailsHtml += '<div class="qvc-detail-row"><label>Estado:</label>';
            detailsHtml += '<select id="email-status" onchange="updateEmailStatus(' + emailData.id + ', this.value)">';
            detailsHtml += '<option value="unread"' + (emailData.status === 'unread' ? ' selected' : '') + '>Sin leer</option>';
            detailsHtml += '<option value="read"' + (emailData.status === 'read' ? ' selected' : '') + '>Leído</option>';
            detailsHtml += '<option value="assigned"' + (emailData.status === 'assigned' ? ' selected' : '') + '>Asignado</option>';
            detailsHtml += '<option value="processed"' + (emailData.status === 'processed' ? ' selected' : '') + '>Procesado</option>';
            detailsHtml += '</select></div>';
            detailsHtml += '<div class="qvc-detail-row"><label>Prioridad:</label>';
            detailsHtml += '<select id="email-priority" onchange="updateEmailPriority(' + emailData.id + ', this.value)">';
            detailsHtml += '<option value="low"' + (emailData.priority === 'low' ? ' selected' : '') + '>Baja</option>';
            detailsHtml += '<option value="medium"' + (emailData.priority === 'medium' ? ' selected' : '') + '>Media</option>';
            detailsHtml += '<option value="high"' + (emailData.priority === 'high' ? ' selected' : '') + '>Alta</option>';
            detailsHtml += '</select></div>';
            detailsHtml += '<div class="qvc-detail-row"><label>Categoría:</label><span>' + emailData.category + '</span></div>';
            detailsHtml += '</div>';
            detailsHtml += '<h4>Contenido del Email:</h4>';
            detailsHtml += '<div class="qvc-email-content-display">' + emailData.body + '</div>';
            
            jQuery('#qvc-email-details').html(detailsHtml);
            jQuery('#qvc-convert-modal-ticket').data('email-id', emailData.id);
            jQuery('#qvc-email-modal').show();
            
            // Marcar como leído automáticamente
            if (emailData.status === 'unread') {
                updateEmailStatus(emailData.id, 'read');
            }
        }
        
        function closeEmailModal() {
            jQuery('#qvc-email-modal').hide();
        }
        
        function updateEmailStatus(emailId, status) {
            var data = {
                action: 'qvc_update_general_email_status',
                email_id: emailId,
                status: status,
                nonce: qvc_ajax.nonce
            };
            
            jQuery.post(qvc_ajax.ajax_url, data, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error al actualizar estado: ' + response.data);
                }
            });
        }
        
        function updateEmailPriority(emailId, priority) {
            var data = {
                action: 'qvc_update_general_email_status',
                email_id: emailId,
                priority: priority,
                nonce: qvc_ajax.nonce
            };
            
            jQuery.post(qvc_ajax.ajax_url, data, function(response) {
                if (response.success) {
                    console.log('Prioridad actualizada correctamente');
                } else {
                    alert('Error al actualizar prioridad: ' + response.data);
                }
            });
        }
        
        function convertEmailToTicket(emailId) {
            var data = {
                action: 'qvc_convert_email_to_ticket',
                email_id: emailId,
                nonce: qvc_ajax.nonce
            };
            
            jQuery.post(qvc_ajax.ajax_url, data, function(response) {
                if (response.success) {
                    alert('Email convertido a ticket #' + response.data.ticket_id + ' exitosamente');
                    location.reload();
                } else {
                    alert('Error al convertir email: ' + response.data);
                }
            });
        }
        </script>
        <?php
    }
    
    /**
     * Render quarantine page
     */
    public static function render_quarantine() {
        // Verificar si el sistema de seguridad está activo
        if (!class_exists('QvaClick_Ticket_Security_Shield')) {
            echo '<div class="notice notice-warning"><p>Sistema de seguridad no encontrado. Actívalo para usar la cuarentena.</p></div>';
            return;
        }
        
        $security = QvaClick_Ticket_Security_Shield::get_instance();
        
        // Procesar acciones
        if (isset($_POST['quarantine_action']) && wp_verify_nonce($_POST['_wpnonce'], 'qvc_quarantine_action')) {
            $action = sanitize_text_field($_POST['quarantine_action']);
            $item_ids = isset($_POST['item_ids']) ? array_map('intval', $_POST['item_ids']) : array();
            $admin_notes = isset($_POST['admin_notes']) ? sanitize_textarea_field($_POST['admin_notes']) : '';
            
            if (!empty($item_ids)) {
                $success_count = 0;
                foreach ($item_ids as $item_id) {
                    switch ($action) {
                        case 'approve':
                            if ($security->approve_quarantine_item($item_id, $admin_notes)) {
                                $success_count++;
                            }
                            break;
                        case 'reject':
                            if ($security->reject_quarantine_item($item_id, $admin_notes)) {
                                $success_count++;
                            }
                            break;
                        case 'delete':
                            if ($security->delete_quarantine_item($item_id)) {
                                $success_count++;
                            }
                            break;
                    }
                }
                
                if ($success_count > 0) {
                    echo '<div class="notice notice-success"><p>' . 
                         sprintf(__('%d elementos procesados correctamente.', 'qvaclick-email-manager'), $success_count) . 
                         '</p></div>';
                }
            }
        }
        
        // Obtener elementos en cuarentena
        $per_page = 20;
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($page - 1) * $per_page;
        
        $quarantine_items = $security->get_quarantine_items($per_page, $offset);
        $total = $security->get_quarantine_total(); // Total de elementos para paginación
        $security_stats = $security->get_security_stats();
        
        ?>
        <div class="qvc-quarantine-page">
            <h2>🛡️ <?php _e('Cuarentena de Seguridad', 'qvaclick-email-manager'); ?></h2>
            <p><?php _e('Elementos bloqueados por el sistema de seguridad por contener amenazas o contenido sospechoso.', 'qvaclick-email-manager'); ?></p>
            
            <!-- Estadísticas de seguridad -->
            <div class="qvc-security-stats" style="display: flex; gap: 20px; margin-bottom: 20px;">
                <div class="qvc-stat-card" style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 15px; min-width: 150px;">
                    <h4 style="margin: 0 0 10px 0; color: #d63638;">Total en Cuarentena</h4>
                    <span style="font-size: 24px; font-weight: bold;"><?php echo intval($security_stats['total_quarantined']); ?></span>
                </div>
                <div class="qvc-stat-card" style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 15px; min-width: 150px;">
                    <h4 style="margin: 0 0 10px 0; color: #fd7e14;">Últimos 7 días</h4>
                    <span style="font-size: 24px; font-weight: bold;"><?php echo intval($security_stats['last_7_days']); ?></span>
                </div>
            </div>
            
            <!-- Sección de Bad Words Management -->
            <div class="qvc-bad-words-section" style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin-bottom: 20px;">
                <h3>🚫 Gestión de Palabras Prohibidas (Bad Words)</h3>
                <p>Configura las palabras que automáticamente enviarán correos y tickets a cuarentena. Incluye protección contra phishing y spam.</p>
                
                <div class="bad-words-tabs" style="margin-bottom: 15px;">
                    <button type="button" class="button" id="tab-view-words" onclick="showBadWordsTab('view')">Ver Palabras Actuales</button>
                    <button type="button" class="button" id="tab-edit-words" onclick="showBadWordsTab('edit')">Editar Lista</button>
                    <button type="button" class="button" id="tab-default-words" onclick="showBadWordsTab('default')">Ver Palabras por Defecto</button>
                </div>
                
                <!-- Tab: Ver palabras actuales -->
                <div id="badwords-tab-view" class="badwords-tab-content">
                    <div id="current-bad-words-display" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">
                        <em>Cargando...</em>
                    </div>
                </div>
                
                <!-- Tab: Editar palabras -->
                <div id="badwords-tab-edit" class="badwords-tab-content" style="display: none;">
                    <textarea id="bad-words-textarea" placeholder="Ingresa las palabras separadas por comas o una por línea..." 
                             style="width: 100%; height: 150px; margin-bottom: 10px;"></textarea>
                    <p><small><strong>Formato:</strong> Puedes separar las palabras con comas o colocar una por línea. Ejemplo: bitcoin, crypto, urgent action, verify account</small></p>
                    
                    <div style="margin-bottom: 10px;">
                        <button type="button" class="button button-primary" onclick="saveBadWords()">💾 Guardar Cambios</button>
                        <button type="button" class="button" onclick="loadCurrentBadWords()">🔄 Recargar</button>
                        <button type="button" class="button" onclick="addDefaultWords()">➕ Agregar Palabras por Defecto</button>
                        <button type="button" class="button button-secondary" onclick="resetBadWords()" 
                                style="color: #d63638;">🗑️ Resetear a Por Defecto</button>
                    </div>
                </div>
                
                <!-- Tab: Palabras por defecto -->
                <div id="badwords-tab-default" class="badwords-tab-content" style="display: none;">
                    <p><strong>Lista de palabras por defecto que protegen contra phishing y spam:</strong></p>
                    <div id="default-bad-words-display" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f0f8ff;">
                        <em>Cargando...</em>
                    </div>
                    <p><small>Estas palabras están incluidas automáticamente en el sistema de detección.</small></p>
                </div>
                
                <div id="bad-words-messages" style="margin-top: 10px;"></div>
            </div>
            
            <form method="post" action="" id="qvc-quarantine-form">
                <?php wp_nonce_field('qvc_quarantine_action'); ?>
                
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="quarantine_action" id="quarantine-action-select">
                            <option value=""><?php _e('Acciones en lote', 'qvaclick-email-manager'); ?></option>
                            <option value="approve"><?php _e('✅ Aprobar (Falso Positivo)', 'qvaclick-email-manager'); ?></option>
                            <option value="reject"><?php _e('❌ Rechazar (Confirmar Amenaza)', 'qvaclick-email-manager'); ?></option>
                            <option value="delete"><?php _e('🗑️ Eliminar Permanentemente', 'qvaclick-email-manager'); ?></option>
                        </select>
                        
                        <div id="admin-notes-section" style="display: none; margin-top: 10px;">
                            <textarea name="admin_notes" placeholder="Notas del administrador (opcional)" 
                                     style="width: 300px; height: 60px;"></textarea>
                        </div>
                        
                        <input type="submit" class="button action" value="<?php _e('Aplicar', 'qvaclick-email-manager'); ?>" 
                               onclick="return confirm('¿Estás seguro de realizar esta acción?');">
                    </div>
                </div>
                
                <table class="wp-list-table widefat fixed striped qvc-quarantine-table">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <input type="checkbox" id="cb-select-all-quarantine">
                            </td>
                            <th><?php _e('Tipo', 'qvaclick-email-manager'); ?></th>
                            <th><?php _e('Contenido', 'qvaclick-email-manager'); ?></th>
                            <th><?php _e('Nivel de Amenaza', 'qvaclick-email-manager'); ?></th>
                            <th><?php _e('Amenazas Detectadas', 'qvaclick-email-manager'); ?></th>
                            <th><?php _e('Usuario/IP', 'qvaclick-email-manager'); ?></th>
                            <th><?php _e('Fecha', 'qvaclick-email-manager'); ?></th>
                            <th><?php _e('Estado', 'qvaclick-email-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($quarantine_items)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 40px;">
                                    <div style="color: #666;">
                                        🛡️ <strong><?php _e('¡Excelente! No hay elementos en cuarentena.', 'qvaclick-email-manager'); ?></strong><br>
                                        <small><?php _e('Tu sistema está protegido y limpio.', 'qvaclick-email-manager'); ?></small>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($quarantine_items as $item): 
                                $original_data = json_decode($item->original_data, true);
                                $threat_reasons = json_decode($item->threat_reasons, true);
                                
                                $threat_color = array(
                                    'low' => '#28a745',
                                    'medium' => '#ffc107', 
                                    'high' => '#fd7e14',
                                    'critical' => '#dc3545'
                                );
                            ?>
                                <tr class="quarantine-item" data-threat-level="<?php echo esc_attr($item->threat_level); ?>">
                                    <th scope="row" class="check-column">
                                        <input type="checkbox" name="item_ids[]" value="<?php echo intval($item->id); ?>">
                                    </th>
                                    <td>
                                        <strong><?php echo esc_html(ucfirst($item->item_type)); ?></strong><br>
                                        <small style="color: #666;"><?php echo esc_html($item->item_id); ?></small>
                                    </td>
                                    <td>
                                        <div class="quarantine-content" style="max-width: 300px;">
                                            <?php if ($item->item_type === 'ticket'): ?>
                                                <strong><?php echo esc_html(substr($original_data['subject'] ?? '', 0, 50)); ?>...</strong><br>
                                                <small><?php echo esc_html(substr($original_data['message'] ?? '', 0, 100)); ?>...</small>
                                            <?php else: ?>
                                                <small><?php echo esc_html(substr($original_data['message'] ?? '', 0, 150)); ?>...</small>
                                            <?php endif; ?>
                                        </div>
                                        <button type="button" class="button-link view-full-content" 
                                                data-content="<?php echo esc_attr(wp_json_encode($original_data)); ?>">
                                            Ver completo
                                        </button>
                                    </td>
                                    <td>
                                        <span class="threat-level-badge" 
                                              style="background: <?php echo $threat_color[$item->threat_level]; ?>; color: white; padding: 4px 8px; border-radius: 3px; font-size: 11px;">
                                            <?php echo esc_html(strtoupper($item->threat_level)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="threat-reasons">
                                            <?php foreach ($threat_reasons as $reason): ?>
                                                <span class="threat-tag" style="background: #f1f3f4; padding: 2px 6px; margin: 1px; border-radius: 3px; font-size: 11px; display: inline-block;">
                                                    <?php echo esc_html($reason); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($item->user_email): ?>
                                            <strong><?php echo esc_html($item->user_email); ?></strong><br>
                                        <?php endif; ?>
                                        <small>IP: <?php echo esc_html($item->source_ip); ?></small>
                                    </td>
                                    <td>
                                        <?php 
                                        $quarantine_time = strtotime($item->quarantined_at);
                                        echo esc_html(date_i18n(get_option('date_format'), $quarantine_time)); ?><br>
                                        <small><?php echo esc_html(human_time_diff($quarantine_time)); ?> ago</small>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo esc_attr($item->status); ?>">
                                            <?php echo esc_html(ucfirst($item->status)); ?>
                                        </span>
                                        <?php if ($item->admin_notes): ?>
                                            <br><small title="<?php echo esc_attr($item->admin_notes); ?>">📝 Con notas</small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>
        </div>
        
        <!-- Modal para ver contenido completo -->
        <div id="qvc-content-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 999999;">
            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border-radius: 8px; max-width: 80%; max-height: 80%; overflow: auto;">
                <h3>Contenido Completo <button type="button" onclick="document.getElementById('qvc-content-modal').style.display='none'" style="float: right;">×</button></h3>
                <div id="modal-content-display"></div>
            </div>
        </div>
        
        <style>
        .qvc-quarantine-table .threat-level-badge {
            font-weight: bold;
            text-shadow: 1px 1px 1px rgba(0,0,0,0.3);
        }
        
        .quarantine-item[data-threat-level="critical"] {
            background-color: #fdf2f2 !important;
        }
        
        .quarantine-item[data-threat-level="high"] {
            background-color: #fef8f0 !important;
        }
        
        .status-badge {
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .status-quarantined { background: #d63638; color: white; }
        .status-approved { background: #28a745; color: white; }
        .status-rejected { background: #dc3545; color: white; }
        .status-deleted { background: #6c757d; color: white; }
        
        .threat-reasons {
            max-width: 200px;
        }
        
        .threat-tag {
            margin-bottom: 2px;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Mostrar/ocultar sección de notas según la acción seleccionada
            $('#quarantine-action-select').change(function() {
                var action = $(this).val();
                if (action === 'approve' || action === 'reject') {
                    $('#admin-notes-section').show();
                } else {
                    $('#admin-notes-section').hide();
                }
            });
            
            // Select all checkbox
            $('#cb-select-all-quarantine').change(function() {
                $('input[name="item_ids[]"]').prop('checked', $(this).is(':checked'));
            });
            
            // Ver contenido completo
            $('.view-full-content').click(function() {
                var content = JSON.parse($(this).data('content'));
                var display = '';
                
                for (var key in content) {
                    if (content.hasOwnProperty(key)) {
                        display += '<strong>' + key + ':</strong><br>';
                        display += '<div style="background: #f9f9f9; padding: 10px; margin-bottom: 10px; border-left: 3px solid #ddd; white-space: pre-wrap;">';
                        display += content[key];
                        display += '</div>';
                    }
                }
                
                $('#modal-content-display').html(display);
                $('#qvc-content-modal').show();
            });
            
            // Cargar bad words al inicializar
            loadCurrentBadWords();
            loadDefaultBadWords();
        });
        
        // Funciones para gestión de Bad Words
        function showBadWordsTab(tab) {
            $('.badwords-tab-content').hide();
            $('.bad-words-tabs button').removeClass('button-primary').addClass('button');
            
            $('#badwords-tab-' + tab).show();
            $('#tab-' + tab + '-words').removeClass('button').addClass('button-primary');
        }
        
        function loadCurrentBadWords() {
            jQuery.post(ajaxurl, {
                action: 'qvc_get_bad_words',
                nonce: '<?php echo wp_create_nonce('qvc_security_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    var customWords = response.data.custom_words;
                    var allWords = response.data.all_words;
                    
                    // Mostrar en el textarea para edición
                    jQuery('#bad-words-textarea').val(customWords.join(', '));
                    
                    // Mostrar palabras actuales
                    var display = '<strong>Palabras personalizadas (' + customWords.length + '):</strong><br>';
                    if (customWords.length > 0) {
                        display += '<div style="margin: 10px 0;">';
                        customWords.forEach(function(word) {
                            display += '<span style="background: #e74c3c; color: white; padding: 3px 8px; margin: 2px; border-radius: 3px; display: inline-block;">' + word + '</span> ';
                        });
                        display += '</div>';
                    } else {
                        display += '<em>No hay palabras personalizadas. Se usan solo las palabras por defecto.</em><br>';
                    }
                    
                    display += '<br><strong>Total de palabras activas: ' + allWords.length + '</strong>';
                    
                    jQuery('#current-bad-words-display').html(display);
                } else {
                    showBadWordsMessage('Error al cargar bad words: ' + response.data.message, 'error');
                }
            });
        }
        
        function loadDefaultBadWords() {
            jQuery.post(ajaxurl, {
                action: 'qvc_get_bad_words',
                nonce: '<?php echo wp_create_nonce('qvc_security_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    var defaultWords = response.data.default_words;
                    var display = '<strong>Palabras por defecto del sistema (' + defaultWords.length + '):</strong><br>';
                    display += '<div style="margin: 10px 0;">';
                    defaultWords.forEach(function(word) {
                        display += '<span style="background: #3498db; color: white; padding: 3px 8px; margin: 2px; border-radius: 3px; display: inline-block;">' + word + '</span> ';
                    });
                    display += '</div>';
                    display += '<p><small>Estas palabras están optimizadas para detectar phishing, spam y contenido malicioso.</small></p>';
                    
                    jQuery('#default-bad-words-display').html(display);
                }
            });
        }
        
        function saveBadWords() {
            var words = jQuery('#bad-words-textarea').val().trim();
            
            if (!confirm('¿Estás seguro de guardar estos cambios en las bad words?')) {
                return;
            }
            
            jQuery.post(ajaxurl, {
                action: 'qvc_save_bad_words',
                words: words,
                nonce: '<?php echo wp_create_nonce('qvc_security_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    showBadWordsMessage('✅ ' + response.data.message, 'success');
                    loadCurrentBadWords(); // Recargar la vista
                } else {
                    showBadWordsMessage('❌ ' + response.data.message, 'error');
                }
            });
        }
        
        function addDefaultWords() {
            if (!confirm('¿Deseas agregar todas las palabras por defecto a tu lista personalizada?')) {
                return;
            }
            
            jQuery.post(ajaxurl, {
                action: 'qvc_get_bad_words',
                nonce: '<?php echo wp_create_nonce('qvc_security_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    var currentWords = jQuery('#bad-words-textarea').val().trim();
                    var defaultWords = response.data.default_words;
                    
                    var allWords = currentWords ? currentWords + ', ' + defaultWords.join(', ') : defaultWords.join(', ');
                    jQuery('#bad-words-textarea').val(allWords);
                    
                    showBadWordsMessage('✅ Palabras por defecto agregadas. Haz clic en "Guardar Cambios" para confirmar.', 'info');
                }
            });
        }
        
        function resetBadWords() {
            if (!confirm('¿Estás seguro de resetear a las palabras por defecto? Esto eliminará todas tus palabras personalizadas.')) {
                return;
            }
            
            jQuery.post(ajaxurl, {
                action: 'qvc_reset_bad_words',
                nonce: '<?php echo wp_create_nonce('qvc_security_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    showBadWordsMessage('✅ ' + response.data.message, 'success');
                    loadCurrentBadWords(); // Recargar la vista
                    jQuery('#bad-words-textarea').val(''); // Limpiar textarea
                } else {
                    showBadWordsMessage('❌ ' + response.data.message, 'error');
                }
            });
        }
        
        function showBadWordsMessage(message, type) {
            var className = type === 'error' ? 'notice-error' : (type === 'success' ? 'notice-success' : 'notice-info');
            var html = '<div class="notice ' + className + ' is-dismissible"><p>' + message + '</p></div>';
            
            jQuery('#bad-words-messages').html(html);
            
            // Auto-hide after 5 seconds
            setTimeout(function() {
                jQuery('#bad-words-messages').fadeOut();
            }, 5000);
        }
        </script>
                    </tbody>
                </table>
            </form>
            
            <!-- Paginación -->
            <?php if ($total > $per_page): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        $total_pages = ceil($total / $per_page);
                        $pagination_args = array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'total' => $total_pages,
                            'current' => $page,
                            'show_all' => false,
                            'type' => 'plain',
                        );
                        echo paginate_links($pagination_args);
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * AJAX handler para ver email de bandeja general
     */
    public function ajax_view_general_email() {
        check_ajax_referer('qvc_admin_nonce', 'nonce');
        
        $email_id = intval($_POST['email_id']);
        if (!$email_id) {
            wp_send_json_error('Invalid email ID');
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'qvc_general_inbox';
        
        $email = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $email_id
        ), ARRAY_A);
        
        if (!$email) {
            wp_send_json_error('Email not found');
        }
        
        // Format the body for display
        $email['body'] = nl2br(esc_html($email['body']));
        
        wp_send_json_success($email);
    }
    
    /**
     * AJAX handler para actualizar estado de email
     */
    public function ajax_update_general_email_status() {
        check_ajax_referer('qvc_admin_nonce', 'nonce');
        
        $email_id = intval($_POST['email_id']);
        if (!$email_id) {
            wp_send_json_error('Invalid email ID');
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'qvc_general_inbox';
        
        // Verificar que el email existe primero
        $email_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d",
            $email_id
        ));
        
        if (!$email_exists) {
            wp_send_json_error('Email not found');
        }
        
        $update_data = array();
        $update_format = array();
        
        // Update status if provided
        if (isset($_POST['status'])) {
            $status = sanitize_text_field($_POST['status']);
            $valid_statuses = array('unread', 'read', 'assigned', 'processed');
            
            if (in_array($status, $valid_statuses)) {
                $update_data['status'] = $status;
                $update_format[] = '%s';
                
                // If marking as read, set read timestamp
                if ($status === 'read') {
                    $update_data['read_at'] = current_time('mysql');
                    $update_format[] = '%s';
                }
                
                // If marking as processed, set processed timestamp
                if ($status === 'processed') {
                    $update_data['processed_at'] = current_time('mysql');
                    $update_format[] = '%s';
                }
            }
        }
        
        // Update priority if provided
        if (isset($_POST['priority'])) {
            $priority = sanitize_text_field($_POST['priority']);
            $valid_priorities = array('low', 'medium', 'high');
            
            if (in_array($priority, $valid_priorities)) {
                $update_data['priority'] = $priority;
                $update_format[] = '%s';
            }
        }
        
        if (empty($update_data)) {
            wp_send_json_error('No valid data to update');
        }
        
        // Ensure schema has read_at if we're trying to set it
        if (isset($update_data['read_at'])) {
            $col_exists = $wpdb->get_var($wpdb->prepare(
                "SHOW COLUMNS FROM {$table} LIKE %s",
                'read_at'
            ));
            if (!$col_exists) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN read_at datetime NULL AFTER status");
            }
        }

        $result = $wpdb->update(
            $table,
            $update_data,
            array('id' => $email_id),
            $update_format,
            array('%d')
        );
        
        if ($result !== false) {
            // Verificar si realmente se actualizó
            if ($result === 0) {
                // 0 significa que no hubo cambios, pero no es error
                wp_send_json_success('Email status unchanged (already set)');
            } else {
                wp_send_json_success('Email updated successfully');
            }
        } else {
            // Log the actual error
            $error = $wpdb->last_error;
            error_log('QvaClick Debug: AJAX update error: ' . $error);
            wp_send_json_error('Database error: ' . $error);
        }
    }
    
    /**
     * AJAX handler para convertir email a ticket
     */
    public function ajax_convert_email_to_ticket() {
        check_ajax_referer('qvc_admin_nonce', 'nonce');
        
        $email_id = intval($_POST['email_id']);
        if (!$email_id) {
            wp_send_json_error('Invalid email ID');
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'qvc_general_inbox';
        
        // Get email data
        $email = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $email_id
        ), ARRAY_A);
        
        if (!$email) {
            wp_send_json_error('Email not found');
        }
        
        // Create ticket via manager to respect schema and side effects
        $admin_email = QvaClick_Admin_Email_Manager::get_instance();

        // Map priority (general inbox uses low/medium/high; tickets expect low/normal/high/urgent)
        $priority_map = array('low' => 'low', 'medium' => 'normal', 'high' => 'high');
        $ticket_priority = isset($email['priority']) && isset($priority_map[$email['priority']])
            ? $priority_map[$email['priority']]
            : 'normal';

        $user = get_user_by('email', $email['sender_email']);
        $user_id = $user ? intval($user->ID) : 0;
        $user_name = $user ? $user->display_name : ($email['sender_email'] ?: 'Invitado');

        $ticket_data = array(
            'user_id'   => $user_id,
            'user_email'=> $email['sender_email'],
            'user_name' => $user_name,
            'subject'   => $email['subject'],
            'message'   => $email['body'],
            'category'  => !empty($email['category']) ? $email['category'] : 'general',
            'priority'  => $ticket_priority,
        );

        $ticket_id = $admin_email->create_support_ticket($ticket_data);
        
        if ($ticket_id) {
            
            // Mark email as processed
            $wpdb->update(
                $table,
                array(
                    'status' => 'processed',
                    'processed_at' => current_time('mysql')
                ),
                array('id' => $email_id),
                array('%s', '%s'),
                array('%d')
            );
            
            // Create notification for new ticket
            if (class_exists('QvaClick_Notification_System')) {
                QvaClick_Notification_System::notify_new_ticket(
                    $ticket_id,
                    $email['sender_email'],
                    $email['subject']
                );
            }
            
            wp_send_json_success(array(
                'ticket_id' => $ticket_id,
                'message' => 'Email converted to ticket successfully'
            ));
        } else {
            $error = $wpdb->last_error;
            if (empty($error)) { $error = 'Unknown error'; }
            wp_send_json_error('Failed to create ticket: ' . $error);
        }
    }
    
    /**
     * AJAX handler para eliminar ticket
     */
    public function ajax_delete_ticket() {
        check_ajax_referer('qvc_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes');
        }
        
        $ticket_id = sanitize_text_field($_POST['ticket_id']);
        if (!$ticket_id) {
            wp_send_json_error('ID de ticket inválido');
        }
        
        global $wpdb;
        
        // Verificar que el ticket existe
        $tickets_table = $wpdb->prefix . 'qvc_support_tickets';
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tickets_table} WHERE ticket_id = %s",
            $ticket_id
        ));
        
        if (!$ticket) {
            wp_send_json_error('Ticket no encontrado');
        }
        
        // Eliminar mensajes del ticket
        $messages_table = $wpdb->prefix . 'qvc_ticket_messages';
        $wpdb->delete($messages_table, array('ticket_id' => $ticket_id));
        
        // Eliminar el ticket
        $result = $wpdb->delete($tickets_table, array('ticket_id' => $ticket_id));
        
        if ($result) {
            // Log de seguridad
            error_log("[QVC Security] Ticket {$ticket_id} eliminado por usuario " . get_current_user_id());
            
            wp_send_json_success(array(
                'message' => 'Ticket eliminado correctamente',
                'ticket_id' => $ticket_id
            ));
        } else {
            wp_send_json_error('Error al eliminar el ticket');
        }
    }
    
    /**
     * AJAX handler para limpiar tickets resueltos
     */
    public function ajax_clean_resolved_tickets() {
        check_ajax_referer('qvc_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes');
        }
        
        global $wpdb;
        
        $tickets_table = $wpdb->prefix . 'qvc_support_tickets';
        $messages_table = $wpdb->prefix . 'qvc_ticket_messages';
        
        // Obtener tickets resueltos y cerrados más antiguos de 15 días
        // Considerar resolved_at cuando exista, de lo contrario usar updated_at
        $sql = "SELECT ticket_id FROM {$tickets_table} 
             WHERE status IN ('resolved', 'closed') 
             AND (
                 (resolved_at IS NOT NULL AND resolved_at < DATE_SUB(NOW(), INTERVAL 15 DAY))
                 OR (resolved_at IS NULL AND updated_at < DATE_SUB(NOW(), INTERVAL 15 DAY))
             )";

        $old_tickets = $wpdb->get_results($sql);
        
        $deleted_count = 0;
        
        foreach ($old_tickets as $ticket) {
            // Eliminar mensajes
            $wpdb->delete($messages_table, array('ticket_id' => $ticket->ticket_id));
            
            // Eliminar ticket
            $result = $wpdb->delete($tickets_table, array('ticket_id' => $ticket->ticket_id));
            
            if ($result) {
                $deleted_count++;
            }
        }
        
        // Log de la operación
        error_log("[QVC Cleanup] {$deleted_count} tickets antiguos eliminados por usuario " . get_current_user_id());
        
        wp_send_json_success(array(
            'message' => sprintf('Se eliminaron %d tickets resueltos antiguos', $deleted_count),
            'deleted_count' => $deleted_count
        ));
    }
    
    /**
     * AJAX handler para acciones de cuarentena
     */
    public function ajax_quarantine_action() {
        check_ajax_referer('qvc_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes');
        }
        
        if (!class_exists('QvaClick_Ticket_Security_Shield')) {
            wp_send_json_error('Sistema de seguridad no disponible');
        }
        
        $action = sanitize_text_field($_POST['action']);
        $item_id = intval($_POST['item_id']);
        $admin_notes = sanitize_textarea_field($_POST['admin_notes'] ?? '');
        
        $security = QvaClick_Ticket_Security_Shield::get_instance();
        
        switch ($action) {
            case 'approve':
                $result = $security->approve_quarantine_item($item_id, $admin_notes);
                break;
            case 'reject':
                $result = $security->reject_quarantine_item($item_id, $admin_notes);
                break;
            case 'delete':
                $result = $security->delete_quarantine_item($item_id);
                break;
            default:
                wp_send_json_error('Acción no válida');
        }
        
        if ($result) {
            wp_send_json_success(array(
                'message' => 'Acción ejecutada correctamente',
                'action' => $action,
                'item_id' => $item_id
            ));
        } else {
            wp_send_json_error('Error al ejecutar la acción');
        }
    }
    
    /**
     * AJAX handler para aprobar elemento de cuarentena
     */
    public function ajax_approve_quarantine() {
        $this->ajax_quarantine_action();
    }
    
    /**
     * AJAX handler para rechazar elemento de cuarentena
     */
    public function ajax_reject_quarantine() {
        $this->ajax_quarantine_action();
    }
    
    /**
     * AJAX: Obtener bad words
     */
    public function ajax_get_bad_words() {
        check_ajax_referer('qvc_security_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes');
        }
        
        $security_shield = new QvcTicketSecurityShield();
        $default_words = $security_shield->get_default_phishing_words();
        $custom_words = get_option('qvc_security_bad_words', array());
        $all_words = $security_shield->get_bad_words();
        
        wp_send_json_success([
            'default_words' => $default_words,
            'custom_words' => $custom_words,
            'all_words' => $all_words
        ]);
    }
    
    /**
     * AJAX: Guardar bad words
     */
    public function ajax_save_bad_words() {
        check_ajax_referer('qvc_security_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes');
        }
        
        $words = sanitize_textarea_field($_POST['words'] ?? '');
        
        $security_shield = new QvcTicketSecurityShield();
        $result = $security_shield->save_bad_words($words);
        
        if ($result) {
            wp_send_json_success(['message' => 'Bad words guardadas correctamente']);
        } else {
            wp_send_json_error(['message' => 'Error al guardar bad words']);
        }
    }
    
    /**
     * AJAX: Resetear bad words a valores por defecto
     */
    public function ajax_reset_bad_words() {
        check_ajax_referer('qvc_security_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes');
        }
        
        $security_shield = new QvcTicketSecurityShield();
        $result = $security_shield->reset_bad_words_to_default();
        
        if ($result) {
            wp_send_json_success(['message' => 'Bad words reseteadas a valores por defecto']);
        } else {
            wp_send_json_error(['message' => 'Error al resetear bad words']);
        }
    }
}