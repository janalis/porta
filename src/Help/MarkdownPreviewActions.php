<?php

declare(strict_types=1);

namespace Porta\Help;

use Porta\Editor\BufferNavigator;
use Porta\Editor\BufferService;
use Porta\Editor\FindMode;
use Porta\Editor\OpenFocus;
use Porta\I18n\Translator;
use Porta\Support\DocsUrlOpener;
use Porta\Support\ProjectPaths;
use Porta\Widget\CodeEditorWidget;
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
        private CodeEditorWidget $editor,
    ) {}

    /**
     * The preview was clicked: put the caret on $word where the clicked block
     * begins in the text — searched from $sourceLine to the block's end, since
     * a wrapped paragraph is one rendered block of several source lines — or
     * at the block's start when the word is not there as written (markup can
     * part a rendered word from its source). The caret line is centred, and
     * beside the preview the editor takes the keys; a lone preview keeps them
     * and the caret waits there for the text to come back.
     */
    public function revealSource(int $sourceLine, string $word): void
    {
        $document = $this->editor->getDocument();
        $needle = \trim($word, characters: '.,;:!?()[]{}*_`"\'');
        $line = \min($sourceLine, $document->getLineCount() - 1);
        $byte = 0;
        for ($n = $line; '' !== $needle && $n < $document->getLineCount() && ($n === $line || '' !== \trim($document->getLine($n))); ++$n) {
            $found = \strpos($document->getLine($n), $needle);
            if (false !== $found) {
                [$line, $byte] = [$n, $found];
                break;
            }
        }
        $this->editor->jumpTo($line, $document->byteColToUtf16($line, $byte));
        if (null !== $this->editor->getContext()) {
            $this->tui->setFocus($this->editor); // on screen: the preview is beside it
        }
        $this->tui->requestRender();
    }

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
        return \realpath($path) === \realpath($this->paths->root().'/README.md')
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
