<?php

declare(strict_types=1);

/**
 * Lints the CREED handbook for RFC 2119 discipline.
 *
 * A chapter that declares RFC 2119 capitals at its top must not use a
 * lowercase RFC 2119 keyword (must, must not, should, should not, may) as a
 * requirement word outside code spans and code blocks. A deliberate
 * plain-English use is excused with a `<!-- lint: allow -->` comment on the
 * same line.
 *
 * Usage:
 *   php bin/lint-handbook.php [dir]
 *
 *   dir  the handbook directory (default: handbook/)
 *
 * Exit codes:
 *   0  every file is clean
 *   1  at least one file has a lowercase RFC 2119 keyword
 *
 * One PHP command, so it runs the same on Windows, macOS and Linux.
 */

use Waynestate\Creed\HandbookLinter;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$argv = $_SERVER['argv'] ?? [];
$dir = $argv[1] ?? $root . '/handbook';

if (!is_dir($dir)) {
    fwrite(STDERR, "Handbook directory not found: {$dir}\n");
    exit(1);
}

$files = glob($dir . '/*.md') ?: [];
sort($files);

$linter = new HandbookLinter();
$failedFiles = 0;

foreach ($files as $file) {
    $content = file_get_contents($file);
    if ($content === false) {
        fwrite(STDERR, "Cannot read: {$file}\n");
        $failedFiles++;
        continue;
    }

    $violations = $linter->lint($content);
    if ($violations === []) {
        continue;
    }

    $failedFiles++;
    $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $file);
    foreach ($violations as $violation) {
        fwrite(STDERR, sprintf(
            "%s:%d: lowercase RFC 2119 keyword '%s': use capitals, or add <!-- lint: allow -->\n",
            $relative,
            $violation['line'],
            $violation['keyword'],
        ));
    }
}

if ($failedFiles > 0) {
    fwrite(STDERR, "Handbook lint failed: {$failedFiles} file(s) with lowercase RFC 2119 keywords.\n");
    exit(1);
}

echo "Handbook lint passed: " . count($files) . " file(s) checked.\n";
exit(0);
