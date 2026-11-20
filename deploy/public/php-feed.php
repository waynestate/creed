<?php

declare(strict_types=1);

/*
 * php-feed.php: the live PHP lifecycle behind /php (site/php/index.html).
 *
 * A real file, so the rewrite in .htaccess hands it straight to PHP instead
 * of the static front controller. It reads php.net, php-src, the php.net wiki,
 * endoflife.date and Packagist, merges them (deploy/lib/PhpLifecycle) and
 * keeps the result in a file cache for ten minutes. A failed refresh serves
 * the last good answer, marked stale. Nothing here is typed in by hand.
 *
 * Cache folder: CREED_PHP_FEED_CACHE when set, else the system temp folder.
 */

if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}
require __DIR__ . '/../lib/PhpLifecycle/autoload.php';

use Waynestate\Creed\Site\PhpLifecycle\Feed;
use Waynestate\Creed\Site\PhpLifecycle\Http;

if (function_exists('Waynestate\Helmet\helmet_boot')) {
    \Waynestate\Helmet\helmet_boot(['preset' => 'citadel']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method not allowed';
    exit;
}

$dir = getenv('CREED_PHP_FEED_CACHE');
if ($dir === false || $dir === '') {
    $dir = rtrim(sys_get_temp_dir(), '/\\') . '/creed-php-lifecycle';
}

try {
    $doc = (new Feed($dir, new Http()))->get(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    $body = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('php-feed: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo '{"error":"The lifecycle feed could not be built."}';
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');
header('Content-Length: ' . strlen($body));
if ($method === 'GET') {
    echo $body;
}
