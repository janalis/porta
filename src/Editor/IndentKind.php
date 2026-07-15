<?php

declare(strict_types=1);

namespace Porta\Editor;

/** The indentation unit of a file: hard tabs or spaces. */
enum IndentKind
{
    case Tabs;
    case Spaces;
}
