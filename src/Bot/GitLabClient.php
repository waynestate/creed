<?php

declare(strict_types=1);

namespace Waynestate\Creed\Bot;

use RuntimeException;

/**
 * A small GitLab API client for the alert issues: search open issues,
 * create an issue, add a note, close an issue. The token rides only in the
 * PRIVATE-TOKEN header, never in a URL (no first ride here), and every
 * error this class throws is masked, so a failed call can never print the
 * token.
 */
final class GitLabClient
{
    /**
     * @param string $apiUrl the CI_API_V4_URL value, for example https://git.example.com/api/v4
     * @param string $projectId the CI_PROJECT_ID value
     * @param string $token the CREED_BOT_TOKEN value
     */
    public function __construct(
        private HttpTransport $transport,
        private string $apiUrl,
        private string $projectId,
        private string $token,
    ) {}

    /**
     * The default transport: PHP streams through file_get_contents with a
     * stream context. No Composer dependency.
     */
    public static function streamTransport(): HttpTransport
    {
        return new class implements HttpTransport {
            /**
             * @param array<string, string> $headers
             * @param array<string, string>|null $form
             *
             * @return array{status: int, body: mixed}
             */
            public function request(string $method, string $url, array $headers, ?array $form): array
            {
                $lines = [];
                foreach ($headers as $name => $value) {
                    $lines[] = "{$name}: {$value}";
                }
                $http = [
                    'method' => $method,
                    'header' => implode("\r\n", $lines),
                    'ignore_errors' => true,
                    'follow_location' => 0,
                    'timeout' => 30,
                ];
                if ($form !== null) {
                    $http['header'] .= "\r\nContent-Type: application/x-www-form-urlencoded";
                    $http['content'] = http_build_query($form);
                }

                $stream = @fopen($url, 'r', false, stream_context_create(['http' => $http]));
                if ($stream === false) {
                    return ['status' => 0, 'body' => null];
                }
                $raw = stream_get_contents($stream);
                $meta = stream_get_meta_data($stream);
                fclose($stream);
                if ($raw === false) {
                    return ['status' => 0, 'body' => null];
                }
                // The response headers come from the stream's metadata (wrapper_data),
                // which PHP fills in for the http wrapper once the request has been made.
                $status = 0;
                foreach ((array) ($meta['wrapper_data'] ?? []) as $line) {
                    if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $matches) === 1) {
                        $status = (int) $matches[1];
                    }
                }
                $body = json_decode((string) $raw);
                if ($body === null && trim((string) $raw) !== '') {
                    $body = (string) $raw;
                }

                return ['status' => $status, 'body' => $body];
            }
        };
    }

    /**
     * Open issues carrying the auto-alert label, optionally narrowed by a
     * search term matched against title and description.
     *
     * @return list<array<string, mixed>>
     */
    public function openIssues(string $search): array
    {
        $query = 'state=opened&labels=auto-alert';
        if ($search !== '') {
            $query .= '&search=' . rawurlencode($search);
        }
        $result = $this->request('GET', $this->projectUrl('/issues') . '?' . $query);

        return $this->list($result['body']);
    }

    /**
     * Create an issue and return it.
     *
     * @param list<string> $labels
     *
     * @return array<string, mixed>
     */
    public function createIssue(string $title, string $description, array $labels): array
    {
        $result = $this->request('POST', $this->projectUrl('/issues'), [
            'title' => $title,
            'description' => $description,
            'labels' => implode(',', $labels),
        ]);

        return $this->object($result['body']);
    }

    /**
     * Add a note to an issue and return the note.
     *
     * @return array<string, mixed>
     */
    public function addNote(int $iid, string $body): array
    {
        $result = $this->request('POST', $this->projectUrl("/issues/{$iid}/notes"), ['body' => $body]);

        return $this->object($result['body']);
    }

    /**
     * Close an issue and return it.
     *
     * @return array<string, mixed>
     */
    public function closeIssue(int $iid): array
    {
        $result = $this->request('PUT', $this->projectUrl("/issues/{$iid}"), ['state_event' => 'close']);

        return $this->object($result['body']);
    }

    /**
     * One API call: send it, and throw a masked error on a non-2xx answer.
     *
     * @param array<string, string>|null $form
     *
     * @return array{status: int, body: mixed}
     *
     * @throws RuntimeException on a non-2xx answer, never naming the token
     */
    private function request(string $method, string $url, ?array $form = null): array
    {
        $result = $this->transport->request($method, $url, [
            'PRIVATE-TOKEN' => $this->token,
            'Accept' => 'application/json',
        ], $form);
        $status = (int) $result['status'];
        if ($status < 200 || $status > 299) {
            throw new RuntimeException($this->mask(sprintf(
                'GitLab API error %d on %s %s: %s',
                $status,
                $method,
                $this->relativeUrl($url),
                $this->excerpt($result['body'] ?? null),
            )));
        }

        return $result;
    }

    /**
     * The project base URL, for example
     * https://git.example.com/api/v4/projects/42.
     */
    private function projectUrl(string $path): string
    {
        return rtrim($this->apiUrl, '/') . '/projects/' . rawurlencode($this->projectId) . $path;
    }

    /**
     * The URL without the API prefix, for error messages.
     */
    private function relativeUrl(string $url): string
    {
        $prefix = rtrim($this->apiUrl, '/') . '/';
        if (str_starts_with($url, $prefix)) {
            return substr($url, strlen($prefix));
        }

        return (string) (parse_url($url, PHP_URL_PATH) ?: $url);
    }

    /**
     * A short, printable view of a decoded body for error messages.
     */
    private function excerpt(mixed $body): string
    {
        if ($body === null) {
            return '(empty body)';
        }
        $text = is_string($body) ? $body : (string) json_encode($body);
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? $text;

        return strlen($text) > 200 ? substr($text, 0, 200) . '...' : $text;
    }

    /**
     * The one mask: the token value becomes [masked]. An empty token masks
     * nothing.
     */
    private function mask(string $line): string
    {
        return $this->token === '' ? $line : str_replace($this->token, '[masked]', $line);
    }

    /**
     * @param mixed $body
     *
     * @return list<array<string, mixed>>
     */
    private function list(mixed $body): array
    {
        if (!is_array($body)) {
            return [];
        }
        $items = [];
        foreach (array_values($body) as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param mixed $body
     *
     * @return array<string, mixed>
     */
    private function object(mixed $body): array
    {
        return is_array($body) ? $body : [];
    }
}
