<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\Policy;

/**
 * The front page's "Inspect a version" form runs the reader's rules in the
 * browser, on a copy of the dates in policy/policy.json kept in
 * site/assets/creed.js (the static page has no PHP to ask). This test holds
 * the copy to the file: every component, line and date, and the grace
 * period, must match, so a policy change cannot leave the page answering
 * from last month's dates. When it fails, it prints the components block to
 * paste.
 *
 * Creed Bratton's driver's license and his memory disagree on his birthday.
 * The front page and the policy file are not allowed that luxury.
 */
final class FrontPageInspectorTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../site/assets/creed.js';

    #[Test]
    public function theInspectorCopyMatchesThePolicy(): void
    {
        $copy = self::inspectorCopy((string) file_get_contents(self::SCRIPT));
        $policy = Policy::bundled();

        self::assertSame(
            $policy->rules()['eol_grace_days'] ?? null,
            $copy['eol_grace_days'] ?? null,
            'site/assets/creed.js: POLICY.eol_grace_days differs from rules.eol_grace_days in policy.json.',
        );

        $expected = self::expectedComponents($policy);
        self::assertSame(
            $expected,
            $copy['components'] ?? null,
            "site/assets/creed.js: the inspector's dates differ from policy.json. Paste these lines into POLICY.components:\n"
            . self::asScript($expected),
        );
    }

    #[Test]
    public function theParserReadsTheScriptsLiteral(): void
    {
        $script = "  var POLICY = {\n    eol_grace_days: 30,\n    components: {\n"
            . "      php: { \"8.4\": [\"2026-12-31\", \"2028-12-31\"] },\n      vue: { \"3\": \"current\" }\n    }\n  };\n";

        self::assertSame(
            ['eol_grace_days' => 30, 'components' => ['php' => ['8.4' => ['2026-12-31', '2028-12-31']], 'vue' => ['3' => 'current']]],
            self::inspectorCopy($script),
        );
    }

    /**
     * The POLICY literal from the script, decoded. Its keys are bare
     * identifiers or quoted versions, and its values are numbers, strings and
     * arrays, so quoting the bare keys makes it JSON.
     *
     * @return array<string, mixed>
     */
    private static function inspectorCopy(string $script): array
    {
        $found = preg_match('~var POLICY = (\{.*?\n  \});~s', $script, $m);
        if ($found !== 1) {
            self::fail('site/assets/creed.js has no "var POLICY = {...};" block.');
        }
        $json = (string) preg_replace('~([{,]\s*)([a-z_]+)\s*:~', '$1"$2":', $m[1]);
        $data = json_decode($json, true);
        self::assertIsArray($data, 'The POLICY block in site/assets/creed.js is not a plain literal: ' . json_last_error_msg());

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * What the page must carry: [active_until, security_until] per line, or
     * "current" for a line marked current, in policy file order.
     *
     * @return array<string, array<string, string|list<string|null>>>
     */
    private static function expectedComponents(Policy $policy): array
    {
        $components = [];
        foreach ($policy->components() as $component) {
            foreach ($policy->versions($component) as $version) {
                $line = $policy->line($component, $version) ?? [];
                $components[$component][$version] = ($line['current'] ?? null) === true
                    ? 'current'
                    : [$line['active_until'] ?? null, $line['security_until'] ?? null];
            }
        }

        return $components;
    }

    /** @param array<string, array<string, string|list<string|null>>> $components */
    private static function asScript(array $components): string
    {
        $lines = [];
        foreach ($components as $component => $versions) {
            $json = str_replace(['":', '","', '],"'], ['": ', '", "', '], "'], (string) json_encode($versions, JSON_UNESCAPED_SLASHES));
            $lines[] = '      ' . $component . ': { ' . substr($json, 1, -1) . ' }';
        }

        return implode(",\n", $lines);
    }
}
