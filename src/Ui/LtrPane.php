<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * Marks a widget whose content is left-to-right regardless of the UI locale, so
 * the RTL bidi reorder ({@see \Porta\Ui\PaneSegmenter}) leaves its columns
 * verbatim. Code panes implement this — the editor, the git diff, the terminal,
 * the results panel and the debug panel — because bidi-reordering source code
 * would mirror punctuation and detach the line-number gutter from its line.
 *
 * The marker is whole-pane, and for the last two that is a KNOWN trade rather
 * than a clean fit: the results and debug panels carry translated headers and
 * status lines as well as snippets, and those render in logical order — that
 * is, visually reversed — beside correctly mirrored chrome. Splitting a pane
 * into a verbatim content span inside mirrored chrome is the fix, and it is not
 * done. Do not read this interface as "no Hebrew reaches here".
 *
 * The membership set is pinned by
 * {@see \Porta\Tests\Unit\Ui\PaneSegmenterTest::testTheVerbatimPaneSetIsExactlyThese()},
 * because an empty marker interface is exactly the kind of contract that
 * silently loses a member.
 */
interface LtrPane {}
