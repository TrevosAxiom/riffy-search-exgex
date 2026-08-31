<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Football_API {
    const DEFAULT_HOST = 'v3.football.api-sports.io';
    const CRON_HOOK = 'rifnote_search_football_sync';
    const UPCOMING_WINDOW_HOURS = 24;
    const FINISHED_WINDOW_HOURS = 72;

    private static function live_statuses() {
        return array('1H', 'HT', '2H', 'ET', 'BT', 'P', 'SUSP', 'INT', 'LIVE');
    }

    private static function upcoming_statuses() {
        return array('NS', 'TBD');
    }

    private static function finished_statuses() {
        return array('FT', 'AET', 'PEN');
    }

    private static function stale_statuses() {
        return array('PST', 'CANC', 'ABD', 'AWD', 'WO');
    }

    public static function usage_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_football_api_usage';
    }

    public static function fixtures_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_football_fixtures';
    }

    public static function standings_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_football_standings';
    }

    public static function scorers_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_football_scorers';
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $usage = self::usage_table();
        $fixtures = self::fixtures_table();
        $standings = self::standings_table();
        $scorers = self::scorers_table();

        dbDelta("CREATE TABLE {$usage} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider VARCHAR(40) NOT NULL,
            host VARCHAR(190) NOT NULL,
            endpoint VARCHAR(120) NOT NULL,
            request_path VARCHAR(190) NOT NULL,
            query_hash VARCHAR(64) NOT NULL,
            query_args LONGTEXT NULL,
            http_status SMALLINT UNSIGNED DEFAULT 0,
            response_count INT DEFAULT 0,
            quota_limit INT NULL,
            quota_remaining INT NULL,
            duration_ms INT DEFAULT 0,
            cache_hit TINYINT(1) DEFAULT 0,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY endpoint (endpoint),
            KEY http_status (http_status),
            KEY cache_hit (cache_hit),
            KEY created_at (created_at)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$fixtures} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            fixture_id BIGINT UNSIGNED NOT NULL,
            cache_group VARCHAR(40) NOT NULL DEFAULT 'fixtures',
            fixture_date DATETIME NULL,
            status_short VARCHAR(20) NULL,
            status_long VARCHAR(100) NULL,
            league_id BIGINT UNSIGNED DEFAULT 0,
            league_name VARCHAR(190) NULL,
            league_country VARCHAR(100) NULL,
            league_season INT DEFAULT 0,
            home_team_id BIGINT UNSIGNED DEFAULT 0,
            home_team_name VARCHAR(190) NULL,
            away_team_id BIGINT UNSIGNED DEFAULT 0,
            away_team_name VARCHAR(190) NULL,
            payload LONGTEXT NOT NULL,
            details_payload LONGTEXT NULL,
            details_synced_at DATETIME NULL,
            cache_expires_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY fixture_id (fixture_id),
            KEY cache_group (cache_group),
            KEY fixture_date (fixture_date),
            KEY status_short (status_short),
            KEY league_id (league_id),
            KEY home_team_id (home_team_id),
            KEY away_team_id (away_team_id),
            KEY cache_expires_at (cache_expires_at)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$standings} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            league_id BIGINT UNSIGNED NOT NULL,
            league_name VARCHAR(190) NULL,
            league_country VARCHAR(100) NULL,
            league_logo VARCHAR(255) NULL,
            league_season INT DEFAULT 0,
            group_name VARCHAR(190) NOT NULL DEFAULT '',
            team_id BIGINT UNSIGNED DEFAULT 0,
            team_name VARCHAR(190) NULL,
            team_logo VARCHAR(255) NULL,
            rank_position INT DEFAULT 0,
            points INT DEFAULT 0,
            goals_diff INT DEFAULT 0,
            form VARCHAR(40) NULL,
            payload LONGTEXT NOT NULL,
            cache_expires_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY league_team_group (league_id, league_season, group_name, team_id),
            KEY league_id (league_id),
            KEY league_season (league_season),
            KEY group_name (group_name),
            KEY cache_expires_at (cache_expires_at)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$scorers} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            league_id BIGINT UNSIGNED NOT NULL,
            league_name VARCHAR(190) NULL,
            league_country VARCHAR(100) NULL,
            league_logo VARCHAR(255) NULL,
            league_season INT DEFAULT 0,
            player_id BIGINT UNSIGNED DEFAULT 0,
            player_name VARCHAR(190) NULL,
            player_photo VARCHAR(255) NULL,
            team_id BIGINT UNSIGNED DEFAULT 0,
            team_name VARCHAR(190) NULL,
            team_logo VARCHAR(255) NULL,
            goals INT DEFAULT 0,
            assists INT DEFAULT 0,
            appearances INT DEFAULT 0,
            payload LONGTEXT NOT NULL,
            cache_expires_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY league_player_team (league_id, league_season, player_id, team_id),
            KEY league_id (league_id),
            KEY league_season (league_season),
            KEY goals (goals),
            KEY cache_expires_at (cache_expires_at)
        ) {$charset_collate};");

        update_option('rifnote_search_football_db_version', RIFNOTE_SEARCH_VERSION);
    }

    public static function maybe_install() {
        global $wpdb;

        $usage = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::usage_table()));
        $fixtures = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::fixtures_table()));
        $standings = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::standings_table()));
        $scorers = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::scorers_table()));

        if (get_option('rifnote_search_football_db_version') !== RIFNOTE_SEARCH_VERSION || !$usage || !$fixtures || !$standings || !$scorers) {
            self::install();
        }
    }

    public static function settings() {
        $host = sanitize_text_field(get_option('rifnote_api_football_host', self::DEFAULT_HOST));

        return array(
            'api_key' => (string) get_option('rifnote_api_football_key', ''),
            'host' => $host,
            'provider' => self::provider_from_host($host),
            'timezone' => sanitize_text_field(get_option('rifnote_api_football_timezone', wp_timezone_string())),
            'live_cache_ttl' => max(60, min(300, (int) get_option('rifnote_api_football_live_cache_ttl', 60))),
            'fixture_cache_ttl' => max(60, min(3600, (int) get_option('rifnote_api_football_fixture_cache_ttl', 300))),
            'upcoming_cache_ttl' => max(60, min(3600, (int) get_option('rifnote_api_football_upcoming_cache_ttl', 300))),
            'finished_cache_ttl' => max(60, min(7200, (int) get_option('rifnote_api_football_finished_cache_ttl', 900))),
            'details_cache_ttl' => max(60, min(7200, (int) get_option('rifnote_api_football_details_cache_ttl', 600))),
            'competitions' => self::competitions(),
            'team_watchlist' => self::team_watchlist(),
        );
    }

    public static function cron_schedules($schedules) {
        $settings = self::settings();
        $interval = max(60, (int) ($settings['live_cache_ttl'] ?? 60));

        if (!isset($schedules['rifnote_football_matchday'])) {
            $schedules['rifnote_football_matchday'] = array(
                'interval' => $interval,
                'display' => __('Rifnote football live sync cadence', 'rifnote-search'),
            );
        }

        return $schedules;
    }

    public static function schedule() {
        $settings = self::settings();

        if (empty($settings['api_key']) || empty($settings['competitions'])) {
            self::clear_schedule();
            return;
        }

        $current = wp_get_schedule(self::CRON_HOOK);

        if ($current && 'rifnote_football_matchday' !== $current) {
            self::clear_schedule();
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + max(30, (int) $settings['live_cache_ttl']), 'rifnote_football_matchday', self::CRON_HOOK);
        }
    }

    public static function clear_schedule() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function run_cron() {
        $settings = self::settings();

        if (empty($settings['api_key']) || empty($settings['competitions'])) {
            return;
        }

        self::live_payload(true);
        self::throttled_refresh('cron_matchday_window', $settings['fixture_cache_ttl'], function () {
            self::sync_matchday_window(true);
            return true;
        });
        self::throttled_refresh('cron_upcoming', $settings['upcoming_cache_ttl'], function () {
            return self::upcoming_payload(30, true);
        });
        self::throttled_refresh('cron_finished', $settings['finished_cache_ttl'], function () {
            return self::finished_payload(30, true);
        });
    }

    public static function competitions() {
        $raw = (string) get_option('rifnote_api_football_competitions', "39:2025:Premier League\n2:2025:UEFA Champions League\n3:2025:UEFA Europa League\n1:2026:FIFA World Cup");
        $competitions = array();

        foreach (array_filter(array_map('trim', explode("\n", $raw))) as $line) {
            $parts = array_map('trim', explode(':', $line, 3));
            $league_id = isset($parts[0]) ? absint($parts[0]) : 0;
            $season = isset($parts[1]) ? absint($parts[1]) : 0;
            $label = isset($parts[2]) ? sanitize_text_field($parts[2]) : '';

            if ($league_id && $season) {
                $competitions[] = array(
                    'league_id' => $league_id,
                    'season' => $season,
                    'label' => $label ? $label : sprintf(__('League %1$d %2$d', 'rifnote-search'), $league_id, $season),
                );
            }
        }

        return array_slice($competitions, 0, 50);
    }

    private static function resolve_competition($league = 0, $season = 0, $settings = null) {
        $settings = is_array($settings) ? $settings : self::settings();
        $league = absint($league);
        $season = absint($season);

        foreach (($settings['competitions'] ?? array()) as $competition) {
            $competition_league = absint($competition['league_id'] ?? 0);
            $competition_season = absint($competition['season'] ?? 0);

            if ($league && $season && $competition_league === $league && $competition_season === $season) {
                return $competition;
            }
        }

        if ($league && $season) {
            return array(
                'league_id' => $league,
                'season' => $season,
                'label' => sprintf(__('League %1$d %2$d', 'rifnote-search'), $league, $season),
            );
        }

        return $settings['competitions'][0] ?? array();
    }

    public static function team_watchlist() {
        $raw = (string) get_option('rifnote_api_football_team_watchlist', '');
        $ids = array();
        $names = array();
        $labels = array();

        foreach (array_filter(array_map('trim', explode("\n", $raw))) as $line) {
            $line = preg_replace('/\s+#.*$/', '', $line);
            $line = trim((string) $line);

            if ('' === $line) {
                continue;
            }

            $parts = preg_split('/[:|,]/', $line, 2);
            $team_id = isset($parts[0]) ? absint($parts[0]) : 0;
            $team_name = isset($parts[1]) ? sanitize_text_field($parts[1]) : '';

            if (!$team_id) {
                $team_name = sanitize_text_field($line);
            }

            if ($team_id) {
                $ids[$team_id] = true;
            }

            $name_key = self::normalize_team_name_key($team_name);
            if ($name_key) {
                $names[$name_key] = true;
            }

            if ($team_id || $team_name) {
                $labels[] = array(
                    'id' => $team_id,
                    'name' => $team_name ? $team_name : sprintf(__('Team %d', 'rifnote-search'), $team_id),
                );
            }
        }

        return array(
            'enabled' => !empty($ids) || !empty($names),
            'ids' => $ids,
            'names' => $names,
            'labels' => array_slice($labels, 0, 250),
        );
    }

    public static function stored_teams($limit = 200) {
        global $wpdb;

        self::maybe_install();

        $limit = max(1, min(500, (int) $limit));
        $table = self::fixtures_table();
        $sql = $wpdb->prepare(
            "SELECT team_id, team_name, SUM(appearances) AS appearances, MAX(last_seen) AS last_seen
             FROM (
                SELECT home_team_id AS team_id, home_team_name AS team_name, COUNT(*) AS appearances, MAX(updated_at) AS last_seen
                FROM {$table}
                WHERE home_team_id > 0 AND home_team_name <> ''
                GROUP BY home_team_id, home_team_name
                UNION ALL
                SELECT away_team_id AS team_id, away_team_name AS team_name, COUNT(*) AS appearances, MAX(updated_at) AS last_seen
                FROM {$table}
                WHERE away_team_id > 0 AND away_team_name <> ''
                GROUP BY away_team_id, away_team_name
             ) teams
             GROUP BY team_id, team_name
             ORDER BY appearances DESC, team_name ASC
             LIMIT %d",
            $limit
        );

        return $wpdb->get_results($sql, ARRAY_A);
    }

    public static function stored_teams_by_competition($league_id = 0, $season = 0, $limit = 500) {
        global $wpdb;

        self::maybe_install();

        $league_id = absint($league_id);
        $season = absint($season);
        $limit = max(1, min(1000, (int) $limit));
        $table = self::fixtures_table();
        $home_where = array('home_team_id > 0', "home_team_name <> ''");
        $away_where = array('away_team_id > 0', "away_team_name <> ''");
        $home_values = array();
        $away_values = array();

        if ($league_id) {
            $home_where[] = 'league_id = %d';
            $away_where[] = 'league_id = %d';
            $home_values[] = $league_id;
            $away_values[] = $league_id;
        }

        if ($season) {
            $home_where[] = 'league_season = %d';
            $away_where[] = 'league_season = %d';
            $home_values[] = $season;
            $away_values[] = $season;
        }

        $home_where_sql = implode(' AND ', $home_where);
        $away_where_sql = implode(' AND ', $away_where);
        $sql = "SELECT team_id, team_name, league_id, league_name, league_season, SUM(appearances) AS appearances, MAX(last_seen) AS last_seen
             FROM (
                SELECT home_team_id AS team_id, home_team_name AS team_name, league_id, league_name, league_season, COUNT(*) AS appearances, MAX(updated_at) AS last_seen
                FROM {$table}
                WHERE {$home_where_sql}
                GROUP BY home_team_id, home_team_name, league_id, league_name, league_season
                UNION ALL
                SELECT away_team_id AS team_id, away_team_name AS team_name, league_id, league_name, league_season, COUNT(*) AS appearances, MAX(updated_at) AS last_seen
                FROM {$table}
                WHERE {$away_where_sql}
                GROUP BY away_team_id, away_team_name, league_id, league_name, league_season
             ) teams
             GROUP BY team_id, team_name, league_id, league_name, league_season
             ORDER BY league_name ASC, appearances DESC, team_name ASC
             LIMIT %d";

        return $wpdb->get_results($wpdb->prepare($sql, array_merge($home_values, $away_values, array($limit))), ARRAY_A);
    }

    public static function stored_matches_for_date($date = '', $limit = 160, $apply_team_watchlist = true) {
        global $wpdb;

        self::maybe_install();

        $table = self::fixtures_table();
        $date = sanitize_text_field($date ? $date : wp_date('Y-m-d'));
        $where = array('DATE(fixture_date) = %s');
        $values = array($date);
        $competitions = self::competitions();

        if ($competitions) {
            $competition_clauses = array();

            foreach ($competitions as $competition) {
                $league_id = (int) ($competition['league_id'] ?? 0);
                $season = (int) ($competition['season'] ?? 0);

                if (!$league_id || !$season) {
                    continue;
                }

                $competition_clauses[] = '(league_id = %d AND league_season = %d)';
                $values[] = $league_id;
                $values[] = $season;
            }

            if ($competition_clauses) {
                $where[] = '(' . implode(' OR ', $competition_clauses) . ')';
            }
        }

        $sql = $wpdb->prepare(
            "SELECT payload FROM {$table} WHERE " . implode(' AND ', $where) . ' ORDER BY fixture_date ASC LIMIT %d',
            array_merge($values, array(max(1, min(300, (int) $limit))))
        );
        $rows = $wpdb->get_col($sql);
        $fixtures = array_values(array_filter(array_map(array(__CLASS__, 'decode_json'), $rows)));

        return $apply_team_watchlist ? self::filter_fixtures_by_team_watchlist($fixtures, self::team_watchlist()) : $fixtures;
    }

    public static function sync_admin_curation_date($date = '') {
        $settings = self::settings();
        $date = sanitize_text_field($date ? $date : wp_date('Y-m-d'));

        if (empty($settings['api_key']) || empty($settings['competitions'])) {
            return array(
                'synced' => 0,
                'errors' => array(),
            );
        }

        $lock_key = 'rifnote_api_football_admin_curation_' . md5($date . wp_json_encode($settings['competitions']));

        if (get_transient($lock_key)) {
            return array(
                'synced' => null,
                'errors' => array(),
                'skipped' => true,
            );
        }

        set_transient($lock_key, 1, max(60, (int) $settings['fixture_cache_ttl']));

        $synced = 0;
        $errors = array();

        foreach ($settings['competitions'] as $competition) {
            $league_id = (int) ($competition['league_id'] ?? 0);
            $season = (int) ($competition['season'] ?? 0);

            if (!$league_id || !$season) {
                continue;
            }

            $payload = self::fixtures_payload($date, $league_id, $season, true, false);
            $synced += count($payload['fixtures'] ?? array());

            if (!empty($payload['errors'])) {
                $errors[$competition['label'] ?? ($league_id . ':' . $season)] = $payload['errors'];
            }
        }

        return array(
            'synced' => $synced,
            'errors' => $errors,
        );
    }

    public static function homepage_featured_fixture_ids() {
        $raw = (string) get_option('rifnote_home_featured_football_matches', '');
        $ids = array();

        foreach (preg_split('/[\s,]+/', $raw) as $item) {
            $fixture_id = absint($item);

            if ($fixture_id) {
                $ids[] = $fixture_id;
            }
        }

        return array_slice(array_values(array_unique($ids)), 0, 12);
    }

    public static function featured_homepage_matches($limit = 8) {
        global $wpdb;

        self::maybe_install();

        $ids = array_slice(self::homepage_featured_fixture_ids(), 0, max(1, min(12, (int) $limit)));
        if (!$ids) {
            return array();
        }

        $table = self::fixtures_table();
        $sql = $wpdb->prepare(
            "SELECT fixture_id, payload FROM {$table} WHERE fixture_id IN (" . implode(',', array_fill(0, count($ids), '%d')) . ')',
            $ids
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);

        if (!$rows) {
            return array();
        }

        $by_id = array();
        foreach ($rows as $row) {
            $fixture = self::decode_json($row['payload'] ?? '');
            if (is_array($fixture)) {
                $by_id[(int) ($row['fixture_id'] ?? 0)] = $fixture;
            }
        }

        $fixtures = array();
        foreach ($ids as $fixture_id) {
            if (!empty($by_id[$fixture_id])) {
                $fixtures[] = self::hydrate_fixture($by_id[$fixture_id]);
            }
        }

        return $fixtures;
    }

    public static function live_payload($force = false) {
        $settings = self::settings();
        $transient_key = 'rifnote_api_football_live_payload';

        if (!$force) {
            $transient = get_transient($transient_key);
            if (is_array($transient)) {
                return self::apply_team_visibility_to_payload($transient, $settings);
            }

            $cached = self::cached_payload('live', $settings['live_cache_ttl'], array(
                'competitions' => $settings['competitions'],
                'live_window' => true,
            ));
            if (is_array($cached)) {
                $cached = self::apply_team_visibility_to_payload($cached, $settings);
                set_transient($transient_key, $cached, (int) $settings['live_cache_ttl']);
                return $cached;
            }

            if (!empty($settings['api_key'])) {
                return self::live_payload(true);
            }

            return self::empty_payload('', 'live', !empty($settings['api_key']), $settings, array(
                'competitions' => $settings['competitions'],
                'source' => 'database',
            ));
        }

        self::sync_matchday_window(false);

        $response = self::request('/fixtures', array(
            'live' => 'all',
            'timezone' => $settings['timezone'],
        ));

        if (is_wp_error($response)) {
            return self::empty_payload($response->get_error_message(), 'live', !empty($settings['api_key']), $settings);
        }

        $fixtures = self::filter_live_window(
            self::filter_fixtures_by_competitions(array_map(array(__CLASS__, 'normalize_fixture'), $response['response'] ?? array()), $settings['competitions'])
        );
        $fixtures = self::filter_fixtures_by_team_watchlist(self::hydrate_fixtures($fixtures), $settings['team_watchlist']);

        $payload = array(
            'provider' => 'api-football',
            'mode' => 'live',
            'configured' => true,
            'updated_at' => gmdate(DATE_ATOM),
            'poll_after' => $settings['live_cache_ttl'],
            'competitions' => $settings['competitions'],
            'fixtures' => $fixtures,
            'errors' => $response['errors'] ?? array(),
        );

        self::store_payload_fixtures($payload, 'live', $settings['live_cache_ttl']);
        set_transient($transient_key, $payload, (int) $settings['live_cache_ttl']);
        update_option('rifnote_api_football_last_live_sync', $payload['updated_at'], false);

        return $payload;
    }

    public static function fixtures_payload($date = '', $league = 0, $season = 0, $force = false, $apply_team_watchlist = true) {
        $settings = self::settings();
        $date = $date ? sanitize_text_field($date) : gmdate('Y-m-d');

        if (!$force) {
            if (self::is_matchday_date($date)) {
                self::sync_matchday_window(false);
            }

            $cached = self::cached_payload('fixtures', $settings['fixture_cache_ttl'], array(
                'date' => $date,
                'league' => $league,
                'season' => $season,
                'competitions' => (!$league && !$season) ? $settings['competitions'] : array(),
                'allow_stale' => true,
            ));
            if (is_array($cached)) {
                return $apply_team_watchlist ? self::apply_team_visibility_to_payload($cached, $settings) : $cached;
            }

            if (!empty($settings['api_key'])) {
                $refreshed = self::throttled_refresh('fixtures_' . $date . '_' . (int) $league . '_' . (int) $season . '_' . ($apply_team_watchlist ? 'watchlist' : 'all'), $settings['fixture_cache_ttl'], function () use ($date, $league, $season, $apply_team_watchlist) {
                    return self::fixtures_payload($date, $league, $season, true, $apply_team_watchlist);
                });

                if (is_array($refreshed)) {
                    return $refreshed;
                }
            }

            return self::empty_payload('', 'fixtures', !empty($settings['api_key']), $settings, array(
                'date' => $date,
                'competitions' => $settings['competitions'],
                'source' => 'database',
            ));
        }

        $query = array(
            'date' => $date,
            'timezone' => $settings['timezone'],
        );

        if ($league) {
            $query['league'] = (int) $league;
        }

        if ($season) {
            $query['season'] = (int) $season;
        }

        $response = self::request('/fixtures', $query);

        if (is_wp_error($response)) {
            return self::empty_payload($response->get_error_message(), 'fixtures', !empty($settings['api_key']), $settings, array('date' => $date));
        }

        $fixtures = (!$league && !$season)
            ? self::filter_fixtures_by_competitions(array_map(array(__CLASS__, 'normalize_fixture'), $response['response'] ?? array()), $settings['competitions'])
            : array_map(array(__CLASS__, 'normalize_fixture'), $response['response'] ?? array());
        $fixtures = self::hydrate_fixtures($fixtures);

        if ($apply_team_watchlist) {
            $fixtures = self::filter_fixtures_by_team_watchlist($fixtures, $settings['team_watchlist']);
        }

        $payload = array(
            'provider' => 'api-football',
            'mode' => 'fixtures',
            'configured' => true,
            'date' => $date,
            'updated_at' => gmdate(DATE_ATOM),
            'poll_after' => $settings['fixture_cache_ttl'],
            'competitions' => $settings['competitions'],
            'fixtures' => $fixtures,
            'errors' => $response['errors'] ?? array(),
        );

        self::store_payload_fixtures($payload, 'fixtures', $settings['fixture_cache_ttl']);

        return $payload;
    }

    public static function upcoming_payload($next = 30, $force = false) {
        $settings = self::settings();
        $next = max(1, min(100, (int) $next));
        $window_hours = self::UPCOMING_WINDOW_HOURS;

        if (!$force) {
            self::sync_matchday_window(false);

            $cached = self::cached_payload('upcoming', $settings['upcoming_cache_ttl'], array(
                'next' => $next,
                'competitions' => $settings['competitions'],
                'cache_groups' => array('upcoming', 'fixtures', 'watchlist'),
                'future_hours' => $window_hours,
                'allow_stale' => true,
            ));
            if (is_array($cached)) {
                return self::apply_team_visibility_to_payload($cached, $settings);
            }

            if (!empty($settings['api_key'])) {
                $refreshed = self::throttled_refresh('upcoming_' . $next . '_' . md5(wp_json_encode($settings['competitions'])), $settings['upcoming_cache_ttl'], function () use ($next) {
                    return self::upcoming_payload($next, true);
                });

                if (is_array($refreshed)) {
                    return $refreshed;
                }
            }

            return self::empty_payload('', 'upcoming', !empty($settings['api_key']), $settings, array(
                'competitions' => $settings['competitions'],
                'window_hours' => $window_hours,
                'source' => 'database',
            ));
        }

        if (empty($settings['api_key'])) {
            return self::empty_payload(__('API-Football key is not configured.', 'rifnote-search'), 'upcoming', false, $settings, array(
                'competitions' => $settings['competitions'],
            ));
        }

        self::sync_matchday_window(true);

        $all_fixtures = array();
        $errors = array();
        $competitions = $settings['competitions'];
        $per_competition = $competitions ? max($next, 50) : $next;

        if (!$competitions) {
            $payload = self::upcoming_for_competition($next, 0, 0, $force, $window_hours);
            $all_fixtures = $payload['fixtures'] ?? array();
            $errors = $payload['errors'] ?? array();
        } else {
            foreach ($competitions as $competition) {
                $payload = self::upcoming_for_competition($per_competition, (int) $competition['league_id'], (int) $competition['season'], $force, $window_hours);

                foreach ($payload['fixtures'] ?? array() as $fixture) {
                    if (!self::fixture_matches_team_watchlist($fixture, $settings['team_watchlist'])) {
                        continue;
                    }

                    $fixture['watchlist_label'] = $competition['label'];
                    $all_fixtures[$fixture['id'] ?: md5(wp_json_encode($fixture))] = $fixture;
                }

                if (!empty($payload['errors'])) {
                    $errors[$competition['label']] = $payload['errors'];
                }
            }
        }

        $fixtures = self::filter_upcoming_window(array_values($all_fixtures), $window_hours);
        usort($fixtures, function ($a, $b) {
            return (int) ($a['timestamp'] ?? 0) <=> (int) ($b['timestamp'] ?? 0);
        });
        $fixtures = array_slice($fixtures, 0, $next);

        $payload = array(
            'provider' => 'api-football',
            'mode' => 'upcoming',
            'configured' => true,
            'updated_at' => gmdate(DATE_ATOM),
            'poll_after' => $settings['upcoming_cache_ttl'],
            'competitions' => $settings['competitions'],
            'window_hours' => $window_hours,
            'fixtures' => $fixtures,
            'errors' => $errors,
        );

        self::store_payload_fixtures($payload, 'upcoming', $settings['upcoming_cache_ttl']);

        return $payload;
    }

    private static function upcoming_for_competition($next, $league = 0, $season = 0, $force = false, $window_hours = 24) {
        $settings = self::settings();
        $next = max(1, min(100, (int) $next));

        if (!$force) {
            $cached = self::cached_payload('upcoming', $settings['upcoming_cache_ttl'], array(
                'next' => $next,
                'league' => $league,
                'season' => $season,
                'future_hours' => $window_hours,
                'allow_stale' => true,
            ));
            if (is_array($cached)) {
                return self::apply_team_visibility_to_payload($cached, $settings);
            }

            return self::empty_payload('', 'upcoming', !empty($settings['api_key']), $settings, array(
                'source' => 'database',
            ));
        }

        $query = array(
            'next' => $next,
            'timezone' => $settings['timezone'],
        );

        if ($league) {
            $query['league'] = (int) $league;
        }

        if ($season) {
            $query['season'] = (int) $season;
        }

        $response = self::request('/fixtures', $query);

        if (is_wp_error($response)) {
            return self::empty_payload($response->get_error_message(), 'upcoming', !empty($settings['api_key']), $settings);
        }

        $payload = array(
            'provider' => 'api-football',
            'mode' => 'upcoming',
            'configured' => true,
            'updated_at' => gmdate(DATE_ATOM),
            'poll_after' => $settings['upcoming_cache_ttl'],
            'fixtures' => self::filter_fixtures_by_team_watchlist(self::hydrate_fixtures(self::filter_upcoming_window(array_map(array(__CLASS__, 'normalize_fixture'), $response['response'] ?? array()), $window_hours)), $settings['team_watchlist']),
            'errors' => $response['errors'] ?? array(),
        );

        self::store_payload_fixtures($payload, 'upcoming', $settings['upcoming_cache_ttl']);

        return $payload;
    }

    public static function finished_payload($limit = 30, $force = false) {
        global $wpdb;

        self::maybe_install();

        $settings = self::settings();
        $limit = max(1, min(100, (int) $limit));

        if ($force && !empty($settings['api_key'])) {
            self::sync_matchday_window(true);
        } else {
            self::sync_matchday_window(false);
        }

        $table = self::fixtures_table();
        $where = array("status_short IN ('FT','AET','PEN')");
        $values = array(gmdate('Y-m-d H:i:s', time() - self::FINISHED_WINDOW_HOURS * HOUR_IN_SECONDS));
        $where[] = 'fixture_date >= %s';

        if (!empty($settings['competitions'])) {
            $competition_clauses = array();

            foreach ($settings['competitions'] as $competition) {
                $league_id = (int) ($competition['league_id'] ?? 0);
                $season = (int) ($competition['season'] ?? 0);

                if (!$league_id || !$season) {
                    continue;
                }

                $competition_clauses[] = '(league_id = %d AND league_season = %d)';
                $values[] = $league_id;
                $values[] = $season;
            }

            if ($competition_clauses) {
                $where[] = '(' . implode(' OR ', $competition_clauses) . ')';
            }
        }

        $watchlist = $settings['team_watchlist'] ?? self::team_watchlist();
        if (!empty($watchlist['enabled']) && !empty($watchlist['ids'])) {
            $team_ids = array_map('absint', array_keys($watchlist['ids']));
            $where[] = '(home_team_id IN (' . implode(',', array_fill(0, count($team_ids), '%d')) . ') OR away_team_id IN (' . implode(',', array_fill(0, count($team_ids), '%d')) . '))';
            $values = array_merge($values, $team_ids, $team_ids);
        }

        $sql = $wpdb->prepare(
            "SELECT payload FROM {$table} WHERE " . implode(' AND ', $where) . ' ORDER BY fixture_date DESC LIMIT %d',
            array_merge($values, array($limit))
        );
        $rows = $wpdb->get_col($sql);
        $fixtures = array_values(array_filter(array_map(array(__CLASS__, 'decode_json'), $rows)));
        $fixtures = self::filter_fixtures_by_team_watchlist($fixtures, $settings['team_watchlist']);
        $fixtures = array_slice($fixtures, 0, $limit);

        return array(
            'provider' => !empty($settings['api_key']) ? 'api-football' : 'database',
            'mode' => 'finished',
            'configured' => !empty($settings['api_key']),
            'updated_at' => gmdate(DATE_ATOM),
            'poll_after' => $settings['finished_cache_ttl'],
            'competitions' => $settings['competitions'],
            'fixtures' => $fixtures,
            'source' => 'database',
            'message' => $fixtures ? '' : __('No finished matches are stored for the configured competitions yet.', 'rifnote-search'),
        );
    }

    public static function watchlist_payload($date = '', $force = false) {
        $settings = self::settings();
        $date = $date ? sanitize_text_field($date) : gmdate('Y-m-d');

        if (!$force) {
            if (self::is_matchday_date($date)) {
                self::sync_matchday_window(false);
            }

            $cached = self::cached_payload('watchlist', $settings['fixture_cache_ttl'], array(
                'date' => $date,
                'competitions' => $settings['competitions'],
                'allow_stale' => true,
            ));
            if (is_array($cached)) {
                return self::apply_team_visibility_to_payload($cached, $settings);
            }

            if (!empty($settings['api_key'])) {
                $refreshed = self::throttled_refresh('watchlist_' . $date . '_' . md5(wp_json_encode($settings['competitions'])), $settings['fixture_cache_ttl'], function () use ($date) {
                    return self::watchlist_payload($date, true);
                });

                if (is_array($refreshed)) {
                    return $refreshed;
                }
            }

            return self::empty_payload('', 'watchlist', !empty($settings['api_key']), $settings, array(
                'date' => $date,
                'competitions' => $settings['competitions'],
                'source' => 'database',
            ));
        }

        $all_fixtures = array();
        $errors = array();

        if (empty($settings['api_key'])) {
            return self::empty_payload(__('API-Football key is not configured.', 'rifnote-search'), 'watchlist', false, $settings, array(
                'date' => $date,
                'competitions' => $settings['competitions'],
            ));
        }

        foreach ($settings['competitions'] as $competition) {
            $payload = self::fixtures_payload($date, (int) $competition['league_id'], (int) $competition['season'], $force);

            foreach ($payload['fixtures'] ?? array() as $fixture) {
                if (!self::fixture_matches_team_watchlist($fixture, $settings['team_watchlist'])) {
                    continue;
                }

                $fixture['watchlist_label'] = $competition['label'];
                $all_fixtures[$fixture['id'] ?: md5(wp_json_encode($fixture))] = $fixture;
            }

            if (!empty($payload['errors'])) {
                $errors[$competition['label']] = $payload['errors'];
            }
        }

        $payload = array(
            'provider' => empty($settings['api_key']) ? 'not-configured' : 'api-football',
            'mode' => 'watchlist',
            'configured' => !empty($settings['api_key']),
            'date' => $date,
            'updated_at' => gmdate(DATE_ATOM),
            'poll_after' => $settings['fixture_cache_ttl'],
            'competitions' => $settings['competitions'],
            'fixtures' => array_values($all_fixtures),
            'errors' => $errors,
        );

        self::store_payload_fixtures($payload, 'watchlist', $settings['fixture_cache_ttl']);

        return $payload;
    }

    public static function fixture_details_payload($fixture_id, $force = false) {
        global $wpdb;

        self::maybe_install();

        $fixture_id = absint($fixture_id);
        $settings = self::settings();
        $table = self::fixtures_table();
        $row = $fixture_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE fixture_id = %d LIMIT 1", $fixture_id), ARRAY_A) : null;

        if (!$fixture_id || !$row) {
            return array(
                'provider' => !empty($settings['api_key']) ? 'api-football' : 'not-configured',
                'configured' => !empty($settings['api_key']),
                'fixture' => null,
                'details' => self::empty_details(),
                'updated_at' => gmdate(DATE_ATOM),
                'message' => __('This fixture is not cached in the Rifnote database yet.', 'rifnote-search'),
            );
        }

        $fixture = self::hydrate_fixture(self::decode_json($row['payload']), self::decode_json($row['details_payload']));
        $details = self::decode_json($row['details_payload']);
        $details_fresh_until = $row['details_synced_at'] ? strtotime($row['details_synced_at'] . ' UTC') + (int) $settings['details_cache_ttl'] : 0;

        if ($force && !empty($settings['api_key'])) {
            self::sync_single_fixture($fixture_id, $settings);
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE fixture_id = %d LIMIT 1", $fixture_id), ARRAY_A);
            if ($row) {
                $fixture = self::hydrate_fixture(self::decode_json($row['payload']), self::decode_json($row['details_payload']));
                $details = self::decode_json($row['details_payload']);
                $details_fresh_until = $row['details_synced_at'] ? strtotime($row['details_synced_at'] . ' UTC') + (int) $settings['details_cache_ttl'] : 0;
            }
        }

        if (($force || !$details || time() > $details_fresh_until) && !empty($settings['api_key'])) {
            $synced = self::sync_fixture_details($fixture);

            if (is_array($synced)) {
                $details = $synced;
                $wpdb->update($table, array(
                    'details_payload' => wp_json_encode($details),
                    'details_synced_at' => current_time('mysql', true),
                    'updated_at' => current_time('mysql', true),
                ), array('fixture_id' => $fixture_id));
                $stored_details = $wpdb->get_var($wpdb->prepare("SELECT details_payload FROM {$table} WHERE fixture_id = %d LIMIT 1", $fixture_id));
                $details = self::decode_json($stored_details);
                $stored_payload = $wpdb->get_var($wpdb->prepare("SELECT payload FROM {$table} WHERE fixture_id = %d LIMIT 1", $fixture_id));
                $fixture = self::hydrate_fixture(self::decode_json($stored_payload), $details);
            }
        }

        if (!$details) {
            $details = self::empty_details();
        }

        $details['markers'] = self::event_marker_rows($fixture, $details);

        return array(
            'provider' => 'api-football',
            'configured' => !empty($settings['api_key']),
            'fixture' => self::hydrate_fixture($fixture, $details),
            'details' => $details,
            'updated_at' => $row['details_synced_at'] ? mysql_to_rfc3339($row['details_synced_at']) : mysql_to_rfc3339($row['updated_at']),
            'source' => 'database',
        );
    }

    private static function sync_single_fixture($fixture_id, $settings = null) {
        $fixture_id = absint($fixture_id);

        if (!$fixture_id) {
            return false;
        }

        $settings = is_array($settings) ? $settings : self::settings();
        $response = self::request('/fixtures', array(
            'id' => $fixture_id,
            'timezone' => $settings['timezone'] ?? 'UTC',
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $fixtures = array_map(array(__CLASS__, 'normalize_fixture'), $response['response'] ?? array());

        if (!$fixtures) {
            return false;
        }

        self::store_payload_fixtures(array('fixtures' => self::hydrate_fixtures($fixtures)), 'fixture', $settings['fixture_cache_ttl'] ?? MINUTE_IN_SECONDS);

        return true;
    }

    public static function standings_payload($league = 0, $season = 0, $force = false) {
        self::maybe_install();

        $settings = self::settings();
        $league = absint($league);
        $season = absint($season);

        if (!$league || !$season) {
            $first = $settings['competitions'][0] ?? array();
            $league = absint($first['league_id'] ?? 0);
            $season = absint($first['season'] ?? 0);
        }

        if (!$league || !$season) {
            return array(
                'provider' => empty($settings['api_key']) ? 'not-configured' : 'api-football',
                'configured' => !empty($settings['api_key']),
                'league' => null,
                'groups' => array(),
                'updated_at' => gmdate(DATE_ATOM),
                'source' => 'database',
                'message' => __('Choose a configured league or cup to load a table.', 'rifnote-search'),
            );
        }

        if (!$force) {
            $cached = self::stored_standings($league, $season);
            if ($cached) {
                return $cached;
            }
        }

        if (!empty($settings['api_key'])) {
            $response = self::request('/standings', array(
                'league' => $league,
                'season' => $season,
            ));

            if (!is_wp_error($response)) {
                $normalized = self::normalize_standings_payload($response);
                if (!empty($normalized['groups'])) {
                    self::store_standings($normalized, $settings['fixture_cache_ttl']);
                    return self::stored_standings($league, $season) ?: $normalized;
                }
            }
        }

        return array(
            'provider' => empty($settings['api_key']) ? 'not-configured' : 'api-football',
            'configured' => !empty($settings['api_key']),
            'league' => array('id' => $league, 'season' => $season),
            'groups' => array(),
            'updated_at' => gmdate(DATE_ATOM),
            'source' => 'database',
            'message' => __('No table is stored for this competition yet. Some cup rounds do not expose league-style standings.', 'rifnote-search'),
        );
    }

    public static function competition_payload($league = 0, $season = 0, $force = false) {
        self::maybe_install();

        $settings = self::settings();
        $competition = self::resolve_competition($league, $season, $settings);
        $league = (int) ($competition['league_id'] ?? $league);
        $season = (int) ($competition['season'] ?? $season);

        $standings = self::standings_payload($league, $season, $force);
        $scorers = self::top_scorers_payload($league, $season, $force);
        $league_meta = !empty($standings['league']) ? $standings['league'] : ($scorers['league'] ?? array());

        return array(
            'provider' => !empty($settings['api_key']) ? 'api-football' : 'not-configured',
            'configured' => !empty($settings['api_key']),
            'league' => $league_meta,
            'season' => (int) ($league_meta['season'] ?? $season),
            'standings' => $standings,
            'top_scorers' => $scorers,
            'competitions' => $settings['competitions'],
            'updated_at' => gmdate(DATE_ATOM),
            'source' => 'database',
        );
    }

    public static function top_scorers_payload($league = 0, $season = 0, $force = false) {
        self::maybe_install();

        $settings = self::settings();
        $competition = self::resolve_competition($league, $season, $settings);
        $league = (int) ($competition['league_id'] ?? 0);
        $season = (int) ($competition['season'] ?? 0);

        if (!$league || !$season) {
            return array(
                'provider' => empty($settings['api_key']) ? 'not-configured' : 'api-football',
                'configured' => !empty($settings['api_key']),
                'league' => null,
                'players' => array(),
                'updated_at' => gmdate(DATE_ATOM),
                'source' => 'database',
                'message' => __('Choose a configured league or cup to load scorers.', 'rifnote-search'),
            );
        }

        if (!$force) {
            $cached = self::stored_top_scorers($league, $season);
            if ($cached) {
                return $cached;
            }
        }

        if (!empty($settings['api_key'])) {
            $response = self::request('/players/topscorers', array(
                'league' => $league,
                'season' => $season,
            ));

            if (!is_wp_error($response)) {
                $normalized = self::normalize_top_scorers_payload($response);
                if (!empty($normalized['players'])) {
                    self::store_top_scorers($normalized, $settings['fixture_cache_ttl']);
                    return self::stored_top_scorers($league, $season) ?: $normalized;
                }
            }
        }

        return array(
            'provider' => empty($settings['api_key']) ? 'not-configured' : 'api-football',
            'configured' => !empty($settings['api_key']),
            'league' => array('id' => $league, 'season' => $season),
            'players' => array(),
            'updated_at' => gmdate(DATE_ATOM),
            'source' => 'database',
            'message' => __('No top scorers are stored for this competition yet.', 'rifnote-search'),
        );
    }

    public static function search_payload($query, $limit = 8) {
        global $wpdb;

        self::maybe_install();

        $query = trim(sanitize_text_field((string) $query));
        $limit = max(1, min(12, (int) $limit));
        $settings = self::settings();

        if ('' === $query) {
            return array(
                'query' => '',
                'source' => 'database',
                'configured' => !empty($settings['api_key']),
                'matches' => array(),
                'teams' => array(),
                'competitions' => array(),
                'players' => array(),
                'stats' => array(),
            );
        }

        $table = self::fixtures_table();
        $like = '%' . $wpdb->esc_like($query) . '%';
        $candidate_limit = max(40, $limit * 12);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE home_team_name LIKE %s
                OR away_team_name LIKE %s
                OR league_name LIKE %s
                OR league_country LIKE %s
                OR payload LIKE %s
                OR details_payload LIKE %s
             ORDER BY CASE WHEN fixture_date IS NULL THEN 1 ELSE 0 END ASC,
                      ABS(TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), fixture_date)) ASC
             LIMIT %d",
            $like,
            $like,
            $like,
            $like,
            $like,
            $like,
            $candidate_limit
        ), ARRAY_A);

        $matches = array();
        $teams = array();
        $competitions = array();
        $players = array();
        $stats = array();

        foreach ($rows as $row) {
            $fixture = self::decode_json($row['payload']);
            $details = self::decode_json($row['details_payload']);

            if ($fixture) {
                $fixture = self::hydrate_fixture($fixture, $details);
                $matches[] = $fixture;
            }

            foreach (array('home', 'away') as $side) {
                $team = $fixture[$side] ?? array();
                $team_id = (int) ($team['id'] ?? 0);
                $team_key = $team_id ? 'team_' . $team_id : sanitize_key($team['name'] ?? $side);

                if (!empty($team['name'])) {
                    if (!isset($teams[$team_key])) {
                        $teams[$team_key] = array(
                            'id' => $team_id,
                            'name' => sanitize_text_field($team['name']),
                            'logo' => esc_url_raw($team['logo'] ?? ''),
                            'matches' => 0,
                            'last_fixture' => null,
                        );
                    }

                    $teams[$team_key]['matches'] += 1;
                    $teams[$team_key]['last_fixture'] = $fixture;
                }
            }

            $league = $fixture['league'] ?? array();
            $league_id = (int) ($league['id'] ?? 0);
            $competition_key = $league_id ? 'league_' . $league_id . '_' . (int) ($league['season'] ?? 0) : sanitize_key($league['name'] ?? 'football');

            if (!empty($league['name']) && !isset($competitions[$competition_key])) {
                $competitions[$competition_key] = array(
                    'id' => $league_id,
                    'name' => sanitize_text_field($league['name']),
                    'country' => sanitize_text_field($league['country'] ?? ''),
                    'season' => (int) ($league['season'] ?? 0),
                    'logo' => esc_url_raw($league['logo'] ?? ''),
                );
            }

            foreach ($details['timeline'] ?? array() as $event) {
                $player = $event['player'] ?? array();

                if (empty($player['name']) || false === stripos($player['name'], $query)) {
                    continue;
                }

                $player_key = !empty($player['id']) ? 'player_' . (int) $player['id'] : sanitize_key($player['name']);
                $players[$player_key] = array(
                    'id' => (int) ($player['id'] ?? 0),
                    'name' => sanitize_text_field($player['name']),
                    'team' => sanitize_text_field($event['team']['name'] ?? ''),
                    'team_logo' => esc_url_raw($event['team']['logo'] ?? ''),
                    'context' => sanitize_text_field(trim(($event['type'] ?? '') . ' ' . ($event['detail'] ?? ''))),
                    'fixture' => $fixture,
                );
            }

            foreach ($details['squads'] ?? array() as $lineup) {
                foreach (array_merge($lineup['startXI'] ?? array(), $lineup['substitutes'] ?? array()) as $player) {
                    if (empty($player['name']) || false === stripos($player['name'], $query)) {
                        continue;
                    }

                    $player_key = !empty($player['id']) ? 'player_' . (int) $player['id'] : sanitize_key($player['name']);
                    $players[$player_key] = array(
                        'id' => (int) ($player['id'] ?? 0),
                        'name' => sanitize_text_field($player['name']),
                        'team' => sanitize_text_field($lineup['team']['name'] ?? ''),
                        'team_logo' => esc_url_raw($lineup['team']['logo'] ?? ''),
                        'context' => sanitize_text_field(trim(($player['pos'] ?? '') . ' #' . ($player['number'] ?? ''))),
                        'fixture' => $fixture,
                    );
                }
            }

            foreach ($details['statistics'] ?? array() as $team_stats) {
                $team_name = sanitize_text_field($team_stats['team']['name'] ?? '');
                $matched_stats = array_values(array_filter($team_stats['statistics'] ?? array(), function ($stat) use ($query) {
                    return false !== stripos((string) ($stat['type'] ?? ''), $query) || false !== stripos((string) ($stat['value'] ?? ''), $query);
                }));

                if ($matched_stats) {
                    $stats[] = array(
                        'team' => $team_name,
                        'team_logo' => esc_url_raw($team_stats['team']['logo'] ?? ''),
                        'statistics' => $matched_stats,
                        'fixture' => $fixture,
                    );
                }
            }
        }

        $matches = self::rank_search_matches_by_proximity($matches);

        return array(
            'query' => $query,
            'source' => 'database',
            'configured' => !empty($settings['api_key']),
            'matches' => array_slice($matches, 0, $limit),
            'teams' => array_slice(array_values($teams), 0, $limit),
            'competitions' => array_slice(array_values($competitions), 0, $limit),
            'players' => array_slice(array_values($players), 0, $limit),
            'stats' => array_slice($stats, 0, $limit),
        );
    }

    private static function rank_search_matches_by_proximity($matches) {
        $now = current_time('timestamp', true);

        usort($matches, function ($a, $b) use ($now) {
            $a_rank = self::search_match_proximity_rank($a, $now);
            $b_rank = self::search_match_proximity_rank($b, $now);

            foreach (array_keys($a_rank) as $index) {
                if ($a_rank[$index] === $b_rank[$index]) {
                    continue;
                }

                return $a_rank[$index] <=> $b_rank[$index];
            }

            return 0;
        });

        return array_values($matches);
    }

    private static function search_match_proximity_rank($fixture, $now) {
        $timestamp = (int) ($fixture['timestamp'] ?? 0);

        if (!$timestamp && !empty($fixture['date'])) {
            $timestamp = strtotime((string) $fixture['date']);
        }

        if (!$timestamp) {
            return array(9, PHP_INT_MAX, 0, PHP_INT_MAX);
        }

        $status = strtoupper((string) ($fixture['status_short'] ?? ($fixture['status']['short'] ?? '')));
        $elapsed = (int) ($fixture['elapsed'] ?? ($fixture['status']['elapsed'] ?? 0));
        $finished_statuses = array('FT', 'AET', 'PEN', 'PST', 'CANC', 'ABD', 'AWD', 'WO');
        $not_started_statuses = array('NS', 'TBD');
        $is_finished = in_array($status, $finished_statuses, true);
        $is_not_started = in_array($status, $not_started_statuses, true);
        $is_live = !$is_finished && !$is_not_started && ($elapsed > 0 || abs($timestamp - $now) <= 4 * HOUR_IN_SECONDS);
        $delta = abs($timestamp - $now);

        if ($is_live) {
            return array(0, $delta, 0, $timestamp);
        }

        if ($delta <= 14 * DAY_IN_SECONDS) {
            return array(1, $delta, $timestamp >= $now ? 0 : 1, $timestamp);
        }

        if ($timestamp >= $now) {
            return array(2, $timestamp - $now, 0, $timestamp);
        }

        return array(3, $now - $timestamp, 0, -$timestamp);
    }

    public static function teams_payload($league = 0, $season = 0, $limit = 100) {
        global $wpdb;

        self::maybe_install();

        $settings = self::settings();
        $table = self::fixtures_table();
        $league = absint($league);
        $season = absint($season);
        $limit = max(1, min(200, (int) $limit));
        $where = array('1=1');
        $values = array();

        if ($league) {
            $where[] = 'league_id = %d';
            $values[] = $league;
        }

        if ($season) {
            $where[] = 'league_season = %d';
            $values[] = $season;
        }

        if (!$league && !$season && !empty($settings['competitions'])) {
            $competition_clauses = array();

            foreach ($settings['competitions'] as $competition) {
                $league_id = (int) ($competition['league_id'] ?? 0);
                $competition_season = (int) ($competition['season'] ?? 0);

                if (!$league_id || !$competition_season) {
                    continue;
                }

                $competition_clauses[] = '(league_id = %d AND league_season = %d)';
                $values[] = $league_id;
                $values[] = $competition_season;
            }

            if ($competition_clauses) {
                $where[] = '(' . implode(' OR ', $competition_clauses) . ')';
            }
        }

        $sql = 'SELECT payload, details_payload FROM ' . $table . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY fixture_date DESC LIMIT 800';
        $rows = $values ? $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        $teams = array();
        $competitions = array();

        foreach ($rows as $row) {
            $fixture = self::decode_json($row['payload']);
            $details = self::decode_json($row['details_payload']);
            $league_data = $fixture['league'] ?? array();
            $competition_key = (int) ($league_data['id'] ?? 0) . ':' . (int) ($league_data['season'] ?? 0);

            if (!empty($league_data['name']) && !isset($competitions[$competition_key])) {
                $competitions[$competition_key] = array(
                    'id' => (int) ($league_data['id'] ?? 0),
                    'name' => sanitize_text_field($league_data['name']),
                    'country' => sanitize_text_field($league_data['country'] ?? ''),
                    'season' => (int) ($league_data['season'] ?? 0),
                    'logo' => esc_url_raw($league_data['logo'] ?? ''),
                );
            }

            foreach (array('home', 'away') as $side) {
                $team = $fixture[$side] ?? array();
                $team_id = (int) ($team['id'] ?? 0);

                if (!$team_id || empty($team['name'])) {
                    continue;
                }

                if (!isset($teams[$team_id])) {
                    $teams[$team_id] = array(
                        'id' => $team_id,
                        'name' => sanitize_text_field($team['name']),
                        'logo' => esc_url_raw($team['logo'] ?? ''),
                        'league' => $league_data,
                        'matches' => 0,
                        'wins' => 0,
                        'draws' => 0,
                        'losses' => 0,
                        'goals_for' => 0,
                        'goals_against' => 0,
                        'players_count' => 0,
                        'last_fixture' => null,
                    );
                }

                $opponent_side = 'home' === $side ? 'away' : 'home';
                $goals_for = isset($fixture['goals'][$side]) ? (int) $fixture['goals'][$side] : 0;
                $goals_against = isset($fixture['goals'][$opponent_side]) ? (int) $fixture['goals'][$opponent_side] : 0;
                $is_finished = in_array((string) ($fixture['status_short'] ?? ''), array('FT', 'AET', 'PEN'), true);

                $teams[$team_id]['matches'] += 1;
                $teams[$team_id]['goals_for'] += $goals_for;
                $teams[$team_id]['goals_against'] += $goals_against;
                $teams[$team_id]['last_fixture'] = $teams[$team_id]['last_fixture'] ?: $fixture;

                if ($is_finished) {
                    if ($goals_for > $goals_against) {
                        $teams[$team_id]['wins'] += 1;
                    } elseif ($goals_for === $goals_against) {
                        $teams[$team_id]['draws'] += 1;
                    } else {
                        $teams[$team_id]['losses'] += 1;
                    }
                }

                foreach ($details['squads'] ?? array() as $squad) {
                    if ((int) ($squad['team']['id'] ?? 0) === $team_id) {
                        $players = array_merge($squad['startXI'] ?? array(), $squad['substitutes'] ?? array());
                        $teams[$team_id]['players_count'] = max($teams[$team_id]['players_count'], count($players));
                    }
                }
            }
        }

        $teams = array_values($teams);
        usort($teams, function ($a, $b) {
            return (int) $b['matches'] <=> (int) $a['matches'] ?: strcasecmp($a['name'], $b['name']);
        });

        return array(
            'source' => 'database',
            'configured' => !empty($settings['api_key']),
            'teams' => array_slice($teams, 0, $limit),
            'competitions' => array_values($competitions),
            'updated_at' => gmdate(DATE_ATOM),
        );
    }

    public static function team_profile_payload($team_id, $limit = 12) {
        global $wpdb;

        self::maybe_install();

        $team_id = absint($team_id);
        $limit = max(1, min(30, (int) $limit));
        $settings = self::settings();
        $table = self::fixtures_table();

        if (!$team_id) {
            return array(
                'source' => 'database',
                'configured' => !empty($settings['api_key']),
                'team' => null,
                'fixtures' => array(),
                'stats' => array(),
                'players' => array(),
                'latest_news' => array(),
            );
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE home_team_id = %d OR away_team_id = %d
             ORDER BY fixture_date DESC
             LIMIT %d",
            $team_id,
            $team_id,
            $limit
        ), ARRAY_A);

        $fixtures = array();
        $players = array();
        $stat_rows = array();
        $team = null;
        $stats = array(
            'matches' => 0,
            'wins' => 0,
            'draws' => 0,
            'losses' => 0,
            'goals_for' => 0,
            'goals_against' => 0,
            'clean_sheets' => 0,
            'latest_form' => array(),
        );

        foreach ($rows as $row) {
            $fixture = self::decode_json($row['payload']);
            $details = self::decode_json($row['details_payload']);
            $side = (int) ($fixture['home']['id'] ?? 0) === $team_id ? 'home' : 'away';
            $opponent_side = 'home' === $side ? 'away' : 'home';
            $team = $team ?: ($fixture[$side] ?? null);
            $fixtures[] = $fixture;

            $goals_for = isset($fixture['goals'][$side]) ? (int) $fixture['goals'][$side] : null;
            $goals_against = isset($fixture['goals'][$opponent_side]) ? (int) $fixture['goals'][$opponent_side] : null;
            $is_finished = in_array((string) ($fixture['status_short'] ?? ''), array('FT', 'AET', 'PEN'), true);

            $stats['matches'] += 1;

            if (null !== $goals_for) {
                $stats['goals_for'] += $goals_for;
            }

            if (null !== $goals_against) {
                $stats['goals_against'] += $goals_against;
                if (0 === $goals_against) {
                    $stats['clean_sheets'] += 1;
                }
            }

            if ($is_finished && null !== $goals_for && null !== $goals_against) {
                if ($goals_for > $goals_against) {
                    $stats['wins'] += 1;
                    $stats['latest_form'][] = 'W';
                } elseif ($goals_for === $goals_against) {
                    $stats['draws'] += 1;
                    $stats['latest_form'][] = 'D';
                } else {
                    $stats['losses'] += 1;
                    $stats['latest_form'][] = 'L';
                }
            }

            foreach ($details['statistics'] ?? array() as $team_stats) {
                if ((int) ($team_stats['team']['id'] ?? 0) === $team_id) {
                    $stat_rows[] = $team_stats;
                }
            }

            foreach ($details['squads'] ?? array() as $squad) {
                if ((int) ($squad['team']['id'] ?? 0) !== $team_id) {
                    continue;
                }

                foreach (array_merge($squad['startXI'] ?? array(), $squad['substitutes'] ?? array()) as $player) {
                    $player_key = !empty($player['id']) ? (int) $player['id'] : sanitize_key($player['name'] ?? '');

                    if (!$player_key || empty($player['name'])) {
                        continue;
                    }

                    $players[$player_key] = array(
                        'id' => (int) ($player['id'] ?? 0),
                        'name' => sanitize_text_field($player['name']),
                        'number' => isset($player['number']) ? (int) $player['number'] : null,
                        'pos' => sanitize_text_field($player['pos'] ?? ''),
                    );
                }
            }
        }

        $stats['goal_difference'] = $stats['goals_for'] - $stats['goals_against'];
        $stats['latest_form'] = array_slice($stats['latest_form'], 0, 5);
        $latest_news = $team && class_exists('Rifnote_Search_Engine')
            ? Rifnote_Search_Engine::payload(array('query' => $team['name'] ?? '', 'category' => 'Football', 'sort' => 'latest', 'date_range' => 'all'), 1, 6)
            : array('results' => array());

        return array(
            'source' => 'database',
            'configured' => !empty($settings['api_key']),
            'team' => $team,
            'fixtures' => $fixtures,
            'stats' => $stats,
            'match_statistics' => $stat_rows,
            'players' => array_values($players),
            'latest_news' => $latest_news['results'] ?? array(),
            'updated_at' => gmdate(DATE_ATOM),
        );
    }

    public static function players_payload($team_id = 0, $limit = 120) {
        global $wpdb;

        self::maybe_install();

        $team_id = absint($team_id);
        $limit = max(1, min(240, (int) $limit));
        $settings = self::settings();
        $table = self::fixtures_table();
        $where = array("details_payload IS NOT NULL", "details_payload <> ''");
        $values = array();

        if ($team_id) {
            $where[] = '(home_team_id = %d OR away_team_id = %d)';
            $values[] = $team_id;
            $values[] = $team_id;
        }

        $sql = 'SELECT payload, details_payload, fixture_date FROM ' . $table . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY fixture_date DESC LIMIT 900';
        $rows = $values ? $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        $players = array();
        $teams = array();

        foreach ($rows as $row) {
            $fixture = self::decode_json($row['payload']);
            $details = self::decode_json($row['details_payload']);

            foreach ($details['squads'] ?? array() as $squad) {
                $team = $squad['team'] ?? array();
                $current_team_id = (int) ($team['id'] ?? 0);

                if ($team_id && $current_team_id !== $team_id) {
                    continue;
                }

                if (!empty($team['name'])) {
                    $teams[$current_team_id ?: sanitize_key($team['name'])] = array(
                        'id' => $current_team_id,
                        'name' => sanitize_text_field($team['name']),
                        'logo' => esc_url_raw($team['logo'] ?? ''),
                    );
                }

                foreach ($squad['startXI'] ?? array() as $player) {
                    self::add_player_snapshot($players, $player, $team, $fixture, 'start');
                }

                foreach ($squad['substitutes'] ?? array() as $player) {
                    self::add_player_snapshot($players, $player, $team, $fixture, 'bench');
                }
            }

            foreach ($details['timeline'] ?? array() as $event) {
                $team = $event['team'] ?? array();

                if ($team_id && (int) ($team['id'] ?? 0) !== $team_id) {
                    continue;
                }

                self::add_player_event($players, $event, $fixture);
            }
        }

        $players = array_values($players);
        usort($players, function ($a, $b) {
            return (int) $b['appearances'] <=> (int) $a['appearances']
                ?: (int) $b['goals'] <=> (int) $a['goals']
                ?: strcasecmp($a['name'], $b['name']);
        });

        return array(
            'source' => 'database',
            'configured' => !empty($settings['api_key']),
            'players' => array_slice($players, 0, $limit),
            'teams' => array_values($teams),
            'updated_at' => gmdate(DATE_ATOM),
            'message' => $players ? '' : __('No stored squad or player timeline data yet. Sync fixture details to populate player profiles.', 'rifnote-search'),
        );
    }

    public static function player_profile_payload($player_id = 0, $player_name = '', $limit = 14) {
        global $wpdb;

        self::maybe_install();

        $player_id = absint($player_id);
        $player_name = trim(sanitize_text_field((string) $player_name));
        $limit = max(1, min(30, (int) $limit));
        $settings = self::settings();

        if (!$player_id && '' === $player_name) {
            return array(
                'source' => 'database',
                'configured' => !empty($settings['api_key']),
                'player' => null,
                'stats' => array(),
                'fixtures' => array(),
                'events' => array(),
                'latest_news' => array(),
                'message' => __('Choose a player to open the profile.', 'rifnote-search'),
            );
        }

        $table = self::fixtures_table();
        $rows = $wpdb->get_results(
            "SELECT payload, details_payload, fixture_date FROM {$table}
             WHERE details_payload IS NOT NULL AND details_payload <> ''
             ORDER BY fixture_date DESC
             LIMIT 1000",
            ARRAY_A
        );
        $player = null;
        $fixtures = array();
        $events = array();
        $stats = array(
            'appearances' => 0,
            'starts' => 0,
            'bench' => 0,
            'goals' => 0,
            'assists' => 0,
            'cards' => 0,
            'teams' => array(),
        );

        foreach ($rows as $row) {
            if (count($fixtures) >= $limit && count($events) >= $limit) {
                break;
            }

            $fixture = self::decode_json($row['payload']);
            $details = self::decode_json($row['details_payload']);
            $matched_fixture = false;

            foreach ($details['squads'] ?? array() as $squad) {
                $team = $squad['team'] ?? array();

                foreach (array('startXI' => 'start', 'substitutes' => 'bench') as $bucket => $role) {
                    foreach ($squad[$bucket] ?? array() as $lineup_player) {
                        if (!self::player_matches($lineup_player, $player_id, $player_name)) {
                            continue;
                        }

                        $player = self::player_summary($lineup_player, $team, $fixture);
                        $stats['appearances'] += 1;
                        $stats['starts'] += 'start' === $role ? 1 : 0;
                        $stats['bench'] += 'bench' === $role ? 1 : 0;
                        $stats['teams'][sanitize_key($team['name'] ?? '')] = sanitize_text_field($team['name'] ?? '');
                        $matched_fixture = true;
                    }
                }
            }

            foreach ($details['timeline'] ?? array() as $event) {
                $event_player = $event['player'] ?? array();
                $assist = $event['assist'] ?? array();
                $is_player = self::player_matches($event_player, $player_id, $player_name);
                $is_assist = self::player_matches($assist, $player_id, $player_name);

                if (!$is_player && !$is_assist) {
                    continue;
                }

                if (!$player) {
                    $player = self::player_summary($is_player ? $event_player : $assist, $event['team'] ?? array(), $fixture);
                }

                if ($is_player && 'Goal' === (string) ($event['type'] ?? '')) {
                    $stats['goals'] += 1;
                }

                if ($is_player && 'Card' === (string) ($event['type'] ?? '')) {
                    $stats['cards'] += 1;
                }

                if ($is_assist) {
                    $stats['assists'] += 1;
                }

                $events[] = array(
                    'fixture' => $fixture,
                    'event' => $event,
                );
                $matched_fixture = true;
            }

            if ($matched_fixture) {
                $fixtures[$fixture['id'] ?: md5(wp_json_encode($fixture))] = $fixture;
            }
        }

        $news_query = $player['name'] ?? $player_name;
        $latest_news = $news_query && class_exists('Rifnote_Search_Engine')
            ? Rifnote_Search_Engine::payload(array('query' => $news_query, 'category' => 'Football', 'sort' => 'latest', 'date_range' => 'all'), 1, 8)
            : array('results' => array());

        return array(
            'source' => 'database',
            'configured' => !empty($settings['api_key']),
            'player' => $player,
            'stats' => array_merge($stats, array('teams' => array_values(array_filter($stats['teams'])))),
            'fixtures' => array_slice(array_values($fixtures), 0, $limit),
            'events' => array_slice($events, 0, $limit),
            'latest_news' => $latest_news['results'] ?? array(),
            'updated_at' => gmdate(DATE_ATOM),
            'message' => $player ? '' : __('This player is not in stored fixture details yet.', 'rifnote-search'),
        );
    }

    public static function transfer_news_payload($limit = 24) {
        $limit = max(1, min(60, (int) $limit));
        $terms = array('transfer', 'signs', 'joins', 'loan', 'bid', 'contract', 'release clause', 'move');
        $stories = array();
        $sources = array();

        if (class_exists('Rifnote_Search_Engine')) {
            foreach ($terms as $term) {
                $payload = Rifnote_Search_Engine::payload(array(
                    'query' => $term,
                    // Warehouse RSS items are often categorised as Sport rather
                    // than Football. The transfer classifier below is the safer filter.
                    'category' => '',
                    'sort' => 'latest',
                    'date_range' => 'all',
                    'include_warehouse' => true,
                ), 1, 10);

                foreach ($payload['results'] ?? array() as $story) {
                    $key = !empty($story['id']) ? 'post_' . (int) $story['id'] : md5(($story['headline'] ?? '') . ($story['original_url'] ?? ''));
                    $text = strtolower(wp_strip_all_tags(($story['headline'] ?? '') . ' ' . ($story['excerpt'] ?? '')));

                    if (!self::looks_like_transfer_story($text)) {
                        continue;
                    }

                    $stories[$key] = $story;

                    if (!empty($story['source_domain'])) {
                        $sources[$story['source_domain']] = true;
                    }
                }
            }
        }

        $stories = array_values($stories);
        usort($stories, function ($a, $b) {
            return strtotime($b['published_at'] ?? '') <=> strtotime($a['published_at'] ?? '');
        });

        $deals = self::build_transfer_deals($stories);
        $exceptions = array_values(array_filter($deals, function ($deal) {
            return !empty($deal['needs_review']);
        }));

        return array(
            'source' => 'database',
            'configured' => true,
            'stories' => array_slice($stories, 0, $limit),
            'deals' => array_slice($deals, 0, $limit),
            'confirmed_count' => count(array_filter($deals, function ($deal) { return 'confirmed' === $deal['status']; })),
            'exceptions_count' => count($exceptions),
            'deadline' => self::transfer_deadline_context(),
            'sources' => count($sources),
            'topics' => self::transfer_topics($stories),
            'updated_at' => gmdate(DATE_ATOM),
            'message' => $stories ? '' : __('No transfer stories are indexed yet. Add football sources or run a TheNewsAPI/RSS import with transfer keywords.', 'rifnote-search'),
        );
    }

    public static function transfer_deadline_context() {
        $timezone = wp_timezone();
        $now = new DateTimeImmutable('now', $timezone);
        $default = $now->format('Y') . '-09-01 23:00:00';
        $deadline_text = sanitize_text_field((string) get_option('rifnote_transfer_deadline_at', $default));

        try {
            $deadline = new DateTimeImmutable($deadline_text, $timezone);
        } catch (Exception $exception) {
            $deadline = new DateTimeImmutable($default, $timezone);
        }

        return array(
            'enabled' => (bool) get_option('rifnote_transfer_deadline_enabled', true) && $now <= $deadline->modify('+2 days'),
            'timestamp' => $deadline->format(DATE_ATOM),
            'timezone' => $timezone->getName(),
            'label' => sanitize_text_field((string) get_option('rifnote_transfer_deadline_label', __('Transfer Deadline Day', 'rifnote-search'))),
            'is_closed' => $now >= $deadline,
            'url' => home_url('/transfers/'),
        );
    }

    private static function build_transfer_deals($stories) {
        $clusters = array();

        foreach ((array) $stories as $story) {
            $parsed = self::parse_transfer_story($story);
            $key = $parsed['cluster_key'];

            if (!isset($clusters[$key])) {
                $clusters[$key] = array_merge($parsed, array(
                    'stories' => array(),
                    'sources' => array(),
                    'destinations' => array(),
                ));
            }

            $clusters[$key]['stories'][] = $story;
            $domain = sanitize_text_field((string) ($story['source_domain'] ?? ''));
            if ($domain) $clusters[$key]['sources'][$domain] = true;
            if (!empty($parsed['to_club'])) $clusters[$key]['destinations'][sanitize_key($parsed['to_club'])] = $parsed['to_club'];

            if ($parsed['status_rank'] > $clusters[$key]['status_rank']) {
                foreach (array('status', 'status_label', 'status_rank', 'confidence', 'official', 'to_club', 'from_club', 'fee', 'transfer_type') as $field) {
                    $clusters[$key][$field] = $parsed[$field];
                }
            }
        }

        $deals = array();
        foreach ($clusters as $deal) {
            $deal['source_count'] = count($deal['sources']);
            $deal['supporting_sources'] = array_values(array_keys($deal['sources']));
            $deal['story_count'] = count($deal['stories']);
            $deal['latest_story'] = $deal['stories'][0] ?? array();
            $conflicting = count($deal['destinations']) > 1;

            if (!$deal['official'] && $deal['source_count'] >= 2 && $deal['status_rank'] < 4) {
                $deal['status'] = 'strongly-reported';
                $deal['status_label'] = __('Strongly reported', 'rifnote-search');
                $deal['status_rank'] = 3;
                $deal['confidence'] = max(.78, $deal['confidence']);
            }

            if ($conflicting) {
                $deal['status'] = 'disputed';
                $deal['status_label'] = __('Disputed', 'rifnote-search');
            }

            $deal['needs_review'] = $conflicting || $deal['confidence'] < .55 || empty($deal['player']);
            unset($deal['sources'], $deal['destinations'], $deal['stories'], $deal['status_rank'], $deal['cluster_key']);
            $deals[] = $deal;
        }

        usort($deals, function ($a, $b) {
            $rank = array('confirmed' => 6, 'medical' => 5, 'strongly-reported' => 4, 'agreement' => 3, 'reported' => 2, 'off' => 1, 'disputed' => 0);
            $score = ($rank[$b['status']] ?? 0) <=> ($rank[$a['status']] ?? 0);
            return $score ?: (strtotime($b['latest_story']['published_at'] ?? '') <=> strtotime($a['latest_story']['published_at'] ?? ''));
        });

        return $deals;
    }

    private static function parse_transfer_story($story) {
        $headline = sanitize_text_field((string) ($story['headline'] ?? ''));
        $excerpt = sanitize_text_field((string) ($story['excerpt'] ?? ''));
        $text = trim($headline . ' ' . $excerpt);
        $lower = strtolower($text);
        $domain = strtolower((string) ($story['source_domain'] ?? ''));
        $official_domains = array_filter(array_map('trim', preg_split('/[\r\n,]+/', strtolower((string) get_option('rifnote_transfer_official_domains', 'premierleague.com,uefa.com,fifa.com')))));
        $trusted_domains = array_filter(array_map('trim', preg_split('/[\r\n,]+/', strtolower((string) get_option('rifnote_transfer_trusted_domains', 'bbc.com,bbc.co.uk,skysports.com,theathletic.com,espn.com,reuters.com')))));
        $official = (bool) array_filter($official_domains, function ($item) use ($domain) { return $item && false !== strpos($domain, $item); });
        $trusted = $official || (bool) array_filter($trusted_domains, function ($item) use ($domain) { return $item && false !== strpos($domain, $item); });
        $player = self::extract_transfer_player($headline);
        $to_club = '';
        $from_club = '';

        if (preg_match('/\bto\s+(?:join\s+)?([A-Z][A-Za-z0-9 .&-]{2,35})(?:\s+(?:for|after|as|on)\b|$)/', $headline, $match)) $to_club = trim($match[1]);
        if (preg_match('/\bfrom\s+([A-Z][A-Za-z0-9 .&-]{2,35})(?:\s+(?:for|after|as|on|to)\b|$)/', $headline, $match)) $from_club = trim($match[1]);
        if (!$to_club && preg_match('/^([A-Z][A-Za-z .&-]{2,30})\s+(?:sign|signs|agree|complete)/', $headline, $match)) $to_club = trim($match[1]);

        $status = 'reported';
        $label = __('Reported', 'rifnote-search');
        $rank = 1;
        $confidence = $trusted ? .62 : .45;
        if (preg_match('/\b(deal off|move off|collapsed|collapse|pulls out|withdraws)\b/i', $text)) {
            $status = 'off'; $label = __('Off', 'rifnote-search'); $rank = 5; $confidence = $trusted ? .9 : .7;
        } elseif ($official && preg_match('/\b(signs|signed|signing|joins|joined|complete[sd]?|announc(?:e|es|ed)|confirmed)\b/i', $text)) {
            $status = 'confirmed'; $label = __('Confirmed', 'rifnote-search'); $rank = 6; $confidence = .98;
        } elseif (preg_match('/\bmedical\b/i', $text)) {
            $status = 'medical'; $label = __('Medical', 'rifnote-search'); $rank = 4; $confidence = $trusted ? .84 : .68;
        } elseif (preg_match('/\b(agreed|agreement|deal agreed|terms agreed|here we go)\b/i', $text)) {
            $status = 'agreement'; $label = __('Agreement', 'rifnote-search'); $rank = 3; $confidence = $trusted ? .8 : .62;
        }

        $fee = preg_match('/(?:£|€|\$)\s?\d+(?:\.\d+)?\s?(?:m|million|bn|billion)?/i', $text, $fee_match) ? $fee_match[0] : '';
        $type = false !== strpos($lower, 'loan') ? 'loan' : (false !== strpos($lower, 'free transfer') ? 'free' : 'permanent');
        $cluster_seed = $player ? $player : implode(' ', array_slice(preg_split('/\s+/', strtolower($headline)), 0, 7));

        return array(
            'cluster_key' => sanitize_key($cluster_seed),
            'player' => $player,
            'from_club' => $from_club,
            'to_club' => $to_club,
            'fee' => $fee,
            'transfer_type' => $type,
            'status' => $status,
            'status_label' => $label,
            'status_rank' => $rank,
            'confidence' => $confidence,
            'official' => $official,
        );
    }

    private static function extract_transfer_player($headline) {
        $headline = preg_replace('/^(Report|Transfer news|Exclusive|Breaking)\s*:\s*/i', '', (string) $headline);
        $patterns = array(
            '/^[A-Z][A-Za-z .\'-]{2,45}?(?=\s+(?:signs|joins|moves|agrees|completes|set to|close to|undergoes|has joined)\b)/',
            '/\b(?:sign|signs|signing of|deal for|bid for)\s+([A-Z][A-Za-z .\'-]{2,45}?)(?=\s+(?:from|for|after|as|on|to)\b|$)/',
        );
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $headline, $match)) return trim($match[1] ?? $match[0]);
        }
        return '';
    }

    public static function backfill_history($years = 1, $detail_limit = 25) {
        global $wpdb;

        self::maybe_install();

        $settings = self::settings();
        $years = max(1, min(8, (int) $years));
        $detail_limit = max(0, min(250, (int) $detail_limit));

        if (empty($settings['api_key'])) {
            return new WP_Error('rifnote_api_football_not_configured', __('API-Football key is not configured.', 'rifnote-search'));
        }

        if (empty($settings['competitions'])) {
            return new WP_Error('rifnote_api_football_no_competitions', __('Add league/cup IDs before running a football history backfill.', 'rifnote-search'));
        }

        $summary = array(
            'ok' => true,
            'years' => $years,
            'detail_limit' => $detail_limit,
            'competitions' => 0,
            'seasons' => 0,
            'fixtures' => 0,
            'details_synced' => 0,
            'errors' => array(),
            'started_at' => gmdate(DATE_ATOM),
            'finished_at' => '',
        );

        foreach ($settings['competitions'] as $competition) {
            $league_id = (int) ($competition['league_id'] ?? 0);
            $base_season = (int) ($competition['season'] ?? 0);

            if (!$league_id || !$base_season) {
                continue;
            }

            $summary['competitions'] += 1;

            for ($offset = 0; $offset < $years; $offset += 1) {
                $season = max(1900, $base_season - $offset);
                $summary['seasons'] += 1;
                $response = self::request('/fixtures', array(
                    'league' => $league_id,
                    'season' => $season,
                    'timezone' => $settings['timezone'],
                ));

                if (is_wp_error($response)) {
                    $summary['ok'] = false;
                    $summary['errors'][] = sprintf('%s %d: %s', $competition['label'] ?? $league_id, $season, $response->get_error_message());
                    continue;
                }

                $fixtures = self::hydrate_fixtures(array_map(array(__CLASS__, 'normalize_fixture'), $response['response'] ?? array()));
                $summary['fixtures'] += count($fixtures);
                self::store_payload_fixtures(array('fixtures' => $fixtures), 'history', YEAR_IN_SECONDS);

                if ($detail_limit > 0 && $fixtures) {
                    foreach ($fixtures as $fixture) {
                        if ($summary['details_synced'] >= $detail_limit) {
                            break;
                        }

                        $details = self::sync_fixture_details($fixture);
                        $fixture_id = (int) ($fixture['id'] ?? 0);

                        if (!$fixture_id) {
                            continue;
                        }

                        $wpdb->update(self::fixtures_table(), array(
                            'details_payload' => wp_json_encode($details),
                            'details_synced_at' => current_time('mysql', true),
                            'updated_at' => current_time('mysql', true),
                        ), array('fixture_id' => $fixture_id));

                        $summary['details_synced'] += 1;
                    }
                }
            }
        }

        $summary['finished_at'] = gmdate(DATE_ATOM);
        update_option('rifnote_api_football_last_history_backfill', $summary, false);
        self::clear_cache();

        return $summary;
    }

    private static function add_player_snapshot(&$players, $player, $team, $fixture, $role) {
        if (empty($player['name'])) {
            return;
        }

        $key = !empty($player['id']) ? 'player_' . (int) $player['id'] : sanitize_key(($player['name'] ?? '') . '_' . ($team['name'] ?? ''));

        if (!isset($players[$key])) {
            $players[$key] = self::player_summary($player, $team, $fixture);
        }

        $players[$key]['appearances'] += 1;
        $players[$key]['starts'] += 'start' === $role ? 1 : 0;
        $players[$key]['bench'] += 'bench' === $role ? 1 : 0;
        $players[$key]['latest_fixture'] = $players[$key]['latest_fixture'] ?: $fixture;
    }

    private static function add_player_event(&$players, $event, $fixture) {
        $team = $event['team'] ?? array();
        $event_player = $event['player'] ?? array();
        $assist = $event['assist'] ?? array();

        if (!empty($event_player['name'])) {
            $key = !empty($event_player['id']) ? 'player_' . (int) $event_player['id'] : sanitize_key(($event_player['name'] ?? '') . '_' . ($team['name'] ?? ''));

            if (!isset($players[$key])) {
                $players[$key] = self::player_summary($event_player, $team, $fixture);
            }

            $players[$key]['events'] += 1;

            if ('Goal' === (string) ($event['type'] ?? '')) {
                $players[$key]['goals'] += 1;
            }

            if ('Card' === (string) ($event['type'] ?? '')) {
                $players[$key]['cards'] += 1;
            }
        }

        if (!empty($assist['name'])) {
            $key = !empty($assist['id']) ? 'player_' . (int) $assist['id'] : sanitize_key(($assist['name'] ?? '') . '_' . ($team['name'] ?? ''));

            if (!isset($players[$key])) {
                $players[$key] = self::player_summary($assist, $team, $fixture);
            }

            $players[$key]['assists'] += 1;
        }
    }

    private static function player_summary($player, $team, $fixture) {
        return array(
            'id' => (int) ($player['id'] ?? 0),
            'name' => sanitize_text_field($player['name'] ?? ''),
            'number' => isset($player['number']) ? (int) $player['number'] : null,
            'pos' => sanitize_text_field($player['pos'] ?? ''),
            'team' => array(
                'id' => (int) ($team['id'] ?? 0),
                'name' => sanitize_text_field($team['name'] ?? ''),
                'logo' => esc_url_raw($team['logo'] ?? ''),
            ),
            'appearances' => 0,
            'starts' => 0,
            'bench' => 0,
            'goals' => 0,
            'assists' => 0,
            'cards' => 0,
            'events' => 0,
            'latest_fixture' => $fixture,
        );
    }

    private static function player_matches($player, $player_id, $player_name) {
        if (!$player || !is_array($player)) {
            return false;
        }

        if ($player_id && (int) ($player['id'] ?? 0) === $player_id) {
            return true;
        }

        return '' !== $player_name && 0 === strcasecmp(trim((string) ($player['name'] ?? '')), $player_name);
    }

    private static function looks_like_transfer_story($text) {
        $has_transfer_signal = (bool) preg_match('/\b(transfer|transfers|signs|signed|signing|joins|joined|loan|bid|bids|contract|clause|move|deal|medical)\b/i', $text);

        if (!$has_transfer_signal) {
            return false;
        }

        return (bool) preg_match('/\b(football|soccer|club|clubs|league|cup|striker|forward|winger|midfielder|defender|keeper|goalkeeper|manager|coach|premier|laliga|serie\s*a|bundesliga|uefa|fifa|afcon|chelsea|arsenal|liverpool|manchester|united|city|madrid|barcelona|napoli|psg|bayern|inter|milan|juventus|fc)\b/i', $text);
    }

    private static function transfer_topics($stories) {
        $topics = array();

        foreach ($stories as $story) {
            foreach ($story['tags'] ?? array() as $tag) {
                $label = sanitize_text_field(is_array($tag) ? ($tag['name'] ?? '') : $tag);

                if ($label) {
                    $topics[sanitize_key($label)] = $label;
                }
            }
        }

        return array_slice(array_values($topics), 0, 12);
    }

    private static function cached_payload($mode, $ttl, $args = array()) {
        global $wpdb;

        self::maybe_install();

        $table = self::fixtures_table();
        $now = current_time('mysql', true);
        $where = array();
        $values = array();
        $cache_groups = !empty($args['cache_groups']) && is_array($args['cache_groups']) ? array_values(array_filter(array_map('sanitize_key', $args['cache_groups']))) : array(sanitize_key($mode));

        if (count($cache_groups) > 1) {
            $where[] = 'cache_group IN (' . implode(',', array_fill(0, count($cache_groups), '%s')) . ')';
            $values = array_merge($values, $cache_groups);
        } else {
            $where[] = 'cache_group = %s';
            $values[] = $cache_groups ? $cache_groups[0] : sanitize_key($mode);
        }

        if (empty($args['allow_stale'])) {
            $where[] = 'cache_expires_at > %s';
            $values[] = $now;
        }

        if (!empty($args['date'])) {
            $where[] = 'DATE(fixture_date) = %s';
            $values[] = sanitize_text_field($args['date']);
        }

        if ('upcoming' === $mode) {
            $where[] = 'fixture_date >= %s';
            $values[] = gmdate('Y-m-d H:i:s');

            if (!empty($args['future_hours'])) {
                $where[] = 'fixture_date <= %s';
                $values[] = gmdate('Y-m-d H:i:s', time() + max(1, min(168, (int) $args['future_hours'])) * HOUR_IN_SECONDS);
            }

            $where[] = "status_short IN ('" . implode("','", array_map('esc_sql', self::upcoming_statuses())) . "')";
        }

        if ('live' === $mode || !empty($args['live_window'])) {
            $where[] = "status_short IN ('" . implode("','", array_map('esc_sql', self::live_statuses())) . "')";
            $where[] = 'fixture_date >= %s';
            $values[] = gmdate('Y-m-d H:i:s', time() - 4 * HOUR_IN_SECONDS);
            $where[] = 'fixture_date <= %s';
            $values[] = gmdate('Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS);
        }

        if (!empty($args['league'])) {
            $where[] = 'league_id = %d';
            $values[] = (int) $args['league'];
        }

        if (!empty($args['season'])) {
            $where[] = 'league_season = %d';
            $values[] = (int) $args['season'];
        }

        if (empty($args['league']) && empty($args['season']) && !empty($args['competitions']) && is_array($args['competitions'])) {
            $competition_clauses = array();

            foreach ($args['competitions'] as $competition) {
                $league_id = (int) ($competition['league_id'] ?? 0);
                $season = (int) ($competition['season'] ?? 0);

                if (!$league_id || !$season) {
                    continue;
                }

                $competition_clauses[] = '(league_id = %d AND league_season = %d)';
                $values[] = $league_id;
                $values[] = $season;
            }

            if ($competition_clauses) {
                $where[] = '(' . implode(' OR ', $competition_clauses) . ')';
            }
        }

        $watchlist = self::team_watchlist();
        if (!empty($watchlist['enabled']) && !empty($watchlist['ids'])) {
            $team_ids = array_map('absint', array_keys($watchlist['ids']));
            $where[] = '(home_team_id IN (' . implode(',', array_fill(0, count($team_ids), '%d')) . ') OR away_team_id IN (' . implode(',', array_fill(0, count($team_ids), '%d')) . '))';
            $values = array_merge($values, $team_ids, $team_ids);
        }

        $limit = !empty($args['next']) ? max(1, min(100, (int) $args['next'])) : 100;
        $sql = $wpdb->prepare(
            "SELECT payload FROM {$table} WHERE " . implode(' AND ', $where) . ' ORDER BY fixture_date ASC LIMIT %d',
            array_merge($values, array($limit))
        );
        $rows = $wpdb->get_col($sql);

        if (!$rows) {
            return null;
        }

        $fixtures = array_values(array_filter(array_map(array(__CLASS__, 'decode_json'), $rows)));

        if (!$fixtures) {
            return null;
        }

        $settings = self::settings();

        return array(
            'provider' => 'api-football',
            'mode' => sanitize_key($mode),
            'configured' => !empty($settings['api_key']),
            'updated_at' => gmdate(DATE_ATOM),
            'poll_after' => (int) $ttl,
            'competitions' => $settings['competitions'],
            'team_watchlist' => $settings['team_watchlist']['labels'] ?? array(),
            'team_watchlist_active' => !empty($settings['team_watchlist']['enabled']),
            'window_hours' => !empty($args['future_hours']) ? (int) $args['future_hours'] : null,
            'fixtures' => self::filter_fixtures_by_team_watchlist(self::hydrate_fixtures($fixtures), $settings['team_watchlist']),
            'errors' => array(),
            'source' => 'database',
        );
    }

    private static function filter_upcoming_window($fixtures, $hours = 24) {
        $now = time();
        $until = $now + max(1, min(168, (int) $hours)) * HOUR_IN_SECONDS;

        return array_values(array_filter((array) $fixtures, function ($fixture) use ($now, $until) {
            $timestamp = (int) ($fixture['timestamp'] ?? 0);
            $status = strtoupper((string) ($fixture['status_short'] ?? ''));

            if (!$timestamp || !in_array($status, self::upcoming_statuses(), true)) {
                return false;
            }

            return $timestamp >= $now && $timestamp <= $until;
        }));
    }

    private static function filter_live_window($fixtures) {
        $now = time();
        $started_after = $now - 4 * HOUR_IN_SECONDS;
        $starts_before = $now + 2 * HOUR_IN_SECONDS;

        return array_values(array_filter((array) $fixtures, function ($fixture) use ($started_after, $starts_before) {
            $timestamp = (int) ($fixture['timestamp'] ?? 0);
            $status = strtoupper((string) ($fixture['status_short'] ?? ''));

            if (!$timestamp || !in_array($status, self::live_statuses(), true)) {
                return false;
            }

            return $timestamp >= $started_after && $timestamp <= $starts_before;
        }));
    }

    private static function is_matchday_date($date) {
        $date = sanitize_text_field((string) $date);

        return in_array($date, array(
            gmdate('Y-m-d'),
            gmdate('Y-m-d', time() + DAY_IN_SECONDS),
        ), true);
    }

    private static function throttled_refresh($key, $ttl, $callback) {
        $settings = self::settings();

        if (empty($settings['api_key']) || !is_callable($callback)) {
            return null;
        }

        $lock_key = 'rifnote_api_football_refresh_' . md5((string) $key);

        if (get_transient($lock_key)) {
            return null;
        }

        set_transient($lock_key, 1, max(60, min(15 * MINUTE_IN_SECONDS, (int) $ttl)));

        return call_user_func($callback);
    }

    private static function sync_matchday_window($force = false) {
        $settings = self::settings();

        if (empty($settings['api_key']) || empty($settings['competitions'])) {
            return;
        }

        $lock_key = 'rifnote_api_football_matchday_window_sync';
        if (!$force && get_transient($lock_key)) {
            return;
        }

        set_transient($lock_key, 1, max(60, (int) $settings['fixture_cache_ttl']));

        $dates = array_unique(array(
            gmdate('Y-m-d'),
            gmdate('Y-m-d', time() + DAY_IN_SECONDS),
        ));

        foreach ($dates as $date) {
            foreach ($settings['competitions'] as $competition) {
                $league_id = (int) ($competition['league_id'] ?? 0);
                $season = (int) ($competition['season'] ?? 0);

                if (!$league_id || !$season) {
                    continue;
                }

                self::fixtures_payload($date, $league_id, $season, true);
            }
        }
    }

    private static function filter_fixtures_by_competitions($fixtures, $competitions) {
        if (empty($competitions) || !is_array($competitions)) {
            return array_values($fixtures);
        }

        $allowed = array();

        foreach ($competitions as $competition) {
            $league_id = (int) ($competition['league_id'] ?? 0);
            $season = (int) ($competition['season'] ?? 0);

            if ($league_id && $season) {
                $allowed[$league_id . ':' . $season] = $competition;
            }
        }

        if (!$allowed) {
            return array_values($fixtures);
        }

        $filtered = array();

        foreach ($fixtures as $fixture) {
            $key = (int) ($fixture['league']['id'] ?? 0) . ':' . (int) ($fixture['league']['season'] ?? 0);

            if (isset($allowed[$key])) {
                $fixture['watchlist_label'] = $allowed[$key]['label'];
                $filtered[] = $fixture;
            }
        }

        return $filtered;
    }

    private static function apply_team_visibility_to_payload($payload, $settings = null) {
        if (!is_array($payload) || empty($payload['fixtures']) || !is_array($payload['fixtures'])) {
            return $payload;
        }

        $settings = is_array($settings) ? $settings : self::settings();
        $watchlist = $settings['team_watchlist'] ?? self::team_watchlist();
        $payload['fixtures'] = self::filter_fixtures_by_team_watchlist($payload['fixtures'], $watchlist);
        $payload['team_watchlist'] = $watchlist['labels'] ?? array();
        $payload['team_watchlist_active'] = !empty($watchlist['enabled']);

        return $payload;
    }

    private static function filter_fixtures_by_team_watchlist($fixtures, $watchlist = null) {
        $watchlist = is_array($watchlist) ? $watchlist : self::team_watchlist();

        if (empty($watchlist['enabled'])) {
            return array_values((array) $fixtures);
        }

        return array_values(array_filter((array) $fixtures, function ($fixture) use ($watchlist) {
            return self::fixture_matches_team_watchlist($fixture, $watchlist);
        }));
    }

    private static function fixture_matches_team_watchlist($fixture, $watchlist = null) {
        $watchlist = is_array($watchlist) ? $watchlist : self::team_watchlist();

        if (empty($watchlist['enabled'])) {
            return true;
        }

        $ids = $watchlist['ids'] ?? array();
        $names = $watchlist['names'] ?? array();
        $home_id = (int) ($fixture['home']['id'] ?? 0);
        $away_id = (int) ($fixture['away']['id'] ?? 0);

        if (($home_id && isset($ids[$home_id])) || ($away_id && isset($ids[$away_id]))) {
            return true;
        }

        $home_name = self::normalize_team_name_key($fixture['home']['name'] ?? '');
        $away_name = self::normalize_team_name_key($fixture['away']['name'] ?? '');

        return ($home_name && isset($names[$home_name])) || ($away_name && isset($names[$away_name]));
    }

    private static function normalize_team_name_key($name) {
        $name = html_entity_decode(wp_strip_all_tags((string) $name), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = strtolower(remove_accents($name));
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name);
        $name = trim(preg_replace('/\s+/', ' ', $name));

        return $name;
    }

    private static function store_payload_fixtures($payload, $cache_group, $ttl) {
        global $wpdb;

        self::maybe_install();

        $fixtures = $payload['fixtures'] ?? array();
        $table = self::fixtures_table();
        $cache_expires_at = gmdate('Y-m-d H:i:s', time() + max(10, (int) $ttl));
        $updated_at = current_time('mysql', true);
        $cache_group = sanitize_key($cache_group);

        if ('live' === $cache_group) {
            $wpdb->delete($table, array('cache_group' => 'live'));
        }

        foreach ($fixtures as $fixture) {
            $fixture_id = (int) ($fixture['id'] ?? 0);

            if (!$fixture_id) {
                continue;
            }

            $fixture = self::hydrate_fixture($fixture);

            $fixture_date = !empty($fixture['date']) ? gmdate('Y-m-d H:i:s', strtotime($fixture['date'])) : null;
            $data = array(
                'fixture_id' => $fixture_id,
                'cache_group' => $cache_group,
                'fixture_date' => $fixture_date,
                'status_short' => sanitize_text_field($fixture['status_short'] ?? ''),
                'status_long' => sanitize_text_field($fixture['status_long'] ?? ''),
                'league_id' => (int) ($fixture['league']['id'] ?? 0),
                'league_name' => sanitize_text_field($fixture['league']['name'] ?? ''),
                'league_country' => sanitize_text_field($fixture['league']['country'] ?? ''),
                'league_season' => (int) ($fixture['league']['season'] ?? 0),
                'home_team_id' => (int) ($fixture['home']['id'] ?? 0),
                'home_team_name' => sanitize_text_field($fixture['home']['name'] ?? ''),
                'away_team_id' => (int) ($fixture['away']['id'] ?? 0),
                'away_team_name' => sanitize_text_field($fixture['away']['name'] ?? ''),
                'payload' => wp_json_encode($fixture),
                'cache_expires_at' => $cache_expires_at,
                'updated_at' => $updated_at,
            );

            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE fixture_id = %d LIMIT 1", $fixture_id));

            if ($existing) {
                $wpdb->update($table, $data, array('fixture_id' => $fixture_id));
            } else {
                $wpdb->insert($table, $data);
            }
        }
    }

    private static function sync_fixture_details($fixture) {
        $fixture_id = (int) ($fixture['id'] ?? 0);

        if (!$fixture_id) {
            return self::empty_details();
        }

        $events = self::request('/fixtures/events', array('fixture' => $fixture_id));
        $statistics = self::request('/fixtures/statistics', array('fixture' => $fixture_id));
        $lineups = self::request('/fixtures/lineups', array('fixture' => $fixture_id));
        $h2h = null;

        if (!empty($fixture['home']['id']) && !empty($fixture['away']['id'])) {
            $h2h = self::request('/fixtures/headtohead', array(
                'h2h' => (int) $fixture['home']['id'] . '-' . (int) $fixture['away']['id'],
                'last' => 8,
            ));
        }

        $timeline = !is_wp_error($events) ? array_map(array(__CLASS__, 'normalize_event'), $events['response'] ?? array()) : array();
        $goalscorers = array_values(array_filter($timeline, function ($event) {
            return 'Goal' === ($event['type'] ?? '');
        }));

        return array(
            'goalscorers' => $goalscorers,
            'statistics' => !is_wp_error($statistics) ? array_map(array(__CLASS__, 'normalize_statistics'), $statistics['response'] ?? array()) : array(),
            'timeline' => $timeline,
            'h2h' => !is_wp_error($h2h) && is_array($h2h) ? self::hydrate_fixtures(array_map(array(__CLASS__, 'normalize_fixture'), $h2h['response'] ?? array())) : array(),
            'squads' => !is_wp_error($lineups) ? array_map(array(__CLASS__, 'normalize_lineup'), $lineups['response'] ?? array()) : array(),
            'errors' => array_filter(array(
                'events' => is_wp_error($events) ? $events->get_error_message() : '',
                'statistics' => is_wp_error($statistics) ? $statistics->get_error_message() : '',
                'lineups' => is_wp_error($lineups) ? $lineups->get_error_message() : '',
                'h2h' => is_wp_error($h2h) ? $h2h->get_error_message() : '',
            )),
            'synced_at' => gmdate(DATE_ATOM),
        );
    }

    private static function empty_details() {
        return array(
            'goalscorers' => array(),
            'statistics' => array(),
            'timeline' => array(),
            'h2h' => array(),
            'squads' => array(),
            'errors' => array(),
        );
    }

    public static function admin_health() {
        $settings = self::settings();
        $last_test = get_option('rifnote_api_football_last_test', array());
        $last_live_sync = get_option('rifnote_api_football_last_live_sync', '');

        return array(
            'configured' => !empty($settings['api_key']),
            'host' => $settings['host'],
            'provider' => $settings['provider'],
            'timezone' => $settings['timezone'],
            'live_cache_ttl' => (int) $settings['live_cache_ttl'],
            'fixture_cache_ttl' => (int) $settings['fixture_cache_ttl'],
            'upcoming_cache_ttl' => (int) $settings['upcoming_cache_ttl'],
            'finished_cache_ttl' => (int) $settings['finished_cache_ttl'],
            'details_cache_ttl' => (int) $settings['details_cache_ttl'],
            'competitions_count' => count($settings['competitions']),
            'last_live_sync' => $last_live_sync,
            'last_test' => is_array($last_test) ? $last_test : array(),
            'endpoints' => array(
                'live' => rest_url('rifnote/v1/football/live'),
                'fixtures' => rest_url('rifnote/v1/football/fixtures'),
                'watchlist' => rest_url('rifnote/v1/football/watchlist'),
                'upcoming' => rest_url('rifnote/v1/football/upcoming'),
                'fixture_details' => rest_url('rifnote/v1/football/fixture/{fixture_id}'),
            ),
        );
    }

    public static function clear_cache() {
        global $wpdb;

        $patterns = array(
            '_transient_rifnote_api_football_%',
            '_transient_timeout_rifnote_api_football_%',
        );
        $deleted = 0;

        foreach ($patterns as $pattern) {
            $deleted += (int) $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $pattern
            ));
        }

        return $deleted;
    }

    public static function summarize_payload($payload) {
        if (!is_array($payload)) {
            return array(
                'ok' => false,
                'provider' => '',
                'mode' => '',
                'fixtures' => 0,
                'message' => __('No payload returned.', 'rifnote-search'),
            );
        }

        $errors = $payload['errors'] ?? array();
        $message = isset($payload['message']) ? (string) $payload['message'] : '';

        if (!$message && !empty($errors)) {
            $message = wp_json_encode($errors);
        }

        return array(
            'ok' => !empty($payload['configured']) && empty($errors),
            'provider' => sanitize_text_field($payload['provider'] ?? ''),
            'mode' => sanitize_text_field($payload['mode'] ?? ''),
            'fixtures' => count($payload['fixtures'] ?? array()),
            'updated_at' => sanitize_text_field($payload['updated_at'] ?? ''),
            'poll_after' => (int) ($payload['poll_after'] ?? 0),
            'message' => $message,
        );
    }

    public static function log_usage($data) {
        global $wpdb;

        self::maybe_install();

        return (bool) $wpdb->insert(self::usage_table(), array(
            'provider' => sanitize_key($data['provider'] ?? ''),
            'host' => sanitize_text_field($data['host'] ?? ''),
            'endpoint' => sanitize_text_field($data['endpoint'] ?? ''),
            'request_path' => sanitize_text_field($data['request_path'] ?? ''),
            'query_hash' => sanitize_text_field($data['query_hash'] ?? ''),
            'query_args' => !empty($data['query_args']) ? wp_json_encode($data['query_args']) : null,
            'http_status' => isset($data['http_status']) ? (int) $data['http_status'] : 0,
            'response_count' => isset($data['response_count']) ? (int) $data['response_count'] : 0,
            'quota_limit' => isset($data['quota_limit']) && '' !== $data['quota_limit'] ? (int) $data['quota_limit'] : null,
            'quota_remaining' => isset($data['quota_remaining']) && '' !== $data['quota_remaining'] ? (int) $data['quota_remaining'] : null,
            'duration_ms' => isset($data['duration_ms']) ? (int) $data['duration_ms'] : 0,
            'cache_hit' => !empty($data['cache_hit']) ? 1 : 0,
            'error_message' => sanitize_textarea_field($data['error_message'] ?? ''),
            'created_at' => current_time('mysql', true),
        ));
    }

    public static function usage_summary($days = 7) {
        global $wpdb;

        self::maybe_install();

        $days = max(1, min(90, (int) $days));
        $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $table = self::usage_table();

        return array(
            'requests' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $since)),
            'errors' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND (http_status < 200 OR http_status >= 300 OR error_message <> '')", $since)),
            'fixtures_returned' => (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(response_count), 0) FROM {$table} WHERE created_at >= %s", $since)),
            'last_remaining' => $wpdb->get_var("SELECT quota_remaining FROM {$table} WHERE quota_remaining IS NOT NULL ORDER BY created_at DESC LIMIT 1"),
            'last_request_at' => $wpdb->get_var("SELECT created_at FROM {$table} ORDER BY created_at DESC LIMIT 1"),
        );
    }

    public static function recent_usage($limit = 20) {
        global $wpdb;

        self::maybe_install();

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::usage_table() . ' ORDER BY created_at DESC LIMIT %d',
            max(1, min(100, (int) $limit))
        ), ARRAY_A);
    }

    private static function empty_payload($message, $mode, $configured, $settings, $extra = array()) {
        $poll_after = (int) ($settings['fixture_cache_ttl'] ?? 300);

        if ('live' === $mode) {
            $poll_after = (int) ($settings['live_cache_ttl'] ?? 60);
        } elseif ('upcoming' === $mode) {
            $poll_after = (int) ($settings['upcoming_cache_ttl'] ?? 300);
        } elseif ('finished' === $mode) {
            $poll_after = (int) ($settings['finished_cache_ttl'] ?? 900);
        }

        return array_merge(array(
            'provider' => $configured ? 'api-football' : 'not-configured',
            'mode' => $mode,
            'configured' => (bool) $configured,
            'updated_at' => gmdate(DATE_ATOM),
            'poll_after' => $poll_after,
            'fixtures' => array(),
            'errors' => array(),
            'message' => $message,
        ), $extra);
    }

    private static function provider_from_host($host) {
        return false !== strpos((string) $host, 'rapidapi.com') ? 'rapidapi' : 'api-sports';
    }

    private static function auth_headers($settings) {
        if ('rapidapi' === ($settings['provider'] ?? '')) {
            return array(
                'x-rapidapi-host' => $settings['host'],
                'x-rapidapi-key' => $settings['api_key'],
            );
        }

        return array(
            'x-apisports-key' => $settings['api_key'],
        );
    }

    private static function request($path, $query = array()) {
        $settings = self::settings();

        if (empty($settings['api_key'])) {
            return new WP_Error('rifnote_api_football_not_configured', __('API-Football key is not configured.', 'rifnote-search'));
        }

        $url = add_query_arg($query, 'https://' . $settings['host'] . $path);
        $started = microtime(true);
        $response = wp_remote_get($url, array(
            'timeout' => 12,
            'headers' => self::auth_headers($settings),
        ));

        if (is_wp_error($response)) {
            self::log_usage(array(
                'provider' => $settings['provider'],
                'host' => $settings['host'],
                'endpoint' => trim($path, '/'),
                'request_path' => $path,
                'query_hash' => hash('sha256', wp_json_encode($query)),
                'query_args' => $query,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'error_message' => $response->get_error_message(),
            ));
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $response_count = is_array($body) && isset($body['results']) ? (int) $body['results'] : count($body['response'] ?? array());
        $error_message = '';

        if ($code < 200 || $code >= 300 || !is_array($body)) {
            $error_message = sprintf(__('API-Football request failed with HTTP %d.', 'rifnote-search'), $code);
        } elseif (!empty($body['errors'])) {
            $error_message = wp_json_encode($body['errors']);
        }

        self::log_usage(array(
            'provider' => $settings['provider'],
            'host' => $settings['host'],
            'endpoint' => trim($path, '/'),
            'request_path' => $path,
            'query_hash' => hash('sha256', wp_json_encode($query)),
            'query_args' => $query,
            'http_status' => $code,
            'response_count' => $response_count,
            'quota_limit' => wp_remote_retrieve_header($response, 'x-ratelimit-requests-limit'),
            'quota_remaining' => wp_remote_retrieve_header($response, 'x-ratelimit-requests-remaining'),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'error_message' => $error_message,
        ));

        if ($code < 200 || $code >= 300 || !is_array($body)) {
            return new WP_Error('rifnote_api_football_request_failed', sprintf(__('API-Football request failed with HTTP %d.', 'rifnote-search'), $code));
        }

        return $body;
    }

    private static function hydrate_fixtures($fixtures) {
        return array_values(array_map(array(__CLASS__, 'hydrate_fixture'), (array) $fixtures));
    }

    private static function hydrate_fixture($fixture, $details = null) {
        if (!is_array($fixture)) {
            return array();
        }

        $round = (string) ($fixture['league']['round'] ?? $fixture['round'] ?? '');
        $fixture['league']['round_clean'] = self::clean_round_label($round);
        $fixture['period_marker'] = self::status_marker($fixture);
        $fixture['markers'] = self::event_marker_rows($fixture, is_array($details) ? $details : null);
        $fixture['leg_label'] = self::leg_label($fixture);
        $fixture['aggregate'] = self::aggregate_context($fixture);

        return $fixture;
    }

    private static function clean_round_label($round) {
        $round = trim(sanitize_text_field((string) $round));

        if ('' === $round || preg_match('/^regular\s+season(?:\s*[-–—]\s*\d+)?$/i', $round)) {
            return '';
        }

        $round = preg_replace('/\s*[-–—]\s*regular\s+season(?:\s*[-–—]\s*\d+)?/i', '', $round);

        return trim((string) $round);
    }

    private static function status_marker($fixture) {
        $status = strtoupper((string) ($fixture['status_short'] ?? ''));

        if ('PEN' === $status || 'P' === $status) {
            return 'PK';
        }

        if (in_array($status, array('HT', 'FT', 'AET'), true)) {
            return $status;
        }

        if (in_array($status, array('ET', 'BT'), true)) {
            return 'ET';
        }

        return '';
    }

    private static function event_marker_rows($fixture, $details = null) {
        $markers = array();
        $timeline = is_array($details) ? ($details['timeline'] ?? array()) : array();

        foreach ((array) $timeline as $event) {
            $type = strtolower((string) ($event['type'] ?? ''));
            $detail = strtolower((string) ($event['detail'] ?? ''));
            $comments = strtolower((string) ($event['comments'] ?? ''));
            $minute = isset($event['elapsed']) ? (int) $event['elapsed'] : null;
            $extra = isset($event['extra']) ? (int) $event['extra'] : null;

            if ('goal' === $type && false === strpos($detail, 'missed')) {
                $goal_type = false !== strpos($detail, 'own') ? 'own-goal' : (false !== strpos($detail, 'penalty') ? 'penalty' : 'normal');
                $markers[] = array(
                    'kind' => 'goal',
                    'label' => 'own-goal' === $goal_type ? 'Own Goal' : ('penalty' === $goal_type ? 'Penalty' : 'Goal'),
                    'goal_type' => $goal_type,
                    'minute' => $minute,
                    'extra' => $extra,
                    'team' => $event['team'] ?? array(),
                    'player' => $event['player'] ?? array(),
                    'red' => true,
                );
                continue;
            }

            if ('var' === $type || false !== strpos($detail . ' ' . $comments, 'var') || false !== strpos($detail . ' ' . $comments, 'cancel')) {
                $markers[] = array(
                    'kind' => 'var',
                    'label' => 'VAR',
                    'minute' => $minute,
                    'extra' => $extra,
                    'team' => $event['team'] ?? array(),
                    'player' => $event['player'] ?? array(),
                    'red' => true,
                );
            }
        }

        $status = self::status_marker($fixture);
        if ($status) {
            $markers[] = array(
                'kind' => 'status',
                'label' => $status,
                'minute' => isset($fixture['elapsed']) ? (int) $fixture['elapsed'] : null,
                'extra' => isset($fixture['extra']) ? (int) $fixture['extra'] : null,
                'red' => true,
            );
        }

        return array_values($markers);
    }

    private static function leg_label($fixture) {
        $round = strtolower((string) ($fixture['league']['round'] ?? $fixture['round'] ?? ''));

        if (preg_match('/\b(1st|first)\s+leg\b/i', $round)) {
            return '1st leg';
        }

        if (preg_match('/\b(2nd|second)\s+leg\b/i', $round)) {
            return '2nd leg';
        }

        return '';
    }

    private static function aggregate_context($fixture) {
        global $wpdb;

        $home_id = (int) ($fixture['home']['id'] ?? 0);
        $away_id = (int) ($fixture['away']['id'] ?? 0);
        $league_id = (int) ($fixture['league']['id'] ?? 0);
        $season = (int) ($fixture['league']['season'] ?? 0);
        $fixture_id = (int) ($fixture['id'] ?? 0);
        $date = !empty($fixture['date']) ? gmdate('Y-m-d H:i:s', strtotime($fixture['date'])) : '';

        if (!$home_id || !$away_id || !$league_id || !$season || !$fixture_id || !$date) {
            return null;
        }

        $round = strtolower((string) ($fixture['league']['round'] ?? ''));
        $leg_hint = self::leg_label($fixture);
        if (!$leg_hint && false === strpos($round, 'qualifying') && false === strpos($round, 'play') && false === strpos($round, 'semi') && false === strpos($round, 'final')) {
            return null;
        }

        $table = self::fixtures_table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT payload FROM {$table}
             WHERE fixture_id <> %d
               AND league_id = %d
               AND league_season = %d
               AND ((home_team_id = %d AND away_team_id = %d) OR (home_team_id = %d AND away_team_id = %d))
               AND fixture_date < %s
               AND fixture_date >= %s
             ORDER BY fixture_date DESC
             LIMIT 1",
            $fixture_id,
            $league_id,
            $season,
            $home_id,
            $away_id,
            $away_id,
            $home_id,
            $date,
            gmdate('Y-m-d H:i:s', strtotime($date . ' -90 days'))
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        $previous = self::decode_json($row['payload']);
        if (!$previous || !isset($fixture['goals']['home'], $fixture['goals']['away'], $previous['goals']['home'], $previous['goals']['away'])) {
            return null;
        }

        $home_total = (int) $fixture['goals']['home'];
        $away_total = (int) $fixture['goals']['away'];

        if ((int) ($previous['home']['id'] ?? 0) === $home_id) {
            $home_total += (int) $previous['goals']['home'];
            $away_total += (int) $previous['goals']['away'];
        } else {
            $home_total += (int) $previous['goals']['away'];
            $away_total += (int) $previous['goals']['home'];
        }

        return array(
            'home' => $home_total,
            'away' => $away_total,
            'label' => sprintf('Agg %d-%d', $home_total, $away_total),
            'previous_fixture_id' => (int) ($previous['id'] ?? 0),
        );
    }

    private static function normalize_standings_payload($response) {
        $league = $response['response'][0]['league'] ?? array();
        $groups = array();

        foreach (($league['standings'] ?? array()) as $group_index => $rows) {
            $group_name = 'Table';

            foreach ((array) $rows as $row) {
                if (!empty($row['group'])) {
                    $group_name = sanitize_text_field($row['group']);
                    break;
                }
            }

            $groups[] = array(
                'name' => $group_name ?: sprintf('Group %d', $group_index + 1),
                'rows' => array_map(array(__CLASS__, 'normalize_standing_row'), (array) $rows),
            );
        }

        return array(
            'provider' => 'api-football',
            'configured' => true,
            'league' => array(
                'id' => (int) ($league['id'] ?? 0),
                'name' => sanitize_text_field($league['name'] ?? ''),
                'country' => sanitize_text_field($league['country'] ?? ''),
                'logo' => esc_url_raw($league['logo'] ?? ''),
                'season' => (int) ($league['season'] ?? 0),
            ),
            'groups' => $groups,
            'updated_at' => gmdate(DATE_ATOM),
            'source' => 'api-football',
        );
    }

    private static function normalize_standing_row($row) {
        $team = $row['team'] ?? array();
        $all = $row['all'] ?? array();

        return array(
            'rank' => (int) ($row['rank'] ?? 0),
            'team' => array(
                'id' => (int) ($team['id'] ?? 0),
                'name' => sanitize_text_field($team['name'] ?? ''),
                'logo' => esc_url_raw($team['logo'] ?? ''),
            ),
            'points' => (int) ($row['points'] ?? 0),
            'goals_diff' => (int) ($row['goalsDiff'] ?? 0),
            'group' => sanitize_text_field($row['group'] ?? ''),
            'form' => sanitize_text_field($row['form'] ?? ''),
            'played' => (int) ($all['played'] ?? 0),
            'win' => (int) ($all['win'] ?? 0),
            'draw' => (int) ($all['draw'] ?? 0),
            'lose' => (int) ($all['lose'] ?? 0),
            'goals_for' => (int) ($all['goals']['for'] ?? 0),
            'goals_against' => (int) ($all['goals']['against'] ?? 0),
        );
    }

    private static function store_standings($payload, $ttl) {
        global $wpdb;

        $league = $payload['league'] ?? array();
        $league_id = (int) ($league['id'] ?? 0);
        $season = (int) ($league['season'] ?? 0);

        if (!$league_id || !$season) {
            return;
        }

        $table = self::standings_table();
        $wpdb->delete($table, array('league_id' => $league_id, 'league_season' => $season));
        $cache_expires_at = gmdate('Y-m-d H:i:s', time() + max(60, (int) $ttl));
        $updated_at = current_time('mysql', true);

        foreach ($payload['groups'] ?? array() as $group) {
            $group_name = sanitize_text_field($group['name'] ?? 'Table');
            foreach ($group['rows'] ?? array() as $row) {
                $team = $row['team'] ?? array();
                $wpdb->insert($table, array(
                    'league_id' => $league_id,
                    'league_name' => sanitize_text_field($league['name'] ?? ''),
                    'league_country' => sanitize_text_field($league['country'] ?? ''),
                    'league_logo' => esc_url_raw($league['logo'] ?? ''),
                    'league_season' => $season,
                    'group_name' => $group_name,
                    'team_id' => (int) ($team['id'] ?? 0),
                    'team_name' => sanitize_text_field($team['name'] ?? ''),
                    'team_logo' => esc_url_raw($team['logo'] ?? ''),
                    'rank_position' => (int) ($row['rank'] ?? 0),
                    'points' => (int) ($row['points'] ?? 0),
                    'goals_diff' => (int) ($row['goals_diff'] ?? 0),
                    'form' => sanitize_text_field($row['form'] ?? ''),
                    'payload' => wp_json_encode($row),
                    'cache_expires_at' => $cache_expires_at,
                    'updated_at' => $updated_at,
                ));
            }
        }
    }

    private static function stored_standings($league, $season) {
        global $wpdb;

        $table = self::standings_table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE league_id = %d AND league_season = %d AND cache_expires_at > %s
             ORDER BY group_name ASC, rank_position ASC",
            (int) $league,
            (int) $season,
            current_time('mysql', true)
        ), ARRAY_A);

        if (!$rows) {
            return null;
        }

        $groups = array();
        $league_meta = array(
            'id' => (int) $rows[0]['league_id'],
            'name' => $rows[0]['league_name'],
            'country' => $rows[0]['league_country'],
            'logo' => $rows[0]['league_logo'],
            'season' => (int) $rows[0]['league_season'],
        );

        foreach ($rows as $row) {
            $group_name = $row['group_name'] ?: 'Table';
            if (!isset($groups[$group_name])) {
                $groups[$group_name] = array('name' => $group_name, 'rows' => array());
            }

            $item = self::decode_json($row['payload']);
            if ($item) {
                $groups[$group_name]['rows'][] = $item;
            }
        }

        return array(
            'provider' => 'api-football',
            'configured' => true,
            'league' => $league_meta,
            'groups' => array_values($groups),
            'updated_at' => mysql_to_rfc3339($rows[0]['updated_at']),
            'source' => 'database',
        );
    }

    private static function normalize_top_scorers_payload($response) {
        $players = array();
        $league_meta = array();

        foreach (($response['response'] ?? array()) as $row) {
            $normalized = self::normalize_top_scorer_row($row);

            if (!$normalized) {
                continue;
            }

            if (empty($league_meta) && !empty($normalized['league'])) {
                $league_meta = $normalized['league'];
            }

            $players[] = $normalized;
        }

        return array(
            'provider' => 'api-football',
            'configured' => true,
            'league' => $league_meta ?: null,
            'players' => $players,
            'updated_at' => gmdate(DATE_ATOM),
            'source' => 'api-football',
        );
    }

    private static function normalize_top_scorer_row($row) {
        $player = $row['player'] ?? array();
        $statistics = $row['statistics'][0] ?? array();
        $team = $statistics['team'] ?? array();
        $league = $statistics['league'] ?? array();
        $games = $statistics['games'] ?? array();
        $goals = $statistics['goals'] ?? array();

        $player_id = (int) ($player['id'] ?? 0);
        $player_name = sanitize_text_field($player['name'] ?? '');

        if (!$player_id && '' === $player_name) {
            return null;
        }

        return array(
            'player' => array(
                'id' => $player_id,
                'name' => $player_name,
                'photo' => esc_url_raw($player['photo'] ?? ''),
            ),
            'team' => array(
                'id' => (int) ($team['id'] ?? 0),
                'name' => sanitize_text_field($team['name'] ?? ''),
                'logo' => esc_url_raw($team['logo'] ?? ''),
            ),
            'league' => array(
                'id' => (int) ($league['id'] ?? 0),
                'name' => sanitize_text_field($league['name'] ?? ''),
                'country' => sanitize_text_field($league['country'] ?? ''),
                'logo' => esc_url_raw($league['logo'] ?? ''),
                'season' => (int) ($league['season'] ?? 0),
            ),
            'goals' => (int) ($goals['total'] ?? 0),
            'assists' => isset($goals['assists']) ? (int) $goals['assists'] : 0,
            'appearances' => isset($games['appearences']) ? (int) $games['appearences'] : (int) ($games['appearances'] ?? 0),
        );
    }

    private static function store_top_scorers($payload, $ttl) {
        global $wpdb;

        $league = $payload['league'] ?? array();
        $league_id = (int) ($league['id'] ?? 0);
        $season = (int) ($league['season'] ?? 0);

        if (!$league_id || !$season) {
            return;
        }

        $table = self::scorers_table();
        $wpdb->delete($table, array('league_id' => $league_id, 'league_season' => $season));
        $cache_expires_at = gmdate('Y-m-d H:i:s', time() + max(60, (int) $ttl));
        $updated_at = current_time('mysql', true);

        foreach ($payload['players'] ?? array() as $row) {
            $player = $row['player'] ?? array();
            $team = $row['team'] ?? array();

            $wpdb->insert($table, array(
                'league_id' => $league_id,
                'league_name' => sanitize_text_field($league['name'] ?? ''),
                'league_country' => sanitize_text_field($league['country'] ?? ''),
                'league_logo' => esc_url_raw($league['logo'] ?? ''),
                'league_season' => $season,
                'player_id' => (int) ($player['id'] ?? 0),
                'player_name' => sanitize_text_field($player['name'] ?? ''),
                'player_photo' => esc_url_raw($player['photo'] ?? ''),
                'team_id' => (int) ($team['id'] ?? 0),
                'team_name' => sanitize_text_field($team['name'] ?? ''),
                'team_logo' => esc_url_raw($team['logo'] ?? ''),
                'goals' => (int) ($row['goals'] ?? 0),
                'assists' => (int) ($row['assists'] ?? 0),
                'appearances' => (int) ($row['appearances'] ?? 0),
                'payload' => wp_json_encode($row),
                'cache_expires_at' => $cache_expires_at,
                'updated_at' => $updated_at,
            ));
        }
    }

    private static function stored_top_scorers($league, $season) {
        global $wpdb;

        $table = self::scorers_table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE league_id = %d AND league_season = %d AND cache_expires_at > %s
             ORDER BY goals DESC, assists DESC, player_name ASC",
            (int) $league,
            (int) $season,
            current_time('mysql', true)
        ), ARRAY_A);

        if (!$rows) {
            return null;
        }

        $players = array();
        foreach ($rows as $row) {
            $item = self::decode_json($row['payload']);
            if ($item) {
                $players[] = $item;
            }
        }

        return array(
            'provider' => 'api-football',
            'configured' => true,
            'league' => array(
                'id' => (int) $rows[0]['league_id'],
                'name' => $rows[0]['league_name'],
                'country' => $rows[0]['league_country'],
                'logo' => $rows[0]['league_logo'],
                'season' => (int) $rows[0]['league_season'],
            ),
            'players' => $players,
            'updated_at' => mysql_to_rfc3339($rows[0]['updated_at']),
            'source' => 'database',
        );
    }

    private static function normalize_fixture($row) {
        $fixture = $row['fixture'] ?? array();
        $league = $row['league'] ?? array();
        $teams = $row['teams'] ?? array();
        $goals = $row['goals'] ?? array();
        $score = $row['score'] ?? array();
        $status = $fixture['status'] ?? array();
        $venue = $fixture['venue'] ?? array();
        $periods = $fixture['periods'] ?? array();

        return array(
            'id' => (int) ($fixture['id'] ?? 0),
            'referee' => sanitize_text_field($fixture['referee'] ?? ''),
            'date' => sanitize_text_field($fixture['date'] ?? ''),
            'timestamp' => (int) ($fixture['timestamp'] ?? 0),
            'timezone' => sanitize_text_field($fixture['timezone'] ?? ''),
            'status_short' => sanitize_text_field($status['short'] ?? ''),
            'status_long' => sanitize_text_field($status['long'] ?? ''),
            'elapsed' => isset($status['elapsed']) ? (int) $status['elapsed'] : null,
            'extra' => isset($status['extra']) ? (int) $status['extra'] : null,
            'periods' => array(
                'first' => isset($periods['first']) ? (int) $periods['first'] : null,
                'second' => isset($periods['second']) ? (int) $periods['second'] : null,
            ),
            'venue' => array(
                'id' => (int) ($venue['id'] ?? 0),
                'name' => sanitize_text_field($venue['name'] ?? ''),
                'city' => sanitize_text_field($venue['city'] ?? ''),
            ),
            'league' => array(
                'id' => (int) ($league['id'] ?? 0),
                'name' => sanitize_text_field($league['name'] ?? ''),
                'country' => sanitize_text_field($league['country'] ?? ''),
                'logo' => esc_url_raw($league['logo'] ?? ''),
                'round' => sanitize_text_field($league['round'] ?? ''),
                'round_clean' => self::clean_round_label($league['round'] ?? ''),
                'season' => (int) ($league['season'] ?? 0),
            ),
            'home' => array(
                'id' => (int) ($teams['home']['id'] ?? 0),
                'name' => sanitize_text_field($teams['home']['name'] ?? ''),
                'code' => sanitize_text_field($teams['home']['code'] ?? ''),
                'logo' => esc_url_raw($teams['home']['logo'] ?? ''),
                'winner' => isset($teams['home']['winner']) ? (bool) $teams['home']['winner'] : null,
            ),
            'away' => array(
                'id' => (int) ($teams['away']['id'] ?? 0),
                'name' => sanitize_text_field($teams['away']['name'] ?? ''),
                'code' => sanitize_text_field($teams['away']['code'] ?? ''),
                'logo' => esc_url_raw($teams['away']['logo'] ?? ''),
                'winner' => isset($teams['away']['winner']) ? (bool) $teams['away']['winner'] : null,
            ),
            'goals' => array(
                'home' => isset($goals['home']) ? (int) $goals['home'] : null,
                'away' => isset($goals['away']) ? (int) $goals['away'] : null,
            ),
            'score' => array(
                'halftime' => $score['halftime'] ?? array(),
                'fulltime' => $score['fulltime'] ?? array(),
                'extratime' => $score['extratime'] ?? array(),
                'penalty' => $score['penalty'] ?? array(),
            ),
        );
    }

    private static function normalize_event($row) {
        $time = $row['time'] ?? array();
        $team = $row['team'] ?? array();
        $player = $row['player'] ?? array();
        $assist = $row['assist'] ?? array();

        return array(
            'elapsed' => isset($time['elapsed']) ? (int) $time['elapsed'] : null,
            'extra' => isset($time['extra']) ? (int) $time['extra'] : null,
            'team' => array(
                'id' => (int) ($team['id'] ?? 0),
                'name' => sanitize_text_field($team['name'] ?? ''),
                'logo' => esc_url_raw($team['logo'] ?? ''),
            ),
            'player' => array(
                'id' => (int) ($player['id'] ?? 0),
                'name' => sanitize_text_field($player['name'] ?? ''),
            ),
            'assist' => array(
                'id' => (int) ($assist['id'] ?? 0),
                'name' => sanitize_text_field($assist['name'] ?? ''),
            ),
            'type' => sanitize_text_field($row['type'] ?? ''),
            'detail' => sanitize_text_field($row['detail'] ?? ''),
            'comments' => sanitize_text_field($row['comments'] ?? ''),
        );
    }

    private static function normalize_statistics($row) {
        $team = $row['team'] ?? array();
        $statistics = array();

        foreach ($row['statistics'] ?? array() as $stat) {
            $statistics[] = array(
                'type' => sanitize_text_field($stat['type'] ?? ''),
                'value' => is_scalar($stat['value'] ?? null) ? sanitize_text_field((string) $stat['value']) : '',
            );
        }

        return array(
            'team' => array(
                'id' => (int) ($team['id'] ?? 0),
                'name' => sanitize_text_field($team['name'] ?? ''),
                'logo' => esc_url_raw($team['logo'] ?? ''),
            ),
            'statistics' => $statistics,
        );
    }

    private static function normalize_lineup($row) {
        $team = $row['team'] ?? array();

        return array(
            'team' => array(
                'id' => (int) ($team['id'] ?? 0),
                'name' => sanitize_text_field($team['name'] ?? ''),
                'logo' => esc_url_raw($team['logo'] ?? ''),
                'colors' => $team['colors'] ?? array(),
            ),
            'formation' => sanitize_text_field($row['formation'] ?? ''),
            'coach' => array(
                'id' => (int) ($row['coach']['id'] ?? 0),
                'name' => sanitize_text_field($row['coach']['name'] ?? ''),
                'photo' => esc_url_raw($row['coach']['photo'] ?? ''),
            ),
            'startXI' => self::normalize_lineup_players($row['startXI'] ?? array()),
            'substitutes' => self::normalize_lineup_players($row['substitutes'] ?? array()),
        );
    }

    private static function normalize_lineup_players($rows) {
        $players = array();

        foreach ($rows as $row) {
            $player = $row['player'] ?? array();
            $players[] = array(
                'id' => (int) ($player['id'] ?? 0),
                'name' => sanitize_text_field($player['name'] ?? ''),
                'number' => isset($player['number']) ? (int) $player['number'] : null,
                'pos' => sanitize_text_field($player['pos'] ?? ''),
                'grid' => sanitize_text_field($player['grid'] ?? ''),
            );
        }

        return $players;
    }

    private static function decode_json($json) {
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : array();
    }

    private static function fallback_payload($message = '', $mode = 'live') {
        return array(
            'provider' => 'not-configured',
            'mode' => $mode,
            'configured' => false,
            'updated_at' => gmdate(DATE_ATOM),
            'poll_after' => 30,
            'message' => $message,
            'fixtures' => array(),
        );
    }
}
