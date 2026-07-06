<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Widget\Render\MenuOverlay;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * One-line breadcrumbs bar under the tabs showing the caret's structural path
 * (`Namespace › Class › method`). Content comes from a provider closure so it
 * tracks the caret for free on every frame. Renders zero rows (no bar) when the
 * caret is not inside any scope, so a structureless file keeps its full editor.
 */
final class BreadcrumbsWidget extends AbstractWidget
{
    private const SEPARATOR = "\x1b[38;5;240m › \x1b[39m"; // visible width 3

    /** @var (callable(): list<array{label: string, line: int}>)|null */
    private $provider = null;

    private string $lastFingerprint = '';

    /** @var list<array{start: int, width: int, line: int}> segment layout from the last render, for hit-testing */
    private array $lastLayout = [];

    /** @var list<array{row: int, col: int, width: int, segment: string}> floating menu spans to overlay on top */
    private array $menuOverlay = [];

    /**
     * Floating menu-dropdown spans (local coords) to composite over the bar, so an
     * app-menu dropdown covers the breadcrumb row instead of drawing behind it.
     *
     * @param list<array{row: int, col: int, width: int, segment: string}> $spans
     */
    public function setMenuOverlay(array $spans): void
    {
        $this->menuOverlay = $spans;
        $this->invalidate();
    }

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
        $content = "\x1b[48;5;235m "; // bar background + a leading space
        $col = 1;
        $last = \count($crumbs) - 1;
        foreach ($crumbs as $i => $crumb) {
            if ($i > 0) {
                $content .= self::SEPARATOR;
                $col += 3;
            }
            $label = $crumb['label'];
            $width = mb_strwidth($label);
            $this->lastLayout[] = ['start' => $col, 'width' => $width, 'line' => $crumb['line']];
            $content .= $i === $last
                ? "\x1b[38;5;255m\x1b[1m".$label."\x1b[22m\x1b[39m" // current scope: bright + bold
                : "\x1b[38;5;250m".$label."\x1b[39m";
            $col += $width;
        }

        return MenuOverlay::apply([AnsiUtils::truncateToWidth($content, $columns, '…', pad: true)."\x1b[0m"], $this->menuOverlay, $columns);
    }

    /** The jump line of the crumb at column $col of the last render, or null. */
    public function lineAtColumn(int $col): ?int
    {
        foreach ($this->lastLayout as $entry) {
            if ($col >= $entry['start'] && $col < ($entry['start'] + $entry['width'])) {
                return $entry['line'];
            }
        }

        return null;
    }
}
