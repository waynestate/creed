<?php

declare(strict_types=1);

/**
 * Validates policy/policy.json against policy/policy.schema.json.
 *
 * Exits 0 when the policy is valid, 1 when it is not.
 */

use Opis\JsonSchema\Validator;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$schemaPath = $root . '/policy/policy.schema.json';
$policyPath = $root . '/policy/policy.json';

// Decode as objects, not assoc arrays: opis reports a PHP assoc array as
// having no JSON type, so "type": "object" would never match.
$schema = json_decode((string) file_get_contents($schemaPath), false);
$data = json_decode((string) file_get_contents($policyPath), false);

$validator = new Validator();
$result = $validator->validate($data, $schema);

// isValid() is error() === null, so a null error means the policy is valid.
$error = $result->error();
if ($error === null) {
    echo "policy/policy.json is valid.\n";
    exit(0);
}

$path = $error->data()->fullPath();
$where = $path === [] ? '(root)' : implode('.', $path);
echo $error->message() . ' at ' . $where . "\n";
exit(1);
