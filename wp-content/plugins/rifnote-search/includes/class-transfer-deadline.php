<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Transfer_Deadline {
    const POST_TYPE = 'rifnote_transfer';
    const MENU_SLUG = 'rifnote-transfer-desk';
    const NONCE = 'rifnote_transfer_details';
    const MODERATION_OPTION = 'rifnote_transfer_rejected_stories';
    const REVIEWED_OPTION = 'rifnote_transfer_reviewed_news';

    public static function init() {
        add_action('init', array(__CLASS__, 'register_post_type'));
        add_action('admin_menu', array(__CLASS__, 'register_menu'), 25);
        add_action('add_meta_boxes_' . self::POST_TYPE, array(__CLASS__, 'add_meta_boxes'));
        add_action('save_post_' . self::POST_TYPE, array(__CLASS__, 'save'), 10, 2);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', array(__CLASS__, 'columns'));
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', array(__CLASS__, 'column_value'), 10, 2);
        add_action('admin_post_rifnote_transfer_moderate', array(__CLASS__, 'moderate_story'));
        add_action('trashed_post', array(__CLASS__, 'transfer_record_changed'));
        add_action('untrashed_post', array(__CLASS__, 'transfer_record_changed'));
        add_action('deleted_post', array(__CLASS__, 'transfer_record_changed'));
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
        $candidates = (array) ($payload['candidates'] ?? array());
        $rejected_stories = array_reverse((array) get_option(self::MODERATION_OPTION, array()), true);
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
            <?php if (isset($_GET['transfer_notice'])) : ?>
                <div class="notice notice-<?php echo 'error' === sanitize_key(wp_unslash($_GET['transfer_notice'])) ? 'error' : 'success'; ?> is-dismissible"><p>
                    <?php echo 'error' === sanitize_key(wp_unslash($_GET['transfer_notice'])) ? esc_html__('The transfer update could not be saved.', 'rifnote-search') : esc_html__('Transfer moderation updated.', 'rifnote-search'); ?>
                </p></div>
            <?php endif; ?>
            <h2><?php esc_html_e('Story moderation queue', 'rifnote-search'); ?></h2>
            <p><?php esc_html_e('Sports RSS candidates are reviewed first. Classification is optional for news; player, origin and destination are only needed when promoting a report into a structured deal card. Removing a candidate only excludes it from the transfer tracker.', 'rifnote-search'); ?></p>
            <?php if (!$candidates) : ?>
                <div class="card" style="padding:18px;max-width:980px;"><p style="margin:0;"><?php esc_html_e('No transfer candidates were discovered in the indexed feeds.', 'rifnote-search'); ?></p></div>
            <?php else : ?>
                <div style="display:grid;gap:14px;max-width:1180px;">
                <?php foreach ($candidates as $candidate) :
                    $story = (array) ($candidate['story'] ?? array());
                    $complete = !empty($candidate['complete']);
                    $reviewed_as_news = !empty($candidate['reviewed_as_news']);
                    $story_id = trim((string) ($story['id'] ?? ''));
                    $post_id = ctype_digit($story_id) && get_post((int) $story_id) ? (int) $story_id : 0;
                    ?>
                    <div class="card" style="padding:16px;margin:0;border-left:4px solid <?php echo $complete ? '#00a32a' : '#d63638'; ?>;">
                        <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;">
                            <div>
                                <strong style="font-size:15px;"><?php echo esc_html($story['headline'] ?? __('Untitled transfer report', 'rifnote-search')); ?></strong>
                                <p style="margin:5px 0;color:#646970;"><?php echo esc_html(($story['source_name'] ?? $story['source_domain'] ?? __('Unknown source', 'rifnote-search')) . ' · ' . ($story['published_at_human'] ?? '')); ?></p>
                            </div>
                            <span style="white-space:nowrap;font-weight:600;color:<?php echo $complete || $reviewed_as_news ? '#008a20' : '#b32d2e'; ?>;"><?php echo $complete ? esc_html__('Ready for deal card', 'rifnote-search') : ($reviewed_as_news ? esc_html__('Approved as news', 'rifnote-search') : esc_html__('Classification optional', 'rifnote-search')); ?></span>
                        </div>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;align-items:end;margin-top:12px;">
                            <input type="hidden" name="action" value="rifnote_transfer_moderate" />
                            <input type="hidden" name="candidate_key" value="<?php echo esc_attr($candidate['moderation_key'] ?? ''); ?>" />
                            <input type="hidden" name="headline" value="<?php echo esc_attr($story['headline'] ?? ''); ?>" />
                            <input type="hidden" name="source_url" value="<?php echo esc_url($story['read_full_story_url'] ?? $story['original_url'] ?? ''); ?>" />
                            <input type="hidden" name="source_name" value="<?php echo esc_attr($story['source_name'] ?? $story['source_domain'] ?? ''); ?>" />
                            <?php wp_nonce_field('rifnote_transfer_moderate', 'rifnote_transfer_moderate_nonce'); ?>
                            <label><span style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e('Player (optional)', 'rifnote-search'); ?></span><input class="regular-text" style="width:100%;" name="player" value="<?php echo esc_attr($candidate['player'] ?? ''); ?>" /></label>
                            <label><span style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e('From club (optional)', 'rifnote-search'); ?></span><input class="regular-text" style="width:100%;" name="from_club" value="<?php echo esc_attr($candidate['from_club'] ?? ''); ?>" /></label>
                            <label><span style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e('Destination club (optional)', 'rifnote-search'); ?></span><input class="regular-text" style="width:100%;" name="to_club" value="<?php echo esc_attr($candidate['to_club'] ?? ''); ?>" /></label>
                            <label><span style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e('Classification', 'rifnote-search'); ?></span><select name="status" style="width:100%;"><?php foreach (self::fields()['status']['options'] as $status => $label) : ?><option value="<?php echo esc_attr($status); ?>" <?php selected($candidate['status'] ?? 'reported', $status); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
                            <label><span style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e('Transfer type', 'rifnote-search'); ?></span><select name="transfer_type" style="width:100%;"><?php foreach (self::fields()['transfer_type']['options'] as $type => $label) : ?><option value="<?php echo esc_attr($type); ?>" <?php selected($candidate['transfer_type'] ?? 'permanent', $type); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
                            <label><span style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e('Fee', 'rifnote-search'); ?></span><input class="regular-text" style="width:100%;" name="fee" value="<?php echo esc_attr($candidate['fee'] ?? ''); ?>" placeholder="£45m / undisclosed" /></label>
                            <div style="display:flex;gap:7px;flex-wrap:wrap;">
                                <button class="button button-primary" name="moderation_action" value="approve"><?php echo $complete ? esc_html__('Approve deal', 'rifnote-search') : esc_html__('Approve as news', 'rifnote-search'); ?></button>
                                <button class="button" name="moderation_action" value="reject" formnovalidate><?php esc_html_e('Remove', 'rifnote-search'); ?></button>
                                <?php if ($post_id && get_edit_post_link($post_id)) : ?><a class="button" href="<?php echo esc_url(get_edit_post_link($post_id)); ?>"><?php esc_html_e('Edit story', 'rifnote-search'); ?></a><?php endif; ?>
                                <?php if (!empty($story['read_full_story_url']) || !empty($story['original_url'])) : ?><a class="button" target="_blank" rel="noreferrer" href="<?php echo esc_url($story['read_full_story_url'] ?? $story['original_url']); ?>"><?php esc_html_e('Open source', 'rifnote-search'); ?></a><?php endif; ?>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($rejected_stories) : ?>
                <details style="margin-top:20px;max-width:1180px;">
                    <summary style="cursor:pointer;font-weight:600;"><?php echo esc_html(sprintf(__('Removed from tracker (%d)', 'rifnote-search'), count($rejected_stories))); ?></summary>
                    <div class="card" style="padding:0 16px;margin-top:10px;">
                        <?php foreach ($rejected_stories as $key => $removed) : $removed = is_array($removed) ? $removed : array(); ?>
                            <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;padding:12px 0;border-bottom:1px solid #dcdcde;">
                                <div><strong><?php echo esc_html($removed['headline'] ?? $key); ?></strong><?php if (!empty($removed['source_name'])) : ?><br /><small><?php echo esc_html($removed['source_name']); ?></small><?php endif; ?></div>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="rifnote_transfer_moderate" /><input type="hidden" name="moderation_action" value="restore" /><input type="hidden" name="candidate_key" value="<?php echo esc_attr($key); ?>" />
                                    <?php wp_nonce_field('rifnote_transfer_moderate', 'rifnote_transfer_moderate_nonce'); ?>
                                    <button class="button"><?php esc_html_e('Restore', 'rifnote-search'); ?></button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
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
        self::invalidate_cache();
    }

    private static function invalidate_cache() {
        if (class_exists('Rifnote_Search_Football_API')) Rifnote_Search_Football_API::invalidate_transfer_cache();
    }

    public static function transfer_record_changed($post_id) {
        if (self::POST_TYPE === get_post_type($post_id)) self::invalidate_cache();
    }

    public static function moderation_key($story) {
        $identity = (string) ($story['id'] ?? $story['canonical_url'] ?? $story['original_url'] ?? $story['headline'] ?? '');
        return md5(strtolower(trim($identity)));
    }

    public static function is_rejected_story($story) {
        $rejected = (array) get_option(self::MODERATION_OPTION, array());
        return isset($rejected[self::moderation_key($story)]);
    }

    public static function is_reviewed_story($story) {
        $reviewed = (array) get_option(self::REVIEWED_OPTION, array());
        return isset($reviewed[self::moderation_key($story)]);
    }

    public static function moderate_story() {
        if (!current_user_can('edit_posts') || !isset($_POST['rifnote_transfer_moderate_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_transfer_moderate_nonce'])), 'rifnote_transfer_moderate')) {
            wp_die(esc_html__('You are not allowed to moderate transfer stories.', 'rifnote-search'));
        }

        $action = sanitize_key(wp_unslash($_POST['moderation_action'] ?? ''));
        $candidate_key = sanitize_text_field(wp_unslash($_POST['candidate_key'] ?? ''));
        $rejected = (array) get_option(self::MODERATION_OPTION, array());
        $reviewed = (array) get_option(self::REVIEWED_OPTION, array());

        if ('restore' === $action) {
            unset($rejected[$candidate_key]);
            update_option(self::MODERATION_OPTION, $rejected, false);
            self::invalidate_cache();
            wp_safe_redirect(add_query_arg('transfer_notice', 'success', admin_url('admin.php?page=' . self::MENU_SLUG)));
            exit;
        }

        if ('reject' === $action) {
            if ($candidate_key) $rejected[$candidate_key] = array(
                'headline' => sanitize_text_field(wp_unslash($_POST['headline'] ?? '')),
                'source_name' => sanitize_text_field(wp_unslash($_POST['source_name'] ?? '')),
                'removed_at' => time(),
            );
            if (count($rejected) > 1000) $rejected = array_slice($rejected, -1000, null, true);
            update_option(self::MODERATION_OPTION, $rejected, false);
            unset($reviewed[$candidate_key]);
            update_option(self::REVIEWED_OPTION, $reviewed, false);
            self::invalidate_cache();
            wp_safe_redirect(add_query_arg('transfer_notice', 'success', admin_url('admin.php?page=' . self::MENU_SLUG)));
            exit;
        }

        $player = sanitize_text_field(wp_unslash($_POST['player'] ?? ''));
        $from_club = sanitize_text_field(wp_unslash($_POST['from_club'] ?? ''));
        $to_club = sanitize_text_field(wp_unslash($_POST['to_club'] ?? ''));
        if (!$player || !$from_club || !$to_club) {
            unset($rejected[$candidate_key]);
            update_option(self::MODERATION_OPTION, $rejected, false);
            if ($candidate_key) $reviewed[$candidate_key] = time();
            if (count($reviewed) > 1000) $reviewed = array_slice($reviewed, -1000, null, true);
            update_option(self::REVIEWED_OPTION, $reviewed, false);
            self::invalidate_cache();
            wp_safe_redirect(add_query_arg('transfer_notice', 'success', admin_url('admin.php?page=' . self::MENU_SLUG)));
            exit;
        }

        unset($rejected[$candidate_key]);
        update_option(self::MODERATION_OPTION, $rejected, false);
        unset($reviewed[$candidate_key]);
        update_option(self::REVIEWED_OPTION, $reviewed, false);
        $headline = sanitize_text_field(wp_unslash($_POST['headline'] ?? '')) ?: sprintf(__('%1$s: %2$s to %3$s', 'rifnote-search'), $player, $from_club, $to_club);
        $existing = get_posts(array('post_type' => self::POST_TYPE, 'post_status' => array('publish', 'draft'), 'meta_key' => '_rifnote_transfer_origin_key', 'meta_value' => $candidate_key, 'posts_per_page' => 1));
        $post_id = wp_insert_post(array(
            'ID' => $existing ? $existing[0]->ID : 0,
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $headline,
            'post_content' => sprintf(__('Approved from indexed coverage: %s', 'rifnote-search'), esc_url_raw(wp_unslash($_POST['source_url'] ?? ''))),
        ));
        if (!is_wp_error($post_id)) {
            $status = sanitize_key(wp_unslash($_POST['status'] ?? 'reported'));
            $type = sanitize_key(wp_unslash($_POST['transfer_type'] ?? 'permanent'));
            if (!isset(self::fields()['status']['options'][$status])) $status = 'reported';
            if (!isset(self::fields()['transfer_type']['options'][$type])) $type = 'permanent';
            $values = array(
                'player' => $player, 'from_club' => $from_club, 'to_club' => $to_club,
                'status' => $status,
                'source_url' => esc_url_raw(wp_unslash($_POST['source_url'] ?? '')),
                'source_name' => sanitize_text_field(wp_unslash($_POST['source_name'] ?? '')),
                'transfer_type' => $type,
                'fee' => sanitize_text_field(wp_unslash($_POST['fee'] ?? '')),
            );
            foreach ($values as $key => $value) update_post_meta($post_id, '_rifnote_transfer_' . $key, $value);
            update_post_meta($post_id, '_rifnote_transfer_origin_key', $candidate_key);
        }
        self::invalidate_cache();
        wp_safe_redirect(add_query_arg('transfer_notice', 'success', admin_url('admin.php?page=' . self::MENU_SLUG)));
        exit;
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
        $deals = array_map(function ($post) {
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
        return array_values($deals);
    }
}
