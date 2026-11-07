<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\I18n\Translator;

/**
 * What applying a {@see WorkspaceEditor} payload actually did.
 *
 * A bare count was not enough to tell the truth: a file that could not be
 * written was dropped from it silently, so "Renamed in 3 files" could mean two
 * files renamed and one left behind. The three callers each report differently,
 * which is what earns this a type rather than an int.
 */
final readonly class WorkspaceEditOutcome
{
    /**
     * @param int                 $touched files actually edited
     * @param list<string>        $skipped files named by the edit that could not be written (missing, read-only)
     * @param string|null         $refused when set, NOTHING was applied — a resource operation the edit
     *                                     required could not be honoured, and half of it is worse than none
     * @param int                 $operations creates, renames and deletes that went through — an edit
     *                                        that changed no text may still have changed the tree
     */
    public function __construct(
        public int $touched,
        public array $skipped = [],
        public ?string $refused = null,
        public int $operations = 0,
    ) {}

    /** The whole edit was declined; the workspace is untouched. */
    public function isRefused(): bool
    {
        return null !== $this->refused;
    }

    /** Nothing to say: nothing refused, touched or left behind — an edit that changed nothing. */
    public function isEmpty(): bool
    {
        return !$this->isRefused() && 0 === $this->touched && [] === $this->skipped;
    }

    /**
     * What to tell the user: $success when it all applied, else that it was
     * refused, or which part could not be written.
     */
    public function report(Translator $translator, string $success): string
    {
        return match (true) {
            $this->isRefused() => $translator->trans('Refused: %reason% — nothing was changed', ['%reason%' => (string) $this->refused]),
            [] === $this->skipped => $success,
            default => $translator->trans(
                'Applied to %count% file, %skipped% could not be written|Applied to %count% files, %skipped% could not be written',
                ['%count%' => $this->touched, '%skipped%' => \count($this->skipped)],
            ),
        };
    }
}
