---
title: Reader API
description: Every public member of the Waynestate\Creed\Policy reader and the one question each one answers.
---

# Reader API

This page lists every public member of the `Waynestate\Creed\Policy` reader, in the order the README introduces them, with the signature from `src/Policy.php`.

| Member | What it does | Signature |
| --- | --- | --- |
| `Policy::bundled()` | Load a policy | `public static function bundled(): self` |
| `Policy::fromFile($path)` | Load a policy | `public static function fromFile(string $path): self` |
| `Policy::fromArray($data)` | Load a policy | `public static function fromArray(array $data): self` |
| `origin()` | Says where it came from | `public function origin(): string` |
| `stateOf($component, $version, $on)` | The state, or an exception | `public function stateOf(string $component, string $version, DateTimeImmutable $on): State` |
| `tryStateOf()` | The same answer as `null` when it cannot be answered | `public function tryStateOf(string $component, ?string $version, DateTimeImmutable $on): ?State` |
| `oldestSupported($component, $on)` | The lowest version still Active or Security | `public function oldestSupported(string $component, DateTimeImmutable $on): ?string` |
| `supported()` | Lists all of them, oldest first | `public function supported(string $component, DateTimeImmutable $on): array` |
| `latestPatch($component, $version)` | The current patch in that line | `public function latestPatch(string $component, string $version): ?string` |
| `minimumPatch($component, $version)` | The oldest patch the stack accepts in that line, or null | `public function minimumPatch(string $component, string $version): ?string` |
| `latestPatchChecked()` | Says when it was last checked | `public function latestPatchChecked(string $component, string $version): ?string` |
| `line($component, $version)` | The raw line, with `active_until`, `security_until`, `latest_patch`, `latest_patch_checked` (each a string or null) and `current` (true or null) | `public function line(string $component, string $version): ?array` |
| `requires($component, $version)` | The line's `requires` object as an array (`php` range, `mysql` and `mariadb` minimums), or null | `public function requires(string $component, string $version): ?array` |
| `phpRange($component, $version)` | The line's PHP range as `['min' => ..., 'max' => ...]`, or null | `public function phpRange(string $component, string $version): ?array` |
| `activeUntil()` | Those dates as `DateTimeImmutable`, or null | `public function activeUntil(string $component, string $version): ?DateTimeImmutable` |
| `securityUntil()` | Those dates as `DateTimeImmutable`, or null | `public function securityUntil(string $component, string $version): ?DateTimeImmutable` |
| `daysToEol($component, $version, $on)` | Whole days until `security_until`, signed | `public function daysToEol(string $component, string $version, DateTimeImmutable $on): ?int` |
| `isPastGrace()` | Says whether the grace period is over | `public function isPastGrace(string $component, string $version, DateTimeImmutable $on): bool` |
| `resolveVersion($component, $version)` | The policy line a full version like `8.3.12` belongs to | `public function resolveVersion(string $component, string $version): ?string` |
| `isAheadOfPolicy($component, $version)` | Whether a version sorts above every line the policy lists | `public function isAheadOfPolicy(string $component, string $version): bool` |
| `belowOldestLineReason($component, $version)` | The reason a version is `Eol` for sorting below every listed line, or null | `public function belowOldestLineReason(string $component, string $version): ?string` |
| `hasComponent()` | The shape of the policy itself | `public function hasComponent(string $component): bool` |
| `components()` | The shape of the policy itself | `public function components(): array` |
| `versions($component)` | The shape of the policy itself | `public function versions(string $component): array` |
| `tools()` | The shape of the policy itself | `public function tools(): array` |
| `toolMinimum($tool)` | The shape of the policy itself | `public function toolMinimum(string $tool): ?string` |
| `rules()` | The shape of the policy itself | `public function rules(): array` |

> [!NOTE]
> The wording is the README's, one line per member; the signatures are copied from `src/Policy.php`.

## Related

- [Reading the policy from PHP](../guides/reading-the-policy.md)
- [Support states and the grace period](../guides/support-states.md)
- [Install](../install.md)
