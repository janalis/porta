<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * The editor's outbound hook set, grouped by domain — one typed handler per
 * receiving controller. Every group is nullable (a bare editor fires
 * nothing), so tests wire only what a scenario needs.
 */
final readonly class EditorCallbacks
{
    public function __construct(
        public ?EditorFileActionHandler $file = null,
        public ?EditorNavigationHandler $navigation = null,
        public ?EditorCompletionHandler $completion = null,
        public ?EditorRefactorHandler $refactor = null,
        public ?BufferNavigationHandler $buffer = null,
        public ?EditorCodeLensHandler $codeLens = null,
    ) {}
}
