<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Ui\TooltipBody;
use Porta\Ui\TooltipLine;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A tooltip card: the popups' rounded frame around a line or a few of words,
 * one space of padding each side, set flush against the element it explains.
 *
 * A line may carry a key after a tab — `Match case\tAlt+C` — which is set
 * apart at the line's end and dimmed, the way a menu shows a shortcut. A
 * {@see TooltipBody} adds a role per line and a lead before it (a problem's
 * severity glyph), kept on the line's first row when it wraps.
 *
 * Every returned row is exactly {@see width()} visible columns — the
 * compositor invariant.
 */
final class TooltipCard
{
    /** The widest a plain card's text runs before it wraps, in columns. */
    public const int MAX_TEXT = TooltipBody::MAX_TEXT;

    /** A tall text is cut here and ends in an ellipsis: a tooltip is a glance, not a page. */
    public const int MAX_LINES = TooltipBody::MAX_LINES;

    /** Columns between a line's words and its key. */
    private const int KEY_GAP = 2;

    /**
     * Plain text laid out for at most $maxText columns: each `\n` a line, each
     * line wrapped — except one carrying a key, which is cut instead so the
     * key stays on its row.
     *
     * @return list<TooltipLine> one per row
     */
    public static function lines(string $text, int $maxText): array
    {
        return self::layout(TooltipBody::fromText($text), $maxText);
    }

    /**
     * A body laid out for at most $maxText columns, one {@see TooltipLine}
     * per row, cut at the body's own line limit with an ellipsis.
     *
     * @return list<TooltipLine>
     */
    public static function layout(TooltipBody $body, int $maxText): array
    {
        $maxText = \max(1, $maxText);
        $rows = [];
        foreach ($body->lines as $line) {
            $leadWidth = AnsiUtils::visibleWidth($line->lead);
            if ('' !== $line->key) {
                $room = \max(1, $maxText - $leadWidth - AnsiUtils::visibleWidth($line->key) - self::KEY_GAP);
                $rows[] = new TooltipLine(AnsiText::truncate($line->text, $room), $line->key, $line->role, $line->lead, $line->leadRole);
                continue;
            }
            $lead = $line->lead;
            foreach (AnsiText::wrap($line->text, \max(1, $maxText - $leadWidth)) as $wrapped) {
                $rows[] = new TooltipLine($wrapped, '', $line->role, $lead, $line->leadRole);
                // Under its first row, a line's lead is only its indent.
                $lead = \str_repeat(' ', \max(0, $leadWidth));
            }
        }
        if (\count($rows) > $body->maxLines) {
            $rows = \array_slice($rows, offset: 0, length: $body->maxLines);
            $last = $rows[$body->maxLines - 1];
            $rows[$body->maxLines - 1] = new TooltipLine(
                AnsiText::truncate($last->text.' '.Glyphs::ELLIPSIS, \max(1, $maxText - AnsiUtils::visibleWidth($last->lead))),
                '',
                $last->role,
                $last->lead,
                $last->leadRole,
            );
        }

        return $rows;
    }

    /**
     * The card's full width for these rows: the widest one, its padding and the frame.
     *
     * @param list<TooltipLine> $lines
     */
    public static function width(array $lines): int
    {
        $widest = 1;
        foreach ($lines as $line) {
            $widest = \max($widest, AnsiUtils::visibleWidth($line->lead.$line->text) + ('' === $line->key ? 0 : self::KEY_GAP + AnsiUtils::visibleWidth($line->key)));
        }

        return $widest + 2 + PopupFrame::CHROME_COLS;
    }

    /**
     * The framed card.
     *
     * @param list<TooltipLine> $lines
     *
     * @return list<string> each exactly {@see width()} columns
     */
    public static function render(array $lines, Styles $styles): array
    {
        $inner = \max(0, self::width($lines) - PopupFrame::CHROME_COLS);

        $rows = [PopupFrame::top($inner, $styles)];
        foreach (self::rows($lines, $inner, $styles) as $row) {
            $rows[] = PopupFrame::rail($row, $inner, $styles);
        }
        $rows[] = PopupFrame::bottom($inner, $styles);

        return $rows;
    }

    /**
     * The rows' contents without the frame, each exactly $inner columns — for
     * a box that frames them itself, as the documentation popup does under
     * its problems.
     *
     * @param list<TooltipLine> $lines
     *
     * @return list<string>
     */
    public static function rows(array $lines, int $inner, Styles $styles): array
    {
        return \array_map(static fn (TooltipLine $line): string => self::row($line, $inner, $styles), $lines);
    }

    /** One row's content, exactly $inner columns: padding, lead, words, the fill, the key. */
    private static function row(TooltipLine $line, int $inner, Styles $styles): string
    {
        $body = Role::PopupRow === $line->role ? $styles->of(Role::PopupRow) : $styles->merge(Role::PopupRow, $line->role);
        $lead = '' === $line->lead ? '' : $styles->merge(Role::PopupRow, $line->leadRole ?? $line->role)->apply($line->lead);
        $fill = \max(0, $inner - 2 - AnsiUtils::visibleWidth($line->lead.$line->text) - AnsiUtils::visibleWidth($line->key));

        return $body->apply(' ').$lead.$body->apply($line->text.\str_repeat(' ', $fill)).('' === $line->key ? '' : $styles->of(Role::Muted)->apply($line->key)).$body->apply(' ');
    }
}
