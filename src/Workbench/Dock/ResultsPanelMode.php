<?php

declare(strict_types=1);

namespace Porta\Workbench\Dock;

/**
 * Which content the shared bottom results panel is currently serving. The IDE
 * multiplexes one panel widget across these modes; the mode selects how a live
 * query is answered and what pressing Enter on a row does.
 *
 * Core's own list, and it stays closed: a {@see DockToolTab} may carry a null
 * mode instead, which is what a CONTRIBUTED tool does (see
 * {@see DockToolHandlers}). The TODO scan used to have a case here and lost it
 * when it became a bundled plugin — a case leaving is the measurable half of a
 * built-in becoming an extension.
 */
enum ResultsPanelMode
{
    /** Project-wide text search (grep), with replace. */
    case Search;
    /** Local-history snapshots for the active file. */
    case History;
    /** Aggregated diagnostics across open buffers. */
    case Problems;
    /** The captured language-server output, newest first (inert rows). */
    case LspLog;
    /** The status-bar flash history, newest first (inert rows). */
    case Notifications;
}
