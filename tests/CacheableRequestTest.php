<?php

declare(strict_types=1);

/**
 * Plain-PHP test runner for CacheableRequest. No PHPUnit needed.
 *
 * Usage:
 *   php tests/CacheableRequestTest.php
 *
 * Exits with non-zero status if any assertion fails.
 */

require __DIR__ . '/../src/CacheableRequest.php';

use Edgenote\CacheableRequest;

$settings = [
    'bypass_cookies' => "wordpress_logged_in_\ncomment_\nwp-postpass_",
    'bypass_paths'   => "/wp-admin\n/wp-login.php\n/feed",
];

$detector = new CacheableRequest($settings);

$base = [
    'method'            => 'GET',
    'uri'               => '/',
    'query'             => '',
    'cookies'           => [],
    'is_admin'          => false,
    'is_user_logged_in' => false,
    'doing_ajax'        => false,
    'rest_request'      => false,
    'is_search'         => false,
    'is_feed'           => false,
    'is_404'            => false,
    'is_preview'        => false,
];

/** @var array{0:string,1:array<string,mixed>,2:bool}[] $cases */
$cases = [
    ['anonymous home GET',                $base,                                                          true],
    ['anonymous HEAD',                    array_merge($base, ['method' => 'HEAD']),                        true],
    ['anonymous POST',                    array_merge($base, ['method' => 'POST']),                        false],
    ['anonymous PUT',                     array_merge($base, ['method' => 'PUT']),                         false],
    ['logged-in home',                    array_merge($base, ['is_user_logged_in' => true]),               false],
    ['admin screen',                      array_merge($base, ['is_admin' => true, 'uri' => '/wp-admin/']), false],
    ['ajax request',                      array_merge($base, ['doing_ajax' => true]),                      false],
    ['REST request',                      array_merge($base, ['rest_request' => true]),                    false],
    ['search results',                    array_merge($base, ['is_search' => true]),                       false],
    ['RSS feed',                          array_merge($base, ['is_feed' => true, 'uri' => '/feed/']),      false],
    ['404 page',                          array_merge($base, ['is_404' => true]),                          false],
    ['preview flag',                      array_merge($base, ['is_preview' => true]),                      false],
    ['preview query string',              array_merge($base, ['query' => 'preview=true&p=42']),            false],
    ['wp-login.php path bypass',          array_merge($base, ['uri' => '/wp-login.php']),                  false],
    ['wp-admin nested path',              array_merge($base, ['uri' => '/wp-admin/edit.php']),             false],
    ['feed nested path',                  array_merge($base, ['uri' => '/feed/']),                         false],
    ['logged-in cookie present',          array_merge($base, ['cookies' => ['wordpress_logged_in_abcd' => '1']]), false],
    ['comment cookie present',            array_merge($base, ['cookies' => ['comment_author_email_x' => 'a@b']]), false],
    ['post-password cookie present',      array_merge($base, ['cookies' => ['wp-postpass_xxx' => 'y']]),   false],
    ['unrelated cookie ok',               array_merge($base, ['cookies' => ['cf_clearance' => 'x', '_ga' => 'y']]), true],
    ['post permalink GET',                array_merge($base, ['uri' => '/2026/05/hello-world/']),          true],
    ['homepage with utm params',          array_merge($base, ['query' => 'utm_source=twitter']),           true],
    ['HEAD on permalink',                 array_merge($base, ['method' => 'HEAD', 'uri' => '/about/']),    true],
    ['DELETE method blocked',             array_merge($base, ['method' => 'DELETE']),                       false],
];

$pass = 0;
$fail = 0;
foreach ($cases as [$label, $ctx, $want]) {
    $got = $detector->is_cacheable($ctx);
    if ($got === $want) {
        $pass++;
        echo "  ok  - {$label}\n";
    } else {
        $fail++;
        $w = $want ? 'true' : 'false';
        $g = $got  ? 'true' : 'false';
        echo "  FAIL - {$label} (want {$w}, got {$g})\n";
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
