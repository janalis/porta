<?php

declare(strict_types=1);

namespace Porta\Help;

use Porta\Editor\BufferNavigator;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\I18n\Translator;
use Porta\Support\DocsUrlOpener;
use Porta\Support\Files;
use Porta\Widget\HelpViewerHandler;
use Porta\Workbench\Notifier;

/**
 * The IDE-side implementation of the help viewer's link and tab hooks. The
 * pages live in the Documentation window ({@see HelpViewController}), so a
 * page link turns the window's page and Ctrl+F opens the window's own find
 * bar; anything that reaches past the window — a file link, a tab verb —
 * closes it first, as a menu command does.
 */
final readonly class HelpViewerActions implements HelpViewerHandler
{
    public function __construct(
        private BufferService $bufferService,
        private BufferNavigator $navigator,
        private DocsUrlOpener $docsUrls,
        private Notifier $notifier,
        private Translator $translator,
        private HelpViewController $window,
        private string $docsDir,
    ) {}

    /** Ctrl+F on the page: the window's own find bar. */
    #[\Override]
    public function openFind(): void
    {
        $this->window->openFind();
    }

    #[\Override]
    public function openHelp(string $path): void
    {
        $this->window->showPage($path);
    }

    /** A link to a file that is not a doc page: it opens in the editor, so the window gives way. */
    #[\Override]
    public function openFile(string $path): void
    {
        $this->window->close();
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
        // Compared resolved, and never false === false: inside the phar
        // realpath() fails for every page, which named them all Documentation.
        return Files::canonical($path) === Files::canonical($this->docsDir.'/README.md')
            ? $this->translator->trans('Documentation')
            : \basename($path, suffix: '.md');
    }

    #[\Override]
    public function backEmpty(): void
    {
        $this->notifier->flash($this->translator->trans('Nowhere to go back to'));
    }

    /** The tab verbs act on the editor's tabs, behind the window: it closes first. */
    #[\Override]
    public function cycleBuffer(int $offset): void
    {
        $this->window->close();
        $this->navigator->cycleBuffer($offset);
    }

    /** Alt+W on the page closes the window, as it closed the help tab. */
    #[\Override]
    public function closeBuffer(): void
    {
        $this->window->close();
    }

    #[\Override]
    public function openGotoFile(): void
    {
        $this->window->close();
        $this->navigator->openGotoFile();
    }

    #[\Override]
    public function saveAll(): void
    {
        $this->navigator->saveAll();
    }

    /** A doc page has no source to reveal. */
    #[\Override]
    public function revealSource(int $line, int $byteCol): void {}

    #[\Override]
    public function viewChanged(): void
    {
        $this->navigator->viewChanged();
    }
}
