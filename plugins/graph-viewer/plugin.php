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
use Porta\Image\ImageDecoder;
use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\PluginApi;
use Porta\Widget\EditorPaneWidget;

/**
 * The Graphviz graph viewer as a bundled plugin: the routing claim (.dot/.gv
 * open in the built-in graph pane), the Project Architecture command, the
 * SVG/PNG exports and the generator-command setting. This file only WIRES:
 * everything behind it — the pane widget, the layout engines, the DOT parser,
 * the renderers and the architecture scanner — is this plugin's OWN code
 * (`src/`, namespace `Porta\GraphViewer`, declared in plugin.json and graded by
 * the same gates as `src/`). Core supplies only the pane SEAM it registers
 * through. Disabling this plugin routes .dot files to the TEXT editor
 * (deliberate: edit the source), and its commands leave the palette and Plugins
 * menu live.
 * Enabled by default ({@see \Porta\Config\IdeConfig::DEFAULT_ENABLED_PLUGINS}).
 */
return new class('graph-viewer') extends AbstractPlugin {
    /** Held so the routing claim can read this plugin's own settings. */
    private ?PluginApi $api = null;

    public function activate(PluginApi $api): void
    {
        $api->registerSetting('plantuml', 'Render PlantUML diagrams', 'bool', true);
        $api->registerViewer('graph', fn (string $path): bool => DotFiles::isDotPath($path) || $this->claimsPlantUml($path));
        // The two diagram tools are built here rather than inside the factory:
        // both read CORE settings through the env channel, which is seeded
        // before any plugin activates, and one statement per engine keeps them
        // legible (and individually attributable in coverage).
        $dot = new DotLayoutEngine($api->projectRoot(), $this->coreSetting('dotPath'));
        $plantUml = new PlantUmlEngine($api->projectRoot(), $this->coreSetting('plantuml'));
        $renderMode = $this->coreSetting('graphRender');
        $api->registerPane(
            'graph',
            static fn (PaneServices $pane): EditorPaneWidget => new GraphViewerWidget($dot, $pane->translator, $plantUml, new ImageDecoder(), $pane->scheduler, $renderMode, $pane->styles),
            // The pane's payload is the DOT (or PlantUML) source. The size guard
            // is the plugin's own: core has no opinion about how big a diagram
            // may be, and this is the same constant the claim reads.
            static function (string $path): string {
                if ((int) filesize($path) > DotFiles::MAX_BYTES) {
                    throw new \RuntimeException('Graph file too large (> 10 MB)');
                }
                $dot = (string) file_get_contents($path);
                if ('' !== $dot && !mb_check_encoding($dot, encoding: 'UTF-8')) {
                    throw new \RuntimeException('Not a UTF-8 text file');
                }

                return $dot;
            },
        );
        $api->registerSetting('command', 'Architecture command (stdout = DOT)', hint: 'empty = built-in PHP namespace scanner');
        $api->registerSetting('outputDir', 'Architecture output directory', hint: 'empty = project root; relative paths resolve from it');

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

        $export = function (GraphExportFormat $format) use ($api): void {
            $file = $api->activeFile();
            if (null === $file || !DotFiles::isDotPath($file)) {
                return; // palette self-guard: only a graph tab exports
            }
            $out = \sprintf('%s/%s.%s', \dirname($file), pathinfo($file, \PATHINFO_FILENAME), $format->extension());
            // The dot binary follows the CORE dotPath setting: two knobs for
            // one path would be worse ({@see AbstractPlugin::coreSetting}).
            new DotLayoutEngine($api->projectRoot(), $this->coreSetting('dotPath'))->export((string) file_get_contents($file), $format, $out, static function (?string $error) use ($api, $out): void {
                null === $error
                    ? $api->flash('Exported '.basename($out))
                    : $api->flash('Export failed: '.$error, 5.0);
            });
        };
        $api->registerCommand('export-svg', 'Graph: Export SVG', static fn () => $export(GraphExportFormat::Svg));
        $api->registerCommand('export-png', 'Graph: Export PNG', static fn () => $export(GraphExportFormat::Png));
        $isDotTab = static fn (): bool => null !== ($file = $api->activeFile()) && DotFiles::isDotPath($file);
        $api->addMenuItem('export-svg', $isDotTab);
        $api->addMenuItem('export-png', $isDotTab);
    }

    /**
     * A PlantUML document is the viewer's only if the user wants it AND
     * something can draw it — otherwise it stays the editable text it has
     * always been. The tool is a CORE setting, so it is read through
     * coreSetting(): a plugin setting can only be read by its own plugin, and
     * the engine that needs the path is a core service.
     */
    private function claimsPlantUml(string $path): bool
    {
        return (
            null !== DiagramKind::forPath($path)
            && !DotFiles::isDotPath($path)
            && true === (bool) $this->api?->setting('plantuml')
            && null !== PlantUmlCommand::tool($this->coreSetting('plantuml'))
        );
    }
};
