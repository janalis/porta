<?php

declare(strict_types=1);

namespace Porta\Help;

use Porta\Editor\BufferNavigator;
use Porta\Editor\BufferService;
use Porta\Editor\FindMode;
use Porta\Editor\OpenFocus;
use Porta\I18n\Translator;
use Porta\Support\DocsUrlOpener;
use Porta\Widget\FindBarWidget;
use Porta\Widget\HelpViewerHandler;
use Porta\Widget\HelpViewerWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Tui;

/** The IDE-side implementation of the help viewer's link and tab hooks. */
final class HelpViewerActions implements HelpViewerHandler
{
    public function __construct(
        private readonly BufferService $bufferService,
        private readonly BufferNavigator $navigator,
        private readonly DocsUrlOpener $docsUrls,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly FindBarWidget $findBar,
        private readonly HelpViewerWidget $helpViewer,
        private readonly Tui $tui,
        private readonly string $docsDir,
    ) {}

    /** Open the find bar on the docs page (the viewer is its own FindTarget). */
    public function openFind(): void
    {
        $this->findBar->open($this->helpViewer, FindMode::Find);
        $this->tui->setFocus($this->findBar);
        $this->tui->requestRender();
    }

    public function openHelp(string $path): void
    {
        $this->bufferService->showHelpPage($path);
    }

    public function openFile(string $path): void
    {
        $this->bufferService->openFile($path, focus: OpenFocus::Editor);
    }

    public function openUrl(string $url): void
    {
        $this->docsUrls->open($url);
    }

    public function missingTarget(string $target): void
    {
        $this->notifier->flash($this->translator->trans('Cannot read %file%', ['%file%' => $target]));
    }

    public function labelFor(string $path): string
    {
        return realpath($path) === realpath($this->docsDir.'/README.md')
            ? $this->translator->trans('Documentation')
            : basename($path, suffix: '.md');
    }

    public function backEmpty(): void
    {
        $this->notifier->flash($this->translator->trans('Nowhere to go back to'));
    }

    public function cycleBuffer(int $offset): void
    {
        $this->navigator->cycle($offset);
    }

    public function closeBuffer(): void
    {
        $this->navigator->closeActive();
    }

    public function openGotoFile(): void
    {
        $this->navigator->openGotoFile();
    }

    public function saveAll(): void
    {
        $this->navigator->saveAll();
    }

    /**
     * The help pane scrolls rather than zooms, so a "view changed" ping would
     * repaint a status segment it does not contribute — but the pane contract is
     * one interface, and the navigator already knows how to refresh the bar.
     */
    public function viewChanged(): void
    {
        $this->navigator->viewChanged();
    }
}
