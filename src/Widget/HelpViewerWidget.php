<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsWidget;
use Porta\Help\LinkSpan;
use Porta\Help\MarkdownRenderer;
use Porta\Help\RenderedDoc;
use Porta\Support\AnsiText;
use Porta\Support\IntMath;
use Porta\Ui\LtrPane;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * The help tab's pane: shows a documentation page rendered by
 * {@see MarkdownRenderer} (re-rendered per pane width), with Tab/Shift+Tab
 * cycling the page's links, Enter/click following them and a viewer-local
 * back stack (Backspace). It replaces the code editor as the editor-pane's
 * content while a help buffer is active, so tab-management keys are re-bound
 * here and routed back to the IDE through {@see HelpViewerHandler}.
 */
#[AsWidget('help-viewer')]
final class HelpViewerWidget extends AbstractWidget implements FocusableInterface, LtrPane, MenuOverlayHost, VerticallyExpandableInterface
{
    use FillsPaneTrait;
    use FocusableTrait;
    use KeybindingsTrait;
    use MenuOverlayHostTrait;

    /** The clickable navigation header occupies the first rendered row. */
    private const int HEADER_ROWS = 1;

    private ?string $path = null;
    private string $markdown = '';
    /** The page rendered for $renderedWidth (dropped on resize/theme change). */
    private ?RenderedDoc $doc = null;
    private int $renderedWidth = 0;
    private int $scrollTop = 0;
    /** The Tab-focused link group, -1 = none. */
    private int $currentLink = -1;
    /** @var list<array{path: string, scrollTop: int, currentLink: int}> */
    private array $history = [];
    /** @var list<array{path: string, scrollTop: int, currentLink: int}> pages backed out of, re-entered via the › arrow */
    private array $forward = [];
    /** @var list<array{action: string, start: int, end: int}> header click zones, rebuilt per render */
    private array $headerZones = [];
    private int $lastRows = 24;
    /** The documentation root directory: the breadcrumb mirrors paths under it. */
    private ?string $docsRoot = null;
    private readonly Styles $styles;
    private readonly MarkdownRenderer $renderer;
    private ?HelpViewerHandler $handler = null;

    public function __construct(?Styles $styles = null)
    {
        $this->styles = $styles ?? new Styles(DarkTheme::definition());
        $this->renderer = new MarkdownRenderer($this->styles);
    }

    public function setHandler(HelpViewerHandler $handler): void
    {
        $this->handler = $handler;
    }

    public function setDocsRoot(string $dir): void
    {
        $resolved = realpath($dir);
        $this->docsRoot = $resolved ? $resolved : $dir;
    }

    /** Show a page from the top; the back history survives page changes. */
    public function show(string $path, string $markdown): void
    {
        $resolved = realpath($path);
        $this->path = $resolved ? $resolved : $path;
        $this->markdown = $markdown;
        $this->doc = null;
        $this->scrollTop = 0;
        $this->currentLink = -1;
        $this->invalidate();
    }

    /** Drop everything, including the back/forward history (the help tab closed). */
    public function clear(): void
    {
        $this->path = null;
        $this->markdown = '';
        $this->doc = null;
        $this->scrollTop = 0;
        $this->currentLink = -1;
        $this->history = [];
        $this->forward = [];
        $this->invalidate();
    }

    /** The shown page's absolute path, or null when the viewer is empty. */
    public function path(): ?string
    {
        return $this->path;
    }

    /** Rendered lines bake palette codes: drop the memo on a theme switch. */
    public function refreshTheme(): void
    {
        $this->doc = null;
        $this->invalidate();
    }

    public function scrollBy(int $rows): void
    {
        $this->scrollTop = $this->clampScroll($this->scrollTop + $rows);
        $this->invalidate();
    }

    /** A click: a header zone (arrows/crumbs), a link under the cell, or a miss. */
    public function clickAt(int $relRow, int $relCol): bool
    {
        if (0 === $relRow) {
            foreach ($this->headerZones as $zone) {
                if (!($relCol >= $zone['start'] && $relCol < $zone['end'])) {
                    continue;
                }

                match (true) {
                    'back' === $zone['action'] => $this->back(),
                    'forward' === $zone['action'] => $this->forward(),
                    'top' === $zone['action'] => $this->scrollBy(-\PHP_INT_MAX),
                    default => $this->follow(substr($zone['action'], offset: 4)),
                };

                return true;
            }

            return false;
        }

        $doc = $this->doc;
        if (null === $doc) {
            return false;
        }
        $line = $this->scrollTop + $relRow - self::HEADER_ROWS;
        foreach ($doc->links as $span) {
            if (!($span->line === $line && $relCol >= $span->col && $relCol < $span->endCol)) {
                continue;
            }

            $this->currentLink = $span->group;
            $this->follow($span->target);

            return true;
        }

        return false;
    }

    /** @return list<string> */
    public function render(RenderContext $context): array
    {
        $cols = $context->getColumns();
        $rows = $context->getRows();
        if ($cols < 1 || $rows < 1) {
            return [];
        }
        $this->lastRows = $rows;

        if (null === $this->doc || $cols !== $this->renderedWidth) {
            $doc = $this->renderer->render($this->markdown, $cols);
            $this->doc = $doc;
            $this->renderedWidth = $cols;
            $this->scrollTop = $this->clampScroll($this->scrollTop);
            $this->currentLink = min($this->currentLink, $doc->groupCount() - 1);
        }

        $lines = [$this->headerLine($cols)];
        for ($row = 0; $row < ($rows - self::HEADER_ROWS); ++$row) {
            $line = $this->doc->lines[$this->scrollTop + $row] ?? '';
            $lines[] = AnsiText::padRight($this->highlight($line, $this->scrollTop + $row), $cols);
        }

        return $this->withMenuOverlay($lines, $cols);
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
        $this->headerZones = [];
        $crumbs = $this->hierarchyCrumbs();
        $total = \count($crumbs);
        while (\count($crumbs) > 1 && (7 + array_sum(array_map(static fn (array $c): int => AnsiUtils::visibleWidth($c['label']) + 3, $crumbs))) > $cols) {
            array_shift($crumbs); // keep the tail: the current page must stay visible
        }

        $hasColor = $this->styles->hasColor();
        $bold = $hasColor ? Sgr::BOLD : '';
        $unbold = $hasColor ? Sgr::NORMAL_INTENSITY : '';
        $text = ' ';
        $col = 1;
        foreach ([['back', '‹', [] !== $this->history], ['forward', '›', [] !== $this->forward]] as [$action, $glyph, $active]) {
            if ($active) {
                // A one-cell glyph is a frustrating click target: pad the zone
                // a cell to each side (earlier zones win where they touch).
                $this->headerZones[] = ['action' => $action, 'start' => $col - 1, 'end' => $col + 2];
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
                $this->headerZones[] = ['action' => $crumb['action'], 'start' => $col - 1, 'end' => $col + $width + 1];
                $text .= $this->styles->of(Role::TabAccent)->apply($crumb['label']);
            }
            if ('' === $crumb['action'] && $isLast) {
                // The current page: clicking it scrolls back to the top rather
                // than doing nothing (the only crumb on the root page).
                $this->headerZones[] = ['action' => 'top', 'start' => $col - 1, 'end' => $col + $width + 1];
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

        $line = $this->styles->of(Role::HeaderBar)->getAnsiRestore().AnsiText::padRight(AnsiUtils::truncateToWidth($text, $cols, '…'), $cols);

        return $hasColor ? $line.Sgr::RESET : AnsiUtils::stripAnsiCodes($line);
    }

    /** A page's crumb label: the host's naming (e.g. the docs root as "Documentation"), else the file name. */
    private function crumbLabel(string $path): string
    {
        return $this->handler?->labelFor($path) ?? basename($path, suffix: '.md');
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
            $crumbs[] = ['action' => is_file($rootReadme) ? 'nav:'.$rootReadme : '', 'label' => $this->crumbLabel($rootReadme)];
            if (str_starts_with($path, $root.'/')) {
                $dirs = array_filter(explode('/', trim(substr(\dirname($path), \strlen($root)), characters: '/')), static fn (string $d): bool => '' !== $d);
                if ('README.md' === basename($path) && [] !== $dirs) {
                    $label = array_pop($dirs);
                }
                $prefix = $root;
                foreach ($dirs as $dir) {
                    $prefix .= '/'.$dir;
                    $index = $prefix.'/README.md';
                    $crumbs[] = ['action' => is_file($index) ? 'nav:'.$index : '', 'label' => $dir];
                }
            }
        }
        $crumbs[] = ['action' => '', 'label' => $label];

        return $crumbs;
    }

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
            $kb->matches($data, 'next_buffer') => $this->handler?->cycleBuffer(1),
            $kb->matches($data, 'prev_buffer') => $this->handler?->cycleBuffer(-1),
            $kb->matches($data, 'close_buffer') => $this->handler?->closeBuffer(),
            $kb->matches($data, 'goto_file') => $this->handler?->openGotoFile(),
            $kb->matches($data, 'save_all') => $this->handler?->saveAll(),
            default => null,
        };
    }

    /** @return array<string, string[]> */
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
            // Re-bound to the editor's defaults so tab management keeps working
            // while the code editor is detached (see EditorKeymap).
            'next_buffer' => ['ctrl+right'],
            'prev_buffer' => ['ctrl+left'],
            'close_buffer' => ['ctrl+f4', 'alt+w'],
            'goto_file' => ['ctrl+shift+n', 'ctrl+p'],
            'save_all' => ['ctrl+alt+s'],
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
        $target = $this->doc?->target($this->currentLink);
        if (null !== $target) {
            $this->follow($target);
        }
    }

    /** Route a link target: browser URL, another doc page, or a plain file. */
    private function follow(string $target): void
    {
        if (1 === preg_match('#^https?://#i', $target)) {
            $this->handler?->openUrl($target);

            return;
        }
        $resolved = str_starts_with($target, '/') ? $target : realpath(\dirname((string) $this->path).'/'.$target);
        if (false === $resolved || !is_file($resolved)) {
            $this->handler?->missingTarget($target);

            return;
        }
        if (str_ends_with($resolved, '.md')) {
            $this->history[] = ['path' => (string) $this->path, 'scrollTop' => $this->scrollTop, 'currentLink' => $this->currentLink];
            $this->forward = []; // a fresh navigation invalidates the forward trail
            $this->handler?->openHelp($resolved);

            return;
        }
        $this->handler?->openFile($resolved);
    }

    /** Backspace / the ‹ arrow: back one page, restoring scroll and focus. */
    private function back(): void
    {
        $previous = array_pop($this->history);
        if (null === $previous) {
            $this->handler?->backEmpty();

            return;
        }
        $this->forward[] = ['path' => (string) $this->path, 'scrollTop' => $this->scrollTop, 'currentLink' => $this->currentLink];
        $this->restore($previous);
    }

    /** The › arrow: re-enter the page most recently backed out of. */
    private function forward(): void
    {
        $next = array_pop($this->forward);
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
        $this->handler?->openHelp($entry['path']);
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
        $spans = array_reverse(array_values(array_filter(
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
            $mid = AnsiUtils::stripAnsiCodes(substr($line, $start, $end - $start));
            $line = substr($line, offset: 0, length: $start).Sgr::RESET.$this->styles->of(Role::SelectedRow)->getAnsiRestore().$mid.Sgr::RESET.substr($line, $end);
        }

        return $line;
    }

    /** The byte offset where a display column starts; zero-width SGR codes at the boundary attach to the left part. */
    private function byteAtColumn(string $line, int $col): int
    {
        $offset = 0;
        $current = 0;
        $len = \strlen($line);
        while ($offset < $len) {
            if ("\x1b" === $line[$offset] && null !== ($ansi = AnsiUtils::extractAnsiCode($line, $offset))) {
                $offset += $ansi['length']; // zero-width: codes at the boundary attach to the left part
                continue;
            }
            if ($current >= $col) {
                break;
            }
            $next = strpos($line, needle: "\x1b", offset: $offset + 1);
            $segment = substr($line, $offset, (false === $next ? $len : $next) - $offset);
            $split = grapheme_str_split($segment);
            /** @var list<string> $graphemes */
            $graphemes = $split ? $split : [];
            foreach ($graphemes as $grapheme) {
                $current += AnsiUtils::graphemeWidth($grapheme);
                $offset += \strlen($grapheme);
                if ($current >= $col) {
                    break;
                }
            }
        }

        return $offset;
    }

    private function clampScroll(int $scrollTop): int
    {
        $max = max(0, \count($this->doc->lines ?? []) - $this->bodyRows());

        return IntMath::clamp($scrollTop, 0, $max);
    }

    /** The rows available to page content, below the navigation header. */
    private function bodyRows(): int
    {
        return max(1, $this->lastRows - self::HEADER_ROWS);
    }
}
