<?php

declare(strict_types=1);

namespace Edgenote\Cloudflare;

use WP_Post;
use WP_Term;

/**
 * Wires WordPress save events to Cloudflare cache purges.
 *
 * Fires on:
 *   - transition_post_status (post saves, including publish/unpublish/trash)
 *   - deleted_post           (hard delete)
 *   - wp_update_nav_menu     (menu changes affect every cached page)
 *
 * Skips:
 *   - WP-CLI invocations (seeders shouldn't flood the API)
 *   - Auto-saves, revisions, and post types that aren't `public => true`
 *   - When the purge mode is `disabled` or credentials aren't set
 *
 * URL-mode purges include the post permalink, the home URL, the
 * post-type archive (if any), and the term archives the post is in
 * (capped at 10 terms total to avoid runaway calls).
 *
 * Errors are written to error_log() and surfaced to manage_options
 * admins via a transient-backed admin notice.
 */
final class SaveHook
{
    private const NOTICE_TRANSIENT = 'edgenote_cf_notice';
    /** Cap on the number of term-archive URLs we collect per save. */
    private const MAX_TERM_URLS    = 10;

    private Purger $purger;

    public function __construct(Purger $purger)
    {
        $this->purger = $purger;
    }

    public function register(): void
    {
        add_action('transition_post_status', [$this, 'on_transition_post_status'], 20, 3);
        add_action('deleted_post', [$this, 'on_deleted_post'], 20, 2);
        add_action('wp_update_nav_menu', [$this, 'on_nav_menu_update'], 20);
        add_action('admin_notices', [$this, 'maybe_render_notice']);
    }

    /**
     * @param string  $new_status
     * @param string  $old_status
     * @param WP_Post $post
     */
    public function on_transition_post_status($new_status, $old_status, $post): void
    {
        if (!$post instanceof WP_Post) {
            return;
        }

        // Trigger on:
        //   - publish → anything (unpublish, trash)
        //   - anything → publish (publishing for the first time)
        //   - publish → publish (an edit on a published post)
        $was_public = $old_status === 'publish';
        $is_public  = $new_status === 'publish';
        if (!$was_public && !$is_public) {
            return;
        }

        if (!$this->isPurgeable($post)) {
            return;
        }

        $this->dispatch($post);
    }

    /**
     * @param int          $post_id
     * @param WP_Post|null $post
     */
    public function on_deleted_post($post_id, $post = null): void
    {
        if (!$post instanceof WP_Post) {
            $post = get_post($post_id);
        }
        if (!$post instanceof WP_Post) {
            return;
        }
        if (!$this->isPurgeable($post)) {
            return;
        }
        $this->dispatch($post);
    }

    public function on_nav_menu_update($menu_id): void
    {
        if (!$this->canFire()) {
            return;
        }
        // Menu changes are site-wide — escalate to everything if mode permits,
        // else purge the home URL as a best-effort.
        $mode = $this->purger->mode();
        if ($mode === Purger::MODE_EVERYTHING) {
            $this->report($this->purger->purgeEverything(), 'nav_menu');
            return;
        }
        if ($mode === Purger::MODE_URLS) {
            $this->report($this->purger->purgeUrls([home_url('/')]), 'nav_menu');
        }
    }

    private function dispatch(WP_Post $post): void
    {
        if (!$this->canFire()) {
            return;
        }

        $mode = $this->purger->mode();
        if ($mode === Purger::MODE_DISABLED) {
            return;
        }
        if ($mode === Purger::MODE_EVERYTHING) {
            $result = $this->purger->purgeEverything();
            $this->report($result, 'post#' . $post->ID, null);
            return;
        }

        // urls mode
        $urls = $this->collectUrls($post);
        if ($urls === []) {
            return;
        }
        $result = $this->purger->purgeUrls($urls);
        $this->report($result, 'post#' . $post->ID, count($urls));
    }

    /**
     * @return string[]
     */
    private function collectUrls(WP_Post $post): array
    {
        $urls = [];

        $permalink = get_permalink($post);
        if (is_string($permalink) && $permalink !== '') {
            $urls[] = $permalink;
        }

        $urls[] = home_url('/');

        $archive = get_post_type_archive_link($post->post_type);
        if (is_string($archive) && $archive !== '') {
            $urls[] = $archive;
        }

        // Term archives — capped to MAX_TERM_URLS total across all taxonomies.
        $taxonomies = get_object_taxonomies($post->post_type, 'objects');
        $term_count = 0;
        foreach ($taxonomies as $tax) {
            if (!is_object($tax) || empty($tax->public)) {
                continue;
            }
            $terms = get_the_terms($post, $tax->name);
            if (!is_array($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                if (!$term instanceof WP_Term) {
                    continue;
                }
                if ($term_count >= self::MAX_TERM_URLS) {
                    break 2;
                }
                $link = get_term_link($term);
                if (is_string($link) && $link !== '') {
                    $urls[] = $link;
                    $term_count++;
                }
            }
        }

        // De-dup while preserving order.
        return array_values(array_unique($urls));
    }

    private function isPurgeable(WP_Post $post): bool
    {
        if (wp_is_post_autosave($post->ID)) {
            return false;
        }
        if (wp_is_post_revision($post->ID)) {
            return false;
        }
        $pto = get_post_type_object($post->post_type);
        if (!$pto || empty($pto->public)) {
            return false;
        }
        return true;
    }

    private function canFire(): bool
    {
        // Don't fire under WP-CLI (seeders / bulk imports).
        if (defined('WP_CLI') && WP_CLI) {
            return false;
        }
        if (!$this->purger->isConfigured()) {
            return false;
        }
        if ($this->purger->mode() === Purger::MODE_DISABLED) {
            return false;
        }
        return true;
    }

    /**
     * @param true|\WP_Error $result
     */
    private function report($result, string $context, ?int $url_count = null): void
    {
        if (is_wp_error($result)) {
            $msg = 'edgenote: cloudflare purge failed [' . $context . ']: ' . $result->get_error_message();
            error_log($msg);
            set_transient(self::NOTICE_TRANSIENT, [
                'type'    => 'error',
                'message' => $msg,
            ], 120);
            return;
        }
        if ($url_count !== null) {
            error_log(sprintf('edgenote: purged: %d URLs [%s]', $url_count, $context));
        } else {
            error_log(sprintf('edgenote: purged: everything [%s]', $context));
        }
    }

    public function maybe_render_notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $notice = get_transient(self::NOTICE_TRANSIENT);
        if (!is_array($notice) || empty($notice['message'])) {
            return;
        }
        delete_transient(self::NOTICE_TRANSIENT);
        $type = (string) ($notice['type'] ?? 'error');
        $cls  = $type === 'success' ? 'notice notice-success' : 'notice notice-error';
        printf(
            '<div class="%s"><p>%s</p></div>',
            esc_attr($cls),
            esc_html((string) $notice['message'])
        );
    }
}
