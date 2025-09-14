<?php
/**
 * Script de verificación de sintaxis
 * Este script verifica que no hay errores de sintaxis en imap-reader.php
 */

// Simular algunas funciones de WordPress para evitar errores
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) {
        return dirname($file) . '/';
    }
}

if (!function_exists('current_time')) {
    function current_time($type) {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('dbDelta')) {
    function dbDelta($sql) {
        return true;
    }
}

if (!function_exists('wp_mail')) {
    function wp_mail($to, $subject, $message, $headers = '') {
        return true;
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        return $default;
    }
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// Verificar la sintaxis del archivo
echo "Verificando sintaxis de imap-reader.php...\n";

$file_path = __DIR__ . '/includes/imap-reader.php';

if (!file_exists($file_path)) {
    echo "ERROR: Archivo no encontrado: $file_path\n";
    exit(1);
}

// Intentar incluir el archivo
ob_start();
$error = false;

try {
    include_once $file_path;
    echo "✅ ÉXITO: No se encontraron errores de sintaxis PHP\n";
} catch (ParseError $e) {
    $error = true;
    echo "❌ ERROR DE SINTAXIS: " . $e->getMessage() . "\n";
    echo "Línea: " . $e->getLine() . "\n";
} catch (Error $e) {
    $error = true;
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "Línea: " . $e->getLine() . "\n";
} catch (Exception $e) {
    echo "⚠️  ADVERTENCIA: " . $e->getMessage() . "\n";
}

ob_end_clean();

if (!$error) {
    echo "✅ Verificación completada exitosamente\n";
    echo "El archivo imap-reader.php está sintácticamente correcto\n";
} else {
    echo "❌ Se encontraron errores que necesitan corrección\n";
    exit(1);
}
?>
