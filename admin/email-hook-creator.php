<?php
// Prevent direct access
if (!defined('ABSPATH')) { exit; }

if (!function_exists('qvc_user_can_manage')) {
    function qvc_user_can_manage() {
        return current_user_can('qvc_manage_emails') || current_user_can('manage_options');
    }
}

function qvc_email_hook_creator_page() {
    if (!qvc_user_can_manage()) {
        wp_die(__('No tienes permisos para acceder a esta página.'));
    }

    global $wpdb;
    $table_emails = $wpdb->prefix . 'qvc_hook_emails';
    $created = false; $updated = false; $error = '';

    // Soporte de edición: ?id=123
    $editing_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $editing_row = null;
    if ($editing_id > 0) {
        $editing_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_emails} WHERE id = %d", $editing_id));
        // Marcar como visto solo si se solicita (por ejemplo, desde la lista con mark_seen=1)
        if ($editing_row && isset($_GET['mark_seen']) && $_GET['mark_seen'] === '1') {
            $seen = get_user_meta(get_current_user_id(), 'qvc_seen_hook_email_ids', true);
            if (!is_array($seen)) { $seen = array(); }
            if (!in_array($editing_id, $seen, true)) {
                $seen[] = $editing_id;
                update_user_meta(get_current_user_id(), 'qvc_seen_hook_email_ids', $seen);
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qvc_hook_creator_nonce']) && wp_verify_nonce($_POST['qvc_hook_creator_nonce'], 'qvc_hook_creator')) {
        $hook_name = sanitize_text_field($_POST['hook_name'] ?? '');
        $name      = sanitize_text_field($_POST['name'] ?? '');
        $email_to_type  = sanitize_text_field($_POST['email_to_type'] ?? 'user');
        $email_to_value = sanitize_text_field($_POST['email_to_value'] ?? 'receiver');
        $subject   = sanitize_text_field($_POST['subject'] ?? '');
        $content   = wp_kses_post($_POST['content'] ?? '');
        $conditions_json = wp_unslash($_POST['conditions'] ?? '{}');
        $use_base_template = isset($_POST['use_base_template']) ? 1 : 0;

        if (empty($hook_name) || empty($name) || empty($subject) || empty($content)) {
            $error = __('Completa todos los campos obligatorios.', 'qvaclick-email-manager');
        } else {
            if ($editing_row) {
                // Actualizar existente
                $wpdb->update($table_emails, [
                    'name' => $name,
                    'hook_name' => $hook_name,
                    'email_to_type' => $email_to_type,
                    'email_to_value' => $email_to_value,
                    'subject' => $subject,
                    'content' => $content,
                    'use_base_template' => $use_base_template,
                    'conditions' => $conditions_json,
                    'priority' => 10,
                ], ['id' => $editing_id]);
                if ($wpdb->last_error) {
                    $error = $wpdb->last_error;
                } else {
                    $updated = true;
                    // refrescar fila de edición
                    $editing_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_emails} WHERE id = %d", $editing_id));
                }
            } else {
                // Crear nuevo
                $wpdb->insert($table_emails, [
                    'name' => $name,
                    'hook_name' => $hook_name,
                    'status' => 'active',
                    'email_to_type' => $email_to_type,
                    'email_to_value' => $email_to_value,
                    'subject' => $subject,
                    'content' => $content,
                    'use_base_template' => $use_base_template,
                    'variables' => '',
                    'conditions' => $conditions_json,
                    'priority' => 10,
                    'created_by' => get_current_user_id(),
                ]);
                if ($wpdb->last_error) {
                    $error = $wpdb->last_error;
                } else {
                    $created = true;
                    $editing_id = intval($wpdb->insert_id);
                    $editing_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_emails} WHERE id = %d", $editing_id));
                }
            }
        }
    }

    // Prefills
    // Prefills por defecto o desde fila existente
    $pref_hook = $editing_row ? $editing_row->hook_name : (isset($_GET['hook']) ? sanitize_text_field($_GET['hook']) : 'exertio_notification_filter');
    $pref_name = $editing_row ? $editing_row->name : 'Offer Received (Employer → Freelancer)';
    $pref_subject = $editing_row ? $editing_row->subject : 'Nueva oferta recibida de {sender_name} en {site_name}';
    $pref_content = $editing_row ? $editing_row->content : 'Hola {receiver_name},<br><br>Has recibido una nueva oferta del empleador {sender_name} en el proyecto "{project_title}".<br>Ver proyecto: {project_link}<br><br>Saludos,<br>{site_name}';
    $pref_conditions = $editing_row ? $editing_row->conditions : '{"n_type":"offer_received","sender_type":"employer"}';
    $pref_to_type = $editing_row ? $editing_row->email_to_type : 'user';
    $pref_to_value = $editing_row ? $editing_row->email_to_value : 'receiver';

    ?>
    <div class="wrap">
        <h1>Crear Email por Hook</h1>
        <?php if ($created): ?>
            <div class="notice notice-success is-dismissible"><p><?php _e('Email creado correctamente. Se enviará cuando ocurra el evento.', 'qvaclick-email-manager'); ?></p></div>
        <?php elseif ($updated): ?>
            <div class="notice notice-success is-dismissible"><p><?php _e('Email actualizado correctamente.', 'qvaclick-email-manager'); ?></p></div>
        <?php elseif (!empty($error)): ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>

    <form method="post">
            <?php wp_nonce_field('qvc_hook_creator', 'qvc_hook_creator_nonce'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="hook_name">Hook</label></th>
                    <td>
                        <input type="text" id="hook_name" name="hook_name" class="regular-text" value="<?php echo esc_attr($pref_hook); ?>" />
                        <p class="description">Ej: exertio_notification_filter</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="name">Nombre</label></th>
                    <td>
                        <input type="text" id="name" name="name" class="regular-text" value="<?php echo esc_attr($pref_name); ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Destinatario</th>
                    <td>
                        <select name="email_to_type" id="email_to_type">
                            <option value="user" <?php selected($pref_to_type, 'user'); ?>>Usuario</option>
                            <option value="admin">Admin</option>
                            <option value="custom">Custom</option>
                            <option value="multiple">Múltiples</option>
                        </select>
                        <input type="text" name="email_to_value" id="email_to_value" class="regular-text" value="<?php echo esc_attr($pref_to_value); ?>" />
                        <p class="description">Para tipo usuario: "receiver" o "sender". Para custom: email. Para múltiples: lista separada por comas.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="subject">Asunto</label></th>
                    <td>
                        <input type="text" id="subject" name="subject" class="regular-text" value="<?php echo esc_attr($pref_subject); ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="content">Contenido</label></th>
                    <td>
                        <?php wp_editor($pref_content, 'content', ['textarea_name' => 'content', 'textarea_rows' => 10]); ?>
                        <p class="description">Placeholders: {receiver_name}, {sender_name}, {project_title}, {project_link}, {site_name}</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="conditions">Condiciones (JSON)</label></th>
                    <td>
                        <textarea id="conditions" name="conditions" class="large-text" rows="3"><?php echo esc_textarea($pref_conditions); ?></textarea>
                        <p class="description">Se debe cumplir para enviar. Ej: {"n_type":"offer_received","sender_type":"employer"}</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Plantilla Base</th>
                    <td>
                        <label><input type="checkbox" name="use_base_template" checked /> Usar plantilla base</label>
                    </td>
                </tr>
            </table>

            <p class="submit" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <button type="submit" class="button button-primary"><?php echo $editing_row ? __('Guardar cambios', 'qvaclick-email-manager') : __('Crear Email', 'qvaclick-email-manager'); ?></button>
                <?php if ($editing_id > 0): ?>
                    <button type="button" id="qvc-hook-preview" class="button"><?php _e('Vista Previa', 'qvaclick-email-manager'); ?></button>
                    <button type="button" id="qvc-hook-apply-base" class="button"><?php _e('Aplicar Plantilla Base', 'qvaclick-email-manager'); ?></button>
                    <span style="margin-left:8px;"><input type="email" id="qvc-test-email-hook" class="regular-text" placeholder="email@ejemplo.com" />
                    <button type="button" id="qvc-hook-send-test" class="button button-secondary" disabled><?php _e('Enviar test', 'qvaclick-email-manager'); ?></button>
                    <span id="qvc-hook-test-result" style="margin-left:8px;"></span></span>
                <?php else: ?>
                    <span class="description"><?php _e('Guarda primero para habilitar vista previa, aplicar base y envío de prueba.', 'qvaclick-email-manager'); ?></span>
                <?php endif; ?>
            </p>
        </form>
    </div>

    <?php if ($editing_id > 0): ?>
    <!-- Preview Modal -->
    <div id="qvc-preview-modal" style="display:none;">
        <div class="qvc-modal-content">
            <span class="qvc-modal-close">&times;</span>
            <h2><?php _e('Vista Previa del Email por Hook', 'qvaclick-email-manager'); ?></h2>
            <div id="qvc-preview-content"></div>
            <div class="qvc-test-send" style="margin-top:12px;">
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
    jQuery(document).ready(function($){
    var ajaxurl = (window.qvcEmailManager && window.qvcEmailManager.ajaxurl) ? window.qvcEmailManager.ajaxurl : '<?php echo admin_url('admin-ajax.php'); ?>';
    // Fallback: generar nonce por PHP si no fue localizado por el script global
    var nonce = (window.qvcEmailManager && window.qvcEmailManager.nonce) ? window.qvcEmailManager.nonce : '<?php echo wp_create_nonce('qvc_email_nonce'); ?>';
        var hookId = <?php echo (int)$editing_id; ?>;

        $('#qvc-hook-preview').on('click', function(e){
            e.preventDefault();
            var $btn = $(this), original = $btn.text();
            $btn.prop('disabled', true).text('<?php echo esc_js(__('Cargando...', 'qvaclick-email-manager')); ?>');
            $.post(ajaxurl, {
                action: 'qvc_hook_email_preview',
                nonce: nonce,
                hook_email_id: hookId
            }).done(function(resp){
                if (resp && resp.success) {
                    $('#qvc-preview-content').html(resp.data.preview);
                    $('#qvc-preview-modal').fadeIn(200);
                } else {
                    alert((resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js(__('Error al generar vista previa', 'qvaclick-email-manager')); ?>');
                }
            }).fail(function(){
                alert('<?php echo esc_js(__('Error de conexión', 'qvaclick-email-manager')); ?>');
            }).always(function(){
                $btn.prop('disabled', false).text(original);
            });
        });

        $('#qvc-hook-apply-base').on('click', function(e){
            e.preventDefault();
            if (!confirm('<?php echo esc_js(__('¿Aplicar la plantilla base a este email?', 'qvaclick-email-manager')); ?>')) return;
            var $btn = $(this), original = $btn.text();
            $btn.prop('disabled', true).text('<?php echo esc_js(__('Aplicando...', 'qvaclick-email-manager')); ?>');
            $.post(ajaxurl, {
                action: 'qvc_hook_email_apply_base',
                nonce: nonce,
                hook_email_id: hookId
            }).done(function(resp){
                if (resp && resp.success) {
                    alert(resp.data.message || '<?php echo esc_js(__('Plantilla base aplicada.', 'qvaclick-email-manager')); ?>');
                    location.reload();
                } else {
                    alert((resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js(__('Error al aplicar plantilla base', 'qvaclick-email-manager')); ?>');
                }
            }).fail(function(){
                alert('<?php echo esc_js(__('Error de conexión', 'qvaclick-email-manager')); ?>');
            }).always(function(){
                $btn.prop('disabled', false).text(original);
            });
        });

        // Inline test send (outside modal)
        $('#qvc-test-email-hook').on('input', function(){
            var valid = /^\S+@\S+\.\S+$/.test($(this).val());
            $('#qvc-hook-send-test').prop('disabled', !valid);
            $('#qvc-hook-test-result').text('');
        });
        $('#qvc-hook-send-test').on('click', function(e){
            e.preventDefault();
            var email = $('#qvc-test-email-hook').val();
            if (!/^\S+@\S+\.\S+$/.test(email)) return;
            var $btn = $(this), original = $btn.text();
            $btn.prop('disabled', true).text('<?php echo esc_js(__('Enviando...', 'qvaclick-email-manager')); ?>');
            $('#qvc-hook-test-result').text('');
            $.post(ajaxurl, {
                action: 'qvc_hook_email_send_test',
                nonce: nonce,
                hook_email_id: hookId,
                test_email: email
            }).done(function(resp){
                if (resp && resp.success) {
                    $('#qvc-hook-test-result').css('color','#2c7').text(resp.data.message || '<?php echo esc_js(__('Enviado', 'qvaclick-email-manager')); ?>');
                } else {
                    $('#qvc-hook-test-result').css('color','#d33').text((resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js(__('Error al enviar', 'qvaclick-email-manager')); ?>');
                }
            }).fail(function(){
                $('#qvc-hook-test-result').css('color','#d33').text('<?php echo esc_js(__('Error de conexión', 'qvaclick-email-manager')); ?>');
            }).always(function(){
                var valid = /^\S+@\S+\.\S+$/.test($('#qvc-test-email-hook').val());
                $btn.prop('disabled', !valid).text(original);
            });
        });

        // Modal interactions
        $(document).on('input', '#qvc-test-email', function(){
            var valid = /^\S+@\S+\.\S+$/.test($(this).val());
            $('#qvc-send-test').prop('disabled', !valid);
            $('#qvc-test-result').text('');
        });
        $(document).on('click', '#qvc-send-test', function(e){
            e.preventDefault();
            var email = $('#qvc-test-email').val();
            if (!/^\S+@\S+\.\S+$/.test(email)) return;
            var $btn = $(this), original = $btn.text();
            $btn.prop('disabled', true).text('<?php echo esc_js(__('Enviando...', 'qvaclick-email-manager')); ?>');
            $('#qvc-test-result').text('');
            $.post(ajaxurl, {
                action: 'qvc_hook_email_send_test',
                nonce: nonce,
                hook_email_id: hookId,
                test_email: email
            }).done(function(resp){
                if (resp && resp.success) {
                    $('#qvc-test-result').css('color','#2c7').text(resp.data.message || '<?php echo esc_js(__('Enviado', 'qvaclick-email-manager')); ?>');
                } else {
                    $('#qvc-test-result').css('color','#d33').text((resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js(__('Error al enviar', 'qvaclick-email-manager')); ?>');
                }
            }).fail(function(){
                $('#qvc-test-result').css('color','#d33').text('<?php echo esc_js(__('Error de conexión', 'qvaclick-email-manager')); ?>');
            }).always(function(){
                var valid = /^\S+@\S+\.\S+$/.test($('#qvc-test-email').val());
                $btn.prop('disabled', !valid).text(original);
            });
        });
        $('.qvc-modal-close, #qvc-preview-modal').on('click', function(e){ if (e.target === this) { $('#qvc-preview-modal').fadeOut(200); } });
        $(document).on('keyup', function(e){ if (e.keyCode === 27) { $('#qvc-preview-modal').fadeOut(200); } });
    });
    </script>
    <?php endif; ?>
    <?php
}
