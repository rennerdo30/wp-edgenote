<?php
/**
 * Plugin Name: Edgenote
 * Plugin URI: https://github.com/rennerdo30/wp-edgenote
 * Description: Cache-Control header helper for Cloudflare and other edge CDNs. Surgically overrides WordPress 6.8+'s aggressive nocache_headers on anonymous public requests. MIT.
 * Version: 0.1.0
 * Author: Renner
 * Author URI: https://renner.dev
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: edgenote
 * Requires at least: 6.5
 * Requires PHP: 8.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('EDGENOTE_VERSION', '0.1.0');
define('EDGENOTE_FILE', __FILE__);
define('EDGENOTE_DIR', plugin_dir_path(__FILE__));
define('EDGENOTE_URL', plugin_dir_url(__FILE__));

// Lightweight PSR-4 autoloader for the Edgenote namespace.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Edgenote\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = EDGENOTE_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

add_action('plugins_loaded', static function (): void {
    (new \Edgenote\Plugin())->boot();
});

register_activation_hook(__FILE__, static function (): void {
    \Edgenote\Plugin::on_activate();
});
