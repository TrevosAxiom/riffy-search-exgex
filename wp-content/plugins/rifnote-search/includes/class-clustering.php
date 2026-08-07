<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Clustering {
    public static function tokens($headline) {
        $normalized = Rifnote_Search_Source_Meta::normalize_headline($headline);
        $tokens = array_filter(explode(' ', $normalized), function ($token) {
            return strlen($token) > 2;
        });

        return array_values(array_unique($tokens));
    }

    public static function similarity($left, $right) {
        $left_tokens = self::tokens($left);
        $right_tokens = self::tokens($right);

        if (!$left_tokens || !$right_tokens) {
            return 0;
        }

        $intersection = array_intersect($left_tokens, $right_tokens);
        $union = array_unique(array_merge($left_tokens, $right_tokens));

        return count($union) ? count($intersection) / count($union) : 0;
    }

    public static function within_window($left_post_id, $right_post_id) {
        $left_time = get_post_time('U', true, $left_post_id);
        $right_time = get_post_time('U', true, $right_post_id);

        return abs($left_time - $right_time) <= 2 * DAY_IN_SECONDS;
    }

    public static function cluster_key($story) {
        if (!empty($story['cluster_id']) && 0 !== strpos($story['cluster_id'], 'post_')) {
            return $story['cluster_id'];
        }

        $tokens = self::tokens($story['normalized_headline'] ? $story['normalized_headline'] : $story['headline']);
        $tokens = array_slice($tokens, 0, 8);

        return $tokens ? 'auto_' . md5(implode(' ', $tokens)) : 'post_' . (int) $story['id'];
    }

    public static function group_results($results) {
        $groups = array();

        foreach ($results as $story) {
            $matched_key = '';

            foreach ($groups as $key => $group) {
                $master = $group[0];

                if (self::same_story($story, $master)) {
                    $matched_key = $key;
                    break;
                }
            }

            $key = $matched_key ? $matched_key : self::cluster_key($story);

            if (!isset($groups[$key])) {
                $groups[$key] = array();
            }

            $groups[$key][] = $story;
        }

        $clusters = array();

        foreach ($groups as $key => $stories) {
            usort($stories, function ($a, $b) {
                if ($a['score'] === $b['score']) {
                    return strcmp($b['published_at'], $a['published_at']);
                }

                return $b['score'] <=> $a['score'];
            });

            $master = $stories[0];
            $alternatives = array_slice($stories, 1, 6);
            $source_names = array_values(array_unique(array_map(function ($story) {
                return $story['source_name'];
            }, $stories)));

            $master['cluster_id'] = $key;
            $master['cluster_count'] = count($stories);
            $master['sources'] = count($source_names);
            $master['source_names'] = $source_names;
            $master['related_stories'] = array_values($alternatives);

            $clusters[] = $master;
        }

        return $clusters;
    }

    public static function same_story($left, $right) {
        if (!empty($left['cluster_id']) && !empty($right['cluster_id']) && $left['cluster_id'] === $right['cluster_id'] && 0 !== strpos($left['cluster_id'], 'post_')) {
            return true;
        }

        $left_time = strtotime($left['published_at']);
        $right_time = strtotime($right['published_at']);

        if ($left_time && $right_time && abs($left_time - $right_time) > 2 * DAY_IN_SECONDS) {
            return false;
        }

        return self::similarity($left['headline'], $right['headline']) >= 0.55;
    }

    public static function assign_post_cluster($post_id, $post, $update) {
        if (wp_is_post_revision($post_id) || 'post' !== get_post_type($post_id) || 'publish' !== get_post_status($post_id)) {
            return;
        }

        if (!get_post_meta($post_id, 'normalized_headline', true)) {
            update_post_meta($post_id, 'normalized_headline', Rifnote_Search_Source_Meta::normalize_headline(get_the_title($post_id)));
        }

        if (get_post_meta($post_id, 'story_cluster_id', true)) {
            return;
        }

        $recent = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => 40,
            'post__not_in' => array($post_id),
            'date_query' => array(array('after' => '2 days ago', 'inclusive' => true)),
            'orderby' => 'date',
            'order' => 'DESC',
        ));

        foreach ($recent as $candidate_id) {
            if (!self::within_window($post_id, $candidate_id)) {
                continue;
            }

            if (self::similarity(get_the_title($post_id), get_the_title($candidate_id)) < 0.55) {
                continue;
            }

            $cluster_id = get_post_meta($candidate_id, 'story_cluster_id', true);

            if (!$cluster_id) {
                $cluster_id = 'cluster_' . md5(Rifnote_Search_Source_Meta::normalize_headline(get_the_title($candidate_id)));
                update_post_meta($candidate_id, 'story_cluster_id', $cluster_id);
            }

            update_post_meta($post_id, 'story_cluster_id', $cluster_id);
            return;
        }

        update_post_meta($post_id, 'story_cluster_id', 'cluster_' . md5(Rifnote_Search_Source_Meta::normalize_headline(get_the_title($post_id))));
    }
}
