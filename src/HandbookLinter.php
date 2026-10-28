<?php

declare(strict_types=1);

namespace Waynestate\Creed;

/**
 * Lints the CREED handbook for RFC 2119 discipline.
 *
 * A handbook chapter that declares RFC 2119 capitals at its top (the part
 * before the first "## " heading) must not use a lowercase RFC 2119 keyword
 * (must, must not, should, should not, may) as a requirement word outside
 * code spans and code blocks. A deliberate plain-English use is excused with
 * a `<!-- lint: allow -->` comment on the same line.
 *
 * The linter is deliberately small and pure: it takes a file's content and
 * returns its violations, so the same rule runs in CI (bin/lint-handbook.php)
 * and in the test suite without a shell.
 */
final class HandbookLinter
{
    /**
     * The lowercase RFC 2119 keywords, longest first so a two-word form wins
     * over its single-word half ("must not" before "must").
     *
     * @var list<string>
     */
    private const KEYWORDS = ['must not', 'should not', 'must', 'should', 'may'];

    /**
     * Lint one handbook file's content.
     *
     * Returns one violation per offending line, each the 1-based line number
     * and the keyword that tripped it. Empty when the file is clean, or when
     * it does not declare RFC 2119 capitals at its top.
     *
     * @return list<array{line: int, keyword: string}>
     */
    public function lint(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        if (!$this->declaresRfc2119($lines)) {
            return [];
        }

        $violations = [];
        $inCodeBlock = false;

        foreach ($lines as $index => $line) {
            // A line that opens or closes a fenced code block toggles the
            // block; the fence itself is never prose.
            if (preg_match('/^\s*```/', $line) === 1) {
                $inCodeBlock = !$inCodeBlock;
                continue;
            }
            if ($inCodeBlock) {
                continue;
            }
            // Inline code spans are not prose; strip them before scanning.
            $prose = (string) preg_replace('/`[^`]*`/', '', $line);
            // A deliberate plain-English use is excused on its own line.
            if (str_contains($prose, '<!-- lint: allow -->')) {
                continue;
            }
            $keyword = $this->firstKeyword($prose);
            if ($keyword !== null) {
                $violations[] = ['line' => $index + 1, 'keyword' => $keyword];
            }
        }

        return $violations;
    }

    /**
     * The first lowercase RFC 2119 keyword in the line, or null when there is
     * none. Two-word forms are checked before their single-word halves.
     */
    private function firstKeyword(string $line): ?string
    {
        foreach (self::KEYWORDS as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $line) === 1) {
                return $keyword;
            }
        }

        return null;
    }

    /**
     * Whether the file declares RFC 2119 capitals at its top: the lines before
     * the first "## " heading.
     *
     * @param list<string> $lines
     */
    private function declaresRfc2119(array $lines): bool
    {
        foreach ($lines as $line) {
            if (str_starts_with($line, '## ')) {
                return false; // the top is over and no declaration was found
            }
            if (str_contains($line, 'RFC 2119')) {
                return true;
            }
        }

        return false;
    }
}
