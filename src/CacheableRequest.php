<?php

declare(strict_types=1);

namespace Edgenote;

/**
 * Decides whether the current request should receive a public,
 * edge-cacheable Cache-Control header.
 *
 * Pure-ish: WordPress globals are read through small adapter methods
 * so the class can be exercised in isolation by unit tests via the
 * $context array.
 */
final class CacheableRequest
{
    /** @var array{bypass_cookies:string,bypass_paths:string} */
    private array $settings;

    /** @param array<string,mixed> $settings */
    public function __construct(array $settings)
    {
        $this->settings = [
            'bypass_cookies' => (string) ($settings['bypass_cookies'] ?? ''),
            'bypass_paths'   => (string) ($settings['bypass_paths'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed>|null $context Optional injected context for tests.
     *   Keys: method, uri, query, cookies, is_admin, is_user_logged_in,
     *   doing_ajax, rest_request, is_search, is_feed, is_404, is_preview.
     */
    public function is_cacheable(?array $context = null): bool
    {
        $ctx = $context ?? $this->collect_context();

        if (!in_array(strtoupper((string) $ctx['method']), ['GET', 'HEAD'], true)) {
            return false;
        }
        if (!empty($ctx['is_admin'])) {
            return false;
        }
        if (!empty($ctx['is_user_logged_in'])) {
            return false;
        }
        if (!empty($ctx['doing_ajax'])) {
            return false;
        }
        if (!empty($ctx['rest_request'])) {
            return false;
        }
        if (!empty($ctx['is_search']) || !empty($ctx['is_feed']) || !empty($ctx['is_404'])) {
            return false;
        }
        if (!empty($ctx['is_preview'])) {
            return false;
        }

        $query = (string) ($ctx['query'] ?? '');
        if ($query !== '' && stripos($query, 'preview') !== false) {
            return false;
        }

        $uri = (string) ($ctx['uri'] ?? '/');
        foreach ($this->bypass_paths() as $needle) {
            if ($needle !== '' && stripos($uri, $needle) === 0) {
                return false;
            }
            // Also match if needle appears anywhere — covers /xx/wp-admin/.
            if ($needle !== '' && strpos($uri, $needle) !== false) {
                return false;
            }
        }

        $cookies = (array) ($ctx['cookies'] ?? []);
        foreach ($this->bypass_cookies() as $prefix) {
            if ($prefix === '') {
                continue;
            }
            foreach (array_keys($cookies) as $name) {
                if (str_starts_with((string) $name, $prefix)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return array<string,mixed> */
    private function collect_context(): array
    {
        return [
            'method'            => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'uri'               => $_SERVER['REQUEST_URI'] ?? '/',
            'query'             => $_SERVER['QUERY_STRING'] ?? '',
            'cookies'           => $_COOKIE ?? [],
            'is_admin'          => function_exists('is_admin') && is_admin(),
            'is_user_logged_in' => function_exists('is_user_logged_in') && is_user_logged_in(),
            'doing_ajax'        => defined('DOING_AJAX') && DOING_AJAX,
            'rest_request'      => defined('REST_REQUEST') && REST_REQUEST,
            'is_search'         => function_exists('is_search') && is_search(),
            'is_feed'           => function_exists('is_feed') && is_feed(),
            'is_404'            => function_exists('is_404') && is_404(),
            'is_preview'        => function_exists('is_preview') && is_preview(),
        ];
    }

    /** @return string[] */
    private function bypass_cookies(): array
    {
        return $this->lines($this->settings['bypass_cookies']);
    }

    /** @return string[] */
    private function bypass_paths(): array
    {
        return $this->lines($this->settings['bypass_paths']);
    }

    /** @return string[] */
    private function lines(string $blob): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $blob) ?: [] as $line) {
            $line = trim((string) $line);
            // Allow shell-style * trailing wildcards in cookies (e.g. wordpress_logged_in_*).
            $line = rtrim($line, '*');
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }
}
