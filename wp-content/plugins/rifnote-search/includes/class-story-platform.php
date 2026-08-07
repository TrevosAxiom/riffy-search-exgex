<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Story_Platform {
    public static function cluster_payload($cluster_id) {
        $cluster_id = sanitize_text_field($cluster_id);

        if (!$cluster_id) {
            return new WP_Error('rifnote_missing_cluster', __('Story cluster is missing.', 'rifnote-search'), array('status' => 404));
        }

        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 30,
            'meta_query' => array(array('key' => 'story_cluster_id', 'value' => $cluster_id, 'compare' => '=')),
            'orderby' => 'date',
            'order' => 'DESC',
        ));

        if (!$posts && 0 === strpos($cluster_id, 'post_')) {
            $post = get_post((int) substr($cluster_id, 5));
            $posts = $post && 'publish' === $post->post_status ? array($post) : array();
        }

        if (!$posts) {
            return new WP_Error('rifnote_cluster_not_found', __('Story cluster not found.', 'rifnote-search'), array('status' => 404));
        }

        if (class_exists('Rifnote_Search_Aggregation') && !Rifnote_Search_Aggregation::cluster_is_intentional($cluster_id, $posts)) {
            return new WP_Error('rifnote_cluster_not_public', __('This story hub has not been curated yet.', 'rifnote-search'), array('status' => 404));
        }

        $request_args = array('query' => '', 'category' => '', 'date_range' => 'all', 'sort' => 'latest');
        $stories = array_values(array_filter(array_map(function ($post) use ($request_args) {
            return Rifnote_Search_Engine::result_payload($post->ID, $request_args);
        }, $posts)));
        $lead = $stories[0];
        $sources = array_values(array_unique(array_map(function ($story) {
            return $story['source_name'];
        }, $stories)));

        $timeline = self::timeline($cluster_id, $stories);
        $manual = class_exists('Rifnote_Search_Aggregation') ? Rifnote_Search_Aggregation::get($cluster_id) : null;

        return array(
            'cluster_id' => $cluster_id,
            'headline' => !empty($manual['title']) ? $manual['title'] : $lead['headline'],
            'summary' => !empty($manual['summary']) ? $manual['summary'] : self::summary($stories),
            'image_url' => !empty($manual['image_url']) ? esc_url_raw($manual['image_url']) : '',
            'manual_aggregation' => $manual ? array(
                'status' => $manual['status'],
                'category' => $manual['category'],
                'updated_at' => $manual['updated_at'],
            ) : null,
            'lead_story' => $lead,
            'stories' => $stories,
            'sources' => $sources,
            'source_count' => count($sources),
            'timeline' => $timeline,
            'timeline_summary' => self::timeline_summary($timeline),
            'related_searches' => self::related_searches($stories),
            'generated_at' => gmdate(DATE_ATOM),
        );
    }

    private static function summary($stories) {
        $lead = $stories[0];
        $count = count($stories);
        $source_count = count(array_unique(wp_list_pluck($stories, 'source_name')));

        return sprintf(
            __('%1$s is covered by %2$d story item(s) across %3$d source(s). Rifnote shows snippets and sends readers to publishers for the full story.', 'rifnote-search'),
            $lead['headline'],
            $count,
            $source_count
        );
    }

    private static function timeline($cluster_id, $stories) {
        $items = array_map(function ($story) {
            return array(
                'time' => $story['published_at'],
                'label' => $story['headline'],
                'source_name' => $story['source_name'],
                'url' => $story['read_full_story_url'],
                'type' => 'story',
            );
        }, $stories);

        foreach (Rifnote_Search_Platform_Insights::timeline_notes($cluster_id) as $note) {
            $items[] = array(
                'time' => $note['note_time'],
                'label' => $note['label'],
                'source_name' => $note['source_name'],
                'url' => esc_url_raw($note['source_url']),
                'type' => 'editor_note',
            );
        }

        usort($items, function ($a, $b) {
            return strcmp($a['time'], $b['time']);
        });

        return $items;
    }

    private static function timeline_summary($timeline) {
        if (!$timeline) {
            return array('first_update' => null, 'latest_update' => null, 'update_count' => 0);
        }

        return array(
            'first_update' => $timeline[0],
            'latest_update' => $timeline[count($timeline) - 1],
            'update_count' => count($timeline),
        );
    }

    private static function related_searches($stories) {
        $terms = array();

        foreach ($stories as $story) {
            $terms = array_merge($terms, array_slice(Rifnote_Search_Clustering::tokens($story['headline']), 0, 4));
        }

        return array_values(array_slice(array_unique($terms), 0, 8));
    }
}
