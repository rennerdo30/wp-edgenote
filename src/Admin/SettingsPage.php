<?php

declare(strict_types=1);

namespace Edgenote\Admin;

use Edgenote\CacheableRequest;
use Edgenote\HeaderOverride;
use Edgenote\Plugin;

/**
 * Edgenote → top-level admin menu.
 *
 * Lets the operator tune s-maxage, stale-while-revalidate, the cookie
 * bypass list, and the path bypass list. Adds an "Overview" panel that
 * shows the resolved Cache-Control / Vary values, the active bypass
 * conditions, and a "Test headers" button that fires a HEAD request to
 * the home URL to surface the actual response the public sees.
 */
final class SettingsPage
{
    private const PAGE_SLUG  = 'edgenote';
    private const NONCE      = 'edgenote_test_headers';

    public function register(): void
    {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('wp_ajax_edgenote_test_headers', [$this, 'ajax_test_headers']);
    }

    public function register_settings(): void
    {
        register_setting('edgenote_group', Plugin::OPTION_KEY, [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default'           => Plugin::DEFAULTS,
        ]);
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('Edgenote', 'edgenote'),
            __('Edgenote', 'edgenote'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render'],
            'dashicons-cloud',
            81
        );
    }

    /** @param array<string,mixed> $input */
    public function sanitize(array $input): array
    {
        return [
            's_maxage'               => max(0, (int) ($input['s_maxage'] ?? 300)),
            'stale_while_revalidate' => max(0, (int) ($input['stale_while_revalidate'] ?? 86400)),
            'bypass_cookies'         => sanitize_textarea_field((string) ($input['bypass_cookies'] ?? '')),
            'bypass_paths'           => sanitize_textarea_field((string) ($input['bypass_paths'] ?? '')),
        ];
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = Plugin::settings();
        $nonce    = wp_create_nonce(self::NONCE);
        $request  = new CacheableRequest($settings);
        $override = new HeaderOverride($request, $settings);

        $bypass_cookies = array_values(array_filter(array_map(
            'trim',
            preg_split('/\r\n|\r|\n/', (string) $settings['bypass_cookies']) ?: []
        )));
        $bypass_paths = array_values(array_filter(array_map(
            'trim',
            preg_split('/\r\n|\r|\n/', (string) $settings['bypass_paths']) ?: []
        )));

        // Static list of every condition that suppresses Edgenote.
        $always_bypassed = [
            __('wp-admin requests',           'edgenote'),
            __('REST API requests',           'edgenote'),
            __('AJAX requests',               'edgenote'),
            __('Logged-in users',             'edgenote'),
            __('Search results',              'edgenote'),
            __('RSS / Atom feeds',            'edgenote'),
            __('404 responses',               'edgenote'),
            __('Preview / draft requests',    'edgenote'),
            __('Non-GET / non-HEAD methods',  'edgenote'),
        ];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Edgenote', 'edgenote'); ?></h1>
            <p class="description">
                <?php esc_html_e('Surgically overrides WordPress 6.8+ Cache-Control headers on anonymous public requests so Cloudflare (and other edge CDNs) can actually cache your site.', 'edgenote'); ?>
            </p>

            <h2 class="title"><?php esc_html_e('Overview', 'edgenote'); ?></h2>
            <table class="widefat striped" style="max-width:780px;">
                <tbody>
                    <tr>
                        <th scope="row" style="width:220px;"><?php esc_html_e('Cache-Control (resolved)', 'edgenote'); ?></th>
                        <td><code><?php echo esc_html($override->cache_control_value()); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Vary (resolved)', 'edgenote'); ?></th>
                        <td><code><?php echo esc_html($override->vary_value()); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Edge TTL (s-maxage)', 'edgenote'); ?></th>
                        <td><?php echo esc_html((string) $settings['s_maxage']); ?>s</td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Stale-while-revalidate', 'edgenote'); ?></th>
                        <td><?php echo esc_html((string) $settings['stale_while_revalidate']); ?>s</td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Always-bypass conditions', 'edgenote'); ?></th>
                        <td>
                            <?php foreach ($always_bypassed as $label): ?>
                                <span class="edgenote-chip" style="display:inline-block;margin:2px 4px 2px 0;padding:2px 8px;background:#f0f0f1;border-radius:10px;font-size:12px;"><?php echo esc_html($label); ?></span>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Bypass cookies', 'edgenote'); ?></th>
                        <td>
                            <?php if ($bypass_cookies === []): ?>
                                <em><?php esc_html_e('(none)', 'edgenote'); ?></em>
                            <?php else: ?>
                                <?php foreach ($bypass_cookies as $c): ?>
                                    <code style="margin-right:6px;"><?php echo esc_html($c); ?></code>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Bypass paths', 'edgenote'); ?></th>
                        <td>
                            <?php if ($bypass_paths === []): ?>
                                <em><?php esc_html_e('(none)', 'edgenote'); ?></em>
                            <?php else: ?>
                                <?php foreach ($bypass_paths as $p): ?>
                                    <code style="margin-right:6px;"><?php echo esc_html($p); ?></code>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p style="margin-top:12px;">
                <button type="button" class="button button-secondary" id="edgenote-test-button" data-nonce="<?php echo esc_attr($nonce); ?>"><?php esc_html_e('Test live headers', 'edgenote'); ?></button>
                <span class="description"><?php esc_html_e('Fire a HEAD request to the home URL and show the actual response.', 'edgenote'); ?></span>
            </p>
            <pre id="edgenote-test-output" style="background:#0b0b0b;color:#dfe3e6;padding:14px;border-radius:6px;min-height:60px;overflow:auto;max-width:780px;"><?php esc_html_e('(no result yet)', 'edgenote'); ?></pre>

            <hr>
            <h2 class="title"><?php esc_html_e('Settings', 'edgenote'); ?></h2>
            <form action="options.php" method="post">
                <?php settings_fields('edgenote_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="edgenote-s-maxage"><?php esc_html_e('Edge cache TTL (s-maxage)', 'edgenote'); ?></label></th>
                        <td>
                            <input id="edgenote-s-maxage" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[s_maxage]" type="number" min="0" value="<?php echo esc_attr((string) $settings['s_maxage']); ?>" class="small-text" />
                            <p class="description"><?php esc_html_e('Seconds the edge (Cloudflare) may serve a cached copy. Default 300.', 'edgenote'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="edgenote-swr"><?php esc_html_e('Stale-while-revalidate', 'edgenote'); ?></label></th>
                        <td>
                            <input id="edgenote-swr" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[stale_while_revalidate]" type="number" min="0" value="<?php echo esc_attr((string) $settings['stale_while_revalidate']); ?>" class="small-text" />
                            <p class="description"><?php esc_html_e('Seconds the edge may serve stale while refreshing in the background. Default 86400.', 'edgenote'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="edgenote-bypass-cookies"><?php esc_html_e('Bypass cookies', 'edgenote'); ?></label></th>
                        <td>
                            <textarea id="edgenote-bypass-cookies" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[bypass_cookies]" rows="4" cols="50"><?php echo esc_textarea((string) $settings['bypass_cookies']); ?></textarea>
                            <p class="description"><?php esc_html_e('One cookie name prefix per line. Any request carrying a cookie with one of these prefixes is treated as authenticated and skipped.', 'edgenote'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="edgenote-bypass-paths"><?php esc_html_e('Bypass paths', 'edgenote'); ?></label></th>
                        <td>
                            <textarea id="edgenote-bypass-paths" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[bypass_paths]" rows="4" cols="50"><?php echo esc_textarea((string) $settings['bypass_paths']); ?></textarea>
                            <p class="description"><?php esc_html_e('One URL fragment per line. Matching requests are skipped.', 'edgenote'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr>
            <h2><?php esc_html_e('Cloudflare setup', 'edgenote'); ?></h2>
            <ol>
                <li><?php echo wp_kses_post(__('In Cloudflare → Caching → Configuration, enable <strong>Respect Existing Headers</strong>.', 'edgenote')); ?></li>
                <li><?php echo wp_kses_post(__('Or add a Page Rule with <strong>Edge Cache TTL</strong> set to a value of your choosing.', 'edgenote')); ?></li>
                <li><?php esc_html_e('Verify with the Test button above — Cache-Control should read public, max-age=0, s-maxage=…', 'edgenote'); ?></li>
            </ol>

            <script>
            (function () {
                var btn = document.getElementById('edgenote-test-button');
                var out = document.getElementById('edgenote-test-output');
                if (!btn) return;
                btn.addEventListener('click', function () {
                    out.textContent = '...';
                    var fd = new FormData();
                    fd.append('action', 'edgenote_test_headers');
                    fd.append('_wpnonce', btn.dataset.nonce);
                    fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd })
                        .then(function (r) { return r.json(); })
                        .then(function (j) {
                            if (j && j.success) {
                                out.textContent = j.data.headers || '(empty)';
                            } else {
                                out.textContent = (j && j.data && j.data.message) ? j.data.message : 'Error';
                            }
                        })
                        .catch(function (e) { out.textContent = String(e); });
                });
            })();
            </script>
        </div>
        <?php
    }

    public function ajax_test_headers(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Forbidden', 'edgenote')], 403);
        }
        check_ajax_referer(self::NONCE);

        $url      = home_url('/');
        $response = wp_remote_head($url, [
            'timeout'     => 5,
            'redirection' => 2,
            'sslverify'   => false,
            'headers'     => ['User-Agent' => 'Edgenote/' . EDGENOTE_VERSION . ' (header-test)'],
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $headers = wp_remote_retrieve_headers($response);
        $code    = (int) wp_remote_retrieve_response_code($response);
        $lines   = ['HEAD ' . $url, 'Status: ' . $code, ''];
        foreach ((array) $headers->getAll() as $k => $v) {
            $lines[] = $k . ': ' . (is_array($v) ? implode(', ', $v) : (string) $v);
        }
        wp_send_json_success(['headers' => implode("\n", $lines)]);
    }
}
