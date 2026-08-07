<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_PWA {
    public static function manifest_url() {
        return home_url('/rifnote-search.webmanifest');
    }

    public static function service_worker_url() {
        return home_url('/rifnote-search-sw.js');
    }

    public static function offline_url() {
        return home_url('/rifnote-offline/');
    }

    public static function app_icon_url($size = 192) {
        $icon_url = get_site_icon_url($size);

        if (!$icon_url) {
            $icon_url = RIFNOTE_SEARCH_URL . 'public/rifnote-favicon.svg';
        }

        return esc_url_raw($icon_url);
    }

    public static function maybe_serve_asset() {
        $path = trim((string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');

        if ('rifnote-search.webmanifest' === $path) {
            self::serve_manifest();
        }

        if ('rifnote-search-sw.js' === $path) {
            self::serve_service_worker();
        }

        if ('rifnote-offline' === $path) {
            self::serve_offline();
        }
    }

    public static function print_head_tags() {
        if (!Rifnote_Search_Pages::mode_for_request() && !Rifnote_Search_Pages::should_use_public_shell()) {
            return;
        }

        echo '<link rel="manifest" href="' . esc_url(self::manifest_url()) . '">' . "\n";
        echo '<meta name="theme-color" content="#ed1c24">' . "\n";
        echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
        echo '<meta name="apple-mobile-web-app-title" content="Rifnote Search">' . "\n";
        echo '<link rel="apple-touch-icon" href="' . esc_url(self::app_icon_url()) . '">' . "\n";
        ?>
        <script>
        (function () {
          var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
          var ua = window.navigator.userAgent || '';
          var root = document.documentElement;

          root.classList.add('rs-pwa-capable');

          if (standalone) {
            root.classList.add('rs-pwa-standalone');
          }

          if (/iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)) {
            root.classList.add('rs-pwa-ios');
          }
        })();
        </script>
        <?php
    }

    public static function print_footer_script() {
        if (!Rifnote_Search_Pages::mode_for_request() && !Rifnote_Search_Pages::should_use_public_shell()) {
            return;
        }
        ?>
        <script>
        (function () {
          if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
              navigator.serviceWorker.register('<?php echo esc_js(self::service_worker_url()); ?>', { scope: '/' }).then(function (registration) {
                if ('sync' in registration) {
                  registration.sync.register('rifnote-background-refresh').catch(function () {});
                }
              }).catch(function () {});
            });
          }

          var installPrompt = null;
          var installButtons = Array.prototype.slice.call(document.querySelectorAll('.rs-plugin-install'));
          var dismissedKey = 'rifnote_pwa_cta_dismissed_v1';
          var ua = window.navigator.userAgent || '';
          var isIOS = /iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
          var isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
          var isMobile = window.matchMedia('(max-width: 720px)').matches;
          var mobileCta = null;
          var instructionSheet = null;
          var offlineToast = null;
          var appIconUrl = <?php echo wp_json_encode(self::app_icon_url()); ?>;

          function markAppReady() {
            document.documentElement.classList.add('rs-pwa-ready');
          }

          function setViewportHeight() {
            document.documentElement.style.setProperty('--rs-app-height', window.innerHeight + 'px');
          }

          setViewportHeight();
          window.addEventListener('resize', setViewportHeight);
          window.addEventListener('orientationchange', function () {
            window.setTimeout(setViewportHeight, 220);
          });
          window.addEventListener('load', function () {
            window.setTimeout(markAppReady, 260);
          });
          window.addEventListener('pageshow', function () {
            window.setTimeout(markAppReady, 260);
          });
          document.addEventListener('rifnote:app-ready', function () {
            window.setTimeout(markAppReady, 160);
          });
          window.setTimeout(markAppReady, 3600);

          function ensureOfflineToast() {
            if (offlineToast) {
              return offlineToast;
            }

            offlineToast = document.createElement('div');
            offlineToast.className = 'rs-pwa-network-toast';
            offlineToast.hidden = true;
            offlineToast.innerHTML = '<span></span><b></b>';
            document.body.appendChild(offlineToast);

            return offlineToast;
          }

          function setNetworkState() {
            var toast = ensureOfflineToast();

            if (navigator.onLine) {
              toast.hidden = true;
              document.documentElement.classList.remove('rs-pwa-offline');
              return;
            }

            document.documentElement.classList.add('rs-pwa-offline');
            toast.querySelector('b').textContent = 'You are offline. Saved Rifnote pages still open.';
            toast.hidden = false;
          }

          window.addEventListener('online', setNetworkState);
          window.addEventListener('offline', setNetworkState);
          setNetworkState();

          function escapeHtml(value) {
            return String(value).replace(/[&<>"']/g, function (char) {
              return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
            });
          }

          function iosBrowserProfile() {
            if (/CriOS/i.test(ua)) {
              return {
                name: 'Chrome',
                kicker: 'Chrome on iPhone',
                steps: [
                  'Tap the <strong>Share</strong> icon in Chrome.',
                  'Scroll the iOS share sheet and choose <strong>Add to Home Screen</strong>.',
                  'Tap <strong>Add</strong>. Rifnote will open like an app.'
                ],
                note: 'Chrome on iPhone still uses the iOS share sheet for Home Screen installs.'
              };
            }

            if (/FxiOS/i.test(ua)) {
              return {
                name: 'Firefox',
                kicker: 'Firefox on iPhone',
                steps: [
                  'Tap the Firefox <strong>menu</strong> or <strong>Share</strong> control.',
                  'Open the iOS share sheet and choose <strong>Add to Home Screen</strong>.',
                  'Tap <strong>Add</strong>. Rifnote will open like an app.'
                ],
                note: 'If you do not see the option, open this page in Safari and add it from there.'
              };
            }

            if (/EdgiOS/i.test(ua)) {
              return {
                name: 'Edge',
                kicker: 'Edge on iPhone',
                steps: [
                  'Tap the Edge <strong>menu</strong>, then tap <strong>Share</strong>.',
                  'Choose <strong>Add to Home Screen</strong> from the iOS share sheet.',
                  'Tap <strong>Add</strong>. Rifnote will open like an app.'
                ],
                note: 'Edge on iPhone uses Apple’s Home Screen install flow.'
              };
            }

            if (/OPiOS|OPT\//i.test(ua)) {
              return {
                name: 'Opera',
                kicker: 'Opera on iPhone',
                steps: [
                  'Tap the Opera <strong>menu</strong>, then open <strong>Share</strong>.',
                  'Choose <strong>Add to Home Screen</strong> from the iOS share sheet.',
                  'Tap <strong>Add</strong>. Rifnote will open like an app.'
                ],
                note: 'If the option is missing, Safari will always expose the iOS Home Screen action.'
              };
            }

            if (/DuckDuckGo/i.test(ua)) {
              return {
                name: 'DuckDuckGo',
                kicker: 'DuckDuckGo on iPhone',
                steps: [
                  'Tap the DuckDuckGo <strong>menu</strong>, then use <strong>Share</strong>.',
                  'Choose <strong>Add to Home Screen</strong> from the iOS share sheet.',
                  'Tap <strong>Add</strong>. Rifnote will open like an app.'
                ],
                note: 'Some privacy browsers hide parts of the share sheet; Safari is the fallback.'
              };
            }

            return {
              name: 'Safari',
              kicker: /Safari/i.test(ua) ? 'Safari on iPhone' : 'iPhone browser',
              steps: [
                'Tap the <strong>Share</strong> button in the browser toolbar.',
                'Choose <strong>Add to Home Screen</strong>.',
                'Tap <strong>Add</strong>. Rifnote will open like an app.'
              ],
              note: 'This is how iOS handles PWA installs. No App Store needed.'
            };
          }

          function hasDismissed() {
            try {
              return window.localStorage.getItem(dismissedKey) === '1';
            } catch (error) {
              return false;
            }
          }

          function dismissCta() {
            try {
              window.localStorage.setItem(dismissedKey, '1');
            } catch (error) {}

            if (mobileCta) {
              mobileCta.hidden = true;
            }
          }

          function setInstallButtonsVisible(visible) {
            installButtons.forEach(function (button) {
              button.hidden = !visible;
              button.textContent = isIOS ? 'Add to Home Screen' : 'Install App';
            });
          }

          function ensureInstructionSheet() {
            if (instructionSheet) {
              return instructionSheet;
            }

            instructionSheet = document.createElement('div');
            instructionSheet.className = 'rs-pwa-sheet';
            instructionSheet.hidden = true;
            instructionSheet.innerHTML = '<div class="rs-pwa-sheet-backdrop" data-rs-pwa-close></div><section class="rs-pwa-sheet-card" role="dialog" aria-modal="true" aria-label="Install Rifnote on iPhone"><button class="rs-pwa-sheet-close" type="button" data-rs-pwa-close aria-label="Close">×</button><div class="rs-pwa-sheet-brand"><img src="' + escapeHtml(appIconUrl) + '" alt="" loading="lazy" /><span class="rs-pwa-kicker"></span></div><h2>Add Rifnote to your Home Screen</h2><ol></ol><p></p></section>';
            document.body.appendChild(instructionSheet);
            instructionSheet.addEventListener('click', function (event) {
              if (event.target && event.target.hasAttribute('data-rs-pwa-close')) {
                instructionSheet.hidden = true;
              }
            });

            return instructionSheet;
          }

          function openIOSInstructions() {
            var sheet = ensureInstructionSheet();
            var profile = iosBrowserProfile();
            var list = sheet.querySelector('ol');

            sheet.querySelector('.rs-pwa-kicker').textContent = profile.kicker;
            sheet.querySelector('p').textContent = profile.note;
            list.innerHTML = profile.steps.map(function (step) {
              return '<li>' + step + '</li>';
            }).join('');
            sheet.hidden = false;
          }

          function openInstallExperience() {
            if (isStandalone) {
              return;
            }

            if (isIOS) {
              openIOSInstructions();
              return;
            }

            if (!installPrompt) {
              return;
            }

            installPrompt.prompt();
            installPrompt.userChoice.finally(function () {
              installPrompt = null;
              setInstallButtonsVisible(false);
              dismissCta();
            });
          }

          function ensureMobileCta() {
            if (!isMobile || isStandalone || hasDismissed()) {
              return;
            }

            if (mobileCta) {
              mobileCta.hidden = false;
              return;
            }

            mobileCta = document.createElement('aside');
            mobileCta.className = 'rs-pwa-mobile-cta';
            mobileCta.hidden = true;
            mobileCta.innerHTML = '<button class="rs-pwa-mobile-main" type="button"><span><img src="' + escapeHtml(appIconUrl) + '" alt="" loading="lazy" /></span><b>' + (isIOS ? 'Add Rifnote to Home Screen' : 'Install Rifnote') + '</b><small>' + (isIOS ? iosBrowserProfile().name + ' install steps' : 'Faster access, app feel') + '</small></button><button class="rs-pwa-mobile-close" type="button" aria-label="Dismiss install prompt">×</button>';
            document.body.appendChild(mobileCta);

            mobileCta.querySelector('.rs-pwa-mobile-main').addEventListener('click', openInstallExperience);
            mobileCta.querySelector('.rs-pwa-mobile-close').addEventListener('click', dismissCta);
            mobileCta.hidden = false;
          }

          window.addEventListener('beforeinstallprompt', function (event) {
            event.preventDefault();
            installPrompt = event;
            setInstallButtonsVisible(true);
            ensureMobileCta();
          });

          installButtons.forEach(function (button) {
            button.addEventListener('click', openInstallExperience);
          });

          if (isIOS && !isStandalone) {
            setInstallButtonsVisible(true);
            ensureMobileCta();
          }

          window.addEventListener('appinstalled', dismissCta);

          document.addEventListener('click', function (event) {
            var link = event.target.closest('a[href]');

            if (!link || link.target || link.hasAttribute('download')) {
              return;
            }

            try {
              var url = new URL(link.href, window.location.href);
              if (url.origin !== window.location.origin || url.hash && url.pathname === window.location.pathname) {
                return;
              }

              document.documentElement.classList.add('rs-pwa-navigating');
              window.setTimeout(function () {
                document.documentElement.classList.remove('rs-pwa-navigating');
              }, 2400);
            } catch (error) {}
          });
        })();
        </script>
        <?php
    }

    private static function serve_manifest() {
        $manifest = array(
            'name' => 'Rifnote Search',
            'short_name' => 'Rifnote',
            'id' => home_url('/search/'),
            'lang' => get_bloginfo('language') ?: 'en',
            'dir' => 'ltr',
            'description' => 'AI-powered news discovery for Rifnote.',
            'start_url' => home_url('/search/'),
            'scope' => home_url('/'),
            'display' => 'standalone',
            'display_override' => array('window-controls-overlay', 'standalone', 'minimal-ui'),
            'orientation' => 'portrait-primary',
            'background_color' => '#f7f8fb',
            'theme_color' => '#ed1c24',
            'categories' => array('news', 'sports', 'productivity'),
            'launch_handler' => array('client_mode' => 'focus-existing'),
            'icons' => array(
                array('src' => self::app_icon_url(192), 'sizes' => '192x192', 'purpose' => 'any maskable'),
                array('src' => self::app_icon_url(512), 'sizes' => '512x512', 'purpose' => 'any maskable'),
                array('src' => RIFNOTE_SEARCH_URL . 'public/rifnote-favicon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'),
            ),
            'shortcuts' => array(
                array('name' => 'Search', 'short_name' => 'Search', 'url' => home_url('/search/'), 'icons' => array(array('src' => self::app_icon_url(192), 'sizes' => '192x192'))),
                array('name' => 'Football', 'short_name' => 'Scores', 'url' => home_url('/football/'), 'icons' => array(array('src' => self::app_icon_url(192), 'sizes' => '192x192'))),
                array('name' => 'Weather', 'short_name' => 'Weather', 'url' => home_url('/weather/'), 'icons' => array(array('src' => self::app_icon_url(192), 'sizes' => '192x192'))),
                array('name' => 'My Feed', 'short_name' => 'Feed', 'url' => home_url('/for-you/'), 'icons' => array(array('src' => self::app_icon_url(192), 'sizes' => '192x192'))),
            ),
            'share_target' => array(
                'action' => home_url('/submit-news/'),
                'method' => 'GET',
                'params' => array(
                    'title' => 'title',
                    'text' => 'text',
                    'url' => 'url',
                ),
            ),
        );

        status_header(200);
        nocache_headers();
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo wp_json_encode($manifest);
        exit;
    }

    private static function serve_service_worker() {
        $css = Rifnote_Search_Plugin::asset('*.css');
        $js = Rifnote_Search_Plugin::asset('*.js');
        $static_urls = array_filter(array(
            self::offline_url(),
            home_url('/search/'),
            home_url('/football/'),
            home_url('/teams/'),
            home_url('/players/'),
            home_url('/transfers/'),
            home_url('/weather/'),
            home_url('/for-you/'),
            $css ? $css['url'] : '',
            $js ? $js['url'] : '',
            self::app_icon_url(),
            RIFNOTE_SEARCH_URL . 'public/rifnote-favicon.svg',
        ));
        $cache_name = 'rifnote-search-' . RIFNOTE_SEARCH_VERSION . '-' . md5(wp_json_encode($static_urls));

        status_header(200);
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Service-Worker-Allowed: /');
        ?>
const RIFNOTE_CACHE = <?php echo wp_json_encode($cache_name); ?>;
const RIFNOTE_STATIC_URLS = <?php echo wp_json_encode(array_values($static_urls)); ?>;
const RIFNOTE_OFFLINE_URL = <?php echo wp_json_encode(self::offline_url()); ?>;

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(RIFNOTE_CACHE)
      .then((cache) => cache.addAll(RIFNOTE_STATIC_URLS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key.startsWith('rifnote-search-') && key !== RIFNOTE_CACHE).map((key) => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

self.addEventListener('sync', (event) => {
  if (event.tag !== 'rifnote-background-refresh') {
    return;
  }

  event.waitUntil(
    caches.open(RIFNOTE_CACHE)
      .then((cache) => cache.addAll(RIFNOTE_STATIC_URLS))
      .catch(() => undefined)
  );
});

self.addEventListener('push', (event) => {
  let data = {};

  try {
    data = event.data ? event.data.json() : {};
  } catch (error) {
    data = { title: 'Rifnote update', body: event.data ? event.data.text() : '' };
  }

  const title = data.title || 'Rifnote update';
  const options = {
    body: data.body || 'Something fresh just landed on Rifnote.',
    icon: data.icon || <?php echo wp_json_encode(self::app_icon_url(192)); ?>,
    badge: data.badge || <?php echo wp_json_encode(self::app_icon_url(192)); ?>,
    tag: data.tag || 'rifnote-update',
    renotify: Boolean(data.renotify),
    data: {
      url: data.url || <?php echo wp_json_encode(home_url('/search/')); ?>
    }
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = event.notification.data && event.notification.data.url ? event.notification.data.url : <?php echo wp_json_encode(home_url('/search/')); ?>;

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientsList) => {
      for (const client of clientsList) {
        if (client.url === targetUrl && 'focus' in client) {
          return client.focus();
        }
      }

      if (self.clients.openWindow) {
        return self.clients.openWindow(targetUrl);
      }

      return undefined;
    })
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  if (request.method !== 'GET') {
    return;
  }

  if (url.pathname.startsWith('/wp-json/')) {
    event.respondWith(fetch(request));
    return;
  }

  if (url.origin === self.location.origin && (url.pathname.includes('/wp-content/plugins/rifnote-search/dist/') || url.pathname.includes('/wp-content/plugins/rifnote-search/public/'))) {
    event.respondWith(
      caches.match(request).then((cached) => cached || fetch(request).then((response) => {
        if (response && response.ok) {
          const copy = response.clone();
          caches.open(RIFNOTE_CACHE).then((cache) => cache.put(request, copy));
        }

        return response;
      }).catch(() => caches.match(RIFNOTE_OFFLINE_URL)))
    );
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          const copy = response.clone();
          caches.open(RIFNOTE_CACHE).then((cache) => cache.put(request, copy));
          return response;
        })
        .catch(() => caches.match(request).then((cached) => cached || caches.match(RIFNOTE_OFFLINE_URL)))
    );
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) {
        return cached;
      }

      return fetch(request).then((response) => {
        if (response && response.ok && new URL(request.url).origin === self.location.origin) {
          const copy = response.clone();
          caches.open(RIFNOTE_CACHE).then((cache) => cache.put(request, copy));
        }

        return response;
      });
    })
  );
});
        <?php
        exit;
    }

    private static function serve_offline() {
        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="theme-color" content="#ed1c24" />
    <title>Rifnote Search Offline</title>
    <style>
        body{margin:0;background:#f7f8fb;color:#111827;font-family:Roboto,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        h1{font-family:"Google Sans","Product Sans",Roboto,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        main{display:grid;min-height:100vh;place-items:center;padding:24px}
        section{max-width:560px;border:1px solid #dfe4ec;border-radius:24px;background:#fff;box-shadow:0 12px 34px rgb(17 24 39 / 10%);padding:32px}
        span{display:inline-flex;border-radius:999px;background:#f3f5f8;color:#ed1c24;font-weight:800;padding:8px 12px}
        h1{font-size:clamp(2rem,8vw,4rem);line-height:1;margin:20px 0 12px}
        p{color:#667085;line-height:1.6}
        a{display:inline-flex;margin-top:12px;border-radius:999px;background:#ed1c24;color:#fff;font-weight:900;padding:12px 16px;text-decoration:none}
    </style>
</head>
<body>
    <main>
        <section>
            <span>Offline</span>
            <h1>Rifnote Search is ready when your connection returns.</h1>
            <p>You can reopen cached Rifnote Search pages while offline. Search results and fresh stories need an internet connection.</p>
            <a href="<?php echo esc_url(home_url('/search/')); ?>">Try again</a>
        </section>
    </main>
</body>
</html>
        <?php
        exit;
    }
}
