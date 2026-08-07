<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Live_Data {
    const VERSION = '1.1.0';
    const DEFAULT_TTL = 900;

    public static function table() {
        global $wpdb;

        return $wpdb->prefix . 'rifnote_live_data_cache';
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $table = self::table();

        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            cache_key varchar(80) NOT NULL,
            data_type varchar(30) NOT NULL,
            payload longtext NOT NULL,
            expires_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY cache_key (cache_key),
            KEY data_type (data_type),
            KEY expires_at (expires_at)
        ) {$charset_collate};");

        update_option('rifnote_live_data_db_version', self::VERSION, false);
    }

    public static function maybe_install() {
        if (get_option('rifnote_live_data_db_version') !== self::VERSION) {
            self::install();
        }
    }

    public static function settings() {
        $ttl = absint(get_option('rifnote_live_data_poll_ttl', self::DEFAULT_TTL));

        return array(
            'ttl' => max(300, min(1800, $ttl ? $ttl : self::DEFAULT_TTL)),
            'weather_locations' => self::ensure_weather_locations(self::parse_weather_locations(get_option('rifnote_live_weather_locations', self::default_weather_locations()))),
            'market_pairs' => self::parse_market_pairs(get_option('rifnote_live_market_pairs', self::default_market_pairs())),
        );
    }

    public static function weather_payload($force = false, $visitor_location = null) {
        $settings = self::settings();
        $visitor_location = self::sanitize_visitor_location($visitor_location);
        $cache_key = $visitor_location ? self::visitor_weather_cache_key($visitor_location) : 'weather';
        $cached = self::cached_payload($cache_key, false);

        if (!$force && $cached && !empty($cached['items'])) {
            return $cached;
        }

        $stale = self::cached_payload($cache_key, true);
        $items = array();
        $errors = array();
        $locations = $settings['weather_locations'];

        if ($visitor_location) {
            array_unshift($locations, $visitor_location);
        }

        $items = self::weather_items_for_locations($locations, $errors);

        if (!$items && $stale && !empty($stale['items'])) {
            $stale['stale'] = true;
            $stale['errors'] = $errors;
            return $stale;
        }

        $payload = array(
            'provider' => 'open-meteo',
            'source_label' => 'Open-Meteo',
            'configured' => true,
            'updated_at' => gmdate('c'),
            'poll_after' => $settings['ttl'],
            'items' => $items,
            'errors' => $errors,
        );

        self::store_payload($cache_key, 'weather', $payload, $settings['ttl']);

        return $payload;
    }

    public static function world_weather_payload($force = false) {
        $settings = self::settings();
        $cache_key = 'weather_world';
        $cached = self::cached_payload($cache_key, false);

        if (!$force && $cached && !empty($cached['items'])) {
            return $cached;
        }

        $stale = self::cached_payload($cache_key, true);
        $errors = array();
        $items = self::weather_items_for_locations(self::world_weather_locations(), $errors);

        if (!$items && $stale && !empty($stale['items'])) {
            $stale['stale'] = true;
            $stale['errors'] = $errors;
            return $stale;
        }

        $payload = array(
            'provider' => 'open-meteo',
            'source_label' => 'Open-Meteo',
            'configured' => true,
            'updated_at' => gmdate('c'),
            'poll_after' => $settings['ttl'],
            'items' => $items,
            'errors' => $errors,
        );

        self::store_payload($cache_key, 'weather', $payload, $settings['ttl']);

        return $payload;
    }

    public static function markets_payload($force = false) {
        $settings = self::settings();
        $cached = self::cached_payload('markets', false);

        if (!$force && $cached) {
            return $cached;
        }

        $stale = self::cached_payload('markets', true);
        $previous = self::items_by_label($stale);
        $items = array();
        $errors = array();

        foreach ($settings['market_pairs'] as $pair) {
            $rate = null;
            $source = 'frankfurter';
            $as_of = '';
            $error = '';
            $url = add_query_arg(array(
                'base' => $pair['base'],
                'symbols' => $pair['symbol'],
            ), 'https://api.frankfurter.dev/v1/latest');

            $response = wp_remote_get($url, array('timeout' => 8));

            if (is_wp_error($response)) {
                $error = $response->get_error_message();
            } else {
                $code = wp_remote_retrieve_response_code($response);
                $body = json_decode((string) wp_remote_retrieve_body($response), true);

                if ($code >= 200 && $code < 300 && is_array($body) && isset($body['rates'][$pair['symbol']])) {
                    $rate = (float) $body['rates'][$pair['symbol']];
                    $as_of = isset($body['date']) ? sanitize_text_field($body['date']) : '';
                } else {
                    $error = 'Frankfurter returned ' . $code;
                }
            }

            if (null === $rate) {
                $fallback_url = 'https://open.er-api.com/v6/latest/' . rawurlencode($pair['base']);
                $fallback = wp_remote_get($fallback_url, array('timeout' => 8));

                if (is_wp_error($fallback)) {
                    $errors[] = $pair['label'] . ': ' . $error . '; ExchangeRate fallback ' . $fallback->get_error_message();
                    continue;
                }

                $fallback_code = wp_remote_retrieve_response_code($fallback);
                $fallback_body = json_decode((string) wp_remote_retrieve_body($fallback), true);

                if ($fallback_code < 200 || $fallback_code >= 300 || !is_array($fallback_body) || empty($fallback_body['rates'][$pair['symbol']])) {
                    $errors[] = $pair['label'] . ': ' . $error . '; ExchangeRate fallback returned ' . $fallback_code;
                    continue;
                }

                $rate = (float) $fallback_body['rates'][$pair['symbol']];
                $source = 'exchange-rate-api';
                $as_of = isset($fallback_body['time_last_update_utc']) ? sanitize_text_field($fallback_body['time_last_update_utc']) : '';
            }

            $history = self::market_history($pair);

            $items[] = array(
                'label' => $pair['label'],
                'value' => self::format_market_value($rate),
                'status' => self::market_delta_label($rate, isset($previous[$pair['label']]) ? $previous[$pair['label']] : null),
                'raw_value' => $rate,
                'history' => $history,
                'base' => $pair['base'],
                'symbol' => $pair['symbol'],
                'provider' => $source,
                'as_of' => $as_of,
            );
        }

        if (!$items && $stale) {
            $stale['stale'] = true;
            $stale['errors'] = $errors;
            return $stale;
        }

        $payload = array(
            'provider' => 'frankfurter',
            'source_label' => 'FX Rates',
            'configured' => true,
            'updated_at' => gmdate('c'),
            'poll_after' => $settings['ttl'],
            'items' => $items,
            'errors' => $errors,
        );

        self::store_payload('markets', 'market', $payload, $settings['ttl']);

        return $payload;
    }

    private static function cached_payload($key, $allow_stale = false) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT payload, expires_at FROM ' . self::table() . ' WHERE cache_key = %s LIMIT 1', $key), ARRAY_A);

        if (!$row || empty($row['payload'])) {
            return null;
        }

        if (!$allow_stale && strtotime($row['expires_at'] . ' UTC') < time()) {
            return null;
        }

        $payload = json_decode((string) $row['payload'], true);

        return is_array($payload) ? $payload : null;
    }

    private static function store_payload($key, $type, $payload, $ttl) {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + max(300, (int) $ttl));

        $wpdb->replace(self::table(), array(
            'cache_key' => $key,
            'data_type' => $type,
            'payload' => wp_json_encode($payload),
            'expires_at' => $expires,
            'updated_at' => $now,
        ), array('%s', '%s', '%s', '%s', '%s'));
    }

    private static function parse_weather_locations($raw) {
        $locations = array();
        $lines = preg_split('/\r\n|\r|\n/', (string) $raw);

        foreach ($lines as $line) {
            $parts = array_map('trim', explode(':', $line));

            if (count($parts) < 3 || '' === $parts[0] || !is_numeric($parts[1]) || !is_numeric($parts[2])) {
                continue;
            }

            $locations[] = array(
                'label' => sanitize_text_field($parts[0]),
                'latitude' => (float) $parts[1],
                'longitude' => (float) $parts[2],
            );
        }

        return $locations ? $locations : self::parse_weather_locations(self::default_weather_locations());
    }

    private static function ensure_weather_locations($locations) {
        $has_new_york = false;

        foreach ($locations as $location) {
            if (false !== stripos((string) $location['label'], 'new york')) {
                $has_new_york = true;
                break;
            }
        }

        if (!$has_new_york) {
            $locations[] = array(
                'label' => 'New York',
                'latitude' => 40.7128,
                'longitude' => -74.0060,
            );
        }

        return $locations;
    }

    private static function parse_market_pairs($raw) {
        $pairs = array();
        $lines = preg_split('/\r\n|\r|\n/', (string) $raw);

        foreach ($lines as $line) {
            $parts = array_map('trim', explode(':', $line));

            if (count($parts) < 3 || '' === $parts[0] || '' === $parts[1] || '' === $parts[2]) {
                continue;
            }

            $pairs[] = array(
                'label' => sanitize_text_field($parts[0]),
                'base' => strtoupper(sanitize_key($parts[1])),
                'symbol' => strtoupper(sanitize_key($parts[2])),
            );
        }

        return $pairs ? $pairs : self::parse_market_pairs(self::default_market_pairs());
    }

    private static function default_weather_locations() {
        return "Lagos:6.5244:3.3792\nAbuja:9.0765:7.3986\nLondon:51.5072:-0.1276\nNew York:40.7128:-74.0060";
    }

    private static function default_market_pairs() {
        return "NGN/USD:USD:NGN\nEUR/USD:EUR:USD\nGBP/USD:GBP:USD";
    }

    private static function items_by_label($payload) {
        $items = array();

        if (empty($payload['items']) || !is_array($payload['items'])) {
            return $items;
        }

        foreach ($payload['items'] as $item) {
            if (isset($item['label'], $item['raw_value'])) {
                $items[$item['label']] = (float) $item['raw_value'];
            }
        }

        return $items;
    }

    private static function sanitize_visitor_location($location) {
        if (!is_array($location) || !isset($location['latitude'], $location['longitude'])) {
            return null;
        }

        $latitude = round((float) $location['latitude'], 2);
        $longitude = round((float) $location['longitude'], 2);

        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return null;
        }

        return array(
            'label' => sanitize_text_field((string) ($location['label'] ?? __('Near you', 'rifnote-search'))),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'is_visitor_location' => true,
        );
    }

    private static function visitor_weather_cache_key($location) {
        return 'weather_' . md5($location['latitude'] . ':' . $location['longitude']);
    }

    private static function weather_items_for_locations($locations, &$errors) {
        $locations = array_values(array_filter($locations, function($location) {
            return isset($location['label'], $location['latitude'], $location['longitude']);
        }));

        if (!$locations) {
            return array();
        }

        $url = add_query_arg(array(
            'latitude' => implode(',', array_map(function($location) {
                return $location['latitude'];
            }, $locations)),
            'longitude' => implode(',', array_map(function($location) {
                return $location['longitude'];
            }, $locations)),
            'current' => 'temperature_2m,relative_humidity_2m,weather_code,wind_speed_10m',
            'timezone' => 'auto',
        ), 'https://api.open-meteo.com/v1/forecast');
        $response = wp_remote_get($url, array('timeout' => 10));

        if (is_wp_error($response)) {
            $errors[] = $response->get_error_message();
            return array();
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || !is_array($body)) {
            $errors[] = 'Open-Meteo returned ' . $code;
            return array();
        }

        if (self::is_list_array($body)) {
            $items = self::weather_items_from_response_list($locations, $body);

            if ($items) {
                return $items;
            }
        }

        if (empty($body['current']) || !is_array($body['current'])) {
            $errors[] = 'Open-Meteo returned ' . $code . ' without current weather';
            return array();
        }

        $current = $body['current'];
        return self::weather_items_from_current($locations, $current);
    }

    private static function weather_items_from_response_list($locations, $responses) {
        $items = array();

        foreach ($locations as $index => $location) {
            if (empty($responses[$index]['current']) || !is_array($responses[$index]['current'])) {
                continue;
            }

            $current_items = self::weather_items_from_current(array($location), $responses[$index]['current']);

            if ($current_items) {
                $items[] = $current_items[0];
            }
        }

        return $items;
    }

    private static function weather_items_from_current($locations, $current) {
        $temperatures = isset($current['temperature_2m']) ? $current['temperature_2m'] : null;
        $humidities = isset($current['relative_humidity_2m']) ? $current['relative_humidity_2m'] : null;
        $codes = isset($current['weather_code']) ? $current['weather_code'] : null;
        $winds = isset($current['wind_speed_10m']) ? $current['wind_speed_10m'] : null;
        $times = isset($current['time']) ? $current['time'] : '';
        $is_multi = is_array($temperatures);
        $items = array();

        foreach ($locations as $index => $location) {
            $weather_code = $is_multi && isset($codes[$index]) ? (int) $codes[$index] : (is_array($codes) ? 0 : (int) $codes);
            $temperature = $is_multi && isset($temperatures[$index]) ? $temperatures[$index] : $temperatures;
            $humidity = $is_multi && isset($humidities[$index]) ? $humidities[$index] : $humidities;
            $wind = $is_multi && isset($winds[$index]) ? $winds[$index] : $winds;
            $time = $is_multi && isset($times[$index]) ? $times[$index] : $times;

            $items[] = array(
                'label' => $location['label'],
                'value' => self::format_temperature($temperature),
                'status' => self::weather_code_label($weather_code),
                'icon' => self::weather_icon($weather_code),
                'humidity' => is_numeric($humidity) ? (int) $humidity : null,
                'wind_speed' => is_numeric($wind) ? round((float) $wind, 1) : null,
                'observed_at' => $time ? sanitize_text_field((string) $time) : '',
                'is_visitor_location' => !empty($location['is_visitor_location']),
            );
        }

        return $items;
    }

    private static function is_list_array($value) {
        if (!is_array($value)) {
            return false;
        }

        $expected = 0;

        foreach (array_keys($value) as $key) {
            if ($key !== $expected) {
                return false;
            }

            $expected++;
        }

        return true;
    }

    private static function world_weather_locations() {
        return array(
            array('label' => 'Lagos', 'latitude' => 6.5244, 'longitude' => 3.3792),
            array('label' => 'Abuja', 'latitude' => 9.0765, 'longitude' => 7.3986),
            array('label' => 'Accra', 'latitude' => 5.6037, 'longitude' => -0.1870),
            array('label' => 'Nairobi', 'latitude' => -1.2921, 'longitude' => 36.8219),
            array('label' => 'Cairo', 'latitude' => 30.0444, 'longitude' => 31.2357),
            array('label' => 'Johannesburg', 'latitude' => -26.2041, 'longitude' => 28.0473),
            array('label' => 'Dakar', 'latitude' => 14.7167, 'longitude' => -17.4677),
            array('label' => 'London', 'latitude' => 51.5072, 'longitude' => -0.1276),
            array('label' => 'Paris', 'latitude' => 48.8566, 'longitude' => 2.3522),
            array('label' => 'Berlin', 'latitude' => 52.5200, 'longitude' => 13.4050),
            array('label' => 'Madrid', 'latitude' => 40.4168, 'longitude' => -3.7038),
            array('label' => 'Rome', 'latitude' => 41.9028, 'longitude' => 12.4964),
            array('label' => 'New York', 'latitude' => 40.7128, 'longitude' => -74.0060),
            array('label' => 'Los Angeles', 'latitude' => 34.0522, 'longitude' => -118.2437),
            array('label' => 'Toronto', 'latitude' => 43.6532, 'longitude' => -79.3832),
            array('label' => 'Mexico City', 'latitude' => 19.4326, 'longitude' => -99.1332),
            array('label' => 'Sao Paulo', 'latitude' => -23.5558, 'longitude' => -46.6396),
            array('label' => 'Buenos Aires', 'latitude' => -34.6037, 'longitude' => -58.3816),
            array('label' => 'Dubai', 'latitude' => 25.2048, 'longitude' => 55.2708),
            array('label' => 'Riyadh', 'latitude' => 24.7136, 'longitude' => 46.6753),
            array('label' => 'Mumbai', 'latitude' => 19.0760, 'longitude' => 72.8777),
            array('label' => 'Delhi', 'latitude' => 28.6139, 'longitude' => 77.2090),
            array('label' => 'Singapore', 'latitude' => 1.3521, 'longitude' => 103.8198),
            array('label' => 'Tokyo', 'latitude' => 35.6762, 'longitude' => 139.6503),
            array('label' => 'Seoul', 'latitude' => 37.5665, 'longitude' => 126.9780),
            array('label' => 'Beijing', 'latitude' => 39.9042, 'longitude' => 116.4074),
            array('label' => 'Shanghai', 'latitude' => 31.2304, 'longitude' => 121.4737),
            array('label' => 'Sydney', 'latitude' => -33.8688, 'longitude' => 151.2093),
            array('label' => 'Melbourne', 'latitude' => -37.8136, 'longitude' => 144.9631),
            array('label' => 'Istanbul', 'latitude' => 41.0082, 'longitude' => 28.9784),
        );
    }

    private static function market_history($pair) {
        $end = gmdate('Y-m-d');
        $start = gmdate('Y-m-d', time() - (29 * DAY_IN_SECONDS));
        $url = add_query_arg(array(
            'base' => $pair['base'],
            'symbols' => $pair['symbol'],
        ), 'https://api.frankfurter.dev/v1/' . rawurlencode($start) . '..' . rawurlencode($end));
        $response = wp_remote_get($url, array('timeout' => 8));

        if (is_wp_error($response)) {
            return array();
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || !is_array($body) || empty($body['rates']) || !is_array($body['rates'])) {
            return array();
        }

        ksort($body['rates']);
        $history = array();

        foreach ($body['rates'] as $date => $rates) {
            if (!isset($rates[$pair['symbol']]) || !is_numeric($rates[$pair['symbol']])) {
                continue;
            }

            $history[] = array(
                'date' => sanitize_text_field((string) $date),
                'value' => (float) $rates[$pair['symbol']],
            );
        }

        return array_slice($history, -30);
    }

    private static function format_temperature($value) {
        if (!is_numeric($value)) {
            return '-';
        }

        return round((float) $value) . '°C';
    }

    private static function format_market_value($value) {
        $value = (float) $value;

        return number_format($value, $value >= 100 ? 2 : 4);
    }

    private static function market_delta_label($current, $previous) {
        if (!$previous || $previous <= 0) {
            return 'FX';
        }

        $delta = (($current - $previous) / $previous) * 100;

        if (abs($delta) < 0.01) {
            return 'Flat';
        }

        return ($delta > 0 ? '+' : '') . number_format($delta, 2) . '%';
    }

    private static function weather_code_label($code) {
        if (0 === $code) {
            return 'Clear';
        }

        if (in_array($code, array(1, 2), true)) {
            return 'Partly cloudy';
        }

        if (3 === $code) {
            return 'Cloudy';
        }

        if (in_array($code, array(45, 48), true)) {
            return 'Fog';
        }

        if (($code >= 51 && $code <= 67) || ($code >= 80 && $code <= 82)) {
            return 'Rain';
        }

        if (($code >= 71 && $code <= 77) || ($code >= 85 && $code <= 86)) {
            return 'Snow';
        }

        if ($code >= 95) {
            return 'Storm';
        }

        return 'Weather';
    }

    private static function weather_icon($code) {
        if (($code >= 51 && $code <= 67) || ($code >= 80 && $code <= 82) || $code >= 95) {
            return 'rain';
        }

        if (in_array($code, array(2, 3, 45, 48), true)) {
            return 'cloud';
        }

        return 'sun';
    }
}
