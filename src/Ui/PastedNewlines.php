<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * What a {@see TextField} does with the line breaks in a bracketed paste.
 *
 * Trailing breaks always go: a copied line carries its terminator, and a query
 * ending in one matches nothing mid-line. A single-line field cannot show an
 * inner break, so the default joins them with a space. The find-in-files
 * fields already take a literal newline (Alt+Enter), so a pasted one lands as
 * that same ⏎.
 */
enum PastedNewlines
{
    /** Every inner run of breaks becomes one space. */
    case Join;

    /** Inner breaks kept, as the field's own newline. */
    case Keep;
}
