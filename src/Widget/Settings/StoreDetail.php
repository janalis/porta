<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\Help\Layout\ImageLayout;
use Porta\Help\MarkdownRenderer;
use Porta\I18n\Translator;
use Porta\Plugin\Store\StoreEntry;
use Porta\Plugin\Store\StoreRow;
use Porta\Ui\Graphics\TerminalGraphics;
use Porta\Ui\Theme\Styles;

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

    /** The screenshot the page shows. */
    private int $shot = 0;

    /** @var array<string, string|null> each asked-for address and what it answered; absent while it is on its way */
    private array $assets = [];

    /** @var array<string, true> the addresses already asked for, so a page laid out again does not ask twice */
    private array $asked = [];

    /** @var array{lines: list<string>, button: array{int, int, int, int}}|null */
    private ?array $page = null;

    /** @var array{?string, int, int, int, bool} the [package, width, rows, shot, hovered] the page was laid out for */
    private array $key = [null, 0, 0, 0, false];

    private readonly MarkdownRenderer $markdown;

    private readonly ImageLayout $images;

    private readonly StorePage $pages;

    public function __construct(Translator $translator, Styles $styles, ?TerminalGraphics $graphics)
    {
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
    }

    public function close(): void
    {
        $this->package = null;
        $this->images->clear();
        $this->markdown->clearImages();
        $this->page = null;
    }

    public function scrollBy(int $delta): void
    {
        $this->scroll = \max(0, $this->scroll + $delta);
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
    public function lines(StoreRow $row, int $width, int $rows, int $room, bool $hovered): array
    {
        $key = [$row->entry->package, $width, $rows, $this->shot, $hovered];
        if (null === $this->page || $key !== $this->key) {
            $this->key = $key;
            $this->page = $this->pages->render($row, $width, $rows, $this->shot, $this->assets, $hovered);
        }
        $this->scroll = \max(0, \min($this->scroll, \count($this->page['lines']) - $room));

        return \array_slice($this->page['lines'], $this->scroll, $room);
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
     * its README and the screenshot on screen — each address and whether it
     * is a picture. Each is asked for once.
     *
     * @return list<array{string, bool}>
     */
    public function unasked(StoreEntry $entry): array
    {
        $wanted = [[$entry->changelog, false], [$entry->readme, false], [$entry->screenshots[$this->shot] ?? null, true]];
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
}
