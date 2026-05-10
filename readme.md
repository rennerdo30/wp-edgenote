# Edgenote

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP 8.1+](https://img.shields.io/badge/php-8.1%2B-777bb4.svg)](https://www.php.net/)
[![WordPress 6.5+](https://img.shields.io/badge/wordpress-6.5%2B-21759b.svg)](https://wordpress.org/)

> **WordPress 6.8+ kills your edge cache. Edgenote brings it back.**

A surgical Cache-Control header helper for Cloudflare and other edge CDNs.

## The problem

WordPress 6.8 changed `wp_get_nocache_headers()` so it now **always** emits:

```
Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private
```

— regardless of whether the request is anonymous or authenticated. Cloudflare (and any well-behaved shared cache) refuses to cache anything containing `private` or `no-store`. The result: every visitor hits the origin every time, TTFB craters, and the "edge cache" is decorative.

The header gets emitted from at least three different places in core (`nocache_headers` filter, `wp_headers` filter, and direct `header()` calls in `send_headers`), so a half-fix that only hooks one of them gets quietly clobbered by the other two.

## The fix

Edgenote hooks **all three** insertion points and runs at low priority so it always wins:

1. `nocache_headers` filter (priority 99) — replaces `Cache-Control`, drops `Expires` / `Pragma`, removes `CDN-Cache-Control`.
2. `wp_headers` filter (priority 99) — replaces `Cache-Control`, sets `Vary: Accept-Encoding, Cookie`, removes `CDN-Cache-Control`.
3. `send_headers` action (priority 999) — explicit `header(..., true)` call as the final word, plus `header_remove('CDN-Cache-Control')` for the rogue header WP 6.8 sometimes emits direct.

Only fires on requests that are **actually safe to cache**:

- Anonymous (no logged-in user, no `wordpress_logged_in_*` / `comment_*` / `wp-postpass_*` cookies)
- `GET` or `HEAD` only
- Not admin, AJAX, REST, search, feed, 404, or preview
- Path doesn't match the bypass list (`/wp-admin`, `/wp-login.php`, `/feed`)

Emitted Cache-Control on a cacheable request:

```
Cache-Control: public, max-age=0, s-maxage=300, stale-while-revalidate=86400
Vary: Accept-Encoding, Cookie
```

`max-age=0` means the **browser** never caches (so a logged-in admin who returns to a page after login still gets fresh content). `s-maxage` means the **edge** caches for 5 minutes by default. `stale-while-revalidate` lets the edge serve stale while refreshing.

## Quick start

1. Drop the plugin into `wp-content/plugins/edgenote/` and activate (or zip-install).
2. Defaults are sane: 5-minute edge TTL, 1-day stale-while-revalidate.
3. **Settings → Edgenote** to tune. Click **Test headers** to verify what visitors see.

## Configuration

Settings → Edgenote (option key `edgenote_settings`):

| Field | Default | Purpose |
|---|---|---|
| Edge cache TTL (`s-maxage`) | `300` | Seconds shared caches may serve a cached copy |
| Stale-while-revalidate | `86400` | Seconds shared caches may serve stale during background refresh |
| Bypass cookies | `wordpress_logged_in_`, `comment_`, `wp-postpass_` | Cookie name prefixes that mark a request as authenticated |
| Bypass paths | `/wp-admin`, `/wp-login.php`, `/feed` | URL fragments that always skip the override |

Cookie and path lists are one prefix per line; substring match against `REQUEST_URI` for paths and against the cookie name for cookies.

## Cloudflare setup

Two ways to make Cloudflare honor the Cache-Control we emit:

1. **Caching → Configuration → Respect Existing Headers** (recommended). Cloudflare obeys whatever `s-maxage` Edgenote sets.
2. **Page Rule** with **Edge Cache TTL** set explicitly. Bypasses Cache-Control entirely; Edgenote still helps because Cloudflare's "cache by default" rules require Cache-Control to be public.

After setup, hit a public URL with `curl -I https://yoursite.com/` and look for `cf-cache-status: HIT` after the first request.

## Architecture

```
        wp boot
           │
           ▼
   ┌──────────────────────────┐
   │ CacheableRequest         │   anonymous? GET/HEAD?
   │  - method check          │   not admin/REST/AJAX/preview/search/feed?
   │  - cookie bypass list    │   no bypass-cookie? no bypass-path?
   │  - path bypass list      │
   └────────────┬─────────────┘
                │ yes
                ▼
   ┌──────────────────────────────────────────────┐
   │ HeaderOverride                               │
   │  ├ nocache_headers filter   (priority 99)    │
   │  ├ wp_headers filter        (priority 99)    │
   │  └ send_headers action      (priority 999)   │
   │                                              │
   │   sets:                                      │
   │     Cache-Control: public, max-age=0,        │
   │       s-maxage=N, stale-while-revalidate=M   │
   │     Vary: Accept-Encoding, Cookie            │
   │   strips:                                    │
   │     Expires, Pragma, CDN-Cache-Control       │
   └──────────────────────────────────────────────┘
```

## Known limitations

- **Anonymous-only.** Logged-in users always bypass the override; per-user edge caching is out of scope.
- **No per-route TTL.** A single `s-maxage` covers every cacheable request. Use a Cloudflare Page Rule or a `Cache Rule` if you need per-path control.
- **No purge integration.** Edgenote sets headers; it does not call Cloudflare's purge API on `save_post`. Pair with a purge plugin (e.g. `cloudflare`, `wp-rocket`) if you need invalidation on content updates.
- **Substring path match.** A bypass entry `/feed` also bypasses `/category/feedback/`. Tune the bypass list to match the literal URL prefix.

## Author

[renner.dev](https://renner.dev) · [@rennerdo30](https://github.com/rennerdo30)

## License

MIT.
