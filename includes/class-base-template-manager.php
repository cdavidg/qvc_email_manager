<?php
/**
 * Base Template Manager Class
 * Gestiona la plantilla base unificada y su aplicación a otros templates
 */

class QvaClick_Base_Template_Manager {
    // --- Safe WP wrappers to avoid static analysis errors when WP is not loaded ---
    private static function safe_get_option($key, $default = '') {
        return function_exists('get_option') ? get_option($key, $default) : $default;
    }
    private static function safe_update_option($key, $value) {
        return function_exists('update_option') ? call_user_func('update_option', $key, $value) : false;
    }
    private static function safe_esc_url($url) {
        if (function_exists('esc_url')) { return call_user_func('esc_url', $url); }
        return htmlspecialchars((string)$url, ENT_QUOTES, 'UTF-8');
    }
    private static function safe_esc_html($text) {
        if (function_exists('esc_html')) { return call_user_func('esc_html', $text); }
        return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    }
    private static function safe__($text, $domain = 'qvaclick-email-manager') {
        return function_exists('__') ? call_user_func('__', $text, $domain) : $text;
    }
    
    /**
     * Obtiene la plantilla base actual
     */
    public static function get_base_template() {
    $base_template = self::safe_get_option('qvc_email_base_template', '');
        
        if (empty($base_template)) {
            // Plantilla base por defecto
            $base_template = self::get_default_base_template();
        }
        
        return $base_template;
    }
    
    /**
     * Guarda la plantilla base
     */
    public static function save_base_template($template) {
    return self::safe_update_option('qvc_email_base_template', $template);
    }
    
    /**
     * Plantilla base por defecto
     */
    private static function get_default_base_template() {
        return '<!-- Encabezado con logo -->
<div> </div>
<table style="max-width: 600px;" width="100%" cellspacing="0" cellpadding="0">
<tbody>
<tr>
<td style="padding: 40px 0;" align="center">
    <img class="alignleft" style="height: 112px;" src="https://www.qvaclick.com/wp-content/uploads/2021/02/logo_qvaclick_r.png" alt="QvaClick" width="195" />
</td>
</tr>
</tbody>
</table>

<!-- Cuerpo del mensaje -->
<table style="max-width: 600px;" width="100%" cellspacing="0" cellpadding="0">
<tbody>
<tr>
<td style="padding: 40px 30px 20px 30px; text-align: left; font-family: sans-serif; color: #333;" bgcolor="#ffffff">
    {{CONTENT}}
</td>
</tr>
<!-- Línea de ayuda -->
<tr>
<td style="padding: 0 30px 30px 30px; text-align: left; font-family: sans-serif; color: #555;" bgcolor="#ffffff">
    <p style="margin-top: 10px; font-size: 16px;">Si tienes alguna duda o necesitas ayuda, no dudes en responder este correo. Estamos aquí para apoyarte.</p>
</td>
</tr>
<!-- Firma -->
<tr>
<td style="padding: 0 30px 40px 30px; text-align: left; font-family: sans-serif; color: #555;" bgcolor="#ffffff">
    <p style="margin: 0; font-size: 16px;">¡Vamos a crear juntos grandes cosas!<br />— El equipo de <strong>QvaClick</strong></p>
</td>
</tr>
</tbody>
</table>

<!-- Pie con ayuda -->
<table style="max-width: 600px;" width="100%" cellspacing="0" cellpadding="0">
<tbody>
<tr>
<td style="padding: 30px; border-radius: 4px; text-align: center;" bgcolor="#FFECD1">
    <h2 style="font-size: 20px; margin: 0;">¿Necesitas ayuda?</h2>
    <p style="margin: 10px 0 0;"><a style="color: #3cbeb2; text-decoration: none;" href="mailto:soporte@qvaclick.com">Visita nuestro centro de soporte</a></p>
</td>
</tr>
</tbody>
</table>

<!-- Créditos -->
<table style="max-width: 600px;" width="100%" cellspacing="0" cellpadding="0">
<tbody>
<tr>
<td style="padding: 20px 30px; font-size: 14px; color: #999; text-align: center;">
    <p style="margin: 0;">© 2025 <strong>QvaClick</strong>. Todos los derechos reservados.</p>
</td>
</tr>
</tbody>
</table>';
    }
    
    /**
     * Aplica la plantilla base a templates específicos
     */
    public static function apply_to_templates($base_template, $apply_to) {
        // Si no se especifica plantilla base, usar la plantilla guardada
        if (empty($base_template)) {
            $base_template = self::get_base_template();
        }
        // Asegurar que exista placeholder {{CONTENT}} en la plantilla base; si no, lo añadimos al final
        if (strpos($base_template, '{{CONTENT}}') === false) {
            $base_template .= "\n<!-- AUTO-APPEND CONTENT -->\n{{CONTENT}}";
        }
        
        $templates = QvaClick_Email_Discovery::discover_email_templates();
        $applied_count = 0;
        $errors = array();
        
        // Crear backup antes de aplicar cambios
    $backup_key = 'qvc_email_backup_' . date('Y_m_d_H_i_s');
    $redux_options = self::safe_get_option('exertio_theme_options', array());
    self::safe_update_option($backup_key, $redux_options);
        
        foreach ($templates as $base_key => $template) {
            // Verificar si debemos aplicar a este template
            if ($apply_to !== 'all' && !in_array($base_key, $apply_to)) {
                continue;
            }
            
            try {
                // Extraer contenido limpio del template actual
                $clean_content = self::extract_clean_content($template['body']);
                
                if (empty($clean_content)) {
                    $errors[] = "No se pudo extraer contenido del template: " . $template['name'];
                    continue;
                }
                
                // Aplicar estilos al contenido
                $styled_content = self::apply_content_styles($clean_content);
                
                // Insertar en la plantilla base
                $new_body = str_replace('{{CONTENT}}', $styled_content, $base_template);
                // Si por algún motivo reemplazo no ocurrió (edge), anexar contenido al final
                if (strpos($new_body, $styled_content) === false) {
                    $new_body .= "\n" . $styled_content;
                }
                
                // Actualizar usando nuestro método de guardado
                if (!empty($template['body_key'])) {
                    if (QvaClick_Email_Discovery::save_template_option($template['body_key'], $new_body)) {
                        $applied_count++;
                    } else {
                        $errors[] = "Error al guardar template: " . $template['name'];
                    }
                }
                
            } catch (Exception $e) {
                $errors[] = "Error en template " . $template['name'] . ": " . $e->getMessage();
            }
        }
        
        $success_message = '';
        if ($applied_count > 0) {
            $success_message = sprintf(
                self::safe__('Plantilla base aplicada a %d template(s). Backup guardado como: %s', 'qvaclick-email-manager'),
                $applied_count,
                $backup_key
            );
        } else {
            $success_message = self::safe__('No se aplicaron cambios. Revisa los errores.', 'qvaclick-email-manager');
        }
        
        return array(
            'success' => $applied_count > 0,
            'applied_count' => $applied_count,
            'errors' => $errors,
            'backup_key' => $backup_key,
            'message' => $success_message
        );
    }
    
    /**
     * Extrae contenido limpio removiendo estructura HTML
     */
    private static function extract_clean_content($html) {
        $content = $html;
        
        // Remover tables de estructura pero mantener el contenido interno
        $content = preg_replace('/<table[^>]*>/i', '', $content);
        $content = preg_replace('/<\/table>/i', '', $content);
        $content = preg_replace('/<tbody[^>]*>/i', '', $content);
        $content = preg_replace('/<\/tbody>/i', '', $content);
        $content = preg_replace('/<tr[^>]*>/i', '', $content);
        $content = preg_replace('/<\/tr>/i', '', $content);
        $content = preg_replace('/<td[^>]*>/i', '', $content);
        $content = preg_replace('/<\/td>/i', '', $content);
        
        // Mantener solo contenido relevante: h1, h2, p, a, strong, br, div
        $content = strip_tags($content, '<h1><h2><h3><p><a><strong><b><em><i><br><div><span>');
        
        // Limpiar espacios extra
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);
        
        return $content;
    }
    
    /**
     * Aplica estilos consistentes al contenido extraído
     */
    private static function apply_content_styles($content) {
        // Aplicar estilos específicos que coincidan con tu estructura
        $content = preg_replace('/<h1([^>]*)>/i', '<h1 style="font-size: 28px; margin: 0 0 10px;"$1>', $content);
        $content = preg_replace('/<h2([^>]*)>/i', '<h2 style="font-size: 20px; margin: 0;"$1>', $content);
        $content = preg_replace('/<h3([^>]*)>/i', '<h3 style="font-size: 18px; margin: 0 0 10px;"$1>', $content);
        $content = preg_replace('/<p([^>]*)>/i', '<p style="font-size: 16px; line-height: 1.5; margin: 0 0 16px;"$1>', $content);
        $content = preg_replace('/<a([^>]*)>/i', '<a style="color: #3cbeb2; text-decoration: none;"$1>', $content);
        $content = preg_replace('/<strong([^>]*)>/i', '<strong$1>', $content);

        // Detectar y estilizar enlaces tipo botón (conservando href original)
        $content = preg_replace_callback(
            '/<a[^>]*href=(\"|\')([^\"\']+)(\1)[^>]*>([^<]*(?:acceder|login|entrar|cuenta|activar|verificar|ver|oferta|ofertas)[^<]*)<\\/a>/i',
            function($m) {
                $href = $m[2];
                $label = trim($m[4]);
                return '<div style="text-align: center; margin: 24px 0;">'
                    . '<a href="' . self::safe_esc_url($href) . '" style="background-color:#5aadff;color:#fff;padding:12px 24px;border-radius:4px;text-decoration:none;font-weight:bold;display:inline-block;">'
                    . self::safe_esc_html($label)
                    . '</a></div>';
            },
            $content
        );
        
        return $content;
    }

    /**
     * Formatea contenido para email HTML preservando saltos de línea y añadiendo estilos básicos.
     */
    public static function format_content_html($content) {
        if (!is_string($content)) { return ''; }
        $content = trim($content);

        // Reemplazar saltos de línea por párrafos/BR si no hay etiquetas de bloque
        $has_blocks = preg_match('/<\s*(p|br|div|table|ul|ol|li|h1|h2|h3|h4|h5|h6)\b/i', $content);
        if (!$has_blocks) {
            if (function_exists('wpautop')) {
                $content = call_user_func('wpautop', $content);
            } else {
                $content = nl2br($content);
            }
        }

        // Balancear etiquetas por seguridad
        if (function_exists('force_balance_tags')) {
            $content = call_user_func('force_balance_tags', $content);
        }

        // Aplicar estilos predefinidos
        $content = self::apply_content_styles($content);
        return $content;
    }

    /**
     * Aplica la plantilla base al contenido formateado (para operaciones de “Aplicar Plantilla Base”).
     */
    public static function apply_to_html($content) {
        $formatted = self::format_content_html($content);
        $base = self::get_base_template();
        if (!empty($base)) {
            $wrapped = str_replace('{{CONTENT}}', $formatted, $base);
            if (strpos($wrapped, $formatted) === false) { $wrapped .= "\n" . $formatted; }
            return $wrapped;
        }
        return $formatted;
    }
    
    /**
     * Detecta si un contenido ya tiene la plantilla base aplicada
     */
    public static function has_base_template_applied($content) {
        if (empty($content)) {
            return false;
        }
        
        // Detectar elementos característicos de la plantilla base
        $base_indicators = array(
            'qvaclick_r.png',  // Logo de QvaClick
            '© 2025 <strong>QvaClick</strong>',  // Footer
            'Visita nuestro centro de soporte',  // Help section
            'El equipo de <strong>QvaClick</strong>',  // Signature
            'bgcolor="#FFECD1"',  // Help section background
            'max-width: 600px'  // Table structure
        );
        
        $indicators_found = 0;
        foreach ($base_indicators as $indicator) {
            if (strpos($content, $indicator) !== false) {
                $indicators_found++;
            }
        }
        
        // Si encontramos 3 o más indicadores, consideramos que ya tiene la plantilla
        return $indicators_found >= 3;
    }
    
    /**
     * Extrae solo el contenido principal de un email que ya tiene plantilla aplicada
     */
    public static function extract_main_content($full_email_html) {
        if (empty($full_email_html)) {
            return '';
        }
        
        // Si no tiene la plantilla aplicada, devolver tal como está
        if (!self::has_base_template_applied($full_email_html)) {
            return $full_email_html;
        }
        
        // Buscar el contenido entre las estructuras de tabla principales
        // El contenido principal está en la tabla con bgcolor="#ffffff"
        $pattern = '/<td[^>]*bgcolor=["\']#ffffff["\'][^>]*>(.+?)<\/td>/is';
        if (preg_match($pattern, $full_email_html, $matches)) {
            $main_content = $matches[1];
            
            // Limpiar contenido de ayuda y firma que son parte de la plantilla
            $main_content = preg_replace('/<p[^>]*>Si tienes alguna duda.+?<\/p>/is', '', $main_content);
            $main_content = preg_replace('/<p[^>]*>¡Vamos a crear juntos.+?<\/p>/is', '', $main_content);
            
            return trim($main_content);
        }
        
        // Fallback: intentar extraer entre comentarios si están presentes
        $content_start = strpos($full_email_html, '<!-- Cuerpo del mensaje -->');
        $content_end = strpos($full_email_html, '<!-- Línea de ayuda -->');
        
        if ($content_start !== false && $content_end !== false) {
            $content_section = substr($full_email_html, $content_start, $content_end - $content_start);
            
            // Extraer solo el contenido dentro de las celdas
            if (preg_match('/<td[^>]*>(.+?)<\/td>/is', $content_section, $matches)) {
                return trim($matches[1]);
            }
        }
        
        // Si no podemos extraer, devolver el contenido original
        return $full_email_html;
    }
    public static function generate_preview($template_content, $preview_data = array()) {
        // Datos de prueba por defecto CON URLs FUNCIONALES
        $default_data = array(
            'site_name' => 'QvaClick',
            'display_name' => 'Juan Pérez',
            'email' => 'juan@ejemplo.com',
            'freelancer_name' => 'María García',
            'employer_name' => 'Empresa Demo',
            'project_title' => 'Desarrollo de Sitio Web',
            'project_link' => 'https://qvaclick.com/dashboard/projects',
            'profile_link' => 'https://qvaclick.com/perfil/usuario',
            'service_link' => 'https://qvaclick.com/dashboard/services',
            'order_link' => 'https://qvaclick.com/dashboard/orders',
            'dashboard_link' => 'https://qvaclick.com/dashboard',
            'site_url' => 'https://qvaclick.com/admin',
            'offer_amount' => '$500',
            'days_to_complete' => '7 días',
            'login_url' => 'https://qvaclick.com/login',
            'verification_link' => 'https://qvaclick.com/verify-account',
            'home_url' => 'https://qvaclick.com',
            'user_login' => 'juan_perez',
            'service_title' => 'Desarrollo Web Profesional',
            'service_cost' => '$450',
            'service_delivery' => '5 días',
            'service_description' => 'Desarrollo completo de sitio web responsive'
        );
        
        $data = array_merge($default_data, $preview_data);
        
        // Reemplazar placeholders
        foreach ($data as $key => $value) {
            $template_content = str_replace(
                array('%' . $key . '%', '{' . $key . '}'),
                $value,
                $template_content
            );
        }
        
    // No aplicar estilos en preview cruda; el llamador puede decidir usar format_content_html si necesita ver formato
    return $template_content;
    }
    
    /**
     * Restaura desde un backup
     */
    public static function restore_from_backup($backup_key) {
    $backup_data = self::safe_get_option($backup_key);
        
        if (empty($backup_data)) {
            return array('success' => false, 'message' => 'Backup no encontrado');
        }
        
    self::safe_update_option('exertio_theme_options', $backup_data);
        
        return array('success' => true, 'message' => 'Restauración completada exitosamente');
    }
}
