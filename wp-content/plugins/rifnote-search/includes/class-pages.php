<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Pages {
    private static $current_mode = '';
    const REWRITE_VERSION = '20260711_story_source_routes_v2';

    public static function page_configs() {
        return array(
            'search' => array('title' => __('Search', 'rifnote-search'), 'mode' => 'app', 'shortcode' => 'rifnote_search_app'),
            'publisher-signup' => array('title' => __('Publisher Sign Up', 'rifnote-search'), 'mode' => 'publisher-signup', 'shortcode' => 'rifnote_publisher_signup'),
            'submit-news' => array('title' => __('Submit News', 'rifnote-search'), 'mode' => 'publisher-submit', 'shortcode' => 'rifnote_publisher_submit'),
            'publisher-dashboard' => array('title' => __('Publisher Dashboard', 'rifnote-search'), 'mode' => 'publisher-dashboard', 'shortcode' => 'rifnote_publisher_dashboard'),
            'football' => array('title' => __('Football', 'rifnote-search'), 'mode' => 'football-hub', 'shortcode' => 'rifnote_football_hub'),
            'teams' => array('title' => __('Teams', 'rifnote-search'), 'mode' => 'team-search', 'shortcode' => 'rifnote_team_search'),
            'players' => array('title' => __('Players', 'rifnote-search'), 'mode' => 'player-search', 'shortcode' => 'rifnote_player_search'),
            'transfers' => array('title' => __('Transfer Tracker', 'rifnote-search'), 'mode' => 'transfer-tracker', 'shortcode' => 'rifnote_transfer_tracker'),
            'weather' => array('title' => __('Weather', 'rifnote-search'), 'mode' => 'weather', 'shortcode' => 'rifnote_weather'),
            'contact-us' => array('title' => __('Contact Us', 'rifnote-search'), 'mode' => 'contact', 'shortcode' => 'rifnote_contact'),
            'publisher-docs' => array('title' => __('Publisher Docs', 'rifnote-search'), 'mode' => 'publisher-docs', 'shortcode' => 'rifnote_publisher_docs'),
            'dmca' => array('title' => __('DMCA Removal Request', 'rifnote-search'), 'mode' => 'legal-dmca', 'shortcode' => 'rifnote_dmca_request'),
            'publisher-opt-out' => array('title' => __('Publisher Opt-Out', 'rifnote-search'), 'mode' => 'legal-opt-out', 'shortcode' => 'rifnote_publisher_opt_out'),
            'beta-feedback' => array('title' => __('Beta Feedback', 'rifnote-search'), 'mode' => 'beta-feedback', 'shortcode' => 'rifnote_beta_feedback'),
            'daily-briefing' => array('title' => __('Daily Briefing', 'rifnote-search'), 'mode' => 'daily-briefing', 'shortcode' => 'rifnote_search_app'),
            'for-you' => array('title' => __('For You', 'rifnote-search'), 'mode' => 'for-you', 'shortcode' => 'rifnote_for_you'),
            'newsletter' => array('title' => __('Newsletter', 'rifnote-search'), 'mode' => 'newsletter-signup', 'shortcode' => 'rifnote_newsletter_signup'),
            'advertiser-signup' => array('title' => __('Advertiser Sign Up', 'rifnote-search'), 'mode' => 'advertiser-signup', 'shortcode' => 'rifnote_advertiser_signup'),
            'advertise' => array('title' => __('Advertise', 'rifnote-search'), 'mode' => 'sponsor-request', 'shortcode' => 'rifnote_sponsor_request'),
            'advertiser-dashboard' => array('title' => __('Advertiser Dashboard', 'rifnote-search'), 'mode' => 'advertiser-dashboard', 'shortcode' => 'rifnote_advertiser_dashboard'),
        );
    }

    public static function ensure_pages() {
        foreach (self::page_configs() as $slug => $config) {
            $page = get_page_by_path($slug, OBJECT, 'page');

            if ($page) {
                continue;
            }

            wp_insert_post(array(
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_name' => $slug,
                'post_title' => $config['title'],
                'post_content' => '[' . $config['shortcode'] . ']',
            ));
        }
    }

    public static function register_rewrites() {
        add_rewrite_tag('%rifnote_story_cluster%', '([^&]+)');
        add_rewrite_tag('%rifnote_source_domain%', '([^&]+)');
        add_rewrite_rule('^story/([^/]+)/?$', 'index.php?rifnote_story_cluster=$matches[1]', 'top');
        add_rewrite_rule('^source/([^/]+)/?$', 'index.php?rifnote_source_domain=$matches[1]', 'top');
    }

    public static function query_vars($vars) {
        $vars[] = 'rifnote_story_cluster';
        $vars[] = 'rifnote_source_domain';

        return array_values(array_unique($vars));
    }

    public static function maybe_flush_rewrites() {
        if (get_option('rifnote_search_rewrite_version') === self::REWRITE_VERSION) {
            return;
        }

        self::register_rewrites();
        flush_rewrite_rules(false);
        update_option('rifnote_search_rewrite_version', self::REWRITE_VERSION, false);
    }

    public static function activate() {
        self::ensure_pages();
        self::register_rewrites();
        flush_rewrite_rules(false);
        update_option('rifnote_search_rewrite_version', self::REWRITE_VERSION, false);
    }

    public static function template_include($template) {
        $mode = self::mode_for_request();

        if ($mode) {
            self::$current_mode = $mode;
            status_header(200);
            nocache_headers();

            return RIFNOTE_SEARCH_DIR . 'templates/app-page.php';
        }

        if (self::should_use_public_shell()) {
            return RIFNOTE_SEARCH_DIR . 'templates/public-page.php';
        }

        return $template;
    }

    public static function disable_theme_shell() {
        if (!self::mode_for_request() && !self::should_use_public_shell()) {
            return;
        }

        if (function_exists('irunmole_print_public_context_script')) {
            remove_action('wp_footer', 'irunmole_print_public_context_script', 1);
        }

        remove_action('wp_head', 'rel_canonical');
    }

    public static function dequeue_theme_assets() {
        if (!self::mode_for_request() && !self::should_use_public_shell()) {
            return;
        }

        foreach (array('irunmole-app') as $handle) {
            wp_dequeue_style($handle);
            wp_deregister_style($handle);
            wp_dequeue_script($handle);
            wp_deregister_script($handle);
        }
    }

    public static function mode_for_request() {
        if (!is_singular('page')) {
            if (get_query_var('rifnote_story_cluster')) {
                return 'story-cluster';
            }

            if (get_query_var('rifnote_source_domain')) {
                return 'source-profile';
            }

            $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            $path = trim((string) wp_parse_url($request_uri, PHP_URL_PATH), '/');
            $home_path = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');

            if ($home_path && 0 === strpos($path, $home_path . '/')) {
                $path = trim(substr($path, strlen($home_path)), '/');
            }

            $slug = strtok($path, '/');
            $configs = self::page_configs();

            if ($slug && isset($configs[$slug])) {
                return $configs[$slug]['mode'];
            }

            return '';
        }

        $post = get_queried_object();

        if (!$post || empty($post->post_name)) {
            return '';
        }

        $configs = self::page_configs();

        if (isset($configs[$post->post_name])) {
            return $configs[$post->post_name]['mode'];
        }

        foreach ($configs as $config) {
            if (has_shortcode($post->post_content, $config['shortcode'])) {
                return $config['mode'];
            }
        }

        return '';
    }

    public static function should_use_public_shell() {
        if (is_admin() || wp_doing_ajax() || is_feed() || is_robots() || is_trackback()) {
            return false;
        }

        if (self::mode_for_request()) {
            return false;
        }

        if (is_embed() || is_attachment()) {
            return false;
        }

        return is_singular(array('post', 'page')) || is_home() || is_archive() || is_search() || is_404();
    }

    public static function current_mode() {
        return self::$current_mode ? self::$current_mode : self::mode_for_request();
    }

    public static function nav_items() {
        return array(
            array('label' => __('Search', 'rifnote-search'), 'url' => home_url('/search/')),
            array('label' => __('Football', 'rifnote-search'), 'url' => home_url('/football/')),
            array('label' => __('Publishers', 'rifnote-search'), 'url' => home_url('/publisher-signup/')),
            array('label' => __('Submit News', 'rifnote-search'), 'url' => home_url('/submit-news/')),
            array('label' => __('Advertise', 'rifnote-search'), 'url' => home_url('/advertise/')),
        );
    }

    public static function nav_groups() {
        return array(
            array(
                'label' => __('Explore', 'rifnote-search'),
                'items' => array(
                    array('label' => __('Search', 'rifnote-search'), 'url' => home_url('/search/')),
                    array('label' => __('Weather', 'rifnote-search'), 'url' => home_url('/weather/')),
                    array('label' => __('Daily Drop', 'rifnote-search'), 'url' => home_url('/daily-briefing/')),
                    array('label' => __('My Feed', 'rifnote-search'), 'url' => home_url('/for-you/')),
                    array('label' => __('Contact Us', 'rifnote-search'), 'url' => home_url('/contact-us/')),
                ),
            ),
            array(
                'label' => __('Football', 'rifnote-search'),
                'items' => array(
                    array('label' => __('Live Scores', 'rifnote-search'), 'url' => home_url('/football/')),
                    array('label' => __('Teams', 'rifnote-search'), 'url' => home_url('/teams/')),
                    array('label' => __('Players', 'rifnote-search'), 'url' => home_url('/players/')),
                    array('label' => __('Transfers', 'rifnote-search'), 'url' => home_url('/transfers/')),
                ),
            ),
            array(
                'label' => __('Publishers', 'rifnote-search'),
                'items' => array(
                    array('label' => __('Publisher Sign Up', 'rifnote-search'), 'url' => home_url('/publisher-signup/')),
                    array('label' => __('Send a Story', 'rifnote-search'), 'url' => home_url('/submit-news/')),
                    array('label' => __('Publisher Hub', 'rifnote-search'), 'url' => home_url('/publisher-dashboard/')),
                    array('label' => __('Publisher Guide', 'rifnote-search'), 'url' => home_url('/publisher-docs/')),
                ),
            ),
            array(
                'label' => __('Advertisers', 'rifnote-search'),
                'items' => array(
                    array('label' => __('Advertiser Sign Up', 'rifnote-search'), 'url' => home_url('/advertiser-signup/')),
                    array('label' => __('Build Campaign', 'rifnote-search'), 'url' => home_url('/advertise/')),
                    array('label' => __('Advertiser Dashboard', 'rifnote-search'), 'url' => home_url('/advertiser-dashboard/')),
                ),
            ),
            array(
                'label' => __('Support', 'rifnote-search'),
                'items' => array(
                    array('label' => __('Beta Feedback', 'rifnote-search'), 'url' => home_url('/beta-feedback/')),
                    array('label' => __('Newsletter', 'rifnote-search'), 'url' => home_url('/newsletter/')),
                    array('label' => __('DMCA', 'rifnote-search'), 'url' => home_url('/dmca/')),
                ),
            ),
        );
    }
}
