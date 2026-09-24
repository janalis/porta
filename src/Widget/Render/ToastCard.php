<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Overlay\State\Toast;
use Porta\Overlay\State\ToastSeverity;
use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;

/**
 * One corner notification card in the modal design language: the rounded
 * frame in the toast's severity role (error red, warning amber, info blue,
 * success green — the diagnostics palette), the severity glyph on the top
 * border, the tab strips' × affordance, and the message WRAPPED across rows
 * rather than truncated — a clipped error message is no message, and the E2e
 * suite greps full flash texts off the terminal.
 *
 * Every returned row is exactly $innerWidth + {@see PopupFrame::CHROME_COLS}
 * visible columns — the compositor invariant.
 */
final class ToastCard
{
    /** Wrap cap: a message longer than this many rows ellipsizes on the last. */
    public const int MAX_MESSAGE_ROWS = 6;

    /**
     * The frame says the severity by shape as well as colour — an error's is
     * double, a warning's heavy, the rest rounded — so a failure never reads
     * like a "Saved" under the monochrome theme. The × under the pointer is
     * underlined as well as brightened. $hint, when given, is set into the
     * bottom border: the key to the notification log on an error, or how many
     * more are waiting on the last card.
     *
     * @return list<string> the framed card rows
     */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain here, not a hidden second responsibility.
    public static function render(Toast $toast, int $innerWidth, Styles $styles, bool $closeHovered = false, string $hint = ''): array
    {
        $accent = $styles->of($toast->severity->role());
        $close = $closeHovered ? $styles->of(Role::CurrentLineNumber)->withUnderline()->apply(Glyphs::CLOSE) : TabCloseGlyph::inactive($styles);
        [$topLeft, $topRight, $bottomLeft, $bottomRight, $rule, $side] = self::frame($toast->severity)->glyphs();

        // `╭ ✗ ──── ×╮`: glyph chrome is 4 columns (corner, spaces, glyph),
        // close chrome 3 (space, ×, corner) — the × lands at width − 2, the
        // exact cell ModalBox::closeHit answers for.
        $fill = \max(0, $innerWidth - 5);
        $top = $accent->apply($topLeft.' ').$accent->withBold()->apply($toast->severity->glyph()).' '.$accent->apply(\str_repeat($rule, $fill)).' '.$close.$accent->apply($topRight);

        $rail = $accent->apply($side);
        $rows = [AnsiText::truncate($top, \max(0, $innerWidth + PopupFrame::CHROME_COLS), pad: true)];
        $lines = AnsiText::wrap($toast->message, \max(1, $innerWidth - 2));
        if (\count($lines) > self::MAX_MESSAGE_ROWS) {
            $lines = \array_slice($lines, offset: 0, length: self::MAX_MESSAGE_ROWS);
            $lines[self::MAX_MESSAGE_ROWS - 1] = AnsiText::truncate($lines[self::MAX_MESSAGE_ROWS - 1].'…', \max(1, $innerWidth - 2));
        }
        foreach ($lines as $line) {
            $body = $styles->of(Role::Bright)->apply(AnsiText::truncate(' '.$line, \max(0, $innerWidth), pad: true));
            $rows[] = $rail.$body.$rail;
        }
        // The hint sits right in the border, `╰──── Alt+9 log ─╯`, when it fits.
        $hintText = '' === $hint || (\mb_strwidth($hint) + 3) > $innerWidth ? '' : ' '.$hint.' ';
        $bottomFill = \max(0, $innerWidth - \mb_strwidth($hintText) - ('' === $hintText ? 0 : 1));
        $rows[] = $accent->apply($bottomLeft.\str_repeat($rule, $bottomFill)).('' === $hintText ? '' : $styles->of(Role::Dim)->apply($hintText).$accent->apply($rule)).$accent->apply($bottomRight);

        return $rows;
    }

    /** An error is double-framed, a warning heavy, the rest rounded. */
    private static function frame(ToastSeverity $severity): FieldState
    {
        return match ($severity) {
            ToastSeverity::Error => FieldState::Danger,
            ToastSeverity::Warn => FieldState::Focused,
            ToastSeverity::Info, ToastSeverity::Success => FieldState::Idle,
        };
    }
}
