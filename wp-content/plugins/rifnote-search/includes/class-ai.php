<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_AI {
    public static function settings() {
        $api_key = defined('RIFNOTE_OPENAI_API_KEY') ? RIFNOTE_OPENAI_API_KEY : getenv('OPENAI_API_KEY');
        $api_key = $api_key ? $api_key : get_option('rifnote_openai_api_key', '');
        $model = defined('RIFNOTE_OPENAI_MODEL') ? RIFNOTE_OPENAI_MODEL : get_option('rifnote_openai_model', 'gpt-5.4-mini');

        return array(
            'api_key' => $api_key ? trim((string) $api_key) : '',
            'model' => $model ? sanitize_text_field($model) : 'gpt-5.4-mini',
            'cache_ttl' => max(300, min(DAY_IN_SECONDS / 4, (int) get_option('rifnote_ai_cache_ttl', 1800))),
            'max_answer_length' => max(280, min(1500, (int) get_option('rifnote_ai_max_answer_length', 900))),
            'enabled' => (bool) get_option('rifnote_ai_enabled', true),
        );
    }

    public static function source_set($results) {
        return array_map(function ($result) {
            return array(
                'id' => (int) $result['id'],
                'headline' => Rifnote_Search_Source_Meta::normalize_text(wp_strip_all_tags($result['headline'])),
                'snippet' => Rifnote_Search_Source_Meta::normalize_text(wp_strip_all_tags($result['excerpt']), true),
                'date' => $result['published_at'],
                'source_name' => Rifnote_Search_Source_Meta::normalize_text(wp_strip_all_tags($result['source_name'])),
                'url' => esc_url_raw(!empty($result['read_full_story_url']) ? $result['read_full_story_url'] : $result['original_url']),
            );
        }, array_slice($results, 0, 8));
    }

    public static function cache_key($query, $source_set) {
        $source_ids = array_map(function ($source) {
            return $source['id'];
        }, $source_set);

        return 'rifnote_ai_' . md5(self::normalize_query($query) . '|' . implode(',', $source_ids));
    }

    public static function normalize_query($query) {
        return strtolower(trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $query))));
    }

    public static function system_prompt() {
        return 'You are Rifnote Search. Answer only from the supplied source snippets. Do not invent facts. If sources are insufficient, say so. Keep the answer concise. Do not reproduce full articles. Always include source labels and recommend reading the full story at the source.';
    }

    public static function schema() {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'short_answer' => array('type' => 'string'),
                'key_points' => array('type' => 'array', 'items' => array('type' => 'string')),
                'sources' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => array('label' => array('type' => 'string'), 'url' => array('type' => 'string')),
                        'required' => array('label', 'url'),
                    ),
                ),
                'related_searches' => array('type' => 'array', 'items' => array('type' => 'string')),
            ),
            'required' => array('short_answer', 'key_points', 'sources', 'related_searches'),
        );
    }

    public static function extract_text($response_body) {
        if (!empty($response_body['output_text'])) {
            return $response_body['output_text'];
        }

        if (empty($response_body['output']) || !is_array($response_body['output'])) {
            return '';
        }

        foreach ($response_body['output'] as $output_item) {
            foreach (($output_item['content'] ?? array()) as $content_item) {
                if (!empty($content_item['text'])) {
                    return $content_item['text'];
                }
            }
        }

        return '';
    }

    public static function call_openai($query, $source_set, $settings) {
        $payload = array(
            'model' => $settings['model'],
            'store' => false,
            'input' => array(
                array('role' => 'system', 'content' => self::system_prompt()),
                array('role' => 'user', 'content' => wp_json_encode(array('query' => $query, 'sources' => $source_set, 'max_answer_length' => $settings['max_answer_length']))),
            ),
            'text' => array(
                'format' => array('type' => 'json_schema', 'name' => 'rifnote_ai_answer', 'strict' => true, 'schema' => self::schema()),
            ),
        );

        $response = wp_remote_post('https://api.openai.com/v1/responses', array(
            'timeout' => 25,
            'headers' => array('Authorization' => 'Bearer ' . $settings['api_key'], 'Content-Type' => 'application/json'),
            'body' => wp_json_encode($payload),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status < 200 || $status >= 300) {
            return new WP_Error('rifnote_ai_openai_error', __('OpenAI request failed.', 'rifnote-search'), array('status' => $status, 'body' => $body));
        }

        $decoded = json_decode(self::extract_text($body), true);

        if (!is_array($decoded)) {
            return new WP_Error('rifnote_ai_parse_error', __('OpenAI returned an unreadable answer.', 'rifnote-search'));
        }

        return $decoded;
    }

    public static function sanitize_answer($answer, $source_set, $query, $settings) {
        $allowed = array();

        foreach ($source_set as $source) {
            $allowed[$source['url']] = array('label' => $source['source_name'], 'url' => $source['url']);
        }

        $sources = array();

        foreach (($answer['sources'] ?? array()) as $source) {
            $url = esc_url_raw($source['url'] ?? '');

            if ($url && isset($allowed[$url])) {
                $sources[$url] = array(
                    'label' => sanitize_text_field($source['label'] ?? $allowed[$url]['label']),
                    'url' => $url,
                );
            }
        }

        if (!$sources) {
            $sources = array_slice($allowed, 0, 4);
        }

        $short_answer = sanitize_textarea_field($answer['short_answer'] ?? '');

        if (strlen($short_answer) > $settings['max_answer_length']) {
            $short_answer = substr($short_answer, 0, $settings['max_answer_length'] - 3) . '...';
        }

        return array(
            'available' => true,
            'cached' => false,
            'query' => $query,
            'short_answer' => $short_answer,
            'key_points' => array_slice(array_map('sanitize_text_field', $answer['key_points'] ?? array()), 0, 5),
            'sources' => array_values($sources),
            'related_searches' => array_slice(array_map('sanitize_text_field', $answer['related_searches'] ?? array()), 0, 4),
            'source_ids' => array_map(function ($source) {
                return (int) $source['id'];
            }, $source_set),
        );
    }

    public static function answer_payload($query, $request_args) {
        if ('' === trim($query)) {
            return array('available' => false, 'reason' => 'missing_query', 'message' => __('Search something first, then Rifnote can pull a quick take.', 'rifnote-search'));
        }

        $search_payload = Rifnote_Search_Engine::payload($request_args, 1, 8);
        $source_set = self::source_set($search_payload['results']);

        if (!$source_set) {
            return array('available' => false, 'reason' => 'insufficient_sources', 'message' => __('Not enough solid sources yet, so we skipped the AI take for now.', 'rifnote-search'), 'sources' => array());
        }

        $settings = self::settings();

        if (!$settings['enabled'] || !$settings['api_key']) {
            return array('available' => false, 'reason' => 'not_configured', 'message' => __('AI takes are switched off for now.', 'rifnote-search'), 'sources' => $source_set);
        }

        $cache_key = self::cache_key($query, $source_set);
        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            $cached['cached'] = true;
            return $cached;
        }

        $answer = self::call_openai($query, $source_set, $settings);

        if (is_wp_error($answer)) {
            return $answer;
        }

        $response = self::sanitize_answer($answer, $source_set, $query, $settings);

        set_transient($cache_key, $response, $settings['cache_ttl']);

        return $response;
    }
}
