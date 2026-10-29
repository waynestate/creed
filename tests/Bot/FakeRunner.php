<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests\Bot;

use RuntimeException;

/**
 * A scripted command transport for the policy robot tests. Calls whose
 * argv names refresh-policy.php pop the next scripted exit code and write
 * the next report to the requested --report path. Git calls return the
 * scripted result for their subcommand, or success with empty streams.
 * Every call is recorded; any other call throws, so a test can never
 * silently run a real command.
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

    /**
     * Scripted results for git subcommands, keyed by the subcommand word
     * (checkout, add, diff, commit, push). Unscripted subcommands succeed
     * with empty streams, so a test scripts only what it cares about.
     *
     * @var array<string, array{status: int, stdout: string, stderr: string}>
     */
    public array $git = [];

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
            if (($argv[0] ?? '') === 'git') {
                // The subcommand is the first argument after "git" that is
                // not an option, so "git -c x=y commit" keys under commit.
                // "-c" consumes its value, which is not an option itself.
                $sub = '';
                for ($i = 1, $count = count($argv); $i < $count; $i++) {
                    if ($argv[$i] === '-c') {
                        $i++;
                        continue;
                    }
                    if (!str_starts_with($argv[$i], '-')) {
                        $sub = $argv[$i];
                        break;
                    }
                }

                return $this->git[$sub] ?? ['status' => 0, 'stdout' => '', 'stderr' => ''];
            }

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
