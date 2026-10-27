<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Symfony\Component\Tui\Ansi\AnsiUtils;

/** What a painted row's SGR codes say at a column. */
final class SgrReads
{
    /** The background colour in effect at visible column $col (net of every SGR code up to it). */
    public static function backgroundAt(string $line, int $col): string
    {
        $slice = AnsiUtils::sliceByColumn($line, $col, 1, true); // carries all prior SGR as pending
        $bg = 'default';
        $codes = [];
        if (\preg_match_all('/\x1b\[([0-9;]*)m/', $slice, $codes)) {
            foreach ($codes[1] as $params) {
                if ('' === $params || '0' === $params || '49' === $params) {
                    $bg = 'default';

                    continue;
                }
                $m = [];
                if (\preg_match('/48;5;(\d+)/', $params, $m)) {
                    $bg = '48;5;'.$m[1];
                }
            }
        }

        return $bg;
    }
}
