<?php

declare(strict_types=1);

namespace Porta\Overlay\Region;

use Porta\Widget\Render\PopupMetrics;

/**
 * Where a floating window opens until a drag gives it a place of its own.
 *
 * The pickers, Find in Files and the Welcome modal have always opened a third
 * of the way down, so a list growing under a query has room to fall; a view
 * window covers most of the workspace, and there "centred" means the middle.
 */
enum WindowPlacement
{
    case Centered;
    case UpperThird;

    /**
     * The box's top-left cell inside $bounds `[top, left, bottom, right)`.
     *
     * @param array{int, int, int, int} $bounds
     *
     * @return array{int, int} [top, col]
     */
    public function origin(array $bounds, int $rows, int $width): array
    {
        if (self::UpperThird === $this) {
            return PopupMetrics::centered($bounds, $rows, $width);
        }
        [$boundTop, $boundLeft, $boundBottom, $boundRight] = $bounds;

        return [
            $boundTop + \max(0, \intdiv($boundBottom - $boundTop - $rows, num2: 2)),
            $boundLeft + \max(0, \intdiv($boundRight - $boundLeft - $width, num2: 2)),
        ];
    }
}
