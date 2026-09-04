<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Support\Clipboard;
use Porta\Support\ProjectPaths;
use Porta\Widget\CodeEditorWidget;
use Porta\Workbench\Notifier;

/**
 * PhpStorm's Copy Path and Copy Reference, from the editor rather than the tree.
 *
 * The tree has had both path forms on its context menu for as long as it has
 * had one, but the place you want them is the file you are IN — a path to paste
 * into a message, or a reference to paste into a test filter or a stack-trace
 * hunt.
 *
 * A reference is the symbol when the caret is inside one (`App\Svc::run`) and
 * `path:line` when it is not, which is PhpStorm's rule and the useful one: a
 * symbol survives the file being edited underneath it, and a line number is
 * what you want when there is no symbol to name.
 */
#[AsIdeService]
final readonly class ReferenceCopier
{
    public function __construct(
        private CodeEditorWidget $editor,
        private ProjectPaths $paths,
        private Clipboard $clipboard,
        private Notifier $notifier,
        private Translator $translator,
    ) {}

    /** The active file's path, relative to the project root. */
    public function copyPath(): void
    {
        $path = $this->editor->getFilePath();
        if (null === $path) {
            return;
        }

        $this->put($this->paths->relative($path));
    }

    /** The symbol the caret is inside, or `path:line` when it is inside none. */
    public function copyReference(): void
    {
        $path = $this->editor->getFilePath();
        if (null === $path) {
            return;
        }

        $line = $this->editor->getDocument()->getCursorLine();
        $symbol = self::symbolAt($this->editor->scopes(), $line);

        // 1-based, because a line number is for a human to read — and for the
        // `file:line` every compiler, linter and stack trace already prints.
        $this->put($symbol ?? $this->paths->relative($path).':'.($line + 1));
    }

    /**
     * The qualified symbol enclosing a 0-based line: the namespace and type
     * joined with `\`, the member with `::`.
     *
     * Null when nothing but a namespace encloses the caret — `App\Http` on its
     * own names the file's neighbourhood, not a place in it, and the caller has
     * a better answer than that.
     *
     * @param list<array{label: string, startLine: int, endLine: int, kind: string}> $scopes
     */
    private static function symbolAt(array $scopes, int $line): ?string
    {
        $qualifier = '';
        $member = null;
        $hasType = false;
        foreach ($scopes as $scope) {
            if ($scope['startLine'] > ($line + 1) || ($line + 1) > $scope['endLine']) {
                continue;
            }
            if ('function' !== $scope['kind']) {
                // Everything that is not a member builds the qualifier; only a
                // TYPE in it earns the `::` below.
                if ('type' === $scope['kind']) {
                    $hasType = true;
                }
                $qualifier .= ('' === $qualifier ? '' : '\\').$scope['label'];
                continue;
            }

            // No continue, and not the first match: scopes arrive
            // outermost-first, so letting a later one overwrite is what makes
            // the INNERMOST enclosing member the one that gets named.
            $member = $scope['label'];
        }

        if (null === $member) {
            // On the class itself: its FQCN. Inside nothing but a namespace,
            // null — `App\Http` names the file's neighbourhood, not a place in
            // it, and the caller has a better answer than that.
            return $hasType ? $qualifier : null;
        }
        if ('' === $qualifier) {
            return $member;
        }

        // `::` binds a member to a TYPE. A free function in a namespace is a
        // name IN that namespace, so it joins the same way the namespace's
        // parts do — `App\helper`, which is also what you would type to call it.
        return $qualifier.($hasType ? '::' : '\\').$member;
    }

    private function put(string $text): void
    {
        $this->clipboard->copy($text);
        $this->notifier->flash($this->translator->trans('Copied %text%', ['%text%' => $text]));
    }
}
