<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests\Bot;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Waynestate\Creed\Bot\CommandException;
use Waynestate\Creed\Bot\CommandRunner;

/**
 * The one injectable command runner the policy robot builds on.
 */
final class CommandRunnerTest extends TestCase
{
    #[Test]
    public function runReturnsStatusAndStreams(): void
    {
        $runner = new CommandRunner(static fn(array $argv): array => [
            'status' => 0,
            'stdout' => "a\nb\n",
            'stderr' => 'warn',
        ]);

        $result = $runner->run(['git', 'rev-parse', 'HEAD']);

        self::assertSame(0, $result['status']);
        self::assertSame("a\nb\n", $result['stdout']);
        self::assertSame('warn', $result['stderr']);
    }

    #[Test]
    public function aTransportFailureBecomesACommandException(): void
    {
        $runner = new CommandRunner(static function (array $argv): array {
            throw new RuntimeException('could not start git');
        });

        try {
            $runner->run(['git', 'status']);
            self::fail('expected a CommandException');
        } catch (CommandException $e) {
            self::assertStringContainsString('could not start git', $e->getMessage());
        }
    }

    #[Test]
    public function theDefaultTransportRunsAResalProcess(): void
    {
        $runner = new CommandRunner();

        $result = $runner->run([PHP_BINARY, '-r', 'fwrite(STDOUT, "hi"); fwrite(STDERR, "oh"); exit(7);']);

        self::assertSame(7, $result['status']);
        self::assertSame('hi', $result['stdout']);
        self::assertSame('oh', $result['stderr']);
    }
}
