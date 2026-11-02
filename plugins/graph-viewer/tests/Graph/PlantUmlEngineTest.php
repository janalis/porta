<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Graph;

use Janalis\Viz\Draw\NativeRenderer;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\PlantUmlEngine;
use Porta\ImageViewer\ImageData;
use Porta\ImageViewer\ImageDecoder;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PumpsLoop;
use Symfony\Component\Filesystem\Filesystem;

final class PlantUmlEngineTest extends TestCase
{
    use PreservesPath;
    use PumpsLoop;

    private const string SMALL_UML = "@startuml\nBob -> Alice : hello\n@enduml\n";

    private static function stub(): string
    {
        return \dirname(__DIR__, levels: 4).'/tests/Support/fake-plantuml';
    }

    /** @return array{0: ?string, 1: ?string} [png bytes, error] once the run settles */
    private function render(PlantUmlEngine $engine, string $source, int $dpi): array
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
        }, what: 'the engine to answer');

        return [$png, $error];
    }

    public function testRendersPngBytesAtTheRequestedDpi(): void
    {
        $engine = new PlantUmlEngine(\sys_get_temp_dir(), self::stub());
        $this->assertSame(96, $engine->baseDpi(), "PlantUML's natural size, not graphviz's 72");

        [$png, $error] = $this->render($engine, self::SMALL_UML, 192);
        $this->assertNull($error);
        $image = new ImageDecoder()->decodeBytes((string) $png);
        $this->assertInstanceOf(ImageData::class, $image, 'the bytes decode as an image — no temp file in between');
        // The stub renders 120×80 at 96 dpi, so twice that at 192.
        $this->assertSame([240, 160], [$image->width, $image->height]);
    }

    public function testASyntaxErrorSurfacesInsteadOfBecomingAPictureOfItself(): void
    {
        // -failfast2 is what makes this an ERROR: by default PlantUML draws the
        // message into the image and exits 0, which would reach the pane as a
        // perfectly valid picture of a mistake.
        [$png, $error] = $this->render(new PlantUmlEngine(\sys_get_temp_dir(), self::stub()), "@startuml\nbroken\n@enduml", 96);

        $this->assertNull($png);
        $this->assertStringContainsString('Syntax Error', (string) $error);
    }

    public function testANewRenderSupersedesTheInFlightOne(): void
    {
        $engine = new PlantUmlEngine(\sys_get_temp_dir(), self::stub());
        $fired = [];
        $onError = static function (string $message): never {
            self::fail("unexpected render error: {$message}");
        };
        $engine->render(
            self::SMALL_UML,
            96,
            static function () use (&$fired): void {
                $fired[] = 'first';
            },
            $onError,
        );
        $engine->render(
            self::SMALL_UML,
            192,
            static function () use (&$fired): void {
                $fired[] = 'second';
            },
            $onError,
        );
        $this->await(static function () use (&$fired): bool {
            return [] !== $fired;
        }, what: 'the surviving render');
        $this->pump(0.2);

        $this->assertSame(['second'], $fired, 'the superseded render went silent, latest wins');
    }

    public function testCancelIsIdempotentAndStopsInFlightWork(): void
    {
        $engine = new PlantUmlEngine(\sys_get_temp_dir(), self::stub());
        $fired = false;
        $mark = static function () use (&$fired): void {
            $fired = true;
        };
        $engine->render(self::SMALL_UML, 96, $mark, $mark);
        $engine->cancel();
        $engine->cancel();
        $this->pump(0.2);

        $this->assertFalse($fired, 'a cancelled render never reports');
    }

    public function testWithoutTheToolVizDrawsItNativelyAndSaysWhatItLeavesToPlantuml(): void
    {
        \putenv('PATH=/nonexistent');
        $engine = new PlantUmlEngine(\sys_get_temp_dir());
        $this->assertNull($engine->binary());

        [$png, $error] = $this->render($engine, self::SMALL_UML, 96);
        $this->assertNull($error);
        $expected = Work::run(new NativeRenderer(PlantUmlPipeline::native())->image(self::SMALL_UML, Format::Png));
        $this->assertSame($expected, $png, 'drawn by viz at its own 1:1, which is PlantUML\'s 96 dpi');

        [$png, $error] = $this->render($engine, "@startuml\n!include local.puml\nBob -> Alice\n@enduml\n", 96);
        $this->assertNull($png);
        $this->assertStringContainsString('leaves !include of a file or a URL to PlantUML', (string) $error);
    }

    public function testAClosurePathIsReReadPerCallSoASettingsEditApplies(): void
    {
        // The plugin hands a closure over its `plantumlPath` setting, which is
        // what makes the setting live; memoizing would make it restart-required.
        \putenv('PATH=/nonexistent');
        $configured = null;
        $engine = new PlantUmlEngine(\sys_get_temp_dir(), static function () use (&$configured): ?string {
            return $configured;
        });
        $this->assertNull($engine->binary(), 'nothing configured, nothing on PATH');

        $configured = \dirname(__DIR__, levels: 4).'/tests/Support/fake-plantuml';

        $this->assertNotNull($engine->binary(), 'the new value is picked up with no rebuild');
    }

    public function testAnExportWritesTheImageOnlyOnceItHasRendered(): void
    {
        $directory = \sys_get_temp_dir().'/porta-puml-export-'.\bin2hex(\random_bytes(6));
        \mkdir($directory);
        try {
            $engine = new PlantUmlEngine(\sys_get_temp_dir(), self::stub());
            $results = [];
            $done = static function (?string $error) use (&$results): void {
                $results[] = $error;
            };

            $engine->export(self::SMALL_UML, Format::Png, $directory.'/seq.png', $done);
            $this->await(static function () use (&$results): bool {
                return 1 === \count($results);
            }, what: 'the export to settle');
            $this->assertNull($results[0]);
            $this->assertSame([120, 80], \array_slice((array) \getimagesize($directory.'/seq.png'), offset: 0, length: 2), 'drawn at the natural 96 dpi');

            $engine->export("@startuml\nbroken\n@enduml", Format::Png, $directory.'/seq.png', $done);
            $this->await(static function () use (&$results): bool {
                return 2 === \count($results);
            }, what: 'the failed export to settle');
            $this->assertStringContainsString('Syntax Error', (string) $results[1]);
            $this->assertSame([120, 80], \array_slice((array) \getimagesize($directory.'/seq.png'), offset: 0, length: 2), 'the earlier export is left as it was');

            \file_put_contents($directory.'/blocker', data: '');
            $engine->export(self::SMALL_UML, Format::Svg, $directory.'/blocker/seq.svg', $done);
            $this->await(static function () use (&$results): bool {
                return 3 === \count($results);
            }, what: 'the unwritable export to settle');
            $this->assertNotNull($results[2]);
        } finally {
            new Filesystem()->remove($directory);
        }
    }

    public function testAnExportWithoutTheToolIsDrawnNativelyAndACancelledOneNeverReports(): void
    {
        \putenv('PATH=/nonexistent');
        $engine = new PlantUmlEngine(\sys_get_temp_dir());
        $out = \sys_get_temp_dir().'/porta-native-'.\bin2hex(\random_bytes(6)).'.svg';
        try {
            $this->assertSame('', $this->awaitString(static function (\Closure $done) use ($engine, $out): void {
                $engine->export(self::SMALL_UML, Format::Svg, $out, static fn (?string $error) => $done($error ?? ''));
            }));
            $this->assertStringContainsString('<svg', (string) \file_get_contents($out));
        } finally {
            new Filesystem()->remove($out);
        }
        $error = $this->awaitString(static function (\Closure $done) use ($engine): void {
            $engine->export(self::SMALL_UML, Format::Pdf, '/nonexistent/seq.pdf', static fn (?string $error) => $done($error ?? ''));
        });
        $this->assertStringContainsString('/nonexistent', $error, 'a write that fails says why');

        $engine = new PlantUmlEngine(\sys_get_temp_dir(), self::stub());
        $fired = false;
        $engine->export(self::SMALL_UML, Format::Png, \sys_get_temp_dir().'/porta-never-'.\bin2hex(\random_bytes(6)).'.png', static function () use (&$fired): void {
            $fired = true;
        });
        $engine->cancel();
        $this->pump(0.2);
        $this->assertFalse($fired, 'a cancelled export never reports');

        $engine = new PlantUmlEngine(\sys_get_temp_dir());
        $engine->export(self::SMALL_UML, Format::Png, \sys_get_temp_dir().'/porta-never-'.\bin2hex(\random_bytes(6)).'.png', static function () use (&$fired): void {
            $fired = true;
        });
        $engine->render(
            self::SMALL_UML,
            96,
            static function () use (&$fired): void {
                $fired = true;
            },
            static function () use (&$fired): void {
                $fired = true;
            },
        );
        $engine->cancel();
        $this->pump(0.2);
        $this->assertFalse($fired, 'a cancelled native render or export never reports');
    }
}
