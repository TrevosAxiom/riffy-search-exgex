<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Index {
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_search_index';
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
            post_id BIGINT UNSIGNED NOT NULL,
            headline TEXT NOT NULL,
            excerpt TEXT NULL,
            search_text LONGTEXT NOT NULL,
            source_name VARCHAR(190) NULL,
            source_domain VARCHAR(190) NULL,
            category_slug VARCHAR(120) NULL,
            tags TEXT NULL,
            entities TEXT NULL,
            story_cluster_id VARCHAR(190) NULL,
            published_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (post_id),
            KEY source_domain (source_domain),
            KEY category_slug (category_slug),
            KEY story_cluster_id (story_cluster_id),
            FULLTEXT KEY search_text (search_text)
        ) {$charset_collate};");

        update_option('rifnote_search_index_db_version', RIFNOTE_SEARCH_VERSION);
    }

    public static function maybe_install() {
        global $wpdb;

        $table = self::table();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        if (get_option('rifnote_search_index_db_version') !== RIFNOTE_SEARCH_VERSION || $exists !== $table) {
            self::install();
        }
    }

    public static function index_post($post_id) {
        global $wpdb;

        if (wp_is_post_revision($post_id) || 'post' !== get_post_type($post_id) || 'publish' !== get_post_status($post_id)) {
            self::delete_post($post_id);
            return;
        }

        self::maybe_install();

        $post = get_post($post_id);
        $source = Rifnote_Search_Source_Meta::source_payload($post_id);
        $categories = get_the_category($post_id);
        $tags = get_the_tags($post_id);
        $tag_names = $tags ? wp_list_pluck($tags, 'name') : array();
        $entities = get_post_meta($post_id, 'entities', true);
        $headline = Rifnote_Search_Source_Meta::normalize_text(get_the_title($post));
        $excerpt = Rifnote_Search_Source_Meta::normalize_text(Rifnote_Search_Engine::plain_excerpt($post), true);
        $cluster_id = get_post_meta($post_id, 'story_cluster_id', true);

        $search_text = implode(' ', array_filter(array(
            $headline,
            $excerpt,
            $source['source_name'],
            $source['source_domain'],
            $categories ? implode(' ', wp_list_pluck($categories, 'name')) : '',
            implode(' ', $tag_names),
            wp_strip_all_tags((string) $entities),
            get_post_meta($post_id, 'normalized_headline', true),
        )));

        $wpdb->replace(self::table(), array(
            'post_id' => (int) $post_id,
            'headline' => $headline,
            'excerpt' => $excerpt,
            'search_text' => wp_strip_all_tags($search_text),
            'source_name' => sanitize_text_field($source['source_name']),
            'source_domain' => sanitize_text_field($source['source_domain']),
            'category_slug' => $categories ? sanitize_title($categories[0]->slug) : 'news',
            'tags' => sanitize_text_field(implode(', ', $tag_names)),
            'entities' => wp_kses_post((string) $entities),
            'story_cluster_id' => sanitize_text_field($cluster_id),
            'published_at' => get_post_time('Y-m-d H:i:s', true, $post),
            'updated_at' => current_time('mysql', true),
        ));
    }

    public static function delete_post($post_id) {
        global $wpdb;

        self::maybe_install();
        $wpdb->delete(self::table(), array('post_id' => (int) $post_id));
    }

    public static function reindex_all($limit = 500) {
        $ids = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => max(1, min(2000, (int) $limit)),
            'orderby' => 'date',
            'order' => 'DESC',
        ));

        foreach ($ids as $post_id) {
            self::index_post((int) $post_id);
        }

        update_option('rifnote_search_index_last_reindex', current_time('mysql', true), false);

        return count($ids);
    }

    public static function candidate_ids($request_args) {
        global $wpdb;

        self::maybe_install();

        $query = trim((string) ($request_args['query'] ?? ''));
        $queries = self::query_variants($query);
        $category = Rifnote_Search_Engine::category_slug($request_args['category'] ?? '');
        $where = array('1=1');
        $params = array();

        if ($category) {
            $where[] = 'category_slug = %s';
            $params[] = $category;
        }

        if ($query) {
            $query_where = array();
            foreach ($queries as $variant) {
                $like = '%' . $wpdb->esc_like($variant) . '%';
                $query_where[] = '(search_text LIKE %s OR headline LIKE %s OR source_name LIKE %s OR source_domain LIKE %s)';
                array_push($params, $like, $like, $like, $like);
            }
            $where[] = '(' . implode(' OR ', $query_where) . ')';
        }

        $date_range = $request_args['date_range'] ?? 'all';

        if ('24h' === $date_range) {
            $where[] = 'published_at >= %s';
            $params[] = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
        } elseif ('7d' === $date_range) {
            $where[] = 'published_at >= %s';
            $params[] = gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS);
        } elseif ('30d' === $date_range) {
            $where[] = 'published_at >= %s';
            $params[] = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
        }

        $sql = 'SELECT post_id FROM ' . self::table() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY published_at DESC LIMIT 200';

        if ($params) {
            $sql = $wpdb->prepare($sql, $params);
        }

        return array_map('intval', $wpdb->get_col($sql));
    }

    public static function query_variants($query) {
        $query = trim((string) $query);

        if (!$query) {
            return array();
        }

        $variants = array($query);
        $synonyms = self::synonyms();
        $normalized = strtolower($query);

        foreach ($synonyms as $term => $alts) {
            if (false !== strpos($normalized, strtolower($term))) {
                foreach ($alts as $alt) {
                    $variants[] = str_ireplace($term, $alt, $query);
                }
            }

            foreach ($alts as $alt) {
                if (false !== strpos($normalized, strtolower($alt))) {
                    $variants[] = str_ireplace($alt, $term, $query);
                }
            }
        }

        if (strlen($query) > 5) {
            $variants[] = substr($query, 0, -1);
        }

        return array_values(array_unique(array_filter($variants)));
    }

    public static function synonyms() {
        $raw = (string) get_option('rifnote_search_synonyms', "football=soccer\ntransfer=move,deal\npolitics=election,government");
        $synonyms = array();

        foreach (array_filter(array_map('trim', explode("\n", $raw))) as $line) {
            if (false === strpos($line, '=')) {
                continue;
            }
            list($term, $alts) = array_map('trim', explode('=', $line, 2));
            $synonyms[$term] = array_values(array_filter(array_map('trim', explode(',', $alts))));
        }

        return $synonyms;
    }

    public static function suggestions($query, $limit = 8) {
        global $wpdb;

        self::maybe_install();

        $query = trim((string) $query);
        $limit = max(1, min(20, (int) $limit));

        if (strlen($query) < 2) {
            return array();
        }

        $like = '%' . $wpdb->esc_like($query) . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT headline, source_name, category_slug, tags FROM ' . self::table() . ' WHERE search_text LIKE %s ORDER BY published_at DESC LIMIT %d',
            $like,
            $limit * 3
        ), ARRAY_A);

        $suggestions = array();

        foreach ($rows as $row) {
            self::add_suggestion($suggestions, $row['headline'], 'headline');
            self::add_suggestion($suggestions, $row['source_name'], 'source');
            self::add_suggestion($suggestions, $row['category_slug'], 'category');

            foreach (array_filter(array_map('trim', explode(',', (string) $row['tags']))) as $tag) {
                self::add_suggestion($suggestions, $tag, 'topic');
            }

            if (count($suggestions) >= $limit) {
                break;
            }
        }

        self::add_query_suggestions($suggestions, $query, $limit);

        return array_slice(array_values($suggestions), 0, $limit);
    }

    private static function add_query_suggestions(&$suggestions, $query, $limit) {
        global $wpdb;

        $query = trim((string) $query);
        $like = '%' . $wpdb->esc_like($query) . '%';

        Rifnote_Search_Analytics::maybe_install();
        $logs = Rifnote_Search_Analytics::logs_table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT query_text AS label, COUNT(*) AS total FROM {$logs} WHERE event_type = 'search' AND query_text LIKE %s AND query_text <> '' GROUP BY query_text ORDER BY total DESC LIMIT %d",
            $like,
            max(1, min(10, (int) $limit))
        ), ARRAY_A);

        foreach ($rows as $row) {
            self::add_suggestion($suggestions, $row['label'], 'query');
        }

        foreach (Rifnote_Search_Trending::topics(12) as $topic) {
            if (false !== stripos($topic['topic'], $query)) {
                self::add_suggestion($suggestions, $topic['topic'], 'trending');
            }
        }
    }

    private static function add_suggestion(&$suggestions, $label, $type) {
        $label = trim(wp_strip_all_tags((string) $label));

        if (!$label) {
            return;
        }

        $key = sanitize_title($type . '-' . $label);

        if (isset($suggestions[$key])) {
            return;
        }

        $suggestions[$key] = array(
            'label' => $label,
            'value' => $label,
            'type' => $type,
        );
    }

    public static function health() {
        global $wpdb;

        self::maybe_install();

        return array(
            'indexed_posts' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table()),
            'last_reindex' => get_option('rifnote_search_index_last_reindex', ''),
        );
    }
}
