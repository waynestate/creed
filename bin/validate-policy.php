<?php

declare(strict_types=1);

/**
 * Validates policy/policy.json against policy/policy.schema.json, then
 * checks each line's optional minimum_patch (it must sit in its own line and
 * may not sort above latest_patch), and validates policy/sources.json (the
 * provenance ledger written by bin/refresh-policy.php) against
 * policy/sources.schema.json when present.
 *
 * With --policy <path>, runs the same policy checks on that file instead of
 * the bundled files (tests use it to point at fixtures).
 *
 * Exits 0 when everything is valid, 1 when anything is not.
 */

use Opis\JsonSchema\Validator;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);

// Decode as objects, not assoc arrays: opis reports a PHP assoc array as
// having no JSON type, so "type": "object" would never match.
function validateFile(string $label, string $schemaPath, string $dataPath): bool
{
    $schema = json_decode((string) file_get_contents($schemaPath), false);
    $data = json_decode((string) file_get_contents($dataPath), false);

    $validator = new Validator();
    $result = $validator->validate($data, $schema);

    // isValid() is error() === null, so a null error means the file is valid.
    $error = $result->error();
    if ($error === null) {
        echo "{$label} is valid.\n";
        return true;
    }

    $path = $error->data()->fullPath();
    $where = $path === [] ? '(root)' : implode('.', $path);
    echo $error->message() . ' at ' . $where . "\n";
    return false;
}

/**
 * The minimum_patch problems of one decoded policy, as messages.
 *
 * A value must sit in its own line (its leading parts equal the line key, so
 * 8.4.1 for "8.4" and 12.1.0 for "12") and may not sort above the line's
 * latest_patch, because a floor above the newest patch accepts nothing. A
 * floor equal to latest_patch is allowed.
 *
 * @param object $policy
 *
 * @return list<string>
 */
function minimumPatchProblems(object $policy): array
{
    $problems = [];
    $components = $policy->components ?? null;
    if (!is_object($components)) {
        return $problems;
    }
    foreach (get_object_vars($components) as $component => $componentObj) {
        if (!is_object($componentObj)) {
            continue;
        }
        $versions = $componentObj->versions ?? null;
        if (!is_object($versions)) {
            continue;
        }
        foreach (get_object_vars($versions) as $version => $entry) {
            if (!is_object($entry)) {
                continue;
            }
            $fields = get_object_vars($entry);
            $minimum = $fields['minimum_patch'] ?? null;
            if (!is_string($minimum)) {
                continue;
            }
            $lineParts = explode('.', (string) $version);
            $head = implode('.', array_slice(explode('.', $minimum), 0, count($lineParts)));
            if ($head !== (string) $version) {
                $problems[] = "{$component} {$version}: minimum_patch {$minimum} is not in the {$version} line";
                continue;
            }
            $latest = $fields['latest_patch'] ?? null;
            if (is_string($latest) && version_compare($minimum, $latest) > 0) {
                $problems[] = "{$component} {$version}: minimum_patch {$minimum} is above latest_patch {$latest}";
            }
        }
    }
    return $problems;
}

$args = array_slice($_SERVER['argv'] ?? [], 1);
$policyPath = null;
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--policy' && $i + 1 < count($args)) {
        $policyPath = $args[$i + 1];
    }
}

/**
 * The schema check, then the minimum_patch checks, on one policy file.
 */
function validatePolicy(string $label, string $schemaPath, string $dataPath): bool
{
    if (!validateFile($label, $schemaPath, $dataPath)) {
        return false;
    }
    $data = json_decode((string) file_get_contents($dataPath), false);
    if (!is_object($data)) {
        echo "{$label} is not a JSON object.\n";
        return false;
    }
    $ok = true;
    foreach (minimumPatchProblems($data) as $problem) {
        echo $problem . "\n";
        $ok = false;
    }
    return $ok;
}

if ($policyPath !== null) {
    exit(validatePolicy($policyPath, $root . '/policy/policy.schema.json', $policyPath) ? 0 : 1);
}

$ok = validatePolicy('policy/policy.json', $root . '/policy/policy.schema.json', $root . '/policy/policy.json');

$sourcesPath = $root . '/policy/sources.json';
if (is_file($sourcesPath)) {
    $ok = validateFile('policy/sources.json', $root . '/policy/sources.schema.json', $sourcesPath) && $ok;
} else {
    echo "policy/sources.json not present, skipping.\n";
}

exit($ok ? 0 : 1);
