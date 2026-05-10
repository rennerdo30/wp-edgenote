<?php

declare(strict_types=1);

namespace Edgenote;

use Edgenote\Admin\SettingsPage;
use Edgenote\Cloudflare\Purger;
use Edgenote\Cloudflare\SaveHook;

/**
 * Plugin orchestrator. Wires the request detector, the header overrider,
 * the Cloudflare purger, and the admin settings page.
 */
final class Plugin
{
    public const OPTION_KEY = 'edgenote_settings';

    public const DEFAULTS = [
        's_maxage'                 => 300,
        'stale_while_revalidate'   => 86400,
        'bypass_cookies'           => "wordpress_logged_in_\ncomment_\nwp-postpass_",
        'bypass_paths'             => "/wp-admin\n/wp-login.php\n/feed",
    ];

    public function boot(): void
    {
        load_plugin_textdomain('edgenote', false, dirname(plugin_basename(EDGENOTE_FILE)) . '/languages');

        $settings = self::settings();
        $request  = new CacheableRequest($settings);
        $override = new HeaderOverride($request, $settings);
        $override->register();

        $purger = new Purger();
        (new SaveHook($purger))->register();

        if (is_admin()) {
            (new SettingsPage($purger))->register();
        }
    }

    public static function on_activate(): void
    {
        if (false === get_option(self::OPTION_KEY)) {
            add_option(self::OPTION_KEY, self::DEFAULTS);
        }
    }

    /**
     * @return array{s_maxage:int, stale_while_revalidate:int, bypass_cookies:string, bypass_paths:string}
     */
    public static function settings(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        $merged = is_array($stored) ? array_merge(self::DEFAULTS, $stored) : self::DEFAULTS;

        $merged['s_maxage']               = max(0, (int) $merged['s_maxage']);
        $merged['stale_while_revalidate'] = max(0, (int) $merged['stale_while_revalidate']);
        $merged['bypass_cookies']         = (string) $merged['bypass_cookies'];
        $merged['bypass_paths']           = (string) $merged['bypass_paths'];

        return $merged;
    }
}
