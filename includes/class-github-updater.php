<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Lightweight GitHub updater for a single plugin.
 * - Checks latest tag via GitHub API (releases or tags fallback)
 * - Injects update info into WP transient
 * - Supplies package zip URL and post-install renaming
 */
class QVC_GitHub_Updater {
    private $owner;
    private $repo;
    private $plugin_basename;
    private $plugin_dirname;
    private $current_version;

    public function __construct($args) {
        $this->owner = $args['owner'];
        $this->repo  = $args['repo'];
        $this->plugin_basename = $args['plugin_basename'];
        $this->plugin_dirname  = $args['plugin_dirname'];
        $this->current_version = $args['current_version'];
    }

    public function init() {
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugins_api'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'post_install'), 10, 3);
    }

    private function api_get($endpoint) {
        $url = "https://api.github.com/repos/{$this->owner}/{$this->repo}/{$endpoint}";
        $args = array(
            'headers' => array(
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'QVC-Email-Manager-Updater'
            ),
            'timeout' => 15,
        );
        $resp = wp_remote_get($url, $args);
        if (is_wp_error($resp)) return false;
        $code = wp_remote_retrieve_response_code($resp);
        if ($code !== 200) return false;
        $body = wp_remote_retrieve_body($resp);
        return json_decode($body, true);
    }

    private function get_latest_release_or_tag() {
        // Try releases first
        $release = $this->api_get('releases/latest');
        if (is_array($release) && !empty($release['tag_name'])) {
            return array(
                'version' => ltrim($release['tag_name'], 'v'),
                'zipball' => $release['zipball_url'],
                'changelog' => isset($release['body']) ? $release['body'] : ''
            );
        }
        // Fallback to latest tag
        $tags = $this->api_get('tags');
        if (is_array($tags) && !empty($tags[0]['name'])) {
            $tag = $tags[0];
            return array(
                'version' => ltrim($tag['name'], 'v'),
                'zipball' => $tag['zipball_url'],
                'changelog' => ''
            );
        }
        return false;
    }

    public function check_for_update($transient) {
        if (empty($transient->checked)) return $transient;

        $latest = $this->get_latest_release_or_tag();
        if (!$latest) return $transient;

        $current = $this->current_version;
        if (version_compare($latest['version'], $current, '<=')) return $transient;

        $obj = new stdClass();
        $obj->slug = $this->plugin_dirname;
        $obj->plugin = $this->plugin_basename;
        $obj->new_version = $latest['version'];
        $obj->url = "https://github.com/{$this->owner}/{$this->repo}";
        $obj->package = $latest['zipball'];
        $obj->tested = get_bloginfo('version');
        $obj->requires = '5.8';

        $transient->response[$this->plugin_basename] = $obj;
        return $transient;
    }

    public function plugins_api($result, $action, $args) {
        if ($action !== 'plugin_information') return $result;
        if (empty($args->slug) || $args->slug !== $this->plugin_dirname) return $result;

        $latest = $this->get_latest_release_or_tag();
        if (!$latest) return $result;

        $res = new stdClass();
        $res->name = 'QvaClick Email Manager V1';
        $res->slug = $this->plugin_dirname;
        $res->version = $latest['version'];
        $res->author = '<a href="https://qvaclick.com/">QvaClick</a>';
        $res->homepage = "https://github.com/{$this->owner}/{$this->repo}";
        $res->download_link = $latest['zipball'];
        $res->sections = array(
            'description' => 'Actualizaciones desde GitHub. Ver README y CHANGELOG en el repositorio.',
            'changelog' => !empty($latest['changelog']) ? wp_kses_post(nl2br($latest['changelog'])) : 'Ver CHANGELOG.md en el repositorio.'
        );
        return $res;
    }

    public function post_install($response, $hook_extra, $result) {
        // Renombrar el directorio descargado (zipball genera carpeta owner-repo-<hash>)
        if (!isset($result['destination']) || !is_dir($result['destination'])) return $result;
        $dest = untrailingslashit($result['destination']);
        $parent = dirname($dest);
        $expected = trailingslashit(WP_PLUGIN_DIR) . $this->plugin_dirname;

        if (trailingslashit($dest) !== trailingslashit($expected) && is_dir($parent)) {
            // Mover al nombre esperado del plugin
            $this->recursive_delete($expected);
            @rename($dest, $expected);
            $result['destination'] = $expected;
        }
        return $result;
    }

    private function recursive_delete($path) {
        if (!file_exists($path)) return;
        if (is_file($path) || is_link($path)) { @unlink($path); return; }
        $items = scandir($path);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $this->recursive_delete($path . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($path);
    }
}
