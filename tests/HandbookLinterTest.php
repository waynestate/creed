<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\HandbookLinter;

final class HandbookLinterTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures';

    /**
     * Each fixture and the violations the linter must report.
     *
     * @return array<string, array{0: string, 1: list<array{line: int, keyword: string}>}>
     */
    public static function lintProvider(): array
    {
        return [
            'lowercase must outside code is a violation' => [
                'handbook-lint-declares-must.md',
                [['line' => 5, 'keyword' => 'must']],
            ],
            'lowercase must in a code span is not flagged' => [
                'handbook-lint-declares-must-in-code-span.md',
                [],
            ],
            'lowercase must in a code block is not flagged' => [
                'handbook-lint-declares-must-in-code-block.md',
                [],
            ],
            'a lint allow comment excuses the line' => [
                'handbook-lint-declares-must-allowed.md',
                [],
            ],
            'a file without an RFC 2119 declaration is not linted' => [
                'handbook-lint-no-declaration.md',
                [],
            ],
            'uppercase keywords are not flagged' => [
                'handbook-lint-declares-uppercase.md',
                [],
            ],
            'the two-word form must not wins over must' => [
                'handbook-lint-declares-must-not.md',
                [['line' => 5, 'keyword' => 'must not']],
            ],
            'a plain-English may without an allow comment is a violation' => [
                'handbook-lint-declares-may-plain.md',
                [['line' => 5, 'keyword' => 'may']],
            ],
        ];
    }

    /**
     * @param list<array{line: int, keyword: string}> $expected
     */
    #[Test]
    #[DataProvider('lintProvider')]
    public function lintReportsTheExpectedViolations(string $fixture, array $expected): void
    {
        $content = (string) file_get_contents(self::FIXTURES . '/' . $fixture);
        self::assertSame($expected, (new HandbookLinter())->lint($content));
    }

    #[Test]
    public function theRealHandbookPassesTheLint(): void
    {
        $linter = new HandbookLinter();
        $files = glob(__DIR__ . '/../handbook/*.md') ?: [];
        self::assertNotEmpty($files, 'The handbook directory has no markdown files.');

        $problems = [];
        foreach ($files as $file) {
            $violations = $linter->lint((string) file_get_contents($file));
            foreach ($violations as $violation) {
                $problems[] = basename($file) . ':' . $violation['line'] . ' ' . $violation['keyword'];
            }
        }

        self::assertSame(
            [],
            $problems,
            'The handbook has lowercase RFC 2119 keywords: ' . implode(', ', $problems),
        );
    }
}
