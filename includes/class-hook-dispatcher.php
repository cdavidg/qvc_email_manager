<?php
/**
 * Hook Dispatcher
 * Escucha hooks (especialmente Exertio) y envía emails configurados en qvc_hook_emails
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class QvaClick_Hook_Dispatcher {
    public static function init() {
        // Exertio notification hub
        add_action('exertio_notification_filter', [__CLASS__, 'on_exertio_notification'], 10, 1);
    }

    /**
     * Maneja el hook exertio_notification_filter
     * @param array $payload Ej: ['post_id'=>, 'n_type'=>'offer_received', 'sender_id'=>, 'receiver_id'=>, 'sender_type'=>'employer']
     */
    public static function on_exertio_notification($payload = []) {
        if (!is_array($payload)) { return; }

        global $wpdb;
        $table_emails = $wpdb->prefix . 'qvc_hook_emails';
        $table_logs   = $wpdb->prefix . 'qvc_email_logs';

        // Buscar emails activos configurados para este hook.
        // Soportamos dos configuraciones:
        //  - hook_name = 'exertio_notification_filter' (acción genérica) con conditions {'n_type':'offer_received',...}
        //  - hook_name = payload['n_type'] (p.ej., 'offer_received') sin necesidad de condition n_type
        $n_type = isset($payload['n_type']) ? sanitize_text_field($payload['n_type']) : '';
        if (!empty($n_type)) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table_emails} WHERE status = 'active' AND (hook_name = %s OR hook_name = %s)",
                'exertio_notification_filter', $n_type
            ));
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table_emails} WHERE hook_name = %s AND status = 'active'",
                'exertio_notification_filter'
            ));
        }
        if (empty($rows)) { return; }

        foreach ($rows as $row) {
            // Validar condiciones (JSON simple: {"n_type":"offer_received", "sender_type":"employer"})
            $conditions_ok = true;
            if (!empty($row->conditions)) {
                $conds = json_decode($row->conditions, true);
                if (is_array($conds)) {
                    foreach ($conds as $k => $v) {
                        if (!array_key_exists($k, $payload) || strval($payload[$k]) !== strval($v)) {
                            $conditions_ok = false;
                            break;
                        }
                    }
                }
            }
            if (!$conditions_ok) { continue; }

            // Determinar destinatarios
            $recipients = self::resolve_recipients($row, $payload);
            if (empty($recipients)) { continue; }

            // Construir subject/body con placeholders
            $context = self::build_context($payload);
            $subject = self::replace_placeholders($row->subject, $context);
            $body    = self::replace_placeholders($row->content, $context);

            // Formatear contenido a HTML si es texto plano (preservar saltos y párrafos)
            if (class_exists('QvaClick_Base_Template_Manager') && method_exists('QvaClick_Base_Template_Manager', 'format_content_html')) {
                $body = QvaClick_Base_Template_Manager::format_content_html($body);
            }

          
            // Aplicar plantilla base si corresponde
            if (!empty($row->use_base_template) && class_exists('QvaClick_Base_Template_Manager')) {
                $base = QvaClick_Base_Template_Manager::get_base_template();
                if (!empty($base)) {
                    $wrapped = str_replace('{{CONTENT}}', $body, $base);
                    // Si el placeholder no existía, anexar contenido al final
                    if (strpos($wrapped, $body) === false) {
                        $wrapped .= "\n" . $body;
                    }
                    $body = $wrapped;
                }
            }
        
            // Enviar a cada destinatario y loguear
            foreach ($recipients as $rcpt) {
                $headers = [
                    'Content-Type: text/html; charset=UTF-8',
                    'From: ' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . ' <' . get_option('admin_email') . '>'
                ];

                // Pre-log (pending)
                $wpdb->insert($table_logs, [
                    'hook_email_id'   => $row->id,
                    'hook_name'       => $row->hook_name,
                    'recipient_email' => $rcpt['email'],
                    'recipient_user_id' => $rcpt['user_id'],
                    'subject'         => $subject,
                    'status'          => 'pending',
                    'hook_data'       => wp_json_encode($payload),
                    'email_content'   => $body,
                    'tracking_id'     => wp_generate_password(16, false),
                    'ip_address'      => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '',
                    'user_agent'      => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : ''
                ]);
                $log_id = $wpdb->insert_id;

                $sent = wp_mail($rcpt['email'], $subject, $body, $headers);

                // Actualizar log
                $wpdb->update(
                    $table_logs,
                    [
                        'status' => $sent ? 'sent' : 'failed',
                        'sent_at' => current_time('mysql')
                    ],
                    ['id' => $log_id]
                );
            }
        }
    }

    private static function resolve_recipients($row, $payload) {
        $list = [];
        $type = $row->email_to_type;
        $value = is_string($row->email_to_value) ? trim($row->email_to_value) : '';

        $push_user = function($user_id) use (&$list) {
            $user_id = intval($user_id);
            if ($user_id > 0) {
                $u = get_user_by('ID', $user_id);
                if ($u && is_email($u->user_email)) {
                    $list[] = ['email' => $u->user_email, 'user_id' => $user_id];
                }
            }
        };

        if ($type === 'admin') {
            $list[] = ['email' => get_option('admin_email'), 'user_id' => 0];
        } elseif ($type === 'user') {
            if ($value === 'receiver' && !empty($payload['receiver_id'])) {
                $push_user($payload['receiver_id']);
            } elseif ($value === 'sender' && !empty($payload['sender_id'])) {
                $push_user($payload['sender_id']);
            } else {
                // user:<id>
                if (preg_match('/^user:(\d+)$/', $value, $m)) {
                    $push_user($m[1]);
                }
            }
        } elseif ($type === 'custom') {
            if (is_email($value)) {
                $list[] = ['email' => $value, 'user_id' => 0];
            }
        } elseif ($type === 'multiple') {
            $emails = array_filter(array_map('trim', explode(',', $value)));
            foreach ($emails as $em) {
                if (is_email($em)) {
                    $list[] = ['email' => $em, 'user_id' => 0];
                }
            }
        }

        return $list;
    }

    private static function build_context($payload) {
        $ctx = [
            'site_name'    => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'home_url'     => home_url(),
            'n_type'       => isset($payload['n_type']) ? $payload['n_type'] : '',
            'sender_type'  => isset($payload['sender_type']) ? $payload['sender_type'] : ''
        ];

        // Usuarios
        if (!empty($payload['receiver_id'])) {
            $u = get_user_by('ID', intval($payload['receiver_id']));
            if ($u) {
                // Intentar obtener nombre de perfil (freelancer/employer) si existe
                $receiver_name = $u->display_name ?: $u->user_login;
                $fl_id = get_user_meta($u->ID, 'freelancer_id', true);
                $em_id = get_user_meta($u->ID, 'employer_id', true);
                if (function_exists('exertio_get_username')) {
                    if (!empty($fl_id)) { $receiver_name = exertio_get_username('freelancer', $fl_id, '') ?: $receiver_name; }
                    if (!empty($em_id)) { $receiver_name = exertio_get_username('employer', $em_id, '') ?: $receiver_name; }
                }
                $ctx['receiver_name'] = $receiver_name;
                $ctx['receiver_email'] = $u->user_email;
                $ctx['receiver_type'] = !empty($fl_id) ? 'freelancer' : (!empty($em_id) ? 'employer' : '')
                ;
                if (!empty($fl_id)) { $ctx['freelancer_name'] = $ctx['receiver_name']; }
                if (!empty($em_id)) { $ctx['employer_name']   = $ctx['receiver_name']; }
            }
        }
        if (!empty($payload['sender_id'])) {
            $u = get_user_by('ID', intval($payload['sender_id']));
            if ($u) {
                $sender_name = $u->display_name ?: $u->user_login;
                $fl_id = get_user_meta($u->ID, 'freelancer_id', true);
                $em_id = get_user_meta($u->ID, 'employer_id', true);
                if (function_exists('exertio_get_username')) {
                    if (!empty($fl_id)) { $sender_name = exertio_get_username('freelancer', $fl_id, '') ?: $sender_name; }
                    if (!empty($em_id)) { $sender_name = exertio_get_username('employer', $em_id, '') ?: $sender_name; }
                }
                $ctx['sender_name'] = $sender_name;
                $ctx['sender_email'] = $u->user_email;
                $ctx['sender_type'] = isset($payload['sender_type']) ? $payload['sender_type'] : (!empty($fl_id) ? 'freelancer' : (!empty($em_id) ? 'employer' : ''));
                if (!empty($fl_id)) { $ctx['freelancer_name'] = $ctx['sender_name']; }
                if (!empty($em_id)) { $ctx['employer_name']   = $ctx['sender_name']; }
            }
        }

        // Post/Proyecto
        if (!empty($payload['post_id'])) {
            $pid = intval($payload['post_id']);
            $ctx['post_id'] = $pid;
            $ctx['project_title'] = get_the_title($pid);
            $ctx['project_link']  = get_permalink($pid);
            // Sinónimos
            $ctx['post_title'] = $ctx['project_title'];
            $ctx['post_link']  = $ctx['project_link'];
            $ctx['project_url'] = $ctx['project_link'];
        }

        // Incluir todos los valores simples del payload como placeholders directos
        foreach ($payload as $k => $v) {
            if (is_scalar($v)) {
                $ctx[$k] = (string) $v;
            }
        }

        // Sinónimos de nombres
        if (!isset($ctx['display_name']) && isset($ctx['receiver_name'])) {
            $ctx['display_name'] = $ctx['receiver_name'];
        }
        if (isset($ctx['sender_name'])) {
            $ctx['sender_display_name'] = $ctx['sender_name'];
        }
        if (isset($ctx['receiver_name'])) {
            $ctx['receiver_display_name'] = $ctx['receiver_name'];
        }

        return $ctx;
    }

    private static function replace_placeholders($text, $data) {
        $text = (string) $text;
        foreach ($data as $k => $v) {
            $text = str_replace(['%'.$k.'%', '{'.$k.'}'], $v, $text);
        }
        return $text;
    }
}
