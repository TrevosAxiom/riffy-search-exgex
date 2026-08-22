<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_REST_API {
    public static function request_args(WP_REST_Request $request) {
        $date_range = sanitize_key((string) ($request->get_param('date_range') ? $request->get_param('date_range') : 'all'));
        $sort = sanitize_key((string) ($request->get_param('sort') ? $request->get_param('sort') : 'relevance'));

        if (!in_array($date_range, array('all', '24h', '7d', '30d'), true)) {
            $date_range = 'all';
        }

        if (!in_array($sort, array('relevance', 'latest'), true)) {
            $sort = 'relevance';
        }

        return array(
            'query' => sanitize_text_field((string) $request->get_param('q')),
            'category' => sanitize_title((string) $request->get_param('category')),
            'date_range' => $date_range,
            'sort' => $sort,
            'visitor_id' => sanitize_text_field((string) $request->get_param('visitor_id')),
            'session_id' => sanitize_text_field((string) $request->get_param('session_id')),
            'metadata' => array(
                'device_type' => sanitize_key((string) $request->get_param('device_type')),
                'page_type' => 'search',
            ),
        );
    }

    public static function register_routes() {
        register_rest_route('rifnote/v1', '/health', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'health'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/settings', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'settings'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/home-lead', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'home_lead'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/home-notes', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'home_notes'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/search', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'search'),
            'permission_callback' => '__return_true',
            'args' => array(
                'q' => array('sanitize_callback' => 'sanitize_text_field'),
                'category' => array('sanitize_callback' => 'sanitize_title'),
                'date_range' => array('sanitize_callback' => 'sanitize_key'),
                'sort' => array('sanitize_callback' => 'sanitize_key'),
                'page' => array('sanitize_callback' => 'absint'),
                'per_page' => array('sanitize_callback' => 'absint'),
            ),
        ));

        register_rest_route('rifnote/v1', '/suggest', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'suggest'),
            'permission_callback' => '__return_true',
            'args' => array(
                'q' => array('sanitize_callback' => 'sanitize_text_field'),
                'limit' => array('sanitize_callback' => 'absint'),
            ),
        ));

        register_rest_route('rifnote/v1', '/no-result/subscribe', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'no_result_subscribe'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/story/(?P<cluster_id>[^/]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'story_cluster'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/source/(?P<domain>[^/]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'source_profile'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/trending', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'trending'),
            'permission_callback' => '__return_true',
            'args' => array(
                'limit' => array('sanitize_callback' => 'absint'),
            ),
        ));

        register_rest_route('rifnote/v1', '/ai-answer', array(
            'methods' => array(WP_REST_Server::READABLE, WP_REST_Server::CREATABLE),
            'callback' => array(__CLASS__, 'ai_answer'),
            'permission_callback' => '__return_true',
            'args' => array(
                'q' => array('sanitize_callback' => 'sanitize_text_field'),
                'category' => array('sanitize_callback' => 'sanitize_title'),
                'date_range' => array('sanitize_callback' => 'sanitize_key'),
                'sort' => array('sanitize_callback' => 'sanitize_key'),
            ),
        ));

        register_rest_route('rifnote/v1', '/submit-post', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'submit_post'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/publisher/signup', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'publisher_signup'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/advertiser/signup', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'advertiser_signup'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/legal-request', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'legal_request'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/beta-feedback', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'beta_feedback'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/publisher/stats', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'publisher_stats'),
            'permission_callback' => array(__CLASS__, 'publisher_permission'),
            'args' => array(
                'publisher_id' => array('sanitize_callback' => 'absint'),
            ),
        ));

        register_rest_route('rifnote/v1', '/publisher/verify', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'publisher_verify'),
            'permission_callback' => '__return_true',
            'args' => array(
                'publisher_id' => array('sanitize_callback' => 'absint'),
                'token' => array('sanitize_callback' => 'sanitize_text_field'),
            ),
        ));

        register_rest_route('rifnote/v1', '/publisher/api/submit', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'publisher_api_submit'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/publisher/api/events', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'publisher_api_events'),
            'permission_callback' => '__return_true',
            'args' => array(
                'limit' => array('sanitize_callback' => 'absint'),
            ),
        ));

        register_rest_route('rifnote/v1', '/publisher/api/webhooks', array(
            'methods' => array(WP_REST_Server::READABLE, WP_REST_Server::CREATABLE),
            'callback' => array(__CLASS__, 'publisher_api_webhooks'),
            'permission_callback' => '__return_true',
            'args' => array(
                'limit' => array('sanitize_callback' => 'absint'),
            ),
        ));

        register_rest_route('rifnote/v1', '/publisher/api/profile', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'publisher_api_profile'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/customgpt/import/batch', array(
            'methods' => array(WP_REST_Server::READABLE, WP_REST_Server::CREATABLE),
            'callback' => array(__CLASS__, 'customgpt_import_batch'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/customgpt/stories', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'customgpt_stories'),
            'permission_callback' => '__return_true',
            'args' => array(
                'limit' => array('sanitize_callback' => 'absint'),
                'status' => array('sanitize_callback' => 'sanitize_key'),
                'source_type' => array('sanitize_callback' => 'sanitize_key'),
                'origin_model' => array('sanitize_callback' => 'sanitize_text_field'),
                'origin_model_not' => array('sanitize_callback' => 'sanitize_text_field'),
                'category' => array('sanitize_callback' => 'sanitize_title'),
                'missing_summary' => array('sanitize_callback' => 'rest_sanitize_boolean'),
                'incomplete' => array('sanitize_callback' => 'rest_sanitize_boolean'),
                'q' => array('sanitize_callback' => 'sanitize_text_field'),
            ),
        ));

        register_rest_route('rifnote/v1', '/customgpt/format/batch', array(
            'methods' => array(WP_REST_Server::READABLE, WP_REST_Server::CREATABLE),
            'callback' => array(__CLASS__, 'customgpt_format_batch'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/customgpt/aggregation/batch', array(
            'methods' => array(WP_REST_Server::READABLE, WP_REST_Server::CREATABLE),
            'callback' => array(__CLASS__, 'customgpt_aggregation_batch'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/customgpt/trending/signals', array(
            'methods' => array(WP_REST_Server::READABLE, WP_REST_Server::CREATABLE),
            'callback' => array(__CLASS__, 'customgpt_trending_signals'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/customgpt/social/batch', array(
            'methods' => array(WP_REST_Server::READABLE, WP_REST_Server::CREATABLE),
            'callback' => array(__CLASS__, 'customgpt_social_batch'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/social/oembed', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'social_oembed'),
            'permission_callback' => '__return_true',
            'args' => array(
                'url' => array('sanitize_callback' => 'esc_url_raw'),
            ),
        ));

        register_rest_route('rifnote/v1', '/social/import', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'social_import'),
            'permission_callback' => array(__CLASS__, 'manage_options_permission'),
        ));

        register_rest_route('rifnote/v1', '/social/youtube/run', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'social_youtube_run'),
            'permission_callback' => array(__CLASS__, 'manage_options_permission'),
        ));

        register_rest_route('rifnote/v1', '/ingestion-health', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'ingestion_health'),
            'permission_callback' => array(__CLASS__, 'manage_options_permission'),
        ));

        register_rest_route('rifnote/v1', '/feed-diagnostics/(?P<publisher_id>\d+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'feed_diagnostics'),
            'permission_callback' => array(__CLASS__, 'publisher_permission'),
        ));

        register_rest_route('rifnote/v1', '/ingest-rss', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'ingest_rss'),
            'permission_callback' => array(__CLASS__, 'manage_options_permission'),
        ));

        register_rest_route('rifnote/v1', '/analytics/event', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'analytics_event'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/analytics/summary', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'analytics_summary'),
            'permission_callback' => array(__CLASS__, 'manage_options_permission'),
            'args' => array(
                'days' => array('sanitize_callback' => 'absint'),
            ),
        ));

        register_rest_route('rifnote/v1', '/editorial-console', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'editorial_console'),
            'permission_callback' => array(__CLASS__, 'manage_options_permission'),
        ));

        register_rest_route('rifnote/v1', '/ranking-simulate', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'ranking_simulate'),
            'permission_callback' => array(__CLASS__, 'manage_options_permission'),
        ));

        register_rest_route('rifnote/v1', '/daily-briefing', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'daily_briefing'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/football/live', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'football_live'),
            'permission_callback' => '__return_true',
            'args' => array(
                'force' => array('sanitize_callback' => 'rest_sanitize_boolean'),
            ),
        ));

        register_rest_route('rifnote/v1', '/football/fixtures', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'football_fixtures'),
            'permission_callback' => '__return_true',
            'args' => array(
                'date' => array('sanitize_callback' => 'sanitize_text_field'),
                'league' => array('sanitize_callback' => 'absint'),
                'season' => array('sanitize_callback' => 'absint'),
                'force' => array('sanitize_callback' => 'rest_sanitize_boolean'),
            ),
        ));

        register_rest_route('rifnote/v1', '/football/watchlist', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'football_watchlist'),
            'permission_callback' => '__return_true',
            'args' => array(
                'date' => array('sanitize_callback' => 'sanitize_text_field'),
                'force' => array('sanitize_callback' => 'rest_sanitize_boolean'),
            ),
        ));

        register_rest_route('rifnote/v1', '/football/standings', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'football_standings'),
            'permission_callback' => '__return_true',
            'args' => array(
                'league' => array('sanitize_callback' => 'absint'),
                'season' => array('sanitize_callback' => 'absint'),
                'force' => array('sanitize_callback' => 'rest_sanitize_boolean'),
            ),
        ));

        register_rest_route('rifnote/v1', '/football/upcoming', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'football_upcoming'),
            'permission_callback' => '__return_true',
            'args' => array(
                'next' => array('sanitize_callback' => 'absint'),
                'force' => array('sanitize_callback' => 'rest_sanitize_boolean'),
            ),
        ));

        register_rest_route('rifnote/v1', '/football/finished', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'football_finished'),
            'permission_callback' => '__return_true',
            'args' => array(
                'limit' => array('sanitize_callback' => 'absint'),
                'force' => array('sanitize_callback' => 'rest_sanitize_boolean'),
            ),
        ));

        register_rest_route('rifnote/v1', '/football/teams', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'football_teams'),
            'permission_callback' => '__return_true',
            'args' => array(
                'league' => array('sanitize_callback' => 'absint'),
                'season' => array('sanitize_callback' => 'absint'),
                'limit' => array('sanitize_callback' => 'absint'),
            ),
        ));

        register_rest_route('rifnote/v1', '/football/team/(?P<team_id>\d+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'football_team_profile'),
            'permission_callback' => '__return_true',
            'args' => array(
                'team_id' => array('sanitize_callback' => 'absint'),
                'limit' => array('sanitize_callback' => 'absint'),
            ),
        ));

        register_rest_route('rifnote/v1', '/football/players', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'football_players'),
            'permission_callback' => '__return_true',
            'args' => array(
                'team' => array('sanitize_callback' => 'absint'),
                'limit' => array('sanitize_callback' => 'absint'),
            ),
        ));

        register_rest_route('rifnote/v1', '/football/player', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'football_player_profile'),
            'permission_callback' => '__return_true',
            'args' => array(
                'player_id' => array('sanitize_callback' => 'absint'),
                'player_name' => array('sanitize_callback' => 'sanitize_text_field'),
                'limit' => array('sanitize_callback' => 'absint'),
            ),
        ));

        register_rest_route('rifnote/v1', '/football/transfers', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'football_transfers'),
            'permission_callback' => '__return_true',
            'args' => array(
                'limit' => array('sanitize_callback' => 'absint'),
            ),
        ));

        register_rest_route('rifnote/v1', '/football/fixture/(?P<fixture_id>\d+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'football_fixture_details'),
            'permission_callback' => '__return_true',
            'args' => array(
                'fixture_id' => array('sanitize_callback' => 'absint'),
                'force' => array('sanitize_callback' => 'rest_sanitize_boolean'),
            ),
        ));

        register_rest_route('rifnote/v1', '/live/weather', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'live_weather'),
            'permission_callback' => '__return_true',
            'args' => array(
                'force' => array('sanitize_callback' => 'rest_sanitize_boolean'),
                'latitude' => array('sanitize_callback' => 'floatval'),
                'longitude' => array('sanitize_callback' => 'floatval'),
                'label' => array('sanitize_callback' => 'sanitize_text_field'),
            ),
        ));

        register_rest_route('rifnote/v1', '/live/weather/world', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'live_weather_world'),
            'permission_callback' => '__return_true',
            'args' => array(
                'force' => array('sanitize_callback' => 'rest_sanitize_boolean'),
            ),
        ));

        register_rest_route('rifnote/v1', '/live/markets', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'live_markets'),
            'permission_callback' => '__return_true',
            'args' => array(
                'force' => array('sanitize_callback' => 'rest_sanitize_boolean'),
            ),
        ));

        register_rest_route('rifnote/v1', '/preferences', array(
            'methods' => array(WP_REST_Server::READABLE, WP_REST_Server::CREATABLE),
            'callback' => array(__CLASS__, 'preferences'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/for-you', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'for_you'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/alerts', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'alerts'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/newsletter', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'newsletter'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/newsletter/unsubscribe', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'newsletter_unsubscribe'),
            'permission_callback' => '__return_true',
            'args' => array(
                'token' => array('sanitize_callback' => 'sanitize_text_field'),
            ),
        ));

        register_rest_route('rifnote/v1', '/newsletter/send-digest', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'send_newsletter_digest'),
            'permission_callback' => array(__CLASS__, 'manage_options_permission'),
        ));

        register_rest_route('rifnote/v1', '/device', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'device'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/notifications', array(
            'methods' => array(WP_REST_Server::READABLE, WP_REST_Server::CREATABLE),
            'callback' => array(__CLASS__, 'notifications'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/notifications/process-push', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'process_push_notifications'),
            'permission_callback' => array(__CLASS__, 'manage_options_permission'),
        ));

        register_rest_route('rifnote/v1', '/alerts/process', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'process_alerts'),
            'permission_callback' => array(__CLASS__, 'manage_options_permission'),
        ));

        register_rest_route('rifnote/v1', '/widget/(?P<widget>[a-z0-9-]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'widget'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/sponsored/click', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'sponsored_click'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/sponsored/conversion', array(
            'methods' => array(WP_REST_Server::READABLE, WP_REST_Server::CREATABLE),
            'callback' => array(__CLASS__, 'sponsored_conversion'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/sponsor/request', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'sponsor_request'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/media/upload', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'media_upload'),
            'permission_callback' => array(__CLASS__, 'logged_in_permission'),
        ));

        register_rest_route('rifnote/v1', '/admin/post/(?P<id>\d+)/trash', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'trash_post'),
            'permission_callback' => array(__CLASS__, 'post_admin_permission'),
            'args' => array(
                'id' => array('sanitize_callback' => 'absint'),
            ),
        ));

        register_rest_route('rifnote/v1', '/advertiser/dashboard', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'advertiser_dashboard'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/advertiser/payment-proof', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'advertiser_payment_proof'),
            'permission_callback' => array(__CLASS__, 'logged_in_permission'),
        ));

        register_rest_route('rifnote/v1', '/advertiser/profile', array(
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => array(__CLASS__, 'advertiser_profile'),
            'permission_callback' => array(__CLASS__, 'logged_in_permission'),
        ));

        register_rest_route('rifnote/v1', '/ads/inventory', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'ads_inventory'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('rifnote/v1', '/launch-readiness', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'launch_readiness'),
            'permission_callback' => array(__CLASS__, 'manage_options_permission'),
        ));

        register_rest_route('rifnote/v1', '/release/readiness', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'release_readiness'),
            'permission_callback' => array(__CLASS__, 'manage_options_permission'),
        ));

        register_rest_route('rifnote/v1', '/search-index/reindex', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'reindex_search'),
            'permission_callback' => array(__CLASS__, 'manage_options_permission'),
        ));
    }

    public static function manage_options_permission() {
        return current_user_can('manage_options');
    }

    public static function logged_in_permission() {
        return is_user_logged_in();
    }

    public static function post_admin_permission(WP_REST_Request $request) {
        return current_user_can('delete_post', absint($request['id']));
    }

    public static function publisher_permission() {
        return is_user_logged_in() || current_user_can('manage_options');
    }

    public static function health() {
        return rest_ensure_response(array('ok' => true, 'version' => RIFNOTE_SEARCH_VERSION));
    }

    public static function settings() {
        $ai = Rifnote_Search_AI::settings();

        return rest_ensure_response(array(
            'ai_enabled' => $ai['enabled'],
            'ai_configured' => !empty($ai['api_key']),
            'model' => $ai['model'],
            'home_pills' => class_exists('Rifnote_Search_Admin') ? Rifnote_Search_Admin::home_pills() : array(),
        ));
    }

    public static function home_lead() {
        $post_id = absint(get_option('rifnote_home_lead_post_id', 0));

        if (!$post_id) {
            return rest_ensure_response(array(
                'configured' => false,
                'story' => null,
            ));
        }

        $post = get_post($post_id);

        if (!$post || 'post' !== $post->post_type || 'publish' !== $post->post_status) {
            return rest_ensure_response(array(
                'configured' => false,
                'story' => null,
                'message' => __('Selected homepage lead story is not published.', 'rifnote-search'),
            ));
        }

        $request_args = array('query' => '', 'category' => '', 'date_range' => 'all', 'sort' => 'relevance');
        $story = Rifnote_Search_Engine::result_payload($post_id, $request_args);

        return rest_ensure_response(array(
            'configured' => true,
            'story' => $story,
        ));
    }

    public static function home_notes(WP_REST_Request $request = null) {
        $pill = $request ? sanitize_text_field((string) $request->get_param('pill')) : 'Notes';
        $pill = $pill ? $pill : 'Notes';
        $pill_key = class_exists('Rifnote_Search_Admin') ? Rifnote_Search_Admin::home_pill_key($pill) : sanitize_key($pill);
        $is_featured = class_exists('Rifnote_Search_Admin') && Rifnote_Search_Admin::is_featured_home_pill($pill, $pill_key);
        $is_notes = !$is_featured && (0 === strcasecmp($pill, 'Notes') || 'notes' === $pill_key);
        $post_ids = array();

        if ($is_featured) {
            $post_ids = Rifnote_Search_Admin::home_featured_tab_post_ids(6);
        } elseif ($is_notes) {
            $manual_post_ids = get_option('rifnote_home_note_post_ids', array());
            $notes_category_id = class_exists('Rifnote_Search_Admin') ? Rifnote_Search_Admin::ensure_notes_category() : 0;

            if ($notes_category_id) {
                $category_post_ids = get_posts(array(
                    'post_type' => 'post',
                    'post_status' => 'publish',
                    'posts_per_page' => 12,
                    'orderby' => 'date',
                    'order' => 'DESC',
                    'fields' => 'ids',
                    'cat' => $notes_category_id,
                ));

                $post_ids = array_values(array_unique(array_merge(
                    is_array($category_post_ids) ? array_map('absint', $category_post_ids) : array(),
                    is_array($manual_post_ids) ? array_map('absint', $manual_post_ids) : array()
                )));
            } else {
                $post_ids = is_array($manual_post_ids) ? $manual_post_ids : array();
            }
        } elseif (class_exists('Rifnote_Search_Admin')) {
            $post_ids = get_posts(array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => 6,
                'orderby' => 'modified',
                'order' => 'DESC',
                'fields' => 'ids',
                'meta_query' => array(
                    array(
                        'key' => Rifnote_Search_Admin::HOME_PILL_META,
                        'value' => $pill_key,
                        'compare' => '=',
                    ),
                ),
            ));
        }

        $limit = 5;
        $post_ids = is_array($post_ids) ? array_slice(array_map('absint', $post_ids), 0, $limit) : array();
        $has_curated_posts = !empty($post_ids);
        $request_args = array('query' => '', 'category' => '', 'date_range' => 'all', 'sort' => 'relevance');
        $stories = array();

        foreach ($post_ids as $post_id) {
            $post = $post_id ? get_post($post_id) : null;
            if (!$post || 'post' !== $post->post_type || 'publish' !== $post->post_status) {
                continue;
            }

            $story = Rifnote_Search_Engine::result_payload($post_id, $request_args);

            if ($story) {
                $content = has_blocks($post) ? do_blocks($post->post_content) : wpautop($post->post_content);
                $content = do_shortcode($content);

                if (class_exists('Rifnote_Search_Social')) {
                    $content = Rifnote_Search_Social::filter_content_embeds($content);
                }

                $story['permalink'] = esc_url_raw(get_permalink($post_id));
                $story['share_url'] = $story['permalink'];
                $story['full_content'] = wp_kses_post($content);
                $story['raw_content'] = Rifnote_Search_Source_Meta::normalize_text(wp_strip_all_tags($post->post_content), true);
                $stories[] = $story;
            }
        }

        if (!$stories && !$is_notes && !$is_featured) {
            $fallback = Rifnote_Search_Engine::payload(array(
                'query' => '',
                'category' => $pill,
                'date_range' => 'all',
                'sort' => 'latest',
            ), 1, $limit);
            $stories = is_array($fallback['results'] ?? null) ? $fallback['results'] : array();
        }

        $archive_url = $is_featured && class_exists('Rifnote_Search_Admin') ? Rifnote_Search_Admin::home_featured_tab_archive_url() : '';
        $archive_term = $archive_url ? null : get_term_by('slug', $pill_key, 'category');

        if (!$archive_term || is_wp_error($archive_term)) {
            $archive_term = get_term_by('name', $pill, 'category');
        }

        if ($archive_term && !is_wp_error($archive_term)) {
            $archive_link = get_term_link($archive_term);
            $archive_url = is_wp_error($archive_link) ? '' : $archive_link;
        }

        return rest_ensure_response(array(
            'configured' => !empty($stories),
            'stories' => $stories,
            'pill' => $pill,
            'is_notes' => $is_notes,
            'is_featured' => $is_featured,
            'curated' => $has_curated_posts,
            'limit' => $limit,
            'archive_url' => $archive_url,
        ));
    }

    public static function search(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('search', 120, MINUTE_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        $page = max(1, (int) $request->get_param('page'));
        $per_page = min(20, max(1, (int) ($request->get_param('per_page') ? $request->get_param('per_page') : 10)));
        $args = self::request_args($request);
        $cache_args = array_intersect_key($args, array_flip(array('query', 'category', 'date_range', 'sort')));
        $cache_key = 'rifnote_search_' . md5(wp_json_encode(array($cache_args, $page, $per_page, Rifnote_Search_Beta::ranking_version())));
        $payload = get_transient($cache_key);

        if (is_array($payload)) {
            Rifnote_Search_Analytics::log_search($args, $payload);
            return rest_ensure_response($payload);
        }

        $payload = Rifnote_Search_Engine::payload($args, $page, $per_page);
        set_transient($cache_key, $payload, MINUTE_IN_SECONDS);

        Rifnote_Search_Analytics::log_search($args, $payload);

        return rest_ensure_response($payload);
    }

    public static function trending(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('trending', 120, MINUTE_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        $limit = min(30, max(1, (int) ($request->get_param('limit') ? $request->get_param('limit') : 10)));

        return rest_ensure_response(Rifnote_Search_Trending::rest_payload($limit));
    }

    public static function suggest(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('suggest', 120, MINUTE_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        return rest_ensure_response(array(
            'suggestions' => Rifnote_Search_Index::suggestions((string) $request->get_param('q'), (int) ($request->get_param('limit') ? $request->get_param('limit') : 8)),
        ));
    }

    public static function story_cluster(WP_REST_Request $request) {
        $payload = Rifnote_Search_Story_Platform::cluster_payload(rawurldecode((string) $request->get_param('cluster_id')));

        if (is_wp_error($payload)) {
            return $payload;
        }

        return rest_ensure_response($payload);
    }

    public static function no_result_subscribe(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('no_result_subscribe', 8, HOUR_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        $result = Rifnote_Search_Platform_Insights::subscribe_no_result($data['query'] ?? '', $data['email'] ?? '', $data['category'] ?? '');

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public static function source_profile(WP_REST_Request $request) {
        $payload = Rifnote_Search_Source_Trust::source_payload(rawurldecode((string) $request->get_param('domain')));

        if (is_wp_error($payload)) {
            return $payload;
        }

        return rest_ensure_response($payload);
    }

    public static function ai_answer(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('ai_answer', 20, MINUTE_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        $args = self::request_args($request);
        $answer = Rifnote_Search_AI::answer_payload($args['query'], $args);

        if (is_wp_error($answer)) {
            Rifnote_Search_Hardening::log_error('ai_answer', $answer->get_error_message(), array('query' => $args['query']), 'warning');
            return $answer;
        }

        return rest_ensure_response($answer);
    }

    public static function submit_post(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('submit_post', 8, 10 * MINUTE_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        if (!empty($data['website'])) {
            Rifnote_Search_Launch_Readiness::log_suspicious('honeypot', 'Publisher submission honeypot filled.', array('endpoint' => 'submit-post'));
            return new WP_Error('rifnote_suspicious_submission', __('Submission could not be accepted.', 'rifnote-search'), array('status' => 400));
        }

        $submission = Rifnote_Search_Publishers::submit(is_array($data) ? $data : array());

        if (is_wp_error($submission)) {
            Rifnote_Search_Hardening::log_error('submit_post', $submission->get_error_message(), array('code' => $submission->get_error_code()), 'warning');
            return $submission;
        }

        return rest_ensure_response($submission);
    }

    public static function publisher_signup(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('publisher_signup', 8, HOUR_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        if (!empty($data['website'])) {
            Rifnote_Search_Launch_Readiness::log_suspicious('honeypot', 'Publisher signup honeypot filled.', array('endpoint' => 'publisher-signup'));
            return new WP_Error('rifnote_suspicious_publisher_signup', __('Signup could not be accepted.', 'rifnote-search'), array('status' => 400));
        }

        $result = Rifnote_Search_Publishers::signup(is_array($data) ? $data : array());

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public static function advertiser_signup(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('advertiser_signup', 8, HOUR_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        if (!empty($data['website'])) {
            Rifnote_Search_Launch_Readiness::log_suspicious('honeypot', 'Advertiser signup honeypot filled.', array('endpoint' => 'advertiser-signup'));
            return new WP_Error('rifnote_suspicious_advertiser_signup', __('Signup could not be accepted.', 'rifnote-search'), array('status' => 400));
        }

        $result = Rifnote_Search_Launch_Readiness::advertiser_signup(is_array($data) ? $data : array());

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public static function legal_request(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('legal_request', 5, HOUR_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        if (!empty($data['website'])) {
            Rifnote_Search_Launch_Readiness::log_suspicious('honeypot', 'Legal request honeypot filled.', array('endpoint' => 'legal-request'));
            return new WP_Error('rifnote_suspicious_legal_request', __('Request could not be accepted.', 'rifnote-search'), array('status' => 400));
        }

        $request_result = Rifnote_Search_Legal::submit_request(is_array($data) ? $data : array());

        if (is_wp_error($request_result)) {
            Rifnote_Search_Hardening::log_error('legal_request', $request_result->get_error_message(), array('code' => $request_result->get_error_code()), 'warning');
            return $request_result;
        }

        return rest_ensure_response($request_result);
    }

    public static function beta_feedback(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('beta_feedback', 10, HOUR_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        if (!empty($data['website'])) {
            Rifnote_Search_Launch_Readiness::log_suspicious('honeypot', 'Beta feedback honeypot filled.', array('endpoint' => 'beta-feedback'));
            return new WP_Error('rifnote_suspicious_feedback', __('Feedback could not be accepted.', 'rifnote-search'), array('status' => 400));
        }

        $feedback = Rifnote_Search_Beta::submit_feedback(is_array($data) ? $data : array());

        if (is_wp_error($feedback)) {
            Rifnote_Search_Hardening::log_error('beta_feedback', $feedback->get_error_message(), array('code' => $feedback->get_error_code()), 'warning');
            return $feedback;
        }

        return rest_ensure_response($feedback);
    }

    public static function publisher_stats(WP_REST_Request $request) {
        $stats = Rifnote_Search_Publishers::dashboard_stats((int) $request->get_param('publisher_id'));

        if (is_wp_error($stats)) {
            return $stats;
        }

        return rest_ensure_response($stats);
    }

    public static function publisher_verify(WP_REST_Request $request) {
        $result = Rifnote_Search_Publishers::verify_publisher((int) $request->get_param('publisher_id'), (string) $request->get_param('token'));

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    private static function publisher_from_api_request(WP_REST_Request $request) {
        $key = (string) $request->get_header('x-rifnote-api-key');

        if (!$key) {
            $auth = (string) $request->get_header('authorization');

            if (preg_match('/Bearer\s+(.+)/i', $auth, $matches)) {
                $key = trim($matches[1]);
            }
        }

        return Rifnote_Search_Publishers::authenticate_api_key($key);
    }

    private static function customgpt_key_from_request(WP_REST_Request $request) {
        $key = (string) $request->get_header('x-rifnote-customgpt-key');

        if (!$key) {
            $auth = (string) $request->get_header('authorization');

            if (preg_match('/Bearer\s+(.+)/i', $auth, $matches)) {
                $key = trim($matches[1]);
            }
        }

        return $key;
    }

    private static function customgpt_endpoint_info($operation_id, $path, $fields = array()) {
        return rest_ensure_response(array(
            'ok' => true,
            'service' => 'Rifnote CustomGPT Actions',
            'operationId' => $operation_id,
            'endpoint' => rest_url('rifnote/v1' . $path),
            'browser_check' => 'alive',
            'message' => __('This endpoint is live. Send a POST request with JSON to run the action.', 'rifnote-search'),
            'method' => 'POST',
            'auth' => array(
                'Authorization' => 'Bearer YOUR_KEY',
                'X-Rifnote-CustomGPT-Key' => 'YOUR_KEY',
            ),
            'expected_fields' => array_values($fields),
        ));
    }

    public static function customgpt_import_batch(WP_REST_Request $request) {
        if ('GET' === $request->get_method()) {
            return self::customgpt_endpoint_info('importRifnoteStories', '/customgpt/import/batch', array('stories', 'payload_json'));
        }

        $rate = Rifnote_Search_Hardening::rate_limit('customgpt_import_batch', 30, HOUR_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        $auth = Rifnote_Search_CustomGPT_Import::authenticate(self::customgpt_key_from_request($request));

        if (is_wp_error($auth)) {
            return $auth;
        }

        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        if (!is_array($data) || !$data) {
            $data = $request->get_body();
        }

        $result = Rifnote_Search_CustomGPT_Import::import_batch($data);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public static function customgpt_stories(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('customgpt_stories', 120, HOUR_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        $auth = Rifnote_Search_CustomGPT_Import::authenticate(self::customgpt_key_from_request($request));

        if (is_wp_error($auth)) {
            return $auth;
        }

        return rest_ensure_response(Rifnote_Search_CustomGPT_Import::export_stories(array(
            'limit' => $request->get_param('limit'),
            'status' => $request->get_param('status'),
            'source_type' => $request->get_param('source_type'),
            'origin_model' => $request->get_param('origin_model'),
            'origin_model_not' => $request->get_param('origin_model_not'),
            'category' => $request->get_param('category'),
            'missing_summary' => $request->get_param('missing_summary'),
            'incomplete' => $request->get_param('incomplete'),
            'q' => $request->get_param('q'),
        )));
    }

    public static function customgpt_format_batch(WP_REST_Request $request) {
        if ('GET' === $request->get_method()) {
            return self::customgpt_endpoint_info('formatRifnoteStories', '/customgpt/format/batch', array('stories', 'payload_json'));
        }

        $rate = Rifnote_Search_Hardening::rate_limit('customgpt_format_batch', 60, HOUR_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        $auth = Rifnote_Search_CustomGPT_Import::authenticate(self::customgpt_key_from_request($request));

        if (is_wp_error($auth)) {
            return $auth;
        }

        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        if (!is_array($data) || !$data) {
            $data = $request->get_body();
        }

        $result = Rifnote_Search_CustomGPT_Import::format_batch($data);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public static function customgpt_aggregation_batch(WP_REST_Request $request) {
        if ('GET' === $request->get_method()) {
            return self::customgpt_endpoint_info('aggregateRifnoteStories', '/customgpt/aggregation/batch', array('clusters', 'stories', 'trending_signals'));
        }

        $rate = Rifnote_Search_Hardening::rate_limit('customgpt_aggregation_batch', 60, HOUR_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        $auth = Rifnote_Search_CustomGPT_Import::authenticate(self::customgpt_key_from_request($request));

        if (is_wp_error($auth)) {
            return $auth;
        }

        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        $result = Rifnote_Search_Aggregation::customgpt_batch(is_array($data) ? $data : array());

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public static function customgpt_trending_signals(WP_REST_Request $request) {
        if ('GET' === $request->get_method()) {
            return self::customgpt_endpoint_info('sendRifnoteTrendingSignals', '/customgpt/trending/signals', array('signals', 'trending_signals'));
        }

        $rate = Rifnote_Search_Hardening::rate_limit('customgpt_trending_signals', 120, HOUR_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        $auth = Rifnote_Search_CustomGPT_Import::authenticate(self::customgpt_key_from_request($request));

        if (is_wp_error($auth)) {
            return $auth;
        }

        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        $signals = isset($data['trending_signals']) ? $data['trending_signals'] : ($data['signals'] ?? array());
        $result = Rifnote_Search_Trending::add_signals(is_array($signals) ? $signals : array(), array(
            'source_model' => sanitize_text_field((string) ($data['source_model'] ?? $data['origin_model'] ?? 'GPT')),
            'source_actor' => sanitize_text_field((string) ($data['source'] ?? 'CustomGPT')),
            'batch_id' => sanitize_text_field((string) ($data['batch_id'] ?? '')),
        ));

        Rifnote_Search_CustomGPT_Import::log_event(array_merge($result, array(
            'event' => 'trending_signals_imported',
            'status' => !empty($result['ok']) ? 'ok' : 'partial',
        )));

        return rest_ensure_response($result);
    }

    public static function customgpt_social_batch(WP_REST_Request $request) {
        if ('GET' === $request->get_method()) {
            return self::customgpt_endpoint_info('importRifnoteSocialStories', '/customgpt/social/batch', array('stories'));
        }

        $rate = Rifnote_Search_Hardening::rate_limit('customgpt_social_batch', 60, HOUR_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        $auth = Rifnote_Search_CustomGPT_Import::authenticate(self::customgpt_key_from_request($request));

        if (is_wp_error($auth)) {
            return $auth;
        }

        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        $result = Rifnote_Search_Social::customgpt_social_batch(is_array($data) ? $data : array());

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public static function social_oembed(WP_REST_Request $request) {
        $url = esc_url_raw((string) $request->get_param('url'));

        if (!$url) {
            return new WP_Error('rifnote_social_missing_url', __('A URL is required.', 'rifnote-search'), array('status' => 400));
        }

        return rest_ensure_response(Rifnote_Search_Social::preview_url($url));
    }

    public static function social_import(WP_REST_Request $request) {
        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        $result = Rifnote_Search_Social::import_manual_url(is_array($data) ? $data : array());

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public static function social_youtube_run(WP_REST_Request $request) {
        return rest_ensure_response(Rifnote_Search_Social::run_youtube_import(true));
    }

    public static function publisher_api_submit(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('publisher_api_submit', 60, HOUR_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        $publisher = self::publisher_from_api_request($request);

        if (is_wp_error($publisher)) {
            Rifnote_Search_Publishers::log_event(0, 'api_auth_failed', 'blocked', $publisher->get_error_message());
            return $publisher;
        }

        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        $result = Rifnote_Search_Publishers::submit_via_api($publisher, is_array($data) ? $data : array());

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public static function publisher_api_events(WP_REST_Request $request) {
        $publisher = self::publisher_from_api_request($request);

        if (is_wp_error($publisher)) {
            return $publisher;
        }

        return rest_ensure_response(array(
            'publisher_id' => (int) $publisher['id'],
            'events' => Rifnote_Search_Publishers::recent_events((int) $publisher['id'], (int) ($request->get_param('limit') ? $request->get_param('limit') : 20)),
        ));
    }

    public static function publisher_api_webhooks(WP_REST_Request $request) {
        $publisher = self::publisher_from_api_request($request);

        if (is_wp_error($publisher)) {
            return $publisher;
        }

        if ('GET' === $request->get_method()) {
            $limit = (int) ($request->get_param('limit') ? $request->get_param('limit') : 20);

            return rest_ensure_response(array(
                'publisher_id' => (int) $publisher['id'],
                'webhooks' => Rifnote_Search_Publishers::webhooks((int) $publisher['id'], $limit),
                'deliveries' => Rifnote_Search_Publishers::recent_webhook_deliveries((int) $publisher['id'], $limit),
            ));
        }

        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        $result = Rifnote_Search_Publishers::register_webhook($publisher, is_array($data) ? $data : array());

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public static function publisher_api_profile(WP_REST_Request $request) {
        $publisher = self::publisher_from_api_request($request);

        if (is_wp_error($publisher)) {
            return $publisher;
        }

        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        $result = Rifnote_Search_Publishers::update_self_service_profile($publisher, is_array($data) ? $data : array());

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public static function ingestion_health() {
        return rest_ensure_response(array(
            'feeds' => Rifnote_Search_Ingestion::feed_health_summary(50),
            'last_run' => get_option('rifnote_search_ingestion_last_run', array()),
            'next_run' => wp_next_scheduled(Rifnote_Search_Ingestion::CRON_HOOK),
            'queue_preview' => Rifnote_Search_Ingestion::queue_preview(),
            'recent_logs' => Rifnote_Search_Ingestion::recent_logs(12),
            'smart_rss' => array(
                'enabled' => (bool) get_option('rifnote_smart_rss_enabled', true),
                'total_feeds' => count(Rifnote_Search_Ingestion::smart_feeds()),
                'batch_size' => (int) get_option('rifnote_smart_rss_batch_size', 25),
                'cursor' => (int) get_option('rifnote_smart_rss_cursor', 0),
                'recent' => Rifnote_Search_Ingestion::smart_statuses(20),
            ),
        ));
    }

    public static function feed_diagnostics(WP_REST_Request $request) {
        $result = Rifnote_Search_Operations::feed_diagnostics((int) $request->get_param('publisher_id'));

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public static function ingest_rss() {
        return rest_ensure_response(Rifnote_Search_Ingestion::run_once());
    }

    public static function analytics_event(WP_REST_Request $request) {
        if (!get_option('rifnote_analytics_enabled', true)) {
            return rest_ensure_response(array('success' => false, 'disabled' => true));
        }

        if (!is_user_logged_in() && !get_option('rifnote_analytics_guest_tracking', true)) {
            return rest_ensure_response(array('success' => false, 'guest_tracking_disabled' => true));
        }

        $rate = Rifnote_Search_Hardening::rate_limit('analytics_event', 180, MINUTE_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        $data = is_array($data) ? $data : array();
        $event_type = sanitize_key($data['event_type'] ?? '');

        if (in_array($event_type, array('result_click', 'source_click', 'read_full_story_click'), true)) {
            $result = Rifnote_Search_Analytics::log_click(array(
                'click_type' => $event_type,
                'post_id' => isset($data['post_id']) ? (int) $data['post_id'] : 0,
                'publisher_id' => isset($data['publisher_id']) ? (int) $data['publisher_id'] : 0,
                'source_name' => sanitize_text_field($data['source_name'] ?? ''),
                'target_url' => esc_url_raw($data['target_url'] ?? ''),
                'query_text' => sanitize_text_field($data['query_text'] ?? ''),
                'visitor_id' => sanitize_text_field($data['visitor_id'] ?? ''),
                'session_id' => sanitize_text_field($data['session_id'] ?? ''),
                'metadata' => isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : array(),
            ));

            if (is_wp_error($result)) {
                return $result;
            }

            return rest_ensure_response($result);
        }

        $logged = Rifnote_Search_Analytics::log_event($event_type ? $event_type : 'custom', array(
            'query_text' => sanitize_text_field($data['query_text'] ?? ''),
            'category' => sanitize_text_field($data['category'] ?? ''),
            'post_id' => isset($data['post_id']) ? (int) $data['post_id'] : 0,
            'publisher_id' => isset($data['publisher_id']) ? (int) $data['publisher_id'] : 0,
            'source_name' => sanitize_text_field($data['source_name'] ?? ''),
            'target_url' => esc_url_raw($data['target_url'] ?? ''),
            'visitor_id' => sanitize_text_field($data['visitor_id'] ?? ''),
            'session_id' => sanitize_text_field($data['session_id'] ?? ''),
            'metadata' => isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : array(),
        ));

        return rest_ensure_response(array('success' => (bool) $logged));
    }

    public static function analytics_summary(WP_REST_Request $request) {
        $days = (int) ($request->get_param('days') ? $request->get_param('days') : 7);

        return rest_ensure_response(Rifnote_Search_Analytics::summary($days));
    }

    public static function editorial_console() {
        return rest_ensure_response(Rifnote_Search_Operations::editorial_console());
    }

    public static function ranking_simulate(WP_REST_Request $request) {
        return rest_ensure_response(array(
            'query' => sanitize_text_field((string) $request->get_param('q')),
            'results' => Rifnote_Search_Operations::ranking_simulation(self::request_args($request), (int) ($request->get_param('limit') ? $request->get_param('limit') : 10)),
        ));
    }

    public static function daily_briefing(WP_REST_Request $request) {
        return rest_ensure_response(Rifnote_Search_Operations::daily_briefing((int) ($request->get_param('limit') ? $request->get_param('limit') : 8)));
    }

    public static function football_live(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('football_live', 120, MINUTE_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        return rest_ensure_response(Rifnote_Search_Football_API::live_payload((bool) $request->get_param('force')));
    }

    public static function football_fixtures(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('football_fixtures', 120, MINUTE_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        return rest_ensure_response(Rifnote_Search_Football_API::fixtures_payload(
            (string) $request->get_param('date'),
            (int) $request->get_param('league'),
            (int) $request->get_param('season'),
            (bool) $request->get_param('force')
        ));
    }

    public static function football_watchlist(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('football_watchlist', 120, MINUTE_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        return rest_ensure_response(Rifnote_Search_Football_API::watchlist_payload(
            (string) $request->get_param('date'),
            (bool) $request->get_param('force')
        ));
    }

    public static function football_upcoming(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('football_upcoming', 120, MINUTE_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        return rest_ensure_response(Rifnote_Search_Football_API::upcoming_payload(
            (int) ($request->get_param('next') ? $request->get_param('next') : 30),
            (bool) $request->get_param('force')
        ));
    }

    public static function football_standings(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('football_standings', 120, MINUTE_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        return rest_ensure_response(Rifnote_Search_Football_API::standings_payload(
            (int) $request->get_param('league'),
            (int) $request->get_param('season'),
            (bool) $request->get_param('force')
        ));
    }

    public static function football_teams(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('football_teams', 120, MINUTE_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        return rest_ensure_response(Rifnote_Search_Football_API::teams_payload(
            (int) $request->get_param('league'),
            (int) $request->get_param('season'),
            (int) ($request->get_param('limit') ? $request->get_param('limit') : 100)
        ));
    }

    public static function football_team_profile(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('football_team_profile', 120, MINUTE_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        return rest_ensure_response(Rifnote_Search_Football_API::team_profile_payload(
            (int) $request->get_param('team_id'),
            (int) ($request->get_param('limit') ? $request->get_param('limit') : 12)
        ));
    }

    public static function football_fixture_details(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('football_fixture_details', 120, MINUTE_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        return rest_ensure_response(Rifnote_Search_Football_API::fixture_details_payload(
            (int) $request->get_param('fixture_id'),
            (bool) $request->get_param('force')
        ));
    }

    public static function football_finished(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('football_finished', 120, MINUTE_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        return rest_ensure_response(Rifnote_Search_Football_API::finished_payload(
            (int) ($request->get_param('limit') ? $request->get_param('limit') : 30),
            (bool) $request->get_param('force')
        ));
    }

    public static function football_players(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('football_players', 120, MINUTE_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        return rest_ensure_response(Rifnote_Search_Football_API::players_payload(
            (int) $request->get_param('team'),
            (int) ($request->get_param('limit') ? $request->get_param('limit') : 120)
        ));
    }

    public static function football_player_profile(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('football_player_profile', 120, MINUTE_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        return rest_ensure_response(Rifnote_Search_Football_API::player_profile_payload(
            (int) $request->get_param('player_id'),
            (string) $request->get_param('player_name'),
            (int) ($request->get_param('limit') ? $request->get_param('limit') : 14)
        ));
    }

    public static function football_transfers(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('football_transfers', 120, MINUTE_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        return rest_ensure_response(Rifnote_Search_Football_API::transfer_news_payload(
            (int) ($request->get_param('limit') ? $request->get_param('limit') : 24)
        ));
    }

    public static function preferences(WP_REST_Request $request) {
        if ('GET' === $request->get_method()) {
            return rest_ensure_response(array('preferences' => Rifnote_Search_Retention::preferences((string) $request->get_param('anon_key'))));
        }

        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        $result = Rifnote_Search_Retention::save_preference(is_array($data) ? $data : array());

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public static function for_you(WP_REST_Request $request) {
        return rest_ensure_response(Rifnote_Search_Retention::for_you_feed((string) $request->get_param('anon_key'), (int) ($request->get_param('limit') ? $request->get_param('limit') : 12)));
    }

    public static function alerts(WP_REST_Request $request) {
        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        $result = Rifnote_Search_Retention::save_alert(is_array($data) ? $data : array());

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public static function newsletter(WP_REST_Request $request) {
        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        $result = Rifnote_Search_Retention::subscribe_newsletter(is_array($data) ? $data : array());

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public static function newsletter_unsubscribe(WP_REST_Request $request) {
        $result = Rifnote_Search_Retention::unsubscribe_newsletter((string) $request->get_param('token'));

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public static function send_newsletter_digest() {
        return rest_ensure_response(array(
            'success' => true,
            'summary' => Rifnote_Search_Retention::send_newsletter_digest(200),
        ));
    }

    public static function device(WP_REST_Request $request) {
        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        $result = Rifnote_Search_Retention::register_device(is_array($data) ? $data : array());

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public static function notifications(WP_REST_Request $request) {
        if ('GET' === $request->get_method()) {
            return rest_ensure_response(array(
                'notifications' => Rifnote_Search_Retention::notifications(
                    (string) $request->get_param('anon_key'),
                    (int) ($request->get_param('limit') ? $request->get_param('limit') : 20)
                ),
            ));
        }

        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        $updated = Rifnote_Search_Retention::update_notification_status(
            isset($data['id']) ? absint($data['id']) : 0,
            isset($data['status']) ? sanitize_key($data['status']) : 'read'
        );

        return rest_ensure_response(array('success' => (bool) $updated));
    }

    public static function process_alerts() {
        return rest_ensure_response(array(
            'success' => true,
            'summary' => Rifnote_Search_Retention::process_alerts(200),
        ));
    }

    public static function process_push_notifications() {
        return rest_ensure_response(array(
            'success' => true,
            'summary' => Rifnote_Search_Delivery::process_push_notifications(200),
        ));
    }

    public static function widget(WP_REST_Request $request) {
        $widget = sanitize_key($request->get_param('widget'));

        if ('trending' === $widget) {
            return rest_ensure_response(Rifnote_Search_Trending::rest_payload(10));
        }

        if ('football' === $widget) {
            return rest_ensure_response(Rifnote_Search_Engine::payload(array('query' => '', 'category' => 'football', 'date_range' => '7d', 'sort' => 'latest'), 1, 6));
        }

        return rest_ensure_response(array('source_badge' => get_bloginfo('name'), 'url' => home_url('/search/')));
    }

    public static function live_weather(WP_REST_Request $request) {
        $visitor_location = null;

        if (null !== $request->get_param('latitude') && null !== $request->get_param('longitude')) {
            $visitor_location = array(
                'label' => $request->get_param('label') ? $request->get_param('label') : __('Near you', 'rifnote-search'),
                'latitude' => $request->get_param('latitude'),
                'longitude' => $request->get_param('longitude'),
            );
        }

        return rest_ensure_response(Rifnote_Search_Live_Data::weather_payload((bool) $request->get_param('force'), $visitor_location));
    }

    public static function live_weather_world(WP_REST_Request $request) {
        return rest_ensure_response(Rifnote_Search_Live_Data::world_weather_payload((bool) $request->get_param('force')));
    }

    public static function live_markets(WP_REST_Request $request) {
        return rest_ensure_response(Rifnote_Search_Live_Data::markets_payload((bool) $request->get_param('force')));
    }

    public static function sponsored_click(WP_REST_Request $request) {
        global $wpdb;

        $data = $request->get_json_params();
        $id = isset($data['id']) ? absint($data['id']) : 0;

        if ($id) {
            Rifnote_Search_Launch_Readiness::maybe_install();
            $wpdb->query($wpdb->prepare('UPDATE ' . Rifnote_Search_Launch_Readiness::sponsored_table() . ' SET clicks = clicks + 1 WHERE id = %d', $id));
        }

        return rest_ensure_response(array('success' => true));
    }

    public static function sponsored_conversion(WP_REST_Request $request) {
        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_params();
        }

        $placement_id = isset($data['id']) ? absint($data['id']) : absint($data['placement_id'] ?? 0);
        $event = sanitize_key($data['event'] ?? 'conversion');
        $value = isset($data['value']) ? (float) preg_replace('/[^0-9.]/', '', (string) $data['value']) : 0;
        $currency = sanitize_text_field($data['currency'] ?? 'NGN');

        Rifnote_Search_Analytics::log_event('ad_conversion', array(
            'target_url' => esc_url_raw($data['target_url'] ?? ''),
            'query_text' => sanitize_text_field($data['query_text'] ?? ''),
            'visitor_id' => sanitize_text_field($data['visitor_id'] ?? ''),
            'session_id' => sanitize_text_field($data['session_id'] ?? ''),
            'metadata' => array(
                'placement_id' => $placement_id,
                'placement' => sanitize_key($data['placement'] ?? ''),
                'sponsor_name' => sanitize_text_field($data['sponsor_name'] ?? ''),
                'conversion_event' => $event,
                'conversion_value' => $value,
                'currency' => $currency,
                'country' => sanitize_text_field($data['country'] ?? ''),
                'region' => sanitize_text_field($data['region'] ?? ''),
                'device_type' => sanitize_key($data['device_type'] ?? ''),
            ),
        ));

        return rest_ensure_response(array(
            'success' => true,
            'placement_id' => $placement_id,
            'event' => $event,
            'value' => $value,
            'currency' => $currency,
        ));
    }

    public static function sponsor_request(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('sponsor_request', 10, HOUR_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        if (!empty($data['website'])) {
            Rifnote_Search_Launch_Readiness::log_suspicious('honeypot', 'Sponsor request honeypot filled.', array('endpoint' => 'sponsor-request'));
            return new WP_Error('rifnote_suspicious_sponsor_request', __('Request could not be accepted.', 'rifnote-search'), array('status' => 400));
        }

        $result = Rifnote_Search_Launch_Readiness::submit_sponsor_request(is_array($data) ? $data : array());

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public static function media_upload(WP_REST_Request $request) {
        $rate = Rifnote_Search_Hardening::rate_limit('media_upload', 20, HOUR_IN_SECONDS);

        if (is_wp_error($rate)) {
            return $rate;
        }

        $files = $request->get_file_params();
        $file = is_array($files) && !empty($files['file']) ? $files['file'] : null;

        if (!$file || empty($file['tmp_name']) || !empty($file['error'])) {
            return new WP_Error('rifnote_media_upload_missing', __('Choose a media file to upload.', 'rifnote-search'), array('status' => 400));
        }

        $max_bytes = min((int) wp_max_upload_size(), 25 * MB_IN_BYTES);

        if (!empty($file['size']) && (int) $file['size'] > $max_bytes) {
            return new WP_Error('rifnote_media_upload_too_large', sprintf(__('Media uploads are limited to %s.', 'rifnote-search'), size_format($max_bytes)), array('status' => 400));
        }

        $allowed_mimes = array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4|m4v' => 'video/mp4',
            'webm' => 'video/webm',
        );
        $checked = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowed_mimes);

        if (empty($checked['type']) || !in_array($checked['type'], $allowed_mimes, true)) {
            return new WP_Error('rifnote_media_upload_type', __('Upload an image, GIF, MP4, or WebM file.', 'rifnote-search'), array('status' => 400));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_handle_upload('file', 0);

        if (is_wp_error($attachment_id)) {
            Rifnote_Search_Hardening::log_error('media_upload', $attachment_id->get_error_message(), array('code' => $attachment_id->get_error_code()), 'warning');
            return $attachment_id;
        }

        $url = wp_get_attachment_url($attachment_id);

        return rest_ensure_response(array(
            'success' => true,
            'id' => (int) $attachment_id,
            'url' => esc_url_raw($url),
            'mime_type' => get_post_mime_type($attachment_id),
            'title' => get_the_title($attachment_id),
        ));
    }

    public static function trash_post(WP_REST_Request $request) {
        $post_id = absint($request['id']);
        $post = get_post($post_id);

        if (!$post || 'trash' === $post->post_status) {
            return new WP_Error('rifnote_post_not_found', __('That story is no longer available.', 'rifnote-search'), array('status' => 404));
        }

        if (!current_user_can('delete_post', $post_id)) {
            return new WP_Error('rifnote_post_delete_forbidden', __('You do not have permission to delete this story.', 'rifnote-search'), array('status' => 403));
        }

        $trashed = wp_trash_post($post_id);

        if (!$trashed) {
            return new WP_Error('rifnote_post_trash_failed', __('Rifnote could not move this story to trash.', 'rifnote-search'), array('status' => 500));
        }

        return rest_ensure_response(array(
            'success' => true,
            'id' => $post_id,
            'status' => 'trash',
        ));
    }

    public static function ads_inventory(WP_REST_Request $request) {
        return rest_ensure_response(Rifnote_Search_Launch_Readiness::ad_inventory());
    }

    public static function advertiser_dashboard(WP_REST_Request $request) {
        if (!is_user_logged_in()) {
            return rest_ensure_response(array(
                'authenticated' => false,
                'login_url' => wp_login_url(home_url('/advertiser-dashboard/')),
                'register_url' => home_url('/advertiser-signup/'),
                'message' => __('Sign in with the email used for your advert brief to see campaign status and reports.', 'rifnote-search'),
            ));
        }

        return rest_ensure_response(array_merge(array('authenticated' => true), Rifnote_Search_Launch_Readiness::advertiser_dashboard(get_current_user_id())));
    }

    public static function advertiser_payment_proof(WP_REST_Request $request) {
        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        $result = Rifnote_Search_Launch_Readiness::submit_payment_proof(absint($data['request_id'] ?? 0), is_array($data) ? $data : array(), get_current_user_id());

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public static function advertiser_profile(WP_REST_Request $request) {
        $data = $request->get_json_params();

        if (!is_array($data) || !$data) {
            $data = $request->get_body_params();
        }

        $result = Rifnote_Search_Launch_Readiness::update_advertiser_profile(is_array($data) ? $data : array(), get_current_user_id());

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public static function launch_readiness() {
        return rest_ensure_response(array(
            'checks' => Rifnote_Search_Hardening::launch_report(),
            'errors' => Rifnote_Search_Hardening::recent_errors(20),
        ));
    }

    public static function release_readiness() {
        return rest_ensure_response(Rifnote_Search_Release::readiness());
    }

    public static function reindex_search() {
        return rest_ensure_response(array(
            'success' => true,
            'indexed' => Rifnote_Search_Index::reindex_all(),
            'health' => Rifnote_Search_Index::health(),
        ));
    }
}
