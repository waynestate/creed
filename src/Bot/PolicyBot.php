<?php

declare(strict_types=1);

namespace Waynestate\Creed\Bot;

use DateTimeImmutable;
use stdClass;

/**
 * The CREED policy robot: the decision core that keeps policy/policy.json
 * current with no human in the loop.
 *
 * One run works like this:
 *
 *   1. Run bin/refresh-policy.php as a subprocess through the CommandRunner.
 *   2. Read the run report the refresher wrote with --report.
 *   3. Decide the outcome from the refresher's exit code and the report:
 *
 *        exit 0  nothing worth committing was written: the robot checks the
 *                weekly heartbeat (below) and otherwise does nothing
 *        exit 3  changes were written: the robot commits the data allowlist
 *                and runs the gate (below)
 *        exit 4  a change was held or refused: the robot commits whatever
 *                was written (often nothing) and runs the gate the same way
 *        exit 1  a hard failure: the robot stops with exit 1
 *
 * The gate: the robot commits on the checked-out main commit (it pushes
 * only from the default branch), then runs composer ci in the same job.
 * Green: it pushes the commit straight to main with the CI job token, no
 * force and no merge request. Red: it force-pushes the commit to
 * auto/policy-refresh as a merge request for review (never auto-merged)
 * and exits 1, so the scheduled pipeline goes red and someone looks.
 *
 * The weekly heartbeat: after an exit 0, if refreshed_on in
 * policy/policy.json is 7 or more days before today, the robot runs the
 * refresher again with --touch and treats that result like a normal run.
 *
 * The push goes to a gitlab-ci-token URL built from the CI_SERVER_*
 * environment variables, with the token from CI_JOB_TOKEN. Every line the
 * robot prints, including runner errors and command echoes, passes through
 * one mask function that replaces the CI_JOB_TOKEN and CREED_BOT_TOKEN
 * values with [masked], so a runner error that echoes the URL back never
 * leaks either.
 *
 * With --dry-run the refresher writes nothing and always exits 0; the robot
 * then prints the decided outcome, the planned files, the merge request
 * title and body, and touches nothing.
 *
 * After a handled real run (a red gate and a failed push included) the
 * robot also brings its alert issues in line
 * with the report: one open GitLab issue per alert key (held, refused,
 * disagreement, upcoming end of life), labeled auto-alert. A key that is
 * still present gets a note on its existing issue; a key that is gone
 * closes its issue. The client is injected by the tests or built from the
 * CI_API_V4_URL, CI_PROJECT_ID and CREED_BOT_TOKEN environment variables
 * (the bot token is for these issues only, never the push);
 * a dry run only prints what it would open and never calls the API.
 */
final readonly class PolicyBot
{
    /**
     * The merge request title, exactly.
     */
    public const MR_TITLE = 'policy: refresh from upstream sources';

    /**
     * The only paths a policy refresh may change; a commit stages exactly
     * these, nothing else.
     *
     * @var list<string>
     */
    public const DATA_ALLOWLIST = [
        'policy/policy.json',
        'policy/sources.json',
    ];

    /**
     * The fallback branch: a refresh that fails the gate lands here,
     * pushed with merge request options for a person to review.
     */
    public const BRANCH = 'auto/policy-refresh';

    /**
     * The committer identity for the refresh commit, passed with -c flags
     * so the repository config is never touched.
     */
    public const COMMITTER_NAME = 'CREED policy bot';

    /**
     * @see self::COMMITTER_NAME
     */
    public const COMMITTER_EMAIL = 'policy-bot@noreply.invalid';

    /**
     * A refresh older than this many days triggers the --touch heartbeat.
     */
    public const HEARTBEAT_DAYS = 7;

    /**
     * The environment variables a push needs, in fixed order.
     *
     * @var list<string>
     */
    private const ENVIRONMENT = [
        'CI_SERVER_PROTOCOL',
        'CI_SERVER_HOST',
        'CI_SERVER_PORT',
        'CI_PROJECT_PATH',
        'CI_DEFAULT_BRANCH',
        'CI_COMMIT_BRANCH',
        'CI_JOB_TOKEN',
    ];

    /**
     * The environment variables the alert channel builds its client from,
     * in fixed order.
     *
     * @var list<string>
     */
    private const ALERT_ENVIRONMENT = [
        'CI_API_V4_URL',
        'CI_PROJECT_ID',
        'CREED_BOT_TOKEN',
    ];

    /**
     * @param resource|null $errorStream stream for error lines; STDERR by default
     * @param AlertIssues|null $alerts injected alert channel; built from the
     *        alert environment when null
     */
    public function __construct(
        private string $repoRoot,
        private CommandRunner $runner,
        private bool $dryRun = false,
        private ?string $today = null,
        private mixed $errorStream = null,
        private ?AlertIssues $alerts = null,
    ) {}

    /**
     * One scheduled run.
     *
     * @param string|null $offlineDir feed directory for the refresher, or null to fetch
     *
     * The alert issues sync after every handled outcome. When the commit
     * step fails after the report was read (a red gate, a failed push, a
     * missing push variable), they still sync from that report and the
     * run still returns 1. A refresher hard failure or an unknown refresher
     * exit code has no usable report and does not sync.
     *
     * @return int 0 on every handled outcome, 1 on a hard failure, a red
     *         gate (the refresh went to a merge request instead), a failed
     *         commit or push, or when the alert channel cannot be reached
     */
    public function run(?string $offlineDir = null): int
    {
        // A real run owns its alert issues, so it needs the alert
        // environment before it refreshes, commits or pushes. An injected
        // channel (tests) carries its own transport and skips this check.
        if (!$this->dryRun && $this->alerts === null) {
            $missing = $this->missingAlertEnvironment();
            if ($missing !== []) {
                $this->say('missing environment variables: ' . implode(', ', $missing) . "\n");

                return 1;
            }
        }

        $reportPath = tempnam(sys_get_temp_dir(), 'creed-policy-report-');
        if ($reportPath === false) {
            $this->say("the policy robot could not create a report file\n");

            return 1;
        }

        try {
            $first = $this->runRefresher($offlineDir, $reportPath);
            if ($first === null) {
                return 1;
            }
            [$status, $report] = $first;

            if ($this->dryRun) {
                $exit = $this->dryRunOutcome($report);
                $this->printAlertPlan($report);

                return $exit;
            }

            if ($status === 0) {
                $age = $this->daysSinceLastRefresh();
                if ($age >= self::HEARTBEAT_DAYS) {
                    $this->say("the last refresh was {$age} days old; running the refresher with --touch\n");
                    $second = $this->runRefresher($offlineDir, $reportPath, touch: true);
                    if ($second === null) {
                        return 1;
                    }
                    [$status, $report] = $second;
                }
            }

            $exit = $this->decide($status, $report);
            if ($exit !== 0) {
                // A red gate or a failed push still owes the alert issues:
                // a red week is exactly when someone needs the held and
                // refused alerts. The run stays 1 whatever the sync says.
                if ($status === 3 || $status === 4) {
                    $this->alertSync($report);
                }

                return $exit;
            }

            return $this->alertSync($report);
        } finally {
            if (is_file($reportPath)) {
                @unlink($reportPath);
            }
        }
    }

    /**
     * The merge request title, exactly.
     */
    public function mrTitle(): string
    {
        return self::MR_TITLE;
    }

    /**
     * The merge request body: every change with its class, plus the held,
     * refused, disagreement and source-error sections, all from the report.
     */
    public function mrBody(object $report): string
    {
        $date = is_string($report->generated_on ?? null) ? $report->generated_on : '';
        $lines = [$date === '' ? 'Auto-refresh from the upstream sources.' : "Auto-refresh from the upstream sources on {$date}."];

        $sections = [];
        foreach ([
            'Changes' => 'changes',
            'Held' => 'held',
            'Refused' => 'refused',
        ] as $heading => $field) {
            $section = [];
            foreach ($this->items($report, $field) as $item) {
                $line = '- ' . self::summaryLine($item) . ' [' . (string) ($item->class ?? '?') . ']';
                $reason = is_string($item->reason ?? null) && $item->reason !== '' ? ': ' . $item->reason : '';
                $section[] = $line . $reason;
            }
            if ($section !== []) {
                $sections[] = [$heading . ':', ...$section];
            }
        }

        $disagreements = [];
        foreach ($this->items($report, 'disagreements') as $item) {
            $disagreements[] = sprintf(
                '- %s %s %s: %s says %s, %s says %s',
                (string) ($item->component ?? '?'),
                (string) ($item->line ?? '?'),
                (string) ($item->field ?? '?'),
                (string) ($item->primary_source ?? '?'),
                (string) ($item->primary_value ?? '?'),
                (string) ($item->secondary_source ?? '?'),
                (string) ($item->secondary_value ?? '?'),
            );
        }
        if ($disagreements !== []) {
            $sections[] = ['Disagreements:', ...$disagreements];
        }

        $errors = [];
        foreach ($this->strings($report, 'source_errors') as $error) {
            $errors[] = "- {$error}";
        }
        if ($errors !== []) {
            $sections[] = ['Sources that failed this run:', ...$errors];
        }

        if ($sections !== []) {
            $lines[] = '';
            foreach ($sections as $section) {
                foreach ($section as $line) {
                    $lines[] = $line;
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Run the refresher once and replay its streams (masked). Returns
     * [exit, report], or null when the subprocess could not start or
     * hard-failed, both already reported.
     *
     * @return array{0: int, 1: object}|null
     */
    private function runRefresher(?string $offlineDir, string $reportPath, bool $touch = false): ?array
    {
        $argv = [PHP_BINARY, $this->repoRoot . '/bin/refresh-policy.php', '--report', $reportPath];
        if ($offlineDir !== null) {
            $argv[] = '--offline';
            $argv[] = $offlineDir;
        }
        if ($touch) {
            $argv[] = '--touch';
        }
        if ($this->dryRun) {
            $argv[] = '--dry-run';
        }

        try {
            $result = $this->runner->run($argv);
        } catch (CommandException $e) {
            $this->say('the refresher could not start: ' . $e->getMessage() . "\n");

            return null;
        }

        if ($result['stdout'] !== '') {
            $this->say($result['stdout']);
        }
        if ($result['stderr'] !== '') {
            $this->err($result['stderr']);
        }

        if ($result['status'] === 1) {
            $this->say("the refresher failed (exit 1); the robot stops here\n");

            return null;
        }

        return [$result['status'], $this->readReport($reportPath)];
    }

    /**
     * The decided outcome for a real (non dry-run) run.
     */
    private function decide(int $exit, object $report): int
    {
        return match ($exit) {
            0 => $this->nothingToDo(),
            3 => $this->commitAndPush($report, held: false),
            4 => $this->commitAndPush($report, held: true),
            default => $this->unexpectedExit($exit),
        };
    }

    private function nothingToDo(): int
    {
        $this->say("nothing to do\n");

        return 0;
    }

    private function unexpectedExit(int $exit): int
    {
        $this->say("the refresher exited with an unknown code ({$exit}); treating it as a failure\n");

        return 1;
    }

    /**
     * Bring the open auto-alert issues in line with the report after a
     * handled run: print each action, or the missing alert environment when
     * no client can be built. Returns the alert channel's exit code.
     */
    private function alertSync(object $report): int
    {
        $alerts = $this->alerts();
        if ($alerts === null) {
            $this->say('missing environment variables: ' . implode(', ', $this->missingAlertEnvironment()) . "\n");

            return 1;
        }

        [$exit, $errors, $actions] = $alerts->sync($report);
        foreach ($actions as $action) {
            $this->say("{$action}\n");
        }
        if ($actions === []) {
            $this->say("alert issues: nothing to do\n");
        }
        foreach ($errors as $error) {
            $this->err("{$error}\n");
        }

        return $exit;
    }

    /**
     * The alert channel to sync: the injected one, or one built from the
     * alert environment, or null when that environment is incomplete.
     */
    private function alerts(): ?AlertIssues
    {
        if ($this->alerts !== null) {
            return $this->alerts;
        }
        $apiUrl = getenv('CI_API_V4_URL');
        $projectId = getenv('CI_PROJECT_ID');
        $token = getenv('CREED_BOT_TOKEN');
        if ($apiUrl === false || $apiUrl === ''
            || $projectId === false || $projectId === ''
            || $token === false || $token === '') {
            return null;
        }
        $client = new GitLabClient(GitLabClient::streamTransport(), (string) $apiUrl, (string) $projectId, (string) $token);

        return new AlertIssues($client, $this->policyPath(), $this->today);
    }

    /**
     * The alert environment variable names that are missing or empty, in
     * fixed order. Names only; values never leave this method.
     *
     * @return list<string>
     */
    private function missingAlertEnvironment(): array
    {
        $missing = [];
        foreach (self::ALERT_ENVIRONMENT as $name) {
            $value = getenv($name);
            if ($value === false || $value === '') {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * What a dry run would do to the alert issues, printed without any API
     * call: one line per issue it would open.
     */
    private function printAlertPlan(object $report): void
    {
        $plan = $this->alertsForPlan()->plan($report);
        if ($plan === []) {
            $this->say("dry run: no alert issues\n");

            return;
        }
        $this->say("dry run: would open these alert issues:\n");
        foreach ($plan as $item) {
            $this->say("  {$item['title']}\n");
        }
    }

    /**
     * An alert channel good enough for a dry-run plan: the injected one, or
     * a clientless one that reads policy/policy.json but never calls the
     * API.
     */
    private function alertsForPlan(): AlertIssues
    {
        if ($this->alerts !== null) {
            return $this->alerts;
        }

        return new AlertIssues(null, $this->policyPath(), $this->today);
    }

    /**
     * The policy document the alert channel reads for the disagreement and
     * upcoming-eol keys.
     */
    private function policyPath(): string
    {
        return $this->repoRoot . '/policy/policy.json';
    }

    /**
     * Changes were written (exit 3) or partly written (exit 4, held says
     * which, for the intro line only): commit the data allowlist on the
     * checked-out main commit, run composer ci, then push straight to main
     * when it passes, or push the fallback branch as a merge request (and
     * exit 1) when it does not. A pipeline on any other branch never
     * commits or pushes.
     */
    private function commitAndPush(object $report, bool $held): int
    {
        if ($held) {
            $this->say("the refresher held or refused some changes; committing what was written\n");
        } else {
            $this->say("changes written; the commit plan:\n");
        }
        foreach ($this->plannedFiles() as $file) {
            $this->say("  {$file}\n");
        }

        $this->printRecords($report);

        $missing = $this->missingEnvironment();
        if ($missing !== []) {
            $this->say('missing environment variables: ' . implode(', ', $missing) . "\n");

            return 1;
        }

        $defaultBranch = (string) getenv('CI_DEFAULT_BRANCH');
        $commitBranch = (string) getenv('CI_COMMIT_BRANCH');
        if ($commitBranch !== $defaultBranch) {
            $this->say("the robot pushes only from {$defaultBranch}; this pipeline runs on {$commitBranch}\n");

            return 1;
        }

        $result = $this->runGit(['add', ...self::DATA_ALLOWLIST]);
        if ($result['status'] !== 0) {
            $this->say("git add failed (exit {$result['status']}); the robot stops here\n");

            return 1;
        }

        $result = $this->runGit(['diff', '--cached', '--name-only']);
        if ($result['status'] !== 0) {
            $this->say("git diff failed (exit {$result['status']}); the robot stops here\n");

            return 1;
        }
        if (trim($result['stdout']) === '') {
            $this->say("nothing staged; skipping the commit and push\n");

            return 0;
        }

        $result = $this->runGit([
            '-c',
            'user.name=' . self::COMMITTER_NAME,
            '-c',
            'user.email=' . self::COMMITTER_EMAIL,
            'commit',
            '-m',
            self::MR_TITLE,
        ]);
        if ($result['status'] !== 0) {
            $this->say("git commit failed (exit {$result['status']}); the robot stops here\n");

            return 1;
        }

        $gate = $this->runGate();
        if ($gate === 0) {
            // Green: straight to main, no force. A main that moved under us
            // makes this push fail, which is the right answer.
            $result = $this->runGit(['push', $this->pushUrl(), 'HEAD:refs/heads/' . $defaultBranch]);
            if ($result['status'] !== 0) {
                $this->say("git push failed (exit {$result['status']}); the robot stops here\n");

                return 1;
            }
            $this->say("the gate passed; pushed the refresh to {$defaultBranch}\n");

            return 0;
        }

        // Red: a person reviews it. The branch is the robot's own, so a
        // force-push only replaces last week's failed attempt.
        $this->say("the gate failed (exit {$gate}); pushing " . self::BRANCH . " as a merge request for review\n");
        $args = ['push', '--force'];
        foreach ($this->pushOptions() as $option) {
            $args[] = '-o';
            $args[] = $option;
        }
        $args[] = $this->pushUrl();
        $args[] = 'HEAD:refs/heads/' . self::BRANCH;

        $result = $this->runGit($args);
        if ($result['status'] !== 0) {
            $this->say("git push failed (exit {$result['status']}); the robot stops here\n");

            return 1;
        }

        $this->printMergeRequest($report);

        return 1;
    }

    /**
     * Run composer ci on the committed refresh, echoed and replayed through
     * the same mask as runGit. A command that cannot start counts as a
     * failed gate with status 1.
     */
    private function runGate(): int
    {
        $argv = ['composer', 'ci'];
        $this->say("running the gate: composer ci\n");
        $this->say('$ ' . implode(' ', $argv) . "\n");

        try {
            $result = $this->runner->run($argv);
        } catch (CommandException $e) {
            $this->err('composer could not start: ' . $e->getMessage() . "\n");

            return 1;
        }

        if ($result['stdout'] !== '') {
            $this->say($result['stdout']);
        }
        if ($result['stderr'] !== '') {
            $this->err($result['stderr']);
        }

        return $result['status'];
    }

    /**
     * Run one git command through the runner. The command line is echoed
     * first (masked) and its streams are replayed (masked), so a push URL
     * never reaches the terminal raw.
     *
     * @param list<string> $args the git arguments, without the leading "git"
     *
     * @return array{status: int, stdout: string, stderr: string}
     */
    private function runGit(array $args): array
    {
        $argv = ['git', ...$args];
        $this->say('$ ' . implode(' ', $argv) . "\n");

        try {
            $result = $this->runner->run($argv);
        } catch (CommandException $e) {
            $this->err('git could not start: ' . $e->getMessage() . "\n");

            return ['status' => 1, 'stdout' => '', 'stderr' => ''];
        }

        if ($result['stdout'] !== '') {
            $this->say($result['stdout']);
        }
        if ($result['stderr'] !== '') {
            $this->err($result['stderr']);
        }

        return $result;
    }

    /**
     * The job-token push URL, built from the CI environment. Only called
     * after missingEnvironment() came back empty.
     */
    private function pushUrl(): string
    {
        return sprintf(
            '%s://gitlab-ci-token:%s@%s:%s/%s.git',
            (string) getenv('CI_SERVER_PROTOCOL'),
            $this->token(),
            (string) getenv('CI_SERVER_HOST'),
            (string) getenv('CI_SERVER_PORT'),
            (string) getenv('CI_PROJECT_PATH'),
        );
    }

    /**
     * The merge request options for the fallback push after a red gate,
     * one per -o flag. Never auto-merge: a red gate needs a person.
     *
     * @return list<string>
     */
    private function pushOptions(): array
    {
        return [
            'merge_request.create',
            'merge_request.target=' . (string) getenv('CI_DEFAULT_BRANCH'),
            'merge_request.remove_source_branch',
            'merge_request.label=auto-data',
            'merge_request.title=' . self::MR_TITLE,
        ];
    }

    /**
     * The required environment variable names that are missing or empty,
     * in fixed order. Names only; values never leave this method.
     *
     * @return list<string>
     */
    private function missingEnvironment(): array
    {
        $missing = [];
        foreach (self::ENVIRONMENT as $name) {
            $value = getenv($name);
            if ($value === false || $value === '') {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * The push token from CI_JOB_TOKEN, or '' when unset. The bot token
     * (CREED_BOT_TOKEN) is for the alert issues only.
     */
    private function token(): string
    {
        $token = getenv('CI_JOB_TOKEN');

        return $token === false ? '' : (string) $token;
    }

    /**
     * The one mask every printed line passes through: the CI_JOB_TOKEN and
     * CREED_BOT_TOKEN values become [masked]. An empty one masks nothing.
     */
    private function mask(string $line): string
    {
        foreach (['CI_JOB_TOKEN', 'CREED_BOT_TOKEN'] as $name) {
            $token = getenv($name);
            if ($token !== false && $token !== '') {
                $line = str_replace((string) $token, '[masked]', $line);
            }
        }

        return $line;
    }

    /**
     * Print one line (or chunk) to stdout, masked.
     */
    private function say(string $line): void
    {
        echo $this->mask($line);
    }

    /**
     * Print one line (or chunk) to the error stream (STDERR by default),
     * masked.
     */
    private function err(string $line): void
    {
        if (is_resource($this->errorStream)) {
            fwrite($this->errorStream, $this->mask($line));

            return;
        }

        fwrite(STDERR, $this->mask($line));
    }

    /**
     * Whole days between refreshed_on in policy/policy.json and today. A
     * missing file, field or unparseable date counts as fully stale, so
     * the heartbeat stamps the file.
     */
    private function daysSinceLastRefresh(): int
    {
        $today = $this->todayDate();
        if ($today === null) {
            return self::HEARTBEAT_DAYS;
        }

        $raw = @file_get_contents($this->repoRoot . '/policy/policy.json');
        if ($raw === false) {
            return self::HEARTBEAT_DAYS;
        }
        $decoded = json_decode($raw);
        if (!is_object($decoded)) {
            return self::HEARTBEAT_DAYS;
        }
        $stamp = $decoded->refreshed_on ?? null;
        if (!is_string($stamp) || $stamp === '') {
            return self::HEARTBEAT_DAYS;
        }
        $then = DateTimeImmutable::createFromFormat('!Y-m-d', $stamp);
        if ($then === false) {
            return self::HEARTBEAT_DAYS;
        }

        return $then->diff($today)->days;
    }

    /**
     * The injected today (tests) or the real today, at midnight.
     */
    private function todayDate(): ?DateTimeImmutable
    {
        if ($this->today !== null) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $this->today);

            return $parsed === false ? null : $parsed;
        }

        return new DateTimeImmutable('today');
    }

    /**
     * What a dry run would have done. A dry-run refresher always exits 0
     * and writes nothing, so the report alone decides the outcome.
     */
    private function dryRunOutcome(object $report): int
    {
        $changes = $this->items($report, 'changes');
        $held = $this->items($report, 'held');
        $refused = $this->items($report, 'refused');
        $disagreements = $this->items($report, 'disagreements');
        $errors = $this->strings($report, 'source_errors');

        if ($changes === [] && $held === [] && $refused === [] && $disagreements === [] && $errors === []) {
            $this->say("dry run: nothing to do\n");

            return 0;
        }

        if ($changes !== []) {
            $this->say("dry run: would commit these files:\n");
            foreach ($this->plannedFiles() as $file) {
                $this->say("  {$file}\n");
            }
        } else {
            $this->say('dry run: nothing to commit (' . $this->countPhrases($held, $refused, $disagreements, $errors) . ")\n");
        }

        $this->printRecords($report);
        $this->printMergeRequest($report);

        return 0;
    }

    /**
     * The held and refused items, one line each, in the refresher's own
     * wording.
     */
    private function printRecords(object $report): void
    {
        foreach ($this->items($report, 'held') as $item) {
            $this->say(self::recordLine('held', $item) . "\n");
        }
        foreach ($this->items($report, 'refused') as $item) {
            $this->say(self::recordLine('refused', $item) . "\n");
        }
    }

    /**
     * The merge request title and body.
     */
    private function printMergeRequest(object $report): void
    {
        $this->say('merge request title: ' . $this->mrTitle() . "\n");
        $this->say("merge request body:\n");
        $this->say($this->mrBody($report) . "\n");
    }

    /**
     * The data allowlist files that exist in the repository: what a commit
     * would stage.
     *
     * @return list<string>
     */
    private function plannedFiles(): array
    {
        $files = [];
        foreach (self::DATA_ALLOWLIST as $path) {
            if (is_file($this->repoRoot . '/' . $path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * "(1 refused, 1 disagreement)" style counts for the non-zero parts.
     *
     * @param list<object> $held
     * @param list<object> $refused
     * @param list<object> $disagreements
     * @param list<string> $errors
     */
    private function countPhrases(array $held, array $refused, array $disagreements, array $errors): string
    {
        $phrases = [];
        foreach ([
            [count($held), 'held', 'held'],
            [count($refused), 'refused', 'refused'],
            [count($disagreements), 'disagreement', 'disagreements'],
            [count($errors), 'source error', 'source errors'],
        ] as [$n, $singular, $plural]) {
            if ($n > 0) {
                $phrases[] = $n === 1 ? "1 {$singular}" : "{$n} {$plural}";
            }
        }

        return implode(', ', $phrases);
    }

    /**
     * One change as "php 8.3: latest_patch 8.3.35 -> 8.3.36", the
     * refresher's own summary wording.
     */
    private static function summaryLine(object $item): string
    {
        $old = $item->old ?? null;
        $new = $item->new ?? null;

        return sprintf(
            '%s %s: %s %s -> %s',
            (string) ($item->component ?? '?'),
            (string) ($item->line ?? '?'),
            (string) ($item->field ?? '?'),
            $old !== null ? (string) $old : '(none)',
            $new !== null ? (string) $new : '(removed)',
        );
    }

    /**
     * One held or refused item as "refused composer 2.8: minimum 2.8.0 ->
     * (removed) [refused]: <reason>", the refresher's own wording.
     */
    private static function recordLine(string $verb, object $item): string
    {
        $line = "{$verb} " . self::summaryLine($item) . ' [' . (string) ($item->class ?? '?') . ']';
        $reason = is_string($item->reason ?? null) && $item->reason !== '' ? ': ' . $item->reason : '';

        return $line . $reason;
    }

    /**
     * The report items under one field, objects only.
     *
     * @return list<object>
     */
    private function items(object $report, string $field): array
    {
        $items = [];
        foreach ((array) ($report->{$field} ?? []) as $item) {
            if (is_object($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * The report strings under one field.
     *
     * @return list<string>
     */
    private function strings(object $report, string $field): array
    {
        $values = [];
        foreach ((array) ($report->{$field} ?? []) as $value) {
            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * The decoded --report document, or an empty one when the refresher
     * wrote nothing readable.
     */
    private function readReport(string $reportPath): object
    {
        $raw = @file_get_contents($reportPath);
        if ($raw !== false) {
            $decoded = json_decode($raw);
            if (is_object($decoded)) {
                return $decoded;
            }
        }

        return new stdClass();
    }
}
