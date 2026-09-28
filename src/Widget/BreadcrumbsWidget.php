<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsWidget;
use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Support\ColumnHover;
use Porta\Widget\Support\ProviderFingerprint;
use Porta\Widget\Support\StylesDefault;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * One-line breadcrumbs bar under the tabs showing the caret's structural path
 * (`Namespace › Class › method`). Content comes from a provider closure so it
 * tracks the caret for free on every frame. Renders zero rows (no bar) when the
 * caret is not inside any scope, so a structureless file keeps its full editor.
 */
#[AsWidget('breadcrumbs')]
final class BreadcrumbsWidget extends AbstractWidget implements MenuOverlayHost
{
    use ColumnHover;
    use MenuOverlayHostTrait;

    /** @use ProviderFingerprint<list<array{label: string, line: int}>> */
    use ProviderFingerprint;
    use StylesDefault;

    /** The "Interface > Breadcrumbs" toggle; when off the bar renders no rows. */
    private bool $visible = true;

    public function __construct(?Styles $styles = null)
    {
        $this->initStyles($styles);
    }

    public function show(): void
    {
        if (!$this->visible) {
            $this->visible = true;
            $this->invalidate();
        }
    }

    public function hide(): void
    {
        if ($this->visible) {
            $this->visible = false;
            $this->invalidate();
        }
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    /** The ` › ` scope separator, dimmed (visible width 3). */
    private function separator(): string
    {
        return $this->styles->of(Role::Gutter)->apply(' '.Glyphs::BREADCRUMB_SEPARATOR.' ');
    }

    /** @var list<array{start: int, width: int, line: int}> segment layout from the last render, for hit-testing */
    private array $lastLayout = [];

    #[\Override]
    public function beforeRender(): void
    {
        // The visible gate is load-bearing: the provider tokenizes the whole
        // file, and a hidden bar must cost nothing — render() drawing no rows
        // does not stop beforeRender from running every frame.
        if (null === $this->provider || !$this->visible) {
            return;
        }
        // The hover column re-resolves per frame, so a relabel needs no
        // clearHover() — a still-correct tint stays, a stale one falls off.
        $this->refreshFingerprint(($this->provider)());
    }

    #[\Override]
    public function render(RenderContext $context): array
    {
        $this->lastLayout = [];
        if (!$this->visible) {
            return []; // the Interface setting hides the bar entirely
        }
        $crumbs = null !== $this->provider ? ($this->provider)() : [];
        if ([] === $crumbs) {
            return []; // no scope → no bar (the editor keeps the row)
        }

        $columns = $context->getColumns();
        $content = ' '; // a leading space; the bar background wraps the padded row
        $col = 1;
        $last = \count($crumbs) - 1;
        foreach ($crumbs as $i => $crumb) {
            if ($i > 0) {
                $content .= $this->separator();
                $col += 3;
            }
            $label = $crumb['label'];
            $width = \mb_strwidth($label);
            $this->lastLayout[] = ['start' => $col, 'width' => $width, 'line' => $crumb['line']];
            // Bar chrome, so the pointer tints with BarHoverBg (not a list
            // row's RowHighlightBg) under whichever foreground the crumb has.
            $hovered = null !== $this->hoverCol && $this->hoverCol >= $col && $this->hoverCol < ($col + $width);
            $content .= $this->crumbStyle($i === $last, $hovered)->apply($label);
            $col += $width;
        }

        $bar = $this->styles->of(Role::Toolbar)->apply(AnsiText::truncate($content, $columns, pad: true));

        return [$bar];
    }

    /**
     * A crumb's shading: the current scope is bright and bold, the ancestors
     * muted, and the one under the pointer takes the bar-chrome hover shade
     * under whichever of those foregrounds it already had.
     */
    private function crumbStyle(bool $current, bool $hovered): Style
    {
        return match (true) {
            $current && $hovered => $this->styles->merge(Role::BarHoverBg, Role::Emphasis)->withBold(),
            $current => $this->styles->of(Role::Emphasis)->withBold(),
            $hovered => $this->styles->merge(Role::BarHoverBg, Role::Muted),
            default => $this->styles->of(Role::Muted),
        };
    }

    /** Tint the crumb under the pointer (visual only). Stores the column; change at crumb granularity. */
    public function hoverAt(int $col): bool
    {
        return $this->storeHover($col, $this->crumbIndexAt($col));
    }

    /** The jump line of the crumb at column $col of the last render, or null. */
    public function lineAtColumn(int $col): ?int
    {
        $index = $this->crumbIndexAt($col);

        return null !== $index ? $this->lastLayout[$index]['line'] : null;
    }

    /** Index into the last render's segment layout at column $col, or null between/past the crumbs. */
    private function crumbIndexAt(int $col): ?int
    {
        foreach ($this->lastLayout as $index => $entry) {
            if ($col >= $entry['start'] && $col < ($entry['start'] + $entry['width'])) {
                return $index;
            }
        }

        return null;
    }
}
