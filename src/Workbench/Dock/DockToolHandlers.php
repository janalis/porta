<?php

declare(strict_types=1);

namespace Porta\Workbench\Dock;

/**
 * A dock tool tab core does not implement: its identity, and the two
 * behaviours {@see ResultsPanelMode} selects for core's own tools.
 *
 * A tab carrying one of these has a NULL mode. That is not a gap — the enum is
 * core's closed list of contents the results panel multiplexes, and a
 * contributed tool is by definition not on it. The null also does real work:
 * {@see DockToolTabs::findByMode()} returns the FIRST tab in a mode, so a
 * shared "extension" case would let a core caller clobber a contributed tab,
 * while a null can never match an enum case at all.
 */
final readonly class DockToolHandlers
{
    public function __construct(
        /** The strip identity — the (mode, title) key does not apply, so this replaces it. */
        public string $id,
        /**
         * Enter on a row: (project-relative path, 0-based line). Null takes
         * core's built-in open-the-file-and-jump, which is what five of core's
         * six tools do.
         *
         * @var (\Closure(string, int): void)|null
         */
        public ?\Closure $activate = null,
        /**
         * F5 on this tab; null leaves it inert.
         *
         * @var (\Closure(): void)|null
         */
        public ?\Closure $rerun = null,
    ) {}
}
