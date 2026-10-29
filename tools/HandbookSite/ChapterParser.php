<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tools\HandbookSite;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;

/**
 * Turns one handbook Markdown file into a Chapter.
 *
 * league/commonmark does the Markdown (CommonMark plus GitHub tables and
 * autolinks). This class only walks the tree it returns and adds what a code
 * book needs: section numbers (§3.2), self-links on every heading, the RFC
 * 2119 keywords set in small capitals, "To be decided" marked as a gap, links
 * to the stack's parts, and policy.json keys linked to the file.
 *
 * Everything is done on the tree or with plain string replacements, never
 * through DOMDocument, so the output is byte-for-byte the same on every PHP
 * version and every libxml in the CI matrix. The freshness check depends on it.
 */
final class ChapterParser
{
    public const GITHUB = 'https://github.com/waynestate/creed/blob/main/';

    /** RFC 2119 keywords, the two-word forms first so they win. */
    private const KEYWORDS = ['MUST NOT', 'SHOULD NOT', 'MUST', 'SHOULD', 'MAY'];

    /**
     * The stack's parts, linked on their first mention in a chapter. The
     * hrefs are production; assets/stack-env.js points them at dev or test.
     *
     * @var array<string, array{0: string, 1: string|null}> name => [slug, mark]
     */
    private const PARTS = [
        'PROCTOR' => ['proctor', 'proctor.png'],
        'PREFLIGHT' => ['preflight', 'preflight.png'],
        'HELMET' => ['helmet', 'helmet.png'],
        'SQUIRE' => ['squire', 'squire.png'],
        'SESAME' => ['sesame', 'sesame.png'],
        'ROLLCALL' => ['rollcall', 'rollcall.png'],
        'BOOSTER' => ['booster', 'booster.png'],
        'ADVISOR' => ['advisor', 'advisor.png'],
        'DRAWBRIDGE' => ['drawbridge', 'drawbridge.png'],
        'WELD' => ['weld', 'weld.png'],
        'Sourdough' => ['sourdough', 'sourdough.png'],
        'Pinstripe' => ['pinstripe', 'pinstripe.png'],
        'WayneUI' => ['wayneui', null],
    ];

    private readonly MarkdownParser $parser;

    private readonly HtmlRenderer $renderer;

    /**
     * @param array<string, string> $policyKeys policy.json key => its path, e.g. eol_grace_days => rules.eol_grace_days
     * @param array<string, string> $chapterSlugs handbook file name => slug, for links between chapters
     */
    public function __construct(
        private readonly array $policyKeys,
        private readonly array $chapterSlugs,
    ) {
        $environment = new Environment([
            // The handbook is ours and carries only <!-- lint: allow --> comments,
            // which are stripped before parsing. Raw HTML stays allowed so the
            // decorations below can be added as inline HTML nodes.
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        $this->parser = new MarkdownParser($environment);
        $this->renderer = new HtmlRenderer($environment);
    }

    /**
     * @param string $prefix '' for a chapter's own page; "<slug>--" when every chapter shares one page (the print edition)
     */
    public function parse(string $file, string $markdown, int $number, string $prefix = ''): Chapter
    {
        $slug = $this->slugFor($file);
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $hash = substr(hash('sha256', $markdown), 0, 7);
        $markdown = (string) preg_replace('/<!--.*?-->/s', '', $markdown);

        $document = $this->parser->parse($markdown);

        $title = $this->takeTitle($document) ?? ucfirst($slug);
        $rfc = $this->takeRfcNotice($document);
        $headings = $this->numberHeadings($document, $number, $prefix);
        $sections = $this->sections($document, $headings, $title);
        $keywords = $this->countKeywords($sections);
        $items = $this->countItems($document);
        $status = $this->status($document);

        $named = [];
        $this->decorate($document, $prefix, $named);

        $html = $this->renderer->renderDocument($document)->getContent();
        $html = $this->finish($html, $number);

        $policyKeys = array_keys($named);
        sort($policyKeys);

        return new Chapter(
            slug: $slug,
            file: $file,
            number: $number,
            title: $title,
            html: $html,
            headings: $headings,
            keywords: $keywords,
            items: $items,
            rfc: $rfc,
            status: $status,
            policyKeys: $policyKeys,
            sections: $sections,
            hash: $hash,
        );
    }

    public function slugFor(string $file): string
    {
        return $this->chapterSlugs[$file] ?? self::slug(basename($file, '.md'));
    }

    public static function slug(string $text): string
    {
        $slug = strtolower($text);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-') ?: 'section';
    }

    /** Removes the chapter's # heading and returns its text. */
    private function takeTitle(Document $document): ?string
    {
        foreach ($document->children() as $child) {
            if ($child instanceof Heading && $child->getLevel() === 1) {
                $title = $this->plain($child);
                $child->detach();

                return $title;
            }
        }

        return null;
    }

    /**
     * Removes the RFC 2119 boilerplate paragraph. The page prints it once, as
     * a key at the head of the chapter, instead of as the chapter's first line.
     */
    private function takeRfcNotice(Document $document): bool
    {
        foreach ($document->children() as $child) {
            if ($child instanceof Paragraph && str_starts_with($this->plain($child), 'The key words MUST')) {
                $child->detach();

                return true;
            }
            if ($child instanceof Heading) {
                break;
            }
        }

        return false;
    }

    /**
     * Gives every ## and ### a section number and an id. §3.2 is chapter 3,
     * section 2; §3.2.1 its first subsection.
     *
     * @return list<array{id: string, number: string, title: string, level: int}>
     */
    private function numberHeadings(Document $document, int $chapter, string $prefix): array
    {
        $headings = [];
        $used = [];
        $h2 = 0;
        $h3 = 0;
        foreach ($this->nodes($document, Heading::class) as $heading) {
            $level = $heading->getLevel();
            if ($level === 2) {
                $h2++;
                $h3 = 0;
                $sec = $chapter . '.' . $h2;
            } elseif ($level === 3) {
                $h3++;
                $sec = $chapter . '.' . $h2 . '.' . $h3;
            } else {
                $sec = '';
            }

            $title = $this->plain($heading);
            $base = self::slug($title);
            $id = $base;
            $n = 2;
            while (isset($used[$id])) {
                $id = $base . '-' . $n++;
            }
            $used[$id] = true;
            $fullId = $prefix . $id;

            $heading->data->set('attributes/id', $fullId);
            if ($title === 'To be decided') {
                $heading->data->set('attributes/class', 'hb-h-tbd');
            }
            if ($sec !== '') {
                // The section number is the heading's own permalink.
                $heading->prependChild(new HtmlInline(
                    '<a class="hb-sec" href="#' . self::e($fullId) . '">§' . $sec . '</a> ',
                ));
            }
            if ($level <= 3) {
                $headings[] = ['id' => $fullId, 'number' => $sec, 'title' => $title, 'level' => $level];
            }
        }

        return $headings;
    }

    /**
     * Splits the chapter at its headings into search sections.
     *
     * @param list<array{id: string, number: string, title: string, level: int}> $headings
     *
     * @return list<array{id: string, title: string, number: string, text: string}>
     */
    private function sections(Document $document, array $headings, string $title): array
    {
        $sections = [];
        $current = ['id' => '', 'title' => $title, 'number' => '', 'text' => ''];
        $i = 0;
        foreach ($document->children() as $child) {
            if ($child instanceof Heading && $child->getLevel() <= 3 && isset($headings[$i])) {
                if (trim($current['text']) !== '' || $current['id'] !== '') {
                    $sections[] = $current;
                }
                $h = $headings[$i++];
                $current = ['id' => $h['id'], 'title' => $h['title'], 'number' => $h['number'], 'text' => ''];
                continue;
            }
            $current['text'] .= ' ' . $this->plain($child);
        }
        $sections[] = $current;

        foreach ($sections as &$section) {
            $section['text'] = trim((string) preg_replace('/\s+/', ' ', $section['text']));
        }
        unset($section);

        return $sections;
    }

    /**
     * @param list<array{id: string, title: string, number: string, text: string}> $sections
     *
     * @return array<string, int>
     */
    private function countKeywords(array $sections): array
    {
        $text = implode(' ', array_column($sections, 'text'));
        $counts = [];
        foreach (['MUST NOT', 'MUST', 'SHOULD NOT', 'SHOULD', 'MAY'] as $keyword) {
            $count = preg_match_all('/\b' . $keyword . '\b/', $text);
            $text = (string) preg_replace('/\b' . $keyword . '\b/', '', $text);
            if ($count > 0) {
                $counts[$keyword] = $count;
            }
        }

        return $counts;
    }

    /** Top-level list items outside the Sources lists: the chapter's numbered provisions. */
    private function countItems(Document $document): int
    {
        $items = 0;
        $inSources = false;
        foreach ($document->children() as $child) {
            if ($child instanceof Heading) {
                $inSources = $this->plain($child) === 'Sources';
            } elseif ($child instanceof ListBlock && !$inSources) {
                foreach ($child->children() as $item) {
                    $items++;
                }
            }
        }

        return $items;
    }

    /** decided, partly or tbd. */
    private function status(Document $document): string
    {
        $first = $document->firstChild();
        if ($first instanceof Paragraph && str_starts_with($this->plain($first), 'To be decided')) {
            return 'tbd';
        }
        foreach ($this->nodes($document, Heading::class) as $heading) {
            if ($this->plain($heading) === 'To be decided') {
                return 'partly';
            }
        }
        foreach ($this->nodes($document, ListItem::class) as $item) {
            if (str_starts_with($this->plain($item), 'To be decided')) {
                return 'partly';
            }
        }

        return 'decided';
    }

    /**
     * The decorations: links between chapters, links to the parts, policy
     * keys, RFC keywords, the gaps and the bibliography.
     *
     * @param array<string, true> $named filled with the policy keys the chapter names
     */
    private function decorate(Document $document, string $prefix, array &$named): void
    {
        // Paragraphs and items that open with "To be decided" are gaps.
        foreach ($this->nodes($document, Paragraph::class) as $paragraph) {
            if (!str_starts_with($this->plain($paragraph), 'To be decided')) {
                continue;
            }
            $parent = $paragraph->parent();
            if ($parent instanceof ListItem) {
                $parent->data->set('attributes/class', 'hb-tbd');
            } else {
                $paragraph->data->set('attributes/class', 'hb-tbd');
                $paragraph->prependChild(new HtmlInline(
                    '<span class="stamp stamp--notyet" aria-hidden="true">Not yet</span> ',
                ));
            }
        }

        // The list after a "Sources" heading is a bibliography, not provisions.
        foreach ($this->nodes($document, Heading::class) as $heading) {
            $next = $heading->next();
            if ($this->plain($heading) === 'Sources' && $next instanceof ListBlock) {
                $next->data->set('attributes/class', 'hb-sources');
            }
        }

        foreach ($this->nodes($document, Link::class) as $link) {
            $link->setUrl($this->rewriteUrl($link->getUrl(), $prefix));
        }

        $linkedFile = false;
        foreach ($this->nodes($document, Code::class) as $code) {
            $literal = $code->getLiteral();
            if (isset($named[$literal])) {
                continue; // linked once per chapter; after that it is plain code
            }
            if (isset($this->policyKeys[$literal]) && !$this->inside($code, Link::class)) {
                $named[$literal] = true;
                $code->replaceWith(new HtmlInline(
                    '<a class="hb-policy-key" href="' . self::GITHUB . 'policy/policy.json" title="'
                    . self::e($this->policyKeys[$literal]) . ' in policy.json"><code>' . self::e($literal) . '</code></a>',
                ));
            } elseif ($literal === 'policy.json' || $literal === 'policy/policy.json') {
                if (!$this->inside($code, Link::class) && !$linkedFile) {
                    $linkedFile = true;
                    $code->replaceWith(new HtmlInline(
                        '<a class="hb-policy-key" href="' . self::GITHUB . 'policy/policy.json"><code>' . self::e($literal) . '</code></a>',
                    ));
                }
            }
        }

        $linked = [];
        $parts = implode('|', array_map(static fn(string $p): string => preg_quote($p, '/'), array_keys(self::PARTS)));
        $keywords = implode('|', self::KEYWORDS);
        $pattern = '/\b(' . $keywords . '|' . $parts . ')\b/';
        foreach ($this->nodes($document, Text::class) as $text) {
            if ($this->inside($text, Link::class) || $this->inside($text, Heading::class)) {
                continue;
            }
            $pieces = preg_split($pattern, $text->getLiteral(), -1, PREG_SPLIT_DELIM_CAPTURE);
            if ($pieces === false || count($pieces) === 1) {
                continue;
            }
            foreach ($pieces as $i => $piece) {
                if ($piece === '') {
                    continue;
                }
                if ($i % 2 === 0) {
                    $text->insertBefore(new Text($piece));
                } elseif (in_array($piece, self::KEYWORDS, true)) {
                    $class = 'kw kw--' . strtolower(str_replace(' ', '-', $piece));
                    $text->insertBefore(new HtmlInline('<strong class="' . $class . '">' . $piece . '</strong>'));
                } elseif (!isset($linked[$piece])) {
                    $linked[$piece] = true;
                    $text->insertBefore(new HtmlInline($this->partLink($piece)));
                } else {
                    $text->insertBefore(new Text($piece));
                }
            }
            $text->detach();
        }
    }

    private function partLink(string $name): string
    {
        [$slug, $mark] = self::PARTS[$name];
        $img = $mark === null ? '' : '<img src="../assets/marks/' . $mark . '" alt="" width="16" height="16">';

        return '<a class="tool" href="https://' . $slug . '.apps.wayne.edu/">' . $img . $name . '</a>';
    }

    /** Chapter links stay on the site; anything else relative goes to the file on GitHub. */
    private function rewriteUrl(string $url, string $prefix): string
    {
        if ($url === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1) {
            return $url;
        }
        $fragment = '';
        $hashAt = strpos($url, '#');
        if ($hashAt !== false) {
            $fragment = substr($url, $hashAt + 1);
            $url = substr($url, 0, $hashAt);
        }
        if ($url === '') {
            return '#' . $prefix . $fragment;
        }
        $file = basename($url);
        if (!str_contains($url, '/') && $file === 'README.md') {
            return $prefix === '' ? './' : '#contents';
        }
        if (!str_contains($url, '/') && isset($this->chapterSlugs[$file])) {
            $slug = $this->chapterSlugs[$file];
            if ($prefix !== '') {
                return '#' . $slug . ($fragment === '' ? '' : '--' . $fragment);
            }

            return $slug . '.html' . ($fragment === '' ? '' : '#' . $fragment);
        }

        return self::GITHUB . 'handbook/' . $url . ($fragment === '' ? '' : '#' . $fragment);
    }

    /** String-level finishing: tables in scroll regions, citations, status words. */
    private function finish(string $html, int $chapter): string
    {
        $table = 0;
        $html = (string) preg_replace_callback(
            '#<table>(.*?)</table>#s',
            static function (array $m) use (&$table, $chapter): string {
                $table++;
                $id = 'table-' . $chapter . '-' . $table;

                return '<figure class="hb-table"><figcaption id="' . $id . '"><span class="code-table__no">Table '
                    . $chapter . '-' . $table . '</span></figcaption><div class="hb-table__scroll" tabindex="0" role="region" aria-labelledby="'
                    . $id . '"><table>' . $m[1] . '</table></div></figure>';
            },
            $html,
        );

        // "(the stack's version policy)" and "(first-repos)" cite the decision behind a rule.
        $html = (string) preg_replace(
            "/\\((the stack&#039;s [^()<>]{2,90}?|the stack's [^()<>]{2,90}?|first-repos)\\)/",
            '<span class="hb-cite">($1)</span>',
            $html,
        );

        return (string) preg_replace(
            '#<td>(Planned|To be decided|to be decided)</td>#',
            '<td><span class="st st--planned">$1</span></td>',
            $html,
        );
    }

    /** The node's text, with code spans and line breaks, and no markup. */
    private function plain(Node $node): string
    {
        if ($node instanceof Text || $node instanceof Code) {
            return $node->getLiteral();
        }
        if ($node instanceof FencedCode || $node instanceof IndentedCode) {
            return $node->getLiteral();
        }
        if ($node instanceof Newline) {
            return ' ';
        }
        $parts = [];
        foreach ($node->children() as $child) {
            $parts[] = $this->plain($child);
        }
        $glue = $node instanceof Paragraph || $node instanceof Heading || $node instanceof Link ? '' : ' ';

        return trim(implode($glue, $parts));
    }

    /**
     * Every node of one type, collected first so the tree can change after.
     *
     * @template T of Node
     *
     * @param class-string<T> $type
     *
     * @return list<T>
     */
    private function nodes(Document $document, string $type): array
    {
        $found = [];
        $walker = $document->walker();
        while (($event = $walker->next()) !== null) {
            $node = $event->getNode();
            if ($event->isEntering() && $node instanceof $type) {
                $found[] = $node;
            }
        }

        return $found;
    }

    /** @param class-string<Node> $type */
    private function inside(Node $node, string $type): bool
    {
        for ($parent = $node->parent(); $parent !== null; $parent = $parent->parent()) {
            if ($parent instanceof $type) {
                return true;
            }
        }

        return false;
    }

    public static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
