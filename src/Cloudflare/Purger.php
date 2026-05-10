<?php

declare(strict_types=1);

namespace Edgenote\Cloudflare;

use WP_Error;

/**
 * Thin wrapper around the Cloudflare Purge API.
 *
 * - purgeUrls(array): purge a discrete URL list (max 30 per call per Cloudflare docs).
 * - purgeEverything(): purge the whole zone.
 * - verifyToken(): round-trip GET /zones/<id> to validate token + zone pairing.
 *
 * The token is read from the `edgenote_cf_api_token` option and the zone
 * from `edgenote_cf_zone_id`. Both are stored as plain WordPress options
 * but the settings page renders the token field as type=password with a
 * masked placeholder so it never re-emits the secret to the browser.
 */
final class Purger
{
    public const API_BASE = 'https://api.cloudflare.com/client/v4';

    public const OPTION_TOKEN  = 'edgenote_cf_api_token';
    public const OPTION_ZONE   = 'edgenote_cf_zone_id';
    public const OPTION_MODE   = 'edgenote_cf_purge_mode';

    public const MODE_URLS       = 'urls';
    public const MODE_EVERYTHING = 'everything';
    public const MODE_DISABLED   = 'disabled';

    /** Cloudflare caps a single purge_cache call at 30 files. */
    public const MAX_URLS_PER_CALL = 30;

    public function token(): string
    {
        return (string) get_option(self::OPTION_TOKEN, '');
    }

    public function zoneId(): string
    {
        return (string) get_option(self::OPTION_ZONE, '');
    }

    public function mode(): string
    {
        $mode = (string) get_option(self::OPTION_MODE, self::MODE_URLS);
        return in_array($mode, [self::MODE_URLS, self::MODE_EVERYTHING, self::MODE_DISABLED], true)
            ? $mode
            : self::MODE_URLS;
    }

    public function isConfigured(): bool
    {
        return $this->token() !== '' && $this->zoneId() !== '';
    }

    /**
     * @param string[] $urls
     * @return true|WP_Error
     */
    public function purgeUrls(array $urls)
    {
        if (!$this->isConfigured()) {
            return new WP_Error('edgenote_cf_unconfigured', __('Cloudflare token or zone ID is not configured.', 'edgenote'));
        }

        $urls = array_values(array_unique(array_filter(array_map('strval', $urls), static fn($u) => $u !== '')));
        if ($urls === []) {
            return true;
        }

        // Chunk to API limit.
        foreach (array_chunk($urls, self::MAX_URLS_PER_CALL) as $batch) {
            $result = $this->request('purge_cache', ['files' => array_values($batch)]);
            if (is_wp_error($result)) {
                return $result;
            }
        }
        return true;
    }

    /** @return true|WP_Error */
    public function purgeEverything()
    {
        if (!$this->isConfigured()) {
            return new WP_Error('edgenote_cf_unconfigured', __('Cloudflare token or zone ID is not configured.', 'edgenote'));
        }
        return $this->request('purge_cache', ['purge_everything' => true]);
    }

    /**
     * Calls GET /zones/<id>. Returns true on a 200 with success=true.
     *
     * @return true|WP_Error
     */
    public function verifyToken()
    {
        if (!$this->isConfigured()) {
            return new WP_Error('edgenote_cf_unconfigured', __('Cloudflare token or zone ID is not configured.', 'edgenote'));
        }

        $url = self::API_BASE . '/zones/' . rawurlencode($this->zoneId());
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token(),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ]);

        return $this->interpretResponse($response, 'verify');
    }

    /**
     * @param array<string,mixed> $body
     * @return true|WP_Error
     */
    private function request(string $endpoint, array $body)
    {
        $url = self::API_BASE . '/zones/' . rawurlencode($this->zoneId()) . '/' . $endpoint;
        $response = wp_remote_post($url, [
            'method'  => 'POST',
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token(),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'    => wp_json_encode($body),
        ]);

        return $this->interpretResponse($response, $endpoint);
    }

    /**
     * @param mixed $response
     * @return true|WP_Error
     */
    private function interpretResponse($response, string $context)
    {
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);

        if ($code >= 200 && $code < 300 && is_array($json) && !empty($json['success'])) {
            return true;
        }

        $message = sprintf('cloudflare %s failed (http %d)', $context, $code);
        if (is_array($json) && !empty($json['errors']) && is_array($json['errors'])) {
            $parts = [];
            foreach ($json['errors'] as $err) {
                if (is_array($err) && isset($err['message'])) {
                    $parts[] = (string) $err['message'];
                }
            }
            if ($parts !== []) {
                $message .= ': ' . implode('; ', $parts);
            }
        } elseif ($raw !== '') {
            $message .= ': ' . substr($raw, 0, 240);
        }

        return new WP_Error('edgenote_cf_error', $message);
    }
}
