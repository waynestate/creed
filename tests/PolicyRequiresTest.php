<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Waynestate\Creed\Policy;

/**
 * The requires object a policy line may carry: the PHP range and the minimum
 * database versions a release needs, recorded per Laravel line from
 * Laravel's own support policy. The reader must answer it, and the schema
 * must guard it, without breaking a policy that never used the field.
 */
final class PolicyRequiresTest extends TestCase
{
    /**
     * The table the bundled policy records, verified 2026-11-03 against
     * Laravel's release notes and database docs.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array{min: string, max: string}}>
     */
    public static function laravelRequires(): array
    {
        return [
            'laravel 10' => [
                '10',
                [
                    'php' => ['min' => '8.1', 'max' => '8.3'],
                    'mysql' => '5.7',
                    'mariadb' => '10.10',
                ],
                ['min' => '8.1', 'max' => '8.3'],
            ],
            'laravel 11' => [
                '11',
                [
                    'php' => ['min' => '8.2', 'max' => '8.4'],
                    'mysql' => '5.7',
                    'mariadb' => '10.3',
                ],
                ['min' => '8.2', 'max' => '8.4'],
            ],
            'laravel 12' => [
                '12',
                [
                    'php' => ['min' => '8.2', 'max' => '8.5'],
                    'mysql' => '5.7',
                    'mariadb' => '10.3',
                ],
                ['min' => '8.2', 'max' => '8.5'],
            ],
            'laravel 13' => [
                '13',
                [
                    'php' => ['min' => '8.3', 'max' => '8.5'],
                    'mysql' => '5.7',
                    'mariadb' => '10.3',
                ],
                ['min' => '8.3', 'max' => '8.5'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $expected
     * @param array{min: string, max: string} $expectedRange
     */
    #[Test]
    #[DataProvider('laravelRequires')]
    public function bundledPolicyRecordsTheLaravelRequires(
        string $version,
        array $expected,
        array $expectedRange,
    ): void {
        $policy = Policy::bundled();
        self::assertSame($expected, $policy->requires('laravel', $version));
        self::assertSame($expectedRange, $policy->phpRange('laravel', $version));
    }

    #[Test]
    public function requiresIsNullWhenTheLineHasNone(): void
    {
        $policy = Policy::bundled();
        // No other component line carries requires, and neither does a
        // current line.
        self::assertNull($policy->requires('php', '8.2'));
        self::assertNull($policy->requires('vue', '3'));
        self::assertNull($policy->phpRange('php', '8.2'));
        self::assertNull($policy->phpRange('vue', '3'));
    }

    #[Test]
    public function requiresIsNullForAnUnknownComponentOrVersion(): void
    {
        $policy = Policy::bundled();
        self::assertNull($policy->requires('cobol', '10'));
        self::assertNull($policy->requires('laravel', '9'));
        self::assertNull($policy->phpRange('cobol', '10'));
        self::assertNull($policy->phpRange('laravel', '9'));
    }

    /**
     * A policy shaped before requires existed, built in memory: every known
     * line still answers, and the new accessors say null.
     */
    private function olderShapedPolicy(): Policy
    {
        return Policy::fromArray([
            'schema_version' => 1,
            'components' => [
                'laravel' => [
                    'versions' => [
                        '10' => ['active_until' => '2024-08-06', 'security_until' => '2025-02-04'],
                        '13' => ['active_until' => '2027-09-30', 'security_until' => '2028-03-17'],
                    ],
                ],
            ],
            'rules' => ['eol_grace_days' => 90],
        ]);
    }

    #[Test]
    public function olderShapedPolicyStillReads(): void
    {
        $policy = $this->olderShapedPolicy();
        $until = $policy->securityUntil('laravel', '13');
        self::assertNotNull($until);
        self::assertSame('2028-03-17', $until->format('Y-m-d'));
        self::assertNull($policy->requires('laravel', '10'));
        self::assertNull($policy->phpRange('laravel', '13'));
    }

    #[Test]
    public function olderShapedFileStillReads(): void
    {
        $policy = Policy::fromFile(__DIR__ . '/fixtures/good.json');
        $until = $policy->securityUntil('php', '8.3');
        self::assertNotNull($until);
        self::assertSame('2027-12-31', $until->format('Y-m-d'));
        self::assertNull($policy->requires('php', '8.2'));
        self::assertNull($policy->phpRange('php', '8.2'));
    }

    // --- Schema ---

    /**
     * Validate data against the bundled schema, in memory.
     *
     * @return list<string> the validation errors, empty when valid
     */
    private static function validate(mixed $data): array
    {
        $schema = json_decode((string) file_get_contents(dirname(__DIR__) . '/policy/policy.schema.json'), false);

        $validator = new Validator();
        $result = $validator->validate($data, $schema);

        $error = $result->error();
        if ($error === null) {
            return [];
        }

        $where = $error->data()->fullPath();
        $where = $where === [] ? '(root)' : implode('.', $where);
        return [$error->message() . ' at ' . $where];
    }

    /**
     * Shape an array the way json_decode(..., false) would: objects become
     * stdClass, lists stay arrays, so opis sees JSON types.
     *
     * @param array<int|string, mixed> $data
     *
     * @return object|array<int, mixed>
     */
    private static function jsonShape(array $data): object|array
    {
        if (array_is_list($data)) {
            return array_map(
                static fn(mixed $value): mixed => is_array($value) ? self::jsonShape($value) : $value,
                $data,
            );
        }
        $out = new stdClass();
        foreach ($data as $key => $value) {
            $out->{"{$key}"} = is_array($value) ? self::jsonShape($value) : $value;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $requires
     *
     * @return array<string, mixed>
     */
    private static function policyWithRequires(array $requires): array
    {
        return [
            'schema_version' => 1,
            'components' => [
                'laravel' => [
                    'versions' => [
                        '12' => [
                            'active_until' => '2026-08-13',
                            'security_until' => '2027-02-24',
                            'requires' => $requires,
                        ],
                    ],
                ],
            ],
            'rules' => [
                'audit_severity_threshold' => 'high',
                'banned_constraints' => ['dev-*', '*'],
                'eol_grace_days' => 90,
            ],
        ];
    }

    #[Test]
    public function schemaAcceptsALineWithRequires(): void
    {
        $errors = self::validate(self::jsonShape(self::policyWithRequires([
            'php' => ['min' => '8.2', 'max' => '8.5'],
            'mysql' => '5.7',
            'mariadb' => '10.3',
        ])));
        self::assertSame([], $errors, 'A version entry with a requires object must validate.');
    }

    #[Test]
    public function schemaRejectsRequiresPhpWithoutMax(): void
    {
        $errors = self::validate(self::jsonShape(self::policyWithRequires([
            'php' => ['min' => '8.2'],
        ])));
        self::assertNotSame([], $errors, 'requires.php without its max bound must not validate.');
    }

    #[Test]
    public function schemaAcceptsAPolicyWithoutAnyRequires(): void
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/fixtures/good.json'), false);
        self::assertSame([], self::validate($data), 'A policy with no requires anywhere must validate.');
    }
}
