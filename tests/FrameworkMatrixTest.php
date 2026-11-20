<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\Tools\FrameworkMatrix\Diagnostics;
use Waynestate\Creed\Tools\FrameworkMatrix\Matrix;

require_once __DIR__ . '/../deploy/lib/PhpLifecycle/autoload.php';

/**
 * The framework x PHP matrix without Docker: the config, the plan from
 * Packagist p2 feeds, the readers that find PHP's diagnostics in a cell's
 * output, the merge into data/framework-php-tests.json, and the docs table.
 * The containers themselves run in scripts/framework-matrix.ps1 and .sh.
 *
 * Creed Bratton swears he tested every amp in the store. This file asks for receipts.
 */
final class FrameworkMatrixTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /** @return array{php_lines: list<string>, run_order: list<string>, image: string, prerelease_image: string, extensions: list<string>, frameworks: list<array{id: string, name: string, major: int, package: string, skeleton: string, skeleton_constraint: string, docroot: string, test: list<string>}>} */
    private static function config(): array
    {
        return Matrix::config(self::ROOT . '/tools/FrameworkMatrix/frameworks.json');
    }

    /** A minified p2 feed: the second entry inherits what it does not repeat. */
    private static function feed(string $package, string $newest, string $newestPhp, string $older, string $olderPhp): string
    {
        return (string) json_encode([
            'minified' => 'composer/2.0',
            'packages' => [$package => [
                ['name' => $package, 'version' => $newest, 'time' => '2026-11-17T09:58:33+00:00', 'require' => ['php' => $newestPhp]],
                ['version' => $newest . '-RC1'],
                ['version' => $older, 'require' => ['php' => $olderPhp]],
            ]],
        ]);
    }

    /** @return array<string, string> */
    private static function feeds(): array
    {
        return [
            'laravel/framework' => self::feed('laravel/framework', 'v13.43.0', '^8.3', 'v12.73.0', '^8.2'),
            'cakephp/cakephp' => self::feed('cakephp/cakephp', '5.4.4', '>=8.2', '4.6.6', '>=7.4.0,<9'),
            'slim/slim' => self::feed('slim/slim', '4.15.3', '~7.4.0 || ~8.0.0 || ~8.1.0 || ~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0', '3.13.1', '^8.1'),
        ];
    }

    #[Test]
    public function theConfigListsSixMajorsAndEveryLineOnce(): void
    {
        $config = self::config();
        self::assertSame(['laravel-13', 'laravel-12', 'cakephp-5', 'cakephp-4', 'slim-4', 'slim-3'], array_column($config['frameworks'], 'id'));
        self::assertSame(['8.0', '8.1', '8.2', '8.3', '8.4', '8.5', '8.6'], $config['php_lines']);
        $order = $config['run_order'];
        self::assertSame(['8.5', '8.6'], array_slice($order, 0, 2), '8.5 and 8.6 run first: they decide the page.');
        sort($order);
        self::assertSame($config['php_lines'], $order, 'run_order is every line, once.');
        self::assertContains('intl', $config['extensions'], 'CakePHP needs intl.');
        self::assertContains('mbstring', $config['extensions'], 'The official 8.6 image has no mbstring; PHPUnit and CakePHP need it.');
    }

    #[Test]
    public function thePlanRunsOnlyWhatRequirePhpAllows(): void
    {
        $plan = Matrix::plan(self::config(), self::feeds(), '2026-11-20');
        $allowed = [];
        foreach ($plan['cells'] as $cell) {
            if ($cell['allowed'] === true) {
                $allowed[$cell['framework']][] = $cell['php_line'];
            }
        }
        self::assertSame(['8.3', '8.4', '8.5', '8.6'], $allowed['laravel-13']);
        self::assertSame(['8.2', '8.3', '8.4', '8.5', '8.6'], $allowed['laravel-12']);
        self::assertSame(['8.2', '8.3', '8.4', '8.5', '8.6'], $allowed['cakephp-5']);
        self::assertSame(['8.0', '8.1', '8.2', '8.3', '8.4', '8.5', '8.6'], $allowed['cakephp-4']);
        self::assertSame(['8.0', '8.1', '8.2', '8.3', '8.4', '8.5'], $allowed['slim-4'], 'Slim 4.15 stops at ~8.5.0.');
        self::assertSame(['8.1', '8.2', '8.3', '8.4', '8.5', '8.6'], $allowed['slim-3']);
        self::assertSame('12.73.0', $plan['frameworks'][1]['release']['version'] ?? null, 'The older major comes from its own newest release, its require.php read through the minified feed.');

        $run = Matrix::runList($plan);
        self::assertSame(['laravel-13', '8.5', true], [$run[0]['framework'], $run[0]['php_line'], $run[0]['run']]);
        self::assertSame('8.6', $run[6]['php_line']);
        self::assertFalse($run[10]['run'], 'slim-4 on 8.6 is listed but not run.');
        self::assertSame(33, count(array_filter($run, static fn(array $c): bool => $c['run'])));
    }

    #[Test]
    public function anUnreadableFeedLetsComposerDecide(): void
    {
        $plan = Matrix::plan(self::config(), ['laravel/framework' => null] + self::feeds(), '2026-11-20');
        foreach ($plan['cells'] as $cell) {
            if ($cell['framework'] === 'laravel-13') {
                self::assertNull($cell['allowed']);
            }
        }
        self::assertTrue(Matrix::runList($plan)[0]['run']);
    }

    #[Test]
    public function scanFindsPhpsOwnMessagesWhereverTheyWereSent(): void
    {
        $cli = "Deprecated: DI\\create(): Implicitly marking parameter \$className as nullable is deprecated in /tmp/app/vendor/php-di/php-di/src/functions.php on line 35\n"
            . "PHP Warning:  Undefined array key \"x\" in /tmp/app/src/App.php on line 9\n"
            . "PHP Fatal error:  Uncaught Error: boom in /tmp/app/public/index.php on line 3\n";
        $found = Diagnostics::scan($cli);
        self::assertSame(['deprecation', 'warning', 'fatal'], array_column($found, 'kind'));
        self::assertSame('vendor/php-di/php-di/src/functions.php', $found[0]['file'], 'Paths are relative to the created app.');
        self::assertSame(35, $found[0]['line']);

        $html = "<br />\n<b>Deprecated</b>:  DI\\autowire(): Implicitly marking parameter \$className as nullable is deprecated in <b>/tmp/app/vendor/php-di/php-di/src/functions.php</b> on line <b>48</b><br />";
        self::assertSame([['kind' => 'deprecation', 'message' => 'DI\\autowire(): Implicitly marking parameter $className as nullable is deprecated', 'file' => 'vendor/php-di/php-di/src/functions.php', 'line' => 48, 'source' => 'app']], Diagnostics::scan($html));

        $laravel = '[2026-11-20 20:41:55] testing.WARNING: str_contains(): Passing null to parameter #1 is deprecated in /tmp/app/vendor/laravel/framework/src/Foo.php on line 12 [] []';
        self::assertSame('deprecation', Diagnostics::scan($laravel)[0]['kind'] ?? null, "Laravel's deprecations channel logs at WARNING; they are deprecations.");

        $cake = '<b>Deprecated</b> (16384): Something is deprecated [<b>CORE/src/Core/functions.php</b>, line <b>311</b>]';
        self::assertSame(['deprecation', 'CORE/src/Core/functions.php', 311], [Diagnostics::scan($cake)[0]['kind'] ?? null, Diagnostics::scan($cake)[0]['file'] ?? null, Diagnostics::scan($cake)[0]['line'] ?? null]);

        $composer = 'Deprecation Notice: Something old in phar:///usr/local/bin/composer/src/Composer/Foo.php:10';
        self::assertSame('tool', Diagnostics::scan($composer)[0]['source'] ?? null, "Composer's own notices are the tool's, not the framework's.");

        self::assertSame([], Diagnostics::scan("  - Installing symfony/deprecation-contracts (v3.8.1): Extracting archive\nWarning: the lock file is not up to date"), 'Package names and Composer warnings without a PHP location are not PHP diagnostics.');
    }

    #[Test]
    public function scanReadsPhpunitIssueSectionsButNotPhpunitsOwn(): void
    {
        $out = <<<'TXT'
            PHPUnit 12.5.45 by Sebastian Bergmann and contributors.

            1 test triggered 1 PHPUnit deprecation:

            1) Tests\Unit\ExampleTest::test_that_true_is_true
            Metadata in doc-comments is deprecated

            1 test triggered 2 deprecations:

            1) /tmp/app/vendor/nesbot/carbon/src/Carbon/Traits/Date.php:400
            Carbon::foo(): Implicitly marking parameter $tz as nullable is deprecated

            Triggered by:

            * Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response
              /tmp/app/tests/Feature/ExampleTest.php:15

            2) /tmp/app/app/Models/User.php:20
            Using null as an array offset is deprecated

            OK, but there were issues!
            Tests: 2, Assertions: 2, Deprecations: 2, PHPUnit Deprecations: 1.
            TXT;
        $found = Diagnostics::scan($out);
        self::assertSame(['deprecation', 'deprecation'], array_column($found, 'kind'));
        self::assertSame('vendor/nesbot/carbon/src/Carbon/Traits/Date.php', $found[0]['file']);
        self::assertSame(['tests' => 2, 'assertions' => 2, 'deprecations' => 2, 'phpunit deprecations' => 1], Diagnostics::phpunitSummary($out));
        self::assertSame(['tests' => 9, 'assertions' => 23], Diagnostics::phpunitSummary("....\nOK (9 tests, 23 assertions)\n"));
        self::assertSame(['tests' => 3, 'assertions' => 4, 'failures' => 1], Diagnostics::phpunitSummary("FAILURES!\nTests: 3, Assertions: 4, Failures: 1.\n"));
        self::assertNull(Diagnostics::phpunitSummary('Fatal error: no autoloader'));
    }

    #[Test]
    public function aPlatformRefusalNamesTheDeepestCause(): void
    {
        $out = <<<'TXT'
            Your requirements could not be resolved to an installable set of packages.

              Problem 1
                - Root composer.json requires cakephp/bake ^2.8 -> satisfiable by cakephp/bake[2.8.0-RC1, ..., 2.x-dev].
                - cakephp/cakephp[4.5.12, ..., 4.5.x-dev] require php >=7.4.0,<8.4 -> your php version (8.5.13) does not satisfy that requirement.
                - laminas/laminas-diactoros[2.26.0, ..., 2.26.x-dev] require php ~8.0.0 || ~8.1.0 || ~8.2.0 || ~8.3.0 -> your php version (8.5.13) does not satisfy that requirement.
            TXT;
        self::assertTrue(Diagnostics::isPlatformRefusal($out));
        self::assertStringStartsWith('- laminas/laminas-diactoros', (string) Diagnostics::firstError($out));
        self::assertTrue(Diagnostics::isPlatformRefusal('Could not find package laravel/laravel with version ^13.0 in a version installable using your PHP version, PHP extensions and Composer version.'));
        self::assertFalse(Diagnostics::isPlatformRefusal("Your requirements could not be resolved to an installable set of packages.\n  - foo/bar 1.0 requires ext-gmp * -> it is missing from your system."), 'A missing extension is the image, not the platform refusing.');
        self::assertSame('v13.11.0', Diagnostics::skeletonVersion('Installing laravel/laravel (v13.11.0)', 'laravel/laravel'));
    }

    #[Test]
    public function theMergeKeepsCellsThatDidNotRunAndMarksTheRest(): void
    {
        $plan = Matrix::plan(self::config(), self::feeds(), '2026-11-20');
        $new = ['framework' => 'laravel-13', 'php_line' => '8.5', 'php_version' => '8.5.13', 'image' => 'php:8.5-cli@sha256:abc', 'prerelease' => false, 'state' => 'pass'];
        $old = ['cells' => [['framework' => 'slim-4', 'php_line' => '8.4', 'php_version' => '8.4.28', 'state' => 'pass-deprecations', 'diagnostics' => ['deprecations' => 3, 'warnings' => 0], 'messages' => [['message' => 'x is deprecated', 'file' => 'vendor/a.php', 'line' => 1, 'step' => 'tests']]]]];
        $data = Matrix::merge($plan, [$new], $old, ['8.0' => null], '2026-11-20');
        $states = [];
        foreach ($data['cells'] as $c) {
            $states[$c['framework'] . '@' . $c['php_line']] = $c['state'];
        }
        self::assertSame('pass', $states['laravel-13@8.5']);
        self::assertSame('pass-deprecations', $states['slim-4@8.4'], 'A cell not run this time keeps its last result.');
        self::assertSame('no', $states['slim-4@8.6']);
        self::assertSame('no', $states['laravel-13@8.0']);
        self::assertSame('no-image', $states['cakephp-4@8.0']);
        self::assertSame('not-run', $states['cakephp-4@8.1']);
        self::assertSame('php:8.5-cli@sha256:abc', $data['images']['8.5']['image']);

        $md = Matrix::markdown($data);
        self::assertStringContainsString('| Laravel 13 (13.43.0) | - [', $md);
        self::assertStringContainsString('Slim 4 on PHP 8.4 (PHP 8.4.28): works, with 3 deprecations. First: "x is deprecated" (vendor/a.php:1, tests).', $md);
        self::assertStringContainsString('Slim 4 on PHP 8.6: not run; slim/slim 4.15.3 requires php `~7.4.0 || ~8.0.0', $md);
        self::assertStringNotContainsString('Laravel 13 on PHP 8.5', $md, 'A clean pass on a released PHP needs no footnote.');
        self::assertStringNotContainsString("\u{2014}", $md);
        self::assertStringNotContainsString("\u{2013}", $md);
    }

    #[Test]
    public function theSectionIsReplacedInPlace(): void
    {
        $page = "# Title\n\nIntro.\n\n" . Matrix::SECTION_START . "\nold\n" . Matrix::SECTION_END . "\n\nAfter.\n";
        $out = Matrix::replaceSection($page, Matrix::SECTION_START . "\nnew\n" . Matrix::SECTION_END);
        self::assertSame("# Title\n\nIntro.\n\n" . Matrix::SECTION_START . "\nnew\n" . Matrix::SECTION_END . "\n\nAfter.\n", $out);
    }

    #[Test]
    public function theCommittedResultsAndTheDocsPageAgree(): void
    {
        $file = self::ROOT . '/data/framework-php-tests.json';
        self::assertFileExists($file);
        $data = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($data);
        self::assertIsArray($data['cells']);
        self::assertCount(42, $data['cells'], 'Six majors by seven lines.');
        foreach ($data['cells'] as $cell) {
            self::assertIsArray($cell);
            self::assertContains($cell['state'], Matrix::STATES);
            if (in_array($cell['state'], ['pass', 'pass-deprecations', 'fail', 'refused'], true)) {
                self::assertIsString($cell['php_version'] ?? null, 'A cell that ran records its PHP patch.');
                self::assertMatchesRegularExpression('/^php:[\w.-]+@sha256:[0-9a-f]{64}$/', (string) ($cell['image'] ?? ''), 'A cell that ran records its image by digest.');
            }
            if (in_array($cell['state'], ['fail', 'refused'], true)) {
                self::assertNotEmpty($cell['first_error'] ?? null, 'A failed or refused cell keeps its first error.');
            }
        }
        $page = (string) file_get_contents(self::ROOT . '/docs/guides/framework-php-tests.md');
        $start = strpos($page, Matrix::SECTION_START);
        $end = strpos($page, Matrix::SECTION_END);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        self::assertSame(Matrix::markdown($data), substr($page, $start, $end + strlen(Matrix::SECTION_END) - $start), 'docs/guides/framework-php-tests.md is stale: run php bin/framework-matrix.php report.');
    }
}
