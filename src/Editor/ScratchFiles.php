<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Config\LanguageCatalog;
use Porta\Config\PortaHome;
use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Workbench\Notifier;
use Symfony\Component\Filesystem\Filesystem;

/**
 * PhpStorm's scratch files: a real buffer to try something in, that belongs to
 * no project and dirties no repository.
 *
 * They live in `<home>/.porta/scratches/`, beside the global config and the
 * global plugins, because that is what makes them scratch files rather than
 * project files — a snippet you paste to reformat, or a query you are drafting,
 * outlives the project you happened to have open.
 *
 * Picking a language rather than typing a name is the whole interaction: the
 * extension is the only thing the editor needs to highlight, indent and hand
 * the file to a language server, and `scratch_7.php` is a better name than
 * whatever you would have invented.
 */
#[AsIdeService]
final readonly class ScratchFiles
{
    /**
     * A fallback for the pick that is not a language: no grammar, no server,
     * no ceremony. `.txt` rather than no extension at all, so the file has one
     * obvious kind and reopening it is not a guess.
     */
    private const string PLAIN_TEXT = 'txt';

    public function __construct(
        private ModalHost $editor,
        private FileOpener $files,
        private Notifier $notifier,
        private Translator $translator,
        private Filesystem $fs = new Filesystem(),
    ) {}

    /** Ask which language, then mint the file and open it. */
    public function open(): void
    {
        // The catalogued languages only. LanguageMap knows fifty grammars
        // because it must recognise whatever a project contains; a list of
        // fifty to arrow through is a worse offer than a list of thirteen.
        $items = [];
        foreach (LanguageCatalog::EXTENSIONS as $id => $extension) {
            $items[] = ['label' => LanguageCatalog::displayName($id), 'hint' => '.'.$extension, 'value' => $extension];
        }
        $items[] = ['label' => $this->translator->trans('Plain text'), 'hint' => '.'.self::PLAIN_TEXT, 'value' => self::PLAIN_TEXT];

        $this->editor->openListOverlay($this->translator->trans('New Scratch File'), $items, $this->create(...));
    }

    private function create(string $extension): void
    {
        $base = PortaHome::base();
        if (null === $base) {
            // Neither PORTA_HOME nor HOME: the same environment in which the
            // global config layer goes away. Say so rather than write into
            // whatever the working directory happens to be.
            $this->notifier->warn($this->translator->trans('No home directory for scratch files'));

            return;
        }

        $dir = $base.'/.porta/scratches';
        $path = $this->nextFree($dir, $extension);
        try {
            $this->fs->dumpFile($path, content: '');
        } catch (\Throwable) {
            $this->notifier->error($this->translator->trans('Cannot create %file%', ['%file%' => \basename($path)]));

            return;
        }

        $this->files->openFile($path, OpenFocus::Editor);
    }

    /**
     * `scratch_1.php`, then `scratch_2.php`… counted per extension, so a run of
     * PHP scratches numbers 1, 2, 3 whatever else is in the directory.
     */
    private function nextFree(string $dir, string $extension): string
    {
        $n = 1;
        $path = \sprintf('%s/scratch_%d.%s', $dir, $n, $extension);
        while (\file_exists($path)) {
            $path = \sprintf('%s/scratch_%d.%s', $dir, ++$n, $extension);
        }

        return $path;
    }
}
