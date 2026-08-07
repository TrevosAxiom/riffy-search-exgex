<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Launch_Readiness {
    public static function claims_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_claims';
    }

    public static function sponsored_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_sponsored_placements';
    }

    public static function suspicious_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_suspicious_activity';
    }

    public static function sponsor_requests_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_sponsor_requests';
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $claims = self::claims_table();
        $sponsored = self::sponsored_table();
        $suspicious = self::suspicious_table();
        $sponsor_requests = self::sponsor_requests_table();

        dbDelta("CREATE TABLE {$claims} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NULL,
            cluster_id VARCHAR(190) NULL,
            claim_text TEXT NOT NULL,
            claimant VARCHAR(190) NULL,
            rating VARCHAR(100) NULL,
            review_summary TEXT NULL,
            review_url TEXT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY cluster_id (cluster_id),
            KEY status (status)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$sponsored} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(190) NOT NULL,
            target_url TEXT NOT NULL,
            sponsor_name VARCHAR(190) NOT NULL,
            campaign_request_id BIGINT UNSIGNED NULL,
            placement VARCHAR(80) NOT NULL DEFAULT 'search',
            category VARCHAR(100) NULL,
            query_match VARCHAR(190) NULL,
            priority INT DEFAULT 50,
            targeting_payload LONGTEXT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'active',
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            impressions BIGINT UNSIGNED NOT NULL DEFAULT 0,
            clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY campaign_request_id (campaign_request_id),
            KEY placement (placement),
            KEY category (category),
            KEY status (status)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$suspicious} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(80) NOT NULL,
            ip_hash VARCHAR(64) NULL,
            user_id BIGINT UNSIGNED NULL,
            message TEXT NOT NULL,
            metadata LONGTEXT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'new',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY event_type (event_type),
            KEY ip_hash (ip_hash),
            KEY status (status)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$sponsor_requests} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sponsor_name VARCHAR(190) NOT NULL,
            contact_email VARCHAR(190) NOT NULL,
            target_url TEXT NOT NULL,
            campaign_title VARCHAR(190) NOT NULL,
            budget DECIMAL(12,2) DEFAULT 0,
            category VARCHAR(100) NULL,
            query_match VARCHAR(190) NULL,
            objective VARCHAR(80) NULL,
            placement_summary TEXT NULL,
            estimated_price DECIMAL(12,2) DEFAULT 0,
            campaign_payload LONGTEXT NULL,
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'new',
            checkout_url TEXT NULL,
            payment_reference VARCHAR(190) NULL,
            payment_amount DECIMAL(12,2) DEFAULT 0,
            payment_note TEXT NULL,
            payment_payload LONGTEXT NULL,
            paid_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY contact_email (contact_email)
        ) {$charset_collate};");

        update_option('rifnote_search_launch_readiness_db_version', RIFNOTE_SEARCH_VERSION);
    }

    public static function maybe_install() {
        global $wpdb;

        $table = self::claims_table();
        $sponsor_requests = self::sponsor_requests_table();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $sponsor_requests_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $sponsor_requests));

        if (get_option('rifnote_search_launch_readiness_db_version') !== RIFNOTE_SEARCH_VERSION || $exists !== $table || $sponsor_requests_exists !== $sponsor_requests) {
            self::install();
        }

        self::ensure_sponsor_request_columns();
        self::ensure_sponsored_columns();
    }

    private static function ensure_sponsor_request_columns() {
        global $wpdb;

        $table = self::sponsor_requests_table();
        $columns = $wpdb->get_col("DESC {$table}", 0);

        if (!is_array($columns)) {
            return;
        }

        $defs = array(
            'objective' => "ALTER TABLE {$table} ADD objective VARCHAR(80) NULL AFTER query_match",
            'placement_summary' => "ALTER TABLE {$table} ADD placement_summary TEXT NULL AFTER objective",
            'estimated_price' => "ALTER TABLE {$table} ADD estimated_price DECIMAL(12,2) DEFAULT 0 AFTER placement_summary",
            'campaign_payload' => "ALTER TABLE {$table} ADD campaign_payload LONGTEXT NULL AFTER estimated_price",
            'payment_reference' => "ALTER TABLE {$table} ADD payment_reference VARCHAR(190) NULL AFTER checkout_url",
            'payment_amount' => "ALTER TABLE {$table} ADD payment_amount DECIMAL(12,2) DEFAULT 0 AFTER payment_reference",
            'payment_note' => "ALTER TABLE {$table} ADD payment_note TEXT NULL AFTER payment_amount",
            'payment_payload' => "ALTER TABLE {$table} ADD payment_payload LONGTEXT NULL AFTER payment_note",
            'paid_at' => "ALTER TABLE {$table} ADD paid_at DATETIME NULL AFTER payment_payload",
        );

        foreach ($defs as $column => $sql) {
            if (!in_array($column, $columns, true)) {
                $wpdb->query($sql);
            }
        }
    }

    private static function ensure_sponsored_columns() {
        global $wpdb;

        $table = self::sponsored_table();
        $columns = $wpdb->get_col("DESC {$table}", 0);

        if (!is_array($columns)) {
            return;
        }

        $defs = array(
            'campaign_request_id' => "ALTER TABLE {$table} ADD campaign_request_id BIGINT UNSIGNED NULL AFTER sponsor_name",
            'priority' => "ALTER TABLE {$table} ADD priority INT DEFAULT 50 AFTER query_match",
            'targeting_payload' => "ALTER TABLE {$table} ADD targeting_payload LONGTEXT NULL AFTER priority",
        );

        foreach ($defs as $column => $sql) {
            if (!in_array($column, $columns, true)) {
                $wpdb->query($sql);
            }
        }
    }

    public static function ad_inventory() {
        return array(
            'currency' => 'NGN',
            'placements' => array(
                array('id' => 'home_top_headline', 'name' => __('Homepage headline takeover', 'rifnote-search'), 'area' => __('Homepage', 'rifnote-search'), 'price' => 35000, 'unit' => 'day', 'impressions' => 18000, 'description' => __('Prime slot beside the stories people open first.', 'rifnote-search')),
                array('id' => 'search_top_intent', 'name' => __('Search top intent card', 'rifnote-search'), 'area' => __('Search', 'rifnote-search'), 'price' => 25000, 'unit' => 'day', 'impressions' => 14000, 'description' => __('Catch users when they are actively looking for a topic.', 'rifnote-search')),
                array('id' => 'search_inline_result', 'name' => __('Inline search result', 'rifnote-search'), 'area' => __('Search', 'rifnote-search'), 'price' => 12500, 'unit' => 'day', 'impressions' => 9000, 'description' => __('A sponsored result inside the natural discovery flow.', 'rifnote-search')),
                array('id' => 'live_updates_sidebar', 'name' => __('Live Updates sidebar', 'rifnote-search'), 'area' => __('Sitewide', 'rifnote-search'), 'price' => 18000, 'unit' => 'day', 'impressions' => 15000, 'description' => __('Sticky desktop and mobile live feed visibility.', 'rifnote-search')),
                array('id' => 'football_matchday_board', 'name' => __('Football matchday board', 'rifnote-search'), 'area' => __('Football', 'rifnote-search'), 'price' => 30000, 'unit' => 'day', 'impressions' => 16000, 'description' => __('High-energy placement around fixtures, scores and match rooms.', 'rifnote-search')),
                array('id' => 'story_cluster_sidebar', 'name' => __('Story hub sidebar', 'rifnote-search'), 'area' => __('Full Coverage', 'rifnote-search'), 'price' => 20000, 'unit' => 'day', 'impressions' => 11000, 'description' => __('Appears beside aggregation pages when readers go deeper.', 'rifnote-search')),
                array('id' => 'trending_topics_card', 'name' => __('Trending topics card', 'rifnote-search'), 'area' => __('Trends', 'rifnote-search'), 'price' => 15000, 'unit' => 'day', 'impressions' => 10000, 'description' => __('Show up where the current conversation is moving.', 'rifnote-search')),
                array('id' => 'mobile_bottom_bar', 'name' => __('Mobile bottom-bar pulse', 'rifnote-search'), 'area' => __('Mobile', 'rifnote-search'), 'price' => 22000, 'unit' => 'day', 'impressions' => 13000, 'description' => __('Compact mobile-native promotion close to navigation.', 'rifnote-search')),
                array('id' => 'daily_drop_newsletter', 'name' => __('Daily Drop newsletter slot', 'rifnote-search'), 'area' => __('Email', 'rifnote-search'), 'price' => 50000, 'unit' => 'send', 'impressions' => 7000, 'description' => __('One sponsored feature inside the newsletter send.', 'rifnote-search')),
            ),
            'objectives' => array(
                array('id' => 'awareness', 'name' => __('Reach / awareness', 'rifnote-search'), 'multiplier' => 1),
                array('id' => 'traffic', 'name' => __('Website visits', 'rifnote-search'), 'multiplier' => 1.15),
                array('id' => 'leads', 'name' => __('Leads / signups', 'rifnote-search'), 'multiplier' => 1.35),
                array('id' => 'matchday', 'name' => __('Matchday takeover', 'rifnote-search'), 'multiplier' => 1.45),
            ),
        );
    }

    public static function estimate_ad_campaign($data) {
        $inventory = self::ad_inventory();
        $placements = isset($data['placements']) && is_array($data['placements']) ? array_map('sanitize_key', $data['placements']) : array();
        $objective = sanitize_key($data['objective'] ?? 'awareness');
        $start = $data['starts_at'] ?? ($data['start_date'] ?? '');
        $end = $data['ends_at'] ?? ($data['end_date'] ?? '');
        $days = 1;

        if ($start && $end) {
            $start_ts = strtotime($start);
            $end_ts = strtotime($end);
            if ($start_ts && $end_ts && $end_ts >= $start_ts) {
                $days = max(1, (int) ceil(($end_ts - $start_ts) / DAY_IN_SECONDS) + 1);
            }
        }

        $objective_multiplier = 1;
        foreach ($inventory['objectives'] as $item) {
            if ($item['id'] === $objective) {
                $objective_multiplier = (float) $item['multiplier'];
                break;
            }
        }

        $selected = array();
        $daily = 0;
        $impressions = 0;

        foreach ($inventory['placements'] as $placement) {
            if (in_array($placement['id'], $placements, true)) {
                $selected[] = $placement;
                $daily += (float) $placement['price'];
                $impressions += (int) $placement['impressions'];
            }
        }

        if (!$selected) {
            $selected[] = $inventory['placements'][1];
            $daily = (float) $inventory['placements'][1]['price'];
            $impressions = (int) $inventory['placements'][1]['impressions'];
        }

        $total = round($daily * $days * $objective_multiplier, 2);

        return array(
            'currency' => 'NGN',
            'days' => $days,
            'daily_base' => $daily,
            'objective' => $objective,
            'objective_multiplier' => $objective_multiplier,
            'selected_placements' => $selected,
            'estimated_impressions' => $impressions * $days,
            'estimated_price' => $total,
            'placement_summary' => implode(', ', wp_list_pluck($selected, 'name')),
        );
    }

    public static function add_claim($data) {
        global $wpdb;

        self::maybe_install();

        $claim = sanitize_textarea_field($data['claim_text'] ?? '');

        if (!$claim) {
            return new WP_Error('rifnote_invalid_claim', __('Claim text is required.', 'rifnote-search'), array('status' => 400));
        }

        $now = current_time('mysql', true);
        $wpdb->insert(self::claims_table(), array(
            'post_id' => isset($data['post_id']) ? absint($data['post_id']) : null,
            'cluster_id' => sanitize_text_field($data['cluster_id'] ?? ''),
            'claim_text' => $claim,
            'claimant' => sanitize_text_field($data['claimant'] ?? ''),
            'rating' => sanitize_text_field($data['rating'] ?? ''),
            'review_summary' => sanitize_textarea_field($data['review_summary'] ?? ''),
            'review_url' => esc_url_raw($data['review_url'] ?? ''),
            'status' => sanitize_key($data['status'] ?? 'active'),
            'created_at' => $now,
            'updated_at' => $now,
        ));

        return (int) $wpdb->insert_id;
    }

    public static function claims_for_result($post_id, $cluster_id = '') {
        global $wpdb;

        self::maybe_install();

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::claims_table() . ' WHERE status = %s AND (post_id = %d OR (cluster_id <> %s AND cluster_id = %s)) ORDER BY updated_at DESC LIMIT 5',
            'active',
            (int) $post_id,
            '',
            sanitize_text_field($cluster_id)
        ), ARRAY_A);
    }

    public static function recent_claims($limit = 20) {
        global $wpdb;

        self::maybe_install();

        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::claims_table() . ' ORDER BY updated_at DESC LIMIT %d', max(1, min(100, (int) $limit))), ARRAY_A);
    }

    public static function add_sponsored($data) {
        global $wpdb;

        self::maybe_install();

        $title = sanitize_text_field($data['title'] ?? '');
        $target = esc_url_raw($data['target_url'] ?? '');
        $sponsor = sanitize_text_field($data['sponsor_name'] ?? '');

        if (!$title || !$target || !$sponsor) {
            return new WP_Error('rifnote_invalid_sponsored', __('Title, target URL and sponsor name are required.', 'rifnote-search'), array('status' => 400));
        }

        $now = current_time('mysql', true);
        $wpdb->insert(self::sponsored_table(), array(
            'title' => $title,
            'target_url' => $target,
            'sponsor_name' => $sponsor,
            'campaign_request_id' => isset($data['campaign_request_id']) ? absint($data['campaign_request_id']) : null,
            'placement' => sanitize_key($data['placement'] ?? 'search'),
            'category' => sanitize_title($data['category'] ?? ''),
            'query_match' => sanitize_text_field($data['query_match'] ?? ''),
            'priority' => isset($data['priority']) ? max(1, min(100, (int) $data['priority'])) : 50,
            'targeting_payload' => !empty($data['targeting_payload']) ? wp_json_encode(is_array($data['targeting_payload']) ? $data['targeting_payload'] : array()) : null,
            'status' => sanitize_key($data['status'] ?? 'active'),
            'starts_at' => !empty($data['starts_at']) ? gmdate('Y-m-d H:i:s', strtotime($data['starts_at'])) : null,
            'ends_at' => !empty($data['ends_at']) ? gmdate('Y-m-d H:i:s', strtotime($data['ends_at'])) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ));

        return (int) $wpdb->insert_id;
    }

    public static function sponsor_request($request_id) {
        global $wpdb;

        self::maybe_install();

        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::sponsor_requests_table() . ' WHERE id = %d', absint($request_id)), ARRAY_A);
    }

    public static function update_sponsor_request_status($request_id, $status, $note = '') {
        global $wpdb;

        self::maybe_install();

        $request_id = absint($request_id);
        $status = sanitize_key($status);
        $allowed = array('new', 'reviewing', 'needs_changes', 'approved', 'payment_review', 'paid', 'active', 'paused', 'rejected', 'completed');

        if (!$request_id || !in_array($status, $allowed, true)) {
            return new WP_Error('rifnote_invalid_ad_status', __('Invalid advert request status.', 'rifnote-search'), array('status' => 400));
        }

        $request = self::sponsor_request($request_id);
        if (!$request) {
            return new WP_Error('rifnote_ad_request_missing', __('Advert request could not be found.', 'rifnote-search'), array('status' => 404));
        }

        $payload = !empty($request['campaign_payload']) ? json_decode($request['campaign_payload'], true) : array();
        if (!is_array($payload)) {
            $payload = array();
        }

        $clean_note = Rifnote_Search_Source_Meta::normalize_text((string) $note, true);
        $timeline = is_array($payload['status_timeline'] ?? null) ? $payload['status_timeline'] : array();
        $timeline[] = array(
            'at' => gmdate(DATE_ATOM),
            'by' => get_current_user_id(),
            'from' => sanitize_key($request['status'] ?? ''),
            'to' => $status,
            'note' => $clean_note,
        );

        $payload['status_note'] = $clean_note;
        $payload['status_timeline'] = array_slice($timeline, -30);

        $updated = $wpdb->update(self::sponsor_requests_table(), array(
            'status' => $status,
            'campaign_payload' => wp_json_encode($payload),
            'updated_at' => current_time('mysql', true),
        ), array('id' => $request_id), array('%s', '%s', '%s'), array('%d'));

        if (false !== $updated && in_array($status, array('paused', 'rejected', 'completed'), true)) {
            $wpdb->update(self::sponsored_table(), array(
                'status' => 'paused',
                'updated_at' => current_time('mysql', true),
            ), array('campaign_request_id' => $request_id), array('%s', '%s'), array('%d'));
        }

        if (false !== $updated && 'active' === $status) {
            $wpdb->update(self::sponsored_table(), array(
                'status' => 'active',
                'updated_at' => current_time('mysql', true),
            ), array('campaign_request_id' => $request_id), array('%s', '%s'), array('%d'));
        }

        return false !== $updated;
    }

    private static function advertiser_owns_request($request, $user_id = 0) {
        $user_id = $user_id ? absint($user_id) : get_current_user_id();
        $user = $user_id ? get_userdata($user_id) : null;

        if (!$request || !$user) {
            return false;
        }

        if (current_user_can('manage_options')) {
            return true;
        }

        $payload = !empty($request['campaign_payload']) ? json_decode($request['campaign_payload'], true) : array();
        $owner_id = is_array($payload) ? (int) ($payload['advertiser_user_id'] ?? 0) : 0;
        $contact_email = sanitize_email($request['contact_email'] ?? '');

        return ($owner_id && $owner_id === $user_id) || ($contact_email && strtolower($contact_email) === strtolower($user->user_email));
    }

    public static function submit_payment_proof($request_id, $data, $user_id = 0) {
        global $wpdb;

        self::maybe_install();

        $request_id = absint($request_id);
        $request = self::sponsor_request($request_id);

        if (!$request) {
            return new WP_Error('rifnote_ad_request_missing', __('Advert request could not be found.', 'rifnote-search'), array('status' => 404));
        }

        if (!self::advertiser_owns_request($request, $user_id)) {
            self::log_suspicious('advertiser_payment_access', 'Advertiser attempted to update payment proof for a campaign they do not own.', array('request_id' => $request_id));
            return new WP_Error('rifnote_ad_request_forbidden', __('You cannot update this campaign.', 'rifnote-search'), array('status' => 403));
        }

        $reference = sanitize_text_field($data['payment_reference'] ?? ($data['reference'] ?? ''));
        $amount = isset($data['payment_amount']) ? (float) preg_replace('/[^0-9.]/', '', (string) $data['payment_amount']) : (float) preg_replace('/[^0-9.]/', '', (string) ($data['amount'] ?? 0));
        $note = sanitize_textarea_field($data['payment_note'] ?? ($data['note'] ?? ''));

        if (!$reference && !$amount && !$note) {
            return new WP_Error('rifnote_payment_proof_empty', __('Add a payment reference, amount, or note before submitting.', 'rifnote-search'), array('status' => 400));
        }

        $payload = array(
            'reference' => $reference,
            'amount' => $amount,
            'note' => $note,
            'submitted_by' => get_current_user_id(),
            'submitted_at' => gmdate(DATE_ATOM),
        );

        $wpdb->update(self::sponsor_requests_table(), array(
            'status' => 'payment_review',
            'payment_reference' => $reference,
            'payment_amount' => $amount,
            'payment_note' => $note,
            'payment_payload' => wp_json_encode($payload),
            'paid_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ), array('id' => $request_id), array('%s', '%s', '%f', '%s', '%s', '%s', '%s'), array('%d'));

        self::update_sponsor_request_status($request_id, 'payment_review', __('Advertiser submitted payment proof.', 'rifnote-search'));

        return array('success' => true, 'request_id' => $request_id, 'status' => 'payment_review', 'message' => __('Payment proof received. Rifnote will verify it and activate the campaign when cleared.', 'rifnote-search'));
    }

    public static function update_campaign_creative($request_id, $data) {
        global $wpdb;

        self::maybe_install();

        $request_id = absint($request_id);
        $request = self::sponsor_request($request_id);

        if (!$request) {
            return new WP_Error('rifnote_ad_request_missing', __('Advert request could not be found.', 'rifnote-search'), array('status' => 404));
        }

        $payload = !empty($request['campaign_payload']) ? json_decode($request['campaign_payload'], true) : array();
        if (!is_array($payload)) {
            $payload = array();
        }

        $previous_creative = is_array($payload['creative'] ?? null) ? $payload['creative'] : array();
        $variants = array();
        $headlines = isset($data['creative_headlines']) ? (array) $data['creative_headlines'] : array();
        $texts = isset($data['creative_texts']) ? (array) $data['creative_texts'] : array();
        $ctas = isset($data['creative_ctas']) ? (array) $data['creative_ctas'] : array();
        $max = max(count($headlines), count($texts), count($ctas), 1);

        for ($i = 0; $i < min(6, $max); $i++) {
            $headline = Rifnote_Search_Source_Meta::normalize_text((string) ($headlines[$i] ?? ''));
            $text = Rifnote_Search_Source_Meta::normalize_text((string) ($texts[$i] ?? ''), true);
            $cta = Rifnote_Search_Source_Meta::normalize_text((string) ($ctas[$i] ?? ''));
            if (!$headline && !$text && !$cta) {
                continue;
            }
            $variants[] = array(
                'headline' => $headline,
                'text' => $text,
                'cta' => $cta ? $cta : __('Learn more', 'rifnote-search'),
            );
        }

        $assets = array();
        foreach ((array) ($data['creative_assets'] ?? array()) as $asset) {
            $url = esc_url_raw((string) $asset);
            if ($url) {
                $assets[] = $url;
            }
        }

        $status = sanitize_key($data['creative_status'] ?? ($previous_creative['status'] ?? 'draft'));
        if (!in_array($status, array('draft', 'review', 'approved', 'rejected', 'needs_changes'), true)) {
            $status = 'draft';
        }

        $history = is_array($payload['creative_history'] ?? null) ? $payload['creative_history'] : array();
        $history[] = array(
            'at' => gmdate(DATE_ATOM),
            'by' => get_current_user_id(),
            'status' => $status,
            'note' => Rifnote_Search_Source_Meta::normalize_text((string) ($data['creative_note'] ?? ''), true),
            'snapshot' => $previous_creative,
        );

        $payload['creative'] = array(
            'headline' => $variants[0]['headline'] ?? Rifnote_Search_Source_Meta::normalize_text((string) ($data['creative_headline'] ?? ($previous_creative['headline'] ?? ''))),
            'text' => $variants[0]['text'] ?? Rifnote_Search_Source_Meta::normalize_text((string) ($data['creative_text'] ?? ($previous_creative['text'] ?? '')), true),
            'cta' => $variants[0]['cta'] ?? Rifnote_Search_Source_Meta::normalize_text((string) ($data['creative_cta'] ?? ($previous_creative['cta'] ?? 'Learn more'))),
            'image_url' => $assets[0] ?? esc_url_raw((string) ($data['creative_image_url'] ?? ($previous_creative['image_url'] ?? ''))),
            'assets' => array_slice(array_values(array_unique($assets)), 0, 12),
            'variants' => $variants,
            'status' => $status,
            'review_note' => Rifnote_Search_Source_Meta::normalize_text((string) ($data['creative_note'] ?? ''), true),
            'updated_at' => gmdate(DATE_ATOM),
        );
        $payload['creative_history'] = array_slice($history, -20);

        $updated = $wpdb->update(self::sponsor_requests_table(), array(
            'campaign_payload' => wp_json_encode($payload),
            'updated_at' => current_time('mysql', true),
        ), array('id' => $request_id), array('%s', '%s'), array('%d'));

        return false !== $updated ? array('success' => true, 'request_id' => $request_id, 'creative' => $payload['creative']) : new WP_Error('rifnote_creative_update_failed', __('Creative could not be saved.', 'rifnote-search'), array('status' => 500));
    }

    public static function activate_sponsor_request($request_id) {
        $request = self::sponsor_request($request_id);

        if (!$request) {
            return new WP_Error('rifnote_ad_request_missing', __('Advert request could not be found.', 'rifnote-search'), array('status' => 404));
        }

        if ('active' === ($request['status'] ?? '')) {
            return new WP_Error('rifnote_ad_request_already_active', __('This advert request is already active.', 'rifnote-search'), array('status' => 400));
        }

        $payload = !empty($request['campaign_payload']) ? json_decode($request['campaign_payload'], true) : array();
        $placements = is_array($payload) && !empty($payload['placements']) && is_array($payload['placements']) ? $payload['placements'] : array('search_top_intent');
        $created = 0;

        foreach ($placements as $placement) {
            $audience = is_array($payload) ? ($payload['audience'] ?? array()) : array();
            if (is_array($audience) && empty($audience['locations'])) {
                $audience['locations'] = implode(', ', array_filter(array(
                    $audience['country'] ?? '',
                    $audience['state'] ?? '',
                )));
            }

            $result = self::add_sponsored(array(
                'title' => $request['campaign_title'],
                'target_url' => $request['target_url'],
                'sponsor_name' => $request['sponsor_name'],
                'campaign_request_id' => absint($request_id),
                'placement' => sanitize_key($placement),
                'category' => $request['category'],
                'query_match' => $request['query_match'],
                'priority' => self::objective_priority(is_array($payload) ? ($payload['objective'] ?? '') : ''),
                'targeting_payload' => is_array($payload) ? array(
                    'objective' => $payload['objective'] ?? '',
                    'audience' => $audience,
                    'interests' => $audience['interests'] ?? '',
                    'advertiser_type' => $payload['advertiser_type'] ?? '',
                ) : array(),
                'starts_at' => $request['starts_at'],
                'ends_at' => $request['ends_at'],
                'status' => 'active',
            ));

            if (!is_wp_error($result)) {
                $created++;
            }
        }

        self::update_sponsor_request_status($request_id, 'active', __('Activated from admin workflow.', 'rifnote-search'));

        return array('created' => $created, 'request_id' => absint($request_id));
    }

    private static function objective_priority($objective) {
        $objective = sanitize_key($objective);
        $map = array(
            'matchday' => 85,
            'leads' => 75,
            'traffic' => 65,
            'awareness' => 50,
        );

        return $map[$objective] ?? 50;
    }

    public static function sponsored_for_request($args, $limit = 2) {
        global $wpdb;

        self::maybe_install();

        $now = current_time('mysql', true);
        $category = sanitize_title($args['category'] ?? '');
        $query = sanitize_text_field($args['query'] ?? '');
        $placement_context = sanitize_key($args['placement'] ?? (empty($query) ? 'home' : 'search'));
        $compatible = self::compatible_placements($placement_context);
        $placeholders = implode(',', array_fill(0, count($compatible), '%s'));
        $visitor = self::ad_visitor_context($args);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::sponsored_table() . " WHERE status = 'active' AND placement IN ({$placeholders}) AND (starts_at IS NULL OR starts_at <= %s) AND (ends_at IS NULL OR ends_at >= %s) ORDER BY priority DESC, updated_at DESC LIMIT 60",
            array_merge($compatible, array($now, $now))
        ), ARRAY_A);
        $scored = array();

        foreach ($rows as $row) {
            if (self::frequency_capped($row, $visitor)) {
                continue;
            }

            $score = self::ad_match_score($row, $args, $visitor);

            if ($score > 0) {
                $scored[] = array('score' => $score, 'row' => $row);
            }
        }

        usort($scored, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $matches = array();
        $seen_sponsors = array();
        $seen_domains = array();

        foreach ($scored as $item) {
            $row = $item['row'];
            $domain = wp_parse_url($row['target_url'], PHP_URL_HOST);
            $sponsor_key = sanitize_title($row['sponsor_name']);
            $domain_key = sanitize_text_field($domain ?: '');

            if ($sponsor_key && in_array($sponsor_key, $seen_sponsors, true)) {
                continue;
            }

            if ($domain_key && in_array($domain_key, $seen_domains, true)) {
                continue;
            }

            $seen_sponsors[] = $sponsor_key;
            $seen_domains[] = $domain_key;
            $matches[] = self::sponsored_payload($row, $item['score']);

            if (count($matches) >= max(1, min(5, (int) $limit))) {
                break;
            }
        }

        foreach ($matches as $match) {
            $wpdb->query($wpdb->prepare('UPDATE ' . self::sponsored_table() . ' SET impressions = impressions + 1 WHERE id = %d', (int) $match['id']));
        }

        return $matches;
    }

    private static function compatible_placements($context) {
        $context = sanitize_key($context);
        $map = array(
            'home' => array('home_top_headline', 'live_updates_sidebar', 'trending_topics_card', 'mobile_bottom_bar', 'search_top_intent', 'search'),
            'search' => array('search_top_intent', 'search_inline_result', 'live_updates_sidebar', 'mobile_bottom_bar', 'search'),
            'football' => array('football_matchday_board', 'live_updates_sidebar', 'mobile_bottom_bar', 'search_top_intent'),
            'story' => array('story_cluster_sidebar', 'live_updates_sidebar', 'trending_topics_card', 'mobile_bottom_bar'),
        );

        return $map[$context] ?? $map['search'];
    }

    private static function ad_visitor_context($args) {
        global $wpdb;

        $visitor_id = sanitize_text_field($args['visitor_id'] ?? '');
        $session_id = sanitize_text_field($args['session_id'] ?? '');
        $metadata = isset($args['metadata']) && is_array($args['metadata']) ? $args['metadata'] : array();
        $profile = array();

        if ($visitor_id) {
            $profile = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Rifnote_Search_Analytics::visitors_table() . ' WHERE visitor_id = %s', $visitor_id), ARRAY_A);
        }

        return array(
            'visitor_id' => $visitor_id,
            'session_id' => $session_id,
            'device_type' => sanitize_key($metadata['device_type'] ?? ($profile['device_type'] ?? '')),
            'country' => sanitize_text_field($metadata['country'] ?? ($profile['country'] ?? '')),
            'region' => sanitize_text_field($metadata['region'] ?? ($profile['region'] ?? '')),
            'interests' => !empty($profile['interest_tags']) ? json_decode($profile['interest_tags'], true) : array(),
            'top_category' => sanitize_text_field($profile['top_category'] ?? ''),
        );
    }

    private static function frequency_capped($row, $visitor) {
        global $wpdb;

        if (empty($visitor['visitor_id'])) {
            return false;
        }

        $daily_cap = max(1, (int) get_option('rifnote_ads_frequency_cap_daily', 4));
        $session_cap = max(1, (int) get_option('rifnote_ads_frequency_cap_session', 2));
        $since = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
        $logs = Rifnote_Search_Analytics::logs_table();
        $needle = '%"placement_id":' . (int) $row['id'] . '%';

        $daily = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$logs} WHERE event_type = 'ad_impression' AND visitor_id = %s AND metadata LIKE %s AND created_at >= %s", $visitor['visitor_id'], $needle, $since));

        if ($daily >= $daily_cap) {
            return true;
        }

        if (!empty($visitor['session_id'])) {
            $session = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$logs} WHERE event_type = 'ad_impression' AND session_id = %s AND metadata LIKE %s", $visitor['session_id'], $needle));
            if ($session >= $session_cap) {
                return true;
            }
        }

        return false;
    }

    private static function ad_match_score($row, $args, $visitor) {
        $category = sanitize_title($args['category'] ?? '');
        $query = strtolower(sanitize_text_field($args['query'] ?? ''));
        $targeting = !empty($row['targeting_payload']) ? json_decode($row['targeting_payload'], true) : array();
        $audience = is_array($targeting) ? ($targeting['audience'] ?? array()) : array();
        $score = 20 + (int) ($row['priority'] ?? 50);

        if (!empty($row['category'])) {
            if ($category && $row['category'] === $category) {
                $score += 35;
            } else {
                $score -= 20;
            }
        }

        if (!empty($row['query_match'])) {
            $needle = strtolower($row['query_match']);
            if ($query && false !== strpos($query, $needle)) {
                $score += 45;
            } else {
                $score -= 30;
            }
        }

        if ((!empty($row['category']) && (!$category || $row['category'] !== $category)) && (!empty($row['query_match']) && (!$query || false === strpos($query, strtolower($row['query_match']))))) {
            return 0;
        }

        $locations = strtolower(sanitize_text_field($audience['locations'] ?? ''));
        if ($locations) {
            $country = strtolower($visitor['country'] ?? '');
            $region = strtolower($visitor['region'] ?? '');
            if (($country && false !== strpos($locations, $country)) || ($region && false !== strpos($locations, $region))) {
                $score += 25;
            } elseif ($country || $region) {
                return 0;
            } else {
                $score -= 8;
            }
        }

        $interests = strtolower(sanitize_text_field($audience['interests'] ?? ($targeting['interests'] ?? '')));
        if ($interests && !empty($visitor['top_category']) && false !== strpos($interests, strtolower($visitor['top_category']))) {
            $score += 20;
        }

        if ($interests && !empty($visitor['interests']) && is_array($visitor['interests'])) {
            foreach ($visitor['interests'] as $interest) {
                if ($interest && false !== strpos($interests, strtolower($interest))) {
                    $score += 20;
                    break;
                }
            }
        }

        $target_device = sanitize_key($audience['device_type'] ?? ($targeting['device_type'] ?? ''));
        if ($target_device && !empty($visitor['device_type'])) {
            if ($target_device === $visitor['device_type']) {
                $score += 25;
            } else {
                $score -= 20;
            }
        }

        if (!empty($visitor['device_type']) && in_array($row['placement'], array('mobile_bottom_bar'), true)) {
            $score += 'mobile' === $visitor['device_type'] ? 30 : -15;
        }

        return $score;
    }

    private static function sponsored_payload($row, $score = 0) {
        return array(
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'target_url' => esc_url_raw($row['target_url']),
            'sponsor_name' => $row['sponsor_name'],
            'placement' => $row['placement'],
            'label' => __('Sponsored', 'rifnote-search'),
            'score' => (int) $score,
            'impressions' => (int) $row['impressions'],
            'clicks' => (int) $row['clicks'],
        );
    }

    public static function recent_sponsored($limit = 20) {
        global $wpdb;

        self::maybe_install();

        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::sponsored_table() . ' ORDER BY updated_at DESC LIMIT %d', max(1, min(100, (int) $limit))), ARRAY_A);
    }

    public static function submit_sponsor_request($data) {
        global $wpdb;

        self::maybe_install();

        $email = sanitize_email($data['contact_email'] ?? '');
        $target = esc_url_raw($data['target_url'] ?? '');
        $title = sanitize_text_field($data['campaign_title'] ?? ($data['title'] ?? ''));
        $sponsor = sanitize_text_field($data['sponsor_name'] ?? '');

        if (!$email || !is_email($email) || !$target || !$title || !$sponsor) {
            return new WP_Error('rifnote_invalid_sponsor_request', __('Sponsor name, email, campaign title and target URL are required.', 'rifnote-search'), array('status' => 400));
        }

        $advertiser_account = self::ensure_advertiser_account($email, $sponsor, $data);
        $now = current_time('mysql', true);
        $checkout_url = self::checkout_url(array_merge($data, array('contact_email' => $email)));
        $estimate = self::estimate_ad_campaign($data);
        $budget = isset($data['budget']) ? preg_replace('/[^0-9.]/', '', (string) $data['budget']) : ($data['total_budget'] ?? $estimate['estimated_price']);
        $starts_at = $data['starts_at'] ?? ($data['start_date'] ?? '');
        $ends_at = $data['ends_at'] ?? ($data['end_date'] ?? '');
        $payload = array(
            'advertiser_user_id' => (int) ($advertiser_account['user_id'] ?? 0),
            'advertiser_account_status' => sanitize_key($advertiser_account['status'] ?? 'existing'),
            'advertiser_type' => sanitize_text_field($data['advertiser_type'] ?? ''),
            'phone' => sanitize_text_field($data['phone'] ?? ''),
            'company_website' => esc_url_raw($data['company_website'] ?? ''),
            'objective' => sanitize_key($data['objective'] ?? 'awareness'),
            'placements' => isset($data['placements']) && is_array($data['placements']) ? array_map('sanitize_key', $data['placements']) : array(),
            'audience' => array(
                'country' => sanitize_text_field($data['audience_country'] ?? ''),
                'state' => sanitize_text_field($data['audience_state'] ?? ''),
                'locations' => sanitize_text_field($data['audience_locations'] ?? ''),
                'age_min' => absint($data['audience_age_min'] ?? 18),
                'age_max' => absint($data['audience_age_max'] ?? 34),
                'gender' => sanitize_text_field($data['audience_gender'] ?? 'all'),
                'interests' => sanitize_text_field($data['interests'] ?? ''),
            ),
            'creative' => array(
                'headline' => sanitize_text_field($data['creative_headline'] ?? ''),
                'text' => sanitize_textarea_field($data['creative_text'] ?? ''),
                'image_url' => esc_url_raw($data['creative_image_url'] ?? ''),
            ),
            'pricing_model' => sanitize_text_field($data['pricing_model'] ?? 'daily'),
            'estimate' => $estimate,
        );
        $wpdb->insert(self::sponsor_requests_table(), array(
            'sponsor_name' => $sponsor,
            'contact_email' => $email,
            'target_url' => $target,
            'campaign_title' => $title,
            'budget' => (float) $budget,
            'category' => sanitize_title($data['category'] ?? ''),
            'query_match' => sanitize_text_field($data['query_match'] ?? ''),
            'objective' => sanitize_key($data['objective'] ?? 'awareness'),
            'placement_summary' => sanitize_text_field($estimate['placement_summary']),
            'estimated_price' => (float) $estimate['estimated_price'],
            'campaign_payload' => wp_json_encode($payload),
            'starts_at' => !empty($starts_at) ? gmdate('Y-m-d H:i:s', strtotime($starts_at)) : null,
            'ends_at' => !empty($ends_at) ? gmdate('Y-m-d H:i:s', strtotime($ends_at)) : null,
            'status' => 'new',
            'checkout_url' => $checkout_url,
            'created_at' => $now,
            'updated_at' => $now,
        ));

        return array('success' => true, 'id' => (int) $wpdb->insert_id, 'request_id' => (int) $wpdb->insert_id, 'status' => 'new', 'checkout_url' => $checkout_url, 'estimate' => $estimate, 'message' => __('Campaign request received. We will review the setup and lock the best slots.', 'rifnote-search'));
    }

    private static function ensure_advertiser_account($email, $sponsor, $data) {
        if (!get_role('rifnote_advertiser')) {
            add_role('rifnote_advertiser', __('Rifnote Advertiser', 'rifnote-search'), array('read' => true));
        }

        $user_id = email_exists($email);
        $status = 'existing';

        if (!$user_id) {
            $base_login = sanitize_user(current(explode('@', $email)), true);
            if (!$base_login) {
                $base_login = sanitize_user($sponsor, true);
            }
            if (!$base_login) {
                $base_login = 'advertiser';
            }

            $login = $base_login;
            $suffix = 1;
            while (username_exists($login)) {
                $suffix++;
                $login = $base_login . $suffix;
            }

            $user_id = wp_create_user($login, wp_generate_password(24, true), $email);
            if (is_wp_error($user_id)) {
                return array('user_id' => 0, 'status' => 'account_failed');
            }

            wp_update_user(array('ID' => $user_id, 'display_name' => $sponsor, 'role' => 'rifnote_advertiser'));
            $status = 'created';
        }

        if ($user_id) {
            update_user_meta($user_id, 'rifnote_advertiser_name', sanitize_text_field($sponsor));
            update_user_meta($user_id, 'rifnote_advertiser_type', sanitize_text_field($data['advertiser_type'] ?? ''));
            update_user_meta($user_id, 'rifnote_advertiser_phone', sanitize_text_field($data['phone'] ?? ''));
            update_user_meta($user_id, 'rifnote_advertiser_website', esc_url_raw($data['company_website'] ?? ''));
        }

        return array('user_id' => (int) $user_id, 'status' => $status);
    }

    public static function advertiser_signup($data) {
        self::maybe_install();

        $name = sanitize_text_field($data['sponsor_name'] ?? ($data['name'] ?? ''));
        $email = sanitize_email($data['contact_email'] ?? ($data['email'] ?? ''));

        if (!$name || !is_email($email)) {
            return new WP_Error('rifnote_advertiser_signup_missing', __('Add advertiser name and a valid work email.', 'rifnote-search'), array('status' => 400));
        }

        $account = self::ensure_advertiser_account($email, $name, array(
            'advertiser_type' => sanitize_text_field($data['advertiser_type'] ?? 'Brand'),
            'phone' => sanitize_text_field($data['phone'] ?? ''),
            'company_website' => esc_url_raw($data['company_website'] ?? ($data['website'] ?? '')),
        ));

        $user_id = (int) ($account['user_id'] ?? 0);

        if (!$user_id) {
            return new WP_Error('rifnote_advertiser_signup_failed', __('Advertiser account could not be created.', 'rifnote-search'), array('status' => 500));
        }

        if ('created' === ($account['status'] ?? '')) {
            wp_new_user_notification($user_id, null, 'user');
        }

        update_user_meta($user_id, 'rifnote_advertiser_role', sanitize_text_field($data['advertiser_role'] ?? ''));
        update_user_meta($user_id, 'rifnote_advertiser_goals', sanitize_text_field($data['goals'] ?? ''));

        return array(
            'success' => true,
            'user_id' => $user_id,
            'account_status' => sanitize_key($account['status'] ?? 'existing'),
            'dashboard_url' => home_url('/advertiser-dashboard/'),
            'login_url' => wp_login_url(home_url('/advertiser-dashboard/')),
            'advertise_url' => home_url('/advertise/'),
            'message' => 'created' === ($account['status'] ?? '') ? __('Advertiser account created. Check your email to set your password.', 'rifnote-search') : __('Advertiser account already exists. Sign in to continue.', 'rifnote-search'),
        );
    }

    private static function checkout_url($data) {
        $base = esc_url_raw(get_option('rifnote_sponsor_checkout_url', ''));

        if (!$base) {
            return add_query_arg(array('sponsor' => rawurlencode($data['contact_email'] ?? '')), home_url('/submit-news/'));
        }

        return add_query_arg(array(
            'email' => rawurlencode($data['contact_email'] ?? ''),
            'campaign' => rawurlencode($data['campaign_title'] ?? ($data['title'] ?? '')),
        ), $base);
    }

    public static function recent_sponsor_requests($limit = 20) {
        global $wpdb;

        self::maybe_install();

        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::sponsor_requests_table() . ' ORDER BY created_at DESC LIMIT %d', max(1, min(100, (int) $limit))), ARRAY_A);
    }

    public static function advertiser_dashboard($user_id = 0) {
        global $wpdb;

        self::maybe_install();

        $user_id = $user_id ? absint($user_id) : get_current_user_id();
        $user = $user_id ? get_userdata($user_id) : null;
        $is_admin = current_user_can('manage_options');
        $requests = $wpdb->get_results('SELECT * FROM ' . self::sponsor_requests_table() . ' ORDER BY created_at DESC LIMIT 250', ARRAY_A);
        $performance = Rifnote_Search_Analytics::ad_performance_summary(30);
        $performance_by_campaign = array();

        foreach ($performance['placements'] ?? array() as $placement) {
            $campaign_id = (int) ($placement['campaign_request_id'] ?? 0);
            if (!$campaign_id) {
                continue;
            }
            if (!isset($performance_by_campaign[$campaign_id])) {
                $performance_by_campaign[$campaign_id] = array('impressions' => 0, 'clicks' => 0, 'conversions' => 0, 'conversion_value' => 0);
            }
            $performance_by_campaign[$campaign_id]['impressions'] += (int) ($placement['impressions'] ?? 0);
            $performance_by_campaign[$campaign_id]['clicks'] += (int) ($placement['clicks'] ?? 0);
            $performance_by_campaign[$campaign_id]['conversions'] += (int) ($placement['conversions'] ?? 0);
            $performance_by_campaign[$campaign_id]['conversion_value'] += (float) ($placement['conversion_value'] ?? 0);
        }

        $campaigns = array();
        foreach (is_array($requests) ? $requests : array() as $request) {
            $payload = !empty($request['campaign_payload']) ? json_decode($request['campaign_payload'], true) : array();
            $owner_id = (int) ($payload['advertiser_user_id'] ?? 0);
            $contact_email = sanitize_email($request['contact_email'] ?? '');

            if (!$is_admin && $user) {
                $owns = ($owner_id && $owner_id === $user_id) || ($contact_email && strtolower($contact_email) === strtolower($user->user_email));
                if (!$owns) {
                    continue;
                }
            }

            $estimate = is_array($payload) ? ($payload['estimate'] ?? array()) : array();
            $stats = $performance_by_campaign[(int) $request['id']] ?? array('impressions' => 0, 'clicks' => 0, 'conversions' => 0, 'conversion_value' => 0);
            $campaigns[] = array(
                'id' => (int) $request['id'],
                'title' => $request['campaign_title'],
                'sponsor_name' => $request['sponsor_name'],
                'status' => $request['status'],
                'objective' => $request['objective'] ?: ($payload['objective'] ?? 'awareness'),
                'placements' => $request['placement_summary'],
                'category' => $request['category'],
                'query_match' => $request['query_match'],
                'estimated_price' => (float) ($request['estimated_price'] ?? 0),
                'estimated_impressions' => (int) ($estimate['estimated_impressions'] ?? 0),
                'payment_reference' => $request['payment_reference'] ?? '',
                'payment_amount' => (float) ($request['payment_amount'] ?? 0),
                'payment_note' => $request['payment_note'] ?? '',
                'paid_at' => $request['paid_at'] ?? '',
                'starts_at' => $request['starts_at'],
                'ends_at' => $request['ends_at'],
                'checkout_url' => $request['checkout_url'],
                'status_note' => is_array($payload) ? (string) ($payload['status_note'] ?? '') : '',
                'timeline' => is_array($payload) && is_array($payload['status_timeline'] ?? null) ? array_values($payload['status_timeline']) : array(),
                'creative' => is_array($payload) && is_array($payload['creative'] ?? null) ? $payload['creative'] : array(),
                'stats' => array_merge($stats, array(
                    'ctr' => (int) $stats['impressions'] > 0 ? round(((int) $stats['clicks'] / (int) $stats['impressions']) * 100, 2) : 0,
                    'conversion_rate' => (int) $stats['clicks'] > 0 ? round(((int) $stats['conversions'] / (int) $stats['clicks']) * 100, 2) : 0,
                )),
            );
        }

        $summary = array('campaigns' => count($campaigns), 'spend' => 0, 'impressions' => 0, 'clicks' => 0, 'conversions' => 0, 'conversion_value' => 0);
        foreach ($campaigns as $campaign) {
            $summary['spend'] += (float) $campaign['estimated_price'];
            $summary['impressions'] += (int) $campaign['stats']['impressions'];
            $summary['clicks'] += (int) $campaign['stats']['clicks'];
            $summary['conversions'] += (int) $campaign['stats']['conversions'];
            $summary['conversion_value'] += (float) $campaign['stats']['conversion_value'];
        }
        $summary['ctr'] = $summary['impressions'] > 0 ? round(($summary['clicks'] / $summary['impressions']) * 100, 2) : 0;
        $summary['conversion_rate'] = $summary['clicks'] > 0 ? round(($summary['conversions'] / $summary['clicks']) * 100, 2) : 0;

        return array(
            'user' => $user ? array('id' => $user_id, 'name' => $user->display_name, 'email' => $user->user_email, 'is_admin' => $is_admin) : null,
            'profile' => $user ? self::advertiser_profile($user_id) : null,
            'summary' => $summary,
            'campaigns' => $campaigns,
            'pacing' => $performance['campaign_pacing'] ?? array(),
            'risk_alerts' => $is_admin ? ($performance['risk_alerts'] ?? array()) : array(),
            'conversion_endpoint' => rest_url('rifnote/v1/sponsored/conversion'),
        );
    }

    public static function advertiser_profile($user_id = 0) {
        $user_id = $user_id ? absint($user_id) : get_current_user_id();
        $user = $user_id ? get_userdata($user_id) : null;

        if (!$user) {
            return null;
        }

        return array(
            'name' => get_user_meta($user_id, 'rifnote_advertiser_name', true) ?: $user->display_name,
            'type' => get_user_meta($user_id, 'rifnote_advertiser_type', true) ?: '',
            'phone' => get_user_meta($user_id, 'rifnote_advertiser_phone', true) ?: '',
            'website' => get_user_meta($user_id, 'rifnote_advertiser_website', true) ?: '',
            'email' => $user->user_email,
        );
    }

    public static function update_advertiser_profile($data, $user_id = 0) {
        $user_id = $user_id ? absint($user_id) : get_current_user_id();
        $user = $user_id ? get_userdata($user_id) : null;

        if (!$user) {
            return new WP_Error('rifnote_advertiser_not_logged_in', __('Sign in before updating advertiser settings.', 'rifnote-search'), array('status' => 401));
        }

        $name = Rifnote_Search_Source_Meta::normalize_text((string) ($data['name'] ?? ''));
        $type = sanitize_key($data['type'] ?? '');
        $phone = sanitize_text_field($data['phone'] ?? '');
        $website = esc_url_raw((string) ($data['website'] ?? ''));

        if ($name) {
            update_user_meta($user_id, 'rifnote_advertiser_name', $name);
            wp_update_user(array('ID' => $user_id, 'display_name' => $name));
        }

        update_user_meta($user_id, 'rifnote_advertiser_type', $type);
        update_user_meta($user_id, 'rifnote_advertiser_phone', $phone);
        update_user_meta($user_id, 'rifnote_advertiser_website', $website);

        return array(
            'success' => true,
            'profile' => self::advertiser_profile($user_id),
            'message' => __('Advertiser profile updated.', 'rifnote-search'),
        );
    }

    public static function log_suspicious($event_type, $message, $metadata = array()) {
        global $wpdb;

        self::maybe_install();

        return (bool) $wpdb->insert(self::suspicious_table(), array(
            'event_type' => sanitize_key($event_type),
            'ip_hash' => Rifnote_Search_Analytics::ip_hash(),
            'user_id' => get_current_user_id(),
            'message' => sanitize_textarea_field($message),
            'metadata' => wp_json_encode(is_array($metadata) ? $metadata : array()),
            'status' => 'new',
            'created_at' => current_time('mysql', true),
        ));
    }

    public static function recent_suspicious($limit = 20) {
        global $wpdb;

        self::maybe_install();

        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::suspicious_table() . ' ORDER BY created_at DESC LIMIT %d', max(1, min(100, (int) $limit))), ARRAY_A);
    }

    public static function rotate_publisher_api_key($publisher_id) {
        global $wpdb;

        Rifnote_Search_Publishers::maybe_install();

        $raw = 'rfs_' . wp_generate_password(32, false);
        $wpdb->update(Rifnote_Search_Publishers::publishers_table(), array(
            'api_key_hash' => wp_hash_password($raw),
            'updated_at' => current_time('mysql', true),
        ), array('id' => (int) $publisher_id));

        return $raw;
    }

    public static function schema_for_result($result) {
        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'NewsArticle',
            'headline' => $result['headline'],
            'datePublished' => $result['published_at'],
            'publisher' => array('@type' => 'Organization', 'name' => $result['source_name']),
            'url' => $result['read_full_story_url'],
            'isBasedOn' => $result['original_url'],
        );

        if (!empty($result['claims'])) {
            $schema['claimReviewed'] = wp_list_pluck($result['claims'], 'claim_text');
        }

        return $schema;
    }

    public static function print_head_tags() {
        if (!Rifnote_Search_Pages::mode_for_request()) {
            return;
        }

        $canonical = self::canonical_url();

        if ($canonical) {
            echo '<link rel="canonical" href="' . esc_url($canonical) . '" />' . "\n";
        }

        foreach (self::schemas_for_request() as $schema) {
            echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
        }
    }

    public static function maybe_serve_sitemap() {
        $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = trim((string) wp_parse_url($path, PHP_URL_PATH), '/');

        if ('rifnote-search-sitemap.xml' !== $path) {
            return;
        }

        nocache_headers();
        status_header(200);
        header('Content-Type: application/xml; charset=' . get_option('blog_charset'));

        echo '<?xml version="1.0" encoding="' . esc_attr(get_option('blog_charset')) . '"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach (self::sitemap_urls() as $url) {
            echo "  <url>\n";
            echo '    <loc>' . esc_xml($url['loc']) . "</loc>\n";
            if (!empty($url['lastmod'])) {
                echo '    <lastmod>' . esc_xml($url['lastmod']) . "</lastmod>\n";
            }
            echo "  </url>\n";
        }

        echo "</urlset>\n";
        exit;
    }

    private static function canonical_url() {
        $story = get_query_var('rifnote_story_cluster');
        $source = get_query_var('rifnote_source_domain');

        if ($story) {
            return home_url('/story/' . rawurlencode($story) . '/');
        }

        if ($source) {
            return home_url('/source/' . rawurlencode($source) . '/');
        }

        if (is_singular('page')) {
            return get_permalink();
        }

        return '';
    }

    private static function schemas_for_request() {
        $schemas = array(self::website_schema());
        $story = get_query_var('rifnote_story_cluster');
        $source = get_query_var('rifnote_source_domain');

        if ($story) {
            $cluster = Rifnote_Search_Story_Platform::cluster_payload($story);
            if (!is_wp_error($cluster)) {
                $schemas[] = self::story_cluster_schema($cluster);
            }
        } elseif ($source) {
            $schemas[] = array(
                '@context' => 'https://schema.org',
                '@type' => 'Organization',
                'name' => sanitize_text_field($source),
                'url' => home_url('/source/' . rawurlencode($source) . '/'),
            );
        } elseif (is_singular('page')) {
            $schemas[] = array(
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'name' => get_the_title(),
                'url' => get_permalink(),
                'isPartOf' => array('@id' => home_url('/search/#website')),
            );
        }

        return $schemas;
    }

    private static function website_schema() {
        return array(
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            '@id' => home_url('/search/#website'),
            'name' => 'Rifnote Search',
            'url' => home_url('/search/'),
            'potentialAction' => array(
                '@type' => 'SearchAction',
                'target' => home_url('/search/?q={search_term_string}'),
                'query-input' => 'required name=search_term_string',
            ),
        );
    }

    private static function story_cluster_schema($cluster) {
        $lead = $cluster['lead_story'];
        $schema = self::schema_for_result($lead);
        $schema['@id'] = home_url('/story/' . rawurlencode($cluster['cluster_id']) . '/#story');
        $schema['url'] = home_url('/story/' . rawurlencode($cluster['cluster_id']) . '/');
        $schema['description'] = $cluster['summary'];
        $schema['about'] = array_values(array_map(function ($term) {
            return array('@type' => 'Thing', 'name' => $term);
        }, $cluster['related_searches']));
        $schema['citation'] = array_values(array_map(function ($story) {
            return array(
                '@type' => 'CreativeWork',
                'name' => $story['headline'],
                'url' => $story['read_full_story_url'],
                'publisher' => array('@type' => 'Organization', 'name' => $story['source_name']),
            );
        }, array_slice($cluster['stories'], 0, 10)));

        return $schema;
    }

    private static function sitemap_urls() {
        $urls = array();
        $static_paths = array('/search/', '/football/', '/teams/', '/players/', '/transfers/', '/submit-news/', '/publisher-dashboard/', '/daily-briefing/', '/for-you/', '/newsletter/');

        foreach ($static_paths as $path) {
            $urls[] = array('loc' => home_url($path), 'lastmod' => gmdate('c'));
        }

        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 100,
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'ids',
        ));
        $clusters = array();
        $sources = array();

        foreach ($posts as $post_id) {
            $cluster = get_post_meta($post_id, 'story_cluster_id', true);
            $cluster = $cluster ? $cluster : 'post_' . $post_id;
            if (!isset($clusters[$cluster])) {
                $clusters[$cluster] = get_post_modified_time('c', true, $post_id);
            }

            $source = Rifnote_Search_Source_Meta::source_payload($post_id);
            if (!empty($source['source_domain']) && !isset($sources[$source['source_domain']])) {
                $sources[$source['source_domain']] = get_post_modified_time('c', true, $post_id);
            }
        }

        foreach ($clusters as $cluster => $lastmod) {
            $urls[] = array('loc' => home_url('/story/' . rawurlencode($cluster) . '/'), 'lastmod' => $lastmod);
        }

        foreach ($sources as $domain => $lastmod) {
            $urls[] = array('loc' => home_url('/source/' . rawurlencode($domain) . '/'), 'lastmod' => $lastmod);
        }

        return $urls;
    }

    public static function admin_summary() {
        global $wpdb;

        self::maybe_install();

        return array(
            'claims' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::claims_table() . " WHERE status = 'active'"),
            'sponsored' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::sponsored_table() . " WHERE status = 'active'"),
            'sponsor_requests' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::sponsor_requests_table() . " WHERE status = 'new'"),
            'suspicious' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::suspicious_table() . " WHERE status = 'new'"),
        );
    }
}
