<?php
/**
 * REST API endpoints for QvaClick Email Manager
 */
if ( ! defined('ABSPATH') ) { exit; }

class QvaClick_Email_REST_API {
    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() {
        register_rest_route('qvc-email/v1', '/templates', [
            'methods'  => 'GET',
            'callback' => [__CLASS__, 'get_templates'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ]);

        register_rest_route('qvc-email/v1', '/apply-base', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'apply_base'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
            'args' => [
                'base_template' => [ 'required' => false ],
                'apply_to' => [ 'required' => false ],
                'dry_run' => [ 'required' => false ]
            ]
        ]);
    }

    public static function get_templates( WP_REST_Request $request ) {
        $preview = (bool) $request->get_param('preview');
        $templates = QvaClick_Email_Discovery::discover_email_templates();
        $out = [];
        foreach ($templates as $base_key => $t) {
            $body = $t['body'];
            if ($preview) {
                $body = QvaClick_Base_Template_Manager::generate_preview($body);
            }
            $out[] = [
                'base_key' => $base_key,
                'name' => $t['name'],
                'enabled' => $t['enabled'],
                'subject' => $t['subject'],
                'body' => $body,
                'placeholders' => $t['placeholders'],
                'last_modified' => $t['last_modified']
            ];
        }
        return rest_ensure_response($out);
    }

    public static function apply_base( WP_REST_Request $request ) {
        $apply_to = $request->get_param('apply_to'); // array or 'all'
        $base_template = $request->get_param('base_template');
        $dry = (bool) $request->get_param('dry_run');

        if ($dry) {
            $templates = QvaClick_Email_Discovery::discover_email_templates();
            $count = 0;
            foreach ($templates as $base_key => $t) {
                if ($apply_to && $apply_to !== 'all' && is_array($apply_to) && !in_array($base_key, $apply_to, true)) { continue; }
                $count++;
            }
            return rest_ensure_response([
                'dry_run' => true,
                'would_apply_to' => $count
            ]);
        }

        $result = QvaClick_Base_Template_Manager::apply_to_templates($base_template, $apply_to ?: 'all');
        return rest_ensure_response($result);
    }
}

QvaClick_Email_REST_API::init();
