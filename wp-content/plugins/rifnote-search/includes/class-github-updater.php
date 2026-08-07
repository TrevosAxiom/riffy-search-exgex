<?php
/**
 * GitHub Releases updater for the Rifnote Search plugin.
 *
 * WordPress can discover releases through the plugin update API. Release zips
 * should contain a top-level rifnote-search/ folder, which the bundled GitHub
 * Action creates for us.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_GitHub_Updater {
    const TRANSIENT_KEY = 'rifnote_github_updater_release';
    const DEFAULT_REPO = 'TrevosAxiom/riffy-search-exgex';
    const DEFAULT_ASSET = 'rifnote-search.zip';

    public static function init() {
        add_action('updated_option', array(__CLASS__, 'maybe_clear_cache_on_settings_update'), 10, 3);

        if (!self::enabled()) {
            return;
        }

        add_filter('pre_set_site_transient_update_plugins', array(__CLASS__, 'check_for_update'));
        add_filter('plugins_api', array(__CLASS__, 'plugin_information'), 20, 3);
        add_filter('upgrader_pre_download', array(__CLASS__, 'authenticated_asset_download'), 10, 3);
    }

    public static function settings() {
        return array(
            'enabled' => (bool) get_option('rifnote_github_updater_enabled', true),
            'repo' => self::normalize_repo(get_option('rifnote_github_repo', self::DEFAULT_REPO)),
            'asset_name' => sanitize_file_name((string) get_option('rifnote_github_asset_name', self::DEFAULT_ASSET)) ?: self::DEFAULT_ASSET,
            'access_token' => (string) get_option('rifnote_github_access_token', ''),
        );
    }

    public static function enabled() {
        return (bool) get_option('rifnote_github_updater_enabled', true);
    }

    public static function plugin_basename() {
        return plugin_basename(RIFNOTE_SEARCH_FILE);
    }

    public static function slug() {
        return dirname(self::plugin_basename());
    }

    public static function normalize_repo($repo) {
        $repo = trim((string) $repo);
        $repo = preg_replace('#^https?://github\.com/#i', '', $repo);
        $repo = preg_replace('#\.git$#i', '', $repo);
        $repo = trim((string) $repo, " \t\n\r\0\x0B/");

        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            return self::DEFAULT_REPO;
        }

        return $repo;
    }

    public static function check_for_update($transient) {
        if (!is_object($transient)) {
            $transient = new stdClass();
        }

        if (empty($transient->checked) || !isset($transient->checked[self::plugin_basename()])) {
            return $transient;
        }

        $release = self::latest_release(false);
        if (is_wp_error($release) || empty($release['version'])) {
            return $transient;
        }

        if (version_compare($release['version'], RIFNOTE_SEARCH_VERSION, '<=')) {
            unset($transient->response[self::plugin_basename()]);
            return $transient;
        }

        $transient->response[self::plugin_basename()] = self::update_object($release);
        return $transient;
    }

    public static function plugin_information($result, $action, $args) {
        if ('plugin_information' !== $action || empty($args->slug) || self::slug() !== $args->slug) {
            return $result;
        }

        $release = self::latest_release(false);
        if (is_wp_error($release)) {
            return $result;
        }

        $info = self::update_object($release);
        $info->name = __('Rifnote Search', 'rifnote-search');
        $info->author = '<a href="https://rifnote.com/">Rifnote</a>';
        $info->homepage = 'https://github.com/' . self::settings()['repo'];
        $info->requires = '6.0';
        $info->requires_php = '7.4';
        $info->tested = get_bloginfo('version');
        $info->last_updated = $release['published_at'] ?? '';
        $info->sections = array(
            'description' => __('Rifnote Search platform plugin updates delivered from GitHub Releases.', 'rifnote-search'),
            'changelog' => wp_kses_post(nl2br((string) ($release['body'] ?? ''))),
        );

        return $info;
    }

    private static function update_object($release) {
        $settings = self::settings();
        $object = new stdClass();
        $object->id = 'https://github.com/' . $settings['repo'];
        $object->slug = self::slug();
        $object->plugin = self::plugin_basename();
        $object->new_version = $release['version'];
        $object->url = 'https://github.com/' . $settings['repo'] . '/releases/tag/' . rawurlencode($release['tag_name']);
        $object->package = $release['package_url'];
        $object->icons = array(
            'svg' => RIFNOTE_SEARCH_URL . 'public/rifnote-favicon.svg',
        );

        return $object;
    }

    public static function latest_release($force = false) {
        if (!$force) {
            $cached = get_site_transient(self::TRANSIENT_KEY);
            if (is_array($cached) || is_wp_error($cached)) {
                return $cached;
            }
        }

        $settings = self::settings();
        $repo_path = self::api_repo_path($settings['repo']);
        $url = 'https://api.github.com/repos/' . $repo_path . '/releases/latest';
        $response = self::github_get($url);

        if (is_wp_error($response)) {
            self::store_error($response->get_error_message());
            set_site_transient(self::TRANSIENT_KEY, $response, 5 * MINUTE_IN_SECONDS);
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if (404 === $code) {
            $fallback = self::github_get('https://api.github.com/repos/' . $repo_path . '/releases?per_page=5');

            if (is_wp_error($fallback)) {
                self::store_error($fallback->get_error_message());
                set_site_transient(self::TRANSIENT_KEY, $fallback, 5 * MINUTE_IN_SECONDS);
                return $fallback;
            }

            $fallback_code = (int) wp_remote_retrieve_response_code($fallback);
            $fallback_body = json_decode((string) wp_remote_retrieve_body($fallback), true);

            if (200 === $fallback_code && is_array($fallback_body) && !empty($fallback_body[0]) && is_array($fallback_body[0])) {
                $body = $fallback_body[0];
                $code = 200;
            } elseif (200 === $fallback_code && is_array($fallback_body) && empty($fallback_body)) {
                $message = sprintf(__('GitHub can see %s, but no Releases exist yet. Push a v* tag and let the release workflow attach %s.', 'rifnote-search'), $settings['repo'], $settings['asset_name']);
                self::store_error($message);
                $error = new WP_Error('rifnote_github_no_releases', $message);
                set_site_transient(self::TRANSIENT_KEY, $error, 5 * MINUTE_IN_SECONDS);
                return $error;
            } else {
                $message = self::github_http_error_message($fallback_code, $settings);
                self::store_error($message);
                $error = new WP_Error('rifnote_github_release_error', $message);
                set_site_transient(self::TRANSIENT_KEY, $error, 5 * MINUTE_IN_SECONDS);
                return $error;
            }
        }

        if (200 !== $code || !is_array($body)) {
            $message = self::github_http_error_message($code, $settings);
            self::store_error($message);
            $error = new WP_Error('rifnote_github_release_error', $message);
            set_site_transient(self::TRANSIENT_KEY, $error, 5 * MINUTE_IN_SECONDS);
            return $error;
        }

        $asset = self::find_asset($body, $settings['asset_name']);
        if (!$asset) {
            $message = sprintf(__('Release found, but asset "%s" was not attached.', 'rifnote-search'), $settings['asset_name']);
            self::store_error($message);
            $error = new WP_Error('rifnote_github_asset_missing', $message);
            set_site_transient(self::TRANSIENT_KEY, $error, 5 * MINUTE_IN_SECONDS);
            return $error;
        }

        $tag = (string) ($body['tag_name'] ?? '');
        $release = array(
            'tag_name' => $tag,
            'version' => ltrim($tag, "vV \t\n\r\0\x0B"),
            'body' => (string) ($body['body'] ?? ''),
            'published_at' => (string) ($body['published_at'] ?? ''),
            'package_url' => self::package_url($asset),
            'asset_name' => (string) ($asset['name'] ?? ''),
        );

        update_option('rifnote_github_last_checked', current_time('mysql'), false);
        update_option('rifnote_github_last_error', '', false);
        set_site_transient(self::TRANSIENT_KEY, $release, 15 * MINUTE_IN_SECONDS);
        return $release;
    }

    private static function github_get($url) {
        return wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => self::github_headers(),
        ));
    }

    private static function github_http_error_message($code, $settings) {
        $repo = $settings['repo'] ?? self::DEFAULT_REPO;

        if (404 === (int) $code) {
            if (empty($settings['access_token'])) {
                return sprintf(__('GitHub returned HTTP 404 for %s. If this repository is private, add a GitHub access token with contents read access. If it is public, confirm the repo name and that a Release exists.', 'rifnote-search'), $repo);
            }

            return sprintf(__('GitHub returned HTTP 404 for %s even with a token. Confirm the token can access the repository and that a Release exists.', 'rifnote-search'), $repo);
        }

        if (401 === (int) $code || 403 === (int) $code) {
            return sprintf(__('GitHub returned HTTP %d for %s. Check the access token permissions and API rate limits.', 'rifnote-search'), (int) $code, $repo);
        }

        return sprintf(__('GitHub returned HTTP %d while checking releases for %s.', 'rifnote-search'), (int) $code, $repo);
    }

    private static function find_asset($release, $asset_name) {
        $assets = isset($release['assets']) && is_array($release['assets']) ? $release['assets'] : array();
        foreach ($assets as $asset) {
            if (!empty($asset['name']) && $asset_name === $asset['name']) {
                return $asset;
            }
        }

        return null;
    }

    private static function package_url($asset) {
        $settings = self::settings();
        if (!empty($settings['access_token']) && !empty($asset['url'])) {
            return esc_url_raw((string) $asset['url']);
        }

        return esc_url_raw((string) ($asset['browser_download_url'] ?? ''));
    }

    public static function authenticated_asset_download($reply, $package, $upgrader) {
        unset($upgrader);

        if ($reply || !self::is_github_asset_api_url($package)) {
            return $reply;
        }

        $settings = self::settings();
        if (empty($settings['access_token'])) {
            return $reply;
        }

        $tmp = wp_tempnam($package);
        if (!$tmp) {
            return new WP_Error('rifnote_github_temp_file', __('Could not create a temporary file for the GitHub asset.', 'rifnote-search'));
        }

        $response = wp_remote_get($package, array(
            'timeout' => 300,
            'redirection' => 5,
            'stream' => true,
            'filename' => $tmp,
            'headers' => self::github_headers(true),
        ));

        if (is_wp_error($response)) {
            @unlink($tmp);
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            @unlink($tmp);
            return new WP_Error('rifnote_github_download_error', sprintf(__('GitHub asset download returned HTTP %d.', 'rifnote-search'), $code));
        }

        return $tmp;
    }

    public static function maybe_clear_cache_on_settings_update($option, $old_value, $value) {
        unset($old_value, $value);

        if (in_array($option, array('rifnote_github_updater_enabled', 'rifnote_github_repo', 'rifnote_github_asset_name', 'rifnote_github_access_token'), true)) {
            delete_site_transient(self::TRANSIENT_KEY);
            delete_site_transient('update_plugins');
        }
    }

    private static function is_github_asset_api_url($url) {
        $settings = self::settings();
        return is_string($url) && false !== strpos($url, 'api.github.com/repos/' . $settings['repo'] . '/releases/assets/');
    }

    private static function api_repo_path($repo) {
        $parts = explode('/', self::normalize_repo($repo), 2);
        return rawurlencode($parts[0]) . '/' . rawurlencode($parts[1]);
    }

    private static function github_headers($download = false) {
        $settings = self::settings();
        $headers = array(
            'Accept' => $download ? 'application/octet-stream' : 'application/vnd.github+json',
            'User-Agent' => 'Rifnote-Search-Updater/' . RIFNOTE_SEARCH_VERSION,
            'X-GitHub-Api-Version' => '2022-11-28',
        );

        if (!empty($settings['access_token'])) {
            $headers['Authorization'] = 'Bearer ' . $settings['access_token'];
        }

        return $headers;
    }

    private static function store_error($message) {
        update_option('rifnote_github_last_checked', current_time('mysql'), false);
        update_option('rifnote_github_last_error', sanitize_text_field((string) $message), false);
    }
}
