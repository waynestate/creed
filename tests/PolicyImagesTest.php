<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\Policy;

final class PolicyImagesTest extends TestCase
{
    /**
     * The bundled policy file, decoded as an assoc array.
     *
     * @return array<string, mixed>
     */
    private static function bundledPolicy(): array
    {
        $raw = (string) file_get_contents(dirname(__DIR__) . '/policy/policy.json');
        $data = json_decode($raw, true);
        self::assertIsArray($data, 'The bundled policy must be valid JSON.');
        return $data;
    }

    /**
     * Validate already-decoded policy data against the bundled schema.
     *
     * Decode as objects, not assoc arrays: opis reports a PHP assoc array as
     * having no JSON type, so "type": "object" would never match.
     *
     * @return list<string> the validation errors, empty when valid
     */
    private static function validateData(mixed $data): array
    {
        $schema = json_decode((string) file_get_contents(dirname(__DIR__) . '/policy/policy.schema.json'), false);

        $validator = new Validator();
        $result = $validator->validate($data, $schema);

        // isValid() is error() === null, so a null error means the data is valid.
        $error = $result->error();
        if ($error === null) {
            return [];
        }

        $where = $error->data()->fullPath();
        $where = $where === [] ? '(root)' : implode('.', $where);
        return [$error->message() . ' at ' . $where];
    }

    /**
     * Validate a policy file against the bundled schema.
     *
     * @return list<string> the validation errors, empty when valid
     */
    private static function validateFile(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), false);
        return self::validateData($data);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function phpLinesWithDockerImages(): array
    {
        return [
            'php 8.3' => ['8.3'],
            'php 8.4' => ['8.4'],
            'php 8.5' => ['8.5'],
        ];
    }

    #[Test]
    #[DataProvider('phpLinesWithDockerImages')]
    public function phpLineCarriesItsDockerImage(string $version): void
    {
        // The brief asked for this assertion through the line() accessor:
        //     Policy::line('php', '8.3')['images']['docker']
        // but line() returns only the five date and patch fields, so the
        // image is read from the bundled policy file itself. The accessor
        // still confirms the line exists.
        $line = Policy::bundled()->line('php', $version);
        self::assertNotNull($line, "The bundled policy must list php {$version}.");

        $entry = self::bundledPolicy()['components']['php']['versions'][$version];
        self::assertArrayHasKey('images', $entry, "php {$version} must carry an images object.");
        self::assertSame(
            ['docker'],
            array_keys($entry['images']),
            "php {$version} carries only the docker image for now.",
        );
        self::assertSame("php:{$version}-cli-trixie", $entry['images']['docker']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function phpLinesWithoutImages(): array
    {
        return [
            'php 8.2' => ['8.2'],
            'php 8.1' => ['8.1'],
        ];
    }

    #[Test]
    #[DataProvider('phpLinesWithoutImages')]
    public function olderPhpLinesCarryNoImages(string $version): void
    {
        // line() never returns an images key today, so "null or absent" is
        // checked both through the accessor and in the bundled file itself.
        $line = Policy::bundled()->line('php', $version);
        self::assertNotNull($line, "The bundled policy must list php {$version}.");
        self::assertNull($line['images'] ?? null, "php {$version} must not carry images.");

        $entry = self::bundledPolicy()['components']['php']['versions'][$version];
        self::assertArrayNotHasKey('images', $entry, "php {$version} must not carry an images object.");
    }

    #[Test]
    public function bundledPolicyIsValidAgainstSchema(): void
    {
        $errors = self::validateFile(dirname(__DIR__) . '/policy/policy.json');
        self::assertSame([], $errors, 'The bundled policy must validate against the schema.');
    }

    #[Test]
    public function unknownImagesKeyIsRejectedBySchema(): void
    {
        $policy = self::bundledPolicy();
        $policy['components']['php']['versions']['8.3']['images']['podman'] = 'docker.io/library/php:8.3-cli';

        $encoded = json_encode($policy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        self::assertNotFalse($encoded, 'The modified policy must encode to JSON.');

        $errors = self::validateData(json_decode($encoded, false));
        self::assertNotSame([], $errors, 'An unknown key under images must be rejected by the schema.');
    }
}
