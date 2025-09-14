<?php
/**
 * Admin Interface Class
 * Gestiona las páginas de administración del plugin
 */

class QvaClick_Email_Admin_Interface {
    
    /**
     * Renderiza la página principal
     */
    public static function render_main_page() {
        $templates = QvaClick_Email_Discovery::discover_email_templates();
        $stats = QvaClick_Email_Discovery::get_templates_stats();
        ?>
        <div class="wrap">
            <h1><?php _e('Email Manager - Dashboard', 'qvaclick-email-manager'); ?></h1>
            
            <div class="qvc-email-dashboard">
                <!-- Stats Cards -->
                <div class="qvc-stats-grid">
                    <div class="qvc-stat-card">
                        <h3><?php echo esc_html($stats['total']); ?></h3>
                        <p><?php _e('Templates Totales', 'qvaclick-email-manager'); ?></p>
                    </div>
                    <div class="qvc-stat-card">
                        <h3><?php echo esc_html($stats['enabled']); ?></h3>
                        <p><?php _e('Activos', 'qvaclick-email-manager'); ?></p>
                    </div>
                    <div class="qvc-stat-card">
                        <h3><?php echo esc_html($stats['with_custom_content']); ?></h3>
                        <p><?php _e('Personalizados', 'qvaclick-email-manager'); ?></p>
                    </div>
                    <div class="qvc-stat-card">
                        <h3><?php echo esc_html($stats['disabled']); ?></h3>
                        <p><?php _e('Desactivados', 'qvaclick-email-manager'); ?></p>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="qvc-quick-actions">
                    <h2><?php _e('Acciones Rápidas', 'qvaclick-email-manager'); ?></h2>
                    <div class="qvc-action-buttons">
                        <a href="<?php echo admin_url('admin.php?page=qvc-email-base-template'); ?>" class="button button-primary button-large">
                            <?php _e('Editar Plantilla Base', 'qvaclick-email-manager'); ?>
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=qvc-email-templates'); ?>" class="button button-secondary button-large">
                            <?php _e('Ver Todos los Emails', 'qvaclick-email-manager'); ?>
                        </a>
                        <button id="qvc-apply-base-all" class="button button-secondary button-large">
                            <?php _e('Aplicar Base a Todos', 'qvaclick-email-manager'); ?>
                        </button>
                        <button id="qvc-export-templates" class="button button-secondary button-large">
                            <?php _e('Exportar JSON', 'qvaclick-email-manager'); ?>
                        </button>
                        <button id="qvc-import-templates" class="button button-secondary button-large">
                            <?php _e('Importar JSON (TEMP)', 'qvaclick-email-manager'); ?>
                        </button>
                        <button id="qvc-sync-exertio" class="button button-primary button-large" style="background: #00a32a;">
                            🔄 <?php _e('Sincronizar con Exertio', 'qvaclick-email-manager'); ?>
                        </button>
                    </div>
                </div>
                
                <!-- Recent Templates -->
                <div class="qvc-recent-templates">
                    <h2><?php _e('Templates Recientes', 'qvaclick-email-manager'); ?></h2>
                    <div class="qvc-templates-preview">
                        <?php foreach (array_slice($templates, 0, 5) as $template): ?>
                        <div class="qvc-template-card">
                            <h4><?php echo esc_html($template['name']); ?></h4>
                            <p class="qvc-template-status <?php echo $template['enabled'] ? 'enabled' : 'disabled'; ?>">
                                <?php echo $template['enabled'] ? __('Activo', 'qvaclick-email-manager') : __('Inactivo', 'qvaclick-email-manager'); ?>
                            </p>
                            <p class="qvc-template-subject"><?php echo esc_html(wp_trim_words($template['subject'], 8)); ?></p>
                            <a href="<?php echo admin_url('admin.php?page=qvc-email-templates&edit=' . $template['base_key']); ?>" class="button button-small">
                                <?php _e('Editar', 'qvaclick-email-manager'); ?>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#qvc-apply-base-all').on('click', function(e) {
                e.preventDefault();
                
                if (!confirm('<?php _e('¿Aplicar la plantilla base a TODOS los templates? Esta acción no se puede deshacer fácilmente.', 'qvaclick-email-manager'); ?>')) {
                    return;
                }
                
                var $btn = $(this);
                $btn.prop('disabled', true).text('<?php _e('Aplicando...', 'qvaclick-email-manager'); ?>');
                
                $.post(ajaxurl, {
                    action: 'qvc_email_apply_base_template',
                    nonce: qvcEmailManager.nonce,
                    base_template: '',  // Se obtendrá del servidor
                    apply_to: 'all'
                }, function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert('Error: ' + (response.data.message || 'Error desconocido'));
                    }
                }).always(function() {
                    $btn.prop('disabled', false).text('<?php _e('Aplicar Base a Todos', 'qvaclick-email-manager'); ?>');
                });
            });

            // Export templates JSON
            $('#qvc-export-templates').on('click', function(e){
                e.preventDefault();
                var $btn = $(this);
                var original = $btn.text();
                $btn.prop('disabled', true).text('<?php echo esc_js(__('Exportando...', 'qvaclick-email-manager')); ?>');
                $.post(ajaxurl, {
                    action: 'qvc_email_export_templates',
                    nonce: qvcEmailManager.nonce
                }).done(function(resp){
                    if(!resp || !resp.success){
                        alert('Error al exportar templates');
                        return;
                    }
                    var data = resp.data || [];
                    var json = JSON.stringify(data, null, 2);
                    var blob = new Blob([json], {type: 'application/json'});
                    var ts = new Date();
                    var pad = n=> (n<10?'0':'')+n;
                    var filename = 'qvc-email-templates-' + ts.getFullYear() + pad(ts.getMonth()+1) + pad(ts.getDate()) + '-' + pad(ts.getHours()) + pad(ts.getMinutes()) + pad(ts.getSeconds()) + '.json';
                    var url = URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    setTimeout(function(){
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);
                    }, 1000);
                }).fail(function(){
                    alert('Error de conexión');
                }).always(function(){
                    $btn.prop('disabled', false).text(original);
                });
            });

            // Import templates JSON (temporal)
            $('#qvc-import-templates').on('click', function(e){
                e.preventDefault();
                var $btn = $(this);
                var input = $('<input type="file" accept="application/json" style="display:none;" />');
                $('body').append(input);
                input.on('change', function(){
                    var file = this.files[0];
                    if(!file){ input.remove(); return; }
                    var reader = new FileReader();
                    reader.onload = function(ev){
                        if(!confirm('<?php echo esc_js(__('Esto sobrescribirá los templates existentes (subject/body). ¿Continuar?', 'qvaclick-email-manager')); ?>')) { input.remove(); return; }
                        $btn.prop('disabled', true).text('<?php echo esc_js(__('Importando...', 'qvaclick-email-manager')); ?>');
                        $.post(ajaxurl, {
                            action: 'qvc_email_import_templates',
                            nonce: qvcEmailManager.nonce,
                            json: ev.target.result
                        }).done(function(resp){
                            if(resp && resp.success){
                                var r = resp.data;
                                alert('Importación OK. Bodies: '+r.updated_bodies+' Subjects nuevos: '+r.created_subjects+' Enabled actualizados: '+r.updated_enabled+' Omitidos: '+r.skipped + (r.errors.length ? '\nErrores: '+r.errors.join(', ') : ''));
                                location.reload();
                            } else {
                                alert('Error al importar: '+ (resp && resp.data && resp.data.message ? resp.data.message : '')); 
                            }
                        }).fail(function(){
                            alert('Error de conexión durante importación');
                        }).always(function(){
                            $btn.prop('disabled', false).text('<?php echo esc_js(__('Importar JSON (TEMP)', 'qvaclick-email-manager')); ?>');
                        });
                    };
                    reader.readAsText(file);
                }).click();
            });

            // Sincronización con Exertio Framework
            $('#qvc-sync-exertio').on('click', function(e) {
                e.preventDefault();
                
                if (!confirm('¿Sincronizar las plantillas de QvaClick con Exertio Framework?\n\nEsto copiará las plantillas de servicios para que Exertio pueda enviar emails.')) {
                    return;
                }
                
                var $btn = $(this);
                var originalText = $btn.text();
                $btn.prop('disabled', true).text('🔄 Sincronizando...');
                
                $.post(ajaxurl, {
                    action: 'qvc_email_sync_exertio',
                    nonce: qvcEmailManager.nonce
                }, function(response) {
                    if (response && response.success) {
                        alert('✅ Sincronización exitosa!\n\n' + response.data.message);
                        if (response.data.reload) {
                            location.reload();
                        }
                    } else {
                        alert('❌ Error en la sincronización:\n' + (response.data ? response.data.message : 'Error desconocido'));
                    }
                }).fail(function() {
                    alert('❌ Error de conexión durante la sincronización');
                }).always(function() {
                    $btn.prop('disabled', false).text(originalText);
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Renderiza la página de plantilla base
     */
    public static function render_base_template_page() {
        $base_template = QvaClick_Base_Template_Manager::get_base_template();
        $templates = QvaClick_Email_Discovery::discover_email_templates();
        
        // Manejar guardado
        if (isset($_POST['save_base_template']) && wp_verify_nonce($_POST['qvc_nonce'], 'save_base_template')) {
            $new_template = wp_unslash($_POST['base_template']);
            if (QvaClick_Base_Template_Manager::save_base_template($new_template)) {
                echo '<div class="notice notice-success"><p>' . __('Plantilla base guardada exitosamente.', 'qvaclick-email-manager') . '</p></div>';
                $base_template = $new_template;
            }
        }
        
        // Manejar aplicación a templates seleccionados
        if (isset($_POST['apply_to_selected']) && wp_verify_nonce($_POST['qvc_nonce'], 'apply_base_template')) {
            $selected_templates = isset($_POST['selected_templates']) ? $_POST['selected_templates'] : array();
            if (!empty($selected_templates)) {
                $result = QvaClick_Base_Template_Manager::apply_to_templates($base_template, $selected_templates);
                if ($result['success']) {
                    echo '<div class="notice notice-success"><p>' . esc_html($result['message']) . '</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>' . __('Error al aplicar plantilla.', 'qvaclick-email-manager') . '</p></div>';
                }
            }
        }
        ?>
        <div class="wrap">
            <h1><?php _e('Plantilla Base', 'qvaclick-email-manager'); ?></h1>
            
            <div class="qvc-base-template-editor">
                <div class="qvc-editor-container">
                    <form method="post" action="">
                        <?php wp_nonce_field('save_base_template', 'qvc_nonce'); ?>
                        
                        <div class="qvc-editor-section">
                            <h2><?php _e('Editor de Plantilla Base', 'qvaclick-email-manager'); ?></h2>
                            <p class="description">
                                <?php _e('Esta plantilla se usará como base para todos los emails. Use {{CONTENT}} donde quiere que aparezca el contenido específico de cada email.', 'qvaclick-email-manager'); ?>
                            </p>
                            
                            <?php
                            wp_editor($base_template, 'base_template', array(
                                'textarea_name' => 'base_template',
                                'media_buttons' => true,
                                'textarea_rows' => 20,
                                'teeny' => false,
                                'tinymce' => array(
                                    'theme_advanced_buttons1' => 'formatselect,|,bold,italic,underline,|,bullist,numlist,blockquote,|,justifyleft,justifycenter,justifyright,justifyfull,|,link,unlink,|,spellchecker,fullscreen,|,help',
                                    'theme_advanced_toolbar_location' => 'top',
                                    'theme_advanced_toolbar_align' => 'left',
                                    'theme_advanced_statusbar_location' => 'bottom',
                                    'theme_advanced_resizing' => true,
                                    'theme_advanced_resize_horizontal' => false,
                                    'dialog_type' => 'modal',
                                    'theme_advanced_resizing_use_cookie' => true
                                )
                            ));
                            ?>
                            
                            <p class="submit">
                                <input type="submit" name="save_base_template" class="button-primary" value="<?php _e('Guardar Plantilla Base', 'qvaclick-email-manager'); ?>" />
                                <button type="button" id="qvc-preview-base" class="button"><?php _e('Vista Previa', 'qvaclick-email-manager'); ?></button>
                            </p>
                        </div>
                    </form>
                </div>
                
                <div class="qvc-apply-section">
                    <h2><?php _e('Aplicar a Templates', 'qvaclick-email-manager'); ?></h2>
                    <form method="post" action="">
                        <?php wp_nonce_field('apply_base_template', 'qvc_nonce'); ?>
                        
                        <div class="qvc-templates-checklist">
                            <p><input type="checkbox" id="select-all-templates"> <label for="select-all-templates"><strong><?php _e('Seleccionar Todos', 'qvaclick-email-manager'); ?></strong></label></p>
                            
                            <?php foreach ($templates as $base_key => $template): ?>
                            <p>
                                <input type="checkbox" name="selected_templates[]" value="<?php echo esc_attr($base_key); ?>" id="template-<?php echo esc_attr($base_key); ?>">
                                <label for="template-<?php echo esc_attr($base_key); ?>">
                                    <?php echo esc_html($template['name']); ?>
                                    <span class="qvc-template-status <?php echo $template['enabled'] ? 'enabled' : 'disabled'; ?>">
                                        (<?php echo $template['enabled'] ? __('Activo', 'qvaclick-email-manager') : __('Inactivo', 'qvaclick-email-manager'); ?>)
                                    </span>
                                </label>
                            </p>
                            <?php endforeach; ?>
                        </div>
                        
                        <p class="submit">
                            <input type="submit" name="apply_to_selected" class="button-secondary" value="<?php _e('Aplicar a Seleccionados', 'qvaclick-email-manager'); ?>" 
                                   onclick="return confirm('<?php _e('¿Aplicar la plantilla base a los templates seleccionados?', 'qvaclick-email-manager'); ?>')" />
                        </p>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Preview Modal -->
        <div id="qvc-preview-modal" style="display: none;">
            <div class="qvc-modal-content">
                <span class="qvc-modal-close">&times;</span>
                <h2><?php _e('Vista Previa de la Plantilla Base', 'qvaclick-email-manager'); ?></h2>
                <div id="qvc-preview-content"></div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Select all functionality
            $('#select-all-templates').on('change', function() {
                $('input[name="selected_templates[]"]').prop('checked', this.checked);
            });
            
            // Preview functionality
            $('#qvc-preview-base').on('click', function(e) {
                e.preventDefault();
                
                var baseTemplate = '';
                if (typeof tinyMCE !== 'undefined' && tinyMCE.get('base_template')) {
                    baseTemplate = tinyMCE.get('base_template').getContent();
                } else {
                    baseTemplate = $('#base_template').val();
                }
                
                $.post(ajaxurl, {
                    action: 'qvc_email_preview_template',
                    nonce: qvcEmailManager.nonce,
                    template_content: baseTemplate,
                    preview_data: {}
                }, function(response) {
                    if (response.success) {
                        $('#qvc-preview-content').html(response.data.preview);
                        $('#qvc-preview-modal').fadeIn();
                    }
                });
            });
            
            // Modal close
            $('.qvc-modal-close, #qvc-preview-modal').on('click', function(e) {
                if (e.target === this) {
                    $('#qvc-preview-modal').fadeOut();
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Renderiza la página de lista de templates
     */
    public static function render_templates_list_page() {
        $templates = QvaClick_Email_Discovery::discover_email_templates();
        
        // Check if we're editing a specific template
        if (isset($_GET['edit'])) {
            self::render_template_editor($_GET['edit']);
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php _e('Lista de Templates de Email', 'qvaclick-email-manager'); ?></h1>
            
            <div class="qvc-templates-list">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col"><?php _e('Nombre', 'qvaclick-email-manager'); ?></th>
                            <th scope="col"><?php _e('Asunto', 'qvaclick-email-manager'); ?></th>
                            <th scope="col"><?php _e('Estado', 'qvaclick-email-manager'); ?></th>
                            <th scope="col"><?php _e('Placeholders', 'qvaclick-email-manager'); ?></th>
                            <th scope="col"><?php _e('Última Modificación', 'qvaclick-email-manager'); ?></th>
                            <th scope="col"><?php _e('Acciones', 'qvaclick-email-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($templates as $base_key => $template): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($template['name']); ?></strong>
                                <div class="row-actions">
                                    <span class="edit">
                                        <a href="<?php echo admin_url('admin.php?page=qvc-email-templates&edit=' . $base_key); ?>">
                                            <?php _e('Editar', 'qvaclick-email-manager'); ?>
                                        </a>
                                    </span>
                                </div>
                            </td>
                            <td><?php echo esc_html(wp_trim_words($template['subject'], 8)); ?></td>
                            <td>
                                <span class="qvc-status-badge <?php echo $template['enabled'] ? 'enabled' : 'disabled'; ?>">
                                    <?php echo $template['enabled'] ? __('Activo', 'qvaclick-email-manager') : __('Inactivo', 'qvaclick-email-manager'); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($template['placeholders'])): ?>
                                    <span class="qvc-placeholders">
                                        <?php 
                                        // Solo mostrar nombres de placeholders sin HTML
                                        $clean_placeholders = array();
                                        foreach (array_slice($template['placeholders'], 0, 3) as $placeholder) {
                                            $clean_placeholders[] = strip_tags($placeholder);
                                        }
                                        echo implode(', ', array_map('esc_html', $clean_placeholders)); 
                                        ?>
                                        <?php if (count($template['placeholders']) > 3): ?>
                                            <span class="qvc-more-placeholders">+<?php echo (count($template['placeholders']) - 3); ?></span>
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="qvc-no-placeholders"><?php _e('Sin placeholders', 'qvaclick-email-manager'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(date('d/m/Y H:i', strtotime($template['last_modified']))); ?></td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=qvc-email-templates&edit=' . $base_key); ?>" class="button button-small">
                                    <?php _e('Editar', 'qvaclick-email-manager'); ?>
                                </a>
                                <button class="button button-small qvc-preview-template" data-template="<?php echo esc_attr($base_key); ?>">
                                    <?php _e('Preview', 'qvaclick-email-manager'); ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <?php
        // Añadir sección: Emails por Hook (qvc_hook_emails)
        global $wpdb;
        $hook_emails_table = $wpdb->prefix . 'qvc_hook_emails';
    $hook_emails = $wpdb->get_results("SELECT id, name, subject, content, status, updated_at FROM {$hook_emails_table} ORDER BY id DESC");
        $seen = get_user_meta(get_current_user_id(), 'qvc_seen_hook_email_ids', true);
        if (!is_array($seen)) { $seen = array(); }
        ?>
        <div class="wrap" style="margin-top: 24px;">
            <h2 style="display:flex;align-items:center;gap:8px;">
                <?php _e('Emails por Hook', 'qvaclick-email-manager'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=qvc-hook-discovery#hook-emails')); ?>" class="button button-secondary"><?php _e('Crear nuevo', 'qvaclick-email-manager'); ?></a>
            </h2>
            <?php if (!empty($hook_emails)): ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col"><?php _e('Nombre', 'qvaclick-email-manager'); ?></th>
                        <th scope="col"><?php _e('Asunto', 'qvaclick-email-manager'); ?></th>
                        <th scope="col"><?php _e('Estado', 'qvaclick-email-manager'); ?></th>
                        <th scope="col"><?php _e('Placeholders', 'qvaclick-email-manager'); ?></th>
                        <th scope="col"><?php _e('Última Modificación', 'qvaclick-email-manager'); ?></th>
                        <th scope="col"><?php _e('Acciones', 'qvaclick-email-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($hook_emails as $he): ?>
                    <?php $is_new = !in_array(intval($he->id), $seen, true); ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($he->name); ?></strong>
                            <?php if ($is_new): ?>
                                <span class="qvc-new-badge" style="margin-left:6px;background:#d63638;color:#fff;border-radius:4px;padding:2px 6px;font-size:11px;vertical-align:middle;"><?php _e('Nuevo', 'qvaclick-email-manager'); ?></span>
                            <?php endif; ?>
                            <div class="row-actions">
                                <span class="edit">
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=qvaclick-email-hooks&id=' . intval($he->id) . '&mark_seen=1')); ?>">
                                        <?php _e('Editar', 'qvaclick-email-manager'); ?>
                                    </a>
                                </span>
                            </div>
                        </td>
                        <td><?php echo esc_html(wp_trim_words($he->subject, 10)); ?></td>
                        <td>
                            <?php 
                            $status = strtolower($he->status);
                            $enabled = ($status === 'active' || $status === '1');
                            ?>
                            <span class="qvc-status-badge <?php echo $enabled ? 'enabled' : 'disabled'; ?>">
                                <?php 
                                    if ($status === 'draft') { _e('Borrador', 'qvaclick-email-manager'); }
                                    else { echo $enabled ? __('Activo', 'qvaclick-email-manager') : __('Inactivo', 'qvaclick-email-manager'); }
                                ?>
                            </span>
                        </td>
                        <td>
                            <?php 
                            $text = (string)($he->subject . ' ' . $he->content);
                            $placeholders = array();
                            if (preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $text, $m1)) {
                                foreach ($m1[1] as $ph) { $placeholders[$ph] = true; }
                            }
                            if (preg_match_all('/%([a-zA-Z0-9_]+)%/', $text, $m2)) {
                                foreach ($m2[1] as $ph) { $placeholders[$ph] = true; }
                            }
                            $ph_list = array_keys($placeholders);
                            if (!empty($ph_list)) {
                                $clean = array();
                                foreach (array_slice($ph_list, 0, 3) as $ph) { $clean[] = esc_html($ph); }
                                echo implode(', ', $clean);
                                if (count($ph_list) > 3) {
                                    echo ' <span class="qvc-more-placeholders">+' . (count($ph_list) - 3) . '</span>';
                                }
                            } else {
                                echo '<span class="qvc-no-placeholders">' . esc_html__('Sin placeholders', 'qvaclick-email-manager') . '</span>';
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html(date('d/m/Y H:i', strtotime($he->updated_at ?: 'now'))); ?></td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=qvaclick-email-hooks&id=' . intval($he->id) . '&mark_seen=1')); ?>" class="button button-small">
                                <?php _e('Editar', 'qvaclick-email-manager'); ?>
                            </a>
                            <button class="button button-small qvc-preview-hook" data-hook-id="<?php echo esc_attr($he->id); ?>">
                                <?php _e('Preview', 'qvaclick-email-manager'); ?>
                            </button>
                            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=qvc-hook-discovery#hook-emails')); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js(__('¿Eliminar este email por hook? Esta acción no se puede deshacer.', 'qvaclick-email-manager')); ?>');">
                                <?php wp_nonce_field('qvaclick_hook_action', 'qvaclick_nonce'); ?>
                                <input type="hidden" name="action" value="delete_hook_email" />
                                <input type="hidden" name="hook_email_id" value="<?php echo intval($he->id); ?>" />
                                <button type="submit" class="button button-small" style="color:#b32d2e;"><?php _e('Eliminar', 'qvaclick-email-manager'); ?></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p class="description"><?php _e('Aún no hay emails por hook. Crea uno nuevo para empezar.', 'qvaclick-email-manager'); ?></p>
            <?php endif; ?>
        </div>
        
        <!-- Preview Modal for Templates List -->
        <div id="qvc-preview-modal" style="display: none;">
            <div class="qvc-modal-content">
                <span class="qvc-modal-close">&times;</span>
                <h2><?php _e('Vista Previa del Template', 'qvaclick-email-manager'); ?></h2>
                <div id="qvc-preview-content"></div>
                <div class="qvc-test-send">
                    <hr />
                    <h3><?php _e('Enviar email de prueba', 'qvaclick-email-manager'); ?></h3>
                    <p>
                        <input type="email" id="qvc-test-email" class="regular-text" placeholder="email@ejemplo.com" />
                        <button id="qvc-send-test" class="button button-primary" disabled><?php _e('Enviar test', 'qvaclick-email-manager'); ?></button>
                        <span id="qvc-test-result" style="margin-left:10px;"></span>
                    </p>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Preview template functionality for templates list
            $('.qvc-preview-template').on('click', function(e) {
                e.preventDefault();
                
                var templateKey = $(this).data('template');
                var $button = $(this);
                var originalText = $button.text();
                
                $button.text('<?php _e('Cargando...', 'qvaclick-email-manager'); ?>').prop('disabled', true);
                // store current template key on modal for test sending and clear hook id
                $('#qvc-preview-modal').data('templateKey', templateKey);
                $('#qvc-preview-modal').removeData('hookEmailId');

                $.post(window.ajaxurl || '<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'qvc_email_preview_specific_template',
                    nonce: window.qvcEmailManager ? window.qvcEmailManager.nonce : '<?php echo wp_create_nonce('qvc_email_nonce'); ?>',
                    template_key: templateKey
                })
                .done(function(response) {
                    if (response.success) {
                        $('#qvc-preview-content').html(response.data.preview);
                        $('#qvc-preview-modal').fadeIn(300);
                        $('body').addClass('qvc-modal-open');
            // Enable send button if email looks valid
            var emailVal = $('#qvc-test-email').val();
            $('#qvc-send-test').prop('disabled', !/^\S+@\S+\.\S+$/.test(emailVal));
                    } else {
                        alert('Error al generar vista previa: ' + (response.data ? response.data.message : 'Error desconocido'));
                    }
                })
                .fail(function(xhr, status, error) {
                    console.error('Error AJAX:', error);
                    alert('Error de conexión al servidor');
                })
                .always(function() {
                    $button.text(originalText).prop('disabled', false);
                });
            });

            // Preview hook email functionality for hooks list
            $('.qvc-preview-hook').on('click', function(e) {
                e.preventDefault();
                var id = $(this).data('hook-id');
                var $button = $(this);
                var originalText = $button.text();
                $button.text('<?php _e('Cargando...', 'qvaclick-email-manager'); ?>').prop('disabled', true);
                // store current hook id on modal for test sending and clear template key
                $('#qvc-preview-modal').data('hookEmailId', id);
                $('#qvc-preview-modal').removeData('templateKey');

                $.post(window.ajaxurl || '<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'qvc_hook_email_preview',
                    nonce: window.qvcEmailManager ? window.qvcEmailManager.nonce : '<?php echo wp_create_nonce('qvc_email_nonce'); ?>',
                    hook_email_id: id
                })
                .done(function(response) {
                    if (response && response.success) {
                        $('#qvc-preview-content').html(response.data.preview);
                        $('#qvc-preview-modal').fadeIn(300);
                        $('body').addClass('qvc-modal-open');
                        var emailVal = $('#qvc-test-email').val();
                        $('#qvc-send-test').prop('disabled', !/^\S+@\S+\.\S+$/.test(emailVal));
                    } else {
                        alert('Error al generar vista previa: ' + (response && response.data && response.data.message ? response.data.message : 'Error desconocido'));
                    }
                })
                .fail(function() {
                    alert('Error de conexión al servidor');
                })
                .always(function() {
                    $button.text(originalText).prop('disabled', false);
                });
            });

            // Validate email input and toggle button
            $(document).on('input', '#qvc-test-email', function() {
                var email = $(this).val();
                var valid = /^\S+@\S+\.\S+$/.test(email);
                $('#qvc-send-test').prop('disabled', !valid);
                $('#qvc-test-result').text('');
            });

            // Send test email (template or hook)
            $(document).on('click', '#qvc-send-test', function(e) {
                e.preventDefault();
                var email = $('#qvc-test-email').val();
                if (!email) return;
                var templateKey = $('#qvc-preview-modal').data('templateKey');
                var hookId = $('#qvc-preview-modal').data('hookEmailId');
                var $btn = $(this);
                var original = $btn.text();
                $btn.prop('disabled', true).text('<?php echo esc_js(__('Enviando...', 'qvaclick-email-manager')); ?>');
                $('#qvc-test-result').text('');
                var ajaxData = {
                    nonce: window.qvcEmailManager ? window.qvcEmailManager.nonce : '<?php echo wp_create_nonce('qvc_email_nonce'); ?>',
                    test_email: email
                };
                var action = '';
                if (hookId) {
                    action = 'qvc_hook_email_send_test';
                    ajaxData.action = action;
                    ajaxData.hook_email_id = hookId;
                } else if (templateKey) {
                    action = 'qvc_email_send_test';
                    ajaxData.action = action;
                    ajaxData.template_key = templateKey;
                } else {
                    // nothing to send
                    $btn.prop('disabled', false).text(original);
                    return;
                }

                $.post(window.ajaxurl || '<?php echo admin_url('admin-ajax.php'); ?>', ajaxData)
                .done(function(resp){
                    if (resp && resp.success) {
                        $('#qvc-test-result').css('color','#2c7').text(resp.data.message || '<?php echo esc_js(__('Enviado', 'qvaclick-email-manager')); ?>');
                    } else {
                        $('#qvc-test-result').css('color','#d33').text((resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js(__('Error al enviar', 'qvaclick-email-manager')); ?>');
                    }
                })
                .fail(function(){
                    $('#qvc-test-result').css('color','#d33').text('<?php echo esc_js(__('Error de conexión', 'qvaclick-email-manager')); ?>');
                })
                .always(function(){
                    $btn.prop('disabled', false).text(original);
                });
            });
            
            // Modal close functionality
            $('.qvc-modal-close, #qvc-preview-modal').on('click', function(e) {
                if (e.target === this) {
                    $('#qvc-preview-modal').fadeOut(300);
                    $('body').removeClass('qvc-modal-open');
                }
            });
            
            // ESC key to close modal
            $(document).on('keyup', function(e) {
                if (e.keyCode === 27) { // ESC key
                    $('#qvc-preview-modal').fadeOut(300);
                    $('body').removeClass('qvc-modal-open');
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Renderiza el editor individual de templates
     */
    public static function render_template_editor($template_key) {
        $templates = QvaClick_Email_Discovery::discover_email_templates();
        
        if (!isset($templates[$template_key])) {
            wp_die(__('Template no encontrado.', 'qvaclick-email-manager'));
        }
        
        $template = $templates[$template_key];
        // No necesitamos cargar todas las opciones aquí, ya tenemos los datos del template
        
        // Manejar guardado
        if (isset($_POST['save_template']) && wp_verify_nonce($_POST['qvc_nonce'], 'save_template')) {
            $success = true;
            $messages = array();
            
            // Guardar switch (si existe)
            if (isset($template['switch_key'])) {
                $new_enabled = isset($_POST['template_enabled']) ? 1 : 0;
                if (QvaClick_Email_Discovery::save_template_option($template['switch_key'], $new_enabled)) {
                    $messages[] = __('Estado del template actualizado.', 'qvaclick-email-manager');
                } else {
                    $success = false;
                }
            }
            
            // Guardar asunto (existente o crear nuevo)
            $new_subject = sanitize_text_field($_POST['template_subject']);
            if (!empty($new_subject)) {
                if (isset($template['subject_key'])) {
                    // Actualizar asunto existente
                    if (QvaClick_Email_Discovery::save_template_option($template['subject_key'], $new_subject)) {
                        $messages[] = __('Asunto actualizado.', 'qvaclick-email-manager');
                    } else {
                        $success = false;
                    }
                } elseif (isset($_POST['create_subject_key'])) {
                    // Crear nueva clave de asunto
                    $new_subject_key = $template['body_key'] ? preg_replace('/(_body|_message|_template)$/i', '_sub', $template['body_key']) : $template_key . '_sub';
                    
                    if (QvaClick_Email_Discovery::save_template_option($new_subject_key, $new_subject)) {
                        $messages[] = __('Asunto creado y guardado.', 'qvaclick-email-manager');
                        $template['subject_key'] = $new_subject_key; // Actualizar para la próxima carga
                        $template['subject'] = $new_subject;
                    } else {
                        $success = false;
                    }
                }
            }
            
            // Guardar cuerpo
            if (isset($template['body_key'])) {
                $new_body = wp_unslash($_POST['template_body']);
                if (QvaClick_Email_Discovery::save_template_option($template['body_key'], $new_body)) {
                    $messages[] = __('Contenido actualizado.', 'qvaclick-email-manager');
                } else {
                    $success = false;
                }
            }
            
            if ($success) {
                echo '<div class="notice notice-success"><p>' . implode(' ', $messages) . '</p></div>';
                // Recargar template data
                $templates = QvaClick_Email_Discovery::discover_email_templates();
                $template = $templates[$template_key];
            } else {
                echo '<div class="notice notice-error"><p>' . __('Error al guardar algunos cambios.', 'qvaclick-email-manager') . '</p></div>';
            }
        }
        ?>
        <div class="wrap">
            <h1>
                <?php _e('Editar Template:', 'qvaclick-email-manager'); ?> 
                <span class="qvc-template-title"><?php echo esc_html($template['name']); ?></span>
                <a href="<?php echo admin_url('admin.php?page=qvc-email-templates'); ?>" class="button">
                    <?php _e('← Volver a la Lista', 'qvaclick-email-manager'); ?>
                </a>
            </h1>
            
            <div class="qvc-template-editor">
                <div class="qvc-editor-main">
                    <form method="post" action="">
                        <?php wp_nonce_field('save_template', 'qvc_nonce'); ?>
                        
                        <!-- Template Info -->
                        <div class="qvc-template-info">
                            <h2><?php _e('Información del Template', 'qvaclick-email-manager'); ?></h2>
                            
                            <?php if (isset($template['switch_key'])): ?>
                            <p>
                                <label>
                                    <input type="checkbox" name="template_enabled" value="1" <?php checked($template['enabled']); ?>>
                                    <?php _e('Template Activo', 'qvaclick-email-manager'); ?>
                                </label>
                            </p>
                            <?php endif; ?>
                            
                            <!-- Campo de asunto - SIEMPRE visible -->
                            <p>
                                <label for="template_subject"><?php _e('Asunto del Email:', 'qvaclick-email-manager'); ?></label>
                                <input type="text" id="template_subject" name="template_subject" 
                                       value="<?php echo esc_attr($template['subject']); ?>" 
                                       class="regular-text" style="width: 100%;"
                                       placeholder="<?php _e('Ingresa el asunto del email...', 'qvaclick-email-manager'); ?>">
                                <?php if (empty($template['subject_key'])): ?>
                                    <input type="hidden" name="create_subject_key" value="1">
                                    <small class="description" style="color: #d63638;">
                                        <?php _e('⚠ Este template no tiene asunto configurado. Se creará automáticamente al guardar.', 'qvaclick-email-manager'); ?>
                                    </small>
                                <?php else: ?>
                                    <small class="description">
                                        <?php printf(__('Clave: %s', 'qvaclick-email-manager'), '<code>' . esc_html($template['subject_key']) . '</code>'); ?>
                                    </small>
                                <?php endif; ?>
                            </p>
                        </div>
                        
                        <!-- Template Body -->
                        <div class="qvc-template-body">
                            <h2><?php _e('Contenido del Email', 'qvaclick-email-manager'); ?></h2>
                            
                            <?php if (isset($template['body_key'])): ?>
                                <?php
                                wp_editor($template['body'], 'template_body', array(
                                    'textarea_name' => 'template_body',
                                    'media_buttons' => true,
                                    'textarea_rows' => 20,
                                    'teeny' => false,
                                    'tinymce' => array(
                                        'theme_advanced_buttons1' => 'formatselect,|,bold,italic,underline,|,bullist,numlist,blockquote,|,justifyleft,justifycenter,justifyright,justifyfull,|,link,unlink,|,spellchecker,fullscreen,|,help',
                                        'theme_advanced_toolbar_location' => 'top',
                                        'theme_advanced_toolbar_align' => 'left',
                                        'theme_advanced_statusbar_location' => 'bottom',
                                        'theme_advanced_resizing' => true,
                                        'theme_advanced_resize_horizontal' => false,
                                        'dialog_type' => 'modal',
                                        'theme_advanced_resizing_use_cookie' => true
                                    )
                                ));
                                ?>
                            <?php else: ?>
                                <p class="description"><?php _e('Este template no tiene contenido de cuerpo editable.', 'qvaclick-email-manager'); ?></p>
                            <?php endif; ?>
                        </div>
                        
                        <p class="submit">
                            <input type="submit" name="save_template" class="button-primary" value="<?php _e('Guardar Cambios', 'qvaclick-email-manager'); ?>" />
                            <button type="button" id="qvc-preview-current" class="button"><?php _e('Vista Previa', 'qvaclick-email-manager'); ?></button>
                            <button type="button" id="qvc-apply-base-current" class="button"><?php _e('Aplicar Plantilla Base', 'qvaclick-email-manager'); ?></button>
                        </p>
                    </form>
                </div>
                
                <div class="qvc-editor-sidebar">
                    <!-- Placeholders Available -->
                    <div class="qvc-placeholders-info">
                        <h3><?php _e('Placeholders Disponibles', 'qvaclick-email-manager'); ?></h3>
                        <?php if (!empty($template['placeholders']) && is_array($template['placeholders'])): ?>
                            <ul class="qvc-placeholders-list">
                                <?php foreach ($template['placeholders'] as $placeholder): ?>
                                <li>
                                    <code class="qvc-placeholder-item" data-placeholder="<?php echo esc_attr($placeholder); ?>">
                                        <?php echo esc_html($placeholder); ?>
                                    </code>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <p class="description"><?php _e('Haz clic en un placeholder para copiarlo al editor.', 'qvaclick-email-manager'); ?></p>
                        <?php else: ?>
                            <p class="description"><?php _e('No se encontraron placeholders en este template.', 'qvaclick-email-manager'); ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Test Send -->
                    <div class="qvc-test-send-sidebar">
                        <h3><?php _e('Enviar email de prueba', 'qvaclick-email-manager'); ?></h3>
                        <p>
                            <input type="email" id="qvc-test-email-editor" class="regular-text" placeholder="email@ejemplo.com" />
                            <button type="button" id="qvc-send-test-editor" class="button button-primary" disabled><?php _e('Enviar test', 'qvaclick-email-manager'); ?></button>
                            <span id="qvc-test-result-editor" style="margin-left:10px;"></span>
                        </p>
                        <small class="description"><?php _e('Si el correo pertenece a un usuario registrado, se usarán sus datos para rellenar variables (nombre, email, etc.).', 'qvaclick-email-manager'); ?></small>
                    </div>

                    <!-- Template Statistics -->
                    <div class="qvc-template-stats">
                        <h3><?php _e('Estadísticas', 'qvaclick-email-manager'); ?></h3>
                        <ul>
                            <li><?php _e('Estado:', 'qvaclick-email-manager'); ?> 
                                <span class="qvc-status-badge <?php echo $template['enabled'] ? 'enabled' : 'disabled'; ?>">
                                    <?php echo $template['enabled'] ? __('Activo', 'qvaclick-email-manager') : __('Inactivo', 'qvaclick-email-manager'); ?>
                                </span>
                            </li>
                            <li><?php _e('Placeholders:', 'qvaclick-email-manager'); ?> <?php echo count($template['placeholders'] ?? array()); ?></li>
                            <li><?php _e('Última modificación:', 'qvaclick-email-manager'); ?> <?php echo esc_html($template['last_modified']); ?></li>
                            <li><?php _e('Clave base:', 'qvaclick-email-manager'); ?> <code><?php echo esc_html($template_key); ?></code></li>
                        </ul>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="qvc-quick-actions-sidebar">
                        <h3><?php _e('Acciones Rápidas', 'qvaclick-email-manager'); ?></h3>
                        <p>
                            <button type="button" id="qvc-reset-template" class="button button-small" data-template="<?php echo esc_attr($template_key); ?>">
                                <?php _e('Restaurar Original', 'qvaclick-email-manager'); ?>
                            </button>
                        </p>
                        <p>
                            <button type="button" id="qvc-duplicate-template" class="button button-small" data-template="<?php echo esc_attr($template_key); ?>">
                                <?php _e('Duplicar Template', 'qvaclick-email-manager'); ?>
                            </button>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Preview Modal -->
        <div id="qvc-preview-modal" style="display: none;">
            <div class="qvc-modal-content">
                <span class="qvc-modal-close">&times;</span>
                <h2><?php _e('Vista Previa del Template', 'qvaclick-email-manager'); ?></h2>
                <div id="qvc-preview-content"></div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Preview current template
            $('#qvc-preview-current').on('click', function(e) {
                e.preventDefault();
                
                var subject = $('#template_subject').val() || '';
                var body = '';
                if (typeof tinyMCE !== 'undefined' && tinyMCE.get('template_body')) {
                    body = tinyMCE.get('template_body').getContent();
                } else {
                    body = $('#template_body').val() || '';
                }
                
                $.post(window.ajaxurl || '<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'qvc_email_preview_individual_template',
                    nonce: window.qvcEmailManager ? window.qvcEmailManager.nonce : '<?php echo wp_create_nonce('qvc_email_nonce'); ?>',
                    template_key: '<?php echo esc_js($template_key); ?>',
                    template_subject: subject,
                    template_body: body
                })
                .done(function(response) {
                    if (response.success) {
                        $('#qvc-preview-content').html(response.data.preview);
                        $('#qvc-preview-modal').fadeIn();
                        $('body').addClass('qvc-modal-open');
                    } else {
                        alert('Error al generar vista previa');
                    }
                })
                .fail(function() {
                    alert('Error de conexión');
                });
            });
            
            // Apply base template to current
            $('#qvc-apply-base-current').on('click', function(e) {
                e.preventDefault();
                
                if (!confirm('<?php _e('¿Aplicar la plantilla base a este template?', 'qvaclick-email-manager'); ?>')) {
                    return;
                }
                
                $.post(window.ajaxurl || '<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'qvc_email_apply_base_template',
                    nonce: window.qvcEmailManager ? window.qvcEmailManager.nonce : '<?php echo wp_create_nonce('qvc_email_nonce'); ?>',
                    base_template: '',
                    apply_to: ['<?php echo esc_js($template_key); ?>']
                })
                .done(function(response) {
                    if (response.success) {
                        alert(response.data.message || 'Plantilla base aplicada exitosamente');
                        location.reload();
                    } else {
                        alert('Error al aplicar plantilla base: ' + (response.data ? response.data.message : 'Error desconocido'));
                    }
                })
                .fail(function() {
                    alert('Error de conexión');
                });
            });
            
            // Placeholder click to copy
            $('.qvc-placeholder-item').on('click', function() {
                var placeholder = $(this).data('placeholder');
                
                if (typeof tinyMCE !== 'undefined' && tinyMCE.get('template_body')) {
                    tinyMCE.get('template_body').execCommand('mceInsertContent', false, placeholder + ' ');
                } else {
                    var $editor = $('#template_body');
                    var cursorPos = $editor.prop('selectionStart');
                    var textBefore = $editor.val().substring(0, cursorPos);
                    var textAfter = $editor.val().substring(cursorPos);
                    $editor.val(textBefore + placeholder + ' ' + textAfter);
                }
                
                $(this).parent().append('<span class="qvc-copied">Copiado!</span>');
                setTimeout(function() {
                    $('.qvc-copied').remove();
                }, 1000);
            });
            
            // Modal close
            $('.qvc-modal-close, #qvc-preview-modal').on('click', function(e) {
                if (e.target === this) {
                    $('#qvc-preview-modal').fadeOut();
                    $('body').removeClass('qvc-modal-open');
                }
            });
            
            // ESC key to close modal
            $(document).on('keyup', function(e) {
                if (e.keyCode === 27) { // ESC key
                    $('#qvc-preview-modal').fadeOut();
                    $('body').removeClass('qvc-modal-open');
                }
            });

            // ===== Test send (editor sidebar) =====
            $('#qvc-test-email-editor').on('input', function(){
                var email = $(this).val();
                var valid = /^\S+@\S+\.\S+$/.test(email);
                $('#qvc-send-test-editor').prop('disabled', !valid);
                $('#qvc-test-result-editor').text('');
            });

            $('#qvc-send-test-editor').on('click', function(e){
                e.preventDefault();
                var email = $('#qvc-test-email-editor').val();
                if (!email) return;
                var $btn = $(this);
                var original = $btn.text();
                $btn.prop('disabled', true).text('<?php echo esc_js(__('Enviando...', 'qvaclick-email-manager')); ?>');
                $('#qvc-test-result-editor').text('');

                $.post(window.ajaxurl || '<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'qvc_email_send_test',
                    nonce: window.qvcEmailManager ? window.qvcEmailManager.nonce : '<?php echo wp_create_nonce('qvc_email_nonce'); ?>',
                    template_key: '<?php echo esc_js($template_key); ?>',
                    test_email: email
                })
                .done(function(resp){
                    if (resp && resp.success) {
                        $('#qvc-test-result-editor').css('color','#2c7').text(resp.data.message || '<?php echo esc_js(__('Enviado', 'qvaclick-email-manager')); ?>');
                    } else {
                        $('#qvc-test-result-editor').css('color','#d33').text((resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js(__('Error al enviar', 'qvaclick-email-manager')); ?>');
                    }
                })
                .fail(function(){
                    $('#qvc-test-result-editor').css('color','#d33').text('<?php echo esc_js(__('Error de conexión', 'qvaclick-email-manager')); ?>');
                })
                .always(function(){
                    // Rehabilitar si el email sigue siendo válido
                    var valid = /^\S+@\S+\.\S+$/.test($('#qvc-test-email-editor').val());
                    $btn.prop('disabled', !valid).text(original);
                });
            });
        });
        </script>
        <?php
    }
}
