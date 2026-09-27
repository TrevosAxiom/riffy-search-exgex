<?php
/**
 * Curated Weblist directory storage and REST controls.
 *
 * @package Rifnote_Search
 */

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Weblist {
    const OPTION = 'rifnote_weblist_groups';

    public static function register_routes() {
        register_rest_route('rifnote/v1', '/weblist', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'get'),
                'permission_callback' => '__return_true',
            ),
            array(
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => array(__CLASS__, 'update'),
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            ),
        ));
    }

    public static function get() {
        $groups = get_option(self::OPTION, null);
        if (!is_array($groups)) {
            $groups = self::defaults();
        }

        return rest_ensure_response(array(
            'groups' => $groups,
            'can_manage' => current_user_can('manage_options'),
        ));
    }

    public static function update(WP_REST_Request $request) {
        $groups = self::sanitize_groups($request->get_param('groups'));

        if (empty($groups)) {
            return new WP_Error('rifnote_weblist_empty', __('Weblist must contain at least one valid group.', 'rifnote-search'), array('status' => 400));
        }

        update_option(self::OPTION, $groups, false);

        return rest_ensure_response(array(
            'ok' => true,
            'groups' => $groups,
            'can_manage' => true,
        ));
    }

    private static function sanitize_groups($groups) {
        if (!is_array($groups)) {
            return array();
        }

        $clean = array();
        foreach (array_slice($groups, 0, 30) as $group_index => $group) {
            if (!is_array($group)) continue;
            $title = sanitize_text_field((string) ($group['title'] ?? ''));
            if (!$title) continue;
            $slug = sanitize_title((string) ($group['slug'] ?? $title));
            $sources = array();

            foreach (array_slice((array) ($group['sources'] ?? array()), 0, 250) as $source_index => $source) {
                if (!is_array($source)) continue;
                $name = sanitize_text_field((string) ($source['name'] ?? ''));
                $url = esc_url_raw((string) ($source['url'] ?? ''));
                $query = sanitize_text_field((string) ($source['query'] ?? ''));
                if (!$name || (!$url && !$query)) continue;
                $sources[] = array(
                    'id' => sanitize_key((string) ($source['id'] ?? $slug . '-' . (sanitize_title($name) ? sanitize_title($name) : $source_index))),
                    'name' => $name,
                    'url' => $url,
                    'query' => $query,
                    'favicon_url' => esc_url_raw((string) ($source['favicon_url'] ?? '')),
                );
            }

            $clean[] = array(
                'id' => sanitize_key((string) ($group['id'] ?? $slug . '-' . $group_index)),
                'slug' => $slug ? $slug : 'group-' . ($group_index + 1),
                'title' => $title,
                'sources' => $sources,
            );
        }

        return $clean;
    }

    public static function defaults() {
        $groups = array(
            array('slug' => 'ng', 'title' => 'Nigerian media', 'sources' => array(
                array('name' => 'Punch', 'url' => 'https://punchng.com'), array('name' => 'Vanguard', 'url' => 'https://www.vanguardngr.com'), array('name' => 'Tribune', 'url' => 'https://tribuneonlineng.com'), array('name' => 'ThisDay', 'url' => 'https://www.thisdaylive.com'), array('name' => 'Guardian', 'url' => 'https://guardian.ng'), array('name' => 'PM News', 'url' => 'https://www.pmnewsnigeria.com'), array('name' => "People's Gazette", 'url' => 'https://gazettengr.com'), array('name' => 'Premium Times', 'url' => 'https://www.premiumtimesng.com'), array('name' => 'Channels TV', 'url' => 'https://www.channelstv.com'), array('name' => 'AIT', 'url' => 'https://www.aitvnews.tv'), array('name' => 'Sahara Reporters', 'url' => 'https://saharareporters.com'), array('name' => 'Independent', 'url' => 'https://independent.ng'), array('name' => 'Daily Post', 'url' => 'https://dailypost.ng'), array('name' => 'Daily Trust', 'url' => 'https://www.dailytrust.com'), array('name' => 'Naija News', 'url' => 'https://www.naijanews.com'), array('name' => 'Ripples', 'url' => 'https://www.ripplesnigeria.com'), array('name' => 'Leadership', 'url' => 'https://leadership.ng'), array('name' => 'Eagle Online', 'url' => 'https://www.eagleonline.com.ng'), array('name' => 'Blueprint', 'url' => 'https://www.blueprintafricana.com'), array('name' => 'Sun News', 'url' => 'https://www.sunnewsonline.com'), array('name' => 'The Cable', 'url' => 'https://www.thecable.ng'), array('name' => 'The News', 'url' => 'https://thenewsnigeria.com.ng'), array('name' => 'The Nation', 'url' => 'https://thenationonlineng.net'), array('name' => 'Nairametrics', 'url' => 'https://nairametrics.com'), array('name' => 'Economic Confidential', 'url' => 'https://economicconfidential.net'), array('name' => 'FIJ', 'url' => 'https://fij.ng'), array('name' => 'ICIR', 'url' => 'https://www.icirnigeria.org'), array('name' => 'Nigeria Abroad', 'url' => 'https://nigeriaabroad.com'), array('name' => 'ABOKI FX', 'url' => 'https://abokifx.com'),
            )),
            array('slug' => 'intl', 'title' => 'International news', 'sources' => array(
                array('name' => 'CNN', 'url' => 'https://www.cnn.com'), array('name' => 'Fox News', 'url' => 'https://www.foxnews.com'), array('name' => 'RealClearPolitics', 'url' => 'https://www.realclearpolitics.com'), array('name' => 'Yahoo News', 'url' => 'https://www.yahoo.com/news'), array('name' => 'MSN News', 'url' => 'https://www.msn.com/en-us/news'), array('name' => 'Sputnik', 'url' => 'https://sputnikglobe.com'), array('name' => 'RT', 'url' => 'https://www.rt.com'), array('name' => 'Daily Wire', 'url' => 'https://www.dailywire.com'), array('name' => 'Daily Caller', 'url' => 'https://www.dailycaller.com'), array('name' => 'Daily Mail', 'url' => 'https://www.dailymail.co.uk'), array('name' => 'The Sun', 'url' => 'https://www.thesun.co.uk'), array('name' => 'HotAir', 'url' => 'https://hotair.com'), array('name' => 'Just the News', 'url' => 'https://justthenews.com'), array('name' => 'Breitbart', 'url' => 'https://www.breitbart.com'), array('name' => 'Drudge Report', 'url' => 'https://www.drudgereport.com'), array('name' => 'Rantingly', 'url' => 'https://www.rantingly.com'), array('name' => 'Newzit', 'url' => 'https://www.newzit.com'), array('name' => 'Knewz', 'url' => 'https://knewz.com'), array('name' => 'Upstract', 'url' => 'https://upstract.com'), array('name' => 'Memeorandum', 'url' => 'https://memeorandum.com'),
            )),
            array('slug' => 'alt', 'title' => 'Analysis & technology', 'sources' => array(
                array('name' => 'The Conversation', 'url' => 'https://theconversation.com'), array('name' => 'DNYUZ', 'url' => 'https://dnyuz.com'), array('name' => 'Euronews', 'url' => 'https://www.euronews.com'), array('name' => 'France 24', 'url' => 'https://www.france24.com'), array('name' => 'News Now', 'url' => 'https://www.newsnow.co.uk'), array('name' => 'Futurism', 'url' => 'https://futurism.com'), array('name' => 'Tech Startups', 'url' => 'https://techcrunch.com'), array('name' => 'Tech Cabal', 'url' => 'https://techcabal.com'), array('name' => 'National Interest', 'url' => 'https://nationalinterest.org'), array('name' => 'ZeroHedge', 'url' => 'https://www.zerohedge.com'),
            )),
            array('slug' => 'sport', 'title' => 'Sports', 'sources' => array(
                array('name' => 'Marca', 'url' => 'https://www.marca.com'), array('name' => 'AS', 'url' => 'https://as.com'), array('name' => 'BBC Football', 'url' => 'https://www.bbc.com/sport/football'), array('name' => 'Daily Mail Sports', 'url' => 'https://www.dailymail.co.uk/sport'), array('name' => 'Science Daily', 'url' => 'https://www.sciencedaily.com'),
            )),
            array('slug' => 'social', 'title' => 'Search & social', 'sources' => array(
                array('name' => 'Reddit', 'url' => 'https://www.reddit.com'), array('name' => 'Twitter / X', 'url' => 'https://x.com'), array('name' => 'Imgur', 'url' => 'https://imgur.com'),
            )),
            array('slug' => 'topics', 'title' => 'Saved topics', 'sources' => array(
                array('name' => 'Messi', 'query' => 'Find me the latest news about Messi'), array('name' => 'Yoruba', 'query' => 'Find me the latest Yoruba news and stories'), array('name' => 'Nigeria', 'query' => 'Find me the latest Nigeria news'), array('name' => 'Nigeria diaspora', 'query' => 'Find me the latest news about the Nigerian diaspora'), array('name' => 'Tinubu', 'query' => 'Find me the latest news about President Tinubu'),
            )),
        );

        return self::sanitize_groups($groups);
    }
}
