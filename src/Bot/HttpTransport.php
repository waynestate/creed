<?php

declare(strict_types=1);

namespace Waynestate\Creed\Bot;

/**
 * One HTTP request to the GitLab API. The policy robot ships a PHP streams
 * implementation (see GitLabClient::streamTransport) and the tests swap in
 * a fake, so no Composer dependency ever carries the token.
 */
interface HttpTransport
{
    /**
     * Send one request and report the answer.
     *
     * @param array<string, string> $headers request headers, name to value
     * @param array<string, string>|null $form urlencoded form fields, or null for a bodyless request
     *
     * @return array{status: int, body: mixed} the status code and the decoded
     *         JSON body (the raw text when the body is not valid JSON, null
     *         when there was no body at all)
     */
    public function request(string $method, string $url, array $headers, ?array $form): array;
}
