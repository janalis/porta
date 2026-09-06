<?php

declare(strict_types=1);

namespace Porta\Lsp;

/**
 * LSP `DiagnosticTag` — what a diagnostic says about the CODE rather than about
 * how wrong it is.
 *
 * Severity answers "how bad"; a tag answers "what kind", and the two are
 * independent: phpactor reports an unused import at severity 2 with
 * `tags: [1]`, so without the tag it is drawn exactly like a real warning about
 * working code. PhpStorm greys the one and strikes the other, which is the
 * whole point — you read "this line does nothing" without reading the message.
 *
 * The spec's set is these two and has been since 3.15.
 */
enum DiagnosticTag: int
{
    case Unnecessary = 1;
    case Deprecated = 2;
}
