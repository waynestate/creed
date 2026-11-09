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
 * The same chapters also name what holds each rule (practice 29): every
 * rule that uses an RFC 2119 capital carries a "PROCTOR check:", a
 * "Held by:" or a "No check exists yet" tag. See untaggedRules().
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
     * Find the rules that name no enforcement point (practice 29).
     *
     * In a chapter that declares RFC 2119 capitals, a rule is a list item or a
     * paragraph that uses MUST, SHOULD or MAY outside code spans. Every rule
     * carries one of three tags, so a reader always knows what holds it:
     *
     * - "PROCTOR check: `<id>`" (or "PROCTOR checks: `<id>`, ...") names
     *   the PROCTOR check that reports a breach;
     * - "Held by: ..." names any other point that holds it (a Preflight
     *   component, a runtime package, a review step);
     * - "No check exists yet" says, honestly, that nothing does.
     *
     * The declaration paragraph itself (the one that cites RFC 2119),
     * headings, table rows and code blocks are not rules. A list item's
     * indented continuation lines belong to it; a nested list item is a rule
     * of its own.
     *
     * Returns one entry per untagged rule: the 1-based line it starts on and
     * its opening words. Empty when every rule is tagged, or when the file
     * does not declare RFC 2119 capitals at its top.
     *
     * @return list<array{line: int, excerpt: string}>
     */
    public function untaggedRules(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        if (!$this->declaresRfc2119($lines)) {
            return [];
        }

        $untagged = [];
        foreach ($this->blocks($lines) as $block) {
            if (!$this->isRule($block['text']) || $this->isTagged($block['text'])) {
                continue;
            }
            $untagged[] = ['line' => $block['line'], 'excerpt' => $this->excerpt($block['text'])];
        }

        return $untagged;
    }

    /**
     * Split a chapter into its prose blocks: list items and paragraphs, each
     * with the 1-based line it starts on and its lines joined by spaces.
     * Fenced code blocks, headings, table rows and blank lines end a block
     * and are never part of one.
     *
     * @param list<string> $lines
     *
     * @return list<array{line: int, text: string}>
     */
    private function blocks(array $lines): array
    {
        $blocks = [];
        $current = null;
        $inCodeBlock = false;

        foreach ($lines as $index => $line) {
            $isFence = preg_match('/^\s*```/', $line) === 1;
            $isBreak = $isFence
                || $inCodeBlock
                || trim($line) === ''
                || preg_match('/^\s*(#|\|)/', $line) === 1;

            if ($isFence) {
                $inCodeBlock = !$inCodeBlock;
            }
            if ($isBreak) {
                if ($current !== null) {
                    $blocks[] = $current;
                    $current = null;
                }
                continue;
            }

            // A list marker always opens a new block, at any depth.
            if (preg_match('/^\s*([-*+]|\d+[.)])\s+/', $line) === 1) {
                if ($current !== null) {
                    $blocks[] = $current;
                }
                $current = ['line' => $index + 1, 'text' => trim($line)];
                continue;
            }

            if ($current === null) {
                $current = ['line' => $index + 1, 'text' => trim($line)];
            } else {
                $current['text'] .= ' ' . trim($line);
            }
        }
        if ($current !== null) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * Whether a block states a rule: an RFC 2119 capital outside code spans,
     * in a block that is not the chapter's RFC 2119 declaration.
     */
    private function isRule(string $text): bool
    {
        if (str_contains($text, 'RFC 2119')) {
            return false; // the declaration names the key words; it is not a rule
        }
        $prose = (string) preg_replace('/`[^`]*`/', '', $text);

        return preg_match('/\b(MUST|SHOULD|MAY)\b/', $prose) === 1;
    }

    /**
     * Whether a rule names what holds it, with one of the three tags.
     */
    private function isTagged(string $text): bool
    {
        return preg_match('/PROCTOR checks?: `[a-z0-9][a-z0-9-]*`/', $text) === 1
            || preg_match('/\bHeld by: \S/', $text) === 1
            || str_contains($text, 'No check exists yet');
    }

    /**
     * The first words of a rule, for the error line: enough to find it.
     */
    private function excerpt(string $text): string
    {
        $text = (string) preg_replace('/^([-*+]|\d+[.)])\s+/', '', $text);

        return strlen($text) > 72 ? substr($text, 0, 69) . '...' : $text;
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
