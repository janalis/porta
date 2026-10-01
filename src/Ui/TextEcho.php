<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * What a {@see TextField} shows of what is typed into it.
 */
enum TextEcho
{
    case Visible;

    /**
     * A bullet per character — a password. The value is still the field's;
     * only what is drawn changes, and its length, which a person typing needs
     * to see grow.
     */
    case Masked;
}
