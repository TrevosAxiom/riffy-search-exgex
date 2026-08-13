<?php

if (!defined('ABSPATH')) {
    exit;
}

$plugin = Rifnote_Search_Plugin::instance();
$mode = Rifnote_Search_Pages::current_mode();
$mount = $plugin->render_app($mode ? $mode : 'app');
$nav_items = Rifnote_Search_Pages::nav_items();
$nav_groups = Rifnote_Search_Pages::nav_groups();
$search_query = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : (isset($_GET['rsq']) ? sanitize_text_field(wp_unslash($_GET['rsq'])) : '');
$has_search_state = '' !== $search_query || !empty($_GET['s']) || !empty($_GET['category']) || !empty($_GET['date_range']) || !empty($_GET['sort']) || !empty($_GET['page']);
$is_search_home = in_array($mode, array('', 'app'), true) && !$has_search_state;
$dashboard_url = is_user_logged_in() ? home_url('/for-you/') : wp_login_url(home_url('/for-you/'));
$site_logo_url = esc_url(get_option('rifnote_site_logo_url', ''));
$site_logo_width = max(80, min(420, absint(get_option('rifnote_site_logo_width_desktop', 220))));
$site_icon_url = esc_url(get_site_icon_url(96));

if (!$site_icon_url) {
    $site_icon_url = esc_url(RIFNOTE_SEARCH_URL . 'public/rifnote-favicon.svg');
}

$mobile_categories = get_categories(array(
    'hide_empty' => false,
    'orderby' => 'name',
    'order' => 'ASC',
    'number' => 24,
));
$category_icon_for_slug = static function ($slug) {
    $slug = sanitize_key($slug);
    $icons = array(
        'notes' => '<svg viewBox="0 0 24 24" fill="none"><path d="M7 4h8l2 2v14H7z"/><path d="M15 4v4h4M9 11h6M9 15h6"/></svg>',
        'football' => '<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9"/><path d="m12 7 4 3-1.5 5h-5L8 10zM4 11l4-1M16 10l4 1M9.5 15 8 20M14.5 15l1.5 5"/></svg>',
        'sports' => '<svg viewBox="0 0 24 24" fill="none"><path d="M14 5a2 2 0 1 0-4 0 2 2 0 0 0 4 0ZM12 7l-2 5-4 2M12 7l3 4h4M10 12l4 3-1 5M9 13l-2 7"/></svg>',
        'world' => '<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9M12 3c-2.5 2.7-3.8 5.7-3.8 9s1.3 6.3 3.8 9"/></svg>',
        'technology' => '<svg viewBox="0 0 24 24" fill="none"><path d="M5 6h14v10H5zM8 20h8M10 16l-1 4M14 16l1 4"/></svg>',
        'tech' => '<svg viewBox="0 0 24 24" fill="none"><path d="M5 6h14v10H5zM8 20h8M10 16l-1 4M14 16l1 4"/></svg>',
        'health' => '<svg viewBox="0 0 24 24" fill="none"><path d="M12 20s-8-4.8-8-10a4.5 4.5 0 0 1 8-2.8A4.5 4.5 0 0 1 20 10c0 5.2-8 10-8 10Z"/><path d="M7 12h3l1-2 2 5 1-3h3"/></svg>',
        'science' => '<svg viewBox="0 0 24 24" fill="none"><path d="M9 3h6M10 3v5l-5 9a3 3 0 0 0 2.6 4.5h8.8A3 3 0 0 0 19 17l-5-9V3"/><path d="M7 16h10"/></svg>',
        'politics' => '<svg viewBox="0 0 24 24" fill="none"><path d="M4 10h16M6 10v8M10 10v8M14 10v8M18 10v8M4 20h16M12 4l8 4H4z"/></svg>',
        'business' => '<svg viewBox="0 0 24 24" fill="none"><path d="M4 19V9M10 19V5M16 19v-7M22 19H2"/><path d="m4 9 6-4 6 7 4-3"/></svg>',
        'entertainment' => '<svg viewBox="0 0 24 24" fill="none"><path d="M4 7c3-2 6-2 9 0v7c0 3-2 5-4.5 6C6 19 4 17 4 14z"/><path d="M13 8c2-1 5-1 7 0v6c0 3-2 5-4.5 6-.8-.3-1.5-.8-2.1-1.4M7 11h0M10 11h0M7 15c1 .8 2 .8 3 0"/></svg>',
        'opinion' => '<svg viewBox="0 0 24 24" fill="none"><path d="M4 5h16v11H8l-4 4z"/><path d="M8 9h8M8 13h5"/></svg>',
        'crime' => '<svg viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="6"/><path d="m16 16 4 4M11 7v8M7 11h8"/></svg>',
        'default' => '<svg viewBox="0 0 24 24" fill="none"><path d="M5 4h11l3 3v13H5z"/><path d="M16 4v4h4M8 11h8M8 15h8"/></svg>',
    );

    return $icons[$slug] ?? $icons['default'];
};
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <script>
        window.RIFNOTE_SEARCH = <?php echo wp_json_encode($plugin->runtime_context()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
    </script>
    <?php wp_head(); ?>
</head>
<body <?php body_class('rifnote-search-plugin-page'); ?>>
<?php wp_body_open(); ?>
<div class="rs-pwa-launch" role="status" aria-live="polite">
    <div class="rs-pwa-launch-card">
        <img src="<?php echo esc_url(Rifnote_Search_PWA::app_icon_url()); ?>" alt="" />
        <strong><?php esc_html_e('Rifnote', 'rifnote-search'); ?></strong>
        <span class="rs-pwa-launch-ring" aria-hidden="true"></span>
        <small><?php esc_html_e('Getting your feed ready', 'rifnote-search'); ?></small>
    </div>
</div>
<div class="rs-plugin-page">
    <header class="rs-plugin-header<?php echo $is_search_home ? ' is-search-home' : ''; ?><?php echo $site_logo_url ? ' has-site-logo' : ''; ?>" role="banner" style="--rs-desktop-logo-width: <?php echo esc_attr((string) $site_logo_width); ?>px;">
        <a class="rs-plugin-brand<?php echo $site_logo_url ? ' has-site-logo' : ''; ?>" href="<?php echo esc_url(home_url('/search/')); ?>">
            <?php if ($site_logo_url) : ?>
                <img class="rs-plugin-logo" src="<?php echo $site_logo_url; ?>" alt="<?php esc_attr_e('Rifnote Search', 'rifnote-search'); ?>" />
                <img class="rs-plugin-favicon" src="<?php echo $site_icon_url; ?>" alt="<?php esc_attr_e('Rifnote Search', 'rifnote-search'); ?>" />
            <?php else : ?>
                <span class="rs-plugin-mark" aria-hidden="true">R</span>
                <?php if ($site_icon_url) : ?>
                    <img class="rs-plugin-favicon" src="<?php echo $site_icon_url; ?>" alt="<?php esc_attr_e('Rifnote Search', 'rifnote-search'); ?>" />
                <?php endif; ?>
                <span>
                    <strong><?php esc_html_e('Rifnote Search', 'rifnote-search'); ?></strong>
                    <small><?php esc_html_e('News, scores, trends', 'rifnote-search'); ?></small>
                </span>
            <?php endif; ?>
        </a>
        <form class="rs-plugin-search" action="<?php echo esc_url(home_url('/search/')); ?>" method="get" role="search">
            <input name="q" type="search" value="<?php echo esc_attr($search_query); ?>" placeholder="<?php esc_attr_e('Search news and trends', 'rifnote-search'); ?>" />
            <button type="submit"><span class="rs-search-button-text"><?php esc_html_e('Search', 'rifnote-search'); ?></span><svg class="rs-search-button-icon" aria-hidden="true" viewBox="0 0 24 24" focusable="false"><circle cx="11" cy="11" r="7"></circle><path d="m16 16 4 4"></path></svg></button>
        </form>
        <div class="rs-plugin-actions">
            <?php require RIFNOTE_SEARCH_DIR . 'templates/partials/menu-drawer.php'; ?>
            <button class="rs-plugin-install" type="button" hidden><?php esc_html_e('Install App', 'rifnote-search'); ?></button>
            <a class="rs-plugin-login" href="<?php echo esc_url($dashboard_url); ?>" aria-label="<?php esc_attr_e('Open user dashboard', 'rifnote-search'); ?>">
                <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false">
                    <path d="M20 21a8 8 0 0 0-16 0" />
                    <circle cx="12" cy="7" r="4" />
                </svg>
                <b><?php esc_html_e('Account', 'rifnote-search'); ?></b>
            </a>
        </div>
    </header>

    <main class="rs-plugin-main" id="main">
        <?php echo $mount; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </main>

    <footer class="rs-plugin-footer" role="contentinfo">
        <p>&copy; <?php echo esc_html(gmdate('Y')); ?> <?php esc_html_e('Rifnote Search', 'rifnote-search'); ?></p>
        <nav aria-label="<?php esc_attr_e('Rifnote Search footer', 'rifnote-search'); ?>">
            <a href="<?php echo esc_url(home_url('/publisher-dashboard/')); ?>"><?php esc_html_e('Publisher Hub', 'rifnote-search'); ?></a>
            <a href="<?php echo esc_url(home_url('/submit-news/')); ?>"><?php esc_html_e('Send a Story', 'rifnote-search'); ?></a>
            <a href="<?php echo esc_url(home_url('/publisher-docs/')); ?>"><?php esc_html_e('Publisher Guide', 'rifnote-search'); ?></a>
            <a href="<?php echo esc_url(home_url('/beta-feedback/')); ?>"><?php esc_html_e('Beta Feedback', 'rifnote-search'); ?></a>
            <a href="<?php echo esc_url(home_url('/daily-briefing/')); ?>"><?php esc_html_e('Daily Drop', 'rifnote-search'); ?></a>
            <a href="<?php echo esc_url(home_url('/for-you/')); ?>"><?php esc_html_e('My Feed', 'rifnote-search'); ?></a>
        </nav>
    </footer>

    <nav class="rs-mobile-tabbar" aria-label="<?php esc_attr_e('Primary mobile navigation', 'rifnote-search'); ?>">
        <a href="<?php echo esc_url(home_url('/search/')); ?>"><span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 11 12 4l8 7"/><path d="M6 10v10h12V10"/></svg></span><b><?php esc_html_e('Home', 'rifnote-search'); ?></b></a>
        <a href="<?php echo esc_url(home_url('/category/notes/')); ?>"><span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M6 4h12v16H6z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg></span><b><?php esc_html_e('Notes', 'rifnote-search'); ?></b></a>
        <a href="<?php echo esc_url(home_url('/football/')); ?>"><span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9"/><path d="m12 7 4 3-1.5 5h-5L8 10zM4 11l4-1M16 10l4 1M9.5 15 8 20M14.5 15l1.5 5"/></svg></span><b><?php esc_html_e('Football', 'rifnote-search'); ?></b></a>
        <a class="is-live" href="#live-updates" data-rs-live-open><span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="4"/><path d="M4.9 4.9a10 10 0 0 0 0 14.2M19.1 4.9a10 10 0 0 1 0 14.2"/></svg></span><b><?php esc_html_e('LIVE', 'rifnote-search'); ?></b></a>
        <button class="rs-mobile-menu-button" type="button" data-rs-menu-open aria-label="<?php esc_attr_e('Open menu', 'rifnote-search'); ?>">
            <span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 7h16M4 12h16M4 17h16"/></svg></span>
            <b><?php esc_html_e('Menu', 'rifnote-search'); ?></b>
        </button>
    </nav>
    <script>
        document.addEventListener('click', function(event) {
            var opener = event.target.closest('[data-rs-live-open]');

            if (!opener) {
                return;
            }

            event.preventDefault();
            document.dispatchEvent(new CustomEvent('rifnote:open-live'));

            var rail = document.querySelector('.rs-live-rail');
            var backdrop = document.querySelector('.rs-live-drawer-backdrop');

            if (rail) {
                rail.classList.add('open');
            }

            if (backdrop) {
                backdrop.classList.add('open');
            }
        });

        document.addEventListener('click', function(event) {
            var tab = event.target.closest('[data-rs-mobile-tab]');

            if (!tab) {
                return;
            }

            var panel = tab.closest('.rs-mobile-menu-panel');

            if (!panel) {
                return;
            }

            var target = tab.getAttribute('data-rs-mobile-tab');

            panel.querySelectorAll('[data-rs-mobile-tab]').forEach(function(button) {
                var active = button === tab;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
            });

            panel.querySelectorAll('[data-rs-mobile-panel]').forEach(function(section) {
                section.classList.toggle('is-active', section.getAttribute('data-rs-mobile-panel') === target);
            });
        });
    </script>
</div>
<?php wp_footer(); ?>
</body>
</html>
