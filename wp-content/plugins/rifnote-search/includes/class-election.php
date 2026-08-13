<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Election {
    const OPTION = 'rifnote_search_election_takeover';
    const POST_TYPE = 'rifnote_election';
    const META = '_rifnote_election_settings';
    const FEATURED_META = '_rifnote_election_featured';
    const MIGRATED_OPTION = 'rifnote_search_election_cpt_migrated';

    public static function init() {
        add_action('init', array(__CLASS__, 'register_post_type'));
        add_action('init', array(__CLASS__, 'maybe_migrate_option_to_cpt'), 20);
        add_action('admin_menu', array(__CLASS__, 'register_menu'), 30);
        add_action('add_meta_boxes', array(__CLASS__, 'register_meta_boxes'));
        add_action('save_post_' . self::POST_TYPE, array(__CLASS__, 'save_election'), 10, 2);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', array(__CLASS__, 'admin_columns'));
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', array(__CLASS__, 'render_admin_column'), 10, 2);
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_post_type() {
        register_post_type(self::POST_TYPE, array(
            'labels' => array(
                'name' => __('Elections', 'rifnote-search'),
                'singular_name' => __('Election', 'rifnote-search'),
                'add_new_item' => __('Add Election', 'rifnote-search'),
                'edit_item' => __('Edit Election', 'rifnote-search'),
                'new_item' => __('New Election', 'rifnote-search'),
                'view_item' => __('View Election', 'rifnote-search'),
                'search_items' => __('Search Elections', 'rifnote-search'),
                'not_found' => __('No elections found', 'rifnote-search'),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'rifnote-search',
            'show_in_rest' => false,
            'supports' => array('title'),
            'capability_type' => 'post',
            'menu_icon' => 'dashicons-chart-bar',
        ));
    }

    public static function maybe_migrate_option_to_cpt() {
        if (get_option(self::MIGRATED_OPTION)) {
            return;
        }

        $raw = get_option(self::OPTION, array());
        if (!is_array($raw) || empty($raw)) {
            update_option(self::MIGRATED_OPTION, '1', false);
            return;
        }

        $existing = get_posts(array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 1,
        ));

        if (!empty($existing)) {
            update_option(self::MIGRATED_OPTION, '1', false);
            return;
        }

        $settings = self::sanitize_settings($raw);
        $post_id = wp_insert_post(array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $settings['title'] ?: __('Election Takeover', 'rifnote-search'),
        ), true);

        if (!is_wp_error($post_id) && $post_id) {
            update_post_meta($post_id, self::META, $settings);
            update_post_meta($post_id, self::FEATURED_META, !empty($settings['enabled']) ? '1' : '0');
        }

        update_option(self::MIGRATED_OPTION, '1', false);
    }

    public static function defaults() {
        return array(
            'enabled' => false,
            'mode' => 'auto',
            'scope' => 'state',
            'state' => 'Osun',
            'eyebrow' => 'Osun Decides',
            'title' => 'Osun Decides 2026',
            'subtitle' => 'Countdown now. Live result bars when verified totals start landing.',
            'election_datetime' => '2026-08-15 08:00',
            'coverage_url' => home_url('/?q=Osun%20election'),
            'media_url' => '',
            'media_type' => 'image',
            'last_update_label' => '',
            'result_note' => 'Awaiting official result updates.',
            'lgas_reporting' => 0,
            'lgas_total' => 30,
            'units_reporting' => 0,
            'units_total' => 3763,
            'parties' => array(
                array('key' => 'apc', 'name' => 'APC', 'candidate' => '', 'logo_url' => '', 'color' => '#ed1c24', 'votes' => 0),
                array('key' => 'pdp', 'name' => 'PDP', 'candidate' => '', 'logo_url' => '', 'color' => '#16a34a', 'votes' => 0),
                array('key' => 'lp', 'name' => 'LP', 'candidate' => '', 'logo_url' => '', 'color' => '#d71920', 'votes' => 0),
                array('key' => 'adc', 'name' => 'ADC', 'candidate' => '', 'logo_url' => '', 'color' => '#2563eb', 'votes' => 0),
            ),
            'lga_results' => array(),
            'state_results' => array(),
        );
    }

    public static function nigeria_lgas() {
        static $lgas = null;

        if (null === $lgas) {
            $path = trailingslashit(RIFNOTE_SEARCH_DIR) . 'includes/data/nigeria-lgas.php';
            $data = file_exists($path) ? include $path : array();
            $lgas = is_array($data) ? $data : array();
        }

        return $lgas;
    }

    public static function settings($post_id = 0) {
        $raw = array();
        if ($post_id) {
            $raw = get_post_meta(absint($post_id), self::META, true);
        } else {
            $active_id = self::active_election_id();
            if ($active_id) {
                $raw = get_post_meta($active_id, self::META, true);
            }
        }

        if (!$raw) {
            $raw = get_option(self::OPTION, array());
        }

        if (!is_array($raw)) {
            $raw = array();
        }

        $settings = wp_parse_args($raw, self::defaults());
        $settings['scope'] = self::sanitize_scope((string) ($settings['scope'] ?? 'state'));
        $settings['parties'] = self::sanitize_parties(isset($settings['parties']) ? $settings['parties'] : array());
        $settings['state'] = self::sanitize_state((string) ($settings['state'] ?? 'Osun'));
        $settings['lga_results'] = self::sanitize_lga_results(isset($settings['lga_results']) ? $settings['lga_results'] : array(), $settings['parties'], $settings['state']);
        $settings['state_results'] = self::sanitize_state_results(isset($settings['state_results']) ? $settings['state_results'] : array(), $settings['parties']);

        return $settings;
    }

    public static function public_payload() {
        $active_id = self::active_election_id();
        if (!$active_id) {
            return array(
                'enabled' => false,
                'id' => 0,
                'phase' => 'off',
                'scope' => 'state',
                'scope_label' => __('State', 'rifnote-search'),
                'state' => '',
                'eyebrow' => '',
                'title' => '',
                'subtitle' => '',
                'election_datetime' => '',
                'countdown_seconds' => 0,
                'coverage_url' => '',
                'media_url' => '',
                'media_type' => 'image',
                'last_update_label' => '',
                'result_note' => '',
                'lgas_reporting' => 0,
                'lgas_total' => 0,
                'states_reporting' => 0,
                'states_total' => 0,
                'units_reporting' => 0,
                'units_total' => 0,
                'total_votes' => 0,
                'parties' => array(),
                'lga_results' => array(),
                'state_results' => array(),
                'updated_at' => current_time('mysql'),
            );
        }

        $settings = self::settings($active_id);
        $mode = sanitize_key((string) $settings['mode']);
        $scope = self::sanitize_scope((string) ($settings['scope'] ?? 'state'));
        $timestamp = self::election_timestamp($settings['election_datetime']);
        $now = current_time('timestamp');
        $phase = 'off';

        if (!empty($settings['enabled'])) {
            $phase = 'auto' === $mode ? (($timestamp && $now >= $timestamp) ? 'live' : 'countdown') : $mode;
            if (!in_array($phase, array('countdown', 'live'), true)) {
                $phase = 'countdown';
            }
        }

        $parties = array_values(array_filter(array_map(function($party) {
            return array(
                'key' => self::party_key((string) ($party['key'] ?? $party['name'] ?? '')),
                'name' => sanitize_text_field((string) ($party['name'] ?? '')),
                'candidate' => sanitize_text_field((string) ($party['candidate'] ?? '')),
                'logo_url' => esc_url_raw((string) ($party['logo_url'] ?? '')),
                'color' => self::sanitize_color((string) ($party['color'] ?? '#ed1c24')),
                'votes' => max(0, absint($party['votes'] ?? 0)),
            );
        }, $settings['parties']), function($party) {
            return '' !== $party['name'];
        }));

        $lga_results = array();
        $state_results = array();
        $rollup_totals = array();

        if ('national' === $scope) {
            $state_results = self::public_state_results($settings['state_results'], $parties);
            $rollup_totals = self::party_totals_from_state_results($state_results, $parties);
        } else {
            $lga_results = self::public_lga_results($settings['lga_results'], $parties, $settings['state']);
            $rollup_totals = self::party_totals_from_lgas($lga_results, $parties);
        }

        if (array_sum($rollup_totals) > 0) {
            foreach ($parties as &$party) {
                $party['votes'] = absint($rollup_totals[$party['key']] ?? 0);
            }
            unset($party);
        }

        $total_votes = array_sum(wp_list_pluck($parties, 'votes'));
        $max_votes = max(array_merge(array(1), wp_list_pluck($parties, 'votes')));

        foreach ($parties as &$party) {
            $party['percentage'] = $total_votes > 0 ? round(($party['votes'] / $total_votes) * 100, 1) : 0;
            $party['bar_width'] = $max_votes > 0 ? round(($party['votes'] / $max_votes) * 100, 1) : 0;
        }
        unset($party);

        return array(
            'enabled' => !empty($settings['enabled']),
            'id' => $active_id,
            'phase' => $phase,
            'scope' => $scope,
            'scope_label' => 'national' === $scope ? __('National', 'rifnote-search') : __('State', 'rifnote-search'),
            'state' => sanitize_text_field((string) $settings['state']),
            'eyebrow' => sanitize_text_field((string) $settings['eyebrow']),
            'title' => sanitize_text_field((string) $settings['title']),
            'subtitle' => sanitize_text_field((string) $settings['subtitle']),
            'election_datetime' => sanitize_text_field((string) $settings['election_datetime']),
            'countdown_seconds' => $timestamp ? max(0, $timestamp - $now) : 0,
            'coverage_url' => esc_url_raw((string) $settings['coverage_url']),
            'media_url' => esc_url_raw((string) $settings['media_url']),
            'media_type' => 'video' === $settings['media_type'] ? 'video' : 'image',
            'last_update_label' => sanitize_text_field((string) $settings['last_update_label']),
            'result_note' => sanitize_text_field((string) $settings['result_note']),
            'lgas_reporting' => self::lga_reporting_count($lga_results),
            'lgas_total' => 'national' === $scope ? 0 : count(self::state_lgas($settings['state'])),
            'states_reporting' => self::state_reporting_count($state_results),
            'states_total' => 'national' === $scope ? count(self::states_list()) : 0,
            'units_reporting' => absint($settings['units_reporting']),
            'units_total' => max(0, absint($settings['units_total'])),
            'total_votes' => $total_votes,
            'parties' => $parties,
            'lga_results' => $lga_results,
            'state_results' => $state_results,
            'updated_at' => current_time('mysql'),
        );
    }

    public static function register_menu() {
        add_submenu_page(
            'rifnote-search',
            __('Election Center', 'rifnote-search'),
            __('Election Center', 'rifnote-search'),
            'manage_options',
            'rifnote-search-election',
            array(__CLASS__, 'render_admin_page')
        );
    }

    public static function register_meta_boxes() {
        add_meta_box(
            'rifnote-election-settings',
            __('Election Control Room', 'rifnote-search'),
            array(__CLASS__, 'render_election_meta_box'),
            self::POST_TYPE,
            'normal',
            'high'
        );
    }

    public static function save_election($post_id, $post) {
        if (!isset($_POST['rifnote_election_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_election_nonce'])), 'rifnote_election_save')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $settings = self::sanitize_settings(wp_unslash($_POST));
        update_post_meta($post_id, self::META, $settings);
        update_post_meta($post_id, self::FEATURED_META, !empty($settings['enabled']) ? '1' : '0');

        if (!empty($settings['enabled'])) {
            $others = get_posts(array(
                'post_type' => self::POST_TYPE,
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => -1,
                'exclude' => array($post_id),
                'meta_key' => self::FEATURED_META,
                'meta_value' => '1',
            ));

            foreach ($others as $other_id) {
                $other_settings = self::settings($other_id);
                $other_settings['enabled'] = false;
                update_post_meta($other_id, self::META, $other_settings);
                update_post_meta($other_id, self::FEATURED_META, '0');
            }
        }
    }

    public static function admin_columns($columns) {
        $next = array();
        foreach ($columns as $key => $label) {
            $next[$key] = $label;
            if ('title' === $key) {
                $next['rs_state'] = __('Scope', 'rifnote-search');
                $next['rs_mode'] = __('Mode', 'rifnote-search');
                $next['rs_featured'] = __('Homepage', 'rifnote-search');
                $next['rs_lgas'] = __('Reporting', 'rifnote-search');
            }
        }

        return $next;
    }

    public static function render_admin_column($column, $post_id) {
        $settings = self::settings($post_id);
        if ('rs_state' === $column) {
            echo esc_html('national' === ($settings['scope'] ?? 'state') ? __('National', 'rifnote-search') : $settings['state']);
        } elseif ('rs_mode' === $column) {
            echo esc_html(ucfirst((string) $settings['mode']));
        } elseif ('rs_featured' === $column) {
            echo !empty($settings['enabled']) ? '<strong style="color:#ed1c24">' . esc_html__('Featured', 'rifnote-search') . '</strong>' : esc_html__('Off', 'rifnote-search');
        } elseif ('rs_lgas' === $column) {
            if ('national' === ($settings['scope'] ?? 'state')) {
                echo esc_html(sprintf('%d / %d states', self::state_reporting_count(self::public_state_results($settings['state_results'], $settings['parties'])), count(self::states_list())));
            } else {
                echo esc_html(sprintf('%d / %d LGAs', self::lga_reporting_count(self::public_lga_results($settings['lga_results'], $settings['parties'], $settings['state'])), count(self::state_lgas($settings['state']))));
            }
        }
    }

    public static function register_routes() {
        register_rest_route('rifnote/v1', '/election/takeover', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'rest_takeover'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function rest_takeover() {
        return rest_ensure_response(self::public_payload());
    }

    private static function active_election_id() {
        $ids = get_posts(array(
            'post_type' => self::POST_TYPE,
            'post_status' => array('publish', 'future', 'draft', 'pending', 'private'),
            'fields' => 'ids',
            'posts_per_page' => 1,
            'meta_key' => self::FEATURED_META,
            'meta_value' => '1',
            'orderby' => 'modified',
            'order' => 'DESC',
        ));

        return !empty($ids) ? absint($ids[0]) : 0;
    }

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage election settings.', 'rifnote-search'));
        }

        $active_id = self::active_election_id();
        $edit_url = admin_url('post-new.php?post_type=' . self::POST_TYPE);
        $list_url = admin_url('edit.php?post_type=' . self::POST_TYPE);
        ?>
        <div class="wrap rs-election-admin">
            <h1><?php esc_html_e('Election Center', 'rifnote-search'); ?></h1>
            <p><?php esc_html_e('Create one election per race: governorship, presidential, senate, house, referendum, or anything else Rifnote needs to track live.', 'rifnote-search'); ?></p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Create election', 'rifnote-search'); ?></a>
                <a class="button" href="<?php echo esc_url($list_url); ?>"><?php esc_html_e('Manage elections', 'rifnote-search'); ?></a>
            </p>
            <section class="rs-admin-card">
                <h2><?php esc_html_e('Homepage election', 'rifnote-search'); ?></h2>
                <?php if ($active_id) : ?>
                    <?php $settings = self::settings($active_id); ?>
                    <?php if ('national' === ($settings['scope'] ?? 'state')) : ?>
                        <p><strong><?php echo esc_html(get_the_title($active_id)); ?></strong> · <?php esc_html_e('National', 'rifnote-search'); ?> · <?php echo esc_html(sprintf('%d / %d states reporting', self::state_reporting_count(self::public_state_results($settings['state_results'], $settings['parties'])), count(self::states_list()))); ?></p>
                    <?php else : ?>
                        <p><strong><?php echo esc_html(get_the_title($active_id)); ?></strong> · <?php echo esc_html($settings['state']); ?> · <?php echo esc_html(sprintf('%d / %d LGAs reporting', self::lga_reporting_count(self::public_lga_results($settings['lga_results'], $settings['parties'], $settings['state'])), count(self::state_lgas($settings['state'])))); ?></p>
                    <?php endif; ?>
                    <p><a class="button" href="<?php echo esc_url(get_edit_post_link($active_id)); ?>"><?php esc_html_e('Open control room', 'rifnote-search'); ?></a></p>
                <?php else : ?>
                    <p><?php esc_html_e('No election is featured right now. Create an election and tick “Feature this election on homepage” when you want it to take over the homepage.', 'rifnote-search'); ?></p>
                <?php endif; ?>
            </section>
        </div>
        <?php self::admin_styles_and_scripts(); ?>
        <?php
    }

    public static function render_election_meta_box($post) {
        wp_nonce_field('rifnote_election_save', 'rifnote_election_nonce');
        self::render_control_room(self::settings($post->ID));
        self::admin_styles_and_scripts();
    }

    private static function render_control_room($settings) {
        $states = self::nigeria_lgas();
        $state_lgas = self::state_lgas($settings['state']);
        ?>
        <div class="rs-election-admin">
            <section class="rs-admin-card">
                <h2><?php esc_html_e('Homepage takeover', 'rifnote-search'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Feature this election', 'rifnote-search'); ?></th>
                        <td><label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?> /> <?php esc_html_e('Show this race on the homepage', 'rifnote-search'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rs-election-mode"><?php esc_html_e('Mode', 'rifnote-search'); ?></label></th>
                        <td>
                            <select id="rs-election-mode" name="mode">
                                <?php foreach (array('auto' => __('Auto countdown/live', 'rifnote-search'), 'countdown' => __('Countdown only', 'rifnote-search'), 'live' => __('Live results', 'rifnote-search')) as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['mode'], $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rs-election-scope"><?php esc_html_e('Election scope', 'rifnote-search'); ?></label></th>
                        <td>
                            <select id="rs-election-scope" name="scope">
                                <option value="state" <?php selected($settings['scope'], 'state'); ?>><?php esc_html_e('State race: LGA result entry', 'rifnote-search'); ?></option>
                                <option value="national" <?php selected($settings['scope'], 'national'); ?>><?php esc_html_e('National race: state result entry', 'rifnote-search'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Use State for governorship/local races. Use National for presidential races where every state and FCT roll into one national total.', 'rifnote-search'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rs-election-state"><?php esc_html_e('Election state', 'rifnote-search'); ?></label></th>
                        <td>
                            <select id="rs-election-state" name="state">
                                <?php foreach ($states as $state_name => $lgas) : ?>
                                    <option value="<?php echo esc_attr($state_name); ?>" <?php selected($settings['state'], $state_name); ?>>
                                        <?php echo esc_html(sprintf('%s (%d LGAs)', $state_name, count($lgas))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('For state races, save after changing state to load that state’s LGA result grid. National races use every state and FCT below.', 'rifnote-search'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rs-election-date"><?php esc_html_e('Election date/time', 'rifnote-search'); ?></label></th>
                        <td><input id="rs-election-date" class="regular-text" type="datetime-local" name="election_datetime" value="<?php echo esc_attr(str_replace(' ', 'T', (string) $settings['election_datetime'])); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rs-election-coverage-url"><?php esc_html_e('Election news button URL', 'rifnote-search'); ?></label></th>
                        <td><input id="rs-election-coverage-url" class="large-text" type="url" name="coverage_url" value="<?php echo esc_attr($settings['coverage_url']); ?>" /></td>
                    </tr>
                </table>
            </section>

            <section class="rs-admin-card">
                <h2><?php esc_html_e('Copy and media', 'rifnote-search'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="rs-election-eyebrow"><?php esc_html_e('Eyebrow', 'rifnote-search'); ?></label></th><td><input id="rs-election-eyebrow" class="regular-text" type="text" name="eyebrow" value="<?php echo esc_attr($settings['eyebrow']); ?>" /></td></tr>
                    <tr><th scope="row"><label for="rs-election-title"><?php esc_html_e('Title', 'rifnote-search'); ?></label></th><td><input id="rs-election-title" class="large-text" type="text" name="title" value="<?php echo esc_attr($settings['title']); ?>" /></td></tr>
                    <tr><th scope="row"><label for="rs-election-subtitle"><?php esc_html_e('Subtitle', 'rifnote-search'); ?></label></th><td><textarea id="rs-election-subtitle" class="large-text" rows="3" name="subtitle"><?php echo esc_textarea($settings['subtitle']); ?></textarea></td></tr>
                    <tr>
                        <th scope="row"><label for="rs-election-media-url"><?php esc_html_e('Countdown image/video', 'rifnote-search'); ?></label></th>
                        <td>
                            <div class="rs-media-field">
                                <input id="rs-election-media-url" class="large-text rs-media-url" type="url" name="media_url" value="<?php echo esc_attr($settings['media_url']); ?>" />
                                <p>
                                    <button type="button" class="button rs-media-picker" data-target="#rs-election-media-url" data-title="<?php esc_attr_e('Choose election media', 'rifnote-search'); ?>" data-button="<?php esc_attr_e('Use media', 'rifnote-search'); ?>"><?php esc_html_e('Choose from Media Library', 'rifnote-search'); ?></button>
                                    <button type="button" class="button rs-media-clear" data-target="#rs-election-media-url"><?php esc_html_e('Clear', 'rifnote-search'); ?></button>
                                </p>
                            </div>
                            <p>
                                <select name="media_type">
                                    <option value="image" <?php selected($settings['media_type'], 'image'); ?>><?php esc_html_e('Image / GIF', 'rifnote-search'); ?></option>
                                    <option value="video" <?php selected($settings['media_type'], 'video'); ?>><?php esc_html_e('Video', 'rifnote-search'); ?></option>
                                </select>
                            </p>
                        </td>
                    </tr>
                </table>
            </section>

            <?php self::render_party_and_result_sections($settings, $state_lgas); ?>
        </div>
        <?php
    }

    private static function render_party_and_result_sections($settings, $state_lgas) {
        $is_national = 'national' === ($settings['scope'] ?? 'state');
        $reporting_count = $is_national
            ? self::state_reporting_count(self::public_state_results($settings['state_results'], $settings['parties']))
            : self::lga_reporting_count(self::public_lga_results($settings['lga_results'], $settings['parties'], $settings['state']));
        $reporting_total = $is_national ? count(self::states_list()) : count($state_lgas);
        $reporting_label = $is_national ? __('states', 'rifnote-search') : __('LGAs', 'rifnote-search');
        ?>
        <section class="rs-admin-card">
            <h2><?php esc_html_e('Live result board', 'rifnote-search'); ?></h2>
            <table class="form-table" role="presentation">
                <tr><th scope="row"><?php esc_html_e('Reporting progress', 'rifnote-search'); ?></th><td><strong><?php echo esc_html(sprintf('%d / %d %s', $reporting_count, $reporting_total, $reporting_label)); ?></strong><br /><input type="number" min="0" name="units_reporting" value="<?php echo esc_attr($settings['units_reporting']); ?>" /> / <input type="number" min="0" name="units_total" value="<?php echo esc_attr($settings['units_total']); ?>" /> <?php esc_html_e('polling units', 'rifnote-search'); ?></td></tr>
                <tr><th scope="row"><label for="rs-election-last-update"><?php esc_html_e('Last update label', 'rifnote-search'); ?></label></th><td><input id="rs-election-last-update" class="regular-text" type="text" name="last_update_label" value="<?php echo esc_attr($settings['last_update_label']); ?>" placeholder="<?php esc_attr_e('Updated 9:42 PM', 'rifnote-search'); ?>" /></td></tr>
                <tr><th scope="row"><label for="rs-election-note"><?php esc_html_e('Result note', 'rifnote-search'); ?></label></th><td><input id="rs-election-note" class="large-text" type="text" name="result_note" value="<?php echo esc_attr($settings['result_note']); ?>" /></td></tr>
            </table>
            <div class="rs-election-party-table" id="rs-election-party-table">
                <div class="rs-election-party-head">
                    <span><?php esc_html_e('Party', 'rifnote-search'); ?></span>
                    <span><?php esc_html_e('Candidate', 'rifnote-search'); ?></span>
                    <span><?php esc_html_e('Logo', 'rifnote-search'); ?></span>
                    <span><?php esc_html_e('Color', 'rifnote-search'); ?></span>
                    <span><?php esc_html_e('Votes', 'rifnote-search'); ?></span>
                    <span></span>
                </div>
                <?php foreach ($settings['parties'] as $index => $party) : ?>
                    <div class="rs-election-party-row">
                        <input type="text" name="party_name[]" value="<?php echo esc_attr($party['name']); ?>" placeholder="APC" />
                        <input type="text" name="party_candidate[]" value="<?php echo esc_attr($party['candidate']); ?>" />
                        <div class="rs-media-field">
                            <input id="rs-election-party-logo-<?php echo esc_attr($index); ?>" class="rs-media-url" type="url" name="party_logo_url[]" value="<?php echo esc_attr($party['logo_url']); ?>" />
                            <button type="button" class="button rs-media-picker" data-target="#rs-election-party-logo-<?php echo esc_attr($index); ?>" data-library="image"><?php esc_html_e('Logo', 'rifnote-search'); ?></button>
                        </div>
                        <input type="color" name="party_color[]" value="<?php echo esc_attr($party['color']); ?>" />
                        <input type="number" min="0" name="party_votes[]" value="<?php echo esc_attr($party['votes']); ?>" />
                        <button type="button" class="button rs-election-remove-party"><?php esc_html_e('Remove', 'rifnote-search'); ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <p>
                <button type="button" class="button button-secondary" id="rs-election-add-party"><?php esc_html_e('Add party', 'rifnote-search'); ?></button>
            </p>
            <template id="rs-election-party-template">
                <div class="rs-election-party-row">
                    <input type="text" name="party_name[]" value="" placeholder="<?php esc_attr_e('Party name', 'rifnote-search'); ?>" />
                    <input type="text" name="party_candidate[]" value="" placeholder="<?php esc_attr_e('Candidate name', 'rifnote-search'); ?>" />
                    <div class="rs-media-field">
                        <input class="rs-media-url" type="url" name="party_logo_url[]" value="" placeholder="<?php esc_attr_e('Logo URL', 'rifnote-search'); ?>" />
                        <button type="button" class="button rs-media-picker" data-library="image"><?php esc_html_e('Logo', 'rifnote-search'); ?></button>
                    </div>
                    <input type="color" name="party_color[]" value="#ed1c24" />
                    <input type="number" min="0" name="party_votes[]" value="0" />
                    <button type="button" class="button rs-election-remove-party"><?php esc_html_e('Remove', 'rifnote-search'); ?></button>
                </div>
            </template>
            <p class="description"><?php esc_html_e('Manual party votes are the fallback totals. If the result grid has votes, Rifnote rolls totals up from LGAs or states automatically.', 'rifnote-search'); ?></p>
        </section>

        <?php if ($is_national) : ?>
            <?php self::render_state_result_section($settings); ?>
        <?php else : ?>
            <?php self::render_lga_result_section($settings, $state_lgas); ?>
        <?php endif; ?>
        <?php
    }

    private static function render_lga_result_section($settings, $state_lgas) {
        ?>
        <section class="rs-admin-card">
            <h2><?php echo esc_html(sprintf(__('%s LGA result room', 'rifnote-search'), $settings['state'])); ?></h2>
            <p><?php esc_html_e('Enter verified totals by LGA. Perfect for gubernatorial races and state-level dashboards.', 'rifnote-search'); ?></p>
            <p><input type="search" class="regular-text" id="rs-election-lga-search" placeholder="<?php esc_attr_e('Search LGA...', 'rifnote-search'); ?>" /></p>
            <div class="rs-election-lga-table">
                <div class="rs-election-lga-head" style="grid-template-columns: 1.4fr repeat(<?php echo esc_attr(max(1, count($settings['parties']))); ?>, minmax(90px, 1fr)) 130px 1.4fr;">
                    <span><?php esc_html_e('LGA', 'rifnote-search'); ?></span>
                    <?php foreach ($settings['parties'] as $party) : ?>
                        <span><?php echo esc_html($party['name']); ?></span>
                    <?php endforeach; ?>
                    <span><?php esc_html_e('Status', 'rifnote-search'); ?></span>
                    <span><?php esc_html_e('Note', 'rifnote-search'); ?></span>
                </div>
                <?php foreach ($state_lgas as $lga) : ?>
                    <?php
                    $lga_key = self::lga_key($lga);
                    $row = $settings['lga_results'][$lga_key] ?? array('votes' => array(), 'status' => 'pending', 'note' => '');
                    ?>
                    <div class="rs-election-lga-row" data-lga="<?php echo esc_attr(strtolower($lga)); ?>" style="grid-template-columns: 1.4fr repeat(<?php echo esc_attr(max(1, count($settings['parties']))); ?>, minmax(90px, 1fr)) 130px 1.4fr;">
                        <strong><?php echo esc_html($lga); ?></strong>
                        <?php foreach ($settings['parties'] as $party) : ?>
                            <?php $party_key = $party['key']; ?>
                            <input type="number" min="0" name="lga_results[<?php echo esc_attr($lga_key); ?>][votes][<?php echo esc_attr($party_key); ?>]" value="<?php echo esc_attr(absint($row['votes'][$party_key] ?? 0)); ?>" />
                        <?php endforeach; ?>
                        <select name="lga_results[<?php echo esc_attr($lga_key); ?>][status]">
                            <?php foreach (array('pending' => __('Pending', 'rifnote-search'), 'partial' => __('Partial', 'rifnote-search'), 'reported' => __('Reported', 'rifnote-search')) as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($row['status'] ?? 'pending', $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="lga_results[<?php echo esc_attr($lga_key); ?>][note]" value="<?php echo esc_attr($row['note'] ?? ''); ?>" placeholder="<?php esc_attr_e('Optional note', 'rifnote-search'); ?>" />
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    private static function render_state_result_section($settings) {
        $states = self::states_list();
        ?>
        <section class="rs-admin-card">
            <h2><?php esc_html_e('National state result room', 'rifnote-search'); ?></h2>
            <p><?php esc_html_e('Enter verified totals by state and FCT. This is built for presidential dashboards and national result nights.', 'rifnote-search'); ?></p>
            <p><input type="search" class="regular-text" id="rs-election-lga-search" placeholder="<?php esc_attr_e('Search state...', 'rifnote-search'); ?>" /></p>
            <div class="rs-election-lga-table">
                <div class="rs-election-lga-head" style="grid-template-columns: 1.4fr repeat(<?php echo esc_attr(max(1, count($settings['parties']))); ?>, minmax(90px, 1fr)) 130px 1.4fr;">
                    <span><?php esc_html_e('State', 'rifnote-search'); ?></span>
                    <?php foreach ($settings['parties'] as $party) : ?>
                        <span><?php echo esc_html($party['name']); ?></span>
                    <?php endforeach; ?>
                    <span><?php esc_html_e('Status', 'rifnote-search'); ?></span>
                    <span><?php esc_html_e('Note', 'rifnote-search'); ?></span>
                </div>
                <?php foreach ($states as $state_name) : ?>
                    <?php
                    $state_key = self::state_key($state_name);
                    $row = $settings['state_results'][$state_key] ?? array('votes' => array(), 'status' => 'pending', 'note' => '');
                    ?>
                    <div class="rs-election-lga-row rs-election-state-row" data-lga="<?php echo esc_attr(strtolower($state_name)); ?>" style="grid-template-columns: 1.4fr repeat(<?php echo esc_attr(max(1, count($settings['parties']))); ?>, minmax(90px, 1fr)) 130px 1.4fr;">
                        <strong><?php echo esc_html($state_name); ?></strong>
                        <?php foreach ($settings['parties'] as $party) : ?>
                            <?php $party_key = $party['key']; ?>
                            <input type="number" min="0" name="state_results[<?php echo esc_attr($state_key); ?>][votes][<?php echo esc_attr($party_key); ?>]" value="<?php echo esc_attr(absint($row['votes'][$party_key] ?? 0)); ?>" />
                        <?php endforeach; ?>
                        <select name="state_results[<?php echo esc_attr($state_key); ?>][status]">
                            <?php foreach (array('pending' => __('Pending', 'rifnote-search'), 'partial' => __('Partial', 'rifnote-search'), 'reported' => __('Reported', 'rifnote-search')) as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($row['status'] ?? 'pending', $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="state_results[<?php echo esc_attr($state_key); ?>][note]" value="<?php echo esc_attr($row['note'] ?? ''); ?>" placeholder="<?php esc_attr_e('Optional note', 'rifnote-search'); ?>" />
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    private static function admin_styles_and_scripts() {
        static $printed = false;
        if ($printed) {
            return;
        }
        $printed = true;
        ?>
        <style>
            .rs-election-admin .rs-admin-card{margin:18px 0;padding:22px;max-width:1180px}
            .rs-election-party-table{display:grid;gap:10px;margin-top:14px;max-width:1180px}
            .rs-election-party-head,.rs-election-party-row{display:grid;grid-template-columns:1fr 1.2fr 2fr 90px 120px auto;gap:10px;align-items:center}
            .rs-election-party-head{font-weight:800;color:#667085;text-transform:uppercase;font-size:11px;letter-spacing:.06em}
            .rs-election-party-row{padding:12px;border:1px solid #e6ebf2;border-radius:14px;background:#f8fafc}
            .rs-election-party-row input[type=text],.rs-election-party-row input[type=url],.rs-election-party-row input[type=number]{width:100%}
            .rs-election-lga-table{display:grid;gap:8px;overflow:auto;max-height:680px;padding-bottom:8px}
            .rs-election-lga-head,.rs-election-lga-row{display:grid;gap:8px;align-items:center;min-width:820px}
            .rs-election-lga-head{position:sticky;top:0;z-index:2;padding:10px 12px;border-radius:12px;background:#111827;color:#fff;font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
            .rs-election-lga-row{padding:10px 12px;border:1px solid #e6ebf2;border-radius:12px;background:#fff}
            .rs-election-lga-row input,.rs-election-lga-row select{width:100%}
            @media(max-width:900px){.rs-election-party-head{display:none}.rs-election-party-row{grid-template-columns:1fr}}
        </style>
        <script>
            (function(){
                var table = document.getElementById('rs-election-party-table');
                var addButton = document.getElementById('rs-election-add-party');
                var template = document.getElementById('rs-election-party-template');

                if (!table || !addButton || !template) {
                    return;
                }

                addButton.addEventListener('click', function(){
                    var fragment = template.content.cloneNode(true);
                    var row = fragment.querySelector('.rs-election-party-row');
                    var logoInput = row ? row.querySelector('.rs-media-url') : null;
                    var logoButton = row ? row.querySelector('.rs-media-picker') : null;
                    var id = 'rs-election-party-logo-new-' + Date.now();

                    if (logoInput && logoButton) {
                        logoInput.id = id;
                        logoButton.dataset.target = '#' + id;
                    }

                    table.appendChild(fragment);
                });

                table.addEventListener('click', function(event){
                    var button = event.target.closest('.rs-election-remove-party');
                    if (!button) {
                        return;
                    }

                    var rows = table.querySelectorAll('.rs-election-party-row');
                    var row = button.closest('.rs-election-party-row');
                    if (row && rows.length > 1) {
                        row.remove();
                    } else if (row) {
                        row.querySelectorAll('input').forEach(function(input){
                            input.value = input.type === 'color' ? '#ed1c24' : '';
                        });
                    }
                });

                var lgaSearch = document.getElementById('rs-election-lga-search');
                if (lgaSearch) {
                    lgaSearch.addEventListener('input', function(){
                        var query = lgaSearch.value.toLowerCase().trim();
                        document.querySelectorAll('.rs-election-lga-row').forEach(function(row){
                            row.style.display = !query || row.dataset.lga.indexOf(query) !== -1 ? 'grid' : 'none';
                        });
                    });
                }
            })();
        </script>
        <?php
    }

    private static function sanitize_settings($input) {
        $defaults = self::defaults();
        $settings = array(
            'enabled' => !empty($input['enabled']),
            'mode' => in_array(sanitize_key((string) ($input['mode'] ?? 'auto')), array('auto', 'countdown', 'live'), true) ? sanitize_key((string) $input['mode']) : 'auto',
            'scope' => self::sanitize_scope((string) ($input['scope'] ?? $defaults['scope'])),
            'state' => self::sanitize_state((string) ($input['state'] ?? $defaults['state'])),
            'eyebrow' => sanitize_text_field((string) ($input['eyebrow'] ?? $defaults['eyebrow'])),
            'title' => sanitize_text_field((string) ($input['title'] ?? $defaults['title'])),
            'subtitle' => sanitize_textarea_field((string) ($input['subtitle'] ?? $defaults['subtitle'])),
            'election_datetime' => sanitize_text_field(str_replace('T', ' ', (string) ($input['election_datetime'] ?? $defaults['election_datetime']))),
            'coverage_url' => esc_url_raw((string) ($input['coverage_url'] ?? $defaults['coverage_url'])),
            'media_url' => esc_url_raw((string) ($input['media_url'] ?? '')),
            'media_type' => 'video' === sanitize_key((string) ($input['media_type'] ?? 'image')) ? 'video' : 'image',
            'last_update_label' => sanitize_text_field((string) ($input['last_update_label'] ?? '')),
            'result_note' => sanitize_text_field((string) ($input['result_note'] ?? $defaults['result_note'])),
            'lgas_reporting' => absint($input['lgas_reporting'] ?? 0),
            'lgas_total' => absint($input['lgas_total'] ?? 0),
            'units_reporting' => absint($input['units_reporting'] ?? 0),
            'units_total' => absint($input['units_total'] ?? 0),
            'parties' => array(),
            'lga_results' => array(),
            'state_results' => array(),
        );

        $names = isset($input['party_name']) && is_array($input['party_name']) ? $input['party_name'] : array();
        $candidates = isset($input['party_candidate']) && is_array($input['party_candidate']) ? $input['party_candidate'] : array();
        $logos = isset($input['party_logo_url']) && is_array($input['party_logo_url']) ? $input['party_logo_url'] : array();
        $colors = isset($input['party_color']) && is_array($input['party_color']) ? $input['party_color'] : array();
        $votes = isset($input['party_votes']) && is_array($input['party_votes']) ? $input['party_votes'] : array();

        foreach ($names as $index => $name) {
            $name = sanitize_text_field((string) $name);
            if ('' === $name) {
                continue;
            }

            $settings['parties'][] = array(
                'key' => self::party_key($name),
                'name' => $name,
                'candidate' => sanitize_text_field((string) ($candidates[$index] ?? '')),
                'logo_url' => esc_url_raw((string) ($logos[$index] ?? '')),
                'color' => self::sanitize_color((string) ($colors[$index] ?? '#ed1c24')),
                'votes' => absint($votes[$index] ?? 0),
            );
        }

        if (empty($settings['parties'])) {
            $settings['parties'] = $defaults['parties'];
        }

        $settings['parties'] = self::sanitize_parties($settings['parties']);
        $settings['lga_results'] = self::sanitize_lga_results($input['lga_results'] ?? array(), $settings['parties'], $settings['state']);
        $settings['state_results'] = self::sanitize_state_results($input['state_results'] ?? array(), $settings['parties']);
        $settings['lgas_total'] = count(self::state_lgas($settings['state']));
        $settings['lgas_reporting'] = self::lga_reporting_count(self::public_lga_results($settings['lga_results'], $settings['parties'], $settings['state']));

        return $settings;
    }

    private static function sanitize_scope($scope) {
        $scope = sanitize_key((string) $scope);
        return in_array($scope, array('state', 'national'), true) ? $scope : 'state';
    }

    private static function sanitize_parties($parties) {
        if (!is_array($parties)) {
            return self::defaults()['parties'];
        }

        $clean = array();
        foreach ($parties as $party) {
            if (!is_array($party) || empty($party['name'])) {
                continue;
            }

            $clean[] = array(
                'key' => self::party_key((string) ($party['key'] ?? $party['name'])),
                'name' => sanitize_text_field((string) $party['name']),
                'candidate' => sanitize_text_field((string) ($party['candidate'] ?? '')),
                'logo_url' => esc_url_raw((string) ($party['logo_url'] ?? '')),
                'color' => self::sanitize_color((string) ($party['color'] ?? '#ed1c24')),
                'votes' => absint($party['votes'] ?? 0),
            );
        }

        return $clean ? $clean : self::defaults()['parties'];
    }

    private static function sanitize_state($state) {
        $states = self::nigeria_lgas();
        return isset($states[$state]) ? $state : 'Osun';
    }

    private static function states_list() {
        return array_keys(self::nigeria_lgas());
    }

    private static function state_lgas($state) {
        $states = self::nigeria_lgas();
        return isset($states[$state]) && is_array($states[$state]) ? $states[$state] : array();
    }

    private static function party_key($name) {
        $key = sanitize_title((string) $name);
        return $key ? $key : 'party';
    }

    private static function lga_key($name) {
        $key = sanitize_title((string) $name);
        return $key ? $key : md5((string) $name);
    }

    private static function state_key($name) {
        $key = sanitize_title((string) $name);
        return $key ? $key : md5((string) $name);
    }

    private static function sanitize_lga_results($results, $parties, $state) {
        if (!is_array($results)) {
            return array();
        }

        $party_keys = array_values(array_filter(array_map(function($party) {
            return self::party_key((string) ($party['key'] ?? $party['name'] ?? ''));
        }, $parties)));
        $valid_lgas = array();
        foreach (self::state_lgas($state) as $lga) {
            $valid_lgas[self::lga_key($lga)] = $lga;
        }

        $clean = array();
        foreach ($valid_lgas as $lga_key => $lga_name) {
            $row = isset($results[$lga_key]) && is_array($results[$lga_key]) ? $results[$lga_key] : array();
            $votes = array();
            foreach ($party_keys as $party_key) {
                $votes[$party_key] = absint($row['votes'][$party_key] ?? 0);
            }

            $status = sanitize_key((string) ($row['status'] ?? 'pending'));
            if (!in_array($status, array('pending', 'partial', 'reported'), true)) {
                $status = 'pending';
            }

            if (array_sum($votes) <= 0 && 'pending' === $status && empty($row['note'])) {
                continue;
            }

            $clean[$lga_key] = array(
                'lga' => $lga_name,
                'votes' => $votes,
                'status' => $status,
                'note' => sanitize_text_field((string) ($row['note'] ?? '')),
            );
        }

        return $clean;
    }

    private static function public_lga_results($results, $parties, $state) {
        $clean = self::sanitize_lga_results($results, $parties, $state);
        $party_names = array();
        foreach ($parties as $party) {
            $party_names[self::party_key((string) ($party['key'] ?? $party['name'] ?? ''))] = sanitize_text_field((string) ($party['name'] ?? ''));
        }

        $public = array();
        foreach ($clean as $lga_key => $row) {
            $leader_key = '';
            $leader_votes = -1;
            foreach ($row['votes'] as $party_key => $votes) {
                if ($votes > $leader_votes) {
                    $leader_key = $party_key;
                    $leader_votes = $votes;
                }
            }

            $public[] = array(
                'key' => $lga_key,
                'lga' => $row['lga'],
                'votes' => $row['votes'],
                'status' => $row['status'],
                'note' => $row['note'],
                'leader_key' => $leader_votes > 0 ? $leader_key : '',
                'leader_name' => $leader_votes > 0 ? ($party_names[$leader_key] ?? strtoupper($leader_key)) : '',
                'leader_votes' => max(0, $leader_votes),
                'total_votes' => array_sum($row['votes']),
            );
        }

        usort($public, function($a, $b) {
            if ($a['status'] !== $b['status']) {
                $weight = array('reported' => 0, 'partial' => 1, 'pending' => 2);
                return ($weight[$a['status']] ?? 9) <=> ($weight[$b['status']] ?? 9);
            }

            return strcmp($a['lga'], $b['lga']);
        });

        return $public;
    }

    private static function sanitize_state_results($results, $parties) {
        if (!is_array($results)) {
            return array();
        }

        $party_keys = array_values(array_filter(array_map(function($party) {
            return self::party_key((string) ($party['key'] ?? $party['name'] ?? ''));
        }, $parties)));

        $valid_states = array();
        foreach (self::states_list() as $state_name) {
            $valid_states[self::state_key($state_name)] = $state_name;
        }

        $clean = array();
        foreach ($valid_states as $state_key => $state_name) {
            $row = isset($results[$state_key]) && is_array($results[$state_key]) ? $results[$state_key] : array();
            $votes = array();
            foreach ($party_keys as $party_key) {
                $votes[$party_key] = absint($row['votes'][$party_key] ?? 0);
            }

            $status = sanitize_key((string) ($row['status'] ?? 'pending'));
            if (!in_array($status, array('pending', 'partial', 'reported'), true)) {
                $status = 'pending';
            }

            if (array_sum($votes) <= 0 && 'pending' === $status && empty($row['note'])) {
                continue;
            }

            $clean[$state_key] = array(
                'state' => $state_name,
                'votes' => $votes,
                'status' => $status,
                'note' => sanitize_text_field((string) ($row['note'] ?? '')),
            );
        }

        return $clean;
    }

    private static function public_state_results($results, $parties) {
        $clean = self::sanitize_state_results($results, $parties);
        $party_names = array();
        foreach ($parties as $party) {
            $party_names[self::party_key((string) ($party['key'] ?? $party['name'] ?? ''))] = sanitize_text_field((string) ($party['name'] ?? ''));
        }

        $public = array();
        foreach ($clean as $state_key => $row) {
            $leader_key = '';
            $leader_votes = -1;
            foreach ($row['votes'] as $party_key => $votes) {
                if ($votes > $leader_votes) {
                    $leader_key = $party_key;
                    $leader_votes = $votes;
                }
            }

            $public[] = array(
                'key' => $state_key,
                'state' => $row['state'],
                'votes' => $row['votes'],
                'status' => $row['status'],
                'note' => $row['note'],
                'leader_key' => $leader_votes > 0 ? $leader_key : '',
                'leader_name' => $leader_votes > 0 ? ($party_names[$leader_key] ?? strtoupper($leader_key)) : '',
                'leader_votes' => max(0, $leader_votes),
                'total_votes' => array_sum($row['votes']),
            );
        }

        usort($public, function($a, $b) {
            if ($a['status'] !== $b['status']) {
                $weight = array('reported' => 0, 'partial' => 1, 'pending' => 2);
                return ($weight[$a['status']] ?? 9) <=> ($weight[$b['status']] ?? 9);
            }

            return strcmp($a['state'], $b['state']);
        });

        return $public;
    }

    private static function party_totals_from_lgas($lga_results, $parties) {
        $totals = array();
        foreach ($parties as $party) {
            $totals[self::party_key((string) ($party['key'] ?? $party['name'] ?? ''))] = 0;
        }

        foreach ($lga_results as $row) {
            foreach (($row['votes'] ?? array()) as $party_key => $votes) {
                if (isset($totals[$party_key])) {
                    $totals[$party_key] += absint($votes);
                }
            }
        }

        return $totals;
    }

    private static function party_totals_from_state_results($state_results, $parties) {
        $totals = array();
        foreach ($parties as $party) {
            $totals[self::party_key((string) ($party['key'] ?? $party['name'] ?? ''))] = 0;
        }

        foreach ($state_results as $row) {
            foreach (($row['votes'] ?? array()) as $party_key => $votes) {
                if (isset($totals[$party_key])) {
                    $totals[$party_key] += absint($votes);
                }
            }
        }

        return $totals;
    }

    private static function lga_reporting_count($lga_results) {
        $count = 0;
        foreach ($lga_results as $row) {
            if (in_array($row['status'] ?? 'pending', array('partial', 'reported'), true) || !empty($row['total_votes'])) {
                $count++;
            }
        }

        return $count;
    }

    private static function state_reporting_count($state_results) {
        $count = 0;
        foreach ($state_results as $row) {
            if (in_array($row['status'] ?? 'pending', array('partial', 'reported'), true) || !empty($row['total_votes'])) {
                $count++;
            }
        }

        return $count;
    }

    private static function sanitize_color($value) {
        $color = sanitize_hex_color($value);
        return $color ? $color : '#ed1c24';
    }

    private static function election_timestamp($value) {
        if (!$value) {
            return 0;
        }

        $timestamp = strtotime((string) $value);
        return $timestamp ? $timestamp : 0;
    }
}
