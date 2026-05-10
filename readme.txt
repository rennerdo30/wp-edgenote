=== Edgenote ===
Contributors: rennerdo30
Tags: cache, cloudflare, performance, cdn, cache-control, edge
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Cache-Control header helper for Cloudflare and other edge CDNs. Surgically overrides WordPress 6.8+'s aggressive nocache_headers on anonymous public requests.

== Description ==

WordPress 6.8 changed `wp_get_nocache_headers()` so it always emits `private, no-store, no-cache` — Cloudflare won't cache anything with those directives. Edgenote replaces the Cache-Control on requests that are actually safe to cache (anonymous, non-admin, non-REST, non-search, non-feed, non-404, GET/HEAD only) with a public, edge-cacheable directive that browsers still revalidate.

= Features =

* Surgical: only overrides on cacheable requests
* Hooks all three insertion points (`nocache_headers`, `wp_headers`, `send_headers`)
* Configurable s-maxage and stale-while-revalidate
* Sets `Vary: Accept-Encoding, Cookie` so authenticated content doesn't leak
* Removes the rogue `CDN-Cache-Control: no-store` header WordPress sometimes emits
* Single-purpose, < 400 lines, no dependencies
* MIT licensed

== Installation ==

1. Upload the `edgenote` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Visit Settings → Edgenote to tune the cache TTL and bypass lists.

== Frequently Asked Questions ==

= Will logged-in users see cached content? =

No. Edgenote skips any request with a `wordpress_logged_in_*` cookie, so the `wp-admin` bar and personalized markup stay fresh.

= Does this break the WP REST API? =

No. REST requests are skipped automatically.

== Changelog ==

= 0.1.0 =
* Initial release.
