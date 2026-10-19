<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidSvgRasterizer;
use Porta\Tests\Support\PngFixture;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Graphics\PngDimensions;
use Symfony\Component\Process\ExecutableFinder;

final class MermaidSvgRasterizerTest extends TestCase
{
    use PreservesPath;
    use PumpsLoop;

    private static function support(string $name): string
    {
        return \dirname(__DIR__, levels: 4).'/tests/Support/'.$name;
    }

    public function testPrivateSnapshotAndPackagedHelperAreCleanedAfterTheBrowserReturns(): void
    {
        $project = new TempProject();
        $tool = self::support('fake-mmdc');
        $browser = self::support('fake-mermaid-browser');
        $rasterizer = new MermaidSvgRasterizer(
            $project->dir,
            static function () use (&$tool): string {
                return $tool;
            },
            static function () use (&$browser): string {
                return $browser;
            },
        );
        $this->assertSame($browser, $rasterizer->binary());
        $source = '<svg><foreignObject><div>HTML label</div></foreignObject></svg>';
        $png = $this->awaitString(static fn (\Closure $success) => $rasterizer->rasterize($source, 2.0, $success, self::fail(...)));
        $this->assertSame([200, 100], PngDimensions::read($png));
        [$script, $cli, $input, $scale, $svg, $program] = \json_decode((string) \file_get_contents($project->path('mermaid-browser-request.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame($tool, $cli);
        $this->assertSame('2', $scale);
        $this->assertSame($source, $svg);
        $this->assertStringEqualsFile(MermaidSvgRasterizer::SCRIPT, $program);
        $this->assertSame(\dirname($script), \dirname($input));
        $this->assertDirectoryDoesNotExist(\dirname($input));
        $this->assertNull(Probe::get($rasterizer, 'directory'));
        $rasterizer->cancel();
    }

    public function testMissingToolsAndUnreadableResourcesReportErrorsWithoutLeavingARequest(): void
    {
        $project = new TempProject(['bin/.keep' => '']);
        \symlink(\PHP_BINARY, $project->path('bin/php'));
        \putenv('PATH='.$project->path('bin'));
        foreach ([new MermaidSvgRasterizer($project->dir), new MermaidSvgRasterizer($project->dir, self::support('fake-mmdc'))] as $rasterizer) {
            $this->assertNull($rasterizer->binary());
            $error = null;
            $rasterizer->rasterize(
                '<svg/>',
                null,
                static function (): never {
                    self::fail('No browser can run');
                },
                static function (string $value) use (&$error): void {
                    $error = $value;
                },
            );
            $this->assertSame('The Mermaid CLI browser is unavailable', $error);
        }
        $rasterizer = new MermaidSvgRasterizer($project->dir, self::support('fake-mmdc'), self::support('fake-mermaid-browser'), script: $project->path('missing.mjs'));
        $error = null;
        $rasterizer->rasterize(
            '<svg/>',
            null,
            static function (): never {
                self::fail('A missing helper cannot render');
            },
            static function (string $value) use (&$error): void {
                $error = $value;
            },
        );
        $this->assertNotNull($error);
        $this->assertStringContainsString('missing.mjs', $error);
        $this->assertNull(Probe::get($rasterizer, 'directory'));
        $this->assertFileDoesNotExist($project->path('mermaid-browser-request.json'));
        $rasterizer->cancel();
    }

    public function testInvalidScalesFailBeforeStartingABrowser(): void
    {
        $project = new TempProject();
        $rasterizer = new MermaidSvgRasterizer($project->dir, self::support('fake-mmdc'), self::support('fake-mermaid-browser'));
        try {
            foreach ([0.0, -1.0, \NAN, \INF] as $scale) {
                $error = null;
                $rasterizer->rasterize('<svg/>', $scale, $this->fail(...), static function (string $value) use (&$error): void {
                    $error = $value;
                });
                $this->assertSame('Invalid SVG raster scale', $error);
                $this->assertNull(Probe::get($rasterizer, 'directory'));
            }
            $this->assertFileDoesNotExist($project->path('mermaid-browser-request.json'));
        } finally {
            $rasterizer->cancel();
        }
    }

    public function testBundledProgramUsesTheCliBrowserProtocolAndRejectsOversizedRasters(): void
    {
        $node = new ExecutableFinder()->find('node');
        if (null === $node) {
            $this->markTestSkipped('Node is only required by the optional Mermaid CLI browser');
        }
        $project = new TempProject([
            'cli/package.json' => '{"name":"@mermaid-js/mermaid-cli"}',
            'cli/bin/mmdc.js' => '',
            'cli/node_modules/puppeteer/package.json' => '{"main":"index.cjs"}',
            'cli/node_modules/puppeteer/index.cjs' => (string) \file_get_contents(__DIR__.'/../fixtures/mermaid/cli-browser.cjs'),
            'fixture.png' => PngFixture::bytes(100, height: 50),
        ]);
        $rasterizer = new MermaidSvgRasterizer($project->dir, $project->path('cli/bin/mmdc.js'), $node);
        $source = '<svg viewBox="0 0 100.5 50.25"><foreignObject><div>HTML label</div></foreignObject></svg>';
        $png = $this->awaitString(static fn (\Closure $success) => $rasterizer->rasterize($source, 2.0, $success, self::fail(...)));
        $this->assertStringEqualsFile($project->path('fixture.png'), $png);
        $record = \json_decode((string) \file_get_contents($project->path('browser-protocol.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame(['launch', 'goto', 'evaluate', 'viewport', 'screenshot', 'close'], $record['calls']);
        $this->assertSame(['headless' => 'shell'], $record['launch']);
        $this->assertSame(['width' => 101, 'height' => 51, 'deviceScaleFactor' => 2], $record['viewport']);
        $this->assertSame(['type' => 'png', 'omitBackground' => false], $record['screenshot']);
        $this->assertSame('svg', $record['selector']);
        $this->assertSame(['width' => '101px', 'height' => '51px', 'maxWidth' => 'none', 'maxHeight' => 'none', 'display' => 'block'], $record['svgStyle']);
        $this->assertStringContainsString('<base href="file://', $record['html']);
        $this->assertStringContainsString('HTML label', $record['html']);
        foreach ([
            ['<svg><!--huge-browser--></svg>', 1.0, 'SVG raster exceeds the image budget'],
            ['<svg><!--wide-browser--></svg>', 1.0, 'SVG raster exceeds the image budget'],
            ['<svg/>', \NAN, 'Invalid SVG raster scale'],
            ['<div/>', null, 'No SVG document'],
        ] as [$input, $scale, $message]) {
            $error = null;
            $rasterizer->rasterize(
                $input,
                $scale,
                static function (): never {
                    self::fail('The oversized screenshot must not run');
                },
                static function (string $value) use (&$error): void {
                    $error = $value;
                },
            );
            $this->await(static function () use (&$error): bool {
                return null !== $error;
            }, what: 'the browser image budget');
            $this->assertStringContainsString($message, $error);
            $record = \json_decode((string) \file_get_contents($project->path('browser-protocol.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
            $this->assertNotContains('screenshot', $record['calls']);
            $this->assertSame('close', \array_last($record['calls']));
        }
        $project->write('cli/package.json', '{"name":"another-tool"}');
        $error = null;
        $rasterizer->rasterize(
            $source,
            null,
            static function (): never {
                self::fail('An arbitrary wrapper has no shared browser contract');
            },
            static function (string $value) use (&$error): void {
                $error = $value;
            },
        );
        $this->await(static function () use (&$error): bool {
            return null !== $error;
        }, what: 'the CLI package check');
        $this->assertStringContainsString('does not expose the official Mermaid CLI browser', $error);
        $rasterizer->cancel();
    }

    public function testBrowserErrorsAndCancellationRetireTheirPrivateSnapshots(): void
    {
        $project = new TempProject();
        $rasterizer = new MermaidSvgRasterizer($project->dir, self::support('fake-mmdc'), self::support('fake-mermaid-browser'));
        $error = null;
        $success = 0;
        $accept = static function () use (&$success): void {
            ++$success;
        };
        $fail = static function (string $value) use (&$error): void {
            $error = $value;
        };
        $rasterizer->rasterize('<svg><!--broken-browser--></svg>', null, $accept, $fail);
        $this->await(static function () use (&$error): bool {
            return null !== $error;
        }, what: 'the browser failure');
        $this->assertStringContainsString('Browser could not render SVG', $error);
        [$script] = \json_decode((string) \file_get_contents($project->path('mermaid-browser-request.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertDirectoryDoesNotExist(\dirname($script));
        \unlink($project->path('mermaid-browser-request.json'));
        $error = null;
        $rasterizer->rasterize('<svg><!--deferred-browser--></svg>', null, $accept, $fail);
        $this->await(static fn (): bool => \is_file($project->path('mermaid-browser-request.json')), what: 'the pending browser snapshot');
        [$script] = \json_decode((string) \file_get_contents($project->path('mermaid-browser-request.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertDirectoryExists(\dirname($script));
        $slot = Probe::get($rasterizer, 'slot');
        $this->assertNotNull(Probe::get($slot, 'current'));
        $rasterizer->cancel();
        $this->assertNull(Probe::get($slot, 'current'));
        $this->assertDirectoryDoesNotExist(\dirname($script));
        $this->assertSame(0, $success);
        $this->assertNull($error);
    }
}
