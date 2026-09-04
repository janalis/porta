<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Widget\CodeEditorWidget;
use Porta\Workbench\Notifier;

/**
 * PhpStorm's Alt+Down / Alt+Up: step between the declarations in the file
 * without leaving the editor.
 *
 * The Structure popup already lists them and the breadcrumbs already name the
 * one you are in; this is the keyboard verb between the two — you stay on the
 * code and move a member at a time.
 *
 * **No new scan and no server round trip.** `CodeEditorWidget::scopes()` has
 * memoized these spans per document version since the breadcrumbs bar shipped,
 * and nothing else ever read them. Classes count as well as methods: the class
 * line is where its docblock is, and stopping there is useful rather than
 * something to skip past.
 *
 * **Wraps** at either end, and says so, because a silent no-op at the last
 * method reads as a broken key — the rule {@see DiagnosticNavigation} set.
 */
#[AsIdeService]
final readonly class MemberNavigation
{
    public function __construct(
        private CodeEditorWidget $editor,
        private Notifier $notifier,
        private Translator $translator,
    ) {}

    public function next(): void
    {
        $this->go(MemberStep::Next);
    }

    public function previous(): void
    {
        $this->go(MemberStep::Previous);
    }

    private function go(MemberStep $step): void
    {
        $members = self::membersOf($this->editor->scopes());
        if ([] === $members) {
            // Also the answer for a non-PHP buffer, where scopes() is empty:
            // there is nothing to step through either way.
            $this->notifier->flash($this->translator->trans('No declarations in this file'));

            return;
        }

        if (MemberStep::Previous === $step) {
            // One direction of travel, read backwards: everything below works
            // on "the next member past the caret".
            $members = array_reverse($members);
        }
        $caret = $this->editor->getDocument()->getCursorLine();
        $target = null;
        foreach ($members as $member) {
            if (MemberStep::Next === $step ? $member['line'] <= $caret : $member['line'] >= $caret) {
                continue;
            }

            $target = $member;
            break;
        }

        // Falling back to $members[0] IS wrapping to the far end, because
        // $members runs the way we are travelling.
        $wrapped = null === $target;
        $target ??= $members[0];

        $this->editor->jumpTo($target['line'], 0);
        $this->notifier->flash(
            $wrapped
                ? $this->translator->trans('Wrapped to %member%', ['%member%' => $target['label']])
                : $target['label'],
        );
    }

    /**
     * The declarations worth stopping on, caret-line ordered.
     *
     * A namespace is dropped: it is the whole file, so stepping to it is
     * stepping nowhere. The scanner reports 1-based lines and the document
     * counts from zero.
     *
     * NOT re-sorted: {@see BreadcrumbScanner::scan()} already returns scopes by
     * ascending `startLine`, and a filter preserves that. A sort here would be
     * dead code no test could kill — the raw stack order (a class pops AFTER
     * its methods) is exactly what that sort exists to fix, at the source.
     *
     * @param list<array{label: string, startLine: int, endLine: int, kind: string}> $scopes
     *
     * @return list<array{label: string, line: int}>
     */
    private static function membersOf(array $scopes): array
    {
        $members = [];
        foreach ($scopes as $scope) {
            if ('namespace' === $scope['kind']) {
                continue;
            }

            $members[] = ['label' => $scope['label'], 'line' => $scope['startLine'] - 1];
        }

        return $members;
    }
}
