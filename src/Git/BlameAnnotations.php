<?php

declare(strict_types=1);

namespace Porta\Git;

use Porta\Event\BufferSaved;
use Porta\Event\BufferSwitched;
use Porta\I18n\Translator;
use Porta\Ui\Notifier;
use Porta\Widget\CodeEditorWidget;
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
        private readonly CodeEditorWidget $editor,
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
            $this->editor->setLineAnnotations([]);
            $this->notifier->flash($this->translator->trans('Blame annotations hidden'));
            $this->tui->requestRender();

            return;
        }
        if (!$this->git->isAvailable() || null === $this->editor->getFilePath()) {
            $this->notifier->flash($this->translator->trans('Git not available'));
            $this->tui->requestRender();

            return;
        }
        $this->enabled = true;
        $this->notifier->flash($this->translator->trans('Blame annotations shown'));
        $this->refresh();
    }

    #[AsEventListener]
    public function onBufferSwitched(BufferSwitched $event): void
    {
        if ($this->enabled && !$event->buffer->isImage() && !$event->buffer->isHelp()) {
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
        $path = $this->editor->getFilePath();
        if (null === $path) {
            return;
        }
        $this->git->blameFile($path, function (array $lines) use ($path): void {
            // Stale or superseded: toggled off, or the buffer changed mid-flight.
            if (!$this->enabled || $path !== $this->editor->getFilePath()) {
                return;
            }
            $annotations = [];
            foreach ($lines as $no => $entry) {
                $annotations[$no] = trim(\sprintf('%s %s %s', $entry['sha'], $entry['author'], $entry['date']));
            }
            $this->editor->setLineAnnotations($annotations);
            $this->tui->requestRender();
        });
    }
}
