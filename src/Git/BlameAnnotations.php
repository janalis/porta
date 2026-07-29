<?php

declare(strict_types=1);

namespace Porta\Git;

use Porta\Editor\ActiveFileProvider;
use Porta\Editor\EditorDecorationSink;
use Porta\Event\BufferSaved;
use Porta\Event\BufferSwitched;
use Porta\I18n\Translator;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Tui\Tui;

/**
 * The toggleable whole-file blame mode: each line gets a dim trailing
 * `sha author date` annotation from one batch `git blame --line-porcelain`.
 * Re-applies on buffer switches and saves while enabled (the widget clears
 * its annotations on every document show). Like the git gutter marks, the
 * annotations are computed against the file on disk — they drift against
 * unsaved edits until the next save re-syncs them.
 */
final class BlameAnnotations
{
    private bool $enabled;

    public function __construct(
        private readonly GitInfo $git,
        // Two roles of the same widget: the active file scopes the blame run,
        // the decoration sink receives the per-line annotations.
        private readonly ActiveFileProvider $activeFile,
        private readonly EditorDecorationSink $decorations,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly Tui $tui,
        ?GitSettings $gitSettings = null,
    ) {
        // The "Show blame annotations by default" setting seeds the initial
        // state; the first buffer switch then fetches them (see onBufferSwitched).
        $this->enabled = $gitSettings?->blameDefault() ?? false;
    }

    public function toggle(): void
    {
        if ($this->enabled) {
            $this->enabled = false;
            $this->decorations->setLineAnnotations([]);
            $this->notifier->flash($this->translator->trans('Blame annotations hidden'));

            return;
        }
        if (!$this->git->isAvailable() || null === $this->activeFile->getFilePath()) {
            $this->notifier->flash($this->translator->trans('Git not available'));

            return;
        }
        $this->enabled = true;
        $this->notifier->flash($this->translator->trans('Blame annotations shown'));
        $this->refresh();
    }

    #[AsEventListener]
    public function onBufferSwitched(BufferSwitched $event): void
    {
        if ($this->enabled && !$event->buffer->isViewer()) {
            $this->refresh();
        }
    }

    #[AsEventListener]
    public function onBufferSaved(BufferSaved $event): void
    {
        if ($this->enabled) {
            $this->refresh();
        }
    }

    private function refresh(): void
    {
        $path = $this->activeFile->getFilePath();
        if (null === $path) {
            return;
        }
        $this->git->blameFile($path, function (array $lines) use ($path): void {
            // Stale or superseded: toggled off, or the buffer changed mid-flight.
            if (!$this->enabled || $path !== $this->activeFile->getFilePath()) {
                return;
            }
            $annotations = [];
            foreach ($lines as $no => $entry) {
                $annotations[$no] = trim(\sprintf('%s %s %s', $entry['sha'], $entry['author'], $entry['date']));
            }
            $this->decorations->setLineAnnotations($annotations);
            $this->tui->requestRender();
        });
    }
}
