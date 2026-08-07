<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_CustomGPT_Import {
    const DEFAULT_MAX_BATCH = 100;

    public static function settings() {
        return array(
            'enabled' => (bool) get_option('rifnote_customgpt_import_enabled', false),
            'api_key_hash' => (string) get_option('rifnote_customgpt_import_key_hash', ''),
            'default_mode' => self::sanitize_mode(get_option('rifnote_customgpt_import_default_mode', 'draft')),
            'max_batch' => max(1, min(100, absint(get_option('rifnote_customgpt_import_max_batch', self::DEFAULT_MAX_BATCH)))),
            'allowed_domains' => self::allowed_domains(),
            'instructions' => (string) get_option('rifnote_customgpt_import_instructions', self::default_instructions()),
        );
    }

    public static function default_instructions() {
        return "You are the Rifnote Search editorial import assistant.\n\nYour job is to convert CSV, JSON, HTML, PDF extracts, source-link dumps, social URLs, video URLs, or existing Rifnote database stories into Rifnote-ready story records, story aggregation hubs and explainable trending signals.\n\nUse the story export endpoint to pull existing stories from WordPress. For aggregation, include GPT-created stories, publisher stories, TheNewsAPI stories, RSS stories, social/video stories and Rifnote Admin stories unless the admin asks otherwise. For cleanup-only work, prefer querying with origin_model_not=GPT and incomplete=true so you avoid reworking stories originally created by CustomGPT unless asked.\n\nFormat stories cleanly, preserve source metadata, add concise ai_summary and ai_key_points when missing, and send editorial updates back through the formatting endpoint.\n\nFor aggregation work, group stories covering the same core event and send the hub to /customgpt/aggregation/batch. Use clusters with cluster_id, title, summary, category, image_url, post_ids or stories containing post_id. Use a stable manual_{topic_slug} cluster id, keep the same category across the group, and never merge unrelated stories just because the same person or club appears.\n\nFor social media or video records, set source_type to social or video, preserve the original post URL in original_url, include platform such as youtube, x, instagram, tiktok, facebook, threads or reddit, and add author_handle, platform_post_id, embed_html and social_metrics when available. Do not invent engagement counts.\n\nWhen a story batch reveals a genuine trend, include trending_signals. Each signal should include topic, type, category, score_boost from 1-20, confidence from 0-1, expires_in_minutes, aliases and reason. Use signals for real momentum only, not generic words.\n\nReturn a JSON object with:\n- source: CustomGPT\n- origin_model: GPT\n- mode: draft\n- batch_id: a short unique label\n- stories: up to 100 story objects for import or formatting\n- clusters: story hub objects for aggregation work\n- trending_signals: optional topic signals\n\nEach story should include post_id when updating an existing post, plus title, excerpt, body, original_url, source_name, source_url, category, tags, published_at, image_url, author_name, source_type, platform, author_handle, platform_post_id, embed_html, social_metrics, entities, ai_summary, ai_key_points, story_cluster_id, and editorial_notes when available.\n\nNever invent facts. If a field is unknown, omit it or leave it empty. Preserve original source URLs. Prefer concise excerpts and neutral language.";
    }

    public static function allowed_domains() {
        $raw = (string) get_option('rifnote_customgpt_import_allowed_domains', '');
        $domains = array();

        foreach (preg_split('/[\r\n,]+/', $raw) as $domain) {
            $domain = strtolower(trim(sanitize_text_field($domain)));
            $domain = preg_replace('#^https?://#', '', $domain);
            $domain = preg_replace('#/.*$#', '', $domain);
            $domain = preg_replace('/^www\./', '', $domain);

            if ($domain) {
                $domains[] = $domain;
            }
        }

        return array_values(array_unique($domains));
    }

    public static function normalize_payload($payload) {
        if (is_string($payload)) {
            $decoded = json_decode(trim($payload), true);
            return is_array($decoded) ? $decoded : array();
        }

        if (!is_array($payload)) {
            return array();
        }

        foreach (array('payload_json', 'payload', 'json') as $key) {
            if (!empty($payload[$key]) && is_string($payload[$key])) {
                $decoded = json_decode(trim((string) $payload[$key]), true);

                if (is_array($decoded)) {
                    return array_merge($payload, $decoded);
                }
            }
        }

        return $payload;
    }

    public static function sanitize_mode($mode) {
        $mode = sanitize_key((string) $mode);
        return in_array($mode, array('draft', 'pending', 'publish'), true) ? $mode : 'draft';
    }

    public static function generate_api_key() {
        $key = 'rfgpt_' . wp_generate_password(40, false, false);
        update_option('rifnote_customgpt_import_key_hash', wp_hash_password($key), false);
        return $key;
    }

    public static function authenticate($key) {
        $settings = self::settings();
        $key = trim((string) $key);

        if (empty($settings['enabled'])) {
            return new WP_Error('rifnote_customgpt_disabled', __('CustomGPT import is disabled.', 'rifnote-search'), array('status' => 403));
        }

        if (!$key || empty($settings['api_key_hash']) || !wp_check_password($key, $settings['api_key_hash'])) {
            self::log_event(array(
                'event' => 'auth_failed',
                'status' => 'blocked',
                'message' => __('CustomGPT import API key was missing or invalid.', 'rifnote-search'),
            ));
            return new WP_Error('rifnote_customgpt_invalid_key', __('Invalid CustomGPT import API key.', 'rifnote-search'), array('status' => 401));
        }

        return true;
    }

    public static function import_batch($payload) {
        $payload = self::normalize_payload($payload);
        $settings = self::settings();
        $stories = isset($payload['stories']) && is_array($payload['stories']) ? $payload['stories'] : array();
        $mode = self::sanitize_mode($payload['mode'] ?? $settings['default_mode']);
        $batch_id = Rifnote_Search_Source_Meta::normalize_text((string) ($payload['batch_id'] ?? ''));
        $source = Rifnote_Search_Source_Meta::normalize_text((string) ($payload['source'] ?? 'CustomGPT'));
        $origin_model = Rifnote_Search_Source_Meta::normalize_text((string) ($payload['origin_model'] ?? 'GPT'));
        $summary = array(
            'ok' => true,
            'source' => $source ? $source : 'CustomGPT',
            'batch_id' => $batch_id ? $batch_id : substr(hash('sha256', wp_json_encode($payload) . microtime(true)), 0, 12),
            'mode' => $mode,
            'received' => count($stories),
            'checked' => 0,
            'created' => 0,
            'updated' => 0,
            'duplicates' => 0,
            'errors' => 0,
            'posts' => array(),
            'messages' => array(),
            'ran_at' => gmdate(DATE_ATOM),
        );

        if (!$stories) {
            $summary['ok'] = false;
            $summary['errors']++;
            $summary['messages'][] = __('No stories were provided.', 'rifnote-search');
            self::log_event(array_merge($summary, array('event' => 'batch_rejected', 'status' => 'error')));
            return new WP_Error('rifnote_customgpt_empty_batch', __('No stories were provided.', 'rifnote-search'), array('status' => 400));
        }

        $stories = array_slice($stories, 0, $settings['max_batch']);
        $update_existing = !empty($payload['update_existing']) || 'update' === sanitize_key((string) ($payload['mode'] ?? ''));

        foreach ($stories as $story) {
            $summary['checked']++;
            $result = self::import_story(is_array($story) ? $story : array(), $mode, $source, $origin_model, $update_existing);

            if (is_wp_error($result)) {
                $code = $result->get_error_code();
                if ('rifnote_customgpt_duplicate' === $code) {
                    $summary['duplicates']++;
                } else {
                    $summary['errors']++;
                }
                $summary['messages'][] = $result->get_error_message();
                continue;
            }

            if (!empty($result['updated_existing'])) {
                $summary['updated']++;
            } else {
                $summary['created']++;
            }
            $summary['posts'][] = $result;
        }

        if (!empty($payload['trending_signals']) && is_array($payload['trending_signals'])) {
            $summary['trending'] = Rifnote_Search_Trending::add_signals($payload['trending_signals'], array(
                'source_model' => $origin_model ? $origin_model : 'GPT',
                'source_actor' => $source ? $source : 'CustomGPT',
                'batch_id' => $summary['batch_id'],
            ));
        }

        $summary['ok'] = 0 === (int) $summary['errors'];
        self::log_event(array_merge($summary, array('event' => 'batch_imported', 'status' => $summary['ok'] ? 'ok' : 'partial')));
        update_option('rifnote_customgpt_import_last_run', $summary, false);

        return $summary;
    }

    public static function export_stories($args = array()) {
        $limit = max(1, min(100, absint($args['limit'] ?? 25)));
        $status = sanitize_key((string) ($args['status'] ?? 'any'));
        $allowed_statuses = array('any', 'publish', 'draft', 'pending', 'future', 'private');
        $source_type = sanitize_key((string) ($args['source_type'] ?? ''));
        $origin_model = Rifnote_Search_Source_Meta::normalize_text((string) ($args['origin_model'] ?? ''));
        $origin_model_not = Rifnote_Search_Source_Meta::normalize_text((string) ($args['origin_model_not'] ?? ''));
        $category = sanitize_title((string) ($args['category'] ?? ''));
        $missing_summary = !empty($args['missing_summary']);
        $incomplete = !empty($args['incomplete']);
        $log_export = !array_key_exists('log', $args) || !empty($args['log']);
        $query = Rifnote_Search_Source_Meta::normalize_text((string) ($args['q'] ?? ''));
        $meta_query = array();
        $tax_query = array();

        if (!in_array($status, $allowed_statuses, true)) {
            $status = 'any';
        }

        if ($source_type) {
            $meta_query[] = array(
                'key' => 'source_type',
                'value' => $source_type,
                'compare' => '=',
            );
        }

        if ($origin_model) {
            $meta_query[] = array(
                'key' => 'rifnote_origin_model',
                'value' => $origin_model,
                'compare' => '=',
            );
        }

        if ($origin_model_not) {
            $meta_query[] = array(
                'relation' => 'OR',
                array('key' => 'rifnote_origin_model', 'compare' => 'NOT EXISTS'),
                array('key' => 'rifnote_origin_model', 'value' => $origin_model_not, 'compare' => '!='),
            );
        }

        if ($missing_summary) {
            $meta_query[] = array(
                'relation' => 'OR',
                array('key' => 'ai_summary', 'compare' => 'NOT EXISTS'),
                array('key' => 'ai_summary', 'value' => '', 'compare' => '='),
            );
        }

        if ($incomplete) {
            $meta_query[] = array(
                'relation' => 'OR',
                array('key' => 'ai_summary', 'compare' => 'NOT EXISTS'),
                array('key' => 'ai_summary', 'value' => '', 'compare' => '='),
                array('key' => 'ai_key_points', 'compare' => 'NOT EXISTS'),
                array('key' => 'ai_key_points', 'value' => '', 'compare' => '='),
                array('key' => 'entities', 'compare' => 'NOT EXISTS'),
                array('key' => 'entities', 'value' => '', 'compare' => '='),
                array('key' => 'source_name', 'compare' => 'NOT EXISTS'),
                array('key' => 'source_name', 'value' => '', 'compare' => '='),
                array('key' => 'original_url', 'compare' => 'NOT EXISTS'),
                array('key' => 'original_url', 'value' => '', 'compare' => '='),
            );
        }

        if ($category) {
            $tax_query[] = array(
                'taxonomy' => 'category',
                'field' => 'slug',
                'terms' => $category,
            );
        }

        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'any' === $status ? array('publish', 'draft', 'pending', 'future', 'private') : $status,
            'posts_per_page' => $limit,
            's' => $query,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => $meta_query,
            'tax_query' => $tax_query,
        ));

        $stories = array_map(array(__CLASS__, 'story_payload'), $posts);
        $summary = array(
            'ok' => true,
            'count' => count($stories),
            'stories' => $stories,
            'filters' => array(
                'limit' => $limit,
                'status' => $status,
                'source_type' => $source_type,
                'origin_model' => $origin_model,
                'origin_model_not' => $origin_model_not,
                'category' => $category,
                'missing_summary' => (bool) $missing_summary,
                'incomplete' => (bool) $incomplete,
                'q' => $query,
            ),
        );

        if ($log_export) {
            self::log_event(array(
                'event' => 'stories_exported',
                'status' => 'ok',
                'count' => count($stories),
                'filters' => $summary['filters'],
            ));
        }

        return $summary;
    }

    public static function cleanup_queue($limit = 20) {
        return self::export_stories(array(
            'limit' => $limit,
            'status' => 'any',
            'origin_model_not' => 'GPT',
            'incomplete' => true,
            'log' => false,
        ));
    }

    public static function missing_fields_for_story($story) {
        $missing = array();

        foreach (array(
            'ai_summary' => __('AI summary', 'rifnote-search'),
            'ai_key_points' => __('Key points', 'rifnote-search'),
            'entities' => __('Entities', 'rifnote-search'),
            'source_name' => __('Source name', 'rifnote-search'),
            'original_url' => __('Original URL', 'rifnote-search'),
        ) as $key => $label) {
            $value = isset($story[$key]) ? $story[$key] : '';

            if (is_array($value)) {
                if (!$value) {
                    $missing[] = $label;
                }
            } elseif ('' === trim((string) $value)) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    public static function format_batch($payload) {
        $payload = self::normalize_payload($payload);
        $settings = self::settings();
        $stories = isset($payload['stories']) && is_array($payload['stories']) ? array_slice($payload['stories'], 0, $settings['max_batch']) : array();
        $batch_id = Rifnote_Search_Source_Meta::normalize_text((string) ($payload['batch_id'] ?? ''));
        $summary = array(
            'ok' => true,
            'batch_id' => $batch_id ? $batch_id : substr(hash('sha256', wp_json_encode($payload) . microtime(true)), 0, 12),
            'received' => count($stories),
            'checked' => 0,
            'updated' => 0,
            'errors' => 0,
            'posts' => array(),
            'messages' => array(),
            'ran_at' => gmdate(DATE_ATOM),
        );

        if (!$stories) {
            $summary['ok'] = false;
            $summary['errors']++;
            $summary['messages'][] = __('No stories were provided for formatting.', 'rifnote-search');
            self::log_event(array_merge($summary, array('event' => 'format_batch_rejected', 'status' => 'error')));
            return new WP_Error('rifnote_customgpt_empty_format_batch', __('No stories were provided for formatting.', 'rifnote-search'), array('status' => 400));
        }

        foreach ($stories as $story) {
            $summary['checked']++;
            $result = self::format_existing_story(is_array($story) ? $story : array());

            if (is_wp_error($result)) {
                $summary['errors']++;
                $summary['messages'][] = $result->get_error_message();
                continue;
            }

            $summary['updated']++;
            $summary['posts'][] = $result;
        }

        if (!empty($payload['trending_signals']) && is_array($payload['trending_signals'])) {
            $summary['trending'] = Rifnote_Search_Trending::add_signals($payload['trending_signals'], array(
                'source_model' => 'GPT',
                'source_actor' => 'CustomGPT Format',
                'batch_id' => $summary['batch_id'],
            ));
        }

        $summary['ok'] = 0 === (int) $summary['errors'];
        self::log_event(array_merge($summary, array('event' => 'stories_formatted', 'status' => $summary['ok'] ? 'ok' : 'partial')));
        update_option('rifnote_customgpt_format_last_run', $summary, false);

        return $summary;
    }

    private static function import_story($story, $mode, $source, $origin_model = 'GPT', $update_existing = false) {
        $title = Rifnote_Search_Source_Meta::normalize_text((string) ($story['title'] ?? $story['headline'] ?? ''));
        $original_url = esc_url_raw((string) ($story['original_url'] ?? $story['url'] ?? ''));
        $source_name = Rifnote_Search_Source_Meta::normalize_text((string) ($story['source_name'] ?? $story['publisher'] ?? ''));
        $body = Rifnote_Search_Source_Meta::normalize_html((string) ($story['body'] ?? $story['content'] ?? ''));
        $excerpt = Rifnote_Search_Source_Meta::normalize_text((string) ($story['excerpt'] ?? $story['summary'] ?? ''), true);
        $existing_post_id = self::resolve_story_post_id($story);

        if ($existing_post_id && ($update_existing || !empty($story['post_id']) || !empty($story['update_existing']))) {
            $updated = self::format_existing_story($story, 'CustomGPT Import');

            if (is_wp_error($updated)) {
                return $updated;
            }

            $updated['updated_existing'] = true;
            return $updated;
        }

        if (!$title || !$original_url) {
            return new WP_Error('rifnote_customgpt_invalid_story', __('A story is missing title or original_url.', 'rifnote-search'));
        }

        if (!self::domain_allowed($original_url)) {
            return new WP_Error('rifnote_customgpt_domain_not_allowed', sprintf(__('Domain is not allowed for CustomGPT import: %s', 'rifnote-search'), Rifnote_Search_Source_Meta::source_domain($original_url)));
        }

        if (Rifnote_Search_Legal::is_domain_blocked($original_url)) {
            return new WP_Error('rifnote_customgpt_blocked_domain', __('Story domain is blocked from Rifnote Search ingestion.', 'rifnote-search'));
        }

        if (Rifnote_Search_Ingestion::already_exists($original_url, $title)) {
            if ($update_existing || !empty($story['update_existing'])) {
                $story['original_url'] = $original_url;
                $updated = self::format_existing_story($story, 'CustomGPT Import');

                if (is_wp_error($updated)) {
                    return $updated;
                }

                $updated['updated_existing'] = true;
                return $updated;
            }

            return new WP_Error('rifnote_customgpt_duplicate', sprintf(__('Duplicate skipped: %s', 'rifnote-search'), $title));
        }

        if (!$excerpt) {
            $excerpt = wp_trim_words(wp_strip_all_tags($body), 45);
        }

        $published_at = !empty($story['published_at']) ? gmdate('Y-m-d H:i:s', strtotime((string) $story['published_at'])) : current_time('mysql', true);
        $post_id = wp_insert_post(array(
            'post_type' => 'post',
            'post_status' => $mode,
            'post_title' => $title,
            'post_excerpt' => $excerpt,
            'post_content' => $body ? $body : $excerpt,
            'post_date_gmt' => $published_at,
            'post_date' => get_date_from_gmt($published_at),
        ), true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        $source_url = esc_url_raw((string) ($story['source_url'] ?? ''));
        if (!$source_url) {
            $source_url = Rifnote_Search_Source_Meta::source_home_from_url($original_url);
        }

        update_post_meta($post_id, 'source_name', $source_name ? $source_name : Rifnote_Search_Source_Meta::source_domain($original_url));
        update_post_meta($post_id, 'source_url', $source_url);
        update_post_meta($post_id, 'original_url', $original_url);
        update_post_meta($post_id, 'read_full_story_url', $original_url);
        update_post_meta($post_id, 'canonical_url', esc_url_raw((string) ($story['canonical_url'] ?? $original_url)));
        $source_type = Rifnote_Search_Source_Meta::sanitize_source_type($story['source_type'] ?? '');
        if ('original' === $source_type) {
            $source_type = !empty($story['platform']) ? ('youtube' === sanitize_key((string) $story['platform']) ? 'video' : 'social') : 'external';
        }
        update_post_meta($post_id, 'source_type', $source_type);
        update_post_meta($post_id, 'rifnote_customgpt_source', $source);
        update_post_meta($post_id, 'normalized_headline', Rifnote_Search_Source_Meta::normalize_headline($title));
        update_post_meta($post_id, 'content_hash', hash('sha256', wp_strip_all_tags($body ? $body : ($excerpt ? $excerpt : $title))));
        update_post_meta($post_id, 'freshness_score', 1);
        update_post_meta($post_id, 'source_authority_score', 0.6);
        Rifnote_Search_Source_Meta::stamp_origin($post_id, $origin_model ? $origin_model : 'GPT', $source ? $source : 'CustomGPT', 'customgpt');

        if (!empty($story['story_cluster_id'])) {
            $story_cluster_id = sanitize_text_field((string) $story['story_cluster_id']);
            update_post_meta($post_id, 'story_cluster_id', $story_cluster_id);
            update_post_meta($post_id, 'rifnote_gpt_aggregation_id', $story_cluster_id);
            update_post_meta($post_id, 'rifnote_aggregation_source', 'customgpt');
        }

        foreach (array('image_url' => 'rifnote_source_image_url', 'author_name' => 'rifnote_author_name', 'editorial_notes' => 'rifnote_customgpt_editorial_notes', 'ai_summary' => 'ai_summary') as $payload_key => $meta_key) {
            if (!empty($story[$payload_key])) {
                update_post_meta($post_id, $meta_key, is_array($story[$payload_key]) ? wp_json_encode(self::sanitize_deep($story[$payload_key])) : Rifnote_Search_Source_Meta::normalize_text((string) $story[$payload_key], true));
            }
        }

        self::update_social_meta($post_id, $story);

        if (!empty($story['entities'])) {
            update_post_meta($post_id, 'entities', wp_json_encode(self::sanitize_deep($story['entities'])));
        }

        if (!empty($story['ai_key_points'])) {
            update_post_meta($post_id, 'ai_key_points', wp_json_encode(self::sanitize_deep($story['ai_key_points'])));
        }

        if (!empty($story['story_cluster_id'])) {
            $story_cluster_id = sanitize_text_field((string) $story['story_cluster_id']);
            update_post_meta($post_id, 'story_cluster_id', $story_cluster_id);
            update_post_meta($post_id, 'rifnote_gpt_aggregation_id', $story_cluster_id);
            update_post_meta($post_id, 'rifnote_aggregation_source', 'customgpt');
        }

        Rifnote_Search_Aggregation::assign_category($post_id, $story['category'] ?? '', array($title, $excerpt, $body, $source_name));

        if (!empty($story['tags'])) {
            $tags = is_array($story['tags']) ? $story['tags'] : explode(',', (string) $story['tags']);
            wp_set_post_terms($post_id, array_filter(array_map(array('Rifnote_Search_Source_Meta', 'normalize_text'), array_map('trim', $tags))), 'post_tag', false);
        }

        if ('publish' === get_post_status($post_id)) {
            Rifnote_Search_Clustering::assign_post_cluster($post_id, get_post($post_id), true);
            Rifnote_Search_Index::index_post($post_id);
        }

        return array(
            'post_id' => (int) $post_id,
            'title' => $title,
            'status' => get_post_status($post_id),
            'edit_url' => get_edit_post_link($post_id, ''),
        );
    }

    private static function format_existing_story($story, $source = 'CustomGPT Format') {
        $post_id = self::resolve_story_post_id($story);

        if (!$post_id) {
            return new WP_Error('rifnote_customgpt_story_missing', __('Existing story could not be found by post_id or original_url.', 'rifnote-search'));
        }

        $post = get_post($post_id);

        if (!$post || 'post' !== $post->post_type) {
            return new WP_Error('rifnote_customgpt_invalid_post', __('Existing story is not a valid post.', 'rifnote-search'));
        }

        $updates = array('ID' => $post_id);

        if (!empty($story['title']) || !empty($story['headline'])) {
            $updates['post_title'] = Rifnote_Search_Source_Meta::normalize_text((string) ($story['title'] ?? $story['headline']));
        }

        if (array_key_exists('excerpt', $story) || array_key_exists('summary', $story)) {
            $updates['post_excerpt'] = Rifnote_Search_Source_Meta::normalize_text((string) ($story['excerpt'] ?? $story['summary'] ?? ''), true);
        }

        if (array_key_exists('body', $story) || array_key_exists('content', $story)) {
            $updates['post_content'] = Rifnote_Search_Source_Meta::normalize_html((string) ($story['body'] ?? $story['content'] ?? ''));
        }

        if (!empty($story['published_at'])) {
            $published_at = gmdate('Y-m-d H:i:s', strtotime((string) $story['published_at']));
            $updates['post_date_gmt'] = $published_at;
            $updates['post_date'] = get_date_from_gmt($published_at);
        }

        if (count($updates) > 1) {
            $updated = wp_update_post($updates, true);

            if (is_wp_error($updated)) {
                return $updated;
            }
        }

        self::update_story_meta($post_id, $story, $source);

        Rifnote_Search_Aggregation::assign_category($post_id, $story['category'] ?? '', array(
            $updates['post_title'] ?? get_the_title($post_id),
            $updates['post_excerpt'] ?? get_the_excerpt($post_id),
            $updates['post_content'] ?? get_post_field('post_content', $post_id),
            $story['source_name'] ?? get_post_meta($post_id, 'source_name', true),
        ));

        if (!empty($story['tags'])) {
            $tags = is_array($story['tags']) ? $story['tags'] : explode(',', (string) $story['tags']);
            wp_set_post_terms($post_id, array_filter(array_map(array('Rifnote_Search_Source_Meta', 'normalize_text'), array_map('trim', $tags))), 'post_tag', false);
        }

        Rifnote_Search_Clustering::assign_post_cluster($post_id, get_post($post_id), true);
        Rifnote_Search_Index::index_post($post_id);

        return array(
            'post_id' => (int) $post_id,
            'title' => get_the_title($post_id),
            'status' => get_post_status($post_id),
            'has_ai_summary' => '' !== (string) get_post_meta($post_id, 'ai_summary', true),
            'updated_existing' => true,
            'edit_url' => get_edit_post_link($post_id, ''),
        );
    }

    private static function resolve_story_post_id($story) {
        global $wpdb;

        $post_id = absint($story['post_id'] ?? 0);

        if ($post_id && get_post($post_id)) {
            return $post_id;
        }

        $original_url = esc_url_raw((string) ($story['original_url'] ?? $story['url'] ?? ''));

        if (!$original_url) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'original_url' AND meta_value = %s LIMIT 1",
            $original_url
        ));
    }

    private static function update_story_meta($post_id, $story, $source) {
        foreach (array(
            'source_name' => 'source_name',
            'source_url' => 'source_url',
            'original_url' => 'original_url',
            'read_full_story_url' => 'read_full_story_url',
            'canonical_url' => 'canonical_url',
            'image_url' => 'rifnote_source_image_url',
            'author_name' => 'rifnote_author_name',
            'editorial_notes' => 'rifnote_customgpt_editorial_notes',
            'ai_summary' => 'ai_summary',
        ) as $payload_key => $meta_key) {
            if (array_key_exists($payload_key, $story) && '' !== $story[$payload_key] && null !== $story[$payload_key]) {
                $value = is_array($story[$payload_key]) ? wp_json_encode(self::sanitize_deep($story[$payload_key])) : Rifnote_Search_Source_Meta::normalize_text((string) $story[$payload_key], true);
                if (in_array($meta_key, array('source_url', 'original_url', 'read_full_story_url', 'canonical_url', 'rifnote_source_image_url'), true)) {
                    $value = esc_url_raw((string) $story[$payload_key]);
                }
                update_post_meta($post_id, $meta_key, $value);
            }
        }

        if (array_key_exists('source_type', $story)) {
            update_post_meta($post_id, 'source_type', Rifnote_Search_Source_Meta::sanitize_source_type($story['source_type']));
        }

        self::update_social_meta($post_id, $story);

        if (!empty($story['entities'])) {
            update_post_meta($post_id, 'entities', wp_json_encode(self::sanitize_deep($story['entities'])));
        }

        if (!empty($story['ai_key_points'])) {
            update_post_meta($post_id, 'ai_key_points', wp_json_encode(self::sanitize_deep($story['ai_key_points'])));
        }

        update_post_meta($post_id, 'rifnote_customgpt_last_format_source', Rifnote_Search_Source_Meta::normalize_text($source));
        update_post_meta($post_id, 'rifnote_customgpt_last_formatted_at', current_time('mysql', true));
        update_post_meta($post_id, 'rifnote_last_editor_model', 'GPT');
        update_post_meta($post_id, 'rifnote_last_editor_actor', 'CustomGPT');
        update_post_meta($post_id, 'normalized_headline', Rifnote_Search_Source_Meta::normalize_headline(get_the_title($post_id)));
        update_post_meta($post_id, 'content_hash', hash('sha256', wp_strip_all_tags(get_post_field('post_content', $post_id) . ' ' . get_post_field('post_excerpt', $post_id))));
    }

    private static function story_payload($post) {
        $post = get_post($post);
        $categories = wp_get_post_terms($post->ID, 'category', array('fields' => 'names'));
        $tags = wp_get_post_terms($post->ID, 'post_tag', array('fields' => 'names'));

        return array(
            'post_id' => (int) $post->ID,
            'title' => get_the_title($post),
            'excerpt' => get_the_excerpt($post),
            'body' => wp_strip_all_tags((string) $post->post_content),
            'status' => get_post_status($post),
            'published_at' => get_post_time(DATE_ATOM, true, $post),
            'source_name' => (string) get_post_meta($post->ID, 'source_name', true),
            'source_url' => esc_url_raw(get_post_meta($post->ID, 'source_url', true)),
            'original_url' => esc_url_raw(get_post_meta($post->ID, 'original_url', true)),
            'read_full_story_url' => esc_url_raw(get_post_meta($post->ID, 'read_full_story_url', true)),
            'canonical_url' => esc_url_raw(get_post_meta($post->ID, 'canonical_url', true)),
            'source_type' => (string) get_post_meta($post->ID, 'source_type', true),
            'platform' => (string) get_post_meta($post->ID, 'rifnote_social_platform', true),
            'author_handle' => (string) get_post_meta($post->ID, 'rifnote_social_author_handle', true),
            'platform_post_id' => (string) get_post_meta($post->ID, 'rifnote_social_post_id', true),
            'embed_html' => (string) get_post_meta($post->ID, 'rifnote_social_embed_html', true),
            'social_metrics' => self::decode_json_meta(get_post_meta($post->ID, 'rifnote_social_metrics', true)),
            'origin_model' => (string) get_post_meta($post->ID, 'rifnote_origin_model', true),
            'origin_actor' => (string) get_post_meta($post->ID, 'rifnote_origin_actor', true),
            'origin_channel' => (string) get_post_meta($post->ID, 'rifnote_origin_channel', true),
            'last_editor_model' => (string) get_post_meta($post->ID, 'rifnote_last_editor_model', true),
            'story_cluster_id' => (string) get_post_meta($post->ID, 'story_cluster_id', true),
            'category' => $categories ? $categories[0] : '',
            'tags' => is_array($tags) ? $tags : array(),
            'image_url' => esc_url_raw(get_post_meta($post->ID, 'rifnote_source_image_url', true)),
            'author_name' => (string) get_post_meta($post->ID, 'rifnote_author_name', true),
            'entities' => self::decode_json_meta(get_post_meta($post->ID, 'entities', true)),
            'ai_summary' => (string) get_post_meta($post->ID, 'ai_summary', true),
            'ai_key_points' => self::decode_json_meta(get_post_meta($post->ID, 'ai_key_points', true)),
            'editorial_notes' => (string) get_post_meta($post->ID, 'rifnote_customgpt_editorial_notes', true),
        );
    }

    private static function decode_json_meta($value) {
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : array();
    }

    private static function domain_allowed($url) {
        $allowed = self::allowed_domains();

        if (!$allowed) {
            return true;
        }

        $domain = Rifnote_Search_Source_Meta::source_domain($url);

        foreach ($allowed as $allowed_domain) {
            if ($domain === $allowed_domain || substr($domain, -1 * (strlen($allowed_domain) + 1)) === '.' . $allowed_domain) {
                return true;
            }
        }

        return false;
    }

    private static function update_social_meta($post_id, $story) {
        $map = array(
            'platform' => 'rifnote_social_platform',
            'author_handle' => 'rifnote_social_author_handle',
            'platform_post_id' => 'rifnote_social_post_id',
            'social_post_id' => 'rifnote_social_post_id',
            'embed_html' => 'rifnote_social_embed_html',
            'social_metrics' => 'rifnote_social_metrics',
        );

        foreach ($map as $payload_key => $meta_key) {
            if (!array_key_exists($payload_key, $story) || '' === $story[$payload_key] || null === $story[$payload_key]) {
                continue;
            }

            if ('rifnote_social_platform' === $meta_key) {
                update_post_meta($post_id, $meta_key, sanitize_key((string) $story[$payload_key]));
            } elseif ('rifnote_social_embed_html' === $meta_key) {
                update_post_meta($post_id, $meta_key, Rifnote_Search_Source_Meta::normalize_html((string) $story[$payload_key]));
            } elseif ('rifnote_social_metrics' === $meta_key) {
                update_post_meta($post_id, $meta_key, is_array($story[$payload_key]) ? wp_json_encode(self::sanitize_deep($story[$payload_key])) : Rifnote_Search_Source_Meta::normalize_text((string) $story[$payload_key], true));
            } else {
                update_post_meta($post_id, $meta_key, Rifnote_Search_Source_Meta::normalize_text((string) $story[$payload_key]));
            }
        }
    }

    private static function sanitize_deep($value) {
        if (is_array($value)) {
            return array_map(array(__CLASS__, 'sanitize_deep'), $value);
        }

        return Rifnote_Search_Source_Meta::normalize_text((string) $value);
    }

    public static function log_event($entry) {
        $entry = self::sanitize_log_entry($entry);
        $path = self::daily_log_path();
        $dir = dirname($path);

        if (!wp_mkdir_p($dir)) {
            return false;
        }

        $entries = array();
        if (file_exists($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $entries = $decoded;
            }
        }

        $entries[] = $entry;

        return false !== file_put_contents($path, wp_json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public static function recent_logs($limit = 30) {
        $base = self::logs_base_dir();
        $files = glob($base . '/*/*.json');

        if (!$files) {
            return array();
        }

        rsort($files);
        $rows = array();

        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (!is_array($decoded)) {
                continue;
            }

            $decoded = array_reverse($decoded);
            foreach ($decoded as $entry) {
                $entry['log_file'] = str_replace(WP_CONTENT_DIR . '/', '', $file);
                $rows[] = $entry;

                if (count($rows) >= $limit) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    public static function logs_base_dir() {
        return trailingslashit(WP_CONTENT_DIR) . 'rifnote-search/customgpt-logs';
    }

    public static function daily_log_path($timestamp = null) {
        $timestamp = $timestamp ? (int) $timestamp : time();
        return self::logs_base_dir() . '/' . gmdate('Y-m', $timestamp) . '/' . gmdate('Y-m-d', $timestamp) . '.json';
    }

    private static function sanitize_log_entry($entry) {
        $entry = self::sanitize_deep(is_array($entry) ? $entry : array());
        $entry['logged_at'] = gmdate(DATE_ATOM);
        $entry['ip_hash'] = substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . wp_salt('auth')), 0, 16);

        return $entry;
    }
}
