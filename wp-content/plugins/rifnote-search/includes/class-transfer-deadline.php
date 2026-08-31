<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Transfer_Deadline {
    const POST_TYPE = 'rifnote_transfer';
    const MENU_SLUG = 'rifnote-transfer-desk';
    const NONCE = 'rifnote_transfer_details';

    public static function init() {
        add_action('init', array(__CLASS__, 'register_post_type'));
        add_action('admin_menu', array(__CLASS__, 'register_menu'), 25);
        add_action('add_meta_boxes_' . self::POST_TYPE, array(__CLASS__, 'add_meta_boxes'));
        add_action('save_post_' . self::POST_TYPE, array(__CLASS__, 'save'), 10, 2);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', array(__CLASS__, 'columns'));
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', array(__CLASS__, 'column_value'), 10, 2);
    }

    public static function register_post_type() {
        register_post_type(self::POST_TYPE, array(
            'labels' => array(
                'name' => __('Manual Transfers', 'rifnote-search'),
                'singular_name' => __('Transfer', 'rifnote-search'),
                'add_new_item' => __('Add Transfer Update', 'rifnote-search'),
                'edit_item' => __('Edit Transfer Details', 'rifnote-search'),
                'menu_name' => __('Manual Updates', 'rifnote-search'),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => self::MENU_SLUG,
            'supports' => array('title', 'editor', 'thumbnail'),
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'menu_icon' => 'dashicons-update',
        ));
    }

    public static function register_menu() {
        add_menu_page(
            __('Transfer Deadline Desk', 'rifnote-search'),
            __('Transfers', 'rifnote-search'),
            'edit_posts',
            self::MENU_SLUG,
            array(__CLASS__, 'render_dashboard'),
            'dashicons-randomize',
            27
        );
        add_submenu_page(self::MENU_SLUG, __('Transfer Overview', 'rifnote-search'), __('Overview', 'rifnote-search'), 'edit_posts', self::MENU_SLUG, array(__CLASS__, 'render_dashboard'));
        add_submenu_page(self::MENU_SLUG, __('Add Transfer Update', 'rifnote-search'), __('Add Manual Update', 'rifnote-search'), 'edit_posts', 'post-new.php?post_type=' . self::POST_TYPE);
        add_submenu_page(self::MENU_SLUG, __('Deadline Settings', 'rifnote-search'), __('Settings', 'rifnote-search'), 'manage_options', 'rifnote-search-football');
    }

    public static function render_dashboard() {
        if (!current_user_can('edit_posts')) return;
        $manual = wp_count_posts(self::POST_TYPE);
        $payload = class_exists('Rifnote_Search_Football_API') ? Rifnote_Search_Football_API::transfer_news_payload(60) : array();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Transfer Deadline Desk', 'rifnote-search'); ?></h1>
            <p><?php esc_html_e('RSS and WordPress reports flow in automatically. Use manual updates only to add an exclusive, correct a deal, or publish information that has no source story yet.', 'rifnote-search'); ?></p>
            <div style="display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:14px;max-width:980px;margin:20px 0;">
                <?php
                $cards = array(
                    array(__('Tracked deals', 'rifnote-search'), count($payload['deals'] ?? array())),
                    array(__('Confirmed', 'rifnote-search'), (int) ($payload['confirmed_count'] ?? 0)),
                    array(__('Needs review', 'rifnote-search'), (int) ($payload['exceptions_count'] ?? 0)),
                    array(__('Manual updates', 'rifnote-search'), (int) ($manual->publish ?? 0)),
                );
                foreach ($cards as $card) : ?>
                    <div class="card" style="padding:18px;margin:0;"><strong style="display:block;font-size:30px;"><?php echo esc_html($card[1]); ?></strong><span><?php echo esc_html($card[0]); ?></span></div>
                <?php endforeach; ?>
            </div>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . self::POST_TYPE)); ?>"><?php esc_html_e('Add manual transfer update', 'rifnote-search'); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=' . self::POST_TYPE)); ?>"><?php esc_html_e('Manage manual updates', 'rifnote-search'); ?></a>
                <a class="button" href="<?php echo esc_url(home_url('/transfers/')); ?>" target="_blank" rel="noreferrer"><?php esc_html_e('View public tracker', 'rifnote-search'); ?></a>
            </p>
        </div>
        <?php
    }

    public static function add_meta_boxes() {
        add_meta_box('rifnote-transfer-details', __('Transfer Details', 'rifnote-search'), array(__CLASS__, 'render_details'), self::POST_TYPE, 'normal', 'high');
    }

    public static function render_details($post) {
        wp_nonce_field(self::NONCE, self::NONCE);
        $fields = self::fields();
        echo '<table class="form-table"><tbody>';
        foreach ($fields as $key => $field) {
            $value = get_post_meta($post->ID, '_rifnote_transfer_' . $key, true);
            echo '<tr><th><label for="rifnote_transfer_' . esc_attr($key) . '">' . esc_html($field['label']) . '</label></th><td>';
            if ('select' === $field['type']) {
                echo '<select id="rifnote_transfer_' . esc_attr($key) . '" name="rifnote_transfer[' . esc_attr($key) . ']">';
                foreach ($field['options'] as $option => $label) echo '<option value="' . esc_attr($option) . '" ' . selected($value, $option, false) . '>' . esc_html($label) . '</option>';
                echo '</select>';
            } else {
                echo '<input id="rifnote_transfer_' . esc_attr($key) . '" class="regular-text" type="' . esc_attr($field['type']) . '" name="rifnote_transfer[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" />';
            }
            if (!empty($field['description'])) echo '<p class="description">' . esc_html($field['description']) . '</p>';
            echo '</td></tr>';
        }
        echo '</tbody></table><p class="description">' . esc_html__('Use the main editor for a detailed update, context, or correction. The featured image becomes the public transfer artwork.', 'rifnote-search') . '</p>';
    }

    private static function fields() {
        return array(
            'player' => array('label' => __('Player', 'rifnote-search'), 'type' => 'text'),
            'from_club' => array('label' => __('From club', 'rifnote-search'), 'type' => 'text'),
            'to_club' => array('label' => __('To club', 'rifnote-search'), 'type' => 'text'),
            'status' => array('label' => __('Status', 'rifnote-search'), 'type' => 'select', 'options' => array('reported' => __('Reported', 'rifnote-search'), 'agreement' => __('Agreement', 'rifnote-search'), 'strongly-reported' => __('Strongly reported', 'rifnote-search'), 'medical' => __('Medical', 'rifnote-search'), 'confirmed' => __('Confirmed', 'rifnote-search'), 'off' => __('Off', 'rifnote-search'), 'disputed' => __('Disputed', 'rifnote-search'))),
            'fee' => array('label' => __('Fee', 'rifnote-search'), 'type' => 'text', 'description' => __('Examples: £45m, loan fee, undisclosed.', 'rifnote-search')),
            'transfer_type' => array('label' => __('Transfer type', 'rifnote-search'), 'type' => 'select', 'options' => array('permanent' => __('Permanent', 'rifnote-search'), 'loan' => __('Loan', 'rifnote-search'), 'free' => __('Free transfer', 'rifnote-search'))),
            'source_url' => array('label' => __('Source URL', 'rifnote-search'), 'type' => 'url'),
            'source_name' => array('label' => __('Source name', 'rifnote-search'), 'type' => 'text'),
        );
    }

    public static function save($post_id, $post) {
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE])), self::NONCE) || wp_is_post_revision($post_id) || !current_user_can('edit_post', $post_id)) return;
        $input = isset($_POST['rifnote_transfer']) && is_array($_POST['rifnote_transfer']) ? wp_unslash($_POST['rifnote_transfer']) : array();
        foreach (self::fields() as $key => $field) {
            $value = $input[$key] ?? '';
            $value = 'url' === $field['type'] ? esc_url_raw($value) : sanitize_text_field($value);
            update_post_meta($post_id, '_rifnote_transfer_' . $key, $value);
        }
    }

    public static function columns($columns) {
        return array('cb' => $columns['cb'], 'title' => __('Update title', 'rifnote-search'), 'player' => __('Player', 'rifnote-search'), 'route' => __('Route', 'rifnote-search'), 'status' => __('Status', 'rifnote-search'), 'date' => $columns['date']);
    }

    public static function column_value($column, $post_id) {
        if ('player' === $column) echo esc_html(get_post_meta($post_id, '_rifnote_transfer_player', true));
        if ('route' === $column) echo esc_html(trim(get_post_meta($post_id, '_rifnote_transfer_from_club', true) . ' → ' . get_post_meta($post_id, '_rifnote_transfer_to_club', true), ' →'));
        if ('status' === $column) echo esc_html(ucwords(str_replace('-', ' ', get_post_meta($post_id, '_rifnote_transfer_status', true))));
    }

    public static function public_deals() {
        $posts = get_posts(array('post_type' => self::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => 'modified', 'order' => 'DESC'));
        return array_map(function ($post) {
            $meta = function ($key) use ($post) { return sanitize_text_field((string) get_post_meta($post->ID, '_rifnote_transfer_' . $key, true)); };
            $player = $meta('player') ?: get_the_title($post);
            $status = sanitize_key($meta('status') ?: 'reported');
            $source_url = esc_url_raw((string) get_post_meta($post->ID, '_rifnote_transfer_source_url', true));
            return array(
                'player' => $player,
                'from_club' => $meta('from_club'),
                'to_club' => $meta('to_club'),
                'fee' => $meta('fee'),
                'transfer_type' => sanitize_key($meta('transfer_type') ?: 'permanent'),
                'status' => $status,
                'status_label' => ucwords(str_replace('-', ' ', $status)),
                'confidence' => 1,
                'official' => true,
                'manual' => true,
                'needs_review' => false,
                'source_count' => $source_url ? 1 : 0,
                'story_count' => 1,
                'supporting_sources' => array_values(array_filter(array($meta('source_name')))),
                'details' => wp_kses_post(apply_filters('the_content', $post->post_content)),
                'latest_story' => array(
                    'id' => 'manual_' . $post->ID,
                    'headline' => get_the_title($post),
                    'excerpt' => wp_trim_words(wp_strip_all_tags($post->post_content), 32, '…'),
                    'image' => get_the_post_thumbnail_url($post, 'large') ?: '',
                    'published_at' => mysql_to_rfc3339($post->post_modified_gmt),
                    'source_name' => $meta('source_name') ?: __('Rifnote Transfer Desk', 'rifnote-search'),
                    'source_domain' => $source_url ? Rifnote_Search_Source_Meta::source_domain($source_url) : '',
                    'original_url' => $source_url ?: home_url('/transfers/'),
                    'read_full_story_url' => $source_url ?: home_url('/transfers/'),
                ),
            );
        }, $posts);
    }
}
