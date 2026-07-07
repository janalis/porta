<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\I18n\Bidi;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\ScreenWriter;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * A {@see Tui} that can post-process each composed frame line before it reaches
 * the screen — the single choke point for the RTL bidi reorder, which the
 * framework's final {@see Renderer}/{@see ScreenWriter} (both final) don't
 * otherwise expose. When no reorderer is set it defers entirely to the parent,
 * so left-to-right locales run the stock render path untouched.
 *
 * The parent renders and writes privately inside {@see Tui::processRender()};
 * with a reorderer active we reproduce that step, mapping every line through it,
 * reading the parent's private collaborators reflectively (the class is pinned).
 */
final class BidiTui extends Tui
{
    /** @var (\Closure(string): string)|null the per-line visual reorder, or null for the stock path */
    private ?\Closure $reorder = null;

    /** @var list<string> the last frame's pre-reorder lines, for visual→logical column mapping */
    private array $lastLines = [];

    /**
     * Install (or clear, with null) the per-line reorder applied to every frame.
     *
     * @param (\Closure(string): string)|null $reorder
     */
    public function setLineReorderer(?\Closure $reorder): void
    {
        $this->reorder = $reorder;
        if (null === $reorder) {
            $this->lastLines = [];
        }
    }

    /**
     * Map a screen (visual) column on $row back to the logical column that the
     * widgets rendered, inverting the RTL reorder. Identity when no reorder is
     * active or the row was not part of the last frame.
     */
    public function logicalColumn(int $row, int $visualCol): int
    {
        if (null === $this->reorder || !isset($this->lastLines[$row])) {
            return $visualCol;
        }

        return Bidi::visualToLogicalColumn($this->lastLines[$row], $visualCol, true);
    }

    public function processRender(): void
    {
        $reorder = $this->reorder;
        if (null === $reorder) {
            parent::processRender();

            return;
        }

        $requested = new \ReflectionProperty(Tui::class, 'renderRequested');
        if (true !== $requested->getValue($this)) {
            return;
        }
        $requested->setValue($this, false);

        /** @var ContainerWidget $root */
        $root = new \ReflectionProperty(Tui::class, 'root')->getValue($this);
        /** @var Renderer $renderer */
        $renderer = new \ReflectionProperty(Tui::class, 'renderer')->getValue($this);
        /** @var ScreenWriter $screenWriter */
        $screenWriter = new \ReflectionProperty(Tui::class, 'screenWriter')->getValue($this);

        $terminal = $this->getTerminal();
        $lines = array_values($renderer->render($root, $terminal->getColumns(), $terminal->getRows()));
        $this->lastLines = $lines; // pre-reorder, row-indexed, for click column mapping

        $screenWriter->writeLines(array_map(static fn (string $line): string => $reorder($line), $lines));
    }
}
