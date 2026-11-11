<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\Tools\Mysql56\Probe;
use Waynestate\Creed\Tools\Mysql56\Probes;

/**
 * Keeps the databases chapter and its MySQL 5.6 probes level. Every probe the
 * chapter cites exists, every probe is cited, the count the chapter prints is
 * the count there is, and no claim is left marked "not yet confirmed". The
 * probes themselves need a live server, so they run in the mysql56-probe and
 * mysql84-probe CI jobs and in scripts/mysql56-probe.sh or .ps1, not here.
 *
 * Creed Bratton says he once sat in with a band nobody can prove existed. The
 * databases chapter no longer gets to say anything like that about 5.6.
 */
final class Mysql56ProbesTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /** The pins Preflight 0.8.0's mysql56-migrate component defaults to, which the scripts must match. */
    private const MYSQL_IMAGE = 'mysql:5.6.51@sha256:20575ecebe6216036d25dab5903808211f1e9ba63dc7825ac20cb975e34cfcae';

    private const PHP_IMAGE = 'php:8.3-cli-trixie@sha256:628102249d93494233b54490b785f12db9cd60f4dc89416dfca15973f2125f10';

    /** The 8.4 the probes' second answers were read from: the multi-arch list digest, so no amd64 runner is needed. */
    private const MYSQL84_IMAGE = 'mysql:8.4.13@sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902';

    #[Test]
    public function everyProbeIsWellFormed(): void
    {
        $ids = [];
        foreach (Probes::all() as $probe) {
            self::assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $probe->id, 'A probe id is lowercase words joined by hyphens.');
            self::assertTrue($probe->expectIsWellFormed(), "Probe {$probe->id} expects '{$probe->expect}', which is not ok, error:NNNN, warning:NNNN or value:TEXT.");
            self::assertStringEndsWith('.', $probe->claim, "Probe {$probe->id}'s claim is a sentence.");
            $ids[] = $probe->id;

            // The runner sweeps creed_probe_* tables, so a probe that names any other table would leave it behind.
            preg_match_all('/(?:create table|alter table|insert into|from|join|delete)\s+`([^`]+)`/i', implode(' ', [...$probe->setup, $probe->sql]), $m);
            foreach ($m[1] as $table) {
                self::assertStringStartsWith('creed_probe_', $table, "Probe {$probe->id} touches `{$table}`, outside the creed_probe_ tables the runner cleans up.");
            }
        }

        self::assertSame(array_values(array_unique($ids)), $ids, 'Two probes share an id.');
    }

    #[Test]
    public function theChapterCitesEveryProbeAndOnlyRealOnes(): void
    {
        $chapter = self::read('handbook/databases.md');
        $ids = array_map(static fn(Probe $p): string => $p->id, Probes::all());

        // "probe `a`" and "probes `a`, `b`" both cite.
        preg_match_all('/\bprobes?\s+((?:`[a-z0-9-]+`(?:,\s*|\s+and\s+)?)+)/', $chapter, $m);
        $cited = [];
        foreach ($m[1] as $list) {
            preg_match_all('/`([a-z0-9-]+)`/', $list, $names);
            $cited = [...$cited, ...$names[1]];
        }
        $cited = array_values(array_unique($cited));

        self::assertSame([], array_values(array_diff($cited, $ids)), 'handbook/databases.md cites a probe that tools/Mysql56/Probes.php does not have.');
        self::assertSame([], array_values(array_diff($ids, $cited)), 'tools/Mysql56/Probes.php has a probe that handbook/databases.md never cites.');
    }

    #[Test]
    public function theChapterPrintsTheRealCountAndLeavesNothingUnconfirmed(): void
    {
        $chapter = self::read('handbook/databases.md');
        $count = count(Probes::all());

        self::assertMatchesRegularExpression('/All ' . $count . ' agreed/', $chapter, "The chapter's proof paragraph says how many probes agreed; there are {$count}.");
        self::assertMatchesRegularExpression('/all ' . $count . ' agreed with their 8\.4 answers/', $chapter, "The chapter's proof paragraph says how many probes agreed on 8.4; there are {$count}.");
        self::assertMatchesRegularExpression('/\b' . $count . ' probes\b/', self::read('README.md'), "The README's Tests section counts the probes; there are {$count}.");
        self::assertStringNotContainsString('not yet confirmed', $chapter, 'A claim in handbook/databases.md is still marked "not yet confirmed": write a probe for it, or say why none can.');
    }

    #[Test]
    public function theScriptsAndTheCiJobUseTheComponentsPins(): void
    {
        foreach (['scripts/mysql56-probe.sh', 'scripts/mysql56-probe.ps1'] as $script) {
            $text = self::read($script);
            self::assertStringContainsString(self::MYSQL_IMAGE, $text, "{$script} does not start the mysql56-migrate component's MySQL pin.");
            self::assertStringContainsString(self::PHP_IMAGE, $text, "{$script} does not default to the mysql56-migrate component's PHP image.");
            self::assertStringContainsString(self::MYSQL84_IMAGE, $text, "{$script} does not start the 8.4 the probes' second answers were read from.");
            self::assertStringContainsString('php bin/mysql56-probe.php', $text, "{$script} does not run the probes.");
        }

        $ci = self::read('.gitlab-ci.yml');
        self::assertMatchesRegularExpression('~preflight/mysql56-migrate@\d+\.\d+\.\d+\s+inputs:\s+job_name: mysql56-probe\s+migrate_command: "php bin/mysql56-probe\.php~', $ci, 'The mysql56-probe CI job is missing or no longer runs bin/mysql56-probe.php.');
        // The 8.4 job leaves the amd64 tag off: only jobs that need amd64 carry it.
        self::assertMatchesRegularExpression(
            '~preflight/mysql56-migrate@\d+\.\d+\.\d+\s+inputs:\s+job_name: mysql84-probe\s+service_image: "' . preg_quote(self::MYSQL84_IMAGE, '~') . '"\s+tags: \[\]\s+migrate_command: "php bin/mysql56-probe\.php~',
            $ci,
            'The mysql84-probe CI job is missing, starts another MySQL, carries a runner tag, or no longer runs bin/mysql56-probe.php.',
        );
    }

    #[Test]
    public function whatWorksOn56StillWorksOn84(): void
    {
        // The chapter's "Moving to 8.4" promise: code that keeps to the conventions runs unchanged. Every statement
        // 5.6 runs, 8.4 runs too, except the one the chapter already says to avoid: a row that breaks a CHECK, which
        // 5.6 lets in only because it ignores the constraint. The live run in the mysql84-probe job checks the server.
        $avoided = ['check-constraint-ignored'];
        foreach (Probes::all() as $probe) {
            if ($probe->expect === 'ok' && !in_array($probe->id, $avoided, true)) {
                self::assertSame('ok', $probe->expectationFor('8.4.13'), "Probe {$probe->id} runs on 5.6 but not on 8.4, so the move would break code that follows the chapter.");
            }
        }
    }

    #[Test]
    public function eachServerIsJudgedAgainstItsOwnAnswer(): void
    {
        $both = new Probe('x', 'X.', [], 'select 1', 'error:1064', 'ok');
        $same = new Probe('y', 'Y.', [], 'select 1', 'error:1067');

        self::assertSame('error:1064', $both->expectationFor('5.6.51'));
        self::assertSame('ok', $both->expectationFor('8.4.13'));
        self::assertSame('error:1064', $both->expectationFor('8.0.40'), 'A server that is neither 5.6 nor 8.4 is judged as 5.6.');
        self::assertSame('error:1067', $same->expectationFor('8.4.13'), 'A probe without an 8.4 answer expects the 5.6 one there too.');

        self::assertNull($both->verdict(null, [], null, 'ok'));
        self::assertSame('expected ok, got error 1064', $both->verdict(1064, [], null, 'ok'));
        self::assertFalse((new Probe('z', 'Z.', [], 'select 1', 'ok', 'maybe'))->expectIsWellFormed());
    }

    #[Test]
    public function theVerdictReadsEachKindOfExpectation(): void
    {
        $probe = static fn(string $expect): Probe => new Probe('x', 'X.', [], 'select 1', $expect);

        self::assertNull($probe('ok')->verdict(null, [], null));
        self::assertSame('expected ok, got error 1064', $probe('ok')->verdict(1064, [], null));

        self::assertNull($probe('error:1064')->verdict(1064, [], null));
        self::assertSame('expected error 1064, got error 1054', $probe('error:1064')->verdict(1054, [], null));
        self::assertSame('expected error 1064, but the statement ran', $probe('error:1064')->verdict(null, [], null));

        self::assertNull($probe('warning:1478')->verdict(null, [1681, 1478], null));
        self::assertSame('expected warning 1478, got none', $probe('warning:1478')->verdict(null, [], null));
        self::assertSame('expected warning 1478, got 1681', $probe('warning:1478')->verdict(null, [1681], null));

        self::assertNull($probe('value:0')->verdict(null, [], '0'));
        self::assertSame('expected value 0, got 1', $probe('value:0')->verdict(null, [], '1'));
        self::assertSame('expected value 0, got no row', $probe('value:0')->verdict(null, [], null));
        self::assertSame('expected value:0, got error 1193', $probe('value:0')->verdict(1193, [], null));

        self::assertFalse($probe('maybe')->expectIsWellFormed());
        self::assertFalse($probe('error:12')->expectIsWellFormed());
    }

    private static function read(string $path): string
    {
        $text = file_get_contents(self::ROOT . '/' . $path);
        self::assertIsString($text, 'Cannot read ' . $path);

        return $text;
    }
}
