<?php

if (!defined('ABSPATH')) exit;

class Rifnote_Search_Story_Channels {
    const MENU_SLUG = 'rifnote-story-channels';
    const SETTINGS_GROUP = 'rifnote_story_channels';
    private static $cache_invalidated = false;

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_menu'), 26);
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('updated_option', array(__CLASS__, 'option_updated'), 10, 3);
        add_action('save_post_post', array(__CLASS__, 'content_changed'), 40);
        add_action('deleted_post', array(__CLASS__, 'content_changed'));
    }

    public static function register_menu() {
        add_menu_page(__('Story Channels', 'rifnote-search'), __('Story Channels', 'rifnote-search'), 'manage_options', self::MENU_SLUG, array(__CLASS__, 'render_admin'), 'dashicons-filter', 28);
        add_submenu_page(self::MENU_SLUG, __('Trending Topics & Football Stories', 'rifnote-search'), __('Channels', 'rifnote-search'), 'manage_options', self::MENU_SLUG, array(__CLASS__, 'render_admin'));
        add_submenu_page(self::MENU_SLUG, __('Add Story', 'rifnote-search'), __('Add Manual Story', 'rifnote-search'), 'manage_options', 'post-new.php');
    }

    public static function register_settings() {
        $text = array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_list'), 'default' => '');
        $integer = array('type' => 'integer', 'sanitize_callback' => function ($value) { return max(6, min(100, absint($value))); }, 'default' => 30);
        foreach (array(
            'rifnote_channel_trending_topics', 'rifnote_channel_trending_tags', 'rifnote_channel_trending_keywords',
            'rifnote_channel_football_leagues', 'rifnote_channel_football_clubs', 'rifnote_channel_football_players', 'rifnote_channel_football_keywords',
        ) as $option) register_setting(self::SETTINGS_GROUP, $option, $text);
        foreach (array('rifnote_channel_home_title', 'rifnote_channel_home_subtitle', 'rifnote_channel_home_eyebrow') as $option) {
            register_setting(self::SETTINGS_GROUP, $option, array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''));
        }
        register_setting(self::SETTINGS_GROUP, 'rifnote_channel_home_image_url', array('type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''));
        register_setting(self::SETTINGS_GROUP, 'rifnote_channel_trending_categories', array('type' => 'array', 'sanitize_callback' => array(__CLASS__, 'sanitize_ids'), 'default' => array()));
        register_setting(self::SETTINGS_GROUP, 'rifnote_channel_trending_enabled', array('type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true));
        register_setting(self::SETTINGS_GROUP, 'rifnote_channel_football_enabled', array('type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true));
        register_setting(self::SETTINGS_GROUP, 'rifnote_channel_trending_limit', $integer);
        register_setting(self::SETTINGS_GROUP, 'rifnote_channel_football_limit', $integer);
        register_setting(self::SETTINGS_GROUP, 'rifnote_channel_cache_ttl', array('type' => 'integer', 'sanitize_callback' => function ($value) { return max(60, min(1800, absint($value))); }, 'default' => 180));
    }

    public static function sanitize_list($value) {
        $items = self::list_items($value);
        return implode("\n", array_slice($items, 0, 100));
    }

    public static function sanitize_ids($value) {
        return array_values(array_unique(array_filter(array_map('absint', (array) $value))));
    }

    private static function list_items($value) {
        $parts = is_array($value) ? $value : preg_split('/[\r\n,]+/', (string) $value);
        return array_values(array_unique(array_filter(array_map('sanitize_text_field', $parts))));
    }

    public static function option_updated($option, $old_value, $value) {
        if (0 === strpos((string) $option, 'rifnote_channel_')) self::invalidate_cache();
    }

    public static function content_changed($post_id) {
        if ('post' === get_post_type($post_id)) self::invalidate_cache();
    }

    public static function invalidate_cache() {
        if (self::$cache_invalidated) return;
        self::$cache_invalidated = true;
        update_option('rifnote_story_channel_generation', max(1, (int) get_option('rifnote_story_channel_generation', 1)) + 1, false);
    }

    public static function config($channel) {
        if ('football' === $channel) {
            return array(
                'enabled' => (bool) get_option('rifnote_channel_football_enabled', true),
                'label' => __('Football Stories', 'rifnote-search'),
                'description' => __('Coverage matched to the leagues, clubs and players selected by the Rifnote desk.', 'rifnote-search'),
                'terms' => array_values(array_unique(array_merge(
                    self::list_items(get_option('rifnote_channel_football_leagues', '')),
                    self::list_items(get_option('rifnote_channel_football_clubs', '')),
                    self::list_items(get_option('rifnote_channel_football_players', '')),
                    self::list_items(get_option('rifnote_channel_football_keywords', ''))
                ))),
                'categories' => array('Football', 'Sport'),
                'limit' => max(6, min(100, (int) get_option('rifnote_channel_football_limit', 30))),
            );
        }

        $category_ids = self::sanitize_ids(get_option('rifnote_channel_trending_categories', array()));
        $categories = array_values(array_filter(array_map(function ($id) {
            $term = get_term($id, 'category');
            return $term && !is_wp_error($term) ? $term->name : '';
        }, $category_ids)));
        return array(
            'enabled' => (bool) get_option('rifnote_channel_trending_enabled', true),
            'label' => __('Trending Topics', 'rifnote-search'),
            'description' => __('Stories matched to topics, tags, categories and keywords selected by the Rifnote desk.', 'rifnote-search'),
            'terms' => array_values(array_unique(array_merge(
                self::list_items(get_option('rifnote_channel_trending_topics', '')),
                self::list_items(get_option('rifnote_channel_trending_tags', '')),
                self::list_items(get_option('rifnote_channel_trending_keywords', ''))
            ))),
            'categories' => $categories,
            'limit' => max(6, min(100, (int) get_option('rifnote_channel_trending_limit', 30))),
        );
    }

    public static function payload($channel, $requested_limit = 0, $context_terms = array()) {
        $channel = 'football' === sanitize_key($channel) ? 'football' : 'trending';
        $config = self::config($channel);
        $context_terms = array_slice(array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) $context_terms)))), 0, 8);
        if ('football' === $channel && $context_terms) $config['terms'] = array_values(array_unique(array_merge($context_terms, $config['terms'])));
        $limit = $requested_limit ? max(1, min(100, (int) $requested_limit)) : $config['limit'];
        $generation = max(1, (int) get_option('rifnote_story_channel_generation', 1));
        $cache_key = 'rifnote_story_channel_' . $generation . '_' . $channel . '_' . $limit . '_' . substr(md5(implode('|', $context_terms)), 0, 10);
        $cached = get_transient($cache_key);
        if (is_array($cached)) return $cached;

        $stories = array();
        $collect = function ($items) use (&$stories, $config, $channel) {
            foreach ((array) $items as $story) {
                if (!is_array($story) || empty($story['headline'])) continue;
                $text = strtolower(wp_strip_all_tags(($story['headline'] ?? '') . ' ' . ($story['excerpt'] ?? '') . ' ' . implode(' ', (array) ($story['tags'] ?? array()))));
                $matches = 0;
                foreach ($config['terms'] as $term) if (false !== strpos($text, strtolower($term))) $matches++;
                $story_categories = array_filter(array_map('strtolower', array_merge(
                    array((string) ($story['category'] ?? ''), (string) ($story['category_slug'] ?? '')),
                    (array) ($story['categories'] ?? array())
                )));
                $category_matches = 0;
                foreach ($config['categories'] as $category) {
                    $needle = strtolower((string) $category);
                    foreach ($story_categories as $story_category) {
                        if ($needle && ($needle === $story_category || sanitize_title($needle) === sanitize_title($story_category))) $category_matches++;
                    }
                }
                $matches += $category_matches;
                if (($config['terms'] || $config['categories']) && !$matches) continue;
                unset($story['admin_edit_url'], $story['admin_delete_url']);
                $identity = (string) ($story['canonical_url'] ?? $story['original_url'] ?? $story['id'] ?? $story['headline']);
                $key = md5(strtolower($identity));
                $story['channel_matches'] = $matches;
                if (!isset($stories[$key]) || $matches > (int) ($stories[$key]['channel_matches'] ?? 0)) $stories[$key] = $story;
            }
        };

        // Always collect published WordPress posts directly. This ensures stories
        // written manually by admins participate even without an RSS/external URL.
        if ($config['enabled'] && class_exists('Rifnote_Search_Engine')) {
            $manual_posts = get_posts(array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => max(100, $limit * 3),
                'orderby' => 'date',
                'order' => 'DESC',
                'date_query' => array(array('after' => '30 days ago')),
                'no_found_rows' => true,
            ));
            $manual_stories = array_filter(array_map(function ($post) {
                $story = Rifnote_Search_Engine::result_payload($post->ID, array('query' => '', 'category' => '', 'sort' => 'latest', 'date_range' => '30d'));
                if (is_array($story)) $story['channel_source'] = 'wordpress';
                return $story;
            }, $manual_posts));
            $collect($manual_stories);
        }

        if ($config['enabled'] && class_exists('Rifnote_Search_Engine')) {
            foreach ($config['categories'] as $category) {
                $search = Rifnote_Search_Engine::payload(array('query' => '', 'category' => $category, 'sort' => 'latest', 'date_range' => '30d', 'include_warehouse' => true), 1, 80);
                $collect($search['results'] ?? array());
                if (class_exists('Rifnote_Search_Data_API')) $collect(Rifnote_Search_Data_API::recent_story_payload($category, 50));
            }
            foreach (array_slice($config['terms'], 0, 30) as $term) {
                $search = Rifnote_Search_Engine::payload(array('query' => $term, 'category' => '', 'sort' => 'latest', 'date_range' => '30d', 'include_warehouse' => true), 1, 40);
                $collect($search['results'] ?? array());
            }
        }

        $stories = array_values($stories);
        usort($stories, function ($a, $b) {
            $matched = (int) ($b['channel_matches'] ?? 0) <=> (int) ($a['channel_matches'] ?? 0);
            return $matched ?: (strtotime($b['published_at'] ?? '') <=> strtotime($a['published_at'] ?? ''));
        });
        $result = array(
            'channel' => $channel, 'enabled' => $config['enabled'], 'label' => $config['label'], 'description' => $config['description'],
            'stories' => array_slice($stories, 0, $limit), 'total' => count($stories), 'terms' => $config['terms'], 'categories' => $config['categories'],
            'updated_at' => gmdate(DATE_ATOM),
        );
        set_transient($cache_key, $result, max(60, min(1800, (int) get_option('rifnote_channel_cache_ttl', 180))));
        return $result;
    }

    public static function render_admin() {
        if (!current_user_can('manage_options')) return;
        $categories = get_categories(array('hide_empty' => false));
        $selected_categories = self::sanitize_ids(get_option('rifnote_channel_trending_categories', array()));
        $teams = class_exists('Rifnote_Search_Football_API') ? Rifnote_Search_Football_API::stored_teams(300) : array();
        $leagues = array_values(array_unique(array_filter(array_map(function ($team) { return $team['league_name'] ?? ''; }, $teams))));
        $clubs = array_values(array_unique(array_filter(array_map(function ($team) { return $team['team_name'] ?? ''; }, $teams))));
        $selected_leagues = self::list_items(get_option('rifnote_channel_football_leagues', ''));
        $selected_clubs = self::list_items(get_option('rifnote_channel_football_clubs', ''));
        $trending = self::payload('trending', 10);
        $football = self::payload('football', 10);
        ?>
        <div class="wrap"><h1><?php esc_html_e('Trending Topics & Football Stories', 'rifnote-search'); ?></h1>
        <p><?php esc_html_e('Build automatic editorial channels from PostgreSQL RSS stories, WordPress posts and manual updates. Saved selections immediately control the public feeds.', 'rifnote-search'); ?></p>
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;max-width:1100px;margin:18px 0;">
            <div class="card" style="padding:18px;margin:0;"><strong style="font-size:28px;display:block;"><?php echo esc_html((int) ($trending['total'] ?? 0)); ?></strong><?php esc_html_e('Trending matches', 'rifnote-search'); ?><p><a href="<?php echo esc_url(home_url('/trending-topics/')); ?>" target="_blank"><?php esc_html_e('View public page', 'rifnote-search'); ?></a></p></div>
            <div class="card" style="padding:18px;margin:0;"><strong style="font-size:28px;display:block;"><?php echo esc_html((int) ($football['total'] ?? 0)); ?></strong><?php esc_html_e('Football story matches', 'rifnote-search'); ?><p><a href="<?php echo esc_url(home_url('/football-stories/')); ?>" target="_blank"><?php esc_html_e('View public page', 'rifnote-search'); ?></a></p></div>
        </div>
        <form method="post" action="options.php" style="max-width:1100px;"><?php settings_fields(self::SETTINGS_GROUP); ?>
            <div class="card" style="padding:20px;margin-bottom:18px;"><h2><?php esc_html_e('Homepage section', 'rifnote-search'); ?></h2>
                <p><?php esc_html_e('Control the single homepage takeover used to introduce Trending Topics and Football Stories. Individual stories remain on their dedicated pages, not in a homepage carousel.', 'rifnote-search'); ?></p>
                <table class="form-table">
                    <tr><th><label for="rifnote_channel_home_eyebrow"><?php esc_html_e('Eyebrow', 'rifnote-search'); ?></label></th><td><input id="rifnote_channel_home_eyebrow" class="regular-text" type="text" name="rifnote_channel_home_eyebrow" value="<?php echo esc_attr(get_option('rifnote_channel_home_eyebrow', 'Live story desk')); ?>" /></td></tr>
                    <tr><th><label for="rifnote_channel_home_title"><?php esc_html_e('Title', 'rifnote-search'); ?></label></th><td><input id="rifnote_channel_home_title" class="large-text" type="text" name="rifnote_channel_home_title" value="<?php echo esc_attr(get_option('rifnote_channel_home_title', 'Follow what is happening now')); ?>" /></td></tr>
                    <tr><th><label for="rifnote_channel_home_subtitle"><?php esc_html_e('Description', 'rifnote-search'); ?></label></th><td><textarea id="rifnote_channel_home_subtitle" class="large-text" rows="3" name="rifnote_channel_home_subtitle"><?php echo esc_textarea(get_option('rifnote_channel_home_subtitle', 'Trending topics and football coverage, organised from trusted stories as they develop.')); ?></textarea></td></tr>
                    <tr><th><label for="rifnote_channel_home_image_url"><?php esc_html_e('Background image', 'rifnote-search'); ?></label></th><td><div class="rs-media-field"><input id="rifnote_channel_home_image_url" class="large-text rs-media-url" type="url" name="rifnote_channel_home_image_url" value="<?php echo esc_attr(get_option('rifnote_channel_home_image_url', '')); ?>" placeholder="https://..." /><p><button type="button" class="button rs-media-picker" data-target="#rifnote_channel_home_image_url" data-library="image" data-title="<?php esc_attr_e('Choose homepage story section image', 'rifnote-search'); ?>" data-button="<?php esc_attr_e('Use image', 'rifnote-search'); ?>"><?php esc_html_e('Choose from Media Library', 'rifnote-search'); ?></button> <button type="button" class="button rs-media-clear" data-target="#rifnote_channel_home_image_url"><?php esc_html_e('Clear', 'rifnote-search'); ?></button></p></div></td></tr>
                </table>
            </div>
            <div class="card" style="padding:20px;margin-bottom:18px;"><h2><?php esc_html_e('Trending Topics', 'rifnote-search'); ?></h2>
                <input type="hidden" name="rifnote_channel_trending_enabled" value="0" /><label><input type="checkbox" name="rifnote_channel_trending_enabled" value="1" <?php checked((bool) get_option('rifnote_channel_trending_enabled', true)); ?> /> <?php esc_html_e('Enable public Trending Topics channel', 'rifnote-search'); ?></label>
                <table class="form-table"><tr><th><?php esc_html_e('Topics', 'rifnote-search'); ?></th><td><textarea class="large-text" rows="4" name="rifnote_channel_trending_topics" placeholder="Elections&#10;Artificial intelligence"><?php echo esc_textarea(get_option('rifnote_channel_trending_topics', '')); ?></textarea><p class="description"><?php esc_html_e('One topic per line.', 'rifnote-search'); ?></p></td></tr>
                <tr><th><?php esc_html_e('Tags', 'rifnote-search'); ?></th><td><textarea class="large-text" rows="3" name="rifnote_channel_trending_tags"><?php echo esc_textarea(get_option('rifnote_channel_trending_tags', '')); ?></textarea></td></tr>
                <tr><th><?php esc_html_e('Keywords', 'rifnote-search'); ?></th><td><textarea class="large-text" rows="3" name="rifnote_channel_trending_keywords"><?php echo esc_textarea(get_option('rifnote_channel_trending_keywords', '')); ?></textarea></td></tr>
                <tr><th><?php esc_html_e('Categories', 'rifnote-search'); ?></th><td><input type="hidden" name="rifnote_channel_trending_categories[]" value="0" /><div style="display:flex;flex-wrap:wrap;gap:10px 16px;"><?php foreach ($categories as $category) : ?><label><input type="checkbox" name="rifnote_channel_trending_categories[]" value="<?php echo esc_attr($category->term_id); ?>" <?php checked(in_array((int) $category->term_id, $selected_categories, true)); ?> /> <?php echo esc_html($category->name); ?></label><?php endforeach; ?></div></td></tr>
                <tr><th><?php esc_html_e('Story limit', 'rifnote-search'); ?></th><td><input type="number" min="6" max="100" name="rifnote_channel_trending_limit" value="<?php echo esc_attr(get_option('rifnote_channel_trending_limit', 30)); ?>" /></td></tr></table>
            </div>
            <div class="card" style="padding:20px;margin-bottom:18px;"><h2><?php esc_html_e('Football Stories', 'rifnote-search'); ?></h2>
                <input type="hidden" name="rifnote_channel_football_enabled" value="0" /><label><input type="checkbox" name="rifnote_channel_football_enabled" value="1" <?php checked((bool) get_option('rifnote_channel_football_enabled', true)); ?> /> <?php esc_html_e('Enable public Football Stories channel', 'rifnote-search'); ?></label>
                <table class="form-table"><tr><th><?php esc_html_e('Leagues', 'rifnote-search'); ?></th><td><input type="hidden" name="rifnote_channel_football_leagues[]" value="" /><select multiple size="7" class="regular-text" name="rifnote_channel_football_leagues[]" style="min-width:360px;"><?php foreach ($leagues as $name) : ?><option value="<?php echo esc_attr($name); ?>" <?php selected(in_array($name, $selected_leagues, true)); ?>><?php echo esc_html($name); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e('Select one or more competitions from stored API-Football data.', 'rifnote-search'); ?></p></td></tr>
                <tr><th><?php esc_html_e('Clubs', 'rifnote-search'); ?></th><td><input type="hidden" name="rifnote_channel_football_clubs[]" value="" /><select multiple size="10" class="regular-text" name="rifnote_channel_football_clubs[]" style="min-width:360px;"><?php foreach ($clubs as $name) : ?><option value="<?php echo esc_attr($name); ?>" <?php selected(in_array($name, $selected_clubs, true)); ?>><?php echo esc_html($name); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e('Select clubs using Ctrl/Cmd-click.', 'rifnote-search'); ?></p></td></tr>
                <tr><th><?php esc_html_e('Players', 'rifnote-search'); ?></th><td><textarea class="large-text" rows="4" name="rifnote_channel_football_players" placeholder="Victor Osimhen&#10;Bukayo Saka"><?php echo esc_textarea(get_option('rifnote_channel_football_players', '')); ?></textarea></td></tr>
                <tr><th><?php esc_html_e('Extra keywords', 'rifnote-search'); ?></th><td><textarea class="large-text" rows="3" name="rifnote_channel_football_keywords" placeholder="injury&#10;press conference"><?php echo esc_textarea(get_option('rifnote_channel_football_keywords', '')); ?></textarea></td></tr>
                <tr><th><?php esc_html_e('Story limit', 'rifnote-search'); ?></th><td><input type="number" min="6" max="100" name="rifnote_channel_football_limit" value="<?php echo esc_attr(get_option('rifnote_channel_football_limit', 30)); ?>" /></td></tr></table>
            </div>
            <div class="card" style="padding:20px;margin-bottom:18px;"><h2><?php esc_html_e('Performance', 'rifnote-search'); ?></h2><label><?php esc_html_e('Cache duration', 'rifnote-search'); ?> <input type="number" min="60" max="1800" name="rifnote_channel_cache_ttl" value="<?php echo esc_attr(get_option('rifnote_channel_cache_ttl', 180)); ?>" /> <?php esc_html_e('seconds', 'rifnote-search'); ?></label></div>
            <?php submit_button(__('Save story channels', 'rifnote-search')); ?>
        </form></div>
        <?php
    }
}
