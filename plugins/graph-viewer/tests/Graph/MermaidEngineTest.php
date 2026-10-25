<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Graph;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\GraphViewer\Graph\DiagramKind;
use Porta\GraphViewer\Graph\MermaidEngine;
use Porta\ImageViewer\ImageDecoder;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Settings\SettingsScope;
use Porta\Support\AsyncProcess;
use Porta\Support\SingleSlotProcess;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class MermaidEngineTest extends TestCase
{
    use PreservesPath;
    use PumpsLoop;

    private static function stub(): string
    {
        return \dirname(__DIR__, levels: 4).'/tests/Support/fake-mmdc';
    }

    /** @return array{?string, ?string} */
    private function render(MermaidEngine $engine, string $source, int $dpi = 96): array
    {
        $png = null;
        $error = null;
        $engine->render(
            $source,
            $dpi,
            static function (string $bytes) use (&$png): void {
                $png = $bytes;
            },
            static function (string $message) use (&$error): void {
                $error = $message;
            },
        );
        $this->await(static function () use (&$png, &$error): bool {
            return null !== $png || null !== $error;
        }, what: 'Mermaid to complete');

        return [$png, $error];
    }

    public function testPluginClaimsMermaidWithoutAToolAndAppliesItsToggleLive(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject();
        $scope = new SettingsScope(ConfigFiles::for($project->dir));
        $scope->set(ConfigScope::Project);
        $settings = new PluginSettings($project->dir, [], scope: $scope);
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $api = $manager->api('graph-viewer', $project->dir, $settings);
        $plugin = require \dirname(__DIR__, levels: 2).'/plugin.php';
        $plugin->activate($api);
        $manager->commit($api, $plugin);
        $this->assertSame('graph', $manager->previewFor('/p/diagram.mmd'));
        $this->assertSame('graph', $manager->viewerFor('/p/diagram.mermaid'));
        $this->assertNull($manager->previewFor('/p/readme.md'));
        $settings->change('graph-viewer', 'mermaid', false);
        $this->assertNull($manager->previewFor('/p/diagram.mmd'));
        $this->assertNull($manager->viewerFor('/p/diagram.mermaid'));
        $settings->change('graph-viewer', 'mermaid', true);
        $this->assertSame('graph', $manager->previewFor('/p/diagram.mmd'));
        $this->assertFileExists($project->path('.porta.json'));
        $this->assertJsonStringEqualsJsonString('{"plugins":{"graph-viewer":{"mermaid":true}}}', (string) \file_get_contents($project->path('.porta.json')));
        $plugin->deactivate();
        $plugin->deactivate();
    }

    public function testBothExtensionsResolveOnlyToMermaid(): void
    {
        $this->assertSame(DiagramKind::Mermaid, DiagramKind::forPath('/p/diagram.MMD'));
        $this->assertSame(DiagramKind::Mermaid, DiagramKind::forPath('/p/diagram.mermaid'));
        $this->assertFalse(DiagramKind::Mermaid->hasLayout());
        $this->assertNull(DiagramKind::forPath('/p/readme.md'));
    }

    public function testQuietStdinExplicitPngAndScaleWithIsolatedCleanup(): void
    {
        $project = new TempProject();
        $engine = new MermaidEngine($project->dir, self::stub());
        [$png, $error] = $this->render($engine, "flowchart LR\nA[été] --> B", 192);
        $this->assertNull($error);
        $this->assertSame(96, $engine->baseDpi());
        $image = new ImageDecoder()->decodeBytes((string) $png);
        $this->assertSame([240, 160], [$image?->width, $image?->height]);
        [$options, $source] = \json_decode((string) \file_get_contents($project->path('mermaid-request.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('--quiet', $options);
        $this->assertSame('-', $options['--input']);
        $this->assertSame('png', $options['--outputFormat']);
        $this->assertSame('2', $options['--scale']);
        $this->assertSame("flowchart LR\nA[été] --> B", $source);
        $this->assertDirectoryDoesNotExist(\dirname($options['--output']));
        $this->assertMatchesRegularExpression(
            '#^'.\preg_quote(\sys_get_temp_dir(), delimiter: '#').'/porta-mermaid-[0-9a-f]{32}/diagram\.png$#',
            $options['--output'],
            'a directory of its own, under a name no one can guess',
        );
        $this->assertSame('700', \file_get_contents($project->path('mermaid-output-dir-mode.txt')), 'that only this user can open');
        [$png] = $this->render($engine, 'flowchart TD', 0);
        $this->assertNotNull($png, 'even a nonpositive dpi has a positive scale');
        $engine->cancel();
        $engine->cancel();
    }

    public function testFailureAndMissingOutputBothCleanTheirDirectory(): void
    {
        $project = new TempProject();
        $engine = new MermaidEngine($project->dir, self::stub());
        foreach (['broken' => 'parse error on line 2', 'empty-output' => 'produced no PNG'] as $source => $message) {
            [$png, $error] = $this->render($engine, $source);
            $this->assertNull($png);
            $this->assertStringContainsString($message, (string) $error);
            [$options] = \json_decode((string) \file_get_contents($project->path('mermaid-request.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
            $this->assertDirectoryDoesNotExist(\dirname($options['--output']));
        }
    }

    public function testCancellationRemovesTheDirectoryAndSuppressesItsAnswer(): void
    {
        $project = new TempProject();
        $engine = new MermaidEngine($project->dir, self::stub());
        $fired = false;
        $mark = static function () use (&$fired): void {
            $fired = true;
        };
        $engine->render('slow', 96, $mark, $mark);
        $this->await(static fn (): bool => \is_file($project->path('mermaid-request.json')), what: 'the stub to start');
        [$options] = \json_decode((string) \file_get_contents($project->path('mermaid-request.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $engine->cancel();
        $engine->cancel();
        $this->assertDirectoryDoesNotExist(\dirname($options['--output']));
        [$png, $error] = $this->render($engine, 'flowchart TD');
        $this->assertNotNull($png);
        $this->assertNull($error);
        $this->assertFalse($fired);
    }

    public function testAnAnswerAlreadyWaitingForReapingCannotAffectANewerRequest(): void
    {
        $project = new TempProject();
        $slot = new SingleSlotProcess();
        $engine = new MermaidEngine($project->dir, self::stub(), $slot);
        foreach (['deferred', 'deferred-failure'] as $source) {
            $fired = false;
            $mark = static function () use (&$fired): void {
                $fired = true;
            };
            $engine->render($source, 96, $mark, $mark);
            $this->await(static function () use ($slot): bool {
                $process = Probe::get($slot, 'current');

                return $process instanceof AsyncProcess && null === Probe::get($process, 'process');
            }, what: 'EOF to hand the child to the reaper');
            [$png, $error] = $this->render($engine, 'flowchart LR');
            $this->assertNotNull($png);
            $this->assertNull($error);
            $this->pump(0.3);
            $this->assertFalse($fired, 'late reaping must never report or clean a newer request');
        }
    }

    public function testDiscoveryAndSettingsAreLive(): void
    {
        $project = new TempProject();
        $configured = '  '.self::stub().'  ';
        $engine = new MermaidEngine($project->dir, static function () use (&$configured): ?string {
            return $configured;
        });
        $this->assertSame(self::stub(), $engine->binary());
        $configured = null;
        \putenv('PATH=/nonexistent');
        [$png, $error] = $this->render($engine, 'flowchart TD');
        $this->assertNull($png);
        $this->assertStringContainsString('"mermaidPath" setting', (string) $error);
        \symlink(self::stub(), $project->path('mmdc'));
        \putenv('PATH='.$project->dir);
        $this->assertSame($project->path('mmdc'), $engine->binary());
    }
}
