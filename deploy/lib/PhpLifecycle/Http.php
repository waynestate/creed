<?php

declare(strict_types=1);

namespace Waynestate\Creed\Site\PhpLifecycle;

/**
 * Fetches several URLs at once with short timeouts. curl_multi when the curl
 * extension is there, plain streams one by one when it is not. TLS is always
 * verified. A feed that cannot be reached in a few seconds is reported as
 * down, and the page says so; nobody waits on php.net's coffee break.
 */
class Http
{
    public const CONNECT_TIMEOUT = 4;

    public const TIMEOUT = 8;

    private const AGENT = 'CREED php-lifecycle (+https://creed.apps.wayne.edu/php/)';

    /**
     * @param array<string, string> $urls id => URL
     *
     * @return array<string, array{status: int, body: ?string, error: ?string}>
     */
    public function fetchAll(array $urls): array
    {
        if ($urls === []) {
            return [];
        }

        return function_exists('curl_multi_init') ? $this->curl($urls) : $this->streams($urls);
    }

    /**
     * @param array<string, string> $urls
     *
     * @return array<string, array{status: int, body: ?string, error: ?string}>
     */
    private function curl(array $urls): array
    {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($urls as $id => $url) {
            $h = curl_init($url);
            if ($h === false) {
                continue;
            }
            curl_setopt_array($h, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_USERAGENT => self::AGENT,
                CURLOPT_ENCODING => '',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                // php.net says some of these may be cached for 30 days; ask for a fresh copy every time.
                CURLOPT_HTTPHEADER => ['Cache-Control: no-cache', 'Accept: application/json, application/atom+xml, text/plain, */*'],
            ]);
            curl_multi_add_handle($multi, $h);
            $handles[$id] = $h;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $id => $h) {
            $body = curl_multi_getcontent($h);
            $code = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
            $error = curl_error($h);
            $out[$id] = [
                'status' => $code,
                'body' => is_string($body) && $code > 0 ? $body : null,
                'error' => $error !== '' ? $error : ($code >= 400 ? 'HTTP ' . $code : null),
            ];
            curl_multi_remove_handle($multi, $h);
        }
        curl_multi_close($multi);

        return $out;
    }

    /**
     * @param array<string, string> $urls
     *
     * @return array<string, array{status: int, body: ?string, error: ?string}>
     */
    private function streams(array $urls): array
    {
        $out = [];
        foreach ($urls as $id => $url) {
            $context = stream_context_create([
                'http' => [
                    'timeout' => self::TIMEOUT,
                    'ignore_errors' => true,
                    'user_agent' => self::AGENT,
                    'header' => "Cache-Control: no-cache\r\n",
                ],
            ]);
            $body = @file_get_contents($url, false, $context);
            $code = 0;
            // PHP 8.4 added the function; 8.5 deprecates the magic variable.
            $headers = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : $http_response_header;
            foreach ($headers as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $code = (int) $m[1];
                }
            }
            $out[$id] = [
                'status' => $code,
                'body' => $body === false ? null : $body,
                'error' => $body === false ? 'unreachable' : ($code >= 400 ? 'HTTP ' . $code : null),
            ];
        }

        return $out;
    }
}
