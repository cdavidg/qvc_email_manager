<?php
// Safe check for mail-related hooks without sending anything.
// This file is intended to be run via `wp eval-file` so WP is already bootstrapped.

function describe_callable($cb) {
    if (is_string($cb)) return $cb;
    if (is_array($cb)) {
        if (is_object($cb[0])) {
            return get_class($cb[0]) . '->' . $cb[1];
        } else {
            return $cb[0] . '::' . $cb[1];
        }
    }
    if ($cb instanceof Closure) return 'Closure';
    return var_export($cb, true);
}

$hooks = array('pre_wp_mail', 'wp_mail');
foreach ($hooks as $hook) {
    echo "Hook: $hook\n";
    if (!isset($GLOBALS['wp_filter'][$hook])) {
        echo "  (no hook registered)\n\n";
        continue;
    }
    $wp_hook = $GLOBALS['wp_filter'][$hook];
    // WP_Hook stores callbacks under ->callbacks with priority keys
    if (is_object($wp_hook) && isset($wp_hook->callbacks) && is_array($wp_hook->callbacks)) {
        foreach ($wp_hook->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $id => $data) {
                if (isset($data['function'])) {
                    echo "  priority=$priority -> " . describe_callable($data['function']) . "\n";
                } else {
                    echo "  priority=$priority -> unknown callback (" . var_export($data, true) . ")\n";
                }
            }
        }
    } else {
        // Fallback: try printing it
        echo "  (unexpected structure) " . var_export($wp_hook, true) . "\n";
    }
    echo "\n";
}

// Also check for MU-plugin files that look like emergency kill switch
$mu_dir = WPMU_PLUGIN_DIR;
if ($mu_dir && is_dir($mu_dir)) {
    echo "MU-plugins in " . $mu_dir . ":\n";
    $files = scandir($mu_dir);
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        echo "  " . $f . "\n";
    }
    echo "\n";
} else {
    echo "No MU-plugins directory or not available.\n";
}

// Also report option that may control emergency mode
if (get_option('qvc_email_emergency_mode') !== false) {
    echo "Option qvc_email_emergency_mode present: " . var_export(get_option('qvc_email_emergency_mode'), true) . "\n";
}

