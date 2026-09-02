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
 * {@see DockToolHandlers}). The TODO scan and Local History each used to have a
 * case here and lost it on becoming a bundled plugin — a case leaving is the
 * measurable half of a built-in becoming an extension.
 */
enum ResultsPanelMode
{
    /** Project-wide text search (grep), with replace. */
    case Search;
    /** Aggregated diagnostics across open buffers. */
    case Problems;
    /** The captured language-server output, newest first (inert rows). */
    case LspLog;
    /** The status-bar flash history, newest first (inert rows). */
    case Notifications;
    /** The active file's symbols, indented by nesting (language server). */
    case Structure;
    /** The lines you marked to come back to, across every file. */
    case Bookmarks;
}
