<?php

declare(strict_types=1);

use Porta\Editor\PaneServices;
use Porta\GraphViewer\ArchitectureArtifact;
use Porta\GraphViewer\Graph\DiagramKind;
use Porta\GraphViewer\Graph\DotFiles;
use Porta\GraphViewer\Graph\DotLayoutEngine;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\PlantUmlCommand;
use Porta\GraphViewer\Graph\PlantUmlEngine;
use Porta\GraphViewer\GraphViewerWidget;
use Porta\ImageViewer\ImageDecoder;
use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\LegacyCoreSettings;
use Porta\Plugin\Api\PluginApi;
use Porta\Widget\EditorPaneWidget;

/**
 * The Graphviz graph viewer as a bundled plugin: the routing claim (.dot/.gv
 * open in the built-in graph pane), the Project Architecture command, the
 * SVG/PNG exports and the generator-command setting. This file only WIRES:
 * everything behind it — the pane widget, the layout engines, the DOT parser,
 * the renderers and the architecture scanner — is this plugin's OWN code
 * (`src/`, namespace `Porta\GraphViewer`, declared in composer.json and graded by
 * the same gates as `src/`). Core supplies only the pane SEAM it registers
 * through. Disabling this plugin routes .dot files to the TEXT editor
 * (deliberate: edit the source), and its commands leave the palette and Plugins
 * menu live.
 * Enabled by default ({@see \Porta\Config\IdeConfig::DEFAULT_ENABLED_PLUGINS}).
 */
return new class('graph-viewer') extends AbstractPlugin {
    /** Held so the routing claim can read this plugin's own settings. */
    private ?PluginApi $api = null;

    /** The standing pane, so a `renderMode` edit can be pushed into it. */
    private ?GraphViewerWidget $widget = null;

    public function activate(PluginApi $api): void
    {
        $api->registerSetting('plantuml', 'Render PlantUML diagrams', 'bool', true);
        // The three tool knobs were CORE settings until this plugin owned
        // them (the git-keys precedent): `dotPath`, `graphRender` and the
        // core `plantuml` PATH — renamed, since this plugin's `plantuml` was
        // already the bool above. All three apply live: the two paths are read
        // per spawn through the closures below, and `renderMode` is pushed
        // into the standing pane by the onSettingChanged hook.
        $api->registerSetting('dotPath', 'Graphviz dot path', hint: 'empty = discover on PATH');
        $api->registerSetting('renderMode', 'Graph rendering', 'choice', 'bitmap', ['bitmap', 'cells']);
        $api->registerSetting('plantumlPath', 'PlantUML path (or .jar)', hint: 'empty = discover');
        $api->registerViewer('graph', fn (string $path): bool => DotFiles::isDotPath($path) || $this->claimsPlantUml($path));
        // A value written under the pre-move core key is adopted once and
        // written through from onReady (persisting is an IDE action).
        $legacy = new LegacyCoreSettings($api->projectRoot(), 'graph-viewer', ['dotPath' => 'dotPath', 'renderMode' => 'graphRender', 'plantumlPath' => 'plantuml']);
        $values = $legacy->seed($api->setting(...));
        $api->onReady(static function () use ($api, $legacy): void {
            foreach ($legacy->adoptions() as $key => $value) {
                $api->changeSetting($key, $value);
            }
        });
        // What is still answered by a pre-move core key. Read per use below,
        // alongside the live setting, and dropped the moment that key is
        // written — the adoption is a snapshot of the file at boot, so keeping
        // it after a Settings edit would shadow the new value forever.
        $adopted = $legacy->adoptions();
        // By REFERENCE on purpose, and never an arrow fn: the hook below unsets
        // a key from $adopted, and a by-value capture would keep answering with
        // the adopted value forever.
        $setting = static function (string $key) use (&$adopted, $api): bool|int|string {
            return $adopted[$key] ?? $api->setting($key);
        };
        // The two diagram tools are built here rather than inside the factory:
        // one statement per engine keeps them legible (and individually
        // attributable in coverage). Each takes a CLOSURE rather than a path:
        // the binary is resolved per spawn, so a Settings edit applies with no
        // restart — the AbstractToolPlugin::binary() idiom.
        $dot = new DotLayoutEngine($api->projectRoot(), static fn (): ?string => self::path($setting('dotPath')));
        $plantUml = new PlantUmlEngine($api->projectRoot(), static fn (): ?string => self::path($setting('plantumlPath')));
        $renderMode = (string) $values['renderMode'];
        // The `/` key and the palette command reach ONE closure. The widget
        // knows the key was pressed and which nodes exist; opening an overlay
        // needs the api, which only this file has. Not an arrow fn: it reads
        // $this->widget, which the pane factory assigns.
        $findNode = function () use ($api): void {
            $widget = $this->widget;
            $choices = null === $widget ? [] : $widget->nodeChoices();
            if ([] === $choices) {
                $api->flash($api->trans('No graph nodes to search'));

                return;
            }
            $api->pick($api->trans('Find node'), $choices, static function (string $value) use ($widget): void {
                $widget?->focusNode((int) $value);
            });
        };
        $api->registerActions([
            'graph_zoom_in' => 'Graph: Zoom In',
            'graph_zoom_out' => 'Graph: Zoom Out',
            'graph_zoom_fit' => 'Graph: Fit to Window',
            'graph_zoom_actual' => 'Graph: Actual Size',
            'graph_pan_left' => 'Graph: Pan Left',
            'graph_pan_right' => 'Graph: Pan Right',
            'graph_pan_up' => 'Graph: Pan Up',
            'graph_pan_down' => 'Graph: Pan Down',
            'graph_select_next' => 'Graph: Select Next Node',
            'graph_select_prev' => 'Graph: Select Previous Node',
            'graph_find_node' => 'Graph: Find Node',
            'graph_render_mode' => 'Graph: Toggle Bitmap/Cell Rendering',
        ]);
        $api->registerPane(
            'graph',
            // Not an arrow fn: the widget is kept so a later `renderMode` edit
            // can be pushed into the standing pane (the two paths need no push,
            // being read per spawn).
            // @mago-expect lint:prefer-arrow-function -- the body ASSIGNS to $this->widget; an arrow function hides a side effect inside an expression
            function (PaneServices $pane) use ($dot, $plantUml, $renderMode, $findNode): EditorPaneWidget {
                return $this->widget = new GraphViewerWidget($dot, $pane->translator, $plantUml, new ImageDecoder(), $pane->scheduler, $renderMode, $pane->styles, $findNode);
            },
            // The pane's payload is the DOT (or PlantUML) source. The size guard
            // is the plugin's own: core has no opinion about how big a diagram
            // may be, and this is the same constant the claim reads.
            static function (string $path) use ($api): string {
                if ((int) filesize($path) > DotFiles::MAX_BYTES) {
                    throw new \RuntimeException($api->trans('Graph file too large (> 10 MB)'));
                }
                $dot = (string) file_get_contents($path);
                if ('' !== $dot && !mb_check_encoding($dot, encoding: 'UTF-8')) {
                    throw new \RuntimeException($api->trans('Not a UTF-8 text file'));
                }

                return $dot;
            },
        );
        $api->registerSetting('command', 'Architecture command (stdout = DOT)', hint: 'empty = built-in PHP namespace scanner');
        $api->registerSetting('outputDir', 'Architecture output directory', hint: 'empty = project root; relative paths resolve from it');

        // What makes all three tool knobs live. A write under this plugin's own
        // key retires whatever a pre-move core key was still answering — that
        // includes the boot write-through of the adoption itself — and
        // `renderMode` additionally has to reach the pane that already exists,
        // since nothing re-reads it per paint the way the paths are per spawn.
        $api->onSettingChanged(function (string $key, bool|int|string $value) use (&$adopted): void {
            unset($adopted[$key]);
            if ('renderMode' === $key) {
                $this->widget?->setRenderMode((string) $value);
            }
        });

        $this->api = $api;

        $api->registerCommand('architecture', 'Project Architecture', static function () use ($api): void {
            $command = (string) $api->setting('command');
            $outputDir = (string) $api->setting('outputDir');
            new ArchitectureArtifact($api->projectRoot(), $outputDir)->generate('' === $command ? null : $command, static function (?string $error, ?string $path) use ($api): void {
                if (null !== $error) {
                    $api->flash($error, 5.0);

                    return;
                }
                $api->openFile((string) $path);
            });
        });
        $api->addMenuItem('architecture');

        $export = static function (GraphExportFormat $format) use ($api, $setting): void {
            $file = $api->activeFile();
            if (null === $file || !DotFiles::isDotPath($file)) {
                return; // palette self-guard: only a graph tab exports
            }
            $out = \sprintf('%s/%s.%s', \dirname($file), pathinfo($file, \PATHINFO_FILENAME), $format->extension());
            // One knob: this throwaway engine resolves `dotPath` exactly as the
            // pane's long-lived one does, per spawn.
            new DotLayoutEngine($api->projectRoot(), static fn (): ?string => self::path($setting('dotPath')))->export(
                (string) file_get_contents($file),
                $format,
                $out,
                static function (?string $error) use ($api, $out): void {
                    null === $error
                        ? $api->flash($api->trans('Exported %file%', ['%file%' => basename($out)]))
                        : $api->flash($api->trans('Export failed: %error%', ['%error%' => $error]), 5.0);
                },
            );
        };
        $api->registerCommand('find-node', 'Graph: Find Node', $findNode);
        $api->registerCommand('export-svg', 'Graph: Export SVG', static fn () => $export(GraphExportFormat::Svg));
        $api->registerCommand('export-png', 'Graph: Export PNG', static fn () => $export(GraphExportFormat::Png));
        $isDotTab = static fn (): bool => null !== ($file = $api->activeFile()) && DotFiles::isDotPath($file);
        $api->addMenuItem('export-svg', $isDotTab);
        $api->addMenuItem('export-png', $isDotTab);
    }

    /**
     * A PlantUML document is the viewer's only if the user wants it AND
     * something can draw it — otherwise it stays the editable text it has
     * always been. Both knobs are this plugin's own settings now: the bool
     * toggle and the tool path.
     */
    private function claimsPlantUml(string $path): bool
    {
        return (
            null !== DiagramKind::forPath($path)
            && !DotFiles::isDotPath($path)
            && (bool) $this->api?->setting('plantuml')
            && null !== PlantUmlCommand::tool(self::path($this->api?->setting('plantumlPath') ?? ''))
        );
    }

    /** A path setting's '' -to-null: empty means "discover", which the engines spell as null. */
    private static function path(bool|int|string $value): ?string
    {
        return '' !== (string) $value ? (string) $value : null;
    }
};
