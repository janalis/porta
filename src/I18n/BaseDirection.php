<?php

declare(strict_types=1);

namespace Porta\I18n;

/** The base paragraph direction a line is reordered for (UAX#9 base level). */
enum BaseDirection
{
    case Ltr;
    case Rtl;
}
