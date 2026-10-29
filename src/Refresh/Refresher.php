<?php

declare(strict_types=1);

namespace Waynestate\Creed\Refresh;

use stdClass;

/**
 * The refresh engine: compares the policy against the parsed feeds,
 * classifies every difference, applies the safe ones in place, and holds the
 * risky ones for confirmation.
 *
 * Pure logic: no network, no filesystem, no clock. The command layer passes
 * in parsed feeds, a decoded policy tree, the provenance ledger, and today's
 * date.
 */
final class Refresher
{
    /**
     * Canonical key order when a version line is rewritten. Unknown keys are
     * kept, after these.
     *
     * @var list<string>
     */
    private const ENTRY_KEY_ORDER = [
        'released',
        'active_until',
        'security_until',
        'latest_patch',
        'latest_patch_checked',
        'current',
        'images',
    ];

    private readonly ChangeClassifier $classifier;

    public function __construct(private readonly string $today)
    {
        $this->classifier = new ChangeClassifier($today);
    }

    /**
     * Compare the policy against the feeds and apply the approved changes in
     * place. Held changes may be released first when a second source or the
     * ledger agrees with them.
     *
     * @param object $policy decoded policy tree (mutated in place)
     */
    public function refresh(object $policy, Sources $sources, SourceLedger $ledger): RefreshResult
    {
        $result = new RefreshResult();
        $components = JsonFields::child($policy, 'components');
        if ($components === null) {
            return $result;
        }

        $this->planPhp($components, $sources, $ledger, $result);
        $this->planLaravel($components, $sources, $ledger, $result);
        $this->planNode($components, $sources, $ledger, $result);
        $this->planMysql($components, $sources, $ledger, $result);
        $this->planVue($components, $sources, $ledger, $result);
        $this->planInertia($components, $sources, $ledger, $result);
        $this->checkComposer($policy, $sources, $result);

        $this->resolveHeld($sources, $ledger, $result);
        $this->apply($policy, $result);

        return $result;
    }

    // -----------------------------------------------------------------------
    // Per-component plans
    // -----------------------------------------------------------------------

    private function planPhp(object $components, Sources $sources, SourceLedger $ledger, RefreshResult $result): void
    {
        $versions = self::versionsOf($components, 'php');
        if ($versions === null) {
            return;
        }
        $listed = get_object_vars($versions);

        $datesByLine = [];
        if ($sources->phpStates !== null) {
            foreach ($sources->phpStates as $branch => $facts) {
                if (array_key_exists($branch, $listed)) {
                    $datesByLine[$branch] = [
                        'active_until' => $facts['active_until'],
                        'security_until' => $facts['security_until'],
                    ];
                }
            }
        }

        $secondaryDates = null;
        if ($sources->endoflifePhp !== null) {
            $index = EndOfLifeParser::byName($sources->endoflifePhp);
            $secondaryDates = [];
            foreach (array_keys($listed) as $branch) {
                $release = $index[(string) $branch] ?? null;
                $secondaryDates[(string) $branch] = [
                    'active_until' => $release?->eoasFrom,
                    'security_until' => $release?->eolFrom,
                    'latest_patch' => $release?->latest,
                ];
            }
        }

        foreach (array_keys($listed) as $branch) {
            $branch = (string) $branch;
            $dates = $datesByLine[$branch] ?? null;
            $facts = [
                'active_until' => $dates['active_until'] ?? null,
                'security_until' => $dates['security_until'] ?? null,
                'latest_patch' => $sources->phpActive[$branch] ?? null,
            ];
            if ($facts['active_until'] === null && $facts['security_until'] === null && $facts['latest_patch'] === null) {
                continue;
            }
            $this->updateListedLine(
                'php',
                $versions,
                $branch,
                $facts,
                [
                    'active_until' => SourceNames::PHP_STATES,
                    'security_until' => SourceNames::PHP_STATES,
                    'latest_patch' => SourceNames::PHP_ACTIVE,
                ],
                $secondaryDates[$branch] ?? null,
                SourceNames::EOL_PHP,
                $ledger,
                $result,
            );
        }

        // New-branch candidates: known state, not EOL, above every listed
        // branch. Anything below the listed lines is held, never written.
        if ($sources->phpStates !== null) {
            foreach ($sources->phpStates as $branch => $facts) {
                if (array_key_exists($branch, $listed)) {
                    continue;
                }
                if ($facts['state'] === null || $facts['state'] === 'eol') {
                    continue;
                }
                if (!Versions::aboveAll($branch, $listed)) {
                    continue;
                }
                $fields = self::entryFieldsFromDates($facts['active_until'], $facts['security_until']);
                if ($fields === []) {
                    continue;
                }
                $this->recordDateObservations('php', $branch, $fields, SourceNames::PHP_STATES, $ledger);
                $this->proposeNewLine('php', $branch, $fields, $listed, SourceNames::PHP_STATES, $result);
            }
        }
    }

    private function planLaravel(object $components, Sources $sources, SourceLedger $ledger, RefreshResult $result): void
    {
        $versions = self::versionsOf($components, 'laravel');
        if ($versions === null) {
            return;
        }
        $listed = get_object_vars($versions);

        $secondaryDates = null;
        if ($sources->endoflifeLaravel !== null) {
            $index = EndOfLifeParser::byName($sources->endoflifeLaravel);
            $secondaryDates = [];
            foreach (array_keys($listed) as $line) {
                $release = $index[(string) $line] ?? null;
                $secondaryDates[(string) $line] = [
                    'active_until' => $release?->eoasFrom,
                    'security_until' => $release?->eolFrom,
                    'latest_patch' => $release?->latest,
                ];
            }
        }

        foreach (array_keys($listed) as $line) {
            $line = (string) $line;
            $dates = $secondaryDates[$line] ?? null;
            $facts = [
                'active_until' => $dates['active_until'] ?? null,
                'security_until' => $dates['security_until'] ?? null,
                'latest_patch' => $sources->packagistLaravel[$line] ?? null,
            ];
            if ($facts['active_until'] === null && $facts['security_until'] === null && $facts['latest_patch'] === null) {
                continue;
            }
            $this->updateListedLine(
                'laravel',
                $versions,
                $line,
                $facts,
                [
                    'active_until' => SourceNames::EOL_LARAVEL,
                    'security_until' => SourceNames::EOL_LARAVEL,
                    'latest_patch' => SourceNames::PACKAGIST_LARAVEL,
                ],
                $dates,
                SourceNames::EOL_LARAVEL,
                $ledger,
                $result,
            );
        }

        // A new major: the highest release endoflife.date still calls
        // supported, when the policy does not list it yet.
        if ($sources->endoflifeLaravel !== null) {
            $candidates = [];
            foreach ($sources->endoflifeLaravel as $release) {
                if ($release->isEol || preg_match('/^\d+$/', $release->name) !== 1) {
                    continue;
                }
                $candidates[] = $release->name;
            }
            $best = Versions::highest($candidates);
            if ($best !== null && !array_key_exists($best, $listed) && Versions::aboveAll($best, $listed)) {
                $this->proposeNewLine('laravel', $best, ['current' => true], $listed, SourceNames::EOL_LARAVEL, $result);
            }
        }
    }

    private function planNode(object $components, Sources $sources, SourceLedger $ledger, RefreshResult $result): void
    {
        $versions = self::versionsOf($components, 'node');
        if ($versions === null) {
            return;
        }
        $listed = get_object_vars($versions);

        $secondaryDates = null;
        if ($sources->endoflifeNodejs !== null) {
            $index = EndOfLifeParser::byName($sources->endoflifeNodejs);
            $secondaryDates = [];
            foreach (array_keys($listed) as $line) {
                $release = $index[(string) $line] ?? null;
                $secondaryDates[(string) $line] = [
                    'active_until' => $release?->eoasFrom,
                    'security_until' => $release?->eolFrom,
                    'latest_patch' => $release?->latest,
                ];
            }
        }

        foreach (array_keys($listed) as $line) {
            $line = (string) $line;
            $schedule = $sources->nodeSchedule[$line] ?? null;
            $dates = $secondaryDates[$line] ?? null;
            $facts = [
                'active_until' => $schedule['maintenance'] ?? null,
                'security_until' => $schedule['end'] ?? null,
                'latest_patch' => $sources->nodeDist[$line] ?? null,
            ];
            if ($facts['active_until'] === null && $facts['security_until'] === null && $facts['latest_patch'] === null) {
                continue;
            }
            $this->updateListedLine(
                'node',
                $versions,
                $line,
                $facts,
                [
                    'active_until' => SourceNames::NODE_SCHEDULE,
                    'security_until' => SourceNames::NODE_SCHEDULE,
                    'latest_patch' => SourceNames::NODE_DIST,
                ],
                $dates,
                SourceNames::EOL_NODEJS,
                $ledger,
                $result,
            );
        }

        // New-major candidates: even majors only, still supported, above
        // every listed major.
        if ($sources->nodeSchedule !== null) {
            foreach ($sources->nodeSchedule as $major => $dates) {
                $major = (string) $major;
                if (preg_match('/^\d+$/', (string) $major) !== 1) {
                    continue;
                }
                if ((int) $major % 2 !== 0) {
                    continue;
                }
                if (array_key_exists($major, $listed)) {
                    continue;
                }
                if ($dates['end'] === null || $dates['end'] <= $this->today) {
                    continue;
                }
                if (!Versions::aboveAll($major, $listed)) {
                    continue;
                }
                $fields = self::entryFieldsFromDates($dates['maintenance'], $dates['end']);
                if ($fields === []) {
                    continue;
                }
                $this->recordDateObservations('node', $major, $fields, SourceNames::NODE_SCHEDULE, $ledger);
                $this->proposeNewLine('node', $major, $fields, $listed, SourceNames::NODE_SCHEDULE, $result);
            }
        }
    }

    private function planMysql(object $components, Sources $sources, SourceLedger $ledger, RefreshResult $result): void
    {
        $versions = self::versionsOf($components, 'mysql');
        if ($versions === null || $sources->endoflifeMysql === null) {
            return;
        }
        $listed = get_object_vars($versions);

        // Lines already listed keep their dates current, whatever their kind.
        $index = EndOfLifeParser::byName($sources->endoflifeMysql);
        foreach (array_keys($listed) as $line) {
            $line = (string) $line;
            $release = $index[$line] ?? null;
            if ($release === null || ($release->eoasFrom === null && $release->eolFrom === null)) {
                continue;
            }
            $this->updateListedLine(
                'mysql',
                $versions,
                $line,
                ['active_until' => $release->eoasFrom, 'security_until' => $release->eolFrom, 'latest_patch' => null],
                ['active_until' => SourceNames::EOL_MYSQL, 'security_until' => SourceNames::EOL_MYSQL, 'latest_patch' => SourceNames::EOL_MYSQL],
                null,
                null,
                $ledger,
                $result,
            );
        }

        // New lines: LTS only (innovation releases are not supported targets),
        // above every listed line, dated when upstream already ended them,
        // current otherwise.
        foreach ($sources->endoflifeMysql as $release) {
            if ($release->isLts !== true || self::resolvesToListedLine($release->name, $listed) || !Versions::aboveAll($release->name, $listed)) {
                continue;
            }
            if ($release->isEol && $release->eolFrom !== null) {
                $fields = self::entryFieldsFromDates($release->eoasFrom, $release->eolFrom);
            } else {
                $fields = ['current' => true];
            }
            if ($fields === []) {
                continue;
            }
            $this->recordDateObservations('mysql', $release->name, $fields, SourceNames::EOL_MYSQL, $ledger);
            $this->proposeNewLine('mysql', $release->name, $fields, $listed, SourceNames::EOL_MYSQL, $result);
        }
    }

    private function planVue(object $components, Sources $sources, SourceLedger $ledger, RefreshResult $result): void
    {
        $versions = self::versionsOf($components, 'vue');
        if ($versions === null || $sources->endoflifeVue === null) {
            return;
        }
        $listed = get_object_vars($versions);
        $dotted = false;
        foreach (array_keys($listed) as $key) {
            if (str_contains((string) $key, '.')) {
                $dotted = true;
                break;
            }
        }

        // Group releases at the granularity the policy already uses: by full
        // release name when the policy lists dotted lines, by major
        // otherwise. The highest release in a group decides.
        $groups = [];
        foreach ($sources->endoflifeVue as $release) {
            $key = $dotted ? $release->name : explode('.', $release->name)[0];
            $groups[$key][] = $release;
        }
        foreach ($groups as $groupKey => $members) {
            usort($members, static fn(Release $a, Release $b): int => version_compare($a->name, $b->name));
            $groups[$groupKey] = $members;
        }

        // Proposed additions: groups that resolve to no listed line.
        foreach ($groups as $key => $members) {
            $key = (string) $key;
            if (self::resolvesToListedLine($key, $listed)) {
                continue;
            }
            if (!Versions::aboveAll($key, $listed)) {
                continue;
            }
            $highest = $members[count($members) - 1];
            if (!$highest->isEol) {
                $fields = ['current' => true];
            } elseif ($highest->eolFrom === null) {
                continue;
            } else {
                $fields = self::entryFieldsFromDates($highest->eoasFrom, $highest->eolFrom);
            }
            $this->recordDateObservations('vue', $key, $fields, SourceNames::EOL_VUE, $ledger);
            $this->proposeNewLine('vue', $key, $fields, $listed, SourceNames::EOL_VUE, $result);
        }

        // Listed lines marked "current": once upstream dates them, the open-
        // ended support becomes dated support. That is a tightening, so it is
        // written without confirmation.
        foreach (array_keys($listed) as $line) {
            $line = (string) $line;
            $vars = get_object_vars($listed[$line]);
            if (($vars['current'] ?? null) !== true) {
                continue;
            }
            $key = $dotted ? $line : explode('.', $line)[0];
            $members = $groups[$key] ?? [];
            if ($members === []) {
                continue;
            }
            $highest = $members[count($members) - 1];
            if (!$highest->isEol || $highest->eolFrom === null) {
                continue;
            }
            $fields = self::entryFieldsFromDates($highest->eoasFrom, $highest->eolFrom);
            $this->recordDateObservations('vue', $line, $fields, SourceNames::EOL_VUE, $ledger);
            $result->addChange(new ChangeItem(
                'vue',
                $line,
                'entry',
                'current',
                self::describeEntry($fields),
                $this->classifier->currentToDated(),
                SourceNames::EOL_VUE,
                '',
                $fields,
            ));
        }
    }

    private function planInertia(object $components, Sources $sources, SourceLedger $ledger, RefreshResult $result): void
    {
        $versions = self::versionsOf($components, 'inertia');
        if ($versions === null || $sources->npmInertia === null) {
            return;
        }
        $listed = get_object_vars($versions);
        $latest = $sources->npmInertia['latest'] ?? null;
        if (!is_string($latest) || $latest === '') {
            return;
        }
        $major = Versions::major($latest);
        if (self::resolvesToListedLine($major, $listed)) {
            return;
        }
        $this->proposeNewLine('inertia', $major, ['current' => true], $listed, SourceNames::NPM_INERTIA, $result);
    }

    /**
     * tools.composer.minimum must point at a composer line upstream still
     * supports. When it does not, the tool refuses to touch it and says why.
     */
    private function checkComposer(object $policy, Sources $sources, RefreshResult $result): void
    {
        if ($sources->endoflifeComposer === null) {
            return;
        }
        $tools = JsonFields::child($policy, 'tools');
        $composer = $tools === null ? null : JsonFields::child($tools, 'composer');
        $minimum = $composer === null ? null : JsonFields::str($composer, 'minimum');
        if ($minimum === null || preg_match('/^(\d+)\.(\d+)\.\d+$/', $minimum, $matches) !== 1) {
            return;
        }
        $line = $matches[1] . '.' . $matches[2];
        $release = EndOfLifeParser::byName($sources->endoflifeComposer)[$line] ?? null;
        if ($release === null || !$release->isEol) {
            return;
        }
        $when = $release->eolFrom !== null ? ' on ' . $release->eolFrom : '';
        $result->addRefused(new ChangeItem(
            'composer',
            $line,
            'minimum',
            $minimum,
            null,
            ChangeClassifier::KIND_REFUSED,
            SourceNames::EOL_COMPOSER,
            "composer {$line} reached end of life{$when}; raise tools.composer.minimum",
        ));
    }

    // -----------------------------------------------------------------------
    // Shared machinery
    // -----------------------------------------------------------------------

    /**
     * Update the dates and latest patch of one listed line, record what the
     * sources said, and report disagreements with the secondary source.
     *
     * @param array{active_until: ?string, security_until: ?string, latest_patch: ?string} $facts
     * @param array{active_until: string, security_until: string, latest_patch: string} $primaryByField
     * @param array{active_until: ?string, security_until: ?string, latest_patch: ?string}|null $secondary
     */
    private function updateListedLine(
        string $component,
        object $versions,
        string $line,
        array $facts,
        array $primaryByField,
        ?array $secondary,
        ?string $secondarySource,
        SourceLedger $ledger,
        RefreshResult $result,
    ): void {
        $entry = JsonFields::child($versions, $line);
        if ($entry === null) {
            return;
        }
        $before = LineContext::fromEntry($entry);

        foreach (['active_until', 'security_until'] as $field) {
            $old = $field === 'active_until' ? $before->activeUntil : $before->securityUntil;
            $new = $facts[$field];
            if ($new === null) {
                // Absence is never evidence: a date the feed lacks stays as it is.
                continue;
            }
            $ledger->record($component, $line, $field, $primaryByField[$field], $new, $this->today);
            $after = new LineContext(
                $before->current,
                $field === 'active_until' ? $new : $before->activeUntil,
                $field === 'security_until' ? $new : $before->securityUntil,
            );
            $kind = $this->classifier->dateChange($before, $after, $old, $new, $field);
            if ($kind === '') {
                continue;
            }
            $reason = match ($kind) {
                ChangeClassifier::KIND_LOOSEN_FAIL => 'upstream extended support past the policy; confirm before writing',
                ChangeClassifier::KIND_REFUSED => 'upstream no longer reports this date; review before removing',
                default => '',
            };
            $this->route(new ChangeItem($component, $line, $field, $old, $new, $kind, $primaryByField[$field], $reason), $result);
        }

        $oldPatch = JsonFields::str($entry, 'latest_patch');
        $newPatch = $facts['latest_patch'];
        $kind = $newPatch === null ? '' : $this->classifier->patchChange($oldPatch, $newPatch);
        if ($newPatch !== null) {
            $ledger->record($component, $line, 'latest_patch', $primaryByField['latest_patch'], $newPatch, $this->today);
        }
        if ($kind !== '') {
            $this->route(new ChangeItem($component, $line, 'latest_patch', $oldPatch, $newPatch, $kind, $primaryByField['latest_patch']), $result);
        }

        if ($secondary !== null && $secondarySource !== null) {
            foreach (['active_until', 'security_until', 'latest_patch'] as $field) {
                $primaryValue = $facts[$field];
                $secondaryValue = $secondary[$field] ?? null;
                if ($primaryValue !== null && $secondaryValue !== null && $primaryValue !== $secondaryValue) {
                    $result->addDisagreement(new Disagreement(
                        $component,
                        $line,
                        $field,
                        $primaryByField[$field],
                        $primaryValue,
                        $secondarySource,
                        $secondaryValue,
                    ));
                }
            }
        }
    }

    /**
     * Route a classified change to the right bucket.
     */
    private function route(ChangeItem $item, RefreshResult $result): void
    {
        if ($item->kind === ChangeClassifier::KIND_REFUSED) {
            $result->addRefused($item);
            return;
        }
        if ($item->kind === ChangeClassifier::KIND_LOOSEN_FAIL) {
            $result->addHeld($item);
            return;
        }
        $result->addChange($item);
    }

    /**
     * Propose a whole new version line.
     *
     * @param array<string, mixed> $fields
     * @param array<int|string, mixed> $listed
     */
    private function proposeNewLine(string $component, string $line, array $fields, array $listed, string $source, RefreshResult $result): void
    {
        $kind = $this->classifier->newLine($line, $listed);
        $reason = $kind === ChangeClassifier::KIND_LOOSEN_FAIL
            ? 'new line sorts below listed lines; confirm before adding'
            : '';
        $this->route(new ChangeItem($component, $line, 'entry', null, self::describeEntry($fields), $kind, $source, $reason, $fields), $result);
    }

    /**
     * Record today's observation for each date a proposed entry carries.
     *
     * @param array<string, mixed> $fields
     */
    private function recordDateObservations(string $component, string $line, array $fields, string $source, SourceLedger $ledger): void
    {
        foreach (['active_until', 'security_until'] as $field) {
            $value = $fields[$field] ?? null;
            if (is_string($value)) {
                $ledger->record($component, $line, $field, $source, $value, $this->today);
            }
        }
    }

    /**
     * A held loosening is released when a second source agrees on the exact
     * value, or when the ledger shows the primary source saying the same
     * thing for MIN_STABLE_DAYS including today. Everything else stays held.
     */
    private function resolveHeld(Sources $sources, SourceLedger $ledger, RefreshResult $result): void
    {
        $remaining = [];
        foreach ($result->held as $item) {
            if ($item->kind === ChangeClassifier::KIND_LOOSEN_FAIL
                && in_array($item->field, ['active_until', 'security_until'], true)
                && $item->newValue !== null
            ) {
                $second = $this->crossSourceName($sources, $item);
                if ($second !== null) {
                    $result->addChange($item->agreedBy($second));
                    continue;
                }
                if ($this->ledgerIsStable($ledger, $item)) {
                    $result->addChange($item->agreedBy('policy/sources.json: unchanged for ' . SourceLedger::MIN_STABLE_DAYS . ' days'));
                    continue;
                }
            }
            $remaining[] = $item;
        }
        $result->held = $remaining;
    }

    /**
     * The name of the independent second source that reports the same new
     * value, or null when none does.
     */
    private function crossSourceName(Sources $sources, ChangeItem $item): ?string
    {
        $expected = $item->newValue;
        if ($expected === null) {
            return null;
        }
        if ($item->component === 'php' && $sources->endoflifePhp !== null) {
            $release = EndOfLifeParser::byName($sources->endoflifePhp)[$item->line] ?? null;
            $value = $release === null ? null : ($item->field === 'active_until' ? $release->eoasFrom : $release->eolFrom);
            return $value === $expected ? SourceNames::EOL_PHP : null;
        }
        if ($item->component === 'node' && $sources->endoflifeNodejs !== null) {
            $release = EndOfLifeParser::byName($sources->endoflifeNodejs)[$item->line] ?? null;
            $value = $release === null ? null : ($item->field === 'active_until' ? $release->eoasFrom : $release->eolFrom);
            return $value === $expected ? SourceNames::EOL_NODEJS : null;
        }

        return null;
    }

    private function ledgerIsStable(SourceLedger $ledger, ChangeItem $item): bool
    {
        if ($item->newValue === null) {
            return false;
        }

        return $ledger->stableFor($item->component, $item->line, $item->field, $item->source, $item->newValue, $this->today);
    }

    /**
     * Apply the approved changes to the policy tree, in place.
     */
    private function apply(object $policy, RefreshResult $result): void
    {
        if ($result->changes === []) {
            return;
        }
        $components = JsonFields::child($policy, 'components');
        if ($components === null) {
            return;
        }
        foreach ($result->changes as $item) {
            $versions = self::versionsOf($components, $item->component);
            if ($versions === null) {
                continue;
            }
            if ($item->field === 'entry') {
                $versions->{$item->line} = self::orderedEntry((array) ($item->entry ?? []));
                continue;
            }
            $entry = JsonFields::child($versions, $item->line);
            if ($entry === null || $item->newValue === null) {
                continue;
            }
            $vars = get_object_vars($entry);
            if ($item->field === 'latest_patch') {
                $vars['latest_patch'] = $item->newValue;
                $vars['latest_patch_checked'] = $this->today;
            } else {
                $vars[$item->field] = $item->newValue;
            }
            $versions->{$item->line} = self::orderedEntry($vars);
        }
    }

    /**
     * Rebuild the policy root with refreshed_on set, right after
     * schema_version. Returns a new object; the input is left alone.
     */
    public function withRefreshedOn(object $policy): stdClass
    {
        $root = new stdClass();
        $inserted = false;
        foreach (get_object_vars($policy) as $key => $value) {
            if ($key === 'refreshed_on') {
                continue;
            }
            $root->{$key} = $value;
            if ($key === 'schema_version' && !$inserted) {
                $root->refreshed_on = $this->today;
                $inserted = true;
            }
        }
        if (!$inserted) {
            $root->refreshed_on = $this->today;
        }

        return $root;
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

    /**
     * Whether a feed line already resolves to a listed policy line: an exact
     * key, or a dotted name under a listed major ("8.0" under "8").
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
     * The dated fields of a version entry, in canonical order.
     *
     * @return array<string, mixed>
     */
    private static function entryFieldsFromDates(?string $activeUntil, ?string $securityUntil): array
    {
        $fields = [];
        if ($activeUntil !== null) {
            $fields['active_until'] = $activeUntil;
        }
        if ($securityUntil !== null) {
            $fields['security_until'] = $securityUntil;
        }

        return $fields;
    }

    /**
     * Describe a proposed entry for output: "current", "security until
     * 2027-09-03", or "active until ..., security until ...".
     *
     * @param array<string, mixed> $fields
     */
    public static function describeEntry(array $fields): string
    {
        if (($fields['current'] ?? null) === true) {
            return 'current';
        }
        $parts = [];
        if (isset($fields['active_until'])) {
            $parts[] = 'active until ' . $fields['active_until'];
        }
        if (isset($fields['security_until'])) {
            $parts[] = 'security until ' . $fields['security_until'];
        }

        return implode(', ', $parts);
    }

    /**
     * Rebuild a version entry with canonical key order, keeping unknown keys
     * after the known ones.
     *
     * @param array<string, mixed> $fields
     */
    private static function orderedEntry(array $fields): stdClass
    {
        $entry = new stdClass();
        foreach (self::ENTRY_KEY_ORDER as $key) {
            if (array_key_exists($key, $fields)) {
                $entry->{$key} = $fields[$key];
            }
        }
        foreach ($fields as $key => $value) {
            if (!in_array($key, self::ENTRY_KEY_ORDER, true)) {
                $entry->{$key} = $value;
            }
        }

        return $entry;
    }
}
