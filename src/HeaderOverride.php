<?php

declare(strict_types=1);

namespace Edgenote;

/**
 * Wins the Cache-Control war on cacheable requests by hooking three
 * different WordPress insertion points:
 *
 *   1) nocache_headers       (filter, priority 99)
 *   2) wp_headers            (filter, priority 99)
 *   3) send_headers          (action, priority 999) — final word
 *
 * Whichever path WordPress (or a misbehaving plugin) takes to emit the
 * default `private, no-store, no-cache` Cache-Control line, Edgenote
 * intercepts it and replaces the value with a public, edge-cacheable
 * header that browsers will still revalidate on every visit.
 */
final class HeaderOverride
{
    private CacheableRequest $request;

    /** @var array{s_maxage:int, stale_while_revalidate:int} */
    private array $settings;

    /** @param array<string,mixed> $settings */
    public function __construct(CacheableRequest $request, array $settings)
    {
        $this->request  = $request;
        $this->settings = [
            's_maxage'               => (int) ($settings['s_maxage'] ?? 300),
            'stale_while_revalidate' => (int) ($settings['stale_while_revalidate'] ?? 86400),
        ];
    }

    public function register(): void
    {
        add_filter('nocache_headers', [$this, 'filter_nocache_headers'], 99);
        add_filter('wp_headers', [$this, 'filter_wp_headers'], 99);
        add_action('send_headers', [$this, 'force_headers'], 999);
    }

    /**
     * Compose the Cache-Control value:
     *   public, max-age=0, s-maxage={ttl}, stale-while-revalidate={swr}
     *
     * - `max-age=0` keeps the browser from holding the response (so a
     *   logged-in admin who hits the same URL after logging in still
     *   gets a fresh response from the edge or origin).
     * - `s-maxage` lets shared caches (Cloudflare) cache for {ttl}.
     * - `stale-while-revalidate` lets the edge serve stale while
     *   refreshing in the background.
     */
    public function cache_control_value(): string
    {
        return sprintf(
            'public, max-age=0, s-maxage=%d, stale-while-revalidate=%d',
            $this->settings['s_maxage'],
            $this->settings['stale_while_revalidate']
        );
    }

    public function vary_value(): string
    {
        return 'Accept-Encoding, Cookie';
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,string>
     */
    public function filter_nocache_headers(array $headers): array
    {
        if (!$this->request->is_cacheable()) {
            return $headers;
        }
        $headers['Cache-Control'] = $this->cache_control_value();
        unset($headers['Expires']);
        unset($headers['Pragma']);
        // Strip any rogue private/no-store CDN-Cache-Control upstream of us.
        if (isset($headers['CDN-Cache-Control'])) {
            unset($headers['CDN-Cache-Control']);
        }
        return $headers;
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,string>
     */
    public function filter_wp_headers(array $headers): array
    {
        if (!$this->request->is_cacheable()) {
            return $headers;
        }
        $headers['Cache-Control'] = $this->cache_control_value();
        $headers['Vary']          = $this->vary_value();
        unset($headers['Expires']);
        unset($headers['Pragma']);
        if (isset($headers['CDN-Cache-Control'])) {
            unset($headers['CDN-Cache-Control']);
        }
        return $headers;
    }

    /**
     * The final word: even if a plugin emitted a header() call directly
     * after wp_headers fired, we replace it here with header($value, true)
     * which overrides any prior Cache-Control of the same name.
     */
    public function force_headers(): void
    {
        if (!$this->request->is_cacheable()) {
            return;
        }
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: ' . $this->cache_control_value(), true);
        header('Vary: ' . $this->vary_value(), true);
        header_remove('Expires');
        header_remove('Pragma');
        header_remove('CDN-Cache-Control');
    }
}
