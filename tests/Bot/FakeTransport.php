<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests\Bot;

use Waynestate\Creed\Bot\HttpTransport;

/**
 * A scripted HTTP transport for the GitLab client tests. Every request is
 * recorded in call order; the Nth call returns the Nth scripted response,
 * or the default (200 with no body) once the script runs out, so a test
 * scripts only the calls it cares about. No network, ever.
 */
final class FakeTransport implements HttpTransport
{
    /**
     * Every request handed to the transport, in order.
     *
     * @var list<array{method: string, url: string, headers: array<string, string>, form: array<string, string>|null}>
     */
    public array $calls = [];

    /**
     * Scripted responses, in call order.
     *
     * @var list<array{status: int, body: mixed}>
     */
    private array $responses;

    /**
     * @param list<array{status: int, body: mixed}> $responses
     */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string>|null $form
     *
     * @return array{status: int, body: mixed}
     */
    public function request(string $method, string $url, array $headers, ?array $form): array
    {
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'form' => $form,
        ];

        return $this->responses[count($this->calls) - 1] ?? ['status' => 200, 'body' => null];
    }
}
