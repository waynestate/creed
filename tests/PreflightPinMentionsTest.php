<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Keeps every sentence that states CREED's Preflight pin level with the pin.
 *
 * .gitlab-ci.yml is the record: each `preflight/<component>@<tag>` include.
 * The prose says the same thing in a handful of fixed ways ("pins its five
 * Preflight components to the 0.8.0 tag", "pinned to Preflight 0.8.0",
 * "the pin is 0.8.0", "the five components CREED uses"), and each pin round
 * used to leave one of them behind: the gap audits found a stale 0.2.0, 0.4.0
 * or 0.5.0 three times. A history mention ("since 0.2.0", "Preflight 0.5.0
 * added") is left alone; only the present-tense pin is held.
 *
 * Creed Bratton has given four different birth years in interviews. The pin
 * gets one.
 */
final class PreflightPinMentionsTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    private const WORDS = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
    ];

    #[Test]
    public function everyIncludeSharesOneTag(): void
    {
        [$components, $tags] = self::pins();

        self::assertNotSame([], $components, '.gitlab-ci.yml includes no Preflight component; the parser no longer matches it.');
        self::assertCount(1, $tags, 'The Preflight includes in .gitlab-ci.yml name more than one tag: ' . implode(', ', $tags) . '. Pin them to one release.');
    }

    #[Test]
    public function everyStatedPinIsThePin(): void
    {
        [$components, $tags] = self::pins();
        $tag = $tags[0];
        $count = count($components);
        $problems = [];

        foreach (self::files() as $path) {
            foreach (self::statements(self::flatten(self::read($path))) as $statement) {
                if ($statement['tag'] !== null && $statement['tag'] !== $tag) {
                    $problems[] = "{$path}: \"{$statement['text']}\" names {$statement['tag']}; the pin is {$tag}.";
                }
                if ($statement['count'] !== null && $statement['count'] !== $count) {
                    $problems[] = "{$path}: \"{$statement['text']}\" counts {$statement['count']} components; .gitlab-ci.yml includes {$count}.";
                }
            }
        }

        self::assertSame([], $problems, "A sentence states a Preflight pin that is not the one in .gitlab-ci.yml:\n" . implode("\n", $problems));
    }

    #[Test]
    public function theStatementParserReadsEachForm(): void
    {
        $text = self::flatten("CREED's own pipeline pins its four Preflight\ncomponents to the 0.5.0 tag. "
            . 'It pins its three Preflight components (`php-test`, `proctor` and `a11y`) to the 0.6.0 tag. '
            . 'Every include is pinned to one Preflight release, 0.8.0. '
            . "Preflight's `a11y` component (since 0.2.0; the pin is 0.4.0) runs axe. "
            . 'The five components CREED uses run PHPUnit (Preflight 0.3.1, the pin in `.gitlab-ci.yml`). '
            . 'Preflight 0.5.0 added an npm-audit component.');

        $found = array_map(static fn(array $s): array => [$s['tag'], $s['count']], self::statements($text));

        self::assertSame([['0.5.0', 4], ['0.6.0', 3], ['0.8.0', null], ['0.4.0', null], [null, 5], ['0.3.1', null]], $found);
    }

    /** @return array{0: list<string>, 1: list<string>} the included component names and the distinct tags */
    private static function pins(): array
    {
        preg_match_all('~/enterprise-apps/preflight/([a-z0-9-]+)@([0-9]+\.[0-9]+\.[0-9]+)~', self::read('.gitlab-ci.yml'), $m);

        return [array_values(array_unique($m[1])), array_values(array_unique($m[2]))];
    }

    /**
     * The present-tense pin statements in a text, in order.
     *
     * @return list<array{text: string, tag: string|null, count: int|null}>
     */
    private static function statements(string $text): array
    {
        $words = implode('|', array_keys(self::WORDS));
        $forms = [
            // "pins its four Preflight components to the 0.5.0 tag"
            '/pins? (?:its |all )?(?<count>' . $words . ')? ?Preflight components(?: \([^)]*\))? to (?:the )?(?<tag>\d+\.\d+\.\d+)/i',
            // "pinned to Preflight 0.8.0", "pinned to one Preflight release, 0.8.0"
            '/pinned to (?:one )?Preflight(?: release,)? (?<tag>\d+\.\d+\.\d+)/i',
            // "(since 0.2.0; the pin is 0.8.0)", on a sentence about Preflight
            '/Preflight(?:[^.]|\.(?=\d)){0,160}?the pin is (?<tag>\d+\.\d+\.\d+)/',
            // "The five components CREED uses"
            '/\b(?<count>' . $words . ') components CREED uses/i',
            // "(Preflight 0.8.0, the pin in `.gitlab-ci.yml`)"
            '/Preflight (?<tag>\d+\.\d+\.\d+), the pin\b/',
        ];

        $found = [];
        foreach ($forms as $form) {
            preg_match_all($form, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $count = isset($match['count']) && $match['count'][0] !== '' ? self::WORDS[strtolower($match['count'][0])] : null;
                $tag = isset($match['tag']) ? $match['tag'][0] : null; // a tag group, where a form has one, is never optional
                $found[$match[0][1]] = ['text' => $match[0][0], 'tag' => $tag, 'count' => $count];
            }
        }
        ksort($found);

        return array_values($found);
    }

    /**
     * The prose files that state the pin. The CHANGELOG is history and is left out.
     *
     * @return list<string>
     */
    private static function files(): array
    {
        $files = ['README.md', 'MAINTAINING.md', 'BEST_PRACTICES.md', 'OPEN_QUESTIONS.md', 'site/index.html', '.gitlab-ci.yml'];
        foreach ([...glob(self::ROOT . '/handbook/*.md') ?: [], ...glob(self::ROOT . '/docs/*.md') ?: [], ...glob(self::ROOT . '/docs/*/*.md') ?: []] as $file) {
            $relative = substr($file, strlen(self::ROOT) + 1);
            if ($relative !== 'docs/changelog.md') {
                $files[] = $relative;
            }
        }

        return $files;
    }

    /** One line of plain text: tags dropped, entities decoded, every run of white space (and comment hashes) one space. */
    private static function flatten(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);

        return (string) preg_replace('/\s+(?:#\s+)?/', ' ', $text);
    }

    private static function read(string $path): string
    {
        $text = file_get_contents(self::ROOT . '/' . $path);
        self::assertIsString($text, 'Cannot read ' . $path);

        return $text;
    }
}
