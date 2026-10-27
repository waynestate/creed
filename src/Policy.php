<?php

declare(strict_types=1);

namespace Waynestate\Creed;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reads a CREED policy file and answers what state a version is in.
 *
 * The policy is data. This reader is the only code in the stack that decides
 * a version's state, so the rules for "what state is this version in" exist
 * in exactly one place.
 */
final class Policy
{
    /** @var array<string, array<string, array<string, string>>> */
    private array $components;

    /** @var array<string, mixed> */
    private array $rules;

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(array $data)
    {
        if (!isset($data['schema_version'])) {
            throw new InvalidArgumentException('Policy file is missing "schema_version".');
        }
        $this->components = $data['components'] ?? [];
        $this->rules = $data['rules'] ?? [];
    }

    /**
     * The policy bundled with this package.
     */
    public static function bundled(): self
    {
        return self::fromFile(dirname(__DIR__) . '/policy/policy.json');
    }

    /**
     * Load a policy from any file.
     *
     * @throws InvalidArgumentException on invalid JSON or a missing schema_version
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException("Policy file not found: {$path}");
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read policy file: {$path}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Invalid JSON in policy file: ' . json_last_error_msg());
        }
        return new self($data);
    }

    /**
     * What state is this version in on this date?
     *
     * Dates only: the date part of $on, in its own timezone. The boundary is
     * inclusive. On security_until the version is still Security. From the
     * next day it is Eol, for eol_grace_days more days. The day after the
     * last grace day is EolGraceExpired.
     *
     * @throws InvalidArgumentException if the component or version is unknown,
     *                                  or the version has no security_until
     */
    public function stateOf(string $component, string $version, DateTimeImmutable $on): State
    {
        $versions = $this->components[$component]['versions'] ?? null;
        if ($versions === null) {
            throw new InvalidArgumentException("Unknown component: {$component}");
        }
        if (!array_key_exists($version, $versions)) {
            throw new InvalidArgumentException("Unknown version of {$component}: {$version}");
        }
        $entry = $versions[$version];
        if (!isset($entry['security_until'])) {
            throw new InvalidArgumentException(
                "Version {$version} of {$component} has no security_until, and this reader does not guess."
            );
        }

        $day = $on->format('Y-m-d');
        $activeUntil = $entry['active_until'] ?? null;
        $securityUntil = $entry['security_until'];

        if ($activeUntil !== null && $day <= $activeUntil) {
            return State::Active;
        }
        if ($day <= $securityUntil) {
            return State::Security;
        }

        $graceDays = $this->rules['eol_grace_days'] ?? null;
        if (!is_int($graceDays)) {
            throw new InvalidArgumentException('Policy rules are missing an integer "eol_grace_days".');
        }
        $graceEnd = (new DateTimeImmutable($securityUntil))
            ->modify('+' . $graceDays . ' days')
            ->format('Y-m-d');

        if ($day <= $graceEnd) {
            return State::Eol;
        }

        return State::EolGraceExpired;
    }

    /**
     * The lowest version of a component that is still Active or Security.
     *
     * Eol and EolGraceExpired are not supported. Null when nothing is.
     *
     * @throws InvalidArgumentException if the component is unknown
     */
    public function oldestSupported(string $component, DateTimeImmutable $on): ?string
    {
        $versions = $this->components[$component]['versions'] ?? null;
        if ($versions === null) {
            throw new InvalidArgumentException("Unknown component: {$component}");
        }
        $supported = [];
        foreach (array_keys($versions) as $version) {
            // json_decode turns keys like "10" into ints; versions are strings.
            $version = (string) $version;
            $state = $this->stateOf($component, $version, $on);
            if ($state === State::Active || $state === State::Security) {
                $supported[] = $version;
            }
        }
        if ($supported === []) {
            return null;
        }
        usort($supported, static fn (string $a, string $b): int => version_compare($a, $b));
        return $supported[0];
    }

    /**
     * The rules block, as an array.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->rules;
    }
}
