<?php

declare(strict_types=1);

use Porta\DiffPlugin\Merge\MergePaneWidget;
use Porta\DiffPlugin\Merge\MergeSides;
use Porta\Editor\ConflictSide;
use Porta\Editor\PaneServices;
use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\PluginApi;
use Porta\Widget\EditorPaneWidget;

/**
 * Diffs for the plugins that show them, and the merge view.
 *
 * What it ships for others is classes — the unified-diff model with its
 * word-level highlights, and the unified or side-by-side pane git and review
 * both draw a diff in — which a plugin that `requires` it can autoload.
 *
 * What it does itself is the merge view: a file holding conflict markers
 * opens as ours | result | theirs, the editor holding the result between the
 * two sides it was merged from — whether git's mergetool launched the IDE on
 * it or a merge in progress left it so. Enabled by default
 * ({@see \Porta\Config\IdeConfig::DEFAULT_ENABLED_PLUGINS}), since git, which
 * is too, needs it.
 */
return new class('diff') extends AbstractPlugin {
    public function activate(PluginApi $api): void
    {
        // Asked only of a file small enough to edit, so reading it whole is the cap's.
        $api->registerPreview(MergePaneWidget::PANE_ID, static fn (string $path): bool => \is_file($path) && \is_readable($path) && MergeSides::conflicted((string) \file_get_contents($path)));
        $api->registerPane(
            MergePaneWidget::PANE_ID,
            static function (PaneServices $services) use ($api): EditorPaneWidget {
                $jump = static function (int $line) use ($api): void {
                    $api->jumpTo($line);
                };
                // The view moves, the caret stays: the three columns follow the editor's top line.
                $scroll = static function (int $rows) use ($api): void {
                    $caret = $api->caretPosition();
                    if (null !== $caret) {
                        $api->jumpTo($caret['line'], $caret['column'], \max(0, $caret['top'] + $rows));
                    }
                };
                $save = static function () use ($api): void {
                    $path = $api->activeFile();
                    if (null !== $path) {
                        $api->openBuffers()->save($path);
                    }
                };
                $sides = [$services->translator, $api->documentText(...), $api->caretPosition(...), $api->editorScreen(...), $api->replaceDocument(...), $jump, $scroll, $save, $services->styles];
                $ours = new MergePaneWidget(ConflictSide::Ours, ...$sides);

                return new MergePaneWidget(ConflictSide::Theirs, ...$sides, ours: $ours);
            },
            static fn (string $path): string => \is_readable($path) ? (string) \file_get_contents($path) : '',
        );
    }
};
