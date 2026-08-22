<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Admin {
    const ADS_REPORT_CRON_HOOK = 'rifnote_search_ads_scheduled_report';
    const HOME_PILL_META = '_rifnote_home_pill';
    const PERFORMANCE_INDEX_VERSION = '2026-08-11-1';

    public static function maybe_install_performance_indexes() {
        global $wpdb;

        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (get_option('rifnote_performance_index_version') === self::PERFORMANCE_INDEX_VERSION) {
            return;
        }

        $index_exists = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = %s
               AND INDEX_NAME = %s",
            $wpdb->postmeta,
            'rifnote_meta_key_value'
        ));

        if (!$index_exists) {
            $wpdb->query("ALTER TABLE {$wpdb->postmeta} ADD INDEX rifnote_meta_key_value (meta_key(191), meta_value(191))");
        }

        update_option('rifnote_performance_index_version', self::PERFORMANCE_INDEX_VERSION, false);
    }

    public static function cron_schedules($schedules) {
        if (!isset($schedules['rifnote_weekly'])) {
            $schedules['rifnote_weekly'] = array(
                'interval' => WEEK_IN_SECONDS,
                'display' => __('Weekly', 'rifnote-search'),
            );
        }

        return $schedules;
    }

    public static function schedule_ads_report() {
        $frequency = self::sanitize_report_frequency(get_option('rifnote_ads_report_frequency', 'off'));

        if ('off' === $frequency) {
            self::clear_ads_report_schedule();
            return;
        }

        $schedule = 'weekly' === $frequency ? 'rifnote_weekly' : 'daily';
        $current = wp_get_schedule(self::ADS_REPORT_CRON_HOOK);

        if ($current && $current !== $schedule) {
            self::clear_ads_report_schedule();
        }

        if (!wp_next_scheduled(self::ADS_REPORT_CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, $schedule, self::ADS_REPORT_CRON_HOOK);
        }
    }

    public static function clear_ads_report_schedule() {
        wp_clear_scheduled_hook(self::ADS_REPORT_CRON_HOOK);
    }

    public static function send_scheduled_ads_report() {
        $email = sanitize_email(get_option('rifnote_ads_report_email', get_option('admin_email')));

        if (!$email) {
            return false;
        }

        $days = self::sanitize_report_days(get_option('rifnote_ads_report_days', 30));
        $revenue = Rifnote_Search_Analytics::ad_revenue_report($days);
        $performance = Rifnote_Search_Analytics::ad_performance_summary($days);
        $summary = $revenue['summary'] ?? array();
        $top_slot = $performance['top_slots'][0] ?? null;
        $subject = sprintf(__('Rifnote ad report: ₦%s paid value tracked', 'rifnote-search'), number_format_i18n((float) ($summary['paid'] ?? 0)));
        $message = '<h2>' . esc_html__('Rifnote Ads Report', 'rifnote-search') . '</h2>';
        $message .= '<p>' . esc_html(sprintf(__('Window: last %d day(s).', 'rifnote-search'), $days)) . '</p>';
        $message .= '<ul>';
        $message .= '<li>' . esc_html(sprintf(__('Pipeline: ₦%s', 'rifnote-search'), number_format_i18n((float) ($summary['pipeline'] ?? 0)))) . '</li>';
        $message .= '<li>' . esc_html(sprintf(__('Booked: ₦%s', 'rifnote-search'), number_format_i18n((float) ($summary['booked'] ?? 0)))) . '</li>';
        $message .= '<li>' . esc_html(sprintf(__('Paid: ₦%s', 'rifnote-search'), number_format_i18n((float) ($summary['paid'] ?? 0)))) . '</li>';
        if ($top_slot) {
            $message .= '<li>' . esc_html(sprintf(__('Top placement: %1$s with %2$s views and %3$s clicks.', 'rifnote-search'), $top_slot['label'], number_format_i18n((int) $top_slot['impressions']), number_format_i18n((int) $top_slot['clicks']))) . '</li>';
        }
        $message .= '</ul>';
        $message .= '<p><a href="' . esc_url(admin_url('admin.php?page=rifnote-search-ads-reports')) . '">' . esc_html__('Open full Reports & Exports desk', 'rifnote-search') . '</a></p>';

        return wp_mail($email, $subject, $message, array('Content-Type: text/html; charset=UTF-8'));
    }

    public static function ensure_notes_category() {
        if (!taxonomy_exists('category')) {
            return 0;
        }

        $term = get_term_by('slug', 'notes', 'category');

        if ($term && !is_wp_error($term)) {
            return (int) $term->term_id;
        }

        $created = wp_insert_term(__('Notes', 'rifnote-search'), 'category', array(
            'slug' => 'notes',
            'description' => __('Protected Rifnote category for manually curated homepage Notes.', 'rifnote-search'),
        ));

        return is_wp_error($created) ? 0 : (int) ($created['term_id'] ?? 0);
    }

    public static function protect_notes_category($delete, $term, $taxonomy = '') {
        if ('category' !== $taxonomy) {
            return $delete;
        }

        $term_id = is_object($term) ? (int) $term->term_id : absint($term);
        $notes_id = self::ensure_notes_category();

        if ($notes_id && $term_id === $notes_id) {
            return new WP_Error('rifnote_notes_category_protected', __('The Notes category is required by Rifnote and cannot be deleted.', 'rifnote-search'));
        }

        return $delete;
    }

    public static function story_default_image_url($post_id = 0) {
        $post_id = absint($post_id);

        if ($post_id) {
            $categories = get_the_category($post_id);

            foreach ($categories as $category) {
                $term_image = esc_url_raw(get_term_meta((int) $category->term_id, 'rifnote_default_story_image_url', true));

                if ($term_image) {
                    return $term_image;
                }
            }
        }

        $global_image = esc_url_raw(get_option('rifnote_default_story_image_url', ''));

        if ($global_image) {
            return $global_image;
        }

        return esc_url_raw(RIFNOTE_SEARCH_URL . 'public/images/rifnote-default-story-image.png');
    }

    public static function category_default_image_add_field($taxonomy = '') {
        wp_nonce_field('rifnote_category_default_story_image', 'rifnote_category_default_story_image_nonce');
        ?>
        <div class="form-field term-rifnote-default-image-wrap">
            <label for="rifnote_category_default_story_image_url"><?php esc_html_e('Default story image', 'rifnote-search'); ?></label>
            <div class="rs-media-field">
                <input id="rifnote_category_default_story_image_url" class="large-text rs-media-url" type="url" name="rifnote_category_default_story_image_url" value="" placeholder="https://..." />
                <p>
                    <button type="button" class="button rs-media-picker" data-target="#rifnote_category_default_story_image_url" data-library="image" data-title="<?php esc_attr_e('Choose category story image', 'rifnote-search'); ?>" data-button="<?php esc_attr_e('Use image', 'rifnote-search'); ?>"><?php esc_html_e('Choose from Media Library', 'rifnote-search'); ?></button>
                    <button type="button" class="button rs-media-clear" data-target="#rifnote_category_default_story_image_url"><?php esc_html_e('Clear', 'rifnote-search'); ?></button>
                </p>
            </div>
            <p><?php esc_html_e('Used when a post in this category has no featured image.', 'rifnote-search'); ?></p>
        </div>
        <?php
    }

    public static function category_default_image_edit_field($term) {
        $image_url = esc_url_raw(get_term_meta((int) $term->term_id, 'rifnote_default_story_image_url', true));
        wp_nonce_field('rifnote_category_default_story_image', 'rifnote_category_default_story_image_nonce');
        ?>
        <tr class="form-field term-rifnote-default-image-wrap">
            <th scope="row"><label for="rifnote_category_default_story_image_url"><?php esc_html_e('Default story image', 'rifnote-search'); ?></label></th>
            <td>
                <div class="rs-media-field">
                    <input id="rifnote_category_default_story_image_url" class="large-text rs-media-url" type="url" name="rifnote_category_default_story_image_url" value="<?php echo esc_attr($image_url); ?>" placeholder="https://..." />
                    <p>
                        <button type="button" class="button rs-media-picker" data-target="#rifnote_category_default_story_image_url" data-library="image" data-title="<?php esc_attr_e('Choose category story image', 'rifnote-search'); ?>" data-button="<?php esc_attr_e('Use image', 'rifnote-search'); ?>"><?php esc_html_e('Choose from Media Library', 'rifnote-search'); ?></button>
                        <button type="button" class="button rs-media-clear" data-target="#rifnote_category_default_story_image_url"><?php esc_html_e('Clear', 'rifnote-search'); ?></button>
                    </p>
                </div>
                <p class="description"><?php esc_html_e('Used before the global fallback when posts in this category have no featured image.', 'rifnote-search'); ?></p>
            </td>
        </tr>
        <?php
    }

    public static function save_category_default_image($term_id) {
        if (!current_user_can('manage_categories')) {
            return;
        }

        $nonce = isset($_POST['rifnote_category_default_story_image_nonce']) ? sanitize_text_field(wp_unslash($_POST['rifnote_category_default_story_image_nonce'])) : '';

        if (!$nonce || !wp_verify_nonce($nonce, 'rifnote_category_default_story_image')) {
            return;
        }

        $image_url = isset($_POST['rifnote_category_default_story_image_url']) ? esc_url_raw(wp_unslash($_POST['rifnote_category_default_story_image_url'])) : '';

        if ($image_url) {
            update_term_meta((int) $term_id, 'rifnote_default_story_image_url', $image_url);
        } else {
            delete_term_meta((int) $term_id, 'rifnote_default_story_image_url');
        }
    }

    public static function add_home_pill_meta_box() {
        add_meta_box(
            'rifnote-home-pill-assignment',
            __('Rifnote Homepage Pill', 'rifnote-search'),
            array(__CLASS__, 'render_home_pill_meta_box'),
            'post',
            'side',
            'high'
        );
    }

    public static function render_home_pill_meta_box($post) {
        wp_nonce_field('rifnote_home_pill_assignment', 'rifnote_home_pill_nonce');
        $selected = self::post_home_pill((int) $post->ID);

        echo '<p>' . esc_html__('Send this post into a homepage pill. Notes is curated manually from the Homepage Notes desk.', 'rifnote-search') . '</p>';
        echo '<fieldset class="rs-home-pill-radio-list">';
        echo '<label><input type="radio" name="rifnote_home_pill_assignment" value="" ' . checked('', $selected, false) . ' /> ' . esc_html__('Not featured on homepage pills', 'rifnote-search') . '</label>';

        foreach (self::home_pills() as $pill) {
            if (!empty($pill['is_notes'])) {
                continue;
            }

            $pill_key = self::home_pill_key($pill['category']);
            echo '<label><input type="radio" name="rifnote_home_pill_assignment" value="' . esc_attr($pill_key) . '" ' . checked($pill_key, $selected, false) . ' /> ' . esc_html($pill['label']) . '</label>';
        }

        echo '</fieldset>';
    }

    public static function save_home_pill_assignment($post_id, $post = null, $update = false) {
        unset($post, $update);

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (!isset($_POST['rifnote_home_pill_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_home_pill_nonce'])), 'rifnote_home_pill_assignment')) {
            return;
        }

        $pill = isset($_POST['rifnote_home_pill_assignment']) ? sanitize_key(wp_unslash($_POST['rifnote_home_pill_assignment'])) : '';
        self::set_post_home_pill((int) $post_id, $pill);
    }

    public static function home_pill_post_columns($columns) {
        $columns['rifnote_home_pill'] = __('Homepage Pill', 'rifnote-search');

        return $columns;
    }

    public static function render_home_pill_post_column($column, $post_id) {
        if ('rifnote_home_pill' !== $column) {
            return;
        }

        $selected = self::post_home_pill((int) $post_id);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="rs-home-pill-list-form">';
        echo '<input type="hidden" name="action" value="rifnote_update_home_pill" />';
        echo '<input type="hidden" name="post_id" value="' . esc_attr((int) $post_id) . '" />';
        echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr(wp_unslash($_SERVER['REQUEST_URI'] ?? '')) . '" />';
        wp_nonce_field('rifnote_update_home_pill_' . (int) $post_id, 'rifnote_home_pill_list_nonce', false);
        echo '<select name="home_pill">';
        echo '<option value="" ' . selected('', $selected, false) . '>' . esc_html__('None', 'rifnote-search') . '</option>';

        foreach (self::home_pills() as $pill) {
            if (!empty($pill['is_notes'])) {
                continue;
            }

            $pill_key = self::home_pill_key($pill['category']);
            echo '<option value="' . esc_attr($pill_key) . '" ' . selected($pill_key, $selected, false) . '>' . esc_html($pill['label']) . '</option>';
        }

        echo '</select> ';
        echo '<button class="button button-small" type="submit">' . esc_html__('Save', 'rifnote-search') . '</button>';
        echo '</form>';
    }

    public static function handle_home_pill_post_list_update() {
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('You cannot update this post.', 'rifnote-search'));
        }

        if (!isset($_POST['rifnote_home_pill_list_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_home_pill_list_nonce'])), 'rifnote_update_home_pill_' . $post_id)) {
            wp_die(esc_html__('Security check failed.', 'rifnote-search'));
        }

        $pill = isset($_POST['home_pill']) ? sanitize_key(wp_unslash($_POST['home_pill'])) : '';
        self::set_post_home_pill($post_id, $pill);

        $redirect = isset($_POST['_wp_http_referer']) ? esc_url_raw(wp_unslash($_POST['_wp_http_referer'])) : admin_url('edit.php');
        wp_safe_redirect($redirect ? $redirect : admin_url('edit.php'));
        exit;
    }

    public static function bulk_home_pill_actions($actions) {
        foreach (self::home_pills() as $pill) {
            if (!empty($pill['is_notes'])) {
                continue;
            }

            $pill_key = self::home_pill_key($pill['category']);
            $actions['rifnote_home_pill_' . $pill_key] = sprintf(
                __('Feature in %s pill', 'rifnote-search'),
                $pill['label']
            );
        }

        $actions['rifnote_home_pill_clear'] = __('Remove from homepage pills', 'rifnote-search');

        return $actions;
    }

    public static function handle_bulk_home_pill_action($redirect_to, $action, $post_ids) {
        if ('rifnote_home_pill_clear' === $action) {
            $pill = '';
        } elseif (0 === strpos($action, 'rifnote_home_pill_')) {
            $pill = sanitize_key(substr($action, strlen('rifnote_home_pill_')));
        } else {
            return $redirect_to;
        }

        $updated = 0;
        foreach ((array) $post_ids as $post_id) {
            $post_id = absint($post_id);
            if (!$post_id || !current_user_can('edit_post', $post_id)) {
                continue;
            }

            self::set_post_home_pill($post_id, $pill);
            $updated++;
        }

        return add_query_arg('rifnote_home_pill_bulk_updated', $updated, $redirect_to);
    }

    public static function bulk_home_pill_notice() {
        if (empty($_GET['rifnote_home_pill_bulk_updated'])) {
            return;
        }

        $updated = absint($_GET['rifnote_home_pill_bulk_updated']);
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
            _n('%d post homepage pill updated.', '%d post homepage pills updated.', $updated, 'rifnote-search'),
            $updated
        )) . '</p></div>';
    }

    public static function register_menu() {
        add_menu_page(
            __('Rifnote Search', 'rifnote-search'),
            __('Rifnote Search', 'rifnote-search'),
            'manage_options',
            'rifnote-search',
            array(__CLASS__, 'render_page'),
            'dashicons-search',
            58
        );

        foreach (self::admin_pages() as $slug => $page) {
            add_submenu_page(
                'rifnote-search',
                $page['title'],
                $page['menu'],
                'manage_options',
                $slug,
                array(__CLASS__, 'render_page')
            );
        }

        foreach (self::home_notes_pages() as $slug => $page) {
            add_submenu_page(
                'rifnote-search',
                $page['title'],
                $page['menu'],
                'manage_options',
                $slug,
                array(__CLASS__, 'render_page')
            );
        }

        add_menu_page(
            __('Rifnote Social', 'rifnote-search'),
            __('Rifnote Social', 'rifnote-search'),
            'manage_options',
            'rifnote-search-social',
            array(__CLASS__, 'render_page'),
            'dashicons-share',
            60
        );

        foreach (self::social_pages() as $slug => $page) {
            add_submenu_page(
                'rifnote-search-social',
                $page['title'],
                $page['menu'],
                'manage_options',
                $slug,
                array(__CLASS__, 'render_page')
            );
        }

        foreach (self::ads_pages() as $slug => $page) {
            add_submenu_page(
                'rifnote-search',
                $page['title'],
                sprintf(__('Ads: %s', 'rifnote-search'), $page['menu']),
                'manage_options',
                $slug,
                array(__CLASS__, 'render_page')
            );
        }

        foreach (self::analytics_pages() as $slug => $page) {
            add_submenu_page(
                'rifnote-search',
                $page['title'],
                sprintf(__('Analytics: %s', 'rifnote-search'), $page['menu']),
                'manage_options',
                $slug,
                array(__CLASS__, 'render_page')
            );
        }
    }

    private static function admin_pages() {
        return array(
            'rifnote-search' => array('title' => __('Rifnote Search Dashboard', 'rifnote-search'), 'menu' => __('Dashboard', 'rifnote-search'), 'section' => 'dashboard'),
            'rifnote-search-settings' => array('title' => __('Rifnote Search Settings', 'rifnote-search'), 'menu' => __('Settings', 'rifnote-search'), 'section' => 'settings'),
            'rifnote-search-settings-branding' => array('title' => __('Branding & Media Settings', 'rifnote-search'), 'menu' => __('Settings: Branding', 'rifnote-search'), 'section' => 'settings-branding'),
            'rifnote-search-settings-typography' => array('title' => __('Typography Settings', 'rifnote-search'), 'menu' => __('Settings: Typography', 'rifnote-search'), 'section' => 'settings-typography'),
            'rifnote-search-settings-search-ai' => array('title' => __('Search & AI Settings', 'rifnote-search'), 'menu' => __('Settings: Search & AI', 'rifnote-search'), 'section' => 'settings-search-ai'),
            'rifnote-search-settings-feeds' => array('title' => __('Feeds & API Settings', 'rifnote-search'), 'menu' => __('Settings: Feeds & APIs', 'rifnote-search'), 'section' => 'settings-feeds'),
            'rifnote-search-settings-live' => array('title' => __('Live Data Settings', 'rifnote-search'), 'menu' => __('Settings: Live Data', 'rifnote-search'), 'section' => 'settings-live'),
            'rifnote-search-settings-delivery' => array('title' => __('Delivery & App Settings', 'rifnote-search'), 'menu' => __('Settings: Delivery & App', 'rifnote-search'), 'section' => 'settings-delivery'),
            'rifnote-search-settings-deployment' => array('title' => __('Deployment & Updates Settings', 'rifnote-search'), 'menu' => __('Settings: Deployment', 'rifnote-search'), 'section' => 'settings-deployment'),
            'rifnote-search-source-logos' => array('title' => __('Source Logos', 'rifnote-search'), 'menu' => __('Source Logos', 'rifnote-search'), 'section' => 'source-logos'),
            'rifnote-search-discovery' => array('title' => __('Search & Discovery', 'rifnote-search'), 'menu' => __('Search & Discovery', 'rifnote-search'), 'section' => 'discovery'),
            'rifnote-search-aggregation' => array('title' => __('Manual Aggregation', 'rifnote-search'), 'menu' => __('Manual Aggregation', 'rifnote-search'), 'section' => 'aggregation'),
            'rifnote-search-football' => array('title' => __('Football Control Center', 'rifnote-search'), 'menu' => __('Football', 'rifnote-search'), 'section' => 'football'),
            'rifnote-search-football-curation' => array('title' => __('Football Curation', 'rifnote-search'), 'menu' => __('Football Curation', 'rifnote-search'), 'section' => 'football-curation'),
            'rifnote-search-customgpt' => array('title' => __('CustomGPT Import', 'rifnote-search'), 'menu' => __('CustomGPT Import', 'rifnote-search'), 'section' => 'customgpt'),
            'rifnote-search-publishers' => array('title' => __('Publishers', 'rifnote-search'), 'menu' => __('Publishers', 'rifnote-search'), 'section' => 'publishers'),
            'rifnote-search-operations' => array('title' => __('Operations', 'rifnote-search'), 'menu' => __('Operations', 'rifnote-search'), 'section' => 'operations'),
            'rifnote-search-growth' => array('title' => __('Growth & Monetization', 'rifnote-search'), 'menu' => __('Growth & Monetization', 'rifnote-search'), 'section' => 'growth'),
            'rifnote-search-compliance' => array('title' => __('Legal & Compliance', 'rifnote-search'), 'menu' => __('Legal & Compliance', 'rifnote-search'), 'section' => 'compliance'),
            'rifnote-search-release' => array('title' => __('Release Readiness', 'rifnote-search'), 'menu' => __('Release Readiness', 'rifnote-search'), 'section' => 'release'),
        );
    }

    private static function social_pages() {
        return array(
            'rifnote-search-social' => array('title' => __('Social Media Desk', 'rifnote-search'), 'menu' => __('Dashboard', 'rifnote-search'), 'section' => 'social-dashboard'),
            'rifnote-search-social-import' => array('title' => __('Manual Social Import', 'rifnote-search'), 'menu' => __('Manual Import', 'rifnote-search'), 'section' => 'social-import'),
            'rifnote-search-social-youtube' => array('title' => __('YouTube Importer', 'rifnote-search'), 'menu' => __('YouTube Polling', 'rifnote-search'), 'section' => 'social-youtube'),
            'rifnote-search-social-library' => array('title' => __('Social Library', 'rifnote-search'), 'menu' => __('Library', 'rifnote-search'), 'section' => 'social-library'),
            'rifnote-search-social-customgpt' => array('title' => __('CustomGPT Social Bridge', 'rifnote-search'), 'menu' => __('CustomGPT Bridge', 'rifnote-search'), 'section' => 'social-customgpt'),
        );
    }

    private static function home_notes_pages() {
        return array(
            'rifnote-search-home-notes' => array('title' => __('Homepage Notes', 'rifnote-search'), 'menu' => __('Homepage Notes', 'rifnote-search'), 'section' => 'home-notes'),
            'rifnote-search-featured-tab' => array('title' => __('Featured Homepage Tab', 'rifnote-search'), 'menu' => __('Featured Tab', 'rifnote-search'), 'section' => 'featured-tab'),
        );
    }

    private static function ads_pages() {
        return array(
            'rifnote-search-ads' => array('title' => __('Advert Management', 'rifnote-search'), 'menu' => __('Dashboard', 'rifnote-search'), 'section' => 'ads-dashboard'),
            'rifnote-search-ads-requests' => array('title' => __('Advert Requests', 'rifnote-search'), 'menu' => __('Campaign Requests', 'rifnote-search'), 'section' => 'ads-requests'),
            'rifnote-search-ads-creative' => array('title' => __('Creative Studio', 'rifnote-search'), 'menu' => __('Creative Studio', 'rifnote-search'), 'section' => 'ads-creative'),
            'rifnote-search-ads-audience' => array('title' => __('Audience Intelligence', 'rifnote-search'), 'menu' => __('Audience', 'rifnote-search'), 'section' => 'ads-audience'),
            'rifnote-search-ads-placements' => array('title' => __('Sponsored Placements', 'rifnote-search'), 'menu' => __('Placements', 'rifnote-search'), 'section' => 'ads-placements'),
            'rifnote-search-ads-reports' => array('title' => __('Advert Reports', 'rifnote-search'), 'menu' => __('Reports & Exports', 'rifnote-search'), 'section' => 'ads-reports'),
            'rifnote-search-ads-inventory' => array('title' => __('Ad Inventory & Rates', 'rifnote-search'), 'menu' => __('Inventory & Rates', 'rifnote-search'), 'section' => 'ads-inventory'),
            'rifnote-search-ads-settings' => array('title' => __('Advert Settings', 'rifnote-search'), 'menu' => __('Settings', 'rifnote-search'), 'section' => 'ads-settings'),
        );
    }

    private static function analytics_pages() {
        return array(
            'rifnote-search-analytics' => array('title' => __('Analytics Dashboard', 'rifnote-search'), 'menu' => __('Dashboard', 'rifnote-search'), 'section' => 'analytics-dashboard'),
            'rifnote-search-analytics-audience' => array('title' => __('Audience Analytics', 'rifnote-search'), 'menu' => __('Audience', 'rifnote-search'), 'section' => 'analytics-audience'),
            'rifnote-search-analytics-usage' => array('title' => __('App Usage', 'rifnote-search'), 'menu' => __('App Usage', 'rifnote-search'), 'section' => 'analytics-usage'),
            'rifnote-search-analytics-search' => array('title' => __('Search & Content Analytics', 'rifnote-search'), 'menu' => __('Search & Content', 'rifnote-search'), 'section' => 'analytics-search'),
            'rifnote-search-analytics-football' => array('title' => __('Football Analytics', 'rifnote-search'), 'menu' => __('Football', 'rifnote-search'), 'section' => 'analytics-football'),
            'rifnote-search-analytics-ads' => array('title' => __('Ad Analytics', 'rifnote-search'), 'menu' => __('Ads', 'rifnote-search'), 'section' => 'analytics-ads'),
            'rifnote-search-analytics-events' => array('title' => __('Event Stream', 'rifnote-search'), 'menu' => __('Events', 'rifnote-search'), 'section' => 'analytics-events'),
            'rifnote-search-analytics-settings' => array('title' => __('Analytics Settings', 'rifnote-search'), 'menu' => __('Settings', 'rifnote-search'), 'section' => 'analytics-settings'),
        );
    }

    private static function current_section() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'rifnote-search';
        $pages = array_merge(self::admin_pages(), self::home_notes_pages(), self::social_pages(), self::ads_pages(), self::analytics_pages());

        return isset($pages[$page]) ? $pages[$page]['section'] : 'dashboard';
    }

    private static function is_section($section, $aliases = array()) {
        $current = self::current_section();
        $sections = array_merge(array($section), (array) $aliases);

        return in_array($current, $sections, true);
    }

    private static function football_competition_key($competition) {
        return absint($competition['league_id'] ?? 0) . ':' . absint($competition['season'] ?? 0);
    }

    private static function football_competition_from_key($key, $competitions) {
        $key = sanitize_text_field((string) $key);

        foreach ((array) $competitions as $competition) {
            if (self::football_competition_key($competition) === $key) {
                return $competition;
            }
        }

        return array();
    }

    private static function render_admin_tabs() {
        $current_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'rifnote-search';
        $pages = self::admin_pages();

        if (0 === strpos($current_page, 'rifnote-search-ads')) {
            $pages = self::ads_pages();
        }

        if (0 === strpos($current_page, 'rifnote-search-analytics')) {
            $pages = self::analytics_pages();
        }

        if (0 === strpos($current_page, 'rifnote-search-social')) {
            $pages = self::social_pages();
        }

        if (in_array($current_page, array('rifnote-search-home-notes', 'rifnote-search-featured-tab'), true)) {
            $pages = self::home_notes_pages();
        }

        echo '<nav class="nav-tab-wrapper" style="margin:18px 0 22px;">';
        foreach ($pages as $slug => $page) {
            $class = $slug === $current_page ? ' nav-tab-active' : '';
            echo '<a class="nav-tab' . esc_attr($class) . '" href="' . esc_url(admin_url('admin.php?page=' . $slug)) . '">' . esc_html($page['menu']) . '</a>';
        }
        echo '</nav>';
    }

    private static function settings_families() {
        return array(
            'branding' => array(
                'title' => __('Branding & Media', 'rifnote-search'),
                'description' => __('Logo, homepage media, default story art and the visual identity pieces that show up across the public app.', 'rifnote-search'),
                'section' => 'settings-branding',
                'fields' => array(
                    'rifnote_site_logo_url' => array('label' => __('Site logo', 'rifnote-search'), 'type' => 'media', 'library' => 'image', 'description' => __('Main desktop logo. Mobile uses the compact favicon-style mark.', 'rifnote-search')),
                    'rifnote_site_logo_width_desktop' => array('label' => __('Desktop logo width', 'rifnote-search'), 'type' => 'number', 'min' => 80, 'max' => 420, 'suffix' => 'px'),
                    'rifnote_home_takeover_logo_size_mobile' => array('label' => __('Mobile homepage takeover logo size', 'rifnote-search'), 'type' => 'number', 'min' => 28, 'suffix' => 'px'),
                    'rifnote_default_story_image_url' => array('label' => __('Global default story image', 'rifnote-search'), 'type' => 'media', 'library' => 'image', 'description' => __('Used when a post and its category have no story image.', 'rifnote-search')),
                    'rifnote_home_search_media_url' => array('label' => __('Homepage big media', 'rifnote-search'), 'type' => 'media', 'library' => ''),
                    'rifnote_home_search_media_link_url' => array('label' => __('Homepage media link', 'rifnote-search'), 'type' => 'url', 'description' => __('Optional custom URL or search URL for the homepage media.', 'rifnote-search')),
                    'rifnote_home_search_media_type' => array('label' => __('Homepage media type', 'rifnote-search'), 'type' => 'select', 'options' => array('image' => __('Image / GIF', 'rifnote-search'), 'video' => __('Uploaded video', 'rifnote-search'), 'embed' => __('YouTube / Vimeo', 'rifnote-search'))),
                    'rifnote_home_lead_post_id' => array('label' => __('Lead headline post ID', 'rifnote-search'), 'type' => 'number', 'min' => 0),
                ),
            ),
            'typography' => array(
                'title' => __('Typography', 'rifnote-search'),
                'description' => __('Control the type system without digging through CSS: Google fonts, reading size and title weight.', 'rifnote-search'),
                'section' => 'settings-typography',
                'fields' => array(
                    'rifnote_typography_heading_font' => array('label' => __('Heading font', 'rifnote-search'), 'type' => 'select', 'options' => self::google_font_choices()),
                    'rifnote_typography_body_font' => array('label' => __('Body font', 'rifnote-search'), 'type' => 'select', 'options' => self::google_font_choices()),
                    'rifnote_typography_story_title_size' => array('label' => __('Story title size', 'rifnote-search'), 'type' => 'text'),
                    'rifnote_typography_story_title_weight' => array('label' => __('Story title weight', 'rifnote-search'), 'type' => 'number', 'min' => 300, 'max' => 950),
                    'rifnote_typography_body_size' => array('label' => __('Body text size', 'rifnote-search'), 'type' => 'text'),
                    'rifnote_typography_body_weight' => array('label' => __('Body text weight', 'rifnote-search'), 'type' => 'number', 'min' => 300, 'max' => 800),
                ),
            ),
            'search-ai' => array(
                'title' => __('Search & AI', 'rifnote-search'),
                'description' => __('Search behavior, excerpts, AI answer cards and ranking vocabulary.', 'rifnote-search'),
                'section' => 'settings-search-ai',
                'fields' => array(
                    'rifnote_ai_enabled' => array('label' => __('Enable AI answers', 'rifnote-search'), 'type' => 'checkbox'),
                    'rifnote_show_ai_cards' => array('label' => __('Show AI cards', 'rifnote-search'), 'type' => 'checkbox'),
                    'rifnote_show_story_excerpts' => array('label' => __('Show excerpts', 'rifnote-search'), 'type' => 'checkbox'),
                    'rifnote_openai_api_key' => array('label' => __('OpenAI API key', 'rifnote-search'), 'type' => 'password'),
                    'rifnote_openai_model' => array('label' => __('AI model', 'rifnote-search'), 'type' => 'text'),
                    'rifnote_ai_cache_ttl' => array('label' => __('AI cache TTL', 'rifnote-search'), 'type' => 'number', 'suffix' => __('seconds', 'rifnote-search')),
                    'rifnote_ai_max_answer_length' => array('label' => __('Max answer length', 'rifnote-search'), 'type' => 'number'),
                    'rifnote_search_synonyms' => array('label' => __('Search synonyms', 'rifnote-search'), 'type' => 'textarea', 'rows' => 6),
                    'rifnote_trending_aliases' => array('label' => __('Trending aliases', 'rifnote-search'), 'type' => 'textarea', 'rows' => 6),
                    'rifnote_trending_blocked_terms' => array('label' => __('Blocked trending terms', 'rifnote-search'), 'type' => 'textarea', 'rows' => 4),
                    'rifnote_trending_internet_feeds' => array('label' => __('Internet trend feeds', 'rifnote-search'), 'type' => 'textarea', 'rows' => 7, 'description' => __('One per line: Lane|Weight|Feed URL. Use Google Trends RSS by default, and add social RSS/JSON bridges when available. Lanes should be Football, Nigeria, US or International.', 'rifnote-search')),
                ),
            ),
            'feeds' => array(
                'title' => __('Feeds & APIs', 'rifnote-search'),
                'description' => __('TheNewsAPI import controls. RSS has moved into its own Rifnote RSS warehouse menu with queue, logs and feed health.', 'rifnote-search'),
                'section' => 'settings-feeds',
                'fields' => array(
                    'rifnote_data_api_enabled' => array('label' => __('Enable Data API bridge', 'rifnote-search'), 'type' => 'checkbox', 'description' => __('Read warehouse stories from the external Rifnote data engine.', 'rifnote-search')),
                    'rifnote_data_api_merge_search' => array('label' => __('Merge Data API into search', 'rifnote-search'), 'type' => 'checkbox', 'description' => __('When enabled, WordPress search blends local posts with external warehouse results.', 'rifnote-search')),
                    'rifnote_data_api_url' => array('label' => __('Data API URL', 'rifnote-search'), 'type' => 'url', 'description' => __('Example: https://data.rifnote.com', 'rifnote-search')),
                    'rifnote_data_api_token' => array('label' => __('Data API token', 'rifnote-search'), 'type' => 'password'),
                    'rifnote_data_api_timeout' => array('label' => __('Data API timeout', 'rifnote-search'), 'type' => 'number', 'min' => 3, 'max' => 20, 'suffix' => __('seconds', 'rifnote-search')),
                    'rifnote_data_api_cache_ttl' => array('label' => __('Data API search cache', 'rifnote-search'), 'type' => 'number', 'min' => 0, 'max' => 900, 'suffix' => __('seconds', 'rifnote-search')),
                    'rifnote_thenewsapi_enabled' => array('label' => __('Enable TheNewsAPI', 'rifnote-search'), 'type' => 'checkbox'),
                    'rifnote_thenewsapi_key' => array('label' => __('TheNewsAPI key', 'rifnote-search'), 'type' => 'password'),
                    'rifnote_thenewsapi_locale' => array('label' => __('Locales', 'rifnote-search'), 'type' => 'text'),
                    'rifnote_thenewsapi_language' => array('label' => __('Language', 'rifnote-search'), 'type' => 'text'),
                    'rifnote_thenewsapi_categories' => array('label' => __('Categories', 'rifnote-search'), 'type' => 'text'),
                    'rifnote_thenewsapi_limit' => array('label' => __('Import limit', 'rifnote-search'), 'type' => 'number'),
                    'rifnote_thenewsapi_auto_publish' => array('label' => __('Auto-publish API items', 'rifnote-search'), 'type' => 'checkbox'),
                ),
            ),
            'live' => array(
                'title' => __('Live Data', 'rifnote-search'),
                'description' => __('Weather, markets and live sidebar polling settings.', 'rifnote-search'),
                'section' => 'settings-live',
                'fields' => array(
                    'rifnote_live_data_poll_ttl' => array('label' => __('Live data poll TTL', 'rifnote-search'), 'type' => 'number', 'suffix' => __('seconds', 'rifnote-search')),
                    'rifnote_live_weather_locations' => array('label' => __('Weather locations', 'rifnote-search'), 'type' => 'textarea', 'rows' => 6),
                    'rifnote_live_market_pairs' => array('label' => __('Market pairs', 'rifnote-search'), 'type' => 'textarea', 'rows' => 6),
                ),
            ),
            'delivery' => array(
                'title' => __('Delivery & App', 'rifnote-search'),
                'description' => __('Email, push, PWA/native wrapper and app release controls.', 'rifnote-search'),
                'section' => 'settings-delivery',
                'fields' => array(
                    'rifnote_email_provider' => array('label' => __('Email provider', 'rifnote-search'), 'type' => 'select', 'options' => array('wp_mail' => 'wp_mail', 'smtp' => 'SMTP', 'sendgrid' => 'SendGrid', 'mailgun' => 'Mailgun')),
                    'rifnote_email_api_key' => array('label' => __('Email API key', 'rifnote-search'), 'type' => 'password'),
                    'rifnote_email_from' => array('label' => __('From email', 'rifnote-search'), 'type' => 'email'),
                    'rifnote_push_provider' => array('label' => __('Push provider', 'rifnote-search'), 'type' => 'select', 'options' => array('local' => __('Local Web Push', 'rifnote-search'), 'onesignal' => 'OneSignal', 'firebase' => 'Firebase')),
                    'rifnote_vapid_public_key' => array('label' => __('VAPID public key', 'rifnote-search'), 'type' => 'password'),
                    'rifnote_vapid_private_key' => array('label' => __('VAPID private key', 'rifnote-search'), 'type' => 'password'),
                    'rifnote_native_ios_bundle_id' => array('label' => __('iOS bundle ID', 'rifnote-search'), 'type' => 'text'),
                    'rifnote_native_android_package' => array('label' => __('Android package', 'rifnote-search'), 'type' => 'text'),
                    'rifnote_release_notes' => array('label' => __('Release notes', 'rifnote-search'), 'type' => 'textarea', 'rows' => 6),
                ),
            ),
            'deployment' => array(
                'title' => __('Deployment & Updates', 'rifnote-search'),
                'description' => __('Let WordPress update Rifnote Search from tagged GitHub Releases instead of manual plugin uploads.', 'rifnote-search'),
                'section' => 'settings-deployment',
                'fields' => array(
                    'rifnote_github_updater_enabled' => array('label' => __('Enable GitHub updater', 'rifnote-search'), 'type' => 'checkbox', 'description' => __('When enabled, WordPress plugin updates will check the configured GitHub Releases feed.', 'rifnote-search')),
                    'rifnote_github_repo' => array('label' => __('GitHub repository', 'rifnote-search'), 'type' => 'text', 'description' => __('Use owner/repo, for example TrevosAxiom/riffy-search-exgex.', 'rifnote-search')),
                    'rifnote_github_asset_name' => array('label' => __('Release zip asset name', 'rifnote-search'), 'type' => 'text', 'description' => __('The release must include this zip asset. The bundled workflow creates rifnote-search.zip.', 'rifnote-search')),
                    'rifnote_github_access_token' => array('label' => __('GitHub access token', 'rifnote-search'), 'type' => 'password', 'description' => __('Optional. Use a fine-grained token with repository contents read access if the repo is private.', 'rifnote-search')),
                ),
            ),
        );
    }

    private static function render_settings_home_page() {
        $families = self::settings_families();
        echo '<div class="card" style="max-width:1120px;"><h2>' . esc_html__('Settings families', 'rifnote-search') . '</h2><p>' . esc_html__('Rifnote settings are now split into focused pages so admins can jump straight to the part of the platform they are tuning.', 'rifnote-search') . '</p></div>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;max-width:1120px;margin-top:16px;">';
        foreach ($families as $key => $family) {
            echo '<a class="card" style="text-decoration:none;display:block;" href="' . esc_url(admin_url('admin.php?page=rifnote-search-settings-' . $key)) . '">';
            echo '<h2 style="margin-top:0;">' . esc_html($family['title']) . '</h2>';
            echo '<p style="color:#667085;">' . esc_html($family['description']) . '</p>';
            echo '<span class="button button-primary">' . esc_html__('Open settings', 'rifnote-search') . '</span>';
            echo '</a>';
        }
        echo '</div>';
    }

    private static function render_settings_family_page($section) {
        $families = self::settings_families();
        $family = null;

        foreach ($families as $key => $candidate) {
            if ($candidate['section'] === $section) {
                $family = $candidate;
                break;
            }
        }

        if (!$family) {
            self::render_settings_home_page();
            return;
        }

        echo '<div class="card" style="max-width:1120px;"><h2>' . esc_html($family['title']) . '</h2><p>' . esc_html($family['description']) . '</p></div>';
        if ('settings-deployment' === $section) {
            self::render_github_updater_status();
        }
        if ('settings-feeds' === $section) {
            Rifnote_Search_Ingestion::repair_schedule(true);
            self::render_data_api_status();
        }
        echo '<form method="post" action="options.php" style="max-width:1120px;margin-top:16px;">';
        settings_fields('rifnote_search_settings');
        self::render_preserved_settings_fields(array_keys($family['fields']));
        echo '<div class="card"><table class="form-table" role="presentation"><tbody>';
        foreach ($family['fields'] as $option => $field) {
            self::render_settings_family_field($option, $field);
        }
        echo '</tbody></table></div>';
        submit_button(__('Save settings', 'rifnote-search'));
        echo '</form>';
    }

    private static function render_data_api_status() {
        $force = !empty($_GET['rifnote_data_api_check']) && !empty($_GET['rifnote_data_api_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['rifnote_data_api_nonce'])), 'rifnote_data_api_check');
        $health = class_exists('Rifnote_Search_Data_API') ? Rifnote_Search_Data_API::health($force) : array('ok' => false, 'message' => __('Data API bridge is not loaded.', 'rifnote-search'));
        $stats = class_exists('Rifnote_Search_Data_API') ? Rifnote_Search_Data_API::stats($force) : array('ok' => false);
        $counts = is_array($stats['counts'] ?? null) ? $stats['counts'] : array();
        $last = get_option('rifnote_data_api_last_check', array());
        $check_url = wp_nonce_url(add_query_arg('rifnote_data_api_check', '1'), 'rifnote_data_api_check', 'rifnote_data_api_nonce');
        ?>
        <div class="card" style="max-width:1120px;margin-top:16px;">
            <h2><?php esc_html_e('Rifnote Data Engine', 'rifnote-search'); ?></h2>
            <p><?php esc_html_e('Connection health for the PostgreSQL warehouse that powers RSS, social, YouTube and high-volume imported content.', 'rifnote-search'); ?></p>
            <p>
                <strong><?php esc_html_e('Status:', 'rifnote-search'); ?></strong>
                <span style="color:<?php echo !empty($health['ok']) ? '#047857' : '#b42318'; ?>;">
                    <?php echo esc_html(!empty($health['ok']) ? __('Connected', 'rifnote-search') : __('Not connected', 'rifnote-search')); ?>
                </span>
                &nbsp;·&nbsp;<?php echo esc_html((string) ($health['message'] ?? ($health['ok'] ? 'OK' : ''))); ?>
            </p>
            <?php if (class_exists('Rifnote_Search_Data_API')) : ?>
                <p class="description">
                    <?php
                    $fingerprint = Rifnote_Search_Data_API::token_fingerprint();
                    echo esc_html($fingerprint ? sprintf(__('Token saved · fingerprint %s', 'rifnote-search'), $fingerprint) : __('No Data API token is saved yet.', 'rifnote-search'));
                    if (!empty($last['checked_at'])) {
                        echo esc_html(' · ' . sprintf(__('Last checked: %s', 'rifnote-search'), get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($last['checked_at'])), 'M j, Y H:i')));
                    }
                    ?>
                </p>
            <?php endif; ?>
            <?php if (!empty($stats['ok'])) : ?>
                <div style="display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:10px;margin:14px 0;">
                    <p style="margin:0;padding:10px;background:#f6f7f7;border-radius:8px;"><strong><?php echo esc_html(number_format_i18n((int) ($counts['sources'] ?? 0))); ?></strong><br /><?php esc_html_e('Sources', 'rifnote-search'); ?></p>
                    <p style="margin:0;padding:10px;background:#f6f7f7;border-radius:8px;"><strong><?php echo esc_html(number_format_i18n((int) ($counts['feed_channels'] ?? 0))); ?></strong><br /><?php esc_html_e('Feeds', 'rifnote-search'); ?></p>
                    <p style="margin:0;padding:10px;background:#f6f7f7;border-radius:8px;"><strong><?php echo esc_html(number_format_i18n((int) ($counts['external_items'] ?? 0))); ?></strong><br /><?php esc_html_e('Items', 'rifnote-search'); ?></p>
                    <p style="margin:0;padding:10px;background:#f6f7f7;border-radius:8px;"><strong><?php echo esc_html(number_format_i18n((int) ($counts['ingest_runs'] ?? 0))); ?></strong><br /><?php esc_html_e('Ingest runs', 'rifnote-search'); ?></p>
                    <p style="margin:0;padding:10px;background:#f6f7f7;border-radius:8px;"><strong><?php echo esc_html(number_format_i18n((int) ($counts['items_24h'] ?? 0))); ?></strong><br /><?php esc_html_e('Last 24h', 'rifnote-search'); ?></p>
                </div>
            <?php endif; ?>
            <p><a class="button button-secondary" href="<?php echo esc_url($check_url); ?>"><?php esc_html_e('Check Data API now', 'rifnote-search'); ?></a></p>
        </div>
        <?php
    }

    private static function render_github_updater_status() {
        if (isset($_POST['rifnote_github_check_now']) && check_admin_referer('rifnote_github_check_now')) {
            delete_site_transient(Rifnote_Search_GitHub_Updater::TRANSIENT_KEY);
            Rifnote_Search_GitHub_Updater::latest_release(true);
        }

        $release = Rifnote_Search_GitHub_Updater::latest_release(false);
        $settings = Rifnote_Search_GitHub_Updater::settings();
        $last_checked = get_option('rifnote_github_last_checked', '');
        $last_error = get_option('rifnote_github_last_error', '');

        echo '<div class="card" style="max-width:1120px;margin-top:16px;">';
        echo '<h2 style="margin-top:0;">' . esc_html__('GitHub updater health', 'rifnote-search') . '</h2>';
        echo '<p><strong>' . esc_html__('Current plugin version:', 'rifnote-search') . '</strong> ' . esc_html(RIFNOTE_SEARCH_VERSION) . '</p>';
        echo '<p><strong>' . esc_html__('Repository:', 'rifnote-search') . '</strong> ' . esc_html($settings['repo']) . '</p>';

        if (is_wp_error($release)) {
            echo '<p style="color:#b42318;"><strong>' . esc_html__('Latest check:', 'rifnote-search') . '</strong> ' . esc_html($release->get_error_message()) . '</p>';
        } elseif (is_array($release) && !empty($release['version'])) {
            echo '<p><strong>' . esc_html__('Latest release:', 'rifnote-search') . '</strong> ' . esc_html($release['tag_name']) . ' (' . esc_html($release['asset_name']) . ')</p>';
            if (version_compare($release['version'], RIFNOTE_SEARCH_VERSION, '>')) {
                echo '<p style="color:#047857;"><strong>' . esc_html__('Update available.', 'rifnote-search') . '</strong> ' . esc_html__('Open Plugins to run the update through WordPress.', 'rifnote-search') . '</p>';
            } else {
                echo '<p style="color:#047857;"><strong>' . esc_html__('Up to date.', 'rifnote-search') . '</strong></p>';
            }
        } elseif ($last_error) {
            echo '<p style="color:#b42318;"><strong>' . esc_html__('Latest check:', 'rifnote-search') . '</strong> ' . esc_html($last_error) . '</p>';
        } else {
            echo '<p>' . esc_html__('No GitHub release check has run yet.', 'rifnote-search') . '</p>';
        }

        if ($last_checked) {
            echo '<p class="description">' . esc_html(sprintf(__('Last checked: %s', 'rifnote-search'), $last_checked)) . '</p>';
        }

        echo '<form method="post" style="margin-top:12px;">';
        wp_nonce_field('rifnote_github_check_now');
        submit_button(__('Check GitHub now', 'rifnote-search'), 'secondary', 'rifnote_github_check_now', false);
        echo '</form>';
        echo '</div>';
    }

    private static function render_settings_family_field($option, $field) {
        $type = $field['type'] ?? 'text';
        $value = self::settings_option_value($option);
        echo '<tr><th scope="row"><label for="' . esc_attr($option) . '">' . esc_html($field['label']) . '</label></th><td>';

        if ('checkbox' === $type) {
            echo '<input type="hidden" name="' . esc_attr($option) . '" value="0" />';
            echo '<label><input id="' . esc_attr($option) . '" type="checkbox" name="' . esc_attr($option) . '" value="1" ' . checked((bool) $value, true, false) . ' /> ' . esc_html__('Enabled', 'rifnote-search') . '</label>';
        } elseif ('textarea' === $type) {
            echo '<textarea id="' . esc_attr($option) . '" class="large-text" rows="' . esc_attr((int) ($field['rows'] ?? 4)) . '" name="' . esc_attr($option) . '">' . esc_textarea((string) $value) . '</textarea>';
        } elseif ('select' === $type) {
            echo '<select id="' . esc_attr($option) . '" name="' . esc_attr($option) . '">';
            foreach (($field['options'] ?? array()) as $key => $label) {
                echo '<option value="' . esc_attr($key) . '" ' . selected((string) $value, (string) $key, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
        } elseif ('media' === $type) {
            echo '<div class="rs-media-field"><input id="' . esc_attr($option) . '" class="large-text rs-media-url" type="url" name="' . esc_attr($option) . '" value="' . esc_attr((string) $value) . '" placeholder="https://..." />';
            echo '<p><button type="button" class="button rs-media-picker" data-target="#' . esc_attr($option) . '" data-library="' . esc_attr($field['library'] ?? '') . '" data-title="' . esc_attr__('Choose media', 'rifnote-search') . '" data-button="' . esc_attr__('Use media', 'rifnote-search') . '">' . esc_html__('Choose from Media Library', 'rifnote-search') . '</button> ';
            echo '<button type="button" class="button rs-media-clear" data-target="#' . esc_attr($option) . '">' . esc_html__('Clear', 'rifnote-search') . '</button></p></div>';
        } else {
            $input_type = in_array($type, array('number', 'url', 'email', 'password'), true) ? $type : 'text';
            echo '<input id="' . esc_attr($option) . '" class="large-text" type="' . esc_attr($input_type) . '" name="' . esc_attr($option) . '" value="' . esc_attr((string) $value) . '"';
            if (isset($field['min'])) {
                echo ' min="' . esc_attr($field['min']) . '"';
            }
            if (isset($field['max'])) {
                echo ' max="' . esc_attr($field['max']) . '"';
            }
            echo ' />';
            if (!empty($field['suffix'])) {
                echo ' <span>' . esc_html($field['suffix']) . '</span>';
            }
        }

        if (!empty($field['description'])) {
            echo '<p class="description">' . esc_html($field['description']) . '</p>';
        }

        echo '</td></tr>';
    }

    private static function render_preserved_settings_fields($visible_options) {
        $visible_options = array_flip(array_map('sanitize_key', (array) $visible_options));
        $registered = self::registered_options_for_group('rifnote_search_settings');

        foreach ($registered as $option) {
            $option = sanitize_key((string) $option);

            if (!$option || isset($visible_options[$option])) {
                continue;
            }

            self::render_hidden_option_field($option, self::settings_option_value($option));
        }
    }

    private static function settings_option_value($option) {
        $missing = '__rifnote_missing_option__';
        $value = get_option($option, $missing);

        if ($missing === $value) {
            return self::settings_option_default($option);
        }

        if (in_array($option, array('rifnote_github_repo', 'rifnote_github_asset_name'), true) && '' === trim((string) $value)) {
            return self::settings_option_default($option);
        }

        return $value;
    }

    private static function settings_option_default($option) {
        $defaults = array(
            'rifnote_github_updater_enabled' => true,
            'rifnote_github_repo' => Rifnote_Search_GitHub_Updater::DEFAULT_REPO,
            'rifnote_github_asset_name' => Rifnote_Search_GitHub_Updater::DEFAULT_ASSET,
            'rifnote_github_access_token' => '',
            'rifnote_data_api_enabled' => false,
            'rifnote_data_api_merge_search' => true,
            'rifnote_data_api_url' => '',
            'rifnote_data_api_token' => '',
            'rifnote_data_api_timeout' => 8,
            'rifnote_data_api_cache_ttl' => 120,
        );

        return array_key_exists($option, $defaults) ? $defaults[$option] : '';
    }

    private static function registered_options_for_group($group) {
        $group = sanitize_key($group);
        $allowed = array();

        if (!empty($GLOBALS['new_allowed_options'][$group]) && is_array($GLOBALS['new_allowed_options'][$group])) {
            $allowed = $GLOBALS['new_allowed_options'][$group];
        } elseif (!empty($GLOBALS['allowed_options'][$group]) && is_array($GLOBALS['allowed_options'][$group])) {
            $allowed = $GLOBALS['allowed_options'][$group];
        }

        return array_values(array_unique(array_map('sanitize_key', $allowed)));
    }

    private static function render_hidden_option_field($name, $value) {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                self::render_hidden_option_field($name . '[' . sanitize_key((string) $key) . ']', $item);
            }
            return;
        }

        echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '" />';
    }

    public static function preserve_missing_settings_on_options_save() {
        if (empty($_POST['option_page']) || empty($_POST['action']) || 'update' !== $_POST['action']) {
            return;
        }

        $group = sanitize_key(wp_unslash($_POST['option_page']));
        $rifnote_groups = array(
            'rifnote_search_settings',
            'rifnote_rss_warehouse_settings',
            'rifnote_home_notes_settings',
            'rifnote_home_featured_tab_settings',
            'rifnote_search_football_settings',
            'rifnote_customgpt_settings',
            'rifnote_social_settings',
            'rifnote_source_logo_settings',
        );

        if (!in_array($group, $rifnote_groups, true)) {
            return;
        }

        foreach (self::registered_options_for_group($group) as $option) {
            if (!$option || array_key_exists($option, $_POST)) {
                continue;
            }

            $current = get_option($option, self::settings_option_default($option));
            $_POST[$option] = wp_slash($current);
        }
    }

    private static function source_logo_rows($limit = 500) {
        $ids = get_posts(array(
            'post_type' => 'post',
            'post_status' => array('publish', 'draft', 'pending', 'future', 'private'),
            'posts_per_page' => max(1, min(1000, absint($limit))),
            'orderby' => 'modified',
            'order' => 'DESC',
            'fields' => 'ids',
            'no_found_rows' => true,
        ));
        $rows = array();
        $overrides = get_option('rifnote_source_logo_overrides', array());
        $cache = get_option('rifnote_source_logo_cache', array());

        foreach ($ids as $post_id) {
            $source_url = esc_url_raw((string) get_post_meta($post_id, 'source_url', true));
            $original_url = esc_url_raw((string) get_post_meta($post_id, 'original_url', true));
            $read_url = esc_url_raw((string) get_post_meta($post_id, 'read_full_story_url', true));
            $lookup_url = $source_url ? $source_url : Rifnote_Search_Source_Meta::source_home_from_url($read_url ? $read_url : $original_url);
            $domain = Rifnote_Search_Source_Meta::source_domain($lookup_url ? $lookup_url : ($read_url ? $read_url : $original_url));

            if (!$domain) {
                continue;
            }

            if (!isset($rows[$domain])) {
                $rows[$domain] = array(
                    'domain' => $domain,
                    'source_name' => '',
                    'source_url' => $lookup_url,
                    'logo_url' => '',
                    'logo_status' => __('Needs logo', 'rifnote-search'),
                    'posts' => 0,
                    'latest_post' => '',
                );
            }

            $rows[$domain]['posts']++;
            $source_name = Rifnote_Search_Source_Meta::normalize_text((string) get_post_meta($post_id, 'source_name', true));
            $manual_logo = esc_url_raw((string) get_post_meta($post_id, 'source_logo_url', true));

            if (!$rows[$domain]['source_name'] && $source_name) {
                $rows[$domain]['source_name'] = $source_name;
            }

            if (!$rows[$domain]['source_url'] && $lookup_url) {
                $rows[$domain]['source_url'] = $lookup_url;
            }

            if (!$rows[$domain]['logo_url'] && $manual_logo) {
                $rows[$domain]['logo_url'] = $manual_logo;
                $rows[$domain]['logo_status'] = __('Post-level', 'rifnote-search');
            }

            $modified = get_post_modified_time('U', true, $post_id);
            if ($modified && (!$rows[$domain]['latest_post'] || $modified > $rows[$domain]['latest_post'])) {
                $rows[$domain]['latest_post'] = $modified;
            }
        }

        foreach ($rows as $domain => &$row) {
            if (!$row['source_name']) {
                $row['source_name'] = $domain;
            }

            if (is_array($overrides) && !empty($overrides[$domain])) {
                $row['logo_url'] = esc_url_raw((string) $overrides[$domain]);
                $row['logo_status'] = __('Override', 'rifnote-search');
            } elseif (!$row['logo_url'] && is_array($cache) && !empty($cache[$domain]['logo_url'])) {
                $row['logo_url'] = esc_url_raw((string) $cache[$domain]['logo_url']);
                $row['logo_status'] = __('Cached', 'rifnote-search');
            }
        }
        unset($row);

        uasort($rows, function ($a, $b) {
            if ((int) $b['posts'] === (int) $a['posts']) {
                return strcmp($a['domain'], $b['domain']);
            }

            return (int) $b['posts'] <=> (int) $a['posts'];
        });

        return array_values($rows);
    }

    private static function maybe_handle_source_logo_action() {
        if (empty($_POST['rifnote_source_logo_action'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage source logos.', 'rifnote-search'));
        }

        check_admin_referer('rifnote_source_logo_action', 'rifnote_source_logo_nonce');
        $action = sanitize_key(wp_unslash($_POST['rifnote_source_logo_action']));
        $domain = isset($_POST['rifnote_source_domain']) ? Rifnote_Search_Source_Meta::source_domain('https://' . preg_replace('/^https?:\/\//', '', sanitize_text_field(wp_unslash($_POST['rifnote_source_domain'])))) : '';
        $overrides = get_option('rifnote_source_logo_overrides', array());
        $overrides = is_array($overrides) ? $overrides : array();
        $cache = get_option('rifnote_source_logo_cache', array());
        $cache = is_array($cache) ? $cache : array();

        if ('save_override' === $action && $domain) {
            $logo_url = isset($_POST['rifnote_source_logo_url']) ? esc_url_raw(wp_unslash($_POST['rifnote_source_logo_url'])) : '';
            if ($logo_url) {
                $overrides[$domain] = $logo_url;
            } else {
                unset($overrides[$domain]);
            }
            update_option('rifnote_source_logo_overrides', self::sanitize_source_logo_overrides($overrides), false);
            add_settings_error('rifnote_source_logos', 'saved', __('Source logo override saved.', 'rifnote-search'), 'updated');
        }

        if ('discover_one' === $action && $domain) {
            $source_url = isset($_POST['rifnote_source_url']) ? esc_url_raw(wp_unslash($_POST['rifnote_source_url'])) : ('https://' . $domain . '/');
            $logo_url = Rifnote_Search_Source_Meta::discover_source_logo($source_url);
            if (!$logo_url) {
                $logo_url = trailingslashit($source_url) . 'favicon.ico';
            }
            $cache[$domain] = array('logo_url' => esc_url_raw($logo_url), 'checked_at' => time());
            update_option('rifnote_source_logo_cache', $cache, false);
            add_settings_error('rifnote_source_logos', 'discovered', __('Source logo discovery refreshed.', 'rifnote-search'), 'updated');
        }

        if ('clear_one' === $action && $domain) {
            unset($overrides[$domain], $cache[$domain]);
            update_option('rifnote_source_logo_overrides', self::sanitize_source_logo_overrides($overrides), false);
            update_option('rifnote_source_logo_cache', $cache, false);
            add_settings_error('rifnote_source_logos', 'cleared', __('Source logo override and cache cleared.', 'rifnote-search'), 'updated');
        }
    }

    private static function render_source_logos_page() {
        $rows = self::source_logo_rows();
        $missing = count(array_filter($rows, function ($row) {
            return empty($row['logo_url']);
        }));
        $overrides = get_option('rifnote_source_logo_overrides', array());

        settings_errors('rifnote_source_logos');
        echo '<div class="card" style="max-width:1180px;"><h2>' . esc_html__('Source logo control', 'rifnote-search') . '</h2><p>' . esc_html__('Fix publisher icons once per domain. Overrides are used everywhere Rifnote shows a source: homepage pills, archives, search results, source pages and story pages.', 'rifnote-search') . '</p>';
        echo '<p><strong>' . esc_html(number_format_i18n(count($rows))) . '</strong> ' . esc_html__('sources scanned', 'rifnote-search') . ' · <strong>' . esc_html(number_format_i18n($missing)) . '</strong> ' . esc_html__('need attention', 'rifnote-search') . ' · <strong>' . esc_html(number_format_i18n(is_array($overrides) ? count($overrides) : 0)) . '</strong> ' . esc_html__('manual overrides', 'rifnote-search') . '</p></div>';

        echo '<div class="card" style="max-width:1180px;margin-top:16px;"><h2>' . esc_html__('Add or override any source', 'rifnote-search') . '</h2>';
        echo '<form method="post" style="display:grid;grid-template-columns:minmax(180px,260px) minmax(260px,1fr) auto auto;gap:10px;align-items:end;">';
        wp_nonce_field('rifnote_source_logo_action', 'rifnote_source_logo_nonce');
        echo '<input type="hidden" name="rifnote_source_logo_action" value="save_override" />';
        echo '<p style="margin:0;"><label><strong>' . esc_html__('Domain', 'rifnote-search') . '</strong><br><input class="regular-text" type="text" name="rifnote_source_domain" placeholder="punchng.com" /></label></p>';
        echo '<p class="rs-media-field" style="margin:0;"><label><strong>' . esc_html__('Logo URL', 'rifnote-search') . '</strong><br><input id="rifnote_source_logo_manual" class="regular-text rs-media-url" type="url" name="rifnote_source_logo_url" placeholder="https://..." /></label></p>';
        echo '<p style="margin:0;"><button type="button" class="button rs-media-picker" data-target="#rifnote_source_logo_manual" data-library="image" data-title="' . esc_attr__('Choose source logo', 'rifnote-search') . '" data-button="' . esc_attr__('Use logo', 'rifnote-search') . '">' . esc_html__('Media', 'rifnote-search') . '</button></p>';
        echo '<p style="margin:0;">' . get_submit_button(__('Save override', 'rifnote-search'), 'primary', 'submit', false) . '</p>';
        echo '</form></div>';

        echo '<table class="widefat striped" style="max-width:1180px;margin-top:16px;"><thead><tr>';
        echo '<th>' . esc_html__('Logo', 'rifnote-search') . '</th><th>' . esc_html__('Source', 'rifnote-search') . '</th><th>' . esc_html__('Posts', 'rifnote-search') . '</th><th>' . esc_html__('Status', 'rifnote-search') . '</th><th>' . esc_html__('Override / actions', 'rifnote-search') . '</th>';
        echo '</tr></thead><tbody>';

        if (!$rows) {
            echo '<tr><td colspan="5">' . esc_html__('No source metadata found yet.', 'rifnote-search') . '</td></tr>';
        }

        foreach ($rows as $index => $row) {
            $field_id = 'rifnote_source_logo_' . md5($row['domain']);
            echo '<tr>';
            echo '<td style="width:76px;">';
            if (!empty($row['logo_url'])) {
                echo '<img src="' . esc_url($row['logo_url']) . '" alt="" style="width:42px;height:42px;object-fit:contain;border:1px solid #d9e1ec;border-radius:10px;background:#fff;" />';
            } else {
                echo '<span style="display:inline-grid;place-items:center;width:42px;height:42px;border:1px solid #d9e1ec;border-radius:10px;background:#f5f7fb;font-weight:800;">' . esc_html(Rifnote_Search_Source_Meta::source_initials($row['source_name'], $row['domain'])) . '</span>';
            }
            echo '</td>';
            echo '<td><strong>' . esc_html($row['source_name']) . '</strong><br><code>' . esc_html($row['domain']) . '</code></td>';
            echo '<td>' . esc_html(number_format_i18n((int) $row['posts'])) . '</td>';
            echo '<td>' . esc_html($row['logo_status']) . '</td>';
            echo '<td>';
            echo '<form method="post" style="display:grid;grid-template-columns:minmax(220px,1fr) auto auto auto;gap:8px;align-items:center;">';
            wp_nonce_field('rifnote_source_logo_action', 'rifnote_source_logo_nonce');
            echo '<input type="hidden" name="rifnote_source_domain" value="' . esc_attr($row['domain']) . '" />';
            echo '<input type="hidden" name="rifnote_source_url" value="' . esc_attr($row['source_url'] ? $row['source_url'] : ('https://' . $row['domain'] . '/')) . '" />';
            echo '<input id="' . esc_attr($field_id) . '" class="regular-text rs-media-url" type="url" name="rifnote_source_logo_url" value="' . esc_attr($row['logo_url']) . '" placeholder="https://logo-url..." />';
            echo '<button type="button" class="button rs-media-picker" data-target="#' . esc_attr($field_id) . '" data-library="image" data-title="' . esc_attr__('Choose source logo', 'rifnote-search') . '" data-button="' . esc_attr__('Use logo', 'rifnote-search') . '">' . esc_html__('Media', 'rifnote-search') . '</button>';
            echo '<button class="button button-primary" type="submit" name="rifnote_source_logo_action" value="save_override">' . esc_html__('Save', 'rifnote-search') . '</button>';
            echo '<button class="button" type="submit" name="rifnote_source_logo_action" value="discover_one">' . esc_html__('Discover', 'rifnote-search') . '</button>';
            echo '<button class="button" type="submit" name="rifnote_source_logo_action" value="clear_one" style="grid-column:2 / span 3;">' . esc_html__('Clear override/cache', 'rifnote-search') . '</button>';
            echo '</form>';
            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }

    public static function football_option_names() {
        return array(
            'rifnote_api_football_key',
            'rifnote_api_football_host',
            'rifnote_api_football_timezone',
            'rifnote_api_football_live_cache_ttl',
            'rifnote_api_football_fixture_cache_ttl',
            'rifnote_api_football_upcoming_cache_ttl',
            'rifnote_api_football_finished_cache_ttl',
            'rifnote_api_football_details_cache_ttl',
            'rifnote_api_football_competitions',
            'rifnote_api_football_team_watchlist',
            'rifnote_home_featured_football_matches',
        );
    }

    public static function maybe_clear_football_cache_on_update($option, $old_value, $value) {
        if ($old_value === $value || !in_array($option, self::football_option_names(), true)) {
            return;
        }

        Rifnote_Search_Football_API::clear_cache();

        if ('rifnote_api_football_live_cache_ttl' === $option || 'rifnote_api_football_key' === $option || 'rifnote_api_football_competitions' === $option || 'rifnote_api_football_team_watchlist' === $option) {
            Rifnote_Search_Football_API::clear_schedule();
            Rifnote_Search_Football_API::schedule();
        }
    }

    public static function maybe_save_trending() {
        if (!isset($_POST['rifnote_trending_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_trending_nonce'])), 'rifnote_save_trending')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $topic = isset($_POST['rifnote_trending_topic']) ? sanitize_text_field(wp_unslash($_POST['rifnote_trending_topic'])) : '';

        if (!$topic) {
            return;
        }

        Rifnote_Search_Trending::upsert_topic($topic, array(
            'score' => isset($_POST['rifnote_trending_score']) ? (float) wp_unslash($_POST['rifnote_trending_score']) : 0,
            'manual_boost' => isset($_POST['rifnote_trending_boost']) ? (float) wp_unslash($_POST['rifnote_trending_boost']) : 0,
            'is_pinned' => !empty($_POST['rifnote_trending_pinned']),
            'is_hidden' => !empty($_POST['rifnote_trending_hidden']),
            'expires_at' => isset($_POST['rifnote_trending_expires']) ? sanitize_text_field(wp_unslash($_POST['rifnote_trending_expires'])) : '',
        ));

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Trending topic saved.', 'rifnote-search') . '</p></div>';
    }

    public static function maybe_handle_submission_action() {
        if (!isset($_POST['rifnote_submission_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_submission_nonce'])), 'rifnote_submission_action')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $submission_id = isset($_POST['rifnote_submission_id']) ? absint($_POST['rifnote_submission_id']) : 0;
        $action = isset($_POST['rifnote_submission_action']) ? sanitize_key(wp_unslash($_POST['rifnote_submission_action'])) : '';

        if (!$submission_id) {
            return;
        }

        if ('approve' === $action) {
            $result = Rifnote_Search_Publishers::approve_submission($submission_id);

            if (is_wp_error($result)) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Submission approved and converted to a draft post.', 'rifnote-search') . '</p></div>';
            }
        }

        if ('reject' === $action) {
            $reason = isset($_POST['rifnote_rejection_reason']) ? sanitize_textarea_field(wp_unslash($_POST['rifnote_rejection_reason'])) : '';
            Rifnote_Search_Publishers::reject_submission($submission_id, $reason);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Submission rejected.', 'rifnote-search') . '</p></div>';
        }
    }

    public static function maybe_handle_publisher_action() {
        if (!isset($_POST['rifnote_publisher_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_publisher_nonce'])), 'rifnote_publisher_action')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $publisher_id = isset($_POST['rifnote_publisher_id']) ? absint($_POST['rifnote_publisher_id']) : 0;
        $action = isset($_POST['rifnote_publisher_action']) ? sanitize_key(wp_unslash($_POST['rifnote_publisher_action'])) : '';

        if (!$publisher_id) {
            return;
        }

        if ('approve' === $action) {
            Rifnote_Search_Publishers::update_publisher_status($publisher_id, 'approved', !empty($_POST['rifnote_auto_approve']));
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Publisher approved for RSS ingestion.', 'rifnote-search') . '</p></div>';
        }

        if ('trust' === $action) {
            Rifnote_Search_Publishers::update_publisher_status($publisher_id, 'approved', true);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Publisher marked as trusted. New feed items can auto-publish.', 'rifnote-search') . '</p></div>';
        }

        if ('review' === $action) {
            Rifnote_Search_Publishers::update_publisher_status($publisher_id, 'approved', false);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Publisher remains approved, but feed items will go to review.', 'rifnote-search') . '</p></div>';
        }

        if ('suspend' === $action) {
            Rifnote_Search_Publishers::update_publisher_status($publisher_id, 'suspended', false);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Publisher suspended from ingestion.', 'rifnote-search') . '</p></div>';
        }

        if ('verify' === $action) {
            global $wpdb;
            $wpdb->update(Rifnote_Search_Publishers::publishers_table(), array(
                'verification_status' => 'verified',
                'verified_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ), array('id' => (int) $publisher_id));
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Publisher manually verified.', 'rifnote-search') . '</p></div>';
        }

        if ('rotate_api_key' === $action) {
            $key = Rifnote_Search_Launch_Readiness::rotate_publisher_api_key($publisher_id);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('Publisher API key rotated. New key: %s', 'rifnote-search'), $key)) . '</p></div>';
        }
    }

    public static function maybe_handle_legal_action() {
        if (!isset($_POST['rifnote_legal_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_legal_nonce'])), 'rifnote_legal_action')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $action = isset($_POST['rifnote_legal_action']) ? sanitize_key(wp_unslash($_POST['rifnote_legal_action'])) : '';

        if ('block_domain' === $action) {
            $domain = isset($_POST['rifnote_blocked_domain']) ? sanitize_text_field(wp_unslash($_POST['rifnote_blocked_domain'])) : '';
            $reason = isset($_POST['rifnote_blocked_reason']) ? sanitize_textarea_field(wp_unslash($_POST['rifnote_blocked_reason'])) : '';
            $result = Rifnote_Search_Legal::block_domain($domain, $reason);

            if (is_wp_error($result)) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Domain added to the blocked list.', 'rifnote-search') . '</p></div>';
            }
        }

        if ('blocked_status' === $action) {
            $domain_id = isset($_POST['rifnote_blocked_domain_id']) ? absint($_POST['rifnote_blocked_domain_id']) : 0;
            $status = isset($_POST['rifnote_blocked_status']) ? sanitize_key(wp_unslash($_POST['rifnote_blocked_status'])) : 'inactive';

            if ($domain_id && Rifnote_Search_Legal::set_blocked_domain_status($domain_id, $status)) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Blocked domain status updated.', 'rifnote-search') . '</p></div>';
            }
        }

        if ('request_status' === $action) {
            $request_id = isset($_POST['rifnote_legal_request_id']) ? absint($_POST['rifnote_legal_request_id']) : 0;
            $status = isset($_POST['rifnote_legal_status']) ? sanitize_key(wp_unslash($_POST['rifnote_legal_status'])) : 'reviewing';
            $notes = isset($_POST['rifnote_legal_notes']) ? sanitize_textarea_field(wp_unslash($_POST['rifnote_legal_notes'])) : '';

            if ($request_id && Rifnote_Search_Legal::update_request_status($request_id, $status, $notes)) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Legal request status updated.', 'rifnote-search') . '</p></div>';
            }
        }
    }

    public static function maybe_run_ingestion() {
        if (isset($_GET['rifnote_rss_repair'], $_GET['rifnote_rss_repair_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['rifnote_rss_repair_nonce'])), 'rifnote_repair_rss_schedule')) {
            if (!current_user_can('manage_options')) {
                return;
            }

            $repaired = Rifnote_Search_Ingestion::repair_schedule(true);
            echo '<div class="notice notice-' . esc_attr($repaired ? 'success' : 'info') . ' is-dismissible"><p>' . esc_html($repaired ? __('Smart RSS schedule repaired. The next run is queued for the next minute.', 'rifnote-search') : __('Smart RSS schedule is already queued in the future.', 'rifnote-search')) . '</p></div>';
            return;
        }

        if (!isset($_POST['rifnote_ingestion_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_ingestion_nonce'])), 'rifnote_run_ingestion')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $summary = Rifnote_Search_Ingestion::run_once();

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
            /* translators: 1: feed count, 2: created count, 3: published count, 4: duplicate count */
            __('RSS ingestion checked %1$d feeds, created %2$d submissions, published %3$d items, skipped %4$d duplicates.', 'rifnote-search'),
            (int) $summary['checked'],
            (int) $summary['created'],
            (int) $summary['published'],
            (int) $summary['duplicates']
        )) . '</p></div>';
    }

    public static function maybe_run_thenewsapi_import() {
        if (!isset($_POST['rifnote_thenewsapi_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_thenewsapi_nonce'])), 'rifnote_run_thenewsapi')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $summary = Rifnote_Search_News_API::import_top_stories(true);

        if (empty($summary['configured']) || !empty($summary['errors'])) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html($summary['message'] ? $summary['message'] : __('TheNewsAPI import could not complete.', 'rifnote-search')) . '</p></div>';
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
            __('TheNewsAPI import complete. Checked %1$d articles, created %2$d stories, skipped %3$d duplicates.', 'rifnote-search'),
            (int) $summary['checked'],
            (int) $summary['created'],
            (int) $summary['duplicates']
        )) . '</p></div>';
    }

    public static function maybe_handle_customgpt_action() {
        if (!isset($_POST['rifnote_customgpt_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_customgpt_nonce'])), 'rifnote_customgpt_action')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $action = isset($_POST['rifnote_customgpt_action']) ? sanitize_key(wp_unslash($_POST['rifnote_customgpt_action'])) : '';

        if ('generate_key' === $action) {
            $key = Rifnote_Search_CustomGPT_Import::generate_api_key();
            Rifnote_Search_CustomGPT_Import::log_event(array(
                'event' => 'api_key_generated',
                'status' => 'ok',
                'message' => __('CustomGPT import API key generated by an admin.', 'rifnote-search'),
            ));
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('CustomGPT API key generated. Copy it now; it will only be shown once.', 'rifnote-search') . '</p><p><code style="font-size:13px;">' . esc_html($key) . '</code></p></div>';
        }

        if ('clear_plain_key_notice' === $action) {
            delete_option('rifnote_customgpt_import_last_plain_key_notice');
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Temporary API key notice cleared.', 'rifnote-search') . '</p></div>';
        }

        if ('normalize_existing_text' === $action) {
            $limit = isset($_POST['rifnote_normalize_limit']) ? absint($_POST['rifnote_normalize_limit']) : 200;
            $summary = Rifnote_Search_Source_Meta::normalize_existing_posts($limit);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                __('Text normalization complete. Checked %1$d posts, updated %2$d posts and %3$d metadata fields.', 'rifnote-search'),
                (int) $summary['checked'],
                (int) $summary['updated'],
                (int) $summary['meta_updated']
            )) . '</p></div>';
        }
    }

    public static function maybe_handle_social_action() {
        if (!isset($_POST['rifnote_social_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_social_nonce'])), 'rifnote_social_action')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $action = isset($_POST['rifnote_social_action']) ? sanitize_key(wp_unslash($_POST['rifnote_social_action'])) : '';

        if ('run_youtube' === $action) {
            $summary = Rifnote_Search_Social::run_youtube_import(true);
            $class = !empty($summary['ok']) ? 'notice-success' : 'notice-warning';
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html(sprintf(
                __('YouTube import checked %1$d videos, created %2$d stories, skipped %3$d duplicates, with %4$d errors.', 'rifnote-search'),
                (int) ($summary['checked'] ?? 0),
                (int) ($summary['created'] ?? 0),
                (int) ($summary['duplicates'] ?? 0),
                (int) ($summary['errors'] ?? 0)
            )) . '</p></div>';
        }

        if ('import_url' === $action) {
            $result = Rifnote_Search_Social::import_manual_url(array(
                'url' => isset($_POST['rifnote_social_url']) ? esc_url_raw(wp_unslash($_POST['rifnote_social_url'])) : '',
                'title' => isset($_POST['rifnote_social_title']) ? sanitize_text_field(wp_unslash($_POST['rifnote_social_title'])) : '',
                'excerpt' => isset($_POST['rifnote_social_excerpt']) ? sanitize_textarea_field(wp_unslash($_POST['rifnote_social_excerpt'])) : '',
                'category' => isset($_POST['rifnote_social_category']) ? sanitize_text_field(wp_unslash($_POST['rifnote_social_category'])) : 'Social',
                'platform' => isset($_POST['rifnote_social_platform']) ? sanitize_key(wp_unslash($_POST['rifnote_social_platform'])) : '',
                'mode' => isset($_POST['rifnote_social_mode']) ? sanitize_key(wp_unslash($_POST['rifnote_social_mode'])) : 'draft',
            ));

            if (is_wp_error($result)) {
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html($result->get_error_message()) . '</p></div>';
                return;
            }

            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('Social story imported: %s', 'rifnote-search'), $result['title'])) . '</p></div>';
        }
    }

    public static function maybe_handle_aggregation_action() {
        if (!isset($_POST['rifnote_aggregation_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_aggregation_nonce'])), 'rifnote_aggregation_action')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $action = isset($_POST['rifnote_aggregation_action']) ? sanitize_key(wp_unslash($_POST['rifnote_aggregation_action'])) : '';

        if ('seed_categories' === $action) {
            $summary = Rifnote_Search_Aggregation::ensure_categories(true);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                __('Categories seeded. Created %1$d, refreshed %2$d existing categories.', 'rifnote-search'),
                (int) $summary['created'],
                (int) $summary['existing']
            )) . '</p></div>';
            return;
        }

        if ('save_cluster' === $action) {
            $post_ids = array();

            if (!empty($_POST['rifnote_aggregation_post_ids']) && is_array($_POST['rifnote_aggregation_post_ids'])) {
                $post_ids = array_map('absint', wp_unslash($_POST['rifnote_aggregation_post_ids']));
            }

            if (!empty($_POST['rifnote_aggregation_post_ids_manual'])) {
                $post_ids = array_merge($post_ids, Rifnote_Search_Aggregation::parse_post_ids(wp_unslash($_POST['rifnote_aggregation_post_ids_manual'])));
            }

            $record = Rifnote_Search_Aggregation::save(array(
                'cluster_id' => isset($_POST['rifnote_aggregation_cluster_id']) ? sanitize_text_field(wp_unslash($_POST['rifnote_aggregation_cluster_id'])) : '',
                'title' => isset($_POST['rifnote_aggregation_title']) ? sanitize_text_field(wp_unslash($_POST['rifnote_aggregation_title'])) : '',
                'summary' => isset($_POST['rifnote_aggregation_summary']) ? sanitize_textarea_field(wp_unslash($_POST['rifnote_aggregation_summary'])) : '',
                'category' => isset($_POST['rifnote_aggregation_category']) ? sanitize_text_field(wp_unslash($_POST['rifnote_aggregation_category'])) : '',
                'image_url' => isset($_POST['rifnote_aggregation_image_url']) ? esc_url_raw(wp_unslash($_POST['rifnote_aggregation_image_url'])) : '',
                'status' => isset($_POST['rifnote_aggregation_status']) ? sanitize_key(wp_unslash($_POST['rifnote_aggregation_status'])) : 'draft',
                'editorial_notes' => isset($_POST['rifnote_aggregation_notes']) ? sanitize_textarea_field(wp_unslash($_POST['rifnote_aggregation_notes'])) : '',
                'post_ids' => $post_ids,
            ));

            if (is_wp_error($record)) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($record->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                    __('Aggregation saved. Cluster %1$s now controls %2$d story item(s).', 'rifnote-search'),
                    $record['cluster_id'],
                    count($record['post_ids'])
                )) . '</p></div>';
            }
        }

        if ('delete_cluster' === $action) {
            $cluster_id = isset($_POST['rifnote_aggregation_delete_cluster_id']) ? sanitize_text_field(wp_unslash($_POST['rifnote_aggregation_delete_cluster_id'])) : '';

            if ($cluster_id && Rifnote_Search_Aggregation::delete($cluster_id)) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Manual aggregation record deleted. Story cluster metadata on posts was left untouched for safety.', 'rifnote-search') . '</p></div>';
            }
        }
    }

    public static function maybe_handle_hardening_action() {
        if (!isset($_POST['rifnote_hardening_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_hardening_nonce'])), 'rifnote_hardening_action')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $action = isset($_POST['rifnote_hardening_action']) ? sanitize_key(wp_unslash($_POST['rifnote_hardening_action'])) : '';

        if ('save_backup_plan' === $action) {
            $plan = isset($_POST['rifnote_backup_plan']) ? sanitize_textarea_field(wp_unslash($_POST['rifnote_backup_plan'])) : '';
            update_option('rifnote_search_backup_plan', $plan, false);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Launch backup plan saved.', 'rifnote-search') . '</p></div>';
        }

        if ('clear_error_logs' === $action) {
            Rifnote_Search_Hardening::clear_errors();
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Rifnote Search error logs cleared.', 'rifnote-search') . '</p></div>';
        }
    }

    public static function maybe_handle_retention_action() {
        if (!isset($_POST['rifnote_retention_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_retention_nonce'])), 'rifnote_retention_action')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $action = isset($_POST['rifnote_retention_action']) ? sanitize_key(wp_unslash($_POST['rifnote_retention_action'])) : '';

        if ('process_alerts' === $action) {
            $summary = Rifnote_Search_Retention::process_alerts(200);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                /* translators: 1: checked count, 2: matched count, 3: queued count */
                __('Alerts processed. Checked %1$d, matched %2$d, queued %3$d notifications.', 'rifnote-search'),
                (int) $summary['checked'],
                (int) $summary['matched'],
                (int) $summary['queued']
            )) . '</p></div>';
        }

        if ('send_newsletter_digest' === $action) {
            $summary = Rifnote_Search_Retention::send_newsletter_digest(200);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                /* translators: 1: subscriber count, 2: sent count, 3: failed count */
                __('Newsletter digest processed. Subscribers %1$d, sent %2$d, failed %3$d.', 'rifnote-search'),
                (int) $summary['subscribers'],
                (int) $summary['sent'],
                (int) $summary['failed']
            )) . '</p></div>';
        }

        if ('process_push' === $action) {
            $summary = Rifnote_Search_Delivery::process_push_notifications(200);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                /* translators: 1: checked count, 2: sent count, 3: failed count */
                __('Push queue processed. Checked %1$d, sent %2$d, failed %3$d.', 'rifnote-search'),
                (int) $summary['checked'],
                (int) $summary['sent'],
                (int) $summary['failed']
            )) . '</p></div>';
        }
    }

    public static function maybe_handle_beta_action() {
        if (!isset($_POST['rifnote_beta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_beta_nonce'])), 'rifnote_beta_action')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $action = isset($_POST['rifnote_beta_action']) ? sanitize_key(wp_unslash($_POST['rifnote_beta_action'])) : '';

        if ('feedback_status' === $action) {
            $feedback_id = isset($_POST['rifnote_feedback_id']) ? absint($_POST['rifnote_feedback_id']) : 0;
            $status = isset($_POST['rifnote_feedback_status']) ? sanitize_key(wp_unslash($_POST['rifnote_feedback_status'])) : 'reviewing';

            if ($feedback_id && Rifnote_Search_Beta::update_feedback_status($feedback_id, $status)) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Beta feedback updated.', 'rifnote-search') . '</p></div>';
            }
        }

        if ('add_ranking_rule' === $action) {
            $result = Rifnote_Search_Beta::add_ranking_rule(array(
                'rule_type' => isset($_POST['rifnote_rule_type']) ? sanitize_key(wp_unslash($_POST['rifnote_rule_type'])) : '',
                'target' => isset($_POST['rifnote_rule_target']) ? sanitize_text_field(wp_unslash($_POST['rifnote_rule_target'])) : '',
                'boost' => isset($_POST['rifnote_rule_boost']) ? (float) wp_unslash($_POST['rifnote_rule_boost']) : 0,
                'notes' => isset($_POST['rifnote_rule_notes']) ? sanitize_textarea_field(wp_unslash($_POST['rifnote_rule_notes'])) : '',
            ));

            if (is_wp_error($result)) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Ranking rule added.', 'rifnote-search') . '</p></div>';
            }
        }

        if ('ranking_rule_status' === $action) {
            $rule_id = isset($_POST['rifnote_rule_id']) ? absint($_POST['rifnote_rule_id']) : 0;
            $status = isset($_POST['rifnote_rule_status']) ? sanitize_key(wp_unslash($_POST['rifnote_rule_status'])) : 'inactive';

            if ($rule_id && Rifnote_Search_Beta::set_ranking_rule_status($rule_id, $status)) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Ranking rule status updated.', 'rifnote-search') . '</p></div>';
            }
        }

        if ('add_publisher_target' === $action) {
            $result = Rifnote_Search_Beta::add_publisher_target(array(
                'publisher_name' => isset($_POST['rifnote_beta_publisher_name']) ? sanitize_text_field(wp_unslash($_POST['rifnote_beta_publisher_name'])) : '',
                'website_url' => isset($_POST['rifnote_beta_website_url']) ? esc_url_raw(wp_unslash($_POST['rifnote_beta_website_url'])) : '',
                'contact_email' => isset($_POST['rifnote_beta_contact_email']) ? sanitize_email(wp_unslash($_POST['rifnote_beta_contact_email'])) : '',
                'status' => isset($_POST['rifnote_beta_publisher_status']) ? sanitize_key(wp_unslash($_POST['rifnote_beta_publisher_status'])) : 'invited',
                'notes' => isset($_POST['rifnote_beta_notes']) ? sanitize_textarea_field(wp_unslash($_POST['rifnote_beta_notes'])) : '',
            ));

            if (is_wp_error($result)) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Publisher added to beta onboarding.', 'rifnote-search') . '</p></div>';
            }
        }

        if ('publisher_target_status' === $action) {
            $target_id = isset($_POST['rifnote_beta_target_id']) ? absint($_POST['rifnote_beta_target_id']) : 0;
            $status = isset($_POST['rifnote_beta_target_status']) ? sanitize_key(wp_unslash($_POST['rifnote_beta_target_status'])) : 'onboarding';

            if ($target_id && Rifnote_Search_Beta::update_publisher_target_status($target_id, $status)) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Publisher onboarding status updated.', 'rifnote-search') . '</p></div>';
            }
        }
    }

    public static function maybe_handle_index_action() {
        if (!isset($_POST['rifnote_index_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_index_nonce'])), 'rifnote_index_action')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $indexed = Rifnote_Search_Index::reindex_all();
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('Search index rebuilt. %d posts indexed.', 'rifnote-search'), (int) $indexed)) . '</p></div>';
    }

    public static function maybe_handle_platform_insights_action() {
        if (!isset($_POST['rifnote_insights_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_insights_nonce'])), 'rifnote_insights_action')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $action = isset($_POST['rifnote_insights_action']) ? sanitize_key(wp_unslash($_POST['rifnote_insights_action'])) : '';

        if ('convert_no_result' === $action) {
            $id = isset($_POST['rifnote_no_result_id']) ? absint($_POST['rifnote_no_result_id']) : 0;
            $topic = isset($_POST['rifnote_no_result_topic']) ? sanitize_text_field(wp_unslash($_POST['rifnote_no_result_topic'])) : '';

            if ($id && Rifnote_Search_Platform_Insights::convert_no_result($id, $topic)) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('No-result query converted to tracked topic.', 'rifnote-search') . '</p></div>';
            }
        }

        if ('add_timeline_note' === $action) {
            $result = Rifnote_Search_Platform_Insights::add_timeline_note(array(
                'cluster_id' => isset($_POST['rifnote_timeline_cluster_id']) ? sanitize_text_field(wp_unslash($_POST['rifnote_timeline_cluster_id'])) : '',
                'label' => isset($_POST['rifnote_timeline_label']) ? sanitize_textarea_field(wp_unslash($_POST['rifnote_timeline_label'])) : '',
                'note_time' => isset($_POST['rifnote_timeline_time']) ? sanitize_text_field(wp_unslash($_POST['rifnote_timeline_time'])) : '',
                'source_name' => isset($_POST['rifnote_timeline_source']) ? sanitize_text_field(wp_unslash($_POST['rifnote_timeline_source'])) : '',
                'source_url' => isset($_POST['rifnote_timeline_url']) ? esc_url_raw(wp_unslash($_POST['rifnote_timeline_url'])) : '',
            ));

            if (is_wp_error($result)) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Timeline note added.', 'rifnote-search') . '</p></div>';
            }
        }
    }

    public static function maybe_handle_operations_action() {
        if (!isset($_POST['rifnote_ops_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_ops_nonce'])), 'rifnote_ops_action')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $action = isset($_POST['rifnote_ops_action']) ? sanitize_key(wp_unslash($_POST['rifnote_ops_action'])) : '';

        if ('run_ingestion' === $action) {
            $summary = Rifnote_Search_Ingestion::run_once();
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('Ingestion run complete. Checked %d feeds, created %d items.', 'rifnote-search'), (int) $summary['checked'], (int) $summary['created'])) . '</p></div>';
        }

        if ('simulate_ranking' === $action) {
            update_option('rifnote_search_last_ranking_simulation_query', isset($_POST['rifnote_sim_query']) ? sanitize_text_field(wp_unslash($_POST['rifnote_sim_query'])) : '', false);
        }

        if ('preview_imported_cleanup' === $action) {
            $days = isset($_POST['rifnote_cleanup_days']) ? absint($_POST['rifnote_cleanup_days']) : 30;
            $limit = isset($_POST['rifnote_cleanup_limit']) ? absint($_POST['rifnote_cleanup_limit']) : 500;
            $include_customgpt = !empty($_POST['rifnote_cleanup_include_customgpt']);
            $preview = self::cleanup_imported_posts_preview(array(
                'days' => $days,
                'limit' => $limit,
                'include_customgpt' => $include_customgpt,
            ));

            set_transient('rifnote_cleanup_last_preview', array(
                'preview' => $preview,
                'days' => $days,
                'limit' => $limit,
                'include_customgpt' => $include_customgpt,
                'created_at' => current_time('mysql'),
            ), 15 * MINUTE_IN_SECONDS);

            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html(sprintf(__('Cleanup preview found %d imported post candidate(s). Nothing was deleted.', 'rifnote-search'), (int) $preview['count'])) . '</p></div>';
        }

        if ('cleanup_imported_posts' === $action) {
            $days = isset($_POST['rifnote_cleanup_days']) ? absint($_POST['rifnote_cleanup_days']) : 30;
            $limit = isset($_POST['rifnote_cleanup_limit']) ? absint($_POST['rifnote_cleanup_limit']) : 500;
            $include_customgpt = !empty($_POST['rifnote_cleanup_include_customgpt']);
            $hard_delete = !empty($_POST['rifnote_cleanup_hard_delete']);

            $result = self::cleanup_imported_posts(array(
                'days' => $days,
                'limit' => $limit,
                'include_customgpt' => $include_customgpt,
                'hard_delete' => $hard_delete,
            ));

            if (is_wp_error($result)) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                    $hard_delete
                        ? __('Deleted %d imported post(s). Manual Rifnote Admin posts were protected.', 'rifnote-search')
                        : __('Moved %d imported post(s) to trash. Manual Rifnote Admin posts were protected.', 'rifnote-search'),
                    (int) $result['affected']
                )) . '</p></div>';
            }
        }
    }

    private static function cleanup_import_markers() {
        return array(
            'channels' => array('rss', 'news_api', 'customgpt', 'customgpt_social', 'customgpt_aggregation', 'publisher', 'manual_social', 'social'),
            'models' => array('GPT', 'TheNewsAPI', 'RSS Feed', 'Publisher Submission', 'Social Import', 'Manual Social', 'CustomGPT'),
            'source_types' => array('rss', 'submitted', 'social', 'video'),
            'meta_keys' => array(
                'thenewsapi_uuid',
                'rifnote_customgpt_source',
                'rifnote_customgpt_last_format_source',
                'rifnote_social_platform',
                'rifnote_social_post_url',
                'rifnote_social_embed_html',
                'publisher_id',
            ),
        );
    }

    private static function is_manual_admin_post($post_id) {
        $model = (string) get_post_meta($post_id, 'rifnote_origin_model', true);
        $channel = (string) get_post_meta($post_id, 'rifnote_origin_channel', true);

        return 'admin' === $channel || 'Rifnote Admin' === $model;
    }

    private static function is_customgpt_post($post_id) {
        $model = (string) get_post_meta($post_id, 'rifnote_origin_model', true);
        $channel = (string) get_post_meta($post_id, 'rifnote_origin_channel', true);

        return 'GPT' === $model
            || false !== strpos($channel, 'customgpt')
            || (bool) get_post_meta($post_id, 'rifnote_customgpt_source', true)
            || (bool) get_post_meta($post_id, 'rifnote_customgpt_last_format_source', true);
    }

    private static function cleanup_import_reason($post_id) {
        $markers = self::cleanup_import_markers();
        $channel = (string) get_post_meta($post_id, 'rifnote_origin_channel', true);
        $model = (string) get_post_meta($post_id, 'rifnote_origin_model', true);
        $source_type = (string) get_post_meta($post_id, 'source_type', true);

        if ($channel && in_array($channel, $markers['channels'], true)) {
            return 'channel:' . $channel;
        }

        if ($model && in_array($model, $markers['models'], true)) {
            return 'model:' . $model;
        }

        if ($source_type && in_array($source_type, $markers['source_types'], true)) {
            return 'source_type:' . $source_type;
        }

        foreach ($markers['meta_keys'] as $meta_key) {
            if (get_post_meta($post_id, $meta_key, true)) {
                return 'meta:' . $meta_key;
            }
        }

        return '';
    }

    private static function cleanup_candidate_ids($args = array()) {
        global $wpdb;

        $defaults = array(
            'days' => 30,
            'limit' => 500,
            'include_customgpt' => true,
        );
        $args = wp_parse_args($args, $defaults);
        $limit = max(1, min(5000, absint($args['limit'])));
        $days = absint($args['days']);
        $markers = self::cleanup_import_markers();
        $statuses = array('publish', 'draft', 'pending', 'future', 'private');
        $params = array();
        $marker_sql = array();
        $placeholder_list = static function ($items) {
            return implode(', ', array_fill(0, count($items), '%s'));
        };

        if (!empty($markers['channels'])) {
            $marker_sql[] = "(pm.meta_key = 'rifnote_origin_channel' AND pm.meta_value IN (" . $placeholder_list($markers['channels']) . '))';
            $params = array_merge($params, $markers['channels']);
        }

        if (!empty($markers['models'])) {
            $marker_sql[] = "(pm.meta_key = 'rifnote_origin_model' AND pm.meta_value IN (" . $placeholder_list($markers['models']) . '))';
            $params = array_merge($params, $markers['models']);
        }

        if (!empty($markers['source_types'])) {
            $marker_sql[] = "(pm.meta_key = 'source_type' AND pm.meta_value IN (" . $placeholder_list($markers['source_types']) . '))';
            $params = array_merge($params, $markers['source_types']);
        }

        if (!empty($markers['meta_keys'])) {
            $marker_sql[] = 'pm.meta_key IN (' . $placeholder_list($markers['meta_keys']) . ')';
            $params = array_merge($params, $markers['meta_keys']);
        }

        if (!$marker_sql) {
            return array();
        }

        $fetch_limit = min(3000, max($limit * 2, $limit));
        $status_placeholders = $placeholder_list($statuses);
        $where = "p.post_type = 'post' AND p.post_status IN ({$status_placeholders})";
        $where_params = $statuses;

        if ($days > 0) {
            $where .= ' AND p.post_date_gmt <= %s';
            $where_params[] = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        }

        $sql = "
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE {$where}
              AND (" . implode(' OR ', $marker_sql) . ")
            ORDER BY p.post_date ASC
            LIMIT {$fetch_limit}
        ";

        $candidate_ids = $wpdb->get_col($wpdb->prepare($sql, array_merge($where_params, $params)));
        $filtered = array();

        foreach ($candidate_ids as $post_id) {
            $post_id = absint($post_id);

            if (!$post_id || self::is_manual_admin_post($post_id)) {
                continue;
            }

            if (empty($args['include_customgpt']) && self::is_customgpt_post($post_id)) {
                continue;
            }

            if (!self::cleanup_import_reason($post_id)) {
                continue;
            }

            $filtered[] = $post_id;

            if (count($filtered) >= $limit) {
                break;
            }
        }

        return $filtered;
    }

    private static function cleanup_imported_posts_preview($args = array()) {
        $ids = self::cleanup_candidate_ids($args);
        $breakdown = array();
        $samples = array();

        foreach ($ids as $post_id) {
            $reason = self::cleanup_import_reason($post_id);
            $breakdown[$reason] = isset($breakdown[$reason]) ? $breakdown[$reason] + 1 : 1;

            if (count($samples) < 8) {
                $samples[] = array(
                    'id' => $post_id,
                    'title' => get_the_title($post_id),
                    'date' => get_the_date('M j, Y', $post_id),
                    'reason' => $reason,
                    'edit_url' => get_edit_post_link($post_id, ''),
                );
            }
        }

        arsort($breakdown);

        return array(
            'count' => count($ids),
            'breakdown' => $breakdown,
            'samples' => $samples,
        );
    }

    private static function cleanup_imported_posts($args = array()) {
        if (!current_user_can('delete_posts')) {
            return new WP_Error('rifnote_cleanup_forbidden', __('You do not have permission to delete posts.', 'rifnote-search'));
        }

        $ids = self::cleanup_candidate_ids($args);
        $affected = 0;
        $hard_delete = !empty($args['hard_delete']);

        foreach ($ids as $post_id) {
            $result = $hard_delete ? wp_delete_post($post_id, true) : wp_trash_post($post_id);

            if ($result) {
                $affected++;

                if (class_exists('Rifnote_Search_Index')) {
                    Rifnote_Search_Index::delete_post($post_id);
                }
            }
        }

        return array(
            'affected' => $affected,
            'requested' => count($ids),
        );
    }

    public static function maybe_handle_launch_action() {
        if (!isset($_POST['rifnote_launch_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_launch_nonce'])), 'rifnote_launch_action')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $action = isset($_POST['rifnote_launch_action']) ? sanitize_key(wp_unslash($_POST['rifnote_launch_action'])) : '';

        if ('add_claim' === $action) {
            $result = Rifnote_Search_Launch_Readiness::add_claim(array(
                'post_id' => isset($_POST['rifnote_claim_post_id']) ? absint($_POST['rifnote_claim_post_id']) : 0,
                'cluster_id' => isset($_POST['rifnote_claim_cluster_id']) ? sanitize_text_field(wp_unslash($_POST['rifnote_claim_cluster_id'])) : '',
                'claim_text' => isset($_POST['rifnote_claim_text']) ? sanitize_textarea_field(wp_unslash($_POST['rifnote_claim_text'])) : '',
                'claimant' => isset($_POST['rifnote_claimant']) ? sanitize_text_field(wp_unslash($_POST['rifnote_claimant'])) : '',
                'rating' => isset($_POST['rifnote_claim_rating']) ? sanitize_text_field(wp_unslash($_POST['rifnote_claim_rating'])) : '',
                'review_summary' => isset($_POST['rifnote_review_summary']) ? sanitize_textarea_field(wp_unslash($_POST['rifnote_review_summary'])) : '',
                'review_url' => isset($_POST['rifnote_review_url']) ? esc_url_raw(wp_unslash($_POST['rifnote_review_url'])) : '',
            ));

            echo is_wp_error($result)
                ? '<div class="notice notice-error is-dismissible"><p>' . esc_html($result->get_error_message()) . '</p></div>'
                : '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Claim metadata saved.', 'rifnote-search') . '</p></div>';
        }

        if ('add_sponsored' === $action) {
            $result = Rifnote_Search_Launch_Readiness::add_sponsored(array(
                'title' => isset($_POST['rifnote_sponsored_title']) ? sanitize_text_field(wp_unslash($_POST['rifnote_sponsored_title'])) : '',
                'target_url' => isset($_POST['rifnote_sponsored_url']) ? esc_url_raw(wp_unslash($_POST['rifnote_sponsored_url'])) : '',
                'sponsor_name' => isset($_POST['rifnote_sponsor_name']) ? sanitize_text_field(wp_unslash($_POST['rifnote_sponsor_name'])) : '',
                'category' => isset($_POST['rifnote_sponsored_category']) ? sanitize_title(wp_unslash($_POST['rifnote_sponsored_category'])) : '',
                'query_match' => isset($_POST['rifnote_sponsored_query']) ? sanitize_text_field(wp_unslash($_POST['rifnote_sponsored_query'])) : '',
            ));

            echo is_wp_error($result)
                ? '<div class="notice notice-error is-dismissible"><p>' . esc_html($result->get_error_message()) . '</p></div>'
                : '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Sponsored placement saved.', 'rifnote-search') . '</p></div>';
        }
    }

    public static function maybe_handle_ads_action() {
        if (!isset($_POST['rifnote_ads_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_ads_nonce'])), 'rifnote_ads_action')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $action = isset($_POST['rifnote_ads_action']) ? sanitize_key(wp_unslash($_POST['rifnote_ads_action'])) : '';

        if ('update_request_status' === $action) {
            $request_id = isset($_POST['rifnote_ad_request_id']) ? absint($_POST['rifnote_ad_request_id']) : 0;
            $status = isset($_POST['rifnote_ad_status']) ? sanitize_key(wp_unslash($_POST['rifnote_ad_status'])) : '';
            $note = isset($_POST['rifnote_ad_status_note']) ? sanitize_textarea_field(wp_unslash($_POST['rifnote_ad_status_note'])) : '';
            $result = Rifnote_Search_Launch_Readiness::update_sponsor_request_status($request_id, $status, $note);

            echo is_wp_error($result)
                ? '<div class="notice notice-error is-dismissible"><p>' . esc_html($result->get_error_message()) . '</p></div>'
                : '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Advert request status updated.', 'rifnote-search') . '</p></div>';
        }

        if ('activate_request' === $action) {
            $request_id = isset($_POST['rifnote_ad_request_id']) ? absint($_POST['rifnote_ad_request_id']) : 0;
            $result = Rifnote_Search_Launch_Readiness::activate_sponsor_request($request_id);

            echo is_wp_error($result)
                ? '<div class="notice notice-error is-dismissible"><p>' . esc_html($result->get_error_message()) . '</p></div>'
                : '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('Advert request activated. Created %d sponsored placement(s).', 'rifnote-search'), (int) ($result['created'] ?? 0))) . '</p></div>';
        }

        if ('save_creative' === $action) {
            $request_id = isset($_POST['rifnote_ad_request_id']) ? absint($_POST['rifnote_ad_request_id']) : 0;
            $result = Rifnote_Search_Launch_Readiness::update_campaign_creative($request_id, array(
                'creative_headlines' => isset($_POST['rifnote_creative_headlines']) ? array_map('wp_unslash', (array) $_POST['rifnote_creative_headlines']) : array(),
                'creative_texts' => isset($_POST['rifnote_creative_texts']) ? array_map('wp_unslash', (array) $_POST['rifnote_creative_texts']) : array(),
                'creative_ctas' => isset($_POST['rifnote_creative_ctas']) ? array_map('wp_unslash', (array) $_POST['rifnote_creative_ctas']) : array(),
                'creative_assets' => isset($_POST['rifnote_creative_assets']) ? array_map('wp_unslash', (array) $_POST['rifnote_creative_assets']) : array(),
                'creative_status' => isset($_POST['rifnote_creative_status']) ? sanitize_key(wp_unslash($_POST['rifnote_creative_status'])) : 'draft',
                'creative_note' => isset($_POST['rifnote_creative_note']) ? sanitize_textarea_field(wp_unslash($_POST['rifnote_creative_note'])) : '',
            ));

            echo is_wp_error($result)
                ? '<div class="notice notice-error is-dismissible"><p>' . esc_html($result->get_error_message()) . '</p></div>'
                : '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Creative saved and versioned.', 'rifnote-search') . '</p></div>';
        }

        if ('add_sponsored' === $action) {
            $locations = isset($_POST['rifnote_sponsored_locations']) ? sanitize_text_field(wp_unslash($_POST['rifnote_sponsored_locations'])) : '';
            $interests = isset($_POST['rifnote_sponsored_interests']) ? sanitize_text_field(wp_unslash($_POST['rifnote_sponsored_interests'])) : '';
            $device_type = isset($_POST['rifnote_sponsored_device']) ? sanitize_key(wp_unslash($_POST['rifnote_sponsored_device'])) : '';
            $targeting_payload = array_filter(array(
                'audience' => array_filter(array(
                    'locations' => $locations,
                    'interests' => $interests,
                    'device_type' => $device_type,
                )),
                'interests' => $interests,
                'device_type' => $device_type,
            ));

            $result = Rifnote_Search_Launch_Readiness::add_sponsored(array(
                'title' => isset($_POST['rifnote_sponsored_title']) ? sanitize_text_field(wp_unslash($_POST['rifnote_sponsored_title'])) : '',
                'target_url' => isset($_POST['rifnote_sponsored_url']) ? esc_url_raw(wp_unslash($_POST['rifnote_sponsored_url'])) : '',
                'sponsor_name' => isset($_POST['rifnote_sponsor_name']) ? sanitize_text_field(wp_unslash($_POST['rifnote_sponsor_name'])) : '',
                'placement' => isset($_POST['rifnote_sponsored_placement']) ? sanitize_key(wp_unslash($_POST['rifnote_sponsored_placement'])) : 'search_top_intent',
                'category' => isset($_POST['rifnote_sponsored_category']) ? sanitize_title(wp_unslash($_POST['rifnote_sponsored_category'])) : '',
                'query_match' => isset($_POST['rifnote_sponsored_query']) ? sanitize_text_field(wp_unslash($_POST['rifnote_sponsored_query'])) : '',
                'priority' => isset($_POST['rifnote_sponsored_priority']) ? absint($_POST['rifnote_sponsored_priority']) : 50,
                'targeting_payload' => $targeting_payload,
                'starts_at' => isset($_POST['rifnote_sponsored_starts_at']) ? sanitize_text_field(wp_unslash($_POST['rifnote_sponsored_starts_at'])) : '',
                'ends_at' => isset($_POST['rifnote_sponsored_ends_at']) ? sanitize_text_field(wp_unslash($_POST['rifnote_sponsored_ends_at'])) : '',
                'status' => isset($_POST['rifnote_sponsored_status']) ? sanitize_key(wp_unslash($_POST['rifnote_sponsored_status'])) : 'active',
            ));

            echo is_wp_error($result)
                ? '<div class="notice notice-error is-dismissible"><p>' . esc_html($result->get_error_message()) . '</p></div>'
                : '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Sponsored placement saved.', 'rifnote-search') . '</p></div>';
        }
    }

    public static function maybe_handle_football_action() {
        if (!isset($_POST['rifnote_football_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_football_nonce'])), 'rifnote_football_action')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $action = isset($_POST['rifnote_football_action']) ? sanitize_key(wp_unslash($_POST['rifnote_football_action'])) : '';

        if ('clear_cache' === $action) {
            $deleted = Rifnote_Search_Football_API::clear_cache();
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('Football cache cleared. Removed %d cached rows.', 'rifnote-search'), (int) $deleted)) . '</p></div>';
        }

        if ('test_live' === $action) {
            $payload = Rifnote_Search_Football_API::live_payload(true);
            $summary = Rifnote_Search_Football_API::summarize_payload($payload);
            update_option('rifnote_api_football_last_test', array_merge($summary, array(
                'tested_at' => gmdate(DATE_ATOM),
                'target' => 'live',
            )), false);

            $notice_class = $summary['ok'] ? 'notice-success' : 'notice-warning';
            $message = sprintf(
                /* translators: 1: fixture count, 2: provider name */
                __('Live API test complete. Fixtures: %1$d. Provider: %2$s.', 'rifnote-search'),
                (int) $summary['fixtures'],
                $summary['provider'] ? $summary['provider'] : __('unknown', 'rifnote-search')
            );

            if (!empty($summary['message'])) {
                $message .= ' ' . $summary['message'];
            }

            echo '<div class="notice ' . esc_attr($notice_class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }

        if ('test_watchlist' === $action) {
            $payload = Rifnote_Search_Football_API::watchlist_payload(gmdate('Y-m-d'), true);
            $summary = Rifnote_Search_Football_API::summarize_payload($payload);
            update_option('rifnote_api_football_last_test', array_merge($summary, array(
                'tested_at' => gmdate(DATE_ATOM),
                'target' => 'watchlist',
            )), false);

            $notice_class = $summary['ok'] ? 'notice-success' : 'notice-warning';
            $message = sprintf(
                /* translators: 1: fixture count, 2: provider name */
                __('Watchlist API test complete. Fixtures: %1$d. Provider: %2$s.', 'rifnote-search'),
                (int) $summary['fixtures'],
                $summary['provider'] ? $summary['provider'] : __('unknown', 'rifnote-search')
            );

            if (!empty($summary['message'])) {
                $message .= ' ' . $summary['message'];
            }

            echo '<div class="notice ' . esc_attr($notice_class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }

        if ('test_upcoming' === $action) {
            $payload = Rifnote_Search_Football_API::upcoming_payload(30, true);
            $summary = Rifnote_Search_Football_API::summarize_payload($payload);
            update_option('rifnote_api_football_last_test', array_merge($summary, array(
                'tested_at' => gmdate(DATE_ATOM),
                'target' => 'upcoming',
            )), false);

            $notice_class = $summary['ok'] ? 'notice-success' : 'notice-warning';
            $message = sprintf(
                /* translators: 1: fixture count, 2: provider name */
                __('Upcoming API test complete. Fixtures: %1$d. Provider: %2$s.', 'rifnote-search'),
                (int) $summary['fixtures'],
                $summary['provider'] ? $summary['provider'] : __('unknown', 'rifnote-search')
            );

            if (!empty($summary['message'])) {
                $message .= ' ' . $summary['message'];
            }

            echo '<div class="notice ' . esc_attr($notice_class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }

        if ('backfill_history' === $action) {
            $years = isset($_POST['rifnote_football_history_years']) ? absint($_POST['rifnote_football_history_years']) : 1;
            $detail_limit = isset($_POST['rifnote_football_history_detail_limit']) ? absint($_POST['rifnote_football_history_detail_limit']) : 25;
            $summary = Rifnote_Search_Football_API::backfill_history($years, $detail_limit);

            if (is_wp_error($summary)) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($summary->get_error_message()) . '</p></div>';
                return;
            }

            $message = sprintf(
                /* translators: 1: season count, 2: fixture count, 3: detail count */
                __('History backfill complete. Seasons checked: %1$d. Fixtures stored: %2$d. Match details synced: %3$d.', 'rifnote-search'),
                (int) ($summary['seasons'] ?? 0),
                (int) ($summary['fixtures'] ?? 0),
                (int) ($summary['details_synced'] ?? 0)
            );

            if (!empty($summary['errors'])) {
                $message .= ' ' . sprintf(__('Warnings: %d.', 'rifnote-search'), count($summary['errors']));
            }

            echo '<div class="notice ' . esc_attr(!empty($summary['ok']) ? 'notice-success' : 'notice-warning') . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }

    public static function sanitize_email_provider($value) {
        $value = sanitize_key($value);
        return in_array($value, array('wp_mail', 'resend'), true) ? $value : 'wp_mail';
    }

    public static function sanitize_push_provider($value) {
        $value = sanitize_key($value);
        return in_array($value, array('local', 'webpush'), true) ? $value : 'local';
    }

    public static function sanitize_ai_cache_ttl($value) {
        return max(300, min(21600, absint($value)));
    }

    public static function sanitize_ai_max_answer_length($value) {
        return max(280, min(1500, absint($value)));
    }

    public static function sanitize_football_live_ttl($value) {
        return max(60, min(300, absint($value)));
    }

    public static function sanitize_football_fixture_ttl($value) {
        return max(60, min(3600, absint($value)));
    }

    public static function sanitize_football_upcoming_ttl($value) {
        return max(60, min(3600, absint($value)));
    }

    public static function sanitize_football_finished_ttl($value) {
        return max(60, min(7200, absint($value)));
    }

    public static function sanitize_football_details_ttl($value) {
        return max(60, min(7200, absint($value)));
    }

    public static function sanitize_football_competitions($value) {
        $lines = array();

        foreach (explode("\n", (string) $value) as $line) {
            $parts = array_map('trim', explode(':', sanitize_text_field($line), 3));
            $league_id = isset($parts[0]) ? absint($parts[0]) : 0;
            $season = isset($parts[1]) ? absint($parts[1]) : 0;
            $label = isset($parts[2]) ? sanitize_text_field($parts[2]) : '';

            if ($league_id && $season) {
                $lines[] = $league_id . ':' . $season . ':' . ($label ? $label : 'League ' . $league_id);
            }
        }

        return implode("\n", array_slice(array_unique($lines), 0, 50));
    }

    public static function sanitize_football_team_watchlist($value) {
        $lines = array();

        if (is_array($value)) {
            $value = implode("\n", array_map('sanitize_text_field', $value));
        }

        foreach (explode("\n", (string) $value) as $line) {
            $line = preg_replace('/\s+#.*$/', '', sanitize_text_field($line));
            $line = trim((string) $line);

            if ('' === $line) {
                continue;
            }

            $parts = preg_split('/[:|,]/', $line, 2);
            $team_id = isset($parts[0]) ? absint($parts[0]) : 0;
            $team_name = isset($parts[1]) ? sanitize_text_field($parts[1]) : '';

            if ($team_id) {
                $lines[] = $team_id . ($team_name ? ':' . $team_name : '');
                continue;
            }

            $team_name = sanitize_text_field($line);
            if ($team_name) {
                $lines[] = $team_name;
            }
        }

        return implode("\n", array_slice(array_unique($lines), 0, 250));
    }

    public static function sanitize_home_featured_football_matches($value) {
        $ids = array();

        if (is_array($value)) {
            $value = implode("\n", array_map('absint', $value));
        }

        foreach (preg_split('/[\s,]+/', (string) $value) as $item) {
            $fixture_id = absint($item);

            if ($fixture_id) {
                $ids[] = $fixture_id;
            }
        }

        return implode("\n", array_slice(array_values(array_unique($ids)), 0, 12));
    }

    public static function sanitize_secret($value) {
        $option = '';
        $filter = current_filter();

        if (is_string($filter) && 0 === strpos($filter, 'sanitize_option_')) {
            $option = substr($filter, strlen('sanitize_option_'));
        }

        if ($option && (!isset($_POST[$option]) || null === $value)) {
            return (string) get_option($option, '');
        }

        return trim(sanitize_text_field((string) $value));
    }

    public static function sanitize_data_api_timeout($value) {
        return max(3, min(20, absint($value)));
    }

    public static function sanitize_data_api_cache_ttl($value) {
        return max(0, min(900, absint($value)));
    }

    public static function sanitize_github_repo($value) {
        return Rifnote_Search_GitHub_Updater::normalize_repo($value);
    }

    public static function sanitize_release_asset_name($value) {
        $value = sanitize_file_name((string) $value);
        return $value ? $value : Rifnote_Search_GitHub_Updater::DEFAULT_ASSET;
    }

    public static function sanitize_synonyms($value) {
        $lines = array();

        foreach (explode("\n", (string) $value) as $line) {
            $line = trim(sanitize_text_field($line));

            if (!$line || false === strpos($line, '=')) {
                continue;
            }

            list($term, $alts) = array_map('trim', explode('=', $line, 2));
            $term = sanitize_text_field($term);
            $alts = implode(',', array_filter(array_map('sanitize_text_field', array_map('trim', explode(',', $alts)))));

            if ($term && $alts) {
                $lines[] = $term . '=' . $alts;
            }
        }

        return implode("\n", array_slice(array_unique($lines), 0, 100));
    }

    public static function sanitize_thenewsapi_limit($value) {
        return max(1, min(25, absint($value)));
    }

    public static function sanitize_thenewsapi_interval($value) {
        return Rifnote_Search_News_API::sanitize_interval($value);
    }

    public static function sanitize_report_frequency($value) {
        $value = sanitize_key($value);
        return in_array($value, array('off', 'daily', 'weekly'), true) ? $value : 'off';
    }

    public static function sanitize_report_days($value) {
        return max(1, min(365, absint($value)));
    }

    public static function sanitize_customgpt_mode($value) {
        return Rifnote_Search_CustomGPT_Import::sanitize_mode($value);
    }

    public static function sanitize_customgpt_max_batch($value) {
        return max(1, min(100, absint($value)));
    }

    public static function sanitize_home_lead_post_id($value) {
        $post_id = absint($value);

        if (!$post_id) {
            return 0;
        }

        $post = get_post($post_id);

        return ($post && 'post' === $post->post_type) ? $post_id : 0;
    }

    public static function sanitize_logo_width($value) {
        $width = absint($value);

        if (!$width) {
            return 220;
        }

        return max(80, min(420, $width));
    }

    public static function sanitize_mobile_logo_size($value) {
        $size = absint($value);

        if (!$size) {
            return 40;
        }

        return max(28, $size);
    }

    public static function sanitize_home_search_media_type($value) {
        $value = sanitize_key($value);

        return in_array($value, array('image', 'video', 'embed'), true) ? $value : 'image';
    }

    public static function google_font_choices() {
        return array(
            'Google Sans' => __('Google Sans / Product Sans stack', 'rifnote-search'),
            'Roboto' => __('Roboto', 'rifnote-search'),
            'Inter' => __('Inter', 'rifnote-search'),
            'Open Sans' => __('Open Sans', 'rifnote-search'),
            'Lato' => __('Lato', 'rifnote-search'),
            'Poppins' => __('Poppins', 'rifnote-search'),
            'Montserrat' => __('Montserrat', 'rifnote-search'),
            'Nunito Sans' => __('Nunito Sans', 'rifnote-search'),
            'Source Sans 3' => __('Source Sans 3', 'rifnote-search'),
            'Merriweather' => __('Merriweather', 'rifnote-search'),
            'Playfair Display' => __('Playfair Display', 'rifnote-search'),
        );
    }

    public static function sanitize_google_font($value) {
        $value = sanitize_text_field((string) $value);
        $choices = array_keys(self::google_font_choices());

        return in_array($value, $choices, true) ? $value : 'Roboto';
    }

    public static function sanitize_font_weight($value) {
        $weight = absint($value);

        if (!$weight) {
            return 700;
        }

        return max(100, min(950, $weight));
    }

    public static function sanitize_css_size($value) {
        $value = trim(sanitize_text_field((string) $value));

        if (!$value) {
            return '1rem';
        }

        if (preg_match('/^[0-9.]+(px|rem|em|%)$/', $value)) {
            return $value;
        }

        if (preg_match('/^clamp\([0-9.\srempx%,]+,[0-9.\svwrempx%,]+,[0-9.\srempx%,]+\)$/i', $value)) {
            return $value;
        }

        return '1rem';
    }

    public static function sanitize_home_note_post_ids($value) {
        if (is_array($value)) {
            $entries = array();

            foreach ($value as $index => $entry) {
                if (is_array($entry)) {
                    $entries[] = array(
                        'post_id' => absint($entry['post_id'] ?? 0),
                        'order' => max(1, min(99, absint($entry['order'] ?? ($index + 1)))),
                    );
                } else {
                    $entries[] = array(
                        'post_id' => absint($entry),
                        'order' => $index + 1,
                    );
                }
            }

            usort($entries, function($first, $second) {
                return $first['order'] <=> $second['order'];
            });

            $ids = wp_list_pluck($entries, 'post_id');
        } else {
            $ids = preg_split('/[\s,]+/', (string) $value);
        }

        $clean = array();

        foreach ($ids as $id) {
            $post_id = absint($id);
            if (!$post_id || in_array($post_id, $clean, true)) {
                continue;
            }

            $post = get_post($post_id);
            if ($post && 'post' === $post->post_type && 'publish' === $post->post_status) {
                $notes_category_id = self::ensure_notes_category();
                if ($notes_category_id) {
                    wp_set_post_categories($post_id, array($notes_category_id), true);
                }

                $clean[] = $post_id;
            }

            if (count($clean) >= 5) {
                break;
            }
        }

        return $clean;
    }

    public static function sanitize_home_pill_story_ids($value) {
        if (!is_array($value)) {
            return array();
        }

        $clean = array();

        foreach ($value as $pill => $entries) {
            $pill_key = sanitize_key($pill);

            if (!$pill_key || !is_array($entries)) {
                continue;
            }

            $ordered = array();

            foreach ($entries as $index => $entry) {
                if (is_array($entry)) {
                    $ordered[] = array(
                        'post_id' => absint($entry['post_id'] ?? 0),
                        'order' => max(1, min(99, absint($entry['order'] ?? ($index + 1)))),
                    );
                } else {
                    $ordered[] = array(
                        'post_id' => absint($entry),
                        'order' => $index + 1,
                    );
                }
            }

            usort($ordered, function($first, $second) {
                return $first['order'] <=> $second['order'];
            });

            foreach ($ordered as $entry) {
                $post_id = absint($entry['post_id'] ?? 0);

                if (!$post_id || in_array($post_id, $clean[$pill_key] ?? array(), true)) {
                    continue;
                }

                $post = get_post($post_id);

                if ($post && 'post' === $post->post_type && 'publish' === $post->post_status) {
                    $clean[$pill_key][] = $post_id;
                }

                if (count($clean[$pill_key] ?? array()) >= 8) {
                    break;
                }
            }
        }

        return $clean;
    }

    public static function home_pill_key($label) {
        $key = sanitize_key($label);

        return $key ? $key : 'notes';
    }

    public static function home_pill_keys($include_notes = false) {
        $keys = array();

        foreach (self::home_pills() as $pill) {
            if (!$include_notes && !empty($pill['is_notes'])) {
                continue;
            }

            $keys[] = self::home_pill_key($pill['category']);
        }

        return array_values(array_unique(array_filter($keys)));
    }

    public static function post_home_pill($post_id) {
        $pill = sanitize_key((string) get_post_meta($post_id, self::HOME_PILL_META, true));

        return in_array($pill, self::home_pill_keys(false), true) ? $pill : '';
    }

    public static function set_post_home_pill($post_id, $pill) {
        $pill = sanitize_key($pill);

        if (!$pill || !in_array($pill, self::home_pill_keys(false), true)) {
            delete_post_meta($post_id, self::HOME_PILL_META);
            return '';
        }

        update_post_meta($post_id, self::HOME_PILL_META, $pill);

        return $pill;
    }

    public static function default_home_pills() {
        return "Notes|Notes\nNigeria|Nigeria\nWorld|World\nFootball|Football\nPolitics|Politics\nBusiness|Business\nTech|Tech";
    }

    public static function default_home_featured_tab() {
        return array(
            'enabled' => false,
            'label' => 'Featured',
            'source' => 'category',
            'category_id' => 0,
            'tag_id' => 0,
            'query' => '',
        );
    }

    public static function sanitize_home_featured_tab($value) {
        $defaults = self::default_home_featured_tab();
        $value = is_array($value) ? $value : array();
        $source = sanitize_key($value['source'] ?? $defaults['source']);

        if (!in_array($source, array('category', 'tag', 'search'), true)) {
            $source = $defaults['source'];
        }

        $label = sanitize_text_field($value['label'] ?? $defaults['label']);
        $query = sanitize_text_field($value['query'] ?? '');

        return array(
            'enabled' => !empty($value['enabled']),
            'label' => $label ? $label : $defaults['label'],
            'source' => $source,
            'category_id' => absint($value['category_id'] ?? 0),
            'tag_id' => absint($value['tag_id'] ?? 0),
            'query' => $query,
        );
    }

    public static function home_featured_tab() {
        return self::sanitize_home_featured_tab(get_option('rifnote_home_featured_tab', self::default_home_featured_tab()));
    }

    public static function home_featured_tab_query_args($settings = null, $limit = 5) {
        $settings = is_array($settings) ? self::sanitize_home_featured_tab($settings) : self::home_featured_tab();

        if (empty($settings['enabled'])) {
            return array();
        }

        $args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => max(1, min(12, absint($limit))),
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'ids',
        );

        if ('category' === $settings['source']) {
            if (empty($settings['category_id'])) {
                return array();
            }

            $args['cat'] = (int) $settings['category_id'];
        } elseif ('tag' === $settings['source']) {
            if (empty($settings['tag_id'])) {
                return array();
            }

            $args['tag_id'] = (int) $settings['tag_id'];
        } elseif ('search' === $settings['source']) {
            if (empty($settings['query'])) {
                return array();
            }

            $args['s'] = $settings['query'];
        }

        return $args;
    }

    public static function home_featured_tab_post_ids($limit = 5, $settings = null) {
        $args = self::home_featured_tab_query_args($settings, $limit);

        return $args ? get_posts($args) : array();
    }

    public static function home_featured_tab_archive_url($settings = null) {
        $settings = is_array($settings) ? self::sanitize_home_featured_tab($settings) : self::home_featured_tab();

        if (empty($settings['enabled'])) {
            return '';
        }

        if ('category' === $settings['source'] && !empty($settings['category_id'])) {
            $link = get_category_link((int) $settings['category_id']);
            return is_wp_error($link) ? '' : $link;
        }

        if ('tag' === $settings['source'] && !empty($settings['tag_id'])) {
            $link = get_tag_link((int) $settings['tag_id']);
            return is_wp_error($link) ? '' : $link;
        }

        if ('search' === $settings['source'] && !empty($settings['query'])) {
            return add_query_arg('q', rawurlencode($settings['query']), home_url('/'));
        }

        return '';
    }

    public static function is_featured_home_pill($pill, $pill_key = '') {
        $settings = self::home_featured_tab();

        if (empty($settings['enabled'])) {
            return false;
        }

        $pill_key = $pill_key ? sanitize_key($pill_key) : self::home_pill_key($pill);

        return 'featured' === $pill_key || '__featured__' === $pill || 0 === strcasecmp((string) $pill, (string) $settings['label']);
    }

    public static function sanitize_home_pills($value) {
        $lines = array();

        if (is_array($value)) {
            $entries = array();

            foreach ($value as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $label = sanitize_text_field($entry['label'] ?? '');
                $category = sanitize_text_field($entry['category'] ?? $label);
                $enabled = !empty($entry['enabled']);
                $is_notes = 0 === strcasecmp($label, 'Notes') || 0 === strcasecmp($category, 'Notes');

                if (!$label || (!$enabled && !$is_notes)) {
                    continue;
                }

                $entries[] = array(
                    'order' => max(1, min(99, absint($entry['order'] ?? 50))),
                    'label' => $label,
                    'category' => $category ? $category : $label,
                    'is_notes' => $is_notes,
                );
            }

            usort($entries, function($first, $second) {
                if (!empty($first['is_notes'])) {
                    return -1;
                }
                if (!empty($second['is_notes'])) {
                    return 1;
                }

                return $first['order'] <=> $second['order'];
            });

            foreach ($entries as $entry) {
                if (!empty($entry['is_notes'])) {
                    continue;
                }

                $lines[] = $entry['label'] . '|' . $entry['category'];

                if (count($lines) >= 9) {
                    break;
                }
            }
        } else {
            foreach (explode("\n", (string) $value) as $line) {
                $line = trim(wp_strip_all_tags($line));

                if (!$line) {
                    continue;
                }

                $parts = array_map('trim', explode('|', $line, 2));
                $label = sanitize_text_field($parts[0] ?? '');
                $category = sanitize_text_field($parts[1] ?? $label);

                if (!$label) {
                    continue;
                }

                if (0 === strcasecmp($label, 'Notes') || 0 === strcasecmp($category, 'Notes')) {
                    continue;
                }

                $lines[] = $label . '|' . ($category ? $category : $label);

                if (count($lines) >= 9) {
                    break;
                }
            }
        }

        array_unshift($lines, 'Notes|Notes');

        return implode("\n", array_values(array_unique($lines)));
    }

    public static function home_pill_options() {
        $options = array(
            array('label' => 'Notes', 'category' => 'Notes', 'locked' => true),
            array('label' => 'Nigeria', 'category' => 'Nigeria'),
            array('label' => 'World', 'category' => 'World'),
            array('label' => 'Football', 'category' => 'Football'),
            array('label' => 'Politics', 'category' => 'Politics'),
            array('label' => 'Business', 'category' => 'Business'),
            array('label' => 'Tech', 'category' => 'Tech'),
        );
        $seen = array();
        $clean = array();

        foreach ($options as $option) {
            $key = strtolower($option['category']);
            $seen[$key] = true;
            $clean[] = $option;
        }

        $terms = get_terms(array(
            'taxonomy' => 'category',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ));

        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $label = sanitize_text_field($term->name);
                $key = strtolower($label);

                if (!$label || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $clean[] = array('label' => $label, 'category' => $label);
            }
        }

        return $clean;
    }

    public static function home_pills() {
        $value = get_option('rifnote_home_pills', self::default_home_pills());
        $value = self::sanitize_home_pills($value);
        $items = array();
        $featured = self::home_featured_tab();

        foreach (explode("\n", $value) as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            $label = sanitize_text_field($parts[0] ?? '');
            $category = sanitize_text_field($parts[1] ?? $label);

            if (!$label) {
                continue;
            }

            $items[] = array(
                'label' => $label,
                'category' => $category ? $category : $label,
                'is_notes' => 0 === strcasecmp($label, 'Notes') || 0 === strcasecmp($category, 'Notes'),
            );
        }

        if (!empty($featured['enabled'])) {
            $items = array_values(array_filter($items, function($item) {
                return empty($item['is_notes']);
            }));
            array_unshift($items, array(
                'label' => $featured['label'] ? $featured['label'] : 'Featured',
                'category' => '__featured__',
                'is_notes' => true,
                'is_featured' => true,
            ));
        } elseif (!$items || empty($items[0]['is_notes'])) {
            array_unshift($items, array('label' => 'Notes', 'category' => 'Notes', 'is_notes' => true));
        }

        return array_slice($items, 0, 10);
    }

    public static function sanitize_smart_rss_batch_size($value) {
        return max(1, min(100, absint($value)));
    }

    public static function sanitize_smart_rss_items_per_feed($value) {
        return max(1, min(30, absint($value)));
    }

    public static function sanitize_smart_rss_timeout($value) {
        return max(3, min(20, absint($value)));
    }

    public static function sanitize_smart_rss_interval($value) {
        return max(1, min(1440, absint($value)));
    }

    public static function sanitize_smart_rss_storage_mode($value) {
        $value = sanitize_key((string) $value);
        return in_array($value, array('warehouse', 'hybrid', 'wordpress'), true) ? $value : 'warehouse';
    }

    public static function sanitize_smart_rss_local_retention_days($value) {
        return max(1, min(365, absint($value)));
    }

    public static function sanitize_bool($value) {
        return rest_sanitize_boolean($value);
    }

    public static function sanitize_social_region_code($value) {
        $value = strtoupper(preg_replace('/[^A-Z]/', '', (string) $value));
        return $value ? substr($value, 0, 2) : 'NG';
    }

    public static function sanitize_social_language($value) {
        $value = strtolower(preg_replace('/[^a-z_-]/i', '', (string) $value));
        return $value ? substr($value, 0, 8) : 'en';
    }

    public static function sanitize_social_max_results($value) {
        return max(1, min(25, absint($value)));
    }

    public static function register_settings() {
        $football_settings = array(
            'rifnote_api_football_key' => array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_secret'), 'default' => ''),
            'rifnote_api_football_host' => array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => Rifnote_Search_Football_API::DEFAULT_HOST),
            'rifnote_api_football_timezone' => array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => wp_timezone_string()),
            'rifnote_api_football_live_cache_ttl' => array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_football_live_ttl'), 'default' => 60),
            'rifnote_api_football_fixture_cache_ttl' => array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_football_fixture_ttl'), 'default' => 300),
            'rifnote_api_football_upcoming_cache_ttl' => array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_football_upcoming_ttl'), 'default' => 300),
            'rifnote_api_football_finished_cache_ttl' => array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_football_finished_ttl'), 'default' => 900),
            'rifnote_api_football_details_cache_ttl' => array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_football_details_ttl'), 'default' => 600),
            'rifnote_api_football_competitions' => array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_football_competitions'), 'default' => "39:2025:Premier League\n2:2025:UEFA Champions League\n3:2025:UEFA Europa League\n1:2026:FIFA World Cup"),
            'rifnote_api_football_team_watchlist' => array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_football_team_watchlist'), 'default' => ''),
            'rifnote_home_featured_football_matches' => array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_home_featured_football_matches'), 'default' => ''),
        );

        register_setting('rifnote_search_settings', 'rifnote_ai_enabled', array('type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true));
        register_setting('rifnote_search_settings', 'rifnote_show_ai_cards', array('type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true));
        register_setting('rifnote_search_settings', 'rifnote_show_story_excerpts', array('type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true));
        register_setting('rifnote_search_settings', 'rifnote_openai_api_key', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_secret'), 'default' => ''));
        register_setting('rifnote_search_settings', 'rifnote_openai_model', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'gpt-5.4-mini'));
        register_setting('rifnote_search_settings', 'rifnote_ai_cache_ttl', array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_ai_cache_ttl'), 'default' => 1800));
        register_setting('rifnote_search_settings', 'rifnote_ai_max_answer_length', array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_ai_max_answer_length'), 'default' => 900));
        register_setting('rifnote_search_settings', 'rifnote_email_provider', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_email_provider'), 'default' => 'wp_mail'));
        register_setting('rifnote_search_settings', 'rifnote_email_api_key', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_secret'), 'default' => ''));
        register_setting('rifnote_search_settings', 'rifnote_email_from', array('type' => 'string', 'sanitize_callback' => 'sanitize_email', 'default' => get_option('admin_email')));
        register_setting('rifnote_search_settings', 'rifnote_push_provider', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_push_provider'), 'default' => 'local'));
        register_setting('rifnote_search_settings', 'rifnote_vapid_public_key', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_secret'), 'default' => ''));
        register_setting('rifnote_search_settings', 'rifnote_vapid_private_key', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_secret'), 'default' => ''));
        register_setting('rifnote_search_settings', 'rifnote_sponsor_checkout_url', array('type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''));
        register_setting('rifnote_search_settings', 'rifnote_ads_manager_email', array('type' => 'string', 'sanitize_callback' => 'sanitize_email', 'default' => get_option('admin_email')));
        register_setting('rifnote_search_settings', 'rifnote_ads_terms_url', array('type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''));
        register_setting('rifnote_search_settings', 'rifnote_ads_min_budget', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 50000));
        register_setting('rifnote_search_settings', 'rifnote_ads_frequency_cap_daily', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 4));
        register_setting('rifnote_search_settings', 'rifnote_ads_frequency_cap_session', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 2));
        register_setting('rifnote_search_settings', 'rifnote_ads_report_email', array('type' => 'string', 'sanitize_callback' => 'sanitize_email', 'default' => get_option('admin_email')));
        register_setting('rifnote_search_settings', 'rifnote_ads_report_frequency', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_report_frequency'), 'default' => 'off'));
        register_setting('rifnote_search_settings', 'rifnote_ads_report_days', array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_report_days'), 'default' => 30));
        register_setting('rifnote_search_settings', 'rifnote_analytics_enabled', array('type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true));
        register_setting('rifnote_search_settings', 'rifnote_analytics_guest_tracking', array('type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true));
        register_setting('rifnote_search_settings', 'rifnote_analytics_retention_days', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 180));
        register_setting('rifnote_search_settings', 'rifnote_search_synonyms', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_synonyms'), 'default' => "football=soccer\ntransfer=move,deal\npolitics=election,government"));
        register_setting('rifnote_search_settings', 'rifnote_trending_aliases', array('type' => 'string', 'sanitize_callback' => array('Rifnote_Search_Trending', 'sanitize_aliases'), 'default' => Rifnote_Search_Trending::alias_lines()));
        register_setting('rifnote_search_settings', 'rifnote_trending_blocked_terms', array('type' => 'string', 'sanitize_callback' => array('Rifnote_Search_Trending', 'sanitize_blocked_terms'), 'default' => Rifnote_Search_Trending::blocked_terms_text()));
        register_setting('rifnote_search_settings', 'rifnote_trending_internet_feeds', array('type' => 'string', 'sanitize_callback' => array('Rifnote_Search_Trending', 'sanitize_internet_feeds'), 'default' => Rifnote_Search_Trending::default_internet_feeds_text()));
        register_setting('rifnote_search_settings', 'rifnote_home_lead_post_id', array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_home_lead_post_id'), 'default' => 0));
        register_setting('rifnote_search_settings', 'rifnote_site_logo_url', array('type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''));
        register_setting('rifnote_search_settings', 'rifnote_site_logo_width_desktop', array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_logo_width'), 'default' => 220));
        register_setting('rifnote_search_settings', 'rifnote_home_takeover_logo_size_mobile', array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_mobile_logo_size'), 'default' => 40));
        register_setting('rifnote_search_settings', 'rifnote_default_story_image_url', array('type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''));
        register_setting('rifnote_search_settings', 'rifnote_typography_heading_font', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_google_font'), 'default' => 'Google Sans'));
        register_setting('rifnote_search_settings', 'rifnote_typography_body_font', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_google_font'), 'default' => 'Roboto'));
        register_setting('rifnote_search_settings', 'rifnote_typography_story_title_size', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_css_size'), 'default' => 'clamp(1.95rem, 3.45vw, 3.25rem)'));
        register_setting('rifnote_search_settings', 'rifnote_typography_story_title_weight', array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_font_weight'), 'default' => 840));
        register_setting('rifnote_search_settings', 'rifnote_typography_body_size', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_css_size'), 'default' => 'clamp(1.02rem, 1vw, 1.1rem)'));
        register_setting('rifnote_search_settings', 'rifnote_typography_body_weight', array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_font_weight'), 'default' => 430));
        register_setting('rifnote_search_settings', 'rifnote_home_search_media_url', array('type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''));
        register_setting('rifnote_search_settings', 'rifnote_home_search_media_link_url', array('type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''));
        register_setting('rifnote_search_settings', 'rifnote_home_search_media_type', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_home_search_media_type'), 'default' => 'image'));
        register_setting('rifnote_home_notes_settings', 'rifnote_home_note_post_ids', array('type' => 'array', 'sanitize_callback' => array(__CLASS__, 'sanitize_home_note_post_ids'), 'default' => array()));
        register_setting('rifnote_home_notes_settings', 'rifnote_home_pill_story_ids', array('type' => 'array', 'sanitize_callback' => array(__CLASS__, 'sanitize_home_pill_story_ids'), 'default' => array()));
        register_setting('rifnote_home_notes_settings', 'rifnote_home_pills', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_home_pills'), 'default' => self::default_home_pills()));
        register_setting('rifnote_home_featured_tab_settings', 'rifnote_home_featured_tab', array('type' => 'array', 'sanitize_callback' => array(__CLASS__, 'sanitize_home_featured_tab'), 'default' => self::default_home_featured_tab()));
        register_setting('rifnote_search_settings', 'rifnote_smart_rss_enabled', array('type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true));
        register_setting('rifnote_search_settings', 'rifnote_smart_rss_list', array('type' => 'string', 'sanitize_callback' => array('Rifnote_Search_Ingestion', 'sanitize_smart_rss_list'), 'default' => ''));
        register_setting('rifnote_search_settings', 'rifnote_smart_rss_interval_minutes', array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_smart_rss_interval'), 'default' => 5));
        register_setting('rifnote_search_settings', 'rifnote_smart_rss_batch_size', array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_smart_rss_batch_size'), 'default' => 25));
        register_setting('rifnote_search_settings', 'rifnote_smart_rss_items_per_feed', array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_smart_rss_items_per_feed'), 'default' => 10));
        register_setting('rifnote_search_settings', 'rifnote_smart_rss_timeout', array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_smart_rss_timeout'), 'default' => 8));
        register_setting('rifnote_search_settings', 'rifnote_smart_rss_auto_publish', array('type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true));
        register_setting('rifnote_search_settings', 'rifnote_smart_rss_storage_mode', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_smart_rss_storage_mode'), 'default' => 'warehouse'));
        register_setting('rifnote_search_settings', 'rifnote_smart_rss_local_retention_days', array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_smart_rss_local_retention_days'), 'default' => 30));
        register_setting('rifnote_search_settings', 'rifnote_rss_warehouse_worker_enabled', array('type' => 'boolean', 'sanitize_callback' => array(__CLASS__, 'sanitize_bool'), 'default' => true));
        register_setting('rifnote_rss_warehouse_settings', 'rifnote_smart_rss_enabled', array('type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true));
        register_setting('rifnote_rss_warehouse_settings', 'rifnote_smart_rss_list', array('type' => 'string', 'sanitize_callback' => array('Rifnote_Search_Ingestion', 'sanitize_smart_rss_list'), 'default' => ''));
        register_setting('rifnote_rss_warehouse_settings', 'rifnote_smart_rss_interval_minutes', array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_smart_rss_interval'), 'default' => 5));
        register_setting('rifnote_rss_warehouse_settings', 'rifnote_smart_rss_batch_size', array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_smart_rss_batch_size'), 'default' => 25));
        register_setting('rifnote_rss_warehouse_settings', 'rifnote_smart_rss_items_per_feed', array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_smart_rss_items_per_feed'), 'default' => 10));
        register_setting('rifnote_rss_warehouse_settings', 'rifnote_smart_rss_timeout', array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_smart_rss_timeout'), 'default' => 8));
        register_setting('rifnote_rss_warehouse_settings', 'rifnote_smart_rss_auto_publish', array('type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true));
        register_setting('rifnote_rss_warehouse_settings', 'rifnote_smart_rss_storage_mode', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_smart_rss_storage_mode'), 'default' => 'warehouse'));
        register_setting('rifnote_rss_warehouse_settings', 'rifnote_smart_rss_local_retention_days', array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_smart_rss_local_retention_days'), 'default' => 30));
        register_setting('rifnote_rss_warehouse_settings', 'rifnote_rss_warehouse_worker_enabled', array('type' => 'boolean', 'sanitize_callback' => array(__CLASS__, 'sanitize_bool'), 'default' => true));
        register_setting('rifnote_search_settings', 'rifnote_data_api_enabled', array('type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false));
        register_setting('rifnote_search_settings', 'rifnote_data_api_merge_search', array('type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true));
        register_setting('rifnote_search_settings', 'rifnote_data_api_url', array('type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''));
        register_setting('rifnote_search_settings', 'rifnote_data_api_token', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_secret'), 'default' => ''));
        register_setting('rifnote_search_settings', 'rifnote_data_api_timeout', array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_data_api_timeout'), 'default' => 8));
        register_setting('rifnote_search_settings', 'rifnote_data_api_cache_ttl', array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_data_api_cache_ttl'), 'default' => 120));
        register_setting('rifnote_search_settings', 'rifnote_thenewsapi_enabled', array('type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false));
        register_setting('rifnote_search_settings', 'rifnote_thenewsapi_key', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_secret'), 'default' => ''));
        register_setting('rifnote_search_settings', 'rifnote_thenewsapi_locale', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'ng,us,gb'));
        register_setting('rifnote_search_settings', 'rifnote_thenewsapi_language', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'en'));
        register_setting('rifnote_search_settings', 'rifnote_thenewsapi_categories', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'general,business,tech,politics,sports'));
        register_setting('rifnote_search_settings', 'rifnote_thenewsapi_limit', array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_thenewsapi_limit'), 'default' => 10));
        register_setting('rifnote_search_settings', 'rifnote_thenewsapi_auto_publish', array('type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true));
        register_setting('rifnote_search_settings', 'rifnote_thenewsapi_interval', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_thenewsapi_interval'), 'default' => 'rifnote_every_six_hours'));
        register_setting('rifnote_search_settings', 'rifnote_live_data_poll_ttl', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 900));
        register_setting('rifnote_search_settings', 'rifnote_live_weather_locations', array('type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => "Lagos:6.5244:3.3792\nAbuja:9.0765:7.3986\nLondon:51.5072:-0.1276"));
        register_setting('rifnote_search_settings', 'rifnote_live_market_pairs', array('type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => "NGN/USD:USD:NGN\nEUR/USD:EUR:USD\nGBP/USD:GBP:USD"));
        register_setting('rifnote_search_settings', 'rifnote_native_ios_bundle_id', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''));
        register_setting('rifnote_search_settings', 'rifnote_native_android_package', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''));
        register_setting('rifnote_search_settings', 'rifnote_release_notes', array('type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => ''));
        register_setting('rifnote_search_settings', 'rifnote_github_updater_enabled', array('type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true));
        register_setting('rifnote_search_settings', 'rifnote_github_repo', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_github_repo'), 'default' => Rifnote_Search_GitHub_Updater::DEFAULT_REPO));
        register_setting('rifnote_search_settings', 'rifnote_github_asset_name', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_release_asset_name'), 'default' => Rifnote_Search_GitHub_Updater::DEFAULT_ASSET));
        register_setting('rifnote_search_settings', 'rifnote_github_access_token', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_secret'), 'default' => ''));
        register_setting('rifnote_customgpt_settings', 'rifnote_customgpt_import_enabled', array('type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false));
        register_setting('rifnote_customgpt_settings', 'rifnote_customgpt_import_default_mode', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_customgpt_mode'), 'default' => 'draft'));
        register_setting('rifnote_customgpt_settings', 'rifnote_customgpt_import_max_batch', array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_customgpt_max_batch'), 'default' => Rifnote_Search_CustomGPT_Import::DEFAULT_MAX_BATCH));
        register_setting('rifnote_customgpt_settings', 'rifnote_customgpt_import_allowed_domains', array('type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => ''));
        register_setting('rifnote_customgpt_settings', 'rifnote_customgpt_import_instructions', array('type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => Rifnote_Search_CustomGPT_Import::default_instructions()));
        register_setting('rifnote_social_settings', 'rifnote_social_youtube_enabled', array('type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false));
        register_setting('rifnote_social_settings', 'rifnote_social_youtube_api_key', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_secret'), 'default' => ''));
        register_setting('rifnote_social_settings', 'rifnote_social_youtube_queries', array('type' => 'string', 'sanitize_callback' => array('Rifnote_Search_Social', 'sanitize_queries'), 'default' => "Nigeria news|Nigeria\nFootball highlights|Football\nAfrobeats news|Entertainment"));
        register_setting('rifnote_social_settings', 'rifnote_social_youtube_region_code', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_social_region_code'), 'default' => 'NG'));
        register_setting('rifnote_social_settings', 'rifnote_social_youtube_language', array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_social_language'), 'default' => 'en'));
        register_setting('rifnote_social_settings', 'rifnote_social_youtube_max_results', array('type' => 'integer', 'sanitize_callback' => array(__CLASS__, 'sanitize_social_max_results'), 'default' => 8));
        register_setting('rifnote_social_settings', 'rifnote_social_default_mode', array('type' => 'string', 'sanitize_callback' => array('Rifnote_Search_Social', 'sanitize_mode'), 'default' => 'draft'));
        register_setting('rifnote_social_settings', 'rifnote_social_manual_auto_embed', array('type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true));
        register_setting('rifnote_source_logo_settings', 'rifnote_source_logo_overrides', array('type' => 'array', 'sanitize_callback' => array(__CLASS__, 'sanitize_source_logo_overrides'), 'default' => array()));

        foreach ($football_settings as $option => $args) {
            register_setting('rifnote_search_football_settings', $option, $args);
        }

        register_setting('rifnote_search_football_curation_settings', 'rifnote_api_football_team_watchlist', $football_settings['rifnote_api_football_team_watchlist']);
        register_setting('rifnote_search_football_curation_settings', 'rifnote_home_featured_football_matches', $football_settings['rifnote_home_featured_football_matches']);
    }

    public static function sanitize_source_logo_overrides($value) {
        $clean = array();

        if (!is_array($value)) {
            return $clean;
        }

        foreach ($value as $domain => $logo_url) {
            $domain = Rifnote_Search_Source_Meta::source_domain('https://' . preg_replace('/^https?:\/\//', '', (string) $domain));
            $logo_url = esc_url_raw((string) $logo_url);

            if ($domain && $logo_url) {
                $clean[$domain] = $logo_url;
            }
        }

        return $clean;
    }

    private static function maybe_export_ads_report() {
        if (empty($_GET['rifnote_ads_export'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to export advert reports.', 'rifnote-search'));
        }

        $export = sanitize_key(wp_unslash($_GET['rifnote_ads_export']));
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

        if (!wp_verify_nonce($nonce, 'rifnote_ads_export_' . $export)) {
            wp_die(esc_html__('Export link expired. Please reload the admin page and try again.', 'rifnote-search'));
        }

        if ('campaign-pdf' === $export) {
            self::export_campaign_pdf_report(absint($_GET['campaign_id'] ?? 0));
            exit;
        }

        $days = self::sanitize_report_days($_GET['days'] ?? get_option('rifnote_ads_report_days', 30));
        $performance = Rifnote_Search_Analytics::ad_performance_summary($days);
        $revenue = Rifnote_Search_Analytics::ad_revenue_report($days);
        $sources = Rifnote_Search_Analytics::source_performance_report($days);
        $filename = 'rifnote-ads-' . $export . '-' . gmdate('Y-m-d') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        $out = fopen('php://output', 'w');

        if ('sponsors' === $export) {
            fputcsv($out, array('Sponsor', 'Impressions', 'Clicks', 'CTR %', 'Conversions', 'Conversion Rate %', 'Conversion Value'));
            foreach ($performance['sponsors'] ?? array() as $row) {
                fputcsv($out, array($row['label'], $row['impressions'], $row['clicks'], $row['ctr'], $row['conversions'], $row['conversion_rate'], $row['conversion_value']));
            }
            exit;
        }

        if ('campaigns' === $export) {
            fputcsv($out, array('ID', 'Campaign', 'Sponsor', 'Status', 'Budget', 'Placements', 'Progress %', 'Delivery %', 'Impressions', 'Clicks', 'Conversions', 'Conversion Value', 'Signal'));
            foreach ($performance['campaign_pacing'] ?? array() as $row) {
                fputcsv($out, array($row['id'], $row['label'], $row['sponsor'], $row['status'], $row['budget'], $row['placements'], $row['progress'], $row['delivery'], $row['impressions'], $row['clicks'], $row['conversions'], $row['conversion_value'], $row['signal']));
            }
            exit;
        }

        if ('sources' === $export) {
            fputcsv($out, array('Source', 'Posts', 'Impressions', 'Clicks', 'CTR %', 'Latest Post'));
            foreach ($sources['rows'] ?? array() as $row) {
                fputcsv($out, array($row['source_name'], $row['posts'], $row['impressions'], $row['clicks'], $row['ctr'], $row['latest_post']));
            }
            exit;
        }

        if ('revenue' === $export) {
            fputcsv($out, array('Type', 'Label', 'Campaigns', 'Value', 'Paid Value'));
            foreach ($revenue['status_rows'] ?? array() as $row) {
                fputcsv($out, array('status', $row['label'], $row['campaigns'], $row['value'], $row['paid_value']));
            }
            foreach ($revenue['daily_rows'] ?? array() as $row) {
                fputcsv($out, array('daily', $row['label'], $row['campaigns'], $row['value'], $row['paid_value']));
            }
            exit;
        }

        if ('placements' === $export) {
            fputcsv($out, array('ID', 'Campaign', 'Sponsor', 'Placement', 'Status', 'Priority', 'Impressions', 'Clicks', 'CTR %', 'Conversions', 'Conversion Rate %', 'Conversion Value', 'Signal'));
            foreach ($performance['placements'] ?? array() as $row) {
                fputcsv($out, array($row['id'], $row['label'], $row['sponsor'], $row['placement'], $row['status'], $row['priority'], $row['impressions'], $row['clicks'], $row['ctr'], $row['conversions'], $row['conversion_rate'], $row['conversion_value'], self::ad_performance_signal($row)));
            }
            exit;
        }

        fputcsv($out, array('Message'));
        fputcsv($out, array('Unknown export requested.'));
        exit;
    }

    private static function format_cron_status($status) {
        $status = is_array($status) ? $status : array();
        $next = (int) ($status['next_run'] ?? 0);

        if (!$next) {
            return __('not scheduled', 'rifnote-search');
        }

        $when = get_date_from_gmt(gmdate('Y-m-d H:i:s', $next), 'M j, Y H:i');

        if (!empty($status['is_overdue'])) {
            $overdue = $status['overdue_label'] ?? '';
            return $overdue
                ? sprintf(__('overdue by %1$s; queued time was %2$s', 'rifnote-search'), $overdue, $when)
                : sprintf(__('overdue; queued time was %s', 'rifnote-search'), $when);
        }

        return sprintf(__('next run %s', 'rifnote-search'), $when);
    }

    public static function render_page() {
        self::maybe_export_ads_report();
        self::maybe_save_trending();
        self::maybe_handle_submission_action();
        self::maybe_handle_publisher_action();
        self::maybe_handle_legal_action();
        self::maybe_run_ingestion();
        self::maybe_run_thenewsapi_import();
        self::maybe_handle_customgpt_action();
        self::maybe_handle_social_action();
        self::maybe_handle_aggregation_action();
        self::maybe_handle_hardening_action();
        self::maybe_handle_retention_action();
        self::maybe_handle_beta_action();
        self::maybe_handle_index_action();
        self::maybe_handle_platform_insights_action();
        self::maybe_handle_operations_action();
        self::maybe_handle_launch_action();
        self::maybe_handle_ads_action();
        self::maybe_handle_football_action();
        self::maybe_handle_source_logo_action();
        $early_section = self::current_section();

        if ('source-logos' === $early_section || 'settings' === $early_section || 0 === strpos($early_section, 'settings-')) {
            $current_admin_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
            ?>
            <div class="wrap">
                <h1><?php echo esc_html('source-logos' === $early_section ? __('Source Logos', 'rifnote-search') : __('Rifnote Settings', 'rifnote-search')); ?></h1>
                <p><?php echo esc_html('source-logos' === $early_section ? __('Manage publisher logo discovery, overrides and fallbacks from one place.', 'rifnote-search') : __('Settings are grouped by family so admins can adjust the exact part of Rifnote they need.', 'rifnote-search')); ?></p>
                <?php self::render_admin_tabs(); ?>
                <?php
                if ('source-logos' === $early_section) {
                    self::render_source_logos_page();
                } elseif ('settings' === $early_section) {
                    self::render_settings_home_page();
                } else {
                    self::render_settings_family_page($early_section);
                }
                ?>
            </div>
            <?php
            return;
        }
        $trending = Rifnote_Search_Trending::topics(12);
        $trending_signals = Rifnote_Search_Trending::recent_signals(20);
        $submissions = Rifnote_Search_Publishers::recent_submissions(20);
        $publishers = Rifnote_Search_Publishers::recent_publishers(30);
        $publisher_events = Rifnote_Search_Publishers::recent_events(0, 20);
        $webhook_deliveries = Rifnote_Search_Publishers::recent_webhook_deliveries(0, 20);
        $feed_health = Rifnote_Search_Ingestion::feed_health_summary(30);
        $analytics = Rifnote_Search_Analytics::summary(7);
        $analytics_usage = Rifnote_Search_Analytics::app_usage_summary(30);
        $analytics_audience = Rifnote_Search_Analytics::audience_summary(30);
        $analytics_events = Rifnote_Search_Analytics::recent_events(80);
        $content_performance = Rifnote_Search_Analytics::content_performance_summary(30);
        $football_analytics = Rifnote_Search_Analytics::football_analytics_summary(7);
        $legal_requests = Rifnote_Search_Legal::recent_requests(20);
        $blocked_domains = Rifnote_Search_Legal::blocked_domains(true);
        $launch_report = Rifnote_Search_Hardening::launch_report();
        $error_logs = Rifnote_Search_Hardening::recent_errors(20);
        $backup_plan = get_option('rifnote_search_backup_plan', Rifnote_Search_Hardening::default_backup_plan());
        $beta_summary = Rifnote_Search_Beta::summary();
        $beta_feedback = Rifnote_Search_Beta::recent_feedback(20);
        $ranking_rules = Rifnote_Search_Beta::ranking_rules(true);
        $publisher_targets = Rifnote_Search_Beta::publisher_targets(20);
        $index_health = Rifnote_Search_Index::health();
        $no_result_queue = Rifnote_Search_Platform_Insights::recent_no_results(20);
        $editorial_console = Rifnote_Search_Operations::editorial_console();
        $ranking_query = get_option('rifnote_search_last_ranking_simulation_query', '');
        $ranking_results = $ranking_query ? Rifnote_Search_Operations::ranking_simulation(array('query' => $ranking_query, 'category' => '', 'date_range' => 'all', 'sort' => 'relevance'), 8) : array();
        $daily_briefing = Rifnote_Search_Operations::daily_briefing(6);
        $cleanup_preview_payload = self::is_section('operations') ? get_transient('rifnote_cleanup_last_preview') : false;
        $cleanup_preview = is_array($cleanup_preview_payload) && isset($cleanup_preview_payload['preview']) ? $cleanup_preview_payload['preview'] : array('count' => 0, 'breakdown' => array(), 'samples' => array());
        $retention_summary = Rifnote_Search_Retention::admin_summary();
        $alerts_last_run = get_option('rifnote_search_alerts_last_run', array());
        $newsletter_last_run = get_option('rifnote_search_newsletter_last_run', array());
        $push_last_run = get_option('rifnote_search_push_last_run', array());
        $delivery_health = Rifnote_Search_Delivery::delivery_health();
        $release_readiness = Rifnote_Search_Release::readiness();
        $launch_summary = Rifnote_Search_Launch_Readiness::admin_summary();
        $audience_summary = Rifnote_Search_Analytics::audience_summary(30);
        $ad_performance = Rifnote_Search_Analytics::ad_performance_summary(30);
        $claims = Rifnote_Search_Launch_Readiness::recent_claims(10);
        $sponsored = Rifnote_Search_Launch_Readiness::recent_sponsored(50);
        $sponsor_requests = Rifnote_Search_Launch_Readiness::recent_sponsor_requests(50);
        $ad_inventory = Rifnote_Search_Launch_Readiness::ad_inventory();
        $suspicious = Rifnote_Search_Launch_Readiness::recent_suspicious(10);
        $editorial_audit = Rifnote_Search_Editorial::recent_audit(20);
        $football_settings = Rifnote_Search_Football_API::settings();
        $football_competitions = Rifnote_Search_Football_API::competitions();
        $football_team_watchlist = Rifnote_Search_Football_API::team_watchlist();
        $football_curation_league_key = isset($_GET['football_league']) ? sanitize_text_field(wp_unslash($_GET['football_league'])) : '';
        $football_curation_date = isset($_GET['football_date']) ? sanitize_text_field(wp_unslash($_GET['football_date'])) : wp_date('Y-m-d');
        $football_curation_competition = self::football_competition_from_key($football_curation_league_key, $football_competitions);
        $football_curation_sync = self::is_section('football-curation') ? Rifnote_Search_Football_API::sync_admin_curation_date($football_curation_date) : array();
        $football_stored_teams = self::is_section('football-curation') ? Rifnote_Search_Football_API::stored_teams_by_competition((int) ($football_curation_competition['league_id'] ?? 0), (int) ($football_curation_competition['season'] ?? 0), 700) : array();
        $football_featured_ids = self::is_section('football-curation') ? Rifnote_Search_Football_API::homepage_featured_fixture_ids() : array();
        $football_today_matches = self::is_section('football-curation') ? Rifnote_Search_Football_API::stored_matches_for_date($football_curation_date, 260, false) : array();
        $football_health = Rifnote_Search_Football_API::admin_health();
        $football_usage = Rifnote_Search_Football_API::usage_summary(7);
        $football_recent_usage = Rifnote_Search_Football_API::recent_usage(12);
        Rifnote_Search_Ingestion::repair_schedule(true);
        $next_ingestion = wp_next_scheduled(Rifnote_Search_Ingestion::CRON_HOOK);
        $rss_schedule_status = Rifnote_Search_Ingestion::schedule_status();
        $next_thenewsapi = wp_next_scheduled(Rifnote_Search_News_API::CRON_HOOK);
        $thenewsapi_last_run = get_option('rifnote_thenewsapi_last_run', array());
        $customgpt_settings = Rifnote_Search_CustomGPT_Import::settings();
        $customgpt_logs = Rifnote_Search_CustomGPT_Import::recent_logs(25);
        $customgpt_last_run = get_option('rifnote_customgpt_import_last_run', array());
        $customgpt_cleanup_queue = Rifnote_Search_CustomGPT_Import::cleanup_queue(20);
        $social_settings = Rifnote_Search_Social::settings();
        $social_last_run = get_option('rifnote_social_last_run', array());
        $social_recent_posts = Rifnote_Search_Social::recent_social_posts(25);
        $aggregation_categories = get_terms(array('taxonomy' => 'category', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC'));
        $manual_aggregations = Rifnote_Search_Aggregation::all();
        $aggregation_candidates = Rifnote_Search_Aggregation::candidates(35);
        $current_admin_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $is_ads_admin_page = 0 === strpos($current_admin_page, 'rifnote-search-ads');
        $is_analytics_admin_page = 0 === strpos($current_admin_page, 'rifnote-search-analytics');
        $is_social_admin_page = 0 === strpos($current_admin_page, 'rifnote-search-social');
        $is_home_notes_admin_page = 'rifnote-search-home-notes' === $current_admin_page;
        $is_featured_tab_admin_page = 'rifnote-search-featured-tab' === $current_admin_page;
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($is_featured_tab_admin_page ? __('Featured Homepage Tab', 'rifnote-search') : ($is_home_notes_admin_page ? __('Homepage Notes', 'rifnote-search') : ($is_social_admin_page ? __('Rifnote Social', 'rifnote-search') : ($is_analytics_admin_page ? __('Rifnote Analytics', 'rifnote-search') : ($is_ads_admin_page ? __('Rifnote Ads', 'rifnote-search') : __('Rifnote Search', 'rifnote-search')))))); ?></h1>
            <p><?php echo esc_html($is_featured_tab_admin_page ? __('Let a category, tag or search query temporarily take over the first homepage pill without touching the normal Notes desk.', 'rifnote-search') : ($is_home_notes_admin_page ? __('Curate the five story summary notes shown on the homepage without touching logo, media or platform settings.', 'rifnote-search') : ($is_social_admin_page ? __('Import social posts and videos into the database as searchable Rifnote stories with clean titles, snippets, source metadata and platform attribution.', 'rifnote-search') : ($is_analytics_admin_page ? __('Track app usage, users, guests, recurring visitors, search intent, content engagement and ad performance.', 'rifnote-search') : ($is_ads_admin_page ? __('Operate advertiser requests, campaign approvals, sponsored placements, pressure-point pricing and ad settings.', 'rifnote-search') : __('Operate Rifnote Search as a plugin-owned discovery platform: search, publishers, growth, legal, release and settings.', 'rifnote-search')))))); ?></p>
            <?php self::render_admin_tabs(); ?>
            <?php if ($is_social_admin_page) : ?>
                <?php self::render_social_admin_page($social_settings, $social_last_run, $social_recent_posts); ?>
            </div>
            <?php return; ?>
            <?php endif; ?>
            <?php if ($is_ads_admin_page) : ?>
                <?php self::render_ads_admin_page(self::current_section(), $launch_summary, $sponsor_requests, $sponsored, $ad_inventory, $audience_summary, $ad_performance); ?>
            </div>
            <?php return; ?>
            <?php endif; ?>
            <?php if ($is_analytics_admin_page) : ?>
                <?php self::render_analytics_admin_page(self::current_section(), $analytics, $analytics_usage, $analytics_audience, $analytics_events, $ad_performance, $content_performance, $football_analytics); ?>
            </div>
            <?php return; ?>
            <?php endif; ?>

            <?php if (self::is_section('home-notes')) : ?>
                <?php self::render_home_notes_admin_page(); ?>
            </div>
            <?php return; ?>
            <?php endif; ?>

            <?php if (self::is_section('featured-tab')) : ?>
                <?php self::render_home_featured_tab_admin_page(); ?>
            </div>
            <?php return; ?>
            <?php endif; ?>

            <?php if (self::is_section('dashboard')) : ?>
                <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;max-width:1120px;">
                    <div class="card"><h3><?php echo esc_html(number_format_i18n((int) $index_health['indexed_posts'])); ?></h3><p><?php esc_html_e('Indexed stories', 'rifnote-search'); ?></p></div>
                    <div class="card"><h3><?php echo esc_html(number_format_i18n((int) $analytics['searches'])); ?></h3><p><?php esc_html_e('Searches this week', 'rifnote-search'); ?></p></div>
                    <div class="card"><h3><?php echo esc_html(number_format_i18n((int) $retention_summary['newsletter_subscribers'])); ?></h3><p><?php esc_html_e('Newsletter subscribers', 'rifnote-search'); ?></p></div>
                    <div class="card"><h3><?php echo esc_html(ucwords(str_replace('_', ' ', $release_readiness['status']))); ?></h3><p><?php esc_html_e('Release state', 'rifnote-search'); ?></p></div>
                </div>
                <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;align-items:start;margin-top:16px;">
                    <div class="card">
                        <h2><?php esc_html_e('Today', 'rifnote-search'); ?></h2>
                        <p><?php echo esc_html(sprintf(__('RSS ingestion: %s.', 'rifnote-search'), self::format_cron_status($rss_schedule_status))); ?></p>
                        <?php if (!empty($rss_schedule_status['is_overdue'])) : ?>
                            <p style="color:#b32d2e;"><?php esc_html_e('The Smart RSS hook is overdue. Other cron jobs may still be fine; Rifnote will repair this RSS schedule on the next normal admin/site request if it is stale beyond the grace window.', 'rifnote-search'); ?></p>
                        <?php endif; ?>
                        <p><?php echo esc_html(sprintf(__('Queued notifications: %d. Open Growth to process alerts, newsletters and push.', 'rifnote-search'), (int) $retention_summary['queued_notifications'])); ?></p>
                        <p><?php echo esc_html(sprintf(__('Sponsor requests waiting: %d.', 'rifnote-search'), (int) $launch_summary['sponsor_requests'])); ?></p>
                    </div>
                    <div class="card">
                        <h2><?php esc_html_e('Fast paths', 'rifnote-search'); ?></h2>
                        <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=rifnote-search-settings')); ?>"><?php esc_html_e('Configure platform settings', 'rifnote-search'); ?></a></p>
                        <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=rifnote-search-publishers')); ?>"><?php esc_html_e('Review publishers', 'rifnote-search'); ?></a></p>
                        <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=rifnote-search-release')); ?>"><?php esc_html_e('Check release readiness', 'rifnote-search'); ?></a></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (self::is_section('settings')) : ?>
            <?php
            $home_lead_post_id = absint(get_option('rifnote_home_lead_post_id', 0));
            $home_lead_post = $home_lead_post_id ? get_post($home_lead_post_id) : null;
            $recent_home_lead_posts = get_posts(array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => 30,
                'orderby' => 'date',
                'order' => 'DESC',
            ));
            ?>
            <form method="post" action="options.php">
                <?php settings_fields('rifnote_search_settings'); ?>
                <h2><?php esc_html_e('Homepage Editorial', 'rifnote-search'); ?></h2>
                <p><?php esc_html_e('Choose the manually curated homepage lead. Use the standalone Homepage Notes desk for the five Notes shown under the search box.', 'rifnote-search'); ?></p>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="rifnote_home_lead_post_id"><?php esc_html_e('Lead headline post ID', 'rifnote-search'); ?></label></th>
                            <td>
                                <input id="rifnote_home_lead_post_id" class="small-text" type="number" min="0" name="rifnote_home_lead_post_id" list="rifnote_home_lead_posts" value="<?php echo esc_attr($home_lead_post_id); ?>" />
                                <datalist id="rifnote_home_lead_posts">
                                    <?php foreach ($recent_home_lead_posts as $lead_candidate) : ?>
                                        <option value="<?php echo esc_attr($lead_candidate->ID); ?>"><?php echo esc_html(get_the_title($lead_candidate)); ?></option>
                                    <?php endforeach; ?>
                                </datalist>
                                <?php if ($home_lead_post && 'publish' === $home_lead_post->post_status) : ?>
                                    <p class="description"><?php echo esc_html(sprintf(__('Currently selected: %s', 'rifnote-search'), get_the_title($home_lead_post))); ?></p>
                                <?php else : ?>
                                    <p class="description"><?php esc_html_e('Leave empty or 0 to let Rifnote use the highest-ranked story as the visual lead. Select a published post ID to curate it manually.', 'rifnote-search'); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_site_logo_url"><?php esc_html_e('Site logo URL', 'rifnote-search'); ?></label></th>
                            <td>
                                <div class="rs-media-field">
                                    <input id="rifnote_site_logo_url" class="large-text rs-media-url" type="url" name="rifnote_site_logo_url" value="<?php echo esc_attr(get_option('rifnote_site_logo_url', '')); ?>" placeholder="https://..." />
                                    <p>
                                        <button type="button" class="button rs-media-picker" data-target="#rifnote_site_logo_url" data-library="image" data-title="<?php esc_attr_e('Choose site logo', 'rifnote-search'); ?>" data-button="<?php esc_attr_e('Use logo', 'rifnote-search'); ?>"><?php esc_html_e('Choose from Media Library', 'rifnote-search'); ?></button>
                                        <button type="button" class="button rs-media-clear" data-target="#rifnote_site_logo_url"><?php esc_html_e('Clear', 'rifnote-search'); ?></button>
                                    </p>
                                </div>
                                <p class="description"><?php esc_html_e('Used by the plugin-owned site header.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_site_logo_width_desktop"><?php esc_html_e('Desktop logo width', 'rifnote-search'); ?></label></th>
                            <td>
                                <input id="rifnote_site_logo_width_desktop" class="small-text" type="number" min="80" max="420" step="1" name="rifnote_site_logo_width_desktop" value="<?php echo esc_attr(self::sanitize_logo_width(get_option('rifnote_site_logo_width_desktop', 220))); ?>" />
                                <span><?php esc_html_e('px', 'rifnote-search'); ?></span>
                                <p class="description"><?php esc_html_e('Controls the max width of the desktop header logo. Mobile still uses the favicon-style mark to save space.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_home_takeover_logo_size_mobile"><?php esc_html_e('Mobile homepage takeover logo size', 'rifnote-search'); ?></label></th>
                            <td>
                                <input id="rifnote_home_takeover_logo_size_mobile" class="small-text" type="number" min="28" step="1" name="rifnote_home_takeover_logo_size_mobile" value="<?php echo esc_attr(self::sanitize_mobile_logo_size(get_option('rifnote_home_takeover_logo_size_mobile', 40))); ?>" />
                                <span><?php esc_html_e('px', 'rifnote-search'); ?></span>
                                <p class="description"><?php esc_html_e('Shown at the top center of the mobile homepage when featured media, election, video or match scoreboard replaces the normal Rifnote wordmark.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_default_story_image_url"><?php esc_html_e('Global default story image', 'rifnote-search'); ?></label></th>
                            <td>
                                <div class="rs-media-field">
                                    <input id="rifnote_default_story_image_url" class="large-text rs-media-url" type="url" name="rifnote_default_story_image_url" value="<?php echo esc_attr(get_option('rifnote_default_story_image_url', '')); ?>" placeholder="https://..." />
                                    <p>
                                        <button type="button" class="button rs-media-picker" data-target="#rifnote_default_story_image_url" data-library="image" data-title="<?php esc_attr_e('Choose default story image', 'rifnote-search'); ?>" data-button="<?php esc_attr_e('Use image', 'rifnote-search'); ?>"><?php esc_html_e('Choose from Media Library', 'rifnote-search'); ?></button>
                                        <button type="button" class="button rs-media-clear" data-target="#rifnote_default_story_image_url"><?php esc_html_e('Clear', 'rifnote-search'); ?></button>
                                    </p>
                                </div>
                                <p class="description"><?php esc_html_e('Fallback for posts with no featured image and no category-level default image.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_home_search_media_url"><?php esc_html_e('Homepage search big media', 'rifnote-search'); ?></label></th>
                            <td>
                                <div class="rs-media-field">
                                    <input id="rifnote_home_search_media_url" class="large-text rs-media-url" type="url" name="rifnote_home_search_media_url" value="<?php echo esc_attr(get_option('rifnote_home_search_media_url', '')); ?>" placeholder="Image, GIF, MP4/WebM, YouTube, or Vimeo URL" />
                                    <p>
                                        <button type="button" class="button rs-media-picker" data-target="#rifnote_home_search_media_url" data-title="<?php esc_attr_e('Choose homepage media', 'rifnote-search'); ?>" data-button="<?php esc_attr_e('Use media', 'rifnote-search'); ?>"><?php esc_html_e('Choose from Media Library', 'rifnote-search'); ?></button>
                                        <button type="button" class="button rs-media-clear" data-target="#rifnote_home_search_media_url"><?php esc_html_e('Clear', 'rifnote-search'); ?></button>
                                    </p>
                                </div>
                                <p>
                                    <select name="rifnote_home_search_media_type">
                                        <?php foreach (array('image' => __('Image / GIF', 'rifnote-search'), 'video' => __('Uploaded video', 'rifnote-search'), 'embed' => __('YouTube / Vimeo', 'rifnote-search')) as $type => $label) : ?>
                                            <option value="<?php echo esc_attr($type); ?>" <?php selected(get_option('rifnote_home_search_media_type', 'image'), $type); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </p>
                                <p>
                                    <label for="rifnote_home_search_media_link_url"><strong><?php esc_html_e('Media click URL', 'rifnote-search'); ?></strong></label><br />
                                    <input id="rifnote_home_search_media_link_url" class="large-text" type="url" name="rifnote_home_search_media_link_url" value="<?php echo esc_attr(get_option('rifnote_home_search_media_link_url', '')); ?>" placeholder="<?php echo esc_attr(home_url('/search/?q=world cup final')); ?>" />
                                </p>
                                <p class="description"><?php esc_html_e('Optional media shown as the main homepage visual. Supports images, GIFs, uploaded MP4/WebM files, YouTube links and Vimeo links. The media click URL applies to images and uploaded videos.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h2><?php esc_html_e('Typography', 'rifnote-search'); ?></h2>
                <p><?php esc_html_e('Tune the platform font stack and story reading sizes from one place. Normal Google Fonts load automatically; Google Sans uses a Google-style stack fallback.', 'rifnote-search'); ?></p>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="rifnote_typography_heading_font"><?php esc_html_e('Heading font', 'rifnote-search'); ?></label></th>
                            <td>
                                <select id="rifnote_typography_heading_font" name="rifnote_typography_heading_font">
                                    <?php foreach (self::google_font_choices() as $font => $label) : ?>
                                        <option value="<?php echo esc_attr($font); ?>" <?php selected(get_option('rifnote_typography_heading_font', 'Google Sans'), $font); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e('Used for headers, story titles, navigation labels and key UI headings.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_typography_body_font"><?php esc_html_e('Reading font', 'rifnote-search'); ?></label></th>
                            <td>
                                <select id="rifnote_typography_body_font" name="rifnote_typography_body_font">
                                    <?php foreach (self::google_font_choices() as $font => $label) : ?>
                                        <option value="<?php echo esc_attr($font); ?>" <?php selected(get_option('rifnote_typography_body_font', 'Roboto'), $font); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e('Used for article body copy, captions, forms and general reading.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_typography_story_title_size"><?php esc_html_e('Story title size', 'rifnote-search'); ?></label></th>
                            <td>
                                <input id="rifnote_typography_story_title_size" class="regular-text code" type="text" name="rifnote_typography_story_title_size" value="<?php echo esc_attr(self::sanitize_css_size(get_option('rifnote_typography_story_title_size', 'clamp(1.95rem, 3.45vw, 3.25rem)'))); ?>" />
                                <label style="margin-left:12px;" for="rifnote_typography_story_title_weight"><?php esc_html_e('Weight', 'rifnote-search'); ?></label>
                                <input id="rifnote_typography_story_title_weight" class="small-text" type="number" min="100" max="950" step="10" name="rifnote_typography_story_title_weight" value="<?php echo esc_attr(self::sanitize_font_weight(get_option('rifnote_typography_story_title_weight', 840))); ?>" />
                                <p class="description"><?php esc_html_e('Accepts px, rem, em, %, or a clamp() value. Controls the BBC-style post title card.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_typography_body_size"><?php esc_html_e('Article body size', 'rifnote-search'); ?></label></th>
                            <td>
                                <input id="rifnote_typography_body_size" class="regular-text code" type="text" name="rifnote_typography_body_size" value="<?php echo esc_attr(self::sanitize_css_size(get_option('rifnote_typography_body_size', 'clamp(1.02rem, 1vw, 1.1rem)'))); ?>" />
                                <label style="margin-left:12px;" for="rifnote_typography_body_weight"><?php esc_html_e('Weight', 'rifnote-search'); ?></label>
                                <input id="rifnote_typography_body_weight" class="small-text" type="number" min="100" max="950" step="10" name="rifnote_typography_body_weight" value="<?php echo esc_attr(self::sanitize_font_weight(get_option('rifnote_typography_body_weight', 430))); ?>" />
                                <p class="description"><?php esc_html_e('Controls the reading rhythm inside story pages and WordPress content views.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php
                $force_data_api_check = !empty($_GET['rifnote_data_api_check']) && !empty($_GET['rifnote_data_api_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['rifnote_data_api_nonce'])), 'rifnote_data_api_check');
                $data_api_health = class_exists('Rifnote_Search_Data_API') ? Rifnote_Search_Data_API::health($force_data_api_check) : array('ok' => false, 'message' => __('Data API bridge is not loaded.', 'rifnote-search'));
                $data_api_stats = class_exists('Rifnote_Search_Data_API') ? Rifnote_Search_Data_API::stats($force_data_api_check) : array('ok' => false);
                $data_api_counts = is_array($data_api_stats['counts'] ?? null) ? $data_api_stats['counts'] : array();
                $data_api_last = get_option('rifnote_data_api_last_check', array());
                ?>
                <h2><?php esc_html_e('Rifnote Data Engine', 'rifnote-search'); ?></h2>
                <p><?php esc_html_e('Connect WordPress to the external PostgreSQL warehouse for RSS, social, YouTube and high-volume imported content. Manual WordPress stories stay local.', 'rifnote-search'); ?></p>
                <div class="rifnote-admin-card" style="max-width:1120px;margin:12px 0 18px;padding:14px 16px;border:1px solid #dcdcde;background:#fff;border-radius:10px;">
                    <h3 style="margin-top:0;"><?php esc_html_e('Data API health', 'rifnote-search'); ?></h3>
                    <p>
                        <strong><?php esc_html_e('Status:', 'rifnote-search'); ?></strong>
                        <span style="color:<?php echo !empty($data_api_health['ok']) ? '#047857' : '#b42318'; ?>;">
                            <?php echo esc_html(!empty($data_api_health['ok']) ? __('Connected', 'rifnote-search') : __('Not connected', 'rifnote-search')); ?>
                        </span>
                        &nbsp;·&nbsp;
                        <?php echo esc_html((string) ($data_api_health['message'] ?? ($data_api_health['ok'] ? 'OK' : ''))); ?>
                    </p>
                    <?php if (!empty($data_api_last['checked_at'])) : ?>
                        <p class="description"><?php echo esc_html(sprintf(__('Last checked: %s', 'rifnote-search'), get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($data_api_last['checked_at'])), 'M j, Y H:i'))); ?></p>
                    <?php endif; ?>
                    <?php if (class_exists('Rifnote_Search_Data_API')) : ?>
                        <p class="description">
                            <?php
                            $token_fingerprint = Rifnote_Search_Data_API::token_fingerprint();
                            echo esc_html($token_fingerprint ? sprintf(__('Token saved · fingerprint %s', 'rifnote-search'), $token_fingerprint) : __('No Data API token is saved yet.', 'rifnote-search'));
                            if (!empty($data_api_last['auth_headers']) && is_array($data_api_last['auth_headers'])) {
                                echo esc_html(' · ' . sprintf(__('Auth headers sent: %s', 'rifnote-search'), implode(', ', array_map('sanitize_text_field', $data_api_last['auth_headers']))));
                            }
                            ?>
                        </p>
                    <?php endif; ?>
                    <p style="margin:10px 0 0;">
                        <a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(add_query_arg('rifnote_data_api_check', '1'), 'rifnote_data_api_check', 'rifnote_data_api_nonce')); ?>"><?php esc_html_e('Check Data API now', 'rifnote-search'); ?></a>
                    </p>
                    <?php if (!empty($data_api_stats['ok'])) : ?>
                        <div style="display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:10px;margin-top:14px;">
                            <p style="margin:0;padding:10px;background:#f6f7f7;border-radius:8px;"><strong><?php echo esc_html(number_format_i18n((int) ($data_api_counts['sources'] ?? 0))); ?></strong><br /><?php esc_html_e('Sources', 'rifnote-search'); ?></p>
                            <p style="margin:0;padding:10px;background:#f6f7f7;border-radius:8px;"><strong><?php echo esc_html(number_format_i18n((int) ($data_api_counts['feed_channels'] ?? 0))); ?></strong><br /><?php esc_html_e('Feeds', 'rifnote-search'); ?></p>
                            <p style="margin:0;padding:10px;background:#f6f7f7;border-radius:8px;"><strong><?php echo esc_html(number_format_i18n((int) ($data_api_counts['external_items'] ?? 0))); ?></strong><br /><?php esc_html_e('Items', 'rifnote-search'); ?></p>
                            <p style="margin:0;padding:10px;background:#f6f7f7;border-radius:8px;"><strong><?php echo esc_html(number_format_i18n((int) ($data_api_counts['ingest_runs'] ?? 0))); ?></strong><br /><?php esc_html_e('Ingest runs', 'rifnote-search'); ?></p>
                            <p style="margin:0;padding:10px;background:#f6f7f7;border-radius:8px;"><strong><?php echo esc_html(number_format_i18n((int) ($data_api_counts['items_24h'] ?? 0))); ?></strong><br /><?php esc_html_e('Last 24h', 'rifnote-search'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Data API bridge', 'rifnote-search'); ?></th>
                            <td>
                                <label>
                                    <input type="hidden" name="rifnote_data_api_enabled" value="0" />
                                    <input type="checkbox" name="rifnote_data_api_enabled" value="1" <?php checked((bool) get_option('rifnote_data_api_enabled', false)); ?> />
                                    <?php esc_html_e('Enable the external Rifnote data engine.', 'rifnote-search'); ?>
                                </label>
                                <p>
                                    <label>
                                        <input type="hidden" name="rifnote_data_api_merge_search" value="0" />
                                        <input type="checkbox" name="rifnote_data_api_merge_search" value="1" <?php checked((bool) get_option('rifnote_data_api_merge_search', true)); ?> />
                                        <?php esc_html_e('Blend data engine stories into frontend search results.', 'rifnote-search'); ?>
                                    </label>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_data_api_url"><?php esc_html_e('Data API URL', 'rifnote-search'); ?></label></th>
                            <td>
                                <input id="rifnote_data_api_url" class="regular-text" type="url" name="rifnote_data_api_url" value="<?php echo esc_attr(get_option('rifnote_data_api_url', '')); ?>" placeholder="https://data.rifnote.com" />
                                <p class="description"><?php esc_html_e('Use the public proxy URL. The API itself remains bound to localhost on the VPS.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_data_api_token"><?php esc_html_e('Data API token', 'rifnote-search'); ?></label></th>
                            <td>
                                <input id="rifnote_data_api_token" class="regular-text" type="password" name="rifnote_data_api_token" value="<?php echo esc_attr(get_option('rifnote_data_api_token', '')); ?>" autocomplete="off" />
                                <p class="description"><?php esc_html_e('Bearer token from the data-engine env file. Prefer RIFNOTE_DATA_API_TOKEN in production when possible.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Performance', 'rifnote-search'); ?></th>
                            <td>
                                <label><?php esc_html_e('Timeout', 'rifnote-search'); ?> <input type="number" min="3" max="20" name="rifnote_data_api_timeout" value="<?php echo esc_attr(get_option('rifnote_data_api_timeout', 8)); ?>" /> <?php esc_html_e('sec', 'rifnote-search'); ?></label>
                                <label style="margin-left:12px;"><?php esc_html_e('Search cache', 'rifnote-search'); ?> <input type="number" min="0" max="900" name="rifnote_data_api_cache_ttl" value="<?php echo esc_attr(get_option('rifnote_data_api_cache_ttl', 120)); ?>" /> <?php esc_html_e('sec', 'rifnote-search'); ?></label>
                                <p class="description"><?php esc_html_e('Short cache keeps search fresh without turning every keystroke or tab click into a VPS hit.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h2><?php esc_html_e('Smart RSS Queue', 'rifnote-search'); ?></h2>
                <p><?php esc_html_e('Add many RSS feeds and let Rifnote check them in small rotating batches every 5 minutes, instead of hammering the server all at once.', 'rifnote-search'); ?></p>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Smart RSS', 'rifnote-search'); ?></th>
                            <td>
                                <label>
                                    <input type="hidden" name="rifnote_smart_rss_enabled" value="0" />
                                    <input type="checkbox" name="rifnote_smart_rss_enabled" value="1" <?php checked((bool) get_option('rifnote_smart_rss_enabled', true)); ?> />
                                    <?php esc_html_e('Enable 5-minute rotating RSS queue.', 'rifnote-search'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_smart_rss_list"><?php esc_html_e('RSS list', 'rifnote-search'); ?></label></th>
                            <td>
                                <textarea id="rifnote_smart_rss_list" class="large-text code" rows="10" name="rifnote_smart_rss_list" placeholder="Punch | https://punchng.com/feed/ | News | publish"><?php echo esc_textarea(get_option('rifnote_smart_rss_list', '')); ?></textarea>
                                <p class="description"><?php esc_html_e('One feed per line. Format: Source name | Feed URL | Category | publish/review. Only Feed URL is required. Lines starting with # are ignored.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Queue safety', 'rifnote-search'); ?></th>
                            <td>
                                <label><?php esc_html_e('Feeds per cron run', 'rifnote-search'); ?> <input type="number" min="1" max="100" name="rifnote_smart_rss_batch_size" value="<?php echo esc_attr(get_option('rifnote_smart_rss_batch_size', 25)); ?>" /></label>
                                <label style="margin-left:12px;"><?php esc_html_e('Items per feed', 'rifnote-search'); ?> <input type="number" min="1" max="30" name="rifnote_smart_rss_items_per_feed" value="<?php echo esc_attr(get_option('rifnote_smart_rss_items_per_feed', 10)); ?>" /></label>
                                <label style="margin-left:12px;"><?php esc_html_e('HTTP timeout', 'rifnote-search'); ?> <input type="number" min="3" max="20" name="rifnote_smart_rss_timeout" value="<?php echo esc_attr(get_option('rifnote_smart_rss_timeout', 8)); ?>" /> <?php esc_html_e('sec', 'rifnote-search'); ?></label>
                                <p class="description"><?php esc_html_e('For 1000 feeds, 25 per run means roughly 40 runs. At 5 minutes each, every feed is checked about every 3h20m without server spikes.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Default handling', 'rifnote-search'); ?></th>
                            <td>
                                <label>
                                    <input type="hidden" name="rifnote_smart_rss_auto_publish" value="0" />
                                    <input type="checkbox" name="rifnote_smart_rss_auto_publish" value="1" <?php checked((bool) get_option('rifnote_smart_rss_auto_publish', true)); ?> />
                                    <?php esc_html_e('Auto-publish new Smart RSS stories by default. Use “review” on a feed line to override.', 'rifnote-search'); ?>
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php
                $smart_rss_statuses = Rifnote_Search_Ingestion::smart_statuses(8);
                $smart_rss_total = count(Rifnote_Search_Ingestion::smart_feeds());
                $smart_rss_preview = Rifnote_Search_Ingestion::queue_preview();
                $smart_rss_logs = Rifnote_Search_Ingestion::recent_logs(8);
                ?>
                <p class="description">
                    <?php echo esc_html(sprintf(__('Smart RSS queue: %1$d feed(s), cursor %2$d, schedule %3$s.', 'rifnote-search'), $smart_rss_total, (int) get_option('rifnote_smart_rss_cursor', 0), self::format_cron_status($smart_rss_preview['schedule'] ?? Rifnote_Search_Ingestion::schedule_status()))); ?>
                </p>
                <div class="rifnote-admin-card" style="max-width:1120px;margin:12px 0 18px;padding:14px 16px;border:1px solid #dcdcde;background:#fff;border-radius:10px;">
                    <h3 style="margin-top:0;"><?php esc_html_e('Next RSS run preview', 'rifnote-search'); ?></h3>
                    <p>
                        <strong><?php esc_html_e('Schedule:', 'rifnote-search'); ?></strong>
                        <?php echo esc_html(self::format_cron_status($smart_rss_preview['schedule'] ?? array())); ?>
                        &nbsp;·&nbsp;
                        <strong><?php esc_html_e('Expected max items:', 'rifnote-search'); ?></strong>
                        <?php echo esc_html(number_format_i18n((int) ($smart_rss_preview['expected_max_items'] ?? 0))); ?>
                        &nbsp;·&nbsp;
                        <strong><?php esc_html_e('Feeds this pass:', 'rifnote-search'); ?></strong>
                        <?php echo esc_html(number_format_i18n(count($smart_rss_preview['feeds'] ?? array()))); ?>
                    </p>
                    <?php if (!empty($smart_rss_preview['schedule']['is_overdue'])) : ?>
                        <p style="color:#b32d2e;margin-top:0;"><?php esc_html_e('This Smart RSS event is overdue. Click repair to clear only the RSS hook and queue it for the next minute.', 'rifnote-search'); ?></p>
                    <?php endif; ?>
                    <p style="margin:10px 0 0;">
                        <a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(add_query_arg('rifnote_rss_repair', '1'), 'rifnote_repair_rss_schedule', 'rifnote_rss_repair_nonce')); ?>"><?php esc_html_e('Repair RSS schedule', 'rifnote-search'); ?></a>
                    </p>
                    <?php if (!empty($smart_rss_preview['feeds'])) : ?>
                        <table class="widefat striped" style="margin-top:10px;">
                            <thead><tr><th><?php esc_html_e('Expected source', 'rifnote-search'); ?></th><th><?php esc_html_e('Expected URL to pull', 'rifnote-search'); ?></th><th><?php esc_html_e('Category', 'rifnote-search'); ?></th><th><?php esc_html_e('Mode', 'rifnote-search'); ?></th><th><?php esc_html_e('Max items', 'rifnote-search'); ?></th></tr></thead>
                            <tbody>
                                <?php foreach (array_slice($smart_rss_preview['feeds'], 0, 12) as $feed) : ?>
                                    <tr>
                                        <td><?php echo esc_html($feed['name'] ?: wp_parse_url($feed['feed_url'], PHP_URL_HOST)); ?></td>
                                        <td><code><?php echo esc_html($feed['feed_url']); ?></code></td>
                                        <td><?php echo esc_html($feed['category'] ?: __('Auto', 'rifnote-search')); ?></td>
                                        <td><?php echo esc_html($feed['mode'] ?? 'publish'); ?></td>
                                        <td><?php echo esc_html(number_format_i18n((int) ($smart_rss_preview['items_per_feed'] ?? 0))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if (count($smart_rss_preview['feeds']) > 12) : ?>
                            <p class="description"><?php echo esc_html(sprintf(__('Showing first 12 of %d feeds expected in the next pass.', 'rifnote-search'), count($smart_rss_preview['feeds']))); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php if ($smart_rss_logs) : ?>
                    <h3><?php esc_html_e('Recent RSS run log', 'rifnote-search'); ?></h3>
                    <table class="widefat striped" style="max-width:1120px;margin:10px 0 22px;">
                        <thead><tr><th><?php esc_html_e('Time', 'rifnote-search'); ?></th><th><?php esc_html_e('Status', 'rifnote-search'); ?></th><th><?php esc_html_e('Summary', 'rifnote-search'); ?></th><th><?php esc_html_e('Expected URLs', 'rifnote-search'); ?></th></tr></thead>
                        <tbody>
                            <?php foreach ($smart_rss_logs as $log) : $summary = is_array($log['summary'] ?? null) ? $log['summary'] : array(); ?>
                                <tr>
                                    <td><?php echo esc_html(!empty($log['created_at']) ? get_date_from_gmt($log['created_at'], 'M j, Y H:i') : ''); ?></td>
                                    <td><?php echo esc_html($log['status'] ?? 'info'); ?></td>
                                    <td>
                                        <strong><?php echo esc_html($log['message'] ?? ''); ?></strong><br />
                                        <small>
                                            <?php echo esc_html(sprintf(
                                                __('Checked %1$d · Created %2$d · Published %3$d · Recovered %4$d · Duplicates %5$d · Errors %6$d · Expected max %7$d', 'rifnote-search'),
                                                (int) ($summary['checked'] ?? 0),
                                                (int) ($summary['created'] ?? 0),
                                                (int) ($summary['published'] ?? 0),
                                                (int) ($summary['recovered'] ?? 0),
                                                (int) ($summary['duplicates'] ?? 0),
                                                (int) ($summary['errors'] ?? 0),
                                                (int) ($summary['expected_max_items'] ?? 0)
                                            )); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php foreach (array_slice($summary['expected_urls'] ?? array(), 0, 4) as $expected) : ?>
                                            <code style="display:block;margin-bottom:4px;"><?php echo esc_html($expected['feed_url'] ?? ''); ?></code>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <?php if ($smart_rss_statuses) : ?>
                    <table class="widefat striped" style="max-width:1120px;margin:10px 0 22px;">
                        <thead><tr><th><?php esc_html_e('Feed', 'rifnote-search'); ?></th><th><?php esc_html_e('Status', 'rifnote-search'); ?></th><th><?php esc_html_e('Indexed', 'rifnote-search'); ?></th><th><?php esc_html_e('Last checked', 'rifnote-search'); ?></th><th><?php esc_html_e('Error', 'rifnote-search'); ?></th></tr></thead>
                        <tbody>
                            <?php foreach ($smart_rss_statuses as $feed_url => $status) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($status['name'] ?? wp_parse_url($feed_url, PHP_URL_HOST)); ?></strong><br /><small><?php echo esc_html($feed_url); ?></small></td>
                                    <td><?php echo esc_html($status['status'] ?? 'unknown'); ?></td>
                                    <td><?php echo esc_html(number_format_i18n((int) ($status['items_indexed'] ?? 0))); ?></td>
                                    <td><?php echo esc_html(!empty($status['last_checked']) ? get_date_from_gmt($status['last_checked'], 'M j, H:i') : ''); ?></td>
                                    <td><?php echo esc_html(wp_trim_words($status['last_error'] ?? '', 12)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <h2><?php esc_html_e('AI Answers', 'rifnote-search'); ?></h2>
                <p><?php esc_html_e('Controls for grounded answers, source-backed summaries and answer caching.', 'rifnote-search'); ?></p>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('AI answers', 'rifnote-search'); ?></th>
                            <td>
                                <label>
                                    <input type="hidden" name="rifnote_ai_enabled" value="0" />
                                    <input type="checkbox" name="rifnote_ai_enabled" value="1" <?php checked((bool) get_option('rifnote_ai_enabled', true)); ?> />
                                    <?php esc_html_e('Enable grounded AI answers when an API key is configured.', 'rifnote-search'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Search page display', 'rifnote-search'); ?></th>
                            <td>
                                <p>
                                    <label>
                                        <input type="hidden" name="rifnote_show_ai_cards" value="0" />
                                        <input type="checkbox" name="rifnote_show_ai_cards" value="1" <?php checked((bool) get_option('rifnote_show_ai_cards', true)); ?> />
                                        <?php esc_html_e('Show AI answer cards on search results pages.', 'rifnote-search'); ?>
                                    </label>
                                </p>
                                <p>
                                    <label>
                                        <input type="hidden" name="rifnote_show_story_excerpts" value="0" />
                                        <input type="checkbox" name="rifnote_show_story_excerpts" value="1" <?php checked((bool) get_option('rifnote_show_story_excerpts', true)); ?> />
                                        <?php esc_html_e('Show story excerpts in homepage notes and search result cards.', 'rifnote-search'); ?>
                                    </label>
                                </p>
                                <p class="description"><?php esc_html_e('Turn these off for a cleaner headline-first experience.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_openai_model"><?php esc_html_e('OpenAI model', 'rifnote-search'); ?></label></th>
                            <td><input id="rifnote_openai_model" class="regular-text" name="rifnote_openai_model" value="<?php echo esc_attr(get_option('rifnote_openai_model', 'gpt-5.4-mini')); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_openai_api_key"><?php esc_html_e('OpenAI API key', 'rifnote-search'); ?></label></th>
                            <td>
                                <input id="rifnote_openai_api_key" class="regular-text" type="password" name="rifnote_openai_api_key" value="<?php echo esc_attr(get_option('rifnote_openai_api_key', '')); ?>" autocomplete="off" />
                                <p class="description"><?php esc_html_e('Prefer RIFNOTE_OPENAI_API_KEY or OPENAI_API_KEY in the environment for production.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_ai_cache_ttl"><?php esc_html_e('AI cache TTL', 'rifnote-search'); ?></label></th>
                            <td><input id="rifnote_ai_cache_ttl" type="number" min="300" max="21600" name="rifnote_ai_cache_ttl" value="<?php echo esc_attr(get_option('rifnote_ai_cache_ttl', 1800)); ?>" /> seconds</td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_ai_max_answer_length"><?php esc_html_e('Max answer length', 'rifnote-search'); ?></label></th>
                            <td><input id="rifnote_ai_max_answer_length" type="number" min="280" max="1500" name="rifnote_ai_max_answer_length" value="<?php echo esc_attr(get_option('rifnote_ai_max_answer_length', 900)); ?>" /> characters</td>
                        </tr>
                    </tbody>
                </table>

                <h2><?php esc_html_e('Delivery Providers', 'rifnote-search'); ?></h2>
                <p><?php esc_html_e('Email and push delivery settings for newsletters, alerts and native/PWA handoff.', 'rifnote-search'); ?></p>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="rifnote_email_provider"><?php esc_html_e('Email provider', 'rifnote-search'); ?></label></th>
                            <td>
                                <select id="rifnote_email_provider" name="rifnote_email_provider">
                                    <option value="wp_mail" <?php selected(get_option('rifnote_email_provider', 'wp_mail'), 'wp_mail'); ?>>wp_mail</option>
                                    <option value="resend" <?php selected(get_option('rifnote_email_provider', 'wp_mail'), 'resend'); ?>>Resend</option>
                                </select>
                                <input class="regular-text" name="rifnote_email_from" value="<?php echo esc_attr(get_option('rifnote_email_from', get_option('admin_email'))); ?>" placeholder="news@rifnote.com" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_email_api_key"><?php esc_html_e('Email API key', 'rifnote-search'); ?></label></th>
                            <td><input id="rifnote_email_api_key" class="regular-text" type="password" name="rifnote_email_api_key" value="<?php echo esc_attr(get_option('rifnote_email_api_key', '')); ?>" autocomplete="off" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_push_provider"><?php esc_html_e('Push provider', 'rifnote-search'); ?></label></th>
                            <td>
                                <select id="rifnote_push_provider" name="rifnote_push_provider">
                                    <option value="local" <?php selected(get_option('rifnote_push_provider', 'local'), 'local'); ?>><?php esc_html_e('Local queue', 'rifnote-search'); ?></option>
                                    <option value="webpush" <?php selected(get_option('rifnote_push_provider', 'local'), 'webpush'); ?>>Web Push</option>
                                </select>
                                <p><input class="regular-text" name="rifnote_vapid_public_key" value="<?php echo esc_attr(get_option('rifnote_vapid_public_key', '')); ?>" placeholder="<?php esc_attr_e('VAPID public key', 'rifnote-search'); ?>" /></p>
                                <p><input class="regular-text" type="password" name="rifnote_vapid_private_key" value="<?php echo esc_attr(get_option('rifnote_vapid_private_key', '')); ?>" placeholder="<?php esc_attr_e('VAPID private key', 'rifnote-search'); ?>" autocomplete="off" /></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h2><?php esc_html_e('Live Sidebar Data', 'rifnote-search'); ?></h2>
                <p><?php esc_html_e('Free provider-backed weather and market cards. The browser reads Rifnote REST only; WordPress fetches providers and stores responses in the database.', 'rifnote-search'); ?></p>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="rifnote_live_data_poll_ttl"><?php esc_html_e('Widget cache TTL', 'rifnote-search'); ?></label></th>
                            <td>
                                <input id="rifnote_live_data_poll_ttl" type="number" min="300" max="1800" step="60" name="rifnote_live_data_poll_ttl" value="<?php echo esc_attr(get_option('rifnote_live_data_poll_ttl', 900)); ?>" /> seconds
                                <p class="description"><?php esc_html_e('900 seconds is 15 minutes. Keep this conservative for free provider limits.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_live_weather_locations"><?php esc_html_e('Weather locations', 'rifnote-search'); ?></label></th>
                            <td>
                                <textarea id="rifnote_live_weather_locations" class="large-text code" rows="4" name="rifnote_live_weather_locations"><?php echo esc_textarea(get_option('rifnote_live_weather_locations', "Lagos:6.5244:3.3792\nAbuja:9.0765:7.3986\nLondon:51.5072:-0.1276")); ?></textarea>
                                <p class="description"><?php esc_html_e('One per line: Label:latitude:longitude. Provider: Open-Meteo.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_live_market_pairs"><?php esc_html_e('Market FX pairs', 'rifnote-search'); ?></label></th>
                            <td>
                                <textarea id="rifnote_live_market_pairs" class="large-text code" rows="4" name="rifnote_live_market_pairs"><?php echo esc_textarea(get_option('rifnote_live_market_pairs', "NGN/USD:USD:NGN\nEUR/USD:EUR:USD\nGBP/USD:GBP:USD")); ?></textarea>
                                <p class="description"><?php esc_html_e('One per line: Label:base:symbol. Providers: Frankfurter first, ExchangeRate open endpoint fallback for unsupported currencies.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h2><?php esc_html_e('TheNewsAPI News Import', 'rifnote-search'); ?></h2>
                <p><?php esc_html_e('Pull top stories from TheNewsAPI into WordPress posts for testing. Frontend search continues to read Rifnote database content only.', 'rifnote-search'); ?></p>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Importer status', 'rifnote-search'); ?></th>
                            <td>
                                <label>
                                    <input type="hidden" name="rifnote_thenewsapi_enabled" value="0" />
                                    <input type="checkbox" name="rifnote_thenewsapi_enabled" value="1" <?php checked((bool) get_option('rifnote_thenewsapi_enabled', false)); ?> />
                                    <?php esc_html_e('Enable TheNewsAPI imports.', 'rifnote-search'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_thenewsapi_key"><?php esc_html_e('API token', 'rifnote-search'); ?></label></th>
                            <td>
                                <input id="rifnote_thenewsapi_key" class="regular-text" type="password" name="rifnote_thenewsapi_key" value="<?php echo esc_attr(get_option('rifnote_thenewsapi_key', '')); ?>" autocomplete="off" />
                                <p class="description"><?php esc_html_e('Use the free TheNewsAPI token while testing locally. Keep frontend requests disabled; imports are server-side only.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_thenewsapi_locale"><?php esc_html_e('Locales', 'rifnote-search'); ?></label></th>
                            <td>
                                <input id="rifnote_thenewsapi_locale" class="regular-text" name="rifnote_thenewsapi_locale" value="<?php echo esc_attr(get_option('rifnote_thenewsapi_locale', 'ng,us,gb')); ?>" />
                                <p class="description"><?php esc_html_e('Comma-separated country locales, for example ng,us,gb.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_thenewsapi_language"><?php esc_html_e('Language', 'rifnote-search'); ?></label></th>
                            <td><input id="rifnote_thenewsapi_language" class="small-text" name="rifnote_thenewsapi_language" value="<?php echo esc_attr(get_option('rifnote_thenewsapi_language', 'en')); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_thenewsapi_categories"><?php esc_html_e('Categories', 'rifnote-search'); ?></label></th>
                            <td>
                                <input id="rifnote_thenewsapi_categories" class="regular-text" name="rifnote_thenewsapi_categories" value="<?php echo esc_attr(get_option('rifnote_thenewsapi_categories', 'general,business,tech,politics,sports')); ?>" />
                                <p class="description"><?php esc_html_e('Comma-separated TheNewsAPI categories to import.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_thenewsapi_limit"><?php esc_html_e('Articles per import', 'rifnote-search'); ?></label></th>
                            <td><input id="rifnote_thenewsapi_limit" type="number" min="1" max="25" name="rifnote_thenewsapi_limit" value="<?php echo esc_attr(get_option('rifnote_thenewsapi_limit', 10)); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_thenewsapi_interval"><?php esc_html_e('Auto import interval', 'rifnote-search'); ?></label></th>
                            <td>
                                <select id="rifnote_thenewsapi_interval" name="rifnote_thenewsapi_interval">
                                    <?php foreach (Rifnote_Search_News_API::interval_options() as $interval => $label) : ?>
                                        <option value="<?php echo esc_attr($interval); ?>" <?php selected(get_option('rifnote_thenewsapi_interval', 'rifnote_every_six_hours'), $interval); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e('The importer only schedules when enabled and an API token is saved. Six hours is safest for free-plan testing.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Publish behavior', 'rifnote-search'); ?></th>
                            <td>
                                <label>
                                    <input type="hidden" name="rifnote_thenewsapi_auto_publish" value="0" />
                                    <input type="checkbox" name="rifnote_thenewsapi_auto_publish" value="1" <?php checked((bool) get_option('rifnote_thenewsapi_auto_publish', true)); ?> />
                                    <?php esc_html_e('Auto-publish imported stories and index them for search.', 'rifnote-search'); ?>
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h2><?php esc_html_e('Monetization', 'rifnote-search'); ?></h2>
                <p><?php esc_html_e('Sponsor request checkout and campaign handoff settings.', 'rifnote-search'); ?></p>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="rifnote_sponsor_checkout_url"><?php esc_html_e('Sponsor checkout URL', 'rifnote-search'); ?></label></th>
                            <td><input id="rifnote_sponsor_checkout_url" class="regular-text" type="url" name="rifnote_sponsor_checkout_url" value="<?php echo esc_attr(get_option('rifnote_sponsor_checkout_url', '')); ?>" placeholder="https://checkout.example/rifnote" /></td>
                        </tr>
                    </tbody>
                </table>

                <h2><?php esc_html_e('Search Quality', 'rifnote-search'); ?></h2>
                <p><?php esc_html_e('Query expansion controls used by autocomplete, search fallback and relevance recovery.', 'rifnote-search'); ?></p>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="rifnote_search_synonyms"><?php esc_html_e('Search synonyms', 'rifnote-search'); ?></label></th>
                            <td>
                                <textarea id="rifnote_search_synonyms" class="large-text" rows="4" name="rifnote_search_synonyms"><?php echo esc_textarea(get_option('rifnote_search_synonyms', "football=soccer\ntransfer=move,deal\npolitics=election,government")); ?></textarea>
                                <p class="description"><?php esc_html_e('One mapping per line: primary=alternate,alternate.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h2><?php esc_html_e('Football Live Scores', 'rifnote-search'); ?></h2>
                <p><?php esc_html_e('Football now has its own control center for API-Football credentials, league/cup watchlists, cache controls, endpoint checks and live-score operations.', 'rifnote-search'); ?></p>
                <p><a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=rifnote-search-football')); ?>"><?php esc_html_e('Open Football Control Center', 'rifnote-search'); ?></a></p>

                <h2><?php esc_html_e('Native Release', 'rifnote-search'); ?></h2>
                <p><?php esc_html_e('Package identifiers and release notes for native wrapper builds.', 'rifnote-search'); ?></p>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Native release IDs', 'rifnote-search'); ?></th>
                            <td>
                                <p><input class="regular-text" name="rifnote_native_ios_bundle_id" value="<?php echo esc_attr(get_option('rifnote_native_ios_bundle_id', '')); ?>" placeholder="com.rifnote.search" /></p>
                                <p><input class="regular-text" name="rifnote_native_android_package" value="<?php echo esc_attr(get_option('rifnote_native_android_package', '')); ?>" placeholder="com.rifnote.search" /></p>
                                <textarea class="large-text" rows="3" name="rifnote_release_notes" placeholder="<?php esc_attr_e('Release notes for native wrapper and beta testers', 'rifnote-search'); ?>"><?php echo esc_textarea(get_option('rifnote_release_notes', '')); ?></textarea>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(); ?>
            </form>
            <?php endif; ?>

            <?php if (self::is_section('football')) : ?>
            <?php
            $football_last_test = $football_health['last_test'];
            $football_last_backfill = get_option('rifnote_api_football_last_history_backfill', array());
            $football_status = $football_health['configured'] ? __('Connected', 'rifnote-search') : __('Needs API key', 'rifnote-search');
            ?>
            <h2><?php esc_html_e('Football Control Center', 'rifnote-search'); ?></h2>
            <p><?php esc_html_e('Run the live-score product from one place: API-Football credentials, league/cup IDs, live polling cadence, watchlist coverage, cache controls and REST endpoints.', 'rifnote-search'); ?></p>

            <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;max-width:1120px;margin:16px 0;">
                <div class="card">
                    <h3 style="margin-top:0;"><?php echo esc_html($football_status); ?></h3>
                    <p><?php echo esc_html($football_health['configured'] ? sprintf(__('API-Football key is configured for %s.', 'rifnote-search'), $football_health['provider']) : __('Add an API key before using real live scores.', 'rifnote-search')); ?></p>
                </div>
                <div class="card">
                    <h3 style="margin-top:0;"><?php echo esc_html(number_format_i18n((int) $football_health['competitions_count'])); ?></h3>
                    <p><?php esc_html_e('Watched leagues/cups', 'rifnote-search'); ?></p>
                </div>
                <div class="card">
                    <h3 style="margin-top:0;"><?php echo esc_html((int) $football_health['live_cache_ttl']); ?>s</h3>
                    <p><?php esc_html_e('Live polling cache', 'rifnote-search'); ?></p>
                </div>
                <div class="card">
                    <h3 style="margin-top:0;"><?php echo esc_html($football_health['last_live_sync'] ? get_date_from_gmt($football_health['last_live_sync'], 'M j, H:i') : __('Never', 'rifnote-search')); ?></h3>
                    <p><?php esc_html_e('Last live sync', 'rifnote-search'); ?></p>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;max-width:1120px;margin:16px 0;">
                <div class="card">
                    <h3 style="margin-top:0;"><?php echo esc_html(number_format_i18n((int) $football_usage['requests'])); ?></h3>
                    <p><?php esc_html_e('API calls logged in 7 days', 'rifnote-search'); ?></p>
                </div>
                <div class="card">
                    <h3 style="margin-top:0;"><?php echo esc_html(number_format_i18n((int) $football_usage['fixtures_returned'])); ?></h3>
                    <p><?php esc_html_e('Fixtures returned', 'rifnote-search'); ?></p>
                </div>
                <div class="card">
                    <h3 style="margin-top:0;"><?php echo null !== $football_usage['last_remaining'] ? esc_html(number_format_i18n((int) $football_usage['last_remaining'])) : esc_html__('Unknown', 'rifnote-search'); ?></h3>
                    <p><?php esc_html_e('Last quota remaining', 'rifnote-search'); ?></p>
                </div>
                <div class="card">
                    <h3 style="margin-top:0;"><?php echo esc_html($football_usage['last_request_at'] ? get_date_from_gmt($football_usage['last_request_at'], 'M j, H:i') : __('Never', 'rifnote-search')); ?></h3>
                    <p><?php esc_html_e('Last API request', 'rifnote-search'); ?></p>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:minmax(0,1.35fr) minmax(320px,.65fr);gap:18px;align-items:start;max-width:1280px;">
                <div class="card">
                    <h2><?php esc_html_e('API-Football configuration', 'rifnote-search'); ?></h2>
                    <form method="post" action="options.php">
                        <?php settings_fields('rifnote_search_football_settings'); ?>
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="rifnote_api_football_key"><?php esc_html_e('API key', 'rifnote-search'); ?></label></th>
                                    <td>
                                        <input id="rifnote_api_football_key" class="regular-text" type="password" name="rifnote_api_football_key" value="<?php echo esc_attr($football_settings['api_key']); ?>" autocomplete="off" />
                                        <p class="description"><?php esc_html_e('For v3.football.api-sports.io, this is sent as x-apisports-key. For RapidAPI hosts, it is sent as x-rapidapi-key. Store this server-side only; the frontend calls Rifnote REST endpoints.', 'rifnote-search'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="rifnote_api_football_host"><?php esc_html_e('API host', 'rifnote-search'); ?></label></th>
                                    <td>
                                        <input id="rifnote_api_football_host" class="regular-text" name="rifnote_api_football_host" value="<?php echo esc_attr($football_settings['host']); ?>" />
                                        <p class="description"><?php echo esc_html(sprintf(__('Default: v3.football.api-sports.io. Current auth mode: %s.', 'rifnote-search'), $football_settings['provider'])); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="rifnote_api_football_timezone"><?php esc_html_e('Timezone', 'rifnote-search'); ?></label></th>
                                    <td><input id="rifnote_api_football_timezone" class="regular-text" name="rifnote_api_football_timezone" value="<?php echo esc_attr($football_settings['timezone']); ?>" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Polling cadence', 'rifnote-search'); ?></th>
                                    <td>
                                        <div style="display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:12px;max-width:760px;">
                                            <label>
                                                <strong><?php esc_html_e('Live matches API refresh', 'rifnote-search'); ?></strong><br />
                                                <input type="number" min="60" max="300" name="rifnote_api_football_live_cache_ttl" value="<?php echo esc_attr($football_settings['live_cache_ttl']); ?>" /> <?php esc_html_e('seconds', 'rifnote-search'); ?>
                                                <span class="description" style="display:block;"><?php esc_html_e('Recommended: 60 seconds for real matchday feel.', 'rifnote-search'); ?></span>
                                            </label>
                                            <label>
                                                <strong><?php esc_html_e('Daily fixtures refresh', 'rifnote-search'); ?></strong><br />
                                                <input type="number" min="60" max="3600" name="rifnote_api_football_fixture_cache_ttl" value="<?php echo esc_attr($football_settings['fixture_cache_ttl']); ?>" /> <?php esc_html_e('seconds', 'rifnote-search'); ?>
                                                <span class="description" style="display:block;"><?php esc_html_e('Recommended: 300 seconds for today/tomorrow match windows.', 'rifnote-search'); ?></span>
                                            </label>
                                            <label>
                                                <strong><?php esc_html_e('Upcoming matches refresh', 'rifnote-search'); ?></strong><br />
                                                <input type="number" min="60" max="3600" name="rifnote_api_football_upcoming_cache_ttl" value="<?php echo esc_attr($football_settings['upcoming_cache_ttl']); ?>" /> <?php esc_html_e('seconds', 'rifnote-search'); ?>
                                                <span class="description" style="display:block;"><?php esc_html_e('Recommended: 300 seconds for the next 24 hours panel.', 'rifnote-search'); ?></span>
                                            </label>
                                            <label>
                                                <strong><?php esc_html_e('Finished matches refresh', 'rifnote-search'); ?></strong><br />
                                                <input type="number" min="60" max="7200" name="rifnote_api_football_finished_cache_ttl" value="<?php echo esc_attr($football_settings['finished_cache_ttl']); ?>" /> <?php esc_html_e('seconds', 'rifnote-search'); ?>
                                                <span class="description" style="display:block;"><?php esc_html_e('Recommended: 900 seconds once matches are settled.', 'rifnote-search'); ?></span>
                                            </label>
                                            <label>
                                                <strong><?php esc_html_e('Match details refresh', 'rifnote-search'); ?></strong><br />
                                                <input type="number" min="60" max="7200" name="rifnote_api_football_details_cache_ttl" value="<?php echo esc_attr($football_settings['details_cache_ttl']); ?>" /> <?php esc_html_e('seconds', 'rifnote-search'); ?>
                                                <span class="description" style="display:block;"><?php esc_html_e('Recommended: 600 seconds for lineups, events, stats and H2H.', 'rifnote-search'); ?></span>
                                            </label>
                                        </div>
                                        <p class="description"><?php esc_html_e('The frontend still reads from the Rifnote database. These values control how often WordPress is allowed to refresh each football data family from API-Football.', 'rifnote-search'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="rifnote_api_football_competitions"><?php esc_html_e('League/cup watchlist', 'rifnote-search'); ?></label></th>
                                    <td>
                                        <textarea id="rifnote_api_football_competitions" class="large-text code" rows="10" name="rifnote_api_football_competitions"><?php echo esc_textarea(get_option('rifnote_api_football_competitions', "39:2025:Premier League\n2:2025:UEFA Champions League\n3:2025:UEFA Europa League\n1:2026:FIFA World Cup")); ?></textarea>
                                        <p class="description"><?php esc_html_e('One per line: league_id:season:label. Examples: 39:2025:Premier League, 2:2025:UEFA Champions League, 3:2025:UEFA Europa League, 1:2026:FIFA World Cup.', 'rifnote-search'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Teams and homepage matches', 'rifnote-search'); ?></th>
                                    <td>
                                        <p>
                                            <strong><?php esc_html_e('Current visibility:', 'rifnote-search'); ?></strong>
                                            <?php echo !empty($football_team_watchlist['enabled']) ? esc_html(sprintf(_n('%d selected team', '%d selected teams', count($football_team_watchlist['labels']), 'rifnote-search'), count($football_team_watchlist['labels']))) : esc_html__('All teams from watched competitions', 'rifnote-search'); ?>
                                        </p>
                                        <p><a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=rifnote-search-football-curation')); ?>"><?php esc_html_e('Open Football Curation', 'rifnote-search'); ?></a></p>
                                        <p class="description"><?php esc_html_e('Team visibility and homepage featured scoreboards now live on a dedicated curation page with league filters, checkbox selection and match picking.', 'rifnote-search'); ?></p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <?php submit_button(__('Save Football settings', 'rifnote-search')); ?>
                    </form>
                </div>

                <div class="card">
                    <h2><?php esc_html_e('Operations', 'rifnote-search'); ?></h2>
                    <p><?php esc_html_e('Use these after changing league IDs, API keys, cache windows or provider configuration.', 'rifnote-search'); ?></p>
                    <form method="post" style="margin-bottom:10px;">
                        <?php wp_nonce_field('rifnote_football_action', 'rifnote_football_nonce'); ?>
                        <input type="hidden" name="rifnote_football_action" value="clear_cache" />
                        <?php submit_button(__('Clear football cache', 'rifnote-search'), 'secondary', 'submit', false); ?>
                    </form>
                    <form method="post" style="margin-bottom:10px;">
                        <?php wp_nonce_field('rifnote_football_action', 'rifnote_football_nonce'); ?>
                        <input type="hidden" name="rifnote_football_action" value="test_live" />
                        <?php submit_button(__('Test live endpoint', 'rifnote-search'), 'secondary', 'submit', false); ?>
                    </form>
                    <form method="post" style="margin-bottom:16px;">
                        <?php wp_nonce_field('rifnote_football_action', 'rifnote_football_nonce'); ?>
                        <input type="hidden" name="rifnote_football_action" value="test_watchlist" />
                        <?php submit_button(__('Test watchlist today', 'rifnote-search'), 'secondary', 'submit', false); ?>
                    </form>
                    <form method="post" style="margin-bottom:16px;">
                        <?php wp_nonce_field('rifnote_football_action', 'rifnote_football_nonce'); ?>
                        <input type="hidden" name="rifnote_football_action" value="test_upcoming" />
                        <?php submit_button(__('Test upcoming fixtures', 'rifnote-search'), 'secondary', 'submit', false); ?>
                    </form>

                    <hr />
                    <h3><?php esc_html_e('Backfill teams & players', 'rifnote-search'); ?></h3>
                    <p><?php esc_html_e('Pull historical fixtures for every configured league/cup. Match details create richer player profiles, but each detail sync uses extra API calls.', 'rifnote-search'); ?></p>
                    <form method="post" style="display:grid;gap:10px;margin-bottom:16px;">
                        <?php wp_nonce_field('rifnote_football_action', 'rifnote_football_nonce'); ?>
                        <input type="hidden" name="rifnote_football_action" value="backfill_history" />
                        <label>
                            <?php esc_html_e('Years to pull', 'rifnote-search'); ?>
                            <input type="number" min="1" max="8" name="rifnote_football_history_years" value="1" />
                        </label>
                        <label>
                            <?php esc_html_e('Fixture details to sync this run', 'rifnote-search'); ?>
                            <input type="number" min="0" max="250" name="rifnote_football_history_detail_limit" value="25" />
                        </label>
                        <p class="description"><?php esc_html_e('Tip: start with 1 year and 25 details. Increase slowly if your API quota is comfortable.', 'rifnote-search'); ?></p>
                        <?php submit_button(__('Pull history into database', 'rifnote-search'), 'primary', 'submit', false); ?>
                    </form>

                    <?php if (is_array($football_last_backfill) && $football_last_backfill) : ?>
                        <table class="widefat striped" style="margin-bottom:16px;">
                            <tbody>
                                <tr><th><?php esc_html_e('Last backfill', 'rifnote-search'); ?></th><td><?php echo esc_html(!empty($football_last_backfill['finished_at']) ? get_date_from_gmt($football_last_backfill['finished_at'], 'M j, Y H:i') : ''); ?></td></tr>
                                <tr><th><?php esc_html_e('Fixtures stored', 'rifnote-search'); ?></th><td><?php echo esc_html(number_format_i18n((int) ($football_last_backfill['fixtures'] ?? 0))); ?></td></tr>
                                <tr><th><?php esc_html_e('Details synced', 'rifnote-search'); ?></th><td><?php echo esc_html(number_format_i18n((int) ($football_last_backfill['details_synced'] ?? 0))); ?></td></tr>
                                <tr><th><?php esc_html_e('Warnings', 'rifnote-search'); ?></th><td><?php echo esc_html(number_format_i18n(count($football_last_backfill['errors'] ?? array()))); ?></td></tr>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <h3><?php esc_html_e('Last test', 'rifnote-search'); ?></h3>
                    <?php if (!$football_last_test) : ?>
                        <p><?php esc_html_e('No football API test has been run yet.', 'rifnote-search'); ?></p>
                    <?php else : ?>
                        <table class="widefat striped">
                            <tbody>
                                <tr><th><?php esc_html_e('Target', 'rifnote-search'); ?></th><td><?php echo esc_html($football_last_test['target'] ?? ''); ?></td></tr>
                                <tr><th><?php esc_html_e('Provider', 'rifnote-search'); ?></th><td><?php echo esc_html($football_last_test['provider'] ?? ''); ?></td></tr>
                                <tr><th><?php esc_html_e('Fixtures', 'rifnote-search'); ?></th><td><?php echo esc_html(number_format_i18n((int) ($football_last_test['fixtures'] ?? 0))); ?></td></tr>
                                <tr><th><?php esc_html_e('Tested', 'rifnote-search'); ?></th><td><?php echo esc_html(!empty($football_last_test['tested_at']) ? get_date_from_gmt($football_last_test['tested_at'], 'M j, Y H:i') : ''); ?></td></tr>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;align-items:start;max-width:1280px;margin-top:18px;">
                <div class="card">
                    <h2><?php esc_html_e('Parsed league/cup IDs', 'rifnote-search'); ?></h2>
                    <table class="widefat striped">
                        <thead><tr><th><?php esc_html_e('Label', 'rifnote-search'); ?></th><th><?php esc_html_e('League/Cup ID', 'rifnote-search'); ?></th><th><?php esc_html_e('Season', 'rifnote-search'); ?></th></tr></thead>
                        <tbody>
                            <?php if (!$football_competitions) : ?>
                                <tr><td colspan="3"><?php esc_html_e('No competitions configured yet.', 'rifnote-search'); ?></td></tr>
                            <?php endif; ?>
                            <?php foreach ($football_competitions as $competition) : ?>
                                <tr>
                                    <td><?php echo esc_html($competition['label']); ?></td>
                                    <td><?php echo esc_html((int) $competition['league_id']); ?></td>
                                    <td><?php echo esc_html((int) $competition['season']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card">
                    <h2><?php esc_html_e('REST endpoints', 'rifnote-search'); ?></h2>
                    <table class="widefat striped">
                        <tbody>
                            <?php foreach ($football_health['endpoints'] as $label => $endpoint) : ?>
                                <tr>
                                    <th><?php echo esc_html(ucfirst($label)); ?></th>
                                    <td><code><?php echo esc_html($endpoint); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="description"><?php esc_html_e('The frontend should poll these Rifnote REST endpoints, never API-Football directly. That keeps credentials private and lets WordPress enforce caching.', 'rifnote-search'); ?></p>
                </div>
            </div>

            <div class="card" style="max-width:1280px;margin-top:18px;">
                <h2><?php esc_html_e('Recent API-Football usage', 'rifnote-search'); ?></h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('When', 'rifnote-search'); ?></th>
                            <th><?php esc_html_e('Endpoint', 'rifnote-search'); ?></th>
                            <th><?php esc_html_e('HTTP', 'rifnote-search'); ?></th>
                            <th><?php esc_html_e('Fixtures', 'rifnote-search'); ?></th>
                            <th><?php esc_html_e('Remaining', 'rifnote-search'); ?></th>
                            <th><?php esc_html_e('Duration', 'rifnote-search'); ?></th>
                            <th><?php esc_html_e('Error', 'rifnote-search'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$football_recent_usage) : ?>
                            <tr><td colspan="7"><?php esc_html_e('No API-Football calls logged yet. Use Test live or Test upcoming to verify the provider.', 'rifnote-search'); ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($football_recent_usage as $usage) : ?>
                            <tr>
                                <td><?php echo esc_html(get_date_from_gmt($usage['created_at'], 'M j, H:i:s')); ?></td>
                                <td><code><?php echo esc_html($usage['endpoint']); ?></code></td>
                                <td><?php echo esc_html((int) $usage['http_status']); ?></td>
                                <td><?php echo esc_html(number_format_i18n((int) $usage['response_count'])); ?></td>
                                <td><?php echo null !== $usage['quota_remaining'] ? esc_html(number_format_i18n((int) $usage['quota_remaining'])) : '-'; ?></td>
                                <td><?php echo esc_html((int) $usage['duration_ms']); ?>ms</td>
                                <td><?php echo esc_html(wp_trim_words($usage['error_message'], 12)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (self::is_section('football-curation')) : ?>
            <?php
            $football_selected_team_ids = isset($football_team_watchlist['ids']) && is_array($football_team_watchlist['ids']) ? $football_team_watchlist['ids'] : array();
            $football_selected_team_labels = isset($football_team_watchlist['labels']) && is_array($football_team_watchlist['labels']) ? $football_team_watchlist['labels'] : array();
            $football_filtered_matches = array_values(array_filter((array) $football_today_matches, function($fixture) use ($football_curation_competition) {
                if (empty($football_curation_competition)) {
                    return true;
                }

                return (int) ($fixture['league']['id'] ?? 0) === (int) ($football_curation_competition['league_id'] ?? 0)
                    && (int) ($fixture['league']['season'] ?? 0) === (int) ($football_curation_competition['season'] ?? 0);
            }));
            ?>
            <h2><?php esc_html_e('Football Curation', 'rifnote-search'); ?></h2>
            <p><?php esc_html_e('Pick the teams Rifnote should surface, then choose the saved match or matches that can become homepage scoreboards.', 'rifnote-search'); ?></p>
            <?php if (!empty($football_curation_sync['skipped'])) : ?>
                <div class="notice notice-info inline"><p><?php esc_html_e('The selected date was checked recently, so Rifnote is showing the saved fixture cache.', 'rifnote-search'); ?></p></div>
            <?php elseif (isset($football_curation_sync['synced']) && null !== $football_curation_sync['synced']) : ?>
                <div class="notice notice-success inline"><p><?php echo esc_html(sprintf(_n('Synced %d fixture for this date across configured leagues.', 'Synced %d fixtures for this date across configured leagues.', (int) $football_curation_sync['synced'], 'rifnote-search'), (int) $football_curation_sync['synced'])); ?></p></div>
            <?php endif; ?>
            <?php if (!empty($football_curation_sync['errors'])) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e('Some leagues returned API errors. Check Football usage logs for the exact provider response.', 'rifnote-search'); ?></p></div>
            <?php endif; ?>

            <div class="card" style="max-width:1280px;margin:16px 0;">
                <h2><?php esc_html_e('Filter the room', 'rifnote-search'); ?></h2>
                <form method="get" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;">
                    <input type="hidden" name="page" value="rifnote-search-football-curation" />
                    <label>
                        <strong><?php esc_html_e('League / cup', 'rifnote-search'); ?></strong><br />
                        <select name="football_league" style="min-width:280px;">
                            <option value=""><?php esc_html_e('All configured leagues/cups', 'rifnote-search'); ?></option>
                            <?php foreach ($football_competitions as $competition) : ?>
                                <?php $competition_key = self::football_competition_key($competition); ?>
                                <option value="<?php echo esc_attr($competition_key); ?>" <?php selected($football_curation_league_key, $competition_key); ?>>
                                    <?php echo esc_html(sprintf('%s · %d', $competition['label'], (int) $competition['season'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <strong><?php esc_html_e('Match date', 'rifnote-search'); ?></strong><br />
                        <input type="date" name="football_date" value="<?php echo esc_attr($football_curation_date); ?>" />
                    </label>
                    <?php submit_button(__('Load teams and matches', 'rifnote-search'), 'secondary', 'submit', false); ?>
                </form>
                <p class="description"><?php esc_html_e('Teams come from saved fixtures in the database. Matches come from the selected date, also from the database.', 'rifnote-search'); ?></p>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('rifnote_search_football_curation_settings'); ?>
                <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;align-items:start;max-width:1280px;">
                    <div class="card">
                        <h2><?php esc_html_e('Team visibility', 'rifnote-search'); ?></h2>
                        <p><?php esc_html_e('Select the teams whose matches should appear in live score surfaces. Leave everything unchecked to show all configured competition teams.', 'rifnote-search'); ?></p>
                        <p>
                            <input type="search" class="regular-text" id="rifnote-football-curation-team-search" placeholder="<?php esc_attr_e('Search teams, IDs, leagues...', 'rifnote-search'); ?>" />
                            <span class="description"><?php echo esc_html(sprintf(_n('%d selected now', '%d selected now', count($football_selected_team_labels), 'rifnote-search'), count($football_selected_team_labels))); ?></span>
                        </p>
                        <input type="hidden" name="rifnote_api_football_team_watchlist[]" value="" />
                        <div style="max-height:620px;overflow:auto;border:1px solid #dcdcde;border-radius:10px;background:#fff;">
                            <table class="widefat striped" id="rifnote-football-curation-team-list">
                                <thead>
                                    <tr>
                                        <th style="width:42px;"><?php esc_html_e('Use', 'rifnote-search'); ?></th>
                                        <th><?php esc_html_e('Team', 'rifnote-search'); ?></th>
                                        <th><?php esc_html_e('League', 'rifnote-search'); ?></th>
                                        <th><?php esc_html_e('Seen', 'rifnote-search'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$football_stored_teams) : ?>
                                        <tr><td colspan="4"><?php esc_html_e('No teams saved for this filter yet. Sync fixtures for the configured leagues, then come back here.', 'rifnote-search'); ?></td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($football_stored_teams as $team) : ?>
                                        <?php
                                        $team_id = absint($team['team_id'] ?? 0);
                                        $team_name = sanitize_text_field($team['team_name'] ?? '');
                                        $league_name = sanitize_text_field($team['league_name'] ?? __('Football', 'rifnote-search'));
                                        $season = absint($team['league_season'] ?? 0);
                                        $line = $team_id . ':' . $team_name;
                                        $checked = $team_id && !empty($football_selected_team_ids[$team_id]);
                                        ?>
                                        <tr data-curation-search="<?php echo esc_attr(strtolower($team_name . ' ' . $team_id . ' ' . $league_name . ' ' . $season)); ?>">
                                            <td><input type="checkbox" name="rifnote_api_football_team_watchlist[]" value="<?php echo esc_attr($line); ?>" <?php checked($checked); ?> /></td>
                                            <td><strong><?php echo esc_html($team_name); ?></strong><br /><code><?php echo esc_html($team_id); ?></code></td>
                                            <td><?php echo esc_html($league_name); ?><br /><span class="description"><?php echo esc_html($season); ?></span></td>
                                            <td><?php echo esc_html(number_format_i18n((int) ($team['appearances'] ?? 0))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card">
                        <h2><?php esc_html_e('Homepage scoreboards', 'rifnote-search'); ?></h2>
                        <p><?php esc_html_e('Pick one or more matches from the selected date. Multiple matches show as a homepage carousel.', 'rifnote-search'); ?></p>
                        <p>
                            <input type="search" class="regular-text" id="rifnote-football-curation-match-search" placeholder="<?php esc_attr_e('Search matches, teams, fixture IDs...', 'rifnote-search'); ?>" />
                            <span class="description"><?php echo esc_html(sprintf(_n('%d featured match selected', '%d featured matches selected', count($football_featured_ids), 'rifnote-search'), count($football_featured_ids))); ?></span>
                        </p>
                        <input type="hidden" name="rifnote_home_featured_football_matches[]" value="" />
                        <div style="max-height:620px;overflow:auto;border:1px solid #dcdcde;border-radius:10px;background:#fff;">
                            <table class="widefat striped" id="rifnote-football-curation-match-list">
                                <thead>
                                    <tr>
                                        <th style="width:42px;"><?php esc_html_e('Feature', 'rifnote-search'); ?></th>
                                        <th><?php esc_html_e('Match', 'rifnote-search'); ?></th>
                                        <th><?php esc_html_e('Kickoff', 'rifnote-search'); ?></th>
                                        <th><?php esc_html_e('Status', 'rifnote-search'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$football_filtered_matches) : ?>
                                        <tr><td colspan="4"><?php esc_html_e('No saved matches found for this date/filter. Sync fixtures first or choose another date.', 'rifnote-search'); ?></td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($football_filtered_matches as $fixture) : ?>
                                        <?php
                                        $fixture_id = absint($fixture['id'] ?? 0);
                                        $home = sanitize_text_field($fixture['home']['name'] ?? '');
                                        $away = sanitize_text_field($fixture['away']['name'] ?? '');
                                        $league = sanitize_text_field($fixture['league']['name'] ?? __('Football', 'rifnote-search'));
                                        $status = sanitize_text_field($fixture['status_short'] ?? '');
                                        $kickoff_ts = !empty($fixture['date']) ? strtotime((string) $fixture['date']) : 0;
                                        $kickoff = $kickoff_ts ? wp_date('M j, H:i', $kickoff_ts) : __('TBD', 'rifnote-search');
                                        $goals_home = isset($fixture['goals']['home']) ? (string) $fixture['goals']['home'] : '-';
                                        $goals_away = isset($fixture['goals']['away']) ? (string) $fixture['goals']['away'] : '-';
                                        ?>
                                        <tr data-curation-search="<?php echo esc_attr(strtolower($league . ' ' . $home . ' ' . $away . ' ' . $fixture_id . ' ' . $status)); ?>">
                                            <td><input type="checkbox" name="rifnote_home_featured_football_matches[]" value="<?php echo esc_attr($fixture_id); ?>" <?php checked(in_array($fixture_id, $football_featured_ids, true)); ?> /></td>
                                            <td><strong><?php echo esc_html($home . ' vs ' . $away); ?></strong><br /><span class="description"><?php echo esc_html($league); ?> · <code><?php echo esc_html($fixture_id); ?></code></span></td>
                                            <td><?php echo esc_html($kickoff); ?></td>
                                            <td><?php echo esc_html(($status ? $status : 'TBD') . ' · ' . $goals_home . '-' . $goals_away); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php submit_button(__('Save football curation', 'rifnote-search')); ?>
            </form>
            <script>
            (function () {
                function bindSearch(inputId, tableId) {
                    var input = document.getElementById(inputId);
                    var table = document.getElementById(tableId);

                    if (!input || !table) {
                        return;
                    }

                    input.addEventListener('input', function () {
                        var query = input.value.trim().toLowerCase();
                        table.querySelectorAll('tbody tr[data-curation-search]').forEach(function (row) {
                            row.style.display = !query || row.getAttribute('data-curation-search').indexOf(query) !== -1 ? '' : 'none';
                        });
                    });
                }

                bindSearch('rifnote-football-curation-team-search', 'rifnote-football-curation-team-list');
                bindSearch('rifnote-football-curation-match-search', 'rifnote-football-curation-match-list');
            })();
            </script>
            <?php endif; ?>

            <?php if (self::is_section('release')) : ?>
            <hr />
            <h2><?php esc_html_e('Launch hardening', 'rifnote-search'); ?></h2>
            <p><?php esc_html_e('Pre-launch readiness for performance, mobile QA, security controls, cache headers, error logs and rollback planning.', 'rifnote-search'); ?></p>
            <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;max-width:1120px;">
                <?php foreach ($launch_report as $check) : ?>
                    <?php
                    $status_color = 'pass' === $check['status'] ? '#15803d' : ('fail' === $check['status'] ? '#ed1c24' : '#a16207');
                    ?>
                    <div class="card">
                        <h3 style="margin-top:0;color:<?php echo esc_attr($status_color); ?>;"><?php echo esc_html(strtoupper($check['status'])); ?></h3>
                        <strong><?php echo esc_html($check['label']); ?></strong>
                        <p><?php echo esc_html($check['detail']); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;align-items:start;margin-top:12px;">
                <div>
                    <h3><?php esc_html_e('Recent plugin error logs', 'rifnote-search'); ?></h3>
                    <form method="post" style="margin-bottom:8px;">
                        <?php wp_nonce_field('rifnote_hardening_action', 'rifnote_hardening_nonce'); ?>
                        <input type="hidden" name="rifnote_hardening_action" value="clear_error_logs" />
                        <?php submit_button(__('Clear logs', 'rifnote-search'), 'secondary small', 'submit', false); ?>
                    </form>
                    <table class="widefat striped">
                        <thead><tr><th><?php esc_html_e('Time', 'rifnote-search'); ?></th><th><?php esc_html_e('Area', 'rifnote-search'); ?></th><th><?php esc_html_e('Severity', 'rifnote-search'); ?></th><th><?php esc_html_e('Message', 'rifnote-search'); ?></th></tr></thead>
                        <tbody>
                            <?php if (!$error_logs) : ?>
                                <tr><td colspan="4"><?php esc_html_e('No plugin errors logged.', 'rifnote-search'); ?></td></tr>
                            <?php endif; ?>
                            <?php foreach ($error_logs as $log) : ?>
                                <tr>
                                    <td><?php echo esc_html(get_date_from_gmt($log['created_at'], 'M j, Y H:i')); ?></td>
                                    <td><?php echo esc_html($log['area']); ?></td>
                                    <td><?php echo esc_html($log['severity']); ?></td>
                                    <td><?php echo esc_html(wp_trim_words($log['message'], 18)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div>
                    <h3><?php esc_html_e('Backup and rollback plan', 'rifnote-search'); ?></h3>
                    <form method="post">
                        <?php wp_nonce_field('rifnote_hardening_action', 'rifnote_hardening_nonce'); ?>
                        <input type="hidden" name="rifnote_hardening_action" value="save_backup_plan" />
                        <textarea class="large-text" rows="10" name="rifnote_backup_plan"><?php echo esc_textarea($backup_plan); ?></textarea>
                        <?php submit_button(__('Save backup plan', 'rifnote-search'), 'secondary'); ?>
                    </form>
                </div>
            </div>

            <hr />
            <h2><?php esc_html_e('Release readiness', 'rifnote-search'); ?></h2>
            <p><?php esc_html_e('PWA, native wrapper, delivery, page, sitemap and index checks for the next release.', 'rifnote-search'); ?></p>
            <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;max-width:920px;">
                <div class="card"><h3><?php echo esc_html(ucwords(str_replace('_', ' ', $release_readiness['status']))); ?></h3><p><?php esc_html_e('Release status', 'rifnote-search'); ?></p></div>
                <div class="card"><h3><?php echo esc_html(number_format_i18n((int) $release_readiness['failures'])); ?></h3><p><?php esc_html_e('Blocking checks', 'rifnote-search'); ?></p></div>
                <div class="card"><h3><?php echo esc_html(number_format_i18n((int) $release_readiness['warnings'])); ?></h3><p><?php esc_html_e('Warnings', 'rifnote-search'); ?></p></div>
            </div>
            <table class="widefat striped" style="margin-top:12px;">
                <thead><tr><th><?php esc_html_e('Check', 'rifnote-search'); ?></th><th><?php esc_html_e('Status', 'rifnote-search'); ?></th><th><?php esc_html_e('Detail', 'rifnote-search'); ?></th></tr></thead>
                <tbody>
                    <?php foreach ($release_readiness['checks'] as $check) : ?>
                        <tr>
                            <td><?php echo esc_html($check['label']); ?></td>
                            <td><?php echo esc_html(strtoupper($check['status'])); ?></td>
                            <td><?php echo esc_html($check['detail']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if (self::is_section('operations')) : ?>
            <hr />
            <h2><?php esc_html_e('Public beta', 'rifnote-search'); ?></h2>
            <p><?php esc_html_e('Track Rifnote audience feedback, adjust ranking while the beta is live, and onboard the first publisher cohort.', 'rifnote-search'); ?></p>
            <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;max-width:1120px;">
                <div class="card"><h3><?php echo esc_html(number_format_i18n((int) $beta_summary['feedback_total'])); ?></h3><p><?php esc_html_e('Feedback items', 'rifnote-search'); ?></p></div>
                <div class="card"><h3><?php echo esc_html(number_format_i18n((int) $beta_summary['new_feedback'])); ?></h3><p><?php esc_html_e('New feedback', 'rifnote-search'); ?></p></div>
                <div class="card"><h3><?php echo esc_html(number_format_i18n((int) $beta_summary['active_rules'])); ?></h3><p><?php esc_html_e('Active ranking rules', 'rifnote-search'); ?></p></div>
                <div class="card"><h3><?php echo esc_html(number_format_i18n((int) $beta_summary['publisher_targets'])); ?></h3><p><?php esc_html_e('Publisher targets', 'rifnote-search'); ?></p></div>
            </div>
            <div style="display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,.85fr);gap:18px;align-items:start;margin-top:12px;">
                <div>
                    <h3><?php esc_html_e('Feedback queue', 'rifnote-search'); ?></h3>
                    <table class="widefat striped">
                        <thead><tr><th><?php esc_html_e('Feedback', 'rifnote-search'); ?></th><th><?php esc_html_e('Context', 'rifnote-search'); ?></th><th><?php esc_html_e('Status', 'rifnote-search'); ?></th><th><?php esc_html_e('Actions', 'rifnote-search'); ?></th></tr></thead>
                        <tbody>
                            <?php if (!$beta_feedback) : ?>
                                <tr><td colspan="4"><?php esc_html_e('No beta feedback yet.', 'rifnote-search'); ?></td></tr>
                            <?php endif; ?>
                            <?php foreach ($beta_feedback as $feedback) : ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html(ucfirst($feedback['feedback_type'])); ?></strong>
                                        <?php if (!empty($feedback['rating'])) : ?><small><?php echo esc_html(' - ' . (int) $feedback['rating'] . '/5'); ?></small><?php endif; ?><br />
                                        <small><?php echo esc_html($feedback['requester_email']); ?></small>
                                        <p><?php echo esc_html(wp_trim_words($feedback['message'], 24)); ?></p>
                                    </td>
                                    <td>
                                        <small><?php echo esc_html($feedback['query_text']); ?></small><br />
                                        <?php if (!empty($feedback['context_url'])) : ?>
                                            <a href="<?php echo esc_url($feedback['context_url']); ?>" target="_blank" rel="noreferrer"><?php esc_html_e('Open context', 'rifnote-search'); ?></a>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html(ucfirst($feedback['status'])); ?></td>
                                    <td>
                                        <?php foreach (array('reviewing', 'done', 'dismissed') as $status) : ?>
                                            <form method="post" style="display:inline-block;margin-right:4px;">
                                                <?php wp_nonce_field('rifnote_beta_action', 'rifnote_beta_nonce'); ?>
                                                <input type="hidden" name="rifnote_beta_action" value="feedback_status" />
                                                <input type="hidden" name="rifnote_feedback_id" value="<?php echo esc_attr($feedback['id']); ?>" />
                                                <input type="hidden" name="rifnote_feedback_status" value="<?php echo esc_attr($status); ?>" />
                                                <?php submit_button(ucfirst($status), 'secondary small', 'submit', false); ?>
                                            </form>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div>
                    <h3><?php esc_html_e('Ranking adjustments', 'rifnote-search'); ?></h3>
                    <form method="post" style="margin-bottom:12px;">
                        <?php wp_nonce_field('rifnote_beta_action', 'rifnote_beta_nonce'); ?>
                        <input type="hidden" name="rifnote_beta_action" value="add_ranking_rule" />
                        <p>
                            <select name="rifnote_rule_type">
                                <option value="keyword"><?php esc_html_e('Keyword', 'rifnote-search'); ?></option>
                                <option value="category"><?php esc_html_e('Category slug', 'rifnote-search'); ?></option>
                                <option value="source_domain"><?php esc_html_e('Source domain', 'rifnote-search'); ?></option>
                                <option value="source_name"><?php esc_html_e('Source name', 'rifnote-search'); ?></option>
                            </select>
                            <input name="rifnote_rule_target" placeholder="<?php esc_attr_e('target', 'rifnote-search'); ?>" />
                            <input name="rifnote_rule_boost" type="number" min="-0.5" max="0.5" step="0.01" value="0.05" />
                        </p>
                        <p><input class="regular-text" name="rifnote_rule_notes" placeholder="<?php esc_attr_e('Why this adjustment?', 'rifnote-search'); ?>" /></p>
                        <?php submit_button(__('Add ranking rule', 'rifnote-search'), 'secondary small', 'submit', false); ?>
                    </form>
                    <table class="widefat striped">
                        <thead><tr><th><?php esc_html_e('Rule', 'rifnote-search'); ?></th><th><?php esc_html_e('Boost', 'rifnote-search'); ?></th><th><?php esc_html_e('Status', 'rifnote-search'); ?></th><th><?php esc_html_e('Action', 'rifnote-search'); ?></th></tr></thead>
                        <tbody>
                            <?php if (!$ranking_rules) : ?>
                                <tr><td colspan="4"><?php esc_html_e('No ranking rules yet.', 'rifnote-search'); ?></td></tr>
                            <?php endif; ?>
                            <?php foreach ($ranking_rules as $rule) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($rule['rule_type']); ?></strong><br /><small><?php echo esc_html($rule['target']); ?></small></td>
                                    <td><?php echo esc_html(number_format_i18n((float) $rule['boost'], 2)); ?></td>
                                    <td><?php echo esc_html(ucfirst($rule['status'])); ?></td>
                                    <td>
                                        <form method="post">
                                            <?php wp_nonce_field('rifnote_beta_action', 'rifnote_beta_nonce'); ?>
                                            <input type="hidden" name="rifnote_beta_action" value="ranking_rule_status" />
                                            <input type="hidden" name="rifnote_rule_id" value="<?php echo esc_attr($rule['id']); ?>" />
                                            <input type="hidden" name="rifnote_rule_status" value="<?php echo 'active' === $rule['status'] ? 'inactive' : 'active'; ?>" />
                                            <?php submit_button('active' === $rule['status'] ? __('Pause', 'rifnote-search') : __('Activate', 'rifnote-search'), 'secondary small', 'submit', false); ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <h3><?php esc_html_e('First publisher onboarding', 'rifnote-search'); ?></h3>
            <form method="post" style="margin-bottom:12px;">
                <?php wp_nonce_field('rifnote_beta_action', 'rifnote_beta_nonce'); ?>
                <input type="hidden" name="rifnote_beta_action" value="add_publisher_target" />
                <input name="rifnote_beta_publisher_name" placeholder="<?php esc_attr_e('Publisher name', 'rifnote-search'); ?>" />
                <input name="rifnote_beta_website_url" type="url" placeholder="https://publisher.com" />
                <input name="rifnote_beta_contact_email" type="email" placeholder="editor@publisher.com" />
                <select name="rifnote_beta_publisher_status"><option value="invited"><?php esc_html_e('Invited', 'rifnote-search'); ?></option><option value="onboarding"><?php esc_html_e('Onboarding', 'rifnote-search'); ?></option><option value="live"><?php esc_html_e('Live', 'rifnote-search'); ?></option></select>
                <input class="regular-text" name="rifnote_beta_notes" placeholder="<?php esc_attr_e('Notes', 'rifnote-search'); ?>" />
                <?php submit_button(__('Add publisher', 'rifnote-search'), 'secondary small', 'submit', false); ?>
            </form>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e('Publisher', 'rifnote-search'); ?></th><th><?php esc_html_e('Email', 'rifnote-search'); ?></th><th><?php esc_html_e('Status', 'rifnote-search'); ?></th><th><?php esc_html_e('Next step', 'rifnote-search'); ?></th></tr></thead>
                <tbody>
                    <?php if (!$publisher_targets) : ?>
                        <tr><td colspan="4"><?php esc_html_e('No beta publishers added yet.', 'rifnote-search'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($publisher_targets as $target) : ?>
                        <tr>
                            <td><strong><?php echo esc_html($target['publisher_name']); ?></strong><br /><small><a href="<?php echo esc_url($target['website_url']); ?>" target="_blank" rel="noreferrer"><?php echo esc_html($target['website_url']); ?></a></small></td>
                            <td><?php echo esc_html($target['contact_email']); ?></td>
                            <td><?php echo esc_html(ucfirst($target['status'])); ?></td>
                            <td>
                                <?php foreach (array('onboarding', 'live', 'paused') as $status) : ?>
                                    <form method="post" style="display:inline-block;margin-right:4px;">
                                        <?php wp_nonce_field('rifnote_beta_action', 'rifnote_beta_nonce'); ?>
                                        <input type="hidden" name="rifnote_beta_action" value="publisher_target_status" />
                                        <input type="hidden" name="rifnote_beta_target_id" value="<?php echo esc_attr($target['id']); ?>" />
                                        <input type="hidden" name="rifnote_beta_target_status" value="<?php echo esc_attr($status); ?>" />
                                        <?php submit_button(ucfirst($status), 'secondary small', 'submit', false); ?>
                                    </form>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <hr />
            <h2><?php esc_html_e('Operations console', 'rifnote-search'); ?></h2>
            <p><?php esc_html_e('Daily newsroom operations: feed diagnostics, editorial alerts, ranking simulation and briefing payloads.', 'rifnote-search'); ?></p>
            <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;max-width:1120px;">
                <div class="card"><h3><?php echo esc_html(count($editorial_console['rising_queries'])); ?></h3><p><?php esc_html_e('Rising queries', 'rifnote-search'); ?></p></div>
                <div class="card"><h3><?php echo esc_html(count($editorial_console['feed_errors'])); ?></h3><p><?php esc_html_e('Feed alerts', 'rifnote-search'); ?></p></div>
                <div class="card"><h3><?php echo esc_html(count($editorial_console['legal_requests'])); ?></h3><p><?php esc_html_e('Legal queue', 'rifnote-search'); ?></p></div>
                <div class="card"><h3><?php echo esc_html(count($daily_briefing['stories'])); ?></h3><p><?php esc_html_e('Briefing stories', 'rifnote-search'); ?></p></div>
            </div>
            <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;align-items:start;margin-top:12px;">
                <div>
                    <h3><?php esc_html_e('Editorial cockpit', 'rifnote-search'); ?></h3>
                    <table class="widefat striped">
                        <tbody>
                            <tr><td><?php esc_html_e('Rising queries', 'rifnote-search'); ?></td><td><?php self::render_analytics_list($editorial_console['rising_queries']); ?></td></tr>
                            <tr><td><?php esc_html_e('No-result queue', 'rifnote-search'); ?></td><td><?php echo esc_html(count($editorial_console['no_result_queue'])); ?></td></tr>
                            <tr><td><?php esc_html_e('Feed errors', 'rifnote-search'); ?></td><td><?php echo esc_html(count($editorial_console['feed_errors'])); ?></td></tr>
                            <tr><td><?php esc_html_e('Beta feedback', 'rifnote-search'); ?></td><td><?php echo esc_html(count($editorial_console['beta_feedback'])); ?></td></tr>
                        </tbody>
                    </table>
                    <form method="post" style="margin-top:12px;">
                        <?php wp_nonce_field('rifnote_ops_action', 'rifnote_ops_nonce'); ?>
                        <input type="hidden" name="rifnote_ops_action" value="run_ingestion" />
                        <?php submit_button(__('Run ingestion now', 'rifnote-search'), 'secondary'); ?>
                    </form>
                    <h3><?php esc_html_e('Imported content cleanup', 'rifnote-search'); ?></h3>
                    <p><?php esc_html_e('Trash or delete old automated stories while protecting manually-added Rifnote Admin posts. CustomGPT/GPT stories are treated as imported content when the checkbox is enabled.', 'rifnote-search'); ?></p>
                    <div class="card" style="max-width:none;">
                        <h4 style="margin-top:0;"><?php echo esc_html(sprintf(__('Last preview: %d cleanup candidate(s)', 'rifnote-search'), (int) $cleanup_preview['count'])); ?></h4>
                        <?php if (!empty($cleanup_preview_payload['created_at'])) : ?>
                            <p class="description"><?php echo esc_html(sprintf(__('Preview generated %s. Page loads do not scan posts automatically.', 'rifnote-search'), get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($cleanup_preview_payload['created_at'])), 'M j, H:i'))); ?></p>
                        <?php else : ?>
                            <p class="description"><?php esc_html_e('No preview has been generated yet. Use the preview button below before cleanup.', 'rifnote-search'); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($cleanup_preview['breakdown'])) : ?>
                            <p><strong><?php esc_html_e('Why they match:', 'rifnote-search'); ?></strong></p>
                            <p>
                                <?php foreach (array_slice($cleanup_preview['breakdown'], 0, 6, true) as $reason => $count) : ?>
                                    <span style="display:inline-block;background:#f6f7f7;border:1px solid #dcdcde;border-radius:999px;padding:4px 9px;margin:0 4px 6px 0;"><?php echo esc_html($reason . ' ' . number_format_i18n((int) $count)); ?></span>
                                <?php endforeach; ?>
                            </p>
                        <?php endif; ?>
                        <?php if (!empty($cleanup_preview['samples'])) : ?>
                            <table class="widefat striped" style="margin:10px 0;">
                                <thead><tr><th><?php esc_html_e('Sample', 'rifnote-search'); ?></th><th><?php esc_html_e('Date', 'rifnote-search'); ?></th><th><?php esc_html_e('Marker', 'rifnote-search'); ?></th></tr></thead>
                                <tbody>
                                    <?php foreach ($cleanup_preview['samples'] as $sample) : ?>
                                        <tr>
                                            <td><a href="<?php echo esc_url($sample['edit_url']); ?>"><?php echo esc_html($sample['title']); ?></a></td>
                                            <td><?php echo esc_html($sample['date']); ?></td>
                                            <td><code><?php echo esc_html($sample['reason']); ?></code></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                        <form method="post">
                            <?php wp_nonce_field('rifnote_ops_action', 'rifnote_ops_nonce'); ?>
                            <p>
                                <label>
                                    <?php esc_html_e('Older than', 'rifnote-search'); ?>
                                    <input type="number" min="0" max="3650" name="rifnote_cleanup_days" value="30" style="width:80px;" />
                                    <?php esc_html_e('days', 'rifnote-search'); ?>
                                </label>
                                &nbsp;
                                <label>
                                    <?php esc_html_e('Max this run', 'rifnote-search'); ?>
                                    <input type="number" min="1" max="5000" name="rifnote_cleanup_limit" value="500" style="width:90px;" />
                                </label>
                            </p>
                            <p>
                                <label><input type="checkbox" name="rifnote_cleanup_include_customgpt" value="1" checked /> <?php esc_html_e('Include CustomGPT/GPT imported stories', 'rifnote-search'); ?></label><br />
                                <label><input type="checkbox" name="rifnote_cleanup_hard_delete" value="1" /> <?php esc_html_e('Hard delete instead of moving to trash', 'rifnote-search'); ?></label>
                            </p>
                            <p>
                                <button class="button button-secondary" type="submit" name="rifnote_ops_action" value="preview_imported_cleanup"><?php esc_html_e('Preview cleanup candidates', 'rifnote-search'); ?></button>
                                <button class="button button-primary button-link-delete" type="submit" name="rifnote_ops_action" value="cleanup_imported_posts"><?php esc_html_e('Clean imported posts', 'rifnote-search'); ?></button>
                            </p>
                        </form>
                    </div>
                    <h3><?php esc_html_e('Editorial audit trail', 'rifnote-search'); ?></h3>
                    <table class="widefat striped">
                        <thead><tr><th><?php esc_html_e('Action', 'rifnote-search'); ?></th><th><?php esc_html_e('Object', 'rifnote-search'); ?></th><th><?php esc_html_e('Notes', 'rifnote-search'); ?></th><th><?php esc_html_e('Time', 'rifnote-search'); ?></th></tr></thead>
                        <tbody>
                            <?php if (!$editorial_audit) : ?>
                                <tr><td colspan="4"><?php esc_html_e('No editorial actions logged yet.', 'rifnote-search'); ?></td></tr>
                            <?php endif; ?>
                            <?php foreach ($editorial_audit as $audit) : ?>
                                <tr>
                                    <td><?php echo esc_html($audit['action']); ?></td>
                                    <td><?php echo esc_html($audit['object_type'] . ' #' . $audit['object_id']); ?></td>
                                    <td><?php echo esc_html(wp_trim_words($audit['notes'], 12)); ?></td>
                                    <td><?php echo esc_html(get_date_from_gmt($audit['created_at'], 'M j, H:i')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div>
                    <h3><?php esc_html_e('Ranking simulator', 'rifnote-search'); ?></h3>
                    <form method="post">
                        <?php wp_nonce_field('rifnote_ops_action', 'rifnote_ops_nonce'); ?>
                        <input type="hidden" name="rifnote_ops_action" value="simulate_ranking" />
                        <input class="regular-text" name="rifnote_sim_query" value="<?php echo esc_attr($ranking_query); ?>" placeholder="<?php esc_attr_e('Search query to inspect', 'rifnote-search'); ?>" />
                        <?php submit_button(__('Simulate ranking', 'rifnote-search'), 'secondary small', 'submit', false); ?>
                    </form>
                    <table class="widefat striped" style="margin-top:12px;">
                        <thead><tr><th><?php esc_html_e('Result', 'rifnote-search'); ?></th><th><?php esc_html_e('Score', 'rifnote-search'); ?></th><th><?php esc_html_e('Breakdown', 'rifnote-search'); ?></th></tr></thead>
                        <tbody>
                            <?php if (!$ranking_results) : ?>
                                <tr><td colspan="3"><?php esc_html_e('Run a query to inspect ranking.', 'rifnote-search'); ?></td></tr>
                            <?php endif; ?>
                            <?php foreach ($ranking_results as $result) : ?>
                                <tr>
                                    <td><?php echo esc_html($result['headline']); ?><br /><small><?php echo esc_html($result['source_name']); ?></small></td>
                                    <td><?php echo esc_html(number_format_i18n((float) $result['score'], 4)); ?></td>
                                    <td><small><?php echo esc_html(wp_json_encode($result['score_breakdown'])); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <h3><?php esc_html_e('Daily briefing preview', 'rifnote-search'); ?></h3>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e('Story', 'rifnote-search'); ?></th><th><?php esc_html_e('Source', 'rifnote-search'); ?></th><th><?php esc_html_e('Score', 'rifnote-search'); ?></th></tr></thead>
                <tbody>
                    <?php if (empty($daily_briefing['stories'])) : ?>
                        <tr><td colspan="3"><?php esc_html_e('No briefing stories yet.', 'rifnote-search'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($daily_briefing['stories'] as $story) : ?>
                        <tr><td><?php echo esc_html($story['headline']); ?></td><td><?php echo esc_html($story['source_name']); ?></td><td><?php echo esc_html(number_format_i18n((float) $story['score'], 2)); ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if (self::is_section('growth')) : ?>
            <hr />
            <h2><?php esc_html_e('Retention and growth', 'rifnote-search'); ?></h2>
            <p><?php esc_html_e('Personalization, alerts, notification queue, newsletter signups and embeddable growth widgets.', 'rifnote-search'); ?></p>
            <div style="display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;max-width:1120px;">
                <div class="card"><h3><?php echo esc_html(number_format_i18n((int) $retention_summary['preferences'])); ?></h3><p><?php esc_html_e('Saved preferences', 'rifnote-search'); ?></p></div>
                <div class="card"><h3><?php echo esc_html(number_format_i18n((int) $retention_summary['alerts'])); ?></h3><p><?php esc_html_e('Active alerts', 'rifnote-search'); ?></p></div>
                <div class="card"><h3><?php echo esc_html(number_format_i18n((int) $retention_summary['queued_notifications'])); ?></h3><p><?php esc_html_e('Queued notifications', 'rifnote-search'); ?></p></div>
                <div class="card"><h3><?php echo esc_html(number_format_i18n((int) $retention_summary['newsletter_subscribers'])); ?></h3><p><?php esc_html_e('Newsletter subscribers', 'rifnote-search'); ?></p></div>
                <div class="card"><h3><?php echo esc_html(number_format_i18n((int) $retention_summary['devices'])); ?></h3><p><?php esc_html_e('Registered devices', 'rifnote-search'); ?></p></div>
            </div>
            <p><code>[rifnote_for_you]</code> <code>[rifnote_newsletter_signup]</code> <code>[rifnote_widget_trending]</code></p>
            <form method="post" style="margin:12px 0;">
                <?php wp_nonce_field('rifnote_retention_action', 'rifnote_retention_nonce'); ?>
                <input type="hidden" name="rifnote_retention_action" value="process_alerts" />
                <?php submit_button(__('Process alerts now', 'rifnote-search'), 'secondary', 'submit', false); ?>
                <?php if (!empty($alerts_last_run['ran_at'])) : ?>
                    <span style="margin-left:8px;">
                        <?php echo esc_html(sprintf(
                            /* translators: 1: checked count, 2: queued count, 3: date */
                            __('Last run checked %1$d alerts and queued %2$d notifications on %3$s.', 'rifnote-search'),
                            (int) ($alerts_last_run['checked'] ?? 0),
                            (int) ($alerts_last_run['queued'] ?? 0),
                            get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($alerts_last_run['ran_at'])), 'M j, H:i')
                        )); ?>
                    </span>
                <?php endif; ?>
            </form>
            <form method="post" style="margin:12px 0;">
                <?php wp_nonce_field('rifnote_retention_action', 'rifnote_retention_nonce'); ?>
                <input type="hidden" name="rifnote_retention_action" value="send_newsletter_digest" />
                <?php submit_button(__('Send newsletter digest now', 'rifnote-search'), 'secondary', 'submit', false); ?>
                <?php if (!empty($newsletter_last_run['ran_at'])) : ?>
                    <span style="margin-left:8px;">
                        <?php echo esc_html(sprintf(
                            /* translators: 1: subscriber count, 2: sent count, 3: date */
                            __('Last digest processed %1$d subscribers and sent %2$d emails on %3$s.', 'rifnote-search'),
                            (int) ($newsletter_last_run['subscribers'] ?? 0),
                            (int) ($newsletter_last_run['sent'] ?? 0),
                            get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($newsletter_last_run['ran_at'])), 'M j, H:i')
                        )); ?>
                    </span>
                <?php endif; ?>
            </form>
            <form method="post" style="margin:12px 0;">
                <?php wp_nonce_field('rifnote_retention_action', 'rifnote_retention_nonce'); ?>
                <input type="hidden" name="rifnote_retention_action" value="process_push" />
                <?php submit_button(__('Process push queue now', 'rifnote-search'), 'secondary', 'submit', false); ?>
                <span style="margin-left:8px;">
                    <?php echo esc_html(sprintf(
                        /* translators: 1: email provider, 2: push provider */
                        __('Delivery: email %1$s, push %2$s.', 'rifnote-search'),
                        $delivery_health['email_ready'] ? __('ready', 'rifnote-search') : __('needs setup', 'rifnote-search'),
                        $delivery_health['push_ready'] ? __('ready', 'rifnote-search') : __('needs setup', 'rifnote-search')
                    )); ?>
                </span>
                <?php if (!empty($push_last_run['ran_at'])) : ?>
                    <span style="margin-left:8px;">
                        <?php echo esc_html(sprintf(
                            /* translators: 1: sent count, 2: date */
                            __('Last push pass sent %1$d notifications on %2$s.', 'rifnote-search'),
                            (int) ($push_last_run['sent'] ?? 0),
                            get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($push_last_run['ran_at'])), 'M j, H:i')
                        )); ?>
                    </span>
                <?php endif; ?>
            </form>

            <hr />
            <h2><?php esc_html_e('Launch, SEO and monetization', 'rifnote-search'); ?></h2>
            <p><?php esc_html_e('Fact-check metadata, clearly labeled sponsored placements, schema readiness and abuse monitoring.', 'rifnote-search'); ?></p>
            <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;max-width:1120px;">
                <div class="card"><h3><?php echo esc_html(number_format_i18n((int) $launch_summary['claims'])); ?></h3><p><?php esc_html_e('Active claims', 'rifnote-search'); ?></p></div>
                <div class="card"><h3><?php echo esc_html(number_format_i18n((int) $launch_summary['sponsored'])); ?></h3><p><?php esc_html_e('Sponsored placements', 'rifnote-search'); ?></p></div>
                <div class="card"><h3><?php echo esc_html(number_format_i18n((int) $launch_summary['sponsor_requests'])); ?></h3><p><?php esc_html_e('Sponsor requests', 'rifnote-search'); ?></p></div>
                <div class="card"><h3><?php echo esc_html(number_format_i18n((int) $launch_summary['suspicious'])); ?></h3><p><?php esc_html_e('Suspicious activity', 'rifnote-search'); ?></p></div>
            </div>
            <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;align-items:start;margin-top:12px;">
                <div>
                    <h3><?php esc_html_e('Claim metadata', 'rifnote-search'); ?></h3>
                    <form method="post">
                        <?php wp_nonce_field('rifnote_launch_action', 'rifnote_launch_nonce'); ?>
                        <input type="hidden" name="rifnote_launch_action" value="add_claim" />
                        <p><input name="rifnote_claim_post_id" type="number" placeholder="Post ID" /> <input name="rifnote_claim_cluster_id" placeholder="cluster_id" /></p>
                        <p><input class="regular-text" name="rifnote_claim_text" placeholder="<?php esc_attr_e('Claim reviewed', 'rifnote-search'); ?>" /></p>
                        <p><input name="rifnote_claimant" placeholder="<?php esc_attr_e('Claimant', 'rifnote-search'); ?>" /> <input name="rifnote_claim_rating" placeholder="<?php esc_attr_e('Rating', 'rifnote-search'); ?>" /></p>
                        <p><input class="regular-text" name="rifnote_review_summary" placeholder="<?php esc_attr_e('Review summary', 'rifnote-search'); ?>" /></p>
                        <p><input class="regular-text" name="rifnote_review_url" type="url" placeholder="https://factcheck.example/review" /></p>
                        <?php submit_button(__('Save claim', 'rifnote-search'), 'secondary'); ?>
                    </form>
                    <table class="widefat striped">
                        <thead><tr><th><?php esc_html_e('Claim', 'rifnote-search'); ?></th><th><?php esc_html_e('Rating', 'rifnote-search'); ?></th></tr></thead>
                        <tbody><?php foreach ($claims as $claim) : ?><tr><td><?php echo esc_html(wp_trim_words($claim['claim_text'], 12)); ?></td><td><?php echo esc_html($claim['rating']); ?></td></tr><?php endforeach; ?></tbody>
                    </table>
                </div>
                <div>
                    <h3><?php esc_html_e('Sponsored placements', 'rifnote-search'); ?></h3>
                    <form method="post">
                        <?php wp_nonce_field('rifnote_launch_action', 'rifnote_launch_nonce'); ?>
                        <input type="hidden" name="rifnote_launch_action" value="add_sponsored" />
                        <p><input class="regular-text" name="rifnote_sponsored_title" placeholder="<?php esc_attr_e('Sponsored title', 'rifnote-search'); ?>" /></p>
                        <p><input class="regular-text" name="rifnote_sponsored_url" type="url" placeholder="https://sponsor.example" /></p>
                        <p><input name="rifnote_sponsor_name" placeholder="<?php esc_attr_e('Sponsor', 'rifnote-search'); ?>" /> <input name="rifnote_sponsored_category" placeholder="<?php esc_attr_e('Category', 'rifnote-search'); ?>" /></p>
                        <p><input class="regular-text" name="rifnote_sponsored_query" placeholder="<?php esc_attr_e('Optional query match', 'rifnote-search'); ?>" /></p>
                        <?php submit_button(__('Save sponsored placement', 'rifnote-search'), 'secondary'); ?>
                    </form>
                    <table class="widefat striped">
                        <thead><tr><th><?php esc_html_e('Placement', 'rifnote-search'); ?></th><th><?php esc_html_e('Sponsor', 'rifnote-search'); ?></th><th><?php esc_html_e('Clicks', 'rifnote-search'); ?></th></tr></thead>
                        <tbody><?php foreach ($sponsored as $placement) : ?><tr><td><?php echo esc_html($placement['title']); ?></td><td><?php echo esc_html($placement['sponsor_name']); ?></td><td><?php echo esc_html((int) $placement['clicks']); ?></td></tr><?php endforeach; ?></tbody>
                    </table>
                </div>
            </div>
            <h3><?php esc_html_e('Sponsor request pipeline', 'rifnote-search'); ?></h3>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e('Campaign', 'rifnote-search'); ?></th><th><?php esc_html_e('Sponsor', 'rifnote-search'); ?></th><th><?php esc_html_e('Targeting', 'rifnote-search'); ?></th><th><?php esc_html_e('Budget', 'rifnote-search'); ?></th><th><?php esc_html_e('Checkout', 'rifnote-search'); ?></th></tr></thead>
                <tbody>
                    <?php if (!$sponsor_requests) : ?><tr><td colspan="5"><?php esc_html_e('No sponsor requests yet.', 'rifnote-search'); ?></td></tr><?php endif; ?>
                    <?php foreach ($sponsor_requests as $request) : ?>
                        <?php
                        $campaign_payload = !empty($request['campaign_payload']) ? json_decode($request['campaign_payload'], true) : array();
                        $audience = is_array($campaign_payload) ? ($campaign_payload['audience'] ?? array()) : array();
                        $creative = is_array($campaign_payload) ? ($campaign_payload['creative'] ?? array()) : array();
                        $estimate = is_array($campaign_payload) ? ($campaign_payload['estimate'] ?? array()) : array();
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($request['campaign_title']); ?></strong><br />
                                <small><?php echo esc_html($request['status']); ?> · <?php echo esc_html($request['objective'] ?? ($campaign_payload['objective'] ?? 'awareness')); ?></small>
                                <?php if (!empty($creative['headline'])) : ?><br /><small><?php echo esc_html($creative['headline']); ?></small><?php endif; ?>
                            </td>
                            <td>
                                <?php echo esc_html($request['sponsor_name']); ?><br />
                                <small><?php echo esc_html($request['contact_email']); ?></small>
                                <?php if (!empty($campaign_payload['phone'])) : ?><br /><small><?php echo esc_html($campaign_payload['phone']); ?></small><?php endif; ?>
                            </td>
                            <td>
                                <?php echo esc_html($request['placement_summary'] ?? $request['category']); ?><br />
                                <small><?php echo esc_html($request['query_match']); ?></small>
                                <?php if (!empty($audience['locations'])) : ?><br /><small><?php echo esc_html($audience['locations']); ?> · <?php echo esc_html(($audience['age_min'] ?? 18) . '-' . ($audience['age_max'] ?? 34)); ?></small><?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo esc_html('₦' . number_format_i18n((float) ($request['estimated_price'] ?? 0))); ?></strong><br />
                                <small><?php echo esc_html(number_format_i18n((int) ($estimate['estimated_impressions'] ?? 0))); ?> <?php esc_html_e('est. impressions', 'rifnote-search'); ?></small>
                            </td>
                            <td><a href="<?php echo esc_url($request['checkout_url']); ?>" target="_blank" rel="noreferrer"><?php esc_html_e('Open', 'rifnote-search'); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <h3><?php esc_html_e('Suspicious activity', 'rifnote-search'); ?></h3>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e('Type', 'rifnote-search'); ?></th><th><?php esc_html_e('Message', 'rifnote-search'); ?></th><th><?php esc_html_e('Time', 'rifnote-search'); ?></th></tr></thead>
                <tbody>
                    <?php if (!$suspicious) : ?><tr><td colspan="3"><?php esc_html_e('No suspicious activity logged.', 'rifnote-search'); ?></td></tr><?php endif; ?>
                    <?php foreach ($suspicious as $event) : ?><tr><td><?php echo esc_html($event['event_type']); ?></td><td><?php echo esc_html($event['message']); ?></td><td><?php echo esc_html(get_date_from_gmt($event['created_at'], 'M j, H:i')); ?></td></tr><?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if (self::is_section('discovery')) : ?>
            <hr />
            <h2><?php esc_html_e('Search platform', 'rifnote-search'); ?></h2>
            <p><?php esc_html_e('Dedicated search index, autocomplete, full coverage story pages, source trust profiles and publisher verification.', 'rifnote-search'); ?></p>
            <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;max-width:920px;">
                <div class="card"><h3><?php echo esc_html(number_format_i18n((int) $index_health['indexed_posts'])); ?></h3><p><?php esc_html_e('Indexed posts', 'rifnote-search'); ?></p></div>
                <div class="card"><h3><?php echo esc_html($index_health['last_reindex'] ? get_date_from_gmt($index_health['last_reindex'], 'M j, H:i') : __('Never', 'rifnote-search')); ?></h3><p><?php esc_html_e('Last reindex', 'rifnote-search'); ?></p></div>
                <div class="card"><h3><?php echo esc_html('/story/{cluster}/'); ?></h3><p><?php esc_html_e('Full coverage route', 'rifnote-search'); ?></p></div>
            </div>
            <form method="post" style="margin-top:12px;">
                <?php wp_nonce_field('rifnote_index_action', 'rifnote_index_nonce'); ?>
                <?php submit_button(__('Rebuild search index', 'rifnote-search'), 'secondary'); ?>
            </form>
            <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;align-items:start;margin-top:12px;">
                <div>
                    <h3><?php esc_html_e('No-result intelligence', 'rifnote-search'); ?></h3>
                    <table class="widefat striped">
                        <thead><tr><th><?php esc_html_e('Query', 'rifnote-search'); ?></th><th><?php esc_html_e('Hits', 'rifnote-search'); ?></th><th><?php esc_html_e('Status', 'rifnote-search'); ?></th><th><?php esc_html_e('Action', 'rifnote-search'); ?></th></tr></thead>
                        <tbody>
                            <?php if (!$no_result_queue) : ?>
                                <tr><td colspan="4"><?php esc_html_e('No no-result queries yet.', 'rifnote-search'); ?></td></tr>
                            <?php endif; ?>
                            <?php foreach ($no_result_queue as $row) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($row['query_text']); ?></strong><br /><small><?php echo esc_html($row['category']); ?> <?php echo esc_html($row['notify_email']); ?></small></td>
                                    <td><?php echo esc_html((int) $row['hit_count']); ?></td>
                                    <td><?php echo esc_html(ucfirst($row['status'])); ?></td>
                                    <td>
                                        <form method="post">
                                            <?php wp_nonce_field('rifnote_insights_action', 'rifnote_insights_nonce'); ?>
                                            <input type="hidden" name="rifnote_insights_action" value="convert_no_result" />
                                            <input type="hidden" name="rifnote_no_result_id" value="<?php echo esc_attr($row['id']); ?>" />
                                            <input name="rifnote_no_result_topic" value="<?php echo esc_attr($row['query_text']); ?>" />
                                            <?php submit_button(__('Track topic', 'rifnote-search'), 'secondary small', 'submit', false); ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div>
                    <h3><?php esc_html_e('Manual timeline note', 'rifnote-search'); ?></h3>
                    <form method="post">
                        <?php wp_nonce_field('rifnote_insights_action', 'rifnote_insights_nonce'); ?>
                        <input type="hidden" name="rifnote_insights_action" value="add_timeline_note" />
                        <p><input class="regular-text" name="rifnote_timeline_cluster_id" placeholder="cluster_id" /></p>
                        <p><input class="regular-text" name="rifnote_timeline_label" placeholder="<?php esc_attr_e('What changed?', 'rifnote-search'); ?>" /></p>
                        <p><input name="rifnote_timeline_time" type="datetime-local" /> <input name="rifnote_timeline_source" placeholder="<?php esc_attr_e('Source name', 'rifnote-search'); ?>" /></p>
                        <p><input class="regular-text" name="rifnote_timeline_url" type="url" placeholder="https://source.example/story" /></p>
                        <?php submit_button(__('Add timeline note', 'rifnote-search'), 'secondary'); ?>
                    </form>
                </div>
            </div>

            <hr />
            <h2><?php esc_html_e('Analytics', 'rifnote-search'); ?></h2>
            <p><?php esc_html_e('Last 7 days of searches, no-result queries and outbound publisher traffic.', 'rifnote-search'); ?></p>
            <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;max-width:920px;">
                <div class="card"><h3><?php echo esc_html(number_format_i18n((int) $analytics['searches'])); ?></h3><p><?php esc_html_e('Searches', 'rifnote-search'); ?></p></div>
                <div class="card"><h3><?php echo esc_html(number_format_i18n((int) $analytics['no_result_searches'])); ?></h3><p><?php esc_html_e('No-result searches', 'rifnote-search'); ?></p></div>
                <div class="card"><h3><?php echo esc_html(number_format_i18n((int) $analytics['source_clicks'])); ?></h3><p><?php esc_html_e('Source clicks', 'rifnote-search'); ?></p></div>
            </div>
            <table class="widefat striped" style="margin-top:12px;">
                <thead><tr><th><?php esc_html_e('Top queries', 'rifnote-search'); ?></th><th><?php esc_html_e('No-result queries', 'rifnote-search'); ?></th><th><?php esc_html_e('Top clicked sources', 'rifnote-search'); ?></th></tr></thead>
                <tbody>
                    <tr>
                        <td><?php self::render_analytics_list($analytics['top_queries']); ?></td>
                        <td><?php self::render_analytics_list($analytics['no_result_queries']); ?></td>
                        <td><?php self::render_analytics_list($analytics['top_sources']); ?></td>
                    </tr>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if (self::is_section('compliance')) : ?>
            <hr />
            <h2><?php esc_html_e('Legal tools', 'rifnote-search'); ?></h2>
            <p><?php esc_html_e('Rifnote Search should drive traffic to publishers: show source names, keep snippets short, link readers to the full story, honor takedowns, respect opt-outs and check robots.txt before feed crawling.', 'rifnote-search'); ?></p>
            <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;align-items:start;">
                <div>
                    <h3><?php esc_html_e('Blocked domains', 'rifnote-search'); ?></h3>
                    <form method="post" style="margin-bottom:12px;">
                        <?php wp_nonce_field('rifnote_legal_action', 'rifnote_legal_nonce'); ?>
                        <input type="hidden" name="rifnote_legal_action" value="block_domain" />
                        <p>
                            <input class="regular-text" name="rifnote_blocked_domain" placeholder="example.com" />
                            <input class="regular-text" name="rifnote_blocked_reason" placeholder="<?php esc_attr_e('Reason', 'rifnote-search'); ?>" />
                            <?php submit_button(__('Block domain', 'rifnote-search'), 'secondary small', 'submit', false); ?>
                        </p>
                    </form>
                    <table class="widefat striped">
                        <thead><tr><th><?php esc_html_e('Domain', 'rifnote-search'); ?></th><th><?php esc_html_e('Status', 'rifnote-search'); ?></th><th><?php esc_html_e('Reason', 'rifnote-search'); ?></th><th><?php esc_html_e('Actions', 'rifnote-search'); ?></th></tr></thead>
                        <tbody>
                            <?php if (!$blocked_domains) : ?>
                                <tr><td colspan="4"><?php esc_html_e('No blocked domains yet.', 'rifnote-search'); ?></td></tr>
                            <?php endif; ?>
                            <?php foreach ($blocked_domains as $domain) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($domain['domain']); ?></strong></td>
                                    <td><?php echo esc_html(ucfirst($domain['status'])); ?></td>
                                    <td><?php echo esc_html(wp_trim_words($domain['reason'], 14)); ?></td>
                                    <td>
                                        <form method="post">
                                            <?php wp_nonce_field('rifnote_legal_action', 'rifnote_legal_nonce'); ?>
                                            <input type="hidden" name="rifnote_legal_action" value="blocked_status" />
                                            <input type="hidden" name="rifnote_blocked_domain_id" value="<?php echo esc_attr($domain['id']); ?>" />
                                            <input type="hidden" name="rifnote_blocked_status" value="<?php echo 'active' === $domain['status'] ? 'inactive' : 'active'; ?>" />
                                            <?php submit_button('active' === $domain['status'] ? __('Deactivate', 'rifnote-search') : __('Reactivate', 'rifnote-search'), 'secondary small', 'submit', false); ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div>
                    <h3><?php esc_html_e('DMCA and opt-out queue', 'rifnote-search'); ?></h3>
                    <table class="widefat striped">
                        <thead><tr><th><?php esc_html_e('Request', 'rifnote-search'); ?></th><th><?php esc_html_e('Target', 'rifnote-search'); ?></th><th><?php esc_html_e('Status', 'rifnote-search'); ?></th><th><?php esc_html_e('Actions', 'rifnote-search'); ?></th></tr></thead>
                        <tbody>
                            <?php if (!$legal_requests) : ?>
                                <tr><td colspan="4"><?php esc_html_e('No legal requests yet.', 'rifnote-search'); ?></td></tr>
                            <?php endif; ?>
                            <?php foreach ($legal_requests as $request) : ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html(strtoupper(str_replace('_', ' ', $request['request_type']))); ?></strong><br />
                                        <small><?php echo esc_html($request['requester_name']); ?> &lt;<?php echo esc_html($request['requester_email']); ?>&gt;</small><br />
                                        <small><?php echo esc_html(get_date_from_gmt($request['created_at'], 'M j, Y H:i')); ?></small>
                                        <p><?php echo esc_html(wp_trim_words($request['details'], 18)); ?></p>
                                    </td>
                                    <td>
                                        <?php echo esc_html($request['domain']); ?><br />
                                        <?php if (!empty($request['url'])) : ?>
                                            <small><a href="<?php echo esc_url($request['url']); ?>" target="_blank" rel="noreferrer"><?php echo esc_html(wp_trim_words($request['url'], 8, '...')); ?></a></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html(ucfirst($request['status'])); ?></td>
                                    <td>
                                        <?php foreach (array('reviewing', 'resolved', 'rejected') as $status) : ?>
                                            <form method="post" style="display:inline-block;margin-right:4px;">
                                                <?php wp_nonce_field('rifnote_legal_action', 'rifnote_legal_nonce'); ?>
                                                <input type="hidden" name="rifnote_legal_action" value="request_status" />
                                                <input type="hidden" name="rifnote_legal_request_id" value="<?php echo esc_attr($request['id']); ?>" />
                                                <input type="hidden" name="rifnote_legal_status" value="<?php echo esc_attr($status); ?>" />
                                                <?php submit_button(ucfirst($status), 'secondary small', 'submit', false); ?>
                                            </form>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php if (self::is_section('aggregation')) : ?>
            <?php
            $customgpt_story_endpoint = rest_url('rifnote/v1/customgpt/stories?limit=100&q={topic}');
            $customgpt_aggregation_endpoint = rest_url('rifnote/v1/customgpt/aggregation/batch');
            ?>
            <h2><?php esc_html_e('Manual Aggregation Desk', 'rifnote-search'); ?></h2>
            <p><?php esc_html_e('Create full-coverage story hubs by assigning one cluster ID to related posts. Admins can do it manually, and CustomGPT can pull candidates and write the same cluster metadata back to WordPress.', 'rifnote-search'); ?></p>

            <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;max-width:1120px;margin:16px 0;">
                <div class="card"><h3 style="margin-top:0;"><?php echo esc_html(number_format_i18n(count($manual_aggregations))); ?></h3><p><?php esc_html_e('Manual hubs', 'rifnote-search'); ?></p></div>
                <div class="card"><h3 style="margin-top:0;"><?php echo esc_html(number_format_i18n(count($aggregation_candidates))); ?></h3><p><?php esc_html_e('Recent candidates', 'rifnote-search'); ?></p></div>
                <div class="card"><h3 style="margin-top:0;"><?php echo esc_html(is_array($aggregation_categories) ? number_format_i18n(count($aggregation_categories)) : '0'); ?></h3><p><?php esc_html_e('Categories', 'rifnote-search'); ?></p></div>
                <div class="card"><h3 style="margin-top:0;"><?php echo esc_html($customgpt_settings['enabled'] ? __('GPT enabled', 'rifnote-search') : __('GPT off', 'rifnote-search')); ?></h3><p><?php esc_html_e('CustomGPT bridge', 'rifnote-search'); ?></p></div>
            </div>

            <div style="display:grid;grid-template-columns:minmax(0,1.2fr) minmax(320px,.8fr);gap:18px;align-items:start;max-width:1320px;">
                <div class="card">
                    <h2><?php esc_html_e('Create / update a story hub', 'rifnote-search'); ?></h2>
                    <form method="post">
                        <?php wp_nonce_field('rifnote_aggregation_action', 'rifnote_aggregation_nonce'); ?>
                        <input type="hidden" name="rifnote_aggregation_action" value="save_cluster" />
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr><th scope="row"><label for="rifnote_aggregation_title"><?php esc_html_e('Hub title', 'rifnote-search'); ?></label></th><td><input id="rifnote_aggregation_title" class="large-text" name="rifnote_aggregation_title" placeholder="<?php esc_attr_e('Example: Osimhen transfer race heats up across Europe', 'rifnote-search'); ?>" /></td></tr>
                                <tr><th scope="row"><label for="rifnote_aggregation_cluster_id"><?php esc_html_e('Cluster ID', 'rifnote-search'); ?></label></th><td><input id="rifnote_aggregation_cluster_id" class="regular-text" name="rifnote_aggregation_cluster_id" placeholder="manual_osimhen_transfer_race" /><p class="description"><?php esc_html_e('Optional. This becomes /story/{cluster_id}/. Use manual_topic_slug for curated hubs.', 'rifnote-search'); ?></p></td></tr>
                                <tr><th scope="row"><label for="rifnote_aggregation_category"><?php esc_html_e('Category', 'rifnote-search'); ?></label></th><td><select id="rifnote_aggregation_category" name="rifnote_aggregation_category"><option value=""><?php esc_html_e('Keep existing categories', 'rifnote-search'); ?></option><?php if (is_array($aggregation_categories)) : foreach ($aggregation_categories as $term) : ?><option value="<?php echo esc_attr($term->name); ?>"><?php echo esc_html($term->name); ?></option><?php endforeach; endif; ?></select></td></tr>
                                <tr><th scope="row"><label for="rifnote_aggregation_status"><?php esc_html_e('Status', 'rifnote-search'); ?></label></th><td><select id="rifnote_aggregation_status" name="rifnote_aggregation_status"><option value="draft"><?php esc_html_e('Draft', 'rifnote-search'); ?></option><option value="review"><?php esc_html_e('Needs review', 'rifnote-search'); ?></option><option value="published"><?php esc_html_e('Published hub', 'rifnote-search'); ?></option><option value="archived"><?php esc_html_e('Archived', 'rifnote-search'); ?></option></select></td></tr>
                                <tr><th scope="row"><label for="rifnote_aggregation_summary"><?php esc_html_e('Summary', 'rifnote-search'); ?></label></th><td><textarea id="rifnote_aggregation_summary" class="large-text" rows="4" name="rifnote_aggregation_summary" placeholder="<?php esc_attr_e('Short neutral summary of the wider story.', 'rifnote-search'); ?>"></textarea></td></tr>
                                <tr><th scope="row"><label for="rifnote_aggregation_image_url"><?php esc_html_e('Featured image', 'rifnote-search'); ?></label></th><td><div class="rs-media-field"><input id="rifnote_aggregation_image_url" class="large-text rs-media-url" type="url" name="rifnote_aggregation_image_url" placeholder="https://publisher.com/image.jpg" /><p><button type="button" class="button rs-media-picker" data-target="#rifnote_aggregation_image_url" data-library="image" data-title="<?php esc_attr_e('Choose aggregation image', 'rifnote-search'); ?>" data-button="<?php esc_attr_e('Use image', 'rifnote-search'); ?>"><?php esc_html_e('Choose from Media Library', 'rifnote-search'); ?></button> <button type="button" class="button rs-media-clear" data-target="#rifnote_aggregation_image_url"><?php esc_html_e('Clear', 'rifnote-search'); ?></button></p></div></td></tr>
                                <tr><th scope="row"><label for="rifnote_aggregation_post_ids_manual"><?php esc_html_e('Post IDs', 'rifnote-search'); ?></label></th><td><input id="rifnote_aggregation_post_ids_manual" class="large-text" name="rifnote_aggregation_post_ids_manual" placeholder="123, 124, 125" /><p class="description"><?php esc_html_e('Optional. You can also tick recent stories below.', 'rifnote-search'); ?></p></td></tr>
                                <tr><th scope="row"><label for="rifnote_aggregation_notes"><?php esc_html_e('Editorial notes', 'rifnote-search'); ?></label></th><td><textarea id="rifnote_aggregation_notes" class="large-text" rows="3" name="rifnote_aggregation_notes" placeholder="<?php esc_attr_e('Internal angle, verification notes, what GPT should watch for.', 'rifnote-search'); ?>"></textarea></td></tr>
                            </tbody>
                        </table>
                        <h3><?php esc_html_e('Recent story candidates', 'rifnote-search'); ?></h3>
                        <table class="widefat striped">
                            <thead><tr><th><?php esc_html_e('Use', 'rifnote-search'); ?></th><th><?php esc_html_e('Story', 'rifnote-search'); ?></th><th><?php esc_html_e('Source / model', 'rifnote-search'); ?></th><th><?php esc_html_e('Category', 'rifnote-search'); ?></th><th><?php esc_html_e('Cluster', 'rifnote-search'); ?></th><th><?php esc_html_e('AI', 'rifnote-search'); ?></th></tr></thead>
                            <tbody>
                                <?php if (!$aggregation_candidates) : ?><tr><td colspan="6"><?php esc_html_e('No candidate stories found yet.', 'rifnote-search'); ?></td></tr><?php endif; ?>
                                <?php foreach ($aggregation_candidates as $candidate) : ?>
                                    <tr>
                                        <td><input type="checkbox" name="rifnote_aggregation_post_ids[]" value="<?php echo esc_attr($candidate['post_id']); ?>" /></td>
                                        <td><strong>#<?php echo esc_html((int) $candidate['post_id']); ?> <?php echo esc_html(wp_trim_words($candidate['title'], 14)); ?></strong><br /><small><?php echo esc_html(get_date_from_gmt($candidate['date'], 'M j, Y H:i')); ?> · <?php echo esc_html($candidate['status']); ?></small><?php if ($candidate['edit_url']) : ?><br /><a href="<?php echo esc_url($candidate['edit_url']); ?>"><?php esc_html_e('Edit post', 'rifnote-search'); ?></a><?php endif; ?></td>
                                        <td><?php echo esc_html($candidate['source_name'] ? $candidate['source_name'] : __('Unknown', 'rifnote-search')); ?><br /><small><?php echo esc_html($candidate['origin_model'] ? $candidate['origin_model'] : __('No model', 'rifnote-search')); ?></small></td>
                                        <td><?php echo esc_html($candidate['category']); ?></td>
                                        <td><code><?php echo esc_html($candidate['cluster_id']); ?></code></td>
                                        <td><?php echo $candidate['missing_ai_summary'] ? esc_html__('Needs summary', 'rifnote-search') : esc_html__('Ready', 'rifnote-search'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php submit_button(__('Save aggregation hub', 'rifnote-search')); ?>
                    </form>
                </div>

                <div>
                    <div class="card">
                        <h2><?php esc_html_e('Seed Rifnote categories', 'rifnote-search'); ?></h2>
                        <p><?php esc_html_e('Creates the core categories used by search, homepage filters, NewsAPI imports, publisher submissions and GPT formatting.', 'rifnote-search'); ?></p>
                        <form method="post">
                            <?php wp_nonce_field('rifnote_aggregation_action', 'rifnote_aggregation_nonce'); ?>
                            <input type="hidden" name="rifnote_aggregation_action" value="seed_categories" />
                            <?php submit_button(__('Create / refresh categories', 'rifnote-search'), 'secondary'); ?>
                        </form>
                    </div>
                    <div class="card">
                        <h2><?php esc_html_e('CustomGPT aggregation bridge', 'rifnote-search'); ?></h2>
                        <p><?php esc_html_e('Give this to the CustomGPT when you want it to group stories into full-coverage hubs.', 'rifnote-search'); ?></p>
                        <p><strong><?php esc_html_e('Pull candidates:', 'rifnote-search'); ?></strong><br /><code><?php echo esc_html($customgpt_story_endpoint); ?></code></p>
                        <p><strong><?php esc_html_e('Write clusters:', 'rifnote-search'); ?></strong><br /><code><?php echo esc_html($customgpt_aggregation_endpoint); ?></code></p>
                        <textarea class="large-text code" rows="12" readonly><?php echo esc_textarea(Rifnote_Search_Aggregation::customgpt_aggregation_instructions()); ?></textarea>
                        <p class="description"><?php esc_html_e('Authentication header: X-Rifnote-CustomGPT-Key. Generate or rotate the key under CustomGPT Import.', 'rifnote-search'); ?></p>
                    </div>
                </div>
            </div>

            <div class="card" style="max-width:1320px;margin-top:18px;">
                <h2><?php esc_html_e('Existing manual hubs', 'rifnote-search'); ?></h2>
                <table class="widefat striped">
                    <thead><tr><th><?php esc_html_e('Hub', 'rifnote-search'); ?></th><th><?php esc_html_e('Category', 'rifnote-search'); ?></th><th><?php esc_html_e('Status', 'rifnote-search'); ?></th><th><?php esc_html_e('Stories', 'rifnote-search'); ?></th><th><?php esc_html_e('Updated', 'rifnote-search'); ?></th><th><?php esc_html_e('Actions', 'rifnote-search'); ?></th></tr></thead>
                    <tbody>
                        <?php if (!$manual_aggregations) : ?><tr><td colspan="6"><?php esc_html_e('No manual aggregations yet.', 'rifnote-search'); ?></td></tr><?php endif; ?>
                        <?php foreach ($manual_aggregations as $aggregation) : ?>
                            <tr>
                                <td><strong><?php echo esc_html($aggregation['title']); ?></strong><br /><code><?php echo esc_html($aggregation['cluster_id']); ?></code><br /><small><?php echo esc_html(wp_trim_words($aggregation['summary'], 18)); ?></small></td>
                                <td><?php echo esc_html($aggregation['category']); ?></td>
                                <td><?php echo esc_html(ucfirst($aggregation['status'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n(count($aggregation['post_ids'] ?? array()))); ?></td>
                                <td><?php echo esc_html(!empty($aggregation['updated_at']) ? get_date_from_gmt($aggregation['updated_at'], 'M j, Y H:i') : ''); ?></td>
                                <td><p><a class="button button-small" href="<?php echo esc_url(home_url('/story/' . rawurlencode($aggregation['cluster_id']) . '/')); ?>" target="_blank" rel="noreferrer"><?php esc_html_e('View hub', 'rifnote-search'); ?></a></p><form method="post"><?php wp_nonce_field('rifnote_aggregation_action', 'rifnote_aggregation_nonce'); ?><input type="hidden" name="rifnote_aggregation_action" value="delete_cluster" /><input type="hidden" name="rifnote_aggregation_delete_cluster_id" value="<?php echo esc_attr($aggregation['cluster_id']); ?>" /><?php submit_button(__('Delete record', 'rifnote-search'), 'delete small', 'submit', false); ?></form></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (self::is_section('customgpt')) : ?>
            <h2><?php esc_html_e('CustomGPT Import', 'rifnote-search'); ?></h2>
            <p><?php esc_html_e('Let a CustomGPT act as an editorial assistant that converts CSV, JSON, HTML, PDF notes or source-link dumps into structured Rifnote stories. WordPress validates, dedupes, creates posts, indexes stories and writes import logs.', 'rifnote-search'); ?></p>

            <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;max-width:1120px;margin:16px 0;">
                <div class="card">
                    <h3 style="margin-top:0;"><?php echo esc_html($customgpt_settings['enabled'] ? __('Enabled', 'rifnote-search') : __('Disabled', 'rifnote-search')); ?></h3>
                    <p><?php esc_html_e('Endpoint status', 'rifnote-search'); ?></p>
                </div>
                <div class="card">
                    <h3 style="margin-top:0;"><?php echo esc_html($customgpt_settings['api_key_hash'] ? __('Key ready', 'rifnote-search') : __('No key', 'rifnote-search')); ?></h3>
                    <p><?php esc_html_e('Authentication', 'rifnote-search'); ?></p>
                </div>
                <div class="card">
                    <h3 style="margin-top:0;"><?php echo esc_html(number_format_i18n((int) $customgpt_settings['max_batch'])); ?></h3>
                    <p><?php esc_html_e('Max stories per batch', 'rifnote-search'); ?></p>
                </div>
                <div class="card">
                    <h3 style="margin-top:0;"><?php echo esc_html(!empty($customgpt_last_run['created']) ? number_format_i18n((int) $customgpt_last_run['created']) : '0'); ?></h3>
                    <p><?php esc_html_e('Created last run', 'rifnote-search'); ?></p>
                </div>
            </div>

            <h3><?php esc_html_e('Editorial Cleanup Queue', 'rifnote-search'); ?></h3>
            <p><?php esc_html_e('These are non-GPT stories with missing editorial fields. CustomGPT should pull this queue, format the stories, add summaries/key points/entities, and send them back through the formatting endpoint.', 'rifnote-search'); ?></p>
            <p><strong><?php esc_html_e('Queue endpoint:', 'rifnote-search'); ?></strong> <code><?php echo esc_html(rest_url('rifnote/v1/customgpt/stories?origin_model_not=GPT&incomplete=true&limit=100')); ?></code></p>
            <form method="post" style="margin:12px 0 18px;">
                <?php wp_nonce_field('rifnote_customgpt_action', 'rifnote_customgpt_nonce'); ?>
                <input type="hidden" name="rifnote_customgpt_action" value="normalize_existing_text" />
                <label>
                    <?php esc_html_e('Normalize latest posts:', 'rifnote-search'); ?>
                    <input type="number" min="1" max="1000" name="rifnote_normalize_limit" value="200" />
                </label>
                <?php submit_button(__('Fix encoded text now', 'rifnote-search'), 'secondary', 'submit', false); ?>
            </form>
            <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;max-width:900px;margin:12px 0;">
                <div class="card">
                    <h3 style="margin-top:0;"><?php echo esc_html(number_format_i18n((int) ($customgpt_cleanup_queue['count'] ?? 0))); ?></h3>
                    <p><?php esc_html_e('Stories ready now', 'rifnote-search'); ?></p>
                </div>
                <div class="card">
                    <h3 style="margin-top:0;"><?php echo esc_html($customgpt_cleanup_queue['count'] ? __('Needs GPT', 'rifnote-search') : __('Clean', 'rifnote-search')); ?></h3>
                    <p><?php esc_html_e('Cleanup state', 'rifnote-search'); ?></p>
                </div>
                <div class="card">
                    <h3 style="margin-top:0;"><?php echo esc_html__('100', 'rifnote-search'); ?></h3>
                    <p><?php esc_html_e('Max CustomGPT pull', 'rifnote-search'); ?></p>
                </div>
            </div>
            <table class="widefat striped" style="margin-bottom:22px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Story', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Origin', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Missing', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Status', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Action', 'rifnote-search'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customgpt_cleanup_queue['stories'])) : ?>
                        <tr><td colspan="5"><?php esc_html_e('No non-GPT incomplete stories are waiting for CustomGPT cleanup.', 'rifnote-search'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach (($customgpt_cleanup_queue['stories'] ?? array()) as $story) : ?>
                        <?php $missing = Rifnote_Search_CustomGPT_Import::missing_fields_for_story($story); ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($story['title'] ?? __('Untitled', 'rifnote-search')); ?></strong><br />
                                <small><?php echo esc_html($story['original_url'] ?? ''); ?></small>
                            </td>
                            <td>
                                <strong><?php echo esc_html($story['origin_model'] ? $story['origin_model'] : __('Unknown', 'rifnote-search')); ?></strong><br />
                                <small><?php echo esc_html(trim(($story['origin_actor'] ?? '') . ' / ' . ($story['origin_channel'] ?? ''), ' /')); ?></small>
                            </td>
                            <td><?php echo esc_html($missing ? implode(', ', $missing) : __('None', 'rifnote-search')); ?></td>
                            <td><?php echo esc_html($story['status'] ?? ''); ?></td>
                            <td>
                                <?php if (!empty($story['post_id'])) : ?>
                                    <a href="<?php echo esc_url(get_edit_post_link((int) $story['post_id'])); ?>"><?php esc_html_e('Edit', 'rifnote-search'); ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <form method="post" action="options.php">
                <?php settings_fields('rifnote_customgpt_settings'); ?>
                <h3><?php esc_html_e('Endpoint Settings', 'rifnote-search'); ?></h3>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Enable endpoint', 'rifnote-search'); ?></th>
                            <td>
                                <label>
                                    <input type="hidden" name="rifnote_customgpt_import_enabled" value="0" />
                                    <input type="checkbox" name="rifnote_customgpt_import_enabled" value="1" <?php checked((bool) get_option('rifnote_customgpt_import_enabled', false)); ?> />
                                    <?php esc_html_e('Allow authenticated CustomGPT batch imports.', 'rifnote-search'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_customgpt_import_default_mode"><?php esc_html_e('Default import mode', 'rifnote-search'); ?></label></th>
                            <td>
                                <select id="rifnote_customgpt_import_default_mode" name="rifnote_customgpt_import_default_mode">
                                    <option value="draft" <?php selected(get_option('rifnote_customgpt_import_default_mode', 'draft'), 'draft'); ?>><?php esc_html_e('Draft', 'rifnote-search'); ?></option>
                                    <option value="pending" <?php selected(get_option('rifnote_customgpt_import_default_mode', 'draft'), 'pending'); ?>><?php esc_html_e('Pending review', 'rifnote-search'); ?></option>
                                    <option value="publish" <?php selected(get_option('rifnote_customgpt_import_default_mode', 'draft'), 'publish'); ?>><?php esc_html_e('Publish immediately', 'rifnote-search'); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e('Draft is recommended. CustomGPT can still send mode per request, but it is sanitized to draft, pending or publish.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_customgpt_import_max_batch"><?php esc_html_e('Max batch size', 'rifnote-search'); ?></label></th>
                            <td><input id="rifnote_customgpt_import_max_batch" type="number" min="1" max="100" name="rifnote_customgpt_import_max_batch" value="<?php echo esc_attr(get_option('rifnote_customgpt_import_max_batch', Rifnote_Search_CustomGPT_Import::DEFAULT_MAX_BATCH)); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_customgpt_import_allowed_domains"><?php esc_html_e('Allowed domains', 'rifnote-search'); ?></label></th>
                            <td>
                                <textarea id="rifnote_customgpt_import_allowed_domains" class="large-text code" rows="4" name="rifnote_customgpt_import_allowed_domains"><?php echo esc_textarea(get_option('rifnote_customgpt_import_allowed_domains', '')); ?></textarea>
                                <p class="description"><?php esc_html_e('Optional. One domain per line or comma-separated. Leave blank to allow any non-blocked source domain.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_customgpt_import_instructions"><?php esc_html_e('CustomGPT instructions', 'rifnote-search'); ?></label></th>
                            <td>
                                <textarea id="rifnote_customgpt_import_instructions" class="large-text code" rows="14" name="rifnote_customgpt_import_instructions"><?php echo esc_textarea(get_option('rifnote_customgpt_import_instructions', Rifnote_Search_CustomGPT_Import::default_instructions())); ?></textarea>
                                <p class="description"><?php esc_html_e('Paste this into your CustomGPT instructions and pair it with the endpoint/action schema below.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(__('Save CustomGPT settings', 'rifnote-search')); ?>
            </form>

            <h3><?php esc_html_e('API Key', 'rifnote-search'); ?></h3>
            <p><?php esc_html_e('Generate a key for CustomGPT Actions. Store it in the CustomGPT action authentication field as a bearer token.', 'rifnote-search'); ?></p>
            <form method="post">
                <?php wp_nonce_field('rifnote_customgpt_action', 'rifnote_customgpt_nonce'); ?>
                <input type="hidden" name="rifnote_customgpt_action" value="generate_key" />
                <?php submit_button($customgpt_settings['api_key_hash'] ? __('Rotate API key', 'rifnote-search') : __('Generate API key', 'rifnote-search'), 'secondary'); ?>
            </form>

            <h3><?php esc_html_e('Endpoint & Payload', 'rifnote-search'); ?></h3>
            <p><strong><?php esc_html_e('Import endpoint:', 'rifnote-search'); ?></strong> <code><?php echo esc_html(rest_url('rifnote/v1/customgpt/import/batch')); ?></code></p>
            <p><strong><?php esc_html_e('Story export endpoint:', 'rifnote-search'); ?></strong> <code><?php echo esc_html(rest_url('rifnote/v1/customgpt/stories')); ?></code></p>
            <p><strong><?php esc_html_e('Formatting endpoint:', 'rifnote-search'); ?></strong> <code><?php echo esc_html(rest_url('rifnote/v1/customgpt/format/batch')); ?></code></p>
            <p><strong><?php esc_html_e('Aggregation endpoint:', 'rifnote-search'); ?></strong> <code><?php echo esc_html(rest_url('rifnote/v1/customgpt/aggregation/batch')); ?></code></p>
            <p><strong><?php esc_html_e('Headers:', 'rifnote-search'); ?></strong> <code>Authorization: Bearer YOUR_KEY</code> <?php esc_html_e('or', 'rifnote-search'); ?> <code>X-Rifnote-CustomGPT-Key: YOUR_KEY</code></p>
            <?php
            $customgpt_story_schema = array(
                'type' => 'object',
                'additionalProperties' => true,
                'properties' => array(
                    'post_id' => array('type' => 'integer', 'description' => 'Required only when updating an existing story.'),
                    'title' => array('type' => 'string'),
                    'headline' => array('type' => 'string'),
                    'excerpt' => array('type' => 'string'),
                    'body' => array('type' => 'string'),
                    'original_url' => array('type' => 'string'),
                    'source_name' => array('type' => 'string'),
                    'source_url' => array('type' => 'string'),
                    'category' => array('type' => 'string'),
                    'tags' => array('type' => 'array', 'items' => array('type' => 'string')),
                    'published_at' => array('type' => 'string'),
                    'image_url' => array('type' => 'string'),
                    'author_name' => array('type' => 'string'),
                    'source_type' => array('type' => 'string', 'enum' => array('external', 'publisher', 'rss', 'newsapi', 'social', 'video', 'admin')),
                    'platform' => array('type' => 'string', 'description' => 'youtube, x, instagram, tiktok, facebook, threads, reddit, etc.'),
                    'author_handle' => array('type' => 'string'),
                    'platform_post_id' => array('type' => 'string'),
                    'embed_html' => array('type' => 'string'),
                    'social_metrics' => array('type' => 'object', 'additionalProperties' => true),
                    'entities' => array('type' => 'object', 'additionalProperties' => true),
                    'ai_summary' => array('type' => 'string'),
                    'ai_key_points' => array('type' => 'array', 'items' => array('type' => 'string')),
                    'story_cluster_id' => array('type' => 'string'),
                    'editorial_notes' => array('type' => 'string'),
                ),
            );
            $customgpt_import_schema = array(
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => array(
                    'source' => array('type' => 'string'),
                    'origin_model' => array('type' => 'string'),
                    'mode' => array('type' => 'string', 'enum' => array('draft', 'pending', 'publish')),
                    'batch_id' => array('type' => 'string'),
                    'payload_json' => array('type' => 'string', 'description' => 'Fallback: raw JSON string containing a stories array. Prefer using stories directly.'),
                    'stories' => array('type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => $customgpt_story_schema),
                    'trending_signals' => array('type' => 'array', 'items' => array('type' => 'object', 'additionalProperties' => true)),
                ),
                'required' => array('stories'),
            );
            $customgpt_format_schema = array(
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => array(
                    'source' => array('type' => 'string'),
                    'origin_model' => array('type' => 'string'),
                    'batch_id' => array('type' => 'string'),
                    'payload_json' => array('type' => 'string', 'description' => 'Fallback: raw JSON string containing a stories array. Prefer using stories directly.'),
                    'stories' => array('type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => $customgpt_story_schema),
                ),
                'required' => array('stories'),
            );
            ?>
            <textarea class="large-text code" rows="18" readonly><?php echo esc_textarea(wp_json_encode(array(
                'openapi' => '3.1.0',
                'info' => array('title' => 'Rifnote CustomGPT Import', 'version' => '1.0.0'),
                'servers' => array(array('url' => home_url('/wp-json/rifnote/v1'))),
                'paths' => array(
                    '/customgpt/import/batch' => array(
                        'post' => array(
                            'operationId' => 'importRifnoteStories',
                            'summary' => 'Import a batch of Rifnote-ready stories.',
                            'requestBody' => array(
                                'required' => true,
                                'content' => array(
                                    'application/json' => array(
                                        'schema' => $customgpt_import_schema,
                                        'example' => array(
                                            'source' => 'CustomGPT',
                                            'mode' => 'draft',
                                            'batch_id' => 'html-dump-2026-07-10',
                                            'stories' => array(array(
                                                'title' => 'Story headline',
                                                'excerpt' => 'Short source-backed summary.',
                                                'body' => 'Clean article body or editorial brief.',
                                                'original_url' => 'https://source.example/story',
                                                'source_name' => 'Publisher Name',
                                                'source_url' => 'https://source.example/',
                                                'category' => 'Football',
                                                'tags' => array('Nigeria', 'Transfers'),
                                                'published_at' => '2026-07-10T12:00:00Z',
                                                'entities' => array('people' => array('Victor Osimhen'), 'teams' => array('Nigeria')),
                                            )),
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                    '/customgpt/stories' => array(
                        'get' => array(
                            'operationId' => 'getRifnoteStoriesForFormatting',
                            'summary' => 'Pull existing Rifnote stories from WordPress in CustomGPT-ready format.',
                            'parameters' => array(
                                array('name' => 'limit', 'in' => 'query', 'schema' => array('type' => 'integer', 'maximum' => 100)),
                                array('name' => 'status', 'in' => 'query', 'schema' => array('type' => 'string', 'enum' => array('any', 'publish', 'draft', 'pending'))),
                                array('name' => 'source_type', 'in' => 'query', 'schema' => array('type' => 'string')),
                                array('name' => 'origin_model', 'in' => 'query', 'schema' => array('type' => 'string')),
                                array('name' => 'origin_model_not', 'in' => 'query', 'schema' => array('type' => 'string'), 'description' => 'Use GPT to avoid pulling stories originally created by CustomGPT.'),
                                array('name' => 'category', 'in' => 'query', 'schema' => array('type' => 'string')),
                                array('name' => 'missing_summary', 'in' => 'query', 'schema' => array('type' => 'boolean')),
                                array('name' => 'incomplete', 'in' => 'query', 'schema' => array('type' => 'boolean'), 'description' => 'Pull stories missing summary, key points, entities, source name or original URL.'),
                                array('name' => 'q', 'in' => 'query', 'schema' => array('type' => 'string')),
                            ),
                        ),
                    ),
                    '/customgpt/format/batch' => array(
                        'post' => array(
                            'operationId' => 'formatRifnoteStories',
                            'summary' => 'Update existing Rifnote stories with cleaned formatting, summaries, key points, entities and metadata.',
                            'requestBody' => array(
                                'required' => true,
                                'content' => array(
                                    'application/json' => array(
                                        'schema' => $customgpt_format_schema,
                                        'example' => array(
                                            'batch_id' => 'format-missing-summaries-2026-07-10',
                                            'stories' => array(array(
                                                'post_id' => 123,
                                                'title' => 'Cleaned story headline',
                                                'excerpt' => 'Cleaner short excerpt.',
                                                'ai_summary' => 'Two sentence source-backed summary.',
                                                'ai_key_points' => array('Key point one', 'Key point two'),
                                                'entities' => array('people' => array('Victor Osimhen')),
                                            )),
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                    '/customgpt/aggregation/batch' => array(
                        'post' => array(
                            'operationId' => 'aggregateRifnoteStories',
                            'summary' => 'Create or update Rifnote Full Coverage aggregation hubs and attach existing stories.',
                            'requestBody' => array(
                                'required' => true,
                                'content' => array(
                                    'application/json' => array(
                                        'schema' => array(
                                            'type' => 'object',
                                            'additionalProperties' => false,
                                            'properties' => array(
                                                'source' => array('type' => 'string'),
                                                'batch_id' => array('type' => 'string'),
                                                'status' => array('type' => 'string', 'enum' => array('draft', 'review', 'published', 'archived')),
                                                'clusters' => array(
                                                    'type' => 'array',
                                                    'items' => array(
                                                        'type' => 'object',
                                                        'additionalProperties' => true,
                                                        'properties' => array(
                                                            'cluster_id' => array('type' => 'string', 'description' => 'Use manual_topic_slug. This becomes /story/{cluster_id}/.'),
                                                            'title' => array('type' => 'string'),
                                                            'summary' => array('type' => 'string'),
                                                            'category' => array('type' => 'string'),
                                                            'image_url' => array('type' => 'string'),
                                                            'status' => array('type' => 'string', 'enum' => array('draft', 'review', 'published', 'archived')),
                                                            'post_ids' => array('type' => 'array', 'items' => array('type' => 'integer')),
                                                            'stories' => array(
                                                                'type' => 'array',
                                                                'items' => array(
                                                                    'type' => 'object',
                                                                    'additionalProperties' => true,
                                                                    'properties' => array(
                                                                        'post_id' => array('type' => 'integer'),
                                                                        'ai_summary' => array('type' => 'string'),
                                                                        'ai_key_points' => array('type' => 'array', 'items' => array('type' => 'string')),
                                                                        'entities' => array('type' => 'object', 'additionalProperties' => true),
                                                                    ),
                                                                ),
                                                            ),
                                                            'editorial_notes' => array('type' => 'string'),
                                                        ),
                                                        'required' => array('title'),
                                                    ),
                                                ),
                                                'stories' => array(
                                                    'type' => 'array',
                                                    'description' => 'Alternative payload. Stories are grouped by story_cluster_id or cluster_id.',
                                                    'items' => array(
                                                        'type' => 'object',
                                                        'additionalProperties' => true,
                                                        'properties' => array(
                                                            'post_id' => array('type' => 'integer'),
                                                            'story_cluster_id' => array('type' => 'string'),
                                                            'cluster_id' => array('type' => 'string'),
                                                            'aggregation_title' => array('type' => 'string'),
                                                            'aggregation_summary' => array('type' => 'string'),
                                                            'category' => array('type' => 'string'),
                                                            'image_url' => array('type' => 'string'),
                                                            'ai_summary' => array('type' => 'string'),
                                                            'ai_key_points' => array('type' => 'array', 'items' => array('type' => 'string')),
                                                            'entities' => array('type' => 'object', 'additionalProperties' => true),
                                                        ),
                                                        'required' => array('post_id', 'story_cluster_id'),
                                                    ),
                                                ),
                                                'trending_signals' => array('type' => 'array', 'items' => array('type' => 'object', 'additionalProperties' => true)),
                                            ),
                                        ),
                                        'example' => array(
                                            'source' => 'CustomGPT Aggregation',
                                            'batch_id' => 'agg-osimhen-2026-07-28',
                                            'status' => 'draft',
                                            'clusters' => array(array(
                                                'cluster_id' => 'manual_osimhen_transfer_race',
                                                'title' => 'Osimhen transfer race heats up',
                                                'summary' => 'A short neutral overview of the developing story.',
                                                'category' => 'Football',
                                                'image_url' => 'https://source.example/image.jpg',
                                                'post_ids' => array(123, 124, 125),
                                                'stories' => array(array(
                                                    'post_id' => 123,
                                                    'ai_summary' => 'Clean summary for this source.',
                                                    'ai_key_points' => array('Club interest is active', 'No agreement has been confirmed'),
                                                    'entities' => array('people' => array('Victor Osimhen')),
                                                )),
                                            )),
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea>

            <h3><?php esc_html_e('Recent Import Logs', 'rifnote-search'); ?></h3>
            <p><?php echo esc_html(sprintf(__('Logs are stored daily at %s.', 'rifnote-search'), str_replace(WP_CONTENT_DIR . '/', 'wp-content/', Rifnote_Search_CustomGPT_Import::logs_base_dir()))); ?></p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Time', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Event', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Status', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Batch', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Created', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Duplicates', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Errors', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Log file', 'rifnote-search'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$customgpt_logs) : ?>
                        <tr><td colspan="8"><?php esc_html_e('No CustomGPT import logs yet.', 'rifnote-search'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($customgpt_logs as $log) : ?>
                        <tr>
                            <td><?php echo esc_html(!empty($log['logged_at']) ? get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($log['logged_at'])), 'M j, H:i') : ''); ?></td>
                            <td><code><?php echo esc_html($log['event'] ?? ''); ?></code></td>
                            <td><?php echo esc_html($log['status'] ?? ''); ?></td>
                            <td><?php echo esc_html($log['batch_id'] ?? ''); ?></td>
                            <td><?php echo esc_html((int) ($log['created'] ?? 0)); ?></td>
                            <td><?php echo esc_html((int) ($log['duplicates'] ?? 0)); ?></td>
                            <td><?php echo esc_html((int) ($log['errors'] ?? 0)); ?></td>
                            <td><small><?php echo esc_html($log['log_file'] ?? ''); ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if (self::is_section('publishers')) : ?>
            <hr />
            <h2><?php esc_html_e('Publisher feeds', 'rifnote-search'); ?></h2>
            <p><?php esc_html_e('Approve publishers before their RSS feeds are read. Trusted publishers can auto-publish indexed headlines; other approved feeds create review items.', 'rifnote-search'); ?></p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Publisher', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Feed', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Approval', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Verification', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Actions', 'rifnote-search'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$publishers) : ?>
                        <tr><td colspan="5"><?php esc_html_e('No publishers have registered yet.', 'rifnote-search'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($publishers as $publisher) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($publisher['publisher_name']); ?></strong><br />
                                <small><?php echo esc_html($publisher['contact_email']); ?></small><br />
                                <small><a href="<?php echo esc_url($publisher['website_url']); ?>" target="_blank" rel="noreferrer"><?php echo esc_html($publisher['website_url']); ?></a></small>
                            </td>
                            <td>
                                <?php if (!empty($publisher['rss_feed_url'])) : ?>
                                    <a href="<?php echo esc_url($publisher['rss_feed_url']); ?>" target="_blank" rel="noreferrer"><?php echo esc_html(wp_trim_words($publisher['rss_feed_url'], 8, '...')); ?></a>
                                <?php else : ?>
                                    <span><?php esc_html_e('No RSS feed', 'rifnote-search'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo esc_html(ucfirst($publisher['approval_status'])); ?><br />
                                <small><?php echo !empty($publisher['auto_approve']) ? esc_html__('Trusted auto-index', 'rifnote-search') : esc_html__('Review before indexing', 'rifnote-search'); ?></small>
                            </td>
                            <td>
                                <?php echo esc_html(ucfirst($publisher['verification_status'])); ?><br />
                                <small><?php echo esc_html(Rifnote_Search_Publishers::verification_url((int) $publisher['id'])); ?></small>
                            </td>
                            <td>
                                <form method="post" style="display:inline-block;margin-right:6px;">
                                    <?php wp_nonce_field('rifnote_publisher_action', 'rifnote_publisher_nonce'); ?>
                                    <input type="hidden" name="rifnote_publisher_id" value="<?php echo esc_attr($publisher['id']); ?>" />
                                    <input type="hidden" name="rifnote_publisher_action" value="verify" />
                                    <?php submit_button(__('Verify', 'rifnote-search'), 'secondary small', 'submit', false); ?>
                                </form>
                                <form method="post" style="display:inline-block;margin-right:6px;">
                                    <?php wp_nonce_field('rifnote_publisher_action', 'rifnote_publisher_nonce'); ?>
                                    <input type="hidden" name="rifnote_publisher_id" value="<?php echo esc_attr($publisher['id']); ?>" />
                                    <input type="hidden" name="rifnote_publisher_action" value="rotate_api_key" />
                                    <?php submit_button(__('Rotate API key', 'rifnote-search'), 'secondary small', 'submit', false); ?>
                                </form>
                                <form method="post" style="display:inline-block;margin-right:6px;">
                                    <?php wp_nonce_field('rifnote_publisher_action', 'rifnote_publisher_nonce'); ?>
                                    <input type="hidden" name="rifnote_publisher_id" value="<?php echo esc_attr($publisher['id']); ?>" />
                                    <input type="hidden" name="rifnote_publisher_action" value="approve" />
                                    <?php submit_button(__('Approve', 'rifnote-search'), 'secondary small', 'submit', false); ?>
                                </form>
                                <form method="post" style="display:inline-block;margin-right:6px;">
                                    <?php wp_nonce_field('rifnote_publisher_action', 'rifnote_publisher_nonce'); ?>
                                    <input type="hidden" name="rifnote_publisher_id" value="<?php echo esc_attr($publisher['id']); ?>" />
                                    <input type="hidden" name="rifnote_publisher_action" value="trust" />
                                    <?php submit_button(__('Trust RSS', 'rifnote-search'), 'secondary small', 'submit', false); ?>
                                </form>
                                <form method="post" style="display:inline-block;margin-right:6px;">
                                    <?php wp_nonce_field('rifnote_publisher_action', 'rifnote_publisher_nonce'); ?>
                                    <input type="hidden" name="rifnote_publisher_id" value="<?php echo esc_attr($publisher['id']); ?>" />
                                    <input type="hidden" name="rifnote_publisher_action" value="review" />
                                    <?php submit_button(__('Require review', 'rifnote-search'), 'secondary small', 'submit', false); ?>
                                </form>
                                <form method="post" style="display:inline-block;">
                                    <?php wp_nonce_field('rifnote_publisher_action', 'rifnote_publisher_nonce'); ?>
                                    <input type="hidden" name="rifnote_publisher_id" value="<?php echo esc_attr($publisher['id']); ?>" />
                                    <input type="hidden" name="rifnote_publisher_action" value="suspend" />
                                    <?php submit_button(__('Suspend', 'rifnote-search'), 'delete small', 'submit', false); ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h3><?php esc_html_e('Publisher API events', 'rifnote-search'); ?></h3>
            <p><?php esc_html_e('Recent API key authentication, publisher submissions and integration events.', 'rifnote-search'); ?></p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Event', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Publisher', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Status', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Message', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('When', 'rifnote-search'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$publisher_events) : ?>
                        <tr><td colspan="5"><?php esc_html_e('No publisher API events yet.', 'rifnote-search'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($publisher_events as $event) : ?>
                        <tr>
                            <td><code><?php echo esc_html($event['event_type']); ?></code></td>
                            <td><?php echo esc_html($event['publisher_id'] ? '#' . $event['publisher_id'] : __('Unknown', 'rifnote-search')); ?></td>
                            <td><?php echo esc_html(ucfirst($event['status'])); ?></td>
                            <td><?php echo esc_html(wp_trim_words($event['message'], 14)); ?></td>
                            <td><?php echo esc_html(get_date_from_gmt($event['created_at'], 'M j, H:i')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h3><?php esc_html_e('Webhook deliveries', 'rifnote-search'); ?></h3>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Event', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Publisher', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Status', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('HTTP', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Endpoint', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('When', 'rifnote-search'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$webhook_deliveries) : ?>
                        <tr><td colspan="6"><?php esc_html_e('No webhook deliveries yet.', 'rifnote-search'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($webhook_deliveries as $delivery) : ?>
                        <tr>
                            <td><code><?php echo esc_html($delivery['event_type']); ?></code></td>
                            <td><?php echo esc_html('#' . $delivery['publisher_id']); ?></td>
                            <td><?php echo esc_html(ucfirst($delivery['status'])); ?></td>
                            <td><?php echo esc_html($delivery['http_status'] ? (int) $delivery['http_status'] : '-'); ?></td>
                            <td><small><?php echo esc_html(wp_trim_words($delivery['target_url'], 9, '...')); ?></small></td>
                            <td><?php echo esc_html(get_date_from_gmt($delivery['created_at'], 'M j, H:i')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <hr />
            <h2><?php esc_html_e('RSS ingestion', 'rifnote-search'); ?></h2>
            <p>
                <?php
                echo esc_html($next_ingestion ? sprintf(
                    /* translators: %s: scheduled date */
                    __('Next scheduled run: %s.', 'rifnote-search'),
                    get_date_from_gmt(gmdate('Y-m-d H:i:s', (int) $next_ingestion), 'M j, Y H:i')
                ) : __('RSS cron is not currently scheduled.', 'rifnote-search'));
                ?>
            </p>
            <form method="post">
                <?php wp_nonce_field('rifnote_run_ingestion', 'rifnote_ingestion_nonce'); ?>
                <?php submit_button(__('Run RSS ingestion now', 'rifnote-search'), 'secondary'); ?>
            </form>

            <h2><?php esc_html_e('TheNewsAPI import', 'rifnote-search'); ?></h2>
            <p><?php esc_html_e('Fetch top stories from TheNewsAPI, dedupe them, store source metadata, cluster them and index published items for Rifnote Search.', 'rifnote-search'); ?></p>
            <p>
                <?php
                echo esc_html($next_thenewsapi ? sprintf(
                    /* translators: %s: scheduled date */
                    __('Next scheduled TheNewsAPI run: %s.', 'rifnote-search'),
                    get_date_from_gmt(gmdate('Y-m-d H:i:s', (int) $next_thenewsapi), 'M j, Y H:i')
                ) : __('TheNewsAPI auto import is not currently scheduled. Enable it in Settings and save an API token.', 'rifnote-search'));
                ?>
            </p>
            <form method="post">
                <?php wp_nonce_field('rifnote_run_thenewsapi', 'rifnote_thenewsapi_nonce'); ?>
                <?php submit_button(__('Run TheNewsAPI import now', 'rifnote-search'), 'secondary'); ?>
            </form>
            <?php if (!empty($thenewsapi_last_run) && is_array($thenewsapi_last_run)) : ?>
                <table class="widefat striped" style="margin:10px 0 18px;">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Last status', 'rifnote-search'); ?></th>
                            <td><?php echo esc_html(!empty($thenewsapi_last_run['configured']) ? __('Configured', 'rifnote-search') : __('Not configured', 'rifnote-search')); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Last run', 'rifnote-search'); ?></th>
                            <td><?php echo esc_html(!empty($thenewsapi_last_run['ran_at']) ? get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($thenewsapi_last_run['ran_at'])), 'M j, Y H:i') : __('Never', 'rifnote-search')); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Result', 'rifnote-search'); ?></th>
                            <td><?php echo esc_html(sprintf(
                                __('Checked %1$d, created %2$d, duplicates %3$d, errors %4$d. %5$s', 'rifnote-search'),
                                (int) ($thenewsapi_last_run['checked'] ?? 0),
                                (int) ($thenewsapi_last_run['created'] ?? 0),
                                (int) ($thenewsapi_last_run['duplicates'] ?? 0),
                                (int) ($thenewsapi_last_run['errors'] ?? 0),
                                (string) ($thenewsapi_last_run['message'] ?? '')
                            )); ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php endif; ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Publisher', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Status', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Last checked', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Created last run', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Last error', 'rifnote-search'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$feed_health) : ?>
                        <tr><td colspan="5"><?php esc_html_e('No RSS feeds have been submitted yet.', 'rifnote-search'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($feed_health as $feed) : ?>
                        <tr>
                            <td><?php echo esc_html($feed['publisher_name']); ?></td>
                            <td><?php echo esc_html(ucfirst($feed['feed_status'] ? $feed['feed_status'] : 'pending')); ?></td>
                            <td><?php echo !empty($feed['feed_last_checked']) ? esc_html(get_date_from_gmt($feed['feed_last_checked'], 'M j, Y H:i')) : esc_html__('Never', 'rifnote-search'); ?></td>
                            <td><?php echo esc_html((int) $feed['feed_items_indexed']); ?></td>
                            <td><?php echo esc_html($feed['feed_last_error']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <hr />
            <h2><?php esc_html_e('Submission queue', 'rifnote-search'); ?></h2>
            <p><?php esc_html_e('Review publisher submissions. Approved items become draft WordPress posts with source metadata attached.', 'rifnote-search'); ?></p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Story', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Publisher', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Status', 'rifnote-search'); ?></th>
                        <th><?php esc_html_e('Actions', 'rifnote-search'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$submissions) : ?>
                        <tr><td colspan="4"><?php esc_html_e('No publisher submissions yet.', 'rifnote-search'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($submissions as $submission) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($submission['headline']); ?></strong><br />
                                <small><a href="<?php echo esc_url($submission['original_url']); ?>" target="_blank" rel="noreferrer"><?php echo esc_html($submission['original_url']); ?></a></small>
                                <p><?php echo esc_html(wp_trim_words($submission['excerpt'], 24)); ?></p>
                            </td>
                            <td>
                                <?php echo esc_html($submission['publisher_name']); ?><br />
                                <small><?php echo esc_html($submission['contact_email']); ?></small>
                            </td>
                            <td><?php echo esc_html(ucfirst($submission['status'])); ?></td>
                            <td>
                                <?php if ('pending' === $submission['status']) : ?>
                                    <form method="post" style="display:inline-block;margin-right:6px;">
                                        <?php wp_nonce_field('rifnote_submission_action', 'rifnote_submission_nonce'); ?>
                                        <input type="hidden" name="rifnote_submission_id" value="<?php echo esc_attr($submission['id']); ?>" />
                                        <input type="hidden" name="rifnote_submission_action" value="approve" />
                                        <?php submit_button(__('Approve', 'rifnote-search'), 'secondary small', 'submit', false); ?>
                                    </form>
                                    <form method="post" style="display:inline-block;">
                                        <?php wp_nonce_field('rifnote_submission_action', 'rifnote_submission_nonce'); ?>
                                        <input type="hidden" name="rifnote_submission_id" value="<?php echo esc_attr($submission['id']); ?>" />
                                        <input type="hidden" name="rifnote_submission_action" value="reject" />
                                        <input type="hidden" name="rifnote_rejection_reason" value="<?php esc_attr_e('Rejected by editor.', 'rifnote-search'); ?>" />
                                        <?php submit_button(__('Reject', 'rifnote-search'), 'delete small', 'submit', false); ?>
                                    </form>
                                <?php elseif (!empty($submission['wp_post_id'])) : ?>
                                    <a href="<?php echo esc_url(get_edit_post_link((int) $submission['wp_post_id'])); ?>"><?php esc_html_e('Edit draft', 'rifnote-search'); ?></a>
                                <?php else : ?>
                                    <span><?php esc_html_e('No action available', 'rifnote-search'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php endif; ?>

            <?php if (self::is_section('discovery')) : ?>
            <hr />
            <h2><?php esc_html_e('Trending control', 'rifnote-search'); ?></h2>
            <p><?php esc_html_e('Pin, boost, hide or expire topics that appear in the public trending module.', 'rifnote-search'); ?></p>
            <form method="post">
                <?php wp_nonce_field('rifnote_save_trending', 'rifnote_trending_nonce'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="rifnote_trending_topic"><?php esc_html_e('Topic', 'rifnote-search'); ?></label></th>
                            <td><input id="rifnote_trending_topic" class="regular-text" name="rifnote_trending_topic" placeholder="Osimhen" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_trending_score"><?php esc_html_e('Base score', 'rifnote-search'); ?></label></th>
                            <td><input id="rifnote_trending_score" type="number" step="0.1" name="rifnote_trending_score" value="0" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_trending_boost"><?php esc_html_e('Manual boost', 'rifnote-search'); ?></label></th>
                            <td><input id="rifnote_trending_boost" type="number" step="0.1" name="rifnote_trending_boost" value="0" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Controls', 'rifnote-search'); ?></th>
                            <td>
                                <label><input type="checkbox" name="rifnote_trending_pinned" value="1" /> <?php esc_html_e('Pin topic', 'rifnote-search'); ?></label><br />
                                <label><input type="checkbox" name="rifnote_trending_hidden" value="1" /> <?php esc_html_e('Hide topic', 'rifnote-search'); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_trending_expires"><?php esc_html_e('Expires at', 'rifnote-search'); ?></label></th>
                            <td><input id="rifnote_trending_expires" type="datetime-local" name="rifnote_trending_expires" /></td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(__('Save trending topic', 'rifnote-search'), 'secondary'); ?>
            </form>

            <h3><?php esc_html_e('Current public topics', 'rifnote-search'); ?></h3>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e('Topic', 'rifnote-search'); ?></th><th><?php esc_html_e('Score', 'rifnote-search'); ?></th><th><?php esc_html_e('Pinned', 'rifnote-search'); ?></th><th><?php esc_html_e('Source', 'rifnote-search'); ?></th></tr></thead>
                <tbody>
                    <?php foreach ($trending as $topic) : ?>
                        <tr>
                            <td><?php echo esc_html($topic['topic']); ?></td>
                            <td><?php echo esc_html(number_format_i18n((float) $topic['score'], 2)); ?></td>
                            <td><?php echo !empty($topic['is_pinned']) ? esc_html__('Yes', 'rifnote-search') : esc_html__('No', 'rifnote-search'); ?></td>
                            <td><?php echo esc_html($topic['source']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h3><?php esc_html_e('Trending intelligence', 'rifnote-search'); ?></h3>
            <p><?php esc_html_e('Aliases merge messy topics into one clean label. Blocked terms stop junk words, encoded fragments and generic phrases from trending.', 'rifnote-search'); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields('rifnote_search_settings'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="rifnote_trending_aliases"><?php esc_html_e('Topic aliases', 'rifnote-search'); ?></label></th>
                            <td>
                                <textarea id="rifnote_trending_aliases" class="large-text code" rows="6" name="rifnote_trending_aliases"><?php echo esc_textarea(Rifnote_Search_Trending::alias_lines()); ?></textarea>
                                <p class="description"><?php esc_html_e('One per line: Canonical Topic=alias,alias. Example: Kylian Mbappe=Mbappe,K. Mbappe.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_trending_blocked_terms"><?php esc_html_e('Blocked trend terms', 'rifnote-search'); ?></label></th>
                            <td>
                                <textarea id="rifnote_trending_blocked_terms" class="large-text code" rows="5" name="rifnote_trending_blocked_terms"><?php echo esc_textarea(Rifnote_Search_Trending::blocked_terms_text()); ?></textarea>
                                <p class="description"><?php esc_html_e('One per line or comma-separated. Use this for junk fragments, generic words and topics you never want surfaced.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rifnote_trending_internet_feeds"><?php esc_html_e('Internet trend feeds', 'rifnote-search'); ?></label></th>
                            <td>
                                <textarea id="rifnote_trending_internet_feeds" class="large-text code" rows="7" name="rifnote_trending_internet_feeds"><?php echo esc_textarea(Rifnote_Search_Trending::internet_feeds_text()); ?></textarea>
                                <p class="description"><?php esc_html_e('One per line: Lane|Weight|Feed URL. Defaults use Google Trends RSS. Add social RSS/JSON bridges for X, Reddit, YouTube, Threads, TikTok or other sources when available.', 'rifnote-search'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(__('Save trending intelligence', 'rifnote-search'), 'secondary'); ?>
            </form>

            <h3><?php esc_html_e('CustomGPT trending signals', 'rifnote-search'); ?></h3>
            <p><strong><?php esc_html_e('Endpoint:', 'rifnote-search'); ?></strong> <code><?php echo esc_html(rest_url('rifnote/v1/customgpt/trending/signals')); ?></code></p>
            <p class="description"><?php esc_html_e('CustomGPT can POST signals with topic, type, category, score_boost, confidence, expires_in_minutes, aliases and reason. GPT influence is capped so user/search/news activity still wins.', 'rifnote-search'); ?></p>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e('Topic', 'rifnote-search'); ?></th><th><?php esc_html_e('Type', 'rifnote-search'); ?></th><th><?php esc_html_e('Category', 'rifnote-search'); ?></th><th><?php esc_html_e('Boost', 'rifnote-search'); ?></th><th><?php esc_html_e('Confidence', 'rifnote-search'); ?></th><th><?php esc_html_e('Expires', 'rifnote-search'); ?></th><th><?php esc_html_e('Reason', 'rifnote-search'); ?></th></tr></thead>
                <tbody>
                    <?php if (!$trending_signals) : ?>
                        <tr><td colspan="7"><?php esc_html_e('No GPT trending signals yet.', 'rifnote-search'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($trending_signals as $signal) : ?>
                        <tr>
                            <td><strong><?php echo esc_html($signal['topic']); ?></strong><br /><small><?php echo esc_html($signal['source_actor']); ?> · <?php echo esc_html($signal['batch_id']); ?></small></td>
                            <td><?php echo esc_html($signal['topic_type']); ?></td>
                            <td><?php echo esc_html($signal['category']); ?></td>
                            <td><?php echo esc_html(number_format_i18n((float) $signal['score_boost'], 2)); ?></td>
                            <td><?php echo esc_html(number_format_i18n((float) $signal['confidence'], 2)); ?></td>
                            <td><?php echo esc_html($signal['expires_at'] ? get_date_from_gmt($signal['expires_at'], 'M j, H:i') : __('Never', 'rifnote-search')); ?></td>
                            <td><?php echo esc_html(wp_trim_words($signal['reason'], 14)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <hr />
            <h2><?php esc_html_e('Shortcodes', 'rifnote-search'); ?></h2>
            <code>[rifnote_search_app]</code>
            <code>[rifnote_search_bar]</code>
            <code>[rifnote_trending_news]</code>
            <code>[rifnote_ai_answer]</code>
            <code>[rifnote_publisher_submit]</code>
            <code>[rifnote_publisher_dashboard]</code>
            <code>[rifnote_live_scores]</code>
            <code>[rifnote_football_hub]</code>
            <code>[rifnote_team_search]</code>
            <code>[rifnote_player_search]</code>
            <code>[rifnote_transfer_tracker]</code>
            <code>[rifnote_legal_request]</code>
            <code>[rifnote_dmca_request]</code>
            <code>[rifnote_publisher_opt_out]</code>
            <code>[rifnote_beta_feedback]</code>
            <?php endif; ?>

            <div id="rifnote-search-admin-root" class="rifnote-search-root" data-rifnote-mode="admin"></div>
        </div>
        <?php
    }

    private static function render_social_admin_page($settings, $last_run, $recent_posts) {
        $categories = get_terms(array('taxonomy' => 'category', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC'));
        $next_run = wp_next_scheduled(Rifnote_Search_Social::CRON_HOOK);
        $section = self::current_section();
        $published_count = 0;
        $draft_count = 0;
        $video_count = 0;
        $social_count = 0;

        foreach ($recent_posts as $post) {
            if ('publish' === get_post_status($post)) {
                $published_count++;
            } else {
                $draft_count++;
            }

            $source_type = get_post_meta($post->ID, 'source_type', true);
            if ('video' === $source_type) {
                $video_count++;
            }
            if ('social' === $source_type) {
                $social_count++;
            }
        }

        if ('social-dashboard' === $section) :
        ?>
        <div style="display:grid;grid-template-columns:minmax(0,1.2fr) minmax(320px,.8fr);gap:18px;align-items:start;max-width:1240px;">
            <div class="card">
                <h2><?php esc_html_e('Social Media Desk', 'rifnote-search'); ?></h2>
                <p><?php esc_html_e('Bring YouTube, X, TikTok, Instagram, Facebook, Threads and Reddit links into Rifnote as searchable database stories with source logos, clean snippets and platform attribution.', 'rifnote-search'); ?></p>
                <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;">
                    <div style="background:#f6f7f7;border-radius:12px;padding:14px;">
                        <strong><?php echo esc_html(number_format_i18n(count($recent_posts))); ?></strong>
                        <p style="margin:.4em 0 0;"><?php esc_html_e('Recent social/video posts', 'rifnote-search'); ?></p>
                    </div>
                    <div style="background:#f6f7f7;border-radius:12px;padding:14px;">
                        <strong><?php echo esc_html(number_format_i18n($published_count)); ?></strong>
                        <p style="margin:.4em 0 0;"><?php esc_html_e('Published and searchable', 'rifnote-search'); ?></p>
                    </div>
                    <div style="background:#f6f7f7;border-radius:12px;padding:14px;">
                        <strong><?php echo esc_html(number_format_i18n($video_count)); ?></strong>
                        <p style="margin:.4em 0 0;"><?php esc_html_e('Video records', 'rifnote-search'); ?></p>
                    </div>
                    <div style="background:#f6f7f7;border-radius:12px;padding:14px;">
                        <strong><?php echo esc_html(number_format_i18n($social_count)); ?></strong>
                        <p style="margin:.4em 0 0;"><?php esc_html_e('Social records', 'rifnote-search'); ?></p>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2><?php esc_html_e('Automation health', 'rifnote-search'); ?></h2>
                <p><strong><?php echo esc_html(!empty($settings['youtube_enabled']) ? __('YouTube polling is on', 'rifnote-search') : __('YouTube polling is off', 'rifnote-search')); ?></strong></p>
                <p><?php echo esc_html($next_run ? sprintf(__('Next YouTube run: %s', 'rifnote-search'), get_date_from_gmt(gmdate('Y-m-d H:i:s', $next_run), 'M j, H:i')) : __('No YouTube cron scheduled.', 'rifnote-search')); ?></p>
                <p><?php echo esc_html(sprintf(__('Last run: %s', 'rifnote-search'), !empty($last_run['ran_at']) ? get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($last_run['ran_at'])), 'M j, H:i') : __('Never', 'rifnote-search'))); ?></p>
                <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=rifnote-search-social-import')); ?>"><?php esc_html_e('Import a social link', 'rifnote-search'); ?></a> <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=rifnote-search-social-youtube')); ?>"><?php esc_html_e('Configure YouTube', 'rifnote-search'); ?></a></p>
            </div>
        </div>
        <div class="card" style="max-width:1240px;margin-top:18px;">
            <h2><?php esc_html_e('Recent social/video stories', 'rifnote-search'); ?></h2>
            <?php self::render_social_recent_table($recent_posts); ?>
        </div>
        <?php
        return;
        endif;

        if ('social-youtube' === $section) :
        ?>
        <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(360px,.72fr);gap:18px;align-items:start;max-width:1240px;">
            <div class="card">
                <h2><?php esc_html_e('YouTube API settings', 'rifnote-search'); ?></h2>
                <form method="post" action="options.php">
                    <?php settings_fields('rifnote_social_settings'); ?>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e('Enable YouTube polling', 'rifnote-search'); ?></th>
                                <td><label><input type="checkbox" name="rifnote_social_youtube_enabled" value="1" <?php checked(!empty($settings['youtube_enabled'])); ?> /> <?php esc_html_e('Poll YouTube every 15 minutes when an API key is set.', 'rifnote-search'); ?></label></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="rifnote_social_youtube_api_key"><?php esc_html_e('YouTube Data API key', 'rifnote-search'); ?></label></th>
                                <td><input id="rifnote_social_youtube_api_key" class="regular-text" type="password" name="rifnote_social_youtube_api_key" value="<?php echo esc_attr($settings['youtube_api_key']); ?>" autocomplete="off" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="rifnote_social_youtube_queries"><?php esc_html_e('Search lanes', 'rifnote-search'); ?></label></th>
                                <td>
                                    <textarea id="rifnote_social_youtube_queries" class="large-text code" rows="8" name="rifnote_social_youtube_queries"><?php echo esc_textarea($settings['youtube_queries']); ?></textarea>
                                    <p class="description"><?php esc_html_e('One per line: search phrase | category | draft/pending/publish. Example: Osimhen transfer | Football | draft', 'rifnote-search'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Region and language', 'rifnote-search'); ?></th>
                                <td>
                                    <input class="small-text" type="text" name="rifnote_social_youtube_region_code" value="<?php echo esc_attr($settings['youtube_region_code']); ?>" maxlength="2" />
                                    <input class="small-text" type="text" name="rifnote_social_youtube_language" value="<?php echo esc_attr($settings['youtube_language']); ?>" maxlength="8" />
                                    <span class="description"><?php esc_html_e('Example: NG and en.', 'rifnote-search'); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="rifnote_social_youtube_max_results"><?php esc_html_e('Max videos per lane', 'rifnote-search'); ?></label></th>
                                <td><input id="rifnote_social_youtube_max_results" class="small-text" type="number" min="1" max="25" name="rifnote_social_youtube_max_results" value="<?php echo esc_attr($settings['youtube_max_results']); ?>" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="rifnote_social_default_mode"><?php esc_html_e('Default post status', 'rifnote-search'); ?></label></th>
                                <td>
                                    <select id="rifnote_social_default_mode" name="rifnote_social_default_mode">
                                        <?php foreach (array('draft' => __('Draft', 'rifnote-search'), 'pending' => __('Pending review', 'rifnote-search'), 'publish' => __('Publish', 'rifnote-search')) as $value => $label) : ?>
                                            <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['default_mode'], $value); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <?php submit_button(__('Save social settings', 'rifnote-search')); ?>
                </form>
            </div>

            <div class="card">
                <h2><?php esc_html_e('Run YouTube now', 'rifnote-search'); ?></h2>
                <p><?php esc_html_e('Pull fresh videos from the saved YouTube searches into the database. Free quota is limited, so keep searches tight.', 'rifnote-search'); ?></p>
                <form method="post">
                    <?php wp_nonce_field('rifnote_social_action', 'rifnote_social_nonce'); ?>
                    <input type="hidden" name="rifnote_social_action" value="run_youtube" />
                    <?php submit_button(__('Run YouTube import', 'rifnote-search'), 'primary', 'submit', false); ?>
                </form>
                <hr />
                <h3><?php esc_html_e('Status', 'rifnote-search'); ?></h3>
                <p><?php echo esc_html($next_run ? sprintf(__('Next run: %s', 'rifnote-search'), get_date_from_gmt(gmdate('Y-m-d H:i:s', $next_run), 'M j, H:i')) : __('No cron scheduled.', 'rifnote-search')); ?></p>
                <p><?php echo esc_html(sprintf(__('Tracked lanes: %d', 'rifnote-search'), count(Rifnote_Search_Social::youtube_queries()))); ?></p>
            </div>
        </div>
        <?php
        return;
        endif;

        if ('social-import' === $section) :
        ?>
        <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(360px,.72fr);gap:18px;align-items:start;max-width:1240px;">
            <div class="card">
                <h2><?php esc_html_e('Manual social URL import', 'rifnote-search'); ?></h2>
                <p><?php esc_html_e('Paste a social or video URL. Rifnote will fetch oEmbed/Open Graph data, generate a title and snippet when fields are blank, then save the item as a searchable database story.', 'rifnote-search'); ?></p>
                <form method="post">
                    <?php wp_nonce_field('rifnote_social_action', 'rifnote_social_nonce'); ?>
                    <input type="hidden" name="rifnote_social_action" value="import_url" />
                    <p><label><?php esc_html_e('Social URL', 'rifnote-search'); ?><br /><input class="large-text" type="url" name="rifnote_social_url" required placeholder="https://x.com/... or https://www.youtube.com/watch?v=..." /></label></p>
                    <p><label><?php esc_html_e('Headline override', 'rifnote-search'); ?><br /><input class="large-text" type="text" name="rifnote_social_title" placeholder="<?php esc_attr_e('Optional. Leave blank and Rifnote will generate it.', 'rifnote-search'); ?>" /></label></p>
                    <p><label><?php esc_html_e('Short note / search snippet', 'rifnote-search'); ?><br /><textarea class="large-text" rows="4" name="rifnote_social_excerpt" placeholder="<?php esc_attr_e('Optional. Leave blank and Rifnote will generate it from metadata.', 'rifnote-search'); ?>"></textarea></label></p>
                    <p>
                        <label><?php esc_html_e('Category', 'rifnote-search'); ?><br />
                            <select name="rifnote_social_category">
                                <option value="Social"><?php esc_html_e('Social', 'rifnote-search'); ?></option>
                                <?php foreach ($categories as $term) : ?>
                                    <option value="<?php echo esc_attr($term->name); ?>"><?php echo esc_html($term->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label style="margin-left:12px;"><?php esc_html_e('Status', 'rifnote-search'); ?><br />
                            <select name="rifnote_social_mode">
                                <option value="draft"><?php esc_html_e('Draft', 'rifnote-search'); ?></option>
                                <option value="pending"><?php esc_html_e('Pending review', 'rifnote-search'); ?></option>
                                <option value="publish"><?php esc_html_e('Publish', 'rifnote-search'); ?></option>
                            </select>
                        </label>
                    </p>
                    <?php submit_button(__('Import social link', 'rifnote-search'), 'primary'); ?>
                </form>
            </div>
            <div class="card">
                <h2><?php esc_html_e('What gets saved', 'rifnote-search'); ?></h2>
                <ul style="list-style:disc;margin-left:18px;">
                    <li><?php esc_html_e('A normal WordPress post, so search and archives can use it.', 'rifnote-search'); ?></li>
                    <li><?php esc_html_e('source_type as social or video.', 'rifnote-search'); ?></li>
                    <li><?php esc_html_e('Platform, post ID, source URL, original URL and embed HTML when available.', 'rifnote-search'); ?></li>
                    <li><?php esc_html_e('Generated title and snippet if the platform does not expose clean metadata.', 'rifnote-search'); ?></li>
                </ul>
                <p class="description"><?php esc_html_e('Publish status makes it searchable immediately. Draft keeps it in the Library for review.', 'rifnote-search'); ?></p>
            </div>
        </div>
        <?php
        return;
        endif;

        if ('social-library' === $section) :
        ?>
        <div class="card" style="max-width:1240px;">
            <h2><?php esc_html_e('Social library', 'rifnote-search'); ?></h2>
            <p><?php esc_html_e('Review imported social/video records. Published rows are available in search results; drafts can be cleaned up before going live.', 'rifnote-search'); ?></p>
            <?php self::render_social_recent_table($recent_posts); ?>
        </div>
        <?php
        return;
        endif;

        if ('social-customgpt' === $section) :
        ?>
        <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(360px,.72fr);gap:18px;align-items:start;max-width:1240px;">
            <div class="card">
                <h2><?php esc_html_e('CustomGPT social action', 'rifnote-search'); ?></h2>
                <p><?php esc_html_e('Use the same CustomGPT bearer key, but point social batches at this dedicated endpoint.', 'rifnote-search'); ?></p>
                <p><code><?php echo esc_html(rest_url('rifnote/v1/customgpt/social/batch')); ?></code></p>
                <pre style="white-space:pre-wrap;background:#0f172a;color:#e5e7eb;padding:14px;border-radius:10px;overflow:auto;"><?php echo esc_html(wp_json_encode(array(
                    'source' => 'CustomGPT Social',
                    'mode' => 'draft',
                    'batch_id' => 'social_20260728_01',
                    'stories' => array(array(
                        'title' => 'Short clear headline',
                        'original_url' => 'https://www.youtube.com/watch?v=...',
                        'source_name' => 'Channel or account name',
                        'source_url' => 'https://www.youtube.com/@channel',
                        'source_type' => 'video',
                        'platform' => 'youtube',
                        'author_handle' => '@channel',
                        'category' => 'Football',
                        'tags' => array('football', 'video'),
                        'excerpt' => 'Why this social post matters.',
                        'image_url' => 'https://...',
                        'platform_post_id' => 'abc123',
                        'embed_html' => '<iframe ...></iframe>',
                        'social_metrics' => array('likes' => 1200, 'comments' => 90),
                    )),
                ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
            </div>
            <div class="card">
                <h2><?php esc_html_e('Search display rules', 'rifnote-search'); ?></h2>
                <p><?php esc_html_e('CustomGPT social/video stories should include enough metadata for Rifnote to show them cleanly in All, Videos and Social tabs.', 'rifnote-search'); ?></p>
                <ul style="list-style:disc;margin-left:18px;">
                    <li><?php esc_html_e('Use source_type=social for posts from X, Instagram, TikTok, Threads, Facebook and Reddit.', 'rifnote-search'); ?></li>
                    <li><?php esc_html_e('Use source_type=video and platform=youtube for YouTube clips.', 'rifnote-search'); ?></li>
                    <li><?php esc_html_e('Always include title/headline, excerpt/summary and original_url when possible.', 'rifnote-search'); ?></li>
                    <li><?php esc_html_e('Include social_metrics only when you have real counts. Do not invent them.', 'rifnote-search'); ?></li>
                </ul>
            </div>
        </div>
        <?php
        return;
        endif;
    }

    private static function render_social_recent_table($recent_posts) {
        ?>
        <table class="widefat striped">
            <thead><tr><th><?php esc_html_e('Story', 'rifnote-search'); ?></th><th><?php esc_html_e('Platform', 'rifnote-search'); ?></th><th><?php esc_html_e('Status', 'rifnote-search'); ?></th><th><?php esc_html_e('Source', 'rifnote-search'); ?></th><th><?php esc_html_e('Search snippet', 'rifnote-search'); ?></th></tr></thead>
            <tbody>
                <?php if ($recent_posts) : foreach ($recent_posts as $post) : $source = Rifnote_Search_Source_Meta::source_payload($post->ID); ?>
                    <tr>
                        <td><a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>"><?php echo esc_html(get_the_title($post)); ?></a><br /><small><?php echo esc_html($source['original_url']); ?></small></td>
                        <td><?php echo esc_html($source['social_platform'] ? $source['social_platform'] : $source['source_type']); ?></td>
                        <td><?php echo esc_html(get_post_status($post)); ?></td>
                        <td><?php echo esc_html($source['source_name']); ?></td>
                        <td><?php echo esc_html(wp_trim_words(get_the_excerpt($post), 18)); ?></td>
                    </tr>
                <?php endforeach; else : ?>
                    <tr><td colspan="5"><?php esc_html_e('No social imports yet.', 'rifnote-search'); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private static function render_home_notes_admin_page() {
        $selected_ids = get_option('rifnote_home_note_post_ids', array());
        $selected_ids = is_array($selected_ids) ? array_slice(array_map('absint', $selected_ids), 0, 5) : array();
        $search = isset($_GET['note_search']) ? sanitize_text_field(wp_unslash($_GET['note_search'])) : '';
        $category = isset($_GET['note_category']) ? absint($_GET['note_category']) : 0;
        $query_args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 20,
            'orderby' => 'date',
            'order' => 'DESC',
        );

        if ($search) {
            $query_args['s'] = $search;
        }

        if ($category) {
            $query_args['cat'] = $category;
        }

        $query = new WP_Query($query_args);
        $categories = get_terms(array('taxonomy' => 'category', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC'));
        $selected_posts = array();

        foreach ($selected_ids as $id) {
            $post = $id ? get_post($id) : null;
            $selected_posts[] = ($post && 'publish' === $post->post_status) ? $post : null;
        }

        while (count($selected_posts) < 5) {
            $selected_posts[] = null;
        }

        echo '<div class="rs-admin-hero rs-home-notes-hero"><div><span>' . esc_html__('Homepage editorial', 'rifnote-search') . '</span><h2>' . esc_html__('Homepage desk', 'rifnote-search') . '</h2><p>' . esc_html__('Control the homepage pills. Notes stays manually curated here; every other pill is picked directly from the post editor or the Posts list dropdown.', 'rifnote-search') . '</p></div><div class="rs-admin-hero-meter"><b>Easy</b><small>' . esc_html__('editor picks', 'rifnote-search') . '</small></div></div>';

        echo '<div class="rs-admin-analytics-grid rs-admin-analytics-grid-main rs-home-notes-grid">';
        echo '<div class="rs-admin-card"><h2>' . esc_html__('Selected homepage stories', 'rifnote-search') . '</h2><p>' . esc_html__('Order matters. Empty slots are ignored. Notes uses the list summary style. Other pills show the latest posts assigned to that pill from the post edit screen or Posts list.', 'rifnote-search') . '</p>';
        echo '<form method="post" action="options.php" class="rs-home-notes-form">';
        settings_fields('rifnote_home_notes_settings');
        echo '<h3>' . esc_html__('Search pill lineup', 'rifnote-search') . '</h3>';
        echo '<p>' . esc_html__('Tick the pills you want under the homepage search, then set the order. Notes is locked on and always stays first because it powers the manual Live Notes desk.', 'rifnote-search') . '</p>';
        $selected_pills = self::home_pills();
        $selected_lookup = array();
        foreach ($selected_pills as $index => $pill) {
            $selected_lookup[strtolower($pill['category'])] = $index + 1;
        }
        echo '<div class="rs-home-pills-manager">';
        foreach (self::home_pill_options() as $index => $option) {
            $category = $option['category'];
            $label = $option['label'];
            $key = strtolower($category);
            $locked = !empty($option['locked']) || 'notes' === $key;
            $enabled = $locked || isset($selected_lookup[$key]);
            $order = $locked ? 1 : ($selected_lookup[$key] ?? ($index + 1));
            echo '<label class="rs-home-pill-option ' . esc_attr($enabled ? 'is-enabled' : '') . '">';
            echo '<span class="rs-home-pill-check">';
            echo '<input type="checkbox" name="rifnote_home_pills[' . esc_attr($index) . '][enabled]" value="1" ' . checked($enabled, true, false) . ' ' . disabled($locked, true, false) . ' />';
            if ($locked) {
                echo '<input type="hidden" name="rifnote_home_pills[' . esc_attr($index) . '][enabled]" value="1" />';
            }
            echo '<strong>' . esc_html($label) . '</strong>';
            echo '</span>';
            echo '<span class="rs-home-pill-meta">' . esc_html($locked ? __('Locked Notes pill', 'rifnote-search') : sprintf(__('Loads %s stories', 'rifnote-search'), $category)) . '</span>';
            echo '<input type="hidden" name="rifnote_home_pills[' . esc_attr($index) . '][label]" value="' . esc_attr($label) . '" />';
            echo '<input type="hidden" name="rifnote_home_pills[' . esc_attr($index) . '][category]" value="' . esc_attr($category) . '" />';
            echo '<input class="small-text" type="number" min="1" max="99" name="rifnote_home_pills[' . esc_attr($index) . '][order]" value="' . esc_attr($order) . '" ' . disabled($locked, true, false) . ' aria-label="' . esc_attr(sprintf(__('Order for %s pill', 'rifnote-search'), $label)) . '" />';
            echo '</label>';
        }
        echo '</div>';
        echo '<p class="description">' . esc_html__('Only checked pills appear. Newly created WordPress categories will show up here automatically.', 'rifnote-search') . '</p>';
        echo '<div class="notice notice-info inline"><p>' . wp_kses_post(__('To hand-pick stories for Nigeria, World, Football and the other pills, open any post and choose <strong>Rifnote Homepage Pill</strong>, or use the <strong>Homepage Pill</strong> dropdown on the Posts list. Notes stays manual below.', 'rifnote-search')) . '</p></div>';
        echo '<hr />';
        echo '<h3>' . esc_html__('Notes summary slots', 'rifnote-search') . '</h3>';
        echo '<div class="rs-home-note-slots">';
        foreach ($selected_posts as $index => $post) {
            $slot = $index + 1;
            $post_id = $post ? (int) $post->ID : 0;
            $has_hub = $post_id && class_exists('Rifnote_Search_Aggregation') && Rifnote_Search_Aggregation::post_has_story_hub($post_id);
            echo '<article>';
            echo '<div class="rs-home-note-slot-head"><span>' . esc_html(sprintf(__('Note %d', 'rifnote-search'), $slot)) . '</span><label>' . esc_html__('Post ID', 'rifnote-search') . ' <input class="small-text rs-home-note-slot-input" type="number" min="0" name="rifnote_home_note_post_ids[' . esc_attr($index) . '][post_id]" value="' . esc_attr($post_id) . '" data-note-slot="' . esc_attr($slot) . '" /></label><label>' . esc_html__('Order', 'rifnote-search') . ' <input class="small-text rs-home-note-order-input" type="number" min="1" max="99" name="rifnote_home_note_post_ids[' . esc_attr($index) . '][order]" value="' . esc_attr($slot) . '" /></label></div>';
            if ($post) {
                echo '<strong>' . esc_html(get_the_title($post)) . '</strong>';
                echo '<small>' . esc_html(get_the_date('M j, Y g:i A', $post)) . ' · ' . esc_html($has_hub ? __('Aggregation ready', 'rifnote-search') : __('No story hub yet', 'rifnote-search')) . '</small>';
            } else {
                echo '<strong>' . esc_html__('Empty slot', 'rifnote-search') . '</strong><small>' . esc_html__('Search below and add a story.', 'rifnote-search') . '</small>';
            }
            echo '</article>';
        }
        echo '</div>';

        submit_button(__('Save homepage editorial', 'rifnote-search'), 'primary');
        echo '</form></div>';

        echo '<div class="rs-admin-card"><h2>' . esc_html__('Search story library', 'rifnote-search') . '</h2><p>' . esc_html__('Find posts by headline, keyword, source or category. Click “Add to Notes” to fill the next empty slot.', 'rifnote-search') . '</p>';
        echo '<form method="get" class="rs-home-notes-search">';
        echo '<input type="hidden" name="page" value="rifnote-search-home-notes" />';
        echo '<input type="search" name="note_search" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Search stories...', 'rifnote-search') . '" />';
        echo '<select name="note_category"><option value="0">' . esc_html__('All categories', 'rifnote-search') . '</option>';
        foreach (is_array($categories) ? $categories : array() as $term) {
            echo '<option value="' . esc_attr((int) $term->term_id) . '" ' . selected($category, (int) $term->term_id, false) . '>' . esc_html($term->name) . '</option>';
        }
        echo '</select>';
        submit_button(__('Search', 'rifnote-search'), 'secondary', 'submit', false);
        if ($search || $category) {
            echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=rifnote-search-home-notes')) . '">' . esc_html__('Reset', 'rifnote-search') . '</a>';
        }
        echo '</form>';

        echo '<table class="widefat striped rs-home-notes-results"><thead><tr><th>' . esc_html__('Story', 'rifnote-search') . '</th><th>' . esc_html__('Source / category', 'rifnote-search') . '</th><th>' . esc_html__('Aggregation', 'rifnote-search') . '</th><th>' . esc_html__('Action', 'rifnote-search') . '</th></tr></thead><tbody>';
        if (!$query->have_posts()) {
            echo '<tr><td colspan="4">' . esc_html__('No stories matched that search.', 'rifnote-search') . '</td></tr>';
        }
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $source = get_post_meta($post_id, 'source_name', true);
            $has_hub = class_exists('Rifnote_Search_Aggregation') && Rifnote_Search_Aggregation::post_has_story_hub($post_id);
            echo '<tr>';
            echo '<td><strong>' . esc_html(get_the_title()) . '</strong><br /><small>ID ' . esc_html((string) $post_id) . ' · ' . esc_html(get_the_date('M j, Y g:i A')) . '</small></td>';
            echo '<td>' . esc_html($source ? $source : get_the_author()) . '<br /><small>' . esc_html(wp_strip_all_tags(get_the_category_list(', '))) . '</small></td>';
            echo '<td><span class="rs-home-note-hub ' . esc_attr($has_hub ? 'ready' : 'missing') . '">' . esc_html($has_hub ? __('Breakdown ready', 'rifnote-search') : __('Source link only', 'rifnote-search')) . '</span></td>';
            echo '<td><button type="button" class="button rs-home-note-add" data-post-id="' . esc_attr($post_id) . '">' . esc_html__('Add to Notes', 'rifnote-search') . '</button> <a class="button button-small" href="' . esc_url(get_edit_post_link($post_id)) . '">' . esc_html__('Edit', 'rifnote-search') . '</a></td>';
            echo '</tr>';
        }
        wp_reset_postdata();
        echo '</tbody></table></div></div>';

        echo '<script>
        document.addEventListener("click", function(event) {
          var button = event.target.closest(".rs-home-note-add");
          if (!button) return;
          var inputs = Array.prototype.slice.call(document.querySelectorAll(".rs-home-note-slot-input"));
          var target = inputs.find(function(input) { return !input.value || input.value === "0"; }) || inputs[0];
          if (target) {
            target.value = button.getAttribute("data-post-id") || "";
            var row = target.closest("article");
            var order = row ? row.querySelector(".rs-home-note-order-input") : null;
            if (order && (!order.value || order.value === "0")) {
              order.value = target.getAttribute("data-note-slot") || "1";
            }
            target.focus();
          }
        });
        </script>';
    }

    private static function render_home_featured_tab_admin_page() {
        $settings = self::home_featured_tab();
        $categories = get_terms(array('taxonomy' => 'category', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC'));
        $tags = get_terms(array('taxonomy' => 'post_tag', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC', 'number' => 250));
        $preview_ids = self::home_featured_tab_post_ids(5, $settings);

        echo '<div class="rs-admin-hero rs-home-notes-hero"><div><span>' . esc_html__('Homepage takeover', 'rifnote-search') . '</span><h2>' . esc_html__('Featured first tab', 'rifnote-search') . '</h2><p>' . esc_html__('Use this when a big topic deserves the first slot. It replaces Notes on the homepage until you switch it off, then Notes returns automatically.', 'rifnote-search') . '</p></div><div class="rs-admin-hero-meter"><b>' . esc_html($settings['enabled'] ? __('On', 'rifnote-search') : __('Off', 'rifnote-search')) . '</b><small>' . esc_html__('homepage tab', 'rifnote-search') . '</small></div></div>';

        echo '<div class="rs-admin-analytics-grid rs-admin-analytics-grid-main rs-home-notes-grid">';
        echo '<div class="rs-admin-card"><h2>' . esc_html__('Featured tab setup', 'rifnote-search') . '</h2><p>' . esc_html__('Pick a source. Category and tag are best for fast editorial control; search works when you want a topic-driven feed.', 'rifnote-search') . '</p>';
        echo '<form method="post" action="options.php">';
        settings_fields('rifnote_home_featured_tab_settings');
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('Enable takeover', 'rifnote-search') . '</th><td><label><input type="checkbox" name="rifnote_home_featured_tab[enabled]" value="1" ' . checked(!empty($settings['enabled']), true, false) . ' /> ' . esc_html__('Replace Notes as the first homepage pill', 'rifnote-search') . '</label></td></tr>';
        echo '<tr><th scope="row"><label for="rifnote-home-featured-label">' . esc_html__('Tab label', 'rifnote-search') . '</label></th><td><input id="rifnote-home-featured-label" class="regular-text" type="text" name="rifnote_home_featured_tab[label]" value="' . esc_attr($settings['label']) . '" placeholder="' . esc_attr__('Featured', 'rifnote-search') . '" /></td></tr>';
        echo '<tr><th scope="row"><label for="rifnote-home-featured-source">' . esc_html__('Content source', 'rifnote-search') . '</label></th><td><select id="rifnote-home-featured-source" name="rifnote_home_featured_tab[source]">';
        foreach (array('category' => __('Category', 'rifnote-search'), 'tag' => __('Tag', 'rifnote-search'), 'search' => __('Search query', 'rifnote-search')) as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($settings['source'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row"><label for="rifnote-home-featured-category">' . esc_html__('Category', 'rifnote-search') . '</label></th><td><select id="rifnote-home-featured-category" name="rifnote_home_featured_tab[category_id]"><option value="0">' . esc_html__('Choose a category', 'rifnote-search') . '</option>';
        foreach (is_array($categories) ? $categories : array() as $term) {
            echo '<option value="' . esc_attr((int) $term->term_id) . '" ' . selected((int) $settings['category_id'], (int) $term->term_id, false) . '>' . esc_html($term->name) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row"><label for="rifnote-home-featured-tag">' . esc_html__('Tag', 'rifnote-search') . '</label></th><td><select id="rifnote-home-featured-tag" name="rifnote_home_featured_tab[tag_id]"><option value="0">' . esc_html__('Choose a tag', 'rifnote-search') . '</option>';
        foreach (is_array($tags) ? $tags : array() as $term) {
            echo '<option value="' . esc_attr((int) $term->term_id) . '" ' . selected((int) $settings['tag_id'], (int) $term->term_id, false) . '>' . esc_html($term->name) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row"><label for="rifnote-home-featured-query">' . esc_html__('Search query', 'rifnote-search') . '</label></th><td><input id="rifnote-home-featured-query" class="regular-text" type="text" name="rifnote_home_featured_tab[query]" value="' . esc_attr($settings['query']) . '" placeholder="' . esc_attr__('Osun election, World Cup final, transfer window...', 'rifnote-search') . '" /><p class="description">' . esc_html__('Used only when Content source is Search query.', 'rifnote-search') . '</p></td></tr>';
        echo '</tbody></table>';
        submit_button(__('Save featured tab', 'rifnote-search'), 'primary');
        echo '</form></div>';

        echo '<div class="rs-admin-card"><h2>' . esc_html__('Current preview', 'rifnote-search') . '</h2>';
        if (!$settings['enabled']) {
            echo '<p>' . esc_html__('Featured takeover is off. Notes is still the first homepage pill.', 'rifnote-search') . '</p>';
        } elseif (!$preview_ids) {
            echo '<p>' . esc_html__('No matching published posts yet. Check the selected source or add matching stories.', 'rifnote-search') . '</p>';
        } else {
            echo '<div class="rs-home-note-slots">';
            foreach ($preview_ids as $index => $post_id) {
                $post = get_post($post_id);
                if (!$post) {
                    continue;
                }

                echo '<article>';
                echo '<div class="rs-home-note-slot-head"><span>' . esc_html(sprintf(__('Item %d', 'rifnote-search'), $index + 1)) . '</span></div>';
                echo '<strong>' . esc_html(get_the_title($post)) . '</strong>';
                echo '<small>' . esc_html(get_the_date('M j, Y g:i A', $post)) . '</small>';
                echo '</article>';
            }
            echo '</div>';
            $archive_url = self::home_featured_tab_archive_url($settings);
            if ($archive_url) {
                echo '<p><a class="button" href="' . esc_url($archive_url) . '" target="_blank" rel="noopener">' . esc_html__('Open archive', 'rifnote-search') . '</a></p>';
            }
        }
        echo '</div></div>';
    }

    private static function render_ads_admin_page($section, $launch_summary, $sponsor_requests, $sponsored, $ad_inventory, $audience_summary, $ad_performance) {
        $pending = 0;
        $approved = 0;
        $active_requests = 0;
        $estimated_pipeline = 0;

        foreach ($sponsor_requests as $request) {
            if ('new' === ($request['status'] ?? '')) {
                $pending++;
            }
            if (in_array($request['status'] ?? '', array('approved', 'paid'), true)) {
                $approved++;
            }
            if ('active' === ($request['status'] ?? '')) {
                $active_requests++;
            }
            $estimated_pipeline += (float) ($request['estimated_price'] ?? 0);
        }

        if (self::is_section('ads-dashboard')) {
            self::render_ads_command_center($sponsor_requests, $sponsored, $ad_inventory, $audience_summary, array(
                'pending' => $pending,
                'approved' => $approved,
                'active_requests' => $active_requests,
                'pipeline' => $estimated_pipeline,
                'active_placements' => (int) ($launch_summary['sponsored'] ?? count($sponsored)),
            ));

            echo '<div class="rs-admin-analytics-grid rs-admin-analytics-grid-main">';
            echo '<div class="rs-admin-card"><h2>' . esc_html__('Latest campaign requests', 'rifnote-search') . '</h2><p>' . esc_html__('Fresh briefs, approvals, and campaigns waiting to go live.', 'rifnote-search') . '</p>';
            self::render_ads_requests_table(array_slice($sponsor_requests, 0, 8), true);
            echo '</div>';
            echo '<div class="rs-admin-card">';
            self::render_ads_recommendations($audience_summary, $ad_inventory);
            echo '</div></div>';
            echo '<div class="rs-admin-analytics-grid rs-admin-analytics-grid-main">';
            echo '<div class="rs-admin-card"><h2>' . esc_html__('Sponsored performance', 'rifnote-search') . '</h2><p>' . esc_html__('CTR, impressions and click health from tracked placements.', 'rifnote-search') . '</p>';
            self::render_ads_export_buttons();
            self::render_ad_performance_table($ad_performance['top_slots'] ?? array(), true);
            echo '</div><div class="rs-admin-card"><h2>' . esc_html__('Creative fixes to make', 'rifnote-search') . '</h2>';
            self::render_ad_weak_slots($ad_performance['weak_slots'] ?? array());
            echo '</div></div>';
            echo '<div class="rs-admin-analytics-grid rs-admin-analytics-grid-main">';
            echo '<div class="rs-admin-card"><h2>' . esc_html__('Campaign pacing', 'rifnote-search') . '</h2><p>' . esc_html__('Delivery progress against campaign windows and estimated impressions.', 'rifnote-search') . '</p>';
            self::render_ad_pacing_table($ad_performance['campaign_pacing'] ?? array(), true);
            echo '</div><div class="rs-admin-card"><h2>' . esc_html__('Risk watch', 'rifnote-search') . '</h2>';
            self::render_ad_risk_alerts($ad_performance['risk_alerts'] ?? array());
            echo '</div></div>';
            return;
        }

        if (self::is_section('ads-requests')) {
            echo '<h2>' . esc_html__('Campaign request pipeline', 'rifnote-search') . '</h2>';
            echo '<p>' . esc_html__('Move advertisers from new brief to approved, paid and active. Activation turns selected pressure points into live sponsored placements.', 'rifnote-search') . '</p>';
            self::render_ads_requests_table($sponsor_requests, false);
            return;
        }

        if (self::is_section('ads-creative')) {
            echo '<div class="rs-admin-hero"><div><span>' . esc_html__('Creative Studio', 'rifnote-search') . '</span><h2>' . esc_html__('Make every sponsored slot feel intentional.', 'rifnote-search') . '</h2><p>' . esc_html__('Manage ad assets, headline variants, CTA copy, placement previews, approvals and creative history before campaigns go live.', 'rifnote-search') . '</p></div><div class="rs-admin-hero-meter"><b>' . esc_html(number_format_i18n(count($sponsor_requests))) . '</b><small>' . esc_html__('campaign briefs available', 'rifnote-search') . '</small></div></div>';
            self::render_creative_studio($sponsor_requests, $ad_inventory);
            return;
        }

        if (self::is_section('ads-audience')) {
            echo '<h2>' . esc_html__('Audience intelligence', 'rifnote-search') . '</h2>';
            echo '<p>' . esc_html__('First-party guest, recurring visitor and registered-user signals for smarter ad targeting. Useful intent, no invasive tracking drama.', 'rifnote-search') . '</p>';
            echo '<div class="rs-admin-kpi-grid">';
            self::render_admin_kpi(__('Visitors', 'rifnote-search'), $audience_summary['visitors'] ?? 0, __('Addressable audience', 'rifnote-search'));
            self::render_admin_kpi(__('Guests', 'rifnote-search'), $audience_summary['guests'] ?? 0, __('Anonymous but targetable', 'rifnote-search'));
            self::render_admin_kpi(__('Returning', 'rifnote-search'), $audience_summary['returning'] ?? 0, __('People coming back', 'rifnote-search'));
            self::render_admin_kpi(__('Ad clickers', 'rifnote-search'), $audience_summary['ad_responsive'] ?? 0, __('Already responsive', 'rifnote-search'));
            echo '</div>';
            echo '<div class="rs-admin-analytics-grid rs-admin-analytics-grid-main">';
            echo '<div class="rs-admin-card rs-admin-map-card"><h2>' . esc_html__('Where the crowd is', 'rifnote-search') . '</h2><p>' . esc_html__('Country and state/region concentration for campaign targeting.', 'rifnote-search') . '</p>';
            self::render_location_panel($audience_summary['country_rows'] ?? array(), $audience_summary['region_rows'] ?? array());
            echo '</div><div class="rs-admin-card">';
            self::render_ads_recommendations($audience_summary, $ad_inventory);
            echo '</div></div>';
            echo '<div class="rs-admin-analytics-grid">';
            self::render_admin_bar_card(__('Ad-ready segments', 'rifnote-search'), $audience_summary['segments'] ?? array());
            self::render_admin_bar_card(__('Top interests', 'rifnote-search'), $audience_summary['top_interests'] ?? array());
            self::render_admin_bar_card(__('Device mix', 'rifnote-search'), $audience_summary['device_mix'] ?? array());
            echo '</div>';
            return;
        }

        if (self::is_section('ads-placements')) {
            self::render_ads_placement_form($ad_inventory);
            echo '<h2>' . esc_html__('Live and recent sponsored placements', 'rifnote-search') . '</h2>';
            self::render_ads_placements_table($sponsored);
            echo '<div class="rs-admin-card" style="margin-top:18px;"><h2>' . esc_html__('Placement reporting', 'rifnote-search') . '</h2>';
            self::render_ads_export_buttons();
            self::render_ad_performance_table($ad_performance['placements'] ?? array(), false);
            echo '</div>';
            echo '<div class="rs-admin-card" style="margin-top:18px;"><h2>' . esc_html__('Campaign pacing', 'rifnote-search') . '</h2>';
            self::render_ad_pacing_table($ad_performance['campaign_pacing'] ?? array(), false);
            echo '</div>';
            return;
        }

        if (self::is_section('ads-reports')) {
            $days = self::sanitize_report_days(get_option('rifnote_ads_report_days', 30));
            $revenue = Rifnote_Search_Analytics::ad_revenue_report($days);
            $sources = Rifnote_Search_Analytics::source_performance_report($days);
            $summary = $revenue['summary'] ?? array();

            echo '<div class="rs-admin-hero"><div><span>' . esc_html__('Reports desk', 'rifnote-search') . '</span><h2>' . esc_html__('Board-room exports without spreadsheet stress.', 'rifnote-search') . '</h2><p>' . esc_html__('Pull campaign, advertiser, publisher/source and revenue reports from Rifnote’s own data. Export CSVs, open printable campaign reports, and keep scheduled report settings in one place.', 'rifnote-search') . '</p></div><div class="rs-admin-hero-meter"><b>₦' . esc_html(number_format_i18n((float) ($summary['paid'] ?? 0))) . '</b><small>' . esc_html__('paid value tracked', 'rifnote-search') . '</small></div></div>';
            echo '<div class="rs-admin-kpi-grid">';
            self::render_admin_kpi(__('Pipeline', 'rifnote-search'), '₦' . number_format_i18n((float) ($summary['pipeline'] ?? 0)), __('Briefs still moving', 'rifnote-search'));
            self::render_admin_kpi(__('Booked', 'rifnote-search'), '₦' . number_format_i18n((float) ($summary['booked'] ?? 0)), __('Paid, active or completed', 'rifnote-search'));
            self::render_admin_kpi(__('Completed', 'rifnote-search'), '₦' . number_format_i18n((float) ($summary['completed'] ?? 0)), __('Closed campaign value', 'rifnote-search'));
            self::render_admin_kpi(__('Unsold slots', 'rifnote-search'), $summary['unsold_inventory'] ?? 0, __('Pressure points still open', 'rifnote-search'));
            echo '</div>';

            echo '<div class="rs-admin-card"><h2>' . esc_html__('Export center', 'rifnote-search') . '</h2><p>' . esc_html__('Download clean CSVs for finance, sales calls, advertiser check-ins and publisher/source performance reviews.', 'rifnote-search') . '</p>';
            self::render_ads_export_buttons();
            echo '</div>';

            echo '<div class="rs-admin-analytics-grid rs-admin-analytics-grid-main">';
            echo '<div class="rs-admin-card"><h2>' . esc_html__('Campaign reports', 'rifnote-search') . '</h2><p>' . esc_html__('Pacing, delivery and outcome report links for each campaign.', 'rifnote-search') . '</p>';
            self::render_ad_pacing_table($ad_performance['campaign_pacing'] ?? array(), false);
            echo '</div><div class="rs-admin-card"><h2>' . esc_html__('Revenue by status', 'rifnote-search') . '</h2>';
            self::render_revenue_status_table($revenue['status_rows'] ?? array());
            echo '</div></div>';

            echo '<div class="rs-admin-analytics-grid rs-admin-analytics-grid-main">';
            echo '<div class="rs-admin-card"><h2>' . esc_html__('Publisher/source report', 'rifnote-search') . '</h2><p>' . esc_html__('Which sources are bringing searchable content and earning reader attention.', 'rifnote-search') . '</p>';
            self::render_source_report_table($sources['rows'] ?? array());
            echo '</div><div class="rs-admin-card"><h2>' . esc_html__('Scheduled reports', 'rifnote-search') . '</h2>';
            self::render_scheduled_report_settings();
            echo '</div></div>';
            return;
        }

        if (self::is_section('ads-inventory')) {
            echo '<h2>' . esc_html__('Inventory and rate card', 'rifnote-search') . '</h2>';
            echo '<p>' . esc_html__('Pressure points advertisers can buy, priced in Naira and ranked by intent, visibility and matchday heat.', 'rifnote-search') . '</p>';
            self::render_ads_inventory_grid($ad_inventory, $sponsored);
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Placement', 'rifnote-search') . '</th><th>' . esc_html__('Area', 'rifnote-search') . '</th><th>' . esc_html__('Rate', 'rifnote-search') . '</th><th>' . esc_html__('Est. impressions', 'rifnote-search') . '</th><th>' . esc_html__('Why it matters', 'rifnote-search') . '</th></tr></thead><tbody>';
            foreach ($ad_inventory['placements'] as $placement) {
                echo '<tr><td><strong>' . esc_html($placement['name']) . '</strong><br /><code>' . esc_html($placement['id']) . '</code></td><td>' . esc_html($placement['area']) . '</td><td><strong>₦' . esc_html(number_format_i18n((float) $placement['price'])) . '</strong> / ' . esc_html($placement['unit']) . '</td><td>' . esc_html(number_format_i18n((int) $placement['impressions'])) . '</td><td>' . esc_html($placement['description']) . '</td></tr>';
            }
            echo '</tbody></table>';

            echo '<h2 style="margin-top:24px;">' . esc_html__('Objectives and multipliers', 'rifnote-search') . '</h2>';
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Objective', 'rifnote-search') . '</th><th>' . esc_html__('Multiplier', 'rifnote-search') . '</th></tr></thead><tbody>';
            foreach ($ad_inventory['objectives'] as $objective) {
                echo '<tr><td><strong>' . esc_html($objective['name']) . '</strong><br /><code>' . esc_html($objective['id']) . '</code></td><td>' . esc_html(number_format_i18n((float) $objective['multiplier'], 2)) . 'x</td></tr>';
            }
            echo '</tbody></table>';
            return;
        }

        if (self::is_section('ads-settings')) {
            echo '<h2>' . esc_html__('Advert settings', 'rifnote-search') . '</h2>';
            echo '<form method="post" action="options.php">';
            settings_fields('rifnote_search_settings');
            echo '<table class="form-table"><tbody>';
            echo '<tr><th scope="row"><label for="rifnote_sponsor_checkout_url">' . esc_html__('Checkout / payment URL', 'rifnote-search') . '</label></th><td><input id="rifnote_sponsor_checkout_url" class="regular-text" type="url" name="rifnote_sponsor_checkout_url" value="' . esc_attr(get_option('rifnote_sponsor_checkout_url', '')) . '" /><p class="description">' . esc_html__('Advert requests use this link after submission. Leave blank to route to Submit News for now.', 'rifnote-search') . '</p></td></tr>';
            echo '<tr><th scope="row"><label for="rifnote_ads_manager_email">' . esc_html__('Ads manager email', 'rifnote-search') . '</label></th><td><input id="rifnote_ads_manager_email" class="regular-text" type="email" name="rifnote_ads_manager_email" value="' . esc_attr(get_option('rifnote_ads_manager_email', get_option('admin_email'))) . '" /></td></tr>';
            echo '<tr><th scope="row"><label for="rifnote_ads_min_budget">' . esc_html__('Minimum campaign budget', 'rifnote-search') . '</label></th><td><input id="rifnote_ads_min_budget" type="number" min="0" step="1000" name="rifnote_ads_min_budget" value="' . esc_attr((int) get_option('rifnote_ads_min_budget', 50000)) . '" /> <span class="description">NGN</span></td></tr>';
            echo '<tr><th scope="row"><label for="rifnote_ads_frequency_cap_daily">' . esc_html__('Daily frequency cap', 'rifnote-search') . '</label></th><td><input id="rifnote_ads_frequency_cap_daily" type="number" min="1" max="50" name="rifnote_ads_frequency_cap_daily" value="' . esc_attr((int) get_option('rifnote_ads_frequency_cap_daily', 4)) . '" /><p class="description">' . esc_html__('Maximum impressions per sponsored placement for the same visitor in 24 hours.', 'rifnote-search') . '</p></td></tr>';
            echo '<tr><th scope="row"><label for="rifnote_ads_frequency_cap_session">' . esc_html__('Session frequency cap', 'rifnote-search') . '</label></th><td><input id="rifnote_ads_frequency_cap_session" type="number" min="1" max="20" name="rifnote_ads_frequency_cap_session" value="' . esc_attr((int) get_option('rifnote_ads_frequency_cap_session', 2)) . '" /><p class="description">' . esc_html__('Maximum impressions per sponsored placement inside one browsing session.', 'rifnote-search') . '</p></td></tr>';
            echo '<tr><th scope="row"><label for="rifnote_ads_report_email">' . esc_html__('Report email', 'rifnote-search') . '</label></th><td><input id="rifnote_ads_report_email" class="regular-text" type="email" name="rifnote_ads_report_email" value="' . esc_attr(get_option('rifnote_ads_report_email', get_option('admin_email'))) . '" /></td></tr>';
            echo '<tr><th scope="row"><label for="rifnote_ads_report_frequency">' . esc_html__('Scheduled report cadence', 'rifnote-search') . '</label></th><td><select id="rifnote_ads_report_frequency" name="rifnote_ads_report_frequency"><option value="off" ' . selected(get_option('rifnote_ads_report_frequency', 'off'), 'off', false) . '>' . esc_html__('Off', 'rifnote-search') . '</option><option value="daily" ' . selected(get_option('rifnote_ads_report_frequency', 'off'), 'daily', false) . '>' . esc_html__('Daily', 'rifnote-search') . '</option><option value="weekly" ' . selected(get_option('rifnote_ads_report_frequency', 'off'), 'weekly', false) . '>' . esc_html__('Weekly', 'rifnote-search') . '</option></select></td></tr>';
            echo '<tr><th scope="row"><label for="rifnote_ads_report_days">' . esc_html__('Report lookback window', 'rifnote-search') . '</label></th><td><input id="rifnote_ads_report_days" type="number" min="1" max="365" name="rifnote_ads_report_days" value="' . esc_attr((int) get_option('rifnote_ads_report_days', 30)) . '" /> <span class="description">' . esc_html__('days', 'rifnote-search') . '</span></td></tr>';
            echo '<tr><th scope="row"><label for="rifnote_ads_terms_url">' . esc_html__('Advertising terms URL', 'rifnote-search') . '</label></th><td><input id="rifnote_ads_terms_url" class="regular-text" type="url" name="rifnote_ads_terms_url" value="' . esc_attr(get_option('rifnote_ads_terms_url', '')) . '" /></td></tr>';
            echo '</tbody></table>';
            submit_button(__('Save advert settings', 'rifnote-search'));
            echo '</form>';
            self::render_ads_conversion_setup();
            return;
        }
    }

    private static function render_ads_command_center($requests, $sponsored, $ad_inventory, $audience_summary, $metrics) {
        $impressions = array_sum(array_map(function ($row) {
            return (int) ($row['impressions'] ?? 0);
        }, is_array($sponsored) ? $sponsored : array()));
        $clicks = array_sum(array_map(function ($row) {
            return (int) ($row['clicks'] ?? 0);
        }, is_array($sponsored) ? $sponsored : array()));
        $ctr = $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0;

        echo '<section class="rs-admin-hero">';
        echo '<div><span>' . esc_html__('Ad command center', 'rifnote-search') . '</span><h2>' . esc_html__('Sell smarter placements, not random banners.', 'rifnote-search') . '</h2><p>' . esc_html__('Pipeline, audience intent, placement pressure and campaign health in one view so admins can price and approve with confidence.', 'rifnote-search') . '</p></div>';
        echo '<div class="rs-admin-hero-meter"><b>' . esc_html(number_format_i18n((float) $ctr, 2)) . '%</b><small>' . esc_html__('current CTR from sponsored clicks', 'rifnote-search') . '</small></div>';
        echo '</section>';

        echo '<div class="rs-admin-kpi-grid">';
        self::render_admin_kpi(__('New briefs', 'rifnote-search'), $metrics['pending'] ?? 0, __('Waiting for review', 'rifnote-search'));
        self::render_admin_kpi(__('Ready money', 'rifnote-search'), $metrics['approved'] ?? 0, __('Approved or paid', 'rifnote-search'));
        self::render_admin_kpi(__('Live slots', 'rifnote-search'), $metrics['active_placements'] ?? 0, __('Sponsored placements', 'rifnote-search'));
        self::render_admin_kpi(__('Pipeline', 'rifnote-search'), '₦' . number_format_i18n((float) ($metrics['pipeline'] ?? 0)), __('Estimated request value', 'rifnote-search'));
        echo '</div>';

        echo '<div class="rs-admin-analytics-grid rs-admin-analytics-grid-main">';
        echo '<div class="rs-admin-card"><h2>' . esc_html__('Placement heatmap', 'rifnote-search') . '</h2><p>' . esc_html__('Live demand, available inventory and where sales should push next.', 'rifnote-search') . '</p>';
        self::render_ads_heatmap($ad_inventory, $sponsored);
        echo '</div>';
        echo '<div class="rs-admin-card"><h2>' . esc_html__('Funnel pulse', 'rifnote-search') . '</h2>';
        self::render_ads_funnel($requests);
        echo '</div></div>';
    }

    private static function render_ads_heatmap($ad_inventory, $sponsored) {
        $active_by_placement = array();
        foreach (is_array($sponsored) ? $sponsored : array() as $row) {
            if ('active' !== ($row['status'] ?? 'active')) {
                continue;
            }
            $key = sanitize_key($row['placement'] ?? '');
            $active_by_placement[$key] = ($active_by_placement[$key] ?? 0) + 1;
        }

        echo '<div class="rs-ad-heatmap">';
        foreach ($ad_inventory['placements'] as $placement) {
            $active = (int) ($active_by_placement[$placement['id']] ?? 0);
            $heat = min(100, ($active * 28) + ((int) $placement['impressions'] / 800));
            $class = $active > 0 ? ' live' : '';
            echo '<article class="rs-ad-heat' . esc_attr($class) . '"><div><strong>' . esc_html($placement['name']) . '</strong><span>' . esc_html($placement['area']) . ' · ₦' . esc_html(number_format_i18n((float) $placement['price'])) . '</span></div><b>' . esc_html($active) . '</b><i><em style="width:' . esc_attr(max(6, min(100, $heat))) . '%"></em></i></article>';
        }
        echo '</div>';
    }

    private static function render_ads_funnel($requests) {
        $statuses = array(
            'new' => __('New', 'rifnote-search'),
            'reviewing' => __('Reviewing', 'rifnote-search'),
            'approved' => __('Approved', 'rifnote-search'),
            'paid' => __('Paid', 'rifnote-search'),
            'active' => __('Active', 'rifnote-search'),
        );
        $counts = array_fill_keys(array_keys($statuses), 0);
        foreach (is_array($requests) ? $requests : array() as $request) {
            $status = sanitize_key($request['status'] ?? 'new');
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        $max = max(1, max($counts));
        echo '<div class="rs-ad-funnel">';
        foreach ($statuses as $status => $label) {
            $count = (int) $counts[$status];
            echo '<div><span>' . esc_html($label) . '</span><strong>' . esc_html(number_format_i18n($count)) . '</strong><i><em style="width:' . esc_attr(max(6, round(($count / $max) * 100))) . '%"></em></i></div>';
        }
        echo '</div>';
    }

    private static function render_ads_recommendations($audience_summary, $ad_inventory) {
        $top_interest = $audience_summary['top_interests'][0]['label'] ?? __('Football', 'rifnote-search');
        $top_device = strtolower($audience_summary['device_mix'][0]['label'] ?? 'mobile');
        $top_region = $audience_summary['region_rows'][0]['label'] ?? ($audience_summary['country_rows'][0]['label'] ?? __('Nigeria', 'rifnote-search'));
        $returning = (int) ($audience_summary['returning'] ?? 0);
        $visitors = max(1, (int) ($audience_summary['visitors'] ?? 0));
        $returning_rate = round(($returning / $visitors) * 100);

        $recommendations = array(
            array(
                'title' => sprintf(__('Pitch %s brands first', 'rifnote-search'), $top_interest),
                'body' => sprintf(__('The audience is showing %s intent. Package Search top intent + Trending topics for a cleaner sell.', 'rifnote-search'), $top_interest),
            ),
            array(
                'title' => sprintf(__('Lead with %s targeting', 'rifnote-search'), $top_region),
                'body' => sprintf(__('Use %s in campaign geo targeting when advertisers want sharper local reach.', 'rifnote-search'), $top_region),
            ),
            array(
                'title' => sprintf(__('Protect mobile inventory', 'rifnote-search'), $top_device),
                'body' => sprintf(__('Top device is %s. Keep the mobile bottom-bar premium and cap frequency so it feels useful, not noisy.', 'rifnote-search'), $top_device),
            ),
            array(
                'title' => __('Retarget the loyal crowd', 'rifnote-search'),
                'body' => sprintf(__('%d%% of tracked visitors are returning. That is strong enough for weekly sponsor packages and reminders.', 'rifnote-search'), $returning_rate),
            ),
        );

        echo '<h2>' . esc_html__('Smart moves', 'rifnote-search') . '</h2><p>' . esc_html__('Quick recommendations based on the audience data coming in right now.', 'rifnote-search') . '</p>';
        echo '<div class="rs-admin-recos">';
        foreach ($recommendations as $item) {
            echo '<article><strong>' . esc_html($item['title']) . '</strong><span>' . esc_html($item['body']) . '</span></article>';
        }
        echo '</div>';
    }

    private static function render_ads_inventory_grid($ad_inventory, $sponsored) {
        $active_by_placement = array();
        foreach (is_array($sponsored) ? $sponsored : array() as $row) {
            $key = sanitize_key($row['placement'] ?? '');
            $active_by_placement[$key] = ($active_by_placement[$key] ?? 0) + 1;
        }

        echo '<div class="rs-ad-inventory-grid">';
        foreach ($ad_inventory['placements'] as $placement) {
            $active = (int) ($active_by_placement[$placement['id']] ?? 0);
            echo '<article><span>' . esc_html($placement['area']) . '</span><h3>' . esc_html($placement['name']) . '</h3><p>' . esc_html($placement['description']) . '</p><div><strong>₦' . esc_html(number_format_i18n((float) $placement['price'])) . '</strong><small>' . esc_html(number_format_i18n((int) $placement['impressions'])) . ' ' . esc_html__('est. views', 'rifnote-search') . '</small><b>' . esc_html($active) . ' ' . esc_html__('live', 'rifnote-search') . '</b></div></article>';
        }
        echo '</div>';
    }

    private static function render_creative_studio($requests, $ad_inventory) {
        $active_id = isset($_GET['campaign_id']) ? absint($_GET['campaign_id']) : 0;
        $active = null;
        foreach ($requests as $request) {
            if (!$active_id || (int) $request['id'] === $active_id) {
                $active = $request;
                break;
            }
        }

        echo '<div class="rs-admin-analytics-grid rs-admin-analytics-grid-main">';
        echo '<div class="rs-admin-card"><h2>' . esc_html__('Campaign shelf', 'rifnote-search') . '</h2><p>' . esc_html__('Pick a campaign, polish the creative, then approve or send it back for changes.', 'rifnote-search') . '</p><div class="rs-creative-campaign-list">';
        foreach (array_slice($requests, 0, 40) as $request) {
            $payload = !empty($request['campaign_payload']) ? json_decode($request['campaign_payload'], true) : array();
            $creative = is_array($payload) ? ($payload['creative'] ?? array()) : array();
            $creative_status = sanitize_key($creative['status'] ?? 'draft');
            $url = add_query_arg(array('page' => 'rifnote-search-ads-creative', 'campaign_id' => (int) $request['id']), admin_url('admin.php'));
            echo '<a class="' . esc_attr((int) $request['id'] === (int) ($active['id'] ?? 0) ? 'active' : '') . '" href="' . esc_url($url) . '"><strong>' . esc_html($request['campaign_title']) . '</strong><span>' . esc_html($request['sponsor_name']) . ' · ' . esc_html($creative_status) . '</span></a>';
        }
        echo '</div></div>';

        if (!$active) {
            echo '<div class="rs-admin-card"><h2>' . esc_html__('No campaign selected', 'rifnote-search') . '</h2><p>' . esc_html__('Campaign briefs will appear here once advertisers submit them.', 'rifnote-search') . '</p></div></div>';
            return;
        }

        $payload = !empty($active['campaign_payload']) ? json_decode($active['campaign_payload'], true) : array();
        if (!is_array($payload)) {
            $payload = array();
        }
        $creative = is_array($payload['creative'] ?? null) ? $payload['creative'] : array();
        $variants = is_array($creative['variants'] ?? null) ? $creative['variants'] : array();
        if (!$variants) {
            $variants[] = array('headline' => $creative['headline'] ?? '', 'text' => $creative['text'] ?? '', 'cta' => $creative['cta'] ?? 'Learn more');
        }
        $assets = is_array($creative['assets'] ?? null) ? $creative['assets'] : array_values(array_filter(array($creative['image_url'] ?? '')));
        $history = is_array($payload['creative_history'] ?? null) ? array_reverse($payload['creative_history']) : array();

        echo '<div class="rs-admin-card"><h2>' . esc_html__('Creative editor', 'rifnote-search') . '</h2><p><strong>' . esc_html($active['campaign_title']) . '</strong> · ' . esc_html($active['sponsor_name']) . '</p>';
        echo '<form method="post">';
        wp_nonce_field('rifnote_ads_action', 'rifnote_ads_nonce');
        echo '<input type="hidden" name="rifnote_ads_action" value="save_creative" /><input type="hidden" name="rifnote_ad_request_id" value="' . esc_attr((int) $active['id']) . '" />';
        echo '<h3>' . esc_html__('Copy variants', 'rifnote-search') . '</h3><div class="rs-creative-variant-grid">';
        for ($i = 0; $i < 3; $i++) {
            $variant = $variants[$i] ?? array();
            echo '<fieldset><legend>' . esc_html(sprintf(__('Variant %d', 'rifnote-search'), $i + 1)) . '</legend>';
            echo '<input class="widefat" name="rifnote_creative_headlines[]" placeholder="' . esc_attr__('Headline', 'rifnote-search') . '" value="' . esc_attr($variant['headline'] ?? '') . '" />';
            echo '<textarea class="widefat" rows="3" name="rifnote_creative_texts[]" placeholder="' . esc_attr__('Body copy', 'rifnote-search') . '">' . esc_textarea($variant['text'] ?? '') . '</textarea>';
            echo '<input class="widefat" name="rifnote_creative_ctas[]" placeholder="' . esc_attr__('CTA e.g. Start now', 'rifnote-search') . '" value="' . esc_attr($variant['cta'] ?? '') . '" />';
            echo '</fieldset>';
        }
        echo '</div><h3>' . esc_html__('Assets', 'rifnote-search') . '</h3><p><span class="description">' . esc_html__('Choose creative images or videos from the Media Library. First asset becomes the default image.', 'rifnote-search') . '</span></p><div class="rs-creative-asset-grid">';
        for ($i = 0; $i < 4; $i++) {
            $asset_id = 'rifnote_creative_asset_' . $i;
            echo '<div class="rs-media-field"><input id="' . esc_attr($asset_id) . '" class="widefat rs-media-url" type="url" name="rifnote_creative_assets[]" placeholder="https://..." value="' . esc_attr($assets[$i] ?? '') . '" /><p><button type="button" class="button button-small rs-media-picker" data-target="#' . esc_attr($asset_id) . '" data-title="' . esc_attr__('Choose creative asset', 'rifnote-search') . '" data-button="' . esc_attr__('Use asset', 'rifnote-search') . '">' . esc_html__('Choose media', 'rifnote-search') . '</button> <button type="button" class="button button-small rs-media-clear" data-target="#' . esc_attr($asset_id) . '">' . esc_html__('Clear', 'rifnote-search') . '</button></p></div>';
        }
        echo '</div><h3>' . esc_html__('Approval', 'rifnote-search') . '</h3><p><select name="rifnote_creative_status">';
        foreach (array('draft', 'review', 'approved', 'needs_changes', 'rejected') as $status) {
            echo '<option value="' . esc_attr($status) . '" ' . selected($creative['status'] ?? 'draft', $status, false) . '>' . esc_html(ucwords(str_replace('_', ' ', $status))) . '</option>';
        }
        echo '</select></p><textarea class="widefat" rows="3" name="rifnote_creative_note" placeholder="' . esc_attr__('Review note for this creative version', 'rifnote-search') . '">' . esc_textarea($creative['review_note'] ?? '') . '</textarea>';
        submit_button(__('Save creative version', 'rifnote-search'), 'primary');
        echo '</form></div></div>';

        self::render_creative_preview($active, $creative, $ad_inventory);
        self::render_creative_history($history);
    }

    private static function render_creative_preview($request, $creative, $ad_inventory) {
        $headline = $creative['headline'] ?? $request['campaign_title'];
        $text = $creative['text'] ?? __('Your message appears here as it will feel inside Rifnote placements.', 'rifnote-search');
        $cta = $creative['cta'] ?? __('Learn more', 'rifnote-search');
        $image = $creative['image_url'] ?? '';

        echo '<div class="rs-admin-card" style="margin-top:18px;"><h2>' . esc_html__('Placement preview studio', 'rifnote-search') . '</h2><div class="rs-creative-preview-grid">';
        foreach (array_slice($ad_inventory['placements'] ?? array(), 0, 6) as $placement) {
            echo '<article><span>' . esc_html($placement['area']) . '</span>';
            if ($image) {
                echo '<img src="' . esc_url($image) . '" alt="" />';
            }
            echo '<strong>' . esc_html($headline) . '</strong><p>' . esc_html($text) . '</p><button type="button">' . esc_html($cta) . '</button><small>' . esc_html($placement['name']) . '</small></article>';
        }
        echo '</div></div>';
    }

    private static function render_creative_history($history) {
        echo '<div class="rs-admin-card" style="margin-top:18px;"><h2>' . esc_html__('Version history', 'rifnote-search') . '</h2>';
        if (!$history) {
            echo '<p class="description">' . esc_html__('No saved creative versions yet.', 'rifnote-search') . '</p></div>';
            return;
        }
        echo '<div class="rs-ad-risk-list">';
        foreach (array_slice($history, 0, 8) as $entry) {
            echo '<article><strong>' . esc_html(ucwords(str_replace('_', ' ', $entry['status'] ?? 'draft'))) . '</strong><span>' . esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($entry['at'] ?? 'now')), 'M j, Y g:i A')) . ' · ' . esc_html($entry['note'] ?? __('No note', 'rifnote-search')) . '</span></article>';
        }
        echo '</div></div>';
    }

    private static function render_ads_requests_table($requests, $compact = false) {
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Campaign', 'rifnote-search') . '</th><th>' . esc_html__('Advertiser', 'rifnote-search') . '</th><th>' . esc_html__('Targeting', 'rifnote-search') . '</th><th>' . esc_html__('Value', 'rifnote-search') . '</th><th>' . esc_html__('Actions', 'rifnote-search') . '</th></tr></thead><tbody>';
        if (!$requests) {
            echo '<tr><td colspan="5">' . esc_html__('No advert requests yet.', 'rifnote-search') . '</td></tr>';
        }

        foreach ($requests as $request) {
            $payload = !empty($request['campaign_payload']) ? json_decode($request['campaign_payload'], true) : array();
            $audience = is_array($payload) ? ($payload['audience'] ?? array()) : array();
            $creative = is_array($payload) ? ($payload['creative'] ?? array()) : array();
            $timeline = is_array($payload) && is_array($payload['status_timeline'] ?? null) ? array_reverse($payload['status_timeline']) : array();
            $estimate = is_array($payload) ? ($payload['estimate'] ?? array()) : array();
            echo '<tr>';
            echo '<td><strong>' . esc_html($request['campaign_title']) . '</strong><br /><small>' . esc_html($request['status']) . ' · ' . esc_html($request['objective'] ?: ($payload['objective'] ?? 'awareness')) . '</small>';
            if (!$compact && !empty($creative['headline'])) {
                echo '<br /><small><strong>' . esc_html__('Creative:', 'rifnote-search') . '</strong> ' . esc_html($creative['headline']) . ' · ' . esc_html($creative['status'] ?? 'draft') . '</small>';
            }
            if (!$compact && !empty($payload['status_note'])) {
                echo '<br /><small><strong>' . esc_html__('Latest note:', 'rifnote-search') . '</strong> ' . esc_html(wp_trim_words($payload['status_note'], 14)) . '</small>';
            }
            if (!$compact && !empty($timeline[0]['to'])) {
                echo '<br /><small><strong>' . esc_html__('Last move:', 'rifnote-search') . '</strong> ' . esc_html($timeline[0]['from'] ?? 'new') . ' → ' . esc_html($timeline[0]['to']) . '</small>';
            }
            echo '</td>';
            echo '<td>' . esc_html($request['sponsor_name']) . '<br /><small>' . esc_html($request['contact_email']) . '</small></td>';
            echo '<td>' . esc_html($request['placement_summary'] ?: $request['category']) . '<br /><small>' . esc_html($audience['locations'] ?? $request['query_match']) . '</small></td>';
            echo '<td><strong>₦' . esc_html(number_format_i18n((float) ($request['estimated_price'] ?? 0))) . '</strong><br /><small>' . esc_html(number_format_i18n((int) ($estimate['estimated_impressions'] ?? 0))) . ' ' . esc_html__('est. impressions', 'rifnote-search') . '</small>';
            if (!empty($request['payment_reference'])) {
                echo '<br /><small><strong>' . esc_html__('Payment:', 'rifnote-search') . '</strong> ' . esc_html($request['payment_reference']);
                if ((float) ($request['payment_amount'] ?? 0) > 0) {
                    echo ' · ₦' . esc_html(number_format_i18n((float) $request['payment_amount']));
                }
                echo '</small>';
                if (!$compact && !empty($request['payment_note'])) {
                    echo '<br /><small>' . esc_html(wp_trim_words($request['payment_note'], 14)) . '</small>';
                }
            }
            echo '</td>';
            echo '<td>';
            self::render_ads_request_action($request['id'], 'reviewing', __('Reviewing', 'rifnote-search'));
            self::render_ads_request_action($request['id'], 'needs_changes', __('Needs changes', 'rifnote-search'), 'secondary', true);
            self::render_ads_request_action($request['id'], 'payment_review', __('Payment review', 'rifnote-search'));
            self::render_ads_request_action($request['id'], 'approved', __('Approve', 'rifnote-search'));
            self::render_ads_request_action($request['id'], 'paid', __('Mark paid', 'rifnote-search'));
            self::render_ads_activate_action($request['id']);
            if ('paused' === ($request['status'] ?? '')) {
                self::render_ads_request_action($request['id'], 'active', __('Resume', 'rifnote-search'), 'primary', true);
            } else {
                self::render_ads_request_action($request['id'], 'paused', __('Pause', 'rifnote-search'), 'secondary', true);
            }
            self::render_ads_request_action($request['id'], 'completed', __('Complete', 'rifnote-search'), 'secondary', true);
            self::render_ads_request_action($request['id'], 'rejected', __('Reject', 'rifnote-search'), 'delete', true);
            if (!empty($request['checkout_url'])) {
                echo ' <a class="button button-small" href="' . esc_url($request['checkout_url']) . '" target="_blank" rel="noreferrer">' . esc_html__('Checkout', 'rifnote-search') . '</a>';
            }
            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }

    private static function render_ads_request_action($request_id, $status, $label, $class = 'secondary', $with_note = false) {
        echo '<form method="post" style="display:inline-block;margin:0 4px 4px 0;">';
        wp_nonce_field('rifnote_ads_action', 'rifnote_ads_nonce');
        echo '<input type="hidden" name="rifnote_ads_action" value="update_request_status" />';
        echo '<input type="hidden" name="rifnote_ad_request_id" value="' . esc_attr((int) $request_id) . '" />';
        echo '<input type="hidden" name="rifnote_ad_status" value="' . esc_attr($status) . '" />';
        if ($with_note) {
            echo '<input type="text" name="rifnote_ad_status_note" placeholder="' . esc_attr__('Short note', 'rifnote-search') . '" style="max-width:130px;margin-right:4px;" />';
        }
        submit_button($label, $class . ' small', 'submit', false);
        echo '</form>';
    }

    private static function render_ads_activate_action($request_id) {
        echo '<form method="post" style="display:inline-block;margin:0 4px 4px 0;">';
        wp_nonce_field('rifnote_ads_action', 'rifnote_ads_nonce');
        echo '<input type="hidden" name="rifnote_ads_action" value="activate_request" />';
        echo '<input type="hidden" name="rifnote_ad_request_id" value="' . esc_attr((int) $request_id) . '" />';
        submit_button(__('Activate', 'rifnote-search'), 'primary small', 'submit', false);
        echo '</form>';
    }

    private static function render_ads_placement_form($ad_inventory) {
        echo '<h2>' . esc_html__('Create sponsored placement', 'rifnote-search') . '</h2>';
        echo '<form method="post" class="card" style="max-width:1120px;">';
        wp_nonce_field('rifnote_ads_action', 'rifnote_ads_nonce');
        echo '<input type="hidden" name="rifnote_ads_action" value="add_sponsored" />';
        echo '<p><input class="regular-text" name="rifnote_sponsored_title" placeholder="' . esc_attr__('Sponsored headline', 'rifnote-search') . '" /> <input class="regular-text" name="rifnote_sponsor_name" placeholder="' . esc_attr__('Sponsor name', 'rifnote-search') . '" /></p>';
        echo '<p><input class="large-text" name="rifnote_sponsored_url" type="url" placeholder="https://sponsor.example/landing-page" /></p>';
        echo '<p><select name="rifnote_sponsored_placement">';
        foreach ($ad_inventory['placements'] as $placement) {
            echo '<option value="' . esc_attr($placement['id']) . '">' . esc_html($placement['name'] . ' - ₦' . number_format_i18n((float) $placement['price']) . '/' . $placement['unit']) . '</option>';
        }
        echo '</select> <input name="rifnote_sponsored_category" placeholder="' . esc_attr__('Category', 'rifnote-search') . '" /> <input name="rifnote_sponsored_query" placeholder="' . esc_attr__('Query / intent match', 'rifnote-search') . '" /></p>';
        echo '<p><label>' . esc_html__('Priority', 'rifnote-search') . ' <input type="number" min="1" max="100" name="rifnote_sponsored_priority" value="50" style="width:90px;" /></label> <select name="rifnote_sponsored_device"><option value="">' . esc_html__('All devices', 'rifnote-search') . '</option><option value="mobile">' . esc_html__('Mobile first', 'rifnote-search') . '</option><option value="desktop">' . esc_html__('Desktop first', 'rifnote-search') . '</option><option value="tablet">' . esc_html__('Tablet first', 'rifnote-search') . '</option></select></p>';
        echo '<p><input class="regular-text" name="rifnote_sponsored_locations" placeholder="' . esc_attr__('Countries / states e.g. Nigeria, Lagos, Ghana', 'rifnote-search') . '" /> <input class="regular-text" name="rifnote_sponsored_interests" placeholder="' . esc_attr__('Interests e.g. football, transfers, Afrobeats', 'rifnote-search') . '" /></p>';
        echo '<p><label>' . esc_html__('Starts', 'rifnote-search') . ' <input type="datetime-local" name="rifnote_sponsored_starts_at" /></label> <label>' . esc_html__('Ends', 'rifnote-search') . ' <input type="datetime-local" name="rifnote_sponsored_ends_at" /></label> <select name="rifnote_sponsored_status"><option value="active">' . esc_html__('Active', 'rifnote-search') . '</option><option value="paused">' . esc_html__('Paused', 'rifnote-search') . '</option></select></p>';
        echo '<p class="description">' . esc_html__('Smart serving uses priority, intent, category, location, interests, device and frequency caps before choosing which ad appears.', 'rifnote-search') . '</p>';
        submit_button(__('Save sponsored placement', 'rifnote-search'), 'secondary');
        echo '</form>';
    }

    private static function render_ads_placements_table($sponsored) {
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Placement', 'rifnote-search') . '</th><th>' . esc_html__('Sponsor', 'rifnote-search') . '</th><th>' . esc_html__('Targeting', 'rifnote-search') . '</th><th>' . esc_html__('Performance', 'rifnote-search') . '</th><th>' . esc_html__('Dates', 'rifnote-search') . '</th></tr></thead><tbody>';
        if (!$sponsored) {
            echo '<tr><td colspan="5">' . esc_html__('No sponsored placements yet.', 'rifnote-search') . '</td></tr>';
        }
        foreach ($sponsored as $placement) {
            $targeting = !empty($placement['targeting_payload']) ? json_decode($placement['targeting_payload'], true) : array();
            $audience = is_array($targeting) ? ($targeting['audience'] ?? array()) : array();
            $targeting_lines = array_filter(array(
                $placement['category'] ? __('Category:', 'rifnote-search') . ' ' . $placement['category'] : '',
                $placement['query_match'] ? __('Intent:', 'rifnote-search') . ' ' . $placement['query_match'] : '',
                !empty($audience['locations']) ? __('Geo:', 'rifnote-search') . ' ' . $audience['locations'] : '',
                !empty($audience['interests']) ? __('Interests:', 'rifnote-search') . ' ' . $audience['interests'] : '',
                !empty($audience['device_type']) ? __('Device:', 'rifnote-search') . ' ' . $audience['device_type'] : '',
            ));
            echo '<tr><td><strong>' . esc_html($placement['title']) . '</strong><br /><small>' . esc_html($placement['placement']) . ' · ' . esc_html($placement['status']) . '</small></td>';
            echo '<td>' . esc_html($placement['sponsor_name']) . '<br /><a href="' . esc_url($placement['target_url']) . '" target="_blank" rel="noreferrer">' . esc_html__('Open landing page', 'rifnote-search') . '</a></td>';
            echo '<td><strong>' . esc_html__('Priority', 'rifnote-search') . ' ' . esc_html((int) ($placement['priority'] ?? 50)) . '</strong><br /><small>' . esc_html($targeting_lines ? implode(' · ', $targeting_lines) : __('Broad audience', 'rifnote-search')) . '</small></td>';
            echo '<td>' . esc_html(number_format_i18n((int) $placement['impressions'])) . ' ' . esc_html__('views', 'rifnote-search') . '<br />' . esc_html(number_format_i18n((int) $placement['clicks'])) . ' ' . esc_html__('clicks', 'rifnote-search') . '</td>';
            echo '<td><small>' . esc_html($placement['starts_at'] ? get_date_from_gmt($placement['starts_at'], 'M j, Y H:i') : __('No start', 'rifnote-search')) . '<br />' . esc_html($placement['ends_at'] ? get_date_from_gmt($placement['ends_at'], 'M j, Y H:i') : __('No end', 'rifnote-search')) . '</small></td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_ads_export_buttons() {
        $days = self::sanitize_report_days(get_option('rifnote_ads_report_days', 30));
        $placement_url = wp_nonce_url(add_query_arg(array('rifnote_ads_export' => 'placements', 'days' => $days)), 'rifnote_ads_export_placements');
        $sponsor_url = wp_nonce_url(add_query_arg(array('rifnote_ads_export' => 'sponsors', 'days' => $days)), 'rifnote_ads_export_sponsors');
        $campaign_url = wp_nonce_url(add_query_arg(array('rifnote_ads_export' => 'campaigns', 'days' => $days)), 'rifnote_ads_export_campaigns');
        $source_url = wp_nonce_url(add_query_arg(array('rifnote_ads_export' => 'sources', 'days' => $days)), 'rifnote_ads_export_sources');
        $revenue_url = wp_nonce_url(add_query_arg(array('rifnote_ads_export' => 'revenue', 'days' => $days)), 'rifnote_ads_export_revenue');

        echo '<p class="rs-admin-actions"><a class="button button-secondary" href="' . esc_url($placement_url) . '">' . esc_html__('Placement CSV', 'rifnote-search') . '</a> <a class="button button-secondary" href="' . esc_url($sponsor_url) . '">' . esc_html__('Advertiser CSV', 'rifnote-search') . '</a> <a class="button button-secondary" href="' . esc_url($campaign_url) . '">' . esc_html__('Campaign CSV', 'rifnote-search') . '</a> <a class="button button-secondary" href="' . esc_url($source_url) . '">' . esc_html__('Source CSV', 'rifnote-search') . '</a> <a class="button button-primary" href="' . esc_url($revenue_url) . '">' . esc_html__('Revenue CSV', 'rifnote-search') . '</a></p>';
    }

    private static function export_campaign_pdf_report($campaign_id) {
        $performance = Rifnote_Search_Analytics::ad_performance_summary(self::sanitize_report_days(get_option('rifnote_ads_report_days', 30)));
        $campaign = null;
        foreach ($performance['campaign_pacing'] ?? array() as $row) {
            if ((int) ($row['id'] ?? 0) === (int) $campaign_id) {
                $campaign = $row;
                break;
            }
        }

        if (!$campaign) {
            wp_die(esc_html__('Campaign report not found yet. Activate the campaign or refresh reporting data first.', 'rifnote-search'));
        }

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>" />
            <title><?php echo esc_html(sprintf(__('Rifnote campaign report - %s', 'rifnote-search'), $campaign['label'])); ?></title>
            <style>
                body{margin:0;padding:40px;background:#f6f7fb;color:#101828;font-family:Inter,Arial,sans-serif}
                .sheet{max-width:900px;margin:0 auto;background:#fff;border:1px solid #dde3ec;border-radius:24px;padding:42px;box-shadow:0 24px 80px rgba(16,24,40,.12)}
                .top{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;border-bottom:1px solid #e6ebf2;padding-bottom:24px;margin-bottom:28px}
                h1{font-size:44px;line-height:.96;margin:0 0 12px;letter-spacing:-.04em}
                p{color:#667085;font-weight:650;line-height:1.5}
                .badge{display:inline-flex;padding:8px 12px;border-radius:999px;background:#f3f5f8;color:#ed1c24;font-weight:900;font-size:12px;text-transform:uppercase}
                .grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:28px 0}
                .card{padding:18px;border:1px solid #e6ebf2;border-radius:18px;background:#f8fafc}
                .card span{display:block;color:#667085;font-size:12px;font-weight:900;text-transform:uppercase}
                .card strong{display:block;margin-top:8px;font-size:28px;letter-spacing:-.03em}
                table{width:100%;border-collapse:collapse;margin-top:22px}th,td{text-align:left;padding:14px;border-bottom:1px solid #e6ebf2}th{color:#667085;text-transform:uppercase;font-size:12px}button{margin-top:28px;border:0;border-radius:999px;background:#ed1c24;color:#fff;padding:14px 22px;font-weight:900}
                @media print{body{background:#fff;padding:0}.sheet{box-shadow:none;border:0;border-radius:0}button{display:none}}
            </style>
        </head>
        <body>
            <main class="sheet">
                <div class="top">
                    <div>
                        <span class="badge"><?php esc_html_e('Campaign report', 'rifnote-search'); ?></span>
                        <h1><?php echo esc_html($campaign['label']); ?></h1>
                        <p><?php echo esc_html($campaign['sponsor']); ?> · <?php echo esc_html($campaign['status']); ?> · <?php echo esc_html($campaign['placements']); ?></p>
                    </div>
                    <p><?php echo esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s'), 'M j, Y g:i A')); ?></p>
                </div>
                <section class="grid">
                    <div class="card"><span><?php esc_html_e('Progress', 'rifnote-search'); ?></span><strong><?php echo esc_html((int) $campaign['progress']); ?>%</strong></div>
                    <div class="card"><span><?php esc_html_e('Delivery', 'rifnote-search'); ?></span><strong><?php echo esc_html((int) $campaign['delivery']); ?>%</strong></div>
                    <div class="card"><span><?php esc_html_e('Clicks', 'rifnote-search'); ?></span><strong><?php echo esc_html(number_format_i18n((int) $campaign['clicks'])); ?></strong></div>
                    <div class="card"><span><?php esc_html_e('Value', 'rifnote-search'); ?></span><strong>₦<?php echo esc_html(number_format_i18n((float) $campaign['budget'])); ?></strong></div>
                </section>
                <table>
                    <tbody>
                        <tr><th><?php esc_html_e('Impressions', 'rifnote-search'); ?></th><td><?php echo esc_html(number_format_i18n((int) $campaign['impressions'])); ?></td></tr>
                        <tr><th><?php esc_html_e('Conversions', 'rifnote-search'); ?></th><td><?php echo esc_html(number_format_i18n((int) $campaign['conversions'])); ?></td></tr>
                        <tr><th><?php esc_html_e('Conversion value', 'rifnote-search'); ?></th><td>₦<?php echo esc_html(number_format_i18n((float) $campaign['conversion_value'])); ?></td></tr>
                        <tr><th><?php esc_html_e('Signal', 'rifnote-search'); ?></th><td><?php echo esc_html($campaign['signal']); ?></td></tr>
                    </tbody>
                </table>
                <button onclick="window.print()"><?php esc_html_e('Print / save as PDF', 'rifnote-search'); ?></button>
            </main>
        </body>
        </html>
        <?php
    }

    private static function render_ads_conversion_setup() {
        $endpoint = rest_url('rifnote/v1/sponsored/conversion');
        $pixel = add_query_arg(array(
            'id' => 'PLACEMENT_ID',
            'event' => 'lead',
            'value' => '0',
            'currency' => 'NGN',
        ), $endpoint);

        echo '<div class="rs-admin-card" style="margin-top:18px;max-width:1120px;"><h2>' . esc_html__('Conversion tracking', 'rifnote-search') . '</h2>';
        echo '<p>' . esc_html__('Give advertisers this endpoint when they want to report leads, signups, sales or other outcomes back to Rifnote. Reports stay database-first inside Analytics.', 'rifnote-search') . '</p>';
        echo '<p><strong>' . esc_html__('POST endpoint:', 'rifnote-search') . '</strong> <code>' . esc_html($endpoint) . '</code></p>';
        echo '<pre><code>' . esc_html(wp_json_encode(array(
            'placement_id' => 123,
            'event' => 'lead',
            'value' => 25000,
            'currency' => 'NGN',
            'sponsor_name' => 'Brand name',
            'country' => 'Nigeria',
            'region' => 'Lagos',
        ), JSON_PRETTY_PRINT)) . '</code></pre>';
        echo '<p><strong>' . esc_html__('No-code test pixel:', 'rifnote-search') . '</strong></p>';
        echo '<pre><code>&lt;img src="' . esc_url($pixel) . '" width="1" height="1" alt="" /&gt;</code></pre>';
        echo '</div>';
    }

    private static function render_ad_performance_table($rows, $compact = false) {
        if (empty($rows)) {
            echo '<p class="description">' . esc_html__('No tracked ad performance yet. Once sponsored placements render, this fills itself from analytics logs and placement counters.', 'rifnote-search') . '</p>';
            return;
        }

        echo '<table class="widefat striped rs-ad-report-table"><thead><tr><th>' . esc_html__('Campaign / slot', 'rifnote-search') . '</th><th>' . esc_html__('Placement', 'rifnote-search') . '</th><th>' . esc_html__('Views', 'rifnote-search') . '</th><th>' . esc_html__('Clicks', 'rifnote-search') . '</th><th>' . esc_html__('CTR', 'rifnote-search') . '</th><th>' . esc_html__('Conv.', 'rifnote-search') . '</th>';
        if (!$compact) {
            echo '<th>' . esc_html__('Value', 'rifnote-search') . '</th><th>' . esc_html__('Signal', 'rifnote-search') . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach (array_slice($rows, 0, $compact ? 6 : 40) as $row) {
            $ctr = (float) ($row['ctr'] ?? 0);
            $tone = $ctr >= 2 ? 'hot' : ($ctr >= .5 ? 'warm' : 'cold');
            echo '<tr>';
            echo '<td><strong>' . esc_html($row['label'] ?? __('Untitled placement', 'rifnote-search')) . '</strong><br /><small>' . esc_html($row['sponsor'] ?? __('Unknown sponsor', 'rifnote-search')) . ' · ' . esc_html(($row['status'] ?? '') ?: __('tracked', 'rifnote-search')) . '</small></td>';
            echo '<td><code>' . esc_html($row['placement'] ?? 'unknown') . '</code><br /><small>' . esc_html__('Priority', 'rifnote-search') . ' ' . esc_html((int) ($row['priority'] ?? 0)) . '</small></td>';
            echo '<td>' . esc_html(number_format_i18n((int) ($row['impressions'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) ($row['clicks'] ?? 0))) . '</td>';
            echo '<td><span class="rs-ad-ctr ' . esc_attr($tone) . '">' . esc_html(number_format_i18n($ctr, 2)) . '%</span></td>';
            echo '<td>' . esc_html(number_format_i18n((int) ($row['conversions'] ?? 0))) . '<br /><small>' . esc_html(number_format_i18n((float) ($row['conversion_rate'] ?? 0), 2)) . '%</small></td>';
            if (!$compact) {
                echo '<td>₦' . esc_html(number_format_i18n((float) ($row['conversion_value'] ?? 0))) . '</td>';
                echo '<td>' . esc_html(self::ad_performance_signal($row)) . '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private static function ad_performance_signal($row) {
        $impressions = (int) ($row['impressions'] ?? 0);
        $ctr = (float) ($row['ctr'] ?? 0);

        if ($impressions < 20) {
            return __('Still warming up', 'rifnote-search');
        }

        if ($ctr >= 2) {
            return __('Strong fit. Keep selling this lane.', 'rifnote-search');
        }

        if ($ctr >= .5) {
            return __('Healthy. Test a sharper hook.', 'rifnote-search');
        }

        return __('Needs creative or targeting work.', 'rifnote-search');
    }

    private static function render_ad_weak_slots($rows) {
        if (empty($rows)) {
            echo '<p class="description">' . esc_html__('No weak slots flagged yet. Good sign, or not enough impressions yet.', 'rifnote-search') . '</p>';
            return;
        }

        echo '<div class="rs-admin-recos">';
        foreach (array_slice($rows, 0, 6) as $row) {
            echo '<article><strong>' . esc_html($row['label'] ?? __('Sponsored placement', 'rifnote-search')) . '</strong><span>' . esc_html(sprintf(__('CTR is %1$s%% after %2$s views. Refresh the headline, narrow targeting, or move budget to a hotter pressure point.', 'rifnote-search'), number_format_i18n((float) ($row['ctr'] ?? 0), 2), number_format_i18n((int) ($row['impressions'] ?? 0)))) . '</span></article>';
        }
        echo '</div>';
    }

    private static function render_ad_pacing_table($rows, $compact = false) {
        if (empty($rows)) {
            echo '<p class="description">' . esc_html__('No campaigns with pacing data yet. Activated campaign requests will appear here once they have linked sponsored placements.', 'rifnote-search') . '</p>';
            return;
        }

        echo '<table class="widefat striped rs-ad-report-table"><thead><tr><th>' . esc_html__('Campaign', 'rifnote-search') . '</th><th>' . esc_html__('Progress', 'rifnote-search') . '</th><th>' . esc_html__('Delivery', 'rifnote-search') . '</th><th>' . esc_html__('Outcomes', 'rifnote-search') . '</th>';
        if (!$compact) {
            echo '<th>' . esc_html__('Budget', 'rifnote-search') . '</th><th>' . esc_html__('Signal', 'rifnote-search') . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach (array_slice($rows, 0, $compact ? 6 : 30) as $row) {
            $delta = (int) ($row['pace_delta'] ?? 0);
            $tone = $delta > 35 ? 'hot' : ($delta < -25 ? 'cold' : 'warm');
            $report_url = wp_nonce_url(add_query_arg(array(
                'rifnote_ads_export' => 'campaign-pdf',
                'campaign_id' => (int) ($row['id'] ?? 0),
            )), 'rifnote_ads_export_campaign-pdf');
            echo '<tr>';
            echo '<td><strong>' . esc_html($row['label'] ?: __('Untitled campaign', 'rifnote-search')) . '</strong><br /><small>' . esc_html($row['sponsor']) . ' · ' . esc_html($row['status']) . '</small>';
            if (!$compact && !empty($row['id'])) {
                echo '<br /><a class="button button-small" href="' . esc_url($report_url) . '" target="_blank" rel="noreferrer">' . esc_html__('Report', 'rifnote-search') . '</a>';
            }
            echo '</td>';
            echo '<td><span class="rs-ad-ctr warm">' . esc_html((int) ($row['progress'] ?? 0)) . '%</span></td>';
            echo '<td><span class="rs-ad-ctr ' . esc_attr($tone) . '">' . esc_html((int) ($row['delivery'] ?? 0)) . '%</span><br /><small>' . esc_html(number_format_i18n((int) ($row['impressions'] ?? 0))) . ' ' . esc_html__('views', 'rifnote-search') . '</small></td>';
            echo '<td>' . esc_html(number_format_i18n((int) ($row['clicks'] ?? 0))) . ' ' . esc_html__('clicks', 'rifnote-search') . '<br /><small>' . esc_html(number_format_i18n((int) ($row['conversions'] ?? 0))) . ' ' . esc_html__('conv.', 'rifnote-search') . '</small></td>';
            if (!$compact) {
                echo '<td>₦' . esc_html(number_format_i18n((float) ($row['budget'] ?? 0))) . '<br /><small>₦' . esc_html(number_format_i18n((float) ($row['conversion_value'] ?? 0))) . ' ' . esc_html__('conv. value', 'rifnote-search') . '</small></td>';
                echo '<td>' . esc_html($row['signal']) . '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private static function render_revenue_status_table($rows) {
        if (empty($rows)) {
            echo '<p class="description">' . esc_html__('No campaign revenue rows yet.', 'rifnote-search') . '</p>';
            return;
        }

        echo '<table class="widefat striped rs-ad-report-table"><thead><tr><th>' . esc_html__('Status', 'rifnote-search') . '</th><th>' . esc_html__('Campaigns', 'rifnote-search') . '</th><th>' . esc_html__('Value', 'rifnote-search') . '</th><th>' . esc_html__('Paid', 'rifnote-search') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td><strong>' . esc_html(ucwords(str_replace('_', ' ', $row['label'] ?? ''))) . '</strong></td><td>' . esc_html(number_format_i18n((int) ($row['campaigns'] ?? 0))) . '</td><td>₦' . esc_html(number_format_i18n((float) ($row['value'] ?? 0))) . '</td><td>₦' . esc_html(number_format_i18n((float) ($row['paid_value'] ?? 0))) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_source_report_table($rows) {
        if (empty($rows)) {
            echo '<p class="description">' . esc_html__('No source activity yet. Once stories and clicks land, publisher/source reporting fills up here.', 'rifnote-search') . '</p>';
            return;
        }

        echo '<table class="widefat striped rs-ad-report-table"><thead><tr><th>' . esc_html__('Source', 'rifnote-search') . '</th><th>' . esc_html__('Posts', 'rifnote-search') . '</th><th>' . esc_html__('Views', 'rifnote-search') . '</th><th>' . esc_html__('Clicks', 'rifnote-search') . '</th><th>' . esc_html__('CTR', 'rifnote-search') . '</th></tr></thead><tbody>';
        foreach (array_slice($rows, 0, 25) as $row) {
            $ctr = (float) ($row['ctr'] ?? 0);
            $tone = $ctr >= 2 ? 'hot' : ($ctr >= .5 ? 'warm' : 'cold');
            echo '<tr><td><strong>' . esc_html($row['source_name'] ?? __('Unknown source', 'rifnote-search')) . '</strong>';
            if (!empty($row['latest_post'])) {
                echo '<br /><small>' . esc_html(sprintf(__('Latest: %s', 'rifnote-search'), get_date_from_gmt($row['latest_post'], 'M j, Y'))) . '</small>';
            }
            echo '</td><td>' . esc_html(number_format_i18n((int) ($row['posts'] ?? 0))) . '</td><td>' . esc_html(number_format_i18n((int) ($row['impressions'] ?? 0))) . '</td><td>' . esc_html(number_format_i18n((int) ($row['clicks'] ?? 0))) . '</td><td><span class="rs-ad-ctr ' . esc_attr($tone) . '">' . esc_html(number_format_i18n($ctr, 2)) . '%</span></td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_content_performance_table($rows, $compact = false) {
        if (empty($rows)) {
            echo '<p class="description">' . esc_html__('No content performance rows yet. Search impressions and source clicks will fill this table.', 'rifnote-search') . '</p>';
            return;
        }

        echo '<table class="widefat striped rs-ad-report-table"><thead><tr><th>' . esc_html__('Story', 'rifnote-search') . '</th><th>' . esc_html__('Views', 'rifnote-search') . '</th><th>' . esc_html__('Clicks', 'rifnote-search') . '</th><th>' . esc_html__('CTR', 'rifnote-search') . '</th>';
        if (!$compact) {
            echo '<th>' . esc_html__('Category', 'rifnote-search') . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach (array_slice($rows, 0, $compact ? 6 : 30) as $row) {
            $ctr = (float) ($row['ctr'] ?? 0);
            $tone = $ctr >= 2 ? 'hot' : ($ctr >= .5 ? 'warm' : 'cold');
            echo '<tr><td><strong>' . esc_html($row['title'] ?? __('Untitled story', 'rifnote-search')) . '</strong><br /><small>' . esc_html($row['source_name'] ?? __('Unknown source', 'rifnote-search')) . '</small></td>';
            echo '<td>' . esc_html(number_format_i18n((int) ($row['impressions'] ?? 0))) . '</td><td>' . esc_html(number_format_i18n((int) ($row['clicks'] ?? 0))) . '</td><td><span class="rs-ad-ctr ' . esc_attr($tone) . '">' . esc_html(number_format_i18n($ctr, 2)) . '%</span></td>';
            if (!$compact) {
                echo '<td>' . esc_html($row['category'] ?? '') . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_scheduled_report_settings() {
        $next = wp_next_scheduled(self::ADS_REPORT_CRON_HOOK);
        echo '<p>' . esc_html__('Choose where ad/revenue report summaries should go. The CSV exports stay manual for now; the scheduled email sends a concise executive summary.', 'rifnote-search') . '</p>';
        echo '<form method="post" action="options.php">';
        settings_fields('rifnote_search_settings');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row"><label for="rifnote_ads_report_email_inline">' . esc_html__('Send reports to', 'rifnote-search') . '</label></th><td><input id="rifnote_ads_report_email_inline" class="regular-text" type="email" name="rifnote_ads_report_email" value="' . esc_attr(get_option('rifnote_ads_report_email', get_option('admin_email'))) . '" /></td></tr>';
        echo '<tr><th scope="row"><label for="rifnote_ads_report_frequency_inline">' . esc_html__('Cadence', 'rifnote-search') . '</label></th><td><select id="rifnote_ads_report_frequency_inline" name="rifnote_ads_report_frequency"><option value="off" ' . selected(get_option('rifnote_ads_report_frequency', 'off'), 'off', false) . '>' . esc_html__('Off', 'rifnote-search') . '</option><option value="daily" ' . selected(get_option('rifnote_ads_report_frequency', 'off'), 'daily', false) . '>' . esc_html__('Daily', 'rifnote-search') . '</option><option value="weekly" ' . selected(get_option('rifnote_ads_report_frequency', 'off'), 'weekly', false) . '>' . esc_html__('Weekly', 'rifnote-search') . '</option></select></td></tr>';
        echo '<tr><th scope="row"><label for="rifnote_ads_report_days_inline">' . esc_html__('Lookback', 'rifnote-search') . '</label></th><td><input id="rifnote_ads_report_days_inline" type="number" min="1" max="365" name="rifnote_ads_report_days" value="' . esc_attr((int) get_option('rifnote_ads_report_days', 30)) . '" /> <span class="description">' . esc_html__('days', 'rifnote-search') . '</span></td></tr>';
        echo '</tbody></table>';
        submit_button(__('Save report schedule', 'rifnote-search'), 'secondary');
        echo '</form>';
        echo '<p class="description">' . esc_html(sprintf(__('Next scheduled report: %s', 'rifnote-search'), $next ? get_date_from_gmt(gmdate('Y-m-d H:i:s', $next), 'M j, Y g:i A') : __('not scheduled', 'rifnote-search'))) . '</p>';
    }

    private static function render_ad_risk_alerts($alerts) {
        if (empty($alerts)) {
            echo '<p class="description">' . esc_html__('No suspicious ad patterns detected yet. Keep the coffee warm, not the panic button.', 'rifnote-search') . '</p>';
            return;
        }

        echo '<div class="rs-ad-risk-list">';
        foreach (array_slice($alerts, 0, 8) as $alert) {
            $level = sanitize_key($alert['level'] ?? 'medium');
            echo '<article class="' . esc_attr($level) . '"><strong>' . esc_html($alert['title'] ?? __('Risk alert', 'rifnote-search')) . '</strong><span>' . esc_html($alert['message'] ?? '') . '</span></article>';
        }
        echo '</div>';
    }

    private static function render_ad_bucket_card($title, $rows) {
        echo '<div class="rs-admin-card"><h2>' . esc_html($title) . '</h2>';
        if (empty($rows)) {
            echo '<p class="description">' . esc_html__('No ad events tracked yet.', 'rifnote-search') . '</p></div>';
            return;
        }

        echo '<div class="rs-ad-bucket-list">';
        foreach (array_slice($rows, 0, 8) as $row) {
            echo '<article><span>' . esc_html($row['label'] ?: __('Unknown', 'rifnote-search')) . '</span><strong>' . esc_html(number_format_i18n((int) ($row['impressions'] ?? 0))) . '</strong><small>' . esc_html(number_format_i18n((float) ($row['ctr'] ?? 0), 2)) . '% CTR · ' . esc_html(number_format_i18n((int) ($row['clicks'] ?? 0))) . ' ' . esc_html__('clicks', 'rifnote-search') . ' · ' . esc_html(number_format_i18n((int) ($row['conversions'] ?? 0))) . ' ' . esc_html__('conv.', 'rifnote-search') . '</small></article>';
        }
        echo '</div></div>';
    }

    private static function render_analytics_admin_page($section, $search_summary, $usage, $audience, $events, $ad_performance = array(), $content_performance = array(), $football_analytics = array()) {
        if (self::is_section('analytics-dashboard')) {
            self::render_analytics_kpis($usage);
            echo '<div class="rs-admin-card"><h2>' . esc_html__('Right-now pulse', 'rifnote-search') . '</h2><div class="rs-admin-kpi-grid">';
            self::render_admin_kpi(__('Active users', 'rifnote-search'), $usage['active_now'] ?? 0, __('Last 30 minutes', 'rifnote-search'));
            self::render_admin_kpi(__('Searches', 'rifnote-search'), $search_summary['searches'] ?? 0, __('This week', 'rifnote-search'));
            self::render_admin_kpi(__('Content clicks', 'rifnote-search'), $search_summary['source_clicks'] ?? 0, __('Sent to sources', 'rifnote-search'));
            self::render_admin_kpi(__('Live matches', 'rifnote-search'), $football_analytics['live'] ?? 0, __('Football desk now', 'rifnote-search'));
            echo '</div></div>';
            echo '<div class="rs-admin-analytics-grid rs-admin-analytics-grid-main">';
            echo '<div class="rs-admin-card rs-admin-card-wide"><h2>' . esc_html__('Traffic pulse', 'rifnote-search') . '</h2><p>' . esc_html__('Daily events across page views, searches and engagement.', 'rifnote-search') . '</p>';
            self::render_svg_line_chart($usage['daily_series'] ?? array(), 'total');
            echo '</div>';
            echo '<div class="rs-admin-card"><h2>' . esc_html__('Device mix', 'rifnote-search') . '</h2>';
            self::render_donut_chart($usage['top_devices'] ?? array());
            echo '</div></div>';
            echo '<div class="rs-admin-analytics-grid">';
            self::render_admin_bar_card(__('Top page types', 'rifnote-search'), $usage['top_page_types'] ?? array());
            self::render_admin_bar_card(__('Traffic sources', 'rifnote-search'), $usage['traffic_sources'] ?? array());
            self::render_admin_bar_card(__('Top interests', 'rifnote-search'), $audience['top_interests'] ?? array());
            echo '</div>';
            echo '<div class="rs-admin-analytics-grid rs-admin-analytics-grid-main">';
            echo '<div class="rs-admin-card"><h2>' . esc_html__('Content moving people', 'rifnote-search') . '</h2>';
            self::render_content_performance_table($content_performance['top_posts'] ?? array(), true);
            echo '</div><div class="rs-admin-card"><h2>' . esc_html__('Traffic acquisition', 'rifnote-search') . '</h2>';
            self::render_bar_chart($usage['traffic_sources'] ?? array());
            echo '</div></div>';
            return;
        }

        if (self::is_section('analytics-audience')) {
            echo '<h2>' . esc_html__('Users, guests and recurring visitors', 'rifnote-search') . '</h2>';
            echo '<div class="rs-admin-kpi-grid">';
            self::render_admin_kpi(__('Guests', 'rifnote-search'), $audience['guests'] ?? 0, __('Anonymous audience pool', 'rifnote-search'));
            self::render_admin_kpi(__('Registered users', 'rifnote-search'), $audience['registered'] ?? 0, __('Logged-in users', 'rifnote-search'));
            self::render_admin_kpi(__('Returning visitors', 'rifnote-search'), $audience['returning'] ?? 0, __('Repeat audience', 'rifnote-search'));
            self::render_admin_kpi(__('Ad responsive', 'rifnote-search'), $audience['ad_responsive'] ?? 0, __('Clicked ads before', 'rifnote-search'));
            echo '</div>';
            echo '<div class="rs-admin-analytics-grid rs-admin-analytics-grid-main">';
            echo '<div class="rs-admin-card rs-admin-map-card"><h2>' . esc_html__('Location map', 'rifnote-search') . '</h2><p>' . esc_html__('Country and state/region concentration from first-party visitor context.', 'rifnote-search') . '</p>';
            self::render_location_panel($audience['country_rows'] ?? array(), $audience['region_rows'] ?? array());
            echo '</div>';
            self::render_admin_bar_card(__('Ad-ready segments', 'rifnote-search'), $audience['segments'] ?? array());
            echo '</div><div class="rs-admin-analytics-grid">';
            self::render_admin_bar_card(__('Top interests', 'rifnote-search'), $audience['top_interests'] ?? array());
            self::render_admin_bar_card(__('Device mix', 'rifnote-search'), $audience['device_mix'] ?? array());
            self::render_admin_bar_card(__('Traffic sources', 'rifnote-search'), $audience['traffic_sources'] ?? array());
            echo '</div>';
            return;
        }

        if (self::is_section('analytics-usage')) {
            echo '<h2>' . esc_html__('App usage', 'rifnote-search') . '</h2>';
            self::render_analytics_kpis($usage);
            echo '<div class="rs-admin-card"><h2>' . esc_html__('Usage trend', 'rifnote-search') . '</h2>';
            self::render_svg_line_chart($usage['daily_series'] ?? array(), 'page_views');
            echo '</div><div class="rs-admin-analytics-grid">';
            self::render_admin_bar_card(__('Top page types', 'rifnote-search'), $usage['top_page_types'] ?? array());
            self::render_admin_bar_card(__('Top devices', 'rifnote-search'), $usage['top_devices'] ?? array());
            self::render_admin_bar_card(__('Top event types', 'rifnote-search'), $usage['top_events'] ?? array());
            echo '</div>';
            return;
        }

        if (self::is_section('analytics-search')) {
            echo '<h2>' . esc_html__('Search and content analytics', 'rifnote-search') . '</h2>';
            echo '<div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;max-width:920px;">';
            echo '<div class="card"><h3>' . esc_html(number_format_i18n((int) ($search_summary['searches'] ?? 0))) . '</h3><p>' . esc_html__('Searches', 'rifnote-search') . '</p></div>';
            echo '<div class="card"><h3>' . esc_html(number_format_i18n((int) ($search_summary['no_result_searches'] ?? 0))) . '</h3><p>' . esc_html__('No-result searches', 'rifnote-search') . '</p></div>';
            echo '<div class="card"><h3>' . esc_html(number_format_i18n((int) ($search_summary['source_clicks'] ?? 0))) . '</h3><p>' . esc_html__('Source clicks', 'rifnote-search') . '</p></div>';
            echo '</div>';
            echo '<div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;align-items:start;margin-top:18px;">';
            echo '<div class="card"><h3>' . esc_html__('Top queries', 'rifnote-search') . '</h3>';
            self::render_analytics_list($search_summary['top_queries'] ?? array());
            echo '</div><div class="card"><h3>' . esc_html__('No-result queries', 'rifnote-search') . '</h3>';
            self::render_analytics_list($search_summary['no_result_queries'] ?? array());
            echo '</div><div class="card"><h3>' . esc_html__('Top sources clicked', 'rifnote-search') . '</h3>';
            self::render_analytics_list($search_summary['top_sources'] ?? array());
            echo '</div></div>';
            echo '<div class="rs-admin-analytics-grid rs-admin-analytics-grid-main"><div class="rs-admin-card"><h2>' . esc_html__('Content performance', 'rifnote-search') . '</h2><p>' . esc_html__('Stories people actually see and click from search results.', 'rifnote-search') . '</p>';
            self::render_content_performance_table($content_performance['top_posts'] ?? array(), false);
            echo '</div><div class="rs-admin-card"><h2>' . esc_html__('Origin mix', 'rifnote-search') . '</h2><p>' . esc_html__('How much content each model/source workflow is adding.', 'rifnote-search') . '</p>';
            self::render_bar_chart($content_performance['origin_rows'] ?? array());
            echo '</div></div>';
            return;
        }

        if (self::is_section('analytics-football')) {
            echo '<h2>' . esc_html__('Football analytics', 'rifnote-search') . '</h2>';
            echo '<div class="rs-admin-kpi-grid">';
            self::render_admin_kpi(__('Live now', 'rifnote-search'), $football_analytics['live'] ?? 0, __('Matches in play', 'rifnote-search'));
            self::render_admin_kpi(__('Next 24h', 'rifnote-search'), $football_analytics['upcoming_24h'] ?? 0, __('Upcoming fixtures', 'rifnote-search'));
            self::render_admin_kpi(__('Finished', 'rifnote-search'), $football_analytics['finished'] ?? 0, __('Recent results', 'rifnote-search'));
            self::render_admin_kpi(__('API calls', 'rifnote-search'), $football_analytics['api_calls'] ?? 0, __('Last 7 days', 'rifnote-search'));
            echo '</div><div class="rs-admin-analytics-grid">';
            self::render_admin_bar_card(__('Top competitions', 'rifnote-search'), $football_analytics['top_competitions'] ?? array());
            self::render_admin_bar_card(__('Teams appearing most', 'rifnote-search'), $football_analytics['team_rows'] ?? array());
            self::render_admin_bar_card(__('API endpoints', 'rifnote-search'), $football_analytics['endpoint_rows'] ?? array());
            echo '</div><div class="rs-admin-card"><h2>' . esc_html__('Football data health', 'rifnote-search') . '</h2><div class="rs-admin-kpi-grid">';
            self::render_admin_kpi(__('Cache hits', 'rifnote-search'), $football_analytics['cache_hits'] ?? 0, __('Saved API calls', 'rifnote-search'));
            self::render_admin_kpi(__('Errors', 'rifnote-search'), $football_analytics['errors'] ?? 0, __('API failures', 'rifnote-search'));
            self::render_admin_kpi(__('Cache rate', 'rifnote-search'), !empty($football_analytics['api_calls']) ? number_format_i18n(((int) $football_analytics['cache_hits'] / max(1, (int) $football_analytics['api_calls'])) * 100, 1) . '%' : '0%', __('Quota protection', 'rifnote-search'));
            self::render_admin_kpi(__('Window', 'rifnote-search'), ($football_analytics['days'] ?? 7) . 'd', __('Reporting range', 'rifnote-search'));
            echo '</div></div>';
            return;
        }

        if (self::is_section('analytics-ads')) {
            $ctr = !empty($usage['ad_impressions']) ? round(((int) ($usage['ad_clicks'] ?? 0) / (int) $usage['ad_impressions']) * 100, 2) : 0;
            echo '<h2>' . esc_html__('Ad analytics', 'rifnote-search') . '</h2>';
            echo '<div class="rs-admin-kpi-grid">';
            self::render_admin_kpi(__('Ad impressions', 'rifnote-search'), $usage['ad_impressions'] ?? 0, __('Rendered sponsored slots', 'rifnote-search'));
            self::render_admin_kpi(__('Ad clicks', 'rifnote-search'), $usage['ad_clicks'] ?? 0, __('Sponsored click actions', 'rifnote-search'));
            self::render_admin_kpi(__('CTR', 'rifnote-search'), number_format_i18n($ctr, 2) . '%', __('Click-through rate', 'rifnote-search'));
            self::render_admin_kpi(__('Conversions', 'rifnote-search'), $usage['ad_conversions'] ?? 0, __('Tracked outcomes', 'rifnote-search'));
            echo '</div><div class="rs-admin-analytics-grid">';
            self::render_ad_bucket_card(__('Sponsor leaderboard', 'rifnote-search'), $ad_performance['sponsors'] ?? array());
            self::render_ad_bucket_card(__('Ad devices', 'rifnote-search'), $ad_performance['devices'] ?? array());
            self::render_ad_bucket_card(__('Ad locations', 'rifnote-search'), $ad_performance['geo'] ?? array());
            echo '</div><div class="rs-admin-analytics-grid rs-admin-analytics-grid-main">';
            echo '<div class="rs-admin-card"><h2>' . esc_html__('Placement performance', 'rifnote-search') . '</h2><p>' . esc_html__('Database-backed placement report across impressions, clicks and CTR.', 'rifnote-search') . '</p>';
            self::render_ads_export_buttons();
            self::render_ad_performance_table($ad_performance['placements'] ?? array(), false);
            echo '</div><div class="rs-admin-card"><h2>' . esc_html__('Underperforming slots', 'rifnote-search') . '</h2>';
            self::render_ad_weak_slots($ad_performance['weak_slots'] ?? array());
            echo '</div></div><div class="rs-admin-analytics-grid rs-admin-analytics-grid-main">';
            echo '<div class="rs-admin-card"><h2>' . esc_html__('Campaign pacing', 'rifnote-search') . '</h2>';
            self::render_ad_pacing_table($ad_performance['campaign_pacing'] ?? array(), false);
            echo '</div><div class="rs-admin-card"><h2>' . esc_html__('Fraud and anomaly watch', 'rifnote-search') . '</h2>';
            self::render_ad_risk_alerts($ad_performance['risk_alerts'] ?? array());
            echo '</div>';
            return;
        }

        if (self::is_section('analytics-events')) {
            echo '<h2>' . esc_html__('Recent event stream', 'rifnote-search') . '</h2>';
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Event', 'rifnote-search') . '</th><th>' . esc_html__('Visitor', 'rifnote-search') . '</th><th>' . esc_html__('Context', 'rifnote-search') . '</th><th>' . esc_html__('When', 'rifnote-search') . '</th></tr></thead><tbody>';
            if (!$events) {
                echo '<tr><td colspan="4">' . esc_html__('No analytics events yet.', 'rifnote-search') . '</td></tr>';
            }
            foreach ($events as $event) {
                $metadata = !empty($event['metadata']) ? json_decode($event['metadata'], true) : array();
                echo '<tr><td><strong>' . esc_html($event['event_type']) . '</strong><br /><small>' . esc_html($event['device_type']) . '</small></td>';
                echo '<td><code>' . esc_html($event['visitor_id']) . '</code><br /><small>' . esc_html($event['visitor_type']) . (!empty($event['is_returning']) ? ' · ' . esc_html__('returning', 'rifnote-search') : '') . '</small></td>';
                echo '<td>' . esc_html($event['category'] ?: ($metadata['page_type'] ?? '')) . '<br /><small>' . esc_html(wp_trim_words($event['query_text'] ?: ($event['target_url'] ?? ''), 12)) . '</small></td>';
                echo '<td>' . esc_html(get_date_from_gmt($event['created_at'], 'M j, H:i')) . '</td></tr>';
            }
            echo '</tbody></table>';
            return;
        }

        if (self::is_section('analytics-settings')) {
            echo '<h2>' . esc_html__('Analytics settings', 'rifnote-search') . '</h2>';
            echo '<form method="post" action="options.php">';
            settings_fields('rifnote_search_settings');
            echo '<table class="form-table"><tbody>';
            echo '<tr><th scope="row">' . esc_html__('Analytics tracking', 'rifnote-search') . '</th><td><label><input type="checkbox" name="rifnote_analytics_enabled" value="1" ' . checked((bool) get_option('rifnote_analytics_enabled', true), true, false) . ' /> ' . esc_html__('Track app usage and engagement events.', 'rifnote-search') . '</label></td></tr>';
            echo '<tr><th scope="row">' . esc_html__('Guest tracking', 'rifnote-search') . '</th><td><label><input type="checkbox" name="rifnote_analytics_guest_tracking" value="1" ' . checked((bool) get_option('rifnote_analytics_guest_tracking', true), true, false) . ' /> ' . esc_html__('Track anonymous guest visitors with first-party IDs.', 'rifnote-search') . '</label><p class="description">' . esc_html__('Recommended for ads. IDs are random first-party values, not exact identity.', 'rifnote-search') . '</p></td></tr>';
            echo '<tr><th scope="row"><label for="rifnote_analytics_retention_days">' . esc_html__('Retention days', 'rifnote-search') . '</label></th><td><input id="rifnote_analytics_retention_days" type="number" min="30" max="730" name="rifnote_analytics_retention_days" value="' . esc_attr((int) get_option('rifnote_analytics_retention_days', 180)) . '" /></td></tr>';
            echo '</tbody></table>';
            submit_button(__('Save analytics settings', 'rifnote-search'));
            echo '</form>';
            return;
        }
    }

    private static function render_analytics_list($rows) {
        if (empty($rows)) {
            esc_html_e('No data yet.', 'rifnote-search');
            return;
        }

        echo '<ol style="margin:0 0 0 18px;">';

        foreach ($rows as $row) {
            echo '<li>' . esc_html($row['label']) . ' <small>(' . esc_html(number_format_i18n((int) $row['total'])) . ')</small></li>';
        }

        echo '</ol>';
    }

    private static function render_analytics_kpis($usage) {
        echo '<div class="rs-admin-kpi-grid">';
        self::render_admin_kpi(__('Visitors', 'rifnote-search'), $usage['visitors'] ?? 0, __('Unique first-party visitors', 'rifnote-search'));
        self::render_admin_kpi(__('Sessions', 'rifnote-search'), $usage['sessions'] ?? 0, __('Tracked visits', 'rifnote-search'));
        self::render_admin_kpi(__('Page views', 'rifnote-search'), $usage['page_views'] ?? 0, __('Screen loads', 'rifnote-search'));
        self::render_admin_kpi(__('Active now', 'rifnote-search'), $usage['active_now'] ?? 0, __('Last 30 minutes', 'rifnote-search'));
        echo '</div>';
    }

    private static function render_admin_kpi($label, $value, $note = '') {
        $display = is_numeric($value) ? number_format_i18n((float) $value) : $value;
        echo '<div class="rs-admin-kpi"><span>' . esc_html($label) . '</span><strong>' . esc_html($display) . '</strong><small>' . esc_html($note) . '</small></div>';
    }

    private static function render_admin_bar_card($title, $rows) {
        echo '<div class="rs-admin-card"><h2>' . esc_html($title) . '</h2>';
        self::render_bar_chart($rows);
        echo '</div>';
    }

    private static function render_bar_chart($rows) {
        if (empty($rows)) {
            echo '<p class="description">' . esc_html__('No data yet.', 'rifnote-search') . '</p>';
            return;
        }

        $max = max(array_map(function ($row) {
            return (int) ($row['total'] ?? 0);
        }, $rows));

        echo '<div class="rs-admin-bars">';
        foreach ($rows as $row) {
            $total = (int) ($row['total'] ?? 0);
            $width = $max > 0 ? max(4, round(($total / $max) * 100)) : 0;
            echo '<div class="rs-admin-bar-row"><div><span>' . esc_html($row['label'] ?: __('Unknown', 'rifnote-search')) . '</span><b>' . esc_html(number_format_i18n($total)) . '</b></div><i><em style="width:' . esc_attr($width) . '%"></em></i></div>';
        }
        echo '</div>';
    }

    private static function render_svg_line_chart($rows, $key = 'total') {
        $width = 760;
        $height = 220;
        $pad = 24;
        $values = array_map(function ($row) use ($key) {
            return (int) ($row[$key] ?? 0);
        }, is_array($rows) ? $rows : array());
        $max = max(1, $values ? max($values) : 1);
        $count = max(1, count($values));
        $points = array();

        foreach ($values as $index => $value) {
            $x = $pad + ($count <= 1 ? 0 : ($index / ($count - 1)) * ($width - $pad * 2));
            $y = $height - $pad - (($value / $max) * ($height - $pad * 2));
            $points[] = round($x, 2) . ',' . round($y, 2);
        }

        echo '<svg class="rs-admin-line-chart" viewBox="0 0 ' . esc_attr($width) . ' ' . esc_attr($height) . '" role="img" aria-label="' . esc_attr__('Analytics trend chart', 'rifnote-search') . '">';
        echo '<defs><linearGradient id="rsTrendFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#ed1c24" stop-opacity=".25"/><stop offset="100%" stop-color="#ed1c24" stop-opacity="0"/></linearGradient></defs>';
        for ($i = 0; $i < 4; $i++) {
            $y = $pad + $i * (($height - $pad * 2) / 3);
            echo '<line x1="' . esc_attr($pad) . '" y1="' . esc_attr($y) . '" x2="' . esc_attr($width - $pad) . '" y2="' . esc_attr($y) . '" stroke="#e8edf4" stroke-width="1"/>';
        }
        $poly = implode(' ', $points);
        $area = $poly ? $pad . ',' . ($height - $pad) . ' ' . $poly . ' ' . ($width - $pad) . ',' . ($height - $pad) : '';
        echo '<polygon points="' . esc_attr($area) . '" fill="url(#rsTrendFill)"/>';
        echo '<polyline points="' . esc_attr($poly) . '" fill="none" stroke="#ed1c24" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>';
        foreach ($points as $point) {
            list($x, $y) = array_map('floatval', explode(',', $point));
            echo '<circle cx="' . esc_attr($x) . '" cy="' . esc_attr($y) . '" r="4" fill="#ed1c24"/>';
        }
        echo '</svg>';
    }

    private static function render_donut_chart($rows) {
        $total = array_sum(array_map(function ($row) {
            return (int) ($row['total'] ?? 0);
        }, is_array($rows) ? $rows : array()));

        if (!$total) {
            echo '<p class="description">' . esc_html__('No data yet.', 'rifnote-search') . '</p>';
            return;
        }

        $colors = array('#ed1c24', '#111827', '#16a34a', '#f59e0b', '#2563eb');
        $offset = 25;
        echo '<div class="rs-admin-donut-wrap"><svg class="rs-admin-donut" viewBox="0 0 42 42">';
        echo '<circle cx="21" cy="21" r="15.915" fill="transparent" stroke="#eef2f7" stroke-width="7"></circle>';
        foreach (array_values($rows) as $index => $row) {
            $value = (int) ($row['total'] ?? 0);
            $dash = ($value / $total) * 100;
            echo '<circle cx="21" cy="21" r="15.915" fill="transparent" stroke="' . esc_attr($colors[$index % count($colors)]) . '" stroke-width="7" stroke-dasharray="' . esc_attr($dash) . ' ' . esc_attr(100 - $dash) . '" stroke-dashoffset="' . esc_attr($offset) . '"></circle>';
            $offset -= $dash;
        }
        echo '<text x="21" y="20" text-anchor="middle" font-size="5" font-weight="800" fill="#111827">' . esc_html(number_format_i18n($total)) . '</text><text x="21" y="25" text-anchor="middle" font-size="2.8" fill="#667085">' . esc_html__('visitors', 'rifnote-search') . '</text></svg>';
        echo '<div class="rs-admin-donut-legend">';
        foreach (array_values($rows) as $index => $row) {
            echo '<span><i style="background:' . esc_attr($colors[$index % count($colors)]) . '"></i>' . esc_html($row['label'] ?: __('Unknown', 'rifnote-search')) . ' <b>' . esc_html(number_format_i18n((int) $row['total'])) . '</b></span>';
        }
        echo '</div></div>';
    }

    private static function render_location_panel($countries, $regions) {
        echo '<div class="rs-admin-location-panel"><div class="rs-admin-map-visual">';
        $bubbles = array_slice(is_array($countries) ? $countries : array(), 0, 6);
        foreach ($bubbles as $index => $row) {
            $size = 34 + min(52, (int) $row['total'] * 4);
            echo '<span class="rs-admin-map-bubble b' . esc_attr($index + 1) . '" style="width:' . esc_attr($size) . 'px;height:' . esc_attr($size) . 'px"><b>' . esc_html($row['label']) . '</b><small>' . esc_html(number_format_i18n((int) $row['total'])) . '</small></span>';
        }
        echo '</div><div class="rs-admin-location-lists">';
        echo '<h3>' . esc_html__('Countries', 'rifnote-search') . '</h3>';
        self::render_bar_chart($countries);
        echo '<h3>' . esc_html__('States / regions', 'rifnote-search') . '</h3>';
        self::render_bar_chart($regions);
        echo '</div></div>';
    }
}
