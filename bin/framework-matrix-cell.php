<?php

declare(strict_types=1);

/**
 * One cell of the framework x PHP matrix, run inside that PHP line's container:
 * creates the framework major's official skeleton with Composer (never with
 * --ignore-platform-reqs: a refusal is a result), runs the skeleton's own test
 * suite, serves it with PHP's built-in server and GETs /, and writes what
 * happened as JSON.
 *
 * Usage (scripts/framework-matrix.ps1 and .sh run it for you):
 *   php bin/framework-matrix-cell.php --framework=<id> --php=<line> --out=<file> [--logs=<dir>]
 *
 * The image (tools/FrameworkMatrix/Dockerfile) shows every deprecation,
 * warning and notice on stderr. CREED_MATRIX_IMAGE names the pinned base image
 * and CREED_MATRIX_PRERELEASE=1 marks a prerelease PHP, for the record.
 *
 * Runs on PHP 8.0 and later, so it keeps to PHP 8.0 syntax.
 * Exit 0 when the cell was recorded (whatever its result), 2 on a usage error.
 */

require_once dirname(__DIR__) . '/tools/FrameworkMatrix/Diagnostics.php';

use Waynestate\Creed\Tools\FrameworkMatrix\Diagnostics;

const APP = '/tmp/app';
const PORT = 8089;

$options = [];
foreach (array_slice(is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [], 1) as $arg) {
    if (is_string($arg) && preg_match('/^--(framework|php|out|logs)=(.+)$/', $arg, $m) === 1) {
        $options[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, "framework-matrix-cell: unknown argument. Usage: php bin/framework-matrix-cell.php --framework=<id> --php=<line> --out=<file> [--logs=<dir>]\n");
        exit(2);
    }
}
if (!isset($options['framework'], $options['php'], $options['out'])) {
    fwrite(STDERR, "framework-matrix-cell: --framework, --php and --out are required.\n");
    exit(2);
}

$config = json_decode((string) file_get_contents(dirname(__DIR__) . '/tools/FrameworkMatrix/frameworks.json'), true);
$framework = null;
foreach (is_array($config) && is_array($config['frameworks'] ?? null) ? $config['frameworks'] : [] as $candidate) {
    if (is_array($candidate) && ($candidate['id'] ?? null) === $options['framework']) {
        $framework = $candidate;
    }
}
if (!is_array($framework)) {
    fwrite(STDERR, "framework-matrix-cell: no framework {$options['framework']} in tools/FrameworkMatrix/frameworks.json.\n");
    exit(2);
}
$logs = $options['logs'] ?? sys_get_temp_dir();
if (!is_dir($logs)) {
    mkdir($logs, 0777, true);
}
$logBase = rtrim($logs, '/') . '/' . $options['framework'] . '--' . $options['php'];

/**
 * Runs a command (an argument array, no shell), output and errors into one log.
 *
 * @param list<string> $command
 * @param array<string, string> $env
 *
 * @return array{exit: int, output: string, seconds: float, timed_out: bool}
 */
function run_step(array $command, string $cwd, string $log, int $timeout, array $env = []): array
{
    $start = microtime(true);
    file_put_contents($log, '$ ' . implode(' ', $command) . "\n");
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $cwd, array_merge(getenv(), $env));
    if (!is_resource($process)) {
        return ['exit' => 127, 'output' => 'could not start ' . $command[0], 'seconds' => 0.0, 'timed_out' => false];
    }
    $timedOut = false;
    $exit = -1;
    while (true) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exit = $status['exitcode'];
            break;
        }
        if (microtime(true) - $start > $timeout) {
            $timedOut = true;
            proc_terminate($process, 9);
            break;
        }
        usleep(200000);
    }
    $closed = proc_close($process);
    if ($exit === -1) {
        $exit = $timedOut ? 124 : $closed;
    }

    return ['exit' => $exit, 'output' => (string) file_get_contents($log), 'seconds' => round(microtime(true) - $start, 1), 'timed_out' => $timedOut];
}

/**
 * GET / from the built-in server: status, headers and body, over a plain socket.
 *
 * @return array{status: int, body: string, error: ?string}
 */
function http_get(int $port): array
{
    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 5);
    if ($socket === false) {
        return ['status' => 0, 'body' => '', 'error' => "connect failed: {$errstr}"];
    }
    stream_set_timeout($socket, 60);
    fwrite($socket, "GET / HTTP/1.0\r\nHost: localhost\r\nAccept: text/html\r\nConnection: close\r\n\r\n");
    $raw = (string) stream_get_contents($socket);
    fclose($socket);
    $parts = explode("\r\n\r\n", $raw, 2);
    $status = preg_match('#^HTTP/\d\.\d (\d{3})#', $parts[0], $m) === 1 ? (int) $m[1] : 0;

    return ['status' => $status, 'body' => $parts[1] ?? '', 'error' => $status === 0 ? 'no HTTP status line' : null];
}

/**
 * @param list<array{kind: string, message: string, file: string, line: int, source: string}> $diagnostics
 *
 * @return array{deprecations: int, warnings: int, fatals: int, tool: int}
 */
function tally(array $diagnostics): array
{
    $t = ['deprecations' => 0, 'warnings' => 0, 'fatals' => 0, 'tool' => 0];
    foreach ($diagnostics as $d) {
        if ($d['source'] === 'tool') {
            $t['tool']++;
        } elseif ($d['kind'] === 'deprecation') {
            $t['deprecations']++;
        } elseif ($d['kind'] === 'fatal') {
            $t['fatals']++;
        } else {
            $t['warnings']++;
        }
    }

    return $t;
}

function plural(int $n, string $word): string
{
    return $n . ' ' . $word . ($n === 1 ? '' : 's');
}

$started = microtime(true);
$env = [
    'COMPOSER_CACHE_DIR' => getenv('COMPOSER_CACHE_DIR') ?: '/tmp/composer-cache',
    'COMPOSER_NO_INTERACTION' => '1',
    'COMPOSER_NO_AUDIT' => '1',
    // Laravel routes deprecations to a log channel, "null" by default; stderr puts them where this script reads.
    'LOG_DEPRECATIONS_CHANNEL' => 'stderr',
];
$image = getenv('CREED_MATRIX_IMAGE');
$cell = [
    'framework' => $framework['id'],
    'php_line' => $options['php'],
    'php_version' => PHP_VERSION,
    'image' => is_string($image) && $image !== '' ? $image : null,
    'prerelease' => getenv('CREED_MATRIX_PRERELEASE') === '1',
    'date' => gmdate('Y-m-d\TH:i:s\Z'),
    'state' => null,
    'step' => null,
    'first_error' => null,
    'skeleton' => ['package' => $framework['skeleton'], 'constraint' => $framework['skeleton_constraint'], 'version' => null],
    'framework_package' => ['package' => $framework['package'], 'version' => null],
    'install' => null,
    'tests' => null,
    'request' => null,
    'diagnostics' => null,
    'messages' => [],
    'seconds' => null,
];
$all = [];

/**
 * Writes the cell's JSON and its one-line verdict.
 *
 * @param array<string, mixed> $cell
 * @param list<array{kind: string, message: string, file: string, line: int, source: string, step?: string}> $all
 */
function finish_cell(array $cell, array $all, float $started, string $out): void
{
    $cell['diagnostics'] = tally($all);
    $messages = [];
    foreach (Diagnostics::distinct($all) as $d) {
        if ($d['source'] === 'app' && count($messages) < 3) {
            $messages[] = $d;
        }
    }
    $cell['messages'] = $messages;
    if ($cell['state'] === null) {
        $appIssues = $cell['diagnostics']['deprecations'] + $cell['diagnostics']['warnings'];
        $cell['state'] = $appIssues > 0 ? 'pass-deprecations' : 'pass';
    }
    $cell['seconds'] = round(microtime(true) - $started, 1);
    file_put_contents($out, json_encode($cell, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    fwrite(STDOUT, sprintf("%s on PHP %s (%s): %s%s\n", $cell['framework'], $cell['php_line'], $cell['php_version'], $cell['state'], $cell['first_error'] !== null ? ' at ' . $cell['step'] . ': ' . $cell['first_error'] : ''));
}

// 1. The official skeleton at the major's newest release.
$install = run_step(
    ['composer', 'create-project', '--no-interaction', '--prefer-dist', '--no-progress', $framework['skeleton'], APP, $framework['skeleton_constraint']],
    '/tmp',
    $logBase . '--install.log',
    1200,
    $env,
);
$found = array_map(static fn(array $d): array => $d + ['step' => 'install'], Diagnostics::scan($install['output']));
$all = array_merge($all, $found);
$cell['install'] = ['exit' => $install['exit'], 'seconds' => $install['seconds']] + tally($found);
$cell['skeleton']['version'] = Diagnostics::skeletonVersion($install['output'], $framework['skeleton']);
$installed = APP . '/vendor/composer/installed.json';
if (is_file($installed)) {
    $data = json_decode((string) file_get_contents($installed), true);
    foreach (is_array($data) && is_array($data['packages'] ?? null) ? $data['packages'] : [] as $package) {
        if (is_array($package) && ($package['name'] ?? null) === $framework['package']) {
            $cell['framework_package']['version'] = $package['version'] ?? null;
        }
    }
}
if ($install['exit'] !== 0 || $install['timed_out']) {
    $cell['state'] = Diagnostics::isPlatformRefusal($install['output']) ? 'refused' : 'fail';
    $cell['step'] = 'install';
    $cell['first_error'] = $install['timed_out'] ? 'timed out after ' . $install['seconds'] . ' s' : Diagnostics::firstError($install['output']);
    finish_cell($cell, $all, $started, $options['out']);
    exit(0);
}

// 2. The skeleton's own test suite.
$phpunitVersion = run_step([PHP_BINARY, 'vendor/bin/phpunit', '--version'], APP, $logBase . '--phpunit-version.log', 60);
$phpunitMajor = preg_match('/PHPUnit (\d+)\./', $phpunitVersion['output'], $m) === 1 ? (int) $m[1] : 0;
$testArgs = [];
foreach (is_array($framework['test'] ?? null) ? $framework['test'] : ['vendor/bin/phpunit'] as $arg) {
    if (is_string($arg)) {
        $testArgs[] = $arg;
    }
}
$testCommand = array_merge([PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr'], $testArgs, ['--colors=never']);
if ($phpunitMajor >= 10) {
    $testCommand = array_merge($testCommand, ['--display-deprecations', '--display-warnings', '--display-notices']);
}
$tests = run_step($testCommand, APP, $logBase . '--tests.log', 900, $env);
$found = array_map(static fn(array $d): array => $d + ['step' => 'tests'], Diagnostics::scan($tests['output']));
$summary = Diagnostics::phpunitSummary($tests['output']);
// PHPUnit counted issues it did not list (an older PHPUnit, or a config that hides details): keep its count.
if ($summary !== null && ($summary['deprecations'] ?? 0) > count(array_filter($found, static fn(array $d): bool => $d['kind'] === 'deprecation'))) {
    $found[] = ['kind' => 'deprecation', 'message' => 'PHPUnit counted ' . $summary['deprecations'] . ' deprecation(s) without listing them', 'file' => '', 'line' => 0, 'source' => 'app', 'step' => 'tests'];
}
$all = array_merge($all, $found);
$cell['tests'] = ['exit' => $tests['exit'], 'seconds' => $tests['seconds'], 'phpunit' => $phpunitMajor > 0 ? trim((string) preg_replace('/^.*?(PHPUnit [\d.]+).*$/s', '$1', $phpunitVersion['output'])) : null, 'summary' => $summary] + tally($found);
$testsPassed = $summary !== null && $summary['tests'] > 0 && ($summary['failures'] ?? 0) === 0 && ($summary['errors'] ?? 0) === 0 && !$tests['timed_out'];
if (!$testsPassed) {
    $cell['state'] = 'fail';
    $cell['step'] = 'tests';
    $cell['first_error'] = $tests['timed_out'] ? 'timed out after ' . $tests['seconds'] . ' s' : ($summary === null ? Diagnostics::firstError($tests['output']) : plural($summary['tests'], 'test') . ', ' . plural($summary['failures'] ?? 0, 'failure') . ', ' . plural($summary['errors'] ?? 0, 'error') . ': ' . (Diagnostics::firstError($tests['output']) ?? 'see the log'));
}

// 3. Serve it and GET /.
$serverLog = $logBase . '--serve.log';
file_put_contents($serverLog, '');
$server = proc_open(
    [PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', '-S', '127.0.0.1:' . PORT, '-t', APP . '/' . $framework['docroot']],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
    $pipes,
    APP,
    array_merge(getenv(), $env),
);
$requestStart = microtime(true);
$response = ['status' => 0, 'body' => '', 'error' => 'the built-in server did not start'];
if (is_resource($server)) {
    for ($i = 0; $i < 50; $i++) {
        $probe = @fsockopen('127.0.0.1', PORT, $errno, $errstr, 1);
        if ($probe !== false) {
            fclose($probe);
            break;
        }
        usleep(200000);
    }
    $response = http_get(PORT);
    usleep(300000);
    proc_terminate($server);
    proc_close($server);
}
file_put_contents($logBase . '--body.html', $response['body']);
$serverOutput = (string) file_get_contents($serverLog);
$bodyFound = Diagnostics::scan($response['body']);
$found = array_map(static fn(array $d): array => $d + ['step' => 'request'], array_merge($bodyFound, Diagnostics::scan($serverOutput)));
$all = array_merge($all, $found);
$cell['request'] = [
    'status' => $response['status'],
    'seconds' => round(microtime(true) - $requestStart, 1),
    'body_bytes' => strlen($response['body']),
    'body_has_php_diagnostic' => $bodyFound !== [],
] + tally($found);
if ($cell['state'] === null && ($response['status'] !== 200 || $cell['request']['fatals'] > 0)) {
    $cell['state'] = 'fail';
    $cell['step'] = 'request';
    $title = preg_match('#<title>(.*?)</title>#is', $response['body'], $t) === 1 ? trim(html_entity_decode(strip_tags($t[1]))) : null;
    $cell['first_error'] = $response['error'] ?? ('HTTP ' . $response['status'] . ($title !== null && $title !== '' ? ': ' . $title : '') . (($e = Diagnostics::firstError($serverOutput)) !== null ? '; ' . $e : ''));
}
if ($cell['state'] === null && tally($all)['fatals'] > 0) {
    $cell['state'] = 'fail';
    $cell['step'] = 'install';
    foreach ($all as $d) {
        if ($d['kind'] === 'fatal' && $d['source'] === 'app') {
            $cell['step'] = $d['step'];
            $cell['first_error'] = $d['message'];
            break;
        }
    }
}

finish_cell($cell, $all, $started, $options['out']);
exit(0);
