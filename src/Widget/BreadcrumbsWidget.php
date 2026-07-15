<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsWidget;
use Porta\Ui\ColorPalette;
use Porta\Ui\Palette;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
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
    use MenuOverlayHostTrait;

    private readonly Palette $palette;

    public function __construct(?Palette $palette = null)
    {
        $this->palette = $palette ?? ColorPalette::dark();
    }

    /** The ` › ` scope separator, dimmed (visible width 3). */
    private function separator(): string
    {
        return $this->palette->of(Role::Gutter).' › '.Sgr::DEFAULT_FG;
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
        $content = $this->palette->of(Role::Toolbar).' '; // bar background + a leading space
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
            $content .= $i === $last
                ? $this->palette->of(Role::Emphasis).Sgr::BOLD.$label."\x1b[22m\x1b[39m" // current scope: bright + bold
                : $this->palette->of(Role::Muted).$label.Sgr::DEFAULT_FG;
            $col += $width;
        }

        return $this->withMenuOverlay([AnsiUtils::truncateToWidth($content, $columns, '…', pad: true).Sgr::RESET], $columns);
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
