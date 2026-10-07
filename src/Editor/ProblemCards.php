<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Lsp\FixLookup;
use Porta\Lsp\FixState;
use Porta\Lsp\QuickFixProbe;
use Porta\Ui\KeyHints;
use Porta\Ui\Role;
use Porta\Ui\Tooltip;
use Porta\Ui\TooltipBody;
use Porta\Ui\TooltipLine;
use Porta\Widget\CodeEditorWidget;

/**
 * PhpStorm's problem card: what a squiggle means, said where it is. Each
 * problem under the pointer, worst first — its severity glyph and message,
 * then where it came from — and, once the language servers have answered,
 * the fix Alt+Shift+Enter would apply and the list Alt+Enter would open,
 * each with the key the live keymap binds.
 *
 * The same body heads the Error Description popup (Ctrl+F1) and the
 * documentation popup (Shift+F1) when the caret sits on a problem.
 */
#[AsIdeService]
final readonly class ProblemCards
{
    /** A problem's message is read, not glanced at: the card may run wider and taller than a hint's. */
    private const int MAX_TEXT = 60;

    private const int MAX_LINES = 14;

    public function __construct(
        private CodeEditorWidget $editor,
        private QuickFixProbe $fixes,
        private Translator $translator,
    ) {}

    /**
     * The card's body for problems on $line, worst first. The fix lines come
     * when the servers answer; $onUpdate then hears the body with them.
     *
     * @param non-empty-list<array{severity: DiagnosticSeverity, message: string, col: int, endCol?: int, code?: int|string, source?: string, tags?: list<int>, data?: mixed}> $diags
     * @param \Closure(TooltipBody): void $onUpdate
     */
    public function body(array $diags, int $line, \Closure $onUpdate): TooltipBody
    {
        $lookup = $this->fixes->lookup($line, $diags[0], fn (FixLookup $answer) => $onUpdate($this->compose($diags, $answer)));

        return $this->compose($diags, $lookup);
    }

    /**
     * The card for the problems under an editor-relative cell, or null when
     * there are none. Its arrow points at the squiggle, not at the pointer,
     * so the card stays put while the pointer slides along it.
     *
     * @param \Closure(Tooltip): void $refresh hears the card again when its fix arrives
     */
    public function tooltipAt(int $relRow, int $relCol, EditorViewport $vp, \Closure $refresh): ?Tooltip
    {
        $at = $this->editor->mouse->problemsAt($relRow, $relCol, $vp);
        if (null === $at) {
            return null;
        }
        ['line' => $line, 'displayCol' => $displayCol, 'diagnostics' => $diags] = $at;
        [$start, $end] = DiagnosticSpan::display($this->editor->getDocument(), $line, $diags[0]);
        $colStart = \max($vp->gutterWidth, $relCol - ($displayCol - $start));
        $endCell = \PHP_INT_MAX === $end ? $vp->stripeCol : $relCol + ($end - $displayCol);
        $colEnd = \max($colStart + 1, \min($vp->stripeCol, $endCell));
        $key = 'problem:'.$line.':'.\hash('xxh128', \serialize(\array_map(static fn (array $d): array => [$d['col'], $d['message']], $diags)));
        $card = static fn (TooltipBody $body): Tooltip => new Tooltip($key, $body->plain(), $relRow, $colStart, $colEnd, body: $body);

        return $card($this->body($diags, $line, static fn (TooltipBody $body) => $refresh($card($body))));
    }

    /**
     * @param non-empty-list<array{severity: DiagnosticSeverity, message: string, code?: int|string, source?: string, ...}> $diags
     */
    private function compose(array $diags, FixLookup $fix): TooltipBody
    {
        $lines = [];
        foreach ($diags as $diag) {
            $glyph = $diag['severity']->glyph().' ';
            $lines[] = new TooltipLine(\trim((string) \preg_replace('/\s+/u', replacement: ' ', subject: $diag['message'])), lead: $glyph, leadRole: $diag['severity']->role());
            $origin = \implode(' · ', \array_filter([$diag['source'] ?? '', (string) ($diag['code'] ?? '')], static fn (string $part): bool => '' !== $part));
            if ('' !== $origin) {
                $lines[] = new TooltipLine($origin, role: Role::Faint, lead: \str_repeat(' ', \max(0, \mb_strwidth($glyph))));
            }
        }
        $keys = $this->editor->getKeybindings();
        if (FixState::Found === $fix->state) {
            $lines[] = new TooltipLine($this->translator->trans('Fix: %title%', ['%title%' => (string) $fix->title]), KeyHints::key('apply_quick_fix', $keys));
        }
        if ($fix->actions > 0) {
            $lines[] = new TooltipLine($this->translator->trans('More actions…'), KeyHints::key('code_action', $keys));
        }

        return new TooltipBody($lines, self::MAX_TEXT, self::MAX_LINES);
    }
}
