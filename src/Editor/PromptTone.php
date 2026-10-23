<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * How a one-line prompt's field is boxed: plainly, or with the double danger
 * border a destructive confirmation wears — the state read by shape, not only
 * by its words.
 */
enum PromptTone
{
    case Plain;

    /** What is typed confirms something that cannot be undone — a delete. */
    case Danger;
}
