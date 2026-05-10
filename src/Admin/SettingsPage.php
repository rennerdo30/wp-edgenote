<?php

declare(strict_types=1);

namespace Edgenote\Admin;

use Edgenote\Plugin;

/**
 * Settings → Edgenote.
 *
 * Lets the operator tune s-maxage, stale-while-revalidate, the cookie
 * bypass list, and the path bypass list. Includes a "Test headers"
 * button that fires a HEAD request to the home URL and shows the
 * actual Cache-Control + Vary the public sees.
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
        add_options_page(
            __('Edgenote', 'edgenote'),
            __('Edgenote', 'edgenote'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
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
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Edgenote', 'edgenote'); ?></h1>
            <p class="description">
                <?php esc_html_e('Surgically overrides WordPress 6.8+ Cache-Control headers on anonymous public requests so Cloudflare (and other edge CDNs) can actually cache your site.', 'edgenote'); ?>
            </p>

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
            <h2><?php esc_html_e('Test live headers', 'edgenote'); ?></h2>
            <p><?php esc_html_e('Fire a HEAD request to the home URL and show the actual Cache-Control / Vary headers the public receives.', 'edgenote'); ?></p>
            <p>
                <button type="button" class="button button-secondary" id="edgenote-test-button" data-nonce="<?php echo esc_attr($nonce); ?>"><?php esc_html_e('Test headers', 'edgenote'); ?></button>
            </p>
            <pre id="edgenote-test-output" style="background:#0b0b0b;color:#dfe3e6;padding:14px;border-radius:6px;min-height:60px;overflow:auto;"><?php esc_html_e('(no result yet)', 'edgenote'); ?></pre>

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
