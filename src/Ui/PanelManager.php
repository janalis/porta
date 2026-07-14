<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Widget\ResultsPanelFields;
use Porta\Widget\ResultsPanelWidget;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Owns the shared bottom results panel and the {@see ResultsPanelMode} it is
 * currently serving, plus the focus/repaint/flash choreography that every
 * panel-opening path repeated. Centralizing the mode here retires the coordinator's
 * hand-rolled `$panelMode` field and the copy-pasted "open → set results → focus →
 * render" sequence.
 */
final class PanelManager
{
    private ResultsPanelMode $mode = ResultsPanelMode::Search;

    public function __construct(
        private readonly Tui $tui,
        private readonly ResultsPanelWidget $panel,
        private readonly Notifier $notifier,
        private readonly EditorArea $editorArea,
    ) {}

    /** Which content the panel is currently serving (drives query/jump routing). */
    public function mode(): ResultsPanelMode
    {
        return $this->mode;
    }

    /**
     * Record the active mode without touching the panel — for paths that open it
     * through another collaborator (project search, command palette).
     */
    public function setMode(ResultsPanelMode $mode): void
    {
        $this->mode = $mode;
    }

    /** Open the panel in a live-query mode (e.g. symbol search) and focus it. */
    public function openQuery(ResultsPanelMode $mode, string $title): void
    {
        $this->mode = $mode;
        $this->panel->open($title, ResultsPanelFields::queryOnly());
        $this->tui->setFocus($this->panel);
        $this->tui->requestRender();
    }

    /**
     * Open the panel showing a static list of rows and focus it.
     *
     * @param list<array{path: string, line: int, text: string}> $rows
     */
    public function showList(ResultsPanelMode $mode, string $title, array $rows): void
    {
        $this->mode = $mode;
        $this->panel->open($title, ResultsPanelFields::resultsOnly());
        $this->panel->setResults($rows);
        $this->tui->setFocus($this->panel);
        $this->tui->requestRender();
    }

    /** Return focus to the editor area when the panel currently holds it, then repaint. */
    public function focusEditor(): void
    {
        $this->returnFocusFrom($this->panel);
    }

    /** Return focus to the editor area when $widget currently holds it, then repaint. */
    public function returnFocusFrom(AbstractWidget $widget): void
    {
        if ($this->tui->getFocus() === $widget) {
            $this->tui->setFocus($this->editorArea->widget());
        }
        $this->tui->requestRender();
    }

    /** Flash a transient status message and repaint. */
    public function flash(string $message): void
    {
        $this->notifier->flash($message);
    }
}
