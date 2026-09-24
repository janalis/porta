<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The popups anchored at the caret that are not the completion list: the
 * signature hint and the documentation popup. One holder, because the editor
 * widget is the codebase's widest class and pins `too-many-properties` — and
 * because both are the same kind of thing, a transient read-out about the
 * code at the caret.
 */
final class CaretPopups
{
    public ?SignatureHint $signature = null;
    public ?DocPopup $doc = null;
}
