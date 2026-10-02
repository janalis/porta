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

    /** $path — the active file's when null — relative to the project root. */
    public function copyPath(?string $path = null): void
    {
        $path ??= $this->editor->getFilePath();
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

        $line = $this->editor->getDocument()->cursorLine;
        $symbol = self::symbolAt($this->editor->scopes(), $line, ProjectPaths::languageId($path));

        // 1-based, because a line number is for a human to read — and for the
        // `file:line` every compiler, linter and stack trace already prints.
        $this->put($symbol ?? $this->paths->relative($path).':'.($line + 1));
    }

    /**
     * The qualified symbol enclosing a 0-based line, spelled the way the
     * language does: `App\Http\Svc::run` in PHP, `Service.handle` everywhere
     * else.
     *
     * PHP is named and the rest share a default rather than each getting an
     * entry, because a scope carries no language semantics — only a label and a
     * kind — so anything finer would be a table of guesses about languages
     * nothing here tests. `.` is what JavaScript, TypeScript, Python, Java and
     * Go all write; a language that spells it `::` gets a reference that reads
     * a little wrong, which is a smaller cost than the table.
     *
     * Null when nothing but a namespace encloses the caret — `App\Http` on its
     * own names the file's neighbourhood, not a place in it, and the caller has
     * a better answer than that.
     *
     * @param list<array{label: string, startLine: int, endLine: int, kind: CodeScopeKind}> $scopes
     */
    private static function symbolAt(array $scopes, int $line, string $languageId): ?string
    {
        $isPhp = 'php' === $languageId;
        $qualifierJoin = $isPhp ? '\\' : '.';
        $memberJoin = $isPhp ? '::' : '.';
        $qualifier = '';
        $member = null;
        $hasType = false;
        foreach ($scopes as $scope) {
            if ($scope['startLine'] > ($line + 1) || ($line + 1) > $scope['endLine']) {
                continue;
            }
            if (CodeScopeKind::Function !== $scope['kind']) {
                // Everything that is not a member builds the qualifier; only a
                // TYPE in it earns the `::` below.
                if (CodeScopeKind::Type === $scope['kind']) {
                    $hasType = true;
                }
                $qualifier .= ('' === $qualifier ? '' : $qualifierJoin).$scope['label'];
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

        // In PHP `::` binds a member to a TYPE, while a free function in a
        // namespace is a name IN that namespace and joins the way the
        // namespace's own parts do — `App\helper`, which is what you would
        // type to call it. Languages that write `.` make no such distinction.
        return $qualifier.($hasType ? $memberJoin : $qualifierJoin).$member;
    }

    private function put(string $text): void
    {
        $this->clipboard->copy($text);
        $this->notifier->flash($this->translator->trans('Copied %text%', ['%text%' => $text]));
    }
}
