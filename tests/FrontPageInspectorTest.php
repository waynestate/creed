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
 * the copy to the file: every component, line and date, the grace period,
 * and what each line requires (the certificate's "Runs on" row), must match,
 * so a policy change cannot leave the page answering from last month's
 * dates. When it fails, it prints the block to paste. It also holds Table 2-1
 * in both site/index.html and its Markdown twin to the reader: every
 * rendered line's Latest patch cell must be the policy's latest_patch (or
 * n/a when the line has none), so a policy refresh cannot leave either copy
 * printing last month's patch.
 *
 * Creed Bratton's driver's license and his memory disagree on his birthday.
 * The front page and the policy file are not allowed that luxury.
 */
final class FrontPageInspectorTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../site/assets/creed.js';

    private const PAGE_HTML = __DIR__ . '/../site/index.html';

    private const PAGE_MD = __DIR__ . '/../site/index.md';

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

        $requires = self::expectedRequires($policy);
        self::assertSame(
            $requires,
            $copy['requires'] ?? null,
            "site/assets/creed.js: the inspector's requires copy differs from policy.json. Paste these lines into POLICY.requires:\n"
            . self::asScript($requires),
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

    #[Test]
    public function theAdoptedEditionsPatchCellsMatchThePolicy(): void
    {
        $policy = Policy::bundled();

        $htmlRows = self::adoptedRows((string) file_get_contents(self::PAGE_HTML));
        self::assertNotEmpty($htmlRows, 'site/index.html: no <tr data-c data-v> rows found in Table 2-1.');
        foreach ($htmlRows as [$component, $version, $cell]) {
            self::patchCellMatchesPolicy($policy, $component, $version, $cell, 'site/index.html');
        }

        $mdRows = self::markdownRows((string) file_get_contents(self::PAGE_MD));
        self::assertNotEmpty($mdRows, 'site/index.md: no version rows found in Table 2-1.');
        foreach ($mdRows as [$component, $version, $cell]) {
            self::patchCellMatchesPolicy($policy, $component, $version, $cell, 'site/index.md');
        }
    }

    /**
     * The rendered rows of Table 2-1 in the HTML front page, as
     * [component, version, latest-patch cell]. Current lines merge their
     * middle cells (two <td>s) and carry no patch to check; any other shape
     * is a markup change this test must not read silently.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function adoptedRows(string $html): array
    {
        $rows = [];
        preg_match_all('~<tr\b[^>]*\bdata-c="([^"]+)"[^>]*\bdata-v="([^"]+)"[^>]*>(.*?)</tr>~s', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            preg_match_all('~<td[^>]*>(.*?)</td>~s', $match[3], $cells);
            if (count($cells[0]) === 4) {
                $rows[] = [$match[1], $match[2], trim(strip_tags($cells[0][2]))];
            } elseif (count($cells[0]) !== 2) {
                self::fail("site/index.html: Table 2-1 row {$match[1]} {$match[2]} has " . count($cells[0]) . ' <td> cells; expected 4, or 2 for a current line.');
            }
        }

        return $rows;
    }

    /**
     * The version rows of Table 2-1 in the Markdown twin, as
     * [component, version, latest-patch cell]. The group header rows name
     * the component; separator rows and the current lines (their patch cell
     * is merged away) are skipped.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function markdownRows(string $md): array
    {
        $rows = [];
        $group = null;
        $inTable = false;
        foreach (preg_split('~\r\n|\r|\n~', $md) ?: [] as $line) {
            $line = trim($line);
            if (!$inTable) {
                $inTable = preg_match('~^\|\s*Runtime\s*\|.*\bLatest patch\b~', $line) === 1;
                continue;
            }
            if (!str_starts_with($line, '|')) {
                break;
            }
            $cells = array_map('trim', preg_split('~\|~', $line) ?: []);
            array_shift($cells);
            array_pop($cells);
            if ($cells[0] === '' || str_starts_with($cells[0], '---')) {
                continue;
            }
            if (count(array_filter($cells, static fn(string $cell): bool => $cell !== '')) <= 1) {
                $group = $cells[0];
                continue;
            }
            if ($cells[3] === '') {
                continue;
            }
            $component = self::markdownComponent($group, $cells[0]);
            $version = $component === 'vue' || $component === 'inertia'
                ? (string) preg_replace('~^(Vue|Inertia)\s+~', '', $cells[0])
                : $cells[0];
            $rows[] = [$component, $version, $cells[3]];
        }

        return $rows;
    }

    /**
     * The policy component of a Markdown twin row: the group header names
     * PHP, Laravel, Node and MySQL outright; under "Vue and Inertia" the row
     * label decides.
     */
    private static function markdownComponent(?string $group, string $label): string
    {
        return match (true) {
            $group !== null && str_starts_with($group, 'PHP') => 'php',
            $group !== null && str_starts_with($group, 'Laravel') => 'laravel',
            $group !== null && str_starts_with($group, 'Node') => 'node',
            $group !== null && str_starts_with($group, 'MySQL') => 'mysql',
            str_starts_with($label, 'Inertia') => 'inertia',
            str_starts_with($label, 'Vue') => 'vue',
            default => self::fail("site/index.md: Table 2-1 row '{$label}' under group '{$group}' does not name a known component."),
        };
    }

    /**
     * Hold one rendered Latest patch cell to policy.json: a line with a
     * latest_patch must print it, a line without one must print n/a. The
     * failure names the component, what the page says and what policy.json
     * says.
     */
    private static function patchCellMatchesPolicy(Policy $policy, string $component, string $version, string $cell, string $where): void
    {
        $known = in_array($component, $policy->components(), true)
            && in_array($version, $policy->versions($component), true);
        $expected = $known ? $policy->latestPatch($component, $version) : null;
        if ($expected === null) {
            self::assertSame(
                'n/a',
                $cell,
                "{$where}: {$component} {$version} has no latest_patch in policy.json, but its Latest patch cell reads '{$cell}'.",
            );

            return;
        }
        self::assertSame(
            $expected,
            $cell,
            "{$where}: the {$component} {$version} Latest patch cell is stale: the page says '{$cell}', policy.json says '{$expected}'. Run composer site:versions.",
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

    /**
     * What the "Runs on" row reads: per component, the lines with a requires
     * object, as php [min, max] plus the other keys as they stand in the file.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private static function expectedRequires(Policy $policy): array
    {
        $requires = [];
        foreach ($policy->components() as $component) {
            foreach ($policy->versions($component) as $version) {
                $line = $policy->requires($component, $version);
                if ($line === null) {
                    continue;
                }
                $range = $policy->phpRange($component, $version);
                if ($range !== null) {
                    $line['php'] = [$range['min'], $range['max']];
                }
                $requires[$component][$version] = $line;
            }
        }

        return $requires;
    }

    /** @param array<string, array<string, mixed>> $components */
    private static function asScript(array $components): string
    {
        $lines = [];
        foreach ($components as $component => $versions) {
            $json = str_replace(['":', '","', '],"'], ['": ', '", "', '], "'], (string) json_encode($versions, JSON_UNESCAPED_SLASHES));
            // Nested objects (a requires line) get bare keys and the script's spacing.
            $json = (string) preg_replace(['~([{,]) ?"([a-z_]+)": ~', '~"\}(?!$)~', '~\},"~'], ['$1 $2: ', '" }', '}, "'], $json);
            $lines[] = '      ' . $component . ': { ' . substr($json, 1, -1) . ' }';
        }

        return implode(",\n", $lines);
    }
}
