<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tools\HandbookSite;

use RuntimeException;

/**
 * Builds site/handbook/ from handbook/*.md: a front door, one page per
 * chapter, a print edition with the whole book on one page, and the search
 * index. build() returns every file as path => bytes and writes nothing, so
 * the same call serves both the writer and the freshness check.
 *
 * The chapter order is the list in handbook/README.md. A chapter file the
 * README does not list is still printed, after the listed ones, so a new
 * chapter never goes missing from the site because nobody edited the index.
 *
 * No dates and no clocks: the pages depend only on the Markdown and
 * policy.json, so a rebuild with nothing changed is byte-identical. "I've
 * been involved in a number of cults", Creed Bratton says; this generator is
 * involved in exactly one source of truth.
 */
final class SiteBuilder
{
    public const OUT = 'site/handbook';

    /** @var list<Chapter> */
    private array $chapters = [];

    /** @var list<Chapter> the same chapters, ids prefixed, for the print edition */
    private array $bookChapters = [];

    private string $lede = '';

    private string $readmeTitle = 'The CREED handbook';

    /** @var array<string, string> policy key => path */
    private array $policyKeys = [];

    /** @var array<string, mixed> */
    private array $policy = [];

    public function __construct(private readonly string $root) {}

    /** @return array<string, string> path relative to the repository => content */
    public function build(): array
    {
        $this->load();

        $files = [];
        $files[self::OUT . '/index.html'] = $this->indexPage();
        foreach ($this->chapters as $i => $chapter) {
            $files[self::OUT . '/' . $chapter->href()] = $this->chapterPage(
                $chapter,
                $this->chapters[$i - 1] ?? null,
                $this->chapters[$i + 1] ?? null,
            );
        }
        $files[self::OUT . '/print.html'] = $this->printPage();
        $files[self::OUT . '/search-index.js'] = $this->searchIndex();

        return $files;
    }

    private function load(): void
    {
        $dir = $this->root . '/handbook';
        $readme = $this->read($dir . '/README.md');

        // The README's list is the contents, in order.
        preg_match_all('/^\s*[-*]\s+\[[^\]]+\]\(([a-z0-9-]+\.md)\)/mi', $readme, $m);
        $order = $m[1];
        $all = array_map('basename', glob($dir . '/*.md') ?: []);
        sort($all);
        foreach ($all as $file) {
            if ($file !== 'README.md' && !in_array($file, $order, true)) {
                $order[] = $file;
            }
        }
        $order = array_values(array_filter($order, static fn(string $f): bool => is_file($dir . '/' . $f)));

        $slugs = [];
        foreach ($order as $file) {
            $slugs[$file] = ChapterParser::slug(basename($file, '.md'));
        }

        $this->policy = $this->decode($this->read($this->root . '/policy/policy.json'));
        $this->policyKeys = [];
        $this->collectKeys($this->policy, '');
        ksort($this->policyKeys);

        $parser = new ChapterParser($this->policyKeys, $slugs);
        $this->chapters = [];
        $this->bookChapters = [];
        foreach ($order as $i => $file) {
            $markdown = $this->read($dir . '/' . $file);
            $this->chapters[] = $parser->parse($file, $markdown, $i + 1);
            $this->bookChapters[] = $parser->parse($file, $markdown, $i + 1, $slugs[$file] . '--');
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $readme));
        $title = (string) array_shift($lines);
        $this->readmeTitle = trim(ltrim($title, '# ')) ?: $this->readmeTitle;
        $paragraph = [];
        foreach ($lines as $line) {
            if (trim($line) === '' && $paragraph !== []) {
                break;
            }
            if (trim($line) !== '') {
                $paragraph[] = trim($line);
            }
        }
        $this->lede = implode(' ', $paragraph);
    }

    /**
     * Every key in policy.json that has an underscore in it (the rule and
     * field names, never a component or a version), with a path that names
     * where it lives: rules.eol_grace_days, components.*.versions.*.security_until.
     *
     * @param array<mixed> $node
     */
    private function collectKeys(array $node, string $path): void
    {
        foreach ($node as $key => $value) {
            $key = (string) $key;
            // A component, a version or a tool name is data, not a key: it prints as *.
            $wild = preg_match('/(^|\.)(components|versions|tools)$/', $path) === 1;
            $here = $path === '' ? $key : $path . '.' . ($wild ? '*' : $key);
            if (str_contains($key, '_') && !isset($this->policyKeys[$key])) {
                $this->policyKeys[$key] = $here;
            }
            if (is_array($value) && !array_is_list($value)) {
                $this->collectKeys($value, $here);
            }
        }
    }

    // ------------------------------------------------------------- pages

    private function indexPage(): string
    {
        $count = count($this->chapters);
        $words = [1 => 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen'];
        $countWord = $words[$count] ?? (string) $count;
        $items = array_sum(array_map(static fn(Chapter $c): int => $c->items, $this->chapters));
        $must = 0;
        $should = 0;
        $may = 0;
        foreach ($this->chapters as $c) {
            $must += ($c->keywords['MUST'] ?? 0) + ($c->keywords['MUST NOT'] ?? 0);
            $should += ($c->keywords['SHOULD'] ?? 0) + ($c->keywords['SHOULD NOT'] ?? 0);
            $may += $c->keywords['MAY'] ?? 0;
        }

        $rows = '';
        foreach ($this->chapters as $c) {
            $sections = array_values(array_filter($c->headings, static fn(array $h): bool => $h['level'] === 2));
            $names = implode(' · ', array_map(static fn(array $h): string => self::e($h['title']), $sections));
            $rows .= '        <li class="hb-door__row">'
                . '<a class="hb-door__link" href="' . $c->href() . '">'
                . '<span class="toc__no">' . $c->number . '</span>'
                . '<span class="hb-door__text"><span class="toc__name">' . self::e($c->title) . '</span>'
                . ($names !== '' ? '<span class="hb-door__sections">' . $names . '</span>' : '')
                . '</span></a>'
                . '<span class="hb-door__meta">' . $this->register($c) . '</span>'
                . $this->statusStamp($c->status)
                . "</li>\n";
        }

        $body = <<<HTML
              <header class="chap-head hb-door__head">
                <p class="chap-head__kicker">The handbook · 2026 Edition</p>
                <h1 id="page-title">{$this->e($this->readmeTitle)}</h1>
                <p class="chap-head__lede">{$this->e($this->lede)}</p>
                <p class="hb-register" aria-label="In this edition">{$countWord} chapters · {$items} provisions · <strong class="kw kw--must">MUST</strong> {$must} · <strong class="kw kw--should">SHOULD</strong> {$should} · <strong class="kw kw--may">MAY</strong> {$may}</p>
              </header>

              <h2 class="hb-door__title" id="contents">Contents</h2>
              <ol class="hb-door" aria-labelledby="contents">
        {$rows}      </ol>

              <div class="key hb-key">
                <h2 class="key__title">How to read this book</h2>
                <dl class="key__list">
                  <div><dt><a class="hb-sec" href="#contents" aria-label="Section number, for example 3.2">§3.2</a></dt><dd>Chapter 3, section 2. Every section number is a link to itself: copy it to cite the rule.</dd></div>
                  <div><dt><strong class="kw kw--must">MUST</strong> <strong class="kw kw--should">SHOULD</strong> <strong class="kw kw--may">MAY</strong></dt><dd>The RFC 2119 key words, set in small capitals so a requirement never hides in a sentence.</dd></div>
                  <div><dt><span class="hb-cite">(the stack's version policy)</span></dt><dd>The decision a rule comes from. Rules state what is decided and cite it.</dd></div>
                  <div><dt><span class="stamp stamp--notyet">Not yet</span></dt><dd>"To be decided": the gap is printed, and no rule is stated in its place.</dd></div>
                </dl>
              </div>

              <div class="hb-door__more">
                <p><a class="btn btn--rule" href="print.html">The whole book on one page</a></p>
                <p>For printing, or for your browser's find. Every chapter, in order, from the same Markdown.</p>
              </div>
        HTML;

        return $this->layout(
            title: 'CREED Handbook: the rules, chapter by chapter',
            description: 'The CREED handbook: the written rules for the Wayne State app stack, chapter by chapter, each citing the decision behind it.',
            crumbs: [['../', 'CREED'], [null, 'Handbook']],
            current: null,
            main: $body,
            source: 'handbook/README.md',
            hash: substr(hash('sha256', $this->read($this->root . '/handbook/README.md')), 0, 7),
            pageClass: 'hb-page--door',
            twin: 'index.md',
        );
    }

    private function chapterPage(Chapter $c, ?Chapter $prev, ?Chapter $next): string
    {
        $toc = '';
        foreach ($c->headings as $h) {
            if ($h['level'] < 2) {
                continue;
            }
            $toc .= '            <li class="hb-toc__l' . $h['level'] . '"><a href="#' . self::e($h['id']) . '" data-toc="' . self::e($h['id']) . '">'
                . '<span class="hb-toc__no">' . $h['number'] . '</span> ' . self::e($h['title']) . "</a></li>\n";
        }
        $tocBlock = $toc === '' ? '' : <<<HTML
                  <nav class="hb-toc" aria-label="On this page">
                    <p class="hb-toc__title">On this page</p>
                    <ol>
            {$toc}        </ol>
                  </nav>

            HTML;

        $rfc = $c->rfc ? $this->rfcKey() : '';
        $policy = $this->policyBox($c);
        $pager = $this->pager($prev, $next);
        $stamp = $this->statusStamp($c->status);
        $register = $this->register($c);

        $body = <<<HTML
              <article class="hb-chapter" aria-labelledby="page-title">
                <p class="folio-head" aria-hidden="true"><span>CREED 2026 · Handbook</span><span>Chapter {$c->number} · {$this->e($c->title)}</span></p>
                <header class="chap-head chap-head--chapter hb-chapter__head">
                  <p class="chap-head__no" aria-hidden="true">{$c->number}</p>
                  <div>
                    <p class="chap-head__kicker">Chapter {$c->number}</p>
                    <h1 id="page-title">{$this->e($c->title)}</h1>
                    <p class="hb-register">{$register}</p>
                  </div>
                  {$stamp}
                </header>
                <div class="hb-body">
        {$tocBlock}          <div class="hb-prose" data-hb-prose>
        {$rfc}{$c->html}{$policy}          </div>
                </div>
              </article>
        {$pager}
        HTML;

        return $this->layout(
            title: 'CREED Handbook: ' . $c->number . ' ' . $c->title,
            description: 'Chapter ' . $c->number . ' of the CREED handbook, the written rules for the Wayne State app stack: ' . $c->title . '.',
            crumbs: [['../', 'CREED'], ['./', 'Handbook'], [null, 'Chapter ' . $c->number . ': ' . $c->title]],
            current: $c,
            main: $body,
            source: 'handbook/' . $c->file,
            hash: $c->hash,
            pageClass: 'hb-page--chapter',
            twin: $c->slug . '.md',
        );
    }

    private function printPage(): string
    {
        $contents = '';
        $chapters = '';
        foreach ($this->bookChapters as $c) {
            $contents .= '          <li><a href="#' . $c->slug . '"><span class="toc__no">' . $c->number . '</span> ' . self::e($c->title) . "</a></li>\n";
            $rfc = $c->rfc ? $this->rfcKey() : '';
            $chapters .= <<<HTML
                  <article class="hb-chapter hb-chapter--book" id="{$c->slug}" aria-labelledby="{$c->slug}-title">
                    <header class="chap-head chap-head--chapter hb-chapter__head">
                      <p class="chap-head__no" aria-hidden="true">{$c->number}</p>
                      <div>
                        <p class="chap-head__kicker">Chapter {$c->number}</p>
                        <h2 id="{$c->slug}-title" class="hb-book__title">{$this->e($c->title)}</h2>
                        <p class="hb-register">{$this->register($c)}</p>
                      </div>
                      {$this->statusStamp($c->status)}
                    </header>
                    <div class="hb-prose hb-prose--book">
            {$rfc}{$this->demote($c->html)}{$this->policyBox($c)}          </div>
                  </article>

            HTML;
        }

        $body = <<<HTML
              <header class="chap-head hb-door__head">
                <p class="chap-head__kicker">The handbook · Print edition</p>
                <h1 id="page-title">{$this->e($this->readmeTitle)}, whole</h1>
                <p class="chap-head__lede">Every chapter on one page, for printing and for your browser's find. Section numbers match the chapter pages.</p>
              </header>
              <nav class="hb-book__contents" aria-labelledby="contents">
                <h2 id="contents" class="hb-door__title">Contents</h2>
                <ol>
        {$contents}        </ol>
              </nav>
        {$chapters}
        HTML;

        return $this->layout(
            title: 'CREED Handbook: the whole book on one page',
            description: 'Every chapter of the CREED handbook on one page, for printing.',
            crumbs: [['../', 'CREED'], ['./', 'Handbook'], [null, 'Print edition']],
            current: null,
            main: $body,
            source: 'handbook/',
            hash: substr(hash('sha256', implode('', array_map(static fn(Chapter $c): string => $c->hash, $this->chapters))), 0, 7),
            pageClass: 'hb-page--book',
        );
    }

    /** On the print page each chapter title is an h2, so its sections step down a level. */
    private function demote(string $html): string
    {
        return (string) preg_replace_callback(
            '#<(/?)h([2-5])\b#',
            static fn(array $m): string => '<' . $m[1] . 'h' . ((int) $m[2] + 1),
            $html,
        );
    }

    private function searchIndex(): string
    {
        $docs = [];
        foreach ($this->chapters as $c) {
            foreach ($c->sections as $s) {
                $docs[] = json_encode([
                    'id' => $c->slug . ($s['id'] === '' ? '' : '#' . $s['id']),
                    'url' => $c->href() . ($s['id'] === '' ? '' : '#' . $s['id']),
                    'chapter' => $c->number,
                    'chapterTitle' => $c->title,
                    'number' => $s['number'],
                    'title' => $s['title'],
                    'text' => $s['text'],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
        }

        return "/* Generated by bin/build-handbook-site.php from handbook/*.md. Do not edit: run composer site:handbook. */\n"
            . "window.CREED_HANDBOOK_INDEX = [\n" . implode(",\n", $docs) . "\n];\n";
    }

    // ------------------------------------------------------------- pieces

    /**
     * @param list<array{0: string|null, 1: string}> $crumbs
     */
    private function layout(
        string $title,
        string $description,
        array $crumbs,
        ?Chapter $current,
        string $main,
        string $source,
        string $hash,
        string $pageClass,
        ?string $twin = null,
    ): string {
        $nav = $this->sidebar($current);
        // The page's Markdown twin, written beside it by bin/agent-ready.php (ADR-0019). The print
        // edition has none: it repeats the chapters, and agents get those one by one.
        $twinLink = $twin === null ? '' : "\n" . '<link rel="alternate" type="text/markdown" href="' . self::e($twin) . '">';
        $crumbHtml = '';
        foreach ($crumbs as [$href, $label]) {
            $crumbHtml .= $href === null
                ? '<li><span aria-current="page">' . self::e($label) . '</span></li>'
                : '<li><a href="' . $href . '">' . self::e($label) . '</a></li>';
        }
        $sourceUrl = ChapterParser::GITHUB . $source;
        if (str_ends_with($source, '/')) {
            $sourceUrl = 'https://github.com/waynestate/creed/tree/main/' . $source;
        }
        $e = self::e(...);

        return <<<HTML
            <!doctype html>
            <!-- Generated by bin/build-handbook-site.php from {$e($source)}. Do not edit: run composer site:handbook. -->
            <html lang="en" data-theme="wsu">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="stack-env" content="auto">
            <script src="../assets/stack-env.js" defer></script>
            <title>{$e($title)}</title>
            <meta name="description" content="{$e($description)}">
            <meta name="theme-color" content="#0c5449">{$twinLink}
            <link rel="icon" href="../brand/favicon.ico" sizes="any">
            <link rel="icon" type="image/png" sizes="32x32" href="../brand/favicon-32.png">
            <link rel="stylesheet" href="../assets/wayne-ui/wayne-ui.css">
            <link rel="stylesheet" href="../assets/creed.css">
            <link rel="stylesheet" href="../assets/handbook.css">
            <script>
              /* Script is here, so the drawer and the search can be offered from the first paint.
                 Motion only for people who are happy with it; everyone else gets the book already printed. */
              document.documentElement.classList.add("js");
              if (window.matchMedia && window.matchMedia("(prefers-reduced-motion: no-preference)").matches) {
                document.documentElement.classList.add("motion");
              }
            </script>
            </head>
            <body class="hb-page {$pageClass}" id="top">
            <a class="wsu-skip-link" href="#main">Skip to content</a>
            <div class="binding" aria-hidden="true"></div>

            <header class="runhead hb-runhead">
              <div class="wsu-container runhead__inner">
                <a class="runhead__brand" href="../" aria-label="CREED front page">
                  <img src="../brand/creed-avatar-256.png" width="28" height="28" alt="">
                  <span>CREED</span>
                </a>
                <a class="runhead__edition hb-runhead__book" href="./">The Handbook</a>
                <button class="hb-menu" type="button" aria-expanded="false" aria-controls="hb-nav" data-hb-menu hidden>Chapters</button>
                <form class="hb-search" role="search" data-hb-search hidden>
                  <label class="wsu-sr-only" for="hb-q">Search the handbook</label>
                  <input id="hb-q" type="search" placeholder="Search the handbook" autocomplete="off" spellcheck="false" aria-controls="hb-results" aria-describedby="hb-q-hint" data-hb-q>
                  <kbd class="hb-search__key" aria-hidden="true">/</kbd>
                  <span class="wsu-sr-only" id="hb-q-hint">Results appear below as you type. Press the down arrow to move into them.</span>
                  <div class="hb-results" id="hb-results" data-hb-results hidden>
                    <p class="hb-results__status" role="status" data-hb-status></p>
                    <ol class="hb-results__list" data-hb-list></ol>
                  </div>
                </form>
                <nav class="runhead__nav hb-runhead__nav" aria-label="Site">
                  <a href="../">Front page</a>
                  <a href="./" aria-current="{$this->ariaCurrent($pageClass === 'hb-page--door')}">Handbook</a>
                </nav>
              </div>
            </header>

            <div class="wsu-container hb-layout">
            {$nav}
            <main id="main" class="hb-main" tabindex="-1">
              <nav class="hb-crumbs" aria-label="Breadcrumb"><ol>{$crumbHtml}</ol></nav>
            {$main}
              <p class="hb-source">Set from <a href="{$e($sourceUrl)}"><code>{$e($source)}</code> on GitHub</a>, impression <code>{$hash}</code>. Found a mistake? Fix the Markdown; this page is rebuilt from it.</p>
            </main>
            </div>

            <footer class="colophon">
              <div class="wsu-container colophon__inner">
                <div class="colophon__mark"><img src="../brand/creed-avatar-256.png" width="48" height="48" alt=""></div>
                <div>
                  <p>CREED, the Wayne State App Stack Code. <a class="hb-colophon__link" href="../">Front page</a> · <a class="hb-colophon__link" href="./">Handbook</a> · <a class="hb-colophon__link" href="https://github.com/waynestate/creed">Source</a></p>
                  <p><a class="hb-top" href="#top" data-hb-top>Back to top</a></p>
                  <p class="colophon__fine">Printed from the Markdown in <code>handbook/</code> by <code>composer site:handbook</code>; CI refuses a page that has fallen behind its source. Nobody hand-copies a rule here, which is more than Creed Bratton can say about his résumé.</p>
                </div>
              </div>
            </footer>

            <script src="../assets/vendor/minisearch/minisearch-7.2.0.js" defer></script>
            <script src="search-index.js" defer></script>
            <script src="../assets/handbook.js" defer></script>
            </body>
            </html>

            HTML;
    }

    private function ariaCurrent(bool $here): string
    {
        return $here ? 'page' : 'false';
    }

    private function sidebar(?Chapter $current): string
    {
        $items = '';
        foreach ($this->chapters as $c) {
            $here = $current !== null && $current->slug === $c->slug;
            $sub = '';
            if ($here) {
                foreach ($c->headings as $h) {
                    if ($h['level'] === 2) {
                        $sub .= '<li><a href="#' . self::e($h['id']) . '" data-toc="' . self::e($h['id']) . '">' . self::e($h['title']) . '</a></li>';
                    }
                }
                $sub = $sub === '' ? '' : '<ol class="hb-nav__sub">' . $sub . '</ol>';
            }
            $items .= '      <li' . ($here ? ' class="is-here"' : '') . '><a href="' . $c->href() . '"' . ($here ? ' aria-current="page"' : '') . '>'
                . '<span class="hb-nav__no">' . $c->number . '</span><span class="hb-nav__name">' . self::e($c->title) . '</span>'
                . ($c->status === 'tbd' ? '<span class="hb-nav__tbd" title="To be decided"><span class="wsu-sr-only">, to be decided</span></span>' : '')
                . '</a>' . $sub . "</li>\n";
        }

        return <<<HTML
            <nav class="hb-nav" id="hb-nav" aria-label="Handbook chapters" data-hb-nav>
              <p class="hb-nav__title"><a href="./">The Handbook</a></p>
              <ol class="hb-nav__list">
            {$items}  </ol>
              <p class="hb-nav__more"><a href="print.html">Whole book on one page</a></p>
            </nav>
            HTML;
    }

    private function pager(?Chapter $prev, ?Chapter $next): string
    {
        $out = '';
        if ($prev !== null) {
            $out .= '<a class="hb-pager__link hb-pager__link--prev" href="' . $prev->href() . '" rel="prev"><span class="hb-pager__dir">Previous · Chapter ' . $prev->number . '</span><span class="hb-pager__name">' . self::e($prev->title) . '</span></a>';
        } else {
            $out .= '<a class="hb-pager__link hb-pager__link--prev" href="./"><span class="hb-pager__dir">Back to</span><span class="hb-pager__name">Contents</span></a>';
        }
        if ($next !== null) {
            $out .= '<a class="hb-pager__link hb-pager__link--next" href="' . $next->href() . '" rel="next"><span class="hb-pager__dir">Next · Chapter ' . $next->number . '</span><span class="hb-pager__name">' . self::e($next->title) . '</span></a>';
        } else {
            $out .= '<a class="hb-pager__link hb-pager__link--next" href="print.html"><span class="hb-pager__dir">End of the book</span><span class="hb-pager__name">The whole book on one page</span></a>';
        }

        return '      <nav class="hb-pager" aria-label="Previous and next chapters">' . $out . "</nav>\n";
    }

    private function rfcKey(): string
    {
        return '<div class="hb-rfc" role="note"><p><span class="hb-rfc__label">Key words</span> '
            . 'The key words <strong class="kw kw--must">MUST</strong>, <strong class="kw kw--must-not">MUST NOT</strong>, '
            . '<strong class="kw kw--should">SHOULD</strong>, <strong class="kw kw--should-not">SHOULD NOT</strong> and '
            . '<strong class="kw kw--may">MAY</strong> in this chapter are to be interpreted as described in '
            . '<a href="https://www.rfc-editor.org/rfc/rfc2119">RFC 2119</a>.</p></div>' . "\n";
    }

    private function policyBox(Chapter $c): string
    {
        if ($c->policyKeys === []) {
            return '';
        }
        $rows = '';
        foreach ($c->policyKeys as $key) {
            $path = $this->policyKeys[$key] ?? $key;
            $value = $this->valueAt($path);
            $rows .= '<div><dt><code>' . self::e($path) . '</code></dt><dd>' . $value . '</dd></div>';
        }
        // A note, not an aside: on the print page every chapter has one, and landmarks must be unique.

        return '<div class="hb-policy" role="note"><p class="hb-policy__title">'
            . 'In <a href="' . ChapterParser::GITHUB . 'policy/policy.json"><code>policy.json</code></a></p><dl>' . $rows . "</dl></div>\n";
    }

    private function valueAt(string $path): string
    {
        if (str_contains($path, '*')) {
            return 'set per version';
        }
        $node = $this->policy;
        foreach (explode('.', $path) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return 'not set';
            }
            $node = $node[$part];
        }

        return '<code>' . self::e(json_encode($node, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) . '</code>';
    }

    private function register(Chapter $c): string
    {
        $parts = [];
        $sections = count(array_filter($c->headings, static fn(array $h): bool => $h['level'] === 2));
        if ($sections > 0) {
            $parts[] = $sections . ' ' . ($sections === 1 ? 'section' : 'sections');
        }
        if ($c->items > 0) {
            $parts[] = $c->items . ' ' . ($c->items === 1 ? 'provision' : 'provisions');
        }
        foreach ($c->keywords as $keyword => $n) {
            $parts[] = '<strong class="kw kw--' . strtolower(str_replace(' ', '-', $keyword)) . '">' . $keyword . '</strong> ' . $n;
        }
        if ($parts === []) {
            $parts[] = 'No provisions yet';
        }

        return implode(' · ', $parts);
    }

    private function statusStamp(string $status): string
    {
        return match ($status) {
            'tbd' => '<span class="stamp stamp--notyet hb-status">To be decided</span>',
            'partly' => '<span class="stamp stamp--checked hb-status">Partly decided</span>',
            default => '<span class="stamp stamp--enforced hb-status">Decided</span>',
        };
    }

    private function read(string $path): string
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Cannot read ' . $path);
        }

        return str_replace("\r\n", "\n", $content);
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('policy.json is not an object');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    private static function e(string $text): string
    {
        return ChapterParser::e($text);
    }
}
