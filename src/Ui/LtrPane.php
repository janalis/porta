<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * Marks a widget whose content is left-to-right regardless of the UI locale, so
 * the RTL bidi reorder ({@see \Porta\Ui\PaneSegmenter}) leaves its columns
 * verbatim. Code panes implement this — the editor, the git diff, the terminal,
 * and the search-results snippets — because bidi-reordering source code would
 * mirror punctuation and detach the line-number gutter from its line.
 */
interface LtrPane {}
