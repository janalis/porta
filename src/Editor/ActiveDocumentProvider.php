<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The active buffer's path *and* its text, for services that read what is being
 * edited without driving the editor. Implemented by the editor widget.
 *
 * Extends {@see ActiveFileProvider} because the two travel together: a caller
 * that reads the document almost always needs the path to name it (a URI for
 * the language server, a title for a flash).
 */
interface ActiveDocumentProvider extends ActiveFileProvider
{
    public function getDocument(): Document;
}
