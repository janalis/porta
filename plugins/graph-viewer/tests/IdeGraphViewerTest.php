<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\DocumentReplacer;
use Porta\Editor\OpenFocus;
use Porta\GraphViewer\Graph\GraphRenderMode;
use Porta\GraphViewer\GraphViewerWidget;
use Porta\GraphViewer\Render\GraphCellGrid;
use Porta\Help\LinkSpan;
use Porta\Help\RenderedDoc;
use Porta\Ide;
use Porta\Plugin\Lifecycle\PluginLifecycle;
use Porta\Plugin\Runtime\PluginHost;
use Porta\Support\Files;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Previews;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Tests\Support\Widgets;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\MarkdownPreviewWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The graph viewer tab: opening a .dot file routes it to the viewer (layout
 * via the committed fake-dot stub — no graphviz dependency), zoom/pan/focus,
 * the invalid-document banner over the last good render (via the filesystem
 * reconciler), tab reuse, and the actionable missing-binary pane.
 */
final class IdeGraphViewerTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use UsesBundledPlugins;

    private const string SMALL_DOT = "digraph G { rankdir=LR; A -> B [label=\"calls\"]; B -> C; }\n";

    private static function fakeDot(): string
    {
        return \dirname(__DIR__, levels: 3).'/tests/Support/fake-dot';
    }

    /**
     * The stubs run via `#!/usr/bin/env php`, so PATH must resolve `php` — but
     * the usual PATH=/nonexistent (blocking git/LSP spawns) would break them.
     * A throwaway bin dir with ONLY a php symlink keeps both properties.
     */
    private function phpOnlyPath(TempProject $project): void
    {
        $bin = $project->path('.php-only-bin');
        \mkdir($bin);
        \symlink(\PHP_BINARY, $bin.'/php');
        \putenv('PATH='.$bin);
    }

    public function testAPreMoveDotPathAdoptsOnceAndWritesThrough(): void
    {
        // The core `dotPath` of an older project: the plugin adopts it (the
        // engine resolves the stub, so the graph lays out) and writes it
        // through to plugins.graph-viewer from onReady, so the file stops
        // answering the same question twice.
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(['enabledPlugins' => ['graph-viewer', 'image-viewer'], 'settings' => ['dotPath' => self::fakeDot()]], \JSON_THROW_ON_ERROR),
            'g.dot' => self::SMALL_DOT,
        ]);
        $this->phpOnlyPath($project);

        $this->runIde(
            $project,
            $project->path('g.dot'),
            [
                200 => function ($tui, $terminal, Ide $ide) use ($project): void {
                    Previews::alone($ide); // the graph's own status segment, with the keys
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'nodes'), what: 'the adopted dotPath laying out the graph');
                    $this->await(static function () use ($project): bool {
                        $file = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);

                        return \is_array($file) && ($file['plugins']['graph-viewer']['dotPath'] ?? null) === self::fakeDot();
                    }, what: 'the adoption written through to plugins.graph-viewer');
                },
            ],
        );
    }

    public function testTheDotPathAndRenderModeSettingsApplyWithoutARestart(): void
    {
        // Both used to be baked at activate(): the engine held the resolved
        // path and the pane the mode, so editing either in Settings did
        // nothing until the next launch — which their hints admitted.
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(
                [
                    // image-viewer too: graph-viewer `requires` it, and PluginOrder
                    // skips a plugin whose requirement is off.
                    'enabledPlugins' => ['graph-viewer', 'image-viewer'],
                    'plugins' => ['graph-viewer' => ['dotPath' => '/nonexistent/dot']],
                ],
                \JSON_THROW_ON_ERROR,
            ),
            'g.dot' => self::SMALL_DOT,
            'h.dot' => self::SMALL_DOT,
        ]);
        $this->phpOnlyPath($project);

        $this->runIde(
            $project,
            $project->path('g.dot'),
            [
                200 => function ($tui, $terminal, Ide $ide) use ($project): void {
                    Previews::alone($ide);
                    // A bogus path: the engine reports rather than laying out.
                    $this->await(
                        static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'could not start dot'),
                        what: 'the graph pane to report the bogus binary',
                    );
                    $this->assertStringNotContainsString('nodes', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'nothing laid out yet');

                    // Point it at the working stub through the Settings path.
                    $lifecycle = Services::resolve($ide, PluginLifecycle::class);
                    $settings = Services::resolve($ide, PluginHost::class)->settings;
                    $settings->change('graph-viewer', 'dotPath', self::fakeDot());
                    $lifecycle->onPluginSettingChanged('graph-viewer', 'dotPath', self::fakeDot());

                    // A DIFFERENT document, so the pane asks the engine again
                    // rather than reusing the layout it cached by hash; the NEW
                    // binary is what answers, with no restart.
                    Services::resolve($ide, BufferService::class)->openFile((string) \realpath($project->path('h.dot')));
                    Previews::alone($ide);
                    $this->await(
                        static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'nodes'),
                        what: 'the edited dotPath laying out the graph',
                    );

                    // renderMode reaches the pane already on screen.
                    $widget = Probe::get(Probe::get(Probe::get($lifecycle, 'plugins'), 'instances')['graph-viewer'], 'widget');
                    $this->assertInstanceOf(GraphViewerWidget::class, $widget);
                    $this->assertSame(GraphRenderMode::Bitmap, Probe::get($widget, 'mode'), 'the default, before');
                    $settings->change('graph-viewer', 'renderMode', 'cells');
                    $lifecycle->onPluginSettingChanged('graph-viewer', 'renderMode', 'cells');
                    $this->assertSame(GraphRenderMode::Cells, Probe::get($widget, 'mode'));
                    $this->await(
                        static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), '┌'),
                        what: 'the standing pane switching to cell rendering',
                    );
                },
            ],
        );
    }

    public function testAFocusedGraphPaneGetsTheTabContextMenu(): void
    {
        // EVERY pane gets the tab context menu, not the two the old list happened
        // to name: Ctrl+F10 over a focused graph pane used to fall through and
        // open nothing at all. Its own session — opening a menu mid-script
        // perturbs the interactions the long test above asserts on.
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(['enabledPlugins' => ['graph-viewer', 'image-viewer'], 'plugins' => ['graph-viewer' => ['dotPath' => self::fakeDot()]]], \JSON_THROW_ON_ERROR),
            'g.dot' => self::SMALL_DOT,
        ]);
        $this->phpOnlyPath($project);

        $this->runIde(
            $project,
            $project->path('g.dot'),
            [
                200 => function (Tui $tui, $terminal, Ide $ide): void {
                    Previews::alone($ide);
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'nodes'), what: 'the graph layout to land');
                    $this->assertSame('graph-viewer', $tui->getFocus()?->getId());

                    $terminal->clearOutput();
                    $tui->handleInput("\x1b[21;5~"); // Ctrl+F10 over the focused graph pane
                    $this->frame($tui);

                    $this->assertStringContainsString('Close Others', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'a graph tab gets the tab context menu');
                },
            ],
        );
    }

    public function testDotFileOpensInTheGraphViewerWithZoomPanAndSelection(): void
    {
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(['enabledPlugins' => ['graph-viewer', 'image-viewer'], 'plugins' => ['graph-viewer' => ['dotPath' => self::fakeDot()]]], \JSON_THROW_ON_ERROR),
            'g.dot' => self::SMALL_DOT,
        ]);
        $this->phpOnlyPath($project);

        $dotPath = $project->path('g.dot');
        $this->runIde(
            $project,
            $dotPath,
            [
                200 => function (Tui $tui, $terminal, Ide $ide): void {
                    Previews::alone($ide);
                    // The stub answers on the loop: await the layout instead of sleeping.
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'nodes'), what: 'the graph layout to land');
                    $this->frame($tui);
                    $this->assertSame('graph-viewer', $tui->getFocus()?->getId(), 'the viewer takes editor-area focus');
                    $output = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('g.dot', $output, 'the tab title is the basename');
                    $this->assertStringContainsString('3 nodes · 2 edges', $output, 'the status segment');
                    $this->assertStringContainsString('Fit', $output, 'fit zoom label');
                    $this->assertStringContainsString('calls', $output, 'the edge label rendered');
                    $tui->handleInput('n'); // select the first node
                },
                380 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, '· A');
                    $this->assertStringContainsString('· A', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'selection surfaces in the status bar');

                    $tui->handleInput("\x1b[<64;40;10M"); // wheel over the graph pane → pans (no crash)
                    $tui->handleInput("\x1b[<67;40;10M"); // horizontal wheel → pans sideways
                    $tui->handleInput("\x1b[<80;40;10M"); // ctrl+wheel → zoom toward the pointer
                    $tui->handleInput("\x1b[<0;40;10M"); // press arms drag-pan
                    $tui->handleInput("\x1b[<32;44;12M"); // drag → pans
                    $tui->handleInput("\x1b[<0;44;12m"); // release after motion: NOT a select
                    $tui->handleInput("\x1b[<0;40;10M"); // press…
                    $tui->handleInput("\x1b[<0;40;10m"); // …release without motion = click-select
                    $tui->handleInput('0'); // back to fit
                },
                560 => function (Tui $tui, $terminal, Ide $ide) use ($dotPath): void {
                    $this->frame($tui);
                    $this->assertSame('graph-viewer', $tui->getFocus()?->getId(), 'drag interactions keep the viewer focused');
                    // Break the document on disk: the reconciler re-shows it, the
                    // failing layout keeps the previous rendering under a banner.
                    \file_put_contents($dotPath, data: 'digraph { broken');
                    Services::resolve($ide, BufferService::class)->pollFilesystem();
                    $this->await(static function () use ($terminal): bool {
                        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());

                        return \str_contains($screen, '✗') || \str_contains($screen, 'syntax error');
                    }, what: 'the error banner');
                    $output = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('3 nodes · 2 edges', $output, 'the last good graph is still the model');

                    // Exporting the broken document fails the same loud way.
                    Services::resolve($ide, CommandRegistry::class)->run('graph-viewer:export-svg');
                    $this->await(static fn (): bool => \str_starts_with(Toasts::last($ide), 'Export failed: '), what: 'the export-failure flash');
                    $this->assertStringNotContainsString('%error%', Toasts::last($ide), 'and why');
                },
            ],
        );
    }

    /**
     * `/` over the pane, then the picker: the wire is the plugin's closure,
     * which the widget's own suite cannot see (it is handed a double there).
     *
     * The FILTER is the half worth an app-level test — the overlay narrows on
     * what you type, and picking the survivor has to select the node the
     * picture was showing all along.
     */
    public function testTheFindKeyOpensAPickerThatFiltersAndSelectsTheNode(): void
    {
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(['enabledPlugins' => ['graph-viewer', 'image-viewer'], 'plugins' => ['graph-viewer' => ['dotPath' => self::fakeDot()]]], \JSON_THROW_ON_ERROR),
            'g.dot' => self::SMALL_DOT,
        ]);
        $this->phpOnlyPath($project);

        $this->runIde(
            $project,
            $project->path('g.dot'),
            [
                200 => function ($tui, $terminal, Ide $ide): void {
                    Previews::alone($ide);
                    $screen = static fn (): string => AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->await(static fn (): bool => \str_contains($screen(), 'nodes'), what: 'the graph layout to land');

                    $terminal->clearOutput();
                    $tui->handleInput('/');
                    $this->await(static fn (): bool => \str_contains($screen(), 'Find node'), what: 'the node picker to paint');
                    $listed = $screen();
                    $this->assertStringContainsString('1 in · 1 out', $listed, 'each row carries the node degree');

                    // Type to narrow: only C survives, so Enter cannot pick A.
                    // Both halves in ONE condition: a cleared terminal satisfies
                    // "A is gone" before anything has repainted at all.
                    $terminal->clearOutput();
                    $tui->handleInput('c');
                    $this->await(static fn (): bool => \str_contains($screen(), '1 in · 0 out') && !\str_contains($screen(), '0 in · 1 out'), what: 'the filter to leave C and drop A');

                    $terminal->clearOutput();
                    $tui->handleInput("\r");
                    $this->await(static fn (): bool => \str_contains($screen(), '· C'), what: 'the picked node to reach the status bar');
                },
            ],
        );
    }

    /** With no graph tab there is nothing to search, and the command says so instead of opening an empty list. */
    public function testTheFindCommandSaysSoWhenNoGraphIsOpen(): void
    {
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(['enabledPlugins' => ['graph-viewer', 'image-viewer'], 'plugins' => ['graph-viewer' => ['dotPath' => self::fakeDot()]]], \JSON_THROW_ON_ERROR),
            'a.php' => "<?php\n",
        ]);
        $this->phpOnlyPath($project);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, Ide $ide): void {
                    $terminal->clearOutput();
                    Services::resolve($ide, CommandRegistry::class)->run('graph-viewer:find-node');
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'No graph nodes to search'), what: 'the empty-graph flash');
                },
            ],
        );
    }

    public function testTheBitmapPaintsAndTheRenderModeKeyFlipsBackToTheCells(): void
    {
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(['enabledPlugins' => ['graph-viewer', 'image-viewer'], 'plugins' => ['graph-viewer' => ['dotPath' => self::fakeDot()]]], \JSON_THROW_ON_ERROR),
            'g.dot' => self::SMALL_DOT,
        ]);
        $this->phpOnlyPath($project);

        $this->runIde(
            $project,
            $project->path('g.dot'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    Previews::alone($ide); // `v` is the pane's key
                    // Read the CURRENT grid: the accumulated output still holds
                    // the cell rendering that painted before the bitmap landed.
                    $grid = fn (): string => \implode("\n", $this->screenGrid($tui, $terminal));
                    $this->await(static fn (): bool => \str_contains($grid(), '▀'), what: "dot's bitmap to paint");

                    $tui->handleInput('v');
                    $this->await(static fn (): bool => \str_contains($grid(), 'calls'), what: 'the cell rendering to come back');
                    $this->assertStringNotContainsString('▀', $grid(), 'the picture stepped aside');

                    $tui->handleInput('v');
                    $this->await(static fn (): bool => \str_contains($grid(), '▀'), what: 'the picture to come back');
                },
            ],
        );
    }

    public function testAMermaidDocumentOpensAndRendersInTheDiagramPane(): void
    {
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(
                [
                    'enabledPlugins' => ['graph-viewer', 'image-viewer'],
                    'plugins' => ['graph-viewer' => ['mermaidPath' => \dirname(__DIR__, levels: 3).'/tests/Support/fake-mmdc']],
                ],
                \JSON_THROW_ON_ERROR,
            ),
            'diagram.mmd' => "flowchart LR\nA --> B\n",
        ]);
        $this->phpOnlyPath($project);
        \symlink(\dirname(__DIR__, levels: 3).'/tests/Support/fake-mermaid-browser', $project->path('.php-only-bin/node'));
        $this->runIde($project, $project->path('diagram.mmd'), [
            200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                Previews::alone($ide);
                $grid = fn (): string => \implode("\n", $this->screenGrid($tui, $terminal));
                $this->await(static fn (): bool => \str_contains($grid(), '▀'), what: 'the Mermaid picture to paint');
                $this->assertSame('graph-viewer', $tui->getFocus()?->getId());
                $this->assertStringContainsString('diagram.mmd', $grid());
                $widget = Widgets::byId($tui, 'graph-viewer', GraphViewerWidget::class);
                $this->await(static fn (): bool => [] !== $widget->nodeChoices(), what: 'the CLI source map');
                $this->assertSame(['A', 'B'], \array_column($widget->nodeChoices(), 'label'));
                $scene = Probe::get($widget, 'nativeScene');
                $node = $scene->graph->nodes[\array_key_first($scene->elements)];
                $cells = new GraphCellGrid((int) Probe::get($widget, 'lastCols'), (int) Probe::get($widget, 'lastRows'), Probe::get($widget, 'view'), null);
                $active = Services::resolve($ide, BufferManager::class)->active();
                $this->assertInstanceOf(Buffer::class, $active);
                $widget->selectAt($cells->rowOf($node->y), $cells->colOf($node->x));
                $this->await(static fn (): bool => 1 === $active->document->cursorLine, what: 'the mapped CLI node source line');
            },
        ]);
    }

    public function testInlineMermaidSearchSourceFollowingAndExportsUseUnsavedFenceText(): void
    {
        $saved = "Intro\n\n```mermaid\nflowchart LR\nA[Saved]\nB[Tail]\nA-->B\n```\n";
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(['enabledPlugins' => ['graph-viewer', 'image-viewer']], \JSON_THROW_ON_ERROR),
            'doc.md' => $saved,
        ]);
        $this->phpOnlyPath($project);
        $this->runIde($project, $project->path('doc.md'), [
            200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project, $saved): void {
                Previews::alone($ide);
                $pane = Widgets::byId($tui, 'markdown-preview', MarkdownPreviewWidget::class);
                $read = static function () use ($pane): ?RenderedDoc {
                    $doc = Probe::get($pane, 'doc');

                    return $doc instanceof RenderedDoc ? $doc : null;
                };
                $ready = static fn (string $label): bool => (
                    \str_contains(\implode("\n", $pane->findLines), $label) && \array_any($read()->links ?? [], static fn (LinkSpan $span): bool => \str_ends_with($span->target, ':export-svg'))
                );
                $this->await(static fn (): bool => $ready('Saved'), what: 'inline semantic controls');
                $active = Services::resolve($ide, BufferManager::class)->active();
                $this->assertInstanceOf(Buffer::class, $active);
                Services::resolve($ide, DocumentReplacer::class)->replace($active, \str_replace('Saved', replace: 'Unsaved', subject: $saved));
                // This service edit is outside an input event; feed the frame observer explicitly.
                $tui->requestRender();
                $this->await(static fn (): bool => $ready('Unsaved'), what: 'the unsaved fence replacement');
                $click = static function (string $id) use ($pane, $read): void {
                    $span = \array_find($read()->links ?? [], static fn (LinkSpan $span): bool => \str_ends_with($span->target, ':'.$id));
                    self::assertNotNull($span);
                    self::assertTrue($pane->clickAt($span->line + 2 - (int) Probe::get($pane, 'scrollTop'), $span->col + (int) Probe::get($pane, 'marginCols')));
                };
                $click('export-svg');
                $this->await(static fn (): bool => \is_file($project->path('doc-diagram-1.svg')), what: 'the inline SVG export');
                $svg = (string) \file_get_contents($project->path('doc-diagram-1.svg'));
                $this->assertStringContainsString('>Unsaved</text>', $svg);
                $this->assertStringNotContainsString('>Saved</text>', $svg);
                $click('export-png');
                $this->await(static fn (): bool => \is_file($project->path('doc-diagram-1.png')), what: 'the inline PNG export');
                $this->assertStringStartsWith("\x89PNG", (string) \file_get_contents($project->path('doc-diagram-1.png')));
                $this->assertSame($saved, \file_get_contents($project->path('doc.md')));
                $terminal->clearOutput();
                $click('find-node');
                $screen = static fn (): string => AnsiUtils::stripAnsiCodes($terminal->getOutput());
                $this->await(static fn (): bool => \str_contains($screen(), 'Find node'), what: 'the inline node picker');
                $terminal->clearOutput();
                $tui->handleInput('Tail');
                $this->await(static fn (): bool => \str_contains($screen(), 'Tail') && !\str_contains($screen(), 'Unsaved'), what: 'the filtered inline node');
                $editor = Services::resolve($ide, CodeEditorWidget::class);
                $tui->handleInput("\r");
                $this->await(static fn (): bool => !EditorState::pickerOpen($editor) && 5 === $editor->getDocument()->cursorLine, what: 'the semantic source line');
                $this->assertSame(5, $editor->getDocument()->cursorLine);
            },
        ]);
    }

    /** @return iterable<string, array{0: string, 1?: string}> */
    public static function nativeDocuments(): iterable
    {
        yield 'sequence array alias key' => ["sequenceDiagram\nactor A@{ignored: &key [alias], *key : Configured} as A\nnote over A: %s\nA->>B: after\n"];
        yield 'sequence literal array key' => ["sequenceDiagram\nactor A@{[alias]: Configured} as A\nnote over A: %s\nA->>B: after\n"];
        yield 'flowchart document geometry' => ["---\nconfig:\n  flowchart: {rankSpacing: 90, padding: 15, minNodeWidth: 100}\n---\nflowchart TD\nA[%s]-->B\n"];
        yield 'sequence overridden non-string alias' => ["sequenceDiagram\nactor A@{alias: true} as Visible\nnote over A: %s\nA->>B: after\n"];
        yield 'sequence surrogate metadata key' => ["sequenceDiagram\nactor A@{\n\"\\uD800\": Value\nalias: Configured\n} as A\nnote over A: %s\nA->>B: after\n"];
        yield 'sequence primitive property key' => ["sequenceDiagram\nactor A@{\nseed: Value\n!!str alias : Configured\n} as A\nnote over A: %s\nA->>B: after\n"];
        yield 'sequence root array metadata' => ["sequenceDiagram\nactor A@{\n[Entry]\n} as A\nnote over A: %s\nA->>B: after\n"];
        yield 'sequence literal block key' => ["sequenceDiagram\nactor A@{\n? [alias]\n: Configured\n} as A\nnote over A: %s\nA->>B: after\n"];
        yield 'sequence plain flow key' => ["sequenceDiagram\nactor A@{alias:Unused, \"alias\": Configured, ignored: [foo: Value]} as A\nnote over A: %s\nA->>B: after\n"];
        yield 'sequence alias key' => ["sequenceDiagram\nactor A@{\nignored: &key alias\n*key : Configured\n} as A\nnote over A: %s\nA->>B: after\n"];
        yield 'sequence cyclic alias' => ["sequenceDiagram\nactor A@{\nignored: &data\n  self: *data\n  label: &text Configured\nalias: *text\n} as A\nnote over A: %s\nA->>B: after\n"];
        yield 'sequence collection alias' => ["sequenceDiagram\nactor A@{\nignored: &data [one, two]\ncopy: *data\n<<: *data\nalias: Configured\n} as A\nnote over A: %s\nA->>B: after\n"];
        yield 'sequence tag directive' => ["sequenceDiagram\nactor A@{\n%%TAG !e! tag:yaml.org,2002:\n---\nalias: !e!str Tagged\n}\nnote over A: %s\nA->>B: after\n"];
        yield 'sequence false alias' => ["sequenceDiagram\nactor A@{alias: false, type: null}\nnote over A: %s\nA->>B: after\n"];
        yield 'sequence YAML tags' => ["sequenceDiagram\nparticipant A@{alias: !<tag:yaml.org,2002:str> &label \"%s\", type: !!str actor}\nA->>B: after\n"];
        yield 'pie dark theme' => ["---\nconfig: {theme: dark, themeVariables: {pie1: red, pieOpacity: 0.5}}\n---\npie title %s\n\"A\":3\n\"B\":1\n", 'title: %s'];
        yield 'pie donut' => ["---\nconfig: {pie: {donutHole: 0.4, legendPosition: bottom}}\n---\npie title %s\n\"A\":3\n\"B\":1\n", 'title: %s'];
        yield 'railroad' => ["railroad-beta\nx=terminal(\"%s\");"];
        yield 'railroad EBNF' => ["railroad-ebnf-beta\nx=\"%s\";"];
        yield 'railroad ABNF' => ["railroad-abnf-beta\nx=\"%s\";"];
        yield 'railroad PEG' => ["railroad-peg-beta\nx<-\"%s\";"];
        yield 'venn' => ["venn-beta\nset A [\"%s\"]\nset B\nunion A,B\n"];
        yield 'eventmodeling' => ["eventmodeling\ntf 01 ui Screen \"%s\"\n"];
        yield 'architecture' => ["architecture-beta\nservice a[\"%s\"]\n"];
        yield 'usecase' => ["usecase-beta\nactor A\nA --> B(\"%s\")\n"];
        yield 'gantt' => ["gantt\n%s:2024-01-01, 1d\n"];
        yield 'agentflow' => ["agentflow-beta LR\nflow Agent\nA[\"%s\"]@{shape: task}\nend\nA --> B\n"];
        yield 'swimlane' => ["swimlane-beta LR\nsubgraph Team\nA[\"%s\"]\nend\nA --> B\n"];
        yield 'modern flowchart graphics' => ["flowchart LR\nA@{shape:person,label:\"%s\"} --> B@{shape:docs,label:\"Documents\"}\n"];
        yield 'flowchart' => ["flowchart\nA[\"%s\"]:::large o--o B\nclassDef large font-size:24px\n"];
        yield 'timeline' => ["timeline\n2000 : %s\n"];
        yield 'class' => ["classDiagram\nclass Entry[\"%s\"] {\n+String name\n+run() void\n}\n"];
        yield 'block' => ["block\nA[\"%s\"] B\nA --> B"];
        yield 'c4' => ["C4Context\nSystem(entry,\"%s\")\n"];
        yield 'wardley' => ["wardley-beta\ncomponent \"%s\" [0.5,0.5]\n"];
        yield 'git' => ["gitGraph\ncommit id:'%s'\n"];
        yield 'state' => ["stateDiagram-v2\nstate \"%s\" as Entry\nEntry --> [*]\n"];
    }

    #[DataProvider('nativeDocuments')]
    public function testNativeMermaidRendersAndExportsUnsavedSourceWithoutCli(string $syntax, string $exportLabel = '%s'): void
    {
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(['enabledPlugins' => ['graph-viewer', 'image-viewer']], \JSON_THROW_ON_ERROR),
            'native.mmd' => \sprintf($syntax, 'Saved'),
        ]);
        $this->phpOnlyPath($project);
        $this->runIde($project, $project->path('native.mmd'), [
            200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project, $syntax, $exportLabel): void {
                Previews::alone($ide);
                $widget = Widgets::byId($tui, 'graph-viewer', GraphViewerWidget::class);
                $this->await(static fn (): bool => [] !== $widget->nodeChoices(), what: 'the native scene');
                $grid = fn (): string => \implode("\n", $this->screenGrid($tui, $terminal));
                $this->await(static fn (): bool => \str_contains($grid(), '▀'), what: 'the native bitmap');
                $active = Services::resolve($ide, BufferManager::class)->active();
                $this->assertInstanceOf(Buffer::class, $active);
                Services::resolve($ide, DocumentReplacer::class)->replace($active, \sprintf($syntax, 'Unsaved'));
                $commands = Services::resolve($ide, CommandRegistry::class);
                $commands->run('graph-viewer:export-svg');
                $this->assertStringNotContainsString('Export failed', \implode("\n", Toasts::all($ide)), 'drawn natively, and by nothing else');
                $this->await(static fn (): bool => \is_file($project->path('native.svg')), what: 'the native SVG export');
                $svg = (string) \file_get_contents($project->path('native.svg'));
                $this->assertStringContainsString(\sprintf('>'.$exportLabel.'</text>', 'Unsaved'), $svg);
                $this->assertStringNotContainsString(\sprintf('>'.$exportLabel.'</text>', 'Saved'), $svg);
                $commands->run('graph-viewer:export-png');
                $this->await(static fn (): bool => \is_file($project->path('native.png')), what: 'the native PNG export');
                $this->assertStringStartsWith("\x89PNG", (string) \file_get_contents($project->path('native.png')));
                $commands->run('graph-viewer:export-pdf');
                $this->await(static fn (): bool => \is_file($project->path('native.pdf')), what: 'the native PDF export');
                $this->assertStringStartsWith('%PDF-', (string) \file_get_contents($project->path('native.pdf')));
                $this->assertStringContainsString('Saved', (string) \file_get_contents($project->path('native.mmd')), 'export does not save the document');
            },
        ]);
    }

    public function testAPlantUmlDocumentOpensInTheDiagramPane(): void
    {
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(
                [
                    'enabledPlugins' => ['graph-viewer', 'image-viewer'],
                    'plugins' => ['graph-viewer' => ['plantumlPath' => \dirname(__DIR__, levels: 3).'/tests/Support/fake-plantuml']],
                ],
                \JSON_THROW_ON_ERROR,
            ),
            'seq.puml' => "@startuml\nBob -> Alice : hello\n@enduml\n",
        ]);
        $this->phpOnlyPath($project);

        $this->runIde(
            $project,
            $project->path('seq.puml'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    Previews::alone($ide);
                    $grid = fn (): string => \implode("\n", $this->screenGrid($tui, $terminal));
                    $this->await(static fn (): bool => \str_contains($grid(), '▀'), what: "PlantUML's picture to paint");

                    $this->assertSame('graph-viewer', $tui->getFocus()?->getId(), 'a .puml takes the diagram pane');
                    // screenGrid() consumes the accumulated output, so the tab
                    // title has to be read from the grid too.
                    $this->assertStringContainsString('seq.puml', $grid());
                    // No geometry exists for it, so the pane reports zoom only.
                    $this->assertSame('', Widgets::byId($tui, 'graph-viewer', GraphViewerWidget::class)->statusInfo());
                    $this->assertStringContainsString('Fit', Widgets::byId($tui, 'graph-viewer', GraphViewerWidget::class)->zoomLabel());

                    // The library draws it through the same tool: an export lands beside the document.
                    Services::resolve($ide, CommandRegistry::class)->run('graph-viewer:export-png');
                    $this->assertStringNotContainsString('Export failed', \implode("\n", Toasts::all($ide)), 'drawn by PlantUML, and by nothing else');
                    $this->await(static fn (): bool => \is_file($project->path('seq.png')), what: 'the PlantUML PNG export');
                    $this->assertStringStartsWith("\x89PNG", (string) \file_get_contents($project->path('seq.png')));
                },
            ],
        );
    }

    public function testWithoutPlantumlADocumentStaysText(): void
    {
        \putenv('PATH=/nonexistent'); // nothing to discover, and no setting
        $project = new TempProject(['seq.puml' => "@startuml\nBob -> Alice\n@enduml\n"]);

        $this->runIde(
            $project,
            $project->path('seq.puml'),
            [
                200 => function (Tui $tui, $terminal): void {
                    $this->frame($tui);
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'the text path, as before');
                    $this->assertStringContainsString('@startuml', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }

    public function testMissingBinaryShowsTheActionablePane(): void
    {
        // No dotPath setting and an empty PATH: discovery finds nothing.
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            'g.dot' => self::SMALL_DOT,
        ]);

        $this->runIde(
            $project,
            $project->path('g.dot'),
            [
                200 => function (Tui $tui, $terminal): void {
                    $this->frame($tui);
                    $output = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('graphviz not found', $output);
                    $this->assertStringContainsString('dotPath', $output, 'the message names the setting');
                },
            ],
        );
    }

    public function testGraphTabReusesAndClosesLikeAnyTab(): void
    {
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(['enabledPlugins' => ['graph-viewer', 'image-viewer'], 'plugins' => ['graph-viewer' => ['dotPath' => self::fakeDot()]]], \JSON_THROW_ON_ERROR),
            'g.dot' => self::SMALL_DOT,
            'src/A.php' => "<?php\n",
            'big.dot' => \str_repeat('/* pad */ ', times: 1_100_000).'digraph {}',
            'binary.dot' => "\xff\xfe\x00digraph",
            '.porta/architecture.dot' => self::SMALL_DOT,
        ]);
        $this->phpOnlyPath($project);

        // Buffers are keyed by realpath (macOS /var symlinks to /private/var),
        // so the paths handed to open/close must be canonical too.
        $dotPath = (string) \realpath($project->path('g.dot'));
        $phpPath = (string) \realpath($project->path('src/A.php'));
        $bigPath = (string) \realpath($project->path('big.dot'));
        $binaryPath = (string) \realpath($project->path('binary.dot'));
        $archPath = (string) \realpath($project->path('.porta/architecture.dot'));
        $this->runIde(
            $project,
            $dotPath,
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($dotPath, $phpPath, $bigPath, $binaryPath, $archPath): void {
                    Previews::alone($ide); // kept by the tab: every re-open below shows the graph alone
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'nodes'), what: 'the graph layout');
                    $buffers = Services::resolve($ide, BufferManager::class);
                    $this->assertCount(1, $buffers->all());
                    $service = Services::resolve($ide, BufferService::class);
                    $service->openFile($dotPath); // same path: reuse, no duplicate
                    $this->assertCount(1, $buffers->all(), 'the existing tab was reused');

                    $service->openFile($phpPath);
                    $this->frame($tui);
                    $this->assertCount(2, $buffers->all());
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'a text tab swaps the editor back in');

                    $service->openFile($dotPath); // back to the graph: cached layout, no re-run
                    $this->awaitPainted($tui, $terminal, '3 nodes');
                    $this->assertStringContainsString('3 nodes', AnsiUtils::stripAnsiCodes($terminal->getOutput()));

                    // The graph is a text buffer now: a change on disk reloads
                    // it like any other file, and the preview follows the text.
                    // (The display cannot prove it — fake-dot ignores stdin —
                    // so assert the model.)
                    \file_put_contents($dotPath, data: "digraph G { A -> B; }\n");
                    \touch($dotPath, mtime: \time() + 2);
                    $service->pollFilesystem();
                    $this->assertSame("digraph G { A -> B; }\n", $buffers->get($dotPath)?->document->getText(), 'the text reloaded from disk');

                    $service->openFile($bigPath);
                    $this->assertCount(2, $buffers->all(), 'an oversized DOT is refused — too large to edit, and too large for its viewer');
                    $service->openFile($binaryPath);
                    $this->assertCount(2, $buffers->all(), 'a non-UTF-8 DOT is refused');

                    $service->openFile($archPath, OpenFocus::Editor);
                    $this->awaitPainted($tui, $terminal, 'Project Architecture');
                    $this->assertStringContainsString('Project Architecture', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the artifact path titles its tab');
                    $this->assertSame('graph-viewer', $tui->getFocus()?->getId(), 'the generated graph opens to be looked at: its preview alone, with the keys');

                    $service->closeTabsExcept($phpPath); // graph tabs bulk-close through forget()
                    $this->frame($tui);
                    $this->assertCount(1, $buffers->all());
                    $service->openFile($dotPath); // reopen for the close step below
                    $this->await(static fn (): bool => true, what: 'noop');
                },
                420 => function (Tui $tui, $terminal, Ide $ide) use ($dotPath): void {
                    $tui->handleInput("\x1bw"); // alt+w: close the graph tab through the handler
                    $this->frame($tui);
                    $buffers = Services::resolve($ide, BufferManager::class);
                    $this->assertCount(1, $buffers->all(), 'the graph tab closed');
                    $this->assertFalse($buffers->has($dotPath));
                },
            ],
        );
    }

    public function testArchitectureCommandScansOpensReusesAndExports(): void
    {
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(['enabledPlugins' => ['graph-viewer', 'image-viewer'], 'plugins' => ['graph-viewer' => ['dotPath' => self::fakeDot()]]], \JSON_THROW_ON_ERROR),
            'composer.json' => (string) \json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], \JSON_THROW_ON_ERROR),
            'src/Editor/Doc.php' => "<?php\nnamespace App\\Editor;\nuse App\\Support\\Text;\nfinal class Doc {}\n",
            'src/Support/Text.php' => "<?php\nnamespace App\\Support;\nfinal class Text {}\n",
        ]);
        $this->phpOnlyPath($project);

        $this->runIde(
            $project,
            null,
            [
                200 => function (Tui $tui, $terminal, Ide $ide) use ($project): void {
                    Services::resolve($ide, CommandRegistry::class)->run('graph-viewer:architecture');
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'nodes'), what: 'the architecture graph');
                    $artifact = $project->path('architecture.dot');
                    $this->assertFileExists($artifact);
                    $this->assertStringContainsString('App\\\\Editor', (string) \file_get_contents($artifact), 'the scanner produced the namespace graph');
                    $this->frame($tui);
                    $this->assertStringContainsString('Project Architecture', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the titled tab');

                    $buffers = Services::resolve($ide, BufferManager::class);
                    $this->assertCount(1, $buffers->all());
                    Services::resolve($ide, CommandRegistry::class)->run('graph-viewer:architecture'); // regenerate: reuse, no duplicate
                    $this->assertCount(1, $buffers->all(), 'the second invocation reused the tab');

                    Services::resolve($ide, CommandRegistry::class)->run('graph-viewer:export-svg');
                    $this->await(static fn (): bool => \is_file($project->path('architecture.svg')), what: 'the SVG export');
                    Services::resolve($ide, CommandRegistry::class)->run('graph-viewer:export-png');
                    $this->await(static fn (): bool => \is_file($project->path('architecture.png')), what: 'the PNG export');
                    Services::resolve($ide, CommandRegistry::class)->run('graph-viewer:export-pdf');
                    // Its content, not its being there: dot makes the file before it writes it.
                    $this->await(static fn (): bool => \str_contains(Files::read($project->path('architecture.pdf')), '-Tpdf -o'), what: 'dot to draw the PDF itself');
                    $this->await(static fn (): bool => \in_array('Exported architecture.pdf', Toasts::all($ide), strict: true), what: 'the export said, by name');
                },
            ],
        );
    }

    public function testTheOutputDirectorySettingPlacesTheArtifact(): void
    {
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(
                [
                    // BOTH: this plugin `requires` the image viewer for the shared
                    // rasterizer, and boot skips a plugin whose requirement is off
                    // rather than quietly enabling it.
                    'enabledPlugins' => ['graph-viewer', 'image-viewer'],
                    'plugins' => ['graph-viewer' => ['dotPath' => self::fakeDot(), 'outputDir' => 'docs/arch']],
                ],
                \JSON_THROW_ON_ERROR,
            ),
            'composer.json' => (string) \json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], \JSON_THROW_ON_ERROR),
            'src/A.php' => "<?php\nnamespace App;\nfinal class A {}\n",
        ]);
        $this->phpOnlyPath($project);

        $this->runIde(
            $project,
            null,
            [
                200 => function ($tui, $terminal, Ide $ide) use ($project): void {
                    Services::resolve($ide, CommandRegistry::class)->run('graph-viewer:architecture');
                    $this->await(static fn (): bool => \is_file($project->path('docs/arch/architecture.dot')), what: 'the artifact in its configured directory');
                    $this->assertFileDoesNotExist($project->path('architecture.dot'), 'and not in the default one');

                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Project Architecture'), what: 'the titled tab');

                    // The exports land beside the artifact, wherever it is.
                    Services::resolve($ide, CommandRegistry::class)->run('graph-viewer:export-svg');
                    $this->await(static fn (): bool => \is_file($project->path('docs/arch/architecture.svg')), what: 'the SVG beside it');
                },
            ],
        );
    }

    public function testConfiguredGeneratorCommandTakesPrecedence(): void
    {
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(
                [
                    'enabledPlugins' => ['graph-viewer', 'image-viewer'],
                    'plugins' => ['graph-viewer' => ['dotPath' => self::fakeDot(), 'command' => 'printf \'digraph FromCommand { X -> Y; }\'']],
                ],
                \JSON_THROW_ON_ERROR,
            ),
        ]);
        $this->phpOnlyPath($project);

        $this->runIde(
            $project,
            null,
            [
                200 => function ($tui, $terminal, Ide $ide) use ($project): void {
                    Services::resolve($ide, CommandRegistry::class)->run('graph-viewer:architecture');
                    $this->await(static fn (): bool => \is_file($project->path('architecture.dot')), what: 'the generated artifact');
                    $this->assertStringContainsString('FromCommand', (string) \file_get_contents($project->path('architecture.dot')), 'the configured command supplied the DOT');
                },
            ],
        );
    }

    public function testGeneratorFailuresFlashInsteadOfOpening(): void
    {
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(
                [
                    'enabledPlugins' => ['graph-viewer', 'image-viewer'],
                    'plugins' => ['graph-viewer' => ['dotPath' => self::fakeDot(), 'command' => 'echo doomed >&2; exit 3']],
                ],
                \JSON_THROW_ON_ERROR,
            ),
        ]);
        $this->phpOnlyPath($project);

        $this->runIde(
            $project,
            null,
            [
                200 => function ($tui, $terminal, Ide $ide) use ($project): void {
                    Services::resolve($ide, CommandRegistry::class)->run('graph-viewer:architecture');
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'doomed'), what: 'the failure flash');
                    $this->assertFileDoesNotExist($project->path('architecture.dot'));
                },
            ],
        );
    }

    public function testEmptyGeneratorOutputFlashes(): void
    {
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(
                [
                    'enabledPlugins' => ['graph-viewer', 'image-viewer'],
                    'plugins' => ['graph-viewer' => ['dotPath' => self::fakeDot(), 'command' => 'true']],
                ],
                \JSON_THROW_ON_ERROR,
            ),
        ]);
        $this->phpOnlyPath($project);

        $this->runIde(
            $project,
            null,
            [
                200 => function ($tui, $terminal, Ide $ide) use ($project): void {
                    Services::resolve($ide, CommandRegistry::class)->run('graph-viewer:architecture');
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'produced no output'), what: 'the empty-output flash');
                    $this->assertFileDoesNotExist($project->path('architecture.dot'));
                },
            ],
        );
    }

    public function testUnwritableArtifactDirFlashesAndExportSelfGuards(): void
    {
        $project = new TempProject([
            // The configured output directory cannot be created: a FILE sits
            // where its parent would have to be.
            '.porta.json' => (string) \json_encode(['plugins' => ['graph-viewer' => ['outputDir' => 'blocked/sub']]], \JSON_THROW_ON_ERROR),
            'blocked' => 'a FILE squatting on the output path',
            'src/A.php' => "<?php\n",
            'composer.json' => (string) \json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], \JSON_THROW_ON_ERROR),
        ]);
        $this->phpOnlyPath($project);

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function ($tui, $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('graph-viewer:architecture');
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Cannot write'), what: 'the unwritable flash');

                    // A text tab active: the plugin export handler self-guards — a
                    // graphviz-less export would have failed and said so, there and then.
                    $toasts = Toasts::all($ide);
                    Services::resolve($ide, CommandRegistry::class)->run('graph-viewer:export-svg');
                    $this->assertSame($toasts, Toasts::all($ide), 'nothing said: nothing tried');
                },
            ],
        );
    }

    public function testADiagramTooLargeToEditStillOpensInTheGraphPane(): void
    {
        // Past the editor's 2 MB the preview declines and the viewer claim
        // takes the file, under the viewer's own 10 MB cap.
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(['enabledPlugins' => ['graph-viewer', 'image-viewer'], 'plugins' => ['graph-viewer' => ['dotPath' => self::fakeDot()]]], \JSON_THROW_ON_ERROR),
            'huge.dot' => '/* '.\str_repeat('x', times: 2_200_000).' */ '.self::SMALL_DOT,
            'huge-binary.dot' => "\xff\xfe\x00digraph".\str_repeat('x', times: 2_200_000),
        ]);
        $this->phpOnlyPath($project);

        $this->runIde(
            $project,
            $project->path('huge.dot'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'nodes'), what: 'the graph of a file too large to edit');
                    $buffers = Services::resolve($ide, BufferManager::class);
                    $this->assertTrue($buffers->active()?->isViewer(), 'a viewer tab, not a text one');

                    Services::resolve($ide, BufferService::class)->openFile((string) \realpath($project->path('huge-binary.dot')));
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Not a UTF-8 text file'), what: 'the viewer\'s refusal');
                    $this->assertCount(1, $buffers->all());
                },
            ],
        );
    }

    /**
     * A syntax error is the editor's, like any analyser's: in Split it is a
     * diagnostic on the line dot names, and the picture beside it stays clear.
     * Shown alone, the picture has to say it itself.
     */
    public function testASyntaxErrorIsTheEditorsBesideTheTextAndThePicturesAlone(): void
    {
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(
                ['enabledPlugins' => ['graph-viewer', 'image-viewer'], 'plugins' => ['graph-viewer' => ['dotPath' => self::fakeDot(), 'renderMode' => 'cells']]],
                \JSON_THROW_ON_ERROR,
            ),
            'g.dot' => "digraph G {\n  A -> B;\n}\n",
        ]);
        $this->phpOnlyPath($project);

        $this->runIde(
            $project,
            $project->path('g.dot'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $pane = static fn (): string => \implode("\n", \array_map(
                        AnsiUtils::stripAnsiCodes(...),
                        Widgets::byId($tui, 'graph-viewer', GraphViewerWidget::class)->render(new RenderContext(60, 12)),
                    ));
                    $this->await(static fn (): bool => \str_contains($pane(), '┌'), what: 'the graph beside the text');
                    $editor = Services::resolve($ide, CodeEditorWidget::class);
                    $this->assertSame($editor, $tui->getFocus());

                    $tui->handleInput("\x1b[B"); // line 2
                    foreach (\str_split('broken ') as $key) {
                        $tui->handleInput($key);
                    }
                    $this->await(static fn (): bool => 1 === $editor->getDiagnosticCounts()['errors'], what: 'the error in the editor');
                    $this->assertSame("syntax error near 'broken'", $editor->getCursorDiagnostic()['message'] ?? null, 'on the line dot named');
                    $this->assertStringNotContainsString('✗', $pane(), 'no banner over the picture beside it');
                    $this->assertStringContainsString('┌', $pane(), 'the last good graph stays');

                    Services::resolve($ide, CommandRegistry::class)->run('preview_only');
                    $this->assertStringContainsString("✗ Line 2: syntax error near 'broken'", $pane(), 'alone, the picture says it');
                },
            ],
        );
    }

    public function testAClickOnANodeSendsTheCaretToWhereTheTextWritesIt(): void
    {
        $project = new TempProject([
            '.porta.json' => (string) \json_encode(
                ['enabledPlugins' => ['graph-viewer', 'image-viewer'], 'plugins' => ['graph-viewer' => ['dotPath' => self::fakeDot(), 'renderMode' => 'cells']]],
                \JSON_THROW_ON_ERROR,
            ),
            'g.dot' => self::SMALL_DOT,
        ]);
        $this->phpOnlyPath($project);

        $this->runIde(
            $project,
            $project->path('g.dot'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $grid = fn (): array => $this->screenGrid($tui, $terminal);
                    $this->await(static fn (): bool => \str_contains(\implode("\n", $grid()), '┌'), what: 'the graph beside the text');
                    $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($tui->getById('graph-viewer'));
                    $this->assertInstanceOf(WidgetRect::class, $rect);
                    foreach ($grid() as $row => $line) {
                        $col = \mb_strpos(\mb_substr($line, $rect->col), needle: '  C  ');
                        if (false !== $col && $row > $rect->row) {
                            $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + $col + 3, $row + 1));
                            $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + $col + 3, $row + 1));
                            break;
                        }
                    }
                    $editor = Services::resolve($ide, CodeEditorWidget::class);
                    $this->assertSame([0, (int) \strpos(self::SMALL_DOT, needle: 'C;')], [$editor->getDocument()->cursorLine, $editor->getDocument()->cursorCol], 'where "B -> C" first writes C');
                    $this->assertSame($editor, $tui->getFocus());
                },
            ],
            columns: 160,
        );
    }
}
