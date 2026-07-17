<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsWidget;
use Porta\Support\AnsiText;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
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
    use HoverTrait;
    use MenuOverlayHostTrait;

    private readonly Styles $styles;

    public function __construct(?Styles $styles = null)
    {
        $this->styles = $styles ?? Styles::dark();
    }

    /** The ` › ` scope separator, dimmed (visible width 3). */
    private function separator(): string
    {
        return $this->styles->of(Role::Gutter)->apply(' › ');
    }

    /** @var (callable(): list<array{label: string, line: int}>)|null */
    private $provider = null;

    private string $lastFingerprint = '';

    /** @var list<array{start: int, width: int, line: int}> segment layout from the last render, for hit-testing */
    private array $lastLayout = [];

    /** @param callable(): list<array{label: string, line: int}> $provider */
    public function setProvider(callable $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function beforeRender(): void
    {
        if (null === $this->provider) {
            return;
        }
        $fingerprint = (string) json_encode(($this->provider)());
        if ($fingerprint !== $this->lastFingerprint) {
            $this->lastFingerprint = $fingerprint;
            $this->clearHover(); // the crumbs changed under the pointer
            $this->invalidate();
        }
    }

    public function render(RenderContext $context): array
    {
        $crumbs = null !== $this->provider ? ($this->provider)() : [];
        $this->lastLayout = [];
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
            $width = mb_strwidth($label);
            $this->lastLayout[] = ['start' => $col, 'width' => $width, 'line' => $crumb['line']];
            // Bar chrome, so the pointer tints with BarHoverBg (not a list
            // row's RowHighlightBg) under whichever foreground the crumb has.
            $hovered = $i === $this->hoveredIndex;
            $content .= $this->crumbStyle($i === $last, $hovered)->apply($label);
            $col += $width;
        }

        $bar = $this->styles->of(Role::Toolbar)->apply(AnsiText::truncate($content, $columns, pad: true));

        return $this->withMenuOverlay([$bar], $columns);
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

    /** Tint the crumb under the pointer (widget-relative column); returns whether it changed. */
    public function hoverAt(int $col): bool
    {
        return $this->setHovered($this->crumbIndexAt($col));
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
