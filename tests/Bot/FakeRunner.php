<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests\Bot;

use RuntimeException;

/**
 * A scripted command transport for the policy robot tests. Calls whose
 * argv names refresh-policy.php pop the next scripted exit code and write
 * the next report to the requested --report path; every call is recorded;
 * any other call throws, so a test can never silently run a real command.
 */
final class FakeRunner
{
    /** @var list<int> */
    private array $exits;

    /** @var list<object|null> */
    private array $reports;

    /**
     * Every argv handed to the transport, in order.
     *
     * @var list<list<string>>
     */
    public array $calls = [];

    private int $call = 0;

    /**
     * @param list<int> $exits exit code for the nth refresher call
     * @param list<object|null> $reports report document for the nth refresher call, or null for none
     */
    public function __construct(array $exits, array $reports = [])
    {
        $this->exits = $exits;
        $this->reports = $reports;
    }

    /**
     * @param list<string> $argv
     *
     * @return array{status: int, stdout: string, stderr: string}
     */
    public function __invoke(array $argv): array
    {
        $this->calls[] = $argv;
        $joined = implode(' ', $argv);
        if (!str_contains($joined, 'refresh-policy.php')) {
            throw new RuntimeException("FakeRunner has no rule for: {$joined}");
        }

        $exit = $this->exits[$this->call] ?? 0;
        $report = $this->reports[$this->call] ?? null;
        if ($report !== null) {
            $index = array_search('--report', $argv, true);
            if ($index === false || !isset($argv[$index + 1])) {
                throw new RuntimeException('FakeRunner: the refresher call has no --report path');
            }
            file_put_contents($argv[$index + 1], (string) json_encode($report));
        }
        $this->call++;

        return ['status' => $exit, 'stdout' => '', 'stderr' => ''];
    }
}
