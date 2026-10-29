<?php

declare(strict_types=1);

namespace Waynestate\Creed\Refresh;

use RuntimeException;

/**
 * The original refresh-patches flow, kept intact as a class so
 * bin/refresh-patches.php can stay a thin CLI wrapper.
 *
 * It does exactly what the old script did: bump latest_patch and
 * latest_patch_checked for the PHP, Laravel, and Node lines the feeds still
 * carry, and propose new Vue lines from endoflife.date. Same messages, same
 * warnings, same exit codes. The fuller tool is Refresher.
 */
final class LegacyPatches
{
    public function __construct(
        private readonly string $phpJson,
        private readonly string $laravelJson,
        private readonly string $nodeJson,
        private readonly ?string $endOfLifePhpJson,
        private readonly ?string $endOfLifeVueJson,
        private readonly string $policyPath,
        private readonly bool $dryRun,
        private readonly string $today,
    ) {}

    public function run(): int
    {
        try {
            $phpLatest = PhpActiveParser::parse($this->phpJson);
            $laravelLatest = PackagistP2Parser::parse($this->laravelJson);
            $nodeLatest = NodeDistParser::parse($this->nodeJson);
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return 1;
        }

        $endOfLifeLatest = null;
        if ($this->endOfLifePhpJson !== null) {
            try {
                $endOfLifeLatest = EndOfLifeParser::latestByLine(EndOfLifeParser::parseReleases($this->endOfLifePhpJson));
            } catch (RuntimeException $e) {
                fwrite(STDERR, 'warning: could not parse endoflife.date PHP cross-check: ' . $e->getMessage() . "\n");
            }
        }
        $vueReleases = null;
        if ($this->endOfLifeVueJson !== null) {
            try {
                $vueReleases = EndOfLifeParser::parseReleases($this->endOfLifeVueJson);
            } catch (RuntimeException $e) {
                fwrite(STDERR, 'warning: could not parse endoflife.date Vue feed: ' . $e->getMessage() . "\n");
            }
        }

        if (!is_file($this->policyPath)) {
            fwrite(STDERR, "Policy file not found: {$this->policyPath}\n");
            return 1;
        }
        $raw = file_get_contents($this->policyPath);
        if ($raw === false) {
            fwrite(STDERR, "Could not read policy file: {$this->policyPath}\n");
            return 1;
        }
        $data = json_decode($raw, false);
        if (!is_object($data)) {
            fwrite(STDERR, "Policy file is not valid JSON: {$this->policyPath}\n");
            return 1;
        }
        $components = JsonFields::child($data, 'components');
        if ($components === null) {
            fwrite(STDERR, "Policy has no \"components\" object\n");
            return 1;
        }

        // Cross-check PHP against endoflife.date (a warning, never a failure).
        if ($endOfLifeLatest !== null) {
            $phpVersions = self::versionsOf($components, 'php');
            if ($phpVersions !== null) {
                foreach (array_keys(get_object_vars($phpVersions)) as $branch) {
                    $branch = (string) $branch;
                    $fromPhpNet = $phpLatest[$branch] ?? null;
                    $fromEndOfLife = $endOfLifeLatest[$branch] ?? null;
                    if ($fromPhpNet !== null && $fromEndOfLife !== null && $fromPhpNet !== $fromEndOfLife) {
                        fwrite(STDERR, "warning: PHP {$branch} differs: php.net says {$fromPhpNet}, endoflife.date says {$fromEndOfLife}\n");
                    }
                }
            }
        }

        $changes = [];
        $updates = [
            'php' => $phpLatest,
            'laravel' => $laravelLatest,
            'node' => $nodeLatest,
        ];
        foreach ($updates as $component => $latestMap) {
            $versions = self::versionsOf($components, $component);
            if ($versions === null) {
                continue;
            }
            foreach (array_keys(get_object_vars($versions)) as $versionKey) {
                $versionKey = (string) $versionKey;
                $latest = $latestMap[$versionKey] ?? null;
                if ($latest === null) {
                    fwrite(STDERR, "warning: {$component} {$versionKey} not in feed, leaving as-is\n");
                    continue; // not in the feed (for example, an EOL branch) - leave it
                }
                $entry = JsonFields::child($versions, $versionKey);
                if ($entry === null) {
                    continue;
                }
                $current = JsonFields::str($entry, 'latest_patch');
                if ($current === $latest) {
                    continue; // already current
                }
                $entry->latest_patch = $latest;
                $entry->latest_patch_checked = $this->today;
                $changes[] = "{$component} {$versionKey}: " . ($current ?? '(none)') . " -> {$latest}";
            }
        }

        // Propose new Vue lines from endoflife.date. A proposal is a change
        // like any other: it is printed, and written unless --dry-run.
        if ($vueReleases !== null) {
            $vueVersions = self::versionsOf($components, 'vue');
            if ($vueVersions !== null) {
                foreach ($this->proposeAdditions($vueReleases, get_object_vars($vueVersions)) as $key => $entry) {
                    $vueVersions->{$key} = (object) $entry;
                    $summary = isset($entry['current']) ? 'current' : 'security until ' . (string) $entry['security_until'];
                    $changes[] = "vue {$key}: (none) -> {$summary}";
                }
            }
        }

        if ($changes === []) {
            echo "No changes.\n";
            return 0;
        }

        foreach ($changes as $change) {
            echo ($this->dryRun ? 'would update ' : 'updated ') . $change . "\n";
        }

        if (!$this->dryRun) {
            $encoded = StableJson::encode($data) . "\n";
            if (file_put_contents($this->policyPath, $encoded) === false) {
                fwrite(STDERR, "Could not write policy file: {$this->policyPath}\n");
                return 1;
            }
        }

        return 3;
    }

    /**
     * Propose new version lines for Vue from an endoflife.date release list.
     * Ported from the original script, unchanged in behaviour.
     *
     * @param list<Release> $releases
     * @param array<int|string, mixed> $versions
     *
     * @return array<string, array<string, mixed>>
     */
    private function proposeAdditions(array $releases, array $versions): array
    {
        $dotted = false;
        foreach (array_keys($versions) as $listed) {
            if (str_contains((string) $listed, '.')) {
                $dotted = true;
                break;
            }
        }

        /** @var array<string, list<Release>> $groups */
        $groups = [];
        foreach ($releases as $release) {
            $key = $dotted ? $release->name : explode('.', $release->name)[0];
            if (self::resolvesToListedLine($key, $versions)) {
                continue;
            }
            $groups[$key][] = $release;
        }

        /** @var array<string, array<string, mixed>> $proposals */
        $proposals = [];
        foreach ($groups as $key => $members) {
            $highest = null;
            foreach ($members as $member) {
                if ($highest === null || version_compare($member->name, $highest->name) > 0) {
                    $highest = $member;
                }
            }
            if ($highest === null) {
                continue;
            }
            if (!$highest->isEol) {
                $proposals[$key] = ['current' => true];
                continue;
            }
            if ($highest->eolFrom === null) {
                fwrite(STDERR, "warning: vue {$key} is EOL but has no eolFrom date, skipping\n");
                continue;
            }
            $entry = [];
            if ($highest->eoasFrom !== null) {
                $entry['active_until'] = $highest->eoasFrom;
            }
            $entry['security_until'] = $highest->eolFrom;
            $proposals[$key] = $entry;
        }

        return $proposals;
    }

    /**
     * Whether a release name resolves to a line the policy already lists.
     *
     * @param array<int|string, mixed> $versions
     */
    private static function resolvesToListedLine(string $name, array $versions): bool
    {
        foreach (array_keys($versions) as $listed) {
            $listed = (string) $listed;
            if ($name === $listed || str_starts_with($name, $listed . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The versions object of one component, or null when the policy does not
     * carry it.
     */
    private static function versionsOf(object $components, string $component): ?object
    {
        $componentObj = JsonFields::child($components, $component);

        return $componentObj === null ? null : JsonFields::child($componentObj, 'versions');
    }
}
