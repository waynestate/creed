<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests\Bot;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use RuntimeException;
use Waynestate\Creed\Bot\GitLabClient;
use Waynestate\Creed\Bot\HttpTransport;

/**
 * The GitLab client against a fake transport: the right endpoint, method,
 * headers and form for each call, and a masked error on a non-2xx answer.
 * The token may ride the PRIVATE-TOKEN header, but never a URL.
 */
final class GitLabClientTest extends PHPUnitTestCase
{
    private const API_URL = 'https://git.example.com/api/v4';

    private const PROJECT_ID = '42';

    private const TOKEN = 'test-token-123';

    private function makeClient(FakeTransport $transport): GitLabClient
    {
        return new GitLabClient($transport, self::API_URL, self::PROJECT_ID, self::TOKEN);
    }

    #[Test]
    public function openIssuesQueriesTheAutoAlertLabel(): void
    {
        $transport = new FakeTransport([[
            'status' => 200,
            'body' => [['title' => '[auto-alert] held:php:8.3:security_until', 'iid' => 7]],
        ]]);
        $client = $this->makeClient($transport);

        $issues = $client->openIssues('');

        self::assertSame([['title' => '[auto-alert] held:php:8.3:security_until', 'iid' => 7]], $issues);
        self::assertCount(1, $transport->calls);
        $call = $transport->calls[0];
        self::assertSame('GET', $call['method']);
        self::assertSame(self::API_URL . '/projects/42/issues?state=opened&labels=auto-alert', $call['url']);
        self::assertNull($call['form']);
        self::assertSame(self::TOKEN, $call['headers']['PRIVATE-TOKEN']);
        self::assertSame('application/json', $call['headers']['Accept']);
    }

    #[Test]
    public function openIssuesAppendsTheSearchTerm(): void
    {
        $transport = new FakeTransport();
        $client = $this->makeClient($transport);

        $client->openIssues('held php');

        self::assertStringEndsWith('&search=held%20php', $transport->calls[0]['url']);
    }

    #[Test]
    public function createIssuePostsTitleDescriptionAndLabels(): void
    {
        $transport = new FakeTransport([['status' => 201, 'body' => ['iid' => 11, 'title' => '[auto-alert] held:x']]]);
        $client = $this->makeClient($transport);

        $issue = $client->createIssue('[auto-alert] held:x', 'held php 8.3: security_until', ['auto-alert']);

        self::assertSame(['iid' => 11, 'title' => '[auto-alert] held:x'], $issue);
        $call = $transport->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame(self::API_URL . '/projects/42/issues', $call['url']);
        self::assertSame(
            ['title' => '[auto-alert] held:x', 'description' => 'held php 8.3: security_until', 'labels' => 'auto-alert'],
            $call['form'],
        );
    }

    #[Test]
    public function addNotePostsToTheIssueNotesEndpoint(): void
    {
        $transport = new FakeTransport([['status' => 201, 'body' => ['id' => 5, 'body' => 'Still present on 2026-10-29.']]]);
        $client = $this->makeClient($transport);

        $note = $client->addNote(7, 'Still present on 2026-10-29.');

        self::assertSame(['id' => 5, 'body' => 'Still present on 2026-10-29.'], $note);
        $call = $transport->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame(self::API_URL . '/projects/42/issues/7/notes', $call['url']);
        self::assertSame(['body' => 'Still present on 2026-10-29.'], $call['form']);
    }

    #[Test]
    public function closeIssuePutsACloseStateEvent(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => ['iid' => 9, 'state' => 'closed']]]);
        $client = $this->makeClient($transport);

        $issue = $client->closeIssue(9);

        self::assertSame(['iid' => 9, 'state' => 'closed'], $issue);
        $call = $transport->calls[0];
        self::assertSame('PUT', $call['method']);
        self::assertSame(self::API_URL . '/projects/42/issues/9', $call['url']);
        self::assertSame(['state_event' => 'close'], $call['form']);
    }

    #[Test]
    public function aNonTwoHundredAnswerThrowsAMaskedError(): void
    {
        $token = 'top-secret-token';
        $transport = new FakeTransport([['status' => 500, 'body' => "boom {$token}"]]);
        $client = new GitLabClient($transport, self::API_URL, self::PROJECT_ID, $token);

        try {
            $client->openIssues('');
            self::fail('expected a RuntimeException for a 500 answer');
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString($token, $e->getMessage());
            self::assertStringContainsString('[masked]', $e->getMessage());
            self::assertStringContainsString('GitLab API error 500', $e->getMessage());
            self::assertStringContainsString('GET projects/42/issues', $e->getMessage());
        }
    }

    #[Test]
    public function theTokenNeverAppearsInAnyUrl(): void
    {
        $transport = new FakeTransport();
        $client = $this->makeClient($transport);

        $client->openIssues('held php');
        $client->createIssue('[auto-alert] held:x', 'body', ['auto-alert']);
        $client->addNote(7, 'note');
        $client->closeIssue(9);

        self::assertCount(4, $transport->calls);
        foreach ($transport->calls as $call) {
            self::assertStringNotContainsString(self::TOKEN, $call['url']);
        }
    }

    #[Test]
    public function streamTransportIsAnHttpTransport(): void
    {
        self::assertInstanceOf(HttpTransport::class, GitLabClient::streamTransport());
    }

    #[Test]
    public function streamTransportHandlesClosedPortGracefully(): void
    {
        $transport = GitLabClient::streamTransport();
        $result = $transport->request('GET', 'http://127.0.0.1:9/', [], null);
        self::assertSame(['status' => 0, 'body' => null], $result);
    }
}
