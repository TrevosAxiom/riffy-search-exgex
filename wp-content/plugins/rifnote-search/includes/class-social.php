<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Social {
    const CRON_HOOK = 'rifnote_search_social_import';

    public static function settings() {
        return array(
            'youtube_enabled' => (bool) get_option('rifnote_social_youtube_enabled', false),
            'youtube_api_key' => (string) get_option('rifnote_social_youtube_api_key', ''),
            'youtube_queries' => (string) get_option('rifnote_social_youtube_queries', "Nigeria news|Nigeria\nFootball highlights|Football\nAfrobeats news|Entertainment"),
            'youtube_region_code' => strtoupper(substr(sanitize_text_field((string) get_option('rifnote_social_youtube_region_code', 'NG')), 0, 2)),
            'youtube_language' => strtolower(substr(sanitize_text_field((string) get_option('rifnote_social_youtube_language', 'en')), 0, 8)),
            'youtube_max_results' => max(1, min(25, absint(get_option('rifnote_social_youtube_max_results', 8)))),
            'default_mode' => self::sanitize_mode(get_option('rifnote_social_default_mode', 'draft')),
            'manual_auto_embed' => (bool) get_option('rifnote_social_manual_auto_embed', true),
        );
    }

    public static function schedule() {
        $settings = self::settings();
        $scheduled = wp_next_scheduled(self::CRON_HOOK);

        if (!empty($settings['youtube_enabled']) && !empty($settings['youtube_api_key'])) {
            if (!$scheduled) {
                wp_schedule_event(time() + 180, 'rifnote_every_fifteen_minutes', self::CRON_HOOK);
            }
            return;
        }

        if ($scheduled) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
    }

    public static function run_cron() {
        return self::run_youtube_import(false);
    }

    public static function sanitize_mode($mode) {
        $mode = sanitize_key((string) $mode);
        return in_array($mode, array('draft', 'pending', 'publish'), true) ? $mode : 'draft';
    }

    public static function sanitize_queries($value) {
        $lines = array();
        foreach (preg_split('/\r\n|\r|\n/', (string) $value) as $line) {
            $line = trim(wp_strip_all_tags($line));
            if (!$line) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $query = Rifnote_Search_Source_Meta::normalize_text($parts[0] ?? '');
            $category = Rifnote_Search_Source_Meta::normalize_text($parts[1] ?? '');
            $mode = !empty($parts[2]) ? self::sanitize_mode($parts[2]) : '';
            if ($query) {
                $lines[] = implode('|', array_filter(array($query, $category, $mode), 'strlen'));
            }
        }
        return implode("\n", array_slice(array_values(array_unique($lines)), 0, 100));
    }

    public static function youtube_queries() {
        $settings = self::settings();
        $rows = array();

        foreach (preg_split('/\r\n|\r|\n/', $settings['youtube_queries']) as $line) {
            $line = trim($line);
            if (!$line) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $rows[] = array(
                'query' => Rifnote_Search_Source_Meta::normalize_text($parts[0] ?? ''),
                'category' => Rifnote_Search_Source_Meta::normalize_text($parts[1] ?? 'Videos'),
                'mode' => !empty($parts[2]) ? self::sanitize_mode($parts[2]) : $settings['default_mode'],
            );
        }

        return array_values(array_filter($rows, function ($row) {
            return !empty($row['query']);
        }));
    }

    public static function run_youtube_import($force = false) {
        $settings = self::settings();
        $summary = array(
            'ok' => true,
            'source' => 'YouTube API',
            'checked' => 0,
            'created' => 0,
            'duplicates' => 0,
            'errors' => 0,
            'messages' => array(),
            'posts' => array(),
            'ran_at' => gmdate(DATE_ATOM),
        );

        if (!$force && empty($settings['youtube_enabled'])) {
            $summary['ok'] = false;
            $summary['messages'][] = __('YouTube social import is disabled.', 'rifnote-search');
            return $summary;
        }

        if (empty($settings['youtube_api_key'])) {
            $summary['ok'] = false;
            $summary['errors']++;
            $summary['messages'][] = __('Add a YouTube Data API key before running YouTube import.', 'rifnote-search');
            update_option('rifnote_social_last_run', $summary, false);
            return $summary;
        }

        foreach (self::youtube_queries() as $row) {
            $endpoint = add_query_arg(array(
                'part' => 'snippet',
                'type' => 'video',
                'order' => 'date',
                'safeSearch' => 'moderate',
                'maxResults' => $settings['youtube_max_results'],
                'q' => $row['query'],
                'key' => $settings['youtube_api_key'],
                'regionCode' => $settings['youtube_region_code'],
                'relevanceLanguage' => $settings['youtube_language'],
            ), 'https://www.googleapis.com/youtube/v3/search');

            $response = wp_remote_get($endpoint, array('timeout' => 12));
            if (is_wp_error($response)) {
                $summary['errors']++;
                $summary['messages'][] = $response->get_error_message();
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $body = json_decode((string) wp_remote_retrieve_body($response), true);

            if ($code < 200 || $code >= 300 || !is_array($body)) {
                $summary['errors']++;
                $summary['messages'][] = sprintf(__('YouTube API request failed for "%s".', 'rifnote-search'), $row['query']);
                continue;
            }

            foreach ((array) ($body['items'] ?? array()) as $item) {
                $video_id = $item['id']['videoId'] ?? '';
                $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : array();
                if (!$video_id || empty($snippet['title'])) {
                    continue;
                }

                $summary['checked']++;
                $url = 'https://www.youtube.com/watch?v=' . rawurlencode($video_id);
                if (Rifnote_Search_Ingestion::already_exists($url, $snippet['title'])) {
                    $summary['duplicates']++;
                    continue;
                }

                $thumbs = is_array($snippet['thumbnails'] ?? null) ? $snippet['thumbnails'] : array();
                $image = $thumbs['high']['url'] ?? $thumbs['medium']['url'] ?? $thumbs['default']['url'] ?? '';
                $created = self::create_social_post(array(
                    'title' => $snippet['title'],
                    'excerpt' => $snippet['description'] ?? '',
                    'body' => $snippet['description'] ?? '',
                    'original_url' => $url,
                    'source_name' => $snippet['channelTitle'] ?? 'YouTube',
                    'source_url' => !empty($snippet['channelId']) ? 'https://www.youtube.com/channel/' . rawurlencode($snippet['channelId']) : 'https://www.youtube.com/',
                    'category' => $row['category'],
                    'tags' => array('YouTube', $row['query'], $row['category']),
                    'published_at' => $snippet['publishedAt'] ?? '',
                    'image_url' => $image,
                    'author_name' => $snippet['channelTitle'] ?? '',
                    'source_type' => 'video',
                    'platform' => 'youtube',
                    'platform_post_id' => $video_id,
                    'embed_html' => self::youtube_iframe($video_id),
                ), $row['mode'], 'YouTube API', 'social_api');

                if (is_wp_error($created)) {
                    $summary['errors']++;
                    $summary['messages'][] = $created->get_error_message();
                    continue;
                }

                $summary['created']++;
                $summary['posts'][] = $created;
            }
        }

        $summary['ok'] = 0 === (int) $summary['errors'];
        update_option('rifnote_social_last_run', $summary, false);
        Rifnote_Search_CustomGPT_Import::log_event(array_merge($summary, array(
            'event' => 'social_youtube_imported',
            'status' => $summary['ok'] ? 'ok' : 'partial',
        )));

        return $summary;
    }

    public static function import_manual_url($args) {
        $url = esc_url_raw((string) ($args['url'] ?? ''));
        $mode = self::sanitize_mode($args['mode'] ?? self::settings()['default_mode']);

        if (!$url) {
            return new WP_Error('rifnote_social_missing_url', __('Add a social URL to import.', 'rifnote-search'));
        }

        $preview = self::preview_url($url);
        $platform = sanitize_key((string) ($args['platform'] ?? ''));
        if (!$platform) {
            $platform = self::platform_from_url($url);
        }

        $title_override = Rifnote_Search_Source_Meta::normalize_text((string) ($args['title'] ?? ''));
        $title = $title_override ? $title_override : Rifnote_Search_Source_Meta::normalize_text((string) ($preview['title'] ?? ''));
        if (!$title) {
            $title = self::generated_title($url, $platform, $preview);
        }

        if (Rifnote_Search_Ingestion::already_exists($url, $title)) {
            return new WP_Error('rifnote_social_duplicate', sprintf(__('Duplicate skipped: %s', 'rifnote-search'), $title));
        }

        $source_name_override = Rifnote_Search_Source_Meta::normalize_text((string) ($args['source_name'] ?? ''));
        $source_url_override = esc_url_raw((string) ($args['source_url'] ?? ''));
        $excerpt_override = Rifnote_Search_Source_Meta::normalize_text((string) ($args['excerpt'] ?? ''), true);
        $source_name = $source_name_override ? $source_name_override : Rifnote_Search_Source_Meta::normalize_text((string) ($preview['provider_name'] ?? ucfirst($platform)));
        $source_url = $source_url_override ? $source_url_override : esc_url_raw((string) ($preview['provider_url'] ?? Rifnote_Search_Source_Meta::source_home_from_url($url)));
        $description = $excerpt_override ? $excerpt_override : Rifnote_Search_Source_Meta::normalize_text((string) ($preview['description'] ?? ''), true);
        if (!$description) {
            $description = self::generated_description($url, $platform, $source_name);
        }

        return self::create_social_post(array(
            'title' => $title,
            'excerpt' => $description,
            'body' => $description,
            'original_url' => $url,
            'source_name' => $source_name,
            'source_url' => $source_url,
            'category' => $args['category'] ?? 'Social',
            'tags' => array_filter(array($platform, $source_name, $args['category'] ?? 'Social')),
            'image_url' => esc_url_raw((string) ($args['image_url'] ?? ($preview['image_url'] ?? ''))),
            'author_name' => Rifnote_Search_Source_Meta::normalize_text((string) ($args['author_name'] ?? ($preview['author_name'] ?? ''))),
            'source_type' => 'youtube' === $platform ? 'video' : 'social',
            'platform' => $platform,
            'author_handle' => Rifnote_Search_Source_Meta::normalize_text((string) ($args['author_handle'] ?? '')),
            'platform_post_id' => Rifnote_Search_Source_Meta::normalize_text((string) ($args['platform_post_id'] ?? self::platform_post_id($url, $platform))),
            'embed_html' => $preview['embed_html'] ?? '',
            'social_metrics' => array('imported' => 'manual'),
        ), $mode, 'Manual Social', 'manual_social');
    }

    public static function customgpt_social_batch($payload) {
        $stories = isset($payload['stories']) && is_array($payload['stories']) ? $payload['stories'] : array();
        $mode = self::sanitize_mode($payload['mode'] ?? self::settings()['default_mode']);
        $summary = array(
            'ok' => true,
            'source' => Rifnote_Search_Source_Meta::normalize_text((string) ($payload['source'] ?? 'CustomGPT Social')),
            'batch_id' => Rifnote_Search_Source_Meta::normalize_text((string) ($payload['batch_id'] ?? 'social_' . substr(hash('sha256', wp_json_encode($payload) . microtime(true)), 0, 10))),
            'received' => count($stories),
            'created' => 0,
            'duplicates' => 0,
            'errors' => 0,
            'messages' => array(),
            'posts' => array(),
            'ran_at' => gmdate(DATE_ATOM),
        );

        if (!$stories) {
            return new WP_Error('rifnote_social_empty_batch', __('No social stories were provided.', 'rifnote-search'), array('status' => 400));
        }

        foreach (array_slice($stories, 0, 100) as $story) {
            if (!is_array($story)) {
                continue;
            }
            $story['source_type'] = $story['source_type'] ?? 'social';
            $story['platform'] = $story['platform'] ?? self::platform_from_url($story['original_url'] ?? $story['url'] ?? '');
            $story['category'] = $story['category'] ?? 'Social';

            $url = esc_url_raw((string) ($story['original_url'] ?? $story['url'] ?? ''));
            $title = Rifnote_Search_Source_Meta::normalize_text((string) ($story['title'] ?? $story['headline'] ?? ''));

            if ($url && Rifnote_Search_Ingestion::already_exists($url, $title)) {
                $summary['duplicates']++;
                continue;
            }

            $created = self::create_social_post($story, $mode, $summary['source'], 'customgpt_social', 'GPT');
            if (is_wp_error($created)) {
                $summary['errors']++;
                $summary['messages'][] = $created->get_error_message();
                continue;
            }
            $summary['created']++;
            $summary['posts'][] = $created;
        }

        if (!empty($payload['trending_signals']) && is_array($payload['trending_signals'])) {
            $summary['trending'] = Rifnote_Search_Trending::add_signals($payload['trending_signals'], array(
                'source_model' => 'GPT',
                'source_actor' => $summary['source'],
                'batch_id' => $summary['batch_id'],
            ));
        }

        $summary['ok'] = 0 === (int) $summary['errors'];
        Rifnote_Search_CustomGPT_Import::log_event(array_merge($summary, array(
            'event' => 'customgpt_social_imported',
            'status' => $summary['ok'] ? 'ok' : 'partial',
        )));

        return $summary;
    }

    public static function create_social_post($story, $mode = 'draft', $origin_actor = 'Social Import', $origin_channel = 'social', $origin_model = 'Social Import') {
        $title = Rifnote_Search_Source_Meta::normalize_text((string) ($story['title'] ?? $story['headline'] ?? ''));
        $original_url = esc_url_raw((string) ($story['original_url'] ?? $story['url'] ?? ''));

        if (!$title || !$original_url) {
            return new WP_Error('rifnote_social_invalid_story', __('Social story needs title and original_url.', 'rifnote-search'));
        }

        if (Rifnote_Search_Legal::is_domain_blocked($original_url)) {
            return new WP_Error('rifnote_social_blocked_domain', __('This social domain is blocked from Rifnote ingestion.', 'rifnote-search'));
        }

        $body = Rifnote_Search_Source_Meta::normalize_html((string) ($story['body'] ?? $story['content'] ?? ''));
        $excerpt = Rifnote_Search_Source_Meta::normalize_text((string) ($story['excerpt'] ?? $story['summary'] ?? ''), true);
        if (!$excerpt) {
            $excerpt = wp_trim_words(wp_strip_all_tags($body), 45);
        }

        $published_at = !empty($story['published_at']) ? gmdate('Y-m-d H:i:s', strtotime((string) $story['published_at'])) : current_time('mysql', true);
        $post_id = wp_insert_post(array(
            'post_type' => 'post',
            'post_status' => self::sanitize_mode($mode),
            'post_title' => $title,
            'post_excerpt' => $excerpt,
            'post_content' => $body ? $body : $excerpt,
            'post_date_gmt' => $published_at,
            'post_date' => get_date_from_gmt($published_at),
        ), true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        $platform = sanitize_key((string) ($story['platform'] ?? self::platform_from_url($original_url)));
        $source_name = Rifnote_Search_Source_Meta::normalize_text((string) ($story['source_name'] ?? $story['publisher'] ?? ucfirst($platform)));
        $source_url = esc_url_raw((string) ($story['source_url'] ?? Rifnote_Search_Source_Meta::source_home_from_url($original_url)));
        $source_type = Rifnote_Search_Source_Meta::sanitize_source_type($story['source_type'] ?? ('youtube' === $platform ? 'video' : 'social'));

        update_post_meta($post_id, 'source_name', $source_name ? $source_name : Rifnote_Search_Source_Meta::source_domain($original_url));
        update_post_meta($post_id, 'source_url', $source_url);
        update_post_meta($post_id, 'original_url', $original_url);
        update_post_meta($post_id, 'read_full_story_url', $original_url);
        update_post_meta($post_id, 'canonical_url', esc_url_raw((string) ($story['canonical_url'] ?? $original_url)));
        update_post_meta($post_id, 'source_type', $source_type);
        update_post_meta($post_id, 'rifnote_social_platform', $platform);
        update_post_meta($post_id, 'rifnote_social_author_handle', Rifnote_Search_Source_Meta::normalize_text((string) ($story['author_handle'] ?? '')));
        update_post_meta($post_id, 'rifnote_social_post_id', Rifnote_Search_Source_Meta::normalize_text((string) ($story['platform_post_id'] ?? $story['social_post_id'] ?? self::platform_post_id($original_url, $platform))));
        update_post_meta($post_id, 'rifnote_social_embed_html', Rifnote_Search_Source_Meta::normalize_html((string) ($story['embed_html'] ?? '')));
        update_post_meta($post_id, 'rifnote_social_metrics', !empty($story['social_metrics']) ? wp_json_encode(self::sanitize_deep($story['social_metrics'])) : '');
        update_post_meta($post_id, 'rifnote_social_import_source', Rifnote_Search_Source_Meta::normalize_text($origin_actor));
        update_post_meta($post_id, 'normalized_headline', Rifnote_Search_Source_Meta::normalize_headline($title));
        update_post_meta($post_id, 'content_hash', hash('sha256', wp_strip_all_tags(($body ? $body : $excerpt) . ' ' . $original_url)));
        update_post_meta($post_id, 'freshness_score', 1);
        update_post_meta($post_id, 'source_authority_score', 'video' === $source_type ? 0.55 : 0.45);
        Rifnote_Search_Source_Meta::stamp_origin($post_id, $origin_model ? $origin_model : 'Social Import', $origin_actor, $origin_channel);

        foreach (array('image_url' => 'rifnote_source_image_url', 'author_name' => 'rifnote_author_name', 'ai_summary' => 'ai_summary') as $payload_key => $meta_key) {
            if (!empty($story[$payload_key])) {
                $value = is_array($story[$payload_key]) ? wp_json_encode(self::sanitize_deep($story[$payload_key])) : Rifnote_Search_Source_Meta::normalize_text((string) $story[$payload_key], true);
                if ('rifnote_source_image_url' === $meta_key) {
                    $value = esc_url_raw((string) $story[$payload_key]);
                }
                update_post_meta($post_id, $meta_key, $value);
            }
        }

        if (!empty($story['entities'])) {
            update_post_meta($post_id, 'entities', wp_json_encode(self::sanitize_deep($story['entities'])));
        }

        if (!empty($story['ai_key_points'])) {
            update_post_meta($post_id, 'ai_key_points', wp_json_encode(self::sanitize_deep($story['ai_key_points'])));
        }

        Rifnote_Search_Aggregation::assign_category($post_id, $story['category'] ?? 'Social', array($title, $excerpt, $body, $source_name, $platform));

        $tags = is_array($story['tags'] ?? null) ? $story['tags'] : explode(',', (string) ($story['tags'] ?? ''));
        $tags[] = $platform;
        wp_set_post_terms($post_id, array_filter(array_map(array('Rifnote_Search_Source_Meta', 'normalize_text'), array_map('trim', $tags))), 'post_tag', false);

        if ('publish' === get_post_status($post_id)) {
            Rifnote_Search_Clustering::assign_post_cluster($post_id, get_post($post_id), true);
            Rifnote_Search_Index::index_post($post_id);
        }

        return array(
            'post_id' => (int) $post_id,
            'title' => $title,
            'status' => get_post_status($post_id),
            'platform' => $platform,
            'source_type' => $source_type,
            'edit_url' => get_edit_post_link($post_id, ''),
        );
    }

    public static function preview_url($url) {
        $url = esc_url_raw((string) $url);
        $platform = self::platform_from_url($url);
        $preview = array(
            'url' => $url,
            'platform' => $platform,
            'title' => '',
            'description' => '',
            'image_url' => '',
            'provider_name' => ucfirst($platform),
            'provider_url' => Rifnote_Search_Source_Meta::source_home_from_url($url),
            'author_name' => '',
            'embed_html' => '',
        );

        if (!$url) {
            return $preview;
        }

        // X/Twitter embeds are deterministic. Return immediately instead of
        // waiting for WordPress oEmbed and a full remote-page metadata scrape.
        if ('x' === $platform || 'twitter' === $platform) {
            $preview['embed_html'] = self::render_embed_for_url($url);
            return $preview;
        }

        $embed = wp_oembed_get($url, array('width' => 680));
        if ($embed) {
            $preview['embed_html'] = $embed;
        }

        if ('youtube' === $platform) {
            $response = wp_remote_get(add_query_arg(array('url' => $url, 'format' => 'json'), 'https://www.youtube.com/oembed'), array('timeout' => 8));
            if (!is_wp_error($response) && 200 === (int) wp_remote_retrieve_response_code($response)) {
                $data = json_decode((string) wp_remote_retrieve_body($response), true);
                if (is_array($data)) {
                    $preview['title'] = Rifnote_Search_Source_Meta::normalize_text($data['title'] ?? '');
                    $preview['provider_name'] = Rifnote_Search_Source_Meta::normalize_text($data['provider_name'] ?? 'YouTube');
                    $preview['provider_url'] = esc_url_raw($data['provider_url'] ?? 'https://www.youtube.com/');
                    $preview['author_name'] = Rifnote_Search_Source_Meta::normalize_text($data['author_name'] ?? '');
                    $preview['image_url'] = esc_url_raw($data['thumbnail_url'] ?? '');
                    $preview['embed_html'] = $data['html'] ?? $preview['embed_html'];
                }
            }
        }

        if (!$preview['embed_html']) {
            $preview['embed_html'] = self::render_embed_for_url($url, array('allow_remote_oembed' => true));
        }

        $response = wp_remote_get($url, array(
            'timeout' => 8,
            'headers' => array('User-Agent' => 'Rifnote Search Social Preview/' . RIFNOTE_SEARCH_VERSION),
            'redirection' => 3,
        ));

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return $preview;
        }

        $html = (string) wp_remote_retrieve_body($response);
        $preview['title'] = $preview['title'] ?: (self::html_meta($html, 'og:title') ?: self::html_title($html));
        $preview['description'] = $preview['description'] ?: (self::html_meta($html, 'og:description') ?: self::html_meta($html, 'description'));
        $preview['image_url'] = $preview['image_url'] ?: esc_url_raw(self::html_meta($html, 'og:image'));
        $preview['provider_name'] = self::html_meta($html, 'og:site_name') ?: $preview['provider_name'];

        return $preview;
    }

    public static function filter_content_embeds($content) {
        if (is_admin() || is_feed() || is_embed() || empty($content)) {
            return $content;
        }

        $content = preg_replace_callback(
            '~<p>\s*<a[^>]+href=["\'](https?://[^"\']+)["\'][^>]*>\s*\1\s*</a>\s*</p>~i',
            function ($matches) {
                $embed = self::render_embed_for_url($matches[1], array('allow_remote_oembed' => true));
                return $embed ? $embed : $matches[0];
            },
            (string) $content
        );

        $content = preg_replace_callback(
            '~<p>\s*(https?://[^\s<]+)\s*</p>~i',
            function ($matches) {
                $embed = self::render_embed_for_url(html_entity_decode($matches[1], ENT_QUOTES, get_bloginfo('charset')), array('allow_remote_oembed' => true));
                return $embed ? $embed : $matches[0];
            },
            (string) $content
        );

        return $content;
    }

    public static function render_embed_for_url($url, $args = array()) {
        $url = esc_url_raw((string) $url);

        if (!$url || !preg_match('~^https?://~i', $url)) {
            return '';
        }

        $platform = self::platform_from_url($url);
        $label = !empty($args['label']) ? Rifnote_Search_Source_Meta::normalize_text((string) $args['label']) : ucfirst($platform ? $platform : Rifnote_Search_Source_Meta::source_domain($url));
        $allow_remote_oembed = !empty($args['allow_remote_oembed']);
        $embed = '';

        if ('youtube' === $platform) {
            $video_id = self::platform_post_id($url, 'youtube');
            if ($video_id) {
                $embed = '<iframe src="https://www.youtube.com/embed/' . esc_attr($video_id) . '?rel=0&modestbranding=1&playsinline=1" title="' . esc_attr($label ? $label : __('YouTube video', 'rifnote-search')) . '" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>';
            }
        } elseif ('vimeo' === $platform) {
            $video_id = self::platform_post_id($url, 'vimeo');
            if ($video_id) {
                $embed = '<iframe src="https://player.vimeo.com/video/' . esc_attr($video_id) . '" title="' . esc_attr($label ? $label : __('Vimeo video', 'rifnote-search')) . '" loading="lazy" allow="autoplay; fullscreen; picture-in-picture; clipboard-write" allowfullscreen></iframe>';
            }
        } elseif ('x' === $platform || 'twitter' === $platform) {
            $post_id = self::platform_post_id($url, 'x');
            if ($post_id) {
                $embed = '<iframe src="https://platform.twitter.com/embed/Tweet.html?id=' . rawurlencode($post_id) . '&amp;dnt=true&amp;theme=light" title="' . esc_attr($label ? $label : __('X post', 'rifnote-search')) . '" width="550" height="420" loading="eager" scrolling="no" frameborder="0" allowfullscreen></iframe>';
            }
        } elseif ('instagram' === $platform) {
            $embed = '<blockquote class="instagram-media" data-instgrm-permalink="' . esc_url($url) . '" data-instgrm-version="14"><a href="' . esc_url($url) . '"></a></blockquote>';
        } elseif ('facebook' === $platform) {
            $embed = '<iframe src="https://www.facebook.com/plugins/post.php?href=' . rawurlencode($url) . '&show_text=true&width=680" title="' . esc_attr($label ? $label : __('Facebook post', 'rifnote-search')) . '" width="680" height="500" loading="lazy" scrolling="no" frameborder="0" allow="encrypted-media; clipboard-write; web-share" allowfullscreen></iframe>';
        } elseif ('tiktok' === $platform) {
            $post_id = self::platform_post_id($url, 'tiktok');
            $embed = '<blockquote class="tiktok-embed" cite="' . esc_url($url) . '"' . ($post_id ? ' data-video-id="' . esc_attr($post_id) . '"' : '') . ' style="max-width: 605px; min-width: 300px;"><section><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></section></blockquote>';
        } elseif ($allow_remote_oembed) {
            $oembed = wp_oembed_get($url, array('width' => 720));
            if ($oembed) {
                $embed = $oembed;
            }
        }

        if (!$embed) {
            $domain = Rifnote_Search_Source_Meta::source_domain($url);
            $embed = '<a class="rs-smart-social-card" href="' . esc_url($url) . '" target="_blank" rel="noreferrer"><span>' . esc_html($label ? $label : __('Open social post', 'rifnote-search')) . '</span><small>' . esc_html($domain) . '</small></a>';
        }

        return '<figure class="rs-smart-embed is-' . esc_attr($platform ? $platform : 'link') . '">' . wp_kses($embed, self::embed_allowed_html()) . '</figure>';
    }

    public static function embed_allowed_html() {
        $allowed = wp_kses_allowed_html('post');
        $allowed['iframe'] = array(
            'src' => true,
            'title' => true,
            'width' => true,
            'height' => true,
            'loading' => true,
            'frameborder' => true,
            'allow' => true,
            'allowfullscreen' => true,
            'referrerpolicy' => true,
            'scrolling' => true,
        );
        $allowed['blockquote'] = array(
            'class' => true,
            'data-dnt' => true,
            'data-theme' => true,
            'data-conversation' => true,
            'data-cards' => true,
            'data-instgrm-permalink' => true,
            'data-instgrm-version' => true,
            'data-instgrm-captioned' => true,
            'data-video-id' => true,
            'cite' => true,
            'style' => true,
        );
        $allowed['section'] = array(
            'class' => true,
            'style' => true,
        );
        $allowed['script'] = array(
            'async' => true,
            'src' => true,
            'charset' => true,
        );

        return $allowed;
    }

    public static function print_embed_scripts() {
        if (is_admin() || is_feed()) {
            return;
        }

        ?>
        <script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>
        <script async src="https://www.instagram.com/embed.js"></script>
        <script async src="https://www.tiktok.com/embed.js"></script>
        <script async src="https://www.threads.net/embed.js"></script>
        <?php
    }

    private static function generated_title($url, $platform, $preview = array()) {
        $provider = Rifnote_Search_Source_Meta::normalize_text((string) ($preview['provider_name'] ?? ''));
        $domain = Rifnote_Search_Source_Meta::source_domain($url);
        $label = $provider ? $provider : ($platform ? ucfirst($platform) : ($domain ? $domain : __('Social', 'rifnote-search')));
        $post_id = self::platform_post_id($url, $platform);

        if ($post_id) {
            return sprintf(__('%1$s post %2$s', 'rifnote-search'), $label, $post_id);
        }

        $path = wp_parse_url($url, PHP_URL_PATH);
        $path = $path ? trim((string) $path, '/') : '';
        $path = $path ? str_replace(array('-', '_', '/'), ' ', $path) : '';
        $path = Rifnote_Search_Source_Meta::normalize_text($path);

        return $path ? sprintf(__('%1$s: %2$s', 'rifnote-search'), $label, wp_trim_words($path, 10, '')) : sprintf(__('Social post from %s', 'rifnote-search'), $label);
    }

    private static function generated_description($url, $platform, $source_name = '') {
        $label = $source_name ? $source_name : ucfirst($platform ? $platform : 'social');
        $domain = Rifnote_Search_Source_Meta::source_domain($url);
        return sprintf(
            __('A %1$s post imported into Rifnote from %2$s. Open the original post for the full social context, replies and media.', 'rifnote-search'),
            $label,
            $domain ? $domain : __('the source platform', 'rifnote-search')
        );
    }

    public static function recent_social_posts($limit = 20) {
        return get_posts(array(
            'post_type' => 'post',
            'post_status' => array('publish', 'draft', 'pending', 'future', 'private'),
            'posts_per_page' => max(1, min(100, absint($limit))),
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => array(
                array(
                    'key' => 'source_type',
                    'value' => array('social', 'video'),
                    'compare' => 'IN',
                ),
            ),
        ));
    }

    public static function platform_from_url($url) {
        $domain = Rifnote_Search_Source_Meta::source_domain($url);
        if (false !== strpos($domain, 'youtube.com') || false !== strpos($domain, 'youtu.be')) {
            return 'youtube';
        }
        if (false !== strpos($domain, 'vimeo.com')) {
            return 'vimeo';
        }
        if (false !== strpos($domain, 'twitter.com') || false !== strpos($domain, 'x.com')) {
            return 'x';
        }
        if (false !== strpos($domain, 'instagram.com')) {
            return 'instagram';
        }
        if (false !== strpos($domain, 'tiktok.com')) {
            return 'tiktok';
        }
        if (false !== strpos($domain, 'facebook.com') || false !== strpos($domain, 'fb.watch')) {
            return 'facebook';
        }
        if (false !== strpos($domain, 'threads.net')) {
            return 'threads';
        }
        if (false !== strpos($domain, 'reddit.com')) {
            return 'reddit';
        }
        return $domain ? preg_replace('/\..*$/', '', $domain) : 'social';
    }

    private static function youtube_iframe($video_id) {
        $video_id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $video_id);
        if (!$video_id) {
            return '';
        }
        return '<iframe width="560" height="315" src="https://www.youtube.com/embed/' . esc_attr($video_id) . '" title="YouTube video player" frameborder="0" allowfullscreen></iframe>';
    }

    private static function platform_post_id($url, $platform) {
        $url = (string) $url;
        if ('youtube' === $platform) {
            if (preg_match('~(?:v=|youtu\.be/|shorts/|embed/)([A-Za-z0-9_-]{6,})~', $url, $matches)) {
                return $matches[1];
            }
        }
        if ('vimeo' === $platform && preg_match('~vimeo\.com/(?:video/)?([0-9]+)~', $url, $matches)) {
            return $matches[1];
        }
        if ('tiktok' === $platform && preg_match('~/video/([0-9]+)~', $url, $matches)) {
            return $matches[1];
        }
        if (preg_match('~/status/([0-9]+)~', $url, $matches)) {
            return $matches[1];
        }
        return '';
    }

    private static function html_title($html) {
        return preg_match('/<title[^>]*>(.*?)<\/title>/is', (string) $html, $matches) ? Rifnote_Search_Source_Meta::normalize_text($matches[1]) : '';
    }

    private static function html_meta($html, $name) {
        $name = preg_quote((string) $name, '/');
        if (preg_match('/<meta[^>]+(?:property|name)=["\']' . $name . '["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', (string) $html, $matches)) {
            return Rifnote_Search_Source_Meta::normalize_text($matches[1], true);
        }
        if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']' . $name . '["\'][^>]*>/i', (string) $html, $matches)) {
            return Rifnote_Search_Source_Meta::normalize_text($matches[1], true);
        }
        return '';
    }

    private static function sanitize_deep($value) {
        if (is_array($value)) {
            return array_map(array(__CLASS__, 'sanitize_deep'), $value);
        }
        return Rifnote_Search_Source_Meta::normalize_text((string) $value);
    }
}
