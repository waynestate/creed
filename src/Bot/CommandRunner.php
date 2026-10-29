<?php

declare(strict_types=1);

namespace Waynestate\Creed\Bot;

use RuntimeException;

/**
 * Runs one external command and reports its exit status and streams.
 * Every process call the policy robot makes (the refresher subprocess
 * today, git later) goes through this one small injectable class, so
 * tests can fake the whole command line without touching a shell.
 */
final class CommandRunner
{
    /**
     * @var callable(list<string> $argv): array{status: int, stdout: string, stderr: string}
     */
    private $transport;

    /**
     * @param (callable(list<string> $argv): array{status: int, stdout: string, stderr: string})|null $transport
     *        Defaults to the proc_open transport.
     */
    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport ?? self::processTransport();
    }

    /**
     * The default transport: proc_open with an argument array, so no shell
     * ever interprets a path.
     *
     * @return callable(list<string> $argv): array{status: int, stdout: string, stderr: string}
     */
    public static function processTransport(): callable
    {
        return static function (array $argv): array {
            $process = proc_open(array_values($argv), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('could not start ' . $argv[0]);
            }
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
        };
    }

    /**
     * Run one command.
     *
     * @param list<string> $argv the full command line, starting with the binary
     *
     * @return array{status: int, stdout: string, stderr: string}
     *
     * @throws CommandException when the command could not be started
     */
    public function run(array $argv): array
    {
        try {
            return ($this->transport)($argv);
        } catch (RuntimeException $e) {
            throw new CommandException($e->getMessage(), previous: $e);
        }
    }
}
