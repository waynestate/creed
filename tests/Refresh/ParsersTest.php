<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests\Refresh;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Waynestate\Creed\Refresh\EndOfLifeParser;
use Waynestate\Creed\Refresh\NodeDistParser;
use Waynestate\Creed\Refresh\NodeScheduleParser;
use Waynestate\Creed\Refresh\NpmDistTagsParser;
use Waynestate\Creed\Refresh\PackagistP2Parser;
use Waynestate\Creed\Refresh\PhpActiveParser;
use Waynestate\Creed\Refresh\PhpStatesParser;

/**
 * One case per source parser, run against the recorded feeds.
 */
final class ParsersTest extends TestCase
{
    private function feed(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/fixtures/feeds/' . $name);
    }

    #[Test]
    public function phpStatesGivesDatesPerBranch(): void
    {
        $states = PhpStatesParser::parse($this->feed('php-states.json'));
        self::assertSame('2027-12-31', $states['8.5']['active_until']);
        self::assertSame('2029-12-31', $states['8.5']['security_until']);
    }

    #[Test]
    public function phpActiveGivesLatestPatchPerBranch(): void
    {
        $active = PhpActiveParser::parse($this->feed('php.json'));
        self::assertMatchesRegularExpression('/^8\.5\.\d+$/', $active['8.5']);
    }

    #[Test]
    public function nodeScheduleMapsMaintenanceAndEnd(): void
    {
        $schedule = NodeScheduleParser::parse($this->feed('node-schedule.json'));
        self::assertSame('2027-10-20', $schedule['26']['maintenance']);
        self::assertSame('2029-04-30', $schedule['26']['end']);
    }

    #[Test]
    public function nodeDistGivesLatestPatchPerMajor(): void
    {
        $dist = NodeDistParser::parse($this->feed('node.json'));
        self::assertSame('26.13.0', ($dist['26'] ?? null));
    }

    #[Test]
    public function endOfLifeGivesDatesAndFlags(): void
    {
        $laravel = EndOfLifeParser::byName(EndOfLifeParser::parseReleases($this->feed('endoflife-laravel.json')));
        self::assertSame('2027-09-30', ($laravel['13'] ?? null)?->eoasFrom);

        $mysql = EndOfLifeParser::byName(EndOfLifeParser::parseReleases($this->feed('endoflife-mysql.json')));
        self::assertTrue($mysql['9.7']->isLts);
        self::assertNotTrue($mysql['26.10']->isLts);
    }

    #[Test]
    public function packagistGivesTheHighestStablePatchPerMajor(): void
    {
        $laravel = PackagistP2Parser::parse($this->feed('laravel.json'));
        self::assertSame('13.39.0', ($laravel['13'] ?? null));
    }

    #[Test]
    public function npmDistTagsGivesLatest(): void
    {
        self::assertSame('3.9.1', NpmDistTagsParser::parse($this->feed('npm-inertia-vue3.json'))['latest']);
    }

    #[Test]
    public function badFeedsThrowSoTheRunCanKeepLastKnownGood(): void
    {
        foreach ([
            PhpStatesParser::parse(...),
            PhpActiveParser::parse(...),
            NodeScheduleParser::parse(...),
            NodeDistParser::parse(...),
            EndOfLifeParser::parseReleases(...),
            PackagistP2Parser::parse(...),
            NpmDistTagsParser::parse(...),
        ] as $parser) {
            try {
                $parser('{ not json');
                self::fail('a malformed feed must throw');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
