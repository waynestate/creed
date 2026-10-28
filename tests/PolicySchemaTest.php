<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PolicySchemaTest extends TestCase
{
    /**
     * Validate a policy file against the bundled schema.
     *
     * @return list<string> the validation errors, empty when valid
     */
    private static function validateFile(string $path): array
    {
        // Decode as objects, not assoc arrays: opis reports a PHP assoc array
        // as having no JSON type, so "type": "object" would never match.
        $schema = json_decode((string) file_get_contents(dirname(__DIR__) . '/policy/policy.schema.json'), false);
        $data = json_decode((string) file_get_contents($path), false);

        $validator = new Validator();
        $result = $validator->validate($data, $schema);

        // isValid() is error() === null, so a null error means the file is valid.
        $error = $result->error();
        if ($error === null) {
            return [];
        }

        $where = $error->data()->fullPath();
        $where = $where === [] ? '(root)' : implode('.', $where);
        return [$error->message() . ' at ' . $where];
    }

    #[Test]
    public function bundledPolicyIsValid(): void
    {
        $errors = self::validateFile(dirname(__DIR__) . '/policy/policy.json');
        self::assertSame([], $errors, 'The bundled policy must validate against the schema.');
    }

    #[Test]
    public function imagesObjectIsValid(): void
    {
        $errors = self::validateFile(__DIR__ . '/fixtures/with-images.json');
        self::assertSame([], $errors, 'A version entry with an images object must validate.');
    }

    #[Test]
    public function latestPatchFieldsAreValid(): void
    {
        $errors = self::validateFile(__DIR__ . '/fixtures/with-latest-patch.json');
        self::assertSame([], $errors, 'A version entry with latest_patch fields must validate.');
    }

    #[Test]
    public function toolsObjectIsValid(): void
    {
        $errors = self::validateFile(__DIR__ . '/fixtures/with-tools.json');
        self::assertSame([], $errors, 'A policy with a tools object must validate.');
    }

    #[Test]
    public function currentOnlyLineIsValid(): void
    {
        $errors = self::validateFile(__DIR__ . '/fixtures/current-only.json');
        self::assertSame([], $errors, 'A version entry marked current must validate.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function brokenFixtures(): array
    {
        return [
            'missing rules block' => [__DIR__ . '/fixtures/missing-rules.json'],
            'unknown top-level key' => [__DIR__ . '/fixtures/unknown-key.json'],
            'bad date' => [__DIR__ . '/fixtures/bad-date.json'],
            'eol_grace_days as a string' => [__DIR__ . '/fixtures/grace-as-string.json'],
            'unknown key inside images' => [__DIR__ . '/fixtures/images-unknown-key.json'],
            'empty string inside images' => [__DIR__ . '/fixtures/images-empty-value.json'],
            'tool minimum not a full version' => [__DIR__ . '/fixtures/tools-bad-minimum.json'],
            'unknown key inside a tool' => [__DIR__ . '/fixtures/tools-unknown-key.json'],
            'current line with security_until' => [__DIR__ . '/fixtures/current-with-security-until.json'],
            'neither current nor security_until' => [__DIR__ . '/fixtures/no-security-until.json'],
        ];
    }

    #[Test]
    #[DataProvider('brokenFixtures')]
    public function brokenPolicyIsRejected(string $path): void
    {
        $errors = self::validateFile($path);
        self::assertNotSame([], $errors, "Expected validation errors for {$path}");
    }
}
