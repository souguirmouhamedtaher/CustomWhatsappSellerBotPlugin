<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GitHub Releases updater for the plugin.
 */
class CWSB_Plugin_Updater
{
    const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    /** @var CWSB_Plugin_Updater|null */
    private static $instance = null;

    /** @var string */
    private $plugin_file;

    /** @var string */
    private $plugin_basename;

    /** @var string */
    private $plugin_slug;

    /** @var string */
    private $current_version;

    /** @var string */
    private $repo_owner;

    /** @var string */
    private $repo_name;

    /** @var string */
    private $repo_url;

    /** @var string */
    private $cache_key;

    public static function bootstrap($plugin_file, $repo_owner, $repo_name)
    {
        if (self::$instance instanceof self) {
            return self::$instance;
        }

        self::$instance = new self($plugin_file, $repo_owner, $repo_name);
        return self::$instance;
    }

    private function __construct($plugin_file, $repo_owner, $repo_name)
    {
        $this->plugin_file = (string) $plugin_file;
        $this->plugin_basename = plugin_basename($this->plugin_file);
        $this->plugin_slug = dirname($this->plugin_basename);
        if ($this->plugin_slug === '.' || $this->plugin_slug === '') {
            $this->plugin_slug = basename($this->plugin_basename, '.php');
        }

        $this->repo_owner = trim((string) $repo_owner);
        $this->repo_name = trim((string) $repo_name);
        $this->repo_url = sprintf('https://github.com/%s/%s', $this->repo_owner, $this->repo_name);
        $this->cache_key = 'cwsb_updater_release_' . md5($this->repo_owner . '/' . $this->repo_name);

        $this->current_version = $this->read_current_version();

        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api', [$this, 'plugins_api'], 20, 3);
        add_action('upgrader_process_complete', [$this, 'clear_cache_after_upgrade'], 10, 2);
    }

    private function read_current_version()
    {
        if (!function_exists('get_file_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_data = get_file_data($this->plugin_file, ['Version' => 'Version']);
        return isset($plugin_data['Version']) ? (string) $plugin_data['Version'] : '';
    }

    private function get_latest_release()
    {
        $cached = get_site_transient($this->cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $endpoint = sprintf('https://api.github.com/repos/%s/%s/releases/latest', $this->repo_owner, $this->repo_name);
        $response = wp_remote_get($endpoint, [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'Custom-WhatsApp-Seller-Bot-Updater',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }

        $tag_name = isset($data['tag_name']) ? trim((string) $data['tag_name']) : '';
        $version = ltrim($tag_name, 'vV');
        if ($version === '') {
            return null;
        }

        $package_url = '';
        if (!empty($data['assets']) && is_array($data['assets'])) {
            foreach ($data['assets'] as $asset) {
                if (!is_array($asset)) {
                    continue;
                }

                $name = isset($asset['name']) ? (string) $asset['name'] : '';
                $download_url = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';
                if ($download_url === '') {
                    continue;
                }

                if (substr($name, -4) === '.zip') {
                    $package_url = $download_url;
                    if (stripos($name, $this->repo_name) !== false) {
                        break;
                    }
                }
            }
        }

        if ($package_url === '') {
            $package_url = isset($data['zipball_url']) ? (string) $data['zipball_url'] : '';
        }

        if ($package_url === '') {
            return null;
        }

        $release = [
            'version' => $version,
            'tag' => $tag_name,
            'package' => $package_url,
            'url' => isset($data['html_url']) ? (string) $data['html_url'] : $this->repo_url,
            'name' => isset($data['name']) && (string) $data['name'] !== '' ? (string) $data['name'] : $tag_name,
            'body' => isset($data['body']) ? (string) $data['body'] : '',
            'published_at' => isset($data['published_at']) ? (string) $data['published_at'] : '',
        ];

        set_site_transient($this->cache_key, $release, self::CACHE_TTL);
        return $release;
    }

    public function inject_update($transient)
    {
        if (!is_object($transient) || !isset($transient->checked) || !is_array($transient->checked)) {
            return $transient;
        }

        if ($this->current_version === '' || $this->repo_owner === '' || $this->repo_name === '') {
            return $transient;
        }

        $release = $this->get_latest_release();
        if (!is_array($release)) {
            return $transient;
        }

        if (version_compare($release['version'], $this->current_version, '<=')) {
            return $transient;
        }

        $plugin_update = (object) [
            'slug' => $this->plugin_slug,
            'plugin' => $this->plugin_basename,
            'new_version' => $release['version'],
            'tested' => get_bloginfo('version'),
            'url' => $release['url'],
            'package' => $release['package'],
        ];

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }

        $transient->response[$this->plugin_basename] = $plugin_update;
        return $transient;
    }

    public function plugins_api($result, $action, $args)
    {
        if ($action !== 'plugin_information' || !is_object($args) || !isset($args->slug) || (string) $args->slug !== $this->plugin_slug) {
            return $result;
        }

        $release = $this->get_latest_release();
        if (!is_array($release)) {
            return $result;
        }

        $plugin_info = (object) [
            'name' => 'Custom WhatsApp Seller Bot',
            'slug' => $this->plugin_slug,
            'version' => $release['version'],
            'author' => 'ILEYCOM-INTERNSHIPS',
            'homepage' => $this->repo_url,
            'requires' => '6.0',
            'tested' => get_bloginfo('version'),
            'download_link' => $release['package'],
            'last_updated' => $release['published_at'],
            'sections' => [
                'description' => 'Seller lookup endpoints for WhatsApp bot.',
                'changelog' => $this->format_changelog($release),
            ],
            'banners' => [],
            'external' => true,
        ];

        return $plugin_info;
    }

    private function format_changelog($release)
    {
        $lines = [];
        $lines[] = sprintf('<h4>%s</h4>', esc_html($release['name']));
        if (!empty($release['body'])) {
            $lines[] = wpautop(esc_html($release['body']));
        } else {
            $lines[] = '<p>No release notes were provided for this version.</p>';
        }

        return implode("\n", $lines);
    }

    public function clear_cache_after_upgrade($upgrader, $options)
    {
        if (!is_array($options) || !isset($options['type'], $options['action'])) {
            return;
        }

        if ($options['type'] !== 'plugin' || $options['action'] !== 'update') {
            return;
        }

        delete_site_transient($this->cache_key);
    }
}
