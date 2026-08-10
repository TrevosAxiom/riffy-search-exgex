<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Engine {
    public static function category_slug($category) {
        $category = sanitize_title((string) $category);
        return 'all-news' === $category ? '' : $category;
    }

    public static function date_query($date_range) {
        $range_map = array(
            '24h' => '1 day ago',
            '7d' => '7 days ago',
            '30d' => '30 days ago',
        );

        if (!isset($range_map[$date_range])) {
            return array();
        }

        return array(array('after' => $range_map[$date_range], 'inclusive' => true));
    }

    public static function base_args($request_args) {
        $args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => 80,
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
        );

        $category = self::category_slug($request_args['category']);

        if ($category) {
            $args['category_name'] = $category;
        }

        $date_query = self::date_query($request_args['date_range']);

        if ($date_query) {
            $args['date_query'] = $date_query;
        }

        return $args;
    }

    public static function candidate_ids($request_args) {
        $indexed_ids = Rifnote_Search_Index::candidate_ids($request_args);

        if ($indexed_ids) {
            return $indexed_ids;
        }

        $query = $request_args['query'];
        $base_args = self::base_args($request_args);
        $ids = array();

        if ('' === $query) {
            $recent = new WP_Query(array_merge($base_args, array('orderby' => 'date', 'order' => 'DESC')));
            return array_map('intval', $recent->posts);
        }

        $text_query = new WP_Query(array_merge($base_args, array('s' => $query)));
        $ids = array_merge($ids, $text_query->posts);

        $meta_query = new WP_Query(array_merge($base_args, array(
            'meta_query' => array(
                'relation' => 'OR',
                array('key' => 'source_name', 'value' => $query, 'compare' => 'LIKE'),
                array('key' => 'source_url', 'value' => $query, 'compare' => 'LIKE'),
                array('key' => 'original_url', 'value' => $query, 'compare' => 'LIKE'),
                array('key' => 'read_full_story_url', 'value' => $query, 'compare' => 'LIKE'),
                array('key' => 'canonical_url', 'value' => $query, 'compare' => 'LIKE'),
                array('key' => 'ai_summary', 'value' => $query, 'compare' => 'LIKE'),
                array('key' => 'entities', 'value' => $query, 'compare' => 'LIKE'),
                array('key' => 'normalized_headline', 'value' => $query, 'compare' => 'LIKE'),
            ),
        )));
        $ids = array_merge($ids, $meta_query->posts);

        $matching_terms = get_terms(array(
            'taxonomy' => array('category', 'post_tag'),
            'hide_empty' => true,
            'search' => $query,
            'fields' => 'ids',
        ));

        if (!is_wp_error($matching_terms) && $matching_terms) {
            $tax_query = new WP_Query(array_merge($base_args, array(
                'tax_query' => array(
                    'relation' => 'OR',
                    array('taxonomy' => 'category', 'field' => 'term_id', 'terms' => array_map('intval', $matching_terms), 'include_children' => true, 'operator' => 'IN'),
                    array('taxonomy' => 'post_tag', 'field' => 'term_id', 'terms' => array_map('intval', $matching_terms), 'operator' => 'IN'),
                ),
            )));
            $ids = array_merge($ids, $tax_query->posts);
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    public static function plain_excerpt($post) {
        $excerpt = has_excerpt($post) ? get_the_excerpt($post) : wp_strip_all_tags($post->post_content);
        return html_entity_decode(wp_trim_words(wp_strip_all_tags($excerpt), 23, '...'), ENT_QUOTES, get_bloginfo('charset'));
    }

    public static function score($post, $request_args) {
        $query = strtolower($request_args['query']);
        $title = strtolower(get_the_title($post));
        $excerpt = strtolower(self::plain_excerpt($post));
        $content = strtolower(wp_strip_all_tags($post->post_content));
        $text_relevance = 0.35;

        if ('' !== $query) {
            $text_relevance = 0;
            $text_relevance += false !== strpos($title, $query) ? 0.55 : 0;
            $text_relevance += false !== strpos($excerpt, $query) ? 0.25 : 0;
            $text_relevance += false !== strpos($content, $query) ? 0.2 : 0;
            $text_relevance = min(1, $text_relevance);
        }

        $published = get_post_time('U', true, $post);
        $age_days = max(0, (time() - $published) / DAY_IN_SECONDS);
        $freshness_score = 0.25;

        if ($age_days <= 1) {
            $freshness_score = 1;
        } elseif ($age_days <= 7) {
            $freshness_score = 0.8;
        } elseif ($age_days <= 30) {
            $freshness_score = 0.55;
        }

        $stored_freshness_score = get_post_meta($post->ID, 'freshness_score', true);

        if ('' !== $stored_freshness_score) {
            $freshness_score = min(1, max(0, (float) $stored_freshness_score));
        }

        $source_authority_score = self::normalize_score(get_post_meta($post->ID, 'source_authority_score', true), 0.5);
        $click_through_score = self::normalize_score(get_post_meta($post->ID, 'click_through_score', true), 0.5);
        $editor_boost = self::normalize_score(get_post_meta($post->ID, 'editor_boost', true), 0);
        $category = self::category_slug($request_args['category']);
        $category_match = $category && has_category($category, $post) ? 1 : 0;

        return round((0.40 * $text_relevance) + (0.25 * $freshness_score) + (0.15 * $source_authority_score) + (0.10 * $click_through_score) + (0.05 * $category_match) + (0.05 * $editor_boost), 4);
    }

    public static function normalize_score($value, $fallback) {
        if ('' === $value || null === $value) {
            return $fallback;
        }

        $score = (float) $value;

        if ($score > 1) {
            $score = $score / 100;
        }

        return min(1, max(0, $score));
    }

    public static function result_payload($post_id, $request_args) {
        $post = get_post($post_id);

        if (!$post) {
            return null;
        }

        $categories = get_the_category($post_id);
        $tags = get_the_tags($post_id);
        $source = Rifnote_Search_Source_Meta::source_payload($post_id);
        $cluster_id = get_post_meta($post_id, 'story_cluster_id', true) ? get_post_meta($post_id, 'story_cluster_id', true) : 'post_' . $post_id;
        $has_story_hub = class_exists('Rifnote_Search_Aggregation') ? Rifnote_Search_Aggregation::post_has_story_hub($post_id) : false;

        $payload = array_merge(array(
            'id' => $post_id,
            'headline' => Rifnote_Search_Source_Meta::normalize_text(get_the_title($post)),
            'excerpt' => Rifnote_Search_Source_Meta::normalize_text(self::plain_excerpt($post), true),
            'image' => get_the_post_thumbnail_url($post, 'medium_large') ? get_the_post_thumbnail_url($post, 'medium_large') : esc_url_raw(get_post_meta($post_id, 'image_url', true)),
            'published_at' => get_post_time(DATE_ATOM, true, $post),
            'published_at_human' => sprintf(__('%s ago', 'rifnote-search'), human_time_diff(get_post_time('U', true, $post), current_time('timestamp', true))),
            'category' => $categories ? Rifnote_Search_Source_Meta::normalize_text($categories[0]->name) : __('News', 'rifnote-search'),
            'category_slug' => $categories ? $categories[0]->slug : 'news',
            'tags' => $tags ? array_map(array('Rifnote_Search_Source_Meta', 'normalize_text'), wp_list_pluck($tags, 'name')) : array(),
            'cluster_id' => $cluster_id,
            'has_story_hub' => (bool) $has_story_hub,
            'aggregation_source' => (string) get_post_meta($post_id, 'rifnote_aggregation_source', true),
            'normalized_headline' => get_post_meta($post_id, 'normalized_headline', true),
            'permalink' => esc_url_raw(get_permalink($post_id)),
            'share_url' => esc_url_raw(get_permalink($post_id)),
            'score' => self::score($post, $request_args),
        ), $source);

        $beta_adjustment = Rifnote_Search_Beta::score_adjustment($payload, $request_args);
        $payload['beta_ranking_adjustment'] = round($beta_adjustment, 4);
        $payload['score'] = round(min(1, max(0, (float) $payload['score'] + $beta_adjustment)), 4);
        $payload['story_url'] = $has_story_hub ? home_url('/story/' . rawurlencode($payload['cluster_id']) . '/') : '';
        $payload['source_profile_url'] = !empty($payload['source_domain']) ? home_url('/source/' . rawurlencode($payload['source_domain']) . '/') : '';
        $payload['why_this_result'] = Rifnote_Search_Source_Trust::why_this_result($payload);
        $payload['claims'] = Rifnote_Search_Launch_Readiness::claims_for_result($post_id, $payload['cluster_id']);
        $payload['schema'] = Rifnote_Search_Launch_Readiness::schema_for_result($payload);

        if (current_user_can('edit_post', $post_id)) {
            $payload['admin_edit_url'] = esc_url_raw(get_edit_post_link($post_id, ''));
        }

        if (current_user_can('delete_post', $post_id)) {
            $payload['admin_delete_url'] = esc_url_raw(get_delete_post_link($post_id, '', false));
        }

        return $payload;
    }

    public static function payload($request_args, $page = 1, $per_page = 10) {
        $candidate_ids = self::candidate_ids($request_args);
        $results = array_filter(array_map(function ($post_id) use ($request_args) {
            return self::result_payload($post_id, $request_args);
        }, $candidate_ids));

        if (class_exists('Rifnote_Search_Data_API')) {
            $data_results = Rifnote_Search_Data_API::search_payload($request_args, max(10, $per_page * 2));

            if ($data_results) {
                $seen = array();
                foreach ($results as $result) {
                    if (!empty($result['canonical_url'])) {
                        $seen[md5(strtolower((string) $result['canonical_url']))] = true;
                    }
                    if (!empty($result['original_url'])) {
                        $seen[md5(strtolower((string) $result['original_url']))] = true;
                    }
                }

                foreach ($data_results as $result) {
                    $key = !empty($result['canonical_url']) ? md5(strtolower((string) $result['canonical_url'])) : '';
                    if ($key && isset($seen[$key])) {
                        continue;
                    }
                    $results[] = $result;
                }
            }
        }

        usort($results, function ($a, $b) use ($request_args) {
            if ('latest' === $request_args['sort']) {
                return strcmp($b['published_at'], $a['published_at']);
            }

            return $b['score'] <=> $a['score'];
        });

        $results = Rifnote_Search_Clustering::group_results(array_values($results));
        $total = count($results);
        $total_pages = max(1, (int) ceil($total / $per_page));
        $offset = ($page - 1) * $per_page;

        $payload = array(
            'query' => $request_args['query'],
            'results' => array_values(array_slice($results, $offset, $per_page)),
            'football_results' => class_exists('Rifnote_Search_Football_API') ? Rifnote_Search_Football_API::search_payload($request_args['query'], 8) : array(),
            'pagination' => array('page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => $total_pages),
            'filters' => array('category' => $request_args['category'], 'date_range' => $request_args['date_range'], 'sort' => $request_args['sort']),
        );

        if ($total <= 0 && !empty($request_args['query'])) {
            $payload['no_result_insights'] = Rifnote_Search_Platform_Insights::no_result_insights($request_args['query'], $request_args['category']);
        }

        $payload['sponsored'] = Rifnote_Search_Launch_Readiness::sponsored_for_request($request_args, 2);

        return $payload;
    }
}
