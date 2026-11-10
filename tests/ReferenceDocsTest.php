<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Waynestate\Creed\Policy;

/**
 * Keeps the reference pages level with the code. Every public method of the
 * reader is named in the README's member list and has a row, with its exact
 * signature, in docs/reference/api.md; every property the schema allows in
 * `rules`, `gate` and `sessions` has a row in docs/reference/policy-format.md.
 *
 * Without it the pages drift quietly: by 0.1.11 four reader methods and three
 * rules had shipped with no line in either page. Creed Bratton can forget a
 * whole decade and call it a lifestyle; a reference page cannot.
 */
final class ReferenceDocsTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    #[Test]
    public function theReadmeNamesEveryPublicReaderMethod(): void
    {
        $readme = self::read('README.md');
        $section = self::between($readme, '### Reading the policy from PHP', '###');
        $missing = [];
        foreach (self::publicMethods() as $method) {
            if (!str_contains($section, '`' . self::memberName($method))) {
                $missing[] = $method->getName();
            }
        }

        self::assertSame([], $missing, 'README "Reading the policy from PHP" does not name: ' . implode(', ', $missing));
    }

    #[Test]
    public function theApiPageHasARowWithTheSignatureOfEveryPublicMethod(): void
    {
        $page = self::read('docs/reference/api.md');
        $missing = [];
        foreach (self::publicMethods() as $method) {
            $signature = self::signatureFromSource($method);
            if (!str_contains($page, '`' . $signature . '`')) {
                $missing[] = $signature;
            }
        }

        self::assertSame([], $missing, "docs/reference/api.md lacks these signatures, copied from src/Policy.php:\n" . implode("\n", $missing));
    }

    #[Test]
    public function thePolicyFormatPageNamesEveryRulesGateAndSessionsProperty(): void
    {
        $page = self::read('docs/reference/policy-format.md');
        /** @var array{properties: array<string, array{properties?: array<string, mixed>}>} $schema */
        $schema = json_decode(self::read('policy/policy.schema.json'), true, 512, JSON_THROW_ON_ERROR);

        $missing = [];
        foreach (['rules', 'gate', 'sessions'] as $block) {
            $section = self::between($page, '## ' . $block, "\n## ");
            foreach (array_keys($schema['properties'][$block]['properties'] ?? []) as $property) {
                if (!str_contains($section, '| `' . $property . '` |')) {
                    $missing[] = $block . '.' . $property;
                }
            }
        }

        self::assertSame([], $missing, 'docs/reference/policy-format.md has no table row for: ' . implode(', ', $missing));
    }

    #[Test]
    public function aSignatureIsReadFromTheSourceAsWritten(): void
    {
        $method = new ReflectionMethod(Policy::class, 'toolCooldownIgnore');

        self::assertSame('public function toolCooldownIgnore(string $tool): array', self::signatureFromSource($method));
        self::assertSame('toolCooldownIgnore(', self::memberName($method));
        self::assertSame('Policy::bundled(', self::memberName(new ReflectionMethod(Policy::class, 'bundled')));
    }

    /** @return list<ReflectionMethod> the reader's own public methods, in source order */
    private static function publicMethods(): array
    {
        $methods = array_filter(
            (new ReflectionClass(Policy::class))->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn(ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === Policy::class,
        );
        // usort reindexes, so the result is already a list.
        usort($methods, static fn(ReflectionMethod $a, ReflectionMethod $b): int => $a->getStartLine() <=> $b->getStartLine());

        return $methods;
    }

    /** How the README writes a member: `name(` for methods, `Policy::name(` for static constructors. */
    private static function memberName(ReflectionMethod $method): string
    {
        return ($method->isStatic() ? 'Policy::' : '') . $method->getName() . '(';
    }

    /** The declaration line from src/Policy.php, without its indentation. */
    private static function signatureFromSource(ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName(), FILE_IGNORE_NEW_LINES) ?: [];

        return trim($lines[$method->getStartLine() - 1] ?? '');
    }

    private static function between(string $text, string $start, string $end): string
    {
        $from = strpos($text, $start);
        self::assertNotFalse($from, 'Section not found: ' . $start);
        $after = $from + strlen($start);
        $to = strpos($text, $end, $after);

        return substr($text, $from, ($to === false ? strlen($text) : $to) - $from);
    }

    private static function read(string $path): string
    {
        $text = file_get_contents(self::ROOT . '/' . $path);
        self::assertIsString($text, 'Cannot read ' . $path);

        return $text;
    }
}
