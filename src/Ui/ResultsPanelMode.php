<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * Which content the shared bottom results panel is currently serving. The IDE
 * multiplexes one panel widget across these modes; the mode selects how a live
 * query is answered and what pressing Enter on a row does.
 */
enum ResultsPanelMode
{
    /** Project-wide text search (grep), with replace. */
    case Search;
    /** Workspace symbol search (workspace/symbol). */
    case Symbol;
    /** Command palette (Find Action) — synchronous filtering. */
    case Action;
    /** Local-history snapshots for the active file. */
    case History;
    /** Aggregated diagnostics across open buffers. */
    case Problems;
    /** Project-wide TODO/FIXME scan. */
    case Todo;
    /** The captured language-server output, newest first (inert rows). */
    case LspLog;
    /** The status-bar flash history, newest first (inert rows). */
    case Notifications;
}
