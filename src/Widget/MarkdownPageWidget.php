<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Editor\Document;
use Porta\Help\Fence\FenceRenderQueue;
use Porta\Help\Fence\FenceSession;
use Porta\Help\Fence\MarkdownFenceRegistry;
use Porta\Help\Layout\PageWords;
use Porta\Help\LinkSpan;
use Porta\Help\MarkdownRenderer;
use Porta\Help\RenderedDoc;
use Porta\Highlight\CodeHighlighterFactory;
use Porta\I18n\Translator;
use Porta\Search\FindTarget;
use Porta\Support\AnsiText;
use Porta\Support\Files;
use Porta\Support\IntMath;
use Porta\Support\RevoltScheduler;
use Porta\Support\Scheduler;
use Porta\Support\Text;
use Porta\Ui\ClickZones;
use Porta\Ui\Glyphs;
use Porta\Ui\Graphics\TerminalGraphics;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\Rule;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * A markdown page as the documentation reads: rendered by
 * {@see MarkdownRenderer} (re-rendered per pane width) under a navigation bar
 * — ‹ › through the pages visited, then a breadcrumb of the page's place in
 * its tree — with Tab/Shift+Tab cycling the page's links, Enter/click
 * following them, a back stack (Backspace) and the in-page find bar. The help
 * browser and the markdown preview are its two pages, and differ only in
 * which tree they walk and what showing a new text means.
 *
 * Where a link LEADS is the handler's business ({@see HelpViewerHandler}):
 * another help page in place, or another file's tab for the preview. Tab
 * management keys are re-bound here and routed back to the IDE, because the
 * code editor is detached while a page owns the slot.
 */
abstract class MarkdownPageWidget extends EditorPaneWidget implements FindTarget
{
    /** The clickable navigation header occupies the first rendered row. */
    /** The navigation bar, and the rule that parts it from the page — the line under the app menu, drawn the same way. */
    private const int HEADER_ROWS = 2;

    protected ?string $path = null;
    protected string $markdown = '';
    /** The page rendered for $renderedWidth (dropped on resize/theme change). */
    private ?RenderedDoc $doc = null;
    private int $renderedWidth = 0;
    private int $scrollTop = 0;
    /** Bumped whenever the searchable lines change (new page / re-wrap). */
    #[\Override]
    public private(set) int $findVersion = 0;
    /** @var array<int, list<array{start: int, end: int, current: bool}>> find-bar matches by line (byte cols into the stripped line) */
    private array $searchMatches = [];
    /** The Tab-focused link group, -1 = none. */
    private int $currentLink = -1;
    /** @var list<array{path: string, scrollTop: int, currentLink: int}> */
    private array $history = [];
    /** @var list<array{path: string, scrollTop: int, currentLink: int}> pages backed out of, re-entered via the › arrow */
    private array $forward = [];
    /** Header click zones (action names), rebuilt per render. */
    private readonly ClickZones $headerZones;
    private int $lastRows = 24;
    /** The columns the last render's margin took, so a click finds the link it drew. */
    private int $marginCols = 0;
    /** A source line to bring into view at the next render, where the width — and so the wrap — is known. */
    private ?int $followLine = null;
    /** The tree the page lives in: the breadcrumb mirrors paths under it, and a link must stay inside it. */
    private ?string $docsRoot = null;
    /** @var (\Closure(): array<string, string>)|null more trees a link may lead into — dir => name — beside the docs root: the plugins' own docs */
    private ?\Closure $moreRoots = null;
    /** @var array{0: string, 1: ?string}|null the tree the current page sits in — its dir, and a name when it is not the docs root — found when the page is taken, so a render never globs */
    private ?array $tree = null;
    /** An anchor to bring to the top once the page it is on is laid out: a link to `page.md#section`. */
    private ?string $pendingAnchor = null;
    /** @var array<int, true> the `<details>` the reader opened, by their number in source order — typing above one keeps it open */
    private array $openDetails = [];
    private readonly MarkdownRenderer $renderer;
    private readonly ?FenceSession $fences;
    private ?string $fenceTimer = null;
    private int $fenceWidth = 0;
    private bool $fencesVisible = false;

    public function __construct(
        ?Styles $styles = null,
        ?CodeHighlighterFactory $highlighters = null,
        ?Translator $translator = null,
        ?TerminalGraphics $graphics = null,
        ?MarkdownFenceRegistry $fences = null,
        ?FenceRenderQueue $fenceQueue = null,
        private readonly Scheduler $scheduler = new RevoltScheduler(),
    ) {
        $this->initStyles($styles);
        $words = null === $translator ? null : new PageWords(static fn (string $name): string => self::pageWord($translator, $name));
        $this->fences =
            null !== $fences && null !== $fenceQueue
                ? new FenceSession($fences, $fenceQueue, function (): void {
                    $this->relayout();
                    $this->scheduleFences();
                }) : null;
        $this->renderer = new MarkdownRenderer($this->styles, $highlighters?->forFragments(), $words, $graphics, $this->fences);
        $this->headerZones = new ClickZones();
        // The terminal's answer arrives after the first frame: images that
        // were text until then are pixels now.
        $graphics?->onChange($this->relayout(...));
    }

    /**
     * A word the page draws itself — an alert's title (`> [!NOTE]` is
     * "Note"), a summary-less `<details>` — asked at each layout so a
     * language switch reaches it.
     */
    private static function pageWord(Translator $translator, string $name): string
    {
        return match ($name) {
            'TIP' => $translator->trans('Tip'),
            'IMPORTANT' => $translator->trans('Important'),
            'WARNING' => $translator->trans('Warning'),
            'CAUTION' => $translator->trans('Caution'),
            'DETAILS' => $translator->trans('Details'),
            'DIAGRAM_PENDING' => $translator->trans('Rendering diagram…'),
            'DIAGRAM_IMAGE_ERROR' => $translator->trans('Cannot display diagram PNG'),
            default => $translator->trans('Note'),
        };
    }

    /** Lay the page out again at the next render, the text unchanged. */
    private function relayout(): void
    {
        $this->doc = null;
        $this->invalidate();
        $this->getContext()?->requestRender();
    }

    public function setDocsRoot(string $dir): void
    {
        $this->docsRoot = Files::canonical($dir);
        $this->tree = null === $this->path ? null : $this->treeOf($this->path);
    }

    private function scheduleFences(): void
    {
        if (!$this->fencesVisible || null === $this->fences || null === $this->path || $this->fenceWidth < 1 || null !== $this->fenceTimer) {
            return;
        }
        $this->fenceTimer = $this->scheduler->delay(0.0, function (): void {
            $this->fenceTimer = null;
            $this->prepareFences();
        });
    }

    private function prepareFences(): void
    {
        if ($this->fencesVisible && null !== $this->path && $this->fenceWidth > 0) {
            $this->renderer->prepareFences($this->markdown, $this->path, $this->fenceWidth);
            $this->relayout();
        }
    }

    protected function resumeFences(): void
    {
        if ($this->fencesVisible) {
            return;
        }
        $this->fencesVisible = true;
        $this->scheduleFences();
    }

    #[\Override]
    public function hide(): void
    {
        $this->fencesVisible = false;
        if (null !== $this->fenceTimer) {
            $this->scheduler->cancel($this->fenceTimer);
            $this->fenceTimer = null;
        }
        $this->fences?->clear();
        $this->renderer->clearImages();
        $this->relayout();
    }

    /**
     * Trees beside the docs root that a link may lead into, each under its
     * own name: a plugin's documentation lives in the plugin, and Porta's
     * pages link to it there. Asked again on every navigation, so a plugin
     * installed while the IDE runs is reachable without a restart.
     *
     * @param \Closure(): array<string, string> $roots dir => name
     */
    public function setMoreRoots(\Closure $roots): void
    {
        $this->moreRoots = $roots;
        $this->tree = null === $this->path ? null : $this->treeOf($this->path);
    }

    /**
     * Take $markdown as $path's page. $fromTop starts the reader at the top
     * with no link focused; otherwise the place is kept, and clamped at the
     * next render — a new text for the page already shown.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the one difference between a new page and a new text for the same page.
    protected function adopt(string $path, string $markdown, bool $fromTop): void
    {
        $canonical = Files::canonical($path);
        if ($canonical !== $this->path) {
            $this->renderer->clearImages();
        }
        $this->path = $canonical;
        $this->fencesVisible = true;
        $this->tree = $this->treeOf($this->path);
        $this->markdown = $markdown;
        $this->prepareFences();
        $this->doc = null;
        if ($fromTop) {
            $this->scrollTop = 0;
            $this->currentLink = -1;
            $this->openDetails = [];
        }
        ++$this->findVersion;
        $this->invalidate();
    }

    /** Drop everything, including the back/forward history. */
    #[\Override]
    public function clear(): void
    {
        $this->hide();
        $this->path = null;
        $this->markdown = '';
        $this->doc = null;
        $this->scrollTop = 0;
        $this->currentLink = -1;
        $this->history = [];
        $this->forward = [];
        ++$this->findVersion;
        $this->invalidate();
    }

    /**
     * The handler, narrowed to a page's own verbs.
     *
     * The pane contract carries the four tab verbs every pane needs
     * ({@see ViewerTabHandler}); this pane's link-following, find and label
     * lookups are extra, declared by {@see HelpViewerHandler} which extends it.
     * One object arrives through `setHandler()` and this is where the wider
     * half is asked for.
     */
    protected function docs(): ?HelpViewerHandler
    {
        return $this->handler instanceof HelpViewerHandler ? $this->handler : null;
    }

    /** The shown page's absolute path, or null when the viewer is empty. */
    public function path(): ?string
    {
        return $this->path;
    }

    /** Rendered lines bake palette codes: drop the memo on a theme switch. */
    #[\Override]
    public function refreshTheme(): void
    {
        $this->renderer->refreshTheme();
        $this->doc = null;
        $this->invalidate();
    }

    /** The wheel scrolls the page, in whole rows — this pane has no zoom to anchor. */
    #[\Override]
    public function wheelBy(int $rows): void
    {
        $this->scrollBy($rows);
    }

    public function scrollBy(int $rows): void
    {
        $this->scrollTop = $this->clampScroll($this->scrollTop + $rows);
        $this->invalidate();
    }

    /** A press follows what is under it — a header zone or a link — and arms no drag. */
    #[\Override]
    public function press(int $relRow, int $relCol): bool
    {
        $this->clickAt($relRow, $relCol);

        return false;
    }

    /** A click: a header zone (arrows/crumbs), a link under the cell, or a miss. */
    public function clickAt(int $relRow, int $relCol): bool
    {
        if (0 === $relRow) {
            $action = $this->headerZones->at($relCol);
            if (null !== $action) {
                match ($action) {
                    'back' => $this->back(),
                    'forward' => $this->forward(),
                    'top' => $this->scrollBy(-\PHP_INT_MAX),
                    default => $this->follow(\substr($action, offset: 4)),
                };
            }

            return null !== $action;
        }

        return $this->clickBody($relRow, $relCol);
    }

    /** A click below the header: a link under the cell, else the source point drawn there. */
    private function clickBody(int $relRow, int $relCol): bool
    {
        $doc = $this->doc;
        if (null === $doc) {
            return false;
        }
        $line = $this->scrollTop + $relRow - self::HEADER_ROWS;
        $relCol -= $this->marginCols;
        $span = \array_find(
            $doc->links,
            static fn (LinkSpan $span): bool => $span->line === $line && $relCol >= $span->col && $relCol < $span->endCol,
        );
        if (null !== $span) {
            $this->currentLink = $span->group;
            $this->activate($doc, $span->group);
        }
        $point = null === $span ? $doc->sourcePointAt($line, $relCol) : null;
        if (null !== $point) {
            $this->pointAtSource(...$point);
        }

        return null !== $span;
    }

    /**
     * A click on the page's text, away from any link: the source line and
     * byte column of what was drawn under the pointer — exact inside text
     * drawn as written, the start of its block elsewhere. A page with a
     * source to show — the preview — takes the reader there; the help
     * browser's pages have none, and ignore it.
     */
    protected function pointAtSource(int $sourceLine, int $byteCol): void {}

    /**
     * A task's box was followed: $start to $end in the source holds its
     * `[ ]` or `[x]`. A page whose text can be edited — the preview — ticks
     * it there; the help browser's pages are read-only, and ignore it.
     */
    protected function toggleTask(int $start, int $end): void {}

    /** @return list<string> */
    #[\Override]
    public function render(RenderContext $context): array
    {
        $cols = $context->getColumns();
        $rows = $context->getRows();
        if ($cols < 1 || $rows < 1) {
            return [];
        }
        $this->lastRows = $rows;

        $margin = $this->margin($cols);
        $this->marginCols = \strlen($margin);
        $width = $cols - $this->marginCols;
        $fenceWidth = \max(1, \max(20, $width) - 4);
        if ($fenceWidth !== $this->fenceWidth) {
            $this->fenceWidth = $fenceWidth;
            $this->scheduleFences();
        }
        if (null === $this->doc || $width !== $this->renderedWidth) {
            // A resize rewraps the page, so the same scroll offset would show
            // other text: keep the source line at the top instead. A new text
            // (no page to measure against) keeps the offset as it was.
            $anchor = $this->doc?->origins[$this->scrollTop] ?? null;
            $doc = $this->renderer->render($this->markdown, $width, $this->openDetails, null === $this->path ? null : \dirname($this->path), $this->tree[0] ?? null);
            $this->doc = $doc;
            $this->renderedWidth = $width;
            if (null !== $anchor) {
                // The same text, so the anchor's block is in the new page too.
                $this->scrollTop = (int) \array_key_first(\array_filter($doc->origins, static fn (int $origin): bool => $origin >= $anchor));
            }
            $this->scrollTop = $this->clampScroll($this->scrollTop);
            $this->currentLink = \min($this->currentLink, $doc->groupCount() - 1);
            ++$this->findVersion; // re-wrapped: match offsets are stale
        }
        if (null !== $this->pendingAnchor) {
            $target = $this->doc?->anchor($this->pendingAnchor);
            $this->scrollTop = null === $target ? $this->scrollTop : $this->clampScroll($target);
            $this->pendingAnchor = null;
        }
        if (null !== $this->followLine) {
            $this->scrollTop = $this->clampScroll($this->scrollToShow($this->doc->origins ?? [], $this->followLine));
            $this->followLine = null;
        }

        // A one-row pane keeps its navigation bar and gives up the row under
        // it: the bar's lower half, or a rule where the theme has no shade.
        $lines = \array_slice([$this->headerLine($cols), Rule::under($cols, $this->styles, Role::HeaderBar)], offset: 0, length: $rows);
        for ($row = 0; $row < ($rows - self::HEADER_ROWS); ++$row) {
            $lineNo = $this->scrollTop + $row;
            $line = $this->searchHighlight($this->highlight($this->doc->lines[$lineNo] ?? '', $lineNo), $lineNo);
            // Truncate as well as pad. padRight() is a bound with no ceiling:
            // the markdown renderer floors its own wrap width, so in a pane
            // narrower than that floor it hands back lines longer than $cols
            // and this padded them to no effect. A row wider than its pane
            // throws RenderException out of the render loop and takes the
            // session with it — the same crash the editor's gutter caused.
            $lines[] = AnsiText::truncate($margin.$line, $cols, pad: true);
        }

        return $lines;
    }

    /**
     * The navigation header: ‹ › arrows (dim while their stack is empty) and
     * a breadcrumb mirroring the docs file hierarchy — root › folders › page.
     * The root and any folder with a README.md index navigate; the current
     * page scrolls to the top. Click zones are padded a cell beyond each
     * glyph/label so near-misses still land, and are rebuilt per render;
     * overlong trails drop the left-most crumbs behind a leading ellipsis so
     * the current page stays visible.
     */
    private function headerLine(int $cols): string
    {
        $this->headerZones->clear();
        $crumbs = $this->hierarchyCrumbs();
        $total = \count($crumbs);
        while (\count($crumbs) > 1 && (7 + \array_sum(\array_map(static fn (array $c): int => AnsiUtils::visibleWidth($c['label']) + 3, $crumbs))) > $cols) {
            \array_shift($crumbs); // keep the tail: the current page must stay visible
        }

        $hasColor = $this->styles->hasColor();
        $bold = $hasColor ? Sgr::BOLD : '';
        $unbold = $hasColor ? Sgr::NORMAL_INTENSITY : '';
        $text = ' ';
        $col = 1;
        foreach ([['back', Glyphs::NAV_BACK, [] !== $this->history], ['forward', Glyphs::NAV_FORWARD, [] !== $this->forward]] as [$action, $glyph, $active]) {
            if ($active) {
                // A one-cell glyph is a frustrating click target: pad the zone
                // a cell to each side (earlier zones win where they touch).
                $this->headerZones->add($action, $col - 1, $col + 2);
            }
            $text .= $this->styles->of($active ? Role::TabAccent : Role::Dim)->apply($glyph).' ';
            $col += 2;
        }
        $text .= ' ';
        ++$col;
        $dropped = \count($crumbs) < $total;
        if ($dropped) {
            $text .= $this->styles->of(Role::Dim)->apply('… › ');
            $col += 4;
        }
        foreach ($crumbs as $i => $crumb) {
            $width = AnsiUtils::visibleWidth($crumb['label']);
            $isLast = $i === (\count($crumbs) - 1);
            if ('' !== $crumb['action']) {
                $this->headerZones->add($crumb['action'], $col - 1, $col + $width + 1);
                $text .= $this->styles->of(Role::TabAccent)->apply($crumb['label']);
            }
            if ('' === $crumb['action'] && $isLast) {
                // The current page: clicking it scrolls back to the top rather
                // than doing nothing (the only crumb on the root page).
                $this->headerZones->add('top', $col - 1, $col + $width + 1);
                $text .= $bold.$crumb['label'].$unbold;
            }
            if ('' === $crumb['action'] && !$isLast) {
                // A folder with no README.md index: part of the path, not a page.
                $text .= $this->styles->of(Role::Dim)->apply($crumb['label']);
            }
            $col += $width;
            if ($i < (\count($crumbs) - 1)) {
                $text .= $this->styles->of(Role::Dim)->apply(' › ');
                $col += 3;
            }
        }

        $line = $this->styles->of(Role::HeaderBar)->getAnsiRestore().AnsiText::padRight(AnsiText::truncate($text, $cols), $cols);

        return $hasColor ? $line.Sgr::RESET : AnsiUtils::stripAnsiCodes($line);
    }

    /** A page's crumb label: the host's naming (e.g. the docs root as "Documentation"), else the file name. */
    private function crumbLabel(string $path): string
    {
        return $this->docs()?->labelFor($path) ?? \basename($path, suffix: '.md');
    }

    /**
     * The current page's place in the docs file tree: the root README first,
     * one crumb per folder on the way down (clickable when the folder has a
     * README.md index — a folder's own index page wears the folder's name),
     * the current page last.
     *
     * @return list<array{action: string, label: string}>
     */
    private function hierarchyCrumbs(): array
    {
        $path = (string) $this->path;
        $label = $this->crumbLabel($path);
        $root = $this->docsRoot;
        $rootReadme = null !== $root ? $root.'/README.md' : null;
        $crumbs = [];
        if (null !== $root && null !== $rootReadme && $rootReadme !== $path) {
            $crumbs[] = ['action' => \is_file($rootReadme) ? 'nav:'.$rootReadme : '', 'label' => $this->crumbLabel($rootReadme)];
            if (null !== $this->tree) {
                [$base, $name] = $this->tree;
                // A named tree (a plugin's docs) hangs off the root as one
                // folder crumb wearing the plugin's name.
                $folders = null === $name ? [] : [[$name, $base]];
                $prefix = $base;
                foreach (\explode('/', \substr(\dirname($path), \strlen($base))) as $dir) {
                    if ('' === $dir) {
                        continue; // the slash right after the root
                    }
                    $prefix .= '/'.$dir;
                    $folders[] = [$dir, $prefix];
                }
                if ('README.md' === \basename($path) && [] !== $folders) {
                    [$label] = \array_pop($folders);
                }
                foreach ($folders as [$folderLabel, $folder]) {
                    $index = $folder.'/README.md';
                    $crumbs[] = ['action' => \is_file($index) ? 'nav:'.$index : '', 'label' => $folderLabel];
                }
            }
        }
        $crumbs[] = ['action' => '', 'label' => $label];

        return $crumbs;
    }

    /**
     * The tree $path sits in: the docs root (no name), else one of the
     * {@see setMoreRoots()} trees under its name, else null — a page outside
     * every tree, which no link may lead to.
     *
     * @return array{0: string, 1: ?string}|null
     */
    private function treeOf(string $path): ?array
    {
        $root = $this->docsRoot;
        if (null !== $root && \str_starts_with($path, $root.'/')) {
            return [$root, null];
        }
        $tree = null;
        foreach (null === $this->moreRoots ? [] : ($this->moreRoots)() as $dir => $name) {
            $dir = Files::canonical($dir);
            if (\str_starts_with($path, $dir.'/')) {
                $tree = [$dir, $name];
                break;
            }
        }

        return $tree;
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();
        match (true) {
            $kb->matches($data, 'help_next_link') => $this->cycleLink(1),
            $kb->matches($data, 'help_prev_link') => $this->cycleLink(-1),
            $kb->matches($data, 'help_follow_link') => $this->followCurrent(),
            $kb->matches($data, 'help_back') => $this->back(),
            $kb->matches($data, 'help_scroll_up') => $this->scrollBy(-1),
            $kb->matches($data, 'help_scroll_down') => $this->scrollBy(1),
            $kb->matches($data, 'help_page_up') => $this->scrollBy(-$this->bodyRows()),
            $kb->matches($data, 'help_page_down') => $this->scrollBy($this->bodyRows()),
            $kb->matches($data, 'help_home') => $this->scrollBy(-\PHP_INT_MAX),
            $kb->matches($data, 'help_end') => $this->scrollBy(\PHP_INT_MAX),
            $kb->matches($data, 'help_find') => $this->docs()?->openFind(),
            default => $this->tabKey($data),
        };
    }

    /** @return array<string, string[]> */
    #[\Override]
    protected static function getDefaultKeybindings(): array
    {
        return [
            'help_next_link' => [Key::TAB],
            'help_prev_link' => ['shift+tab'],
            'help_follow_link' => [Key::ENTER],
            'help_back' => [Key::BACKSPACE, 'ctrl+alt+left'],
            'help_scroll_up' => [Key::UP],
            'help_scroll_down' => [Key::DOWN],
            'help_page_up' => [Key::PAGE_UP],
            'help_page_down' => [Key::PAGE_DOWN],
            'help_home' => [Key::HOME],
            'help_end' => [Key::END],
            'help_find' => ['ctrl+f'],
            ...self::TAB_KEYS,
        ];
    }

    /** Tab/Shift+Tab: move the focused link (wrapping) and scroll it into view. */
    private function cycleLink(int $direction): void
    {
        $doc = $this->doc;
        if (null === $doc || 0 === ($count = $doc->groupCount())) {
            return;
        }
        $this->currentLink =
            -1 === $this->currentLink && $direction < 0
                ? $count - 1
                : ((($this->currentLink + $direction) % $count) + $count) % $count;

        $first = $doc->spansOfGroup($this->currentLink)[0] ?? null;
        if (null !== $first) {
            $this->scrollTop = match (true) {
                $first->line < $this->scrollTop => $this->clampScroll($first->line),
                $first->line > ($this->scrollTop + $this->bodyRows() - 1) => $this->clampScroll($first->line - $this->bodyRows() + 1),
                default => $this->scrollTop,
            };
        }
        $this->invalidate();
    }

    private function followCurrent(): void
    {
        if (null !== $this->doc) {
            $this->activate($this->doc, $this->currentLink);
        }
    }

    /**
     * A group taken: one of the page's own actions — a task's box, else a
     * `<details>` — is run, anything else followed as the link its author
     * wrote. No group taken (Enter before any Tab) does nothing.
     */
    private function activate(RenderedDoc $doc, int $group): void
    {
        $target = $doc->target($group);
        if (null === $target) {
            return;
        }
        $m = [];
        match (true) {
            !$doc->isAction($group) => $this->follow($target),
            1 === \preg_match('/^fence-(element|action):(\d+):(.+)$/', $target, $m) => $this->fenceAction($m[1], (int) $m[2], $m[3]),
            1 === \preg_match('/^task:(\d+)-(\d+)$/', $target, $m) => $this->toggleTask((int) $m[1], (int) $m[2]),
            default => $this->toggleDetails((int) \substr($target, offset: \strlen('details:'))),
        };
    }

    private function fenceAction(string $kind, int $offset, string $id): void
    {
        $selected = function (int $line, string $element) use ($offset): void {
            $target = 'fence-element:'.$offset.':'.$element;
            $span = \array_find($this->doc->links ?? [], static fn (LinkSpan $span): bool => $target === $span->target);
            if (null !== $span) {
                $this->currentLink = $span->group;
            }
            $origin = Text::lineAndColumn($this->markdown, $offset)[0] + $line;
            $this->pointAtSource($origin, 0);
            $this->followLine = $origin;
            $this->invalidate();
        };
        'element' === $kind
            ? $this->fences?->select($offset, $id, $selected)
            : $this->fences?->invoke($offset, $id, $selected);
    }

    /**
     * Route a link target: a place on this page (`#anchor`), a browser URL,
     * another doc page — at its anchor, when the link names one — or a plain
     * file.
     */
    private function follow(string $target): void
    {
        if (1 === \preg_match('#^(?:https?|mailto|ftp):#i', $target)) {
            $this->docs()?->openUrl($target);

            return;
        }
        [$file, $anchor] = \array_pad(\explode('#', $target, limit: 2), length: 2, value: null);
        if ('' === $file) {
            $this->goToAnchor((string) $anchor);

            return;
        }
        $this->followFile(\rawurldecode((string) $file), $target, $anchor);
    }

    /** Bring an anchor of this page (a heading, a footnote) to the top of the view. */
    private function goToAnchor(string $anchor): void
    {
        $line = $this->doc?->anchor(\rawurldecode($anchor));
        if (null === $line) {
            $this->docs()?->missingTarget('#'.$anchor);

            return;
        }
        $this->scrollTop = $this->clampScroll($line);
        $this->invalidate();
    }

    private function toggleDetails(int $number): void
    {
        $open = !\array_key_exists($number, $this->openDetails);
        unset($this->openDetails[$number]);
        if ($open) {
            $this->openDetails[$number] = true;
        }
        $this->doc = null;
        $this->invalidate();
    }

    /** Follow a link to a file: another doc page (scrolled to $anchor once shown), or a plain file. */
    private function followFile(string $file, string $target, ?string $anchor): void
    {
        $resolved = Files::resolve(\str_starts_with($file, '/') ? $file : \dirname((string) $this->path).'/'.$file);
        // Containment mirrors hierarchyCrumbs(): a target must resolve inside
        // the docs root or one of the plugins' docs trees, so a link that
        // escapes (an absolute path, a ../ chain into a plugin's src/) is not
        // a doc target.
        if (null === $resolved || !\is_file($resolved) || null === $this->treeOf($resolved)) {
            $this->docs()?->missingTarget($target);

            return;
        }
        if (\str_ends_with($resolved, '.md')) {
            $this->history[] = ['path' => (string) $this->path, 'scrollTop' => $this->scrollTop, 'currentLink' => $this->currentLink];
            $this->forward = []; // a fresh navigation invalidates the forward trail
            $this->pendingAnchor = null === $anchor ? null : \rawurldecode($anchor);
            $this->docs()?->openHelp($resolved);

            return;
        }
        $this->docs()?->openFile($resolved);
    }

    /** Backspace / the ‹ arrow: back one page, restoring scroll and focus. */
    private function back(): void
    {
        $previous = \array_pop($this->history);
        if (null === $previous) {
            $this->docs()?->backEmpty();

            return;
        }
        $this->forward[] = ['path' => (string) $this->path, 'scrollTop' => $this->scrollTop, 'currentLink' => $this->currentLink];
        $this->restore($previous);
    }

    /** The › arrow: re-enter the page most recently backed out of. */
    private function forward(): void
    {
        $next = \array_pop($this->forward);
        if (null === $next) {
            return;
        }
        $this->history[] = ['path' => (string) $this->path, 'scrollTop' => $this->scrollTop, 'currentLink' => $this->currentLink];
        $this->restore($next);
    }

    /** @param array{path: string, scrollTop: int, currentLink: int} $entry */
    private function restore(array $entry): void
    {
        // onOpenHelp re-shows synchronously; restore the view state after.
        $this->docs()?->openHelp($entry['path']);
        $this->scrollTop = $entry['scrollTop'];
        $this->currentLink = $entry['currentLink'];
        $this->invalidate();
    }

    /** Splice the focused link group's highlight over a rendered line. */
    private function highlight(string $line, int $lineNo): string
    {
        if (-1 === $this->currentLink || null === $this->doc || !$this->styles->hasColor()) {
            return $line;
        }
        // Spans are non-overlapping; paint right-to-left so columns stay valid.
        $spans = \array_reverse(\array_values(\array_filter(
            $this->doc->spansOfGroup($this->currentLink),
            static fn (LinkSpan $span): bool => $span->line === $lineNo,
        )));
        foreach ($spans as $span) {
            // Split at raw byte offsets (boundary SGR codes attach left) so the
            // prefix/suffix keep their own style regions byte-for-byte — column
            // re-slicing would replay the link's open codes into the suffix and
            // bleed the link style over the rest of the line.
            $start = $this->byteAtColumn($line, $span->col);
            $end = $this->byteAtColumn($line, $span->endCol);
            $mid = AnsiUtils::stripAnsiCodes(\substr($line, $start, $end - $start));
            // Dim the focused link when the pane is blurred, like the list panes'
            // .list::selected(:focus) — the bright shade means "this pane has focus".
            $linkRole = $this->isFocused() ? Role::SelectedRow : Role::SelectedRowUnfocused;
            $line = \substr($line, offset: 0, length: $start).Sgr::RESET.$this->styles->of($linkRole)->getAnsiRestore().$mid.Sgr::RESET.\substr($line, $end);
        }

        return $line;
    }

    // --- FindTarget: the in-file find bar searches the rendered page ---------

    #[\Override]
    public array $findLines {
        get => \array_map(AnsiUtils::stripAnsiCodes(...), $this->doc->lines ?? []);
    }

    #[\Override]
    public int $findCaretLine {
        get => $this->scrollTop; // "nearest match" anchors to the top of the viewport
    }

    #[\Override]
    public int $findCaretCol {
        get => 0;
    }

    #[\Override]
    public ?Document $replaceDocument {
        get => null; // documentation is read-only
    }

    #[\Override]
    public function revealMatch(int $line, int $startByte, int $endByte): void
    {
        // Scroll the match's line into view (the match itself is highlighted via
        // showSearchMatches); mirror cycleLink's scroll-into-view arithmetic.
        $this->scrollTop = match (true) {
            $line < $this->scrollTop => $this->clampScroll($line),
            $line > ($this->scrollTop + $this->bodyRows() - 1) => $this->clampScroll($line - $this->bodyRows() + 1),
            default => $this->scrollTop,
        };
        $this->invalidate();
    }

    #[\Override]
    public function showSearchMatches(array $byLine): void
    {
        $this->searchMatches = $byLine;
        $this->invalidate();
    }

    #[\Override]
    public function selectMatches(array $matches, int $current): bool
    {
        return false; // documentation has no carets
    }

    /**
     * Splice the find-bar's match highlights over a rendered line. Match offsets
     * are byte columns into the STRIPPED line, so map each through the display
     * column to a byte offset in the ANSI line (as {@see highlight()} does for
     * links). The current match is bold, matching the editor's search styling.
     */
    private function searchHighlight(string $line, int $lineNo): string
    {
        $matches = $this->searchMatches[$lineNo] ?? [];
        if ([] === $matches || !$this->styles->hasColor()) {
            return $line;
        }
        $stripped = AnsiUtils::stripAnsiCodes($line);
        // Paint right-to-left so earlier byte offsets stay valid.
        foreach (\array_reverse($matches) as $match) {
            $start = $this->byteAtColumn($line, AnsiUtils::visibleWidth(\substr($stripped, offset: 0, length: $match['start'])));
            $end = $this->byteAtColumn($line, AnsiUtils::visibleWidth(\substr($stripped, offset: 0, length: $match['end'])));
            $mid = AnsiUtils::stripAnsiCodes(\substr($line, $start, $end - $start));
            $style = $match['current'] ? $this->styles->of(Role::SearchCurrentBg)->withBold() : $this->styles->of(Role::SearchBg);
            $line = \substr($line, offset: 0, length: $start).Sgr::RESET.$style->getAnsiRestore().$mid.Sgr::RESET.\substr($line, $end);
        }

        return $line;
    }

    /** The byte offset where a display column starts; zero-width SGR codes at the boundary attach to the left part. */
    private function byteAtColumn(string $line, int $col): int
    {
        $offset = 0;
        foreach (AnsiUtils::walkCells($line) as $cell) {
            if (0 < $cell['width'] && $cell['col'] >= $col) {
                break;
            }
            $offset += \strlen($cell['text']);
        }

        return $offset;
    }

    private function clampScroll(int $scrollTop): int
    {
        $max = \max(0, \count($this->doc->lines ?? []) - $this->bodyRows());

        return IntMath::clamp($scrollTop, 0, $max);
    }

    /**
     * Bring the block written from $sourceLine into view: the preview's half
     * of following the caret. Settled at the next render.
     */
    protected function showSourceLine(int $sourceLine): void
    {
        $this->followLine = $sourceLine;
        $this->invalidate();
    }

    /**
     * Where the view has to start for the block holding $sourceLine to show:
     * where it is when the block's first line already does, else a third of
     * the page above it, so the reader sees what leads into it.
     *
     * @param list<int> $origins
     */
    private function scrollToShow(array $origins, int $sourceLine): int
    {
        $block = null;
        foreach ($origins as $line => $origin) {
            if ($origin > $sourceLine || (null !== $block && $origin <= $origins[$block])) {
                continue;
            }
            $block = $line;
        }
        $body = $this->bodyRows();
        if (null === $block || ($block >= $this->scrollTop && $block < ($this->scrollTop + $body))) {
            return $this->scrollTop;
        }

        return $block - \intdiv($body, num2: 3);
    }

    /**
     * The page's left margin in a pane $cols wide — none by default, as the
     * help browser has always drawn. Single-byte by contract: its length is
     * the columns it takes.
     */
    protected function margin(int $cols): string
    {
        return '';
    }

    /** The rows available to page content, below the navigation header. */
    private function bodyRows(): int
    {
        return \max(1, $this->lastRows - self::HEADER_ROWS);
    }
}
