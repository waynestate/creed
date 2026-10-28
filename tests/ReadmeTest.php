<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\State;

/**
 * Keeps the README honest: every ```php block that uses the reader is run
 * against the bundled policy, and the values the README prints next to it are
 * asserted to be the values the reader actually returns.
 *
 * A snippet that cannot run as-is (it needs setup the README does not show)
 * is marked with an HTML comment `<!-- readme-test: skip -->`; the test then
 * leaves it alone. The current README has no such snippet: its one reader
 * example runs as-is on a fixed date.
 */
final class ReadmeTest extends TestCase
{
    private const README = __DIR__ . '/../README.md';

    #[Test]
    public function readmePhpSnippetsMatchTheReader(): void
    {
        $readme = (string) file_get_contents(self::README);
        $blocks = self::phpBlocks($readme);
        self::assertNotEmpty($blocks, 'The README has no PHP code blocks.');

        $checked = 0;
        foreach ($blocks as $block) {
            if (!str_contains($block, 'Waynestate\\Creed')) {
                continue; // not a reader snippet
            }
            if (self::isSkipped($block)) {
                continue; // marked as not runnable as-is
            }
            $checked++;
            $expected = self::expectedOutputs($block);
            self::assertNotEmpty($expected, 'A reader snippet has no expected outputs to check.');
            $actual = self::runSnippet($block, array_keys($expected));
            foreach ($expected as $var => $expression) {
                // eval does not inherit this file's use-imports, so the
                // reader's State enum is imported into the evaluated code.
                $evaluated = eval('use Waynestate\Creed\State; return ' . $expression . ';');
                self::assertSame(
                    $evaluated,
                    $actual[$var],
                    "README snippet: \${$var} should be {$expression}.",
                );
            }
        }
        self::assertGreaterThan(0, $checked, 'No runnable reader snippet was checked.');
    }

    #[Test]
    public function aSnippetMarkedSkipIsNotRun(): void
    {
        $skipped = "<!-- readme-test: skip -->\nuse Waynestate\\Creed\\Policy;\n";
        $plain = "use Waynestate\\Creed\\Policy;\n";

        self::assertTrue(self::isSkipped($skipped));
        self::assertFalse(self::isSkipped($plain));
    }

    /**
     * The ```php blocks of the README, in order.
     *
     * @return list<string>
     */
    private static function phpBlocks(string $readme): array
    {
        $blocks = [];
        if (preg_match_all('/^```php[ \t]*\r?\n(.*?)^```[ \t]*$/ms', $readme, $matches) === 1) {
            $blocks = $matches[1];
        }

        return $blocks;
    }

    /**
     * Whether a snippet is marked as not runnable as-is.
     */
    private static function isSkipped(string $block): bool
    {
        return str_contains($block, '<!-- readme-test: skip -->');
    }

    /**
     * The expected outputs of a snippet: each `$var = ...;` line that is
     * followed by a `// <value>` comment.
     *
     * @return array<string, string>
     */
    private static function expectedOutputs(string $block): array
    {
        $expected = [];
        $lines = preg_split('/\r\n|\r|\n/', $block) ?: [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^\$(\w+)\s*=\s*.+;\s*$/', $line, $m) !== 1) {
                continue;
            }
            $next = $lines[$i + 1] ?? '';
            if (preg_match('/^\s*\/\/\s*(.+?)\s*$/', $next, $c) === 1) {
                $expected[$m[1]] = $c[1];
            }
        }

        return $expected;
    }

    /**
     * Run a snippet as-is and return the named variables it assigns.
     *
     * @param list<string> $vars
     *
     * @return array<string, mixed>
     */
    private static function runSnippet(string $block, array $vars): array
    {
        $assignments = implode(', ', array_map(
            static fn(string $v): string => "'{$v}' => \${$v}",
            $vars,
        ));
        $code = "<?php\n" . $block . "\nreturn [" . $assignments . "];\n";
        $tmp = tempnam(sys_get_temp_dir(), 'creed-readme-') . '.php';
        file_put_contents($tmp, $code);
        try {
            $result = include $tmp;
        } finally {
            unlink($tmp);
        }
        if (!is_array($result)) {
            self::fail('The README snippet did not return its variables.');
        }
        /** @var array<string, mixed> $result */
        return $result;
    }
}
