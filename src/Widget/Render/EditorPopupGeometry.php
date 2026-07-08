<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

/**
 * The frame geometry the editor needs to anchor its floating popups (the
 * signature hint and the completion box) over the rendered rows: terminal size,
 * scroll offsets, the caret's line and display column, the gutter width and the
 * sticky-header band height. Passed as one value instead of eight positional
 * ints so {@see \Porta\Widget\CodeEditorWidget::composePopups()} reads clearly.
 */
final readonly class EditorPopupGeometry
{
    public function __construct(
        public int $columns,
        public int $rows,
        public int $vScroll,
        public int $hScroll,
        public int $cursorLine,
        public int $cursorDisplayCol,
        public int $gutterWidth,
        public int $stickyCount,
    ) {}
}
