<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests\Bot;

use PHPUnit\Framework\Attributes\Test;
use Waynestate\Creed\Bot\PolicyBot;

/**
 * The merge request title and body builders, straight from a report.
 */
final class MergeRequestTest extends TestCase
{
    #[Test]
    public function theTitleIsExact(): void
    {
        [$bot] = $this->makeBot([], []);

        self::assertSame('policy: refresh from upstream sources', $bot->mrTitle());
        self::assertSame(PolicyBot::MR_TITLE, $bot->mrTitle());
    }

    #[Test]
    public function theBodyListsEveryChangeWithItsClass(): void
    {
        [$bot] = $this->makeBot([], []);

        $body = $bot->mrBody($this->report(
            changes: [
                $this->changeItem(['class' => 'tighten']),
                $this->changeItem(['class' => 'neutral']),
                $this->changeItem(['class' => 'loosen-warning']),
                $this->changeItem([
                    'field' => 'security_until',
                    'old' => '2026-12-31',
                    'new' => '2027-12-31',
                    'class' => 'loosen-fail',
                ]),
            ],
            held: [$this->changeItem([
                'field' => 'active_until',
                'old' => null,
                'new' => '2027-11-30',
                'class' => 'loosen-fail',
                'reason' => 'confirm before writing',
            ])],
            refused: [$this->changeItem([
                'component' => 'composer',
                'line' => '2.7',
                'field' => 'minimum',
                'old' => '2.7.0',
                'new' => null,
                'class' => 'refused',
                'reason' => 'raise tools.composer.minimum',
            ])],
            disagreements: [$this->disagreementItem()],
            sourceErrors: ['endoflife-mysql: could not fetch'],
        ));

        $expected = <<<'BODY'
Auto-refresh from the upstream sources on 2026-10-29.

Changes:
- php 8.3: latest_patch 8.3.35 -> 8.3.36 [tighten]
- php 8.3: latest_patch 8.3.35 -> 8.3.36 [neutral]
- php 8.3: latest_patch 8.3.35 -> 8.3.36 [loosen-warning]
- php 8.3: security_until 2026-12-31 -> 2027-12-31 [loosen-fail]
Held:
- php 8.3: active_until (none) -> 2027-11-30 [loosen-fail]: confirm before writing
Refused:
- composer 2.7: minimum 2.7.0 -> (removed) [refused]: raise tools.composer.minimum
Disagreements:
- node 26 active_until: nodejs/schedule says 2027-10-20, endoflife.date/nodejs says 2027-10-27
Sources that failed this run:
- endoflife-mysql: could not fetch
BODY;

        self::assertSame($expected, $body);
    }

    #[Test]
    public function anEmptyReportBodyHasOnlyTheHeader(): void
    {
        [$bot] = $this->makeBot([], []);

        self::assertSame('Auto-refresh from the upstream sources on 2026-10-29.', $bot->mrBody($this->report()));
    }

    #[Test]
    public function theBodyOmitsEmptySections(): void
    {
        [$bot] = $this->makeBot([], []);

        $body = $bot->mrBody($this->report(changes: [$this->changeItem()]));

        self::assertStringContainsString("Changes:\n- php 8.3: latest_patch 8.3.35 -> 8.3.36 [neutral]", $body);
        self::assertStringNotContainsString('Held:', $body);
        self::assertStringNotContainsString('Refused:', $body);
        self::assertStringNotContainsString('Disagreements:', $body);
        self::assertStringNotContainsString('Sources that failed this run:', $body);
    }

    #[Test]
    public function aMissingGeneratedOnDropsTheDate(): void
    {
        [$bot] = $this->makeBot([], []);
        $report = $this->report();
        unset($report->generated_on);

        self::assertSame('Auto-refresh from the upstream sources.', $bot->mrBody($report));
    }
}
