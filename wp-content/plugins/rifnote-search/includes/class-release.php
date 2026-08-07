<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Release {
    public static function readiness() {
        $checks = array();
        $delivery = Rifnote_Search_Delivery::delivery_health();
        $index = Rifnote_Search_Index::health();
        $launch = Rifnote_Search_Hardening::launch_report();

        $checks[] = self::check('PWA manifest', class_exists('Rifnote_Search_PWA') && (bool) Rifnote_Search_PWA::manifest_url(), __('Manifest endpoint is available for installable web app surfaces.', 'rifnote-search'));
        $checks[] = self::check('Service worker', class_exists('Rifnote_Search_PWA') && (bool) Rifnote_Search_PWA::service_worker_url(), __('Service worker endpoint is available for offline shell and app handoff.', 'rifnote-search'));
        $checks[] = self::check('Built frontend', (bool) Rifnote_Search_Plugin::asset('*.js'), __('Frontend bundle is built and ready to enqueue.', 'rifnote-search'));
        $checks[] = self::check('Search index', !empty($index['indexed_posts']), sprintf(__('Indexed posts: %d.', 'rifnote-search'), (int) $index['indexed_posts']));
        $checks[] = self::check('Email delivery', !empty($delivery['email_ready']), sprintf(__('Email provider: %s.', 'rifnote-search'), $delivery['email_provider']));
        $checks[] = self::check('Push handoff', !empty($delivery['push_ready']), sprintf(__('Push provider: %s.', 'rifnote-search'), $delivery['push_provider']));
        $checks[] = self::check('Native iOS ID', (bool) get_option('rifnote_native_ios_bundle_id', ''), __('Set the iOS bundle ID before App Store packaging.', 'rifnote-search'), 'warn');
        $checks[] = self::check('Native Android ID', (bool) get_option('rifnote_native_android_package', ''), __('Set the Android package name before Play Store packaging.', 'rifnote-search'), 'warn');
        $checks[] = self::check('Search page', (bool) get_page_by_path('search', OBJECT, 'page'), __('Search page exists and is managed by the plugin shell.', 'rifnote-search'));
        $checks[] = self::check('Advertise page', (bool) get_page_by_path('advertise', OBJECT, 'page'), __('Advertise page exists for sponsor intake.', 'rifnote-search'));

        foreach ($launch as $launch_check) {
            $checks[] = array(
                'label' => 'Launch: ' . $launch_check['label'],
                'status' => $launch_check['status'],
                'detail' => $launch_check['detail'],
            );
        }

        $fail = count(array_filter($checks, function ($check) {
            return 'fail' === $check['status'];
        }));
        $warn = count(array_filter($checks, function ($check) {
            return 'warn' === $check['status'];
        }));

        return array(
            'status' => $fail ? 'blocked' : ($warn ? 'ready_with_warnings' : 'ready'),
            'failures' => $fail,
            'warnings' => $warn,
            'checks' => $checks,
            'native' => array(
                'ios_bundle_id' => get_option('rifnote_native_ios_bundle_id', ''),
                'android_package' => get_option('rifnote_native_android_package', ''),
                'release_notes' => get_option('rifnote_release_notes', ''),
            ),
        );
    }

    private static function check($label, $passed, $detail, $warning_status = 'fail') {
        return array(
            'label' => $label,
            'status' => $passed ? 'pass' : $warning_status,
            'detail' => $detail,
        );
    }
}
