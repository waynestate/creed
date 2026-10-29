<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tools\HandbookSite;

/**
 * One handbook chapter, read from handbook/<slug>.md and set in type.
 *
 * A plain value: the parser fills it, the site builder prints it. Nothing in
 * here is written by hand, which is the whole point. Creed Bratton keeps his
 * own records; this book only keeps copies of the Markdown.
 */
final class Chapter
{
    /**
     * @param list<array{id: string, number: string, title: string, level: int}> $headings
     * @param array<string, int> $keywords RFC 2119 keyword => count, in the chapter's rules
     * @param list<string> $policyKeys the policy.json keys the chapter names in code spans
     * @param list<array{id: string, title: string, number: string, text: string}> $sections search sections
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $file,
        public readonly int $number,
        public readonly string $title,
        public readonly string $html,
        public readonly array $headings,
        public readonly array $keywords,
        public readonly int $items,
        public readonly bool $rfc,
        public readonly string $status,
        public readonly array $policyKeys,
        public readonly array $sections,
        public readonly string $hash,
    ) {}

    /** The page this chapter is printed on, relative to site/handbook/. */
    public function href(): string
    {
        return $this->slug . '.html';
    }
}
