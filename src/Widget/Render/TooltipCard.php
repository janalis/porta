<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A tooltip card: the popups' rounded frame around a line or a few of words,
 * one space of padding each side, set flush against the element it explains.
 *
 * A line may carry a key after a tab — `Match case\tAlt+C` — which is set
 * apart at the line's end and dimmed, the way a menu shows a shortcut.
 *
 * Every returned row is exactly {@see width()} visible columns — the
 * compositor invariant.
 */
final class TooltipCard
{
    /** The widest a card's text runs before it wraps, in columns. */
    public const int MAX_TEXT = 48;

    /** A tall text is cut here and ends in an ellipsis: a tooltip is a glance, not a page. */
    public const int MAX_LINES = 8;

    /** Columns between a line's words and its key. */
    private const int KEY_GAP = 2;

    /**
     * The text laid out for at most $maxText columns: each `\n` a line, each
     * line wrapped — except one carrying a key, which is cut instead so the
     * key stays on its row.
     *
     * @return list<array{string, string}> each line's words and its key ('' for none)
     */
    public static function lines(string $text, int $maxText): array
    {
        $maxText = \max(1, $maxText);
        $lines = [];
        foreach (\explode("\n", $text) as $paragraph) {
            [$words, $key] = [...\explode("\t", $paragraph, limit: 2), ''];
            if ('' !== $key) {
                $room = \max(1, $maxText - AnsiUtils::visibleWidth($key) - self::KEY_GAP);
                $lines[] = [AnsiText::truncate($words, $room), $key];
                continue;
            }
            foreach (AnsiText::wrap($words, $maxText) as $wrapped) {
                $lines[] = [$wrapped, ''];
            }
        }
        if (\count($lines) > self::MAX_LINES) {
            $lines = \array_slice($lines, offset: 0, length: self::MAX_LINES);
            $lines[self::MAX_LINES - 1] = [AnsiText::truncate($lines[self::MAX_LINES - 1][0].' '.Glyphs::ELLIPSIS, $maxText), ''];
        }

        return $lines;
    }

    /**
     * The card's full width for these lines: the widest line, its padding and the frame.
     *
     * @param list<array{string, string}> $lines
     */
    public static function width(array $lines): int
    {
        $widest = 1;
        foreach ($lines as [$words, $key]) {
            $widest = \max($widest, AnsiUtils::visibleWidth($words) + ('' === $key ? 0 : self::KEY_GAP + AnsiUtils::visibleWidth($key)));
        }

        return $widest + 2 + PopupFrame::CHROME_COLS;
    }

    /**
     * The framed card.
     *
     * @param list<array{string, string}> $lines
     *
     * @return list<string> each exactly {@see width()} columns
     */
    public static function render(array $lines, Styles $styles): array
    {
        $inner = \max(0, self::width($lines) - PopupFrame::CHROME_COLS);
        $accent = $styles->of(Role::TabAccent);
        $body = $styles->of(Role::PopupRow);
        $key = $styles->of(Role::Muted);
        $rail = $accent->apply(Glyphs::FRAME_VERTICAL);

        $rows = [$accent->apply(Glyphs::MODAL_TOP_LEFT.\str_repeat(Glyphs::FRAME_HORIZONTAL, $inner).Glyphs::MODAL_TOP_RIGHT)];
        foreach ($lines as [$words, $keyText]) {
            $fill = \max(0, $inner - 2 - AnsiUtils::visibleWidth($words) - ('' === $keyText ? 0 : AnsiUtils::visibleWidth($keyText)));
            $rows[] = $rail.$body->apply(' '.$words.\str_repeat(' ', $fill)).('' === $keyText ? '' : $key->apply($keyText)).$body->apply(' ').$rail;
        }
        $rows[] = $accent->apply(Glyphs::MODAL_BOTTOM_LEFT.\str_repeat(Glyphs::FRAME_HORIZONTAL, $inner).Glyphs::MODAL_BOTTOM_RIGHT);

        return $rows;
    }
}
