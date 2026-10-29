<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests\Refresh;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\Refresh\ChangeClassifier;
use Waynestate\Creed\Refresh\LineContext;
use Waynestate\Creed\Refresh\SourceLedger;
use Waynestate\Creed\Refresh\StableJson;

final class ChangeClassifierAndLedgerTest extends TestCase
{
    private const TODAY = '2026-10-29';

    #[Test]
    public function aDateMovingEarlierTightens(): void
    {
        $c = new ChangeClassifier(self::TODAY);
        $ctx = new LineContext(false, '2027-01-01', '2028-01-01');
        self::assertSame('tighten', $c->dateChange($ctx, $ctx, '2028-01-01', '2027-06-01', 'security_until'));
    }

    #[Test]
    public function aLaterActiveDateOnlyWarns(): void
    {
        $c = new ChangeClassifier(self::TODAY);
        $ctx = new LineContext(false, '2027-01-01', '2028-01-01');
        self::assertSame('loosen-warning', $c->dateChange($ctx, $ctx, '2027-01-01', '2027-02-01', 'active_until'));
    }

    #[Test]
    public function aLaterSecurityDateFails(): void
    {
        $c = new ChangeClassifier(self::TODAY);
        $ctx = new LineContext(false, '2027-01-01', '2028-01-01');
        self::assertSame('loosen-fail', $c->dateChange($ctx, $ctx, '2028-01-01', '2028-06-01', 'security_until'));
    }

    #[Test]
    public function makingAnEolLineSupportedAgainFails(): void
    {
        $c = new ChangeClassifier(self::TODAY);
        $before = new LineContext(false, null, '2024-01-01');
        $after = new LineContext(false, null, '2030-01-01');
        self::assertSame('loosen-fail', $c->dateChange($before, $after, null, '2030-01-01', 'security_until'));
    }

    #[Test]
    public function aCurrentLineGettingItsFirstEndDateTightens(): void
    {
        $c = new ChangeClassifier(self::TODAY);
        $ctx = new LineContext(true, null, null);
        self::assertSame('tighten', $c->dateChange($ctx, $ctx, null, '2030-01-01', 'security_until'));
    }

    #[Test]
    public function deletingADateIsRefused(): void
    {
        $c = new ChangeClassifier(self::TODAY);
        $ctx = new LineContext(false, null, '2028-01-01');
        self::assertSame('refused', $c->dateChange($ctx, $ctx, '2028-01-01', null, 'security_until'));
    }

    #[Test]
    public function patchesAreNeutralAndANewTopLineIsNeutral(): void
    {
        $c = new ChangeClassifier(self::TODAY);
        self::assertSame('neutral', $c->patchChange('1.0.0', '1.0.1'));
        self::assertSame('', $c->patchChange('1.0.1', '1.0.1'));
        self::assertSame('neutral', $c->newLine('14', ['12' => 1, '13' => 1]));
        self::assertSame('loosen-fail', $c->newLine('11', ['12' => 1, '13' => 1]));
    }

    #[Test]
    public function ledgerTrustsAValueOnlyAfterFourteenDays(): void
    {
        $ledger = SourceLedger::empty();
        $ledger->record('php', '8.3', 'security_until', 'php.net/states', '2028-12-31', '2026-10-15');
        self::assertFalse($ledger->stableFor('php', '8.3', 'security_until', 'php.net/states', '2028-12-31', '2026-10-28'));

        $ledger->record('php', '8.3', 'security_until', 'php.net/states', '2028-12-31', '2026-10-29');
        self::assertTrue($ledger->stableFor('php', '8.3', 'security_until', 'php.net/states', '2028-12-31', '2026-10-29'));
    }

    #[Test]
    public function aChangedValueRestartsTheClock(): void
    {
        $ledger = SourceLedger::empty();
        $ledger->record('php', '8.3', 'security_until', 'php.net/states', '2028-12-31', '2026-10-01');
        $ledger->record('php', '8.3', 'security_until', 'php.net/states', '2029-12-31', '2026-10-29');
        self::assertFalse($ledger->stableFor('php', '8.3', 'security_until', 'php.net/states', '2029-12-31', '2026-10-29'));
    }

    #[Test]
    public function movingLastSeenForwardIsNotNews(): void
    {
        $first = SourceLedger::empty();
        $first->record('php', '8.3', 'security_until', 'php.net/states', '2027-12-31', '2026-10-01');
        $json = StableJson::encode($first->toObject());

        $again = SourceLedger::decode($json);
        self::assertNotNull($again);
        $again->record('php', '8.3', 'security_until', 'php.net/states', '2027-12-31', '2026-10-29');
        self::assertFalse($again->hasMaterialChange());
        self::assertTrue($first->hasMaterialChange());
        self::assertNotSame($json, StableJson::encode($again->toObject()));
    }

    #[Test]
    public function aBrokenLedgerDecodesToNull(): void
    {
        self::assertNull(SourceLedger::decode('{ nope'));
        self::assertNull(SourceLedger::decode('{"schema_version": 2}'));
    }
}
