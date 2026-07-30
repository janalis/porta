<?php

declare(strict_types=1);

namespace Porta\Bootstrap;

use Porta\Container\AsIdeService;
use Porta\Editor\EditorPanes;
use Porta\I18n\Translator;
use Porta\Widget\HelpViewerWidget;

/**
 * Registers the pane core ships — the help browser — into the same
 * registry a plugin's `registerPane()` reaches through
 * {@see \Porta\Editor\PaneRegistrar}.
 *
 * Core registering through the plugin extension point is what keeps the point
 * honest: it had three consumers inside `src/` before any plugin depended on
 * it, and when the two viewers moved out only their CODE changed — each took
 * its registration and its loader to its own `plugin.php` unaltered. What is
 * left here is the one pane core genuinely owns.
 *
 * A loader THROWS to refuse a file — too large to decode, not UTF-8 — and
 * `BufferService` flashes the message it carries. That is the one place a pane
 * gets to say "not this file" after its claim already said "mine".
 */
#[AsIdeService]
final class CorePanes
{
    public function __construct(
        private readonly EditorPanes $panes,
        private readonly HelpViewerWidget $helpViewer,
        private readonly Translator $translator,
    ) {}

    public function register(): void
    {
        $this->panes->register($this->helpViewer, $this->loadHelp(...));
    }

    /** A help page is text: read it, refusing anything that is not UTF-8. */
    private function loadHelp(string $path): string
    {
        $content = (string) file_get_contents($path);
        if ('' !== $content && !mb_check_encoding($content, encoding: 'UTF-8')) {
            throw new \RuntimeException($this->translator->trans('Not a UTF-8 text file'));
        }

        return $content;
    }
}
