<?php

declare(strict_types=1);

/**
 * Validates policy/policy.json against policy/policy.schema.json, and
 * validates policy/sources.json (the provenance ledger written by
 * bin/refresh-policy.php) against policy/sources.schema.json when present.
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

$ok = validateFile('policy/policy.json', $root . '/policy/policy.schema.json', $root . '/policy/policy.json');

$sourcesPath = $root . '/policy/sources.json';
if (is_file($sourcesPath)) {
    $ok = validateFile('policy/sources.json', $root . '/policy/sources.schema.json', $sourcesPath) && $ok;
} else {
    echo "policy/sources.json not present, skipping.\n";
}

exit($ok ? 0 : 1);
