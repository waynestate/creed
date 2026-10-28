<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\Policy;
use Waynestate\Creed\State;

final class PolicyFromFileTest extends TestCase
{
    #[Test]
    public function goodFixtureAnswersStateOf(): void
    {
        $policy = Policy::fromFile(__DIR__ . '/fixtures/good.json');
        self::assertSame(
            State::Security,
            $policy->stateOf('php', '8.2', new DateTimeImmutable('2026-12-31')),
        );
        self::assertSame(
            State::EolGraceExpired,
            $policy->stateOf('php', '8.2', new DateTimeImmutable('2027-04-01')),
        );
        self::assertSame(
            '8.3',
            $policy->oldestSupported('php', new DateTimeImmutable('2027-01-01')),
        );
    }

    #[Test]
    public function readerIgnoresImagesKey(): void
    {
        // The reader does not read images; an older reader must still read a
        // policy file that carries the key (ADR-0003).
        $policy = Policy::fromFile(__DIR__ . '/fixtures/with-images.json');
        self::assertSame(
            State::Security,
            $policy->stateOf('php', '8.3', new DateTimeImmutable('2026-12-31')),
        );
        self::assertSame(
            '8.3',
            $policy->oldestSupported('php', new DateTimeImmutable('2027-01-01')),
        );
    }

    #[Test]
    public function latestPatchReturnsTheFieldWhenPresent(): void
    {
        $policy = Policy::fromFile(__DIR__ . '/fixtures/with-latest-patch.json');
        self::assertSame('8.3.36', $policy->latestPatch('php', '8.3'));
    }

    #[Test]
    public function latestPatchIsNullWhenAbsent(): void
    {
        $policy = Policy::fromFile(__DIR__ . '/fixtures/with-latest-patch.json');
        self::assertNull($policy->latestPatch('php', '8.4'));
    }

    #[Test]
    public function latestPatchUnknownComponentThrows(): void
    {
        $policy = Policy::fromFile(__DIR__ . '/fixtures/with-latest-patch.json');
        $this->expectException(InvalidArgumentException::class);
        $policy->latestPatch('cobol', '8.3');
    }

    #[Test]
    public function latestPatchUnknownVersionThrows(): void
    {
        $policy = Policy::fromFile(__DIR__ . '/fixtures/with-latest-patch.json');
        $this->expectException(InvalidArgumentException::class);
        $policy->latestPatch('php', '7.4');
    }

    #[Test]
    public function invalidJsonThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Policy::fromFile(__DIR__ . '/fixtures/not-json.json');
    }

    #[Test]
    public function missingSchemaVersionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Policy::fromFile(__DIR__ . '/fixtures/no-schema-version.json');
    }

    #[Test]
    public function missingFileThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Policy::fromFile(__DIR__ . '/fixtures/does-not-exist.json');
    }
}
