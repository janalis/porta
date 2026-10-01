<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsIdeService;
use Porta\Container\AsWidget;
use Symfony\Component\Tui\Input\Key;

/**
 * The Documentation window's page ({@see MarkdownPageWidget}): its links walk
 * the docs tree in place, with the back/forward history that walk leaves, and
 * it keeps whatever page the reader navigated to across the window closing and
 * reopening. Esc closes the window, as it closes every window
 * ({@see \Porta\Help\HelpViewController}).
 */
#[AsIdeService]
#[AsWidget('help-viewer')]
final class HelpViewerWidget extends MarkdownPageWidget
{
    /** What Esc does on the page: close its window. */
    private ?\Closure $onExit = null;

    public function onExit(\Closure $exit): void
    {
        $this->onExit = $exit;
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        if (null !== $this->onExit && $this->getKeybindings()->matches($data, 'exit_view')) {
            ($this->onExit)();

            return;
        }
        parent::handleInput($data);
    }

    /** @return array<string, string[]> */
    #[\Override]
    protected static function getDefaultKeybindings(): array
    {
        return [...parent::getDefaultKeybindings(), 'exit_view' => [Key::ESCAPE]];
    }

    #[\Override]
    public function paneId(): string
    {
        return 'help';
    }

    /**
     * Empty on purpose: a help page contributes no right-hand segment, so the
     * status bar keeps showing the editor's diagnostics/LSP legend — which is
     * exactly what it did when this was a branch in the provider.
     */
    #[\Override]
    public function statusRight(): string
    {
        return '';
    }

    /**
     * Adopt the tab's page — but only when this pane is empty. Switching back to
     * a help tab keeps whatever the user navigated to, which is why the caller
     * used to ask `null === path()` before calling at all; the rule belongs
     * here, where "what this pane is showing" is known. A live refresh of the
     * page on disk goes through {@see refreshPage()} instead.
     *
     * @param mixed $payload the page markdown this pane's loader read
     */
    #[\Override]
    public function show(string $path, mixed $payload): void
    {
        if (null !== $this->path) {
            return;
        }
        $this->refreshPage($path, (string) $payload);
    }

    /** Re-render a page whose file changed, replacing what is on screen. */
    public function refreshPage(string $path, string $markdown): void
    {
        $this->adopt($path, $markdown, fromTop: true);
    }

    /**
     * The help tab closed: the page, the scroll and the whole back/forward
     * history go with it. Unconditional on $path deliberately — there is only
     * ever one help tab, and after navigation this pane's own path is the page
     * the user walked to rather than the tab's.
     */
    #[\Override]
    public function forget(string $path): void
    {
        $this->clear();
    }
}
