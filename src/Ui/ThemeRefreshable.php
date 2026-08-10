<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * A widget that bakes theme colours into cached output and has to re-make it
 * when the theme switches.
 *
 * Most widgets do not: they read the live {@see Theme\Styles} holder per frame,
 * so a switch needs nothing but a repaint. The ones that cache rendered lines —
 * a diff pane colouring thousands of rows — would otherwise keep painting the
 * old palette until something else invalidated them.
 */
interface ThemeRefreshable
{
    public function refreshTheme(): void;
}
