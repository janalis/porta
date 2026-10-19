<?php

declare(strict_types=1);

namespace Porta\Ui\Graphics;

use Porta\Ui\Sgr;

/** Shared color-run encoding for fitted Markdown images and zoomable viewers. */
final class HalfBlockRow
{
    private const array VOID = [30, 30, 30];

    /** @param list<array{array{int, int, int}|null, array{int, int, int}|null}> $pixels */
    public static function encode(array $pixels): string
    {
        $line = '';
        $current = null;
        foreach ($pixels as [$top, $bottom]) {
            if (null === $top && null === $bottom) {
                if (null !== $current) {
                    $line .= Sgr::RESET;
                    $current = null;
                }
                $line .= ' ';

                continue;
            }
            [$tr, $tg, $tb] = $top ?? self::VOID;
            [$br, $bg, $bb] = $bottom ?? self::VOID;
            $sgr = "\x1b[38;2;{$tr};{$tg};{$tb};48;2;{$br};{$bg};{$bb}m";
            if ($sgr !== $current) {
                $line .= $sgr;
                $current = $sgr;
            }
            $line .= '▀';
        }

        return null === $current ? $line : $line.Sgr::RESET;
    }
}
