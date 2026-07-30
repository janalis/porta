<?php

declare(strict_types=1);

use Porta\Graph\ArchitectureArtifact;
use Porta\Graph\DotFiles;
use Porta\Graph\DotLayoutEngine;
use Porta\Graph\GraphExportFormat;
use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\PluginApi;

/**
 * The Graphviz graph viewer as a bundled plugin: the routing claim (.dot/.gv
 * open in the built-in graph pane), the Project Architecture command, the
 * SVG/PNG exports and the generator-command setting. Implementation stays in
 * `src/` (registration-only doctrine): the commands are thin glue over
 * {@see ArchitectureArtifact} and {@see DotLayoutEngine}. Disabling this
 * plugin routes .dot files to the TEXT editor (deliberate: edit the source),
 * and its commands leave the palette and Plugins menu live.
 * Enabled by default ({@see \Porta\Config\IdeConfig::DEFAULT_ENABLED_PLUGINS}).
 */
return new class('graph-viewer') extends AbstractPlugin {
    public function activate(PluginApi $api): void
    {
        $api->registerViewer('graph', DotFiles::EXTENSIONS);
        $api->registerSetting('command', 'Architecture command (stdout = DOT)', hint: 'empty = built-in PHP namespace scanner');
        $api->registerSetting('outputDir', 'Architecture output directory', hint: 'empty = project root; relative paths resolve from it');

        $api->registerCommand('architecture', 'Project Architecture', function () use ($api): void {
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
};
