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
use Porta\Widget\ViewerTabHandler;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Tui;

/** The IDE-side implementation of the help viewer's link and tab hooks. */
final readonly class HelpViewerActions implements HelpViewerHandler
{
    use DelegatesTabVerbs;

    public function __construct(
        private BufferService $bufferService,
        private BufferNavigator $navigator,
        private DocsUrlOpener $docsUrls,
        private Notifier $notifier,
        private Translator $translator,
        private FindBarWidget $findBar,
        private HelpViewerWidget $helpViewer,
        private Tui $tui,
        private string $docsDir,
    ) {}

    /** Open the find bar on the docs page (the viewer is its own FindTarget). */
    #[\Override]
    public function openFind(): void
    {
        $this->findBar->open($this->helpViewer, FindMode::Find);
        $this->tui->setFocus($this->findBar);
        $this->tui->requestRender();
    }

    #[\Override]
    public function openHelp(string $path): void
    {
        $this->bufferService->showHelpPage($path);
    }

    #[\Override]
    public function openFile(string $path): void
    {
        $this->bufferService->openFile($path, focus: OpenFocus::Editor);
    }

    #[\Override]
    public function openUrl(string $url): void
    {
        $this->docsUrls->open($url);
    }

    #[\Override]
    public function missingTarget(string $target): void
    {
        $this->notifier->error($this->translator->trans('Cannot read %file%', ['%file%' => $target]));
    }

    #[\Override]
    public function labelFor(string $path): string
    {
        return \realpath($path) === \realpath($this->docsDir.'/README.md')
            ? $this->translator->trans('Documentation')
            : \basename($path, suffix: '.md');
    }

    #[\Override]
    public function backEmpty(): void
    {
        $this->notifier->flash($this->translator->trans('Nowhere to go back to'));
    }

    private function tabs(): ViewerTabHandler
    {
        return $this->navigator;
    }
}
