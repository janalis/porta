<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * Whether a text field / multiline input splices its caret marker into the
 * rendered output. Replaces the former `bool $cursor` render flag on
 * {@see TextField} and {@see MultilineInput}.
 */
enum CaretVisibility
{
    case Shown;
    case Hidden;
}
