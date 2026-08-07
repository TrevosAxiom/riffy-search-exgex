<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Aggregation {
    const OPTION_KEY = 'rifnote_manual_aggregations';
    const CATEGORY_VERSION = '2026-07-12-1';
    const CATEGORY_REPAIR_VERSION = '2026-07-19-2';

    public static function categories() {
        return array(
            'Breaking News' => 'Fast-moving stories and urgent updates.',
            'Football' => 'Live scores, fixtures, transfers, clubs, players and matchday coverage.',
            'Sports' => 'Non-football sports, tournaments, athletes and results.',
            'Politics' => 'Government, elections, policy, parties and public office.',
            'Nigeria' => 'Nigeria-focused national, state, city and culture coverage.',
            'Africa' => 'Pan-African politics, business, culture, sport and regional news.',
            'World' => 'International affairs, diplomacy, conflict, climate and global events.',
            'Business' => 'Markets, companies, startups, money, work and the economy.',
            'Tech' => 'Technology, AI, gadgets, startups, platforms and digital culture.',
            'Entertainment' => 'Music, film, TV, creators, celebrities and pop culture.',
            'Culture' => 'Arts, books, fashion, food, identity and youth culture.',
            'Lifestyle' => 'Health-adjacent living, travel, relationships, homes and daily life.',
            'Health' => 'Public health, medicine, wellness, disease and healthcare systems.',
            'Science' => 'Research, space, environment, discoveries and explainers.',
            'Education' => 'Schools, universities, policy, exams and learning.',
            'Finance' => 'Personal finance, currencies, banking, crypto and investing.',
            'Opinion' => 'Columns, analysis, editorials and commentary.',
            'Fact Check' => 'Verification, misinformation checks and evidence-backed corrections.',
        );
    }

    public static function normalize_category($category = '', $context = array()) {
        $raw = trim((string) $category);
        $text = strtolower(trim(wp_strip_all_tags($raw . ' ' . self::context_text($context))));
        $slug = sanitize_title($raw);

        $aliases = array(
            'news' => 'Breaking News',
            'general' => 'Breaking News',
            'top' => 'Breaking News',
            'top stories' => 'Breaking News',
            'breaking' => 'Breaking News',
            'breaking-news' => 'Breaking News',
            'national' => 'Nigeria',
            'nigeria news' => 'Nigeria',
            'naija' => 'Nigeria',
            'local' => 'Nigeria',
            'africa news' => 'Africa',
            'world news' => 'World',
            'international' => 'World',
            'global' => 'World',
            'politics news' => 'Politics',
            'political' => 'Politics',
            'election' => 'Politics',
            'elections' => 'Politics',
            'sports' => 'Sports',
            'sport' => 'Sports',
            'football' => 'Football',
            'soccer' => 'Football',
            'livescore' => 'Football',
            'live scores' => 'Football',
            'transfer' => 'Football',
            'transfers' => 'Football',
            'business news' => 'Business',
            'economy' => 'Business',
            'markets' => 'Business',
            'market' => 'Business',
            'finance' => 'Finance',
            'money' => 'Finance',
            'crypto' => 'Finance',
            'tech news' => 'Tech',
            'technology' => 'Tech',
            'ai' => 'Tech',
            'science' => 'Science',
            'health' => 'Health',
            'entertainment news' => 'Entertainment',
            'showbiz' => 'Entertainment',
            'music' => 'Entertainment',
            'celebrity' => 'Entertainment',
            'lifestyle' => 'Lifestyle',
            'travel' => 'Lifestyle',
            'food' => 'Lifestyle',
            'culture' => 'Culture',
            'education' => 'Education',
            'opinion' => 'Opinion',
            'analysis' => 'Opinion',
            'fact check' => 'Fact Check',
            'fact-check' => 'Fact Check',
        );

        if (isset($aliases[$slug])) {
            return $aliases[$slug];
        }

        foreach (self::categories() as $name => $description) {
            if ($slug && sanitize_title($name) === $slug) {
                return $name;
            }
        }

        $keyword_rules = array(
            'Football' => '/\b(football|soccer|premier league|champions league|europa league|fifa|uefa|afcon|world cup|goal|striker|fixture|livescore|transfer|chelsea|arsenal|liverpool|man city|manchester united|real madrid|barcelona|psg|napoli|mbappe|messi|ronaldo|osimhen)\b/i',
            'Politics' => '/\b(president|governor|senate|house of reps|minister|election|tribunal|campaign|policy|government|lawmaker|court ruling|white house|congress|parliament)\b/i',
            'Nigeria' => '/\b(nigeria|lagos|abuja|kano|rivers|port harcourt|ibadan|enugu|anambra|naira|inec|tinubu|fg|nigerian)\b/i',
            'Africa' => '/\b(africa|ghana|kenya|south africa|egypt|morocco|ethiopia|au|ecowas)\b/i',
            'Business' => '/\b(company|market|economy|stocks|oil|bank|startup|funding|inflation|currency|business|trade|revenue|profit)\b/i',
            'Finance' => '/\b(crypto|bitcoin|ethereum|forex|loan|interest rate|personal finance|investment|investor)\b/i',
            'Tech' => '/\b(technology|tech|ai|artificial intelligence|openai|apple|google|meta|software|app|gadget|cyber|startup)\b/i',
            'Entertainment' => '/\b(music|movie|film|album|celebrity|actor|actress|nollywood|hollywood|concert|artist|bbnaija)\b/i',
            'Health' => '/\b(health|hospital|doctor|medicine|disease|virus|vaccine|medical|patient)\b/i',
            'Science' => '/\b(science|space|research|climate|discovery|study|nasa|environment)\b/i',
            'Education' => '/\b(school|university|student|exam|teacher|education|jamb|waec)\b/i',
        );

        foreach ($keyword_rules as $name => $pattern) {
            if (preg_match($pattern, $text)) {
                return $name;
            }
        }

        return 'Breaking News';
    }

    private static function context_text($context) {
        $parts = array();
        $context = (array) $context;

        array_walk_recursive($context, function ($value) use (&$parts) {
            if (is_scalar($value)) {
                $parts[] = (string) $value;
            }
        });

        return implode(' ', array_filter($parts));
    }

    public static function assign_category($post_id, $category = '', $context = array()) {
        self::ensure_categories();

        $name = self::normalize_category($category, $context);
        $term = term_exists($name, 'category');
        $term_id = $term ? (is_array($term) ? (int) $term['term_id'] : (int) $term) : 0;

        if (!$term) {
            $created = wp_insert_term($name, 'category', array('slug' => sanitize_title($name)));
            if (is_wp_error($created)) {
                return $name;
            }
            $term_id = (int) ($created['term_id'] ?? 0);
        }

        if ($term_id) {
            wp_set_post_terms((int) $post_id, array($term_id), 'category', false);
        }
        update_post_meta((int) $post_id, 'rifnote_normalized_category', $name);

        return $name;
    }

    public static function repair_post_categories($limit = 500) {
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => array('publish', 'draft', 'pending', 'future', 'private'),
            'posts_per_page' => max(1, min(2000, (int) $limit)),
            'orderby' => 'date',
            'order' => 'DESC',
        ));

        $updated = 0;

        foreach ($posts as $post) {
            $terms = wp_get_post_terms($post->ID, 'category', array('fields' => 'names'));
            $current = $terms ? $terms[0] : '';
            $normalized = self::assign_category($post->ID, $current, array(
                $post->post_title,
                $post->post_excerpt,
                get_post_meta($post->ID, 'source_name', true),
                get_post_meta($post->ID, 'ai_summary', true),
            ));

            if (!$current || $normalized !== $current) {
                $updated++;
            }

            if ('publish' === $post->post_status) {
                Rifnote_Search_Index::index_post($post->ID);
            }
        }

        update_option('rifnote_category_repair_last_run', array(
            'updated' => $updated,
            'checked' => count($posts),
            'ran_at' => current_time('mysql', true),
        ), false);

        return array('updated' => $updated, 'checked' => count($posts));
    }

    public static function ensure_categories($force = false) {
        $installed = (string) get_option('rifnote_seeded_categories_version', '');

        if (!$force && self::CATEGORY_VERSION === $installed) {
            return array('created' => 0, 'existing' => count(self::categories()));
        }

        $created = 0;
        $existing = 0;

        foreach (self::categories() as $name => $description) {
            $term = term_exists($name, 'category');

            if ($term) {
                $existing++;
                $term_id = is_array($term) ? (int) $term['term_id'] : (int) $term;
                if ($term_id && !get_term_field('description', $term_id, 'category')) {
                    wp_update_term($term_id, 'category', array('description' => $description));
                }
                update_term_meta($term_id, 'rifnote_seeded_category', 1);
                continue;
            }

            $result = wp_insert_term($name, 'category', array(
                'slug' => sanitize_title($name),
                'description' => $description,
            ));

            if (!is_wp_error($result) && !empty($result['term_id'])) {
                $created++;
                update_term_meta((int) $result['term_id'], 'rifnote_seeded_category', 1);
            }
        }

        update_option('rifnote_seeded_categories_version', self::CATEGORY_VERSION, false);

        return array('created' => $created, 'existing' => $existing);
    }

    public static function maybe_repair_categories() {
        if (get_option('rifnote_category_repair_version') === self::CATEGORY_REPAIR_VERSION) {
            return;
        }

        self::repair_post_categories(2000);
        update_option('rifnote_category_repair_version', self::CATEGORY_REPAIR_VERSION, false);
    }

    public static function all() {
        $items = get_option(self::OPTION_KEY, array());
        return is_array($items) ? $items : array();
    }

    public static function get($cluster_id) {
        $items = self::all();
        return isset($items[$cluster_id]) && is_array($items[$cluster_id]) ? $items[$cluster_id] : null;
    }

    public static function save($data) {
        $title = Rifnote_Search_Source_Meta::normalize_text((string) ($data['title'] ?? ''));

        if (!$title) {
            return new WP_Error('rifnote_aggregation_missing_title', __('Aggregation title is required.', 'rifnote-search'));
        }

        $cluster_id = sanitize_title((string) ($data['cluster_id'] ?? ''));
        if (!$cluster_id) {
            $cluster_id = 'manual_' . substr(md5($title . '|' . microtime(true)), 0, 12);
        }

        if (0 !== strpos($cluster_id, 'manual_') && 0 !== strpos($cluster_id, 'cluster_')) {
            $cluster_id = 'manual_' . $cluster_id;
        }

        $items = self::all();
        $existing = isset($items[$cluster_id]) && is_array($items[$cluster_id]) ? $items[$cluster_id] : array();
        $post_ids = self::parse_post_ids($data['post_ids'] ?? ($existing['post_ids'] ?? array()));

        $record = array_merge($existing, array(
            'cluster_id' => $cluster_id,
            'title' => $title,
            'summary' => Rifnote_Search_Source_Meta::normalize_text((string) ($data['summary'] ?? ($existing['summary'] ?? '')), true),
            'category' => Rifnote_Search_Source_Meta::normalize_text((string) ($data['category'] ?? ($existing['category'] ?? ''))),
            'image_url' => esc_url_raw((string) ($data['image_url'] ?? ($existing['image_url'] ?? ''))),
            'status' => self::sanitize_status($data['status'] ?? ($existing['status'] ?? 'draft')),
            'post_ids' => $post_ids,
            'editorial_notes' => Rifnote_Search_Source_Meta::normalize_text((string) ($data['editorial_notes'] ?? ($existing['editorial_notes'] ?? '')), true),
            'updated_at' => current_time('mysql', true),
            'updated_by' => get_current_user_id(),
        ));

        if (empty($record['created_at'])) {
            $record['created_at'] = current_time('mysql', true);
            $record['created_by'] = get_current_user_id();
        }

        $items[$cluster_id] = $record;
        update_option(self::OPTION_KEY, $items, false);

        if ($post_ids) {
            self::assign_posts($cluster_id, $post_ids, $record);
        }

        Rifnote_Search_CustomGPT_Import::log_event(array(
            'event' => 'manual_aggregation_saved',
            'status' => 'ok',
            'cluster_id' => $cluster_id,
            'post_count' => count($post_ids),
        ));

        return $record;
    }

    public static function delete($cluster_id) {
        $cluster_id = sanitize_text_field($cluster_id);
        $items = self::all();

        if (!isset($items[$cluster_id])) {
            return false;
        }

        unset($items[$cluster_id]);
        update_option(self::OPTION_KEY, $items, false);
        return true;
    }

    public static function customgpt_batch($payload) {
        $clusters = isset($payload['clusters']) && is_array($payload['clusters']) ? $payload['clusters'] : array();
        $stories = isset($payload['stories']) && is_array($payload['stories']) ? $payload['stories'] : array();
        $source = Rifnote_Search_Source_Meta::normalize_text((string) ($payload['source'] ?? 'CustomGPT Aggregation'));
        $batch_id = Rifnote_Search_Source_Meta::normalize_text((string) ($payload['batch_id'] ?? 'aggregation_' . substr(hash('sha256', wp_json_encode($payload) . microtime(true)), 0, 10)));

        if (!$clusters && $stories) {
            $grouped = array();

            foreach ($stories as $story) {
                if (!is_array($story)) {
                    continue;
                }
                $cluster_id = sanitize_title((string) ($story['story_cluster_id'] ?? $story['cluster_id'] ?? ''));
                if (!$cluster_id) {
                    continue;
                }
                if (!isset($grouped[$cluster_id])) {
                    $grouped[$cluster_id] = array(
                        'cluster_id' => $cluster_id,
                        'title' => $story['aggregation_title'] ?? $story['cluster_title'] ?? $story['title'] ?? $cluster_id,
                        'summary' => $story['aggregation_summary'] ?? $story['ai_summary'] ?? $story['excerpt'] ?? '',
                        'category' => $story['category'] ?? '',
                        'image_url' => $story['image_url'] ?? '',
                        'status' => $payload['status'] ?? 'draft',
                        'stories' => array(),
                    );
                }
                $grouped[$cluster_id]['stories'][] = $story;
            }

            $clusters = array_values($grouped);
        }

        $summary = array(
            'ok' => true,
            'source' => $source,
            'batch_id' => $batch_id,
            'received_clusters' => count($clusters),
            'saved' => 0,
            'attached_posts' => 0,
            'errors' => 0,
            'messages' => array(),
            'clusters' => array(),
            'ran_at' => gmdate(DATE_ATOM),
        );

        if (!$clusters) {
            $summary['ok'] = false;
            $summary['errors']++;
            $summary['messages'][] = __('No aggregation clusters or stories were provided.', 'rifnote-search');
            Rifnote_Search_CustomGPT_Import::log_event(array_merge($summary, array('event' => 'customgpt_aggregation_rejected', 'status' => 'error')));
            return new WP_Error('rifnote_customgpt_empty_aggregation', __('No aggregation clusters or stories were provided.', 'rifnote-search'), array('status' => 400));
        }

        foreach (array_slice($clusters, 0, 50) as $cluster) {
            if (!is_array($cluster)) {
                continue;
            }

            $cluster_stories = isset($cluster['stories']) && is_array($cluster['stories']) ? $cluster['stories'] : array();
            $post_ids = self::parse_post_ids($cluster['post_ids'] ?? array());

            foreach ($cluster_stories as $story) {
                if (!is_array($story)) {
                    continue;
                }
                $post_id = absint($story['post_id'] ?? 0);
                if ($post_id) {
                    $post_ids[] = $post_id;
                }
            }

            $record = self::save(array(
                'cluster_id' => $cluster['cluster_id'] ?? $cluster['story_cluster_id'] ?? '',
                'title' => $cluster['title'] ?? $cluster['aggregation_title'] ?? '',
                'summary' => $cluster['summary'] ?? $cluster['aggregation_summary'] ?? '',
                'category' => $cluster['category'] ?? '',
                'image_url' => $cluster['image_url'] ?? '',
                'status' => $cluster['status'] ?? ($payload['status'] ?? 'draft'),
                'post_ids' => array_values(array_unique(array_filter(array_map('absint', $post_ids)))),
                'editorial_notes' => $cluster['editorial_notes'] ?? sprintf(__('Created by %1$s in batch %2$s.', 'rifnote-search'), $source, $batch_id),
            ));

            if (is_wp_error($record)) {
                $summary['errors']++;
                $summary['messages'][] = $record->get_error_message();
                continue;
            }

            $attached = 0;
            foreach (($record['post_ids'] ?? array()) as $post_id) {
                update_post_meta($post_id, 'rifnote_gpt_aggregation_id', $record['cluster_id']);
                update_post_meta($post_id, 'rifnote_aggregation_source', 'customgpt');
                update_post_meta($post_id, 'rifnote_customgpt_aggregation_batch', $batch_id);
                Rifnote_Search_Source_Meta::stamp_origin($post_id, get_post_meta($post_id, 'rifnote_origin_model', true) ?: 'GPT', get_post_meta($post_id, 'rifnote_origin_actor', true) ?: $source, get_post_meta($post_id, 'rifnote_origin_channel', true) ?: 'customgpt_aggregation');
                $attached++;
            }

            foreach ($cluster_stories as $story) {
                if (!is_array($story) || empty($story['post_id'])) {
                    continue;
                }
                $post_id = absint($story['post_id']);
                if (!empty($story['ai_summary'])) {
                    update_post_meta($post_id, 'ai_summary', Rifnote_Search_Source_Meta::normalize_text((string) $story['ai_summary'], true));
                }
                if (!empty($story['ai_key_points'])) {
                    update_post_meta($post_id, 'ai_key_points', wp_json_encode(self::sanitize_deep($story['ai_key_points'])));
                }
                if (!empty($story['entities'])) {
                    update_post_meta($post_id, 'entities', wp_json_encode(self::sanitize_deep($story['entities'])));
                }
                Rifnote_Search_Index::index_post($post_id);
            }

            $summary['saved']++;
            $summary['attached_posts'] += $attached;
            $summary['clusters'][] = array(
                'cluster_id' => $record['cluster_id'],
                'title' => $record['title'],
                'post_ids' => array_values(array_map('absint', $record['post_ids'] ?? array())),
                'url' => home_url('/story/' . rawurlencode($record['cluster_id']) . '/'),
            );
        }

        if (!empty($payload['trending_signals']) && is_array($payload['trending_signals'])) {
            $summary['trending'] = Rifnote_Search_Trending::add_signals($payload['trending_signals'], array(
                'source_model' => 'GPT',
                'source_actor' => $source,
                'batch_id' => $batch_id,
            ));
        }

        $summary['ok'] = 0 === (int) $summary['errors'];
        Rifnote_Search_CustomGPT_Import::log_event(array_merge($summary, array(
            'event' => 'customgpt_aggregation_imported',
            'status' => $summary['ok'] ? 'ok' : 'partial',
        )));
        update_option('rifnote_customgpt_aggregation_last_run', $summary, false);

        return $summary;
    }

    public static function assign_posts($cluster_id, $post_ids, $record = array()) {
        $cluster_id = sanitize_text_field($cluster_id);
        $post_ids = self::parse_post_ids($post_ids);
        $updated = 0;

        foreach ($post_ids as $post_id) {
            if ('post' !== get_post_type($post_id)) {
                continue;
            }

            update_post_meta($post_id, 'story_cluster_id', $cluster_id);
            update_post_meta($post_id, 'rifnote_manual_aggregation_id', $cluster_id);
            update_post_meta($post_id, 'rifnote_aggregation_source', 'manual');
            update_post_meta($post_id, 'rifnote_manual_aggregation_updated_at', current_time('mysql', true));

            if (!empty($record['title'])) {
                update_post_meta($post_id, 'rifnote_manual_aggregation_title', Rifnote_Search_Source_Meta::normalize_text($record['title']));
            }

            if (!empty($record['summary']) && !get_post_meta($post_id, 'ai_summary', true)) {
                update_post_meta($post_id, 'ai_summary', Rifnote_Search_Source_Meta::normalize_text($record['summary'], true));
            }

            if (!empty($record['category'])) {
                self::assign_category($post_id, $record['category'], array(
                    get_the_title($post_id),
                    get_the_excerpt($post_id),
                    get_post_meta($post_id, 'source_name', true),
                ));
            }

            Rifnote_Search_Index::index_post($post_id);
            $updated++;
        }

        return $updated;
    }

    public static function post_has_story_hub($post_id) {
        $cluster_id = (string) get_post_meta($post_id, 'story_cluster_id', true);

        if (!$cluster_id) {
            return false;
        }

        if (get_post_meta($post_id, 'rifnote_manual_aggregation_id', true) || get_post_meta($post_id, 'rifnote_gpt_aggregation_id', true)) {
            return true;
        }

        if (self::get($cluster_id)) {
            return true;
        }

        return 0 === strpos($cluster_id, 'manual_');
    }

    public static function cluster_is_intentional($cluster_id, $posts = array()) {
        $cluster_id = sanitize_text_field($cluster_id);

        if (!$cluster_id) {
            return false;
        }

        if (self::get($cluster_id) || 0 === strpos($cluster_id, 'manual_')) {
            return true;
        }

        foreach ($posts as $post) {
            $post_id = is_object($post) ? (int) $post->ID : (int) $post;

            if ($post_id && self::post_has_story_hub($post_id)) {
                return true;
            }
        }

        return false;
    }

    public static function candidates($limit = 30) {
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => array('publish', 'draft', 'pending', 'future', 'private'),
            'posts_per_page' => max(1, min(100, absint($limit))),
            'orderby' => 'date',
            'order' => 'DESC',
        ));

        return array_map(array(__CLASS__, 'candidate_payload'), $posts);
    }

    public static function candidate_payload($post) {
        $post = get_post($post);
        $categories = wp_get_post_terms($post->ID, 'category', array('fields' => 'names'));

        return array(
            'post_id' => (int) $post->ID,
            'title' => get_the_title($post),
            'status' => get_post_status($post),
            'date' => get_post_time('Y-m-d H:i:s', true, $post),
            'source_name' => (string) get_post_meta($post->ID, 'source_name', true),
            'origin_model' => (string) get_post_meta($post->ID, 'rifnote_origin_model', true),
            'cluster_id' => (string) get_post_meta($post->ID, 'story_cluster_id', true),
            'category' => $categories ? $categories[0] : '',
            'missing_ai_summary' => '' === (string) get_post_meta($post->ID, 'ai_summary', true),
            'edit_url' => get_edit_post_link($post->ID, ''),
        );
    }

    public static function parse_post_ids($value) {
        if (is_array($value)) {
            $raw = $value;
        } else {
            $raw = preg_split('/[\s,]+/', (string) $value);
        }

        $ids = array_filter(array_map('absint', $raw));
        return array_values(array_unique($ids));
    }

    public static function sanitize_status($status) {
        $status = sanitize_key((string) $status);
        return in_array($status, array('draft', 'review', 'published', 'archived'), true) ? $status : 'draft';
    }

    public static function customgpt_aggregation_instructions() {
        return "Use Rifnote manual aggregation when a story needs a full coverage hub.\n\nFlow:\n1. GET /wp-json/rifnote/v1/customgpt/stories?limit=100&q={topic} to pull all matching candidate stories from the database, including GPT-created stories, publisher stories, TheNewsAPI stories, RSS/social stories and admin-created stories.\n2. If an admin specifically asks for cleanup-only work, use origin_model_not=GPT&incomplete=true. For aggregation, include GPT-created stories too unless told otherwise.\n3. Group stories that discuss the same core event. Do not merge adjacent topics just because names overlap.\n4. For each group, choose a clear aggregation title, short neutral summary, category, tags, entities, image_url when available, and one shared cluster_id using manual_{slug}.\n5. POST /wp-json/rifnote/v1/customgpt/aggregation/batch with clusters. Each cluster must include cluster_id, title, summary, category, post_ids or stories with post_id.\n6. Include ai_summary and ai_key_points when missing, preserve source metadata, original_url and publisher attribution.\n7. Include trending_signals when the batch reveals genuine momentum. Signals should include topic, type, category, score_boost, confidence, expires_in_minutes, aliases and reason.\n\nReturn concise JSON. Never invent facts. Never remove original source URLs.";
    }

    private static function sanitize_deep($value) {
        if (is_array($value)) {
            return array_map(array(__CLASS__, 'sanitize_deep'), $value);
        }

        return Rifnote_Search_Source_Meta::normalize_text((string) $value);
    }
}
