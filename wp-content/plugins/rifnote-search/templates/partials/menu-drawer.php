<?php

if (!defined('ABSPATH')) {
    exit;
}

$rifnote_menu_categories = isset($mobile_categories) && is_array($mobile_categories) ? $mobile_categories : get_categories(array(
    'hide_empty' => false,
    'orderby' => 'name',
    'order' => 'ASC',
    'number' => 48,
));

$rifnote_menu_groups = isset($nav_groups) && is_array($nav_groups) ? $nav_groups : array();

if (!$rifnote_menu_groups && class_exists('Rifnote_Search_Pages')) {
    $rifnote_menu_groups = Rifnote_Search_Pages::nav_groups();
}

$rifnote_dashboard_url = isset($dashboard_url) ? $dashboard_url : (is_user_logged_in() ? home_url('/for-you/') : wp_login_url(home_url('/for-you/')));
$rifnote_site_icon_url = isset($site_icon_url) && $site_icon_url ? $site_icon_url : get_site_icon_url(96);

if (!$rifnote_site_icon_url && defined('RIFNOTE_SEARCH_URL')) {
    $rifnote_site_icon_url = RIFNOTE_SEARCH_URL . 'public/rifnote-favicon.svg';
}

$rifnote_category_icon = isset($category_icon_for_slug) && is_callable($category_icon_for_slug)
    ? $category_icon_for_slug
    : static function () {
        return '<svg viewBox="0 0 24 24" fill="none"><path d="M5 4h11l3 3v13H5z"/><path d="M16 4v4h4M8 11h8M8 15h8"/></svg>';
    };

$rifnote_menu_icon = static function ($label) use ($rifnote_category_icon) {
    $key = sanitize_key($label);

    if (false !== strpos($key, 'home')) {
        return '<svg viewBox="0 0 24 24" fill="none"><path d="M4 11 12 4l8 7"/><path d="M6 10v10h12V10"/></svg>';
    }

    if (false !== strpos($key, 'search') || false !== strpos($key, 'explore')) {
        return '<svg viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7"/><path d="m16 16 4 4"/></svg>';
    }

    if (false !== strpos($key, 'publisher') || false !== strpos($key, 'story') || false !== strpos($key, 'article')) {
        return '<svg viewBox="0 0 24 24" fill="none"><path d="M6 4h12v16H6z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg>';
    }

    if (false !== strpos($key, 'advert') || false !== strpos($key, 'campaign')) {
        return '<svg viewBox="0 0 24 24" fill="none"><path d="M4 13h4l9 5V6L8 11H4z"/><path d="M8 13v5"/></svg>';
    }

    if (false !== strpos($key, 'account') || false !== strpos($key, 'profile') || false !== strpos($key, 'feed')) {
        return '<svg viewBox="0 0 24 24" fill="none"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg>';
    }

    if (false !== strpos($key, 'football') || false !== strpos($key, 'sport')) {
        return $rifnote_category_icon('football');
    }

    if (false !== strpos($key, 'support') || false !== strpos($key, 'feedback')) {
        return '<svg viewBox="0 0 24 24" fill="none"><path d="M4 5h16v11H8l-4 4z"/><path d="M8 9h8M8 13h5"/></svg>';
    }

    if (false !== strpos($key, 'legal') || false !== strpos($key, 'dmca') || false !== strpos($key, 'privacy')) {
        return '<svg viewBox="0 0 24 24" fill="none"><path d="M12 3 5 6v6c0 4.5 3 7.5 7 9 4-1.5 7-4.5 7-9V6z"/></svg>';
    }

    return '<svg viewBox="0 0 24 24" fill="none"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg>';
};

$rifnote_menu_group_ids = array();
?>
<button class="rs-plugin-menu-trigger" type="button" data-rs-menu-open aria-label="<?php esc_attr_e('Open navigation menu', 'rifnote-search'); ?>">
    <span></span><span></span><span></span>
</button>
<div class="rs-menu-overlay" data-rs-menu-overlay hidden></div>
<aside class="rs-menu-drawer" data-rs-menu-drawer aria-hidden="true" aria-label="<?php esc_attr_e('Rifnote menu', 'rifnote-search'); ?>">
    <div class="rs-menu-drawer-head">
        <button class="rs-menu-back" type="button" data-rs-menu-back hidden aria-label="<?php esc_attr_e('Back to menu', 'rifnote-search'); ?>">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
            <span><?php esc_html_e('Back', 'rifnote-search'); ?></span>
        </button>
        <span class="rs-menu-head-spacer" aria-hidden="true"></span>
        <button class="rs-menu-close" type="button" data-rs-menu-close aria-label="<?php esc_attr_e('Close navigation menu', 'rifnote-search'); ?>">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
        </button>
    </div>

    <div class="rs-menu-scroll">
        <section class="rs-menu-view is-active" data-rs-menu-view="home">
            <div class="rs-menu-section">
                <a class="rs-menu-row" href="<?php echo esc_url(home_url('/search/')); ?>">
                    <span class="rs-menu-row-icon" aria-hidden="true"><?php echo $rifnote_menu_icon('Home'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                    <b><?php esc_html_e('Home', 'rifnote-search'); ?></b>
                </a>
                <button class="rs-menu-row" type="button" data-rs-menu-submenu="categories">
                    <span class="rs-menu-row-icon" aria-hidden="true"><?php echo $rifnote_category_icon('top-headlines'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                    <b><?php esc_html_e('Categories', 'rifnote-search'); ?></b>
                    <i aria-hidden="true">›</i>
                </button>
                <a class="rs-menu-row" href="<?php echo esc_url(home_url('/football/')); ?>">
                    <span class="rs-menu-row-icon" aria-hidden="true"><?php echo $rifnote_menu_icon('Football'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                    <b><?php esc_html_e('Football', 'rifnote-search'); ?></b>
                </a>
                <?php foreach ($rifnote_menu_groups as $index => $group) : ?>
                    <?php
                    $group_label = isset($group['label']) ? (string) $group['label'] : __('More', 'rifnote-search');
                    $group_key = sanitize_key($group_label);
                    if (false !== strpos($group_key, 'football') || false !== strpos($group_key, 'sport')) {
                        continue;
                    }
                    $group_id = sanitize_title($group_label);
                    $group_id = $group_id ? $group_id : 'group';
                    $group_id .= '-' . absint($index);
                    $rifnote_menu_group_ids[] = array(
                        'id' => $group_id,
                        'label' => $group_label,
                        'items' => isset($group['items']) && is_array($group['items']) ? $group['items'] : array(),
                    );
                    ?>
                    <button class="rs-menu-row" type="button" data-rs-menu-submenu="<?php echo esc_attr($group_id); ?>">
                        <span class="rs-menu-row-icon" aria-hidden="true"><?php echo $rifnote_menu_icon($group_label); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                        <b><?php echo esc_html($group_label); ?></b>
                        <i aria-hidden="true">›</i>
                    </button>
                <?php endforeach; ?>
                <a class="rs-menu-row" href="<?php echo esc_url($rifnote_dashboard_url); ?>">
                    <span class="rs-menu-row-icon" aria-hidden="true"><?php echo $rifnote_menu_icon('Account'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                    <b><?php esc_html_e('Account', 'rifnote-search'); ?></b>
                </a>
            </div>
        </section>

        <section class="rs-menu-view" data-rs-menu-view="categories">
            <div class="rs-menu-section">
                <h2><?php esc_html_e('Categories', 'rifnote-search'); ?></h2>
                <div class="rs-menu-category-list">
                    <?php foreach ($rifnote_menu_categories as $category) : ?>
                        <a class="rs-menu-row" href="<?php echo esc_url(get_category_link($category)); ?>">
                            <span class="rs-menu-row-icon" aria-hidden="true"><?php echo $rifnote_category_icon($category->slug); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                            <b><?php echo esc_html($category->name); ?></b>
                            <i aria-hidden="true">›</i>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <?php foreach ($rifnote_menu_group_ids as $group) : ?>
            <section class="rs-menu-view" data-rs-menu-view="<?php echo esc_attr($group['id']); ?>">
                <div class="rs-menu-section">
                    <h2><?php echo esc_html($group['label']); ?></h2>
                    <?php foreach ($group['items'] as $item) : ?>
                        <a class="rs-menu-row" href="<?php echo esc_url($item['url'] ?? '#'); ?>">
                            <span class="rs-menu-row-icon" aria-hidden="true"><?php echo $rifnote_menu_icon($item['label'] ?? ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                            <b><?php echo esc_html($item['label'] ?? __('Menu item', 'rifnote-search')); ?></b>
                            <i aria-hidden="true">›</i>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</aside>
<script>
    (function() {
        if (window.__rifnoteMenuDrawerBound) {
            return;
        }

        window.__rifnoteMenuDrawerBound = true;

        function drawer() {
            return document.querySelector('[data-rs-menu-drawer]');
        }

        function overlay() {
            return document.querySelector('[data-rs-menu-overlay]');
        }

        function portalDrawer() {
            var panel = drawer();
            var shade = overlay();

            if (panel && panel.parentNode !== document.body) {
                document.body.appendChild(panel);
            }

            if (shade && shade.parentNode !== document.body) {
                document.body.appendChild(shade);
            }
        }

        function setView(name) {
            portalDrawer();
            var panel = drawer();
            if (!panel) return;
            var target = name || 'home';
            panel.querySelectorAll('[data-rs-menu-view]').forEach(function(view) {
                view.classList.toggle('is-active', view.getAttribute('data-rs-menu-view') === target);
            });
            var back = panel.querySelector('[data-rs-menu-back]');
            if (back) {
                if (target === 'home') {
                    back.setAttribute('hidden', 'hidden');
                } else {
                    back.removeAttribute('hidden');
                }
            }
        }

        function setOpen(open) {
            portalDrawer();
            var panel = drawer();
            var shade = overlay();
            if (!panel || !shade) return;

            if (open) {
                shade.hidden = false;
                requestAnimationFrame(function() {
                    panel.classList.add('is-open');
                    shade.classList.add('is-open');
                    document.documentElement.classList.add('rs-menu-is-open');
                });
                panel.setAttribute('aria-hidden', 'false');
                setView('home');
                return;
            }

            panel.classList.remove('is-open');
            shade.classList.remove('is-open');
            document.documentElement.classList.remove('rs-menu-is-open');
            panel.setAttribute('aria-hidden', 'true');
            setTimeout(function() {
                if (!shade.classList.contains('is-open')) {
                    shade.hidden = true;
                }
            }, 220);
        }

        document.addEventListener('rifnote:open-menu', function() {
            setOpen(true);
        });

        document.addEventListener('click', function(event) {
            if (event.target.closest('[data-rs-menu-open]')) {
                event.preventDefault();
                setOpen(true);
                return;
            }

            if (event.target.closest('[data-rs-menu-close]') || event.target === overlay()) {
                event.preventDefault();
                setOpen(false);
                return;
            }

            if (event.target.closest('[data-rs-menu-back]')) {
                event.preventDefault();
                setView('home');
                return;
            }

            var submenu = event.target.closest('[data-rs-menu-submenu]');
            if (submenu) {
                event.preventDefault();
                setView(submenu.getAttribute('data-rs-menu-submenu'));
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        });

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', portalDrawer, { once: true });
        } else {
            portalDrawer();
        }
    })();
</script>
