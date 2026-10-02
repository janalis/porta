<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\Support\AnsiText;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\CrumbBar;

/**
 * A settings page's title row as a trail — `Settings › Plugins › git` — drawn
 * by {@see CrumbBar} as the editor's navigation bar is, so the two read as one
 * control. A crumb that names another page is a link to it: it tints under
 * the pointer and a click asks for that page. The current crumb, and a
 * heading that is no page (Languages), are not links and do neither.
 *
 * Owned by each page widget that has a title row (the form, the keybindings
 * list); the view decides what the trail says.
 */
final class CrumbHeader
{
    /** @var list<array{label: string, id: ?string}> root first, the page shown last */
    private array $trail = [];

    /** @var array<int, array{start: int, width: int}> each drawn crumb's columns in the last render */
    private array $zones = [];

    /** The pointer's column on the title row, or null. */
    private ?int $hoverCol = null;

    /** @var (callable(string): void)|null a link crumb was clicked; its page id */
    private $onCrumb;

    /**
     * Whether the trail changed.
     *
     * @param list<array{label: string, id: ?string}> $trail
     */
    public function set(array $trail): bool
    {
        if ($trail === $this->trail) {
            return false;
        }
        $this->trail = $trail;

        return true;
    }

    /** @param callable(string): void $callback */
    public function onCrumb(callable $callback): void
    {
        $this->onCrumb = $callback;
    }

    /**
     * The title row, $width wide, with $hint faint at its right end. The
     * trail is what says which page this is, so it keeps its room and the
     * hint is what gives way on a narrow pane.
     */
    public function render(int $width, Styles $styles, string $hint): string
    {
        $crumbs = \array_map(static fn (array $crumb): array => ['label' => $crumb['label']], $this->trail);
        $hint = AnsiText::truncate($hint.' ', \max(0, $width - CrumbBar::naturalWidth($crumbs)));
        $hovered = null === $this->linkAt($this->hoverCol) ? null : $this->hoverCol;
        $bar = CrumbBar::render($crumbs, $width, $styles, $hovered, '' === $hint ? '' : $styles->of(Role::Subtle)->apply($hint));
        $this->zones = $bar['zones'];

        return $bar['line'];
    }

    /** A press at column $col of the title row: a link crumb asks for its page. Returns whether one did. */
    public function click(int $col): bool
    {
        $id = $this->linkAt($col);
        if (null === $id) {
            return false;
        }
        if (null !== $this->onCrumb) {
            ($this->onCrumb)($id);
        }

        return true;
    }

    /** The pointer is at column $col of the title row, or off it (null). Returns whether the lit link changed. */
    public function hover(?int $col): bool
    {
        $before = $this->linkIndexAt($this->hoverCol);
        $this->hoverCol = $col;

        return $before !== $this->linkIndexAt($col);
    }

    /** The page the link crumb drawn at $col leads to, or null. */
    private function linkAt(?int $col): ?string
    {
        $index = $this->linkIndexAt($col);

        return null === $index ? null : $this->trail[$index]['id'];
    }

    /** The index of the link crumb drawn at $col: not the current crumb, and one that names a page. */
    private function linkIndexAt(?int $col): ?int
    {
        $index = null === $col ? null : CrumbBar::at($this->zones, $col);
        if (null === $index || $index === (\count($this->trail) - 1) || null === ($this->trail[$index]['id'] ?? null)) {
            return null;
        }

        return $index;
    }
}
