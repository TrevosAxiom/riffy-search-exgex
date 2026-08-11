<?php
/**
 * Plugin Name: Rifnote Search
 * Plugin URI: https://rifnote.com/
 * Description: AI-powered news search and publisher discovery plugin for Rifnote.
 * Version: 0.2.7
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Rifnote
 * Text Domain: rifnote-search
 * Update URI: https://github.com/TrevosAxiom/riffy-search-exgex
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RIFNOTE_SEARCH_VERSION', '0.2.7');
define('RIFNOTE_SEARCH_FILE', __FILE__);
define('RIFNOTE_SEARCH_DIR', plugin_dir_path(__FILE__));
define('RIFNOTE_SEARCH_URL', plugin_dir_url(__FILE__));

require_once RIFNOTE_SEARCH_DIR . 'includes/class-source-meta.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-clustering.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-aggregation.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-trending.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-publishers.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-ingestion.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-analytics.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-legal.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-hardening.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-beta.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-search-index.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-story-platform.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-source-trust.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-platform-insights.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-operations.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-editorial.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-retention.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-delivery.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-launch-readiness.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-release.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-football-api.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-live-data.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-news-api.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-social.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-customgpt-import.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-data-api.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-github-updater.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-rss-warehouse.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-search.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-ai.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-rest-api.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-admin.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-pages.php';
require_once RIFNOTE_SEARCH_DIR . 'includes/class-pwa.php';

final class Rifnote_Search_Plugin {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_action('plugins_loaded', array('Rifnote_Search_Trending', 'maybe_install'));
        add_action('plugins_loaded', array('Rifnote_Search_Publishers', 'maybe_install'));
        add_action('plugins_loaded', array('Rifnote_Search_Analytics', 'maybe_install'));
        add_action('plugins_loaded', array('Rifnote_Search_Legal', 'maybe_install'));
        add_action('plugins_loaded', array('Rifnote_Search_Hardening', 'maybe_install'));
        add_action('plugins_loaded', array('Rifnote_Search_Beta', 'maybe_install'));
        add_action('plugins_loaded', array('Rifnote_Search_Index', 'maybe_install'));
        add_action('plugins_loaded', array('Rifnote_Search_Platform_Insights', 'maybe_install'));
        add_action('plugins_loaded', array('Rifnote_Search_Editorial', 'maybe_install'));
        add_action('plugins_loaded', array('Rifnote_Search_Retention', 'maybe_install'));
        add_action('plugins_loaded', array('Rifnote_Search_Launch_Readiness', 'maybe_install'));
        add_action('plugins_loaded', array('Rifnote_Search_GitHub_Updater', 'init'));
        add_action('plugins_loaded', array('Rifnote_Search_Football_API', 'maybe_install'));
        add_action('plugins_loaded', array('Rifnote_Search_Live_Data', 'maybe_install'));
        add_action('plugins_loaded', array('Rifnote_Search_Aggregation', 'ensure_categories'));
        add_action('init', array('Rifnote_Search_Aggregation', 'maybe_repair_categories'), 30);
        add_filter('cron_schedules', array('Rifnote_Search_Trending', 'cron_schedules'));
        add_filter('cron_schedules', array('Rifnote_Search_Ingestion', 'cron_schedules'));
        add_filter('cron_schedules', array('Rifnote_Search_News_API', 'cron_schedules'));
        add_filter('cron_schedules', array('Rifnote_Search_Admin', 'cron_schedules'));
        add_filter('cron_schedules', array('Rifnote_Search_Football_API', 'cron_schedules'));
        add_action('init', array('Rifnote_Search_Trending', 'schedule'));
        add_action(Rifnote_Search_Trending::CRON_HOOK, array('Rifnote_Search_Trending', 'run_cron'));
        add_action('init', array('Rifnote_Search_Ingestion', 'schedule'));
        add_action(Rifnote_Search_Ingestion::CRON_HOOK, array('Rifnote_Search_Ingestion', 'run_cron'));
        add_action('pre_get_posts', array('Rifnote_Search_Ingestion', 'hide_legacy_rss_posts_from_admin'));
        add_filter('posts_where', array('Rifnote_Search_Ingestion', 'exclude_legacy_rss_posts_where'), 10, 2);
        add_action('init', array('Rifnote_Search_News_API', 'schedule'));
        add_action(Rifnote_Search_News_API::CRON_HOOK, array('Rifnote_Search_News_API', 'run_cron'));
        add_action('init', array('Rifnote_Search_Football_API', 'schedule'));
        add_action(Rifnote_Search_Football_API::CRON_HOOK, array('Rifnote_Search_Football_API', 'run_cron'));
        add_action('init', array('Rifnote_Search_Social', 'schedule'));
        add_action(Rifnote_Search_Social::CRON_HOOK, array('Rifnote_Search_Social', 'run_cron'));
        add_action('init', array('Rifnote_Search_Retention', 'schedule'));
        add_action(Rifnote_Search_Retention::CRON_HOOK, array('Rifnote_Search_Retention', 'process_alerts'));
        add_action(Rifnote_Search_Retention::NEWSLETTER_CRON_HOOK, array('Rifnote_Search_Retention', 'send_newsletter_digest'));
        add_action('init', array('Rifnote_Search_Admin', 'schedule_ads_report'));
        add_action(Rifnote_Search_Admin::ADS_REPORT_CRON_HOOK, array('Rifnote_Search_Admin', 'send_scheduled_ads_report'));
        add_action('rifnote_search_process_push', array('Rifnote_Search_Delivery', 'process_push_notifications'));
        add_action('init', array('Rifnote_Search_Source_Meta', 'register'));
        add_action('init', array('Rifnote_Search_Admin', 'ensure_notes_category'));
        add_action('add_meta_boxes', array('Rifnote_Search_Source_Meta', 'add_meta_box'));
        add_action('add_meta_boxes', array('Rifnote_Search_Admin', 'add_home_pill_meta_box'));
        add_action('save_post_post', array('Rifnote_Search_Source_Meta', 'maybe_stamp_admin_origin'), 5, 3);
        add_action('save_post_post', array('Rifnote_Search_Source_Meta', 'save'));
        add_action('save_post_post', array('Rifnote_Search_Admin', 'save_home_pill_assignment'), 15, 3);
        add_action('save_post_post', array('Rifnote_Search_Clustering', 'assign_post_cluster'), 20, 3);
        add_action('save_post_post', array('Rifnote_Search_Index', 'index_post'), 30);
        add_action('deleted_post', array('Rifnote_Search_Index', 'delete_post'));
        add_filter('manage_post_posts_columns', array('Rifnote_Search_Source_Meta', 'post_columns'));
        add_filter('manage_post_posts_columns', array('Rifnote_Search_Admin', 'home_pill_post_columns'), 20);
        add_action('manage_post_posts_custom_column', array('Rifnote_Search_Source_Meta', 'render_post_column'), 10, 2);
        add_action('manage_post_posts_custom_column', array('Rifnote_Search_Admin', 'render_home_pill_post_column'), 20, 2);
        add_action('admin_post_rifnote_update_home_pill', array('Rifnote_Search_Admin', 'handle_home_pill_post_list_update'));
        add_filter('bulk_actions-edit-post', array('Rifnote_Search_Admin', 'bulk_home_pill_actions'));
        add_filter('handle_bulk_actions-edit-post', array('Rifnote_Search_Admin', 'handle_bulk_home_pill_action'), 10, 3);
        add_action('admin_notices', array('Rifnote_Search_Admin', 'bulk_home_pill_notice'));
        add_filter('pre_delete_term', array('Rifnote_Search_Admin', 'protect_notes_category'), 10, 3);
        add_action('category_add_form_fields', array('Rifnote_Search_Admin', 'category_default_image_add_field'));
        add_action('category_edit_form_fields', array('Rifnote_Search_Admin', 'category_default_image_edit_field'));
        add_action('created_category', array('Rifnote_Search_Admin', 'save_category_default_image'));
        add_action('edited_category', array('Rifnote_Search_Admin', 'save_category_default_image'));
        add_action('rest_api_init', array('Rifnote_Search_REST_API', 'register_routes'));
        add_action('admin_menu', array('Rifnote_Search_Admin', 'register_menu'));
        add_action('admin_menu', array('Rifnote_Search_RSS_Warehouse', 'register_menu'));
        add_action('admin_init', array('Rifnote_Search_Admin', 'maybe_install_performance_indexes'), 5);
        add_action('admin_init', array('Rifnote_Search_Admin', 'register_settings'));
        add_action('admin_init', array('Rifnote_Search_Admin', 'preserve_missing_settings_on_options_save'), 20);
        add_action('updated_option', array('Rifnote_Search_Trending', 'maybe_clear_snapshot_on_update'), 10, 3);
        add_action('updated_option', array('Rifnote_Search_Ingestion', 'maybe_reschedule_on_settings_update'), 10, 3);
        add_action('updated_option', array('Rifnote_Search_Admin', 'maybe_clear_football_cache_on_update'), 10, 3);
        add_action('admin_init', array('Rifnote_Search_Pages', 'ensure_pages'));
        add_action('init', array('Rifnote_Search_Pages', 'register_rewrites'));
        add_action('init', array('Rifnote_Search_Pages', 'maybe_flush_rewrites'), 20);
        add_filter('query_vars', array('Rifnote_Search_Pages', 'query_vars'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('template_redirect', array('Rifnote_Search_PWA', 'maybe_serve_asset'), 0);
        add_action('template_redirect', array('Rifnote_Search_Launch_Readiness', 'maybe_serve_sitemap'), 0);
        add_action('wp_head', array('Rifnote_Search_PWA', 'print_head_tags'), 2);
        add_action('wp_head', array($this, 'print_social_card_tags'), 3);
        add_action('wp_head', array('Rifnote_Search_Launch_Readiness', 'print_head_tags'), 4);
        add_action('wp_footer', array('Rifnote_Search_PWA', 'print_footer_script'), 30);
        add_action('wp_footer', array('Rifnote_Search_Social', 'print_embed_scripts'), 40);
        add_action('send_headers', array('Rifnote_Search_Hardening', 'add_security_headers'));
        add_filter('rest_post_dispatch', array('Rifnote_Search_Hardening', 'set_rest_cache_headers'), 10, 3);
        add_action('wp', array('Rifnote_Search_Pages', 'disable_theme_shell'), 5);
        add_action('wp_enqueue_scripts', array('Rifnote_Search_Pages', 'dequeue_theme_assets'), 100);
        add_filter('template_include', array('Rifnote_Search_Pages', 'template_include'), 99);
        add_filter('the_content', array('Rifnote_Search_Social', 'filter_content_embeds'), 18);
        add_shortcode('rifnote_search_app', array($this, 'render_app_shortcode'));
        add_shortcode('rifnote_search_bar', array($this, 'render_search_bar_shortcode'));
        add_shortcode('rifnote_trending_news', array($this, 'render_trending_shortcode'));
        add_shortcode('rifnote_ai_answer', array($this, 'render_ai_answer_shortcode'));
        add_shortcode('rifnote_publisher_signup', array($this, 'render_publisher_signup_shortcode'));
        add_shortcode('rifnote_publisher_submit', array($this, 'render_submit_shortcode'));
        add_shortcode('rifnote_publisher_docs', array($this, 'render_publisher_docs_shortcode'));
        add_shortcode('rifnote_publisher_dashboard', array($this, 'render_publisher_dashboard_shortcode'));
        add_shortcode('rifnote_live_scores', array($this, 'render_live_scores_shortcode'));
        add_shortcode('rifnote_football_hub', array($this, 'render_football_hub_shortcode'));
        add_shortcode('rifnote_team_search', array($this, 'render_team_search_shortcode'));
        add_shortcode('rifnote_player_search', array($this, 'render_player_search_shortcode'));
        add_shortcode('rifnote_transfer_tracker', array($this, 'render_transfer_tracker_shortcode'));
        add_shortcode('rifnote_weather', array($this, 'render_weather_shortcode'));
        add_shortcode('rifnote_contact', array($this, 'render_contact_shortcode'));
        add_shortcode('rifnote_legal_request', array($this, 'render_legal_request_shortcode'));
        add_shortcode('rifnote_dmca_request', array($this, 'render_dmca_request_shortcode'));
        add_shortcode('rifnote_publisher_opt_out', array($this, 'render_opt_out_shortcode'));
        add_shortcode('rifnote_beta_feedback', array($this, 'render_beta_feedback_shortcode'));
        add_shortcode('rifnote_for_you', array($this, 'render_for_you_shortcode'));
        add_shortcode('rifnote_newsletter_signup', array($this, 'render_newsletter_shortcode'));
        add_shortcode('rifnote_widget_trending', array($this, 'render_widget_trending_shortcode'));
        add_shortcode('rifnote_advertiser_signup', array($this, 'render_advertiser_signup_shortcode'));
        add_shortcode('rifnote_sponsor_request', array($this, 'render_sponsor_request_shortcode'));
        add_shortcode('rifnote_advertiser_dashboard', array($this, 'render_advertiser_dashboard_shortcode'));
        add_shortcode('rifnote_share_links', array($this, 'render_share_links_shortcode'));
        add_filter('script_loader_tag', array($this, 'script_module'), 10, 3);
        add_action('login_enqueue_scripts', array($this, 'enqueue_login_assets'));
        add_filter('login_headerurl', array($this, 'login_header_url'));
        add_filter('login_headertext', array($this, 'login_header_text'));
        add_filter('login_body_class', array($this, 'login_body_classes'));
    }

    public function load_textdomain() {
        load_plugin_textdomain('rifnote-search', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public static function asset($pattern) {
        $manifest_path = RIFNOTE_SEARCH_DIR . 'dist/.vite/manifest.json';

        if (file_exists($manifest_path)) {
            $manifest = json_decode((string) file_get_contents($manifest_path), true);
            $entry = is_array($manifest) && isset($manifest['src/main.jsx']) ? $manifest['src/main.jsx'] : null;

            if ($entry && '*.js' === $pattern && !empty($entry['file'])) {
                $path = RIFNOTE_SEARCH_DIR . 'dist/' . $entry['file'];

                return file_exists($path) ? array(
                    'url' => RIFNOTE_SEARCH_URL . 'dist/' . $entry['file'],
                    'version' => (string) filemtime($path),
                ) : null;
            }

            if ($entry && '*.css' === $pattern && !empty($entry['css'][0])) {
                $path = RIFNOTE_SEARCH_DIR . 'dist/' . $entry['css'][0];

                return file_exists($path) ? array(
                    'url' => RIFNOTE_SEARCH_URL . 'dist/' . $entry['css'][0],
                    'version' => (string) filemtime($path),
                ) : null;
            }
        }

        $matches = glob(RIFNOTE_SEARCH_DIR . 'dist/assets/' . $pattern);

        if (!$matches) {
            return null;
        }

        $path = $matches[0];

        return array(
            'url' => RIFNOTE_SEARCH_URL . 'dist/assets/' . basename($path),
            'version' => (string) filemtime($path),
        );
    }

    public function enqueue_app_assets() {
        $css = self::asset('*.css');
        $js = self::asset('*.js');
        $this->enqueue_google_fonts();

        if ($css) {
            wp_enqueue_style('rifnote-search-app', $css['url'], array('rifnote-search-google-fonts'), $css['version']);
            wp_add_inline_style('rifnote-search-app', $this->typography_inline_css());
        }

        if ($js) {
            wp_enqueue_script('rifnote-search-app', $js['url'], array(), $js['version'], true);
        }

        wp_add_inline_script('rifnote-search-app', 'window.RIFNOTE_SEARCH = ' . wp_json_encode($this->runtime_context()) . ';', 'before');
    }

    public function script_module($tag, $handle, $src) {
        if ('rifnote-search-app' !== $handle) {
            return $tag;
        }

        return '<script type="module" src="' . esc_url($src) . '"></script>' . "\n";
    }

    public function enqueue_admin_assets($hook) {
        $is_rifnote_admin = false !== strpos((string) $hook, 'rifnote-search');
        $is_post_editor = in_array((string) $hook, array('post.php', 'post-new.php'), true);
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $is_category_screen = $screen && 'category' === $screen->taxonomy && in_array((string) $hook, array('edit-tags.php', 'term.php'), true);

        if ($is_rifnote_admin || $is_post_editor || $is_category_screen) {
            $this->enqueue_google_fonts();
            wp_enqueue_media();
            wp_register_style('rifnote-search-admin-inline', false, array('rifnote-search-google-fonts'), RIFNOTE_SEARCH_VERSION);
            wp_enqueue_style('rifnote-search-admin-inline');
            if ($is_rifnote_admin) {
                wp_add_inline_style('rifnote-search-admin-inline', $this->admin_inline_css());
                wp_add_inline_style('rifnote-search-admin-inline', $this->admin_creative_css());
            }
            wp_add_inline_style('rifnote-search-admin-inline', $this->admin_media_css());
            wp_register_script('rifnote-search-admin-media', false, array('jquery'), RIFNOTE_SEARCH_VERSION, true);
            wp_enqueue_script('rifnote-search-admin-media');
            wp_add_inline_script('rifnote-search-admin-media', $this->admin_media_js());
        }

        if ('toplevel_page_rifnote-search' !== $hook) {
            return;
        }

        $this->enqueue_app_assets();
    }

    public function enqueue_login_assets() {
        $css = self::asset('*.css');
        $this->enqueue_google_fonts();

        if ($css) {
            wp_enqueue_style('rifnote-search-app', $css['url'], array('rifnote-search-google-fonts'), $css['version']);
        }

        wp_register_style('rifnote-search-login', false, array('rifnote-search-app'), RIFNOTE_SEARCH_VERSION);
        wp_enqueue_style('rifnote-search-login');
        wp_add_inline_style('rifnote-search-login', $this->login_inline_css());
    }

    public function enqueue_google_fonts() {
        $fonts = array_unique(array_filter(array(
            get_option('rifnote_typography_heading_font', 'Google Sans'),
            get_option('rifnote_typography_body_font', 'Roboto'),
            'Roboto',
        )));
        $families = array();

        foreach ($fonts as $font) {
            $font = sanitize_text_field((string) $font);

            if ('Google Sans' === $font || 'Product Sans' === $font) {
                continue;
            }

            $families[] = 'family=' . str_replace('%20', '+', rawurlencode($font)) . ':ital,wght@0,300..900;1,300..900';
        }

        if (!$families) {
            $families[] = 'family=Roboto:ital,wght@0,300..900;1,300..900';
        }

        wp_enqueue_style(
            'rifnote-search-google-fonts',
            'https://fonts.googleapis.com/css2?' . implode('&', $families) . '&display=swap',
            array(),
            null
        );
    }

    private function typography_inline_css() {
        $heading_font = sanitize_text_field((string) get_option('rifnote_typography_heading_font', 'Google Sans'));
        $body_font = sanitize_text_field((string) get_option('rifnote_typography_body_font', 'Roboto'));
        $title_size = Rifnote_Search_Admin::sanitize_css_size(get_option('rifnote_typography_story_title_size', 'clamp(1.95rem, 3.45vw, 3.25rem)'));
        $body_size = Rifnote_Search_Admin::sanitize_css_size(get_option('rifnote_typography_body_size', 'clamp(1.02rem, 1vw, 1.1rem)'));
        $title_weight = Rifnote_Search_Admin::sanitize_font_weight(get_option('rifnote_typography_story_title_weight', 840));
        $body_weight = Rifnote_Search_Admin::sanitize_font_weight(get_option('rifnote_typography_body_weight', 430));
        $heading_stack = '"' . esc_attr($heading_font) . '", "Product Sans", Roboto, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        $body_stack = '"' . esc_attr($body_font) . '", Roboto, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';

        return ':root,.rifnote-search-root{--rs-font-heading:' . $heading_stack . ';--rs-font-reading:' . $body_stack . ';--rs-font-ui:' . $body_stack . ';--rs-story-title-size:' . esc_html($title_size) . ';--rs-story-title-weight:' . absint($title_weight) . ';--rs-body-size:' . esc_html($body_size) . ';--rs-body-weight:' . absint($body_weight) . '}';
    }

    public function print_social_card_tags() {
        if (!is_singular('post')) {
            return;
        }

        $post_id = get_queried_object_id();
        $title = wp_strip_all_tags(get_the_title($post_id));
        $description = wp_strip_all_tags(get_the_excerpt($post_id));
        $url = wp_get_shortlink($post_id, 'post');

        if (!$url) {
            $url = home_url('/?p=' . $post_id);
        }

        $image = get_the_post_thumbnail_url($post_id, 'large');

        if (!$image && class_exists('Rifnote_Search_Admin')) {
            $image = Rifnote_Search_Admin::story_default_image_url($post_id);
        }

        if (!$image) {
            $image = Rifnote_Search_PWA::app_icon_url(512);
        }

        echo '<meta property="og:type" content="article">' . "\n";
        echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr(wp_trim_words($description, 28)) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
        echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($title) . '">' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr(wp_trim_words($description, 28)) . '">' . "\n";
        echo '<meta name="twitter:image" content="' . esc_url($image) . '">' . "\n";
    }

    public function render_share_links_shortcode($atts = array()) {
        $atts = shortcode_atts(array(
            'id' => 0,
            'label' => __('Share:', 'rifnote-search'),
        ), $atts, 'rifnote_share_links');
        $post_id = absint($atts['id']);

        if (!$post_id) {
            $post_id = get_the_ID();
        }

        if (!$post_id) {
            return '';
        }

        $url = wp_get_shortlink($post_id, 'post');

        if (!$url) {
            $url = home_url('/?p=' . $post_id);
        }

        $title = wp_strip_all_tags(get_the_title($post_id));
        $encoded_url = rawurlencode($url);
        $encoded_title = rawurlencode($title);
        $links = array(
            'x' => array(
                'label' => __('Share on X', 'rifnote-search'),
                'url' => 'https://twitter.com/intent/tweet?url=' . $encoded_url . '&text=' . $encoded_title,
                'svg' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M18.9 2h3.4l-7.5 8.6L23.6 22h-6.9l-5.4-7.1L5.1 22H1.7l8-9.2L1.2 2h7.1l4.9 6.5L18.9 2Zm-1.2 18h1.9L7.2 3.9h-2L17.7 20Z"/></svg>',
            ),
            'facebook' => array(
                'label' => __('Share on Facebook', 'rifnote-search'),
                'url' => 'https://www.facebook.com/sharer/sharer.php?u=' . $encoded_url,
                'svg' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M14 8.3V6.7c0-.8.6-1 1-1h2.8V1.2L14 1.1c-4.2 0-5.2 3.1-5.2 5.1v2.1H5.5V13h3.3v9h5.1v-9h3.8l.6-4.7H14Z"/></svg>',
            ),
            'whatsapp' => array(
                'label' => __('Share on WhatsApp', 'rifnote-search'),
                'url' => 'https://wa.me/?text=' . $encoded_title . '%20' . $encoded_url,
                'svg' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12.1 2a9.8 9.8 0 0 0-8.4 14.8L2.5 22l5.3-1.4A9.9 9.9 0 1 0 12.1 2Zm0 18.2c-1.5 0-2.9-.4-4.1-1.1l-.3-.2-3.1.8.8-3-.2-.3a8.1 8.1 0 1 1 6.9 3.8Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1-.2.3-.7.8-.8 1-.2.2-.3.2-.6.1a6.6 6.6 0 0 1-3.3-2.9c-.2-.3 0-.4.1-.6l.4-.5c.1-.2.2-.3.3-.5.1-.2 0-.4 0-.5-.1-.1-.6-1.4-.8-2-.2-.5-.4-.5-.6-.5h-.5c-.2 0-.5.1-.7.3-.2.3-1 1-1 2.4s1 2.7 1.2 2.9c.1.2 2 3.1 5 4.3.7.3 1.2.5 1.7.6.7.2 1.3.2 1.8.1.5-.1 1.5-.6 1.8-1.2.2-.6.2-1.1.1-1.2-.1-.1-.3-.2-.6-.3Z"/></svg>',
            ),
            'email' => array(
                'label' => __('Share by email', 'rifnote-search'),
                'url' => 'mailto:?subject=' . $encoded_title . '&body=' . $encoded_url,
                'svg' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 5h18a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm9 8 7.2-5.8H4.8L12 13Zm0 2.5L4 9.1V17h16V9.1l-8 6.4Z"/></svg>',
            ),
        );

        ob_start();
        ?>
        <div class="rs-public-story-share">
            <?php if ((string) $atts['label'] !== '') : ?>
                <span><?php echo esc_html($atts['label']); ?></span>
            <?php endif; ?>
            <?php foreach ($links as $class => $link) : ?>
                <a class="is-<?php echo esc_attr($class); ?>" href="<?php echo esc_url($link['url']); ?>" target="_blank" rel="noreferrer" aria-label="<?php echo esc_attr($link['label']); ?>"><?php echo $link['svg']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
            <?php endforeach; ?>
            <button class="is-copy" type="button" data-rs-copy-link="<?php echo esc_url($url); ?>" aria-label="<?php esc_attr_e('Copy story link', 'rifnote-search'); ?>"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8 12a4 4 0 0 1 4-4h3v2h-3a2 2 0 0 0 0 4h3v2h-3a4 4 0 0 1-4-4Zm5-1h-2v2h2v-2Zm-4-1H6a2 2 0 0 0 0 4h3v2H6A4 4 0 0 1 6 8h3v2Zm6-2h3a4 4 0 0 1 0 8h-3v-2h3a2 2 0 0 0 0-4h-3V8Z"/></svg></button>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    public function login_header_url() {
        return home_url('/search/');
    }

    public function login_header_text() {
        return __('Rifnote Search', 'rifnote-search');
    }

    public function login_body_classes($classes) {
        $classes[] = 'rifnote-login-page';

        return $classes;
    }

    private function login_inline_css() {
        $logo_url = esc_url_raw(get_option('rifnote_site_logo_url', ''));
        $icon_url = esc_url_raw(get_site_icon_url(160));

        if (!$icon_url) {
            $icon_url = esc_url_raw(RIFNOTE_SEARCH_URL . 'public/rifnote-favicon.svg');
        }

        $brand_image = $logo_url ? $logo_url : $icon_url;

        return '
body.login.rifnote-login-page{min-height:100vh;background:radial-gradient(circle at 12% 0%,rgba(215,25,32,.12),transparent 34%),radial-gradient(circle at 88% 18%,rgba(22,163,74,.12),transparent 34%),#f6f8fb;color:#101828;font-family:Roboto,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
body.login.rifnote-login-page:before{content:"";position:fixed;inset:0;background-image:linear-gradient(rgba(16,24,40,.035) 1px,transparent 1px),linear-gradient(90deg,rgba(16,24,40,.035) 1px,transparent 1px);background-size:34px 34px;pointer-events:none}
body.login.rifnote-login-page #login{position:relative;width:min(440px,calc(100vw - 32px));padding:8vh 0 32px}
body.login.rifnote-login-page h1 a{width:100%;height:82px;margin:0 auto 22px;background-image:url("' . esc_url($brand_image) . '");background-size:contain;background-position:center;background-repeat:no-repeat;text-indent:-9999px;filter:drop-shadow(0 16px 32px rgba(16,24,40,.12))}
body.login.rifnote-login-page h1:after{content:"Your Rifnote account";display:block;margin:-8px 0 20px;color:#667085;font-family:"Google Sans","Product Sans",Roboto,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:15px;font-weight:750;text-align:center}
body.login.rifnote-login-page form{border:1px solid #e1e7ef;border-radius:28px;background:rgba(255,255,255,.94);box-shadow:0 24px 70px rgba(16,24,40,.12);padding:28px}
body.login.rifnote-login-page label{color:#344054;font-size:13px;font-weight:800}
body.login.rifnote-login-page input.input{min-height:52px;border:1px solid #dbe3ee;border-radius:18px;background:#f8fafc;color:#101828;font-size:18px;font-weight:650;padding:10px 14px;box-shadow:none}
body.login.rifnote-login-page input.input:focus{border-color:#d71920;box-shadow:0 0 0 4px rgba(215,25,32,.12);outline:0}
body.login.rifnote-login-page .button-primary{min-height:48px;border:0;border-radius:999px;background:#d71920;color:#fff;font-weight:900;padding:0 22px;text-shadow:none;box-shadow:0 14px 30px rgba(215,25,32,.25)}
body.login.rifnote-login-page .button-primary:hover,body.login.rifnote-login-page .button-primary:focus{background:#b51218}
body.login.rifnote-login-page #nav,body.login.rifnote-login-page #backtoblog{padding:0;text-align:center}
body.login.rifnote-login-page #nav a,body.login.rifnote-login-page #backtoblog a{color:#667085;font-weight:800;text-decoration:none}
body.login.rifnote-login-page #nav a:hover,body.login.rifnote-login-page #backtoblog a:hover{color:#d71920}
body.login.rifnote-login-page .message,body.login.rifnote-login-page .notice,body.login.rifnote-login-page #login_error{border-left:0;border-radius:18px;border:1px solid #e1e7ef;background:#fff;color:#101828;box-shadow:0 10px 24px rgba(16,24,40,.06);font-weight:650}
body.login.rifnote-login-page .privacy-policy-page-link{margin:18px 0 0}
@media(max-width:560px){body.login.rifnote-login-page #login{padding-top:32px}body.login.rifnote-login-page form{padding:22px;border-radius:24px}body.login.rifnote-login-page h1 a{height:64px}}
';
    }

    private function admin_inline_css() {
        return '.rs-admin-kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:18px 0}.rs-admin-kpi,.rs-admin-card{background:#fff;border:1px solid #dfe4ec;border-radius:18px;box-shadow:0 14px 34px rgba(16,24,40,.06)}.rs-admin-kpi{display:grid;gap:8px;padding:20px}.rs-admin-kpi span{color:#667085;font-weight:800;text-transform:uppercase;font-size:11px;letter-spacing:.08em}.rs-admin-kpi strong{color:#101828;font-size:36px;line-height:1;font-weight:900;letter-spacing:-.04em}.rs-admin-kpi small,.rs-admin-card p{color:#667085;font-weight:600}.rs-admin-analytics-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;margin-top:18px}.rs-admin-analytics-grid-main{grid-template-columns:minmax(0,1.6fr) minmax(300px,.8fr)}.rs-admin-card{padding:22px}.rs-admin-card-wide{min-height:310px}.rs-admin-card h2{margin:0 0 8px;color:#101828;font-size:20px}.rs-admin-hero{display:grid;grid-template-columns:minmax(0,1fr) 220px;gap:18px;align-items:end;padding:28px;margin:8px 0 18px;border-radius:26px;background:radial-gradient(circle at 85% 20%,rgba(22,163,74,.16),transparent 34%),linear-gradient(135deg,#fff1f1,#fff,#effaf4);border:1px solid #dfe4ec;box-shadow:0 18px 44px rgba(16,24,40,.07)}.rs-admin-hero span{text-transform:uppercase;letter-spacing:.08em;font-weight:900;color:#b51218;font-size:12px}.rs-admin-hero h2{margin:8px 0;font-size:36px;line-height:1;letter-spacing:-.04em;color:#101828}.rs-admin-hero p{max-width:780px;font-size:15px}.rs-admin-hero-meter{display:grid;gap:8px;place-items:center;text-align:center;background:#101828;color:#fff;border-radius:22px;padding:22px}.rs-admin-hero-meter b{font-size:42px;line-height:1}.rs-admin-hero-meter small{color:#d0d5dd;font-weight:700}.rs-ad-heatmap,.rs-ad-funnel,.rs-admin-recos,.rs-ad-risk-list{display:grid;gap:12px;margin-top:14px}.rs-ad-heat{display:grid;grid-template-columns:minmax(0,1fr) 42px;gap:12px;align-items:center;padding:14px;border:1px solid #e6ebf2;border-radius:16px;background:#f8fafc}.rs-ad-heat.live{background:linear-gradient(135deg,#fff5f5,#f4fff8);border-color:#ffd0d3}.rs-ad-heat strong{display:block;color:#101828}.rs-ad-heat span{color:#667085;font-weight:700}.rs-ad-heat b{display:grid;place-items:center;width:38px;height:38px;border-radius:999px;background:#101828;color:#fff}.rs-ad-heat i,.rs-ad-funnel i{grid-column:1/-1;display:block;height:8px;background:#edf1f6;border-radius:999px;overflow:hidden}.rs-ad-heat em,.rs-ad-funnel em{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,#d71920,#f59e0b,#16a34a)}.rs-ad-funnel div{display:grid;grid-template-columns:1fr 48px;gap:8px;align-items:center}.rs-ad-funnel span{font-weight:800;color:#344054}.rs-ad-funnel strong{text-align:right;color:#101828}.rs-admin-recos article,.rs-ad-risk-list article{padding:14px;border-radius:16px;background:#f8fafc;border:1px solid #e6ebf2}.rs-ad-risk-list article.high{background:#fff1f2;border-color:#fecdd3}.rs-ad-risk-list article.medium{background:#fffbeb;border-color:#fde68a}.rs-admin-recos strong,.rs-ad-risk-list strong{display:block;color:#101828;font-size:15px;margin-bottom:5px}.rs-admin-recos span,.rs-ad-risk-list span{display:block;color:#667085;font-weight:650;line-height:1.45}.rs-ad-bucket-list{display:grid;gap:10px;margin-top:12px}.rs-ad-bucket-list article{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:4px 12px;padding:12px;border-radius:14px;background:#f8fafc;border:1px solid #e6ebf2}.rs-ad-bucket-list span{font-weight:850;color:#101828}.rs-ad-bucket-list strong{font-size:18px;color:#101828}.rs-ad-bucket-list small{grid-column:1/-1;color:#667085;font-weight:700}.rs-ad-report-table td{vertical-align:middle}.rs-ad-ctr{display:inline-flex;align-items:center;justify-content:center;min-width:70px;padding:6px 10px;border-radius:999px;font-weight:900;background:#f1f4f8;color:#344054}.rs-ad-ctr.hot{background:#dcfce7;color:#166534}.rs-ad-ctr.warm{background:#fef3c7;color:#92400e}.rs-ad-ctr.cold{background:#fee2e2;color:#991b1b}.rs-ad-inventory-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:18px 0}.rs-ad-inventory-grid article{padding:18px;border-radius:18px;border:1px solid #e2e8f0;background:linear-gradient(145deg,#fff,#f8fafc);box-shadow:0 12px 30px rgba(16,24,40,.05)}.rs-ad-inventory-grid span{display:inline-flex;padding:4px 9px;border-radius:999px;background:#fff1f1;color:#b51218;font-weight:900;font-size:11px}.rs-ad-inventory-grid h3{margin:12px 0 8px;color:#101828;font-size:18px}.rs-ad-inventory-grid p{min-height:44px}.rs-ad-inventory-grid div{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:14px}.rs-ad-inventory-grid strong{font-size:19px;color:#101828}.rs-ad-inventory-grid small,.rs-ad-inventory-grid b{padding:5px 9px;border-radius:999px;background:#f1f4f8;color:#667085}.rs-admin-line-chart{width:100%;height:260px;margin-top:12px;overflow:visible}.rs-admin-bars{display:grid;gap:13px;margin-top:12px}.rs-admin-bar-row div{display:flex;justify-content:space-between;gap:12px;margin-bottom:6px;color:#344054;font-weight:700}.rs-admin-bar-row b{color:#101828}.rs-admin-bar-row i{display:block;height:10px;background:#f1f4f8;border-radius:999px;overflow:hidden}.rs-admin-bar-row em{display:block;height:100%;background:linear-gradient(90deg,#d71920,#16a34a);border-radius:inherit}.rs-admin-donut-wrap{display:grid;grid-template-columns:180px minmax(0,1fr);gap:18px;align-items:center}.rs-admin-donut{width:180px;height:180px;transform:rotate(-90deg)}.rs-admin-donut text{transform:rotate(90deg);transform-origin:center}.rs-admin-donut-legend{display:grid;gap:10px}.rs-admin-donut-legend span{display:flex;align-items:center;gap:8px;color:#344054;font-weight:700}.rs-admin-donut-legend i{width:10px;height:10px;border-radius:999px}.rs-admin-donut-legend b{margin-left:auto;color:#101828}.rs-admin-location-panel{display:grid;grid-template-columns:minmax(280px,.9fr) minmax(0,1.1fr);gap:20px}.rs-admin-map-visual{position:relative;min-height:340px;border-radius:24px;background:radial-gradient(circle at 35% 25%,rgba(215,25,32,.16),transparent 28%),radial-gradient(circle at 70% 64%,rgba(22,163,74,.18),transparent 28%),linear-gradient(135deg,#f8fafc,#eef4f0);overflow:hidden}.rs-admin-map-visual:before{content:"";position:absolute;inset:34px 58px;border:2px dashed rgba(16,24,40,.12);border-radius:46% 54% 56% 44%;transform:rotate(-12deg)}.rs-admin-map-bubble{position:absolute;display:grid;place-items:center;text-align:center;border-radius:999px;background:#fff;border:2px solid rgba(215,25,32,.4);box-shadow:0 16px 40px rgba(16,24,40,.12);padding:8px;color:#101828}.rs-admin-map-bubble b{font-size:11px;line-height:1}.rs-admin-map-bubble small{font-size:10px;color:#667085}.rs-admin-map-bubble.b1{left:18%;top:18%}.rs-admin-map-bubble.b2{right:18%;top:24%}.rs-admin-map-bubble.b3{left:34%;bottom:20%}.rs-admin-map-bubble.b4{right:28%;bottom:30%}.rs-admin-map-bubble.b5{left:12%;bottom:36%}.rs-admin-map-bubble.b6{right:12%;bottom:12%}.rs-admin-location-lists h3{margin:8px 0}@media(max-width:1200px){.rs-admin-kpi-grid,.rs-admin-analytics-grid,.rs-admin-analytics-grid-main,.rs-admin-location-panel,.rs-ad-inventory-grid{grid-template-columns:1fr 1fr}.rs-admin-hero{grid-template-columns:1fr}}@media(max-width:782px){.rs-admin-kpi-grid,.rs-admin-analytics-grid,.rs-admin-analytics-grid-main,.rs-admin-location-panel,.rs-admin-donut-wrap,.rs-ad-inventory-grid{grid-template-columns:1fr}.rs-admin-kpi strong{font-size:30px}.rs-admin-hero h2{font-size:30px}.rs-admin-hero{padding:20px}}';
    }

    private function admin_creative_css() {
        return '.rs-creative-campaign-list,.rs-creative-variant-grid,.rs-creative-asset-grid,.rs-creative-preview-grid{display:grid;gap:12px}.rs-creative-campaign-list a{display:grid;gap:4px;padding:13px;border:1px solid #e6ebf2;border-radius:14px;background:#f8fafc;text-decoration:none}.rs-creative-campaign-list a.active{border-color:#d71920;background:#fff5f5}.rs-creative-campaign-list strong{color:#101828}.rs-creative-campaign-list span{color:#667085;font-weight:700}.rs-creative-variant-grid{grid-template-columns:repeat(3,minmax(0,1fr));margin:12px 0}.rs-creative-variant-grid fieldset{display:grid;gap:10px;margin:0;padding:14px;border:1px solid #e6ebf2;border-radius:16px;background:#f8fafc}.rs-creative-variant-grid legend{font-weight:900;color:#101828}.rs-creative-asset-grid{grid-template-columns:repeat(2,minmax(0,1fr));margin:12px 0}.rs-creative-preview-grid{grid-template-columns:repeat(3,minmax(0,1fr));margin-top:14px}.rs-creative-preview-grid article{display:grid;gap:10px;padding:16px;border:1px solid #e6ebf2;border-radius:18px;background:linear-gradient(145deg,#fff,#f8fafc)}.rs-creative-preview-grid span{width:max-content;padding:5px 9px;border-radius:999px;background:#fff1f1;color:#b51218;font-weight:900;font-size:11px}.rs-creative-preview-grid img{width:100%;aspect-ratio:16/9;object-fit:cover;border-radius:14px;background:#eef2f7}.rs-creative-preview-grid strong{font-size:18px;color:#101828}.rs-creative-preview-grid p{margin:0}.rs-creative-preview-grid button{width:max-content;border:0;border-radius:999px;background:#d71920;color:#fff;padding:9px 13px;font-weight:900}.rs-creative-preview-grid small{color:#667085;font-weight:700}.rs-home-notes-grid{align-items:start}.rs-home-note-slots{display:grid;gap:12px;margin:14px 0}.rs-home-note-slots article{display:grid;gap:7px;padding:14px;border:1px solid #e6ebf2;border-radius:16px;background:#f8fafc}.rs-home-note-slots article div{display:flex;align-items:center;gap:10px}.rs-home-note-slots span{display:inline-flex;padding:4px 9px;border-radius:999px;background:#fff1f1;color:#b51218;font-size:11px;font-weight:900;text-transform:uppercase}.rs-home-note-slots strong{color:#101828;font-size:15px}.rs-home-note-slots small{color:#667085;font-weight:700}.rs-home-notes-search{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:12px 0 16px}.rs-home-notes-search input[type=search]{min-width:min(360px,100%)}.rs-home-note-hub{display:inline-flex;padding:6px 10px;border-radius:999px;font-weight:900;font-size:11px}.rs-home-note-hub.ready{background:#dcfce7;color:#166534}.rs-home-note-hub.missing{background:#fff1f1;color:#b51218}.rs-home-notes-results td{vertical-align:middle}@media(max-width:1200px){.rs-creative-variant-grid,.rs-creative-preview-grid{grid-template-columns:1fr 1fr}}@media(max-width:782px){.rs-creative-variant-grid,.rs-creative-asset-grid,.rs-creative-preview-grid{grid-template-columns:1fr}.rs-home-notes-search input[type=search],.rs-home-notes-search select{width:100%}}';
    }

    private function admin_media_js() {
        return <<<'JS'
(function($){$(document).on('click','.rs-media-picker',function(e){e.preventDefault();var button=$(this);var target=$(button.data('target'));if(!target.length){target=button.closest('.rs-media-field').find('.rs-media-url').first();}var frame=wp.media({title:button.data('title')||'Select media',button:{text:button.data('button')||'Use this media'},multiple:false,library:{type:button.data('library')||''}});frame.on('select',function(){var attachment=frame.state().get('selection').first().toJSON();target.val(attachment.url).trigger('change');});frame.open();});$(document).on('click','.rs-media-clear',function(e){e.preventDefault();var button=$(this);var target=$(button.data('target'));if(!target.length){target=button.closest('.rs-media-field').find('.rs-media-url').first();}target.val('').trigger('change');});})(jQuery);
JS;
    }

    private function admin_media_css() {
        return '.rs-media-field{display:grid;gap:8px;max-width:820px}.rs-media-field p{margin:6px 0 0}.rs-media-url{max-width:100%}.rs-creative-asset-grid .rs-media-field{max-width:none}.rs-home-pill-radio-list{display:grid;gap:8px;margin:10px 0}.rs-home-pill-radio-list label{font-weight:700;color:#101828}.rs-home-pill-list-form{display:flex;align-items:center;gap:6px;flex-wrap:wrap}.rs-home-pill-list-form select{max-width:150px}.rs-home-pills-manager{display:grid;gap:10px;margin:14px 0 10px}.rs-home-pill-option{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:4px 12px;align-items:center;padding:12px 14px;border:1px solid #e6ebf2;border-radius:14px;background:#f8fafc}.rs-home-pill-option.is-enabled{border-color:#ffd0d3;background:linear-gradient(135deg,#fff5f5,#fff)}.rs-home-pill-check{display:flex;align-items:center;gap:9px;color:#101828}.rs-home-pill-check strong{font-size:14px}.rs-home-pill-meta{grid-column:1;color:#667085;font-size:12px;font-weight:700}.rs-home-pill-option input[type=number]{grid-column:2;grid-row:1 / span 2;text-align:center;font-weight:800}.rs-home-note-slot-head{display:flex!important;flex-wrap:wrap;align-items:center;gap:10px!important}.rs-home-note-slot-head label{display:inline-flex;align-items:center;gap:6px;color:#667085;font-size:12px;font-weight:800}.rs-home-note-slot-head input[type=number]{text-align:center;font-weight:800}.rs-home-note-order-input{width:72px}@media(max-width:782px){.rs-home-pill-option{grid-template-columns:1fr}.rs-home-pill-option input[type=number]{grid-column:1;grid-row:auto;width:80px}.rs-home-note-slot-head label{width:100%;justify-content:space-between}.rs-home-note-slot-head input[type=number]{width:96px}}';
    }

    public function runtime_context() {
        return array(
            'version' => RIFNOTE_SEARCH_VERSION,
            'restUrl' => esc_url_raw(rest_url()),
            'nonce' => wp_create_nonce('wp_rest'),
            'homeUrl' => home_url('/'),
            'pluginUrl' => RIFNOTE_SEARCH_URL,
            'currentMode' => class_exists('Rifnote_Search_Pages') ? Rifnote_Search_Pages::current_mode() : '',
            'siteLogoUrl' => esc_url_raw(get_option('rifnote_site_logo_url', '')),
            'siteIconUrl' => class_exists('Rifnote_Search_PWA') ? Rifnote_Search_PWA::app_icon_url(192) : esc_url_raw(get_site_icon_url(192)),
            'siteLogoWidthDesktop' => absint(get_option('rifnote_site_logo_width_desktop', 220)),
            'homeSearchMediaUrl' => esc_url_raw(get_option('rifnote_home_search_media_url', '')),
            'homeSearchMediaLinkUrl' => esc_url_raw(get_option('rifnote_home_search_media_link_url', '')),
            'homeSearchMediaType' => sanitize_key(get_option('rifnote_home_search_media_type', 'image')),
            'homePills' => class_exists('Rifnote_Search_Admin') ? Rifnote_Search_Admin::home_pills() : array(),
            'showExcerpts' => (bool) get_option('rifnote_show_story_excerpts', true),
            'showAiCards' => (bool) get_option('rifnote_show_ai_cards', true),
            'canManageOptions' => current_user_can('manage_options'),
            'canManagePosts' => current_user_can('edit_posts'),
        );
    }

    public function render_app($mode = 'app') {
        $this->enqueue_app_assets();

        return '<div class="rifnote-search-root" data-rifnote-mode="' . esc_attr($mode) . '"></div>';
    }

    public function render_app_shortcode() {
        return $this->render_app('app');
    }

    public function render_search_bar_shortcode() {
        return $this->render_app('search-bar');
    }

    public function render_trending_shortcode() {
        return $this->render_app('trending');
    }

    public function render_ai_answer_shortcode() {
        return $this->render_app('ai-answer');
    }

    public function render_submit_shortcode() {
        return $this->render_app('publisher-submit');
    }

    public function render_publisher_signup_shortcode() {
        return $this->render_app('publisher-signup');
    }

    public function render_publisher_docs_shortcode() {
        return $this->render_app('publisher-docs');
    }

    public function render_publisher_dashboard_shortcode() {
        return $this->render_app('publisher-dashboard');
    }

    public function render_live_scores_shortcode() {
        return $this->render_app('live-scores');
    }

    public function render_football_hub_shortcode() {
        return $this->render_app('football-hub');
    }

    public function render_team_search_shortcode() {
        return $this->render_app('team-search');
    }

    public function render_player_search_shortcode() {
        return $this->render_app('player-search');
    }

    public function render_transfer_tracker_shortcode() {
        return $this->render_app('transfer-tracker');
    }

    public function render_weather_shortcode() {
        return $this->render_app('weather');
    }

    public function render_contact_shortcode() {
        return $this->render_app('contact');
    }

    public function render_legal_request_shortcode() {
        return $this->render_app('legal-request');
    }

    public function render_dmca_request_shortcode() {
        return $this->render_app('legal-dmca');
    }

    public function render_opt_out_shortcode() {
        return $this->render_app('legal-opt-out');
    }

    public function render_beta_feedback_shortcode() {
        return $this->render_app('beta-feedback');
    }

    public function render_for_you_shortcode() {
        return $this->render_app('for-you');
    }

    public function render_newsletter_shortcode() {
        return $this->render_app('newsletter-signup');
    }

    public function render_sponsor_request_shortcode() {
        return $this->render_app('sponsor-request');
    }

    public function render_advertiser_signup_shortcode() {
        return $this->render_app('advertiser-signup');
    }

    public function render_advertiser_dashboard_shortcode() {
        return $this->render_app('advertiser-dashboard');
    }

    public function render_widget_trending_shortcode() {
        return $this->render_app('widget-trending');
    }
}

register_activation_hook(__FILE__, array('Rifnote_Search_Trending', 'install'));
register_activation_hook(__FILE__, array('Rifnote_Search_Publishers', 'install'));
register_activation_hook(__FILE__, array('Rifnote_Search_Analytics', 'install'));
register_activation_hook(__FILE__, array('Rifnote_Search_Legal', 'install'));
register_activation_hook(__FILE__, array('Rifnote_Search_Hardening', 'install'));
register_activation_hook(__FILE__, array('Rifnote_Search_Beta', 'install'));
register_activation_hook(__FILE__, array('Rifnote_Search_Index', 'install'));
register_activation_hook(__FILE__, array('Rifnote_Search_Platform_Insights', 'install'));
register_activation_hook(__FILE__, array('Rifnote_Search_Retention', 'install'));
register_activation_hook(__FILE__, array('Rifnote_Search_Launch_Readiness', 'install'));
register_activation_hook(__FILE__, array('Rifnote_Search_Football_API', 'install'));
register_activation_hook(__FILE__, array('Rifnote_Search_Football_API', 'schedule'));
register_activation_hook(__FILE__, array('Rifnote_Search_Live_Data', 'install'));
register_activation_hook(__FILE__, array('Rifnote_Search_Ingestion', 'schedule'));
register_activation_hook(__FILE__, array('Rifnote_Search_News_API', 'schedule'));
register_activation_hook(__FILE__, array('Rifnote_Search_Pages', 'activate'));
register_deactivation_hook(__FILE__, array('Rifnote_Search_Ingestion', 'clear_schedule'));
register_deactivation_hook(__FILE__, array('Rifnote_Search_News_API', 'clear_schedule'));
register_deactivation_hook(__FILE__, array('Rifnote_Search_Football_API', 'clear_schedule'));
register_deactivation_hook(__FILE__, array('Rifnote_Search_Admin', 'clear_ads_report_schedule'));

Rifnote_Search_Plugin::instance();
