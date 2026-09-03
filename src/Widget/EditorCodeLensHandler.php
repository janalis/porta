<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * Running a code lens the user clicked. Implemented by
 * {@see \Porta\Lsp\CodeLensController}.
 *
 * A role on {@see EditorCallbacks} rather than an event, because the widget
 * holds no dispatcher and its property ceiling is the reason — the callbacks
 * object is the one property that carries every outbound verb, which is what it
 * is for.
 */
interface EditorCodeLensHandler
{
    /** Run the $index'th lens above $line, if it carries a command. */
    public function runCodeLens(int $line, int $index): void;
}
