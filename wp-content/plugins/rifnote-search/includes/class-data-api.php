<?php
/**
 * Data engine bridge for the external Rifnote warehouse.
 *
 * @package Rifnote_Search
 */

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Data_API {
    const DEFAULT_TIMEOUT = 8;
    const DEFAULT_CACHE_TTL = 120;

    public static function enabled() {
        return (bool) get_option('rifnote_data_api_enabled', false) && self::base_url() && self::token();
    }

    public static function merge_search_enabled() {
        return self::enabled() && (bool) get_option('rifnote_data_api_merge_search', true);
    }

    public static function base_url() {
        $url = trim((string) get_option('rifnote_data_api_url', ''));

        if (!$url) {
            $env = getenv('RIFNOTE_DATA_API_URL');
            $url = $env ? (string) $env : '';
        }

        return $url ? untrailingslashit(esc_url_raw($url)) : '';
    }

    public static function token() {
        $token = trim((string) get_option('rifnote_data_api_token', ''));

        if (!$token) {
            $env = getenv('RIFNOTE_DATA_API_TOKEN');
            $token = $env ? (string) $env : '';
        }

        return trim($token);
    }

    public static function token_fingerprint() {
        $token = self::token();

        return $token ? substr(hash('sha256', $token), 0, 12) : '';
    }

    public static function timeout() {
        return max(3, min(20, absint(get_option('rifnote_data_api_timeout', self::DEFAULT_TIMEOUT))));
    }

    public static function cache_ttl() {
        return max(0, min(900, absint(get_option('rifnote_data_api_cache_ttl', self::DEFAULT_CACHE_TTL))));
    }

    public static function health($force = false) {
        if (!self::base_url()) {
            return array(
                'ok' => false,
                'message' => __('Data API URL is not configured.', 'rifnote-search'),
            );
        }

        return self::request('/v1/health', array(), 'health', $force);
    }

    public static function search_payload($request_args, $limit = 20) {
        if (!self::merge_search_enabled() || empty($request_args['query'])) {
            return array();
        }

        $query = array(
            'q' => (string) $request_args['query'],
            'limit' => max(1, min(50, absint($limit))),
        );

        if (!empty($request_args['category']) && 'all' !== $request_args['category']) {
            $query['category'] = sanitize_key($request_args['category']);
        }

        $response = self::request('/v1/stories/search', $query, 'search_' . md5(wp_json_encode($query)));

        if (empty($response['ok']) || empty($response['items']) || !is_array($response['items'])) {
            return array();
        }

        $items = array();

        foreach ($response['items'] as $item) {
            $payload = self::normalize_story($item, $request_args);

            if ($payload) {
                $items[] = $payload;
            }
        }

        return $items;
    }

    public static function stats($force = false) {
        if (!self::enabled()) {
            return array(
                'ok' => false,
                'message' => __('Data API is not enabled or is missing credentials.', 'rifnote-search'),
            );
        }

        return self::request('/v1/admin/stats', array(), 'admin_stats', $force);
    }

    public static function ingest_rss_batch($feeds) {
        if (!self::enabled()) {
            return array(
                'ok' => false,
                'message' => __('Data API is not enabled or is missing credentials.', 'rifnote-search'),
            );
        }

        $payload = array('feeds' => array());
        foreach ((array) $feeds as $feed) {
            if (empty($feed['feed_url']) && empty($feed['rss_feed_url'])) {
                continue;
            }

            $payload['feeds'][] = array(
                'name' => Rifnote_Search_Source_Meta::normalize_text((string) ($feed['name'] ?? $feed['publisher_name'] ?? '')),
                'feed_url' => esc_url_raw((string) ($feed['feed_url'] ?? $feed['rss_feed_url'])),
                'source_url' => esc_url_raw((string) ($feed['source_url'] ?? $feed['website_url'] ?? '')),
                'category' => sanitize_key((string) ($feed['category'] ?? $feed['categories'] ?? 'news')),
                'items_per_feed' => max(1, min(30, absint($feed['items_per_feed'] ?? get_option('rifnote_smart_rss_items_per_feed', 10)))),
                'timeout' => max(3, min(20, absint($feed['timeout'] ?? get_option('rifnote_smart_rss_timeout', 8)))),
                'language_code' => sanitize_key((string) ($feed['language_code'] ?? 'en')),
                'country_code' => strtoupper(sanitize_key((string) ($feed['country_code'] ?? ''))),
            );
        }

        if (empty($payload['feeds'])) {
            return array(
                'ok' => false,
                'message' => __('No valid RSS feeds were prepared for the Data API.', 'rifnote-search'),
            );
        }

        return self::request('/v1/ingest/rss/batch', array(), '', true, 'POST', $payload);
    }

    private static function request($path, $query = array(), $cache_key = '', $force = false, $method = 'GET', $body = null) {
        if (!self::base_url()) {
            return array('ok' => false, 'message' => __('Missing Data API URL.', 'rifnote-search'));
        }

        if (!self::token()) {
            return array('ok' => false, 'message' => __('Missing Data API token.', 'rifnote-search'));
        }

        $path = '/' . ltrim((string) $path, '/');
        $method = strtoupper((string) $method);
        $url = add_query_arg(array_filter($query, function ($value) {
            return '' !== $value && null !== $value;
        }), self::base_url() . $path);

        $transient_key = ('GET' === $method && $cache_key) ? 'rifnote_data_api_' . md5($cache_key . '|' . $url) : '';

        if (!$force && $transient_key && self::cache_ttl() > 0) {
            $cached = get_transient($transient_key);

            if (false !== $cached) {
                return is_array($cached) ? $cached : array('ok' => false, 'message' => __('Invalid cached Data API response.', 'rifnote-search'));
            }
        }

        $token = self::token();
        $headers = array(
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
            'X-Rifnote-Token' => $token,
            'X-API-Key' => $token,
        );
        $headers = apply_filters('rifnote_data_api_headers', $headers, $token, $path, $method);

        $args = array(
            'method' => $method,
            'timeout' => self::timeout(),
            'headers' => $headers,
        );

        if ('GET' !== $method) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode(is_array($body) ? $body : array());
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $result = array('ok' => false, 'message' => $response->get_error_message());
        } else {
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = json_decode((string) wp_remote_retrieve_body($response), true);

            if ($code < 200 || $code >= 300) {
                $message = sprintf(__('Data API returned HTTP %d.', 'rifnote-search'), $code);

                if (401 === $code) {
                    $message = __('Data API rejected the token (HTTP 401). Re-save the warehouse token or sync it with the data engine token.', 'rifnote-search');
                } elseif (403 === $code) {
                    $message = __('Data API request was blocked (HTTP 403). Check Cloudflare/firewall rules and allow server-to-server API calls.', 'rifnote-search');
                }

                $result = array(
                    'ok' => false,
                    'message' => $message,
                    'status' => $code,
                );
            } elseif (!is_array($body)) {
                $result = array('ok' => false, 'message' => __('Data API returned invalid JSON.', 'rifnote-search'));
            } else {
                $result = array_merge(array('ok' => true), $body);
            }
        }

        update_option('rifnote_data_api_last_check', array(
            'checked_at' => gmdate('c'),
            'ok' => !empty($result['ok']),
            'message' => (string) ($result['message'] ?? ($result['ok'] ? 'OK' : 'Unknown response')),
            'url' => esc_url_raw($url),
            'status' => isset($result['status']) ? absint($result['status']) : 0,
            'token_present' => (bool) $token,
            'token_fingerprint' => self::token_fingerprint(),
            'auth_headers' => array_values(array_intersect(array_keys($headers), array('Authorization', 'X-Rifnote-Token', 'X-API-Key'))),
        ), false);

        if ($transient_key && self::cache_ttl() > 0) {
            set_transient($transient_key, $result, self::cache_ttl());
        }

        return $result;
    }

    private static function normalize_story($item, $request_args) {
        if (!is_array($item)) {
            return null;
        }

        $title = Rifnote_Search_Source_Meta::normalize_text((string) ($item['title'] ?? ''));

        if (!$title) {
            return null;
        }

        $source = is_array($item['source'] ?? null) ? $item['source'] : array();
        $media = is_array($item['media'] ?? null) ? $item['media'] : array();
        $url = esc_url_raw((string) ($item['canonical_url'] ?? $item['url'] ?? ''));
        $source_url = esc_url_raw((string) ($source['url'] ?? ''));
        $source_domain = self::domain($source_url ? $source_url : $url);
        $published_at = self::published_at($item);
        $score = self::score_story($item, $request_args, $published_at);
        $wp_post_id = !empty($item['wp_post_id']) ? absint($item['wp_post_id']) : 0;
        $local_url = $wp_post_id && get_post($wp_post_id) ? get_permalink($wp_post_id) : '';
        $display_url = $local_url ? $local_url : $url;

        return array(
            'id' => 'data_' . sanitize_key((string) ($item['id'] ?? md5($url . $title))),
            'headline' => $title,
            'excerpt' => Rifnote_Search_Source_Meta::normalize_text(self::excerpt($item), true),
            'image' => esc_url_raw((string) ($media['image'] ?? '')),
            'published_at' => $published_at,
            'published_at_human' => self::human_time($published_at),
            'category' => Rifnote_Search_Source_Meta::normalize_text((string) ($item['category'] ?? __('News', 'rifnote-search'))),
            'category_slug' => sanitize_key((string) ($item['category_slug'] ?? $item['category'] ?? 'news')),
            'tags' => array_values(array_filter(array_map('sanitize_text_field', (array) ($item['tags'] ?? array())))),
            'cluster_id' => 'data_' . sanitize_key((string) ($item['id'] ?? md5($url . $title))),
            'has_story_hub' => false,
            'aggregation_source' => 'data-api',
            'normalized_headline' => $title,
            'permalink' => esc_url_raw($display_url),
            'share_url' => esc_url_raw($display_url),
            'score' => $score,
            'source_name' => Rifnote_Search_Source_Meta::normalize_text((string) ($source['name'] ?? ($source_domain ?: __('External source', 'rifnote-search')))),
            'source_url' => $source_url ? $source_url : ($url ? Rifnote_Search_Source_Meta::source_home_from_url($url) : ''),
            'source_domain' => $source_domain,
            'source_logo_url' => esc_url_raw((string) ($source['logo_url'] ?? $source['logo'] ?? '')),
            'source_initials' => Rifnote_Search_Source_Meta::source_initials((string) ($source['name'] ?? ''), $source_domain),
            'original_url' => $url,
            'read_full_story_url' => $url,
            'canonical_url' => $url,
            'publisher_id' => 0,
            'source_type' => sanitize_key((string) ($item['source_type'] ?? 'external')),
            'has_external_source' => true,
            'is_data_api_result' => true,
            'source_profile_url' => $source_domain ? home_url('/source/' . rawurlencode($source_domain) . '/') : '',
            'why_this_result' => array(),
            'claims' => array(),
            'schema' => array(),
        );
    }

    private static function excerpt($item) {
        $text = (string) ($item['description'] ?? $item['summary'] ?? $item['content'] ?? '');
        $text = wp_strip_all_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return wp_trim_words($text, 23, '...');
    }

    private static function published_at($item) {
        $value = (string) ($item['published_at'] ?? $item['discovered_at'] ?? '');
        $timestamp = $value ? strtotime($value) : 0;

        return $timestamp ? gmdate(DATE_ATOM, $timestamp) : gmdate(DATE_ATOM);
    }

    private static function human_time($published_at) {
        $timestamp = strtotime((string) $published_at);

        if (!$timestamp) {
            return __('just now', 'rifnote-search');
        }

        return sprintf(__('%s ago', 'rifnote-search'), human_time_diff($timestamp, current_time('timestamp', true)));
    }

    private static function score_story($item, $request_args, $published_at) {
        $score = isset($item['score']) ? (float) $item['score'] : 0.55;
        $published = strtotime((string) $published_at);

        if ($published) {
            $age_hours = max(0, (time() - $published) / HOUR_IN_SECONDS);
            $score += max(0, 0.2 - min(0.2, $age_hours / 240));
        }

        if (!empty($request_args['query'])) {
            $haystack = strtolower((string) ($item['title'] ?? '') . ' ' . (string) ($item['description'] ?? ''));
            $query = strtolower((string) $request_args['query']);

            if (false !== strpos($haystack, $query)) {
                $score += 0.15;
            }
        }

        return round(min(1, max(0, $score)), 4);
    }

    private static function domain($url) {
        $host = $url ? wp_parse_url($url, PHP_URL_HOST) : '';
        $host = is_string($host) ? strtolower($host) : '';

        return preg_replace('/^www\./', '', $host);
    }
}
