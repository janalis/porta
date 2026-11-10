<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\Help\Layout\ImageLayout;
use Porta\Help\MarkdownRenderer;
use Porta\I18n\Translator;
use Porta\Plugin\Store\Art;
use Porta\Plugin\Store\StoreEntry;
use Porta\Plugin\Store\StoreRow;
use Porta\Support\AnsiText;
use Porta\Ui\Graphics\TerminalGraphics;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The plugin page the store has open: which plugin, how far it is scrolled,
 * which screenshot it shows, what its addresses answered — and the page laid
 * out by {@see StorePage}, kept until one of those changes.
 */
final class StoreDetail
{
    /** The package whose page is open, or null on the shelf. */
    private ?string $package = null;

    /** The page's first drawn line. */
    private int $scroll = 0;

    /** The strip's first screenshot. */
    private int $shot = 0;

    /** @var array<string, string|null> each asked-for address and what it answered; absent while it is on its way */
    private array $assets = [];

    /** @var array<string, true> the addresses already asked for, so a page laid out again does not ask twice */
    private array $asked = [];

    /** @var array{lines: list<string>, button: array{int, int, int, int}, links: list<array{url: string, spans: list<array{int, int, int}>}>}|null */
    private ?array $page = null;

    /** The link the keys are on, by its place on the page; -1 for the page's button. */
    private int $link = -1;

    /** Whether the next layout scrolls the focused link into view. */
    private bool $followLink = false;

    /** @var array{?string, int, int, int, bool, ?Art} the [package, width, rows, shot, hovered, art] the page was laid out for */
    private array $key = [null, 0, 0, 0, false, null];

    private readonly MarkdownRenderer $markdown;

    private readonly ImageLayout $images;

    private readonly StorePage $pages;

    public function __construct(
        Translator $translator,
        private readonly Styles $styles,
        ?TerminalGraphics $graphics,
    ) {
        $this->markdown = new MarkdownRenderer($styles, graphics: $graphics);
        $this->images = new ImageLayout($graphics);
        $this->pages = new StorePage($translator, $styles, $this->markdown, $this->images);
    }

    public function package(): ?string
    {
        return $this->package;
    }

    public function open(string $package): void
    {
        $this->package = $package;
        $this->scroll = 0;
        $this->shot = 0;
        $this->page = null;
        $this->link = -1;
    }

    public function close(): void
    {
        $this->package = null;
        // What answered nothing is asked again when a page opens: a converter may be there by then.
        $failed = \array_filter($this->assets, static fn (?string $body): bool => null === $body);
        $this->asked = \array_diff_key($this->asked, $failed);
        $this->assets = \array_diff_key($this->assets, $failed);
        $this->images->clear();
        $this->markdown->clearImages();
        $this->page = null;
    }

    public function scrollBy(int $delta): void
    {
        $this->scroll = \max(0, $this->scroll + $delta);
    }

    /**
     * Tab: the next of the page's links, the button before the first and
     * after the last; $step -1 goes back.
     */
    public function cycleLink(int $step): void
    {
        // -1 (the button) and each link, round.
        $stops = \count($this->page['links'] ?? []) + 1;
        $this->link = (((($this->link + 1 + $step) % $stops) + $stops) % $stops) - 1;
        $this->followLink = true;
    }

    /** Where the focused link leads, or null on the button. */
    public function focusedLink(): ?string
    {
        return $this->page['links'][$this->link]['url'] ?? null;
    }

    /** Where the link at ($row, $col) of what {@see lines()} drew leads, or null. */
    public function linkAt(int $row, int $col): ?string
    {
        $row += $this->scroll;
        $link = \array_find(
            $this->page['links'] ?? [],
            static fn (array $link): bool => null !== \array_find($link['spans'], static fn (array $span): bool => $row === $span[0] && $col >= $span[1] && $col < $span[2]),
        );

        return $link['url'] ?? null;
    }

    public function shot(): int
    {
        return $this->shot;
    }

    public function showShot(int $shot): void
    {
        $this->shot = $shot;
        $this->page = null;
    }

    /** What $url answered (null: nothing); the page is laid out again with it. */
    public function answer(string $url, ?string $body): void
    {
        $this->assets[$url] = $body;
        $this->page = null;
    }

    /** The page's button may have changed: it is laid out again. */
    public function stale(): void
    {
        $this->page = null;
    }

    /**
     * The page's lines for $room rows, from its scroll, which this clamps.
     *
     * @return list<string>
     */
    public function lines(StoreRow $row, int $width, int $rows, int $room, bool $hovered, ?Art $art = null): array
    {
        $key = [$row->entry->package, $width, $rows, $this->shot, $hovered, $art];
        // Art compares as the object it is: art found again lays the page out again.
        if (null === $this->page || $key !== $this->key) {
            $this->key = $key;
            $this->page = $this->pages->render($row, $width, $rows, $this->shot, $this->assets, $hovered, $art);
            // The pictures this layout no longer draws — a strip moved on, a pane resized — are freed.
            $this->images->sweep();
        }
        $this->link = \min($this->link, \count($this->page['links']) - 1);
        $spans = $this->page['links'][$this->link]['spans'] ?? [];
        if ($this->followLink && [] !== $spans) {
            // The focused link in view: scrolled to just as far as it takes.
            $this->scroll = \max(\min($this->scroll, $spans[0][0]), \array_last($spans)[0] - $room + 1);
        }
        $this->followLink = false;
        $this->scroll = \max(0, \min($this->scroll, \count($this->page['lines']) - $room));
        $lines = $this->page['lines'];
        foreach ($spans as [$at, $from, $to]) {
            $lines[$at] = $this->marked($lines[$at], $from, $to);
        }

        return \array_slice($lines, $this->scroll, $room);
    }

    /**
     * Whether the page's button is at ($row, $col) of what {@see lines()} drew.
     */
    public function onButton(int $row, int $col): bool
    {
        return StoreItem::within($this->page['button'] ?? null, $row + $this->scroll, $col);
    }

    /**
     * What the open page shows that has not been asked for: its changelog,
     * its README and its screenshots — each
     * address and whether it is a picture. Each is asked for once.
     *
     * @param list<string> $shots the page's screenshots
     *
     * @return list<array{string, bool}>
     */
    public function unasked(StoreEntry $entry, array $shots): array
    {
        $wanted = [[$entry->changelog, false], [$entry->readme, false]];
        // Six at most, as a package may list, and a wide pane shows them all.
        foreach ($shots as $shot) {
            $wanted[] = [$shot, true];
        }
        $asks = [];
        foreach ($wanted as [$url, $image]) {
            if (null === $url || \array_key_exists($url, $this->asked)) {
                continue;
            }
            $this->asked[$url] = true;
            $asks[] = [$url, $image];
        }

        return $asks;
    }

    /** $line with its columns $from to $to drawn as the focused link. */
    private function marked(string $line, int $from, int $to): string
    {
        $width = AnsiUtils::visibleWidth($line);

        return (
            AnsiText::columnsFrom($line, 0, $from)
            .Sgr::RESET
            .$this->styles->of(Role::SelectedRow)->apply(AnsiUtils::stripAnsiCodes(AnsiText::columnsFrom($line, $from, $to - $from)))
            .AnsiText::columnsFrom($line, $to, \max(0, $width - $to))
        );
    }
}
