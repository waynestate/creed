<?php

declare(strict_types=1);

/**
 * Shared plumbing for the refresh commands: fetch a feed from its URL, or
 * read the same feed from an offline directory. Both commands share one user
 * agent so the feeds see a single polite visitor.
 */

/**
 * Fetch a URL with curl.
 *
 * @throws RuntimeException when the request fails or returns an error status
 */
function creedFetchUrl(string $url): string
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException("Could not initialise curl for {$url}");
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'creed-refresh-policy/1.0',
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("Could not fetch {$url}: {$error}");
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("HTTP {$status} fetching {$url}");
    }

    return (string) $body;
}

/**
 * Load a feed from the offline directory or fetch it from its URL.
 *
 * @throws RuntimeException when the feed cannot be read
 */
function creedLoadFeed(string $file, string $url, ?string $offlineDir): string
{
    if ($offlineDir !== null) {
        $path = $offlineDir . '/' . $file;
        if (!is_file($path)) {
            throw new RuntimeException("Offline feed not found: {$path}");
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Could not read offline feed: {$path}");
        }

        return $contents;
    }

    return creedFetchUrl($url);
}
