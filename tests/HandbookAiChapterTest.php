<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\HandbookLinter;

/**
 * Keeps the AI and LLM chapter honest. The file exists under its title and
 * names the topics the decision turned on (WarriorGPT first, tokens out of
 * client code, public data only, labelled output), the README links it, and
 * every rule in it carries one of the three tags the linter accepts, so a
 * rule cannot quietly lose its tag.
 */
final class HandbookAiChapterTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    #[Test]
    public function theChapterExistsWithItsTitleAndTopics(): void
    {
        $path = self::ROOT . '/handbook/ai.md';
        self::assertFileExists($path, 'handbook/ai.md is missing.');
        $content = (string) file_get_contents($path);

        self::assertStringStartsWith("# AI and LLM use\n", $content, 'The chapter does not start with its title.');
        foreach (['WarriorGPT', 'VITE_', 'public data', 'AI-written'] as $topic) {
            self::assertStringContainsString($topic, $content, "handbook/ai.md never mentions {$topic}.");
        }
    }

    #[Test]
    public function theReadmeLinksTheChapter(): void
    {
        $readme = (string) file_get_contents(self::ROOT . '/handbook/README.md');

        self::assertStringContainsString('](ai.md)', $readme, 'handbook/README.md does not link ai.md.');
    }

    #[Test]
    public function everyRuleInTheChapterCarriesATagTheLinterAccepts(): void
    {
        $content = (string) file_get_contents(self::ROOT . '/handbook/ai.md');

        // The linter's own view of the chapter: no untagged rule may survive.
        self::assertSame([], (new HandbookLinter())->untaggedRules($content), 'handbook/ai.md has a rule that names nothing that holds it.');

        // Line by line, the way a reader sees it: every line that states a
        // rule (MUST or SHOULD, outside the RFC 2119 declaration) carries one
        // of the three tags the linter accepts.
        $untagged = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $index => $line) {
            if (str_contains($line, 'RFC 2119')) {
                continue; // the declaration names the key words; it is not a rule
            }
            $prose = (string) preg_replace('/`[^`]*`/', '', $line);
            if (preg_match('/\b(MUST|SHOULD)\b/', $prose) !== 1) {
                continue;
            }
            if (!self::isTagged($line)) {
                $untagged[] = ($index + 1) . ': ' . trim($line);
            }
        }

        self::assertSame([], $untagged, 'Lines in handbook/ai.md that state a rule but carry no tag:' . "\n" . implode("\n", $untagged));
    }

    /**
     * The three tags the linter accepts, kept level with
     * HandbookLinter::isTagged() so the test and the lint cannot drift apart.
     */
    private static function isTagged(string $text): bool
    {
        return preg_match('/PROCTOR checks?: `[a-z0-9][a-z0-9-]*`/', $text) === 1
            || preg_match('/\bHeld by: \S/', $text) === 1
            || str_contains($text, 'No check exists yet');
    }
}
