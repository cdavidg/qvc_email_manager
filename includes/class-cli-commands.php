<?php
/**
 * WP-CLI Commands for QvaClick Email Manager
 */
if ( defined('WP_CLI') && WP_CLI && ! class_exists('QvaClick_Email_CLI') ) {

    class QvaClick_Email_CLI {
        /**
         * Lista los templates de email detectados.
         *
         * ## OPTIONS
         *
         * [--format=<format>]
         * : Formato de salida. Opciones: table, json, csv. Default: table.
         *
         * [--fields=<fields>]
         * : Campos separados por coma. Disponibles: base_key,name,enabled,subject_length,placeholders_count,subject,body_key,subject_key,switch_key.
         *
         * [--include-body]
         * : Incluye el HTML completo del body en la salida (para table se recorta).
         *
         * ## EXAMPLES
         *   wp qvc-emails list
         *   wp qvc-emails list --format=json --include-body > emails.json
         *   wp qvc-emails list --fields=base_key,name,enabled,subject_length
         */
        public function list( $args, $assoc_args ) {
            $templates = QvaClick_Email_Discovery::discover_email_templates();
            $items = [];
            $include_body = isset($assoc_args['include-body']);
            $fields = isset($assoc_args['fields']) ? explode(',', $assoc_args['fields']) : [];
            $allowed_fields = ['base_key','name','enabled','subject_length','placeholders_count','subject','body_key','subject_key','switch_key','body'];

            if (empty($fields)) {
                $fields = ['base_key','name','enabled','subject_length','placeholders_count'];
            }
            if ($include_body && !in_array('body', $fields, true)) {
                $fields[] = 'body';
            }
            // Validate fields
            $fields = array_values(array_intersect($fields, $allowed_fields));

            foreach ($templates as $base_key => $t) {
                $row = [
                    'base_key' => $base_key,
                    'name' => $t['name'],
                    'enabled' => $t['enabled'] ? 'yes' : 'no',
                    'subject_length' => strlen($t['subject']),
                    'placeholders_count' => count($t['placeholders']),
                    'subject' => $t['subject'],
                    'body_key' => $t['body_key'],
                    'subject_key' => $t['subject_key'],
                    'switch_key' => $t['switch_key'],
                    'body' => $include_body ? $t['body'] : ''
                ];
                // Keep only requested
                $items[] = array_intersect_key($row, array_flip($fields));
            }

            $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
            \WP_CLI\Utils\format_items($format, $items, $fields);
        }

        /**
         * Muestra el HTML completo de un template.
         *
         * ## OPTIONS
         *
         * <base_key>
         * : Clave base del template.
         *
         * [--raw]
         * : Devuelve solo el HTML sin envoltura.
         *
         * [--preview]
         * : Reemplaza placeholders con datos de ejemplo.
         *
         * ## EXAMPLES
         *   wp qvc-emails show fl_new_user_admin
         *   wp qvc-emails show fl_new_user_admin --preview > preview.html
         */
        public function show( $args, $assoc_args ) {
            list($base_key) = $args;
            $templates = QvaClick_Email_Discovery::discover_email_templates();
            if (!isset($templates[$base_key])) {
                \WP_CLI::error("Template no encontrado: {$base_key}");
            }
            $template = $templates[$base_key];
            $body = $template['body'];
            if ( isset($assoc_args['preview']) ) {
                $body = QvaClick_Base_Template_Manager::generate_preview($body);
            }
            if ( isset($assoc_args['raw']) ) {
                echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } else {
                \WP_CLI::line("=== {$template['name']} ({$base_key}) ===");
                \WP_CLI::line('Subject: ' . $template['subject']);
                \WP_CLI::line('Enabled: ' . ($template['enabled'] ? 'yes' : 'no'));
                \WP_CLI::line('Placeholders: ' . implode(', ', $template['placeholders']));
                \WP_CLI::line('--- BODY HTML START ---');
                echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                \WP_CLI::line('\n--- BODY HTML END ---');
            }
        }

        /**
         * Aplica la plantilla base a uno o varios templates.
         *
         * ## OPTIONS
         * [--only=<keys>]
         * : Lista separada por coma de base_keys a las que aplicar. Si se omite aplica a todos.
         *
         * [--dry-run]
         * : Muestra qué haría sin guardar.
         *
         * ## EXAMPLES
         *   wp qvc-emails apply-base
         *   wp qvc-emails apply-base --only=fl_new_user_admin,fl_user_reset_pwd
         *   wp qvc-emails apply-base --dry-run
         */
        public function apply_base( $args, $assoc_args ) {
            $templates = QvaClick_Email_Discovery::discover_email_templates();
            $apply_to = 'all';
            if ( isset($assoc_args['only']) ) {
                $keys = array_filter(array_map('trim', explode(',', $assoc_args['only'])));
                $apply_to = $keys;
            }
            if ( isset($assoc_args['dry-run']) ) {
                // Simulación: contar cuántos se afectarían
                $count = 0;
                foreach ($templates as $k => $t) {
                    if ($apply_to !== 'all' && !in_array($k, $apply_to, true)) continue;
                    $count++;
                }
                \WP_CLI::success("Dry-run: se aplicarían cambios a {$count} template(s).");
                return;
            }
            $result = QvaClick_Base_Template_Manager::apply_to_templates('', $apply_to);
            if ($result['success']) {
                \WP_CLI::success($result['message']);
            } else {
                \WP_CLI::error($result['message']);
            }
        }

        /**
         * Obtiene o establece la plantilla base.
         *
         * ## OPTIONS
         * [--set=<file>]
         * : Archivo cuyo contenido se usará como nueva plantilla base. Use '-' para STDIN.
         *
         * [--output=<file>]
         * : Si se usa sin --set, exporta la plantilla base actual al archivo.
         *
         * ## EXAMPLES
         *   wp qvc-emails base-template
         *   wp qvc-emails base-template --output=base.html
         *   wp qvc-emails base-template --set=nueva_base.html
         *   cat base.html | wp qvc-emails base-template --set=-
         */
        public function base_template( $args, $assoc_args ) {
            if ( isset($assoc_args['set']) ) {
                $file = $assoc_args['set'];
                if ($file === '-') {
                    $content = stream_get_contents(STDIN);
                } else {
                    if (!file_exists($file)) {
                        \WP_CLI::error("Archivo no encontrado: {$file}");
                    }
                    $content = file_get_contents($file);
                }
                QvaClick_Base_Template_Manager::save_base_template($content);
                \WP_CLI::success('Plantilla base actualizada.');
                return;
            }
            $base = QvaClick_Base_Template_Manager::get_base_template();
            if ( isset($assoc_args['output']) ) {
                file_put_contents($assoc_args['output'], $base);
                \WP_CLI::success('Plantilla base exportada a ' . $assoc_args['output']);
            } else {
                echo $base; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
        }

        /**
         * Exporta los templates a archivos individuales.
         *
         * ## OPTIONS
         * --dir=<path>
         * : Directorio destino (se crea si no existe).
         *
         * [--filter=<keys>]
         * : Lista separada por coma de base_keys a exportar. (por defecto todos)
         *
         * [--preview]
         * : Usa versión con placeholders reemplazados por datos de ejemplo.
         *
         * [--format=<fmt>]
         * : html (default) o txt (elimina etiquetas).
         *
         * [--overwrite]
         * : Sobrescribe archivos existentes.
         *
         * ## EXAMPLES
         *   wp qvc-emails export --dir=emails_export
         *   wp qvc-emails export --dir=emails_export --preview --format=txt
         *   wp qvc-emails export --dir=emails_export --filter=fl_new_user_admin,fl_user_reset_pwd
         */
        public function export( $args, $assoc_args ) {
            if ( empty($assoc_args['dir']) ) {
                \WP_CLI::error('Debe indicar --dir=DESTINO');
            }
            $dir = rtrim($assoc_args['dir'], '/\\');
            $format = isset($assoc_args['format']) ? strtolower($assoc_args['format']) : 'html';
            $preview = isset($assoc_args['preview']);
            $overwrite = isset($assoc_args['overwrite']);
            $filter_keys = [];
            if ( isset($assoc_args['filter']) ) {
                $filter_keys = array_filter(array_map('trim', explode(',', $assoc_args['filter'])));
            }
            if ( ! file_exists($dir) ) {
                if ( ! mkdir($dir, 0775, true) ) {
                    \WP_CLI::error('No se pudo crear el directorio destino.');
                }
            }
            $templates = QvaClick_Email_Discovery::discover_email_templates();
            $exported = 0; $skipped = 0;
            foreach ($templates as $base_key => $t) {
                if ($filter_keys && !in_array($base_key, $filter_keys, true)) { continue; }
                $body = $t['body'];
                if ($preview) { $body = QvaClick_Base_Template_Manager::generate_preview($body); }
                if ($format === 'txt') {
                    $body_content = wp_strip_all_tags($body);
                } else {
                    $body_content = $body;
                }
                $filename = $dir . DIRECTORY_SEPARATOR . $base_key . '.' . ($format === 'txt' ? 'txt' : 'html');
                if ( file_exists($filename) && !$overwrite ) {
                    $skipped++;
                    continue;
                }
                file_put_contents($filename, $body_content);
                $exported++;
            }
            \WP_CLI::success("Exportados {$exported} archivo(s). Omitidos {$skipped}.");
        }

        /**
         * Valida placeholders y consistencia básica.
         *
         * Reglas actuales:
         * - Detecta placeholders en subject y body (%x% y {x}).
         * - Lista placeholders por template y resumen global.
         * - Marca placeholders con espacios o caracteres sospechosos.
         * - Detecta placeholders presentes en subject pero no en body.
         *
         * ## OPTIONS
         * [--json]
         * : Devuelve resultado en JSON.
         *
         * [--missing-only]
         * : Muestra solo discrepancias / advertencias.
         *
         * ## EXAMPLES
         *   wp qvc-emails validate
         *   wp qvc-emails validate --json > placeholders.json
         *   wp qvc-emails validate --missing-only
         */
        public function validate( $args, $assoc_args ) {
            $templates = QvaClick_Email_Discovery::discover_email_templates();
            $issues_only = isset($assoc_args['missing-only']);
            $results = [];
            $global_usage = [];

            foreach ($templates as $base_key => $t) {
                $body = $t['body'];
                $subject = $t['subject'];
                $body_ph = $this->extract_placeholders($body);
                $subject_ph = $this->extract_placeholders($subject);
                $all_ph = array_unique(array_merge($body_ph, $subject_ph));
                foreach ($all_ph as $ph) {
                    if (!isset($global_usage[$ph])) { $global_usage[$ph] = 0; }
                    $global_usage[$ph]++;
                }
                // Checks
                $suspicious = array_filter($all_ph, function($ph){ return preg_match('/\s/', $ph) || strlen($ph) > 40; });
                $subject_not_in_body = array_diff($subject_ph, $body_ph);
                $warnings = [];
                if ($suspicious) { $warnings[] = 'placeholders_sospechosos: ' . implode(',', $suspicious); }
                if ($subject_not_in_body) { $warnings[] = 'subject_no_body: ' . implode(',', $subject_not_in_body); }
                if (empty($body_ph) && empty($subject_ph)) { $warnings[] = 'sin_placeholders'; }
                $entry = [
                    'base_key' => $base_key,
                    'name' => $t['name'],
                    'placeholders_body' => $body_ph,
                    'placeholders_subject' => $subject_ph,
                    'warnings' => $warnings
                ];
                if (!$issues_only || ($issues_only && $warnings)) {
                    $results[] = $entry;
                }
            }
            ksort($global_usage);
            $output = [
                'templates' => $results,
                'totals' => [
                    'templates_analizados' => count($templates),
                    'templates_reportados' => count($results),
                    'placeholders_unicos' => count($global_usage)
                ],
                'global_usage' => $global_usage
            ];
            if ( isset($assoc_args['json']) ) {
                echo wp_json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                return;
            }
            // Human readable
            \WP_CLI::line('Resumen: ' . $output['totals']['templates_reportados'] . ' templates reportados (' . $output['totals']['templates_analizados'] . ' analizados) | ' . $output['totals']['placeholders_unicos'] . ' placeholders únicos');
            foreach ($results as $r) {
                \WP_CLI::line("- {$r['base_key']} ({$r['name']}): body=" . count($r['placeholders_body']) . ' subj=' . count($r['placeholders_subject']) . ( $r['warnings'] ? ' WARN: ' . implode('; ', $r['warnings']) : '' ));
            }
        }

        private function extract_placeholders($content) {
            $ph = [];
            if (preg_match_all('/%([^%]+)%/', $content, $m)) { $ph = array_merge($ph, $m[1]); }
            if (preg_match_all('/\{([^}]+)\}/', $content, $m2)) { $ph = array_merge($ph, $m2[1]); }
            return array_values(array_unique(array_map('trim', $ph)));
        }
    }
}
