<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Graph;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\PlantUmlEngine;
use Porta\ImageViewer\ImageDecoder;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PumpsLoop;

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
        $engine = new PlantUmlEngine(sys_get_temp_dir(), self::stub());
        $this->assertSame(96, $engine->baseDpi(), "PlantUML's natural size, not graphviz's 72");

        [$png, $error] = $this->render($engine, self::SMALL_UML, 192);
        $this->assertNull($error);
        $image = new ImageDecoder()->decodeBytes((string) $png);
        $this->assertNotNull($image, 'the bytes decode as an image — no temp file in between');
        // The stub renders 120×80 at 96 dpi, so twice that at 192.
        $this->assertSame([240, 160], [$image->width, $image->height]);
    }

    public function testASyntaxErrorSurfacesInsteadOfBecomingAPictureOfItself(): void
    {
        // -failfast2 is what makes this an ERROR: by default PlantUML draws the
        // message into the image and exits 0, which would reach the pane as a
        // perfectly valid picture of a mistake.
        [$png, $error] = $this->render(new PlantUmlEngine(sys_get_temp_dir(), self::stub()), "@startuml\nbroken\n@enduml", 96);

        $this->assertNull($png);
        $this->assertStringContainsString('Syntax Error', (string) $error);
    }

    public function testANewRenderSupersedesTheInFlightOne(): void
    {
        $engine = new PlantUmlEngine(sys_get_temp_dir(), self::stub());
        $fired = [];
        $onError = static function (string $message): void {
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
        $engine = new PlantUmlEngine(sys_get_temp_dir(), self::stub());
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

    public function testWithoutTheToolItErrorsWithoutSpawning(): void
    {
        putenv('PATH=/nonexistent');
        $engine = new PlantUmlEngine(sys_get_temp_dir(), null);
        $error = null;
        $engine->render(
            self::SMALL_UML,
            96,
            static function (): void {
                self::fail('onPng must not fire without a tool');
            },
            static function (string $message) use (&$error): void {
                $error = $message;
            },
        );

        $this->assertSame('plantuml not found', $error, 'synchronous — no process was involved');
        $this->assertNull($engine->binary());
    }

    public function testAClosurePathIsReReadPerCallSoASettingsEditApplies(): void
    {
        // The plugin hands a closure over its `plantumlPath` setting, which is
        // what makes the setting live; memoizing would make it restart-required.
        putenv('PATH=/nonexistent');
        $configured = null;
        $engine = new PlantUmlEngine(sys_get_temp_dir(), static function () use (&$configured): ?string {
            return $configured;
        });
        $this->assertNull($engine->binary(), 'nothing configured, nothing on PATH');

        $configured = \dirname(__DIR__, levels: 4).'/tests/Support/fake-plantuml';

        $this->assertNotNull($engine->binary(), 'the new value is picked up with no rebuild');
    }
}
