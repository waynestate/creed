<?php

declare(strict_types=1);

/**
 * Asks a live MySQL 5.6 whether the databases chapter tells the truth about it.
 *
 * Runs every probe in tools/Mysql56/Probes.php against the server named by
 * DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD (the variables
 * Preflight's mysql56-migrate job sets), with the session Laravel 13 opens, and
 * prints one line per probe: [OK] when the server answered as the chapter says,
 * [FAIL] and the difference when it did not.
 *
 * Usage: php bin/mysql56-probe.php [--wait=SECONDS]
 * --wait retries the connection for that long (default 0), for a server that
 * is still starting. Needs pdo_mysql; no Composer install.
 *
 * Exits 0 when every probe agreed, 1 when one did not, 2 when it could not
 * connect or pdo_mysql is missing.
 */

require_once dirname(__DIR__) . '/tools/Mysql56/Probe.php';
require_once dirname(__DIR__) . '/tools/Mysql56/Probes.php';
require_once dirname(__DIR__) . '/tools/Mysql56/Runner.php';

use Waynestate\Creed\Tools\Mysql56\Probes;
use Waynestate\Creed\Tools\Mysql56\Runner;

if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "mysql56-probe: this PHP has no pdo_mysql; install it (docker-php-ext-install pdo_mysql) or use an image that has it.\n");
    exit(2);
}

$wait = 0;
$arguments = $_SERVER['argv'] ?? [];
foreach (array_slice(is_array($arguments) ? $arguments : [], 1) as $arg) {
    $arg = is_string($arg) ? $arg : '';
    if (preg_match('/^--wait=(\d+)$/', $arg, $m) === 1) {
        $wait = (int) $m[1];
    } else {
        fwrite(STDERR, "mysql56-probe: unknown argument {$arg}. Usage: php bin/mysql56-probe.php [--wait=SECONDS]\n");
        exit(2);
    }
}

$env = static fn(string $name, string $default): string => is_string($v = getenv($name)) && $v !== '' ? $v : $default;
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s', $env('DB_HOST', '127.0.0.1'), $env('DB_PORT', '3306'), $env('DB_DATABASE', 'app'));
$user = $env('DB_USERNAME', 'app');
$password = getenv('DB_PASSWORD');
$password = is_string($password) ? $password : '';

$deadline = time() + $wait;
while (true) {
    try {
        $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        break;
    } catch (PDOException $e) {
        if (time() >= $deadline) {
            fwrite(STDERR, 'mysql56-probe: cannot connect: ' . $e->getMessage() . "\n");
            exit(2);
        }
        sleep(2);
    }
}

$runner = new Runner($pdo);
$version = $runner->serverVersion();
echo "MySQL {$version}, session as Laravel 13 opens it (utf8mb4_unicode_ci, strict sql_mode)\n";
if (str_starts_with($version, '8.4.')) {
    echo "Judged against each probe's 8.4 answer (the chapter's \"Moving to 8.4\" section).\n";
} elseif (!str_starts_with($version, '5.6.')) {
    echo "Note: this server is neither 5.6 nor 8.4, so it is judged as 5.6 and a [FAIL] below may only mean it is newer.\n";
}

$failed = 0;
$results = $runner->run(Probes::all());
foreach ($results as $result) {
    $probe = $result['probe'];
    if ($result['failure'] === null) {
        echo "[OK] {$probe->id}: {$probe->claim}\n";
    } else {
        $failed++;
        echo "[FAIL] {$probe->id}: {$probe->claim} ({$result['failure']})\n";
    }
}

$count = count($results);
echo $failed === 0
    ? "All {$count} probes agree with handbook/databases.md.\n"
    : "{$failed} of {$count} probes disagree with handbook/databases.md: fix the chapter or the probe.\n";

exit($failed === 0 ? 0 : 1);
