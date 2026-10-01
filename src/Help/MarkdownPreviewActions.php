<?php

declare(strict_types=1);

namespace Porta\Help;

use Porta\Editor\BufferNavigator;
use Porta\Editor\BufferService;
use Porta\Editor\FindMode;
use Porta\Editor\OpenFocus;
use Porta\I18n\Translator;
use Porta\Support\DocsUrlOpener;
use Porta\Support\Files;
use Porta\Support\ProjectPaths;
use Porta\Widget\FindBarWidget;
use Porta\Widget\HelpViewerHandler;
use Porta\Widget\MarkdownPreviewWidget;
use Porta\Widget\ViewerTabHandler;
use Porta\Workbench\EditorArea;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Tui;

/**
 * Where the markdown preview's links lead: another markdown file opens as its
 * own tab — beside its preview, which keeps the keys, so reading goes on in
 * the page the link named — and anything else opens in the editor, as a help
 * page's link does. The breadcrumb walks the project, the root named after it.
 */
final readonly class MarkdownPreviewActions implements HelpViewerHandler
{
    use DelegatesTabVerbs;

    public function __construct(
        private BufferService $bufferService,
        private BufferNavigator $navigator,
        private DocsUrlOpener $docsUrls,
        private Notifier $notifier,
        private Translator $translator,
        private FindBarWidget $findBar,
        private MarkdownPreviewWidget $preview,
        private EditorArea $editorArea,
        private ProjectPaths $paths,
        private Tui $tui,
    ) {}

    /** Open the find bar on the preview's page (the preview is its own FindTarget). */
    #[\Override]
    public function openFind(): void
    {
        $this->findBar->open($this->preview, FindMode::Find);
        $this->tui->setFocus($this->findBar);
        $this->tui->requestRender();
    }

    /** Another markdown file: its own tab, and the keys stay with the page the reader is in. */
    #[\Override]
    public function openHelp(string $path): void
    {
        $this->bufferService->openFile($path);
        if ($this->editorArea->visiblePane() === $this->preview) {
            $this->tui->setFocus($this->preview);
        }
        $this->tui->requestRender();
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

    /** The project's README is the project, named after its directory; a page is its file name. */
    #[\Override]
    public function labelFor(string $path): string
    {
        return Files::canonical($path) === Files::canonical($this->paths->root().'/README.md')
            ? \basename($this->paths->root())
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
