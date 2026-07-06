<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Severity → styling for diagnostics, shared by the gutter, the in-editor
 * squiggle underlines, the inline end-of-line message and the right-margin error
 * stripe so they all read consistently. LSP severities: 1=error, 2=warning,
 * 3=info, 4=hint; anything else is treated as a hint.
 */
final class DiagnosticStyle
{
    /** The severity marker drawn in the right stripe — a plain block, colored per severity. */
    public const string MARK = '▉';

    /** @var array<int, array{fg: int, dim: int}> */
    private const PALETTE = [
        1 => ['fg' => 203, 'dim' => 131], // error   — red
        2 => ['fg' => 179, 'dim' => 101], // warning — amber
        3 => ['fg' => 75, 'dim' => 67], // info    — blue
        4 => ['fg' => 244, 'dim' => 240], // hint    — grey
    ];

    /**
     * The worst (lowest-numbered) severity present; 4 (hint) when the list is empty.
     *
     * @param list<array{severity?: int, ...}> $diags
     */
    public static function worst(array $diags): int
    {
        $worst = 4;
        foreach ($diags as $diag) {
            $worst = min($worst, self::normalize($diag['severity'] ?? 1));
        }

        return $worst;
    }

    /** Clamp any severity int to the known 1..4 range (unknown → hint). */
    public static function normalize(int $severity): int
    {
        return isset(self::PALETTE[$severity]) ? $severity : 4;
    }

    /** 256-color foreground code (line number, stripe mark, underline color). */
    public static function fg(int $severity): int
    {
        return self::PALETTE[self::normalize($severity)]['fg'];
    }

    /** Dimmer 256-color code for the inline end-of-line message. */
    public static function dim(int $severity): int
    {
        return self::PALETTE[self::normalize($severity)]['dim'];
    }

    /**
     * Open a curly, severity-colored underline. Terminals that support neither
     * escape degrade gracefully: `4:3` falls back to a plain underline and the
     * `58;5` underline color is simply ignored (the text's own color is used).
     */
    public static function underlineOpen(int $severity): string
    {
        return "\x1b[4:3m\x1b[58;5;".self::fg($severity).'m';
    }

    /** Close an underline opened by {@see underlineOpen}: reset its color and style. */
    public static function underlineClose(): string
    {
        return "\x1b[59m\x1b[24m";
    }
}
