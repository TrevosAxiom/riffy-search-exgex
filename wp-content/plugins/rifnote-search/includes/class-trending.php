<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Trending {
    const CRON_HOOK = 'rifnote_search_refresh_trending_topics';
    const SNAPSHOT_OPTION = 'rifnote_search_trending_snapshot_v3';
    const SNAPSHOT_TTL = 900;
    const INTERNET_FEEDS_OPTION = 'rifnote_trending_internet_feeds';

    public static function table_name() {
        global $wpdb;

        return $wpdb->prefix . 'rifnote_trending_topics';
    }

    public static function signals_table() {
        global $wpdb;

        return $wpdb->prefix . 'rifnote_trending_signals';
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $table = self::table_name();
        $signals = self::signals_table();

        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            topic VARCHAR(190) NOT NULL,
            slug VARCHAR(190) NOT NULL,
            score DECIMAL(10,4) DEFAULT 0,
            manual_boost DECIMAL(10,4) DEFAULT 0,
            is_pinned TINYINT(1) DEFAULT 0,
            is_hidden TINYINT(1) DEFAULT 0,
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY visible_score (is_hidden, is_pinned, score),
            KEY expires_at (expires_at)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$signals} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            topic VARCHAR(190) NOT NULL,
            slug VARCHAR(190) NOT NULL,
            topic_type VARCHAR(60) DEFAULT '',
            category VARCHAR(100) DEFAULT '',
            score_boost DECIMAL(10,4) DEFAULT 0,
            confidence DECIMAL(5,4) DEFAULT 0,
            source_model VARCHAR(100) DEFAULT 'GPT',
            source_actor VARCHAR(190) DEFAULT 'CustomGPT',
            batch_id VARCHAR(120) DEFAULT '',
            reason TEXT NULL,
            aliases LONGTEXT NULL,
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY slug (slug),
            KEY category (category),
            KEY expires_at (expires_at),
            KEY created_at (created_at)
        ) {$charset_collate};");

        update_option('rifnote_search_trending_db_version', RIFNOTE_SEARCH_VERSION);
        self::seed_defaults();
    }

    public static function maybe_install() {
        global $wpdb;

        $topic_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::table_name()));
        $signals_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::signals_table()));

        if (get_option('rifnote_search_trending_db_version') !== RIFNOTE_SEARCH_VERSION || !$topic_table || !$signals_table) {
            self::install();
        }
    }

    public static function cron_schedules($schedules) {
        if (!isset($schedules['rifnote_every_fifteen_minutes'])) {
            $schedules['rifnote_every_fifteen_minutes'] = array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display' => __('Every 15 minutes', 'rifnote-search'),
            );
        }

        return $schedules;
    }

    public static function schedule() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 2 * MINUTE_IN_SECONDS, 'rifnote_every_fifteen_minutes', self::CRON_HOOK);
        }
    }

    public static function run_cron() {
        self::refresh_snapshot(true);
    }

    public static function maybe_clear_snapshot_on_update($option, $old_value, $value) {
        if (in_array($option, array('rifnote_trending_aliases', 'rifnote_trending_blocked_terms', self::INTERNET_FEEDS_OPTION), true)) {
            delete_option(self::SNAPSHOT_OPTION);
        }
    }

    public static function default_internet_feeds_text() {
        return implode("\n", array(
            'Football|4|https://trends.google.com/trends/trendingsearches/daily/rss?geo=NG',
            'Nigeria|3|https://trends.google.com/trends/trendingsearches/daily/rss?geo=NG',
            'US|2|https://trends.google.com/trends/trendingsearches/daily/rss?geo=US',
            'International|1.5|https://trends.google.com/trends/trendingsearches/daily/rss?geo=GB',
            'International|1.2|https://trends.google.com/trends/trendingsearches/daily/rss?geo=ZA',
        ));
    }

    public static function seed_defaults() {
        foreach (array('Osimhen', 'AFCON qualifiers', 'Nigeria economy', 'Transfer window', 'World Cup hub', 'Election tribunal') as $index => $topic) {
            self::upsert_topic($topic, array('score' => 2 - min(1.5, $index * 0.2), 'manual_boost' => 0));
        }
    }

    public static function normalize_slug($topic) {
        return sanitize_title((string) $topic);
    }

    public static function upsert_topic($topic, $args = array()) {
        global $wpdb;

        $topic = sanitize_text_field((string) $topic);
        $slug = self::normalize_slug($topic);

        if (!$topic || !$slug) {
            return false;
        }

        $table = self::table_name();
        $now = current_time('mysql', true);
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE slug = %s", $slug));
        $data = array(
            'topic' => $topic,
            'score' => isset($args['score']) ? (float) $args['score'] : 0,
            'manual_boost' => isset($args['manual_boost']) ? (float) $args['manual_boost'] : 0,
            'is_pinned' => !empty($args['is_pinned']) ? 1 : 0,
            'is_hidden' => !empty($args['is_hidden']) ? 1 : 0,
            'expires_at' => !empty($args['expires_at']) ? gmdate('Y-m-d H:i:s', strtotime($args['expires_at'])) : null,
            'updated_at' => $now,
        );

        if ($existing) {
            $wpdb->update($table, $data, array('id' => (int) $existing));
            return (int) $existing;
        }

        $data['slug'] = $slug;
        $data['created_at'] = $now;
        $wpdb->insert($table, $data);

        return (int) $wpdb->insert_id;
    }

    public static function editorial_topics() {
        global $wpdb;

        self::maybe_install();

        $table = self::table_name();
        $now = current_time('mysql', true);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE is_hidden = 0 AND (expires_at IS NULL OR expires_at > %s)",
            $now
        ), ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    public static function generated_topics($force = false) {
        $snapshot = get_option(self::SNAPSHOT_OPTION, array());

        if (!$force && is_array($snapshot) && !empty($snapshot['generated_at']) && !empty($snapshot['topics'])) {
            $age = time() - strtotime((string) $snapshot['generated_at']);

            if ($age >= 0 && $age < self::SNAPSHOT_TTL) {
                return $snapshot['topics'];
            }
        }

        return self::refresh_snapshot($force);
    }

    public static function refresh_snapshot($force = false) {
        $topics = self::calculate_generated_topics();

        update_option(self::SNAPSHOT_OPTION, array(
            'generated_at' => gmdate(DATE_ATOM),
            'topics' => $topics,
        ), false);

        return $topics;
    }

    private static function calculate_generated_topics() {
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 160,
            'date_query' => array(array('after' => '48 hours ago', 'inclusive' => true)),
            'orderby' => 'date',
            'order' => 'DESC',
        ));
        $scores = array();

        foreach ($posts as $post) {
            $age_hours = max(1, (time() - get_post_time('U', true, $post)) / HOUR_IN_SECONDS);
            $freshness = max(0.1, 1 / sqrt($age_hours));
            $terms = array();
            $categories = get_the_category($post->ID);
            $tags = get_the_tags($post->ID);

            if ($categories) {
                $terms = array_merge($terms, wp_list_pluck($categories, 'name'));
            }

            if ($tags) {
                $terms = array_merge($terms, wp_list_pluck($tags, 'name'));
            }

            $terms = array_merge($terms, self::headline_terms(get_the_title($post)));
            $terms = array_merge($terms, self::entity_terms($post->ID));

            foreach ($terms as $term) {
                self::add_score($scores, $term, $freshness, 'content');
            }
        }

        foreach (self::internet_trend_terms() as $term) {
            self::add_score($scores, $term['topic'], $term['score'], 'internet', array(
                'scope' => $term['scope'],
                'feed' => $term['feed'],
            ));
        }

        foreach (self::analytics_terms() as $term) {
            self::add_score($scores, $term['topic'], $term['score'], 'analytics');
        }

        foreach (self::active_signals() as $signal) {
            $slug = self::normalize_slug(self::canonical_topic($signal['topic']));
            $existing_score = isset($scores[$slug]) ? (float) $scores[$slug]['score'] : 0;
            $raw_boost = (float) $signal['score'] * max(0.1, min(1, (float) $signal['confidence']));
            $capped_boost = min($raw_boost, max(3, ($existing_score * 0.35) + 2));
            self::add_score($scores, $signal['topic'], $capped_boost, 'gpt_signal', array(
                'scope' => $signal['category'],
            ));
        }

        usort($scores, function ($a, $b) {
            $priority = ((int) ($b['priority'] ?? 99)) <=> ((int) ($a['priority'] ?? 99));

            if (0 !== $priority) {
                return $priority;
            }

            return $b['score'] <=> $a['score'];
        });

        return array_slice(array_values($scores), 0, 80);
    }

    public static function topics($limit = 10) {
        $topics = array();

        foreach (self::generated_topics() as $topic) {
            $topics[$topic['slug']] = $topic;
        }

        foreach (self::editorial_topics() as $topic) {
            $slug = $topic['slug'];
            $existing_score = isset($topics[$slug]) ? (float) $topics[$slug]['score'] : 0;
            $topics[$slug] = array(
                'topic' => $topic['topic'],
                'slug' => $slug,
                'score' => $existing_score + self::editorial_score($topic),
                'manual_boost' => (float) $topic['manual_boost'],
                'is_pinned' => (bool) $topic['is_pinned'],
                'scope' => self::trend_lane($topic['topic'])['key'] ?? 'editorial',
                'priority' => self::trend_lane($topic['topic'])['priority'] ?? 100,
                'source' => 'editorial',
            );
        }

        usort($topics, function ($a, $b) {
            if (!empty($a['is_pinned']) !== !empty($b['is_pinned'])) {
                return !empty($a['is_pinned']) ? -1 : 1;
            }

            $priority = ((int) ($b['priority'] ?? 99)) <=> ((int) ($a['priority'] ?? 99));

            if (0 !== $priority) {
                return $priority;
            }

            return $b['score'] <=> $a['score'];
        });

        return array_slice(array_values($topics), 0, max(1, min(30, (int) $limit)));
    }

    private static function add_score(&$scores, $term, $score, $source = 'generated', $context = array()) {
        $term = self::canonical_topic(self::clean_topic($term));
        $slug = self::normalize_slug($term);
        $scope_text = $term . ' ' . (string) ($context['scope'] ?? '');
        $lane = self::trend_lane($scope_text);

        if (!$slug || self::is_stop_topic($slug) || !$lane) {
            return;
        }

        if (!isset($scores[$slug])) {
            $scores[$slug] = array(
                'topic' => $term,
                'slug' => $slug,
                'score' => 0,
                'scope' => $lane['key'],
                'priority' => $lane['priority'],
                'source' => $source,
            );
        }

        $scores[$slug]['score'] += (float) $score * (float) $lane['weight'];
        $scores[$slug]['priority'] = min((int) $scores[$slug]['priority'], (int) $lane['priority']);

        if (!empty($context['feed'])) {
            $scores[$slug]['feed'] = sanitize_text_field((string) $context['feed']);
        }

        if (in_array($source, array('analytics', 'gpt_signal', 'internet'), true)) {
            $scores[$slug]['source'] = 'analytics';
            if ('gpt_signal' === $source) {
                $scores[$slug]['source'] = 'gpt_signal';
                $scores[$slug]['gpt_assisted'] = true;
            } elseif ('internet' === $source) {
                $scores[$slug]['source'] = 'internet';
            }
        }
    }

    private static function trend_lane($text) {
        $text = strtolower(' ' . remove_accents(wp_strip_all_tags((string) $text)) . ' ');
        $lanes = array(
            'football' => array(
                'priority' => 4,
                'weight' => 3.4,
                'terms' => array('football', 'soccer', 'fifa', 'uefa', 'afcon', 'world cup', 'premier league', 'champions league', 'europa', 'laliga', 'serie a', 'bundesliga', 'epl', 'fixture', 'fixtures', 'matchday', 'transfer', 'goal', 'goals', 'club', 'arsenal', 'chelsea', 'liverpool', 'man city', 'manchester united', 'barcelona', 'real madrid', 'psg', 'bayern', 'juventus', 'mbappe', 'messi', 'ronaldo', 'osimhen', 'salah', 'haaland', 'saka', 'super eagles', 'nff'),
            ),
            'nigeria' => array(
                'priority' => 3,
                'weight' => 2.5,
                'terms' => array('nigeria', 'nigerian', 'naija', 'lagos', 'abuja', 'kano', 'rivers', 'tinubu', 'atiku', 'peter obi', 'obi', 'apc', 'pdp', 'lp', 'naira', 'ngn', 'cbn', 'inec', 'efcc', 'nollywood', 'dangote', 'nnpc'),
            ),
            'us' => array(
                'priority' => 2,
                'weight' => 1.8,
                'terms' => array('united states', 'u.s.', 'usa', ' us ', 'american', 'america', 'trump', 'biden', 'washington', 'white house', 'senate', 'congress', 'republican', 'democrat', 'california', 'texas', 'new york', 'fbi', 'doj', 'supreme court'),
            ),
            'international' => array(
                'priority' => 1,
                'weight' => 1.25,
                'terms' => array('world', 'global', 'international', 'uk', 'britain', 'england', 'europe', 'eu', 'russia', 'ukraine', 'china', 'israel', 'gaza', 'iran', 'france', 'germany', 'spain', 'italy', 'india', 'pakistan', 'south africa', 'ghana', 'kenya', 'canada', 'mexico', 'brazil', 'argentina', 'war', 'election', 'climate', 'trade'),
            ),
        );

        foreach ($lanes as $key => $lane) {
            foreach ($lane['terms'] as $term) {
                if (false !== strpos($text, strtolower($term))) {
                    return array(
                        'key' => $key,
                        'priority' => (int) $lane['priority'],
                        'weight' => (float) $lane['weight'],
                    );
                }
            }
        }

        return null;
    }

    private static function clean_topic($term) {
        $term = wp_strip_all_tags((string) $term);
        $term = html_entity_decode($term, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ? get_bloginfo('charset') : 'UTF-8');
        $term = preg_replace('/\s+/', ' ', trim($term));

        return sanitize_text_field($term);
    }

    private static function is_stop_topic($slug) {
        if (strlen($slug) < 3 || ctype_digit($slug)) {
            return true;
        }

        $blocked = self::blocked_slugs();

        if (in_array($slug, $blocked, true)) {
            return true;
        }

        return in_array($slug, array(
            'the', 'and', 'for', 'with', 'from', 'after', 'before', 'this', 'that', 'what', 'when', 'where', 'will', 'says', 'said',
            'news', 'story', 'report', 'reports', 'latest', 'update', 'updates', 'video', 'photos', 'photo', 'watch', 'live',
            'sen', 'rep', 'gov',
        ), true);
    }

    private static function editorial_score($topic) {
        $score = (float) ($topic['score'] ?? 0);
        $manual_boost = (float) ($topic['manual_boost'] ?? 0);
        $slug = (string) ($topic['slug'] ?? '');

        if (!$manual_boost && empty($topic['is_pinned']) && in_array($slug, self::default_topic_slugs(), true)) {
            $score = min($score, 2);
        }

        return $score + $manual_boost;
    }

    private static function default_topic_slugs() {
        return array_map(array(__CLASS__, 'normalize_slug'), array('Osimhen', 'AFCON qualifiers', 'Nigeria economy', 'Transfer window', 'World Cup hub', 'Election tribunal'));
    }

    public static function alias_lines() {
        return (string) get_option('rifnote_trending_aliases', "Kylian Mbappe=Mbappe,K. Mbappe\nDonald Trump=Trump,President Trump");
    }

    public static function blocked_terms_text() {
        return (string) get_option('rifnote_trending_blocked_terms', "8217\n8216\nbreaking\nlatest\nupdate");
    }

    public static function internet_feeds_text() {
        return (string) get_option(self::INTERNET_FEEDS_OPTION, self::default_internet_feeds_text());
    }

    public static function sanitize_aliases($value) {
        $lines = array();

        foreach (explode("\n", (string) $value) as $line) {
            $line = trim(sanitize_text_field($line));

            if (!$line || false === strpos($line, '=')) {
                continue;
            }

            list($canonical, $aliases) = array_map('trim', explode('=', $line, 2));
            $canonical = self::clean_topic($canonical);
            $aliases = array_filter(array_map(array(__CLASS__, 'clean_topic'), array_map('trim', explode(',', $aliases))));

            if ($canonical && $aliases) {
                $lines[] = $canonical . '=' . implode(',', array_unique($aliases));
            }
        }

        return implode("\n", array_slice(array_unique($lines), 0, 200));
    }

    public static function sanitize_blocked_terms($value) {
        $terms = array();

        foreach (preg_split('/[\r\n,]+/', (string) $value) as $term) {
            $term = self::clean_topic($term);

            if ($term) {
                $terms[] = $term;
            }
        }

        return implode("\n", array_slice(array_unique($terms), 0, 300));
    }

    public static function sanitize_internet_feeds($value) {
        $lines = array();

        foreach (explode("\n", (string) $value) as $line) {
            $line = trim($line);

            if (!$line) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            $url = count($parts) >= 3 ? $parts[2] : $parts[0];
            $url = esc_url_raw($url);

            if (!$url || !preg_match('#^https?://#i', $url)) {
                continue;
            }

            $scope = count($parts) >= 3 ? sanitize_text_field($parts[0]) : 'International';
            $weight = count($parts) >= 3 ? max(0.1, min(10, (float) $parts[1])) : 1;
            $lines[] = $scope . '|' . $weight . '|' . $url;
        }

        return implode("\n", array_slice(array_unique($lines), 0, 50));
    }

    private static function internet_trend_terms() {
        $terms = array();

        foreach (self::parse_internet_feeds() as $feed) {
            $items = self::fetch_trend_feed_items($feed['url']);
            $count = max(1, count($items));

            foreach ($items as $index => $item) {
                $text = trim($item['title'] . ' ' . $item['summary'] . ' ' . $feed['scope']);
                $lane = self::trend_lane($text);

                if (!$lane) {
                    continue;
                }

                $rank_score = max(0.25, ($count - $index) / $count);
                $terms[] = array(
                    'topic' => $item['title'],
                    'scope' => $lane['key'],
                    'score' => $rank_score * (float) $feed['weight'],
                    'feed' => $feed['scope'],
                );
            }
        }

        return $terms;
    }

    private static function parse_internet_feeds() {
        $feeds = array();

        foreach (explode("\n", self::internet_feeds_text()) as $line) {
            $line = trim($line);

            if (!$line) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            $url = count($parts) >= 3 ? $parts[2] : $parts[0];
            $url = esc_url_raw($url);

            if (!$url) {
                continue;
            }

            $feeds[] = array(
                'scope' => count($parts) >= 3 ? sanitize_text_field($parts[0]) : 'International',
                'weight' => count($parts) >= 3 ? max(0.1, min(10, (float) $parts[1])) : 1,
                'url' => $url,
            );
        }

        return array_slice($feeds, 0, 50);
    }

    private static function fetch_trend_feed_items($url) {
        $response = wp_remote_get($url, array(
            'timeout' => 8,
            'redirection' => 3,
            'user-agent' => 'Rifnote Search/' . RIFNOTE_SEARCH_VERSION . '; ' . home_url('/'),
        ));

        if (is_wp_error($response)) {
            return array();
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300 || !$body) {
            return array();
        }

        $items = self::feed_items_from_json($body);

        if ($items) {
            return $items;
        }

        return self::feed_items_from_xml($body);
    }

    private static function feed_items_from_json($body) {
        $json = json_decode($body, true);

        if (!is_array($json)) {
            return array();
        }

        $rows = array();

        if (!empty($json['items']) && is_array($json['items'])) {
            $rows = $json['items'];
        } elseif (!empty($json['data']) && is_array($json['data'])) {
            $rows = $json['data'];
        }

        $items = array();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $title = self::clean_topic((string) ($row['title'] ?? $row['name'] ?? $row['topic'] ?? ''));

            if (!$title) {
                continue;
            }

            $items[] = array(
                'title' => $title,
                'summary' => self::clean_topic((string) ($row['summary'] ?? $row['description'] ?? $row['text'] ?? '')),
            );
        }

        return array_slice($items, 0, 40);
    }

    private static function feed_items_from_xml($body) {
        if (!function_exists('simplexml_load_string')) {
            return array();
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$xml) {
            return array();
        }

        $nodes = array();

        if (isset($xml->channel->item)) {
            $nodes = $xml->channel->item;
        } elseif (isset($xml->entry)) {
            $nodes = $xml->entry;
        }

        $items = array();

        foreach ($nodes as $node) {
            $title = self::clean_topic((string) ($node->title ?? ''));
            $summary = self::clean_topic((string) ($node->description ?? $node->summary ?? ''));

            if (!$title) {
                continue;
            }

            $items[] = array(
                'title' => $title,
                'summary' => $summary,
            );
        }

        return array_slice($items, 0, 40);
    }

    private static function alias_map() {
        $map = array();

        foreach (explode("\n", self::alias_lines()) as $line) {
            if (false === strpos($line, '=')) {
                continue;
            }

            list($canonical, $aliases) = array_map('trim', explode('=', $line, 2));
            $canonical = self::clean_topic($canonical);

            if (!$canonical) {
                continue;
            }

            $map[self::normalize_slug($canonical)] = $canonical;

            foreach (array_filter(array_map('trim', explode(',', $aliases))) as $alias) {
                $alias = self::clean_topic($alias);

                if ($alias) {
                    $map[self::normalize_slug($alias)] = $canonical;
                }
            }
        }

        return $map;
    }

    private static function canonical_topic($topic) {
        $topic = self::clean_topic($topic);
        $slug = self::normalize_slug($topic);
        $map = self::alias_map();

        return isset($map[$slug]) ? $map[$slug] : $topic;
    }

    private static function blocked_slugs() {
        $slugs = array();

        foreach (preg_split('/[\r\n,]+/', self::blocked_terms_text()) as $term) {
            $slug = self::normalize_slug(self::clean_topic($term));

            if ($slug) {
                $slugs[] = $slug;
            }
        }

        return array_values(array_unique($slugs));
    }

    public static function add_signal($signal, $defaults = array()) {
        global $wpdb;

        self::maybe_install();

        $topic = self::clean_topic((string) ($signal['topic'] ?? ''));
        $topic = self::canonical_topic($topic);
        $slug = self::normalize_slug($topic);

        if (!$topic || !$slug || self::is_stop_topic($slug)) {
            return new WP_Error('rifnote_trending_invalid_signal', __('Trending signal topic is invalid or blocked.', 'rifnote-search'));
        }

        $confidence = max(0, min(1, (float) ($signal['confidence'] ?? 0.7)));
        $score_boost = max(0, min(20, (float) ($signal['score_boost'] ?? $signal['score'] ?? 1)));
        $expires_at = '';

        if (!empty($signal['expires_at'])) {
            $expires_at = gmdate('Y-m-d H:i:s', strtotime((string) $signal['expires_at']));
        } elseif (!empty($signal['expires_in_minutes'])) {
            $expires_at = gmdate('Y-m-d H:i:s', time() + max(15, min(1440, absint($signal['expires_in_minutes']))) * MINUTE_IN_SECONDS);
        } else {
            $expires_at = gmdate('Y-m-d H:i:s', time() + 3 * HOUR_IN_SECONDS);
        }

        $aliases = isset($signal['aliases']) && is_array($signal['aliases']) ? array_values(array_filter(array_map(array(__CLASS__, 'clean_topic'), $signal['aliases']))) : array();

        $wpdb->insert(self::signals_table(), array(
            'topic' => $topic,
            'slug' => $slug,
            'topic_type' => sanitize_key((string) ($signal['type'] ?? $signal['topic_type'] ?? '')),
            'category' => sanitize_text_field((string) ($signal['category'] ?? '')),
            'score_boost' => $score_boost,
            'confidence' => $confidence,
            'source_model' => sanitize_text_field((string) ($defaults['source_model'] ?? $signal['source_model'] ?? 'GPT')),
            'source_actor' => sanitize_text_field((string) ($defaults['source_actor'] ?? $signal['source_actor'] ?? 'CustomGPT')),
            'batch_id' => sanitize_text_field((string) ($defaults['batch_id'] ?? $signal['batch_id'] ?? '')),
            'reason' => sanitize_textarea_field((string) ($signal['reason'] ?? '')),
            'aliases' => $aliases ? wp_json_encode($aliases) : null,
            'expires_at' => $expires_at,
            'created_at' => current_time('mysql', true),
        ));

        delete_option(self::SNAPSHOT_OPTION);

        return array(
            'id' => (int) $wpdb->insert_id,
            'topic' => $topic,
            'slug' => $slug,
            'score_boost' => $score_boost,
            'confidence' => $confidence,
            'expires_at' => $expires_at,
        );
    }

    public static function add_signals($signals, $defaults = array()) {
        $summary = array('ok' => true, 'received' => is_array($signals) ? count($signals) : 0, 'created' => 0, 'errors' => 0, 'signals' => array(), 'messages' => array());

        if (!is_array($signals)) {
            $summary['ok'] = false;
            $summary['errors']++;
            $summary['messages'][] = __('Signals payload must be an array.', 'rifnote-search');
            return $summary;
        }

        foreach (array_slice($signals, 0, 100) as $signal) {
            $result = self::add_signal(is_array($signal) ? $signal : array(), $defaults);

            if (is_wp_error($result)) {
                $summary['errors']++;
                $summary['messages'][] = $result->get_error_message();
                continue;
            }

            $summary['created']++;
            $summary['signals'][] = $result;
        }

        $summary['ok'] = 0 === (int) $summary['errors'];

        return $summary;
    }

    public static function active_signals($limit = 100) {
        global $wpdb;

        self::maybe_install();

        $now = current_time('mysql', true);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT slug, topic, topic_type, category, SUM(score_boost) AS score, MAX(confidence) AS confidence, COUNT(*) AS signal_count, MAX(created_at) AS latest_at FROM " . self::signals_table() . " WHERE expires_at IS NULL OR expires_at > %s GROUP BY slug, topic, topic_type, category ORDER BY score DESC LIMIT %d",
            $now,
            max(1, min(200, absint($limit)))
        ), ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    public static function recent_signals($limit = 30) {
        global $wpdb;

        self::maybe_install();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::signals_table() . " ORDER BY created_at DESC LIMIT %d",
            max(1, min(100, absint($limit)))
        ), ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    private static function headline_terms($headline) {
        $tokens = Rifnote_Search_Clustering::tokens($headline);
        $terms = array_slice($tokens, 0, 7);
        $headline = self::clean_topic($headline);

        if (preg_match_all('/\b[A-Z][a-zA-Z0-9\'-]{2,}(?:\s+[A-Z][a-zA-Z0-9\'-]{2,}){0,2}\b/', $headline, $matches)) {
            $terms = array_merge($terms, array_slice($matches[0], 0, 8));
        }

        return array_values(array_unique($terms));
    }

    private static function entity_terms($post_id) {
        $raw = (string) get_post_meta($post_id, 'entities', true);
        $decoded = json_decode($raw, true);
        $terms = array();

        if (!is_array($decoded)) {
            return $terms;
        }

        foreach ($decoded as $value) {
            if (is_array($value)) {
                $terms = array_merge($terms, array_slice(array_map('sanitize_text_field', $value), 0, 8));
            } elseif (is_string($value)) {
                $terms[] = sanitize_text_field($value);
            }
        }

        return $terms;
    }

    private static function analytics_terms() {
        global $wpdb;

        if (!class_exists('Rifnote_Search_Analytics')) {
            return array();
        }

        Rifnote_Search_Analytics::maybe_install();
        $logs = Rifnote_Search_Analytics::logs_table();
        $clicks = Rifnote_Search_Analytics::clicks_table();
        $since_15 = gmdate('Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS);
        $since_hour = gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS);
        $terms = array();

        $searches = $wpdb->get_results($wpdb->prepare(
            "SELECT query_text AS topic, COUNT(*) AS total FROM {$logs} WHERE event_type = 'search' AND query_text <> '' AND created_at >= %s GROUP BY query_text ORDER BY total DESC LIMIT 25",
            $since_hour
        ), ARRAY_A);

        foreach (is_array($searches) ? $searches : array() as $row) {
            $boost = strtotime($row['topic']) ? 1 : 1;
            $terms[] = array('topic' => $row['topic'], 'score' => (float) $row['total'] * 2.5 * $boost);
        }

        $fresh_searches = $wpdb->get_results($wpdb->prepare(
            "SELECT query_text AS topic, COUNT(*) AS total FROM {$logs} WHERE event_type = 'search' AND query_text <> '' AND created_at >= %s GROUP BY query_text ORDER BY total DESC LIMIT 20",
            $since_15
        ), ARRAY_A);

        foreach (is_array($fresh_searches) ? $fresh_searches : array() as $row) {
            $terms[] = array('topic' => $row['topic'], 'score' => (float) $row['total'] * 5);
        }

        $clicks_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT query_text AS topic, COUNT(*) AS total FROM {$clicks} WHERE query_text <> '' AND created_at >= %s GROUP BY query_text ORDER BY total DESC LIMIT 20",
            $since_hour
        ), ARRAY_A);

        foreach (is_array($clicks_rows) ? $clicks_rows : array() as $row) {
            $terms[] = array('topic' => $row['topic'], 'score' => (float) $row['total'] * 3);
        }

        return $terms;
    }

    public static function rest_payload($limit = 10) {
        return array(
            'topics' => array_map(function ($topic) {
                return array(
                    'topic' => $topic['topic'],
                    'slug' => $topic['slug'],
                    'score' => round((float) $topic['score'], 4),
                    'is_pinned' => !empty($topic['is_pinned']),
                    'gpt_assisted' => !empty($topic['gpt_assisted']),
                    'scope' => $topic['scope'] ?? '',
                    'source' => $topic['source'],
                );
            }, self::topics($limit)),
            'generated_at' => gmdate(DATE_ATOM),
        );
    }
}
