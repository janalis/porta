<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Ide;
use Porta\Overlay\Presenter\ViewWindowPresenter;
use Porta\Widget\Render\ModalFrame;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Tui;

/**
 * Where a cell of the open view's region is on screen.
 *
 * A view used to take the whole main area, so a test could click a pane at a
 * fixed screen cell. It opens in a window now — centred, 80% of the
 * workspace — so the same pane cell moves with the window. These name a cell
 * by its place in the REGION (0-based from its top-left, the row under the
 * window's padding and the column after its left rail and padding) and
 * answer where that is on screen, or the SGR mouse report for it — or by
 * its place in one widget of it ({@see widgetSgr()}).
 */
trait ViewWindowCells
{
    /**
     * The 0-based screen [row, col] of region cell ($row, $col).
     *
     * @return array{int, int}
     */
    private static function windowCell(Ide $ide, int $row, int $col): array
    {
        $box = Services::resolve($ide, ViewWindowPresenter::class)->box();
        if (null === $box) {
            throw new \LogicException('no view window is drawn: open the view and paint a frame first');
        }

        return [$box->startRow + ModalFrame::CHROME_ROWS_TOP + $row, $box->col + 2 + $col];
    }

    /**
     * The SGR mouse report for $button at region cell ($row, $col): a press,
     * or with $release its release. 0 is the left button, 32 a left drag,
     * 35 a bare move, 64/65 the wheel.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain here: an SGR report is a press or a release.
    private static function windowSgr(Ide $ide, int $button, int $row, int $col, bool $release = false): string
    {
        [$screenRow, $screenCol] = self::windowCell($ide, $row, $col);

        return \sprintf("\x1b[<%d;%d;%d%s", $button, $screenCol + 1, $screenRow + 1, $release ? 'm' : 'M');
    }

    /**
     * The SGR mouse report for $button at ($row, $col) of the widget whose id
     * is $id, counted from its rendered rect — for a test that means "the
     * second row of the changes list" wherever the window put it. A negative
     * $col counts back from the widget's right edge.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain here: an SGR report is a press or a release.
    private static function widgetSgr(Ide $ide, string $id, int $button, int $row, int $col, bool $release = false): string
    {
        $rect = Services::resolve($ide, Renderer::class)->getWidgetRect(Services::resolve($ide, Tui::class)->getById($id));
        if (null === $rect) {
            throw new \LogicException("{$id} has not been drawn: paint a frame first");
        }
        $screenCol = $col < 0 ? $rect->col + $rect->columns + $col : $rect->col + $col;

        return \sprintf("\x1b[<%d;%d;%d%s", $button, $screenCol + 1, $rect->row + $row + 1, $release ? 'm' : 'M');
    }
}
