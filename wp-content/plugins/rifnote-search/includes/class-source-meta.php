<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Source_Meta {
    public static function normalize_text($value, $textarea = false) {
        $value = (string) $value;

        if ('' === $value) {
            return '';
        }

        for ($i = 0; $i < 3; $i++) {
            $decoded = wp_specialchars_decode(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')), ENT_QUOTES);

            if ($decoded === $value) {
                break;
            }

            $value = $decoded;
        }

        $value = preg_replace('/\x{00A0}|\x{200B}|\x{FEFF}/u', ' ', $value);
        $value = preg_replace('/[ \t]+/u', ' ', $value);
        $value = trim($value);

        return $textarea ? sanitize_textarea_field($value) : sanitize_text_field($value);
    }

    public static function normalize_html($value) {
        $value = (string) $value;

        for ($i = 0; $i < 3; $i++) {
            $decoded = wp_specialchars_decode(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')), ENT_QUOTES);

            if ($decoded === $value) {
                break;
            }

            $value = $decoded;
        }

        return wp_kses_post($value);
    }

    public static function fields() {
        return array(
            'source_name' => array('label' => __('Source name', 'rifnote-search'), 'description' => __('Publisher or publication name shown beside the headline.', 'rifnote-search'), 'type' => 'string', 'sanitize' => array(__CLASS__, 'sanitize_text'), 'primary' => true),
            'source_url' => array('label' => __('Source website URL', 'rifnote-search'), 'description' => __('Publisher homepage. Used for source transparency and source search.', 'rifnote-search'), 'type' => 'url', 'sanitize' => 'esc_url_raw', 'primary' => true),
            'source_logo_url' => array('label' => __('Source logo URL', 'rifnote-search'), 'description' => __('Optional override. Rifnote will auto-discover and cache the source logo when this is empty.', 'rifnote-search'), 'type' => 'url', 'sanitize' => 'esc_url_raw', 'primary' => true, 'media' => true),
            'original_url' => array('label' => __('Original story URL', 'rifnote-search'), 'description' => __('Canonical publisher story link. This should point away from Rifnote for external stories.', 'rifnote-search'), 'type' => 'url', 'sanitize' => 'esc_url_raw', 'primary' => true),
            'read_full_story_url' => array('label' => __('Read full story URL', 'rifnote-search'), 'description' => __('Prominent outbound CTA. Falls back to Original story URL when blank.', 'rifnote-search'), 'type' => 'url', 'sanitize' => 'esc_url_raw', 'primary' => true),
            'rifnote_source_image_url' => array('label' => __('Story image', 'rifnote-search'), 'description' => __('Image used by Rifnote cards when the post has no featured image.', 'rifnote-search'), 'type' => 'url', 'sanitize' => 'esc_url_raw', 'primary' => true, 'media' => true),
            'publisher_id' => array('label' => __('Publisher ID', 'rifnote-search'), 'description' => __('Future publisher portal account ID. Leave empty for Rifnote-owned posts.', 'rifnote-search'), 'type' => 'integer', 'sanitize' => 'absint', 'primary' => true),
            'canonical_url' => array('label' => __('Canonical URL', 'rifnote-search'), 'description' => __('SEO canonical URL. Falls back to Original story URL or the post permalink.', 'rifnote-search'), 'type' => 'url', 'sanitize' => 'esc_url_raw'),
            'story_cluster_id' => array('label' => __('Story cluster ID', 'rifnote-search'), 'type' => 'string', 'sanitize' => array(__CLASS__, 'sanitize_text')),
            'ai_summary' => array('label' => __('AI summary', 'rifnote-search'), 'type' => 'string', 'sanitize' => array(__CLASS__, 'sanitize_textarea')),
            'ai_key_points' => array('label' => __('AI key points JSON', 'rifnote-search'), 'type' => 'string', 'sanitize' => 'wp_kses_post'),
            'freshness_score' => array('label' => __('Freshness score', 'rifnote-search'), 'type' => 'number', 'sanitize' => array(__CLASS__, 'sanitize_score')),
            'importance_score' => array('label' => __('Importance score', 'rifnote-search'), 'type' => 'number', 'sanitize' => array(__CLASS__, 'sanitize_score')),
            'source_authority_score' => array('label' => __('Source authority score', 'rifnote-search'), 'type' => 'number', 'sanitize' => array(__CLASS__, 'sanitize_score')),
            'click_through_score' => array('label' => __('Click-through score', 'rifnote-search'), 'type' => 'number', 'sanitize' => array(__CLASS__, 'sanitize_score')),
            'editor_boost' => array('label' => __('Editor boost', 'rifnote-search'), 'type' => 'number', 'sanitize' => array(__CLASS__, 'sanitize_score')),
            'content_hash' => array('label' => __('Content hash', 'rifnote-search'), 'type' => 'string', 'sanitize' => array(__CLASS__, 'sanitize_text')),
            'normalized_headline' => array('label' => __('Normalized headline', 'rifnote-search'), 'type' => 'string', 'sanitize' => array(__CLASS__, 'sanitize_text')),
            'entities' => array('label' => __('Entities JSON', 'rifnote-search'), 'type' => 'string', 'sanitize' => 'wp_kses_post'),
            'source_type' => array('label' => __('Source type', 'rifnote-search'), 'type' => 'string', 'sanitize' => array(__CLASS__, 'sanitize_source_type')),
            'rifnote_social_platform' => array('label' => __('Social platform', 'rifnote-search'), 'type' => 'string', 'sanitize' => 'sanitize_key'),
            'rifnote_social_author_handle' => array('label' => __('Social author handle', 'rifnote-search'), 'type' => 'string', 'sanitize' => array(__CLASS__, 'sanitize_text')),
            'rifnote_social_post_id' => array('label' => __('Social platform post ID', 'rifnote-search'), 'type' => 'string', 'sanitize' => array(__CLASS__, 'sanitize_text')),
            'rifnote_social_embed_html' => array('label' => __('Social embed HTML', 'rifnote-search'), 'type' => 'string', 'sanitize' => array(__CLASS__, 'normalize_html')),
            'rifnote_social_metrics' => array('label' => __('Social metrics JSON', 'rifnote-search'), 'type' => 'string', 'sanitize' => 'wp_kses_post'),
            'rifnote_social_import_source' => array('label' => __('Social import source', 'rifnote-search'), 'type' => 'string', 'sanitize' => array(__CLASS__, 'sanitize_text')),
            'rifnote_origin_model' => array('label' => __('Origin model/system', 'rifnote-search'), 'type' => 'string', 'sanitize' => array(__CLASS__, 'sanitize_text')),
            'rifnote_origin_actor' => array('label' => __('Origin actor', 'rifnote-search'), 'type' => 'string', 'sanitize' => array(__CLASS__, 'sanitize_text')),
            'rifnote_origin_channel' => array('label' => __('Origin channel', 'rifnote-search'), 'type' => 'string', 'sanitize' => 'sanitize_key'),
        );
    }

    public static function primary_fields() {
        return array_filter(self::fields(), function ($field) {
            return !empty($field['primary']);
        });
    }

    public static function advanced_fields() {
        return array_filter(self::fields(), function ($field) {
            return empty($field['primary']);
        });
    }

    public static function register() {
        foreach (self::fields() as $key => $field) {
            register_post_meta('post', $key, array(
                'type' => 'number' === $field['type'] ? 'number' : ('integer' === $field['type'] ? 'integer' : 'string'),
                'single' => true,
                'show_in_rest' => true,
                'sanitize_callback' => $field['sanitize'],
                'auth_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ));
        }
    }

    public static function sanitize_score($value) {
        if ('' === $value || null === $value) {
            return '';
        }

        $score = (float) $value;

        if ($score > 1) {
            $score = $score / 100;
        }

        return min(1, max(0, $score));
    }

    public static function sanitize_text($value) {
        return self::normalize_text($value);
    }

    public static function sanitize_textarea($value) {
        return self::normalize_text($value, true);
    }

    public static function sanitize_source_type($value) {
        $allowed = array('original', 'syndicated', 'external', 'submitted', 'rss', 'social', 'video');
        $value = sanitize_key($value);

        return in_array($value, $allowed, true) ? $value : 'original';
    }

    public static function stamp_origin($post_id, $model, $actor = '', $channel = '') {
        $post_id = absint($post_id);

        if (!$post_id) {
            return;
        }

        if ($model) {
            update_post_meta($post_id, 'rifnote_origin_model', sanitize_text_field((string) $model));
        }

        if ($actor) {
            update_post_meta($post_id, 'rifnote_origin_actor', sanitize_text_field((string) $actor));
        }

        if ($channel) {
            update_post_meta($post_id, 'rifnote_origin_channel', sanitize_key((string) $channel));
        }
    }

    public static function maybe_stamp_admin_origin($post_id, $post, $update) {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) || 'post' !== $post->post_type) {
            return;
        }

        if (get_post_meta($post_id, 'rifnote_origin_model', true)) {
            return;
        }

        $user = get_userdata(get_current_user_id());
        self::stamp_origin($post_id, 'Rifnote Admin', $user ? $user->user_login : __('Rifnote Admin', 'rifnote-search'), 'admin');
    }

    public static function normalize_existing_posts($limit = 200) {
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => array('publish', 'draft', 'pending', 'future', 'private'),
            'posts_per_page' => max(1, min(1000, absint($limit))),
            'orderby' => 'modified',
            'order' => 'DESC',
            'fields' => 'all',
        ));
        $summary = array(
            'checked' => 0,
            'updated' => 0,
            'meta_updated' => 0,
        );
        $meta_keys = array(
            'source_name',
            'ai_summary',
            'normalized_headline',
            'rifnote_origin_model',
            'rifnote_origin_actor',
            'rifnote_customgpt_editorial_notes',
            'rifnote_author_name',
        );

        foreach ($posts as $post) {
            $summary['checked']++;
            $updates = array('ID' => (int) $post->ID);
            $title = self::normalize_text($post->post_title);
            $excerpt = self::normalize_text($post->post_excerpt, true);
            $content = self::normalize_html($post->post_content);

            if ($title !== $post->post_title) {
                $updates['post_title'] = $title;
            }

            if ($excerpt !== $post->post_excerpt) {
                $updates['post_excerpt'] = $excerpt;
            }

            if ($content !== $post->post_content) {
                $updates['post_content'] = $content;
            }

            if (count($updates) > 1) {
                wp_update_post($updates);
                $summary['updated']++;
            }

            foreach ($meta_keys as $key) {
                $value = get_post_meta($post->ID, $key, true);

                if ('' === $value || is_array($value)) {
                    continue;
                }

                $normalized = self::normalize_text($value, in_array($key, array('ai_summary', 'rifnote_customgpt_editorial_notes'), true));

                if ($normalized !== $value) {
                    update_post_meta($post->ID, $key, $normalized);
                    $summary['meta_updated']++;
                }
            }

            update_post_meta($post->ID, 'normalized_headline', self::normalize_headline($title));
            update_post_meta($post->ID, 'content_hash', hash('sha256', wp_strip_all_tags($content . ' ' . $excerpt)));
            Rifnote_Search_Index::index_post((int) $post->ID);
        }

        return $summary;
    }

    public static function normalize_headline($headline) {
        $headline = strtolower(wp_strip_all_tags((string) $headline));
        $headline = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $headline);
        $headline = preg_replace('/\b(the|a|an|and|or|to|of|in|on|for|with|from|by|at|as|is|are|was|were)\b/u', ' ', $headline);

        return trim(preg_replace('/\s+/', ' ', $headline));
    }

    public static function source_domain($url) {
        $host = wp_parse_url((string) $url, PHP_URL_HOST);

        if (!$host) {
            return '';
        }

        return preg_replace('/^www\./', '', strtolower($host));
    }

    public static function source_home_from_url($url) {
        $parts = wp_parse_url((string) $url);

        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        return esc_url_raw($parts['scheme'] . '://' . $parts['host'] . '/');
    }

    public static function source_initials($name, $domain = '') {
        $name = self::normalize_text($name ? $name : $domain);
        $name = preg_replace('/\.(com|net|org|co|ng|uk|info|news)$/i', '', $name);
        $parts = preg_split('/\s+|[.\-_]+/', $name, -1, PREG_SPLIT_NO_EMPTY);

        if (!$parts) {
            return 'R';
        }

        $initials = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= strtoupper(substr($part, 0, 1));
        }

        return $initials ? $initials : 'R';
    }

    public static function source_logo_url($post_id, $source_url = '', $outbound_url = '') {
        $manual_logo = esc_url_raw((string) get_post_meta($post_id, 'source_logo_url', true));

        if ($manual_logo) {
            return $manual_logo;
        }

        $lookup_url = $source_url ? $source_url : self::source_home_from_url($outbound_url);
        $domain = self::source_domain($lookup_url ? $lookup_url : $outbound_url);

        if (!$domain) {
            return '';
        }

        $overrides = get_option('rifnote_source_logo_overrides', array());
        $override = is_array($overrides) && !empty($overrides[$domain]) ? esc_url_raw((string) $overrides[$domain]) : '';

        if ($override) {
            return $override;
        }

        $cache = get_option('rifnote_source_logo_cache', array());
        $cached = is_array($cache) && isset($cache[$domain]) && is_array($cache[$domain]) ? $cache[$domain] : null;

        if ($cached && !empty($cached['checked_at']) && (time() - (int) $cached['checked_at']) < WEEK_IN_SECONDS) {
            return esc_url_raw((string) ($cached['logo_url'] ?? ''));
        }

        if (!self::can_discover_source_logo_now()) {
            return $lookup_url ? esc_url_raw(trailingslashit($lookup_url) . 'favicon.ico') : '';
        }

        $logo_url = self::discover_source_logo($lookup_url ? $lookup_url : self::source_home_from_url($outbound_url));

        if (!$logo_url && $lookup_url) {
            $logo_url = trailingslashit($lookup_url) . 'favicon.ico';
        }

        if (!is_array($cache)) {
            $cache = array();
        }

        $cache[$domain] = array(
            'logo_url' => esc_url_raw($logo_url),
            'checked_at' => time(),
        );

        if (count($cache) > 800) {
            $cache = array_slice($cache, -800, null, true);
        }

        update_option('rifnote_source_logo_cache', $cache, false);

        return esc_url_raw($logo_url);
    }

    private static function can_discover_source_logo_now() {
        return is_admin() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI);
    }

    public static function discover_source_logo($source_url) {
        $source_url = esc_url_raw((string) $source_url);

        if (!$source_url || !function_exists('wp_remote_get')) {
            return '';
        }

        $response = wp_remote_get($source_url, array(
            'timeout' => 2,
            'redirection' => 3,
            'limit_response_size' => 200000,
            'user-agent' => 'RifnoteSearchBot/1.0; ' . home_url('/'),
        ));

        if (is_wp_error($response)) {
            return '';
        }

        $body = (string) wp_remote_retrieve_body($response);

        if ('' === $body) {
            return '';
        }

        $candidates = array();

        if (preg_match_all('/<link\b[^>]*>/i', $body, $links)) {
            foreach ($links[0] as $tag) {
                if (!preg_match('/\brel=["\']([^"\']+)["\']/i', $tag, $rel_match) || !preg_match('/\bhref=["\']([^"\']+)["\']/i', $tag, $href_match)) {
                    continue;
                }

                $rel = strtolower($rel_match[1]);

                if (false === strpos($rel, 'icon')) {
                    continue;
                }

                $score = false !== strpos($rel, 'apple-touch-icon') ? 3 : (false !== strpos($rel, 'shortcut') ? 2 : 1);
                $candidates[] = array('url' => self::absolute_url($href_match[1], $source_url), 'score' => $score);
            }
        }

        if (preg_match_all('/<meta\b[^>]*(?:property|name)=["\'](?:og:logo|og:image|twitter:image)["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/i', $body, $meta_matches)) {
            foreach ($meta_matches[1] as $href) {
                $candidates[] = array('url' => self::absolute_url($href, $source_url), 'score' => 1);
            }
        }

        usort($candidates, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        foreach ($candidates as $candidate) {
            if (!empty($candidate['url'])) {
                return esc_url_raw($candidate['url']);
            }
        }

        return '';
    }

    public static function absolute_url($url, $base_url) {
        $url = trim(html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')));

        if (!$url) {
            return '';
        }

        if (0 === strpos($url, '//')) {
            $scheme = wp_parse_url($base_url, PHP_URL_SCHEME);
            return esc_url_raw(($scheme ? $scheme : 'https') . ':' . $url);
        }

        if (wp_parse_url($url, PHP_URL_SCHEME)) {
            return esc_url_raw($url);
        }

        $parts = wp_parse_url($base_url);

        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        if (0 === strpos($url, '/')) {
            return esc_url_raw($parts['scheme'] . '://' . $parts['host'] . $url);
        }

        return esc_url_raw(trailingslashit($parts['scheme'] . '://' . $parts['host'] . (isset($parts['path']) ? dirname($parts['path']) : '')) . $url);
    }

    private static function is_editorial_local_story($post_id, $origin_model, $origin_channel, $source_type) {
        $import_channels = array(
            'rss',
            'news_api',
            'newsapi',
            'publisher',
            'publisher_api',
            'customgpt',
            'customgpt_social',
            'customgpt_aggregation',
            'manual_social',
            'social',
            'youtube',
            'video',
            'warehouse',
            'data_api',
        );
        $import_models = array('GPT', 'CustomGPT', 'TheNewsAPI', 'NewsAPI', 'RSS Feed', 'Publisher Submission', 'Social Import', 'Manual Social');
        $import_source_types = array('external', 'submitted', 'rss', 'social', 'video', 'syndicated');

        if ($origin_channel && in_array($origin_channel, $import_channels, true)) {
            return false;
        }

        if ($origin_model && in_array($origin_model, $import_models, true)) {
            return false;
        }

        if ($source_type && in_array($source_type, $import_source_types, true)) {
            return false;
        }

        if ('admin' === $origin_channel || 0 === strcasecmp($origin_model, 'Rifnote Admin')) {
            return true;
        }

        $post = get_post($post_id);
        $user = $post ? get_userdata((int) $post->post_author) : false;

        if (!$user) {
            return false;
        }

        return (bool) array_intersect(array('administrator', 'editor', 'author'), (array) $user->roles);
    }

    public static function source_payload($post_id) {
        $original_url = get_post_meta($post_id, 'original_url', true);
        $read_full_story_url = get_post_meta($post_id, 'read_full_story_url', true);
        $source_url = get_post_meta($post_id, 'source_url', true);
        $canonical_url = get_post_meta($post_id, 'canonical_url', true);
        $source_name = get_post_meta($post_id, 'source_name', true);
        $source_type = get_post_meta($post_id, 'source_type', true);
        $origin_model = self::normalize_text((string) get_post_meta($post_id, 'rifnote_origin_model', true));
        $origin_actor = self::normalize_text((string) get_post_meta($post_id, 'rifnote_origin_actor', true));
        $origin_channel = sanitize_key((string) get_post_meta($post_id, 'rifnote_origin_channel', true));
        $social_platform = sanitize_key((string) get_post_meta($post_id, 'rifnote_social_platform', true));
        $social_embed_html = (string) get_post_meta($post_id, 'rifnote_social_embed_html', true);
        $post_url = get_permalink($post_id);
        $outbound_url = $read_full_story_url ? $read_full_story_url : ($original_url ? $original_url : $post_url);
        $home_url = home_url('/');
        $home_domain = self::source_domain($home_url);
        $outbound_domain = self::source_domain($outbound_url);
        $source_type = $source_type ? self::sanitize_source_type($source_type) : 'original';
        $is_admin_story = self::is_editorial_local_story($post_id, $origin_model, $origin_channel, $source_type);
        $is_rifnote_story = $is_admin_story || !$original_url || ($outbound_domain && $home_domain === $outbound_domain);

        if ($is_rifnote_story) {
            $source_url = $source_url ? $source_url : $home_url;
            $source_name = $source_name ? $source_name : get_bloginfo('name');
            $outbound_url = $post_url;
        }

        if (!$source_url && $outbound_url) {
            $source_url = self::source_home_from_url($outbound_url);
        }

        if (!$social_embed_html && class_exists('Rifnote_Search_Social')) {
            $embed_url = $original_url ? $original_url : $outbound_url;
            $embed_platform = $social_platform ? $social_platform : Rifnote_Search_Social::platform_from_url($embed_url);

            if (in_array($embed_platform, array('youtube', 'vimeo', 'x', 'twitter', 'instagram', 'facebook', 'tiktok', 'threads', 'reddit'), true)) {
                $social_embed_html = Rifnote_Search_Social::render_embed_for_url($embed_url, array('allow_remote_oembed' => true));
            }
        }

        return array(
            'source_name' => self::normalize_text($source_name ? $source_name : get_bloginfo('name')),
            'source_url' => esc_url_raw($source_url),
            'source_domain' => self::source_domain($source_url ? $source_url : $outbound_url),
            'source_logo_url' => self::source_logo_url($post_id, $source_url, $outbound_url),
            'source_initials' => self::source_initials($source_name ? $source_name : get_bloginfo('name'), self::source_domain($source_url ? $source_url : $outbound_url)),
            'original_url' => esc_url_raw($is_rifnote_story ? $post_url : ($original_url ? $original_url : $outbound_url)),
            'read_full_story_url' => esc_url_raw($outbound_url),
            'canonical_url' => esc_url_raw($is_rifnote_story ? $post_url : ($canonical_url ? $canonical_url : ($original_url ? $original_url : $post_url))),
            'publisher_id' => (int) get_post_meta($post_id, 'publisher_id', true),
            'source_type' => $source_type,
            'origin_model' => $origin_model,
            'origin_actor' => $origin_actor,
            'origin_channel' => $origin_channel,
            'is_rifnote_story' => (bool) $is_rifnote_story,
            'social_platform' => $social_platform,
            'social_author_handle' => self::normalize_text((string) get_post_meta($post_id, 'rifnote_social_author_handle', true)),
            'social_post_id' => self::normalize_text((string) get_post_meta($post_id, 'rifnote_social_post_id', true)),
            'social_embed_html' => $social_embed_html,
            'social_metrics' => json_decode((string) get_post_meta($post_id, 'rifnote_social_metrics', true), true) ?: array(),
            'has_external_source' => $outbound_url && self::source_domain($outbound_url) !== self::source_domain(home_url('/')),
        );
    }

    public static function add_meta_box() {
        add_meta_box(
            'rifnote-source-meta',
            __('Rifnote source metadata', 'rifnote-search'),
            array(__CLASS__, 'render_meta_box'),
            'post',
            'normal',
            'high'
        );
    }

    public static function render_meta_box($post) {
        $source_type = get_post_meta($post->ID, 'source_type', true);

        wp_nonce_field('rifnote_save_source_meta', 'rifnote_source_meta_nonce');
        ?>
        <p><?php esc_html_e('Use these fields to keep search results publisher-safe, source-attributed and rankable.', 'rifnote-search'); ?></p>
        <table class="form-table" role="presentation">
            <tbody>
                <?php self::render_fields($post, self::primary_fields()); ?>
            </tbody>
        </table>
        <details>
            <summary><?php esc_html_e('Advanced ranking, clustering and AI metadata', 'rifnote-search'); ?></summary>
            <table class="form-table" role="presentation">
                <tbody>
                    <?php self::render_fields($post, self::advanced_fields()); ?>
                </tbody>
            </table>
        </details>
        <?php
    }

    public static function render_fields($post, $fields) {
        $source_type = get_post_meta($post->ID, 'source_type', true);

        foreach ($fields as $key => $field) :
            ?>
            <tr>
                <th scope="row"><label for="rifnote_<?php echo esc_attr($key); ?>"><?php echo esc_html($field['label']); ?></label></th>
                <td>
                    <?php if ('source_type' === $key) : ?>
                        <select id="rifnote_<?php echo esc_attr($key); ?>" name="rifnote_meta[<?php echo esc_attr($key); ?>]">
                            <?php foreach (array('original', 'syndicated', 'external', 'submitted', 'rss') as $option) : ?>
                                <option value="<?php echo esc_attr($option); ?>" <?php selected($source_type ? $source_type : 'original', $option); ?>><?php echo esc_html(ucfirst($option)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif (in_array($key, array('ai_summary', 'ai_key_points', 'entities'), true)) : ?>
                        <textarea id="rifnote_<?php echo esc_attr($key); ?>" name="rifnote_meta[<?php echo esc_attr($key); ?>]" rows="3" class="large-text"><?php echo esc_textarea(get_post_meta($post->ID, $key, true)); ?></textarea>
                    <?php elseif (!empty($field['media'])) : ?>
                        <div class="rs-media-field">
                            <input id="rifnote_<?php echo esc_attr($key); ?>" name="rifnote_meta[<?php echo esc_attr($key); ?>]" type="url" class="large-text rs-media-url" value="<?php echo esc_attr(get_post_meta($post->ID, $key, true)); ?>" />
                            <p>
                                <button type="button" class="button rs-media-picker" data-target="#rifnote_<?php echo esc_attr($key); ?>" data-library="image" data-title="<?php esc_attr_e('Choose story image', 'rifnote-search'); ?>" data-button="<?php esc_attr_e('Use image', 'rifnote-search'); ?>"><?php esc_html_e('Choose from Media Library', 'rifnote-search'); ?></button>
                                <button type="button" class="button rs-media-clear" data-target="#rifnote_<?php echo esc_attr($key); ?>"><?php esc_html_e('Clear', 'rifnote-search'); ?></button>
                            </p>
                        </div>
                    <?php else : ?>
                        <input id="rifnote_<?php echo esc_attr($key); ?>" name="rifnote_meta[<?php echo esc_attr($key); ?>]" type="<?php echo esc_attr(self::input_type($field)); ?>" step="<?php echo 'number' === $field['type'] ? '0.01' : '1'; ?>" min="<?php echo 'number' === $field['type'] || 'integer' === $field['type'] ? '0' : ''; ?>" max="<?php echo 'number' === $field['type'] ? '100' : ''; ?>" class="large-text" value="<?php echo esc_attr(get_post_meta($post->ID, $key, true)); ?>" />
                    <?php endif; ?>
                    <?php if (!empty($field['description'])) : ?>
                        <p class="description"><?php echo esc_html($field['description']); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
        endforeach;
    }

    public static function input_type($field) {
        if ('url' === $field['type']) {
            return 'url';
        }

        return 'number' === $field['type'] || 'integer' === $field['type'] ? 'number' : 'text';
    }

    public static function save($post_id) {
        if (!isset($_POST['rifnote_source_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_source_meta_nonce'])), 'rifnote_save_source_meta')) {
            return;
        }

        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $post_id)) {
            return;
        }

        $posted = isset($_POST['rifnote_meta']) && is_array($_POST['rifnote_meta']) ? wp_unslash($_POST['rifnote_meta']) : array();

        foreach (self::fields() as $key => $field) {
            if (!array_key_exists($key, $posted)) {
                continue;
            }

            $value = call_user_func($field['sanitize'], $posted[$key]);

            if ('' === $value || null === $value) {
                delete_post_meta($post_id, $key);
            } else {
                update_post_meta($post_id, $key, $value);
            }
        }

        $original_url = get_post_meta($post_id, 'original_url', true);
        $read_full_story_url = get_post_meta($post_id, 'read_full_story_url', true);
        $source_url = get_post_meta($post_id, 'source_url', true);

        if ($original_url && !$read_full_story_url) {
            update_post_meta($post_id, 'read_full_story_url', $original_url);
        }

        if ($original_url && !get_post_meta($post_id, 'canonical_url', true)) {
            update_post_meta($post_id, 'canonical_url', $original_url);
        }

        if (!$source_url && ($original_url || $read_full_story_url)) {
            $source_home = self::source_home_from_url($original_url ? $original_url : $read_full_story_url);

            if ($source_home) {
                update_post_meta($post_id, 'source_url', $source_home);
            }
        }

        if (!get_post_meta($post_id, 'normalized_headline', true)) {
            update_post_meta($post_id, 'normalized_headline', self::normalize_headline(get_the_title($post_id)));
        }

        if (!get_post_meta($post_id, 'content_hash', true)) {
            $post = get_post($post_id);
            update_post_meta($post_id, 'content_hash', hash('sha256', wp_strip_all_tags($post ? $post->post_content : get_the_title($post_id))));
        }
    }

    public static function post_columns($columns) {
        $columns['rifnote_source'] = __('Rifnote source', 'rifnote-search');
        return $columns;
    }

    public static function render_post_column($column, $post_id) {
        if ('rifnote_source' !== $column) {
            return;
        }

        $source = self::source_payload($post_id);
        echo esc_html($source['source_name']);

        if (!empty($source['source_domain'])) {
            echo '<br /><small>' . esc_html($source['source_domain']) . '</small>';
        }
    }
}
